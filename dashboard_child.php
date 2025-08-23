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

$data = getDashboardData($_SESSION['user_id']); // Fetch child-specific dashboard data
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
        </div>
        <div class="links">
            <a href="task.php" class="button">View Tasks</a>
            <a href="#" class="button">View Rewards</a>
        </div>
    </main>
    <footer>
        <p>Child Task and Chore App - Ver 2.0.0</p>
    </footer>
</body>
</html>