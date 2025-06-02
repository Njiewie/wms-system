
<?php
require 'auth.php';
require_login();
include 'db_config.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $order_number = $_POST['order_number'];
    $sku = $_POST['sku'];
    $qty_ordered = (int) $_POST['qty_ordered'];
    $delivery_address = $_POST['delivery_address'];
    $carrier = $_POST['carrier'];
    $status = 'Hold';

    $stmt = $conn->prepare("INSERT INTO outbound_orders (order_number, sku, qty_ordered, delivery_address, carrier, status) VALUES (?, ?, ?, ?, ?, ?)");
    if ($stmt) {
        $stmt->bind_param("ssisss", $order_number, $sku, $qty_ordered, $delivery_address, $carrier, $status);
        if ($stmt->execute()) {
            echo "✅ Outbound order created successfully.<br><a href='outbound.php'>Return to Outbound</a>";
        } else {
            echo "❌ Failed to create order: " . $stmt->error;
        }
        $stmt->close();
    } else {
        echo "❌ Prepare failed: " . $conn->error;
    }
}
$conn->close();
?>
