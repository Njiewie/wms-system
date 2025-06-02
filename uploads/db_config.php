<?php
$host = "localhost";
$user = "root"; // your MySQL username
$pass = "Ecn@76581232";     // your MySQL password
$db   = "wms_db"; // your MySQL database name

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>
