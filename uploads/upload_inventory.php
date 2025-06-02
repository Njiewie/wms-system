<?php
require 'auth.php';
require_login();
include 'db_config.php';

$message = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    $file = $_FILES['csv_file']['tmp_name'];

    if (($handle = fopen($file, "r")) !== FALSE) {
        $header = fgetcsv($handle); // read column headers
        while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
            $item_code = $conn->real_escape_string($data[0]);
            $total_qty = intval($data[1]);
            $qty_allocated = intval($data[2]);
            $qty_available = intval($data[3]);
            $location = $conn->real_escape_string($data[4]);
            $client_id = intval($data[5]);

            // Lookup from SKU master
            $sku_check = $conn->query("SELECT description, pack_config FROM sku_master WHERE item_code = '$item_code'");
            if ($sku_row = $sku_check->fetch_assoc()) {
                $description = $sku_row['description'];
                $pack_config = $sku_row['pack_config'];
            } else {
                // Skip rows with unknown item_code
                continue;
            }

            $stmt = $conn->prepare("INSERT INTO inventory 
                (item_code, description, pack_config, quantity, qty_allocated, qty_available, location, client_id) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("sssiiisi", $item_code, $description, $pack_config, $total_qty, $qty_allocated, $qty_available, $location, $client_id);
            $stmt->execute();
        }
        fclose($handle);
        $message = "✅ Inventory uploaded successfully using SKU master.";
    } else {
        $message = "❌ Failed to read the CSV file.";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
  <title>Upload Inventory CSV | ECWMS</title>
</head>
<body>
  <h2>📤 Upload Inventory via CSV (with SKU Master)</h2>

  <?php if ($message) echo "<p>$message</p>"; ?>

  <form method="POST" enctype="multipart/form-data">
    <label>Select CSV File:</label>
    <input type="file" name="csv_file" accept=".csv" required>
    <button type="submit">Upload</button>
  </form>

  <p>CSV Format: <code>Item Code, Total Qty, QTY Allocated, QTY Available, Location, Client ID</code></p>
  <pre>
ITM001, 100, 20, 80, Zone A, 1
  </pre>

  <a href="view_inventory.php">⬅️ Back to Inventory</a>
</body>
</html>