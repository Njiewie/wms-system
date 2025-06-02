<?php
require 'auth.php';
require_login();
require_admin();

include 'db_config.php';

// Handle delete request
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    $conn->query("DELETE FROM users WHERE id = $id");
    header("Location: manage_users.php");
    exit;
}

// Search and filter handling
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$sql = "SELECT * FROM users";
if (!empty($search)) {
    $search_safe = $conn->real_escape_string($search);
    $sql .= " WHERE username LIKE '%$search_safe%' OR role LIKE '%$search_safe%'";
}
$sql .= " ORDER BY created_at DESC";
$users = $conn->query($sql);

// Handle success alert from edit_user.php
$success = isset($_GET['success']) && $_GET['success'] === '1';
?>

<!DOCTYPE html>
<html>
<head>
    <title>Manage Users</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="style.css">
</head>
<body class="p-4">
<div class="container">
<h2 class="mb-4">Manage Users</h2>
<a href="register.php" class="btn btn-success mb-3">➕ Register New User</a>

<?php if ($success): ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
  ✅ User updated successfully.
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<form class="mb-3" method="GET">
  <div class="input-group">
    <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" class="form-control" placeholder="Search by username or role">
    <button class="btn btn-primary" type="submit">🔍 Search</button>
  </div>
</form>

<p class="text-muted">Total Users: <?= $users->num_rows ?></p>

<table class="table table-bordered">
  <thead class="table-dark">
    <tr>
      <th>ID</th>
      <th>Username</th>
      <th>Role</th>
      <th>Created</th>
      <th>Actions</th>
    </tr>
  </thead>
  <tbody>
  <?php while ($row = $users->fetch_assoc()): ?>
    <tr>
      <td><?= $row['id'] ?></td>
      <td><?= htmlspecialchars($row['username']) ?></td>
      <td><?= htmlspecialchars($row['role']) ?></td>
      <td><?= htmlspecialchars($row['created_at']) ?></td>
      <td>
        <?php if ($row['username'] !== $_SESSION['user']): ?>
          <a href="edit_user.php?id=<?= $row['id'] ?>" class="btn btn-warning btn-sm">✏ Edit</a>
          <a href="?delete=<?= $row['id'] ?>" class="btn btn-danger btn-sm" onclick="return confirm('Delete user?')">🗑 Delete</a>
        <?php else: ?>
          (You)
        <?php endif; ?>
      </td>
    </tr>
  <?php endwhile; ?>
  </tbody>
</table>
<?php include 'footer.php'; ?>
</div>
</body>
</html>
