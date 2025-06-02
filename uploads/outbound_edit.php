<?php
require 'auth.php';
require_login();
include 'db_config.php';

if (!isset($_GET['id'])) {
    echo "❌ No item selected. <a href='view_outbound.php'>Back</a>";
    exit;
}

$id = (int) $_GET['id'];
$result = $conn->query("SELECT * FROM outbound_orders WHERE id = $id");
if ($result->num_rows !== 1) {
    echo "❌ Order not found. <a href='view_outbound.php'>Back</a>";
    exit;
}
$data = $result->fetch_assoc();

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $order_number = $_POST['order_number'];
    $sku = $_POST['sku'];
    $qty_ordered = (int) $_POST['qty_ordered'];
    $client_id = (int) $_POST['client_id'];
    $delivery_address = $_POST['delivery_address'];
    $carrier = $_POST['carrier'];
    $status = $_POST['status'];

    $update = $conn->prepare("UPDATE outbound_orders SET order_number=?, sku=?, qty_ordered=?, client_id=?, delivery_address=?, carrier=?, status=? WHERE id=?");
    $update->bind_param("ssiisssi", $order_number, $sku, $qty_ordered, $client_id, $delivery_address, $carrier, $status, $id);
    if ($update->execute()) {
        echo "✅ Order updated. <a href='view_outbound.php'>Back</a>";
        exit;
    } else {
        echo "❌ Update failed: " . $conn->error;
    }
}
?>

<!DOCTYPE html>
<html><head><title>Edit Outbound Order</title></head>
<body>
<h2>Edit Outbound Order</h2>
<form method="POST">
    <label>Order Number: <input type="text" name="order_number" value="<?= htmlspecialchars($data['order_number']) ?>"></label><br><br>
    <label>SKU: <input type="text" name="sku" value="<?= htmlspecialchars($data['sku']) ?>"></label><br><br>
    <label>Qty Ordered: <input type="number" name="qty_ordered" value="<?= htmlspecialchars($data['qty_ordered']) ?>"></label><br><br>
    <label>Client ID: <input type="number" name="client_id" value="<?= htmlspecialchars($data['client_id']) ?>"></label><br><br>
    <label>Delivery Address: <input type="text" name="delivery_address" value="<?= htmlspecialchars($data['delivery_address']) ?>"></label><br><br>
    <label>Carrier: <input type="text" name="carrier" value="<?= htmlspecialchars($data['carrier']) ?>"></label><br><br>
    <label>Status:
        <select name="status">
            <option value="HOLD" <?= $data['status'] === 'HOLD' ? 'selected' : '' ?>>HOLD</option>
            <option value="RELEASED" <?= $data['status'] === 'RELEASED' ? 'selected' : '' ?>>RELEASED</option>
        </select>
    </label><br><br>
    <input type="submit" value="Update Order">
</form>
</body></html>
