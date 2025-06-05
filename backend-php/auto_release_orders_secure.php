<?php
/**
 * Secure Auto-Release Orders Script
 * Automatically releases 'Hold' orders if sufficient inventory is available
 * This script should be run via cron job or scheduled task
 */

require_once 'security-utils.php';
include 'db_config.php';

// Set security headers
setSecurityHeaders();

// Determine if running from command line or web
$is_cli = php_sapi_name() === 'cli';
$is_authorized = false;

if ($is_cli) {
    // CLI execution is authorized
    $is_authorized = true;
    $execution_user = 'system_cron';
} else {
    // Web execution requires authentication and admin role
    require 'auth.php';
    if (isset($_SESSION['user']) && $_SESSION['role'] === 'admin') {
        $is_authorized = true;
        $execution_user = $_SESSION['user'];

        // Additional security for web execution
        if (!isset($_GET['confirm']) || $_GET['confirm'] !== 'auto_release') {
            handleSecurityError('Unauthorized auto-release attempt');
        }

        // Rate limiting for manual web execution
        try {
            WMSSecurity::checkRateLimit('auto_release_' . $_SESSION['user'], 3, 3600);
        } catch (Exception $e) {
            handleSecurityError('Auto-release rate limit exceeded');
        }
    }
}

if (!$is_authorized) {
    if (!$is_cli) {
        handleSecurityError('Unauthorized access to auto-release system');
    } else {
        error_log("Auto-release: Unauthorized CLI execution attempt");
        exit(1);
    }
}

// Initialize counters
$processed_orders = 0;
$released_orders = 0;
$errors = [];
$start_time = microtime(true);

try {
    // Start transaction for data integrity
    $conn->autocommit(false);

    // Fetch orders on hold with available inventory
    $hold_orders = secure_select_all($conn,
        "SELECT o.id, o.order_number, o.sku, o.qty_ordered, o.client_id,
                i.id AS inventory_id, i.qty_on_hand, i.qty_allocated,
                (i.qty_on_hand - i.qty_allocated) AS qty_available
         FROM outbound_orders o
         JOIN inventory i ON o.sku = i.sku_id
         WHERE o.status = 'HOLD'
         AND (i.qty_on_hand - i.qty_allocated) >= o.qty_ordered
         ORDER BY o.created_at ASC"
    );

    if (!$is_cli) {
        echo "<h2>🔄 Auto-Release Orders Process</h2>";
        echo "<p>Found " . count($hold_orders) . " orders eligible for release.</p>";
    }

    foreach ($hold_orders as $order) {
        $processed_orders++;

        try {
            // Validate order data
            $order_id = WMSSecurity::validateInteger($order['id'], 1);
            $qty_ordered = WMSSecurity::validateInteger($order['qty_ordered'], 1);
            $inventory_id = WMSSecurity::validateInteger($order['inventory_id'], 1);

            // Double-check inventory availability (race condition protection)
            $current_inventory = secure_select_one($conn,
                "SELECT qty_on_hand, qty_allocated FROM inventory WHERE id = ?",
                "i",
                [$inventory_id]
            );

            if (!$current_inventory) {
                throw new Exception("Inventory record not found");
            }

            $current_available = $current_inventory['qty_on_hand'] - $current_inventory['qty_allocated'];

            if ($current_available < $qty_ordered) {
                throw new Exception("Insufficient inventory after recheck. Available: $current_available, Required: $qty_ordered");
            }

            // Update order status to Released
            $order_updated = secure_update($conn, 'outbound_orders',
                ['status' => 'RELEASED'],
                'id = ?',
                'i',
                [$order_id]
            );

            if ($order_updated === 0) {
                throw new Exception("Failed to update order status");
            }

            // Log the auto-release activity
            WMSSecurity::logActivity($conn, $execution_user, 'order_auto_released',
                "Order ID: {$order['id']}, Number: {$order['order_number']}, " .
                "SKU: {$order['sku']}, Qty: {$order['qty_ordered']}, " .
                "Available: $current_available");

            $released_orders++;

            if (!$is_cli) {
                echo "<div class='alert alert-success'>✅ Released order #{$order['order_number']} (SKU: {$order['sku']}, Qty: {$order['qty_ordered']})</div>";
            }

        } catch (Exception $e) {
            $error_msg = "Failed to release order {$order['order_number']}: " . $e->getMessage();
            $errors[] = $error_msg;
            error_log("Auto-release error: $error_msg");

            if (!$is_cli) {
                echo "<div class='alert alert-warning'>⚠️ $error_msg</div>";
            }
        }
    }

    // Commit all changes
    $conn->commit();
    $conn->autocommit(true);

    $execution_time = round((microtime(true) - $start_time) * 1000, 2);

    // Log execution summary
    WMSSecurity::logActivity($conn, $execution_user, 'auto_release_completed',
        "Processed: $processed_orders, Released: $released_orders, Errors: " . count($errors) .
        ", Time: {$execution_time}ms");

    // Prepare summary
    $summary = [
        'timestamp' => date('Y-m-d H:i:s'),
        'processed_orders' => $processed_orders,
        'released_orders' => $released_orders,
        'error_count' => count($errors),
        'execution_time_ms' => $execution_time,
        'success_rate' => $processed_orders > 0 ? round(($released_orders / $processed_orders) * 100, 2) : 0
    ];

    if ($is_cli) {
        // CLI output
        echo "Auto-Release Summary:\n";
        echo "Timestamp: {$summary['timestamp']}\n";
        echo "Orders Processed: {$summary['processed_orders']}\n";
        echo "Orders Released: {$summary['released_orders']}\n";
        echo "Errors: {$summary['error_count']}\n";
        echo "Success Rate: {$summary['success_rate']}%\n";
        echo "Execution Time: {$summary['execution_time_ms']}ms\n";

        if (!empty($errors)) {
            echo "\nErrors:\n";
            foreach ($errors as $error) {
                echo "- $error\n";
            }
        }

        exit(0);
    } else {
        // Web output
        echo "<div class='card mt-4'>";
        echo "<div class='card-header'><h3>📊 Execution Summary</h3></div>";
        echo "<div class='card-body'>";
        echo "<div class='row'>";
        echo "<div class='col-md-3'><strong>Orders Processed:</strong><br><span class='text-primary'>{$summary['processed_orders']}</span></div>";
        echo "<div class='col-md-3'><strong>Orders Released:</strong><br><span class='text-success'>{$summary['released_orders']}</span></div>";
        echo "<div class='col-md-3'><strong>Errors:</strong><br><span class='text-danger'>{$summary['error_count']}</span></div>";
        echo "<div class='col-md-3'><strong>Success Rate:</strong><br><span class='text-info'>{$summary['success_rate']}%</span></div>";
        echo "</div>";
        echo "<div class='mt-3'><small>Execution Time: {$summary['execution_time_ms']}ms</small></div>";
        echo "</div></div>";

        if (!empty($errors)) {
            echo "<div class='card mt-3'>";
            echo "<div class='card-header'><h4>❌ Errors Encountered</h4></div>";
            echo "<div class='card-body'>";
            echo "<ul>";
            foreach ($errors as $error) {
                echo "<li>" . secure_escape($error) . "</li>";
            }
            echo "</ul>";
            echo "</div></div>";
        }

        echo "<div class='text-center mt-4'>";
        echo "<a href='view_outbound.php' class='btn btn-primary'>📋 View Orders</a>";
        echo "<a href='secure-dashboard.php' class='btn btn-secondary'>🏠 Dashboard</a>";
        echo "</div>";
    }

} catch (Exception $e) {
    // Rollback on major error
    $conn->rollback();
    $conn->autocommit(true);

    $error_msg = "Critical auto-release error: " . $e->getMessage();
    error_log($error_msg);

    // Log critical error
    WMSSecurity::logActivity($conn, $execution_user, 'auto_release_failed', $error_msg);

    if ($is_cli) {
        echo "CRITICAL ERROR: " . $e->getMessage() . "\n";
        exit(1);
    } else {
        echo "<div class='alert alert-danger'>";
        echo "<h4>❌ Critical Error</h4>";
        echo "<p>Auto-release process failed: " . secure_escape($e->getMessage()) . "</p>";
        echo "</div>";
    }
}

$conn->close();

// HTML template for web execution
if (!$is_cli): ?>
<!DOCTYPE html>
<html>
<head>
    <title>Auto-Release Orders | ECWMS</title>
    <link rel="stylesheet" href="modern-style.css">
    <style>
        .execution-log {
            max-width: 1000px;
            margin: 2rem auto;
            background: white;
            padding: 2rem;
            border-radius: var(--border-radius-lg);
            box-shadow: var(--box-shadow);
        }
    </style>
</head>
<body class="wms-layout">
<main class="wms-content">
    <div class="execution-log">
        <!-- Content already echoed above -->
    </div>
</main>
</body>
</html>
<?php endif; ?>
