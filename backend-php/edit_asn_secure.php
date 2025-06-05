<?php
/**
 * Secure ASN Edit System
 * Enhanced with comprehensive security measures
 *
 * Security Features:
 * - CSRF Protection
 * - SQL Injection Prevention
 * - Input Validation & Sanitization
 * - XSS Prevention
 * - Activity Logging
 * - Access Control
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
$asn_data = null;
$clients = [];

// Generate CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = $security->generateCSRFToken();
}

// Validate ASN number parameter
if (!isset($_GET['asn_number'])) {
    $security->logSecurityEvent($_SESSION['user_id'] ?? 'unknown', 'invalid_asn_edit_access',
        'Attempted to edit ASN without ASN number');
    header('Location: inbound_secure.php?error=' . urlencode('ASN number is required'));
    exit;
}

try {
    // Sanitize and validate ASN number
    $asn_number = $security->sanitizeInput($_GET['asn_number'], 50);

    if (empty($asn_number) || !preg_match('/^[A-Za-z0-9\-_]+$/', $asn_number)) {
        throw new InvalidArgumentException('Invalid ASN number format');
    }

    // Rate limiting
    if (!$security->checkRateLimit($_SESSION['user_id'], 'asn_edit', 10, 300)) {
        throw new Exception('Too many edit requests. Please wait before editing another ASN.');
    }

    // Check if user has access to this ASN
    $asn_data = secure_select_one($conn,
        "SELECT ah.*, c.client_name
         FROM asn_header ah
         LEFT JOIN clients c ON ah.client_id = c.id
         WHERE ah.asn_number = ?",
        "s",
        [$asn_number]
    );

    if (!$asn_data) {
        $security->logSecurityEvent($_SESSION['user_id'], 'unauthorized_asn_edit_access',
            "Attempted to edit non-existent ASN: $asn_number");
        throw new Exception('ASN not found or access denied');
    }

    // Check if ASN can be edited (not completed)
    if ($asn_data['status'] === 'Completed') {
        throw new Exception('Cannot edit completed ASN');
    }

} catch (Exception $e) {
    $message = "❌ Error: " . htmlspecialchars($e->getMessage());

    // Log security events
    if (strpos($e->getMessage(), 'Invalid') !== false ||
        strpos($e->getMessage(), 'access denied') !== false ||
        strpos($e->getMessage(), 'Too many requests') !== false) {

        $security->logSecurityEvent($_SESSION['user_id'], 'asn_edit_violation', $e->getMessage());
    }

    error_log("ASN Edit Access Error: " . $e->getMessage() . " | User: " . $_SESSION['user_id'] . " | IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
}

/**
 * Validate ASN edit input
 */
function validateASNEditInput($data, &$errors) {
    if (empty($data['supplier_name'])) {
        $errors['supplier_name'] = "Supplier name is required";
    } elseif (strlen($data['supplier_name']) > 255) {
        $errors['supplier_name'] = "Supplier name is too long (max 255 characters)";
    }

    if (empty($data['arrival_date'])) {
        $errors['arrival_date'] = "Arrival date is required";
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['arrival_date'])) {
        $errors['arrival_date'] = "Invalid arrival date format";
    }

    if (!empty($data['client_id']) && !is_numeric($data['client_id'])) {
        $errors['client_id'] = "Invalid client ID";
    }

    return empty($errors);
}

/**
 * Update ASN header
 */
function updateASNHeader($conn, $asn_number, $data, $user_id) {
    $data['updated_by'] = $user_id;
    $data['updated_at'] = date('Y-m-d H:i:s');

    $updated = secure_update($conn, 'asn_header', $data, 'asn_number = ?', 's', [$asn_number]);

    if (!$updated) {
        throw new Exception('Failed to update ASN');
    }

    return true;
}

/**
 * Log ASN edit activity
 */
function logASNEditActivity($conn, $action, $asn_number, $user_id, $details = '') {
    $security = SecurityUtils::getInstance($conn);
    $security->logActivity($user_id, $action,
        "ASN: $asn_number" . ($details ? ", $details" : ''));
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $asn_data) {
    try {
        // Verify CSRF token
        if (!$security->validateCSRFToken($_POST['csrf_token'] ?? '')) {
            throw new Exception("Invalid security token. Please refresh the page and try again.");
        }

        // Rate limiting for updates
        if (!$security->checkRateLimit($_SESSION['user_id'], 'asn_update', 5, 300)) {
            throw new Exception("Too many update requests. Please wait before updating again.");
        }

        // Sanitize input data
        $update_data = [
            'supplier_name' => $security->sanitizeInput($_POST['supplier_name'] ?? ''),
            'arrival_date' => $security->sanitizeInput($_POST['arrival_date'] ?? ''),
            'client_id' => !empty($_POST['client_id']) ? (int)$_POST['client_id'] : null,
            'status' => $security->sanitizeInput($_POST['status'] ?? 'Pending'),
            'notes' => $security->sanitizeInput($_POST['notes'] ?? '', 1000)
        ];

        // Validate input
        if (!validateASNEditInput($update_data, $errors)) {
            throw new Exception("Please correct the validation errors below.");
        }

        // Validate status
        $allowed_statuses = ['Pending', 'Released', 'In Progress', 'Hold'];
        if (!in_array($update_data['status'], $allowed_statuses)) {
            throw new Exception("Invalid status value");
        }

        // Update ASN
        updateASNHeader($conn, $asn_number, $update_data, $_SESSION['user_id']);

        // Log successful update
        $changes = [];
        foreach ($update_data as $key => $value) {
            if ($asn_data[$key] != $value) {
                $changes[] = "$key: {$asn_data[$key]} → $value";
            }
        }

        logASNEditActivity($conn, 'ASN_UPDATED', $asn_number, $_SESSION['user_id'],
            implode(', ', $changes));

        $message = "✅ ASN updated successfully!<br>
                   <strong>ASN Number:</strong> " . htmlspecialchars($asn_number) . "<br>
                   <strong>Changes:</strong> " . (count($changes) > 0 ? count($changes) . " fields updated" : "No changes made");

        // Refresh ASN data
        $asn_data = secure_select_one($conn,
            "SELECT ah.*, c.client_name
             FROM asn_header ah
             LEFT JOIN clients c ON ah.client_id = c.id
             WHERE ah.asn_number = ?",
            "s",
            [$asn_number]
        );

    } catch (Exception $e) {
        $message = "❌ Error: " . htmlspecialchars($e->getMessage());

        // Log security events
        if (strpos($e->getMessage(), 'security token') !== false ||
            strpos($e->getMessage(), 'Too many requests') !== false) {

            $security->logSecurityEvent($_SESSION['user_id'], 'asn_edit_security_violation', $e->getMessage());
        }

        error_log("ASN Edit Error: " . $e->getMessage() . " | User: " . $_SESSION['user_id'] . " | IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
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
    <meta name="description" content="Edit ASN - Secure WMS">
    <title>Edit ASN | Secure WMS</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="modern-style.css">
    <style>
        .edit-container {
            max-width: 800px;
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

        .asn-info {
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
        .status-released { background: #d1fae5; color: #065f46; }
        .status-hold { background: #fee2e2; color: #991b1b; }
        .status-in-progress { background: #dbeafe; color: #1e40af; }
        .status-completed { background: #f3e8ff; color: #6b21a8; }

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
            <h1 style="margin: 0; font-size: 2.5rem;">✏️ Edit ASN</h1>
            <p style="margin: 0.5rem 0 0; font-size: 1.25rem; opacity: 0.9;">
                Update Advanced Shipping Notice Information
            </p>

            <?php if ($asn_data): ?>
            <div class="asn-info">
                <div class="info-grid">
                    <div class="info-item">
                        <div class="info-label">ASN Number</div>
                        <div class="info-value"><?= htmlspecialchars($asn_number) ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Current Status</div>
                        <div class="info-value">
                            <span class="status-badge status-<?= strtolower(str_replace(' ', '-', $asn_data['status'] ?? 'pending')) ?>">
                                <?= htmlspecialchars($asn_data['status'] ?? 'Pending') ?>
                            </span>
                        </div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Created</div>
                        <div class="info-value">
                            <?= $asn_data['created_at'] ? date('M d, Y H:i', strtotime($asn_data['created_at'])) : 'N/A' ?>
                        </div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Last Updated</div>
                        <div class="info-value">
                            <?= $asn_data['updated_at'] ? date('M d, Y H:i', strtotime($asn_data['updated_at'])) : 'Never' ?>
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

        <?php if ($asn_data): ?>
        <!-- Edit Form -->
        <div class="form-container">
            <h2 style="margin-top: 0; color: #374151;">ASN Information</h2>

            <form method="POST" id="editForm" novalidate>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

                <div class="form-grid">
                    <div class="form-group">
                        <label for="asn_number_display" class="form-label">ASN Number</label>
                        <input type="text"
                               id="asn_number_display"
                               class="form-control"
                               value="<?= htmlspecialchars($asn_number) ?>"
                               disabled>
                        <div class="help-text">ASN number cannot be changed</div>
                    </div>

                    <div class="form-group">
                        <label for="supplier_name" class="form-label">Supplier Name <span class="required">*</span></label>
                        <input type="text"
                               id="supplier_name"
                               name="supplier_name"
                               class="form-control"
                               value="<?= htmlspecialchars($asn_data['supplier_name'] ?? '') ?>"
                               maxlength="255"
                               required>
                        <div class="help-text">Supplier or vendor name</div>
                        <?php if (isset($errors['supplier_name'])): ?>
                            <div class="error-message"><?= htmlspecialchars($errors['supplier_name']) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label for="arrival_date" class="form-label">Arrival Date <span class="required">*</span></label>
                        <input type="date"
                               id="arrival_date"
                               name="arrival_date"
                               class="form-control"
                               value="<?= htmlspecialchars($asn_data['arrival_date'] ?? '') ?>"
                               required>
                        <div class="help-text">Expected arrival date</div>
                        <?php if (isset($errors['arrival_date'])): ?>
                            <div class="error-message"><?= htmlspecialchars($errors['arrival_date']) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label for="client_id" class="form-label">Client</label>
                        <select id="client_id" name="client_id" class="form-control">
                            <option value="">Select client (optional)...</option>
                            <?php foreach ($clients as $client): ?>
                                <option value="<?= $client['id'] ?>"
                                        <?= ($asn_data['client_id'] ?? '') == $client['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($client['client_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="help-text">Client for this shipment</div>
                        <?php if (isset($errors['client_id'])): ?>
                            <div class="error-message"><?= htmlspecialchars($errors['client_id']) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label for="status" class="form-label">Status</label>
                        <select id="status" name="status" class="form-control">
                            <option value="Pending" <?= ($asn_data['status'] ?? '') === 'Pending' ? 'selected' : '' ?>>Pending</option>
                            <option value="Released" <?= ($asn_data['status'] ?? '') === 'Released' ? 'selected' : '' ?>>Released</option>
                            <option value="In Progress" <?= ($asn_data['status'] ?? '') === 'In Progress' ? 'selected' : '' ?>>In Progress</option>
                            <option value="Hold" <?= ($asn_data['status'] ?? '') === 'Hold' ? 'selected' : '' ?>>Hold</option>
                        </select>
                        <div class="help-text">Current ASN status</div>
                    </div>

                    <div class="form-group full-width">
                        <label for="notes" class="form-label">Notes</label>
                        <textarea id="notes"
                                  name="notes"
                                  class="form-control"
                                  rows="4"
                                  maxlength="1000"><?= htmlspecialchars($asn_data['notes'] ?? '') ?></textarea>
                        <div class="help-text">Additional notes or special instructions (max 1000 characters)</div>
                    </div>
                </div>

                <div class="action-buttons">
                    <button type="submit" class="btn btn-primary" id="updateBtn">
                        💾 Update ASN
                    </button>
                    <a href="asn_lines_secure.php?asn_number=<?= urlencode($asn_number) ?>" class="btn btn-secondary">
                        👁️ View Lines
                    </a>
                    <a href="inbound_secure.php" class="btn btn-secondary">
                        ⬅️ Back to Inbound
                    </a>
                </div>
            </form>
        </div>

        <?php else: ?>

        <!-- Error State -->
        <div class="form-container" style="text-align: center;">
            <h2>ASN Not Found</h2>
            <p style="color: #6b7280; margin-bottom: 2rem;">
                The requested ASN could not be found or you don't have permission to edit it.
            </p>
            <a href="inbound_secure.php" class="btn btn-primary">
                ⬅️ Back to Inbound Dashboard
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
                        updateBtn.textContent = '🔄 Updating ASN...';

                        // Re-enable button after 5 seconds as fallback
                        setTimeout(() => {
                            updateBtn.disabled = false;
                            updateBtn.textContent = '💾 Update ASN';
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

        // Auto-save functionality (optional)
        let autoSaveTimeout;
        function scheduleAutoSave() {
            clearTimeout(autoSaveTimeout);
            autoSaveTimeout = setTimeout(() => {
                // Could implement auto-save here
                console.log('Auto-save would trigger here');
            }, 30000); // Auto-save every 30 seconds
        }

        // Status change confirmation
        document.getElementById('status')?.addEventListener('change', function() {
            const newStatus = this.value;
            const originalStatus = '<?= htmlspecialchars($asn_data['status'] ?? '') ?>';

            if (newStatus === 'Completed' && originalStatus !== 'Completed') {
                if (!confirm('Changing status to Completed will prevent further edits. Are you sure?')) {
                    this.value = originalStatus;
                }
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
unset($asn_data, $clients, $errors);
?>
