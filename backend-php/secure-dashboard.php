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
    $inv_result = secure_select_one($conn, "SELECT COUNT(*) as total FROM inventory");
    $stats['inventory_count'] = $inv_result['total'] ?? 0;

    // Get orders count
    $order_result = secure_select_one($conn, "SELECT COUNT(*) as total FROM outbound_orders");
    $stats['order_count'] = $order_result['total'] ?? 0;

    // Get low stock alerts
    $low_stock_result = secure_select_one($conn, "SELECT COUNT(*) as total FROM inventory WHERE qty_on_hand < 5");
    $stats['low_stock'] = $low_stock_result['total'] ?? 0;

    // Get pending ASNs
    $asn_result = secure_select_one($conn, "SELECT COUNT(*) as total FROM asn_header WHERE status != 'Completed'");
    $stats['pending_asns'] = $asn_result['total'] ?? 0;

    // Get recent activities (last 24 hours)
    $activity_result = secure_select_one($conn,
        "SELECT COUNT(*) as total FROM audit_log WHERE timestamp >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"
    );
    $stats['recent_activities'] = $activity_result['total'] ?? 0;

} catch (Exception $e) {
    error_log("Dashboard stats error: " . $e->getMessage());
    // Set default values if queries fail
    $stats = [
        'inventory_count' => 0,
        'order_count' => 0,
        'low_stock' => 0,
        'pending_asns' => 0,
        'recent_activities' => 0
    ];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ECWMS Dashboard</title>
    <link rel="stylesheet" href="modern-style.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        .quick-action-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
            margin-top: 2rem;
        }

        .quick-action-card {
            background: white;
            border-radius: var(--border-radius-lg);
            padding: 1.5rem;
            box-shadow: var(--box-shadow);
            border-left: 4px solid var(--primary-blue);
            transition: var(--transition);
        }

        .quick-action-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--box-shadow-lg);
        }

        .quick-action-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .quick-action-icon {
            font-size: 2rem;
            width: 60px;
            height: 60px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, var(--primary-blue), var(--primary-blue-light));
            color: white;
            border-radius: var(--border-radius);
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
        }

        .quick-action-links {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
        }

        .quick-action-links .btn {
            font-size: 0.75rem;
            padding: 0.5rem 1rem;
        }
    </style>
</head>
<body class="wms-layout">
    <!-- Header -->
    <header class="wms-header">
        <div class="wms-logo">
            <span>📦</span>
            <span>ECWMS</span>
        </div>

        <div class="wms-search">
            <input type="text" placeholder="Search modules, orders, inventory..." id="globalSearch">
        </div>

        <div class="wms-user-menu">
            <span>👤 <?= secure_escape($user_info['username']) ?></span>
            <span class="badge badge-primary"><?= secure_escape($user_info['role']) ?></span>
            <button class="btn btn-secondary btn-sm" onclick="showUserMenu()">⚙️</button>
            <a href="logout.php" class="btn btn-danger btn-sm">🔒 Logout</a>
        </div>
    </header>

    <!-- Main Content -->
    <main class="wms-content">
        <div class="fade-in">
            <!-- Welcome Section -->
            <div class="mb-4">
                <h1>Welcome back, <?= secure_escape($user_info['username']) ?>!</h1>
                <p class="text-secondary">Here's what's happening in your warehouse today</p>
            </div>

            <!-- Dashboard Statistics -->
            <div class="dashboard-grid">
                <div class="stat-card">
                    <div class="stat-value text-primary"><?= number_format($stats['inventory_count']) ?></div>
                    <div class="stat-label">Total Inventory Items</div>
                </div>

                <div class="stat-card">
                    <div class="stat-value text-success"><?= number_format($stats['order_count']) ?></div>
                    <div class="stat-label">Total Orders</div>
                </div>

                <div class="stat-card" style="border-left-color: <?= $stats['low_stock'] > 0 ? 'var(--danger-red)' : 'var(--success-green)' ?>">
                    <div class="stat-value" style="color: <?= $stats['low_stock'] > 0 ? 'var(--danger-red)' : 'var(--success-green)' ?>"><?= number_format($stats['low_stock']) ?></div>
                    <div class="stat-label">Low Stock Alerts</div>
                </div>

                <div class="stat-card" style="border-left-color: var(--accent-amber)">
                    <div class="stat-value" style="color: var(--accent-amber)"><?= number_format($stats['pending_asns']) ?></div>
                    <div class="stat-label">Pending ASNs</div>
                </div>

                <div class="stat-card" style="border-left-color: var(--accent-emerald)">
                    <div class="stat-value" style="color: var(--accent-emerald)"><?= number_format($stats['recent_activities']) ?></div>
                    <div class="stat-label">Activities (24h)</div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="quick-action-grid">
                <!-- Inventory Management -->
                <div class="quick-action-card">
                    <div class="quick-action-header">
                        <div class="quick-action-icon">📦</div>
                        <div>
                            <h3 class="quick-action-title">Inventory Management</h3>
                        </div>
                    </div>
                    <p class="quick-action-desc">Manage stock levels, locations, and inventory movements</p>
                    <div class="quick-action-links">
                        <a href="view_inventory.php" class="btn btn-primary">View Inventory</a>
                        <a href="inventory_add.php" class="btn btn-secondary">Add Stock</a>
                        <a href="view_movements.php" class="btn btn-secondary">Movement History</a>
                    </div>
                </div>

                <!-- Inbound Operations -->
                <div class="quick-action-card">
                    <div class="quick-action-header">
                        <div class="quick-action-icon">📥</div>
                        <div>
                            <h3 class="quick-action-title">Inbound Operations</h3>
                        </div>
                    </div>
                    <p class="quick-action-desc">Process incoming shipments and manage ASNs</p>
                    <div class="quick-action-links">
                        <a href="inbound.php" class="btn btn-primary">Inbound Dashboard</a>
                        <a href="create_asn.php" class="btn btn-secondary">Create ASN</a>
                        <a href="putaway.php" class="btn btn-secondary">Putaway</a>
                    </div>
                </div>

                <!-- Outbound Operations -->
                <div class="quick-action-card">
                    <div class="quick-action-header">
                        <div class="quick-action-icon">📤</div>
                        <div>
                            <h3 class="quick-action-title">Outbound Operations</h3>
                        </div>
                    </div>
                    <p class="quick-action-desc">Process orders and manage shipments</p>
                    <div class="quick-action-links">
                        <a href="view_outbound.php" class="btn btn-primary">View Orders</a>
                        <a href="outbound.php" class="btn btn-secondary">Create Order</a>
                        <a href="pick_order.php" class="btn btn-secondary">Pick Orders</a>
                        <a href="ship_order.php" class="btn btn-secondary">Ship Orders</a>
                    </div>
                </div>

                <!-- SKU & Product Management -->
                <div class="quick-action-card">
                    <div class="quick-action-header">
                        <div class="quick-action-icon">🏷️</div>
                        <div>
                            <h3 class="quick-action-title">SKU Management</h3>
                        </div>
                    </div>
                    <p class="quick-action-desc">Manage product information and SKU master data</p>
                    <div class="quick-action-links">
                        <a href="manage_sku_master.php" class="btn btn-primary">SKU Master</a>
                        <a href="add_sku.php" class="btn btn-secondary">Add SKU</a>
                        <a href="sku.php" class="btn btn-secondary">View All SKUs</a>
                    </div>
                </div>

                <!-- RF Scanner -->
                <div class="quick-action-card">
                    <div class="quick-action-header">
                        <div class="quick-action-icon">📱</div>
                        <div>
                            <h3 class="quick-action-title">RF Scanner</h3>
                        </div>
                    </div>
                    <p class="quick-action-desc">Barcode scanning for warehouse operations</p>
                    <div class="quick-action-links">
                        <a href="rf_scanner.php" class="btn btn-primary">Open Scanner</a>
                    </div>
                </div>

                <!-- Reports & Analytics -->
                <div class="quick-action-card">
                    <div class="quick-action-header">
                        <div class="quick-action-icon">📊</div>
                        <div>
                            <h3 class="quick-action-title">Reports & Analytics</h3>
                        </div>
                    </div>
                    <p class="quick-action-desc">View reports and export data for analysis</p>
                    <div class="quick-action-links">
                        <a href="export_inventory_csv.php" class="btn btn-primary">Export Inventory</a>
                        <a href="export_movements.php" class="btn btn-secondary">Export Movements</a>
                        <a href="view_logs.php" class="btn btn-secondary">Activity Logs</a>
                    </div>
                </div>

                <?php if ($_SESSION['role'] === 'admin'): ?>
                <!-- Administration -->
                <div class="quick-action-card">
                    <div class="quick-action-header">
                        <div class="quick-action-icon">⚙️</div>
                        <div>
                            <h3 class="quick-action-title">Administration</h3>
                        </div>
                    </div>
                    <p class="quick-action-desc">System administration and user management</p>
                    <div class="quick-action-links">
                        <a href="manage_users.php" class="btn btn-primary">Manage Users</a>
                        <a href="manage_clients.php" class="btn btn-secondary">Manage Clients</a>
                        <a href="view_logs.php" class="btn btn-secondary">System Logs</a>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Recent Activity -->
            <?php if ($stats['recent_activities'] > 0): ?>
            <div class="card mt-4">
                <div class="card-header">
                    <h3 class="card-title">Recent Activity</h3>
                </div>
                <div class="card-body">
                    <div class="text-center">
                        <p class="text-secondary">You have <?= $stats['recent_activities'] ?> activities in the last 24 hours</p>
                        <a href="view_logs.php" class="btn btn-primary">View Details</a>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </main>

    <!-- User Menu Modal -->
    <div id="userModal" class="modal">
        <div class="modal-content" style="max-width: 400px;">
            <div class="modal-header">
                <h3 class="modal-title">User Settings</h3>
                <button onclick="closeUserMenu()" style="background: none; border: none; font-size: 1.5rem;">&times;</button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <strong>Username:</strong> <?= secure_escape($user_info['username']) ?>
                </div>
                <div class="mb-3">
                    <strong>Role:</strong> <?= secure_escape($user_info['role']) ?>
                </div>
                <div class="mb-3">
                    <strong>Member since:</strong> <?= date('M d, Y', strtotime($user_info['created_at'])) ?>
                </div>
            </div>
            <div class="modal-footer">
                <a href="change_password.php" class="btn btn-primary">Change Password</a>
                <button onclick="closeUserMenu()" class="btn btn-secondary">Close</button>
            </div>
        </div>
    </div>

    <script>
        // Global search functionality
        document.getElementById('globalSearch').addEventListener('keyup', function(e) {
            if (e.key === 'Enter') {
                const query = this.value.toLowerCase();
                // Simple redirect to relevant pages based on search terms
                if (query.includes('inventory')) {
                    window.location.href = 'view_inventory.php';
                } else if (query.includes('order')) {
                    window.location.href = 'view_outbound.php';
                } else if (query.includes('sku')) {
                    window.location.href = 'manage_sku_master.php';
                } else if (query.includes('user')) {
                    window.location.href = 'manage_users.php';
                } else if (query.includes('inbound')) {
                    window.location.href = 'inbound.php';
                } else if (query.includes('scanner')) {
                    window.location.href = 'rf_scanner.php';
                }
            }
        });

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

        // Add loading states to buttons
        document.querySelectorAll('.btn').forEach(btn => {
            btn.addEventListener('click', function() {
                if (this.href || this.onclick) {
                    this.classList.add('loading');
                    setTimeout(() => {
                        this.classList.remove('loading');
                    }, 2000);
                }
            });
        });

        // Add fade-in animation
        document.addEventListener('DOMContentLoaded', function() {
            const cards = document.querySelectorAll('.quick-action-card, .stat-card');
            cards.forEach((card, index) => {
                setTimeout(() => {
                    card.classList.add('fade-in');
                }, index * 100);
            });
        });

        // Auto-refresh stats every 5 minutes
        setInterval(function() {
            fetch(window.location.href)
                .then(response => response.text())
                .then(html => {
                    const parser = new DOMParser();
                    const doc = parser.parseFromString(html, 'text/html');
                    const newStats = doc.querySelectorAll('.stat-value');
                    const currentStats = document.querySelectorAll('.stat-value');

                    newStats.forEach((newStat, index) => {
                        if (currentStats[index] && newStat.textContent !== currentStats[index].textContent) {
                            currentStats[index].textContent = newStat.textContent;
                            currentStats[index].style.animation = 'fadeIn 0.5s ease-out';
                        }
                    });
                })
                .catch(err => console.log('Auto-refresh failed:', err));
        }, 300000); // 5 minutes
    </script>
</body>
</html>

<?php $conn->close(); ?>
