<?php
require 'auth.php';
require_login();
include 'db_config.php';

$message = "";

// Handle shipping action
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['ship_id'])) {
    $order_id = intval($_POST['ship_id']);
    $order = $conn->query("SELECT * FROM outbound WHERE id = $order_id")->fetch_assoc();

    if ($order && $order['status'] == 'Picked') {
        $sku = $order['sku'];
        $qty = $order['qty_ordered'];

        $inv = $conn->query("SELECT id, total_qty, qty_allocated FROM inventory WHERE sku = '$sku'")->fetch_assoc();

        if ($inv && $inv['qty_allocated'] >= $qty) {
            $inv_id = $inv['id'];
            $new_qty = $inv['total_qty'] - $qty;
            $new_allocated = $inv['qty_allocated'] - $qty;
            $new_available = $new_qty - $new_allocated;

            $conn->query("UPDATE inventory 
                          SET total_qty = $new_qty, 
                              qty_allocated = $new_allocated, 
                              qty_available = $new_available 
                          WHERE id = $inv_id");

            $conn->query("UPDATE outbound SET status = 'Shipped' WHERE id = $order_id");

            $message = "✅ Order #{$order['order_number']} shipped and inventory updated.";
        } else {
            $message = "❌ Not enough allocated stock or item not found.";
        }
    } else {
        $message = "❌ Order not eligible for shipping.";
    }
}

// Fetch picked orders
$orders = $conn->query("SELECT o.*, c.client_name FROM outbound o 
                        LEFT JOIN clients c ON o.client_id = c.id 
                        WHERE o.status = 'Picked' ORDER BY o.created_at DESC");
?>

<!DOCTYPE html>
<html>
<head>
    <title>Ship Orders | ECWMS</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<h2 style="text-align:center;">🚚 Ship Picked Orders</h2>
<?php if ($message) echo "<p style='text-align:center;color:green;'>$message</p>"; ?>

<?php if ($orders && $orders->num_rows > 0): ?>
<form method="POST">
    <table border="1" cellpadding="6" style="margin:auto; width:95%; border-collapse:collapse;">
        <thead>
            <tr>
                <th>Order #</th>
                <th>Item</th>
                <th>Qty</th>
                <th>Client</th>
                <th>Carrier</th>
                <th>Status</th>
                <th>Ship</th>
            </tr>
        </thead>
        <tbody>
            <?php while($row = $orders->fetch_assoc()): ?>
            <tr>
                <td><?= $row['order_number'] ?></td>
                <td><?= $row['sku'] ?></td>
                <td><?= $row['qty_ordered'] ?></td>
                <td><?= $row['client_name'] ?? 'Unassigned' ?></td>
                <td><?= $row['carrier'] ?></td>
                <td><?= $row['status'] ?></td>
                <td>
                    <button type="submit" name="ship_id" value="<?= $row['id'] ?>">Ship</button>
                </td>
            </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
</form>
<?php else: ?>
    <p style="text-align:center;">No picked orders ready to ship.</p>
<?php endif; ?>

<div style="text-align:center; margin-top:20px;">
    <a href="dashboard.php">⬅️ Return to Dashboard</a>
</div>
</body>
</html>

<?php $conn->close(); ?>