<?php
// dashboard_child.php - Child dashboard
// Purpose: Display child dashboard with progress and task/reward links
// Inputs: Session data
// Outputs: Dashboard interface
// Version: 3.15.0 (Notifications moved to header-triggered modal, Font Awesome icons)

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
            $message = "Notifications moved to trash.";
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
            $data = getDashboardData($_SESSION['user_id']);
        }
    } elseif (isset($_POST['redeem_reward'])) {
        $reward_id = filter_input(INPUT_POST, 'reward_id', FILTER_VALIDATE_INT);
        if (redeemReward($_SESSION['user_id'], $reward_id)) {
            $message = "Reward redeemed successfully! Refresh to see updates.";
            $data = getDashboardData($_SESSION['user_id']); // Refresh data
        } else {
            $message = "Not enough points to redeem this reward.";
        }
    }
}
$notificationsNew = $data['notifications_new'] ?? [];
$notificationsRead = $data['notifications_read'] ?? [];
$notificationsDeleted = $data['notifications_deleted'] ?? [];
$notificationCount = is_array($notificationsNew) ? count($notificationsNew) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Child Dashboard</title>
   <link rel="stylesheet" href="css/main.css?v=3.15.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css" integrity="Evv84Mr4kqVGRNSgIGL/F/aIDqQb7xQ2vcrdIwxfjThSH8CSR7PBEakCr51Ck+w+/U6swU2Im1vVX0SVk9ABhg==" crossorigin="anonymous" referrerpolicy="no-referrer">
    <style>
        .dashboard { padding: 20px; max-width: 720px; margin: 0 auto; text-align: center; }
        .points-summary { margin: 20px 0; display: flex; align-items: flex-start; gap: 25px; text-align: left; }
        .child-identity { display: flex; flex-direction: column; align-items: center; gap: 6px; min-width: 120px; }
        .child-avatar-wrap { position: relative; display: inline-block; }
        .child-edit-wrapper { display: flex; justify-content: center; }
        .child-edit-button { background: transparent; border: none; color: #5d4037; cursor: pointer; font-size: 1rem; padding: 4px; text-decoration: none; }
        .child-edit-button:hover { color: #0d47a1; }
        .child-avatar { width: 72px; height: 72px; border-radius: 50%; object-fit: cover; border: 3px solid #ffd28a; background: #fff; box-shadow: 0 4px 10px rgba(0,0,0,0.12); }
        .child-first-name { font-size: 1rem; font-weight: 700; color: #263238; }
        .points-total { margin: 0; font-weight: 700; color: #263238; display: flex; flex-direction: column; gap: 6px; text-align: center; }
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
        .week-day.active { background: #ffe0b2; border-color: #ffd28a; color: #ef6c00; }
        .week-day-name { font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.04em; }
        .week-day-num { font-size: 1rem; }
        .week-schedule { margin-top: 10px; display: grid; gap: 8px; }
        .week-section { display: grid; gap: 6px; }
        .week-section-title { font-weight: 700; color: #37474f; font-size: 0.95rem; }
        .week-section-list { display: grid; gap: 8px; }
        .week-item { display: flex; align-items: center; justify-content: space-between; gap: 10px; background: #fff7e6; border: 1px solid #ffd28a; border-radius: 10px; padding: 8px 10px; }
        .week-item-main { display: flex; align-items: center; gap: 8px; }
        .week-item-icon { color: #ef6c00; }
        .week-item-title { font-weight: 700; color: #3e2723; }
        .week-item-meta { color: #6d4c41; font-size: 0.9rem; }
        .week-item-points { font-weight: 700; color: #00bb01; white-space: nowrap; }
        .button { padding: 10px 20px; margin: 5px; background-color: #ff9800; color: white; border: none; border-radius: 5px; cursor: pointer; text-decoration: none; display: inline-block; font-size: 16px; min-height: 44px; }
        .redeem-button { background-color: #2196f3; }
        
        .trash-button { border: none; background: transparent; cursor: pointer; font-size: 1.1rem; padding: 4px; color: #b71c1c; }
        @media (max-width: 768px) { .dashboard { padding: 10px; } .button { width: 100%; } }
        @media (max-width: 600px) {
            .points-summary { flex-direction: column; align-items: center; text-align: center; }
        }
        /* Notifications Modal */
        .notification-trigger { position: relative; display: inline-flex; align-items: center; justify-content: center; width: 34px; height: 34px; background: #fff; border: 2px solid #ffd28a; border-radius: 50%; box-shadow: 0 2px 4px rgba(0,0,0,0.12); cursor: pointer; }
        .notification-trigger i { font-size: 18px; color: #ef6c00; }
        .notification-badge { position: absolute; top: -6px; right: -8px; background: #d32f2f; color: #fff; border-radius: 12px; padding: 2px 6px; font-size: 0.75rem; font-weight: 700; min-width: 22px; text-align: center; }
        .avatar-notification { position: absolute; top: 0; right: 0; transform: translate(35%, -35%); }
        .week-item-badge { display: inline-flex; align-items: center; gap: 4px; margin-left: 8px; padding: 2px 8px; border-radius: 999px; font-size: 0.7rem; font-weight: 700; background: #2e7d32; color: #fff; text-transform: uppercase; }
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
        .notification-item { padding: 10px; background: #fff; border: 1px solid #e0e0e0; border-radius: 8px; display: grid; grid-template-columns: auto 1fr auto; gap: 10px; align-items: center; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .notification-item input[type="checkbox"] { width: 19.8px; height: 19.8px; }
        .notification-actions { display: flex; gap: 8px; justify-content: flex-end; margin-top: 8px; }

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
        .reward-list-item .reward-title { font-weight: 700; }
        .reward-list-item .reward-actions { display: flex; justify-content: flex-end; }
        .points-history-modal { position: fixed; inset: 0; background: rgba(0,0,0,0.45); display: none; align-items: center; justify-content: center; z-index: 4200; padding: 14px; }
        .points-history-modal.open { display: flex; }
        .points-history-card { background: #fff; border-radius: 12px; max-width: 620px; width: min(620px, 100%); max-height: 82vh; overflow: hidden; box-shadow: 0 12px 32px rgba(0,0,0,0.25); display: grid; grid-template-rows: auto 1fr; }
        .points-history-card header { display: flex; align-items: center; justify-content: space-between; padding: 12px 16px; border-bottom: 1px solid #e0e0e0; }
        .points-history-card h2 { margin: 0; font-size: 1.1rem; }
        .points-history-close { background: transparent; border: none; font-size: 1.3rem; cursor: pointer; color: #555; }
        .points-history-body { padding: 12px 16px 16px; overflow-y: auto; text-align: left; }
        .history-day { margin-top: 12px; }
        .history-day-title { font-weight: 700; color: #5d4037; margin-bottom: 6px; }
        .history-list { list-style: none; padding: 0; margin: 0; display: grid; gap: 8px; }
        .history-item { background: #fff7e6; border: 1px solid #ffd28a; border-radius: 10px; padding: 10px 12px; display: flex; align-items: center; justify-content: space-between; gap: 10px; }
        .history-item-title { font-weight: 700; color: #3e2723; }
        .history-item-meta { color: #6d4c41; font-size: 0.95rem; }
        .history-item-points { font-weight: 700; color: #00bb01; white-space: nowrap; }
        .history-item-points.is-negative { color: #d32f2f; }
    </style>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const childNotifyTrigger = document.querySelector('[data-child-notify-trigger]');
            const childModal = document.querySelector('[data-child-notifications-modal]');
            const childClose = childModal ? childModal.querySelector('[data-child-notifications-close]') : null;
            const childTabButtons = childModal ? childModal.querySelectorAll('.tab-button') : [];
            const childPanels = childModal ? childModal.querySelectorAll('.notification-panel') : [];
            const setChildTab = (target) => {
                childTabButtons.forEach(btn => btn.classList.toggle('active', btn.getAttribute('data-tab') === target));
                childPanels.forEach(panel => panel.classList.toggle('active', panel.getAttribute('data-tab-panel') === target));
            };
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

            const historyOpen = document.querySelector('[data-points-history-open]');
            const historyModal = document.querySelector('[data-points-history-modal]');
            const historyClose = historyModal ? historyModal.querySelector('[data-points-history-close]') : null;
            const openHistoryModal = () => {
                if (!historyModal) return;
                historyModal.classList.add('open');
                document.body.classList.add('no-scroll');
            };
            const closeHistoryModal = () => {
                if (!historyModal) return;
                historyModal.classList.remove('open');
                document.body.classList.remove('no-scroll');
            };
            if (historyOpen && historyModal) {
                historyOpen.addEventListener('click', openHistoryModal);
                if (historyClose) historyClose.addEventListener('click', closeHistoryModal);
                historyModal.addEventListener('click', (e) => { if (e.target === historyModal) closeHistoryModal(); });
                document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeHistoryModal(); });
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
                    const badge = item.completed
                        ? '<span class="week-item-badge"><i class="fa-solid fa-check"></i>Done</span>'
                        : '';
                    return '<div class="week-item">' +
                        '<div class="week-item-main">' +
                        '<i class="' + item.icon + ' week-item-icon"></i>' +
                        '<div>' +
                        '<div class="week-item-title">' + item.title + badge + '</div>' +
                        '<div class="week-item-meta">' + item.time_label + '</div>' +
                        '</div>' +
                        '</div>' +
                        '<div class="week-item-points">' + item.points + ' pts</div>' +
                        '</div>';
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
        });
    </script>
</head>
<body class="child-theme">
    <header>
     <h1>Child Dashboard</h1>
     <div class="nav-links">
        <a class="nav-button" href="logout.php">Logout</a>
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
            <form method="POST" action="dashboard_child.php" data-tab-panel="new" class="notification-panel active">
               <?php if (!empty($notificationsNew)): ?>
                  <ul class="notification-list">
                     <?php foreach ($notificationsNew as $note): ?>
                        <li class="notification-item">
                           <input type="checkbox" name="notification_ids[]" value="<?php echo (int)$note['id']; ?>" aria-label="Mark notification as read">
                           <div>
                              <div><?php echo htmlspecialchars($note['message']); ?></div>
                              <div class="notification-meta">
                                 <?php echo htmlspecialchars(date('m/d/Y h:i A', strtotime($note['created_at']))); ?>
                                 <?php if (!empty($note['type'])): ?> | <?php echo htmlspecialchars(str_replace('_', ' ', $note['type'])); ?><?php endif; ?>
                                 <?php if (!empty($note['link_url'])): ?> | <a href="<?php echo htmlspecialchars($note['link_url']); ?>">View</a><?php endif; ?>
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
                  <ul class="notification-list">
                     <?php foreach ($notificationsRead as $note): ?>
                        <li class="notification-item">
                           <input type="checkbox" name="notification_ids[]" value="<?php echo (int)$note['id']; ?>" aria-label="Move to trash">
                           <div>
                              <div><?php echo htmlspecialchars($note['message']); ?></div>
                              <div class="notification-meta">
                                 <?php echo htmlspecialchars(date('m/d/Y h:i A', strtotime($note['created_at']))); ?>
                                 <?php if (!empty($note['type'])): ?> | <?php echo htmlspecialchars(str_replace('_', ' ', $note['type'])); ?><?php endif; ?>
                                 <?php if (!empty($note['link_url'])): ?> | <a href="<?php echo htmlspecialchars($note['link_url']); ?>">View</a><?php endif; ?>
                              </div>
                           </div>
                           <button type="submit" name="trash_single" value="<?php echo (int)$note['id']; ?>" class="trash-button" aria-label="Move to trash"><i class="fa-solid fa-trash"></i></button>
                        </li>
                     <?php endforeach; ?>
                  </ul>
                  <div class="notification-actions">
                     <button type="submit" name="move_notifications_trash" class="button">Move Selected to Trash</button>
                  </div>
               <?php else: ?>
                  <p class="notification-meta" style="margin: 12px 0;">No read notifications.</p>
               <?php endif; ?>
            </form>

            <form method="POST" action="dashboard_child.php" data-tab-panel="deleted" class="notification-panel">
               <?php if (!empty($notificationsDeleted)): ?>
                  <ul class="notification-list">
                     <?php foreach ($notificationsDeleted as $note): ?>
                        <li class="notification-item">
                           <input type="checkbox" name="notification_ids[]" value="<?php echo (int)$note['id']; ?>" aria-label="Delete permanently">
                           <div>
                              <div><?php echo htmlspecialchars($note['message']); ?></div>
                              <div class="notification-meta">
                                 Deleted: <?php echo htmlspecialchars(date('m/d/Y h:i A', strtotime($note['deleted_at']))); ?>
                              </div>
                           </div>
                           <button type="submit" name="delete_single_perm" value="<?php echo (int)$note['id']; ?>" class="trash-button" aria-label="Delete permanently"><i class="fa-solid fa-trash"></i></button>
                        </li>
                     <?php endforeach; ?>
                  </ul>
                  <div class="notification-actions">
                     <button type="submit" name="delete_notifications_perm" class="button">Delete Selected</button>
                  </div>
               <?php else: ?>
                  <p class="notification-meta" style="margin: 12px 0;">Trash is empty.</p>
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
         $isRoutineCompletedOnDate = static function (array $routine, string $dateKey): bool {
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
         $taskCountStmt = $db->prepare("SELECT due_date, end_date, recurrence, recurrence_days, status, completed_at FROM tasks WHERE child_user_id = :child_id");
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
            $completedToday = false;
            if (!empty($completedAt)) {
               $completedDate = date('Y-m-d', strtotime($completedAt));
               $completedToday = $completedDate === $todayDate && in_array($status, ['completed', 'approved'], true);
            }
            if ($completedToday) {
               continue;
            }
            $taskCount++;
         }
         $goalCount = isset($data['active_goals']) && is_array($data['active_goals']) ? count($data['active_goals']) : 0;
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
         $taskWeekStmt = $db->prepare("SELECT title, points, due_date, end_date, recurrence, recurrence_days, time_of_day FROM tasks WHERE child_user_id = :child_id AND due_date IS NOT NULL AND DATE(due_date) <= :end ORDER BY due_date");
         $taskWeekStmt->execute([
            ':child_id' => $_SESSION['user_id'],
            ':end' => $weekEnd->format('Y-m-d')
         ]);
         foreach ($taskWeekStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $timeOfDay = $row['time_of_day'] ?? 'anytime';
            $dueDate = $row['due_date'];
            $startDateKey = date('Y-m-d', strtotime($dueDate));
            $endDateKey = !empty($row['end_date']) ? $row['end_date'] : null;
            $timeSort = date('H:i', strtotime($dueDate));
            $timeLabel = date('g:i A', strtotime($dueDate));
            if ($timeOfDay === 'anytime') {
               $timeSort = '99:99';
               $timeLabel = 'Anytime';
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
               $scheduleByDay[$dateKey][] = [
                  'title' => $row['title'],
                  'type' => 'Task',
                  'points' => (int)($row['points'] ?? 0),
                  'time' => $timeSort,
                  'time_label' => $timeLabel,
                  'time_of_day' => $timeOfDay,
                  'icon' => 'fa-solid fa-list-check'
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
            $timeSort = !empty($routine['start_time']) ? date('H:i', strtotime($routine['start_time'])) : '99:99';
            $timeLabel = !empty($routine['start_time']) ? date('g:i A', strtotime($routine['start_time'])) : 'Anytime';
            if ($timeOfDay === 'anytime') {
               $timeSort = '99:99';
               $timeLabel = 'Anytime';
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
                  $completedFlag = $isRoutineCompletedOnDate($routine, $dateKey);
                  $scheduleByDay[$dateKey][] = [
                     'title' => $routine['title'],
                     'type' => 'Routine',
                     'points' => $totalPoints,
                     'time' => $timeSort,
                     'time_label' => $timeLabel,
                     'time_of_day' => $timeOfDay,
                     'icon' => 'fa-solid fa-repeat',
                     'completed' => $completedFlag
                  ];
               }
            }
         $historyItems = [];
         $taskHistoryStmt = $db->prepare("SELECT title, points, approved_at, completed_at FROM tasks WHERE child_user_id = :child_id AND status = 'approved'");
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
         <div class="child-identity">
            <div class="child-avatar-wrap">
               <img class="child-avatar" src="<?php echo htmlspecialchars($childAvatar); ?>" alt="<?php echo htmlspecialchars($childFirstName !== '' ? $childFirstName : 'Child'); ?>">
               <button type="button" class="notification-trigger avatar-notification" data-child-notify-trigger aria-label="Notifications"><i class="fa-solid fa-bell"></i><?php if ($notificationCount > 0): ?><span class="notification-badge"><?php echo (int)$notificationCount; ?></span><?php endif; ?></button>
            </div>
            <div class="child-first-name"><?php echo htmlspecialchars($childFirstName); ?></div>
            <div class="child-edit-wrapper">
               <a class="child-edit-button" href="profile.php?self=1" aria-label="Edit profile">
                  <i class="fa-solid fa-pen"></i>
               </a>
            </div>
         </div>
         <div class="points-total">
            <span class="points-total-label">Total Points</span>
            <span class="points-total-value"><?php echo $childTotalPoints; ?></span>
            <button type="button" class="points-history-button" data-points-history-open aria-haspopup="dialog" aria-controls="points-history-modal">
               <i class="fa-solid fa-clock-rotate-left"></i>History
            </button>
         </div>
         <div class="week-calendar">
            <div class="week-days" aria-label="Current week">
               <?php foreach ($weekDates as $day): ?>
                  <button type="button" class="week-day<?php echo $day['date'] === $todayDate ? ' active' : ''; ?>" data-week-date="<?php echo htmlspecialchars($day['date']); ?>">
                     <span class="week-day-name"><?php echo htmlspecialchars($day['day']); ?></span>
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
                           <li class="reward-list-item">
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
                           <li class="reward-list-item">
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
      <div class="points-history-modal" data-points-history-modal id="points-history-modal">
         <div class="points-history-card" role="dialog" aria-modal="true" aria-labelledby="points-history-title">
            <header>
               <h2 id="points-history-title">Points History</h2>
               <button type="button" class="points-history-close" aria-label="Close points history" data-points-history-close>&times;</button>
            </header>
            <div class="points-history-body">
               <?php if (!empty($historyByDay)): ?>
                  <?php foreach ($historyByDay as $day => $items): ?>
                     <div class="history-day">
                        <div class="history-day-title"><?php echo htmlspecialchars(date('M j', strtotime($day))); ?></div>
                        <ul class="history-list">
                           <?php foreach ($items as $item): ?>
                              <li class="history-item">
                                 <div>
                                    <div class="history-item-title"><?php echo htmlspecialchars($item['type']); ?>: <?php echo htmlspecialchars($item['title']); ?></div>
                                    <div class="history-item-meta"><?php echo htmlspecialchars(date('g:i A', strtotime($item['date']))); ?></div>
                                 </div>
                                 <div class="history-item-points<?php echo ($item['points'] < 0 ? ' is-negative' : ''); ?>"><?php echo ($item['points'] >= 0 ? '+' : '') . (int)$item['points']; ?> pts</div>
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
   </main>
   <footer>
   <p>Child Task and Chore App - Ver 3.15.0</p>
</footer>
  <script src="js/number-stepper.js" defer></script>
</body>
</html>

















