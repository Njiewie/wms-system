<?php
function log_action($username, $action) {
    include 'db_config.php';
    $stmt = $conn->prepare("INSERT INTO user_logs (username, action) VALUES (?, ?)");
    $stmt->bind_param("ss", $username, $action);
    $stmt->execute();
    $conn->close();
}
