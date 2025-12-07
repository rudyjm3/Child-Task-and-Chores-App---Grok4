<?php
// edit_goal.php - Edit goal details
// Purpose: Allow parents to edit goal details
// Inputs: POST for update, goal ID from GET
// Outputs: Goal edit interface
// Version: 3.11.0

session_start();
require_once __DIR__ . '/includes/functions.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'parent') {
    header("Location: login.php");
    exit;
}

$goal_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$goal_id) {
    header("Location: goal.php");
    exit;
}

// Fetch goal details
$stmt = $db->prepare("SELECT g.title, g.target_points, g.start_date, g.end_date, g.reward_id, g.child_user_id 
                     FROM goals g 
                     WHERE g.id = :goal_id AND g.parent_user_id = :parent_id");
$stmt->execute([':goal_id' => $goal_id, ':parent_id' => $_SESSION['user_id']]);
$goal = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$goal) {
    header("Location: goal.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = filter_input(INPUT_POST, 'title', FILTER_SANITIZE_STRING);
    $target_points = filter_input(INPUT_POST, 'target_points', FILTER_VALIDATE_INT);
    $start_date = filter_input(INPUT_POST, 'start_date', FILTER_SANITIZE_STRING);
    $end_date = filter_input(INPUT_POST, 'end_date', FILTER_SANITIZE_STRING);
    $reward_id = filter_input(INPUT_POST, 'reward_id', FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE);

    if (updateGoal($goal_id, $_SESSION['user_id'], $title, $target_points, $start_date, $end_date, $reward_id)) {
        $message = "Goal updated successfully!";
        header("Location: goal.php?message=" . urlencode($message));
        exit;
    } else {
        $message = "Failed to update goal.";
    }
}

$welcome_role_label = getUserRoleLabel($_SESSION['user_id']);
if (!$welcome_role_label) {
    $fallback_role = getEffectiveRole($_SESSION['user_id']) ?: ($_SESSION['role'] ?? null);
    if ($fallback_role) {
        $welcome_role_label = ucfirst(str_replace('_', ' ', $fallback_role));
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Goal</title>
    <link rel="stylesheet" href="css/main.css?v=3.11.0">
    <style>
        .goal-form {
            padding: 20px;
            max-width: 800px;
            margin: 0 auto;
        }
        .goal-form label {
            display: block;
            margin-bottom: 5px;
        }
        .goal-form input, .goal-form select, .goal-form textarea {
            width: 100%;
            margin-bottom: 10px;
            padding: 8px;
        }
        .button {
            padding: 10px 20px;
            margin: 5px;
            background-color: #4caf50;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
        }
        .role-badge {
            background: #4caf50;
            color: #fff;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 0.9em;
            margin-left: 8px;
            display: inline-block;
        }
    </style>
</head>
<body>
    <header>
        <h1>Edit Goal</h1>
        <p>Welcome, <?php echo htmlspecialchars($_SESSION['name'] ?? $_SESSION['username'] ?? 'Unknown User'); ?>
            <?php if ($welcome_role_label): ?>
                <span class="role-badge">(<?php echo htmlspecialchars($welcome_role_label); ?>)</span>
            <?php endif; ?>
        </p>
        <a href="goal.php">Back to Goals</a> | <a href="dashboard_parent.php">Dashboard</a> | <a href="logout.php">Logout</a>
    </header>
    <main>
        <?php if (isset($message)) echo "<p>$message</p>"; ?>
        <div class="goal-form">
            <form method="POST" action="edit_goal.php?id=<?php echo $goal_id; ?>">
                <label for="title">Title:</label>
                <input type="text" id="title" name="title" value="<?php echo htmlspecialchars($goal['title']); ?>" required>
                <label for="target_points">Target Points:</label>
                <input type="number" id="target_points" name="target_points" min="1" value="<?php echo htmlspecialchars($goal['target_points']); ?>" required>
                <label for="start_date">Start Date/Time:</label>
                <input type="datetime-local" id="start_date" name="start_date" value="<?php echo date('Y-m-d\TH:i', strtotime($goal['start_date'])); ?>" required>
                <label for="end_date">End Date/Time:</label>
                <input type="datetime-local" id="end_date" name="end_date" value="<?php echo date('Y-m-d\TH:i', strtotime($goal['end_date'])); ?>" required>
                <label for="reward_id">Reward (optional):</label>
                <select id="reward_id" name="reward_id">
                    <option value="">None</option>
                    <?php
                    $stmt = $db->prepare("SELECT id, title FROM rewards WHERE parent_user_id = :parent_id AND status = 'available'");
                    $stmt->execute([':parent_id' => $_SESSION['user_id']]);
                    $rewards = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($rewards as $reward): ?>
                        <option value="<?php echo $reward['id']; ?>" <?php if ($reward['id'] == $goal['reward_id']) echo 'selected'; ?>><?php echo htmlspecialchars($reward['title']); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" name="update_goal" class="button">Update Goal</button>
            </form>
        </div>
    </main>
    <footer>
        <p>Child Task and Chore App - Ver 3.10.16</p>
    </footer>
</body>
</html>
