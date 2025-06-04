<?php
/**
 * Secure ASN Edit Form
 * Advanced Shipping Notice editing with access control and comprehensive validation
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
    $security->logActivity('RATE_LIMIT_EXCEEDED', ['page' => 'edit_asn'], 'WARNING');
    die('Rate limit exceeded. Please try again later.');
}

$asnId = (int) ($_GET['id'] ?? 0);
$created = isset($_GET['created']) && $_GET['created'] == '1';

if (!$asnId) {
    header('Location: inbound_secure.php?error=' . urlencode('Invalid ASN ID'));
    exit();
}

// Get ASN details with supplier information
$asn = $db->fetchRow("
    SELECT a.*, s.name as supplier_name, s.code as supplier_code,
           s.contact_person as supplier_contact, s.phone as supplier_phone,
           creator.username as created_by_name,
           updater.username as updated_by_name
    FROM asn a
    LEFT JOIN suppliers s ON a.supplier_id = s.id
    LEFT JOIN users creator ON a.created_by = creator.id
    LEFT JOIN users updater ON a.updated_by = updater.id
    WHERE a.id = :id AND a.deleted_at IS NULL
", [':id' => $asnId]);

if (!$asn) {
    header('Location: inbound_secure.php?error=' . urlencode('ASN not found'));
    exit();
}

// Check permissions for editing
$canEdit = has_role('operator');
$canChangeStatus = has_role('supervisor');
$canDelete = has_role('manager');

// Check if ASN can be edited based on status
$editableStatuses = ['draft', 'confirmed'];
$canEditContent = $canEdit && in_array($asn['status'], $editableStatuses);

$security->logActivity('ASN_EDIT_PAGE_ACCESS', [
    'asn_id' => $asnId,
    'asn_number' => $asn['asn_number'],
    'user_id' => get_current_user_id()
]);

$csrfToken = $security->generateCSRFToken();
$message = '';
$messageType = '';
$formData = $asn;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$security->validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = 'Invalid security token. Please try again.';
        $messageType = 'danger';
        $security->logActivity('CSRF_TOKEN_VALIDATION_FAILED', ['page' => 'edit_asn', 'asn_id' => $asnId], 'WARNING');
    } else {
        try {
            $action = $_POST['action'] ?? 'update';
            
            if ($action === 'update' && $canEditContent) {
                // Sanitize and validate input
                $formData = [
                    'asn_number' => $security->sanitizeInput($_POST['asn_number'] ?? ''),
                    'reference_number' => $security->sanitizeInput($_POST['reference_number'] ?? ''),
                    'supplier_id' => (int) ($_POST['supplier_id'] ?? 0),
                    'expected_date' => $security->sanitizeInput($_POST['expected_date'] ?? ''),
                    'shipping_method' => $security->sanitizeInput($_POST['shipping_method'] ?? ''),
                    'tracking_number' => $security->sanitizeInput($_POST['tracking_number'] ?? ''),
                    'notes' => $security->sanitizeInput($_POST['notes'] ?? ''),
                    'priority' => $security->sanitizeInput($_POST['priority'] ?? 'normal'),
                    'warehouse_location' => $security->sanitizeInput($_POST['warehouse_location'] ?? ''),
                    'contact_person' => $security->sanitizeInput($_POST['contact_person'] ?? ''),
                    'contact_phone' => $security->sanitizeInput($_POST['contact_phone'] ?? ''),
                    'special_instructions' => $security->sanitizeInput($_POST['special_instructions'] ?? '')
                ];

                // Validation rules
                $validationRules = [
                    'asn_number' => [
                        'required' => true,
                        'min_length' => 3,
                        'max_length' => 50,
                        'pattern' => '/^[A-Z0-9\-_]+$/i',
                        'pattern_message' => 'ASN number can only contain letters, numbers, hyphens, and underscores'
                    ],
                    'supplier_id' => [
                        'required' => true,
                        'type' => 'int'
                    ],
                    'expected_date' => [
                        'required' => true
                    ],
                    'shipping_method' => [
                        'required' => true,
                        'max_length' => 100
                    ],
                    'priority' => [
                        'required' => true
                    ]
                ];

                $validationErrors = $security->validateInput($formData, $validationRules);

                // Additional custom validations
                if (empty($validationErrors)) {
                    // Check if ASN number already exists (excluding current ASN)
                    $existingAsn = $db->fetchRow(
                        "SELECT id FROM asn WHERE asn_number = :asn_number AND id != :id AND deleted_at IS NULL",
                        [':asn_number' => $formData['asn_number'], ':id' => $asnId]
                    );
                    
                    if ($existingAsn) {
                        $validationErrors['asn_number'] = 'ASN number already exists';
                    }

                    // Validate supplier exists and is active
                    $supplier = $db->fetchRow(
                        "SELECT id, name FROM suppliers WHERE id = :id AND is_active = 1 AND deleted_at IS NULL",
                        [':id' => $formData['supplier_id']]
                    );
                    
                    if (!$supplier) {
                        $validationErrors['supplier_id'] = 'Invalid or inactive supplier selected';
                    }

                    // Validate priority
                    $validPriorities = ['low', 'normal', 'high', 'urgent'];
                    if (!in_array($formData['priority'], $validPriorities)) {
                        $validationErrors['priority'] = 'Invalid priority level';
                    }
                }

                if (empty($validationErrors)) {
                    $db->beginTransaction();

                    try {
                        // Prepare ASN data for update
                        $updateData = [
                            'asn_number' => $formData['asn_number'],
                            'reference_number' => $formData['reference_number'],
                            'supplier_id' => $formData['supplier_id'],
                            'expected_date' => $formData['expected_date'],
                            'shipping_method' => $formData['shipping_method'],
                            'tracking_number' => $formData['tracking_number'],
                            'notes' => $formData['notes'],
                            'priority' => $formData['priority'],
                            'warehouse_location' => $formData['warehouse_location'],
                            'contact_person' => $formData['contact_person'],
                            'contact_phone' => $formData['contact_phone'],
                            'special_instructions' => $formData['special_instructions'],
                            'updated_by' => get_current_user_id(),
                            'updated_at' => date('Y-m-d H:i:s')
                        ];

                        $updated = $db->update('asn', $updateData, 'id = :id', [':id' => $asnId]);

                        if ($updated) {
                            $security->logActivity('ASN_UPDATED', [
                                'asn_id' => $asnId,
                                'asn_number' => $formData['asn_number'],
                                'changes' => array_diff_assoc($formData, $asn)
                            ]);

                            $db->commit();
                            
                            // Refresh ASN data
                            $asn = $db->fetchRow("
                                SELECT a.*, s.name as supplier_name, s.code as supplier_code,
                                       s.contact_person as supplier_contact, s.phone as supplier_phone,
                                       creator.username as created_by_name,
                                       updater.username as updated_by_name
                                FROM asn a
                                LEFT JOIN suppliers s ON a.supplier_id = s.id
                                LEFT JOIN users creator ON a.created_by = creator.id
                                LEFT JOIN users updater ON a.updated_by = updater.id
                                WHERE a.id = :id AND a.deleted_at IS NULL
                            ", [':id' => $asnId]);
                            
                            $formData = $asn;
                            $message = 'ASN updated successfully!';
                            $messageType = 'success';
                        } else {
                            throw new Exception('Failed to update ASN record');
                        }

                    } catch (Exception $e) {
                        $db->rollback();
                        throw $e;
                    }

                } else {
                    $message = 'Please correct the following errors:<br>' . implode('<br>', $validationErrors);
                    $messageType = 'danger';
                }
                
            } elseif ($action === 'change_status' && $canChangeStatus) {
                $newStatus = $security->sanitizeInput($_POST['new_status'] ?? '');
                $validStatuses = ['draft', 'confirmed', 'in_transit', 'arrived', 'receiving', 'completed', 'cancelled'];
                
                if (in_array($newStatus, $validStatuses)) {
                    $db->beginTransaction();
                    
                    try {
                        $updated = $db->update('asn', [
                            'status' => $newStatus,
                            'updated_by' => get_current_user_id(),
                            'updated_at' => date('Y-m-d H:i:s')
                        ], 'id = :id', [':id' => $asnId]);
                        
                        if ($updated) {
                            $security->logActivity('ASN_STATUS_CHANGED', [
                                'asn_id' => $asnId,
                                'asn_number' => $asn['asn_number'],
                                'old_status' => $asn['status'],
                                'new_status' => $newStatus
                            ]);
                            
                            $db->commit();
                            $asn['status'] = $newStatus;
                            $message = 'ASN status updated successfully!';
                            $messageType = 'success';
                        } else {
                            $db->rollback();
                            $message = 'Failed to update ASN status';
                            $messageType = 'danger';
                        }
                        
                    } catch (Exception $e) {
                        $db->rollback();
                        throw $e;
                    }
                } else {
                    $message = 'Invalid status selected';
                    $messageType = 'danger';
                }
            } else {
                $message = 'You do not have permission to perform this action';
                $messageType = 'danger';
            }

        } catch (Exception $e) {
            $security->logActivity('ASN_UPDATE_ERROR', [
                'asn_id' => $asnId,
                'error' => $e->getMessage()
            ], 'ERROR');
            
            $message = 'Failed to update ASN. Please try again.';
            $messageType = 'danger';
        }
    }
}

// Get suppliers for dropdown
$suppliers = $db->fetchAll("
    SELECT id, name, code, address, contact_person, phone 
    FROM suppliers 
    WHERE is_active = 1 AND deleted_at IS NULL 
    ORDER BY name
");

// Get warehouse locations
$warehouseLocations = $db->fetchAll("
    SELECT DISTINCT location 
    FROM inventory 
    WHERE location IS NOT NULL AND location != '' 
    ORDER BY location
");

// Get ASN line items
$asnLines = $db->fetchAll("
    SELECT al.*, i.sku, i.description, i.unit_of_measure,
           COALESCE(i.on_hand_quantity, 0) as current_stock
    FROM asn_lines al
    LEFT JOIN inventory i ON al.sku = i.sku
    WHERE al.asn_id = :asn_id AND al.deleted_at IS NULL
    ORDER BY al.line_number, al.created_at
", [':asn_id' => $asnId]);

// Calculate totals
$totalLines = count($asnLines);
$totalQuantity = array_sum(array_column($asnLines, 'quantity'));
$totalReceived = array_sum(array_column($asnLines, 'received_quantity'));
$progressPercentage = $totalQuantity > 0 ? round(($totalReceived / $totalQuantity) * 100, 1) : 0;

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit ASN <?php echo htmlspecialchars($asn['asn_number']); ?> - WMS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        .header-section {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem 0;
            margin-bottom: 2rem;
        }
        .status-badge {
            font-size: 1rem;
            padding: 0.5rem 1rem;
            border-radius: 25px;
        }
        .info-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            padding: 1.5rem;
            margin-bottom: 2rem;
        }
        .form-container {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        .form-header {
            background: #f8f9fa;
            padding: 1.5rem;
            border-bottom: 1px solid #dee2e6;
        }
        .section-title {
            color: #495057;
            font-weight: 600;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid #e9ecef;
        }
        .priority-badge {
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-weight: 500;
            margin: 0.25rem;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        .priority-badge.active {
            transform: scale(1.05);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        .priority-low { background: #d1ecf1; color: #0c5460; }
        .priority-normal { background: #d4edda; color: #155724; }
        .priority-high { background: #fff3cd; color: #856404; }
        .priority-urgent { background: #f8d7da; color: #721c24; }
        .progress-info {
            background: linear-gradient(90deg, #28a745 0%, #28a745 var(--progress, 0%), #e9ecef var(--progress, 0%), #e9ecef 100%);
            color: white;
            padding: 1rem;
            border-radius: 10px;
            font-weight: 500;
        }
        .readonly-field {
            background-color: #f8f9fa !important;
            cursor: not-allowed;
        }
        .status-timeline {
            display: flex;
            justify-content: space-between;
            margin: 2rem 0;
        }
        .status-step {
            flex: 1;
            text-align: center;
            position: relative;
        }
        .status-step:not(:last-child)::after {
            content: '';
            position: absolute;
            top: 15px;
            right: -50%;
            width: 100%;
            height: 2px;
            background: #e9ecef;
            z-index: 1;
        }
        .status-step.active::after {
            background: #28a745;
        }
        .status-step-icon {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #e9ecef;
            color: #6c757d;
            position: relative;
            z-index: 2;
        }
        .status-step.active .status-step-icon {
            background: #28a745;
            color: white;
        }
        .lines-preview {
            max-height: 400px;
            overflow-y: auto;
        }
    </style>
</head>
<body class="bg-light">
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container-fluid">
            <a class="navbar-brand" href="secure-dashboard.php">
                <i class="fas fa-warehouse me-2"></i>WMS - Edit ASN
            </a>
            <div class="navbar-nav ms-auto">
                <span class="navbar-text me-3">
                    Welcome, <?php echo htmlspecialchars(get_user_full_name()); ?>
                </span>
                <a class="nav-link" href="inbound_secure.php">
                    <i class="fas fa-arrow-left me-1"></i>Back to Inbound
                </a>
            </div>
        </div>
    </nav>

    <!-- Header Section -->
    <div class="header-section">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <h2><i class="fas fa-edit me-2"></i>Edit ASN: <?php echo htmlspecialchars($asn['asn_number']); ?></h2>
                    <p class="mb-0">Supplier: <?php echo htmlspecialchars($asn['supplier_name']); ?></p>
                </div>
                <div class="col-md-6 text-md-end">
                    <span class="status-badge badge bg-<?php 
                        echo match($asn['status']) {
                            'draft' => 'secondary',
                            'confirmed' => 'primary',
                            'in_transit' => 'warning',
                            'arrived' => 'info',
                            'receiving' => 'warning',
                            'completed' => 'success',
                            'cancelled' => 'danger',
                            default => 'light text-dark'
                        };
                    ?>">
                        <?php echo ucfirst(str_replace('_', ' ', $asn['status'])); ?>
                    </span>
                </div>
            </div>
        </div>
    </div>

    <div class="container py-4">
        <?php if ($created): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i>
                ASN created successfully! You can now add line items and manage the shipment.
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($message): ?>
            <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
                <?php echo $message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Status Timeline -->
        <div class="info-card">
            <h5><i class="fas fa-route me-2"></i>Status Timeline</h5>
            <div class="status-timeline">
                <?php
                $statuses = [
                    'draft' => ['icon' => 'fas fa-file-alt', 'label' => 'Draft'],
                    'confirmed' => ['icon' => 'fas fa-check-circle', 'label' => 'Confirmed'],
                    'in_transit' => ['icon' => 'fas fa-truck', 'label' => 'In Transit'],
                    'arrived' => ['icon' => 'fas fa-warehouse', 'label' => 'Arrived'],
                    'receiving' => ['icon' => 'fas fa-boxes', 'label' => 'Receiving'],
                    'completed' => ['icon' => 'fas fa-check-double', 'label' => 'Completed']
                ];
                
                $currentStatusIndex = array_search($asn['status'], array_keys($statuses));
                foreach ($statuses as $statusKey => $statusInfo):
                    $isActive = array_search($statusKey, array_keys($statuses)) <= $currentStatusIndex;
                ?>
                    <div class="status-step <?php echo $isActive ? 'active' : ''; ?>">
                        <div class="status-step-icon">
                            <i class="<?php echo $statusInfo['icon']; ?>"></i>
                        </div>
                        <div class="mt-2">
                            <small><?php echo $statusInfo['label']; ?></small>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Progress Information -->
        <div class="info-card">
            <div class="row">
                <div class="col-md-3">
                    <div class="progress-info text-center" style="--progress: <?php echo $progressPercentage; ?>%;">
                        <div>Receiving Progress</div>
                        <h4><?php echo $progressPercentage; ?>%</h4>
                        <small><?php echo number_format($totalReceived); ?> / <?php echo number_format($totalQuantity); ?> units</small>
                    </div>
                </div>
                <div class="col-md-9">
                    <div class="row text-center">
                        <div class="col-md-3">
                            <h4 class="text-primary"><?php echo number_format($totalLines); ?></h4>
                            <small class="text-muted">Line Items</small>
                        </div>
                        <div class="col-md-3">
                            <h4 class="text-info"><?php echo number_format($totalQuantity); ?></h4>
                            <small class="text-muted">Expected Units</small>
                        </div>
                        <div class="col-md-3">
                            <h4 class="text-success"><?php echo number_format($totalReceived); ?></h4>
                            <small class="text-muted">Received Units</small>
                        </div>
                        <div class="col-md-3">
                            <h4 class="text-warning"><?php echo number_format($totalQuantity - $totalReceived); ?></h4>
                            <small class="text-muted">Pending Units</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center">
                    <h4>ASN Details</h4>
                    <div>
                        <a href="asn_lines_secure.php?id=<?php echo $asnId; ?>" class="btn btn-primary">
                            <i class="fas fa-list me-2"></i>Manage Line Items
                        </a>
                        <?php if ($canChangeStatus): ?>
                            <button class="btn btn-outline-info ms-2" data-bs-toggle="modal" data-bs-target="#statusModal">
                                <i class="fas fa-sync-alt me-2"></i>Change Status
                            </button>
                        <?php endif; ?>
                        <?php if ($asn['status'] === 'arrived' || $asn['status'] === 'receiving'): ?>
                            <a href="asn_process_secure.php?id=<?php echo $asnId; ?>" class="btn btn-success ms-2">
                                <i class="fas fa-arrow-right me-2"></i>Process ASN
                            </a>
                        <?php endif; ?>
                        <?php if ($canDelete): ?>
                            <a href="delete_asn_secure.php?id=<?php echo $asnId; ?>" class="btn btn-outline-danger ms-2" 
                               onclick="return confirm('Are you sure you want to delete this ASN?')">
                                <i class="fas fa-trash me-2"></i>Delete
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- ASN Form -->
            <div class="col-lg-8">
                <div class="form-container">
                    <div class="form-header">
                        <h5><i class="fas fa-edit me-2"></i>ASN Information</h5>
                        <?php if (!$canEditContent): ?>
                            <small class="text-muted">
                                <i class="fas fa-lock me-1"></i>
                                ASN cannot be edited in current status or you don't have permission
                            </small>
                        <?php endif; ?>
                    </div>

                    <div class="p-3">
                        <form method="POST" id="asnForm" novalidate>
                            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                            <input type="hidden" name="action" value="update">

                            <!-- Basic Information -->
                            <h6 class="section-title">
                                <i class="fas fa-info-circle me-2"></i>Basic Information
                            </h6>

                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <div class="form-floating mb-3">
                                        <input type="text" class="form-control <?php echo !$canEditContent ? 'readonly-field' : ''; ?>" 
                                               id="asn_number" name="asn_number" 
                                               value="<?php echo htmlspecialchars($formData['asn_number']); ?>"
                                               placeholder="ASN Number" required maxlength="50"
                                               <?php echo !$canEditContent ? 'readonly' : ''; ?>>
                                        <label for="asn_number">ASN Number *</label>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-floating mb-3">
                                        <input type="text" class="form-control <?php echo !$canEditContent ? 'readonly-field' : ''; ?>" 
                                               id="reference_number" name="reference_number" 
                                               value="<?php echo htmlspecialchars($formData['reference_number']); ?>"
                                               placeholder="Reference Number" maxlength="100"
                                               <?php echo !$canEditContent ? 'readonly' : ''; ?>>
                                        <label for="reference_number">Reference Number</label>
                                    </div>
                                </div>
                            </div>

                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <div class="form-floating mb-3">
                                        <select class="form-select <?php echo !$canEditContent ? 'readonly-field' : ''; ?>" 
                                                id="supplier_id" name="supplier_id" required
                                                <?php echo !$canEditContent ? 'disabled' : ''; ?>>
                                            <option value="">Select Supplier</option>
                                            <?php foreach ($suppliers as $supplier): ?>
                                                <option value="<?php echo $supplier['id']; ?>" 
                                                        <?php echo $formData['supplier_id'] == $supplier['id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($supplier['name']); ?>
                                                    <?php if ($supplier['code']): ?>
                                                        (<?php echo htmlspecialchars($supplier['code']); ?>)
                                                    <?php endif; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <label for="supplier_id">Supplier *</label>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-floating mb-3">
                                        <input type="date" class="form-control <?php echo !$canEditContent ? 'readonly-field' : ''; ?>" 
                                               id="expected_date" name="expected_date" 
                                               value="<?php echo htmlspecialchars($formData['expected_date']); ?>"
                                               required <?php echo !$canEditContent ? 'readonly' : ''; ?>>
                                        <label for="expected_date">Expected Date *</label>
                                    </div>
                                </div>
                            </div>

                            <!-- Priority Selection -->
                            <div class="mb-3">
                                <label class="form-label">Priority Level *</label>
                                <div class="d-flex flex-wrap">
                                    <?php
                                    $priorities = [
                                        'low' => ['label' => 'Low', 'icon' => 'fas fa-arrow-down'],
                                        'normal' => ['label' => 'Normal', 'icon' => 'fas fa-minus'],
                                        'high' => ['label' => 'High', 'icon' => 'fas fa-arrow-up'],
                                        'urgent' => ['label' => 'Urgent', 'icon' => 'fas fa-exclamation-triangle']
                                    ];
                                    ?>
                                    <?php foreach ($priorities as $value => $priority): ?>
                                        <label class="priority-badge priority-<?php echo $value; ?> <?php echo $formData['priority'] === $value ? 'active' : ''; ?>">
                                            <input type="radio" name="priority" value="<?php echo $value; ?>" 
                                                   <?php echo $formData['priority'] === $value ? 'checked' : ''; ?>
                                                   <?php echo !$canEditContent ? 'disabled' : ''; ?>
                                                   style="display: none;">
                                            <i class="<?php echo $priority['icon']; ?> me-2"></i>
                                            <?php echo $priority['label']; ?>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <!-- Shipping Information -->
                            <h6 class="section-title">
                                <i class="fas fa-truck me-2"></i>Shipping Information
                            </h6>

                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <div class="form-floating mb-3">
                                        <select class="form-select <?php echo !$canEditContent ? 'readonly-field' : ''; ?>" 
                                                id="shipping_method" name="shipping_method" required
                                                <?php echo !$canEditContent ? 'disabled' : ''; ?>>
                                            <option value="">Select Shipping Method</option>
                                            <option value="ground" <?php echo $formData['shipping_method'] === 'ground' ? 'selected' : ''; ?>>Ground</option>
                                            <option value="express" <?php echo $formData['shipping_method'] === 'express' ? 'selected' : ''; ?>>Express</option>
                                            <option value="overnight" <?php echo $formData['shipping_method'] === 'overnight' ? 'selected' : ''; ?>>Overnight</option>
                                            <option value="freight" <?php echo $formData['shipping_method'] === 'freight' ? 'selected' : ''; ?>>Freight</option>
                                            <option value="pickup" <?php echo $formData['shipping_method'] === 'pickup' ? 'selected' : ''; ?>>Pickup</option>
                                            <option value="other" <?php echo $formData['shipping_method'] === 'other' ? 'selected' : ''; ?>>Other</option>
                                        </select>
                                        <label for="shipping_method">Shipping Method *</label>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-floating mb-3">
                                        <input type="text" class="form-control <?php echo !$canEditContent ? 'readonly-field' : ''; ?>" 
                                               id="tracking_number" name="tracking_number" 
                                               value="<?php echo htmlspecialchars($formData['tracking_number']); ?>"
                                               placeholder="Tracking Number" maxlength="100"
                                               <?php echo !$canEditContent ? 'readonly' : ''; ?>>
                                        <label for="tracking_number">Tracking Number</label>
                                    </div>
                                </div>
                            </div>

                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <div class="form-floating mb-3">
                                        <select class="form-select <?php echo !$canEditContent ? 'readonly-field' : ''; ?>" 
                                                id="warehouse_location" name="warehouse_location"
                                                <?php echo !$canEditContent ? 'disabled' : ''; ?>>
                                            <option value="">Select Location</option>
                                            <?php foreach ($warehouseLocations as $location): ?>
                                                <option value="<?php echo htmlspecialchars($location['location']); ?>"
                                                        <?php echo $formData['warehouse_location'] === $location['location'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($location['location']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                            <option value="DOCK-A" <?php echo $formData['warehouse_location'] === 'DOCK-A' ? 'selected' : ''; ?>>DOCK-A</option>
                                            <option value="DOCK-B" <?php echo $formData['warehouse_location'] === 'DOCK-B' ? 'selected' : ''; ?>>DOCK-B</option>
                                            <option value="DOCK-C" <?php echo $formData['warehouse_location'] === 'DOCK-C' ? 'selected' : ''; ?>>DOCK-C</option>
                                        </select>
                                        <label for="warehouse_location">Receiving Location</label>
                                    </div>
                                </div>
                            </div>

                            <!-- Contact Information -->
                            <h6 class="section-title">
                                <i class="fas fa-address-book me-2"></i>Contact Information
                            </h6>

                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <div class="form-floating mb-3">
                                        <input type="text" class="form-control <?php echo !$canEditContent ? 'readonly-field' : ''; ?>" 
                                               id="contact_person" name="contact_person" 
                                               value="<?php echo htmlspecialchars($formData['contact_person']); ?>"
                                               placeholder="Contact Person" maxlength="100"
                                               <?php echo !$canEditContent ? 'readonly' : ''; ?>>
                                        <label for="contact_person">Contact Person</label>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-floating mb-3">
                                        <input type="tel" class="form-control <?php echo !$canEditContent ? 'readonly-field' : ''; ?>" 
                                               id="contact_phone" name="contact_phone" 
                                               value="<?php echo htmlspecialchars($formData['contact_phone']); ?>"
                                               placeholder="Contact Phone" maxlength="20"
                                               <?php echo !$canEditContent ? 'readonly' : ''; ?>>
                                        <label for="contact_phone">Contact Phone</label>
                                    </div>
                                </div>
                            </div>

                            <!-- Additional Information -->
                            <h6 class="section-title">
                                <i class="fas fa-sticky-note me-2"></i>Additional Information
                            </h6>

                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <div class="form-floating mb-3">
                                        <textarea class="form-control <?php echo !$canEditContent ? 'readonly-field' : ''; ?>" 
                                                  id="notes" name="notes" placeholder="General Notes" 
                                                  style="height: 120px;" maxlength="1000"
                                                  <?php echo !$canEditContent ? 'readonly' : ''; ?>><?php echo htmlspecialchars($formData['notes']); ?></textarea>
                                        <label for="notes">General Notes</label>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-floating mb-3">
                                        <textarea class="form-control <?php echo !$canEditContent ? 'readonly-field' : ''; ?>" 
                                                  id="special_instructions" name="special_instructions" 
                                                  placeholder="Special Instructions" style="height: 120px;" maxlength="1000"
                                                  <?php echo !$canEditContent ? 'readonly' : ''; ?>><?php echo htmlspecialchars($formData['special_instructions']); ?></textarea>
                                        <label for="special_instructions">Special Instructions</label>
                                    </div>
                                </div>
                            </div>

                            <!-- Form Actions -->
                            <?php if ($canEditContent): ?>
                                <div class="d-flex justify-content-between">
                                    <a href="inbound_secure.php" class="btn btn-outline-secondary">
                                        <i class="fas fa-times me-2"></i>Cancel
                                    </a>
                                    <button type="submit" class="btn btn-success">
                                        <i class="fas fa-save me-2"></i>Update ASN
                                    </button>
                                </div>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Side Panel -->
            <div class="col-lg-4">
                <!-- ASN Information -->
                <div class="info-card">
                    <h6><i class="fas fa-info-circle me-2"></i>ASN Information</h6>
                    <div class="row">
                        <div class="col-6"><strong>Created:</strong></div>
                        <div class="col-6"><?php echo date('M j, Y g:i A', strtotime($asn['created_at'])); ?></div>
                    </div>
                    <div class="row">
                        <div class="col-6"><strong>Created by:</strong></div>
                        <div class="col-6"><?php echo htmlspecialchars($asn['created_by_name'] ?? 'Unknown'); ?></div>
                    </div>
                    <?php if ($asn['updated_at'] !== $asn['created_at']): ?>
                        <div class="row">
                            <div class="col-6"><strong>Updated:</strong></div>
                            <div class="col-6"><?php echo date('M j, Y g:i A', strtotime($asn['updated_at'])); ?></div>
                        </div>
                        <div class="row">
                            <div class="col-6"><strong>Updated by:</strong></div>
                            <div class="col-6"><?php echo htmlspecialchars($asn['updated_by_name'] ?? 'Unknown'); ?></div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Line Items Preview -->
                <div class="info-card">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h6><i class="fas fa-list me-2"></i>Line Items (<?php echo $totalLines; ?>)</h6>
                        <a href="asn_lines_secure.php?id=<?php echo $asnId; ?>" class="btn btn-sm btn-outline-primary">
                            <i class="fas fa-edit me-1"></i>Manage
                        </a>
                    </div>
                    
                    <?php if ($totalLines > 0): ?>
                        <div class="lines-preview">
                            <div class="table-responsive">
                                <table class="table table-sm table-hover">
                                    <thead>
                                        <tr>
                                            <th>SKU</th>
                                            <th>Qty</th>
                                            <th>Rcvd</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach (array_slice($asnLines, 0, 10) as $line): ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($line['sku']); ?></strong>
                                                    <?php if ($line['description']): ?>
                                                        <br><small class="text-muted"><?php echo htmlspecialchars(substr($line['description'], 0, 30)); ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo number_format($line['quantity']); ?></td>
                                                <td>
                                                    <span class="badge bg-<?php echo $line['received_quantity'] >= $line['quantity'] ? 'success' : 'warning'; ?>">
                                                        <?php echo number_format($line['received_quantity']); ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        <?php if ($totalLines > 10): ?>
                                            <tr>
                                                <td colspan="3" class="text-center text-muted">
                                                    <small>... and <?php echo $totalLines - 10; ?> more lines</small>
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="text-center text-muted py-3">
                            <i class="fas fa-inbox fa-2x mb-2"></i>
                            <p>No line items yet</p>
                            <a href="asn_lines_secure.php?id=<?php echo $asnId; ?>" class="btn btn-sm btn-primary">
                                <i class="fas fa-plus me-1"></i>Add Line Items
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Status Change Modal -->
    <?php if ($canChangeStatus): ?>
        <div class="modal fade" id="statusModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Change ASN Status</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <form method="POST">
                        <div class="modal-body">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                            <input type="hidden" name="action" value="change_status">
                            
                            <div class="mb-3">
                                <label class="form-label">Current Status</label>
                                <p class="form-control-plaintext">
                                    <span class="badge bg-<?php 
                                        echo match($asn['status']) {
                                            'draft' => 'secondary',
                                            'confirmed' => 'primary',
                                            'in_transit' => 'warning',
                                            'arrived' => 'info',
                                            'receiving' => 'warning',
                                            'completed' => 'success',
                                            'cancelled' => 'danger',
                                            default => 'light text-dark'
                                        };
                                    ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $asn['status'])); ?>
                                    </span>
                                </p>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">New Status</label>
                                <select class="form-select" name="new_status" required>
                                    <option value="">Select new status</option>
                                    <option value="draft">Draft</option>
                                    <option value="confirmed">Confirmed</option>
                                    <option value="in_transit">In Transit</option>
                                    <option value="arrived">Arrived</option>
                                    <option value="receiving">Receiving</option>
                                    <option value="completed">Completed</option>
                                    <option value="cancelled">Cancelled</option>
                                </select>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary">Update Status</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Priority radio button handling
            document.querySelectorAll('input[name="priority"]').forEach(radio => {
                radio.addEventListener('change', function() {
                    document.querySelectorAll('.priority-badge').forEach(badge => {
                        badge.classList.remove('active');
                    });
                    this.closest('.priority-badge').classList.add('active');
                });
            });

            // Form validation
            const form = document.getElementById('asnForm');
            if (form) {
                form.addEventListener('submit', function(e) {
                    if (!form.checkValidity()) {
                        e.preventDefault();
                        e.stopPropagation();
                        
                        // Find first invalid field and focus it
                        const firstInvalid = form.querySelector(':invalid');
                        if (firstInvalid) {
                            firstInvalid.focus();
                            firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        }
                    }
                    
                    form.classList.add('was-validated');
                });
            }
        });
    </script>
</body>
</html>