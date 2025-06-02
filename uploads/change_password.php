<?php
require 'auth.php';
require_login();
include 'db_config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_SESSION['user'];
    $current = $_POST['current_password'];
    $new = $_POST['new_password'];

    $stmt = $conn->prepare("SELECT password FROM users WHERE username=?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $stored = $stmt->get_result()->fetch_assoc()['password'];

    if (!password_verify($current, $stored)) {
        $error = "❌ Current password is incorrect.";
    } else {
        $new_hash = password_hash($new, PASSWORD_DEFAULT);
        $update = $conn->prepare("UPDATE users SET password=? WHERE username=?");
        $update->bind_param("ss", $new_hash, $username);
        $update->execute();
        $success = "✅ Password changed successfully.";
    }
}
?>

<h2>Change Password</h2>
<form method="POST">
  <label>Current Password: <input type="password" name="current_password" required></label><br><br>
  <label>New Password: <input type="password" name="new_password" required></label><br><br>
  <button type="submit">Change Password</button>
</form>

<?php
if (isset($error)) echo "<p style='color:red;'>$error</p>";
if (isset($success)) echo "<p style='color:green;'>$success</p>";
?>
<?php include 'footer.php'; ?>

</div>
<link rel="stylesheet" href="style.css">


