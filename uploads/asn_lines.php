<?php
require 'auth.php';
require_login();
include 'db_config.php';

if (!isset($_GET['asn_number'])) {
    echo "ASN number is required.";
    exit;
}

$asn_number = $_GET['asn_number'];

$stmt = $conn->prepare("SELECT * FROM asn_lines WHERE asn_number = ?");
$stmt->bind_param("s", $asn_number);
$stmt->execute();
$result = $stmt->get_result();
?>

<!DOCTYPE html>
<html>
<head>
    <title>ASN Details | ECWMS</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
        }
        h2 {
            text-align: center;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        th, td {
            border: 1px solid #ccc;
            padding: 8px;
            text-align: center;
        }
        th {
            background-color: #f4f4f4;
        }
        .btn {
            padding: 6px 12px;
            margin: 10px 0;
            text-decoration: none;
            border-radius: 5px;
            background-color: #007BFF;
            color: white;
            display: inline-block;
        }
        .btn:hover {
            background-color: #0056b3;
        }
    </style>
</head>
<body>

<h2>📦 ASN Details for <?= htmlspecialchars($asn_number) ?></h2>

<a href="inbound.php" class="btn">⬅️ Back to Inbound Dashboard</a>
<a href="edit_asn.php?asn_number=<?= urlencode($asn_number) ?>" class="btn">✏️ Edit ASN</a>
<a href="delete_asn.php?asn_number=<?= urlencode($asn_number) ?>" class="btn" onclick="return confirm('Are you sure you want to delete this ASN and its lines?')">🗑️ Delete ASN</a>

<table>
    <thead>
        <tr>
            <th>SKU ID</th>
            <th>Description</th>
            <th>Expected Qty</th>
            <th>Received Qty</th>
            <th>Batch ID</th>
            <th>Expiry Date</th>
        </tr>
    </thead>
    <tbody>
        <?php while ($line = $result->fetch_assoc()): ?>
        <tr>
            <td><?= htmlspecialchars($line['sku_id']) ?></td>
            <td><?= htmlspecialchars($line['description']) ?></td>
            <td><?= htmlspecialchars($line['qty_expected']) ?></td>
            <td><?= htmlspecialchars($line['qty_received']) ?></td>
            <td><?= htmlspecialchars($line['batch_id']) ?></td>
            <td><?= htmlspecialchars($line['expiry_date']) ?></td>
        </tr>
        <?php endwhile; ?>
    </tbody>
</table>

</body>
</html>
