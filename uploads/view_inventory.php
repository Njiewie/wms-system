
<?php
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
if (!in_array($limit, [10, 20, 40])) {
    $limit = 10;
}
$offset = ($page - 1) * $limit;
?>

<?php
require 'auth.php';
require_login();
include 'db_config.php';
include 'sticky_header.php';
include 'persistent_header.php';
echo '<script>saveOpenedScreen("Inventory ", "view_inventory.php");</script>';


$where = [];
$params = [];
$types = "";

if (!empty($_GET['tag_id'])) {
    $where[] = "tag_id = ?";
    $params[] = $_GET['tag_id'];
    $types .= "s";
}
if (!empty($_GET['client_id'])) {
    $where[] = "client_id = ?";
    $params[] = $_GET['client_id'];
    $types .= "s";
}
if (!empty($_GET['sku_id'])) {
    $where[] = "sku_id = ?";
    $params[] = $_GET['sku_id'];
    $types .= "s";
}
if (!empty($_GET['site_id'])) {
    $where[] = "site_id = ?";
    $params[] = $_GET['site_id'];
    $types .= "s";
}
if (!empty($_GET['location_id'])) {
    $where[] = "location_id = ?";
    $params[] = $_GET['location_id'];
    $types .= "s";
}
if (!empty($_GET['description'])) {
    $where[] = "description LIKE ?";
    $params[] = "%" . $_GET['description'] . "%";
    $types .= "s";
}
if (!empty($_GET['qty_on_hand'])) {
    $where[] = "qty_on_hand = ?";
    $params[] = $_GET['qty_on_hand'];
    $types .= "s";
}
if (!empty($_GET['qty_allocated'])) {
    $where[] = "qty_allocated = ?";
    $params[] = $_GET['qty_allocated'];
    $types .= "s";
}
if (!empty($_GET['Batch_id'])) {
    $where[] = "Batch_id = ?";
    $params[] = $_GET['Batch_id'];
    $types .= "s";
}
if (!empty($_GET['condition'])) {
    $where[] = "condition = ?";
    $params[] = $_GET['condition'];
    $types .= "s";
}
if (!empty($_GET['lock_status'])) {
    $where[] = "lock_status = ?";
    $params[] = $_GET['lock_status'];
    $types .= "s";
}
if (!empty($_GET['zone'])) {
    $where[] = "zone = ?";
    $params[] = $_GET['zone'];
    $types .= "s";
}
if (!empty($_GET['pallet_config'])) {
    $where[] = "pallet_config = ?";
    $params[] = $_GET['pallet_config'];
    $types .= "s";
}
if (!empty($_GET['receipt_id'])) {
    $where[] = "receipt_id = ?";
    $params[] = $_GET['receipt_id'];
    $types .= "s";
}
if (!empty($_GET['line_id'])) {
    $where[] = "line_id = ?";
    $params[] = $_GET['line_id'];
    $types .= "s";
}
if (!empty($_GET['receipt_dstamp'])) {
    $where[] = "DATE(receipt_dstamp) = ?";
    $params[] = $_GET['receipt_dstamp'];
    $types .= "s";
}
if (!empty($_GET['move_dstamp'])) {
    $where[] = "DATE(move_dstamp) = ?";
    $params[] = $_GET['move_dstamp'];
    $types .= "s";
}
if (!empty($_GET['count_dstamp'])) {
    $where[] = "DATE(count_dstamp) = ?";
    $params[] = $_GET['count_dstamp'];
    $types .= "s";
}
if (!empty($_GET['pallet_id'])) {
    $where[] = "pallet_id = ?";
    $params[] = $_GET['pallet_id'];
    $types .= "s";
}
if (!empty($_GET['container_id'])) {
    $where[] = "container_id = ?";
    $params[] = $_GET['container_id'];
    $types .= "s";
}
if (!empty($_GET['last_updated'])) {
    $where[] = "DATE(last_updated) = ?";
    $params[] = $_GET['last_updated'];
    $types .= "s";
}


$dateFields = ['receipt_dstamp', 'move_dstamp', 'count_dstamp', 'last_updated'];
foreach ($dateFields as $field) {
    $filterType = $_GET[$field . '_filter'] ?? '';
    $from = $_GET[$field . '_from'] ?? '';
    $to = $_GET[$field . '_to'] ?? '';

    if (!empty($from) || !empty($to)) {
        switch ($filterType) {
            case 'between':
                if ($from && $to) {
                    $where[] = "$field BETWEEN ? AND ?";
                    $params[] = $from;
                    $params[] = $to;
                    $types .= "ss";
                }
                break;
            case 'before':
                if ($to) {
                    $where[] = "$field < ?";
                    $params[] = $to;
                    $types .= "s";
                }
                break;
            case 'after':
                if ($from) {
                    $where[] = "$field > ?";
                    $params[] = $from;
                    $types .= "s";
                }
                break;
            case 'exclude':
                if ($from && $to) {
                    $where[] = "($field < ? OR $field > ?)";
                    $params[] = $from;
                    $params[] = $to;
                    $types .= "ss";
                }
                break;
        }
    }
}


$sql = "SELECT * FROM inventory WHERE qty_on_hand != 0";

if ($where) {
    $sql .= " WHERE " . implode(" AND ", $where);
}
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10000;
if (!in_array($limit, [1000, 2000, 4000])) {
    $limit = 10000;
}
$sql .= " ORDER BY sku_id ASC";

$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;
$sql .= " LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;
$types .= "ii";

$stmt = $conn->prepare($sql);
if ($stmt === false) {
    die("❌ SQL error: " . $conn->error);
}
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();


$item_code_filter = $_GET['sku'] ?? '';
$client_filter = $_GET['client'] ?? '';
$low_stock = isset($_GET['low_stock']);
?>

<!DOCTYPE html>
<html>


<head>
    <title>Inventory | ECWMS</title>
    <link rel="stylesheet" href="style.css">
    <style>
        table {
            border-collapse: collapse;
            margin: auto;
            width: 95%;
        }
        table th, td {
            padding: 6px;
            border: 1px solid #aaa;
            white-space: nowrap;
        }
        .filter-bar {
            text-align: center;
            margin-bottom: 10px;
        }
        .filter-bar input, .filter-bar select {
            padding: 4px;
            margin: 2px;
            width: 140px;
        }
  
    .scroll-table-wrapper {
        max-height: 550px;
        overflow-y: auto;
        border-top: 1px solid #ddd;
    }
    .scroll-table-wrapper table thead th {
        position: sticky;
        top: 0;
        background-color: #f2f2f2;
        z-index: 10;
    }
	.filter-group {
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    gap: 6px;
    margin-bottom: 4px;
}
.filter-group input,
.filter-group select {
    padding: 5px;
    width: 130px;
    font-size: 13px;
}
tr.selected {
  background-color: #d1ecf1 !important;
  color: #000;
}
tr.item-row {
  cursor: pointer;
}


.sticky-form-container {
  position: sticky;
  top: 0;
  background: white;
  z-index: 1000;
  padding: 10px 0;
  border-bottom: 1px solid #ccc;
}


th {
  position: sticky;
  top: 0;
  background: #fff;
  z-index: 100;
  box-shadow: 0 1px 2px rgba(0,0,0,0.05);
}
</style>


</head>
<body>


<h2 style="text-align:center;">Inventory</h2>



<div class="filter-bar">





<?php
$count_sql = "SELECT COUNT(*) FROM inventory";
if (!empty($where)) {
    $count_sql .= " WHERE " . implode(" AND ", $where);
}
$count_stmt = $conn->prepare($count_sql);

if (!empty($where)) {
    $bind_types = substr($types, 0, -2);
    $bind_values = array_slice($params, 0, -2);
    if (!empty($bind_types) && !empty($bind_values)) {
        $count_stmt->bind_param($bind_types, ...$bind_values);
    }
}

$count_stmt->execute();
$count_result = $count_stmt->get_result();
$total_rows = $count_result->fetch_row()[0];
$total_pages = ceil($total_rows / $limit);
?>


<div style="max-height: 300px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; margin-bottom: 20px;">
<form method="GET" style="display: flex; flex-direction: column; align-items: center;">
  <!-- Line 1 -->
  <div class="filter-group">
    <input type="text" name="tag_id" placeholder="Tag ID" value="<?= htmlspecialchars($_GET['tag_id'] ?? '') ?>">
    <input type="text" name="client_id" placeholder="Client ID" value="<?= htmlspecialchars($_GET['client_id'] ?? '') ?>">
    <input type="text" name="sku_id" placeholder="SKU ID" value="<?= htmlspecialchars($_GET['sku_id'] ?? '') ?>">
    <input type="text" name="site_id" placeholder="Site ID" value="<?= htmlspecialchars($_GET['site_id'] ?? '') ?>">
    <input type="text" name="location_id" placeholder="Location ID" value="<?= htmlspecialchars($_GET['location_id'] ?? '') ?>">
    <input type="text" name="description" placeholder="Description" value="<?= htmlspecialchars($_GET['description'] ?? '') ?>">
    <input type="text" name="qty_on_hand" placeholder="Qty on Hand" value="<?= htmlspecialchars($_GET['qty_on_hand'] ?? '') ?>">
    <input type="text" name="qty_allocated" placeholder="Qty Allocated" value="<?= htmlspecialchars($_GET['qty_allocated'] ?? '') ?>">
  </div>

  <!-- Line 2 -->
  <div class="filter-group">
    <input type="text" name="Batch_id" placeholder="Batch ID" value="<?= htmlspecialchars($_GET['Batch_id'] ?? '') ?>">
    <input type="text" name="condition" placeholder="Condition ID" value="<?= htmlspecialchars($_GET['condition'] ?? '') ?>">
    <input type="text" name="lock_status" placeholder="Lock Status" value="<?= htmlspecialchars($_GET['lock_status'] ?? '') ?>">
    <input type="text" name="zone" placeholder="Zone 1" value="<?= htmlspecialchars($_GET['zone'] ?? '') ?>">
    <input type="text" name="pallet_config" placeholder="Pallet Config" value="<?= htmlspecialchars($_GET['pallet_config'] ?? '') ?>">
    <input type="text" name="receipt_id" placeholder="Receipt ID" value="<?= htmlspecialchars($_GET['receipt_id'] ?? '') ?>">
    <input type="text" name="line_id" placeholder="Line ID" value="<?= htmlspecialchars($_GET['line_id'] ?? '') ?>">
    <input type="text" name="pallet_id" placeholder="Pallet ID" value="<?= htmlspecialchars($_GET['pallet_id'] ?? '') ?>">
  </div>


  <!-- Line 3 (Compact Dates) -->
  <div class="filter-group">
    <select name="receipt_dstamp_filter">
      <option value="between">Receipt: Between</option>
      <option value="before">Before</option>
      <option value="after">After</option>
      <option value="exclude">Exclude</option>
    </select>
    <input type="date" name="receipt_dstamp_from" value="<?= htmlspecialchars($_GET['receipt_dstamp_from'] ?? '') ?>">
    <input type="date" name="receipt_dstamp_to" value="<?= htmlspecialchars($_GET['receipt_dstamp_to'] ?? '') ?>">

    <select name="move_dstamp_filter">
      <option value="between">Move: Between</option>
      <option value="before">Before</option>
      <option value="after">After</option>
      <option value="exclude">Exclude</option>
    </select>
    <input type="date" name="move_dstamp_from" value="<?= htmlspecialchars($_GET['move_dstamp_from'] ?? '') ?>">
    <input type="date" name="move_dstamp_to" value="<?= htmlspecialchars($_GET['move_dstamp_to'] ?? '') ?>">

    <input type="date" name="count_dstamp" value="<?= htmlspecialchars($_GET['count_dstamp'] ?? '') ?>" placeholder="Count Date">
    <input type="date" name="last_updated" value="<?= htmlspecialchars($_GET['last_updated'] ?? '') ?>" placeholder="Last Updated">
    <input type="text" name="container_id" placeholder="Container ID" value="<?= htmlspecialchars($_GET['container_id'] ?? '') ?>">
  </div>

  <div style="margin-top: 6px;">
    <button type="submit">🔍 Apply Filters</button>
    <a href="view_inventory.php"><button type="button">🔄 Reset</button></a>
	<a href="export_inventory_csv.php" target="_blank">
  <button type="button">📤 Export to Excel</button>

</a>

  </div>
</form>
</div>

<div style="display: flex; justify-content: space-between; align-items: center; margin: 10px 0; padding: 0 15px;">
  <form method="GET" style="margin: 0;">
    <label>Page:
      <select name="page" onchange="this.form.submit()">
        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
          <option value="<?= $i ?>" <?= ($i == $page) ? 'selected' : '' ?>><?= $i ?></option>
        <?php endfor; ?>
      </select>
    </label>
    <input type="hidden" name="limit" value="<?= $limit ?>">
  </form>

  <div style="text-align: right;">
    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
      <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"
         style="margin:0 5px; <?= ($i == $page) ? 'font-weight:bold;' : '' ?>"><?= $i ?></a>
    <?php endfor; ?>
  </div>
</div>








<?php
$conditions = [];
if (!empty($item_code_filter)) {
    $conditions[] = "sku_id LIKE '%" . $conn->real_escape_string($item_code_filter) . "%'";

}
if (!empty($client_filter)) {
    $conditions[] = "client LIKE '%" . $conn->real_escape_string($client_filter) . "%'";
}
if ($low_stock) {
    $conditions[] = "qty_available < 10";
}
$where_sql = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';



if (!$result) {
    echo "❌ Query failed: " . $conn->error;
    exit;
}
?>
<?php if ($result->num_rows > 0): ?>

<div style="overflow-x:auto; width: 100%; max-width: 100vw; padding-bottom: 80px;">
<style>
th { position: sticky; top: 0; background: white; z-index: 10; }

.sticky-form-container {
  position: sticky;
  top: 0;
  background: white;
  z-index: 1000;
  padding: 10px 0;
  border-bottom: 1px solid #ccc;
}


th {
  position: sticky;
  top: 0;
  background: #fff;
  z-index: 100;
  box-shadow: 0 1px 2px rgba(0,0,0,0.05);
}
</style>

<table style="min-width: 2000px;">
<tr>
<th>tag_id</th><th>client_id</th><th>sku_id</th><th>site_id</th><th>location_id</th>
<th>description</th><th>qty_on_hand</th><th>qty_allocated</th><th>batch_id</th><th>condition</th>
<th>lock_status</th><th>zone</th><th>pallet_config</th><th>receipt_id</th><th>line_id</th>
<th>receipt_dstamp</th><th>receipt_time</th><th>move_dstamp</th><th>move_time</th>
<th>count_dstamp</th><th>expiry_date</th><th>pallet_id</th><th>container_id</th><th>last_updated</th>
</tr>
<?php while ($row = $result->fetch_assoc()): ?>
<tr class='item-row' data-id='<?= $row['tag_id'] ?? '' ?>'>
    <td><?= $row['tag_id'] ?? '' ?></td>
    <td><?= $row['client_id'] ?? '' ?></td>
    <td><?= $row['sku_id'] ?? '' ?></td>
    <td><?= $row['site_id'] ?? '' ?></td>
    <td><?= $row['location_id'] ?? '' ?></td>
    <td><?= $row['description'] ?? '' ?></td>
    <td><?= $row['qty_on_hand'] ?? '' ?></td>
    <td><?= $row['qty_allocated'] ?? '' ?></td>
    <td><?= $row['batch_id'] ?? '' ?></td>
    <?php
$conditionStyles = [
    'OK1' => 'color: green;',
    'OK2' => 'color: green;',
    'DM1' => 'color: red;',
    'QC1' => 'color: orange;',
    'BL1' => 'color: blue;',
    'RT1' => 'color: purple;',
];
$condition = $row['condition'];
$style = $conditionStyles[$condition] ?? '';
?>
<td style="<?= $style ?>"><?= htmlspecialchars($condition) ?></td>

    <td><?= $row['lock_status'] ?? '' ?></td>
    <td><?= $row['zone'] ?? '' ?></td>
    <td><?= $row['pallet_config'] ?? '' ?></td>
    <td><?= $row['receipt_id'] ?? '' ?></td>
    <td><?= $row['line_id'] ?? '' ?></td>
    <td><?= $row['receipt_dstamp'] ?? '' ?></td>
    <td><?= $row['receipt_time'] ?? '' ?></td>
    <td><?= $row['move_dstamp'] ?? '' ?></td>
    <td><?= $row['move_time'] ?? '' ?></td>
    <td><?= $row['count_dstamp'] ?? '' ?></td>
    <td><?= $row['expiry_date'] ?? '' ?></td>
    <td><?= $row['pallet_id'] ?? '' ?></td>
    <td><?= $row['container_id'] ?? '' ?></td>
    <td><?= $row['last_updated'] ?? '' ?></td>
</tr>
<?php endwhile; ?>
</table>

</div>
<?php else: ?>
<p>No inventory records found for the selected filters.</p>
<?php endif; ?>

<!-- Footer Actions -->

<div style="text-align:center; margin-top: 20px;">
  <form id="footerForm" method="POST">
    <input type="hidden" name="item_id" id="selected_id">
    <button type="button" onclick="handleAdd()">➕ Add</button>
    <button type="button" id="updateBtn" disabled onclick="handleEdit()">✏️ Update</button>
    <button type="submit" formaction="" id="deleteBtn" disabled onclick="return confirm('Delete this item?')">🗑️ Delete</button>
    <a href="dashboard.php" style="margin-left: 20px;">⬅️ Dashboard</a>
  </form>



<script>
let selectedId = null;
let previouslySelected = null;
let currentModule = "inventory";

function setSelectedId(id, row) {
    selectedId = id;
    document.getElementById("selected_id").value = id;
    document.getElementById("updateBtn").disabled = false;
    document.getElementById("deleteBtn").disabled = false;

    if (previouslySelected) {
        previouslySelected.classList.remove("selected");
    }
    row.classList.add("selected");
    previouslySelected = row;
}

function handleAdd() {
    window.location.href = currentModule + "_add.php";
}

function handleEdit() {
    if (selectedId && currentModule) {
        window.location.href = currentModule + "_edit.php?tag_id=" + selectedId;
    }
}

document.addEventListener("DOMContentLoaded", () => {
    document.querySelectorAll(".item-row").forEach(row => {
        row.addEventListener("click", () => {
            const id = row.getAttribute("data-id");
            setSelectedId(id, row);
        });
    });
});
</script>

</body>
</html>
