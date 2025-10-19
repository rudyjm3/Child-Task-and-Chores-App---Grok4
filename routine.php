<?php
// routine.php - Routine management
// Purpose: Allow parents to create routines from Routine Tasks pool and children to view/complete them
// Inputs: POST for create/update, routine ID for complete/delete
// Outputs: Routine management interface
// Version: 3.4.8 (Revised for Routine Tasks: selectable pool, inline add, drag-and-drop reorder)

session_start();

require_once __DIR__ . '/includes/functions.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// Ensure display name is set for header
if (!isset($_SESSION['name'])) {
    $_SESSION['name'] = getDisplayName($_SESSION['user_id']);
}

$family_root_id = getFamilyRootId($_SESSION['user_id']);

$routine_tasks = (isset($_SESSION['user_id']) && canCreateContent($_SESSION['user_id'])) ? getRoutineTasks($family_root_id) : [];
$routines = getRoutines($_SESSION['user_id']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create_routine'])) {
        $child_user_id = filter_input(INPUT_POST, 'child_user_id', FILTER_VALIDATE_INT);
        $title = filter_input(INPUT_POST, 'title', FILTER_SANITIZE_STRING);
        $start_time = filter_input(INPUT_POST, 'start_time', FILTER_SANITIZE_STRING);
        $end_time = filter_input(INPUT_POST, 'end_time', FILTER_SANITIZE_STRING);
        $recurrence = filter_input(INPUT_POST, 'recurrence', FILTER_SANITIZE_STRING);
        $bonus_points = filter_input(INPUT_POST, 'bonus_points', FILTER_VALIDATE_INT);
        $routine_task_ids = $_POST['routine_task_ids'] ?? []; // Array of selected IDs

        $routine_id = createRoutine($family_root_id, $child_user_id, $title, $start_time, $end_time, $recurrence, $bonus_points, $_SESSION['user_id']);
        if ($routine_id) {
            foreach ($routine_task_ids as $order => $routine_task_id) {
                addRoutineTaskToRoutine($routine_id, $routine_task_id, $order + 1);
            }
            $message = "Routine created successfully!";
            $routines = getRoutines($_SESSION['user_id']); // Refresh
        } else {
            $message = "Failed to create routine.";
        }
    } elseif (isset($_POST['create_routine_task'])) {
        $title = filter_input(INPUT_POST, 'title', FILTER_SANITIZE_STRING);
        $description = filter_input(INPUT_POST, 'description', FILTER_SANITIZE_STRING);
        $time_limit = filter_input(INPUT_POST, 'time_limit', FILTER_VALIDATE_INT);
        $point_value = filter_input(INPUT_POST, 'point_value', FILTER_VALIDATE_INT);
        $category = filter_input(INPUT_POST, 'category', FILTER_SANITIZE_STRING);
        if (createRoutineTask($family_root_id, $title, $description, $time_limit, $point_value, $category, null, null, $_SESSION['user_id'])) {
            $message = "Routine Task created!";
            $routine_tasks = getRoutineTasks($family_root_id); // Refresh
        } else {
            $message = "Failed to create Routine Task.";
        }
    } elseif (isset($_POST['update_routine'])) {
        $routine_id = filter_input(INPUT_POST, 'routine_id', FILTER_VALIDATE_INT);
        $title = filter_input(INPUT_POST, 'title', FILTER_SANITIZE_STRING);
        $start_time = filter_input(INPUT_POST, 'start_time', FILTER_SANITIZE_STRING);
        $end_time = filter_input(INPUT_POST, 'end_time', FILTER_SANITIZE_STRING);
        $recurrence = filter_input(INPUT_POST, 'recurrence', FILTER_SANITIZE_STRING);
        $bonus_points = filter_input(INPUT_POST, 'bonus_points', FILTER_VALIDATE_INT);
        $routine_task_orders = $_POST['routine_task_orders'] ?? []; // array(routine_task_id => order)

        if (updateRoutine($routine_id, $title, $start_time, $end_time, $recurrence, $bonus_points, $family_root_id)) {
            reorderRoutineTasks($routine_id, $routine_task_orders);
            $message = "Routine updated successfully!";
            $routines = getRoutines($_SESSION['user_id']); // Refresh
        } else {
            $message = "Failed to update routine.";
        }
    } elseif (isset($_POST['complete_routine'])) {
        $routine_id = filter_input(INPUT_POST, 'routine_id', FILTER_VALIDATE_INT);
        $bonus = completeRoutine($routine_id, $_SESSION['user_id']);
        if ($bonus !== false) {
            $message = "Routine completed! Bonus points awarded: $bonus";
            $routines = getRoutines($_SESSION['user_id']); // Refresh
        } else {
            $message = "Failed to complete routine (ensure all tasks are approved).";
        }
    } elseif (isset($_POST['delete_routine']) && isset($_SESSION['user_id']) && canCreateContent($_SESSION['user_id'])) {
        $routine_id = filter_input(INPUT_POST, 'routine_id', FILTER_VALIDATE_INT);
        if (deleteRoutine($routine_id, $family_root_id)) {
            $message = "Routine deleted!";
            $routines = getRoutines($_SESSION['user_id']); // Refresh
        } else {
            $message = "Failed to delete routine.";
        }
    } elseif (isset($_POST['delete_routine_task']) && isset($_SESSION['user_id']) && canCreateContent($_SESSION['user_id'])) {
        $routine_task_id = filter_input(INPUT_POST, 'routine_task_id', FILTER_VALIDATE_INT);
        if (deleteRoutineTask($routine_task_id, $family_root_id)) {
            $message = "Routine Task deleted!";
            $routine_tasks = getRoutineTasks($family_root_id); // Refresh
        } else {
            $message = "Failed to delete Routine Task.";
        }
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
    <title>Routine Management</title>
    <link rel="stylesheet" href="css/main.css">
    <style>
        .routine-form, .routine-list { padding: 20px; max-width: 800px; margin: 0 auto; }
        .routine-card { padding: 15px; margin-bottom: 20px; border: 1px solid #ddd; border-radius: 8px; background: #f9f9f9; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .checklist { list-style: none; padding: 0; }
        .checklist li { margin-bottom: 10px; display: flex; align-items: center; background: #e8f5e8; padding: 10px; border-radius: 5px; }
        .sortable { cursor: move; }
        .overall-timer { font-size: 1.5em; color: #ff9900; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; }
        .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 8px; }
        /* Kid-Friendly for Child View */
        .routine-card.child-view { background: linear-gradient(135deg, #e3f2fd, #f3e5f5); }
        .routine-card.child-view li { background: #fff9c4; border-left: 4px solid #ff9800; }
        .role-badge {
            background: #4caf50;
            color: #fff;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 0.9em;
            margin-left: 8px;
            display: inline-block;
        }
        .button { padding: 10px 20px; margin: 5px; background-color: #4caf50; color: white; border: none; border-radius: 5px; cursor: pointer; font-size: 16px; min-height: 44px; }
        .start-next-button { background-color: #2196f3; }
        /* Mobile Responsive */
        @media (max-width: 768px) { .routine-form { padding: 10px; } .checklist li { flex-direction: column; } }
    </style>
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.js"></script>
    <script>
        // JS for drag-and-drop reorder (parent view)
        document.addEventListener('DOMContentLoaded', function() {
            <?php if (isset($_SESSION['user_id']) && canCreateContent($_SESSION['user_id'])): ?>
                <?php foreach ($routines as $routine): ?>
                    new Sortable(document.getElementById('checklist-<?php echo $routine['id']; ?>'), {
                        animation: 150,
                        onEnd: function(evt) {
                            // Update order via AJAX or form submit
                            const order = Array.from(evt.from.children).map(li => li.dataset.routineTaskId);
                            const formData = new FormData();
                            formData.append('routine_id', <?php echo $routine['id']; ?>);
                            formData.append('routine_task_orders', JSON.stringify(order));
                            fetch('routine.php', { method: 'POST', body: formData });
                        }
                    });
                <?php endforeach; ?>
            <?php endif; ?>
        });

        // JS for routine progression (child view)
        let currentTaskIndex = 0;
        let routineTimerInterval;
        function startRoutine(routineId) {
            const routine = <?php echo json_encode($routines); ?>[0]; // Assume single routine for simplicity
            currentTaskIndex = 0;
            const tasks = routine.tasks;
            if (tasks.length > 0) {
                displayTask(tasks[currentTaskIndex]);
                startRoutineTimer(routineId, (strtotime(routine.end_time) - strtotime(routine.start_time)) / 60);
            }
        }

        function displayTask(task) {
            document.getElementById('current-task-title').textContent = task.title;
            document.getElementById('current-task-timer').textContent = task.time_limit + ':00';
            // Start task timer
            let time = task.time_limit * 60;
            const interval = setInterval(() => {
                let minutes = Math.floor(time / 60);
                let seconds = time % 60;
                document.getElementById('current-task-timer').textContent = `${minutes}:${seconds < 10 ? '0' : ''}${seconds}`;
                if (time <= 0) {
                    clearInterval(interval);
                    alert("Time's up! Try to hurry and finish up " + task.title);
                }
                time--;
            }, 1000);
        }

        function startRoutineTimer(routineId, durationMinutes) {
            let time = durationMinutes * 60;
            const timerElement = document.getElementById('routine-timer');
            timerElement.textContent = `${durationMinutes}:00`;
            routineTimerInterval = setInterval(() => {
                let minutes = Math.floor(time / 60);
                let seconds = time % 60;
                timerElement.textContent = `${minutes}:${seconds < 10 ? '0' : ''}${seconds}`;
                if (time <= 0) {
                    clearInterval(routineTimerInterval);
                    alert("Routine time is up!");
                }
                time--;
            }, 1000);
        }

        function nextTask() {
            currentTaskIndex++;
            if (currentTaskIndex < tasks.length) {
                displayTask(tasks[currentTaskIndex]);
            } else {
                completeRoutine(routineId);
            }
        }
    </script>
</head>
<body>
    <header>
      <h1>Routine Management</h1>
      <p>Welcome, <?php echo htmlspecialchars($_SESSION['name'] ?? $_SESSION['username'] ?? 'Unknown User'); ?>
         <?php if ($welcome_role_label): ?>
            <span class="role-badge">(<?php echo htmlspecialchars($welcome_role_label); ?>)</span>
         <?php endif; ?>
      </p>
      <a href="dashboard_<?php echo $_SESSION['role']; ?>.php">Dashboard</a> | <a href="goal.php">Goals</a> | <a href="task.php">Tasks</a> | <a href="profile.php?self=1">Profile</a> | <a href="logout.php">Logout</a>
    </header>
    <main>
        <?php if (isset($message)) echo "<p>$message</p>"; ?>
        <?php if (isset($_SESSION['user_id']) && canCreateContent($_SESSION['user_id'])): ?>
            <div class="routine-form">
                <h2>Create Routine</h2>
                <form method="POST" action="routine.php">
                    <div class="form-group">
                        <label for="child_user_id">Child:</label>
                        <select id="child_user_id" name="child_user_id" required>
                            <?php
                            $stmt = $db->prepare("SELECT cp.child_user_id, cp.child_name 
                                             FROM child_profiles cp 
                                             WHERE cp.parent_user_id = :parent_id");
                            $stmt->execute([':parent_id' => $family_root_id]);
                            $children = $stmt->fetchAll(PDO::FETCH_ASSOC);
                            foreach ($children as $child): ?>
                                <option value="<?php echo $child['child_user_id']; ?>">
                                    <?php echo htmlspecialchars($child['child_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="title">Title:</label>
                        <input type="text" id="title" name="title" required>
                    </div>
                    <div class="form-group">
                        <label for="start_time">Start Time:</label>
                        <input type="time" id="start_time" name="start_time" required>
                    </div>
                    <div class="form-group">
                        <label for="end_time">End Time:</label>
                        <input type="time" id="end_time" name="end_time" required>
                    </div>
                    <div class="form-group">
                        <label for="bonus_points">Bonus Points:</label>
                        <input type="number" id="bonus_points" name="bonus_points" min="0" value="0" required>
                    </div>
                    <div class="form-group">
                        <label for="recurrence">Recurrence:</label>
                        <select id="recurrence" name="recurrence">
                            <option value="">None</option>
                            <option value="daily">Daily</option>
                            <option value="weekly">Weekly</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Routine Tasks (select to add):</label>
                        <select multiple id="routine_task_ids" name="routine_task_ids[]" size="8">
                            <?php foreach ($routine_tasks as $rt): ?>
                                <option value="<?php echo $rt['id']; ?>"><?php echo htmlspecialchars($rt['title']); ?> (<?php echo $rt['time_limit']; ?>min, <?php echo $rt['category']; ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="button" id="add-new-rt">Add New Routine Task</button>
                    <div id="new-rt-form" style="display:none;">
                        <div class="form-group">
                            <label for="new_title">New Title:</label>
                            <input type="text" id="new_title" name="title" required>
                        </div>
                        <div class="form-group">
                            <label for="new_description">Description:</label>
                            <textarea id="new_description" name="description"></textarea>
                        </div>
                        <div class="form-group">
                            <label for="new_time_limit">Time Limit (min):</label>
                            <input type="number" id="new_time_limit" name="time_limit" min="1">
                        </div>
                        <div class="form-group">
                            <label for="new_point_value">Point Value:</label>
                            <input type="number" id="new_point_value" name="point_value" min="0">
                        </div>
                        <div class="form-group">
                            <label for="new_category">Category:</label>
                            <select id="new_category" name="category">
                                <option value="hygiene">Hygiene</option>
                                <option value="homework">Homework</option>
                                <option value="household">Household</option>
                            </select>
                        </div>
                        <button type="submit" name="create_routine_task">Add New Task</button>
                    </div>
                    <button type="submit" name="create_routine" class="button">Create Routine</button>
                </form>
            </div>
        <?php endif; ?>
        <div class="routine-list">
            <h2><?php echo ($_SESSION['role'] === 'parent') ? 'Created Routines' : 'Assigned Routines'; ?></h2>
            <?php if (empty($routines)): ?>
                <p>No routines available.</p>
            <?php else: ?>
                <?php foreach ($routines as $routine): ?>
                    <div class="routine-card <?php echo ($_SESSION['role'] === 'child') ? 'child-view' : ''; ?>">
                        <p>Title: <?php echo htmlspecialchars($routine['title']); ?></p>
                        <p>Timeframe: <?php echo date('h:i A', strtotime($routine['start_time'])) . ' - ' . date('h:i A', strtotime($routine['end_time'])); ?></p>
                        <p>Bonus Points: <?php echo $routine['bonus_points']; ?></p>
                        <p>Recurrence: <?php echo htmlspecialchars($routine['recurrence'] ?: 'None'); ?></p>
                        <h4>Tasks:</h4>
                        <ul class="checklist" id="checklist-<?php echo $routine['id']; ?>" <?php if ($_SESSION['role'] === 'parent') echo 'class="sortable"'; ?>>
                            <?php foreach ($routine['tasks'] as $task): ?>
                                <li data-routine-task-id="<?php echo $task['id']; ?>" <?php if ($_SESSION['role'] === 'parent') echo 'draggable="true"'; ?>>
                                    <?php if ($_SESSION['role'] === 'child'): ?>
                                        <input type="checkbox" <?php if ($task['status'] === 'approved') echo 'checked'; ?> disabled>
                                    <?php endif; ?>
                                    <?php echo htmlspecialchars($task['title']); ?> (<?php echo $task['time_limit']; ?>min, Status: <?php echo $task['status']; ?>)
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <?php if ($_SESSION['role'] === 'child'): ?>
                            <p class="overall-timer" id="routine-timer">Duration</p>
                            <button onclick="startRoutine(<?php echo $routine['id']; ?>)" class="button start-next-button">Start Routine</button>
                            <div id="current-task">
                                <h3 id="current-task-title"></h3>
                                <p id="current-task-timer"></p>
                                <button onclick="nextTask()" class="button start-next-button">Finish Task</button>
                            </div>
                            <form method="POST" action="routine.php">
                                <input type="hidden" name="routine_id" value="<?php echo $routine['id']; ?>">
                                <button type="submit" name="complete_routine" class="button">Complete Routine</button>
                            </form>
                        <?php endif; ?>
                        <?php if (isset($_SESSION['user_id']) && canCreateContent($_SESSION['user_id'])): ?>
                            <form method="POST" action="routine.php">
                                <input type="hidden" name="routine_id" value="<?php echo $routine['id']; ?>">
                                <input type="text" name="title" value="<?php echo htmlspecialchars($routine['title']); ?>" placeholder="New Title">
                                <input type="time" name="start_time" value="<?php echo $routine['start_time']; ?>">
                                <input type="time" name="end_time" value="<?php echo $routine['end_time']; ?>">
                                <select name="recurrence">
                                    <option value="" <?php if (empty($routine['recurrence'])) echo 'selected'; ?>>None</option>
                                    <option value="daily" <?php if ($routine['recurrence'] === 'daily') echo 'selected'; ?>>Daily</option>
                                    <option value="weekly" <?php if ($routine['recurrence'] === 'weekly') echo 'selected'; ?>>Weekly</option>
                                </select>
                                <input type="number" name="bonus_points" value="<?php echo $routine['bonus_points']; ?>" min="0">
                                <button type="submit" name="update_routine" class="button">Update Routine</button>
                            </form>
                            <form method="POST" action="routine.php">
                                <input type="hidden" name="routine_id" value="<?php echo $routine['id']; ?>">
                                <button type="submit" name="delete_routine" class="button" style="background: #f44336;" onclick="return confirm('Delete this routine?')">Delete Routine</button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </main>
    <footer>
      <p>Child Task and Chore App - Ver 3.10.14</p>
    </footer>
</body>
</html>
