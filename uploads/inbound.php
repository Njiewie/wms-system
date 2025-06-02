<?php
require 'auth.php';
require_login();
include 'db_config.php';

// Handle status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $asn = $_POST['asn_number'];
    $status = $_POST['new_status'];
    $stmt = $conn->prepare("UPDATE asn_header SET status = ? WHERE asn_number = ?");
    $stmt->bind_param("ss", $status, $asn);
    $stmt->execute();
}

$asn_result = $conn->query("SELECT * FROM asn_header ORDER BY created_at DESC");
?>

<!DOCTYPE html>
<html>
<head>
  <title>Inbound Dashboard | ECWMS</title>
  <style>
    body {
      font-family: Arial, sans-serif;
      margin: 20px;
    }
    h2 {
      text-align: center;
    }
    table {
      width: 100%;
      border-collapse: collapse;
      margin-top: 20px;
    }
    th, td {
      border: 1px solid #ccc;
      padding: 8px;
      text-align: center;
    }
    th {
      background-color: #f4f4f4;
    }
    .btn {
      padding: 6px 12px;
      margin: 4px;
      text-decoration: none;
      border-radius: 5px;
      background-color: #007BFF;
      color: white;
    }
    .btn:hover {
      background-color: #0056b3;
    }
  </style>
</head>
<body>

<h2>📦 Inbound Management Dashboard</h2>

<div style="text-align:center; margin-bottom: 20px;">
  <a href="create_asn.php" class="btn">➕ Create ASN</a>
  <a href="asn_process.php" class="btn">🚚 Process ASN</a>
  <a href="putaway.php" class="btn">📥 Putaway</a>
</div>

<table>
  <thead>
    <tr>
      <th>ASN Number</th>
      <th>Supplier</th>
      <th>Status</th>
      <th>Arrival Date</th>
      <th>Created At</th>
      <th>Actions</th>
    </tr>
  </thead>
  <tbody>
    <?php while ($asn = $asn_result->fetch_assoc()): ?>
    <tr>
      <td><a href="asn_lines.php?asn_number=<?= urlencode($asn['asn_number']) ?>"><?= htmlspecialchars($asn['asn_number']) ?></a></td>
      <td><?= htmlspecialchars($asn['supplier_name']) ?></td>
      <td><?= htmlspecialchars($asn['status']) ?></td>
      <td><?= htmlspecialchars($asn['arrival_date']) ?></td>
      <td><?= htmlspecialchars($asn['created_at']) ?></td>
      <td>
        <form method="POST" style="display:inline;">
          <input type="hidden" name="asn_number" value="<?= htmlspecialchars($asn['asn_number']) ?>">
          <select name="new_status">
            <option <?= $asn['status'] === 'Hold' ? 'selected' : '' ?>>Hold</option>
            <option <?= $asn['status'] === 'Released' ? 'selected' : '' ?>>Released</option>
            <option <?= $asn['status'] === 'In Progress' ? 'selected' : '' ?>>In Progress</option>
            <option <?= $asn['status'] === 'Completed' ? 'selected' : '' ?>>Completed</option>
          </select>
          <button type="submit" name="update_status">Update</button>
        </form>
		
      </td>
    </tr>
    <?php endwhile; ?>
  </tbody>
</table>

</body>
</html>
