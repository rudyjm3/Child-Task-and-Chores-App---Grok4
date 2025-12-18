<?php
// dashboard_parent.php - Parent dashboard
// Purpose: Display parent dashboard with child overview and management links
// Inputs: Session data
// Outputs: Dashboard interface
// Version: 3.12.2 (Notifications moved to header-triggered modal, Font Awesome icons, routine/reward updates)

require_once __DIR__ . '/includes/functions.php';

session_start(); // Force session start to load existing session
error_log("Dashboard Parent: user_id=" . (isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 'null') . ", role=" . (isset($_SESSION['role']) ? $_SESSION['role'] : 'null') . ", session_id=" . session_id() . ", cookie=" . (isset($_SERVER['HTTP_COOKIE']) ? $_SERVER['HTTP_COOKIE'] : 'none'));
if (!isset($_SESSION['user_id']) || !canCreateContent($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
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
        $target_points = filter_input(INPUT_POST, 'target_points', FILTER_VALIDATE_INT);
        $start_date = filter_input(INPUT_POST, 'start_date', FILTER_SANITIZE_STRING);
        $end_date = filter_input(INPUT_POST, 'end_date', FILTER_SANITIZE_STRING);
        $reward_id = filter_input(INPUT_POST, 'reward_id', FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE);
        $message = createGoal($main_parent_id, $child_user_id, $title, $target_points, $start_date, $end_date, $reward_id, $_SESSION['user_id'])
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
            // Fetch points for message only
            $pointsStmt = $db->prepare("SELECT target_points FROM goals WHERE id = :goal_id AND parent_user_id = :parent_id");
            $pointsStmt->execute([':goal_id' => $goal_id, ':parent_id' => $main_parent_id]);
            $points_value = $pointsStmt->fetchColumn();
            $approved = approveGoal($goal_id, $main_parent_id);
            if ($approved) {
                $message = "Goal approved!" . ($points_value !== false ? " Child earned " . (int)$points_value . " points." : "");
            } else {
                $message = "Failed to approve goal.";
            }
        } else {
            $rejectError = null;
            if (rejectGoal($goal_id, $main_parent_id, $comment, $rejectError)) {
                $message = "Goal rejected.";
            } else {
                $message = "Failed to reject goal." . ($rejectError ? " Reason: " . htmlspecialchars($rejectError) : "");
            }
        }
    } elseif (isset($_POST['fulfill_reward'])) {
        $reward_id = filter_input(INPUT_POST, 'reward_id', FILTER_VALIDATE_INT);
        if (!$reward_id && isset($_POST['fulfill_reward'])) {
            $reward_id = filter_input(INPUT_POST, 'fulfill_reward', FILTER_VALIDATE_INT);
        }
        $parent_notification_id = filter_input(INPUT_POST, 'parent_notification_id', FILTER_VALIDATE_INT);
        $message = ($reward_id && fulfillReward($reward_id, $main_parent_id, $_SESSION['user_id']))
            ? "Reward fulfillment recorded."
            : "Unable to mark reward as fulfilled.";
        if ($message === "Reward fulfillment recorded." && $parent_notification_id) {
            ensureParentNotificationsTable();
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

$parentNotices = getParentNotifications($main_parent_id);
$parentNew = $parentNotices['new'] ?? [];
$parentRead = $parentNotices['read'] ?? [];
$parentDeleted = $parentNotices['deleted'] ?? [];
$parentNotificationCount = count($parentNew);
$parentNotices = getParentNotifications($main_parent_id);
$parentNew = $parentNotices['new'] ?? [];
$parentRead = $parentNotices['read'] ?? [];
$parentDeleted = $parentNotices['deleted'] ?? [];
$parentNotificationCount = count($parentNew);
$getRewardFulfillMeta = function($rewardId) use ($db) {
    static $cache = [];
    $rewardId = (int)$rewardId;
    if ($rewardId <= 0) return null;
    if (isset($cache[$rewardId])) return $cache[$rewardId];
    $stmt = $db->prepare("SELECT fulfilled_on, fulfilled_by FROM rewards WHERE id = :id");
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Parent Dashboard</title>
    <link rel="stylesheet" href="css/main.css?v=3.12.2">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css" integrity="Evv84Mr4kqVGRNSgIGL/F/aIDqQb7xQ2vcrdIwxfjThSH8CSR7PBEakCr51Ck+w+/U6swU2Im1vVX0SVk9ABhg==" crossorigin="anonymous" referrerpolicy="no-referrer">
    <style>
        .dashboard { padding: 20px; max-width: 900px; margin: 0 auto; }
        .children-overview, .management-links, .active-rewards, .redeemed-rewards, .pending-approvals, .completed-goals, .manage-family { margin-top: 20px; }
        .children-overview-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; }
        .child-info-card, .reward-item, .goal-item { background-color: #f5f5f5; padding: 15px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        .child-info-card { display: flex; flex-direction: column; gap: 16px; min-height: 100%; max-width: fit-content; }
        .child-info-header { display: flex; align-items: center; gap: 16px; }
        .child-info-header img { width: 64px; height: 64px; border-radius: 50%; object-fit: cover; border: 2px solid #ececec; }
        .child-info-header-details { display: flex; flex-direction: column; gap: 4px; }
        .child-info-name { font-size: 1.15em; font-weight: 600; margin: 0; color: #333; }
        .child-info-meta { margin: 0; font-size: 0.9em; color: #666; }
        .child-info-body { display: flex; gap: 16px; align-items: flex-start; }
        .child-info-stats { display: flex; flex-direction: column; gap: 12px; max-width: 240px; min-width: 195px; }
        /* .child-info-stats .stat { } */
        .child-info-stats .stat-label { display: block; font-size: 0.85em; color: #666; font-weight: 600; }
        .child-info-stats .stat-value { font-size: 1.4em; font-weight: 600; color: #2e7d32; }
        .child-info-stats .stat-subvalue { display: block; font-size: 0.85em; color: #888; margin-top: 2px; }
        .child-reward-badges { display: flex; justify-content: center; gap: 10px; flex-wrap: nowrap; margin-top: 4px; }
        .child-reward-badge-link { text-decoration: none; display: grid; gap: 2px; align-items: center; justify-items: center; padding: 4px 6px; border-radius: 8px; min-width: 73.25px;}
        .child-reward-badge-link:hover { text-decoration: none;}
        .child-reward-badge-link .badge-count { font-size: 1.6em; font-weight: 700; color: #2e7d32; line-height: 1.1; }
        .child-reward-badge-link .badge-label { font-size: 0.85em; color: #666; }
        .points-progress-wrapper { display: flex; flex-direction: column; align-items: center; gap: 6px; flex: 1; }
        .points-progress-label { font-size: 0.9em; font-weight: 600; color: #555; text-align: center; }
        .points-number { font-size: 1.6em; font-weight: 700; color: #2e7d32; line-height: 1; }
        .child-info-actions { display: flex; flex-wrap: wrap; gap: 10px; }
        .child-info-actions form { margin: 0; flex-grow: 1; }
        .child-info-actions a { flex-grow: 1; }
        .child-info-actions form button { width: 100%; }
        .child-badge-row { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin-top: 6px; }
        .badge-pill { display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 8px; background: transparent; color: #0d47a1; font-weight: 700; border: 1px solid #d5def0; font-size: 0.95em; text-decoration: none; }
        .badge-pill:hover { background: #eef4ff; text-decoration: none; }
        .badge-pill i { font-size: 0.95em; }
        .adjust-button { background: #ff9800 !important; color: #fff; display: block; gap: 4px; justify-items: center; font-weight: 700; }
        .adjust-button .label { font-size: 0.95em; }
        .adjust-button .icon { font-size: 1.1em; line-height: 1; }
        .points-adjust-card { border: 1px dashed #c8e6c9; background: #fdfefb; padding: 10px 12px; border-radius: 6px; display: grid; gap: 8px; }
        .points-adjust-card .button { width: 100%; }
        body.modal-open { overflow: hidden; }
        .adjust-modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.55); display: none; align-items: center; justify-content: center; z-index: 3000; padding: 12px; }
        .adjust-modal-backdrop.open { display: flex; }
        .adjust-modal { background: #fff; border-radius: 10px; padding: 18px; max-width: 420px; width: min(420px, 100%); box-shadow: 0 14px 36px rgba(0,0,0,0.25); display: grid; gap: 12px; }
        .adjust-modal header { display: flex; justify-content: space-between; align-items: center; }
        .adjust-modal h3 { margin: 0; font-size: 1.1rem; }
        .adjust-modal-close { background: transparent; border: none; font-size: 1.4rem; cursor: pointer; }
        .adjust-control { display: grid; grid-template-columns: auto 1fr auto; gap: 8px; align-items: center; }
        .adjust-control button { width: 44px; height: 44px; font-size: 1.2rem; }
        .adjust-control input[type="number"] { width: 100%; padding: 10px; font-size: 1rem; text-align: center; }
        .adjust-control input[type="number"]::-webkit-outer-spin-button,
        .adjust-control input[type="number"]::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
        .adjust-control input[type="number"] { -moz-appearance: textfield; }
        .adjust-history { background: #f9f9f9; border: 1px solid #e0e0e0; border-radius: 8px; padding: 10px; max-height: 180px; overflow-y: auto; }
        .adjust-history h4 { margin: 0 0 8px; font-size: 0.95rem; }
        .adjust-history ul { list-style: none; padding: 0; margin: 0; display: grid; gap: 8px; }
        .adjust-history li { display: grid; gap: 2px; font-size: 0.9rem; }
        .adjust-history .delta { font-weight: 700; }
        .adjust-history .delta.positive { color: #2e7d32; }
        .adjust-history .delta.negative { color: #c62828; }
        .adjust-history .meta { color: #666; font-size: 0.85rem; }
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
        .manage-family { background: #f9f9f9; border-radius: 8px; padding: 20px; }
        .family-form { display: none; } /* JS toggle for wizard */
        .family-form.active { display: block; }
        .avatar-preview { width: 50px; height: 50px; border-radius: 50%; margin: 5px; cursor: pointer; }
        .avatar-options { display: flex; flex-wrap: wrap; justify-content: center; gap: 10px; }
        .avatar-option { width: 60px; height: 60px; border-radius: 50%; cursor: pointer; border: 2px solid #ddd; }
        .avatar-option.selected { border-color: #4caf50; }
        .upload-preview { max-width: 100px; max-height: 100px; border-radius: 50%; }
        .mother-badge { background: #e91e63; color: white; padding: 2px 6px; border-radius: 3px; font-size: 0.8em; }
        .father-badge { background: #2196f3; color: white; padding: 2px 6px; border-radius: 3px; font-size: 0.8em; }
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
        @media (max-width: 768px) {
            .overtime-date > summary, .overtime-routine > summary { padding: 12px; }
            .ot-row-header { flex-direction: column; align-items: flex-start; }
        }
        @media (max-width: 768px) {
            .manage-family { padding: 10px; }
            .button { width: 100%; }
            .child-info-header { flex-direction: column; align-items: flex-start; }
            .child-info-header img { width: 56px; height: 56px; }
            .child-info-body { flex-direction: column; }
            .points-progress-container { width: 100%; height: 140px; }
        }
        /* Notifications Modal */
        .parent-notification-trigger { position: relative; display: inline-flex; align-items: center; justify-content: center; width: 36px; height: 36px; background: #fff; border: 2px solid #c8e6c9; border-radius: 50%; box-shadow: 0 2px 4px rgba(0,0,0,0.12); cursor: pointer; margin-left: 12px; }
        .parent-notification-trigger i { font-size: 18px; color: #4caf50; }
        .parent-notification-badge { position: absolute; top: -6px; right: -8px; background: #e53935; color: #fff; border-radius: 12px; padding: 2px 6px; font-size: 0.75rem; font-weight: 700; min-width: 22px; text-align: center; }
        .no-scroll { overflow: hidden; }
        .parent-notifications-modal { position: fixed; inset: 0; background: rgba(0,0,0,0.5); display: none; align-items: center; justify-content: center; z-index: 4000; padding: 14px; }
        .parent-notifications-modal.open { display: flex; }
        .parent-notifications-card { background: #fff; border-radius: 10px; max-width: 680px; width: min(680px, 100%); max-height: 80vh; overflow: hidden; box-shadow: 0 12px 32px rgba(0,0,0,0.25); display: grid; grid-template-rows: auto auto 1fr; }
        .parent-notifications-card header { display: flex; align-items: center; justify-content: space-between; padding: 12px 14px; border-bottom: 1px solid #e0e0e0; }
        .parent-notifications-card h2 { margin: 0; font-size: 1.1rem; }
        .parent-notifications-close { background: transparent; border: none; font-size: 1.3rem; cursor: pointer; color: #555; }
        .parent-notification-tabs { display: grid; grid-template-columns: repeat(3, minmax(0,1fr)); gap: 8px; padding: 10px 14px 0 14px; }
        .parent-tab-button { padding: 8px; border: 1px solid #c8e6c9; background: #fff; border-radius: 8px; font-weight: 700; color: #1565c0; cursor: pointer; }
        .parent-tab-button.active { background: #e8f5e9; }
        .parent-notification-body { padding: 0 14px 14px 14px; overflow-y: auto; }
        .parent-notification-panel { display: none; }
        .parent-notification-panel.active { display: block; }
        .parent-notification-list { list-style: none; padding: 0; margin: 12px 0; display: grid; gap: 8px; }
        .parent-notification-item { padding: 10px; background: #fff; border: 1px solid #e0e0e0; border-radius: 8px; display: grid; grid-template-columns: auto 1fr auto; gap: 10px; align-items: center; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .parent-notification-item input[type="checkbox"] { width: 19.8px; height: 19.8px; }
        .parent-notification-actions { display: flex; gap: 8px; justify-content: flex-end; margin-top: 8px; }
        .parent-trash-button { border: none; background: transparent; cursor: pointer; font-size: 1.1rem; padding: 4px; color: #b71c1c; }
        .nav-links { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; justify-content: center; margin-top: 8px; }
        .nav-button { display: inline-flex; align-items: center; gap: 6px; padding: 8px 12px; background: #eef4ff; border: 1px solid #d5def0; border-radius: 8px; color: #0d47a1; font-weight: 700; text-decoration: none; }
        .nav-button:hover { background: #dce8ff; }
        .child-remove-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.45); display: none; align-items: center; justify-content: center; z-index: 4000; padding: 16px; }
        .child-remove-backdrop.open { display: flex; }
        .child-remove-modal { background: #fff; border-radius: 12px; max-width: 420px; width: 100%; padding: 18px; box-shadow: 0 16px 38px rgba(0,0,0,0.25); display: grid; gap: 14px; }
        .child-remove-modal header { display: flex; justify-content: space-between; align-items: center; }
        .child-remove-modal .actions { display: grid; gap: 10px; }
        .child-remove-modal .actions .button { width: 100%; }
        .child-remove-modal .subtext { color: #555; font-size: 0.95rem; }
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
                const target = parseInt(el.dataset.points, 10) || 0;
                let current = 0;
                const duration = 800;
                const start = performance.now();
                const step = (now) => {
                    const progress = Math.min(1, (now - start) / duration);
                    current = Math.floor(progress * target);
                    el.textContent = `${current} pts`;
                    if (progress < 1) {
                        requestAnimationFrame(step);
                    } else {
                        el.textContent = `${target} pts`;
                    }
                };
                requestAnimationFrame(step);
            });

            const parentNotifyTrigger = document.querySelector('[data-parent-notify-trigger]');
            const parentModal = document.querySelector('[data-parent-notifications-modal]');
            const parentClose = parentModal ? parentModal.querySelector('[data-parent-notifications-close]') : null;
            const parentTabButtons = parentModal ? parentModal.querySelectorAll('.parent-tab-button') : [];
            const parentPanels = parentModal ? parentModal.querySelectorAll('.parent-notification-panel') : [];
            const setParentTab = (target) => {
                parentTabButtons.forEach(btn => btn.classList.toggle('active', btn.getAttribute('data-tab') === target));
                parentPanels.forEach(panel => panel.classList.toggle('active', panel.getAttribute('data-tab-panel') === target));
            };
            const openParentModal = () => {
                if (!parentModal) return;
                parentModal.classList.add('open');
                document.body.classList.add('no-scroll');
            };
            const closeParentModal = () => {
                if (!parentModal) return;
                parentModal.classList.remove('open');
                document.body.classList.remove('no-scroll');
            };
            if (parentNotifyTrigger && parentModal) {
                parentNotifyTrigger.addEventListener('click', openParentModal);
                if (parentClose) parentClose.addEventListener('click', closeParentModal);
                parentModal.addEventListener('click', (e) => { if (e.target === parentModal) closeParentModal(); });
                document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeParentModal(); });
                parentTabButtons.forEach(btn => {
                    btn.addEventListener('click', () => setParentTab(btn.getAttribute('data-tab')));
                });
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

            const adjustModal = document.querySelector('[data-role="adjust-modal"]');
            const adjustTitle = adjustModal ? adjustModal.querySelector('[data-role="adjust-title"]') : null;
            const adjustChildIdInput = adjustModal ? adjustModal.querySelector('[data-role="adjust-child-id"]') : null;
            const adjustHistoryList = adjustModal ? adjustModal.querySelector('[data-role="adjust-history-list"]') : null;
            const pointsInput = adjustModal ? adjustModal.querySelector('#adjust_points_input') : null;
            const reasonInput = adjustModal ? adjustModal.querySelector('#adjust_reason_input') : null;
            const setBodyScrollLocked = (locked) => {
                if (!document.body) return;
                document.body.classList.toggle('modal-open', !!locked);
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
                    const historyRaw = btn.dataset.history || '[]';
                    let history = [];
                    try { history = JSON.parse(historyRaw); } catch (e) { history = []; }
                    if (adjustTitle) { adjustTitle.textContent = 'Adjust Points - ' + childName; }
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
        });
    </script>
</head>
<body>
   <header>
      <h1>Parent Dashboard</h1>
      <p>Welcome, <?php echo htmlspecialchars($_SESSION['name'] ?? $_SESSION['username']); ?> 
         <?php if ($welcome_role_label): ?>
            <span class="role-badge">(<?php echo htmlspecialchars($welcome_role_label); ?>)</span>
         <?php endif; ?>
      </p>
      <div class="nav-links">
         <a class="nav-button" href="dashboard_parent.php">Dashboard</a>
         <a class="nav-button" href="goal.php">Goals</a>
         <a class="nav-button" href="task.php">Tasks</a>
         <a class="nav-button" href="routine.php">Routines</a>
         <a class="nav-button" href="rewards.php">Rewards</a>
         <a class="nav-button" href="profile.php?self=1">Profile</a>
         <a class="nav-button" href="logout.php">Logout</a>
         <button type="button" class="parent-notification-trigger" data-parent-notify-trigger aria-label="Notifications">
            <i class="fa-solid fa-bell"></i>
            <?php if ($parentNotificationCount > 0): ?>
               <span class="parent-notification-badge"><?php echo (int)$parentNotificationCount; ?></span>
            <?php endif; ?>
         </button>
      </div>
      
   </header>
      <div class="parent-notifications-modal" data-parent-notifications-modal>
      <div class="parent-notifications-card">
         <header>
            <h2>Notifications</h2>
            <button type="button" class="parent-notifications-close" aria-label="Close notifications" data-parent-notifications-close><i class="fa-solid fa-xmark"></i></button>
         </header>
         <div class="parent-notification-tabs" data-role="parent-notification-tabs">
            <button type="button" class="parent-tab-button active" data-tab="new">New (<?php echo count($parentNew); ?>)</button>
            <button type="button" class="parent-tab-button" data-tab="read">Read (<?php echo count($parentRead); ?>)</button>
            <button type="button" class="parent-tab-button" data-tab="deleted">Deleted (<?php echo count($parentDeleted); ?>)</button>
         </div>
         <div class="parent-notification-body">
            <form method="POST" action="dashboard_parent.php" data-tab-panel="new" class="parent-notification-panel active">
                <?php if (!empty($parentNew)): ?>
                    <ul class="parent-notification-list">
                        <?php foreach ($parentNew as $note): ?>
                            <li class="parent-notification-item">
                                <input type="checkbox" name="parent_notification_ids[]" value="<?php echo (int)$note['id']; ?>" aria-label="Mark notification as read">
                                <div>
                                    <div><?php echo htmlspecialchars($note['message']); ?></div>
                                    <div class="parent-notification-meta">
                                        <?php echo htmlspecialchars(date('m/d/Y h:i A', strtotime($note['created_at']))); ?>
                                        <?php if (!empty($note['type'])): ?> | <?php echo htmlspecialchars(str_replace('_', ' ', $note['type'])); ?><?php endif; ?>
                                    <?php
                                        $rewardIdFromLink = null;
                                        $viewLink = !empty($note['link_url']) ? $note['link_url'] : null;
                                        if (!empty($note['link_url'])) {
                                            $urlParts = parse_url($note['link_url']);
                                            if (!empty($urlParts['query'])) {
                                                parse_str($urlParts['query'], $queryVars);
                                                if (!empty($queryVars['highlight_reward'])) {
                                                    $rewardIdFromLink = (int)$queryVars['highlight_reward'];
                                                } elseif (!empty($queryVars['reward_id'])) {
                                                    $rewardIdFromLink = (int)$queryVars['reward_id'];
                                                }
                                            }
                                        }
                                        if ($note['type'] === 'reward_redeemed' && $rewardIdFromLink) {
                                            $viewLink = 'dashboard_parent.php?highlight_redeemed=' . (int)$rewardIdFromLink . '#redeemed-reward-' . (int)$rewardIdFromLink;
                                        }
                                        if ($viewLink) {
                                            echo ' | <a href="' . htmlspecialchars($viewLink) . '">View</a>';
                                        }
                                        $fulfillMeta = $rewardIdFromLink ? $getRewardFulfillMeta($rewardIdFromLink) : null;
                                    ?>
                                </div>
                                <?php if ($note['type'] === 'reward_redeemed' && $rewardIdFromLink): ?>
                                    <?php if (!empty($fulfillMeta) && !empty($fulfillMeta['fulfilled_on'])): ?>
                                        <div class="parent-notification-meta">
                                            Fulfilled on: <?php echo htmlspecialchars(date('m/d/Y h:i A', strtotime($fulfillMeta['fulfilled_on']))); ?>
                                            <?php if (!empty($fulfillMeta['fulfilled_by'])):
                                                $fulfillNameStmt = $db->prepare("SELECT CONCAT(first_name, ' ', last_name) AS name, username FROM users WHERE id = :uid");
                                                $fulfillNameStmt->execute([':uid' => (int)$fulfillMeta['fulfilled_by']]);
                                                $fn = $fulfillNameStmt->fetch(PDO::FETCH_ASSOC);
                                                $fulfillName = $fn && trim($fn['name']) !== '' ? $fn['name'] : ($fn['username'] ?? '');
                                            ?>
                                                by <?php echo htmlspecialchars($fulfillName); ?>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="inline-form" style="margin-top:6px;">
                                            <input type="hidden" name="parent_notification_id" value="<?php echo (int)$note['id']; ?>">
                                            <button type="submit" name="fulfill_reward" value="<?php echo (int)$rewardIdFromLink; ?>" class="button approve-button">Fulfill</button>
                                        </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                    </ul>
                    <div class="parent-notification-actions">
                        <button type="submit" name="mark_parent_notifications_read" class="button secondary">Mark Selected as Read</button>
                    </div>
                <?php else: ?>
                    <p class="parent-notification-meta" style="margin: 12px 0;">No new notifications.</p>
                <?php endif; ?>
            </form>

            <form method="POST" action="dashboard_parent.php" data-tab-panel="read" class="parent-notification-panel">
                <?php if (!empty($parentRead)): ?>
                    <ul class="parent-notification-list">
                        <?php foreach ($parentRead as $note): ?>
                            <li class="parent-notification-item">
                                <input type="checkbox" name="parent_notification_ids[]" value="<?php echo (int)$note['id']; ?>" aria-label="Move to trash">
                                <div>
                                    <div><?php echo htmlspecialchars($note['message']); ?></div>
                                    <div class="parent-notification-meta">
                                        <?php echo htmlspecialchars(date('m/d/Y h:i A', strtotime($note['created_at']))); ?>
                                        <?php
                                            $rewardIdFromLink = null;
                                            $viewLink = !empty($note['link_url']) ? $note['link_url'] : null;
                                            if (!empty($note['link_url'])) {
                                                $urlParts = parse_url($note['link_url']);
                                                if (!empty($urlParts['query'])) {
                                                    parse_str($urlParts['query'], $queryVars);
                                                    if (!empty($queryVars['highlight_reward'])) {
                                                        $rewardIdFromLink = (int)$queryVars['highlight_reward'];
                                                    } elseif (!empty($queryVars['reward_id'])) {
                                                        $rewardIdFromLink = (int)$queryVars['reward_id'];
                                                    }
                                                }
                                            }
                                            if ($note['type'] === 'reward_redeemed' && $rewardIdFromLink) {
                                                $viewLink = 'dashboard_parent.php?highlight_redeemed=' . (int)$rewardIdFromLink . '#redeemed-reward-' . (int)$rewardIdFromLink;
                                            }
                                            if ($viewLink) {
                                                echo ' | <a href="' . htmlspecialchars($viewLink) . '">View</a>';
                                            }
                                            $fulfillMeta = $rewardIdFromLink ? $getRewardFulfillMeta($rewardIdFromLink) : null;
                                        ?>
                                    </div>
                                    <?php if ($note['type'] === 'reward_redeemed' && $rewardIdFromLink): ?>
                                        <?php if (!empty($fulfillMeta) && !empty($fulfillMeta['fulfilled_on'])): ?>
                                            <div class="parent-notification-meta">
                                                Fulfilled on: <?php echo htmlspecialchars(date('m/d/Y h:i A', strtotime($fulfillMeta['fulfilled_on']))); ?>
                                                <?php if (!empty($fulfillMeta['fulfilled_by'])):
                                                    $fulfillNameStmt = $db->prepare("SELECT CONCAT(first_name, ' ', last_name) AS name, username FROM users WHERE id = :uid");
                                                    $fulfillNameStmt->execute([':uid' => (int)$fulfillMeta['fulfilled_by']]);
                                                    $fn = $fulfillNameStmt->fetch(PDO::FETCH_ASSOC);
                                                    $fulfillName = $fn && trim($fn['name']) !== '' ? $fn['name'] : ($fn['username'] ?? '');
                                                ?>
                                                    by <?php echo htmlspecialchars($fulfillName); ?>
                                                <?php endif; ?>
                                            </div>
                                        <?php else: ?>
                                            <div class="inline-form" style="margin-top:6px;">
                                                <input type="hidden" name="parent_notification_id" value="<?php echo (int)$note['id']; ?>">
                                                <button type="submit" name="fulfill_reward" value="<?php echo (int)$rewardIdFromLink; ?>" class="button approve-button">Fulfill</button>
                                            </div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                                <button type="submit" name="trash_parent_single" value="<?php echo (int)$note['id']; ?>" class="parent-trash-button" aria-label="Move to trash"><i class="fa-solid fa-trash"></i></button>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <div class="parent-notification-actions">
                        <button type="submit" name="move_parent_notifications_trash" class="button danger">Move Selected to Trash</button>
                    </div>
                <?php else: ?>
                    <p class="parent-notification-meta" style="margin: 12px 0;">No read notifications.</p>
                <?php endif; ?>
            </form>

            <form method="POST" action="dashboard_parent.php" data-tab-panel="deleted" class="parent-notification-panel">
                <?php if (!empty($parentDeleted)): ?>
                    <ul class="parent-notification-list">
                        <?php foreach ($parentDeleted as $note): ?>
                            <li class="parent-notification-item">
                                <input type="checkbox" name="parent_notification_ids[]" value="<?php echo (int)$note['id']; ?>" aria-label="Delete permanently">
                                <div>
                                    <div><?php echo htmlspecialchars($note['message']); ?></div>
                                    <div class="parent-notification-meta">
                                        Deleted: <?php echo htmlspecialchars(date('m/d/Y h:i A', strtotime($note['deleted_at']))); ?>
                                    </div>
                                </div>
                                <button type="submit" name="delete_parent_single_perm" value="<?php echo (int)$note['id']; ?>" class="parent-trash-button" aria-label="Delete permanently"><i class="fa-solid fa-trash"></i></button>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <div class="parent-notification-actions">
                        <button type="submit" name="delete_parent_notifications_perm" class="button danger">Delete Selected</button>
                    </div>
                <?php else: ?>
                    <p class="parent-notification-meta" style="margin: 12px 0;">Trash is empty.</p>
                <?php endif; ?>
            </form>
         </div>
      </div>
   </div><main class="dashboard">
      <?php if (isset($message)) echo "<p>$message</p>"; ?>
      
      <div class="children-overview">
         <h2>Children Overview</h2>
         <?php if (isset($data['children']) && is_array($data['children']) && !empty($data['children'])): ?>
               <div class="children-overview-grid">
               <?php foreach ($data['children'] as $child): ?>
                  <div class="child-info-card">
                     <div class="child-info-header">
                        <img src="<?php echo htmlspecialchars($child['avatar'] ?? 'default-avatar.png'); ?>" alt="Avatar for <?php echo htmlspecialchars($child['child_name']); ?>">
                        <div class="child-info-header-details">
                           <p class="child-info-name"><?php echo htmlspecialchars($child['child_name']); ?></p>
                           <p class="child-info-meta">Age: <?php echo htmlspecialchars($child['age'] ?? 'N/A'); ?></p>
                        </div>
                     </div>
                     <div class="child-info-body">
                        <div class="child-info-stats">
                           <div class="stat">
                              <span class="stat-label">Tasks Assigned</span>
                              <span class="stat-value"><?php echo (int)($child['task_count'] ?? 0); ?></span>
                           </div>
                           <div class="stat">
                              <span class="stat-label">Goals</span>
                              <span class="stat-value"><?php echo (int)($child['goals_assigned'] ?? 0); ?></span>
                              <span class="stat-subvalue">Target: <?php echo (int)($child['goal_target_points'] ?? 0); ?> pts</span>
                           </div>
                           <div class="stat">
                              <span class="stat-label">Rewards</span>
                              <?php
                                 $childActiveRewards = $activeRewardCounts[$child['child_user_id']] ?? 0;
                                 $childRedeemedRewards = $redeemedRewardCounts[$child['child_user_id']] ?? 0;
                              ?>
                              <div class="child-reward-badges">
                                 <a class="child-reward-badge-link" href="rewards.php#active-child-<?php echo (int)$child['child_user_id']; ?>">
                                    <span class="badge-count"><?php echo $childActiveRewards; ?></span>
                                    <span class="badge-label">active</span>
                                 </a>
                                 <a class="child-reward-badge-link" href="rewards.php#redeemed-child-<?php echo (int)$child['child_user_id']; ?>">
                                    <span class="badge-count"><?php echo $childRedeemedRewards; ?></span>
                                    <span class="badge-label">redeemed</span>
                                 </a>
                                <?php
                                    $pendingRewards = (int)($pendingRewardCounts[$child['child_user_id']] ?? 0);
                                ?>
                                <?php if ($pendingRewards > 0): ?>
                                    <a class="child-reward-badge-link" href="rewards.php#pending-child-<?php echo (int)$child['child_user_id']; ?>">
                                        <span class="badge-count"><?php echo $pendingRewards; ?></span>
                                        <span class="badge-label">awaiting fulfillment</span>
                                    </a>
                                <?php endif; ?>
                              </div>
                           </div>
                   </div>
                   <div class="points-progress-wrapper">
                           <div class="points-progress-label">Points Earned</div>
                           <div class="points-number" data-points="<?php echo (int)($child['points_earned'] ?? 0); ?>">0</div>
                           <?php if (in_array($role_type, ['main_parent', 'secondary_parent'], true)): ?>
                                <button type="button"
                                    class="button adjust-button"
                                    data-role="open-adjust-modal"
                                    data-child-id="<?php echo (int)$child['child_user_id']; ?>"
                                    data-child-name="<?php echo htmlspecialchars($child['child_name']); ?>"
                                    data-history='<?php echo htmlspecialchars(json_encode($child['point_adjustments'] ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)); ?>'>
                                    <span class="label">Points</span>
                                    <span class="icon">+ / -</span>
                                </button>
                            <?php endif; ?>
                        </div>
                     </div>
                     <div class="child-info-actions">
                        <?php if (in_array($role_type, ['main_parent', 'secondary_parent'])): ?>
                            <a href="profile.php?user_id=<?php echo $child['child_user_id']; ?>&type=child" class="button">Edit Child</a>
                        <?php endif; ?>
                        <?php if ($role_type === 'main_parent'): ?>
                            <form method="POST" data-role="child-remove-form">
                                <input type="hidden" name="delete_user_id" value="<?php echo $child['child_user_id']; ?>">
                                <input type="hidden" name="delete_mode" value="soft">
                                <input type="hidden" name="delete_user" value="1">
                                <button type="submit" class="button delete-btn" data-action="remove-child">Remove</button>
                            </form>
                        <?php endif; ?>
                     </div>
                  </div>
               <?php endforeach; ?>
               </div>
         <?php else: ?>
               <p>No children added yet. Add your first child below!</p>
         <?php endif; ?>
      </div>
      <div class="family-members-list">
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
                    <p><?php echo htmlspecialchars($member['name'] ?? $member['username']); ?> 
                        <span class="role-type">(<?php
                            $memberBadge = getUserRoleLabel($member['id']) ?? ($member['role_type'] ?? '');
                            if (!$memberBadge && isset($member['role_type'])) {
                                $memberBadge = ucfirst(str_replace('_', ' ', $member['role_type']));
                            }
                            echo htmlspecialchars($memberBadge);
                        ?>)</span>
                     </p>
                     <?php if (in_array($role_type, ['main_parent', 'secondary_parent']) && ($member['role_type'] ?? '') !== 'main_parent'): ?>
                         <a href="profile.php?edit_user=<?php echo $member['id']; ?>&role_type=<?php echo urlencode($member['role_type']); ?>" class="button edit-btn">Edit</a>
                         <form method="POST" style="display: inline;">
                             <input type="hidden" name="delete_user_id" value="<?php echo $member['id']; ?>">
                             <button type="submit" name="delete_user" class="button delete-btn" 
                                     onclick="return confirm('Are you sure you want to remove this family member?')">
                                 Remove
                             </button>
                         </form>
                     <?php endif; ?>
                 </div>
             <?php endforeach; ?>
         <?php else: ?>
             <p>No family members added yet.</p>
         <?php endif; ?>

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
                     <p><?php echo htmlspecialchars($caregiver['name'] ?? $caregiver['username']); ?></p>
                     <?php if (in_array($role_type, ['main_parent', 'secondary_parent'])): ?>
                         <a href="profile.php?edit_user=<?php echo $caregiver['id']; ?>&role_type=<?php echo urlencode($caregiver['role_type']); ?>" class="button edit-btn">Edit</a>
                         <form method="POST" style="display: inline;">
                             <input type="hidden" name="delete_user_id" value="<?php echo $caregiver['id']; ?>">
                             <button type="submit" name="delete_user" class="button delete-btn" 
                                     onclick="return confirm('Are you sure you want to remove this caregiver?')">
                                 Remove
                             </button>
                         </form>
                     <?php endif; ?>
                 </div>
             <?php endforeach; ?>
         <?php else: ?>
             <p>No caregivers added yet.</p>
         <?php endif; ?>
     </div>
      <?php if (in_array($role_type, ['main_parent', 'secondary_parent', 'family_member'])): ?>
      <div class="manage-family" id="manage-family">
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

      <!-- Rest of sections (Management Links, Rewards, etc.) with name display updates -->
      <div class="management-links">
         <h2>Management Links</h2>
         <a href="task.php" class="button">Create Task</a>
         <a href="rewards.php" class="button">Reward Library</a>
         <div>
               <h3>Create Goal</h3>
               <form method="POST" action="dashboard_parent.php">
                  <div class="form-group">
                     <label for="child_user_id">Child:</label>
                     <select id="child_user_id" name="child_user_id" required>
                        <?php
                        $stmt = $db->prepare("SELECT cp.child_user_id, cp.child_name 
                                             FROM child_profiles cp 
                                             WHERE cp.parent_user_id = :parent_id AND cp.deleted_at IS NULL");
                        $stmt->execute([':parent_id' => $main_parent_id]);
                        $children = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        foreach ($children as $child): ?>
                            <option value="<?php echo $child['child_user_id']; ?>">
                                <?php echo htmlspecialchars($child['child_name']); ?>
                            </option>
                        <?php endforeach; ?>
                     </select>
                  </div>
                  <div class="form-group">
                     <label for="goal_title">Title:</label>
                     <input type="text" id="goal_title" name="goal_title" required>
                  </div>
                  <div class="form-group">
                     <label for="target_points">Target Points:</label>
                     <input type="number" id="target_points" name="target_points" min="1" required>
                  </div>
                  <div class="form-group">
                     <label for="start_date">Start Date:</label>
                     <input type="datetime-local" id="start_date" name="start_date">
                  </div>
                  <div class="form-group">
                     <label for="end_date">End Date:</label>
                     <input type="datetime-local" id="end_date" name="end_date">
                  </div>
                  <div class="form-group">
                     <label for="reward_id">Reward (optional):</label>
                     <select id="reward_id" name="reward_id">
                        <option value="">None</option>
                        <?php foreach ($data['active_rewards'] as $reward): ?>
                            <option value="<?php echo $reward['id']; ?>"><?php echo htmlspecialchars($reward['title']); ?></option>
                        <?php endforeach; ?>
                     </select>
                  </div>
                  <button type="submit" name="create_goal" class="button">Create Goal</button>
               </form>
         </div>
      </div>
      <div class="routine-management">
         <h2>Routine Management</h2>
         <a href="routine.php" class="button">Full Routine Editor</a>
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
      <div class="pending-approvals">
         <h2>Pending Goal Approvals</h2>
         <?php if (isset($data['pending_approvals']) && is_array($data['pending_approvals']) && !empty($data['pending_approvals'])): ?>
              <?php foreach ($data['pending_approvals'] as $approval): ?>
                 <div class="goal-item">
                    <p>Goal: <?php echo htmlspecialchars($approval['title']); ?> (Target: <?php echo htmlspecialchars($approval['target_points']); ?> points)</p>
                    <p>Child: <?php echo htmlspecialchars($approval['child_username']); ?></p>
                    <?php if (!empty($approval['creator_display_name'])): ?>
                        <p>Created by: <?php echo htmlspecialchars($approval['creator_display_name']); ?></p>
                    <?php endif; ?>
                    <p>Requested on: <?php echo htmlspecialchars($approval['requested_at']); ?></p>
                     <form method="POST" action="dashboard_parent.php">
                           <input type="hidden" name="goal_id" value="<?php echo $approval['id']; ?>">
                           <button type="submit" name="approve_goal" class="button approve-button">Approve</button>
                           <button type="submit" name="reject_goal" class="button reject-button">Reject</button>
                           <div class="form-group">
                              <label for="rejection_comment_<?php echo $approval['id']; ?>">Comment (optional):</label>
                              <textarea id="rejection_comment_<?php echo $approval['id']; ?>" name="rejection_comment"></textarea>
                           </div>
                     </form>
                  </div>
               <?php endforeach; ?>
         <?php else: ?>
               <p>No pending approvals.</p>
         <?php endif; ?>
      </div>
      <div class="completed-goals">
         <h2>Completed Goals</h2>
         <?php
         $all_completed_goals = [];
         $parent_id = $_SESSION['user_id'];
         $stmt = $db->prepare("SELECT 
                              g.id, 
                              g.title, 
                              g.target_points, 
                              g.start_date, 
                              g.end_date, 
                              g.completed_at, 
                              u.username as child_username,
                              COALESCE(
                                 NULLIF(TRIM(CONCAT(COALESCE(creator.first_name, ''), ' ', COALESCE(creator.last_name, ''))), ''),
                                 NULLIF(creator.name, ''),
                                 creator.username,
                                 'Unknown'
                              ) AS creator_display_name
                              FROM goals g 
                              JOIN users u ON g.child_user_id = u.id 
                              LEFT JOIN users creator ON g.created_by = creator.id
                              WHERE g.parent_user_id = :parent_id AND g.status = 'completed'");
         $stmt->execute([':parent_id' => $parent_id]);
         $all_completed_goals = $stmt->fetchAll(PDO::FETCH_ASSOC);
         ?>
         <?php if (!empty($all_completed_goals)): ?>
              <?php foreach ($all_completed_goals as $goal): ?>
                 <div class="goal-item">
                    <p>Goal: <?php echo htmlspecialchars($goal['title']); ?> (Target: <?php echo htmlspecialchars($goal['target_points']); ?> points)</p>
                    <p>Child: <?php echo htmlspecialchars($goal['child_username']); ?></p>
                    <?php if (!empty($goal['creator_display_name'])): ?>
                        <p>Created by: <?php echo htmlspecialchars($goal['creator_display_name']); ?></p>
                    <?php endif; ?>
                    <p>Period: <?php echo htmlspecialchars(date('m/d/Y h:i A', strtotime($goal['start_date']))); ?> to <?php echo htmlspecialchars(date('m/d/Y h:i A', strtotime($goal['end_date']))); ?></p>
                     <p>Completed on: <?php echo htmlspecialchars(date('m/d/Y h:i A', strtotime($goal['completed_at']))); ?></p>
                  </div>
               <?php endforeach; ?>
         <?php else: ?>
               <p>No goals completed yet.</p>
         <?php endif; ?>
      </div>
      <div class="rejected-goals">
         <h2>Rejected Goals</h2>
         <?php
         $stmt = $db->prepare("SELECT 
                              g.id, 
                              g.title, 
                              g.target_points, 
                              g.start_date, 
                              g.end_date, 
                              g.rejected_at, 
                              g.rejection_comment, 
                              u.username as child_username, 
                              r.title as reward_title,
                              COALESCE(
                                 NULLIF(TRIM(CONCAT(COALESCE(creator.first_name, ''), ' ', COALESCE(creator.last_name, ''))), ''),
                                 NULLIF(creator.name, ''),
                                 creator.username,
                                 'Unknown'
                              ) AS creator_display_name
                              FROM goals g 
                              JOIN users u ON g.child_user_id = u.id 
                              LEFT JOIN rewards r ON g.reward_id = r.id 
                              LEFT JOIN users creator ON g.created_by = creator.id
                              WHERE g.parent_user_id = :parent_id AND g.status = 'rejected'");
         $stmt->execute([':parent_id' => $_SESSION['user_id']]);
         $rejected_goals = $stmt->fetchAll(PDO::FETCH_ASSOC);
         foreach ($rejected_goals as &$goal) {
               $goal['start_date_formatted'] = date('m/d/Y h:i A', strtotime($goal['start_date']));
               $goal['end_date_formatted'] = date('m/d/Y h:i A', strtotime($goal['end_date']));
               $goal['rejected_at_formatted'] = date('m/d/Y h:i A', strtotime($goal['rejected_at']));
         }
         unset($goal);
         ?>
         <?php if (empty($rejected_goals)): ?>
               <p>No rejected goals.</p>
         <?php else: ?>
              <?php foreach ($rejected_goals as $goal): ?>
                 <div class="goal-item">
                    <p>Goal: <?php echo htmlspecialchars($goal['title']); ?> (Target: <?php echo htmlspecialchars($goal['target_points']); ?> points)</p>
                    <p>Child: <?php echo htmlspecialchars($goal['child_username']); ?></p>
                    <?php if (!empty($goal['creator_display_name'])): ?>
                        <p>Created by: <?php echo htmlspecialchars($goal['creator_display_name']); ?></p>
                    <?php endif; ?>
                    <p>Period: <?php echo htmlspecialchars($goal['start_date_formatted']); ?> to <?php echo htmlspecialchars($goal['end_date_formatted']); ?></p>
                     <p>Reward: <?php echo htmlspecialchars($goal['reward_title'] ?? 'None'); ?></p>
                     <p>Status: Rejected</p>
                     <p>Rejected on: <?php echo htmlspecialchars($goal['rejected_at_formatted']); ?></p>
                     <p>Comment: <?php echo htmlspecialchars($goal['rejection_comment'] ?? 'No comments available.'); ?></p>
                  </div>
               <?php endforeach; ?>
         <?php endif; ?>
      </div>
    </main>
    <div class="adjust-modal-backdrop" data-role="adjust-modal">
        <div class="adjust-modal">
            <header>
                <h3 data-role="adjust-title">Adjust Points</h3>
                <button type="button" class="adjust-modal-close" data-action="close-adjust"><i class="fa-solid fa-xmark"></i></button>
            </header>
            <form method="POST">
                <div class="form-group">
                    <label for="adjust_points_input">Points (positive or negative)</label>
                    <div class="adjust-control">
                        <button type="button" data-action="decrement-points">-</button>
                        <input id="adjust_points_input" type="number" name="points_delta" step="1" value="1" required>
                        <button type="button" data-action="increment-points">+</button>
                    </div>
                </div>
                <div class="form-group">
                    <label for="adjust_reason_input">Reason</label>
                    <input id="adjust_reason_input" type="text" name="point_reason" maxlength="255" placeholder="e.g., Helped sibling, behavior reminder">
                </div>
                <input type="hidden" name="child_user_id" data-role="adjust-child-id">
                <input type="hidden" name="adjust_child_points" value="1">
                <div class="points-adjust-actions">
                    <button type="submit" class="button approve-button">Apply</button>
                </div>
            </form>
            <div class="adjust-history" data-role="adjust-history">
                <h4>Recent adjustments</h4>
                <ul data-role="adjust-history-list"></ul>
            </div>
        </div>
    </div>
    <footer>
     <p>Child Task and Chores App - Ver 3.12.2</p>
   </footer>
</body>
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
</body>
</html>

