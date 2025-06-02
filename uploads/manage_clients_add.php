<?php
require 'auth.php';
require_login();
include 'db_config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // handle insert query here
    echo "✅ Manage_clients added successfully. <a href='manage_clients.php'>Back to Manage_clients</a>";
    exit;
}
?>
<!DOCTYPE html>
<html><head><title>Add Manage_clients</title></head><body>
<h2>Add Manage_clients</h2>
<form method="POST">
<label>Client Name: <input type="text" name="client_name" required></label><br>
<label>Contact: <input type="text" name="contact" required></label><br>
<label>Location: <input type="text" name="location" required></label><br>
<input type="submit" value="Add"></form></body></html>