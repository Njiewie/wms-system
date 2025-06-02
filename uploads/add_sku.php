<?php
require 'auth.php';
require_login();
include 'db_config.php';

$message = "";

// Fetch clients for dropdown
$clients = [];
$result = $conn->query("SELECT id, client_name FROM clients ORDER BY client_name ASC");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $clients[$row['id']] = $row['client_name'];
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Dimensions and weights
    $each_height = $_POST['each_height'] ?? 0;
    $packed_height = $_POST['packed_height'] ?? 0;
    $each_width = $_POST['each_width'] ?? 0;
    $packed_width = $_POST['packed_width'] ?? 0;
    $each_depth = $_POST['each_depth'] ?? 0;
    $packed_depth = $_POST['packed_depth'] ?? 0;
    $each_volume = $_POST['each_volume'] ?? 0;
    $each_weight = $_POST['each_weight'] ?? 0;
    $packed_weight = $_POST['packed_weight'] ?? 0;

    // Other fields
    $sku_id = trim($_POST['sku_id']);
    $client_id = $_POST['client_id'];
    $description = trim($_POST['description']);
    $product_group = trim($_POST['product_group']);
    $ean = trim($_POST['ean']);
    $fragile = isset($_POST['fragile']) ? 'Y' : 'N';
    $high_security = isset($_POST['high_security']) ? 'Y' : 'N';
    $created_by = 'admin';
    $creation_date = date('Y-m-d');
    $create_time = date('H:i:s');

    if (empty($sku_id) || empty($description)) {
        $message = "❌ SKU ID and Description are required.";
    } else {
        $stmt = $conn->prepare("INSERT INTO sku_master (
            sku_id, client_id, description, product_group, ean,
            created_by, creation_date, create_time, fragile, high_security,
            each_height, packed_height, each_width, packed_width,
            each_depth, packed_depth, each_volume, each_weight, packed_weight
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE 
            description=VALUES(description),
            product_group=VALUES(product_group),
            ean=VALUES(ean),
            fragile=VALUES(fragile),
            high_security=VALUES(high_security),
            each_height=VALUES(each_height), packed_height=VALUES(packed_height),
            each_width=VALUES(each_width), packed_width=VALUES(packed_width),
            each_depth=VALUES(each_depth), packed_depth=VALUES(packed_depth),
            each_volume=VALUES(each_volume), each_weight=VALUES(each_weight),
            packed_weight=VALUES(packed_weight)");

        if (!$stmt) {
            die("❌ SQL Prepare Error: " . $conn->error);
        }

        $stmt->bind_param("sissssssssddddddddd",
            $sku_id, $client_id, $description, $product_group, $ean,
            $created_by, $creation_date, $create_time, $fragile, $high_security,
            $each_height, $packed_height, $each_width, $packed_width,
            $each_depth, $packed_depth, $each_volume, $each_weight, $packed_weight
        );

        if ($stmt->execute()) {
            $message = "✅ SKU added or updated successfully.";
        } else {
            $message = "❌ Insert failed: " . $stmt->error;
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Add SKU | ECWMS</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f5f5f5;
            padding: 20px;
        }
        h2 {
            text-align: center;
        }
        form {
            max-width: 800px;
            margin: 0 auto;
            background-color: #fff;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.1);
        }
        label {
            display: block;
            font-weight: bold;
            margin-top: 10px;
        }
        input[type="text"], input[type="number"], select {
            width: 100%;
            padding: 8px;
            margin-top: 4px;
            border: 1px solid #ccc;
            border-radius: 4px;
        }
        .checkbox-group {
            display: flex;
            gap: 20px;
            margin-top: 10px;
        }
        .form-row {
            display: flex;
            gap: 20px;
        }
        .form-col {
            flex: 1;
        }
        button[type="submit"] {
            margin-top: 20px;
            padding: 10px 20px;
            background-color: #007bff;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }
        button[type="submit"]:hover {
            background-color: #0056b3;
        }
        a {
            display: block;
            text-align: center;
            margin-top: 20px;
            color: #007bff;
            text-decoration: none;
        }
    </style>
</head>
<body>
<h2>Add SKU to SKU Master</h2>

<?php if ($message): ?>
    <p style="color:green;"> <?= $message ?> </p>
<?php endif; ?>

<form method="POST">
    <div class="form-row">
        <div class="form-col">
            <label>SKU ID:</label>
            <input type="text" name="sku_id" required>
        </div>
        <div class="form-col">
            <label>Client:</label>
            <select name="client_id" required>
                <option value="">-- Select Client --</option>
                <?php foreach ($clients as $id => $name): ?>
                    <option value="<?= $id ?>"><?= htmlspecialchars($name) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <label>Description:</label>
    <input type="text" name="description" required>

    <label>Product Group:</label>
    <input type="text" name="product_group">

    <label>EAN:</label>
    <input type="text" name="ean">

    <div class="checkbox-group">
        <label><input type="checkbox" name="fragile"> Fragile</label>
        <label><input type="checkbox" name="high_security"> High Security</label>
    </div>

    <div class="form-row">
        <div class="form-col">
            <label>Each Height:</label>
            <input type="number" step="any" name="each_height">
        </div>
        <div class="form-col">
            <label>Packed Height:</label>
            <input type="number" step="any" name="packed_height">
        </div>
    </div>

    <div class="form-row">
        <div class="form-col">
            <label>Each Width:</label>
            <input type="number" step="any" name="each_width">
        </div>
        <div class="form-col">
            <label>Packed Width:</label>
            <input type="number" step="any" name="packed_width">
        </div>
    </div>

    <div class="form-row">
        <div class="form-col">
            <label>Each Depth:</label>
            <input type="number" step="any" name="each_depth">
        </div>
        <div class="form-col">
            <label>Packed Depth:</label>
            <input type="number" step="any" name="packed_depth">
        </div>
    </div>

    <div class="form-row">
        <div class="form-col">
            <label>Each Volume:</label>
            <input type="number" step="any" name="each_volume">
        </div>
        <div class="form-col">
            <label>Each Weight:</label>
            <input type="number" step="any" name="each_weight">
        </div>
    </div>

    <label>Packed Weight:</label>
    <input type="number" step="any" name="packed_weight">

    <button type="submit">➕ Add SKU</button>
</form>

<a href="manage_sku_master.php">⬅ Back to SKU Master</a>
</body>
</html>

<?php $conn->close(); ?>
