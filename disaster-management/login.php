<?php
include 'db.php';
session_start();

if (isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = mysqli_real_escape_string($conn, $_POST['username']);
    $password = $_POST['password'];

    $sql = "SELECT * FROM users WHERE username='$username'";
    $result = $conn->query($sql);

    if ($result && $result->num_rows > 0) {
        $user = $result->fetch_assoc();
        if (password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['district'] = $user['district'];
            header("Location: index.php");
            exit;
        }
    }
    echo "<script>alert('Invalid username or password.');</script>";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Disaster Management Portal</title>
    <link rel="stylesheet" href="style.css">
</head>
<body style="background:#d6e4f0;">
    <div class="card" style="max-width: 420px; margin: 120px auto; padding: 30px;">
        <h2 style="color:#2c3e50; text-align:center;">System Portal Authentication</h2>
        <p style="text-align:center; font-size:0.85rem; color:#7f8c8d; margin-bottom:20px;">Disaster Displacement Management Platform</p>
        
        <form action="login.php" method="POST">
            <label>Username:</label>
            <input type="text" name="username" required placeholder="e.g., officer1">
            <label>Password:</label>
            <input type="password" name="password" required placeholder="••••••••">
            <button type="submit" style="background:#2c3e50; margin-top:10px;">Login</button>
        </form>

        <p style="margin-top: 20px; text-align: center; font-size: 0.9rem;">
            New personnel? <a href="register.php" style="color: #3498db; text-decoration: none; font-weight: bold;">Create an Account here</a>
        </p>
    </div>
</body>
</html>