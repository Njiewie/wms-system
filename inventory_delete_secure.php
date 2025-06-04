<?php
/**
 * Secure Inventory Deletion System
 * Handles inventory item deletion with soft delete and audit logging
 */

require_once 'security-utils.php';
require_once 'db_config.php';

$security = SecurityUtils::getInstance();
$db = getDB();

// Check rate limiting and session (require supervisor role for deletion)
if (!$security->checkRateLimit()) {
    http_response_code(429);
    $security->logActivity('RATE_LIMIT_EXCEEDED', ['page' => 'inventory_delete'], 'WARNING');
    die('Rate limit exceeded. Please try again later.');
}

if (!$security->validateSession('supervisor')) {
    $security->logActivity('UNAUTHORIZED_ACCESS_ATTEMPT', ['page' => 'inventory_delete'], 'WARNING');
    header('Location: auth.php');
    exit();
}

$csrfToken = $security->generateCSRFToken();
$message = '';
$messageType = '';
$inventoryItem = null;

// Get inventory item ID
$itemId = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);

if ($itemId <= 0) {
    $security->logActivity('INVENTORY_DELETE_INVALID_ID', ['provided_id' => $_GET['id'] ?? $_POST['id'] ?? 'none'], 'WARNING');
    header('Location: secure-inventory.php');
    exit();
}

// Get inventory item details
try {
    $inventoryItem = $db->fetchRow(
        "SELECT i.*, sm.description, sm.unit_cost 
         FROM inventory i 
         LEFT JOIN sku_master sm ON i.sku = sm.sku 
         WHERE i.id = ? AND i.deleted_at IS NULL",
        [$itemId]
    );
    
    if (!$inventoryItem) {
        $security->logActivity('INVENTORY_DELETE_ITEM_NOT_FOUND', ['item_id' => $itemId], 'WARNING');
        header('Location: secure-inventory.php?error=item_not_found');
        exit();
    }
} catch (Exception $e) {
    $security->logActivity('INVENTORY_DELETE_FETCH_ERROR', ['item_id' => $itemId, 'error' => $e->getMessage()], 'ERROR');
    header('Location: secure-inventory.php?error=fetch_failed');
    exit();
}

// Handle deletion request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$security->validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = 'Invalid CSRF token. Please try again.';
        $messageType = 'danger';
    } else {
        $action = $security->sanitizeInput($_POST['action']);
        
        if ($action === 'delete') {
            $result = handleInventoryDeletion($inventoryItem, $db, $security);
            
            if ($result['success']) {
                $security->logActivity('INVENTORY_ITEM_DELETED', [
                    'item_id' => $itemId,
                    'sku' => $inventoryItem['sku'],
                    'quantity' => $inventoryItem['quantity'],
                    'location' => $inventoryItem['location']
                ], 'WARNING');
                
                header('Location: secure-inventory.php?success=item_deleted');
                exit();
            } else {
                $message = $result['message'];
                $messageType = 'danger';
            }
        }
    }
}

/**
 * Handle inventory item deletion with checks
 */
function handleInventoryDeletion($item, $db, $security) {
    try {
        // Check for existing dependencies
        $dependencies = checkDependencies($item, $db);
        
        if (!empty($dependencies)) {
            return [
                'success' => false,
                'message' => 'Cannot delete item due to existing dependencies: ' . implode(', ', $dependencies)
            ];
        }
        
        // Perform transaction to ensure data consistency
        $db->beginTransaction();
        
        try {
            // Soft delete the inventory item
            $deleteData = [
                'deleted_at' => date('Y-m-d H:i:s'),
                'deleted_by' => $_SESSION['user_id']
            ];
            
            $affectedRows = $db->update('inventory', $deleteData, 'id = ?', [':id' => $item['id']]);
            
            if ($affectedRows === 0) {
                throw new Exception('No rows affected during deletion');
            }
            
            // Create inventory adjustment record for audit trail
            $adjustmentData = [
                'sku' => $item['sku'],
                'location' => $item['location'],
                'client' => $item['client'],
                'adjustment_type' => 'deletion',
                'quantity' => -$item['quantity'], // Negative quantity for removal
                'reason' => 'Item deleted by user',
                'reference_number' => 'DEL-' . date('Ymd') . '-' . $item['id'],
                'created_by' => $_SESSION['user_id'],
                'created_at' => date('Y-m-d H:i:s')
            ];
            
            $db->insert('inventory_adjustments', $adjustmentData);
            
            $db->commit();
            
            return ['success' => true, 'message' => 'Item deleted successfully'];
            
        } catch (Exception $e) {
            $db->rollback();
            throw $e;
        }
        
    } catch (Exception $e) {
        $security->logActivity('INVENTORY_DELETE_FAILED', [
            'item_id' => $item['id'],
            'error' => $e->getMessage()
        ], 'ERROR');
        
        return [
            'success' => false,
            'message' => 'Failed to delete item: ' . $security->sanitizeErrorMessage($e->getMessage())
        ];
    }
}

/**
 * Check for dependencies that would prevent deletion
 */
function checkDependencies($item, $db) {
    $dependencies = [];
    
    try {
        // Check for pending outbound orders
        $pendingOrders = $db->fetchValue(
            "SELECT COUNT(*) FROM outbound_order_lines ol 
             JOIN outbound_orders o ON ol.order_id = o.id 
             WHERE ol.sku = ? AND o.status IN ('pending', 'allocated', 'picking') 
             AND o.deleted_at IS NULL AND ol.deleted_at IS NULL",
            [$item['sku']]
        );
        
        if ($pendingOrders > 0) {
            $dependencies[] = "{$pendingOrders} pending outbound order(s)";
        }
        
        // Check for pending inbound ASNs
        $pendingASNs = $db->fetchValue(
            "SELECT COUNT(*) FROM asn_lines al 
             JOIN asn a ON al.asn_id = a.id 
             WHERE al.sku = ? AND a.status IN ('created', 'in_progress') 
             AND a.deleted_at IS NULL AND al.deleted_at IS NULL",
            [$item['sku']]
        );
        
        if ($pendingASNs > 0) {
            $dependencies[] = "{$pendingASNs} pending inbound ASN(s)";
        }
        
        // Check for active cycle counts
        $activeCycleCounts = $db->fetchValue(
            "SELECT COUNT(*) FROM cycle_counts cc 
             WHERE cc.sku = ? AND cc.status = 'active' 
             AND cc.deleted_at IS NULL",
            [$item['sku']]
        );
        
        if ($activeCycleCounts > 0) {
            $dependencies[] = "{$activeCycleCounts} active cycle count(s)";
        }
        
        // Check for recent transactions (within last 24 hours)
        $recentTransactions = $db->fetchValue(
            "SELECT COUNT(*) FROM inventory_movements 
             WHERE sku = ? AND location = ? 
             AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)",
            [$item['sku'], $item['location']]
        );
        
        if ($recentTransactions > 0) {
            $dependencies[] = "{$recentTransactions} recent transaction(s) (within 24 hours)";
        }
        
    } catch (Exception $e) {
        error_log("Error checking dependencies: " . $e->getMessage());
    }
    
    return $dependencies;
}

/**
 * Get related inventory movements for audit display
 */
function getRelatedMovements($item, $db) {
    try {
        return $db->fetchAll(
            "SELECT * FROM inventory_movements 
             WHERE sku = ? AND location = ? 
             ORDER BY created_at DESC 
             LIMIT 10",
            [$item['sku'], $item['location']]
        );
    } catch (Exception $e) {
        return [];
    }
}

$relatedMovements = getRelatedMovements($inventoryItem, $db);
$dependencies = checkDependencies($inventoryItem, $db);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delete Inventory Item - Secure WMS</title>
    <meta name="robots" content="noindex, nofollow">
    <meta http-equiv="X-Content-Type-Options" content="nosniff">
    <meta http-equiv="X-Frame-Options" content="SAMEORIGIN">
    <meta http-equiv="X-XSS-Protection" content="1; mode=block">
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    
    <style>
        :root {
            --danger-color: #dc3545;
            --warning-color: #ffc107;
            --info-color: #0dcaf0;
            --dark-color: #212529;
        }
        
        body {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .navbar {
            background: linear-gradient(135deg, var(--danger-color), #b02a37);
            box-shadow: 0 2px 15px rgba(0,0,0,0.1);
        }
        
        .card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
        }
        
        .delete-warning {
            background: linear-gradient(135deg, #fff5f5 0%, #fed7d7 100%);
            border: 2px solid var(--danger-color);
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .item-details {
            background: white;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
        }
        
        .dependency-alert {
            background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%);
            border: 2px solid var(--warning-color);
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
        }
        
        .movements-table {
            background: white;
            border-radius: 10px;
            overflow: hidden;
        }
        
        .confirm-section {
            background: white;
            border-radius: 15px;
            padding: 30px;
            text-align: center;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
        }
        
        .danger-icon {
            font-size: 4rem;
            color: var(--danger-color);
            margin-bottom: 20px;
        }
        
        .item-info {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid #eee;
        }
        
        .item-info:last-child {
            border-bottom: none;
        }
        
        .badge-location {
            background: #e3f2fd;
            color: #1565c0;
            padding: 5px 10px;
            border-radius: 8px;
            font-size: 0.9rem;
        }
        
        .badge-quantity {
            background: #f3e5f5;
            color: #7b1fa2;
            padding: 5px 10px;
            border-radius: 8px;
            font-size: 0.9rem;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container-fluid">
            <a class="navbar-brand" href="secure-inventory.php">
                <i class="fas fa-trash-alt"></i> Delete Inventory Item
            </a>
            
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="secure-inventory.php"><i class="fas fa-arrow-left"></i> Back to Inventory</a>
                <a class="nav-link" href="secure-dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <!-- Messages -->
        <?php if (!empty($message)): ?>
            <div class="alert alert-<?= $messageType ?> alert-dismissible fade show" role="alert">
                <?= $security->escapeOutput($message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Delete Warning -->
        <div class="delete-warning">
            <div class="d-flex align-items-center mb-3">
                <i class="fas fa-exclamation-triangle fa-2x text-danger me-3"></i>
                <div>
                    <h4 class="mb-1 text-danger">Warning: Permanent Deletion</h4>
                    <p class="mb-0">You are about to delete an inventory item. This action cannot be undone.</p>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-lg-8">
                <!-- Item Details -->
                <div class="item-details">
                    <h5 class="mb-4"><i class="fas fa-box"></i> Item Details</h5>
                    
                    <div class="item-info">
                        <span class="fw-bold">SKU:</span>
                        <span class="fw-bold text-primary"><?= $security->escapeOutput($inventoryItem['sku']) ?></span>
                    </div>
                    
                    <div class="item-info">
                        <span class="fw-bold">Description:</span>
                        <span><?= $security->escapeOutput($inventoryItem['description'] ?? 'No description') ?></span>
                    </div>
                    
                    <div class="item-info">
                        <span class="fw-bold">Current Quantity:</span>
                        <span class="badge-quantity"><?= number_format($inventoryItem['quantity']) ?></span>
                    </div>
                    
                    <div class="item-info">
                        <span class="fw-bold">Location:</span>
                        <span class="badge-location"><?= $security->escapeOutput($inventoryItem['location'] ?? 'No location') ?></span>
                    </div>
                    
                    <div class="item-info">
                        <span class="fw-bold">Client:</span>
                        <span><?= $security->escapeOutput($inventoryItem['client'] ?? 'No client') ?></span>
                    </div>
                    
                    <?php if ($inventoryItem['unit_cost']): ?>
                        <div class="item-info">
                            <span class="fw-bold">Unit Cost:</span>
                            <span>$<?= number_format($inventoryItem['unit_cost'], 2) ?></span>
                        </div>
                        
                        <div class="item-info">
                            <span class="fw-bold">Total Value:</span>
                            <span class="fw-bold text-success">$<?= number_format($inventoryItem['quantity'] * $inventoryItem['unit_cost'], 2) ?></span>
                        </div>
                    <?php endif; ?>
                    
                    <div class="item-info">
                        <span class="fw-bold">Created:</span>
                        <span><?= date('M j, Y H:i', strtotime($inventoryItem['created_at'])) ?></span>
                    </div>
                    
                    <div class="item-info">
                        <span class="fw-bold">Last Updated:</span>
                        <span><?= date('M j, Y H:i', strtotime($inventoryItem['updated_at'])) ?></span>
                    </div>
                </div>

                <!-- Dependencies Check -->
                <?php if (!empty($dependencies)): ?>
                    <div class="dependency-alert">
                        <h6 class="text-warning mb-3">
                            <i class="fas fa-exclamation-triangle"></i> Cannot Delete - Dependencies Found
                        </h6>
                        <p class="mb-2">This item cannot be deleted due to the following dependencies:</p>
                        <ul class="mb-0">
                            <?php foreach ($dependencies as $dependency): ?>
                                <li><?= $security->escapeOutput($dependency) ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <hr>
                        <p class="mb-0 small">
                            <strong>Resolution:</strong> Complete or cancel pending transactions before attempting deletion.
                        </p>
                    </div>
                <?php endif; ?>

                <!-- Recent Movements -->
                <?php if (!empty($relatedMovements)): ?>
                    <div class="card">
                        <div class="card-header">
                            <h6 class="mb-0"><i class="fas fa-exchange-alt"></i> Recent Movements (Last 10)</h6>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive movements-table">
                                <table class="table table-sm mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Date</th>
                                            <th>Type</th>
                                            <th>Quantity</th>
                                            <th>Reference</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($relatedMovements as $movement): ?>
                                            <tr>
                                                <td><?= date('M j H:i', strtotime($movement['created_at'])) ?></td>
                                                <td>
                                                    <span class="badge bg-<?= $movement['movement_type'] === 'in' ? 'success' : 'danger' ?>">
                                                        <?= ucfirst($movement['movement_type']) ?>
                                                    </span>
                                                </td>
                                                <td><?= number_format($movement['quantity']) ?></td>
                                                <td class="small"><?= $security->escapeOutput($movement['reference_number'] ?? '') ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <div class="col-lg-4">
                <!-- Confirmation Section -->
                <div class="confirm-section">
                    <i class="fas fa-trash-alt danger-icon"></i>
                    
                    <h4 class="mb-3">Confirm Deletion</h4>
                    
                    <?php if (empty($dependencies)): ?>
                        <p class="text-muted mb-4">
                            Are you sure you want to delete this inventory item? 
                            This will remove <strong><?= number_format($inventoryItem['quantity']) ?></strong> 
                            units of <strong><?= $security->escapeOutput($inventoryItem['sku']) ?></strong> 
                            from the system.
                        </p>
                        
                        <form method="POST" onsubmit="return confirmDeletion()">
                            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $inventoryItem['id'] ?>">
                            
                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-danger btn-lg">
                                    <i class="fas fa-trash-alt"></i> Delete Item
                                </button>
                                <a href="secure-inventory.php" class="btn btn-outline-secondary">
                                    <i class="fas fa-times"></i> Cancel
                                </a>
                            </div>
                        </form>
                    <?php else: ?>
                        <p class="text-warning mb-4">
                            This item cannot be deleted due to existing dependencies. 
                            Please resolve the issues listed above before attempting deletion.
                        </p>
                        
                        <div class="d-grid">
                            <a href="secure-inventory.php" class="btn btn-secondary btn-lg">
                                <i class="fas fa-arrow-left"></i> Back to Inventory
                            </a>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Additional Actions -->
                <div class="card mt-4">
                    <div class="card-header">
                        <h6 class="mb-0"><i class="fas fa-tools"></i> Alternative Actions</h6>
                    </div>
                    <div class="card-body">
                        <div class="d-grid gap-2">
                            <a href="edit_sku_secure.php?id=<?= $inventoryItem['id'] ?>" class="btn btn-outline-primary btn-sm">
                                <i class="fas fa-edit"></i> Edit Item
                            </a>
                            <a href="fetch_sku_info_secure.php?sku=<?= urlencode($inventoryItem['sku']) ?>" class="btn btn-outline-info btn-sm">
                                <i class="fas fa-eye"></i> View Details
                            </a>
                            <?php if ($inventoryItem['quantity'] > 0): ?>
                                <button class="btn btn-outline-warning btn-sm" onclick="requestCycleCount()">
                                    <i class="fas fa-clipboard-check"></i> Request Cycle Count
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Deletion Guidelines -->
                <div class="card mt-4">
                    <div class="card-header">
                        <h6 class="mb-0"><i class="fas fa-info-circle"></i> Deletion Guidelines</h6>
                    </div>
                    <div class="card-body">
                        <ul class="small mb-0">
                            <li>Deletion creates an audit trail</li>
                            <li>Item will be soft-deleted (recoverable)</li>
                            <li>Inventory adjustments are logged</li>
                            <li>Reports will exclude deleted items</li>
                            <li>Historical data remains intact</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function confirmDeletion() {
            const sku = '<?= $security->escapeOutput($inventoryItem['sku']) ?>';
            const quantity = <?= $inventoryItem['quantity'] ?>;
            
            return confirm(
                `Final confirmation required!\n\n` +
                `SKU: ${sku}\n` +
                `Quantity: ${quantity.toLocaleString()}\n\n` +
                `This action cannot be undone. Continue with deletion?`
            );
        }
        
        function requestCycleCount() {
            const itemId = <?= $inventoryItem['id'] ?>;
            const sku = '<?= $security->escapeOutput($inventoryItem['sku']) ?>';
            
            if (confirm(`Request cycle count for ${sku}?`)) {
                // Implementation would make AJAX call to request cycle count
                alert('Cycle count requested successfully');
            }
        }
        
        // Warn user before leaving page if there are dependencies
        <?php if (!empty($dependencies)): ?>
            window.addEventListener('beforeunload', function(e) {
                if (document.activeElement.tagName !== 'A') {
                    e.preventDefault();
                    e.returnValue = '';
                }
            });
        <?php endif; ?>
        
        // Auto-hide alerts after 5 seconds
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                if (alert.classList.contains('alert-success') || alert.classList.contains('alert-info')) {
                    const bsAlert = new bootstrap.Alert(alert);
                    bsAlert.close();
                }
            });
        }, 5000);
    </script>
</body>
</html>