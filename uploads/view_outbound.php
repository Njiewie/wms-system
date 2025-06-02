<?php
require 'auth.php';
require_login();
include 'db_config.php';

// Process filters
$search = $_GET['search'] ?? '';
$status = $_GET['status'] ?? '';
$from_date = $_GET['from_date'] ?? '';
$to_date = $_GET['to_date'] ?? '';

$filters = [];
if ($search) {
    $search_safe = $conn->real_escape_string($search);
    $filters[] = "(o.order_number LIKE '%$search_safe%' OR o.sku LIKE '%$search_safe%' OR c.client_name LIKE '%$search_safe%')";
}
if ($status) {
    $status_safe = $conn->real_escape_string($status);
    $filters[] = "o.status = '$status_safe'";
}
if ($from_date) {
    $filters[] = "DATE(o.created_at) >= '$from_date'";
}
if ($to_date) {
    $filters[] = "DATE(o.created_at) <= '$to_date'";
}

$where_sql = $filters ? 'WHERE ' . implode(' AND ', $filters) : '';

$sql = "SELECT o.*, c.client_name 
        FROM outbound_orders o 
        LEFT JOIN clients c ON o.client_id = c.id 
        $where_sql
        ORDER BY o.created_at DESC";

$result = $conn->query($sql);
?>

<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>View Outbound Orders | ECWMS</title>
  <link rel="stylesheet" href="style.css">
  <style>
    body { font-family: Arial, sans-serif; }
    h2 { text-align: center; margin-top: 20px; }
    .filter-box {
      width: 95%;
      margin: 10px auto;
      padding: 10px;
      background: #f9f9f9;
      border: 1px solid #ccc;
      border-radius: 10px;
    }
    .filter-box form {
      display: flex;
      flex-wrap: wrap;
      justify-content: space-between;
    }
    .filter-box input, .filter-box select {
      margin: 5px;
      padding: 6px;
      width: 180px;
    }
    table {
      width: 95%;
      margin: 20px auto;
      border-collapse: collapse;
    }
    th, td {
      padding: 10px;
      border: 1px solid #ddd;
      text-align: center;
    }
    th {
      background-color: #f4f4f4;
    }
    tr.clickable-row:hover {
      background-color: #eef;
      cursor: pointer;
    }
    .actions {
      text-align: center;
      margin: 20px;
    }
    .actions button {
      padding: 8px 16px;
      margin: 0 5px;
      font-size: 14px;
    }
    .row-actions a {
      margin: 0 5px;
      text-decoration: none;
    }
  </style>
</head>
<body>

<h2>Outbound Orders</h2>

<div class="filter-box">
  <form method="GET">
    <input type="text" name="search" placeholder="Search Order, SKU, Client" value="<?= htmlspecialchars($search) ?>">
    <select name="status">
      <option value="">All Statuses</option>
      <option value="RELEASED" <?= $status == 'RELEASED' ? 'selected' : '' ?>>RELEASED</option>
      <option value="HOLD" <?= $status == 'HOLD' ? 'selected' : '' ?>>HOLD</option>
    </select>
    <input type="date" name="from_date" value="<?= htmlspecialchars($from_date) ?>">
    <input type="date" name="to_date" value="<?= htmlspecialchars($to_date) ?>">
    <button type="submit">Search</button>
  </form>
</div>

<?php if ($result->num_rows > 0): ?>
<table>
  <thead>
    <tr>
      <th>ID</th>
      <th>Order Number</th>
      <th>SKU</th>
      <th>Qty Ordered</th>
      <th>Status</th>
      <th>Client</th>
      <th>Carrier</th>
      <th>Delivery Address</th>
      <th>Created At</th>
    </tr>
  </thead>
  <tbody>
    <?php while ($row = $result->fetch_assoc()): ?>
    <tr class="clickable-row" data-id="<?= $row['id'] ?>">
      <td><?= $row['id'] ?></td>
      <td><?= $row['order_number'] ?></td>
      <td><?= $row['sku'] ?></td>
      <td><?= $row['qty_ordered'] ?></td>
      <td><?= $row['status'] ?></td>
      <td><?= $row['client_name'] ?></td>
      <td><?= $row['carrier'] ?></td>
      <td><?= $row['delivery_address'] ?></td>
      <td><?= $row['created_at'] ?></td>
    </tr>
    <?php endwhile; ?>
  </tbody>
</table>
<?php else: ?>
<p style="text-align:center;">No outbound orders found.</p>
<?php endif; ?>

<div class="actions">
  <button onclick="location.href='outbound.php'">➕ Add Order</button>
  <button onclick="editSelected()">✏️ Edit Selected</button>
  <button onclick="deleteSelected()">🗑️ Delete Selected</button>
  <button onclick="allocateSelected()">📦 Allocate Selected</button>
  <button onclick="location.href='dashboard.php'">🏠 Dashboard</button>
</div>

<script>
let selectedId = null;
document.querySelectorAll(".clickable-row").forEach(row => {
  row.addEventListener("click", () => {
    document.querySelectorAll(".clickable-row").forEach(r => r.style.backgroundColor = "");
    row.style.backgroundColor = "#d0e6ff";
    selectedId = row.dataset.id;
  });
});

function editSelected() {
  if (selectedId) {
    window.location.href = 'outbound_edit.php?id=' + selectedId;
  } else {
    alert("Please click on a row to select an order.");
  }
}

function deleteSelected() {
  if (selectedId) {
    if (confirm("Are you sure you want to delete this order?")) {
      window.location.href = 'outbound_delete.php?id=' + selectedId;
    }
  } else {
    alert("Please click on a row to select an order.");
  }
}

function allocateSelected() {
  if (selectedId) {
    if (confirm("Allocate this order and reserve inventory?")) {
      window.location.href = 'allocate_order.php?id=' + selectedId;
    }
  } else {
    alert("Please click on a row to select an order.");
  }
}
</script>

</body>
</html>
