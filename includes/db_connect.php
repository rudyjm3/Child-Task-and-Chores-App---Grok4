<?php
// db_connect.php - Establishes database connection
// Purpose: Connects to MySQL for all app data storage
// Inputs: None (uses defined constants)
// Outputs: PDO connection object or error termination


// Define database credentials (move to config file in later phases for security)
//Database connection details

// Local Development database
// define('DB_HOST', 'localhost');
// define('DB_USER', 'root'); // Update with your MySQL username
// define('DB_PASS', '');     // Update with your MySQL password
// define('DB_NAME', 'child_chore_app'); // Database name


// Live server database
define('DB_HOST', 'localhost');
define('DB_USER', 'u207292155_rudyjm333');
define('DB_PASS', 'Matrix_1645');
define('DB_NAME', 'u207292155_child_chore_ap');

// App timezone (keep PHP and MySQL aligned across environments)
define('APP_TIMEZONE', 'America/New_York');
date_default_timezone_set(APP_TIMEZONE);




try {
    // Create PDO connection with error handling
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); // Enable exceptions
    $pdo->exec("SET NAMES 'utf8'");
    $pdo->exec("SET time_zone = '" . date('P') . "'");
} catch (PDOException $e) {
    // Check if database exists, create if not (temporary for setup)
    if ($e->getCode() == 1049) { // Unknown database
        $pdo = new PDO("mysql:host=" . DB_HOST, DB_USER, DB_PASS);
        $pdo->exec("CREATE DATABASE IF NOT EXISTS " . DB_NAME);
        $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("SET NAMES 'utf8'");
        $pdo->exec("SET time_zone = '" . date('P') . "'");
    } else {
        die("Connection failed: " . $e->getMessage());
    }
}

// Make connection available globally (refine with dependency injection later)
$db = $pdo;
?>
