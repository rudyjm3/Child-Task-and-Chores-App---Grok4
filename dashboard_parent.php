<?php
// dashboard_parent.php - Parent dashboard
// Purpose: Display overview of children's progress and management options
// Inputs: User session data
// Outputs: Dashboard with summary charts and links

require_once __DIR__ . '/includes/functions.php';

session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'parent') {
    header("Location: login.php");
    exit;
}

$dashboardData = getDashboardData($_SESSION['user_id']);
$user = $dashboardData['user'];
$children = $dashboardData['children'];
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
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            padding: 20px;
        }
        .card {
            background: #f0f0f0;
            padding: 15px;
            border-radius: 8px;
            text-align: center;
        }
        .chart-placeholder {
            height: 200px;
            background: #ddd;
            margin-top: 10px;
        }
    </style>
</head>
<body>
    <header>
        <h1>Parent Dashboard</h1>
        <p>Welcome, <?php echo htmlspecialchars($user['username']); ?></p>
        <a href="profile.php">Profile</a> | <a href="logout.php">Logout</a>
    </header>
    <main class="dashboard">
        <div class="card">
            <h2>Children Overview</h2>
            <?php foreach ($children as $child): ?>
                <p>Child: <?php echo htmlspecialchars($child['avatar']); ?>, Age: <?php echo htmlspecialchars($child['age']); ?></p>
            <?php endforeach; ?>
        </div>
        <div class="card">
            <h2>Management Links</h2>
            <ul>
                <li><a href="task.php">Create Task</a></li> <!-- Placeholder, create later -->
                <li><a href="goal.php">Create Goal</a></li> <!-- Placeholder -->
                <li><a href="reward.php">Create Reward</a></li> <!-- Placeholder -->
            </ul>
        </div>
        <div class="card">
            <h2>Summary Charts</h2>
            <p>Points Earned: <span class="chart-placeholder"></span></p>
            <p>Goals Met: <span class="chart-placeholder"></span></p>
        </div>
    </main>
    <footer>
        <p>Child Task and Chore App - Ver 1.2.0</p>
    </footer>
</body>
</html>