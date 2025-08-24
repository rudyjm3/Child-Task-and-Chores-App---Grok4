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

// Fetch dashboard data based on user role
// Update getDashboardData to include completed goals and adjust points
function getDashboardData($user_id) {
    global $db;
    $data = [];

    $userStmt = $db->prepare("SELECT role FROM users WHERE id = :id");
    $userStmt->execute([':id' => $user_id]);
    $role = $userStmt->fetchColumn();

    if ($role === 'parent') {
        $stmt = $db->prepare("SELECT cp.id, cp.child_user_id, u.username, cp.avatar, cp.age, cp.preferences 
                             FROM child_profiles cp 
                             JOIN users u ON cp.child_user_id = u.id 
                             WHERE cp.parent_user_id = :parent_id");
        $stmt->execute([':parent_id' => $user_id]);
        $data['children'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $db->prepare("SELECT t.id, t.title, t.due_date, t.points, t.status, u.username as assigned_to 
                             FROM tasks t 
                             JOIN child_profiles cp ON t.user_id = cp.parent_user_id 
                             JOIN users u ON cp.child_user_id = u.id 
                             WHERE t.user_id = :parent_id AND t.status = 'pending'");
        $stmt->execute([':parent_id' => $user_id]);
        $data['tasks'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch active (unredeemed) rewards
        $stmt = $db->prepare("SELECT id, title, description, point_cost FROM rewards WHERE parent_user_id = :parent_id AND status = 'available'");
        $stmt->execute([':parent_id' => $user_id]);
        $data['active_rewards'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch redeemed rewards
        $stmt = $db->prepare("SELECT r.id, r.title, r.description, r.point_cost, u.username as child_username 
                             FROM rewards r 
                             JOIN child_profiles cp ON r.parent_user_id = cp.parent_user_id 
                             JOIN users u ON cp.child_user_id = u.id 
                             WHERE r.parent_user_id = :parent_id AND r.status = 'redeemed'");
        $stmt->execute([':parent_id' => $user_id]);
        $data['redeemed_rewards'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($role === 'child') {
        $stmt = $db->prepare("SELECT COALESCE(SUM(t.points), 0) as total_points 
                             FROM tasks t 
                             JOIN child_profiles cp ON t.user_id = cp.parent_user_id 
                             WHERE cp.child_user_id = :child_id AND t.status = 'approved'");
        $stmt->execute([':child_id' => $user_id]);
        $total_points = $stmt->fetchColumn();

        // Include points from completed goals
        $stmt = $db->prepare("SELECT COALESCE(SUM(g.target_points), 0) as goal_points 
                             FROM goals g 
                             WHERE g.child_user_id = :child_id AND g.status = 'completed'");
        $stmt->execute([':child_id' => $user_id]);
        $goal_points = $stmt->fetchColumn();
        $total_points += $goal_points;

        // Calculate remaining points after redemptions
        $stmt = $db->prepare("SELECT COALESCE(SUM(r.point_cost), 0) as redeemed_points 
                             FROM rewards r 
                             JOIN goals g ON r.id = g.reward_id 
                             JOIN child_profiles cp ON g.child_user_id = cp.child_user_id 
                             WHERE cp.child_user_id = :child_id AND r.status = 'redeemed'");
        $stmt->execute([':child_id' => $user_id]);
        $redeemed_points = $stmt->fetchColumn();
        $remaining_points = max(0, $total_points - $redeemed_points);

        $max_points = 100; // Define a max points threshold (adjust as needed)
        $points_progress = ($remaining_points > 0 && $max_points > 0) ? min(100, round(($remaining_points / $max_points) * 100)) : 0;
        $data['points_progress'] = $points_progress;
        $data['remaining_points'] = $remaining_points;

        // Fetch available rewards for the child
        $parentStmt = $db->prepare("SELECT parent_user_id FROM child_profiles WHERE child_user_id = :child_id LIMIT 1");
        $parentStmt->execute([':child_id' => $user_id]);
        $parent_id = $parentStmt->fetchColumn();
        if ($parent_id) {
            $stmt = $db->prepare("SELECT id, title, description, point_cost FROM rewards WHERE parent_user_id = :parent_id AND status = 'available'");
            $stmt->execute([':parent_id' => $parent_id]);
            $data['rewards'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // Fetch active goals for the child
        $stmt = $db->prepare("SELECT g.id, g.title, g.target_points, g.start_date, g.end_date, r.title as reward_title 
                             FROM goals g 
                             LEFT JOIN rewards r ON g.reward_id = r.id 
                             WHERE g.child_user_id = :child_id AND g.status = 'active'");
        $stmt->execute([':child_id' => $user_id]);
        $data['active_goals'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch completed goals for the child
        $stmt = $db->prepare("SELECT g.id, g.title, g.target_points, g.start_date, g.end_date, g.completed_at, r.title as reward_title 
                             FROM goals g 
                             LEFT JOIN rewards r ON g.reward_id = r.id 
                             WHERE g.child_user_id = :child_id AND g.status = 'completed'");
        $stmt->execute([':child_id' => $user_id]);
        $data['completed_goals'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch redeemed rewards with redemption dates
        $stmt = $db->prepare("SELECT r.id, r.title, r.description, r.point_cost, r.status, t.completed_at as redemption_date 
                             FROM rewards r 
                             LEFT JOIN tasks t ON r.id = t.id -- Approximation; adjust join as needed
                             JOIN child_profiles cp ON r.parent_user_id = cp.parent_user_id 
                             WHERE cp.child_user_id = :child_id AND r.status = 'redeemed'");
        $stmt->execute([':child_id' => $user_id]);
        $data['redeemed_rewards'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

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

// Create a new reward
function createReward($parent_user_id, $title, $description, $point_cost) {
    global $db;
    $stmt = $db->prepare("INSERT INTO rewards (parent_user_id, title, description, point_cost) VALUES (:parent_user_id, :title, :description, :point_cost)");
    return $stmt->execute([
        ':parent_user_id' => $parent_user_id,
        ':title' => $title,
        ':description' => $description,
        ':point_cost' => $point_cost
    ]);
}

// Create a new goal
function createGoal($parent_user_id, $child_user_id, $title, $target_points, $start_date, $end_date, $reward_id = null) {
    global $db;
    // Validate date range
    $start_datetime = new DateTime($start_date);
    $end_datetime = new DateTime($end_date);
    if ($end_datetime < $start_datetime) {
        error_log("Invalid date range: end_date ($end_date) is before start_date ($start_date) for goal creation.");
        return false;
    }
    // Ensure reward_id is null if not provided or invalid
    $reward_id = ($reward_id && is_numeric($reward_id)) ? $reward_id : null;
    $stmt = $db->prepare("INSERT INTO goals (parent_user_id, child_user_id, title, target_points, start_date, end_date, reward_id) VALUES (:parent_user_id, :child_user_id, :title, :target_points, :start_date, :end_date, :reward_id)");
    return $stmt->execute([
        ':parent_user_id' => $parent_user_id,
        ':child_user_id' => $child_user_id,
        ':title' => $title,
        ':target_points' => $target_points,
        ':start_date' => $start_date,
        ':end_date' => $end_date,
        ':reward_id' => $reward_id
    ]);
}

// Redeem a reward
function redeemReward($child_user_id, $reward_id) {
    global $db;
    $db->beginTransaction();
    try {
        // Check if the child has enough points
        $stmt = $db->prepare("SELECT COALESCE(SUM(t.points), 0) as total_points 
                             FROM tasks t 
                             JOIN child_profiles cp ON t.user_id = cp.parent_user_id 
                             WHERE cp.child_user_id = :child_id AND t.status = 'approved'");
        $stmt->execute([':child_id' => $child_user_id]);
        $total_points = $stmt->fetchColumn();

        $stmt = $db->prepare("SELECT point_cost FROM rewards WHERE id = :reward_id AND status = 'available' FOR UPDATE");
        $stmt->execute([':reward_id' => $reward_id]);
        $reward = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$reward || $total_points < $reward['point_cost']) {
            $db->rollBack();
            return false;
        }

        // Update reward status
        $stmt = $db->prepare("UPDATE rewards SET status = 'redeemed' WHERE id = :reward_id");
        $stmt->execute([':reward_id' => $reward_id]);

        $db->commit();
        return true;
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Reward redemption failed: " . $e->getMessage());
        return false;
    }
}

// Complete a goal
function completeGoal($child_user_id, $goal_id) {
    global $db;
    $db->beginTransaction();
    try {
        // Check if the goal exists and is active for the child
        $stmt = $db->prepare("SELECT target_points FROM goals WHERE id = :goal_id AND child_user_id = :child_id AND status = 'active'");
        $stmt->execute([':goal_id' => $goal_id, ':child_id' => $child_user_id]);
        $goal = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$goal) {
            $db->rollBack();
            return false;
        }

        // Update goal status to completed
        $stmt = $db->prepare("UPDATE goals SET status = 'completed', completed_at = NOW() WHERE id = :goal_id");
        $stmt->execute([':goal_id' => $goal_id]);

        // Award points (simplified; adjust logic as needed)
        $target_points = $goal['target_points'];
        $db->commit();
        return $target_points; // Return points for updating child points
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Goal completion failed: " . $e->getMessage());
        return false;
    }
}

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Initial table creation (remove after setup)
// if (!createDatabaseTables()) {
//     die("Failed to initialize database tables.");
// }

// Ensure goals table includes status and completed_at columns
$db->exec("ALTER TABLE goals ADD COLUMN IF NOT EXISTS status VARCHAR(20) DEFAULT 'active'");
$db->exec("ALTER TABLE goals ADD COLUMN IF NOT EXISTS completed_at DATETIME DEFAULT NULL");

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

// Create rewards table if not exists
$sql = "CREATE TABLE IF NOT EXISTS rewards (
    id INT AUTO_INCREMENT PRIMARY KEY,
    parent_user_id INT NOT NULL,
    title VARCHAR(100) NOT NULL,
    description TEXT,
    point_cost INT NOT NULL,
    status ENUM('available', 'redeemed') DEFAULT 'available',
    FOREIGN KEY (parent_user_id) REFERENCES users(id) ON DELETE CASCADE
)";
$db->exec($sql);

// Create goals table if not exists
$sql = "CREATE TABLE IF NOT EXISTS goals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    parent_user_id INT NOT NULL,
    child_user_id INT NOT NULL,
    title VARCHAR(100) NOT NULL,
    target_points INT NOT NULL,
    start_date DATETIME,
    end_date DATETIME,
    status ENUM('active', 'completed') DEFAULT 'active',
    reward_id INT,
    FOREIGN KEY (parent_user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (child_user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (reward_id) REFERENCES rewards(id) ON DELETE SET NULL
)";
$db->exec($sql);
?>