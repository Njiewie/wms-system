<?php
require 'auth.php';
require_login();
include 'db_config.php';
?>

<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>ECWMS Dashboard</title>
  <link rel="stylesheet" href="style.css">
  <style>
    .accordion {
      width: 90%;
      max-width: 600px;
      margin: 20px auto;
      border-radius: 8px;
      overflow: hidden;
      box-shadow: 0 0 8px rgba(0,0,0,0.1);
    }

    .accordion-section {
      border-bottom: 1px solid #ccc;
    }

    .accordion-header {
      background-color: #007bff;
      color: white;
      padding: 16px;
      cursor: pointer;
      font-weight: bold;
    }

    .accordion-content {
      display: none;
      padding: 16px;
      background-color: #f9f9f9;
    }

    .accordion-content a {
      display: block;
      padding: 6px 0;
      color: #007bff;
    }

    .accordion-content a:hover {
      text-decoration: underline;
    }

    .logout {
      text-align: center;
      margin-top: 30px;
    }
  </style>
</head>
<body>

<?php
$inv_count = $conn->query("SELECT COUNT(*) AS total FROM inventory")->fetch_assoc()['total'];
$order_count = $conn->query("SELECT COUNT(*) AS total FROM outbound_orders")->fetch_assoc()['total'];
$low_stock_result = $conn->query("SELECT COUNT(*) AS total FROM inventory WHERE qty_on_hand < 5");
$low_stock = $low_stock_result ? $low_stock_result->fetch_assoc()['total'] : 0;
?>
<div style="width: 300px; margin: 20px auto; padding: 20px; background: #ffffff; border: 1px solid #ccc; border-radius: 10px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); font-family: Arial, sans-serif;">
  <h3 style="text-align:center; color: #003366; font-size: 20px; margin-bottom: 15px;">
    <img src="https://cdn-icons-png.flaticon.com/512/1828/1828919.png" width="20" style="vertical-align: middle;"> System Overview
  </h3>
  <p style="font-size: 16px;"><strong>Total Inventory Items:</strong> <span style="float:right; color:#333;"><?= $inv_count ?></span></p>
  <p style="font-size: 16px;"><strong>Total Outbound Orders:</strong> <span style="float:right; color:#333;"><?= $order_count ?></span></p>
  <p style="font-size: 16px; color:red;"><strong>Low Stock Alerts:</strong> <span style="float:right;"><?= $low_stock ?></span></p>
</div>


<div class="container">
  <h2>Welcome, <?= $_SESSION['user'] ?> (<?= $_SESSION['role'] ?>)</h2>

  <div class="accordion">
    <!-- Data Section -->
    <div class="accordion-section">
      <div class="accordion-header">📦 Data</div>
      <div class="accordion-content">
        <a href="view_inventory.php">Inventory</a>
        <a href="inbound.php">Inbound Stock</a>
        <a href="outbound.php">➕ Add Orders</a>
         <a href="view_outbound.php">➕ Outbound Orders</a>
        <a href="allocate_order.php">📦 Allocate Orders</a>
        <a href="pick_order.php">📥 Pick Orders</a>
        <a href="ship_order.php">🚚 Ship Orders</a>
        <a href="scan_item.php">Scan Item</a>
        <a href="sku.php">SKU</a>
        <a href="manage_sku_master.php">Item Master</a>
      </div>
    </div>

    <!-- Movements Section -->
    <div class="accordion-section">
      <div class="accordion-header">📈 Movements</div>
      <div class="accordion-content">
        <a href="view_movements.php">Stock Movement History</a>
      </div>
    </div>

    <!-- Admin Section -->
    <?php if ($_SESSION['role'] === 'admin'): ?>
    <div class="accordion-section">
      <div class="accordion-header">⚙️ Admin</div>
      <div class="accordion-content">
        <a href="manage_users.php">Manage Users</a>
        <a href="change_password.php">Change Password</a>
        <a href="view_logs.php">View Activity Logs</a>
        <a href="manage_clients.php">Manage Client</a>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <div class="logout">
    <a href="logout.php" style="color: darkred;">🔒 Logout</a>
  </div>
</div>

<script>
  const headers = document.querySelectorAll(".accordion-header");

  headers.forEach((header, index) => {
    const content = header.nextElementSibling;

    // Restore saved state
    const isOpen = localStorage.getItem("accordion_" + index);
    if (isOpen === "true") {
      content.style.display = "block";
    }

    header.addEventListener("click", () => {
      const visible = content.style.display === "block";
      content.style.display = visible ? "none" : "block";
      localStorage.setItem("accordion_" + index, !visible);
    });
  });
</script>

</body>
</html>


<style>
  .search-container {
    margin-left: 20px;
    flex-grow: 1;
    max-width: 300px;
  }
  .search-container input {
    width: 100%;
    padding: 6px 10px;
    border-radius: 4px;
    border: none;
    font-size: 13px;
  }
</style>


<script>
function filterDashboardLinks() {
  const query = document.getElementById('quickSearchInput').value.toLowerCase();
  document.querySelectorAll('.dashboard-box a').forEach(link => {
    const box = link.closest('.dashboard-box');
    const text = link.textContent.toLowerCase();
    box.style.display = text.includes(query) ? '' : 'none';
  });
}
</script>
