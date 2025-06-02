<?php
require 'auth.php';
require_login();
require_admin();
include 'db_config.php';

if (!isset($_GET['id'])) {
    header('Location: manage_users.php');
    exit;
}

$id = intval($_GET['id']);
$result = $conn->query("SELECT * FROM users WHERE id = $id");
if ($result->num_rows === 0) {
    echo "User not found.";
    exit;
}

$user = $result->fetch_assoc();
$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $conn->real_escape_string(trim($_POST['username']));
    $role = $conn->real_escape_string(trim($_POST['role']));
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if (!empty($password) && $password !== $confirm) {
        $error = "❌ Password and confirmation do not match.";
    } else {
        $update_query = "UPDATE users SET username = '$username', role = '$role'";
        if (!empty($password)) {
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            $update_query .= ", password = '$password_hash'";
        }
        $update_query .= " WHERE id = $id";
        $conn->query($update_query);
        header('Location: manage_users.php?success=1');
        exit;
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Edit User</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="p-4">
<div class="container">
    <h2>Edit User</h2>
    <?php if ($error): ?>
        <div class="alert alert-danger"><?= $error ?></div>
    <?php endif; ?>
    <form method="POST">
        <div class="mb-3">
            <label class="form-label">Username</label>
            <input type="text" name="username" class="form-control" value="<?= htmlspecialchars($user['username']) ?>" required>
        </div>
        <div class="mb-3">
            <label class="form-label">Role</label>
            <select name="role" class="form-select" required>
                <option value="admin" <?= $user['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                <option value="manager" <?= $user['role'] === 'manager' ? 'selected' : '' ?>>Manager</option>
                <option value="staff" <?= $user['role'] === 'staff' ? 'selected' : '' ?>>Staff</option>
            </select>
        </div>
        <div class="mb-3">
            <label class="form-label">New Password (Leave blank to keep current)</label>
            <input type="password" name="password" class="form-control" placeholder="••••••••">
        </div>
        <div class="mb-3">
            <label class="form-label">Confirm Password</label>
            <input type="password" name="confirm_password" class="form-control" placeholder="••••••••">
        </div>
        <button type="submit" class="btn btn-primary">💾 Save Changes</button>
        <a href="manage_users.php" class="btn btn-secondary">🔙 Cancel</a>
    </form>
</div>
</body>
</html>
