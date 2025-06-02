<?php
require 'auth.php';
require_login();
include 'db_config.php';

header('Content-Type: application/json');

if (isset($_GET['sku'])) {
    $sku_id = trim($conn->real_escape_string($_GET['sku']));

    $sql = "SELECT s.description, s.pack_config, s.client_id, c.client_name 
            FROM sku_master s
            LEFT JOIN clients c ON s.client_id = c.id
            WHERE s.sku_id = '$sku_id'";

    $result = $conn->query($sql);

    if (!$result) {
        echo json_encode(["error" => $conn->error]);
        exit;
    }

    if ($row = $result->fetch_assoc()) {
        echo json_encode([
            "description" => $row['description'],
            "pack_config" => $row['pack_config'],
            "client_id" => $row['client_id'],
            "client_name" => $row['client_name']
        ]);
    } else {
        echo json_encode(["error" => "No data found for this SKU"]);
    }
} else {
    echo json_encode(["error" => "No SKU provided."]);
}
?>