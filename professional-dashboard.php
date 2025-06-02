<?php
require_once 'security-utils.php';
require 'auth.php';
require_login();
include 'db_config.php';

// Set security headers
setSecurityHeaders();

// Validate session
WMSSecurity::regenerateSession();

// Get current user info securely
$user_info = secure_select_one($conn,
    "SELECT username, role, created_at FROM users WHERE username = ?",
    "s",
    [$_SESSION['user']]
);

// Get dashboard statistics securely
$stats = [];

try {
    // Get inventory count
    $inv_result = secure_select_one($conn, "SELECT COUNT(*) as total FROM inventory WHERE qty_on_hand > 0");
    $stats['inventory_count'] = $inv_result['total'] ?? 0;

    // Get total inventory value (estimated)
    $value_result = secure_select_one($conn, "SELECT SUM(qty_on_hand) as total_qty FROM inventory WHERE qty_on_hand > 0");
    $stats['total_items'] = $value_result['total_qty'] ?? 0;

    // Get orders count
    $order_result = secure_select_one($conn, "SELECT COUNT(*) as total FROM outbound_orders WHERE status != 'SHIPPED'");
    $stats['active_orders'] = $order_result['total'] ?? 0;

    // Get today's shipments
    $shipped_today = secure_select_one($conn,
        "SELECT COUNT(*) as total FROM outbound_orders WHERE status = 'SHIPPED' AND DATE(shipped_at) = CURDATE()"
    );
    $stats['shipped_today'] = $shipped_today['total'] ?? 0;

    // Get low stock alerts
    $low_stock_result = secure_select_one($conn, "SELECT COUNT(*) as total FROM inventory WHERE qty_on_hand < 10 AND qty_on_hand > 0");
    $stats['low_stock'] = $low_stock_result['total'] ?? 0;

    // Get pending ASNs
    $asn_result = secure_select_one($conn, "SELECT COUNT(*) as total FROM asn_header WHERE status != 'Completed'");
    $stats['pending_asns'] = $asn_result['total'] ?? 0;

    // Get allocated inventory
    $allocated_result = secure_select_one($conn, "SELECT SUM(qty_allocated) as total FROM inventory WHERE qty_allocated > 0");
    $stats['allocated_qty'] = $allocated_result['total'] ?? 0;

    // Get unique SKUs
    $sku_result = secure_select_one($conn, "SELECT COUNT(DISTINCT sku_id) as total FROM inventory WHERE qty_on_hand > 0");
    $stats['unique_skus'] = $sku_result['total'] ?? 0;

    // Get recent activities (last 24 hours)
    $activity_result = secure_select_one($conn,
        "SELECT COUNT(*) as total FROM audit_log WHERE timestamp >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"
    );
    $stats['recent_activities'] = $activity_result['total'] ?? 0;

    // Get critical alerts
    $critical_orders = secure_select_one($conn,
        "SELECT COUNT(*) as total FROM outbound_orders
         WHERE status IN ('HOLD', 'RELEASED') AND created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)"
    );
    $stats['critical_orders'] = $critical_orders['total'] ?? 0;

} catch (Exception $e) {
    error_log("Dashboard stats error: " . $e->getMessage());
    // Set default values if queries fail
    $stats = array_fill_keys([
        'inventory_count', 'total_items', 'active_orders', 'shipped_today',
        'low_stock', 'pending_asns', 'allocated_qty', 'unique_skus',
        'recent_activities', 'critical_orders'
    ], 0);
}

// Get recent orders for quick view
try {
    $recent_orders = secure_select_all($conn,
        "SELECT o.id, o.order_number, o.sku, o.qty_ordered, o.status,
                o.created_at, c.client_name
         FROM outbound_orders o
         LEFT JOIN clients c ON o.client_id = c.id
         WHERE o.status != 'SHIPPED'
         ORDER BY o.created_at DESC
         LIMIT 5"
    );
} catch (Exception $e) {
    $recent_orders = [];
}

// Get low stock items
try {
    $low_stock_items = secure_select_all($conn,
        "SELECT i.sku_id, i.qty_on_hand, i.location_id, s.description
         FROM inventory i
         LEFT JOIN sku_master s ON i.sku_id = s.sku_id
         WHERE i.qty_on_hand < 10 AND i.qty_on_hand > 0
         ORDER BY i.qty_on_hand ASC
         LIMIT 5"
    );
} catch (Exception $e) {
    $low_stock_items = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ECWMS Dashboard</title>
    <link rel="stylesheet" href="wms-theme.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        .quick-actions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 1.5rem;
            margin: 2rem 0;
        }

        .quick-action-card {
            background: white;
            border-radius: var(--radius-xl);
            padding: 2rem;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--gray-200);
            transition: all var(--transition-fast);
            position: relative;
            overflow: hidden;
        }

        .quick-action-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        .quick-action-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--primary-500), var(--primary-600));
        }

        .quick-action-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .quick-action-icon {
            width: 56px;
            height: 56px;
            border-radius: var(--radius-lg);
            background: linear-gradient(135deg, var(--primary-100), var(--primary-200));
            color: var(--primary-600);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.75rem;
        }

        .quick-action-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--gray-900);
            margin: 0;
        }

        .quick-action-desc {
            color: var(--gray-600);
            font-size: 0.875rem;
            margin-bottom: 1.5rem;
            line-height: 1.5;
        }

        .quick-action-buttons {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
        }

        .recent-activity-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem;
            border-radius: var(--radius-md);
            transition: background-color var(--transition-fast);
        }

        .recent-activity-item:hover {
            background: var(--gray-50);
        }

        .activity-icon {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.875rem;
            font-weight: 500;
        }

        .activity-icon.order {
            background: var(--primary-100);
            color: var(--primary-600);
        }

        .activity-icon.warning {
            background: var(--warning-100);
            color: var(--warning-600);
        }

        .activity-icon.success {
            background: var(--success-100);
            color: var(--success-600);
        }

        .mobile-menu-toggle {
            display: none;
            background: none;
            border: none;
            color: white;
            font-size: 1.5rem;
            cursor: pointer;
            padding: 0.5rem;
        }

        @media (max-width: 768px) {
            .mobile-menu-toggle {
                display: block;
            }

            .wms-search {
                order: 3;
                width: 100%;
                margin: 1rem 0 0 0;
            }

            .wms-user-menu {
                order: 2;
            }
        }
    </style>
</head>
<body class="wms-app">
    <!-- Header -->
    <header class="wms-header">
        <div class="wms-logo">
            <div class="wms-logo-icon">📦</div>
            <span>ECWMS</span>
        </div>

        <div class="wms-search">
            <div style="position: relative;">
                <span class="wms-search-icon">🔍</span>
                <input type="text" class="wms-search-input" placeholder="Search orders, inventory, SKUs..." id="globalSearch">
            </div>
        </div>

        <div class="wms-user-menu">
            <div class="wms-user-info" onclick="showUserMenu()">
                <div class="wms-avatar"><?= strtoupper(substr($user_info['username'], 0, 1)) ?></div>
                <div>
                    <div style="font-weight: 500; font-size: 0.875rem;"><?= secure_escape($user_info['username']) ?></div>
                    <div style="font-size: 0.75rem; opacity: 0.8;"><?= secure_escape(ucfirst($user_info['role'])) ?></div>
                </div>
            </div>
            <button class="mobile-menu-toggle" onclick="toggleMobileMenu()">☰</button>
        </div>
    </header>

    <!-- Main Layout -->
    <div class="wms-main">
        <!-- Sidebar -->
        <nav class="wms-sidebar" id="sidebar">
            <div class="wms-sidebar-header">
                <div class="wms-sidebar-title">Navigation</div>
                <button class="wms-sidebar-toggle" onclick="toggleSidebar()">◀</button>
            </div>

            <div class="wms-nav">
                <div class="wms-nav-group">
                    <div class="wms-nav-group-title">Overview</div>
                    <a href="professional-dashboard.php" class="wms-nav-item active">
                        <span class="wms-nav-icon">🏠</span>
                        <span class="wms-nav-text">Dashboard</span>
                    </a>
                    <a href="analytics.php" class="wms-nav-item">
                        <span class="wms-nav-icon">📊</span>
                        <span class="wms-nav-text">Analytics</span>
                    </a>
                </div>

                <div class="wms-nav-group">
                    <div class="wms-nav-group-title">Inventory</div>
                    <a href="secure-inventory.php" class="wms-nav-item">
                        <span class="wms-nav-icon">📦</span>
                        <span class="wms-nav-text">View Inventory</span>
                    </a>
                    <a href="inventory_add.php" class="wms-nav-item">
                        <span class="wms-nav-icon">➕</span>
                        <span class="wms-nav-text">Add Stock</span>
                    </a>
                    <a href="view_movements.php" class="wms-nav-item">
                        <span class="wms-nav-icon">🔄</span>
                        <span class="wms-nav-text">Movements</span>
                        <?php if ($stats['recent_activities'] > 50): ?>
                        <span class="wms-nav-badge"><?= $stats['recent_activities'] ?></span>
                        <?php endif; ?>
                    </a>
                </div>

                <div class="wms-nav-group">
                    <div class="wms-nav-group-title">Inbound</div>
                    <a href="inbound.php" class="wms-nav-item">
                        <span class="wms-nav-icon">📥</span>
                        <span class="wms-nav-text">Inbound Dashboard</span>
                        <?php if ($stats['pending_asns'] > 0): ?>
                        <span class="wms-nav-badge"><?= $stats['pending_asns'] ?></span>
                        <?php endif; ?>
                    </a>
                    <a href="create_asn.php" class="wms-nav-item">
                        <span class="wms-nav-icon">📋</span>
                        <span class="wms-nav-text">Create ASN</span>
                    </a>
                    <a href="putaway_secure.php" class="wms-nav-item">
                        <span class="wms-nav-icon">📦</span>
                        <span class="wms-nav-text">Putaway</span>
                    </a>
                </div>

                <div class="wms-nav-group">
                    <div class="wms-nav-group-title">Outbound</div>
                    <a href="view_outbound.php" class="wms-nav-item">
                        <span class="wms-nav-icon">📤</span>
                        <span class="wms-nav-text">View Orders</span>
                        <?php if ($stats['critical_orders'] > 0): ?>
                        <span class="wms-nav-badge"><?= $stats['critical_orders'] ?></span>
                        <?php endif; ?>
                    </a>
                    <a href="outbound.php" class="wms-nav-item">
                        <span class="wms-nav-icon">➕</span>
                        <span class="wms-nav-text">Create Order</span>
                    </a>
                    <a href="pick_order_secure.php" class="wms-nav-item">
                        <span class="wms-nav-icon">📥</span>
                        <span class="wms-nav-text">Pick Orders</span>
                    </a>
                    <a href="ship_order_secure.php" class="wms-nav-item">
                        <span class="wms-nav-icon">🚚</span>
                        <span class="wms-nav-text">Ship Orders</span>
                    </a>
                </div>

                <div class="wms-nav-group">
                    <div class="wms-nav-group-title">Master Data</div>
                    <a href="manage_sku_master.php" class="wms-nav-item">
                        <span class="wms-nav-icon">🏷️</span>
                        <span class="wms-nav-text">SKU Master</span>
                    </a>
                    <a href="manage_clients.php" class="wms-nav-item">
                        <span class="wms-nav-icon">👥</span>
                        <span class="wms-nav-text">Clients</span>
                    </a>
                </div>

                <div class="wms-nav-group">
                    <div class="wms-nav-group-title">Tools</div>
                    <a href="rf_scanner.php" class="wms-nav-item">
                        <span class="wms-nav-icon">📱</span>
                        <span class="wms-nav-text">RF Scanner</span>
                    </a>
                    <a href="export_inventory_csv.php" class="wms-nav-item">
                        <span class="wms-nav-icon">📊</span>
                        <span class="wms-nav-text">Export Data</span>
                    </a>
                </div>

                <?php if ($_SESSION['role'] === 'admin'): ?>
                <div class="wms-nav-group">
                    <div class="wms-nav-group-title">Administration</div>
                    <a href="manage_users_secure.php" class="wms-nav-item">
                        <span class="wms-nav-icon">👤</span>
                        <span class="wms-nav-text">Users</span>
                    </a>
                    <a href="view_logs.php" class="wms-nav-item">
                        <span class="wms-nav-icon">📋</span>
                        <span class="wms-nav-text">Activity Logs</span>
                    </a>
                    <a href="auto_release_orders_secure.php?confirm=auto_release" class="wms-nav-item">
                        <span class="wms-nav-icon">⚡</span>
                        <span class="wms-nav-text">Auto Release</span>
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </nav>

        <!-- Content -->
        <main class="wms-content">
            <div class="wms-content-inner">
                <!-- Page Header -->
                <div class="wms-page-header wms-fade-in">
                    <h1 class="wms-page-title">Welcome back, <?= secure_escape($user_info['username']) ?>!</h1>
                    <p class="wms-page-subtitle">Here's what's happening in your warehouse today</p>
                    <div class="wms-page-actions">
                        <a href="outbound.php" class="wms-btn wms-btn-primary wms-btn-lg">
                            <span>📤</span> Create Order
                        </a>
                        <a href="create_asn.php" class="wms-btn wms-btn-secondary wms-btn-lg">
                            <span>📥</span> Create ASN
                        </a>
                        <a href="rf_scanner.php" class="wms-btn wms-btn-secondary wms-btn-lg">
                            <span>📱</span> RF Scanner
                        </a>
                    </div>
                </div>

                <!-- Statistics Grid -->
                <div class="wms-stats-grid wms-slide-in-right">
                    <div class="wms-stat-card">
                        <div class="wms-stat-header">
                            <div class="wms-stat-icon">📦</div>
                            <div class="wms-stat-change positive">
                                <span>↗</span> Active
                            </div>
                        </div>
                        <div class="wms-stat-value"><?= number_format($stats['inventory_count']) ?></div>
                        <div class="wms-stat-label">Inventory Items</div>
                    </div>

                    <div class="wms-stat-card">
                        <div class="wms-stat-header">
                            <div class="wms-stat-icon">📊</div>
                            <div class="wms-stat-change positive">
                                <span>↗</span> Total
                            </div>
                        </div>
                        <div class="wms-stat-value"><?= number_format($stats['total_items']) ?></div>
                        <div class="wms-stat-label">Total Quantity</div>
                    </div>

                    <div class="wms-stat-card warning">
                        <div class="wms-stat-header">
                            <div class="wms-stat-icon">📤</div>
                            <div class="wms-stat-change">
                                <span>→</span> Pending
                            </div>
                        </div>
                        <div class="wms-stat-value"><?= number_format($stats['active_orders']) ?></div>
                        <div class="wms-stat-label">Active Orders</div>
                    </div>

                    <div class="wms-stat-card success">
                        <div class="wms-stat-header">
                            <div class="wms-stat-icon">🚚</div>
                            <div class="wms-stat-change positive">
                                <span>↗</span> Today
                            </div>
                        </div>
                        <div class="wms-stat-value"><?= number_format($stats['shipped_today']) ?></div>
                        <div class="wms-stat-label">Shipped Today</div>
                    </div>

                    <div class="wms-stat-card <?= $stats['low_stock'] > 5 ? 'danger' : 'info' ?>">
                        <div class="wms-stat-header">
                            <div class="wms-stat-icon">⚠️</div>
                            <div class="wms-stat-change <?= $stats['low_stock'] > 5 ? 'negative' : '' ?>">
                                <span><?= $stats['low_stock'] > 5 ? '↗' : '→' ?></span> Alert
                            </div>
                        </div>
                        <div class="wms-stat-value"><?= number_format($stats['low_stock']) ?></div>
                        <div class="wms-stat-label">Low Stock Items</div>
                    </div>

                    <div class="wms-stat-card info">
                        <div class="wms-stat-header">
                            <div class="wms-stat-icon">🏷️</div>
                            <div class="wms-stat-change">
                                <span>→</span> Unique
                            </div>
                        </div>
                        <div class="wms-stat-value"><?= number_format($stats['unique_skus']) ?></div>
                        <div class="wms-stat-label">SKUs in Stock</div>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="quick-actions-grid wms-scale-in">
                    <!-- Inventory Management -->
                    <div class="quick-action-card">
                        <div class="quick-action-header">
                            <div class="quick-action-icon">📦</div>
                            <h3 class="quick-action-title">Inventory Management</h3>
                        </div>
                        <p class="quick-action-desc">Manage stock levels, locations, and inventory movements with real-time tracking</p>
                        <div class="quick-action-buttons">
                            <a href="secure-inventory.php" class="wms-btn wms-btn-primary wms-btn-sm">View Inventory</a>
                            <a href="inventory_add.php" class="wms-btn wms-btn-secondary wms-btn-sm">Add Stock</a>
                        </div>
                    </div>

                    <!-- Order Processing -->
                    <div class="quick-action-card">
                        <div class="quick-action-header">
                            <div class="quick-action-icon">📋</div>
                            <h3 class="quick-action-title">Order Processing</h3>
                        </div>
                        <p class="quick-action-desc">Complete order lifecycle from creation to shipping with automated workflows</p>
                        <div class="quick-action-buttons">
                            <a href="view_outbound.php" class="wms-btn wms-btn-primary wms-btn-sm">View Orders</a>
                            <a href="pick_order_secure.php" class="wms-btn wms-btn-success wms-btn-sm">Pick Orders</a>
                        </div>
                    </div>

                    <!-- Warehouse Operations -->
                    <div class="quick-action-card">
                        <div class="quick-action-header">
                            <div class="quick-action-icon">🏭</div>
                            <h3 class="quick-action-title">Warehouse Operations</h3>
                        </div>
                        <p class="quick-action-desc">Inbound receiving, putaway, and warehouse management operations</p>
                        <div class="quick-action-buttons">
                            <a href="inbound.php" class="wms-btn wms-btn-primary wms-btn-sm">Inbound</a>
                            <a href="rf_scanner.php" class="wms-btn wms-btn-secondary wms-btn-sm">RF Scanner</a>
                        </div>
                    </div>

                    <!-- Reports & Analytics -->
                    <div class="quick-action-card">
                        <div class="quick-action-header">
                            <div class="quick-action-icon">📊</div>
                            <h3 class="quick-action-title">Reports & Analytics</h3>
                        </div>
                        <p class="quick-action-desc">Comprehensive reporting and data analysis for operational insights</p>
                        <div class="quick-action-buttons">
                            <a href="export_inventory_csv.php" class="wms-btn wms-btn-primary wms-btn-sm">Export Data</a>
                            <a href="view_movements.php" class="wms-btn wms-btn-secondary wms-btn-sm">View Reports</a>
                        </div>
                    </div>
                </div>

                <!-- Recent Activity & Alerts -->
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; margin-top: 2rem;">
                    <!-- Recent Orders -->
                    <div class="wms-card wms-fade-in">
                        <div class="wms-card-header">
                            <h3 class="wms-card-title">Recent Orders</h3>
                            <a href="view_outbound.php" class="wms-btn wms-btn-secondary wms-btn-sm">View All</a>
                        </div>
                        <div class="wms-card-body">
                            <?php if (!empty($recent_orders)): ?>
                                <?php foreach ($recent_orders as $order): ?>
                                <div class="recent-activity-item">
                                    <div class="activity-icon order"><?= substr($order['status'], 0, 1) ?></div>
                                    <div style="flex: 1;">
                                        <div style="font-weight: 500; font-size: 0.875rem;">
                                            <?= secure_escape($order['order_number']) ?>
                                        </div>
                                        <div style="font-size: 0.75rem; color: var(--gray-600);">
                                            <?= secure_escape($order['client_name'] ?? 'Unknown Client') ?> •
                                            <?= secure_escape($order['sku']) ?> •
                                            Qty: <?= $order['qty_ordered'] ?>
                                        </div>
                                    </div>
                                    <div class="wms-badge wms-badge-<?= strtolower($order['status']) === 'hold' ? 'danger' : (strtolower($order['status']) === 'released' ? 'warning' : 'primary') ?>">
                                        <?= secure_escape($order['status']) ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p class="wms-text-center" style="color: var(--gray-500); margin: 2rem 0;">No recent orders</p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Low Stock Alerts -->
                    <div class="wms-card wms-fade-in">
                        <div class="wms-card-header">
                            <h3 class="wms-card-title">Low Stock Alerts</h3>
                            <a href="secure-inventory.php?low_stock=1" class="wms-btn wms-btn-secondary wms-btn-sm">View All</a>
                        </div>
                        <div class="wms-card-body">
                            <?php if (!empty($low_stock_items)): ?>
                                <?php foreach ($low_stock_items as $item): ?>
                                <div class="recent-activity-item">
                                    <div class="activity-icon warning">⚠️</div>
                                    <div style="flex: 1;">
                                        <div style="font-weight: 500; font-size: 0.875rem;">
                                            <?= secure_escape($item['sku_id']) ?>
                                        </div>
                                        <div style="font-size: 0.75rem; color: var(--gray-600);">
                                            <?= secure_escape($item['description'] ?? 'No description') ?><br>
                                            Location: <?= secure_escape($item['location_id']) ?>
                                        </div>
                                    </div>
                                    <div class="wms-badge wms-badge-danger">
                                        <?= $item['qty_on_hand'] ?> left
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="recent-activity-item">
                                    <div class="activity-icon success">✅</div>
                                    <div style="flex: 1;">
                                        <div style="font-weight: 500; font-size: 0.875rem; color: var(--success-600);">
                                            All stock levels are healthy
                                        </div>
                                        <div style="font-size: 0.75rem; color: var(--gray-600);">
                                            No items below minimum stock levels
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- User Menu Modal -->
    <div id="userModal" class="wms-modal">
        <div class="wms-modal-content" style="max-width: 400px;">
            <div class="wms-modal-header">
                <h3 class="wms-modal-title">User Settings</h3>
                <button class="wms-modal-close" onclick="closeUserMenu()">&times;</button>
            </div>
            <div class="wms-modal-body">
                <div style="text-align: center; margin-bottom: 2rem;">
                    <div class="wms-avatar" style="width: 64px; height: 64px; font-size: 1.5rem; margin: 0 auto 1rem;">
                        <?= strtoupper(substr($user_info['username'], 0, 1)) ?>
                    </div>
                    <div style="font-weight: 600; font-size: 1.125rem;"><?= secure_escape($user_info['username']) ?></div>
                    <div style="color: var(--gray-600); font-size: 0.875rem;"><?= secure_escape(ucfirst($user_info['role'])) ?></div>
                </div>

                <div style="margin-bottom: 1rem;">
                    <strong>Member since:</strong> <?= date('M d, Y', strtotime($user_info['created_at'])) ?>
                </div>
                <div style="margin-bottom: 1rem;">
                    <strong>Session activities:</strong> <?= $stats['recent_activities'] ?> (24h)
                </div>
            </div>
            <div class="wms-modal-footer">
                <a href="change_password.php" class="wms-btn wms-btn-primary">Change Password</a>
                <a href="logout.php" class="wms-btn wms-btn-danger">Logout</a>
                <button onclick="closeUserMenu()" class="wms-btn wms-btn-secondary">Close</button>
            </div>
        </div>
    </div>

    <script>
        // Global search functionality
        document.getElementById('globalSearch').addEventListener('keyup', function(e) {
            if (e.key === 'Enter') {
                const query = this.value.toLowerCase();
                // Simple redirect to relevant pages based on search terms
                if (query.includes('inventory') || query.includes('stock')) {
                    window.location.href = 'secure-inventory.php';
                } else if (query.includes('order')) {
                    window.location.href = 'view_outbound.php';
                } else if (query.includes('sku')) {
                    window.location.href = 'manage_sku_master.php';
                } else if (query.includes('user')) {
                    window.location.href = 'manage_users_secure.php';
                } else if (query.includes('inbound') || query.includes('asn')) {
                    window.location.href = 'inbound.php';
                } else if (query.includes('scanner') || query.includes('scan')) {
                    window.location.href = 'rf_scanner.php';
                } else if (query.includes('pick')) {
                    window.location.href = 'pick_order_secure.php';
                } else if (query.includes('ship')) {
                    window.location.href = 'ship_order_secure.php';
                } else {
                    // Default search in inventory
                    window.location.href = 'secure-inventory.php?description=' + encodeURIComponent(query);
                }
            }
        });

        // Sidebar functionality
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('collapsed');
            localStorage.setItem('sidebar-collapsed', document.getElementById('sidebar').classList.contains('collapsed'));
        }

        function toggleMobileMenu() {
            document.getElementById('sidebar').classList.toggle('mobile-open');
        }

        // User menu functions
        function showUserMenu() {
            document.getElementById('userModal').classList.add('active');
        }

        function closeUserMenu() {
            document.getElementById('userModal').classList.remove('active');
        }

        // Close modal when clicking outside
        document.getElementById('userModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeUserMenu();
            }
        });

        // Load saved sidebar state
        document.addEventListener('DOMContentLoaded', function() {
            if (localStorage.getItem('sidebar-collapsed') === 'true') {
                document.getElementById('sidebar').classList.add('collapsed');
            }

            // Add animation delays to cards
            const cards = document.querySelectorAll('.wms-stat-card, .quick-action-card');
            cards.forEach((card, index) => {
                card.style.animationDelay = `${index * 0.1}s`;
            });
        });

        // Auto-refresh stats every 5 minutes
        setInterval(function() {
            if (document.visibilityState === 'visible') {
                fetch(window.location.href)
                    .then(response => response.text())
                    .then(html => {
                        const parser = new DOMParser();
                        const doc = parser.parseFromString(html, 'text/html');
                        const newStats = doc.querySelectorAll('.wms-stat-value');
                        const currentStats = document.querySelectorAll('.wms-stat-value');

                        newStats.forEach((newStat, index) => {
                            if (currentStats[index] && newStat.textContent !== currentStats[index].textContent) {
                                currentStats[index].textContent = newStat.textContent;
                                currentStats[index].style.animation = 'wms-scale-in 0.5s ease-out';
                            }
                        });
                    })
                    .catch(err => console.log('Auto-refresh failed:', err));
            }
        }, 300000); // 5 minutes

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey || e.metaKey) {
                switch(e.key) {
                    case 'k':
                        e.preventDefault();
                        document.getElementById('globalSearch').focus();
                        break;
                    case 'b':
                        e.preventDefault();
                        toggleSidebar();
                        break;
                }
            }
        });
    </script>
</body>
</html>

<?php $conn->close(); ?>
