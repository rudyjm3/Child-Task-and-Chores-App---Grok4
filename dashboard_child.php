<?php
// dashboard_child.php - Child dashboard
// Purpose: Display child dashboard with progress and task/reward links
// Inputs: Session data
// Outputs: Dashboard interface

require_once __DIR__ . '/includes/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['redeem_reward'])) {
    $reward_id = filter_input(INPUT_POST, 'reward_id', FILTER_VALIDATE_INT);
    if (redeemReward($_SESSION['user_id'], $reward_id)) {
        $message = "Reward redeemed successfully! Refresh to see updates.";
        $data = getDashboardData($_SESSION['user_id]); // Refresh data
    } else {
        $message = "Not enough points to redeem this reward.";
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
        .dashboard {
            padding: 20px;
            max-width: 600px;
            margin: 0 auto;
            text-align: center;
        }
        .progress {
            margin: 20px 0;
            font-size: 1.2em;
            color: #4caf50;
        }
        .rewards, .goals {
            margin: 20px 0;
        }
        .reward-item, .goal-item {
            background-color: #f5f5f5;
            padding: 10px;
            margin: 5px 0;
            border-radius: 5px;
        }
        .button {
            padding: 10px 20px;
            margin: 5px;
            background-color: #ff9800;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }
        .redeem-button {
            background-color: #2196f3;
        }
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
        <div class="goals">
            <h2>Your Goals</h2>
            <?php if (isset($data['goals']) && is_array($data['goals']) && !empty($data['goals'])): ?>
                <?php foreach ($data['goals'] as $goal): ?>
                    <div class="goal-item">
                        <p><?php echo htmlspecialchars($goal['title']); ?> (Target: <?php echo htmlspecialchars($goal['target_points']); ?> points)</p>
                        <p>Period: <?php echo htmlspecialchars($goal['start_date']); ?> to <?php echo htmlspecialchars($goal['end_date']); ?></p>
                        <p>Reward: <?php echo htmlspecialchars($goal['reward_title'] ?? 'None'); ?></p>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p>No active goals.</p>
            <?php endif; ?>
        </div>
        <div class="links">
            <a href="task.php" class="button">View Tasks</a>
            <a href="#" class="button">View Rewards</a>
        </div>
    </main>
    <footer>
        <p>Child Task and Chore App - Ver 3.0.0</p>
    </footer>
</body>
</html>