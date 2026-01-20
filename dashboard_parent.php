<?php
// dashboard_parent.php - Parent dashboard
// Purpose: Display parent dashboard with child overview and management links
// Inputs: Session data
// Outputs: Dashboard interface
// Version: 3.17.6 (Notifications moved to header-triggered modal, Font Awesome icons, routine/reward updates)

require_once __DIR__ . '/includes/functions.php';

session_start(); // Force session start to load existing session
error_log("Dashboard Parent: user_id=" . (isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 'null') . ", role=" . (isset($_SESSION['role']) ? $_SESSION['role'] : 'null') . ", session_id=" . session_id() . ", cookie=" . (isset($_SERVER['HTTP_COOKIE']) ? $_SERVER['HTTP_COOKIE'] : 'none'));
if (!isset($_SESSION['user_id']) || !canCreateContent($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
$currentPage = basename($_SERVER['PHP_SELF']);
// Set role_type for permission checks
$role_type = getEffectiveRole($_SESSION['user_id']);

// Compute the family context's main parent id for later queries
$main_parent_id = $_SESSION['user_id'];
if ($role_type !== 'main_parent') {
    $stmt = $db->prepare("SELECT main_parent_id FROM family_links WHERE linked_user_id = :linked_id LIMIT 1");
    $stmt->execute([':linked_id' => $_SESSION['user_id']]);
    $fetched_main_id = $stmt->fetchColumn();
    if ($fetched_main_id) {
        $main_parent_id = $fetched_main_id;
    }
}

if ($role_type === 'family_member') {
    $stmt = $db->prepare("SELECT role_type FROM family_links WHERE linked_user_id = :id");
    $stmt->execute([':id' => $_SESSION['user_id']]);
    $linked_role_type = $stmt->fetchColumn();
    if ($linked_role_type) {
        $role_type = $linked_role_type;
    }
}

// Ensure display name in session
if (!isset($_SESSION['name'])) {
    $_SESSION['name'] = getDisplayName($_SESSION['user_id']);
}
if (!isset($_SESSION['username'])) {
    $uStmt = $db->prepare("SELECT username FROM users WHERE id = :id");
    $uStmt->execute([':id' => $_SESSION['user_id']]);
    $_SESSION['username'] = $uStmt->fetchColumn() ?: 'Unknown';
}

$routine_overtime_logs = getRoutineOvertimeLogs($main_parent_id, 25);
$routine_overtime_stats = getRoutineOvertimeStats($main_parent_id);
$overtimeByChild = $routine_overtime_stats['by_child'] ?? [];
$overtimeByRoutine = $routine_overtime_stats['by_routine'] ?? [];
$overtimeLogGroups = [];
$overtimeLogsByRoutine = [];
        if (!empty($routine_overtime_logs) && is_array($routine_overtime_logs)) {
            foreach ($routine_overtime_logs as $log) {
                $timestamp = strtotime($log['occurred_at']);
                $dateKey = $timestamp ? date('Y-m-d', $timestamp) : 'unknown';
        $dateLabel = $timestamp ? date('l, M j, Y', $timestamp) : 'Unknown date';
        if (!isset($overtimeLogGroups[$dateKey])) {
            $overtimeLogGroups[$dateKey] = [
                'label' => $dateLabel,
                'count' => 0,
                'routines' => []
            ];
        }
        $routineId = (int) ($log['routine_id'] ?? 0);
        $routineKey = $routineId ?: md5($log['routine_title'] ?? 'Routine');
        if (!isset($overtimeLogGroups[$dateKey]['routines'][$routineKey])) {
            $overtimeLogGroups[$dateKey]['routines'][$routineKey] = [
                'title' => $log['routine_title'] ?? 'Routine',
                'entries' => []
            ];
        }
        $overtimeLogGroups[$dateKey]['routines'][$routineKey]['entries'][] = $log;
        $overtimeLogGroups[$dateKey]['count']++;

        if (!isset($overtimeLogsByRoutine[$routineKey])) {
            $overtimeLogsByRoutine[$routineKey] = [
                'title' => $log['routine_title'] ?? 'Routine',
                'entries' => []
            ];
        }
        $overtimeLogsByRoutine[$routineKey]['entries'][] = $log;
    }
}
$formatDuration = function($seconds) {
    $seconds = max(0, (int) $seconds);
    $minutes = intdiv($seconds, 60);
    $remaining = $seconds % 60;
    return sprintf('%02d:%02d', $minutes, $remaining);
};
$formatDurationOrDash = function($seconds) use ($formatDuration) {
    if ($seconds === null) {
        return '--:--';
    }
    $seconds = (int) $seconds;
    if ($seconds <= 0) {
        return '--:--';
    }
    return $formatDuration($seconds);
};
$routineCompletionSessions = [];
$routineCompletionTasks = [];
try {
    ensureRoutineCompletionTables();
    $completionStmt = $db->prepare("
        SELECT
            rcl.id,
            rcl.routine_id,
            rcl.child_user_id,
            rcl.completed_by,
            rcl.started_at,
            rcl.completed_at,
            r.title AS routine_title,
            COALESCE(
                NULLIF(TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))), ''),
                NULLIF(u.name, ''),
                u.username,
                'Unknown'
            ) AS child_display_name
        FROM routine_completion_logs rcl
        JOIN routines r ON rcl.routine_id = r.id
        LEFT JOIN users u ON rcl.child_user_id = u.id
        WHERE rcl.parent_user_id = :parent_id
        ORDER BY rcl.completed_at DESC
        LIMIT 15
    ");
    $completionStmt->execute([':parent_id' => $main_parent_id]);
    $routineCompletionSessions = $completionStmt->fetchAll(PDO::FETCH_ASSOC);
    $sessionIds = array_values(array_filter(array_map(static function ($row) {
        return (int) ($row['id'] ?? 0);
    }, $routineCompletionSessions)));
    if (!empty($sessionIds)) {
        $placeholders = implode(',', array_fill(0, count($sessionIds), '?'));
        $taskStmt = $db->prepare("
            SELECT
                rct.completion_log_id,
                rct.routine_task_id,
                rct.sequence_order,
                rct.completed_at,
                rct.status_screen_seconds,
                rct.scheduled_seconds,
                rct.actual_seconds,
                rt.title AS task_title,
                rt.time_limit AS task_time_limit
            FROM routine_completion_tasks rct
            LEFT JOIN routine_tasks rt ON rct.routine_task_id = rt.id
            WHERE rct.completion_log_id IN ($placeholders)
            ORDER BY rct.completion_log_id DESC, rct.sequence_order ASC, rct.id ASC
        ");
        $taskStmt->execute($sessionIds);
        foreach ($taskStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $logId = (int) ($row['completion_log_id'] ?? 0);
            if ($logId) {
                if (!isset($routineCompletionTasks[$logId])) {
                    $routineCompletionTasks[$logId] = [];
                }
                $routineCompletionTasks[$logId][] = $row;
            }
        }
    }
} catch (Exception $e) {
    error_log("Failed to load routine completion logs: " . $e->getMessage());
}

$welcome_role_label = getUserRoleLabel($_SESSION['user_id']);
if (!$welcome_role_label) {
    $fallback_role = $role_type ?: ($_SESSION['role'] ?? null);
    if ($fallback_role) {
        $welcome_role_label = ucfirst(str_replace('_', ' ', $fallback_role));
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['mark_parent_notifications_read'])) {
        $ids = array_map('intval', $_POST['parent_notification_ids'] ?? []);
        $ids = array_values(array_filter($ids));
        if (!empty($ids)) {
            ensureParentNotificationsTable();
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $params = $ids;
            $params[] = $main_parent_id;
            $stmt = $db->prepare("UPDATE parent_notifications SET is_read = 1, deleted_at = NULL WHERE id IN ($placeholders) AND parent_user_id = ?");
            $stmt->execute($params);
            $message = "Notifications marked as read.";
            $count = count($ids);
            $parentNotificationActionSummary = 'Marked ' . $count . ' notification' . ($count === 1 ? '' : 's') . ' as read.';
            $parentNotificationActionTab = 'read';
            $parentNotices = getParentNotifications($main_parent_id);
        }
    } elseif (isset($_POST['move_parent_notifications_trash']) || isset($_POST['trash_parent_single'])) {
        $ids = array_map('intval', $_POST['parent_notification_ids'] ?? []);
        if (isset($_POST['trash_parent_single'])) {
            $ids[] = (int) $_POST['trash_parent_single'];
        }
        $ids = array_values(array_filter($ids));
        if (!empty($ids)) {
            ensureParentNotificationsTable();
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $params = $ids;
            $params[] = $main_parent_id;
            $stmt = $db->prepare("UPDATE parent_notifications SET deleted_at = NOW() WHERE id IN ($placeholders) AND parent_user_id = ?");
            $stmt->execute($params);
            $message = "Notifications moved to trash.";
            $count = count($ids);
            $parentNotificationActionSummary = 'Moved ' . $count . ' notification' . ($count === 1 ? '' : 's') . ' to Trash.';
            $parentNotificationActionTab = 'deleted';
            $parentNotices = getParentNotifications($main_parent_id);
        }
    } elseif (isset($_POST['delete_parent_notifications_perm']) || isset($_POST['delete_parent_single_perm'])) {
        $ids = array_map('intval', $_POST['parent_notification_ids'] ?? []);
        if (isset($_POST['delete_parent_single_perm'])) {
            $ids[] = (int) $_POST['delete_parent_single_perm'];
        }
        $ids = array_values(array_filter($ids));
        if (!empty($ids)) {
            ensureParentNotificationsTable();
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $params = $ids;
            $params[] = $main_parent_id;
            $stmt = $db->prepare("DELETE FROM parent_notifications WHERE id IN ($placeholders) AND parent_user_id = ?");
            $stmt->execute($params);
            $message = "Notifications deleted.";
            $count = count($ids);
            $parentNotificationActionSummary = 'Deleted ' . $count . ' notification' . ($count === 1 ? '' : 's') . '.';
            $parentNotificationActionTab = 'deleted';
        }
    } elseif (isset($_POST['approve_task_notification'])) {
        $task_id = isset($_POST['approve_task_notification']) ? (int) $_POST['approve_task_notification'] : 0;
        $parent_notification_id = null;
        $instance_date = null;
        if ($task_id) {
            $instanceMap = $_POST['instance_date_map'] ?? [];
            $parentMap = $_POST['parent_notification_map'] ?? [];
            if (!empty($instanceMap[$task_id])) {
                $instance_date = filter_var($instanceMap[$task_id], FILTER_SANITIZE_STRING);
            }
            if (!empty($parentMap[$task_id])) {
                $parent_notification_id = (int) $parentMap[$task_id];
            }
        }
        if ($task_id) {
            $taskStmt = $db->prepare("SELECT parent_user_id, status, recurrence FROM tasks WHERE id = :id LIMIT 1");
            $taskStmt->execute([':id' => $task_id]);
            $taskRow = $taskStmt->fetch(PDO::FETCH_ASSOC);
            if ($taskRow && (int) $taskRow['parent_user_id'] === (int) $main_parent_id) {
                $taskIsRecurring = !empty($taskRow['recurrence']);
                $instanceStatus = null;
                $instanceDateToUse = $instance_date ?: null;
                if ($taskIsRecurring) {
                    if ($instanceDateToUse) {
                        $instStmt = $db->prepare("SELECT status FROM task_instances WHERE task_id = :id AND date_key = :date_key LIMIT 1");
                        $instStmt->execute([':id' => $task_id, ':date_key' => $instanceDateToUse]);
                        $instanceStatus = $instStmt->fetchColumn();
                    } else {
                        $instStmt = $db->prepare("SELECT date_key, status FROM task_instances WHERE task_id = :id AND status = 'completed' ORDER BY completed_at DESC LIMIT 1");
                        $instStmt->execute([':id' => $task_id]);
                        $instRow = $instStmt->fetch(PDO::FETCH_ASSOC);
                        $instanceStatus = $instRow['status'] ?? null;
                        $instanceDateToUse = $instRow['date_key'] ?? null;
                    }
                }
                $canApprove = $taskIsRecurring ? ($instanceStatus === 'completed') : (($taskRow['status'] ?? '') === 'completed');
                if ($canApprove) {
                    if (approveTask($task_id, $instanceDateToUse)) {
                        $message = "Task approved!";
                        if ($parent_notification_id) {
                            ensureParentNotificationsTable();
                            $mark = $db->prepare("UPDATE parent_notifications SET is_read = 1 WHERE id = :id AND parent_user_id = :pid");
                            $mark->execute([':id' => $parent_notification_id, ':pid' => $main_parent_id]);
                        }
                    } else {
                        $message = "Failed to approve task.";
                    }
                } else {
                    $message = "Task is no longer waiting approval.";
                }
            } else {
                $message = "Task is no longer waiting approval.";
            }
        } else {
            $message = "Invalid task approval request.";
        }
    } elseif (isset($_POST['create_reward'])) {
        $title = filter_input(INPUT_POST, 'reward_title', FILTER_SANITIZE_STRING);
        $description = filter_input(INPUT_POST, 'reward_description', FILTER_SANITIZE_STRING);
        $point_cost = filter_input(INPUT_POST, 'point_cost', FILTER_VALIDATE_INT);
        $message = createReward($main_parent_id, $title, $description, $point_cost)
            ? "Reward created successfully!"
            : "Failed to create reward.";
    } elseif (isset($_POST['update_reward'])) {
        $reward_id = filter_input(INPUT_POST, 'reward_id', FILTER_VALIDATE_INT);
        $title = trim((string) filter_input(INPUT_POST, 'reward_title', FILTER_SANITIZE_STRING));
        $description = trim((string) filter_input(INPUT_POST, 'reward_description', FILTER_SANITIZE_STRING));
        $point_cost = filter_input(INPUT_POST, 'point_cost', FILTER_VALIDATE_INT);
        if ($reward_id && $title !== '' && $point_cost !== false && $point_cost !== null && $point_cost > 0) {
            $message = updateReward($main_parent_id, $reward_id, $title, $description, $point_cost)
                ? "Reward updated."
                : "Unable to update reward. It may have been redeemed or removed.";
        } else {
            $message = "Provide a title and point cost to update the reward.";
        }
    } elseif (isset($_POST['delete_reward'])) {
        $reward_id = filter_input(INPUT_POST, 'reward_id', FILTER_VALIDATE_INT);
        if ($reward_id) {
            $message = deleteReward($main_parent_id, $reward_id)
                ? "Reward deleted."
                : "Unable to delete reward. Only available rewards can be removed.";
        } else {
            $message = "Invalid reward selected for deletion.";
        }
    } elseif (isset($_POST['create_goal'])) {
        $child_user_id = filter_input(INPUT_POST, 'child_user_id', FILTER_VALIDATE_INT);
        $title = filter_input(INPUT_POST, 'goal_title', FILTER_SANITIZE_STRING);
        $description = trim((string) filter_input(INPUT_POST, 'goal_description', FILTER_SANITIZE_STRING));
        if ($description === '') {
            $description = null;
        }
        $start_date = filter_input(INPUT_POST, 'start_date', FILTER_SANITIZE_STRING);
        $end_date = filter_input(INPUT_POST, 'end_date', FILTER_SANITIZE_STRING);
        $reward_id = filter_input(INPUT_POST, 'reward_id', FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE);
        $message = createGoal($main_parent_id, $child_user_id, $title, $start_date, $end_date, $reward_id, $_SESSION['user_id'], ['description' => $description])
            ? "Goal created successfully!"
            : "Failed to create goal. Check date range or reward ID.";
    } elseif (isset($_POST['adjust_child_points'])) {
        if (!in_array($role_type, ['main_parent', 'secondary_parent'], true)) {
            $message = "You do not have permission to adjust points.";
        } else {
            $child_user_id = filter_input(INPUT_POST, 'child_user_id', FILTER_VALIDATE_INT);
            $points_delta_raw = filter_input(INPUT_POST, 'points_delta', FILTER_VALIDATE_INT);
            $point_reason = trim(filter_input(INPUT_POST, 'point_reason', FILTER_SANITIZE_STRING) ?? '');
            if (!$child_user_id || $points_delta_raw === false || $points_delta_raw === null || $points_delta_raw == 0) {
                $message = "Enter a non-zero point amount.";
            } else {
                $point_reason = $point_reason !== '' ? substr($point_reason, 0, 255) : 'Manual adjustment';
                $points_delta = (int) $points_delta_raw;
                // Ensure log table exists (idempotent)
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
                updateChildPoints($child_user_id, $points_delta);
                $stmt = $db->prepare("INSERT INTO child_point_adjustments (child_user_id, delta_points, reason, created_by, created_at) VALUES (:child_id, :delta, :reason, :created_by, NOW())");
                $stmt->execute([
                    ':child_id' => $child_user_id,
                    ':delta' => $points_delta,
                    ':reason' => $point_reason,
                    ':created_by' => $_SESSION['user_id']
                ]);
                addChildNotification(
                    (int)$child_user_id,
                    $points_delta > 0 ? 'points_added' : 'points_deducted',
                    ($points_delta > 0 ? 'You received ' : 'You lost ') . abs($points_delta) . ' pts: ' . $point_reason,
                    'dashboard_child.php'
                );
                $sign = $points_delta > 0 ? 'added' : 'deducted';
                $message = ucfirst($sign) . " " . abs($points_delta) . " points. Reason: " . htmlspecialchars($point_reason);
            }
        }
    } elseif (isset($_POST['approve_goal']) || isset($_POST['reject_goal'])) {
        $goal_id = filter_input(INPUT_POST, 'goal_id', FILTER_VALIDATE_INT);
        $action = isset($_POST['approve_goal']) ? 'approve' : 'reject';
        $comment = filter_input(INPUT_POST, 'rejection_comment', FILTER_SANITIZE_STRING);
        if ($action === 'approve') {
            $approved = approveGoal($goal_id, $main_parent_id);
            if ($approved) {
                $message = "Goal approved!";
            } else {
                $message = "Failed to approve goal.";
            }
        } else {
            $rejectError = null;
            if (rejectGoal($goal_id, $main_parent_id, $comment, $rejectError)) {
                $message = "Goal denied.";
            } else {
                $message = "Failed to deny goal." . ($rejectError ? " Reason: " . htmlspecialchars($rejectError) : "");
            }
        }
    } elseif (isset($_POST['fulfill_reward'])) {
        $rewardPayload = $_POST['fulfill_reward'] ?? '';
        $reward_id = 0;
        $parent_notification_id = 0;
        if (is_string($rewardPayload) && strpos($rewardPayload, '|') !== false) {
            [$rewardIdRaw, $noteIdRaw] = explode('|', $rewardPayload, 2);
            $reward_id = (int) $rewardIdRaw;
            $parent_notification_id = (int) $noteIdRaw;
        } else {
            $reward_id = filter_var($rewardPayload, FILTER_VALIDATE_INT) ?: 0;
            $parent_notification_id = filter_input(INPUT_POST, 'parent_notification_id', FILTER_VALIDATE_INT);
        }
        if (!$reward_id) {
            $reward_id = filter_input(INPUT_POST, 'reward_id', FILTER_VALIDATE_INT);
        }
        $fulfilled = ($reward_id && fulfillReward($reward_id, $main_parent_id, $_SESSION['user_id']));
        if (!$fulfilled && $reward_id) {
            $statusStmt = $db->prepare("SELECT status, fulfilled_on FROM rewards WHERE id = :id AND parent_user_id = :parent_id");
            $statusStmt->execute([':id' => $reward_id, ':parent_id' => $main_parent_id]);
            $statusRow = $statusStmt->fetch(PDO::FETCH_ASSOC);
            if (!empty($statusRow) && !empty($statusRow['fulfilled_on'])) {
                $fulfilled = true;
            }
        }
        $message = $fulfilled ? "Reward fulfillment recorded." : "Unable to mark reward as fulfilled.";
        if ($fulfilled && $parent_notification_id) {
            ensureParentNotificationsTable();
            $rewardTitleStmt = $db->prepare("SELECT title FROM rewards WHERE id = :id");
            $rewardTitleStmt->execute([':id' => $reward_id]);
            $rewardTitle = $rewardTitleStmt->fetchColumn() ?: 'Reward';
            $resolvedMessage = 'Reward fulfilled: ' . $rewardTitle . ' | ' . date('m/d/Y h:i A');
            $update = $db->prepare("UPDATE parent_notifications SET type = 'reward_fulfilled', message = :message, is_read = 1 WHERE id = :id AND parent_user_id = :pid");
            $update->execute([':message' => $resolvedMessage, ':id' => $parent_notification_id, ':pid' => $main_parent_id]);
            $mark = $db->prepare("UPDATE parent_notifications SET is_read = 1 WHERE id = :id AND parent_user_id = :pid");
            $mark->execute([':id' => $parent_notification_id, ':pid' => $main_parent_id]);
        }
    } elseif (isset($_POST['deny_reward'])) {
        $denyPayload = $_POST['deny_reward'] ?? '';
        $reward_id = 0;
        $parent_notification_id = 0;
        if (is_string($denyPayload) && strpos($denyPayload, '|') !== false) {
            [$rewardIdRaw, $noteIdRaw] = explode('|', $denyPayload, 2);
            $reward_id = (int) $rewardIdRaw;
            $parent_notification_id = (int) $noteIdRaw;
        } else {
            $reward_id = filter_var($denyPayload, FILTER_VALIDATE_INT) ?: 0;
            $parent_notification_id = filter_input(INPUT_POST, 'parent_notification_id', FILTER_VALIDATE_INT);
        }
        if (!$reward_id) {
            $reward_id = filter_input(INPUT_POST, 'reward_id', FILTER_VALIDATE_INT);
        }
        $deny_note = '';
        if ($parent_notification_id && !empty($_POST['deny_reward_note']) && is_array($_POST['deny_reward_note'])) {
            $noteMap = $_POST['deny_reward_note'];
            if (array_key_exists((string) $parent_notification_id, $noteMap)) {
                $deny_note = trim((string) filter_var($noteMap[(string) $parent_notification_id], FILTER_SANITIZE_STRING));
            }
        }
        if ($deny_note === '') {
            $deny_note = trim(filter_input(INPUT_POST, 'deny_reward_note', FILTER_SANITIZE_STRING) ?? '');
        }
        $denied = ($reward_id && denyReward($reward_id, $main_parent_id, $_SESSION['user_id'], $deny_note));
        if (!$denied && $reward_id) {
            $statusStmt = $db->prepare("SELECT status, denied_on FROM rewards WHERE id = :id AND parent_user_id = :parent_id");
            $statusStmt->execute([':id' => $reward_id, ':parent_id' => $main_parent_id]);
            $statusRow = $statusStmt->fetch(PDO::FETCH_ASSOC);
            if (!empty($statusRow) && ($statusRow['status'] ?? '') === 'available' && !empty($statusRow['denied_on'])) {
                $denied = true;
            }
        }
        $message = $denied ? "Reward request denied." : "Unable to deny reward request.";
        if ($denied && $parent_notification_id) {
            ensureParentNotificationsTable();
            $rewardTitleStmt = $db->prepare("SELECT title FROM rewards WHERE id = :id");
            $rewardTitleStmt->execute([':id' => $reward_id]);
            $rewardTitle = $rewardTitleStmt->fetchColumn() ?: 'Reward';
            $resolvedMessage = 'Reward denied: ' . $rewardTitle . ' | ' . date('m/d/Y h:i A');
            if ($deny_note !== '') {
                $resolvedMessage .= ' | Reason: ' . $deny_note;
            }
            $update = $db->prepare("UPDATE parent_notifications SET type = 'reward_denied', message = :message, is_read = 1 WHERE id = :id AND parent_user_id = :pid");
            $update->execute([':message' => $resolvedMessage, ':id' => $parent_notification_id, ':pid' => $main_parent_id]);
            $mark = $db->prepare("UPDATE parent_notifications SET is_read = 1 WHERE id = :id AND parent_user_id = :pid");
            $mark->execute([':id' => $parent_notification_id, ':pid' => $main_parent_id]);
        }
    } elseif (isset($_POST['add_child'])) {
        if (!canAddEditChild($_SESSION['user_id'])) {
            $message = "You do not have permission to add children.";
        } else {
            $first_name = filter_input(INPUT_POST, 'first_name', FILTER_SANITIZE_STRING);
            $last_name = filter_input(INPUT_POST, 'last_name', FILTER_SANITIZE_STRING);
            $child_username = filter_input(INPUT_POST, 'child_username', FILTER_SANITIZE_STRING);
            $child_password = filter_input(INPUT_POST, 'child_password', FILTER_SANITIZE_STRING);
            $birthday = filter_input(INPUT_POST, 'birthday', FILTER_SANITIZE_STRING);
            $avatar = filter_input(INPUT_POST, 'avatar', FILTER_SANITIZE_STRING);
            $gender = filter_input(INPUT_POST, 'child_gender', FILTER_SANITIZE_STRING);
            $upload_path = '';
            if (isset($_FILES['avatar_upload']) && $_FILES['avatar_upload']['error'] == 0) {
                $upload_dir = __DIR__ . '/uploads/avatars/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                $file_ext = pathinfo($_FILES['avatar_upload']['name'], PATHINFO_EXTENSION);
                $file_name = uniqid() . '_' . pathinfo($_FILES['avatar_upload']['name'], PATHINFO_FILENAME) . '.' . $file_ext;
                $upload_path = 'uploads/avatars/' . $file_name;
                if (move_uploaded_file($_FILES['avatar_upload']['tmp_name'], __DIR__ . '/' . $upload_path)) {
                    $image = imagecreatefromstring(file_get_contents(__DIR__ . '/' . $upload_path));
                    $resized = imagecreatetruecolor(100, 100);
                    imagecopyresampled($resized, $image, 0, 0, 0, 0, 100, 100, imagesx($image), imagesy($image));
                    imagejpeg($resized, __DIR__ . '/' . $upload_path, 90);
                    imagedestroy($image);
                    imagedestroy($resized);
                    $avatar = $upload_path;
                } else {
                    $message = "Upload failed; using default avatar.";
                }
            }
            $created = createChildProfile($_SESSION['user_id'], $first_name, $last_name, $child_username, $child_password, $birthday, $avatar, $gender);
            if ($created && is_array($created)) {
                if (($created['status'] ?? '') === 'restored') {
                    $message = "Child restored with existing data. Username updated to $child_username. New password: $child_password (share securely).";
                } else {
                    $message = "Child added successfully! Username: $child_username, Password: $child_password (share securely).";
                }
            } else {
                $message = "Failed to add child. Check for duplicate username.";
            }
        }
    } elseif (isset($_POST['add_new_user'])) {
        if (!canAddEditFamilyMember($_SESSION['user_id'])) {
            $message = "You do not have permission to add family members or caregivers.";
        } else {
            $first_name = filter_input(INPUT_POST, 'secondary_first_name', FILTER_SANITIZE_STRING);
            $last_name = filter_input(INPUT_POST, 'secondary_last_name', FILTER_SANITIZE_STRING);
            $username = filter_input(INPUT_POST, 'secondary_username', FILTER_SANITIZE_STRING);
            $password = filter_input(INPUT_POST, 'secondary_password', FILTER_SANITIZE_STRING);
            $role_type = filter_input(INPUT_POST, 'role_type', FILTER_SANITIZE_STRING);
            if ($role_type && in_array($role_type, ['secondary_parent', 'family_member', 'caregiver'], true)) {
                $message = addLinkedUser($main_parent_id, $username, $password, $first_name, $last_name, $role_type)
                    ? ucfirst(str_replace('_', ' ', $role_type)) . " added successfully! Username: $username"
                    : "Failed to add user. Check for duplicate username.";
            } else {
                $message = "Invalid role type selected.";
            }
        }
    } elseif (isset($_POST['delete_user']) && in_array($role_type, ['main_parent', 'secondary_parent'], true)) {
        $delete_user_id = filter_input(INPUT_POST, 'delete_user_id', FILTER_VALIDATE_INT);
        $delete_mode = $_POST['delete_mode'] ?? 'soft';
        if ($delete_user_id) {
            if ($delete_user_id == $main_parent_id) {
                $message = "Cannot remove the main account owner.";
            } else {
                // Check if this user is a child of the family
                $childCheck = $db->prepare("SELECT 1 FROM child_profiles WHERE child_user_id = :uid AND parent_user_id = :pid LIMIT 1");
                $childCheck->execute([':uid' => $delete_user_id, ':pid' => $main_parent_id]);
                if ($childCheck->fetchColumn()) {
                    if ($delete_mode === 'hard') {
                        $message = hardDeleteChild($main_parent_id, $delete_user_id)
                            ? "Child permanently deleted."
                            : "Failed to permanently delete child.";
                    } else {
                        $message = softDeleteChild($main_parent_id, $delete_user_id, $_SESSION['user_id'])
                            ? "Child removed. Data retained for restore."
                            : "Failed to remove child.";
                    }
                } else {
                    // Remove linked adults / caregivers
                    $stmt = $db->prepare("DELETE FROM users 
                                          WHERE id = :user_id AND id IN (
                                              SELECT linked_user_id FROM family_links WHERE main_parent_id = :main_parent_id
                                          )");
                    $stmt->execute([':user_id' => $delete_user_id, ':main_parent_id' => $main_parent_id]);
                    $message = $stmt->rowCount() > 0 ? "User removed successfully." : "Failed to remove user.";
                }
            }
        }
    }
}

require_once __DIR__ . '/includes/notifications_bootstrap.php';

$parentNotificationActionSummary = $parentNotificationActionSummary ?? '';
$parentNotificationActionTab = $parentNotificationActionTab ?? '';
$getRewardFulfillMeta = function($rewardId) use ($db) {
    static $cache = [];
    $rewardId = (int)$rewardId;
    if ($rewardId <= 0) return null;
    if (isset($cache[$rewardId])) return $cache[$rewardId];
    $stmt = $db->prepare("SELECT status, redeemed_on, fulfilled_on, fulfilled_by, denied_on, denied_by, denied_note FROM rewards WHERE id = :id");
    $stmt->execute([':id' => $rewardId]);
    $cache[$rewardId] = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    return $cache[$rewardId];
};
$data = getDashboardData($_SESSION['user_id']);
$activeRewardCounts = [];
foreach (($data['active_rewards'] ?? []) as $ar) {
    $cid = (int)($ar['child_user_id'] ?? 0);
    if ($cid > 0) {
        $activeRewardCounts[$cid] = ($activeRewardCounts[$cid] ?? 0) + 1;
    }
}
$redeemedRewardCounts = [];
 $pendingRewardCounts = [];
foreach (($data['redeemed_rewards'] ?? []) as $rr) {
    $cid = (int)($rr['child_user_id'] ?? 0);
    if ($cid > 0) {
        $redeemedRewardCounts[$cid] = ($redeemedRewardCounts[$cid] ?? 0) + 1;
        if (empty($rr['fulfilled_on'])) {
            $pendingRewardCounts[$cid] = ($pendingRewardCounts[$cid] ?? 0) + 1;
        }
    }
}
$todayDate = date('Y-m-d');
ensureRoutinePointsLogsTable();
$isRoutineCompletedOnDate = static function (array $routine, string $dateKey, array $completionMap = []): bool {
    $rid = (int) ($routine['id'] ?? 0);
    if ($rid > 0 && !empty($completionMap[$rid][$dateKey])) {
        return true;
    }
    $tasks = $routine['tasks'] ?? [];
    if (empty($tasks)) {
        return false;
    }
    foreach ($tasks as $task) {
        $completedAt = $task['completed_at'] ?? null;
        if (empty($completedAt)) {
            return false;
        }
        $completedDate = date('Y-m-d', strtotime($completedAt));
        if ($completedDate !== $dateKey || ($task['status'] ?? 'pending') !== 'completed') {
            return false;
        }
    }
    return true;
};
$weekStart = new DateTime('monday this week');
$weekStart->setTime(0, 0, 0);
$weekEnd = new DateTime('sunday this week');
$weekEnd->setTime(23, 59, 59);
$weekDates = [];
$weekCursor = clone $weekStart;
for ($i = 0; $i < 7; $i++) {
    $weekDates[] = $weekCursor->format('Y-m-d');
    $weekCursor->modify('+1 day');
}
$nowTs = time();
$todayKey = date('Y-m-d');
$getScheduleDueStamp = static function ($dateKey, $timeOfDay, $timeValue) {
    if (empty($dateKey)) {
        return null;
    }
    $timeValue = trim((string) $timeValue);
    $hasTime = $timeValue !== '' && $timeValue !== '00:00';
    if ($hasTime) {
        $stamp = strtotime($dateKey . ' ' . $timeValue . ':00');
        return $stamp === false ? null : $stamp;
    }
    if (($timeOfDay ?? 'anytime') !== 'anytime') {
        $fallback = $timeOfDay === 'morning' ? '08:00' : ($timeOfDay === 'afternoon' ? '13:00' : '18:00');
        $stamp = strtotime($dateKey . ' ' . $fallback . ':00');
        return $stamp === false ? null : $stamp;
    }
    $stamp = strtotime($dateKey . ' 23:59:59');
    return $stamp === false ? null : $stamp;
};
$buildWeekSchedule = static function (int $childId, DateTime $weekStart, DateTime $weekEnd, array $weekDates) use ($db, $getScheduleDueStamp, $nowTs, $todayKey, $isRoutineCompletedOnDate): array {
    ensureRoutinePointsLogsTable();
    $routineCompletionByDate = [];
    $routineLogStmt = $db->prepare("SELECT routine_id, DATE(created_at) AS date_key, MAX(created_at) AS completed_at
        FROM routine_points_logs
        WHERE child_user_id = :child_id
        GROUP BY routine_id, DATE(created_at)");
    $routineLogStmt->execute([':child_id' => $childId]);
    foreach ($routineLogStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rid = (int) ($row['routine_id'] ?? 0);
        $dateKey = $row['date_key'] ?? null;
        if ($rid > 0 && $dateKey) {
            if (!isset($routineCompletionByDate[$rid])) {
                $routineCompletionByDate[$rid] = [];
            }
            $routineCompletionByDate[$rid][$dateKey] = $row['completed_at'];
        }
    }

    $weekSchedule = [];
    foreach ($weekDates as $dateKey) {
        $weekSchedule[$dateKey] = [];
    }

    $taskStmt = $db->prepare("SELECT id, title, points, due_date, end_date, recurrence, recurrence_days, time_of_day, status, completed_at, approved_at FROM tasks WHERE child_user_id = :child_id AND due_date IS NOT NULL AND DATE(due_date) <= :end");
    $taskStmt->execute([
        ':child_id' => $childId,
        ':end' => $weekEnd->format('Y-m-d')
    ]);
    $taskRows = $taskStmt->fetchAll(PDO::FETCH_ASSOC);
    $taskInstanceMap = [];
    if (!empty($taskRows)) {
        $taskIds = array_values(array_filter(array_map(static function ($row) {
            return (int) ($row['id'] ?? 0);
        }, $taskRows)));
        if (!empty($taskIds)) {
            $placeholders = implode(',', array_fill(0, count($taskIds), '?'));
            $instanceStmt = $db->prepare("SELECT task_id, date_key, status, completed_at FROM task_instances WHERE task_id IN ($placeholders) AND date_key BETWEEN ? AND ?");
            $params = $taskIds;
            $params[] = $weekStart->format('Y-m-d');
            $params[] = $weekEnd->format('Y-m-d');
            $instanceStmt->execute($params);
            foreach ($instanceStmt->fetchAll(PDO::FETCH_ASSOC) as $instanceRow) {
                $tid = (int) $instanceRow['task_id'];
                $dateKey = $instanceRow['date_key'];
                if (!$dateKey) {
                    continue;
                }
                if (!isset($taskInstanceMap[$tid])) {
                    $taskInstanceMap[$tid] = [];
                }
                $taskInstanceMap[$tid][$dateKey] = [
                    'status' => $instanceRow['status'] ?? null,
                    'completed_at' => $instanceRow['completed_at'] ?? null
                ];
            }
        }
    }

    foreach ($taskRows as $row) {
        $timeOfDay = $row['time_of_day'] ?? 'anytime';
        $dueDate = $row['due_date'];
        $dueTimeValue = !empty($dueDate) ? date('H:i', strtotime($dueDate)) : '';
        $startDateKey = date('Y-m-d', strtotime($dueDate));
        $endDateKey = !empty($row['end_date']) ? $row['end_date'] : null;
        $timeSort = !empty($dueDate) ? date('H:i', strtotime($dueDate)) : '99:99';
        $timeLabel = !empty($dueDate) ? date('g:i A', strtotime($dueDate)) : '';
        if ($timeLabel === '12:00 AM') {
            $timeLabel = '';
        }
        if ($timeLabel === '') {
            if ($timeOfDay === 'anytime') {
                $timeSort = '99:99';
                $timeLabel = 'Anytime';
            } else {
                $timeLabel = ucfirst($timeOfDay);
            }
        }
        $repeat = $row['recurrence'] ?? '';
        $repeatDays = array_filter(array_map('trim', explode(',', (string) ($row['recurrence_days'] ?? ''))));
        foreach ($weekDates as $dateKey) {
            if ($dateKey < $startDateKey) {
                continue;
            }
            if ($endDateKey && $dateKey > $endDateKey) {
                continue;
            }
            if ($repeat === 'daily') {
                // include every day
            } elseif ($repeat === 'weekly') {
                $dayName = date('D', strtotime($dateKey));
                if (!in_array($dayName, $repeatDays, true)) {
                    continue;
                }
            } elseif ($repeat) {
                continue;
            } else {
                if ($dateKey !== $startDateKey) {
                    continue;
                }
            }
            $instanceData = $taskInstanceMap[(int) ($row['id'] ?? 0)][$dateKey] ?? null;
            $instanceStatus = is_array($instanceData) ? ($instanceData['status'] ?? null) : $instanceData;
            $instanceCompletedAt = is_array($instanceData) ? ($instanceData['completed_at'] ?? null) : null;
            $completedFlag = false;
            $rejectedFlag = false;
            $completedStamp = null;
            if (empty($repeat)) {
                $completedFlag = in_array(($row['status'] ?? ''), ['completed', 'approved'], true);
                $completedStamp = $row['completed_at'] ?? $row['approved_at'] ?? null;
            } elseif ($instanceStatus) {
                $completedFlag = in_array($instanceStatus, ['completed', 'approved'], true);
                $rejectedFlag = $instanceStatus === 'rejected';
                $completedStamp = $instanceCompletedAt;
            }
            $overdueFlag = false;
            $dueStamp = $getScheduleDueStamp($dateKey, $timeOfDay, $dueTimeValue);
            if ($completedFlag) {
                if ($completedStamp && $dueStamp !== null && strtotime($completedStamp) > $dueStamp) {
                    $overdueFlag = true;
                }
            } elseif (!$rejectedFlag) {
                if ($dueStamp !== null && $dueStamp < $nowTs && $dateKey <= $todayKey) {
                    $overdueFlag = true;
                }
            }
            $weekSchedule[$dateKey][] = [
                'id' => (int) ($row['id'] ?? 0),
                'title' => $row['title'],
                'type' => 'Task',
                'points' => (int) ($row['points'] ?? 0),
                'time' => $timeSort,
                'time_label' => $timeLabel,
                'time_of_day' => $timeOfDay,
                'link' => 'task.php?task_id=' . (int) ($row['id'] ?? 0) . '&instance_date=' . $dateKey . '#task-' . (int) ($row['id'] ?? 0),
                'icon' => 'fa-solid fa-list-check',
                'completed' => $completedFlag,
                'overdue' => $overdueFlag
            ];
        }
    }

    $childRoutines = getRoutines($childId);
    foreach ($childRoutines as $routine) {
        $timeOfDay = $routine['time_of_day'] ?? 'anytime';
        $recurrence = $routine['recurrence'] ?? '';
        $routineWeekday = !empty($routine['created_at']) ? (int) date('N', strtotime($routine['created_at'])) : null;
        $routineDays = array_values(array_filter(array_map('trim', explode(',', (string) ($routine['recurrence_days'] ?? '')))));
        $routineDateKey = !empty($routine['routine_date']) ? $routine['routine_date'] : (!empty($routine['created_at']) ? date('Y-m-d', strtotime($routine['created_at'])) : null);
        $routinePointsTotal = 0;
        foreach (($routine['tasks'] ?? []) as $task) {
            $routinePointsTotal += (int) ($task['point_value'] ?? 0);
        }
        $totalPoints = $routinePointsTotal + (int) ($routine['bonus_points'] ?? 0);
        $startTimeValue = !empty($routine['start_time']) ? date('H:i', strtotime($routine['start_time'])) : '';
        $timeSort = !empty($routine['start_time']) ? date('H:i', strtotime($routine['start_time'])) : '99:99';
        $timeLabel = !empty($routine['start_time']) ? date('g:i A', strtotime($routine['start_time'])) : '';
        if ($timeLabel === '12:00 AM') {
            $timeLabel = '';
        }
        if ($timeLabel === '') {
            if ($timeOfDay === 'anytime') {
                $timeSort = '99:99';
                $timeLabel = 'Anytime';
            } else {
                $timeLabel = ucfirst($timeOfDay);
            }
        }
        foreach ($weekDates as $dateKey) {
            if ($recurrence === 'daily') {
                // include every day
            } elseif ($recurrence === 'weekly') {
                if (!empty($routineDays)) {
                    $dayName = date('D', strtotime($dateKey));
                    if (!in_array($dayName, $routineDays, true)) {
                        continue;
                    }
                } elseif ($routineWeekday) {
                    $dayNumber = (int) date('N', strtotime($dateKey));
                    if ($dayNumber !== $routineWeekday) {
                        continue;
                    }
                }
            } elseif ($recurrence) {
                continue;
            } else {
                if (!$routineDateKey || $dateKey !== $routineDateKey) {
                    continue;
                }
            }
            $routineId = (int) ($routine['id'] ?? 0);
            $completedStamp = $routineCompletionByDate[$routineId][$dateKey] ?? null;
            $completedFlag = $completedStamp ? true : $isRoutineCompletedOnDate($routine, $dateKey, $routineCompletionByDate);
            $overdueFlag = false;
            if (!$completedFlag) {
                $dueStamp = $getScheduleDueStamp($dateKey, $timeOfDay, $startTimeValue);
                if ($dueStamp !== null && $dueStamp < $nowTs && $dateKey <= $todayKey) {
                    $overdueFlag = true;
                }
            } else {
                $dueStamp = $getScheduleDueStamp($dateKey, $timeOfDay, $startTimeValue);
                if ($completedStamp && $dueStamp !== null && strtotime($completedStamp) > $dueStamp) {
                    $overdueFlag = true;
                }
            }
            $weekSchedule[$dateKey][] = [
                'id' => (int) ($routine['id'] ?? 0),
                'title' => $routine['title'],
                'type' => 'Routine',
                'points' => $totalPoints,
                'time' => $timeSort,
                'time_label' => $timeLabel,
                'time_of_day' => $timeOfDay,
                'link' => 'routine.php?start=' . (int) ($routine['id'] ?? 0),
                'icon' => 'fa-solid fa-repeat',
                'completed' => $completedFlag,
                'overdue' => $overdueFlag
            ];
        }
    }

    foreach ($weekSchedule as &$items) {
        usort($items, static function ($a, $b) {
            return ($a['time'] ?? '99:99') <=> ($b['time'] ?? '99:99');
        });
    }
    unset($items);

    return $weekSchedule;
};
if (isset($_GET['week_schedule'])) {
    header('Content-Type: application/json');
    $childId = filter_input(INPUT_GET, 'child_id', FILTER_VALIDATE_INT);
    $weekStartRaw = trim((string) ($_GET['week_start'] ?? ''));
    $weekStartDate = DateTime::createFromFormat('Y-m-d', $weekStartRaw);
    if (!$childId || !$weekStartDate) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid request.']);
        exit;
    }
    $allowedChildIds = array_map(static function ($row) {
        return (int) ($row['child_user_id'] ?? 0);
    }, $data['children'] ?? []);
    if (!in_array($childId, $allowedChildIds, true)) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden.']);
        exit;
    }
    $weekStartDate->setTime(0, 0, 0);
    $weekEndDate = clone $weekStartDate;
    $weekEndDate->modify('+6 days');
    $weekEndDate->setTime(23, 59, 59);
    $weekDates = [];
    $cursor = clone $weekStartDate;
    for ($i = 0; $i < 7; $i++) {
        $weekDates[] = $cursor->format('Y-m-d');
        $cursor->modify('+1 day');
    }
    $weekSchedule = $buildWeekSchedule($childId, $weekStartDate, $weekEndDate, $weekDates);
    echo json_encode([
        'week_dates' => $weekDates,
        'week_schedule' => $weekSchedule
    ]);
    exit;
}

$formatParentNotificationMessage = static function (array $note): string {
    $message = (string) ($note['message'] ?? '');
    $type = (string) ($note['type'] ?? '');
    $highlight = static function (string $text, int $start, int $length): string {
        $prefix = substr($text, 0, $start);
        $title = substr($text, $start, $length);
        $suffix = substr($text, $start + $length);
        return htmlspecialchars($prefix)
            . '<span class="parent-notification-title">' . htmlspecialchars($title) . '</span>'
            . htmlspecialchars($suffix);
    };

    if ($type === 'reward_redeemed') {
        if (preg_match('/"([^"]+)"/', $message, $match, PREG_OFFSET_CAPTURE)) {
            return $highlight($message, $match[1][1], strlen($match[1][0]));
        }
    }

    if (in_array($type, ['routine_completed', 'task_completed'], true)) {
        if (preg_match('/\\bcompleted\\s+([^\\.]+)\\./', $message, $match, PREG_OFFSET_CAPTURE)) {
            return $highlight($message, $match[1][1], strlen($match[1][0]));
        }
    }

    if (in_array($type, ['task_approved', 'task_rejected', 'task_rejected_closed', 'goal_completed', 'goal_ready', 'goal_reward_earned', 'reward_denied', 'reward_fulfilled'], true)) {
        if (preg_match('/:\\s*([^|]+?)(?=\\s*(\\||$))/', $message, $match, PREG_OFFSET_CAPTURE)) {
            return $highlight($message, $match[1][1], strlen($match[1][0]));
        }
    }

    if (preg_match('/"([^"]+)"/', $message, $match, PREG_OFFSET_CAPTURE)) {
        return $highlight($message, $match[1][1], strlen($match[1][0]));
    }
    if (preg_match('/\\bcompleted\\s+([^\\.]+)\\./', $message, $match, PREG_OFFSET_CAPTURE)) {
        return $highlight($message, $match[1][1], strlen($match[1][0]));
    }
    if (preg_match('/:\\s*([^|]+?)(?=\\s*(\\||$))/', $message, $match, PREG_OFFSET_CAPTURE)) {
        return $highlight($message, $match[1][1], strlen($match[1][0]));
    }

    return htmlspecialchars($message);
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Parent Dashboard</title>
    <link rel="stylesheet" href="css/main.css?v=3.17.6">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css" integrity="Evv84Mr4kqVGRNSgIGL/F/aIDqQb7xQ2vcrdIwxfjThSH8CSR7PBEakCr51Ck+w+/U6swU2Im1vVX0SVk9ABhg==" crossorigin="anonymous" referrerpolicy="no-referrer">
    <style>
        .dashboard { padding: 20px; max-width: 1200px; margin: 0 auto; }
        .children-overview, .management-links, .active-rewards, .redeemed-rewards, .manage-family { margin-top: 20px; }
        .children-overview-grid { display: grid; grid-template-columns: 1fr; gap: 20px; }
        .child-info-card, .reward-item, .goal-item { background-color: #f5f5f5; padding: 15px; border-radius: 8px; box-shadow: 0 8px 20px rgba(0,0,0,0.08); }
        .child-info-card { width: 100%; display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 20px; align-items: start; min-height: 100%; background-color: #fff; margin: 20px 0;}
        .child-info-left { display: contents; }
        .child-info-header { display: flex; flex-direction: column; align-items: center; gap: 8px; text-align: center; }
        .child-info-header img { width: 72px; height: 72px; border-radius: 50%; object-fit: cover; border: 2px solid #ececec; }
        .child-info-header-details { display: flex; flex-direction: column; gap: 4px; }
        .child-info-name { font-size: 1.15em; font-weight: 600; margin: 0; color: #333; }
        .child-action-icon { width: 36px; height: 36px; border-radius: 50%; border: none; background: transparent; color: #919191; display: inline-flex; align-items: center; justify-content: center; cursor: pointer; }
        .child-action-icon:hover { color: #7a7a7a; }
        .child-action-icon.danger { color: #919191; }
        .child-action-icon.danger:hover { color: #7a7a7a; }
        .member-item { display: flex; align-items: center; gap: 12px; background: #fff; }
        .member-details { display: flex; flex-direction: column; gap: 4px; }
        .member-actions { display: inline-flex; align-items: center; gap: 8px; margin-left: auto; }
        .member-actions form { margin: 0; display: inline-flex; }
        .member-action-icon { width: 36px; height: 36px; border-radius: 50%; border: none; background: transparent; color: #9f9f9f; display: inline-flex; align-items: center; justify-content: center; cursor: pointer; text-decoration: none; }
        .member-action-icon:hover { color: #7a7a7a; }
        .member-action-icon.danger { color: #919191; }
        .member-action-icon.danger:hover { color: #7a7a7a; }
        .child-info-content { display: contents; }
        .child-info-body { display: grid; gap: 12px; margin-bottom: 25px; }
        .child-stats-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 0; background: #fff; border-radius: 14px; border: 1px solid #ece7e1; box-shadow: 0 10px 22px rgba(0,0,0,0.08); overflow: hidden; }
        .child-stat-link { text-decoration: none; color: inherit; display: grid; justify-items: center; gap: 6px; padding: 12px 10px; border-right: 1px solid #eee6dd; text-align: center; }
        .child-stat-link:last-child { border-right: none; }
        .child-stat-icon { width: 34px; height: 34px; border-radius: 50%; background: #eef3ee; display: inline-flex; align-items: center; justify-content: center; color: #4a7a42; font-size: 1.05rem; }
        .child-stat-label { font-size: 0.82rem; color: #6f6f6f; font-weight: 600; line-height: 1.2; white-space: normal; }
        .child-stat-badge { min-width: 34px; padding: 2px 10px; border-radius: 999px; background: #e7f2e7; color: #2f6f2a; font-weight: 700; font-size: 0.95rem; text-align: center; box-shadow: inset 0 0 0 1px rgba(47,111,42,0.08); }
        @media (min-width: 900px) {
            .child-stats-grid { grid-template-columns: 1fr; }
            .child-stat-link { border-right: none; border-bottom: 1px solid #eee6dd; }
            .child-stat-link:last-child { border-bottom: none; }
        }
        .child-reward-badges { display: flex; justify-content: center; gap: 10px; flex-wrap: nowrap; margin-top: 4px; }
        .stat-link { color: inherit; text-decoration: none; }
        .stat-link:hover { text-decoration: none; }
        .child-reward-badge-link { text-decoration: none; display: grid; gap: 2px; align-items: center; justify-items: center; padding: 4px 6px; border-radius: 8px; min-width: 73.25px;}
        .child-reward-badge-link:hover { text-decoration: none;}
        .badge-count { font-size: 1.6em; font-weight: 700; color: #2e7d32; line-height: 1.1; }
        .child-reward-badge-link .badge-label { font-size: 0.85em; color: #666; }
        .points-progress-wrapper { display: flex; flex-direction: column; align-items: center; gap: 12px; flex: 1; /* background: linear-gradient(180deg, #fbfaf9 0%, #f3efea 100%);*/ border-radius: 18px; padding: 16px; border: 1px solid #e9e9e9; box-shadow: 0 8px 18px rgba(0,0,0,0.08); }
        .points-progress-label { font-size: 0.8em; font-weight: 700; color: #7a7a7a; text-align: center; text-transform: none; letter-spacing: 0.03em; }
        .points-number { display: inline-flex; align-items: center; justify-content: center; gap: 6px; padding: 2px 4px; border-radius: 999px; background: transparent; border: none; box-shadow: none; font-size: 1.5rem; font-weight: 600; color: #4a7a42; line-height: 1; }
        .points-number i { color: #5f8a57; font-size: 0.9em; }
        .points-number-value { font-weight: 600; }
        .points-number-suffix { font-size: 0.78em; font-weight: 600; color: #4a7a42; }
        .child-badge-row { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin-top: 6px; }
        .badge-pill { display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 8px; background: transparent; color: #0d47a1; font-weight: 700; border: 1px solid #d5def0; font-size: 0.95em; text-decoration: none; }
        .badge-pill:hover { background: #eef4ff; text-decoration: none; }
        .badge-pill i { font-size: 0.95em; }
        .adjust-button { background: linear-gradient(180deg, #ffb244 0%, #f38a0c 100%) !important; color: #fff; display: inline-flex; align-items: center; justify-content: center; gap: 10px; font-weight: 500; border-radius: 12px; padding: 12px 16px; width: 100%; box-shadow: none; border: 1px solid rgba(0,0,0,0.06); }
        .adjust-button .label { font-size: 0.95em; }
        .adjust-button i { font-size: 1.05em; line-height: 1; }
        .history-button { background: linear-gradient(180deg, #6f8fe3 0%, #4f6cc8 100%) !important; color: #fff; display: inline-flex; align-items: center; justify-content: center; gap: 10px; border-radius: 12px; padding: 12px 16px; width: 100%; font-weight: 500; box-shadow: none; border: 1px solid rgba(0,0,0,0.06); }
        .history-button i { color: inherit; }
        .points-adjust-card { border: 1px dashed #c8e6c9; background: #fdfefb; padding: 10px 12px; border-radius: 6px; display: grid; gap: 8px; }
        .points-adjust-card .button { width: 100%; }
        .child-schedule-card { border: 1px solid #e0e0e0; background: #fff; border-radius: 10px; padding: 12px; display: grid; gap: 10px; }
        .child-schedule-today { display: grid; gap: 4px; text-align: left; }
        .child-schedule-date { font-weight: 700; color: #37474f; }
        .child-schedule-list { list-style: none; padding: 0; margin: 0; display: grid; gap: 8px; }
        .child-schedule-section { display: grid; gap: 6px; }
        .child-schedule-section-title { font-weight: 700; color: #37474f; font-size: 0.95rem; }
        .child-schedule-section-list { list-style: none; padding: 0; margin: 0; display: grid; gap: 8px; }
        .child-schedule-item { display: flex; align-items: center; justify-content: space-between; gap: 10px; background: #f9f9f9; border-radius: 8px; padding: 8px 10px; text-decoration: none; color: inherit; cursor: pointer; width: 100%; }
        .child-schedule-item:hover { background: #f0f0f0; }
        .child-schedule-main { display: flex; align-items: center; gap: 8px; }
        .child-schedule-main>i { color: #919191; }
        .child-schedule-card .child-schedule-main > i.fa-list-check { color: #ef6c00; }
        .child-schedule-card .child-schedule-main > i.fa-repeat { color: #0d47a1; }
        .child-schedule-title { font-weight: 600; color: #3e2723; }
        .child-schedule-time { color: #6d4c41; font-size: 0.9rem; }
        .child-schedule-points { background-color: #4caf50; color: #fff; border-radius: 12px; padding: 4px 8px; font-size: 0.85rem; font-weight: 600; white-space: nowrap; }
        .child-schedule-badge { display: inline-flex; align-items: center; gap: 4px; margin-left: 8px; padding: 2px 8px; border-radius: 999px; font-size: 0.7rem; font-weight: 700; background: #4caf50; color: #fff; text-transform: uppercase; }
        .child-schedule-badge.compact { justify-content: center; margin-left: 6px; width: 20px; height: 20px; padding: 0; border-radius: 50%; font-size: 0.65rem; }
        .child-schedule-badge.overdue { background: #d9534f; }
        .child-schedule-badge-group { display: inline-flex; align-items: center; }
        .week-day-group.is-today { border: 1px solid #ffd28a; background: #ffe0b2; border-radius: 10px; padding: 10px; }
        .view-week-button { justify-self: start; padding: 6px 12px; font-size: 0.9rem; background: #eef4ff; border: 1px solid #d5def0; color: #0d47a1; border-radius: 8px; cursor: pointer; }
        .week-modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.55); display: none; align-items: center; justify-content: center; z-index: 3200; padding: 14px; }
        .week-modal-backdrop.open { display: flex; }
        .week-modal { background: #fff; border-radius: 12px; max-width: 1100px; width: min(1100px, 96vw); max-height: 90vh; overflow: hidden; box-shadow: 0 14px 36px rgba(0,0,0,0.25); display: grid; grid-template-rows: auto 1fr; }
        .week-modal header { display: flex; align-items: center; justify-content: space-between; padding: 12px 16px; border-bottom: 1px solid #e0e0e0; }
        .week-modal h3 { margin: 0; font-size: 1.1rem; }
        .week-modal-close { background: transparent; border: none; font-size: 1.3rem; cursor: pointer; color: #555; }
        .week-modal-body { padding: 0; overflow-y: auto; text-align: left; }
        .week-modal .task-calendar-section { width: 100%; max-width: 100%; margin: 0; padding: 0; }
        .week-modal .task-calendar-card { background: transparent; border-radius: 0; box-shadow: none; padding: 16px; display: grid; gap: 16px; }
        .week-modal .calendar-header { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; }
        .week-modal .calendar-header h2 { margin: 0; font-size: 1.2rem; }
        .week-modal .calendar-subtitle { margin: 4px 0 0; color: #607d8b; font-size: 0.95rem; }
        .week-modal .calendar-nav { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
        .week-modal .calendar-nav-button { border: 1px solid #d5def0; background: #eef4ff; color: #0d47a1; font-weight: 700; border-radius: 999px; padding: 6px 12px; cursor: pointer; }
        .week-modal .calendar-nav-button:hover { background: #dce8ff; }
        .week-modal .calendar-range { font-weight: 700; color: #37474f; }
        .week-modal .calendar-view-toggle { display: inline-flex; align-items: center; gap: 6px; padding: 4px; border-radius: 999px; border: 1px solid #d5def0; background: #f5f7fb; }
        .week-modal .calendar-view-button { width: 36px; height: 36px; border: none; border-radius: 50%; background: transparent; color: #607d8b; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; }
        .week-modal .calendar-view-button.active { background: #0d47a1; color: #fff; box-shadow: 0 4px 10px rgba(13, 71, 161, 0.2); }
        .week-modal .task-week-calendar { border: 1px solid #d5def0; border-radius: 12px; background: #fff; overflow: hidden; position: relative; }
        .week-modal .task-week-scroll { overflow-x: auto; }
        .week-modal .week-days { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 6px; }
        .week-modal .week-day-name { font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.04em; }
        .week-modal .week-day-num { font-size: 1rem; }
        .week-modal .week-days-header { background: #f5f7fb; padding: 8px; min-width: 980px; }
        .week-modal .week-days-header .week-day { background: #fff; border: 1px solid #d5def0; border-radius: 10px; padding: 6px 0; display: grid; gap: 2px; justify-items: center; font-weight: 700; color: #37474f; font-family: inherit; }
        .week-modal .week-days-header .week-day.is-today { background: #ffe0b2; border-color: #ffd28a; color: #ef6c00; }
        .week-modal .week-grid { display: grid; grid-template-columns: repeat(7, minmax(133px, 1fr)); gap: 6px; background: #f5f7fb; padding: 6px 8px 10px; min-width: 980px; }
        .week-modal .week-column { background: #fff; border: 1px solid #d5def0; border-radius: 10px; padding: 8px; display: flex; flex-direction: column; gap: 8px; min-height: 140px; }
        .week-modal .week-column-tasks { display: grid; gap: 8px; }
        .week-modal .task-week-calendar.is-hidden { display: none; }
        .week-modal .task-week-list { display: none; border: 1px solid #d5def0; border-radius: 12px; background: #fff; padding: 12px; }
        .week-modal .task-week-list.active { display: grid; gap: 12px; }
        .week-modal .week-list-day { border: 1px solid #d5def0; border-radius: 12px; padding: 12px; background: #fdfdfd; display: grid; gap: 10px; }
        .week-modal .week-list-day.is-today { border-color: #ffd28a; background: #ffe0b2; }
        .week-modal .week-list-day.is-today .week-list-day-name,
        .week-modal .week-list-day.is-today .week-list-day-date { color: #ef6c00; }
        .week-modal .week-list-day-header { display: flex; align-items: center; justify-content: space-between; gap: 10px; font-weight: 700; color: #f9f9f9; background: #595959; margin: -12px -12px 8px; padding: 10px 12px; border-radius: 12px 12px 0 0; }
        .week-modal .week-list-day-name { text-transform: uppercase; letter-spacing: 0.04em; font-size: 0.8rem; color: #f9f9f9; }
        .week-modal .week-list-day-date { color: #f9f9f9; }
        .week-modal .week-list-sections { display: grid; gap: 10px; }
        .week-modal .week-list-section-title { font-weight: 700; color: #37474f; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.04em; }
        .week-modal .week-list-items { display: grid; gap: 8px; }
        .week-modal .week-list-empty { color: #9e9e9e; font-size: 0.9rem; text-align: center; }
        .week-modal .calendar-section { display: grid; gap: 6px; }
        .week-modal .calendar-section-title { font-weight: 700; color: #37474f; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.04em; }
        .week-modal .calendar-task-item { border: 1px solid #ffd28a; background: #fff7e6; border-radius: 10px; padding: 8px; text-align: left; cursor: pointer; display: grid; gap: 4px; font-size: 0.9rem; }
        .week-modal .calendar-task-item:hover { background: #ffe9c6; }
        .week-modal .calendar-task-header { display: flex; flex-direction: column; align-items: flex-start; gap: 6px; }
        .week-modal .task-week-list .calendar-task-header { flex-direction: row; align-items: center; flex-wrap: wrap; }
        .week-modal .calendar-task-type-icon { font-size: 1rem; }
        .week-modal .calendar-task-type-icon.is-task { color: #ef6c00; }
        .week-modal .calendar-task-type-icon.is-routine { color: #0d47a1; }
        .week-modal .task-week-list .calendar-task-type-icon { align-self: center; }
        .week-modal .calendar-task-title-wrap { display: inline-flex; align-items: center; gap: 6px; flex: 1; min-width: 0; }
        .week-modal .calendar-task-badge { display: inline-flex; align-items: center; gap: 4px; width: fit-content; padding: 2px 8px; border-radius: 999px; font-size: 0.7rem; font-weight: 700; letter-spacing: 0.02em; text-transform: uppercase; }
        .week-modal .calendar-task-badge.overdue { background: #d9534f; color: #fff; }
        .week-modal .calendar-task-badge.completed { background: #4caf50; color: #fff; }
        .week-modal .calendar-task-badge.compact { justify-content: center; width: 20px; height: 20px; padding: 0; border-radius: 50%; font-size: 0.65rem; }
        .week-modal .calendar-task-badge-group { display: inline-flex; align-items: center; gap: 5px; }
        .week-modal .calendar-task-title { font-weight: 700; color: #3e2723; }
        .week-modal .calendar-task-points { color: #fff; font-size: 0.7rem; font-weight: 700; border-radius: 50px; background-color: #4caf50; padding: 2px 8px; }
        .week-modal .calendar-task-meta { color: #919191; font-size: 0.85rem; }
        .week-modal .calendar-day-empty { color: #9e9e9e; font-size: 0.85rem; text-align: center; padding: 8px 0; }
        .week-modal .calendar-empty { display: none; text-align: center; color: #9e9e9e; font-weight: 600; padding: 18px; }
        .week-modal .calendar-empty.active { display: block; }
        .help-modal { position: fixed; inset: 0; background: rgba(0,0,0,0.55); display: none; align-items: center; justify-content: center; z-index: 3300; padding: 14px; }
        .help-modal.open { display: flex; }
        .help-card { background: #fff; border-radius: 12px; max-width: 720px; width: min(720px, 100%); max-height: 85vh; overflow: hidden; box-shadow: 0 14px 36px rgba(0,0,0,0.25); display: grid; grid-template-rows: auto 1fr; }
        .help-card header { display: flex; align-items: center; justify-content: space-between; padding: 12px 16px; border-bottom: 1px solid #e0e0e0; }
        .help-card h2 { margin: 0; font-size: 1.1rem; }
        .help-close { background: transparent; border: none; font-size: 1.3rem; cursor: pointer; color: #555; }
        .help-body { padding: 12px 16px 16px; overflow-y: auto; display: grid; gap: 12px; }
        .help-section h3 { margin: 0 0 6px; font-size: 1rem; color: #37474f; }
        .help-section ul { margin: 0; padding-left: 18px; display: grid; gap: 6px; color: #455a64; }
        .week-day-group { margin-bottom: 12px; }
        .week-day-title { font-weight: 700; color: #f9f9f9; margin-bottom: 6px; padding: 6px 10px; border-radius: 8px; background: linear-gradient(135deg, #5a98e2, #84b3ed); }
        .week-day-list { list-style: none; padding: 0; margin: 0; display: grid; gap: 8px; }
        body.modal-open { overflow: hidden; }
        .child-history-modal { position: fixed; inset: 0; background: rgba(0,0,0,0.45); display: none; align-items: center; justify-content: center; z-index: 4200; padding: 14px; }
        .child-history-modal.open { display: flex; }
        .child-history-card { background: #fff; border-radius: 12px; max-width: 620px; width: min(620px, 100%); max-height: 92vh; overflow: hidden; box-shadow: 0 12px 32px rgba(0,0,0,0.25); display: flex; flex-direction: column; }
        .child-history-card header { display: flex; align-items: center; justify-content: space-between; padding: 12px 16px; border-bottom: 1px solid #e0e0e0; }
        .child-history-card h2 { margin: 0; font-size: 1.1rem; }
        .child-history-close { background: transparent; border: none; font-size: 1.3rem; cursor: pointer; color: #555; }
        .child-history-body { padding: 12px 16px 16px; overflow-y: auto; text-align: left; flex: 1; min-height: 0; }
        .child-history-day { margin-top: 12px; }
        .child-history-day-title { font-weight: 700; color: #5d4037; margin-bottom: 6px; }
        .child-history-list { list-style: none; padding: 0; margin: 0; display: grid; gap: 8px; }
        .child-history-item { background: #fff; border: 1px solid #eceff4; border-radius: 14px; padding: 12px; display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; }
        .child-history-item-title { font-weight: 700; color: #3e2723; }
        .child-history-item-meta { color: #6d4c41; font-size: 0.95rem; }
        .child-history-item-points { background: #e8f5e9; color: #2e7d32; padding: 4px 10px; border-radius: 999px; font-weight: 700; white-space: nowrap; }
        .child-history-item-points.is-negative { background: #ffebee; color: #d32f2f; }
        .adjust-modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.55); display: none; align-items: center; justify-content: center; z-index: 3000; padding: 12px; }
        .adjust-modal-backdrop.open { display: flex; }
        .adjust-modal { background: #fff; border-radius: 10px; padding: 18px; max-width: 420px; width: min(420px, 100%); max-height: 92vh; box-shadow: 0 14px 36px rgba(0,0,0,0.25); display: flex; flex-direction: column; gap: 12px; overflow: hidden; }
        .adjust-modal header { display: flex; justify-content: space-between; align-items: center; }
        .adjust-modal h3 { margin: 0; font-size: 1.1rem; }
        .adjust-modal-close { background: transparent; border: none; font-size: 1.4rem; cursor: pointer; }
        .adjust-control { display: grid; grid-template-columns: auto 1fr auto; gap: 8px; align-items: center; }
        .adjust-control button { width: 100%; height: 100%; font-size: 1.2rem; }
        .adjust-control input[type="number"] { width: 100%; padding: 10px; font-size: 1rem; text-align: center; }
        .adjust-control input[type="number"]::-webkit-outer-spin-button,
        .adjust-control input[type="number"]::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
        .adjust-control input[type="number"] { -moz-appearance: textfield; }
        .adjust-history { background: #f9f9f9; border: 1px solid #e0e0e0; border-radius: 8px; padding: 10px; max-height: 180px; min-height: 110px; overflow-y: auto; }
        .adjust-history h4 { margin: 0 0 8px; font-size: 0.95rem; }
        .adjust-history ul { list-style: none; padding: 0; margin: 0; display: grid; gap: 8px; }
        .adjust-history li { display: grid; gap: 2px; font-size: 0.9rem; }
        .adjust-history .delta { font-weight: 700; display: inline-flex; align-items: center; padding: 2px 8px; border-radius: 999px; background: #e8f5e9; color: #2e7d32; width: fit-content; }
        .adjust-history .delta.positive { color: #2e7d32; }
        .adjust-history .delta.negative { color: #c62828; background: #ffebee; }
        .adjust-history .meta { color: #666; font-size: 0.85rem; }
        .adjust-modal-header { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 0; }
        .adjust-modal-back { border: none; background: transparent; color: #424242; font-size: 1.1rem; cursor: pointer; display: none; }
        .adjust-modal-body { display: grid; gap: 14px; overflow-y: auto; flex: 1; min-height: 0; }
        .adjust-child-card { display: flex; align-items: center; gap: 12px; padding: 12px; border-radius: 16px; background: #fff; border: 1px solid #eceff4; box-shadow: 0 8px 18px rgba(0,0,0,0.08); }
        .adjust-child-avatar { width: 56px; height: 56px; border-radius: 50%; object-fit: cover; box-shadow: 0 2px 6px rgba(0,0,0,0.15); }
        .adjust-child-name { font-weight: 700; color: #263238; }
        .adjust-form { display: grid; gap: 12px; }
        .adjust-points-panel { background: #fff; border: 1px solid #eceff4; border-radius: 16px; padding: 12px; display: grid; gap: 10px; box-shadow: 0 8px 18px rgba(0,0,0,0.06); }
        .adjust-current-points { display: inline-flex; align-items: center; justify-content: center; gap: 6px; font-weight: 700; color: #2e7d32; font-size: 1.05rem; }
        .adjust-current-points i { color: #4caf50; }
        .adjust-control { display: grid; grid-template-columns: 56px 1fr 56px; border-radius: 12px; overflow: hidden; border: 1px solid #e0e0e0; background: #f5f5f5; }
        .adjust-control input[type="number"] { border: none; background: #fff; font-size: 1.2rem; text-align: center; padding: 10px; }
        .adjust-control input[type="number"]:focus { outline: none; }
        .adjust-step { border: none; color: #fff; font-size: 1.4rem; font-weight: 700; cursor: pointer; }
        .adjust-step-plus { background: #4caf50; }
        .adjust-step-minus { background: #ff9800; }
        .adjust-reason label { font-weight: 700; }
        .points-adjust-actions { display: flex; gap: 6px; }
        .adjust-confirm { flex: 1; width: 100%; background: linear-gradient(90deg, #f39c12, #ffa726); }
        .adjust-cancel { flex: 1; background: transparent; border: none; color: #757575; font-weight: 600; cursor: pointer; padding: 6px; }
        .child-history-header { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 12px 16px; border-bottom: 1px solid #e0e0e0; }
        .child-history-back { border: none; background: transparent; color: #424242; font-size: 1.1rem; cursor: pointer; display: none; }
        .child-history-hero { display: flex; align-items: center; gap: 12px; padding: 12px; margin-bottom: 10px; border-radius: 16px; background: #fff; border: 1px solid #eceff4; box-shadow: 0 8px 18px rgba(0,0,0,0.08); }
        .child-history-avatar { width: 56px; height: 56px; border-radius: 50%; object-fit: cover; box-shadow: 0 2px 6px rgba(0,0,0,0.15); }
        .child-history-name { font-weight: 700; color: #263238; }
        .child-history-points { display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 999px; background: #e8f5e9; color: #2e7d32; font-weight: 700; margin-top: 6px; }
        .child-history-filters { display: inline-flex; gap: 6px; padding: 10px; border-radius: 16px; border: 1px solid #eceff4; background: #fff; box-shadow: 0 8px 18px rgba(0,0,0,0.06); }
        .history-filter { border: none; background: transparent; color: #616161; font-weight: 600; padding: 6px 12px; border-radius: 10px; cursor: pointer; }
        .history-filter.active { background: #6e9bd5; color: #fff; }
        .child-history-empty { color: #9e9e9e; font-weight: 600; text-align: center; }
        .child-history-timeline { display: grid; gap: 12px; }
        .child-history-day { display: grid; gap: 10px; }
        .child-history-day-title { font-weight: 700; color: #8d6e63; }
        .child-history-item { background: #fff; border-radius: 14px; padding: 12px; border: 1px solid #eceff4; display: flex; gap: 12px; align-items: flex-start; justify-content: space-between; }
        .child-history-item-points { background: #e8f5e9; color: #2e7d32; padding: 4px 10px; border-radius: 999px; font-weight: 700; white-space: nowrap; }
        .child-history-item-points.is-negative { background: #ffebee; color: #d32f2f; }
        .adjust-modal .button { margin: 0; }

        @media (max-width: 768px) {
            .adjust-modal-backdrop,
            .child-history-modal { padding: 0; align-items: stretch; }
            .adjust-modal,
            .child-history-card { max-width: none; width: 100%; height: 100%; min-height: 100vh; border-radius: 0; box-shadow: none; background: #f6f3f0; display: flex; flex-direction: column; }
            
            .adjust-modal { padding: 0; }
            .adjust-modal-header,
            .child-history-header { padding: 12px 16px; background: #f6f3f0; }
            .adjust-modal-back,
            .child-history-back { display: inline-flex; }
            .adjust-modal-close,
            .child-history-close { display: none; }
            .adjust-modal-body,
            .child-history-body { padding: 12px 16px 96px; overflow-y: auto; flex: 1; min-height: 0; }
            .child-history-filters { width: 100%; justify-content: space-between; }
            .history-filter { flex: 1; text-align: center; }
            .adjust-history { background: #fff; border-color: #eceff4; border-radius: 16px; box-shadow: 0 8px 18px rgba(0,0,0,0.06); max-height: 360px; min-height: 160px; }
            .adjust-history li { padding-bottom: 6px; border-bottom: 1px solid #f0f0f0; }
            .adjust-history li:last-child { border-bottom: none; padding-bottom: 0; }
        }
        .button { padding: 10px 20px; margin: 5px; background-color: #4caf50; color: white; border: none; border-radius: 5px; cursor: pointer; text-decoration: none; display: inline-block; font-size: 16px; min-height: 44px; }
        .approve-button { background-color: #4caf50; }
        .reject-button { background-color: #f44336; }
        .reward-edit-form { display: grid; gap: 10px; }
        .reward-edit-actions { display: flex; flex-wrap: wrap; gap: 8px; }
        .reward-edit-actions .button { flex: 1 1 140px; text-align: center; }
        .reward-delete { background-color: #d32f2f; }
        .reward-item.highlight { border: 2px solid #f9a825; box-shadow: 0 0 0 3px rgba(249,168,37,0.2); }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; }
        .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 8px; }
        .awaiting-label { font-style: italic; color: #bf360c; margin-bottom: 8px; }
        .inline-form { margin-top: 6px; }
        .inline-form .button { width: 100%; }
        @media (min-width: 600px) {
            .inline-form { display: inline-block; }
            .inline-form .button { width: auto; }
        }
        /* Manage Family Styles - Mobile Responsive, Autism-Friendly Wizard */
        .manage-family { background: #f5f7fb; padding: 8px; border: 1px solid #d5def0; border-radius: 12px; box-shadow: 0 6px 14px rgba(0,0,0,0.08); }
        .family-form { display: none; } /* JS toggle for wizard */
        .family-form.active { display: block; }
        .avatar-preview { width: 50px; height: 50px; border-radius: 50%; margin: 5px; cursor: pointer; }
        .avatar-options { display: flex; flex-wrap: wrap; justify-content: center; gap: 10px; }
        .avatar-option { width: 60px; height: 60px; border-radius: 50%; cursor: pointer; border: 2px solid #ddd; }
        .avatar-option.selected { border-color: #4caf50; }
        .upload-preview { max-width: 100px; max-height: 100px; border-radius: 50%; }
        .mother-badge { background: #e91e63; color: white; padding: 2px 6px; border-radius: 3px; font-size: 0.8em; }
        .father-badge { background: #2196f3; color: white; padding: 2px 6px; border-radius: 3px; font-size: 0.8em; }
        .routine-completion-section { margin-top: 20px; background: #ffffff; border-radius: 8px; padding: 20px; box-shadow: 0 2px 6px rgba(0,0,0,0.08); }
        .routine-completion-section h2 { margin-top: 0; }
        .routine-completion-list { display: grid; gap: 12px; margin-top: 12px; }
        .routine-completion-card { border: 1px solid #e3e7eb; border-radius: 10px; overflow: hidden; background: #fff; }
        .routine-completion-card > summary { padding: 12px 16px; cursor: pointer; display: flex; justify-content: space-between; align-items: center; gap: 12px; list-style: none; background: #f5f8fb; }
        .routine-completion-card > summary::-webkit-details-marker { display: none; }
        .completion-summary { display: grid; gap: 4px; }
        .completion-title { font-weight: 700; color: #0d47a1; }
        .completion-child { color: #455a64; font-size: 0.9rem; }
        .completion-meta { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; color: #546e7a; font-size: 0.9rem; }
        .completion-badge { padding: 2px 8px; border-radius: 999px; font-size: 0.75rem; font-weight: 700; }
        .completion-badge.child { background: #e3f2fd; color: #0d47a1; }
        .completion-badge.parent { background: #ffe0b2; color: #bf360c; }
        .completion-body { padding: 12px 16px; display: grid; gap: 12px; }
        .completion-times { display: grid; gap: 6px; color: #37474f; }
        .completion-note { color: #bf360c; font-weight: 600; }
        .completion-task-list { display: grid; gap: 8px; }
        .completion-task-row { display: grid; gap: 6px; padding: 10px; border: 1px solid #e9edf2; border-radius: 8px; background: #f9fbfd; }
        .completion-task-header { display: flex; justify-content: space-between; align-items: center; gap: 10px; }
        .completion-task-title { font-weight: 600; color: #263238; }
        .completion-task-meta { font-size: 0.9rem; color: #37474f; display: flex; gap: 10px; flex-wrap: wrap; }
        .completion-task-meta strong { color: #455a64; }
        .completion-task-empty { color: #666; font-style: italic; }
        .routine-analytics { margin-top: 20px; background: #fafafa; border-radius: 8px; padding: 20px; box-shadow: 0 2px 6px rgba(0,0,0,0.08); }
        .routine-analytics h2 { margin-top: 0; }
        .overtime-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 16px; margin-top: 16px; }
        .overtime-card { background: #ffffff; border-radius: 8px; padding: 16px; box-shadow: 0 1px 4px rgba(0,0,0,0.05); }
        .overtime-card h3 { margin-top: 0; font-size: 1.05em; }
        .overtime-table { width: 100%; border-collapse: collapse; margin-top: 16px; font-size: 0.95em; }
        .overtime-table th, .overtime-table td { border: 1px solid #e0e0e0; padding: 8px; text-align: left; }
        .overtime-table th { background: #f0f4f8; font-weight: 600; }
        .overtime-empty { font-style: italic; color: #666; margin-top: 12px; }
        .routine-log-link { background: none; border: none; color: #1565c0; cursor: pointer; padding: 0; font-weight: 700; text-decoration: underline; }
        .routine-log-link:hover { color: #0d47a1; }
        .overtime-accordion { display: grid; gap: 12px; margin-top: 12px; }
        .overtime-date { border: 1px solid #e3e7eb; border-radius: 10px; overflow: hidden; background: #fff; box-shadow: 0 2px 6px rgba(0,0,0,0.05); }
        .overtime-date > summary { padding: 12px 14px; cursor: pointer; display: flex; justify-content: space-between; align-items: center; gap: 10px; font-weight: 700; background: #f5f8fb; list-style: none; }
        .overtime-date > summary::-webkit-details-marker { display: none; }
        .overtime-date-count { color: #607d8b; font-weight: 600; font-size: 0.92rem; }
        .overtime-routine { border-top: 1px solid #eef1f4; }
        .overtime-routine > summary { padding: 12px 16px; cursor: pointer; display: flex; justify-content: space-between; align-items: center; gap: 10px; font-weight: 700; color: #0d47a1; list-style: none; }
        .overtime-routine > summary::-webkit-details-marker { display: none; }
        .overtime-routine-count { color: #455a64; font-size: 0.9rem; font-weight: 600; }
        .overtime-card-list { display: grid; gap: 10px; padding: 0 14px 14px; }
        .overtime-card-row { background: linear-gradient(145deg, #ffffff, #f7f9fb); border: 1px solid #e3e7eb; border-radius: 10px; padding: 12px; display: grid; gap: 6px; box-shadow: 0 2px 6px rgba(0,0,0,0.05); }
        .ot-row-header { display: flex; justify-content: space-between; align-items: center; gap: 10px; }
        .ot-task { font-weight: 700; color: #0d47a1; }
        .ot-time { color: #546e7a; font-size: 0.9rem; }
        .ot-meta { font-size: 0.92rem; color: #37474f; display: flex; gap: 10px; flex-wrap: wrap; }
        .ot-meta strong { color: #455a64; }
        .ot-overtime { color: #c62828; font-weight: 700; }
        .routine-log-modal { position: fixed; inset: 0; background: rgba(0,0,0,0.55); display: none; align-items: center; justify-content: center; z-index: 3000; padding: 16px; }
        .routine-log-modal.active { display: flex; }
        .routine-log-dialog { background: #fff; border-radius: 12px; max-width: 640px; width: min(640px, 100%); max-height: 80vh; overflow: hidden; box-shadow: 0 18px 36px rgba(0,0,0,0.25); display: grid; grid-template-rows: auto 1fr; }
        .routine-log-header { display: flex; align-items: center; justify-content: space-between; padding: 14px 16px; border-bottom: 1px solid #e0e0e0; }
        .routine-log-title { margin: 0; font-size: 1.1rem; font-weight: 700; color: #0d47a1; }
        .routine-log-close { border: none; background: transparent; font-size: 1.3rem; cursor: pointer; color: #455a64; }
        .routine-log-body { padding: 14px 16px; overflow-y: auto; display: grid; gap: 10px; }
        .routine-log-empty { color: #666; font-style: italic; }
        .routine-log-item { border: 1px solid #e3e7eb; border-radius: 10px; padding: 10px; display: grid; gap: 6px; background: #f9fbfd; }
        .routine-log-item .meta { color: #546e7a; font-size: 0.9rem; display: flex; flex-wrap: wrap; gap: 10px; }
        .routine-log-item .overtime { color: #c62828; font-weight: 700; }
        @media (max-width: 900px) {
            .child-info-card { grid-template-columns: minmax(160px, max-content) minmax(0, 1fr) minmax(0, 1fr); column-gap: 20px; row-gap: 16px; align-items: start; }
            .child-info-left { display: flex; flex-direction: column; gap: 18px; grid-column: 1; }
            .child-info-body { grid-column: 2; }
            .child-schedule-card { grid-column: 3; }
        }
        @media (max-width: 768px) {
            .overtime-date > summary, .overtime-routine > summary { padding: 12px; }
            .ot-row-header { flex-direction: column; align-items: flex-start; }
            .routine-completion-card > summary { flex-direction: column; align-items: flex-start; }
            .completion-task-header { flex-direction: column; align-items: flex-start; }
        }
        @media (max-width: 768px) {
            .manage-family { padding: 8px; }
            .button { width: 100%; }
            .child-info-card { grid-template-columns: 1fr; }
            .child-info-left,
            .child-info-body,
            .child-schedule-card { grid-column: auto; }
            .child-info-content { display: block; }
            .child-info-header img { width: 56px; height: 56px; }
            .child-info-body { flex-direction: column; }
            .points-progress-container { width: 100%; height: 140px; }
        }
        .no-scroll { overflow: hidden; }
        .parent-photo-modal { position: fixed; inset: 0; background: rgba(0,0,0,0.5); display: none; align-items: center; justify-content: center; z-index: 4200; padding: 14px; }
        .parent-photo-modal.open { display: flex; }
        .parent-photo-card { background: #fff; border-radius: 12px; max-width: 720px; width: min(720px, 100%); max-height: 85vh; overflow: hidden; box-shadow: 0 12px 32px rgba(0,0,0,0.25); display: grid; grid-template-rows: auto 1fr; }
        .parent-photo-card header { display: flex; align-items: center; justify-content: space-between; padding: 12px 14px; border-bottom: 1px solid #e0e0e0; }
        .parent-photo-close { background: transparent; border: none; font-size: 1.3rem; cursor: pointer; color: #555; }
        .parent-photo-body { padding: 12px 14px 16px; }
        .parent-photo-preview { width: 100%; max-height: 70vh; object-fit: contain; border-radius: 10px; }
        .parent-trash-button { border: none; background: transparent; cursor: pointer; font-size: 1.1rem; padding: 4px; color: #d32f2f; }
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
        .nav-family-button { border: none; background: transparent; }
        .nav-mobile-bottom { display: none; gap: 6px; padding: 10px 12px; border-top: 1px solid #e0e0e0; background: #fff; position: fixed; left: 0; right: 0; bottom: 0; z-index: 900; }
        .nav-mobile-bottom .nav-mobile-link { flex: 1; }
        body.show-mobile-nav .nav-mobile-bottom { z-index: 5200; }
        @media (max-width: 768px) {
            .nav-links { display: none; }
            .nav-mobile-bottom { display: flex; justify-content: space-between; }
            body { padding-bottom: 72px; }
        }
        .family-modal { position: fixed; inset: 0; background: rgba(0,0,0,0.55); display: none; align-items: center; justify-content: center; z-index: 3600; padding: 14px; }
        .family-modal.open { display: flex; }
        .family-modal-card { background: #fff; border-radius: 12px; max-width: 980px; width: min(980px, 100%); max-height: 85vh; overflow: hidden; box-shadow: 0 12px 32px rgba(0,0,0,0.25); display: grid; grid-template-rows: auto 1fr; }
        .family-modal-card header { display: flex; align-items: center; justify-content: space-between; padding: 12px 14px; border-bottom: 1px solid #e0e0e0; }
        .family-modal-close { background: transparent; border: none; font-size: 1.3rem; cursor: pointer; color: #555; }
        .family-modal-body { padding: 14px; overflow-y: auto; display: grid; gap: 16px; }
        .family-section { background: #f5f7fb; padding: 8px; border: 1px solid #d5def0; border-radius: 12px; box-shadow: 0 6px 14px rgba(0,0,0,0.08); }
        .child-remove-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.45); display: none; align-items: center; justify-content: center; z-index: 4000; padding: 16px; }
        .child-remove-backdrop.open { display: flex; }
        .child-remove-modal { background: #fff; border-radius: 12px; max-width: 420px; width: 100%; padding: 18px; box-shadow: 0 16px 38px rgba(0,0,0,0.25); display: grid; gap: 14px; }
        .child-remove-modal header { display: flex; justify-content: space-between; align-items: center; }
        .child-remove-modal .actions { display: grid; gap: 10px; }
        .child-remove-modal .actions .button { width: 100%; }
        .child-remove-modal .subtext { color: #555; font-size: 0.95rem; }
        .member-remove-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.45); display: none; align-items: center; justify-content: center; z-index: 4010; padding: 16px; }
        .member-remove-backdrop.open { display: flex; }
        .member-remove-modal { background: #fff; border-radius: 12px; max-width: 420px; width: 100%; padding: 18px; box-shadow: 0 16px 38px rgba(0,0,0,0.25); display: grid; gap: 14px; }
        .member-remove-modal header { display: flex; justify-content: space-between; align-items: center; }
        .member-remove-modal .actions { display: grid; gap: 10px; }
        .member-remove-modal .actions .button { width: 100%; }
        .member-remove-modal .subtext { color: #555; font-size: 0.95rem; }
    </style>
    <script>
        window.RoutineOvertimeByRoutine = <?php echo json_encode($overtimeLogsByRoutine, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    </script>
    <script>
        // JS for Manage Family Wizard (step-by-step)
        document.addEventListener('DOMContentLoaded', function() {
            const addChildBtn = document.getElementById('add-child-btn');
            const addCaregiverBtn = document.getElementById('add-caregiver-btn');
            const childForm = document.getElementById('child-form');
            const caregiverForm = document.getElementById('caregiver-form');
            const avatarPreview = document.getElementById('avatar-preview');
            const avatarInput = document.getElementById('avatar');

            if (addChildBtn && childForm) {
                addChildBtn.addEventListener('click', () => {
                    childForm.classList.add('active');
                    if (caregiverForm) caregiverForm.classList.remove('active');
                });
            }

            if (addCaregiverBtn && caregiverForm) {
                addCaregiverBtn.addEventListener('click', () => {
                    caregiverForm.classList.add('active');
                    if (childForm) childForm.classList.remove('active');
                });
            }

            if (avatarPreview && avatarInput) {
                const avatarOptions = document.querySelectorAll('.avatar-option');

                avatarOptions.forEach(option => {
                    option.addEventListener('click', () => {
                        avatarOptions.forEach(opt => opt.classList.remove('selected'));
                        option.classList.add('selected');
                        avatarPreview.src = option.dataset.avatar;
                        avatarInput.value = option.dataset.avatar;
                    });
                });

                const avatarUpload = document.getElementById('avatar-upload');
                if (avatarUpload) {
                    avatarUpload.addEventListener('change', function(e) {
                        const file = e.target.files[0];
                        if (file) {
                            const reader = new FileReader();
                            reader.onload = function(evt) {
                                avatarPreview.src = evt.target.result;
                            };
                            reader.readAsDataURL(file);
                        }
                    });
                }
            }

            // Animate points numbers
            const pointEls = document.querySelectorAll('.points-number');
            pointEls.forEach(el => {
                const valueEl = el.querySelector('.points-number-value');
                if (!valueEl) return;
                const target = parseInt(el.dataset.points, 10) || 0;
                let current = 0;
                const duration = 800;
                const start = performance.now();
                const step = (now) => {
                    const progress = Math.min(1, (now - start) / duration);
                    current = Math.floor(progress * target);
                    valueEl.textContent = `${current}`;
                    if (progress < 1) {
                        requestAnimationFrame(step);
                    } else {
                        valueEl.textContent = `${target}`;
                    }
                };
                requestAnimationFrame(step);
            });

            const parentPhotoThumbs = document.querySelectorAll('[data-parent-photo-src]');
            const parentPhotoModal = document.querySelector('[data-parent-photo-modal]');
            const parentPhotoClose = parentPhotoModal ? parentPhotoModal.querySelector('[data-parent-photo-close]') : null;
            const parentPhotoPreview = parentPhotoModal ? parentPhotoModal.querySelector('[data-parent-photo-preview]') : null;
            const openParentPhotoModal = (src) => {
                if (!parentPhotoModal || !parentPhotoPreview) return;
                parentPhotoPreview.src = src;
                parentPhotoModal.classList.add('open');
                document.body.classList.add('no-scroll');
            };
            const closeParentPhotoModal = () => {
                if (!parentPhotoModal) return;
                parentPhotoModal.classList.remove('open');
                document.body.classList.remove('no-scroll');
                if (parentPhotoPreview) {
                    parentPhotoPreview.src = '';
                }
            };
            if (parentPhotoThumbs.length && parentPhotoModal) {
                parentPhotoThumbs.forEach((thumb) => {
                    thumb.addEventListener('click', () => {
                        const src = thumb.dataset.parentPhotoSrc;
                        if (src) {
                            openParentPhotoModal(src);
                        }
                    });
                });
                if (parentPhotoClose) parentPhotoClose.addEventListener('click', closeParentPhotoModal);
                parentPhotoModal.addEventListener('click', (e) => { if (e.target === parentPhotoModal) closeParentPhotoModal(); });
                document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeParentPhotoModal(); });
            }

            const params = new URLSearchParams(window.location.search);
            const highlightReward = params.get('highlight_reward');
            if (highlightReward) {
                const rewardCard = document.getElementById('reward-' + highlightReward);
                if (rewardCard) {
                    rewardCard.classList.add('highlight');
                    rewardCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            }
            const highlightRedeemed = params.get('highlight_redeemed');
            if (highlightRedeemed) {
                const redeemedCard = document.getElementById('redeemed-reward-' + highlightRedeemed);
                if (redeemedCard) {
                    redeemedCard.classList.add('highlight');
                    redeemedCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            }
            const overtimeRoutineParam = params.get('overtime_routine');
            if (overtimeRoutineParam) {
                const target = document.querySelector(`.overtime-routine[data-routine-id="${overtimeRoutineParam}"]`);
                if (target) {
                    target.open = true;
                    const dateWrapper = target.closest('.overtime-date');
                    if (dateWrapper) {
                        dateWrapper.open = true;
                    }
                    const overtimeSection = document.getElementById('overtime-section');
                    if (overtimeSection && typeof overtimeSection.scrollIntoView === 'function') {
                        overtimeSection.scrollIntoView({ behavior: 'smooth' });
                    }
                }
            }

            const historyButtons = document.querySelectorAll('[data-child-history-open]');
            const historyModals = document.querySelectorAll('[data-child-history-modal]');
            const applyHistoryFilter = (modal, filter) => {
                const items = Array.from(modal.querySelectorAll('[data-history-item]'));
                const groups = Array.from(modal.querySelectorAll('[data-history-day]'));
                if (!items.length) {
                    const empty = modal.querySelector('[data-history-empty]');
                    if (empty) {
                        empty.style.display = 'none';
                    }
                    return;
                }
                let anyVisible = false;
                items.forEach(item => {
                    const type = (item.dataset.historyType || '').toLowerCase();
                    const show = filter === 'all' ? true : type === filter;
                    item.style.display = show ? '' : 'none';
                    item.dataset.hidden = show ? '0' : '1';
                    if (show) {
                        anyVisible = true;
                    }
                });
                groups.forEach(group => {
                    const groupItems = Array.from(group.querySelectorAll('[data-history-item]'));
                    const hasVisible = groupItems.some(item => item.dataset.hidden !== '1');
                    group.style.display = hasVisible ? '' : 'none';
                });
                const empty = modal.querySelector('[data-history-empty]');
                if (empty) {
                    empty.style.display = anyVisible ? 'none' : 'block';
                }
            };
            historyButtons.forEach((btn) => {
                btn.addEventListener('click', () => {
                    const childId = btn.dataset.childHistoryId;
                    const modal = document.querySelector(`[data-child-history-modal][data-child-history-id="${childId}"]`);
                    if (!modal) return;
                    modal.classList.add('open');
                    document.body.classList.add('no-scroll');
                    document.body.classList.add('show-mobile-nav');
                    const filterButtons = Array.from(modal.querySelectorAll('[data-history-filter]'));
                    filterButtons.forEach(button => {
                        button.classList.toggle('active', (button.dataset.historyFilter || 'all') === 'all');
                    });
                    applyHistoryFilter(modal, 'all');
                });
            });
            historyModals.forEach((modal) => {
                const closeButtons = modal.querySelectorAll('[data-child-history-close]');
                const filterButtons = Array.from(modal.querySelectorAll('[data-history-filter]'));
                const closeModal = () => {
                    modal.classList.remove('open');
                    document.body.classList.remove('no-scroll');
                    document.body.classList.remove('show-mobile-nav');
                };
                closeButtons.forEach(btn => btn.addEventListener('click', closeModal));
                modal.addEventListener('click', (e) => { if (e.target === modal) closeModal(); });
                if (filterButtons.length) {
                    filterButtons.forEach((button) => {
                        button.addEventListener('click', () => {
                            filterButtons.forEach(btn => btn.classList.toggle('active', btn === button));
                            const filter = button.dataset.historyFilter || 'all';
                            applyHistoryFilter(modal, filter);
                        });
                    });
                    applyHistoryFilter(modal, 'all');
                }
            });
            document.addEventListener('keydown', (e) => {
                if (e.key !== 'Escape') return;
                historyModals.forEach((modal) => {
                    if (modal.classList.contains('open')) {
                        modal.classList.remove('open');
                        document.body.classList.remove('no-scroll');
                        document.body.classList.remove('show-mobile-nav');
                    }
                });
            });

            const weekModal = document.querySelector('[data-week-modal]');
            const weekModalBody = weekModal ? weekModal.querySelector('[data-week-modal-body]') : null;
            const weekModalTitle = weekModal ? weekModal.querySelector('#week-modal-title') : null;
            const weekModalClose = weekModal ? weekModal.querySelector('[data-week-modal-close]') : null;
            const startOfWeek = (date) => {
                const d = new Date(date.getFullYear(), date.getMonth(), date.getDate());
                const day = d.getDay();
                const diff = (day + 6) % 7;
                d.setDate(d.getDate() - diff);
                d.setHours(0, 0, 0, 0);
                return d;
            };
            const addDays = (date, days) => {
                const d = new Date(date.getTime());
                d.setDate(d.getDate() + days);
                d.setHours(0, 0, 0, 0);
                return d;
            };
            const formatDateKey = (date) => {
                const year = date.getFullYear();
                const month = String(date.getMonth() + 1).padStart(2, '0');
                const day = String(date.getDate()).padStart(2, '0');
                return `${year}-${month}-${day}`;
            };
            const formatWeekRange = (startDate) => {
                const endDate = addDays(startDate, 6);
                const options = { month: 'short', day: 'numeric' };
                const startLabel = startDate.toLocaleDateString(undefined, options);
                const endLabel = endDate.toLocaleDateString(undefined, options);
                return `${startLabel} - ${endLabel}`;
            };
            const buildWeekModalSkeleton = (childName) => `
                <section class="task-calendar-section week-modal-calendar">
                    <div class="task-calendar-card">
                        <div class="calendar-header">
                            <div>
                                <h2>Weekly Calendar</h2>
                                <p class="calendar-subtitle">Tasks and routines for ${childName}.</p>
                            </div>
                            <div class="calendar-nav">
                                <div class="calendar-view-toggle" role="group" aria-label="Calendar view">
                                    <button type="button" class="calendar-view-button active" data-calendar-view="calendar" aria-pressed="true" title="Calendar view">
                                        <i class="fa-solid fa-calendar-days"></i>
                                    </button>
                                    <button type="button" class="calendar-view-button" data-calendar-view="list" aria-pressed="false" title="List view">
                                        <i class="fa-solid fa-list"></i>
                                    </button>
                                </div>
                                <button type="button" class="calendar-nav-button" data-week-nav="-1">Previous Week</button>
                                <div class="calendar-range" data-week-range></div>
                                <button type="button" class="calendar-nav-button" data-week-nav="1">Next Week</button>
                            </div>
                        </div>
                        <div class="task-week-calendar" data-week-calendar>
                            <div class="task-week-scroll">
                                <div class="week-days week-days-header" data-week-days></div>
                                <div class="week-grid" data-week-grid></div>
                            </div>
                            <div class="calendar-empty" data-calendar-empty>No tasks or routines for this week.</div>
                        </div>
                        <div class="task-week-list" data-week-list></div>
                    </div>
                </section>
            `;
            const openWeekModal = (btn) => {
                if (!weekModal || !weekModalBody) return;
                const childName = btn.getAttribute('data-child-name') || 'Child';
                const childId = parseInt(btn.getAttribute('data-child-id'), 10);
                const scheduleRaw = btn.getAttribute('data-week-schedule') || '{}';
                let schedule = {};
                try {
                    schedule = JSON.parse(scheduleRaw);
                } catch (e) {
                    schedule = {};
                }
                if (weekModalTitle) {
                    weekModalTitle.textContent = childName + ' - Week Schedule';
                }
                weekModalBody.innerHTML = buildWeekModalSkeleton(childName);
                const calendarWrap = weekModalBody.querySelector('[data-week-calendar]');
                const listWrap = weekModalBody.querySelector('[data-week-list]');
                const weekDaysEl = weekModalBody.querySelector('[data-week-days]');
                const weekGridEl = weekModalBody.querySelector('[data-week-grid]');
                const weekRangeEl = weekModalBody.querySelector('[data-week-range]');
                const emptyEl = weekModalBody.querySelector('[data-calendar-empty]');
                const viewButtons = Array.from(weekModalBody.querySelectorAll('[data-calendar-view]'));
                const navButtons = Array.from(weekModalBody.querySelectorAll('[data-week-nav]'));
                let currentWeekStart = startOfWeek(new Date());
                let currentView = 'calendar';

                const setView = (view) => {
                    currentView = view === 'list' ? 'list' : 'calendar';
                    if (calendarWrap) {
                        calendarWrap.classList.toggle('is-hidden', currentView === 'list');
                    }
                    if (listWrap) {
                        listWrap.classList.toggle('active', currentView === 'list');
                    }
                    viewButtons.forEach((btn) => {
                        const btnView = btn.getAttribute('data-calendar-view');
                        const isActive = btnView === (currentView === 'calendar' ? 'calendar' : 'list');
                        btn.classList.toggle('active', isActive);
                        btn.setAttribute('aria-pressed', isActive ? 'true' : 'false');
                    });
                };

                const buildBadge = (item, useTextDual) => {
                    if (!item) return null;
                    if (item.completed && item.overdue) {
                        const group = document.createElement('span');
                        group.className = 'calendar-task-badge-group';
                        const doneBadge = document.createElement('span');
                        doneBadge.className = useTextDual ? 'calendar-task-badge completed' : 'calendar-task-badge completed compact';
                        doneBadge.title = 'Done';
                        const doneIcon = document.createElement('i');
                        doneIcon.className = 'fa-solid fa-check';
                        doneBadge.appendChild(doneIcon);
                        if (useTextDual) {
                            doneBadge.appendChild(document.createTextNode(' Done'));
                        }
                        const overdueBadge = document.createElement('span');
                        overdueBadge.className = useTextDual ? 'calendar-task-badge overdue' : 'calendar-task-badge overdue compact';
                        overdueBadge.title = 'Overdue';
                        if (useTextDual) {
                            overdueBadge.appendChild(document.createTextNode('Overdue'));
                        } else {
                            const overdueIcon = document.createElement('i');
                            overdueIcon.className = 'fa-solid fa-triangle-exclamation';
                            overdueBadge.appendChild(overdueIcon);
                        }
                        group.appendChild(doneBadge);
                        group.appendChild(overdueBadge);
                        return group;
                    }
                    if (item.completed) {
                        const badge = document.createElement('span');
                        badge.className = 'calendar-task-badge completed';
                        badge.title = 'Done';
                        const icon = document.createElement('i');
                        icon.className = 'fa-solid fa-check';
                        badge.appendChild(icon);
                        badge.appendChild(document.createTextNode(' Done'));
                        return badge;
                    }
                    if (item.overdue) {
                        const badge = document.createElement('span');
                        badge.className = 'calendar-task-badge overdue';
                        badge.title = 'Overdue';
                        badge.textContent = 'Overdue';
                        return badge;
                    }
                    return null;
                };

                const buildTaskItem = (item, useTextDual = false) => {
                    const wrapper = document.createElement(item.link ? 'a' : 'div');
                    wrapper.className = 'calendar-task-item';
                    if (item.link) {
                        wrapper.href = item.link;
                    }
                    const header = document.createElement('div');
                    header.className = 'calendar-task-header';
                    const typeIcon = document.createElement('i');
                    typeIcon.className = item.type === 'Routine'
                        ? 'fa-solid fa-repeat calendar-task-type-icon is-routine'
                        : 'fa-solid fa-list-check calendar-task-type-icon is-task';
                    const titleWrap = document.createElement('span');
                    titleWrap.className = 'calendar-task-title-wrap';
                    const title = document.createElement('span');
                    title.className = 'calendar-task-title';
                    title.textContent = item.title || 'Item';
                    titleWrap.appendChild(title);
                    const points = document.createElement('span');
                    points.className = 'calendar-task-points';
                    points.textContent = `${item.points || 0} pts`;
                    const badge = buildBadge(item, useTextDual);
                    header.appendChild(typeIcon);
                    header.appendChild(titleWrap);
                    header.appendChild(points);
                    if (badge) {
                        header.appendChild(badge);
                    }
                    wrapper.appendChild(header);
                    if (item.time_label) {
                        const meta = document.createElement('span');
                        meta.className = 'calendar-task-meta';
                        const metaIcon = document.createElement('i');
                        metaIcon.className = 'fa-solid fa-clock';
                        meta.appendChild(metaIcon);
                        meta.appendChild(document.createTextNode(` ${item.time_label}`));
                        wrapper.appendChild(meta);
                    }
                    return wrapper;
                };

                const renderList = (weekDates) => {
                    if (!listWrap) return 0;
                    listWrap.innerHTML = '';
                    const todayKey = formatDateKey(new Date());
                    let totalItems = 0;
                    const sections = [
                        { key: 'anytime', label: 'Due Today' },
                        { key: 'morning', label: 'Morning' },
                        { key: 'afternoon', label: 'Afternoon' },
                        { key: 'evening', label: 'Evening' }
                    ];
                    weekDates.forEach(({ date, dateKey }) => {
                        const items = (schedule[dateKey] || []).slice();
                        items.sort((a, b) => {
                            const timeA = a.time || '99:99';
                            const timeB = b.time || '99:99';
                            const timeCompare = timeA.localeCompare(timeB);
                            if (timeCompare !== 0) return timeCompare;
                            return String(a.title || '').localeCompare(String(b.title || ''));
                        });
                        totalItems += items.length;
                        const dayCard = document.createElement('div');
                        dayCard.className = `week-list-day${dateKey === todayKey ? ' is-today' : ''}`;
                        const header = document.createElement('div');
                        header.className = 'week-list-day-header';
                        const name = document.createElement('span');
                        name.className = 'week-list-day-name';
                        name.textContent = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'][date.getDay()];
                        const dateLabel = document.createElement('span');
                        dateLabel.className = 'week-list-day-date';
                        dateLabel.textContent = date.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
                        header.appendChild(name);
                        header.appendChild(dateLabel);
                        dayCard.appendChild(header);
                        const sectionsWrap = document.createElement('div');
                        sectionsWrap.className = 'week-list-sections';
                        sections.forEach((section) => {
                            const sectionItems = items.filter((entry) => (entry.time_of_day || 'anytime') === section.key);
                            if (!sectionItems.length) return;
                            const sectionWrap = document.createElement('div');
                            const sectionTitle = document.createElement('div');
                            sectionTitle.className = 'week-list-section-title';
                            sectionTitle.textContent = section.label;
                            const itemsWrap = document.createElement('div');
                            itemsWrap.className = 'week-list-items';
                            sectionItems.forEach((entry) => {
                                itemsWrap.appendChild(buildTaskItem(entry, true));
                            });
                            sectionWrap.appendChild(sectionTitle);
                            sectionWrap.appendChild(itemsWrap);
                            sectionsWrap.appendChild(sectionWrap);
                        });
                        if (!sectionsWrap.childElementCount) {
                            const empty = document.createElement('div');
                            empty.className = 'week-list-empty';
                            empty.textContent = 'No tasks or routines';
                            dayCard.appendChild(empty);
                        } else {
                            dayCard.appendChild(sectionsWrap);
                        }
                        listWrap.appendChild(dayCard);
                    });
                    return totalItems;
                };

                const renderWeek = () => {
                    if (!weekDaysEl || !weekGridEl) return;
                    weekDaysEl.innerHTML = '';
                    weekGridEl.innerHTML = '';
                    const weekDates = [];
                    const todayKey = formatDateKey(new Date());
                    for (let i = 0; i < 7; i += 1) {
                        const date = addDays(currentWeekStart, i);
                        const dateKey = formatDateKey(date);
                        weekDates.push({ date, dateKey });
                        const dayCell = document.createElement('div');
                        dayCell.className = `week-day${dateKey === todayKey ? ' is-today' : ''}`;
                        const nameSpan = document.createElement('span');
                        nameSpan.className = 'week-day-name';
                        nameSpan.textContent = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'][date.getDay()];
                        const numSpan = document.createElement('span');
                        numSpan.className = 'week-day-num';
                        numSpan.textContent = date.getDate();
                        dayCell.appendChild(nameSpan);
                        dayCell.appendChild(numSpan);
                        weekDaysEl.appendChild(dayCell);
                    }
                    let totalItems = 0;
                    weekDates.forEach(({ dateKey }) => {
                        const items = (schedule[dateKey] || []).slice();
                        items.sort((a, b) => {
                            const timeA = a.time || '99:99';
                            const timeB = b.time || '99:99';
                            const timeCompare = timeA.localeCompare(timeB);
                            if (timeCompare !== 0) return timeCompare;
                            return String(a.title || '').localeCompare(String(b.title || ''));
                        });
                        totalItems += items.length;
                        const column = document.createElement('div');
                        column.className = 'week-column';
                        const list = document.createElement('div');
                        list.className = 'week-column-tasks';
                        if (!items.length) {
                            const empty = document.createElement('div');
                            empty.className = 'calendar-day-empty';
                            empty.textContent = 'No items';
                            list.appendChild(empty);
                        } else {
                            items.forEach((entry) => {
                                list.appendChild(buildTaskItem(entry, false));
                            });
                        }
                        column.appendChild(list);
                        weekGridEl.appendChild(column);
                    });
                    if (weekRangeEl) {
                        weekRangeEl.textContent = formatWeekRange(currentWeekStart);
                    }
                    if (emptyEl) {
                        emptyEl.classList.toggle('active', totalItems === 0);
                    }
                    renderList(weekDates);
                };

                viewButtons.forEach((btn) => {
                    btn.addEventListener('click', () => {
                        const view = btn.getAttribute('data-calendar-view');
                        setView(view);
                    });
                });
                const fetchWeekSchedule = async (startDate) => {
                    if (!childId || Number.isNaN(childId)) return schedule;
                    const weekStartKey = formatDateKey(startDate);
                    const params = new URLSearchParams({
                        week_schedule: '1',
                        child_id: String(childId),
                        week_start: weekStartKey
                    });
                    const response = await fetch(`dashboard_parent.php?${params.toString()}`, { credentials: 'same-origin' });
                    if (!response.ok) {
                        throw new Error('Failed to load week schedule.');
                    }
                    const payload = await response.json();
                    return payload.week_schedule || {};
                };
                const loadWeekSchedule = async () => {
                    try {
                        const newSchedule = await fetchWeekSchedule(currentWeekStart);
                        if (newSchedule) {
                            schedule = newSchedule;
                        }
                    } catch (e) {
                        // Keep existing schedule on failure.
                    }
                    renderWeek();
                };
                navButtons.forEach((btn) => {
                    btn.addEventListener('click', () => {
                        const delta = parseInt(btn.getAttribute('data-week-nav'), 10);
                        if (Number.isNaN(delta)) return;
                        currentWeekStart = addDays(currentWeekStart, delta * 7);
                        loadWeekSchedule();
                    });
                });

                setView(currentView);
                loadWeekSchedule();
                weekModal.classList.add('open');
                document.body.classList.add('modal-open');
            };
            document.querySelectorAll('[data-week-view]').forEach((btn) => {
                btn.addEventListener('click', () => openWeekModal(btn));
            });
            if (weekModal && weekModalClose) {
                weekModalClose.addEventListener('click', () => {
                    weekModal.classList.remove('open');
                    document.body.classList.remove('modal-open');
                });
                weekModal.addEventListener('click', (e) => {
                    if (e.target === weekModal) {
                        weekModal.classList.remove('open');
                        document.body.classList.remove('modal-open');
                    }
                });
            }

            const helpOpen = document.querySelector('[data-help-open]');
            const helpModal = document.querySelector('[data-help-modal]');
            const helpClose = helpModal ? helpModal.querySelector('[data-help-close]') : null;
            const openHelp = () => {
                if (!helpModal) return;
                helpModal.classList.add('open');
                document.body.classList.add('modal-open');
            };
            const closeHelp = () => {
                if (!helpModal) return;
                helpModal.classList.remove('open');
                document.body.classList.remove('modal-open');
            };
            if (helpOpen && helpModal) {
                helpOpen.addEventListener('click', openHelp);
                if (helpClose) helpClose.addEventListener('click', closeHelp);
                helpModal.addEventListener('click', (e) => { if (e.target === helpModal) closeHelp(); });
                document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeHelp(); });
            }

            const familyOpen = document.querySelector('[data-family-open]');
            const familyModal = document.querySelector('[data-family-modal]');
            const familyClose = familyModal ? familyModal.querySelector('[data-family-close]') : null;
            const openFamily = () => {
                if (!familyModal) return;
                familyModal.classList.add('open');
                document.body.classList.add('modal-open');
            };
            const closeFamily = () => {
                if (!familyModal) return;
                familyModal.classList.remove('open');
                document.body.classList.remove('modal-open');
            };
            if (familyOpen && familyModal) {
                familyOpen.addEventListener('click', openFamily);
                if (familyClose) familyClose.addEventListener('click', closeFamily);
                familyModal.addEventListener('click', (e) => { if (e.target === familyModal) closeFamily(); });
                document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeFamily(); });
            }

            const adjustModal = document.querySelector('[data-role="adjust-modal"]');
            const adjustTitle = adjustModal ? adjustModal.querySelector('[data-role="adjust-title"]') : null;
            const adjustChildIdInput = adjustModal ? adjustModal.querySelector('[data-role="adjust-child-id"]') : null;
            const adjustHistoryList = adjustModal ? adjustModal.querySelector('[data-role="adjust-history-list"]') : null;
            const adjustChildName = adjustModal ? adjustModal.querySelector('[data-role="adjust-child-name"]') : null;
            const adjustChildAvatar = adjustModal ? adjustModal.querySelector('[data-role="adjust-child-avatar"]') : null;
            const adjustCurrentPoints = adjustModal ? adjustModal.querySelector('[data-role="adjust-current-points"]') : null;
            const pointsInput = adjustModal ? adjustModal.querySelector('#adjust_points_input') : null;
            const reasonInput = adjustModal ? adjustModal.querySelector('#adjust_reason_input') : null;
            const setBodyScrollLocked = (locked) => {
                if (!document.body) return;
                document.body.classList.toggle('modal-open', !!locked);
                document.body.classList.toggle('show-mobile-nav', !!locked);
            };

            const renderHistory = (history) => {
                if (!adjustHistoryList) return;
                adjustHistoryList.innerHTML = '';
                if (!history || !history.length) {
                    const li = document.createElement('li');
                    li.textContent = 'No recent adjustments.';
                    adjustHistoryList.appendChild(li);
                    return;
                }
                history.forEach(item => {
                    const li = document.createElement('li');
                    const delta = document.createElement('span');
                    delta.className = 'delta ' + (item.delta_points >= 0 ? 'positive' : 'negative');
                    delta.textContent = (item.delta_points >= 0 ? '+' : '') + item.delta_points + ' pts';
                    const reason = document.createElement('span');
                    reason.textContent = item.reason || 'No reason';
                    const meta = document.createElement('span');
                    meta.className = 'meta';
                    meta.textContent = item.created_at ? new Date(item.created_at).toLocaleString() : '';
                    li.appendChild(delta);
                    li.appendChild(reason);
                    li.appendChild(meta);
                    adjustHistoryList.appendChild(li);
                });
            };

            document.querySelectorAll('[data-role="open-adjust-modal"]').forEach(btn => {
                btn.addEventListener('click', () => {
                    const childId = btn.dataset.childId || '';
                    const childName = btn.dataset.childName || 'Child';
                    const childAvatar = btn.dataset.childAvatar || 'images/default-avatar.png';
                    const childPoints = btn.dataset.childPoints || '0';
                    const historyRaw = btn.dataset.history || '[]';
                    let history = [];
                    try { history = JSON.parse(historyRaw); } catch (e) { history = []; }
                    if (adjustTitle) { adjustTitle.textContent = 'Adjust Points'; }
                    if (adjustChildName) { adjustChildName.textContent = childName; }
                    if (adjustChildAvatar) { adjustChildAvatar.src = childAvatar; adjustChildAvatar.alt = childName; }
                    if (adjustCurrentPoints) { adjustCurrentPoints.textContent = childPoints; }
                    if (adjustChildIdInput) { adjustChildIdInput.value = childId; }
                    if (pointsInput) { pointsInput.value = 1; }
                    if (reasonInput) { reasonInput.value = ''; }
                    renderHistory(history);
                    if (adjustModal) {
                        adjustModal.classList.add('open');
                        setBodyScrollLocked(true);
                    }
                });
            });

            if (adjustModal) {
                const closeButtons = adjustModal.querySelectorAll('[data-action="close-adjust"]');
                closeButtons.forEach(btn => btn.addEventListener('click', () => {
                    adjustModal.classList.remove('open');
                    setBodyScrollLocked(false);
                }));
                adjustModal.addEventListener('click', (e) => {
                    if (e.target === adjustModal) {
                        adjustModal.classList.remove('open');
                        setBodyScrollLocked(false);
                    }
                });
                const decBtn = adjustModal.querySelector('[data-action="decrement-points"]');
                const incBtn = adjustModal.querySelector('[data-action="increment-points"]');
                if (decBtn && pointsInput) {
                    decBtn.addEventListener('click', () => {
                        const current = parseInt(pointsInput.value || '0', 10) || 0;
                        pointsInput.value = current - 1;
                    });
                }
                if (incBtn && pointsInput) {
                    incBtn.addEventListener('click', () => {
                        const current = parseInt(pointsInput.value || '0', 10) || 0;
                        pointsInput.value = current + 1;
                    });
                }
            }

            const routineLogModal = document.getElementById('routine-log-modal');
            const routineLogTitle = routineLogModal ? routineLogModal.querySelector('[data-role="routine-log-title"]') : null;
            const routineLogBody = routineLogModal ? routineLogModal.querySelector('[data-role="routine-log-body"]') : null;
            const routineLogClose = routineLogModal ? routineLogModal.querySelector('[data-role="routine-log-close"]') : null;
            const routineLogsByRoutine = window.RoutineOvertimeByRoutine || {};

            const formatDuration = (seconds) => {
                const safe = Math.max(0, Math.floor(Number(seconds) || 0));
                const mins = Math.floor(safe / 60);
                const secs = safe % 60;
                return `${mins.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`;
            };

            const openRoutineLogModal = (routineId, routineTitle) => {
                if (!routineLogModal || !routineLogBody || !routineLogTitle) return;
                const key = String(routineId || routineTitle || '');
                const group = routineLogsByRoutine[String(routineId)] || routineLogsByRoutine[key] || null;
                const entries = group && Array.isArray(group.entries) ? group.entries : [];
                routineLogTitle.textContent = routineTitle || (group ? group.title : 'Routine Overtime');
                routineLogBody.innerHTML = '';
                if (!entries.length) {
                    const empty = document.createElement('div');
                    empty.className = 'routine-log-empty';
                    empty.textContent = 'No recent overtime events for this routine.';
                    routineLogBody.appendChild(empty);
                } else {
                    entries.forEach(entry => {
                        const item = document.createElement('div');
                        item.className = 'routine-log-item';
                        const when = entry.occurred_at ? new Date(entry.occurred_at) : null;
                        const header = document.createElement('div');
                        header.className = 'meta';
                        header.textContent = when ? when.toLocaleString() : 'Date unavailable';
                        const child = document.createElement('div');
                        child.className = 'meta';
                        child.textContent = `Child: ${entry.child_display_name || 'Unknown'}`;
                        const task = document.createElement('div');
                        task.className = 'meta';
                        task.textContent = `Task: ${entry.task_title || 'Task'}`;
                        const times = document.createElement('div');
                        times.className = 'meta';
                        times.textContent = `Scheduled: ${formatDuration(entry.scheduled_seconds)} - Actual: ${formatDuration(entry.actual_seconds)}`;
                        const overtime = document.createElement('div');
                        overtime.className = 'overtime';
                        overtime.textContent = `Overtime: ${formatDuration(entry.overtime_seconds)}`;
                        item.append(header, child, task, times, overtime);
                        routineLogBody.appendChild(item);
                    });
                }
                routineLogModal.classList.add('active');
                routineLogModal.setAttribute('aria-hidden', 'false');
            };

            const closeRoutineLogModal = () => {
                if (!routineLogModal) return;
                routineLogModal.classList.remove('active');
                routineLogModal.setAttribute('aria-hidden', 'true');
            };

            if (routineLogClose) {
                routineLogClose.addEventListener('click', closeRoutineLogModal);
            }
            if (routineLogModal) {
                routineLogModal.addEventListener('click', (event) => {
                    if (event.target === routineLogModal) {
                        closeRoutineLogModal();
                    }
                });
            }

            document.querySelectorAll('[data-routine-log-trigger]').forEach(btn => {
                btn.addEventListener('click', () => {
                    const routineId = btn.getAttribute('data-routine-id');
                    const routineTitle = btn.getAttribute('data-routine-title');
                    openRoutineLogModal(routineId, routineTitle);
                });
            });

            // Child removal: modal with soft-remove or hard-delete
            const childRemoveModal = document.querySelector('[data-child-remove-modal]');
            const childRemoveSoft = childRemoveModal ? childRemoveModal.querySelector('[data-action="child-remove-soft"]') : null;
            const childRemoveHard = childRemoveModal ? childRemoveModal.querySelector('[data-action="child-remove-hard"]') : null;
            const childRemoveCancelButtons = childRemoveModal ? childRemoveModal.querySelectorAll('[data-action="child-remove-cancel"]') : [];
            let activeRemoveForm = null;

            const closeChildRemoveModal = () => {
                if (!childRemoveModal) return;
                childRemoveModal.classList.remove('open');
                childRemoveModal.setAttribute('aria-hidden', 'true');
                document.body.classList.remove('modal-open');
                activeRemoveForm = null;
            };
            const openChildRemoveModal = (form) => {
                activeRemoveForm = form;
                if (!childRemoveModal) return;
                childRemoveModal.classList.add('open');
                childRemoveModal.setAttribute('aria-hidden', 'false');
                document.body.classList.add('modal-open');
            };

            document.querySelectorAll('[data-role="child-remove-form"]').forEach(form => {
                const button = form.querySelector('[data-action="remove-child"]');
                if (!button) return;
                button.addEventListener('click', (e) => {
                    e.preventDefault();
                    console.log('Open child remove modal for form child_id:', form.querySelector('input[name="delete_user_id"]')?.value || '');
                    openChildRemoveModal(form);
                });
            });

            if (childRemoveModal) {
                childRemoveModal.addEventListener('click', (e) => {
                    if (e.target === childRemoveModal) {
                        closeChildRemoveModal();
                    }
                });
            }

            childRemoveCancelButtons.forEach(btn => btn.addEventListener('click', closeChildRemoveModal));

            if (childRemoveSoft) {
                childRemoveSoft.addEventListener('click', () => {
                    const form = activeRemoveForm;
                    if (!form) {
                        console.warn('No active form for soft remove');
                        closeChildRemoveModal();
                        return;
                    }
                    const modeInput = form.querySelector('input[name="delete_mode"]');
                    if (modeInput) modeInput.value = 'soft';
                    console.log('Submitting soft remove for child_id:', form.querySelector('input[name="delete_user_id"]')?.value || '');
                    closeChildRemoveModal();
                    form.submit();
                });
            }
            if (childRemoveHard) {
                childRemoveHard.addEventListener('click', () => {
                    const form = activeRemoveForm;
                    if (!form) {
                        console.warn('No active form for hard delete');
                        closeChildRemoveModal();
                        return;
                    }
                    const modeInput = form.querySelector('input[name="delete_mode"]');
                    if (modeInput) modeInput.value = 'hard';
                    console.log('Submitting hard delete for child_id:', form.querySelector('input[name="delete_user_id"]')?.value || '');
                    closeChildRemoveModal();
                    form.submit();
                });
            }

            const memberRemoveModal = document.querySelector('[data-member-remove-modal]');
            const memberRemoveConfirm = memberRemoveModal ? memberRemoveModal.querySelector('[data-action="member-remove-confirm"]') : null;
            const memberRemoveCancelButtons = memberRemoveModal ? memberRemoveModal.querySelectorAll('[data-action="member-remove-cancel"]') : [];
            let activeMemberRemoveForm = null;

            const closeMemberRemoveModal = () => {
                if (!memberRemoveModal) return;
                memberRemoveModal.classList.remove('open');
                memberRemoveModal.setAttribute('aria-hidden', 'true');
                document.body.classList.remove('modal-open');
                activeMemberRemoveForm = null;
            };
            const openMemberRemoveModal = (form) => {
                activeMemberRemoveForm = form;
                if (!memberRemoveModal) return;
                memberRemoveModal.classList.add('open');
                memberRemoveModal.setAttribute('aria-hidden', 'false');
                document.body.classList.add('modal-open');
            };

            document.querySelectorAll('[data-role="member-remove-form"]').forEach(form => {
                const button = form.querySelector('[data-action="remove-member"]');
                if (!button) return;
                button.addEventListener('click', (e) => {
                    e.preventDefault();
                    openMemberRemoveModal(form);
                });
            });

            if (memberRemoveModal) {
                memberRemoveModal.addEventListener('click', (e) => {
                    if (e.target === memberRemoveModal) {
                        closeMemberRemoveModal();
                    }
                });
            }

            memberRemoveCancelButtons.forEach(btn => btn.addEventListener('click', closeMemberRemoveModal));

            if (memberRemoveConfirm) {
                memberRemoveConfirm.addEventListener('click', () => {
                    const form = activeMemberRemoveForm;
                    if (!form) {
                        closeMemberRemoveModal();
                        return;
                    }
                    closeMemberRemoveModal();
                    form.submit();
                });
            }
        });
    </script>
</head>
<body>
   <?php
      $dashboardActive = $currentPage === 'dashboard_parent.php';
      $routinesActive = $currentPage === 'routine.php';
      $tasksActive = $currentPage === 'task.php';
      $goalsActive = $currentPage === 'goal.php';
      $rewardsActive = $currentPage === 'rewards.php';
      $profileActive = $currentPage === 'profile.php';
   ?>
   <header class="page-header">
      <div class="page-header-top">
         <div class="page-header-title">
            <h1>Parent Dashboard</h1>
            <p class="page-header-meta">Welcome back, <?php echo htmlspecialchars($_SESSION['name'] ?? $_SESSION['username']); ?>
               <?php if ($welcome_role_label): ?>
                  <span class="role-badge"><?php echo htmlspecialchars($welcome_role_label); ?></span>
               <?php endif; ?>
            </p>
         </div>
         <div class="page-header-actions">
            <button type="button" class="parent-notification-trigger page-header-action" data-parent-notify-trigger aria-label="Notifications">
               <i class="fa-solid fa-bell"></i>
               <?php if ($parentNotificationCount > 0): ?>
                  <span class="parent-notification-badge"><?php echo (int)$parentNotificationCount; ?></span>
               <?php endif; ?>
            </button>
            <button type="button" class="nav-family-button page-header-action" data-family-open aria-label="Family settings">
               <i class="fa-solid fa-gear"></i>
            </button>
            <a class="page-header-action" href="logout.php" aria-label="Logout">
               <i class="fa-solid fa-right-from-bracket"></i>
            </a>
         </div>
      </div>
      <nav class="nav-links" aria-label="Primary">
         <a class="nav-link<?php echo $dashboardActive ? ' is-active' : ''; ?>" href="dashboard_parent.php"<?php echo $dashboardActive ? ' aria-current="page"' : ''; ?>>
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
   <?php include __DIR__ . "/includes/notifications_parent.php"; ?>

   <div class="parent-photo-modal" data-parent-photo-modal>
      <div class="parent-photo-card" role="dialog" aria-modal="true" aria-labelledby="parent-photo-title">
         <header>
            <h2 id="parent-photo-title">Photo Proof</h2>
            <button type="button" class="parent-photo-close" aria-label="Close photo preview" data-parent-photo-close><i class="fa-solid fa-xmark"></i></button>
         </header>
         <div class="parent-photo-body">
            <img src="" alt="Task photo proof" class="parent-photo-preview" data-parent-photo-preview>
         </div>
      </div>
   </div>
   <div class="family-modal" data-family-modal>
      <div class="family-modal-card" role="dialog" aria-modal="true" aria-labelledby="family-modal-title">
         <header>
            <h2 id="family-modal-title">Family Management</h2>
            <button type="button" class="family-modal-close" aria-label="Close family modal" data-family-close><i class="fa-solid fa-xmark"></i></button>
         </header>
         <div class="family-modal-body">
            <?php if (in_array($role_type, ['main_parent', 'secondary_parent', 'family_member'])): ?>
            <div class="manage-family family-section" id="manage-family">
               <h2>Manage Family</h2>
               <?php if (in_array($role_type, ['main_parent', 'secondary_parent'])): ?>
                  <button id="add-child-btn" class="button">Add Child</button>
               <?php endif; ?>
               <button id="add-caregiver-btn" class="button" style="background: #ff9800;">Add New User</button>
               <?php if (in_array($role_type, ['main_parent', 'secondary_parent'])): ?>
                  <div id="child-form" class="family-form">
                     <h3>Add Child</h3>
                     <form method="POST" action="dashboard_parent.php" enctype="multipart/form-data">
                        <div class="form-group">
                           <label for="first_name">First Name:</label>
                           <input type="text" id="first_name" name="first_name" required>
                        </div>
                        <div class="form-group">
                           <label for="last_name">Last Name:</label>
                           <input type="text" id="last_name" name="last_name" required>
                        </div>
                        <div class="form-group">
                           <label for="child_username">Username (for login):</label>
                           <input type="text" id="child_username" name="child_username" required>
                        </div>
                        <div class="form-group">
                           <label for="child_password">Password (parent sets):</label>
                           <input type="password" id="child_password" name="child_password" required>
                        </div>
                        <div class="form-group">
                           <label for="birthday">Birthday:</label>
                           <input type="date" id="birthday" name="birthday" required>
                        </div>
                        <div class="form-group">
                           <label for="child_gender">Gender:</label>
                           <select id="child_gender" name="child_gender" required>
                               <option value="">Select...</option>
                               <option value="male">Male</option>
                               <option value="female">Female</option>
                           </select>
                        </div>
                        <div class="form-group">
                           <label>Avatar:</label>
                           <div class="avatar-options">
                              <img class="avatar-option" data-avatar="images/avatar_images/default-avatar.png" src="images/avatar_images/default-avatar.png" alt="Avatar default">
                              <img class="avatar-option" data-avatar="images/avatar_images/boy-1.png" src="images/avatar_images/boy-1.png" alt="Avatar 1">
                              <img class="avatar-option" data-avatar="images/avatar_images/girl-1.png" src="images/avatar_images/girl-1.png" alt="Avatar 2">
                              <img class="avatar-option" data-avatar="images/avatar_images/xmas-elf-boy.png" src="images/avatar_images/xmas-elf-boy.png" alt="Avatar 3">
                              <!-- Add more based on uploaded files -->
                           </div>
                           <input type="file" id="avatar-upload" name="avatar_upload" accept="image/*">
                           <img id="avatar-preview" src="images/avatar_images/default-avatar.png" alt="Preview" style="width: 100px; border-radius: 50%;">
                           <input type="hidden" id="avatar" name="avatar">
                        </div>
                        <button type="submit" name="add_child" class="button">Add Child</button>
                     </form>
                  </div>
               <?php endif; ?>
               <div id="caregiver-form" class="family-form">
                  <h3>Add Family Member/Caregiver</h3>
                  <form method="POST" action="dashboard_parent.php">
                     <div class="form-group">
                        <label for="secondary_first_name">First Name:</label>
                        <input type="text" id="secondary_first_name" name="secondary_first_name" required placeholder="Enter first name">
                     </div>
                     <div class="form-group">
                        <label for="secondary_last_name">Last Name:</label>
                        <input type="text" id="secondary_last_name" name="secondary_last_name" required placeholder="Enter last name">
                     </div>
                     <div class="form-group">
                        <label for="secondary_username">Username (for login):</label>
                        <input type="text" id="secondary_username" name="secondary_username" required placeholder="Choose a username">
                     </div>
                     <div class="form-group">
                        <label for="secondary_password">Password:</label>
                        <input type="password" id="secondary_password" name="secondary_password" required>
                     </div>
                     <div class="form-group">
                        <label for="role_type">Role Type:</label>
                        <select id="role_type" name="role_type" required>
                           <option value="secondary_parent">Secondary Parent (Full Access)</option>
                           <option value="family_member">Family Member (Limited Access)</option>
                           <option value="caregiver">Caregiver (Task Management Only)</option>
                        </select>
                     </div>
                     <button type="submit" name="add_new_user" class="button">Add New User</button>
                  </form>
               </div>
            </div>
            <?php endif; ?>
            <div class="family-members-list family-children-list family-section">
               <h2>Children</h2>
               <?php if (isset($data['children']) && is_array($data['children']) && !empty($data['children'])): ?>
                   <?php foreach ($data['children'] as $child): ?>
                       <div class="member-item">
                          <div class="member-details">
                             <p><?php echo htmlspecialchars($child['child_name']); ?>
                                <span class="role-type">(Child)</span>
                             </p>
                          </div>
                          <div class="member-actions">
                             <?php if (in_array($role_type, ['main_parent', 'secondary_parent'])): ?>
                                 <a href="profile.php?user_id=<?php echo $child['child_user_id']; ?>&type=child" class="member-action-icon" aria-label="Edit child">
                                     <i class="fa-solid fa-pen"></i>
                                 </a>
                             <?php endif; ?>
                             <?php if ($role_type === 'main_parent'): ?>
                                 <form method="POST" data-role="child-remove-form">
                                     <input type="hidden" name="delete_user_id" value="<?php echo $child['child_user_id']; ?>">
                                     <input type="hidden" name="delete_mode" value="soft">
                                     <input type="hidden" name="delete_user" value="1">
                                     <button type="submit" class="member-action-icon danger" data-action="remove-child" aria-label="Remove child">
                                         <i class="fa-solid fa-trash"></i>
                                     </button>
                                 </form>
                             <?php endif; ?>
                          </div>
                       </div>
                   <?php endforeach; ?>
               <?php else: ?>
                   <p>No children added yet.</p>
               <?php endif; ?>
            </div>
            <div class="family-members-list family-section">
               <?php // Use precomputed $main_parent_id from top of file ?>
               <h2>Family Members</h2>
               <?php
              $stmt = $db->prepare("SELECT u.id, CONCAT(u.first_name, ' ', u.last_name) AS name, u.username, fl.role_type 
                                    FROM users u 
                                    JOIN family_links fl ON u.id = fl.linked_user_id 
                                    WHERE fl.main_parent_id = :main_parent_id 
                                    AND fl.role_type IN ('secondary_parent', 'family_member') 
                                    ORDER BY fl.role_type, u.name");
              $stmt->execute([':main_parent_id' => $main_parent_id]);
              $family_members = $stmt->fetchAll(PDO::FETCH_ASSOC);

              if ($role_type !== 'main_parent') {
                  $ownerStmt = $db->prepare("SELECT id, CONCAT(first_name, ' ', last_name) AS name, username FROM users WHERE id = :id");
                  $ownerStmt->execute([':id' => $main_parent_id]);
                  $mainOwner = $ownerStmt->fetch(PDO::FETCH_ASSOC);
                  if ($mainOwner) {
                      $mainOwner['role_type'] = 'main_parent';
                      array_unshift($family_members, $mainOwner);
                  }
              }
               
               if (!empty($family_members)): ?>
                   <?php foreach ($family_members as $member): ?>
                       <div class="member-item">
                          <div class="member-details">
                             <p><?php echo htmlspecialchars($member['name'] ?? $member['username']); ?>
                                 <span class="role-type">(<?php
                                     $memberBadge = getUserRoleLabel($member['id']) ?? ($member['role_type'] ?? '');
                                     if (!$memberBadge && isset($member['role_type'])) {
                                         $memberBadge = ucfirst(str_replace('_', ' ', $member['role_type']));
                                     }
                                     echo htmlspecialchars($memberBadge);
                                 ?>)</span>
                              </p>
                          </div>
                          <?php if (in_array($role_type, ['main_parent', 'secondary_parent']) && ($member['role_type'] ?? '') !== 'main_parent'): ?>
                              <div class="member-actions">
                                  <a href="profile.php?edit_user=<?php echo $member['id']; ?>&role_type=<?php echo urlencode($member['role_type']); ?>" class="member-action-icon" aria-label="Edit family member">
                                      <i class="fa-solid fa-pen"></i>
                                  </a>
                                  <form method="POST" data-role="member-remove-form">
                                      <input type="hidden" name="delete_user_id" value="<?php echo $member['id']; ?>">
                                      <input type="hidden" name="delete_user" value="1">
                                      <button type="button" class="member-action-icon danger" data-action="remove-member" aria-label="Remove family member">
                                          <i class="fa-solid fa-trash"></i>
                                      </button>
                                  </form>
                              </div>
                          <?php endif; ?>
                       </div>
                   <?php endforeach; ?>
               <?php else: ?>
                   <p>No family members added yet.</p>
               <?php endif; ?>

           </div>
            <div class="family-members-list family-section">
               <h2>Caregivers</h2>
               <?php
               $stmt = $db->prepare("SELECT u.id, CONCAT(u.first_name, ' ', u.last_name) AS name, u.username, fl.role_type 
                                     FROM users u 
                                     JOIN family_links fl ON u.id = fl.linked_user_id 
                                     WHERE fl.main_parent_id = :main_parent_id 
                                     AND fl.role_type = 'caregiver' 
                                     ORDER BY u.name");
               $stmt->execute([':main_parent_id' => $main_parent_id]);
               $caregivers = $stmt->fetchAll(PDO::FETCH_ASSOC);
               
               if (!empty($caregivers)): ?>
                   <?php foreach ($caregivers as $caregiver): ?>
                       <div class="member-item">
                           <div class="member-details">
                              <p><?php echo htmlspecialchars($caregiver['name'] ?? $caregiver['username']); ?>
                                 <span class="role-type">(<?php
                                     $caregiverBadge = getUserRoleLabel($caregiver['id']) ?? ($caregiver['role_type'] ?? '');
                                     if (!$caregiverBadge && isset($caregiver['role_type'])) {
                                         $caregiverBadge = ucfirst(str_replace('_', ' ', $caregiver['role_type']));
                                     }
                                     echo htmlspecialchars($caregiverBadge ?: 'Caregiver');
                                 ?>)</span>
                              </p>
                           </div>
                           <?php if (in_array($role_type, ['main_parent', 'secondary_parent'])): ?>
                              <div class="member-actions">
                                  <a href="profile.php?edit_user=<?php echo $caregiver['id']; ?>&role_type=<?php echo urlencode($caregiver['role_type']); ?>" class="member-action-icon" aria-label="Edit caregiver">
                                      <i class="fa-solid fa-pen"></i>
                                  </a>
                                  <form method="POST" data-role="member-remove-form">
                                      <input type="hidden" name="delete_user_id" value="<?php echo $caregiver['id']; ?>">
                                      <input type="hidden" name="delete_user" value="1">
                                      <button type="button" class="member-action-icon danger" data-action="remove-member" aria-label="Remove caregiver">
                                          <i class="fa-solid fa-trash"></i>
                                      </button>
                                  </form>
                              </div>
                           <?php endif; ?>
                       </div>
                   <?php endforeach; ?>
               <?php else: ?>
                   <p>No caregivers added yet.</p>
               <?php endif; ?>
           </div>
         </div>
      </div>
   </div>
   <main class="dashboard">
      <?php if (isset($message)) echo "<p>$message</p>"; ?>
      
      <div class="children-overview">
         <h2>Children Overview</h2>
         <?php if (isset($data['children']) && is_array($data['children']) && !empty($data['children'])): ?>
               <div class="children-overview-grid">
               <?php foreach ($data['children'] as $child): ?>
                  <?php
                     $childId = (int) ($child['child_user_id'] ?? 0);
                     $weekSchedule = $buildWeekSchedule($childId, $weekStart, $weekEnd, $weekDates);
                     $todayItems = $weekSchedule[$todayDate] ?? [];
                     $weekScheduleJson = htmlspecialchars(json_encode($weekSchedule, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES);
                  ?>
                  <div class="child-info-card">
                      <div class="child-info-left">
                          <div class="child-info-header">
                             <img src="<?php echo htmlspecialchars($child['avatar'] ?? 'default-avatar.png'); ?>" alt="Avatar for <?php echo htmlspecialchars($child['child_name']); ?>">
                             <div class="child-info-header-details">
                                <p class="child-info-name"><?php echo htmlspecialchars($child['child_name']); ?></p>
                            </div>
                          </div>
                          <div class="points-progress-wrapper">
                              <div class="points-progress-label">Total points</div>
                              <div class="points-number" data-points="<?php echo (int)($child['points_earned'] ?? 0); ?>">
                                  <i class="fa-solid fa-star"></i>
                                  <span class="points-number-value">0</span>
                                  <span class="points-number-suffix">pts</span>
                              </div>
                              <?php if (in_array($role_type, ['main_parent', 'secondary_parent'], true)): ?>
                                  <button type="button"
                                      class="button adjust-button"
                                      data-role="open-adjust-modal"
                                      data-child-id="<?php echo (int)$child['child_user_id']; ?>"
                                      data-child-name="<?php echo htmlspecialchars($child['child_name']); ?>"
                                      data-child-avatar="<?php echo htmlspecialchars($child['avatar'] ?? 'images/default-avatar.png'); ?>"
                                      data-child-points="<?php echo (int)($child['points_earned'] ?? 0); ?>"
                                      data-history='<?php echo htmlspecialchars(json_encode($child['point_adjustments'] ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)); ?>'>
                                      <i class="fa-solid fa-plus"></i>
                                      <span class="label">Add / Remove Points</span>
                                  </button>
                              <?php endif; ?>
                              <button type="button"
                                      class="button secondary history-button"
                                      data-child-history-open
                                      data-child-history-id="<?php echo (int)$child['child_user_id']; ?>"
                                      data-child-history-name="<?php echo htmlspecialchars($child['child_name']); ?>">
                                  <i class="fa-solid fa-clock-rotate-left"></i>
                                  <span class="label">History</span>
                              </button>
                          </div>
                      </div>
                      <div class="child-info-content">
                      <?php
                          $historyItems = [];
                          $taskHistoryStmt = $db->prepare("
                              SELECT t.title, t.points, ti.approved_at, ti.completed_at
                              FROM task_instances ti
                              JOIN tasks t ON t.id = ti.task_id
                              WHERE t.child_user_id = :child_id AND ti.status = 'approved'
                              UNION ALL
                              SELECT title, points, approved_at, completed_at
                              FROM tasks
                              WHERE child_user_id = :child_id AND approved_at IS NOT NULL AND (recurrence IS NULL OR recurrence = '')
                          ");
                          $taskHistoryStmt->execute([':child_id' => $childId]);
                          foreach ($taskHistoryStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                              $dateValue = $row['approved_at'] ?? $row['completed_at'] ?? null;
                              if (empty($dateValue)) {
                                  continue;
                              }
                              $historyItems[] = [
                                  'type' => 'Task',
                                  'title' => $row['title'],
                                  'points' => (int)($row['points'] ?? 0),
                                  'date' => $dateValue
                              ];
                          }
                          try {
                              ensureRoutinePointsLogsTable();
                              $routineHistoryStmt = $db->prepare("
                                  SELECT rpl.task_points, rpl.bonus_points, rpl.created_at, r.title
                                  FROM routine_points_logs rpl
                                  LEFT JOIN routines r ON rpl.routine_id = r.id
                                  WHERE rpl.child_user_id = :child_id
                                  ORDER BY rpl.created_at DESC
                              ");
                              $routineHistoryStmt->execute([':child_id' => $childId]);
                              foreach ($routineHistoryStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                                  $totalPoints = (int)($row['task_points'] ?? 0) + (int)($row['bonus_points'] ?? 0);
                                  $historyItems[] = [
                                      'type' => 'Routine',
                                      'title' => $row['title'] ?: 'Routine',
                                      'points' => $totalPoints,
                                      'date' => $row['created_at']
                                  ];
                              }
                          } catch (Exception $e) {
                              $historyItems = $historyItems;
                          }
                          try {
                              $adjStmt = $db->prepare("SELECT delta_points, reason, created_at FROM child_point_adjustments WHERE child_user_id = :child_id");
                              $adjStmt->execute([':child_id' => $childId]);
                              foreach ($adjStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                                  $historyItems[] = [
                                      'type' => 'Adjustment',
                                      'title' => $row['reason'],
                                      'points' => (int) $row['delta_points'],
                                      'date' => $row['created_at']
                                  ];
                              }
                          } catch (Exception $e) {
                              $historyItems = $historyItems;
                          }
                          try {
                              $rewardStmt = $db->prepare("
                                  SELECT title, point_cost, redeemed_on
                                  FROM rewards
                                  WHERE redeemed_by = :child_id AND redeemed_on IS NOT NULL
                              ");
                              $rewardStmt->execute([':child_id' => $childId]);
                              foreach ($rewardStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                                  $cost = (int) ($row['point_cost'] ?? 0);
                                  if ($cost <= 0 || empty($row['redeemed_on'])) {
                                      continue;
                                  }
                                  $historyItems[] = [
                                      'type' => 'Reward',
                                      'title' => 'Redeemed: ' . ($row['title'] ?? 'Reward'),
                                      'points' => -abs($cost),
                                      'date' => $row['redeemed_on']
                                  ];
                              }
                          } catch (Exception $e) {
                              $historyItems = $historyItems;
                          }
                          usort($historyItems, static function ($a, $b) {
                              return strtotime($b['date']) <=> strtotime($a['date']);
                          });
                          $historyByDay = [];
                          foreach ($historyItems as $item) {
                              if (empty($item['date'])) {
                                  continue;
                              }
                              $dayKey = date('Y-m-d', strtotime($item['date']));
                              if (!isset($historyByDay[$dayKey])) {
                                  $historyByDay[$dayKey] = [];
                              }
                              $historyByDay[$dayKey][] = $item;
                          }
                      ?>
                      <div class="child-info-body">
                         <div class="child-stats-grid">
                             <a class="child-stat-link" href="task.php">
                                 <span class="child-stat-icon"><i class="fa-solid fa-list-check"></i></span>
                                 <span class="child-stat-badge"><?php echo (int)($child['task_count'] ?? 0); ?></span>
                                 <span class="child-stat-label">Tasks Assigned</span>
                             </a>
                             <a class="child-stat-link" href="goal.php">
                                 <span class="child-stat-icon"><i class="fa-solid fa-bullseye"></i></span>
                                 <span class="child-stat-badge"><?php echo (int)($child['goals_assigned'] ?? 0); ?></span>
                                 <span class="child-stat-label">Goals</span>
                             </a>
                             <a class="child-stat-link" href="rewards.php#redeemed-child-<?php echo (int)$child['child_user_id']; ?>">
                                 <span class="child-stat-icon"><i class="fa-solid fa-star"></i></span>
                                 <span class="child-stat-badge"><?php echo (int)($redeemedRewardCounts[$child['child_user_id']] ?? 0); ?></span>
                                 <span class="child-stat-label">Redeemed</span>
                             </a>
                         </div>
                    </div>
                    <div class="child-schedule-card">
                      <div class="child-schedule-today">
                         <div class="child-schedule-date">Today: <?php echo htmlspecialchars(date('D, M j', strtotime($todayDate))); ?></div>
                         <?php
                            $sections = [
                               'anytime' => 'Due Today',
                               'morning' => 'Morning',
                               'afternoon' => 'Afternoon',
                               'evening' => 'Evening'
                            ];
                            $sectionedToday = ['anytime' => [], 'morning' => [], 'afternoon' => [], 'evening' => []];
                            foreach ($todayItems as $item) {
                               $key = $item['time_of_day'] ?? '';
                               if (isset($sectionedToday[$key])) {
                                  $sectionedToday[$key][] = $item;
                               }
                            }
                            $hasSchedule = false;
                            foreach ($sectionedToday as $sectionItems) {
                               if (!empty($sectionItems)) {
                                  $hasSchedule = true;
                                  break;
                               }
                            }
                         ?>
                         <?php if ($hasSchedule): ?>
                            <?php foreach ($sections as $key => $label): ?>
                               <?php if (!empty($sectionedToday[$key])): ?>
                                  <div class="child-schedule-section">
                                     <div class="child-schedule-section-title"><?php echo $label; ?></div>
                                     <ul class="child-schedule-section-list">
                                        <?php foreach ($sectionedToday[$key] as $item): ?>
                                           <?php $itemLink = $item['link'] ?? ''; ?>
                                           <li>
                                              <?php if (!empty($itemLink)): ?>
                                                 <a class="child-schedule-item" href="<?php echo htmlspecialchars($itemLink); ?>">
                                              <?php else: ?>
                                                 <div class="child-schedule-item">
                                              <?php endif; ?>
                                                 <div class="child-schedule-main">
                                                    <i class="<?php echo htmlspecialchars($item['icon']); ?>"></i>
                                                    <div>
                                                       <div class="child-schedule-title">
                                                          <?php echo htmlspecialchars($item['title']); ?>
                                                            <?php if (!empty($item['completed']) && !empty($item['overdue'])): ?>
                                                              <span class="child-schedule-badge-group">
                                                                <span class="child-schedule-badge compact" title="Done"><i class="fa-solid fa-check"></i></span>
                                                                <span class="child-schedule-badge overdue compact" title="Overdue"><i class="fa-solid fa-triangle-exclamation"></i></span>
                                                              </span>
                                                            <?php elseif (!empty($item['completed'])): ?>
                                                              <span class="child-schedule-badge" title="Done"><i class="fa-solid fa-check"></i>Done</span>
                                                            <?php elseif (!empty($item['overdue'])): ?>
                                                              <span class="child-schedule-badge overdue" title="Overdue"><i class="fa-solid fa-triangle-exclamation"></i>Overdue</span>
                                                            <?php endif; ?>
                                                       </div>
                                                       <div class="child-schedule-time"><?php echo htmlspecialchars($item['time_label']); ?></div>
                                                    </div>
                                                 </div>
                                                 <div class="child-schedule-points"><?php echo (int)$item['points']; ?> pts</div>
                                              <?php if (!empty($itemLink)): ?>
                                                 </a>
                                              <?php else: ?>
                                                 </div>
                                              <?php endif; ?>
                                           </li>
                                        <?php endforeach; ?>
                                     </ul>
                                  </div>
                               <?php endif; ?>
                            <?php endforeach; ?>
                         <?php else: ?>
                            <div class="child-schedule-time">No tasks or routines today.</div>
                         <?php endif; ?>
                      </div>
                        <button type="button"
                                class="view-week-button"
                                data-week-view
                                data-child-id="<?php echo (int) $childId; ?>"
                                data-child-name="<?php echo htmlspecialchars($child['child_name'], ENT_QUOTES); ?>"
                                data-week-schedule="<?php echo $weekScheduleJson; ?>">
                          View Week
                      </button>
                   </div>
                      </div>
                  </div>
                  <div class="child-history-modal" data-child-history-modal data-child-history-id="<?php echo (int)$childId; ?>">
                      <div class="child-history-card" role="dialog" aria-modal="true" aria-labelledby="child-history-title-<?php echo (int)$childId; ?>">
                          <header class="child-history-header">
                              <button type="button" class="child-history-back" aria-label="Close points history" data-child-history-close>
                                  <i class="fa-solid fa-arrow-left"></i>
                              </button>
                              <h2 id="child-history-title-<?php echo (int)$childId; ?>">Points History</h2>
                              <button type="button" class="child-history-close" aria-label="Close points history" data-child-history-close>&times;</button>
                          </header>
                          <div class="child-history-body">
                              <div class="child-history-hero">
                                  <img class="child-history-avatar" src="<?php echo htmlspecialchars($child['avatar'] ?? 'images/default-avatar.png'); ?>" alt="<?php echo htmlspecialchars($child['child_name']); ?>">
                                  <div class="child-history-info">
                                      <div class="child-history-name"><?php echo htmlspecialchars($child['child_name']); ?></div>
                                      <div class="child-history-points"><i class="fa-solid fa-star"></i> <?php echo (int)($child['points_earned'] ?? 0); ?> pts</div>
                                  </div>
                              </div>
                              <div class="child-history-filters" data-history-filters>
                                  <button type="button" class="history-filter active" data-history-filter="all">All</button>
                                  <button type="button" class="history-filter" data-history-filter="reward">Rewards Only</button>
                              </div>
                              <p class="child-history-empty" data-history-empty style="display:none;">No history for this filter.</p>
                          <div class="child-history-timeline">
                                  <?php if (!empty($historyByDay)): ?>
                                      <?php foreach ($historyByDay as $day => $items): ?>
                                          <div class="child-history-day" data-history-day>
                                              <div class="child-history-day-title"><?php echo htmlspecialchars(date('M j, Y', strtotime($day))); ?></div>
                                              <ul class="child-history-list">
                                                  <?php foreach ($items as $item): ?>
                                                      <li class="child-history-item" data-history-item data-history-type="<?php echo htmlspecialchars(strtolower($item['type'])); ?>">
                                                          <div>
                                                              <div class="child-history-item-title"><?php echo htmlspecialchars($item['title']); ?></div>
                                                              <div class="child-history-item-meta"><?php echo htmlspecialchars(date('M j, Y, g:i A', strtotime($item['date']))); ?></div>
                                                          </div>
                                                          <div class="child-history-item-points<?php echo ($item['points'] < 0 ? ' is-negative' : ''); ?>"><?php echo ($item['points'] >= 0 ? '+' : '') . (int)$item['points']; ?> pts</div>
                                                      </li>
                                                  <?php endforeach; ?>
                                              </ul>
                                          </div>
                                      <?php endforeach; ?>
                                  <?php else: ?>
                                      <p>No points history yet.</p>
                                  <?php endif; ?>
                              </div>
                          </div>
                      </div>
                  </div>
               <?php endforeach; ?>
               </div>
         <?php else: ?>
               <p>No children added yet. Add your first child below!</p>
         <?php endif; ?>
      </div>
      <div class="routine-completion-section" id="routine-completion-section">
         <h2>Routine Completion Timeline</h2>
         <p>See when routines start and finish, task completion times, and status screen time between tasks.</p>
         <?php if (empty($routineCompletionSessions)): ?>
             <p class="completion-task-empty">No routine completion data yet.</p>
         <?php else: ?>
             <div class="routine-completion-list">
                 <?php foreach ($routineCompletionSessions as $index => $session): ?>
                     <?php
                         $sessionId = (int) ($session['id'] ?? 0);
                         $tasks = $routineCompletionTasks[$sessionId] ?? [];
                         $startedAt = !empty($session['started_at']) ? date('m/d/Y g:i A', strtotime($session['started_at'])) : '--';
                         $completedAt = !empty($session['completed_at']) ? date('m/d/Y g:i A', strtotime($session['completed_at'])) : '--';
                         $completedBy = ($session['completed_by'] ?? '') === 'parent' ? 'parent' : 'child';
                        $badgeLabel = $completedBy === 'parent' ? 'Parent Managed' : 'Child';
                         $openAttr = $index === 0 ? ' open' : '';
                     ?>
                     <details class="routine-completion-card"<?php echo $openAttr; ?>>
                         <summary>
                             <div class="completion-summary">
                                 <div class="completion-title"><?php echo htmlspecialchars($session['routine_title'] ?? 'Routine'); ?></div>
                                 <div class="completion-child"><?php echo htmlspecialchars($session['child_display_name'] ?? 'Child'); ?></div>
                             </div>
                             <div class="completion-meta">
                                 <span>Ended: <?php echo htmlspecialchars($completedAt); ?></span>
                                 <span class="completion-badge <?php echo $completedBy; ?>"><?php echo $badgeLabel; ?></span>
                             </div>
                         </summary>
                         <div class="completion-body">
                             <div class="completion-times">
                                 <span>Started: <?php echo htmlspecialchars($startedAt); ?></span>
                                 <span>Ended: <?php echo htmlspecialchars($completedAt); ?></span>
                                 <?php if ($completedBy === 'parent'): ?>
                                     <span class="completion-note">Completed by parent (no timing data).</span>
                                 <?php endif; ?>
                             </div>
                             <div class="completion-task-list">
                                 <?php if (empty($tasks)): ?>
                                     <div class="completion-task-empty">No task timing data recorded.</div>
                                 <?php else: ?>
                                     <?php foreach ($tasks as $taskRow): ?>
                                         <?php
                                             $taskDoneAt = !empty($taskRow['completed_at']) ? date('g:i A', strtotime($taskRow['completed_at'])) : '--';
                                             $statusSeconds = (int) ($taskRow['status_screen_seconds'] ?? 0);
                                             $scheduledSeconds = $taskRow['scheduled_seconds'] ?? null;
                                             if ($completedBy === 'parent') {
                                                 $taskLimitMinutes = (int) ($taskRow['task_time_limit'] ?? 0);
                                                 $scheduledSeconds = $taskLimitMinutes > 0 ? $taskLimitMinutes * 60 : null;
                                             }
                                             $scheduledLabel = $formatDurationOrDash($scheduledSeconds);
                                             $actualLabel = $formatDurationOrDash($taskRow['actual_seconds'] ?? null);
                                         ?>
                                         <div class="completion-task-row">
                                             <div class="completion-task-header">
                                                 <span class="completion-task-title"><?php echo htmlspecialchars($taskRow['task_title'] ?? 'Task'); ?></span>
                                                 <span class="completion-task-time">Task done: <?php echo htmlspecialchars($taskDoneAt); ?></span>
                                             </div>
                                             <div class="completion-task-meta">
                                                 <span><strong>Scheduled:</strong> <?php echo htmlspecialchars($scheduledLabel); ?></span>
                                                 <?php if ($completedBy === 'child'): ?>
                                                     <span><strong>Actual:</strong> <?php echo htmlspecialchars($actualLabel); ?></span>
                                                     <span><strong>Status screen:</strong> <?php echo $formatDuration($statusSeconds); ?></span>
                                                 <?php endif; ?>
                                             </div>
                                         </div>
                                     <?php endforeach; ?>
                                 <?php endif; ?>
                             </div>
                         </div>
                     </details>
                 <?php endforeach; ?>
             </div>
         <?php endif; ?>
      </div>
      <div class="routine-analytics">
         <h2>Routine Overtime Insights</h2>
         <p>Track where routines run long so you can coach kids on timing and adjust expectations.</p>
         <div class="overtime-grid">
            <div class="overtime-card">
               <h3>Top Overtime by Child</h3>
               <?php $topChild = array_slice($overtimeByChild, 0, 5); ?>
               <?php if (!empty($topChild)): ?>
                   <table class="overtime-table">
                      <thead>
                         <tr>
                            <th>Child</th>
                            <th>Occurrences</th>
                            <th>Total OT (min)</th>
                         </tr>
                      </thead>
                      <tbody>
                         <?php foreach ($topChild as $childRow): ?>
                             <tr>
                                <td><?php echo htmlspecialchars($childRow['child_display_name']); ?></td>
                                <td><?php echo (int) $childRow['occurrences']; ?></td>
                                <td><?php echo round(((int) $childRow['total_overtime_seconds']) / 60, 1); ?></td>
                             </tr>
                         <?php endforeach; ?>
                      </tbody>
                   </table>
               <?php else: ?>
                   <p class="overtime-empty">No overtime data recorded yet.</p>
               <?php endif; ?>
            </div>
            <div class="overtime-card">
               <h3>Routines with Most Overtime</h3>
               <?php $topRoutine = array_slice($overtimeByRoutine, 0, 5); ?>
               <?php if (!empty($topRoutine)): ?>
                   <table class="overtime-table">
                      <thead>
                         <tr>
                            <th>Routine</th>
                            <th>Occurrences</th>
                            <th>Total OT (min)</th>
                         </tr>
                      </thead>
                      <tbody>
                         <?php foreach ($topRoutine as $routineRow): ?>
                             <tr>
                                <td>
                                    <button type="button"
                                            class="routine-log-link"
                                            data-routine-log-trigger
                                            data-routine-id="<?php echo (int) $routineRow['routine_id']; ?>"
                                            data-routine-title="<?php echo htmlspecialchars($routineRow['routine_title']); ?>">
                                        <?php echo htmlspecialchars($routineRow['routine_title']); ?>
                                    </button>
                                </td>
                                <td><?php echo (int) $routineRow['occurrences']; ?></td>
                                <td><?php echo round(((int) $routineRow['total_overtime_seconds']) / 60, 1); ?></td>
                             </tr>
                         <?php endforeach; ?>
                      </tbody>
                   </table>
               <?php else: ?>
                   <p class="overtime-empty">No recurring overtime yet. Great job!</p>
               <?php endif; ?>
            </div>
         </div>
         <div class="overtime-card" id="overtime-section" style="margin-top: 20px;">
            <h3>Most Recent Overtime Events</h3>
            <?php if (!empty($overtimeLogGroups)): ?>
                <div class="overtime-accordion">
                    <?php $firstDate = true; ?>
                    <?php foreach ($overtimeLogGroups as $dateGroup): ?>
                        <details class="overtime-date" <?php echo $firstDate ? 'open' : ''; ?>>
                            <summary>
                                <span class="ot-date-label"><?php echo htmlspecialchars($dateGroup['label']); ?></span>
                                <span class="overtime-date-count"><?php echo (int) $dateGroup['count']; ?> event<?php echo $dateGroup['count'] === 1 ? '' : 's'; ?></span>
                            </summary>
                            <div class="overtime-routine-list">
                                <?php foreach ($dateGroup['routines'] as $routineGroup): ?>
                                    <details class="overtime-routine" data-routine-id="<?php echo (int) ($routineGroup['entries'][0]['routine_id'] ?? 0); ?>" open>
                                        <summary>
                                            <span class="ot-routine-title"><?php echo htmlspecialchars($routineGroup['title']); ?></span>
                                            <span class="overtime-routine-count"><?php echo count($routineGroup['entries']); ?> miss<?php echo count($routineGroup['entries']) === 1 ? '' : 'es'; ?></span>
                                        </summary>
                                        <div class="overtime-card-list">
                                            <?php foreach ($routineGroup['entries'] as $entry): ?>
                                                <?php $occurTs = strtotime($entry['occurred_at']); ?>
                                                <div class="overtime-card-row">
                                                    <div class="ot-row-header">
                                                        <span class="ot-task"><?php echo htmlspecialchars($entry['task_title']); ?></span>
                                                        <span class="ot-time"><?php echo $occurTs ? date('g:i A', $occurTs) : 'Time unavailable'; ?></span>
                                                    </div>
                                                    <div class="ot-meta"><strong>Child:</strong> <?php echo htmlspecialchars($entry['child_display_name']); ?></div>
                                                    <div class="ot-meta">
                                                        <strong>Scheduled:</strong> <?php echo $formatDuration($entry['scheduled_seconds']); ?>
                                                        <strong>Actual:</strong> <?php echo $formatDuration($entry['actual_seconds']); ?>
                                                    </div>
                                                    <div class="ot-meta ot-overtime"><strong>Overtime:</strong> <?php echo $formatDuration($entry['overtime_seconds']); ?></div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </details>
                                <?php endforeach; ?>
                            </div>
                        </details>
                        <?php $firstDate = false; ?>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="overtime-empty">No overtime events have been logged yet.</p>
            <?php endif; ?>
         </div>
         <div class="routine-log-modal" id="routine-log-modal" aria-hidden="true" role="dialog" aria-modal="true">
            <div class="routine-log-dialog">
                <div class="routine-log-header">
                    <h4 class="routine-log-title" data-role="routine-log-title">Routine Overtime</h4>
                    <button type="button" class="routine-log-close" data-role="routine-log-close" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
                </div>
                <div class="routine-log-body" data-role="routine-log-body"></div>
            </div>
         </div>
      </div>
      
      
      
    </main>
    <div class="adjust-modal-backdrop" data-role="adjust-modal">
        <div class="adjust-modal">
            <header class="adjust-modal-header">
                <button type="button" class="adjust-modal-back" data-action="close-adjust" aria-label="Close adjust points">
                    <i class="fa-solid fa-arrow-left"></i>
                </button>
                <h3 data-role="adjust-title">Adjust Points</h3>
                <button type="button" class="adjust-modal-close" data-action="close-adjust" aria-label="Close adjust points">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </header>
            <div class="adjust-modal-body">
                <div class="adjust-child-card">
                    <img class="adjust-child-avatar" data-role="adjust-child-avatar" src="images/default-avatar.png" alt="Child avatar">
                    <div class="adjust-child-info">
                        <div class="adjust-child-name" data-role="adjust-child-name">Child</div>
                    </div>
                </div>
                <form method="POST" class="adjust-form">
                    <div class="adjust-points-panel">
                        <div class="adjust-current-points">
                            <i class="fa-solid fa-star"></i>
                            <span data-role="adjust-current-points">0</span> pts
                        </div>
                        <label for="adjust_points_input" class="sr-only">Points adjustment</label>
                        <div class="adjust-control">
                            <button type="button" class="adjust-step adjust-step-minus" data-action="decrement-points">-</button>
                            <input id="adjust_points_input" type="number" name="points_delta" step="1" value="1" required data-stepper="false">
                            <button type="button" class="adjust-step adjust-step-plus" data-action="increment-points">+</button>
                        </div>
                    </div>
                    <div class="form-group adjust-reason">
                        <label for="adjust_reason_input">Reason</label>
                        <input id="adjust_reason_input" type="text" name="point_reason" maxlength="255" placeholder="Optional">
                    </div>
                    <input type="hidden" name="child_user_id" data-role="adjust-child-id">
                    <input type="hidden" name="adjust_child_points" value="1">
                    <div class="points-adjust-actions">
                        <button type="submit" class="button approve-button adjust-confirm">Confirm</button>
                        <button type="button" class="adjust-cancel" data-action="close-adjust">Cancel</button>
                    </div>
                </form>
                <div class="adjust-history" data-role="adjust-history">
                    <h4>Recent adjustments</h4>
                    <ul data-role="adjust-history-list"></ul>
                </div>
            </div>
        </div>
    </div>
   <div class="week-modal-backdrop" data-week-modal>
        <div class="week-modal" role="dialog" aria-modal="true" aria-labelledby="week-modal-title">
            <header>
                <h3 id="week-modal-title">Week Schedule</h3>
                <button type="button" class="week-modal-close" data-week-modal-close aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
            </header>
            <div class="week-modal-body" data-week-modal-body></div>
        </div>
    </div>
   <div class="help-modal" data-help-modal>
      <div class="help-card" role="dialog" aria-modal="true" aria-labelledby="help-title">
         <header>
            <h2 id="help-title">Task Help</h2>
            <button type="button" class="help-close" data-help-close aria-label="Close help"><i class="fa-solid fa-xmark"></i></button>
         </header>
         <div class="help-body">
            <section class="help-section">
               <h3>Parent view</h3>
               <ul>
                  <li>Create one-time or repeating tasks with optional end dates, time-of-day, and due time.</li>
                  <li>Use the calendar or list view and click an item to open Task Details.</li>
                  <li>Start timers in Task Details; a floating timer appears if you close the modal.</li>
                  <li>Finish tasks from Task Details to auto-approve and award points.</li>
                  <li>Approve or reject completed tasks (with optional notes) in Waiting Approval.</li>
               </ul>
            </section>
         </div>
      </div>
   </div>
   <nav class="nav-mobile-bottom" aria-label="Primary">
      <a class="nav-mobile-link<?php echo $dashboardActive ? ' is-active' : ''; ?>" href="dashboard_parent.php"<?php echo $dashboardActive ? ' aria-current="page"' : ''; ?>>
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
     <p>Child Task and Chores App - Ver 3.17.6</p>
   </footer>
<div class="child-remove-backdrop" data-child-remove-modal aria-hidden="true">
    <div class="child-remove-modal" role="dialog" aria-modal="true" aria-labelledby="child-remove-title">
        <header>
            <h3 id="child-remove-title">Remove Child</h3>
            <button type="button" class="modal-close" data-action="child-remove-cancel" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
        </header>
        <p class="subtext">Choose whether to temporarily remove the child (data kept) or permanently delete all data.</p>
        <div class="actions">
            <button type="button" class="button" data-action="child-remove-soft">Remove (keep data)</button>
            <button type="button" class="button danger" data-action="child-remove-hard">Delete permanently</button>
            <button type="button" class="button secondary" data-action="child-remove-cancel">Cancel</button>
        </div>
    </div>
</div>
<div class="member-remove-backdrop" data-member-remove-modal aria-hidden="true">
    <div class="member-remove-modal" role="dialog" aria-modal="true" aria-labelledby="member-remove-title">
        <header>
            <h3 id="member-remove-title">Remove User</h3>
            <button type="button" class="modal-close" data-action="member-remove-cancel" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
        </header>
        <p class="subtext">Are you sure you want to remove this user?</p>
        <div class="actions">
            <button type="button" class="button danger" data-action="member-remove-confirm">Remove</button>
            <button type="button" class="button secondary" data-action="member-remove-cancel">Cancel</button>
        </div>
    </div>
</div>
  <script src="js/number-stepper.js" defer></script>
</body>
</html>






