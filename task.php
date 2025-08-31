<?php
// task.php - Task and chore management
// Purpose: Allow parents to create tasks and children to view/complete them
// Inputs: POST data for task creation, task ID for completion
// Outputs: Task management interface

session_start(); // Ensure session is started to load existing session

require_once __DIR__ . '/includes/functions.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// Set username in session if not already set (for display)
if (!isset($_SESSION['username'])) {
    $userStmt = $db->prepare("SELECT username FROM users WHERE id = :id");
    $userStmt->execute([':id' => $_SESSION['user_id']]);
    $username = $userStmt->fetchColumn();
    if ($username) {
        $_SESSION['username'] = $username;
    } else {
        error_log("Username not found for user_id: " . $_SESSION['user_id']);
        $_SESSION['username'] = "Unknown User";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create_task'])) {
        $child_user_id = filter_input(INPUT_POST, 'child_user_id', FILTER_VALIDATE_INT);
        $title = filter_input(INPUT_POST, 'title', FILTER_SANITIZE_STRING);
        $description = filter_input(INPUT_POST, 'description', FILTER_SANITIZE_STRING);
        $due_date = filter_input(INPUT_POST, 'due_date', FILTER_SANITIZE_STRING);
        $points = filter_input(INPUT_POST, 'points', FILTER_VALIDATE_INT);
        $recurrence = filter_input(INPUT_POST, 'recurrence', FILTER_SANITIZE_STRING);
        $category = filter_input(INPUT_POST, 'category', FILTER_SANITIZE_STRING);
        $timing_mode = filter_input(INPUT_POST, 'timing_mode', FILTER_SANITIZE_STRING);

        if (createTask($_SESSION['user_id'], $child_user_id, $title, $description, $due_date, $points, $recurrence, $category, $timing_mode)) {
            $message = "Task created successfully!";
        } else {
            $message = "Failed to create task.";
        }
    } elseif (isset($_POST['complete_task'])) {
        $task_id = filter_input(INPUT_POST, 'task_id', FILTER_VALIDATE_INT);
        $photo_proof = $_FILES['photo_proof']['name'] ? 'uploads/' . $_FILES['photo_proof']['name'] : null;
        if (completeTask($task_id, $_SESSION['user_id'], $photo_proof)) {
            if ($photo_proof && is_uploaded_file($_FILES['photo_proof']['tmp_name'])) {
                move_uploaded_file($_FILES['photo_proof']['tmp_name'], $photo_proof);
            }
            $message = "Task marked as completed (awaiting approval).";
        } else {
            $message = "Failed to complete task.";
        }
    } elseif (isset($_POST['approve_task']) && $_SESSION['role'] === 'parent') {
        $task_id = filter_input(INPUT_POST, 'task_id', FILTER_VALIDATE_INT);
        if (approveTask($task_id)) {
            $message = "Task approved!";
        } else {
            $message = "Failed to approve task.";
        }
    }
}

$tasks = getTasks($_SESSION['user_id']);
// Format due_date for display
foreach ($tasks as &$task) {
    $task['due_date_formatted'] = date('m/d/Y h:i A', strtotime($task['due_date']));
}

// Group tasks by status for sectioned display
$pending_tasks = array_filter($tasks, function($t) { return $t['status'] === 'pending'; });
$completed_tasks = array_filter($tasks, function($t) { return $t['status'] === 'completed'; }); // Waiting approval
$approved_tasks = array_filter($tasks, function($t) { return $t['status'] === 'approved'; });
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Task Management</title>
    <link rel="stylesheet" href="css/main.css">
    <style>
        .task-form, .task-list {
            padding: 20px;
            max-width: 800px;
            margin: 0 auto;
        }
        .task-form label, .task-list label {
            display: block;
            margin-bottom: 5px;
        }
        .task-form input, .task-form select, .task-form textarea {
            width: 100%;
            margin-bottom: 10px;
            padding: 8px;
        }
        .timer {
            font-size: 1.5em;
            color: #ff9800;
        }
        .completed {
            background-color: #e0e0e0;
            padding: 10px;
            margin: 5px 0;
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
        /* Improved task card styling for spacing and readability */
        .task-card {
            padding: 15px;
            margin-bottom: 20px;
            border: 1px solid #ddd;
            border-radius: 8px;
            background: #f9f9f9;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        /* Overdue styles (role-specific colors for autism-friendliness) */
        .overdue {
            border-left: 5px solid <?php echo ($_SESSION['role'] === 'parent') ? '#d9534f' : '#ff9900'; ?>; /* Red for parent, orange for child */
        }
        .overdue-label {
            background-color: <?php echo ($_SESSION['role'] === 'parent') ? '#d9534f' : '#ff9900'; ?>;
            color: white;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.9em;
            margin-left: 10px;
        }
        .waiting-label {
            color: #ff9800;
            font-style: italic;
        }
        .edit-delete {
            margin-top: 10px;
        }
        .edit-delete a {
            margin-right: 10px;
            color: #007bff;
            text-decoration: none;
        }
        .edit-delete a:hover {
            text-decoration: underline;
        }
    </style>
    <script>
        function startTimer(taskId, limit) {
            let time = limit * 60;
            const timerElement = document.getElementById(`timer-${taskId}`);
            const interval = setInterval(() => {
                let minutes = Math.floor(time / 60);
                let seconds = time % 60;
                timerElement.textContent = `${minutes}:${seconds < 10 ? '0' : ''}${seconds}`;
                if (time <= 0) {
                    clearInterval(interval);
                    alert("Time's up! Try to hurry and finish up.");
                }
                time--;
            }, 1000);
            localStorage.setItem(`timer-${taskId}`, Date.now() + limit * 1000);
        }
    </script>
</head>
<body>
    <header>
        <h1>Task Management</h1>
        <p>Welcome, <?php echo htmlspecialchars($_SESSION['username'] ?? 'Unknown User'); ?> (<?php echo htmlspecialchars($_SESSION['role']); ?>)</p>
        <a href="profile.php">Profile</a> | <a href="logout.php">Logout</a>
    </header>
    <main>
        <?php if (isset($message)) echo "<p>$message</p>"; ?>
        <?php if ($_SESSION['role'] === 'parent'): ?>
            <div class="task-form">
                <h2>Create Task</h2>
                <form method="POST" action="task.php" enctype="multipart/form-data">
                    <label for="child_user_id">Child:</label>
                    <select id="child_user_id" name="child_user_id" required>
                        <?php
                        $stmt = $db->prepare("SELECT cp.child_user_id, u.username FROM child_profiles cp JOIN users u ON cp.child_user_id = u.id WHERE cp.parent_user_id = :parent_id");
                        $stmt->execute([':parent_id' => $_SESSION['user_id']]);
                        $children = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        foreach ($children as $child): ?>
                            <option value="<?php echo $child['child_user_id']; ?>"><?php echo htmlspecialchars($child['username']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label for="title">Title:</label>
                    <input type="text" id="title" name="title" required>
                    <label for="description">Description:</label>
                    <textarea id="description" name="description"></textarea>
                    <label for="due_date">Due Date/Time:</label>
                    <input type="datetime-local" id="due_date" name="due_date">
                    <label for="points">Points:</label>
                    <input type="number" id="points" name="points" min="1" required>
                    <label for="recurrence">Recurrence:</label>
                    <select id="recurrence" name="recurrence">
                        <option value="">None</option>
                        <option value="daily">Daily</option>
                        <option value="weekly">Weekly</option>
                    </select>
                    <label for="category">Category:</label>
                    <select id="category" name="category">
                        <option value="hygiene">Hygiene</option>
                        <option value="homework">Homework</option>
                        <option value="household">Household</option>
                    </select>
                    <label for="timing_mode">Timing Mode:</label>
                    <select id="timing_mode" name="timing_mode">
                        <option value="timer">Timer</option>
                        <option value="suggested">Suggested Time</option>
                        <option value="no_limit">No Time Limit</option>
                    </select>
                    <button type="submit" name="create_task">Create Task</button>
                </form>
            </div>
        <?php endif; ?>
        <div class="task-list">
            <h2><?php echo ($_SESSION['role'] === 'parent') ? 'Created Tasks' : 'Assigned Tasks'; ?></h2>
            <?php if (empty($tasks)): ?>
                <p>No tasks available.</p>
            <?php else: ?>
                <h3>Pending Tasks</h3>
                <?php if (empty($pending_tasks)): ?>
                    <p>No pending tasks.</p>
                <?php else: ?>
                    <?php foreach ($pending_tasks as $task): ?>
                        <div class="task-card<?php if (strtotime($task['due_date']) < time()) { echo ' overdue'; } ?>" data-task-id="<?php echo $task['id']; ?>">
                            <p>Title: <?php echo htmlspecialchars($task['title']); ?></p>
                            <p>Due: <?php echo htmlspecialchars($task['due_date_formatted']); ?><?php if (strtotime($task['due_date']) < time()) { echo '<span class="overdue-label">Overdue!</span>'; } ?></p>
                            <p>Points: <?php echo htmlspecialchars($task['points']); ?></p>
                            <p>Category: <?php echo htmlspecialchars($task['category']); ?></p>
                            <p>Timing Mode: <?php echo htmlspecialchars($task['timing_mode']); ?></p>
                            <?php if ($task['timing_mode'] === 'timer'): ?>
                                <p class="timer" id="timer-<?php echo $task['id']; ?>">5:00</p>
                                <button onclick="startTimer(<?php echo $task['id']; ?>, 5)">Start Timer</button>
                            <?php elseif ($task['timing_mode'] === 'suggested'): ?>
                                <p>Suggested Time: 10min (guideline)</p>
                            <?php endif; ?>
                            <?php if ($_SESSION['role'] === 'child'): ?>
                                <form method="POST" action="task.php" enctype="multipart/form-data">
                                    <input type="hidden" name="task_id" value="<?php echo $task['id']; ?>">
                                    <input type="file" name="photo_proof">
                                    <button type="submit" name="complete_task">Finish Task</button>
                                </form>
                            <?php endif; ?>
                            <?php if ($_SESSION['role'] === 'parent'): ?>
                                <div class="edit-delete">
                                    <a href="edit_task.php?id=<?php echo $task['id']; ?>">Edit</a>
                                    <a href="delete_task.php?id=<?php echo $task['id']; ?>">Delete</a>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <h3>Tasks Waiting Approval</h3>
                <?php if (empty($completed_tasks)): ?>
                    <p>No tasks waiting approval.</p>
                <?php else: ?>
                    <?php foreach ($completed_tasks as $task): ?>
                        <div class="task-card" data-task-id="<?php echo $task['id']; ?>">
                            <p>Title: <?php echo htmlspecialchars($task['title']); ?></p>
                            <p>Due: <?php echo htmlspecialchars($task['due_date_formatted']); ?></p>
                            <p>Points: <?php echo htmlspecialchars($task['points']); ?></p>
                            <p>Category: <?php echo htmlspecialchars($task['category']); ?></p>
                            <p>Timing Mode: <?php echo htmlspecialchars($task['timing_mode']); ?></p>
                            <?php if ($_SESSION['role'] === 'parent'): ?>
                                <form method="POST" action="task.php">
                                    <input type="hidden" name="task_id" value="<?php echo $task['id']; ?>">
                                    <button type="submit" name="approve_task">Approve Task</button>
                                </form>
                                <div class="edit-delete">
                                    <a href="edit_task.php?id=<?php echo $task['id']; ?>">Edit</a>
                                    <a href="delete_task.php?id=<?php echo $task['id']; ?>">Delete</a>
                                </div>
                            <?php else: ?>
                                <p class="waiting-label">Waiting for approval</p>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <h3>Approved Tasks</h3>
                <?php if (empty($approved_tasks)): ?>
                    <p>No approved tasks.</p>
                <?php else: ?>
                    <?php foreach ($approved_tasks as $task): ?>
                        <div class="task-card" data-task-id="<?php echo $task['id']; ?>">
                            <p>Title: <?php echo htmlspecialchars($task['title']); ?></p>
                            <p>Due: <?php echo htmlspecialchars($task['due_date_formatted']); ?></p>
                            <p>Points: <?php echo htmlspecialchars($task['points']); ?></p>
                            <p>Category: <?php echo htmlspecialchars($task['category']); ?></p>
                            <p>Timing Mode: <?php echo htmlspecialchars($task['timing_mode']); ?></p>
                            <p class="completed">Approved!</p>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </main>
    <footer>
        <p>Child Task and Chore App - Ver 3.3.4</p>
    </footer>
</body>
</html>