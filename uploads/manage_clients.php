<?php
require 'auth.php';
require_admin();
include 'db_config.php';

// Handle client creation
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = $_POST['client_name'];
    $location = $_POST['client_location'];

    $stmt = $conn->prepare("INSERT INTO clients (client_name, client_location) VALUES (?, ?)");
    $stmt->bind_param("ss", $name, $location);
    $stmt->execute();
}

// Fetch all clients
$clients = $conn->query("SELECT * FROM clients ORDER BY client_name ASC");
?>

<!DOCTYPE html>
<html>
<head>
  <title>Manage Clients</title>
  <style>
    body { font-family: Arial; padding: 20px; }
    table { border-collapse: collapse; width: 80%; margin-top: 20px; }
    th, td { padding: 8px; border: 1px solid #ccc; }
    th { background: #f0f0f0; }
    form { margin-top: 20px; }
  </style>
</head>
<?php include 'footer.php'; ?>

</div>

<body>

<h2>👤 Manage Clients</h2>

<form method="POST">
  <label>Client Name: <input type="text" name="client_name" required></label><br><br>
  <label>Location: <input type="text" name="client_location" required></label><br><br>
  <button type="submit">➕ Add Client</button>
</form>

<h3>All Clients</h3>
<table>
  <tr>
    <th>ID</th>
    <th>Client Name</th>
    <th>Location</th>
    <th>Created At</th>
  </tr>
  <?php while ($row = $clients->fetch_assoc()): ?>
    <tr>
      <td><?= $row['id'] ?></td>
      <td><?= $row['client_name'] ?></td>
      <td><?= $row['client_location'] ?></td>
      <td><?= $row['created_at'] ?></td>
    </tr>
  <?php endwhile; ?>
</table>

</body>
</html>
