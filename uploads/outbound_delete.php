<?php
require 'auth.php';
require_login();
include 'db_config.php';

if (!isset($_GET['id'])) {
    echo "❌ No item selected. <a href='view_outbound.php'>Back</a>";
    exit;
}

$id = (int) $_GET['id'];

$check = $conn->query("SELECT * FROM outbound_orders WHERE id = $id");
if ($check->num_rows !== 1) {
    echo "❌ Order not found. <a href='view_outbound.php'>Back</a>";
    exit;
}

$delete = $conn->query("DELETE FROM outbound_orders WHERE id = $id");

if ($delete) {
    echo "✅ Order deleted. <a href='view_outbound.php'>Back</a>";
} else {
    echo "❌ Delete failed: " . $conn->error;
}
?>
