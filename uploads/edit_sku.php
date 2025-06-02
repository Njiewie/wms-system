<?php
require 'auth.php';
require_login();
include 'db_config.php';

if (!isset($_GET['item_code'])) {
    echo "❌ No item selected. <a href='manage_sku_master.php'>Back</a>";
    exit;
}

$item_code = $_GET['item_code'];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $stmt = $conn->prepare("UPDATE sku_master SET description=?, pack_config=?, ean=?, serial_number=?, origin=?, dimension=?, unit_weight=?, client=? WHERE item_code=?");
    $stmt->bind_param("sssssssis", $_POST['description'], $_POST['pack_config'], $_POST['ean'], $_POST['serial_number'],
                                   $_POST['origin'], $_POST['dimension'], $_POST['unit_weight'], $_POST['client_id'], $item_code);
    if ($stmt->execute()) {
        
    echo "<div style='text-align:center; color:green; font-weight:bold; margin-top:20px;'>
            ✅ SKU updated successfully.
            <br><br><a href='manage_sku_master.php' style='color:#007bff;'>⬅️ Return to SKU Master</a>
          </div>";
    
    } else {
        echo "❌ Update failed: " . $stmt->error;
    }
    exit;
}

$item = $conn->query("SELECT * FROM sku_master WHERE item_code = '$item_code'")->fetch_assoc();
$clients = $conn->query("SELECT id, client_name FROM clients ORDER BY client_name ASC");
?>

<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>Edit SKU | ECWMS</title>
  <link rel="stylesheet" href="style.css">
</head>
<body>
<h2 style="text-align:center;">✏️ Edit SKU</h2>

<form method="POST" style="max-width:600px;margin:auto;">
  <label>Item Code:</label>
  <input type="text" value="<?= htmlspecialchars($item['item_code']) ?>" disabled><br><br>

  <label>Description:</label>
  <input type="text" name="description" value="<?= htmlspecialchars($item['description']) ?>"><br><br>

  <label>Pack Config:</label>
  <input type="text" name="pack_config" value="<?= htmlspecialchars($item['pack_config']) ?>"><br><br>

  <label>EAN:</label>
  <input type="text" name="ean" value="<?= htmlspecialchars($item['ean']) ?>"><br><br>

  <label>Serial Number:</label>
  <input type="text" name="serial_number" value="<?= htmlspecialchars($item['serial_number']) ?>"><br><br>

  <label>Origin:</label>
  <input type="text" name="origin" value="<?= htmlspecialchars($item['origin']) ?>"><br><br>

  <label>Dimension:</label>
  <input type="text" name="dimension" value="<?= htmlspecialchars($item['dimension']) ?>"><br><br>

  <label>Unit Weight:</label>
  <input type="text" name="unit_weight" value="<?= htmlspecialchars($item['unit_weight']) ?>"><br><br>

  <label>Client:</label>
  <select name="client_id" required>
    <option value="">-- Select Client --</option>
    <?php while($client = $clients->fetch_assoc()): ?>
      <option value="<?= $client['id'] ?>" <?= ($item['client'] == $client['id']) ? 'selected' : '' ?>>
        <?= htmlspecialchars($client['client_name']) ?>
      </option>
    <?php endwhile; ?>
  </select><br><br>

  <button type="submit">💾 Save Changes</button>
</form>

<div style="text-align:center;margin-top:20px;">
  <a href="manage_sku_master.php">⬅️ Back to SKU Master</a>
</div>
</body>
</html>

<?php $conn->close(); ?>