<?php
require 'auth.php';
require_login();
include 'db_config.php';

// Fetch clients for dropdown
$clients = $conn->query("SELECT * FROM clients ORDER BY client_name ASC");
?>

<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>Add Inventory Item</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body {
      font-family: Arial, sans-serif;
      padding: 30px;
      background: #f8f9fa;
    }
    h2 {
      text-align: center;
      color: #333;
    }
    form {
      max-width: 500px;
      margin: 0 auto;
      background: white;
      padding: 20px;
      border-radius: 10px;
      box-shadow: 0 0 10px rgba(0,0,0,0.1);
    }
    label {
      display: block;
      margin-top: 10px;
      font-weight: bold;
    }
    input, select {
      width: 100%;
      padding: 10px;
      margin-top: 5px;
      margin-bottom: 15px;
      border: 1px solid #ccc;
      border-radius: 5px;
    }
    button {
      width: 100%;
      padding: 10px;
      background: #007bff;
      color: white;
      border: none;
      border-radius: 5px;
      font-size: 16px;
    }
    button:hover {
      background: #0056b3;
    }
    .back {
      text-align: center;
      margin-top: 20px;
    }
    .back a {
      color: #007bff;
      text-decoration: none;
    }
  </style>
</head>
<body>

<h2>Add New Inventory Item</h2>

<form method="POST" action="add_item.php">
  <label for="item_name">Item Name</label>
  <input type="text" name="item_name" id="item_name" required>

  <label for="quantity">Quantity</label>
  <input type="number" name="quantity" id="quantity" required>

  <label for="location">Location</label>
  <input type="text" name="location" id="location" required>

  <label for="client_id">Client</label>
  <select name="client_id" id="client_id" required>
    <option value="">-- Select Client --</option>
    <?php while ($row = $clients->fetch_assoc()): ?>
      <option value="<?= $row['id'] ?>"><?= $row['client_name'] ?></option>
    <?php endwhile; ?>
  </select>

  <button type="submit">➕ Add Item</button>
</form>

<div class="back">
  <a href="dashboard.php">⬅️ Return to Dashboard</a>
</div>

</body>
</html>
