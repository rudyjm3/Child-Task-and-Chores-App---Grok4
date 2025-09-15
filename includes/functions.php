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

        $stmt = $db->prepare("SELECT id, title, description, point_cost, created_on FROM rewards WHERE parent_user_id = :parent_id AND status = 'available'");
        $stmt->execute([':parent_id' => $user_id]);
        $data['active_rewards'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $db->prepare("SELECT r.id, r.title, r.description, r.point_cost, u.username as child_username, r.redeemed_on 
                             FROM rewards r 
                             LEFT JOIN users u ON r.redeemed_by = u.id 
                             WHERE r.parent_user_id = :parent_id AND r.status = 'redeemed'");
        $stmt->execute([':parent_id' => $user_id]);
        $data['redeemed_rewards'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $db->prepare("SELECT g.id, g.title, g.target_points, g.requested_at, u.username as child_username 
                             FROM goals g 
                             JOIN child_profiles cp ON g.child_user_id = cp.child_user_id 
                             JOIN users u ON g.child_user_id = u.id 
                             WHERE cp.parent_user_id = :parent_id AND g.status = 'pending_approval'");
        $stmt->execute([':parent_id' => $user_id]);
        $data['pending_approvals'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $db->prepare("SELECT COALESCE(SUM(t.points), 0) as task_points 
                             FROM tasks t 
                             JOIN child_profiles cp ON t.parent_user_id = cp.parent_user_id 
                             WHERE cp.parent_user_id = :parent_id AND t.status = 'approved'");
        $stmt->execute([':parent_id' => $user_id]);
        $task_points = $stmt->fetchColumn();

        $stmt = $db->prepare("SELECT COALESCE(SUM(g.target_points), 0) as goal_points 
                             FROM goals g 
                             JOIN child_profiles cp ON g.child_user_id = cp.child_user_id 
                             WHERE cp.parent_user_id = :parent_id AND g.status = 'completed'");
        $stmt->execute([':parent_id' => $user_id]);
        $goal_points = $stmt->fetchColumn();

        $data['total_points_earned'] = $task_points + $goal_points;
        $stmt = $db->prepare("SELECT COUNT(*) FROM goals WHERE parent_user_id = :parent_id AND status = 'completed'");
        $stmt->execute([':parent_id' => $user_id]);
        $data['goals_met'] = $stmt->fetchColumn();
    } elseif ($role === 'child') {
        $stmt = $db->prepare("SELECT COALESCE(SUM(t.points), 0) as task_points 
                             FROM tasks t 
                             JOIN child_profiles cp ON t.parent_user_id = cp.parent_user_id 
                             WHERE cp.child_user_id = :child_id AND t.status = 'approved'");
        $stmt->execute([':child_id' => $user_id]);
        $task_points = $stmt->fetchColumn();

        $stmt = $db->prepare("SELECT COALESCE(SUM(g.target_points), 0) as goal_points 
                             FROM goals g 
                             WHERE g.child_user_id = :child_id AND g.status = 'completed'");
        $stmt->execute([':child_id' => $user_id]);
        $goal_points = $stmt->fetchColumn();
        $total_points = $task_points + $goal_points;

        $stmt = $db->prepare("SELECT COALESCE(SUM(r.point_cost), 0) as redeemed_points 
                             FROM rewards r 
                             WHERE r.redeemed_by = :child_id AND r.status = 'redeemed'");
        $stmt->execute([':child_id' => $user_id]);
        $redeemed_points = $stmt->fetchColumn();
        $remaining_points = max(0, $total_points - $redeemed_points);

        $max_points = 100; // Define a max points threshold (adjust as needed)
        $points_progress = ($remaining_points > 0 && $max_points > 0) ? min(100, round(($remaining_points / $max_points) * 100)) : 0;
        $data['points_progress'] = $points_progress;
        $data['remaining_points'] = $remaining_points;

        $parentStmt = $db->prepare("SELECT parent_user_id FROM child_profiles WHERE child_user_id = :child_id LIMIT 1");
        $parentStmt->execute([':child_id' => $user_id]);
        $parent_id = $parentStmt->fetchColumn();
        if ($parent_id) {
            $stmt = $db->prepare("SELECT id, title, description, point_cost FROM rewards WHERE parent_user_id = :parent_id AND status = 'available'");
            $stmt->execute([':parent_id' => $parent_id]);
            $data['rewards'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        $stmt = $db->prepare("SELECT g.id, g.title, g.target_points, g.start_date, g.end_date, r.title as reward_title 
                             FROM goals g 
                             LEFT JOIN rewards r ON g.reward_id = r.id 
                             WHERE g.child_user_id = :child_id AND g.status = 'active'");
        $stmt->execute([':child_id' => $user_id]);
        $data['active_goals'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $db->prepare("SELECT g.id, g.title, g.target_points, g.start_date, g.end_date, g.completed_at, r.title as reward_title 
                             FROM goals g 
                             LEFT JOIN rewards r ON g.reward_id = r.id 
                             WHERE g.child_user_id = :child_id AND g.status = 'completed'");
        $stmt->execute([':child_id' => $user_id]);
        $data['completed_goals'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $db->prepare("SELECT r.id, r.title, r.description, r.point_cost, r.redeemed_on 
                             FROM rewards r 
                             WHERE r.redeemed_by = :child_id AND r.status = 'redeemed'");
        $stmt->execute([':child_id' => $user_id]);
        $data['redeemed_rewards'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    return $data;
}

// Create a new task
function createTask($parent_user_id, $child_user_id, $title, $description, $due_date, $points, $recurrence, $category, $timing_mode) {
    global $db;
    if (!isset($db) || !$db) {
        error_log("Database connection not available in createTask");
        return false;
    }
    $db->beginTransaction();
    try {
        $stmt = $db->prepare("INSERT INTO tasks (parent_user_id, child_user_id, title, description, due_date, points, recurrence, category, timing_mode, status, created_at) 
                             VALUES (:parent_user_id, :child_user_id, :title, :description, :due_date, :points, :recurrence, :category, :timing_mode, 'pending', NOW())");
        $stmt->execute([
            ':parent_user_id' => $parent_user_id,
            ':child_user_id' => $child_user_id,
            ':title' => $title,
            ':description' => $description,
            ':due_date' => $due_date,
            ':points' => $points,
            ':recurrence' => $recurrence,
            ':category' => $category,
            ':timing_mode' => $timing_mode
        ]);
        $db->commit();
        error_log("Task created by parent $parent_user_id for child $child_user_id: $title");
        return true;
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Failed to create task by parent $parent_user_id for child $child_user_id: " . $e->getMessage());
        return false;
    }
}

// Retrieve tasks for a user
function getTasks($user_id) {
    global $db;
    if (!isset($db) || !$db) {
        error_log("Database connection not available in getTasks for user_id $user_id");
        return [];
    }
    $role = $_SESSION['role'] ?? 'unknown';
    $tasks = [];

    if ($role === 'parent') {
        $query = "SELECT t.id, t.parent_user_id, t.child_user_id, t.title, t.due_date, t.points, t.status, t.category, t.timing_mode, t.description 
                  FROM tasks t 
                  WHERE t.parent_user_id = :user_id 
                  ORDER BY t.due_date ASC";
        $stmt = $db->prepare($query);
        $stmt->execute([':user_id' => $user_id]);
        $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
        error_log("Parent getTasks query for user_id $user_id: " . $query . ", Result: " . print_r($tasks, true));
    } elseif ($role === 'child') {
        $query = "SELECT t.id, t.parent_user_id, t.child_user_id, t.title, t.due_date, t.points, t.status, t.category, t.timing_mode, t.description 
                  FROM tasks t 
                  WHERE t.child_user_id = :user_id 
                  ORDER BY t.due_date ASC";
        $stmt = $db->prepare($query);
        $stmt->execute([':user_id' => $user_id]);
        $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
        error_log("Child getTasks query for user_id $user_id: " . $query . ", Result: " . print_r($tasks, true));
    } else {
        error_log("Unknown role for user_id $user_id in getTasks");
    }

    return $tasks;
}

// Complete a task (child marks as completed)
function completeTask($task_id, $child_id, $photo_proof = null) {
    global $db;
    $db->beginTransaction();
    try {
        $stmt = $db->prepare("UPDATE tasks SET status = 'completed', photo_proof = :photo_proof, completed_at = NOW() WHERE id = :id AND child_user_id = :child_id AND status = 'pending'");
        $stmt->execute([
            ':photo_proof' => $photo_proof,
            ':id' => $task_id,
            ':child_id' => $child_id
        ]);
        if ($stmt->rowCount() > 0) {
            $db->commit();
            return true;
        }
        $db->rollBack();
        return false;
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Task completion failed: " . $e->getMessage());
        return false;
    }
}

// Approve a task (parent approves)
function approveTask($task_id) {
    global $db;
    $db->beginTransaction();
    try {
        $stmt = $db->prepare("UPDATE tasks SET status = 'approved' WHERE id = :id AND status = 'completed'");
        $stmt->execute([':id' => $task_id]);
        if ($stmt->rowCount() > 0) {
            $db->commit();
            return true;
        }
        $db->rollBack();
        return false;
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Task approval failed: " . $e->getMessage());
        return false;
    }
}

// Create a new goal
function createGoal($parent_user_id, $child_user_id, $title, $target_points, $start_date, $end_date, $reward_id) {
    global $db;
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

// Create a new reward (added for reward creation functionality)
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

// Redeem a reward
function redeemReward($child_user_id, $reward_id) {
    global $db;
    $db->beginTransaction();
    try {
        // Calculate total points: tasks + goals
        $stmt = $db->prepare("SELECT COALESCE(SUM(t.points), 0) as task_points 
                             FROM tasks t 
                             JOIN child_profiles cp ON t.parent_user_id = cp.parent_user_id 
                             WHERE cp.child_user_id = :child_id AND t.status = 'approved'");
        $stmt->execute([':child_id' => $child_user_id]);
        $task_points = $stmt->fetchColumn();

        $stmt = $db->prepare("SELECT COALESCE(SUM(g.target_points), 0) as goal_points 
                             FROM goals g 
                             WHERE g.child_user_id = :child_id AND g.status = 'completed'");
        $stmt->execute([':child_id' => $child_user_id]);
        $goal_points = $stmt->fetchColumn();
        $total_points = $task_points + $goal_points;

        // Calculate already redeemed points
        $stmt = $db->prepare("SELECT COALESCE(SUM(r.point_cost), 0) as redeemed_points 
                             FROM rewards r 
                             WHERE r.redeemed_by = :child_id AND r.status = 'redeemed'");
        $stmt->execute([':child_id' => $child_user_id]);
        $redeemed_points = $stmt->fetchColumn();
        $available_points = max(0, $total_points - $redeemed_points);

        // Get reward cost
        $stmt = $db->prepare("SELECT point_cost, parent_user_id FROM rewards WHERE id = :reward_id AND status = 'available' FOR UPDATE");
        $stmt->execute([':reward_id' => $reward_id]);
        $reward = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$reward || $available_points < $reward['point_cost']) {
            $db->rollBack();
            return false;
        }

        // Update reward status, redeemed_by, and redeemed_on
        $stmt = $db->prepare("UPDATE rewards SET status = 'redeemed', redeemed_by = :child_id, redeemed_on = NOW() WHERE id = :reward_id");
        $stmt->execute([':child_id' => $child_user_id, ':reward_id' => $reward_id]);

        $db->commit();
        return true;
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Reward redemption failed: " . $e->getMessage());
        return false;
    }
}

// Request goal completion
function requestGoalCompletion($child_user_id, $goal_id) {
    global $db;
    if (!isset($db) || !$db) {
        error_log("Database connection not available in requestGoalCompletion");
        return false;
    }
    $db->beginTransaction();
    try {
        error_log("Attempting to request completion for goal $goal_id by child $child_user_id");
        $checkStmt = $db->prepare("SELECT status, requested_at FROM goals WHERE id = :goal_id AND child_user_id = :child_user_id");
        $checkStmt->execute([':goal_id' => $goal_id, ':child_user_id' => $child_user_id]);
        $current_data = $checkStmt->fetch(PDO::FETCH_ASSOC);
        $current_status = $current_data['status'] ?? 'NULL';
        $current_requested_at = $current_data['requested_at'] ?? 'NULL';
        error_log("Current status for goal $goal_id: $current_status, requested_at: $current_requested_at");

        $stmt = $db->prepare("UPDATE goals SET status = :status, requested_at = NOW() WHERE id = :goal_id AND child_user_id = :child_user_id");
        $result = $stmt->execute([
            ':goal_id' => $goal_id,
            ':child_user_id' => $child_user_id,
            ':status' => 'pending_approval'
        ]);
        $rows_affected = $stmt->rowCount();
        if ($result && $rows_affected > 0) {
            $db->commit();
            error_log("Goal $goal_id requested for approval by child $child_user_id. Rows affected: $rows_affected");
            $checkStmt->execute([':goal_id' => $goal_id, ':child_user_id' => $child_user_id]);
            $post_update_data = $checkStmt->fetch(PDO::FETCH_ASSOC);
            $post_status = $post_update_data['status'] ?? 'NULL';
            $post_requested_at = $post_update_data['requested_at'] ?? 'NULL';
            error_log("Post-update status for goal $goal_id: $post_status, requested_at: $post_requested_at");
            return true;
        }
        $db->rollBack();
        error_log("Failed to request goal $goal_id approval for child $child_user_id. Rows affected: $rows_affected. Current status: $current_status");
        return false;
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Goal completion request failed for goal $goal_id by child $child_user_id: " . $e->getMessage());
        return false;
    }
}

// Approve or reject goal completion
function approveGoal($parent_user_id, $goal_id, $approve = true) {
    global $db;
    if (!isset($db) || !$db) {
        error_log("Database connection not available in approveGoal");
        return false;
    }
    $db->beginTransaction();
    try {
        error_log("Attempting to approve/reject goal $goal_id by parent $parent_user_id");
        $stmt = $db->prepare("SELECT g.target_points, cp.child_user_id FROM goals g 
                             JOIN child_profiles cp ON g.child_user_id = cp.child_user_id 
                             WHERE g.id = :goal_id AND cp.parent_user_id = :parent_user_id AND g.status = 'pending_approval'");
        $stmt->execute([':goal_id' => $goal_id, ':parent_user_id' => $parent_user_id]);
        $goal = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$goal) {
            $db->rollBack();
            error_log("No pending goal $goal_id found for parent $parent_user_id");
            return false;
        }

        $new_status = $approve ? 'completed' : 'rejected';
        $stmt = $db->prepare("UPDATE goals SET status = :status, " . ($approve ? "completed_at = NOW()" : "rejected_at = NOW()") . " WHERE id = :goal_id");
        $stmt->execute([':status' => $new_status, ':goal_id' => $goal_id]);

        if ($approve) {
            $target_points = $goal['target_points'];
            $db->commit();
            error_log("Goal $goal_id approved for child {$goal['child_user_id']} with $target_points points");
            return $target_points;
        }
        $db->commit();
        error_log("Goal $goal_id rejected for child {$goal['child_user_id']}");
        return 0;
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Goal approval failed for goal $goal_id by parent $parent_user_id: " . $e->getMessage());
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

// New: Create a new routine
function createRoutine($parent_id, $child_id, $title, $start_time, $end_time, $recurrence, $bonus_points) {
    global $db;
    if (!isset($db) || !$db) {
        error_log("Database connection not available in createRoutine");
        return false;
    }
    $db->beginTransaction();
    try {
        $stmt = $db->prepare("INSERT INTO routines (parent_user_id, child_user_id, title, start_time, end_time, recurrence, bonus_points, created_at) 
                             VALUES (:parent_id, :child_id, :title, :start_time, :end_time, :recurrence, :bonus_points, NOW())");
        $stmt->execute([
            ':parent_id' => $parent_id,
            ':child_id' => $child_id,
            ':title' => $title,
            ':start_time' => $start_time,
            ':end_time' => $end_time,
            ':recurrence' => $recurrence,
            ':bonus_points' => $bonus_points
        ]);
        $routine_id = $db->lastInsertId();
        $db->commit();
        error_log("Routine created by parent $parent_id for child $child_id: $title (ID: $routine_id)");
        return $routine_id;
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Failed to create routine by parent $parent_id for child $child_id: " . $e->getMessage());
        return false;
    }
}

// New: Update a routine
function updateRoutine($routine_id, $title, $start_time, $end_time, $recurrence, $bonus_points, $parent_id) {
    global $db;
    $db->beginTransaction();
    try {
        $stmt = $db->prepare("UPDATE routines SET title = :title, start_time = :start_time, end_time = :end_time, recurrence = :recurrence, bonus_points = :bonus_points 
                             WHERE id = :routine_id AND parent_user_id = :parent_id");
        $stmt->execute([
            ':title' => $title,
            ':start_time' => $start_time,
            ':end_time' => $end_time,
            ':recurrence' => $recurrence,
            ':bonus_points' => $bonus_points,
            ':routine_id' => $routine_id,
            ':parent_id' => $parent_id
        ]);
        if ($stmt->rowCount() > 0) {
            $db->commit();
            return true;
        }
        $db->rollBack();
        return false;
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Routine update failed for ID $routine_id: " . $e->getMessage());
        return false;
    }
}

// New: Delete a routine
function deleteRoutine($routine_id, $parent_id) {
    global $db;
    $db->beginTransaction();
    try {
        $stmt = $db->prepare("DELETE FROM routines WHERE id = :routine_id AND parent_user_id = :parent_id");
        $stmt->execute([':routine_id' => $routine_id, ':parent_id' => $parent_id]);
        if ($stmt->rowCount() > 0) {
            $db->commit();
            return true;
        }
        $db->rollBack();
        return false;
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Routine deletion failed for ID $routine_id: " . $e->getMessage());
        return false;
    }
}

// New: Add task to routine with order
function addTaskToRoutine($routine_id, $task_id, $order) {
    global $db;
    try {
        $stmt = $db->prepare("INSERT INTO routine_tasks (routine_id, task_id, sequence_order) VALUES (:routine_id, :task_id, :order)");
        $stmt->execute([':routine_id' => $routine_id, ':task_id' => $task_id, ':order' => $order]);
        return true;
    } catch (Exception $e) {
        error_log("Failed to add task $task_id to routine $routine_id: " . $e->getMessage());
        return false;
    }
}

// New: Remove task from routine
function removeTaskFromRoutine($routine_id, $task_id) {
    global $db;
    try {
        $stmt = $db->prepare("DELETE FROM routine_tasks WHERE routine_id = :routine_id AND task_id = :task_id");
        $stmt->execute([':routine_id' => $routine_id, ':task_id' => $task_id]);
        return true;
    } catch (Exception $e) {
        error_log("Failed to remove task $task_id from routine $routine_id: " . $e->getMessage());
        return false;
    }
}

// New: Reorder tasks in routine (update orders)
function reorderRoutineTasks($routine_id, $task_orders) { // $task_orders = array(task_id => new_order)
    global $db;
    $db->beginTransaction();
    try {
        foreach ($task_orders as $task_id => $order) {
            $stmt = $db->prepare("UPDATE routine_tasks SET sequence_order = :order WHERE routine_id = :routine_id AND task_id = :task_id");
            $stmt->execute([':order' => $order, ':routine_id' => $routine_id, ':task_id' => $task_id]);
        }
        $db->commit();
        return true;
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Routine task reorder failed for ID $routine_id: " . $e->getMessage());
        return false;
    }
}

// New: Get routines for user (with associated tasks sorted by order)
function getRoutines($user_id) {
    global $db;
    if (!isset($db) || !$db) {
        error_log("Database connection not available in getRoutines for user_id $user_id");
        return [];
    }
    $role = $_SESSION['role'] ?? 'unknown';
    $routines = [];

    if ($role === 'parent') {
        $stmt = $db->prepare("SELECT r.* FROM routines r WHERE r.parent_user_id = :user_id ORDER BY r.title ASC");
        $stmt->execute([':user_id' => $user_id]);
        $routines = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($role === 'child') {
        $stmt = $db->prepare("SELECT r.* FROM routines r WHERE r.child_user_id = :user_id ORDER BY r.title ASC");
        $stmt->execute([':user_id' => $user_id]);
        $routines = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Fetch associated tasks for each routine
    foreach ($routines as &$routine) {
        $stmt = $db->prepare("SELECT t.* FROM tasks t 
                             JOIN routine_tasks rt ON t.id = rt.task_id 
                             WHERE rt.routine_id = :routine_id ORDER BY rt.sequence_order ASC");
        $stmt->execute([':routine_id' => $routine['id']]);
        $routine['tasks'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    unset($routine);

    error_log("getRoutines for user_id $user_id (" . $role . "): " . print_r($routines, true));
    return $routines;
}

// New: Complete a routine (check all tasks approved, award bonus if within timeframe)
function completeRoutine($routine_id, $child_id) {
    global $db;
    $db->beginTransaction();
    try {
        // Fetch routine and check if all tasks are approved
        $stmt = $db->prepare("SELECT r.start_time, r.end_time, r.bonus_points FROM routines r WHERE r.id = :routine_id AND r.child_user_id = :child_id");
        $stmt->execute([':routine_id' => $routine_id, ':child_id' => $child_id]);
        $routine = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$routine) {
            $db->rollBack();
            return false;
        }

        $stmt = $db->prepare("SELECT COUNT(*) FROM routine_tasks rt 
                             JOIN tasks t ON rt.task_id = t.id 
                             WHERE rt.routine_id = :routine_id AND t.status != 'approved'");
        $stmt->execute([':routine_id' => $routine_id]);
        if ($stmt->fetchColumn() > 0) {
            $db->rollBack();
            return false; // Not all tasks approved
        }

        // Check if current time is within start-end (assuming daily time, ignore date)
        $current_time = date('H:i:s');
        $start = $routine['start_time'];
        $end = $routine['end_time'];
        $bonus = ($current_time >= $start && $current_time <= $end) ? $routine['bonus_points'] : 0;

        // Award bonus (add to child's points; assume a points table or update total, here simplify as log)
        error_log("Routine $routine_id completed by child $child_id with bonus $bonus");

        $db->commit();
        return $bonus;
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Routine completion failed for ID $routine_id: " . $e->getMessage());
        return false;
    }
}

// Below code commented out so Notice message does not show up on the login page
// Start session if not already started
// if (session_status() === PHP_SESSION_NONE) {
//     session_start();
// }

// Initial table creation (remove after setup)
// if (!createDatabaseTables()) {
//     die("Failed to initialize database tables.");
// }

// Ensure goals table includes status and completed_at columns
$db->exec("ALTER TABLE goals ADD COLUMN IF NOT EXISTS status ENUM('active', 'pending_approval', 'completed', 'rejected') DEFAULT 'active'");
$db->exec("ALTER TABLE goals ADD COLUMN IF NOT EXISTS completed_at DATETIME DEFAULT NULL");

// Ensure rewards table includes created_on column (added for timestamping)
$db->exec("ALTER TABLE rewards ADD COLUMN IF NOT EXISTS created_on TIMESTAMP DEFAULT CURRENT_TIMESTAMP");

// Ensure rewards table includes redeemed_by and redeemed_on
$db->exec("ALTER TABLE rewards ADD COLUMN IF NOT EXISTS redeemed_by INT NULL");
$db->exec("ALTER TABLE rewards ADD COLUMN IF NOT EXISTS redeemed_on DATETIME NULL");
$db->exec("ALTER TABLE rewards ADD FOREIGN KEY IF NOT EXISTS (redeemed_by) REFERENCES users(id) ON DELETE SET NULL");

// Create users table if not exists
$sql = "CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('parent', 'child') NOT NULL
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
    parent_user_id INT NOT NULL,
    child_user_id INT NOT NULL,
    title VARCHAR(100) NOT NULL,
    description TEXT,
    due_date DATETIME,
    points INT,
    recurrence ENUM('daily', 'weekly', '') DEFAULT '',
    category ENUM('hygiene', 'homework', 'household') DEFAULT 'household',
    timing_mode ENUM('timer', 'suggested', 'no_limit') DEFAULT 'no_limit',
    status ENUM('pending', 'completed', 'approved') DEFAULT 'pending',
    photo_proof VARCHAR(255),
    completed_at DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (parent_user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (child_user_id) REFERENCES users(id) ON DELETE CASCADE
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

// New: Create routines table if not exists
$sql = "CREATE TABLE IF NOT EXISTS routines (
    id INT AUTO_INCREMENT PRIMARY KEY,
    parent_user_id INT NOT NULL,
    child_user_id INT NOT NULL,
    title VARCHAR(100) NOT NULL,
    start_time TIME,
    end_time TIME,
    recurrence ENUM('daily', 'weekly', '') DEFAULT '',
    bonus_points INT DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (parent_user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (child_user_id) REFERENCES users(id) ON DELETE CASCADE
)";
$db->exec($sql);

// New: Create routine_tasks association table if not exists
$sql = "CREATE TABLE IF NOT EXISTS routine_tasks (
    routine_id INT NOT NULL,
    task_id INT NOT NULL,
    sequence_order INT NOT NULL,
    PRIMARY KEY (routine_id, task_id),
    FOREIGN KEY (routine_id) REFERENCES routines(id) ON DELETE CASCADE,
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE
)";
$db->exec($sql);
?>