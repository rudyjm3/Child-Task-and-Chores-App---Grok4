<?php
// index.php - Main entry point and landing page
// Purpose: Provides initial access point with login redirection
// Inputs: None
// Outputs: HTML landing page

require_once __DIR__ . '/includes/functions.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Child Task and Chore App</title>
    <link rel="stylesheet" href="css/main.css">
</head>
<body>
    <header>
        <h1>Child Task and Chore App</h1>
        <p>Welcome! Please <a href="login.php">log in</a> to get started.</p>
    </header>
    <main>
        <p>This app helps children aged 5-13 manage tasks and routines, with autism-friendly features.</p>
    </main>
    <footer>
        <p>Child Task and Chore App - Ver 3.4.2</p>
    </footer>
</body>
</html>