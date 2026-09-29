<?php
include 'db.php';
session_start();

// If the user is already logged in, redirect them to the dashboard
if (isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$reg_error = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = trim($_POST['role'] ?? '');
    $district = trim($_POST['district'] ?? '');

    if (!empty($username) && !empty($password) && !empty($role) && !empty($district)) {
        // Securely hash the password using bcrypt
        $hashed_password = password_hash($password, PASSWORD_BCRYPT);
        
        $stmt = $conn->prepare("INSERT INTO users (username, password, role, district) VALUES (?, ?, ?, ?)");
        if ($stmt) {
            $stmt->bind_param("ssss", $username, $hashed_password, $role, $district);
            if ($stmt->execute()) {
                $stmt->close();
                $_SESSION['flash_message'] = [
                    'type' => 'success',
                    'message' => 'Registration successful! You can now log in.'
                ];
                header("Location: login.php");
                exit;
            } else {
                $reg_error = "Error: Username might already be taken.";
            }
            $stmt->close();
        } else {
            $reg_error = "Database error. Please try again.";
        }
    } else {
        $reg_error = "Please fill in all fields correctly.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - Disaster Management System</title>
    <link rel="stylesheet" href="style.css">
    <style>
        body { background: #d6e4f0; }
        .register-container { max-width: 450px; margin: 60px auto; padding: 30px; }
    </style>
</head>
<body>
    <div class="card register-container">
        <h2 style="color:#2c3e50; text-align:center;">Create Personnel Account</h2>
        <p style="text-align:center; font-size:0.85rem; color:#7f8c8d; margin-bottom:20px;">
            Register new authorized camp or administrative staff.
        </p>
        
        <?php if (!empty($reg_error)): ?>
            <div style="background:#fee2e2; border:1px solid #fca5a5; color:#b91c1c; padding:10px 14px; border-radius:6px; margin-bottom:18px; font-size:13px; font-weight:600; text-align:center;">
                <?php echo htmlspecialchars($reg_error); ?>
            </div>
        <?php endif; ?>
        
        <form action="register.php" method="POST">
            <label>Username:</label>
            <input type="text" name="username" required placeholder="e.g., colombo_officer">
            
            <label>Password:</label>
            <input type="password" name="password" required placeholder="••••••••">
            
            <label>System Access Role:</label>
            <select name="role" required>
                <option value="Camp Officer">Camp Officer (Manage Camps & Families)</option>
                <option value="District Admin">District Admin (Approve Supply Requests)</option>
                <option value="National Authority">National Authority (View Island-wide Map/Metrics)</option>
            </select>
            
            <label>Assigned District (Sri Lanka):</label>
            <select name="district" required>
                <option value="All">All Districts (For National Authority Only)</option>
                <option value="Colombo">Colombo</option>
                <option value="Gampaha">Gampaha</option>
                <option value="Kalutara">Kalutara</option>
                <option value="Kandy">Kandy</option>
                <option value="Matale">Matale</option>
                <option value="Nuwara Eliya">Nuwara Eliya</option>
                <option value="Galle">Galle</option>
                <option value="Matara">Matara</option>
                <option value="Hambantota">Hambantota</option>
                <option value="Jaffna">Jaffna</option>
                <option value="Kilinochchi">Kilinochchi</option>
                <option value="Mannar">Mannar</option>
                <option value="Vavuniya">Vavuniya</option>
                <option value="Mullaitivu">Mullaitivu</option>
                <option value="Batticaloa">Batticaloa</option>
                <option value="Ampara">Ampara</option>
                <option value="Trincomalee">Trincomalee</option>
                <option value="Kurunegala">Kurunegala</option>
                <option value="Puttalam">Puttalam</option>
                <option value="Anuradhapura">Anuradhapura</option>
                <option value="Polonnaruwa">Polonnaruwa</option>
                <option value="Badulla">Badulla</option>
                <option value="Monaragala">Monaragala</option>
                <option value="Ratnapura">Ratnapura</option>
                <option value="Kegalle">Kegalle</option>
            </select>
            
            <button type="submit" style="background:#2ecc71; margin-top:15px;">Register Account</button>
        </form>
        
        <p style="margin-top: 20px; text-align: center; font-size: 0.9rem;">
            Already have an account? <a href="login.php" style="color: #3498db; text-decoration: none; font-weight: bold;">Sign In here</a>
        </p>
    </div>
</body>
</html>