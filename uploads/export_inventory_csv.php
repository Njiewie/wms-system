<?php
require 'db_config.php';

header('Content-Type: text/csv');
header('Content-Disposition: attachment;filename=inventory_export.csv');

$output = fopen('php://output', 'w');

// Column headers
fputcsv($output, [
    'tag_id', 'client_id', 'sku_id', 'site_id', 'location_id',
    'description', 'qty_on_hand', 'qty_allocated', 'batch_id', 'condition',
    'lock_status', 'zone', 'pallet_config', 'receipt_id', 'line_id',
    'receipt_dstamp', 'receipt_time', 'move_dstamp', 'move_time',
    'count_dstamp', 'expiry_date', 'pallet_id', 'container_id', 'last_updated'
]);

$where = [];
$params = [];
$types = "";

$fields = [
    'tag_id', 'client_id', 'sku_id', 'site_id', 'location_id', 'description',
    'qty_on_hand', 'qty_allocated', 'batch_id', 'condition', 'lock_status',
    'zone', 'pallet_config', 'receipt_id', 'line_id', 'receipt_dstamp',
    'receipt_time', 'move_dstamp', 'move_time', 'count_dstamp',
    'expiry_date', 'pallet_id', 'container_id', 'last_updated'
];

foreach ($fields as $field) {
    if (!empty($_GET[$field])) {
        $where[] = "$field LIKE ?";
        $params[] = "%" . $_GET[$field] . "%";
        $types .= "s";
    }
}

$sql = "SELECT * FROM inventory";
if (!empty($where)) {
    $sql .= " WHERE " . implode(" AND ", $where);
}
$sql .= " ORDER BY sku_id ASC";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    fputcsv($output, [
        $row['tag_id'] ?? '',
        $row['client_id'] ?? '',
        $row['sku_id'] ?? '',
        $row['site_id'] ?? '',
        $row['location_id'] ?? '',
        $row['description'] ?? '',
        $row['qty_on_hand'] ?? '',
        $row['qty_allocated'] ?? '',
        $row['batch_id'] ?? '',
        $row['condition'] ?? '',
        $row['lock_status'] ?? '',
        $row['zone'] ?? '',
        $row['pallet_config'] ?? '',
        $row['receipt_id'] ?? '',
        $row['line_id'] ?? '',
        $row['receipt_dstamp'] ?? '',
        $row['receipt_time'] ?? '',
        $row['move_dstamp'] ?? '',
        $row['move_time'] ?? '',
        $row['count_dstamp'] ?? '',
        $row['expiry_date'] ?? '',
        $row['pallet_id'] ?? '',
        $row['container_id'] ?? '',
        $row['last_updated'] ?? ''
    ]);
}

fclose($output);
exit;
?>