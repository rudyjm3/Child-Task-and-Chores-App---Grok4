<?php
// dashboard_child.php - Child dashboard
// Purpose: Display tasks, points, and rewards in a kid-friendly way
// Inputs: User session data
// Outputs: Dashboard with large buttons and progress bars

require_once __DIR__ . '/includes/functions.php';

session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'child') {
    header("Location: login.php");
    exit;
}

$dashboardData = getDashboardData($_SESSION['user_id']);
$user = $dashboardData['user'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Child Dashboard</title>
    <link rel="stylesheet" href="css/main.css">
    <style>
        .child-dashboard {
            padding: 20px;
            text-align: center;
            background-color: #e0f7fa; /* Light blue for calm, kid-friendly feel */
        }
        .button {
            font-size: 1.5em;
            padding: 15px 30px;
            margin: 10px;
            background-color: #4caf50;
            color: white;
            border: none;
            border-radius: 10px;
            cursor: pointer;
        }
        .progress {
            width: 80%;
            height: 30px;
            background-color: #ddd;
            border-radius: 15px;
            margin: 20px auto;
            overflow: hidden;
        }
        .progress-bar {
            height: 100%;
            background-color: #ff9800;
            text-align: center;
            color: white;
        }
    </style>
</head>
<body>
    <header>
        <h1>Child Dashboard</h1>
        <p>Hi, <?php echo htmlspecialchars($user['username']); ?>!</p>
        <a href="profile.php">Profile</a> | <a href="logout.php">Logout</a>
    </header>
    <main class="child-dashboard">
        <div class="progress">
            <div class="progress-bar" style="width: 50%;">50% Points</div> <!-- Placeholder -->
        </div>
        <button class="button">View Tasks</button>
        <button class="button">View Rewards</button>
    </main>
    <footer>
        <p>Child Task and Chore App - Ver 1.2.0</p>
    </footer>
</body>
</html>