<?php
// register.php - User registration
// Purpose: Register new parent account (child creation now parent-driven)
// Version: 3.11.0

require_once __DIR__ . '/includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = filter_input(INPUT_POST, 'username', FILTER_SANITIZE_STRING);
    $password = filter_input(INPUT_POST, 'password', FILTER_SANITIZE_STRING);
    $first_name = filter_input(INPUT_POST, 'first_name', FILTER_SANITIZE_STRING);
    $last_name = filter_input(INPUT_POST, 'last_name', FILTER_SANITIZE_STRING);
    $gender = filter_input(INPUT_POST, 'gender', FILTER_SANITIZE_STRING);
    $role = 'main_parent'; // primary account creator

    if (registerUser($username, $password, $role, $first_name, $last_name, $gender)) {
        // Auto-login after registration
        $_SESSION['user_id'] = $db->lastInsertId();
        $_SESSION['role'] = 'parent'; // UI-level
        $_SESSION['role_type'] = $role; // detailed
        $_SESSION['username'] = $username;
        header("Location: dashboard_parent.php?setup_family=1");
        exit;
    } else {
        $error = "Registration failed. Username may already exist.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - Child Task and Chore App</title>
    <link rel="stylesheet" href="css/main.css?v=3.11.0">
    <style>
        .register-form { padding: 20px; max-width: 400px; margin: 0 auto; text-align: center; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; }
        .form-group input, .form-group select { width: 100%; padding: 8px; }
        .button { padding: 10px 20px; background-color: #4caf50; color: white; border: none; border-radius: 5px; cursor: pointer; }
        .role-note { font-size: 0.9em; color: #666; margin-top: 10px; }
        @media (max-width: 768px) { .register-form { padding: 10px; } }
    </style>
</head>
<body>
    <div class="register-form">
        <h1>Register as Parent</h1>
        <p>Create your account to manage your child's tasks and chores.</p>
        <?php if (isset($error)): ?>
            <p style="color: red;"><?php echo htmlspecialchars($error); ?></p>
        <?php endif; ?>
        <form method="POST">
            <div class="form-group">
                <label for="first_name">First Name:</label>
                <input type="text" id="first_name" name="first_name" required>
            </div>
            <div class="form-group">
                <label for="last_name">Last Name:</label>
                <input type="text" id="last_name" name="last_name" required>
            </div>
            <div class="form-group">
                <label for="gender">Gender:</label>
                <select id="gender" name="gender">
                    <option value="">Select</option>
                    <option value="male">Male (Father)</option>
                    <option value="female">Female (Mother)</option>
                </select>
            </div>
            <div class="form-group">
                <label for="username">Username (for login):</label>
                <input type="text" id="username" name="username" required>
            </div>
            <div class="form-group">
                <label for="password">Password:</label>
                <input type="password" id="password" name="password" required>
            </div>
            <button type="submit" class="button">Register</button>
        </form>
        <p>Already have an account? <a href="login.php">Login here</a></p>
        <p class="role-note">Child accounts are created by parents during setup.</p>
    </div>
</body>
</html>
