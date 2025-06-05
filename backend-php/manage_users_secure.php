<?php
/**
 * Secure User Management System
 * Enhanced with comprehensive security measures
 *
 * Security Features:
 * - CSRF Protection
 * - SQL Injection Prevention
 * - Input Validation & Sanitization
 * - XSS Prevention
 * - Password Security (bcrypt)
 * - Role-based Access Control
 * - Activity Logging
 * - Rate Limiting
 */

// Start session and include security utilities
session_start();
require_once 'security-utils.php';
require_once 'auth.php';
require_once 'db_config.php';

// Require login and admin privileges
require_login();
$security = SecurityUtils::getInstance($conn);
$security->setSecurityHeaders();

// Check admin access
if ($_SESSION['role'] !== 'admin') {
    $security->logSecurityEvent($_SESSION['user_id'], 'unauthorized_user_management_access',
        'Non-admin user attempted to access user management');
    header('Location: secure-dashboard.php?error=' . urlencode('Access denied: Admin privileges required'));
    exit;
}

// Initialize variables
$message = "";
$errors = [];
$users = [];
$edit_user = null;

// Generate CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = $security->generateCSRFToken();
}

/**
 * Validate user input data
 */
function validateUserInput($data, $is_edit = false, &$errors) {
    // Username validation
    if (empty($data['username'])) {
        $errors['username'] = "Username is required";
    } elseif (!preg_match('/^[A-Za-z0-9_]{3,30}$/', $data['username'])) {
        $errors['username'] = "Username must be 3-30 characters, letters, numbers, and underscores only";
    }

    // Password validation (required for new users, optional for edits)
    if (!$is_edit || !empty($data['password'])) {
        if (empty($data['password'])) {
            $errors['password'] = "Password is required";
        } elseif (strlen($data['password']) < 8) {
            $errors['password'] = "Password must be at least 8 characters";
        } elseif (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)/', $data['password'])) {
            $errors['password'] = "Password must contain uppercase, lowercase, and number";
        }

        // Confirm password
        if ($data['password'] !== $data['confirm_password']) {
            $errors['confirm_password'] = "Passwords do not match";
        }
    }

    // Email validation
    if (!empty($data['email'])) {
        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = "Invalid email format";
        } elseif (strlen($data['email']) > 100) {
            $errors['email'] = "Email is too long (max 100 characters)";
        }
    }

    // First name validation
    if (!empty($data['first_name'])) {
        if (strlen($data['first_name']) > 50) {
            $errors['first_name'] = "First name is too long (max 50 characters)";
        } elseif (!preg_match('/^[a-zA-Z\s\-\']+$/', $data['first_name'])) {
            $errors['first_name'] = "First name contains invalid characters";
        }
    }

    // Last name validation
    if (!empty($data['last_name'])) {
        if (strlen($data['last_name']) > 50) {
            $errors['last_name'] = "Last name is too long (max 50 characters)";
        } elseif (!preg_match('/^[a-zA-Z\s\-\']+$/', $data['last_name'])) {
            $errors['last_name'] = "Last name contains invalid characters";
        }
    }

    // Role validation
    $allowed_roles = ['admin', 'manager', 'user', 'viewer'];
    if (empty($data['role'])) {
        $errors['role'] = "Role is required";
    } elseif (!in_array($data['role'], $allowed_roles)) {
        $errors['role'] = "Invalid role selected";
    }

    return empty($errors);
}

/**
 * Check if username exists (excluding current user in edit mode)
 */
function usernameExists($conn, $username, $exclude_id = null) {
    $query = "SELECT id FROM users WHERE username = ?";
    $params = [$username];
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
 * Check if email exists (excluding current user in edit mode)
 */
function emailExists($conn, $email, $exclude_id = null) {
    if (empty($email)) return false;

    $query = "SELECT id FROM users WHERE email = ?";
    $params = [$email];
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
 * Create new user
 */
function createUser($conn, $data, $user_id) {
    // Hash password securely
    $data['password'] = password_hash($data['password'], PASSWORD_ARGON2ID, [
        'memory_cost' => 65536,
        'time_cost' => 4,
        'threads' => 3
    ]);

    // Remove confirm_password from data
    unset($data['confirm_password']);

    // Add audit fields
    $data['created_by'] = $user_id;
    $data['created_at'] = date('Y-m-d H:i:s');
    $data['updated_at'] = date('Y-m-d H:i:s');

    return secure_insert($conn, 'users', $data);
}

/**
 * Update existing user
 */
function updateUser($conn, $user_id, $data, $updated_by) {
    // Hash password if provided
    if (!empty($data['password'])) {
        $data['password'] = password_hash($data['password'], PASSWORD_ARGON2ID, [
            'memory_cost' => 65536,
            'time_cost' => 4,
            'threads' => 3
        ]);
    } else {
        unset($data['password']);
    }

    // Remove confirm_password from data
    unset($data['confirm_password']);

    // Add audit fields
    $data['updated_by'] = $updated_by;
    $data['updated_at'] = date('Y-m-d H:i:s');

    return secure_update($conn, 'users', $data, 'id = ?', 'i', [$user_id]);
}

/**
 * Soft delete user
 */
function deleteUser($conn, $user_id, $deleted_by) {
    // Prevent self-deletion
    if ($user_id == $deleted_by) {
        throw new Exception('You cannot delete your own account');
    }

    // Prevent deletion of last admin
    $admin_count = secure_select_one($conn,
        "SELECT COUNT(*) as count FROM users WHERE role = 'admin' AND deleted_at IS NULL"
    );

    $user_role = secure_select_one($conn,
        "SELECT role FROM users WHERE id = ?", "i", [$user_id]
    );

    if ($user_role['role'] === 'admin' && $admin_count['count'] <= 1) {
        throw new Exception('Cannot delete the last admin user');
    }

    return secure_update($conn, 'users',
        [
            'deleted_at' => date('Y-m-d H:i:s'),
            'deleted_by' => $deleted_by
        ],
        'id = ? AND deleted_at IS NULL',
        'i',
        [$user_id]
    );
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Verify CSRF token
        if (!$security->validateCSRFToken($_POST['csrf_token'] ?? '')) {
            throw new Exception("Invalid security token. Please refresh the page and try again.");
        }

        // Rate limiting
        if (!$security->checkRateLimit($_SESSION['user_id'], 'user_management', 10, 300)) {
            throw new Exception("Too many requests. Please wait before performing another action.");
        }

        $action = $_POST['action'] ?? '';

        // Sanitize input data
        $input_data = [
            'username' => $security->sanitizeInput($_POST['username'] ?? ''),
            'password' => $_POST['password'] ?? '',
            'confirm_password' => $_POST['confirm_password'] ?? '',
            'email' => $security->sanitizeInput($_POST['email'] ?? ''),
            'first_name' => $security->sanitizeInput($_POST['first_name'] ?? ''),
            'last_name' => $security->sanitizeInput($_POST['last_name'] ?? ''),
            'role' => $security->sanitizeInput($_POST['role'] ?? '')
        ];

        switch ($action) {
            case 'create':
                // Validate input
                if (!validateUserInput($input_data, false, $errors)) {
                    throw new Exception("Please correct the validation errors below.");
                }

                // Check for existing username/email
                if (usernameExists($conn, $input_data['username'])) {
                    $errors['username'] = "Username already exists";
                    throw new Exception("Username is already taken.");
                }

                if (!empty($input_data['email']) && emailExists($conn, $input_data['email'])) {
                    $errors['email'] = "Email already exists";
                    throw new Exception("Email is already registered.");
                }

                // Create user
                $new_user_id = createUser($conn, $input_data, $_SESSION['user_id']);

                if ($new_user_id) {
                    // Log activity
                    $security->logActivity($_SESSION['user_id'], 'USER_CREATED',
                        "New user created: {$input_data['username']}, Role: {$input_data['role']}");

                    $message = "✅ User created successfully!<br>
                               <strong>Username:</strong> " . htmlspecialchars($input_data['username']) . "<br>
                               <strong>Role:</strong> " . htmlspecialchars($input_data['role']);

                    // Clear form data
                    $input_data = array_fill_keys(array_keys($input_data), '');
                } else {
                    throw new Exception("Failed to create user");
                }
                break;

            case 'update':
                $user_id = (int)($_POST['user_id'] ?? 0);

                if ($user_id <= 0) {
                    throw new Exception("Invalid user ID");
                }

                // Validate input
                if (!validateUserInput($input_data, true, $errors)) {
                    throw new Exception("Please correct the validation errors below.");
                }

                // Check for existing username/email (excluding current user)
                if (usernameExists($conn, $input_data['username'], $user_id)) {
                    $errors['username'] = "Username already exists";
                    throw new Exception("Username is already taken.");
                }

                if (!empty($input_data['email']) && emailExists($conn, $input_data['email'], $user_id)) {
                    $errors['email'] = "Email already exists";
                    throw new Exception("Email is already registered.");
                }

                // Update user
                $updated = updateUser($conn, $user_id, $input_data, $_SESSION['user_id']);

                if ($updated) {
                    // Log activity
                    $security->logActivity($_SESSION['user_id'], 'USER_UPDATED',
                        "User updated: {$input_data['username']}, ID: $user_id");

                    $message = "✅ User updated successfully!<br>
                               <strong>Username:</strong> " . htmlspecialchars($input_data['username']);

                    // Clear edit mode
                    $edit_user = null;
                } else {
                    throw new Exception("Failed to update user or no changes made");
                }
                break;

            case 'delete':
                $user_id = (int)($_POST['user_id'] ?? 0);

                if ($user_id <= 0) {
                    throw new Exception("Invalid user ID");
                }

                // Get user info for logging
                $user_info = secure_select_one($conn,
                    "SELECT username FROM users WHERE id = ?", "i", [$user_id]);

                if (!$user_info) {
                    throw new Exception("User not found");
                }

                // Delete user
                $deleted = deleteUser($conn, $user_id, $_SESSION['user_id']);

                if ($deleted) {
                    // Log activity
                    $security->logActivity($_SESSION['user_id'], 'USER_DELETED',
                        "User deleted: {$user_info['username']}, ID: $user_id");

                    $message = "✅ User deleted successfully!<br>
                               <strong>Username:</strong> " . htmlspecialchars($user_info['username']);
                } else {
                    throw new Exception("Failed to delete user");
                }
                break;

            default:
                throw new Exception("Invalid action");
        }

    } catch (Exception $e) {
        $message = "❌ Error: " . htmlspecialchars($e->getMessage());

        // Log security events
        if (strpos($e->getMessage(), 'security token') !== false ||
            strpos($e->getMessage(), 'Too many requests') !== false) {

            $security->logSecurityEvent($_SESSION['user_id'], 'user_management_security_violation', $e->getMessage());
        }

        error_log("User Management Error: " . $e->getMessage() . " | User: " . $_SESSION['user_id'] . " | IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    }
}

// Handle edit request
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $edit_user = secure_select_one($conn,
        "SELECT * FROM users WHERE id = ? AND deleted_at IS NULL",
        "i",
        [(int)$_GET['edit']]
    );

    if ($edit_user) {
        $input_data = [
            'username' => $edit_user['username'],
            'password' => '',
            'confirm_password' => '',
            'email' => $edit_user['email'] ?? '',
            'first_name' => $edit_user['first_name'] ?? '',
            'last_name' => $edit_user['last_name'] ?? '',
            'role' => $edit_user['role']
        ];
    }
}

// Load all users
try {
    $users = secure_select_all($conn,
        "SELECT u.*,
                creator.username as created_by_name,
                updater.username as updated_by_name
         FROM users u
         LEFT JOIN users creator ON u.created_by = creator.id
         LEFT JOIN users updater ON u.updated_by = updater.id
         WHERE u.deleted_at IS NULL
         ORDER BY u.created_at DESC"
    );
} catch (Exception $e) {
    error_log("Failed to load users: " . $e->getMessage());
    $users = [];
}

// Set default values if not set
if (!isset($input_data)) {
    $input_data = [
        'username' => '',
        'password' => '',
        'confirm_password' => '',
        'email' => '',
        'first_name' => '',
        'last_name' => '',
        'role' => 'user'
    ];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="User Management - Secure WMS">
    <title>User Management | Secure WMS</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="modern-style.css">
    <style>
        .management-container {
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

        .content-grid {
            display: grid;
            grid-template-columns: 1fr 2fr;
            gap: 2rem;
        }

        .form-section {
            background: white;
            padding: 2rem;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            height: fit-content;
        }

        .users-section {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            overflow: hidden;
        }

        .section-header {
            background: #f8fafc;
            padding: 1.5rem;
            border-bottom: 1px solid #e2e8f0;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
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

        .users-table {
            width: 100%;
            border-collapse: collapse;
        }

        .users-table th {
            background: #f8fafc;
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            color: #374151;
            border-bottom: 2px solid #e2e8f0;
        }

        .users-table td {
            padding: 1rem;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: top;
        }

        .users-table tbody tr:hover {
            background: #f8fafc;
        }

        .role-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 500;
            text-transform: uppercase;
        }

        .role-admin { background: #fee2e2; color: #991b1b; }
        .role-manager { background: #dbeafe; color: #1e40af; }
        .role-user { background: #d1fae5; color: #065f46; }
        .role-viewer { background: #f3f4f6; color: #374151; }

        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 6px;
            font-weight: 500;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
            transition: all 0.2s;
            font-size: 0.875rem;
        }

        .btn-primary {
            background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(79, 70, 229, 0.4);
        }

        .btn-secondary {
            background: #f3f4f6;
            color: #374151;
        }

        .btn-secondary:hover {
            background: #e5e7eb;
        }

        .btn-warning {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            color: white;
        }

        .btn-danger {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            color: white;
        }

        .btn-success {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
        }

        .action-buttons {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
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

        .password-strength {
            margin-top: 0.5rem;
        }

        .strength-bar {
            height: 4px;
            background: #e2e8f0;
            border-radius: 2px;
            overflow: hidden;
        }

        .strength-fill {
            height: 100%;
            transition: width 0.3s, background-color 0.3s;
        }

        .strength-weak { background: #ef4444; }
        .strength-medium { background: #f59e0b; }
        .strength-strong { background: #10b981; }

        .user-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: 12px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            text-align: center;
        }

        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            color: #4f46e5;
        }

        .stat-label {
            color: #6b7280;
            font-size: 0.875rem;
        }

        @media (max-width: 1024px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .management-container {
                padding: 1rem;
            }

            .page-header {
                padding: 1rem;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .users-table {
                font-size: 0.875rem;
            }

            .users-table th,
            .users-table td {
                padding: 0.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="management-container">
        <!-- Page Header -->
        <div class="page-header">
            <h1 style="margin: 0; font-size: 2.5rem;">👥 User Management</h1>
            <p style="margin: 0.5rem 0 0; font-size: 1.25rem; opacity: 0.9;">
                Manage system users and access control
            </p>
        </div>

        <!-- Messages -->
        <?php if ($message): ?>
            <div class="alert <?= strpos($message, '✅') !== false ? 'alert-success' : 'alert-error' ?>">
                <?= $message ?>
            </div>
        <?php endif; ?>

        <!-- User Statistics -->
        <div class="user-stats">
            <?php
            $role_counts = [];
            foreach ($users as $user) {
                $role_counts[$user['role']] = ($role_counts[$user['role']] ?? 0) + 1;
            }
            ?>
            <div class="stat-card">
                <div class="stat-number"><?= count($users) ?></div>
                <div class="stat-label">Total Users</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= $role_counts['admin'] ?? 0 ?></div>
                <div class="stat-label">Administrators</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= $role_counts['manager'] ?? 0 ?></div>
                <div class="stat-label">Managers</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= $role_counts['user'] ?? 0 ?></div>
                <div class="stat-label">Users</div>
            </div>
        </div>

        <!-- Main Content Grid -->
        <div class="content-grid">
            <!-- User Form -->
            <div class="form-section">
                <h2 style="margin-top: 0; color: #374151;">
                    <?= $edit_user ? '✏️ Edit User' : '➕ Create New User' ?>
                </h2>

                <form method="POST" id="userForm" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="<?= $edit_user ? 'update' : 'create' ?>">
                    <?php if ($edit_user): ?>
                        <input type="hidden" name="user_id" value="<?= $edit_user['id'] ?>">
                    <?php endif; ?>

                    <div class="form-grid">
                        <div class="form-group">
                            <label for="username" class="form-label">Username <span class="required">*</span></label>
                            <input type="text"
                                   id="username"
                                   name="username"
                                   class="form-control"
                                   value="<?= htmlspecialchars($input_data['username']) ?>"
                                   maxlength="30"
                                   pattern="[A-Za-z0-9_]{3,30}"
                                   required>
                            <div class="help-text">3-30 characters, letters, numbers, and underscores only</div>
                            <?php if (isset($errors['username'])): ?>
                                <div class="error-message"><?= htmlspecialchars($errors['username']) ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="form-group">
                            <label for="role" class="form-label">Role <span class="required">*</span></label>
                            <select id="role" name="role" class="form-control" required>
                                <option value="">Select role...</option>
                                <option value="admin" <?= $input_data['role'] === 'admin' ? 'selected' : '' ?>>Administrator</option>
                                <option value="manager" <?= $input_data['role'] === 'manager' ? 'selected' : '' ?>>Manager</option>
                                <option value="user" <?= $input_data['role'] === 'user' ? 'selected' : '' ?>>User</option>
                                <option value="viewer" <?= $input_data['role'] === 'viewer' ? 'selected' : '' ?>>Viewer</option>
                            </select>
                            <div class="help-text">User access level and permissions</div>
                            <?php if (isset($errors['role'])): ?>
                                <div class="error-message"><?= htmlspecialchars($errors['role']) ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="form-group">
                            <label for="password" class="form-label">
                                Password <?= $edit_user ? '' : '<span class="required">*</span>' ?>
                            </label>
                            <input type="password"
                                   id="password"
                                   name="password"
                                   class="form-control"
                                   minlength="8"
                                   <?= $edit_user ? '' : 'required' ?>>
                            <div class="help-text">
                                <?= $edit_user ? 'Leave blank to keep current password' : 'Minimum 8 characters with uppercase, lowercase, and number' ?>
                            </div>
                            <div class="password-strength" id="passwordStrength" style="display: none;">
                                <div class="strength-bar">
                                    <div class="strength-fill" id="strengthFill"></div>
                                </div>
                                <div class="help-text" id="strengthText"></div>
                            </div>
                            <?php if (isset($errors['password'])): ?>
                                <div class="error-message"><?= htmlspecialchars($errors['password']) ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="form-group">
                            <label for="confirm_password" class="form-label">
                                Confirm Password <?= $edit_user ? '' : '<span class="required">*</span>' ?>
                            </label>
                            <input type="password"
                                   id="confirm_password"
                                   name="confirm_password"
                                   class="form-control"
                                   <?= $edit_user ? '' : 'required' ?>>
                            <div class="help-text">Re-enter the password</div>
                            <?php if (isset($errors['confirm_password'])): ?>
                                <div class="error-message"><?= htmlspecialchars($errors['confirm_password']) ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="form-group">
                            <label for="email" class="form-label">Email</label>
                            <input type="email"
                                   id="email"
                                   name="email"
                                   class="form-control"
                                   value="<?= htmlspecialchars($input_data['email']) ?>"
                                   maxlength="100">
                            <div class="help-text">Optional email address</div>
                            <?php if (isset($errors['email'])): ?>
                                <div class="error-message"><?= htmlspecialchars($errors['email']) ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="form-group">
                            <label for="first_name" class="form-label">First Name</label>
                            <input type="text"
                                   id="first_name"
                                   name="first_name"
                                   class="form-control"
                                   value="<?= htmlspecialchars($input_data['first_name']) ?>"
                                   maxlength="50">
                            <div class="help-text">Optional first name</div>
                            <?php if (isset($errors['first_name'])): ?>
                                <div class="error-message"><?= htmlspecialchars($errors['first_name']) ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="form-group">
                            <label for="last_name" class="form-label">Last Name</label>
                            <input type="text"
                                   id="last_name"
                                   name="last_name"
                                   class="form-control"
                                   value="<?= htmlspecialchars($input_data['last_name']) ?>"
                                   maxlength="50">
                            <div class="help-text">Optional last name</div>
                            <?php if (isset($errors['last_name'])): ?>
                                <div class="error-message"><?= htmlspecialchars($errors['last_name']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div style="margin-top: 2rem; display: flex; gap: 1rem;">
                        <button type="submit" class="btn btn-primary" id="submitBtn">
                            <?= $edit_user ? '💾 Update User' : '➕ Create User' ?>
                        </button>
                        <?php if ($edit_user): ?>
                            <a href="manage_users_secure.php" class="btn btn-secondary">
                                ❌ Cancel Edit
                            </a>
                        <?php endif; ?>
                        <a href="secure-dashboard.php" class="btn btn-secondary">
                            ⬅️ Back to Dashboard
                        </a>
                    </div>
                </form>
            </div>

            <!-- Users List -->
            <div class="users-section">
                <div class="section-header">
                    <h2 style="margin: 0; color: #374151;">System Users</h2>
                    <p style="margin: 0.5rem 0 0; color: #6b7280;">
                        <?= count($users) ?> user(s) registered
                    </p>
                </div>

                <?php if (!empty($users)): ?>
                    <div style="overflow-x: auto;">
                        <table class="users-table">
                            <thead>
                                <tr>
                                    <th>User</th>
                                    <th>Role</th>
                                    <th>Email</th>
                                    <th>Created</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($users as $user): ?>
                                    <tr>
                                        <td>
                                            <strong><?= htmlspecialchars($user['username']) ?></strong>
                                            <?php if ($user['first_name'] || $user['last_name']): ?>
                                                <br><small style="color: #6b7280;">
                                                    <?= htmlspecialchars(trim($user['first_name'] . ' ' . $user['last_name'])) ?>
                                                </small>
                                            <?php endif; ?>
                                            <?php if ($user['id'] == $_SESSION['user_id']): ?>
                                                <br><small style="color: #059669; font-weight: 600;">(You)</small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="role-badge role-<?= $user['role'] ?>">
                                                <?= htmlspecialchars($user['role']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?= $user['email'] ? htmlspecialchars($user['email']) : '<em>Not set</em>' ?>
                                        </td>
                                        <td>
                                            <?= date('M d, Y', strtotime($user['created_at'])) ?><br>
                                            <small style="color: #6b7280;">
                                                by <?= htmlspecialchars($user['created_by_name'] ?? 'System') ?>
                                            </small>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <a href="?edit=<?= $user['id'] ?>" class="btn btn-secondary">
                                                    ✏️ Edit
                                                </a>
                                                <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                                    <form method="POST" style="display: inline;"
                                                          onsubmit="return confirm('Are you sure you want to delete user: <?= htmlspecialchars($user['username']) ?>?')">
                                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                                        <button type="submit" class="btn btn-danger">
                                                            🗑️ Delete
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div style="text-align: center; padding: 3rem; color: #6b7280;">
                        <h3>No Users Found</h3>
                        <p>No users are currently registered in the system.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        // Password strength checker
        function checkPasswordStrength(password) {
            let strength = 0;
            let feedback = [];

            if (password.length >= 8) strength += 1;
            else feedback.push('At least 8 characters');

            if (/[a-z]/.test(password)) strength += 1;
            else feedback.push('Lowercase letter');

            if (/[A-Z]/.test(password)) strength += 1;
            else feedback.push('Uppercase letter');

            if (/\d/.test(password)) strength += 1;
            else feedback.push('Number');

            if (/[^A-Za-z0-9]/.test(password)) strength += 1;

            return { strength, feedback };
        }

        // Password input enhancement
        document.getElementById('password').addEventListener('input', function() {
            const password = this.value;
            const strengthDiv = document.getElementById('passwordStrength');
            const strengthFill = document.getElementById('strengthFill');
            const strengthText = document.getElementById('strengthText');

            if (password.length === 0) {
                strengthDiv.style.display = 'none';
                return;
            }

            strengthDiv.style.display = 'block';
            const result = checkPasswordStrength(password);

            // Update strength bar
            const percentage = (result.strength / 5) * 100;
            strengthFill.style.width = percentage + '%';

            if (result.strength <= 2) {
                strengthFill.className = 'strength-fill strength-weak';
                strengthText.textContent = 'Weak - Missing: ' + result.feedback.join(', ');
            } else if (result.strength <= 3) {
                strengthFill.className = 'strength-fill strength-medium';
                strengthText.textContent = 'Medium - Consider adding: ' + result.feedback.join(', ');
            } else {
                strengthFill.className = 'strength-fill strength-strong';
                strengthText.textContent = 'Strong password';
            }
        });

        // Form validation
        document.getElementById('userForm').addEventListener('submit', function(e) {
            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            const isEdit = <?= $edit_user ? 'true' : 'false' ?>;

            // Check password match
            if (password && password !== confirmPassword) {
                e.preventDefault();
                alert('Passwords do not match!');
                return;
            }

            // Check password strength for new users
            if (!isEdit && password) {
                const result = checkPasswordStrength(password);
                if (result.strength < 3) {
                    e.preventDefault();
                    alert('Password is too weak. Please choose a stronger password.');
                    return;
                }
            }

            // Disable submit button
            const submitBtn = document.getElementById('submitBtn');
            submitBtn.disabled = true;
            submitBtn.textContent = isEdit ? '🔄 Updating...' : '🔄 Creating...';

            // Re-enable after timeout as fallback
            setTimeout(() => {
                submitBtn.disabled = false;
                submitBtn.textContent = isEdit ? '💾 Update User' : '➕ Create User';
            }, 5000);
        });

        // Username validation
        document.getElementById('username').addEventListener('input', function() {
            this.value = this.value.replace(/[^A-Za-z0-9_]/g, '');
        });

        // Real-time validation
        const requiredFields = document.querySelectorAll('input[required], select[required]');
        requiredFields.forEach(field => {
            field.addEventListener('input', function() {
                if (this.value.trim()) {
                    this.style.borderColor = '#10b981';
                } else {
                    this.style.borderColor = '#ef4444';
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
unset($users, $edit_user, $input_data, $errors);
?>
