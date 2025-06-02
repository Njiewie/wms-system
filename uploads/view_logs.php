<?php
require 'auth.php';
require_admin();
include 'db_config.php';

$result = $conn->query("SELECT * FROM user_logs ORDER BY log_time DESC");
?>

<h2>User Activity Logs</h2>
<table border="1" cellpadding="6">
  <tr><th>ID</th><th>User</th><th>Action</th><th>Time</th></tr>
  <?php while ($row = $result->fetch_assoc()): ?>
    <tr>
      <td><?= $row['id'] ?></td>
      <td><?= $row['username'] ?></td>
      <td><?= $row['action'] ?></td>
      <td><?= $row['log_time'] ?></td>
    </tr>
  <?php endwhile; ?>
</table>
<?php include 'footer.php'; ?>

</div>
<link rel="stylesheet" href="style.css">

