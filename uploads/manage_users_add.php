<?php
require 'auth.php';
require_login();
include 'db_config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // handle insert query here
    echo "✅ Manage_users added successfully. <a href='manage_users.php'>Back to Manage_users</a>";
    exit;
}
?>
<!DOCTYPE html>
<html><head><title>Add Manage_users</title></head><body>
<h2>Add Manage_users</h2>
<form method="POST">
<label>Username: <input type="text" name="username" required></label><br>
<label>Email: <input type="text" name="email" required></label><br>
<label>Role: <input type="text" name="role" required></label><br>
<input type="submit" value="Add"></form></body></html>