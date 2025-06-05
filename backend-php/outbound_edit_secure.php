<?php
/**
 * Secure Outbound Order Edit System
 * Enhanced with comprehensive security measures
 *
 * Security Features:
 * - CSRF Protection
 * - SQL Injection Prevention
 * - Input Validation & Sanitization
 * - XSS Prevention
 * - Activity Logging
 * - Access Control
 * - Inventory Integration
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
$order_data = null;
$clients = [];

// Generate CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = $security->generateCSRFToken();
}

// Validate order ID parameter
if (!isset($_GET['id'])) {
    $security->logSecurityEvent($_SESSION['user_id'] ?? 'unknown', 'invalid_order_edit_access',
        'Attempted to edit order without order ID');
    header('Location: view_outbound.php?error=' . urlencode('Order ID is required'));
    exit;
}

try {
    // Sanitize and validate order ID
    $order_id = $security->validateInteger($_GET['id'], 1);

    // Rate limiting
    if (!$security->checkRateLimit($_SESSION['user_id'], 'order_edit', 10, 300)) {
        throw new Exception('Too many edit requests. Please wait before editing another order.');
    }

    // Check if user has access to this order
    $order_data = secure_select_one($conn,
        "SELECT o.*, c.client_name
         FROM orders o
         LEFT JOIN clients c ON o.client_id = c.id
         WHERE o.id = ? AND o.deleted_at IS NULL",
        "i",
        [$order_id]
    );

    if (!$order_data) {
        $security->logSecurityEvent($_SESSION['user_id'], 'unauthorized_order_edit_access',
            "Attempted to edit non-existent order: $order_id");
        throw new Exception('Order not found or access denied');
    }

    // Check if order can be edited
    if (in_array($order_data['status'], ['shipped', 'delivered', 'cancelled'])) {
        throw new Exception('Cannot edit orders with status: ' . $order_data['status']);
    }

} catch (Exception $e) {
    $message = "❌ Error: " . htmlspecialchars($e->getMessage());

    // Log security events
    if (strpos($e->getMessage(), 'Too many requests') !== false ||
        strpos($e->getMessage(), 'access denied') !== false) {

        $security->logSecurityEvent($_SESSION['user_id'], 'order_edit_violation', $e->getMessage());
    }

    error_log("Order Edit Access Error: " . $e->getMessage() . " | User: " . $_SESSION['user_id'] . " | IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
}

/**
 * Validate order edit input
 */
function validateOrderEditInput($data, &$errors) {
    if (empty($data['order_number'])) {
        $errors['order_number'] = "Order number is required";
    } elseif (!preg_match('/^[A-Za-z0-9\-_]{1,50}$/', $data['order_number'])) {
        $errors['order_number'] = "Order number must be alphanumeric (1-50 characters)";
    }

    if (empty($data['customer_name'])) {
        $errors['customer_name'] = "Customer name is required";
    } elseif (strlen($data['customer_name']) > 100) {
        $errors['customer_name'] = "Customer name is too long (max 100 characters)";
    }

    if (!empty($data['customer_email']) && !filter_var($data['customer_email'], FILTER_VALIDATE_EMAIL)) {
        $errors['customer_email'] = "Invalid email format";
    }

    if (!empty($data['client_id']) && !is_numeric($data['client_id'])) {
        $errors['client_id'] = "Invalid client ID";
    }

    $allowed_statuses = ['pending', 'processing', 'picking', 'packed', 'ready', 'hold'];
    if (empty($data['status'])) {
        $errors['status'] = "Status is required";
    } elseif (!in_array($data['status'], $allowed_statuses)) {
        $errors['status'] = "Invalid status value";
    }

    $allowed_priorities = ['low', 'normal', 'high', 'urgent'];
    if (!empty($data['priority']) && !in_array($data['priority'], $allowed_priorities)) {
        $errors['priority'] = "Invalid priority value";
    }

    if (!empty($data['total_amount']) && (!is_numeric($data['total_amount']) || (float)$data['total_amount'] < 0)) {
        $errors['total_amount'] = "Total amount must be a valid positive number";
    }

    return empty($errors);
}

/**
 * Check if order number exists (excluding current order)
 */
function orderNumberExists($conn, $order_number, $exclude_id = null) {
    $query = "SELECT id FROM orders WHERE order_number = ? AND deleted_at IS NULL";
    $params = [$order_number];
    $types = "s";

    if ($exclude_id) {
        $query .= " AND id != ?";
        $params[] = $exclude_id;
        $types .= "i";
    }

    $existing = secure_select_one($conn, $query, $types, $params);
    return $existing !== null;
}

/**
 * Update order
 */
function updateOrder($conn, $order_id, $data, $user_id) {
    // Add audit fields
    $data['updated_by'] = $user_id;
    $data['updated_at'] = date('Y-m-d H:i:s');

    return secure_update($conn, 'orders', $data, 'id = ?', 'i', [$order_id]);
}

/**
 * Log order edit activity
 */
function logOrderEditActivity($conn, $action, $order_id, $user_id, $details = '') {
    $security = SecurityUtils::getInstance($conn);
    $security->logActivity($user_id, $action,
        "Order ID: $order_id" . ($details ? ", $details" : ''));
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $order_data) {
    try {
        // Verify CSRF token
        if (!$security->validateCSRFToken($_POST['csrf_token'] ?? '')) {
            throw new Exception("Invalid security token. Please refresh the page and try again.");
        }

        // Rate limiting for updates
        if (!$security->checkRateLimit($_SESSION['user_id'], 'order_update', 5, 300)) {
            throw new Exception("Too many update requests. Please wait before updating again.");
        }

        // Sanitize input data
        $update_data = [
            'order_number' => $security->sanitizeInput($_POST['order_number'] ?? ''),
            'customer_name' => $security->sanitizeInput($_POST['customer_name'] ?? ''),
            'customer_email' => $security->sanitizeInput($_POST['customer_email'] ?? ''),
            'status' => $security->sanitizeInput($_POST['status'] ?? ''),
            'priority' => $security->sanitizeInput($_POST['priority'] ?? 'normal'),
            'total_amount' => !empty($_POST['total_amount']) ? (float)$_POST['total_amount'] : null,
            'shipping_address' => $security->sanitizeInput($_POST['shipping_address'] ?? '', 500),
            'notes' => $security->sanitizeInput($_POST['notes'] ?? '', 1000),
            'client_id' => !empty($_POST['client_id']) ? (int)$_POST['client_id'] : null
        ];

        // Validate input
        if (!validateOrderEditInput($update_data, $errors)) {
            throw new Exception("Please correct the validation errors below.");
        }

        // Check for existing order number (excluding current order)
        if (orderNumberExists($conn, $update_data['order_number'], $order_id)) {
            $errors['order_number'] = "Order number already exists";
            throw new Exception("Order number must be unique.");
        }

        // Track changes for logging
        $changes = [];
        foreach ($update_data as $key => $value) {
            if ($order_data[$key] != $value) {
                $old_value = $order_data[$key] ?? 'null';
                $changes[] = "$key: '$old_value' → '$value'";
            }
        }

        // Update order
        $updated = updateOrder($conn, $order_id, $update_data, $_SESSION['user_id']);

        if ($updated) {
            // Log successful update
            logOrderEditActivity($conn, 'ORDER_UPDATED', $order_id, $_SESSION['user_id'],
                "Order: {$update_data['order_number']}, Changes: " . implode(', ', $changes));

            $message = "✅ Order updated successfully!<br>
                       <strong>Order Number:</strong> " . htmlspecialchars($update_data['order_number']) . "<br>
                       <strong>Changes:</strong> " . (count($changes) > 0 ? count($changes) . " fields updated" : "No changes made");

            // Refresh order data
            $order_data = secure_select_one($conn,
                "SELECT o.*, c.client_name
                 FROM orders o
                 LEFT JOIN clients c ON o.client_id = c.id
                 WHERE o.id = ?",
                "i",
                [$order_id]
            );
        } else {
            throw new Exception("Failed to update order or no changes were made");
        }

    } catch (Exception $e) {
        $message = "❌ Error: " . htmlspecialchars($e->getMessage());

        // Log security events
        if (strpos($e->getMessage(), 'security token') !== false ||
            strpos($e->getMessage(), 'Too many requests') !== false) {

            $security->logSecurityEvent($_SESSION['user_id'], 'order_edit_security_violation', $e->getMessage());
        }

        error_log("Order Edit Error: " . $e->getMessage() . " | User: " . $_SESSION['user_id'] . " | IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    }
}

// Load clients for dropdown
try {
    $clients = secure_select_all($conn,
        "SELECT id, client_name FROM clients ORDER BY client_name"
    );
} catch (Exception $e) {
    error_log("Failed to load clients: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Edit Order - Secure WMS">
    <title>Edit Order | Secure WMS</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="modern-style.css">
    <style>
        .edit-container {
            max-width: 900px;
            margin: 0 auto;
            padding: 2rem;
        }

        .page-header {
            text-align: center;
            margin-bottom: 3rem;
            padding: 2rem;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 12px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
        }

        .order-info {
            background: rgba(255, 255, 255, 0.1);
            padding: 1rem;
            border-radius: 8px;
            margin-top: 1rem;
            backdrop-filter: blur(10px);
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }

        .info-item {
            font-size: 0.875rem;
        }

        .info-label {
            opacity: 0.8;
            margin-bottom: 0.25rem;
        }

        .info-value {
            font-weight: 600;
        }

        .form-container {
            background: white;
            padding: 2rem;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            margin-bottom: 2rem;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-group.full-width {
            grid-column: 1 / -1;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: #374151;
        }

        .form-control {
            width: 100%;
            padding: 12px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.3s;
        }

        .form-control:focus {
            outline: none;
            border-color: #4f46e5;
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }

        .form-control:disabled {
            background: #f9fafb;
            color: #6b7280;
        }

        .error-message {
            color: #dc2626;
            font-size: 13px;
            margin-top: 5px;
        }

        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 500;
            text-transform: uppercase;
        }

        .status-pending { background: #fef3c7; color: #92400e; }
        .status-processing { background: #dbeafe; color: #1e40af; }
        .status-picking { background: #e0e7ff; color: #3730a3; }
        .status-packed { background: #f3e8ff; color: #6b21a8; }
        .status-ready { background: #d1fae5; color: #065f46; }
        .status-shipped { background: #dcfce7; color: #166534; }
        .status-delivered { background: #f0fdf4; color: #14532d; }
        .status-hold { background: #fee2e2; color: #991b1b; }
        .status-cancelled { background: #f3f4f6; color: #6b7280; }

        .priority-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 500;
        }

        .priority-low { background: #f3f4f6; color: #6b7280; }
        .priority-normal { background: #dbeafe; color: #1e40af; }
        .priority-high { background: #fef3c7; color: #92400e; }
        .priority-urgent { background: #fee2e2; color: #991b1b; }

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-primary {
            background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 8px 25px rgba(79, 70, 229, 0.4);
        }

        .btn-primary:disabled {
            background: #9ca3af;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        .btn-secondary {
            background: #f3f4f6;
            color: #374151;
        }

        .btn-secondary:hover {
            background: #e5e7eb;
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

        .required {
            color: #dc2626;
        }

        .help-text {
            font-size: 0.875rem;
            color: #6b7280;
            margin-top: 0.25rem;
        }

        .action-buttons {
            display: flex;
            gap: 1rem;
            justify-content: center;
            align-items: center;
            margin-top: 2rem;
            flex-wrap: wrap;
        }

        @media (max-width: 768px) {
            .edit-container {
                padding: 1rem;
            }

            .page-header {
                padding: 1rem;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .action-buttons {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="edit-container">
        <!-- Page Header -->
        <div class="page-header">
            <h1 style="margin: 0; font-size: 2.5rem;">✏️ Edit Order</h1>
            <p style="margin: 0.5rem 0 0; font-size: 1.25rem; opacity: 0.9;">
                Update Order Information
            </p>

            <?php if ($order_data): ?>
            <div class="order-info">
                <div class="info-grid">
                    <div class="info-item">
                        <div class="info-label">Order Number</div>
                        <div class="info-value"><?= htmlspecialchars($order_data['order_number']) ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Current Status</div>
                        <div class="info-value">
                            <span class="status-badge status-<?= strtolower($order_data['status']) ?>">
                                <?= htmlspecialchars($order_data['status']) ?>
                            </span>
                        </div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Priority</div>
                        <div class="info-value">
                            <span class="priority-badge priority-<?= strtolower($order_data['priority'] ?? 'normal') ?>">
                                <?= htmlspecialchars($order_data['priority'] ?? 'Normal') ?>
                            </span>
                        </div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Created</div>
                        <div class="info-value">
                            <?= $order_data['created_at'] ? date('M d, Y H:i', strtotime($order_data['created_at'])) : 'N/A' ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Messages -->
        <?php if ($message): ?>
            <div class="alert <?= strpos($message, '✅') !== false ? 'alert-success' : 'alert-error' ?>">
                <?= $message ?>
            </div>
        <?php endif; ?>

        <?php if ($order_data): ?>
        <!-- Edit Form -->
        <div class="form-container">
            <h2 style="margin-top: 0; color: #374151;">Order Details</h2>

            <form method="POST" id="editForm" novalidate>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

                <div class="form-grid">
                    <div class="form-group">
                        <label for="order_number" class="form-label">Order Number <span class="required">*</span></label>
                        <input type="text"
                               id="order_number"
                               name="order_number"
                               class="form-control"
                               value="<?= htmlspecialchars($order_data['order_number'] ?? '') ?>"
                               maxlength="50"
                               pattern="[A-Za-z0-9\-_]+"
                               required>
                        <div class="help-text">Alphanumeric characters, hyphens, and underscores only</div>
                        <?php if (isset($errors['order_number'])): ?>
                            <div class="error-message"><?= htmlspecialchars($errors['order_number']) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label for="customer_name" class="form-label">Customer Name <span class="required">*</span></label>
                        <input type="text"
                               id="customer_name"
                               name="customer_name"
                               class="form-control"
                               value="<?= htmlspecialchars($order_data['customer_name'] ?? '') ?>"
                               maxlength="100"
                               required>
                        <div class="help-text">Customer or company name</div>
                        <?php if (isset($errors['customer_name'])): ?>
                            <div class="error-message"><?= htmlspecialchars($errors['customer_name']) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label for="customer_email" class="form-label">Customer Email</label>
                        <input type="email"
                               id="customer_email"
                               name="customer_email"
                               class="form-control"
                               value="<?= htmlspecialchars($order_data['customer_email'] ?? '') ?>"
                               maxlength="100">
                        <div class="help-text">Optional customer email address</div>
                        <?php if (isset($errors['customer_email'])): ?>
                            <div class="error-message"><?= htmlspecialchars($errors['customer_email']) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label for="client_id" class="form-label">Client</label>
                        <select id="client_id" name="client_id" class="form-control">
                            <option value="">Select client (optional)...</option>
                            <?php foreach ($clients as $client): ?>
                                <option value="<?= $client['id'] ?>"
                                        <?= ($order_data['client_id'] ?? '') == $client['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($client['client_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="help-text">Associated client account</div>
                        <?php if (isset($errors['client_id'])): ?>
                            <div class="error-message"><?= htmlspecialchars($errors['client_id']) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label for="status" class="form-label">Status <span class="required">*</span></label>
                        <select id="status" name="status" class="form-control" required>
                            <option value="">Select status...</option>
                            <option value="pending" <?= ($order_data['status'] ?? '') === 'pending' ? 'selected' : '' ?>>Pending</option>
                            <option value="processing" <?= ($order_data['status'] ?? '') === 'processing' ? 'selected' : '' ?>>Processing</option>
                            <option value="picking" <?= ($order_data['status'] ?? '') === 'picking' ? 'selected' : '' ?>>Picking</option>
                            <option value="packed" <?= ($order_data['status'] ?? '') === 'packed' ? 'selected' : '' ?>>Packed</option>
                            <option value="ready" <?= ($order_data['status'] ?? '') === 'ready' ? 'selected' : '' ?>>Ready</option>
                            <option value="hold" <?= ($order_data['status'] ?? '') === 'hold' ? 'selected' : '' ?>>Hold</option>
                        </select>
                        <div class="help-text">Current order status</div>
                        <?php if (isset($errors['status'])): ?>
                            <div class="error-message"><?= htmlspecialchars($errors['status']) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label for="priority" class="form-label">Priority</label>
                        <select id="priority" name="priority" class="form-control">
                            <option value="low" <?= ($order_data['priority'] ?? 'normal') === 'low' ? 'selected' : '' ?>>Low</option>
                            <option value="normal" <?= ($order_data['priority'] ?? 'normal') === 'normal' ? 'selected' : '' ?>>Normal</option>
                            <option value="high" <?= ($order_data['priority'] ?? 'normal') === 'high' ? 'selected' : '' ?>>High</option>
                            <option value="urgent" <?= ($order_data['priority'] ?? 'normal') === 'urgent' ? 'selected' : '' ?>>Urgent</option>
                        </select>
                        <div class="help-text">Order processing priority</div>
                        <?php if (isset($errors['priority'])): ?>
                            <div class="error-message"><?= htmlspecialchars($errors['priority']) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label for="total_amount" class="form-label">Total Amount</label>
                        <input type="number"
                               id="total_amount"
                               name="total_amount"
                               class="form-control"
                               value="<?= htmlspecialchars($order_data['total_amount'] ?? '') ?>"
                               min="0"
                               step="0.01">
                        <div class="help-text">Order total amount (optional)</div>
                        <?php if (isset($errors['total_amount'])): ?>
                            <div class="error-message"><?= htmlspecialchars($errors['total_amount']) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="form-group full-width">
                        <label for="shipping_address" class="form-label">Shipping Address</label>
                        <textarea id="shipping_address"
                                  name="shipping_address"
                                  class="form-control"
                                  rows="3"
                                  maxlength="500"><?= htmlspecialchars($order_data['shipping_address'] ?? '') ?></textarea>
                        <div class="help-text">Complete shipping address (max 500 characters)</div>
                        <?php if (isset($errors['shipping_address'])): ?>
                            <div class="error-message"><?= htmlspecialchars($errors['shipping_address']) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="form-group full-width">
                        <label for="notes" class="form-label">Notes</label>
                        <textarea id="notes"
                                  name="notes"
                                  class="form-control"
                                  rows="4"
                                  maxlength="1000"><?= htmlspecialchars($order_data['notes'] ?? '') ?></textarea>
                        <div class="help-text">Additional notes or special instructions (max 1000 characters)</div>
                        <?php if (isset($errors['notes'])): ?>
                            <div class="error-message"><?= htmlspecialchars($errors['notes']) ?></div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="action-buttons">
                    <button type="submit" class="btn btn-primary" id="updateBtn">
                        💾 Update Order
                    </button>
                    <a href="view_order_items.php?id=<?= $order_id ?>" class="btn btn-secondary">
                        📋 View Items
                    </a>
                    <a href="view_outbound.php" class="btn btn-secondary">
                        ⬅️ Back to Orders
                    </a>
                </div>
            </form>
        </div>

        <?php else: ?>

        <!-- Error State -->
        <div class="form-container" style="text-align: center;">
            <h2>Order Not Found</h2>
            <p style="color: #6b7280; margin-bottom: 2rem;">
                The requested order could not be found or you don't have permission to edit it.
            </p>
            <a href="view_outbound.php" class="btn btn-primary">
                ⬅️ Back to Orders
            </a>
        </div>

        <?php endif; ?>
    </div>

    <script>
        // Form validation and enhancement
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('editForm');
            const updateBtn = document.getElementById('updateBtn');

            if (form) {
                // Real-time validation
                const requiredInputs = form.querySelectorAll('input[required], select[required]');

                function validateForm() {
                    let isValid = true;

                    requiredInputs.forEach(input => {
                        if (!input.value.trim()) {
                            isValid = false;
                        }
                    });

                    if (updateBtn) {
                        updateBtn.disabled = !isValid;
                    }
                }

                requiredInputs.forEach(input => {
                    input.addEventListener('input', validateForm);
                    input.addEventListener('change', validateForm);
                });

                // Initial validation
                validateForm();

                // Form submission handling
                form.addEventListener('submit', function(e) {
                    if (updateBtn) {
                        updateBtn.disabled = true;
                        updateBtn.textContent = '🔄 Updating Order...';

                        // Re-enable button after 5 seconds as fallback
                        setTimeout(() => {
                            updateBtn.disabled = false;
                            updateBtn.textContent = '💾 Update Order';
                        }, 5000);
                    }
                });

                // Track changes
                let originalValues = {};
                const inputs = form.querySelectorAll('input, select, textarea');

                inputs.forEach(input => {
                    originalValues[input.name] = input.value;
                });

                function hasChanges() {
                    return Array.from(inputs).some(input =>
                        originalValues[input.name] !== input.value
                    );
                }

                // Warn about unsaved changes
                window.addEventListener('beforeunload', function(e) {
                    if (hasChanges()) {
                        e.preventDefault();
                        e.returnValue = '';
                    }
                });

                // Remove warning when form is submitted
                form.addEventListener('submit', function() {
                    window.removeEventListener('beforeunload', arguments.callee);
                });
            }
        });

        // Status change notifications
        document.getElementById('status')?.addEventListener('change', function() {
            const status = this.value;
            const warningStatuses = ['hold', 'cancelled'];

            if (warningStatuses.includes(status)) {
                const message = status === 'hold' ?
                    'Setting order to HOLD will prevent further processing. Continue?' :
                    'Setting order to CANCELLED will stop all processing. Continue?';

                if (!confirm(message)) {
                    // Reset to original value
                    this.value = '<?= htmlspecialchars($order_data['status'] ?? '') ?>';
                }
            }
        });

        // Priority change visual feedback
        document.getElementById('priority')?.addEventListener('change', function() {
            const priority = this.value;
            const form = this.closest('form');

            // Remove existing priority classes
            form.classList.remove('priority-urgent', 'priority-high');

            // Add new priority class for visual feedback
            if (priority === 'urgent' || priority === 'high') {
                form.classList.add('priority-' + priority);
            }
        });

        // Auto-format currency input
        document.getElementById('total_amount')?.addEventListener('input', function() {
            const value = parseFloat(this.value);
            if (!isNaN(value)) {
                this.value = value.toFixed(2);
            }
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
unset($order_data, $clients, $errors);
?>
