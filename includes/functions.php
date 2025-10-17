<?php
// functions.php - Reusable utility functions
// Purpose: Centralize common operations for maintainability
// Inputs: None initially
// Outputs: Functions for app logic
// Version: 3.5.2 (Fixed addLinkedUser to use first/last name instead of name, for consistency with child profiles)

require_once __DIR__ . '/db_connect.php';

// Calculate age from birthday
function calculateAge($birthday) {
    if (!$birthday) return null;
    $birthdayDate = new DateTime($birthday);
    $today = new DateTime();
    $age = $birthdayDate->diff($today)->y;
    return $age;
}

// Update database schema for first/last name
$db->exec("ALTER TABLE users 
    ADD COLUMN IF NOT EXISTS first_name VARCHAR(50) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS last_name VARCHAR(50) DEFAULT NULL");

// Register a new user (revised for first/last name and gender)
function registerUser($username, $password, $role, $first_name = null, $last_name = null, $gender = null) {
    global $db;
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $db->prepare("INSERT INTO users (username, password, role, first_name, last_name, gender) 
                         VALUES (:username, :password, :role, :first_name, :last_name, :gender)");
    return $stmt->execute([
        ':username' => $username,
        ':password' => $hashedPassword,
        ':role' => $role,
        ':first_name' => $first_name,
        ':last_name' => $last_name,
        ':gender' => $gender
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

// Helper: get normalized role for a user (maps legacy 'parent' to 'main_parent')
function getUserRole($user_id) {
    global $db;
    $stmt = $db->prepare("SELECT role FROM users WHERE id = :id");
    $stmt->execute([':id' => $user_id]);
    $role = $stmt->fetchColumn();
    if ($role === 'parent') return 'main_parent'; // legacy mapping
    return $role;
}

// Permission helper: returns true if user is main parent OR a family member linked as secondary_parent
function userCanManageAll($user_id) {
    global $db;
    $role = getUserRole($user_id);
    if ($role === 'main_parent') return true;
    if ($role === 'family_member') {
        $stmt = $db->prepare("SELECT 1 FROM family_links WHERE linked_user_id = :id AND role_type = 'secondary_parent' LIMIT 1");
        $stmt->execute([':id' => $user_id]);
        if ($stmt->fetchColumn()) return true;
    }
    return false;
}

// Simple helpers
function isCaregiver($user_id) {
    return getUserRole($user_id) === 'caregiver';
}

function isFamilyMember($user_id) {
    return getUserRole($user_id) === 'family_member';
}

function canCreateContent($user_id) {
    $role = getUserRole($user_id);
    return in_array($role, ['main_parent', 'secondary_parent', 'family_member', 'caregiver']);
}

function canAddEditChild($user_id) {
    $role = getUserRole($user_id);
    return in_array($role, ['main_parent', 'secondary_parent']);
}

function canAddEditCaregiver($user_id) {
    return canAddEditChild($user_id); // same restriction
}

function canAddEditFamilyMember($user_id) {
    $role = getUserRole($user_id);
    return in_array($role, ['main_parent', 'secondary_parent', 'family_member']);
}

// Revised: Create a child profile (now auto-creates child user and links, with name)
function createChildProfile($parent_user_id, $first_name, $last_name, $child_username, $child_password, $birthday, $avatar, $gender) {
    global $db;
    try {
        $db->beginTransaction();
        
        // Create child user
        $hashedChildPassword = password_hash($child_password, PASSWORD_DEFAULT);
        $stmt = $db->prepare("INSERT INTO users (username, password, role, first_name, last_name, gender) 
                             VALUES (:username, :password, 'child', :first_name, :last_name, :gender)");
        if (!$stmt->execute([
            ':username' => $child_username,
            ':password' => $hashedChildPassword,
            ':first_name' => $first_name,
            ':last_name' => $last_name,
            ':gender' => $gender
        ])) {
            $db->rollBack();
            return false;
        }
        $child_user_id = $db->lastInsertId();

        // Create child profile
        $stmt = $db->prepare("INSERT INTO child_profiles (child_user_id, parent_user_id, child_name, birthday, avatar) 
                             VALUES (:child_user_id, :parent_id, :child_name, :birthday, :avatar)");
        if (!$stmt->execute([
            ':child_user_id' => $child_user_id,
            ':parent_id' => $parent_user_id,
            ':child_name' => $first_name . ' ' . $last_name,
            ':birthday' => $birthday,
            ':avatar' => $avatar
        ])) {
            $db->rollBack();
            return false;
        }

        $db->commit();
        return $child_user_id;
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Failed to create child profile: " . $e->getMessage());
        return false;
    }
}

// New: Add linked user (secondary parent, family member, or caregiver)
// $roleType should be one of: 'secondary_parent', 'family_member', 'caregiver'
function addLinkedUser($main_parent_id, $username, $password, $first_name, $last_name, $roleType = 'secondary_parent') {
    global $db;
    $allowed = ['secondary_parent', 'family_member', 'caregiver'];
    if (!in_array($roleType, $allowed)) $roleType = 'family_member';
    try {
        $db->beginTransaction();

        // Map roleType to users.role
        $mappedRole = ($roleType === 'caregiver') ? 'caregiver' : 'family_member';

        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $db->prepare("INSERT INTO users (username, password, role, first_name, last_name) VALUES (:username, :password, :role, :first_name, :last_name)");
        $stmt->execute([
            ':username' => $username,
            ':password' => $hashedPassword,
            ':role' => $mappedRole,
            ':first_name' => $first_name,
            ':last_name' => $last_name
        ]);
        $linked_id = $db->lastInsertId();

        // Link in family_links with role_type
        $stmt = $db->prepare("INSERT INTO family_links (main_parent_id, linked_user_id, role_type) VALUES (:main_id, :linked_id, :role_type)");
        $stmt->execute([
            ':main_id' => $main_parent_id,
            ':linked_id' => $linked_id,
            ':role_type' => $roleType
        ]);

        $db->commit();
        return $linked_id;
    } catch (PDOException $e) {
        $db->rollBack();
        error_log("Failed to add linked user: " . $e->getMessage());
        return false;
    }
}

// Revised: Update user password
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
function updateChildProfile($child_user_id, $first_name, $last_name, $birthday, $avatar) {
    global $db;
    $age = calculateAge($birthday);
    try {
        $db->beginTransaction();
        
        // Update child_profiles table
        $stmt = $db->prepare("UPDATE child_profiles 
                             SET child_name = :child_name, 
                                 birthday = :birthday,
                                 age = :age,
                                 avatar = :avatar 
                             WHERE child_user_id = :child_id");
        $stmt->execute([
            ':child_name' => $first_name . ' ' . $last_name,
            ':birthday' => $birthday,
            ':age' => $age,
            ':avatar' => $avatar,
            ':child_id' => $child_user_id
        ]);
        
        // Also update users table
        $stmt = $db->prepare("UPDATE users 
                             SET first_name = :first_name,
                                 last_name = :last_name 
                             WHERE id = :user_id");
        $stmt->execute([
            ':first_name' => $first_name,
            ':last_name' => $last_name,
            ':user_id' => $child_user_id
        ]);

        // Update users table
        $stmt = $db->prepare("UPDATE users 
                             SET first_name = :first_name,
                                 last_name = :last_name
                             WHERE id = :id");
        $stmt->execute([
            ':first_name' => $first_name,
            ':last_name' => $last_name,
            ':id' => $child_user_id
        ]);

        $db->commit();
        return true;
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Failed to update child profile: " . $e->getMessage());
        return false;
    }
    // Update users table name too
    $stmt = $db->prepare("UPDATE users SET name = :name WHERE id = :id");
    return $stmt->execute([
        ':name' => $child_name,
        ':id' => $child_user_id
    ]);
}

// Revised: getDashboardData (name display, caregiver access)
function getDashboardData($user_id) {
    global $db;
    $data = [];
    
    $userStmt = $db->prepare("SELECT role, name FROM users WHERE id = :id");
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
      $main_parent_from_link = $secondary_stmt->fetchColumn();
      if ($main_parent_from_link) {
         $main_parent_id = $main_parent_from_link;
      }

      // ******

        // Revised: Use name display
        $stmt = $db->prepare("SELECT cp.id, cp.child_user_id, COALESCE(u.name, u.username) as display_name, cp.avatar, cp.age, cp.child_name
                     FROM child_profiles cp 
                     JOIN users u ON cp.child_user_id = u.id 
                     WHERE cp.parent_user_id = :parent_id");
        $stmt->execute([':parent_id' => $main_parent_id]);
        $data['children'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $db->prepare("SELECT id, title, description, point_cost, created_on FROM rewards WHERE parent_user_id = :parent_id AND status = 'available'");
        $stmt->execute([':parent_id' => $main_parent_id]);
        $data['active_rewards'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $db->prepare("SELECT r.id, r.title, r.description, r.point_cost, COALESCE(u.name, u.username) as child_username, r.redeemed_on 
                     FROM rewards r 
                     LEFT JOIN users u ON r.redeemed_by = u.id 
                     WHERE r.redeemed_by = :child_id AND r.status = 'redeemed'");
        $stmt->execute([':child_id' => $user_id]);
        $data['redeemed_rewards'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $db->prepare("SELECT g.id, g.title, g.target_points, g.requested_at, COALESCE(u.name, u.username) as child_username 
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

        $stmt = $db->prepare("SELECT r.id, r.title, r.description, r.point_cost, COALESCE(u.name, u.username) as child_username, r.redeemed_on 
                     FROM rewards r 
                     LEFT JOIN users u ON r.redeemed_by = u.id 
                     WHERE r.redeemed_by = :child_id AND r.status = 'redeemed'");
        $stmt->execute([':child_id' => $user_id]);
        $data['redeemed_rewards'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    return $data;
}

// Create a new task
function createTask($parent_user_id, $child_user_id, $title, $description, $due_date, $points, $recurrence, $category, $timing_mode) {
    global $db;
    $stmt = $db->prepare("INSERT INTO tasks (parent_user_id, child_user_id, title, description, due_date, points, recurrence, category, timing_mode, created_by) VALUES (:parent_id, :child_id, :title, :description, :due_date, :points, :recurrence, :category, :timing_mode, :created_by)");
   return $stmt->execute([
      ':parent_id' => $parent_user_id,
      ':child_id' => $child_user_id,
      ':title' => $title,
      ':description' => $description,
      ':due_date' => $due_date,
      ':points' => $points,
      ':recurrence' => $recurrence,
      ':category' => $category,
      ':timing_mode' => $timing_mode,
      ':created_by' => $parent_user_id
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

// Create reward
function createReward($parent_user_id, $title, $description, $point_cost) {
    global $db;
   $stmt = $db->prepare("INSERT INTO rewards (parent_user_id, title, description, point_cost, created_by) VALUES (:parent_id, :title, :description, :point_cost, :created_by)");
   return $stmt->execute([
      ':parent_id' => $parent_user_id,
      ':title' => $title,
      ':description' => $description,
      ':point_cost' => $point_cost,
      ':created_by' => $parent_user_id
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
    $stmt = $db->prepare("INSERT INTO goals (parent_user_id, child_user_id, title, target_points, start_date, end_date, reward_id, created_by) VALUES (:parent_id, :child_id, :title, :target_points, :start_date, :end_date, :reward_id, :created_by)");
    return $stmt->execute([
        ':parent_id' => $parent_user_id,
        ':child_id' => $child_user_id,
        ':title' => $title,
        ':target_points' => $target_points,
        ':start_date' => $start_date,
        ':end_date' => $end_date,
        ':reward_id' => $reward_id,
        ':created_by' => $parent_user_id
    ]);
}

// Keep existing updateGoal function (for editing goal details)
function updateGoal($goal_id, $parent_user_id, $title, $target_points, $start_date, $end_date, $reward_id = null) {
    global $db;
    $stmt = $db->prepare("UPDATE goals 
                         SET title = :title, 
                             target_points = :target_points, 
                             start_date = :start_date, 
                             end_date = :end_date, 
                             reward_id = :reward_id 
                         WHERE id = :goal_id 
                         AND parent_user_id = :parent_id");
    return $stmt->execute([
        ':goal_id' => $goal_id,
        ':parent_id' => $parent_user_id,
        ':title' => $title,
        ':target_points' => $target_points,
        ':start_date' => $start_date,
        ':end_date' => $end_date,
        ':reward_id' => $reward_id
    ]);
}

// Add back requestGoalCompletion function
function requestGoalCompletion($goal_id, $child_user_id) {
    global $db;
    $stmt = $db->prepare("UPDATE goals 
                         SET status = 'pending_approval', 
                             requested_at = NOW() 
                         WHERE id = :goal_id 
                         AND child_user_id = :child_id 
                         AND status = 'active'");
    return $stmt->execute([
        ':goal_id' => $goal_id,
        ':child_id' => $child_user_id
    ]);
}

// Add back approveGoal function
function approveGoal($goal_id, $parent_user_id) {
    global $db;
    try {
        $db->beginTransaction();
        
        $stmt = $db->prepare("SELECT child_user_id, target_points 
                             FROM goals 
                             WHERE id = :goal_id 
                             AND parent_user_id = :parent_id 
                             AND status = 'pending_approval'");
        $stmt->execute([
            ':goal_id' => $goal_id,
            ':parent_id' => $parent_user_id
        ]);
        $goal = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($goal) {
            // Update goal status to completed
            $stmt = $db->prepare("UPDATE goals 
                                SET status = 'completed', 
                                    completed_at = NOW() 
                                WHERE id = :goal_id");
            $stmt->execute([':goal_id' => $goal_id]);
            
            // Award points to child
            updateChildPoints($goal['child_user_id'], $goal['target_points']);
            
            $db->commit();
            return true;
        }
        
        $db->rollBack();
        return false;
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Goal approval failed: " . $e->getMessage());
        return false;
    }
}

// Add back rejectGoal function
function rejectGoal($goal_id, $parent_user_id, $rejection_comment) {
    global $db;
    $stmt = $db->prepare("UPDATE goals 
                         SET status = 'rejected', 
                             rejected_at = NOW(), 
                             rejection_comment = :comment 
                         WHERE id = :goal_id 
                         AND parent_user_id = :parent_id 
                         AND status = 'pending_approval'");
    return $stmt->execute([
        ':goal_id' => $goal_id,
        ':parent_id' => $parent_user_id,
        ':comment' => $rejection_comment
    ]);
}

// **[New] Routine Task Functions **
function createRoutineTask($parent_user_id, $title, $description, $time_limit, $point_value, $category, $icon_url = null, $audio_url = null) {
    global $db;
    $stmt = $db->prepare("INSERT INTO routine_tasks (parent_user_id, title, description, time_limit, point_value, category, icon_url, audio_url, created_by) VALUES (:parent_id, :title, :description, :time_limit, :point_value, :category, :icon_url, :audio_url, :created_by)");
    return $stmt->execute([
        ':parent_id' => $parent_user_id,
        ':title' => $title,
        ':description' => $description,
        ':time_limit' => $time_limit,
        ':point_value' => $point_value,
        ':category' => $category,
        ':icon_url' => $icon_url,
        ':audio_url' => $audio_url,
        ':created_by' => $parent_user_id
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

// Delete goal
function deleteGoal($goal_id, $parent_user_id) {
    global $db;
    try {
        $db->beginTransaction();
        
        // First verify the goal belongs to this parent
        $stmt = $db->prepare("SELECT id FROM goals 
                             WHERE id = :goal_id 
                             AND parent_user_id = :parent_id");
        $stmt->execute([
            ':goal_id' => $goal_id,
            ':parent_id' => $parent_user_id
        ]);
        
        if ($stmt->fetch()) {
            // If found, delete the goal
            $stmt = $db->prepare("DELETE FROM goals 
                                WHERE id = :goal_id 
                                AND parent_user_id = :parent_id");
            $result = $stmt->execute([
                ':goal_id' => $goal_id,
                ':parent_id' => $parent_user_id
            ]);
            
            $db->commit();
            return $result;
        }
        
        $db->rollBack();
        return false;
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Failed to delete goal $goal_id: " . $e->getMessage());
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

// **[Revised] Routine Functions (now use routine_task_id instead of task_id) **
function createRoutine($parent_user_id, $child_user_id, $title, $start_time, $end_time, $recurrence, $bonus_points) {
    global $db;
    $stmt = $db->prepare("INSERT INTO routines (parent_user_id, child_user_id, title, start_time, end_time, recurrence, bonus_points, created_by) VALUES (:parent_id, :child_id, :title, :start_time, :end_time, :recurrence, :bonus_points, :created_by)");
    $stmt->execute([
        ':parent_id' => $parent_user_id,
        ':child_id' => $child_user_id,
        ':title' => $title,
        ':start_time' => $start_time,
        ':end_time' => $end_time,
        ':recurrence' => $recurrence,
        ':bonus_points' => $bonus_points,
        ':created_by' => $parent_user_id
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
        role ENUM('main_parent', 'family_member', 'caregiver', 'child') NOT NULL,
        is_secondary TINYINT(1) DEFAULT 0
    )";
    $db->exec($sql);
    error_log("Created/verified users table successfully");

    // Add name column to users if not exists
   $db->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS name VARCHAR(50) DEFAULT NULL");
   $db->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS gender ENUM('male', 'female') DEFAULT NULL");
   error_log("Added/verified name and gender columns in users");

    // Create child_profiles table if not exists (removed preferences, added child_name)
   $sql = "CREATE TABLE IF NOT EXISTS child_profiles (
      id INT AUTO_INCREMENT PRIMARY KEY,
      child_user_id INT NOT NULL,
      parent_user_id INT NOT NULL,
      child_name VARCHAR(50),
      age INT,
      avatar VARCHAR(255),
      birthday DATE DEFAULT NULL,
      FOREIGN KEY (parent_user_id) REFERENCES users(id) ON DELETE CASCADE,
      FOREIGN KEY (child_user_id) REFERENCES users(id) ON DELETE CASCADE
   )";
   $db->exec($sql);
   error_log("Created/verified child_profiles table successfully");

   // Add avatar column size if not exists (for existing databases)
   $db->exec("ALTER TABLE child_profiles MODIFY COLUMN IF EXISTS avatar VARCHAR(255)");
   error_log("Updated avatar column size to VARCHAR(255)");

   // Add child_name column column if not exists (for existing databases)
   $db->exec("ALTER TABLE child_profiles ADD COLUMN IF NOT EXISTS child_name VARCHAR(50) DEFAULT NULL");
   error_log("Added/verified child_name column in child_profiles");

    // Create tasks table if not exists (added created_by)
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
      created_by INT NULL,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      FOREIGN KEY (parent_user_id) REFERENCES users(id) ON DELETE CASCADE,
      FOREIGN KEY (child_user_id) REFERENCES users(id) ON DELETE CASCADE,
      FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
   )";
   $db->exec($sql);
   error_log("Created/verified tasks table successfully");

   // Add created_by to existing tasks if not exists
   $db->exec("ALTER TABLE tasks ADD COLUMN IF NOT EXISTS created_by INT NULL");
   error_log("Added/verified created_by in tasks");

   // Create rewards table if not exists (added created_by)
   $sql = "CREATE TABLE IF NOT EXISTS rewards (
   id INT AUTO_INCREMENT PRIMARY KEY,
   parent_user_id INT NOT NULL,
   title VARCHAR(100) NOT NULL,
   description TEXT,
   point_cost INT NOT NULL,
   status ENUM('available', 'redeemed') DEFAULT 'available',
   created_on TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
   redeemed_by INT NULL,
   redeemed_on DATETIME NULL,
   created_by INT NULL,
   FOREIGN KEY (parent_user_id) REFERENCES users(id) ON DELETE CASCADE,
   FOREIGN KEY (redeemed_by) REFERENCES users(id) ON DELETE SET NULL,
   FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
   )";
   $db->exec($sql);
   error_log("Created/verified rewards table successfully");

   // Add created_by to existing rewards if not exists
   $db->exec("ALTER TABLE rewards ADD COLUMN IF NOT EXISTS created_by INT NULL");
   error_log("Added/verified created_by in rewards");

   // Create goals table with corrected constraints
   $sql = "CREATE TABLE IF NOT EXISTS goals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    parent_user_id INT NOT NULL,
    child_user_id INT NOT NULL,
    title VARCHAR(100) NOT NULL,
    target_points INT NOT NULL,
    start_date DATETIME,
    end_date DATETIME,
    status ENUM('active', 'pending_approval', 'completed', 'rejected') DEFAULT 'active',
    reward_id INT NULL,
    completed_at DATETIME DEFAULT NULL,
    requested_at DATETIME DEFAULT NULL,
    rejected_at DATETIME DEFAULT NULL,
    rejection_comment TEXT DEFAULT NULL,
    created_by INT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (parent_user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (child_user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (reward_id) REFERENCES rewards(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
  )";
  $db->exec($sql);
  error_log("Created/verified goals table successfully");

  // Add created_by to existing goals if not exists
  $db->exec("ALTER TABLE goals ADD COLUMN IF NOT EXISTS created_by INT NULL");
  error_log("Added/verified created_by in goals");

  // Create routines table if not exists (fixed constraints)
   $sql = "CREATE TABLE IF NOT EXISTS routines (
    id INT AUTO_INCREMENT PRIMARY KEY,
    parent_user_id INT NOT NULL,
    child_user_id INT NOT NULL,
    title VARCHAR(100) NOT NULL,
    start_time TIME,
    end_time TIME,
    recurrence ENUM('daily', 'weekly', '') DEFAULT '',
    bonus_points INT DEFAULT 0,
    created_by INT NULL,  /* Changed from NOT NULL to NULL */
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (parent_user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (child_user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
   )";
   $db->exec($sql);
   error_log("Created/verified routines table successfully");

   // Add created_by to existing routines if not exists
   $db->exec("ALTER TABLE routines ADD COLUMN IF NOT EXISTS created_by INT NULL");
   error_log("Added/verified created_by in routines");

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
        role_type ENUM('child', 'secondary_parent', 'family_member', 'caregiver') NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (main_parent_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (linked_user_id) REFERENCES users(id) ON DELETE CASCADE
    )";
    $db->exec($sql);
    error_log("Created/verified family_links table successfully");
      
$db->exec("ALTER TABLE tasks ADD COLUMN IF NOT EXISTS created_by INT NULL");
error_log("Added/verified created_by in tasks");

$db->exec("ALTER TABLE rewards ADD COLUMN IF NOT EXISTS created_by INT NULL");
error_log("Added/verified created_by in rewards");

$db->exec("ALTER TABLE goals ADD COLUMN IF NOT EXISTS created_by INT NULL");
error_log("Added/verified created_by in goals");

$db->exec("ALTER TABLE routines ADD COLUMN IF NOT EXISTS created_by INT NULL");
error_log("Added/verified created_by in routines");

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

// Modify the child_profiles table schema:
// Add birthday column if it doesn't exist
$sql = "ALTER TABLE child_profiles 
        ADD COLUMN IF NOT EXISTS birthday DATE DEFAULT NULL,
        MODIFY COLUMN age INT DEFAULT NULL";
$db->exec($sql);

?>
