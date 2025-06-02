<?php
session_start();
include 'db_config.php';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'];
    $password = $_POST['password'];

    $stmt = $conn->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();

    $result = $stmt->get_result();
    $user = $result->fetch_assoc();

    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user'] = $user['username'];
        $_SESSION['role'] = $user['role'];
        header("Location: dashboard.php");
        exit;
    } else {
        $error = "Invalid username or password.";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
  <title>ECWMS Login</title>
  <style>
    body {
      font-family: Arial, sans-serif;
      background: #f1f5f9;
      margin: 0;
      display: flex;
      justify-content: center;
      align-items: center;
      height: 100vh;
    }

    .login-wrapper {
      background: white;
      border-radius: 10px;
      box-shadow: 0 0 12px rgba(0, 0, 0, 0.15);
      width: 420px;
      padding: 20px 30px 40px;
      text-align: center;
      position: relative;
    }

    .header-section {
      background: #0c4a6e;
      color: white;
      padding: 16px;
      border-radius: 10px 10px 0 0;
      position: relative;
    }

    .header-section h2 {
      margin: 0;
      font-size: 22px;
    }

    .header-section p {
      font-size: 13px;
      margin: 4px 0 0;
    }

    .header-section .profile-image {
      position: absolute;
      top: 12px;
      right: 16px;
      width: 40px;
      height: 40px;
      border-radius: 50%;
      object-fit: cover;
      border: 2px solid white;
    }

    input[type="text"],
    input[type="password"] {
      width: 90%;
      padding: 10px;
      margin: 12px 0;
      border: 1px solid #ccc;
      border-radius: 6px;
    }

    button {
      background: #2563eb;
      color: white;
      border: none;
      padding: 10px 20px;
      border-radius: 6px;
      font-weight: bold;
      font-size: 15px;
      cursor: pointer;
      width: 95%;
      margin-top: 10px;
    }

    button:hover {
      background: #1d4ed8;
    }
  </style>
</head>
<body>

<div class="login-wrapper">
  <div class="header-section">
    <h2><strong>ECWMS</strong></h2>
    <p>Enterprise Client Warehouse Management System</p>
    <img src="images/IMG_3045.jpeg" alt="Profile" class="profile-image">
  </div>

<div class="login-container">
  <div class="ecwms-title"></div>
  <div class="subtext"></div>

  <?php if ($error): ?>
    <div class="error"><?= $error ?></div>
  <?php endif; ?>

  <form method="POST">
    <input type="text" name="username" placeholder="Username" required>
    <input type="password" name="password" placeholder="Password" required>
    <button type="submit">🔒 Login</button>
  </form>
</div>

</body>
</html>
