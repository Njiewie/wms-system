<?php
/**
 * Secure Outbound Order Creation System
 * Enhanced with comprehensive security measures
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
$clients = [];
$order_data = null;

// Generate CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = $security->generateCSRFToken();
}

/**
 * Validate order input
 */
function validateOrderInput($data, &$errors) {
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
 * Check if order number exists
 */
function orderNumberExists($conn, $order_number) {
    $existing = secure_select_one($conn,
        "SELECT id FROM orders WHERE order_number = ? AND deleted_at IS NULL",
        "s",
        [$order_number]
    );
    return $existing !== null;
}

/**
 * Create new order
 */
function createOrder($conn, $data, $user_id) {
    // Add audit fields
    $data['created_by'] = $user_id;
    $data['created_at'] = date('Y-m-d H:i:s');
    $data['updated_at'] = date('Y-m-d H:i:s');
    $data['status'] = 'pending';

    return secure_insert($conn, 'orders', $data);
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Verify CSRF token
        if (!$security->validateCSRFToken($_POST['csrf_token'] ?? '')) {
            throw new Exception("Invalid security token. Please refresh the page and try again.");
        }

        // Rate limiting
        if (!$security->checkRateLimit($_SESSION['user_id'], 'order_create', 10, 300)) {
            throw new Exception("Too many order creation requests. Please wait before creating another order.");
        }

        // Sanitize input data
        $input_data = [
            'order_number' => $security->sanitizeInput($_POST['order_number'] ?? ''),
            'customer_name' => $security->sanitizeInput($_POST['customer_name'] ?? ''),
            'customer_email' => $security->sanitizeInput($_POST['customer_email'] ?? ''),
            'priority' => $security->sanitizeInput($_POST['priority'] ?? 'normal'),
            'total_amount' => !empty($_POST['total_amount']) ? (float)$_POST['total_amount'] : null,
            'shipping_address' => $security->sanitizeInput($_POST['shipping_address'] ?? '', 500),
            'notes' => $security->sanitizeInput($_POST['notes'] ?? '', 1000),
            'client_id' => !empty($_POST['client_id']) ? (int)$_POST['client_id'] : null
        ];

        // Validate input
        if (!validateOrderInput($input_data, $errors)) {
            throw new Exception("Please correct the validation errors below.");
        }

        // Check for existing order number
        if (orderNumberExists($conn, $input_data['order_number'])) {
            $errors['order_number'] = "Order number already exists";
            throw new Exception("Order number must be unique.");
        }

        // Create order
        $order_id = createOrder($conn, $input_data, $_SESSION['user_id']);

        if ($order_id) {
            // Log successful creation
            $security->logActivity($_SESSION['user_id'], 'ORDER_CREATED',
                "Order created: {$input_data['order_number']}, Customer: {$input_data['customer_name']}");

            $message = "✅ Order created successfully!<br>
                       <strong>Order Number:</strong> " . htmlspecialchars($input_data['order_number']) . "<br>
                       <strong>Customer:</strong> " . htmlspecialchars($input_data['customer_name']);

            // Redirect to order details
            header("Location: view_order_items.php?id=$order_id&message=" . urlencode("Order created successfully"));
            exit;
        } else {
            throw new Exception("Failed to create order");
        }

    } catch (Exception $e) {
        $message = "❌ Error: " . htmlspecialchars($e->getMessage());

        // Log security events
        if (strpos($e->getMessage(), 'security token') !== false ||
            strpos($e->getMessage(), 'Too many requests') !== false) {

            $security->logSecurityEvent($_SESSION['user_id'], 'order_create_security_violation', $e->getMessage());
        }

        error_log("Order Creation Error: " . $e->getMessage() . " | User: " . $_SESSION['user_id'] . " | IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
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
    <meta name="description" content="Create Outbound Order - Secure WMS">
    <title>Create Outbound Order | Secure WMS</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="modern-style.css">
    <style>
        .create-container {
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

        .error-message {
            color: #dc2626;
            font-size: 13px;
            margin-top: 5px;
        }

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

        .btn-secondary {
            background: #f3f4f6;
            color: #374151;
        }

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
            .create-container {
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
    <div class="create-container">
        <!-- Page Header -->
        <div class="page-header">
            <h1 style="margin: 0; font-size: 2.5rem;">📤 Create Outbound Order</h1>
            <p style="margin: 0.5rem 0 0; font-size: 1.25rem; opacity: 0.9;">
                Create new customer order for fulfillment
            </p>
        </div>

        <!-- Messages -->
        <?php if ($message): ?>
            <div class="alert <?= strpos($message, '✅') !== false ? 'alert-success' : 'alert-error' ?>">
                <?= $message ?>
            </div>
        <?php endif; ?>

        <!-- Order Form -->
        <div class="form-container">
            <h2 style="margin-top: 0; color: #374151;">Order Information</h2>

            <form method="POST" id="orderForm" novalidate>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

                <div class="form-grid">
                    <div class="form-group">
                        <label for="order_number" class="form-label">Order Number <span class="required">*</span></label>
                        <input type="text"
                               id="order_number"
                               name="order_number"
                               class="form-control"
                               value="<?= htmlspecialchars($_POST['order_number'] ?? 'ORD-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT)) ?>"
                               maxlength="50"
                               pattern="[A-Za-z0-9\-_]+"
                               required>
                        <div class="help-text">Unique order identifier</div>
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
                               value="<?= htmlspecialchars($_POST['customer_name'] ?? '') ?>"
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
                               value="<?= htmlspecialchars($_POST['customer_email'] ?? '') ?>"
                               maxlength="100">
                        <div class="help-text">For order notifications</div>
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
                                        <?= ($_POST['client_id'] ?? '') == $client['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($client['client_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="help-text">Associated client account</div>
                    </div>

                    <div class="form-group">
                        <label for="priority" class="form-label">Priority</label>
                        <select id="priority" name="priority" class="form-control">
                            <option value="low" <?= ($_POST['priority'] ?? 'normal') === 'low' ? 'selected' : '' ?>>Low</option>
                            <option value="normal" <?= ($_POST['priority'] ?? 'normal') === 'normal' ? 'selected' : '' ?>>Normal</option>
                            <option value="high" <?= ($_POST['priority'] ?? 'normal') === 'high' ? 'selected' : '' ?>>High</option>
                            <option value="urgent" <?= ($_POST['priority'] ?? 'normal') === 'urgent' ? 'selected' : '' ?>>Urgent</option>
                        </select>
                        <div class="help-text">Order processing priority</div>
                    </div>

                    <div class="form-group">
                        <label for="total_amount" class="form-label">Total Amount</label>
                        <input type="number"
                               id="total_amount"
                               name="total_amount"
                               class="form-control"
                               value="<?= htmlspecialchars($_POST['total_amount'] ?? '') ?>"
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
                                  maxlength="500"><?= htmlspecialchars($_POST['shipping_address'] ?? '') ?></textarea>
                        <div class="help-text">Complete shipping address (max 500 characters)</div>
                    </div>

                    <div class="form-group full-width">
                        <label for="notes" class="form-label">Notes</label>
                        <textarea id="notes"
                                  name="notes"
                                  class="form-control"
                                  rows="4"
                                  maxlength="1000"><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
                        <div class="help-text">Additional notes or special instructions (max 1000 characters)</div>
                    </div>
                </div>

                <div class="action-buttons">
                    <button type="submit" class="btn btn-primary" id="createBtn">
                        📤 Create Order
                    </button>
                    <a href="view_outbound.php" class="btn btn-secondary">
                        ⬅️ Back to Orders
                    </a>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Form validation and enhancement
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('orderForm');
            const createBtn = document.getElementById('createBtn');

            // Real-time validation
            const requiredInputs = form.querySelectorAll('input[required], select[required]');

            function validateForm() {
                let isValid = true;

                requiredInputs.forEach(input => {
                    if (!input.value.trim()) {
                        isValid = false;
                    }
                });

                createBtn.disabled = !isValid;
            }

            requiredInputs.forEach(input => {
                input.addEventListener('input', validateForm);
                input.addEventListener('change', validateForm);
            });

            // Initial validation
            validateForm();

            // Form submission
            form.addEventListener('submit', function(e) {
                createBtn.disabled = true;
                createBtn.textContent = '🔄 Creating Order...';

                // Re-enable after 5 seconds as fallback
                setTimeout(() => {
                    createBtn.disabled = false;
                    createBtn.textContent = '📤 Create Order';
                }, 5000);
            });

            // Auto-generate order number
            document.getElementById('order_number').addEventListener('focus', function() {
                if (!this.value) {
                    const date = new Date();
                    const dateStr = date.getFullYear().toString() +
                                   (date.getMonth() + 1).toString().padStart(2, '0') +
                                   date.getDate().toString().padStart(2, '0');
                    const randomNum = Math.floor(Math.random() * 9999).toString().padStart(4, '0');
                    this.value = `ORD-${dateStr}-${randomNum}`;
                }
            });

            // Priority visualization
            document.getElementById('priority').addEventListener('change', function() {
                const priority = this.value;
                const form = this.closest('form');

                // Remove existing priority classes
                form.classList.remove('priority-urgent', 'priority-high');

                // Add new priority class for visual feedback
                if (priority === 'urgent' || priority === 'high') {
                    form.classList.add('priority-' + priority);
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
unset($clients, $errors, $input_data);
?>
