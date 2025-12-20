<?php
// task.php - Task and chore management
// Purpose: Allow parents to create tasks and children to view/complete them
// Inputs: POST data for task creation, task ID for completion
// Outputs: Task management interface
// Version: 3.12.2

session_start(); // Ensure session is started to load existing session

// Set timezone to avoid mismatches
date_default_timezone_set('America/New_York'); // Adjust to your server's timezone

require_once __DIR__ . '/includes/functions.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// Ensure display name in session for header
if (!isset($_SESSION['name'])) {
    $_SESSION['name'] = getDisplayName($_SESSION['user_id']);
}

$family_root_id = getFamilyRootId($_SESSION['user_id']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create_task'])) {
        $child_ids = array_map('intval', $_POST['child_user_ids'] ?? []);
        $child_ids = array_values(array_filter($child_ids));
        $title = filter_input(INPUT_POST, 'title', FILTER_SANITIZE_STRING);
        $description = filter_input(INPUT_POST, 'description', FILTER_SANITIZE_STRING);
        $start_date = filter_input(INPUT_POST, 'start_date', FILTER_SANITIZE_STRING);
        $due_time = filter_input(INPUT_POST, 'due_time', FILTER_SANITIZE_STRING);
        $end_date_enabled = !empty($_POST['end_date_enabled']);
        $end_date = $end_date_enabled ? filter_input(INPUT_POST, 'end_date', FILTER_SANITIZE_STRING) : null;
        if (empty($start_date)) {
            $start_date = date('Y-m-d');
        }
        $due_date = $start_date;
        if (!empty($due_time)) {
            $due_date .= ' ' . $due_time . ':00';
        }
        $points = filter_input(INPUT_POST, 'points', FILTER_VALIDATE_INT);
        $repeat = filter_input(INPUT_POST, 'recurrence', FILTER_SANITIZE_STRING);
        $recurrence = $repeat === 'daily' ? 'daily' : ($repeat === 'weekly' ? 'weekly' : '');
        $recurrence_days = null;
        if ($repeat === 'weekly') {
            $days = $_POST['recurrence_days'] ?? [];
            $days = array_values(array_filter(array_map('trim', (array) $days)));
            $recurrence_days = !empty($days) ? implode(',', $days) : null;
        }
        $category = filter_input(INPUT_POST, 'category', FILTER_SANITIZE_STRING);
        $timing_mode = filter_input(INPUT_POST, 'timing_mode', FILTER_SANITIZE_STRING);
        $timer_minutes = filter_input(INPUT_POST, 'timer_minutes', FILTER_VALIDATE_INT);
        if ($timing_mode !== 'timer') {
            $timer_minutes = null;
        }

        if (!empty($child_ids) && canCreateContent($_SESSION['user_id'])) {
            $allOk = true;
            foreach ($child_ids as $child_user_id) {
                $ok = createTask($family_root_id, $child_user_id, $title, $description, $due_date, $end_date, $points, $recurrence, $recurrence_days, $category, $timing_mode, $timer_minutes, $_SESSION['user_id']);
                if (!$ok) {
                    $allOk = false;
                }
            }
            $message = $allOk ? "Task created successfully!" : "Some tasks failed to create.";
        } else {
            $message = "Select at least one child.";
        }
    } elseif (isset($_POST['update_task']) && canCreateContent($_SESSION['user_id']) && canAddEditChild($_SESSION['user_id'])) {
        $task_id = filter_input(INPUT_POST, 'task_id', FILTER_VALIDATE_INT);
        $child_ids = array_map('intval', $_POST['child_user_ids'] ?? []);
        $child_ids = array_values(array_filter($child_ids));
        $title = filter_input(INPUT_POST, 'title', FILTER_SANITIZE_STRING);
        $description = filter_input(INPUT_POST, 'description', FILTER_SANITIZE_STRING);
        $start_date = filter_input(INPUT_POST, 'start_date', FILTER_SANITIZE_STRING);
        $due_time = filter_input(INPUT_POST, 'due_time', FILTER_SANITIZE_STRING);
        $end_date_enabled = !empty($_POST['end_date_enabled']);
        $end_date = $end_date_enabled ? filter_input(INPUT_POST, 'end_date', FILTER_SANITIZE_STRING) : null;
        if (empty($start_date)) {
            $start_date = date('Y-m-d');
        }
        $due_date = $start_date;
        if (!empty($due_time)) {
            $due_date .= ' ' . $due_time . ':00';
        }
        $points = filter_input(INPUT_POST, 'points', FILTER_VALIDATE_INT);
        $repeat = filter_input(INPUT_POST, 'recurrence', FILTER_SANITIZE_STRING);
        $recurrence = $repeat === 'daily' ? 'daily' : ($repeat === 'weekly' ? 'weekly' : '');
        $recurrence_days = null;
        if ($repeat === 'weekly') {
            $days = $_POST['recurrence_days'] ?? [];
            $days = array_values(array_filter(array_map('trim', (array) $days)));
            $recurrence_days = !empty($days) ? implode(',', $days) : null;
        }
        $category = filter_input(INPUT_POST, 'category', FILTER_SANITIZE_STRING);
        $timing_mode = filter_input(INPUT_POST, 'timing_mode', FILTER_SANITIZE_STRING);
        $timer_minutes = filter_input(INPUT_POST, 'timer_minutes', FILTER_VALIDATE_INT);
        if ($timing_mode !== 'timer') {
            $timer_minutes = null;
        }

        if ($task_id && !empty($child_ids)) {
            $primary_child_id = $child_ids[0];
            $stmt = $db->prepare("UPDATE tasks 
                                  SET child_user_id = :child_id,
                                      title = :title,
                                      description = :description,
                                      due_date = :due_date,
                                      end_date = :end_date,
                                      points = :points,
                                      recurrence = :recurrence,
                                      recurrence_days = :recurrence_days,
                                      category = :category,
                                      timing_mode = :timing_mode,
                                      timer_minutes = :timer_minutes
                                  WHERE id = :id AND parent_user_id = :parent_id AND status = 'pending'");
            $ok = $stmt->execute([
                ':child_id' => $primary_child_id,
                ':title' => $title,
                ':description' => $description,
                ':due_date' => $due_date ?: null,
                ':end_date' => $end_date,
                ':points' => $points,
                ':recurrence' => $recurrence,
                ':recurrence_days' => $recurrence_days,
                ':category' => $category,
                ':timing_mode' => $timing_mode,
                ':timer_minutes' => $timer_minutes,
                ':id' => $task_id,
                ':parent_id' => $family_root_id
            ]);
            $allOk = $ok;
            foreach (array_slice($child_ids, 1) as $child_id) {
                $cloneOk = createTask($family_root_id, $child_id, $title, $description, $due_date, $end_date, $points, $recurrence, $recurrence_days, $category, $timing_mode, $timer_minutes, $_SESSION['user_id']);
                if (!$cloneOk) {
                    $allOk = false;
                }
            }
            $message = $allOk ? "Task updated successfully!" : "Task updated with some failures.";
        } else {
            $message = "Invalid task update request.";
        }
    } elseif (isset($_POST['delete_task']) && canCreateContent($_SESSION['user_id']) && canAddEditChild($_SESSION['user_id'])) {
        $task_id = filter_input(INPUT_POST, 'task_id', FILTER_VALIDATE_INT);
        if ($task_id) {
            $stmt = $db->prepare("DELETE FROM tasks WHERE id = :id AND parent_user_id = :parent_id AND status = 'pending'");
            $ok = $stmt->execute([':id' => $task_id, ':parent_id' => $family_root_id]);
            $message = $ok ? "Task deleted successfully!" : "Failed to delete task.";
        } else {
            $message = "Invalid task delete request.";
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
    } elseif (isset($_POST['approve_task']) && isset($_SESSION['user_id']) && canCreateContent($_SESSION['user_id']) && canAddEditChild($_SESSION['user_id'])) {
        $task_id = filter_input(INPUT_POST, 'task_id', FILTER_VALIDATE_INT);
        if (approveTask($task_id)) {
            $message = "Task approved!";
        } else {
            $message = "Failed to approve task.";
        }
    }
}

$children = [];
if (canCreateContent($_SESSION['user_id'])) {
    $stmt = $db->prepare("SELECT cp.child_user_id, cp.child_name, cp.avatar 
                         FROM child_profiles cp 
                         WHERE cp.parent_user_id = :parent_id AND cp.deleted_at IS NULL");
    $stmt->execute([':parent_id' => $family_root_id]);
    $children = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($children as &$child) {
        $name = trim((string) ($child['child_name'] ?? ''));
        $parts = $name === '' ? [] : preg_split('/\s+/', $name);
        $child['first_name'] = $parts[0] ?? $name;
        $child['avatar'] = !empty($child['avatar']) ? $child['avatar'] : 'images/default-avatar.png';
    }
    unset($child);
}
$childNameById = [];
foreach ($children as $child) {
    $childNameById[(int)$child['child_user_id']] = $child['first_name'] ?? $child['child_name'];
}

$tasks = getTasks($_SESSION['user_id']);
// Format due_date for display
foreach ($tasks as &$task) {
    $task['due_date_formatted'] = !empty($task['due_date']) ? date('m/d/Y h:i A', strtotime($task['due_date'])) : 'No date set';
    if (empty($task['child_display_name'])) {
        $task['child_display_name'] = $childNameById[(int)($task['child_user_id'] ?? 0)] ?? '';
    }
}
unset($task);

// Group tasks by status for sectioned display
$pending_tasks = array_filter($tasks, function($t) { return $t['status'] === 'pending'; });
$completed_tasks = array_filter($tasks, function($t) { return $t['status'] === 'completed'; }); // Waiting approval
$approved_tasks = array_filter($tasks, function($t) { return $t['status'] === 'approved'; });

$welcome_role_label = getUserRoleLabel($_SESSION['user_id']);
if (!$welcome_role_label) {
    $fallback_role = getEffectiveRole($_SESSION['user_id']) ?: ($_SESSION['role'] ?? null);
    if ($fallback_role) {
        $welcome_role_label = ucfirst(str_replace('_', ' ', $fallback_role));
    }
}

$bodyClasses = [];
if (isset($_SESSION['role']) && $_SESSION['role'] === 'child') {
    $bodyClasses[] = 'child-theme';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Task Management</title>
      <link rel="stylesheet" href="css/main.css?v=3.12.2">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css" integrity="Evv84Mr4kqVGRNSgIGL/F/aIDqQb7xQ2vcrdIwxfjThSH8CSR7PBEakCr51Ck+w+/U6swU2Im1vVX0SVk9ABhg==" crossorigin="anonymous" referrerpolicy="no-referrer">
    <style>
        .task-form, .task-list {
            padding: 20px;
            max-width: 900px;
            margin: 0 auto;
        }
        .routine-section { background: #fff; border-radius: 10px; box-shadow: 0 2px 6px rgba(0,0,0,0.08); padding: 20px; margin-bottom: 24px; }
        .routine-section-header { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; }
        .routine-section-header h2 { margin: 0; font-size: 1.2rem; letter-spacing: 0.02em; }
        .form-grid { display: grid; gap: 16px; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); }
        .form-group { display: flex; flex-direction: column; gap: 6px; }
        .form-group label { font-weight: 600; }
        .form-group input, .form-group select, .form-group textarea { padding: 8px; border: 1px solid #ccc; border-radius: 6px; font-size: 0.95rem; }
        .form-actions { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 12px; }
        .child-select-grid { display: flex; flex-wrap: wrap; gap: 14px; }
        .child-select-card { border: none; border-radius: 50%; padding: 0; background: transparent; display: grid; justify-items: center; gap: 8px; cursor: pointer; position: relative; }
        .child-select-card input[type="checkbox"] { position: absolute; opacity: 0; width: 0; height: 0; pointer-events: none; }
        .child-select-card img { width: 52px; height: 52px; border-radius: 50%; object-fit: cover; box-shadow: 0 2px 6px rgba(0,0,0,0.15); transition: box-shadow 150ms ease, transform 150ms ease; }
        .child-select-card span { font-size: 13px; width: min-content; text-align: center; transition: color 150ms ease, text-shadow 150ms ease; }
        .child-select-card input[type="checkbox"]:checked + img { box-shadow: 0 0 0 4px rgba(100,181,246,0.8), 0 0 14px rgba(100,181,246,0.8); transform: translateY(-2px); }
        .child-select-card input[type="checkbox"]:checked + img + span { color: #0d47a1; text-shadow: 0 1px 8px rgba(100,181,246,0.8); }
        .toggle-row { display: inline-flex; align-items: center; justify-content: flex-start; }
        .toggle-row input[type="checkbox"] { width: 18px; height: 18px; }
        .toggle-field { display: flex; flex-direction: column; gap: 6px; align-items: center; text-align: center; }
        .end-date-field { align-items: center; text-align: center; }
        .end-date-field input[type="date"] { margin: 0 auto; display: block; }
        .toggle-switch { position: relative; display: inline-flex; align-items: center; }
        .toggle-switch input { position: absolute; opacity: 0; width: 0; height: 0; }
        .toggle-slider { width: 44px; height: 24px; background: #cfd8dc; border-radius: 999px; position: relative; transition: background 150ms ease; display: inline-block; }
        .toggle-slider::after { content: ''; position: absolute; top: 3px; left: 3px; width: 18px; height: 18px; background: #fff; border-radius: 50%; box-shadow: 0 1px 3px rgba(0,0,0,0.2); transition: transform 150ms ease; }
        .toggle-switch input:checked + .toggle-slider { background: #4caf50; }
        .toggle-switch input:checked + .toggle-slider::after { transform: translateX(20px); }
        .toggle-label { font-weight: 600; }
        .timer {
            font-size: 1.5em;
            color: #ff9800;
        }
        .completed {
            background-color: #e0e0e0;
            padding: 10px;
            margin: 5px 0;
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
        .button {
            padding: 10px 20px;
            margin: 5px;
            background-color: #4caf50;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
        }
        .button.secondary { background: #607d8b; }
        .button.danger { background: #e53935; }
        /* Improved task card styling for spacing and readability */
        .task-card {
            padding: 15px;
            margin-bottom: 20px;
            border: 1px solid #ddd;
            border-radius: 8px;
            background: #fff;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .task-card-header { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 8px; }
        .task-card-title { font-weight: 700; font-size: 1.2rem; color: #37474f; }
        .task-pill { background: #e3f2fd; color: #0d47a1; padding: 2px 8px; border-radius: 999px; font-size: 0.85rem; font-weight: 700; white-space: nowrap; }
        .task-meta { display: grid; gap: 4px; color: #455a64; font-size: 0.95rem; }
        .task-meta-row { display: flex; flex-wrap: wrap; gap: 10px; }
        .task-meta-label { font-weight: 600; color: #37474f; }
        .task-description { margin-top: 8px; color: #546e7a; }
        .task-section-toggle { margin: 18px 0 10px; border: 1px solid #e0e0e0; border-radius: 10px; padding: 8px 12px; background: #fff; }
        .task-section-toggle summary { cursor: pointer; font-weight: 700; color: #37474f; display: flex; align-items: center; justify-content: space-between; gap: 10px; }
        .task-count-badge { background: #ff6f61; color: #fff; border-radius: 12px; padding: 2px 8px; font-size: 0.8rem; font-weight: 700; min-width: 24px; text-align: center; }
        /* Overdue styles (role-specific colors for autism-friendliness) */
        .overdue {
            border-left: 5px solid <?php echo (isset($_SESSION['user_id']) && canCreateContent($_SESSION['user_id'])) ? '#d9534f' : '#ff9900'; ?>; /* Red for parent/family/caregiver, orange for child */
        }
        .overdue-label {
            background-color: <?php echo (isset($_SESSION['user_id']) && canCreateContent($_SESSION['user_id'])) ? '#d9534f' : '#ff9900'; ?>;
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
        .task-edit-button { margin-top: 10px; }
        .task-modal { position: fixed; inset: 0; background: rgba(0,0,0,0.45); display: none; align-items: center; justify-content: center; z-index: 4000; padding: 14px; }
        .task-modal.open { display: flex; }
        .task-modal-card { background: #fff; border-radius: 12px; max-width: 760px; width: min(760px, 100%); max-height: 85vh; overflow: hidden; box-shadow: 0 12px 32px rgba(0,0,0,0.25); display: grid; grid-template-rows: auto 1fr; }
        .task-modal-card header { display: flex; align-items: center; justify-content: space-between; padding: 12px 16px; border-bottom: 1px solid #e0e0e0; }
        .task-modal-card h2 { margin: 0; font-size: 1.1rem; }
        .task-modal-close { background: transparent; border: none; font-size: 1.3rem; cursor: pointer; color: #555; }
        .task-modal-body { padding: 12px 16px 16px; overflow-y: auto; }
        .icon-button { width: 36px; height: 36px;     border: none;
         background-color: rgba(0, 0, 0, 0.0);
         color: #9f9f9f; /*border-radius: 50%; border: 1px solid #d5def0; background: #f5f5f5; color: #757575; display: inline-flex; align-items: center; justify-content: center;*/ cursor: pointer; }
        .icon-button.danger { border: none;
         background-color: rgba(0, 0, 0, 0.0);
         color: #9f9f9f; /*background: #f5f5f5; border-color: #d5def0; color: #757575;*/ }
        .task-card-actions { margin-top: 10px; display: flex; gap: 8px; }
        .modal-actions { display: flex; flex-wrap: wrap; gap: 12px; justify-content: flex-end; }
        .timer-controls {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 8px;
        }
        .timer-button {
            padding: 10px 20px;
            background-color: #2196f3;
            color: #fff;
            border: none;
            border-radius: 5px;
            cursor: pointer;
        }
        .timer-cancel-button {
            padding: 10px 20px;
            background-color: #f44336;
            color: #fff;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            display: none;
        }
        .pause-hold-countdown {
            display: none;
            font-weight: bold;
            color: #ff5722;
            width: 100%;
        }
    .nav-links { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; justify-content: center; margin-top: 8px; }
    .nav-button { display: inline-flex; align-items: center; gap: 6px; padding: 8px 12px; background: #eef4ff; border: 1px solid #d5def0; border-radius: 8px; color: #0d47a1; font-weight: 700; text-decoration: none; }
    .nav-button:hover { background: #dce8ff; }
    </style>
    <script>
        const taskTimers = {};

        document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('.timer-button').forEach((button) => {
                const taskId = button.dataset.taskId;
                const limitMinutes = parseInt(button.dataset.limit, 10) || 5;
                const timerElement = document.getElementById(`timer-${taskId}`);
                const countdownElement = document.getElementById(`pause-countdown-${taskId}`);
                const cancelButton = document.querySelector(`.timer-cancel-button[data-task-id="${taskId}"]`);

                if (!timerElement || !cancelButton) return;

                const limitSeconds = limitMinutes * 60;
                taskTimers[taskId] = {
                    remaining: limitSeconds,
                    initial: limitSeconds,
                    intervalId: null,
                    holdIntervalId: null,
                    holdRemaining: 0,
                    isRunning: false,
                    ignoreNextClick: false,
                    activePointerId: null,
                    timerElement,
                    button,
                    countdownElement,
                    cancelButton
                };

                updateTimerDisplay(taskId);

                button.addEventListener('click', (event) => handleTimerClick(event, taskId));

                const holdStartEvents = ['pointerdown', 'touchstart', 'mousedown'];
                const holdEndEvents = ['pointerup', 'pointerleave', 'pointercancel', 'touchend', 'touchcancel', 'mouseup'];

                holdStartEvents.forEach((evt) => {
                    button.addEventListener(evt, (event) => beginHold(event, taskId), { passive: false });
                });
                holdEndEvents.forEach((evt) => {
                    button.addEventListener(evt, (event) => cancelHold(taskId, { event }));
                });
            cancelButton.addEventListener('click', () => cancelTimer(taskId));
        });

        const editButtons = document.querySelectorAll('[data-task-edit-open]');
        const deleteButtons = document.querySelectorAll('[data-task-delete-open]');
        const modal = document.querySelector('[data-task-edit-modal]');
        const modalCloses = modal ? modal.querySelectorAll('[data-task-edit-close]') : [];
        const modalForm = modal ? modal.querySelector('[data-task-edit-form]') : null;
        const deleteModal = document.querySelector('[data-task-delete-modal]');
        const deleteCloses = deleteModal ? deleteModal.querySelectorAll('[data-task-delete-close]') : [];
        const deleteForm = deleteModal ? deleteModal.querySelector('[data-task-delete-form]') : null;
        const deleteCopy = deleteModal ? deleteModal.querySelector('[data-task-delete-copy]') : null;

        const updateTimerField = (wrapper, selectEl) => {
            if (!wrapper || !selectEl) return;
            const show = selectEl.value === 'timer';
            wrapper.style.display = show ? 'block' : 'none';
            const input = wrapper.querySelector('input');
            if (input) {
                input.required = show;
            }
        };
        const updateRepeatDays = (wrapper, selectEl) => {
            if (!wrapper || !selectEl) return;
            const show = selectEl.value === 'weekly';
            wrapper.style.display = show ? 'block' : 'none';
        };
        const updateEndDate = (wrapper, toggle) => {
            if (!wrapper || !toggle) return;
            wrapper.style.display = toggle.checked ? 'block' : 'none';
        };
        const openModal = (data) => {
            if (!modal || !modalForm) return;
            modalForm.querySelector('[name="task_id"]').value = data.id;
            modalForm.querySelectorAll('[name="child_user_ids[]"]').forEach((box) => {
                box.checked = box.value === String(data.childId);
            });
            modalForm.querySelector('[name="title"]').value = data.title;
            modalForm.querySelector('[name="description"]').value = data.description || '';
            modalForm.querySelector('[name="start_date"]').value = data.startDate || '';
            modalForm.querySelector('[name="due_time"]').value = data.dueTime || '';
            modalForm.querySelector('[name="end_date"]').value = data.endDate || '';
            modalForm.querySelector('[name="end_date_enabled"]').checked = !!data.endDate;
            modalForm.querySelector('[name="points"]').value = data.points;
            modalForm.querySelector('[name="recurrence"]').value = data.recurrence || '';
            modalForm.querySelector('[name="category"]').value = data.category || 'household';
            modalForm.querySelector('[name="timing_mode"]').value = data.timingMode || 'no_limit';
            modalForm.querySelector('[name="timer_minutes"]').value = data.timerMinutes || '';
            updateTimerField(
                modalForm.querySelector('[data-timer-minutes-wrapper]'),
                modalForm.querySelector('[name="timing_mode"]')
            );
            updateRepeatDays(
                modalForm.querySelector('[data-recurrence-days-wrapper]'),
                modalForm.querySelector('[name="recurrence"]')
            );
            updateEndDate(
                modalForm.querySelector('[data-end-date-wrapper]'),
                modalForm.querySelector('[name="end_date_enabled"]')
            );
            const days = (data.recurrenceDays || '').split(',').filter(Boolean);
            modalForm.querySelectorAll('[name="recurrence_days[]"]').forEach((box) => {
                box.checked = days.includes(box.value);
            });
            modal.classList.add('open');
            document.body.classList.add('no-scroll');
        };
        const closeModal = () => {
            if (!modal) return;
            modal.classList.remove('open');
            document.body.classList.remove('no-scroll');
        };
        const openDeleteModal = (data) => {
            if (!deleteModal || !deleteForm || !deleteCopy) return;
            deleteForm.querySelector('[name="task_id"]').value = data.id;
            deleteCopy.textContent = `Are you sure you want to delete task "${data.title}" assigned to ${data.childName}?`;
            deleteModal.classList.add('open');
            document.body.classList.add('no-scroll');
        };
        const closeDeleteModal = () => {
            if (!deleteModal) return;
            deleteModal.classList.remove('open');
            document.body.classList.remove('no-scroll');
        };

        if (editButtons.length && modal) {
            editButtons.forEach((btn) => {
                btn.addEventListener('click', () => {
                    openModal({
                        id: btn.dataset.taskId,
                        childId: btn.dataset.childId,
                        title: btn.dataset.title,
                        description: btn.dataset.description,
                        startDate: btn.dataset.startDate,
                        dueTime: btn.dataset.dueTime,
                        endDate: btn.dataset.endDate,
                        points: btn.dataset.points,
                        recurrence: btn.dataset.recurrence,
                        category: btn.dataset.category,
                        timingMode: btn.dataset.timingMode,
                        timerMinutes: btn.dataset.timerMinutes,
                        recurrenceDays: btn.dataset.recurrenceDays
                    });
                });
            });
            if (modalCloses.length) {
                modalCloses.forEach((btn) => btn.addEventListener('click', closeModal));
            }
            modal.addEventListener('click', (e) => { if (e.target === modal) closeModal(); });
            document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeModal(); });
        }
        const createTimingSelect = document.querySelector('#timing_mode');
        const createTimerWrapper = document.querySelector('[data-create-timer-minutes]');
        const createRepeatSelect = document.querySelector('#recurrence');
        const createRepeatWrapper = document.querySelector('[data-create-recurrence-days]');
        const createForm = document.querySelector('form[action="task.php"]');
        const createEndToggle = document.querySelector('[data-end-date-toggle]');
        const createEndWrapper = document.querySelector('[data-create-end-date]');
        if (createTimingSelect && createTimerWrapper) {
            updateTimerField(createTimerWrapper, createTimingSelect);
            createTimingSelect.addEventListener('change', () => updateTimerField(createTimerWrapper, createTimingSelect));
        }
        if (createRepeatSelect && createRepeatWrapper) {
            updateRepeatDays(createRepeatWrapper, createRepeatSelect);
            createRepeatSelect.addEventListener('change', () => updateRepeatDays(createRepeatWrapper, createRepeatSelect));
        }
        if (createEndToggle && createEndWrapper) {
            updateEndDate(createEndWrapper, createEndToggle);
            createEndToggle.addEventListener('change', () => updateEndDate(createEndWrapper, createEndToggle));
        }
        if (createForm) {
            createForm.addEventListener('submit', (e) => {
                const checked = createForm.querySelectorAll('input[name="child_user_ids[]"]:checked');
                if (!checked.length) {
                    e.preventDefault();
                    alert('Select at least one child.');
                }
            });
        }
        if (modalForm) {
            const modalTiming = modalForm.querySelector('[name="timing_mode"]');
            const modalTimerWrapper = modalForm.querySelector('[data-timer-minutes-wrapper]');
            if (modalTiming && modalTimerWrapper) {
                modalTiming.addEventListener('change', () => updateTimerField(modalTimerWrapper, modalTiming));
            }
            const modalRepeat = modalForm.querySelector('[name="recurrence"]');
            const modalRepeatWrapper = modalForm.querySelector('[data-recurrence-days-wrapper]');
            if (modalRepeat && modalRepeatWrapper) {
                modalRepeat.addEventListener('change', () => updateRepeatDays(modalRepeatWrapper, modalRepeat));
            }
            const modalEndToggle = modalForm.querySelector('[name="end_date_enabled"]');
            const modalEndWrapper = modalForm.querySelector('[data-end-date-wrapper]');
            if (modalEndToggle && modalEndWrapper) {
                modalEndToggle.addEventListener('change', () => updateEndDate(modalEndWrapper, modalEndToggle));
            }
            modalForm.addEventListener('submit', (e) => {
                const checked = modalForm.querySelectorAll('input[name="child_user_ids[]"]:checked');
                if (!checked.length) {
                    e.preventDefault();
                    alert('Select at least one child.');
                }
            });
        }
        if (deleteButtons.length && deleteModal) {
            deleteButtons.forEach((btn) => {
                btn.addEventListener('click', () => {
                    openDeleteModal({
                        id: btn.dataset.taskId,
                        title: btn.dataset.title,
                        childName: btn.dataset.childName
                    });
                });
            });
            if (deleteCloses.length) {
                deleteCloses.forEach((btn) => btn.addEventListener('click', closeDeleteModal));
            }
            deleteModal.addEventListener('click', (e) => { if (e.target === deleteModal) closeDeleteModal(); });
            document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeDeleteModal(); });
        }
    });

        function updateTimerDisplay(taskId) {
            const state = taskTimers[taskId];
            if (!state || !state.timerElement) return;
            const minutes = Math.floor(state.remaining / 60);
            const seconds = state.remaining % 60;
            state.timerElement.textContent = `${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
        }

        function handleTimerClick(event, taskId) {
            const state = taskTimers[taskId];
            if (!state) return;

            if (state.ignoreNextClick) {
                state.ignoreNextClick = false;
                event.preventDefault();
                return;
            }

            if (state.isRunning) {
                event.preventDefault();
                return;
            }

            if (state.remaining <= 0) {
                state.remaining = state.initial;
                updateTimerDisplay(taskId);
            }

            startTimer(taskId);
        }

        function startTimer(taskId) {
            const state = taskTimers[taskId];
            if (!state || state.isRunning) return;

            state.isRunning = true;
            state.button.textContent = 'Pause Timer';
            state.cancelButton.style.display = 'none';
            hideCountdown(state);

            clearInterval(state.intervalId);
            state.intervalId = setInterval(() => {
                state.remaining -= 1;
                if (state.remaining <= 0) {
                    state.remaining = 0;
                    updateTimerDisplay(taskId);
                    clearInterval(state.intervalId);
                    state.intervalId = null;
                    state.isRunning = false;
                    state.button.textContent = 'Restart';
                    state.cancelButton.style.display = 'inline-block';
                    state.ignoreNextClick = false;
                    hideCountdown(state);
                    alert("Time's up! Try to hurry and finish up.");
                    return;
                }
                updateTimerDisplay(taskId);
            }, 1000);

            updateTimerDisplay(taskId);
        }

        function pauseTimer(taskId) {
            const state = taskTimers[taskId];
            if (!state || !state.isRunning) return;
            clearInterval(state.intervalId);
            state.intervalId = null;
            state.isRunning = false;
            state.button.textContent = 'Resume';
            state.cancelButton.style.display = 'inline-block';
            state.ignoreNextClick = true;
        }

        function cancelTimer(taskId) {
            const state = taskTimers[taskId];
            if (!state) return;
            if (state.intervalId) {
                clearInterval(state.intervalId);
                state.intervalId = null;
            }
            cancelHold(taskId);
            state.isRunning = false;
            state.remaining = state.initial;
            state.button.textContent = 'Start Timer';
            state.cancelButton.style.display = 'none';
            state.ignoreNextClick = false;
            updateTimerDisplay(taskId);
        }

        function beginHold(event, taskId) {
            const state = taskTimers[taskId];
            if (!state || !state.isRunning) return;
            if (event.type === 'mousedown' && event.button !== 0) return;
            if (state.holdIntervalId) return;

            if (typeof event.preventDefault === 'function') {
                event.preventDefault();
            }

            if (event.pointerId !== undefined && state.button.setPointerCapture) {
                try {
                    state.button.setPointerCapture(event.pointerId);
                    state.activePointerId = event.pointerId;
                } catch (error) {
                    state.activePointerId = null;
                }
            }

            state.holdRemaining = 5;
            showCountdown(state, `Hold for ${state.holdRemaining}s to pause`);

            state.holdIntervalId = setInterval(() => {
                state.holdRemaining -= 1;
                if (state.holdRemaining > 0) {
                    showCountdown(state, `Hold for ${state.holdRemaining}s to pause`);
                    return;
                }

                clearInterval(state.holdIntervalId);
                state.holdIntervalId = null;
                showCountdown(state, 'Pausing...');
                pauseTimer(taskId);
                if (state.activePointerId !== null && state.button && state.button.releasePointerCapture) {
                    try {
                        state.button.releasePointerCapture(state.activePointerId);
                    } catch (error) {
                        // ignore release failures
                    }
                }
                state.activePointerId = null;
                setTimeout(() => hideCountdown(state), 600);
            }, 1000);
        }

        function cancelHold(taskId, { event } = {}) {
            const state = taskTimers[taskId];
            if (!state) return;
            if (state.holdIntervalId) {
                clearInterval(state.holdIntervalId);
                state.holdIntervalId = null;
            }
            if (event && event.pointerId !== undefined && state.button && state.button.hasPointerCapture && state.button.hasPointerCapture(event.pointerId)) {
                try {
                    state.button.releasePointerCapture(event.pointerId);
                } catch (error) {
                    // ignore release failures
                }
            } else if (state.activePointerId !== null && state.button && state.button.releasePointerCapture) {
                try {
                    state.button.releasePointerCapture(state.activePointerId);
                } catch (error) {
                    // ignore release failures
                }
            }
            state.activePointerId = null;
            hideCountdown(state);
        }

        function showCountdown(state, message) {
            if (!state.countdownElement) return;
            state.countdownElement.style.display = 'block';
            state.countdownElement.textContent = message;
        }

        function hideCountdown(state) {
            if (!state.countdownElement) return;
            state.countdownElement.style.display = 'none';
            state.countdownElement.textContent = '';
        }
    </script>
</head>
<body<?php echo !empty($bodyClasses) ? ' class="' . implode(' ', $bodyClasses) . '"' : ''; ?>>
    <header>
        <h1>Task Management</h1>
        <p>Welcome, <?php echo htmlspecialchars($_SESSION['name'] ?? $_SESSION['username'] ?? 'Unknown User'); ?>
            <?php if ($welcome_role_label): ?>
                <span class="role-badge">(<?php echo htmlspecialchars($welcome_role_label); ?>)</span>
            <?php endif; ?>
        </p>
         <div class="nav-links">
            <a class="nav-button" href="dashboard_<?php echo canCreateContent($_SESSION['user_id']) ? 'parent' : 'child'; ?>.php">Dashboard</a>
            <a class="nav-button" href="goal.php">Goals</a>
            <a class="nav-button" href="task.php">Tasks</a>
            <a class="nav-button" href="routine.php">Routines</a>
            <a class="nav-button" href="rewards.php">Rewards</a>
            <a class="nav-button" href="profile.php?self=1">Profile</a>
            <a class="nav-button" href="logout.php">Logout</a>
         </div>
    </header>
    <main>
        <?php if (isset($message)) echo "<p>$message</p>"; ?>
        <?php if (canCreateContent($_SESSION['user_id'])): ?>
            <section class="task-form routine-section">
                <div class="routine-section-header">
                    <h2>Create Task</h2>
                </div>
                <form method="POST" action="task.php" enctype="multipart/form-data">
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Child</label>
                            <div class="child-select-grid">
                                <?php foreach ($children as $index => $child): ?>
                                    <label class="child-select-card">
                                        <input type="checkbox" name="child_user_ids[]" value="<?php echo (int) $child['child_user_id']; ?>">
                                        <img src="<?php echo htmlspecialchars($child['avatar']); ?>" alt="<?php echo htmlspecialchars($child['first_name'] ?? $child['child_name']); ?>">
                                        <span><?php echo htmlspecialchars($child['first_name'] ?? $child['child_name']); ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="title">Title</label>
                            <input type="text" id="title" name="title" required>
                        </div>
                        <div class="form-group">
                            <label for="description">Description</label>
                            <textarea id="description" name="description"></textarea>
                        </div>
                        <div class="form-group">
                            <label for="points">Points</label>
                            <input type="number" id="points" name="points" min="1" required>
                        </div>
                        <div class="form-group">
                            <label for="recurrence">Repeat</label>
                            <select id="recurrence" name="recurrence">
                                <option value="">Once</option>
                                <option value="daily">Every Day</option>
                                <option value="weekly">Specific Days</option>
                            </select>
                        </div>
                        <div class="form-group" data-create-recurrence-days>
                            <label>Specific Days</label>
                            <div>
                                <label><input type="checkbox" name="recurrence_days[]" value="Sun"> Sun</label>
                                <label><input type="checkbox" name="recurrence_days[]" value="Mon"> Mon</label>
                                <label><input type="checkbox" name="recurrence_days[]" value="Tue"> Tue</label>
                                <label><input type="checkbox" name="recurrence_days[]" value="Wed"> Wed</label>
                                <label><input type="checkbox" name="recurrence_days[]" value="Thu"> Thu</label>
                                <label><input type="checkbox" name="recurrence_days[]" value="Fri"> Fri</label>
                                <label><input type="checkbox" name="recurrence_days[]" value="Sat"> Sat</label>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="start_date">Start Date</label>
                            <input type="date" id="start_date" name="start_date" value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="form-group toggle-field">
                            <span class="toggle-label">End Date</span>
                            <label class="toggle-row">
                                <span class="toggle-switch">
                                    <input type="checkbox" name="end_date_enabled" data-end-date-toggle>
                                    <span class="toggle-slider"></span>
                                </span>
                            </label>
                        </div>
                        <div class="form-group end-date-field" data-create-end-date>
                            <label for="end_date">End Date</label>
                            <input type="date" id="end_date" name="end_date" value="">
                        </div>
                        <div class="form-group">
                            <label for="due_time">Time Due By</label>
                            <input type="time" id="due_time" name="due_time">
                        </div>
                        <div class="form-group">
                            <label for="category">Category</label>
                            <select id="category" name="category">
                                <option value="hygiene">Hygiene</option>
                                <option value="homework">Homework</option>
                                <option value="household">Household</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="timing_mode">Timing Mode</label>
                            <select id="timing_mode" name="timing_mode">
                                <option value="no_limit" selected>None</option>
                                <option value="timer">Timer</option>
                            </select>
                        </div>
                        <div class="form-group" data-create-timer-minutes>
                            <label for="timer_minutes">Timer Minutes</label>
                            <input type="number" id="timer_minutes" name="timer_minutes" min="1" value="">
                        </div>
                    </div>
                    <div class="form-actions">
                        <button type="submit" name="create_task" class="button">Create Task</button>
                    </div>
                </form>
            </section>
        <?php endif; ?>
        <div class="task-list">
            <h2><?php echo (canCreateContent($_SESSION['user_id'])) ? 'Created Tasks' : 'Assigned Tasks'; ?></h2>
            <?php if (empty($tasks)): ?>
                <p>No tasks available.</p>
            <?php else: ?>
                <h3>Pending Tasks</h3>
                <?php if (empty($pending_tasks)): ?>
                    <p>No pending tasks.</p>
                <?php else: ?>
                    <?php foreach ($pending_tasks as $task): ?>
                        <?php
                        // Debug overdue check
                        $due_time = strtotime($task['due_date']);
                        $current_time = time();
                        error_log("Task ID {$task['id']}: due_date={$task['due_date']}, due_time=$due_time, current_time=$current_time, overdue=" . ($due_time < $current_time ? 'true' : 'false'));
                        ?>
                        <div class="task-card<?php if ($due_time < $current_time) { echo ' overdue'; } ?>" data-task-id="<?php echo $task['id']; ?>">
                            <div class="task-card-header">
                                <div class="task-card-title"><?php echo htmlspecialchars($task['title']); ?></div>
                                <div class="task-pill"><?php echo (int)$task['points']; ?> pts</div>
                            </div>
                            <div class="task-meta">
                                <div class="task-meta-row">
                                    <span><span class="task-meta-label">Due:</span> <?php echo htmlspecialchars($task['due_date_formatted']); ?><?php if ($due_time < $current_time) { echo '<span class="overdue-label">Overdue!</span>'; } ?></span>
                                </div>
                                <div class="task-meta-row">
                                    <span><span class="task-meta-label">Category:</span> <?php echo htmlspecialchars($task['category']); ?></span>
                                    <span><span class="task-meta-label">Timing:</span> <?php echo htmlspecialchars($task['timing_mode']); ?></span>
                                    <span><span class="task-meta-label">Repeat:</span>
                                        <?php
                                            $repeatLabel = 'Once';
                                            if ($task['recurrence'] === 'daily') {
                                                $repeatLabel = 'Every Day';
                                            } elseif ($task['recurrence'] === 'weekly') {
                                                $days = !empty($task['recurrence_days']) ? str_replace(',', ', ', $task['recurrence_days']) : 'Specific Days';
                                                $repeatLabel = 'Specific Days (' . $days . ')';
                                            }
                                            echo htmlspecialchars($repeatLabel);
                                        ?>
                                    </span>
                                </div>
                                <?php if (!empty($task['creator_display_name'])): ?>
                                    <div class="task-meta-row">
                                        <span><span class="task-meta-label">Created by:</span> <?php echo htmlspecialchars($task['creator_display_name']); ?></span>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($task['child_display_name'])): ?>
                                    <div class="task-meta-row">
                                        <span><span class="task-meta-label">Assigned to:</span> <?php echo htmlspecialchars($task['child_display_name']); ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($task['description'])): ?>
                                <div class="task-description"><?php echo htmlspecialchars($task['description']); ?></div>
                            <?php endif; ?>
                            <?php if ($task['timing_mode'] === 'timer' && !empty($task['timer_minutes'])): ?>
                                <?php $timerMinutes = (int) $task['timer_minutes']; ?>
                                <p class="timer" id="timer-<?php echo $task['id']; ?>"><?php echo sprintf('%02d:00', $timerMinutes); ?></p>
                                <div class="timer-controls">
                                    <div class="pause-hold-countdown" id="pause-countdown-<?php echo $task['id']; ?>" aria-live="polite"></div>
                                    <button type="button" class="timer-button" data-task-id="<?php echo $task['id']; ?>" data-limit="<?php echo $timerMinutes; ?>">Start Timer</button>
                                    <button type="button" class="timer-cancel-button" data-task-id="<?php echo $task['id']; ?>">Cancel</button>
                                </div>
                            <?php endif; ?>
                                <?php if (!canCreateContent($_SESSION['user_id'])): ?>
                                <form method="POST" action="task.php" enctype="multipart/form-data">
                                    <input type="hidden" name="task_id" value="<?php echo $task['id']; ?>">
                                    <input type="file" name="photo_proof">
                                    <button type="submit" name="complete_task">Finish Task</button>
                                </form>
                            <?php endif; ?>
                            <?php if (canCreateContent($_SESSION['user_id']) && canAddEditChild($_SESSION['user_id'])): ?>
                                <div class="task-card-actions">
                                    <?php $childName = $childNameById[(int)$task['child_user_id']] ?? 'Child'; ?>
                                    <?php
                                        $dueDateValue = !empty($task['due_date']) ? date('Y-m-d', strtotime($task['due_date'])) : date('Y-m-d');
                                        $dueTimeValue = !empty($task['due_date']) ? date('H:i', strtotime($task['due_date'])) : '';
                                        $repeatValue = $task['recurrence'] === 'daily' ? 'daily' : ($task['recurrence'] === 'weekly' ? 'weekly' : '');
                                    ?>
                                    <button type="button"
                                            class="icon-button"
                                            aria-label="Edit task"
                                            data-task-edit-open
                                            data-task-id="<?php echo $task['id']; ?>"
                                            data-child-id="<?php echo (int)$task['child_user_id']; ?>"
                                            data-child-name="<?php echo htmlspecialchars($childName, ENT_QUOTES); ?>"
                                            data-title="<?php echo htmlspecialchars($task['title'], ENT_QUOTES); ?>"
                                            data-description="<?php echo htmlspecialchars($task['description'], ENT_QUOTES); ?>"
                                            data-start-date="<?php echo htmlspecialchars($dueDateValue, ENT_QUOTES); ?>"
                                            data-due-time="<?php echo htmlspecialchars($dueTimeValue, ENT_QUOTES); ?>"
                                            data-end-date="<?php echo !empty($task['end_date']) ? htmlspecialchars($task['end_date'], ENT_QUOTES) : ''; ?>"
                                            data-points="<?php echo (int)$task['points']; ?>"
                                            data-recurrence="<?php echo htmlspecialchars($repeatValue, ENT_QUOTES); ?>"
                                            data-recurrence-days="<?php echo htmlspecialchars($task['recurrence_days'] ?? '', ENT_QUOTES); ?>"
                                            data-category="<?php echo htmlspecialchars($task['category'] ?? '', ENT_QUOTES); ?>"
                                            data-timing-mode="<?php echo htmlspecialchars($task['timing_mode'] ?? '', ENT_QUOTES); ?>"
                                            data-timer-minutes="<?php echo (int)($task['timer_minutes'] ?? 0); ?>">
                                        <i class="fa-solid fa-pen"></i>
                                    </button>
                                    <button type="button"
                                            class="icon-button danger"
                                            aria-label="Delete task"
                                            data-task-delete-open
                                            data-task-id="<?php echo $task['id']; ?>"
                                            data-child-name="<?php echo htmlspecialchars($childName, ENT_QUOTES); ?>"
                                            data-title="<?php echo htmlspecialchars($task['title'], ENT_QUOTES); ?>">
                                        <i class="fa-solid fa-trash"></i>
                                    </button>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <details class="task-section-toggle" <?php echo !empty($completed_tasks) ? 'open' : ''; ?>>
                    <summary>
                        <span>Tasks Waiting Approval</span>
                        <span class="task-count-badge"><?php echo count($completed_tasks); ?></span>
                    </summary>
                    <?php if (empty($completed_tasks)): ?>
                        <p>No tasks waiting approval.</p>
                    <?php else: ?>
                        <?php foreach ($completed_tasks as $task): ?>
                        <div class="task-card" data-task-id="<?php echo $task['id']; ?>">
                            <div class="task-card-header">
                                <div class="task-card-title"><?php echo htmlspecialchars($task['title']); ?></div>
                                <div class="task-pill"><?php echo (int)$task['points']; ?> pts</div>
                            </div>
                            <div class="task-meta">
                                <div class="task-meta-row">
                                    <span><span class="task-meta-label">Due:</span> <?php echo htmlspecialchars($task['due_date_formatted']); ?></span>
                                </div>
                                <div class="task-meta-row">
                                    <span><span class="task-meta-label">Category:</span> <?php echo htmlspecialchars($task['category']); ?></span>
                                    <span><span class="task-meta-label">Timing:</span> <?php echo htmlspecialchars($task['timing_mode']); ?></span>
                                    <span><span class="task-meta-label">Repeat:</span>
                                        <?php
                                            $repeatLabel = 'Once';
                                            if ($task['recurrence'] === 'daily') {
                                                $repeatLabel = 'Every Day';
                                            } elseif ($task['recurrence'] === 'weekly') {
                                                $days = !empty($task['recurrence_days']) ? str_replace(',', ', ', $task['recurrence_days']) : 'Specific Days';
                                                $repeatLabel = 'Specific Days (' . $days . ')';
                                            }
                                            echo htmlspecialchars($repeatLabel);
                                        ?>
                                    </span>
                                </div>
                                <?php if (!empty($task['creator_display_name'])): ?>
                                    <div class="task-meta-row">
                                        <span><span class="task-meta-label">Created by:</span> <?php echo htmlspecialchars($task['creator_display_name']); ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($task['description'])): ?>
                                <div class="task-description"><?php echo htmlspecialchars($task['description']); ?></div>
                            <?php endif; ?>
                            <?php if (canCreateContent($_SESSION['user_id']) && canAddEditChild($_SESSION['user_id'])): ?>
                                <form method="POST" action="task.php">
                                    <input type="hidden" name="task_id" value="<?php echo $task['id']; ?>">
                                    <button type="submit" name="approve_task">Approve Task</button>
                                </form>
                            <?php else: ?>
                                <p class="waiting-label">Waiting for approval</p>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </details>

                <details class="task-section-toggle">
                    <summary>
                        <span>Approved Tasks</span>
                        <span class="task-count-badge"><?php echo count($approved_tasks); ?></span>
                    </summary>
                    <?php if (empty($approved_tasks)): ?>
                        <p>No approved tasks.</p>
                    <?php else: ?>
                        <?php foreach ($approved_tasks as $task): ?>
                        <div class="task-card" data-task-id="<?php echo $task['id']; ?>">
                            <div class="task-card-header">
                                <div class="task-card-title"><?php echo htmlspecialchars($task['title']); ?></div>
                                <div class="task-pill"><?php echo (int)$task['points']; ?> pts</div>
                            </div>
                            <div class="task-meta">
                                <div class="task-meta-row">
                                    <span><span class="task-meta-label">Due:</span> <?php echo htmlspecialchars($task['due_date_formatted']); ?></span>
                                </div>
                                <div class="task-meta-row">
                                    <span><span class="task-meta-label">Category:</span> <?php echo htmlspecialchars($task['category']); ?></span>
                                    <span><span class="task-meta-label">Timing:</span> <?php echo htmlspecialchars($task['timing_mode']); ?></span>
                                    <span><span class="task-meta-label">Repeat:</span>
                                        <?php
                                            $repeatLabel = 'Once';
                                            if ($task['recurrence'] === 'daily') {
                                                $repeatLabel = 'Every Day';
                                            } elseif ($task['recurrence'] === 'weekly') {
                                                $days = !empty($task['recurrence_days']) ? str_replace(',', ', ', $task['recurrence_days']) : 'Specific Days';
                                                $repeatLabel = 'Specific Days (' . $days . ')';
                                            }
                                            echo htmlspecialchars($repeatLabel);
                                        ?>
                                    </span>
                                </div>
                                <?php if (!empty($task['creator_display_name'])): ?>
                                    <div class="task-meta-row">
                                        <span><span class="task-meta-label">Created by:</span> <?php echo htmlspecialchars($task['creator_display_name']); ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($task['description'])): ?>
                                <div class="task-description"><?php echo htmlspecialchars($task['description']); ?></div>
                            <?php endif; ?>
                            <p class="completed">Approved!</p>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </details>
            <?php endif; ?>
        </div>
        <?php if (canCreateContent($_SESSION['user_id']) && canAddEditChild($_SESSION['user_id'])): ?>
            <div class="task-modal" data-task-edit-modal>
                <div class="task-modal-card" role="dialog" aria-modal="true" aria-labelledby="task-edit-title">
                    <header>
                        <h2 id="task-edit-title">Edit Task</h2>
                        <button type="button" class="task-modal-close" aria-label="Close edit task" data-task-edit-close>&times;</button>
                    </header>
                    <div class="task-modal-body">
                        <form method="POST" action="task.php" data-task-edit-form>
                            <input type="hidden" name="task_id" value="">
                            <div class="form-grid">
                                <div class="form-group">
                                    <label>Child</label>
                                    <div class="child-select-grid">
                                        <?php foreach ($children as $child): ?>
                                            <label class="child-select-card">
                                                <input type="checkbox" name="child_user_ids[]" value="<?php echo (int) $child['child_user_id']; ?>">
                                                <img src="<?php echo htmlspecialchars($child['avatar']); ?>" alt="<?php echo htmlspecialchars($child['first_name'] ?? $child['child_name']); ?>">
                                                <span><?php echo htmlspecialchars($child['first_name'] ?? $child['child_name']); ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label>Title</label>
                                    <input type="text" name="title" value="" required>
                                </div>
                                <div class="form-group">
                                    <label>Description</label>
                                    <textarea name="description"></textarea>
                                </div>
                                <div class="form-group">
                                    <label>Points</label>
                                    <input type="number" name="points" min="1" value="" required>
                                </div>
                                <div class="form-group">
                                    <label>Repeat</label>
                                    <select name="recurrence">
                                        <option value="">Once</option>
                                        <option value="daily">Every Day</option>
                                        <option value="weekly">Specific Days</option>
                                    </select>
                                </div>
                                <div class="form-group" data-recurrence-days-wrapper>
                                    <label>Specific Days</label>
                                    <div>
                                        <label><input type="checkbox" name="recurrence_days[]" value="Sun"> Sun</label>
                                        <label><input type="checkbox" name="recurrence_days[]" value="Mon"> Mon</label>
                                        <label><input type="checkbox" name="recurrence_days[]" value="Tue"> Tue</label>
                                        <label><input type="checkbox" name="recurrence_days[]" value="Wed"> Wed</label>
                                        <label><input type="checkbox" name="recurrence_days[]" value="Thu"> Thu</label>
                                        <label><input type="checkbox" name="recurrence_days[]" value="Fri"> Fri</label>
                                        <label><input type="checkbox" name="recurrence_days[]" value="Sat"> Sat</label>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label>Start Date</label>
                                    <input type="date" name="start_date" value="">
                                </div>
                                <div class="form-group toggle-field">
                                    <span class="toggle-label">End Date</span>
                                    <label class="toggle-row">
                                        <span class="toggle-switch">
                                            <input type="checkbox" name="end_date_enabled">
                                            <span class="toggle-slider"></span>
                                        </span>
                                    </label>
                                </div>
                                <div class="form-group end-date-field" data-end-date-wrapper>
                                    <label>End Date</label>
                                    <input type="date" name="end_date" value="">
                                </div>
                                <div class="form-group">
                                    <label>Time Due By</label>
                                    <input type="time" name="due_time" value="">
                                </div>
                                <div class="form-group">
                                    <label>Category</label>
                                    <select name="category">
                                        <option value="hygiene">Hygiene</option>
                                        <option value="homework">Homework</option>
                                        <option value="household">Household</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Timing Mode</label>
                                    <select name="timing_mode">
                                        <option value="no_limit" selected>None</option>
                                        <option value="timer">Timer</option>
                                    </select>
                                </div>
                                <div class="form-group" data-timer-minutes-wrapper>
                                    <label>Timer Minutes</label>
                                    <input type="number" name="timer_minutes" min="1" value="">
                                </div>
                            </div>
                            <div class="form-actions">
                                <button type="submit" name="update_task" class="button">Update Task</button>
                                <button type="button" class="button secondary" data-task-edit-close>Cancel</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <div class="task-modal" data-task-delete-modal>
                <div class="task-modal-card" role="dialog" aria-modal="true" aria-labelledby="task-delete-title">
                    <header>
                        <h2 id="task-delete-title">Delete Task</h2>
                        <button type="button" class="task-modal-close" aria-label="Close delete task" data-task-delete-close>&times;</button>
                    </header>
                    <div class="task-modal-body">
                        <p data-task-delete-copy></p>
                        <form method="POST" action="task.php" data-task-delete-form>
                            <input type="hidden" name="task_id" value="">
                            <div class="modal-actions">
                                <button type="submit" name="delete_task" class="button danger">Delete Task</button>
                                <button type="button" class="button secondary" data-task-delete-close>Cancel</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </main>
    <footer>
      <p>Child Task and Chore App - Ver 3.12.2</p>
   </footer>
</body>
</html>
