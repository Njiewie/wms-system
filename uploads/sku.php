<?php
require 'auth.php';
require_login();
include 'db_config.php';

$skus = $conn->query("SELECT s.*, c.client_name FROM sku_master s LEFT JOIN clients c ON s.client_id = c.id ORDER BY s.sku_id ASC");
?>
<!DOCTYPE html>
<html>
<head>
    <title>All SKUs | ECWMS</title>
    <meta http-equiv="refresh" content="10"> <!-- Auto-refresh every 10 seconds -->
    <link rel="stylesheet" href="style.css">
    <style>
        body { font-family: Arial, sans-serif; }
        h2 { text-align: center; }
        table {
            border-collapse: collapse;
            width: 95%;
            margin: auto;
        }
        th, td {
            border: 1px solid #ccc;
            padding: 8px;
            text-align: left;
            white-space: nowrap;
        }
        th {
            background-color: #f2f2f2;
        }
    </style>
</head>
<body>
<?php include 'sticky_header.php'; ?>

<h2>📋 SKU Data</h2>
<table>
    <thead>
        <tr>
            <th>SKU ID</th>
            <th>Client</th>
            <th>Description</th>
            <th>Product Group</th>
            <th>EAN</th>
            <th>Fragile</th>
            <th>High Security</th>
            <th>Created Date</th>
            <th>Last Updated</th>
        </tr>
    </thead>
    <tbody>
        <?php if ($skus && $skus->num_rows > 0): ?>
            <?php while ($row = $skus->fetch_assoc()): ?>
                <tr>
                    <td><?= $row['sku_id'] ?></td>
                    <td><?= $row['client_name'] ?? 'Unassigned' ?></td>
                    <td><?= $row['description'] ?></td>
                    <td><?= $row['product_group'] ?></td>
                    <td><?= $row['ean'] ?></td>
                    <td><?= $row['fragile'] ? 'Yes' : 'No' ?></td>
                    <td><?= $row['high_security'] ? 'Yes' : 'No' ?></td>
                    <td><?= $row['creation_date'] ?></td>
                    <td><?= $row['last_update_date'] ?></td>
                </tr>
            <?php endwhile; ?>
        <?php else: ?>
            <tr><td colspan="9" style="text-align:center;">No SKUs found.</td></tr>
        <?php endif; ?>
    </tbody>
</table>
<div style="text-align:center; margin-top: 20px;">
    <a href="dashboard.php"><button>⬅️ Return to Dashboard</button></a>
</div>
</body>
</html>
<?php $conn->close(); ?>
