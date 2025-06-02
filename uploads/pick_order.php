<?php
require 'auth.php';
require_login();
include 'db_config.php';

$message = "";

// Handle picking action
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['pick_id'])) {
    $order_id = intval($_POST['pick_id']);
    $order = $conn->query("SELECT * FROM outbound WHERE id = $order_id")->fetch_assoc();

    if ($order && $order['status'] == 'Allocated') {
        $conn->query("UPDATE outbound SET status = 'Picked' WHERE id = $order_id");
        $message = "✅ Order #{$order['order_number']} marked as Picked.";
    } else {
        $message = "❌ Order not eligible for picking.";
    }
}

// Fetch allocated orders
$orders = $conn->query("SELECT o.*, c.client_name FROM outbound o 
                        LEFT JOIN clients c ON o.client_id = c.id 
                        WHERE o.status = 'Allocated' ORDER BY o.created_at DESC");
?>

<!DOCTYPE html>
<html>
<head>
    <title>Pick Orders | ECWMS</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<h2 style="text-align:center;">📥 Pick Allocated Orders</h2>
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
                <th>Pick</th>
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
                    <button type="submit" name="pick_id" value="<?= $row['id'] ?>">Pick</button>
                </td>
            </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
</form>
<?php else: ?>
    <p style="text-align:center;">No orders ready for picking.</p>
<?php endif; ?>

<div style="text-align:center; margin-top:20px;">
    <a href="dashboard.php">⬅️ Return to Dashboard</a>
</div>
</body>
</html>

<?php $conn->close(); ?>