<?php
require_once 'security-utils.php';
require 'auth.php';
require_login();
include 'db_config.php';

// Set security headers
setSecurityHeaders();

$message = '';
$message_type = 'info';

// Handle picking action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pick_id'])) {
    try {
        // Validate CSRF token
        validate_csrf();

        $order_id = WMSSecurity::validateInteger($_POST['pick_id'], 1);

        // Rate limiting for picking operations
        WMSSecurity::checkRateLimit('pick_order_' . $_SESSION['user'], 15, 300);

        // Start transaction for data integrity
        $conn->autocommit(false);

        // Fetch order details securely
        $order = secure_select_one($conn,
            "SELECT id, order_number, sku, qty_ordered, status, client_id FROM outbound_orders WHERE id = ?",
            "i",
            [$order_id]
        );

        if (!$order) {
            throw new Exception("Order not found");
        }

        // Validate order status
        if ($order['status'] !== 'ALLOCATED') {
            throw new Exception("Order is not in ALLOCATED status. Current status: {$order['status']}");
        }

        // Verify inventory allocation
        $inventory = secure_select_one($conn,
            "SELECT id, qty_on_hand, qty_allocated FROM inventory WHERE sku_id = ?",
            "s",
            [$order['sku']]
        );

        if (!$inventory) {
            throw new Exception("Inventory record not found for SKU: {$order['sku']}");
        }

        if ($inventory['qty_allocated'] < $order['qty_ordered']) {
            throw new Exception("Insufficient allocated quantity. Required: {$order['qty_ordered']}, Allocated: {$inventory['qty_allocated']}");
        }

        // Update order status to PICKED
        $updated_rows = secure_update($conn, 'outbound_orders',
            ['status' => 'PICKED'],
            'id = ?',
            'i',
            [$order_id]
        );

        if ($updated_rows === 0) {
            throw new Exception("Failed to update order status");
        }

        // Log the picking activity
        WMSSecurity::logActivity($conn, $_SESSION['user'], 'order_picked',
            "Order ID: {$order['id']}, Number: {$order['order_number']}, SKU: {$order['sku']}, Qty: {$order['qty_ordered']}");

        // Commit transaction
        $conn->commit();
        $conn->autocommit(true);

        $message = "✅ Order #{$order['order_number']} marked as Picked successfully.";
        $message_type = 'success';

    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollback();
        $conn->autocommit(true);

        error_log("Pick order error: " . $e->getMessage());
        $message = "❌ Picking failed: " . $e->getMessage();
        $message_type = 'error';
    }
}

// Fetch allocated orders securely
try {
    $orders = secure_select_all($conn,
        "SELECT o.id, o.order_number, o.sku, o.qty_ordered, o.carrier, o.status, o.created_at,
                c.client_name, i.qty_on_hand, i.qty_allocated
         FROM outbound_orders o
         LEFT JOIN clients c ON o.client_id = c.id
         LEFT JOIN inventory i ON o.sku = i.sku_id
         WHERE o.status = 'ALLOCATED'
         ORDER BY o.created_at ASC"
    );
} catch (Exception $e) {
    error_log("Orders fetch error: " . $e->getMessage());
    $orders = [];
    $message = "Error loading orders for picking.";
    $message_type = 'error';
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Pick Orders | ECWMS</title>
    <link rel="stylesheet" href="modern-style.css">
    <style>
        .pick-container {
            max-width: 1200px;
            margin: 0 auto;
        }
        .pick-stats {
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
            border-left: 4px solid var(--primary-blue);
        }
        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary-blue);
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
        .pick-btn {
            padding: 0.5rem 1rem;
            font-size: 0.875rem;
        }
        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 500;
            text-transform: uppercase;
            background: rgba(59, 130, 246, 0.1);
            color: #1e40af;
        }
        .priority-high {
            border-left-color: var(--danger-red) !important;
        }
        .priority-medium {
            border-left-color: var(--warning-orange) !important;
        }
        .priority-low {
            border-left-color: var(--success-green) !important;
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
    </style>
</head>
<body class="wms-layout">

<main class="wms-content">
    <div class="pick-container">
        <!-- Header -->
        <div class="text-center mb-4">
            <h1>📥 Pick Allocated Orders</h1>
            <p class="text-secondary">Process orders that have been allocated for picking</p>
        </div>

        <!-- Messages -->
        <?php if (!empty($message)): ?>
        <div class="alert alert-<?= $message_type === 'success' ? 'success' : ($message_type === 'warning' ? 'warning' : 'danger') ?>">
            <?= secure_escape($message) ?>
        </div>
        <?php endif; ?>

        <!-- Statistics -->
        <div class="pick-stats">
            <div class="stat-card">
                <div class="stat-value"><?= count($orders) ?></div>
                <div class="stat-label">Orders Ready to Pick</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= array_sum(array_column($orders, 'qty_ordered')) ?></div>
                <div class="stat-label">Total Items to Pick</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= count(array_unique(array_column($orders, 'sku'))) ?></div>
                <div class="stat-label">Unique SKUs</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= count(array_unique(array_column($orders, 'client_name'))) ?></div>
                <div class="stat-label">Clients</div>
            </div>
        </div>

        <?php if (!empty($orders)): ?>

        <!-- Batch Actions -->
        <div class="batch-actions">
            <h4>Batch Operations</h4>
            <p class="text-secondary mb-3">Select multiple orders for batch picking operations</p>
            <button class="btn btn-secondary" onclick="selectAllOrders()">Select All</button>
            <button class="btn btn-secondary" onclick="clearSelection()">Clear Selection</button>
            <button class="btn btn-primary" onclick="batchPick()" id="batchPickBtn" disabled>
                📥 Pick Selected Orders
            </button>
        </div>

        <!-- Orders Table -->
        <div class="orders-table">
            <form method="POST" id="pickingForm">
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
                                <th>Status</th>
                                <th>Created</th>
                                <th>Inventory</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($orders as $order): ?>
                            <?php
                                // Determine priority based on order age
                                $created_time = strtotime($order['created_at']);
                                $hours_old = (time() - $created_time) / 3600;
                                $priority_class = $hours_old > 24 ? 'priority-high' : ($hours_old > 8 ? 'priority-medium' : 'priority-low');
                            ?>
                            <tr class="order-row <?= $priority_class ?>" data-order-id="<?= $order['id'] ?>">
                                <td>
                                    <input type="checkbox" name="selected_orders[]" value="<?= $order['id'] ?>"
                                           class="order-checkbox" onchange="updateBatchButton()">
                                </td>
                                <td>
                                    <strong><?= secure_escape($order['order_number']) ?></strong>
                                </td>
                                <td><?= secure_escape($order['sku']) ?></td>
                                <td><strong><?= number_format($order['qty_ordered']) ?></strong></td>
                                <td><?= secure_escape($order['client_name'] ?? 'Unassigned') ?></td>
                                <td><?= secure_escape($order['carrier']) ?></td>
                                <td>
                                    <span class="status-badge"><?= secure_escape($order['status']) ?></span>
                                </td>
                                <td>
                                    <?= date('M d, H:i', strtotime($order['created_at'])) ?>
                                    <?php if ($hours_old > 24): ?>
                                        <br><small class="text-danger">⚠️ <?= round($hours_old) ?>h old</small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <small>
                                        On Hand: <?= number_format($order['qty_on_hand']) ?><br>
                                        Allocated: <?= number_format($order['qty_allocated']) ?>
                                    </small>
                                </td>
                                <td>
                                    <button type="submit" name="pick_id" value="<?= $order['id'] ?>"
                                            class="btn btn-success pick-btn"
                                            onclick="return confirmPick('<?= secure_escape($order['order_number']) ?>')">
                                        📥 Pick
                                    </button>
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
                <h3>📭 No Orders Ready for Picking</h3>
                <p>There are currently no allocated orders ready for picking.</p>
                <div class="mt-3">
                    <a href="view_outbound.php" class="btn btn-primary">📋 View All Orders</a>
                    <a href="allocate_order_secure.php" class="btn btn-secondary">📦 Allocate Orders</a>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Help Information -->
        <div class="card mt-4">
            <div class="card-header">
                <h3 class="card-title">Picking Process Information</h3>
            </div>
            <div class="card-body">
                <h4>Order Priority System:</h4>
                <ul style="margin-left: 1.5rem;">
                    <li><span style="color: var(--danger-red);">●</span> <strong>High Priority:</strong> Orders older than 24 hours</li>
                    <li><span style="color: var(--warning-orange);">●</span> <strong>Medium Priority:</strong> Orders older than 8 hours</li>
                    <li><span style="color: var(--success-green);">●</span> <strong>Low Priority:</strong> Recent orders</li>
                </ul>

                <h4 class="mt-3">Picking Guidelines:</h4>
                <ul style="margin-left: 1.5rem;">
                    <li>Verify SKU and quantity before picking</li>
                    <li>Check inventory location for efficient picking</li>
                    <li>Process high-priority orders first</li>
                    <li>Use batch picking for multiple orders with same SKU</li>
                    <li>Mark orders as picked only after physical picking is complete</li>
                </ul>
            </div>
        </div>

        <!-- Navigation -->
        <div class="text-center mt-4">
            <a href="secure-dashboard.php" class="btn btn-secondary">🏠 Dashboard</a>
            <a href="ship_order_secure.php" class="btn btn-primary">🚚 Ship Picked Orders</a>
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

function clearSelection() {
    document.getElementById('selectAll').checked = false;
    toggleSelectAll();
}

function updateBatchButton() {
    const selectedOrders = document.querySelectorAll('.order-checkbox:checked');
    const batchBtn = document.getElementById('batchPickBtn');

    batchBtn.disabled = selectedOrders.length === 0;
    batchBtn.textContent = selectedOrders.length > 0 ?
        `📥 Pick ${selectedOrders.length} Orders` :
        '📥 Pick Selected Orders';
}

function batchPick() {
    const selectedOrders = document.querySelectorAll('.order-checkbox:checked');

    if (selectedOrders.length === 0) {
        alert('Please select at least one order to pick.');
        return;
    }

    const orderNumbers = Array.from(selectedOrders).map(cb => {
        const row = cb.closest('tr');
        return row.querySelector('td:nth-child(2)').textContent.trim();
    });

    const confirmed = confirm(
        `Are you sure you want to pick ${selectedOrders.length} orders?\n\n` +
        `Orders: ${orderNumbers.join(', ')}\n\n` +
        'This will mark all selected orders as PICKED.'
    );

    if (confirmed) {
        // Process each selected order
        selectedOrders.forEach((checkbox, index) => {
            setTimeout(() => {
                const pickBtn = checkbox.closest('tr').querySelector('.pick-btn');
                pickBtn.click();
            }, index * 500); // Stagger the picks
        });
    }
}

// Individual pick confirmation
function confirmPick(orderNumber) {
    return confirm(`Are you sure you want to pick order ${orderNumber}?\n\nThis will mark the order as PICKED and ready for shipping.`);
}

// Auto-refresh every 30 seconds
setInterval(function() {
    if (document.visibilityState === 'visible') {
        // Only refresh if no orders are selected
        const selectedOrders = document.querySelectorAll('.order-checkbox:checked');
        if (selectedOrders.length === 0) {
            window.location.reload();
        }
    }
}, 30000);

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
