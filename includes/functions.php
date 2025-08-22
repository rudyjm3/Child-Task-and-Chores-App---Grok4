<?php
// functions.php - Reusable utility functions
// Purpose: Centralize common operations for maintainability
// Inputs: None initially
// Outputs: Functions for app logic

require_once __DIR__ . '/db_connect.php';

// Function to create initial database tables (from Phase 1, updated)
function createDatabaseTables() {
    global $db;
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
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        );
    ";
    try {
        $db->exec($sql);
        return true;
    } catch (PDOException $e) {
        error_log("Table creation failed: " . $e->getMessage());
        return false;
    }
}

// Register a new user
function registerUser($username, $password, $role) {
    global $db;
    $password = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $db->prepare("INSERT INTO users (username, password, role) VALUES (:username, :password, :role)");
    return $stmt->execute([':username' => $username, ':password' => $password, ':role' => $role]);
}

// Login function
function loginUser($username, $password) {
    global $db;
    $stmt = $db->prepare("SELECT * FROM users WHERE username = :username");
    $stmt->execute([':username' => $username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['role'] = $user['role'];
        return true;
    }
    return false;
}

// Create child profile (parent-only)
function createChildProfile($user_id, $avatar, $age, $preferences) {
    global $db;
    $stmt = $db->prepare("INSERT INTO child_profiles (user_id, avatar, age, preferences) VALUES (:user_id, :avatar, :age, :preferences)");
    return $stmt->execute([':user_id' => $user_id, ':avatar' => $avatar, ':age' => $age, ':preferences' => $preferences]);
}

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Initial table creation (remove after setup)
if (!createDatabaseTables()) {
    die("Failed to initialize database tables.");
}
?>