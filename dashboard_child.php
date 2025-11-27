<?php
// dashboard_child.php - Child dashboard
// Purpose: Display child dashboard with progress and task/reward links
// Inputs: Session data
// Outputs: Dashboard interface
// Version: 3.4.8 (Added Routines section with start/complete buttons)

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
            $stmt = $db->prepare("UPDATE child_notifications SET is_read = 1 WHERE id IN ($placeholders) AND child_user_id = ?");
            $stmt->execute($params);
            $message = "Notifications updated.";
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Child Dashboard</title>
   <link rel="stylesheet" href="css/main.css?v=3.10.15">
    <style>
        .dashboard { padding: 20px; max-width: 720px; margin: 0 auto; text-align: center; }
        .progress { margin: 20px 0; display: grid; gap: 12px; text-align: left; color: #263238; }
        .points-progress-title { font-size: 1.05rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: #ff6f61; }
        .points-progress-wrap { display: grid; grid-template-columns: 1fr auto; gap: 16px; align-items: center; }
        .points-progress-track { position: relative; height: 34px; background: rgba(30,136,229,0.08); border-radius: 20px; overflow: hidden; box-shadow: inset 0 1px 3px rgba(0,0,0,0.08); border: 2px solid #d8d8d8; }
        .points-progress-fill { position: absolute; inset: 0; background-image: linear-gradient(90deg, #f7d564 0%, #efb710 100%), repeating-linear-gradient(45deg, rgba(255,255,255,0.35) 0, rgba(255,255,255,0.35) 14px, rgba(239,183,16,0.15) 14px, rgba(239,183,16,0.15) 28px); background-size: 100% 100%, 180px 100%; transform: scaleX(0); transform-origin: left center; transition: transform 1800ms ease; animation: progressStripes 2.8s linear infinite; }
        .points-progress-value { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; font-weight: 700; color: #f9f9f9; text-shadow: 0 2px 6px rgba(0,0,0,0.45); pointer-events: none; letter-spacing: 0.04em; }
        .points-progress-total { font-weight: 700; font-size: 1.2rem; color: #00bb01; min-width: 88px; text-align: right; }
        .points-extra { margin: 0; font-size: 1rem; font-weight: 600; color: #333; }
        .extra-points-num { color: #00bb01; }
        @keyframes progressStripes {
            0% { background-position: 0 0, 0 0; }
            100% { background-position: 0 0, 360px 0; }
        }
        .rewards, .redeemed-rewards, .active-goals, .completed-goals, .rejected-goals, .routines { margin: 20px 0; }
        .reward-item, .redeemed-item, .goal-item, .routine-item { background-color: #f5f5f5; padding: 10px; margin: 5px 0; border-radius: 5px; }
        .button { padding: 10px 20px; margin: 5px; background-color: #ff9800; color: white; border: none; border-radius: 5px; cursor: pointer; text-decoration: none; display: inline-block; font-size: 16px; min-height: 44px; }
        .redeem-button { background-color: #2196f3; }
        .request-button { background-color: #9c27b0; }
        .start-routine-button { background-color: #4caf50; }
        .fulfilled-label { font-weight: 600; color: #2e7d32; }
        .awaiting-label { font-style: italic; color: #bf360c; }
        /* Kid-Friendly Styles - Autism-Friendly: Bright pastels, large buttons */
        .routine-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 16px; }
        .routine-item { background: linear-gradient(135deg, #e3f2fd, #f3e5f5); border: 2px solid #bbdefb; margin: 0; display: flex; flex-direction: column; height: 100%; }
        .routine-item h3 { color: #1976d2; font-size: 1.5em; }
        .routine-item p { font-size: 1.1em; }
        .routine-item .routine-points-line { display: flex; flex-wrap: wrap;    justify-content: center; gap: 12px; font-weight: 600; color: #37474f; margin: 6px 0; }
        .routine-item .start-routine-button { align-self: center; margin-top: auto; }
        .notifications { margin: 20px 0; text-align: left; background: #fffaf3; border: 1px solid #ffe0b2; border-radius: 10px; padding: 12px; box-shadow: 0 2px 6px rgba(0,0,0,0.06); position: relative; }
        .notifications-header { display: flex; align-items: center; gap: 10px; cursor: pointer; }
        .notifications h2 { margin: 0; color: #ef6c00; display: flex; align-items: center; gap: 8px; }
        .notification-icon { width: 32px; height: 32px; position: relative; display: inline-flex; align-items: center; justify-content: center; background: #fff; border-radius: 50%; border: 2px solid #ffb74d; box-shadow: 0 2px 4px rgba(0,0,0,0.15); }
        .notification-icon svg { width: 18px; height: 18px; fill: #ef6c00; }
        .notification-badge { position: absolute; top: -6px; right: -6px; background: #d32f2f; color: #fff; border-radius: 12px; padding: 2px 6px; font-size: 0.75rem; font-weight: 700; min-width: 22px; text-align: center; }
        .notification-list { list-style: none; padding: 0; margin: 12px 0; display: none; gap: 10px; }
        .notifications.open .notification-list { display: grid; }
        .notification-item { display: grid; grid-template-columns: auto 1fr; gap: 10px; align-items: flex-start; padding: 10px; border-radius: 8px; background: #fff; border: 1px solid #ffe0b2; }
        .notification-item.unread { border-color: #ff9800; box-shadow: 0 1px 4px rgba(255,152,0,0.2); }
        .notification-meta { font-size: 0.85rem; color: #666; margin-top: 4px; }
        .notification-actions { display: none; justify-content: flex-end; margin-top: 10px; }
        .notifications.open .notification-actions { display: flex; }
        .notification-actions .button { margin: 0; }
        .notification-empty { margin: 0; font-style: italic; color: #777; display: none; }
        .notifications.open .notification-empty { display: block; }
        .notifications-footer-toggle { display: flex; justify-content: center; margin-top: 8px; }
        .notifications-footer-toggle button { background: transparent; border: none; color: #ef6c00; font-weight: 700; cursor: pointer; text-decoration: underline; }
        @media (max-width: 768px) { .dashboard { padding: 10px; } .button { width: 100%; } }
    </style>
    <script>
        // JS for routine start (basic timer placeholder)
        function startRoutine(routineId) {
            // Redirect to routine.php for full flow
            window.location.href = 'routine.php?start=' + routineId;
        }

        document.addEventListener('DOMContentLoaded', function () {
            document.querySelectorAll('[data-progress-fill]').forEach(function (fill) {
                const target = parseFloat(fill.dataset.target || '0');
                const clamped = Math.max(0, Math.min(100, target));
                requestAnimationFrame(function () {
                    fill.style.transform = 'scaleX(' + (clamped / 100) + ')';
                });
            });

            const easeOutCubic = function (t) { return 1 - Math.pow(1 - t, 3); };
            const animateCount = function (element) {
                const target = parseFloat(element.dataset.target || '0');
                const prefix = element.dataset.prefix || '';
                const suffix = element.dataset.suffix || '';
                const decimals = parseInt(element.dataset.decimals || '0', 10);
                const duration = parseInt(element.dataset.duration || '1800', 10);
                const startValue = parseFloat(element.dataset.start || '0');
                const startTime = performance.now();

                function update(now) {
                    const elapsed = now - startTime;
                    const progress = Math.min(1, elapsed / duration);
                    const eased = easeOutCubic(progress);
                    const value = startValue + (target - startValue) * eased;
                    const display = decimals > 0 ? value.toFixed(decimals) : Math.round(value).toString();
                    element.textContent = prefix + display + suffix;
                    if (progress < 1) {
                        requestAnimationFrame(update);
                    }
                }
                requestAnimationFrame(update);
            };

            document.querySelectorAll('[data-animate-count]').forEach(function (element) {
                element.textContent = (element.dataset.prefix || '') + (element.dataset.start || '0') + (element.dataset.suffix || '');
                animateCount(element);
            });

            const notifications = document.querySelector('[data-role="notifications"]');
            if (notifications) {
                const toggleTargets = notifications.querySelectorAll('[data-action="toggle-notifications"]');
                const toggle = () => notifications.classList.toggle('open');
                toggleTargets.forEach(btn => btn.addEventListener('click', toggle));
            }
        });
    </script>
</head>
<body class="child-theme">
   <header>
   <h1>Child Dashboard</h1>
   <p>Hi, <?php echo htmlspecialchars($_SESSION['name']); ?>!</p>
   <a href="goal.php">Goals</a> | <a href="task.php">Tasks</a> | <a href="routine.php">Routines</a> | <a href="profile.php?self=1">Profile</a> | <a href="logout.php">Logout</a>
   </header>
   <main class="dashboard">
      <?php if (isset($message)) echo "<p>$message</p>"; ?>
      <?php
         $childTotalPoints = isset($data['remaining_points']) ? max(0, (int)$data['remaining_points']) : 0;
         $progressPercent = isset($data['points_progress']) ? max(0, min(100, (int)$data['points_progress'])) : 0;
         $displayPoints = min(100, $childTotalPoints);
         $extraPoints = max(0, $childTotalPoints - 100);
         $notifications = $data['notifications'] ?? [];
         $notificationCount = is_array($notifications) ? count($notifications) : 0;
         $unreadCount = is_array($notifications) ? count(array_filter($notifications, static function ($n) { return empty($n['is_read']); })) : 0;
      ?>
      <div class="progress">
         <span class="points-progress-title">Total Points</span>
         <div class="points-progress-wrap">
            <div class="points-progress-track">
               <div class="points-progress-fill" data-progress-fill data-target="<?php echo $progressPercent; ?>"></div>
               <span class="points-progress-value" data-animate-count data-target="<?php echo $progressPercent; ?>" data-suffix="%" data-start="0">0%</span>
            </div>
            <div class="points-progress-total"><span data-animate-count data-target="<?php echo $displayPoints; ?>" data-start="0">0</span> / 100</div>
         </div>
         <p class="points-extra">Extra points: <span class="extra-points-num" data-animate-count data-target="<?php echo $extraPoints; ?>" data-start="0">0</span></p>
      </div>
      <div class="notifications" data-role="notifications">
         <div class="notifications-header" data-action="toggle-notifications">
            <div class="notification-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" focusable="false"><path d="M12 24a2.5 2.5 0 0 0 2.45-2h-4.9A2.5 2.5 0 0 0 12 24Zm7.12-6.41-1.17-1.11V11a6 6 0 0 0-5-5.9V4a1 1 0 1 0-2 0v1.1A6 6 0 0 0 5.05 11v5.48l-1.17 1.11A1 1 0 0 0 4.6 19h14.8a1 1 0 0 0 .72-1.69Z"/></svg>
                <span class="notification-badge"><?php echo (int)($unreadCount ?: $notificationCount); ?></span>
            </div>
            <h2>Notifications</h2>
         </div>
         <form method="POST" action="dashboard_child.php">
            <?php if (!empty($notifications)): ?>
               <ul class="notification-list">
                  <?php foreach ($notifications as $note): ?>
                     <li class="notification-item <?php echo !empty($note['is_read']) ? '' : 'unread'; ?>">
                        <input type="checkbox" name="notification_ids[]" value="<?php echo (int)$note['id']; ?>" aria-label="Mark notification as read">
                        <div>
                           <div><?php echo htmlspecialchars($note['message']); ?></div>
                           <div class="notification-meta">
                              <?php echo htmlspecialchars(date('m/d/Y h:i A', strtotime($note['created_at']))); ?>
                              <?php if (!empty($note['type'])): ?> · <?php echo htmlspecialchars(str_replace('_', ' ', $note['type'])); ?><?php endif; ?>
                              <?php if (!empty($note['link_url'])): ?> · <a href="<?php echo htmlspecialchars($note['link_url']); ?>">View</a><?php endif; ?>
                           </div>
                        </div>
                     </li>
                  <?php endforeach; ?>
               </ul>
               <div class="notification-actions">
                  <button type="submit" name="mark_notifications_read" class="button">Mark Selected as Read</button>
               </div>
            <?php else: ?>
               <p class="notification-empty">No notifications yet. Keep going!</p>
            <?php endif; ?>
         </form>
         <div class="notifications-footer-toggle">
            <button type="button" data-action="toggle-notifications">View Notifications</button>
         </div>
         <p class="notification-meta">Ideas to add later: streak bonuses, reminders for upcoming routines, and messages when a new goal is assigned.</p>
      </div>
      <div class="rewards">
         <h2>Available Rewards</h2>
         <?php if (isset($data['rewards']) && is_array($data['rewards']) && !empty($data['rewards'])): ?>
            <?php foreach ($data['rewards'] as $reward): ?>
                  <div class="reward-item">
                     <p><?php echo htmlspecialchars($reward['title']); ?> (<?php echo htmlspecialchars($reward['point_cost']); ?> points)</p>
                     <p><?php echo htmlspecialchars($reward['description']); ?></p>
                     <form method="POST" action="dashboard_child.php">
                        <input type="hidden" name="reward_id" value="<?php echo $reward['id']; ?>">
                        <button type="submit" name="redeem_reward" class="button redeem-button">Redeem</button>
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
                   <div class="redeemed-item">
                      <p>Reward: <?php echo htmlspecialchars($reward['title']); ?> (<?php echo htmlspecialchars($reward['point_cost']); ?> points)</p>
                      <p>Description: <?php echo htmlspecialchars($reward['description']); ?></p>
                      <p>Redeemed on: <?php echo !empty($reward['redeemed_on']) ? htmlspecialchars(date('m/d/Y h:i A', strtotime($reward['redeemed_on']))) : 'Date unavailable'; ?></p>
                      <?php if (!empty($reward['fulfilled_on'])): ?>
                          <p class="fulfilled-label">Fulfilled on: <?php echo htmlspecialchars(date('m/d/Y h:i A', strtotime($reward['fulfilled_on']))); ?></p>
                      <?php else: ?>
                          <p class="awaiting-label">Waiting for parent to fulfill this reward.</p>
                      <?php endif; ?>
                   </div>
             <?php endforeach; ?>
         <?php else: ?>
            <p>No rewards redeemed yet.</p>
         <?php endif; ?>
      </div>
      <div class="active-goals">
         <h2>Active Goals</h2>
         <?php if (isset($data['active_goals']) && is_array($data['active_goals']) && !empty($data['active_goals'])): ?>
            <?php foreach ($data['active_goals'] as $goal): ?>
                  <div class="goal-item">
                     <p><?php echo htmlspecialchars($goal['title']); ?> (Target: <?php echo htmlspecialchars($goal['target_points']); ?> points)</p>
                     <p>Period: <?php echo htmlspecialchars(date('m/d/Y h:i A', strtotime($goal['start_date']))); ?> to <?php echo htmlspecialchars(date('m/d/Y h:i A', strtotime($goal['end_date']))); ?></p>
                     <p>Reward: <?php echo htmlspecialchars($goal['reward_title'] ?? 'None'); ?></p>
                     <?php if (!empty($goal['creator_display_name'])): ?>
                        <p>Created by: <?php echo htmlspecialchars($goal['creator_display_name']); ?></p>
                     <?php endif; ?>
                     <form method="POST" action="dashboard_child.php">
                        <input type="hidden" name="goal_id" value="<?php echo $goal['id']; ?>">
                        <button type="submit" name="request_completion" class="button request-button">Request Completion</button>
                     </form>
                  </div>
            <?php endforeach; ?>
         <?php else: ?>
            <p>No active goals.</p>
         <?php endif; ?>
      </div>
      <div class="completed-goals">
         <h2>Completed Goals</h2>
         <?php if (isset($data['completed_goals']) && is_array($data['completed_goals']) && !empty($data['completed_goals'])): ?>
            <?php foreach ($data['completed_goals'] as $goal): ?>
                  <div class="goal-item">
                     <p><?php echo htmlspecialchars($goal['title']); ?> (Target: <?php echo htmlspecialchars($goal['target_points']); ?> points)</p>
                     <p>Period: <?php echo htmlspecialchars(date('m/d/Y h:i A', strtotime($goal['start_date']))); ?> to <?php echo htmlspecialchars(date('m/d/Y h:i A', strtotime($goal['end_date']))); ?></p>
                     <p>Reward: <?php echo htmlspecialchars($goal['reward_title'] ?? 'None'); ?></p>
                     <?php if (!empty($goal['creator_display_name'])): ?>
                        <p>Created by: <?php echo htmlspecialchars($goal['creator_display_name']); ?></p>
                     <?php endif; ?>
                     <p>Completed on: <?php echo htmlspecialchars(date('m/d/Y h:i A', strtotime($goal['completed_at']))); ?></p>
                  </div>
            <?php endforeach; ?>
         <?php else: ?>
            <p>No completed goals yet.</p>
         <?php endif; ?>
      </div>
      <div class="rejected-goals">
         <h2>Rejected Goals</h2>
         <?php
         $stmt = $db->prepare("SELECT 
                              g.id, 
                              g.title, 
                              g.created_at, 
                              g.rejected_at, 
                              g.rejection_comment,
                              COALESCE(
                                 NULLIF(TRIM(CONCAT(COALESCE(creator.first_name, ''), ' ', COALESCE(creator.last_name, ''))), ''),
                                 NULLIF(creator.name, ''),
                                 creator.username,
                                 'Unknown'
                              ) AS creator_display_name
                              FROM goals g 
                              LEFT JOIN users creator ON g.created_by = creator.id
                              WHERE g.child_user_id = :child_id AND g.status = 'rejected'");
         $stmt->execute([':child_id' => $_SESSION['user_id']]);
         $rejected_goals = $stmt->fetchAll(PDO::FETCH_ASSOC);
         foreach ($rejected_goals as &$goal) {
            $goal['created_at_formatted'] = date('m/d/Y h:i A', strtotime($goal['created_at']));
            $goal['rejected_at_formatted'] = date('m/d/Y h:i A', strtotime($goal['rejected_at']));
         }
         unset($goal);
         ?>
         <?php if (empty($rejected_goals)): ?>
            <p>No rejected goals.</p>
         <?php else: ?>
            <?php foreach ($rejected_goals as $goal): ?>
                  <div class="goal-item">
                     <p>Title: <?php echo htmlspecialchars($goal['title']); ?></p>
                     <?php if (!empty($goal['creator_display_name'])): ?>
                        <p>Created by: <?php echo htmlspecialchars($goal['creator_display_name']); ?></p>
                     <?php endif; ?>
                     <p>Created on: <?php echo htmlspecialchars($goal['created_at_formatted']); ?></p>
                     <p>Rejected on: <?php echo htmlspecialchars($goal['rejected_at_formatted']); ?></p>
                     <p>Comment: <?php echo htmlspecialchars($goal['rejection_comment'] ?? 'No comments available.'); ?></p>
                  </div>
            <?php endforeach; ?>
         <?php endif; ?>
      </div>
      <div class="routines">
         <h2>Routines <span style="font-size: 1.5em; color: #ff9800;">🌟</span></h2>
         <?php if (!empty($routines)): ?>
            <div class="routine-grid">
            <?php foreach ($routines as $routine): ?>
                  <?php
                     $routinePointsTotal = 0;
                     foreach ($routine['tasks'] as $task) {
                        $routinePointsTotal += (int) ($task['point_value'] ?? 0);
                     }
                  ?>
                  <div class="routine-item">
                     <h3><?php echo htmlspecialchars($routine['title']); ?></h3>
                     <?php if (!empty($routine['creator_display_name'])): ?>
                        <p>Created by: <?php echo htmlspecialchars($routine['creator_display_name']); ?></p>
                     <?php endif; ?>
                     <p>Time: <?php echo date('h:i A', strtotime($routine['start_time'])) . ' - ' . date('h:i A', strtotime($routine['end_time'])); ?></p>
                     <p class="routine-points-line">
                        <span>Routine: <?php echo $routinePointsTotal; ?> pts</span>
                        <span>Bonus: <?php echo (int) $routine['bonus_points']; ?> pts</span>
                     </p>
                     <button onclick="startRoutine(<?php echo $routine['id']; ?>)" class="button start-routine-button">Start Routine</button>
                  </div>
            <?php endforeach; ?>
            </div>
         <?php else: ?>
            <p>No routines assigned yet. Ask your parent!</p>
         <?php endif; ?>
      </div>
      <div class="links">
         <a href="goal.php" class="button">View Goals</a>
         <a href="task.php" class="button">View Tasks</a>
         <a href="routine.php" class="button">View Routines</a>
      </div>
   </main>
   <footer>
   <p>Child Task and Chore App - Ver 3.10.15</p>
</footer>
</body>
</html>
