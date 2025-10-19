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
    } elseif (isset($_POST['redeem_reward'])) {
        $reward_id = filter_input(INPUT_POST, 'reward_id', FILTER_VALIDATE_INT);
        if (redeemReward($_SESSION['user_id'], $reward_id)) {
            $message = "Reward redeemed successfully! Refresh to see updates.";
            $data = getDashboardData($_SESSION['user_id']); // Refresh data
        } else {
            $message = "Not enough points to redeem this reward.";
        }
    } elseif (isset($_POST['complete_routine'])) {
        $routine_id = filter_input(INPUT_POST, 'routine_id', FILTER_VALIDATE_INT);
        $bonus = completeRoutine($routine_id, $_SESSION['user_id']);
        if ($bonus !== false) {
            $message = "Routine completed! Bonus points awarded: $bonus";
            $routines = getRoutines($_SESSION['user_id']); // Refresh
        } else {
            $message = "Failed to complete routine (ensure all tasks are approved).";
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
    <link rel="stylesheet" href="css/main.css">
    <style>
        .dashboard { padding: 20px; max-width: 600px; margin: 0 auto; text-align: center; }
        .progress { margin: 20px 0; font-size: 1.2em; color: #4caf50; }
        .rewards, .redeemed-rewards, .active-goals, .completed-goals, .rejected-goals, .routines { margin: 20px 0; }
        .reward-item, .redeemed-item, .goal-item, .routine-item { background-color: #f5f5f5; padding: 10px; margin: 5px 0; border-radius: 5px; }
        .button { padding: 10px 20px; margin: 5px; background-color: #ff9800; color: white; border: none; border-radius: 5px; cursor: pointer; text-decoration: none; display: inline-block; font-size: 16px; min-height: 44px; }
        .redeem-button { background-color: #2196f3; }
        .request-button { background-color: #9c27b0; }
        .start-routine-button { background-color: #4caf50; }
        .complete-routine-button { background-color: #ffeb3b; color: #333; }
        /* Kid-Friendly Styles - Autism-Friendly: Bright pastels, large buttons */
        .routine-item { background: linear-gradient(135deg, #e3f2fd, #f3e5f5); border: 2px solid #bbdefb; margin: 10px 0; }
        .routine-item h3 { color: #1976d2; font-size: 1.5em; }
        .routine-item p { font-size: 1.1em; }
        @media (max-width: 768px) { .dashboard { padding: 10px; } .button { width: 100%; } }
    </style>
    <script>
        // JS for routine start (basic timer placeholder)
        function startRoutine(routineId) {
            // Redirect to routine.php for full flow
            window.location.href = 'routine.php?start=' + routineId;
        }
    </script>
</head>
<body>
   <header>
   <h1>Child Dashboard</h1>
   <p>Hi, <?php echo htmlspecialchars($_SESSION['name']); ?>!</p>
   <a href="goal.php">Goals</a> | <a href="task.php">Tasks</a> | <a href="routine.php">Routines</a> | <a href="profile.php?self=1">Profile</a> | <a href="logout.php">Logout</a>
   </header>
   <main class="dashboard">
      <?php if (isset($message)) echo "<p>$message</p>"; ?>
      <div class="progress">
         <p>Points Progress: <?php echo isset($data['points_progress']) ? htmlspecialchars($data['points_progress']) . '%' : '0%'; ?></p>
         <p>Remaining Points: <?php echo isset($data['remaining_points']) ? htmlspecialchars($data['remaining_points']) : '0'; ?></p>
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
         $stmt = $db->prepare("SELECT g.id, g.title, g.created_at, g.rejected_at, g.rejection_comment 
                              FROM goals g 
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
            <?php foreach ($routines as $routine): ?>
                  <div class="routine-item">
                     <h3><?php echo htmlspecialchars($routine['title']); ?></h3>
                     <p>Time: <?php echo date('h:i A', strtotime($routine['start_time'])) . ' - ' . date('h:i A', strtotime($routine['end_time'])); ?></p>
                     <p>Bonus: <?php echo $routine['bonus_points']; ?> points</p>
                     <button onclick="startRoutine(<?php echo $routine['id']; ?>)" class="button start-routine-button">Start Routine</button>
                     <form method="POST" action="dashboard_child.php">
                        <input type="hidden" name="routine_id" value="<?php echo $routine['id']; ?>">
                        <button type="submit" name="complete_routine" class="button complete-routine-button">Complete Routine</button>
                     </form>
                     <ul>
                        <?php foreach ($routine['tasks'] as $task): ?>
                           <li><?php echo htmlspecialchars($task['title']); ?> (<?php echo $task['time_limit']; ?> min)</li>
                        <?php endforeach; ?>
                     </ul>
                  </div>
            <?php endforeach; ?>
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
   <p>Child Task and Chore App - Ver 3.4.8</p>
   </footer>
</body>
</html>
