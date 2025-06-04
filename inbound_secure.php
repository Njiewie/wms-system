<?php
/**
 * Secure Inbound Operations Dashboard
 * Comprehensive ASN (Advanced Shipping Notice) management with filtering, search, and statistics
 */

require_once 'auth.php';
require_once 'security-utils.php';
require_once 'db_config.php';

// Require authentication and proper role
require_login();

$security = SecurityUtils::getInstance();
$db = getDB();

// Check rate limiting
if (!$security->checkRateLimit()) {
    http_response_code(429);
    $security->logActivity('RATE_LIMIT_EXCEEDED', ['page' => 'inbound'], 'WARNING');
    die('Rate limit exceeded. Please try again later.');
}

$security->logActivity('INBOUND_DASHBOARD_ACCESS', ['user_id' => get_current_user_id()]);

$csrfToken = $security->generateCSRFToken();
$message = '';
$messageType = '';

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
        case 'get_asn_data':
            try {
                $filters = [
                    'search' => $security->sanitizeInput($_POST['search'] ?? ''),
                    'status' => $security->sanitizeInput($_POST['status'] ?? ''),
                    'supplier' => $security->sanitizeInput($_POST['supplier'] ?? ''),
                    'date_from' => $security->sanitizeInput($_POST['date_from'] ?? ''),
                    'date_to' => $security->sanitizeInput($_POST['date_to'] ?? ''),
                    'page' => max(1, (int) ($_POST['page'] ?? 1))
                ];
                
                $limit = 25;
                $offset = ($filters['page'] - 1) * $limit;
                
                // Build WHERE clause
                $whereConditions = ['a.deleted_at IS NULL'];
                $params = [];
                
                if (!empty($filters['search'])) {
                    $whereConditions[] = '(a.asn_number LIKE :search OR a.reference_number LIKE :search OR s.name LIKE :search)';
                    $params[':search'] = '%' . $filters['search'] . '%';
                }
                
                if (!empty($filters['status']) && $filters['status'] !== 'all') {
                    $whereConditions[] = 'a.status = :status';
                    $params[':status'] = $filters['status'];
                }
                
                if (!empty($filters['supplier']) && $filters['supplier'] !== 'all') {
                    $whereConditions[] = 'a.supplier_id = :supplier';
                    $params[':supplier'] = $filters['supplier'];
                }
                
                if (!empty($filters['date_from'])) {
                    $whereConditions[] = 'DATE(a.expected_date) >= :date_from';
                    $params[':date_from'] = $filters['date_from'];
                }
                
                if (!empty($filters['date_to'])) {
                    $whereConditions[] = 'DATE(a.expected_date) <= :date_to';
                    $params[':date_to'] = $filters['date_to'];
                }
                
                $whereClause = implode(' AND ', $whereConditions);
                
                // Get total count
                $countSql = "SELECT COUNT(*) as total FROM asn a 
                           LEFT JOIN suppliers s ON a.supplier_id = s.id 
                           WHERE {$whereClause}";
                $totalRecords = $db->fetchValue($countSql, $params);
                
                // Get paginated data
                $sql = "SELECT a.*, s.name as supplier_name, s.code as supplier_code,
                               COUNT(al.id) as line_count,
                               COALESCE(SUM(al.quantity), 0) as total_quantity,
                               COALESCE(SUM(al.received_quantity), 0) as total_received
                        FROM asn a
                        LEFT JOIN suppliers s ON a.supplier_id = s.id
                        LEFT JOIN asn_lines al ON a.id = al.asn_id AND al.deleted_at IS NULL
                        WHERE {$whereClause}
                        GROUP BY a.id
                        ORDER BY a.created_at DESC
                        LIMIT {$limit} OFFSET {$offset}";
                
                $asns = $db->fetchAll($sql, $params);
                
                // Format data for display
                foreach ($asns as &$asn) {
                    $asn['formatted_expected_date'] = date('M j, Y', strtotime($asn['expected_date']));
                    $asn['formatted_created_at'] = date('M j, Y g:i A', strtotime($asn['created_at']));
                    $asn['status_class'] = match($asn['status']) {
                        'draft' => 'badge-secondary',
                        'confirmed' => 'badge-primary',
                        'in_transit' => 'badge-warning',
                        'arrived' => 'badge-info',
                        'receiving' => 'badge-warning',
                        'completed' => 'badge-success',
                        'cancelled' => 'badge-danger',
                        default => 'badge-light'
                    };
                    $asn['progress_percentage'] = $asn['total_quantity'] > 0 ? 
                        round(($asn['total_received'] / $asn['total_quantity']) * 100, 1) : 0;
                }
                
                echo json_encode([
                    'success' => true,
                    'data' => $asns,
                    'total_records' => $totalRecords,
                    'current_page' => $filters['page'],
                    'total_pages' => ceil($totalRecords / $limit)
                ]);
                
            } catch (Exception $e) {
                $security->logActivity('ASN_DATA_FETCH_ERROR', ['error' => $e->getMessage()], 'ERROR');
                echo json_encode(['error' => 'Failed to fetch ASN data']);
            }
            exit();
            
        case 'get_dashboard_stats':
            try {
                $stats = [];
                
                // Total ASNs by status
                $statusStats = $db->fetchAll("
                    SELECT status, COUNT(*) as count 
                    FROM asn 
                    WHERE deleted_at IS NULL 
                    GROUP BY status
                ");
                
                $stats['status_counts'] = [];
                foreach ($statusStats as $stat) {
                    $stats['status_counts'][$stat['status']] = $stat['count'];
                }
                
                // ASNs expected today
                $stats['expected_today'] = $db->fetchValue("
                    SELECT COUNT(*) 
                    FROM asn 
                    WHERE DATE(expected_date) = CURDATE() 
                    AND status IN ('confirmed', 'in_transit') 
                    AND deleted_at IS NULL
                ");
                
                // Overdue ASNs
                $stats['overdue'] = $db->fetchValue("
                    SELECT COUNT(*) 
                    FROM asn 
                    WHERE expected_date < CURDATE() 
                    AND status IN ('confirmed', 'in_transit', 'arrived') 
                    AND deleted_at IS NULL
                ");
                
                // Total items pending receiving
                $stats['pending_items'] = $db->fetchValue("
                    SELECT COALESCE(SUM(al.quantity - al.received_quantity), 0)
                    FROM asn_lines al
                    JOIN asn a ON al.asn_id = a.id
                    WHERE a.status IN ('arrived', 'receiving')
                    AND al.deleted_at IS NULL
                    AND a.deleted_at IS NULL
                ");
                
                // Recent activity (last 7 days)
                $stats['recent_activity'] = $db->fetchAll("
                    SELECT DATE(created_at) as date, COUNT(*) as count
                    FROM asn
                    WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                    AND deleted_at IS NULL
                    GROUP BY DATE(created_at)
                    ORDER BY date DESC
                ");
                
                echo json_encode(['success' => true, 'stats' => $stats]);
                
            } catch (Exception $e) {
                $security->logActivity('DASHBOARD_STATS_ERROR', ['error' => $e->getMessage()], 'ERROR');
                echo json_encode(['error' => 'Failed to fetch dashboard statistics']);
            }
            exit();
            
        case 'update_asn_status':
            if (!has_role('supervisor')) {
                echo json_encode(['error' => 'Insufficient permissions']);
                exit();
            }
            
            try {
                $asnId = (int) ($_POST['asn_id'] ?? 0);
                $newStatus = $security->sanitizeInput($_POST['status'] ?? '');
                
                if (!$asnId || !$newStatus) {
                    echo json_encode(['error' => 'Invalid parameters']);
                    exit();
                }
                
                $validStatuses = ['draft', 'confirmed', 'in_transit', 'arrived', 'receiving', 'completed', 'cancelled'];
                if (!in_array($newStatus, $validStatuses)) {
                    echo json_encode(['error' => 'Invalid status']);
                    exit();
                }
                
                // Get current ASN
                $currentAsn = $db->fetchRow("SELECT * FROM asn WHERE id = :id AND deleted_at IS NULL", [':id' => $asnId]);
                if (!$currentAsn) {
                    echo json_encode(['error' => 'ASN not found']);
                    exit();
                }
                
                $db->beginTransaction();
                
                // Update ASN status
                $updated = $db->update('asn', [
                    'status' => $newStatus,
                    'updated_at' => date('Y-m-d H:i:s'),
                    'updated_by' => get_current_user_id()
                ], 'id = :id', [':id' => $asnId]);
                
                if ($updated) {
                    $security->logActivity('ASN_STATUS_UPDATED', [
                        'asn_id' => $asnId,
                        'asn_number' => $currentAsn['asn_number'],
                        'old_status' => $currentAsn['status'],
                        'new_status' => $newStatus
                    ]);
                    
                    $db->commit();
                    echo json_encode(['success' => true, 'message' => 'ASN status updated successfully']);
                } else {
                    $db->rollback();
                    echo json_encode(['error' => 'Failed to update ASN status']);
                }
                
            } catch (Exception $e) {
                $db->rollback();
                $security->logActivity('ASN_STATUS_UPDATE_ERROR', ['error' => $e->getMessage()], 'ERROR');
                echo json_encode(['error' => 'Failed to update ASN status']);
            }
            exit();
    }
}

// Get suppliers for filter dropdown
$suppliers = $db->fetchAll("SELECT id, name, code FROM suppliers WHERE is_active = 1 AND deleted_at IS NULL ORDER BY name");

// Get quick stats for dashboard cards
$quickStats = [
    'total_asns' => $db->fetchValue("SELECT COUNT(*) FROM asn WHERE deleted_at IS NULL"),
    'pending_asns' => $db->fetchValue("SELECT COUNT(*) FROM asn WHERE status IN ('confirmed', 'in_transit', 'arrived') AND deleted_at IS NULL"),
    'receiving_asns' => $db->fetchValue("SELECT COUNT(*) FROM asn WHERE status = 'receiving' AND deleted_at IS NULL"),
    'completed_today' => $db->fetchValue("SELECT COUNT(*) FROM asn WHERE status = 'completed' AND DATE(updated_at) = CURDATE() AND deleted_at IS NULL")
];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inbound Operations - WMS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        .stats-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
            padding: 2rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            transition: transform 0.3s ease;
        }
        .stats-card:hover {
            transform: translateY(-5px);
        }
        .stats-card h3 {
            font-size: 2.5rem;
            font-weight: bold;
            margin-bottom: 0.5rem;
        }
        .filter-section {
            background: #f8f9fa;
            padding: 1.5rem;
            border-radius: 10px;
            margin-bottom: 2rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        .table-container {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 20px rgba(0,0,0,0.08);
            overflow: hidden;
        }
        .progress-thin {
            height: 6px;
        }
        .badge-status {
            font-size: 0.75rem;
            padding: 0.35rem 0.75rem;
        }
        .action-buttons .btn {
            margin: 0 2px;
            padding: 0.25rem 0.5rem;
            font-size: 0.875rem;
        }
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(255, 255, 255, 0.8);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 9999;
        }
        .chart-container {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 2rem;
        }
    </style>
</head>
<body class="bg-light">
    <div class="loading-overlay" id="loadingOverlay">
        <div class="spinner-border text-primary" role="status">
            <span class="visually-hidden">Loading...</span>
        </div>
    </div>

    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container-fluid">
            <a class="navbar-brand" href="secure-dashboard.php">
                <i class="fas fa-warehouse me-2"></i>WMS - Inbound Operations
            </a>
            <div class="navbar-nav ms-auto">
                <span class="navbar-text me-3">
                    Welcome, <?php echo htmlspecialchars(get_user_full_name()); ?>
                </span>
                <a class="nav-link" href="secure-dashboard.php">
                    <i class="fas fa-arrow-left me-1"></i>Back to Dashboard
                </a>
            </div>
        </div>
    </nav>

    <div class="container-fluid py-4">
        <!-- Quick Stats Cards -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="stats-card text-center">
                    <h3><?php echo number_format($quickStats['total_asns']); ?></h3>
                    <p class="mb-0"><i class="fas fa-file-alt me-2"></i>Total ASNs</p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card text-center">
                    <h3><?php echo number_format($quickStats['pending_asns']); ?></h3>
                    <p class="mb-0"><i class="fas fa-clock me-2"></i>Pending</p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card text-center">
                    <h3><?php echo number_format($quickStats['receiving_asns']); ?></h3>
                    <p class="mb-0"><i class="fas fa-truck-loading me-2"></i>Receiving</p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card text-center">
                    <h3><?php echo number_format($quickStats['completed_today']); ?></h3>
                    <p class="mb-0"><i class="fas fa-check-circle me-2"></i>Completed Today</p>
                </div>
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center">
                    <h2><i class="fas fa-list me-2"></i>ASN Management</h2>
                    <div>
                        <a href="create_asn_secure.php" class="btn btn-success">
                            <i class="fas fa-plus me-2"></i>Create New ASN
                        </a>
                        <button class="btn btn-info ms-2" onclick="refreshData()">
                            <i class="fas fa-sync-alt me-2"></i>Refresh
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="filter-section">
            <h5><i class="fas fa-filter me-2"></i>Filters</h5>
            <div class="row">
                <div class="col-md-3">
                    <label class="form-label">Search</label>
                    <input type="text" class="form-control" id="searchInput" placeholder="ASN number, reference, supplier...">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Status</label>
                    <select class="form-select" id="statusFilter">
                        <option value="">All Statuses</option>
                        <option value="draft">Draft</option>
                        <option value="confirmed">Confirmed</option>
                        <option value="in_transit">In Transit</option>
                        <option value="arrived">Arrived</option>
                        <option value="receiving">Receiving</option>
                        <option value="completed">Completed</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Supplier</label>
                    <select class="form-select" id="supplierFilter">
                        <option value="">All Suppliers</option>
                        <?php foreach ($suppliers as $supplier): ?>
                            <option value="<?php echo $supplier['id']; ?>">
                                <?php echo htmlspecialchars($supplier['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Date From</label>
                    <input type="date" class="form-control" id="dateFromFilter">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Date To</label>
                    <input type="date" class="form-control" id="dateToFilter">
                </div>
                <div class="col-md-1 d-flex align-items-end">
                    <button class="btn btn-primary w-100" onclick="applyFilters()">
                        <i class="fas fa-search"></i>
                    </button>
                </div>
            </div>
        </div>

        <!-- ASN Table -->
        <div class="table-container">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th>ASN Number</th>
                            <th>Supplier</th>
                            <th>Status</th>
                            <th>Expected Date</th>
                            <th>Items</th>
                            <th>Progress</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="asnTableBody">
                        <tr>
                            <td colspan="8" class="text-center py-4">
                                <div class="spinner-border text-primary" role="status">
                                    <span class="visually-hidden">Loading...</span>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Pagination -->
        <div class="d-flex justify-content-between align-items-center mt-3">
            <div id="paginationInfo"></div>
            <nav>
                <ul class="pagination mb-0" id="paginationNav"></ul>
            </nav>
        </div>
    </div>

    <!-- Status Update Modal -->
    <div class="modal fade" id="statusModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Update ASN Status</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="statusForm">
                        <input type="hidden" id="statusAsnId">
                        <div class="mb-3">
                            <label class="form-label">New Status</label>
                            <select class="form-select" id="newStatus" required>
                                <option value="">Select Status</option>
                                <option value="draft">Draft</option>
                                <option value="confirmed">Confirmed</option>
                                <option value="in_transit">In Transit</option>
                                <option value="arrived">Arrived</option>
                                <option value="receiving">Receiving</option>
                                <option value="completed">Completed</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="updateStatus()">Update Status</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let currentPage = 1;
        let totalPages = 1;

        // Initialize page
        document.addEventListener('DOMContentLoaded', function() {
            loadAsnData();
            
            // Setup search with debounce
            let searchTimeout;
            document.getElementById('searchInput').addEventListener('input', function() {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(() => {
                    currentPage = 1;
                    loadAsnData();
                }, 500);
            });
        });

        function applyFilters() {
            currentPage = 1;
            loadAsnData();
        }

        function refreshData() {
            loadAsnData();
        }

        function loadAsnData() {
            showLoading(true);
            
            const formData = new FormData();
            formData.append('action', 'get_asn_data');
            formData.append('csrf_token', '<?php echo $csrfToken; ?>');
            formData.append('search', document.getElementById('searchInput').value);
            formData.append('status', document.getElementById('statusFilter').value);
            formData.append('supplier', document.getElementById('supplierFilter').value);
            formData.append('date_from', document.getElementById('dateFromFilter').value);
            formData.append('date_to', document.getElementById('dateToFilter').value);
            formData.append('page', currentPage);

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    renderAsnTable(data.data);
                    updatePagination(data.current_page, data.total_pages, data.total_records);
                } else {
                    showAlert('Error loading ASN data: ' + (data.error || 'Unknown error'), 'danger');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showAlert('Failed to load ASN data', 'danger');
            })
            .finally(() => {
                showLoading(false);
            });
        }

        function renderAsnTable(asns) {
            const tbody = document.getElementById('asnTableBody');
            
            if (asns.length === 0) {
                tbody.innerHTML = '<tr><td colspan="8" class="text-center py-4">No ASNs found</td></tr>';
                return;
            }

            tbody.innerHTML = asns.map(asn => `
                <tr>
                    <td>
                        <strong>${escapeHtml(asn.asn_number)}</strong>
                        ${asn.reference_number ? `<br><small class="text-muted">Ref: ${escapeHtml(asn.reference_number)}</small>` : ''}
                    </td>
                    <td>
                        <strong>${escapeHtml(asn.supplier_name)}</strong>
                        ${asn.supplier_code ? `<br><small class="text-muted">${escapeHtml(asn.supplier_code)}</small>` : ''}
                    </td>
                    <td>
                        <span class="badge ${asn.status_class} badge-status">
                            ${escapeHtml(asn.status.replace('_', ' ').toUpperCase())}
                        </span>
                    </td>
                    <td>${asn.formatted_expected_date}</td>
                    <td>
                        <strong>${asn.line_count}</strong> lines
                        <br><small class="text-muted">${asn.total_quantity} units</small>
                    </td>
                    <td>
                        <div class="progress progress-thin">
                            <div class="progress-bar" style="width: ${asn.progress_percentage}%"></div>
                        </div>
                        <small>${asn.progress_percentage}% (${asn.total_received}/${asn.total_quantity})</small>
                    </td>
                    <td>
                        <small>${asn.formatted_created_at}</small>
                    </td>
                    <td>
                        <div class="action-buttons">
                            <a href="asn_lines_secure.php?id=${asn.id}" class="btn btn-outline-primary btn-sm" title="View Lines">
                                <i class="fas fa-list"></i>
                            </a>
                            <a href="edit_asn_secure.php?id=${asn.id}" class="btn btn-outline-secondary btn-sm" title="Edit">
                                <i class="fas fa-edit"></i>
                            </a>
                            ${hasRole('supervisor') ? `
                                <button class="btn btn-outline-info btn-sm" onclick="showStatusModal(${asn.id}, '${asn.status}')" title="Update Status">
                                    <i class="fas fa-sync-alt"></i>
                                </button>
                            ` : ''}
                            ${asn.status === 'arrived' || asn.status === 'receiving' ? `
                                <a href="asn_process_secure.php?id=${asn.id}" class="btn btn-outline-success btn-sm" title="Process">
                                    <i class="fas fa-arrow-right"></i>
                                </a>
                            ` : ''}
                            ${hasRole('manager') ? `
                                <button class="btn btn-outline-danger btn-sm" onclick="confirmDelete(${asn.id}, '${escapeHtml(asn.asn_number)}')" title="Delete">
                                    <i class="fas fa-trash"></i>
                                </button>
                            ` : ''}
                        </div>
                    </td>
                </tr>
            `).join('');
        }

        function updatePagination(current, total, totalRecords) {
            currentPage = current;
            totalPages = total;
            
            // Update pagination info
            const start = totalRecords === 0 ? 0 : (current - 1) * 25 + 1;
            const end = Math.min(current * 25, totalRecords);
            document.getElementById('paginationInfo').textContent = 
                `Showing ${start}-${end} of ${totalRecords} ASNs`;
            
            // Update pagination nav
            const nav = document.getElementById('paginationNav');
            nav.innerHTML = '';
            
            if (total <= 1) return;
            
            // Previous button
            const prevLi = document.createElement('li');
            prevLi.className = `page-item ${current === 1 ? 'disabled' : ''}`;
            prevLi.innerHTML = `<a class="page-link" href="#" onclick="changePage(${current - 1})">Previous</a>`;
            nav.appendChild(prevLi);
            
            // Page numbers
            const startPage = Math.max(1, current - 2);
            const endPage = Math.min(total, current + 2);
            
            for (let i = startPage; i <= endPage; i++) {
                const pageLi = document.createElement('li');
                pageLi.className = `page-item ${i === current ? 'active' : ''}`;
                pageLi.innerHTML = `<a class="page-link" href="#" onclick="changePage(${i})">${i}</a>`;
                nav.appendChild(pageLi);
            }
            
            // Next button
            const nextLi = document.createElement('li');
            nextLi.className = `page-item ${current === total ? 'disabled' : ''}`;
            nextLi.innerHTML = `<a class="page-link" href="#" onclick="changePage(${current + 1})">Next</a>`;
            nav.appendChild(nextLi);
        }

        function changePage(page) {
            if (page < 1 || page > totalPages || page === currentPage) return;
            currentPage = page;
            loadAsnData();
        }

        function showStatusModal(asnId, currentStatus) {
            document.getElementById('statusAsnId').value = asnId;
            document.getElementById('newStatus').value = currentStatus;
            new bootstrap.Modal(document.getElementById('statusModal')).show();
        }

        function updateStatus() {
            const asnId = document.getElementById('statusAsnId').value;
            const newStatus = document.getElementById('newStatus').value;
            
            if (!newStatus) {
                showAlert('Please select a status', 'warning');
                return;
            }
            
            showLoading(true);
            
            const formData = new FormData();
            formData.append('action', 'update_asn_status');
            formData.append('csrf_token', '<?php echo $csrfToken; ?>');
            formData.append('asn_id', asnId);
            formData.append('status', newStatus);

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showAlert('Status updated successfully', 'success');
                    bootstrap.Modal.getInstance(document.getElementById('statusModal')).hide();
                    loadAsnData();
                } else {
                    showAlert('Error updating status: ' + (data.error || 'Unknown error'), 'danger');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showAlert('Failed to update status', 'danger');
            })
            .finally(() => {
                showLoading(false);
            });
        }

        function confirmDelete(asnId, asnNumber) {
            if (confirm(`Are you sure you want to delete ASN "${asnNumber}"? This action cannot be undone.`)) {
                window.location.href = `delete_asn_secure.php?id=${asnId}`;
            }
        }

        function showLoading(show) {
            document.getElementById('loadingOverlay').style.display = show ? 'flex' : 'none';
        }

        function showAlert(message, type) {
            const alert = document.createElement('div');
            alert.className = `alert alert-${type} alert-dismissible fade show`;
            alert.innerHTML = `
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;
            
            const container = document.querySelector('.container-fluid');
            container.insertBefore(alert, container.firstChild);
            
            setTimeout(() => {
                alert.remove();
            }, 5000);
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function hasRole(role) {
            <?php echo json_encode(has_role('supervisor')); ?> && role === 'supervisor' ||
            <?php echo json_encode(has_role('manager')); ?> && (role === 'manager' || role === 'supervisor')
        }
    </script>
</body>
</html>