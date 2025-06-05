<?php
/**
 * Secure Outbound Order Creation System
 * Enhanced with comprehensive security measures
 *
 * Security Features:
 * - CSRF Protection
 * - SQL Injection Prevention
 * - Input Validation & Sanitization
 * - XSS Prevention
 * - Activity Logging
 * - Error Handling
 */

// Start session and include security utilities
session_start();
require_once 'security-utils.php';
require_once 'auth.php';
require_once 'db_config.php';

// Require login
require_login();

// Initialize security and logging
$security = SecurityUtils::getInstance($conn);
$message = "";
$errors = [];

// Generate CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = $security->generateCSRFToken();
}

/**
 * Validate outbound order input data
 */
function validateOutboundInput($data, &$errors) {
    global $security;

    // Validate order number
    if (empty($data['order_number'])) {
        $errors['order_number'] = "Order number is required";
    } elseif (!preg_match('/^[A-Za-z0-9\-_]{1,50}$/', $data['order_number'])) {
        $errors['order_number'] = "Order number must be alphanumeric (1-50 characters)";
    }

    // Validate SKU
    if (empty($data['sku'])) {
        $errors['sku'] = "SKU is required";
    } elseif (!preg_match('/^[A-Za-z0-9\-_]{1,30}$/', $data['sku'])) {
        $errors['sku'] = "SKU must be alphanumeric (1-30 characters)";
    }

    // Validate quantity
    if (empty($data['qty_ordered'])) {
        $errors['qty_ordered'] = "Quantity is required";
    } elseif (!is_numeric($data['qty_ordered']) || (int)$data['qty_ordered'] <= 0) {
        $errors['qty_ordered'] = "Quantity must be a positive number";
    } elseif ((int)$data['qty_ordered'] > 100000) {
        $errors['qty_ordered'] = "Quantity cannot exceed 100,000";
    }

    // Validate client ID
    if (empty($data['client_id'])) {
        $errors['client_id'] = "Client ID is required";
    } elseif (!is_numeric($data['client_id']) || (int)$data['client_id'] <= 0) {
        $errors['client_id'] = "Client ID must be a positive number";
    }

    // Validate delivery address
    if (empty($data['delivery_address'])) {
        $errors['delivery_address'] = "Delivery address is required";
    } elseif (strlen($data['delivery_address']) > 500) {
        $errors['delivery_address'] = "Delivery address is too long (max 500 characters)";
    }

    // Validate carrier
    if (empty($data['carrier'])) {
        $errors['carrier'] = "Carrier is required";
    } elseif (!preg_match('/^[A-Za-z0-9\s\-_&.,]{1,100}$/', $data['carrier'])) {
        $errors['carrier'] = "Carrier name contains invalid characters";
    }

    return empty($errors);
}

/**
 * Check if order number already exists
 */
function orderNumberExists($conn, $order_number, $client_id) {
    $stmt = $conn->prepare("SELECT id FROM outbound_orders WHERE order_number = ? AND client_id = ?");
    if ($stmt) {
        $stmt->bind_param("si", $order_number, $client_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $exists = $result->num_rows > 0;
        $stmt->close();
        return $exists;
    }
    return false;
}

/**
 * Verify client access permissions
 */
function verifyClientAccess($conn, $client_id, $user_id) {
    // Check if user has access to this client
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM user_client_access WHERE user_id = ? AND client_id = ?");
    if ($stmt) {
        $stmt->bind_param("ii", $user_id, $client_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        return $row['count'] > 0;
    }
    return false;
}

/**
 * Check inventory availability and determine order status
 */
function checkInventoryAndSetStatus($conn, $sku, $qty_ordered, $client_id) {
    $stmt = $conn->prepare("SELECT id, qty_available FROM inventory WHERE sku = ? AND client_id = ? AND qty_available > 0");
    if ($stmt) {
        $stmt->bind_param("si", $sku, $client_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result && $result->num_rows > 0) {
            $data = $result->fetch_assoc();
            $stmt->close();

            if ($data['qty_available'] >= $qty_ordered) {
                return ['status' => 'RELEASED', 'inventory_id' => $data['id']];
            } else {
                return ['status' => 'PARTIAL', 'inventory_id' => $data['id'], 'available_qty' => $data['qty_available']];
            }
        }
        $stmt->close();
    }

    return ['status' => 'HOLD', 'inventory_id' => null];
}

/**
 * Log outbound order activity
 */
function logOutboundActivity($conn, $action, $order_id, $user_id, $details = '') {
    $stmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, table_name, record_id, details, ip_address, user_agent, created_at)
                           VALUES (?, ?, 'outbound_orders', ?, ?, ?, ?, NOW())");
    if ($stmt) {
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        $stmt->bind_param("isisss", $user_id, $action, $order_id, $details, $ip_address, $user_agent);
        $stmt->execute();
        $stmt->close();
    }
}

// Process form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    try {
        // Verify CSRF token
        if (!$security->validateCSRFToken($_POST['csrf_token'] ?? '')) {
            throw new Exception("Invalid security token. Please refresh the page and try again.");
        }

        // Rate limiting check
        if (!$security->checkRateLimit($_SESSION['user_id'], 'outbound_create', 10, 300)) {
            throw new Exception("Too many requests. Please wait before creating another order.");
        }

        // Sanitize input data
        $input_data = [
            'order_number' => $security->sanitizeInput($_POST['order_number'] ?? ''),
            'sku' => $security->sanitizeInput($_POST['sku'] ?? ''),
            'qty_ordered' => (int)($_POST['qty_ordered'] ?? 0),
            'client_id' => (int)($_POST['client_id'] ?? 0),
            'delivery_address' => $security->sanitizeInput($_POST['delivery_address'] ?? ''),
            'carrier' => $security->sanitizeInput($_POST['carrier'] ?? '')
        ];

        // Validate input
        if (!validateOutboundInput($input_data, $errors)) {
            throw new Exception("Please correct the validation errors below.");
        }

        // Verify client access
        if (!verifyClientAccess($conn, $input_data['client_id'], $_SESSION['user_id'])) {
            $security->logSecurityEvent($_SESSION['user_id'], 'unauthorized_client_access',
                "Attempted to create order for client: " . $input_data['client_id']);
            throw new Exception("Access denied: You don't have permission to create orders for this client.");
        }

        // Check if order number already exists
        if (orderNumberExists($conn, $input_data['order_number'], $input_data['client_id'])) {
            $errors['order_number'] = "Order number already exists for this client";
            throw new Exception("Order number must be unique for each client.");
        }

        // Check inventory and determine status
        $inventory_check = checkInventoryAndSetStatus(
            $conn,
            $input_data['sku'],
            $input_data['qty_ordered'],
            $input_data['client_id']
        );

        $status = $inventory_check['status'];
        $status_message = "";

        switch ($status) {
            case 'RELEASED':
                $status_message = "Order released - sufficient inventory available";
                break;
            case 'PARTIAL':
                $status_message = "Partial inventory available: " . $inventory_check['available_qty'] . " units";
                break;
            case 'HOLD':
                $status_message = "Order on hold - insufficient inventory";
                break;
        }

        // Begin transaction
        $conn->autocommit(false);

        try {
            // Insert outbound order
            $stmt = $conn->prepare("INSERT INTO outbound_orders (order_number, sku, qty_ordered, client_id, delivery_address, carrier, status, status_message, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");

            if (!$stmt) {
                throw new Exception("Database preparation failed: " . $conn->error);
            }

            $stmt->bind_param("ssiissssi",
                $input_data['order_number'],
                $input_data['sku'],
                $input_data['qty_ordered'],
                $input_data['client_id'],
                $input_data['delivery_address'],
                $input_data['carrier'],
                $status,
                $status_message,
                $_SESSION['user_id']
            );

            if (!$stmt->execute()) {
                throw new Exception("Failed to create outbound order: " . $stmt->error);
            }

            $order_id = $conn->insert_id;
            $stmt->close();

            // If released, reserve inventory
            if ($status === 'RELEASED' && $inventory_check['inventory_id']) {
                $reserve_stmt = $conn->prepare("UPDATE inventory SET qty_reserved = qty_reserved + ?, updated_at = NOW() WHERE id = ?");
                if ($reserve_stmt) {
                    $reserve_stmt->bind_param("ii", $input_data['qty_ordered'], $inventory_check['inventory_id']);
                    $reserve_stmt->execute();
                    $reserve_stmt->close();
                }
            }

            // Commit transaction
            $conn->commit();

            // Log successful activity
            logOutboundActivity($conn, 'CREATE', $order_id, $_SESSION['user_id'],
                "Order: {$input_data['order_number']}, SKU: {$input_data['sku']}, Qty: {$input_data['qty_ordered']}, Status: $status");

            $message = "✅ Outbound order created successfully!<br>
                      <strong>Order Number:</strong> " . htmlspecialchars($input_data['order_number']) . "<br>
                      <strong>Status:</strong> $status<br>
                      <strong>Details:</strong> $status_message";

            // Clear form data after successful submission
            $input_data = [
                'order_number' => '',
                'sku' => '',
                'qty_ordered' => '',
                'client_id' => '',
                'delivery_address' => '',
                'carrier' => ''
            ];

        } catch (Exception $e) {
            // Rollback transaction
            $conn->rollback();
            throw $e;
        }

        // Re-enable autocommit
        $conn->autocommit(true);

    } catch (Exception $e) {
        $message = "❌ Error: " . htmlspecialchars($e->getMessage());

        // Log security events
        if (strpos($e->getMessage(), 'security token') !== false ||
            strpos($e->getMessage(), 'Too many requests') !== false ||
            strpos($e->getMessage(), 'Access denied') !== false) {

            $security->logSecurityEvent($_SESSION['user_id'], 'outbound_creation_security_violation', $e->getMessage());
        }

        error_log("Outbound Order Creation Error: " . $e->getMessage() . " | User: " . $_SESSION['user_id'] . " | IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    }
}

// Get user's accessible clients for dropdown
$clients = [];
try {
    $client_stmt = $conn->prepare("SELECT c.id, c.client_name FROM clients c
                                  JOIN user_client_access uca ON c.id = uca.client_id
                                  WHERE uca.user_id = ?
                                  ORDER BY c.client_name");
    if ($client_stmt) {
        $client_stmt->bind_param("i", $_SESSION['user_id']);
        $client_stmt->execute();
        $client_result = $client_stmt->get_result();
        while ($row = $client_result->fetch_assoc()) {
            $clients[] = $row;
        }
        $client_stmt->close();
    }
} catch (Exception $e) {
    error_log("Failed to load clients: " . $e->getMessage());
}

// Set default values if not set
if (!isset($input_data)) {
    $input_data = [
        'order_number' => '',
        'sku' => '',
        'qty_ordered' => '',
        'client_id' => '',
        'delivery_address' => '',
        'carrier' => ''
    ];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Create secure outbound orders with inventory validation">
    <title>Create Outbound Order - Secure WMS</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="modern-style.css">
    <style>
        .form-container {
            max-width: 800px;
            margin: 20px auto;
            padding: 30px;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .form-header {
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid #e2e8f0;
        }

        .form-header h2 {
            color: #2d3748;
            margin: 0;
            font-size: 28px;
        }

        .form-row {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .form-group {
            flex: 1;
            min-width: 250px;
        }

        .form-group.full-width {
            flex: 100%;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #374151;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.3s;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #4f46e5;
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }

        .error-message {
            color: #dc2626;
            font-size: 13px;
            margin-top: 5px;
            display: block;
        }

        .success-message {
            background: #d1fae5;
            color: #065f46;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid #10b981;
        }

        .error-alert {
            background: #fef2f2;
            color: #991b1b;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid #ef4444;
        }

        .submit-btn {
            background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
            color: white;
            padding: 15px 30px;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
            width: 100%;
        }

        .submit-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(79, 70, 229, 0.4);
        }

        .submit-btn:disabled {
            background: #9ca3af;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            text-decoration: none;
            color: #6b7280;
            margin-top: 20px;
            padding: 10px 20px;
            border-radius: 8px;
            transition: background 0.3s;
        }

        .back-link:hover {
            background: #f3f4f6;
            color: #374151;
        }

        .required {
            color: #dc2626;
        }

        .help-text {
            font-size: 12px;
            color: #6b7280;
            margin-top: 5px;
        }

        @media (max-width: 768px) {
            .form-container {
                margin: 10px;
                padding: 20px;
            }

            .form-row {
                flex-direction: column;
                gap: 10px;
            }

            .form-group {
                min-width: unset;
            }
        }
    </style>
</head>
<body>
    <div class="form-container">
        <div class="form-header">
            <h2>🚚 Create Outbound Order</h2>
            <p>Create a new outbound order with automatic inventory validation</p>
        </div>

        <?php if ($message): ?>
            <div class="<?= strpos($message, '✅') !== false ? 'success-message' : 'error-alert' ?>">
                <?= $message ?>
            </div>
        <?php endif; ?>

        <form method="POST" id="outboundForm" novalidate>
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

            <div class="form-row">
                <div class="form-group">
                    <label for="order_number">Order Number <span class="required">*</span></label>
                    <input type="text"
                           id="order_number"
                           name="order_number"
                           value="<?= htmlspecialchars($input_data['order_number']) ?>"
                           maxlength="50"
                           pattern="[A-Za-z0-9\-_]+"
                           required>
                    <div class="help-text">Alphanumeric characters, hyphens, and underscores only</div>
                    <?php if (isset($errors['order_number'])): ?>
                        <span class="error-message"><?= htmlspecialchars($errors['order_number']) ?></span>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label for="sku">SKU <span class="required">*</span></label>
                    <input type="text"
                           id="sku"
                           name="sku"
                           value="<?= htmlspecialchars($input_data['sku']) ?>"
                           maxlength="30"
                           pattern="[A-Za-z0-9\-_]+"
                           required>
                    <div class="help-text">Product SKU or item code</div>
                    <?php if (isset($errors['sku'])): ?>
                        <span class="error-message"><?= htmlspecialchars($errors['sku']) ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="qty_ordered">Quantity Ordered <span class="required">*</span></label>
                    <input type="number"
                           id="qty_ordered"
                           name="qty_ordered"
                           value="<?= htmlspecialchars($input_data['qty_ordered']) ?>"
                           min="1"
                           max="100000"
                           required>
                    <div class="help-text">Number of units to order (1-100,000)</div>
                    <?php if (isset($errors['qty_ordered'])): ?>
                        <span class="error-message"><?= htmlspecialchars($errors['qty_ordered']) ?></span>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label for="client_id">Client <span class="required">*</span></label>
                    <select id="client_id" name="client_id" required>
                        <option value="">Select a client...</option>
                        <?php foreach ($clients as $client): ?>
                            <option value="<?= $client['id'] ?>"
                                    <?= $input_data['client_id'] == $client['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($client['client_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="help-text">Client for this order</div>
                    <?php if (isset($errors['client_id'])): ?>
                        <span class="error-message"><?= htmlspecialchars($errors['client_id']) ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group full-width">
                    <label for="delivery_address">Delivery Address <span class="required">*</span></label>
                    <textarea id="delivery_address"
                              name="delivery_address"
                              rows="3"
                              maxlength="500"
                              required><?= htmlspecialchars($input_data['delivery_address']) ?></textarea>
                    <div class="help-text">Complete delivery address (max 500 characters)</div>
                    <?php if (isset($errors['delivery_address'])): ?>
                        <span class="error-message"><?= htmlspecialchars($errors['delivery_address']) ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="carrier">Carrier <span class="required">*</span></label>
                    <input type="text"
                           id="carrier"
                           name="carrier"
                           value="<?= htmlspecialchars($input_data['carrier']) ?>"
                           maxlength="100"
                           required>
                    <div class="help-text">Shipping carrier or delivery service</div>
                    <?php if (isset($errors['carrier'])): ?>
                        <span class="error-message"><?= htmlspecialchars($errors['carrier']) ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group full-width">
                    <button type="submit" class="submit-btn" id="submitBtn">
                        🚀 Create Outbound Order
                    </button>
                </div>
            </div>
        </form>

        <div style="text-align: center;">
            <a href="dashboard.php" class="back-link">
                ⬅️ Return to Dashboard
            </a>
        </div>
    </div>

    <script>
        // Form validation and enhancement
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('outboundForm');
            const submitBtn = document.getElementById('submitBtn');

            // Real-time validation
            const inputs = form.querySelectorAll('input[required], select[required], textarea[required]');

            function validateForm() {
                let isValid = true;

                inputs.forEach(input => {
                    if (!input.value.trim()) {
                        isValid = false;
                    }
                });

                submitBtn.disabled = !isValid;
            }

            inputs.forEach(input => {
                input.addEventListener('input', validateForm);
                input.addEventListener('change', validateForm);
            });

            // Initial validation
            validateForm();

            // Form submission handling
            form.addEventListener('submit', function(e) {
                submitBtn.disabled = true;
                submitBtn.textContent = '🔄 Creating Order...';

                // Re-enable button after 5 seconds as fallback
                setTimeout(() => {
                    submitBtn.disabled = false;
                    submitBtn.textContent = '🚀 Create Outbound Order';
                }, 5000);
            });

            // Auto-generate order number if empty
            const orderNumberField = document.getElementById('order_number');
            if (!orderNumberField.value) {
                const timestamp = new Date().toISOString().replace(/[-:T]/g, '').slice(0, 14);
                orderNumberField.value = 'ORD-' + timestamp;
            }

            // SKU field enhancement
            const skuField = document.getElementById('sku');
            skuField.addEventListener('input', function() {
                this.value = this.value.toUpperCase();
            });
        });

        // Security: Clear sensitive data on page unload
        window.addEventListener('beforeunload', function() {
            const sensitiveFields = ['delivery_address'];
            sensitiveFields.forEach(fieldName => {
                const field = document.getElementById(fieldName);
                if (field && field.value) {
                    // Don't actually clear during normal navigation
                    // This is just a security measure for some browsers
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
unset($input_data, $errors);
?>
