<?php
// dashboard_parent.php - Parent dashboard
// Purpose: Display parent dashboard with child overview and management links
// Inputs: Session data
// Outputs: Dashboard interface

require_once __DIR__ . '/includes/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'parent') {
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
    if (isset($_POST['create_reward'])) {
        $title = filter_input(INPUT_POST, 'reward_title', FILTER_SANITIZE_STRING);
        $description = filter_input(INPUT_POST, 'reward_description', FILTER_SANITIZE_STRING);
        $point_cost = filter_input(INPUT_POST, 'point_cost', FILTER_VALIDATE_INT);
        if (createReward($_SESSION['user_id'], $title, $description, $point_cost)) {
            $message = "Reward created successfully!";
        } else {
            $message = "Failed to create reward.";
        }
    } elseif (isset($_POST['create_goal'])) {
        $child_user_id = filter_input(INPUT_POST, 'child_user_id', FILTER_VALIDATE_INT);
        $title = filter_input(INPUT_POST, 'goal_title', FILTER_SANITIZE_STRING);
        $target_points = filter_input(INPUT_POST, 'target_points', FILTER_VALIDATE_INT);
        $start_date = filter_input(INPUT_POST, 'start_date', FILTER_SANITIZE_STRING);
        $end_date = filter_input(INPUT_POST, 'end_date', FILTER_SANITIZE_STRING);
        $reward_id = filter_input(INPUT_POST, 'reward_id', FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE);
        if (createGoal($_SESSION['user_id'], $child_user_id, $title, $target_points, $start_date, $end_date, $reward_id)) {
            $message = "Goal created successfully!";
        } else {
            $message = "Failed to create goal. Check date range or reward ID.";
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
    <link rel="stylesheet" href="css/main.css">
    <style>
        .dashboard {
            padding: 20px;
            max-width: 800px;
            margin: 0 auto;
        }
        .children-overview, .management-links, .active-rewards, .redeemed-rewards {
            margin-top: 20px;
        }
        .child-item, .reward-item {
            background-color: #f5f5f5;
            padding: 10px;
            margin: 5px 0;
            border-radius: 5px;
        }
        .button {
            padding: 10px 20px;
            margin: 5px;
            background-color: #4caf50;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }
        .form-group {
            margin-bottom: 15px;
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
        }
        .form-group input, .form-group select, .form-group textarea {
            width: 100%;
            padding: 8px;
        }
    </style>
</head>
<body>
    <header>
        <h1>Parent Dashboard</h1>
        <p>Welcome, <?php echo htmlspecialchars($_SESSION['username'] ?? 'Unknown User'); ?> (<?php echo htmlspecialchars($_SESSION['role']); ?>)</p>
        <a href="profile.php">Profile</a> | <a href="logout.php">Logout</a>
    </header>
    <main class="dashboard">
        <?php if (isset($message)) echo "<p>$message</p>"; ?>
        <div class="children-overview">
            <h2>Children Overview</h2>
            <?php if (isset($data['children']) && is_array($data['children']) && !empty($data['children'])): ?>
                <?php foreach ($data['children'] as $child): ?>
                    <div class="child-item">
                        <p>Child: <?php echo htmlspecialchars($child['username']); ?>, Avatar=<?php echo htmlspecialchars($child['avatar'] ?? 'No Avatar'); ?>, Age=<?php echo htmlspecialchars($child['age'] ?? 'N/A'); ?></p>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p>No children registered.</p>
            <?php endif; ?>
        </div>
        <div class="management-links">
            <h2>Management Links</h2>
            <a href="task.php" class="button">Create Task</a>
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
                    <button type="submit" name="create_reward">Create Reward</button>
                </form>
            </div>
            <div>
                <h3>Create Goal</h3>
                <form method="POST" action="dashboard_parent.php">
                    <div class="form-group">
                        <label for="child_user_id">Child:</label>
                        <select id="child_user_id" name="child_user_id" required>
                            <?php foreach ($data['children'] as $child): ?>
                                <option value="<?php echo $child['child_user_id']; ?>"><?php echo htmlspecialchars($child['username']); ?></option>
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
                        <label for="start_date">Start Date/Time:</label>
                        <input type="datetime-local" id="start_date" name="start_date">
                    </div>
                    <div class="form-group">
                        <label for="end_date">End Date/Time:</label>
                        <input type="datetime-local" id="end_date" name="end_date">
                    </div>
                    <div class="form-group">
                        <label for="reward_id">Reward (Optional):</label>
                        <select id="reward_id" name="reward_id">
                            <option value="">None</option>
                            <?php
                            $stmt = $db->prepare("SELECT id, title FROM rewards WHERE parent_user_id = :parent_id AND status = 'available'");
                            $stmt->execute([':parent_id' => $_SESSION['user_id']]);
                            $rewards = $stmt->fetchAll(PDO::FETCH_ASSOC);
                            foreach ($rewards as $reward): ?>
                                <option value="<?php echo $reward['id']; ?>"><?php echo htmlspecialchars($reward['title']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" name="create_goal">Create Goal</button>
                </form>
            </div>
            <a href="#" class="button">Summary Charts</a>
            <p>Points Earned: <?php echo isset($data['tasks']) && is_array($data['tasks']) ? array_sum(array_column($data['tasks'], 'points')) : 0; ?></p>
            <p>Goals Met: <?php echo 0; // Placeholder, to be implemented ?></p>
        </div>
        <div class="active-rewards">
            <h2>Active Rewards</h2>
            <?php if (isset($data['active_rewards']) && is_array($data['active_rewards']) && !empty($data['active_rewards'])): ?>
                <?php foreach ($data['active_rewards'] as $reward): ?>
                    <div class="reward-item">
                        <p><?php echo htmlspecialchars($reward['title']); ?> (<?php echo htmlspecialchars($reward['point_cost']); ?> points)</p>
                        <p><?php echo htmlspecialchars($reward['description']); ?></p>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p>No active rewards available.</p>
            <?php endif; ?>
        </div>
        <div class="redeemed-rewards">
            <h2>Redeemed Rewards</h2>
            <?php if (isset($data['redeemed_rewards']) && is_array($data['redeemed_rewards']) && !empty($data['redeemed_rewards'])): ?>
                <?php foreach ($data['redeemed_rewards'] as $reward): ?>
                    <div class="reward-item">
                        <p>Reward: <?php echo htmlspecialchars($reward['title']); ?> (<?php echo htmlspecialchars($reward['point_cost']); ?> points)</p>
                        <p>Description: <?php echo htmlspecialchars($reward['description']); ?></p>
                        <p>Redeemed by: <?php echo htmlspecialchars($reward['child_username']); ?></p>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p>No rewards redeemed yet.</p>
            <?php endif; ?>
        </div>
    </main>
    <footer>
        <p>Child Task and Chore App - Ver 3.1.0</p>
    </footer>
</body>
</html>