
<?php
require 'auth.php';
require_login();
include 'db_config.php';

if (!isset($_POST['item_id']) || empty($_POST['item_id'])) {
    echo "❌ No item selected.";
    exit;
}

$id = intval($_POST['item_id']);
$result = $conn->query("DELETE FROM inventory WHERE id = $id");

if ($result) {
    header("Location: view_inventory.php?msg=deleted");
    exit;
} else {
    echo "❌ Delete failed: " . $conn->error;
}
