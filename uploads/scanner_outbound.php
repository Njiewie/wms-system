<?php
include 'db_config.php';

$barcode = $_POST['barcode'] ?? '';
$response = ['status' => 'fail', 'message' => 'No barcode provided.'];

if ($barcode) {
    $inv = $conn->prepare("SELECT * FROM inventory WHERE item_code = ?");
    $inv->bind_param("s", $barcode);
    $inv->execute();
    $item = $inv->get_result()->fetch_assoc();

    if ($item) {
        if ($item['qty_available'] > 0) {
            $update = $conn->prepare("UPDATE inventory SET total_qty = total_qty - 1, qty_available = qty_available - 1 WHERE item_code = ?");
            $update->bind_param("s", $barcode);
            $update->execute();
            $response = ['status' => 'success', 'message' => "✅ Outbound processed for $barcode"];
        } else {
            $response['message'] = "❌ No available stock.";
        }
    } else {
        $response['message'] = "❌ Item not in inventory.";
    }
}

header('Content-Type: application/json');
echo json_encode($response);
