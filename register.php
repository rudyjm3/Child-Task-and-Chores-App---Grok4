<?php
// register.php - User registration page
// Purpose: Allow parents and children to register
// Inputs: Username, password, role
// Outputs: Registration form and success/error messages

require_once __DIR__ . '/includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = filter_input(INPUT_POST, 'username', FILTER_SANITIZE_STRING);
    $password = filter_input(INPUT_POST, 'password', FILTER_SANITIZE_STRING);
    $role = filter_input(INPUT_POST, 'role', FILTER_SANITIZE_STRING);

    if (registerUser($username, $password, $role)) {
        $message = "Registration successful! Please <a href='login.php'>log in</a>.";
    } else {
        $message = "Registration failed. Username may be taken.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register</title>
    <link rel="stylesheet" href="css/main.css">
</head>
<body>
    <header>
        <h1>Register</h1>
    </header>
    <main>
        <?php if (isset($message)) echo "<p>$message</p>"; ?>
        <form method="POST" action="register.php">
            <label for="username">Username:</label><br>
            <input type="text" id="username" name="username" required><br>
            <label for="password">Password:</label><br>
            <input type="password" id="password" name="password" required><br>
            <label for="role">Role:</label><br>
            <select id="role" name="role" required>
                <option value="parent">Parent</option>
                <option value="child">Child</option>
            </select><br>
            <button type="submit">Register</button>
        </form>
    </main>
    <footer>
        <p>Child Task and Chore App - Ver 1.1.0</p>
    </footer>
</body>
</html>