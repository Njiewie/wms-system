<?php
require_once 'security-utils.php';
require 'auth.php';
require_login();
include 'db_config.php';

// Set security headers
setSecurityHeaders();

// Validate CSRF token for POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        validate_csrf();
    } catch (Exception $e) {
        handleSecurityError('Invalid security token');
    }
}

// Get order ID and validate
if (!isset($_GET['id']) && !isset($_POST['order_id'])) {
    echo "❌ No order selected. <a href='view_outbound.php'>Back</a>";
    exit;
}

try {
    $order_id = isset($_GET['id']) ?
        WMSSecurity::validateInteger($_GET['id'], 1) :
        WMSSecurity::validateInteger($_POST['order_id'], 1);
} catch (Exception $e) {
    handleSecurityError('Invalid order ID parameter');
}

// Rate limiting for deletion operations
try {
    WMSSecurity::checkRateLimit('order_delete_' . $_SESSION['user'], 10, 300);
} catch (Exception $e) {
    handleSecurityError('Too many deletion requests. Please try again later.');
}

// Fetch order details for validation and logging
try {
    $order = secure_select_one($conn,
        "SELECT id, order_number, sku, qty_ordered, status, client_id FROM outbound_orders WHERE id = ?",
        "i",
        [$order_id]
    );

    if (!$order) {
        echo "❌ Order not found. <a href='view_outbound.php'>Back</a>";
        exit;
    }

} catch (Exception $e) {
    error_log("Order fetch error: " . $e->getMessage());
    echo "❌ Error loading order data. <a href='view_outbound.php'>Back</a>";
    exit;
}

// Handle deletion confirmation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_delete'])) {
    try {
        // Check if order can be safely deleted
        if ($order['status'] === 'ALLOCATED' || $order['status'] === 'PICKED') {
            // Need to release allocated inventory first
            $inventory = secure_select_one($conn,
                "SELECT id, qty_allocated FROM inventory WHERE sku_id = ?",
                "s",
                [$order['sku']]
            );

            if ($inventory && $inventory['qty_allocated'] >= $order['qty_ordered']) {
                // Start transaction for data integrity
                $conn->autocommit(false);

                try {
                    // Release allocated inventory
                    $new_allocated = $inventory['qty_allocated'] - $order['qty_ordered'];
                    secure_update($conn, 'inventory',
                        ['qty_allocated' => $new_allocated],
                        'id = ?',
                        'i',
                        [$inventory['id']]
                    );

                    // Delete the order
                    $deleted_rows = secure_delete($conn, 'outbound_orders', 'id = ?', 'i', [$order_id]);

                    if ($deleted_rows > 0) {
                        // Log the deletion with details
                        WMSSecurity::logActivity($conn, $_SESSION['user'], 'outbound_order_deleted',
                            "Order ID: {$order['id']}, Number: {$order['order_number']}, " .
                            "SKU: {$order['sku']}, Qty: {$order['qty_ordered']}, Status: {$order['status']}");

                        $conn->commit();
                        $success_message = "✅ Order #{$order['order_number']} deleted successfully and inventory allocation released.";
                    } else {
                        throw new Exception("Failed to delete order from database");
                    }

                } catch (Exception $e) {
                    $conn->rollback();
                    throw $e;
                }

                $conn->autocommit(true);

            } else {
                throw new Exception("Cannot release inventory allocation - insufficient allocated quantity");
            }

        } else {
            // Order not allocated, safe to delete directly
            $deleted_rows = secure_delete($conn, 'outbound_orders', 'id = ?', 'i', [$order_id]);

            if ($deleted_rows > 0) {
                // Log the deletion
                WMSSecurity::logActivity($conn, $_SESSION['user'], 'outbound_order_deleted',
                    "Order ID: {$order['id']}, Number: {$order['order_number']}, " .
                    "SKU: {$order['sku']}, Qty: {$order['qty_ordered']}, Status: {$order['status']}");

                $success_message = "✅ Order #{$order['order_number']} deleted successfully.";
            } else {
                throw new Exception("Failed to delete order from database");
            }
        }

    } catch (Exception $e) {
        error_log("Order deletion error: " . $e->getMessage());
        $error_message = "❌ Deletion failed: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Delete Order | ECWMS</title>
    <link rel="stylesheet" href="modern-style.css">
    <style>
        .delete-container {
            max-width: 600px;
            margin: 3rem auto;
        }
        .order-details {
            background: white;
            border-radius: var(--border-radius-lg);
            box-shadow: var(--box-shadow);
            padding: 2rem;
            margin-bottom: 1.5rem;
        }
        .detail-row {
            display: flex;
            justify-content: space-between;
            padding: 0.75rem 0;
            border-bottom: 1px solid var(--gray-200);
        }
        .detail-row:last-child {
            border-bottom: none;
        }
        .detail-label {
            font-weight: 600;
            color: var(--gray-700);
        }
        .detail-value {
            color: var(--gray-900);
        }
        .warning-panel {
            background: rgba(245, 158, 11, 0.1);
            border: 1px solid rgba(245, 158, 11, 0.3);
            border-radius: var(--border-radius);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        .warning-panel h4 {
            color: #92400e;
            margin-top: 0;
        }
        .confirmation-form {
            background: white;
            border-radius: var(--border-radius-lg);
            box-shadow: var(--box-shadow);
            padding: 2rem;
            text-align: center;
        }
        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 500;
            text-transform: uppercase;
        }
        .status-hold { background: rgba(239, 68, 68, 0.1); color: #991b1b; }
        .status-released { background: rgba(245, 158, 11, 0.1); color: #92400e; }
        .status-allocated { background: rgba(59, 130, 246, 0.1); color: #1e40af; }
        .status-picked { background: rgba(34, 197, 94, 0.1); color: #166534; }
        .status-shipped { background: rgba(107, 114, 128, 0.1); color: #374151; }
    </style>
</head>
<body class="wms-layout">

<main class="wms-content">
    <div class="delete-container">
        <!-- Success Message -->
        <?php if (isset($success_message)): ?>
        <div class="alert alert-success text-center">
            <?= secure_escape($success_message) ?>
            <div class="mt-3">
                <a href="view_outbound.php" class="btn btn-primary">⬅️ Return to Orders</a>
                <a href="secure-dashboard.php" class="btn btn-secondary">🏠 Dashboard</a>
            </div>
        </div>
        <?php exit; endif; ?>

        <!-- Error Message -->
        <?php if (isset($error_message)): ?>
        <div class="alert alert-danger">
            <?= secure_escape($error_message) ?>
        </div>
        <?php endif; ?>

        <!-- Page Header -->
        <div class="text-center mb-4">
            <h1>🗑️ Delete Outbound Order</h1>
            <p class="text-secondary">Review order details before deletion</p>
        </div>

        <!-- Order Details -->
        <div class="order-details">
            <h3 class="mb-3">Order Information</h3>

            <div class="detail-row">
                <span class="detail-label">Order Number:</span>
                <span class="detail-value"><strong><?= secure_escape($order['order_number']) ?></strong></span>
            </div>

            <div class="detail-row">
                <span class="detail-label">SKU:</span>
                <span class="detail-value"><?= secure_escape($order['sku']) ?></span>
            </div>

            <div class="detail-row">
                <span class="detail-label">Quantity Ordered:</span>
                <span class="detail-value"><?= number_format($order['qty_ordered']) ?></span>
            </div>

            <div class="detail-row">
                <span class="detail-label">Status:</span>
                <span class="detail-value">
                    <span class="status-badge status-<?= strtolower($order['status']) ?>">
                        <?= secure_escape($order['status']) ?>
                    </span>
                </span>
            </div>

            <div class="detail-row">
                <span class="detail-label">Client ID:</span>
                <span class="detail-value"><?= secure_escape($order['client_id']) ?></span>
            </div>
        </div>

        <!-- Warning Panel -->
        <?php if ($order['status'] === 'ALLOCATED' || $order['status'] === 'PICKED'): ?>
        <div class="warning-panel">
            <h4>⚠️ Important Notice</h4>
            <p>This order has status <strong><?= secure_escape($order['status']) ?></strong> and has allocated inventory.</p>
            <p>Deleting this order will automatically release the allocated inventory back to available stock.</p>
        </div>
        <?php endif; ?>

        <?php if ($order['status'] === 'SHIPPED'): ?>
        <div class="warning-panel">
            <h4>⚠️ Warning</h4>
            <p>This order has already been <strong>SHIPPED</strong>. Deleting shipped orders is not recommended as it may affect inventory accuracy and reporting.</p>
        </div>
        <?php endif; ?>

        <!-- Confirmation Form -->
        <div class="confirmation-form">
            <h3 class="text-danger mb-3">Confirm Deletion</h3>
            <p class="mb-4">Are you sure you want to delete order <strong><?= secure_escape($order['order_number']) ?></strong>?</p>
            <p class="text-secondary mb-4"><small>This action cannot be undone.</small></p>

            <form method="POST" onsubmit="return confirmDeletion();">
                <?= csrf_field() ?>
                <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
                <input type="hidden" name="confirm_delete" value="1">

                <div class="d-flex gap-3 justify-content-center">
                    <button type="submit" class="btn btn-danger">
                        🗑️ Yes, Delete Order
                    </button>
                    <a href="view_outbound.php" class="btn btn-secondary">
                        ↩️ Cancel
                    </a>
                </div>
            </form>
        </div>

        <!-- Navigation -->
        <div class="text-center mt-4">
            <a href="view_outbound.php" class="btn btn-secondary">⬅️ Back to Orders</a>
        </div>
    </div>
</main>

<script>
function confirmDeletion() {
    const orderNumber = '<?= secure_escape($order['order_number']) ?>';
    const orderStatus = '<?= secure_escape($order['status']) ?>';

    let confirmMessage = `Are you absolutely sure you want to delete order ${orderNumber}?`;

    if (orderStatus === 'ALLOCATED' || orderStatus === 'PICKED') {
        confirmMessage += '\n\nThis will also release the allocated inventory.';
    }

    if (orderStatus === 'SHIPPED') {
        confirmMessage += '\n\nWARNING: This order has been shipped!';
    }

    confirmMessage += '\n\nThis action cannot be undone.';

    const confirmed = confirm(confirmMessage);

    if (confirmed) {
        // Add loading state
        const submitBtn = event.target.querySelector('button[type="submit"]');
        submitBtn.disabled = true;
        submitBtn.innerHTML = '⏳ Deleting...';
    }

    return confirmed;
}

// Auto-redirect after successful deletion
<?php if (isset($success_message)): ?>
setTimeout(function() {
    window.location.href = 'view_outbound.php';
}, 3000);
<?php endif; ?>
</script>

</body>
</html>

<?php $conn->close(); ?>
