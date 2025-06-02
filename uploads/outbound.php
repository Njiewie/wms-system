<?php
require 'auth.php';
require_login();
include 'db_config.php';

$message = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $order_number = $_POST['order_number'];
    $sku = $_POST['sku'];
    $qty_ordered = (int) $_POST['qty_ordered'];
    $client_id = (int) $_POST['client_id'];
    $delivery_address = $_POST['delivery_address'];
    $carrier = $_POST['carrier'];

    // Check inventory availability
    $inv = $conn->prepare("SELECT id, qty_available FROM inventory WHERE sku = ? AND client_id = ?");
    $inv->bind_param("si", $sku, $client_id);
    $inv->execute();
    $result = $inv->get_result();

    $status = "HOLD";

    if ($result && $result->num_rows > 0) {
        $data = $result->fetch_assoc();
        if ($data['qty_available'] >= $qty_ordered) {
            $status = "RELEASED";
        }
    }

    $stmt = $conn->prepare("INSERT INTO outbound_orders (order_number, sku, qty_ordered, client_id, delivery_address, carrier, status, created_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");

    if ($stmt) {
        $stmt->bind_param("ssiisss", $order_number, $sku, $qty_ordered, $client_id, $delivery_address, $carrier, $status);
        if ($stmt->execute()) {
            $message = "✅ Outbound order created successfully with status: <strong>$status</strong>.";
        } else {
            $message = "❌ Submission failed: " . $stmt->error;
        }
    } else {
        $message = "❌ Prepare failed: " . $conn->error;
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Create Outbound Order</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

<h2 style="text-align:center;">🚚 Create Outbound Order</h2>
<?php if ($message): ?>
    <p style="color:<?= strpos($message, '✅') !== false ? 'green' : 'red' ?>; text-align:center;"><?= $message ?></p>
<?php endif; ?>

<form method="POST" style="max-width:600px; margin:auto;">
    <label>Order Number:</label>
    <input type="text" name="order_number" required><br><br>

    <label>SKU:</label>
    <input type="text" name="sku" required><br><br>

    <label>Quantity Ordered:</label>
    <input type="number" name="qty_ordered" required><br><br>

    <label>Client ID:</label>
    <input type="number" name="client_id" required><br><br>

    <label>Delivery Address:</label>
    <input type="text" name="delivery_address" required><br><br>

    <label>Carrier:</label>
    <input type="text" name="carrier" required><br><br>

    <button type="submit">🚀 Submit Order</button>
</form>

<div style="text-align:center; margin-top:20px;">
    <a href="dashboard.php">⬅️ Return to Dashboard</a>
</div>

</body>
</html>

<?php $conn->close(); ?>
