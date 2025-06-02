<?php
include 'db_config.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = $_POST['username'];
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $role = $_POST['role'];

    $stmt = $conn->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, ?)");
    $stmt->bind_param("sss", $username, $password, $role);

    if ($stmt->execute()) {
        echo "✅ User registered. <a href='login.php'>Login</a>";
    } else {
        echo "❌ Registration failed: " . $stmt->error;
    }

    $conn->close();
    exit;
}
?>

<h2>Register New User</h2>
<form method="POST">
  Username: <input type="text" name="username" required><br><br>
  Password: <input type="password" name="password" required><br><br>
  Role: 
  <select name="role">
    <option value="operator">Operator</option>
    <option value="admin">Admin</option>
  </select><br><br>
  <button type="submit">Register</button>
</form>
