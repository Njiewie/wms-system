<?php
require_once 'security-utils.php';
require 'auth.php';
require_login();
include 'db_config.php';

// Set security headers
setSecurityHeaders();

// Validate CSRF token
try {
    validate_csrf();
} catch (Exception $e) {
    handleSecurityError('Invalid security token');
}

// Check if we have tag IDs to delete
if (!isset($_POST['tag_ids']) || empty($_POST['tag_ids'])) {
    if (isset($_POST['item_id']) && !empty($_POST['item_id'])) {
        // Single item deletion (legacy support)
        $tag_ids = [$_POST['item_id']];
    } else {
        handleSecurityError('No items selected for deletion');
    }
} else {
    // Multiple item deletion
    $tag_ids = $_POST['tag_ids'];
}

// Validate all tag IDs
$validated_tag_ids = [];
foreach ($tag_ids as $tag_id) {
    try {
        $clean_tag_id = WMSSecurity::sanitizeString($tag_id, 50);
        if (!empty($clean_tag_id)) {
            $validated_tag_ids[] = $clean_tag_id;
        }
    } catch (Exception $e) {
        // Skip invalid tag IDs
        continue;
    }
}

if (empty($validated_tag_ids)) {
    handleSecurityError('No valid items selected for deletion');
}

// Rate limiting for bulk operations
if (count($validated_tag_ids) > 10) {
    try {
        WMSSecurity::checkRateLimit('bulk_delete_' . $_SESSION['user'], 3, 3600);
    } catch (Exception $e) {
        handleSecurityError('Bulk deletion rate limit exceeded');
    }
}

$deleted_count = 0;
$errors = [];

try {
    // Start transaction for data integrity
    $conn->autocommit(false);

    foreach ($validated_tag_ids as $tag_id) {
        try {
            // Check if item exists and get details for logging
            $item = secure_select_one($conn,
                "SELECT tag_id, sku_id, qty_on_hand, location_id FROM inventory WHERE tag_id = ?",
                "s",
                [$tag_id]
            );

            if (!$item) {
                $errors[] = "Item with tag ID '$tag_id' not found";
                continue;
            }

            // Check if item has allocated quantity
            if ($item['qty_on_hand'] > 0) {
                // You might want to check for allocations before deletion
                $allocated_qty = secure_select_one($conn,
                    "SELECT qty_allocated FROM inventory WHERE tag_id = ?",
                    "s",
                    [$tag_id]
                );

                if ($allocated_qty && $allocated_qty['qty_allocated'] > 0) {
                    $errors[] = "Cannot delete item '$tag_id' - has allocated quantity";
                    continue;
                }
            }

            // Perform secure deletion
            $deleted_rows = secure_delete($conn, 'inventory', 'tag_id = ?', 's', [$tag_id]);

            if ($deleted_rows > 0) {
                $deleted_count++;

                // Log the deletion
                WMSSecurity::logActivity($conn, $_SESSION['user'], 'inventory_deleted',
                    "Deleted inventory - Tag ID: {$item['tag_id']}, SKU: {$item['sku_id']}, Location: {$item['location_id']}");
            } else {
                $errors[] = "Failed to delete item with tag ID '$tag_id'";
            }

        } catch (Exception $e) {
            $errors[] = "Error deleting item '$tag_id': " . $e->getMessage();
            error_log("Inventory deletion error for tag_id $tag_id: " . $e->getMessage());
        }
    }

    // Commit transaction
    $conn->commit();
    $conn->autocommit(true);

} catch (Exception $e) {
    // Rollback on error
    $conn->rollback();
    $conn->autocommit(true);
    error_log("Inventory deletion transaction error: " . $e->getMessage());
    handleSecurityError('Deletion failed due to system error');
}

// Prepare response
$response = [];
if ($deleted_count > 0) {
    $response[] = "✅ Successfully deleted $deleted_count item(s)";
}

if (!empty($errors)) {
    $response[] = "⚠️ Some items could not be deleted:";
    $response = array_merge($response, $errors);
}

if ($deleted_count === 0 && !empty($errors)) {
    $response = ["❌ No items were deleted"] + $response;
}

// Check if this is an AJAX request
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {

    header('Content-Type: application/json');
    echo json_encode([
        'success' => $deleted_count > 0,
        'deleted_count' => $deleted_count,
        'errors' => $errors,
        'message' => implode('<br>', $response)
    ]);
    exit;
}

// Regular HTTP response
?>
<!DOCTYPE html>
<html>
<head>
    <title>Deletion Result | ECWMS</title>
    <link rel="stylesheet" href="modern-style.css">
    <style>
        .result-container {
            max-width: 600px;
            margin: 3rem auto;
            background: white;
            padding: 2rem;
            border-radius: var(--border-radius-lg);
            box-shadow: var(--box-shadow);
            text-align: center;
        }
        .result-message {
            margin: 1rem 0;
            padding: 1rem;
            border-radius: var(--border-radius);
        }
        .result-success {
            background: rgba(34, 197, 94, 0.1);
            border: 1px solid rgba(34, 197, 94, 0.2);
            color: #166534;
        }
        .result-error {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.2);
            color: #991b1b;
        }
        .result-warning {
            background: rgba(245, 158, 11, 0.1);
            border: 1px solid rgba(245, 158, 11, 0.2);
            color: #92400e;
        }
        .error-list {
            text-align: left;
            margin-top: 1rem;
        }
        .error-list ul {
            margin: 0;
            padding-left: 1.5rem;
        }
    </style>
</head>
<body class="wms-layout">

<main class="wms-content">
    <div class="result-container">
        <h2>Deletion Results</h2>

        <?php if ($deleted_count > 0): ?>
        <div class="result-message result-success">
            ✅ Successfully deleted <?= $deleted_count ?> inventory item(s)
        </div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
        <div class="result-message result-<?= $deleted_count > 0 ? 'warning' : 'error' ?>">
            <?= $deleted_count > 0 ? '⚠️ Some items could not be deleted:' : '❌ No items were deleted:' ?>
            <div class="error-list">
                <ul>
                    <?php foreach ($errors as $error): ?>
                    <li><?= secure_escape($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
        <?php endif; ?>

        <div class="mt-4">
            <a href="secure-inventory.php" class="btn btn-primary">⬅️ Return to Inventory</a>
            <a href="secure-dashboard.php" class="btn btn-secondary">🏠 Dashboard</a>
        </div>
    </div>
</main>

<script>
// Auto-redirect after 5 seconds if successful
<?php if ($deleted_count > 0 && empty($errors)): ?>
setTimeout(function() {
    window.location.href = 'secure-inventory.php';
}, 5000);
<?php endif; ?>
</script>

</body>
</html>

<?php $conn->close(); ?>
