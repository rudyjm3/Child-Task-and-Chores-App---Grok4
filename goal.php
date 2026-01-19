<?php
// goal.php - Goal management
// Purpose: Allow parents to create/edit/delete/reactivate goals and children to view/request completion
// Inputs: POST for create/update/delete/reactivate, goal ID for request completion
// Outputs: Goal management interface
// Version: 3.17.6

session_start();
require_once __DIR__ . '/includes/functions.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
$currentPage = basename($_SERVER['PHP_SELF']);

// Ensure a friendly display name is available in session
if (!isset($_SESSION['name'])) {
    $_SESSION['name'] = getDisplayName($_SESSION['user_id']);
}

$family_root_id = getFamilyRootId($_SESSION['user_id']);
$goalRole = getEffectiveRole($_SESSION['user_id']);
if (in_array($goalRole, ['main_parent', 'secondary_parent', 'family_member', 'caregiver'], true)) {
    autoCloseExpiredGoals($family_root_id, null);
} elseif ($goalRole === 'child') {
    autoCloseExpiredGoals(null, (int) $_SESSION['user_id']);
}

require_once __DIR__ . '/includes/notifications_bootstrap.php';

function resolveGoalRewardId($parent_id, $child_id, $reward_selection) {
    global $db;
    $reward_selection = trim((string) $reward_selection);
    if ($reward_selection === '') {
        return null;
    }

    if (preg_match('/^template:(\d+)$/', $reward_selection, $matches)) {
        $template_id = (int) $matches[1];
        $child_id = (int) $child_id;
        if ($template_id <= 0 || $child_id <= 0) {
            return null;
        }
        assignTemplateToChildren($parent_id, $template_id, [$child_id]);
        $stmt = $db->prepare("SELECT id FROM rewards WHERE parent_user_id = :parent_id AND child_user_id = :child_id AND template_id = :template_id AND status = 'available' ORDER BY created_on DESC LIMIT 1");
        $stmt->execute([
            ':parent_id' => $parent_id,
            ':child_id' => $child_id,
            ':template_id' => $template_id
        ]);
        $reward_id = (int) $stmt->fetchColumn();
        return $reward_id > 0 ? $reward_id : null;
    }

    $reward_id = filter_var($reward_selection, FILTER_VALIDATE_INT);
    return ($reward_id && $reward_id > 0) ? $reward_id : null;
}

function hydrateGoalRoutineData(array $goals) {
    global $db;
    if (empty($goals)) {
        return $goals;
    }
    $goalIds = array_values(array_unique(array_filter(array_map('intval', array_column($goals, 'id')))));
    if (empty($goalIds)) {
        return $goals;
    }

    $placeholders = implode(',', array_fill(0, count($goalIds), '?'));
    $stmt = $db->prepare("SELECT goal_id, routine_id FROM goal_routine_targets WHERE goal_id IN ($placeholders)");
    $stmt->execute($goalIds);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $goalTargets = [];
    $routineIds = [];
    foreach ($rows as $row) {
        $goalId = (int) ($row['goal_id'] ?? 0);
        $routineId = (int) ($row['routine_id'] ?? 0);
        if ($goalId && $routineId) {
            if (!isset($goalTargets[$goalId])) {
                $goalTargets[$goalId] = [];
            }
            $goalTargets[$goalId][] = $routineId;
            $routineIds[] = $routineId;
        }
    }

    foreach ($goals as $goal) {
        $goalId = (int) ($goal['id'] ?? 0);
        $existingRoutineId = (int) ($goal['routine_id'] ?? 0);
        if ($goalId && empty($goalTargets[$goalId]) && $existingRoutineId > 0) {
            $routineIds[] = $existingRoutineId;
        }
    }
    $routineIds = array_values(array_unique(array_filter($routineIds)));
    $routineTitleMap = [];
    if (!empty($routineIds)) {
        $routinePlaceholders = implode(',', array_fill(0, count($routineIds), '?'));
        $routineStmt = $db->prepare("SELECT id, title FROM routines WHERE id IN ($routinePlaceholders)");
        $routineStmt->execute($routineIds);
        foreach (($routineStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
            $routineTitleMap[(int) $row['id']] = $row['title'] ?? '';
        }
    }

    foreach ($goals as &$goal) {
        $goalId = (int) ($goal['id'] ?? 0);
        $targetIds = $goalTargets[$goalId] ?? [];
        if (empty($targetIds)) {
            $existingRoutineId = (int) ($goal['routine_id'] ?? 0);
            if ($existingRoutineId > 0) {
                $targetIds = [$existingRoutineId];
            }
        }
        $goal['routine_target_ids'] = $targetIds;
        $titles = [];
        $targets = [];
        foreach ($targetIds as $routineId) {
            if (isset($routineTitleMap[$routineId])) {
                $titles[] = $routineTitleMap[$routineId];
                $targets[] = [
                    'id' => $routineId,
                    'title' => $routineTitleMap[$routineId]
                ];
            }
        }
        $goal['routine_title_display'] = implode(', ', $titles);
        $goal['routine_targets'] = $targets;
    }
    unset($goal);
    return $goals;
}

function hydrateGoalTaskData(array $goals) {
    global $db;
    if (empty($goals)) {
        return $goals;
    }
    $goalIds = array_values(array_unique(array_filter(array_map('intval', array_column($goals, 'id')))));
    if (empty($goalIds)) {
        return $goals;
    }

    $placeholders = implode(',', array_fill(0, count($goalIds), '?'));
    $stmt = $db->prepare("SELECT gtt.goal_id, gtt.task_id, t.title
                          FROM goal_task_targets gtt
                          JOIN tasks t ON gtt.task_id = t.id
                          WHERE gtt.goal_id IN ($placeholders)");
    $stmt->execute($goalIds);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $taskMap = [];
    foreach ($rows as $row) {
        $goalId = (int) ($row['goal_id'] ?? 0);
        if (!$goalId) {
            continue;
        }
        if (!isset($taskMap[$goalId])) {
            $taskMap[$goalId] = [];
        }
        if (!empty($row['title'])) {
            $taskMap[$goalId][] = [
                'id' => (int) ($row['task_id'] ?? 0),
                'title' => $row['title']
            ];
        }
    }

    foreach ($goals as &$goal) {
        $goalId = (int) ($goal['id'] ?? 0);
        $goal['task_targets'] = $taskMap[$goalId] ?? [];
    }
    unset($goal);
    return $goals;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create_goal']) && isset($_SESSION['user_id']) && canCreateContent($_SESSION['user_id'])) {
        $child_user_id = filter_input(INPUT_POST, 'child_user_id', FILTER_VALIDATE_INT);
        $title = filter_input(INPUT_POST, 'title', FILTER_SANITIZE_STRING);
        $description = trim((string) filter_input(INPUT_POST, 'description', FILTER_SANITIZE_STRING));
        if ($description === '') {
            $description = null;
        }
        $start_date = filter_input(INPUT_POST, 'start_date', FILTER_SANITIZE_STRING);
        $end_date = filter_input(INPUT_POST, 'end_date', FILTER_SANITIZE_STRING);
        $reward_selection = $_POST['reward_id'] ?? '';
        $reward_id = resolveGoalRewardId($family_root_id, $child_user_id, $reward_selection);
        $goal_type = filter_input(INPUT_POST, 'goal_type', FILTER_SANITIZE_STRING) ?: 'manual';
        $allowed_types = ['manual', 'routine_streak', 'routine_count', 'task_quota'];
        if (!in_array($goal_type, $allowed_types, true)) {
            $goal_type = 'manual';
        }
        $routine_ids = array_values(array_filter(array_map('intval', $_POST['routine_ids'] ?? [])));
        $task_category = filter_input(INPUT_POST, 'task_category', FILTER_SANITIZE_STRING);
        $target_count = filter_input(INPUT_POST, 'target_count', FILTER_VALIDATE_INT);
        $streak_required = filter_input(INPUT_POST, 'streak_required', FILTER_VALIDATE_INT);
        $time_window_type = filter_input(INPUT_POST, 'time_window_type', FILTER_SANITIZE_STRING) ?: 'rolling';
        $time_window_days = filter_input(INPUT_POST, 'time_window_days', FILTER_VALIDATE_INT);
        $fixed_window_start = filter_input(INPUT_POST, 'fixed_window_start', FILTER_SANITIZE_STRING);
        $fixed_window_end = filter_input(INPUT_POST, 'fixed_window_end', FILTER_SANITIZE_STRING);
        $require_on_time = !empty($_POST['require_on_time']);
        $points_awarded = filter_input(INPUT_POST, 'points_awarded', FILTER_VALIDATE_INT);
        $award_mode = filter_input(INPUT_POST, 'award_mode', FILTER_SANITIZE_STRING) ?: 'both';
        $requires_parent_approval = isset($_POST['requires_parent_approval']) ? 1 : 0;
        $task_target_ids = array_values(array_filter(array_map('intval', $_POST['task_target_ids'] ?? [])));
        $reactivateOnSave = !empty($_POST['reactivate_on_save']);

        if (!in_array($goal_type, ['routine_streak', 'routine_count'], true)) {
            $routine_ids = [];
        }
        $routine_id = !empty($routine_ids) ? $routine_ids[0] : null;
        $options = [
            'description' => $description,
            'goal_type' => $goal_type,
            'routine_id' => $routine_id,
            'routine_target_ids' => $routine_ids,
            'task_category' => $task_category,
            'target_count' => max(0, (int) ($target_count ?? 0)),
            'streak_required' => max(0, (int) ($streak_required ?? 0)),
            'time_window_type' => $time_window_type === 'fixed' ? 'fixed' : 'rolling',
            'time_window_days' => max(0, (int) ($time_window_days ?? 0)),
            'fixed_window_start' => $fixed_window_start ?: null,
            'fixed_window_end' => $fixed_window_end ?: null,
            'require_on_time' => $require_on_time,
            'points_awarded' => max(0, (int) ($points_awarded ?? 0)),
            'award_mode' => in_array($award_mode, ['points', 'reward', 'both'], true) ? $award_mode : 'both',
            'requires_parent_approval' => $requires_parent_approval,
            'task_target_ids' => $task_target_ids
        ];

        if (createGoal($family_root_id, $child_user_id, $title, $start_date, $end_date, $reward_id, $_SESSION['user_id'], $options)) {
            $message = "Goal created successfully!";
        } else {
            $message = "Failed to create goal.";
        }
    } elseif (isset($_POST['update_goal']) && isset($_SESSION['user_id']) && canCreateContent($_SESSION['user_id'])) {
        $goal_id = filter_input(INPUT_POST, 'goal_id', FILTER_VALIDATE_INT);
        $child_user_id = filter_input(INPUT_POST, 'child_user_id', FILTER_VALIDATE_INT);
        $title = filter_input(INPUT_POST, 'title', FILTER_SANITIZE_STRING);
        $description = trim((string) filter_input(INPUT_POST, 'description', FILTER_SANITIZE_STRING));
        if ($description === '') {
            $description = null;
        }
        $reactivateOnSave = !empty($_POST['reactivate_on_save']);
        $start_date = filter_input(INPUT_POST, 'start_date', FILTER_SANITIZE_STRING);
        $end_date = filter_input(INPUT_POST, 'end_date', FILTER_SANITIZE_STRING);
        $reward_selection = $_POST['reward_id'] ?? '';
        $reward_id = resolveGoalRewardId($family_root_id, $child_user_id, $reward_selection);
        $goal_type = filter_input(INPUT_POST, 'goal_type', FILTER_SANITIZE_STRING) ?: 'manual';
        $allowed_types = ['manual', 'routine_streak', 'routine_count', 'task_quota'];
        if (!in_array($goal_type, $allowed_types, true)) {
            $goal_type = 'manual';
        }
        $routine_ids = array_values(array_filter(array_map('intval', $_POST['routine_ids'] ?? [])));
        $task_category = filter_input(INPUT_POST, 'task_category', FILTER_SANITIZE_STRING);
        $target_count = filter_input(INPUT_POST, 'target_count', FILTER_VALIDATE_INT);
        $streak_required = filter_input(INPUT_POST, 'streak_required', FILTER_VALIDATE_INT);
        $time_window_type = filter_input(INPUT_POST, 'time_window_type', FILTER_SANITIZE_STRING) ?: 'rolling';
        $time_window_days = filter_input(INPUT_POST, 'time_window_days', FILTER_VALIDATE_INT);
        $fixed_window_start = filter_input(INPUT_POST, 'fixed_window_start', FILTER_SANITIZE_STRING);
        $fixed_window_end = filter_input(INPUT_POST, 'fixed_window_end', FILTER_SANITIZE_STRING);
        $require_on_time = !empty($_POST['require_on_time']);
        $points_awarded = filter_input(INPUT_POST, 'points_awarded', FILTER_VALIDATE_INT);
        $award_mode = filter_input(INPUT_POST, 'award_mode', FILTER_SANITIZE_STRING) ?: 'both';
        $requires_parent_approval = isset($_POST['requires_parent_approval']) ? 1 : 0;
        $task_target_ids = array_values(array_filter(array_map('intval', $_POST['task_target_ids'] ?? [])));

        if (!in_array($goal_type, ['routine_streak', 'routine_count'], true)) {
            $routine_ids = [];
        }
        $routine_id = !empty($routine_ids) ? $routine_ids[0] : null;
        $options = [
            'child_user_id' => $child_user_id,
            'description' => $description,
            'goal_type' => $goal_type,
            'routine_id' => $routine_id,
            'routine_target_ids' => $routine_ids,
            'task_category' => $task_category,
            'target_count' => max(0, (int) ($target_count ?? 0)),
            'streak_required' => max(0, (int) ($streak_required ?? 0)),
            'time_window_type' => $time_window_type === 'fixed' ? 'fixed' : 'rolling',
            'time_window_days' => max(0, (int) ($time_window_days ?? 0)),
            'fixed_window_start' => $fixed_window_start ?: null,
            'fixed_window_end' => $fixed_window_end ?: null,
            'require_on_time' => $require_on_time,
            'points_awarded' => max(0, (int) ($points_awarded ?? 0)),
            'award_mode' => in_array($award_mode, ['points', 'reward', 'both'], true) ? $award_mode : 'both',
            'requires_parent_approval' => $requires_parent_approval,
            'task_target_ids' => $task_target_ids
        ];

        if (updateGoal($goal_id, $family_root_id, $title, $start_date, $end_date, $reward_id, $options)) {
            if ($reactivateOnSave) {
                if (reactivateGoal($goal_id, $family_root_id)) {
                    $updated = $db->prepare("SELECT * FROM goals WHERE id = :goal_id");
                    $updated->execute([':goal_id' => $goal_id]);
                    $goalRow = $updated->fetch(PDO::FETCH_ASSOC);
                    if ($goalRow) {
                        refreshGoalProgress($goalRow, $goalRow['child_user_id']);
                    }
                    $message = "Goal updated and reactivated successfully!";
                } else {
                    $message = "Goal updated, but failed to reactivate.";
                }
            } else {
                $message = "Goal updated successfully!";
            }
        } else {
            $message = "Failed to update goal.";
        }
    } elseif (isset($_POST['delete_goal']) && isset($_SESSION['user_id']) && canCreateContent($_SESSION['user_id'])) {
        $goal_id = filter_input(INPUT_POST, 'goal_id', FILTER_VALIDATE_INT);
        if (deleteGoal($goal_id, $family_root_id)) {
            $message = "Goal deleted successfully!";
        } else {
            $message = "Failed to delete goal.";
        }
    } elseif (isset($_POST['reactivate_goal']) && isset($_SESSION['user_id']) && canCreateContent($_SESSION['user_id'])) {
        $goal_id = filter_input(INPUT_POST, 'goal_id', FILTER_VALIDATE_INT);
        if (reactivateGoal($goal_id, $family_root_id)) {
            $message = "Goal reactivated successfully!";
        } else {
            $message = "Failed to reactivate goal.";
        }
    } elseif (isset($_POST['request_completion']) && isset($_SESSION['user_id']) && getUserRole($_SESSION['user_id']) === 'child') {
        $goal_id = filter_input(INPUT_POST, 'goal_id', FILTER_VALIDATE_INT);
        if (requestGoalCompletion($goal_id, $_SESSION['user_id'])) {
            $message = "Completion requested! Awaiting parent approval.";
        } else {
            $message = "Failed to request completion.";
        }
    } elseif (isset($_POST['approve_goal']) || isset($_POST['reject_goal'])) {
        $goal_id = filter_input(INPUT_POST, 'goal_id', FILTER_VALIDATE_INT);
        $action = isset($_POST['approve_goal']) ? 'approve' : 'reject';
        $rejection_comment = $action === 'reject' ? filter_input(INPUT_POST, 'rejection_comment', FILTER_UNSAFE_RAW, FILTER_FLAG_STRIP_LOW) : null;

        if (!canCreateContent($_SESSION['user_id'])) {
            $message = "Access denied.";
        } else {
            if ($action === 'approve') {
                if (approveGoal($goal_id, $family_root_id)) {
                    $message = "Goal approved!";
                } else {
                    $message = "Failed to approve goal.";
                }
            } else {
                $rejectError = null;
                if (rejectGoal($goal_id, $family_root_id, $rejection_comment, $rejectError)) {
                    $message = "Goal denied.";
                } else {
                    $message = "Failed to deny goal." . ($rejectError ? " Reason: " . htmlspecialchars($rejectError) : "");
                }
            }
        }
    }
}

// Fetch goals for the user
$goals = [];
if (isset($_SESSION['user_id']) && canCreateContent($_SESSION['user_id'])) {
    $stmt = $db->prepare("SELECT 
                             g.id, 
                             g.child_user_id,
                             g.title, 
                             g.description,
                             g.start_date, 
                             g.end_date, 
                             g.status, 
                             g.reward_id, 
                             g.goal_type,
                             g.routine_id,
                             g.task_category,
                             g.target_count,
                             g.streak_required,
                             g.time_window_type,
                             g.time_window_days,
                             g.fixed_window_start,
                             g.fixed_window_end,
                             g.require_on_time,
                             g.points_awarded,
                             g.award_mode,
                             g.requires_parent_approval,
                             r.title AS reward_title, 
                             COALESCE(
                                 NULLIF(TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))), ''),
                                 NULLIF(u.name, ''),
                                 u.username
                             ) AS child_display_name, 
                             g.rejected_at, 
                             g.rejection_comment, 
                             g.created_at,
                             rt.title AS routine_title,
                             COALESCE(
                                 NULLIF(TRIM(CONCAT(COALESCE(creator.first_name, ''), ' ', COALESCE(creator.last_name, ''))), ''),
                                 NULLIF(creator.name, ''),
                                 creator.username,
                                 'Unknown'
                             ) AS creator_display_name
                         FROM goals g 
                         JOIN users u ON g.child_user_id = u.id 
                         LEFT JOIN rewards r ON g.reward_id = r.id 
                         LEFT JOIN routines rt ON g.routine_id = rt.id
                         LEFT JOIN users creator ON g.created_by = creator.id
                         WHERE g.parent_user_id = :parent_id 
                         ORDER BY g.start_date ASC");
    $stmt->execute([':parent_id' => $family_root_id]);
    $goals = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else { // Child
    $stmt = $db->prepare("SELECT 
                             g.id, 
                             g.child_user_id,
                             g.title, 
                             g.description,
                             g.start_date, 
                             g.end_date, 
                             g.status, 
                             g.reward_id, 
                             g.goal_type,
                             g.routine_id,
                             g.task_category,
                             g.target_count,
                             g.streak_required,
                             g.time_window_type,
                             g.time_window_days,
                             g.fixed_window_start,
                             g.fixed_window_end,
                             g.require_on_time,
                             g.points_awarded,
                             g.award_mode,
                             g.requires_parent_approval,
                             r.title AS reward_title, 
                             g.rejected_at, 
                             g.rejection_comment, 
                             g.created_at,
                             rt.title AS routine_title,
                             COALESCE(
                                 NULLIF(TRIM(CONCAT(COALESCE(creator.first_name, ''), ' ', COALESCE(creator.last_name, ''))), ''),
                                 NULLIF(creator.name, ''),
                                 creator.username,
                                 'Unknown'
                             ) AS creator_display_name
                         FROM goals g 
                         LEFT JOIN rewards r ON g.reward_id = r.id 
                         LEFT JOIN routines rt ON g.routine_id = rt.id
                         LEFT JOIN users creator ON g.created_by = creator.id
                         WHERE g.child_user_id = :child_id 
                         ORDER BY g.start_date ASC");
    $stmt->execute([':child_id' => $_SESSION['user_id']]);
    $goals = $stmt->fetchAll(PDO::FETCH_ASSOC);
    error_log("Child goals fetched: " . print_r($goals, true)); // Debugging log
}

$goals = hydrateGoalRoutineData($goals);
$goals = hydrateGoalTaskData($goals);

// Format dates for all goals
foreach ($goals as &$goal) {
    $goal['start_date_formatted'] = date('m/d/Y h:i A', strtotime($goal['start_date']));
    $goal['end_date_formatted'] = date('m/d/Y h:i A', strtotime($goal['end_date']));
    if (isset($goal['rejected_at'])) {
        $goal['rejected_at_formatted'] = date('m/d/Y h:i A', strtotime($goal['rejected_at']));
    }
    $goal['created_at_formatted'] = date('m/d/Y h:i A', strtotime($goal['created_at']));
}
unset($goal);

// Group goals by status
$active_goals = array_filter($goals, function($g) { return $g['status'] === 'active' || $g['status'] === 'pending_approval'; });
$completed_goals = array_filter($goals, function($g) { return $g['status'] === 'completed'; });
$rejected_goals = array_filter($goals, function($g) { return $g['status'] === 'rejected'; });

// Fetch all goals for parent view (including active, pending, completed, rejected)
$all_goals = [];
if (isset($_SESSION['user_id']) && canCreateContent($_SESSION['user_id'])) {
    $stmt = $db->prepare("SELECT 
                             g.id, 
                             g.child_user_id,
                             g.title, 
                             g.description,
                             g.start_date, 
                             g.end_date, 
                             g.status, 
                             g.reward_id, 
                             g.goal_type,
                             g.routine_id,
                             g.task_category,
                             g.target_count,
                             g.streak_required,
                             g.time_window_type,
                             g.time_window_days,
                             g.fixed_window_start,
                             g.fixed_window_end,
                             g.require_on_time,
                             g.points_awarded,
                             g.award_mode,
                             g.requires_parent_approval,
                             r.title AS reward_title, 
                             COALESCE(
                                 NULLIF(TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))), ''),
                                 NULLIF(u.name, ''),
                                 u.username
                             ) AS child_display_name, 
                             g.rejected_at, 
                             g.rejection_comment, 
                             g.created_at,
                             rt.title AS routine_title,
                             COALESCE(
                                 NULLIF(TRIM(CONCAT(COALESCE(creator.first_name, ''), ' ', COALESCE(creator.last_name, ''))), ''),
                                 NULLIF(creator.name, ''),
                                 creator.username,
                                 'Unknown'
                             ) AS creator_display_name
                         FROM goals g 
                         JOIN users u ON g.child_user_id = u.id 
                         LEFT JOIN rewards r ON g.reward_id = r.id 
                         LEFT JOIN routines rt ON g.routine_id = rt.id
                         LEFT JOIN users creator ON g.created_by = creator.id
                         WHERE g.parent_user_id = :parent_id 
                         ORDER BY g.start_date ASC");
    $stmt->execute([':parent_id' => $family_root_id]);
    $all_goals = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $all_goals = hydrateGoalRoutineData($all_goals);
    $all_goals = hydrateGoalTaskData($all_goals);

    // Format dates for all goals in parent view
    foreach ($all_goals as &$goal) {
        $goal['start_date_formatted'] = date('m/d/Y h:i A', strtotime($goal['start_date']));
        $goal['end_date_formatted'] = date('m/d/Y h:i A', strtotime($goal['end_date']));
        if (isset($goal['rejected_at'])) {
            $goal['rejected_at_formatted'] = date('m/d/Y h:i A', strtotime($goal['rejected_at']));
        }
        $goal['created_at_formatted'] = date('m/d/Y h:i A', strtotime($goal['created_at']));
    }
    unset($goal);

    $active_goals = array_filter($all_goals, function($g) { return $g['status'] === 'active' || $g['status'] === 'pending_approval'; });
    $completed_goals = array_filter($all_goals, function($g) { return $g['status'] === 'completed'; });
    $rejected_goals = array_filter($all_goals, function($g) { return $g['status'] === 'rejected'; });
}

$welcome_role_label = getUserRoleLabel($_SESSION['user_id']);
if (!$welcome_role_label) {
    $fallback_role = getEffectiveRole($_SESSION['user_id']) ?: ($_SESSION['role'] ?? null);
    if ($fallback_role) {
        $welcome_role_label = ucfirst(str_replace('_', ' ', $fallback_role));
    }
}

$bodyClasses = [];
if (isset($_SESSION['role']) && $_SESSION['role'] === 'child') {
    $bodyClasses[] = 'child-theme';
}

$goalFormData = null;
if (isset($_SESSION['user_id']) && canCreateContent($_SESSION['user_id'])) {
    $childStmt = $db->prepare("SELECT cp.child_user_id, cp.child_name, cp.avatar
                               FROM child_profiles cp
                               WHERE cp.parent_user_id = :parent_id AND cp.deleted_at IS NULL
                               ORDER BY cp.child_name");
    $childStmt->execute([':parent_id' => $family_root_id]);
    $goalChildren = $childStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $routineStmt = $db->prepare("SELECT id, title, child_user_id FROM routines WHERE parent_user_id = :parent_id ORDER BY title");
    $routineStmt->execute([':parent_id' => $family_root_id]);
    $goalRoutines = $routineStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $taskStmt = $db->prepare("SELECT id, title, child_user_id, category FROM tasks WHERE parent_user_id = :parent_id ORDER BY title");
    $taskStmt->execute([':parent_id' => $family_root_id]);
    $goalTasks = $taskStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $goalRewardTemplates = getRewardTemplates($family_root_id);

    $goalFormData = [
        'children' => $goalChildren,
        'routines' => $goalRoutines,
        'tasks' => $goalTasks,
        'reward_templates' => $goalRewardTemplates
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Goal Management</title>
    <link rel="stylesheet" href="css/main.css?v=3.17.6">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css" integrity="Evv84Mr4kqVGRNSgIGL/F/aIDqQb7xQ2vcrdIwxfjThSH8CSR7PBEakCr51Ck+w+/U6swU2Im1vVX0SVk9ABhg==" crossorigin="anonymous" referrerpolicy="no-referrer">
    <style>
        .goal-list {
            padding: 20px;
            max-width: 900px;
            margin: 0 auto;
        }
        .goal-actions {
            max-width: 900px;
            margin: 0 auto 16px;
            padding: 0 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }
        .goal-actions h2 {
            margin: 0;
        }
        .goal-create-button {
            width: 52px;
            height: 52px;
            border-radius: 50%;
            border: none;
            background: #4caf50;
            color: #fff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.4rem;
            cursor: pointer;
            box-shadow: 0 6px 14px rgba(76, 175, 80, 0.35);
        }
        .goal-create-button:hover { background: #43a047; }
        .goal-create-modal {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.45);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 4100;
            padding: 14px;
        }
        .goal-create-modal.open { display: flex; }
        .goal-create-card {
            background: #fff;
            border-radius: 14px;
            max-width: 780px;
            width: min(780px, 100%);
            max-height: 90vh;
            overflow: hidden;
            box-shadow: 0 12px 32px rgba(0,0,0,0.25);
            display: grid;
            grid-template-rows: auto 1fr;
        }
        .goal-create-card header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 16px;
            border-bottom: 1px solid #e0e0e0;
        }
        .goal-edit-modal {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.45);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 4150;
            padding: 14px;
        }
        .goal-edit-modal.open { display: flex; }
        .goal-edit-card {
            background: #fff;
            border-radius: 14px;
            max-width: 780px;
            width: min(780px, 100%);
            max-height: 90vh;
            overflow: hidden;
            box-shadow: 0 12px 32px rgba(0,0,0,0.25);
            display: grid;
            grid-template-rows: auto 1fr;
        }
        .goal-edit-card header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 16px;
            border-bottom: 1px solid #e0e0e0;
        }
        .task-modal-close {
            background: transparent;
            border: none;
            font-size: 1.3rem;
            cursor: pointer;
            color: #555;
        }
        .goal-create-body {
            padding: 12px 16px 18px;
            overflow-y: auto;
        }
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 14px;
        }
        .form-group {
            display: grid;
            gap: 6px;
        }
        .form-group.full-span {
            grid-column: 1 / -1;
        }
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 8px;
        }
        .form-actions {
            display: flex;
            justify-content: flex-end;
            margin-top: 12px;
        }
        .child-select-grid { display: flex; flex-wrap: wrap; gap: 14px; }
        .child-select-card {
            border: none;
            border-radius: 50%;
            padding: 0;
            background: transparent;
            display: grid;
            justify-items: center;
            gap: 8px;
            cursor: pointer;
            position: relative;
        }
        .child-select-card input[type="radio"] {
            position: absolute;
            opacity: 0;
            width: 0;
            height: 0;
            pointer-events: none;
        }
        .child-select-card img {
            width: 52px;
            height: 52px;
            border-radius: 50%;
            object-fit: cover;
            box-shadow: 0 2px 6px rgba(0,0,0,0.15);
            transition: box-shadow 150ms ease, transform 150ms ease;
        }
        .child-select-card span {
            font-size: 13px;
            width: min-content;
            text-align: center;
            transition: color 150ms ease, text-shadow 150ms ease;
        }
        .child-select-card input[type="radio"]:checked + img {
            box-shadow: 0 0 0 4px rgba(100,181,246,0.8), 0 0 14px rgba(100,181,246,0.8);
            transform: translateY(-2px);
        }
        .child-select-card input[type="radio"]:checked + img + span {
            color: #0d47a1;
            text-shadow: 0 1px 8px rgba(100,181,246,0.8);
        }
        .task-section-toggle {
            margin: 18px 0 10px;
            border: 1px solid #e0e0e0;
            border-radius: 10px;
            padding: 8px 12px;
            background: #fff;
            overflow: hidden;
            transition: border-color 200ms ease, box-shadow 200ms ease;
        }
        .task-section-toggle summary {
            cursor: pointer;
            font-weight: 700;
            color: #37474f;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }
        .task-section-title { display: inline-flex; align-items: center; gap: 8px; }
        .task-section-toggle summary::-webkit-details-marker { display: none; }
        .task-section-toggle summary::after {
            content: '\f078';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            font-size: 0.85rem;
            color: #607d8b;
            transition: transform 200ms ease;
        }
        .task-section-toggle[open] summary::after { transform: rotate(180deg); }
        .task-section-toggle[open] { border-color: #ffd28a; box-shadow: 0 6px 16px rgba(255, 210, 138, 0.25); }
        .task-section-content {
            overflow: hidden;
            max-height: 0;
            opacity: 0;
            transform: translateY(-6px);
            transition: max-height 280ms ease, opacity 200ms ease, transform 200ms ease;
            margin-top: 15px;
        }
        .task-section-toggle[open] .task-section-content {
            max-height: 3000px;
            opacity: 1;
            transform: translateY(0);
        }
        .task-count-badge {
            background: #ff6f61;
            color: #fff;
            border-radius: 12px;
            padding: 2px 8px;
            font-size: 0.8rem;
            font-weight: 700;
            min-width: 24px;
            text-align: center;
        }
        .toggle-row { display: inline-flex; align-items: center; justify-content: flex-start; }
        .toggle-row input[type="checkbox"] { width: 18px; height: 18px; }
        .toggle-field { display: flex; flex-direction: column; gap: 6px; align-items: flex-start; }
        .toggle-switch { position: relative; display: inline-flex; align-items: center; }
        .toggle-switch input { position: absolute; opacity: 0; width: 0; height: 0; }
        .toggle-slider { width: 44px; height: 24px; background: #cfd8dc; border-radius: 999px; position: relative; transition: background 150ms ease; display: inline-block; }
        .toggle-slider::after { content: ''; position: absolute; top: 3px; left: 3px; width: 18px; height: 18px; background: #fff; border-radius: 50%; box-shadow: 0 1px 3px rgba(0,0,0,0.2); transition: transform 150ms ease; }
        .toggle-switch input:checked + .toggle-slider { background: #4caf50; }
        .toggle-switch input:checked + .toggle-slider::after { transform: translateX(20px); }
        .toggle-label { font-weight: 600; }
        .tooltip {
            position: relative;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            margin-left: 6px;
            color: #607d8b;
            cursor: help;
        }
        .tooltip i { font-size: 0.9rem; }
        .tooltip .tooltip-text {
            position: absolute;
            left: 50%;
            top: calc(100% + 6px);
            transform: translateX(-50%);
            background: #263238;
            color: #fff;
            padding: 8px 10px;
            border-radius: 8px;
            font-size: 0.82rem;
            width: 240px;
            max-width: min(240px, 80vw);
            line-height: 1.35;
            opacity: 0;
            visibility: hidden;
            pointer-events: none;
            transition: opacity 150ms ease;
            z-index: 5;
        }
        .tooltip.tooltip-left .tooltip-text {
            left: 0;
            transform: none;
        }
        .tooltip.tooltip-right .tooltip-text {
            right: -80px;
            left: auto;
            transform: none;
        }
        .tooltip.tooltip-shift-left .tooltip-text {
            left: -80px;
            right: auto;
            transform: none;
        }
        @media (max-width: 700px) {
            .tooltip.tooltip-shift-left .tooltip-text {
                left: 0;
                right: auto;
            }
        }
        .tooltip.open .tooltip-text {
            opacity: 1;
            visibility: visible;
        }
        .tooltip:focus .tooltip-text,
        .tooltip:hover .tooltip-text {
            opacity: 1;
            visibility: visible;
        }
        .goal-progress { margin-top: 12px; display: grid; gap: 6px; }
        .goal-progress-header { display: flex; align-items: center; justify-content: space-between; font-weight: 700; color: #37474f; }
        .goal-progress-bar { 
            height: 30px;
            border-radius: 999px;
            background: #edf1f7;
            overflow: hidden;
            border: 1.5px solid #e2e2e2;
            position: relative;
            --goal-progress-steps: 1;
            --goal-progress-tick-width: 1.5px;
            --goal-progress-tick-color: #e2e2e2;
        }
        .goal-progress-bar span { display: block; height: 100%; background: linear-gradient(90deg, #00bcd4, #4caf50); width: 0; transition: width 300ms ease; box-shadow: 0 0 8px rgba(0, 188, 212, 0.35); }
        .goal-progress-bar.complete span { background: #4caf50; animation: none; box-shadow: none; }
        body:not(.child-theme) .goal-progress-bar span { transform-origin: left center; animation: goal-fill 650ms ease-out 1; }
        .goal-progress-bar::after { content: ''; position: absolute; inset: 0; border-radius: inherit; pointer-events: none; }
        .goal-progress-bar.has-steps::after {
            background-image: repeating-linear-gradient(
                to right,
                transparent,
                transparent calc(100% / var(--goal-progress-steps) - var(--goal-progress-tick-width)),
                var(--goal-progress-tick-color) calc(100% / var(--goal-progress-steps) - var(--goal-progress-tick-width)),
                var(--goal-progress-tick-color) calc(100% / var(--goal-progress-steps))
            );
            opacity: 1;
        }
        .goal-next-needed { font-size: 0.92rem; color: #455a64; }
        .goal-meta { display: grid; gap: 6px; color: #5f6c76; font-size: 0.92rem; margin-top: 8px; }
        .goal-detail-pill { display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 999px; background: #eef4ff; color: #0d47a1; font-weight: 700; font-size: 0.85rem; }
        .goal-routine-badges { display: flex; flex-wrap: wrap; gap: 6px; }
        .goal-routine-badge { display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 999px; background: #f2f5f9; color: #37474f; font-weight: 700; font-size: 0.82rem; }
        .goal-routine-count { display: inline-flex; align-items: center; justify-content: center; min-width: 22px; padding: 2px 8px; border-radius: 999px; background: #1565c0; color: #fff; font-weight: 700; font-size: 0.78rem; }
        .goal-card-header { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 8px; }
        .goal-card-title-text { font-size: 1.2rem; font-weight: 600; text-align: left; margin: 0; }
        .goal-status-badge { color: #f9f9f9; font-weight: 600; font-size: 0.85rem; letter-spacing: 2px; border-radius: 50px; padding: 5px 10px; margin-left: 1%; }
        .goal-status-badge.active { background-color: #1db41d; }
        .goal-status-badge.completed { background-color: #4caf50; }
        .completed { background-color: #4caf50; }
        .goal-status-badge.rejected { background-color: #d32f2f; }
        .goal-info-row { display: flex; flex-wrap: wrap; gap: 6px; }
        .goal-info-label { font-weight: 700; color: #919191; display: inline-flex; align-items: center; margin-right: 6px; }
        .goal-assignee { text-align: left; margin-top: 0; }
        .goal-description { text-align: left; }
        .goal-info-row { text-align: left; }
        .goal-description { margin: 6px 0 0; color: #546e7a; }
        .goal-task-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 10px; max-height: 180px; overflow: auto; padding: 6px; border: 1px solid #e0e0e0; border-radius: 10px; background: #fafafa; }
        .goal-task-card { border: 1px solid #d5def0; border-radius: 10px; padding: 8px; display: grid; gap: 6px; background: #fff; }
        .goal-task-card input { margin-right: 6px; }
        .input-error { border-color: #d32f2f !important; box-shadow: 0 0 0 2px rgba(211, 47, 47, 0.2); }
        .form-error { color: #d32f2f; font-weight: 700; margin-bottom: 8px; }
        .child-select-grid.input-error { outline: 2px solid #d32f2f; border-radius: 12px; padding: 6px; }
        .icon-button { width: 36px; height: 36px; border: none; background: transparent; color: #919191; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; cursor: pointer; }
        /* .icon-button:hover { background: rgba(0,0,0,0.06); color: #37474f; } */
        .icon-button:hover {color: #7a7a7a; }
        /* .icon-button.danger { color: #d32f2f; }
        .icon-button.danger:hover { background: rgba(211,47,47,0.12); } */
        .child-theme .goal-progress-bar {
            height: 30px;
            background: #ffe9c6;
            border: 2px solid #ffb74d;
            --goal-progress-tick-width: 1px;
            --goal-progress-tick-color: #ffb74d;
        }
        .child-theme .goal-progress-bar span { background: linear-gradient(90deg, #ff6f61, #ffd54f, #4caf50); background-size: 200% 100%; animation: goal-spark 2.4s linear infinite; box-shadow: 0 0 8px rgba(255, 111, 97, 0.35); }
        .child-theme .goal-progress-bar.complete span { background: #4caf50; animation: none; box-shadow: none; }
        @keyframes goal-fill {
            from { transform: scaleX(0); }
            to { transform: scaleX(1); }
        }
        @keyframes goal-spark {
            0% { background-position: 200% 50%; }
            100% { background-position: 0% 50%; }
        }
        .goal-celebration { position: fixed; inset: 0; display: none; align-items: center; justify-content: center; background: rgba(255, 248, 225, 0.92); z-index: 5000; }
        .goal-celebration.active { display: flex; }
        .goal-celebration-card { background: #fff; border-radius: 18px; padding: 24px 26px; text-align: center; box-shadow: 0 18px 40px rgba(0,0,0,0.25); position: relative; animation: pop-in 300ms ease; }
        .goal-celebration-close { position: absolute; top: 10px; right: 10px; width: 34px; height: 34px; border: none; border-radius: 50%; background: #f5f5f5; color: #37474f; cursor: pointer; }
        .goal-celebration-close:hover { background: #e0e0e0; }
        .goal-celebration-icon { font-size: 2.2rem; color: #ff9800; margin-bottom: 8px; }
        .goal-celebration-title { font-weight: 800; color: #4caf50; margin: 0 0 6px; }
        .goal-celebration-goal { margin: 0; color: #37474f; font-weight: 700; }
        .goal-confetti { position: absolute; inset: 0; overflow: hidden; pointer-events: none; }
        .goal-confetti span { position: absolute; width: 10px; height: 16px; border-radius: 4px; opacity: 0.9; animation: confetti-fall 1400ms ease-in-out forwards; }
        @keyframes confetti-fall {
            0% { transform: translateY(-20px) rotate(0deg); opacity: 0; }
            10% { opacity: 1; }
            100% { transform: translateY(260px) rotate(160deg); opacity: 0; }
        }
        @keyframes pop-in {
            0% { transform: scale(0.9); opacity: 0; }
            100% { transform: scale(1); opacity: 1; }
        }
        .goal-card {
            padding: 15px;
            margin-bottom: 20px;
            border: 1px solid #ddd;
            border-radius: 8px;
            background: #f9f9f9;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .button {
            padding: 10px 20px;
            margin: 5px;
            background-color: <?php echo (isset($_SESSION['user_id']) && canCreateContent($_SESSION['user_id'])) ? '#4caf50' : '#ff9800'; ?>;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
        }
        .role-badge {
            background: #4caf50;
            color: #fff;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 0.9em;
            margin-left: 8px;
            display: inline-block;
        }
        .edit-delete a {
            margin-right: 10px;
            color: #007bff;
            text-decoration: none;
        }
        .edit-delete a:hover {
            text-decoration: underline;
        }
        .edit-delete { display: flex; justify-content: flex-end; gap: 8px; margin-top: 10px; }
        .reject-comment {
            margin-top: 10px;
        }
        /* Autism-friendly styles for children */
        .child-view .goal-card {
            background-color: #e6f3fa;
            border: 2px solid #2196f3;
            font-size: 1.2em;
        }
        .child-view .button {
            font-size: 1.1em;
            padding: 12px 24px;
        }
        .rejected-card {
            background-color: #ffebee; /* Light red for rejected goals */
            border-left: 5px solid #f44336;
        }
        .no-scroll { overflow: hidden; }
        .page-header { padding: 18px 16px 12px; display: grid; gap: 12px; text-align: left; }
        .page-header-top { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; }
        .page-header-title { display: grid; gap: 6px; }
        .page-header-title h1 { margin: 0; font-size: 1.1rem; color: #2c2c2c; }
        .page-header-meta { margin: 0; color: #616161; display: flex; flex-wrap: wrap; gap: 8px; align-items: center; font-size: 0.6rem; }
        .page-header-actions { display: flex; gap: 10px; align-items: center; }
        .page-header-action { position: relative; display: inline-flex; align-items: center; justify-content: center; width: 40px; height: 40px; border-radius: 50%; border: 1px solid #dfe8df; background: #fff; color: #6d6d6d; box-shadow: 0 6px 14px rgba(0,0,0,0.08); cursor: pointer; }
        .page-header-action i { font-size: 1.1rem; }
        .page-header-action:hover { color: #4caf50; border-color: #c8e6c9; }
        .nav-links { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; justify-content: center; padding: 10px 12px; border-radius: 18px; background: #fff; border: 1px solid #eceff4; box-shadow: 0 8px 18px rgba(0,0,0,0.06); }
        .nav-link,
        .nav-mobile-link { flex: 1 1 90px; display: grid; justify-items: center; gap: 4px; text-decoration: none; color: #6d6d6d; font-weight: 600; font-size: 0.75rem; border-radius: 12px; padding: 6px 4px; }
        .nav-link i,
        .nav-mobile-link i { font-size: 1.2rem; }
        .nav-link.is-active,
        .nav-mobile-link.is-active { color: #4caf50; }
        .nav-link.is-active i,
        .nav-mobile-link.is-active i { color: #4caf50; }
        .nav-link:hover,
        .nav-mobile-link:hover { color: #4caf50; }
        .nav-link-button { background: transparent; border: none; cursor: pointer; }
        .nav-mobile-bottom { display: none; gap: 6px; padding: 10px 12px; border-top: 1px solid #e0e0e0; background: #fff; position: fixed; left: 0; right: 0; bottom: 0; z-index: 900; }
        .nav-mobile-bottom .nav-mobile-link { flex: 1; }
        @media (max-width: 768px) {
            .nav-links { display: none; }
            .nav-mobile-bottom { display: flex; justify-content: space-between; }
            body { padding-bottom: 72px; }
        }
    </style>
</head>
<body<?php echo !empty($bodyClasses) ? ' class="' . implode(' ', $bodyClasses) . '"' : ''; ?>>
    <?php
        $dashboardPage = 'dashboard_' . ($_SESSION['role'] ?? 'parent') . '.php';
        $dashboardActive = $currentPage === $dashboardPage;
        $routinesActive = $currentPage === 'routine.php';
        $tasksActive = $currentPage === 'task.php';
        $goalsActive = $currentPage === 'goal.php';
        $rewardsActive = $currentPage === 'rewards.php';
        $profileActive = $currentPage === 'profile.php';
        $isParentContext = canCreateContent($_SESSION['user_id']);
    ?>
    <header class="page-header">
        <div class="page-header-top">
            <div class="page-header-title">
                <h1>Goal Management</h1>
                <p class="page-header-meta">Welcome back, <?php echo htmlspecialchars($_SESSION['name'] ?? $_SESSION['username'] ?? 'Unknown User'); ?>
                    <?php if ($welcome_role_label): ?>
                        <span class="role-badge"><?php echo htmlspecialchars($welcome_role_label); ?></span>
                    <?php endif; ?>
                </p>
            </div>
            <div class="page-header-actions">
                <?php if (!empty($isParentNotificationUser)): ?>
                    <button type="button" class="page-header-action parent-notification-trigger" data-parent-notify-trigger aria-label="Notifications">
                        <i class="fa-solid fa-bell"></i>
                        <?php if ($parentNotificationCount > 0): ?>
                            <span class="parent-notification-badge"><?php echo (int) $parentNotificationCount; ?></span>
                        <?php endif; ?>
                    </button>
                    <a class="page-header-action" href="dashboard_parent.php#manage-family" aria-label="Family settings">
                        <i class="fa-solid fa-gear"></i>
                    </a>
                <?php elseif (!empty($isChildNotificationUser)): ?>
                    <button type="button" class="page-header-action notification-trigger" data-child-notify-trigger aria-label="Notifications">
                        <i class="fa-solid fa-bell"></i>
                        <?php if ($notificationCount > 0): ?>
                            <span class="notification-badge"><?php echo (int) $notificationCount; ?></span>
                        <?php endif; ?>
                    </button>
                <?php endif; ?>
                <a class="page-header-action" href="logout.php" aria-label="Logout">
                    <i class="fa-solid fa-right-from-bracket"></i>
                </a>
            </div>
        </div>
        <nav class="nav-links" aria-label="Primary">
            <a class="nav-link<?php echo $dashboardActive ? ' is-active' : ''; ?>" href="<?php echo htmlspecialchars($dashboardPage); ?>"<?php echo $dashboardActive ? ' aria-current="page"' : ''; ?>>
                <i class="fa-solid fa-house"></i>
                <span>Dashboard</span>
            </a>
            <a class="nav-link<?php echo $routinesActive ? ' is-active' : ''; ?>" href="routine.php"<?php echo $routinesActive ? ' aria-current="page"' : ''; ?>>
                <i class="fa-solid fa-rotate"></i>
                <span>Routines</span>
            </a>
            <a class="nav-link<?php echo $tasksActive ? ' is-active' : ''; ?>" href="task.php"<?php echo $tasksActive ? ' aria-current="page"' : ''; ?>>
                <i class="fa-solid fa-list-check"></i>
                <span>Tasks</span>
            </a>
            <a class="nav-link<?php echo $goalsActive ? ' is-active' : ''; ?>" href="goal.php"<?php echo $goalsActive ? ' aria-current="page"' : ''; ?>>
                <i class="fa-solid fa-bullseye"></i>
                <span>Goals</span>
            </a>
            <a class="nav-link<?php echo $rewardsActive ? ' is-active' : ''; ?>" href="rewards.php"<?php echo $rewardsActive ? ' aria-current="page"' : ''; ?>>
                <i class="fa-solid fa-gift"></i>
                <span>Rewards</span>
            </a>
            <a class="nav-link<?php echo $profileActive ? ' is-active' : ''; ?>" href="profile.php?self=1"<?php echo $profileActive ? ' aria-current="page"' : ''; ?>>
                <i class="fa-solid fa-user"></i>
                <span>Profile</span>
            </a>
        </nav>
    </header>
    <?php $celebrationGoals = ($_SESSION['role'] === 'child') ? [] : null; ?>
    <main class="<?php echo ($_SESSION['role'] === 'child') ? 'child-view' : ''; ?>">
        <?php if (isset($message)) echo "<p>$message</p>"; ?>
        <?php if (isset($_SESSION['user_id']) && canCreateContent($_SESSION['user_id'])): ?>
            <div class="goal-actions">
                <h2>Goals</h2>
                <button type="button" class="goal-create-button" data-goal-create-open aria-label="Create Goal">
                    <i class="fa-solid fa-plus"></i>
                </button>
            </div>
        <?php endif; ?>
        <div class="goal-list">
            <h2><?php echo (isset($_SESSION['user_id']) && canCreateContent($_SESSION['user_id'])) ? 'Created Goals' : 'Your Goals'; ?></h2>
            <?php if (empty($goals)): ?>
                <p>No goals available.</p>
            <?php else: ?>
                <details class="task-section-toggle" open>
                    <summary>
                        <span class="task-section-title">Active Goals <span class="task-count-badge"><?php echo count($active_goals); ?></span></span>
                    </summary>
                    <div class="task-section-content">
                        <?php if (empty($active_goals)): ?>
                            <p>No active goals.</p>
                        <?php else: ?>
                            <?php foreach ($active_goals as $goal): ?>
                                <?php
                                $goalChildId = (int) ($goal['child_user_id'] ?? ($_SESSION['user_id'] ?? 0));
                                $goalProgressData = getGoalProgressSnapshot($goal, $goalChildId);
                                $goalProgress = $goalProgressData['progress'];
                                $goalTypeLabel = [
                                    'manual' => 'Manual',
                                    'routine_streak' => 'Routine streak',
                                    'routine_count' => 'Routine count',
                                    'task_quota' => 'Task quota'
                                ][$goalProgress['goal_type']] ?? 'Goal';
                                $progressValue = $goalProgress['current'] . ' / ' . $goalProgress['target'];
                                $progressPercent = (int) ($goalProgress['percent'] ?? 0);
                                $progressSteps = min(14, max(1, (int) ($goalProgress['target'] ?? 0)));
                                $displayStatus = $goal['status'];
                                if ($goal['status'] === 'active' && !empty($goalProgress['is_met'])) {
                                    $displayStatus = !empty($goal['requires_parent_approval']) ? 'pending_approval' : 'completed';
                                }
                                $goalType = $goalProgress['goal_type'] ?? '';
                                $routineTargets = $goal['routine_targets'] ?? [];
                                if (count($routineTargets) > 1 && in_array($goalType, ['routine_streak', 'routine_count'], true)) {
                                    $routineCounts = $goalProgress['routine_counts'] ?? [];
                                    $totalUnits = count($routineTargets) * max(1, (int) ($goalProgress['target'] ?? 0));
                                    if ($totalUnits > 0) {
                                        $completedUnits = 0;
                                        foreach ($routineTargets as $routine) {
                                            $completedUnits += (int) ($routineCounts[$routine['id']] ?? 0);
                                        }
                                        if ($completedUnits > $totalUnits) {
                                            $completedUnits = $totalUnits;
                                        }
                                        $progressPercent = (int) round(($completedUnits / $totalUnits) * 100);
                                        $progressSteps = min(14, max(1, $totalUnits));
                                    }
                                }
                                $routineTargetIds = $goal['routine_target_ids'] ?? [];
                                if (empty($routineTargetIds) && !empty($goal['routine_id'])) {
                                    $routineTargetIds = [(int) $goal['routine_id']];
                                }
                                $goalPayload = [
                                    'id' => (int) $goal['id'],
                                    'child_user_id' => (int) ($goal['child_user_id'] ?? 0),
                                    'title' => $goal['title'] ?? '',
                                    'description' => $goal['description'] ?? '',
                                    'start_date' => !empty($goal['start_date']) ? date('Y-m-d\\TH:i', strtotime($goal['start_date'])) : '',
                                    'end_date' => !empty($goal['end_date']) ? date('Y-m-d\\TH:i', strtotime($goal['end_date'])) : '',
                                    'reward_id' => (int) ($goal['reward_id'] ?? 0),
                                    'goal_type' => $goal['goal_type'] ?? 'manual',
                                    'routine_id' => (int) ($goal['routine_id'] ?? 0),
                                    'routine_ids' => array_values(array_filter(array_map('intval', $routineTargetIds))),
                                    'task_category' => $goal['task_category'] ?? '',
                                    'target_count' => (int) ($goal['target_count'] ?? 0),
                                    'streak_required' => (int) ($goal['streak_required'] ?? 0),
                                    'time_window_type' => $goal['time_window_type'] ?? 'rolling',
                                    'time_window_days' => (int) ($goal['time_window_days'] ?? 0),
                                    'fixed_window_start' => $goal['fixed_window_start'] ?? '',
                                    'fixed_window_end' => $goal['fixed_window_end'] ?? '',
                                    'require_on_time' => (int) ($goal['require_on_time'] ?? 0),
                                    'points_awarded' => (int) ($goal['points_awarded'] ?? 0),
                                    'award_mode' => $goal['award_mode'] ?? 'both',
                                    'requires_parent_approval' => (int) ($goal['requires_parent_approval'] ?? 1),
                                    'task_target_ids' => getGoalTaskTargetIds((int) $goal['id'])
                                ];
                                $goalPayloadJson = htmlspecialchars(json_encode($goalPayload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP), ENT_QUOTES, 'UTF-8');
                                ?>
                                <div class="goal-card" id="goal-<?php echo (int) $goal['id']; ?>">
                                    <div class="goal-card-header">
                                        <h3 class="goal-card-title-text"><?php echo htmlspecialchars($goal['title']); ?></h3>
                                        <?php if ($displayStatus === 'active'): ?>
                                            <span class="goal-status-badge active">Active</span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if (!empty($goal['description'])): ?>
                                        <p class="goal-description"><span class="goal-info-label"><i class="fa-solid fa-align-center"></i></span><?php echo nl2br(htmlspecialchars($goal['description'])); ?></p>
                                    <?php endif; ?>
                                    <p class="goal-info-row"><span class="goal-info-label"><i class="fa-regular fa-calendar-days"></i></span><?php echo htmlspecialchars($goal['start_date_formatted']); ?> to <?php echo htmlspecialchars($goal['end_date_formatted']); ?></p>
                                    <p class="goal-info-row"><span class="goal-info-label"><i class="fa-solid fa-gift"></i></span><?php echo htmlspecialchars($goal['reward_title'] ?? 'None'); ?></p>
                                    <?php if (!empty($goal['creator_display_name'])): ?>
                                        <p class="goal-info-row"><span class="goal-info-label"><i class="fa-solid fa-user-pen"></i></span><?php echo htmlspecialchars($goal['creator_display_name']); ?></p>
                                    <?php endif; ?>
                                    <?php if (isset($_SESSION['user_id']) && canCreateContent($_SESSION['user_id'])): ?>
                                        <div class="goal-info-row goal-assignee"><span class="goal-info-label"><i class="fa-solid fa-user"></i></span><?php echo htmlspecialchars($goal['child_display_name']); ?></div>
                                    <?php endif; ?>
                                    <div class="goal-progress">
                                        <div class="goal-progress-header">
                                            <span><?php echo htmlspecialchars($goalTypeLabel); ?></span>
                                            <span><?php echo htmlspecialchars($progressValue); ?></span>
                                        </div>
                                        <div class="goal-progress-bar has-steps"<?php echo $progressSteps > 0 ? ' style="--goal-progress-steps: ' . (int) $progressSteps . ';"' : ''; ?>>
                                            <span style="width: <?php echo (int) $progressPercent; ?>%;"></span>
                                        </div>
                                        <?php if (!empty($goalProgress['next_needed'])): ?>
                                            <div class="goal-next-needed">Next: <?php echo htmlspecialchars($goalProgress['next_needed']); ?></div>
                                        <?php endif; ?>
                                        <div class="goal-meta">
                                            <?php if (!empty($goal['task_targets'])): ?>
                                                <?php $taskCounts = $goalProgress['task_counts'] ?? []; ?>
                                                <div class="goal-routine-badges" aria-label="Task completion counts">
                                                    <?php foreach ($goal['task_targets'] as $task): ?>
                                                        <?php
                                                            $taskId = (int) ($task['id'] ?? 0);
                                                            $taskTitle = $task['title'] ?? '';
                                                            $taskCount = $taskId ? (int) ($taskCounts[$taskId] ?? 0) : 0;
                                                        ?>
                                                        <?php if ($taskTitle !== ''): ?>
                                                            <span class="goal-routine-badge">
                                                                <?php echo htmlspecialchars($taskTitle); ?>
                                                                <span class="goal-routine-count"><?php echo $taskCount; ?></span>
                                                            </span>
                                                        <?php endif; ?>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                            <?php if (!empty($goal['routine_targets'])): ?>
                                                <div class="goal-routine-badges" aria-label="Routine completion counts">
                                                    <?php foreach ($goal['routine_targets'] as $routine): ?>
                                                        <?php $routineCount = $goalProgress['routine_counts'][$routine['id']] ?? 0; ?>
                                                        <span class="goal-routine-badge">
                                                            <?php echo htmlspecialchars($routine['title']); ?>
                                                            <span class="goal-routine-count"><?php echo (int) $routineCount; ?></span>
                                                        </span>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                            <?php if (!empty($goal['task_category'])): ?>
                                                <span class="goal-detail-pill">Category: <?php echo htmlspecialchars(ucfirst($goal['task_category'])); ?></span>
                                            <?php endif; ?>
                                            <?php if (($goalProgress['goal_type'] ?? '') === 'routine_streak'): ?>
                                                <span class="goal-detail-pill">Streak: <?php echo (int) ($goal['streak_required'] ?? 0); ?> days</span>
                                            <?php elseif (in_array(($goalProgress['goal_type'] ?? ''), ['routine_count', 'task_quota'], true)): ?>
                                                <span class="goal-detail-pill">Target days: <?php echo (int) ($goal['target_count'] ?? 0); ?></span>
                                            <?php endif; ?>
                                            <?php if (($goal['award_mode'] ?? 'both') === 'reward' && empty($goal['points_awarded']) && !empty($goal['reward_title'])): ?>
                                                <span class="goal-detail-pill">Reward: <?php echo htmlspecialchars($goal['reward_title']); ?></span>
                                            <?php endif; ?>
                                            <?php if (!empty($goal['require_on_time'])): ?>
                                                <span class="goal-detail-pill">On-time required</span>
                                            <?php endif; ?>
                                            <?php if (!empty($goal['points_awarded'])): ?>
                                                <span class="goal-detail-pill">Points: <?php echo (int) $goal['points_awarded']; ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <?php if (isset($_SESSION['user_id']) && canCreateContent($_SESSION['user_id'])): ?>
                                        <?php if ($goal['status'] === 'pending_approval'): ?>
                                            <form method="POST" action="goal.php">
                                                <input type="hidden" name="goal_id" value="<?php echo $goal['id']; ?>">
                                                <button type="submit" name="approve_goal" class="button">Approve</button>
                                                <button type="submit" name="reject_goal" class="button" style="background-color: #f44336;">Deny</button>
                                                <div class="reject-comment">
                                                    <label for="rejection_comment_<?php echo $goal['id']; ?>">Reason (optional):</label>
                                                    <textarea id="rejection_comment_<?php echo $goal['id']; ?>" name="rejection_comment"></textarea>
                                                </div>
                                            </form>
                                        <?php endif; ?>
                                <div class="edit-delete">
                                    <button type="button" class="icon-button" data-goal-edit-open data-goal-payload="<?php echo $goalPayloadJson; ?>" aria-label="Edit goal">
                                        <i class="fa-solid fa-pen"></i>
                                    </button>
                                    <form method="POST" action="goal.php" style="display:inline;">
                                        <input type="hidden" name="goal_id" value="<?php echo $goal['id']; ?>">
                                        <button type="submit" name="delete_goal" class="icon-button danger" aria-label="Delete goal">
                                            <i class="fa-solid fa-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            <?php elseif ($_SESSION['role'] === 'child' && $goal['status'] === 'active' && ($goal['goal_type'] ?? 'manual') === 'manual'): ?>
                                        <form method="POST" action="goal.php">
                                            <input type="hidden" name="goal_id" value="<?php echo $goal['id']; ?>">
                                            <button type="submit" name="request_completion" class="button">Request Completion</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </details>
                <details class="task-section-toggle">
                    <summary>
                        <span class="task-section-title">Completed Goals <span class="task-count-badge"><?php echo count($completed_goals); ?></span></span>
                    </summary>
                    <div class="task-section-content">
                        <?php if (empty($completed_goals)): ?>
                            <p>No completed goals.</p>
                        <?php else: ?>
                    <?php foreach ($completed_goals as $goal): ?>
                        <?php
                        $goalChildId = (int) ($goal['child_user_id'] ?? ($_SESSION['user_id'] ?? 0));
                        $goalProgressData = getGoalProgressSnapshot($goal, $goalChildId);
                        $goalProgress = $goalProgressData['progress'];
                        $goalTypeLabel = [
                            'manual' => 'Manual',
                            'routine_streak' => 'Routine streak',
                            'routine_count' => 'Routine count',
                            'task_quota' => 'Task quota'
                        ][$goalProgress['goal_type']] ?? 'Goal';
                        $progressValue = $goalProgress['current'] . ' / ' . $goalProgress['target'];
                        $progressPercent = (int) ($goalProgress['percent'] ?? 0);
                        $progressSteps = min(14, max(1, (int) ($goalProgress['target'] ?? 0)));
                        $goalType = $goalProgress['goal_type'] ?? '';
                        $routineTargets = $goal['routine_targets'] ?? [];
                        if (count($routineTargets) > 1 && in_array($goalType, ['routine_streak', 'routine_count'], true)) {
                            $routineCounts = $goalProgress['routine_counts'] ?? [];
                            $totalUnits = count($routineTargets) * max(1, (int) ($goalProgress['target'] ?? 0));
                            if ($totalUnits > 0) {
                                $completedUnits = 0;
                                foreach ($routineTargets as $routine) {
                                    $completedUnits += (int) ($routineCounts[$routine['id']] ?? 0);
                                }
                                if ($completedUnits > $totalUnits) {
                                    $completedUnits = $totalUnits;
                                }
                                $progressPercent = (int) round(($completedUnits / $totalUnits) * 100);
                                $progressSteps = min(14, max(1, $totalUnits));
                            }
                        }
                        if (is_array($celebrationGoals) && !empty($goalProgressData['celebration_ready'])) {
                            $celebrationGoals[] = [
                                'id' => (int) $goal['id'],
                                'title' => $goal['title'] ?? 'Goal achieved'
                            ];
                        }
                        ?>
                        <div class="goal-card" id="goal-<?php echo (int) $goal['id']; ?>">
                            <div class="goal-card-header">
                                <h3 class="goal-card-title-text"><?php echo htmlspecialchars($goal['title']); ?></h3>
                                <span class="goal-status-badge completed">Completed</span>
                            </div>
                            <?php if (!empty($goal['description'])): ?>
                                <p class="goal-description"><span class="goal-info-label"><i class="fa-solid fa-align-center"></i></span><?php echo nl2br(htmlspecialchars($goal['description'])); ?></p>
                            <?php endif; ?>
                            <p class="goal-info-row"><span class="goal-info-label"><i class="fa-regular fa-calendar-days"></i></span><?php echo htmlspecialchars($goal['start_date_formatted']); ?> to <?php echo htmlspecialchars($goal['end_date_formatted']); ?></p>
                            <p class="goal-info-row"><span class="goal-info-label"><i class="fa-solid fa-gift"></i></span><?php echo htmlspecialchars($goal['reward_title'] ?? 'None'); ?></p>
                            <?php if (!empty($goal['creator_display_name'])): ?>
                                <p class="goal-info-row"><span class="goal-info-label"><i class="fa-solid fa-user-pen"></i></span><?php echo htmlspecialchars($goal['creator_display_name']); ?></p>
                            <?php endif; ?>
                            <?php if (isset($_SESSION['user_id']) && canCreateContent($_SESSION['user_id'])): ?>
                                <div class="goal-info-row goal-assignee"><span class="goal-info-label"><i class="fa-solid fa-user"></i></span><?php echo htmlspecialchars($goal['child_display_name']); ?></div>
                            <?php endif; ?>
                            <div class="goal-progress">
                                <div class="goal-progress-header">
                                    <span><?php echo htmlspecialchars($goalTypeLabel); ?></span>
                                    <span><?php echo htmlspecialchars($progressValue); ?></span>
                                </div>
                            <div class="goal-progress-bar has-steps complete"<?php echo $progressSteps > 0 ? ' style="--goal-progress-steps: ' . (int) $progressSteps . ';"' : ''; ?>>
                                <span style="width: <?php echo (int) $progressPercent; ?>%;"></span>
                            </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </details>
                <details class="task-section-toggle" <?php echo !empty($rejected_goals) ? 'open' : ''; ?>>
                    <summary>
                        <span class="task-section-title">Inactive Goals <span class="task-count-badge"><?php echo count($rejected_goals); ?></span></span>
                    </summary>
                    <div class="task-section-content">
                        <?php if (empty($rejected_goals)): ?>
                            <p>No inactive goals.</p>
                        <?php else: ?>
                            <?php foreach ($rejected_goals as $goal): ?>
                                <?php
                                    $inactiveLabel = 'Denied';
                                    $rejectionNote = (string) ($goal['rejection_comment'] ?? '');
                                    if (stripos($rejectionNote, 'Incomplete') === 0) {
                                        $inactiveLabel = 'Incomplete';
                                    }
                                    $inactiveDateLabel = $inactiveLabel === 'Incomplete' ? 'Incomplete on' : 'Denied on';
                                    $inactiveRoutineTargetIds = $goal['routine_target_ids'] ?? [];
                                    if (empty($inactiveRoutineTargetIds) && !empty($goal['routine_id'])) {
                                        $inactiveRoutineTargetIds = [(int) $goal['routine_id']];
                                    }
                                    $inactivePayload = [
                                        'id' => (int) $goal['id'],
                                        'child_user_id' => (int) ($goal['child_user_id'] ?? 0),
                                        'title' => $goal['title'] ?? '',
                                        'description' => $goal['description'] ?? '',
                                        'start_date' => !empty($goal['start_date']) ? date('Y-m-d\\TH:i', strtotime($goal['start_date'])) : '',
                                        'end_date' => !empty($goal['end_date']) ? date('Y-m-d\\TH:i', strtotime($goal['end_date'])) : '',
                                        'reward_id' => (int) ($goal['reward_id'] ?? 0),
                                        'goal_type' => $goal['goal_type'] ?? 'manual',
                                        'routine_id' => (int) ($goal['routine_id'] ?? 0),
                                        'routine_ids' => array_values(array_filter(array_map('intval', $inactiveRoutineTargetIds))),
                                        'task_category' => $goal['task_category'] ?? '',
                                        'target_count' => (int) ($goal['target_count'] ?? 0),
                                        'streak_required' => (int) ($goal['streak_required'] ?? 0),
                                        'time_window_type' => $goal['time_window_type'] ?? 'rolling',
                                        'time_window_days' => (int) ($goal['time_window_days'] ?? 0),
                                        'fixed_window_start' => $goal['fixed_window_start'] ?? '',
                                        'fixed_window_end' => $goal['fixed_window_end'] ?? '',
                                        'require_on_time' => (int) ($goal['require_on_time'] ?? 0),
                                        'points_awarded' => (int) ($goal['points_awarded'] ?? 0),
                                        'award_mode' => $goal['award_mode'] ?? 'both',
                                        'requires_parent_approval' => (int) ($goal['requires_parent_approval'] ?? 1),
                                        'task_target_ids' => getGoalTaskTargetIds((int) $goal['id'])
                                    ];
                                    $inactivePayloadJson = htmlspecialchars(json_encode($inactivePayload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP), ENT_QUOTES, 'UTF-8');
                                ?>
                                <div class="goal-card rejected-card" id="goal-<?php echo (int) $goal['id']; ?>">
                                    <div class="goal-card-header">
                                        <h3 class="goal-card-title-text"><?php echo htmlspecialchars($goal['title']); ?></h3>
                                        <span class="goal-status-badge rejected"><?php echo htmlspecialchars($inactiveLabel); ?></span>
                                    </div>
                                    <?php if (!empty($goal['description'])): ?>
                                        <p class="goal-description"><span class="goal-info-label"><i class="fa-solid fa-align-center"></i></span><?php echo nl2br(htmlspecialchars($goal['description'])); ?></p>
                                    <?php endif; ?>
                                    <p class="goal-info-row"><span class="goal-info-label"><i class="fa-regular fa-calendar-days"></i></span><?php echo htmlspecialchars($goal['start_date_formatted']); ?> to <?php echo htmlspecialchars($goal['end_date_formatted']); ?></p>
                                    <p class="goal-info-row"><span class="goal-info-label"><i class="fa-solid fa-gift"></i></span><?php echo htmlspecialchars($goal['reward_title'] ?? 'None'); ?></p>
                                    <?php if (!empty($goal['creator_display_name'])): ?>
                                        <p class="goal-info-row"><span class="goal-info-label"><i class="fa-solid fa-user-pen"></i></span><?php echo htmlspecialchars($goal['creator_display_name']); ?></p>
                                    <?php endif; ?>
                                    <?php if (isset($_SESSION['user_id']) && canCreateContent($_SESSION['user_id'])): ?>
                                        <div class="goal-info-row goal-assignee"><span class="goal-info-label"><i class="fa-solid fa-user"></i></span><?php echo htmlspecialchars($goal['child_display_name']); ?></div>
                                    <?php endif; ?>
                                    <p class="goal-info-row"><span class="goal-info-label"><i class="fa-regular fa-calendar-days"></i></span><?php echo htmlspecialchars($inactiveDateLabel); ?>: <?php echo htmlspecialchars($goal['rejected_at_formatted']); ?></p>
                                    <p class="goal-info-row"><span class="goal-info-label"><i class="fa-solid fa-comment"></i></span><?php echo htmlspecialchars($goal['rejection_comment'] ?? 'No comments available.'); ?></p>
                                    <?php if (isset($_SESSION['user_id']) && canCreateContent($_SESSION['user_id'])): ?>
                                        <div class="edit-delete">
                                            <button type="button" class="button" data-goal-edit-open data-goal-reactivate="1" data-goal-payload="<?php echo $inactivePayloadJson; ?>" aria-label="Reactivate goal">
                                                Reactivate
                                            </button>
                                            <form method="POST" action="goal.php" style="display:inline;">
                                                <input type="hidden" name="goal_id" value="<?php echo $goal['id']; ?>">
                                                <button type="submit" name="delete_goal" class="icon-button danger" aria-label="Delete goal">
                                                    <i class="fa-solid fa-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                    <?php elseif ($_SESSION['role'] === 'child'): ?>
                                        <p>Created on: <?php echo htmlspecialchars($goal['created_at_formatted']); ?></p>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </details>
            <?php endif; ?>
        </div>
    </main>
    <?php if (isset($_SESSION['user_id']) && canCreateContent($_SESSION['user_id'])): ?>
        <div class="goal-create-modal" data-goal-create-modal>
            <div class="goal-create-card" role="dialog" aria-modal="true" aria-labelledby="goal-create-title">
                <header>
                    <h2 id="goal-create-title">Create Goal</h2>
                    <button type="button" class="task-modal-close" aria-label="Close create goal" data-goal-create-close>&times;</button>
                </header>
                <div class="goal-create-body">
                    <form method="POST" action="goal.php" data-goal-create-form>
                        <div class="form-error" data-goal-create-error style="display:none;"></div>
                        <?php
                        $children = $goalFormData['children'] ?? [];
                        $autoSelectChildId = count($children) === 1 ? (int) $children[0]['child_user_id'] : null;
                        $routines = $goalFormData['routines'] ?? [];
                        $goalTasks = $goalFormData['tasks'] ?? [];
                        $goalRewardTemplates = $goalFormData['reward_templates'] ?? [];
                        ?>
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Child
                                    <span class="tooltip tooltip-left" tabindex="0" aria-label="Pick the child this goal is for. This filters routines and tasks.">
                                        <i class="fa-solid fa-circle-info"></i>
                                        <span class="tooltip-text">Pick the child this goal is for. This filters routines and tasks.</span>
                                    </span>
                                </label>
                                <div class="child-select-grid">
                                    <?php if (!empty($children)): ?>
                                        <?php foreach ($children as $child):
                                            $avatar = !empty($child['avatar']) ? $child['avatar'] : 'images/default-avatar.png';
                                            ?>
                                            <label class="child-select-card">
                                                <input type="radio" name="child_user_id" value="<?php echo (int) $child['child_user_id']; ?>"<?php echo $autoSelectChildId === (int) $child['child_user_id'] ? ' checked' : ''; ?> required>
                                                <img src="<?php echo htmlspecialchars($avatar); ?>" alt="<?php echo htmlspecialchars($child['child_name']); ?>">
                                                <span><?php echo htmlspecialchars($child['child_name']); ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <p>No children found.</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="goal_title">Title
                                    <span class="tooltip tooltip-left" tabindex="0" aria-label="Short name shown on goal cards and notifications.">
                                        <i class="fa-solid fa-circle-info"></i>
                                        <span class="tooltip-text">Short name shown on goal cards and notifications.</span>
                                    </span>
                                </label>
                                <input type="text" id="goal_title" name="title" required>
                            </div>
                            <div class="form-group full-span">
                                <label for="goal_description">Description
                                    <span class="tooltip tooltip-left" tabindex="0" aria-label="Optional details or requirements shown on goal cards and the child dashboard.">
                                        <i class="fa-solid fa-circle-info"></i>
                                        <span class="tooltip-text">Optional details or requirements shown on goal cards and the child dashboard.</span>
                                    </span>
                                </label>
                                <textarea id="goal_description" name="description" rows="3" placeholder="Optional details about the goal"></textarea>
                            </div>
                            <div class="form-group">
                                <label for="goal_start_date">Start Date/Time
                                    <span class="tooltip" tabindex="0" aria-label="When the goal becomes active. Progress before this does not count.">
                                        <i class="fa-solid fa-circle-info"></i>
                                        <span class="tooltip-text">When the goal becomes active. Progress before this does not count.</span>
                                    </span>
                                </label>
                                <input type="datetime-local" id="goal_start_date" name="start_date" required>
                            </div>
                            <div class="form-group">
                                <label for="goal_end_date">End Date/Time
                                    <span class="tooltip" tabindex="0" aria-label="Deadline. Progress after this does not count.">
                                        <i class="fa-solid fa-circle-info"></i>
                                        <span class="tooltip-text">Deadline. Progress after this does not count.</span>
                                    </span>
                                </label>
                                <input type="datetime-local" id="goal_end_date" name="end_date" required>
                            </div>
                            <div class="form-group">
                                <label for="goal_type">Goal Type
                                    <span class="tooltip tooltip-shift-left" tabindex="0" aria-label="Choose how progress is tracked and which fields are required.">
                                        <i class="fa-solid fa-circle-info"></i>
                                        <span class="tooltip-text">Choose how progress is tracked and which fields are required.</span>
                                    </span>
                                </label>
                                <select id="goal_type" name="goal_type" data-goal-type>
                                    <option value="manual" selected>Manual (Parent approval)</option>
                                    <option value="routine_streak">Routine streak</option>
                                    <option value="routine_count">Routine count</option>
                                    <option value="task_quota">Task quota</option>
                                </select>
                            </div>
                            <div class="form-group" data-goal-routine>
                                <label for="goal_routine_id">Routine(s)
                                    <span class="tooltip" tabindex="0" aria-label="Required for routine goals. Select one or more routines for routine streak/count goals.">
                                        <i class="fa-solid fa-circle-info"></i>
                                        <span class="tooltip-text">Required for routine goals. Select one or more routines for routine streak/count goals.</span>
                                    </span>
                                </label>
                                <select id="goal_routine_id" name="routine_ids[]" data-goal-routine-select>
                                    <option value="">Select routine</option>
                                    <?php foreach ($routines as $routine): ?>
                                        <option value="<?php echo (int) $routine['id']; ?>" data-child-id="<?php echo (int) $routine['child_user_id']; ?>">
                                            <?php echo htmlspecialchars($routine['title']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group" data-goal-task>
                                <label for="goal_task_category">Task Category
                                    <span class="tooltip" tabindex="0" aria-label="Optional filter. Leave blank to count any category.">
                                        <i class="fa-solid fa-circle-info"></i>
                                        <span class="tooltip-text">Optional filter. Leave blank to count any category.</span>
                                    </span>
                                </label>
                                <select id="goal_task_category" name="task_category">
                                    <option value="">Any category</option>
                                    <option value="hygiene">Hygiene</option>
                                    <option value="homework">Homework</option>
                                    <option value="household">Household</option>
                                </select>
                            </div>
                            <div class="form-group" data-goal-task>
                                <label>Specific Tasks (optional)
                                    <span class="tooltip" tabindex="0" aria-label="Optional. If selected, only these tasks count; otherwise any matching tasks count.">
                                        <i class="fa-solid fa-circle-info"></i>
                                        <span class="tooltip-text">Optional. If selected, only these tasks count; otherwise any matching tasks count.</span>
                                    </span>
                                </label>
                                <div class="goal-task-grid" data-goal-task-grid>
                                    <?php if (!empty($goalTasks)): ?>
                                        <?php foreach ($goalTasks as $task): ?>
                                            <label class="goal-task-card" data-child-id="<?php echo (int) $task['child_user_id']; ?>">
                                                <span>
                                                    <input type="checkbox" name="task_target_ids[]" value="<?php echo (int) $task['id']; ?>">
                                                    <?php echo htmlspecialchars($task['title']); ?>
                                                </span>
                                                <span class="goal-detail-pill"><?php echo htmlspecialchars($task['category'] ?? 'general'); ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <p>No tasks available.</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="form-group" data-goal-count>
                                <label for="target_count">Target Count
                                    <span class="tooltip" tabindex="0" aria-label="How many completions are needed.">
                                        <i class="fa-solid fa-circle-info"></i>
                                        <span class="tooltip-text">How many completions are needed.</span>
                                    </span>
                                </label>
                                <input type="number" id="target_count" name="target_count" min="1" value="3">
                            </div>
                            <div class="form-group" data-goal-streak>
                                <label for="streak_required">Streak Length (days)
                                    <span class="tooltip" tabindex="0" aria-label="Consecutive days required.">
                                        <i class="fa-solid fa-circle-info"></i>
                                        <span class="tooltip-text">Consecutive days required.</span>
                                    </span>
                                </label>
                                <input type="number" id="streak_required" name="streak_required" min="2" value="3">
                            </div>
                            <div class="form-group" data-goal-window>
                                <label for="time_window_type">Time Window
                                    <span class="tooltip" tabindex="0" aria-label="Rolling counts the last X days. Fixed uses the start/end dates below.">
                                        <i class="fa-solid fa-circle-info"></i>
                                        <span class="tooltip-text">Rolling counts the last X days. Fixed uses the start/end dates below.</span>
                                    </span>
                                </label>
                                <select id="time_window_type" name="time_window_type" data-goal-window-type>
                                    <option value="rolling" selected>Rolling window</option>
                                    <option value="fixed">Fixed dates</option>
                                </select>
                            </div>
                            <div class="form-group" data-goal-window-rolling>
                                <label for="time_window_days">Rolling Days
                                    <span class="tooltip" tabindex="0" aria-label="Size of the rolling window in days.">
                                        <i class="fa-solid fa-circle-info"></i>
                                        <span class="tooltip-text">Size of the rolling window in days.</span>
                                    </span>
                                </label>
                                <input type="number" id="time_window_days" name="time_window_days" min="1" value="7">
                            </div>
                            <div class="form-group" data-goal-window-fixed>
                                <label for="fixed_window_start">Fixed Start Date
                                    <span class="tooltip" tabindex="0" aria-label="Start of the fixed counting window.">
                                        <i class="fa-solid fa-circle-info"></i>
                                        <span class="tooltip-text">Start of the fixed counting window.</span>
                                    </span>
                                </label>
                                <input type="date" id="fixed_window_start" name="fixed_window_start">
                            </div>
                            <div class="form-group" data-goal-window-fixed>
                                <label for="fixed_window_end">Fixed End Date
                                    <span class="tooltip" tabindex="0" aria-label="End of the fixed counting window.">
                                        <i class="fa-solid fa-circle-info"></i>
                                        <span class="tooltip-text">End of the fixed counting window.</span>
                                    </span>
                                </label>
                                <input type="date" id="fixed_window_end" name="fixed_window_end">
                            </div>
                            <div class="form-group toggle-field" data-goal-toggle>
                                <span class="toggle-label">Require On-Time Completion
                                    <span class="tooltip" tabindex="0" aria-label="Only count items finished within their time limits.">
                                        <i class="fa-solid fa-circle-info"></i>
                                        <span class="tooltip-text">Only count items finished within their time limits.</span>
                                    </span>
                                </span>
                                <label class="toggle-row">
                                    <span class="toggle-switch">
                                        <input type="checkbox" name="require_on_time" value="1">
                                        <span class="toggle-slider"></span>
                                    </span>
                                </label>
                            </div>
                            <div class="form-group">
                                <label for="points_awarded">Points Awarded
                                    <span class="tooltip" tabindex="0" aria-label="Points added when the goal is completed.">
                                        <i class="fa-solid fa-circle-info"></i>
                                        <span class="tooltip-text">Points added when the goal is completed.</span>
                                    </span>
                                </label>
                                <input type="number" id="points_awarded" name="points_awarded" min="0" value="0" data-goal-points>
                            </div>
                            <div class="form-group">
                                <label for="award_mode">Award Mode
                                    <span class="tooltip" tabindex="0" aria-label="Choose points, reward, or both. Required fields change.">
                                        <i class="fa-solid fa-circle-info"></i>
                                        <span class="tooltip-text">Choose points, reward, or both. Required fields change.</span>
                                    </span>
                                </label>
                                <select id="award_mode" name="award_mode" data-award-mode>
                                    <option value="both" selected>Points + Reward</option>
                                    <option value="points">Points only</option>
                                    <option value="reward">Reward only</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="goal_reward_id">Reward (optional)
                                    <span class="tooltip tooltip-right" tabindex="0" aria-label="Reward to grant on completion. Required if reward is part of the award mode.">
                                        <i class="fa-solid fa-circle-info"></i>
                                        <span class="tooltip-text">Reward to grant on completion. Required if reward is part of the award mode.</span>
                                    </span>
                                </label>
                                <select id="goal_reward_id" name="reward_id" data-goal-reward>
                                    <option value="">None</option>
                                    <?php foreach ($goalRewardTemplates as $template): ?>
                                        <option value="template:<?php echo (int) $template['id']; ?>">
                                            <?php echo htmlspecialchars($template['title']); ?> (<?php echo (int) $template['point_cost']; ?> pts)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group toggle-field">
                                <span class="toggle-label">Parent Approval Required
                                    <span class="tooltip" tabindex="0" aria-label="If on, the goal goes to pending approval even when progress is met.">
                                        <i class="fa-solid fa-circle-info"></i>
                                        <span class="tooltip-text">If on, the goal goes to pending approval even when progress is met.</span>
                                    </span>
                                </span>
                                <label class="toggle-row">
                                    <span class="toggle-switch">
                                        <input type="checkbox" name="requires_parent_approval" value="1" checked>
                                        <span class="toggle-slider"></span>
                                    </span>
                                </label>
                            </div>
                        </div>
                        <div class="form-actions">
                            <button type="submit" name="create_goal" class="button">Create Goal</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <div class="goal-edit-modal" data-goal-edit-modal>
            <div class="goal-edit-card" role="dialog" aria-modal="true" aria-labelledby="goal-edit-title">
                <header>
                    <h2 id="goal-edit-title">Edit Goal</h2>
                    <button type="button" class="task-modal-close" aria-label="Close edit goal" data-goal-edit-close>&times;</button>
                </header>
                <div class="goal-create-body">
                    <form method="POST" action="goal.php" data-goal-edit-form>
                        <input type="hidden" name="goal_id" value="">
                        <input type="hidden" name="reactivate_on_save" value="0" data-goal-reactivate-flag>
                        <div class="form-error" data-goal-edit-error style="display:none;"></div>
                        <?php
                        $children = $goalFormData['children'] ?? [];
                        $autoSelectChildId = count($children) === 1 ? (int) $children[0]['child_user_id'] : null;
                        $routines = $goalFormData['routines'] ?? [];
                        $goalTasks = $goalFormData['tasks'] ?? [];
                        $goalRewardTemplates = $goalFormData['reward_templates'] ?? [];
                        ?>
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Child
                                    <span class="tooltip tooltip-left" tabindex="0" aria-label="Pick the child this goal is for. This filters routines and tasks.">
                                        <i class="fa-solid fa-circle-info"></i>
                                        <span class="tooltip-text">Pick the child this goal is for. This filters routines and tasks.</span>
                                    </span>
                                </label>
                                <div class="child-select-grid">
                                    <?php if (!empty($children)): ?>
                                        <?php foreach ($children as $child):
                                            $avatar = !empty($child['avatar']) ? $child['avatar'] : 'images/default-avatar.png';
                                            ?>
                                            <label class="child-select-card">
                                                <input type="radio" name="child_user_id" value="<?php echo (int) $child['child_user_id']; ?>"<?php echo $autoSelectChildId === (int) $child['child_user_id'] ? ' checked' : ''; ?> required>
                                                <img src="<?php echo htmlspecialchars($avatar); ?>" alt="<?php echo htmlspecialchars($child['child_name']); ?>">
                                                <span><?php echo htmlspecialchars($child['child_name']); ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <p>No children found.</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="edit_goal_title">Title
                                    <span class="tooltip tooltip-left" tabindex="0" aria-label="Short name shown on goal cards and notifications.">
                                        <i class="fa-solid fa-circle-info"></i>
                                        <span class="tooltip-text">Short name shown on goal cards and notifications.</span>
                                    </span>
                                </label>
                                <input type="text" id="edit_goal_title" name="title" required>
                            </div>
                            <div class="form-group full-span">
                                <label for="edit_goal_description">Description
                                    <span class="tooltip tooltip-left" tabindex="0" aria-label="Optional details or requirements shown on goal cards and the child dashboard.">
                                        <i class="fa-solid fa-circle-info"></i>
                                        <span class="tooltip-text">Optional details or requirements shown on goal cards and the child dashboard.</span>
                                    </span>
                                </label>
                                <textarea id="edit_goal_description" name="description" rows="3" placeholder="Optional details about the goal"></textarea>
                            </div>
                            <div class="form-group">
                                <label for="edit_goal_start_date">Start Date/Time
                                    <span class="tooltip" tabindex="0" aria-label="When the goal becomes active. Progress before this does not count.">
                                        <i class="fa-solid fa-circle-info"></i>
                                        <span class="tooltip-text">When the goal becomes active. Progress before this does not count.</span>
                                    </span>
                                </label>
                                <input type="datetime-local" id="edit_goal_start_date" name="start_date" required>
                            </div>
                            <div class="form-group">
                                <label for="edit_goal_end_date">End Date/Time
                                    <span class="tooltip" tabindex="0" aria-label="Deadline. Progress after this does not count.">
                                        <i class="fa-solid fa-circle-info"></i>
                                        <span class="tooltip-text">Deadline. Progress after this does not count.</span>
                                    </span>
                                </label>
                                <input type="datetime-local" id="edit_goal_end_date" name="end_date" required>
                            </div>
                            <div class="form-group">
                                <label for="edit_goal_type">Goal Type
                                    <span class="tooltip tooltip-left" tabindex="0" aria-label="Choose how progress is tracked and which fields are required.">
                                        <i class="fa-solid fa-circle-info"></i>
                                        <span class="tooltip-text">Choose how progress is tracked and which fields are required.</span>
                                    </span>
                                </label>
                                <select id="edit_goal_type" name="goal_type" data-goal-type>
                                    <option value="manual">Manual (Parent approval)</option>
                                    <option value="routine_streak">Routine streak</option>
                                    <option value="routine_count">Routine count</option>
                                    <option value="task_quota">Task quota</option>
                                </select>
                            </div>
                            <div class="form-group" data-goal-routine>
                                <label for="edit_goal_routine_id">Routine(s)
                                    <span class="tooltip" tabindex="0" aria-label="Required for routine goals. Select one or more routines for routine streak/count goals.">
                                        <i class="fa-solid fa-circle-info"></i>
                                        <span class="tooltip-text">Required for routine goals. Select one or more routines for routine streak/count goals.</span>
                                    </span>
                                </label>
                                <select id="edit_goal_routine_id" name="routine_ids[]" data-goal-routine-select>
                                    <option value="">Select routine</option>
                                    <?php foreach ($routines as $routine): ?>
                                        <option value="<?php echo (int) $routine['id']; ?>" data-child-id="<?php echo (int) $routine['child_user_id']; ?>">
                                            <?php echo htmlspecialchars($routine['title']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group" data-goal-task>
                                <label for="edit_goal_task_category">Task Category
                                    <span class="tooltip" tabindex="0" aria-label="Optional filter. Leave blank to count any category.">
                                        <i class="fa-solid fa-circle-info"></i>
                                        <span class="tooltip-text">Optional filter. Leave blank to count any category.</span>
                                    </span>
                                </label>
                                <select id="edit_goal_task_category" name="task_category">
                                    <option value="">Any category</option>
                                    <option value="hygiene">Hygiene</option>
                                    <option value="homework">Homework</option>
                                    <option value="household">Household</option>
                                </select>
                            </div>
                            <div class="form-group" data-goal-task>
                                <label>Specific Tasks (optional)
                                    <span class="tooltip" tabindex="0" aria-label="Optional. If selected, only these tasks count; otherwise any matching tasks count.">
                                        <i class="fa-solid fa-circle-info"></i>
                                        <span class="tooltip-text">Optional. If selected, only these tasks count; otherwise any matching tasks count.</span>
                                    </span>
                                </label>
                                <div class="goal-task-grid" data-goal-task-grid>
                                    <?php if (!empty($goalTasks)): ?>
                                        <?php foreach ($goalTasks as $task): ?>
                                            <label class="goal-task-card" data-child-id="<?php echo (int) $task['child_user_id']; ?>">
                                                <span>
                                                    <input type="checkbox" name="task_target_ids[]" value="<?php echo (int) $task['id']; ?>">
                                                    <?php echo htmlspecialchars($task['title']); ?>
                                                </span>
                                                <span class="goal-detail-pill"><?php echo htmlspecialchars($task['category'] ?? 'general'); ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <p>No tasks available.</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="form-group" data-goal-count>
                                <label for="edit_target_count">Target Count
                                    <span class="tooltip" tabindex="0" aria-label="How many completions are needed.">
                                        <i class="fa-solid fa-circle-info"></i>
                                        <span class="tooltip-text">How many completions are needed.</span>
                                    </span>
                                </label>
                                <input type="number" id="edit_target_count" name="target_count" min="1" value="3">
                            </div>
                            <div class="form-group" data-goal-streak>
                                <label for="edit_streak_required">Streak Length (days)
                                    <span class="tooltip" tabindex="0" aria-label="Consecutive days required.">
                                        <i class="fa-solid fa-circle-info"></i>
                                        <span class="tooltip-text">Consecutive days required.</span>
                                    </span>
                                </label>
                                <input type="number" id="edit_streak_required" name="streak_required" min="2" value="3">
                            </div>
                            <div class="form-group" data-goal-window>
                                <label for="edit_time_window_type">Time Window
                                    <span class="tooltip" tabindex="0" aria-label="Rolling counts the last X days. Fixed uses the start/end dates below.">
                                        <i class="fa-solid fa-circle-info"></i>
                                        <span class="tooltip-text">Rolling counts the last X days. Fixed uses the start/end dates below.</span>
                                    </span>
                                </label>
                                <select id="edit_time_window_type" name="time_window_type" data-goal-window-type>
                                    <option value="rolling">Rolling window</option>
                                    <option value="fixed">Fixed dates</option>
                                </select>
                            </div>
                            <div class="form-group" data-goal-window-rolling>
                                <label for="edit_time_window_days">Rolling Days
                                    <span class="tooltip" tabindex="0" aria-label="Size of the rolling window in days.">
                                        <i class="fa-solid fa-circle-info"></i>
                                        <span class="tooltip-text">Size of the rolling window in days.</span>
                                    </span>
                                </label>
                                <input type="number" id="edit_time_window_days" name="time_window_days" min="1" value="7">
                            </div>
                            <div class="form-group" data-goal-window-fixed>
                                <label for="edit_fixed_window_start">Fixed Start Date
                                    <span class="tooltip" tabindex="0" aria-label="Start of the fixed counting window.">
                                        <i class="fa-solid fa-circle-info"></i>
                                        <span class="tooltip-text">Start of the fixed counting window.</span>
                                    </span>
                                </label>
                                <input type="date" id="edit_fixed_window_start" name="fixed_window_start">
                            </div>
                            <div class="form-group" data-goal-window-fixed>
                                <label for="edit_fixed_window_end">Fixed End Date
                                    <span class="tooltip" tabindex="0" aria-label="End of the fixed counting window.">
                                        <i class="fa-solid fa-circle-info"></i>
                                        <span class="tooltip-text">End of the fixed counting window.</span>
                                    </span>
                                </label>
                                <input type="date" id="edit_fixed_window_end" name="fixed_window_end">
                            </div>
                            <div class="form-group toggle-field" data-goal-toggle>
                                <span class="toggle-label">Require On-Time Completion
                                    <span class="tooltip" tabindex="0" aria-label="Only count items finished within their time limits.">
                                        <i class="fa-solid fa-circle-info"></i>
                                        <span class="tooltip-text">Only count items finished within their time limits.</span>
                                    </span>
                                </span>
                                <label class="toggle-row">
                                    <span class="toggle-switch">
                                        <input type="checkbox" name="require_on_time" value="1">
                                        <span class="toggle-slider"></span>
                                    </span>
                                </label>
                            </div>
                            <div class="form-group">
                                <label for="edit_points_awarded">Points Awarded
                                    <span class="tooltip" tabindex="0" aria-label="Points added when the goal is completed.">
                                        <i class="fa-solid fa-circle-info"></i>
                                        <span class="tooltip-text">Points added when the goal is completed.</span>
                                    </span>
                                </label>
                                <input type="number" id="edit_points_awarded" name="points_awarded" min="0" value="0" data-goal-points>
                            </div>
                            <div class="form-group">
                                <label for="edit_award_mode">Award Mode
                                    <span class="tooltip" tabindex="0" aria-label="Choose points, reward, or both. Required fields change.">
                                        <i class="fa-solid fa-circle-info"></i>
                                        <span class="tooltip-text">Choose points, reward, or both. Required fields change.</span>
                                    </span>
                                </label>
                                <select id="edit_award_mode" name="award_mode" data-award-mode>
                                    <option value="both">Points + Reward</option>
                                    <option value="points">Points only</option>
                                    <option value="reward">Reward only</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="edit_goal_reward_id">Reward (optional)
                                    <span class="tooltip tooltip-right" tabindex="0" aria-label="Reward to grant on completion. Required if reward is part of the award mode.">
                                        <i class="fa-solid fa-circle-info"></i>
                                        <span class="tooltip-text">Reward to grant on completion. Required if reward is part of the award mode.</span>
                                    </span>
                                </label>
                                <select id="edit_goal_reward_id" name="reward_id" data-goal-reward>
                                    <option value="">None</option>
                                    <?php foreach ($goalRewardTemplates as $template): ?>
                                        <option value="template:<?php echo (int) $template['id']; ?>">
                                            <?php echo htmlspecialchars($template['title']); ?> (<?php echo (int) $template['point_cost']; ?> pts)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group toggle-field">
                                <span class="toggle-label">Parent Approval Required
                                    <span class="tooltip" tabindex="0" aria-label="If on, the goal goes to pending approval even when progress is met.">
                                        <i class="fa-solid fa-circle-info"></i>
                                        <span class="tooltip-text">If on, the goal goes to pending approval even when progress is met.</span>
                                    </span>
                                </span>
                                <label class="toggle-row">
                                    <span class="toggle-switch">
                                        <input type="checkbox" name="requires_parent_approval" value="1" checked>
                                        <span class="toggle-slider"></span>
                                    </span>
                                </label>
                            </div>
                        </div>
                        <div class="form-actions">
                            <button type="submit" name="update_goal" class="button">Update Goal</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endif; ?>
    <?php if (is_array($celebrationGoals) && !empty($celebrationGoals)): ?>
        <?php foreach ($celebrationGoals as $celebrationGoal) {
            markGoalCelebrationShown((int) $celebrationGoal['id']);
        } ?>
        <div class="goal-celebration" data-goal-celebration>
            <div class="goal-celebration-card">
                <div class="goal-confetti" data-goal-confetti></div>
                <button type="button" class="goal-celebration-close" data-goal-celebration-close aria-label="Close celebration">
                    <i class="fa-solid fa-xmark"></i>
                </button>
                <div class="goal-celebration-icon"><i class="fa-solid fa-trophy"></i></div>
                <h3 class="goal-celebration-title">Goal Achieved!</h3>
                <p class="goal-celebration-goal" data-goal-celebration-title></p>
            </div>
        </div>
        <script>
            const celebrationQueue = <?php echo json_encode($celebrationGoals, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
        </script>
    <?php endif; ?>
    <nav class="nav-mobile-bottom" aria-label="Primary">
        <a class="nav-mobile-link<?php echo $dashboardActive ? ' is-active' : ''; ?>" href="<?php echo htmlspecialchars($dashboardPage); ?>"<?php echo $dashboardActive ? ' aria-current="page"' : ''; ?>>
            <i class="fa-solid fa-house"></i>
            <span>Dashboard</span>
        </a>
        <a class="nav-mobile-link<?php echo $routinesActive ? ' is-active' : ''; ?>" href="routine.php"<?php echo $routinesActive ? ' aria-current="page"' : ''; ?>>
            <i class="fa-solid fa-rotate"></i>
            <span>Routines</span>
        </a>
        <a class="nav-mobile-link<?php echo $tasksActive ? ' is-active' : ''; ?>" href="task.php"<?php echo $tasksActive ? ' aria-current="page"' : ''; ?>>
            <i class="fa-solid fa-list-check"></i>
            <span>Tasks</span>
        </a>
        <a class="nav-mobile-link<?php echo $goalsActive ? ' is-active' : ''; ?>" href="goal.php"<?php echo $goalsActive ? ' aria-current="page"' : ''; ?>>
            <i class="fa-solid fa-bullseye"></i>
            <span>Goals</span>
        </a>
        <a class="nav-mobile-link<?php echo $rewardsActive ? ' is-active' : ''; ?>" href="rewards.php"<?php echo $rewardsActive ? ' aria-current="page"' : ''; ?>>
            <i class="fa-solid fa-gift"></i>
            <span>Rewards</span>
        </a>
    </nav>
    <footer>
        <p>Child Task and Chore App - Ver 3.17.6</p>
    </footer>
  <script src="js/number-stepper.js" defer></script>
  <script>
      const goalCreateModal = document.querySelector('[data-goal-create-modal]');
      const goalCreateOpen = document.querySelector('[data-goal-create-open]');
      const goalCreateClose = goalCreateModal ? goalCreateModal.querySelector('[data-goal-create-close]') : null;
      const goalCreateForm = goalCreateModal ? goalCreateModal.querySelector('[data-goal-create-form]') : null;
      const goalCreateError = goalCreateModal ? goalCreateModal.querySelector('[data-goal-create-error]') : null;
      const goalEditModal = document.querySelector('[data-goal-edit-modal]');
      const goalEditClose = goalEditModal ? goalEditModal.querySelector('[data-goal-edit-close]') : null;
      const goalEditForm = goalEditModal ? goalEditModal.querySelector('[data-goal-edit-form]') : null;
      const goalEditError = goalEditModal ? goalEditModal.querySelector('[data-goal-edit-error]') : null;

      const setupGoalForm = (scope) => {
          if (!scope) return null;
          const goalTypeSelect = scope.querySelector('[data-goal-type]');
          const goalWindowSelect = scope.querySelector('[data-goal-window-type]');
          const awardModeSelect = scope.querySelector('[data-award-mode]');
          const rewardSelect = scope.querySelector('[data-goal-reward]');
          const pointsInput = scope.querySelector('[data-goal-points]');
          const childInputs = scope.querySelectorAll('input[name="child_user_id"]');
          const routineSelect = scope.querySelector('[data-goal-routine-select]');
          const taskGrid = scope.querySelector('[data-goal-task-grid]');

          const setStepperDisabled = (disabled) => {
              if (!pointsInput) return;
              const stepper = pointsInput.closest('.number-stepper');
              if (!stepper) return;
              stepper.querySelectorAll('.stepper-btn').forEach(btn => {
                  btn.disabled = disabled;
              });
          };

          const setGoalTypeVisibility = () => {
              const type = goalTypeSelect ? goalTypeSelect.value : 'manual';
              const showRoutine = type === 'routine_streak' || type === 'routine_count';
              const showTask = type === 'task_quota';
              const showCount = type === 'routine_count' || type === 'task_quota';
              const showStreak = type === 'routine_streak';
              const showWindow = type === 'routine_count' || type === 'task_quota';
              const allowMultiple = type === 'routine_streak' || type === 'routine_count';
              if (routineSelect) {
                  routineSelect.multiple = allowMultiple;
                  if (!allowMultiple) {
                      const selected = Array.from(routineSelect.selectedOptions).filter(opt => opt.value !== '');
                      if (selected.length > 1) {
                          routineSelect.value = selected[0].value;
                          selected.slice(1).forEach(opt => {
                              opt.selected = false;
                          });
                      }
                  }
              }
              scope.querySelectorAll('[data-goal-routine]').forEach(el => el.style.display = showRoutine ? 'grid' : 'none');
              scope.querySelectorAll('[data-goal-task]').forEach(el => el.style.display = showTask ? 'grid' : 'none');
              scope.querySelectorAll('[data-goal-count]').forEach(el => el.style.display = showCount ? 'grid' : 'none');
              scope.querySelectorAll('[data-goal-streak]').forEach(el => el.style.display = showStreak ? 'grid' : 'none');
              scope.querySelectorAll('[data-goal-window]').forEach(el => el.style.display = showWindow ? 'grid' : 'none');
              scope.querySelectorAll('[data-goal-window-rolling]').forEach(el => el.style.display = showWindow && goalWindowSelect?.value !== 'fixed' ? 'grid' : 'none');
              scope.querySelectorAll('[data-goal-window-fixed]').forEach(el => el.style.display = showWindow && goalWindowSelect?.value === 'fixed' ? 'grid' : 'none');
              scope.querySelectorAll('[data-goal-toggle]').forEach(el => el.style.display = (showRoutine || showTask) ? 'flex' : 'none');
          };

          const setAwardModeVisibility = () => {
              const mode = awardModeSelect ? awardModeSelect.value : 'both';
              if (rewardSelect) {
                  rewardSelect.disabled = mode === 'points';
              }
              if (pointsInput) {
                  const lockPoints = mode === 'points';
                  pointsInput.readOnly = lockPoints;
                  pointsInput.disabled = mode === 'reward';
                  setStepperDisabled(lockPoints || pointsInput.disabled);
              }
          };

          const filterByChild = () => {
              const checked = Array.from(childInputs).find(input => input.checked);
              const childId = checked ? checked.value : '';
              if (routineSelect) {
                  const options = routineSelect.querySelectorAll('option[data-child-id]');
                  options.forEach(opt => {
                      const hidden = childId !== '' && opt.getAttribute('data-child-id') !== childId;
                      opt.hidden = hidden;
                      if (hidden && opt.selected) {
                          opt.selected = false;
                      }
                  });
                  if (!routineSelect.multiple) {
                      if (routineSelect.value && routineSelect.querySelector(`option[value="${routineSelect.value}"]`)?.hidden) {
                          routineSelect.value = '';
                      }
                  }
              }
              if (taskGrid) {
                  taskGrid.querySelectorAll('[data-child-id]').forEach(card => {
                      const matches = childId === '' || card.getAttribute('data-child-id') === childId;
                      card.style.display = matches ? 'grid' : 'none';
                      if (!matches) {
                          const box = card.querySelector('input[type="checkbox"]');
                          if (box) box.checked = false;
                      }
                  });
              }
          };

          if (goalTypeSelect) {
              goalTypeSelect.addEventListener('change', setGoalTypeVisibility);
          }
          if (goalWindowSelect) {
              goalWindowSelect.addEventListener('change', setGoalTypeVisibility);
          }
          if (awardModeSelect) {
              awardModeSelect.addEventListener('change', setAwardModeVisibility);
          }
          if (childInputs.length) {
              childInputs.forEach(input => input.addEventListener('change', filterByChild));
          }
          setGoalTypeVisibility();
          setAwardModeVisibility();
          filterByChild();

          return {
              setGoalTypeVisibility,
              setAwardModeVisibility,
              filterByChild
          };
      };

      const createFormApi = setupGoalForm(goalCreateModal);
      const editFormApi = setupGoalForm(goalEditModal);

      document.addEventListener('DOMContentLoaded', () => {
          createFormApi?.setAwardModeVisibility();
          editFormApi?.setAwardModeVisibility();
      });

      const closeGoalCreate = () => {
          if (!goalCreateModal) return;
          goalCreateModal.classList.remove('open');
          document.body.classList.remove('no-scroll');
      };
      const openGoalCreate = () => {
          if (!goalCreateModal) return;
          goalCreateModal.classList.add('open');
          document.body.classList.add('no-scroll');
      };
      if (goalCreateModal && goalCreateOpen) {
          goalCreateOpen.addEventListener('click', openGoalCreate);
          if (goalCreateClose) {
              goalCreateClose.addEventListener('click', closeGoalCreate);
          }
          goalCreateModal.addEventListener('click', (event) => {
              if (event.target === goalCreateModal) {
                  closeGoalCreate();
              }
          });
      }

      const closeGoalEdit = () => {
          if (!goalEditModal) return;
          goalEditModal.classList.remove('open');
          document.body.classList.remove('no-scroll');
      };
      const openGoalEdit = (payload, reactivate = false) => {
          if (!goalEditModal || !goalEditForm) return;
          goalEditForm.reset();
          if (goalEditError) {
              goalEditError.textContent = '';
              goalEditError.style.display = 'none';
          }
          goalEditForm.querySelectorAll('.input-error').forEach(el => el.classList.remove('input-error'));
          goalEditForm.querySelectorAll('.child-select-grid').forEach(el => el.classList.remove('input-error'));
          const reactivateField = goalEditForm.querySelector('[data-goal-reactivate-flag]');
          if (reactivateField) {
              reactivateField.value = reactivate ? '1' : '0';
          }

          goalEditForm.querySelector('[name="goal_id"]').value = payload.id || '';
          goalEditForm.querySelector('[name="title"]').value = payload.title || '';
          goalEditForm.querySelector('[name="description"]').value = payload.description || '';
          goalEditForm.querySelector('[name="start_date"]').value = payload.start_date || '';
          goalEditForm.querySelector('[name="end_date"]').value = payload.end_date || '';
          goalEditForm.querySelector('[name="goal_type"]').value = payload.goal_type || 'manual';
          goalEditForm.querySelector('[name="task_category"]').value = payload.task_category || '';
          goalEditForm.querySelector('[name="target_count"]').value = payload.target_count || 0;
          goalEditForm.querySelector('[name="streak_required"]').value = payload.streak_required || 0;
          goalEditForm.querySelector('[name="time_window_type"]').value = payload.time_window_type || 'rolling';
          goalEditForm.querySelector('[name="time_window_days"]').value = payload.time_window_days || 0;
          goalEditForm.querySelector('[name="fixed_window_start"]').value = payload.fixed_window_start || '';
          goalEditForm.querySelector('[name="fixed_window_end"]').value = payload.fixed_window_end || '';
          goalEditForm.querySelector('[name="require_on_time"]').checked = !!payload.require_on_time;
          goalEditForm.querySelector('[name="points_awarded"]').value = payload.points_awarded || 0;
          goalEditForm.querySelector('[name="award_mode"]').value = payload.award_mode || 'both';
          goalEditForm.querySelector('[name="reward_id"]').value = payload.reward_id || '';
          goalEditForm.querySelector('[name="requires_parent_approval"]').checked = payload.requires_parent_approval !== 0;

          const childInputs = goalEditForm.querySelectorAll('input[name="child_user_id"]');
          childInputs.forEach(input => {
              input.checked = String(input.value) === String(payload.child_user_id || '');
          });
          if (!Array.from(childInputs).some(input => input.checked)) {
              const firstChild = childInputs[0];
              if (firstChild) firstChild.checked = true;
          }

          const targetIds = Array.isArray(payload.task_target_ids) ? payload.task_target_ids.map(String) : [];
          goalEditForm.querySelectorAll('input[name="task_target_ids[]"]').forEach(box => {
              box.checked = targetIds.includes(String(box.value));
          });

          if (editFormApi) {
              editFormApi.setGoalTypeVisibility();
              editFormApi.setAwardModeVisibility();
          }

          const routineSelect = goalEditForm.querySelector('[data-goal-routine-select]');
          const routineIds = Array.isArray(payload.routine_ids) ? payload.routine_ids.map(String) : [];
          if (!routineIds.length && payload.routine_id) {
              routineIds.push(String(payload.routine_id));
          }
          if (routineSelect) {
              Array.from(routineSelect.options).forEach(option => {
                  option.selected = routineIds.includes(option.value);
              });
          }

          if (editFormApi) {
              editFormApi.filterByChild();
          }
          goalEditModal.classList.add('open');
          document.body.classList.add('no-scroll');
      };

      if (goalEditModal) {
          if (goalEditClose) {
              goalEditClose.addEventListener('click', closeGoalEdit);
          }
          goalEditModal.addEventListener('click', (event) => {
              if (event.target === goalEditModal) {
                  closeGoalEdit();
              }
          });
      }

      document.querySelectorAll('[data-goal-edit-open]').forEach(button => {
          button.addEventListener('click', () => {
              let payload = {};
              try {
                  payload = JSON.parse(button.dataset.goalPayload || '{}');
              } catch (err) {
                  payload = {};
              }
              const reactivate = button.dataset.goalReactivate === '1';
              openGoalEdit(payload, reactivate);
          });
      });

      document.addEventListener('keydown', (event) => {
          if (event.key === 'Escape') {
              closeGoalCreate();
              closeGoalEdit();
              document.querySelectorAll('.tooltip.open').forEach(el => el.classList.remove('open'));
          }
      });

      document.querySelectorAll('.tooltip').forEach(tooltip => {
          tooltip.addEventListener('click', (event) => {
              event.stopPropagation();
              const isOpen = tooltip.classList.contains('open');
              document.querySelectorAll('.tooltip.open').forEach(el => {
                  if (el !== tooltip) el.classList.remove('open');
              });
              tooltip.classList.toggle('open', !isOpen);
          });
      });

      document.addEventListener('click', () => {
          document.querySelectorAll('.tooltip.open').forEach(el => el.classList.remove('open'));
      });

      const validateGoalForm = (scope, errorEl) => {
          if (!scope) return true;
          const missing = [];
          const childGrid = scope.querySelector('.child-select-grid');
          const childChecked = scope.querySelector('input[name="child_user_id"]:checked');
          if (childGrid) childGrid.classList.remove('input-error');
          scope.querySelectorAll('.input-error').forEach(el => el.classList.remove('input-error'));

          const titleInput = scope.querySelector('[name="title"]');
          const startInput = scope.querySelector('[name="start_date"]');
          const endInput = scope.querySelector('[name="end_date"]');
          const goalType = scope.querySelector('[name="goal_type"]')?.value || 'manual';
          const routineSelect = scope.querySelector('[data-goal-routine-select]');
          const targetCountInput = scope.querySelector('[name="target_count"]');
          const streakInput = scope.querySelector('[name="streak_required"]');
          const windowTypeSelect = scope.querySelector('[name="time_window_type"]');
          const windowDaysInput = scope.querySelector('[name="time_window_days"]');
          const fixedStartInput = scope.querySelector('[name="fixed_window_start"]');
          const fixedEndInput = scope.querySelector('[name="fixed_window_end"]');
          const awardMode = scope.querySelector('[name="award_mode"]')?.value || 'both';
          const rewardSelect = scope.querySelector('[name="reward_id"]');
          const pointsInput = scope.querySelector('[name="points_awarded"]');

          const markMissing = (el, label) => {
              missing.push(label);
              if (el) el.classList.add('input-error');
          };

          if (!childChecked) {
              missing.push('Child');
              if (childGrid) childGrid.classList.add('input-error');
          }
          if (!titleInput?.value.trim()) markMissing(titleInput, 'Title');
          if (!startInput?.value) markMissing(startInput, 'Start date');
          if (!endInput?.value) markMissing(endInput, 'End date');

          if (goalType === 'routine_streak' || goalType === 'routine_count') {
              const routineSelected = routineSelect ? Array.from(routineSelect.selectedOptions).some(opt => opt.value !== '') : false;
              if (!routineSelected) markMissing(routineSelect, 'Routine');
          }
          if (goalType === 'routine_streak') {
              if (!streakInput?.value || parseInt(streakInput.value, 10) <= 0) {
                  markMissing(streakInput, 'Streak length');
              }
          }
          if (goalType === 'routine_count' || goalType === 'task_quota') {
              if (!targetCountInput?.value || parseInt(targetCountInput.value, 10) <= 0) {
                  markMissing(targetCountInput, 'Target count');
              }
              const windowType = windowTypeSelect?.value || 'rolling';
              if (windowType === 'fixed') {
                  if (!fixedStartInput?.value) markMissing(fixedStartInput, 'Fixed start date');
                  if (!fixedEndInput?.value) markMissing(fixedEndInput, 'Fixed end date');
              } else if (!windowDaysInput?.value || parseInt(windowDaysInput.value, 10) <= 0) {
                  markMissing(windowDaysInput, 'Rolling days');
              }
          }

          const pointsValue = pointsInput ? parseInt(pointsInput.value, 10) : 0;
          const rewardSelected = rewardSelect && rewardSelect.value !== '';
          if (awardMode === 'points') {
              if (!pointsValue || pointsValue <= 0) {
                  markMissing(pointsInput, 'Points awarded');
              }
          } else if (awardMode === 'reward') {
              if (!rewardSelected) {
                  markMissing(rewardSelect, 'Reward');
              }
          } else {
              if (!rewardSelected) {
                  markMissing(rewardSelect, 'Reward');
              }
              if (!pointsValue || pointsValue <= 0) {
                  markMissing(pointsInput, 'Points awarded');
              }
          }

          if (errorEl) {
              if (missing.length) {
                  errorEl.textContent = 'Please complete: ' + missing.join(', ');
                  errorEl.style.display = 'block';
              } else {
                  errorEl.textContent = '';
                  errorEl.style.display = 'none';
              }
          }
          return missing.length === 0;
      };

      if (goalEditForm) {
          goalEditForm.addEventListener('submit', (event) => {
              if (!validateGoalForm(goalEditForm, goalEditError)) {
                  event.preventDefault();
              }
          });
      }
      if (goalCreateForm) {
          goalCreateForm.addEventListener('submit', (event) => {
              if (!validateGoalForm(goalCreateForm, goalCreateError)) {
                  event.preventDefault();
              }
          });
      }

      if (typeof celebrationQueue !== 'undefined' && celebrationQueue.length) {
          const celebrationModal = document.querySelector('[data-goal-celebration]');
          const celebrationTitle = document.querySelector('[data-goal-celebration-title]');
          const confettiHost = document.querySelector('[data-goal-confetti]');
          const celebrationClose = document.querySelector('[data-goal-celebration-close]');
          const colors = ['#ff7043', '#ffd54f', '#4caf50', '#29b6f6', '#ab47bc'];

          const closeCelebration = () => {
              if (!celebrationModal) return;
              celebrationModal.classList.remove('active');
              setTimeout(showNextCelebration, 300);
          };

          const dropConfetti = () => {
              if (!confettiHost) return;
              confettiHost.innerHTML = '';
              for (let i = 0; i < 18; i += 1) {
                  const piece = document.createElement('span');
                  piece.style.left = `${Math.random() * 100}%`;
                  piece.style.background = colors[i % colors.length];
                  piece.style.animationDelay = `${Math.random() * 0.4}s`;
                  confettiHost.appendChild(piece);
              }
          };

          const showNextCelebration = () => {
              const next = celebrationQueue.shift();
              if (!next || !celebrationModal) return;
              if (celebrationTitle) {
                  celebrationTitle.textContent = next.title || 'Goal achieved!';
              }
              dropConfetti();
              celebrationModal.classList.add('active');
          };

          if (celebrationClose) {
              celebrationClose.addEventListener('click', closeCelebration);
          }
          showNextCelebration();
      }
  </script>
<?php if (!empty($isParentNotificationUser)): ?>
    <?php include __DIR__ . '/includes/notifications_parent.php'; ?>
<?php endif; ?>
<?php if (!empty($isChildNotificationUser)): ?>
    <?php include __DIR__ . '/includes/notifications_child.php'; ?>
<?php endif; ?>
</body>
</html>




