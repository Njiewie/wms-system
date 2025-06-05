<?php
require_once 'security-utils.php';
require 'auth.php';
require_login();
include 'db_config.php';

// Set security headers
setSecurityHeaders();

$message = '';
$message_type = 'info';

// Fetch ASN list securely
try {
    $asn_list = secure_select_all($conn,
        "SELECT asn_number, supplier_name, arrival_date FROM asn_header WHERE status != 'Completed'"
    );
} catch (Exception $e) {
    error_log("ASN list fetch error: " . $e->getMessage());
    $asn_list = [];
    $message = "Error loading ASN list.";
    $message_type = 'error';
}

// Process inbound
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['asn_number'])) {
    try {
        // Validate CSRF token
        validate_csrf();

        // Validate ASN number
        $asn_number = WMSSecurity::sanitizeString($_POST['asn_number'], 50);
        if (empty($asn_number)) {
            throw new InvalidArgumentException('Invalid ASN number');
        }

        // Rate limiting for putaway operations
        WMSSecurity::checkRateLimit('putaway_' . $_SESSION['user'], 5, 300);

        // Start transaction for data integrity
        $conn->autocommit(false);

        // Fetch ASN lines securely
        $asn_lines = secure_select_all($conn,
            "SELECT * FROM asn_lines WHERE asn_number = ?",
            "s",
            [$asn_number]
        );

        if (empty($asn_lines)) {
            throw new Exception("No lines found for ASN: $asn_number");
        }

        $processed_lines = 0;
        $errors = [];

        // Process each line: insert or update inventory
        foreach ($asn_lines as $line) {
            try {
                // Validate line data
                $sku_id = WMSSecurity::sanitizeString($line['sku_id'], 50);
                $qty = WMSSecurity::validateInteger($line['qty_expected'], 1);
                $batch_id = WMSSecurity::sanitizeString($line['batch_id'] ?? '', 50);
                $expiry_date = null;

                if (!empty($line['expiry_date'])) {
                    $expiry_date = WMSSecurity::validateDate($line['expiry_date']);
                }

                // Check if inventory record exists
                $existing_inventory = secure_select_one($conn,
                    "SELECT id, qty_on_hand FROM inventory WHERE sku_id = ? AND batch_id = ?",
                    "ss",
                    [$sku_id, $batch_id]
                );

                if ($existing_inventory) {
                    // Update existing inventory
                    $new_qty = $existing_inventory['qty_on_hand'] + $qty;

                    $updated_rows = secure_update($conn, 'inventory',
                        [
                            'qty_on_hand' => $new_qty,
                            'last_updated' => date('Y-m-d H:i:s')
                        ],
                        'id = ?',
                        'i',
                        [$existing_inventory['id']]
                    );

                    if ($updated_rows > 0) {
                        $processed_lines++;
                    }

                } else {
                    // Insert new inventory record
                    $inventory_data = [
                        'sku_id' => $sku_id,
                        'qty_on_hand' => $qty,
                        'qty_allocated' => 0,
                        'batch_id' => $batch_id,
                        'location_id' => 'RCV01', // Default receiving location
                        'condition' => 'OK1', // Default condition
                        'receipt_dstamp' => date('Y-m-d'),
                        'receipt_time' => date('H:i:s'),
                        'last_updated' => date('Y-m-d H:i:s')
                    ];

                    if ($expiry_date) {
                        $inventory_data['expiry_date'] = $expiry_date;
                    }

                    $insert_id = secure_insert($conn, 'inventory', $inventory_data);

                    if ($insert_id > 0) {
                        $processed_lines++;
                    }
                }

                // Log the putaway activity
                WMSSecurity::logActivity($conn, $_SESSION['user'], 'putaway_processed',
                    "ASN: $asn_number, SKU: $sku_id, Qty: $qty, Batch: $batch_id");

            } catch (Exception $e) {
                $errors[] = "Error processing line for SKU {$line['sku_id']}: " . $e->getMessage();
                error_log("Putaway line processing error: " . $e->getMessage());
            }
        }

        // Update ASN header status if all lines processed successfully
        if ($processed_lines === count($asn_lines) && empty($errors)) {
            secure_update($conn, 'asn_header',
                ['status' => 'Completed'],
                'asn_number = ?',
                's',
                [$asn_number]
            );

            $message = "✅ ASN $asn_number processed successfully. $processed_lines lines processed.";
            $message_type = 'success';

        } elseif ($processed_lines > 0) {
            $message = "⚠️ ASN $asn_number partially processed. $processed_lines out of " . count($asn_lines) . " lines processed.";
            $message_type = 'warning';

        } else {
            throw new Exception("No lines were processed for ASN $asn_number");
        }

        // Commit transaction
        $conn->commit();
        $conn->autocommit(true);

        // Log overall putaway completion
        WMSSecurity::logActivity($conn, $_SESSION['user'], 'asn_putaway_completed',
            "ASN: $asn_number, Lines processed: $processed_lines");

    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollback();
        $conn->autocommit(true);

        error_log("Putaway processing error: " . $e->getMessage());
        $message = "❌ Putaway failed: " . $e->getMessage();
        $message_type = 'error';
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Putaway Operations | ECWMS</title>
    <link rel="stylesheet" href="modern-style.css">
    <style>
        .putaway-container {
            max-width: 800px;
            margin: 0 auto;
        }
        .asn-selection {
            background: white;
            padding: 2rem;
            border-radius: var(--border-radius-lg);
            box-shadow: var(--box-shadow);
            margin-bottom: 2rem;
        }
        .asn-list {
            margin-top: 1.5rem;
        }
        .asn-item {
            background: var(--gray-50);
            border: 1px solid var(--gray-200);
            border-radius: var(--border-radius);
            padding: 1rem;
            margin-bottom: 0.5rem;
            cursor: pointer;
            transition: var(--transition);
        }
        .asn-item:hover {
            background: var(--gray-100);
            border-color: var(--primary-blue);
        }
        .asn-item.selected {
            background: rgba(59, 130, 246, 0.1);
            border-color: var(--primary-blue);
        }
        .asn-details {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .asn-info h4 {
            margin: 0 0 0.25rem 0;
            color: var(--gray-900);
        }
        .asn-info p {
            margin: 0;
            color: var(--gray-600);
            font-size: 0.875rem;
        }
        .asn-status {
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 500;
            background: rgba(245, 158, 11, 0.1);
            color: #92400e;
        }
    </style>
</head>
<body class="wms-layout">

<main class="wms-content">
    <div class="putaway-container">
        <!-- Header -->
        <div class="text-center mb-4">
            <h1>📦 Putaway Operations</h1>
            <p class="text-secondary">Process ASN inbound inventory and update stock levels</p>
        </div>

        <!-- Messages -->
        <?php if (!empty($message)): ?>
        <div class="alert alert-<?= $message_type === 'success' ? 'success' : ($message_type === 'warning' ? 'warning' : 'danger') ?>">
            <?= secure_escape($message) ?>
        </div>
        <?php endif; ?>

        <!-- ASN Selection -->
        <div class="asn-selection">
            <h3>Select ASN to Process</h3>

            <?php if (!empty($asn_list)): ?>
            <form method="POST" id="putawayForm" onsubmit="return confirmPutaway();">
                <?= csrf_field() ?>
                <input type="hidden" name="asn_number" id="selectedASN" required>

                <div class="asn-list">
                    <?php foreach ($asn_list as $asn): ?>
                    <div class="asn-item" onclick="selectASN('<?= secure_escape($asn['asn_number']) ?>', this)">
                        <div class="asn-details">
                            <div class="asn-info">
                                <h4>ASN: <?= secure_escape($asn['asn_number']) ?></h4>
                                <p>Supplier: <?= secure_escape($asn['supplier_name']) ?></p>
                                <p>Arrival Date: <?= date('M d, Y', strtotime($asn['arrival_date'])) ?></p>
                            </div>
                            <div class="asn-status">Pending</div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div class="text-center mt-3">
                    <button type="submit" class="btn btn-primary" id="processBtn" disabled>
                        🚚 Process Selected ASN
                    </button>
                </div>
            </form>

            <?php else: ?>
            <div class="text-center">
                <p class="text-secondary">No ASNs available for putaway processing.</p>
                <a href="create_asn.php" class="btn btn-primary">➕ Create New ASN</a>
            </div>
            <?php endif; ?>
        </div>

        <!-- Information Panel -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Putaway Process Information</h3>
            </div>
            <div class="card-body">
                <h4>What happens during putaway:</h4>
                <ul style="margin-left: 1.5rem;">
                    <li>ASN lines are processed and added to inventory</li>
                    <li>Existing inventory quantities are updated if SKU/batch matches</li>
                    <li>New inventory records are created for new SKU/batch combinations</li>
                    <li>ASN status is updated to 'Completed' upon successful processing</li>
                    <li>All activities are logged for audit purposes</li>
                </ul>

                <div class="alert alert-info mt-3">
                    <strong>Note:</strong> This operation cannot be easily undone. Please ensure the ASN data is correct before processing.
                </div>
            </div>
        </div>

        <!-- Navigation -->
        <div class="text-center mt-4">
            <a href="inbound.php" class="btn btn-secondary">⬅️ Back to Inbound</a>
            <a href="secure-dashboard.php" class="btn btn-secondary">🏠 Dashboard</a>
        </div>
    </div>
</main>

<script>
let selectedASNNumber = null;

function selectASN(asnNumber, element) {
    // Clear previous selection
    document.querySelectorAll('.asn-item').forEach(item => {
        item.classList.remove('selected');
    });

    // Select current item
    element.classList.add('selected');
    selectedASNNumber = asnNumber;

    // Update form
    document.getElementById('selectedASN').value = asnNumber;
    document.getElementById('processBtn').disabled = false;
}

function confirmPutaway() {
    if (!selectedASNNumber) {
        alert('Please select an ASN to process.');
        return false;
    }

    const confirmation = confirm(
        `Are you sure you want to process ASN: ${selectedASNNumber}?\n\n` +
        'This will:\n' +
        '- Update inventory quantities\n' +
        '- Mark the ASN as completed\n' +
        '- Create audit log entries\n\n' +
        'This action cannot be easily undone.'
    );

    if (confirmation) {
        // Add loading state
        const submitBtn = document.getElementById('processBtn');
        submitBtn.disabled = true;
        submitBtn.innerHTML = '⏳ Processing...';

        // Disable ASN selection
        document.querySelectorAll('.asn-item').forEach(item => {
            item.style.pointerEvents = 'none';
            item.style.opacity = '0.6';
        });
    }

    return confirmation;
}

// Auto-refresh ASN list every 2 minutes
setInterval(function() {
    if (document.visibilityState === 'visible' && !selectedASNNumber) {
        window.location.reload();
    }
}, 120000);
</script>

</body>
</html>

<?php $conn->close(); ?>
