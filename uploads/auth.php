<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function require_login() {
    if (!isset($_SESSION['user'])) {
        header("Location: login.php");
        exit;
    }
}

function require_admin() {
    if ($_SESSION['role'] !== 'admin') {
        echo "Access denied.";
        exit;
    }
}
