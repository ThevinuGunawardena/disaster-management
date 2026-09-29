<?php
include 'db.php';
session_start();

if (isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$login_error = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt = $conn->prepare("SELECT id, username, password, role, district FROM users WHERE username = ?");
    if ($stmt) {
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result && $result->num_rows > 0) {
            $user = $result->fetch_assoc();
            if (password_verify($password, $user['password'])) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['district'] = $user['district'];
                $stmt->close();
                header("Location: index.php");
                exit;
            }
        }
        $stmt->close();
    }
    $login_error = "Invalid username or password.";
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
        <?php 
        $flash = $_SESSION['flash_message'] ?? null;
        unset($_SESSION['flash_message']);
        if ($flash): ?>
            <div style="background:<?php echo $flash['type'] === 'success' ? '#dcfce7' : '#fee2e2'; ?>; border:1px solid <?php echo $flash['type'] === 'success' ? '#86efac' : '#fca5a5'; ?>; color:<?php echo $flash['type'] === 'success' ? '#15803d' : '#b91c1c'; ?>; padding:10px 14px; border-radius:6px; margin-bottom:18px; font-size:13px; font-weight:600; text-align:center;">
                <?php echo htmlspecialchars($flash['message']); ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($login_error)): ?>
            <div style="background:#fee2e2; border:1px solid #fca5a5; color:#b91c1c; padding:10px 14px; border-radius:6px; margin-bottom:18px; font-size:13px; font-weight:600; text-align:center;">
                <?php echo htmlspecialchars($login_error); ?>
            </div>
        <?php endif; ?>
        
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