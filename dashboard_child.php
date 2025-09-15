<?php
// dashboard_child.php - Child dashboard
// Purpose: Display child dashboard with progress and task/reward links
// Inputs: Session data
// Outputs: Dashboard interface

require_once __DIR__ . '/includes/functions.php';

session_start(); // Force session start to load existing session
error_log("Dashboard Child: user_id=" . (isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 'null') . ", role=" . (isset($_SESSION['role']) ? $_SESSION['role'] : 'null') . ", session_id=" . session_id() . ", cookie=" . (isset($_SERVER['HTTP_COOKIE']) ? $_SERVER['HTTP_COOKIE'] : 'none'));
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'child') {
    header("Location: login.php");
    exit;
}

// Set username in session if not already set
if (!isset($_SESSION['username'])) {
    $userStmt = $db->prepare("SELECT username FROM users WHERE id = :id");
    $userStmt->execute([':id' => $_SESSION['user_id']]);
    $_SESSION['username'] = $userStmt->fetchColumn() ?: 'Unknown User';
}

$data = getDashboardData($_SESSION['user_id']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['request_completion'])) {
        $goal_id = filter_input(INPUT_POST, 'goal_id', FILTER_VALIDATE_INT);
        if (requestGoalCompletion($_SESSION['user_id'], $goal_id)) {
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
        .rewards, .redeemed-rewards, .active-goals, .completed-goals { margin: 20px 0; }
        .reward-item, .redeemed-item, .goal-item { background-color: #f5f5f5; padding: 10px; margin: 5px 0; border-radius: 5px; }
        .button { padding: 10px 20px; margin: 5px; background-color: #ff9800; color: white; border: none; border-radius: 5px; cursor: pointer; text-decoration: none; display: inline-block; }
        .redeem-button { background-color: #2196f3; }
        .request-button { background-color: #9c27b0; }
    </style>
</head>
<body>
    <header>
        <h1>Child Dashboard</h1>
        <p>Hi, <?php echo htmlspecialchars($_SESSION['username'] ?? 'Unknown User'); ?>!</p>
        <a href="profile.php">Profile</a> | <a href="logout.php">Logout</a>
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
                        <p>Redeemed on: <?php echo htmlspecialchars(date('m/d/Y h:i A', strtotime($reward['redeemed_on']))) ?? 'Date unavailable'; ?></p>
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
        <div class="links">
            <a href="task.php" class="button">View Tasks</a>
            <a href="#" class="button">View Rewards</a>
        </div>
    </main>
    <footer>
      <p>Child Task and Chores App - Ver 3.3.9</p>
    </footer>
</body>
</html>