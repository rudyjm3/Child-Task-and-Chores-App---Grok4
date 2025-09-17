<?php
// routine.php - Routine management
// Purpose: Allow parents to create routines (groups of tasks) and children to view/complete them
// Inputs: POST for create/update, routine ID for complete/delete
// Outputs: Routine management interface

session_start();

require_once __DIR__ . '/includes/functions.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create_routine'])) {
        $child_user_id = filter_input(INPUT_POST, 'child_user_id', FILTER_VALIDATE_INT);
        $title = filter_input(INPUT_POST, 'title', FILTER_SANITIZE_STRING);
        $start_time = filter_input(INPUT_POST, 'start_time', FILTER_SANITIZE_STRING);
        $end_time = filter_input(INPUT_POST, 'end_time', FILTER_SANITIZE_STRING);
        $recurrence = filter_input(INPUT_POST, 'recurrence', FILTER_SANITIZE_STRING);
        $bonus_points = filter_input(INPUT_POST, 'bonus_points', FILTER_VALIDATE_INT);
        $task_ids = $_POST['task_ids'] ?? []; // Array of selected task IDs

        $routine_id = createRoutine($_SESSION['user_id'], $child_user_id, $title, $start_time, $end_time, $recurrence, $bonus_points);
        if ($routine_id) {
            foreach ($task_ids as $order => $task_id) {
                addTaskToRoutine($routine_id, $task_id, $order + 1); // Order starts from 1
            }
            $message = "Routine created successfully!";
        } else {
            $message = "Failed to create routine.";
        }
    } elseif (isset($_POST['update_routine'])) {
        $routine_id = filter_input(INPUT_POST, 'routine_id', FILTER_VALIDATE_INT);
        $title = filter_input(INPUT_POST, 'title', FILTER_SANITIZE_STRING);
        $start_time = filter_input(INPUT_POST, 'start_time', FILTER_SANITIZE_STRING);
        $end_time = filter_input(INPUT_POST, 'end_time', FILTER_SANITIZE_STRING);
        $recurrence = filter_input(INPUT_POST, 'recurrence', FILTER_SANITIZE_STRING);
        $bonus_points = filter_input(INPUT_POST, 'bonus_points', FILTER_VALIDATE_INT);
        $task_orders = $_POST['task_orders'] ?? []; // array(task_id => order)

        if (updateRoutine($routine_id, $title, $start_time, $end_time, $recurrence, $bonus_points, $_SESSION['user_id'])) {
            // Update orders (assume tasks already added; for simplicity, no remove here - add if needed)
            reorderRoutineTasks($routine_id, $task_orders);
            $message = "Routine updated successfully!";
        } else {
            $message = "Failed to update routine.";
        }
    } elseif (isset($_POST['complete_routine'])) {
        $routine_id = filter_input(INPUT_POST, 'routine_id', FILTER_VALIDATE_INT);
        $bonus = completeRoutine($routine_id, $_SESSION['user_id']);
        if ($bonus !== false) {
            $message = "Routine completed! Bonus points awarded: $bonus";
        } else {
            $message = "Failed to complete routine (ensure all tasks are approved).";
        }
    } elseif (isset($_POST['delete_routine']) && $_SESSION['role'] === 'parent') {
        $routine_id = filter_input(INPUT_POST, 'routine_id', FILTER_VALIDATE_INT);
        if (deleteRoutine($routine_id, $_SESSION['user_id'])) {
            $message = "Routine deleted!";
        } else {
            $message = "Failed to delete routine.";
        }
    }
}

$routines = getRoutines($_SESSION['user_id']);
// For parent, fetch available tasks for adding to routines
if ($_SESSION['role'] === 'parent') {
    $available_tasks = getTasks($_SESSION['user_id']); // All tasks, but could filter unassigned if needed
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Routine Management</title>
    <link rel="stylesheet" href="css/main.css">
    <style>
        /* Similar to task.php, with checklist styles */
        .routine-form, .routine-list {
            padding: 20px;
            max-width: 800px;
            margin: 0 auto;
        }
        .routine-card {
            padding: 15px;
            margin-bottom: 20px;
            border: 1px solid #ddd;
            border-radius: 8px;
            background: #f9f9f9;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .checklist {
            list-style: none;
            padding: 0;
        }
        .checklist li {
            margin-bottom: 10px;
        }
        .sortable {
            cursor: move;
        }
        .overall-timer {
            font-size: 1.5em;
            color: #ff9900; /* Gentle orange for autism-friendliness */
        }
    </style>
    <script>
        // Store active timers
        const activeTimers = {};

        // Vanilla JS for drag-and-drop sortable list
        function makeSortable(listId) {
            const list = document.getElementById(listId);
            list.addEventListener('dragstart', e => {
                e.dataTransfer.setData('text/plain', e.target.dataset.taskId);
                e.target.classList.add('dragging');
            });
            list.addEventListener('dragend', e => e.target.classList.remove('dragging'));
            list.addEventListener('dragover', e => e.preventDefault());
            list.addEventListener('drop', e => {
                e.preventDefault();
                const draggedId = e.dataTransfer.getData('text/plain');
                const dragged = list.querySelector(`[data-task-id="${draggedId}"]`);
                const dropTarget = e.target.closest('li');
                if (dragged && dropTarget && dragged !== dropTarget) {
                    const refNode = (dropTarget.nextSibling === dragged) ? dropTarget.nextSibling : dropTarget;
                    list.insertBefore(dragged, refNode);
                }
            });
        }

        // Start overall routine timer (duration from start to end)
        function startRoutineTimer(routineId, durationMinutes) {
            // Clear any existing timer for this routine
            if (activeTimers[routineId]) {
                clearInterval(activeTimers[routineId]);
                delete activeTimers[routineId];
            }

            let time = durationMinutes * 60;
            const timerElement = document.getElementById(`routine-timer-${routineId}`);
            timerElement.textContent = `${durationMinutes}:00`; // Reset display to initial

            const interval = setInterval(() => {
                let minutes = Math.floor(time / 60);
                let seconds = time % 60;
                timerElement.textContent = `${minutes}:${seconds < 10 ? '0' : ''}${seconds}`;
                if (time <= 0) {
                    clearInterval(interval);
                    delete activeTimers[routineId];
                    alert("Routine time is up!");
                }
                time--;
            }, 1000);
            activeTimers[routineId] = interval;
        }
    </script>
</head>
<body>
    <header>
      <h1>Routine Management</h1>
      <p>Welcome, <?php echo htmlspecialchars($_SESSION['username'] ?? 'Unknown User'); ?> (<?php echo htmlspecialchars($_SESSION['role']); ?>)</p>
      <a href="dashboard_<?php echo $_SESSION['role']; ?>.php">Dashboard</a> | <a href="goal.php">Goals</a> | <a href="task.php">Tasks</a> | <a href="profile.php">Profile</a> | <a href="logout.php">Logout</a>
    </header>
    <main>
        <?php if (isset($message)) echo "<p>$message</p>"; ?>
        <?php if ($_SESSION['role'] === 'parent'): ?>
            <div class="routine-form">
                <h2>Create Routine</h2>
                <form method="POST" action="routine.php">
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
                    <label for="start_time">Start Time:</label>
                    <input type="time" id="start_time" name="start_time" required>
                    <label for="end_time">End Time:</label>
                    <input type="time" id="end_time" name="end_time" required>
                    <label for="bonus_points">Bonus Points:</label>
                    <input type="number" id="bonus_points" name="bonus_points" min="0" value="0" required>
                    <label for="recurrence">Recurrence:</label>
                    <select id="recurrence" name="recurrence">
                        <option value="">None</option>
                        <option value="daily">Daily</option>
                        <option value="weekly">Weekly</option>
                    </select>
                    <label>Tasks (select to add):</label>
                    <select multiple id="task_ids" name="task_ids[]">
                        <?php foreach ($available_tasks as $task): ?>
                            <option value="<?php echo $task['id']; ?>"><?php echo htmlspecialchars($task['title']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" name="create_routine">Create Routine</button>
                </form>
            </div>
        <?php endif; ?>
        <div class="routine-list">
            <h2><?php echo ($_SESSION['role'] === 'parent') ? 'Created Routines' : 'Assigned Routines'; ?></h2>
            <?php if (empty($routines)): ?>
                <p>No routines available.</p>
            <?php else: ?>
                <?php foreach ($routines as $routine): ?>
                    <div class="routine-card">
                        <p>Title: <?php echo htmlspecialchars($routine['title']); ?></p>
                        <p>Timeframe: <?php echo date('h:i A', strtotime($routine['start_time'])) . ' - ' . date('h:i A', strtotime($routine['end_time'])); ?></p>
                        <p>Bonus Points: <?php echo $routine['bonus_points']; ?></p>
                        <p>Recurrence: <?php echo htmlspecialchars($routine['recurrence'] ?: 'None'); ?></p>
                        <h4>Tasks:</h4>
                        <ul class="checklist" id="checklist-<?php echo $routine['id']; ?>" <?php if ($_SESSION['role'] === 'parent') echo 'class="sortable"'; ?>>
                            <?php foreach ($routine['tasks'] as $task): ?>
                                <li data-task-id="<?php echo $task['id']; ?>" <?php if ($_SESSION['role'] === 'parent') echo 'draggable="true" class="sortable"'; ?>>
                                    <?php if ($_SESSION['role'] === 'child'): ?>
                                        <input type="checkbox" disabled <?php if ($task['status'] === 'approved') echo 'checked'; ?>>
                                    <?php endif; ?>
                                    <?php echo htmlspecialchars($task['title']); ?> (Status: <?php echo $task['status']; ?>)
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <?php if ($_SESSION['role'] === 'child'): ?>
                            <p class="overall-timer" id="routine-timer-<?php echo $routine['id']; ?>">Duration</p>
                            <button onclick="startRoutineTimer(<?php echo $routine['id']; ?>, <?php echo (strtotime($routine['end_time']) - strtotime($routine['start_time'])) / 60; ?>)">Start Routine Timer</button>
                            <form method="POST" action="routine.php">
                                <input type="hidden" name="routine_id" value="<?php echo $routine['id']; ?>">
                                <button type="submit" name="complete_routine">Complete Routine</button>
                            </form>
                        <?php endif; ?>
                        <?php if ($_SESSION['role'] === 'parent'): ?>
                            <!-- Edit form (simplified; expand for full edit) -->
                            <form method="POST" action="routine.php">
                                <input type="hidden" name="routine_id" value="<?php echo $routine['id']; ?>">
                                <!-- Add fields for edit similar to create -->
                                <button type="submit" name="update_routine">Update Routine</button>
                            </form>
                            <form method="POST" action="routine.php">
                                <input type="hidden" name="routine_id" value="<?php echo $routine['id']; ?>">
                                <button type="submit" name="delete_routine">Delete Routine</button>
                            </form>
                            <script>makeSortable('checklist-<?php echo $routine['id']; ?>');</script>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </main>
    <footer>
      <p>Child Task and Chore App - Ver 3.4.6</p>
    </footer>
</body>
</html>