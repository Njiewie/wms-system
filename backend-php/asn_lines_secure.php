<?php
/**
 * Secure ASN Lines Management System
 * Enhanced with comprehensive security measures
 *
 * Security Features:
 * - CSRF Protection
 * - SQL Injection Prevention
 * - Input Validation & Sanitization
 * - XSS Prevention
 * - Activity Logging
 * - Access Control
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
$asn_lines = [];
$asn_details = null;
$message = "";
$errors = [];

// Validate ASN number parameter
if (!isset($_GET['asn_number'])) {
    $security->logSecurityEvent($_SESSION['user_id'] ?? 'unknown', 'invalid_asn_access',
        'Attempted to access ASN lines without ASN number');
    header('Location: inbound_secure.php?error=' . urlencode('ASN number is required'));
    exit;
}

try {
    // Sanitize and validate ASN number
    $asn_number = $security->sanitizeInput($_GET['asn_number'], 50);

    if (empty($asn_number) || !preg_match('/^[A-Za-z0-9\-_]+$/', $asn_number)) {
        throw new InvalidArgumentException('Invalid ASN number format');
    }

    // Rate limiting
    if (!$security->checkRateLimit($_SESSION['user_id'], 'asn_view', 20, 300)) {
        throw new Exception('Too many requests. Please wait before viewing more ASNs.');
    }

    // Check if user has access to this ASN (if using multi-client system)
    $access_check = secure_select_one($conn,
        "SELECT ah.asn_number, ah.supplier_name, ah.arrival_date, ah.status, ah.created_at,
                c.client_name, uca.user_id
         FROM asn_header ah
         LEFT JOIN clients c ON ah.client_id = c.id
         LEFT JOIN user_client_access uca ON c.id = uca.client_id
         WHERE ah.asn_number = ? AND (uca.user_id = ? OR uca.user_id IS NULL)",
        "si",
        [$asn_number, $_SESSION['user_id']]
    );

    if (!$access_check) {
        $security->logSecurityEvent($_SESSION['user_id'], 'unauthorized_asn_access',
            "Attempted to access ASN: $asn_number");
        throw new Exception('ASN not found or access denied');
    }

    $asn_details = $access_check;

    // Fetch ASN lines with security
    $asn_lines = secure_select_all($conn,
        "SELECT al.*, sm.description as sku_description, sm.pack_config
         FROM asn_lines al
         LEFT JOIN sku_master sm ON al.sku_id = sm.sku_id
         WHERE al.asn_number = ?
         ORDER BY al.line_id ASC",
        "s",
        [$asn_number]
    );

    // Log successful access
    $security->logActivity($_SESSION['user_id'], 'asn_lines_viewed',
        "ASN: $asn_number, Lines: " . count($asn_lines));

} catch (Exception $e) {
    $message = "❌ Error: " . htmlspecialchars($e->getMessage());

    // Log security events
    if (strpos($e->getMessage(), 'Invalid') !== false ||
        strpos($e->getMessage(), 'access denied') !== false ||
        strpos($e->getMessage(), 'Too many requests') !== false) {

        $security->logSecurityEvent($_SESSION['user_id'], 'asn_access_violation', $e->getMessage());
    }

    error_log("ASN Lines Access Error: " . $e->getMessage() . " | User: " . $_SESSION['user_id'] . " | IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="ASN Lines Management - Secure WMS">
    <title>ASN Details | Secure WMS</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="modern-style.css">
    <style>
        .asn-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem;
            border-radius: 12px;
            margin-bottom: 2rem;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
        }

        .asn-info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }

        .info-card {
            background: rgba(255, 255, 255, 0.1);
            padding: 1rem;
            border-radius: 8px;
            backdrop-filter: blur(10px);
        }

        .info-label {
            font-size: 0.875rem;
            opacity: 0.8;
            margin-bottom: 0.25rem;
        }

        .info-value {
            font-size: 1.125rem;
            font-weight: 600;
        }

        .action-bar {
            background: white;
            padding: 1.5rem;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            margin-bottom: 2rem;
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
        }

        .lines-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            overflow: hidden;
        }

        .lines-header {
            background: #f8fafc;
            padding: 1.5rem;
            border-bottom: 1px solid #e2e8f0;
        }

        .table-container {
            overflow-x: auto;
        }

        .lines-table {
            width: 100%;
            border-collapse: collapse;
        }

        .lines-table th {
            background: #f8fafc;
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            color: #374151;
            border-bottom: 2px solid #e2e8f0;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .lines-table td {
            padding: 1rem;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: top;
        }

        .lines-table tbody tr:hover {
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
        .status-received { background: #d1fae5; color: #065f46; }
        .status-partial { background: #dbeafe; color: #1e40af; }
        .status-hold { background: #fee2e2; color: #991b1b; }

        .qty-comparison {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .qty-expected {
            color: #6b7280;
        }

        .qty-received {
            font-weight: 600;
            color: #059669;
        }

        .qty-variance {
            font-size: 0.875rem;
            padding: 0.125rem 0.5rem;
            border-radius: 4px;
        }

        .variance-positive {
            background: #d1fae5;
            color: #065f46;
        }

        .variance-negative {
            background: #fee2e2;
            color: #991b1b;
        }

        .variance-zero {
            background: #f3f4f6;
            color: #6b7280;
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 8px;
            font-weight: 500;
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

        .btn-secondary {
            background: #f3f4f6;
            color: #374151;
        }

        .btn-secondary:hover {
            background: #e5e7eb;
        }

        .btn-danger {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            color: white;
        }

        .btn-danger:hover {
            transform: translateY(-1px);
            box-shadow: 0 8px 25px rgba(239, 68, 68, 0.4);
        }

        .empty-state {
            text-align: center;
            padding: 3rem;
            color: #6b7280;
        }

        .empty-state h3 {
            margin-bottom: 0.5rem;
            color: #374151;
        }

        .summary-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }

        .stat-card {
            background: rgba(255, 255, 255, 0.1);
            padding: 1rem;
            border-radius: 8px;
            text-align: center;
            backdrop-filter: blur(10px);
        }

        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }

        .stat-label {
            font-size: 0.875rem;
            opacity: 0.8;
        }

        @media (max-width: 768px) {
            .asn-header {
                padding: 1rem;
            }

            .action-bar {
                flex-direction: column;
                align-items: stretch;
            }

            .btn {
                justify-content: center;
            }

            .lines-table th,
            .lines-table td {
                padding: 0.5rem;
                font-size: 0.875rem;
            }
        }

        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
        }

        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border-left: 4px solid #ef4444;
        }
    </style>
</head>
<body>
    <div class="container" style="max-width: 1400px; margin: 0 auto; padding: 2rem;">
        <!-- Error Message -->
        <?php if ($message): ?>
            <div class="alert alert-error">
                <?= $message ?>
                <div style="margin-top: 1rem;">
                    <a href="inbound_secure.php" class="btn btn-secondary">⬅️ Back to Inbound</a>
                </div>
            </div>
        <?php elseif ($asn_details): ?>

        <!-- ASN Header -->
        <div class="asn-header">
            <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 1rem;">
                <div>
                    <h1 style="margin: 0; font-size: 2.5rem;">📦 ASN Details</h1>
                    <p style="margin: 0.5rem 0 0; font-size: 1.25rem; opacity: 0.9;">
                        <?= htmlspecialchars($asn_number) ?>
                    </p>
                </div>
                <div class="status-badge status-<?= strtolower($asn_details['status'] ?? 'pending') ?>">
                    <?= htmlspecialchars($asn_details['status'] ?? 'Pending') ?>
                </div>
            </div>

            <div class="asn-info">
                <div class="info-card">
                    <div class="info-label">Supplier</div>
                    <div class="info-value"><?= htmlspecialchars($asn_details['supplier_name'] ?? 'N/A') ?></div>
                </div>
                <div class="info-card">
                    <div class="info-label">Arrival Date</div>
                    <div class="info-value"><?= htmlspecialchars($asn_details['arrival_date'] ?? 'N/A') ?></div>
                </div>
                <div class="info-card">
                    <div class="info-label">Created</div>
                    <div class="info-value"><?= $asn_details['created_at'] ? date('M d, Y', strtotime($asn_details['created_at'])) : 'N/A' ?></div>
                </div>
                <?php if (isset($asn_details['client_name'])): ?>
                <div class="info-card">
                    <div class="info-label">Client</div>
                    <div class="info-value"><?= htmlspecialchars($asn_details['client_name']) ?></div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Summary Statistics -->
            <?php if (!empty($asn_lines)): ?>
                <?php
                $total_lines = count($asn_lines);
                $total_expected = array_sum(array_column($asn_lines, 'qty_expected'));
                $total_received = array_sum(array_column($asn_lines, 'qty_received'));
                $completion_rate = $total_expected > 0 ? round(($total_received / $total_expected) * 100, 1) : 0;
                ?>
                <div class="summary-stats">
                    <div class="stat-card">
                        <div class="stat-number"><?= $total_lines ?></div>
                        <div class="stat-label">Total Lines</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?= number_format($total_expected) ?></div>
                        <div class="stat-label">Expected Qty</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?= number_format($total_received) ?></div>
                        <div class="stat-label">Received Qty</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?= $completion_rate ?>%</div>
                        <div class="stat-label">Completion</div>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Action Bar -->
        <div class="action-bar">
            <div style="display: flex; gap: 1rem; flex-wrap: wrap;">
                <a href="inbound_secure.php" class="btn btn-secondary">
                    ⬅️ Back to Inbound
                </a>
                <a href="edit_asn_secure.php?asn_number=<?= urlencode($asn_number) ?>" class="btn btn-primary">
                    ✏️ Edit ASN
                </a>
                <a href="asn_process_secure.php?asn_number=<?= urlencode($asn_number) ?>" class="btn btn-primary">
                    🚚 Process ASN
                </a>
            </div>
            <div style="display: flex; gap: 1rem;">
                <button class="btn btn-secondary" onclick="window.print()">
                    🖨️ Print
                </button>
                <button class="btn btn-danger" onclick="confirmDelete('<?= htmlspecialchars($asn_number) ?>')">
                    🗑️ Delete ASN
                </button>
            </div>
        </div>

        <!-- ASN Lines -->
        <div class="lines-container">
            <div class="lines-header">
                <h2 style="margin: 0; color: #374151;">ASN Line Items</h2>
                <p style="margin: 0.5rem 0 0; color: #6b7280;">
                    Detailed breakdown of expected and received items
                </p>
            </div>

            <?php if (!empty($asn_lines)): ?>
                <div class="table-container">
                    <table class="lines-table">
                        <thead>
                            <tr>
                                <th>Line</th>
                                <th>SKU ID</th>
                                <th>Description</th>
                                <th>Expected Qty</th>
                                <th>Received Qty</th>
                                <th>Variance</th>
                                <th>Batch ID</th>
                                <th>Condition</th>
                                <th>Expiry Date</th>
                                <th>Pack Config</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($asn_lines as $line): ?>
                                <?php
                                $expected = (int)($line['qty_expected'] ?? 0);
                                $received = (int)($line['qty_received'] ?? 0);
                                $variance = $received - $expected;
                                $variance_class = $variance > 0 ? 'variance-positive' : ($variance < 0 ? 'variance-negative' : 'variance-zero');
                                $variance_symbol = $variance > 0 ? '+' : '';
                                ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($line['line_id'] ?? 'N/A') ?></strong></td>
                                    <td>
                                        <strong><?= htmlspecialchars($line['sku_id'] ?? 'N/A') ?></strong>
                                    </td>
                                    <td>
                                        <div><?= htmlspecialchars($line['description'] ?? $line['sku_description'] ?? 'No description') ?></div>
                                    </td>
                                    <td>
                                        <div class="qty-comparison">
                                            <span class="qty-expected"><?= number_format($expected) ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="qty-comparison">
                                            <span class="qty-received"><?= number_format($received) ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="qty-variance <?= $variance_class ?>">
                                            <?= $variance_symbol . number_format($variance) ?>
                                        </span>
                                    </td>
                                    <td><?= htmlspecialchars($line['batch_id'] ?? '-') ?></td>
                                    <td><?= htmlspecialchars($line['condition'] ?? 'Good') ?></td>
                                    <td>
                                        <?= $line['expiry_date'] ? date('M d, Y', strtotime($line['expiry_date'])) : '-' ?>
                                    </td>
                                    <td><?= htmlspecialchars($line['pack_config'] ?? '-') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <h3>No Line Items Found</h3>
                    <p>This ASN does not have any line items yet.</p>
                    <a href="edit_asn_secure.php?asn_number=<?= urlencode($asn_number) ?>" class="btn btn-primary">
                        ➕ Add Line Items
                    </a>
                </div>
            <?php endif; ?>
        </div>

        <?php endif; ?>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="modal" style="display: none;">
        <div class="modal-content" style="max-width: 400px;">
            <div class="modal-header">
                <h3>Confirm Deletion</h3>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete ASN <strong id="deleteAsnNumber"></strong>?</p>
                <p style="color: #dc2626;">This action will delete all line items and cannot be undone.</p>
            </div>
            <div class="modal-footer">
                <form method="POST" action="delete_asn_secure.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="asn_number" id="deleteAsnInput">
                    <button type="submit" class="btn btn-danger">🗑️ Delete ASN</button>
                    <button type="button" class="btn btn-secondary" onclick="closeDeleteModal()">Cancel</button>
                </form>
            </div>
        </div>
    </div>

    <script>
        function confirmDelete(asnNumber) {
            document.getElementById('deleteAsnNumber').textContent = asnNumber;
            document.getElementById('deleteAsnInput').value = asnNumber;
            document.getElementById('deleteModal').style.display = 'flex';
        }

        function closeDeleteModal() {
            document.getElementById('deleteModal').style.display = 'none';
        }

        // Close modal when clicking outside
        document.getElementById('deleteModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeDeleteModal();
            }
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeDeleteModal();
            }
        });

        // Print functionality
        function printPage() {
            window.print();
        }

        // Auto-refresh data every 30 seconds if page is visible
        setInterval(function() {
            if (document.visibilityState === 'visible') {
                // Could implement live updates here
            }
        }, 30000);
    </script>

    <style>
        .modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }

        .modal-content {
            background: white;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            max-width: 90vw;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header {
            padding: 1.5rem 1.5rem 1rem;
            border-bottom: 1px solid #e2e8f0;
        }

        .modal-header h3 {
            margin: 0;
            color: #374151;
        }

        .modal-body {
            padding: 1.5rem;
        }

        .modal-footer {
            padding: 1rem 1.5rem 1.5rem;
            border-top: 1px solid #e2e8f0;
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
        }

        @media print {
            .action-bar, .btn {
                display: none !important;
            }

            .asn-header {
                background: #f8fafc !important;
                color: #374151 !important;
            }
        }
    </style>
</body>
</html>

<?php
// Clean up and close connections
if (isset($conn)) {
    $conn->close();
}

// Clean sensitive variables
unset($asn_lines, $asn_details, $errors);
?>
