<?php
include 'auth.php';
require_login();
include 'db_config.php';

$filters = [
    'sku_id', 'client_id', 'description', 'product_group', 'ean', 'allocation_group', 'putaway_group',
    'tag_merge', 'created_by', 'last_updated_by', 'fragile', 'high_security',
    'each_height', 'packed_height', 'each_width', 'packed_width', 'each_depth', 'packed_depth',
    'each_volume', 'each_weight', 'packed_weight'
];
$where = [];
foreach ($filters as $field) {
    if (!empty($_GET[$field])) {
        $safe = $conn->real_escape_string($_GET[$field]);
        $where[] = "$field LIKE '%$safe%'";
    }
}
$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$page_size = isset($_GET['page_size']) ? (int)$_GET['page_size'] : 25;
$offset = ($page - 1) * $page_size;

// Fetch filtered SKUs
$sql = "SELECT s.*, c.client_name FROM sku_master s 
        LEFT JOIN clients c ON s.client_id = c.id 
        $where_sql 
        ORDER BY s.sku_id ASC 
        LIMIT $page_size OFFSET $offset";

$result = $conn->query($sql);

if ($result && $result->num_rows > 0) {
    while($row = $result->fetch_assoc()) {
        echo "<tr>
            <td>{$row['sku_id']}</td>
            <td>" . ($row['client_name'] ?? 'Unassigned') . "</td>
            <td>{$row['description']}</td>
            <td>{$row['product_group']}</td>
            <td>{$row['ean']}</td>
            <td>{$row['allocation_group']}</td>
            <td>{$row['putaway_group']}</td>
            <td>{$row['tag_merge']}</td>
            <td>{$row['created_by']}</td>
            <td>{$row['creation_date']}</td>
            <td>{$row['create_time']}</td>
            <td>{$row['last_updated_by']}</td>
            <td>{$row['last_update_date']}</td>
            <td>{$row['last_update_time']}</td>
            <td>{$row['fragile']}</td>
            <td>{$row['high_security']}</td>
            <td>{$row['each_height']}</td>
            <td>{$row['packed_height']}</td>
            <td>{$row['each_width']}</td>
            <td>{$row['packed_width']}</td>
            <td>{$row['each_depth']}</td>
            <td>{$row['packed_depth']}</td>
            <td>{$row['each_volume']}</td>
            <td>{$row['each_weight']}</td>
            <td>{$row['packed_weight']}</td>
            <td>
                <a href='edit_sku.php?sku_id={$row['sku_id']}'>✏️ Edit</a>
            </td>
        </tr>";
    }
} else {
    echo "<tr><td colspan='26' style='text-align:center;'>No SKU records found.</td></tr>";
}
$conn->close();
?>
