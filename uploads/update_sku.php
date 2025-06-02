<?php
require 'auth.php';
require_login();
include 'db_config.php';

$message = "";

if (!isset($_GET['sku_id'])) {
    die("❌ No SKU ID provided.");
}
$sku_id = $_GET['sku_id'];

// Fetch existing SKU
$stmt = $conn->prepare("SELECT * FROM sku_master WHERE sku_id = ?");
$stmt->bind_param("s", $sku_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows === 0) {
    die("❌ SKU not found.");
}
$sku = $result->fetch_assoc();

// Update if form submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fields = [
        'client_id', 'description', 'product_group', 'ean',
        'pack_config', 'putaway_group', 'tag_merge',
        'each_height', 'packed_height', 'each_width', 'packed_width',
        'each_depth', 'packed_depth', 'each_volume',
        'each_weight', 'packed_weight'
    ];
    $updates = [];
    foreach ($fields as $f) {
        $updates[$f] = $_POST[$f] ?? '';
    }

    $fragile = isset($_POST['fragile']) ? 1 : 0;
    $high_security = isset($_POST['high_security']) ? 1 : 0;
    $last_updated_by = $_SESSION['username'] ?? 'system';
    $last_update_date = date('Y-m-d');
    $last_update_time = date('H:i:s');

    $stmt = $conn->prepare("UPDATE sku_master SET 
        client_id=?, description=?, product_group=?, ean=?, pack_config=?, putaway_group=?, tag_merge=?,
        each_height=?, packed_height=?, each_width=?, packed_width=?, each_depth=?, packed_depth=?,
        each_volume=?, each_weight=?, packed_weight=?,
        fragile=?, high_security=?, last_updated_by=?, last_update_date=?, last_update_time=?
        WHERE sku_id=?");

    $stmt->bind_param("ssssssssddddddddddssss",
        $updates['client_id'], $updates['description'], $updates['product_group'], $updates['ean'],
        $updates['pack_config'], $updates['putaway_group'], $updates['tag_merge'],
        $updates['each_height'], $updates['packed_height'], $updates['each_width'], $updates['packed_width'],
        $updates['each_depth'], $updates['packed_depth'], $updates['each_volume'],
        $updates['each_weight'], $updates['packed_weight'],
        $fragile, $high_security, $last_updated_by, $last_update_date, $last_update_time,
        $sku_id
    );

    if ($stmt->execute()) {
        $message = "✅ SKU updated successfully.";
        
        $stmt->close();
        header("Location: manage_sku_master.php");
        exit;

    } else {
        $message = "❌ Update failed: " . $stmt->error;
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Update SKU</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background: rgba(0, 0, 0, 0.4);
            margin: 0;
        }
        .modal {
            position: fixed;
            top: 0; left: 0;
            width: 100vw; height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
        }
        .modal-content {
            background: white;
            padding: 30px;
            border-radius: 12px;
            width: 90%;
            max-width: 900px;
            box-shadow: 0 0 12px rgba(0,0,0,0.2);
        }
        h2 {
            text-align: center;
        }
        form {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        label {
            font-weight: bold;
            margin-bottom: 5px;
            display: block;
        }
        input, select {
            width: 100%;
            padding: 8px;
            border-radius: 6px;
            border: 1px solid #ccc;
        }
        .full-width {
            grid-column: span 2;
        }
        .checkbox-group {
            display: flex;
            gap: 20px;
            grid-column: span 2;
        }
        .footer {
            grid-column: span 2;
            text-align: center;
            margin-top: 20px;
        }
        button {
            background: #0077cc;
            color: white;
            padding: 10px 18px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
        }
        button:hover {
            background: #005fa3;
        }
    </style>
</head>
<body>
<div class="modal">
  <div class="modal-content">
    <h2>Update SKU: <?= htmlspecialchars($sku_id) ?></h2>
    <?php if ($message) echo "<p style='color:green;'>$message</p>"; ?>
    <form method="POST">
        <div>
            <label>Client ID</label>
            <input type="text" name="client_id" value="<?= htmlspecialchars($sku['client_id']) ?>">
        </div>
        <div>
            <label>Description</label>
            <input type="text" name="description" value="<?= htmlspecialchars($sku['description']) ?>">
        </div>
        <div>
            <label>Product Group</label>
            <input type="text" name="product_group" value="<?= htmlspecialchars($sku['product_group']) ?>">
        </div>
        <div>
            <label>EAN</label>
            <input type="text" name="ean" value="<?= htmlspecialchars($sku['ean']) ?>">
        </div>
        <div>
            <label>Pack Config</label>
            <input type="text" name="pack_config" value="<?= htmlspecialchars($sku['pack_config']) ?>">
        </div>
        <div>
            <label>Putaway Group</label>
            <input type="text" name="putaway_group" value="<?= htmlspecialchars($sku['putaway_group']) ?>">
        </div>
        <div>
            <label>Tag Merge</label>
            <input type="text" name="tag_merge" value="<?= htmlspecialchars($sku['tag_merge']) ?>">
        </div>
        <div></div>
        <div>
            <label>Each Height</label>
            <input type="number" step="any" name="each_height" value="<?= htmlspecialchars($sku['each_height']) ?>">
        </div>
        <div>
            <label>Packed Height</label>
            <input type="number" step="any" name="packed_height" value="<?= htmlspecialchars($sku['packed_height']) ?>">
        </div>
        <div>
            <label>Each Width</label>
            <input type="number" step="any" name="each_width" value="<?= htmlspecialchars($sku['each_width']) ?>">
        </div>
        <div>
            <label>Packed Width</label>
            <input type="number" step="any" name="packed_width" value="<?= htmlspecialchars($sku['packed_width']) ?>">
        </div>
        <div>
            <label>Each Depth</label>
            <input type="number" step="any" name="each_depth" value="<?= htmlspecialchars($sku['each_depth']) ?>">
        </div>
        <div>
            <label>Packed Depth</label>
            <input type="number" step="any" name="packed_depth" value="<?= htmlspecialchars($sku['packed_depth']) ?>">
        </div>
        <div>
            <label>Each Volume</label>
            <input type="number" step="any" name="each_volume" value="<?= htmlspecialchars($sku['each_volume']) ?>">
        </div>
        <div>
            <label>Each Weight</label>
            <input type="number" step="any" name="each_weight" value="<?= htmlspecialchars($sku['each_weight']) ?>">
        </div>
        <div>
            <label>Packed Weight</label>
            <input type="number" step="any" name="packed_weight" value="<?= htmlspecialchars($sku['packed_weight']) ?>">
        </div>
        <div class="checkbox-group">
            <label><input type="checkbox" name="fragile" <?= $sku['fragile'] ? 'checked' : '' ?>> Fragile</label>
            <label><input type="checkbox" name="high_security" <?= $sku['high_security'] ? 'checked' : '' ?>> High Security</label>
        </div>
        <div class="footer">
            <button type="submit">💾 Update SKU</button>
            <a href="manage_sku_master.php" style="margin-left: 20px;"><button type="button">⬅️ Back to SKUs</button></a>
        </div>
    </form>
  </div>
</div>
</body>
</html>
