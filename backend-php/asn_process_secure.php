<?php
/**
 * Secure ASN Processing System
 * Enhanced with comprehensive security measures
 *
 * Security Features:
 * - CSRF Protection
 * - SQL Injection Prevention
 * - Input Validation & Sanitization
 * - XSS Prevention
 * - Activity Logging
 * - Transaction Management
 */

// Start session and include security utilities
session_start();
require_once 'security-utils.php';
require_once 'auth.php';
require_once 'db_config.php';

// Require login and set security headers
require_login();
$security = SecurityUtils::getInstance($conn);
$security->setSecurityHeaders();

// Initialize variables
$message = "";
$errors = [];
$asn_list = [];
$processing_results = [];

// Generate CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = $security->generateCSRFToken();
}

/**
 * Validate ASN processing input
 */
function validateProcessingInput($data, &$errors) {
    if (empty($data['asn_number'])) {
        $errors['asn_number'] = "ASN number is required";
        return false;
    }

    if (!preg_match('/^[A-Za-z0-9\-_]+$/', $data['asn_number'])) {
        $errors['asn_number'] = "Invalid ASN number format";
        return false;
    }

    return empty($errors);
}

/**
 * Check ASN processing eligibility
 */
function checkASNEligibility($conn, $asn_number) {
    $asn = secure_select_one($conn,
        "SELECT asn_number, status, supplier_name FROM asn_header WHERE asn_number = ?",
        "s",
        [$asn_number]
    );

    if (!$asn) {
        throw new Exception('ASN not found');
    }

    if ($asn['status'] === 'Completed') {
        throw new Exception('ASN has already been processed');
    }

    return $asn;
}

/**
 * Process ASN lines into inventory
 */
function processASNLines($conn, $asn_number, $user_id) {
    $processing_results = [
        'lines_processed' => 0,
        'items_added' => 0,
        'items_updated' => 0,
        'errors' => []
    ];

    try {
        // Begin transaction
        $conn->autocommit(false);

        // Fetch ASN lines
        $lines = secure_select_all($conn,
            "SELECT * FROM asn_lines WHERE asn_number = ? ORDER BY line_id",
            "s",
            [$asn_number]
        );

        if (empty($lines)) {
            throw new Exception('No ASN lines found to process');
        }

        foreach ($lines as $line) {
            try {
                // Check if inventory record already exists
                $existing = secure_select_one($conn,
                    "SELECT id, qty_on_hand FROM inventory WHERE sku_id = ? AND batch_id = ? AND expiry_date = ?",
                    "sss",
                    [$line['sku_id'], $line['batch_id'] ?? '', $line['expiry_date'] ?? null]
                );

                if ($existing) {
                    // Update existing inventory
                    $new_qty = $existing['qty_on_hand'] + $line['qty_received'];

                    $updated = secure_update($conn, 'inventory',
                        [
                            'qty_on_hand' => $new_qty,
                            'last_updated' => date('Y-m-d H:i:s'),
                            'updated_by' => $user_id
                        ],
                        'id = ?',
                        'i',
                        [$existing['id']]
                    );

                    if ($updated) {
                        $processing_results['items_updated']++;
                    }
                } else {
                    // Insert new inventory record
                    $inventory_data = [
                        'sku_id' => $line['sku_id'],
                        'qty_on_hand' => $line['qty_received'],
                        'qty_allocated' => 0,
                        'batch_id' => $line['batch_id'] ?? '',
                        'condition' => $line['condition'] ?? 'Good',
                        'location_id' => 'RCV01', // Default receiving location
                        'expiry_date' => $line['expiry_date'] ?? null,
                        'receipt_id' => $asn_number,
                        'line_id' => $line['line_id'],
                        'created_by' => $user_id,
                        'last_updated' => date('Y-m-d H:i:s')
                    ];

                    $insert_id = secure_insert($conn, 'inventory', $inventory_data);

                    if ($insert_id) {
                        $processing_results['items_added']++;
                    }
                }

                // Log inventory movement
                $movement_data = [
                    'sku_id' => $line['sku_id'],
                    'movement_type' => 'INBOUND',
                    'quantity' => $line['qty_received'],
                    'reference_number' => $asn_number,
                    'location_from' => 'RECEIVING',
                    'location_to' => 'RCV01',
                    'batch_id' => $line['batch_id'] ?? '',
                    'user_id' => $user_id,
                    'created_at' => date('Y-m-d H:i:s')
                ];

                secure_insert($conn, 'inventory_movements', $movement_data);

                $processing_results['lines_processed']++;

            } catch (Exception $e) {
                $processing_results['errors'][] = "Line {$line['line_id']}: " . $e->getMessage();
                error_log("ASN Line Processing Error: " . $e->getMessage());
            }
        }

        // Update ASN header status
        $updated_asn = secure_update($conn, 'asn_header',
            [
                'status' => 'Completed',
                'processed_at' => date('Y-m-d H:i:s'),
                'processed_by' => $user_id
            ],
            'asn_number = ?',
            's',
            [$asn_number]
        );

        if (!$updated_asn) {
            throw new Exception('Failed to update ASN status');
        }

        // Commit transaction
        $conn->commit();

        return $processing_results;

    } catch (Exception $e) {
        // Rollback transaction
        $conn->rollback();
        throw $e;
    } finally {
        // Re-enable autocommit
        $conn->autocommit(true);
    }
}

/**
 * Log ASN processing activity
 */
function logProcessingActivity($conn, $action, $asn_number, $user_id, $details = '') {
    $security = SecurityUtils::getInstance($conn);
    $security->logActivity($user_id, $action,
        "ASN: $asn_number" . ($details ? ", $details" : ''));
}

// Handle ASN processing
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['process_asn'])) {
    try {
        // Verify CSRF token
        if (!$security->validateCSRFToken($_POST['csrf_token'] ?? '')) {
            throw new Exception("Invalid security token. Please refresh the page and try again.");
        }

        // Rate limiting
        if (!$security->checkRateLimit($_SESSION['user_id'], 'asn_process', 5, 300)) {
            throw new Exception("Too many processing requests. Please wait before processing another ASN.");
        }

        // Sanitize input
        $input_data = [
            'asn_number' => $security->sanitizeInput($_POST['asn_number'] ?? '', 50)
        ];

        // Validate input
        if (!validateProcessingInput($input_data, $errors)) {
            throw new Exception("Please correct the validation errors below.");
        }

        // Check ASN eligibility
        $asn_details = checkASNEligibility($conn, $input_data['asn_number']);

        // Process ASN
        $processing_results = processASNLines($conn, $input_data['asn_number'], $_SESSION['user_id']);

        // Log successful processing
        logProcessingActivity($conn, 'ASN_PROCESSED', $input_data['asn_number'], $_SESSION['user_id'],
            "Lines: {$processing_results['lines_processed']}, Added: {$processing_results['items_added']}, Updated: {$processing_results['items_updated']}");

        $message = "✅ ASN processed successfully!<br>
                   <strong>ASN:</strong> " . htmlspecialchars($input_data['asn_number']) . "<br>
                   <strong>Lines Processed:</strong> {$processing_results['lines_processed']}<br>
                   <strong>Items Added:</strong> {$processing_results['items_added']}<br>
                   <strong>Items Updated:</strong> {$processing_results['items_updated']}";

        if (!empty($processing_results['errors'])) {
            $message .= "<br><br><strong>Warnings:</strong><br>" . implode('<br>', array_map('htmlspecialchars', $processing_results['errors']));
        }

    } catch (Exception $e) {
        $message = "❌ Error: " . htmlspecialchars($e->getMessage());

        // Log security events
        if (strpos($e->getMessage(), 'security token') !== false ||
            strpos($e->getMessage(), 'Too many requests') !== false) {

            $security->logSecurityEvent($_SESSION['user_id'], 'asn_processing_security_violation', $e->getMessage());
        }

        error_log("ASN Processing Error: " . $e->getMessage() . " | User: " . $_SESSION['user_id'] . " | IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    }
}

// Fetch available ASNs for processing
try {
    $asn_list = secure_select_all($conn,
        "SELECT ah.asn_number, ah.supplier_name, ah.arrival_date, ah.status, ah.created_at,
                COUNT(al.line_id) as line_count,
                SUM(al.qty_expected) as total_expected,
                SUM(al.qty_received) as total_received
         FROM asn_header ah
         LEFT JOIN asn_lines al ON ah.asn_number = al.asn_number
         WHERE ah.status != 'Completed'
         GROUP BY ah.asn_number, ah.supplier_name, ah.arrival_date, ah.status, ah.created_at
         ORDER BY ah.created_at DESC"
    );
} catch (Exception $e) {
    error_log("Failed to load ASN list: " . $e->getMessage());
    $asn_list = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="ASN Processing - Secure WMS">
    <title>Process ASN | Secure WMS</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="modern-style.css">
    <style>
        .processing-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 2rem;
        }

        .page-header {
            text-align: center;
            margin-bottom: 3rem;
            padding: 2rem;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 12px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
        }

        .processing-form {
            background: white;
            padding: 2rem;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            margin-bottom: 2rem;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: #374151;
        }

        .form-select {
            width: 100%;
            padding: 12px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 16px;
            transition: border-color 0.3s;
        }

        .form-select:focus {
            outline: none;
            border-color: #4f46e5;
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-primary {
            background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 8px 25px rgba(79, 70, 229, 0.4);
        }

        .btn-primary:disabled {
            background: #9ca3af;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        .btn-secondary {
            background: #f3f4f6;
            color: #374151;
        }

        .btn-secondary:hover {
            background: #e5e7eb;
        }

        .asn-list {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            overflow: hidden;
        }

        .list-header {
            background: #f8fafc;
            padding: 1.5rem;
            border-bottom: 1px solid #e2e8f0;
        }

        .asn-table {
            width: 100%;
            border-collapse: collapse;
        }

        .asn-table th {
            background: #f8fafc;
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            color: #374151;
            border-bottom: 2px solid #e2e8f0;
        }

        .asn-table td {
            padding: 1rem;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: top;
        }

        .asn-table tbody tr:hover {
            background: #f8fafc;
        }

        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 500;
            text-transform: uppercase;
        }

        .status-pending { background: #fef3c7; color: #92400e; }
        .status-released { background: #d1fae5; color: #065f46; }
        .status-hold { background: #fee2e2; color: #991b1b; }
        .status-in-progress { background: #dbeafe; color: #1e40af; }

        .progress-bar {
            width: 100%;
            height: 8px;
            background: #e2e8f0;
            border-radius: 4px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #10b981, #059669);
            transition: width 0.3s ease;
        }

        .progress-text {
            font-size: 0.875rem;
            color: #6b7280;
            margin-top: 0.25rem;
        }

        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
        }

        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border-left: 4px solid #10b981;
        }

        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border-left: 4px solid #ef4444;
        }

        .empty-state {
            text-align: center;
            padding: 3rem;
            color: #6b7280;
        }

        .processing-details {
            background: #f8fafc;
            padding: 1rem;
            border-radius: 8px;
            margin-top: 1rem;
            font-size: 0.875rem;
        }

        @media (max-width: 768px) {
            .processing-container {
                padding: 1rem;
            }

            .page-header {
                padding: 1rem;
            }

            .asn-table {
                font-size: 0.875rem;
            }

            .asn-table th,
            .asn-table td {
                padding: 0.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="processing-container">
        <!-- Page Header -->
        <div class="page-header">
            <h1 style="margin: 0; font-size: 2.5rem;">🚚 Process ASN Inbound</h1>
            <p style="margin: 0.5rem 0 0; font-size: 1.25rem; opacity: 0.9;">
                Process Advanced Shipping Notices into inventory
            </p>
        </div>

        <!-- Messages -->
        <?php if ($message): ?>
            <div class="alert <?= strpos($message, '✅') !== false ? 'alert-success' : 'alert-error' ?>">
                <?= $message ?>
            </div>
        <?php endif; ?>

        <!-- Processing Form -->
        <div class="processing-form">
            <h2 style="margin-top: 0; color: #374151;">Select ASN to Process</h2>
            <p style="color: #6b7280; margin-bottom: 1.5rem;">
                Choose an ASN from the list below to process into inventory. Only ASNs with status other than "Completed" can be processed.
            </p>

            <form method="POST" id="processingForm"
                  onsubmit="return confirm('Are you sure you want to process this ASN? This action will update inventory levels and cannot be easily undone.')">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

                <div class="form-group">
                    <label for="asn_number" class="form-label">ASN Number <span style="color: #dc2626;">*</span></label>
                    <select name="asn_number" id="asn_number" class="form-select" required>
                        <option value="">-- Select ASN to Process --</option>
                        <?php foreach ($asn_list as $asn): ?>
                            <?php
                            $completion_rate = $asn['total_expected'] > 0 ?
                                round(($asn['total_received'] / $asn['total_expected']) * 100, 1) : 0;
                            ?>
                            <option value="<?= htmlspecialchars($asn['asn_number']) ?>"
                                    data-supplier="<?= htmlspecialchars($asn['supplier_name']) ?>"
                                    data-lines="<?= $asn['line_count'] ?>"
                                    data-expected="<?= $asn['total_expected'] ?>"
                                    data-received="<?= $asn['total_received'] ?>"
                                    data-completion="<?= $completion_rate ?>">
                                <?= htmlspecialchars($asn['asn_number']) ?> -
                                <?= htmlspecialchars($asn['supplier_name']) ?>
                                (<?= $asn['line_count'] ?> lines, <?= $completion_rate ?>% received)
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <!-- ASN Details Display -->
                    <div id="asnDetails" class="processing-details" style="display: none;">
                        <h4 style="margin: 0 0 0.5rem;">ASN Processing Details:</h4>
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
                            <div>
                                <strong>Supplier:</strong> <span id="detailSupplier">-</span>
                            </div>
                            <div>
                                <strong>Line Items:</strong> <span id="detailLines">-</span>
                            </div>
                            <div>
                                <strong>Expected Qty:</strong> <span id="detailExpected">-</span>
                            </div>
                            <div>
                                <strong>Received Qty:</strong> <span id="detailReceived">-</span>
                            </div>
                        </div>
                        <div style="margin-top: 1rem;">
                            <strong>Reception Progress:</strong>
                            <div class="progress-bar" style="margin-top: 0.5rem;">
                                <div class="progress-fill" id="progressFill" style="width: 0%"></div>
                            </div>
                            <div class="progress-text" id="progressText">0% received</div>
                        </div>
                    </div>
                </div>

                <div style="display: flex; gap: 1rem; align-items: center;">
                    <button type="submit" name="process_asn" class="btn btn-primary" id="processBtn" disabled>
                        🚚 Process ASN into Inventory
                    </button>
                    <a href="inbound_secure.php" class="btn btn-secondary">
                        ⬅️ Back to Inbound Dashboard
                    </a>
                </div>
            </form>
        </div>

        <!-- Available ASNs List -->
        <div class="asn-list">
            <div class="list-header">
                <h2 style="margin: 0; color: #374151;">Available ASNs for Processing</h2>
                <p style="margin: 0.5rem 0 0; color: #6b7280;">
                    ASNs ready for inventory processing
                </p>
            </div>

            <?php if (!empty($asn_list)): ?>
                <div style="overflow-x: auto;">
                    <table class="asn-table">
                        <thead>
                            <tr>
                                <th>ASN Number</th>
                                <th>Supplier</th>
                                <th>Status</th>
                                <th>Arrival Date</th>
                                <th>Lines</th>
                                <th>Progress</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($asn_list as $asn): ?>
                                <?php
                                $completion_rate = $asn['total_expected'] > 0 ?
                                    round(($asn['total_received'] / $asn['total_expected']) * 100, 1) : 0;
                                ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($asn['asn_number']) ?></strong>
                                    </td>
                                    <td><?= htmlspecialchars($asn['supplier_name']) ?></td>
                                    <td>
                                        <span class="status-badge status-<?= strtolower(str_replace(' ', '-', $asn['status'])) ?>">
                                            <?= htmlspecialchars($asn['status']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?= $asn['arrival_date'] ? date('M d, Y', strtotime($asn['arrival_date'])) : '-' ?>
                                    </td>
                                    <td><?= number_format($asn['line_count']) ?></td>
                                    <td>
                                        <div class="progress-bar">
                                            <div class="progress-fill" style="width: <?= $completion_rate ?>%"></div>
                                        </div>
                                        <div class="progress-text"><?= $completion_rate ?>% received</div>
                                    </td>
                                    <td>
                                        <a href="asn_lines_secure.php?asn_number=<?= urlencode($asn['asn_number']) ?>"
                                           class="btn btn-secondary" style="font-size: 0.875rem; padding: 0.5rem 1rem;">
                                            👁️ View Details
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <h3>No ASNs Available for Processing</h3>
                    <p>There are currently no ASNs ready for processing.</p>
                    <a href="create_asn_secure.php" class="btn btn-primary">
                        ➕ Create New ASN
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // ASN selection handling
        document.getElementById('asn_number').addEventListener('change', function() {
            const selected = this.selectedOptions[0];
            const processBtn = document.getElementById('processBtn');
            const detailsDiv = document.getElementById('asnDetails');

            if (this.value) {
                // Show ASN details
                document.getElementById('detailSupplier').textContent = selected.dataset.supplier || '-';
                document.getElementById('detailLines').textContent = selected.dataset.lines || '-';
                document.getElementById('detailExpected').textContent =
                    selected.dataset.expected ? parseInt(selected.dataset.expected).toLocaleString() : '-';
                document.getElementById('detailReceived').textContent =
                    selected.dataset.received ? parseInt(selected.dataset.received).toLocaleString() : '-';

                const completion = parseFloat(selected.dataset.completion) || 0;
                document.getElementById('progressFill').style.width = completion + '%';
                document.getElementById('progressText').textContent = completion + '% received';

                detailsDiv.style.display = 'block';
                processBtn.disabled = false;
            } else {
                detailsDiv.style.display = 'none';
                processBtn.disabled = true;
            }
        });

        // Form submission handling
        document.getElementById('processingForm').addEventListener('submit', function(e) {
            const processBtn = document.getElementById('processBtn');
            processBtn.disabled = true;
            processBtn.textContent = '🔄 Processing ASN...';

            // Re-enable button after 10 seconds as fallback
            setTimeout(() => {
                processBtn.disabled = false;
                processBtn.textContent = '🚚 Process ASN into Inventory';
            }, 10000);
        });

        // Auto-refresh every 30 seconds if page is visible
        setInterval(function() {
            if (document.visibilityState === 'visible' && !document.getElementById('processingForm').disabled) {
                // Could implement live updates here
            }
        }, 30000);
    </script>
</body>
</html>

<?php
// Clean up and close connections
if (isset($conn)) {
    $conn->close();
}

// Clean sensitive variables
unset($asn_list, $processing_results, $errors);
?>
