<?php
require 'auth.php';
require_login();
include 'db_config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $asn_number = $_POST['asn_number'];
    $supplier_name = $_POST['supplier_name'];
    $arrival_date = $_POST['arrival_date'];

    // Insert into ASN header
    $stmt = $conn->prepare("INSERT INTO asn_header (asn_number, supplier_name, arrival_date, status) VALUES (?, ?, ?, 'Hold')");
    if (!$stmt) {
        die("Header Prepare failed: " . $conn->error);
    }
    $stmt->bind_param("sss", $asn_number, $supplier_name, $arrival_date);
    $stmt->execute();

    // Insert ASN line items
    if (!empty($_POST['sku_id'])) {
        for ($i = 0; $i < count($_POST['sku_id']); $i++) {
            $sku_id = $_POST['sku_id'][$i];
            $description = $_POST['description'][$i];
            $qty_expected = $_POST['qty'][$i];
            $qty_received = 0;
            $batch_id = $_POST['batch_id'][$i];
            $expiry_date_raw = trim($_POST['expiry_date'][$i]);
            $expiry_date = !empty($expiry_date_raw) ? $expiry_date_raw : null;

            $query = "INSERT INTO asn_lines (
                asn_number, sku_id, description, qty_expected, qty_received, batch_id, expiry_date
            ) VALUES (?, ?, ?, ?, ?, ?, ?)";

            $stmt = $conn->prepare($query);
            if (!$stmt) {
                die("Line Prepare failed: " . $conn->error);
            }

            // Handle nullable expiry_date binding
            if ($expiry_date === null) {
                $stmt->bind_param("sssiiis", $asn_number, $sku_id, $description, $qty_expected, $qty_received, $batch_id, $expiry_date);
            } else {
                $stmt->bind_param("sssiiis", $asn_number, $sku_id, $description, $qty_expected, $qty_received, $batch_id, $expiry_date);
            }

            $stmt->execute();
        }
    }

    echo "<p style='color: green;'>✅ ASN created successfully.</p>";
    echo "<a href='inbound.php'>⬅️ Return to Inbound Dashboard</a>";
}
?>
