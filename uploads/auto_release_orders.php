
<?php
// Automatically release 'Hold' orders if sufficient inventory is available
require 'db_config.php';

$sql = "SELECT o.id, o.sku, o.qty_ordered, i.id AS inventory_id, i.qty_available
        FROM outbound_orders o
        JOIN inventory i ON o.sku = i.sku
        WHERE o.status = 'Hold' AND i.qty_available >= o.qty_ordered";

$result = $conn->query($sql);

while ($row = $result->fetch_assoc()) {
    $order_id = $row['id'];
    $inventory_id = $row['inventory_id'];
    $qty_ordered = $row['qty_ordered'];

    // Update outbound order status to Released
    $conn->query("UPDATE outbound_orders SET status = 'Released' WHERE id = $order_id");

    // Optionally log the release or notify user
    $note = 'Auto-release by system';
    $conn->query("INSERT INTO stock_movements (item_id, movement_type, quantity, notes)
                  VALUES ($inventory_id, 'RELEASE', 0, '$note')");
}
?>
