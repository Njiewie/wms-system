<?php
/**
 * Secure ASN Creation Form
 * Advanced Shipping Notice creation with comprehensive validation and transaction management
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
    $security->logActivity('RATE_LIMIT_EXCEEDED', ['page' => 'create_asn'], 'WARNING');
    die('Rate limit exceeded. Please try again later.');
}

$security->logActivity('CREATE_ASN_PAGE_ACCESS', ['user_id' => get_current_user_id()]);

$csrfToken = $security->generateCSRFToken();
$message = '';
$messageType = '';
$formData = [];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$security->validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = 'Invalid security token. Please try again.';
        $messageType = 'danger';
        $security->logActivity('CSRF_TOKEN_VALIDATION_FAILED', ['page' => 'create_asn'], 'WARNING');
    } else {
        try {
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
                    'required' => true,
                    'type' => 'date'
                ],
                'shipping_method' => [
                    'required' => true,
                    'max_length' => 100
                ],
                'priority' => [
                    'required' => true
                ],
                'contact_person' => [
                    'max_length' => 100
                ],
                'contact_phone' => [
                    'max_length' => 20
                ]
            ];

            $validationErrors = $security->validateInput($formData, $validationRules);

            // Additional custom validations
            if (empty($validationErrors)) {
                // Check if ASN number already exists
                $existingAsn = $db->fetchRow(
                    "SELECT id FROM asn WHERE asn_number = :asn_number AND deleted_at IS NULL",
                    [':asn_number' => $formData['asn_number']]
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

                // Validate expected date is not in the past
                if ($formData['expected_date'] < date('Y-m-d')) {
                    $validationErrors['expected_date'] = 'Expected date cannot be in the past';
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
                    // Generate unique ASN ID if needed
                    if (empty($formData['asn_number'])) {
                        $formData['asn_number'] = 'ASN' . date('Ymd') . str_pad(
                            $db->fetchValue("SELECT COUNT(*) + 1 FROM asn WHERE DATE(created_at) = CURDATE()"),
                            4, '0', STR_PAD_LEFT
                        );
                    }

                    // Prepare ASN data for insertion
                    $asnData = [
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
                        'status' => 'draft',
                        'created_by' => get_current_user_id(),
                        'created_at' => date('Y-m-d H:i:s'),
                        'updated_at' => date('Y-m-d H:i:s')
                    ];

                    $asnId = $db->insert('asn', $asnData);

                    if ($asnId) {
                        $security->logActivity('ASN_CREATED', [
                            'asn_id' => $asnId,
                            'asn_number' => $formData['asn_number'],
                            'supplier_id' => $formData['supplier_id'],
                            'supplier_name' => $supplier['name']
                        ]);

                        $db->commit();

                        // Redirect to edit page to add line items
                        header("Location: edit_asn_secure.php?id={$asnId}&created=1");
                        exit();
                    } else {
                        throw new Exception('Failed to create ASN record');
                    }

                } catch (Exception $e) {
                    $db->rollback();
                    throw $e;
                }

            } else {
                $message = 'Please correct the following errors:<br>' . implode('<br>', $validationErrors);
                $messageType = 'danger';
            }

        } catch (Exception $e) {
            $security->logActivity('ASN_CREATION_ERROR', [
                'error' => $e->getMessage(),
                'form_data' => array_intersect_key($formData, array_flip(['asn_number', 'supplier_id', 'priority']))
            ], 'ERROR');
            
            $message = 'Failed to create ASN. Please try again.';
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

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create ASN - WMS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css" rel="stylesheet">
    <style>
        .form-container {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        .form-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem;
            text-align: center;
        }
        .form-body {
            padding: 2rem;
        }
        .section-title {
            color: #495057;
            font-weight: 600;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid #e9ecef;
        }
        .form-floating .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
        }
        .form-floating .form-select:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
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
        .supplier-card {
            border: 2px solid #e9ecef;
            border-radius: 10px;
            padding: 1rem;
            margin-bottom: 1rem;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        .supplier-card:hover {
            border-color: #667eea;
            background: #f8f9ff;
        }
        .supplier-card.selected {
            border-color: #667eea;
            background: #f8f9ff;
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.15);
        }
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(255, 255, 255, 0.8);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 9999;
        }
        .character-counter {
            font-size: 0.875rem;
            color: #6c757d;
            margin-top: 0.25rem;
        }
        .required-field::after {
            content: " *";
            color: #dc3545;
        }
    </style>
</head>
<body class="bg-light">
    <div class="loading-overlay" id="loadingOverlay">
        <div class="spinner-border text-primary" role="status">
            <span class="visually-hidden">Loading...</span>
        </div>
    </div>

    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container-fluid">
            <a class="navbar-brand" href="secure-dashboard.php">
                <i class="fas fa-warehouse me-2"></i>WMS - Create ASN
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

    <div class="container py-4">
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
                <?php echo $message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="form-container">
            <div class="form-header">
                <h2><i class="fas fa-plus-circle me-2"></i>Create New ASN</h2>
                <p class="mb-0">Advanced Shipping Notice - Create and manage incoming shipments</p>
            </div>

            <div class="form-body">
                <form method="POST" id="asnForm" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">

                    <!-- Basic Information Section -->
                    <div class="row mb-4">
                        <div class="col-12">
                            <h4 class="section-title">
                                <i class="fas fa-info-circle me-2"></i>Basic Information
                            </h4>
                        </div>
                    </div>

                    <div class="row mb-4">
                        <div class="col-md-6">
                            <div class="form-floating mb-3">
                                <input type="text" class="form-control" id="asn_number" name="asn_number" 
                                       value="<?php echo htmlspecialchars($formData['asn_number'] ?? ''); ?>"
                                       placeholder="ASN Number" required maxlength="50">
                                <label for="asn_number" class="required-field">ASN Number</label>
                                <div class="form-text">Leave empty to auto-generate</div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-floating mb-3">
                                <input type="text" class="form-control" id="reference_number" name="reference_number" 
                                       value="<?php echo htmlspecialchars($formData['reference_number'] ?? ''); ?>"
                                       placeholder="Reference Number" maxlength="100">
                                <label for="reference_number">Reference Number</label>
                                <div class="form-text">Purchase order or internal reference</div>
                            </div>
                        </div>
                    </div>

                    <div class="row mb-4">
                        <div class="col-md-6">
                            <div class="form-floating mb-3">
                                <select class="form-select" id="supplier_id" name="supplier_id" required>
                                    <option value="">Select Supplier</option>
                                    <?php foreach ($suppliers as $supplier): ?>
                                        <option value="<?php echo $supplier['id']; ?>" 
                                                <?php echo ($formData['supplier_id'] ?? 0) == $supplier['id'] ? 'selected' : ''; ?>
                                                data-name="<?php echo htmlspecialchars($supplier['name']); ?>"
                                                data-code="<?php echo htmlspecialchars($supplier['code'] ?? ''); ?>"
                                                data-contact="<?php echo htmlspecialchars($supplier['contact_person'] ?? ''); ?>"
                                                data-phone="<?php echo htmlspecialchars($supplier['phone'] ?? ''); ?>">
                                            <?php echo htmlspecialchars($supplier['name']); ?>
                                            <?php if ($supplier['code']): ?>
                                                (<?php echo htmlspecialchars($supplier['code']); ?>)
                                            <?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <label for="supplier_id" class="required-field">Supplier</label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-floating mb-3">
                                <input type="date" class="form-control" id="expected_date" name="expected_date" 
                                       value="<?php echo htmlspecialchars($formData['expected_date'] ?? ''); ?>"
                                       required min="<?php echo date('Y-m-d'); ?>">
                                <label for="expected_date" class="required-field">Expected Date</label>
                            </div>
                        </div>
                    </div>

                    <!-- Priority Selection -->
                    <div class="row mb-4">
                        <div class="col-12">
                            <label class="form-label required-field">Priority Level</label>
                            <div class="d-flex flex-wrap">
                                <?php
                                $priorities = [
                                    'low' => ['label' => 'Low', 'icon' => 'fas fa-arrow-down'],
                                    'normal' => ['label' => 'Normal', 'icon' => 'fas fa-minus'],
                                    'high' => ['label' => 'High', 'icon' => 'fas fa-arrow-up'],
                                    'urgent' => ['label' => 'Urgent', 'icon' => 'fas fa-exclamation-triangle']
                                ];
                                $selectedPriority = $formData['priority'] ?? 'normal';
                                ?>
                                <?php foreach ($priorities as $value => $priority): ?>
                                    <label class="priority-badge priority-<?php echo $value; ?> <?php echo $selectedPriority === $value ? 'active' : ''; ?>">
                                        <input type="radio" name="priority" value="<?php echo $value; ?>" 
                                               <?php echo $selectedPriority === $value ? 'checked' : ''; ?> style="display: none;">
                                        <i class="<?php echo $priority['icon']; ?> me-2"></i>
                                        <?php echo $priority['label']; ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Shipping Information Section -->
                    <div class="row mb-4">
                        <div class="col-12">
                            <h4 class="section-title">
                                <i class="fas fa-truck me-2"></i>Shipping Information
                            </h4>
                        </div>
                    </div>

                    <div class="row mb-4">
                        <div class="col-md-6">
                            <div class="form-floating mb-3">
                                <select class="form-select" id="shipping_method" name="shipping_method" required>
                                    <option value="">Select Shipping Method</option>
                                    <option value="ground" <?php echo ($formData['shipping_method'] ?? '') === 'ground' ? 'selected' : ''; ?>>Ground</option>
                                    <option value="express" <?php echo ($formData['shipping_method'] ?? '') === 'express' ? 'selected' : ''; ?>>Express</option>
                                    <option value="overnight" <?php echo ($formData['shipping_method'] ?? '') === 'overnight' ? 'selected' : ''; ?>>Overnight</option>
                                    <option value="freight" <?php echo ($formData['shipping_method'] ?? '') === 'freight' ? 'selected' : ''; ?>>Freight</option>
                                    <option value="pickup" <?php echo ($formData['shipping_method'] ?? '') === 'pickup' ? 'selected' : ''; ?>>Pickup</option>
                                    <option value="other" <?php echo ($formData['shipping_method'] ?? '') === 'other' ? 'selected' : ''; ?>>Other</option>
                                </select>
                                <label for="shipping_method" class="required-field">Shipping Method</label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-floating mb-3">
                                <input type="text" class="form-control" id="tracking_number" name="tracking_number" 
                                       value="<?php echo htmlspecialchars($formData['tracking_number'] ?? ''); ?>"
                                       placeholder="Tracking Number" maxlength="100">
                                <label for="tracking_number">Tracking Number</label>
                            </div>
                        </div>
                    </div>

                    <div class="row mb-4">
                        <div class="col-md-6">
                            <div class="form-floating mb-3">
                                <select class="form-select" id="warehouse_location" name="warehouse_location">
                                    <option value="">Select Location</option>
                                    <?php foreach ($warehouseLocations as $location): ?>
                                        <option value="<?php echo htmlspecialchars($location['location']); ?>"
                                                <?php echo ($formData['warehouse_location'] ?? '') === $location['location'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($location['location']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                    <option value="DOCK-A" <?php echo ($formData['warehouse_location'] ?? '') === 'DOCK-A' ? 'selected' : ''; ?>>DOCK-A</option>
                                    <option value="DOCK-B" <?php echo ($formData['warehouse_location'] ?? '') === 'DOCK-B' ? 'selected' : ''; ?>>DOCK-B</option>
                                    <option value="DOCK-C" <?php echo ($formData['warehouse_location'] ?? '') === 'DOCK-C' ? 'selected' : ''; ?>>DOCK-C</option>
                                </select>
                                <label for="warehouse_location">Receiving Location</label>
                            </div>
                        </div>
                    </div>

                    <!-- Contact Information Section -->
                    <div class="row mb-4">
                        <div class="col-12">
                            <h4 class="section-title">
                                <i class="fas fa-address-book me-2"></i>Contact Information
                            </h4>
                        </div>
                    </div>

                    <div class="row mb-4">
                        <div class="col-md-6">
                            <div class="form-floating mb-3">
                                <input type="text" class="form-control" id="contact_person" name="contact_person" 
                                       value="<?php echo htmlspecialchars($formData['contact_person'] ?? ''); ?>"
                                       placeholder="Contact Person" maxlength="100">
                                <label for="contact_person">Contact Person</label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-floating mb-3">
                                <input type="tel" class="form-control" id="contact_phone" name="contact_phone" 
                                       value="<?php echo htmlspecialchars($formData['contact_phone'] ?? ''); ?>"
                                       placeholder="Contact Phone" maxlength="20">
                                <label for="contact_phone">Contact Phone</label>
                            </div>
                        </div>
                    </div>

                    <!-- Additional Information Section -->
                    <div class="row mb-4">
                        <div class="col-12">
                            <h4 class="section-title">
                                <i class="fas fa-sticky-note me-2"></i>Additional Information
                            </h4>
                        </div>
                    </div>

                    <div class="row mb-4">
                        <div class="col-md-6">
                            <div class="form-floating mb-3">
                                <textarea class="form-control" id="notes" name="notes" 
                                          placeholder="General Notes" style="height: 120px;" maxlength="1000"><?php echo htmlspecialchars($formData['notes'] ?? ''); ?></textarea>
                                <label for="notes">General Notes</label>
                                <div class="character-counter">
                                    <span id="notesCounter">0</span>/1000 characters
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-floating mb-3">
                                <textarea class="form-control" id="special_instructions" name="special_instructions" 
                                          placeholder="Special Instructions" style="height: 120px;" maxlength="1000"><?php echo htmlspecialchars($formData['special_instructions'] ?? ''); ?></textarea>
                                <label for="special_instructions">Special Instructions</label>
                                <div class="character-counter">
                                    <span id="instructionsCounter">0</span>/1000 characters
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Supplier Information Display -->
                    <div id="supplierInfo" class="supplier-card" style="display: none;">
                        <h5><i class="fas fa-building me-2"></i>Supplier Information</h5>
                        <div class="row">
                            <div class="col-md-6">
                                <strong>Contact Person:</strong> <span id="supplierContact">-</span>
                            </div>
                            <div class="col-md-6">
                                <strong>Phone:</strong> <span id="supplierPhone">-</span>
                            </div>
                        </div>
                    </div>

                    <!-- Form Actions -->
                    <div class="row">
                        <div class="col-12">
                            <div class="d-flex justify-content-between">
                                <a href="inbound_secure.php" class="btn btn-outline-secondary">
                                    <i class="fas fa-times me-2"></i>Cancel
                                </a>
                                <div>
                                    <button type="submit" class="btn btn-success btn-lg">
                                        <i class="fas fa-save me-2"></i>Create ASN
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize date picker
            flatpickr("#expected_date", {
                minDate: "today",
                dateFormat: "Y-m-d"
            });

            // Priority radio button handling
            document.querySelectorAll('input[name="priority"]').forEach(radio => {
                radio.addEventListener('change', function() {
                    document.querySelectorAll('.priority-badge').forEach(badge => {
                        badge.classList.remove('active');
                    });
                    this.closest('.priority-badge').classList.add('active');
                });
            });

            // Supplier selection handling
            const supplierSelect = document.getElementById('supplier_id');
            const supplierInfo = document.getElementById('supplierInfo');
            const contactPerson = document.getElementById('contact_person');
            const contactPhone = document.getElementById('contact_phone');

            supplierSelect.addEventListener('change', function() {
                const selectedOption = this.options[this.selectedIndex];
                
                if (selectedOption.value) {
                    const supplierData = {
                        name: selectedOption.dataset.name,
                        code: selectedOption.dataset.code,
                        contact: selectedOption.dataset.contact,
                        phone: selectedOption.dataset.phone
                    };

                    // Show supplier info
                    document.getElementById('supplierContact').textContent = supplierData.contact || '-';
                    document.getElementById('supplierPhone').textContent = supplierData.phone || '-';
                    supplierInfo.style.display = 'block';

                    // Auto-fill contact fields if empty
                    if (!contactPerson.value && supplierData.contact) {
                        contactPerson.value = supplierData.contact;
                    }
                    if (!contactPhone.value && supplierData.phone) {
                        contactPhone.value = supplierData.phone;
                    }
                } else {
                    supplierInfo.style.display = 'none';
                }
            });

            // Character counters
            function updateCharacterCounter(textareaId, counterId) {
                const textarea = document.getElementById(textareaId);
                const counter = document.getElementById(counterId);
                
                function updateCount() {
                    const count = textarea.value.length;
                    counter.textContent = count;
                    
                    if (count > textarea.maxLength * 0.8) {
                        counter.style.color = '#dc3545';
                    } else if (count > textarea.maxLength * 0.6) {
                        counter.style.color = '#ffc107';
                    } else {
                        counter.style.color = '#6c757d';
                    }
                }
                
                textarea.addEventListener('input', updateCount);
                updateCount();
            }

            updateCharacterCounter('notes', 'notesCounter');
            updateCharacterCounter('special_instructions', 'instructionsCounter');

            // Form validation
            const form = document.getElementById('asnForm');
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
                } else {
                    showLoading(true);
                }
                
                form.classList.add('was-validated');
            });

            // Real-time validation
            form.querySelectorAll('input, select, textarea').forEach(field => {
                field.addEventListener('blur', function() {
                    if (this.hasAttribute('required') && !this.value.trim()) {
                        this.classList.add('is-invalid');
                    } else {
                        this.classList.remove('is-invalid');
                        this.classList.add('is-valid');
                    }
                });
            });

            // Initialize supplier info on page load
            if (supplierSelect.value) {
                supplierSelect.dispatchEvent(new Event('change'));
            }
        });

        function showLoading(show) {
            document.getElementById('loadingOverlay').style.display = show ? 'flex' : 'none';
        }

        // Auto-generate ASN number if empty
        function generateAsnNumber() {
            const asnInput = document.getElementById('asn_number');
            if (!asnInput.value.trim()) {
                const today = new Date();
                const dateStr = today.toISOString().slice(0, 10).replace(/-/g, '');
                const randomSuffix = Math.floor(Math.random() * 1000).toString().padStart(3, '0');
                asnInput.value = `ASN${dateStr}${randomSuffix}`;
            }
        }

        // Format phone number input
        document.getElementById('contact_phone').addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            if (value.length >= 10) {
                value = value.replace(/(\d{3})(\d{3})(\d{4})/, '($1) $2-$3');
            } else if (value.length >= 6) {
                value = value.replace(/(\d{3})(\d{3})/, '($1) $2-');
            } else if (value.length >= 3) {
                value = value.replace(/(\d{3})/, '($1) ');
            }
            e.target.value = value;
        });
    </script>
</body>
</html>