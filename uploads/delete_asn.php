<?php
require 'auth.php';
require_login();
include 'db_config.php';

if (!isset($_GET['asn_number'])) {
    die("ASN number is required.");
}

$asn_number = $_GET['asn_number'];

// Delete lines first to maintain FK integrity
$conn->prepare("DELETE FROM asn_lines WHERE asn_number = ?")->bind_param("s", $asn_number)->execute();

// Then delete header
$conn->prepare("DELETE FROM asn_header WHERE asn_number = ?")->bind_param("s", $asn_number)->execute();

echo "<p style='color:red;'>🗑️ ASN and all related lines deleted.</p>";
echo "<a href='inbound.php'>⬅️ Return to Inbound Dashboard</a>";
?>
