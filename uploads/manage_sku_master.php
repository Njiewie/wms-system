<?php
ob_start();
ob_start();

require 'auth.php';
require_login();
include 'db_config.php';
include 'sticky_header.php';
include 'persistent_header.php';
echo '<script>saveOpenedScreen("SKU Master ", "manage_sku_master.php");</script>';

$message = "";

// Fetch clients for dropdown
$clients = [];
$result = $conn->query("SELECT id, client_name FROM clients ORDER BY client_name ASC");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $clients[$row['id']] = $row['client_name'];
    }
}

// Handle Delete
if (isset($_POST['action']) && $_POST['action'] === 'delete') {
    $stmt = $conn->prepare("DELETE FROM sku_master WHERE sku_id = ?");
    
    if (!$stmt) {
        die("❌ SQL Prepare Error (Delete): " . $conn->error);
    }

    $stmt->bind_param("s", $_POST['delete_code']);
    $stmt->execute();
    $message = "🗑️ SKU deleted.";
}


// Handle CSV Import
if (isset($_POST['import_csv']) && isset($_FILES['csv_file'])) {
    $file = $_FILES['csv_file']['tmp_name'];
    $handle = fopen($file, "r");
    $header = fgetcsv($handle); // skip header row

    while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
        // Force system-generated values
        $user = $_SESSION['user']['username'] ?? 'admin';
        $data[8]  = $user;                  // created_by
        $data[9]  = date('Y-m-d');            // creation_date
        $data[10] = date('H:i:s');            // create_time
        $data[11] = $user;                  // last_updated_by
        $data[12] = date('Y-m-d');            // last_update_date
        $data[13] = date('H:i:s');            // last_update_time

        $stmt = $conn->prepare("INSERT INTO sku_master (
            sku_id, client_id, description, product_group, ean,
            pack_config, putaway_group, tag_merge, created_by,
            creation_date, create_time, last_updated_by, last_update_date,
            last_update_time, fragile, high_security, each_height, packed_height,
            each_width, packed_width, each_depth, packed_depth, each_volume,
            each_weight, packed_weight
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE 
            description=VALUES(description),
            product_group=VALUES(product_group),
            ean=VALUES(ean),
            pack_config=VALUES(pack_config),
            putaway_group=VALUES(putaway_group),
            tag_merge=VALUES(tag_merge),
            last_updated_by=VALUES(last_updated_by),
            last_update_date=VALUES(last_update_date),
            last_update_time=VALUES(last_update_time),
            fragile=VALUES(fragile),
            high_security=VALUES(high_security),
            each_height=VALUES(each_height),
            packed_height=VALUES(packed_height),
            each_width=VALUES(each_width),
            packed_width=VALUES(packed_width),
            each_depth=VALUES(each_depth),
            packed_depth=VALUES(packed_depth),
            each_volume=VALUES(each_volume),
            each_weight=VALUES(each_weight),
            packed_weight=VALUES(packed_weight)
        ");

        if (!$stmt) {
            die("❌ SQL Prepare Error (Import): " . $conn->error);
        }

        
        $fragile = strtoupper(trim($data[14])) === 'Y' ? 1 : 0;
        $high_security = strtoupper(trim($data[15])) === 'Y' ? 1 : 0;

        $stmt->bind_param("sissssssssssssddddddddddd",
            $data[0], $data[1], $data[2], $data[3], $data[4],
            $data[5], $data[6], $data[7], $data[8], $data[9],
            $data[10], $data[11], $data[12], $data[13],
            $fragile, $high_security, $data[16], $data[17], $data[18],
            $data[19], $data[20], $data[21], $data[22], $data[23],
            $data[24]
        );

        $stmt->execute();
    }

    $message = "✅ CSV import completed. Existing SKUs were updated.";
}

if (isset($_POST['mass_delete']) && !empty($_POST['selected_skus'])) {
    $sku_ids = array_map('intval', $_POST['selected_skus']);
    $placeholders = implode(',', array_fill(0, count($sku_ids), '?'));
    $stmt = $conn->prepare("DELETE FROM sku_master WHERE sku_id IN ($placeholders)");

    if ($stmt) {
        $stmt->bind_param(str_repeat('i', count($sku_ids)), ...$sku_ids);
        $stmt->execute();
        $message = "✅ Deleted " . $stmt->affected_rows . " SKU(s).";
    } else {
        $message = "❌ Failed to prepare deletion.";
    }
}

// Filters
$filters = [
    'sku_id', 'client_id', 'description', 'product_group', 'ean', 'pack_config', 'putaway_group',
    'tag_merge', 'created_by', 'last_updated_by', 'fragile', 'high_security',
    'each_height', 'packed_height', 'each_width', 'packed_width', 'each_depth', 'packed_depth',
    'each_volume', 'each_weight', 'packed_weight'
];
$where = [];
foreach ($filters as $field) {
    if (!empty($_GET[$field])) {
        $safe = $conn->real_escape_string($_GET[$field]);
        $where[] = "$field LIKE '%$safe%'";
    }
}

// Advanced date filtering
$date_filters = [
    'creation_date' => 'creation_date',
    'last_update_date' => 'last_update_date'
];
foreach ($date_filters as $key => $column) {
    if (!empty($_GET[$key . '_filter_type'])) {
        $type = $_GET[$key . '_filter_type'];
        $from = $_GET[$key . '_from'] ?? '';
        $to = $_GET[$key . '_to'] ?? '';

        if ($type === 'between' && $from && $to) {
            $where[] = "$column BETWEEN '" . $conn->real_escape_string($from) . "' AND '" . $conn->real_escape_string($to) . "'";
        } elseif ($type === 'not_between' && $from && $to) {
            $where[] = "$column NOT BETWEEN '" . $conn->real_escape_string($from) . "' AND '" . $conn->real_escape_string($to) . "'";
        }
    }
}
$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$page_size = isset($_GET['page_size']) ? (int)$_GET['page_size'] : 50;
$limit = in_array($page_size, [10, 25, 100]) ? $page_size : 50;
$offset = ($page - 1) * $limit;

// Count total SKUs for pagination
$count_result = $conn->query("SELECT COUNT(*) as total FROM sku_master $where_sql");
$total_rows = $count_result->fetch_assoc()['total'];
$total_pages = ceil($total_rows / $limit);

// Handle Export to CSV
if (isset($_GET['export']) && $_GET['export'] == 'csv') {
    $exportResult = $conn->query("SELECT * FROM sku_master ORDER BY sku_id ASC");
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment;filename=sku_master_export.csv');

    $output = fopen("php://output", "w");
    fputcsv($output, array_keys($exportResult->fetch_assoc())); // headers
    $exportResult->data_seek(0); // reset pointer
    while ($row = $exportResult->fetch_assoc()) {
        fputcsv($output, $row);
    }
    fclose($output);
    exit;
}

// Fetch all SKUs

$sort_by = $_GET['sort_by'] ?? 'sku_id';
$allowed_sort_columns = [
    'sku_id','client_name','description','product_group','ean','pack_config',
    'putaway_group','tag_merge','created_by','creation_date','create_time',
    'last_updated_by','last_update_date','last_update_time','fragile','high_security',
    'each_height','packed_height','each_width','packed_width','each_depth',
    'packed_depth','each_volume','each_weight','packed_weight'
];
if (!in_array($sort_by, $allowed_sort_columns)) {
    $sort_by = 'sku_id';
}
$skus = $conn->query("SELECT s.*, c.client_name FROM sku_master s LEFT JOIN clients c ON s.client_id = c.id $where_sql ORDER BY $sort_by ASC");


ob_end_flush();
?>

<!DOCTYPE html>
<html>
<head>
    <title>Manage SKU Master | ECWMS</title>
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
    </style>

<style>
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
</style>

</head>
<body>


<h2 style="text-align:center;">📦 SKU Master Management</h2>

<?php if ($message) echo "<p style='text-align:center;color:green;'>$message</p>"; ?>

<div class="filter-bar">
    <form method="GET" style="display: flex; flex-wrap: wrap; justify-content: center; gap: 8px; align-items: center;">
        <?php foreach ($filters as $field): ?>
            <input type="text" name="<?= $field ?>" placeholder="<?= ucwords(str_replace('_',' ',$field)) ?>" value="<?= htmlspecialchars($_GET[$field] ?? '') ?>">
        <?php endforeach; ?>

        
<select name="date_filter_choice" onchange="toggleDateFields(this.value)">
    <option value="">-- Choose Date Filter --</option>
    <option value="creation" <?= ($_GET['date_filter_choice'] ?? '') === 'creation' ? 'selected' : '' ?>>Creation Date Only</option>
    <option value="update" <?= ($_GET['date_filter_choice'] ?? '') === 'update' ? 'selected' : '' ?>>Last Update Date Only</option>
    <option value="both" <?= ($_GET['date_filter_choice'] ?? '') === 'both' ? 'selected' : '' ?>>Both</option>
</select>

<div id="creation_date_fields" style="display:none;">
    <label>Creation Date:</label>
    <select name="creation_date_filter_type">
        <option value="">-- Filter Type --</option>
        <option value="between" <?= ($_GET['creation_date_filter_type'] ?? '') === 'between' ? 'selected' : '' ?>>Between</option>
        <option value="not_between" <?= ($_GET['creation_date_filter_type'] ?? '') === 'not_between' ? 'selected' : '' ?>>Not Between</option>
    </select>
    <input type="date" name="creation_date_from" value="<?= htmlspecialchars($_GET['creation_date_from'] ?? '') ?>">
    <input type="date" name="creation_date_to" value="<?= htmlspecialchars($_GET['creation_date_to'] ?? '') ?>">
</div>

<div id="last_update_date_fields" style="display:none;">
    <label>Last Update Date:</label>
    <select name="last_update_date_filter_type">
        <option value="">-- Filter Type --</option>
        <option value="between" <?= ($_GET['last_update_date_filter_type'] ?? '') === 'between' ? 'selected' : '' ?>>Between</option>
        <option value="not_between" <?= ($_GET['last_update_date_filter_type'] ?? '') === 'not_between' ? 'selected' : '' ?>>Not Between</option>
    </select>
    <input type="date" name="last_update_date_from" value="<?= htmlspecialchars($_GET['last_update_date_from'] ?? '') ?>">
    <input type="date" name="last_update_date_to" value="<?= htmlspecialchars($_GET['last_update_date_to'] ?? '') ?>">
</div>

        <button type="submit">🔍 Filter</button>
        <a href="manage_sku_master.php"><button type="button">🔄 Reset</button></a>

   

    </form>

    <!-- Import CSV form -->
    <form method="POST" enctype="multipart/form-data" style="display: flex; justify-content: center; align-items: center; margin-top: 8px; gap: 8px;">
        <input type="file" name="csv_file" accept=".csv" required style="max-width: 180px;">
        <button type="submit" name="import_csv">📥 Import CSV</button>
		 <a href="export_sku_master.php?export=csv"><button type="button">📤 Export CSV</button></a>
    </form>
</div>



<div style="position:sticky; top:0; background:#f8f9fa; padding:10px 0; z-index:1000; text-align:center; box-shadow:0 2px 5px rgba(0,0,0,0.05);">
    <h2 style="margin:0;" </h2>
</div>
 
<form method="POST" action="manage_sku_master.php" onsubmit="return confirm('Are you sure you want to delete selected SKUs?');">
<h3 style="text-align:center;">Existing SKUs</h3>
<div class="scroll-table-wrapper">

<table>
    <thead>
<tr data-sku="<?= $row['sku_id'] ?>">
<th><input type='checkbox' onclick='toggleAll(this)'></th>
<th><a href="?sort_by=sku_id">SKU ID</a></th>
<th><a href="?sort_by=client_name">Client</a></th>
<th><a href="?sort_by=description">Description</a></th>
<th><a href="?sort_by=product_group">Product Group</a></th>
<th><a href="?sort_by=ean">EAN</a></th>
<th><a href="?sort_by=pack_config">Pack Config</a></th>
<th><a href="?sort_by=putaway_group">Putaway Group</a></th>
<th><a href="?sort_by=tag_merge">Tag Merge</a></th>
<th><a href="?sort_by=created_by">Created By</a></th>
<th><a href="?sort_by=creation_date">Created Date</a></th>
<th><a href="?sort_by=create_time">Created Time</a></th>
<th><a href="?sort_by=last_updated_by">Last Updated By</a></th>
<th><a href="?sort_by=last_update_date">Last Update Date</a></th>
<th><a href="?sort_by=last_update_time">Last Update Time</a></th>
<th><a href="?sort_by=fragile">Fragile</a></th>
<th><a href="?sort_by=high_security">High Security</a></th>
<th><a href="?sort_by=each_height">Each Height</a></th>
<th><a href="?sort_by=packed_height">Packed Height</a></th>
<th><a href="?sort_by=each_width">Each Width</a></th>
<th><a href="?sort_by=packed_width">Packed Width</a></th>
<th><a href="?sort_by=each_depth">Each Depth</a></th>
<th><a href="?sort_by=packed_depth">Packed Depth</a></th>
<th><a href="?sort_by=each_volume">Each Volume</a></th>
<th><a href="?sort_by=each_weight">Each Weight</a></th>
<th><a href="?sort_by=packed_weight">Packed Weight</a></th>
</tr>
</thead>
    <tbody>
        <?php if ($skus && $skus->num_rows > 0): ?>
            <?php while($row = $skus->fetch_assoc()): ?>
            <tr data-sku="<?= $row['sku_id'] ?>">
                <td><input type='checkbox' name='selected_skus[]' value='<?= $row['sku_id'] ?>'></td>
                <td><?= $row['sku_id'] ?></td>
                <td><?= $row['client_name'] ? $row['client_name'] : '<span style="color:red;">Unassigned</span>' ?></td>
                <td><?= $row['description'] ?></td>
                <td><?= $row['product_group'] ?></td>
                <td><?= $row['ean'] ?></td>
                <td><?= $row['pack_config'] ?></td>
                <td><?= $row['putaway_group'] ?></td>
                <td><?= $row['tag_merge'] ?></td>
                <td><?= $row['created_by'] ?></td>
                <td><?= $row['creation_date'] ?></td>
                <td><?= $row['create_time'] ?></td>
                <td><?= $row['last_updated_by'] ?></td>
                <td><?= $row['last_update_date'] ?></td>
                <td><?= $row['last_update_time'] ?></td>
                <td><?= $row['fragile'] ?></td>
                <td><?= $row['high_security'] ?></td>
                <td><?= $row['each_height'] ?></td>
                <td><?= $row['packed_height'] ?></td>
                <td><?= $row['each_width'] ?></td>
                <td><?= $row['packed_width'] ?></td>
                <td><?= $row['each_depth'] ?></td>
                <td><?= $row['packed_depth'] ?></td>
                <td><?= $row['each_volume'] ?></td>
                <td><?= $row['each_weight'] ?></td>
                <td><?= $row['packed_weight'] ?></td>
                
            </tr>
            <?php endwhile; ?>
        <?php else: ?>
            <tr data-sku="<?= $row['sku_id'] ?>"><td colspan="26" style="text-align:center;">No SKU records found.</td></tr>
        <?php endif; ?>
    </tbody>
</table></div>
<div style="text-align:center; margin-top: 20px;">

    <button type="submit" name="mass_delete" style="padding: 6px 14px;">🗑️ Delete Selected</button>
</div>
</form>

</form>
<div style="text-align:center; margin-top: 10px;">
    <form method="GET" style="margin-bottom: 10px;">
        <label>Page Size:</label>
        <select name="page_size" onchange="this.form.submit()">
            <option value="10" <?= $limit == 10 ? 'selected' : '' ?>>10</option>
            <option value="25" <?= $limit == 25 ? 'selected' : '' ?>>25</option>
            <option value="100" <?= $limit == 100 ? 'selected' : '' ?>>100</option>
        </select>
        <?php foreach ($_GET as $key => $value): if ($key !== "page_size" && $key !== "page"): ?>
            <input type="hidden" name="<?= htmlspecialchars($key) ?>" value="<?= htmlspecialchars($value) ?>">
        <?php endif; endforeach; ?>
    </form>

    <?php if ($page > 1): ?>
        <a href="?<?php foreach ($_GET as $k => $v) if ($k != 'page') echo $k . '=' . urlencode($v) . '&'; ?>page=<?= $page - 1 ?>">« Prev</a>
    <?php endif; ?>

    <?php for ($p = 1; $p <= $total_pages; $p++): ?>
        <a href="?<?php foreach ($_GET as $k => $v) if ($k != 'page') echo $k . '=' . urlencode($v) . '&'; ?>page=<?= $p ?>" style="margin: 0 5px; <?= ($p == $page) ? 'font-weight:bold;' : '' ?>">
            <?= $p ?>
        </a>
    <?php endfor; ?>

    <?php if ($page < $total_pages): ?>
        <a href="?<?php foreach ($_GET as $k => $v) if ($k != 'page') echo $k . '=' . urlencode($v) . '&'; ?>page=<?= $page + 1 ?>">Next »</a>
    <?php endif; ?>
</div>




<div style="text-align:center; margin-top: 20px;">
    <a href="dashboard.php" style="margin-right: 15px; text-decoration: none;">
        <button style="padding: 6px 14px;">⬅️ Return to Dashboard</button>
    </a>
    <a href="add_sku.php" style="margin-right: 15px; text-decoration: none;">
        <button style="padding: 6px 14px;">➕ Add New SKU</button>
    </a>
   
</div>



<script>
function toggleDateFields(choice) {
    document.getElementById('creation_date_fields').style.display = 
        (choice === 'creation' || choice === 'both') ? 'inline-block' : 'none';
    document.getElementById('last_update_date_fields').style.display = 
        (choice === 'update' || choice === 'both') ? 'inline-block' : 'none';
}
document.addEventListener('DOMContentLoaded', () => {
    toggleDateFields("<?= $_GET['date_filter_choice'] ?? '' ?>");
	 
});

</script>


<script>
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('table tbody tr').forEach(row => {
        row.addEventListener('click', (e) => {
    if (e.target.type === 'checkbox') return; // prevent row action on checkbox
    
    document.querySelectorAll('table tbody tr').forEach(r => r.classList.remove('highlight'));
    row.classList.add('highlight');
    const skuId = row.dataset.sku;
    if (skuId) {
        window.location.href = `update_sku.php?sku_id=${skuId}`;
    }
});

    });
});
</script>

<style>
tr.highlight {
    background-color: #d2eaf1 !important;
}
table tbody tr {
    cursor: pointer;
}
</style>


<script>
document.addEventListener('DOMContentLoaded', () => {
  const table = document.querySelector('table');
  let dragged;

  table.querySelectorAll('th').forEach(th => {
    th.setAttribute('draggable', true);

    th.addEventListener('dragstart', e => {
      dragged = e.target;
      e.dataTransfer.effectAllowed = 'move';
    });

    th.addEventListener('dragover', e => {
      e.preventDefault();
    });

    th.addEventListener('drop', e => {
      e.preventDefault();
      if (dragged && dragged !== e.target) {
        const draggedIndex = Array.from(dragged.parentNode.children).indexOf(dragged);
        const targetIndex = Array.from(e.target.parentNode.children).indexOf(e.target);
        moveColumn(table, draggedIndex, targetIndex);
      }
    });
  });

  function moveColumn(table, from, to) {
    for (let row of table.rows) {
      if (row.cells.length > Math.max(from, to)) {
        row.insertBefore(row.cells[from], row.cells[to + (from < to ? 1 : 0)]);
      }
    }
  }
});
</script>

</body>
</html>

<?php $conn->close(); ?>
<script>
function toggleAll(source) {
    document.querySelectorAll('input[name="selected_skus[]"]').forEach(cb => cb.checked = source.checked);
}
</script>
