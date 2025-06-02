<?php
require_once 'security-utils.php';
require 'auth.php';
require_login();
include 'db_config.php';

// Set security headers
setSecurityHeaders();

// Validate CSRF token for POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        validate_csrf();
    } catch (Exception $e) {
        handleSecurityError('Invalid security token');
    }
}

// Secure pagination parameters
$page = 1;
$limit = 50;

if (isset($_GET['page'])) {
    try {
        $page = WMSSecurity::validateInteger($_GET['page'], 1, 1000);
    } catch (Exception $e) {
        $page = 1;
    }
}

if (isset($_GET['limit'])) {
    try {
        $limit = WMSSecurity::validateInteger($_GET['limit'], 10, 1000);
        if (!in_array($limit, [10, 25, 50, 100, 250, 500, 1000])) {
            $limit = 50;
        }
    } catch (Exception $e) {
        $limit = 50;
    }
}

$offset = ($page - 1) * $limit;

// Build secure WHERE conditions
$where_conditions = [];
$bind_types = '';
$bind_params = [];

// Define filterable fields with their types
$filterable_fields = [
    'tag_id' => 's',
    'client_id' => 'i',
    'sku_id' => 's',
    'site_id' => 's',
    'location_id' => 's',
    'description' => 's',
    'qty_on_hand' => 'i',
    'qty_allocated' => 'i',
    'batch_id' => 's',
    'condition' => 's',
    'lock_status' => 's',
    'zone' => 's',
    'pallet_config' => 's',
    'receipt_id' => 's',
    'line_id' => 's',
    'pallet_id' => 's',
    'container_id' => 's'
];

// Process filters securely
foreach ($filterable_fields as $field => $type) {
    if (!empty($_GET[$field])) {
        try {
            if ($type === 'i') {
                $value = WMSSecurity::validateInteger($_GET[$field]);
                $where_conditions[] = "`{$field}` = ?";
            } elseif ($type === 's') {
                $value = WMSSecurity::sanitizeString($_GET[$field], 100);
                if ($field === 'description') {
                    $where_conditions[] = "`{$field}` LIKE ?";
                    $value = "%{$value}%";
                } else {
                    $where_conditions[] = "`{$field}` = ?";
                }
            }
            $bind_types .= $type;
            $bind_params[] = $value;
        } catch (Exception $e) {
            // Skip invalid filter values
            continue;
        }
    }
}

// Handle date filters securely
$date_fields = ['receipt_dstamp', 'move_dstamp', 'count_dstamp', 'last_updated'];
foreach ($date_fields as $field) {
    $filter_type = $_GET[$field . '_filter'] ?? '';
    $from_date = $_GET[$field . '_from'] ?? '';
    $to_date = $_GET[$field . '_to'] ?? '';

    if (!empty($from_date) || !empty($to_date)) {
        try {
            switch ($filter_type) {
                case 'between':
                    if ($from_date && $to_date) {
                        WMSSecurity::validateDate($from_date);
                        WMSSecurity::validateDate($to_date);
                        $where_conditions[] = "`{$field}` BETWEEN ? AND ?";
                        $bind_types .= 'ss';
                        $bind_params[] = $from_date;
                        $bind_params[] = $to_date;
                    }
                    break;
                case 'before':
                    if ($to_date) {
                        WMSSecurity::validateDate($to_date);
                        $where_conditions[] = "`{$field}` < ?";
                        $bind_types .= 's';
                        $bind_params[] = $to_date;
                    }
                    break;
                case 'after':
                    if ($from_date) {
                        WMSSecurity::validateDate($from_date);
                        $where_conditions[] = "`{$field}` > ?";
                        $bind_types .= 's';
                        $bind_params[] = $from_date;
                    }
                    break;
                case 'exclude':
                    if ($from_date && $to_date) {
                        WMSSecurity::validateDate($from_date);
                        WMSSecurity::validateDate($to_date);
                        $where_conditions[] = "(`{$field}` < ? OR `{$field}` > ?)";
                        $bind_types .= 'ss';
                        $bind_params[] = $from_date;
                        $bind_params[] = $to_date;
                    }
                    break;
            }
        } catch (Exception $e) {
            // Skip invalid date filters
            continue;
        }
    }
}

// Build the final SQL query
$base_sql = "SELECT * FROM inventory WHERE qty_on_hand > 0";
if (!empty($where_conditions)) {
    $base_sql .= " AND " . implode(" AND ", $where_conditions);
}

// Get total count for pagination
$count_sql = "SELECT COUNT(*) as total FROM inventory WHERE qty_on_hand > 0";
if (!empty($where_conditions)) {
    $count_sql .= " AND " . implode(" AND ", $where_conditions);
}

try {
    $total_count = secure_select_one($conn, $count_sql, $bind_types, $bind_params);
    $total_rows = $total_count['total'] ?? 0;
    $total_pages = ceil($total_rows / $limit);
} catch (Exception $e) {
    $total_rows = 0;
    $total_pages = 1;
    error_log("Inventory count query failed: " . $e->getMessage());
}

// Get inventory data
$data_sql = $base_sql . " ORDER BY sku_id ASC LIMIT ? OFFSET ?";
$data_bind_types = $bind_types . 'ii';
$data_bind_params = array_merge($bind_params, [$limit, $offset]);

try {
    $inventory_data = secure_select_all($conn, $data_sql, $data_bind_types, $data_bind_params);
} catch (Exception $e) {
    $inventory_data = [];
    error_log("Inventory data query failed: " . $e->getMessage());
}

// Log user activity
WMSSecurity::logActivity($conn, $_SESSION['user'], 'viewed_inventory',
    "Page: {$page}, Limit: {$limit}, Filters: " . count($where_conditions));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Management | ECWMS</title>
    <link rel="stylesheet" href="modern-style.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        .inventory-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
        }

        .inventory-stats {
            display: flex;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .inventory-stat {
            background: white;
            padding: 1rem 1.5rem;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            text-align: center;
            min-width: 120px;
        }

        .inventory-stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary-blue);
        }

        .inventory-stat-label {
            font-size: 0.75rem;
            color: var(--gray-600);
            text-transform: uppercase;
            letter-spacing: 0.025em;
        }

        .condition-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 500;
            text-transform: uppercase;
        }

        .condition-ok1, .condition-ok2 { background: #dcfce7; color: #166534; }
        .condition-dm1 { background: #fecaca; color: #991b1b; }
        .condition-qc1 { background: #fed7aa; color: #9a3412; }
        .condition-bl1 { background: #dbeafe; color: #1e40af; }
        .condition-rt1 { background: #e9d5ff; color: #7c2d12; }

        .table-controls {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin: 1rem 0;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .pagination-info {
            color: var(--gray-600);
            font-size: 0.875rem;
        }

        .pagination-controls {
            display: flex;
            gap: 0.5rem;
            align-items: center;
        }

        .table-wrapper {
            background: white;
            border-radius: var(--border-radius-lg);
            box-shadow: var(--box-shadow);
            overflow: hidden;
        }

        .table-scroll {
            overflow-x: auto;
            max-height: 70vh;
        }

        .inventory-table {
            width: 100%;
            min-width: 1800px;
            border-collapse: collapse;
            font-size: 0.875rem;
        }

        .inventory-table th {
            background: var(--gray-50);
            color: var(--gray-700);
            font-weight: 600;
            padding: 1rem 0.75rem;
            text-align: left;
            border-bottom: 1px solid var(--gray-200);
            position: sticky;
            top: 0;
            z-index: 10;
            white-space: nowrap;
        }

        .inventory-table td {
            padding: 0.75rem;
            border-bottom: 1px solid var(--gray-100);
            vertical-align: middle;
        }

        .inventory-table tbody tr:hover {
            background-color: var(--gray-50);
        }

        .inventory-table tbody tr.selected {
            background-color: rgba(59, 130, 246, 0.1);
        }

        .inventory-table tbody tr {
            cursor: pointer;
        }

        .action-toolbar {
            background: white;
            padding: 1rem;
            border-top: 1px solid var(--gray-200);
            display: flex;
            gap: 0.75rem;
            justify-content: center;
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
            <input type="text" placeholder="Search inventory..." id="quickSearch">
        </div>
        <div class="wms-user-menu">
            <span>👤 <?= secure_escape($_SESSION['user']) ?></span>
            <a href="secure-dashboard.php" class="btn btn-secondary btn-sm">🏠 Dashboard</a>
            <a href="logout.php" class="btn btn-danger btn-sm">🔒 Logout</a>
        </div>
    </header>

    <!-- Main Content -->
    <main class="wms-content">
        <div class="fade-in">
            <!-- Page Header -->
            <div class="inventory-header">
                <div>
                    <h1>Inventory Management</h1>
                    <p class="text-secondary">Manage stock levels, locations, and inventory movements</p>
                </div>
                <div class="d-flex gap-2">
                    <a href="inventory_add.php" class="btn btn-primary">➕ Add Inventory</a>
                    <a href="export_inventory_csv.php" class="btn btn-secondary">📤 Export</a>
                </div>
            </div>

            <!-- Statistics -->
            <div class="inventory-stats">
                <div class="inventory-stat">
                    <div class="inventory-stat-value"><?= number_format($total_rows) ?></div>
                    <div class="inventory-stat-label">Total Items</div>
                </div>
                <div class="inventory-stat">
                    <div class="inventory-stat-value"><?= number_format(array_sum(array_column($inventory_data, 'qty_on_hand'))) ?></div>
                    <div class="inventory-stat-label">Total Qty</div>
                </div>
                <div class="inventory-stat">
                    <div class="inventory-stat-value"><?= number_format(array_sum(array_column($inventory_data, 'qty_allocated'))) ?></div>
                    <div class="inventory-stat-label">Allocated</div>
                </div>
                <div class="inventory-stat">
                    <div class="inventory-stat-value"><?= count(array_unique(array_column($inventory_data, 'location_id'))) ?></div>
                    <div class="inventory-stat-label">Locations</div>
                </div>
            </div>

            <!-- Filters -->
            <div class="filter-panel">
                <form method="GET" id="filterForm">
                    <?= csrf_field() ?>

                    <!-- Basic Filters -->
                    <div class="filter-grid">
                        <div class="form-group">
                            <label class="form-label">Tag ID</label>
                            <input type="text" name="tag_id" class="form-control" value="<?= secure_escape($_GET['tag_id'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">SKU ID</label>
                            <input type="text" name="sku_id" class="form-control" value="<?= secure_escape($_GET['sku_id'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Location ID</label>
                            <input type="text" name="location_id" class="form-control" value="<?= secure_escape($_GET['location_id'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Client ID</label>
                            <input type="number" name="client_id" class="form-control" value="<?= secure_escape($_GET['client_id'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Description</label>
                            <input type="text" name="description" class="form-control" value="<?= secure_escape($_GET['description'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Condition</label>
                            <select name="condition" class="form-control form-select">
                                <option value="">All Conditions</option>
                                <option value="OK1" <?= ($_GET['condition'] ?? '') === 'OK1' ? 'selected' : '' ?>>OK1</option>
                                <option value="OK2" <?= ($_GET['condition'] ?? '') === 'OK2' ? 'selected' : '' ?>>OK2</option>
                                <option value="DM1" <?= ($_GET['condition'] ?? '') === 'DM1' ? 'selected' : '' ?>>DM1</option>
                                <option value="QC1" <?= ($_GET['condition'] ?? '') === 'QC1' ? 'selected' : '' ?>>QC1</option>
                                <option value="BL1" <?= ($_GET['condition'] ?? '') === 'BL1' ? 'selected' : '' ?>>BL1</option>
                                <option value="RT1" <?= ($_GET['condition'] ?? '') === 'RT1' ? 'selected' : '' ?>>RT1</option>
                            </select>
                        </div>
                    </div>

                    <!-- Advanced Filters -->
                    <details style="margin-top: 1rem;">
                        <summary style="font-weight: 500; cursor: pointer; margin-bottom: 1rem;">Advanced Filters</summary>
                        <div class="filter-grid">
                            <div class="form-group">
                                <label class="form-label">Zone</label>
                                <input type="text" name="zone" class="form-control" value="<?= secure_escape($_GET['zone'] ?? '') ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Batch ID</label>
                                <input type="text" name="batch_id" class="form-control" value="<?= secure_escape($_GET['batch_id'] ?? '') ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Receipt Date Filter</label>
                                <select name="receipt_dstamp_filter" class="form-control form-select">
                                    <option value="between">Between</option>
                                    <option value="before">Before</option>
                                    <option value="after">After</option>
                                    <option value="exclude">Exclude</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label">From Date</label>
                                <input type="date" name="receipt_dstamp_from" class="form-control" value="<?= secure_escape($_GET['receipt_dstamp_from'] ?? '') ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label">To Date</label>
                                <input type="date" name="receipt_dstamp_to" class="form-control" value="<?= secure_escape($_GET['receipt_dstamp_to'] ?? '') ?>">
                            </div>
                        </div>
                    </details>

                    <div class="filter-actions">
                        <button type="submit" class="btn btn-primary">🔍 Apply Filters</button>
                        <a href="secure-inventory.php" class="btn btn-secondary">🔄 Reset</a>
                        <button type="button" class="btn btn-secondary" onclick="toggleAdvancedFilters()">⚙️ Advanced</button>
                    </div>
                </form>
            </div>

            <!-- Table Controls -->
            <div class="table-controls">
                <div class="pagination-info">
                    Showing <?= number_format(($page - 1) * $limit + 1) ?> to <?= number_format(min($page * $limit, $total_rows)) ?> of <?= number_format($total_rows) ?> items
                </div>
                <div class="pagination-controls">
                    <form method="GET" style="display: inline-flex; align-items: center; gap: 0.5rem;">
                        <?php foreach ($_GET as $key => $value): ?>
                            <?php if ($key !== 'limit' && $key !== 'page'): ?>
                                <input type="hidden" name="<?= secure_escape($key) ?>" value="<?= secure_escape($value) ?>">
                            <?php endif; ?>
                        <?php endforeach; ?>
                        <label>Show:</label>
                        <select name="limit" onchange="this.form.submit()" class="form-control" style="width: auto;">
                            <option value="25" <?= $limit == 25 ? 'selected' : '' ?>>25</option>
                            <option value="50" <?= $limit == 50 ? 'selected' : '' ?>>50</option>
                            <option value="100" <?= $limit == 100 ? 'selected' : '' ?>>100</option>
                            <option value="250" <?= $limit == 250 ? 'selected' : '' ?>>250</option>
                        </select>
                    </form>
                </div>
            </div>

            <!-- Data Table -->
            <?php if (!empty($inventory_data)): ?>
            <div class="table-wrapper">
                <div class="table-scroll">
                    <table class="inventory-table">
                        <thead>
                            <tr>
                                <th>Tag ID</th>
                                <th>Client ID</th>
                                <th>SKU ID</th>
                                <th>Site ID</th>
                                <th>Location ID</th>
                                <th>Description</th>
                                <th>Qty On Hand</th>
                                <th>Qty Allocated</th>
                                <th>Batch ID</th>
                                <th>Condition</th>
                                <th>Lock Status</th>
                                <th>Zone</th>
                                <th>Pallet Config</th>
                                <th>Receipt ID</th>
                                <th>Line ID</th>
                                <th>Receipt Date</th>
                                <th>Receipt Time</th>
                                <th>Move Date</th>
                                <th>Move Time</th>
                                <th>Count Date</th>
                                <th>Expiry Date</th>
                                <th>Pallet ID</th>
                                <th>Container ID</th>
                                <th>Last Updated</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($inventory_data as $row): ?>
                            <tr class="inventory-row" data-id="<?= secure_escape($row['tag_id']) ?>">
                                <td><?= secure_escape($row['tag_id']) ?></td>
                                <td><?= secure_escape($row['client_id']) ?></td>
                                <td><strong><?= secure_escape($row['sku_id']) ?></strong></td>
                                <td><?= secure_escape($row['site_id']) ?></td>
                                <td><?= secure_escape($row['location_id']) ?></td>
                                <td><?= secure_escape($row['description']) ?></td>
                                <td><strong><?= number_format($row['qty_on_hand']) ?></strong></td>
                                <td><?= number_format($row['qty_allocated']) ?></td>
                                <td><?= secure_escape($row['batch_id']) ?></td>
                                <td>
                                    <?php if ($row['condition']): ?>
                                        <span class="condition-badge condition-<?= strtolower($row['condition']) ?>">
                                            <?= secure_escape($row['condition']) ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td><?= secure_escape($row['lock_status']) ?></td>
                                <td><?= secure_escape($row['zone']) ?></td>
                                <td><?= secure_escape($row['pallet_config']) ?></td>
                                <td><?= secure_escape($row['receipt_id']) ?></td>
                                <td><?= secure_escape($row['line_id']) ?></td>
                                <td><?= $row['receipt_dstamp'] ? date('M d, Y', strtotime($row['receipt_dstamp'])) : '' ?></td>
                                <td><?= secure_escape($row['receipt_time']) ?></td>
                                <td><?= $row['move_dstamp'] ? date('M d, Y', strtotime($row['move_dstamp'])) : '' ?></td>
                                <td><?= secure_escape($row['move_time']) ?></td>
                                <td><?= $row['count_dstamp'] ? date('M d, Y', strtotime($row['count_dstamp'])) : '' ?></td>
                                <td><?= $row['expiry_date'] ? date('M d, Y', strtotime($row['expiry_date'])) : '' ?></td>
                                <td><?= secure_escape($row['pallet_id']) ?></td>
                                <td><?= secure_escape($row['container_id']) ?></td>
                                <td><?= date('M d, Y H:i', strtotime($row['last_updated'])) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Action Toolbar -->
                <div class="action-toolbar">
                    <button id="editBtn" class="btn btn-primary" disabled onclick="editSelected()">✏️ Edit Selected</button>
                    <button id="deleteBtn" class="btn btn-danger" disabled onclick="deleteSelected()">🗑️ Delete Selected</button>
                    <button class="btn btn-secondary" onclick="clearSelection()">Clear Selection</button>
                </div>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
            <div class="pagination-controls" style="justify-content: center; margin-top: 2rem;">
                <?php if ($page > 1): ?>
                    <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>" class="btn btn-secondary">« Previous</a>
                <?php endif; ?>

                <?php
                $start = max(1, $page - 2);
                $end = min($total_pages, $page + 2);
                ?>

                <?php for ($i = $start; $i <= $end; $i++): ?>
                    <?php if ($i == $page): ?>
                        <button class="btn btn-primary"><?= $i ?></button>
                    <?php else: ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>" class="btn btn-secondary"><?= $i ?></a>
                    <?php endif; ?>
                <?php endfor; ?>

                <?php if ($page < $total_pages): ?>
                    <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>" class="btn btn-secondary">Next »</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php else: ?>
            <div class="card">
                <div class="card-body text-center">
                    <h3>No inventory records found</h3>
                    <p class="text-secondary">Try adjusting your filters or add some inventory items.</p>
                    <a href="inventory_add.php" class="btn btn-primary">➕ Add Inventory</a>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </main>

    <script>
        let selectedRows = new Set();

        // Row selection handling
        document.querySelectorAll('.inventory-row').forEach(row => {
            row.addEventListener('click', function(e) {
                if (e.ctrlKey || e.metaKey) {
                    // Multi-select with Ctrl/Cmd
                    this.classList.toggle('selected');
                    const tagId = this.dataset.id;
                    if (selectedRows.has(tagId)) {
                        selectedRows.delete(tagId);
                    } else {
                        selectedRows.add(tagId);
                    }
                } else {
                    // Single select
                    document.querySelectorAll('.inventory-row').forEach(r => r.classList.remove('selected'));
                    selectedRows.clear();
                    this.classList.add('selected');
                    selectedRows.add(this.dataset.id);
                }
                updateActionButtons();
            });
        });

        function updateActionButtons() {
            const editBtn = document.getElementById('editBtn');
            const deleteBtn = document.getElementById('deleteBtn');
            const hasSelection = selectedRows.size > 0;

            editBtn.disabled = selectedRows.size !== 1;
            deleteBtn.disabled = !hasSelection;
        }

        function editSelected() {
            if (selectedRows.size === 1) {
                const tagId = Array.from(selectedRows)[0];
                window.location.href = `inventory_edit.php?tag_id=${encodeURIComponent(tagId)}`;
            }
        }

        function deleteSelected() {
            if (selectedRows.size > 0) {
                const count = selectedRows.size;
                if (confirm(`Are you sure you want to delete ${count} inventory item(s)?`)) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = 'inventory_delete.php';

                    // Add CSRF token
                    const csrfInput = document.createElement('input');
                    csrfInput.type = 'hidden';
                    csrfInput.name = 'csrf_token';
                    csrfInput.value = '<?= csrf_token() ?>';
                    form.appendChild(csrfInput);

                    // Add selected IDs
                    selectedRows.forEach(tagId => {
                        const input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = 'tag_ids[]';
                        input.value = tagId;
                        form.appendChild(input);
                    });

                    document.body.appendChild(form);
                    form.submit();
                }
            }
        }

        function clearSelection() {
            document.querySelectorAll('.inventory-row').forEach(r => r.classList.remove('selected'));
            selectedRows.clear();
            updateActionButtons();
        }

        // Quick search functionality
        document.getElementById('quickSearch').addEventListener('keyup', function(e) {
            if (e.key === 'Enter') {
                const query = this.value;
                if (query) {
                    // Add search to description filter and submit
                    const descInput = document.querySelector('input[name="description"]');
                    descInput.value = query;
                    document.getElementById('filterForm').submit();
                }
            }
        });

        // Auto-submit form on filter changes
        document.querySelectorAll('select[name="condition"]').forEach(select => {
            select.addEventListener('change', function() {
                document.getElementById('filterForm').submit();
            });
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey || e.metaKey) {
                switch(e.key) {
                    case 'a':
                        e.preventDefault();
                        // Select all visible rows
                        document.querySelectorAll('.inventory-row').forEach(row => {
                            row.classList.add('selected');
                            selectedRows.add(row.dataset.id);
                        });
                        updateActionButtons();
                        break;
                    case 'Escape':
                        clearSelection();
                        break;
                }
            }
        });

        // Auto-refresh every 5 minutes
        setInterval(function() {
            if (document.visibilityState === 'visible') {
                window.location.reload();
            }
        }, 300000);
    </script>
</body>
</html>

<?php $conn->close(); ?>
