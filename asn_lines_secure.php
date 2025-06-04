<?php
/**
 * Secure ASN Line Items Management
 * Comprehensive line item management with detailed views, editing, and validation
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
    $security->logActivity('RATE_LIMIT_EXCEEDED', ['page' => 'asn_lines'], 'WARNING');
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

// Check permissions
$canEdit = has_role('operator') && in_array($asn['status'], ['draft', 'confirmed']);
$canReceive = has_role('operator') && in_array($asn['status'], ['arrived', 'receiving']);

$security->logActivity('ASN_LINES_PAGE_ACCESS', [
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
        case 'add_line':
            if (!$canEdit) {
                echo json_encode(['error' => 'Permission denied']);
                exit();
            }
            
            try {
                $lineData = [
                    'sku' => $security->sanitizeInput($_POST['sku'] ?? ''),
                    'description' => $security->sanitizeInput($_POST['description'] ?? ''),
                    'quantity' => (float) ($_POST['quantity'] ?? 0),
                    'unit_cost' => (float) ($_POST['unit_cost'] ?? 0),
                    'unit_of_measure' => $security->sanitizeInput($_POST['unit_of_measure'] ?? ''),
                    'lot_number' => $security->sanitizeInput($_POST['lot_number'] ?? ''),
                    'expiry_date' => $security->sanitizeInput($_POST['expiry_date'] ?? ''),
                    'notes' => $security->sanitizeInput($_POST['notes'] ?? '')
                ];

                // Validation
                $errors = [];
                if (empty($lineData['sku'])) {
                    $errors[] = 'SKU is required';
                }
                if ($lineData['quantity'] <= 0) {
                    $errors[] = 'Quantity must be greater than 0';
                }
                if (strlen($lineData['sku']) > 50) {
                    $errors[] = 'SKU cannot exceed 50 characters';
                }

                if (!empty($errors)) {
                    echo json_encode(['error' => implode(', ', $errors)]);
                    exit();
                }

                $db->beginTransaction();

                // Get next line number
                $lineNumber = $db->fetchValue(
                    "SELECT COALESCE(MAX(line_number), 0) + 1 FROM asn_lines WHERE asn_id = :asn_id",
                    [':asn_id' => $asnId]
                );

                // Check if inventory item exists, create if needed
                $inventory = $db->fetchRow(
                    "SELECT * FROM inventory WHERE sku = :sku AND deleted_at IS NULL",
                    [':sku' => $lineData['sku']]
                );

                if (!$inventory) {
                    // Create new inventory item
                    $inventoryId = $db->insert('inventory', [
                        'sku' => $lineData['sku'],
                        'description' => $lineData['description'],
                        'unit_of_measure' => $lineData['unit_of_measure'] ?: 'EA',
                        'on_hand_quantity' => 0,
                        'available_quantity' => 0,
                        'reserved_quantity' => 0,
                        'unit_cost' => $lineData['unit_cost'],
                        'created_by' => get_current_user_id(),
                        'created_at' => date('Y-m-d H:i:s'),
                        'updated_at' => date('Y-m-d H:i:s')
                    ]);
                } else {
                    // Update existing inventory with latest info
                    $db->update('inventory', [
                        'description' => $lineData['description'] ?: $inventory['description'],
                        'unit_of_measure' => $lineData['unit_of_measure'] ?: $inventory['unit_of_measure'],
                        'unit_cost' => $lineData['unit_cost'] > 0 ? $lineData['unit_cost'] : $inventory['unit_cost'],
                        'updated_by' => get_current_user_id(),
                        'updated_at' => date('Y-m-d H:i:s')
                    ], 'id = :id', [':id' => $inventory['id']]);
                }

                // Insert ASN line
                $lineId = $db->insert('asn_lines', [
                    'asn_id' => $asnId,
                    'line_number' => $lineNumber,
                    'sku' => $lineData['sku'],
                    'description' => $lineData['description'],
                    'quantity' => $lineData['quantity'],
                    'received_quantity' => 0,
                    'unit_cost' => $lineData['unit_cost'],
                    'unit_of_measure' => $lineData['unit_of_measure'] ?: 'EA',
                    'lot_number' => $lineData['lot_number'],
                    'expiry_date' => $lineData['expiry_date'] ?: null,
                    'notes' => $lineData['notes'],
                    'created_by' => get_current_user_id(),
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s')
                ]);

                if ($lineId) {
                    $security->logActivity('ASN_LINE_ADDED', [
                        'asn_id' => $asnId,
                        'line_id' => $lineId,
                        'sku' => $lineData['sku'],
                        'quantity' => $lineData['quantity']
                    ]);

                    $db->commit();
                    echo json_encode(['success' => true, 'line_id' => $lineId]);
                } else {
                    $db->rollback();
                    echo json_encode(['error' => 'Failed to add line item']);
                }

            } catch (Exception $e) {
                $db->rollback();
                $security->logActivity('ASN_LINE_ADD_ERROR', [
                    'asn_id' => $asnId,
                    'error' => $e->getMessage()
                ], 'ERROR');
                echo json_encode(['error' => 'Failed to add line item']);
            }
            exit();

        case 'update_line':
            if (!$canEdit) {
                echo json_encode(['error' => 'Permission denied']);
                exit();
            }
            
            try {
                $lineId = (int) ($_POST['line_id'] ?? 0);
                $lineData = [
                    'sku' => $security->sanitizeInput($_POST['sku'] ?? ''),
                    'description' => $security->sanitizeInput($_POST['description'] ?? ''),
                    'quantity' => (float) ($_POST['quantity'] ?? 0),
                    'unit_cost' => (float) ($_POST['unit_cost'] ?? 0),
                    'unit_of_measure' => $security->sanitizeInput($_POST['unit_of_measure'] ?? ''),
                    'lot_number' => $security->sanitizeInput($_POST['lot_number'] ?? ''),
                    'expiry_date' => $security->sanitizeInput($_POST['expiry_date'] ?? ''),
                    'notes' => $security->sanitizeInput($_POST['notes'] ?? '')
                ];

                // Get existing line
                $existingLine = $db->fetchRow(
                    "SELECT * FROM asn_lines WHERE id = :id AND asn_id = :asn_id AND deleted_at IS NULL",
                    [':id' => $lineId, ':asn_id' => $asnId]
                );

                if (!$existingLine) {
                    echo json_encode(['error' => 'Line item not found']);
                    exit();
                }

                // Validation
                $errors = [];
                if (empty($lineData['sku'])) {
                    $errors[] = 'SKU is required';
                }
                if ($lineData['quantity'] <= 0) {
                    $errors[] = 'Quantity must be greater than 0';
                }
                if ($lineData['quantity'] < $existingLine['received_quantity']) {
                    $errors[] = 'Quantity cannot be less than received quantity';
                }

                if (!empty($errors)) {
                    echo json_encode(['error' => implode(', ', $errors)]);
                    exit();
                }

                $db->beginTransaction();

                // Update ASN line
                $updated = $db->update('asn_lines', [
                    'sku' => $lineData['sku'],
                    'description' => $lineData['description'],
                    'quantity' => $lineData['quantity'],
                    'unit_cost' => $lineData['unit_cost'],
                    'unit_of_measure' => $lineData['unit_of_measure'],
                    'lot_number' => $lineData['lot_number'],
                    'expiry_date' => $lineData['expiry_date'] ?: null,
                    'notes' => $lineData['notes'],
                    'updated_by' => get_current_user_id(),
                    'updated_at' => date('Y-m-d H:i:s')
                ], 'id = :id', [':id' => $lineId]);

                if ($updated) {
                    $security->logActivity('ASN_LINE_UPDATED', [
                        'asn_id' => $asnId,
                        'line_id' => $lineId,
                        'sku' => $lineData['sku'],
                        'changes' => array_diff_assoc($lineData, $existingLine)
                    ]);

                    $db->commit();
                    echo json_encode(['success' => true]);
                } else {
                    $db->rollback();
                    echo json_encode(['error' => 'Failed to update line item']);
                }

            } catch (Exception $e) {
                $db->rollback();
                $security->logActivity('ASN_LINE_UPDATE_ERROR', [
                    'asn_id' => $asnId,
                    'error' => $e->getMessage()
                ], 'ERROR');
                echo json_encode(['error' => 'Failed to update line item']);
            }
            exit();

        case 'delete_line':
            if (!$canEdit) {
                echo json_encode(['error' => 'Permission denied']);
                exit();
            }
            
            try {
                $lineId = (int) ($_POST['line_id'] ?? 0);
                
                // Get existing line
                $existingLine = $db->fetchRow(
                    "SELECT * FROM asn_lines WHERE id = :id AND asn_id = :asn_id AND deleted_at IS NULL",
                    [':id' => $lineId, ':asn_id' => $asnId]
                );

                if (!$existingLine) {
                    echo json_encode(['error' => 'Line item not found']);
                    exit();
                }

                if ($existingLine['received_quantity'] > 0) {
                    echo json_encode(['error' => 'Cannot delete line with received quantity']);
                    exit();
                }

                $db->beginTransaction();

                // Soft delete the line
                $deleted = $db->softDelete('asn_lines', $lineId);

                if ($deleted) {
                    $security->logActivity('ASN_LINE_DELETED', [
                        'asn_id' => $asnId,
                        'line_id' => $lineId,
                        'sku' => $existingLine['sku'],
                        'quantity' => $existingLine['quantity']
                    ]);

                    $db->commit();
                    echo json_encode(['success' => true]);
                } else {
                    $db->rollback();
                    echo json_encode(['error' => 'Failed to delete line item']);
                }

            } catch (Exception $e) {
                $db->rollback();
                $security->logActivity('ASN_LINE_DELETE_ERROR', [
                    'asn_id' => $asnId,
                    'error' => $e->getMessage()
                ], 'ERROR');
                echo json_encode(['error' => 'Failed to delete line item']);
            }
            exit();

        case 'receive_quantity':
            if (!$canReceive) {
                echo json_encode(['error' => 'Permission denied']);
                exit();
            }
            
            try {
                $lineId = (int) ($_POST['line_id'] ?? 0);
                $receivedQty = (float) ($_POST['received_quantity'] ?? 0);
                
                // Get existing line
                $line = $db->fetchRow(
                    "SELECT * FROM asn_lines WHERE id = :id AND asn_id = :asn_id AND deleted_at IS NULL",
                    [':id' => $lineId, ':asn_id' => $asnId]
                );

                if (!$line) {
                    echo json_encode(['error' => 'Line item not found']);
                    exit();
                }

                if ($receivedQty < 0 || $receivedQty > $line['quantity']) {
                    echo json_encode(['error' => 'Invalid received quantity']);
                    exit();
                }

                $db->beginTransaction();

                // Update received quantity
                $updated = $db->update('asn_lines', [
                    'received_quantity' => $receivedQty,
                    'updated_by' => get_current_user_id(),
                    'updated_at' => date('Y-m-d H:i:s')
                ], 'id = :id', [':id' => $lineId]);

                if ($updated) {
                    $security->logActivity('ASN_LINE_RECEIVED', [
                        'asn_id' => $asnId,
                        'line_id' => $lineId,
                        'sku' => $line['sku'],
                        'old_received' => $line['received_quantity'],
                        'new_received' => $receivedQty
                    ]);

                    $db->commit();
                    echo json_encode(['success' => true]);
                } else {
                    $db->rollback();
                    echo json_encode(['error' => 'Failed to update received quantity']);
                }

            } catch (Exception $e) {
                $db->rollback();
                $security->logActivity('ASN_LINE_RECEIVE_ERROR', [
                    'asn_id' => $asnId,
                    'error' => $e->getMessage()
                ], 'ERROR');
                echo json_encode(['error' => 'Failed to update received quantity']);
            }
            exit();

        case 'get_lines_data':
            try {
                $lines = $db->fetchAll("
                    SELECT al.*, i.on_hand_quantity as current_stock,
                           i.unit_cost as inventory_unit_cost,
                           CASE 
                               WHEN al.received_quantity >= al.quantity THEN 'complete'
                               WHEN al.received_quantity > 0 THEN 'partial'
                               ELSE 'pending'
                           END as receive_status
                    FROM asn_lines al
                    LEFT JOIN inventory i ON al.sku = i.sku AND i.deleted_at IS NULL
                    WHERE al.asn_id = :asn_id AND al.deleted_at IS NULL
                    ORDER BY al.line_number, al.created_at
                ", [':asn_id' => $asnId]);

                echo json_encode(['success' => true, 'lines' => $lines]);

            } catch (Exception $e) {
                $security->logActivity('ASN_LINES_FETCH_ERROR', [
                    'asn_id' => $asnId,
                    'error' => $e->getMessage()
                ], 'ERROR');
                echo json_encode(['error' => 'Failed to fetch line items']);
            }
            exit();

        case 'import_from_csv':
            if (!$canEdit) {
                echo json_encode(['error' => 'Permission denied']);
                exit();
            }
            
            try {
                if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
                    echo json_encode(['error' => 'No file uploaded or upload error']);
                    exit();
                }

                $csvData = file_get_contents($_FILES['csv_file']['tmp_name']);
                $lines = str_getcsv($csvData, "\n");
                
                if (empty($lines)) {
                    echo json_encode(['error' => 'CSV file is empty']);
                    exit();
                }

                $db->beginTransaction();
                $imported = 0;
                $errors = [];

                // Skip header row
                $header = str_getcsv(array_shift($lines));
                $expectedHeaders = ['sku', 'description', 'quantity', 'unit_cost', 'unit_of_measure'];
                
                foreach ($lines as $index => $line) {
                    if (empty(trim($line))) continue;
                    
                    $data = str_getcsv($line);
                    
                    if (count($data) < 3) {
                        $errors[] = "Row " . ($index + 2) . ": Insufficient data";
                        continue;
                    }

                    $lineData = [
                        'sku' => trim($data[0]),
                        'description' => trim($data[1] ?? ''),
                        'quantity' => (float) ($data[2] ?? 0),
                        'unit_cost' => (float) ($data[3] ?? 0),
                        'unit_of_measure' => trim($data[4] ?? 'EA')
                    ];

                    if (empty($lineData['sku']) || $lineData['quantity'] <= 0) {
                        $errors[] = "Row " . ($index + 2) . ": Invalid SKU or quantity";
                        continue;
                    }

                    // Get next line number
                    $lineNumber = $db->fetchValue(
                        "SELECT COALESCE(MAX(line_number), 0) + 1 FROM asn_lines WHERE asn_id = :asn_id",
                        [':asn_id' => $asnId]
                    );

                    // Insert line
                    $lineId = $db->insert('asn_lines', [
                        'asn_id' => $asnId,
                        'line_number' => $lineNumber,
                        'sku' => $lineData['sku'],
                        'description' => $lineData['description'],
                        'quantity' => $lineData['quantity'],
                        'received_quantity' => 0,
                        'unit_cost' => $lineData['unit_cost'],
                        'unit_of_measure' => $lineData['unit_of_measure'],
                        'created_by' => get_current_user_id(),
                        'created_at' => date('Y-m-d H:i:s'),
                        'updated_at' => date('Y-m-d H:i:s')
                    ]);

                    if ($lineId) {
                        $imported++;
                    } else {
                        $errors[] = "Row " . ($index + 2) . ": Failed to import";
                    }
                }

                if ($imported > 0) {
                    $security->logActivity('ASN_LINES_IMPORTED', [
                        'asn_id' => $asnId,
                        'imported_count' => $imported,
                        'error_count' => count($errors)
                    ]);

                    $db->commit();
                    echo json_encode([
                        'success' => true, 
                        'imported' => $imported, 
                        'errors' => $errors
                    ]);
                } else {
                    $db->rollback();
                    echo json_encode(['error' => 'No valid lines imported', 'errors' => $errors]);
                }

            } catch (Exception $e) {
                $db->rollback();
                $security->logActivity('ASN_LINES_IMPORT_ERROR', [
                    'asn_id' => $asnId,
                    'error' => $e->getMessage()
                ], 'ERROR');
                echo json_encode(['error' => 'Failed to import CSV data']);
            }
            exit();
    }
}

// Get existing line items
$asnLines = $db->fetchAll("
    SELECT al.*, i.on_hand_quantity as current_stock,
           i.unit_cost as inventory_unit_cost,
           CASE 
               WHEN al.received_quantity >= al.quantity THEN 'complete'
               WHEN al.received_quantity > 0 THEN 'partial'
               ELSE 'pending'
           END as receive_status
    FROM asn_lines al
    LEFT JOIN inventory i ON al.sku = i.sku AND i.deleted_at IS NULL
    WHERE al.asn_id = :asn_id AND al.deleted_at IS NULL
    ORDER BY al.line_number, al.created_at
", [':asn_id' => $asnId]);

// Calculate totals
$totalLines = count($asnLines);
$totalQuantity = array_sum(array_column($asnLines, 'quantity'));
$totalReceived = array_sum(array_column($asnLines, 'received_quantity'));
$totalValue = array_sum(array_map(function($line) {
    return $line['quantity'] * $line['unit_cost'];
}, $asnLines));
$progressPercentage = $totalQuantity > 0 ? round(($totalReceived / $totalQuantity) * 100, 1) : 0;

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ASN Line Items - <?php echo htmlspecialchars($asn['asn_number']); ?> - WMS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        .header-section {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
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
            border-left: 4px solid #667eea;
        }
        .stat-value {
            font-size: 2rem;
            font-weight: bold;
            color: #667eea;
            margin-bottom: 0.5rem;
        }
        .table-container {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 20px rgba(0,0,0,0.08);
            overflow: hidden;
        }
        .table-actions {
            background: #f8f9fa;
            padding: 1rem;
            border-bottom: 1px solid #dee2e6;
        }
        .progress-thin {
            height: 6px;
            border-radius: 3px;
        }
        .receive-status {
            padding: 0.25rem 0.75rem;
            border-radius: 15px;
            font-size: 0.75rem;
            font-weight: 500;
        }
        .receive-status.pending { background: #fff3cd; color: #856404; }
        .receive-status.partial { background: #cce5ff; color: #004085; }
        .receive-status.complete { background: #d1f2eb; color: #0c5460; }
        .action-buttons .btn {
            margin: 0 2px;
            padding: 0.25rem 0.5rem;
            font-size: 0.875rem;
        }
        .modal-lg {
            max-width: 800px;
        }
        .form-floating .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
        }
        .readonly-field {
            background-color: #f8f9fa !important;
        }
        .drag-drop-area {
            border: 2px dashed #ccc;
            border-radius: 10px;
            padding: 2rem;
            text-align: center;
            transition: all 0.3s ease;
            margin-bottom: 1rem;
        }
        .drag-drop-area.dragover {
            border-color: #667eea;
            background: #f8f9ff;
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
        <div class="spinner-border text-primary" role="status">
            <span class="visually-hidden">Loading...</span>
        </div>
    </div>

    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container-fluid">
            <a class="navbar-brand" href="secure-dashboard.php">
                <i class="fas fa-warehouse me-2"></i>WMS - ASN Line Items
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
                    <h2><i class="fas fa-list me-2"></i>Line Items: <?php echo htmlspecialchars($asn['asn_number']); ?></h2>
                    <p class="mb-0">Supplier: <?php echo htmlspecialchars($asn['supplier_name']); ?></p>
                    <small>Status: <?php echo ucfirst(str_replace('_', ' ', $asn['status'])); ?></small>
                </div>
                <div class="col-md-4 text-md-end">
                    <div class="progress progress-thin mb-2">
                        <div class="progress-bar bg-success" style="width: <?php echo $progressPercentage; ?>%"></div>
                    </div>
                    <small><?php echo $progressPercentage; ?>% Complete</small>
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

        <!-- Statistics -->
        <div class="stats-container">
            <div class="stat-card">
                <div class="stat-value"><?php echo number_format($totalLines); ?></div>
                <div class="text-muted">Total Lines</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo number_format($totalQuantity); ?></div>
                <div class="text-muted">Expected Units</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo number_format($totalReceived); ?></div>
                <div class="text-muted">Received Units</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">$<?php echo number_format($totalValue, 2); ?></div>
                <div class="text-muted">Total Value</div>
            </div>
        </div>

        <!-- Line Items Table -->
        <div class="table-container">
            <div class="table-actions">
                <div class="d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-boxes me-2"></i>Line Items</h5>
                    <div>
                        <?php if ($canEdit): ?>
                            <button class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#addLineModal">
                                <i class="fas fa-plus me-2"></i>Add Line
                            </button>
                            <button class="btn btn-info btn-sm ms-2" data-bs-toggle="modal" data-bs-target="#importModal">
                                <i class="fas fa-upload me-2"></i>Import CSV
                            </button>
                        <?php endif; ?>
                        <button class="btn btn-outline-primary btn-sm ms-2" onclick="refreshLines()">
                            <i class="fas fa-sync-alt me-2"></i>Refresh
                        </button>
                        <button class="btn btn-outline-secondary btn-sm ms-2" onclick="exportToCsv()">
                            <i class="fas fa-download me-2"></i>Export CSV
                        </button>
                    </div>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-hover mb-0" id="linesTable">
                    <thead class="table-dark">
                        <tr>
                            <th>Line #</th>
                            <th>SKU</th>
                            <th>Description</th>
                            <th>Expected</th>
                            <th>Received</th>
                            <th>Status</th>
                            <th>Unit Cost</th>
                            <th>Total Value</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="linesTableBody">
                        <tr>
                            <td colspan="9" class="text-center py-4">
                                <div class="spinner-border text-primary" role="status">
                                    <span class="visually-hidden">Loading...</span>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Add/Edit Line Modal -->
    <div class="modal fade" id="addLineModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-plus me-2"></i>
                        <span id="modalTitle">Add Line Item</span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="lineForm">
                        <input type="hidden" id="lineId" name="line_id">
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-floating mb-3">
                                    <input type="text" class="form-control" id="sku" name="sku" 
                                           placeholder="SKU" required maxlength="50">
                                    <label for="sku">SKU *</label>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-floating mb-3">
                                    <select class="form-select" id="unit_of_measure" name="unit_of_measure">
                                        <option value="EA">Each (EA)</option>
                                        <option value="BOX">Box (BOX)</option>
                                        <option value="CASE">Case (CASE)</option>
                                        <option value="PALLET">Pallet (PLT)</option>
                                        <option value="LB">Pound (LB)</option>
                                        <option value="KG">Kilogram (KG)</option>
                                        <option value="FT">Foot (FT)</option>
                                        <option value="M">Meter (M)</option>
                                    </select>
                                    <label for="unit_of_measure">Unit of Measure</label>
                                </div>
                            </div>
                        </div>

                        <div class="form-floating mb-3">
                            <textarea class="form-control" id="description" name="description" 
                                      placeholder="Description" style="height: 80px;" maxlength="255"></textarea>
                            <label for="description">Description</label>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-floating mb-3">
                                    <input type="number" class="form-control" id="quantity" name="quantity" 
                                           placeholder="Quantity" required min="0.01" step="0.01">
                                    <label for="quantity">Expected Quantity *</label>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-floating mb-3">
                                    <input type="number" class="form-control" id="unit_cost" name="unit_cost" 
                                           placeholder="Unit Cost" min="0" step="0.01">
                                    <label for="unit_cost">Unit Cost</label>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-floating mb-3">
                                    <input type="text" class="form-control" id="lot_number" name="lot_number" 
                                           placeholder="Lot Number" maxlength="50">
                                    <label for="lot_number">Lot Number</label>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-floating mb-3">
                                    <input type="date" class="form-control" id="expiry_date" name="expiry_date">
                                    <label for="expiry_date">Expiry Date</label>
                                </div>
                            </div>
                        </div>

                        <div class="form-floating mb-3">
                            <textarea class="form-control" id="notes" name="notes" 
                                      placeholder="Notes" style="height: 80px;" maxlength="500"></textarea>
                            <label for="notes">Notes</label>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-success" onclick="saveLine()">
                        <i class="fas fa-save me-2"></i>Save Line
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Receive Quantity Modal -->
    <div class="modal fade" id="receiveModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-box me-2"></i>Receive Quantity
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="receiveForm">
                        <input type="hidden" id="receiveLineId" name="line_id">
                        
                        <div class="mb-3">
                            <label class="form-label">SKU</label>
                            <p class="form-control-plaintext" id="receiveSku"></p>
                        </div>
                        
                        <div class="row">
                            <div class="col-6">
                                <label class="form-label">Expected</label>
                                <p class="form-control-plaintext" id="receiveExpected"></p>
                            </div>
                            <div class="col-6">
                                <label class="form-label">Currently Received</label>
                                <p class="form-control-plaintext" id="receiveCurrently"></p>
                            </div>
                        </div>

                        <div class="form-floating mb-3">
                            <input type="number" class="form-control" id="received_quantity" name="received_quantity" 
                                   placeholder="Received Quantity" required min="0" step="0.01">
                            <label for="received_quantity">New Received Quantity *</label>
                            <div class="form-text">Enter the total received quantity (not additional)</div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-success" onclick="saveReceiveQuantity()">
                        <i class="fas fa-check me-2"></i>Update Quantity
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Import CSV Modal -->
    <div class="modal fade" id="importModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-upload me-2"></i>Import Line Items from CSV
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>CSV Format:</strong> The CSV file should have columns: 
                        <code>sku, description, quantity, unit_cost, unit_of_measure</code>
                    </div>

                    <form id="importForm" enctype="multipart/form-data">
                        <div class="drag-drop-area" id="dragDropArea">
                            <i class="fas fa-cloud-upload-alt fa-3x mb-3 text-muted"></i>
                            <p>Drag and drop your CSV file here, or <a href="#" id="fileSelectLink">click to select</a></p>
                            <input type="file" id="csvFile" name="csv_file" accept=".csv" style="display: none;">
                        </div>
                        
                        <div id="fileInfo" class="mt-3" style="display: none;">
                            <div class="card">
                                <div class="card-body">
                                    <h6><i class="fas fa-file-csv me-2"></i>Selected File</h6>
                                    <p id="fileName" class="mb-0"></p>
                                </div>
                            </div>
                        </div>
                    </form>

                    <div class="mt-3">
                        <h6>Sample CSV Format:</h6>
                        <pre class="bg-light p-2 rounded"><code>sku,description,quantity,unit_cost,unit_of_measure
ITEM001,Sample Product 1,100,10.50,EA
ITEM002,Sample Product 2,50,25.00,BOX
ITEM003,Sample Product 3,200,5.75,EA</code></pre>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-success" onclick="importCsv()" id="importBtn" disabled>
                        <i class="fas fa-upload me-2"></i>Import Lines
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const asnId = <?php echo $asnId; ?>;
        const canEdit = <?php echo json_encode($canEdit); ?>;
        const canReceive = <?php echo json_encode($canReceive); ?>;
        const csrfToken = '<?php echo $csrfToken; ?>';

        // Initialize page
        document.addEventListener('DOMContentLoaded', function() {
            loadLines();
            setupDragDrop();
        });

        function loadLines() {
            showLoading(true);
            
            const formData = new FormData();
            formData.append('action', 'get_lines_data');
            formData.append('csrf_token', csrfToken);

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    renderLinesTable(data.lines);
                } else {
                    showAlert('Error loading line items: ' + (data.error || 'Unknown error'), 'danger');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showAlert('Failed to load line items', 'danger');
            })
            .finally(() => {
                showLoading(false);
            });
        }

        function renderLinesTable(lines) {
            const tbody = document.getElementById('linesTableBody');
            
            if (lines.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="9" class="text-center py-4">
                            <i class="fas fa-inbox fa-2x mb-2 text-muted"></i>
                            <p class="text-muted">No line items found</p>
                            ${canEdit ? '<button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addLineModal"><i class="fas fa-plus me-2"></i>Add First Line</button>' : ''}
                        </td>
                    </tr>
                `;
                return;
            }

            tbody.innerHTML = lines.map(line => {
                const totalValue = line.quantity * line.unit_cost;
                const receivedPercentage = line.quantity > 0 ? (line.received_quantity / line.quantity * 100) : 0;
                
                return `
                    <tr>
                        <td><strong>${line.line_number}</strong></td>
                        <td>
                            <strong>${escapeHtml(line.sku)}</strong>
                            ${line.lot_number ? `<br><small class="text-muted">Lot: ${escapeHtml(line.lot_number)}</small>` : ''}
                        </td>
                        <td>
                            ${escapeHtml(line.description || '')}
                            ${line.notes ? `<br><small class="text-muted">${escapeHtml(line.notes)}</small>` : ''}
                        </td>
                        <td>
                            <strong>${formatNumber(line.quantity)}</strong>
                            <br><small class="text-muted">${escapeHtml(line.unit_of_measure)}</small>
                        </td>
                        <td>
                            <strong class="${line.received_quantity >= line.quantity ? 'text-success' : 'text-warning'}">
                                ${formatNumber(line.received_quantity)}
                            </strong>
                            <div class="progress progress-thin mt-1">
                                <div class="progress-bar ${line.received_quantity >= line.quantity ? 'bg-success' : 'bg-warning'}" 
                                     style="width: ${Math.min(receivedPercentage, 100)}%"></div>
                            </div>
                        </td>
                        <td>
                            <span class="receive-status ${line.receive_status}">
                                ${line.receive_status.charAt(0).toUpperCase() + line.receive_status.slice(1)}
                            </span>
                        </td>
                        <td>
                            ${line.unit_cost > 0 ? '$' + formatNumber(line.unit_cost, 2) : '-'}
                        </td>
                        <td>
                            <strong>$${formatNumber(totalValue, 2)}</strong>
                        </td>
                        <td>
                            <div class="action-buttons">
                                ${canEdit ? `
                                    <button class="btn btn-outline-primary btn-sm" onclick="editLine(${line.id})" title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                ` : ''}
                                ${canReceive ? `
                                    <button class="btn btn-outline-success btn-sm" onclick="showReceiveModal(${line.id})" title="Receive">
                                        <i class="fas fa-box"></i>
                                    </button>
                                ` : ''}
                                ${canEdit && line.received_quantity == 0 ? `
                                    <button class="btn btn-outline-danger btn-sm" onclick="deleteLine(${line.id})" title="Delete">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                ` : ''}
                            </div>
                        </td>
                    </tr>
                `;
            }).join('');
        }

        function showAddLineModal() {
            document.getElementById('modalTitle').textContent = 'Add Line Item';
            document.getElementById('lineForm').reset();
            document.getElementById('lineId').value = '';
            new bootstrap.Modal(document.getElementById('addLineModal')).show();
        }

        function editLine(lineId) {
            // This would fetch line details and populate the modal
            // For now, we'll just show the modal
            showAddLineModal();
        }

        function saveLine() {
            const form = document.getElementById('lineForm');
            const formData = new FormData(form);
            
            const lineId = document.getElementById('lineId').value;
            formData.append('action', lineId ? 'update_line' : 'add_line');
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
                    showAlert(lineId ? 'Line item updated successfully' : 'Line item added successfully', 'success');
                    bootstrap.Modal.getInstance(document.getElementById('addLineModal')).hide();
                    loadLines();
                } else {
                    showAlert('Error: ' + (data.error || 'Unknown error'), 'danger');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showAlert('Failed to save line item', 'danger');
            })
            .finally(() => {
                showLoading(false);
            });
        }

        function showReceiveModal(lineId) {
            // Find the line data from the current table
            const lines = Array.from(document.querySelectorAll('#linesTableBody tr')).map(row => {
                const cells = row.querySelectorAll('td');
                if (cells.length < 9) return null;
                
                return {
                    id: lineId, // This would need to be stored in the row somehow
                    sku: cells[1].querySelector('strong').textContent,
                    quantity: parseFloat(cells[3].querySelector('strong').textContent.replace(/,/g, '')),
                    received_quantity: parseFloat(cells[4].querySelector('strong').textContent.replace(/,/g, ''))
                };
            }).filter(line => line && line.id === lineId)[0];

            if (lines) {
                document.getElementById('receiveLineId').value = lineId;
                document.getElementById('receiveSku').textContent = lines.sku;
                document.getElementById('receiveExpected').textContent = formatNumber(lines.quantity);
                document.getElementById('receiveCurrently').textContent = formatNumber(lines.received_quantity);
                document.getElementById('received_quantity').value = lines.received_quantity;
                document.getElementById('received_quantity').max = lines.quantity;
                
                new bootstrap.Modal(document.getElementById('receiveModal')).show();
            }
        }

        function saveReceiveQuantity() {
            const form = document.getElementById('receiveForm');
            const formData = new FormData(form);
            formData.append('action', 'receive_quantity');
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
                    showAlert('Received quantity updated successfully', 'success');
                    bootstrap.Modal.getInstance(document.getElementById('receiveModal')).hide();
                    loadLines();
                } else {
                    showAlert('Error: ' + (data.error || 'Unknown error'), 'danger');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showAlert('Failed to update received quantity', 'danger');
            })
            .finally(() => {
                showLoading(false);
            });
        }

        function deleteLine(lineId) {
            if (!confirm('Are you sure you want to delete this line item?')) {
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'delete_line');
            formData.append('line_id', lineId);
            formData.append('csrf_token', csrfToken);
            
            showLoading(true);

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showAlert('Line item deleted successfully', 'success');
                    loadLines();
                } else {
                    showAlert('Error: ' + (data.error || 'Unknown error'), 'danger');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showAlert('Failed to delete line item', 'danger');
            })
            .finally(() => {
                showLoading(false);
            });
        }

        function refreshLines() {
            loadLines();
        }

        function exportToCsv() {
            const table = document.getElementById('linesTable');
            const rows = Array.from(table.querySelectorAll('tr'));
            
            const csvContent = rows.map(row => {
                const cells = Array.from(row.querySelectorAll('th, td'));
                return cells.slice(0, -1).map(cell => {
                    const text = cell.textContent.trim().replace(/\s+/g, ' ');
                    return `"${text.replace(/"/g, '""')}"`;
                }).join(',');
            }).join('\n');

            const blob = new Blob([csvContent], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `asn_${asnId}_lines.csv`;
            a.click();
            window.URL.revokeObjectURL(url);
        }

        function setupDragDrop() {
            const dragDropArea = document.getElementById('dragDropArea');
            const fileInput = document.getElementById('csvFile');
            const fileSelectLink = document.getElementById('fileSelectLink');
            const fileInfo = document.getElementById('fileInfo');
            const fileName = document.getElementById('fileName');
            const importBtn = document.getElementById('importBtn');

            fileSelectLink.addEventListener('click', (e) => {
                e.preventDefault();
                fileInput.click();
            });

            fileInput.addEventListener('change', handleFileSelect);

            dragDropArea.addEventListener('dragover', (e) => {
                e.preventDefault();
                dragDropArea.classList.add('dragover');
            });

            dragDropArea.addEventListener('dragleave', () => {
                dragDropArea.classList.remove('dragover');
            });

            dragDropArea.addEventListener('drop', (e) => {
                e.preventDefault();
                dragDropArea.classList.remove('dragover');
                
                const files = e.dataTransfer.files;
                if (files.length > 0) {
                    fileInput.files = files;
                    handleFileSelect();
                }
            });

            function handleFileSelect() {
                const file = fileInput.files[0];
                if (file) {
                    fileName.textContent = `${file.name} (${formatFileSize(file.size)})`;
                    fileInfo.style.display = 'block';
                    importBtn.disabled = false;
                } else {
                    fileInfo.style.display = 'none';
                    importBtn.disabled = true;
                }
            }
        }

        function importCsv() {
            const fileInput = document.getElementById('csvFile');
            if (!fileInput.files[0]) {
                showAlert('Please select a CSV file', 'warning');
                return;
            }

            const formData = new FormData();
            formData.append('action', 'import_from_csv');
            formData.append('csv_file', fileInput.files[0]);
            formData.append('csrf_token', csrfToken);
            
            showLoading(true);

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    let message = `Successfully imported ${data.imported} line items`;
                    if (data.errors && data.errors.length > 0) {
                        message += `\n\nErrors:\n${data.errors.join('\n')}`;
                    }
                    showAlert(message, 'success');
                    bootstrap.Modal.getInstance(document.getElementById('importModal')).hide();
                    loadLines();
                } else {
                    let message = 'Import failed: ' + (data.error || 'Unknown error');
                    if (data.errors && data.errors.length > 0) {
                        message += `\n\nErrors:\n${data.errors.join('\n')}`;
                    }
                    showAlert(message, 'danger');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showAlert('Failed to import CSV file', 'danger');
            })
            .finally(() => {
                showLoading(false);
            });
        }

        function showLoading(show) {
            document.getElementById('loadingOverlay').style.display = show ? 'flex' : 'none';
        }

        function showAlert(message, type) {
            const alert = document.createElement('div');
            alert.className = `alert alert-${type} alert-dismissible fade show`;
            alert.innerHTML = `
                ${message.replace(/\n/g, '<br>')}
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

        function formatFileSize(bytes) {
            if (bytes === 0) return '0 Bytes';
            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }
    </script>
</body>
</html>