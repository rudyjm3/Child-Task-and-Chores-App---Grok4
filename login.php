<?php
// login.php - User login
// Purpose: Authenticate and redirect to dashboard
// Version: 3.17.5

require_once __DIR__ . '/includes/functions.php';

session_start();
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard_" . $_SESSION['role'] . ".php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = filter_input(INPUT_POST, 'username', FILTER_SANITIZE_STRING);
    $password = filter_input(INPUT_POST, 'password', FILTER_SANITIZE_STRING);
    if (loginUser($username, $password)) {
        $userStmt = $db->prepare("SELECT id, role, name FROM users WHERE username = :username");
        $userStmt->execute([':username' => $username]);
        $user = $userStmt->fetch(PDO::FETCH_ASSOC);
        $_SESSION['user_id'] = $user['id'];
        // For backward compatibility the UI expects 'parent' or 'child' dashboards.
        // We'll set a generic session role for UI and a detailed role_type for permissions.
        $_SESSION['role'] = ($user['role'] === 'child') ? 'child' : 'parent';
        $_SESSION['role_type'] = $user['role']; // 'main_parent', 'family_member', 'caregiver', or 'child'
        $_SESSION['username'] = $username;
        // Normalize display name consistently
        $_SESSION['name'] = getDisplayName($user['id']);
        error_log("Login successful for user_id=" . $user['id'] . ", role_type=" . $user['role']);
        header("Location: dashboard_" . $_SESSION['role'] . ".php");
        exit;
    } else {
        $error = "Invalid username or password.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Child Task and Chore App</title>
    <link rel="stylesheet" href="css/main.css?v=3.17.5">
    <style>
        .login-form { padding: 20px; max-width: 400px; margin: 0 auto; text-align: center; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; }
        .form-group input { width: 100%; padding: 8px; }
        .button { padding: 10px 20px; background-color: #4caf50; color: white; border: none; border-radius: 5px; cursor: pointer; }
        @media (max-width: 768px) { .login-form { padding: 10px; } }
    </style>
</head>
<body>
    <div class="login-form">
        <h1>Login</h1>
        <?php if (isset($error)): ?>
            <p style="color: red;"><?php echo htmlspecialchars($error); ?></p>
        <?php endif; ?>
        <form method="POST">
            <div class="form-group">
                <label for="username">Username:</label>
                <input type="text" id="username" name="username" required>
            </div>
            <div class="form-group">
                <label for="password">Password:</label>
                <input type="password" id="password" name="password" required>
            </div>
            <button type="submit" class="button">Login</button>
        </form>
        <p>New user? <a href="register.php">Register here</a></p>
    </div>
  <script src="js/number-stepper.js" defer></script>
</body>
</html>




