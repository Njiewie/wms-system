<?php
require 'auth.php';
require_login();
include 'db_config.php';

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="movements.csv"');

$output = fopen("php://output", "w");
fputcsv($output, ['ID', 'Item Name', 'Type', 'Qty', 'Notes', 'Moved At']);

$sql = "SELECT sm.*, i.item_name 
        FROM stock_movements sm 
        JOIN inventory i ON sm.item_id = i.id 
        ORDER BY sm.moved_at DESC";

$result = $conn->query($sql);
while ($row = $result->fetch_assoc()) {
    fputcsv($output, [$row['id'], $row['item_name'], $row['movement_type'], $row['quantity'], $row['notes'], $row['moved_at']]);
}
fclose($output);
$conn->close();
exit;
