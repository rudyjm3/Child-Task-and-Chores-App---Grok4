<?php
// routine.php - Routine management
// Purpose: Allow parents to create routines from Routine Tasks pool
// Version: 3.4.7

session_start();
require_once __DIR__ . '/includes/functions.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create_routine'])) {
        // ... (Keep existing, but use addRoutineTaskToRoutine with $_POST['routine_task_ids'][])
    } elseif (isset($_POST['create_routine_task'])) {
        // New: Inline create during routine or standalone
        createRoutineTask($_SESSION['user_id'], $_POST['title'], $_POST['description'], $_POST['time_limit'], $_POST['point_value'], $_POST['category']);
        $message = "Routine Task created!";
    }
    // ... (Keep other POST handlers, revised for routine_task_id)
}

$routine_tasks = ($_SESSION['role'] === 'parent') ? getRoutineTasks($_SESSION['user_id']) : [];
$routines = getRoutines($_SESSION['user_id']);
?>
<!DOCTYPE html>
<html lang="en">
<!-- ... (Keep head with styles/scripts) -->
<body>
    <!-- ... (Header) -->
    <main>
        <?php if (isset($message)) echo "<p>$message</p>"; ?>
        <?php if ($_SESSION['role'] === 'parent'): ?>
            <div class="routine-form">
                <h2>Manage Routine Tasks</h2>
                <form method="POST" action="routine.php">  <!-- Standalone create -->
                    <label>Title:</label><input type="text" name="title" required>
                    <label>Description:</label><textarea name="description"></textarea>
                    <label>Time Limit (min):</label><input type="number" name="time_limit">
                    <label>Points:</label><input type="number" name="point_value">
                    <label>Category:</label><select name="category"><!-- Options --></select>
                    <button type="submit" name="create_routine_task">Add Routine Task</button>
                </form>

                <h3>Existing Routine Tasks</h3>
                <?php foreach ($routine_tasks as $rt): ?>
                    <div><?php echo htmlspecialchars($rt['title']); ?> <a href="?edit_rt=<?php echo $rt['id']; ?>">Edit</a> <a href="?delete_rt=<?php echo $rt['id']; ?>">Delete</a></div>
                <?php endforeach; ?>

                <h2>Create Routine</h2>
                <form method="POST" action="routine.php">
                    <!-- Child select, title, times, etc. -->
                    <label>Routine Tasks:</label>
                    <select multiple name="routine_task_ids[]">
                        <?php foreach ($routine_tasks as $rt): ?>
                            <option value="<?php echo $rt['id']; ?>"><?php echo htmlspecialchars($rt['title']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <!-- Inline add new Routine Task (JS to show form) -->
                    <button type="button" id="add-new-rt">Add New Routine Task</button>
                    <div id="new-rt-form" style="display:none;">
                        <!-- Similar fields as above, name="new_rt_title", etc. -->
                    </div>
                    <button type="submit" name="create_routine">Create</button>
                </form>
            </div>
        <?php endif; ?>
        <div class="routine-list">
            <!-- ... (Updated to show routine['tasks'] from routine_tasks) -->
        </div>
    </main>
    <!-- Footer -->
    <script>
        // JS for inline add (append fields on click)
        document.getElementById('add-new-rt').addEventListener('click', () => {
            document.getElementById('new-rt-form').style.display = 'block';
        });
    </script>
</body>
</html>