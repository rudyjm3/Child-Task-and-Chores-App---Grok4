<?php
// functions.php - Reusable utility functions
// Purpose: Centralize common operations for maintainability
// Inputs: None initially
// Outputs: Functions for app logic
// Version: 3.4.6

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
    $result = $stmt->execute([
        ':child_user_id' => $child_user_id,
        ':parent_user_id' => $parent_user_id,
        ':avatar' => $avatar,
        ':age' => $age,
        ':preferences' => $preferences
    ]);
    if ($result) {
        // Initialize points
        updateChildPoints($child_user_id, 0);
    }
    return $result;
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

        // Sum total_points_earned from child_points
        $data['total_points_earned'] = 0;
        foreach ($data['children'] as $child) {
            $stmt = $db->prepare("SELECT total_points FROM child_points WHERE child_user_id = :child_id");
            $stmt->execute([':child_id' => $child['child_user_id']]);
            $data['total_points_earned'] += $stmt->fetchColumn() ?: 0;
        }

        $stmt = $db->prepare("SELECT COUNT(*) FROM goals WHERE parent_user_id = :parent_id AND status = 'completed'");
        $stmt->execute([':parent_id' => $user_id]);
        $data['goals_met'] = $stmt->fetchColumn();
    } elseif ($role === 'child') {
        // Fetch remaining_points from child_points
        $stmt = $db->prepare("SELECT total_points FROM child_points WHERE child_user_id = :child_id");
        $stmt->execute([':child_id' => $user_id]);
        $data['remaining_points'] = $stmt->fetchColumn() ?: 0;

        $max_points = 100; // Define a max points threshold (adjust as needed)
        $points_progress = ($data['remaining_points'] > 0 && $max_points > 0) ? min(100, round(($data['remaining_points'] / $max_points) * 100)) : 0;
        $data['points_progress'] = $points_progress;

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

        $stmt = $db->prepare("SELECT r.id, r.title, r.description, r.point_cost, r.status, r.redeemed_on 
                             FROM rewards r 
                             JOIN child_profiles cp ON r.parent_user_id = cp.parent_user_id 
                             WHERE cp.child_user_id = :child_id AND r.status = 'redeemed'");
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
        // Validate due_date
        $due_date_obj = DateTime::createFromFormat('Y-m-d\TH:i', $due_date);
        if (!$due_date_obj) {
            error_log("Invalid due_date format in createTask: $due_date");
            $db->rollBack();
            return false;
        }
        $formatted_due_date = $due_date_obj->format('Y-m-d H:i:s');
        error_log("createTask due_date input: $due_date, formatted: $formatted_due_date");

        $stmt = $db->prepare("INSERT INTO tasks (parent_user_id, child_user_id, title, description, due_date, points, recurrence, category, timing_mode, status, created_at) 
                             VALUES (:parent_user_id, :child_user_id, :title, :description, :due_date, :points, :recurrence, :category, :timing_mode, 'pending', NOW())");
        $stmt->execute([
            ':parent_user_id' => $parent_user_id,
            ':child_user_id' => $child_user_id,
            ':title' => $title,
            ':description' => $description,
            ':due_date' => $formatted_due_date,
            ':points' => $points,
            ':recurrence' => $recurrence,
            ':category' => $category,
            ':timing_mode' => $timing_mode
        ]);
        $db->commit();
        error_log("Task created by parent $parent_user_id for child $child_user_id: $title, due_date: $formatted_due_date");
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
        $stmt = $db->prepare("SELECT child_user_id, points FROM tasks WHERE id = :id AND status = 'completed'");
        $stmt->execute([':id' => $task_id]);
        $task = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$task) {
            $db->rollBack();
            return false;
        }

        $stmt = $db->prepare("UPDATE tasks SET status = 'approved' WHERE id = :id");
        $stmt->execute([':id' => $task_id]);

        updateChildPoints($task['child_user_id'], $task['points']);
        $db->commit();
        return true;
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Task approval failed: " . $e->getMessage());
        return false;
    }
}

// New: Create a new reward
function createReward($parent_user_id, $title, $description, $point_cost) {
    global $db;
    if (!isset($db) || !$db) {
        error_log("Database connection not available in createReward");
        return false;
    }
    $db->beginTransaction();
    try {
        $stmt = $db->prepare("INSERT INTO rewards (parent_user_id, title, description, point_cost, status, created_on) 
                             VALUES (:parent_user_id, :title, :description, :point_cost, 'available', NOW())");
        $stmt->execute([
            ':parent_user_id' => $parent_user_id,
            ':title' => $title,
            ':description' => $description,
            ':point_cost' => $point_cost
        ]);
        $db->commit();
        error_log("Reward created by parent $parent_user_id: $title");
        return true;
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Failed to create reward by parent $parent_user_id: " . $e->getMessage());
        return false;
    }
}

// Create a new goal
function createGoal($parent_user_id, $child_user_id, $title, $target_points, $start_date, $end_date, $reward_id) {
    global $db;
    $db->beginTransaction();
    try {
        // Validate dates
        $start_date_obj = DateTime::createFromFormat('Y-m-d\TH:i', $start_date);
        $end_date_obj = DateTime::createFromFormat('Y-m-d\TH:i', $end_date);
        if (!$start_date_obj || !$end_date_obj || $start_date_obj >= $end_date_obj) {
            error_log("Invalid date range in createGoal: start_date=$start_date, end_date=$end_date");
            $db->rollBack();
            return false;
        }
        $formatted_start_date = $start_date_obj->format('Y-m-d H:i:s');
        $formatted_end_date = $end_date_obj->format('Y-m-d H:i:s');

        $stmt = $db->prepare("INSERT INTO goals (parent_user_id, child_user_id, title, target_points, start_date, end_date, reward_id, status, created_at) 
                             VALUES (:parent_user_id, :child_user_id, :title, :target_points, :start_date, :end_date, :reward_id, 'active', NOW())");
        $stmt->execute([
            ':parent_user_id' => $parent_user_id,
            ':child_user_id' => $child_user_id,
            ':title' => $title,
            ':target_points' => $target_points,
            ':start_date' => $formatted_start_date,
            ':end_date' => $formatted_end_date,
            ':reward_id' => $reward_id
        ]);
        $db->commit();
        error_log("Goal created by parent $parent_user_id for child $child_user_id: $title");
        return true;
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Failed to create goal by parent $parent_user_id for child $child_user_id: " . $e->getMessage());
        return false;
    }
}

// New: Update a goal
function updateGoal($goal_id, $parent_user_id, $title, $target_points, $start_date, $end_date, $reward_id) {
    global $db;
    $db->beginTransaction();
    try {
        // Validate dates
        $start_date_obj = DateTime::createFromFormat('Y-m-d\TH:i', $start_date);
        $end_date_obj = DateTime::createFromFormat('Y-m-d\TH:i', $end_date);
        if (!$start_date_obj || !$end_date_obj || $start_date_obj >= $end_date_obj) {
            error_log("Invalid date range in updateGoal: start_date=$start_date, end_date=$end_date");
            $db->rollBack();
            return false;
        }
        $formatted_start_date = $start_date_obj->format('Y-m-d H:i:s');
        $formatted_end_date = $end_date_obj->format('Y-m-d H:i:s');

        $stmt = $db->prepare("UPDATE goals SET title = :title, target_points = :target_points, start_date = :start_date, 
                             end_date = :end_date, reward_id = :reward_id 
                             WHERE id = :goal_id AND parent_user_id = :parent_user_id AND status IN ('active', 'pending_approval', 'rejected')");
        $stmt->execute([
            ':title' => $title,
            ':target_points' => $target_points,
            ':start_date' => $formatted_start_date,
            ':end_date' => $formatted_end_date,
            ':reward_id' => $reward_id,
            ':goal_id' => $goal_id,
            ':parent_user_id' => $parent_user_id
        ]);
        if ($stmt->rowCount() > 0) {
            $db->commit();
            error_log("Goal $goal_id updated by parent $parent_user_id");
            return true;
        }
        $db->rollBack();
        error_log("No rows affected when updating goal $goal_id by parent $parent_user_id");
        return false;
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Failed to update goal $goal_id by parent $parent_user_id: " . $e->getMessage());
        return false;
    }
}

// New: Delete a goal
function deleteGoal($goal_id, $parent_user_id) {
    global $db;
    $db->beginTransaction();
    try {
        $stmt = $db->prepare("DELETE FROM goals WHERE id = :goal_id AND parent_user_id = :parent_user_id AND status IN ('active', 'pending_approval', 'rejected')");
        $stmt->execute([':goal_id' => $goal_id, ':parent_user_id' => $parent_user_id]);
        if ($stmt->rowCount() > 0) {
            $db->commit();
            error_log("Goal $goal_id deleted by parent $parent_user_id");
            return true;
        }
        $db->rollBack();
        error_log("No rows affected when deleting goal $goal_id by parent $parent_user_id");
        return false;
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Failed to delete goal $goal_id by parent $parent_user_id: " . $e->getMessage());
        return false;
    }
}

// Redeem a reward
function redeemReward($child_user_id, $reward_id) {
    global $db;
    $db->beginTransaction();
    try {
        $stmt = $db->prepare("SELECT total_points FROM child_points WHERE child_user_id = :child_id");
        $stmt->execute([':child_id' => $child_user_id]);
        $total_points = $stmt->fetchColumn() ?: 0;

        $stmt = $db->prepare("SELECT point_cost FROM rewards WHERE id = :reward_id AND status = 'available' FOR UPDATE");
        $stmt->execute([':reward_id' => $reward_id]);
        $reward = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$reward || $total_points < $reward['point_cost']) {
            $db->rollBack();
            return false;
        }

        $stmt = $db->prepare("UPDATE rewards SET status = 'redeemed', redeemed_by = :child_id, redeemed_on = NOW() WHERE id = :reward_id");
        $stmt->execute([':reward_id' => $reward_id, ':child_id' => $child_user_id]);

        updateChildPoints($child_user_id, -$reward['point_cost']);
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

        $stmt = $db->prepare("UPDATE goals SET status = :status, requested_at = NOW() WHERE id = :goal_id AND child_user_id = :child_user_id AND status = 'active'");
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
function approveGoal($parent_user_id, $goal_id, $approve = true, $rejection_comment = null) {
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
        $update_fields = "status = :status";
        if ($approve) {
            $update_fields .= ", completed_at = NOW(), rejected_at = NULL, rejection_comment = NULL";
        } else {
            $update_fields .= ", rejected_at = NOW(), rejection_comment = :rejection_comment";
        }
        $stmt = $db->prepare("UPDATE goals SET $update_fields WHERE id = :goal_id");
        $execute_params = [':status' => $new_status, ':goal_id' => $goal_id];
        if (!$approve) {
            $execute_params[':rejection_comment'] = $rejection_comment !== null ? $rejection_comment : '';
        }
        $stmt->execute($execute_params);

        if ($approve) {
            $target_points = $goal['target_points'];
            updateChildPoints($goal['child_user_id'], $target_points);
            $db->commit();
            error_log("Goal $goal_id approved for child {$goal['child_user_id']} with $target_points points");
            return $target_points;
        }
        $db->commit();
        error_log("Goal $goal_id rejected for child {$goal['child_user_id']}, comment: " . ($rejection_comment ?? 'none'));
        return 0;
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Goal approval/rejection failed for goal $goal_id by parent $parent_user_id: " . $e->getMessage());
        return false;
    }
}

// New: Reactivate a rejected goal
function reactivateGoal($goal_id, $parent_user_id) {
    global $db;
    $db->beginTransaction();
    try {
        $stmt = $db->prepare("UPDATE goals SET status = 'active', rejected_at = NULL, rejection_comment = NULL 
                             WHERE id = :goal_id AND parent_user_id = :parent_user_id AND status = 'rejected'");
        $stmt->execute([
            ':goal_id' => $goal_id,
            ':parent_user_id' => $parent_user_id
        ]);
        if ($stmt->rowCount() > 0) {
            $db->commit();
            error_log("Goal $goal_id reactivated by parent $parent_user_id");
            return true;
        }
        $db->rollBack();
        error_log("No rows affected when reactivating goal $goal_id by parent $parent_user_id");
        return false;
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Failed to reactivate goal $goal_id by parent $parent_user_id: " . $e->getMessage());
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

        // Award points
        $target_points = $goal['target_points'];
        updateChildPoints($child_user_id, $target_points);
        $db->commit();
        return $target_points;
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Goal completion failed: " . $e->getMessage());
        return false;
    }
}

// New: Update child points (positive to add, negative to deduct)
function updateChildPoints($child_id, $points) {
    global $db;
    try {
        $stmt = $db->prepare("INSERT INTO child_points (child_user_id, total_points) VALUES (:child_id, :points) 
                              ON DUPLICATE KEY UPDATE total_points = total_points + :points");
        $stmt->execute([':child_id' => $child_id, ':points' => $points]);
        return true;
    } catch (Exception $e) {
        error_log("Failed to update points for child $child_id by $points: " . $e->getMessage());
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
            error_log("Routine $routine_id not found for child $child_id");
            return false;
        }

        $stmt = $db->prepare("SELECT COUNT(*) FROM routine_tasks rt 
                             JOIN tasks t ON rt.task_id = t.id 
                             WHERE rt.routine_id = :routine_id AND t.status != 'approved'");
        $stmt->execute([':routine_id' => $routine_id]);
        if ($stmt->fetchColumn() > 0) {
            $db->rollBack();
            error_log("Not all tasks approved for routine $routine_id");
            return false; // Not all tasks approved
        }

        // Check if current time is within start-end (combine with current date for comparison)
        $current_time = new DateTime();
        $start = new DateTime(date('Y-m-d') . ' ' . $routine['start_time']);
        $end = new DateTime(date('Y-m-d') . ' ' . $routine['end_time']);
        // Handle case where end time is past midnight
        if ($end < $start) {
            $end->modify('+1 day');
        }
        $bonus = ($current_time >= $start && $current_time <= $end) ? $routine['bonus_points'] : 0;

        // Award bonus to child's points
        updateChildPoints($child_id, $bonus);

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

// Ensure goals table includes status, completed_at, rejected_at, and rejection_comment columns
$db->exec("ALTER TABLE goals ADD COLUMN IF NOT EXISTS status ENUM('active', 'pending_approval', 'completed', 'rejected') DEFAULT 'active'");
$db->exec("ALTER TABLE goals ADD COLUMN IF NOT EXISTS completed_at DATETIME DEFAULT NULL");
$db->exec("ALTER TABLE goals ADD COLUMN IF NOT EXISTS rejected_at DATETIME DEFAULT NULL");
$db->exec("ALTER TABLE goals ADD COLUMN IF NOT EXISTS rejection_comment TEXT DEFAULT NULL");

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
    created_on TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    redeemed_by INT,
    redeemed_on DATETIME,
    FOREIGN KEY (parent_user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (redeemed_by) REFERENCES users(id) ON DELETE SET NULL
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
    status ENUM('active', 'pending_approval', 'completed', 'rejected') DEFAULT 'active',
    reward_id INT,
    completed_at DATETIME DEFAULT NULL,
    requested_at DATETIME DEFAULT NULL,
    rejected_at DATETIME DEFAULT NULL,
    rejection_comment TEXT DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
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

// New: Create child_points table if not exists
$sql = "CREATE TABLE IF NOT EXISTS child_points (
    child_user_id INT PRIMARY KEY,
    total_points INT DEFAULT 0,
    FOREIGN KEY (child_user_id) REFERENCES users(id) ON DELETE CASCADE
)";
$db->exec($sql);
?>