
<?php
require 'auth.php';
require_login();
include 'db_config.php';

// Fetch ASN list for selection
$asn_list = $conn->query("SELECT asn_number FROM asn_header WHERE status != 'Completed'");

// Process inbound
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['asn_number'])) {
    $asn_number = $_POST['asn_number'];

    // Fetch ASN lines
    $stmt = $conn->prepare("SELECT * FROM asn_lines WHERE asn_number = ?");
    $stmt->bind_param("s", $asn_number);
    $stmt->execute();
    $lines = $stmt->get_result();

    // Process each line: insert or update inventory
    while ($line = $lines->fetch_assoc()) {
        $check = $conn->prepare("SELECT * FROM inventory WHERE sku_id = ? AND tag_id = ?");
        $check->bind_param("ss", $line['sku_id'], $line['tag_id']);
        $check->execute();
        $result = $check->get_result();

        if ($result->num_rows > 0) {
            $update = $conn->prepare("UPDATE inventory SET qty_on_hand = qty_on_hand + ?, last_updated = NOW() WHERE sku_id = ? AND tag_id = ?");
            $update->bind_param("iss", $line['qty'], $line['sku_id'], $line['tag_id']);
            $update->execute();
        } else {
            $insert = $conn->prepare("INSERT INTO inventory (tag_id, sku_id, qty_on_hand, location_id, last_updated) VALUES (?, ?, ?, 'RCV01', NOW())");
            $insert->bind_param("ssi", $line['tag_id'], $line['sku_id'], $line['qty']);
            $insert->execute();
        }
    }

    // Update ASN header status
    $conn->query("UPDATE asn_headers SET status = 'Completed' WHERE asn_number = '" . $conn->real_escape_string($asn_number) . "'");
    echo "<p style='color: green;'>✅ ASN $asn_number processed successfully.</p>";
}
?>

<h2>📦 Process ASN Inbound</h2>
<form method="POST" onsubmit="return confirm('Are you sure you want to process this ASN?');">
    <label>Select ASN Number:</label>
    <select name="asn_number" required>
        <option value="">-- Select ASN --</option>
        <?php while ($asn = $asn_list->fetch_assoc()): ?>
            <option value="<?= htmlspecialchars($asn['asn_number']) ?>"><?= htmlspecialchars($asn['asn_number']) ?></option>
        <?php endwhile; ?>
    </select>
    <button type="submit">🚚 Process Inbound</button>
</form>
