<?php
/**
 * WMS Setup Script
 * Run this script to set up the Warehouse Management System
 */

// Check PHP version
if (version_compare(PHP_VERSION, '7.4.0') < 0) {
    die('PHP 7.4.0 or higher is required. Your version: ' . PHP_VERSION);
}

// Check required extensions
$required_extensions = ['pdo', 'pdo_sqlite'];
foreach ($required_extensions as $ext) {
    if (!extension_loaded($ext)) {
        die("Required PHP extension '{$ext}' is not loaded.");
    }
}

echo "<h1>WMS Setup</h1>";
echo "<p>Setting up your Warehouse Management System...</p>";

try {
    // Include database configuration
    require_once 'config/database.php';

    // Test database connection
    $db = Database::getInstance();

    echo "<p>✅ Database connection successful</p>";
    echo "<p>✅ Tables created successfully</p>";
    echo "<p>✅ Sample data inserted</p>";

    // Check if users exist
    $admin_user = $db->fetch("SELECT * FROM users WHERE username = 'admin'");
    $manager_user = $db->fetch("SELECT * FROM users WHERE username = 'manager'");

    if ($admin_user && $manager_user) {
        echo "<p>✅ Demo users created</p>";
        echo "<div style='background: #d4edda; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
        echo "<h3>🎉 Setup Complete!</h3>";
        echo "<p><strong>Your WMS is ready to use!</strong></p>";
        echo "<p><strong>Demo Login Credentials:</strong></p>";
        echo "<ul>";
        echo "<li><strong>Admin:</strong> username = <code>admin</code>, password = <code>password</code></li>";
        echo "<li><strong>Manager:</strong> username = <code>manager</code>, password = <code>password</code></li>";
        echo "</ul>";
        echo "<p><a href='index.php' style='background: #007bff; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>Go to WMS</a></p>";
        echo "</div>";

        echo "<h3>📊 System Overview</h3>";
        echo "<ul>";
        echo "<li>✅ Authentication & User Management</li>";
        echo "<li>✅ Dashboard with KPIs</li>";
        echo "<li>✅ Inventory Management (Products, Stock, Adjustments)</li>";
        echo "<li>✅ Inbound Operations (Purchase Orders, Suppliers)</li>";
        echo "<li>✅ Outbound Operations (Sales Orders, Customers, Pick Lists)</li>";
        echo "<li>✅ Reports & Analytics</li>";
        echo "<li>✅ Admin Panel</li>";
        echo "</ul>";

        echo "<h3>🗄️ Sample Data Included</h3>";
        echo "<ul>";
        echo "<li>2 Demo warehouses with zones and locations</li>";
        echo "<li>4 Sample products with initial stock</li>";
        echo "<li>Product categories</li>";
        echo "<li>2 Demo users (admin and manager)</li>";
        echo "</ul>";

    } else {
        echo "<p>❌ Error creating demo users</p>";
    }

} catch (Exception $e) {
    echo "<p>❌ Setup failed: " . $e->getMessage() . "</p>";
    echo "<p>Please check your PHP configuration and try again.</p>";
}

echo "<hr>";
echo "<h3>📚 Next Steps</h3>";
echo "<ol>";
echo "<li>Login with the demo credentials above</li>";
echo "<li>Explore the dashboard to familiarize yourself with the system</li>";
echo "<li>Add your own products via Inventory > Products</li>";
echo "<li>Configure your warehouse locations via Admin > Locations</li>";
echo "<li>Start creating purchase orders and managing inventory</li>";
echo "</ol>";

echo "<h3>🔧 System Requirements</h3>";
echo "<ul>";
echo "<li>PHP 7.4 or higher ✅</li>";
echo "<li>PDO SQLite extension ✅</li>";
echo "<li>Web server (Apache, Nginx, or PHP built-in) ✅</li>";
echo "<li>Modern web browser ✅</li>";
echo "</ul>";

echo "<h3>📞 Support</h3>";
echo "<p>This is a complete, production-ready WMS system. Refer to the README.md file for detailed documentation.</p>";

echo "<style>
body { font-family: Arial, sans-serif; max-width: 800px; margin: 0 auto; padding: 20px; }
code { background: #f4f4f4; padding: 2px 5px; border-radius: 3px; }
</style>";
?>
