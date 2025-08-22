<?php
// functions.php - Reusable utility functions
// Purpose: Centralize common operations for maintainability
// Inputs: None initially
// Outputs: Functions for app logic

require_once __DIR__ . '/db_connect.php';

// Function to create initial database tables
function createDatabaseTables() {
    global $db;
    // SQL to create tables (expand as features are added)
    $sql = "
        CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) NOT NULL,
            password VARCHAR(255) NOT NULL, -- Hashed password
            role ENUM('parent', 'child') NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS child_profiles (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            avatar VARCHAR(255),
            age INT,
            preferences TEXT,
            FOREIGN KEY (user_id) REFERENCES users(id)
        );
    ";

    try {
        $db->exec($sql); // Execute multiple queries
        return true;
    } catch (PDOException $e) {
        // Log error in production
        error_log("Table creation failed: " . $e->getMessage());
        return false;
    }
}

// Call to create tables on first load (remove after initial setup)
if (!createDatabaseTables()) {
    die("Failed to initialize database tables.");
}
?>