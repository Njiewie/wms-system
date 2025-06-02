<?php
require_once 'security-utils.php';
require 'auth.php';
require_login();
include 'db_config.php';

// Set security headers for API endpoint
setSecurityHeaders();
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');
header('Expires: 0');

// Rate limiting for API requests
try {
    WMSSecurity::checkRateLimit('sku_info_api_' . $_SESSION['user'], 60, 300); // 60 requests per 5 minutes
} catch (Exception $e) {
    http_response_code(429);
    echo json_encode(['error' => 'Rate limit exceeded. Please try again later.']);
    exit;
}

// Initialize response
$response = ['error' => 'No SKU provided'];

if (isset($_GET['sku'])) {
    try {
        // Validate and sanitize SKU input
        $sku_id = WMSSecurity::sanitizeString($_GET['sku'], 50);

        if (empty($sku_id)) {
            throw new InvalidArgumentException('Invalid SKU format');
        }

        // Validate SKU format (alphanumeric with some special chars)
        if (!preg_match('/^[A-Za-z0-9\-_\.]+$/', $sku_id)) {
            throw new InvalidArgumentException('SKU contains invalid characters');
        }

        // Fetch SKU information securely
        $sku_data = secure_select_one($conn,
            "SELECT s.sku_id, s.description, s.pack_config, s.client_id, s.product_group,
                    s.ean, s.fragile, s.high_security, s.each_weight, s.packed_weight,
                    c.client_name
             FROM sku_master s
             LEFT JOIN clients c ON s.client_id = c.id
             WHERE s.sku_id = ?",
            "s",
            [$sku_id]
        );

        if ($sku_data) {
            // Prepare secure response with validated data
            $response = [
                'sku_id' => secure_escape($sku_data['sku_id']),
                'description' => secure_escape($sku_data['description']),
                'pack_config' => secure_escape($sku_data['pack_config']),
                'client_id' => (int)$sku_data['client_id'],
                'client_name' => secure_escape($sku_data['client_name']),
                'product_group' => secure_escape($sku_data['product_group']),
                'ean' => secure_escape($sku_data['ean']),
                'fragile' => (bool)$sku_data['fragile'],
                'high_security' => (bool)$sku_data['high_security'],
                'each_weight' => (float)$sku_data['each_weight'],
                'packed_weight' => (float)$sku_data['packed_weight'],
                'found' => true
            ];

            // Get inventory information if requested
            if (isset($_GET['include_inventory']) && $_GET['include_inventory'] === '1') {
                $inventory_data = secure_select_one($conn,
                    "SELECT SUM(qty_on_hand) as total_on_hand,
                            SUM(qty_allocated) as total_allocated,
                            COUNT(*) as location_count
                     FROM inventory
                     WHERE sku_id = ?",
                    "s",
                    [$sku_id]
                );

                if ($inventory_data) {
                    $response['inventory'] = [
                        'total_on_hand' => (int)($inventory_data['total_on_hand'] ?? 0),
                        'total_allocated' => (int)($inventory_data['total_allocated'] ?? 0),
                        'total_available' => (int)(($inventory_data['total_on_hand'] ?? 0) - ($inventory_data['total_allocated'] ?? 0)),
                        'location_count' => (int)($inventory_data['location_count'] ?? 0)
                    ];
                }
            }

            // Log successful API access
            WMSSecurity::logActivity($conn, $_SESSION['user'], 'sku_info_accessed',
                "SKU: $sku_id, Include Inventory: " . (isset($_GET['include_inventory']) ? 'Yes' : 'No'));

        } else {
            $response = [
                'error' => 'SKU not found in master data',
                'sku_id' => secure_escape($sku_id),
                'found' => false
            ];
        }

    } catch (InvalidArgumentException $e) {
        http_response_code(400);
        $response = ['error' => $e->getMessage()];

    } catch (Exception $e) {
        error_log("SKU info fetch error: " . $e->getMessage());
        http_response_code(500);
        $response = ['error' => 'Server error occurred while fetching SKU information'];
    }

} else {
    http_response_code(400);
    $response = ['error' => 'SKU parameter is required'];
}

// Add API metadata
$response['timestamp'] = date('c');
$response['api_version'] = '1.0';

// Output JSON response
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

// Close database connection
$conn->close();
?>
