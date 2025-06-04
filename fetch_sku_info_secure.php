<?php
/**
 * Secure SKU Information Viewer
 * Displays comprehensive SKU details, inventory status, and movement history
 */

require_once 'security-utils.php';
require_once 'db_config.php';

$security = SecurityUtils::getInstance();
$db = getDB();

// Check rate limiting and session
if (!$security->checkRateLimit()) {
    http_response_code(429);
    $security->logActivity('RATE_LIMIT_EXCEEDED', ['page' => 'fetch_sku_info'], 'WARNING');
    die('Rate limit exceeded. Please try again later.');
}

if (!$security->validateSession('operator')) {
    $security->logActivity('UNAUTHORIZED_ACCESS_ATTEMPT', ['page' => 'fetch_sku_info'], 'WARNING');
    header('Location: auth.php');
    exit();
}

$csrfToken = $security->generateCSRFToken();
$sku = $security->sanitizeInput($_GET['sku'] ?? '');

if (empty($sku)) {
    $security->logActivity('FETCH_SKU_INFO_INVALID_SKU', ['provided_sku' => $_GET['sku'] ?? 'none'], 'WARNING');
    header('Location: secure-inventory.php');
    exit();
}

// Handle AJAX requests for dynamic data
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    if (!$security->validateCSRFToken($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid CSRF token']);
        exit();
    }
    
    $action = $security->sanitizeInput($_POST['action']);
    
    switch ($action) {
        case 'get_movements':
            try {
                $page = max(1, (int) ($_POST['page'] ?? 1));
                $movements = getInventoryMovements($db, $sku, $page);
                echo json_encode(['success' => true, 'data' => $movements]);
            } catch (Exception $e) {
                echo json_encode(['error' => 'Failed to load movements']);
            }
            break;
            
        case 'get_forecast':
            try {
                $forecast = getInventoryForecast($db, $sku);
                echo json_encode(['success' => true, 'data' => $forecast]);
            } catch (Exception $e) {
                echo json_encode(['error' => 'Failed to generate forecast']);
            }
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
    }
    exit();
}

// Get SKU data
try {
    $skuData = getSKUData($db, $sku);
    if (!$skuData) {
        $security->logActivity('FETCH_SKU_INFO_NOT_FOUND', ['sku' => $sku], 'WARNING');
        header('Location: secure-inventory.php?error=sku_not_found');
        exit();
    }
    
    $inventoryItems = getInventoryItems($db, $sku);
    $recentMovements = getInventoryMovements($db, $sku, 1, 10);
    $analytics = getSKUAnalytics($db, $sku);
    $relatedOrders = getRelatedOrders($db, $sku);
    
} catch (Exception $e) {
    $security->logActivity('FETCH_SKU_INFO_ERROR', ['sku' => $sku, 'error' => $e->getMessage()], 'ERROR');
    header('Location: secure-inventory.php?error=fetch_failed');
    exit();
}

$security->logActivity('SKU_INFO_ACCESSED', ['sku' => $sku]);

/**
 * Get comprehensive SKU data
 */
function getSKUData($db, $sku) {
    return $db->fetchRow(
        "SELECT sm.*, 
                COALESCE(SUM(i.quantity), 0) as total_quantity,
                COUNT(DISTINCT i.location) as location_count,
                COUNT(DISTINCT i.client) as client_count,
                (COALESCE(SUM(i.quantity), 0) * COALESCE(sm.unit_cost, 0)) as total_value
         FROM sku_master sm
         LEFT JOIN inventory i ON sm.sku = i.sku AND i.deleted_at IS NULL
         WHERE sm.sku = ? AND sm.deleted_at IS NULL
         GROUP BY sm.sku",
        [$sku]
    );
}

/**
 * Get inventory items by location
 */
function getInventoryItems($db, $sku) {
    return $db->fetchAll(
        "SELECT i.*, 
                CASE 
                    WHEN i.quantity = 0 THEN 'zero'
                    WHEN i.quantity <= COALESCE(sm.min_stock_level, 10) THEN 'low'
                    ELSE 'normal'
                END as stock_status
         FROM inventory i
         LEFT JOIN sku_master sm ON i.sku = sm.sku
         WHERE i.sku = ? AND i.deleted_at IS NULL
         ORDER BY i.location, i.client",
        [$sku]
    );
}

/**
 * Get inventory movements with pagination
 */
function getInventoryMovements($db, $sku, $page = 1, $limit = 20) {
    $offset = ($page - 1) * $limit;
    
    $movements = $db->fetchAll(
        "SELECT im.*, u.username as created_by_name
         FROM inventory_movements im
         LEFT JOIN users u ON im.created_by = u.id
         WHERE im.sku = ?
         ORDER BY im.created_at DESC
         LIMIT {$limit} OFFSET {$offset}",
        [$sku]
    );
    
    $total = $db->fetchValue(
        "SELECT COUNT(*) FROM inventory_movements WHERE sku = ?",
        [$sku]
    ) ?: 0;
    
    return [
        'movements' => $movements,
        'pagination' => [
            'current_page' => $page,
            'total_pages' => ceil($total / $limit),
            'total' => $total,
            'per_page' => $limit
        ]
    ];
}

/**
 * Get SKU analytics
 */
function getSKUAnalytics($db, $sku) {
    $analytics = [
        'avg_monthly_movement' => 0,
        'last_inbound' => null,
        'last_outbound' => null,
        'velocity_trend' => 'stable',
        'turnover_rate' => 0,
        'days_of_supply' => 0
    ];
    
    try {
        // Average monthly movement
        $monthlyMovement = $db->fetchValue(
            "SELECT AVG(monthly_total) FROM (
                SELECT SUM(ABS(quantity)) as monthly_total
                FROM inventory_movements 
                WHERE sku = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
                GROUP BY YEAR(created_at), MONTH(created_at)
            ) as monthly_data",
            [$sku]
        );
        
        $analytics['avg_monthly_movement'] = round($monthlyMovement ?: 0, 2);
        
        // Last movements
        $analytics['last_inbound'] = $db->fetchRow(
            "SELECT created_at, quantity, reference_number 
             FROM inventory_movements 
             WHERE sku = ? AND movement_type = 'in' 
             ORDER BY created_at DESC LIMIT 1",
            [$sku]
        );
        
        $analytics['last_outbound'] = $db->fetchRow(
            "SELECT created_at, quantity, reference_number 
             FROM inventory_movements 
             WHERE sku = ? AND movement_type = 'out' 
             ORDER BY created_at DESC LIMIT 1",
            [$sku]
        );
        
        // Calculate turnover rate and days of supply
        $currentStock = $db->fetchValue(
            "SELECT COALESCE(SUM(quantity), 0) FROM inventory WHERE sku = ? AND deleted_at IS NULL",
            [$sku]
        ) ?: 0;
        
        $avgDailyUsage = $db->fetchValue(
            "SELECT AVG(daily_usage) FROM (
                SELECT ABS(SUM(quantity)) as daily_usage
                FROM inventory_movements 
                WHERE sku = ? AND movement_type = 'out' 
                AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                GROUP BY DATE(created_at)
            ) as daily_data",
            [$sku]
        ) ?: 0;
        
        if ($avgDailyUsage > 0) {
            $analytics['days_of_supply'] = round($currentStock / $avgDailyUsage, 1);
            $analytics['turnover_rate'] = round(($avgDailyUsage * 365) / max($currentStock, 1), 2);
        }
        
    } catch (Exception $e) {
        error_log("SKU analytics error: " . $e->getMessage());
    }
    
    return $analytics;
}

/**
 * Get related orders
 */
function getRelatedOrders($db, $sku) {
    $orders = [
        'pending_outbound' => [],
        'pending_inbound' => []
    ];
    
    try {
        // Pending outbound orders
        $orders['pending_outbound'] = $db->fetchAll(
            "SELECT o.order_number, o.customer, o.status, ol.quantity, o.expected_ship_date
             FROM outbound_orders o
             JOIN outbound_order_lines ol ON o.id = ol.order_id
             WHERE ol.sku = ? AND o.status IN ('pending', 'allocated', 'picking')
             AND o.deleted_at IS NULL AND ol.deleted_at IS NULL
             ORDER BY o.expected_ship_date ASC",
            [$sku]
        );
        
        // Pending inbound ASNs
        $orders['pending_inbound'] = $db->fetchAll(
            "SELECT a.asn_number, a.supplier, a.status, al.quantity, a.expected_date
             FROM asn a
             JOIN asn_lines al ON a.id = al.asn_id
             WHERE al.sku = ? AND a.status IN ('created', 'in_progress')
             AND a.deleted_at IS NULL AND al.deleted_at IS NULL
             ORDER BY a.expected_date ASC",
            [$sku]
        );
        
    } catch (Exception $e) {
        error_log("Related orders error: " . $e->getMessage());
    }
    
    return $orders;
}

/**
 * Get inventory forecast
 */
function getInventoryForecast($db, $sku) {
    $forecast = [];
    $currentStock = 0;
    
    try {
        $currentStock = $db->fetchValue(
            "SELECT COALESCE(SUM(quantity), 0) FROM inventory WHERE sku = ? AND deleted_at IS NULL",
            [$sku]
        ) ?: 0;
        
        // Get average daily usage for last 30 days
        $avgDailyUsage = $db->fetchValue(
            "SELECT AVG(daily_usage) FROM (
                SELECT ABS(SUM(quantity)) as daily_usage
                FROM inventory_movements 
                WHERE sku = ? AND movement_type = 'out' 
                AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                GROUP BY DATE(created_at)
            ) as daily_data",
            [$sku]
        ) ?: 0;
        
        // Project forward 30 days
        for ($i = 1; $i <= 30; $i++) {
            $projectedStock = max(0, $currentStock - ($avgDailyUsage * $i));
            $forecast[] = [
                'date' => date('Y-m-d', strtotime("+{$i} days")),
                'projected_stock' => round($projectedStock, 0),
                'status' => $projectedStock <= 0 ? 'stockout' : ($projectedStock <= 10 ? 'low' : 'normal')
            ];
        }
        
    } catch (Exception $e) {
        error_log("Forecast error: " . $e->getMessage());
    }
    
    return $forecast;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SKU Information: <?= $security->escapeOutput($sku) ?> - Secure WMS</title>
    <meta name="robots" content="noindex, nofollow">
    <meta http-equiv="X-Content-Type-Options" content="nosniff">
    <meta http-equiv="X-Frame-Options" content="SAMEORIGIN">
    <meta http-equiv="X-XSS-Protection" content="1; mode=block">
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <style>
        :root {
            --primary-color: #2c3e50;
            --secondary-color: #34495e;
            --success-color: #27ae60;
            --info-color: #3498db;
            --warning-color: #f39c12;
            --danger-color: #e74c3c;
        }
        
        body {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .navbar {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            box-shadow: 0 2px 15px rgba(0,0,0,0.1);
        }
        
        .card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
            transition: all 0.3s ease;
        }
        
        .card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.12);
        }
        
        .sku-header {
            background: linear-gradient(135deg, var(--primary-color), var(--info-color));
            color: white;
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 30px;
        }
        
        .metric-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            text-align: center;
            transition: all 0.3s ease;
        }
        
        .metric-card:hover {
            transform: translateY(-3px);
        }
        
        .metric-value {
            font-size: 2rem;
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .metric-label {
            color: #6c757d;
            font-size: 0.9rem;
        }
        
        .stock-status {
            padding: 5px 15px;
            border-radius: 20px;
            font-weight: 500;
            font-size: 0.9rem;
        }
        
        .stock-normal { background: #d4edda; color: #155724; }
        .stock-low { background: #fff3cd; color: #856404; }
        .stock-zero { background: #f8d7da; color: #721c24; }
        
        .location-item {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 10px;
            border-left: 4px solid var(--primary-color);
        }
        
        .movement-item {
            background: white;
            border-radius: 8px;
            padding: 12px;
            margin-bottom: 8px;
            border-left: 3px solid #dee2e6;
            transition: all 0.3s ease;
        }
        
        .movement-item:hover {
            border-left-color: var(--primary-color);
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .movement-in { border-left-color: var(--success-color); }
        .movement-out { border-left-color: var(--danger-color); }
        .movement-adjustment { border-left-color: var(--warning-color); }
        
        .chart-container {
            background: white;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .tabs-container {
            background: white;
            border-radius: 15px;
            overflow: hidden;
        }
        
        .nav-tabs {
            border-bottom: none;
            background: #f8f9fa;
        }
        
        .nav-tabs .nav-link {
            border: none;
            border-radius: 0;
            color: var(--primary-color);
        }
        
        .nav-tabs .nav-link.active {
            background: white;
            color: var(--primary-color);
            font-weight: 600;
        }
        
        .forecast-item {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 10px;
            margin-bottom: 5px;
        }
        
        .forecast-stockout { border-left: 4px solid var(--danger-color); }
        .forecast-low { border-left: 4px solid var(--warning-color); }
        .forecast-normal { border-left: 4px solid var(--success-color); }
        
        .order-item {
            background: white;
            border-radius: 8px;
            padding: 12px;
            margin-bottom: 8px;
            border: 1px solid #dee2e6;
        }
        
        .analytics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container-fluid">
            <a class="navbar-brand" href="secure-inventory.php">
                <i class="fas fa-barcode"></i> SKU Information
            </a>
            
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="secure-inventory.php"><i class="fas fa-arrow-left"></i> Back to Inventory</a>
                <a class="nav-link" href="secure-dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
            </div>
        </div>
    </nav>

    <div class="container-fluid mt-4">
        <!-- SKU Header -->
        <div class="sku-header">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h1 class="mb-2"><?= $security->escapeOutput($sku) ?></h1>
                    <p class="mb-0 h5 opacity-75"><?= $security->escapeOutput($skuData['description'] ?? 'No description available') ?></p>
                    <?php if ($skuData['category']): ?>
                        <span class="badge bg-light text-dark mt-2"><?= $security->escapeOutput($skuData['category']) ?></span>
                    <?php endif; ?>
                </div>
                <div class="col-md-4 text-end">
                    <div class="btn-group" role="group">
                        <?php if (!empty($inventoryItems)): ?>
                            <a href="edit_sku_secure.php?id=<?= $inventoryItems[0]['id'] ?>" class="btn btn-light">
                                <i class="fas fa-edit"></i> Edit
                            </a>
                        <?php endif; ?>
                        <button class="btn btn-light" onclick="refreshData()">
                            <i class="fas fa-sync-alt"></i> Refresh
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Analytics Grid -->
        <div class="analytics-grid">
            <div class="metric-card">
                <div class="metric-value text-primary"><?= number_format($skuData['total_quantity']) ?></div>
                <div class="metric-label">Total Quantity</div>
            </div>
            
            <div class="metric-card">
                <div class="metric-value text-success">$<?= number_format($skuData['total_value'], 2) ?></div>
                <div class="metric-label">Total Value</div>
            </div>
            
            <div class="metric-card">
                <div class="metric-value text-info"><?= $skuData['location_count'] ?></div>
                <div class="metric-label">Locations</div>
            </div>
            
            <div class="metric-card">
                <div class="metric-value text-warning"><?= number_format($analytics['avg_monthly_movement']) ?></div>
                <div class="metric-label">Avg Monthly Movement</div>
            </div>
            
            <div class="metric-card">
                <div class="metric-value text-secondary"><?= $analytics['days_of_supply'] ?></div>
                <div class="metric-label">Days of Supply</div>
            </div>
            
            <div class="metric-card">
                <div class="metric-value text-dark"><?= $analytics['turnover_rate'] ?></div>
                <div class="metric-label">Turnover Rate</div>
            </div>
        </div>

        <div class="row">
            <div class="col-lg-8">
                <!-- Tabbed Content -->
                <div class="tabs-container">
                    <ul class="nav nav-tabs" id="skuTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="locations-tab" data-bs-toggle="tab" data-bs-target="#locations" type="button">
                                <i class="fas fa-map-marker-alt"></i> Locations
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="movements-tab" data-bs-toggle="tab" data-bs-target="#movements" type="button">
                                <i class="fas fa-exchange-alt"></i> Movements
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="forecast-tab" data-bs-toggle="tab" data-bs-target="#forecast" type="button">
                                <i class="fas fa-chart-line"></i> Forecast
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="orders-tab" data-bs-toggle="tab" data-bs-target="#orders" type="button">
                                <i class="fas fa-receipt"></i> Related Orders
                            </button>
                        </li>
                    </ul>
                    
                    <div class="tab-content p-3" id="skuTabsContent">
                        <!-- Locations Tab -->
                        <div class="tab-pane fade show active" id="locations" role="tabpanel">
                            <?php if (empty($inventoryItems)): ?>
                                <div class="text-center py-4">
                                    <i class="fas fa-box-open fa-3x text-muted mb-3"></i>
                                    <p class="text-muted">No inventory found for this SKU</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($inventoryItems as $item): ?>
                                    <div class="location-item">
                                        <div class="row align-items-center">
                                            <div class="col-md-4">
                                                <h6 class="mb-1"><?= $security->escapeOutput($item['location'] ?: 'No Location') ?></h6>
                                                <small class="text-muted"><?= $security->escapeOutput($item['client'] ?: 'No Client') ?></small>
                                            </div>
                                            <div class="col-md-3 text-center">
                                                <span class="h5 mb-0"><?= number_format($item['quantity']) ?></span>
                                                <br><small class="text-muted">Quantity</small>
                                            </div>
                                            <div class="col-md-3 text-center">
                                                <span class="stock-status stock-<?= $item['stock_status'] ?>">
                                                    <?= ucfirst($item['stock_status']) ?>
                                                </span>
                                            </div>
                                            <div class="col-md-2 text-end">
                                                <a href="edit_sku_secure.php?id=<?= $item['id'] ?>" class="btn btn-sm btn-outline-primary">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Movements Tab -->
                        <div class="tab-pane fade" id="movements" role="tabpanel">
                            <div id="movementsContainer">
                                <?php if (empty($recentMovements['movements'])): ?>
                                    <div class="text-center py-4">
                                        <i class="fas fa-exchange-alt fa-3x text-muted mb-3"></i>
                                        <p class="text-muted">No movements found for this SKU</p>
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($recentMovements['movements'] as $movement): ?>
                                        <div class="movement-item movement-<?= $movement['movement_type'] ?>">
                                            <div class="row align-items-center">
                                                <div class="col-md-2">
                                                    <span class="badge bg-<?= $movement['movement_type'] === 'in' ? 'success' : 'danger' ?>">
                                                        <?= strtoupper($movement['movement_type']) ?>
                                                    </span>
                                                </div>
                                                <div class="col-md-2 text-center">
                                                    <strong><?= number_format(abs($movement['quantity'])) ?></strong>
                                                </div>
                                                <div class="col-md-3">
                                                    <small><?= $security->escapeOutput($movement['reference_number'] ?? 'No Reference') ?></small>
                                                </div>
                                                <div class="col-md-3">
                                                    <small><?= $security->escapeOutput($movement['created_by_name'] ?? 'System') ?></small>
                                                </div>
                                                <div class="col-md-2 text-end">
                                                    <small><?= date('M j H:i', strtotime($movement['created_at'])) ?></small>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                    
                                    <?php if ($recentMovements['pagination']['total'] > 10): ?>
                                        <div class="text-center mt-3">
                                            <button class="btn btn-outline-primary" onclick="loadMoreMovements()">
                                                Load More Movements
                                            </button>
                                        </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Forecast Tab -->
                        <div class="tab-pane fade" id="forecast" role="tabpanel">
                            <div id="forecastContainer">
                                <div class="text-center py-4">
                                    <button class="btn btn-primary" onclick="generateForecast()">
                                        <i class="fas fa-chart-line"></i> Generate 30-Day Forecast
                                    </button>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Orders Tab -->
                        <div class="tab-pane fade" id="orders" role="tabpanel">
                            <div class="row">
                                <div class="col-md-6">
                                    <h6><i class="fas fa-truck-loading text-success"></i> Pending Inbound</h6>
                                    <?php if (empty($relatedOrders['pending_inbound'])): ?>
                                        <p class="text-muted">No pending inbound orders</p>
                                    <?php else: ?>
                                        <?php foreach ($relatedOrders['pending_inbound'] as $order): ?>
                                            <div class="order-item">
                                                <div class="d-flex justify-content-between">
                                                    <div>
                                                        <strong><?= $security->escapeOutput($order['asn_number']) ?></strong>
                                                        <br><small><?= $security->escapeOutput($order['supplier']) ?></small>
                                                    </div>
                                                    <div class="text-end">
                                                        <span class="badge bg-info"><?= number_format($order['quantity']) ?></span>
                                                        <br><small><?= date('M j', strtotime($order['expected_date'])) ?></small>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="col-md-6">
                                    <h6><i class="fas fa-shipping-fast text-warning"></i> Pending Outbound</h6>
                                    <?php if (empty($relatedOrders['pending_outbound'])): ?>
                                        <p class="text-muted">No pending outbound orders</p>
                                    <?php else: ?>
                                        <?php foreach ($relatedOrders['pending_outbound'] as $order): ?>
                                            <div class="order-item">
                                                <div class="d-flex justify-content-between">
                                                    <div>
                                                        <strong><?= $security->escapeOutput($order['order_number']) ?></strong>
                                                        <br><small><?= $security->escapeOutput($order['customer']) ?></small>
                                                    </div>
                                                    <div class="text-end">
                                                        <span class="badge bg-warning"><?= number_format($order['quantity']) ?></span>
                                                        <br><small><?= date('M j', strtotime($order['expected_ship_date'])) ?></small>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <!-- SKU Details -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h6 class="mb-0"><i class="fas fa-info-circle"></i> SKU Details</h6>
                    </div>
                    <div class="card-body">
                        <div class="row mb-2">
                            <div class="col-6"><strong>Unit Cost:</strong></div>
                            <div class="col-6 text-end">$<?= number_format($skuData['unit_cost'] ?? 0, 2) ?></div>
                        </div>
                        <div class="row mb-2">
                            <div class="col-6"><strong>Unit Price:</strong></div>
                            <div class="col-6 text-end">$<?= number_format($skuData['unit_price'] ?? 0, 2) ?></div>
                        </div>
                        <div class="row mb-2">
                            <div class="col-6"><strong>Min Stock:</strong></div>
                            <div class="col-6 text-end"><?= number_format($skuData['min_stock_level'] ?? 0) ?></div>
                        </div>
                        <div class="row mb-2">
                            <div class="col-6"><strong>Max Stock:</strong></div>
                            <div class="col-6 text-end"><?= number_format($skuData['max_stock_level'] ?? 0) ?></div>
                        </div>
                        <div class="row mb-2">
                            <div class="col-6"><strong>Reorder Point:</strong></div>
                            <div class="col-6 text-end"><?= number_format($skuData['reorder_point'] ?? 0) ?></div>
                        </div>
                        <hr>
                        <div class="row mb-2">
                            <div class="col-6"><strong>Created:</strong></div>
                            <div class="col-6 text-end"><small><?= date('M j, Y', strtotime($skuData['created_at'])) ?></small></div>
                        </div>
                        <div class="row">
                            <div class="col-6"><strong>Updated:</strong></div>
                            <div class="col-6 text-end"><small><?= date('M j, Y', strtotime($skuData['updated_at'])) ?></small></div>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h6 class="mb-0"><i class="fas fa-tools"></i> Quick Actions</h6>
                    </div>
                    <div class="card-body">
                        <div class="d-grid gap-2">
                            <?php if (!empty($inventoryItems)): ?>
                                <a href="edit_sku_secure.php?id=<?= $inventoryItems[0]['id'] ?>" class="btn btn-primary">
                                    <i class="fas fa-edit"></i> Edit SKU
                                </a>
                            <?php endif; ?>
                            <button class="btn btn-outline-info" onclick="exportSKUData()">
                                <i class="fas fa-download"></i> Export Data
                            </button>
                            <button class="btn btn-outline-warning" onclick="requestCycleCount()">
                                <i class="fas fa-clipboard-check"></i> Cycle Count
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Movement Summary -->
                <div class="card">
                    <div class="card-header">
                        <h6 class="mb-0"><i class="fas fa-chart-bar"></i> Recent Activity</h6>
                    </div>
                    <div class="card-body">
                        <?php if ($analytics['last_inbound']): ?>
                            <div class="mb-3">
                                <small class="text-muted">Last Inbound:</small>
                                <br><strong><?= number_format($analytics['last_inbound']['quantity']) ?> units</strong>
                                <br><small><?= date('M j, Y', strtotime($analytics['last_inbound']['created_at'])) ?></small>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($analytics['last_outbound']): ?>
                            <div class="mb-3">
                                <small class="text-muted">Last Outbound:</small>
                                <br><strong><?= number_format(abs($analytics['last_outbound']['quantity'])) ?> units</strong>
                                <br><small><?= date('M j, Y', strtotime($analytics['last_outbound']['created_at'])) ?></small>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!$analytics['last_inbound'] && !$analytics['last_outbound']): ?>
                            <p class="text-muted mb-0">No recent activity</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const csrfToken = '<?= $csrfToken ?>';
        const sku = '<?= $security->escapeOutput($sku) ?>';
        
        function refreshData() {
            location.reload();
        }
        
        function loadMoreMovements() {
            // Implementation would load additional movements via AJAX
            console.log('Loading more movements...');
        }
        
        function generateForecast() {
            const container = document.getElementById('forecastContainer');
            container.innerHTML = '<div class="text-center py-4"><i class="fas fa-spinner fa-spin fa-2x"></i><br>Generating forecast...</div>';
            
            fetch(window.location.href, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=get_forecast&csrf_token=${csrfToken}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    displayForecast(data.data);
                } else {
                    container.innerHTML = '<div class="alert alert-danger">Failed to generate forecast</div>';
                }
            })
            .catch(error => {
                console.error('Error:', error);
                container.innerHTML = '<div class="alert alert-danger">Error generating forecast</div>';
            });
        }
        
        function displayForecast(forecast) {
            const container = document.getElementById('forecastContainer');
            
            if (forecast.length === 0) {
                container.innerHTML = '<div class="text-center py-4 text-muted">No forecast data available</div>';
                return;
            }
            
            let html = '<h6 class="mb-3">30-Day Stock Projection</h6>';
            
            // Show first 10 days
            for (let i = 0; i < Math.min(forecast.length, 10); i++) {
                const item = forecast[i];
                html += `
                    <div class="forecast-item forecast-${item.status}">
                        <div class="d-flex justify-content-between">
                            <span>${new Date(item.date).toLocaleDateString('en-US', {month: 'short', day: 'numeric'})}</span>
                            <span><strong>${item.projected_stock}</strong> units</span>
                        </div>
                    </div>
                `;
            }
            
            if (forecast.length > 10) {
                html += `<div class="text-center mt-3"><small class="text-muted">Showing first 10 days of 30-day forecast</small></div>`;
            }
            
            container.innerHTML = html;
        }
        
        function exportSKUData() {
            const data = {
                sku: sku,
                total_quantity: <?= $skuData['total_quantity'] ?>,
                total_value: <?= $skuData['total_value'] ?>,
                locations: <?= json_encode($inventoryItems) ?>
            };
            
            const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `sku_${sku}_data.json`;
            a.click();
            window.URL.revokeObjectURL(url);
        }
        
        function requestCycleCount() {
            if (confirm(`Request cycle count for SKU ${sku}?`)) {
                // Implementation would make AJAX call to request cycle count
                alert('Cycle count requested successfully');
            }
        }
        
        // Auto-refresh every 5 minutes
        setInterval(refreshData, 300000);
    </script>
</body>
</html>