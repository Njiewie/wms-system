<?php
require 'auth.php';
require_login();
include 'db_config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // handle insert query here
    echo "✅ Outbound added successfully. <a href='outbound.php'>Back to Outbound</a>";
    exit;
}
?>
<!DOCTYPE html>
<html><head><title>Add Outbound</title></head><body>
<h2>Add Outbound</h2>
<form method="POST">
<label>Item Name: <input type="text" name="item_name" required></label><br>
<label>Quantity: <input type="text" name="quantity" required></label><br>
<label>Destination: <input type="text" name="destination" required></label><br>
<label>Date: <input type="text" name="date" required></label><br>
<input type="submit" value="Add"></form></body></html>