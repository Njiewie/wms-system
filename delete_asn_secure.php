<?php
/**
 * Secure ASN Deletion System
 * Safe ASN deletion with confirmation, validation, and comprehensive audit trail
 */

require_once 'auth.php';
require_once 'security-utils.php';
require_once 'db_config.php';

// Require authentication and manager role for deletion
require_login();
require_manager();

$security = SecurityUtils::getInstance();
$db = getDB();

// Check rate limiting
if (!$security->checkRateLimit()) {
    http_response_code(429);
    $security->logActivity('RATE_LIMIT_EXCEEDED', ['page' => 'delete_asn'], 'WARNING');
    die('Rate limit exceeded. Please try again later.');
}

$asnId = (int) ($_GET['id'] ?? 0);

if (!$asnId) {
    header('Location: inbound_secure.php?error=' . urlencode('Invalid ASN ID'));
    exit();
}

// Get ASN details with related information
$asn = $db->fetchRow("
    SELECT a.*, s.name as supplier_name, s.code as supplier_code,
           creator.username as created_by_name,
           COUNT(al.id) as line_count,
           SUM(al.quantity) as total_quantity,
           SUM(al.received_quantity) as total_received,
           SUM(COALESCE(al.processed_quantity, 0)) as total_processed
    FROM asn a
    LEFT JOIN suppliers s ON a.supplier_id = s.id
    LEFT JOIN users creator ON a.created_by = creator.id
    LEFT JOIN asn_lines al ON a.id = al.asn_id AND al.deleted_at IS NULL
    WHERE a.id = :id AND a.deleted_at IS NULL
    GROUP BY a.id
", [':id' => $asnId]);

if (!$asn) {
    header('Location: inbound_secure.php?error=' . urlencode('ASN not found or already deleted'));
    exit();
}

$security->logActivity('ASN_DELETE_PAGE_ACCESS', [
    'asn_id' => $asnId,
    'asn_number' => $asn['asn_number'],
    'user_id' => get_current_user_id()
]);

$csrfToken = $security->generateCSRFToken();
$message = '';
$messageType = '';

// Check if ASN can be deleted safely
$canDelete = true;
$deleteWarnings = [];
$deleteErrors = [];

// Check ASN status
if (in_array($asn['status'], ['receiving', 'completed'])) {
    $deleteErrors[] = 'ASN cannot be deleted because it has been received or completed';
    $canDelete = false;
}

// Check if any inventory has been processed
if ($asn['total_processed'] > 0) {
    $deleteErrors[] = 'ASN cannot be deleted because some items have been processed into inventory';
    $canDelete = false;
}

// Check if any quantities have been received
if ($asn['total_received'] > 0) {
    $deleteWarnings[] = 'ASN has received quantities that will be lost';
}

// Check for related transactions
$relatedTransactions = $db->fetchValue("
    SELECT COUNT(*) 
    FROM inventory_transactions 
    WHERE reference_type = 'asn' AND reference_id = :asn_id
", [':asn_id' => $asnId]);

if ($relatedTransactions > 0) {
    $deleteErrors[] = 'ASN cannot be deleted because it has related inventory transactions';
    $canDelete = false;
}

// Handle deletion confirmation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_delete'])) {
    if (!$security->validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = 'Invalid security token. Please try again.';
        $messageType = 'danger';
        $security->logActivity('CSRF_TOKEN_VALIDATION_FAILED', ['page' => 'delete_asn', 'asn_id' => $asnId], 'WARNING');
    } elseif (!$canDelete) {
        $message = 'ASN cannot be deleted due to validation errors.';
        $messageType = 'danger';
    } else {
        try {
            $deletionReason = $security->sanitizeInput($_POST['deletion_reason'] ?? '');
            $forceDelete = isset($_POST['force_delete']) && $_POST['force_delete'] === '1';

            // Validation
            if (empty($deletionReason)) {
                $message = 'Deletion reason is required.';
                $messageType = 'danger';
            } else {
                $db->beginTransaction();

                try {
                    // Get all line items for audit
                    $asnLines = $db->fetchAll("
                        SELECT * FROM asn_lines 
                        WHERE asn_id = :asn_id AND deleted_at IS NULL
                    ", [':asn_id' => $asnId]);

                    // Soft delete all line items first
                    foreach ($asnLines as $line) {
                        $db->softDelete('asn_lines', $line['id']);
                        
                        $security->logActivity('ASN_LINE_DELETED_WITH_ASN', [
                            'asn_id' => $asnId,
                            'line_id' => $line['id'],
                            'sku' => $line['sku'],
                            'quantity' => $line['quantity'],
                            'received_quantity' => $line['received_quantity'],
                            'reason' => $deletionReason
                        ]);
                    }

                    // Create deletion audit record
                    $auditId = $db->insert('asn_deletion_audit', [
                        'asn_id' => $asnId,
                        'asn_number' => $asn['asn_number'],
                        'supplier_id' => $asn['supplier_id'],
                        'supplier_name' => $asn['supplier_name'],
                        'status_at_deletion' => $asn['status'],
                        'line_count' => $asn['line_count'],
                        'total_quantity' => $asn['total_quantity'],
                        'total_received' => $asn['total_received'],
                        'total_processed' => $asn['total_processed'],
                        'deletion_reason' => $deletionReason,
                        'force_deleted' => $forceDelete ? 1 : 0,
                        'asn_data' => json_encode($asn),
                        'line_items_data' => json_encode($asnLines),
                        'deleted_by' => get_current_user_id(),
                        'deleted_at' => date('Y-m-d H:i:s')
                    ]);

                    // Soft delete the ASN
                    $deleted = $db->softDelete('asn', $asnId);

                    if ($deleted && $auditId) {
                        $security->logActivity('ASN_DELETED', [
                            'asn_id' => $asnId,
                            'asn_number' => $asn['asn_number'],
                            'supplier_name' => $asn['supplier_name'],
                            'line_count' => $asn['line_count'],
                            'total_quantity' => $asn['total_quantity'],
                            'deletion_reason' => $deletionReason,
                            'audit_id' => $auditId,
                            'force_deleted' => $forceDelete
                        ], 'WARNING');

                        $db->commit();

                        // Redirect with success message
                        header('Location: inbound_secure.php?success=' . urlencode('ASN deleted successfully'));
                        exit();
                    } else {
                        throw new Exception('Failed to delete ASN record');
                    }

                } catch (Exception $e) {
                    $db->rollback();
                    throw $e;
                }
            }

        } catch (Exception $e) {
            $security->logActivity('ASN_DELETION_ERROR', [
                'asn_id' => $asnId,
                'error' => $e->getMessage(),
                'deletion_reason' => $deletionReason ?? ''
            ], 'ERROR');
            
            $message = 'Failed to delete ASN. Please try again.';
            $messageType = 'danger';
        }
    }
}

// Get ASN line items for display
$asnLines = $db->fetchAll("
    SELECT al.*, i.on_hand_quantity as current_stock
    FROM asn_lines al
    LEFT JOIN inventory i ON al.sku = i.sku AND i.deleted_at IS NULL
    WHERE al.asn_id = :asn_id AND al.deleted_at IS NULL
    ORDER BY al.line_number, al.created_at
", [':asn_id' => $asnId]);

// Get recent deletion history for reference
$recentDeletions = $db->fetchAll("
    SELECT ada.*, u.username as deleted_by_name
    FROM asn_deletion_audit ada
    LEFT JOIN users u ON ada.deleted_by = u.id
    WHERE ada.deleted_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ORDER BY ada.deleted_at DESC
    LIMIT 10
");

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delete ASN <?php echo htmlspecialchars($asn['asn_number']); ?> - WMS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        .header-section {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
            color: white;
            padding: 2rem 0;
            margin-bottom: 2rem;
        }
        .danger-card {
            background: white;
            border: 2px solid #dc3545;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(220,53,69,0.2);
            overflow: hidden;
            margin-bottom: 2rem;
        }
        .danger-header {
            background: #dc3545;
            color: white;
            padding: 1.5rem;
            text-align: center;
        }
        .danger-body {
            padding: 2rem;
        }
        .warning-card {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 10px;
            padding: 1rem;
            margin-bottom: 1rem;
        }
        .error-card {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            border-radius: 10px;
            padding: 1rem;
            margin-bottom: 1rem;
        }
        .info-section {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            padding: 1.5rem;
            margin-bottom: 2rem;
        }
        .asn-summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }
        .summary-item {
            text-align: center;
            padding: 1rem;
            background: #f8f9fa;
            border-radius: 8px;
            border: 1px solid #e9ecef;
        }
        .summary-value {
            font-size: 1.5rem;
            font-weight: bold;
            color: #495057;
        }
        .line-items-preview {
            max-height: 300px;
            overflow-y: auto;
            border: 1px solid #e9ecef;
            border-radius: 8px;
        }
        .deletion-form {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 2rem;
            border: 2px solid #dc3545;
        }
        .confirmation-checkbox {
            background: white;
            border: 2px solid #dc3545;
            border-radius: 8px;
            padding: 1rem;
            margin: 1rem 0;
        }
        .btn-delete-confirm {
            background: #dc3545;
            border-color: #dc3545;
            color: white;
            font-weight: bold;
            padding: 0.75rem 2rem;
        }
        .btn-delete-confirm:hover {
            background: #c82333;
            border-color: #bd2130;
            color: white;
        }
        .recent-deletions {
            max-height: 400px;
            overflow-y: auto;
        }
    </style>
</head>
<body class="bg-light">
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-danger">
        <div class="container-fluid">
            <a class="navbar-brand" href="secure-dashboard.php">
                <i class="fas fa-warehouse me-2"></i>WMS - Delete ASN
            </a>
            <div class="navbar-nav ms-auto">
                <span class="navbar-text me-3">
                    Welcome, <?php echo htmlspecialchars(get_user_full_name()); ?>
                </span>
                <a class="nav-link" href="edit_asn_secure.php?id=<?php echo $asnId; ?>">
                    <i class="fas fa-arrow-left me-1"></i>Back to ASN
                </a>
            </div>
        </div>
    </nav>

    <!-- Header Section -->
    <div class="header-section">
        <div class="container">
            <div class="text-center">
                <h2><i class="fas fa-trash-alt me-2"></i>Delete ASN</h2>
                <h3><?php echo htmlspecialchars($asn['asn_number']); ?></h3>
                <p class="mb-0">This action will permanently remove the ASN and all related data</p>
            </div>
        </div>
    </div>

    <div class="container py-4">
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
                <?php echo $message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Validation Errors -->
        <?php if (!empty($deleteErrors)): ?>
            <div class="error-card">
                <h5><i class="fas fa-exclamation-circle me-2"></i>Cannot Delete ASN</h5>
                <p>The following issues prevent this ASN from being deleted:</p>
                <ul class="mb-0">
                    <?php foreach ($deleteErrors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <!-- Warnings -->
        <?php if (!empty($deleteWarnings) && $canDelete): ?>
            <div class="warning-card">
                <h5><i class="fas fa-exclamation-triangle me-2"></i>Deletion Warnings</h5>
                <p>Please be aware of the following before proceeding:</p>
                <ul class="mb-0">
                    <?php foreach ($deleteWarnings as $warning): ?>
                        <li><?php echo htmlspecialchars($warning); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="row">
            <!-- ASN Information -->
            <div class="col-lg-8">
                <!-- ASN Summary -->
                <div class="info-section">
                    <h5><i class="fas fa-info-circle me-2"></i>ASN Information</h5>
                    
                    <div class="asn-summary">
                        <div class="summary-item">
                            <div class="summary-value"><?php echo htmlspecialchars($asn['status']); ?></div>
                            <div class="text-muted">Status</div>
                        </div>
                        <div class="summary-item">
                            <div class="summary-value"><?php echo number_format($asn['line_count']); ?></div>
                            <div class="text-muted">Line Items</div>
                        </div>
                        <div class="summary-item">
                            <div class="summary-value"><?php echo number_format($asn['total_quantity']); ?></div>
                            <div class="text-muted">Expected Units</div>
                        </div>
                        <div class="summary-item">
                            <div class="summary-value"><?php echo number_format($asn['total_received']); ?></div>
                            <div class="text-muted">Received Units</div>
                        </div>
                        <div class="summary-item">
                            <div class="summary-value"><?php echo number_format($asn['total_processed']); ?></div>
                            <div class="text-muted">Processed Units</div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <strong>Supplier:</strong> <?php echo htmlspecialchars($asn['supplier_name']); ?><br>
                            <strong>Reference:</strong> <?php echo htmlspecialchars($asn['reference_number'] ?: 'N/A'); ?><br>
                            <strong>Expected Date:</strong> <?php echo date('M j, Y', strtotime($asn['expected_date'])); ?>
                        </div>
                        <div class="col-md-6">
                            <strong>Created:</strong> <?php echo date('M j, Y g:i A', strtotime($asn['created_at'])); ?><br>
                            <strong>Created by:</strong> <?php echo htmlspecialchars($asn['created_by_name']); ?><br>
                            <strong>Priority:</strong> <?php echo ucfirst($asn['priority']); ?>
                        </div>
                    </div>
                </div>

                <!-- Line Items Preview -->
                <?php if (!empty($asnLines)): ?>
                <div class="info-section">
                    <h5><i class="fas fa-list me-2"></i>Line Items (<?php echo count($asnLines); ?>)</h5>
                    <div class="line-items-preview">
                        <div class="table-responsive">
                            <table class="table table-sm table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>SKU</th>
                                        <th>Description</th>
                                        <th>Expected</th>
                                        <th>Received</th>
                                        <th>Processed</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($asnLines as $line): ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($line['sku']); ?></strong></td>
                                            <td><?php echo htmlspecialchars($line['description'] ?: 'N/A'); ?></td>
                                            <td><?php echo number_format($line['quantity']); ?></td>
                                            <td class="<?php echo $line['received_quantity'] > 0 ? 'text-warning' : ''; ?>">
                                                <?php echo number_format($line['received_quantity']); ?>
                                            </td>
                                            <td class="<?php echo ($line['processed_quantity'] ?? 0) > 0 ? 'text-danger' : ''; ?>">
                                                <?php echo number_format($line['processed_quantity'] ?? 0); ?>
                                            </td>
                                            <td>
                                                <?php if (($line['processed_quantity'] ?? 0) > 0): ?>
                                                    <span class="badge bg-danger">Processed</span>
                                                <?php elseif ($line['received_quantity'] > 0): ?>
                                                    <span class="badge bg-warning">Received</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">Pending</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Deletion Form -->
                <?php if ($canDelete): ?>
                <div class="danger-card">
                    <div class="danger-header">
                        <h4><i class="fas fa-exclamation-triangle me-2"></i>Confirm Deletion</h4>
                        <p class="mb-0">This action cannot be undone</p>
                    </div>
                    <div class="danger-body">
                        <form method="POST" id="deleteForm">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                            
                            <div class="mb-4">
                                <label for="deletion_reason" class="form-label">
                                    <strong>Deletion Reason *</strong>
                                </label>
                                <textarea class="form-control" id="deletion_reason" name="deletion_reason" 
                                          rows="3" required maxlength="500" 
                                          placeholder="Please provide a detailed reason for deleting this ASN..."></textarea>
                                <div class="form-text">This reason will be logged for audit purposes.</div>
                            </div>

                            <div class="confirmation-checkbox">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="confirm_deletion" required>
                                    <label class="form-check-label fw-bold" for="confirm_deletion">
                                        I understand that deleting this ASN will permanently remove:
                                    </label>
                                </div>
                                <ul class="mt-2 mb-0">
                                    <li>The ASN record (<?php echo htmlspecialchars($asn['asn_number']); ?>)</li>
                                    <li>All <?php echo $asn['line_count']; ?> line items</li>
                                    <?php if ($asn['total_received'] > 0): ?>
                                        <li>Received quantity data (<?php echo number_format($asn['total_received']); ?> units)</li>
                                    <?php endif; ?>
                                    <li>All related data and history</li>
                                </ul>
                            </div>

                            <div class="confirmation-checkbox">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="acknowledge_responsibility" required>
                                    <label class="form-check-label fw-bold" for="acknowledge_responsibility">
                                        I acknowledge full responsibility for this deletion and confirm that I have proper authorization.
                                    </label>
                                </div>
                            </div>

                            <div class="d-flex justify-content-between align-items-center mt-4">
                                <a href="edit_asn_secure.php?id=<?php echo $asnId; ?>" class="btn btn-outline-secondary btn-lg">
                                    <i class="fas fa-times me-2"></i>Cancel
                                </a>
                                <button type="submit" name="confirm_delete" value="1" class="btn btn-delete-confirm btn-lg" 
                                        id="deleteButton" disabled>
                                    <i class="fas fa-trash-alt me-2"></i>Delete ASN Permanently
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                <?php else: ?>
                <div class="danger-card">
                    <div class="danger-header">
                        <h4><i class="fas fa-ban me-2"></i>Deletion Not Allowed</h4>
                    </div>
                    <div class="danger-body">
                        <p>This ASN cannot be deleted due to the validation errors shown above. Please resolve these issues first or contact an administrator.</p>
                        <div class="text-center">
                            <a href="edit_asn_secure.php?id=<?php echo $asnId; ?>" class="btn btn-outline-secondary btn-lg">
                                <i class="fas fa-arrow-left me-2"></i>Return to ASN
                            </a>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Side Panel -->
            <div class="col-lg-4">
                <!-- Deletion Guidelines -->
                <div class="info-section">
                    <h6><i class="fas fa-book me-2"></i>Deletion Guidelines</h6>
                    <div class="small">
                        <p><strong>ASNs can only be deleted if:</strong></p>
                        <ul>
                            <li>Status is Draft or Confirmed</li>
                            <li>No items have been processed</li>
                            <li>No inventory transactions exist</li>
                        </ul>
                        
                        <p><strong>What happens when you delete:</strong></p>
                        <ul>
                            <li>ASN is soft-deleted (marked as deleted)</li>
                            <li>All line items are soft-deleted</li>
                            <li>Audit trail is created</li>
                            <li>Action is logged for security</li>
                        </ul>
                        
                        <p><strong>Data retention:</strong></p>
                        <ul>
                            <li>Deletion audit kept for 7 years</li>
                            <li>Original data backed up</li>
                            <li>Restoration possible by admin</li>
                        </ul>
                    </div>
                </div>

                <!-- Recent Deletions -->
                <?php if (!empty($recentDeletions)): ?>
                <div class="info-section">
                    <h6><i class="fas fa-history me-2"></i>Recent Deletions (Last 30 Days)</h6>
                    <div class="recent-deletions">
                        <?php foreach ($recentDeletions as $deletion): ?>
                            <div class="border-bottom pb-2 mb-2">
                                <div class="small">
                                    <strong><?php echo htmlspecialchars($deletion['asn_number']); ?></strong>
                                    <br>
                                    <span class="text-muted">
                                        <?php echo htmlspecialchars($deletion['supplier_name']); ?>
                                        - <?php echo date('M j, Y', strtotime($deletion['deleted_at'])); ?>
                                    </span>
                                    <br>
                                    <span class="text-muted">
                                        By: <?php echo htmlspecialchars($deletion['deleted_by_name']); ?>
                                    </span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Contact Information -->
                <div class="info-section">
                    <h6><i class="fas fa-phone me-2"></i>Need Help?</h6>
                    <p class="small">
                        If you're unsure about deleting this ASN, please contact:
                    </p>
                    <ul class="small mb-0">
                        <li><strong>IT Support:</strong> ext. 1234</li>
                        <li><strong>Warehouse Manager:</strong> ext. 5678</li>
                        <li><strong>System Admin:</strong> admin@company.com</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const confirmDeletion = document.getElementById('confirm_deletion');
            const acknowledgeResponsibility = document.getElementById('acknowledge_responsibility');
            const deleteButton = document.getElementById('deleteButton');
            const deleteForm = document.getElementById('deleteForm');

            function updateDeleteButtonState() {
                if (confirmDeletion && acknowledgeResponsibility && deleteButton) {
                    deleteButton.disabled = !(confirmDeletion.checked && acknowledgeResponsibility.checked);
                }
            }

            if (confirmDeletion) {
                confirmDeletion.addEventListener('change', updateDeleteButtonState);
            }
            
            if (acknowledgeResponsibility) {
                acknowledgeResponsibility.addEventListener('change', updateDeleteButtonState);
            }

            // Form submission confirmation
            if (deleteForm) {
                deleteForm.addEventListener('submit', function(e) {
                    const reason = document.getElementById('deletion_reason').value.trim();
                    
                    if (reason.length < 10) {
                        e.preventDefault();
                        alert('Please provide a more detailed deletion reason (at least 10 characters).');
                        return false;
                    }

                    const asnNumber = '<?php echo addslashes($asn['asn_number']); ?>';
                    const confirmMessage = `Are you absolutely sure you want to delete ASN "${asnNumber}"?\n\nThis action cannot be undone and will permanently remove:\n- The ASN record\n- All line items\n- Related data and history\n\nType "DELETE" to confirm:`;
                    
                    const userConfirmation = prompt(confirmMessage);
                    
                    if (userConfirmation !== 'DELETE') {
                        e.preventDefault();
                        return false;
                    }

                    // Show loading state
                    deleteButton.disabled = true;
                    deleteButton.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Deleting...';
                    
                    return true;
                });
            }

            // Character counter for deletion reason
            const deletionReason = document.getElementById('deletion_reason');
            if (deletionReason) {
                const maxLength = deletionReason.maxLength;
                const counter = document.createElement('div');
                counter.className = 'form-text';
                counter.style.textAlign = 'right';
                deletionReason.parentNode.appendChild(counter);

                function updateCounter() {
                    const remaining = maxLength - deletionReason.value.length;
                    counter.textContent = `${deletionReason.value.length}/${maxLength} characters`;
                    
                    if (remaining < 50) {
                        counter.style.color = '#dc3545';
                    } else if (remaining < 100) {
                        counter.style.color = '#ffc107';
                    } else {
                        counter.style.color = '#6c757d';
                    }
                }

                deletionReason.addEventListener('input', updateCounter);
                updateCounter();
            }
        });
    </script>
</body>
</html>