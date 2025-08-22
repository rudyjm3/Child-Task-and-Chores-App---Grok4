<?php
// db_connect.php - Establishes database connection
// Purpose: Connects to MySQL for all app data storage
// Inputs: None (uses defined constants)
// Outputs: PDO connection object or error termination

// Define database credentials (move to config file in later phases for security)
define('DB_HOST', 'localhost');
define('DB_USER', 'root'); // Update with your MySQL username
define('DB_PASS', '');     // Update with your MySQL password
define('DB_NAME', 'child_chore_app'); // Database name

try {
    // Create PDO connection with error handling
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); // Enable exceptions
    // Set to UTF-8 for character support
    $pdo->exec("SET NAMES 'utf8'");
} catch (PDOException $e) {
    // Terminate with error message (log in production)
    die("Connection failed: " . $e->getMessage());
}

// Make connection available globally (refine with dependency injection later)
$db = $pdo;
?>