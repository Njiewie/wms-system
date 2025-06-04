<?php
/**
 * Secure User Management System
 * Comprehensive user management with enhanced security features
 */

require_once 'security-utils.php';
require_once 'db_config.php';

$security = SecurityUtils::getInstance();
$db = getDB();

// Check rate limiting and session (require manager role)
if (!$security->checkRateLimit()) {
    http_response_code(429);
    $security->logActivity('RATE_LIMIT_EXCEEDED', ['page' => 'manage_users'], 'WARNING');
    die('Rate limit exceeded. Please try again later.');
}

if (!$security->validateSession('manager')) {
    $security->logActivity('UNAUTHORIZED_ACCESS_ATTEMPT', ['page' => 'manage_users'], 'WARNING');
    header('Location: auth.php');
    exit();
}

$csrfToken = $security->generateCSRFToken();
$message = '';
$messageType = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$security->validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = 'Invalid CSRF token. Please try again.';
        $messageType = 'danger';
    } else {
        $action = $security->sanitizeInput($_POST['action']);
        
        switch ($action) {
            case 'add_user':
                $result = handleAddUser($_POST, $db, $security);
                $message = $result['message'];
                $messageType = $result['type'];
                break;
                
            case 'edit_user':
                $result = handleEditUser($_POST, $db, $security);
                $message = $result['message'];
                $messageType = $result['type'];
                break;
                
            case 'delete_user':
                $result = handleDeleteUser($_POST, $db, $security);
                $message = $result['message'];
                $messageType = $result['type'];
                break;
                
            case 'reset_password':
                $result = handleResetPassword($_POST, $db, $security);
                $message = $result['message'];
                $messageType = $result['type'];
                break;
                
            case 'unlock_user':
                $result = handleUnlockUser($_POST, $db, $security);
                $message = $result['message'];
                $messageType = $result['type'];
                break;
        }
    }
}

// Handle AJAX requests
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    
    if (!$security->validateCSRFToken($_GET['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid CSRF token']);
        exit();
    }
    
    switch ($_GET['ajax']) {
        case 'get_user':
            $userId = (int) ($_GET['id'] ?? 0);
            if ($userId > 0) {
                $user = $db->fetchRow(
                    "SELECT id, username, email, role, is_active, last_login, failed_login_attempts, locked_until 
                     FROM users WHERE id = ? AND deleted_at IS NULL", 
                    [$userId]
                );
                echo json_encode($user ?: ['error' => 'User not found']);
            } else {
                echo json_encode(['error' => 'Invalid user ID']);
            }
            break;
            
        case 'check_username':
            $username = $security->sanitizeInput($_GET['username'] ?? '');
            $excludeId = (int) ($_GET['exclude_id'] ?? 0);
            
            $sql = "SELECT COUNT(*) FROM users WHERE username = ? AND deleted_at IS NULL";
            $params = [$username];
            
            if ($excludeId > 0) {
                $sql .= " AND id != ?";
                $params[] = $excludeId;
            }
            
            $exists = $db->fetchValue($sql, $params) > 0;
            echo json_encode(['exists' => $exists]);
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid AJAX action']);
    }
    exit();
}

// Get users list with pagination
$page = max(1, (int) ($_GET['page'] ?? 1));
$search = $security->sanitizeInput($_GET['search'] ?? '');
$roleFilter = $security->sanitizeInput($_GET['role'] ?? '');

$whereClause = "deleted_at IS NULL";
$params = [];

if (!empty($search)) {
    $whereClause .= " AND (username LIKE ? OR email LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

if (!empty($roleFilter)) {
    $whereClause .= " AND role = ?";
    $params[] = $roleFilter;
}

$result = $db->paginate('users', $whereClause, $params, $page, 20, 'created_at DESC');
$users = $result['data'];
$pagination = $result['pagination'];

$security->logActivity('USER_MANAGEMENT_ACCESS', ['search' => $search, 'role_filter' => $roleFilter]);

/**
 * Handle adding new user
 */
function handleAddUser($data, $db, $security) {
    try {
        // Validate input
        $rules = [
            'username' => ['required' => true, 'min_length' => 3, 'max_length' => 50, 'pattern' => '/^[a-zA-Z0-9_]+$/', 'pattern_message' => 'Username can only contain letters, numbers, and underscores'],
            'email' => ['required' => true, 'type' => 'email', 'max_length' => 100],
            'password' => ['required' => true, 'min_length' => 8],
            'role' => ['required' => true, 'pattern' => '/^(viewer|operator|supervisor|manager|admin)$/', 'pattern_message' => 'Invalid role selected']
        ];
        
        $errors = $security->validateInput($data, $rules);
        
        // Check password strength
        $passwordErrors = $security->validatePasswordStrength($data['password']);
        if (!empty($passwordErrors)) {
            $errors['password'] = implode(', ', $passwordErrors);
        }
        
        // Check if username already exists
        if (empty($errors['username']) && $db->exists('users', 'username = ? AND deleted_at IS NULL', [$data['username']])) {
            $errors['username'] = 'Username already exists';
        }
        
        // Check if email already exists
        if (empty($errors['email']) && $db->exists('users', 'email = ? AND deleted_at IS NULL', [$data['email']])) {
            $errors['email'] = 'Email already exists';
        }
        
        if (!empty($errors)) {
            return ['message' => 'Validation errors: ' . implode(', ', $errors), 'type' => 'danger'];
        }
        
        // Hash password and insert user
        $userData = [
            'username' => $data['username'],
            'email' => $data['email'],
            'password_hash' => $security->hashPassword($data['password']),
            'role' => $data['role'],
            'is_active' => 1,
            'password_changed_at' => date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        $userId = $db->insert('users', $userData);
        
        $security->logActivity('USER_CREATED', [
            'new_user_id' => $userId,
            'username' => $data['username'],
            'role' => $data['role']
        ]);
        
        return ['message' => 'User created successfully', 'type' => 'success'];
        
    } catch (Exception $e) {
        $security->logActivity('USER_CREATE_FAILED', ['error' => $e->getMessage()], 'ERROR');
        return ['message' => 'Failed to create user: ' . $security->sanitizeErrorMessage($e->getMessage()), 'type' => 'danger'];
    }
}

/**
 * Handle editing user
 */
function handleEditUser($data, $db, $security) {
    try {
        $userId = (int) ($data['user_id'] ?? 0);
        if ($userId <= 0) {
            return ['message' => 'Invalid user ID', 'type' => 'danger'];
        }
        
        // Prevent self-modification of critical fields
        if ($userId == $_SESSION['user_id']) {
            if (isset($data['role']) && $data['role'] !== $_SESSION['role']) {
                return ['message' => 'Cannot change your own role', 'type' => 'danger'];
            }
            if (isset($data['is_active']) && !$data['is_active']) {
                return ['message' => 'Cannot deactivate your own account', 'type' => 'danger'];
            }
        }
        
        // Get current user data
        $currentUser = $db->fetchRow("SELECT * FROM users WHERE id = ? AND deleted_at IS NULL", [$userId]);
        if (!$currentUser) {
            return ['message' => 'User not found', 'type' => 'danger'];
        }
        
        // Validate input
        $rules = [
            'username' => ['required' => true, 'min_length' => 3, 'max_length' => 50, 'pattern' => '/^[a-zA-Z0-9_]+$/'],
            'email' => ['required' => true, 'type' => 'email', 'max_length' => 100],
            'role' => ['required' => true, 'pattern' => '/^(viewer|operator|supervisor|manager|admin)$/']
        ];
        
        $errors = $security->validateInput($data, $rules);
        
        // Check username uniqueness
        if (empty($errors['username']) && $data['username'] !== $currentUser['username']) {
            if ($db->exists('users', 'username = ? AND id != ? AND deleted_at IS NULL', [$data['username'], $userId])) {
                $errors['username'] = 'Username already exists';
            }
        }
        
        // Check email uniqueness
        if (empty($errors['email']) && $data['email'] !== $currentUser['email']) {
            if ($db->exists('users', 'email = ? AND id != ? AND deleted_at IS NULL', [$data['email'], $userId])) {
                $errors['email'] = 'Email already exists';
            }
        }
        
        if (!empty($errors)) {
            return ['message' => 'Validation errors: ' . implode(', ', $errors), 'type' => 'danger'];
        }
        
        // Prepare update data
        $updateData = [
            'username' => $data['username'],
            'email' => $data['email'],
            'role' => $data['role'],
            'is_active' => isset($data['is_active']) ? 1 : 0,
            'updated_at' => date('Y-m-d H:i:s')
        ];
        
        $affectedRows = $db->update('users', $updateData, 'id = ?', [':id' => $userId]);
        
        if ($affectedRows > 0) {
            $security->logActivity('USER_UPDATED', [
                'user_id' => $userId,
                'changes' => array_diff_assoc($updateData, array_intersect_assoc($currentUser, $updateData))
            ]);
            
            return ['message' => 'User updated successfully', 'type' => 'success'];
        } else {
            return ['message' => 'No changes made', 'type' => 'info'];
        }
        
    } catch (Exception $e) {
        $security->logActivity('USER_UPDATE_FAILED', ['user_id' => $userId, 'error' => $e->getMessage()], 'ERROR');
        return ['message' => 'Failed to update user: ' . $security->sanitizeErrorMessage($e->getMessage()), 'type' => 'danger'];
    }
}

/**
 * Handle deleting user (soft delete)
 */
function handleDeleteUser($data, $db, $security) {
    try {
        $userId = (int) ($data['user_id'] ?? 0);
        if ($userId <= 0) {
            return ['message' => 'Invalid user ID', 'type' => 'danger'];
        }
        
        // Prevent self-deletion
        if ($userId == $_SESSION['user_id']) {
            return ['message' => 'Cannot delete your own account', 'type' => 'danger'];
        }
        
        $affectedRows = $db->softDelete('users', $userId);
        
        if ($affectedRows > 0) {
            $security->logActivity('USER_DELETED', ['deleted_user_id' => $userId], 'WARNING');
            return ['message' => 'User deleted successfully', 'type' => 'success'];
        } else {
            return ['message' => 'User not found or already deleted', 'type' => 'warning'];
        }
        
    } catch (Exception $e) {
        $security->logActivity('USER_DELETE_FAILED', ['user_id' => $userId, 'error' => $e->getMessage()], 'ERROR');
        return ['message' => 'Failed to delete user: ' . $security->sanitizeErrorMessage($e->getMessage()), 'type' => 'danger'];
    }
}

/**
 * Handle password reset
 */
function handleResetPassword($data, $db, $security) {
    try {
        $userId = (int) ($data['user_id'] ?? 0);
        if ($userId <= 0) {
            return ['message' => 'Invalid user ID', 'type' => 'danger'];
        }
        
        // Generate secure temporary password
        $tempPassword = $security->generateToken(12);
        $hashedPassword = $security->hashPassword($tempPassword);
        
        $updateData = [
            'password_hash' => $hashedPassword,
            'password_changed_at' => date('Y-m-d H:i:s'),
            'failed_login_attempts' => 0,
            'locked_until' => null,
            'updated_at' => date('Y-m-d H:i:s')
        ];
        
        $affectedRows = $db->update('users', $updateData, 'id = ? AND deleted_at IS NULL', [':id' => $userId]);
        
        if ($affectedRows > 0) {
            $security->logActivity('PASSWORD_RESET', ['target_user_id' => $userId], 'WARNING');
            return ['message' => "Password reset successfully. Temporary password: {$tempPassword}", 'type' => 'success'];
        } else {
            return ['message' => 'User not found', 'type' => 'warning'];
        }
        
    } catch (Exception $e) {
        $security->logActivity('PASSWORD_RESET_FAILED', ['user_id' => $userId, 'error' => $e->getMessage()], 'ERROR');
        return ['message' => 'Failed to reset password: ' . $security->sanitizeErrorMessage($e->getMessage()), 'type' => 'danger'];
    }
}

/**
 * Handle unlocking user account
 */
function handleUnlockUser($data, $db, $security) {
    try {
        $userId = (int) ($data['user_id'] ?? 0);
        if ($userId <= 0) {
            return ['message' => 'Invalid user ID', 'type' => 'danger'];
        }
        
        $updateData = [
            'failed_login_attempts' => 0,
            'locked_until' => null,
            'updated_at' => date('Y-m-d H:i:s')
        ];
        
        $affectedRows = $db->update('users', $updateData, 'id = ? AND deleted_at IS NULL', [':id' => $userId]);
        
        if ($affectedRows > 0) {
            $security->logActivity('USER_UNLOCKED', ['target_user_id' => $userId]);
            return ['message' => 'User account unlocked successfully', 'type' => 'success'];
        } else {
            return ['message' => 'User not found', 'type' => 'warning'];
        }
        
    } catch (Exception $e) {
        $security->logActivity('USER_UNLOCK_FAILED', ['user_id' => $userId, 'error' => $e->getMessage()], 'ERROR');
        return ['message' => 'Failed to unlock user: ' . $security->sanitizeErrorMessage($e->getMessage()), 'type' => 'danger'];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management - Secure WMS</title>
    <meta name="robots" content="noindex, nofollow">
    <meta http-equiv="X-Content-Type-Options" content="nosniff">
    <meta http-equiv="X-Frame-Options" content="SAMEORIGIN">
    <meta http-equiv="X-XSS-Protection" content="1; mode=block">
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    
    <style>
        :root {
            --primary-color: #2c3e50;
            --secondary-color: #34495e;
            --success-color: #27ae60;
            --warning-color: #f39c12;
            --danger-color: #e74c3c;
        }
        
        body {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .navbar {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            box-shadow: 0 2px 15px rgba(0,0,0,0.1);
        }
        
        .card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
            transition: transform 0.3s ease;
        }
        
        .card:hover {
            transform: translateY(-2px);
        }
        
        .table {
            background: white;
            border-radius: 10px;
            overflow: hidden;
        }
        
        .btn-group-actions {
            display: flex;
            gap: 5px;
        }
        
        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
        }
        
        .status-badge {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
        }
        
        .modal-content {
            border: none;
            border-radius: 15px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.2);
        }
        
        .password-strength {
            height: 5px;
            border-radius: 3px;
            margin-top: 5px;
            transition: all 0.3s ease;
        }
        
        .strength-weak { background-color: #e74c3c; }
        .strength-medium { background-color: #f39c12; }
        .strength-strong { background-color: #27ae60; }
        
        .search-container {
            background: white;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container-fluid">
            <a class="navbar-brand" href="secure-dashboard.php">
                <i class="fas fa-users-cog"></i> User Management
            </a>
            
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="secure-dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                <a class="nav-link" href="auth.php?action=logout"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
        </div>
    </nav>

    <div class="container-fluid mt-4">
        <!-- Messages -->
        <?php if (!empty($message)): ?>
            <div class="alert alert-<?= $messageType ?> alert-dismissible fade show" role="alert">
                <?= $security->escapeOutput($message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Search and Filters -->
        <div class="search-container">
            <form method="GET" class="row g-3">
                <div class="col-md-4">
                    <label for="search" class="form-label">Search Users</label>
                    <input type="text" class="form-control" id="search" name="search" 
                           value="<?= $security->escapeOutput($search) ?>" 
                           placeholder="Username or email...">
                </div>
                <div class="col-md-3">
                    <label for="role" class="form-label">Filter by Role</label>
                    <select class="form-select" id="role" name="role">
                        <option value="">All Roles</option>
                        <option value="viewer" <?= $roleFilter === 'viewer' ? 'selected' : '' ?>>Viewer</option>
                        <option value="operator" <?= $roleFilter === 'operator' ? 'selected' : '' ?>>Operator</option>
                        <option value="supervisor" <?= $roleFilter === 'supervisor' ? 'selected' : '' ?>>Supervisor</option>
                        <option value="manager" <?= $roleFilter === 'manager' ? 'selected' : '' ?>>Manager</option>
                        <option value="admin" <?= $roleFilter === 'admin' ? 'selected' : '' ?>>Admin</option>
                    </select>
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary me-2">
                        <i class="fas fa-search"></i> Search
                    </button>
                    <a href="?" class="btn btn-outline-secondary">
                        <i class="fas fa-times"></i> Clear
                    </a>
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="button" class="btn btn-success w-100" data-bs-toggle="modal" data-bs-target="#addUserModal">
                        <i class="fas fa-plus"></i> Add User
                    </button>
                </div>
            </form>
        </div>

        <!-- Users Table -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-users"></i> Users (<?= $pagination['total'] ?>)</h5>
            </div>
            <div class="card-body p-0">
                <?php if (empty($users)): ?>
                    <div class="text-center py-5">
                        <i class="fas fa-users fa-3x text-muted mb-3"></i>
                        <p class="text-muted">No users found</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>User</th>
                                    <th>Email</th>
                                    <th>Role</th>
                                    <th>Status</th>
                                    <th>Last Login</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($users as $user): ?>
                                    <tr>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="user-avatar me-3">
                                                    <?= strtoupper(substr($user['username'], 0, 2)) ?>
                                                </div>
                                                <div>
                                                    <div class="fw-bold"><?= $security->escapeOutput($user['username']) ?></div>
                                                    <small class="text-muted">ID: <?= $user['id'] ?></small>
                                                </div>
                                            </div>
                                        </td>
                                        <td><?= $security->escapeOutput($user['email']) ?></td>
                                        <td>
                                            <span class="badge bg-<?= getRoleBadgeColor($user['role']) ?>">
                                                <?= ucfirst($user['role']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($user['is_active']): ?>
                                                <?php if ($user['locked_until'] && strtotime($user['locked_until']) > time()): ?>
                                                    <span class="status-badge bg-warning text-dark">
                                                        <i class="fas fa-lock"></i> Locked
                                                    </span>
                                                <?php else: ?>
                                                    <span class="status-badge bg-success text-white">
                                                        <i class="fas fa-check"></i> Active
                                                    </span>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="status-badge bg-danger text-white">
                                                    <i class="fas fa-times"></i> Inactive
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($user['last_login']): ?>
                                                <small><?= date('M j, Y H:i', strtotime($user['last_login'])) ?></small>
                                            <?php else: ?>
                                                <small class="text-muted">Never</small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="btn-group-actions">
                                                <button class="btn btn-sm btn-outline-primary" 
                                                        onclick="editUser(<?= $user['id'] ?>)" 
                                                        title="Edit User">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                
                                                <?php if ($user['locked_until'] && strtotime($user['locked_until']) > time()): ?>
                                                    <button class="btn btn-sm btn-outline-warning" 
                                                            onclick="unlockUser(<?= $user['id'] ?>)" 
                                                            title="Unlock User">
                                                        <i class="fas fa-unlock"></i>
                                                    </button>
                                                <?php endif; ?>
                                                
                                                <button class="btn btn-sm btn-outline-info" 
                                                        onclick="resetPassword(<?= $user['id'] ?>)" 
                                                        title="Reset Password">
                                                    <i class="fas fa-key"></i>
                                                </button>
                                                
                                                <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                                    <button class="btn btn-sm btn-outline-danger" 
                                                            onclick="deleteUser(<?= $user['id'] ?>, '<?= $security->escapeOutput($user['username']) ?>')" 
                                                            title="Delete User">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Pagination -->
            <?php if ($pagination['total_pages'] > 1): ?>
                <div class="card-footer">
                    <nav aria-label="User pagination">
                        <ul class="pagination justify-content-center mb-0">
                            <?php if ($pagination['has_prev']): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=<?= $pagination['current_page'] - 1 ?>&search=<?= urlencode($search) ?>&role=<?= urlencode($roleFilter) ?>">
                                        <i class="fas fa-chevron-left"></i>
                                    </a>
                                </li>
                            <?php endif; ?>
                            
                            <?php for ($i = max(1, $pagination['current_page'] - 2); $i <= min($pagination['total_pages'], $pagination['current_page'] + 2); $i++): ?>
                                <li class="page-item <?= $i === $pagination['current_page'] ? 'active' : '' ?>">
                                    <a class="page-link" href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&role=<?= urlencode($roleFilter) ?>">
                                        <?= $i ?>
                                    </a>
                                </li>
                            <?php endfor; ?>
                            
                            <?php if ($pagination['has_next']): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=<?= $pagination['current_page'] + 1 ?>&search=<?= urlencode($search) ?>&role=<?= urlencode($roleFilter) ?>">
                                        <i class="fas fa-chevron-right"></i>
                                    </a>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </nav>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Add User Modal -->
    <div class="modal fade" id="addUserModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-user-plus"></i> Add New User</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="addUserForm">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add_user">
                        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                        
                        <div class="mb-3">
                            <label for="add_username" class="form-label">Username *</label>
                            <input type="text" class="form-control" id="add_username" name="username" required 
                                   pattern="[a-zA-Z0-9_]+" minlength="3" maxlength="50">
                            <div class="form-text">Only letters, numbers, and underscores allowed</div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="add_email" class="form-label">Email *</label>
                            <input type="email" class="form-control" id="add_email" name="email" required maxlength="100">
                        </div>
                        
                        <div class="mb-3">
                            <label for="add_password" class="form-label">Password *</label>
                            <input type="password" class="form-control" id="add_password" name="password" required 
                                   minlength="8" onkeyup="checkPasswordStrength(this.value, 'add_strength')">
                            <div class="password-strength" id="add_strength"></div>
                            <div class="form-text">
                                Password must contain: 8+ characters, uppercase, lowercase, number, special character
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="add_role" class="form-label">Role *</label>
                            <select class="form-select" id="add_role" name="role" required>
                                <option value="">Select Role</option>
                                <option value="viewer">Viewer</option>
                                <option value="operator">Operator</option>
                                <option value="supervisor">Supervisor</option>
                                <option value="manager">Manager</option>
                                <?php if ($_SESSION['role'] === 'admin'): ?>
                                    <option value="admin">Admin</option>
                                <?php endif; ?>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-user-plus"></i> Add User
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit User Modal -->
    <div class="modal fade" id="editUserModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-user-edit"></i> Edit User</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="editUserForm">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="edit_user">
                        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                        <input type="hidden" name="user_id" id="edit_user_id">
                        
                        <div class="mb-3">
                            <label for="edit_username" class="form-label">Username *</label>
                            <input type="text" class="form-control" id="edit_username" name="username" required 
                                   pattern="[a-zA-Z0-9_]+" minlength="3" maxlength="50">
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit_email" class="form-label">Email *</label>
                            <input type="email" class="form-control" id="edit_email" name="email" required maxlength="100">
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit_role" class="form-label">Role *</label>
                            <select class="form-select" id="edit_role" name="role" required>
                                <option value="viewer">Viewer</option>
                                <option value="operator">Operator</option>
                                <option value="supervisor">Supervisor</option>
                                <option value="manager">Manager</option>
                                <?php if ($_SESSION['role'] === 'admin'): ?>
                                    <option value="admin">Admin</option>
                                <?php endif; ?>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="edit_is_active" name="is_active" value="1">
                                <label class="form-check-label" for="edit_is_active">
                                    Active Account
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Update User
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const csrfToken = '<?= $csrfToken ?>';
        
        function checkPasswordStrength(password, strengthId) {
            const strengthElement = document.getElementById(strengthId);
            let score = 0;
            
            if (password.length >= 8) score++;
            if (/[A-Z]/.test(password)) score++;
            if (/[a-z]/.test(password)) score++;
            if (/[0-9]/.test(password)) score++;
            if (/[^A-Za-z0-9]/.test(password)) score++;
            
            strengthElement.className = 'password-strength ';
            if (score < 3) {
                strengthElement.className += 'strength-weak';
                strengthElement.style.width = '33%';
            } else if (score < 5) {
                strengthElement.className += 'strength-medium';
                strengthElement.style.width = '66%';
            } else {
                strengthElement.className += 'strength-strong';
                strengthElement.style.width = '100%';
            }
        }
        
        function editUser(userId) {
            fetch(`?ajax=get_user&id=${userId}&csrf_token=${csrfToken}`)
                .then(response => response.json())
                .then(data => {
                    if (data.error) {
                        alert('Error: ' + data.error);
                        return;
                    }
                    
                    document.getElementById('edit_user_id').value = data.id;
                    document.getElementById('edit_username').value = data.username;
                    document.getElementById('edit_email').value = data.email;
                    document.getElementById('edit_role').value = data.role;
                    document.getElementById('edit_is_active').checked = data.is_active == 1;
                    
                    new bootstrap.Modal(document.getElementById('editUserModal')).show();
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Failed to load user data');
                });
        }
        
        function deleteUser(userId, username) {
            if (confirm(`Are you sure you want to delete user "${username}"? This action cannot be undone.`)) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="delete_user">
                    <input type="hidden" name="csrf_token" value="${csrfToken}">
                    <input type="hidden" name="user_id" value="${userId}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        function resetPassword(userId) {
            if (confirm('Are you sure you want to reset this user\'s password? A new temporary password will be generated.')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="reset_password">
                    <input type="hidden" name="csrf_token" value="${csrfToken}">
                    <input type="hidden" name="user_id" value="${userId}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        function unlockUser(userId) {
            if (confirm('Are you sure you want to unlock this user account?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="unlock_user">
                    <input type="hidden" name="csrf_token" value="${csrfToken}">
                    <input type="hidden" name="user_id" value="${userId}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        // Real-time username availability check
        document.getElementById('add_username').addEventListener('blur', function() {
            const username = this.value;
            if (username.length >= 3) {
                fetch(`?ajax=check_username&username=${encodeURIComponent(username)}&csrf_token=${csrfToken}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.exists) {
                            this.setCustomValidity('Username already exists');
                            this.classList.add('is-invalid');
                        } else {
                            this.setCustomValidity('');
                            this.classList.remove('is-invalid');
                        }
                    });
            }
        });
        
        document.getElementById('edit_username').addEventListener('blur', function() {
            const username = this.value;
            const userId = document.getElementById('edit_user_id').value;
            if (username.length >= 3) {
                fetch(`?ajax=check_username&username=${encodeURIComponent(username)}&exclude_id=${userId}&csrf_token=${csrfToken}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.exists) {
                            this.setCustomValidity('Username already exists');
                            this.classList.add('is-invalid');
                        } else {
                            this.setCustomValidity('');
                            this.classList.remove('is-invalid');
                        }
                    });
            }
        });
    </script>
</body>
</html>

<?php
function getRoleBadgeColor($role) {
    return match($role) {
        'admin' => 'danger',
        'manager' => 'warning',
        'supervisor' => 'info',
        'operator' => 'primary',
        'viewer' => 'secondary',
        default => 'secondary'
    };
}
?>