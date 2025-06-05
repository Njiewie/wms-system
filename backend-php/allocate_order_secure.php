<?php
require_once 'security-utils.php';
require 'auth.php';
require_login();
include 'db_config.php';

// Set security headers
setSecurityHeaders();

// Validate CSRF for POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        validate_csrf();
    } catch (Exception $e) {
        handleSecurityError('Invalid security token');
    }
}

if (!isset($_GET['id'])) {
    echo "❌ No order selected. <a href='view_outbound.php'>Back</a>";
    exit;
}

try {
    $order_id = WMSSecurity::validateInteger($_GET['id'], 1);
} catch (Exception $e) {
    handleSecurityError('Invalid order ID');
}

// 1. Fetch order securely
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

    $sku = $order['sku'];
    $qty_ordered = $order['qty_ordered'];

    // 2. Fetch inventory securely
    $inventory = secure_select_one($conn,
        "SELECT * FROM inventory WHERE sku_id = ?",
        "s",
        [$sku]
    );

    if (!$inventory) {
        echo "❌ Inventory record not found for SKU '$sku'. <a href='view_outbound.php'>Back</a>";
        exit;
    }

    // Calculate available quantity
    $qty_available = $inventory['qty_on_hand'] - $inventory['qty_allocated'];

    if ($qty_available < $qty_ordered) {
        echo "❌ Not enough available stock. Required: $qty_ordered, Available: $qty_available. <a href='view_outbound.php'>Back</a>";
        exit;
    }

    // 3. Perform allocation securely
    $new_allocated = $inventory['qty_allocated'] + $qty_ordered;

    // Update inventory
    $updated_rows = secure_update($conn, 'inventory',
        ['qty_allocated' => $new_allocated],
        'id = ?',
        'i',
        [$inventory['id']]
    );

    if ($updated_rows > 0) {
        // Update order status
        secure_update($conn, 'outbound_orders',
            ['status' => 'ALLOCATED'],
            'id = ?',
            'i',
            [$order_id]
        );

        // Log the allocation activity
        WMSSecurity::logActivity($conn, $_SESSION['user'], 'order_allocated',
            "Order ID: $order_id, SKU: $sku, Qty: $qty_ordered");

        echo "✅ Order successfully allocated and inventory updated. <a href='view_outbound.php'>Back</a>";
    } else {
        echo "❌ Allocation failed. <a href='view_outbound.php'>Back</a>";
    }

} catch (Exception $e) {
    error_log("Allocation error: " . $e->getMessage());
    echo "❌ Allocation failed due to system error. <a href='view_outbound.php'>Back</a>";
}
?>
