<?php
// dashboard_child.php - Child dashboard
// Purpose: Display child dashboard with progress and task/reward links
// Inputs: Session data
// Outputs: Dashboard interface
// Version: 3.17.6 (Notifications moved to header-triggered modal, Font Awesome icons)

require_once __DIR__ . '/includes/functions.php';

session_start(); // Force session start to load existing session
error_log("Dashboard Child: user_id=" . (isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 'null') . ", role=" . (isset($_SESSION['role']) ? $_SESSION['role'] : 'null') . ", session_id=" . session_id() . ", cookie=" . (isset($_SERVER['HTTP_COOKIE']) ? $_SERVER['HTTP_COOKIE'] : 'none'));
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'child') {
    header("Location: login.php");
    exit;
}

// Ensure friendly display name
if (!isset($_SESSION['name'])) {
    $_SESSION['name'] = getDisplayName($_SESSION['user_id']);
}

$data = getDashboardData($_SESSION['user_id']);

// Fetch routines for child dashboard
$routines = getRoutines($_SESSION['user_id']);

$goalRows = [];
$dashboardGoals = [];
$goalCelebrations = [];
$goalStmt = $db->prepare("SELECT g.*, r.title AS reward_title, rt.title AS routine_title
                          FROM goals g
                          LEFT JOIN rewards r ON g.reward_id = r.id
                          LEFT JOIN routines rt ON g.routine_id = rt.id
                          WHERE g.child_user_id = :child_id
                            AND g.status IN ('active', 'pending_approval', 'completed')
                          ORDER BY g.start_date ASC");
$goalStmt->execute([':child_id' => $_SESSION['user_id']]);
$goalRows = $goalStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
foreach ($goalRows as &$goalRow) {
    $snap = getGoalProgressSnapshot($goalRow, $_SESSION['user_id']);
    $goalRow['progress'] = $snap['progress'];
    $goalRow['celebration_ready'] = $snap['celebration_ready'];
    if ($goalRow['status'] === 'active' && !empty($goalRow['progress']['is_met'])) {
        $goalRow['status'] = !empty($goalRow['requires_parent_approval']) ? 'pending_approval' : 'completed';
    }
    if (!empty($goalRow['celebration_ready'])) {
        $goalCelebrations[] = [
            'id' => (int) $goalRow['id'],
            'title' => $goalRow['title'] ?? 'Goal achieved'
        ];
    }
    if (in_array($goalRow['status'], ['active', 'pending_approval'], true)) {
        $dashboardGoals[] = $goalRow;
    }
}
unset($goalRow);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['request_completion'])) {
        $goal_id = filter_input(INPUT_POST, 'goal_id', FILTER_VALIDATE_INT);
        if (requestGoalCompletion($goal_id, $_SESSION['user_id'])) {
            $message = "Completion requested! Awaiting parent approval.";
            $data = getDashboardData($_SESSION['user_id']); // Refresh data
        } else {
            $message = "Failed to request completion.";
        }
    } elseif (isset($_POST['mark_notifications_read'])) {
        $ids = array_map('intval', $_POST['notification_ids'] ?? []);
        $ids = array_values(array_filter($ids));
        if (!empty($ids)) {
            ensureChildNotificationsTable();
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $params = $ids;
            $params[] = $_SESSION['user_id'];
            $stmt = $db->prepare("UPDATE child_notifications SET is_read = 1, deleted_at = NULL WHERE id IN ($placeholders) AND child_user_id = ?");
            $stmt->execute($params);
            $message = "Notifications marked as read.";
            $count = count($ids);
            $notificationActionSummary = 'Marked ' . $count . ' notification' . ($count === 1 ? '' : 's') . ' as read.';
            $notificationActionTab = 'read';
            $data = getDashboardData($_SESSION['user_id']);
        }
    } elseif (isset($_POST['move_notifications_trash']) || isset($_POST['trash_single'])) {
        $ids = array_map('intval', $_POST['notification_ids'] ?? []);
        if (isset($_POST['trash_single'])) {
            $ids[] = (int) $_POST['trash_single'];
        }
        $ids = array_values(array_filter($ids));
        if (!empty($ids)) {
            ensureChildNotificationsTable();
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $params = $ids;
            $params[] = $_SESSION['user_id'];
            $stmt = $db->prepare("UPDATE child_notifications SET deleted_at = NOW() WHERE id IN ($placeholders) AND child_user_id = ?");
            $stmt->execute($params);
            $message = "Notifications moved to deleted.";
            $count = count($ids);
            $notificationActionSummary = 'Moved ' . $count . ' notification' . ($count === 1 ? '' : 's') . ' to Deleted.';
            $notificationActionTab = 'deleted';
            $data = getDashboardData($_SESSION['user_id']);
        }
    } elseif (isset($_POST['delete_notifications_perm']) || isset($_POST['delete_single_perm'])) {
        $ids = array_map('intval', $_POST['notification_ids'] ?? []);
        if (isset($_POST['delete_single_perm'])) {
            $ids[] = (int) $_POST['delete_single_perm'];
        }
        $ids = array_values(array_filter($ids));
        if (!empty($ids)) {
            ensureChildNotificationsTable();
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $params = $ids;
            $params[] = $_SESSION['user_id'];
            $stmt = $db->prepare("DELETE FROM child_notifications WHERE id IN ($placeholders) AND child_user_id = ?");
            $stmt->execute($params);
            $message = "Notifications deleted.";
            $count = count($ids);
            $notificationActionSummary = 'Deleted ' . $count . ' notification' . ($count === 1 ? '' : 's') . '.';
            $notificationActionTab = 'deleted';
            $data = getDashboardData($_SESSION['user_id']);
        }
    } elseif (isset($_POST['redeem_reward'])) {
        $reward_id = filter_input(INPUT_POST, 'reward_id', FILTER_VALIDATE_INT);
        $success = ($reward_id && redeemReward($_SESSION['user_id'], $reward_id));
        $_SESSION['flash_message'] = $success
            ? "Reward redeemed successfully!"
            : "Not enough points to redeem this reward.";
        header("Location: dashboard_child.php?open_rewards=1&reward_tab=available");
        exit;
    }
}
$notificationsNew = $data['notifications_new'] ?? [];
$notificationsRead = $data['notifications_read'] ?? [];
$notificationsDeleted = $data['notifications_deleted'] ?? [];
$notificationCount = is_array($notificationsNew) ? count($notificationsNew) : 0;
$notificationActionSummary = $notificationActionSummary ?? '';
$notificationActionTab = $notificationActionTab ?? '';
$flashMessage = $_SESSION['flash_message'] ?? null;
if ($flashMessage !== null) {
    $message = $flashMessage;
    unset($_SESSION['flash_message']);
}

$formatChildNotificationMessage = static function (array $note): string {
    $message = (string) ($note['message'] ?? '');
    $type = (string) ($note['type'] ?? '');
    $highlight = static function (string $text, int $start, int $length): string {
        $prefix = substr($text, 0, $start);
        $title = substr($text, $start, $length);
        $suffix = substr($text, $start + $length);
        return htmlspecialchars($prefix)
            . '<span class="notification-title">' . htmlspecialchars($title) . '</span>'
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

    if (in_array($type, ['task_approved', 'task_rejected', 'task_rejected_closed', 'goal_completed', 'goal_ready', 'goal_reward_earned', 'goal_points_awarded', 'reward_denied', 'reward_fulfilled'], true)) {
        if (preg_match('/:\\s*([^|]+?)(?=\\s*(\\||$))/', $message, $match, PREG_OFFSET_CAPTURE)) {
            return $highlight($message, $match[1][1], strlen($match[1][0]));
        }
    }

    return htmlspecialchars($message);
};

$buildChildNotificationViewLink = static function (array $note): ?string {
    $linkUrl = trim((string) ($note['link_url'] ?? ''));
    $type = (string) ($note['type'] ?? '');
    $viewLink = $linkUrl !== '' ? $linkUrl : null;
    $taskIdFromLink = null;
    $taskInstanceDate = null;
    $rewardIdFromLink = null;

    if ($linkUrl !== '') {
        $urlParts = parse_url($linkUrl);
        if (!empty($urlParts['query'])) {
            parse_str($urlParts['query'], $queryVars);
            if (!empty($queryVars['task_id'])) {
                $taskIdFromLink = (int) $queryVars['task_id'];
            }
            if (!empty($queryVars['instance_date'])) {
                $taskInstanceDate = $queryVars['instance_date'];
            }
            if (!empty($queryVars['highlight_reward'])) {
                $rewardIdFromLink = (int) $queryVars['highlight_reward'];
            } elseif (!empty($queryVars['reward_id'])) {
                $rewardIdFromLink = (int) $queryVars['reward_id'];
            }
        }
        if (!$taskIdFromLink && !empty($urlParts['fragment']) && preg_match('/task-(\d+)/', $urlParts['fragment'], $matches)) {
            $taskIdFromLink = (int) $matches[1];
        }
    }

    if (in_array($type, ['task_completed', 'task_approved', 'task_rejected', 'task_rejected_closed'], true)) {
        if ($taskIdFromLink) {
            $viewLink = 'task.php?task_id=' . (int) $taskIdFromLink;
            if (!empty($taskInstanceDate)) {
                $viewLink .= '&instance_date=' . urlencode($taskInstanceDate);
            }
            $viewLink .= '#task-' . (int) $taskIdFromLink;
        } elseif ($viewLink === null) {
            $viewLink = 'task.php';
        }
    }

    if (in_array($type, ['goal_completed', 'goal_ready', 'goal_points_awarded'], true)) {
        if ($viewLink === null || strpos($viewLink, 'dashboard_child.php') === 0) {
            $viewLink = 'goal.php';
        }
    }

    if (in_array($type, ['reward_redeemed', 'reward_denied', 'goal_reward_earned'], true)) {
        $viewLink = 'dashboard_child.php?open_rewards=1';
        if ($rewardIdFromLink) {
            $viewLink .= '&highlight_reward=' . (int) $rewardIdFromLink . '#reward-' . (int) $rewardIdFromLink;
        }
    }

    if ($type === 'routine_completed') {
        if ($viewLink === null || strpos($viewLink, 'dashboard_child.php') === 0) {
            $viewLink = 'routine.php';
        }
    }

    return $viewLink;
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Child Dashboard</title>
   <link rel="stylesheet" href="css/main.css?v=3.17.6">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css" integrity="Evv84Mr4kqVGRNSgIGL/F/aIDqQb7xQ2vcrdIwxfjThSH8CSR7PBEakCr51Ck+w+/U6swU2Im1vVX0SVk9ABhg==" crossorigin="anonymous" referrerpolicy="no-referrer">
    <style>
        .dashboard { padding: 20px; /*max-width: 720px;*/ max-width: 100%; margin: 0 auto; text-align: center; }
        .points-summary { margin: 20px 0; display: flex; align-items: flex-start; gap: 25px; text-align: left; }
        .points-left { display: contents; }
        .child-identity { display: flex; flex-direction: column; align-items: center; gap: 6px; min-width: 120px; }
        .child-avatar-wrap { position: relative; display: inline-block; }
        .child-avatar { width: 72px; height: 72px; border-radius: 50%; object-fit: cover; border: 3px solid #ffd28a; background: #fff; box-shadow: 0 4px 10px rgba(0,0,0,0.12); }
        .child-first-name { font-size: 1rem; font-weight: 700; color: #263238; }
        .points-total { margin: 0; font-weight: 700; color: #263238; display: flex; flex-direction: column; gap: 6px; text-align: center; }
        .goal-summary { flex: 1; min-width: 220px; background: #fff; border: 2px solid #ffd28a; border-radius: 12px; padding: 12px; box-shadow: 0 4px 10px rgba(0,0,0,0.08); display: grid; gap: 10px; }
        .goal-summary-header { display: flex; align-items: center; justify-content: space-between; gap: 10px; }
        .goal-summary-title { font-weight: 800; color: #ef6c00; margin: 0; font-size: 1rem; }
        .goal-item { background: #fff7e6; border: 1px solid #ffd28a; border-radius: 10px; padding: 10px; display: grid; gap: 6px; text-align: left; }
        .goal-item-title { font-weight: 700; color: #3e2723; }
        .goal-item-meta { font-size: 0.85rem; color: #6d4c41; }
        .goal-item-desc { font-size: 0.85rem; color: #5d4037; }
        .goal-progress-bar { height: 20px; border-radius: 999px; background: #ffe9c6; overflow: hidden; border: 1px solid #ffb74d; }
        .goal-progress-bar span { display: block; height: 100%; background: linear-gradient(90deg, #ff6f61, #ffd54f, #4caf50); background-size: 200% 100%; width: 0; transition: width 300ms ease; animation: goal-spark 2.4s linear infinite; box-shadow: 0 0 8px rgba(255, 111, 97, 0.35); }
        .goal-progress-bar.complete span { background: #4caf50; animation: none; box-shadow: none; }
        .goal-next-needed { font-size: 0.85rem; color: #455a64; }
        .goal-pending-pill { display: inline-flex; align-items: center; gap: 6px; padding: 3px 8px; border-radius: 999px; background: #ffe0b2; color: #ef6c00; font-size: 0.75rem; font-weight: 700; }
        .dashboard-cards { margin: 18px 0 24px; display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 12px; }
        .dashboard-card { background: #fff7e6; border: 2px solid #ffd28a; border-radius: 12px; padding: 14px 12px; display: flex; align-items: center; justify-content: center; gap: 10px; font-weight: 700; color: #5d4037; text-decoration: none; box-shadow: 0 4px 10px rgba(0,0,0,0.08); position: relative; cursor: pointer; appearance: none; font-family: 'Sigmar One', 'Sigma One', cursive; }
        .dashboard-card i { font-size: 1.2rem; color: #ef6c00; }
        .dashboard-card:hover { background: #ffe9c6; }
        .dashboard-card-count { position: absolute; top: 8px; right: 10px; background: #ff6f61; color: #fff; font-size: 0.8rem; min-width: 24px; height: 24px; border-radius: 12px; display: inline-flex; align-items: center; justify-content: center; padding: 0 6px; box-shadow: 0 2px 6px rgba(0,0,0,0.18); }
        .points-total-label { text-transform: uppercase; letter-spacing: 0.05em; color: #ff6f61; margin-right: 6px; font-size: 1.1rem; }
        .points-total-value { color: #00bb01; font-size: 2rem; }
        .points-history-button { display: inline-flex; align-items: center; justify-content: center; gap: 6px; margin: 6px auto 0; background: #fff; border: 2px solid #ffd28a; border-radius: 999px; padding: 6px 12px; color: #ef6c00; font-weight: 700; cursor: pointer; }
        .points-history-button i { font-size: 1rem; }
        .week-calendar { flex: 1; min-width: 220px; text-align: left; }
        .week-days { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 6px; }
        .week-day { background: #f5f5f5; border: 1px solid #d5def0; border-radius: 10px; padding: 8px 0; display: grid; gap: 2px; justify-items: center; font-weight: 700; color: #37474f; cursor: pointer; }
        .week-day.active { background: #ffe0b2; border-color: #ffd28a; }
        .week-day-name-full,
        .week-day-name-initial { font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.04em; }
        .week-day-name-initial { display: none; }
        .week-day-num { font-size: 1rem; }
        .week-schedule { margin-top: 10px; display: grid; gap: 8px; }
        .week-section { display: grid; gap: 6px; }
        .week-section-title { font-weight: 700; color: #37474f; font-size: 0.95rem; }
        .week-section-list { display: grid; gap: 8px; }
        .week-item { display: flex; align-items: center; justify-content: space-between; gap: 10px; background: #fff7e6; border: 1px solid #ffd28a; border-radius: 10px; padding: 8px 10px; text-decoration: none; color: inherit; cursor: pointer; }
        .week-item:hover { background: #ffefcc; }
        .week-item-main { display: flex; align-items: center; gap: 8px; }
        .week-item-icon { color: #ef6c00; }
        .week-item-title { font-weight: 700; color: #3e2723; }
        .week-item-meta { color: #6d4c41; font-size: 0.9rem; }
        .week-item-points { color: #fff; font-size: 0.7rem; font-weight: 600; border-radius: 50px; background-color: #4caf50; padding: 2px 8px; white-space: nowrap; }
        .button { padding: 10px 20px; margin: 5px; background-color: #ff9800; color: white; border: none; border-radius: 5px; cursor: pointer; text-decoration: none; display: inline-block; font-size: 16px; min-height: 44px; }
        .redeem-button { background-color: #2196f3; }
        
        .trash-button { border: none; background: transparent; cursor: pointer; font-size: 1.1rem; padding: 4px; color: #d32f2f; }
        @media (max-width: 900px) {
            .week-day-name-full { display: none; }
            .week-day-name-initial { display: inline; }
            .points-summary { display: grid; grid-template-columns: minmax(160px, max-content) minmax(0, 1fr) minmax(0, 1fr); column-gap: 25px; align-items: start; }
            .points-left { display: flex; flex-direction: column; gap: 18px; }
            .points-left { grid-column: 1; }
            .goal-summary { grid-column: 2; }
            .week-calendar { grid-column: 3; }
        }
        @media (max-width: 768px) { .dashboard { padding: 10px; } .button { width: 100%; } }
        @media (max-width: 700px) {
            .points-summary { display: flex; flex-direction: column; align-items: center; text-align: center; }
            .points-left { display: contents; }
        }
        /* Notifications Modal */
        .notification-trigger { position: relative; display: inline-flex; align-items: center; justify-content: center; width: 34px; height: 34px; background: #fff; border: 2px solid #ffd28a; border-radius: 50%; box-shadow: 0 2px 4px rgba(0,0,0,0.12); cursor: pointer; }
        .notification-trigger i { font-size: 18px; color: #ef6c00; }
        .notification-badge { position: absolute; top: -6px; right: -8px; background: #d32f2f; color: #fff; border-radius: 12px; padding: 2px 6px; font-size: 0.75rem; font-weight: 700; min-width: 22px; text-align: center; }
        .avatar-notification { position: absolute; top: 0; right: 0; transform: translate(35%, -35%); }
        .week-item-badge { display: inline-flex; align-items: center; gap: 4px; margin-left: 8px; padding: 2px 8px; border-radius: 999px; font-size: 0.7rem; font-weight: 700; background: #4caf50; color: #fff; text-transform: uppercase; }
        .week-item-badge.compact { justify-content: center; margin-left: 6px; width: 20px; height: 20px; padding: 0; border-radius: 50%; font-size: 0.65rem; }
        .week-item-badge.overdue { background: #d9534f; }
        .week-item-badge-group { display: inline-flex; align-items: center; }
        .nav-links { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; justify-content: center; margin-top: 8px; }
        .nav-button { display: inline-flex; align-items: center; gap: 6px; padding: 8px 12px; background: #eef4ff; border: 1px solid #d5def0; border-radius: 8px; color: #0d47a1; font-weight: 700; text-decoration: none; }
        .nav-button:hover { background: #dce8ff; }
        .no-scroll { overflow: hidden; }
        .notifications-modal { position: fixed; inset: 0; background: rgba(0,0,0,0.45); display: none; align-items: center; justify-content: center; z-index: 4000; padding: 14px; }
        .notifications-modal.open { display: flex; }
        .notifications-card { background: #fff; border-radius: 10px; max-width: 620px; width: min(620px, 100%); max-height: 80vh; overflow: hidden; box-shadow: 0 12px 32px rgba(0,0,0,0.25); display: grid; grid-template-rows: auto auto 1fr; }
        .notifications-card header { display: flex; align-items: center; justify-content: space-between; padding: 12px 14px; border-bottom: 1px solid #e0e0e0; }
        .notifications-card h2 { margin: 0; font-size: 1.05rem; }
        .notifications-close { background: transparent; border: none; font-size: 1.3rem; cursor: pointer; color: #555; }
        .notification-tabs { display: grid; grid-template-columns: repeat(3, minmax(0,1fr)); gap: 8px; padding: 10px 14px 0 14px; }
        .tab-button { padding: 8px; border: 1px solid #ffd28a; background: #fff; border-radius: 8px; font-weight: 700; color: #ef6c00; cursor: pointer; }
        .tab-button.active { background: #ffe0b2; }
        .notification-body { padding: 0 14px 14px 14px; overflow-y: auto; }
        .notification-panel { display: none; }
        .notification-panel.active { display: block; }
        .notification-list { list-style: none; padding: 0; margin: 12px 0; display: grid; gap: 10px; }
        .notification-bulk { display: flex; align-items: center; gap: 8px; margin-top: 10px; font-weight: 600; color: #37474f; }
        .notification-bulk input { width: 18px; height: 18px; }
        .notification-item { padding: 10px; background: #fff; border: 1px solid #e0e0e0; border-radius: 8px; display: grid; grid-template-columns: auto 1fr auto; gap: 10px; align-items: center; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .notification-item input[type="checkbox"] { width: 19.8px; height: 19.8px; }
        .notification-actions { display: flex; gap: 8px; justify-content: flex-end; margin-top: 8px; }
        .notification-actions .button.danger { background-color: #d32f2f; }
        .notification-title { font-weight: 700; color: #ef6c00; }
        .notification-action-summary { margin-top: 10px; padding: 8px 10px; border-radius: 8px; background: #fff3e0; color: #ef6c00; font-weight: 700; }
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
        @keyframes goal-spark {
            0% { background-position: 200% 50%; }
            100% { background-position: 0% 50%; }
        }
        @keyframes confetti-fall {
            0% { transform: translateY(-20px) rotate(0deg); opacity: 0; }
            10% { opacity: 1; }
            100% { transform: translateY(260px) rotate(160deg); opacity: 0; }
        }
        @keyframes pop-in {
            0% { transform: scale(0.9); opacity: 0; }
            100% { transform: scale(1); opacity: 1; }
        }

        .rewards-modal { position: fixed; inset: 0; background: rgba(0,0,0,0.45); display: none; align-items: center; justify-content: center; z-index: 4100; padding: 14px; }
        .rewards-modal.open { display: flex; }
        .rewards-card { background: #fff; border-radius: 12px; max-width: 720px; width: min(720px, 100%); max-height: 82vh; overflow: hidden; box-shadow: 0 12px 32px rgba(0,0,0,0.25); display: grid; grid-template-rows: auto auto 1fr; }
        .rewards-card header { display: flex; align-items: center; justify-content: space-between; padding: 12px 16px; border-bottom: 1px solid #e0e0e0; }
        .rewards-card h2 { margin: 0; font-size: 1.1rem; }
        .rewards-close { background: transparent; border: none; font-size: 1.3rem; cursor: pointer; color: #555; }
        .rewards-tabs { display: grid; grid-template-columns: repeat(2, minmax(0,1fr)); gap: 8px; padding: 10px 16px 0 16px; }
        .rewards-tab { padding: 8px; border: 1px solid #ffd28a; background: #fff; border-radius: 8px; font-weight: 700; color: #ef6c00; cursor: pointer; }
        .rewards-tab.active { background: #ffe0b2; }
        .rewards-body { padding: 0 16px 16px 16px; overflow-y: auto; }
        .rewards-panel { display: none; }
        .rewards-panel.active { display: block; }
        .reward-list { list-style: none; padding: 0; margin: 12px 0; display: grid; gap: 10px; }
        .reward-list-item { padding: 12px; background: #fff; border: 1px solid #e0e0e0; border-radius: 10px; display: grid; gap: 6px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); text-align: left; }
        .reward-list-item.highlight { outline: 2px solid #ffd28a; box-shadow: 0 0 0 3px rgba(255, 210, 138, 0.35); }
        .reward-list-item .reward-title { font-weight: 700; }
        .reward-list-item .reward-actions { display: flex; justify-content: flex-end; }
        .child-history-modal { position: fixed; inset: 0; background: rgba(0,0,0,0.45); display: none; align-items: center; justify-content: center; z-index: 4200; padding: 14px; }
        .child-history-modal.open { display: flex; }
        .child-history-card { background: #fff; border-radius: 12px; max-width: 620px; width: min(620px, 100%); max-height: 92vh; overflow: hidden; box-shadow: 0 12px 32px rgba(0,0,0,0.25); display: flex; flex-direction: column; }
        .child-history-header { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 12px 16px; border-bottom: 1px solid #e0e0e0; }
        .child-history-card h2 { margin: 0; font-size: 1.1rem; }
        .child-history-close { background: transparent; border: none; font-size: 1.3rem; cursor: pointer; color: #555; }
        .child-history-back { border: none; background: transparent; color: #424242; font-size: 1.1rem; cursor: pointer; display: none; }
        .child-history-body { padding: 12px 16px 16px; overflow-y: auto; text-align: left; flex: 1; min-height: 0; display: grid; gap: 12px; }
        .child-history-hero { display: flex; align-items: center; gap: 12px; padding: 12px; border-radius: 16px; background: #fff; border: 1px solid #eceff4; box-shadow: 0 8px 18px rgba(0,0,0,0.08); }
        .child-history-avatar { width: 56px; height: 56px; border-radius: 50%; object-fit: cover; box-shadow: 0 2px 6px rgba(0,0,0,0.15); }
        .child-history-name { font-weight: 700; color: #263238; }
        .child-history-points { display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 999px; background: #e8f5e9; color: #2e7d32; font-weight: 700; margin-top: 6px; }
        .child-history-filters { display: inline-flex; gap: 6px; padding: 10px; border-radius: 16px; border: 1px solid #eceff4; background: #fff; box-shadow: 0 8px 18px rgba(0,0,0,0.06); }
        .history-filter { border: 2px solid #ffd28a; background: #fff; color: #ef6c00; font-weight: 600; padding: 6px 12px; border-radius: 10px; cursor: pointer; }
        .history-filter.active { background: #ffd28a; color: #ef6c00; }
        .points-history-title { color: #ef6c00; }
        .child-history-empty { color: #9e9e9e; font-weight: 600; text-align: center; }
        .child-history-timeline { display: grid; gap: 12px; }
        .child-history-day { display: grid; gap: 10px; }
        .child-history-day-title { font-weight: 700; color: #8d6e63; }
        .child-history-list { list-style: none; padding: 0; margin: 0; display: grid; gap: 8px; }
        .child-history-item { background: #fff; border: 1px solid #eceff4; border-radius: 14px; padding: 12px; display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; }
        .child-history-item-title { font-weight: 700; color: #3e2723; }
        .child-history-item-meta { color: #6d4c41; font-size: 0.95rem; }
        .child-history-item-points { background: #e8f5e9; color: #2e7d32; padding: 4px 10px; border-radius: 999px; font-weight: 700; white-space: nowrap; }
        .child-history-item-points.is-negative { background: #ffebee; color: #d32f2f; }
        .help-modal { position: fixed; inset: 0; background: rgba(0,0,0,0.45); display: none; align-items: center; justify-content: center; z-index: 4300; padding: 14px; }
        .help-modal.open { display: flex; }
        .help-card { background: #fff; border-radius: 12px; max-width: 720px; width: min(720px, 100%); max-height: 85vh; overflow: hidden; box-shadow: 0 12px 32px rgba(0,0,0,0.25); display: grid; grid-template-rows: auto 1fr; }
        .help-card header { display: flex; align-items: center; justify-content: space-between; padding: 12px 16px; border-bottom: 1px solid #e0e0e0; }
        .help-card h2 { margin: 0; font-size: 1.1rem; }
        .help-close { background: transparent; border: none; font-size: 1.3rem; cursor: pointer; color: #555; }
        .help-body { padding: 12px 16px 16px; overflow-y: auto; display: grid; gap: 12px; }
        .help-section h3 { margin: 0 0 6px; font-size: 1rem; color: #37474f; }
        .help-section ul { margin: 0; padding-left: 18px; display: grid; gap: 6px; color: #455a64; }
        @media (max-width: 768px) {
            .child-history-modal { padding: 0; align-items: stretch; }
            .child-history-card { max-width: none; width: 100%; height: 100%; min-height: 100vh; border-radius: 0; box-shadow: none; background: #f6f3f0; }
            .child-history-header { padding: 12px 16px; background: #f6f3f0; }
            .child-history-back { display: inline-flex; }
            .child-history-close { display: none; }
            .child-history-body { padding: 12px 16px; overflow-y: auto; flex: 1; min-height: 0; }
            .child-history-filters { width: 100%; justify-content: space-between; }
            .history-filter { flex: 1; text-align: center; }
        }
    </style>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const childNotifyTrigger = document.querySelector('[data-child-notify-trigger]');
            const childModal = document.querySelector('[data-child-notifications-modal]');
            const childClose = childModal ? childModal.querySelector('[data-child-notifications-close]') : null;
            const childTabButtons = childModal ? childModal.querySelectorAll('.tab-button') : [];
            const childPanels = childModal ? childModal.querySelectorAll('.notification-panel') : [];
            const childAction = {
                summary: <?php echo json_encode($notificationActionSummary); ?>,
                tab: <?php echo json_encode($notificationActionTab); ?>
            };
            const setChildTab = (target) => {
                childTabButtons.forEach(btn => btn.classList.toggle('active', btn.getAttribute('data-tab') === target));
                childPanels.forEach(panel => panel.classList.toggle('active', panel.getAttribute('data-tab-panel') === target));
            };
            if (childModal) {
                childModal.querySelectorAll('[data-child-bulk-action]').forEach((bulk) => {
                    bulk.addEventListener('change', () => {
                        const form = bulk.closest('form');
                        if (!form) return;
                        const shouldCheck = bulk.checked;
                        form.querySelectorAll('input[name="notification_ids[]"]').forEach((input) => {
                            input.checked = shouldCheck;
                        });
                    });
                });
            }
        const openChildModal = () => {
            if (!childModal) return;
            childModal.classList.add('open');
            document.body.classList.add('no-scroll');
        };
        const closeChildModal = () => {
            if (!childModal) return;
            childModal.classList.remove('open');
            document.body.classList.remove('no-scroll');
        };
            if (childNotifyTrigger && childModal) {
                childNotifyTrigger.addEventListener('click', openChildModal);
                if (childClose) childClose.addEventListener('click', closeChildModal);
                childModal.addEventListener('click', (e) => { if (e.target === childModal) closeChildModal(); });
                document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeChildModal(); });
                childTabButtons.forEach(btn => {
                    btn.addEventListener('click', () => setChildTab(btn.getAttribute('data-tab')));
                });
            }
            if (childAction.summary && childModal) {
                openChildModal();
                if (childAction.tab) {
                    setChildTab(childAction.tab);
                }
            }

            const rewardsOpen = document.querySelector('[data-rewards-open]');
            const rewardsModal = document.querySelector('[data-rewards-modal]');
            const rewardsClose = rewardsModal ? rewardsModal.querySelector('[data-rewards-close]') : null;
            const rewardsTabs = rewardsModal ? rewardsModal.querySelectorAll('[data-rewards-tab]') : [];
            const rewardsPanels = rewardsModal ? rewardsModal.querySelectorAll('[data-rewards-panel]') : [];
            const setRewardsTab = (target) => {
                rewardsTabs.forEach(btn => btn.classList.toggle('active', btn.getAttribute('data-rewards-tab') === target));
                rewardsPanels.forEach(panel => panel.classList.toggle('active', panel.getAttribute('data-rewards-panel') === target));
            };
            const openRewardsModal = () => {
                if (!rewardsModal) return;
                rewardsModal.classList.add('open');
                document.body.classList.add('no-scroll');
            };
            const closeRewardsModal = () => {
                if (!rewardsModal) return;
                rewardsModal.classList.remove('open');
                document.body.classList.remove('no-scroll');
            };
            if (rewardsOpen && rewardsModal) {
                rewardsOpen.addEventListener('click', openRewardsModal);
                if (rewardsClose) rewardsClose.addEventListener('click', closeRewardsModal);
                rewardsModal.addEventListener('click', (e) => { if (e.target === rewardsModal) closeRewardsModal(); });
                document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeRewardsModal(); });
                rewardsTabs.forEach(btn => {
                    btn.addEventListener('click', () => setRewardsTab(btn.getAttribute('data-rewards-tab')));
                });
            }

            const pageParams = new URLSearchParams(window.location.search);
            const openRewards = pageParams.get('open_rewards');
            const rewardTabParam = pageParams.get('reward_tab');
            const highlightReward = pageParams.get('highlight_reward');
            if ((openRewards === '1' || highlightReward) && rewardsModal) {
                openRewardsModal();
                if (rewardTabParam) {
                    setRewardsTab(rewardTabParam === 'redeemed' ? 'redeemed' : 'available');
                }
                if (highlightReward) {
                    const rewardCard = document.getElementById('reward-' + highlightReward)
                        || document.getElementById('redeemed-reward-' + highlightReward);
                    if (rewardCard) {
                        rewardCard.classList.add('highlight');
                        rewardCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }
                }
            }

            const historyOpen = document.querySelector('[data-points-history-open]');
            const historyModal = document.querySelector('[data-points-history-modal]');
            const historyCloseButtons = historyModal ? historyModal.querySelectorAll('[data-points-history-close]') : [];
            const historyFilterButtons = historyModal ? Array.from(historyModal.querySelectorAll('[data-history-filter]')) : [];
            const applyHistoryFilter = (filter) => {
                if (!historyModal) return;
                const items = Array.from(historyModal.querySelectorAll('[data-history-item]'));
                const groups = Array.from(historyModal.querySelectorAll('[data-history-day]'));
                const empty = historyModal.querySelector('[data-history-empty]');
                if (!items.length) {
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
                if (empty) {
                    empty.style.display = anyVisible ? 'none' : 'block';
                }
            };
            const openHistoryModal = () => {
                if (!historyModal) return;
                historyModal.classList.add('open');
                document.body.classList.add('no-scroll');
                historyFilterButtons.forEach(button => {
                    button.classList.toggle('active', (button.dataset.historyFilter || 'all') === 'all');
                });
                applyHistoryFilter('all');
            };
            const closeHistoryModal = () => {
                if (!historyModal) return;
                historyModal.classList.remove('open');
                document.body.classList.remove('no-scroll');
            };
            if (historyOpen && historyModal) {
                historyOpen.addEventListener('click', openHistoryModal);
                historyCloseButtons.forEach(btn => btn.addEventListener('click', closeHistoryModal));
                historyModal.addEventListener('click', (e) => { if (e.target === historyModal) closeHistoryModal(); });
                document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeHistoryModal(); });
            }
            if (historyFilterButtons.length) {
                historyFilterButtons.forEach(button => {
                    button.addEventListener('click', () => {
                        historyFilterButtons.forEach(btn => btn.classList.toggle('active', btn === button));
                        const filter = button.dataset.historyFilter || 'all';
                        applyHistoryFilter(filter);
                    });
                });
                applyHistoryFilter('all');
            }

            const helpOpen = document.querySelector('[data-help-open]');
            const helpModal = document.querySelector('[data-help-modal]');
            const helpClose = helpModal ? helpModal.querySelector('[data-help-close]') : null;
            const openHelp = () => {
                if (!helpModal) return;
                helpModal.classList.add('open');
                document.body.classList.add('no-scroll');
            };
            const closeHelp = () => {
                if (!helpModal) return;
                helpModal.classList.remove('open');
                document.body.classList.remove('no-scroll');
            };
            if (helpOpen && helpModal) {
                helpOpen.addEventListener('click', openHelp);
                if (helpClose) helpClose.addEventListener('click', closeHelp);
                helpModal.addEventListener('click', (e) => { if (e.target === helpModal) closeHelp(); });
                document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeHelp(); });
            }

            const scheduleData = window.weekScheduleData || {};
            const todayDate = window.weekScheduleToday || '';
            const dayButtons = document.querySelectorAll('[data-week-date]');
            const scheduleTarget = document.querySelector('[data-week-schedule]');

            const renderSchedule = (dateKey) => {
                if (!scheduleTarget) return;
                const items = (scheduleData[dateKey] || []).slice().sort((a, b) => {
                    const at = a.time || '99:99';
                    const bt = b.time || '99:99';
                    return at.localeCompare(bt);
                });
                if (items.length === 0) {
                    scheduleTarget.innerHTML = '<div class="week-item"><div class="week-item-main"><i class="fa-solid fa-calendar-day week-item-icon"></i><div><div class="week-item-title">Nothing scheduled</div><div class="week-item-meta">Check back later</div></div></div></div>';
                    return;
                }
                const sections = [
                    { key: 'anytime', label: 'Due Today' },
                    { key: 'morning', label: 'Morning' },
                    { key: 'afternoon', label: 'Afternoon' },
                    { key: 'evening', label: 'Evening' }
                ];
                const buildItem = (item) => {
                    let badge = '';
                      if (item.completed && item.overdue) {
                        badge = '<span class="week-item-badge-group"><span class="week-item-badge compact" title="Done"><i class="fa-solid fa-check"></i></span><span class="week-item-badge overdue compact" title="Overdue"><i class="fa-solid fa-triangle-exclamation"></i></span></span>';
                      } else if (item.completed) {
                        badge = '<span class="week-item-badge" title="Done"><i class="fa-solid fa-check"></i>Done</span>';
                      } else if (item.overdue) {
                        badge = '<span class="week-item-badge overdue" title="Overdue"><i class="fa-solid fa-triangle-exclamation"></i>Overdue</span>';
                      }
                    const wrapperStart = item.link
                        ? '<a class="week-item" href="' + item.link + '">'
                        : '<div class="week-item">';
                    const wrapperEnd = item.link ? '</a>' : '</div>';
                    return wrapperStart +
                        '<div class="week-item-main">' +
                        '<i class="' + item.icon + ' week-item-icon"></i>' +
                        '<div>' +
                        '<div class="week-item-title">' + item.title + badge + '</div>' +
                        '<div class="week-item-meta">' + item.time_label + '</div>' +
                        '</div>' +
                        '</div>' +
                        '<div class="week-item-points">' + item.points + ' pts</div>' +
                        wrapperEnd;
                };
                const sectionHtml = sections.map(section => {
                    const sectionItems = items.filter(item => item.time_of_day === section.key);
                    if (!sectionItems.length) return '';
                    return '<div class="week-section">' +
                        '<div class="week-section-title">' + section.label + '</div>' +
                        '<div class="week-section-list">' + sectionItems.map(buildItem).join('') + '</div>' +
                        '</div>';
                }).join('');
                scheduleTarget.innerHTML = sectionHtml || '<div class="week-item"><div class="week-item-main"><i class="fa-solid fa-calendar-day week-item-icon"></i><div><div class="week-item-title">Nothing scheduled</div><div class="week-item-meta">Check back later</div></div></div></div>';
            };

            const setActiveDay = (dateKey) => {
                dayButtons.forEach(btn => btn.classList.toggle('active', btn.getAttribute('data-week-date') === dateKey));
                renderSchedule(dateKey);
            };

            if (dayButtons.length > 0) {
                const defaultDate = todayDate && scheduleData[todayDate] !== undefined ? todayDate : dayButtons[0].getAttribute('data-week-date');
                setActiveDay(defaultDate);
                dayButtons.forEach(btn => {
                    btn.addEventListener('click', () => setActiveDay(btn.getAttribute('data-week-date')));
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
        });
    </script>
</head>
<body class="child-theme">
    <header>
     <h1>Child Dashboard</h1>
     <div class="nav-links">
        <a class="nav-button" href="logout.php">Logout</a>
        <button type="button" class="nav-button" data-help-open>Help</button>
     </div>
    </header>
      <div class="notifications-modal" data-child-notifications-modal>
      <div class="notifications-card">
         <header>
            <h2>Notifications</h2>
            <button type="button" class="notifications-close" aria-label="Close notifications" data-child-notifications-close>&times;</button>
         </header>
         <div class="notification-tabs" data-role="notification-tabs">
            <button type="button" class="tab-button active" data-tab="new">New (<?php echo count($notificationsNew); ?>)</button>
            <button type="button" class="tab-button" data-tab="read">Read (<?php echo count($notificationsRead); ?>)</button>
            <button type="button" class="tab-button" data-tab="deleted">Deleted (<?php echo count($notificationsDeleted); ?>)</button>
         </div>
         <div class="notification-body">
            <?php if (!empty($notificationActionSummary)): ?>
               <div class="notification-action-summary"><?php echo htmlspecialchars($notificationActionSummary); ?></div>
            <?php endif; ?>
            <form method="POST" action="dashboard_child.php" data-tab-panel="new" class="notification-panel active">
               <?php if (!empty($notificationsNew)): ?>
                  <label class="notification-bulk">
                     <input type="checkbox" data-child-bulk-action="mark_notifications_read">
                     Mark all as read
                  </label>
                  <ul class="notification-list">
                     <?php foreach ($notificationsNew as $note): ?>
                        <li class="notification-item">
                           <input type="checkbox" name="notification_ids[]" value="<?php echo (int)$note['id']; ?>" aria-label="Mark notification as read">
                           <div>
                              <div><?php echo $formatChildNotificationMessage($note); ?></div>
                              <div class="notification-meta">
                                 <?php echo htmlspecialchars(date('m/d/Y h:i A', strtotime($note['created_at']))); ?>
                                 <?php if (!empty($note['type'])): ?> | <?php echo htmlspecialchars(str_replace('_', ' ', $note['type'])); ?><?php endif; ?>
                                 <?php
                                    $viewLink = $buildChildNotificationViewLink($note);
                                    if (!empty($viewLink)) {
                                        echo ' | <a href="' . htmlspecialchars($viewLink) . '">View</a>';
                                    }
                                 ?>
                              </div>
                           </div>
                        </li>
                     <?php endforeach; ?>
                  </ul>
                  <div class="notification-actions">
                     <button type="submit" name="mark_notifications_read" class="button">Mark Selected as Read</button>
                  </div>
               <?php else: ?>
                  <p class="notification-meta" style="margin: 12px 0;">No new notifications.</p>
               <?php endif; ?>
            </form>

            <form method="POST" action="dashboard_child.php" data-tab-panel="read" class="notification-panel">
               <?php if (!empty($notificationsRead)): ?>
                  <label class="notification-bulk">
                     <input type="checkbox" data-child-bulk-action="move_notifications_trash">
                     Move all to deleted
                  </label>
                  <ul class="notification-list">
                     <?php foreach ($notificationsRead as $note): ?>
                        <li class="notification-item">
                           <input type="checkbox" name="notification_ids[]" value="<?php echo (int)$note['id']; ?>" aria-label="Move to deleted">
                           <div>
                              <div><?php echo $formatChildNotificationMessage($note); ?></div>
                              <div class="notification-meta">
                                 <?php echo htmlspecialchars(date('m/d/Y h:i A', strtotime($note['created_at']))); ?>
                                 <?php if (!empty($note['type'])): ?> | <?php echo htmlspecialchars(str_replace('_', ' ', $note['type'])); ?><?php endif; ?>
                                 <?php
                                    $viewLink = $buildChildNotificationViewLink($note);
                                    if (!empty($viewLink)) {
                                        echo ' | <a href="' . htmlspecialchars($viewLink) . '">View</a>';
                                    }
                                 ?>
                              </div>
                           </div>
                           <button type="submit" name="trash_single" value="<?php echo (int)$note['id']; ?>" class="trash-button" aria-label="Move to deleted"><i class="fa-solid fa-trash"></i></button>
                        </li>
                     <?php endforeach; ?>
                  </ul>
                  <div class="notification-actions">
                     <button type="submit" name="move_notifications_trash" class="button">Move Selected to Deleted</button>
                  </div>
               <?php else: ?>
                  <p class="notification-meta" style="margin: 12px 0;">No read notifications.</p>
               <?php endif; ?>
            </form>

            <form method="POST" action="dashboard_child.php" data-tab-panel="deleted" class="notification-panel">
               <?php if (!empty($notificationsDeleted)): ?>
                  <label class="notification-bulk">
                     <input type="checkbox" data-child-bulk-action="delete_notifications_perm">
                     Delete all
                  </label>
                  <ul class="notification-list">
                     <?php foreach ($notificationsDeleted as $note): ?>
                        <li class="notification-item">
                           <input type="checkbox" name="notification_ids[]" value="<?php echo (int)$note['id']; ?>" aria-label="Delete permanently">
                           <div>
                              <div><?php echo $formatChildNotificationMessage($note); ?></div>
                              <div class="notification-meta">
                                 Deleted: <?php echo htmlspecialchars(date('m/d/Y h:i A', strtotime($note['deleted_at']))); ?>
                              </div>
                           </div>
                           <button type="submit" name="delete_single_perm" value="<?php echo (int)$note['id']; ?>" class="trash-button" aria-label="Delete permanently"><i class="fa-solid fa-trash"></i></button>
                        </li>
                     <?php endforeach; ?>
                  </ul>
                  <div class="notification-actions">
                     <button type="submit" name="delete_notifications_perm" class="button danger">Delete Selected</button>
                  </div>
               <?php else: ?>
                  <p class="notification-meta" style="margin: 12px 0;">Deleted is empty.</p>
               <?php endif; ?>
            </form>
         </div>
      </div>
   </div><main class="dashboard">
      <?php if (isset($message)) echo "<p>$message</p>"; ?>
      <?php
         $childTotalPoints = isset($data['remaining_points']) ? max(0, (int)$data['remaining_points']) : 0;
         $profileStmt = $db->prepare("SELECT u.first_name, u.name, u.username, cp.avatar FROM users u LEFT JOIN child_profiles cp ON cp.child_user_id = u.id WHERE u.id = :child_id AND u.deleted_at IS NULL LIMIT 1");
         $profileStmt->execute([':child_id' => $_SESSION['user_id']]);
         $profile = $profileStmt->fetch(PDO::FETCH_ASSOC) ?: [];
         $childAvatar = !empty($profile['avatar']) ? $profile['avatar'] : 'images/default-avatar.png';
         $childFirstName = trim((string)($profile['first_name'] ?? ''));
         if ($childFirstName === '') {
            $fallbackName = trim((string)($_SESSION['name'] ?? ($profile['name'] ?? $profile['username'] ?? '')));
            $childFirstName = $fallbackName !== '' ? explode(' ', $fallbackName)[0] : '';
         }
         $todayDate = date('Y-m-d');
         $todayDay = date('D');
         ensureRoutinePointsLogsTable();
         $routineCompletionByDate = [];
         $routineLogStmt = $db->prepare("SELECT routine_id, DATE(created_at) AS date_key, MAX(created_at) AS completed_at
                                         FROM routine_points_logs
                                         WHERE child_user_id = :child_id
                                         GROUP BY routine_id, DATE(created_at)");
         $routineLogStmt->execute([':child_id' => $_SESSION['user_id']]);
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
         $isRoutineScheduledOnDate = static function (array $routine, string $dateKey): bool {
            $recurrence = $routine['recurrence'] ?? '';
            $routineWeekday = !empty($routine['created_at']) ? (int) date('N', strtotime($routine['created_at'])) : null;
            $routineDays = array_values(array_filter(array_map('trim', explode(',', (string) ($routine['recurrence_days'] ?? '')))));
            $routineDateKey = !empty($routine['routine_date']) ? $routine['routine_date'] : (!empty($routine['created_at']) ? date('Y-m-d', strtotime($routine['created_at'])) : null);
            if ($recurrence === 'daily') {
               return true;
            }
            if ($recurrence === 'weekly') {
               if (!empty($routineDays)) {
                  $dayName = date('D', strtotime($dateKey));
                  return in_array($dayName, $routineDays, true);
               }
               if ($routineWeekday) {
                  $dayWeek = (int) date('N', strtotime($dateKey));
                  return $dayWeek === $routineWeekday;
               }
               return false;
            }
            return $routineDateKey !== null && $routineDateKey === $dateKey;
         };
         $isRoutineCompletedOnDate = static function (array $routine, string $dateKey) use ($routineCompletionByDate): bool {
            $rid = (int) ($routine['id'] ?? 0);
            if ($rid > 0 && !empty($routineCompletionByDate[$rid][$dateKey])) {
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
$routineCount = 0;
foreach ($routines as $routineEntry) {
   if ($isRoutineScheduledOnDate($routineEntry, $todayDate) && !$isRoutineCompletedOnDate($routineEntry, $todayDate)) {
      $routineCount++;
   }
}
$taskCount = 0;
$taskCountStmt = $db->prepare("SELECT due_date, end_date, recurrence, recurrence_days, status, completed_at, approved_at FROM tasks WHERE child_user_id = :child_id");
$taskCountStmt->execute([':child_id' => $_SESSION['user_id']]);
foreach ($taskCountStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
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
   $taskCount++;
}
         $goalCount = count($dashboardGoals);
         $rewardCount = isset($data['rewards']) && is_array($data['rewards']) ? count($data['rewards']) : 0;
         $redeemedRewards = isset($data['redeemed_rewards']) && is_array($data['redeemed_rewards']) ? $data['redeemed_rewards'] : [];
         $weekStart = new DateTime('monday this week');
         $weekStart->setTime(0, 0, 0);
         $weekEnd = new DateTime('sunday this week');
         $weekEnd->setTime(23, 59, 59);
         $redeemedThisWeek = array_values(array_filter($redeemedRewards, static function ($reward) use ($weekStart, $weekEnd) {
            if (empty($reward['redeemed_on'])) {
                return false;
            }
            $stamp = strtotime($reward['redeemed_on']);
            if ($stamp === false) {
                return false;
            }
            return $stamp >= $weekStart->getTimestamp() && $stamp <= $weekEnd->getTimestamp();
         }));
         $weekDates = [];
         $scheduleByDay = [];
         $weekCursor = clone $weekStart;
         for ($i = 0; $i < 7; $i++) {
            $dateKey = $weekCursor->format('Y-m-d');
            $weekDates[] = [
               'date' => $dateKey,
               'day' => $weekCursor->format('D'),
               'num' => $weekCursor->format('j')
            ];
            $scheduleByDay[$dateKey] = [];
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
        $taskWeekStmt = $db->prepare("SELECT id, title, points, due_date, end_date, recurrence, recurrence_days, time_of_day, status, completed_at, approved_at FROM tasks WHERE child_user_id = :child_id AND due_date IS NOT NULL AND DATE(due_date) <= :end ORDER BY due_date");
         $taskWeekStmt->execute([
            ':child_id' => $_SESSION['user_id'],
            ':end' => $weekEnd->format('Y-m-d')
         ]);
         $taskRows = $taskWeekStmt->fetchAll(PDO::FETCH_ASSOC);
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
            $repeatDays = array_filter(array_map('trim', explode(',', (string)($row['recurrence_days'] ?? ''))));
            foreach ($weekDates as $day) {
               $dateKey = $day['date'];
               if ($dateKey < $startDateKey) {
                  continue;
               }
               if ($endDateKey && $dateKey > $endDateKey) {
                  continue;
               }
               if ($repeat === 'daily') {
                  // include every day on/after start date
               } elseif ($repeat === 'weekly') {
                  $dayName = date('D', strtotime($dateKey));
                  if (!in_array($dayName, $repeatDays, true)) {
                     continue;
                  }
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
               $scheduleByDay[$dateKey][] = [
                  'id' => (int) ($row['id'] ?? 0),
                  'title' => $row['title'],
                  'type' => 'Task',
                  'points' => (int)($row['points'] ?? 0),
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
            foreach ($routines as $routine) {
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
               foreach ($weekDates as $day) {
                  $dateKey = $day['date'];
                  if ($recurrence === 'daily') {
                     // include every day
                  } elseif ($recurrence === 'weekly') {
                  if (!empty($routineDays)) {
                     $dayName = date('D', strtotime($dateKey));
                     if (!in_array($dayName, $routineDays, true)) {
                        continue;
                     }
                  } elseif ($routineWeekday) {
                     $dayWeek = (int) date('N', strtotime($dateKey));
                     if ($dayWeek !== $routineWeekday) {
                        continue;
                     }
                  }
                  } else {
                     if (!$routineDateKey || $dateKey !== $routineDateKey) {
                        continue;
                     }
                  }
                  $routineId = (int) ($routine['id'] ?? 0);
                  $completedStamp = $routineCompletionByDate[$routineId][$dateKey] ?? null;
                  $completedFlag = $completedStamp ? true : $isRoutineCompletedOnDate($routine, $dateKey);
                  $overdueFlag = false;
                  $dueStamp = $getScheduleDueStamp($dateKey, $timeOfDay, $startTimeValue);
                  if ($completedFlag) {
                     if ($completedStamp && $dueStamp !== null && strtotime($completedStamp) > $dueStamp) {
                        $overdueFlag = true;
                     }
                  } else if ($dueStamp !== null && $dueStamp < $nowTs && $dateKey <= $todayKey) {
                     $overdueFlag = true;
                  }
                    $scheduleByDay[$dateKey][] = [
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
         $taskHistoryStmt->execute([':child_id' => $_SESSION['user_id']]);
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
            $routineHistoryStmt->execute([':child_id' => $_SESSION['user_id']]);
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
            $adjStmt = $db->prepare("SELECT delta_points, reason, created_at FROM child_point_adjustments WHERE child_user_id = :child_id");
            $adjStmt->execute([':child_id' => $_SESSION['user_id']]);
            foreach ($adjStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
               $historyItems[] = [
                  'type' => 'Adjustment',
                  'title' => $row['reason'],
                  'points' => (int)$row['delta_points'],
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
            $rewardStmt->execute([':child_id' => $_SESSION['user_id']]);
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
      <script>
         window.weekScheduleData = <?php echo json_encode($scheduleByDay, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
         window.weekScheduleToday = "<?php echo htmlspecialchars($todayDate, ENT_QUOTES); ?>";
      </script>
      <div class="points-summary">
         <div class="points-left">
            <div class="child-identity">
               <div class="child-avatar-wrap">
                  <img class="child-avatar" src="<?php echo htmlspecialchars($childAvatar); ?>" alt="<?php echo htmlspecialchars($childFirstName !== '' ? $childFirstName : 'Child'); ?>">
                  <button type="button" class="notification-trigger avatar-notification" data-child-notify-trigger aria-label="Notifications"><i class="fa-solid fa-bell"></i><?php if ($notificationCount > 0): ?><span class="notification-badge"><?php echo (int)$notificationCount; ?></span><?php endif; ?></button>
               </div>
               <div class="child-first-name"><?php echo htmlspecialchars($childFirstName); ?></div>
            </div>
            <div class="points-total">
               <span class="points-total-label">Total Points</span>
               <span class="points-total-value"><?php echo $childTotalPoints; ?></span>
               <button type="button" class="points-history-button" data-points-history-open aria-haspopup="dialog" aria-controls="points-history-modal">
                  <i class="fa-solid fa-clock-rotate-left"></i>History
               </button>
            </div>
         </div>
          <div class="goal-summary">
             <div class="goal-summary-header">
                <h3 class="goal-summary-title">Goals</h3>
                <a class="nav-button" href="goal.php">View</a>
             </div>
             <?php if (empty($dashboardGoals)): ?>
                <div class="goal-item">
                   <div class="goal-item-title">No active goals</div>
                   <div class="goal-item-meta">Check back when a new goal is assigned.</div>
                </div>
             <?php else: ?>
                <?php foreach ($dashboardGoals as $goal): ?>
                   <?php
                      $progress = $goal['progress'] ?? ['current' => 0, 'target' => 1, 'percent' => 0, 'goal_type' => 'manual'];
                      $typeLabel = [
                          'manual' => 'Manual',
                          'routine_streak' => 'Routine streak',
                          'routine_count' => 'Routine count',
                          'task_quota' => 'Task quota'
                      ][$progress['goal_type']] ?? 'Goal';
                   ?>
                   <div class="goal-item">
                      <div class="goal-item-title"><?php echo htmlspecialchars($goal['title']); ?></div>
                      <?php if (!empty($goal['description'])): ?>
                         <div class="goal-item-desc"><?php echo nl2br(htmlspecialchars($goal['description'])); ?></div>
                      <?php endif; ?>
                      <div class="goal-item-meta"><?php echo htmlspecialchars($typeLabel); ?> • <?php echo (int) $progress['current']; ?> / <?php echo (int) $progress['target']; ?></div>
                      <div class="goal-progress-bar">
                         <span style="width: <?php echo (int) $progress['percent']; ?>%;"></span>
                      </div>
                      <?php if (!empty($progress['next_needed'])): ?>
                         <div class="goal-next-needed">Next: <?php echo htmlspecialchars($progress['next_needed']); ?></div>
                      <?php endif; ?>
                      <?php if (($goal['status'] ?? '') === 'pending_approval'): ?>
                         <span class="goal-pending-pill">Waiting for approval</span>
                      <?php endif; ?>
                   </div>
                <?php endforeach; ?>
             <?php endif; ?>
          </div>
          <div class="week-calendar">
            <div class="week-days" aria-label="Current week">
               <?php foreach ($weekDates as $day): ?>
                  <button type="button" class="week-day<?php echo $day['date'] === $todayDate ? ' active' : ''; ?>" data-week-date="<?php echo htmlspecialchars($day['date']); ?>">
                     <span class="week-day-name-full"><?php echo htmlspecialchars($day['day']); ?></span>
                     <span class="week-day-name-initial"><?php echo htmlspecialchars(strtoupper(substr((string) $day['day'], 0, 1))); ?></span>
                     <span class="week-day-num"><?php echo htmlspecialchars($day['num']); ?></span>
                  </button>
               <?php endforeach; ?>
            </div>
            <div class="week-schedule" data-week-schedule></div>
         </div>
      </div>
      <div class="dashboard-cards" aria-label="Quick links">
         <a class="dashboard-card" href="routine.php">
            <i class="fa-solid fa-repeat"></i>Routines
            <?php if ($routineCount > 0): ?><span class="dashboard-card-count"><?php echo $routineCount; ?></span><?php endif; ?>
         </a>
         <a class="dashboard-card" href="task.php">
            <i class="fa-solid fa-list-check"></i>Tasks
            <?php if ($taskCount > 0): ?><span class="dashboard-card-count"><?php echo $taskCount; ?></span><?php endif; ?>
         </a>
         <a class="dashboard-card" href="goal.php">
            <i class="fa-solid fa-bullseye"></i>Goals
            <?php if ($goalCount > 0): ?><span class="dashboard-card-count"><?php echo $goalCount; ?></span><?php endif; ?>
         </a>
         <button type="button" class="dashboard-card" data-rewards-open aria-haspopup="dialog" aria-controls="rewards-modal">
            <i class="fa-solid fa-gift"></i>Rewards
            <?php if ($rewardCount > 0): ?><span class="dashboard-card-count"><?php echo $rewardCount; ?></span><?php endif; ?>
         </button>
      </div>
      <div class="rewards-modal" data-rewards-modal id="rewards-modal">
         <div class="rewards-card" role="dialog" aria-modal="true" aria-labelledby="rewards-title">
            <header>
               <h2 id="rewards-title">Rewards</h2>
               <button type="button" class="rewards-close" aria-label="Close rewards" data-rewards-close>&times;</button>
            </header>
            <div class="rewards-tabs">
               <button type="button" class="rewards-tab active" data-rewards-tab="available">Available (<?php echo $rewardCount; ?>)</button>
               <button type="button" class="rewards-tab" data-rewards-tab="redeemed">This Week (<?php echo count($redeemedThisWeek); ?>)</button>
            </div>
            <div class="rewards-body">
               <div class="rewards-panel active" data-rewards-panel="available">
                  <?php if (!empty($data['rewards'])): ?>
                     <ul class="reward-list">
                        <?php foreach ($data['rewards'] as $reward): ?>
                           <li class="reward-list-item" id="reward-<?php echo (int) $reward['id']; ?>">
                              <div class="reward-title"><?php echo htmlspecialchars($reward['title']); ?> (<?php echo htmlspecialchars($reward['point_cost']); ?> points)</div>
                              <div><?php echo htmlspecialchars($reward['description']); ?></div>
                              <div class="reward-actions">
                                 <form method="POST" action="dashboard_child.php">
                                    <input type="hidden" name="reward_id" value="<?php echo $reward['id']; ?>">
                                    <button type="submit" name="redeem_reward" class="button redeem-button">Redeem</button>
                                 </form>
                              </div>
                           </li>
                        <?php endforeach; ?>
                     </ul>
                  <?php else: ?>
                     <p>No rewards available.</p>
                  <?php endif; ?>
               </div>
               <div class="rewards-panel" data-rewards-panel="redeemed">
                  <?php if (!empty($redeemedThisWeek)): ?>
                     <ul class="reward-list">
                        <?php foreach ($redeemedThisWeek as $reward): ?>
                           <li class="reward-list-item" id="redeemed-reward-<?php echo (int) $reward['id']; ?>">
                              <div class="reward-title"><?php echo htmlspecialchars($reward['title']); ?> (<?php echo htmlspecialchars($reward['point_cost']); ?> points)</div>
                              <div><?php echo htmlspecialchars($reward['description']); ?></div>
                              <div>Redeemed on: <?php echo !empty($reward['redeemed_on']) ? htmlspecialchars(date('m/d/Y h:i A', strtotime($reward['redeemed_on']))) : 'Date unavailable'; ?></div>
                           </li>
                        <?php endforeach; ?>
                     </ul>
                  <?php else: ?>
                     <p>No rewards redeemed this week.</p>
                  <?php endif; ?>
               </div>
            </div>
         </div>
      </div>
      <div class="child-history-modal" data-points-history-modal id="points-history-modal">
         <div class="child-history-card" role="dialog" aria-modal="true" aria-labelledby="points-history-title">
            <header class="child-history-header">
               <button type="button" class="child-history-back" aria-label="Close points history" data-points-history-close>
                  <i class="fa-solid fa-arrow-left"></i>
               </button>
               <h2 id="points-history-title" class="points-history-title">Points History</h2>
               <button type="button" class="child-history-close" aria-label="Close points history" data-points-history-close>&times;</button>
            </header>
            <div class="child-history-body">
               <div class="child-history-hero">
                  <img class="child-history-avatar" src="<?php echo htmlspecialchars($childAvatar); ?>" alt="<?php echo htmlspecialchars($childFirstName !== '' ? $childFirstName : 'Child'); ?>">
                  <div class="child-history-info">
                     <div class="child-history-name"><?php echo htmlspecialchars($childFirstName !== '' ? $childFirstName : 'Child'); ?></div>
                     <div class="child-history-points"><i class="fa-solid fa-star"></i> <?php echo (int)$childTotalPoints; ?> pts</div>
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
                     <p class="child-history-empty">No points history yet.</p>
                  <?php endif; ?>
               </div>
            </div>
         </div>
      </div>
      <div class="help-modal" data-help-modal>
         <div class="help-card" role="dialog" aria-modal="true" aria-labelledby="help-title">
            <header>
               <h2 id="help-title">Task Help</h2>
               <button type="button" class="help-close" data-help-close aria-label="Close help">&times;</button>
            </header>
            <div class="help-body">
               <section class="help-section">
                  <h3>Child view</h3>
                  <ul>
                     <li>Tap a task in the calendar or list view to open Task Details.</li>
                     <li>Start timers from Task Details; a floating timer appears if you close the modal.</li>
                     <li>Finish tasks in Task Details. Photo proof is required when toggled on.</li>
                     <li>Completed tasks wait for parent approval before points are awarded.</li>
                  </ul>
               </section>
            </div>
         </div>
      </div>
   </main>
   <?php if (!empty($goalCelebrations)): ?>
      <?php foreach ($goalCelebrations as $goalCelebration) {
         markGoalCelebrationShown((int) $goalCelebration['id']);
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
         const celebrationQueue = <?php echo json_encode($goalCelebrations, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
      </script>
   <?php endif; ?>
   <footer>
   <p>Child Task and Chore App - Ver 3.17.6</p>
</footer>
  <script src="js/number-stepper.js" defer></script>
</body>
</html>



















