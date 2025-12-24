<?php
// index.php - Landing page
// Purpose: Welcome and login/register links
// Version: 3.15.0

session_start();
if (isset($_SESSION['user_id'])) {
    $role = $_SESSION['role'];
    header("Location: dashboard_$role.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Child Task and Chore App</title>
    <link rel="stylesheet" href="css/main.css?v=3.15.0">
    <style>
        .landing { text-align: center; padding: 50px 20px; max-width: 600px; margin: 0 auto; }
        .button { padding: 15px 30px; background: #4caf50; color: white; text-decoration: none; border-radius: 5px; display: inline-block; margin: 10px; font-size: 18px; }
        .landing h1 { color: #1976d2; }
        @media (max-width: 768px) { .landing { padding: 20px; } .button { width: 100%; } }
    </style>
</head>
<body>
    <div class="landing">
        <h1>Child Task and Chore App</h1>
        <p>Help your child build good habits with fun tasks, routines, and rewards!</p>
        <a href="login.php" class="button">Login</a>
        <a href="register.php" class="button">Register</a>
    </div>
</body>
</html>

