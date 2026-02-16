<?php
// functions.php - Reusable utility functions
// Purpose: Centralize common operations for maintainability
// Inputs: None initially
// Outputs: Functions for app logic
// Version: 3.26.0 (Family-wide role support and linked management enhancements)

require_once __DIR__ . '/db_connect.php';

// Return a consistent display name for a user
function getDisplayName($user_id) {
    global $db;
    $stmt = $db->prepare("SELECT first_name, last_name, name, username FROM users WHERE id = :id");
    $stmt->execute([':id' => $user_id]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$u) return '';
    $first = trim((string)($u['first_name'] ?? ''));
    $last  = trim((string)($u['last_name'] ?? ''));
    if ($first !== '' || $last !== '') {
        return trim($first . ' ' . $last);
    }
    if (!empty($u['name'])) return $u['name'];
    return $u['username'];
}

// Return the role_type string from family_links if present
function getFamilyLinkRole($user_id) {
    global $db;
    $stmt = $db->prepare("SELECT role_type FROM family_links WHERE linked_user_id = :id LIMIT 1");
    $stmt->execute([':id' => $user_id]);
    return $stmt->fetchColumn() ?: null;
}

function getFamilyRootId($user_id) {
    $role = getEffectiveRole($user_id);
    if ($role === 'main_parent') {
        return $user_id;
    }

    global $db;
    $stmt = $db->prepare("SELECT main_parent_id FROM family_links WHERE linked_user_id = :id LIMIT 1");
    $stmt->execute([':id' => $user_id]);
    $main_parent_id = $stmt->fetchColumn();

    if ($main_parent_id) {
        return (int) $main_parent_id;
    }

    if ($role === 'child') {
        $childStmt = $db->prepare("SELECT parent_user_id FROM child_profiles WHERE child_user_id = :id AND deleted_at IS NULL LIMIT 1");
        $childStmt->execute([':id' => $user_id]);
        $parentId = $childStmt->fetchColumn();
        if ($parentId) {
            return (int) $parentId;
        }
    }

    return $user_id;
}

// Retrieve parent title (mother/father) for a user if assigned
function getParentTitle($user_id) {
    global $db;
    $stmt = $db->prepare("SELECT parent_title FROM users WHERE id = :id");
    $stmt->execute([':id' => $user_id]);
    $title = $stmt->fetchColumn();
    return $title ?: null;
}

function getEffectiveRole($user_id) {
    $role = getUserRole($user_id);
    if ($role === 'family_member') {
        $linkedRole = getFamilyLinkRole($user_id);
        if ($linkedRole) {
            return $linkedRole;
        }
    }
    return $role;
}

// Human readable role label for badges
function getUserRoleLabel($user_id) {
    global $db;
    $role = getEffectiveRole($user_id);
    if (!$role) return null;

    if ($role !== 'child') {
        $badgeStmt = $db->prepare("SELECT role_badge_label, use_role_badge_label FROM users WHERE id = :id");
        $badgeStmt->execute([':id' => $user_id]);
        $badge = $badgeStmt->fetch(PDO::FETCH_ASSOC);
        if (!empty($badge['use_role_badge_label']) && !empty($badge['role_badge_label'])) {
            return trim((string) $badge['role_badge_label']);
        }
    }

    if (in_array($role, ['main_parent', 'secondary_parent'], true)) {
        $parentTitle = getParentTitle($user_id);
        if ($parentTitle === 'mother') {
            return 'Mother';
        }
        if ($parentTitle === 'father') {
            return 'Father';
        }
    }

    if ($role === 'main_parent') return 'Main Account Owner';
    if ($role === 'child') return 'Child';

    if ($role === 'caregiver') {
        return 'Caregiver';
    }

    if ($role === 'family_member') {
        $linkedRole = getFamilyLinkRole($user_id);
        if ($linkedRole === 'secondary_parent') return 'Secondary Parent';
        if ($linkedRole === 'caregiver') return 'Caregiver';
        if ($linkedRole === 'child') return 'Child';
        return 'Family Member';
    }

    return ucfirst(str_replace('_', ' ', $role));
}

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
    ADD COLUMN IF NOT EXISTS last_name VARCHAR(50) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS role_badge_label VARCHAR(50) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS use_role_badge_label TINYINT(1) DEFAULT 0,
    ADD COLUMN IF NOT EXISTS parent_title ENUM('mother','father') DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS deleted_at DATETIME DEFAULT NULL");

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
    $stmt = $db->prepare("SELECT * FROM users WHERE username = :username AND deleted_at IS NULL");
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
    $role = getEffectiveRole($user_id);
    if ($role === 'main_parent' || $role === 'secondary_parent') return true;
    if ($role === 'family_member') {
        $stmt = $db->prepare("SELECT 1 FROM family_links WHERE linked_user_id = :id AND role_type = 'secondary_parent' LIMIT 1");
        $stmt->execute([':id' => $user_id]);
        if ($stmt->fetchColumn()) return true;
    }
    return false;
}

// Simple helpers
function isCaregiver($user_id) {
    return getEffectiveRole($user_id) === 'caregiver';
}

function isFamilyMember($user_id) {
    return getEffectiveRole($user_id) === 'family_member';
}

function canCreateContent($user_id) {
    $role = getEffectiveRole($user_id);
    return in_array($role, ['main_parent', 'secondary_parent', 'family_member', 'caregiver']);
}

function canAddEditChild($user_id) {
    $role = getEffectiveRole($user_id);
    return in_array($role, ['main_parent', 'secondary_parent']);
}

function canAddEditCaregiver($user_id) {
    return canAddEditChild($user_id); // same restriction
}

function canAddEditFamilyMember($user_id) {
    $role = getEffectiveRole($user_id);
    return in_array($role, ['main_parent', 'secondary_parent', 'family_member']);
}

// Revised: Create a child profile (now auto-creates child user and links, with name)
function createChildProfile($parent_user_id, $first_name, $last_name, $child_username, $child_password, $birthday, $avatar, $gender) {
    global $db;
    $family_root_id = getFamilyRootId($parent_user_id);
    $fullName = trim(trim((string)$first_name) . ' ' . trim((string)$last_name));
    $age = calculateAge($birthday);

    // Check for soft-deleted child match (same parent, name, birthday) and restore instead of creating duplicate
    if ($fullName !== '' && $birthday) {
        $existing = findSoftDeletedChild($family_root_id, $fullName, $birthday);
        if ($existing) {
            $restored = restoreChildProfile($existing['child_user_id'], $family_root_id, [
                'username' => $child_username,
                'password' => $child_password,
                'first_name' => $first_name,
                'last_name' => $last_name,
                'gender' => $gender,
                'birthday' => $birthday,
                'age' => $age,
                'avatar' => $avatar
            ]);
            return $restored ? ['child_user_id' => $existing['child_user_id'], 'status' => 'restored'] : false;
        }
    }

    // Check for soft-deleted child by username and restore
    $existingByUsername = findSoftDeletedChildByUsername($family_root_id, $child_username);
    if ($existingByUsername) {
        $restored = restoreChildProfile($existingByUsername['child_user_id'], $family_root_id, [
            'username' => $child_username,
            'password' => $child_password,
            'first_name' => $first_name,
            'last_name' => $last_name,
            'gender' => $gender,
            'birthday' => $birthday,
            'age' => $age,
            'avatar' => $avatar
        ]);
        return $restored ? ['child_user_id' => $existingByUsername['child_user_id'], 'status' => 'restored'] : false;
    }

    // Ensure username not used by an active account
    $usernameCheck = $db->prepare("SELECT id FROM users WHERE username = :username AND deleted_at IS NULL LIMIT 1");
    $usernameCheck->execute([':username' => $child_username]);
    if ($usernameCheck->fetchColumn()) {
        return false;
    }

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
        $stmt = $db->prepare("INSERT INTO child_profiles (child_user_id, parent_user_id, child_name, birthday, age, avatar) 
                             VALUES (:child_user_id, :parent_id, :child_name, :birthday, :age, :avatar)");
        if (!$stmt->execute([
            ':child_user_id' => $child_user_id,
            ':parent_id' => $family_root_id,
            ':child_name' => $first_name . ' ' . $last_name,
            ':birthday' => $birthday,
            ':age' => $age,
            ':avatar' => $avatar
        ])) {
            $db->rollBack();
            return false;
        }

        $db->commit();
        return ['child_user_id' => $child_user_id, 'status' => 'created'];
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Failed to create child profile: " . $e->getMessage());
        return false;
    }
}

// Locate a soft-deleted child profile by parent + name + birthday
function findSoftDeletedChild($parent_user_id, $child_name, $birthday) {
    global $db;
    $stmt = $db->prepare("
        SELECT child_user_id, deleted_at
        FROM child_profiles
        WHERE parent_user_id = :parent_id
          AND deleted_at IS NOT NULL
          AND LOWER(TRIM(child_name)) = LOWER(TRIM(:child_name))
          AND DATE(birthday) = DATE(:birthday)
        LIMIT 1
    ");
    $stmt->execute([
        ':parent_id' => $parent_user_id,
        ':child_name' => $child_name,
        ':birthday' => $birthday
    ]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// Locate a soft-deleted child by username under a parent
function findSoftDeletedChildByUsername($parent_user_id, $username) {
    global $db;
    $stmt = $db->prepare("
        SELECT cp.child_user_id, cp.deleted_at
        FROM child_profiles cp
        JOIN users u ON cp.child_user_id = u.id
        WHERE cp.parent_user_id = :parent_id
          AND cp.deleted_at IS NOT NULL
          AND u.username = :username
        LIMIT 1
    ");
    $stmt->execute([
        ':parent_id' => $parent_user_id,
        ':username' => $username
    ]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// Soft-delete child profile (preserve data for restore)
function softDeleteChild($parent_user_id, $child_user_id, $actor_user_id = null) {
    global $db;
    $managesTransaction = !$db->inTransaction();
    try {
        if ($managesTransaction) {
            $db->beginTransaction();
        }
        $stmt = $db->prepare("
            UPDATE child_profiles
            SET deleted_at = NOW(), deleted_by = :actor
            WHERE child_user_id = :child_id
              AND parent_user_id = :parent_id
              AND deleted_at IS NULL
        ");
        $stmt->execute([
            ':child_id' => $child_user_id,
            ':parent_id' => $parent_user_id,
            ':actor' => $actor_user_id
        ]);
        if ($stmt->rowCount() === 0) {
            if ($managesTransaction && $db->inTransaction()) {
                $db->rollBack();
            }
            return false;
        }

        $db->prepare("UPDATE users SET deleted_at = NOW() WHERE id = :id")->execute([':id' => $child_user_id]);

        if ($managesTransaction && $db->inTransaction()) {
            $db->commit();
        }
        return true;
    } catch (Exception $e) {
        if ($managesTransaction && $db->inTransaction()) {
            $db->rollBack();
        }
        error_log("Failed to soft delete child: " . $e->getMessage());
        return false;
    }
}

// Permanently delete a child and cascade data (irreversible)
function hardDeleteChild($parent_user_id, $child_user_id) {
    global $db;
    try {
        $db->beginTransaction();
        // Ensure the target belongs to this parent
        $check = $db->prepare("SELECT 1 FROM child_profiles WHERE child_user_id = :child_id AND parent_user_id = :parent_id LIMIT 1");
        $check->execute([':child_id' => $child_user_id, ':parent_id' => $parent_user_id]);
        if (!$check->fetchColumn()) {
            $db->rollBack();
            return false;
        }
        // Delete user will cascade child_points, child_profiles (FK)
        $stmt = $db->prepare("DELETE FROM users WHERE id = :id");
        $stmt->execute([':id' => $child_user_id]);
        if ($stmt->rowCount() === 0) {
            $db->rollBack();
            return false;
        }
        $db->commit();
        return true;
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log("Hard delete child failed: " . $e->getMessage());
        return false;
    }
}

// Restore a previously soft-deleted child and refresh credentials/profile
function restoreChildProfile($child_user_id, $parent_user_id, array $updates = []) {
    global $db;
    $managesTransaction = !$db->inTransaction();
    try {
        if ($managesTransaction) {
            $db->beginTransaction();
        }
        $check = $db->prepare("SELECT 1 FROM child_profiles WHERE child_user_id = :child_id AND parent_user_id = :parent_id AND deleted_at IS NOT NULL LIMIT 1");
        $check->execute([':child_id' => $child_user_id, ':parent_id' => $parent_user_id]);
        if (!$check->fetchColumn()) {
            if ($managesTransaction && $db->inTransaction()) {
                $db->rollBack();
            }
            return false;
        }

        $userParams = [
            ':id' => $child_user_id,
            ':first_name' => $updates['first_name'] ?? null,
            ':last_name' => $updates['last_name'] ?? null,
            ':gender' => $updates['gender'] ?? null,
        ];
        $setPassword = isset($updates['password']) && $updates['password'] !== '';
        $setUsername = isset($updates['username']) && $updates['username'] !== '';
        $sqlParts = [
            "first_name = :first_name",
            "last_name = :last_name",
            "gender = :gender",
            "deleted_at = NULL"
        ];
        if ($setPassword) {
            $sqlParts[] = "password = :password";
            $userParams[':password'] = password_hash($updates['password'], PASSWORD_DEFAULT);
        }
        if ($setUsername) {
            // Prevent username collision with another active account
            $dupeCheck = $db->prepare("SELECT id FROM users WHERE username = :username AND id != :id AND deleted_at IS NULL LIMIT 1");
            $dupeCheck->execute([':username' => $updates['username'], ':id' => $child_user_id]);
            if ($dupeCheck->fetchColumn()) {
                if ($managesTransaction && $db->inTransaction()) {
                    $db->rollBack();
                }
                return false;
            }
            $sqlParts[] = "username = :username";
            $userParams[':username'] = $updates['username'];
        }
        $userSql = "UPDATE users SET " . implode(', ', $sqlParts) . " WHERE id = :id";
        $db->prepare($userSql)->execute($userParams);

        $profileSql = "
            UPDATE child_profiles
            SET deleted_at = NULL,
                deleted_by = NULL,
                child_name = :child_name,
                birthday = :birthday,
                age = :age,
                avatar = COALESCE(NULLIF(:avatar, ''), avatar)
            WHERE child_user_id = :child_id
              AND parent_user_id = :parent_id
        ";
        $db->prepare($profileSql)->execute([
            ':child_name' => trim(($updates['first_name'] ?? '') . ' ' . ($updates['last_name'] ?? '')),
            ':birthday' => $updates['birthday'] ?? null,
            ':age' => $updates['age'] ?? null,
            ':avatar' => $updates['avatar'] ?? null,
            ':child_id' => $child_user_id,
            ':parent_id' => $parent_user_id
        ]);

        // Ensure child_points exists
        $db->prepare("INSERT IGNORE INTO child_points (child_user_id, total_points) VALUES (:child_id, 0)")
           ->execute([':child_id' => $child_user_id]);

        if ($managesTransaction && $db->inTransaction()) {
            $db->commit();
        }
        return true;
    } catch (Exception $e) {
        if ($managesTransaction && $db->inTransaction()) {
            $db->rollBack();
        }
        error_log("Restore child failed: " . $e->getMessage());
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
        if (!$stmt->execute([
            ':username' => $username,
            ':password' => $hashedPassword,
            ':role' => $mappedRole,
            ':first_name' => $first_name,
            ':last_name' => $last_name
        ])) {
            $db->rollBack();
            return false;
        }
        $linked_id = $db->lastInsertId();

      // Link in family_links with role_type
        $stmt = $db->prepare("INSERT INTO family_links (main_parent_id, linked_user_id, role_type) VALUES (:main_id, :linked_id, :role_type)");
        if (!$stmt->execute([
            ':main_id' => $main_parent_id,
            ':linked_id' => $linked_id,
            ':role_type' => $roleType
        ])) {
            $db->rollBack();
            return false;
        }

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
function updateChildProfile($child_user_id, $first_name, $last_name, $birthday, $avatar, $gender = null) {
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
                                 last_name = :last_name,
                                 gender = :gender
                             WHERE id = :user_id");
        $stmt->execute([
            ':first_name' => $first_name,
            ':last_name' => $last_name,
            ':gender' => $gender,
            ':user_id' => $child_user_id
        ]);

        $db->commit();
        return true;
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Failed to update child profile: " . $e->getMessage());
        return false;
    }
}

// Revised: getDashboardData (name display, caregiver access)
function getDashboardData($user_id) {
    global $db;
    $data = [];

    // Normalize role (map legacy 'parent' -> 'main_parent')
    $role = getUserRole($user_id) ?? 'unknown';
    error_log("Fetching dashboard data for user_id=$user_id, role=$role");

    // Build a unified branch for all parent-like roles
    if (in_array($role, ['main_parent', 'family_member', 'caregiver'])) {
        // Determine the main parent id for the current actor
        $main_parent_id = $user_id;
        if ($role !== 'main_parent') {
            $secondary_stmt = $db->prepare("SELECT main_parent_id FROM family_links WHERE linked_user_id = :user_id LIMIT 1");
            $secondary_stmt->execute([':user_id' => $user_id]);
            $main_parent_from_link = $secondary_stmt->fetchColumn();
            if ($main_parent_from_link) {
                $main_parent_id = $main_parent_from_link;
            }
        }
        autoCloseExpiredGoals($main_parent_id, null);

        // Determine all parent IDs in this family (main + linked adults)
        $family_parent_ids = [$main_parent_id];
        $linkedParentStmt = $db->prepare("SELECT linked_user_id FROM family_links WHERE main_parent_id = :parent_id");
        $linkedParentStmt->execute([':parent_id' => $main_parent_id]);
        $linkedParents = $linkedParentStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        foreach ($linkedParents as $linkedId) {
            $family_parent_ids[] = (int) $linkedId;
        }
        $family_parent_ids = array_values(array_unique(array_filter($family_parent_ids, static function ($value) {
            return $value !== null && $value !== '';
        })));
        if (empty($family_parent_ids)) {
            $family_parent_ids = [$main_parent_id];
        }
        $parentPlaceholders = implode(',', array_fill(0, count($family_parent_ids), '?'));

        // Children for this family
          $stmt = $db->prepare("SELECT cp.id, cp.child_user_id, COALESCE(CONCAT(u.first_name, ' ', u.last_name), u.name, u.username) AS display_name, cp.avatar, cp.birthday, cp.child_name
                                 FROM child_profiles cp
                                 JOIN users u ON cp.child_user_id = u.id
                                 WHERE cp.parent_user_id IN ($parentPlaceholders)
                                   AND cp.deleted_at IS NULL
                                   AND u.deleted_at IS NULL");
          $stmt->execute($family_parent_ids);
          $data['children'] = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $childIds = array_column($data['children'], 'child_user_id');
        $taskCounts = [];
        $pointsMap = [];
        $goalStats = [];
        $rewardsClaimed = [];
        $maxChildPoints = 0;

        if (!empty($childIds)) {
            $placeholders = implode(',', array_fill(0, count($childIds), '?'));
            $todayDate = date('Y-m-d');
            $todayDay = date('D');

            // Tasks assigned per child
            $stmt = $db->prepare("SELECT child_user_id, due_date, end_date, recurrence, recurrence_days, status, completed_at, approved_at FROM tasks WHERE child_user_id IN ($placeholders)");
            $stmt->execute($childIds);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $childId = (int) $row['child_user_id'];
                if (!isset($taskCounts[$childId])) {
                    $taskCounts[$childId] = 0;
                }
                $dueDate = $row['due_date'] ?? null;
                if (empty($dueDate)) {
                    continue;
                }
                $startKey = date('Y-m-d', strtotime($dueDate));
                $endKey = !empty($row['end_date']) ? $row['end_date'] : null;
                if ($todayDate < $startKey) {
                    continue;
                }
                if ($endKey && $todayDate > $endKey) {
                    continue;
                }
                $repeat = $row['recurrence'] ?? '';
                $repeatDays = array_filter(array_map('trim', explode(',', (string) ($row['recurrence_days'] ?? ''))));
                if ($repeat === 'daily') {
                    // keep
                } elseif ($repeat === 'weekly') {
                    if (!in_array($todayDay, $repeatDays, true)) {
                        continue;
                    }
                } else {
                    if ($todayDate !== $startKey) {
                        continue;
                    }
                }
                $status = $row['status'] ?? 'pending';
                $completedAt = $row['completed_at'] ?? null;
                $approvedAt = $row['approved_at'] ?? null;
                $completedToday = false;
                if (!empty($approvedAt)) {
                    $approvedDate = date('Y-m-d', strtotime($approvedAt));
                    $completedToday = $approvedDate === $todayDate;
                } elseif (!empty($completedAt)) {
                    $completedDate = date('Y-m-d', strtotime($completedAt));
                    $completedToday = $completedDate === $todayDate;
                }
                if ($completedToday) {
                    continue;
                }
                $taskCounts[$childId] += 1;
            }

            // Points earned per child
            $stmt = $db->prepare("SELECT child_user_id, total_points FROM child_points WHERE child_user_id IN ($placeholders)");
            $stmt->execute($childIds);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $childId = (int)$row['child_user_id'];
                $points = (int)$row['total_points'];
                $pointsMap[$childId] = $points;
                if ($points > $maxChildPoints) {
                    $maxChildPoints = $points;
                }
            }

            // Active/pending goals per child
            $stmt = $db->prepare("SELECT child_user_id, COUNT(*) AS goal_count
                                    FROM goals
                                    WHERE child_user_id IN ($placeholders) AND status IN ('active', 'pending_approval')
                                    GROUP BY child_user_id");
            $stmt->execute($childIds);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $goalStats[(int)$row['child_user_id']] = [
                    'goal_count' => (int)$row['goal_count']
                ];
            }

            // Rewards claimed per child
            $stmt = $db->prepare("SELECT redeemed_by AS child_user_id, COUNT(*) AS rewards_claimed
                                  FROM rewards
                                  WHERE redeemed_by IN ($placeholders) AND status = 'redeemed'
                                  GROUP BY redeemed_by");
            $stmt->execute($childIds);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $rewardsClaimed[(int)$row['child_user_id']] = (int)$row['rewards_claimed'];
            }

            // Points adjustments history (latest 10 per child)
            try {
                $db->exec("
                    CREATE TABLE IF NOT EXISTS child_point_adjustments (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        child_user_id INT NOT NULL,
                        delta_points INT NOT NULL,
                        reason VARCHAR(255) NOT NULL,
                        created_by INT NOT NULL,
                        created_at DATETIME NOT NULL,
                        INDEX idx_child_created (child_user_id, created_at)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                ");
                $adjStmt = $db->prepare("
                    SELECT child_user_id, delta_points, reason, created_at
                    FROM child_point_adjustments
                    WHERE child_user_id IN ($placeholders)
                    ORDER BY created_at DESC
                ");
                $adjStmt->execute($childIds);
                $adjustmentsByChild = [];
                while ($row = $adjStmt->fetch(PDO::FETCH_ASSOC)) {
                    $cid = (int)$row['child_user_id'];
                    if (!isset($adjustmentsByChild[$cid])) {
                        $adjustmentsByChild[$cid] = [];
                    }
                    if (count($adjustmentsByChild[$cid]) < 10) {
                        $adjustmentsByChild[$cid][] = [
                            'delta_points' => (int)$row['delta_points'],
                            'reason' => $row['reason'],
                            'created_at' => $row['created_at']
                        ];
                    }
                }
            } catch (Exception $e) {
                error_log("Failed to load point adjustments: " . $e->getMessage());
                $adjustmentsByChild = [];
            }

            // Star adjustments history (latest 10 per child)
            try {
                ensureChildStarAdjustmentsTable();
                $starAdjStmt = $db->prepare("\n                    SELECT child_user_id, delta_stars, reason, created_at\n                    FROM child_star_adjustments\n                    WHERE child_user_id IN ($placeholders)\n                    ORDER BY created_at DESC\n                ");
                $starAdjStmt->execute($childIds);
                $starAdjustmentsByChild = [];
                while ($row = $starAdjStmt->fetch(PDO::FETCH_ASSOC)) {
                    $cid = (int)$row['child_user_id'];
                    if (!isset($starAdjustmentsByChild[$cid])) {
                        $starAdjustmentsByChild[$cid] = [];
                    }
                    if (count($starAdjustmentsByChild[$cid]) < 10) {
                        $starAdjustmentsByChild[$cid][] = [
                            'delta_stars' => (int)$row['delta_stars'],
                            'reason' => $row['reason'],
                            'created_at' => $row['created_at']
                        ];
                    }
                }
            } catch (Exception $e) {
                error_log("Failed to load star adjustments: " . $e->getMessage());
                $starAdjustmentsByChild = [];
            }
        }

        $maxChildPoints = max(100, $maxChildPoints);

        foreach ($data['children'] as &$child) {
            $childId = (int)$child['child_user_id'];
            $child['age'] = calculateAge($child['birthday'] ?? null);
            $child['task_count'] = $taskCounts[$childId] ?? 0;
            $childPoints = $pointsMap[$childId] ?? 0;
            $child['points_earned'] = $childPoints;
            $child['points_progress_percent'] = $maxChildPoints > 0 ? min(100, (int)round(($childPoints / $maxChildPoints) * 100)) : 0;
            $levelState = getChildLevelState($childId, (int) $main_parent_id);
            $child['level'] = $levelState['level'] ?? 1;
            $child['stars_per_level'] = max(1, (int) ($levelState['stars_per_level'] ?? 10));
            $child['stars_to_next_level'] = max(0, (int) ($levelState['stars_to_next_level'] ?? 0));
            $child['stars_in_level'] = max(0, $child['stars_per_level'] - $child['stars_to_next_level']);
            $child['level_progress_percent'] = min(100, (int) round(($child['stars_in_level'] / $child['stars_per_level']) * 100));
            $childGoalStats = $goalStats[$childId] ?? ['goal_count' => 0];
            $child['goals_assigned'] = $childGoalStats['goal_count'];
            $child['rewards_claimed'] = $rewardsClaimed[$childId] ?? 0;
            $child['point_adjustments'] = $adjustmentsByChild[$childId] ?? [];
            $child['star_adjustments'] = $starAdjustmentsByChild[$childId] ?? [];
            $streaks = getChildStreaks($childId, (int) $main_parent_id);
            $child['routine_streak'] = (int) ($streaks['routine_streak'] ?? 0);
            $child['task_streak'] = (int) ($streaks['task_streak'] ?? 0);
            $child['routine_week_dates'] = array_values($streaks['routine_week_dates'] ?? []);
            $child['task_week_dates'] = array_values($streaks['task_week_dates'] ?? []);
            $child['weekly_task_completed_count'] = (int) ($streaks['weekly_task_completed_count'] ?? 0);
            $child['routine_on_time_rate'] = (int) ($streaks['routine_on_time_rate'] ?? 0);
            $child['task_on_time_rate'] = (int) ($streaks['task_on_time_rate'] ?? 0);
            $child['routine_best_streak'] = (int) ($streaks['routine_best_streak'] ?? 0);
            $child['task_best_streak'] = (int) ($streaks['task_best_streak'] ?? 0);
        }
        unset($child);
        $data['max_child_points'] = $maxChildPoints;

        // Active rewards for the family (include child scope if set)
        $stmt = $db->prepare("SELECT 
                                  r.id, 
                                  r.title, 
                                  r.description, 
                                  r.point_cost, 
                                  r.created_on,
                                  r.child_user_id,
                                  cu.first_name AS child_first_name,
                                  cp.avatar AS child_avatar,
                                  COALESCE(
                                      NULLIF(TRIM(CONCAT(COALESCE(cu.first_name, ''), ' ', COALESCE(cu.last_name, ''))), ''),
                                      NULLIF(cu.name, ''),
                                      cu.username,
                                      NULL
                                  ) AS child_name
                              FROM rewards r
                              LEFT JOIN users cu ON r.child_user_id = cu.id
                              LEFT JOIN child_profiles cp ON r.child_user_id = cp.child_user_id
                              WHERE r.parent_user_id = :parent_id AND r.status = 'available'
                                AND (cp.deleted_at IS NULL OR cp.child_user_id IS NULL)
                                AND (cu.deleted_at IS NULL OR r.child_user_id IS NULL)
                                AND NOT EXISTS (
                                    SELECT 1 FROM goals g
                                    WHERE g.reward_id = r.id
                                      AND g.award_mode IN ('reward', 'both')
                                      AND g.status IN ('active', 'pending_approval', 'rejected')
                                )
                              ORDER BY r.created_on DESC");
        $stmt->execute([':parent_id' => $main_parent_id]);
        $data['active_rewards'] = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Recently redeemed rewards so parents can review them
        $stmt = $db->prepare("
            SELECT 
                r.id,
                r.title,
                r.description,
                r.point_cost,
                r.redeemed_on,
                r.fulfilled_on,
                r.redeemed_by AS child_user_id,
                COALESCE(
                    NULLIF(TRIM(CONCAT(COALESCE(child.first_name, ''), ' ', COALESCE(child.last_name, ''))), ''),
                    NULLIF(child.name, ''),
                    child.username,
                    'Unknown'
                ) AS child_username,
                COALESCE(
                    NULLIF(TRIM(CONCAT(COALESCE(fulfiller.first_name, ''), ' ', COALESCE(fulfiller.last_name, ''))), ''),
                    NULLIF(fulfiller.name, ''),
                    fulfiller.username,
                    'Unknown'
                ) AS fulfilled_by_name
            FROM rewards r
            LEFT JOIN users child ON r.redeemed_by = child.id
            LEFT JOIN users fulfiller ON r.fulfilled_by = fulfiller.id
            WHERE r.parent_user_id = :parent_id AND r.status = 'redeemed' AND r.denied_on IS NULL
            ORDER BY COALESCE(r.redeemed_on, r.created_on) DESC
            LIMIT 25
        ");
        $stmt->execute([':parent_id' => $main_parent_id]);
        $data['redeemed_rewards'] = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Pending approvals across this parent's children
        $stmt = $db->prepare("SELECT 
                                g.id, 
                                g.title, 
                                g.requested_at, 
                                COALESCE(
                                    NULLIF(TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))), ''),
                                    NULLIF(u.name, ''),
                                    u.username,
                                    'Unknown'
                                ) AS child_username,
                                COALESCE(
                                    NULLIF(TRIM(CONCAT(COALESCE(creator.first_name, ''), ' ', COALESCE(creator.last_name, ''))), ''),
                                    NULLIF(creator.name, ''),
                                    creator.username,
                                    'Unknown'
                                ) AS creator_display_name
                              FROM goals g
                              JOIN child_profiles cp ON g.child_user_id = cp.child_user_id
                              JOIN users u ON g.child_user_id = u.id
                              LEFT JOIN users creator ON g.created_by = creator.id
                              WHERE cp.parent_user_id IN ($parentPlaceholders) AND g.status = 'pending_approval'
                                AND cp.deleted_at IS NULL
                                AND u.deleted_at IS NULL");
        $stmt->execute($family_parent_ids);
        $data['pending_approvals'] = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Sum total points across children
        $data['total_points_earned'] = array_sum($pointsMap);

        $stmt = $db->prepare("SELECT COUNT(*) FROM goals WHERE parent_user_id = :parent_id AND status = 'completed'");
        $stmt->execute([':parent_id' => $main_parent_id]);
        $data['goals_met'] = (int)($stmt->fetchColumn() ?: 0);

    } elseif ($role === 'child') {
        // Fetch remaining_points from child_points
        autoCloseExpiredGoals(null, $user_id);
        $stmt = $db->prepare("SELECT total_points FROM child_points WHERE child_user_id = :child_id");
        $stmt->execute([':child_id' => $user_id]);
        $data['remaining_points'] = $stmt->fetchColumn() ?: 0;

        $max_points = 100; // Define a max points threshold (adjust as needed)
        $points_progress = ($data['remaining_points'] > 0 && $max_points > 0) ? min(100, round(($data['remaining_points'] / $max_points) * 100)) : 0;
        $data['points_progress'] = $points_progress;

        $parentStmt = $db->prepare("SELECT parent_user_id FROM child_profiles WHERE child_user_id = :child_id AND deleted_at IS NULL LIMIT 1");
        $parentStmt->execute([':child_id' => $user_id]);
        $parent_id = $parentStmt->fetchColumn();
        if ($parent_id) {
            $levelState = getChildLevelState((int) $user_id, (int) $parent_id);
            $data['child_level'] = $levelState['level'] ?? 1;
            $data['stars_per_level'] = max(1, (int) ($levelState['stars_per_level'] ?? 10));
            $data['level_pending'] = (int) ($levelState['pending'] ?? 0);
            $data['stars_to_next_level'] = max(0, (int) ($levelState['stars_to_next_level'] ?? 0));
            $data['stars_in_level'] = max(0, $data['stars_per_level'] - $data['stars_to_next_level']);
            $data['level_progress_percent'] = min(100, (int) round(($data['stars_in_level'] / $data['stars_per_level']) * 100));
            $streaks = getChildStreaks((int) $user_id, (int) $parent_id);
            $data['routine_streak'] = (int) ($streaks['routine_streak'] ?? 0);
            $data['task_streak'] = (int) ($streaks['task_streak'] ?? 0);
            $data['routine_week_dates'] = array_values($streaks['routine_week_dates'] ?? []);
            $data['task_week_dates'] = array_values($streaks['task_week_dates'] ?? []);
            $data['weekly_task_completed_count'] = (int) ($streaks['weekly_task_completed_count'] ?? 0);
            $data['routine_on_time_rate'] = (int) ($streaks['routine_on_time_rate'] ?? 0);
            $data['task_on_time_rate'] = (int) ($streaks['task_on_time_rate'] ?? 0);
            $data['routine_best_streak'] = (int) ($streaks['routine_best_streak'] ?? 0);
            $data['task_best_streak'] = (int) ($streaks['task_best_streak'] ?? 0);
            $stmt = $db->prepare("SELECT id, title, description, point_cost
                                  FROM rewards
                                  WHERE parent_user_id = :parent_id
                                    AND status = 'available'
                                    AND (child_user_id IS NULL OR child_user_id = :child_id)
                                    AND NOT EXISTS (
                                        SELECT 1 FROM goals g
                                        WHERE g.reward_id = rewards.id
                                          AND g.award_mode IN ('reward', 'both')
                                          AND g.status IN ('active', 'pending_approval', 'rejected')
                                    )");
            $stmt->execute([':parent_id' => $parent_id, ':child_id' => $user_id]);
            $data['rewards'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        $stmt = $db->prepare("SELECT 
                                g.id, 
                                g.title, 
                                g.start_date, 
                                g.end_date, 
                                r.title AS reward_title,
                                COALESCE(
                                    NULLIF(TRIM(CONCAT(COALESCE(creator.first_name, ''), ' ', COALESCE(creator.last_name, ''))), ''),
                                    NULLIF(creator.name, ''),
                                    creator.username,
                                    'Unknown'
                                ) AS creator_display_name
                             FROM goals g 
                             LEFT JOIN rewards r ON g.reward_id = r.id 
                             LEFT JOIN users creator ON g.created_by = creator.id
                             WHERE g.child_user_id = :child_id AND g.status = 'active'");
        $stmt->execute([':child_id' => $user_id]);
        $data['active_goals'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $db->prepare("SELECT 
                                g.id, 
                                g.title, 
                                g.start_date, 
                                g.end_date, 
                                g.completed_at, 
                                r.title AS reward_title,
                                COALESCE(
                                    NULLIF(TRIM(CONCAT(COALESCE(creator.first_name, ''), ' ', COALESCE(creator.last_name, ''))), ''),
                                    NULLIF(creator.name, ''),
                                    creator.username,
                                    'Unknown'
                                ) AS creator_display_name
                             FROM goals g 
                             LEFT JOIN rewards r ON g.reward_id = r.id 
                             LEFT JOIN users creator ON g.created_by = creator.id
                             WHERE g.child_user_id = :child_id AND g.status = 'completed'");
        $stmt->execute([':child_id' => $user_id]);
        $data['completed_goals'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $db->prepare("SELECT r.id, r.title, r.description, r.point_cost, COALESCE(u.name, u.username) as child_username, r.redeemed_on, r.fulfilled_on
                     FROM rewards r 
                     LEFT JOIN users u ON r.redeemed_by = u.id 
                     WHERE r.redeemed_by = :child_id AND r.status = 'redeemed'");
        $stmt->execute([':child_id' => $user_id]);
        $data['redeemed_rewards'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        ensureChildNotificationsTable();
        // Auto-purge deleted notifications older than 1 week (production window; future setting may extend this)
        $db->prepare("DELETE FROM child_notifications WHERE child_user_id = :child_id AND deleted_at IS NOT NULL AND deleted_at <= (NOW() - INTERVAL 1 WEEK)")
            ->execute([':child_id' => $user_id]);
        $notifStmt = $db->prepare("SELECT id, type, message, link_url, is_read, created_at, deleted_at FROM child_notifications WHERE child_user_id = :child_id ORDER BY created_at DESC LIMIT 150");
        $notifStmt->execute([':child_id' => $user_id]);
        $allNotes = $notifStmt->fetchAll(PDO::FETCH_ASSOC);
        $data['notifications_new'] = array_values(array_filter($allNotes, static function ($n) { return empty($n['is_read']) && empty($n['deleted_at']); }));
        $data['notifications_read'] = array_values(array_filter($allNotes, static function ($n) { return !empty($n['is_read']) && empty($n['deleted_at']); }));
        $data['notifications_deleted'] = array_values(array_filter($allNotes, static function ($n) { return !empty($n['deleted_at']); }));
    }

    return $data;
}

// Create a new task
function createTask($parent_user_id, $child_user_id, $title, $description, $due_date, $end_date, $points, $recurrence, $recurrence_days, $category, $timing_mode, $timer_minutes = null, $time_of_day = 'anytime', $photo_proof_required = 0, $creator_user_id = null) {
    global $db;
    $stmt = $db->prepare("INSERT INTO tasks (parent_user_id, child_user_id, title, description, due_date, end_date, points, recurrence, recurrence_days, category, timing_mode, timer_minutes, time_of_day, photo_proof_required, created_by) VALUES (:parent_id, :child_id, :title, :description, :due_date, :end_date, :points, :recurrence, :recurrence_days, :category, :timing_mode, :timer_minutes, :time_of_day, :photo_proof_required, :created_by)");
    return $stmt->execute([
        ':parent_id' => $parent_user_id,
        ':child_id' => $child_user_id,
        ':title' => $title,
        ':description' => $description,
        ':due_date' => $due_date,
        ':end_date' => $end_date,
        ':points' => $points,
        ':recurrence' => $recurrence,
        ':recurrence_days' => $recurrence_days,
        ':category' => $category,
        ':timing_mode' => $timing_mode,
        ':timer_minutes' => $timer_minutes,
        ':time_of_day' => $time_of_day,
        ':photo_proof_required' => !empty($photo_proof_required) ? 1 : 0,
        ':created_by' => $creator_user_id ?? $parent_user_id
    ]);
}

// Get tasks for a user
function getTasks($user_id) {
    global $db;
    $role = getEffectiveRole($user_id);

    if (in_array($role, ['main_parent', 'secondary_parent', 'family_member', 'caregiver'], true)) {
        $parent_id = getFamilyRootId($user_id);
        $stmt = $db->prepare("
            SELECT 
                t.*,
                COALESCE(
                    NULLIF(TRIM(CONCAT(COALESCE(creator.first_name, ''), ' ', COALESCE(creator.last_name, ''))), ''),
                    NULLIF(creator.name, ''),
                    creator.username,
                    'Unknown'
                ) AS creator_display_name
            FROM tasks t
            LEFT JOIN users creator ON t.created_by = creator.id
            WHERE t.parent_user_id = :parent_id
        ");
        $stmt->execute([':parent_id' => $parent_id]);
    } else {
        $stmt = $db->prepare("
            SELECT 
                t.*,
                COALESCE(
                    NULLIF(TRIM(CONCAT(COALESCE(creator.first_name, ''), ' ', COALESCE(creator.last_name, ''))), ''),
                    NULLIF(creator.name, ''),
                    creator.username,
                    'Unknown'
                ) AS creator_display_name
            FROM tasks t
            LEFT JOIN users creator ON t.created_by = creator.id
            WHERE t.child_user_id = :child_id
        ");
        $stmt->execute([':child_id' => $user_id]);
    }
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Complete a task
function completeTask($task_id, $child_id, $photo_proof = null, $instance_date = null) {
    global $db;
    $stmt = $db->prepare("SELECT recurrence FROM tasks WHERE id = :id AND child_user_id = :child_id");
    $stmt->execute([':id' => $task_id, ':child_id' => $child_id]);
    $recurrence = $stmt->fetchColumn();
    $isRecurring = !empty($recurrence);

    if ($isRecurring) {
        $dateKey = $instance_date ?: date('Y-m-d');
        $stmt = $db->prepare("
            INSERT INTO task_instances (task_id, date_key, status, photo_proof, completed_at, created_at)
            VALUES (:task_id, :date_key, 'completed', :photo_proof, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                status = 'completed',
                photo_proof = :photo_proof,
                completed_at = NOW(),
                updated_at = NOW()
        ");
        return $stmt->execute([
            ':task_id' => $task_id,
            ':date_key' => $dateKey,
            ':photo_proof' => $photo_proof
        ]);
    }

    $stmt = $db->prepare("UPDATE tasks SET status = 'completed', photo_proof = :photo_proof, completed_at = NOW(), approved_at = NULL WHERE id = :id AND child_user_id = :child_id AND status = 'pending'");
    return $stmt->execute([':photo_proof' => $photo_proof, ':id' => $task_id, ':child_id' => $child_id]);
}

function ensureChildNotificationsTable() {
    global $db;
    $db->exec("
        CREATE TABLE IF NOT EXISTS child_notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            child_user_id INT NOT NULL,
            type VARCHAR(64) NOT NULL,
            message VARCHAR(255) NOT NULL,
            link_url VARCHAR(255) NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            deleted_at DATETIME DEFAULT NULL,
            INDEX idx_child_read (child_user_id, is_read, created_at),
            INDEX idx_child_deleted (child_user_id, deleted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    try {
        $db->exec("ALTER TABLE child_notifications ADD COLUMN deleted_at DATETIME DEFAULT NULL");
    } catch (PDOException $e) {
        // ignore if it already exists
    }
}

function addChildNotification($child_id, $type, $message, $link_url = null) {
    global $db;
    ensureChildNotificationsTable();
    $stmt = $db->prepare("INSERT INTO child_notifications (child_user_id, type, message, link_url, created_at) VALUES (:child_id, :type, :message, :link_url, NOW())");
    $stmt->execute([
        ':child_id' => $child_id,
        ':type' => substr((string)$type, 0, 64),
        ':message' => substr((string)$message, 0, 255),
        ':link_url' => $link_url ? substr((string)$link_url, 0, 255) : null
    ]);
}

function ensureParentNotificationsTable() {
    global $db;
    $db->exec("
        CREATE TABLE IF NOT EXISTS parent_notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            parent_user_id INT NOT NULL,
            type VARCHAR(64) NOT NULL,
            message VARCHAR(255) NOT NULL,
            link_url VARCHAR(255) NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            deleted_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL,
            INDEX idx_parent_read (parent_user_id, is_read, created_at),
            INDEX idx_parent_deleted (parent_user_id, deleted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    try {
        $db->exec("ALTER TABLE parent_notifications ADD COLUMN deleted_at DATETIME DEFAULT NULL");
    } catch (PDOException $e) {
        // ignore if exists
    }
}

function addParentNotification($parent_user_id, $type, $message, $link_url = null) {
    global $db;
    ensureParentNotificationsTable();
    $stmt = $db->prepare("INSERT INTO parent_notifications (parent_user_id, type, message, link_url, created_at) VALUES (:parent_id, :type, :message, :link_url, NOW())");
    $stmt->execute([
        ':parent_id' => $parent_user_id,
        ':type' => substr((string)$type, 0, 64),
        ':message' => substr((string)$message, 0, 255),
        ':link_url' => $link_url ? substr((string)$link_url, 0, 255) : null
    ]);
}

function getParentNotifications($parent_user_id) {
    global $db;
    ensureParentNotificationsTable();
    // Purge deleted after 1 week
    $db->prepare("DELETE FROM parent_notifications WHERE parent_user_id = :pid AND deleted_at IS NOT NULL AND deleted_at <= (NOW() - INTERVAL 1 WEEK)")
        ->execute([':pid' => $parent_user_id]);

    $stmt = $db->prepare("SELECT id, type, message, link_url, is_read, deleted_at, created_at FROM parent_notifications WHERE parent_user_id = :pid ORDER BY created_at DESC LIMIT 200");
    $stmt->execute([':pid' => $parent_user_id]);
    $all = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return [
        'new' => array_values(array_filter($all, static fn($n) => empty($n['is_read']) && empty($n['deleted_at']))),
        'read' => array_values(array_filter($all, static fn($n) => !empty($n['is_read']) && empty($n['deleted_at']))),
        'deleted' => array_values(array_filter($all, static fn($n) => !empty($n['deleted_at'])))
    ];
}

function getChildNotifications($child_user_id) {
    global $db;
    ensureChildNotificationsTable();
    $db->prepare("DELETE FROM child_notifications WHERE child_user_id = :child_id AND deleted_at IS NOT NULL AND deleted_at <= (NOW() - INTERVAL 1 WEEK)")
        ->execute([':child_id' => $child_user_id]);

    $stmt = $db->prepare("SELECT id, type, message, link_url, is_read, created_at, deleted_at FROM child_notifications WHERE child_user_id = :child_id ORDER BY created_at DESC LIMIT 150");
    $stmt->execute([':child_id' => $child_user_id]);
    $all = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return [
        'new' => array_values(array_filter($all, static fn($n) => empty($n['is_read']) && empty($n['deleted_at']))),
        'read' => array_values(array_filter($all, static fn($n) => !empty($n['is_read']) && empty($n['deleted_at']))),
        'deleted' => array_values(array_filter($all, static fn($n) => !empty($n['deleted_at'])))
    ];
}

function ensureRoutinePointsLogsTable() {
    global $db;
    $db->exec("
        CREATE TABLE IF NOT EXISTS routine_points_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            routine_id INT NOT NULL,
            child_user_id INT NOT NULL,
            task_points INT NOT NULL DEFAULT 0,
            bonus_points INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            INDEX idx_routine_points_child (child_user_id, created_at),
            INDEX idx_routine_points_routine (routine_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function logRoutinePointsAward($routine_id, $child_id, $task_points, $bonus_points) {
    global $db;
    ensureRoutinePointsLogsTable();
    $stmt = $db->prepare("INSERT INTO routine_points_logs (routine_id, child_user_id, task_points, bonus_points, created_at)
                          VALUES (:routine_id, :child_id, :task_points, :bonus_points, NOW())");
    $stmt->execute([
        ':routine_id' => (int) $routine_id,
        ':child_id' => (int) $child_id,
        ':task_points' => (int) $task_points,
        ':bonus_points' => (int) $bonus_points
    ]);
}

function ensureRoutineCompletionTables() {
    global $db;
    $db->exec("
        CREATE TABLE IF NOT EXISTS routine_completion_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            routine_id INT NOT NULL,
            child_user_id INT NOT NULL,
            parent_user_id INT NOT NULL,
            completed_by ENUM('child','parent') NOT NULL DEFAULT 'child',
            started_at DATETIME NULL,
            completed_at DATETIME NOT NULL,
            status_screen_seconds INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_routine_completion_parent (parent_user_id, completed_at),
            INDEX idx_routine_completion_child (child_user_id, completed_at),
            FOREIGN KEY (routine_id) REFERENCES routines(id) ON DELETE CASCADE,
            FOREIGN KEY (child_user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (parent_user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $db->exec("
        CREATE TABLE IF NOT EXISTS routine_completion_tasks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            completion_log_id INT NOT NULL,
            routine_task_id INT NOT NULL,
            sequence_order INT NOT NULL DEFAULT 0,
            completed_at DATETIME NULL,
            scheduled_seconds INT NULL,
            actual_seconds INT NULL,
            status_screen_seconds INT NOT NULL DEFAULT 0,
            stars_awarded TINYINT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_routine_completion_task (completion_log_id, sequence_order),
            FOREIGN KEY (completion_log_id) REFERENCES routine_completion_logs(id) ON DELETE CASCADE,
            FOREIGN KEY (routine_task_id) REFERENCES routine_tasks(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    try {
        $db->exec("ALTER TABLE routine_completion_tasks ADD COLUMN IF NOT EXISTS scheduled_seconds INT NULL");
    } catch (PDOException $e) {
        // ignore if not supported
    }
    try {
        $db->exec("ALTER TABLE routine_completion_tasks ADD COLUMN IF NOT EXISTS actual_seconds INT NULL");
    } catch (PDOException $e) {
        // ignore if not supported
    }
    try {
        $db->exec("ALTER TABLE routine_completion_tasks ADD COLUMN IF NOT EXISTS stars_awarded TINYINT NOT NULL DEFAULT 0");
    } catch (PDOException $e) {
        // ignore if not supported
    }
}

function ensureFamilyLevelSettingsTable() {
    global $db;
    $db->exec("
        CREATE TABLE IF NOT EXISTS family_level_settings (
            parent_user_id INT PRIMARY KEY,
            stars_per_level INT NOT NULL DEFAULT 10,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (parent_user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function ensureChildLevelsTable() {
    global $db;
    $db->exec("
        CREATE TABLE IF NOT EXISTS child_levels (
            id INT AUTO_INCREMENT PRIMARY KEY,
            parent_user_id INT NOT NULL,
            child_user_id INT NOT NULL,
            current_level INT NOT NULL DEFAULT 1,
            pending_level_up TINYINT(1) NOT NULL DEFAULT 0,
            last_calculated_at DATETIME NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_child_level (parent_user_id, child_user_id),
            FOREIGN KEY (parent_user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (child_user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function getFamilyStarsPerLevel(int $parent_user_id): int {
    global $db;
    ensureFamilyLevelSettingsTable();
    $stmt = $db->prepare("SELECT stars_per_level FROM family_level_settings WHERE parent_user_id = :parent_id");
    $stmt->execute([':parent_id' => $parent_user_id]);
    $stars = $stmt->fetchColumn();
    if ($stars === false) {
        $insert = $db->prepare("INSERT INTO family_level_settings (parent_user_id, stars_per_level) VALUES (:parent_id, 10)");
        $insert->execute([':parent_id' => $parent_user_id]);
        return 10;
    }
    $stars = (int) $stars;
    return $stars > 0 ? $stars : 10;
}

function updateFamilyStarsPerLevel(int $parent_user_id, int $stars_per_level): bool {
    global $db;
    ensureFamilyLevelSettingsTable();
    $stars = max(1, $stars_per_level);
    $stmt = $db->prepare("
        INSERT INTO family_level_settings (parent_user_id, stars_per_level)
        VALUES (:parent_id, :stars)
        ON DUPLICATE KEY UPDATE stars_per_level = VALUES(stars_per_level)
    ");
    return $stmt->execute([
        ':parent_id' => $parent_user_id,
        ':stars' => $stars
    ]);
}

function calculateRoutineTaskStars(int $scheduledSeconds, int $actualSeconds): int {
    if ($scheduledSeconds <= 0) {
        return 3;
    }
    $overtime = $actualSeconds - $scheduledSeconds;
    if ($overtime <= 0) {
        return 3;
    }
    if ($overtime <= 60) {
        return 2;
    }
    return 1;
}



function ensureChildStarAdjustmentsTable(): void {
    global $db;
    $db->exec("
        CREATE TABLE IF NOT EXISTS child_star_adjustments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            child_user_id INT NOT NULL,
            delta_stars INT NOT NULL,
            reason VARCHAR(255) NOT NULL,
            created_by INT NOT NULL,
            created_at DATETIME NOT NULL,
            INDEX idx_child_created (child_user_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function getChildRollingStarsAverage(int $child_user_id, int $parent_user_id, int $weeks = 4): float {
    global $db;
    ensureRoutineCompletionTables();
    ensureChildStarAdjustmentsTable();
    $weeks = max(1, $weeks);
    $today = new DateTimeImmutable('today');
    $startOfWeek = $today->modify('monday this week');
    $totalStars = 0;
    $weekCount = 0;

    $stmt = $db->prepare("
        SELECT SUM(rct.stars_awarded)
        FROM routine_completion_tasks rct
        JOIN routine_completion_logs rcl ON rct.completion_log_id = rcl.id
        WHERE rcl.child_user_id = :child_id
          AND rcl.parent_user_id = :parent_id
          AND rcl.completed_at >= :week_start
          AND rcl.completed_at <= :week_end
    ");
    $adjustmentStmt = $db->prepare("
        SELECT SUM(delta_stars)
        FROM child_star_adjustments
        WHERE child_user_id = :child_id
          AND created_at >= :week_start
          AND created_at <= :week_end
    ");

    for ($i = 0; $i < $weeks; $i++) {
        $weekStart = $startOfWeek->modify("-{$i} week");
        $weekEnd = $weekStart->modify('+6 days 23:59:59');
        $stmt->execute([
            ':child_id' => $child_user_id,
            ':parent_id' => $parent_user_id,
            ':week_start' => $weekStart->format('Y-m-d H:i:s'),
            ':week_end' => $weekEnd->format('Y-m-d H:i:s')
        ]);
        $stars = (int) ($stmt->fetchColumn() ?: 0);
        $adjustmentStmt->execute([
            ':child_id' => $child_user_id,
            ':week_start' => $weekStart->format('Y-m-d H:i:s'),
            ':week_end' => $weekEnd->format('Y-m-d H:i:s')
        ]);
        $stars += (int) ($adjustmentStmt->fetchColumn() ?: 0);
        $totalStars += $stars;
        $weekCount++;
    }

    if ($weekCount === 0) {
        return 0.0;
    }
    return $totalStars / $weekCount;
}

function updateChildLevelState(int $child_user_id, int $parent_user_id, bool $triggerCelebration = false): array {
    global $db;
    ensureChildLevelsTable();
    $starsPerLevel = getFamilyStarsPerLevel($parent_user_id);
    $rollingAverage = getChildRollingStarsAverage($child_user_id, $parent_user_id, 4);
    $newLevel = max(1, (int) floor($rollingAverage / $starsPerLevel) + 1);
    $nextThreshold = $starsPerLevel * max(1, $newLevel);
    $starsToNext = max(0, (int) ceil($nextThreshold - $rollingAverage));

    $stmt = $db->prepare("SELECT id, current_level, pending_level_up FROM child_levels WHERE parent_user_id = :parent_id AND child_user_id = :child_id LIMIT 1");
    $stmt->execute([':parent_id' => $parent_user_id, ':child_id' => $child_user_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $pending = 0;
    $currentLevel = 1;

    if (!$row) {
        $pending = ($triggerCelebration && $newLevel > 1) ? 1 : 0;
        $insert = $db->prepare("
            INSERT INTO child_levels (parent_user_id, child_user_id, current_level, pending_level_up, last_calculated_at)
            VALUES (:parent_id, :child_id, :level, :pending, NOW())
        ");
        $insert->execute([
            ':parent_id' => $parent_user_id,
            ':child_id' => $child_user_id,
            ':level' => $newLevel,
            ':pending' => $pending
        ]);
        return [
            'level' => $newLevel,
            'pending' => $pending,
            'stars_per_level' => $starsPerLevel,
            'rolling_average' => $rollingAverage,
            'stars_to_next_level' => $starsToNext
        ];
    }

    $currentLevel = (int) ($row['current_level'] ?? 1);
    $pending = (int) ($row['pending_level_up'] ?? 0);
    $levelChanged = $newLevel !== $currentLevel;
    if ($levelChanged) {
        if ($triggerCelebration && $newLevel > $currentLevel) {
            $pending = 1;
        } elseif ($newLevel < $currentLevel) {
            $pending = 0;
        }
        $update = $db->prepare("
            UPDATE child_levels
            SET current_level = :level, pending_level_up = :pending, last_calculated_at = NOW()
            WHERE id = :id
        ");
        $update->execute([
            ':level' => $newLevel,
            ':pending' => $pending,
            ':id' => (int) $row['id']
        ]);
    } else {
        $db->prepare("UPDATE child_levels SET last_calculated_at = NOW() WHERE id = :id")
           ->execute([':id' => (int) $row['id']]);
    }

    return [
        'level' => $newLevel,
        'pending' => $pending,
        'stars_per_level' => $starsPerLevel,
        'rolling_average' => $rollingAverage,
        'stars_to_next_level' => $starsToNext
    ];
}

function getChildLevelState(int $child_user_id, int $parent_user_id): array {
    return updateChildLevelState($child_user_id, $parent_user_id, false);
}

function clearChildLevelCelebration(int $child_user_id, int $parent_user_id): void {
    global $db;
    ensureChildLevelsTable();
    $stmt = $db->prepare("
        UPDATE child_levels
        SET pending_level_up = 0
        WHERE parent_user_id = :parent_id AND child_user_id = :child_id
    ");
    $stmt->execute([':parent_id' => $parent_user_id, ':child_id' => $child_user_id]);
}

function logRoutineCompletionSession($routine_id, $child_id, $parent_id, $completed_by, $started_at, $completed_at, array $tasks = []) {
    global $db;
    ensureRoutineCompletionTables();
    $completed_by = in_array($completed_by, ['child', 'parent'], true) ? $completed_by : 'child';
    $completed_at = $completed_at ?: date('Y-m-d H:i:s');
    $statusTotal = 0;
    foreach ($tasks as $task) {
        $statusTotal += max(0, (int) ($task['status_screen_seconds'] ?? 0));
    }
    try {
        $db->beginTransaction();
        $stmt = $db->prepare("
            INSERT INTO routine_completion_logs
                (routine_id, child_user_id, parent_user_id, completed_by, started_at, completed_at, status_screen_seconds)
            VALUES
                (:routine_id, :child_id, :parent_id, :completed_by, :started_at, :completed_at, :status_total)
        ");
        $stmt->execute([
            ':routine_id' => (int) $routine_id,
            ':child_id' => (int) $child_id,
            ':parent_id' => (int) $parent_id,
            ':completed_by' => $completed_by,
            ':started_at' => $started_at ?: null,
            ':completed_at' => $completed_at,
            ':status_total' => $statusTotal
        ]);
        $logId = (int) $db->lastInsertId();
        if ($logId && !empty($tasks)) {
            $taskStmt = $db->prepare("
                INSERT INTO routine_completion_tasks
                    (completion_log_id, routine_task_id, sequence_order, completed_at, scheduled_seconds, actual_seconds, status_screen_seconds, stars_awarded)
                VALUES
                    (:log_id, :task_id, :sequence_order, :completed_at, :scheduled_seconds, :actual_seconds, :status_seconds, :stars_awarded)
            ");
            foreach ($tasks as $task) {
                $scheduledSeconds = null;
                if (array_key_exists('scheduled_seconds', $task)) {
                    $scheduledSeconds = (int) ($task['scheduled_seconds'] ?? 0);
                }
                $actualSeconds = null;
                if (array_key_exists('actual_seconds', $task)) {
                    $actualSeconds = (int) ($task['actual_seconds'] ?? 0);
                }
                $starsAwarded = 0;
                if (array_key_exists('stars_awarded', $task)) {
                    $starsAwarded = (int) ($task['stars_awarded'] ?? 0);
                }
                $taskStmt->execute([
                    ':log_id' => $logId,
                    ':task_id' => (int) ($task['routine_task_id'] ?? 0),
                    ':sequence_order' => (int) ($task['sequence_order'] ?? 0),
                    ':completed_at' => $task['completed_at'] ?: null,
                    ':scheduled_seconds' => $scheduledSeconds,
                    ':actual_seconds' => $actualSeconds,
                    ':status_seconds' => max(0, (int) ($task['status_screen_seconds'] ?? 0)),
                    ':stars_awarded' => max(0, min(3, $starsAwarded))
                ]);
            }
        }
        $db->commit();
        return $logId;
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Failed to log routine completion for routine $routine_id: " . $e->getMessage());
        return false;
    }
}

// Approve a task
function approveTask($task_id, $instance_date = null) {
    global $db;
    $stmt = $db->prepare("SELECT child_user_id, points, title, recurrence FROM tasks WHERE id = :id");
    $stmt->execute([':id' => $task_id]);
    $task = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($task) {
        $isRecurring = !empty($task['recurrence']);
        if ($isRecurring) {
            $dateKey = $instance_date ?: date('Y-m-d');
            $stmt = $db->prepare("
                UPDATE task_instances
                SET status = 'approved',
                    approved_at = NOW(),
                    updated_at = NOW()
                WHERE task_id = :task_id AND date_key = :date_key AND status = 'completed'
            ");
              if ($stmt->execute([':task_id' => $task_id, ':date_key' => $dateKey]) && $stmt->rowCount() > 0) {
                  updateChildPoints($task['child_user_id'], $task['points']);
                  addChildNotification(
                      (int)$task['child_user_id'],
                      'task_approved',
                      'Task approved: ' . ($task['title'] ?? 'Task'),
                      'task.php'
                  );
                  refreshTaskGoalsForChild((int) $task['child_user_id']);
                  return true;
              }
            return false;
        }

        $stmt = $db->prepare("UPDATE tasks SET status = 'approved', approved_at = NOW() WHERE id = :id AND status = 'completed'");
          if ($stmt->execute([':id' => $task_id])) {
              updateChildPoints($task['child_user_id'], $task['points']);
              addChildNotification(
                  (int)$task['child_user_id'],
                  'task_approved',
                  'Task approved: ' . ($task['title'] ?? 'Task'),
                  'task.php'
              );
              refreshTaskGoalsForChild((int) $task['child_user_id']);
              return true;
          }
    }
    return false;
}

function rejectTask($task_id, $parent_user_id, $note = '', $reactivate = false, $actor_id = null, $instance_date = null) {
    global $db;
    $stmt = $db->prepare("SELECT child_user_id, title, recurrence FROM tasks WHERE id = :id AND parent_user_id = :parent_id");
    $stmt->execute([':id' => $task_id, ':parent_id' => $parent_user_id]);
    $task = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$task) {
        return false;
    }

    $isRecurring = !empty($task['recurrence']);
    if ($isRecurring) {
        $dateKey = $instance_date ?: date('Y-m-d');
        if ($reactivate) {
            $stmt = $db->prepare("DELETE FROM task_instances WHERE task_id = :task_id AND date_key = :date_key");
            $ok = $stmt->execute([':task_id' => $task_id, ':date_key' => $dateKey]);
        } else {
            $stmt = $db->prepare("
                INSERT INTO task_instances (task_id, date_key, status, note, rejected_at, created_at)
                VALUES (:task_id, :date_key, 'rejected', :note, NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    status = 'rejected',
                    note = :note,
                    rejected_at = NOW(),
                    updated_at = NOW()
            ");
            $ok = $stmt->execute([
                ':task_id' => $task_id,
                ':date_key' => $dateKey,
                ':note' => $note !== '' ? $note : null
            ]);
        }
    } else {
        $status = $reactivate ? 'pending' : 'rejected';
        $stmt = $db->prepare("
            UPDATE tasks
            SET status = :status,
                completed_at = IF(:reactivate = 1, NULL, completed_at),
                approved_at = NULL,
                rejected_at = NOW(),
                rejected_note = :note,
                rejected_by = :rejected_by,
                photo_proof = IF(:reactivate = 1, NULL, photo_proof)
            WHERE id = :id AND parent_user_id = :parent_id AND status = 'completed'
        ");
        $ok = $stmt->execute([
            ':status' => $status,
            ':reactivate' => $reactivate ? 1 : 0,
            ':note' => $note !== '' ? $note : null,
            ':rejected_by' => $actor_id,
            ':id' => $task_id,
            ':parent_id' => $parent_user_id
        ]);
    }

    if ($ok) {
        $noteSuffix = $note !== '' ? " Note: $note" : '';
        if ($reactivate) {
            addChildNotification(
                (int)$task['child_user_id'],
                'task_rejected',
                'Task needs to be redone: ' . ($task['title'] ?? 'Task') . '.' . $noteSuffix,
                'task.php#task-' . (int)$task_id
            );
        } else {
            addChildNotification(
                (int)$task['child_user_id'],
                'task_rejected_closed',
                'Task rejected: ' . ($task['title'] ?? 'Task') . '.' . $noteSuffix,
                'task.php#task-' . (int)$task_id
            );
        }
    }

    return $ok;
}

// Create reward (optionally scoped to a child or template)
function createReward($parent_user_id, $title, $description, $point_cost, $child_user_id = null, $template_id = null) {
    global $db;
   $stmt = $db->prepare("INSERT INTO rewards (parent_user_id, child_user_id, template_id, title, description, point_cost, created_by) VALUES (:parent_id, :child_id, :template_id, :title, :description, :point_cost, :created_by)");
   return $stmt->execute([
      ':parent_id' => $parent_user_id,
      ':child_id' => $child_user_id,
      ':template_id' => $template_id,
      ':title' => $title,
      ':description' => $description,
      ':point_cost' => $point_cost,
      ':created_by' => $parent_user_id
   ]);
}

// Update reward (only while available)
function updateReward($parent_user_id, $reward_id, $title, $description, $point_cost) {
    global $db;
    $title = trim((string)$title);
    $description = trim((string)$description);
    $point_cost = max(1, (int)$point_cost);

    $stmt = $db->prepare("UPDATE rewards
                          SET title = :title,
                              description = :description,
                              point_cost = :point_cost
                          WHERE id = :reward_id
                            AND parent_user_id = :parent_id
                            AND status = 'available'");
    $stmt->execute([
        ':title' => $title,
        ':description' => $description,
        ':point_cost' => $point_cost,
        ':reward_id' => $reward_id,
        ':parent_id' => $parent_user_id
    ]);
    return $stmt->rowCount() > 0;
}

// Delete reward (only while available)
function deleteReward($parent_user_id, $reward_id) {
    global $db;
    $stmt = $db->prepare("DELETE FROM rewards WHERE id = :reward_id AND parent_user_id = :parent_id AND status = 'available'");
    $stmt->execute([
        ':reward_id' => $reward_id,
        ':parent_id' => $parent_user_id
    ]);
    return $stmt->rowCount() > 0;
}

// Reward library helpers
function createRewardTemplate($parent_user_id, $title, $description, $point_cost, $level_required = 1, $creator_user_id = null, $icon_class = null, $icon_color = null) {
    global $db;
    $stmt = $db->prepare("INSERT INTO reward_templates (parent_user_id, title, description, point_cost, level_required, icon_class, icon_color, created_by) VALUES (:parent_id, :title, :description, :point_cost, :level_required, :icon_class, :icon_color, :created_by)");
    $success = $stmt->execute([
        ':parent_id' => $parent_user_id,
        ':title' => trim((string)$title),
        ':description' => trim((string)$description),
        ':point_cost' => max(1, (int)$point_cost),
        ':level_required' => max(1, (int)$level_required),
        ':icon_class' => $icon_class,
        ':icon_color' => $icon_color,
        ':created_by' => $creator_user_id ?: $parent_user_id
    ]);
    return $success ? (int) $db->lastInsertId() : false;
}

function deleteRewardTemplate($parent_user_id, $template_id) {
    global $db;
    $stmt = $db->prepare("DELETE FROM reward_templates WHERE id = :template_id AND parent_user_id = :parent_id");
    $stmt->execute([
        ':template_id' => $template_id,
        ':parent_id' => $parent_user_id
    ]);
    return $stmt->rowCount() > 0;
}

function updateRewardTemplate($parent_user_id, $template_id, $title, $description, $point_cost, $level_required = 1, $icon_class = null, $icon_color = null) {
    global $db;
    $title = trim((string)$title);
    $description = trim((string)$description);
    $point_cost = max(1, (int)$point_cost);
    $level_required = max(1, (int)$level_required);
    $stmt = $db->prepare("UPDATE reward_templates
                          SET title = :title,
                              description = :description,
                              point_cost = :point_cost,
                              level_required = :level_required,
                              icon_class = :icon_class,
                              icon_color = :icon_color
                          WHERE id = :template_id AND parent_user_id = :parent_id");
    $stmt->execute([
        ':title' => $title,
        ':description' => $description,
        ':point_cost' => $point_cost,
        ':level_required' => $level_required,
        ':icon_class' => $icon_class,
        ':icon_color' => $icon_color,
        ':template_id' => $template_id,
        ':parent_id' => $parent_user_id
    ]);
    return $stmt->rowCount() > 0;
}

function duplicateRewardTemplate($parent_user_id, $template_id, $creator_user_id = null) {
    global $db;
    $template_id = (int) $template_id;
    if ($template_id <= 0) {
        return 0;
    }

    $fetch = $db->prepare("SELECT title, description, point_cost, level_required, icon_class, icon_color
                           FROM reward_templates
                           WHERE id = :template_id AND parent_user_id = :parent_id");
    $fetch->execute([
        ':template_id' => $template_id,
        ':parent_id' => (int) $parent_user_id
    ]);
    $template = $fetch->fetch(PDO::FETCH_ASSOC);
    if (!$template) {
        return 0;
    }

    $newTitle = 'Copy of ' . ($template['title'] ?? 'Reward');
    $insert = $db->prepare("INSERT INTO reward_templates (parent_user_id, title, description, point_cost, level_required, icon_class, icon_color, created_by)
                            VALUES (:parent_id, :title, :description, :point_cost, :level_required, :icon_class, :icon_color, :created_by)");
    $ok = $insert->execute([
        ':parent_id' => (int) $parent_user_id,
        ':title' => $newTitle,
        ':description' => $template['description'] ?? '',
        ':point_cost' => (int) ($template['point_cost'] ?? 1),
        ':level_required' => max(1, (int) ($template['level_required'] ?? 1)),
        ':icon_class' => $template['icon_class'] ?? null,
        ':icon_color' => $template['icon_color'] ?? null,
        ':created_by' => $creator_user_id ?? $parent_user_id
    ]);
    if (!$ok) {
        return 0;
    }
    $newId = (int) $db->lastInsertId();
    if ($newId <= 0) {
        return 0;
    }

    ensureRewardTemplateDisabledChildrenTable();
    $disabled = $db->prepare("SELECT child_user_id
                              FROM reward_template_disabled_children
                              WHERE parent_user_id = :parent_id AND template_id = :template_id");
    $disabled->execute([
        ':parent_id' => (int) $parent_user_id,
        ':template_id' => $template_id
    ]);
    $childIds = $disabled->fetchAll(PDO::FETCH_COLUMN) ?: [];
    if (!empty($childIds)) {
        setRewardTemplateDisabledChildren($parent_user_id, $newId, $childIds);
    }

    return $newId;
}

function getRewardTemplates($parent_user_id) {
    global $db;
    $stmt = $db->prepare("SELECT id, title, description, point_cost, level_required, icon_class, icon_color, created_at FROM reward_templates WHERE parent_user_id = :parent_id ORDER BY created_at DESC");
    $stmt->execute([':parent_id' => $parent_user_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function ensureRewardTemplateDisabledChildrenTable() {
    global $db;
    $db->exec("
        CREATE TABLE IF NOT EXISTS reward_template_disabled_children (
            id INT AUTO_INCREMENT PRIMARY KEY,
            parent_user_id INT NOT NULL,
            template_id INT NOT NULL,
            child_user_id INT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_template_child (template_id, child_user_id),
            INDEX idx_parent_template (parent_user_id, template_id),
            FOREIGN KEY (parent_user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (template_id) REFERENCES reward_templates(id) ON DELETE CASCADE,
            FOREIGN KEY (child_user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function getRewardTemplateDisabledMap($parent_user_id, array $child_user_ids = []) {
    global $db;
    ensureRewardTemplateDisabledChildrenTable();
    $child_user_ids = array_values(array_unique(array_filter(array_map('intval', $child_user_ids))));
    if (empty($child_user_ids)) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($child_user_ids), '?'));
    $params = array_merge([(int) $parent_user_id], $child_user_ids);
    $stmt = $db->prepare("SELECT template_id, child_user_id
                          FROM reward_template_disabled_children
                          WHERE parent_user_id = ? AND child_user_id IN ($placeholders)");
    $stmt->execute($params);
    $map = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $childId = (int) ($row['child_user_id'] ?? 0);
        $templateId = (int) ($row['template_id'] ?? 0);
        if ($childId && $templateId) {
            if (!isset($map[$childId])) {
                $map[$childId] = [];
            }
            $map[$childId][] = $templateId;
        }
    }
    return $map;
}

function setRewardTemplateDisabledChildren($parent_user_id, $template_id, array $child_user_ids): bool {
    global $db;
    ensureRewardTemplateDisabledChildrenTable();
    $template_id = (int) $template_id;
    if ($template_id <= 0) {
        return false;
    }
    $child_user_ids = array_values(array_unique(array_filter(array_map('intval', $child_user_ids))));
    $delete = $db->prepare("DELETE FROM reward_template_disabled_children WHERE parent_user_id = :parent_id AND template_id = :template_id");
    $delete->execute([
        ':parent_id' => (int) $parent_user_id,
        ':template_id' => $template_id
    ]);
    if (empty($child_user_ids)) {
        return true;
    }
    $insert = $db->prepare("INSERT INTO reward_template_disabled_children (parent_user_id, template_id, child_user_id) VALUES (:parent_id, :template_id, :child_id)");
    foreach ($child_user_ids as $child_id) {
        $insert->execute([
            ':parent_id' => (int) $parent_user_id,
            ':template_id' => $template_id,
            ':child_id' => (int) $child_id
        ]);
    }
    return true;
}

function assignTemplateToChildren($parent_user_id, $template_id, array $child_user_ids, $creator_user_id = null) {
    global $db;
    $child_user_ids = array_values(array_unique(array_filter(array_map('intval', $child_user_ids))));
    if (empty($child_user_ids)) {
        return 0;
    }

    $templateStmt = $db->prepare("SELECT title, description, point_cost FROM reward_templates WHERE id = :template_id AND parent_user_id = :parent_id");
    $templateStmt->execute([
        ':template_id' => $template_id,
        ':parent_id' => $parent_user_id
    ]);
    $template = $templateStmt->fetch(PDO::FETCH_ASSOC);
    if (!$template) {
        return 0;
    }

    $inserted = 0;
    foreach ($child_user_ids as $child_id) {
        // Skip if an available reward from this template already exists for this child
        $existing = $db->prepare("SELECT id FROM rewards WHERE parent_user_id = :parent_id AND child_user_id = :child_id AND template_id = :template_id AND status = 'available' LIMIT 1");
        $existing->execute([
            ':parent_id' => $parent_user_id,
            ':child_id' => $child_id,
            ':template_id' => $template_id
        ]);
        if ($existing->fetchColumn()) {
            continue;
        }

        $created = createReward(
            $parent_user_id,
            $template['title'],
            $template['description'],
            $template['point_cost'],
            $child_id,
            $template_id
        );
        if ($created) {
            $inserted++;
        }
    }
    return $inserted;
}

// Redeem reward
function redeemReward($child_user_id, $reward_id) {
    global $db;
    $parent_id = getFamilyRootId($child_user_id);
    $lockStmt = $db->prepare("SELECT 1
                              FROM goals
                              WHERE reward_id = :reward_id
                                AND award_mode IN ('reward', 'both')
                                AND status IN ('active', 'pending_approval', 'rejected')
                              LIMIT 1");
    $lockStmt->execute([':reward_id' => $reward_id]);
    if ($lockStmt->fetchColumn()) {
        return false;
    }
    $db->beginTransaction();
    try {
        $stmt = $db->prepare("SELECT title, point_cost FROM rewards WHERE id = :id AND status = 'available' AND parent_user_id = :parent_id AND (child_user_id IS NULL OR child_user_id = :child_id)");
        $stmt->execute([':id' => $reward_id, ':parent_id' => $parent_id, ':child_id' => $child_user_id]);
        $rewardRow = $stmt->fetch(PDO::FETCH_ASSOC);
        $point_cost = $rewardRow['point_cost'] ?? null;
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

        $stmt = $db->prepare("UPDATE rewards
                              SET status = 'redeemed',
                                  redeemed_by = :child_id,
                                  redeemed_on = NOW(),
                                  fulfilled_on = NULL,
                                  fulfilled_by = NULL,
                                  denied_on = NULL,
                                  denied_by = NULL,
                                  denied_note = NULL
                              WHERE id = :id");
        $stmt->execute([':child_id' => $child_user_id, ':id' => $reward_id]);

        $db->commit();
        // Notify parent
        $childName = getDisplayName($child_user_id);
        $title = $rewardRow['title'] ?? 'Reward';
        $message = ($childName ?: 'Child') . " purchased \"" . $title . "\" (" . (int)$point_cost . " pts). Awaiting fulfillment.";
        $link = "dashboard_parent.php?highlight_reward=" . (int)$reward_id . "#reward-" . (int)$reward_id;
        addParentNotification($parent_id, 'reward_redeemed', $message, $link);
        return true;
    } catch (Exception $e) {
        $db->rollBack();
        return false;
    }
}

// Purchase a reward directly from a template (child shop flow)
function purchaseRewardTemplate($child_user_id, $template_id, &$error = null) {
    global $db;
    $error = null;
    $child_id = (int) $child_user_id;
    $template_id = (int) $template_id;
    if ($child_id <= 0 || $template_id <= 0) {
        $error = 'invalid';
        return false;
    }
    $parent_id = getFamilyRootId($child_id);
    if (!$parent_id) {
        $error = 'invalid_parent';
        return false;
    }

    $templateStmt = $db->prepare("SELECT id, title, description, point_cost, level_required
                                  FROM reward_templates
                                  WHERE id = :template_id AND parent_user_id = :parent_id");
    $templateStmt->execute([
        ':template_id' => $template_id,
        ':parent_id' => (int) $parent_id
    ]);
    $template = $templateStmt->fetch(PDO::FETCH_ASSOC);
    if (!$template) {
        $error = 'not_found';
        return false;
    }

    ensureRewardTemplateDisabledChildrenTable();
    $disabledStmt = $db->prepare("SELECT 1 FROM reward_template_disabled_children
                                  WHERE parent_user_id = :parent_id
                                    AND template_id = :template_id
                                    AND child_user_id = :child_id
                                  LIMIT 1");
    $disabledStmt->execute([
        ':parent_id' => (int) $parent_id,
        ':template_id' => $template_id,
        ':child_id' => $child_id
    ]);
    if ($disabledStmt->fetchColumn()) {
        $error = 'disabled';
        return false;
    }

    $levelState = getChildLevelState($child_id, (int) $parent_id);
    $childLevel = (int) ($levelState['level'] ?? 1);
    $requiredLevel = max(1, (int) ($template['level_required'] ?? 1));
    if ($childLevel < $requiredLevel) {
        $error = 'level';
        return false;
    }

    $point_cost = (int) ($template['point_cost'] ?? 0);
    if ($point_cost <= 0) {
        $error = 'invalid_cost';
        return false;
    }

    $total_points = getChildTotalPoints($child_id);
    if ($total_points < $point_cost) {
        $error = 'points';
        return false;
    }

    $db->beginTransaction();
    try {
        $insert = $db->prepare("INSERT INTO rewards (parent_user_id, child_user_id, template_id, title, description, point_cost, status, redeemed_by, redeemed_on, created_by)
                                VALUES (:parent_id, :child_id, :template_id, :title, :description, :point_cost, 'redeemed', :redeemed_by, NOW(), :created_by)");
        $insert->execute([
            ':parent_id' => (int) $parent_id,
            ':child_id' => $child_id,
            ':template_id' => $template_id,
            ':title' => $template['title'],
            ':description' => $template['description'],
            ':point_cost' => $point_cost,
            ':redeemed_by' => $child_id,
            ':created_by' => $child_id
        ]);
        $reward_id = (int) $db->lastInsertId();
        if (!$reward_id || !updateChildPoints($child_id, -$point_cost)) {
            throw new Exception('points_update');
        }
        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        $error = 'failed';
        return false;
    }

    $childName = getDisplayName($child_id);
    $title = $template['title'] ?? 'Reward';
    $message = ($childName ?: 'Child') . " purchased \"" . $title . "\" (" . (int) $point_cost . " pts). Awaiting fulfillment.";
    $link = "dashboard_parent.php?highlight_reward=" . (int) $reward_id . "#reward-" . (int) $reward_id;
    addParentNotification((int) $parent_id, 'reward_redeemed', $message, $link);

    return $reward_id;
}

function fulfillReward($reward_id, $parent_user_id, $actor_user_id) {
    global $db;
    $stmt = $db->prepare("
        UPDATE rewards
        SET fulfilled_on = NOW(), fulfilled_by = :actor_id
        WHERE id = :reward_id
          AND parent_user_id = :parent_id
          AND status = 'redeemed'
          AND fulfilled_on IS NULL
    ");
    $stmt->execute([
        ':actor_id' => $actor_user_id,
        ':reward_id' => $reward_id,
        ':parent_id' => $parent_user_id
    ]);
    $updated = $stmt->rowCount() > 0;
    if ($updated) {
        $fetch = $db->prepare("SELECT redeemed_by, title FROM rewards WHERE id = :reward_id");
        $fetch->execute([':reward_id' => $reward_id]);
        $row = $fetch->fetch(PDO::FETCH_ASSOC);
        if ($row && !empty($row['redeemed_by'])) {
            addChildNotification(
                (int)$row['redeemed_by'],
                'reward_fulfilled',
                'Reward fulfilled: ' . ($row['title'] ?? 'Reward'),
                'dashboard_child.php'
            );
        }
    }
    return $updated;
}

function denyReward($reward_id, $parent_user_id, $actor_user_id, $note = null) {
    global $db;
    $db->beginTransaction();
    try {
        $fetch = $db->prepare("SELECT redeemed_by, point_cost, title
                               FROM rewards
                               WHERE id = :reward_id
                                 AND parent_user_id = :parent_id
                                 AND status = 'redeemed'
                                 AND fulfilled_on IS NULL");
        $fetch->execute([
            ':reward_id' => $reward_id,
            ':parent_id' => $parent_user_id
        ]);
        $row = $fetch->fetch(PDO::FETCH_ASSOC);
        $childId = (int) ($row['redeemed_by'] ?? 0);
        $pointCost = (int) ($row['point_cost'] ?? 0);
        if ($childId <= 0 || $pointCost <= 0) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            return false;
        }
        $noteValue = trim((string) $note);
        if ($noteValue === '') {
            $noteValue = null;
        }
        $stmt = $db->prepare("
            UPDATE rewards
            SET status = 'available',
                redeemed_by = NULL,
                redeemed_on = NULL,
                fulfilled_on = NULL,
                fulfilled_by = NULL,
                denied_on = NOW(),
                denied_by = :actor_id,
                denied_note = :note
            WHERE id = :reward_id
              AND parent_user_id = :parent_id
              AND status = 'redeemed'
              AND fulfilled_on IS NULL
        ");
        $stmt->execute([
            ':actor_id' => $actor_user_id,
            ':note' => $noteValue,
            ':reward_id' => $reward_id,
            ':parent_id' => $parent_user_id
        ]);
        if ($stmt->rowCount() <= 0) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            return false;
        }
        updateChildPoints($childId, $pointCost);
        $title = $row['title'] ?? 'Reward';
        $message = 'Reward request denied: ' . $title;
        if ($noteValue) {
            $message .= ' | Reason: ' . $noteValue;
        }
        addChildNotification($childId, 'reward_denied', $message, 'dashboard_child.php');
        $db->commit();
        return true;
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        return false;
    }
}

// Create goal
function normalizeGoalDateTimeInput($value, $offsetMinutes = null) {
    $raw = trim((string) $value);
    if ($raw === '') {
        return null;
    }
    $serverTz = new DateTimeZone(defined('APP_TIMEZONE') ? APP_TIMEZONE : date_default_timezone_get());
    $offset = filter_var($offsetMinutes, FILTER_VALIDATE_INT, ['options' => ['default' => null]]);
    if ($offset === null) {
        try {
            $parsed = new DateTimeImmutable($raw, $serverTz);
            return $parsed->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            return $raw;
        }
    }
    $offset = (int) $offset;
    $sign = $offset > 0 ? '-' : '+';
    $abs = abs($offset);
    $hours = intdiv($abs, 60);
    $minutes = $abs % 60;
    $offsetTz = new DateTimeZone(sprintf('%s%02d:%02d', $sign, $hours, $minutes));
    $parsed = DateTimeImmutable::createFromFormat('Y-m-d\\TH:i', $raw, $offsetTz);
    if (!$parsed) {
        try {
            $parsed = new DateTimeImmutable($raw, $offsetTz);
        } catch (Exception $e) {
            return $raw;
        }
    }
    return $parsed->setTimezone($serverTz)->format('Y-m-d H:i:s');
}

function createGoal($parent_user_id, $child_user_id, $title, $start_date, $end_date, $reward_id = null, $creator_user_id = null, array $options = []) {
    global $db;
    $description = isset($options['description']) ? trim((string) $options['description']) : null;
    if ($description === '') {
        $description = null;
    }
    $goalType = $options['goal_type'] ?? 'manual';
    $routineId = !empty($options['routine_id']) ? (int) $options['routine_id'] : null;
    $taskCategory = $options['task_category'] ?? null;
    $targetCount = isset($options['target_count']) ? (int) $options['target_count'] : 0;
    $streakRequired = isset($options['streak_required']) ? (int) $options['streak_required'] : 0;
    $requireOnTime = !empty($options['require_on_time']) ? 1 : 0;
    $pointsAwarded = isset($options['points_awarded']) ? (int) $options['points_awarded'] : 0;
    $awardMode = $options['award_mode'] ?? 'both';
    $requiresApproval = array_key_exists('requires_parent_approval', $options) ? (!empty($options['requires_parent_approval']) ? 1 : 0) : 1;
    $taskTargets = $options['task_target_ids'] ?? [];
    $routineTargets = $options['routine_target_ids'] ?? [];

    $stmt = $db->prepare("INSERT INTO goals (parent_user_id, child_user_id, title, description, target_points, start_date, end_date, reward_id, goal_type, routine_id, task_category, target_count, streak_required, require_on_time, points_awarded, award_mode, requires_parent_approval, created_by)
                          VALUES (:parent_id, :child_id, :title, :description, :target_points, :start_date, :end_date, :reward_id, :goal_type, :routine_id, :task_category, :target_count, :streak_required, :require_on_time, :points_awarded, :award_mode, :requires_parent_approval, :created_by)");
    $ok = $stmt->execute([
        ':parent_id' => $parent_user_id,
        ':child_id' => $child_user_id,
        ':title' => $title,
        ':description' => $description,
        ':target_points' => 0,
        ':start_date' => $start_date,
        ':end_date' => $end_date,
        ':reward_id' => $reward_id,
        ':goal_type' => $goalType,
        ':routine_id' => $routineId,
        ':task_category' => $taskCategory,
        ':target_count' => $targetCount,
        ':streak_required' => $streakRequired,
        ':require_on_time' => $requireOnTime,
        ':points_awarded' => $pointsAwarded,
        ':award_mode' => $awardMode,
        ':requires_parent_approval' => $requiresApproval,
        ':created_by' => $creator_user_id ?? $parent_user_id
    ]);
    if (!$ok) {
        return false;
    }
    $goalId = (int) $db->lastInsertId();
    if ($goalId && !empty($taskTargets)) {
        saveGoalTaskTargets($goalId, $taskTargets);
    }
    if ($goalId && !empty($routineTargets)) {
        saveGoalRoutineTargets($goalId, $routineTargets);
    }
    return true;
}

// Keep existing updateGoal function (for editing goal details)
function updateGoal($goal_id, $parent_user_id, $title, $start_date, $end_date, $reward_id = null, array $options = []) {
    global $db;
    $stmt = $db->prepare("SELECT * FROM goals WHERE id = :goal_id AND parent_user_id = :parent_id");
    $stmt->execute([':goal_id' => $goal_id, ':parent_id' => $parent_user_id]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$existing) {
        return false;
    }

    $description = array_key_exists('description', $options) ? trim((string) ($options['description'] ?? '')) : ($existing['description'] ?? null);
    if ($description === '') {
        $description = null;
    }
    $childId = array_key_exists('child_user_id', $options) ? (int) $options['child_user_id'] : (int) ($existing['child_user_id'] ?? 0);
    $goalType = $options['goal_type'] ?? ($existing['goal_type'] ?? 'manual');
    $routineId = array_key_exists('routine_id', $options) ? (!empty($options['routine_id']) ? (int) $options['routine_id'] : null) : ($existing['routine_id'] ?? null);
    $taskCategory = array_key_exists('task_category', $options) ? ($options['task_category'] ?? null) : ($existing['task_category'] ?? null);
    $targetCount = array_key_exists('target_count', $options) ? (int) $options['target_count'] : (int) ($existing['target_count'] ?? 0);
    $streakRequired = array_key_exists('streak_required', $options) ? (int) $options['streak_required'] : (int) ($existing['streak_required'] ?? 0);
    $requireOnTime = array_key_exists('require_on_time', $options) ? (!empty($options['require_on_time']) ? 1 : 0) : (int) ($existing['require_on_time'] ?? 0);
    $pointsAwarded = array_key_exists('points_awarded', $options) ? (int) $options['points_awarded'] : (int) ($existing['points_awarded'] ?? 0);
    $awardMode = array_key_exists('award_mode', $options) ? ($options['award_mode'] ?? 'both') : ($existing['award_mode'] ?? 'both');
    $requiresApproval = array_key_exists('requires_parent_approval', $options) ? (!empty($options['requires_parent_approval']) ? 1 : 0) : (int) ($existing['requires_parent_approval'] ?? 1);
    $routineTargets = array_key_exists('routine_target_ids', $options) ? $options['routine_target_ids'] : null;

    if (array_key_exists('goal_type', $options) && $goalType === 'manual') {
        $routineId = null;
        $taskCategory = null;
        $targetCount = 0;
        $streakRequired = 0;
        $requireOnTime = 0;
        $routineTargets = [];
    }
    if (array_key_exists('goal_type', $options) && $goalType === 'task_quota') {
        $routineId = null;
        $routineTargets = [];
    }

    $stmt = $db->prepare("UPDATE goals 
                         SET child_user_id = :child_id,
                             title = :title, 
                             description = :description,
                             target_points = 0, 
                             start_date = :start_date, 
                             end_date = :end_date, 
                             reward_id = :reward_id,
                             goal_type = :goal_type,
                             routine_id = :routine_id,
                             task_category = :task_category,
                             target_count = :target_count,
                             streak_required = :streak_required,
                             require_on_time = :require_on_time,
                             points_awarded = :points_awarded,
                             award_mode = :award_mode,
                             requires_parent_approval = :requires_parent_approval
                         WHERE id = :goal_id 
                         AND parent_user_id = :parent_id");
    $ok = $stmt->execute([
        ':goal_id' => $goal_id,
        ':parent_id' => $parent_user_id,
        ':child_id' => $childId,
        ':title' => $title,
        ':description' => $description,
        ':start_date' => $start_date,
        ':end_date' => $end_date,
        ':reward_id' => $reward_id,
        ':goal_type' => $goalType,
        ':routine_id' => $routineId,
        ':task_category' => $taskCategory,
        ':target_count' => $targetCount,
        ':streak_required' => $streakRequired,
        ':require_on_time' => $requireOnTime,
        ':points_awarded' => $pointsAwarded,
        ':award_mode' => $awardMode,
        ':requires_parent_approval' => $requiresApproval
    ]);
    if (!$ok) {
        return false;
    }
    if (array_key_exists('task_target_ids', $options)) {
        saveGoalTaskTargets($goal_id, $options['task_target_ids'] ?? []);
    }
    if ($routineTargets !== null) {
        saveGoalRoutineTargets($goal_id, $routineTargets);
    }
    $updated = $db->prepare("SELECT * FROM goals WHERE id = :goal_id");
    $updated->execute([':goal_id' => $goal_id]);
    $goalRow = $updated->fetch(PDO::FETCH_ASSOC);
    if ($goalRow && in_array(($goalRow['status'] ?? ''), ['active', 'pending_approval'], true)) {
        refreshGoalProgress($goalRow, $goalRow['child_user_id']);
    }
    return true;
}

function saveGoalTaskTargets($goal_id, array $task_ids) {
    global $db;
    $goal_id = (int) $goal_id;
    $task_ids = array_values(array_filter(array_map('intval', $task_ids)));
    $db->prepare("DELETE FROM goal_task_targets WHERE goal_id = :goal_id")->execute([':goal_id' => $goal_id]);
    if (empty($task_ids)) {
        return true;
    }
    $stmt = $db->prepare("INSERT INTO goal_task_targets (goal_id, task_id) VALUES (:goal_id, :task_id)");
    foreach ($task_ids as $taskId) {
        $stmt->execute([':goal_id' => $goal_id, ':task_id' => $taskId]);
    }
    return true;
}

function saveGoalRoutineTargets($goal_id, array $routine_ids) {
    global $db;
    $goal_id = (int) $goal_id;
    $routine_ids = array_values(array_filter(array_map('intval', $routine_ids)));
    $db->prepare("DELETE FROM goal_routine_targets WHERE goal_id = :goal_id")->execute([':goal_id' => $goal_id]);
    if (empty($routine_ids)) {
        return true;
    }
    $stmt = $db->prepare("INSERT INTO goal_routine_targets (goal_id, routine_id) VALUES (:goal_id, :routine_id)");
    foreach ($routine_ids as $routine_id) {
        $stmt->execute([':goal_id' => $goal_id, ':routine_id' => $routine_id]);
    }
    return true;
}

function getGoalTaskTargetIds($goal_id) {
    global $db;
    $stmt = $db->prepare("SELECT task_id FROM goal_task_targets WHERE goal_id = :goal_id");
    $stmt->execute([':goal_id' => (int) $goal_id]);
    return array_values(array_filter(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN))));
}

function getGoalRoutineTargetIds($goal_id) {
    global $db;
    $stmt = $db->prepare("SELECT routine_id FROM goal_routine_targets WHERE goal_id = :goal_id");
    $stmt->execute([':goal_id' => (int) $goal_id]);
    return array_values(array_filter(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN))));
}

function getRoutineCompletionSummary(array $routine_ids, $child_id, $require_on_time, $start_date = null, $end_date = null) {
    global $db;
    $routine_ids = array_values(array_filter(array_map('intval', $routine_ids)));
    if (empty($routine_ids)) {
        return [
            'routine_counts' => [],
            'routine_dates' => [],
            'all_dates' => [],
            'any_dates' => []
        ];
    }

    $placeholders = implode(',', array_fill(0, count($routine_ids), '?'));
    $params = $routine_ids;
    $params[] = (int) $child_id;
    $dateFilterSql = '';
    if ($start_date && $end_date) {
        $dateFilterSql = " AND DATE(created_at) BETWEEN ? AND ?";
        $params[] = $start_date;
        $params[] = $end_date;
    }
    $stmt = $db->prepare("SELECT routine_id, DATE(created_at) AS date_key
                          FROM routine_points_logs
                          WHERE routine_id IN ($placeholders) AND child_user_id = ?{$dateFilterSql}
                          GROUP BY routine_id, DATE(created_at)
                          ORDER BY date_key ASC");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $routineDates = [];
    foreach ($rows as $row) {
        $dateKey = $row['date_key'] ?? null;
        $routineId = (int) ($row['routine_id'] ?? 0);
        if ($dateKey && $routineId) {
            if (!isset($routineDates[$routineId])) {
                $routineDates[$routineId] = [];
            }
            $routineDates[$routineId][$dateKey] = true;
        }
    }
    if (empty($routineDates)) {
        return [
            'routine_counts' => [],
            'routine_dates' => [],
            'all_dates' => [],
            'any_dates' => []
        ];
    }

    $otParams = $routine_ids;
    $otParams[] = (int) $child_id;
    $otDateFilterSql = '';
    if ($start_date && $end_date) {
        $otDateFilterSql = " AND DATE(occurred_at) BETWEEN ? AND ?";
        $otParams[] = $start_date;
        $otParams[] = $end_date;
    }
    $otStmt = $db->prepare("SELECT DISTINCT routine_id, DATE(occurred_at) AS date_key
                            FROM routine_overtime_logs
                            WHERE routine_id IN ($placeholders) AND child_user_id = ?{$otDateFilterSql}");
    $otStmt->execute($otParams);
    $overtimeRows = $otStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $overtimeByRoutineDate = [];
    foreach ($overtimeRows as $row) {
        $routineId = (int) ($row['routine_id'] ?? 0);
        $dateKey = $row['date_key'] ?? null;
        if ($routineId && $dateKey) {
            if (!isset($overtimeByRoutineDate[$routineId])) {
                $overtimeByRoutineDate[$routineId] = [];
            }
            $overtimeByRoutineDate[$routineId][$dateKey] = true;
        }
    }

    if ($require_on_time) {
        foreach ($routineDates as $routineId => $dates) {
            foreach (array_keys($dates) as $dateKey) {
                if (!empty($overtimeByRoutineDate[$routineId][$dateKey])) {
                    unset($routineDates[$routineId][$dateKey]);
                }
            }
            if (empty($routineDates[$routineId])) {
                unset($routineDates[$routineId]);
            }
        }
        if (empty($routineDates)) {
            return [
                'routine_counts' => [],
                'routine_dates' => [],
                'all_dates' => [],
                'any_dates' => []
            ];
        }
    }

    $routineCounts = [];
    $taskCounts = [];
    $taskCounts = [];
    foreach ($routineDates as $routineId => $dates) {
        $routineCounts[$routineId] = count($dates);
    }

    $anyDates = [];
    foreach ($routineDates as $dates) {
        foreach (array_keys($dates) as $dateKey) {
            $anyDates[$dateKey] = true;
        }
    }

    $allDates = null;
    foreach ($routine_ids as $routineId) {
        $dates = array_keys($routineDates[$routineId] ?? []);
        if ($allDates === null) {
            $allDates = $dates;
            continue;
        }
        $allDates = array_values(array_intersect($allDates, $dates));
    }
    if ($allDates === null) {
        $allDates = [];
    }
    sort($allDates);

    $anyList = array_keys($anyDates);
    sort($anyList);
    return [
        'routine_counts' => $routineCounts,
        'routine_dates' => $routineDates,
        'all_dates' => $allDates,
        'any_dates' => $anyList
    ];
}
function awardGoalReward($goal, $child_id) {
    global $db;
    $rewardId = isset($goal['reward_id']) ? (int) $goal['reward_id'] : 0;
    if ($rewardId <= 0) {
        return false;
    }
    $stmt = $db->prepare("SELECT id, title, status FROM rewards WHERE id = :id");
    $stmt->execute([':id' => $rewardId]);
    $reward = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$reward || ($reward['status'] ?? '') !== 'available') {
        return false;
    }
    $db->prepare("UPDATE rewards SET status = 'redeemed', redeemed_by = :child_id, redeemed_on = NOW() WHERE id = :id")
       ->execute([':child_id' => (int) $child_id, ':id' => $rewardId]);
    $title = $reward['title'] ?? 'Reward';
    $message = "Goal reward earned: " . $title;
    $parentId = (int) ($goal['parent_user_id'] ?? 0);
    if ($parentId) {
        $link = "dashboard_parent.php?highlight_reward=" . $rewardId . "#reward-" . $rewardId;
        addParentNotification($parentId, 'goal_reward_earned', $message, $link);
    }
    addChildNotification((int) $child_id, 'goal_reward_earned', $message, 'goal.php');
    return true;
}

function markGoalCompleted($goal, $child_id, $note = null, $notifyParent = true) {
    global $db;
    $goalId = (int) ($goal['id'] ?? 0);
    if ($goalId <= 0) {
        return false;
    }
    $stmt = $db->prepare("UPDATE goals SET status = 'completed', completed_at = NOW() WHERE id = :goal_id");
    $stmt->execute([':goal_id' => $goalId]);
    $db->prepare("INSERT INTO goal_progress (goal_id, child_user_id, celebration_shown) VALUES (:goal_id, :child_id, 0)
                  ON DUPLICATE KEY UPDATE child_user_id = VALUES(child_user_id), celebration_shown = 0")
       ->execute([':goal_id' => $goalId, ':child_id' => (int) $child_id]);
    $message = $note ?: ('Goal completed: ' . ($goal['title'] ?? 'Goal'));
    addChildNotification((int) $child_id, 'goal_completed', $message, 'goal.php');
    $parentId = (int) ($goal['parent_user_id'] ?? 0);
    if ($notifyParent && $parentId) {
        addParentNotification($parentId, 'goal_completed', $message, 'goal.php');
    }
    $awardMode = $goal['award_mode'] ?? 'both';
    $pointsAwarded = isset($goal['points_awarded']) ? (int) $goal['points_awarded'] : 0;
    if ($pointsAwarded > 0 && in_array($awardMode, ['points', 'both'], true)) {
        updateChildPoints((int) $child_id, $pointsAwarded);
        $goalTitle = $goal['title'] ?? 'Goal';
        $goalLink = $goalId > 0 ? ('goal.php#goal-' . $goalId) : 'goal.php';
        addChildNotification((int) $child_id, 'goal_points_awarded', "You earned {$pointsAwarded} points for completing: {$goalTitle}", $goalLink);
    }
    if (!empty($goal['reward_id']) && in_array($awardMode, ['reward', 'both'], true)) {
        awardGoalReward($goal, $child_id);
    }
    return true;
}

function markGoalPendingApproval($goal) {
    global $db;
    $goalId = (int) ($goal['id'] ?? 0);
    if ($goalId <= 0) {
        return false;
    }
    $stmt = $db->prepare("UPDATE goals SET status = 'pending_approval', requested_at = NOW() WHERE id = :goal_id AND status = 'active'");
    $stmt->execute([':goal_id' => $goalId]);
    if ($stmt->rowCount() <= 0) {
        return false;
    }
    $childId = (int) ($goal['child_user_id'] ?? 0);
    $title = $goal['title'] ?? 'Goal';
    addChildNotification($childId, 'goal_ready', "Goal ready for approval: {$title}", 'goal.php');
    $parentId = (int) ($goal['parent_user_id'] ?? 0);
    if ($parentId) {
        addParentNotification($parentId, 'goal_ready', "Goal ready for approval: {$title}", 'goal.php');
    }
    return true;
}

function markGoalIncomplete($goal, $child_id, $reason = null) {
    global $db;
    $goalId = (int) ($goal['id'] ?? 0);
    if ($goalId <= 0) {
        return false;
    }
    $note = trim((string) $reason);
    if ($note === '') {
        $note = 'Incomplete: End date reached before completing the goal.';
    } elseif (stripos($note, 'Incomplete') !== 0) {
        $note = 'Incomplete: ' . $note;
    }
    $stmt = $db->prepare("UPDATE goals
                          SET status = 'rejected',
                              rejected_at = NOW(),
                              rejection_comment = :comment
                          WHERE id = :goal_id AND status = 'active'");
    $stmt->execute([
        ':comment' => $note,
        ':goal_id' => $goalId
    ]);
    if ($stmt->rowCount() <= 0) {
        return false;
    }
    $title = $goal['title'] ?? 'Goal';
    $message = "Goal incomplete: {$title}. End date reached before the goal requirements were met.";
    addChildNotification((int) $child_id, 'goal_incomplete', $message, 'goal.php');
    $parentId = (int) ($goal['parent_user_id'] ?? 0);
    if ($parentId) {
        addParentNotification($parentId, 'goal_incomplete', $message, 'goal.php');
    }
    return true;
}

function getGoalWindowRange(array $goal) {
    $now = new DateTimeImmutable();
    $todayStart = $now->setTime(0, 0, 0);
    $todayEnd = $now->setTime(23, 59, 59);
    $start = null;
    $end = null;
    if (!$start && !empty($goal['start_date'])) {
        $start = new DateTimeImmutable($goal['start_date']);
    }
    if (!$end && !empty($goal['end_date'])) {
        $end = new DateTimeImmutable($goal['end_date']);
    }
    if (!$start) {
        $start = $todayStart;
    }
    if (!$end) {
        $end = $todayEnd;
    }
    return [$start, $end];
}

function ensureChildStreaksTable() {
    global $db;
    $db->exec("
        CREATE TABLE IF NOT EXISTS child_streak_records (
            child_user_id INT NOT NULL PRIMARY KEY,
            routine_best_streak INT NOT NULL DEFAULT 0,
            task_best_streak INT NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL,
            INDEX idx_child_streak_updated (updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    try {
        $db->exec("ALTER TABLE child_streak_records ADD COLUMN routine_best_streak INT NOT NULL DEFAULT 0");
    } catch (PDOException $e) {
        // ignore if it already exists
    }
    try {
        $db->exec("ALTER TABLE child_streak_records ADD COLUMN task_best_streak INT NOT NULL DEFAULT 0");
    } catch (PDOException $e) {
        // ignore if it already exists
    }
    try {
        $db->exec("ALTER TABLE child_streak_records ADD COLUMN updated_at DATETIME NOT NULL");
    } catch (PDOException $e) {
        // ignore if it already exists
    }
}

function calculateConsecutiveStreak(array $dates): int {
    if (empty($dates)) {
        return 0;
    }
    $dateSet = array_fill_keys(array_unique($dates), true);
    $today = new DateTimeImmutable('today');
    $cursor = $today;
    $todayKey = $today->format('Y-m-d');
    if (!isset($dateSet[$todayKey])) {
        $cursor = $today->modify('-1 day');
        if (!isset($dateSet[$cursor->format('Y-m-d')])) {
            return 0;
        }
    }
    $count = 0;
    while (isset($dateSet[$cursor->format('Y-m-d')])) {
        $count++;
        $cursor = $cursor->modify('-1 day');
    }
    return $count;
}

function getChildStreaks(int $child_user_id, int $parent_user_id = null): array {
    global $db;
    $routineDates = [];
    $taskDates = [];
    $routineWeekDates = [];
    $taskWeekDates = [];
    ensureRoutineCompletionTables();
    ensureChildStreaksTable();

    $params = [':child_id' => $child_user_id];
    $parentFilter = '';
    if ($parent_user_id) {
        $parentFilter = ' AND rcl.parent_user_id = :parent_id';
        $params[':parent_id'] = $parent_user_id;
    }
    $routineStmt = $db->prepare("
        SELECT DISTINCT DATE(rcl.completed_at) AS date_key
        FROM routine_completion_logs rcl
        WHERE rcl.child_user_id = :child_id
          AND rcl.completed_at IS NOT NULL
          {$parentFilter}
    ");
    $routineStmt->execute($params);
    $routineDates = $routineStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $routineDates = array_values(array_unique(array_filter($routineDates)));
    $routineCompletionSet = array_fill_keys($routineDates, true);

    $routineDefStmt = $db->prepare("
        SELECT id, recurrence, recurrence_days, routine_date, created_at
        FROM routines
        WHERE child_user_id = :child_id
    ");
    $routineDefStmt->execute([':child_id' => $child_user_id]);
    $routineDefs = $routineDefStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $taskDefStmt = $db->prepare("
        SELECT id, due_date, end_date, recurrence, recurrence_days
        FROM tasks
        WHERE child_user_id = :child_id
          AND recurrence IS NOT NULL
          AND recurrence != ''
          AND due_date IS NOT NULL
    ");
    $taskDefStmt->execute([':child_id' => $child_user_id]);
    $taskDefs = $taskDefStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $normalizeDays = static function ($raw): array {
        return array_values(array_filter(array_map('trim', explode(',', (string) $raw))));
    };

    $isRoutineScheduled = static function (string $dateKey) use ($routineDefs, $normalizeDays): bool {
        $dayShort = date('D', strtotime($dateKey));
        foreach ($routineDefs as $routine) {
            $recurrence = $routine['recurrence'] ?? '';
            if ($recurrence === '') {
                $routineDate = $routine['routine_date'] ?? null;
                if ($routineDate && $routineDate === $dateKey) {
                    return true;
                }
                continue;
            }
            $startKey = !empty($routine['created_at']) ? date('Y-m-d', strtotime($routine['created_at'])) : $dateKey;
            if ($dateKey < $startKey) {
                continue;
            }
            if ($recurrence === 'daily') {
                return true;
            }
            if ($recurrence === 'weekly') {
                $days = $normalizeDays($routine['recurrence_days'] ?? '');
                if (empty($days) || in_array($dayShort, $days, true)) {
                    return true;
                }
            }
        }
        return false;
    };

    $isTaskScheduled = static function (string $dateKey) use ($taskDefs, $normalizeDays): bool {
        $dayShort = date('D', strtotime($dateKey));
        foreach ($taskDefs as $task) {
            $startKey = !empty($task['due_date']) ? date('Y-m-d', strtotime($task['due_date'])) : null;
            if ($startKey && $dateKey < $startKey) {
                continue;
            }
            $endKey = !empty($task['end_date']) ? $task['end_date'] : null;
            if ($endKey && $dateKey > $endKey) {
                continue;
            }
            $recurrence = $task['recurrence'] ?? '';
            if ($recurrence === 'daily') {
                return true;
            }
            if ($recurrence === 'weekly') {
                $days = $normalizeDays($task['recurrence_days'] ?? '');
                if (empty($days) || in_array($dayShort, $days, true)) {
                    return true;
                }
            }
        }
        return false;
    };

    $taskInstanceStmt = $db->prepare("
        SELECT ti.date_key, ti.completed_at
        FROM task_instances ti
        JOIN tasks t ON t.id = ti.task_id
        WHERE t.child_user_id = :child_id
          AND t.recurrence IS NOT NULL
          AND t.recurrence != ''
          AND ti.completed_at IS NOT NULL
          AND (ti.status IS NULL OR ti.status != 'rejected')
    ");
    $taskInstanceStmt->execute([':child_id' => $child_user_id]);
    $recurringTaskRows = $taskInstanceStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($recurringTaskRows as $row) {
        $dateKey = $row['date_key'] ?? '';
        if ($dateKey === '' || empty($row['completed_at'])) {
            continue;
        }
        $completedKey = date('Y-m-d', strtotime((string) $row['completed_at']));
        if ($completedKey !== $dateKey) {
            continue;
        }
        $taskDates[] = $dateKey;
    }
    $taskDates = array_values(array_unique(array_filter($taskDates)));
    $taskCompletionSet = array_fill_keys($taskDates, true);

    $calcScheduledStreak = static function (callable $isScheduled, array $completionSet): int {
        $cursor = new DateTimeImmutable('today');
        $streak = 0;
        for ($i = 0; $i < 365; $i++) {
            $dateKey = $cursor->format('Y-m-d');
            if ($isScheduled($dateKey)) {
                if (!empty($completionSet[$dateKey])) {
                    $streak++;
                } else {
                    break;
                }
            }
            $cursor = $cursor->modify('-1 day');
        }
        return $streak;
    };

    $routineStreak = $calcScheduledStreak($isRoutineScheduled, $routineCompletionSet);
    $taskStreak = $calcScheduledStreak($isTaskScheduled, $taskCompletionSet);

    $weekStart = new DateTimeImmutable('monday this week');
    $weekEnd = $weekStart->modify('+6 days');
    $rollingStart = new DateTimeImmutable('today -6 days');
    $rollingEnd = new DateTimeImmutable('today');
    $weekCursor = $rollingStart;
    $routineScheduledCount = 0;
    $routineCompletedCount = 0;
    $taskScheduledCount = 0;
    $taskCompletedCount = 0;
    while ($weekCursor <= $rollingEnd) {
        $dateKey = $weekCursor->format('Y-m-d');
        if ($isRoutineScheduled($dateKey)) {
            $routineScheduledCount++;
            if (!empty($routineCompletionSet[$dateKey])) {
                $routineCompletedCount++;
                $routineWeekDates[] = $dateKey;
            }
        }
        if ($isTaskScheduled($dateKey)) {
            $taskScheduledCount++;
            if (!empty($taskCompletionSet[$dateKey])) {
                $taskCompletedCount++;
                $taskWeekDates[] = $dateKey;
            }
        }
        $weekCursor = $weekCursor->modify('+1 day');
    }
    $routineWeekDates = array_values(array_unique($routineWeekDates));
    $taskWeekDates = array_values(array_unique($taskWeekDates));
    $routineOnTimeRate = $routineScheduledCount > 0 ? (int) round(($routineCompletedCount / $routineScheduledCount) * 100) : 0;
    $taskOnTimeRate = $taskScheduledCount > 0 ? (int) round(($taskCompletedCount / $taskScheduledCount) * 100) : 0;

    $weekStartStamp = $weekStart->setTime(0, 0, 0)->format('Y-m-d H:i:s');
    $weekEndStamp = $weekEnd->setTime(23, 59, 59)->format('Y-m-d H:i:s');
    $nonRecurringCount = 0;
    $recurringCount = 0;
    $nonRecurringStmt = $db->prepare("
        SELECT COUNT(*)
        FROM tasks
        WHERE child_user_id = :child_id
          AND (recurrence IS NULL OR recurrence = '')
          AND COALESCE(approved_at, completed_at) IS NOT NULL
          AND COALESCE(approved_at, completed_at) BETWEEN :week_start AND :week_end
    ");
    $nonRecurringStmt->execute([
        ':child_id' => $child_user_id,
        ':week_start' => $weekStartStamp,
        ':week_end' => $weekEndStamp
    ]);
    $nonRecurringCount = (int) ($nonRecurringStmt->fetchColumn() ?: 0);

    $recurringStmt = $db->prepare("
        SELECT COUNT(*)
        FROM task_instances ti
        JOIN tasks t ON t.id = ti.task_id
        WHERE t.child_user_id = :child_id
          AND t.recurrence IS NOT NULL
          AND t.recurrence != ''
          AND COALESCE(ti.approved_at, ti.completed_at) IS NOT NULL
          AND COALESCE(ti.approved_at, ti.completed_at) BETWEEN :week_start AND :week_end
          AND (ti.status IS NULL OR ti.status != 'rejected')
    ");
    $recurringStmt->execute([
        ':child_id' => $child_user_id,
        ':week_start' => $weekStartStamp,
        ':week_end' => $weekEndStamp
    ]);
    $recurringCount = (int) ($recurringStmt->fetchColumn() ?: 0);
    $weeklyTaskCompletedCount = $nonRecurringCount + $recurringCount;

    $bestRoutineStreak = 0;
    $bestTaskStreak = 0;
    $bestStmt = $db->prepare("SELECT routine_best_streak, task_best_streak FROM child_streak_records WHERE child_user_id = :child_id LIMIT 1");
    $bestStmt->execute([':child_id' => $child_user_id]);
    $bestRow = $bestStmt->fetch(PDO::FETCH_ASSOC);
    if ($bestRow) {
        $bestRoutineStreak = (int) ($bestRow['routine_best_streak'] ?? 0);
        $bestTaskStreak = (int) ($bestRow['task_best_streak'] ?? 0);
    }
    if ($routineStreak > $bestRoutineStreak || $taskStreak > $bestTaskStreak) {
        $bestRoutineStreak = max($bestRoutineStreak, $routineStreak);
        $bestTaskStreak = max($bestTaskStreak, $taskStreak);
        $db->prepare("
            INSERT INTO child_streak_records (child_user_id, routine_best_streak, task_best_streak, updated_at)
            VALUES (:child_id, :routine_best, :task_best, NOW())
            ON DUPLICATE KEY UPDATE
                routine_best_streak = VALUES(routine_best_streak),
                task_best_streak = VALUES(task_best_streak),
                updated_at = NOW()
        ")->execute([
            ':child_id' => $child_user_id,
            ':routine_best' => $bestRoutineStreak,
            ':task_best' => $bestTaskStreak
        ]);
    } elseif (!$bestRow) {
        $db->prepare("
            INSERT INTO child_streak_records (child_user_id, routine_best_streak, task_best_streak, updated_at)
            VALUES (:child_id, :routine_best, :task_best, NOW())
        ")->execute([
            ':child_id' => $child_user_id,
            ':routine_best' => $routineStreak,
            ':task_best' => $taskStreak
        ]);
        $bestRoutineStreak = $routineStreak;
        $bestTaskStreak = $taskStreak;
    }

    return [
        'routine_streak' => $routineStreak,
        'task_streak' => $taskStreak,
        'routine_week_dates' => $routineWeekDates,
        'task_week_dates' => $taskWeekDates,
        'weekly_task_completed_count' => $weeklyTaskCompletedCount,
        'routine_on_time_rate' => $routineOnTimeRate,
        'task_on_time_rate' => $taskOnTimeRate,
        'routine_best_streak' => $bestRoutineStreak,
        'task_best_streak' => $bestTaskStreak
    ];
}

function getDueTimestampForTask(array $task, string $dateKey = null) {
    $timeOfDay = $task['time_of_day'] ?? 'anytime';
    $dueDate = $task['due_date'] ?? null;
    if (empty($dateKey)) {
        $dateKey = !empty($dueDate) ? date('Y-m-d', strtotime($dueDate)) : date('Y-m-d');
    }
    $timeValue = !empty($dueDate) ? date('H:i', strtotime($dueDate)) : '';
    $hasExplicitTime = $timeValue !== '' && $timeValue !== '00:00';
    if ($hasExplicitTime) {
        $stamp = strtotime($dateKey . ' ' . $timeValue . ':00');
        return $stamp === false ? null : $stamp;
    }
    if ($timeOfDay !== 'anytime') {
        $fallback = $timeOfDay === 'morning' ? '08:00' : ($timeOfDay === 'afternoon' ? '13:00' : '18:00');
        $stamp = strtotime($dateKey . ' ' . $fallback . ':00');
        return $stamp === false ? null : $stamp;
    }
    $stamp = strtotime($dateKey . ' 23:59:59');
    return $stamp === false ? null : $stamp;
}

function calculateGoalProgress(array $goal, $child_id) {
    global $db;
    $goalType = $goal['goal_type'] ?? 'manual';
    $goalType = $goalType !== '' ? $goalType : 'manual';
    $status = $goal['status'] ?? 'active';
    $title = $goal['title'] ?? 'Goal';
    $requireOnTime = !empty($goal['require_on_time']);
    $routineCounts = [];
    $taskCounts = [];
    $target = 1;
    $current = 0;
    $currentStreak = 0;
    $lastProgressDate = null;
    $nextHint = null;

    if ($goalType === 'routine_streak') {
        $target = max(1, (int) ($goal['streak_required'] ?? 0));
        $routineIds = getGoalRoutineTargetIds((int) ($goal['id'] ?? 0));
        if (empty($routineIds)) {
            $routineId = (int) ($goal['routine_id'] ?? 0);
            if ($routineId > 0) {
                $routineIds = [$routineId];
            }
        }
        if (!empty($routineIds)) {
            $summary = getRoutineCompletionSummary($routineIds, $child_id, $requireOnTime);
            $routineCounts = $summary['routine_counts'] ?? [];
            $dates = $summary['all_dates'] ?? [];
            if (!empty($dates)) {
                $lastDate = end($dates);
                $lastProgressDate = $lastDate;
                $currentStreak = 1;
                for ($i = count($dates) - 2; $i >= 0; $i--) {
                    $prev = $dates[$i];
                    $expected = date('Y-m-d', strtotime($lastDate . ' -1 day'));
                    if ($prev === $expected) {
                        $currentStreak++;
                        $lastDate = $prev;
                    } else {
                        break;
                    }
                }
                $current = $currentStreak;
            }
        }
        $remaining = max(0, $target - $currentStreak);
        if ($remaining > 0) {
            $today = date('Y-m-d');
            if ($lastProgressDate === $today) {
                $nextHint = "Keep the streak! Complete it tomorrow to reach day " . ($currentStreak + 1) . ".";
            } elseif ($lastProgressDate === date('Y-m-d', strtotime('-1 day'))) {
                $nextHint = "Complete it today for day " . ($currentStreak + 1) . ".";
            } else {
                $nextHint = count($routineIds) > 1
                    ? "Complete all routines today to start your streak."
                    : "Complete the routine today to start your streak.";
            }
        }
    } elseif ($goalType === 'routine_count') {
        $target = max(1, (int) ($goal['target_count'] ?? 0));
        $routineIds = getGoalRoutineTargetIds((int) ($goal['id'] ?? 0));
        if (empty($routineIds)) {
            $routineId = (int) ($goal['routine_id'] ?? 0);
            if ($routineId > 0) {
                $routineIds = [$routineId];
            }
        }
        if (!empty($routineIds)) {
            [$start, $end] = getGoalWindowRange($goal);
            $summary = getRoutineCompletionSummary(
                $routineIds,
                $child_id,
                $requireOnTime,
                $start->format('Y-m-d'),
                $end->format('Y-m-d')
            );
            $routineCounts = $summary['routine_counts'] ?? [];
            $dates = $summary['all_dates'] ?? [];
            $current = count($dates);
            $lastProgressDate = !empty($dates) ? max($dates) : null;
            $remaining = max(0, $target - $current);
            if ($remaining > 0) {
                $routineLabel = count($routineIds) > 1 ? 'all routines' : 'the routine';
                $nextHint = "Complete {$routineLabel} {$remaining} more time(s) by " . $end->format('m/d');
            }
        }
    } elseif ($goalType === 'task_quota') {
        $target = max(1, (int) ($goal['target_count'] ?? 0));
        [$start, $end] = getGoalWindowRange($goal);
        $taskTargetIds = getGoalTaskTargetIds((int) ($goal['id'] ?? 0));
        $taskCategory = $goal['task_category'] ?? null;
        $taskFilterSql = '';
        $params = [(int) $child_id, $start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')];
        if (!empty($taskTargetIds)) {
            $placeholders = implode(',', array_fill(0, count($taskTargetIds), '?'));
            $taskFilterSql = " AND t.id IN ($placeholders)";
            $params = array_merge($params, $taskTargetIds);
        } elseif (!empty($taskCategory)) {
            $taskFilterSql = " AND t.category = ?";
            $params[] = $taskCategory;
        }

        $nonRecurringSql = "
            SELECT t.id, t.due_date, t.time_of_day, t.completed_at, t.approved_at
            FROM tasks t
            WHERE t.child_user_id = ?
              AND (t.recurrence IS NULL OR t.recurrence = '')
              AND t.status IN ('completed', 'approved')
              AND COALESCE(t.completed_at, t.approved_at) IS NOT NULL
              AND COALESCE(t.completed_at, t.approved_at) BETWEEN ? AND ?
              {$taskFilterSql}
        ";
        $stmt = $db->prepare($nonRecurringSql);
        $stmt->execute($params);
        $nonRecurring = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $recurringSql = "
            SELECT t.id, t.due_date, t.time_of_day, ti.date_key, ti.completed_at, ti.approved_at
            FROM task_instances ti
            JOIN tasks t ON t.id = ti.task_id
            WHERE t.child_user_id = ?
              AND (t.recurrence IS NOT NULL AND t.recurrence != '')
              AND ti.status IN ('completed', 'approved')
              AND COALESCE(ti.completed_at, ti.approved_at) IS NOT NULL
              AND COALESCE(ti.completed_at, ti.approved_at) BETWEEN ? AND ?
              {$taskFilterSql}
        ";
        $stmt = $db->prepare($recurringSql);
        $stmt->execute($params);
        $recurring = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $count = 0;
        $lastDate = null;
        $checkOnTime = static function ($row, $dateKeyOverride = null) {
            $dueStamp = getDueTimestampForTask($row, $dateKeyOverride);
            $completedAt = $row['completed_at'] ?? $row['approved_at'] ?? null;
            if (!$completedAt || !$dueStamp) {
                return false;
            }
            return strtotime($completedAt) <= $dueStamp;
        };
        foreach ($nonRecurring as $row) {
            if ($requireOnTime && !$checkOnTime($row)) {
                continue;
            }
            $count++;
            $taskId = (int) ($row['id'] ?? 0);
            if ($taskId > 0) {
                $taskCounts[$taskId] = ($taskCounts[$taskId] ?? 0) + 1;
            }
            $rowDate = !empty($row['completed_at']) ? date('Y-m-d', strtotime($row['completed_at'])) : (!empty($row['approved_at']) ? date('Y-m-d', strtotime($row['approved_at'])) : null);
            if ($rowDate) {
                $lastDate = $lastDate ? max($lastDate, $rowDate) : $rowDate;
            }
        }
        foreach ($recurring as $row) {
            $dateKey = $row['date_key'] ?? null;
            if ($requireOnTime && !$checkOnTime($row, $dateKey)) {
                continue;
            }
            $count++;
            $taskId = (int) ($row['id'] ?? 0);
            if ($taskId > 0) {
                $taskCounts[$taskId] = ($taskCounts[$taskId] ?? 0) + 1;
            }
            if ($dateKey) {
                $lastDate = $lastDate ? max($lastDate, $dateKey) : $dateKey;
            }
        }
        $current = $count;
        $lastProgressDate = $lastDate;
        $remaining = max(0, $target - $current);
        if ($remaining > 0) {
            $nextHint = "Complete {$remaining} more task(s) by " . $end->format('m/d') . ".";
        }
    } else {
        $target = 1;
        $current = $status === 'completed' ? 1 : 0;
        if ($current === 0 && $status === 'active') {
            $nextHint = "Request completion when you're done.";
        }
    }

    $isMet = ($goalType === 'routine_streak') ? ($currentStreak >= $target) : ($current >= $target);
    if ($status === 'completed') {
        $current = $target;
        $currentStreak = max($currentStreak, $target);
        $isMet = true;
    }

    $percent = $target > 0 ? min(100, (int) round(($current / $target) * 100)) : 0;
    return [
        'goal_id' => (int) ($goal['id'] ?? 0),
        'title' => $title,
        'goal_type' => $goalType,
        'status' => $status,
        'target' => $target,
        'current' => $current,
        'current_streak' => $currentStreak,
        'percent' => $percent,
        'last_progress_date' => $lastProgressDate,
        'next_needed' => $nextHint,
        'is_met' => $isMet,
        'routine_counts' => $routineCounts,
        'task_counts' => $taskCounts
    ];
}

function refreshGoalProgress(array $goal, $child_id) {
    global $db;
    $progress = calculateGoalProgress($goal, $child_id);
    $goalId = (int) ($goal['id'] ?? 0);
    if ($goalId > 0) {
        $db->prepare("INSERT INTO goal_progress (goal_id, child_user_id, current_count, current_streak, last_progress_date, next_needed_hint)
                      VALUES (:goal_id, :child_id, :current_count, :current_streak, :last_progress_date, :next_needed_hint)
                      ON DUPLICATE KEY UPDATE
                        child_user_id = VALUES(child_user_id),
                        current_count = VALUES(current_count),
                        current_streak = VALUES(current_streak),
                        last_progress_date = VALUES(last_progress_date),
                        next_needed_hint = VALUES(next_needed_hint)")
            ->execute([
                ':goal_id' => $goalId,
                ':child_id' => (int) $child_id,
                ':current_count' => (int) $progress['current'],
                ':current_streak' => (int) $progress['current_streak'],
                ':last_progress_date' => $progress['last_progress_date'],
                ':next_needed_hint' => $progress['next_needed']
            ]);
    }
    $status = $goal['status'] ?? 'active';
    $goalType = $goal['goal_type'] ?? 'manual';
    if ($status === 'pending_approval' && !$progress['is_met'] && $goalType !== 'manual') {
        $db->prepare("UPDATE goals SET status = 'active', requested_at = NULL WHERE id = :goal_id AND status = 'pending_approval'")
           ->execute([':goal_id' => $goalId]);
        $status = 'active';
    }
    if (!$progress['is_met'] && $status === 'active' && !empty($goal['end_date'])) {
        $endStamp = strtotime($goal['end_date']);
        if ($endStamp && $endStamp < time()) {
            markGoalIncomplete($goal, $child_id);
            return $progress;
        }
    }
    $requiresApproval = !empty($goal['requires_parent_approval']);
    if ($progress['is_met'] && $status === 'active') {
        if ($requiresApproval) {
            markGoalPendingApproval($goal);
        } else {
            markGoalCompleted($goal, $child_id);
        }
    }
    return $progress;
}

function autoCloseExpiredGoals($parent_id = null, $child_id = null) {
    global $db;
    $filters = ["status = 'active'", "end_date IS NOT NULL", "end_date < NOW()"];
    $params = [];
    if ($parent_id !== null) {
        $filters[] = "parent_user_id = :parent_id";
        $params[':parent_id'] = (int) $parent_id;
    }
    if ($child_id !== null) {
        $filters[] = "child_user_id = :child_id";
        $params[':child_id'] = (int) $child_id;
    }
    $where = implode(' AND ', $filters);
    $stmt = $db->prepare("SELECT * FROM goals WHERE {$where}");
    $stmt->execute($params);
    $goals = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($goals as $goal) {
        $childId = (int) ($goal['child_user_id'] ?? 0);
        if ($childId > 0) {
            refreshGoalProgress($goal, $childId);
        }
    }
}

function refreshTaskGoalsForChild($child_id) {
    global $db;
    $stmt = $db->prepare("SELECT * FROM goals WHERE child_user_id = :child_id AND status = 'active' AND goal_type = 'task_quota'");
    $stmt->execute([':child_id' => (int) $child_id]);
    $goals = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($goals as $goal) {
        refreshGoalProgress($goal, $child_id);
    }
}

function refreshRoutineGoalsForChild($child_id, $routine_id) {
    global $db;
    $stmt = $db->prepare("SELECT DISTINCT g.*
                          FROM goals g
                          LEFT JOIN goal_routine_targets grt ON g.id = grt.goal_id
                          WHERE g.child_user_id = :child_id
                            AND g.status = 'active'
                            AND g.goal_type IN ('routine_streak', 'routine_count')
                            AND (g.routine_id = :routine_id OR grt.routine_id = :routine_id)");
    $stmt->execute([
        ':child_id' => (int) $child_id,
        ':routine_id' => (int) $routine_id
    ]);
    $goals = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($goals as $goal) {
        refreshGoalProgress($goal, $child_id);
    }
}

function getGoalProgressSnapshot(array $goal, $child_id) {
    global $db;
    $progress = refreshGoalProgress($goal, $child_id);
    $celebrationShown = 1;
    $goalId = (int) ($goal['id'] ?? 0);
    if ($goalId > 0) {
        $stmt = $db->prepare("SELECT celebration_shown FROM goal_progress WHERE goal_id = :goal_id");
        $stmt->execute([':goal_id' => $goalId]);
        $celebrationShown = (int) ($stmt->fetchColumn() ?? 1);
    }
    $celebrationReady = ($goal['status'] ?? 'active') === 'completed' && $celebrationShown === 0;
    return [
        'progress' => $progress,
        'celebration_ready' => $celebrationReady
    ];
}

function markGoalCelebrationShown($goal_id) {
    global $db;
    $db->prepare("UPDATE goal_progress SET celebration_shown = 1 WHERE goal_id = :goal_id")
       ->execute([':goal_id' => (int) $goal_id]);
}

// Add back requestGoalCompletion function
function requestGoalCompletion($goal_id, $child_user_id) {
    global $db;
    $stmt = $db->prepare("SELECT * FROM goals WHERE id = :goal_id AND child_user_id = :child_id AND status = 'active'");
    $stmt->execute([':goal_id' => $goal_id, ':child_id' => $child_user_id]);
    $goal = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$goal) {
        return false;
    }
    if (!empty($goal['requires_parent_approval'])) {
        return markGoalPendingApproval($goal);
    }
    return markGoalCompleted($goal, $child_user_id);
}

// Add back approveGoal function
function approveGoal($goal_id, $parent_user_id) {
    global $db;
    $managesTransaction = !$db->inTransaction();
    try {
        if ($managesTransaction) {
            $db->beginTransaction();
        }
        
        $stmt = $db->prepare("SELECT * 
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
            markGoalCompleted($goal, (int) $goal['child_user_id'], 'Goal approved: ' . ($goal['title'] ?? 'Goal'), false);
            
            if ($managesTransaction && $db->inTransaction()) {
                $db->commit();
            }
            return true;
        }
        
        if ($managesTransaction && $db->inTransaction()) {
            $db->rollBack();
        }
        return false;
    } catch (Exception $e) {
        if ($managesTransaction && $db->inTransaction()) {
            $db->rollBack();
        }
        error_log("Goal approval failed: " . $e->getMessage());
        return false;
    }
}

// Add back rejectGoal function
function rejectGoal($goal_id, $parent_user_id, $rejection_comment, &$error = null) {
    global $db;
    try {
        $detail = $db->prepare("SELECT child_user_id, title FROM goals WHERE id = :goal_id AND parent_user_id = :parent_id AND status = 'pending_approval'");
        $detail->execute([
            ':goal_id' => $goal_id,
            ':parent_id' => $parent_user_id
        ]);
        $goal = $detail->fetch(PDO::FETCH_ASSOC);
        if (!$goal) {
            $error = 'No pending approval goal found for this parent.';
            return false; // No pending approval to reject
        }

        $stmt = $db->prepare("UPDATE goals 
                             SET status = 'rejected', 
                                 rejected_at = NOW(), 
                                 rejection_comment = :comment 
                             WHERE id = :goal_id 
                             AND parent_user_id = :parent_id 
                             AND status = 'pending_approval'");
        $stmt->execute([
            ':goal_id' => $goal_id,
            ':parent_id' => $parent_user_id,
            ':comment' => $rejection_comment
        ]);
        if ($stmt->rowCount() > 0) {
            $note = trim((string) $rejection_comment);
            $message = 'Goal denied: ' . ($goal['title'] ?? 'Goal');
            if ($note !== '') {
                $message .= ' | Reason: ' . $note;
            }
            addChildNotification(
                (int)$goal['child_user_id'],
                'goal_rejected',
                $message,
                'dashboard_child.php'
            );
            return true;
        }
        $error = 'Goal could not be updated.';
        return false;
    } catch (Exception $e) {
        $error = $e->getMessage();
        error_log("Goal rejection failed: " . $e->getMessage());
        return false;
    }
}

// **[New] Routine Task Functions **
function createRoutineTask($parent_user_id, $title, $description, $time_limit, $point_value, $category, $minimum_seconds = null, $minimum_enabled = 0, $icon_url = null, $audio_url = null, $creator_user_id = null) {
    global $db;
    if ($minimum_seconds !== null) {
        $minimum_seconds = max(0, (int) $minimum_seconds);
    }
    $minimum_enabled = $minimum_enabled ? 1 : 0;
    if ($minimum_seconds === null || $minimum_seconds === 0) {
        $minimum_seconds = null;
        $minimum_enabled = 0;
    }
    $stmt = $db->prepare("INSERT INTO routine_tasks (parent_user_id, title, description, time_limit, point_value, category, minimum_seconds, minimum_enabled, icon_url, audio_url, created_by) VALUES (:parent_id, :title, :description, :time_limit, :point_value, :category, :minimum_seconds, :minimum_enabled, :icon_url, :audio_url, :created_by)");
    return $stmt->execute([
        ':parent_id' => $parent_user_id,
        ':title' => $title,
        ':description' => $description,
        ':time_limit' => $time_limit,
        ':point_value' => $point_value,
        ':category' => $category,
        ':minimum_seconds' => $minimum_seconds,
        ':minimum_enabled' => $minimum_enabled,
        ':icon_url' => $icon_url,
        ':audio_url' => $audio_url,
        ':created_by' => $creator_user_id ?? $parent_user_id
    ]);
}

function getRoutineTasks($parent_user_id) {
    global $db;
    // Include global defaults (parent_id = 0) and parent-specific
    $stmt = $db->prepare("SELECT * FROM routine_tasks WHERE parent_user_id = 0 OR parent_user_id = :parent_id");
    $stmt->execute([':parent_id' => $parent_user_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getRoutineTasksByIds($parent_user_id, array $task_ids) {
    global $db;
    if (empty($task_ids)) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($task_ids), '?'));
    $sql = "SELECT * FROM routine_tasks 
            WHERE id IN ($placeholders) 
              AND (parent_user_id = 0 OR parent_user_id = ?)";
    $params = array_map('intval', $task_ids);
    $params[] = $parent_user_id;
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $indexed = [];
    foreach ($results as $row) {
        $indexed[$row['id']] = $row;
    }
    return $indexed;
}

function calculateRoutineDurationMinutes($start_time, $end_time) {
    if (!$start_time || !$end_time) {
        return null;
    }
    $baseDate = date('Y-m-d');
    $formats = ['Y-m-d H:i', 'Y-m-d H:i:s'];
    $start = $end = false;
    foreach ($formats as $format) {
        if (!$start) {
            $start = DateTime::createFromFormat($format, $baseDate . ' ' . $start_time);
        }
        if (!$end) {
            $end = DateTime::createFromFormat($format, $baseDate . ' ' . $end_time);
        }
        if ($start && $end) {
            break;
        }
    }
    if (!$start || !$end) {
        return null;
    }
    $duration = ($end->getTimestamp() - $start->getTimestamp()) / 60;
    if ($duration <= 0) {
        $end = $end->modify('+1 day');
        $duration = ($end->getTimestamp() - $start->getTimestamp()) / 60;
    }
    return (int) round($duration);
}

function replaceRoutineTasks($routine_id, array $tasks) {
    global $db;
    $stmt = $db->prepare("DELETE FROM routines_routine_tasks WHERE routine_id = :routine_id");
    $stmt->execute([':routine_id' => $routine_id]);
    foreach ($tasks as $task) {
        $taskId = (int) ($task['id'] ?? 0);
        if ($taskId <= 0) {
            continue;
        }
        $sequence = (int) ($task['sequence_order'] ?? 0);
        if ($sequence <= 0) {
            continue;
        }
        $dependencyId = $task['dependency_id'] !== null ? (int) $task['dependency_id'] : null;
        addRoutineTaskToRoutine($routine_id, $taskId, $sequence, $dependencyId);
    }
    return true;
}

function getRoutinePreferences($parent_user_id) {
    global $db;
    $stmt = $db->prepare("SELECT timer_warnings_enabled, sub_timer_label, show_countdown, progress_style, sound_effects_enabled, background_music_enabled FROM routine_preferences WHERE parent_user_id = :parent LIMIT 1");
    $stmt->execute([':parent' => $parent_user_id]);
    $prefs = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$prefs) {
        return [
            'timer_warnings_enabled' => 1,
            'sub_timer_label' => 'hurry_goal',
            'show_countdown' => 1,
            'progress_style' => 'bar',
            'sound_effects_enabled' => 1,
            'background_music_enabled' => 1,
            '__from_db' => false,
        ];
    }

    $prefs['timer_warnings_enabled'] = (int) $prefs['timer_warnings_enabled'];

    if (!isset($prefs['sub_timer_label']) || $prefs['sub_timer_label'] === '') {
        $prefs['sub_timer_label'] = 'hurry_goal';
    }

    if (!array_key_exists('show_countdown', $prefs)) {
        $prefs['show_countdown'] = 1;
    }
    $prefs['show_countdown'] = (int) $prefs['show_countdown'];

    if (!isset($prefs['progress_style']) || !in_array($prefs['progress_style'], ['bar', 'circle', 'pie'], true)) {
        $prefs['progress_style'] = 'bar';
    }

    if (!array_key_exists('sound_effects_enabled', $prefs)) {
        $prefs['sound_effects_enabled'] = 1;
    }
    $prefs['sound_effects_enabled'] = (int) $prefs['sound_effects_enabled'];

    if (!array_key_exists('background_music_enabled', $prefs)) {
        $prefs['background_music_enabled'] = 1;
    }
    $prefs['background_music_enabled'] = (int) $prefs['background_music_enabled'];

    $prefs['__from_db'] = true;

    return $prefs;
}

function saveRoutinePreferences($parent_user_id, $timer_warnings_enabled, $sub_timer_label, $show_countdown, $progress_style = 'bar', $sound_effects_enabled = 1, $background_music_enabled = 1) {
    global $db;
    
    // Validate label
    $validLabels = [
        'hurry_goal',
        'adjusted_time',
        'routine_target',
        'quick_finish',
        'new_limit'
    ];
    
    if (!in_array($sub_timer_label, $validLabels)) {
        error_log("Invalid timer label attempted: $sub_timer_label");
        $sub_timer_label = 'hurry_goal'; // Fallback to default
    }

    if (!in_array($progress_style, ['bar', 'circle', 'pie'], true)) {
        $progress_style = 'bar';
    }
    
    $stmt = $db->prepare("INSERT INTO routine_preferences (parent_user_id, timer_warnings_enabled, sub_timer_label, show_countdown, progress_style, sound_effects_enabled, background_music_enabled)
                          VALUES (:parent_id, :timer_enabled, :label, :countdown, :progress_style, :sfx_enabled, :music_enabled)
                          ON DUPLICATE KEY UPDATE timer_warnings_enabled = VALUES(timer_warnings_enabled),
                                                  sub_timer_label = VALUES(sub_timer_label),
                                                  show_countdown = VALUES(show_countdown),
                                                  progress_style = VALUES(progress_style),
                                                  sound_effects_enabled = VALUES(sound_effects_enabled),
                                                  background_music_enabled = VALUES(background_music_enabled)");
    
    $result = $stmt->execute([
        ':parent_id' => $parent_user_id,
        ':timer_enabled' => $timer_warnings_enabled ? 1 : 0,
        ':label' => $sub_timer_label,
        ':countdown' => $show_countdown ? 1 : 0,
        ':progress_style' => $progress_style,
        ':sfx_enabled' => $sound_effects_enabled ? 1 : 0,
        ':music_enabled' => $background_music_enabled ? 1 : 0
    ]);
    
    if ($result) {
        error_log("Routine preferences updated for parent $parent_user_id: timer_warnings=$timer_warnings_enabled, label=$sub_timer_label, show_countdown=$show_countdown, style=$progress_style, sfx=$sound_effects_enabled, music=$background_music_enabled");
    } else {
        error_log("Failed to update routine preferences for parent $parent_user_id");
    }
    
    return $result;
}

function logRoutineOvertime($routine_id, $routine_task_id, $child_user_id, $scheduled_seconds, $actual_seconds, $overtime_seconds) {
    global $db;
    $stmt = $db->prepare("INSERT INTO routine_overtime_logs (routine_id, routine_task_id, child_user_id, scheduled_seconds, actual_seconds, overtime_seconds)
                          VALUES (:routine_id, :task_id, :child_id, :scheduled, :actual, :overtime)");
    return $stmt->execute([
        ':routine_id' => $routine_id,
        ':task_id' => $routine_task_id,
        ':child_id' => $child_user_id,
        ':scheduled' => (int) $scheduled_seconds,
        ':actual' => (int) $actual_seconds,
        ':overtime' => (int) $overtime_seconds
    ]);
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
        $stmt = $db->prepare("SELECT * FROM goals WHERE id = :goal_id AND child_user_id = :child_id AND status = 'active'");
        $stmt->execute([':goal_id' => $goal_id, ':child_id' => $child_user_id]);
        $goal = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$goal) {
            $db->rollBack();
            return false;
        }

        markGoalCompleted($goal, $child_user_id);

        $db->commit();
        return true;
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

function getChildTotalPoints($child_id) {
    global $db;
    try {
        $stmt = $db->prepare("SELECT total_points FROM child_points WHERE child_user_id = :child_id");
        $stmt->execute([':child_id' => $child_id]);
        $points = $stmt->fetchColumn();
        return $points !== false ? (int)$points : 0;
    } catch (Exception $e) {
        error_log("Failed to fetch points for child $child_id: " . $e->getMessage());
        return 0;
    }
}

// **[Revised] Routine Functions (now use routine_task_id instead of task_id) **
function createRoutine($parent_user_id, $child_user_id, $title, $start_time, $end_time, $recurrence, $bonus_points, $time_of_day = 'anytime', $recurrence_days = null, $routine_date = null, $creator_user_id = null) {
    global $db;
    $stmt = $db->prepare("INSERT INTO routines (parent_user_id, child_user_id, title, start_time, end_time, recurrence, bonus_points, time_of_day, recurrence_days, routine_date, created_by) VALUES (:parent_id, :child_id, :title, :start_time, :end_time, :recurrence, :bonus_points, :time_of_day, :recurrence_days, :routine_date, :created_by)");
    $stmt->execute([
        ':parent_id' => $parent_user_id,
        ':child_id' => $child_user_id,
        ':title' => $title,
        ':start_time' => $start_time,
        ':end_time' => $end_time,
        ':recurrence' => $recurrence,
        ':bonus_points' => $bonus_points,
        ':time_of_day' => $time_of_day,
        ':recurrence_days' => $recurrence_days,
        ':routine_date' => $routine_date,
        ':created_by' => $creator_user_id ?? $parent_user_id
    ]);
    return $db->lastInsertId();
}

function updateRoutine($routine_id, $child_user_id, $title, $start_time, $end_time, $recurrence, $bonus_points, $time_of_day, $recurrence_days, $routine_date, $parent_user_id) {
    global $db;
    $stmt = $db->prepare("UPDATE routines SET child_user_id = :child_id, title = :title, start_time = :start_time, end_time = :end_time, recurrence = :recurrence, bonus_points = :bonus_points, time_of_day = :time_of_day, recurrence_days = :recurrence_days, routine_date = :routine_date WHERE id = :id AND parent_user_id = :parent_id");
    return $stmt->execute([
        ':child_id' => $child_user_id,
        ':title' => $title,
        ':start_time' => $start_time,
        ':end_time' => $end_time,
        ':recurrence' => $recurrence,
        ':bonus_points' => $bonus_points,
        ':time_of_day' => $time_of_day,
        ':recurrence_days' => $recurrence_days,
        ':routine_date' => $routine_date,
        ':id' => $routine_id,
        ':parent_id' => $parent_user_id
    ]);
}

function deleteRoutine($routine_id, $parent_user_id) {
    global $db;
    $stmt = $db->prepare("DELETE FROM routines WHERE id = :id AND parent_user_id = :parent_id");
    return $stmt->execute([':id' => $routine_id, ':parent_id' => $parent_user_id]);
}

function addRoutineTaskToRoutine($routine_id, $routine_task_id, $sequence_order, $dependency_id = null, $status = 'pending') {
    global $db;
    $stmt = $db->prepare("INSERT INTO routines_routine_tasks (routine_id, routine_task_id, sequence_order, dependency_id, status) VALUES (:routine_id, :routine_task_id, :sequence_order, :dependency_id, :status)");
    return $stmt->execute([
        ':routine_id' => $routine_id,
        ':routine_task_id' => $routine_task_id,
        ':sequence_order' => $sequence_order,
        ':dependency_id' => $dependency_id,
        ':status' => in_array($status, ['pending', 'completed'], true) ? $status : 'pending'
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
    $role = getEffectiveRole($user_id);

    if (in_array($role, ['main_parent', 'secondary_parent', 'family_member', 'caregiver'], true)) {
        $parent_id = getFamilyRootId($user_id);
        $stmt = $db->prepare("
            SELECT 
                r.*,
                COALESCE(
                    NULLIF(TRIM(CONCAT(COALESCE(creator.first_name, ''), ' ', COALESCE(creator.last_name, ''))), ''),
                    NULLIF(creator.name, ''),
                    creator.username,
                    'Unknown'
                ) AS creator_display_name,
                COALESCE(
                    NULLIF(TRIM(CONCAT(COALESCE(child.first_name, ''), ' ', COALESCE(child.last_name, ''))), ''),
                    NULLIF(child.name, ''),
                    child.username,
                    'Unknown'
                ) AS child_display_name,
                cp.avatar AS child_avatar
            FROM routines r
            LEFT JOIN users creator ON r.created_by = creator.id
            LEFT JOIN users child ON r.child_user_id = child.id
            LEFT JOIN child_profiles cp ON cp.child_user_id = child.id
            WHERE r.parent_user_id = :parent_id
        ");
        $stmt->execute([':parent_id' => $parent_id]);
    } else {
        $stmt = $db->prepare("
            SELECT 
                r.*,
                COALESCE(
                    NULLIF(TRIM(CONCAT(COALESCE(creator.first_name, ''), ' ', COALESCE(creator.last_name, ''))), ''),
                    NULLIF(creator.name, ''),
                    creator.username,
                    'Unknown'
                ) AS creator_display_name,
                COALESCE(
                    NULLIF(TRIM(CONCAT(COALESCE(child.first_name, ''), ' ', COALESCE(child.last_name, ''))), ''),
                    NULLIF(child.name, ''),
                    child.username,
                    'Unknown'
                ) AS child_display_name,
                cp.avatar AS child_avatar
            FROM routines r
            LEFT JOIN users creator ON r.created_by = creator.id
            LEFT JOIN users child ON r.child_user_id = child.id
            LEFT JOIN child_profiles cp ON cp.child_user_id = child.id
            WHERE r.child_user_id = :child_id
        ");
        $stmt->execute([':child_id' => $user_id]);
    }
    $routines = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($routines as &$routine) {
        $taskStmt = $db->prepare("SELECT rt.*, rrt.sequence_order, rrt.dependency_id, rrt.status AS routine_status, rrt.completed_at AS routine_completed_at FROM routine_tasks rt JOIN routines_routine_tasks rrt ON rt.id = rrt.routine_task_id WHERE rrt.routine_id = :routine_id ORDER BY rrt.sequence_order");
        $taskStmt->execute([':routine_id' => $routine['id']]);
        $tasks = $taskStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($tasks as &$task) {
            $task['library_status'] = $task['status'] ?? null;
            $task['status'] = $task['routine_status'] ?? 'pending';
            $task['completed_at'] = $task['routine_completed_at'] ?? null;
            unset($task['routine_status'], $task['routine_completed_at']);
        }
        $routine['tasks'] = $tasks;
    }
    return $routines;
}

function getRoutineWithTasks($routine_id) {
    global $db;
    $stmt = $db->prepare("SELECT * FROM routines WHERE id = :id");
    $stmt->execute([':id' => $routine_id]);
    $routine = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($routine) {
        $taskStmt = $db->prepare("SELECT rt.*, rrt.sequence_order, rrt.dependency_id, rrt.status AS routine_status, rrt.completed_at AS routine_completed_at FROM routine_tasks rt JOIN routines_routine_tasks rrt ON rt.id = rrt.routine_task_id WHERE rrt.routine_id = :routine_id ORDER BY rrt.sequence_order");
        $taskStmt->execute([':routine_id' => $routine_id]);
        $tasks = $taskStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($tasks as &$task) {
            $task['library_status'] = $task['status'] ?? null;
            $task['status'] = $task['routine_status'] ?? 'pending';
            $task['completed_at'] = $task['routine_completed_at'] ?? null;
            unset($task['routine_status'], $task['routine_completed_at']);
        }
        $routine['tasks'] = $tasks;
    }
    return $routine;
}

function completeRoutine($routine_id, $child_id, $grant_bonus = true) {
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

        $bonus = 0;
        if ($grant_bonus) {
            $bonus = max(0, (int) $routine['bonus_points']);
        }

        // Award bonus to child's points
        if ($bonus > 0) {
            updateChildPoints($child_id, $bonus);
        }

        error_log("Routine $routine_id completed by child $child_id with bonus $bonus (grant flag " . ($grant_bonus ? 'true' : 'false') . ")");

        $db->commit();
        return $bonus;
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Routine completion failed for ID $routine_id: " . $e->getMessage());
        return false;
    }
}

function resetRoutineTaskStatuses($routine_id) {
    global $db;
    $stmt = $db->prepare("UPDATE routines_routine_tasks SET status = 'pending', completed_at = NULL WHERE routine_id = :routine_id");
    return $stmt->execute([':routine_id' => $routine_id]);
}

function setRoutineTaskStatus($routine_id, $routine_task_id, $status, $completed_at = null) {
    global $db;
    $allowed = ['pending', 'completed'];
    if (!in_array($status, $allowed, true)) {
        return false;
    }
    $resolvedCompletedAt = null;
    if ($status === 'completed') {
        if (!empty($completed_at)) {
            $resolvedCompletedAt = $completed_at;
        } else {
            $resolvedCompletedAt = date('Y-m-d H:i:s');
        }
    }
    $params = [
        ':routine_id' => $routine_id,
        ':routine_task_id' => $routine_task_id,
        ':status' => $status,
        ':completed_at' => $resolvedCompletedAt
    ];
    $stmt = $db->prepare("UPDATE routines_routine_tasks SET status = :status, completed_at = :completed_at WHERE routine_id = :routine_id AND routine_task_id = :routine_task_id");
    if (!$stmt->execute($params)) {
        return false;
    }
    return $stmt->rowCount() > 0;
}

function getRoutineOvertimeLogs($parent_user_id, $limit = 25) {
    global $db;
    $limit = max(1, (int) $limit);
    $sql = "
        SELECT 
            rol.id,
            rol.routine_id,
            rol.routine_task_id,
            rol.child_user_id,
            rol.scheduled_seconds,
            rol.actual_seconds,
            rol.overtime_seconds,
            rol.occurred_at,
            r.title AS routine_title,
            rt.title AS task_title,
            COALESCE(
                NULLIF(TRIM(CONCAT(COALESCE(cu.first_name, ''), ' ', COALESCE(cu.last_name, ''))), ''),
                NULLIF(cu.name, ''),
                cu.username,
                'Unknown'
            ) AS child_display_name
        FROM routine_overtime_logs rol
        JOIN routines r ON rol.routine_id = r.id
        JOIN routine_tasks rt ON rol.routine_task_id = rt.id
        JOIN users cu ON rol.child_user_id = cu.id
        WHERE r.parent_user_id = :parent_id
        ORDER BY rol.occurred_at DESC
        LIMIT :log_limit
    ";
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':parent_id', $parent_user_id, PDO::PARAM_INT);
    $stmt->bindValue(':log_limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getRoutineOvertimeStats($parent_user_id) {
    global $db;

    $childSql = "
        SELECT 
            rol.child_user_id,
            COALESCE(
                NULLIF(TRIM(CONCAT(COALESCE(cu.first_name, ''), ' ', COALESCE(cu.last_name, ''))), ''),
                NULLIF(cu.name, ''),
                cu.username,
                'Unknown'
            ) AS child_display_name,
            COUNT(*) AS occurrences,
            SUM(rol.overtime_seconds) AS total_overtime_seconds
        FROM routine_overtime_logs rol
        JOIN routines r ON rol.routine_id = r.id
        JOIN users cu ON rol.child_user_id = cu.id
        WHERE r.parent_user_id = :parent_id
        GROUP BY rol.child_user_id
        ORDER BY total_overtime_seconds DESC
    ";
    $stmt = $db->prepare($childSql);
    $stmt->execute([':parent_id' => $parent_user_id]);
    $byChild = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $routineSql = "
        SELECT 
            rol.routine_id,
            r.title AS routine_title,
            COUNT(*) AS occurrences,
            SUM(rol.overtime_seconds) AS total_overtime_seconds
        FROM routine_overtime_logs rol
        JOIN routines r ON rol.routine_id = r.id
        WHERE r.parent_user_id = :parent_id
        GROUP BY rol.routine_id
        ORDER BY total_overtime_seconds DESC
    ";
    $stmt = $db->prepare($routineSql);
    $stmt->execute([':parent_id' => $parent_user_id]);
    $byRoutine = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return [
        'by_child' => $byChild,
        'by_routine' => $byRoutine
    ];
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
   $db->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS role_badge_label VARCHAR(50) DEFAULT NULL");
   $db->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS use_role_badge_label TINYINT(1) DEFAULT 0");
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
   // Add soft-delete tracking columns for children
   $db->exec("ALTER TABLE child_profiles ADD COLUMN IF NOT EXISTS deleted_at DATETIME DEFAULT NULL");
   $db->exec("ALTER TABLE child_profiles ADD COLUMN IF NOT EXISTS deleted_by INT DEFAULT NULL");
   $db->exec("ALTER TABLE child_profiles ADD COLUMN IF NOT EXISTS rewards_shop_open TINYINT(1) NOT NULL DEFAULT 1");
   try {
       $db->exec("CREATE INDEX IF NOT EXISTS idx_child_profiles_deleted ON child_profiles(parent_user_id, deleted_at)");
   } catch (PDOException $e) {
       // ignore if IF NOT EXISTS not supported
   }
   error_log("Added/verified soft-delete columns on child_profiles");

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
       timer_minutes INT DEFAULT NULL,
       time_of_day ENUM('anytime', 'morning', 'afternoon', 'evening') DEFAULT 'anytime',
       recurrence_days VARCHAR(32) DEFAULT NULL,
       end_date DATE DEFAULT NULL,
       status ENUM('pending', 'completed', 'approved', 'rejected') DEFAULT 'pending',
       photo_proof VARCHAR(255),
       photo_proof_required TINYINT(1) DEFAULT 0,
       completed_at DATETIME,
       approved_at DATETIME,
       rejected_at DATETIME,
       rejected_note TEXT,
       rejected_by INT NULL,
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
   $db->exec("ALTER TABLE tasks ADD COLUMN IF NOT EXISTS timer_minutes INT NULL");
   error_log("Added/verified timer_minutes in tasks");
   $db->exec("ALTER TABLE tasks ADD COLUMN IF NOT EXISTS time_of_day ENUM('anytime','morning','afternoon','evening') DEFAULT 'anytime'");
   error_log("Added/verified time_of_day in tasks");
   $db->exec("ALTER TABLE tasks ADD COLUMN IF NOT EXISTS recurrence_days VARCHAR(32) NULL");
   error_log("Added/verified recurrence_days in tasks");
   $db->exec("ALTER TABLE tasks ADD COLUMN IF NOT EXISTS end_date DATE NULL");
   error_log("Added/verified end_date in tasks");
   $db->exec("ALTER TABLE tasks ADD COLUMN IF NOT EXISTS photo_proof_required TINYINT(1) DEFAULT 0");
   error_log("Added/verified photo_proof_required in tasks");
   $db->exec("ALTER TABLE tasks ADD COLUMN IF NOT EXISTS approved_at DATETIME NULL");
   error_log("Added/verified approved_at in tasks");
   $db->exec("ALTER TABLE tasks ADD COLUMN IF NOT EXISTS rejected_at DATETIME NULL");
   $db->exec("ALTER TABLE tasks ADD COLUMN IF NOT EXISTS rejected_note TEXT NULL");
   $db->exec("ALTER TABLE tasks ADD COLUMN IF NOT EXISTS rejected_by INT NULL");
   error_log("Added/verified rejection fields in tasks");
   $db->exec("ALTER TABLE tasks MODIFY COLUMN status ENUM('pending', 'completed', 'approved', 'rejected') DEFAULT 'pending'");
   error_log("Expanded tasks status enum to include rejected");

   // Create task_instances table for per-date tracking of recurring tasks
   $sql = "CREATE TABLE IF NOT EXISTS task_instances (
       id INT AUTO_INCREMENT PRIMARY KEY,
       task_id INT NOT NULL,
       date_key DATE NOT NULL,
       status ENUM('completed', 'approved', 'rejected') NOT NULL,
       note TEXT NULL,
       photo_proof VARCHAR(255) NULL,
       completed_at DATETIME NULL,
       approved_at DATETIME NULL,
       rejected_at DATETIME NULL,
       created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
       updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
       UNIQUE KEY uniq_task_date (task_id, date_key),
       INDEX idx_task_status (task_id, status),
       FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE
   )";
   $db->exec($sql);
   error_log("Created/verified task_instances table successfully");

   // Create reward_templates table (library of reusable rewards)
   $sql = "CREATE TABLE IF NOT EXISTS reward_templates (
      id INT AUTO_INCREMENT PRIMARY KEY,
      parent_user_id INT NOT NULL,
      title VARCHAR(100) NOT NULL,
      description TEXT DEFAULT NULL,
      description TEXT,
      point_cost INT NOT NULL,
      level_required INT NOT NULL DEFAULT 1,
      icon_class VARCHAR(64) DEFAULT NULL,
      icon_color VARCHAR(16) DEFAULT NULL,
      created_by INT NULL,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      FOREIGN KEY (parent_user_id) REFERENCES users(id) ON DELETE CASCADE,
      FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
   )";
   $db->exec($sql);
   error_log("Created/verified reward_templates table successfully");
   $db->exec("ALTER TABLE reward_templates ADD COLUMN IF NOT EXISTS level_required INT NOT NULL DEFAULT 1");
   $db->exec("ALTER TABLE reward_templates ADD COLUMN IF NOT EXISTS icon_class VARCHAR(64) NULL");
   $db->exec("ALTER TABLE reward_templates ADD COLUMN IF NOT EXISTS icon_color VARCHAR(16) NULL");

   // Create rewards table if not exists (added created_by)
   $sql = "CREATE TABLE IF NOT EXISTS rewards (
   id INT AUTO_INCREMENT PRIMARY KEY,
   parent_user_id INT NOT NULL,
   child_user_id INT NULL,
   template_id INT NULL,
   title VARCHAR(100) NOT NULL,
   description TEXT,
   point_cost INT NOT NULL,
   status ENUM('available', 'redeemed') DEFAULT 'available',
   created_on TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
   redeemed_by INT NULL,
   redeemed_on DATETIME NULL,
   fulfilled_on DATETIME NULL,
   fulfilled_by INT NULL,
   created_by INT NULL,
   FOREIGN KEY (parent_user_id) REFERENCES users(id) ON DELETE CASCADE,
   FOREIGN KEY (child_user_id) REFERENCES users(id) ON DELETE CASCADE,
   FOREIGN KEY (template_id) REFERENCES reward_templates(id) ON DELETE SET NULL,
   FOREIGN KEY (redeemed_by) REFERENCES users(id) ON DELETE SET NULL,
   FOREIGN KEY (fulfilled_by) REFERENCES users(id) ON DELETE SET NULL,
   FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
   )";
   $db->exec($sql);
   error_log("Created/verified rewards table successfully");

   // Add created_by to existing rewards if not exists
   $db->exec("ALTER TABLE rewards ADD COLUMN IF NOT EXISTS created_by INT NULL");
   error_log("Added/verified created_by in rewards");
  $db->exec("ALTER TABLE rewards ADD COLUMN IF NOT EXISTS fulfilled_on DATETIME NULL");
  $db->exec("ALTER TABLE rewards ADD COLUMN IF NOT EXISTS fulfilled_by INT NULL");
  $db->exec("ALTER TABLE rewards ADD COLUMN IF NOT EXISTS denied_on DATETIME NULL");
  $db->exec("ALTER TABLE rewards ADD COLUMN IF NOT EXISTS denied_by INT NULL");
  $db->exec("ALTER TABLE rewards ADD COLUMN IF NOT EXISTS denied_note VARCHAR(255) NULL");
  $db->exec("ALTER TABLE rewards ADD COLUMN IF NOT EXISTS child_user_id INT NULL");
  $db->exec("ALTER TABLE rewards ADD COLUMN IF NOT EXISTS template_id INT NULL");
   try {
       $db->exec("ALTER TABLE rewards ADD CONSTRAINT fk_rewards_child_user FOREIGN KEY (child_user_id) REFERENCES users(id) ON DELETE CASCADE");
   } catch (PDOException $e) {
       error_log("Skipped adding child_user_id FK on rewards: " . $e->getMessage());
   }
   try {
       $db->exec("ALTER TABLE rewards ADD CONSTRAINT fk_rewards_template FOREIGN KEY (template_id) REFERENCES reward_templates(id) ON DELETE SET NULL");
   } catch (PDOException $e) {
       error_log("Skipped adding template_id FK on rewards: " . $e->getMessage());
   }

   // Create goals table with corrected constraints
   $sql = "CREATE TABLE IF NOT EXISTS goals (
      id INT AUTO_INCREMENT PRIMARY KEY,
      parent_user_id INT NOT NULL,
      child_user_id INT NOT NULL,
      title VARCHAR(100) NOT NULL,
      description TEXT DEFAULT NULL,
      target_points INT NOT NULL DEFAULT 0,
      start_date DATETIME,
      end_date DATETIME,
      status ENUM('active', 'pending_approval', 'completed', 'rejected') DEFAULT 'active',
      reward_id INT NULL,
      goal_type VARCHAR(24) DEFAULT 'manual',
      routine_id INT NULL,
      task_category VARCHAR(50) DEFAULT NULL,
      target_count INT NOT NULL DEFAULT 0,
      streak_required INT NOT NULL DEFAULT 0,
      require_on_time TINYINT(1) NOT NULL DEFAULT 0,
      points_awarded INT NOT NULL DEFAULT 0,
      award_mode VARCHAR(12) DEFAULT 'both',
      requires_parent_approval TINYINT(1) NOT NULL DEFAULT 1,
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
  $db->exec("ALTER TABLE goals ADD COLUMN IF NOT EXISTS description TEXT DEFAULT NULL");
  $db->exec("ALTER TABLE goals ADD COLUMN IF NOT EXISTS goal_type VARCHAR(24) DEFAULT 'manual'");
  $db->exec("ALTER TABLE goals ADD COLUMN IF NOT EXISTS routine_id INT NULL");
  $db->exec("ALTER TABLE goals ADD COLUMN IF NOT EXISTS task_category VARCHAR(50) DEFAULT NULL");
  $db->exec("ALTER TABLE goals ADD COLUMN IF NOT EXISTS target_count INT NOT NULL DEFAULT 0");
  $db->exec("ALTER TABLE goals ADD COLUMN IF NOT EXISTS streak_required INT NOT NULL DEFAULT 0");
  $db->exec("ALTER TABLE goals ADD COLUMN IF NOT EXISTS require_on_time TINYINT(1) NOT NULL DEFAULT 0");
  $db->exec("ALTER TABLE goals ADD COLUMN IF NOT EXISTS points_awarded INT NOT NULL DEFAULT 0");
  $db->exec("ALTER TABLE goals ADD COLUMN IF NOT EXISTS award_mode VARCHAR(12) DEFAULT 'both'");
  $db->exec("ALTER TABLE goals ADD COLUMN IF NOT EXISTS requires_parent_approval TINYINT(1) NOT NULL DEFAULT 1");
  $db->exec("ALTER TABLE goals DROP COLUMN IF EXISTS time_window_type");
  $db->exec("ALTER TABLE goals DROP COLUMN IF EXISTS time_window_days");
  $db->exec("ALTER TABLE goals DROP COLUMN IF EXISTS fixed_window_start");
  $db->exec("ALTER TABLE goals DROP COLUMN IF EXISTS fixed_window_end");
  error_log("Added/verified goal criteria columns in goals");

  $sql = "CREATE TABLE IF NOT EXISTS goal_progress (
      id INT AUTO_INCREMENT PRIMARY KEY,
      goal_id INT NOT NULL,
      child_user_id INT NOT NULL,
      current_count INT NOT NULL DEFAULT 0,
      current_streak INT NOT NULL DEFAULT 0,
      last_progress_date DATE DEFAULT NULL,
      next_needed_hint VARCHAR(255) DEFAULT NULL,
      celebration_shown TINYINT(1) NOT NULL DEFAULT 0,
      updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uniq_goal_progress (goal_id),
      INDEX idx_goal_child (child_user_id, goal_id),
      FOREIGN KEY (goal_id) REFERENCES goals(id) ON DELETE CASCADE,
      FOREIGN KEY (child_user_id) REFERENCES users(id) ON DELETE CASCADE
  )";
  $db->exec($sql);
  error_log("Created/verified goal_progress table successfully");

  $sql = "CREATE TABLE IF NOT EXISTS goal_task_targets (
      id INT AUTO_INCREMENT PRIMARY KEY,
      goal_id INT NOT NULL,
      task_id INT NOT NULL,
      UNIQUE KEY uniq_goal_task (goal_id, task_id),
      FOREIGN KEY (goal_id) REFERENCES goals(id) ON DELETE CASCADE,
      FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE
  )";
  $db->exec($sql);
  error_log("Created/verified goal_task_targets table successfully");

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
    time_of_day ENUM('anytime', 'morning', 'afternoon', 'evening') DEFAULT 'anytime',
    recurrence_days VARCHAR(32) DEFAULT NULL,
    routine_date DATE DEFAULT NULL,
    created_by INT NULL,  /* Changed from NOT NULL to NULL */
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (parent_user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (child_user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
   )";
   $db->exec($sql);
   error_log("Created/verified routines table successfully");

  $sql = "CREATE TABLE IF NOT EXISTS goal_routine_targets (
      id INT AUTO_INCREMENT PRIMARY KEY,
      goal_id INT NOT NULL,
      routine_id INT NOT NULL,
      UNIQUE KEY uniq_goal_routine (goal_id, routine_id),
      FOREIGN KEY (goal_id) REFERENCES goals(id) ON DELETE CASCADE,
      FOREIGN KEY (routine_id) REFERENCES routines(id) ON DELETE CASCADE
  )";
  $db->exec($sql);
  error_log("Created/verified goal_routine_targets table successfully");

   // Add created_by to existing routines if not exists
   $db->exec("ALTER TABLE routines ADD COLUMN IF NOT EXISTS created_by INT NULL");
   error_log("Added/verified created_by in routines");
   $db->exec("ALTER TABLE routines ADD COLUMN IF NOT EXISTS time_of_day ENUM('anytime','morning','afternoon','evening') DEFAULT 'anytime'");
   error_log("Added/verified time_of_day in routines");
   $db->exec("ALTER TABLE routines ADD COLUMN IF NOT EXISTS recurrence_days VARCHAR(32) NULL");
   error_log("Added/verified recurrence_days in routines");
   $db->exec("ALTER TABLE routines ADD COLUMN IF NOT EXISTS routine_date DATE NULL");
   error_log("Added/verified routine_date in routines");

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
        created_by INT NULL,
        status ENUM('pending', 'completed', 'approved') DEFAULT 'pending',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (parent_user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
    )";
    $db->exec($sql);
    error_log("Created/verified routine_tasks table successfully");

    try {
        $db->exec("ALTER TABLE routine_tasks ADD COLUMN IF NOT EXISTS created_by INT NULL");
    } catch (PDOException $e) {
        error_log("routine_tasks.created_by add column skipped: " . $e->getMessage());
    }
    try {
        $db->exec("ALTER TABLE routine_tasks ADD CONSTRAINT fk_routine_tasks_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL");
    } catch (PDOException $e) {
        error_log("routine_tasks.created_by constraint ensure skipped: " . $e->getMessage());
    }

    // Create routines_routine_tasks association table if not exists
    $sql = "CREATE TABLE IF NOT EXISTS routines_routine_tasks (
        routine_id INT NOT NULL,
        routine_task_id INT NOT NULL,
        sequence_order INT NOT NULL,
        dependency_id INT DEFAULT NULL,
        status ENUM('pending', 'completed') DEFAULT 'pending',
        completed_at DATETIME DEFAULT NULL,
        PRIMARY KEY (routine_id, routine_task_id),
        FOREIGN KEY (routine_id) REFERENCES routines(id) ON DELETE CASCADE,
        FOREIGN KEY (routine_task_id) REFERENCES routine_tasks(id) ON DELETE CASCADE,
        FOREIGN KEY (dependency_id) REFERENCES routine_tasks(id) ON DELETE SET NULL
    )";
    $db->exec($sql);
    error_log("Created/verified routines_routine_tasks table successfully");

    try {
        $db->exec("ALTER TABLE routines_routine_tasks ADD COLUMN IF NOT EXISTS status ENUM('pending','completed') DEFAULT 'pending'");
    } catch (PDOException $e) {
        error_log("routines_routine_tasks.status ensure skipped: " . $e->getMessage());
    }
    try {
        $db->exec("ALTER TABLE routines_routine_tasks ADD COLUMN IF NOT EXISTS completed_at DATETIME DEFAULT NULL");
    } catch (PDOException $e) {
        error_log("routines_routine_tasks.completed_at ensure skipped: " . $e->getMessage());
    }

    // Create routine_preferences table if not exists (family-level routine settings)
    $sql = "CREATE TABLE IF NOT EXISTS routine_preferences (
        id INT AUTO_INCREMENT PRIMARY KEY,
        parent_user_id INT NOT NULL,
        timer_warnings_enabled TINYINT(1) DEFAULT 1,
        sub_timer_label VARCHAR(50) DEFAULT 'hurry_goal',
        show_countdown TINYINT(1) DEFAULT 1,
        progress_style VARCHAR(12) DEFAULT 'bar',
        sound_effects_enabled TINYINT(1) DEFAULT 1,
        background_music_enabled TINYINT(1) DEFAULT 1,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_parent (parent_user_id),
        FOREIGN KEY (parent_user_id) REFERENCES users(id) ON DELETE CASCADE
    )";
    $db->exec($sql);
    error_log("Created/verified routine_preferences table successfully");
    // Ensure progress_style column exists
    try {
        $db->exec("ALTER TABLE routine_preferences ADD COLUMN IF NOT EXISTS progress_style VARCHAR(12) DEFAULT 'bar'");
    } catch (PDOException $e) {
        // ignore if not supported; table already created with column above
    }
    try {
        $db->exec("ALTER TABLE routine_preferences ADD COLUMN IF NOT EXISTS sound_effects_enabled TINYINT(1) DEFAULT 1");
    } catch (PDOException $e) {
        // ignore if not supported; table already created with column above
    }
    try {
        $db->exec("ALTER TABLE routine_preferences ADD COLUMN IF NOT EXISTS background_music_enabled TINYINT(1) DEFAULT 1");
    } catch (PDOException $e) {
        // ignore if not supported; table already created with column above
    }

    // Create routine_overtime_logs table if not exists
    $sql = "CREATE TABLE IF NOT EXISTS routine_overtime_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        routine_id INT NOT NULL,
        routine_task_id INT NOT NULL,
        child_user_id INT NOT NULL,
        scheduled_seconds INT NOT NULL,
        actual_seconds INT NOT NULL,
        overtime_seconds INT NOT NULL,
        occurred_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (routine_id) REFERENCES routines(id) ON DELETE CASCADE,
        FOREIGN KEY (routine_task_id) REFERENCES routine_tasks(id) ON DELETE CASCADE,
        FOREIGN KEY (child_user_id) REFERENCES users(id) ON DELETE CASCADE
    )";
    $db->exec($sql);
    error_log("Created/verified routine_overtime_logs table successfully");

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

    // Create child_streak_records table if not exists
    $sql = "CREATE TABLE IF NOT EXISTS child_streak_records (
        child_user_id INT PRIMARY KEY,
        routine_best_streak INT NOT NULL DEFAULT 0,
        task_best_streak INT NOT NULL DEFAULT 0,
        updated_at DATETIME NOT NULL,
        FOREIGN KEY (child_user_id) REFERENCES users(id) ON DELETE CASCADE
    )";
    $db->exec($sql);
    error_log("Created/verified child_streak_records table successfully");

            // Note: Pre-population of default Routine Tasks skipped to avoid foreign key constraint violation with parent_user_id = 0.
    // Parents can create initial tasks via the UI.
    error_log("Skipped pre-population of default Routine Tasks to avoid foreign key issues");
} catch (PDOException $e) {
    error_log("Table creation failed: " . $e->getMessage() . " at line " . $e->getLine());
    throw $e; // Re-throw to preserve the original error handling
}

try {
    // Migrate legacy 'parent' roles first to avoid data corruption on ALTER
    $db->exec("UPDATE users SET role = 'main_parent' WHERE role = 'parent' OR role = '' OR role IS NULL;");
    error_log("Migrated legacy user roles successfully");

    $db->exec("ALTER TABLE users MODIFY role ENUM('main_parent', 'family_member', 'caregiver', 'child') NOT NULL;");
    error_log("Modified users role ENUM successfully");
} catch (PDOException $e) {
    error_log("Failed to modify users role ENUM: " . $e->getMessage());
}

try {
    $db->exec("ALTER TABLE family_links MODIFY role_type ENUM('child', 'secondary_parent', 'family_member', 'caregiver') NOT NULL;");
    error_log("Modified family_links role_type ENUM successfully");
} catch (PDOException $e) {
    error_log("Failed to modify family_links role_type ENUM: " . $e->getMessage());
}

// In functions.php, modify the child_profiles table schema:
// Add birthday column if it doesn't exist
$sql = "ALTER TABLE child_profiles 
        ADD COLUMN IF NOT EXISTS birthday DATE DEFAULT NULL,
        MODIFY COLUMN age INT DEFAULT NULL";
$db->exec($sql);

?>



