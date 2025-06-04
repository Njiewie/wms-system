<?php
/**
 * Secure WMS Dashboard
 * Main dashboard with role-based access control and security features
 */

require_once 'security-utils.php';
require_once 'db_config.php';

$security = SecurityUtils::getInstance();
$db = getDB();

// Check rate limiting
if (!$security->checkRateLimit()) {
    http_response_code(429);
    $security->logActivity('RATE_LIMIT_EXCEEDED', ['page' => 'dashboard'], 'WARNING');
    die('Rate limit exceeded. Please try again later.');
}

// Validate session and require minimum operator role
if (!$security->validateSession('operator')) {
    $security->logActivity('UNAUTHORIZED_ACCESS_ATTEMPT', ['page' => 'dashboard'], 'WARNING');
    header('Location: auth.php');
    exit();
}

// Generate CSRF token
$csrfToken = $security->generateCSRFToken();

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    // Validate CSRF token
    if (!$security->validateCSRFToken($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid CSRF token']);
        exit();
    }
    
    $action = $security->sanitizeInput($_POST['action']);
    
    switch ($action) {
        case 'get_stats':
            try {
                $stats = getDashboardStats($db);
                echo json_encode(['success' => true, 'data' => $stats]);
            } catch (Exception $e) {
                $security->logActivity('DASHBOARD_STATS_ERROR', ['error' => $e->getMessage()], 'ERROR');
                echo json_encode(['error' => 'Failed to load statistics']);
            }
            break;
            
        case 'get_alerts':
            try {
                $alerts = getSystemAlerts($db, $security);
                echo json_encode(['success' => true, 'data' => $alerts]);
            } catch (Exception $e) {
                $security->logActivity('DASHBOARD_ALERTS_ERROR', ['error' => $e->getMessage()], 'ERROR');
                echo json_encode(['error' => 'Failed to load alerts']);
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
    $stats = getDashboardStats($db);
    $alerts = getSystemAlerts($db, $security);
    $recentActivity = getRecentActivity($db, $security);
} catch (Exception $e) {
    $security->logActivity('DASHBOARD_LOAD_ERROR', ['error' => $e->getMessage()], 'ERROR');
    $stats = getDefaultStats();
    $alerts = [];
    $recentActivity = [];
}

$security->logActivity('DASHBOARD_ACCESS', ['user_role' => $_SESSION['role']]);

/**
 * Get dashboard statistics
 */
function getDashboardStats($db) {
    $stats = [
        'inventory' => [
            'total_skus' => 0,
            'total_quantity' => 0,
            'low_stock_alerts' => 0,
            'zero_stock_items' => 0
        ],
        'orders' => [
            'pending_inbound' => 0,
            'pending_outbound' => 0,
            'ready_to_ship' => 0,
            'shipped_today' => 0
        ],
        'operations' => [
            'pending_putaway' => 0,
            'pending_picks' => 0,
            'completed_today' => 0,
            'active_users' => 0
        ]
    ];
    
    try {
        // Inventory stats
        $stats['inventory']['total_skus'] = $db->fetchValue(
            "SELECT COUNT(DISTINCT sku) FROM inventory WHERE deleted_at IS NULL"
        ) ?: 0;
        
        $stats['inventory']['total_quantity'] = $db->fetchValue(
            "SELECT COALESCE(SUM(quantity), 0) FROM inventory WHERE deleted_at IS NULL"
        ) ?: 0;
        
        $stats['inventory']['low_stock_alerts'] = $db->fetchValue(
            "SELECT COUNT(*) FROM inventory i 
             LEFT JOIN sku_master sm ON i.sku = sm.sku 
             WHERE i.quantity <= COALESCE(sm.min_stock_level, 10) 
             AND i.deleted_at IS NULL"
        ) ?: 0;
        
        $stats['inventory']['zero_stock_items'] = $db->fetchValue(
            "SELECT COUNT(*) FROM inventory WHERE quantity = 0 AND deleted_at IS NULL"
        ) ?: 0;
        
        // Order stats
        $stats['orders']['pending_inbound'] = $db->fetchValue(
            "SELECT COUNT(*) FROM asn WHERE status IN ('created', 'in_progress') AND deleted_at IS NULL"
        ) ?: 0;
        
        $stats['orders']['pending_outbound'] = $db->fetchValue(
            "SELECT COUNT(*) FROM outbound_orders WHERE status = 'pending' AND deleted_at IS NULL"
        ) ?: 0;
        
        $stats['orders']['ready_to_ship'] = $db->fetchValue(
            "SELECT COUNT(*) FROM outbound_orders WHERE status = 'picked' AND deleted_at IS NULL"
        ) ?: 0;
        
        $stats['orders']['shipped_today'] = $db->fetchValue(
            "SELECT COUNT(*) FROM outbound_orders 
             WHERE status = 'shipped' AND DATE(shipped_at) = CURDATE() AND deleted_at IS NULL"
        ) ?: 0;
        
        // Operations stats
        $stats['operations']['pending_putaway'] = $db->fetchValue(
            "SELECT COUNT(*) FROM asn_lines al 
             JOIN asn a ON al.asn_id = a.id 
             WHERE a.status = 'received' AND al.putaway_location IS NULL 
             AND a.deleted_at IS NULL AND al.deleted_at IS NULL"
        ) ?: 0;
        
        $stats['operations']['pending_picks'] = $db->fetchValue(
            "SELECT COUNT(*) FROM outbound_orders 
             WHERE status IN ('allocated', 'picking') AND deleted_at IS NULL"
        ) ?: 0;
        
        $stats['operations']['completed_today'] = $db->fetchValue(
            "SELECT COUNT(*) FROM activity_logs 
             WHERE action IN ('ORDER_SHIPPED', 'ASN_PROCESSED') 
             AND DATE(created_at) = CURDATE()"
        ) ?: 0;
        
        $stats['operations']['active_users'] = $db->fetchValue(
            "SELECT COUNT(DISTINCT user_id) FROM activity_logs 
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)"
        ) ?: 0;
        
    } catch (Exception $e) {
        error_log("Dashboard stats error: " . $e->getMessage());
    }
    
    return $stats;
}

/**
 * Get system alerts
 */
function getSystemAlerts($db, $security) {
    $alerts = [];
    
    try {
        // Low stock alerts
        $lowStock = $db->fetchAll(
            "SELECT i.sku, i.quantity, sm.min_stock_level, sm.description 
             FROM inventory i 
             LEFT JOIN sku_master sm ON i.sku = sm.sku 
             WHERE i.quantity <= COALESCE(sm.min_stock_level, 10) 
             AND i.quantity > 0 AND i.deleted_at IS NULL 
             ORDER BY i.quantity ASC 
             LIMIT 10"
        );
        
        foreach ($lowStock as $item) {
            $alerts[] = [
                'type' => 'warning',
                'title' => 'Low Stock Alert',
                'message' => "SKU: {$item['sku']} - Quantity: {$item['quantity']} (Min: {$item['min_stock_level']})",
                'priority' => 'medium',
                'created_at' => date('Y-m-d H:i:s')
            ];
        }
        
        // Zero stock alerts
        $zeroStock = $db->fetchAll(
            "SELECT sku, description FROM sku_master 
             WHERE sku NOT IN (SELECT DISTINCT sku FROM inventory WHERE quantity > 0 AND deleted_at IS NULL) 
             AND deleted_at IS NULL 
             LIMIT 5"
        );
        
        foreach ($zeroStock as $item) {
            $alerts[] = [
                'type' => 'danger',
                'title' => 'Out of Stock',
                'message' => "SKU: {$item['sku']} - {$item['description']}",
                'priority' => 'high',
                'created_at' => date('Y-m-d H:i:s')
            ];
        }
        
        // Overdue orders
        $overdueOrders = $db->fetchAll(
            "SELECT order_number, customer, expected_ship_date 
             FROM outbound_orders 
             WHERE expected_ship_date < CURDATE() 
             AND status NOT IN ('shipped', 'cancelled') 
             AND deleted_at IS NULL 
             LIMIT 5"
        );
        
        foreach ($overdueOrders as $order) {
            $alerts[] = [
                'type' => 'danger',
                'title' => 'Overdue Order',
                'message' => "Order: {$order['order_number']} - Customer: {$order['customer']} - Due: {$order['expected_ship_date']}",
                'priority' => 'high',
                'created_at' => date('Y-m-d H:i:s')
            ];
        }
        
        // System security alerts from the last 24 hours
        $securityAlerts = $db->fetchAll(
            "SELECT action, COUNT(*) as count 
             FROM activity_logs 
             WHERE action IN ('UNAUTHORIZED_ACCESS_ATTEMPT', 'RATE_LIMIT_EXCEEDED', 'CSRF_TOKEN_INVALID') 
             AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) 
             GROUP BY action 
             HAVING count > 5"
        );
        
        foreach ($securityAlerts as $alert) {
            $alerts[] = [
                'type' => 'warning',
                'title' => 'Security Alert',
                'message' => "Multiple {$alert['action']} events detected ({$alert['count']} times in 24h)",
                'priority' => 'high',
                'created_at' => date('Y-m-d H:i:s')
            ];
        }
        
    } catch (Exception $e) {
        error_log("Dashboard alerts error: " . $e->getMessage());
    }
    
    // Sort alerts by priority
    usort($alerts, function($a, $b) {
        $priorities = ['high' => 3, 'medium' => 2, 'low' => 1];
        return ($priorities[$b['priority']] ?? 1) - ($priorities[$a['priority']] ?? 1);
    });
    
    return array_slice($alerts, 0, 20); // Limit to 20 alerts
}

/**
 * Get recent activity
 */
function getRecentActivity($db, $security) {
    try {
        return $db->fetchAll(
            "SELECT al.action, al.created_at, u.username, al.ip_address 
             FROM activity_logs al 
             LEFT JOIN users u ON al.user_id = u.id 
             WHERE al.action NOT IN ('DATABASE_QUERY', 'SESSION_REGENERATION') 
             ORDER BY al.created_at DESC 
             LIMIT 20"
        );
    } catch (Exception $e) {
        error_log("Recent activity error: " . $e->getMessage());
        return [];
    }
}

/**
 * Get default stats for error scenarios
 */
function getDefaultStats() {
    return [
        'inventory' => ['total_skus' => 0, 'total_quantity' => 0, 'low_stock_alerts' => 0, 'zero_stock_items' => 0],
        'orders' => ['pending_inbound' => 0, 'pending_outbound' => 0, 'ready_to_ship' => 0, 'shipped_today' => 0],
        'operations' => ['pending_putaway' => 0, 'pending_picks' => 0, 'completed_today' => 0, 'active_users' => 0]
    ];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WMS Dashboard - Secure</title>
    <meta name="robots" content="noindex, nofollow">
    <meta http-equiv="X-Content-Type-Options" content="nosniff">
    <meta http-equiv="X-Frame-Options" content="SAMEORIGIN">
    <meta http-equiv="X-XSS-Protection" content="1; mode=block">
    <meta name="referrer" content="same-origin">
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    
    <style>
        :root {
            --primary-color: #2c3e50;
            --secondary-color: #34495e;
            --success-color: #27ae60;
            --warning-color: #f39c12;
            --danger-color: #e74c3c;
            --info-color: #3498db;
        }
        
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .navbar {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .stats-card {
            border: none;
            border-radius: 15px;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            background: white;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        
        .stats-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        }
        
        .stats-icon {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: white;
        }
        
        .alert-item {
            border-left: 4px solid;
            background: white;
            border-radius: 8px;
            transition: all 0.3s ease;
        }
        
        .alert-item:hover {
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        
        .activity-item {
            background: white;
            border-radius: 8px;
            border: 1px solid #eee;
            transition: all 0.3s ease;
        }
        
        .activity-item:hover {
            border-color: var(--primary-color);
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .loading {
            opacity: 0.6;
            pointer-events: none;
        }
        
        .security-status {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1000;
        }
    </style>
</head>
<body>
    <!-- Security Status Indicator -->
    <div class="security-status">
        <span class="badge bg-success"><i class="fas fa-shield-alt"></i> Secure Session</span>
    </div>

    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container-fluid">
            <a class="navbar-brand" href="#">
                <i class="fas fa-warehouse"></i> WMS Dashboard
            </a>
            
            <div class="navbar-nav ms-auto">
                <div class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown">
                        <i class="fas fa-user"></i> <?= $security->escapeOutput($_SESSION['username']) ?>
                        <span class="badge bg-light text-dark ms-1"><?= $security->escapeOutput($_SESSION['role']) ?></span>
                    </a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="manage_users_secure.php"><i class="fas fa-users"></i> Manage Users</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="auth.php?action=logout"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </nav>

    <div class="container-fluid mt-4">
        <!-- Dashboard Statistics -->
        <div class="row mb-4">
            <div class="col-md-3 mb-3">
                <div class="card stats-card">
                    <div class="card-body d-flex align-items-center">
                        <div class="stats-icon bg-primary me-3">
                            <i class="fas fa-boxes"></i>
                        </div>
                        <div>
                            <h5 class="card-title text-muted mb-1">Total SKUs</h5>
                            <h3 class="mb-0"><?= number_format($stats['inventory']['total_skus']) ?></h3>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3 mb-3">
                <div class="card stats-card">
                    <div class="card-body d-flex align-items-center">
                        <div class="stats-icon bg-success me-3">
                            <i class="fas fa-cubes"></i>
                        </div>
                        <div>
                            <h5 class="card-title text-muted mb-1">Total Quantity</h5>
                            <h3 class="mb-0"><?= number_format($stats['inventory']['total_quantity']) ?></h3>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3 mb-3">
                <div class="card stats-card">
                    <div class="card-body d-flex align-items-center">
                        <div class="stats-icon bg-warning me-3">
                            <i class="fas fa-exclamation-triangle"></i>
                        </div>
                        <div>
                            <h5 class="card-title text-muted mb-1">Low Stock</h5>
                            <h3 class="mb-0"><?= number_format($stats['inventory']['low_stock_alerts']) ?></h3>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3 mb-3">
                <div class="card stats-card">
                    <div class="card-body d-flex align-items-center">
                        <div class="stats-icon bg-info me-3">
                            <i class="fas fa-shipping-fast"></i>
                        </div>
                        <div>
                            <h5 class="card-title text-muted mb-1">Pending Orders</h5>
                            <h3 class="mb-0"><?= number_format($stats['orders']['pending_outbound']) ?></h3>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-bolt"></i> Quick Actions</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-2 mb-2">
                                <a href="secure-inventory.php" class="btn btn-outline-primary w-100">
                                    <i class="fas fa-warehouse"></i><br>Inventory
                                </a>
                            </div>
                            <div class="col-md-2 mb-2">
                                <a href="inbound_secure.php" class="btn btn-outline-success w-100">
                                    <i class="fas fa-truck-loading"></i><br>Inbound
                                </a>
                            </div>
                            <div class="col-md-2 mb-2">
                                <a href="outbound_secure.php" class="btn btn-outline-info w-100">
                                    <i class="fas fa-shipping-fast"></i><br>Outbound
                                </a>
                            </div>
                            <div class="col-md-2 mb-2">
                                <a href="putaway_secure.php" class="btn btn-outline-warning w-100">
                                    <i class="fas fa-dolly"></i><br>Putaway
                                </a>
                            </div>
                            <div class="col-md-2 mb-2">
                                <a href="pick_order_secure.php" class="btn btn-outline-secondary w-100">
                                    <i class="fas fa-hand-paper"></i><br>Picking
                                </a>
                            </div>
                            <div class="col-md-2 mb-2">
                                <a href="ship_order_secure.php" class="btn btn-outline-dark w-100">
                                    <i class="fas fa-truck"></i><br>Shipping
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Alerts Panel -->
            <div class="col-lg-8 mb-4">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-bell"></i> System Alerts</h5>
                        <button class="btn btn-sm btn-outline-secondary" onclick="refreshAlerts()">
                            <i class="fas fa-sync-alt"></i> Refresh
                        </button>
                    </div>
                    <div class="card-body" id="alertsContainer">
                        <?php if (empty($alerts)): ?>
                            <div class="text-center text-muted py-4">
                                <i class="fas fa-check-circle fa-3x mb-3"></i>
                                <p>No active alerts</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($alerts as $alert): ?>
                                <div class="alert-item p-3 mb-3 border-<?= $alert['type'] ?>">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <h6 class="mb-1 text-<?= $alert['type'] ?>">
                                                <i class="fas fa-<?= $alert['type'] === 'danger' ? 'exclamation-circle' : 'exclamation-triangle' ?>"></i>
                                                <?= $security->escapeOutput($alert['title']) ?>
                                            </h6>
                                            <p class="mb-1"><?= $security->escapeOutput($alert['message']) ?></p>
                                            <small class="text-muted"><?= $security->escapeOutput($alert['created_at']) ?></small>
                                        </div>
                                        <span class="badge bg-<?= $alert['type'] ?>"><?= $security->escapeOutput($alert['priority']) ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Recent Activity -->
            <div class="col-lg-4 mb-4">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-history"></i> Recent Activity</h5>
                    </div>
                    <div class="card-body">
                        <div id="activityContainer" style="max-height: 400px; overflow-y: auto;">
                            <?php foreach ($recentActivity as $activity): ?>
                                <div class="activity-item p-2 mb-2">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div class="flex-grow-1">
                                            <small class="text-muted"><?= $security->escapeOutput($activity['username'] ?? 'System') ?></small>
                                            <div class="fw-bold"><?= $security->escapeOutput(str_replace('_', ' ', $activity['action'])) ?></div>
                                            <small class="text-muted"><?= date('M j, H:i', strtotime($activity['created_at'])) ?></small>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // CSRF token for AJAX requests
        const csrfToken = '<?= $csrfToken ?>';
        
        // Auto-refresh dashboard data every 30 seconds
        setInterval(function() {
            refreshDashboard();
        }, 30000);
        
        function refreshDashboard() {
            refreshStats();
            refreshAlerts();
        }
        
        function refreshStats() {
            fetch(window.location.href, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=get_stats&csrf_token=${csrfToken}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Update stats display
                    updateStatsDisplay(data.data);
                }
            })
            .catch(error => console.error('Error refreshing stats:', error));
        }
        
        function refreshAlerts() {
            const container = document.getElementById('alertsContainer');
            container.classList.add('loading');
            
            fetch(window.location.href, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=get_alerts&csrf_token=${csrfToken}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    updateAlertsDisplay(data.data);
                }
            })
            .catch(error => console.error('Error refreshing alerts:', error))
            .finally(() => {
                container.classList.remove('loading');
            });
        }
        
        function updateStatsDisplay(stats) {
            // Update stats cards with new data
            // Implementation would update the displayed numbers
        }
        
        function updateAlertsDisplay(alerts) {
            const container = document.getElementById('alertsContainer');
            
            if (alerts.length === 0) {
                container.innerHTML = `
                    <div class="text-center text-muted py-4">
                        <i class="fas fa-check-circle fa-3x mb-3"></i>
                        <p>No active alerts</p>
                    </div>
                `;
                return;
            }
            
            let html = '';
            alerts.forEach(alert => {
                html += `
                    <div class="alert-item p-3 mb-3 border-${alert.type}">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <h6 class="mb-1 text-${alert.type}">
                                    <i class="fas fa-${alert.type === 'danger' ? 'exclamation-circle' : 'exclamation-triangle'}"></i>
                                    ${alert.title}
                                </h6>
                                <p class="mb-1">${alert.message}</p>
                                <small class="text-muted">${alert.created_at}</small>
                            </div>
                            <span class="badge bg-${alert.type}">${alert.priority}</span>
                        </div>
                    </div>
                `;
            });
            
            container.innerHTML = html;
        }
        
        // Session timeout warning
        let warningShown = false;
        setInterval(function() {
            // Check session timeout (55 minutes)
            if (!warningShown) {
                setTimeout(function() {
                    warningShown = true;
                    if (confirm('Your session will expire in 5 minutes. Click OK to extend.')) {
                        location.reload();
                    }
                }, 55 * 60 * 1000); // 55 minutes
            }
        }, 60000);
    </script>
</body>
</html>