<?php
require_once 'security-utils.php';
require 'auth.php';
require_login();
require_admin();
include 'db_config.php';

// Set security headers
setSecurityHeaders();

// Rate limiting for user management actions
try {
    WMSSecurity::checkRateLimit('user_management_' . $_SERVER['REMOTE_ADDR'], 10, 300);
} catch (Exception $e) {
    handleSecurityError('Too many requests. Please try again later.');
}

// Handle secure delete request
if (isset($_POST['delete_user_id'])) {
    try {
        // Validate CSRF token
        validate_csrf();

        $delete_id = WMSSecurity::validateInteger($_POST['delete_user_id'], 1);

        // Check if trying to delete own account
        $current_user = secure_select_one($conn,
            "SELECT id FROM users WHERE username = ?",
            "s",
            [$_SESSION['user']]
        );

        if ($current_user && $current_user['id'] == $delete_id) {
            throw new Exception('Cannot delete your own account');
        }

        // Perform secure deletion
        $deleted_rows = secure_delete($conn, 'users', 'id = ?', 'i', [$delete_id]);

        if ($deleted_rows > 0) {
            // Log the deletion
            WMSSecurity::logActivity($conn, $_SESSION['user'], 'user_deleted',
                "Deleted user ID: $delete_id");

            $success_message = "User deleted successfully.";
        } else {
            $error_message = "User not found or could not be deleted.";
        }

    } catch (Exception $e) {
        error_log("User deletion error: " . $e->getMessage());
        $error_message = $e->getMessage();
    }
}

// Search and filter handling with security
$search = '';
$where_conditions = [];
$bind_types = '';
$bind_params = [];

if (!empty($_GET['search'])) {
    try {
        $search = WMSSecurity::sanitizeString($_GET['search'], 100);
        $where_conditions[] = "(username LIKE ? OR role LIKE ?)";
        $bind_types .= 'ss';
        $bind_params[] = "%$search%";
        $bind_params[] = "%$search%";
    } catch (Exception $e) {
        $search = '';
    }
}

// Build secure query
$base_sql = "SELECT id, username, role, created_at FROM users";
if (!empty($where_conditions)) {
    $base_sql .= " WHERE " . implode(" AND ", $where_conditions);
}
$base_sql .= " ORDER BY created_at DESC";

try {
    $users = secure_select_all($conn, $base_sql, $bind_types, $bind_params);
} catch (Exception $e) {
    error_log("User fetch error: " . $e->getMessage());
    $users = [];
    $error_message = "Error loading user data.";
}

// Log user management access
WMSSecurity::logActivity($conn, $_SESSION['user'], 'accessed_user_management',
    "Search: " . ($search ?: 'none'));
?>

<!DOCTYPE html>
<html>
<head>
    <title>Manage Users | ECWMS</title>
    <link rel="stylesheet" href="modern-style.css">
    <style>
        .user-management {
            max-width: 1200px;
            margin: 0 auto;
        }
        .search-bar {
            background: white;
            padding: 1.5rem;
            border-radius: var(--border-radius-lg);
            box-shadow: var(--box-shadow);
            margin-bottom: 1.5rem;
        }
        .users-table {
            background: white;
            border-radius: var(--border-radius-lg);
            box-shadow: var(--box-shadow);
            overflow: hidden;
        }
        .user-row {
            cursor: pointer;
        }
        .user-row:hover {
            background-color: var(--gray-50);
        }
        .user-row.selected {
            background-color: rgba(59, 130, 246, 0.1);
        }
        .role-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 500;
            text-transform: uppercase;
        }
        .role-admin { background: rgba(239, 68, 68, 0.1); color: #991b1b; }
        .role-manager { background: rgba(59, 130, 246, 0.1); color: #1e40af; }
        .role-staff { background: rgba(34, 197, 94, 0.1); color: #166534; }
        .role-operator { background: rgba(245, 158, 11, 0.1); color: #92400e; }
    </style>
</head>
<body class="wms-layout">

<main class="wms-content">
    <div class="user-management">
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1>User Management</h1>
                <p class="text-secondary">Manage system users and their permissions</p>
            </div>
            <a href="register.php" class="btn btn-primary">➕ Add New User</a>
        </div>

        <!-- Messages -->
        <?php if (isset($success_message)): ?>
        <div class="alert alert-success">
            ✅ <?= secure_escape($success_message) ?>
        </div>
        <?php endif; ?>

        <?php if (isset($error_message)): ?>
        <div class="alert alert-danger">
            ❌ <?= secure_escape($error_message) ?>
        </div>
        <?php endif; ?>

        <!-- Search Bar -->
        <div class="search-bar">
            <form method="GET" class="d-flex gap-3">
                <div class="form-group" style="flex-grow: 1; margin-bottom: 0;">
                    <input type="text" name="search" class="form-control"
                           placeholder="Search by username or role..."
                           value="<?= secure_escape($search) ?>">
                </div>
                <button type="submit" class="btn btn-primary">🔍 Search</button>
                <?php if ($search): ?>
                <a href="manage_users_secure.php" class="btn btn-secondary">🔄 Clear</a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Users Table -->
        <?php if (!empty($users)): ?>
        <div class="users-table">
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Username</th>
                            <th>Role</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $user): ?>
                        <tr class="user-row" data-user-id="<?= $user['id'] ?>">
                            <td><?= $user['id'] ?></td>
                            <td><strong><?= secure_escape($user['username']) ?></strong></td>
                            <td>
                                <span class="role-badge role-<?= secure_escape($user['role']) ?>">
                                    <?= secure_escape($user['role']) ?>
                                </span>
                            </td>
                            <td><?= date('M d, Y', strtotime($user['created_at'])) ?></td>
                            <td>
                                <?php if ($user['username'] !== $_SESSION['user']): ?>
                                    <a href="edit_user.php?id=<?= $user['id'] ?>" class="btn btn-sm btn-secondary">✏️ Edit</a>
                                    <button class="btn btn-sm btn-danger" onclick="confirmDelete(<?= $user['id'] ?>, '<?= secure_escape($user['username']) ?>')">🗑️ Delete</button>
                                <?php else: ?>
                                    <span class="text-secondary">(Current User)</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="text-center mt-3">
            <p class="text-secondary">Total Users: <?= count($users) ?></p>
        </div>

        <?php else: ?>
        <div class="card">
            <div class="card-body text-center">
                <h3>No users found</h3>
                <p class="text-secondary">
                    <?= $search ? 'No users match your search criteria.' : 'No users in the system.' ?>
                </p>
                <?php if (!$search): ?>
                <a href="register.php" class="btn btn-primary">➕ Add First User</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Back Button -->
        <div class="text-center mt-4">
            <a href="secure-dashboard.php" class="btn btn-secondary">⬅️ Back to Dashboard</a>
        </div>
    </div>
</main>

<!-- Delete Confirmation Modal -->
<div id="deleteModal" class="modal">
    <div class="modal-content" style="max-width: 400px;">
        <div class="modal-header">
            <h3 class="modal-title">Confirm Deletion</h3>
        </div>
        <div class="modal-body">
            <p>Are you sure you want to delete user <strong id="deleteUsername"></strong>?</p>
            <p class="text-danger">This action cannot be undone.</p>
        </div>
        <div class="modal-footer">
            <form method="POST" id="deleteForm">
                <?= csrf_field() ?>
                <input type="hidden" name="delete_user_id" id="deleteUserId">
                <button type="submit" class="btn btn-danger">🗑️ Delete User</button>
                <button type="button" class="btn btn-secondary" onclick="closeDeleteModal()">Cancel</button>
            </form>
        </div>
    </div>
</div>

<script>
// Delete confirmation functionality
function confirmDelete(userId, username) {
    document.getElementById('deleteUserId').value = userId;
    document.getElementById('deleteUsername').textContent = username;
    document.getElementById('deleteModal').classList.add('active');
}

function closeDeleteModal() {
    document.getElementById('deleteModal').classList.remove('active');
}

// Close modal when clicking outside
document.getElementById('deleteModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeDeleteModal();
    }
});

// Row selection for visual feedback
document.querySelectorAll('.user-row').forEach(row => {
    row.addEventListener('click', function(e) {
        // Don't select if clicking on buttons
        if (e.target.tagName === 'BUTTON' || e.target.tagName === 'A') {
            return;
        }

        // Clear previous selection
        document.querySelectorAll('.user-row').forEach(r => r.classList.remove('selected'));

        // Select current row
        this.classList.add('selected');
    });
});

// Auto-refresh every 5 minutes
setInterval(function() {
    if (document.visibilityState === 'visible') {
        window.location.reload();
    }
}, 300000);
</script>

</body>
</html>

<?php $conn->close(); ?>
