<?php
include 'db.php';
session_start();

// If the user is already logged in, redirect them to the dashboard
if (isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = mysqli_real_escape_string($conn, $_POST['username']);
    $password = $_POST['password'];
    $role = mysqli_real_escape_string($conn, $_POST['role']);
    $district = mysqli_real_escape_string($conn, $_POST['district']);

    if (!empty($username) && !empty($password) && !empty($role) && !empty($district)) {
        // Securely hash the password using bcrypt
        $hashed_password = password_hash($password, PASSWORD_BCRYPT);
        
        // Insert the new user into the database
        $sql = "INSERT INTO users (username, password, role, district) VALUES ('$username', '$hashed_password', '$role', '$district')";
        
        if ($conn->query($sql) === TRUE) {
            echo "<script>alert('Registration successful! You can now log in.'); window.location.href='login.php';</script>";
        } else {
            echo "<script>alert('Error: Username might already be taken.');</script>";
        }
    } else {
        echo "<script>alert('Please fill in all fields correctly.');</script>";
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
                <option value="Kalutara">Matale</option>
                <option value="Kandy">Kandy</option>
                <option value="Nuvara Eliya">Nuwara Eliya</option>
                <option value="Galle">Galle</option>
                <option value="Matara">Matara</option>
                <option value="Hambantota">Hambantota</option>
                <option value="Anuradhapura">Anuradhapura</option>
                <option value="Polonnaruwa">Polonnaruwa</option>
                <option value="Jaffna">Jaffna</option>
                <option value="Jaffna">Vavuniya</option>
                <option value="Jaffna">Kilinochchi</option>
                <option value="Jaffna">Mulaithivu</option>
                <option value="Jaffna">Mannar</option>
                <option value="Jaffna">Trincomalee</option>
                <option value="Batticaloa">Batticaloa</option>
                <option value="Ampara">Ampara</option>
                <option value="Jaffna">Puttalam</option>
                <option value="Jaffna">Kurunegala</option>
                <option value="Badulla">Badulla</option>
                <option value="Badulla">Moneragala</option>
                <option value="Ratnapura">Ratnapura</option>
                <option value="Ratnapura">Kegalle</option>
                </select>
            
            <button type="submit" style="background:#2ecc71; margin-top:15px;">Register Account</button>
        </form>
        
        <p style="margin-top: 20px; text-align: center; font-size: 0.9rem;">
            Already have an account? <a href="login.php" style="color: #3498db; text-decoration: none; font-weight: bold;">Sign In here</a>
        </p>
    </div>
</body>
</html>