<?php
require 'auth.php';
require_login();
include 'db_config.php';

$message = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $tag_id = $_POST['tag_id'] ?? null;
    $client_id = $_POST['client_id'] ?? null;
    $sku_id = $_POST['sku_id'] ?? null;
    $site_id = $_POST['site_id'] ?? null;
    $location_id = $_POST['location_id'] ?? null;
    $description = $_POST['description'] ?? '';
    $qty_on_hand = intval($_POST['qty_on_hand'] ?? 0);
    $batch_id = $_POST['batch_id'] ?? null;
    $condition_id = $_POST['condition_id'] ?? null;
    $lock_status = $_POST['lock_status'] ?? '';
    $zone = $_POST['zone'] ?? '';
    $pallet_config = $_POST['pallet_config'] ?? '';
    $receipt_id = $_POST['receipt_id'] ?? null;
    $line_id = $_POST['line_id'] ?? null;
    $expiry_date = $_POST['expiry_date'] ?? null;
if ($expiry_date === '') {
    $expiry_date = null;
}


    $stmt = $conn->prepare("INSERT INTO inventory (
    tag_id, client_id, sku_id, site_id, location_id, description,
    qty_on_hand, qty_allocated, batch_id, `condition`,
    lock_status, zone, pallet_config, receipt_id, line_id,
    expiry_date, receipt_dstamp, receipt_time, move_dstamp, move_time,
    count_dstamp, pallet_id, container_id, last_updated
) VALUES (
    ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), NULL, NULL, NULL, NULL, NULL, NOW()
)");


    if ($stmt) {
        $stmt->bind_param("iiisssiiissssis",
            $tag_id, $client_id, $sku_id, $site_id, $location_id, $description,
            $qty_on_hand, $batch_id, $condition_id,
            $lock_status, $zone, $pallet_config, $receipt_id, $line_id, $expiry_date
        );

        if ($stmt->execute()) {
            $message = "✅ Item successfully added.";
        } else {
            $message = "❌ Error adding item: " . $stmt->error;
        }
    } else {
        $message = "❌ Prepare failed: " . $conn->error;
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Add Inventory | ECWMS</title>
    <link rel="stylesheet" href="style.css">





<style>
  body {
    font-family: Arial, sans-serif;
    background: #f4f6f8;
    margin: 0;
    padding: 20px;
  }
  .form-wrapper {
    max-width: 1000px;
    margin: auto;
    background: white;
    padding: 30px 40px;
    border-radius: 12px;
    box-shadow: 0 0 10px rgba(0,0,0,0.05);
  }
  .form-wrapper h2 {
    text-align: center;
    margin-bottom: 20px;
  }
  .grid {
    display: grid;
    grid-template-columns: auto 1fr auto 1fr auto 1fr;
    gap: 12px 20px;
    align-items: center;
  }
  .grid label {
    text-align: right;
    font-weight: bold;
    white-space: nowrap;
  }
  .grid input,
  .grid select {
    width: 100%;
    min-width: 200px;
    max-width: 260px;
    padding: 10px;
    border: 1px solid #ccc;
    border-radius: 6px;
    font-size: 15px;
    box-sizing: border-box;
  }
  .form-actions {
    display: flex;
    justify-content: center;
    gap: 40px;
    margin-top: 32px;
  }
  .form-actions button {
    background: #007bff;
    color: white;
    padding: 10px 26px;
    border: none;
    border-radius: 6px;
    font-size: 16px;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 8px;
    white-space: nowrap;
  }
  
</style>






</style>

</head>
<body>
<h2 style="text-align:center;">➕ Add New Inventory</h2>

<?php if ($message) echo "<p style='text-align:center;color:red;'>$message</p>"; ?>

<div class="form-wrapper">
<div id="skuAlert" class="alert warning"></div>
<div class="form-wrapper"><form method="POST" style="max-width:600px;margin:auto;">
<div class="grid">
<div class="grid">
    <label>SKU ID:</label>
    <input type="text" name="sku_id" id="sku_id" required><br><br>
  
    <label>Tag ID:</label>
    <input type="text" name="tag_id" id="Tag_id" required><br><br>
  
    <label>Description:</label>
    <input type="text" name="description" id="description" readonly><br><br>

    <label>Pack Config:</label>
    <input type="text" name="pallet_config" id="pallet_config" readonly><br><br>

    <label>Client ID:</label>
    <input type="number" name="client_id" id="client_id"><br><br>

    <label>Qty On Hand:</label>
    <input type="number" name="qty_on_hand"><br><br>

    <label>Location ID:</label>
    <input type="text" name="location_id"><br><br>

    <label>Site ID:</label>
    <input type="text" name="site_id"><br><br>

    <label>Batch ID:</label>
    <input type="text" name="batch_id"><br><br>

    <label>Condition:</label>
	<input type="text" name="condition"><br><br>

    <label>Lock Status:</label>
    <input type="text" name="lock_status"><br><br>

    <label>Zone:</label>
    <input type="text" name="zone"><br><br>

    <label>Receipt ID:</label>
    <input type="text" name="receipt_id"><br><br>

    <label>Line ID:</label>
<input type="text" name="line_id"><br><br>

    <label>Expiry Date:</label>
<input type="date" name="expiry_date"><br><br>

    
</div>

</div>

</div><div class="form-actions"><button type="submit">💾 Add Item</button><button type="submit">💾 Save</button></div></form></div>
</div>



<div style="text-align:center; margin-top:20px;">
    <a href="view_inventory.php">⬅️ Back to Inventory</a>
</div>

<script>
document.addEventListener("DOMContentLoaded", function () {
  const skuField = document.querySelector("[name='sku_id']");
  const descField = document.querySelector("[name='description']");
  const packConfigField = document.querySelector("[name='pallet_config']");
  const clientField = document.querySelector("[name='client_id']");

  function loadSkuData(sku) {
    if (!sku) return;

    fetch("fetch_sku_info.php?sku=" + encodeURIComponent(sku))
      .then(res => res.json())
      .then(data => {
        if (!data.description && !data.pack_config) {
          document.getElementById("skuAlert").innerText = "⚠️ No data found for this SKU.";
document.getElementById("skuAlert").style.display = "block";
        }

        if (descField) descField.value = data.description || "";
        if (packConfigField) packConfigField.value = data.pack_config || "";
        if (clientField && data.client_id) {
          clientField.value = data.client_id;
        }
      })
      .catch(() => {
        alert("❌ Failed to load SKU data.");
      });
  }

  if (skuField) {
    loadSkuData(skuField.value); // Auto-load on page load

    skuField.addEventListener("change", function () {
      loadSkuData(this.value);
    });

    skuField.addEventListener("blur", function () {
      loadSkuData(this.value); // Also support typing and leaving input
    });
  }
});
</script>


<script>
document.addEventListener("DOMContentLoaded", function () {
  const skuField = document.querySelector("[name='sku_id']");
  const descField = document.querySelector("[name='description']");
  const packConfigField = document.querySelector("[name='pallet_config']");
  const clientField = document.querySelector("[name='client_id']");

  function loadSkuData(sku) {
    if (!sku) return;

    fetch("fetch_sku_info.php?sku=" + encodeURIComponent(sku))
      .then(res => res.json())
      .then(data => {
        if (!data.description && !data.pack_config) {
          document.getElementById("skuAlert").innerText = "⚠️ No data found for this SKU.";
document.getElementById("skuAlert").style.display = "block";
        }

        if (descField) descField.value = data.description || "";
        if (packConfigField) packConfigField.value = data.pack_config || "";
        if (clientField && data.client_id) {
          clientField.value = data.client_id;
        }
      })
      .catch(() => {
        alert("❌ Failed to load SKU data.");
      });
  }

  if (skuField) {
    loadSkuData(skuField.value); // Auto-load on page load

    skuField.addEventListener("change", function () {
      loadSkuData(this.value);
    });

    skuField.addEventListener("blur", function () {
      loadSkuData(this.value); // Also support typing and leaving input
    });
  }
});
</script>

</body>
</html>

<?php $conn->close(); ?>
