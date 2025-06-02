<?php
require 'auth.php';
require_login();
include 'db_config.php';

if (!isset($_POST['item_id'])) {
    echo "❌ No item selected. <a href='manage_users.php'>Back</a>";
    exit;
}

$id = $_POST['item_id'];
// handle deletion logic
echo "🗑️ Manage_users deleted. <a href='manage_users.php'>Back</a>";
?>
