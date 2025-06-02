<?php
require_once 'security-utils.php';
require 'auth.php';
require_login();
include 'db_config.php';

// Set security headers
setSecurityHeaders();

if (!isset($_GET['item_code'])) {
    echo "❌ No item selected. <a href='manage_sku_master.php'>Back</a>";
    exit;
}

try {
    $item_code = WMSSecurity::sanitizeString($_GET['item_code'], 50);
    if (empty($item_code)) {
        throw new InvalidArgumentException('Invalid item code');
    }
} catch (Exception $e) {
    handleSecurityError('Invalid item code parameter');
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Validate CSRF token
        validate_csrf();

        // Validate and sanitize input data
        $description = WMSSecurity::sanitizeString($_POST['description'], 255);
        $pack_config = WMSSecurity::sanitizeString($_POST['pack_config'], 100);
        $ean = WMSSecurity::sanitizeString($_POST['ean'], 50);
        $serial_number = WMSSecurity::sanitizeString($_POST['serial_number'], 100);
        $origin = WMSSecurity::sanitizeString($_POST['origin'], 100);
        $dimension = WMSSecurity::sanitizeString($_POST['dimension'], 100);
        $unit_weight = WMSSecurity::validateFloat($_POST['unit_weight'], 0);
        $client_id = WMSSecurity::validateInteger($_POST['client_id'], 1);

        // Update record securely
        $updated_rows = secure_update($conn, 'sku_master',
            [
                'description' => $description,
                'pack_config' => $pack_config,
                'ean' => $ean,
                'serial_number' => $serial_number,
                'origin' => $origin,
                'dimension' => $dimension,
                'unit_weight' => $unit_weight,
                'client' => $client_id,
                'last_updated' => date('Y-m-d H:i:s')
            ],
            'item_code = ?',
            's',
            [$item_code]
        );

        if ($updated_rows > 0) {
            // Log the update activity
            WMSSecurity::logActivity($conn, $_SESSION['user'], 'sku_updated',
                "Item Code: $item_code");

            echo "<div style='text-align:center; color:green; font-weight:bold; margin-top:20px;'>
                    ✅ SKU updated successfully.
                    <br><br><a href='manage_sku_master.php' style='color:#007bff;'>⬅️ Return to SKU Master</a>
                  </div>";
        } else {
            echo "❌ No changes were made or item not found.";
        }

    } catch (Exception $e) {
        error_log("SKU update error: " . $e->getMessage());
        echo "❌ Update failed: " . secure_escape($e->getMessage());
    }
    exit;
}

// Fetch item data securely
try {
    $item = secure_select_one($conn,
        "SELECT * FROM sku_master WHERE item_code = ?",
        "s",
        [$item_code]
    );

    if (!$item) {
        echo "❌ Item not found. <a href='manage_sku_master.php'>Back</a>";
        exit;
    }

    // Fetch clients for dropdown
    $clients = secure_select_all($conn,
        "SELECT id, client_name FROM clients ORDER BY client_name ASC"
    );

} catch (Exception $e) {
    error_log("Data fetch error: " . $e->getMessage());
    echo "❌ Error loading data. <a href='manage_sku_master.php'>Back</a>";
    exit;
}
?>

<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>Edit SKU | ECWMS</title>
  <link rel="stylesheet" href="modern-style.css">
  <style>
    .edit-form {
      max-width: 600px;
      margin: 2rem auto;
      background: white;
      padding: 2rem;
      border-radius: var(--border-radius-lg);
      box-shadow: var(--box-shadow);
    }
    .form-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 1rem;
    }
    .form-group.full-width {
      grid-column: span 2;
    }
  </style>
</head>
<body class="wms-layout">

<main class="wms-content">
  <div class="edit-form">
    <h2 style="text-align:center;">✏️ Edit SKU</h2>

    <form method="POST" id="editSKUForm">
      <?= csrf_field() ?>

      <div class="form-group full-width">
        <label class="form-label">Item Code</label>
        <input type="text" class="form-control" value="<?= secure_escape($item['item_code']) ?>" disabled>
      </div>

      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Description *</label>
          <input type="text" name="description" class="form-control"
                 value="<?= secure_escape($item['description']) ?>"
                 required maxlength="255">
        </div>

        <div class="form-group">
          <label class="form-label">Pack Config</label>
          <input type="text" name="pack_config" class="form-control"
                 value="<?= secure_escape($item['pack_config']) ?>"
                 maxlength="100">
        </div>

        <div class="form-group">
          <label class="form-label">EAN</label>
          <input type="text" name="ean" class="form-control"
                 value="<?= secure_escape($item['ean']) ?>"
                 maxlength="50">
        </div>

        <div class="form-group">
          <label class="form-label">Serial Number</label>
          <input type="text" name="serial_number" class="form-control"
                 value="<?= secure_escape($item['serial_number']) ?>"
                 maxlength="100">
        </div>

        <div class="form-group">
          <label class="form-label">Origin</label>
          <input type="text" name="origin" class="form-control"
                 value="<?= secure_escape($item['origin']) ?>"
                 maxlength="100">
        </div>

        <div class="form-group">
          <label class="form-label">Dimension</label>
          <input type="text" name="dimension" class="form-control"
                 value="<?= secure_escape($item['dimension']) ?>"
                 maxlength="100">
        </div>

        <div class="form-group">
          <label class="form-label">Unit Weight</label>
          <input type="number" step="0.01" name="unit_weight" class="form-control"
                 value="<?= secure_escape($item['unit_weight']) ?>"
                 min="0">
        </div>

        <div class="form-group">
          <label class="form-label">Client *</label>
          <select name="client_id" class="form-control form-select" required>
            <option value="">-- Select Client --</option>
            <?php foreach($clients as $client): ?>
              <option value="<?= $client['id'] ?>"
                      <?= ($item['client'] == $client['id']) ? 'selected' : '' ?>>
                <?= secure_escape($client['client_name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div style="text-align: center; margin-top: 2rem;">
        <button type="submit" class="btn btn-primary">💾 Save Changes</button>
        <a href="manage_sku_master.php" class="btn btn-secondary">⬅️ Cancel</a>
      </div>
    </form>
  </div>
</main>

<script>
// Form validation
document.getElementById('editSKUForm').addEventListener('submit', function(e) {
    const description = this.querySelector('[name="description"]').value.trim();
    const clientId = this.querySelector('[name="client_id"]').value;

    if (!description) {
        alert('Description is required');
        e.preventDefault();
        return;
    }

    if (!clientId) {
        alert('Please select a client');
        e.preventDefault();
        return;
    }

    // Add loading state
    const submitBtn = this.querySelector('button[type="submit"]');
    submitBtn.disabled = true;
    submitBtn.textContent = 'Saving...';
});
</script>

</body>
</html>

<?php $conn->close(); ?>
