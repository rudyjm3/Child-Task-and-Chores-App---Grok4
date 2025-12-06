<?php
// dashboard_parent.php - Parent dashboard
// Purpose: Display parent dashboard with child overview and management links
// Inputs: Session data
// Outputs: Dashboard interface
// Version: 3.5.2 (Fixed family list display for non-main parents by fetching correct main_parent_id; updated name display to use CONCAT(first_name, ' ', last_name))

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
$parentNotices = getParentNotifications($main_parent_id);
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
            $parentNotices = getParentNotifications($main_parent_id);
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
        $message = ($reward_id && fulfillReward($reward_id, $main_parent_id, $_SESSION['user_id']))
            ? "Reward fulfillment recorded."
            : "Unable to mark reward as fulfilled.";
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
            $message = createChildProfile($_SESSION['user_id'], $first_name, $last_name, $child_username, $child_password, $birthday, $avatar, $gender)
                ? "Child added successfully! Username: $child_username, Password: $child_password (share securely)."
                : "Failed to add child. Check for duplicate username.";
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
        if ($delete_user_id) {
            if ($delete_user_id == $main_parent_id) {
                $message = "Cannot remove the main account owner.";
            } else {
                $stmt = $db->prepare("DELETE FROM users 
                                      WHERE id = :user_id AND id IN (
                                          SELECT linked_user_id FROM family_links WHERE main_parent_id = :main_parent_id
                                      )");
                $stmt->execute([':user_id' => $delete_user_id, ':main_parent_id' => $main_parent_id]);
                if ($stmt->rowCount() === 0) {
                    $stmt2 = $db->prepare("DELETE FROM users 
                                           WHERE id = :user_id AND id IN (
                                               SELECT child_user_id FROM child_profiles WHERE parent_user_id = :main_parent_id
                                           )");
                    $stmt2->execute([':user_id' => $delete_user_id, ':main_parent_id' => $main_parent_id]);
                    $message = $stmt2->rowCount() > 0 ? "Child removed successfully." : "Failed to remove user.";
                } else {
                    $message = "User removed successfully.";
                }
            }
        }
    }
}

$data = getDashboardData($_SESSION['user_id']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Parent Dashboard</title>
    <link rel="stylesheet" href="css/main.css?v=3.10.16">
    <style>
        .dashboard { padding: 20px; max-width: 900px; margin: 0 auto; }
        .children-overview, .management-links, .active-rewards, .redeemed-rewards, .pending-approvals, .completed-goals, .manage-family { margin-top: 20px; }
        .children-overview-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; }
        .child-info-card, .reward-item, .goal-item { background-color: #f5f5f5; padding: 15px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        .child-info-card { display: flex; flex-direction: column; gap: 16px; min-height: 100%; }
        .child-info-header { display: flex; align-items: center; gap: 16px; }
        .child-info-header img { width: 64px; height: 64px; border-radius: 50%; object-fit: cover; border: 2px solid #ececec; }
        .child-info-header-details { display: flex; flex-direction: column; gap: 4px; }
        .child-info-name { font-size: 1.15em; font-weight: 600; margin: 0; color: #333; }
        .child-info-meta { margin: 0; font-size: 0.9em; color: #666; }
        .child-info-body { display: flex; gap: 16px; align-items: flex-start; }
        .child-info-stats { display: flex; flex-direction: column; gap: 12px; min-width: 160px; }
        .child-info-stats .stat { }
        .child-info-stats .stat-label { display: block; font-size: 0.85em; color: #666; }
        .child-info-stats .stat-value { font-size: 1.4em; font-weight: 600; color: #2e7d32; }
        .child-info-stats .stat-subvalue { display: block; font-size: 0.85em; color: #888; margin-top: 2px; }
        .points-progress-wrapper { display: flex; flex-direction: column; align-items: center; gap: 10px; flex: 1; }
        .points-progress-label { font-size: 0.9em; color: #555; text-align: center; }
        .points-progress-container { width: 70px; height: 160px; background: #e0e0e0; border-radius: 35px; display: flex; align-items: flex-end; justify-content: center; position: relative; overflow: hidden; }
        .points-progress-fill { width: 100%; height: 0; background: linear-gradient(180deg, #81c784, #4caf50); border-radius: 5px; transition: height 1.2s ease-out; }
        .points-progress-target { position: absolute; top: 25px; left: 50%; transform: translateX(-50%); font-size: 1em; font-weight: 700; width: 100%; color: #fff; text-shadow: 0 2px 2px rgba(0,0,0,0.4); opacity: 0.9; }
        .child-info-actions { display: flex; flex-wrap: wrap; gap: 10px; }
        .child-info-actions form { margin: 0; flex-grow: 1; }
        .child-info-actions a { flex-grow: 1; }
        .child-info-actions form button { width: 100%; }
        .adjust-button { background: #ff9800 !important; color: #fff; display: block; gap: 4px; justify-items: center; font-weight: 700; }
        .adjust-button .label { font-size: 0.95em; }
        .adjust-button .icon { font-size: 1.1em; line-height: 1; }
        .points-adjust-card { border: 1px dashed #c8e6c9; background: #fdfefb; padding: 10px 12px; border-radius: 6px; display: grid; gap: 8px; }
        .points-adjust-card .button { width: 100%; }
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
        .parent-notifications { margin: 16px 0 24px; background: #f5f5f5; border: 1px solid #e0e0e0; border-radius: 10px; padding: 12px 14px; box-shadow: 0 2px 6px rgba(0,0,0,0.06); }
        .parent-notifications.open .parent-notification-list { display: grid; }
        .parent-notifications-header { display: flex; align-items: center; gap: 10px; cursor: pointer; }
        .parent-notifications-title { margin: 0; color: #333; display: flex; align-items: center; gap: 8px; font-weight: 700; }
        .parent-notification-icon { width: 32px; height: 32px; position: relative; display: inline-flex; align-items: center; justify-content: center; background: #fff; border-radius: 50%; border: 2px solid #c8e6c9; box-shadow: 0 2px 4px rgba(0,0,0,0.12); }
        .parent-notification-icon svg { width: 18px; height: 18px; fill: #4caf50; }
        .parent-notification-badge { position: absolute; top: -6px; right: -6px; background: #e53935; color: #fff; border-radius: 12px; padding: 2px 6px; font-size: 0.75rem; font-weight: 700; min-width: 22px; text-align: center; }
        .parent-notification-list { list-style: none; padding: 0; margin: 12px 0; display: none; gap: 8px; }
        .parent-notification-item { padding: 10px; background: #fff; border: 1px solid #e0e0e0; border-radius: 8px; display: grid; gap: 6px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .parent-notification-meta { font-size: 0.9em; color: #666; }
        .parent-notifications-footer { display: flex; justify-content: center; }
        .parent-notifications-footer button { background: transparent; border: none; color: #1565c0; font-weight: 700; cursor: pointer; text-decoration: underline; }
        .parent-notification-tabs { display: grid; grid-template-columns: repeat(3, minmax(0,1fr)); gap: 8px; margin-top: 10px; }
        .parent-tab-button { padding: 8px; border: 1px solid #c8e6c9; background: #fff; border-radius: 8px; font-weight: 700; color: #1565c0; cursor: pointer; }
        .parent-tab-button.active { background: #e8f5e9; }
        .parent-notification-panel { display: none; }
        .parent-notification-panel.active { display: block; }
        .parent-notification-list { list-style: none; padding: 0; margin: 12px 0; display: grid; gap: 8px; }
        .parent-notification-item { padding: 10px; background: #fff; border: 1px solid #e0e0e0; border-radius: 8px; display: grid; grid-template-columns: auto 1fr auto; gap: 10px; align-items: center; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .parent-notification-item input[type="checkbox"] { width: 19.8px; height: 19.8px; }
        .parent-notification-actions { display: flex; gap: 8px; justify-content: flex-end; margin-top: 8px; }
        .parent-trash-button { border: none; background: transparent; cursor: pointer; font-size: 1.1rem; padding: 4px; color: #b71c1c; }
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

            const verticalBars = document.querySelectorAll('.points-progress-container');
            verticalBars.forEach(bar => {
                const fill = bar.querySelector('.points-progress-fill');
                const target = parseInt(bar.dataset.progress, 10) || 0;
                if (fill) {
                    requestAnimationFrame(() => {
                        fill.style.height = `${Math.min(100, Math.max(0, target))}%`;
                    });
                }
            });

            const parentNotifications = document.querySelector('[data-role="parent-notifications"]');
            if (parentNotifications) {
                const toggles = parentNotifications.querySelectorAll('[data-action="toggle-parent-notifications"]');
                const toggle = () => parentNotifications.classList.toggle('open');
                toggles.forEach(btn => btn.addEventListener('click', toggle));
                const tabButtons = parentNotifications.querySelectorAll('.parent-tab-button');
                const panels = parentNotifications.querySelectorAll('.parent-notification-panel');
                tabButtons.forEach(btn => {
                    btn.addEventListener('click', () => {
                        const target = btn.getAttribute('data-tab');
                        tabButtons.forEach(b => b.classList.toggle('active', b === btn));
                        panels.forEach(panel => {
                            panel.classList.toggle('active', panel.getAttribute('data-tab-panel') === target);
                        });
                    });
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
                    if (adjustModal) { adjustModal.classList.add('open'); }
                });
            });

            if (adjustModal) {
                const closeButtons = adjustModal.querySelectorAll('[data-action="close-adjust"]');
                closeButtons.forEach(btn => btn.addEventListener('click', () => adjustModal.classList.remove('open')));
                adjustModal.addEventListener('click', (e) => {
                    if (e.target === adjustModal) {
                        adjustModal.classList.remove('open');
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
                        times.textContent = `Scheduled: ${formatDuration(entry.scheduled_seconds)} · Actual: ${formatDuration(entry.actual_seconds)}`;
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
      <a href="goal.php">Goals</a> | <a href="task.php">Tasks</a> | <a href="routine.php">Routines</a> | <a href="profile.php?self=1">Profile</a> | <a href="logout.php">Logout</a>
   </header>
   <main class="dashboard">
      <?php if (isset($message)) echo "<p>$message</p>"; ?>
      <?php
        $parentNew = $parentNotices['new'] ?? [];
        $parentRead = $parentNotices['read'] ?? [];
        $parentDeleted = $parentNotices['deleted'] ?? [];
        $parentNotificationCount = count($parentNew);
      ?>
      <section class="parent-notifications" data-role="parent-notifications">
        <div class="parent-notifications-header" data-action="toggle-parent-notifications">
            <div class="parent-notification-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" focusable="false"><path d="M12 24a2.5 2.5 0 0 0 2.45-2h-4.9A2.5 2.5 0 0 0 12 24Zm7.12-6.41-1.17-1.11V11a6 6 0 0 0-5-5.9V4a1 1 0 1 0-2 0v1.1A6 6 0 0 0 5.05 11v5.48l-1.17 1.11A1 1 0 0 0 4.6 19h14.8a1 1 0 0 0 .72-1.69Z"/></svg>
                <?php if ($parentNotificationCount > 0): ?>
                    <span class="parent-notification-badge"><?php echo (int)$parentNotificationCount; ?></span>
                <?php endif; ?>
            </div>
            <h2 class="parent-notifications-title">Notifications</h2>
        </div>
        <div class="parent-notification-tabs" data-role="parent-notification-tabs">
            <button type="button" class="parent-tab-button active" data-tab="new">New (<?php echo count($parentNew); ?>)</button>
            <button type="button" class="parent-tab-button" data-tab="read">Read (<?php echo count($parentRead); ?>)</button>
            <button type="button" class="parent-tab-button" data-tab="deleted">Deleted (<?php echo count($parentDeleted); ?>)</button>
        </div>

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
                                            echo ' | <a href="' . htmlspecialchars($note['link_url']) . '">View</a>';
                                        }
                                    ?>
                                </div>
                                <?php if ($note['type'] === 'reward_redeemed' && $rewardIdFromLink): ?>
                                    <div class="inline-form" style="margin-top:6px;">
                                        <button type="submit" name="fulfill_reward" value="<?php echo (int)$rewardIdFromLink; ?>" class="button approve-button">Fulfill</button>
                                    </div>
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
                                            echo ' | <a href="' . htmlspecialchars($note['link_url']) . '">View</a>';
                                        }
                                    ?>
                                </div>
                                <?php if ($note['type'] === 'reward_redeemed' && $rewardIdFromLink): ?>
                                    <div class="inline-form" style="margin-top:6px;">
                                        <button type="submit" name="fulfill_reward" value="<?php echo (int)$rewardIdFromLink; ?>" class="button approve-button">Fulfill</button>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <button type="submit" name="trash_parent_single" value="<?php echo (int)$note['id']; ?>" class="parent-trash-button" aria-label="Move to trash">dY-`</button>
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
                            <button type="submit" name="delete_parent_single_perm" value="<?php echo (int)$note['id']; ?>" class="parent-trash-button" aria-label="Delete permanently">dY-`</button>
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

        <div class="parent-notifications-footer">
            <button type="button" data-action="toggle-parent-notifications">View Notifications</button>
        </div>
      </section>
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
                              <span class="stat-label">Rewards Claimed</span>
                              <span class="stat-value"><?php echo (int)($child['rewards_claimed'] ?? 0); ?></span>
                           </div>
                        </div>
                        <div class="points-progress-wrapper">
                           <div class="points-progress-label">Points Earned</div>
                           <div class="points-progress-container" data-progress="<?php echo (int)($child['points_progress_percent'] ?? 0); ?>" aria-label="Points progress for <?php echo htmlspecialchars($child['child_name']); ?>">
                              <div class="points-progress-fill"></div>
                              <span class="points-progress-target"><?php echo (int)($child['points_earned'] ?? 0); ?> pts</span>
                           </div>
                           <?php if (in_array($role_type, ['main_parent', 'secondary_parent'], true)): ?>
                                <button type="button"
                                    class="button adjust-button"
                                    data-role="open-adjust-modal"
                                    data-child-id="<?php echo (int)$child['child_user_id']; ?>"
                                    data-child-name="<?php echo htmlspecialchars($child['child_name']); ?>"
                                    data-history='<?php echo htmlspecialchars(json_encode($child['point_adjustments'] ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)); ?>'>
                                    <span class="label">Adjust Points</span>
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
                            <form method="POST">
                                <input type="hidden" name="delete_user_id" value="<?php echo $child['child_user_id']; ?>">
                                <button type="submit" name="delete_user" class="button delete-btn" onclick="return confirm('Remove this child and all their data?')">Remove</button>
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
               <h3>Create Reward</h3>
               <form method="POST" action="dashboard_parent.php">
                  <div class="form-group">
                     <label for="reward_title">Title:</label>
                     <input type="text" id="reward_title" name="reward_title" required>
                  </div>
                  <div class="form-group">
                     <label for="reward_description">Description:</label>
                     <textarea id="reward_description" name="reward_description"></textarea>
                  </div>
                  <div class="form-group">
                     <label for="point_cost">Point Cost:</label>
                     <input type="number" id="point_cost" name="point_cost" min="1" required>
                  </div>
                  <button type="submit" name="create_reward" class="button">Create Reward</button>
               </form>
         </div>
         <div>
               <h3>Create Goal</h3>
               <form method="POST" action="dashboard_parent.php">
                  <div class="form-group">
                     <label for="child_user_id">Child:</label>
                     <select id="child_user_id" name="child_user_id" required>
                        <?php
                        $stmt = $db->prepare("SELECT cp.child_user_id, cp.child_name 
                                             FROM child_profiles cp 
                                             WHERE cp.parent_user_id = :parent_id");
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
                    <button type="button" class="routine-log-close" data-role="routine-log-close" aria-label="Close">×</button>
                </div>
                <div class="routine-log-body" data-role="routine-log-body"></div>
            </div>
         </div>
      </div>
      <div class="active-rewards">
         <h2>Active Rewards</h2>
         <?php if (isset($data['active_rewards']) && is_array($data['active_rewards']) && !empty($data['active_rewards'])): ?>
               <?php foreach ($data['active_rewards'] as $reward): ?>
                  <div class="reward-item" id="reward-<?php echo (int) $reward['id']; ?>">
                     <form method="POST" action="dashboard_parent.php" class="reward-edit-form">
                        <input type="hidden" name="reward_id" value="<?php echo (int) $reward['id']; ?>">
                        <?php if (!empty($reward['child_name'])): ?>
                           <p><strong>Assigned to:</strong> <?php echo htmlspecialchars($reward['child_name']); ?></p>
                        <?php else: ?>
                           <p><strong>Assigned to:</strong> All children</p>
                        <?php endif; ?>
                        <div class="form-group">
                           <label for="reward_title_<?php echo (int) $reward['id']; ?>">Title:</label>
                           <input type="text" id="reward_title_<?php echo (int) $reward['id']; ?>" name="reward_title" value="<?php echo htmlspecialchars($reward['title']); ?>" required>
                        </div>
                        <div class="form-group">
                           <label for="reward_description_<?php echo (int) $reward['id']; ?>">Description:</label>
                           <textarea id="reward_description_<?php echo (int) $reward['id']; ?>" name="reward_description"><?php echo htmlspecialchars($reward['description'] ?? ''); ?></textarea>
                        </div>
                        <div class="form-group">
                           <label for="reward_cost_<?php echo (int) $reward['id']; ?>">Point Cost:</label>
                           <input type="number" id="reward_cost_<?php echo (int) $reward['id']; ?>" name="point_cost" min="1" value="<?php echo (int) $reward['point_cost']; ?>" required>
                        </div>
                        <div class="reward-edit-actions">
                           <button type="submit" name="update_reward" class="button">Save Changes</button>
                           <button type="submit" name="delete_reward" class="button reward-delete" onclick="return confirm('Delete this reward?');">Delete</button>
                        </div>
                     </form>
                  </div>
               <?php endforeach; ?>
         <?php else: ?>
               <p>No rewards available.</p>
         <?php endif; ?>
      </div>
      <div class="redeemed-rewards">
         <h2>Redeemed Rewards</h2>
          <?php if (isset($data['redeemed_rewards']) && is_array($data['redeemed_rewards']) && !empty($data['redeemed_rewards'])): ?>
                <?php foreach ($data['redeemed_rewards'] as $reward): ?>
                   <div class="reward-item">
                      <p>Reward: <?php echo htmlspecialchars($reward['title']); ?> (<?php echo htmlspecialchars($reward['point_cost']); ?> points)</p>
                      <p>Description: <?php echo htmlspecialchars($reward['description']); ?></p>
                      <p>Redeemed by: <?php echo htmlspecialchars($reward['child_username'] ?? 'Unknown'); ?></p>
                      <p>Redeemed on: <?php echo !empty($reward['redeemed_on']) ? htmlspecialchars(date('m/d/Y h:i A', strtotime($reward['redeemed_on']))) : 'Date unavailable'; ?></p>
                      <?php if (!empty($reward['fulfilled_on'])): ?>
                          <p>Fulfilled on: <?php echo htmlspecialchars(date('m/d/Y h:i A', strtotime($reward['fulfilled_on']))); ?><?php if (!empty($reward['fulfilled_by_name'])): ?> by <?php echo htmlspecialchars($reward['fulfilled_by_name']); ?><?php endif; ?></p>
                      <?php else: ?>
                          <p class="awaiting-label">Awaiting fulfillment by parent.</p>
                          <form method="POST" action="dashboard_parent.php" class="inline-form">
                              <input type="hidden" name="reward_id" value="<?php echo (int) $reward['id']; ?>">
                              <button type="submit" name="fulfill_reward" class="button approve-button">Mark Fulfilled</button>
                          </form>
                      <?php endif; ?>
                   </div>
                <?php endforeach; ?>
         <?php else: ?>
               <p>No rewards redeemed yet.</p>
         <?php endif; ?>
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
                <button type="button" class="adjust-modal-close" data-action="close-adjust">&times;</button>
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
      <p>Child Task and Chores App - Ver 3.10.16</p>
   </footer>
</body>
</html>














