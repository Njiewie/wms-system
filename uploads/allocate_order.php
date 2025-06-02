<?php
require 'auth.php';
require_login();
include 'db_config.php';

if (!isset($_GET['id'])) {
    echo "❌ No order selected. <a href='view_outbound.php'>Back</a>";
    exit;
}

$order_id = (int) $_GET['id'];

// 1. Fetch order
$order_sql = "SELECT * FROM outbound_orders WHERE id = $order_id";
$order_result = $conn->query($order_sql);

if ($order_result->num_rows !== 1) {
    echo "❌ Order not found. <a href='view_outbound.php'>Back</a>";
    exit;
}

$order = $order_result->fetch_assoc();
$sku = $order['sku'];
$qty_ordered = $order['qty_ordered'];

// 2. Fetch inventory using only sku
$inv_sql = "SELECT * FROM inventory WHERE sku = '$sku'";
$inv_result = $conn->query($inv_sql);

if ($inv_result->num_rows !== 1) {
    echo "❌ Inventory record not found for sku '$sku'. <a href='view_outbound'>Back</a>";
    exit;
}

$inventory = $inv_result->fetch_assoc();

if ($inventory['qty_available'] < $qty_ordered) {
    echo "❌ Not enough available stock. Required: $qty_ordered, Available: {$inventory['qty_available']}. <a href='view_outbound'>Back</a>";
    exit;
}

// 3. Perform allocation
$new_allocated = $inventory['qty_allocated'] + $qty_ordered;
$new_available = $inventory['qty_available'] - $qty_ordered;

$update_inv = $conn->prepare("UPDATE inventory SET qty_allocated = ?, qty_available = ? WHERE id = ?");
$update_inv->bind_param("iii", $new_allocated, $new_available, $inventory['id']);

$update_order = $conn->prepare("UPDATE outbound_orders SET status = 'ALLOCATED' WHERE id = ?");
$update_order->bind_param("i", $order_id);

if ($update_inv->execute() && $update_order->execute()) {
    echo "✅ Order successfully allocated and inventory updated. <a href='view_outbound.php'>Back</a>";
} else {
    echo "❌ Allocation failed. <a href='view_outbound.php'>Back</a>";
}
?>
