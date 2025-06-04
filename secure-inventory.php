<?php
/**
 * Secure Inventory Management System
 * Comprehensive inventory listing with filtering, search, and secure operations
 */

require_once 'security-utils.php';
require_once 'db_config.php';

$security = SecurityUtils::getInstance();
$db = getDB();

// Check rate limiting and session
if (!$security->checkRateLimit()) {
    http_response_code(429);
    $security->logActivity('RATE_LIMIT_EXCEEDED', ['page' => 'inventory'], 'WARNING');
    die('Rate limit exceeded. Please try again later.');
}

if (!$security->validateSession('operator')) {
    $security->logActivity('UNAUTHORIZED_ACCESS_ATTEMPT', ['page' => 'inventory'], 'WARNING');
    header('Location: auth.php');
    exit();
}

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
        case 'get_inventory_data':
            try {
                $filters = [
                    'search' => $security->sanitizeInput($_POST['search'] ?? ''),
                    'location' => $security->sanitizeInput($_POST['location'] ?? ''),
                    'stock_level' => $security->sanitizeInput($_POST['stock_level'] ?? ''),
                    'client' => $security->sanitizeInput($_POST['client'] ?? ''),
                    'page' => max(1, (int) ($_POST['page'] ?? 1))
                ];
                
                $result = getInventoryData($db, $filters);
                echo json_encode(['success' => true, 'data' => $result]);
            } catch (Exception $e) {
                $security->logActivity('INVENTORY_DATA_ERROR', ['error' => $e->getMessage()], 'ERROR');
                echo json_encode(['error' => 'Failed to load inventory data']);
            }
            break;
            
        case 'export_inventory':
            try {
                $format = $security->sanitizeInput($_POST['format'] ?? 'csv');
                $filters = [
                    'search' => $security->sanitizeInput($_POST['search'] ?? ''),
                    'location' => $security->sanitizeInput($_POST['location'] ?? ''),
                    'stock_level' => $security->sanitizeInput($_POST['stock_level'] ?? ''),
                    'client' => $security->sanitizeInput($_POST['client'] ?? '')
                ];
                
                $exportData = exportInventory($db, $security, $filters, $format);
                echo json_encode(['success' => true, 'data' => $exportData]);
            } catch (Exception $e) {
                echo json_encode(['error' => 'Failed to export inventory']);
            }
            break;
            
        case 'bulk_action':
            try {
                $bulkAction = $security->sanitizeInput($_POST['bulk_action']);
                $selectedItems = $_POST['selected_items'] ?? [];
                
                if (empty($selectedItems)) {
                    echo json_encode(['error' => 'No items selected']);
                    break;
                }
                
                $result = handleBulkAction($db, $security, $bulkAction, $selectedItems);
                echo json_encode($result);
            } catch (Exception $e) {
                echo json_encode(['error' => 'Bulk action failed']);
            }
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
    }
    exit();
}

// Get filter options
$locations = getLocations($db);
$clients = getClients($db);

// Get initial inventory data
$filters = [
    'search' => $security->sanitizeInput($_GET['search'] ?? ''),
    'location' => $security->sanitizeInput($_GET['location'] ?? ''),
    'stock_level' => $security->sanitizeInput($_GET['stock_level'] ?? ''),
    'client' => $security->sanitizeInput($_GET['client'] ?? ''),
    'page' => max(1, (int) ($_GET['page'] ?? 1))
];

try {
    $inventoryData = getInventoryData($db, $filters);
    $inventory = $inventoryData['data'];
    $pagination = $inventoryData['pagination'];
    $summary = $inventoryData['summary'];
} catch (Exception $e) {
    $security->logActivity('INVENTORY_LOAD_ERROR', ['error' => $e->getMessage()], 'ERROR');
    $inventory = [];
    $pagination = ['current_page' => 1, 'total_pages' => 1, 'total' => 0];
    $summary = ['total_items' => 0, 'total_quantity' => 0, 'low_stock_count' => 0];
}

$security->logActivity('INVENTORY_ACCESS', ['filters' => $filters]);

/**
 * Get inventory data with filters
 */
function getInventoryData($db, $filters) {
    $whereClause = "i.deleted_at IS NULL";
    $params = [];
    
    // Search filter
    if (!empty($filters['search'])) {
        $whereClause .= " AND (i.sku LIKE ? OR sm.description LIKE ? OR i.location LIKE ?)";
        $searchTerm = "%{$filters['search']}%";
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }
    
    // Location filter
    if (!empty($filters['location'])) {
        $whereClause .= " AND i.location = ?";
        $params[] = $filters['location'];
    }
    
    // Client filter
    if (!empty($filters['client'])) {
        $whereClause .= " AND i.client = ?";
        $params[] = $filters['client'];
    }
    
    // Stock level filter
    if (!empty($filters['stock_level'])) {
        switch ($filters['stock_level']) {
            case 'zero':
                $whereClause .= " AND i.quantity = 0";
                break;
            case 'low':
                $whereClause .= " AND i.quantity > 0 AND i.quantity <= COALESCE(sm.min_stock_level, 10)";
                break;
            case 'normal':
                $whereClause .= " AND i.quantity > COALESCE(sm.min_stock_level, 10)";
                break;
        }
    }
    
    // Get total count for pagination
    $totalCount = $db->fetchValue(
        "SELECT COUNT(*) FROM inventory i 
         LEFT JOIN sku_master sm ON i.sku = sm.sku 
         WHERE {$whereClause}",
        $params
    ) ?: 0;
    
    // Calculate pagination
    $perPage = 50;
    $totalPages = ceil($totalCount / $perPage);
    $offset = ($filters['page'] - 1) * $perPage;
    
    // Get inventory data
    $inventory = $db->fetchAll(
        "SELECT i.id, i.sku, i.quantity, i.location, i.client, i.created_at, i.updated_at,
                sm.description, sm.unit_cost, sm.min_stock_level, sm.max_stock_level,
                CASE 
                    WHEN i.quantity = 0 THEN 'zero'
                    WHEN i.quantity <= COALESCE(sm.min_stock_level, 10) THEN 'low'
                    ELSE 'normal'
                END as stock_status
         FROM inventory i 
         LEFT JOIN sku_master sm ON i.sku = sm.sku 
         WHERE {$whereClause}
         ORDER BY i.updated_at DESC, i.sku ASC 
         LIMIT {$perPage} OFFSET {$offset}",
        $params
    );
    
    // Get summary statistics
    $summary = $db->fetchRow(
        "SELECT 
            COUNT(*) as total_items,
            COALESCE(SUM(i.quantity), 0) as total_quantity,
            COALESCE(SUM(CASE WHEN i.quantity <= COALESCE(sm.min_stock_level, 10) AND i.quantity > 0 THEN 1 ELSE 0 END), 0) as low_stock_count,
            COALESCE(SUM(CASE WHEN i.quantity = 0 THEN 1 ELSE 0 END), 0) as zero_stock_count,
            COALESCE(SUM(i.quantity * COALESCE(sm.unit_cost, 0)), 0) as total_value
         FROM inventory i 
         LEFT JOIN sku_master sm ON i.sku = sm.sku 
         WHERE {$whereClause}",
        $params
    );
    
    return [
        'data' => $inventory,
        'pagination' => [
            'current_page' => $filters['page'],
            'total_pages' => $totalPages,
            'total' => $totalCount,
            'per_page' => $perPage,
            'has_prev' => $filters['page'] > 1,
            'has_next' => $filters['page'] < $totalPages
        ],
        'summary' => $summary
    ];
}

/**
 * Get available locations
 */
function getLocations($db) {
    return $db->fetchAll(
        "SELECT DISTINCT location FROM inventory 
         WHERE location IS NOT NULL AND location != '' AND deleted_at IS NULL 
         ORDER BY location"
    );
}

/**
 * Get available clients
 */
function getClients($db) {
    return $db->fetchAll(
        "SELECT DISTINCT client FROM inventory 
         WHERE client IS NOT NULL AND client != '' AND deleted_at IS NULL 
         ORDER BY client"
    );
}

/**
 * Export inventory data
 */
function exportInventory($db, $security, $filters, $format) {
    $whereClause = "i.deleted_at IS NULL";
    $params = [];
    
    // Apply same filters as the main query
    if (!empty($filters['search'])) {
        $whereClause .= " AND (i.sku LIKE ? OR sm.description LIKE ? OR i.location LIKE ?)";
        $searchTerm = "%{$filters['search']}%";
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }
    
    if (!empty($filters['location'])) {
        $whereClause .= " AND i.location = ?";
        $params[] = $filters['location'];
    }
    
    if (!empty($filters['client'])) {
        $whereClause .= " AND i.client = ?";
        $params[] = $filters['client'];
    }
    
    if (!empty($filters['stock_level'])) {
        switch ($filters['stock_level']) {
            case 'zero':
                $whereClause .= " AND i.quantity = 0";
                break;
            case 'low':
                $whereClause .= " AND i.quantity > 0 AND i.quantity <= COALESCE(sm.min_stock_level, 10)";
                break;
            case 'normal':
                $whereClause .= " AND i.quantity > COALESCE(sm.min_stock_level, 10)";
                break;
        }
    }
    
    $data = $db->fetchAll(
        "SELECT i.sku, sm.description, i.quantity, i.location, i.client,
                sm.unit_cost, sm.min_stock_level, sm.max_stock_level,
                (i.quantity * COALESCE(sm.unit_cost, 0)) as total_value,
                i.created_at, i.updated_at
         FROM inventory i 
         LEFT JOIN sku_master sm ON i.sku = sm.sku 
         WHERE {$whereClause}
         ORDER BY i.sku ASC",
        $params
    );
    
    $security->logActivity('INVENTORY_EXPORTED', [
        'format' => $format,
        'record_count' => count($data),
        'filters' => $filters
    ]);
    
    if ($format === 'json') {
        return $data;
    }
    
    // CSV format
    if (empty($data)) {
        return "No data available for export\n";
    }
    
    $headers = ['SKU', 'Description', 'Quantity', 'Location', 'Client', 'Unit Cost', 'Min Stock', 'Max Stock', 'Total Value', 'Created At', 'Updated At'];
    $csv = implode(',', $headers) . "\n";
    
    foreach ($data as $row) {
        $csvRow = [
            $row['sku'],
            '"' . str_replace('"', '""', $row['description'] ?? '') . '"',
            $row['quantity'],
            $row['location'] ?? '',
            $row['client'] ?? '',
            $row['unit_cost'] ?? '0',
            $row['min_stock_level'] ?? '',
            $row['max_stock_level'] ?? '',
            $row['total_value'] ?? '0',
            $row['created_at'],
            $row['updated_at']
        ];
        $csv .= implode(',', $csvRow) . "\n";
    }
    
    return $csv;
}

/**
 * Handle bulk actions
 */
function handleBulkAction($db, $security, $action, $selectedItems) {
    if (!$security->hasRole($_SESSION['role'], 'supervisor')) {
        return ['error' => 'Insufficient permissions for bulk actions'];
    }
    
    $processedCount = 0;
    $errors = [];
    
    foreach ($selectedItems as $itemId) {
        $itemId = (int) $itemId;
        if ($itemId <= 0) continue;
        
        try {
            switch ($action) {
                case 'delete':
                    $affected = $db->softDelete('inventory', $itemId);
                    if ($affected > 0) {
                        $processedCount++;
                        $security->logActivity('INVENTORY_BULK_DELETE', ['item_id' => $itemId]);
                    }
                    break;
                    
                case 'cycle_count':
                    // Mark for cycle count (add to cycle count queue)
                    $db->execute(
                        "INSERT INTO cycle_count_queue (inventory_id, requested_by, requested_at) 
                         VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE requested_at = NOW()",
                        [$itemId, $_SESSION['user_id']]
                    );
                    $processedCount++;
                    $security->logActivity('INVENTORY_CYCLE_COUNT_REQUESTED', ['item_id' => $itemId]);
                    break;
                    
                default:
                    $errors[] = "Unknown action: {$action}";
            }
        } catch (Exception $e) {
            $errors[] = "Failed to process item {$itemId}: " . $e->getMessage();
        }
    }
    
    if ($processedCount > 0) {
        return [
            'success' => true,
            'message' => "Successfully processed {$processedCount} items",
            'errors' => $errors
        ];
    } else {
        return ['error' => 'No items were processed. ' . implode(', ', $errors)];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Management - Secure WMS</title>
    <meta name="robots" content="noindex, nofollow">
    <meta http-equiv="X-Content-Type-Options" content="nosniff">
    <meta http-equiv="X-Frame-Options" content="SAMEORIGIN">
    <meta http-equiv="X-XSS-Protection" content="1; mode=block">
    
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
        
        .filters-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
        }
        
        .summary-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .table-responsive {
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
        }
        
        .table {
            background: white;
            margin-bottom: 0;
        }
        
        .table th {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border: none;
            font-weight: 600;
            color: var(--primary-color);
        }
        
        .table td {
            border: none;
            border-bottom: 1px solid #f1f3f4;
            vertical-align: middle;
        }
        
        .stock-status {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
        }
        
        .stock-normal { background: #d4edda; color: #155724; }
        .stock-low { background: #fff3cd; color: #856404; }
        .stock-zero { background: #f8d7da; color: #721c24; }
        
        .quantity-display {
            font-weight: bold;
            font-size: 1.1rem;
        }
        
        .location-badge {
            background: #e3f2fd;
            color: #1565c0;
            padding: 4px 8px;
            border-radius: 8px;
            font-size: 0.8rem;
        }
        
        .action-buttons {
            display: flex;
            gap: 5px;
        }
        
        .bulk-actions {
            background: white;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            display: none;
        }
        
        .loading {
            opacity: 0.6;
            pointer-events: none;
        }
        
        .export-dropdown {
            position: relative;
        }
        
        .summary-stat {
            text-align: center;
            padding: 15px;
        }
        
        .summary-stat h3 {
            margin-bottom: 5px;
            font-weight: bold;
        }
        
        .summary-stat p {
            margin-bottom: 0;
            opacity: 0.9;
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container-fluid">
            <a class="navbar-brand" href="secure-dashboard.php">
                <i class="fas fa-warehouse"></i> Inventory Management
            </a>
            
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="secure-dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                <a class="nav-link" href="inbound_secure.php"><i class="fas fa-truck-loading"></i> Inbound</a>
                <a class="nav-link" href="outbound_secure.php"><i class="fas fa-shipping-fast"></i> Outbound</a>
                <a class="nav-link" href="auth.php?action=logout"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
        </div>
    </nav>

    <div class="container-fluid mt-4">
        <!-- Summary Statistics -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="summary-card">
                    <div class="row">
                        <div class="col-md-3">
                            <div class="summary-stat">
                                <h3><?= number_format($summary['total_items']) ?></h3>
                                <p><i class="fas fa-boxes"></i> Total Items</p>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="summary-stat">
                                <h3><?= number_format($summary['total_quantity']) ?></h3>
                                <p><i class="fas fa-cubes"></i> Total Quantity</p>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="summary-stat">
                                <h3><?= number_format($summary['low_stock_count']) ?></h3>
                                <p><i class="fas fa-exclamation-triangle"></i> Low Stock Items</p>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="summary-stat">
                                <h3>$<?= number_format($summary['total_value'], 2) ?></h3>
                                <p><i class="fas fa-dollar-sign"></i> Total Value</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="filters-card">
            <form id="filtersForm" onsubmit="return false;">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label for="search" class="form-label">Search</label>
                        <input type="text" class="form-control" id="search" name="search" 
                               value="<?= $security->escapeOutput($filters['search']) ?>" 
                               placeholder="SKU, description, or location...">
                    </div>
                    
                    <div class="col-md-2">
                        <label for="location" class="form-label">Location</label>
                        <select class="form-select" id="location" name="location">
                            <option value="">All Locations</option>
                            <?php foreach ($locations as $loc): ?>
                                <option value="<?= $security->escapeOutput($loc['location']) ?>" 
                                        <?= $filters['location'] === $loc['location'] ? 'selected' : '' ?>>
                                    <?= $security->escapeOutput($loc['location']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-2">
                        <label for="client" class="form-label">Client</label>
                        <select class="form-select" id="client" name="client">
                            <option value="">All Clients</option>
                            <?php foreach ($clients as $client): ?>
                                <option value="<?= $security->escapeOutput($client['client']) ?>" 
                                        <?= $filters['client'] === $client['client'] ? 'selected' : '' ?>>
                                    <?= $security->escapeOutput($client['client']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-2">
                        <label for="stock_level" class="form-label">Stock Level</label>
                        <select class="form-select" id="stock_level" name="stock_level">
                            <option value="">All Levels</option>
                            <option value="zero" <?= $filters['stock_level'] === 'zero' ? 'selected' : '' ?>>Zero Stock</option>
                            <option value="low" <?= $filters['stock_level'] === 'low' ? 'selected' : '' ?>>Low Stock</option>
                            <option value="normal" <?= $filters['stock_level'] === 'normal' ? 'selected' : '' ?>>Normal Stock</option>
                        </select>
                    </div>
                    
                    <div class="col-md-3 d-flex align-items-end">
                        <button type="button" class="btn btn-primary me-2" onclick="applyFilters()">
                            <i class="fas fa-search"></i> Filter
                        </button>
                        <button type="button" class="btn btn-outline-secondary me-2" onclick="clearFilters()">
                            <i class="fas fa-times"></i> Clear
                        </button>
                        <div class="dropdown">
                            <button class="btn btn-success dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                <i class="fas fa-download"></i> Export
                            </button>
                            <ul class="dropdown-menu">
                                <li><a class="dropdown-item" href="#" onclick="exportData('csv')">
                                    <i class="fas fa-file-csv"></i> CSV
                                </a></li>
                                <li><a class="dropdown-item" href="#" onclick="exportData('json')">
                                    <i class="fas fa-file-code"></i> JSON
                                </a></li>
                            </ul>
                        </div>
                    </div>
                </div>
            </form>
        </div>

        <!-- Bulk Actions -->
        <div class="bulk-actions" id="bulkActions">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <span id="selectedCount">0</span> items selected
                </div>
                <div>
                    <?php if ($security->hasRole($_SESSION['role'], 'supervisor')): ?>
                        <button class="btn btn-sm btn-warning me-2" onclick="bulkAction('cycle_count')">
                            <i class="fas fa-clipboard-check"></i> Request Cycle Count
                        </button>
                        <button class="btn btn-sm btn-danger" onclick="bulkAction('delete')">
                            <i class="fas fa-trash"></i> Delete Selected
                        </button>
                    <?php endif; ?>
                    <button class="btn btn-sm btn-secondary ms-2" onclick="clearSelection()">
                        <i class="fas fa-times"></i> Clear
                    </button>
                </div>
            </div>
        </div>

        <!-- Inventory Table -->
        <div class="card">
            <div class="card-header">
                <div class="d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-list"></i> Inventory Items (<?= $pagination['total'] ?>)</h5>
                    <div>
                        <button class="btn btn-sm btn-outline-primary" onclick="refreshData()">
                            <i class="fas fa-sync-alt"></i> Refresh
                        </button>
                    </div>
                </div>
            </div>
            <div class="card-body p-0">
                <div id="inventoryTableContainer">
                    <?php if (empty($inventory)): ?>
                        <div class="text-center py-5">
                            <i class="fas fa-box-open fa-3x text-muted mb-3"></i>
                            <p class="text-muted">No inventory items found</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <?php if ($security->hasRole($_SESSION['role'], 'supervisor')): ?>
                                            <th width="50">
                                                <input type="checkbox" id="selectAll" onchange="toggleSelectAll()">
                                            </th>
                                        <?php endif; ?>
                                        <th>SKU</th>
                                        <th>Description</th>
                                        <th>Quantity</th>
                                        <th>Location</th>
                                        <th>Client</th>
                                        <th>Status</th>
                                        <th>Last Updated</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($inventory as $item): ?>
                                        <tr>
                                            <?php if ($security->hasRole($_SESSION['role'], 'supervisor')): ?>
                                                <td>
                                                    <input type="checkbox" class="item-checkbox" value="<?= $item['id'] ?>" 
                                                           onchange="updateSelection()">
                                                </td>
                                            <?php endif; ?>
                                            <td>
                                                <strong><?= $security->escapeOutput($item['sku']) ?></strong>
                                            </td>
                                            <td>
                                                <?= $security->escapeOutput($item['description'] ?? 'No description') ?>
                                                <?php if ($item['unit_cost']): ?>
                                                    <br><small class="text-muted">Unit: $<?= number_format($item['unit_cost'], 2) ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="quantity-display text-<?= getQuantityColor($item['stock_status']) ?>">
                                                    <?= number_format($item['quantity']) ?>
                                                </span>
                                                <?php if ($item['min_stock_level']): ?>
                                                    <br><small class="text-muted">Min: <?= $item['min_stock_level'] ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($item['location']): ?>
                                                    <span class="location-badge"><?= $security->escapeOutput($item['location']) ?></span>
                                                <?php else: ?>
                                                    <span class="text-muted">No location</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= $security->escapeOutput($item['client'] ?? 'No client') ?></td>
                                            <td>
                                                <span class="stock-status stock-<?= $item['stock_status'] ?>">
                                                    <?= ucfirst($item['stock_status']) ?> Stock
                                                </span>
                                            </td>
                                            <td>
                                                <small><?= date('M j, Y H:i', strtotime($item['updated_at'])) ?></small>
                                            </td>
                                            <td>
                                                <div class="action-buttons">
                                                    <a href="edit_sku_secure.php?id=<?= $item['id'] ?>" 
                                                       class="btn btn-sm btn-outline-primary" title="Edit">
                                                        <i class="fas fa-edit"></i>
                                                    </a>
                                                    <a href="fetch_sku_info_secure.php?sku=<?= urlencode($item['sku']) ?>" 
                                                       class="btn btn-sm btn-outline-info" title="View Details">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                    <?php if ($security->hasRole($_SESSION['role'], 'supervisor')): ?>
                                                        <button class="btn btn-sm btn-outline-danger" 
                                                                onclick="deleteInventoryItem(<?= $item['id'] ?>, '<?= $security->escapeOutput($item['sku']) ?>')" 
                                                                title="Delete">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Pagination -->
            <?php if ($pagination['total_pages'] > 1): ?>
                <div class="card-footer">
                    <nav aria-label="Inventory pagination">
                        <ul class="pagination justify-content-center mb-0">
                            <?php if ($pagination['has_prev']): ?>
                                <li class="page-item">
                                    <a class="page-link" href="#" onclick="goToPage(<?= $pagination['current_page'] - 1 ?>)">
                                        <i class="fas fa-chevron-left"></i>
                                    </a>
                                </li>
                            <?php endif; ?>
                            
                            <?php for ($i = max(1, $pagination['current_page'] - 2); $i <= min($pagination['total_pages'], $pagination['current_page'] + 2); $i++): ?>
                                <li class="page-item <?= $i === $pagination['current_page'] ? 'active' : '' ?>">
                                    <a class="page-link" href="#" onclick="goToPage(<?= $i ?>)"><?= $i ?></a>
                                </li>
                            <?php endfor; ?>
                            
                            <?php if ($pagination['has_next']): ?>
                                <li class="page-item">
                                    <a class="page-link" href="#" onclick="goToPage(<?= $pagination['current_page'] + 1 ?>)">
                                        <i class="fas fa-chevron-right"></i>
                                    </a>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </nav>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const csrfToken = '<?= $csrfToken ?>';
        let selectedItems = [];
        
        function applyFilters() {
            const form = document.getElementById('filtersForm');
            const formData = new FormData(form);
            const params = new URLSearchParams(formData);
            
            // Update URL without reloading
            window.history.pushState({}, '', '?' + params.toString());
            
            // Load filtered data
            loadInventoryData();
        }
        
        function clearFilters() {
            document.getElementById('filtersForm').reset();
            window.history.pushState({}, '', window.location.pathname);
            loadInventoryData();
        }
        
        function loadInventoryData(page = 1) {
            const container = document.getElementById('inventoryTableContainer');
            container.classList.add('loading');
            
            const form = document.getElementById('filtersForm');
            const formData = new FormData(form);
            formData.append('action', 'get_inventory_data');
            formData.append('csrf_token', csrfToken);
            formData.append('page', page);
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    updateInventoryDisplay(data.data);
                } else {
                    alert('Failed to load inventory data: ' + data.error);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Failed to load inventory data');
            })
            .finally(() => {
                container.classList.remove('loading');
            });
        }
        
        function updateInventoryDisplay(data) {
            // Update table content
            // Implementation would update the table with new data
            location.reload(); // Simplified for this example
        }
        
        function goToPage(page) {
            loadInventoryData(page);
        }
        
        function refreshData() {
            loadInventoryData();
        }
        
        function exportData(format) {
            const form = document.getElementById('filtersForm');
            const formData = new FormData(form);
            formData.append('action', 'export_inventory');
            formData.append('csrf_token', csrfToken);
            formData.append('format', format);
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    downloadFile(data.data, `inventory_export.${format}`, format);
                } else {
                    alert('Export failed: ' + data.error);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Export failed');
            });
        }
        
        function downloadFile(data, filename, format) {
            let content, mimeType;
            
            if (format === 'json') {
                content = JSON.stringify(data, null, 2);
                mimeType = 'application/json';
            } else {
                content = data;
                mimeType = 'text/csv';
            }
            
            const blob = new Blob([content], { type: mimeType });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = filename;
            a.click();
            window.URL.revokeObjectURL(url);
        }
        
        function toggleSelectAll() {
            const selectAll = document.getElementById('selectAll');
            const checkboxes = document.querySelectorAll('.item-checkbox');
            
            checkboxes.forEach(checkbox => {
                checkbox.checked = selectAll.checked;
            });
            
            updateSelection();
        }
        
        function updateSelection() {
            const checkboxes = document.querySelectorAll('.item-checkbox:checked');
            selectedItems = Array.from(checkboxes).map(cb => cb.value);
            
            const bulkActions = document.getElementById('bulkActions');
            const selectedCount = document.getElementById('selectedCount');
            
            selectedCount.textContent = selectedItems.length;
            
            if (selectedItems.length > 0) {
                bulkActions.style.display = 'block';
            } else {
                bulkActions.style.display = 'none';
            }
        }
        
        function clearSelection() {
            document.querySelectorAll('.item-checkbox').forEach(cb => cb.checked = false);
            document.getElementById('selectAll').checked = false;
            updateSelection();
        }
        
        function bulkAction(action) {
            if (selectedItems.length === 0) {
                alert('No items selected');
                return;
            }
            
            let confirmMessage = '';
            switch (action) {
                case 'delete':
                    confirmMessage = `Delete ${selectedItems.length} selected items?`;
                    break;
                case 'cycle_count':
                    confirmMessage = `Request cycle count for ${selectedItems.length} selected items?`;
                    break;
                default:
                    alert('Unknown action');
                    return;
            }
            
            if (!confirm(confirmMessage)) return;
            
            const formData = new FormData();
            formData.append('action', 'bulk_action');
            formData.append('csrf_token', csrfToken);
            formData.append('bulk_action', action);
            selectedItems.forEach(item => formData.append('selected_items[]', item));
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(data.message);
                    clearSelection();
                    refreshData();
                } else {
                    alert('Bulk action failed: ' + data.error);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Bulk action failed');
            });
        }
        
        function deleteInventoryItem(id, sku) {
            if (confirm(`Delete inventory item "${sku}"?`)) {
                window.location.href = `inventory_delete_secure.php?id=${id}`;
            }
        }
        
        // Auto-refresh every 5 minutes
        setInterval(refreshData, 300000);
        
        // Real-time search
        let searchTimeout;
        document.getElementById('search').addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(applyFilters, 500);
        });
    </script>
</body>
</html>

<?php
function getQuantityColor($stockStatus) {
    return match($stockStatus) {
        'zero' => 'danger',
        'low' => 'warning',
        'normal' => 'success',
        default => 'secondary'
    };
}
?>