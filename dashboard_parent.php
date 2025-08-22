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
        .children-overview, .management-links {
            margin-top: 20px;
        }
        .child-item {
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
                        <p>Child: <?php echo htmlspecialchars($child['avatar'] ?? 'No Avatar'); ?>, Age: <?php echo htmlspecialchars($child['age'] ?? 'N/A'); ?></p>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p>No children registered.</p>
            <?php endif; ?>
        </div>
        <div class="management-links">
            <h2>Management Links</h2>
            <a href="task.php" class="button">Create Task</a>
            <a href="#" class="button">Create Goal</a>
            <a href="#" class="button">Create Reward</a>
            <a href="#" class="button">Summary Charts</a>
            <p>Points Earned: <?php echo isset($data['tasks']) && is_array($data['tasks']) ? array_sum(array_column($data['tasks'], 'points')) : 0; ?></p>
            <p>Goals Met: <?php echo 0; // Placeholder, to be implemented in later phases ?></p>
        </div>
    </main>
    <footer>
        <p>Child Task and Chore App - Ver 2.0.0</p>
    </footer>
</body>
</html>