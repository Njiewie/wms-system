<?php
/**
 * Secure Inbound Dashboard
 * Enhanced with comprehensive security measures
 *
 * Security Features:
 * - CSRF Protection
 * - SQL Injection Prevention
 * - Input Validation & Sanitization
 * - XSS Prevention
 * - Activity Logging
 * - Access Control
 * - Rate Limiting
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

// Initialize variables
$message = "";
$errors = [];
$asn_list = [];
$dashboard_stats = [];

// Handle messages from redirects
if (isset($_GET['message'])) {
    $message = "✅ " . htmlspecialchars($_GET['message']);
} elseif (isset($_GET['error'])) {
    $message = "❌ " . htmlspecialchars($_GET['error']);
}

/**
 * Get dashboard statistics
 */
function getDashboardStats($conn) {
    $stats = [
        'total_asns' => 0,
        'pending_asns' => 0,
        'in_progress_asns' => 0,
        'completed_asns' => 0,
        'total_lines' => 0,
        'pending_lines' => 0,
        'recent_activity' => []
    ];

    try {
        // ASN counts by status
        $asn_stats = secure_select_all($conn,
            "SELECT status, COUNT(*) as count
             FROM asn_header
             WHERE deleted_at IS NULL
             GROUP BY status"
        );

        foreach ($asn_stats as $stat) {
            $stats['total_asns'] += $stat['count'];

            switch (strtolower($stat['status'])) {
                case 'pending':
                    $stats['pending_asns'] = $stat['count'];
                    break;
                case 'in progress':
                    $stats['in_progress_asns'] = $stat['count'];
                    break;
                case 'completed':
                    $stats['completed_asns'] = $stat['count'];
                    break;
            }
        }

        // Line counts
        $line_stats = secure_select_one($conn,
            "SELECT
                COUNT(*) as total_lines,
                SUM(CASE WHEN ah.status != 'Completed' THEN 1 ELSE 0 END) as pending_lines
             FROM asn_lines al
             JOIN asn_header ah ON al.asn_number = ah.asn_number
             WHERE al.deleted_at IS NULL AND ah.deleted_at IS NULL"
        );

        if ($line_stats) {
            $stats['total_lines'] = (int)$line_stats['total_lines'];
            $stats['pending_lines'] = (int)$line_stats['pending_lines'];
        }

        // Recent activity
        $stats['recent_activity'] = secure_select_all($conn,
            "SELECT al.action, al.details, al.created_at, u.username
             FROM activity_logs al
             LEFT JOIN users u ON al.user_id = u.id
             WHERE al.action LIKE '%ASN%'
             ORDER BY al.created_at DESC
             LIMIT 10"
        );

    } catch (Exception $e) {
        error_log("Dashboard stats error: " . $e->getMessage());
    }

    return $stats;
}

/**
 * Get ASN list with filtering and pagination
 */
function getASNList($conn, $filters = [], $limit = 50, $offset = 0) {
    $where_conditions = ["ah.deleted_at IS NULL"];
    $params = [];
    $types = "";

    // Apply filters
    if (!empty($filters['status'])) {
        $where_conditions[] = "ah.status = ?";
        $params[] = $filters['status'];
        $types .= "s";
    }

    if (!empty($filters['supplier'])) {
        $where_conditions[] = "ah.supplier_name LIKE ?";
        $params[] = "%" . $filters['supplier'] . "%";
        $types .= "s";
    }

    if (!empty($filters['date_from'])) {
        $where_conditions[] = "ah.arrival_date >= ?";
        $params[] = $filters['date_from'];
        $types .= "s";
    }

    if (!empty($filters['date_to'])) {
        $where_conditions[] = "ah.arrival_date <= ?";
        $params[] = $filters['date_to'];
        $types .= "s";
    }

    $where_clause = implode(" AND ", $where_conditions);

    // Add pagination
    $params[] = $limit;
    $params[] = $offset;
    $types .= "ii";

    return secure_select_all($conn,
        "SELECT ah.asn_number, ah.supplier_name, ah.arrival_date, ah.status,
                ah.created_at, ah.updated_at, c.client_name,
                COUNT(al.line_id) as line_count,
                SUM(al.qty_expected) as total_expected,
                SUM(al.qty_received) as total_received,
                u.username as created_by_name
         FROM asn_header ah
         LEFT JOIN asn_lines al ON ah.asn_number = al.asn_number
         LEFT JOIN clients c ON ah.client_id = c.id
         LEFT JOIN users u ON ah.created_by = u.id
         WHERE $where_clause
         GROUP BY ah.asn_number, ah.supplier_name, ah.arrival_date, ah.status,
                  ah.created_at, ah.updated_at, c.client_name, u.username
         ORDER BY ah.created_at DESC
         LIMIT ? OFFSET ?",
        $types,
        $params
    );
}

// Handle filtering
$filters = [];
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!empty($_GET['status'])) {
        $filters['status'] = $security->sanitizeInput($_GET['status']);
    }
    if (!empty($_GET['supplier'])) {
        $filters['supplier'] = $security->sanitizeInput($_GET['supplier']);
    }
    if (!empty($_GET['date_from'])) {
        $filters['date_from'] = $security->sanitizeInput($_GET['date_from']);
    }
    if (!empty($_GET['date_to'])) {
        $filters['date_to'] = $security->sanitizeInput($_GET['date_to']);
    }
}

// Get dashboard data
try {
    $dashboard_stats = getDashboardStats($conn);
    $asn_list = getASNList($conn, $filters);

    // Log dashboard access
    $security->logActivity($_SESSION['user_id'], 'INBOUND_DASHBOARD_ACCESSED',
        "Filters: " . json_encode($filters));

} catch (Exception $e) {
    $message = "❌ Error loading dashboard data: " . htmlspecialchars($e->getMessage());
    error_log("Inbound Dashboard Error: " . $e->getMessage() . " | User: " . $_SESSION['user_id']);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Inbound Dashboard - Secure WMS">
    <title>Inbound Dashboard | Secure WMS</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="modern-style.css">
    <style>
        .dashboard-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 2rem;
        }

        .dashboard-header {
            text-align: center;
            margin-bottom: 3rem;
            padding: 2rem;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 12px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
        }

        .quick-actions {
            display: flex;
            gap: 1rem;
            justify-content: center;
            margin-top: 1.5rem;
            flex-wrap: wrap;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 3rem;
        }

        .stat-card {
            background: white;
            padding: 2rem;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            text-align: center;
            transition: transform 0.2s;
        }

        .stat-card:hover {
            transform: translateY(-2px);
        }

        .stat-number {
            font-size: 3rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .stat-label {
            color: #6b7280;
            font-weight: 500;
        }

        .stat-pending { color: #f59e0b; }
        .stat-progress { color: #3b82f6; }
        .stat-completed { color: #10b981; }
        .stat-total { color: #6366f1; }

        .filters-section {
            background: white;
            padding: 1.5rem;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            margin-bottom: 2rem;
        }

        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            align-items: end;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
        }

        .filter-label {
            font-weight: 600;
            color: #374151;
            margin-bottom: 0.5rem;
        }

        .filter-control {
            padding: 10px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.3s;
        }

        .filter-control:focus {
            outline: none;
            border-color: #4f46e5;
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }

        .asn-list-section {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            overflow: hidden;
        }

        .section-header {
            background: #f8fafc;
            padding: 1.5rem;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .asn-table {
            width: 100%;
            border-collapse: collapse;
        }

        .asn-table th {
            background: #f8fafc;
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            color: #374151;
            border-bottom: 2px solid #e2e8f0;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .asn-table td {
            padding: 1rem;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: top;
        }

        .asn-table tbody tr:hover {
            background: #f8fafc;
        }

        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 500;
            text-transform: uppercase;
        }

        .status-pending { background: #fef3c7; color: #92400e; }
        .status-released { background: #dbeafe; color: #1e40af; }
        .status-in-progress { background: #dbeafe; color: #1e40af; }
        .status-completed { background: #d1fae5; color: #065f46; }
        .status-hold { background: #fee2e2; color: #991b1b; }
        .status-deleted { background: #f3f4f6; color: #6b7280; }

        .progress-bar {
            width: 100%;
            height: 6px;
            background: #e2e8f0;
            border-radius: 3px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #10b981, #059669);
            transition: width 0.3s ease;
        }

        .progress-text {
            font-size: 0.75rem;
            color: #6b7280;
            margin-top: 0.25rem;
        }

        .btn {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 6px;
            font-weight: 500;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
            transition: all 0.2s;
            font-size: 0.875rem;
        }

        .btn-primary {
            background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(79, 70, 229, 0.4);
        }

        .btn-secondary {
            background: #f3f4f6;
            color: #374151;
        }

        .btn-secondary:hover {
            background: #e5e7eb;
        }

        .btn-success {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
        }

        .btn-warning {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            color: white;
        }

        .btn-danger {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            color: white;
        }

        .action-buttons {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .empty-state {
            text-align: center;
            padding: 3rem;
            color: #6b7280;
        }

        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
        }

        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border-left: 4px solid #10b981;
        }

        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border-left: 4px solid #ef4444;
        }

        .activity-feed {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            padding: 1.5rem;
            margin-top: 2rem;
        }

        .activity-item {
            display: flex;
            align-items: start;
            gap: 1rem;
            padding: 1rem 0;
            border-bottom: 1px solid #e2e8f0;
        }

        .activity-item:last-child {
            border-bottom: none;
        }

        .activity-icon {
            width: 2rem;
            height: 2rem;
            border-radius: 50%;
            background: #e0e7ff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.875rem;
        }

        .activity-content {
            flex: 1;
        }

        .activity-title {
            font-weight: 600;
            color: #374151;
            margin-bottom: 0.25rem;
        }

        .activity-details {
            color: #6b7280;
            font-size: 0.875rem;
        }

        .activity-time {
            color: #9ca3af;
            font-size: 0.75rem;
        }

        @media (max-width: 768px) {
            .dashboard-container {
                padding: 1rem;
            }

            .dashboard-header {
                padding: 1rem;
            }

            .quick-actions {
                flex-direction: column;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .filters-grid {
                grid-template-columns: 1fr;
            }

            .asn-table {
                font-size: 0.875rem;
            }

            .asn-table th,
            .asn-table td {
                padding: 0.5rem;
            }

            .action-buttons {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Dashboard Header -->
        <div class="dashboard-header">
            <h1 style="margin: 0; font-size: 2.5rem;">📦 Inbound Dashboard</h1>
            <p style="margin: 0.5rem 0 0; font-size: 1.25rem; opacity: 0.9;">
                Advanced Shipping Notice Management
            </p>

            <div class="quick-actions">
                <a href="create_asn_secure.php" class="btn btn-primary">
                    ➕ Create New ASN
                </a>
                <a href="asn_process_secure.php" class="btn btn-success">
                    🚚 Process ASNs
                </a>
                <a href="secure-dashboard.php" class="btn btn-secondary">
                    🏠 Main Dashboard
                </a>
            </div>
        </div>

        <!-- Messages -->
        <?php if ($message): ?>
            <div class="alert <?= strpos($message, '✅') !== false ? 'alert-success' : 'alert-error' ?>">
                <?= $message ?>
            </div>
        <?php endif; ?>

        <!-- Dashboard Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number stat-total"><?= number_format($dashboard_stats['total_asns']) ?></div>
                <div class="stat-label">Total ASNs</div>
            </div>
            <div class="stat-card">
                <div class="stat-number stat-pending"><?= number_format($dashboard_stats['pending_asns']) ?></div>
                <div class="stat-label">Pending ASNs</div>
            </div>
            <div class="stat-card">
                <div class="stat-number stat-progress"><?= number_format($dashboard_stats['in_progress_asns']) ?></div>
                <div class="stat-label">In Progress</div>
            </div>
            <div class="stat-card">
                <div class="stat-number stat-completed"><?= number_format($dashboard_stats['completed_asns']) ?></div>
                <div class="stat-label">Completed</div>
            </div>
        </div>

        <!-- Filters Section -->
        <div class="filters-section">
            <h3 style="margin: 0 0 1rem; color: #374151;">Filter ASNs</h3>
            <form method="GET" class="filters-grid">
                <div class="filter-group">
                    <label class="filter-label">Status</label>
                    <select name="status" class="filter-control">
                        <option value="">All Statuses</option>
                        <option value="Pending" <?= ($filters['status'] ?? '') === 'Pending' ? 'selected' : '' ?>>Pending</option>
                        <option value="Released" <?= ($filters['status'] ?? '') === 'Released' ? 'selected' : '' ?>>Released</option>
                        <option value="In Progress" <?= ($filters['status'] ?? '') === 'In Progress' ? 'selected' : '' ?>>In Progress</option>
                        <option value="Completed" <?= ($filters['status'] ?? '') === 'Completed' ? 'selected' : '' ?>>Completed</option>
                        <option value="Hold" <?= ($filters['status'] ?? '') === 'Hold' ? 'selected' : '' ?>>Hold</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label class="filter-label">Supplier</label>
                    <input type="text" name="supplier" class="filter-control"
                           placeholder="Search supplier..."
                           value="<?= htmlspecialchars($filters['supplier'] ?? '') ?>">
                </div>
                <div class="filter-group">
                    <label class="filter-label">From Date</label>
                    <input type="date" name="date_from" class="filter-control"
                           value="<?= htmlspecialchars($filters['date_from'] ?? '') ?>">
                </div>
                <div class="filter-group">
                    <label class="filter-label">To Date</label>
                    <input type="date" name="date_to" class="filter-control"
                           value="<?= htmlspecialchars($filters['date_to'] ?? '') ?>">
                </div>
                <div class="filter-group">
                    <button type="submit" class="btn btn-primary" style="margin-top: 1.75rem;">
                        🔍 Filter
                    </button>
                </div>
            </form>
        </div>

        <!-- ASN List -->
        <div class="asn-list-section">
            <div class="section-header">
                <h2 style="margin: 0; color: #374151;">ASN List</h2>
                <span style="color: #6b7280;">
                    <?= count($asn_list) ?> ASN(s) found
                </span>
            </div>

            <?php if (!empty($asn_list)): ?>
                <div style="overflow-x: auto;">
                    <table class="asn-table">
                        <thead>
                            <tr>
                                <th>ASN Number</th>
                                <th>Supplier</th>
                                <th>Status</th>
                                <th>Arrival Date</th>
                                <th>Lines</th>
                                <th>Progress</th>
                                <th>Created By</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($asn_list as $asn): ?>
                                <?php
                                $completion_rate = $asn['total_expected'] > 0 ?
                                    round(($asn['total_received'] / $asn['total_expected']) * 100, 1) : 0;
                                ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($asn['asn_number']) ?></strong>
                                        <?php if ($asn['client_name']): ?>
                                            <br><small style="color: #6b7280;"><?= htmlspecialchars($asn['client_name']) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($asn['supplier_name']) ?></td>
                                    <td>
                                        <span class="status-badge status-<?= strtolower(str_replace(' ', '-', $asn['status'])) ?>">
                                            <?= htmlspecialchars($asn['status']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?= $asn['arrival_date'] ? date('M d, Y', strtotime($asn['arrival_date'])) : '-' ?>
                                    </td>
                                    <td>
                                        <strong><?= number_format($asn['line_count']) ?></strong> lines<br>
                                        <small style="color: #6b7280;">
                                            <?= number_format($asn['total_expected']) ?> expected
                                        </small>
                                    </td>
                                    <td>
                                        <div class="progress-bar">
                                            <div class="progress-fill" style="width: <?= $completion_rate ?>%"></div>
                                        </div>
                                        <div class="progress-text"><?= $completion_rate ?>% received</div>
                                    </td>
                                    <td>
                                        <?= htmlspecialchars($asn['created_by_name'] ?? 'Unknown') ?><br>
                                        <small style="color: #6b7280;">
                                            <?= date('M d, Y', strtotime($asn['created_at'])) ?>
                                        </small>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <a href="asn_lines_secure.php?asn_number=<?= urlencode($asn['asn_number']) ?>"
                                               class="btn btn-secondary">
                                                👁️ View
                                            </a>
                                            <?php if ($asn['status'] !== 'Completed'): ?>
                                            <a href="edit_asn_secure.php?asn_number=<?= urlencode($asn['asn_number']) ?>"
                                               class="btn btn-primary">
                                                ✏️ Edit
                                            </a>
                                            <?php endif; ?>
                                            <?php if ($asn['status'] !== 'Completed' && $completion_rate < 100): ?>
                                            <a href="asn_process_secure.php?asn_number=<?= urlencode($asn['asn_number']) ?>"
                                               class="btn btn-success">
                                                🚚 Process
                                            </a>
                                            <?php endif; ?>
                                            <?php if ($asn['status'] !== 'Completed'): ?>
                                            <a href="delete_asn_secure.php?asn_number=<?= urlencode($asn['asn_number']) ?>"
                                               class="btn btn-danger"
                                               onclick="return confirm('Are you sure you want to delete this ASN?')">
                                                🗑️ Delete
                                            </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <h3>No ASNs Found</h3>
                    <p>No ASNs match your current filters.</p>
                    <a href="create_asn_secure.php" class="btn btn-primary">
                        ➕ Create Your First ASN
                    </a>
                </div>
            <?php endif; ?>
        </div>

        <!-- Recent Activity Feed -->
        <?php if (!empty($dashboard_stats['recent_activity'])): ?>
        <div class="activity-feed">
            <h3 style="margin: 0 0 1rem; color: #374151;">Recent Activity</h3>
            <?php foreach (array_slice($dashboard_stats['recent_activity'], 0, 5) as $activity): ?>
                <div class="activity-item">
                    <div class="activity-icon">
                        📦
                    </div>
                    <div class="activity-content">
                        <div class="activity-title">
                            <?= htmlspecialchars($activity['action']) ?>
                        </div>
                        <div class="activity-details">
                            <?= htmlspecialchars($activity['details']) ?>
                            <?php if ($activity['username']): ?>
                                by <?= htmlspecialchars($activity['username']) ?>
                            <?php endif; ?>
                        </div>
                        <div class="activity-time">
                            <?= date('M d, Y H:i', strtotime($activity['created_at'])) ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <script>
        // Auto-refresh dashboard data every 60 seconds
        setInterval(function() {
            if (document.visibilityState === 'visible') {
                // Could implement live updates here
                console.log('Auto-refresh would update dashboard data');
            }
        }, 60000);

        // Real-time notifications (placeholder)
        function showNotification(message, type = 'info') {
            // Could implement toast notifications here
            console.log(`${type}: ${message}`);
        }

        // Quick filter shortcuts
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 'f') {
                e.preventDefault();
                document.querySelector('input[name="supplier"]').focus();
            }
        });

        // Enhanced table interactions
        document.querySelectorAll('.asn-table tbody tr').forEach(row => {
            row.addEventListener('click', function(e) {
                if (e.target.tagName !== 'A' && e.target.tagName !== 'BUTTON') {
                    const asnNumber = this.querySelector('td:first-child strong').textContent;
                    window.location.href = `asn_lines_secure.php?asn_number=${encodeURIComponent(asnNumber)}`;
                }
            });
        });
    </script>
</body>
</html>

<?php
// Clean up and close connections
if (isset($conn)) {
    $conn->close();
}

// Clean sensitive variables
unset($asn_list, $dashboard_stats, $filters);
?>
