<?php
/**
 * Secure SKU Information Fetcher
 * Enhanced with comprehensive security measures
 *
 * Security Features:
 * - CSRF Protection
 * - SQL Injection Prevention
 * - Input Validation & Sanitization
 * - XSS Prevention
 * - Rate Limiting
 * - JSON Response Security
 */

// Start session and include security utilities
session_start();
require_once 'security-utils.php';
require_once 'auth.php';
require_once 'db_config.php';

// Require login and set security headers
require_login();
$security = SecurityUtils::getInstance($conn);
$security->setSecurityHeaders();

// Set JSON content type
header('Content-Type: application/json');

// Initialize response structure
$response = [
    'success' => false,
    'data' => null,
    'error' => null
];

try {
    // Check request method
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception('Invalid request method');
    }

    // Rate limiting
    if (!$security->checkRateLimit($_SESSION['user_id'], 'sku_info_fetch', 50, 300)) {
        throw new Exception('Too many requests. Please wait before fetching more SKU information.');
    }

    // Validate and sanitize SKU parameter
    if (!isset($_GET['sku']) || empty($_GET['sku'])) {
        throw new Exception('SKU parameter is required');
    }

    $sku = $security->sanitizeInput($_GET['sku'], 30);

    if (!preg_match('/^[A-Za-z0-9\-_]+$/', $sku)) {
        throw new Exception('Invalid SKU format');
    }

    // Fetch SKU information from database
    $sku_info = secure_select_one($conn,
        "SELECT sku_id, description, pack_config, category, unit_price, reorder_level, supplier
         FROM sku_master
         WHERE sku_id = ? AND deleted_at IS NULL",
        "s",
        [$sku]
    );

    if ($sku_info) {
        // SKU found - return information
        $response['success'] = true;
        $response['data'] = [
            'sku_id' => htmlspecialchars($sku_info['sku_id']),
            'description' => htmlspecialchars($sku_info['description'] ?? ''),
            'pack_config' => htmlspecialchars($sku_info['pack_config'] ?? ''),
            'category' => htmlspecialchars($sku_info['category'] ?? ''),
            'unit_price' => number_format((float)($sku_info['unit_price'] ?? 0), 2),
            'reorder_level' => (int)($sku_info['reorder_level'] ?? 0),
            'supplier' => htmlspecialchars($sku_info['supplier'] ?? '')
        ];

        // Log successful fetch
        $security->logActivity($_SESSION['user_id'], 'SKU_INFO_FETCHED', "SKU: $sku");
    } else {
        // SKU not found - return empty data
        $response['success'] = true;
        $response['data'] = [
            'sku_id' => htmlspecialchars($sku),
            'description' => '',
            'pack_config' => '',
            'category' => '',
            'unit_price' => '0.00',
            'reorder_level' => 0,
            'supplier' => ''
        ];

        // Log SKU not found (for inventory management purposes)
        $security->logActivity($_SESSION['user_id'], 'SKU_NOT_FOUND', "SKU: $sku");
    }

} catch (Exception $e) {
    $response['success'] = false;
    $response['error'] = htmlspecialchars($e->getMessage());

    // Log security events
    if (strpos($e->getMessage(), 'Too many requests') !== false ||
        strpos($e->getMessage(), 'Invalid') !== false) {

        $security->logSecurityEvent($_SESSION['user_id'], 'sku_fetch_security_violation', $e->getMessage());
    }

    error_log("SKU Info Fetch Error: " . $e->getMessage() . " | User: " . $_SESSION['user_id'] . " | IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
}

// Output JSON response
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

// Clean up and close connections
if (isset($conn)) {
    $conn->close();
}

// Clean sensitive variables
unset($response, $sku_info);
?>
