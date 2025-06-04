<?php
/**
 * Secure SKU/Inventory Item Editor
 * Comprehensive editing with validation and audit logging
 */

require_once 'security-utils.php';
require_once 'db_config.php';

$security = SecurityUtils::getInstance();
$db = getDB();

// Check rate limiting and session
if (!$security->checkRateLimit()) {
    http_response_code(429);
    $security->logActivity('RATE_LIMIT_EXCEEDED', ['page' => 'edit_sku'], 'WARNING');
    die('Rate limit exceeded. Please try again later.');
}

if (!$security->validateSession('operator')) {
    $security->logActivity('UNAUTHORIZED_ACCESS_ATTEMPT', ['page' => 'edit_sku'], 'WARNING');
    header('Location: auth.php');
    exit();
}

$csrfToken = $security->generateCSRFToken();
$message = '';
$messageType = '';
$inventoryItem = null;
$skuMaster = null;

// Get inventory item ID
$itemId = (int) ($_GET['id'] ?? 0);

if ($itemId <= 0) {
    $security->logActivity('EDIT_SKU_INVALID_ID', ['provided_id' => $_GET['id'] ?? 'none'], 'WARNING');
    header('Location: secure-inventory.php');
    exit();
}

// Get inventory item and SKU master data
try {
    $inventoryItem = $db->fetchRow(
        "SELECT * FROM inventory WHERE id = ? AND deleted_at IS NULL",
        [$itemId]
    );
    
    if (!$inventoryItem) {
        $security->logActivity('EDIT_SKU_ITEM_NOT_FOUND', ['item_id' => $itemId], 'WARNING');
        header('Location: secure-inventory.php?error=item_not_found');
        exit();
    }
    
    // Get or create SKU master record
    $skuMaster = $db->fetchRow(
        "SELECT * FROM sku_master WHERE sku = ? AND deleted_at IS NULL",
        [$inventoryItem['sku']]
    );
    
    if (!$skuMaster) {
        // Create default SKU master record if it doesn't exist
        $skuMasterData = [
            'sku' => $inventoryItem['sku'],
            'description' => '',
            'category' => '',
            'unit_cost' => 0.00,
            'unit_price' => 0.00,
            'min_stock_level' => 10,
            'max_stock_level' => 100,
            'reorder_point' => 20,
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        $db->insert('sku_master', $skuMasterData);
        $skuMaster = $skuMasterData;
    }
    
} catch (Exception $e) {
    $security->logActivity('EDIT_SKU_FETCH_ERROR', ['item_id' => $itemId, 'error' => $e->getMessage()], 'ERROR');
    header('Location: secure-inventory.php?error=fetch_failed');
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$security->validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = 'Invalid CSRF token. Please try again.';
        $messageType = 'danger';
    } else {
        $result = handleInventoryUpdate($_POST, $inventoryItem, $skuMaster, $db, $security);
        $message = $result['message'];
        $messageType = $result['type'];
        
        if ($result['success']) {
            // Refresh data after successful update
            $inventoryItem = $db->fetchRow("SELECT * FROM inventory WHERE id = ?", [$itemId]);
            $skuMaster = $db->fetchRow("SELECT * FROM sku_master WHERE sku = ?", [$inventoryItem['sku']]);
        }
    }
}

// Get available locations and clients for dropdowns
$locations = getAvailableLocations($db);
$clients = getAvailableClients($db);
$categories = getAvailableCategories($db);

$security->logActivity('EDIT_SKU_ACCESS', ['item_id' => $itemId, 'sku' => $inventoryItem['sku']]);

/**
 * Handle inventory and SKU master update
 */
function handleInventoryUpdate($data, $currentItem, $currentSku, $db, $security) {
    try {
        // Validate input data
        $rules = [
            'sku' => ['required' => true, 'max_length' => 50, 'pattern' => '/^[A-Za-z0-9\-_]+$/', 'pattern_message' => 'SKU can only contain letters, numbers, hyphens, and underscores'],
            'description' => ['max_length' => 255],
            'quantity' => ['required' => true, 'type' => 'int', 'min_value' => 0],
            'location' => ['max_length' => 50],
            'client' => ['max_length' => 100],
            'unit_cost' => ['type' => 'float', 'min_value' => 0],
            'unit_price' => ['type' => 'float', 'min_value' => 0],
            'min_stock_level' => ['type' => 'int', 'min_value' => 0],
            'max_stock_level' => ['type' => 'int', 'min_value' => 0],
            'reorder_point' => ['type' => 'int', 'min_value' => 0]
        ];
        
        $errors = $security->validateInput($data, $rules);
        
        // Custom validations
        if (!empty($data['max_stock_level']) && !empty($data['min_stock_level'])) {
            if ((int)$data['max_stock_level'] < (int)$data['min_stock_level']) {
                $errors['max_stock_level'] = 'Maximum stock level must be greater than minimum stock level';
            }
        }
        
        if (!empty($data['reorder_point']) && !empty($data['max_stock_level'])) {
            if ((int)$data['reorder_point'] > (int)$data['max_stock_level']) {
                $errors['reorder_point'] = 'Reorder point must not exceed maximum stock level';
            }
        }
        
        // Check if SKU already exists for a different item
        if ($data['sku'] !== $currentItem['sku']) {
            $existingSku = $db->fetchValue(
                "SELECT COUNT(*) FROM inventory WHERE sku = ? AND id != ? AND deleted_at IS NULL",
                [$data['sku'], $currentItem['id']]
            );
            
            if ($existingSku > 0) {
                $errors['sku'] = 'SKU already exists for another inventory item';
            }
        }
        
        if (!empty($errors)) {
            return ['success' => false, 'message' => 'Validation errors: ' . implode(', ', $errors), 'type' => 'danger'];
        }
        
        // Perform updates in transaction
        $db->beginTransaction();
        
        try {
            $oldValues = $currentItem;
            $oldSkuValues = $currentSku;
            
            // Update inventory item
            $inventoryData = [
                'sku' => $security->sanitizeInput($data['sku'], 'alphanumeric'),
                'quantity' => (int)$data['quantity'],
                'location' => $security->sanitizeInput($data['location']),
                'client' => $security->sanitizeInput($data['client']),
                'updated_at' => date('Y-m-d H:i:s')
            ];
            
            $inventoryChanges = array_diff_assoc($inventoryData, array_intersect_key($oldValues, $inventoryData));
            
            if (!empty($inventoryChanges)) {
                $db->update('inventory', $inventoryData, 'id = ?', [':id' => $currentItem['id']]);
                
                // Log quantity change if it occurred
                if (isset($inventoryChanges['quantity'])) {
                    $quantityDiff = $inventoryData['quantity'] - $oldValues['quantity'];
                    if ($quantityDiff != 0) {
                        $adjustmentData = [
                            'sku' => $inventoryData['sku'],
                            'location' => $inventoryData['location'],
                            'client' => $inventoryData['client'],
                            'adjustment_type' => 'manual_adjustment',
                            'quantity' => $quantityDiff,
                            'reason' => 'Quantity updated via edit form',
                            'reference_number' => 'ADJ-' . date('Ymd') . '-' . $currentItem['id'],
                            'created_by' => $_SESSION['user_id'],
                            'created_at' => date('Y-m-d H:i:s')
                        ];
                        
                        $db->insert('inventory_adjustments', $adjustmentData);
                    }
                }
            }
            
            // Update SKU master
            $skuMasterData = [
                'sku' => $security->sanitizeInput($data['sku'], 'alphanumeric'),
                'description' => $security->sanitizeInput($data['description']),
                'category' => $security->sanitizeInput($data['category']),
                'unit_cost' => (float)($data['unit_cost'] ?? 0),
                'unit_price' => (float)($data['unit_price'] ?? 0),
                'min_stock_level' => (int)($data['min_stock_level'] ?? 10),
                'max_stock_level' => (int)($data['max_stock_level'] ?? 100),
                'reorder_point' => (int)($data['reorder_point'] ?? 20),
                'updated_at' => date('Y-m-d H:i:s')
            ];
            
            $skuChanges = array_diff_assoc($skuMasterData, array_intersect_key($oldSkuValues, $skuMasterData));
            
            if (!empty($skuChanges)) {
                $existing = $db->fetchValue("SELECT COUNT(*) FROM sku_master WHERE sku = ?", [$currentSku['sku']]);
                
                if ($existing > 0) {
                    $db->update('sku_master', $skuMasterData, 'sku = ?', [':sku' => $currentSku['sku']]);
                } else {
                    $skuMasterData['created_at'] = date('Y-m-d H:i:s');
                    $db->insert('sku_master', $skuMasterData);
                }
            }
            
            $db->commit();
            
            // Log the update
            $security->logActivity('INVENTORY_ITEM_UPDATED', [
                'item_id' => $currentItem['id'],
                'sku' => $data['sku'],
                'inventory_changes' => $inventoryChanges,
                'sku_master_changes' => $skuChanges
            ]);
            
            return ['success' => true, 'message' => 'Item updated successfully', 'type' => 'success'];
            
        } catch (Exception $e) {
            $db->rollback();
            throw $e;
        }
        
    } catch (Exception $e) {
        $security->logActivity('INVENTORY_UPDATE_FAILED', [
            'item_id' => $currentItem['id'],
            'error' => $e->getMessage()
        ], 'ERROR');
        
        return [
            'success' => false,
            'message' => 'Failed to update item: ' . $security->sanitizeErrorMessage($e->getMessage()),
            'type' => 'danger'
        ];
    }
}

/**
 * Get available locations
 */
function getAvailableLocations($db) {
    try {
        return $db->fetchAll(
            "SELECT DISTINCT location FROM inventory 
             WHERE location IS NOT NULL AND location != '' AND deleted_at IS NULL 
             ORDER BY location"
        );
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Get available clients
 */
function getAvailableClients($db) {
    try {
        return $db->fetchAll(
            "SELECT DISTINCT client FROM inventory 
             WHERE client IS NOT NULL AND client != '' AND deleted_at IS NULL 
             ORDER BY client"
        );
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Get available categories
 */
function getAvailableCategories($db) {
    try {
        return $db->fetchAll(
            "SELECT DISTINCT category FROM sku_master 
             WHERE category IS NOT NULL AND category != '' AND deleted_at IS NULL 
             ORDER BY category"
        );
    } catch (Exception $e) {
        return [];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Inventory Item - Secure WMS</title>
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
            --info-color: #3498db;
            --warning-color: #f39c12;
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
        
        .form-section {
            background: white;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 20px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
        }
        
        .section-header {
            border-bottom: 2px solid #f8f9fa;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }
        
        .form-control, .form-select {
            border-radius: 10px;
            border: 2px solid #e9ecef;
            transition: all 0.3s ease;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.2rem rgba(44, 62, 80, 0.25);
        }
        
        .btn {
            border-radius: 10px;
            padding: 10px 20px;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--primary-color), var(--info-color));
            border: none;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }
        
        .input-group-text {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            border: 2px solid #e9ecef;
            border-radius: 10px 0 0 10px;
        }
        
        .quantity-indicator {
            background: linear-gradient(135deg, #e3f2fd, #bbdefb);
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
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
        
        .calculation-display {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            margin-top: 15px;
        }
        
        .field-help {
            font-size: 0.875rem;
            color: #6c757d;
            margin-top: 5px;
        }
        
        .required-field::after {
            content: ' *';
            color: #dc3545;
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container-fluid">
            <a class="navbar-brand" href="secure-inventory.php">
                <i class="fas fa-edit"></i> Edit Inventory Item
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

        <form method="POST" id="editForm" novalidate>
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            
            <div class="row">
                <div class="col-lg-8">
                    <!-- Basic Information -->
                    <div class="form-section">
                        <div class="section-header">
                            <h5 class="mb-0"><i class="fas fa-box"></i> Basic Information</h5>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="sku" class="form-label required-field">SKU</label>
                                    <input type="text" class="form-control" id="sku" name="sku" 
                                           value="<?= $security->escapeOutput($inventoryItem['sku']) ?>" 
                                           required pattern="[A-Za-z0-9\-_]+" maxlength="50">
                                    <div class="field-help">Letters, numbers, hyphens, and underscores only</div>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="description" class="form-label">Description</label>
                                    <input type="text" class="form-control" id="description" name="description" 
                                           value="<?= $security->escapeOutput($skuMaster['description'] ?? '') ?>" 
                                           maxlength="255">
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="category" class="form-label">Category</label>
                                    <input type="text" class="form-control" id="category" name="category" 
                                           value="<?= $security->escapeOutput($skuMaster['category'] ?? '') ?>" 
                                           list="categories" maxlength="100">
                                    <datalist id="categories">
                                        <?php foreach ($categories as $cat): ?>
                                            <option value="<?= $security->escapeOutput($cat['category']) ?>">
                                        <?php endforeach; ?>
                                    </datalist>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="quantity" class="form-label required-field">Current Quantity</label>
                                    <input type="number" class="form-control" id="quantity" name="quantity" 
                                           value="<?= $inventoryItem['quantity'] ?>" 
                                           min="0" required onchange="updateCalculations()">
                                    <?php if ($security->hasRole($_SESSION['role'], 'supervisor')): ?>
                                        <div class="field-help">Changing quantity will create an adjustment record</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Location and Client -->
                    <div class="form-section">
                        <div class="section-header">
                            <h5 class="mb-0"><i class="fas fa-map-marker-alt"></i> Location & Client</h5>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="location" class="form-label">Location</label>
                                    <input type="text" class="form-control" id="location" name="location" 
                                           value="<?= $security->escapeOutput($inventoryItem['location'] ?? '') ?>" 
                                           list="locations" maxlength="50">
                                    <datalist id="locations">
                                        <?php foreach ($locations as $loc): ?>
                                            <option value="<?= $security->escapeOutput($loc['location']) ?>">
                                        <?php endforeach; ?>
                                    </datalist>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="client" class="form-label">Client</label>
                                    <input type="text" class="form-control" id="client" name="client" 
                                           value="<?= $security->escapeOutput($inventoryItem['client'] ?? '') ?>" 
                                           list="clients" maxlength="100">
                                    <datalist id="clients">
                                        <?php foreach ($clients as $client): ?>
                                            <option value="<?= $security->escapeOutput($client['client']) ?>">
                                        <?php endforeach; ?>
                                    </datalist>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Financial Information -->
                    <div class="form-section">
                        <div class="section-header">
                            <h5 class="mb-0"><i class="fas fa-dollar-sign"></i> Financial Information</h5>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="unit_cost" class="form-label">Unit Cost</label>
                                    <div class="input-group">
                                        <span class="input-group-text">$</span>
                                        <input type="number" class="form-control" id="unit_cost" name="unit_cost" 
                                               value="<?= $skuMaster['unit_cost'] ?? 0 ?>" 
                                               step="0.01" min="0" onchange="updateCalculations()">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="unit_price" class="form-label">Unit Price</label>
                                    <div class="input-group">
                                        <span class="input-group-text">$</span>
                                        <input type="number" class="form-control" id="unit_price" name="unit_price" 
                                               value="<?= $skuMaster['unit_price'] ?? 0 ?>" 
                                               step="0.01" min="0" onchange="updateCalculations()">
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="calculation-display" id="calculations">
                            <div class="row text-center">
                                <div class="col-4">
                                    <strong>Total Cost</strong><br>
                                    <span id="totalCost" class="text-info">$0.00</span>
                                </div>
                                <div class="col-4">
                                    <strong>Total Value</strong><br>
                                    <span id="totalValue" class="text-success">$0.00</span>
                                </div>
                                <div class="col-4">
                                    <strong>Potential Profit</strong><br>
                                    <span id="potentialProfit" class="text-primary">$0.00</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Stock Management -->
                    <div class="form-section">
                        <div class="section-header">
                            <h5 class="mb-0"><i class="fas fa-chart-line"></i> Stock Management</h5>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="min_stock_level" class="form-label">Minimum Stock Level</label>
                                    <input type="number" class="form-control" id="min_stock_level" name="min_stock_level" 
                                           value="<?= $skuMaster['min_stock_level'] ?? 10 ?>" 
                                           min="0" onchange="validateStockLevels()">
                                </div>
                            </div>
                            
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="max_stock_level" class="form-label">Maximum Stock Level</label>
                                    <input type="number" class="form-control" id="max_stock_level" name="max_stock_level" 
                                           value="<?= $skuMaster['max_stock_level'] ?? 100 ?>" 
                                           min="0" onchange="validateStockLevels()">
                                </div>
                            </div>
                            
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="reorder_point" class="form-label">Reorder Point</label>
                                    <input type="number" class="form-control" id="reorder_point" name="reorder_point" 
                                           value="<?= $skuMaster['reorder_point'] ?? 20 ?>" 
                                           min="0" onchange="validateStockLevels()">
                                </div>
                            </div>
                        </div>
                        
                        <div id="stockLevelWarnings"></div>
                    </div>
                </div>

                <div class="col-lg-4">
                    <!-- Current Status -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h6 class="mb-0"><i class="fas fa-info-circle"></i> Current Status</h6>
                        </div>
                        <div class="card-body">
                            <div class="quantity-indicator text-center">
                                <h3 class="mb-2"><?= number_format($inventoryItem['quantity']) ?></h3>
                                <span class="stock-status stock-<?= getStockStatus($inventoryItem, $skuMaster) ?>">
                                    <?= ucfirst(getStockStatus($inventoryItem, $skuMaster)) ?> Stock
                                </span>
                            </div>
                            
                            <div class="d-grid gap-2">
                                <small class="text-muted">
                                    <strong>ID:</strong> <?= $inventoryItem['id'] ?>
                                </small>
                                <small class="text-muted">
                                    <strong>Created:</strong> <?= date('M j, Y H:i', strtotime($inventoryItem['created_at'])) ?>
                                </small>
                                <small class="text-muted">
                                    <strong>Last Updated:</strong> <?= date('M j, Y H:i', strtotime($inventoryItem['updated_at'])) ?>
                                </small>
                            </div>
                        </div>
                    </div>

                    <!-- Actions -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h6 class="mb-0"><i class="fas fa-tools"></i> Actions</h6>
                        </div>
                        <div class="card-body">
                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save"></i> Update Item
                                </button>
                                <a href="fetch_sku_info_secure.php?sku=<?= urlencode($inventoryItem['sku']) ?>" 
                                   class="btn btn-outline-info">
                                    <i class="fas fa-eye"></i> View Details
                                </a>
                                <a href="secure-inventory.php" class="btn btn-outline-secondary">
                                    <i class="fas fa-times"></i> Cancel
                                </a>
                                <?php if ($security->hasRole($_SESSION['role'], 'supervisor')): ?>
                                    <a href="inventory_delete_secure.php?id=<?= $inventoryItem['id'] ?>" 
                                       class="btn btn-outline-danger"
                                       onclick="return confirm('Delete this inventory item?')">
                                        <i class="fas fa-trash"></i> Delete
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Help -->
                    <div class="card">
                        <div class="card-header">
                            <h6 class="mb-0"><i class="fas fa-question-circle"></i> Help</h6>
                        </div>
                        <div class="card-body">
                            <ul class="small mb-0">
                                <li>SKU changes will update all references</li>
                                <li>Quantity changes create adjustment records</li>
                                <li>Stock levels help with reorder alerts</li>
                                <li>Financial data is used for reporting</li>
                                <li>All changes are logged for audit</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function updateCalculations() {
            const quantity = parseFloat(document.getElementById('quantity').value) || 0;
            const unitCost = parseFloat(document.getElementById('unit_cost').value) || 0;
            const unitPrice = parseFloat(document.getElementById('unit_price').value) || 0;
            
            const totalCost = quantity * unitCost;
            const totalValue = quantity * unitPrice;
            const potentialProfit = totalValue - totalCost;
            
            document.getElementById('totalCost').textContent = '$' + totalCost.toFixed(2);
            document.getElementById('totalValue').textContent = '$' + totalValue.toFixed(2);
            document.getElementById('potentialProfit').textContent = '$' + potentialProfit.toFixed(2);
            
            // Update profit color
            const profitElement = document.getElementById('potentialProfit');
            if (potentialProfit > 0) {
                profitElement.className = 'text-success';
            } else if (potentialProfit < 0) {
                profitElement.className = 'text-danger';
            } else {
                profitElement.className = 'text-secondary';
            }
        }
        
        function validateStockLevels() {
            const minLevel = parseInt(document.getElementById('min_stock_level').value) || 0;
            const maxLevel = parseInt(document.getElementById('max_stock_level').value) || 0;
            const reorderPoint = parseInt(document.getElementById('reorder_point').value) || 0;
            const currentQuantity = parseInt(document.getElementById('quantity').value) || 0;
            
            const warningsContainer = document.getElementById('stockLevelWarnings');
            let warnings = [];
            
            if (maxLevel > 0 && minLevel > maxLevel) {
                warnings.push('Maximum stock level must be greater than minimum stock level');
            }
            
            if (maxLevel > 0 && reorderPoint > maxLevel) {
                warnings.push('Reorder point should not exceed maximum stock level');
            }
            
            if (minLevel > 0 && currentQuantity < minLevel) {
                warnings.push('Current quantity is below minimum stock level');
            }
            
            if (reorderPoint > 0 && currentQuantity <= reorderPoint) {
                warnings.push('Current quantity is at or below reorder point');
            }
            
            if (warnings.length > 0) {
                warningsContainer.innerHTML = `
                    <div class="alert alert-warning alert-sm">
                        <i class="fas fa-exclamation-triangle"></i>
                        <ul class="mb-0 ms-3">
                            ${warnings.map(w => `<li>${w}</li>`).join('')}
                        </ul>
                    </div>
                `;
            } else {
                warningsContainer.innerHTML = '';
            }
        }
        
        // Real-time validation
        document.getElementById('editForm').addEventListener('input', function(e) {
            if (e.target.type === 'number') {
                updateCalculations();
                validateStockLevels();
            }
        });
        
        // Initialize calculations
        updateCalculations();
        validateStockLevels();
        
        // Form submission validation
        document.getElementById('editForm').addEventListener('submit', function(e) {
            const minLevel = parseInt(document.getElementById('min_stock_level').value) || 0;
            const maxLevel = parseInt(document.getElementById('max_stock_level').value) || 0;
            
            if (maxLevel > 0 && minLevel > maxLevel) {
                e.preventDefault();
                alert('Please fix stock level validation errors before submitting');
                return false;
            }
        });
        
        // Auto-save warning
        let formChanged = false;
        document.getElementById('editForm').addEventListener('change', function() {
            formChanged = true;
        });
        
        window.addEventListener('beforeunload', function(e) {
            if (formChanged) {
                e.preventDefault();
                e.returnValue = '';
            }
        });
        
        // Mark form as saved on submission
        document.getElementById('editForm').addEventListener('submit', function() {
            formChanged = false;
        });
    </script>
</body>
</html>

<?php
function getStockStatus($item, $sku) {
    if ($item['quantity'] == 0) {
        return 'zero';
    } elseif ($item['quantity'] <= ($sku['min_stock_level'] ?? 10)) {
        return 'low';
    } else {
        return 'normal';
    }
}
?>