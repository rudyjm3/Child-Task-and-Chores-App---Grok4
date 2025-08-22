<?php
// functions.php - Reusable utility functions
// Purpose: Centralize common operations for maintainability
// Inputs: None initially
// Outputs: Functions for app logic

require_once __DIR__ . '/db_connect.php';

// Register a new user
function registerUser($username, $password, $role) {
    global $db;
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $db->prepare("INSERT INTO users (username, password, role) VALUES (:username, :password, :role)");
    return $stmt->execute([
        ':username' => $username,
        ':password' => $hashedPassword,
        ':role' => $role
    ]);
}

// Login user
function loginUser($username, $password) {
    global $db;
    $stmt = $db->prepare("SELECT * FROM users WHERE username = :username");
    $stmt->execute([':username' => $username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($user && password_verify($password, $user['password'])) {
        return true;
    }
    return false;
}

// Create a child profile
function createChildProfile($child_user_id, $avatar, $age, $preferences, $parent_user_id) {
    global $db;
    $stmt = $db->prepare("INSERT INTO child_profiles (child_user_id, parent_user_id, avatar, age, preferences) VALUES (:child_user_id, :parent_user_id, :avatar, :age, :preferences)");
    return $stmt->execute([
        ':child_user_id' => $child_user_id,
        ':parent_user_id' => $parent_user_id,
        ':avatar' => $avatar,
        ':age' => $age,
        ':preferences' => $preferences
    ]);
}

// Fetch dashboard data for a parent
function getDashboardData($parent_user_id) {
    global $db;
    $data = [];
    
    // Fetch child profiles for the parent
    $stmt = $db->prepare("SELECT cp.id, cp.child_user_id, u.username, cp.avatar, cp.age, cp.preferences 
                         FROM child_profiles cp 
                         JOIN users u ON cp.child_user_id = u.id 
                         WHERE cp.parent_user_id = :parent_user_id");
    $stmt->execute([':parent_user_id' => $parent_user_id]);
    $data['children'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch pending tasks for the parent's children
    $stmt = $db->prepare("SELECT t.id, t.title, t.due_date, t.points, t.status, u.username as assigned_to 
                         FROM tasks t 
                         JOIN child_profiles cp ON t.user_id = cp.parent_user_id 
                         JOIN users u ON cp.child_user_id = u.id 
                         WHERE t.user_id = :parent_user_id AND t.status = 'pending'");
    $stmt->execute([':parent_user_id' => $parent_user_id]);
    $data['tasks'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return $data;
}

// Create a new task
function createTask($user_id, $title, $description, $due_date, $points, $recurrence, $category, $timing_mode) {
    global $db;
    $stmt = $db->prepare("INSERT INTO tasks (user_id, title, description, due_date, points, recurrence, category, timing_mode, status) VALUES (:user_id, :title, :description, :due_date, :points, :recurrence, :category, :timing_mode, 'pending')");
    return $stmt->execute([
        ':user_id' => $user_id,
        ':title' => $title,
        ':description' => $description,
        ':due_date' => $due_date,
        ':points' => $points,
        ':recurrence' => $recurrence,
        ':category' => $category,
        ':timing_mode' => $timing_mode
    ]);
}

// Fetch tasks for a user (adjusted for children to see parent's tasks)
function getTasks($user_id) {
    global $db;
    $tasks = [];
    $userStmt = $db->prepare("SELECT role FROM users WHERE id = :id");
    $userStmt->execute([':id' => $user_id]);
    $role = $userStmt->fetchColumn();

    if ($role === 'parent') {
        $stmt = $db->prepare("SELECT * FROM tasks WHERE user_id = :user_id ORDER BY due_date ASC");
        $stmt->execute([':user_id' => $user_id]);
        $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($role === 'child') {
        $parentStmt = $db->prepare("SELECT parent_user_id FROM child_profiles WHERE child_user_id = :child_id LIMIT 1");
        $parentStmt->execute([':child_id' => $user_id]);
        $parent_id = $parentStmt->fetchColumn();
        if ($parent_id) {
            $stmt = $db->prepare("SELECT * FROM tasks WHERE user_id = :parent_id ORDER BY due_date ASC");
            $stmt->execute([':parent_id' => $parent_id]);
            $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            error_log("Parent ID not found for child user_id: " . $user_id);
        }
    }
    return $tasks;
}

// Complete a task
function completeTask($task_id, $completed_by, $photo_proof) {
    global $db;
    $stmt = $db->prepare("UPDATE tasks SET status = 'completed', completed_by = :completed_by, photo_proof = :photo_proof, completed_at = NOW() WHERE id = :task_id");
    return $stmt->execute([
        ':task_id' => $task_id,
        ':completed_by' => $completed_by,
        ':photo_proof' => $photo_proof
    ]);
}

// Approve a task
function approveTask($task_id) {
    global $db;
    $stmt = $db->prepare("UPDATE tasks SET status = 'approved' WHERE id = :task_id");
    return $stmt->execute([':task_id' => $task_id]);
}

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Initial table creation (remove after setup)
// if (!createDatabaseTables()) {
//     die("Failed to initialize database tables.");
// }

// Create users table if not exists
$sql = "CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('parent', 'child') NOT NULL,
    FOREIGN KEY (id) REFERENCES users(id) ON DELETE CASCADE
)";
$db->exec($sql);

// Create child_profiles table if not exists
$sql = "CREATE TABLE IF NOT EXISTS child_profiles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    child_user_id INT NOT NULL,
    parent_user_id INT NOT NULL,
    avatar VARCHAR(50),
    age INT,
    preferences TEXT,
    FOREIGN KEY (parent_user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (child_user_id) REFERENCES users(id) ON DELETE CASCADE
)";
$db->exec($sql);

// Create tasks table if not exists
$sql = "CREATE TABLE IF NOT EXISTS tasks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(100) NOT NULL,
    description TEXT,
    due_date DATETIME,
    points INT,
    recurrence ENUM('daily', 'weekly', '') DEFAULT '',
    category ENUM('hygiene', 'homework', 'household') DEFAULT 'household',
    timing_mode ENUM('timer', 'suggested', 'no_limit') DEFAULT 'no_limit',
    status ENUM('pending', 'completed', 'approved') DEFAULT 'pending',
    photo_proof VARCHAR(255),
    completed_by INT,
    completed_at DATETIME,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)";
$db->exec($sql);
?>