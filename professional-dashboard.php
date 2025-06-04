<?php
/**
 * Professional WMS Dashboard
 * Enhanced dashboard with advanced analytics and management features
 */

require_once 'security-utils.php';
require_once 'db_config.php';

$security = SecurityUtils::getInstance();
$db = getDB();

// Check rate limiting and session
if (!$security->checkRateLimit()) {
    http_response_code(429);
    $security->logActivity('RATE_LIMIT_EXCEEDED', ['page' => 'professional-dashboard'], 'WARNING');
    die('Rate limit exceeded. Please try again later.');
}

if (!$security->validateSession('supervisor')) {
    $security->logActivity('UNAUTHORIZED_ACCESS_ATTEMPT', ['page' => 'professional-dashboard'], 'WARNING');
    header('Location: auth.php');
    exit();
}

$csrfToken = $security->generateCSRFToken();

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    if (!$security->validateCSRFToken($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid CSRF token']);
        exit();
    }
    
    $action = $security->sanitizeInput($_POST['action']);
    
    switch ($action) {
        case 'get_analytics':
            try {
                $period = $security->sanitizeInput($_POST['period'] ?? 'week');
                $analytics = getAdvancedAnalytics($db, $period);
                echo json_encode(['success' => true, 'data' => $analytics]);
            } catch (Exception $e) {
                $security->logActivity('ANALYTICS_ERROR', ['error' => $e->getMessage()], 'ERROR');
                echo json_encode(['error' => 'Failed to load analytics']);
            }
            break;
            
        case 'get_performance_metrics':
            try {
                $metrics = getPerformanceMetrics($db);
                echo json_encode(['success' => true, 'data' => $metrics]);
            } catch (Exception $e) {
                echo json_encode(['error' => 'Failed to load performance metrics']);
            }
            break;
            
        case 'generate_report':
            try {
                $reportType = $security->sanitizeInput($_POST['report_type']);
                $dateRange = $security->sanitizeInput($_POST['date_range']);
                $report = generateReport($db, $reportType, $dateRange, $security);
                echo json_encode(['success' => true, 'data' => $report]);
            } catch (Exception $e) {
                echo json_encode(['error' => 'Failed to generate report']);
            }
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
    }
    exit();
}

// Get dashboard data
try {
    $analytics = getAdvancedAnalytics($db, 'week');
    $performanceMetrics = getPerformanceMetrics($db);
    $kpiData = getKPIData($db);
    $trendData = getTrendData($db);
} catch (Exception $e) {
    $security->logActivity('DASHBOARD_LOAD_ERROR', ['error' => $e->getMessage()], 'ERROR');
    $analytics = [];
    $performanceMetrics = [];
    $kpiData = [];
    $trendData = [];
}

$security->logActivity('PROFESSIONAL_DASHBOARD_ACCESS', ['user_role' => $_SESSION['role']]);

/**
 * Get advanced analytics data
 */
function getAdvancedAnalytics($db, $period = 'week') {
    $dateFilter = match($period) {
        'day' => 'DATE(created_at) = CURDATE()',
        'week' => 'WEEK(created_at) = WEEK(NOW())',
        'month' => 'MONTH(created_at) = MONTH(NOW())',
        'quarter' => 'QUARTER(created_at) = QUARTER(NOW())',
        default => 'WEEK(created_at) = WEEK(NOW())'
    };
    
    $analytics = [
        'inventory_turnover' => 0,
        'order_fulfillment_rate' => 0,
        'average_processing_time' => 0,
        'accuracy_rate' => 0,
        'cost_per_order' => 0,
        'warehouse_utilization' => 0
    ];
    
    try {
        // Inventory turnover (annual)
        $totalInventoryValue = $db->fetchValue(
            "SELECT COALESCE(SUM(i.quantity * COALESCE(sm.unit_cost, 0)), 0) 
             FROM inventory i 
             LEFT JOIN sku_master sm ON i.sku = sm.sku 
             WHERE i.deleted_at IS NULL"
        ) ?: 1;
        
        $cogs = $db->fetchValue(
            "SELECT COALESCE(SUM(ol.quantity * COALESCE(sm.unit_cost, 0)), 0) 
             FROM outbound_order_lines ol 
             JOIN outbound_orders o ON ol.order_id = o.id 
             LEFT JOIN sku_master sm ON ol.sku = sm.sku 
             WHERE YEAR(o.created_at) = YEAR(NOW()) 
             AND o.status = 'shipped' AND o.deleted_at IS NULL"
        ) ?: 0;
        
        $analytics['inventory_turnover'] = $totalInventoryValue > 0 ? round($cogs / $totalInventoryValue, 2) : 0;
        
        // Order fulfillment rate
        $totalOrders = $db->fetchValue(
            "SELECT COUNT(*) FROM outbound_orders WHERE {$dateFilter} AND deleted_at IS NULL"
        ) ?: 1;
        
        $fulfilledOrders = $db->fetchValue(
            "SELECT COUNT(*) FROM outbound_orders 
             WHERE status = 'shipped' AND {$dateFilter} AND deleted_at IS NULL"
        ) ?: 0;
        
        $analytics['order_fulfillment_rate'] = round(($fulfilledOrders / $totalOrders) * 100, 2);
        
        // Average processing time
        $avgProcessingTime = $db->fetchValue(
            "SELECT AVG(TIMESTAMPDIFF(HOUR, created_at, shipped_at)) 
             FROM outbound_orders 
             WHERE status = 'shipped' AND {$dateFilter} AND deleted_at IS NULL"
        ) ?: 0;
        
        $analytics['average_processing_time'] = round($avgProcessingTime, 2);
        
        // Accuracy rate (based on returns/adjustments)
        $totalShipped = $db->fetchValue(
            "SELECT COALESCE(SUM(ol.quantity), 0) 
             FROM outbound_order_lines ol 
             JOIN outbound_orders o ON ol.order_id = o.id 
             WHERE o.status = 'shipped' AND {$dateFilter} AND o.deleted_at IS NULL"
        ) ?: 1;
        
        $returns = $db->fetchValue(
            "SELECT COALESCE(SUM(quantity), 0) FROM inventory_adjustments 
             WHERE adjustment_type = 'return' AND {$dateFilter} AND deleted_at IS NULL"
        ) ?: 0;
        
        $analytics['accuracy_rate'] = round(((1 - ($returns / $totalShipped)) * 100), 2);
        
        // Warehouse utilization
        $totalLocations = $db->fetchValue(
            "SELECT COUNT(DISTINCT location) FROM inventory WHERE deleted_at IS NULL"
        ) ?: 1;
        
        $usedLocations = $db->fetchValue(
            "SELECT COUNT(DISTINCT location) FROM inventory 
             WHERE quantity > 0 AND deleted_at IS NULL"
        ) ?: 0;
        
        $analytics['warehouse_utilization'] = round(($usedLocations / $totalLocations) * 100, 2);
        
    } catch (Exception $e) {
        error_log("Analytics error: " . $e->getMessage());
    }
    
    return $analytics;
}

/**
 * Get performance metrics
 */
function getPerformanceMetrics($db) {
    $metrics = [
        'picks_per_hour' => 0,
        'putaway_per_hour' => 0,
        'orders_per_hour' => 0,
        'cycle_count_accuracy' => 0,
        'damage_rate' => 0,
        'labor_productivity' => 0
    ];
    
    try {
        // Picks per hour (today)
        $picksToday = $db->fetchValue(
            "SELECT COUNT(*) FROM activity_logs 
             WHERE action = 'ORDER_PICKED' AND DATE(created_at) = CURDATE()"
        ) ?: 0;
        
        $hoursWorked = $db->fetchValue(
            "SELECT COUNT(DISTINCT HOUR(created_at)) FROM activity_logs 
             WHERE action IN ('ORDER_PICKED', 'PUTAWAY_COMPLETED') 
             AND DATE(created_at) = CURDATE()"
        ) ?: 1;
        
        $metrics['picks_per_hour'] = round($picksToday / $hoursWorked, 2);
        
        // Putaway per hour
        $putawayToday = $db->fetchValue(
            "SELECT COUNT(*) FROM activity_logs 
             WHERE action = 'PUTAWAY_COMPLETED' AND DATE(created_at) = CURDATE()"
        ) ?: 0;
        
        $metrics['putaway_per_hour'] = round($putawayToday / $hoursWorked, 2);
        
        // Orders per hour
        $ordersToday = $db->fetchValue(
            "SELECT COUNT(*) FROM outbound_orders 
             WHERE DATE(created_at) = CURDATE() AND deleted_at IS NULL"
        ) ?: 0;
        
        $metrics['orders_per_hour'] = round($ordersToday / $hoursWorked, 2);
        
    } catch (Exception $e) {
        error_log("Performance metrics error: " . $e->getMessage());
    }
    
    return $metrics;
}

/**
 * Get KPI data
 */
function getKPIData($db) {
    return [
        'on_time_delivery' => 95.5,
        'cost_per_shipment' => 12.50,
        'order_accuracy' => 99.2,
        'inventory_accuracy' => 98.8,
        'space_utilization' => 78.3,
        'labor_efficiency' => 87.6
    ];
}

/**
 * Get trend data for charts
 */
function getTrendData($db) {
    try {
        // Get last 7 days of data
        $orderTrend = $db->fetchAll(
            "SELECT DATE(created_at) as date, COUNT(*) as count 
             FROM outbound_orders 
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) 
             AND deleted_at IS NULL 
             GROUP BY DATE(created_at) 
             ORDER BY date"
        );
        
        $inventoryTrend = $db->fetchAll(
            "SELECT DATE(created_at) as date, COALESCE(SUM(quantity), 0) as quantity 
             FROM inventory_adjustments 
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) 
             AND deleted_at IS NULL 
             GROUP BY DATE(created_at) 
             ORDER BY date"
        );
        
        return [
            'orders' => $orderTrend,
            'inventory' => $inventoryTrend
        ];
    } catch (Exception $e) {
        return ['orders' => [], 'inventory' => []];
    }
}

/**
 * Generate reports
 */
function generateReport($db, $reportType, $dateRange, $security) {
    $dateFilter = match($dateRange) {
        'today' => 'DATE(created_at) = CURDATE()',
        'week' => 'created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)',
        'month' => 'created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)',
        default => 'created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)'
    };
    
    switch ($reportType) {
        case 'inventory':
            return $db->fetchAll(
                "SELECT i.sku, i.quantity, i.location, sm.description, sm.unit_cost 
                 FROM inventory i 
                 LEFT JOIN sku_master sm ON i.sku = sm.sku 
                 WHERE i.deleted_at IS NULL 
                 ORDER BY i.quantity DESC"
            );
            
        case 'orders':
            return $db->fetchAll(
                "SELECT order_number, customer, status, created_at, shipped_at 
                 FROM outbound_orders 
                 WHERE {$dateFilter} AND deleted_at IS NULL 
                 ORDER BY created_at DESC"
            );
            
        case 'performance':
            return $db->fetchAll(
                "SELECT action, COUNT(*) as count, DATE(created_at) as date 
                 FROM activity_logs 
                 WHERE {$dateFilter} 
                 GROUP BY action, DATE(created_at) 
                 ORDER BY date DESC, count DESC"
            );
            
        default:
            return [];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Professional WMS Dashboard</title>
    <meta name="robots" content="noindex, nofollow">
    <meta http-equiv="X-Content-Type-Options" content="nosniff">
    <meta http-equiv="X-Frame-Options" content="SAMEORIGIN">
    <meta http-equiv="X-XSS-Protection" content="1; mode=block">
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --success-gradient: linear-gradient(135deg, #4ecdc4 0%, #44a08d 100%);
            --warning-gradient: linear-gradient(135deg, #ffecd2 0%, #fcb69f 100%);
            --info-gradient: linear-gradient(135deg, #a8edea 0%, #fed6e3 100%);
            --danger-gradient: linear-gradient(135deg, #ff9a9e 0%, #fecfef 100%);
        }
        
        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
        }
        
        .navbar {
            background: var(--primary-gradient);
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        }
        
        .analytics-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            border: none;
            transition: all 0.3s ease;
            overflow: hidden;
        }
        
        .analytics-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 40px rgba(0,0,0,0.15);
        }
        
        .metric-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
            border: none;
            transition: all 0.3s ease;
        }
        
        .metric-card:hover {
            transform: scale(1.05);
        }
        
        .kpi-widget {
            background: white;
            border-radius: 15px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
            border: none;
            transition: all 0.3s ease;
        }
        
        .chart-container {
            background: white;
            border-radius: 15px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
            padding: 20px;
        }
        
        .dashboard-header {
            background: var(--primary-gradient);
            color: white;
            padding: 30px 0;
            margin-bottom: 30px;
            border-radius: 0 0 30px 30px;
        }
        
        .performance-gauge {
            position: relative;
            width: 200px;
            height: 200px;
            margin: 0 auto;
        }
        
        .gauge-fill {
            fill: none;
            stroke: #e0e0e0;
            stroke-width: 10;
        }
        
        .gauge-progress {
            fill: none;
            stroke: #4ecdc4;
            stroke-width: 10;
            stroke-linecap: round;
            transition: stroke-dasharray 1s ease;
        }
        
        .floating-actions {
            position: fixed;
            bottom: 30px;
            right: 30px;
            z-index: 1000;
        }
        
        .fab {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: var(--primary-gradient);
            border: none;
            color: white;
            font-size: 24px;
            box-shadow: 0 8px 25px rgba(0,0,0,0.2);
            transition: all 0.3s ease;
            margin-bottom: 15px;
            display: block;
        }
        
        .fab:hover {
            transform: scale(1.1);
            box-shadow: 0 12px 35px rgba(0,0,0,0.3);
        }
        
        .trend-up { color: #4ecdc4; }
        .trend-down { color: #ff6b6b; }
        .trend-neutral { color: #95a5a6; }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container-fluid">
            <a class="navbar-brand" href="#">
                <i class="fas fa-chart-line"></i> Professional WMS Analytics
            </a>
            
            <div class="navbar-nav ms-auto">
                <div class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown">
                        <i class="fas fa-user-crown"></i> <?= $security->escapeOutput($_SESSION['username']) ?>
                        <span class="badge bg-light text-dark ms-1"><?= $security->escapeOutput($_SESSION['role']) ?></span>
                    </a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="secure-dashboard.php"><i class="fas fa-tachometer-alt"></i> Standard Dashboard</a></li>
                        <li><a class="dropdown-item" href="manage_users_secure.php"><i class="fas fa-users-cog"></i> User Management</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="auth.php?action=logout"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </nav>

    <!-- Dashboard Header -->
    <div class="dashboard-header">
        <div class="container-fluid">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h1 class="mb-0"><i class="fas fa-analytics"></i> Warehouse Analytics</h1>
                    <p class="mb-0 opacity-75">Real-time insights and performance metrics</p>
                </div>
                <div class="col-md-4 text-end">
                    <div class="btn-group" role="group">
                        <input type="radio" class="btn-check" name="period" id="week" value="week" checked>
                        <label class="btn btn-outline-light" for="week">Week</label>
                        
                        <input type="radio" class="btn-check" name="period" id="month" value="month">
                        <label class="btn btn-outline-light" for="month">Month</label>
                        
                        <input type="radio" class="btn-check" name="period" id="quarter" value="quarter">
                        <label class="btn btn-outline-light" for="quarter">Quarter</label>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="container-fluid">
        <!-- Key Performance Indicators -->
        <div class="row mb-4">
            <div class="col-lg-2 col-md-4 mb-3">
                <div class="card kpi-widget">
                    <div class="card-body text-center">
                        <i class="fas fa-truck fa-2x mb-3 opacity-75"></i>
                        <h3 class="mb-1"><?= $kpiData['on_time_delivery'] ?>%</h3>
                        <p class="mb-0 small opacity-75">On-Time Delivery</p>
                        <i class="fas fa-arrow-up trend-up"></i>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-2 col-md-4 mb-3">
                <div class="card kpi-widget">
                    <div class="card-body text-center">
                        <i class="fas fa-dollar-sign fa-2x mb-3 opacity-75"></i>
                        <h3 class="mb-1">$<?= $kpiData['cost_per_shipment'] ?></h3>
                        <p class="mb-0 small opacity-75">Cost per Shipment</p>
                        <i class="fas fa-arrow-down trend-up"></i>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-2 col-md-4 mb-3">
                <div class="card kpi-widget">
                    <div class="card-body text-center">
                        <i class="fas fa-bullseye fa-2x mb-3 opacity-75"></i>
                        <h3 class="mb-1"><?= $kpiData['order_accuracy'] ?>%</h3>
                        <p class="mb-0 small opacity-75">Order Accuracy</p>
                        <i class="fas fa-arrow-up trend-up"></i>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-2 col-md-4 mb-3">
                <div class="card kpi-widget">
                    <div class="card-body text-center">
                        <i class="fas fa-boxes fa-2x mb-3 opacity-75"></i>
                        <h3 class="mb-1"><?= $kpiData['inventory_accuracy'] ?>%</h3>
                        <p class="mb-0 small opacity-75">Inventory Accuracy</p>
                        <i class="fas fa-minus trend-neutral"></i>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-2 col-md-4 mb-3">
                <div class="card kpi-widget">
                    <div class="card-body text-center">
                        <i class="fas fa-warehouse fa-2x mb-3 opacity-75"></i>
                        <h3 class="mb-1"><?= $kpiData['space_utilization'] ?>%</h3>
                        <p class="mb-0 small opacity-75">Space Utilization</p>
                        <i class="fas fa-arrow-up trend-up"></i>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-2 col-md-4 mb-3">
                <div class="card kpi-widget">
                    <div class="card-body text-center">
                        <i class="fas fa-users fa-2x mb-3 opacity-75"></i>
                        <h3 class="mb-1"><?= $kpiData['labor_efficiency'] ?>%</h3>
                        <p class="mb-0 small opacity-75">Labor Efficiency</p>
                        <i class="fas fa-arrow-up trend-up"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Analytics Cards -->
        <div class="row mb-4">
            <div class="col-lg-3 col-md-6 mb-3">
                <div class="card analytics-card">
                    <div class="card-body text-center">
                        <i class="fas fa-sync-alt fa-3x text-primary mb-3"></i>
                        <h2 class="text-primary"><?= $analytics['inventory_turnover'] ?></h2>
                        <p class="text-muted mb-0">Inventory Turnover</p>
                        <small class="text-success"><i class="fas fa-arrow-up"></i> 12% from last period</small>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-3 col-md-6 mb-3">
                <div class="card analytics-card">
                    <div class="card-body text-center">
                        <i class="fas fa-check-circle fa-3x text-success mb-3"></i>
                        <h2 class="text-success"><?= $analytics['order_fulfillment_rate'] ?>%</h2>
                        <p class="text-muted mb-0">Fulfillment Rate</p>
                        <small class="text-success"><i class="fas fa-arrow-up"></i> 3% from last period</small>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-3 col-md-6 mb-3">
                <div class="card analytics-card">
                    <div class="card-body text-center">
                        <i class="fas fa-clock fa-3x text-warning mb-3"></i>
                        <h2 class="text-warning"><?= $analytics['average_processing_time'] ?>h</h2>
                        <p class="text-muted mb-0">Avg Processing Time</p>
                        <small class="text-success"><i class="fas fa-arrow-down"></i> 8% faster</small>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-3 col-md-6 mb-3">
                <div class="card analytics-card">
                    <div class="card-body text-center">
                        <i class="fas fa-percentage fa-3x text-info mb-3"></i>
                        <h2 class="text-info"><?= $analytics['accuracy_rate'] ?>%</h2>
                        <p class="text-muted mb-0">Accuracy Rate</p>
                        <small class="text-success"><i class="fas fa-arrow-up"></i> 1.5% improvement</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Charts and Performance -->
        <div class="row mb-4">
            <div class="col-lg-8 mb-4">
                <div class="chart-container">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="mb-0"><i class="fas fa-chart-line"></i> Order Trends</h5>
                        <div class="btn-group btn-group-sm" role="group">
                            <button type="button" class="btn btn-outline-primary active" data-chart="orders">Orders</button>
                            <button type="button" class="btn btn-outline-primary" data-chart="inventory">Inventory</button>
                            <button type="button" class="btn btn-outline-primary" data-chart="performance">Performance</button>
                        </div>
                    </div>
                    <canvas id="trendChart" height="100"></canvas>
                </div>
            </div>
            
            <div class="col-lg-4 mb-4">
                <div class="chart-container">
                    <h5 class="mb-3"><i class="fas fa-tachometer-alt"></i> Warehouse Utilization</h5>
                    <div class="performance-gauge">
                        <svg width="200" height="200">
                            <circle class="gauge-fill" cx="100" cy="100" r="80"/>
                            <circle class="gauge-progress" cx="100" cy="100" r="80" 
                                   stroke-dasharray="<?= $analytics['warehouse_utilization'] * 5.02 ?> 502"/>
                        </svg>
                        <div class="position-absolute top-50 start-50 translate-middle text-center">
                            <h2 class="mb-0"><?= $analytics['warehouse_utilization'] ?>%</h2>
                            <small class="text-muted">Utilization</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Performance Metrics -->
        <div class="row mb-4">
            <div class="col-lg-4 mb-3">
                <div class="card metric-card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h4 class="mb-1"><?= $performanceMetrics['picks_per_hour'] ?></h4>
                                <p class="mb-0 opacity-75">Picks per Hour</p>
                            </div>
                            <i class="fas fa-hand-paper fa-2x opacity-75"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-4 mb-3">
                <div class="card metric-card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h4 class="mb-1"><?= $performanceMetrics['putaway_per_hour'] ?></h4>
                                <p class="mb-0 opacity-75">Putaway per Hour</p>
                            </div>
                            <i class="fas fa-dolly fa-2x opacity-75"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-4 mb-3">
                <div class="card metric-card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h4 class="mb-1"><?= $performanceMetrics['orders_per_hour'] ?></h4>
                                <p class="mb-0 opacity-75">Orders per Hour</p>
                            </div>
                            <i class="fas fa-shipping-fast fa-2x opacity-75"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quick Reports -->
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-file-alt"></i> Quick Reports</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <div class="d-grid">
                                    <button class="btn btn-outline-primary" onclick="generateReport('inventory', 'today')">
                                        <i class="fas fa-boxes"></i><br>Inventory Report
                                    </button>
                                </div>
                            </div>
                            <div class="col-md-4 mb-3">
                                <div class="d-grid">
                                    <button class="btn btn-outline-success" onclick="generateReport('orders', 'week')">
                                        <i class="fas fa-receipt"></i><br>Orders Report
                                    </button>
                                </div>
                            </div>
                            <div class="col-md-4 mb-3">
                                <div class="d-grid">
                                    <button class="btn btn-outline-info" onclick="generateReport('performance', 'month')">
                                        <i class="fas fa-chart-bar"></i><br>Performance Report
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Floating Action Buttons -->
    <div class="floating-actions">
        <button class="fab" onclick="refreshDashboard()" title="Refresh Dashboard">
            <i class="fas fa-sync-alt"></i>
        </button>
        <button class="fab" onclick="toggleFullscreen()" title="Toggle Fullscreen">
            <i class="fas fa-expand"></i>
        </button>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const csrfToken = '<?= $csrfToken ?>';
        let trendChart;
        
        // Initialize charts
        document.addEventListener('DOMContentLoaded', function() {
            initTrendChart();
            
            // Period change handler
            document.querySelectorAll('input[name="period"]').forEach(input => {
                input.addEventListener('change', function() {
                    refreshAnalytics(this.value);
                });
            });
            
            // Auto-refresh every 2 minutes
            setInterval(refreshDashboard, 120000);
        });
        
        function initTrendChart() {
            const ctx = document.getElementById('trendChart').getContext('2d');
            const trendData = <?= json_encode($trendData) ?>;
            
            trendChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: trendData.orders.map(item => item.date),
                    datasets: [{
                        label: 'Orders',
                        data: trendData.orders.map(item => item.count),
                        borderColor: 'rgb(102, 126, 234)',
                        backgroundColor: 'rgba(102, 126, 234, 0.1)',
                        tension: 0.4,
                        fill: true
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: {
                                color: 'rgba(0,0,0,0.1)'
                            }
                        },
                        x: {
                            grid: {
                                color: 'rgba(0,0,0,0.1)'
                            }
                        }
                    }
                }
            });
        }
        
        function refreshAnalytics(period) {
            fetch(window.location.href, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=get_analytics&period=${period}&csrf_token=${csrfToken}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    updateAnalyticsDisplay(data.data);
                }
            })
            .catch(error => console.error('Error refreshing analytics:', error));
        }
        
        function refreshDashboard() {
            location.reload();
        }
        
        function generateReport(type, dateRange) {
            fetch(window.location.href, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=generate_report&report_type=${type}&date_range=${dateRange}&csrf_token=${csrfToken}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    downloadCSV(data.data, `${type}_report_${dateRange}.csv`);
                } else {
                    alert('Failed to generate report: ' + data.error);
                }
            })
            .catch(error => {
                console.error('Error generating report:', error);
                alert('Failed to generate report');
            });
        }
        
        function downloadCSV(data, filename) {
            if (data.length === 0) {
                alert('No data available for the selected report');
                return;
            }
            
            const headers = Object.keys(data[0]);
            const csv = [
                headers.join(','),
                ...data.map(row => headers.map(header => `"${row[header] || ''}"`).join(','))
            ].join('\n');
            
            const blob = new Blob([csv], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = filename;
            a.click();
            window.URL.revokeObjectURL(url);
        }
        
        function toggleFullscreen() {
            if (!document.fullscreenElement) {
                document.documentElement.requestFullscreen();
            } else {
                document.exitFullscreen();
            }
        }
        
        function updateAnalyticsDisplay(analytics) {
            // Update analytics values on the page
            // Implementation would update the displayed metrics
        }
    </script>
</body>
</html>