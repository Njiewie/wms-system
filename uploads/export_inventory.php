<?php
require 'auth.php';
require_login();
include 'db_config.php';

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="inventory.csv"');

$output = fopen("php://output", "w");
fputcsv($output, ['ID', 'Item Name', 'Quantity', 'Location', 'Last Updated']);

$result = $conn->query("SELECT * FROM inventory");
while ($row = $result->fetch_assoc()) {
    fputcsv($output, $row);
}
fclose($output);
$conn->close();
exit;
