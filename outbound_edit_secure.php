<?php
require_once 'security-utils.php';
require 'auth.php';
require_login();
include 'db_config.php';

// Set security headers
setSecurityHeaders();

if (!isset($_GET['id'])) {
    echo "❌ No order selected. <a href='view_outbound.php'>Back</a>";
    exit;
}

try {
    $order_id = WMSSecurity::validateInteger($_GET['id'], 1);
} catch (Exception $e) {
    handleSecurityError('Invalid order ID parameter');
}

// Fetch order data securely
try {
    $order = secure_select_one($conn,
        "SELECT * FROM outbound_orders WHERE id = ?",
        "i",
        [$order_id]
    );

    if (!$order) {
        echo "❌ Order not found. <a href='view_outbound.php'>Back</a>";
        exit;
    }

    // Fetch clients for dropdown
    $clients = secure_select_all($conn,
        "SELECT id, client_name FROM clients ORDER BY client_name ASC"
    );

} catch (Exception $e) {
    error_log("Order fetch error: " . $e->getMessage());
    echo "❌ Error loading order data. <a href='view_outbound.php'>Back</a>";
    exit;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Validate CSRF token
        validate_csrf();

        // Rate limiting for order edits
        WMSSecurity::checkRateLimit('order_edit_' . $_SESSION['user'], 20, 300);

        // Validate and sanitize all inputs
        $order_number = WMSSecurity::sanitizeString($_POST['order_number'], 100);
        $sku = WMSSecurity::sanitizeString($_POST['sku'], 50);
        $qty_ordered = WMSSecurity::validateInteger($_POST['qty_ordered'], 1, 999999);
        $client_id = WMSSecurity::validateInteger($_POST['client_id'], 1);
        $delivery_address = WMSSecurity::sanitizeString($_POST['delivery_address'], 500);
        $carrier = WMSSecurity::sanitizeString($_POST['carrier'], 100);
        $status = $_POST['status'];

        // Validate status against allowed values
        $allowed_statuses = ['HOLD', 'RELEASED', 'ALLOCATED', 'PICKED', 'SHIPPED'];
        if (!in_array($status, $allowed_statuses)) {
            throw new InvalidArgumentException('Invalid status value');
        }

        // Additional business logic validation
        if (empty($order_number)) {
            throw new InvalidArgumentException('Order number is required');
        }

        if (empty($sku)) {
            throw new InvalidArgumentException('SKU is required');
        }

        if (empty($delivery_address)) {
            throw new InvalidArgumentException('Delivery address is required');
        }

        // Check if changing quantity on allocated/picked orders
        if (($order['status'] === 'ALLOCATED' || $order['status'] === 'PICKED') &&
            $qty_ordered != $order['qty_ordered']) {

            // Need to handle inventory allocation changes
            $inventory = secure_select_one($conn,
                "SELECT id, qty_on_hand, qty_allocated FROM inventory WHERE sku_id = ?",
                "s",
                [$sku]
            );

            if ($inventory) {
                $qty_difference = $qty_ordered - $order['qty_ordered'];
                $new_allocated = $inventory['qty_allocated'] + $qty_difference;
                $available_qty = $inventory['qty_on_hand'] - $inventory['qty_allocated'];

                if ($qty_difference > 0 && $available_qty < $qty_difference) {
                    throw new Exception("Not enough available inventory to increase order quantity");
                }

                // Update inventory allocation
                secure_update($conn, 'inventory',
                    ['qty_allocated' => $new_allocated],
                    'id = ?',
                    'i',
                    [$inventory['id']]
                );
            }
        }

        // Update order record
        $update_data = [
            'order_number' => $order_number,
            'sku' => $sku,
            'qty_ordered' => $qty_ordered,
            'client_id' => $client_id,
            'delivery_address' => $delivery_address,
            'carrier' => $carrier,
            'status' => $status,
            'updated_at' => date('Y-m-d H:i:s')
        ];

        $updated_rows = secure_update($conn, 'outbound_orders',
            $update_data,
            'id = ?',
            'i',
            [$order_id]
        );

        if ($updated_rows > 0) {
            // Log the update activity
            WMSSecurity::logActivity($conn, $_SESSION['user'], 'outbound_order_updated',
                "Order ID: $order_id, Number: $order_number, SKU: $sku, New Status: $status");

            $success_message = "✅ Order updated successfully.";

            // Refresh order data
            $order = secure_select_one($conn,
                "SELECT * FROM outbound_orders WHERE id = ?",
                "i",
                [$order_id]
            );
        } else {
            $error_message = "❌ No changes were made or order not found.";
        }

    } catch (Exception $e) {
        error_log("Order update error: " . $e->getMessage());
        $error_message = "❌ Update failed: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Edit Outbound Order | ECWMS</title>
    <link rel="stylesheet" href="modern-style.css">
    <style>
        .edit-container {
            max-width: 800px;
            margin: 2rem auto;
        }
        .edit-form {
            background: white;
            border-radius: var(--border-radius-lg);
            box-shadow: var(--box-shadow);
            padding: 2rem;
        }
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
        }
        .form-group.full-width {
            grid-column: span 2;
        }
        .status-info {
            background: var(--gray-50);
            border-radius: var(--border-radius);
            padding: 1rem;
            margin-bottom: 1.5rem;
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
        .warning-box {
            background: rgba(245, 158, 11, 0.1);
            border: 1px solid rgba(245, 158, 11, 0.3);
            border-radius: var(--border-radius);
            padding: 1rem;
            margin-bottom: 1rem;
        }
    </style>
</head>
<body class="wms-layout">

<main class="wms-content">
    <div class="edit-container">
        <!-- Header -->
        <div class="text-center mb-4">
            <h1>✏️ Edit Outbound Order</h1>
            <p class="text-secondary">Order ID: <?= $order['id'] ?></p>
        </div>

        <!-- Messages -->
        <?php if (isset($success_message)): ?>
        <div class="alert alert-success">
            <?= secure_escape($success_message) ?>
        </div>
        <?php endif; ?>

        <?php if (isset($error_message)): ?>
        <div class="alert alert-danger">
            <?= secure_escape($error_message) ?>
        </div>
        <?php endif; ?>

        <!-- Status Information -->
        <div class="status-info">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <strong>Current Status:</strong>
                    <span class="status-badge status-<?= strtolower($order['status']) ?>">
                        <?= secure_escape($order['status']) ?>
                    </span>
                </div>
                <div class="text-secondary">
                    <small>Created: <?= date('M d, Y H:i', strtotime($order['created_at'])) ?></small>
                </div>
            </div>
        </div>

        <!-- Warning for allocated/picked orders -->
        <?php if ($order['status'] === 'ALLOCATED' || $order['status'] === 'PICKED'): ?>
        <div class="warning-box">
            <strong>⚠️ Warning:</strong> This order has allocated inventory. Changing the quantity will automatically adjust inventory allocations.
        </div>
        <?php endif; ?>

        <!-- Edit Form -->
        <div class="edit-form">
            <form method="POST" id="editOrderForm">
                <?= csrf_field() ?>

                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Order Number *</label>
                        <input type="text" name="order_number" class="form-control"
                               value="<?= secure_escape($order['order_number']) ?>"
                               required maxlength="100">
                    </div>

                    <div class="form-group">
                        <label class="form-label">SKU *</label>
                        <input type="text" name="sku" class="form-control"
                               value="<?= secure_escape($order['sku']) ?>"
                               required maxlength="50">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Quantity Ordered *</label>
                        <input type="number" name="qty_ordered" class="form-control"
                               value="<?= secure_escape($order['qty_ordered']) ?>"
                               required min="1" max="999999">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Client *</label>
                        <select name="client_id" class="form-control form-select" required>
                            <option value="">-- Select Client --</option>
                            <?php foreach($clients as $client): ?>
                                <option value="<?= $client['id'] ?>"
                                        <?= ($order['client_id'] == $client['id']) ? 'selected' : '' ?>>
                                    <?= secure_escape($client['client_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group full-width">
                        <label class="form-label">Delivery Address *</label>
                        <textarea name="delivery_address" class="form-control" rows="3"
                                  required maxlength="500"><?= secure_escape($order['delivery_address']) ?></textarea>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Carrier</label>
                        <input type="text" name="carrier" class="form-control"
                               value="<?= secure_escape($order['carrier']) ?>"
                               maxlength="100">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Status *</label>
                        <select name="status" class="form-control form-select" required>
                            <option value="HOLD" <?= ($order['status'] === 'HOLD') ? 'selected' : '' ?>>HOLD</option>
                            <option value="RELEASED" <?= ($order['status'] === 'RELEASED') ? 'selected' : '' ?>>RELEASED</option>
                            <option value="ALLOCATED" <?= ($order['status'] === 'ALLOCATED') ? 'selected' : '' ?>>ALLOCATED</option>
                            <option value="PICKED" <?= ($order['status'] === 'PICKED') ? 'selected' : '' ?>>PICKED</option>
                            <option value="SHIPPED" <?= ($order['status'] === 'SHIPPED') ? 'selected' : '' ?>>SHIPPED</option>
                        </select>
                    </div>
                </div>

                <!-- Form Actions -->
                <div class="text-center mt-4">
                    <button type="submit" class="btn btn-primary">💾 Update Order</button>
                    <a href="view_outbound.php" class="btn btn-secondary">↩️ Cancel</a>
                    <a href="outbound_delete_secure.php?id=<?= $order['id'] ?>"
                       class="btn btn-danger"
                       onclick="return confirm('Are you sure you want to delete this order?')">
                        🗑️ Delete Order
                    </a>
                </div>
            </form>
        </div>

        <!-- Navigation -->
        <div class="text-center mt-4">
            <a href="view_outbound.php" class="btn btn-secondary">⬅️ Back to Orders</a>
            <a href="secure-dashboard.php" class="btn btn-secondary">🏠 Dashboard</a>
        </div>
    </div>
</main>

<script>
// Form validation
document.getElementById('editOrderForm').addEventListener('submit', function(e) {
    const orderNumber = this.querySelector('[name="order_number"]').value.trim();
    const sku = this.querySelector('[name="sku"]').value.trim();
    const qtyOrdered = this.querySelector('[name="qty_ordered"]').value;
    const clientId = this.querySelector('[name="client_id"]').value;
    const deliveryAddress = this.querySelector('[name="delivery_address"]').value.trim();

    let errors = [];

    if (!orderNumber) {
        errors.push('Order number is required');
    }

    if (!sku) {
        errors.push('SKU is required');
    }

    if (!qtyOrdered || qtyOrdered < 1) {
        errors.push('Quantity must be at least 1');
    }

    if (!clientId) {
        errors.push('Please select a client');
    }

    if (!deliveryAddress) {
        errors.push('Delivery address is required');
    }

    if (errors.length > 0) {
        alert('Please fix the following errors:\n\n' + errors.join('\n'));
        e.preventDefault();
        return;
    }

    // Check for quantity changes on allocated orders
    const currentStatus = '<?= secure_escape($order['status']) ?>';
    const currentQty = <?= $order['qty_ordered'] ?>;

    if ((currentStatus === 'ALLOCATED' || currentStatus === 'PICKED') &&
        parseInt(qtyOrdered) !== currentQty) {

        const confirmed = confirm(
            'You are changing the quantity on an allocated order. ' +
            'This will automatically adjust inventory allocations. ' +
            'Are you sure you want to continue?'
        );

        if (!confirmed) {
            e.preventDefault();
            return;
        }
    }

    // Add loading state
    const submitBtn = this.querySelector('button[type="submit"]');
    submitBtn.disabled = true;
    submitBtn.textContent = 'Updating...';
});

// SKU validation (check if exists)
document.querySelector('[name="sku"]').addEventListener('blur', function() {
    const sku = this.value.trim();
    if (sku) {
        fetch('fetch_sku_info.php?sku=' + encodeURIComponent(sku))
            .then(res => res.json())
            .then(data => {
                if (data.error) {
                    this.style.borderColor = '#dc2626';
                    this.title = 'SKU not found in master data';
                } else {
                    this.style.borderColor = '#16a34a';
                    this.title = 'SKU found: ' + data.description;
                }
            })
            .catch(() => {
                // Ignore fetch errors
            });
    }
});
</script>

</body>
</html>

<?php $conn->close(); ?>
