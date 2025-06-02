<?php
require 'db_config.php';

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="sku_master_export.csv"');
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen("php://output", "w");

// Fetch data
$result = $conn->query("SELECT * FROM sku_master ORDER BY sku_id ASC");

// Write header row
if ($result && $result->num_rows > 0) {
    fputcsv($output, array_keys($result->fetch_assoc()));
    $result->data_seek(0); // Reset pointer

    while ($row = $result->fetch_assoc()) {
        fputcsv($output, $row);
    }
}

fclose($output);
exit;
?>
