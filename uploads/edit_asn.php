<?php
require 'auth.php';
require_login();
include 'db_config.php';

if (!isset($_GET['asn_number'])) {
    die("ASN number is required.");
}

$asn_number = $_GET['asn_number'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $supplier_name = $_POST['supplier_name'];
    $arrival_date = $_POST['arrival_date'];

    $stmt = $conn->prepare("UPDATE asn_header SET supplier_name = ?, arrival_date = ? WHERE asn_number = ?");
    $stmt->bind_param("sss", $supplier_name, $arrival_date, $asn_number);
    $stmt->execute();

    echo "<p style='color:green;'>✅ ASN updated successfully.</p>";
    echo "<a href='asn_lines.php?asn_number=" . urlencode($asn_number) . "'>⬅️ Return to ASN Details</a>";
    exit;
}

$stmt = $conn->prepare("SELECT supplier_name, arrival_date FROM asn_header WHERE asn_number = ?");
$stmt->bind_param("s", $asn_number);
$stmt->execute();
$result = $stmt->get_result();
$asn = $result->fetch_assoc();
?>

<!DOCTYPE html>
<html>
<head><title>Edit ASN</title></head>
<body>
<h2>Edit ASN: <?= htmlspecialchars($asn_number) ?></h2>
<form method="POST">
    <label>Supplier Name:</label><br>
    <input type="text" name="supplier_name" value="<?= htmlspecialchars($asn['supplier_name']) ?>" required><br><br>
    <label>Arrival Date:</label><br>
    <input type="date" name="arrival_date" value="<?= htmlspecialchars($asn['arrival_date']) ?>" required><br><br>
    <button type="submit">💾 Save Changes</button>
</form>
</body>
</html>
