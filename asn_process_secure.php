<?php
/**
 * Secure ASN Processing System
 * Process ASN line items into inventory with comprehensive allocation and tracking
 */

require_once 'auth.php';
require_once 'security-utils.php';
require_once 'db_config.php';

// Require authentication and proper role
require_login();

$security = SecurityUtils::getInstance();
$db = getDB();

// Check rate limiting
if (!$security->checkRateLimit()) {
    http_response_code(429);
    $security->logActivity('RATE_LIMIT_EXCEEDED', ['page' => 'asn_process'], 'WARNING');
    die('Rate limit exceeded. Please try again later.');
}

$asnId = (int) ($_GET['id'] ?? 0);

if (!$asnId) {
    header('Location: inbound_secure.php?error=' . urlencode('Invalid ASN ID'));
    exit();
}

// Get ASN details with supplier information
$asn = $db->fetchRow("
    SELECT a.*, s.name as supplier_name, s.code as supplier_code
    FROM asn a
    LEFT JOIN suppliers s ON a.supplier_id = s.id
    WHERE a.id = :id AND a.deleted_at IS NULL
", [':id' => $asnId]);

if (!$asn) {
    header('Location: inbound_secure.php?error=' . urlencode('ASN not found'));
    exit();
}

// Check if ASN can be processed
$processableStatuses = ['arrived', 'receiving'];
if (!in_array($asn['status'], $processableStatuses)) {
    header('Location: edit_asn_secure.php?id=' . $asnId . '&error=' . urlencode('ASN cannot be processed in current status'));
    exit();
}

// Check permissions
$canProcess = has_role('operator');

if (!$canProcess) {
    header('Location: edit_asn_secure.php?id=' . $asnId . '&error=' . urlencode('Insufficient permissions'));
    exit();
}

$security->logActivity('ASN_PROCESS_PAGE_ACCESS', [
    'asn_id' => $asnId,
    'asn_number' => $asn['asn_number'],
    'user_id' => get_current_user_id()
]);

$csrfToken = $security->generateCSRFToken();
$message = '';
$messageType = '';

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    if (!$security->validateCSRFToken($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid CSRF token']);
        exit();
    }
    
    $action = $security->sanitizeInput($_POST['action']);
    
    switch ($action) {
        case 'get_processing_data':
            try {
                // Get ASN lines with current inventory status
                $lines = $db->fetchAll("
                    SELECT al.*, 
                           i.on_hand_quantity as current_stock,
                           i.available_quantity as available_stock,
                           i.location as current_location,
                           i.unit_cost as current_unit_cost,
                           CASE 
                               WHEN al.received_quantity >= al.quantity THEN 'complete'
                               WHEN al.received_quantity > 0 THEN 'partial'
                               ELSE 'pending'
                           END as receive_status,
                           CASE 
                               WHEN al.received_quantity = 0 THEN 'not_processed'
                               WHEN al.received_quantity > 0 AND al.processed_quantity < al.received_quantity THEN 'partial_processed'
                               WHEN al.processed_quantity >= al.received_quantity THEN 'fully_processed'
                           END as process_status
                    FROM asn_lines al
                    LEFT JOIN inventory i ON al.sku = i.sku AND i.deleted_at IS NULL
                    WHERE al.asn_id = :asn_id AND al.deleted_at IS NULL
                    ORDER BY al.line_number
                ", [':asn_id' => $asnId]);

                // Get available warehouse locations
                $locations = $db->fetchAll("
                    SELECT DISTINCT location 
                    FROM inventory 
                    WHERE location IS NOT NULL AND location != '' 
                    ORDER BY location
                ");

                // Add default locations if not present
                $defaultLocations = ['A-01-01', 'A-01-02', 'B-01-01', 'B-01-02', 'RECEIVING', 'QC-HOLD'];
                foreach ($defaultLocations as $loc) {
                    if (!in_array($loc, array_column($locations, 'location'))) {
                        $locations[] = ['location' => $loc];
                    }
                }

                echo json_encode([
                    'success' => true, 
                    'lines' => $lines,
                    'locations' => $locations
                ]);

            } catch (Exception $e) {
                $security->logActivity('ASN_PROCESSING_DATA_ERROR', [
                    'asn_id' => $asnId,
                    'error' => $e->getMessage()
                ], 'ERROR');
                echo json_encode(['error' => 'Failed to fetch processing data']);
            }
            exit();

        case 'process_line':
            try {
                $lineId = (int) ($_POST['line_id'] ?? 0);
                $processQty = (float) ($_POST['process_quantity'] ?? 0);
                $location = $security->sanitizeInput($_POST['location'] ?? '');
                $lotNumber = $security->sanitizeInput($_POST['lot_number'] ?? '');
                $expiryDate = $security->sanitizeInput($_POST['expiry_date'] ?? '');
                $condition = $security->sanitizeInput($_POST['condition'] ?? 'good');
                $notes = $security->sanitizeInput($_POST['notes'] ?? '');

                // Get line details
                $line = $db->fetchRow("
                    SELECT al.*, i.id as inventory_id, i.on_hand_quantity, i.available_quantity
                    FROM asn_lines al
                    LEFT JOIN inventory i ON al.sku = i.sku AND i.deleted_at IS NULL
                    WHERE al.id = :id AND al.asn_id = :asn_id AND al.deleted_at IS NULL
                ", [':id' => $lineId, ':asn_id' => $asnId]);

                if (!$line) {
                    echo json_encode(['error' => 'Line item not found']);
                    exit();
                }

                // Validation
                $errors = [];
                if ($processQty <= 0) {
                    $errors[] = 'Process quantity must be greater than 0';
                }
                if ($processQty > ($line['received_quantity'] - ($line['processed_quantity'] ?? 0))) {
                    $errors[] = 'Process quantity cannot exceed unprocessed received quantity';
                }
                if (empty($location)) {
                    $errors[] = 'Location is required';
                }

                $validConditions = ['good', 'damaged', 'expired', 'quarantine'];
                if (!in_array($condition, $validConditions)) {
                    $errors[] = 'Invalid condition';
                }

                if (!empty($errors)) {
                    echo json_encode(['error' => implode(', ', $errors)]);
                    exit();
                }

                $db->beginTransaction();

                try {
                    // Update inventory quantity
                    if ($line['inventory_id']) {
                        // Update existing inventory
                        $newOnHand = $line['on_hand_quantity'] + $processQty;
                        $newAvailable = $condition === 'good' ? $line['available_quantity'] + $processQty : $line['available_quantity'];

                        $updated = $db->update('inventory', [
                            'on_hand_quantity' => $newOnHand,
                            'available_quantity' => $newAvailable,
                            'location' => $location,
                            'unit_cost' => $line['unit_cost'],
                            'last_received_date' => date('Y-m-d'),
                            'updated_by' => get_current_user_id(),
                            'updated_at' => date('Y-m-d H:i:s')
                        ], 'id = :id', [':id' => $line['inventory_id']]);

                        $inventoryId = $line['inventory_id'];
                    } else {
                        // Create new inventory record
                        $inventoryId = $db->insert('inventory', [
                            'sku' => $line['sku'],
                            'description' => $line['description'],
                            'on_hand_quantity' => $processQty,
                            'available_quantity' => $condition === 'good' ? $processQty : 0,
                            'reserved_quantity' => 0,
                            'unit_cost' => $line['unit_cost'],
                            'unit_of_measure' => $line['unit_of_measure'],
                            'location' => $location,
                            'last_received_date' => date('Y-m-d'),
                            'created_by' => get_current_user_id(),
                            'created_at' => date('Y-m-d H:i:s'),
                            'updated_at' => date('Y-m-d H:i:s')
                        ]);
                    }

                    // Create inventory transaction record
                    $transactionId = $db->insert('inventory_transactions', [
                        'inventory_id' => $inventoryId,
                        'transaction_type' => 'receipt',
                        'quantity' => $processQty,
                        'unit_cost' => $line['unit_cost'],
                        'reference_type' => 'asn',
                        'reference_id' => $asnId,
                        'reference_line_id' => $lineId,
                        'location' => $location,
                        'lot_number' => $lotNumber,
                        'expiry_date' => $expiryDate ?: null,
                        'condition_status' => $condition,
                        'notes' => $notes,
                        'created_by' => get_current_user_id(),
                        'created_at' => date('Y-m-d H:i:s')
                    ]);

                    // Update ASN line processed quantity
                    $newProcessedQty = ($line['processed_quantity'] ?? 0) + $processQty;
                    $db->update('asn_lines', [
                        'processed_quantity' => $newProcessedQty,
                        'processed_location' => $location,
                        'processed_condition' => $condition,
                        'updated_by' => get_current_user_id(),
                        'updated_at' => date('Y-m-d H:i:s')
                    ], 'id = :id', [':id' => $lineId]);

                    // Check if entire ASN is fully processed
                    $unprocessedCount = $db->fetchValue("
                        SELECT COUNT(*) 
                        FROM asn_lines 
                        WHERE asn_id = :asn_id 
                        AND deleted_at IS NULL 
                        AND (processed_quantity IS NULL OR processed_quantity < received_quantity)
                    ", [':asn_id' => $asnId]);

                    // Update ASN status if fully processed
                    if ($unprocessedCount == 0) {
                        $db->update('asn', [
                            'status' => 'completed',
                            'completed_at' => date('Y-m-d H:i:s'),
                            'updated_by' => get_current_user_id(),
                            'updated_at' => date('Y-m-d H:i:s')
                        ], 'id = :id', [':id' => $asnId]);
                    } else {
                        // Set to receiving if not already
                        if ($asn['status'] !== 'receiving') {
                            $db->update('asn', [
                                'status' => 'receiving',
                                'updated_by' => get_current_user_id(),
                                'updated_at' => date('Y-m-d H:i:s')
                            ], 'id = :id', [':id' => $asnId]);
                        }
                    }

                    $security->logActivity('ASN_LINE_PROCESSED', [
                        'asn_id' => $asnId,
                        'line_id' => $lineId,
                        'sku' => $line['sku'],
                        'processed_quantity' => $processQty,
                        'location' => $location,
                        'condition' => $condition,
                        'transaction_id' => $transactionId
                    ]);

                    $db->commit();

                    echo json_encode([
                        'success' => true,
                        'message' => 'Line processed successfully',
                        'processed_quantity' => $processQty,
                        'total_processed' => $newProcessedQty
                    ]);

                } catch (Exception $e) {
                    $db->rollback();
                    throw $e;
                }

            } catch (Exception $e) {
                $security->logActivity('ASN_LINE_PROCESS_ERROR', [
                    'asn_id' => $asnId,
                    'line_id' => $lineId ?? 0,
                    'error' => $e->getMessage()
                ], 'ERROR');
                echo json_encode(['error' => 'Failed to process line item']);
            }
            exit();

        case 'process_all_lines':
            try {
                $defaultLocation = $security->sanitizeInput($_POST['default_location'] ?? '');
                $defaultCondition = $security->sanitizeInput($_POST['default_condition'] ?? 'good');

                if (empty($defaultLocation)) {
                    echo json_encode(['error' => 'Default location is required']);
                    exit();
                }

                // Get all unprocessed lines with received quantity
                $lines = $db->fetchAll("
                    SELECT al.*, i.id as inventory_id, i.on_hand_quantity, i.available_quantity
                    FROM asn_lines al
                    LEFT JOIN inventory i ON al.sku = i.sku AND i.deleted_at IS NULL
                    WHERE al.asn_id = :asn_id 
                    AND al.deleted_at IS NULL 
                    AND al.received_quantity > 0
                    AND (al.processed_quantity IS NULL OR al.processed_quantity < al.received_quantity)
                ", [':asn_id' => $asnId]);

                if (empty($lines)) {
                    echo json_encode(['error' => 'No lines available for processing']);
                    exit();
                }

                $db->beginTransaction();

                $processedCount = 0;
                $totalProcessed = 0;

                foreach ($lines as $line) {
                    $processQty = $line['received_quantity'] - ($line['processed_quantity'] ?? 0);
                    
                    if ($processQty <= 0) continue;

                    try {
                        // Update inventory quantity
                        if ($line['inventory_id']) {
                            // Update existing inventory
                            $newOnHand = $line['on_hand_quantity'] + $processQty;
                            $newAvailable = $defaultCondition === 'good' ? $line['available_quantity'] + $processQty : $line['available_quantity'];

                            $db->update('inventory', [
                                'on_hand_quantity' => $newOnHand,
                                'available_quantity' => $newAvailable,
                                'location' => $defaultLocation,
                                'unit_cost' => $line['unit_cost'],
                                'last_received_date' => date('Y-m-d'),
                                'updated_by' => get_current_user_id(),
                                'updated_at' => date('Y-m-d H:i:s')
                            ], 'id = :id', [':id' => $line['inventory_id']]);

                            $inventoryId = $line['inventory_id'];
                        } else {
                            // Create new inventory record
                            $inventoryId = $db->insert('inventory', [
                                'sku' => $line['sku'],
                                'description' => $line['description'],
                                'on_hand_quantity' => $processQty,
                                'available_quantity' => $defaultCondition === 'good' ? $processQty : 0,
                                'reserved_quantity' => 0,
                                'unit_cost' => $line['unit_cost'],
                                'unit_of_measure' => $line['unit_of_measure'],
                                'location' => $defaultLocation,
                                'last_received_date' => date('Y-m-d'),
                                'created_by' => get_current_user_id(),
                                'created_at' => date('Y-m-d H:i:s'),
                                'updated_at' => date('Y-m-d H:i:s')
                            ]);
                        }

                        // Create inventory transaction record
                        $db->insert('inventory_transactions', [
                            'inventory_id' => $inventoryId,
                            'transaction_type' => 'receipt',
                            'quantity' => $processQty,
                            'unit_cost' => $line['unit_cost'],
                            'reference_type' => 'asn',
                            'reference_id' => $asnId,
                            'reference_line_id' => $line['id'],
                            'location' => $defaultLocation,
                            'lot_number' => $line['lot_number'],
                            'expiry_date' => $line['expiry_date'],
                            'condition_status' => $defaultCondition,
                            'notes' => 'Bulk processed from ASN',
                            'created_by' => get_current_user_id(),
                            'created_at' => date('Y-m-d H:i:s')
                        ]);

                        // Update ASN line processed quantity
                        $newProcessedQty = ($line['processed_quantity'] ?? 0) + $processQty;
                        $db->update('asn_lines', [
                            'processed_quantity' => $newProcessedQty,
                            'processed_location' => $defaultLocation,
                            'processed_condition' => $defaultCondition,
                            'updated_by' => get_current_user_id(),
                            'updated_at' => date('Y-m-d H:i:s')
                        ], 'id = :id', [':id' => $line['id']]);

                        $processedCount++;
                        $totalProcessed += $processQty;

                    } catch (Exception $e) {
                        // Log error but continue with other lines
                        error_log("Failed to process line {$line['id']}: " . $e->getMessage());
                    }
                }

                if ($processedCount > 0) {
                    // Update ASN status to completed
                    $db->update('asn', [
                        'status' => 'completed',
                        'completed_at' => date('Y-m-d H:i:s'),
                        'updated_by' => get_current_user_id(),
                        'updated_at' => date('Y-m-d H:i:s')
                    ], 'id = :id', [':id' => $asnId]);

                    $security->logActivity('ASN_BULK_PROCESSED', [
                        'asn_id' => $asnId,
                        'lines_processed' => $processedCount,
                        'total_quantity' => $totalProcessed,
                        'location' => $defaultLocation,
                        'condition' => $defaultCondition
                    ]);

                    $db->commit();

                    echo json_encode([
                        'success' => true,
                        'message' => "Successfully processed {$processedCount} lines with {$totalProcessed} total units",
                        'processed_lines' => $processedCount,
                        'total_quantity' => $totalProcessed
                    ]);
                } else {
                    $db->rollback();
                    echo json_encode(['error' => 'No lines were processed']);
                }

            } catch (Exception $e) {
                $db->rollback();
                $security->logActivity('ASN_BULK_PROCESS_ERROR', [
                    'asn_id' => $asnId,
                    'error' => $e->getMessage()
                ], 'ERROR');
                echo json_encode(['error' => 'Failed to process ASN lines']);
            }
            exit();

        case 'get_transaction_history':
            try {
                $transactions = $db->fetchAll("
                    SELECT it.*, al.sku, al.description, u.username as created_by_name
                    FROM inventory_transactions it
                    JOIN asn_lines al ON it.reference_line_id = al.id
                    LEFT JOIN users u ON it.created_by = u.id
                    WHERE it.reference_type = 'asn' 
                    AND it.reference_id = :asn_id
                    ORDER BY it.created_at DESC
                ", [':asn_id' => $asnId]);

                echo json_encode(['success' => true, 'transactions' => $transactions]);

            } catch (Exception $e) {
                $security->logActivity('ASN_TRANSACTION_HISTORY_ERROR', [
                    'asn_id' => $asnId,
                    'error' => $e->getMessage()
                ], 'ERROR');
                echo json_encode(['error' => 'Failed to fetch transaction history']);
            }
            exit();
    }
}

// Get processing summary
$processingStats = $db->fetchRow("
    SELECT 
        COUNT(*) as total_lines,
        SUM(al.quantity) as total_expected,
        SUM(al.received_quantity) as total_received,
        SUM(COALESCE(al.processed_quantity, 0)) as total_processed,
        SUM(al.quantity * al.unit_cost) as total_value
    FROM asn_lines al
    WHERE al.asn_id = :asn_id AND al.deleted_at IS NULL
", [':asn_id' => $asnId]);

$progressPercentage = $processingStats['total_received'] > 0 ? 
    round(($processingStats['total_processed'] / $processingStats['total_received']) * 100, 1) : 0;

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Process ASN <?php echo htmlspecialchars($asn['asn_number']); ?> - WMS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        .header-section {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            padding: 2rem 0;
            margin-bottom: 2rem;
        }
        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }
        .stat-card {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            text-align: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            border-left: 4px solid #28a745;
        }
        .stat-value {
            font-size: 2rem;
            font-weight: bold;
            color: #28a745;
            margin-bottom: 0.5rem;
        }
        .processing-container {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 20px rgba(0,0,0,0.08);
            overflow: hidden;
            margin-bottom: 2rem;
        }
        .processing-header {
            background: #f8f9fa;
            padding: 1.5rem;
            border-bottom: 1px solid #dee2e6;
        }
        .line-item-card {
            border: 1px solid #e9ecef;
            border-radius: 10px;
            margin-bottom: 1rem;
            overflow: hidden;
            transition: all 0.3s ease;
        }
        .line-item-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        .line-item-header {
            background: #f8f9fa;
            padding: 1rem;
            border-bottom: 1px solid #e9ecef;
            display: flex;
            justify-content: between;
            align-items: center;
        }
        .line-item-body {
            padding: 1rem;
        }
        .process-form {
            background: #f0f8f0;
            border-radius: 8px;
            padding: 1rem;
            margin-top: 1rem;
        }
        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 15px;
            font-size: 0.75rem;
            font-weight: 500;
        }
        .status-not-processed { background: #fff3cd; color: #856404; }
        .status-partial-processed { background: #cce5ff; color: #004085; }
        .status-fully-processed { background: #d1f2eb; color: #0c5460; }
        .condition-good { background: #d1f2eb; color: #0c5460; }
        .condition-damaged { background: #f8d7da; color: #721c24; }
        .condition-expired { background: #fff3cd; color: #856404; }
        .condition-quarantine { background: #e2e3e5; color: #383d41; }
        .progress-processing {
            height: 8px;
            border-radius: 4px;
            margin: 1rem 0;
        }
        .bulk-actions {
            background: #e8f5e8;
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }
        .transaction-history {
            max-height: 400px;
            overflow-y: auto;
        }
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(255, 255, 255, 0.8);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 9999;
        }
    </style>
</head>
<body class="bg-light">
    <div class="loading-overlay" id="loadingOverlay">
        <div class="spinner-border text-success" role="status">
            <span class="visually-hidden">Loading...</span>
        </div>
    </div>

    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-success">
        <div class="container-fluid">
            <a class="navbar-brand" href="secure-dashboard.php">
                <i class="fas fa-warehouse me-2"></i>WMS - Process ASN
            </a>
            <div class="navbar-nav ms-auto">
                <span class="navbar-text me-3">
                    Welcome, <?php echo htmlspecialchars(get_user_full_name()); ?>
                </span>
                <a class="nav-link" href="edit_asn_secure.php?id=<?php echo $asnId; ?>">
                    <i class="fas fa-arrow-left me-1"></i>Back to ASN
                </a>
            </div>
        </div>
    </nav>

    <!-- Header Section -->
    <div class="header-section">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h2><i class="fas fa-cogs me-2"></i>Process ASN: <?php echo htmlspecialchars($asn['asn_number']); ?></h2>
                    <p class="mb-0">Supplier: <?php echo htmlspecialchars($asn['supplier_name']); ?></p>
                    <small>Convert received items into available inventory</small>
                </div>
                <div class="col-md-4 text-md-end">
                    <div class="progress progress-processing mb-2">
                        <div class="progress-bar bg-light" style="width: <?php echo $progressPercentage; ?>%"></div>
                    </div>
                    <small><?php echo $progressPercentage; ?>% Processed</small>
                </div>
            </div>
        </div>
    </div>

    <div class="container py-4">
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
                <?php echo $message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Processing Statistics -->
        <div class="stats-container">
            <div class="stat-card">
                <div class="stat-value"><?php echo number_format($processingStats['total_lines']); ?></div>
                <div class="text-muted">Total Lines</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo number_format($processingStats['total_received']); ?></div>
                <div class="text-muted">Units Received</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo number_format($processingStats['total_processed']); ?></div>
                <div class="text-muted">Units Processed</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">$<?php echo number_format($processingStats['total_value'], 2); ?></div>
                <div class="text-muted">Total Value</div>
            </div>
        </div>

        <!-- Bulk Processing Actions -->
        <div class="bulk-actions">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h5><i class="fas fa-rocket me-2"></i>Bulk Processing</h5>
                    <p class="mb-0">Process all unprocessed lines with default settings</p>
                </div>
                <div class="col-md-4 text-md-end">
                    <button class="btn btn-success btn-lg" onclick="showBulkProcessModal()">
                        <i class="fas fa-magic me-2"></i>Process All Lines
                    </button>
                </div>
            </div>
        </div>

        <!-- Processing Container -->
        <div class="processing-container">
            <div class="processing-header">
                <div class="d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-boxes me-2"></i>Line Items Processing</h5>
                    <div>
                        <button class="btn btn-outline-primary btn-sm" onclick="refreshProcessingData()">
                            <i class="fas fa-sync-alt me-2"></i>Refresh
                        </button>
                        <button class="btn btn-outline-info btn-sm ms-2" onclick="showTransactionHistory()">
                            <i class="fas fa-history me-2"></i>Transaction History
                        </button>
                    </div>
                </div>
            </div>

            <div class="p-3" id="processingContent">
                <div class="text-center py-4">
                    <div class="spinner-border text-success" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Process Line Modal -->
    <div class="modal fade" id="processLineModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-cog me-2"></i>Process Line Item
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="processLineForm">
                        <input type="hidden" id="processLineId" name="line_id">
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label">SKU</label>
                                <p class="form-control-plaintext" id="processSku"></p>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Available to Process</label>
                                <p class="form-control-plaintext" id="processAvailable"></p>
                            </div>
                        </div>

                        <div class="row mb-3">
                            <div class="col-md-6">
                                <div class="form-floating">
                                    <input type="number" class="form-control" id="process_quantity" name="process_quantity" 
                                           placeholder="Process Quantity" required min="0.01" step="0.01">
                                    <label for="process_quantity">Process Quantity *</label>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-floating">
                                    <select class="form-select" id="location" name="location" required>
                                        <option value="">Select Location</option>
                                    </select>
                                    <label for="location">Warehouse Location *</label>
                                </div>
                            </div>
                        </div>

                        <div class="row mb-3">
                            <div class="col-md-6">
                                <div class="form-floating">
                                    <input type="text" class="form-control" id="lot_number" name="lot_number" 
                                           placeholder="Lot Number" maxlength="50">
                                    <label for="lot_number">Lot Number</label>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-floating">
                                    <input type="date" class="form-control" id="expiry_date" name="expiry_date">
                                    <label for="expiry_date">Expiry Date</label>
                                </div>
                            </div>
                        </div>

                        <div class="row mb-3">
                            <div class="col-md-6">
                                <div class="form-floating">
                                    <select class="form-select" id="condition" name="condition" required>
                                        <option value="good">Good Condition</option>
                                        <option value="damaged">Damaged</option>
                                        <option value="expired">Expired</option>
                                        <option value="quarantine">Quarantine</option>
                                    </select>
                                    <label for="condition">Condition *</label>
                                </div>
                            </div>
                        </div>

                        <div class="form-floating mb-3">
                            <textarea class="form-control" id="process_notes" name="notes" 
                                      placeholder="Processing Notes" style="height: 80px;" maxlength="500"></textarea>
                            <label for="process_notes">Processing Notes</label>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-success" onclick="processLine()">
                        <i class="fas fa-cog me-2"></i>Process Line
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bulk Process Modal -->
    <div class="modal fade" id="bulkProcessModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-magic me-2"></i>Bulk Process All Lines
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        This will process all unprocessed received quantities into inventory using the settings below.
                    </div>

                    <form id="bulkProcessForm">
                        <div class="form-floating mb-3">
                            <select class="form-select" id="default_location" name="default_location" required>
                                <option value="">Select Default Location</option>
                            </select>
                            <label for="default_location">Default Location *</label>
                        </div>

                        <div class="form-floating mb-3">
                            <select class="form-select" id="default_condition" name="default_condition" required>
                                <option value="good">Good Condition</option>
                                <option value="damaged">Damaged</option>
                                <option value="expired">Expired</option>
                                <option value="quarantine">Quarantine</option>
                            </select>
                            <label for="default_condition">Default Condition *</label>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-success" onclick="bulkProcessLines()">
                        <i class="fas fa-magic me-2"></i>Process All Lines
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Transaction History Modal -->
    <div class="modal fade" id="transactionHistoryModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-history me-2"></i>Transaction History
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="transaction-history" id="transactionHistoryContent">
                        <div class="text-center py-4">
                            <div class="spinner-border text-primary" role="status">
                                <span class="visually-hidden">Loading...</span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const asnId = <?php echo $asnId; ?>;
        const csrfToken = '<?php echo $csrfToken; ?>';
        let availableLocations = [];
        let processingData = null;

        // Initialize page
        document.addEventListener('DOMContentLoaded', function() {
            loadProcessingData();
        });

        function loadProcessingData() {
            showLoading(true);
            
            const formData = new FormData();
            formData.append('action', 'get_processing_data');
            formData.append('csrf_token', csrfToken);

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    processingData = data;
                    availableLocations = data.locations;
                    renderProcessingLines(data.lines);
                    populateLocationSelects();
                } else {
                    showAlert('Error loading processing data: ' + (data.error || 'Unknown error'), 'danger');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showAlert('Failed to load processing data', 'danger');
            })
            .finally(() => {
                showLoading(false);
            });
        }

        function renderProcessingLines(lines) {
            const container = document.getElementById('processingContent');
            
            if (lines.length === 0) {
                container.innerHTML = `
                    <div class="text-center py-4">
                        <i class="fas fa-inbox fa-2x mb-2 text-muted"></i>
                        <p class="text-muted">No line items found for processing</p>
                    </div>
                `;
                return;
            }

            container.innerHTML = lines.map(line => {
                const availableToProcess = line.received_quantity - (line.processed_quantity || 0);
                const processPercentage = line.received_quantity > 0 ? 
                    ((line.processed_quantity || 0) / line.received_quantity * 100) : 0;
                
                return `
                    <div class="line-item-card">
                        <div class="line-item-header">
                            <div class="row w-100 align-items-center">
                                <div class="col-md-4">
                                    <h6 class="mb-0">
                                        <strong>${escapeHtml(line.sku)}</strong>
                                        ${line.description ? `<br><small class="text-muted">${escapeHtml(line.description)}</small>` : ''}
                                    </h6>
                                </div>
                                <div class="col-md-4 text-center">
                                    <span class="status-badge status-${line.process_status.replace('_', '-')}">
                                        ${line.process_status.replace('_', ' ').toUpperCase()}
                                    </span>
                                </div>
                                <div class="col-md-4 text-end">
                                    ${availableToProcess > 0 ? `
                                        <button class="btn btn-success btn-sm" onclick="showProcessLineModal(${line.id})">
                                            <i class="fas fa-cog me-1"></i>Process
                                        </button>
                                    ` : ''}
                                </div>
                            </div>
                        </div>
                        <div class="line-item-body">
                            <div class="row">
                                <div class="col-md-3">
                                    <strong>Received:</strong> ${formatNumber(line.received_quantity)} ${escapeHtml(line.unit_of_measure)}
                                </div>
                                <div class="col-md-3">
                                    <strong>Processed:</strong> ${formatNumber(line.processed_quantity || 0)}
                                </div>
                                <div class="col-md-3">
                                    <strong>Available:</strong> 
                                    <span class="${availableToProcess > 0 ? 'text-warning' : 'text-success'}">
                                        ${formatNumber(availableToProcess)}
                                    </span>
                                </div>
                                <div class="col-md-3">
                                    <strong>Unit Cost:</strong> $${formatNumber(line.unit_cost, 2)}
                                </div>
                            </div>
                            
                            <div class="progress progress-processing">
                                <div class="progress-bar bg-success" style="width: ${Math.min(processPercentage, 100)}%"></div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    ${line.current_stock !== null ? `
                                        <small class="text-muted">Current Stock: ${formatNumber(line.current_stock)}</small>
                                    ` : '<small class="text-muted">New Item</small>'}
                                </div>
                                <div class="col-md-6 text-end">
                                    ${line.current_location ? `
                                        <small class="text-muted">Current Location: ${escapeHtml(line.current_location)}</small>
                                    ` : ''}
                                </div>
                            </div>
                            
                            ${line.lot_number || line.expiry_date ? `
                                <div class="mt-2">
                                    ${line.lot_number ? `<small class="text-muted">Lot: ${escapeHtml(line.lot_number)} </small>` : ''}
                                    ${line.expiry_date ? `<small class="text-muted">Expires: ${formatDate(line.expiry_date)}</small>` : ''}
                                </div>
                            ` : ''}
                        </div>
                    </div>
                `;
            }).join('');
        }

        function populateLocationSelects() {
            const selects = [
                document.getElementById('location'),
                document.getElementById('default_location')
            ];

            selects.forEach(select => {
                if (select) {
                    // Clear existing options except first one
                    select.innerHTML = select.options[0].outerHTML;
                    
                    availableLocations.forEach(location => {
                        const option = document.createElement('option');
                        option.value = location.location;
                        option.textContent = location.location;
                        select.appendChild(option);
                    });
                }
            });
        }

        function showProcessLineModal(lineId) {
            const line = processingData.lines.find(l => l.id === lineId);
            if (!line) return;

            const availableToProcess = line.received_quantity - (line.processed_quantity || 0);

            document.getElementById('processLineId').value = lineId;
            document.getElementById('processSku').textContent = line.sku;
            document.getElementById('processAvailable').textContent = `${formatNumber(availableToProcess)} ${line.unit_of_measure}`;
            document.getElementById('process_quantity').value = availableToProcess;
            document.getElementById('process_quantity').max = availableToProcess;
            document.getElementById('location').value = line.current_location || '';
            document.getElementById('lot_number').value = line.lot_number || '';
            document.getElementById('expiry_date').value = line.expiry_date || '';
            document.getElementById('condition').value = 'good';
            document.getElementById('process_notes').value = '';

            new bootstrap.Modal(document.getElementById('processLineModal')).show();
        }

        function processLine() {
            const form = document.getElementById('processLineForm');
            const formData = new FormData(form);
            formData.append('action', 'process_line');
            formData.append('csrf_token', csrfToken);
            
            if (!form.checkValidity()) {
                form.classList.add('was-validated');
                return;
            }
            
            showLoading(true);

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showAlert('Line processed successfully', 'success');
                    bootstrap.Modal.getInstance(document.getElementById('processLineModal')).hide();
                    loadProcessingData();
                    
                    // Check if ASN is now completed
                    if (data.asn_completed) {
                        showAlert('ASN has been fully processed and marked as completed!', 'success');
                        setTimeout(() => {
                            window.location.href = `edit_asn_secure.php?id=${asnId}`;
                        }, 2000);
                    }
                } else {
                    showAlert('Error: ' + (data.error || 'Unknown error'), 'danger');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showAlert('Failed to process line item', 'danger');
            })
            .finally(() => {
                showLoading(false);
            });
        }

        function showBulkProcessModal() {
            document.getElementById('default_location').value = '';
            document.getElementById('default_condition').value = 'good';
            new bootstrap.Modal(document.getElementById('bulkProcessModal')).show();
        }

        function bulkProcessLines() {
            const form = document.getElementById('bulkProcessForm');
            const formData = new FormData(form);
            formData.append('action', 'process_all_lines');
            formData.append('csrf_token', csrfToken);
            
            if (!form.checkValidity()) {
                form.classList.add('was-validated');
                return;
            }
            
            if (!confirm('Are you sure you want to process all unprocessed lines? This action cannot be undone.')) {
                return;
            }
            
            showLoading(true);

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showAlert(data.message, 'success');
                    bootstrap.Modal.getInstance(document.getElementById('bulkProcessModal')).hide();
                    
                    // Redirect to ASN edit page since it's completed
                    setTimeout(() => {
                        window.location.href = `edit_asn_secure.php?id=${asnId}&processed=1`;
                    }, 2000);
                } else {
                    showAlert('Error: ' + (data.error || 'Unknown error'), 'danger');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showAlert('Failed to process lines', 'danger');
            })
            .finally(() => {
                showLoading(false);
            });
        }

        function showTransactionHistory() {
            const modal = new bootstrap.Modal(document.getElementById('transactionHistoryModal'));
            modal.show();
            
            const formData = new FormData();
            formData.append('action', 'get_transaction_history');
            formData.append('csrf_token', csrfToken);

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    renderTransactionHistory(data.transactions);
                } else {
                    document.getElementById('transactionHistoryContent').innerHTML = 
                        '<div class="alert alert-danger">Failed to load transaction history</div>';
                }
            })
            .catch(error => {
                console.error('Error:', error);
                document.getElementById('transactionHistoryContent').innerHTML = 
                    '<div class="alert alert-danger">Failed to load transaction history</div>';
            });
        }

        function renderTransactionHistory(transactions) {
            const container = document.getElementById('transactionHistoryContent');
            
            if (transactions.length === 0) {
                container.innerHTML = '<div class="text-center py-4 text-muted">No transactions found</div>';
                return;
            }

            container.innerHTML = `
                <div class="table-responsive">
                    <table class="table table-sm table-hover">
                        <thead>
                            <tr>
                                <th>Date/Time</th>
                                <th>SKU</th>
                                <th>Quantity</th>
                                <th>Location</th>
                                <th>Condition</th>
                                <th>User</th>
                                <th>Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${transactions.map(tx => `
                                <tr>
                                    <td>${formatDateTime(tx.created_at)}</td>
                                    <td>
                                        <strong>${escapeHtml(tx.sku)}</strong>
                                        ${tx.description ? `<br><small class="text-muted">${escapeHtml(tx.description)}</small>` : ''}
                                    </td>
                                    <td>${formatNumber(tx.quantity)}</td>
                                    <td>${escapeHtml(tx.location)}</td>
                                    <td>
                                        <span class="condition-${tx.condition_status}">
                                            ${tx.condition_status.charAt(0).toUpperCase() + tx.condition_status.slice(1)}
                                        </span>
                                    </td>
                                    <td>${escapeHtml(tx.created_by_name || 'Unknown')}</td>
                                    <td>${tx.notes ? escapeHtml(tx.notes) : '-'}</td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                </div>
            `;
        }

        function refreshProcessingData() {
            loadProcessingData();
        }

        function showLoading(show) {
            document.getElementById('loadingOverlay').style.display = show ? 'flex' : 'none';
        }

        function showAlert(message, type) {
            const alert = document.createElement('div');
            alert.className = `alert alert-${type} alert-dismissible fade show`;
            alert.innerHTML = `
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;
            
            const container = document.querySelector('.container');
            container.insertBefore(alert, container.firstChild);
            
            setTimeout(() => {
                alert.remove();
            }, 10000);
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function formatNumber(number, decimals = 0) {
            return new Intl.NumberFormat('en-US', {
                minimumFractionDigits: decimals,
                maximumFractionDigits: decimals
            }).format(number);
        }

        function formatDate(dateString) {
            return new Date(dateString).toLocaleDateString('en-US');
        }

        function formatDateTime(dateString) {
            return new Date(dateString).toLocaleString('en-US');
        }
    </script>
</body>
</html>