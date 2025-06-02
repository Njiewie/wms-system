<?php
session_start();
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit;
}

include 'db_config.php';

$sql = "SELECT sm.*, i.item_name 
        FROM stock_movements sm 
        JOIN inventory i ON sm.item_id = i.id 
        ORDER BY sm.moved_at DESC";
$result = $conn->query($sql);
?>

<!DOCTYPE html>
<html>
<head>
  <title>Stock Movement History</title>
  <style>
   <style>
  .update { color: orange; font-weight: bold; }
  .delete { color: darkred; font-weight: bold; }
  .in { color: green; font-weight: bold; }
  .out { color: red; font-weight: bold; }
  .write-off { color: red; font-weight: bold; }  /* added this */
  table {
    width: 90%;
    border-collapse: collapse;
    margin: 20px auto;
  }
  th, td {
    padding: 8px 12px;
    border: 1px solid #444;
  }
  th {
    background-color: #eee;
  }
  h2 {
    text-align: center;
  }
</style>

</head>
<body>
  <h2>Stock Movement History</h2>
  <div style="text-align:center;">
    <a href="inbound.php">➕ Inbound</a> |
    <a href="outbound.php">➖ Outbound</a> |
    <a href="view_inventory.php">📦 Inventory</a> |
    <a href="export_inventory.php">⬇️ Export CSV</a>
  </div>

  <table>
    <thead>
      <tr>
        <th>ID</th>
        <th>Item Name</th>
        <th>Movement Type</th>
        <th>Qty Before</th>
        <th>Qty Changed</th>
        <th>Qty After</th>

        <th>Notes</th>
        <th>Moved At</th>
      </tr>
    </thead>
    <tbody>
      <?php
      if ($result->num_rows > 0) {
          while ($row = $result->fetch_assoc()) {
              $typeClass = strtolower(str_replace(' ', '-', $row['movement_type']));
              echo "<tr class=\"$typeClass\">
        <td>{$row['id']}</td>
        <td>{$row['item_name']}</td>
        <td class=\"$typeClass\">{$row['movement_type']}</td>
        <td>{$row['quantity_before']}</td>
        <td>{$row['quantity']}</td>
        <td>{$row['quantity_after']}</td>
        <td>{$row['notes']}</td>
        <td>{$row['moved_at']}</td>
      </tr>";

          }
      } else {
          echo "<tr><td colspan='6'>No movements found</td></tr>";
      }
      ?>
    </tbody>
  </table>

  <?php include 'footer.php'; ?>
</body>
</html>

<?php $conn->close(); ?>
