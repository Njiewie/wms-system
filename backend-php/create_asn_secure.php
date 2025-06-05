<?php
/**
 * Secure ASN Creation System
 * Enhanced with comprehensive security measures
 *
 * Security Features:
 * - CSRF Protection
 * - SQL Injection Prevention
 * - Input Validation & Sanitization
 * - XSS Prevention
 * - Activity Logging
 * - Transaction Management
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
$suppliers = [];

// Generate CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = $security->generateCSRFToken();
}

/**
 * Validate ASN header input
 */
function validateASNHeader($data, &$errors) {
    if (empty($data['asn_number'])) {
        $errors['asn_number'] = "ASN number is required";
    } elseif (!preg_match('/^[A-Za-z0-9\-_]{1,50}$/', $data['asn_number'])) {
        $errors['asn_number'] = "ASN number must be alphanumeric (1-50 characters)";
    }

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
 * Validate ASN line input
 */
function validateASNLine($line, $line_index, &$errors) {
    $line_errors = [];

    if (empty($line['sku_id'])) {
        $line_errors[] = "SKU is required";
    } elseif (!preg_match('/^[A-Za-z0-9\-_]{1,30}$/', $line['sku_id'])) {
        $line_errors[] = "Invalid SKU format";
    }

    if (empty($line['qty']) || !is_numeric($line['qty']) || (int)$line['qty'] <= 0) {
        $line_errors[] = "Valid quantity is required";
    } elseif ((int)$line['qty'] > 1000000) {
        $line_errors[] = "Quantity too large (max 1,000,000)";
    }

    if (!empty($line['expiry_date']) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $line['expiry_date'])) {
        $line_errors[] = "Invalid expiry date format";
    }

    if (!empty($line_errors)) {
        $errors["line_$line_index"] = $line_errors;
    }

    return empty($line_errors);
}

/**
 * Check if ASN number already exists
 */
function asnNumberExists($conn, $asn_number) {
    $existing = secure_select_one($conn,
        "SELECT asn_number FROM asn_header WHERE asn_number = ?",
        "s",
        [$asn_number]
    );
    return $existing !== null;
}

/**
 * Create ASN with lines
 */
function createASNWithLines($conn, $header_data, $lines_data, $user_id) {
    try {
        // Begin transaction
        $conn->autocommit(false);

        // Insert ASN header
        $header_data['created_by'] = $user_id;
        $header_data['created_at'] = date('Y-m-d H:i:s');
        $header_data['status'] = 'Pending';

        $asn_id = secure_insert($conn, 'asn_header', $header_data);

        if (!$asn_id) {
            throw new Exception('Failed to create ASN header');
        }

        // Insert ASN lines
        foreach ($lines_data as $line) {
            $line['asn_number'] = $header_data['asn_number'];
            $line['created_by'] = $user_id;
            $line['created_at'] = date('Y-m-d H:i:s');

            $line_id = secure_insert($conn, 'asn_lines', $line);

            if (!$line_id) {
                throw new Exception('Failed to create ASN line');
            }
        }

        // Commit transaction
        $conn->commit();

        return $asn_id;

    } catch (Exception $e) {
        // Rollback transaction
        $conn->rollback();
        throw $e;
    } finally {
        // Re-enable autocommit
        $conn->autocommit(true);
    }
}

/**
 * Log ASN creation activity
 */
function logASNActivity($conn, $action, $asn_number, $user_id, $details = '') {
    $security = SecurityUtils::getInstance($conn);
    $security->logActivity($user_id, $action,
        "ASN: $asn_number" . ($details ? ", $details" : ''));
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Verify CSRF token
        if (!$security->validateCSRFToken($_POST['csrf_token'] ?? '')) {
            throw new Exception("Invalid security token. Please refresh the page and try again.");
        }

        // Rate limiting
        if (!$security->checkRateLimit($_SESSION['user_id'], 'asn_create', 5, 300)) {
            throw new Exception("Too many ASN creation requests. Please wait before creating another ASN.");
        }

        // Sanitize header input
        $header_data = [
            'asn_number' => $security->sanitizeInput($_POST['asn_number'] ?? ''),
            'supplier_name' => $security->sanitizeInput($_POST['supplier_name'] ?? ''),
            'arrival_date' => $security->sanitizeInput($_POST['arrival_date'] ?? ''),
            'client_id' => !empty($_POST['client_id']) ? (int)$_POST['client_id'] : null,
            'notes' => $security->sanitizeInput($_POST['notes'] ?? '', 1000)
        ];

        // Validate header
        if (!validateASNHeader($header_data, $errors)) {
            throw new Exception("Please correct the header validation errors.");
        }

        // Check if ASN number already exists
        if (asnNumberExists($conn, $header_data['asn_number'])) {
            $errors['asn_number'] = "ASN number already exists";
            throw new Exception("ASN number must be unique.");
        }

        // Process lines data
        $lines_data = [];
        if (isset($_POST['sku_id']) && is_array($_POST['sku_id'])) {
            foreach ($_POST['sku_id'] as $index => $sku_id) {
                if (empty($sku_id)) continue; // Skip empty lines

                $line_data = [
                    'line_id' => $index + 1,
                    'sku_id' => $security->sanitizeInput($sku_id),
                    'qty_expected' => (int)($_POST['qty'][$index] ?? 0),
                    'qty_received' => 0, // Initially zero
                    'batch_id' => $security->sanitizeInput($_POST['batch_id'][$index] ?? ''),
                    'condition' => $security->sanitizeInput($_POST['condition'][$index] ?? 'Good'),
                    'expiry_date' => !empty($_POST['expiry_date'][$index]) ? $_POST['expiry_date'][$index] : null,
                    'pack_config' => $security->sanitizeInput($_POST['pack_config'][$index] ?? ''),
                    'description' => $security->sanitizeInput($_POST['description'][$index] ?? '')
                ];

                if (validateASNLine($line_data, $index, $errors)) {
                    $lines_data[] = $line_data;
                }
            }
        }

        if (empty($lines_data)) {
            throw new Exception("At least one valid ASN line is required.");
        }

        if (!empty($errors)) {
            throw new Exception("Please correct the validation errors below.");
        }

        // Create ASN
        $asn_id = createASNWithLines($conn, $header_data, $lines_data, $_SESSION['user_id']);

        // Log successful creation
        logASNActivity($conn, 'ASN_CREATED', $header_data['asn_number'], $_SESSION['user_id'],
            "Lines: " . count($lines_data) . ", Supplier: " . $header_data['supplier_name']);

        $message = "✅ ASN created successfully!<br>
                   <strong>ASN Number:</strong> " . htmlspecialchars($header_data['asn_number']) . "<br>
                   <strong>Line Items:</strong> " . count($lines_data) . "<br>
                   <strong>Supplier:</strong> " . htmlspecialchars($header_data['supplier_name']);

        // Clear form data after successful submission
        $header_data = ['asn_number' => '', 'supplier_name' => '', 'arrival_date' => '', 'client_id' => '', 'notes' => ''];

    } catch (Exception $e) {
        $message = "❌ Error: " . htmlspecialchars($e->getMessage());

        // Log security events
        if (strpos($e->getMessage(), 'security token') !== false ||
            strpos($e->getMessage(), 'Too many requests') !== false) {

            $security->logSecurityEvent($_SESSION['user_id'], 'asn_creation_security_violation', $e->getMessage());
        }

        error_log("ASN Creation Error: " . $e->getMessage() . " | User: " . $_SESSION['user_id'] . " | IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    }
}

// Load clients and suppliers for dropdowns
try {
    $clients = secure_select_all($conn,
        "SELECT id, client_name FROM clients ORDER BY client_name"
    );

    $suppliers = secure_select_all($conn,
        "SELECT DISTINCT supplier_name FROM asn_header
         WHERE supplier_name IS NOT NULL AND supplier_name != ''
         ORDER BY supplier_name LIMIT 20"
    );
} catch (Exception $e) {
    error_log("Failed to load dropdown data: " . $e->getMessage());
}

// Set default values if not set
if (!isset($header_data)) {
    $header_data = ['asn_number' => '', 'supplier_name' => '', 'arrival_date' => '', 'client_id' => '', 'notes' => ''];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Create ASN - Secure WMS">
    <title>Create ASN | Secure WMS</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="modern-style.css">
    <style>
        .asn-container {
            max-width: 1400px;
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

        .form-section {
            margin-bottom: 2rem;
            padding-bottom: 2rem;
            border-bottom: 1px solid #e2e8f0;
        }

        .form-section:last-child {
            border-bottom: none;
        }

        .section-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: #374151;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
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

        .lines-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
        }

        .lines-table th {
            background: #f8fafc;
            padding: 1rem 0.5rem;
            text-align: left;
            font-weight: 600;
            color: #374151;
            border: 1px solid #e2e8f0;
            font-size: 0.875rem;
        }

        .lines-table td {
            padding: 0.5rem;
            border: 1px solid #e2e8f0;
            vertical-align: top;
        }

        .lines-table input,
        .lines-table select {
            width: 100%;
            padding: 8px;
            border: 1px solid #d1d5db;
            border-radius: 4px;
            font-size: 0.875rem;
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

        .btn-secondary:hover {
            background: #e5e7eb;
        }

        .btn-success {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
        }

        .btn-danger {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            color: white;
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 0.875rem;
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

        .line-actions {
            display: flex;
            gap: 0.5rem;
            align-items: center;
        }

        .auto-fill-info {
            background: #f0f9ff;
            color: #0369a1;
            padding: 0.5rem;
            border-radius: 4px;
            font-size: 0.875rem;
            margin-top: 1rem;
        }

        @media (max-width: 768px) {
            .asn-container {
                padding: 1rem;
            }

            .page-header {
                padding: 1rem;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .lines-table {
                font-size: 0.75rem;
            }

            .lines-table th,
            .lines-table td {
                padding: 0.25rem;
            }
        }
    </style>
</head>
<body>
    <div class="asn-container">
        <!-- Page Header -->
        <div class="page-header">
            <h1 style="margin: 0; font-size: 2.5rem;">📦 Create ASN</h1>
            <p style="margin: 0.5rem 0 0; font-size: 1.25rem; opacity: 0.9;">
                Advanced Shipping Notice Creation
            </p>
        </div>

        <!-- Messages -->
        <?php if ($message): ?>
            <div class="alert <?= strpos($message, '✅') !== false ? 'alert-success' : 'alert-error' ?>">
                <?= $message ?>
            </div>
        <?php endif; ?>

        <!-- ASN Creation Form -->
        <form method="POST" id="asnForm" novalidate>
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

            <div class="form-container">
                <!-- ASN Header Section -->
                <div class="form-section">
                    <h2 class="section-title">📋 ASN Header Information</h2>

                    <div class="form-grid">
                        <div class="form-group">
                            <label for="asn_number" class="form-label">ASN Number <span class="required">*</span></label>
                            <input type="text"
                                   id="asn_number"
                                   name="asn_number"
                                   class="form-control"
                                   value="<?= htmlspecialchars($header_data['asn_number']) ?>"
                                   maxlength="50"
                                   pattern="[A-Za-z0-9\-_]+"
                                   required>
                            <div class="help-text">Alphanumeric characters, hyphens, and underscores only</div>
                            <?php if (isset($errors['asn_number'])): ?>
                                <div class="error-message"><?= htmlspecialchars($errors['asn_number']) ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="form-group">
                            <label for="supplier_name" class="form-label">Supplier Name <span class="required">*</span></label>
                            <input type="text"
                                   id="supplier_name"
                                   name="supplier_name"
                                   class="form-control"
                                   value="<?= htmlspecialchars($header_data['supplier_name']) ?>"
                                   maxlength="255"
                                   list="suppliers"
                                   required>
                            <datalist id="suppliers">
                                <?php foreach ($suppliers as $supplier): ?>
                                    <option value="<?= htmlspecialchars($supplier['supplier_name']) ?>">
                                <?php endforeach; ?>
                            </datalist>
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
                                   value="<?= htmlspecialchars($header_data['arrival_date']) ?>"
                                   min="<?= date('Y-m-d') ?>"
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
                                            <?= $header_data['client_id'] == $client['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($client['client_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="help-text">Client for this shipment</div>
                            <?php if (isset($errors['client_id'])): ?>
                                <div class="error-message"><?= htmlspecialchars($errors['client_id']) ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="form-group full-width">
                            <label for="notes" class="form-label">Notes</label>
                            <textarea id="notes"
                                      name="notes"
                                      class="form-control"
                                      rows="3"
                                      maxlength="1000"><?= htmlspecialchars($header_data['notes']) ?></textarea>
                            <div class="help-text">Additional notes or special instructions</div>
                        </div>
                    </div>
                </div>

                <!-- ASN Lines Section -->
                <div class="form-section">
                    <h2 class="section-title">📋 ASN Line Items</h2>

                    <div class="auto-fill-info">
                        💡 <strong>Auto-fill:</strong> When you enter a SKU, the system will automatically fill in the description and pack configuration if available.
                    </div>

                    <div style="overflow-x: auto;">
                        <table class="lines-table" id="linesTable">
                            <thead>
                                <tr>
                                    <th>Line</th>
                                    <th>SKU ID <span class="required">*</span></th>
                                    <th>Description</th>
                                    <th>Quantity <span class="required">*</span></th>
                                    <th>Batch ID</th>
                                    <th>Condition</th>
                                    <th>Pack Config</th>
                                    <th>Expiry Date</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="linesTableBody">
                                <tr>
                                    <td><span class="line-number">1</span></td>
                                    <td>
                                        <input type="text" name="sku_id[]" class="sku-input" maxlength="30" required>
                                    </td>
                                    <td>
                                        <input type="text" name="description[]" class="description-input" readonly>
                                    </td>
                                    <td>
                                        <input type="number" name="qty[]" min="1" max="1000000" required>
                                    </td>
                                    <td>
                                        <input type="text" name="batch_id[]" maxlength="50">
                                    </td>
                                    <td>
                                        <select name="condition[]">
                                            <option value="Good">Good</option>
                                            <option value="Damaged">Damaged</option>
                                            <option value="Expired">Expired</option>
                                            <option value="Hold">Hold</option>
                                        </select>
                                    </td>
                                    <td>
                                        <input type="text" name="pack_config[]" class="pack-config-input" readonly>
                                    </td>
                                    <td>
                                        <input type="date" name="expiry_date[]">
                                    </td>
                                    <td>
                                        <div class="line-actions">
                                            <button type="button" class="btn btn-danger btn-sm remove-line">🗑️</button>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div style="margin-top: 1rem; display: flex; gap: 1rem;">
                        <button type="button" class="btn btn-success btn-sm" id="addLineBtn">
                            ➕ Add Line
                        </button>
                        <button type="button" class="btn btn-secondary btn-sm" id="clearLinesBtn">
                            🗑️ Clear All Lines
                        </button>
                    </div>
                </div>

                <!-- Form Actions -->
                <div style="display: flex; gap: 1rem; justify-content: center; align-items: center; margin-top: 2rem;">
                    <button type="submit" class="btn btn-primary" id="submitBtn">
                        ✅ Create ASN
                    </button>
                    <button type="button" class="btn btn-secondary" onclick="resetForm()">
                        🔄 Reset Form
                    </button>
                    <a href="inbound_secure.php" class="btn btn-secondary">
                        ⬅️ Back to Inbound
                    </a>
                </div>
            </div>
        </form>
    </div>

    <script>
        let lineCounter = 1;

        // Auto-generate ASN number if empty
        document.addEventListener('DOMContentLoaded', function() {
            const asnNumberField = document.getElementById('asn_number');
            if (!asnNumberField.value) {
                const timestamp = new Date().toISOString().replace(/[-:T]/g, '').slice(0, 14);
                asnNumberField.value = 'ASN-' + timestamp;
            }

            // Set default arrival date to tomorrow
            const arrivalDateField = document.getElementById('arrival_date');
            if (!arrivalDateField.value) {
                const tomorrow = new Date();
                tomorrow.setDate(tomorrow.getDate() + 1);
                arrivalDateField.value = tomorrow.toISOString().split('T')[0];
            }
        });

        // SKU auto-fill functionality
        function setupSKUAutofill(row) {
            const skuInput = row.querySelector('.sku-input');
            const descInput = row.querySelector('.description-input');
            const packConfigInput = row.querySelector('.pack-config-input');

            if (skuInput) {
                skuInput.addEventListener('blur', function() {
                    const sku = this.value.trim();
                    if (sku) {
                        fetchSKUInfo(sku, descInput, packConfigInput);
                    }
                });
            }
        }

        function fetchSKUInfo(sku, descInput, packConfigInput) {
            fetch('fetch_sku_info_secure.php?sku=' + encodeURIComponent(sku))
                .then(response => response.json())
                .then(data => {
                    if (descInput) descInput.value = data.description || '';
                    if (packConfigInput) packConfigInput.value = data.pack_config || '';
                })
                .catch(error => {
                    console.error('Error fetching SKU info:', error);
                });
        }

        // Add new line
        document.getElementById('addLineBtn').addEventListener('click', function() {
            lineCounter++;
            const tbody = document.getElementById('linesTableBody');
            const newRow = tbody.rows[0].cloneNode(true);

            // Clear inputs
            newRow.querySelectorAll('input, select').forEach(input => {
                if (input.type === 'number') {
                    input.value = '';
                } else if (input.tagName === 'SELECT') {
                    input.selectedIndex = 0;
                } else {
                    input.value = '';
                }
            });

            // Update line number
            newRow.querySelector('.line-number').textContent = lineCounter;

            tbody.appendChild(newRow);
            setupSKUAutofill(newRow);
            updateRemoveButtons();
        });

        // Remove line functionality
        function updateRemoveButtons() {
            const rows = document.querySelectorAll('#linesTableBody tr');
            rows.forEach((row, index) => {
                const removeBtn = row.querySelector('.remove-line');
                if (removeBtn) {
                    removeBtn.onclick = function() {
                        if (rows.length > 1) {
                            row.remove();
                            updateLineNumbers();
                        } else {
                            alert('At least one line item is required.');
                        }
                    };
                    removeBtn.disabled = rows.length <= 1;
                }
            });
        }

        function updateLineNumbers() {
            const rows = document.querySelectorAll('#linesTableBody tr');
            rows.forEach((row, index) => {
                row.querySelector('.line-number').textContent = index + 1;
            });
            lineCounter = rows.length;
        }

        // Clear all lines
        document.getElementById('clearLinesBtn').addEventListener('click', function() {
            if (confirm('Are you sure you want to clear all line items?')) {
                const tbody = document.getElementById('linesTableBody');
                tbody.innerHTML = '';
                lineCounter = 0;
                document.getElementById('addLineBtn').click(); // Add one empty line
            }
        });

        // Form validation
        document.getElementById('asnForm').addEventListener('submit', function(e) {
            const skuInputs = document.querySelectorAll('.sku-input');
            const hasValidLines = Array.from(skuInputs).some(input => input.value.trim() !== '');

            if (!hasValidLines) {
                e.preventDefault();
                alert('Please add at least one line item with a valid SKU.');
                return;
            }

            // Disable submit button
            const submitBtn = document.getElementById('submitBtn');
            submitBtn.disabled = true;
            submitBtn.textContent = '🔄 Creating ASN...';

            // Re-enable after timeout as fallback
            setTimeout(() => {
                submitBtn.disabled = false;
                submitBtn.textContent = '✅ Create ASN';
            }, 10000);
        });

        // Reset form
        function resetForm() {
            if (confirm('Are you sure you want to reset the form? All data will be lost.')) {
                document.getElementById('asnForm').reset();

                // Reset line items
                const tbody = document.getElementById('linesTableBody');
                tbody.innerHTML = '';
                lineCounter = 0;
                document.getElementById('addLineBtn').click();

                // Reset auto-generated fields
                const timestamp = new Date().toISOString().replace(/[-:T]/g, '').slice(0, 14);
                document.getElementById('asn_number').value = 'ASN-' + timestamp;

                const tomorrow = new Date();
                tomorrow.setDate(tomorrow.getDate() + 1);
                document.getElementById('arrival_date').value = tomorrow.toISOString().split('T')[0];
            }
        }

        // Initialize
        setupSKUAutofill(document.querySelector('#linesTableBody tr'));
        updateRemoveButtons();
    </script>
</body>
</html>

<?php
// Clean up and close connections
if (isset($conn)) {
    $conn->close();
}

// Clean sensitive variables
unset($header_data, $clients, $suppliers, $errors);
?>
