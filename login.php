<?php
// login.php - User login page
// Purpose: Handle user authentication and session management
// Inputs: Username and password from form
// Outputs: Redirect to dashboard or error message

require_once __DIR__ . '/includes/functions.php';

session_start(); // Ensure session starts here
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = filter_input(INPUT_POST, 'username', FILTER_SANITIZE_STRING);
    $password = filter_input(INPUT_POST, 'password', FILTER_SANITIZE_STRING);

    if (loginUser($username, $password)) {
        // Fetch user details to set username in session
        $userStmt = $db->prepare("SELECT id, username, role FROM users WHERE username = :username");
        $userStmt->execute([':username' => $username]);
        $user = $userStmt->fetch(PDO::FETCH_ASSOC);
        
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['username'] = $user['username'];
        error_log("Login successful for user_id={$user['id']}, role={$user['role']}");

        // Debug session data and ID
        error_log("Session data before redirect: " . print_r($_SESSION, true));
        error_log("Session ID before redirect: " . session_id());

        // Debug before redirect
        error_log("Before redirect for user_id={$_SESSION['user_id']}, role={$_SESSION['role']}");
        if ($user['role'] === 'parent') {
            error_log("Redirecting to dashboard_parent.php");
            header("Location: http://localhost/Child Task and Chores App - Grok4/dashboard_parent.php");
        } else {
            error_log("Redirecting to dashboard_child.php");
            header("Location: http://localhost/Child Task and Chores App - Grok4/dashboard_child.php");
        }
        error_log("After header call for user_id={$_SESSION['user_id']}, role={$_SESSION['role']}");
        exit;
    } else {
        $message = "Invalid username or password.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login</title>
    <link rel="stylesheet" href="css/main.css">
    <style>
        .login-form {
            padding: 20px;
            max-width: 400px;
            margin: 0 auto;
        }
        .login-form label {
            display: block;
            margin-bottom: 5px;
        }
        .login-form input {
            width: 100%;
            margin-bottom: 10px;
            padding: 8px;
        }
        .button {
            padding: 10px 20px;
            background-color: #4caf50;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
        }
    </style>
</head>
<body>
    <header>
        <h1>Login</h1>
    </header>
    <main>
        <?php if ($message) echo "<p>$message</p>"; ?>
        <div class="login-form">
            <form method="POST" action="login.php">
                <label for="username">Username:</label>
                <input type="text" id="username" name="username" required>
                <label for="password">Password:</label>
                <input type="password" id="password" name="password" required>
                <button type="submit" class="button">Login</button>
            </form>
        </div>
    </main>
    <footer>
        <p>Child Task and Chore App - Ver 3.4.6</p>
    </footer>
</body>
</html>