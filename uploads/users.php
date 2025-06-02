<?php
require 'auth.php';
require_login();
require_admin();

include 'db_config.php';
$users = $conn->query("SELECT * FROM users ORDER BY created_at DESC");

if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    $conn->query("DELETE FROM users WHERE id = $id");
    header("Location: manage_users.php");
    exit;
}
?>

<h2>Manage Users</h2>
<a href="register.php">➕ Register New User</a><br><br>
<table border="1" cellpadding="8">
  <tr>
    <th>ID</th>
    <th>Username</th>
    <th>Role</th>
    <th>Created</th>
    <th>Actions</th>
  </tr>
  <?php while ($row = $users->fetch_assoc()): ?>
    <tr>
      <td><?= $row['id'] ?></td>
      <td><?= $row['username'] ?></td>
      <td><?= $row['role'] ?></td>
      <td><?= $row['created_at'] ?></td>
      <td>
        <?php if ($row['username'] !== $_SESSION['user']): ?>
          <a href="?delete=<?= $row['id'] ?>" onclick="return confirm('Delete user?')">🗑 Delete</a>
        <?php else: ?>
          (You)
        <?php endif; ?>
      </td>
    </tr>
  <?php endwhile; ?>
</table>
<?php include 'footer.php'; ?>

</div>
<link rel="stylesheet" href="style.css">

