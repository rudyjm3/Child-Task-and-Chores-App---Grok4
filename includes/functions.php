<?php
// functions.php - Reusable utility functions
// Purpose: Centralize common operations for maintainability
// Inputs: None initially
// Outputs: Functions for app logic
// Version: 3.5.0 (Major: Reworked auth/profile: Parent-only registration, auto-child creation, family_links table for secondary parents, removed preferences)

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

// Revised: Create a child profile (now auto-creates child user and links)
function createChildProfile($parent_user_id, $child_name, $child_username, $child_password, $age, $avatar) {
    global $db;
    try {
        $db->beginTransaction();
        
        // Auto-create child user
        $hashedChildPassword = password_hash($child_password, PASSWORD_DEFAULT);
        $stmt = $db->prepare("INSERT INTO users (username, password, role) VALUES (:username, :password, 'child')");
        $stmt->execute([
            ':username' => $child_username,
            ':password' => $hashedChildPassword
        ]);
        $child_user_id = $db->lastInsertId();

        // Link in child_profiles (no preferences)
        $stmt = $db->prepare("INSERT INTO child_profiles (child_user_id, parent_user_id, child_name, age, avatar) VALUES (:child_user_id, :parent_id, :child_name, :age, :avatar)");
        $stmt->execute([
            ':child_user_id' => $child_user_id,
            ':parent_id' => $parent_user_id,
            ':child_name' => $child_name,
            ':age' => $age,
            ':avatar' => $avatar
        ]);

        // Initialize child points
        updateChildPoints($child_user_id, 0);

        $db->commit();
        return $child_user_id;
    } catch (PDOException $e) {
        $db->rollBack();
        error_log("Failed to create child profile: " . $e->getMessage());
        return false;
    }
}

// New: Add secondary parent/caregiver
function addSecondaryParent($main_parent_id, $secondary_username, $secondary_password) {
    global $db;
    try {
        $db->beginTransaction();
        
        // Create secondary user
        $hashedPassword = password_hash($secondary_password, PASSWORD_DEFAULT);
        $stmt = $db->prepare("INSERT INTO users (username, password, role, is_secondary) VALUES (:username, :password, 'parent', 1)");
        $stmt->execute([
            ':username' => $secondary_username,
            ':password' => $hashedPassword
        ]);
        $secondary_id = $db->lastInsertId();

        // Link in family_links
        $stmt = $db->prepare("INSERT INTO family_links (main_parent_id, linked_user_id, role_type) VALUES (:main_id, :linked_id, 'secondary_parent')");
        $stmt->execute([
            ':main_id' => $main_parent_id,
            ':linked_id' => $secondary_id
        ]);

        $db->commit();
        return $secondary_id;
    } catch (PDOException $e) {
        $db->rollBack();
        error_log("Failed to add secondary parent: " . $e->getMessage());
        return false;
    }
}

// Revised: Update user password (for profile edits)
function updateUserPassword($user_id, $new_password) {
    global $db;
    $hashedPassword = password_hash($new_password, PASSWORD_DEFAULT);
    $stmt = $db->prepare("UPDATE users SET password = :password WHERE id = :id");
    return $stmt->execute([
        ':password' => $hashedPassword,
        ':id' => $user_id
    ]);
}

// Revised: Update child profile (avatar, age, name)
function updateChildProfile($child_user_id, $child_name, $age, $avatar) {
    global $db;
    $stmt = $db->prepare("UPDATE child_profiles SET child_name = :child_name, age = :age, avatar = :avatar WHERE child_user_id = :child_id");
    return $stmt->execute([
        ':child_name' => $child_name,
        ':age' => $age,
        ':avatar' => $avatar,
        ':child_id' => $child_user_id
    ]);
}

// Fetch dashboard data based on user role (revised for secondary parents)
function getDashboardData($user_id) {
    global $db;
    $data = [];
    
    $userStmt = $db->prepare("SELECT role FROM users WHERE id = :id");
    $userStmt->execute([':id' => $user_id]);
    $role = $userStmt->fetchColumn();
    if ($role === false) {
        $role = 'unknown'; // Default if query fails
        error_log("Warning: No role found for user_id=$user_id, defaulting to 'unknown'");
    }

    error_log("Fetching dashboard data for user_id=$user_id, role=$role");

    if ($role === 'parent') {
        // Check if secondary parent; get main parent ID for shared data
        $main_parent_id = $user_id;
        $secondary_stmt = $db->prepare("SELECT main_parent_id FROM family_links WHERE linked_user_id = :user_id AND role_type = 'secondary_parent'");
        $secondary_stmt->execute([':user_id' => $user_id]);
        if ($secondary_id = $secondary_stmt->fetchColumn()) {
            $main_parent_stmt = $db->prepare("SELECT main_parent_id FROM family_links WHERE linked_user_id = :secondary_id");
            $main_parent_stmt->execute([':secondary_id' => $secondary_id]);
            $main_parent_id = $main_parent_stmt->fetchColumn() ?: $user_id;
        }

        $stmt = $db->prepare("SELECT cp.id, cp.child_user_id, u.username, cp.avatar, cp.age, COALESCE(cp.child_name, u.username) as child_name 
                     FROM child_profiles cp 
                     JOIN users u ON cp.child_user_id = u.id 
                     WHERE cp.parent_user_id = :parent_id");
        $stmt->execute([':parent_id' => $main_parent_id]);
        $data['children'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $db->prepare("SELECT id, title, description, point_cost, created_on FROM rewards WHERE parent_user_id = :parent_id AND status = 'available'");
        $stmt->execute([':parent_id' => $main_parent_id]);
        $data['active_rewards'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $db->prepare("SELECT r.id, r.title, r.description, r.point_cost, u.username as child_username, r.redeemed_on 
                             FROM rewards r 
                             LEFT JOIN users u ON r.redeemed_by = u.id 
                             WHERE r.parent_user_id = :parent_id AND r.status = 'redeemed'");
        $stmt->execute([':parent_id' => $main_parent_id]);
        $data['redeemed_rewards'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $db->prepare("SELECT g.id, g.title, g.target_points, g.requested_at, u.username as child_username 
                             FROM goals g 
                             JOIN child_profiles cp ON g.child_user_id = cp.child_user_id 
                             JOIN users u ON g.child_user_id = u.id 
                             WHERE cp.parent_user_id = :parent_id AND g.status = 'pending_approval'");
        $stmt->execute([':parent_id' => $main_parent_id]);
        $data['pending_approvals'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Sum total_points_earned from child_points
        $data['total_points_earned'] = 0;
        foreach ($data['children'] as $child) {
            $stmt = $db->prepare("SELECT total_points FROM child_points WHERE child_user_id = :child_id");
            $stmt->execute([':child_id' => $child['child_user_id']]);
            $data['total_points_earned'] += $stmt->fetchColumn() ?: 0;
        }

        $stmt = $db->prepare("SELECT COUNT(*) FROM goals WHERE parent_user_id = :parent_id AND status = 'completed'");
        $stmt->execute([':parent_id' => $main_parent_id]);
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
    $stmt = $db->prepare("INSERT INTO tasks (parent_user_id, child_user_id, title, description, due_date, points, recurrence, category, timing_mode) VALUES (:parent_id, :child_id, :title, :description, :due_date, :points, :recurrence, :category, :timing_mode)");
    return $stmt->execute([
        ':parent_id' => $parent_user_id,
        ':child_id' => $child_user_id,
        ':title' => $title,
        ':description' => $description,
        ':due_date' => $due_date,
        ':points' => $points,
        ':recurrence' => $recurrence,
        ':category' => $category,
        ':timing_mode' => $timing_mode
    ]);
}

// Get tasks for a user
function getTasks($user_id) {
    global $db;
    $userStmt = $db->prepare("SELECT role FROM users WHERE id = :id");
    $userStmt->execute([':id' => $user_id]);
    $role = $userStmt->fetchColumn();

    if ($role === 'parent') {
        $stmt = $db->prepare("SELECT * FROM tasks WHERE parent_user_id = :parent_id");
        $stmt->execute([':parent_id' => $user_id]);
    } else {
        $stmt = $db->prepare("SELECT * FROM tasks WHERE child_user_id = :child_id");
        $stmt->execute([':child_id' => $user_id]);
    }
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Complete a task
function completeTask($task_id, $child_id, $photo_proof = null) {
    global $db;
    $stmt = $db->prepare("UPDATE tasks SET status = 'completed', photo_proof = :photo_proof, completed_at = NOW() WHERE id = :id AND child_user_id = :child_id AND status = 'pending'");
    return $stmt->execute([':photo_proof' => $photo_proof, ':id' => $task_id, ':child_id' => $child_id]);
}

// Approve a task
function approveTask($task_id) {
    global $db;
    $stmt = $db->prepare("SELECT child_user_id, points FROM tasks WHERE id = :id AND status = 'completed'");
    $stmt->execute([':id' => $task_id]);
    $task = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($task) {
        $stmt = $db->prepare("UPDATE tasks SET status = 'approved' WHERE id = :id");
        if ($stmt->execute([':id' => $task_id])) {
            updateChildPoints($task['child_user_id'], $task['points']);
            return true;
        }
    }
    return false;
}

// Update child points
// function updateChildPoints($child_user_id, $points) {
//     global $db;
//     $stmt = $db->prepare("INSERT INTO child_points (child_user_id, total_points) VALUES (:child_id, :points) ON DUPLICATE KEY UPDATE total_points = total_points + :points");
//     return $stmt->execute([':child_id' => $child_user_id, ':points' => $points]);
// }

// Create reward
function createReward($parent_user_id, $title, $description, $point_cost) {
    global $db;
    $stmt = $db->prepare("INSERT INTO rewards (parent_user_id, title, description, point_cost) VALUES (:parent_id, :title, :description, :point_cost)");
    return $stmt->execute([
        ':parent_id' => $parent_user_id,
        ':title' => $title,
        ':description' => $description,
        ':point_cost' => $point_cost
    ]);
}

// Redeem reward
function redeemReward($child_user_id, $reward_id) {
    global $db;
    $db->beginTransaction();
    try {
        $stmt = $db->prepare("SELECT point_cost FROM rewards WHERE id = :id AND status = 'available'");
        $stmt->execute([':id' => $reward_id]);
        $point_cost = $stmt->fetchColumn();
        if (!$point_cost) {
            $db->rollBack();
            return false;
        }

        $stmt = $db->prepare("SELECT total_points FROM child_points WHERE child_user_id = :child_id");
        $stmt->execute([':child_id' => $child_user_id]);
        $total_points = $stmt->fetchColumn() ?: 0;
        if ($total_points < $point_cost) {
            $db->rollBack();
            return false;
        }

        updateChildPoints($child_user_id, -$point_cost);

        $stmt = $db->prepare("UPDATE rewards SET status = 'redeemed', redeemed_by = :child_id, redeemed_on = NOW() WHERE id = :id");
        $stmt->execute([':child_id' => $child_user_id, ':id' => $reward_id]);

        $db->commit();
        return true;
    } catch (Exception $e) {
        $db->rollBack();
        return false;
    }
}

// Create goal
function createGoal($parent_user_id, $child_user_id, $title, $target_points, $start_date, $end_date, $reward_id = null) {
    global $db;
    $stmt = $db->prepare("INSERT INTO goals (parent_user_id, child_user_id, title, target_points, start_date, end_date, reward_id) VALUES (:parent_id, :child_id, :title, :target_points, :start_date, :end_date, :reward_id)");
    return $stmt->execute([
        ':parent_id' => $parent_user_id,
        ':child_id' => $child_user_id,
        ':title' => $title,
        ':target_points' => $target_points,
        ':start_date' => $start_date,
        ':end_date' => $end_date,
        ':reward_id' => $reward_id
    ]);
}

// Request goal completion
function requestGoalCompletion($child_user_id, $goal_id) {
    global $db;
    $stmt = $db->prepare("UPDATE goals SET status = 'pending_approval', requested_at = NOW() WHERE id = :id AND child_user_id = :child_id AND status = 'active'");
    return $stmt->execute([':id' => $goal_id, ':child_id' => $child_user_id]);
}

// Approve goal
function approveGoal($parent_user_id, $goal_id, $approve = true, $comment = null) {
    global $db;
    $stmt = $db->prepare("SELECT child_user_id, target_points FROM goals WHERE id = :id AND parent_user_id = :parent_id AND status = 'pending_approval'");
    $stmt->execute([':id' => $goal_id, ':parent_id' => $parent_user_id]);
    $goal = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($goal) {
        if ($approve) {
            $stmt = $db->prepare("UPDATE goals SET status = 'completed', completed_at = NOW() WHERE id = :id");
            $stmt->execute([':id' => $goal_id]);
            updateChildPoints($goal['child_user_id'], $goal['target_points']);
            return $goal['target_points'];
        } else {
            $stmt = $db->prepare("UPDATE goals SET status = 'rejected', rejected_at = NOW(), rejection_comment = :comment WHERE id = :id");
            $stmt->execute([':id' => $goal_id, ':comment' => $comment]);
            return 0;
        }
    }
    return false;
}

// [Existing Routine Task Functions remain as previously updated - no change here]

// NEW 9/28
// **[New] Routine Task Functions **
function createRoutineTask($parent_user_id, $title, $description, $time_limit, $point_value, $category, $icon_url = null, $audio_url = null) {
    global $db;
    $stmt = $db->prepare("INSERT INTO routine_tasks (parent_user_id, title, description, time_limit, point_value, category, icon_url, audio_url) VALUES (:parent_id, :title, :description, :time_limit, :point_value, :category, :icon_url, :audio_url)");
    return $stmt->execute([
        ':parent_id' => $parent_user_id,
        ':title' => $title,
        ':description' => $description,
        ':time_limit' => $time_limit,
        ':point_value' => $point_value,
        ':category' => $category,
        ':icon_url' => $icon_url,
        ':audio_url' => $audio_url
    ]);
}

function getRoutineTasks($parent_user_id) {
    global $db;
    // Include global defaults (parent_id = 0) and parent-specific
    $stmt = $db->prepare("SELECT * FROM routine_tasks WHERE parent_user_id = 0 OR parent_user_id = :parent_id");
    $stmt->execute([':parent_id' => $parent_user_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function updateRoutineTask($routine_task_id, $updates) {
    global $db;
    $fields = [];
    $params = [':id' => $routine_task_id];
    foreach ($updates as $key => $value) {
        $fields[] = "$key = :$key";
        $params[":$key"] = $value;
    }
    $stmt = $db->prepare("UPDATE routine_tasks SET " . implode(', ', $fields) . " WHERE id = :id");
    return $stmt->execute($params);
}

function deleteRoutineTask($routine_task_id, $parent_user_id) {
    global $db;
    $stmt = $db->prepare("DELETE FROM routine_tasks WHERE id = :id AND parent_user_id = :parent_id");
    return $stmt->execute([':id' => $routine_task_id, ':parent_id' => $parent_user_id]);
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


// ADDED BELOW CODE NEW ROUTINE FUNCTION 9/28
// **[Revised] Routine Functions (now use routine_task_id instead of task_id) **
function createRoutine($parent_user_id, $child_user_id, $title, $start_time, $end_time, $recurrence, $bonus_points) {
    global $db;
    $stmt = $db->prepare("INSERT INTO routines (parent_user_id, child_user_id, title, start_time, end_time, recurrence, bonus_points) VALUES (:parent_id, :child_id, :title, :start_time, :end_time, :recurrence, :bonus_points)");
    $stmt->execute([
        ':parent_id' => $parent_user_id,
        ':child_id' => $child_user_id,
        ':title' => $title,
        ':start_time' => $start_time,
        ':end_time' => $end_time,
        ':recurrence' => $recurrence,
        ':bonus_points' => $bonus_points
    ]);
    return $db->lastInsertId();
}

function updateRoutine($routine_id, $title, $start_time, $end_time, $recurrence, $bonus_points, $parent_user_id) {
    global $db;
    $stmt = $db->prepare("UPDATE routines SET title = :title, start_time = :start_time, end_time = :end_time, recurrence = :recurrence, bonus_points = :bonus_points WHERE id = :id AND parent_user_id = :parent_id");
    return $stmt->execute([
        ':title' => $title,
        ':start_time' => $start_time,
        ':end_time' => $end_time,
        ':recurrence' => $recurrence,
        ':bonus_points' => $bonus_points,
        ':id' => $routine_id,
        ':parent_id' => $parent_user_id
    ]);
}

function deleteRoutine($routine_id, $parent_user_id) {
    global $db;
    $stmt = $db->prepare("DELETE FROM routines WHERE id = :id AND parent_user_id = :parent_id");
    return $stmt->execute([':id' => $routine_id, ':parent_id' => $parent_user_id]);
}

function addRoutineTaskToRoutine($routine_id, $routine_task_id, $sequence_order, $dependency_id = null) {
    global $db;
    $stmt = $db->prepare("INSERT INTO routines_routine_tasks (routine_id, routine_task_id, sequence_order, dependency_id) VALUES (:routine_id, :routine_task_id, :sequence_order, :dependency_id)");
    return $stmt->execute([
        ':routine_id' => $routine_id,
        ':routine_task_id' => $routine_task_id,
        ':sequence_order' => $sequence_order,
        ':dependency_id' => $dependency_id
    ]);
}

function removeRoutineTaskFromRoutine($routine_id, $routine_task_id) {
    global $db;
    $stmt = $db->prepare("DELETE FROM routines_routine_tasks WHERE routine_id = :routine_id AND routine_task_id = :routine_task_id");
    return $stmt->execute([':routine_id' => $routine_id, ':routine_task_id' => $routine_task_id]);
}

function reorderRoutineTasks($routine_id, $new_order) {  // $new_order = array(routine_task_id => order)
    global $db;
    foreach ($new_order as $routine_task_id => $order) {
        $stmt = $db->prepare("UPDATE routines_routine_tasks SET sequence_order = :order WHERE routine_id = :routine_id AND routine_task_id = :routine_task_id");
        $stmt->execute([':order' => $order, ':routine_id' => $routine_id, ':routine_task_id' => $routine_task_id]);
    }
    return true;
}

function getRoutines($user_id) {
    global $db;
    $userStmt = $db->prepare("SELECT role FROM users WHERE id = :id");
    $userStmt->execute([':id' => $user_id]);
    $role = $userStmt->fetchColumn();

    if ($role === 'parent') {
        $stmt = $db->prepare("SELECT * FROM routines WHERE parent_user_id = :parent_id");
        $stmt->execute([':parent_id' => $user_id]);
    } else {
        $stmt = $db->prepare("SELECT * FROM routines WHERE child_user_id = :child_id");
        $stmt->execute([':child_id' => $user_id]);
    }
    $routines = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($routines as &$routine) {
        $taskStmt = $db->prepare("SELECT rt.*, rrt.sequence_order, rrt.dependency_id FROM routine_tasks rt JOIN routines_routine_tasks rrt ON rt.id = rrt.routine_task_id WHERE rrt.routine_id = :routine_id ORDER BY rrt.sequence_order");
        $taskStmt->execute([':routine_id' => $routine['id']]);
        $routine['tasks'] = $taskStmt->fetchAll(PDO::FETCH_ASSOC);
    }
    return $routines;
}

function getRoutineWithTasks($routine_id) {
    global $db;
    $stmt = $db->prepare("SELECT * FROM routines WHERE id = :id");
    $stmt->execute([':id' => $routine_id]);
    $routine = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($routine) {
        $taskStmt = $db->prepare("SELECT rt.*, rrt.sequence_order FROM routine_tasks rt JOIN routines_routine_tasks rrt ON rt.id = rrt.routine_task_id WHERE rrt.routine_id = :routine_id ORDER BY rrt.sequence_order");
        $taskStmt->execute([':routine_id' => $routine_id]);
        $routine['tasks'] = $taskStmt->fetchAll(PDO::FETCH_ASSOC);
    }
    return $routine;
}

function completeRoutine($routine_id, $child_id) {
    global $db;
    try {
        $db->beginTransaction();
        $stmt = $db->prepare("SELECT * FROM routines WHERE id = :id AND child_user_id = :child_id");
        $stmt->execute([':id' => $routine_id, ':child_id' => $child_id]);
        $routine = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$routine) {
            $db->rollBack();
            return false;
        }

        // Check if all routine tasks are approved
        $stmt = $db->prepare("SELECT COUNT(*) FROM routines_routine_tasks rrt 
                             JOIN routine_tasks rt ON rrt.routine_task_id = rt.id 
                             WHERE rrt.routine_id = :routine_id AND rt.status != 'approved'");
        $stmt->execute([':routine_id' => $routine_id]);
        if ($stmt->fetchColumn() > 0) {
            $db->rollBack();
            return false;
        }

        // Check if current time is within start-end
        $current_time = new DateTime();
        $start = new DateTime(date('Y-m-d') . ' ' . $routine['start_time']);
        $end = new DateTime(date('Y-m-d') . ' ' . $routine['end_time']);
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
// ADDED ABOVE CODE NEW ROUTINE FUNCTION 9/28


// Below code commented out so Notice message does not show up on the login page
// Start session if not already started
// if (session_status() === PHP_SESSION_NONE) {
//     session_start();
// }

// Ensure all dependent tables are created in correct order with error handling
try {
    // Create users table if not exists (added is_secondary for secondary parents)
    $sql = "CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) NOT NULL UNIQUE,
        password VARCHAR(255) NOT NULL,
        role ENUM('parent', 'child') NOT NULL,
        is_secondary TINYINT(1) DEFAULT 0
    )";
    $db->exec($sql);
    error_log("Created/verified users table successfully");

   // Add child_name column if not exists (for existing databases)
   $db->exec("ALTER TABLE child_profiles ADD COLUMN IF NOT EXISTS child_name VARCHAR(50) DEFAULT NULL");
   error_log("Added/verified child_name column in child_profiles");

    // Create child_profiles table if not exists (removed preferences, added child_name)
    $sql = "CREATE TABLE IF NOT EXISTS child_profiles (
        id INT AUTO_INCREMENT PRIMARY KEY,
        child_user_id INT NOT NULL,
        parent_user_id INT NOT NULL,
        child_name VARCHAR(50),
        age INT,
        avatar VARCHAR(50),
        FOREIGN KEY (parent_user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (child_user_id) REFERENCES users(id) ON DELETE CASCADE
    )";
    $db->exec($sql);
    error_log("Created/verified child_profiles table successfully");

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
    error_log("Created/verified tasks table successfully");

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
    error_log("Created/verified rewards table successfully");

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
    error_log("Created/verified goals table successfully");

    // Create routines table if not exists
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
    error_log("Created/verified routines table successfully");

    // Create routine_tasks table if not exists
    $sql = "CREATE TABLE IF NOT EXISTS routine_tasks (
        id INT AUTO_INCREMENT PRIMARY KEY,
        parent_user_id INT NOT NULL,
        title VARCHAR(100) NOT NULL,
        description TEXT,
        time_limit INT,
        point_value INT,
        category ENUM('hygiene', 'homework', 'household') DEFAULT 'household',
        icon_url VARCHAR(255),
        audio_url VARCHAR(255),
        status ENUM('pending', 'completed', 'approved') DEFAULT 'pending',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (parent_user_id) REFERENCES users(id) ON DELETE CASCADE
    )";
    $db->exec($sql);
    error_log("Created/verified routine_tasks table successfully");

    // Create routines_routine_tasks association table if not exists
    $sql = "CREATE TABLE IF NOT EXISTS routines_routine_tasks (
        routine_id INT NOT NULL,
        routine_task_id INT NOT NULL,
        sequence_order INT NOT NULL,
        dependency_id INT DEFAULT NULL,
        PRIMARY KEY (routine_id, routine_task_id),
        FOREIGN KEY (routine_id) REFERENCES routines(id) ON DELETE CASCADE,
        FOREIGN KEY (routine_task_id) REFERENCES routine_tasks(id) ON DELETE CASCADE,
        FOREIGN KEY (dependency_id) REFERENCES routine_tasks(id) ON DELETE SET NULL
    )";
    $db->exec($sql);
    error_log("Created/verified routines_routine_tasks table successfully");

    // New: Create family_links table for secondary parents
    $sql = "CREATE TABLE IF NOT EXISTS family_links (
        id INT AUTO_INCREMENT PRIMARY KEY,
        main_parent_id INT NOT NULL,
        linked_user_id INT NOT NULL,
        role_type ENUM('child', 'secondary_parent') NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (main_parent_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (linked_user_id) REFERENCES users(id) ON DELETE CASCADE
    )";
    $db->exec($sql);
    error_log("Created/verified family_links table successfully");

    // Create child_points table if not exists
    $sql = "CREATE TABLE IF NOT EXISTS child_points (
        child_user_id INT PRIMARY KEY,
        total_points INT DEFAULT 0,
        FOREIGN KEY (child_user_id) REFERENCES users(id) ON DELETE CASCADE
    )";
    $db->exec($sql);
    error_log("Created/verified child_points table successfully");

    // Note: Pre-population of default Routine Tasks skipped to avoid foreign key constraint violation with parent_user_id = 0.
    // Parents can create initial tasks via the UI.
    error_log("Skipped pre-population of default Routine Tasks to avoid foreign key issues");
} catch (PDOException $e) {
    error_log("Table creation failed: " . $e->getMessage() . " at line " . $e->getLine());
    throw $e; // Re-throw to preserve the original error handling
}
?>
