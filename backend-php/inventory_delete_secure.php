<?php
/**
 * Secure Inventory Deletion System
 * Enhanced with comprehensive security measures
 *
 * Security Features:
 * - CSRF Protection
 * - SQL Injection Prevention
 * - Input Validation & Sanitization
 * - XSS Prevention
 * - Activity Logging
 * - Soft Delete Implementation
 * - Allocation Check
 * - Movement Tracking
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
$inventory_item = null;

/**
 * Validate inventory deletion request
 */
function validateDeletionRequest($data, &$errors) {
    if (empty($data['inventory_id'])) {
        $errors['inventory_id'] = "Inventory ID is required";
        return false;
    }

    if (!is_numeric($data['inventory_id']) || (int)$data['inventory_id'] <= 0) {
        $errors['inventory_id'] = "Invalid inventory ID";
        return false;
    }

    return empty($errors);
}

/**
 * Check inventory deletion eligibility
 */
function checkDeletionEligibility($conn, $inventory_id, $user_id) {
    // Get inventory item details
    $item = secure_select_one($conn,
        "SELECT i.*, sm.description as sku_description
         FROM inventory i
         LEFT JOIN sku_master sm ON i.sku_id = sm.sku_id
         WHERE i.id = ? AND i.deleted_at IS NULL",
        "i",
        [$inventory_id]
    );

    if (!$item) {
        throw new Exception('Inventory item not found or already deleted');
    }

    // Check if inventory has allocations
    $qty_allocated = (int)($item['qty_allocated'] ?? 0);
    if ($qty_allocated > 0) {
        throw new Exception("Cannot delete inventory with active allocations ($qty_allocated units allocated)");
    }

    // Check for related outbound orders
    $related_orders = secure_select_one($conn,
        "SELECT COUNT(*) as count FROM order_items oi
         JOIN orders o ON oi.order_id = o.id
         WHERE oi.sku = ? AND o.status IN ('pending', 'processing', 'picking')
         AND o.deleted_at IS NULL",
        "s",
        [$item['sku_id']]
    );

    if ($related_orders && $related_orders['count'] > 0) {
        throw new Exception("Cannot delete inventory with pending orders for this SKU ({$related_orders['count']} orders)");
    }

    return $item;
}

/**
 * Check user permissions for inventory deletion
 */
function checkUserPermissions($user_role, $item) {
    // Only admin and managers can delete inventory
    if (!in_array($user_role, ['admin', 'manager'])) {
        throw new Exception('Insufficient privileges: Only administrators and managers can delete inventory');
    }

    // Additional checks for high-value items could be added here
    // For example, require admin for items over a certain value

    return true;
}

/**
 * Create inventory movement record for deletion
 */
function createDeletionMovement($conn, $item, $user_id, $reason = '') {
    $movement_data = [
        'sku_id' => $item['sku_id'],
        'movement_type' => 'DELETION',
        'quantity' => -$item['qty_on_hand'], // Negative quantity for deletion
        'location_from' => $item['location_id'] ?? 'UNKNOWN',
        'location_to' => 'DELETED',
        'batch_id' => $item['batch_id'] ?? '',
        'reference_number' => 'DEL-' . $item['id'],
        'reason' => $reason ?: 'Inventory deletion',
        'user_id' => $user_id,
        'created_at' => date('Y-m-d H:i:s')
    ];

    return secure_insert($conn, 'inventory_movements', $movement_data);
}

/**
 * Soft delete inventory item
 */
function softDeleteInventory($conn, $inventory_id, $user_id, $reason = '') {
    try {
        // Begin transaction
        $conn->autocommit(false);

        $deletion_timestamp = date('Y-m-d H:i:s');

        // Get item details before deletion
        $item = secure_select_one($conn,
            "SELECT * FROM inventory WHERE id = ? AND deleted_at IS NULL",
            "i",
            [$inventory_id]
        );

        if (!$item) {
            throw new Exception('Inventory item not found');
        }

        // Create movement record
        $movement_id = createDeletionMovement($conn, $item, $user_id, $reason);

        if (!$movement_id) {
            throw new Exception('Failed to create deletion movement record');
        }

        // Soft delete inventory item
        $updated = secure_update($conn, 'inventory',
            [
                'deleted_at' => $deletion_timestamp,
                'deleted_by' => $user_id,
                'deletion_reason' => $reason ?: 'Manual deletion',
                'final_qty' => $item['qty_on_hand'],
                'last_updated' => $deletion_timestamp
            ],
            'id = ? AND deleted_at IS NULL',
            'i',
            [$inventory_id]
        );

        if (!$updated) {
            throw new Exception('Failed to delete inventory item');
        }

        // Commit transaction
        $conn->commit();

        return [
            'success' => true,
            'item' => $item,
            'movement_id' => $movement_id
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
 * Log inventory deletion activity
 */
function logDeletionActivity($conn, $action, $inventory_id, $user_id, $details = '') {
    $security = SecurityUtils::getInstance($conn);
    $security->logActivity($user_id, $action,
        "Inventory ID: $inventory_id" . ($details ? ", $details" : ''));
}

// Handle inventory deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Verify CSRF token
        if (!$security->validateCSRFToken($_POST['csrf_token'] ?? '')) {
            throw new Exception("Invalid security token. Please refresh the page and try again.");
        }

        // Rate limiting
        if (!$security->checkRateLimit($_SESSION['user_id'], 'inventory_delete', 5, 300)) {
            throw new Exception("Too many deletion requests. Please wait before deleting another item.");
        }

        // Sanitize input
        $input_data = [
            'inventory_id' => (int)($_POST['inventory_id'] ?? 0),
            'deletion_reason' => $security->sanitizeInput($_POST['deletion_reason'] ?? '', 500),
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
        $inventory_item = checkDeletionEligibility($conn, $input_data['inventory_id'], $_SESSION['user_id']);

        // Check user permissions
        checkUserPermissions($_SESSION['role'], $inventory_item);

        // Perform soft deletion
        $deletion_result = softDeleteInventory(
            $conn,
            $input_data['inventory_id'],
            $_SESSION['user_id'],
            $input_data['deletion_reason']
        );

        // Log successful deletion
        $details = "SKU: {$inventory_item['sku_id']}, Qty: {$inventory_item['qty_on_hand']}, Location: " . ($inventory_item['location_id'] ?? 'N/A');
        if ($input_data['deletion_reason']) {
            $details .= ", Reason: {$input_data['deletion_reason']}";
        }

        logDeletionActivity($conn, 'INVENTORY_DELETED', $input_data['inventory_id'], $_SESSION['user_id'], $details);

        // Log security event for audit trail
        $security->logSecurityEvent($_SESSION['user_id'], 'inventory_deletion',
            "Inventory deleted: ID {$input_data['inventory_id']}, SKU: {$inventory_item['sku_id']}, Qty: {$inventory_item['qty_on_hand']}");

        $message = "✅ Inventory item deleted successfully!<br>
                   <strong>SKU:</strong> " . htmlspecialchars($inventory_item['sku_id']) . "<br>
                   <strong>Quantity:</strong> " . number_format($inventory_item['qty_on_hand']) . "<br>
                   <strong>Location:</strong> " . htmlspecialchars($inventory_item['location_id'] ?? 'N/A');

        // Redirect after successful deletion
        header("Location: secure-inventory.php?message=" . urlencode("Inventory item deleted successfully"));
        exit;

    } catch (Exception $e) {
        $message = "❌ Error: " . htmlspecialchars($e->getMessage());

        // Log security events
        if (strpos($e->getMessage(), 'security token') !== false ||
            strpos($e->getMessage(), 'Too many requests') !== false ||
            strpos($e->getMessage(), 'Insufficient privileges') !== false) {

            $security->logSecurityEvent($_SESSION['user_id'], 'inventory_deletion_security_violation', $e->getMessage());
        }

        error_log("Inventory Deletion Error: " . $e->getMessage() . " | User: " . $_SESSION['user_id'] . " | IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    }
}

// Get inventory item for confirmation (GET request)
$inventory_id = (int)($_GET['id'] ?? 0);

if ($inventory_id <= 0) {
    header('Location: secure-inventory.php?error=' . urlencode('Invalid inventory ID'));
    exit;
}

try {
    // Get inventory item details
    $inventory_item = checkDeletionEligibility($conn, $inventory_id, $_SESSION['user_id']);

    // Check user permissions
    checkUserPermissions($_SESSION['role'], $inventory_item);

} catch (Exception $e) {
    header('Location: secure-inventory.php?error=' . urlencode($e->getMessage()));
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Delete Inventory - Secure WMS">
    <title>Delete Inventory | Secure WMS</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="modern-style.css">
    <style>
        .deletion-container {
            max-width: 700px;
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

        .item-details {
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
            text-align: right;
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

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: #374151;
        }

        .form-control {
            width: 100%;
            padding: 12px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.3s;
        }

        .form-control:focus {
            outline: none;
            border-color: #4f46e5;
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
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
            flex-wrap: wrap;
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

        .help-text {
            font-size: 0.875rem;
            color: #6b7280;
            margin-top: 0.25rem;
        }

        .high-value-warning {
            background: #fef3c7;
            border: 2px solid #f59e0b;
            border-radius: 8px;
            padding: 1rem;
            margin: 1rem 0;
            text-align: center;
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

            .detail-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="deletion-container">
        <!-- Warning Header -->
        <div class="warning-header">
            <h1 style="margin: 0; font-size: 2rem;">⚠️ Delete Inventory Item</h1>
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
            <h2 style="color: #dc2626; margin-top: 0;">Confirm Inventory Deletion</h2>

            <!-- Item Details -->
            <div class="item-details">
                <h3 style="margin-top: 0; color: #374151;">Inventory Item Information</h3>
                <div class="detail-grid">
                    <div class="detail-item">
                        <span class="detail-label">SKU:</span>
                        <span class="detail-value"><?= htmlspecialchars($inventory_item['sku_id']) ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Description:</span>
                        <span class="detail-value"><?= htmlspecialchars($inventory_item['sku_description'] ?? 'N/A') ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Quantity on Hand:</span>
                        <span class="detail-value"><?= number_format($inventory_item['qty_on_hand']) ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Allocated:</span>
                        <span class="detail-value"><?= number_format($inventory_item['qty_allocated'] ?? 0) ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Available:</span>
                        <span class="detail-value"><?= number_format(($inventory_item['qty_on_hand'] ?? 0) - ($inventory_item['qty_allocated'] ?? 0)) ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Location:</span>
                        <span class="detail-value"><?= htmlspecialchars($inventory_item['location_id'] ?? 'N/A') ?></span>
                    </div>
                    <?php if ($inventory_item['batch_id']): ?>
                    <div class="detail-item">
                        <span class="detail-label">Batch ID:</span>
                        <span class="detail-value"><?= htmlspecialchars($inventory_item['batch_id']) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($inventory_item['expiry_date']): ?>
                    <div class="detail-item">
                        <span class="detail-label">Expiry Date:</span>
                        <span class="detail-value"><?= date('M d, Y', strtotime($inventory_item['expiry_date'])) ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="detail-item">
                        <span class="detail-label">Last Updated:</span>
                        <span class="detail-value"><?= date('M d, Y H:i', strtotime($inventory_item['last_updated'])) ?></span>
                    </div>
                </div>
            </div>

            <!-- Warnings -->
            <div class="warning-box">
                <div style="display: flex; align-items: flex-start;">
                    <span class="warning-icon">⚠️</span>
                    <div>
                        <strong>Warning:</strong> Deleting this inventory item will:
                        <ul style="margin: 0.5rem 0 0 1rem;">
                            <li>Permanently remove <?= number_format($inventory_item['qty_on_hand']) ?> units from inventory</li>
                            <li>Create a deletion movement record for audit purposes</li>
                            <li>Mark the item as deleted in the system</li>
                            <li>This action cannot be reversed</li>
                        </ul>
                    </div>
                </div>
            </div>

            <?php if (($inventory_item['qty_on_hand'] ?? 0) > 1000): ?>
            <div class="high-value-warning">
                <strong>⚠️ High Quantity Alert</strong><br>
                This item has a high quantity (<?= number_format($inventory_item['qty_on_hand']) ?> units).
                Please verify this deletion is intentional.
            </div>
            <?php endif; ?>

            <!-- Deletion Form -->
            <form method="POST" id="deletionForm">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="inventory_id" value="<?= $inventory_item['id'] ?>">

                <div class="form-group">
                    <label for="deletion_reason" class="form-label">Deletion Reason (Optional)</label>
                    <textarea id="deletion_reason"
                              name="deletion_reason"
                              class="form-control"
                              rows="3"
                              maxlength="500"
                              placeholder="Enter reason for deletion (optional)..."></textarea>
                    <div class="help-text">Provide a reason for this deletion for audit purposes</div>
                </div>

                <div class="checkbox-group">
                    <label class="checkbox-label">
                        <input type="checkbox" name="confirm_deletion" value="yes" id="confirmCheckbox" required>
                        I understand this action cannot be undone and I want to delete this inventory item
                    </label>
                </div>

                <div class="action-buttons">
                    <button type="submit" class="btn btn-danger" id="deleteBtn" disabled>
                        🗑️ Delete Inventory Item
                    </button>
                    <a href="edit_sku_secure.php?sku=<?= urlencode($inventory_item['sku_id']) ?>" class="btn btn-secondary">
                        ✏️ Edit Instead
                    </a>
                    <a href="secure-inventory.php" class="btn btn-secondary">
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
            const inventoryId = <?= $inventory_item['id'] ?>;
            const sku = '<?= htmlspecialchars($inventory_item['sku_id']) ?>';
            const quantity = <?= $inventory_item['qty_on_hand'] ?? 0 ?>;
            const reason = document.getElementById('deletion_reason').value.trim();

            let confirmMessage = 'Are you absolutely sure you want to delete this inventory item?\n\n' +
                'SKU: ' + sku + '\n' +
                'Quantity: ' + quantity.toLocaleString() + ' units\n';

            if (reason) {
                confirmMessage += 'Reason: ' + reason + '\n';
            }

            confirmMessage += '\nThis action CANNOT be undone!';

            const confirmed = confirm(confirmMessage);

            if (!confirmed) {
                e.preventDefault();
                return false;
            }

            // Disable button and show processing state
            const deleteBtn = document.getElementById('deleteBtn');
            deleteBtn.disabled = true;
            deleteBtn.textContent = '🔄 Deleting...';

            // Re-enable after timeout as fallback
            setTimeout(() => {
                deleteBtn.disabled = false;
                deleteBtn.textContent = '🗑️ Delete Inventory Item';
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

        // Auto-focus on reason field when checkbox is checked
        document.getElementById('confirmCheckbox').addEventListener('change', function() {
            if (this.checked) {
                document.getElementById('deletion_reason').focus();
            }
        });

        // Character counter for deletion reason
        document.getElementById('deletion_reason').addEventListener('input', function() {
            const maxLength = 500;
            const currentLength = this.value.length;
            const remaining = maxLength - currentLength;

            // Create or update character counter
            let counter = document.getElementById('charCounter');
            if (!counter) {
                counter = document.createElement('div');
                counter.id = 'charCounter';
                counter.className = 'help-text';
                this.parentNode.appendChild(counter);
            }

            counter.textContent = remaining + ' characters remaining';
            counter.style.color = remaining < 50 ? '#dc2626' : '#6b7280';
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
unset($inventory_item, $errors, $input_data);
?>
