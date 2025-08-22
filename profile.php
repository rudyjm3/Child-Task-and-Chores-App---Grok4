<?php
// profile.php - User profile management
// Purpose: Display and manage user/child profiles
// Inputs: POST data for child profiles
// Outputs: Profile view and child profile form

require_once __DIR__ . '/includes/functions.php';

session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_SESSION['role'] === 'parent') {
    $avatar = filter_input(INPUT_POST, 'avatar', FILTER_SANITIZE_STRING);
    $age = filter_input(INPUT_POST, 'age', FILTER_VALIDATE_INT);
    $preferences = filter_input(INPUT_POST, 'preferences', FILTER_SANITIZE_STRING);

    if (createChildProfile($_SESSION['user_id'], $avatar, $age, $preferences)) {
        $message = "Child profile created successfully!";
    } else {
        $message = "Failed to create child profile.";
    }
}

// Fetch user and child profiles
$userStmt = $db->prepare("SELECT * FROM users WHERE id = :id");
$userStmt->execute([':id' => $_SESSION['user_id']]);
$user = $userStmt->fetch(PDO::FETCH_ASSOC);

$childStmt = $db->prepare("SELECT * FROM child_profiles WHERE user_id = :user_id");
$childStmt->execute([':user_id' => $_SESSION['user_id']]);
$children = $childStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile</title>
    <link rel="stylesheet" href="css/main.css">
</head>
<body>
    <header>
        <h1>Profile</h1>
        <p>Welcome, <?php echo htmlspecialchars($user['username']); ?> (<?php echo htmlspecialchars($user['role']); ?>)</p>
        <a href="logout.php">Logout</a> <!-- Placeholder, create logout.php later -->
    </header>
    <main>
        <?php if (isset($message)) echo "<p>$message</p>"; ?>
        <?php if ($user['role'] === 'parent'): ?>
            <h2>Manage Child Profiles</h2>
            <?php foreach ($children as $child): ?>
                <div>
                    <p>Child: Avatar=<?php echo htmlspecialchars($child['avatar']); ?>, Age=<?php echo htmlspecialchars($child['age']); ?>, Preferences=<?php echo htmlspecialchars($child['preferences']); ?></p>
                </div>
            <?php endforeach; ?>
            <form method="POST" action="profile.php">
                <label for="avatar">Avatar (e.g., 'star', 'tree'):</label><br>
                <input type="text" id="avatar" name="avatar" required><br>
                <label for="age">Age:</label><br>
                <input type="number" id="age" name="age" min="5" max="13" required><br>
                <label for="preferences">Preferences:</label><br>
                <textarea id="preferences" name="preferences"></textarea><br>
                <button type="submit">Add Child</button>
            </form>
        <?php endif; ?>
    </main>
    <footer>
        <p>Child Task and Chore App - Ver 1.1.0</p>
    </footer>
</body>
</html>