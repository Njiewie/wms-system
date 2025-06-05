<?php
/**
 * Secure ASN Deletion System
 * Enhanced with comprehensive security measures
 *
 * Security Features:
 * - CSRF Protection
 * - SQL Injection Prevention
 * - Input Validation & Sanitization
 * - XSS Prevention
 * - Activity Logging
 * - Soft Delete Implementation
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
$message = "";
$errors = [];

/**
 * Validate ASN deletion request
 */
function validateDeletionRequest($data, &$errors) {
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
 * Check ASN deletion eligibility
 */
function checkDeletionEligibility($conn, $asn_number, $user_id) {
    // Check if ASN exists and user has access
    $asn = secure_select_one($conn,
        "SELECT ah.asn_number, ah.status, ah.created_by, ah.processed_at, ah.deleted_at,
                COUNT(al.line_id) as line_count
         FROM asn_header ah
         LEFT JOIN asn_lines al ON ah.asn_number = al.asn_number
         WHERE ah.asn_number = ? AND ah.deleted_at IS NULL
         GROUP BY ah.asn_number, ah.status, ah.created_by, ah.processed_at, ah.deleted_at",
        "s",
        [$asn_number]
    );

    if (!$asn) {
        throw new Exception('ASN not found or access denied');
    }

    // Check if ASN is already processed
    if ($asn['status'] === 'Completed' && $asn['processed_at']) {
        throw new Exception('Cannot delete completed ASN that has been processed into inventory');
    }

    // Check if user is authorized to delete (admin or creator)
    if ($_SESSION['role'] !== 'admin' && $asn['created_by'] != $user_id) {
        throw new Exception('You are not authorized to delete this ASN');
    }

    return $asn;
}

/**
 * Check for related inventory records
 */
function checkRelatedInventory($conn, $asn_number) {
    $inventory_count = secure_select_one($conn,
        "SELECT COUNT(*) as count FROM inventory WHERE receipt_id = ? AND deleted_at IS NULL",
        "s",
        [$asn_number]
    );

    return (int)($inventory_count['count'] ?? 0);
}

/**
 * Soft delete ASN and related records
 */
function softDeleteASN($conn, $asn_number, $user_id) {
    try {
        // Begin transaction
        $conn->autocommit(false);

        $deletion_timestamp = date('Y-m-d H:i:s');

        // Soft delete ASN header
        $header_updated = secure_update($conn, 'asn_header',
            [
                'deleted_at' => $deletion_timestamp,
                'deleted_by' => $user_id,
                'status' => 'Deleted'
            ],
            'asn_number = ? AND deleted_at IS NULL',
            's',
            [$asn_number]
        );

        if (!$header_updated) {
            throw new Exception('Failed to delete ASN header');
        }

        // Soft delete ASN lines
        $lines_updated = secure_update($conn, 'asn_lines',
            [
                'deleted_at' => $deletion_timestamp,
                'deleted_by' => $user_id
            ],
            'asn_number = ? AND deleted_at IS NULL',
            's',
            [$asn_number]
        );

        // Check for related inventory and handle appropriately
        $inventory_count = checkRelatedInventory($conn, $asn_number);

        if ($inventory_count > 0) {
            // Mark related inventory as orphaned but don't delete
            secure_update($conn, 'inventory',
                [
                    'receipt_id' => 'DELETED_' . $asn_number,
                    'notes' => 'Original ASN deleted: ' . $asn_number,
                    'last_updated' => $deletion_timestamp
                ],
                'receipt_id = ? AND deleted_at IS NULL',
                's',
                [$asn_number]
            );

            // Log inventory orphaning
            $movement_data = [
                'sku_id' => 'SYSTEM',
                'movement_type' => 'ASN_DELETION',
                'quantity' => 0,
                'reference_number' => $asn_number,
                'location_from' => 'ASN_SYSTEM',
                'location_to' => 'ORPHANED',
                'notes' => "ASN deleted - inventory orphaned: $inventory_count records",
                'user_id' => $user_id,
                'created_at' => $deletion_timestamp
            ];

            secure_insert($conn, 'inventory_movements', $movement_data);
        }

        // Commit transaction
        $conn->commit();

        return [
            'success' => true,
            'lines_deleted' => $lines_updated,
            'inventory_orphaned' => $inventory_count
        ];

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
 * Log ASN deletion activity
 */
function logDeletionActivity($conn, $action, $asn_number, $user_id, $details = '') {
    $security = SecurityUtils::getInstance($conn);
    $security->logActivity($user_id, $action,
        "ASN: $asn_number" . ($details ? ", $details" : ''));
}

// Handle ASN deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Verify CSRF token
        if (!$security->validateCSRFToken($_POST['csrf_token'] ?? '')) {
            throw new Exception("Invalid security token. Please refresh the page and try again.");
        }

        // Rate limiting
        if (!$security->checkRateLimit($_SESSION['user_id'], 'asn_delete', 3, 300)) {
            throw new Exception("Too many deletion requests. Please wait before deleting another ASN.");
        }

        // Sanitize input
        $input_data = [
            'asn_number' => $security->sanitizeInput($_POST['asn_number'] ?? '', 50),
            'confirm_deletion' => isset($_POST['confirm_deletion']) ? 'yes' : 'no'
        ];

        // Validate input
        if (!validateDeletionRequest($input_data, $errors)) {
            throw new Exception("Please correct the validation errors below.");
        }

        // Require explicit confirmation
        if ($input_data['confirm_deletion'] !== 'yes') {
            throw new Exception("Deletion confirmation is required.");
        }

        // Check deletion eligibility
        $asn_details = checkDeletionEligibility($conn, $input_data['asn_number'], $_SESSION['user_id']);

        // Check for related inventory
        $inventory_count = checkRelatedInventory($conn, $input_data['asn_number']);

        // Perform soft deletion
        $deletion_result = softDeleteASN($conn, $input_data['asn_number'], $_SESSION['user_id']);

        // Log successful deletion
        $details = "Lines deleted: {$deletion_result['lines_deleted']}";
        if ($deletion_result['inventory_orphaned'] > 0) {
            $details .= ", Inventory orphaned: {$deletion_result['inventory_orphaned']}";
        }

        logDeletionActivity($conn, 'ASN_DELETED', $input_data['asn_number'], $_SESSION['user_id'], $details);

        // Log security event for audit trail
        $security->logSecurityEvent($_SESSION['user_id'], 'asn_deletion',
            "ASN deleted: {$input_data['asn_number']}, Lines: {$deletion_result['lines_deleted']}, Inventory: {$deletion_result['inventory_orphaned']}");

        $message = "✅ ASN deleted successfully!<br>
                   <strong>ASN Number:</strong> " . htmlspecialchars($input_data['asn_number']) . "<br>
                   <strong>Lines Deleted:</strong> {$deletion_result['lines_deleted']}<br>";

        if ($deletion_result['inventory_orphaned'] > 0) {
            $message .= "<strong>Inventory Records Orphaned:</strong> {$deletion_result['inventory_orphaned']}<br>
                        <em>Note: Related inventory records have been preserved but marked as orphaned.</em>";
        }

        // Redirect after successful deletion
        header("Location: inbound_secure.php?message=" . urlencode("ASN deleted successfully"));
        exit;

    } catch (Exception $e) {
        $message = "❌ Error: " . htmlspecialchars($e->getMessage());

        // Log security events
        if (strpos($e->getMessage(), 'security token') !== false ||
            strpos($e->getMessage(), 'Too many requests') !== false ||
            strpos($e->getMessage(), 'not authorized') !== false) {

            $security->logSecurityEvent($_SESSION['user_id'], 'asn_deletion_security_violation', $e->getMessage());
        }

        error_log("ASN Deletion Error: " . $e->getMessage() . " | User: " . $_SESSION['user_id'] . " | IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    }
}

// If we reach here with a GET request, show deletion confirmation form
$asn_number = $security->sanitizeInput($_GET['asn_number'] ?? '', 50);

if (empty($asn_number)) {
    header('Location: inbound_secure.php?error=' . urlencode('ASN number is required'));
    exit;
}

try {
    // Get ASN details for confirmation
    $asn_details = checkDeletionEligibility($conn, $asn_number, $_SESSION['user_id']);
    $inventory_count = checkRelatedInventory($conn, $asn_number);
} catch (Exception $e) {
    header('Location: inbound_secure.php?error=' . urlencode($e->getMessage()));
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Delete ASN - Secure WMS">
    <title>Delete ASN | Secure WMS</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="modern-style.css">
    <style>
        .deletion-container {
            max-width: 600px;
            margin: 0 auto;
            padding: 2rem;
        }

        .warning-header {
            text-align: center;
            margin-bottom: 2rem;
            padding: 2rem;
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            color: white;
            border-radius: 12px;
            box-shadow: 0 8px 32px rgba(239, 68, 68, 0.3);
        }

        .confirmation-form {
            background: white;
            padding: 2rem;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            border: 2px solid #fecaca;
        }

        .asn-details {
            background: #fef2f2;
            padding: 1.5rem;
            border-radius: 8px;
            margin-bottom: 2rem;
            border-left: 4px solid #ef4444;
        }

        .detail-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }

        .detail-item {
            display: flex;
            justify-content: space-between;
            padding: 0.5rem 0;
            border-bottom: 1px solid #e5e7eb;
        }

        .detail-label {
            font-weight: 600;
            color: #374151;
        }

        .detail-value {
            color: #6b7280;
        }

        .warning-box {
            background: #fffbeb;
            border: 1px solid #f59e0b;
            border-radius: 8px;
            padding: 1rem;
            margin: 1rem 0;
        }

        .warning-icon {
            color: #f59e0b;
            font-size: 1.5rem;
            margin-right: 0.5rem;
        }

        .checkbox-group {
            margin: 1.5rem 0;
            padding: 1rem;
            background: #f9fafb;
            border-radius: 8px;
            border: 1px solid #d1d5db;
        }

        .checkbox-label {
            display: flex;
            align-items: center;
            font-weight: 600;
            color: #374151;
        }

        .checkbox-label input[type="checkbox"] {
            margin-right: 0.5rem;
            transform: scale(1.2);
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

        .btn-danger {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            color: white;
        }

        .btn-danger:hover {
            transform: translateY(-1px);
            box-shadow: 0 8px 25px rgba(239, 68, 68, 0.4);
        }

        .btn-danger:disabled {
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

        .action-buttons {
            display: flex;
            gap: 1rem;
            justify-content: center;
            margin-top: 2rem;
        }

        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
        }

        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border-left: 4px solid #ef4444;
        }

        @media (max-width: 768px) {
            .deletion-container {
                padding: 1rem;
            }

            .warning-header {
                padding: 1rem;
            }

            .action-buttons {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="deletion-container">
        <!-- Warning Header -->
        <div class="warning-header">
            <h1 style="margin: 0; font-size: 2rem;">⚠️ Delete ASN</h1>
            <p style="margin: 0.5rem 0 0; font-size: 1.125rem; opacity: 0.9;">
                This action cannot be undone!
            </p>
        </div>

        <!-- Messages -->
        <?php if ($message): ?>
            <div class="alert alert-error">
                <?= $message ?>
            </div>
        <?php endif; ?>

        <!-- Confirmation Form -->
        <div class="confirmation-form">
            <h2 style="color: #dc2626; margin-top: 0;">Confirm ASN Deletion</h2>

            <!-- ASN Details -->
            <div class="asn-details">
                <h3 style="margin-top: 0; color: #374151;">ASN Information</h3>
                <div class="detail-grid">
                    <div class="detail-item">
                        <span class="detail-label">ASN Number:</span>
                        <span class="detail-value"><?= htmlspecialchars($asn_number) ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Status:</span>
                        <span class="detail-value"><?= htmlspecialchars($asn_details['status'] ?? 'Unknown') ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Line Items:</span>
                        <span class="detail-value"><?= (int)($asn_details['line_count'] ?? 0) ?></span>
                    </div>
                    <?php if ($inventory_count > 0): ?>
                    <div class="detail-item">
                        <span class="detail-label">Related Inventory:</span>
                        <span class="detail-value" style="color: #dc2626; font-weight: 600;">
                            <?= $inventory_count ?> records
                        </span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Warnings -->
            <div class="warning-box">
                <div style="display: flex; align-items: flex-start;">
                    <span class="warning-icon">⚠️</span>
                    <div>
                        <strong>Warning:</strong> Deleting this ASN will:
                        <ul style="margin: 0.5rem 0 0 1rem;">
                            <li>Permanently remove the ASN and all its line items</li>
                            <li>Mark the deletion in the audit log</li>
                            <?php if ($inventory_count > 0): ?>
                            <li><strong>Orphan <?= $inventory_count ?> inventory record(s)</strong> - they will be preserved but marked as orphaned</li>
                            <?php endif; ?>
                            <?php if ($asn_details['status'] === 'In Progress'): ?>
                            <li><strong>Cancel any ongoing processing</strong> of this ASN</li>
                            <?php endif; ?>
                        </ul>
                    </div>
                </div>
            </div>

            <?php if ($inventory_count > 0): ?>
            <div class="warning-box" style="border-color: #f59e0b; background: #fffbeb;">
                <div style="display: flex; align-items: flex-start;">
                    <span class="warning-icon">🔍</span>
                    <div>
                        <strong>Inventory Impact:</strong> This ASN has <?= $inventory_count ?> related inventory record(s).
                        These will be preserved but marked as orphaned. You may need to:
                        <ul style="margin: 0.5rem 0 0 1rem;">
                            <li>Review orphaned inventory records manually</li>
                            <li>Reassign inventory to correct receipts if needed</li>
                            <li>Update inventory tracking systems</li>
                        </ul>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Deletion Form -->
            <form method="POST" id="deletionForm">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="asn_number" value="<?= htmlspecialchars($asn_number) ?>">

                <div class="checkbox-group">
                    <label class="checkbox-label">
                        <input type="checkbox" name="confirm_deletion" value="yes" id="confirmCheckbox" required>
                        I understand this action cannot be undone and I want to delete this ASN
                    </label>
                </div>

                <div class="action-buttons">
                    <button type="submit" class="btn btn-danger" id="deleteBtn" disabled>
                        🗑️ Delete ASN Permanently
                    </button>
                    <a href="asn_lines_secure.php?asn_number=<?= urlencode($asn_number) ?>" class="btn btn-secondary">
                        👁️ View ASN Details
                    </a>
                    <a href="inbound_secure.php" class="btn btn-secondary">
                        ⬅️ Cancel & Return
                    </a>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Enable/disable delete button based on confirmation
        document.getElementById('confirmCheckbox').addEventListener('change', function() {
            document.getElementById('deleteBtn').disabled = !this.checked;
        });

        // Form submission confirmation
        document.getElementById('deletionForm').addEventListener('submit', function(e) {
            const confirmed = confirm(
                'Are you absolutely sure you want to delete this ASN?\n\n' +
                'ASN: <?= htmlspecialchars($asn_number) ?>\n' +
                'Line Items: <?= (int)($asn_details['line_count'] ?? 0) ?>\n' +
                <?php if ($inventory_count > 0): ?>
                'Inventory Impact: <?= $inventory_count ?> records will be orphaned\n' +
                <?php endif; ?>
                '\nThis action CANNOT be undone!'
            );

            if (!confirmed) {
                e.preventDefault();
                return false;
            }

            // Disable button and show processing state
            const deleteBtn = document.getElementById('deleteBtn');
            deleteBtn.disabled = true;
            deleteBtn.textContent = '🔄 Deleting ASN...';

            // Re-enable after timeout as fallback
            setTimeout(() => {
                deleteBtn.disabled = false;
                deleteBtn.textContent = '🗑️ Delete ASN Permanently';
            }, 10000);
        });

        // Warn about page navigation
        window.addEventListener('beforeunload', function(e) {
            const checkbox = document.getElementById('confirmCheckbox');
            if (checkbox && checkbox.checked) {
                e.preventDefault();
                e.returnValue = '';
            }
        });
    </script>
</body>
</html>

<?php
// Clean up and close connections
if (isset($conn)) {
    $conn->close();
}

// Clean sensitive variables
unset($asn_details, $errors, $input_data);
?>
