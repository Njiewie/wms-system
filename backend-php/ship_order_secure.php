<?php
require_once 'security-utils.php';
require 'auth.php';
require_login();
include 'db_config.php';

// Set security headers
setSecurityHeaders();

$message = '';
$message_type = 'info';

// Handle shipping action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ship_id'])) {
    try {
        // Validate CSRF token
        validate_csrf();

        $order_id = WMSSecurity::validateInteger($_POST['ship_id'], 1);

        // Rate limiting for shipping operations
        WMSSecurity::checkRateLimit('ship_order_' . $_SESSION['user'], 10, 300);

        // Start transaction for data integrity
        $conn->autocommit(false);

        // Fetch order details securely
        $order = secure_select_one($conn,
            "SELECT id, order_number, sku, qty_ordered, status, client_id, carrier FROM outbound_orders WHERE id = ?",
            "i",
            [$order_id]
        );

        if (!$order) {
            throw new Exception("Order not found");
        }

        // Validate order status
        if ($order['status'] !== 'PICKED') {
            throw new Exception("Order is not in PICKED status. Current status: {$order['status']}");
        }

        $sku = $order['sku'];
        $qty_ordered = $order['qty_ordered'];

        // Fetch inventory details securely
        $inventory = secure_select_one($conn,
            "SELECT id, qty_on_hand, qty_allocated FROM inventory WHERE sku_id = ?",
            "s",
            [$sku]
        );

        if (!$inventory) {
            throw new Exception("Inventory record not found for SKU: $sku");
        }

        // Validate sufficient allocated quantity
        if ($inventory['qty_allocated'] < $qty_ordered) {
            throw new Exception("Insufficient allocated quantity. Required: $qty_ordered, Allocated: {$inventory['qty_allocated']}");
        }

        // Calculate new inventory quantities
        $new_qty_on_hand = $inventory['qty_on_hand'] - $qty_ordered;
        $new_qty_allocated = $inventory['qty_allocated'] - $qty_ordered;

        // Validate quantities don't go negative
        if ($new_qty_on_hand < 0) {
            throw new Exception("Insufficient on-hand quantity. Available: {$inventory['qty_on_hand']}, Required: $qty_ordered");
        }

        if ($new_qty_allocated < 0) {
            throw new Exception("Insufficient allocated quantity. Allocated: {$inventory['qty_allocated']}, Required: $qty_ordered");
        }

        // Update inventory quantities
        $inventory_updated = secure_update($conn, 'inventory',
            [
                'qty_on_hand' => $new_qty_on_hand,
                'qty_allocated' => $new_qty_allocated,
                'last_updated' => date('Y-m-d H:i:s')
            ],
            'id = ?',
            'i',
            [$inventory['id']]
        );

        if ($inventory_updated === 0) {
            throw new Exception("Failed to update inventory quantities");
        }

        // Update order status to SHIPPED
        $order_updated = secure_update($conn, 'outbound_orders',
            [
                'status' => 'SHIPPED',
                'shipped_at' => date('Y-m-d H:i:s')
            ],
            'id = ?',
            'i',
            [$order_id]
        );

        if ($order_updated === 0) {
            throw new Exception("Failed to update order status");
        }

        // Log the shipping activity with detailed information
        WMSSecurity::logActivity($conn, $_SESSION['user'], 'order_shipped',
            "Order ID: {$order['id']}, Number: {$order['order_number']}, " .
            "SKU: $sku, Qty: $qty_ordered, Carrier: {$order['carrier']}, " .
            "New On-Hand: $new_qty_on_hand, New Allocated: $new_qty_allocated");

        // Commit transaction
        $conn->commit();
        $conn->autocommit(true);

        $message = "✅ Order #{$order['order_number']} shipped successfully. Inventory updated: -$qty_ordered from on-hand and allocated quantities.";
        $message_type = 'success';

    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollback();
        $conn->autocommit(true);

        error_log("Ship order error: " . $e->getMessage());
        $message = "❌ Shipping failed: " . $e->getMessage();
        $message_type = 'error';
    }
}

// Fetch picked orders securely
try {
    $orders = secure_select_all($conn,
        "SELECT o.id, o.order_number, o.sku, o.qty_ordered, o.carrier, o.status, o.created_at,
                o.delivery_address, c.client_name, i.qty_on_hand, i.qty_allocated
         FROM outbound_orders o
         LEFT JOIN clients c ON o.client_id = c.id
         LEFT JOIN inventory i ON o.sku = i.sku_id
         WHERE o.status = 'PICKED'
         ORDER BY o.created_at ASC"
    );
} catch (Exception $e) {
    error_log("Orders fetch error: " . $e->getMessage());
    $orders = [];
    $message = "Error loading orders for shipping.";
    $message_type = 'error';
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Ship Orders | ECWMS</title>
    <link rel="stylesheet" href="modern-style.css">
    <style>
        .ship-container {
            max-width: 1400px;
            margin: 0 auto;
        }
        .ship-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }
        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: var(--border-radius-lg);
            box-shadow: var(--box-shadow);
            text-align: center;
            border-left: 4px solid var(--success-green);
        }
        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--success-green);
            margin-bottom: 0.5rem;
        }
        .stat-label {
            color: var(--gray-600);
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.025em;
        }
        .orders-table {
            background: white;
            border-radius: var(--border-radius-lg);
            box-shadow: var(--box-shadow);
            overflow: hidden;
        }
        .order-row {
            transition: var(--transition);
        }
        .order-row:hover {
            background-color: var(--gray-50);
        }
        .ship-btn {
            padding: 0.5rem 1rem;
            font-size: 0.875rem;
        }
        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 500;
            text-transform: uppercase;
            background: rgba(34, 197, 94, 0.1);
            color: #166534;
        }
        .carrier-badge {
            background: var(--gray-100);
            color: var(--gray-700);
            padding: 0.25rem 0.5rem;
            border-radius: var(--border-radius);
            font-size: 0.75rem;
        }
        .address-cell {
            max-width: 200px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .inventory-info {
            font-size: 0.75rem;
            color: var(--gray-600);
        }
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: var(--gray-600);
        }
        .batch-actions {
            background: white;
            padding: 1rem;
            border-radius: var(--border-radius-lg);
            box-shadow: var(--box-shadow);
            margin-bottom: 1.5rem;
            text-align: center;
        }
        .urgent-order {
            border-left: 4px solid var(--danger-red) !important;
        }
        .urgent-order td:first-child::before {
            content: "🔥 ";
            color: var(--danger-red);
        }
    </style>
</head>
<body class="wms-layout">

<main class="wms-content">
    <div class="ship-container">
        <!-- Header -->
        <div class="text-center mb-4">
            <h1>🚚 Ship Picked Orders</h1>
            <p class="text-secondary">Process orders that have been picked and are ready for shipping</p>
        </div>

        <!-- Messages -->
        <?php if (!empty($message)): ?>
        <div class="alert alert-<?= $message_type === 'success' ? 'success' : ($message_type === 'warning' ? 'warning' : 'danger') ?>">
            <?= secure_escape($message) ?>
        </div>
        <?php endif; ?>

        <!-- Statistics -->
        <div class="ship-stats">
            <div class="stat-card">
                <div class="stat-value"><?= count($orders) ?></div>
                <div class="stat-label">Orders Ready to Ship</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= array_sum(array_column($orders, 'qty_ordered')) ?></div>
                <div class="stat-label">Total Items to Ship</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= count(array_unique(array_column($orders, 'carrier'))) ?></div>
                <div class="stat-label">Carriers</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= count(array_unique(array_column($orders, 'client_name'))) ?></div>
                <div class="stat-label">Clients</div>
            </div>
        </div>

        <?php if (!empty($orders)): ?>

        <!-- Batch Actions -->
        <div class="batch-actions">
            <h4>Batch Shipping</h4>
            <p class="text-secondary mb-3">Select multiple orders for batch shipping operations</p>
            <button class="btn btn-secondary" onclick="selectAllOrders()">Select All</button>
            <button class="btn btn-secondary" onclick="selectByCarrier()">Select by Carrier</button>
            <button class="btn btn-secondary" onclick="clearSelection()">Clear Selection</button>
            <button class="btn btn-success" onclick="batchShip()" id="batchShipBtn" disabled>
                🚚 Ship Selected Orders
            </button>
        </div>

        <!-- Orders Table -->
        <div class="orders-table">
            <form method="POST" id="shippingForm">
                <?= csrf_field() ?>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th width="40">
                                    <input type="checkbox" id="selectAll" onchange="toggleSelectAll()">
                                </th>
                                <th>Order #</th>
                                <th>SKU</th>
                                <th>Qty</th>
                                <th>Client</th>
                                <th>Carrier</th>
                                <th>Delivery Address</th>
                                <th>Status</th>
                                <th>Picked On</th>
                                <th>Inventory</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($orders as $order): ?>
                            <?php
                                // Mark urgent orders (picked more than 4 hours ago)
                                $picked_time = strtotime($order['created_at']);
                                $hours_since_pick = (time() - $picked_time) / 3600;
                                $is_urgent = $hours_since_pick > 4;
                            ?>
                            <tr class="order-row <?= $is_urgent ? 'urgent-order' : '' ?>"
                                data-order-id="<?= $order['id'] ?>"
                                data-carrier="<?= secure_escape($order['carrier']) ?>">
                                <td>
                                    <input type="checkbox" name="selected_orders[]" value="<?= $order['id'] ?>"
                                           class="order-checkbox" onchange="updateBatchButton()">
                                </td>
                                <td>
                                    <strong><?= secure_escape($order['order_number']) ?></strong>
                                    <?php if ($is_urgent): ?>
                                        <br><small class="text-danger">Urgent!</small>
                                    <?php endif; ?>
                                </td>
                                <td><?= secure_escape($order['sku']) ?></td>
                                <td><strong><?= number_format($order['qty_ordered']) ?></strong></td>
                                <td><?= secure_escape($order['client_name'] ?? 'Unassigned') ?></td>
                                <td>
                                    <span class="carrier-badge"><?= secure_escape($order['carrier']) ?></span>
                                </td>
                                <td class="address-cell" title="<?= secure_escape($order['delivery_address']) ?>">
                                    <?= secure_escape(substr($order['delivery_address'], 0, 50)) ?>
                                    <?= strlen($order['delivery_address']) > 50 ? '...' : '' ?>
                                </td>
                                <td>
                                    <span class="status-badge"><?= secure_escape($order['status']) ?></span>
                                </td>
                                <td>
                                    <?= date('M d, H:i', strtotime($order['created_at'])) ?>
                                    <?php if ($is_urgent): ?>
                                        <br><small class="text-danger"><?= round($hours_since_pick) ?>h ago</small>
                                    <?php endif; ?>
                                </td>
                                <td class="inventory-info">
                                    On Hand: <?= number_format($order['qty_on_hand']) ?><br>
                                    Allocated: <?= number_format($order['qty_allocated']) ?>
                                    <?php if ($order['qty_allocated'] < $order['qty_ordered']): ?>
                                        <br><small class="text-danger">⚠️ Insufficient allocation</small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($order['qty_allocated'] >= $order['qty_ordered']): ?>
                                        <button type="submit" name="ship_id" value="<?= $order['id'] ?>"
                                                class="btn btn-success ship-btn"
                                                onclick="return confirmShip('<?= secure_escape($order['order_number']) ?>', '<?= secure_escape($order['carrier']) ?>')">
                                            🚚 Ship
                                        </button>
                                    <?php else: ?>
                                        <button class="btn btn-danger ship-btn" disabled title="Insufficient allocation">
                                            ❌ Cannot Ship
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </form>
        </div>

        <?php else: ?>
        <div class="card">
            <div class="card-body empty-state">
                <h3>📭 No Orders Ready for Shipping</h3>
                <p>There are currently no picked orders ready for shipping.</p>
                <div class="mt-3">
                    <a href="pick_order_secure.php" class="btn btn-primary">📥 Pick Orders</a>
                    <a href="view_outbound.php" class="btn btn-secondary">📋 View All Orders</a>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Shipping Guidelines -->
        <div class="card mt-4">
            <div class="card-header">
                <h3 class="card-title">Shipping Process Information</h3>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <h4>Pre-Shipping Checks:</h4>
                        <ul style="margin-left: 1.5rem;">
                            <li>Verify picked quantities match order requirements</li>
                            <li>Confirm delivery address accuracy</li>
                            <li>Check carrier service availability</li>
                            <li>Ensure proper packaging for SKU type</li>
                            <li>Generate shipping labels and documentation</li>
                        </ul>
                    </div>
                    <div class="col-md-6">
                        <h4>Priority System:</h4>
                        <ul style="margin-left: 1.5rem;">
                            <li>🔥 <strong>Urgent:</strong> Orders picked >4 hours ago</li>
                            <li>📦 <strong>Standard:</strong> Recent picks</li>
                            <li>⚠️ <strong>Blocked:</strong> Insufficient inventory allocation</li>
                        </ul>

                        <h4 class="mt-3">After Shipping:</h4>
                        <ul style="margin-left: 1.5rem;">
                            <li>Inventory quantities automatically reduced</li>
                            <li>Allocated quantities released</li>
                            <li>Shipping confirmation generated</li>
                            <li>Order status updated to SHIPPED</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

        <!-- Navigation -->
        <div class="text-center mt-4">
            <a href="pick_order_secure.php" class="btn btn-secondary">📥 Back to Picking</a>
            <a href="secure-dashboard.php" class="btn btn-secondary">🏠 Dashboard</a>
        </div>
    </div>
</main>

<script>
// Batch selection functionality
function toggleSelectAll() {
    const selectAll = document.getElementById('selectAll');
    const checkboxes = document.querySelectorAll('.order-checkbox');

    checkboxes.forEach(checkbox => {
        checkbox.checked = selectAll.checked;
    });

    updateBatchButton();
}

function selectAllOrders() {
    document.getElementById('selectAll').checked = true;
    toggleSelectAll();
}

function selectByCarrier() {
    const carriers = [...new Set(Array.from(document.querySelectorAll('.order-row')).map(row =>
        row.getAttribute('data-carrier')))];

    if (carriers.length <= 1) {
        selectAllOrders();
        return;
    }

    const selectedCarrier = prompt(`Select carrier:\n${carriers.map((c, i) => `${i+1}. ${c}`).join('\n')}`);

    if (selectedCarrier) {
        const carrierIndex = parseInt(selectedCarrier) - 1;
        if (carrierIndex >= 0 && carrierIndex < carriers.length) {
            const targetCarrier = carriers[carrierIndex];

            document.querySelectorAll('.order-checkbox').forEach(checkbox => {
                const row = checkbox.closest('tr');
                checkbox.checked = row.getAttribute('data-carrier') === targetCarrier;
            });

            updateBatchButton();
        }
    }
}

function clearSelection() {
    document.getElementById('selectAll').checked = false;
    toggleSelectAll();
}

function updateBatchButton() {
    const selectedOrders = document.querySelectorAll('.order-checkbox:checked');
    const batchBtn = document.getElementById('batchShipBtn');

    batchBtn.disabled = selectedOrders.length === 0;
    batchBtn.textContent = selectedOrders.length > 0 ?
        `🚚 Ship ${selectedOrders.length} Orders` :
        '🚚 Ship Selected Orders';
}

function batchShip() {
    const selectedOrders = document.querySelectorAll('.order-checkbox:checked');

    if (selectedOrders.length === 0) {
        alert('Please select at least one order to ship.');
        return;
    }

    const orderDetails = Array.from(selectedOrders).map(cb => {
        const row = cb.closest('tr');
        return {
            number: row.querySelector('td:nth-child(2)').textContent.trim(),
            carrier: row.getAttribute('data-carrier')
        };
    });

    const confirmed = confirm(
        `Are you sure you want to ship ${selectedOrders.length} orders?\n\n` +
        `This will:\n` +
        `- Update inventory quantities\n` +
        `- Mark orders as SHIPPED\n` +
        `- Release allocated inventory\n\n` +
        `Selected orders: ${orderDetails.map(o => o.number).join(', ')}`
    );

    if (confirmed) {
        // Process each selected order with delay to prevent server overload
        selectedOrders.forEach((checkbox, index) => {
            setTimeout(() => {
                const shipBtn = checkbox.closest('tr').querySelector('.ship-btn:not([disabled])');
                if (shipBtn) {
                    shipBtn.click();
                }
            }, index * 1000); // 1 second delay between each
        });
    }
}

// Individual ship confirmation
function confirmShip(orderNumber, carrier) {
    return confirm(
        `Ship order ${orderNumber} via ${carrier}?\n\n` +
        `This will:\n` +
        `- Reduce inventory quantities\n` +
        `- Mark order as SHIPPED\n` +
        `- Release allocated inventory\n\n` +
        `This action cannot be easily undone.`
    );
}

// Auto-refresh every 60 seconds
setInterval(function() {
    if (document.visibilityState === 'visible') {
        // Only refresh if no orders are selected
        const selectedOrders = document.querySelectorAll('.order-checkbox:checked');
        if (selectedOrders.length === 0) {
            window.location.reload();
        }
    }
}, 60000);

// Keyboard shortcuts
document.addEventListener('keydown', function(e) {
    if (e.ctrlKey || e.metaKey) {
        switch(e.key) {
            case 'a':
                e.preventDefault();
                selectAllOrders();
                break;
            case 'Escape':
                clearSelection();
                break;
        }
    }
});
</script>

</body>
</html>

<?php $conn->close(); ?>
