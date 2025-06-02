<?php
// Free Hosting Database Configuration
// Update these values with your 000webhost database credentials

$host = "localhost";  // Usually localhost for 000webhost
$username = "id21234567_wmsuser";  // Your 000webhost database username
$password = "YourSecurePassword123!";  // Your 000webhost database password
$database = "id21234567_wmsdb";  // Your 000webhost database name

// Create connection
try {
    $conn = new mysqli($host, $username, $password, $database);

    // Check connection
    if ($conn->connect_error) {
        error_log("Database connection failed: " . $conn->connect_error);
        die("Connection failed. Please check your database configuration.");
    }

    // Set charset to UTF8 for proper character handling
    $conn->set_charset("utf8");

} catch (Exception $e) {
    error_log("Database error: " . $e->getMessage());
    die("Database connection error. Please try again later.");
}

// Optional: Add CORS headers for frontend integration
if (isset($_SERVER['HTTP_ORIGIN'])) {
    // Allow your Vercel frontend domain
    $allowed_origins = [
        'https://your-wms-frontend.vercel.app',
        'https://localhost:3000',  // For local development
        'http://localhost:3000'
    ];

    if (in_array($_SERVER['HTTP_ORIGIN'], $allowed_origins)) {
        header("Access-Control-Allow-Origin: " . $_SERVER['HTTP_ORIGIN']);
    }
}

header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Access-Control-Allow-Credentials: true");

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Session configuration for free hosting
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_secure', 1); // Ensure HTTPS

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>
