<?php
// routine.php - Routine management (Phase 5 upgrade)
// Provides parent routine builder with validation, adaptive timers for children, and overtime tracking.

session_start();

require_once __DIR__ . '/includes/functions.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

if (!isset($_SESSION['name'])) {
    $_SESSION['name'] = getDisplayName($_SESSION['user_id']);
}

$family_root_id = getFamilyRootId($_SESSION['user_id']);
$isParentContext = canCreateContent($_SESSION['user_id']);

$subTimerLabelOptions = [
    'hurry_goal' => 'Hurry Goal: Finish in [time] to end routine on time!',
    'adjusted_time' => 'Adjusted Time: [time] left due to extra on [previous task].',
    'routine_target' => 'Routine Target: Complete by [time] (buffer low).',
    'quick_finish' => 'Quick Finish: [time] to stay on schedule after [task].',
    'new_limit' => 'New Limit: [time] because of overrun on [task name].'
];

$routinePreferences = getRoutinePreferences($family_root_id);

$messages = [];
$createRoutineState = ['tasks' => []];
$editRoutineStates = [];
$createFormHasErrors = false;
$editFormErrors = [];
$editFieldOverrides = [];

function routineChildBelongsToFamily(int $child_user_id, int $family_root_id): bool {
    global $db;
    $stmt = $db->prepare("SELECT 1 FROM child_profiles WHERE child_user_id = :child_id AND parent_user_id = :parent_id LIMIT 1");
    $stmt->execute([':child_id' => $child_user_id, ':parent_id' => $family_root_id]);
    return (bool) $stmt->fetchColumn();
}

function routineBelongsToParent(int $routine_id, int $family_root_id): bool {
    global $db;
    $stmt = $db->prepare("SELECT parent_user_id FROM routines WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $routine_id]);
    $ownerId = $stmt->fetchColumn();
    return (int) $ownerId === $family_root_id;
}

function normalizeRoutineStructure(?string $rawStructure, int $family_root_id, array &$errors): array {
    if (!$rawStructure) {
        $errors[] = 'Add at least one routine task.';
        return [[], [], ['tasks' => []]];
    }
    $decoded = json_decode($rawStructure, true);
    if (!is_array($decoded) || !isset($decoded['tasks']) || !is_array($decoded['tasks'])) {
        $errors[] = 'Routine tasks could not be parsed. Please re-add them.';
        return [[], [], ['tasks' => []]];
    }

    $taskEntries = $decoded['tasks'];
    $taskIds = [];
    foreach ($taskEntries as $entry) {
        $taskId = isset($entry['id']) ? (int) $entry['id'] : 0;
        if ($taskId > 0) {
            $taskIds[] = $taskId;
        }
    }
    $taskIds = array_values(array_unique($taskIds));
    $taskMap = getRoutineTasksByIds($family_root_id, $taskIds);
    if (count($taskMap) !== count($taskIds)) {
        $errors[] = 'One or more selected routine tasks are no longer available.';
    }

    $normalized = [];
    $seen = [];
    $sequence = 1;
    foreach ($taskEntries as $entry) {
        $taskId = isset($entry['id']) ? (int) $entry['id'] : 0;
        if ($taskId <= 0 || !isset($taskMap[$taskId])) {
            continue;
        }
        if (isset($seen[$taskId])) {
            $errors[] = 'Routine tasks must be unique within a routine.';
            continue;
        }
        $dependencyId = null;
        if (isset($entry['dependency_id']) && $entry['dependency_id'] !== '' && $entry['dependency_id'] !== null) {
            $candidate = (int) $entry['dependency_id'];
            if (isset($seen[$candidate])) {
                $dependencyId = $candidate;
            } else {
                $errors[] = 'Dependencies must reference a task that appears earlier in the sequence.';
            }
        }
        $normalized[] = [
            'id' => $taskId,
            'sequence_order' => $sequence,
            'dependency_id' => $dependencyId,
            'time_limit' => (int) ($taskMap[$taskId]['time_limit'] ?? 0)
        ];
        $seen[$taskId] = true;
        $sequence++;
    }

    if (empty($normalized)) {
        $errors[] = 'Add at least one routine task.';
    }

    $sanitizedStructure = [
        'tasks' => array_map(static function ($task) {
            return [
                'id' => (int) $task['id'],
                'dependency_id' => $task['dependency_id'] !== null ? (int) $task['dependency_id'] : null
            ];
        }, $normalized)
    ];

    return [$normalized, $taskMap, $sanitizedStructure];
}

function validateRoutineTimeframe(?string $start_time, ?string $end_time, array &$errors): ?int {
    $duration = calculateRoutineDurationMinutes($start_time, $end_time);
    if ($duration === null) {
        $errors[] = 'Provide a valid start and end time for the routine.';
    }
    return $duration;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'log_overtime') {
    header('Content-Type: application/json');
    if (!isset($_SESSION['user_id'])) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Not authenticated.']);
        exit;
    }
    if (getEffectiveRole($_SESSION['user_id']) !== 'child') {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Only children can log overtime.']);
        exit;
    }
    $payload = json_decode($_POST['overtime_payload'] ?? '[]', true);
    if (!is_array($payload)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Malformed payload.']);
        exit;
    }
    $logged = 0;
    foreach ($payload as $entry) {
        $routineId = isset($entry['routine_id']) ? (int) $entry['routine_id'] : 0;
        $taskId = isset($entry['routine_task_id']) ? (int) $entry['routine_task_id'] : 0;
        $scheduled = isset($entry['scheduled_seconds']) ? (int) $entry['scheduled_seconds'] : 0;
        $actual = isset($entry['actual_seconds']) ? (int) $entry['actual_seconds'] : 0;
        $overtime = isset($entry['overtime_seconds']) ? (int) $entry['overtime_seconds'] : 0;
        if ($routineId <= 0 || $taskId <= 0 || $scheduled <= 0 || $actual <= 0 || $overtime <= 0) {
            continue;
        }
        global $db;
        $stmt = $db->prepare("SELECT child_user_id FROM routines WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $routineId]);
        $childId = $stmt->fetchColumn();
        if ((int) $childId !== (int) $_SESSION['user_id']) {
            continue;
        }
        if (logRoutineOvertime($routineId, $taskId, (int) $childId, $scheduled, $actual, $overtime)) {
            $logged++;
        }
    }
    echo json_encode(['status' => 'ok', 'logged' => $logged]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($isParentContext && isset($_POST['create_routine'])) {
        $child_user_id = filter_input(INPUT_POST, 'child_user_id', FILTER_VALIDATE_INT);
        $title = trim((string) filter_input(INPUT_POST, 'title', FILTER_SANITIZE_STRING));
        $start_time = filter_input(INPUT_POST, 'start_time', FILTER_SANITIZE_STRING);
        $end_time = filter_input(INPUT_POST, 'end_time', FILTER_SANITIZE_STRING);
        $recurrence = filter_input(INPUT_POST, 'recurrence', FILTER_SANITIZE_STRING);
        $bonus_points = filter_input(INPUT_POST, 'bonus_points', FILTER_VALIDATE_INT);
        $structureRaw = $_POST['routine_structure'] ?? '';

        $recurrence = in_array($recurrence, ['daily', 'weekly'], true) ? $recurrence : '';
        $bonus_points = ($bonus_points !== false && $bonus_points >= 0) ? $bonus_points : 0;

        $errors = [];
        if (!$child_user_id || !routineChildBelongsToFamily($child_user_id, $family_root_id)) {
            $errors[] = 'Select a child from your family for this routine.';
        }
        if ($title === '') {
            $errors[] = 'Provide a title for the routine.';
        }

        $durationMinutes = validateRoutineTimeframe($start_time, $end_time, $errors);
        [$normalizedTasks, $taskMap, $sanitizedStructure] = normalizeRoutineStructure($structureRaw, $family_root_id, $errors);

        $totalTaskMinutes = 0;
        foreach ($normalizedTasks as $taskRow) {
            $totalTaskMinutes += max(0, (int) $taskRow['time_limit']);
        }
        if ($durationMinutes !== null && $totalTaskMinutes > $durationMinutes) {
            $errors[] = "Total task time ({$totalTaskMinutes} min) exceeds the routine timeframe ({$durationMinutes} min).";
        }

        if (empty($errors)) {
            global $db;
            try {
                $db->beginTransaction();
                $routineId = createRoutine($family_root_id, $child_user_id, $title, $start_time, $end_time, $recurrence, $bonus_points, $_SESSION['user_id']);
                replaceRoutineTasks($routineId, $normalizedTasks);
                $db->commit();
                $messages[] = ['type' => 'success', 'text' => 'Routine created successfully.'];
                $createRoutineState = ['tasks' => []];
            } catch (Exception $e) {
                $db->rollBack();
                error_log('Routine creation failed: ' . $e->getMessage());
                $messages[] = ['type' => 'error', 'text' => 'Failed to create routine. Please try again.'];
                $createFormHasErrors = true;
                $createRoutineState = $sanitizedStructure;
            }
        } else {
            $createFormHasErrors = true;
            $createRoutineState = $sanitizedStructure;
            $messages[] = ['type' => 'error', 'text' => implode(' ', array_unique($errors))];
        }
    } elseif ($isParentContext && isset($_POST['update_routine'])) {
        $routine_id = filter_input(INPUT_POST, 'routine_id', FILTER_VALIDATE_INT);
        $title = trim((string) filter_input(INPUT_POST, 'title', FILTER_SANITIZE_STRING));
        $start_time = filter_input(INPUT_POST, 'start_time', FILTER_SANITIZE_STRING);
        $end_time = filter_input(INPUT_POST, 'end_time', FILTER_SANITIZE_STRING);
        $recurrence = filter_input(INPUT_POST, 'recurrence', FILTER_SANITIZE_STRING);
        $bonus_points = filter_input(INPUT_POST, 'bonus_points', FILTER_VALIDATE_INT);
        $structureRaw = $_POST['routine_structure'] ?? '';

        $recurrence = in_array($recurrence, ['daily', 'weekly'], true) ? $recurrence : '';
        $bonus_points = ($bonus_points !== false && $bonus_points >= 0) ? $bonus_points : 0;

        $errors = [];
        if (!$routine_id || !routineBelongsToParent($routine_id, $family_root_id)) {
            $errors[] = 'Unable to locate that routine for editing.';
        }
        if ($title === '') {
            $errors[] = 'Provide a title for the routine.';
        }
        $durationMinutes = validateRoutineTimeframe($start_time, $end_time, $errors);
        [$normalizedTasks, $taskMap, $sanitizedStructure] = normalizeRoutineStructure($structureRaw, $family_root_id, $errors);

        $totalTaskMinutes = 0;
        foreach ($normalizedTasks as $taskRow) {
            $totalTaskMinutes += max(0, (int) $taskRow['time_limit']);
        }
        if ($durationMinutes !== null && $totalTaskMinutes > $durationMinutes) {
            $errors[] = "Total task time ({$totalTaskMinutes} min) exceeds the routine timeframe ({$durationMinutes} min).";
        }

        if (empty($errors)) {
            global $db;
            try {
                $db->beginTransaction();
                updateRoutine($routine_id, $title, $start_time, $end_time, $recurrence, $bonus_points, $family_root_id);
                replaceRoutineTasks($routine_id, $normalizedTasks);
                $db->commit();
                $messages[] = ['type' => 'success', 'text' => 'Routine updated successfully.'];
            } catch (Exception $e) {
                $db->rollBack();
                error_log('Routine update failed: ' . $e->getMessage());
                $messages[] = ['type' => 'error', 'text' => 'Failed to update routine. Please retry.'];
                $editRoutineStates[$routine_id] = $sanitizedStructure;
                $editFormErrors[$routine_id] = true;
                $editFieldOverrides[$routine_id] = [
                    'title' => $title,
                    'start_time' => $start_time,
                    'end_time' => $end_time,
                    'bonus_points' => $bonus_points,
                    'recurrence' => $recurrence
                ];
            }
        } else {
            $messages[] = ['type' => 'error', 'text' => implode(' ', array_unique($errors))];
            if ($routine_id) {
                $editRoutineStates[$routine_id] = $sanitizedStructure;
                $editFormErrors[$routine_id] = true;
                $editFieldOverrides[$routine_id] = [
                    'title' => $title,
                    'start_time' => $start_time,
                    'end_time' => $end_time,
                    'bonus_points' => $bonus_points,
                    'recurrence' => $recurrence
                ];
            }
        }
    } elseif ($isParentContext && isset($_POST['delete_routine'])) {
        $routine_id = filter_input(INPUT_POST, 'routine_id', FILTER_VALIDATE_INT);
        if ($routine_id && routineBelongsToParent($routine_id, $family_root_id) && deleteRoutine($routine_id, $family_root_id)) {
            $messages[] = ['type' => 'success', 'text' => 'Routine deleted.'];
        } else {
            $messages[] = ['type' => 'error', 'text' => 'Unable to delete routine.'];
        }
    } elseif ($isParentContext && isset($_POST['create_routine_task'])) {
        $title = trim((string) filter_input(INPUT_POST, 'rt_title', FILTER_SANITIZE_STRING));
        $description = trim((string) filter_input(INPUT_POST, 'rt_description', FILTER_SANITIZE_STRING));
        $time_limit = filter_input(INPUT_POST, 'rt_time_limit', FILTER_VALIDATE_INT);
        $point_value = filter_input(INPUT_POST, 'rt_point_value', FILTER_VALIDATE_INT);
        $category = filter_input(INPUT_POST, 'rt_category', FILTER_SANITIZE_STRING);

        $time_limit = ($time_limit !== false && $time_limit > 0) ? $time_limit : null;
        $point_value = ($point_value !== false && $point_value >= 0) ? $point_value : 0;
        $category = in_array($category, ['hygiene', 'homework', 'household'], true) ? $category : 'household';

        if ($title === '' || $time_limit === null) {
            $messages[] = ['type' => 'error', 'text' => 'Routine task needs a title and a positive time limit.'];
        } else {
            if (createRoutineTask($family_root_id, $title, $description, $time_limit, $point_value, $category, null, null, $_SESSION['user_id'])) {
                $messages[] = ['type' => 'success', 'text' => 'Routine task added to the library.'];
            } else {
                $messages[] = ['type' => 'error', 'text' => 'Failed to add routine task.'];
            }
        }
    } elseif ($isParentContext && isset($_POST['delete_routine_task'])) {
        $routine_task_id = filter_input(INPUT_POST, 'routine_task_id', FILTER_VALIDATE_INT);
        if ($routine_task_id && deleteRoutineTask($routine_task_id, $family_root_id)) {
            $messages[] = ['type' => 'success', 'text' => 'Routine task removed from the library.'];
        } else {
            $messages[] = ['type' => 'error', 'text' => 'Unable to delete routine task.'];
        }
    } elseif ($isParentContext && isset($_POST['save_routine_preferences'])) {
        $adaptive = isset($_POST['adaptive_warnings_enabled']) ? 1 : 0;
        $label = filter_input(INPUT_POST, 'sub_timer_label', FILTER_SANITIZE_STRING);
        if (!array_key_exists($label, $subTimerLabelOptions)) {
            $label = 'hurry_goal';
        }
        if (saveRoutinePreferences($family_root_id, $adaptive, $label)) {
            $routinePreferences = getRoutinePreferences($family_root_id);
            $messages[] = ['type' => 'success', 'text' => 'Routine timer preferences saved.'];
        } else {
            $messages[] = ['type' => 'error', 'text' => 'Failed to save routine preferences.'];
        }
    } elseif (isset($_POST['complete_routine'])) {
        $routine_id = filter_input(INPUT_POST, 'routine_id', FILTER_VALIDATE_INT);
        $bonus = completeRoutine($routine_id, $_SESSION['user_id']);
        if ($bonus !== false) {
            $messages[] = ['type' => 'success', 'text' => "Routine completed! Bonus points awarded: {$bonus}"];
        } else {
            $messages[] = ['type' => 'error', 'text' => 'Failed to complete routine. Ensure all tasks are approved.'];
        }
    }
}

$routine_tasks = $isParentContext ? getRoutineTasks($family_root_id) : [];
$routines = getRoutines($_SESSION['user_id']);

$children = [];
if ($isParentContext) {
    global $db;
    $stmt = $db->prepare("SELECT child_user_id, child_name FROM child_profiles WHERE parent_user_id = :parent ORDER BY child_name ASC");
    $stmt->execute([':parent' => $family_root_id]);
    $children = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$welcome_role_label = getUserRoleLabel($_SESSION['user_id']);
if (!$welcome_role_label) {
    $fallback_role = getEffectiveRole($_SESSION['user_id']) ?: ($_SESSION['role'] ?? null);
    if ($fallback_role) {
        $welcome_role_label = ucfirst(str_replace('_', ' ', $fallback_role));
    }
}

$createBuilderInitial = $createRoutineState['tasks'];
$editBuilderInitial = [];
foreach ($routines as $routine) {
    $rid = (int) $routine['id'];
    if (isset($editRoutineStates[$rid])) {
        $editBuilderInitial[$rid] = $editRoutineStates[$rid]['tasks'];
        continue;
    }
    $editBuilderInitial[$rid] = array_map(static function ($task) {
        return [
            'id' => (int) $task['id'],
            'dependency_id' => $task['dependency_id'] !== null ? (int) $task['dependency_id'] : null
        ];
    }, $routine['tasks']);
}

$jsonOptions = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP;
$pageState = [
    'tasks' => $routine_tasks,
    'createInitial' => $createBuilderInitial,
    'editInitial' => $editBuilderInitial,
    'routines' => $routines,
    'preferences' => $routinePreferences,
    'subTimerLabels' => $subTimerLabelOptions,
    'createFormHasErrors' => $createFormHasErrors,
    'editFormErrors' => array_keys($editFormErrors)
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Routine Management</title>
    <link rel="stylesheet" href="css/main.css">
    <style>
        .page-messages { max-width: 960px; margin: 0 auto 20px; }
        .page-alert { padding: 12px 16px; border-radius: 6px; margin-bottom: 12px; font-weight: 600; }
        .page-alert.success { background: #e8f5e9; color: #2e7d32; border: 1px solid #c8e6c9; }
        .page-alert.error { background: #ffebee; color: #c62828; border: 1px solid #ffcdd2; }
        .page-alert.info { background: #e3f2fd; color: #1565c0; border: 1px solid #bbdefb; }
        .role-badge { margin-left: 8px; padding: 2px 8px; border-radius: 999px; background: #4caf50; color: #fff; font-size: 0.82rem; }
        .routine-layout { max-width: 1080px; margin: 0 auto; padding: 0 16px 40px; }
        .routine-section { background: #fff; border-radius: 10px; box-shadow: 0 2px 6px rgba(0,0,0,0.08); padding: 20px; margin-bottom: 24px; }
        .routine-section h2 { margin-top: 0; font-size: 1.5rem; }
        .form-grid { display: grid; gap: 16px; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); }
        .form-group { display: flex; flex-direction: column; gap: 6px; }
        .form-group label { font-weight: 600; }
        .form-group input, .form-group select, .form-group textarea { padding: 8px; border: 1px solid #ccc; border-radius: 6px; font-size: 0.95rem; }
        .form-actions { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 12px; }
        .button { padding: 10px 18px; border: none; border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 0.95rem; background: #4caf50; color: #fff; }
        .button.secondary { background: #607d8b; }
        .start-next-button { background: #1e88e5; }
        .button.danger { background: #e53935; }
        .button.linkish { background: transparent; color: #1565c0; border: none; padding: 0; }
        .routine-builder { border: 1px solid #e0e0e0; border-radius: 8px; padding: 16px; margin-top: 16px; background: #fafafa; }
        .builder-controls { display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end; }
        .builder-controls select { min-width: 240px; }
        .selected-task-list { list-style: none; margin: 18px 0 0; padding: 0; }
        .selected-task-item { background: #fff; border: 1px solid #dcdcdc; border-radius: 8px; padding: 12px; display: grid; grid-template-columns: auto 1fr auto; align-items: center; gap: 12px; margin-bottom: 10px; }
        .selected-task-item.error { border-color: #f44336; }
        .drag-handle { cursor: grab; font-size: 1.2rem; color: #9e9e9e; }
        .task-meta { font-size: 0.85rem; color: #616161; }
        .dependency-select { margin-top: 6px; }
        .dependency-select label { font-weight: 600; font-size: 0.85rem; display: block; }
        .summary-row { display: flex; flex-wrap: wrap; gap: 16px; font-weight: 600; margin-top: 12px; }
        .summary-row .warning { color: #c62828; }
        .routine-card { border: 1px solid #e0e0e0; border-radius: 12px; padding: 18px; margin-bottom: 20px; background: linear-gradient(145deg, #ffffff, #f5f5f5); box-shadow: 0 3px 8px rgba(0,0,0,0.08); }
        .routine-card.child-view { background: linear-gradient(160deg, #e3f2fd, #e8f5e9); border-color: #bbdefb; }
        .routine-card header { display: flex; flex-direction: column; gap: 4px; margin-bottom: 12px; }
        .routine-card h3 { margin: 0; font-size: 1.25rem; }
        .routine-details { font-size: 0.9rem; color: #455a64; display: grid; gap: 4px; }
        .task-list { list-style: none; margin: 0; padding: 0; display: grid; gap: 8px; }
        .task-list li { background: rgba(255,255,255,0.85); border-radius: 8px; padding: 10px 12px; border-left: 4px solid #64b5f6; }
        .task-list li .dependency { font-size: 0.8rem; color: #6d4c41; }
        .card-actions { margin-top: 16px; display: flex; flex-wrap: wrap; gap: 12px; }
        .collapse-toggle { background: #1e88e5; color: #fff; border: none; padding: 8px 14px; border-radius: 6px; cursor: pointer; font-weight: 600; }
        .collapsible-content { margin-top: 12px; display: none; }
        .collapsible-content.active { display: block; }
        .timer-stack { display: grid; gap: 12px; margin-top: 12px; }
        .timer-widget { background: rgba(255,255,255,0.92); border-radius: 10px; padding: 12px 16px; border: 1px solid rgba(33,150,243,0.2); }
        .timer-title { font-weight: 700; color: #1e88e5; margin-bottom: 4px; }
        .timer-value { font-size: 1.6rem; font-weight: 700; letter-spacing: 1px; }
        .timer-warning { color: #c62828; font-weight: 600; margin-top: 6px; }
        .sub-timer-label { font-size: 0.95rem; font-weight: 600; color: #ef6c00; margin-top: 6px; }
        .warning-active .timer-widget { border-color: #e53935; box-shadow: 0 0 12px rgba(229,57,53,0.25); }
        .library-table { width: 100%; border-collapse: collapse; margin-top: 12px; font-size: 0.92rem; }
        .library-table th, .library-table td { border: 1px solid #e0e0e0; padding: 8px; text-align: left; }
        .library-table th { background: #f0f4f7; }
        .no-data { font-style: italic; color: #757575; }
        footer { text-align: center; padding: 24px 0; color: #607d8b; }
        @media (max-width: 720px) {
            .selected-task-item { grid-template-columns: 1fr; }
            .drag-handle { display: none; }
            .card-actions { flex-direction: column; }
        }
    </style>
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
</head>
<body>
    <header>
        <h1>Routine Management</h1>
        <p>
            Welcome, <?php echo htmlspecialchars($_SESSION['name'] ?? $_SESSION['username'] ?? 'User'); ?>
            <?php if ($welcome_role_label): ?>
                <span class="role-badge"><?php echo htmlspecialchars($welcome_role_label); ?></span>
            <?php endif; ?>
        </p>
        <nav>
            <a href="dashboard_<?php echo htmlspecialchars($_SESSION['role']); ?>.php">Dashboard</a> |
            <a href="goal.php">Goals</a> |
            <a href="task.php">Tasks</a> |
            <a href="profile.php?self=1">Profile</a> |
            <a href="logout.php">Logout</a>
        </nav>
    </header>
    <main class="routine-layout">
        <?php if (!empty($messages)): ?>
            <div class="page-messages">
                <?php foreach ($messages as $message): ?>
                    <div class="page-alert <?php echo htmlspecialchars($message['type']); ?>">
                        <?php echo htmlspecialchars($message['text']); ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($isParentContext): ?>
            <section class="routine-section">
                <h2>Routine Timer Preferences</h2>
                <form method="POST" class="form-grid" autocomplete="off">
                    <div class="form-group">
                        <label>
                            <input type="checkbox" name="adaptive_warnings_enabled" value="1" <?php echo $routinePreferences['adaptive_warnings_enabled'] ? 'checked' : ''; ?>>
                            Enable Adaptive Time Warnings
                        </label>
                        <small>Warns children when routine buffer is low.</small>
                    </div>
                    <div class="form-group">
                        <label for="sub_timer_label">Sub-Timer Label</label>
                        <select id="sub_timer_label" name="sub_timer_label">
                            <?php foreach ($subTimerLabelOptions as $key => $label): ?>
                                <option value="<?php echo htmlspecialchars($key); ?>" <?php echo ($routinePreferences['sub_timer_label'] === $key) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-actions">
                        <button type="submit" name="save_routine_preferences" class="button">Save Preferences</button>
                    </div>
                </form>
            </section>

            <section class="routine-section">
                <h2>Create Routine</h2>
                <?php if (empty($children)): ?>
                    <p class="no-data">Add children to your family profile before creating routines.</p>
                <?php else: ?>
                    <form method="POST" autocomplete="off">
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="child_user_id">Assign to Child</label>
                                <select id="child_user_id" name="child_user_id" required>
                                    <option value="">Select Child</option>
                                    <?php foreach ($children as $child): ?>
                                        <option value="<?php echo (int) $child['child_user_id']; ?>"><?php echo htmlspecialchars($child['child_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="title">Routine Title</label>
                                <input type="text" id="title" name="title" required <?php echo $createFormHasErrors ? 'value="' . htmlspecialchars($_POST['title'] ?? '', ENT_QUOTES) . '"' : ''; ?>>
                            </div>
                            <div class="form-group">
                                <label for="start_time">Start Time</label>
                                <input type="time" id="start_time" name="start_time" required <?php echo $createFormHasErrors ? 'value="' . htmlspecialchars($_POST['start_time'] ?? '', ENT_QUOTES) . '"' : ''; ?>>
                            </div>
                            <div class="form-group">
                                <label for="end_time">End Time</label>
                                <input type="time" id="end_time" name="end_time" required <?php echo $createFormHasErrors ? 'value="' . htmlspecialchars($_POST['end_time'] ?? '', ENT_QUOTES) . '"' : ''; ?>>
                            </div>
                            <div class="form-group">
                                <label for="bonus_points">Bonus Points</label>
                                <input type="number" id="bonus_points" name="bonus_points" min="0" value="<?php echo (int) ($_POST['bonus_points'] ?? 0); ?>">
                            </div>
                            <div class="form-group">
                                <label for="recurrence">Recurrence</label>
                                <select id="recurrence" name="recurrence">
                                    <option value="">None</option>
                                    <option value="daily" <?php echo (($_POST['recurrence'] ?? '') === 'daily') ? 'selected' : ''; ?>>Daily</option>
                                    <option value="weekly" <?php echo (($_POST['recurrence'] ?? '') === 'weekly') ? 'selected' : ''; ?>>Weekly</option>
                                </select>
                            </div>
                        </div>
                        <div class="routine-builder" data-builder-id="create" data-start-input="#start_time" data-end-input="#end_time">
                            <div class="builder-controls">
                                <div class="form-group">
                                    <label for="create-task-picker">Add Routine Task</label>
                                    <select id="create-task-picker" data-role="task-picker">
                                        <option value="">Select task...</option>
                                        <?php foreach ($routine_tasks as $task): ?>
                                            <option value="<?php echo (int) $task['id']; ?>"><?php echo htmlspecialchars($task['title']); ?> (<?php echo (int) $task['time_limit']; ?> min)</option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <button type="button" class="button secondary" data-role="add-task">Add Task</button>
                            </div>
                            <ul class="selected-task-list" data-role="selected-list"></ul>
                            <div class="summary-row">
                                <span>Total Task Time: <span data-role="total-minutes">0</span> min</span>
                                <span>Routine Duration: <span data-role="duration-minutes">--</span> min</span>
                                <span class="warning" data-role="warning"></span>
                            </div>
                            <input type="hidden" name="routine_structure" data-role="structure-input">
                        </div>
                        <div class="form-actions">
                            <button type="submit" name="create_routine" class="button">Create Routine</button>
                        </div>
                    </form>
                <?php endif; ?>
            </section>

            <section class="routine-section">
                <h2>Add Routine Task to Library</h2>
                <form method="POST" class="form-grid" autocomplete="off">
                    <div class="form-group">
                        <label for="rt_title">Task Title</label>
                        <input type="text" id="rt_title" name="rt_title" required>
                    </div>
                    <div class="form-group">
                        <label for="rt_description">Description</label>
                        <textarea id="rt_description" name="rt_description" rows="2"></textarea>
                    </div>
                    <div class="form-group">
                        <label for="rt_time_limit">Time Limit (minutes)</label>
                        <input type="number" id="rt_time_limit" name="rt_time_limit" min="1" required>
                    </div>
                    <div class="form-group">
                        <label for="rt_point_value">Point Value</label>
                        <input type="number" id="rt_point_value" name="rt_point_value" min="0" value="0">
                    </div>
                    <div class="form-group">
                        <label for="rt_category">Category</label>
                        <select id="rt_category" name="rt_category">
                            <option value="hygiene">Hygiene</option>
                            <option value="homework">Homework</option>
                            <option value="household">Household</option>
                        </select>
                    </div>
                    <div class="form-actions">
                        <button type="submit" name="create_routine_task" class="button">Add Routine Task</button>
                    </div>
                </form>
                <?php if (empty($routine_tasks)): ?>
                    <p class="no-data">No routine tasks available yet. Add a task to start building routines.</p>
                <?php else: ?>
                    <table class="library-table">
                        <thead>
                            <tr>
                                <th>Title</th>
                                <th>Time Limit (min)</th>
                                <th>Points</th>
                                <th>Category</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($routine_tasks as $task): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($task['title']); ?></td>
                                    <td><?php echo (int) $task['time_limit']; ?></td>
                                    <td><?php echo (int) $task['point_value']; ?></td>
                                    <td><?php echo htmlspecialchars($task['category']); ?></td>
                                    <td>
                                        <?php if ((int) $task['parent_user_id'] === $family_root_id): ?>
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="routine_task_id" value="<?php echo (int) $task['id']; ?>">
                                                <button type="submit" name="delete_routine_task" class="button danger" onclick="return confirm('Delete this routine task from the library?');">Delete</button>
                                            </form>
                                        <?php else: ?>
                                            <span class="no-data">Global task</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <section class="routine-section">
            <h2><?php echo ($isParentContext ? 'Family Routines' : 'My Routines'); ?></h2>
            <?php if (empty($routines)): ?>
                <p class="no-data">No routines available.</p>
            <?php else: ?>
                <?php foreach ($routines as $routine): ?>
                    <?php
                        $isChildView = (getEffectiveRole($_SESSION['user_id']) === 'child');
                        $cardClasses = 'routine-card' . ($isChildView ? ' child-view' : '');
                    ?>
                    <article class="<?php echo $cardClasses; ?>" data-routine-id="<?php echo (int) $routine['id']; ?>">
                        <header>
                            <h3><?php echo htmlspecialchars($routine['title']); ?></h3>
                            <div class="routine-details">
                                <span>Timeframe: <?php echo date('g:i A', strtotime($routine['start_time'])) . ' - ' . date('g:i A', strtotime($routine['end_time'])); ?></span>
                                <span>Bonus Points: <?php echo (int) $routine['bonus_points']; ?></span>
                                <span>Recurrence: <?php echo htmlspecialchars($routine['recurrence'] ?: 'None'); ?></span>
                                <?php if (!empty($routine['creator_display_name'])): ?>
                                    <span>Created by: <?php echo htmlspecialchars($routine['creator_display_name']); ?></span>
                                <?php endif; ?>
                            </div>
                        </header>
                        <button type="button" class="collapse-toggle" data-role="toggle-card">Toggle Routine Details</button>
                        <div class="collapsible-content" data-role="collapsible">
                            <ul class="task-list">
                                <?php foreach ($routine['tasks'] as $task): ?>
                                    <li>
                                        <strong><?php echo htmlspecialchars($task['title']); ?></strong>
                                        <div class="task-meta"><?php echo (int) $task['time_limit']; ?> min, Status: <?php echo htmlspecialchars($task['status']); ?></div>
                                        <?php if (!empty($task['dependency_id'])): ?>
                                            <div class="dependency">Depends on Task ID: <?php echo (int) $task['dependency_id']; ?></div>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            <?php if ($isChildView): ?>
                                <div class="timer-stack" data-role="child-controls">
                                    <div class="timer-widget">
                                        <div class="timer-title">Routine Timer</div>
                                        <div class="timer-value" data-role="routine-timer">--:--</div>
                                        <div class="timer-warning" data-role="routine-warning"></div>
                                    </div>
                                    <div class="timer-widget" data-role="task-widget">
                                        <div class="timer-title">Current Task</div>
                                        <div class="task-meta" data-role="current-task-title">Press Start to begin.</div>
                                        <div class="timer-value" data-role="task-timer">--:--</div>
                                        <div class="sub-timer-label" data-role="sub-timer-label"></div>
                                    </div>
                                </div>
                                <div class="card-actions">
                                    <button type="button" class="button start-next-button" data-action="start-routine">Start Routine</button>
                                    <button type="button" class="button secondary" data-action="finish-task" disabled>Finish Task</button>
                                </div>
                                <form method="POST" action="routine.php">
                                    <input type="hidden" name="routine_id" value="<?php echo (int) $routine['id']; ?>">
                                    <button type="submit" name="complete_routine" class="button">Complete Routine</button>
                                </form>
                            <?php elseif ($isParentContext): ?>
                                <details class="routine-section" style="margin-top: 16px; background: rgba(250,250,250,0.9);">
                                    <summary><strong>Edit Routine</strong></summary>
                                    <?php
                                        $rid = (int) $routine['id'];
                                        $override = $editFieldOverrides[$rid] ?? null;
                                        $titleValue = htmlspecialchars($override['title'] ?? $routine['title'], ENT_QUOTES);
                                        $startValue = htmlspecialchars($override['start_time'] ?? $routine['start_time'], ENT_QUOTES);
                                        $endValue = htmlspecialchars($override['end_time'] ?? $routine['end_time'], ENT_QUOTES);
                                        $bonusValue = (int) ($override['bonus_points'] ?? $routine['bonus_points']);
                                        $recurrenceValue = $override['recurrence'] ?? $routine['recurrence'];
                                    ?>
                                    <form method="POST" autocomplete="off">
                                        <input type="hidden" name="routine_id" value="<?php echo (int) $routine['id']; ?>">
                                        <div class="form-grid">
                                            <div class="form-group">
                                                <label>Title</label>
                                                <input type="text" name="title" value="<?php echo $titleValue; ?>" required>
                                            </div>
                                            <div class="form-group">
                                                <label>Start Time</label>
                                                <input type="time" name="start_time" value="<?php echo $startValue; ?>" required>
                                            </div>
                                            <div class="form-group">
                                                <label>End Time</label>
                                                <input type="time" name="end_time" value="<?php echo $endValue; ?>" required>
                                            </div>
                                            <div class="form-group">
                                                <label>Bonus Points</label>
                                                <input type="number" name="bonus_points" min="0" value="<?php echo $bonusValue; ?>">
                                            </div>
                                            <div class="form-group">
                                                <label>Recurrence</label>
                                                <select name="recurrence">
                                                    <option value="" <?php echo empty($recurrenceValue) ? 'selected' : ''; ?>>None</option>
                                                    <option value="daily" <?php echo ($recurrenceValue === 'daily') ? 'selected' : ''; ?>>Daily</option>
                                                    <option value="weekly" <?php echo ($recurrenceValue === 'weekly') ? 'selected' : ''; ?>>Weekly</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="routine-builder" data-builder-id="edit-<?php echo (int) $routine['id']; ?>" data-start-input="input[name='start_time']" data-end-input="input[name='end_time']">
                                            <div class="builder-controls">
                                                <div class="form-group">
                                                    <label>Add Routine Task</label>
                                                    <select data-role="task-picker">
                                                        <option value="">Select task...</option>
                                                        <?php foreach ($routine_tasks as $task): ?>
                                                            <option value="<?php echo (int) $task['id']; ?>"><?php echo htmlspecialchars($task['title']); ?> (<?php echo (int) $task['time_limit']; ?> min)</option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <button type="button" class="button secondary" data-role="add-task">Add Task</button>
                                            </div>
                                            <ul class="selected-task-list" data-role="selected-list"></ul>
                                            <div class="summary-row">
                                                <span>Total Task Time: <span data-role="total-minutes">0</span> min</span>
                                                <span>Routine Duration: <span data-role="duration-minutes">--</span> min</span>
                                                <span class="warning" data-role="warning"></span>
                                            </div>
                                            <input type="hidden" name="routine_structure" data-role="structure-input">
                                        </div>
                                        <div class="form-actions">
                                            <button type="submit" name="update_routine" class="button">Save Changes</button>
                                            <button type="submit" name="delete_routine" class="button danger" onclick="return confirm('Delete this routine?');">Delete Routine</button>
                                        </div>
                                    </form>
                                </details>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
    </main>
    <footer>
        <p>Child Task and Chore App - Ver 3.10.14</p>
    </footer>
    <script>
        window.RoutinePage = <?php echo json_encode($pageState, $jsonOptions); ?>;
    </script>
    <script>
        (function() {
            const page = window.RoutinePage || {
                tasks: [],
                createInitial: [],
                editInitial: {},
                routines: [],
                preferences: { adaptive_warnings_enabled: 1, sub_timer_label: 'hurry_goal' },
                subTimerLabels: {}
            };

            const taskLookup = new Map((Array.isArray(page.tasks) ? page.tasks : []).map(task => [String(task.id), task]));

            function parseTimeParts(value) {
                if (!value) return null;
                const parts = value.split(':').map(Number);
                if (parts.length < 2 || parts.some(Number.isNaN)) return null;
                return { hours: parts[0], minutes: parts[1] };
            }

            function calculateDurationSeconds(start, end) {
                const startParts = parseTimeParts(start);
                const endParts = parseTimeParts(end);
                if (!startParts || !endParts) return null;
                const startSeconds = startParts.hours * 3600 + startParts.minutes * 60;
                let endSeconds = endParts.hours * 3600 + endParts.minutes * 60;
                if (endSeconds <= startSeconds) {
                    endSeconds += 24 * 3600;
                }
                return endSeconds - startSeconds;
            }

            function formatSeconds(totalSeconds) {
                const safe = Math.max(0, Math.floor(totalSeconds));
                const minutes = Math.floor(safe / 60);
                const seconds = safe % 60;
                return `${minutes}:${seconds.toString().padStart(2, '0')}`;
            }

            function formatSignedSeconds(seconds) {
                if (seconds >= 0) {
                    return formatSeconds(seconds);
                }
                return `-${formatSeconds(Math.abs(seconds))}`;
            }

            class RoutineBuilder {
                constructor(container, initialTasks) {
                    this.container = container;
                    this.listEl = container.querySelector('[data-role="selected-list"]');
                    this.taskPicker = container.querySelector('[data-role="task-picker"]');
                    this.addButton = container.querySelector('[data-role="add-task"]');
                    this.structureInput = container.querySelector('[data-role="structure-input"]');
                    this.totalMinutesEl = container.querySelector('[data-role="total-minutes"]');
                    this.durationEl = container.querySelector('[data-role="duration-minutes"]');
                    this.warningEl = container.querySelector('[data-role="warning"]');
                    this.startInput = this.resolveInput(container.dataset.startInput);
                    this.endInput = this.resolveInput(container.dataset.endInput);
                    this.selectedTasks = Array.isArray(initialTasks)
                        ? initialTasks.map(task => ({
                            id: parseInt(task.id, 10),
                            dependency_id: task.dependency_id !== null ? parseInt(task.dependency_id, 10) : null
                        })).filter(task => task.id > 0)
                        : [];
                    this.setup();
                }

                resolveInput(selector) {
                    if (!selector) return null;
                    if (selector.startsWith('input')) {
                        const form = this.container.closest('form');
                        return form ? form.querySelector(selector) : document.querySelector(selector);
                    }
                    return document.querySelector(selector);
                }

                setup() {
                    if (this.addButton && this.taskPicker) {
                        this.addButton.addEventListener('click', () => {
                            const value = this.taskPicker.value;
                            if (!value) return;
                            const numeric = parseInt(value, 10);
                            if (Number.isNaN(numeric)) return;
                            if (this.selectedTasks.some(task => task.id === numeric)) return;
                            this.selectedTasks.push({ id: numeric, dependency_id: null });
                            this.render();
                        });
                    }

                    if (this.startInput) {
                        this.startInput.addEventListener('change', () => this.updateSummary());
                    }
                    if (this.endInput) {
                        this.endInput.addEventListener('change', () => this.updateSummary());
                    }

                    if (this.listEl) {
                        new Sortable(this.listEl, {
                            animation: 150,
                            handle: '.drag-handle',
                            onEnd: () => {
                                const order = Array.from(this.listEl.querySelectorAll('.selected-task-item'))
                                    .map(item => parseInt(item.dataset.taskId, 10));
                                const reordered = [];
                                order.forEach(id => {
                                    const existing = this.selectedTasks.find(task => task.id === id);
                                    if (existing) {
                                        reordered.push(existing);
                                    }
                                });
                                this.selectedTasks = reordered;
                                this.render();
                            }
                        });
                    }

                    this.render();
                }

                render() {
                    if (!this.listEl) return;
                    this.listEl.innerHTML = '';

                    this.selectedTasks.forEach((task, index) => {
                        const taskData = taskLookup.get(String(task.id));
                        if (!taskData) return;

                        const item = document.createElement('li');
                        item.className = 'selected-task-item';
                        item.dataset.taskId = String(task.id);

                        const handle = document.createElement('span');
                        handle.className = 'drag-handle';
                        handle.textContent = '?';

                        const body = document.createElement('div');
                        const title = document.createElement('div');
                        title.innerHTML = `<strong>${taskData.title}</strong>`;
                        const meta = document.createElement('div');
                        meta.className = 'task-meta';
                        meta.textContent = `${taskData.time_limit || 0} min • ${taskData.category}`;

                        const dependencyWrapper = document.createElement('div');
                        dependencyWrapper.className = 'dependency-select';
                        const label = document.createElement('label');
                        label.textContent = 'Depends on:';
                        const select = document.createElement('select');
                        const noneOption = document.createElement('option');
                        noneOption.value = '';
                        noneOption.textContent = 'None';
                        select.appendChild(noneOption);
                        for (let i = 0; i < index; i++) {
                            const allowedTask = this.selectedTasks[i];
                            const allowedData = taskLookup.get(String(allowedTask.id));
                            if (!allowedData) continue;
                            const option = document.createElement('option');
                            option.value = String(allowedTask.id);
                            option.textContent = allowedData.title;
                            select.appendChild(option);
                        }
                        select.value = task.dependency_id !== null ? String(task.dependency_id) : '';
                        select.addEventListener('change', () => {
                            task.dependency_id = select.value !== '' ? parseInt(select.value, 10) : null;
                        });
                        dependencyWrapper.appendChild(label);
                        dependencyWrapper.appendChild(select);

                        body.appendChild(title);
                        body.appendChild(meta);
                        body.appendChild(dependencyWrapper);

                        const removeButton = document.createElement('button');
                        removeButton.type = 'button';
                        removeButton.className = 'button danger';
                        removeButton.textContent = 'Remove';
                        removeButton.addEventListener('click', () => {
                            this.selectedTasks = this.selectedTasks.filter(selected => selected.id !== task.id);
                            this.render();
                        });

                        item.appendChild(handle);
                        item.appendChild(body);
                        item.appendChild(removeButton);
                        this.listEl.appendChild(item);
                    });

                    this.syncStructureInput();
                    this.updateSummary();
                }

                syncStructureInput() {
                    if (!this.structureInput) return;
                    const payload = {
                        tasks: this.selectedTasks.map(task => ({
                            id: task.id,
                            dependency_id: task.dependency_id
                        }))
                    };
                    this.structureInput.value = JSON.stringify(payload);
                }

                updateSummary() {
                    const totalMinutes = this.selectedTasks.reduce((sum, task) => {
                        const data = taskLookup.get(String(task.id));
                        return sum + (data ? (parseInt(data.time_limit, 10) || 0) : 0);
                    }, 0);
                    const durationSeconds = calculateDurationSeconds(this.startInput ? this.startInput.value : '', this.endInput ? this.endInput.value : '');
                    const durationMinutes = durationSeconds !== null ? Math.round(durationSeconds / 60) : null;
                    if (this.totalMinutesEl) {
                        this.totalMinutesEl.textContent = totalMinutes;
                    }
                    if (this.durationEl) {
                        this.durationEl.textContent = durationMinutes !== null ? durationMinutes : '--';
                    }
                    if (this.warningEl) {
                        if (durationMinutes !== null && totalMinutes > durationMinutes) {
                            this.warningEl.textContent = 'Total task time exceeds routine duration.';
                            this.container.classList.add('warning-active');
                        } else {
                            this.warningEl.textContent = '';
                            this.container.classList.remove('warning-active');
                        }
                    }
                }
            }

            class RoutinePlayer {
                constructor(card, routine, preferences, labelMap) {
                    this.card = card;
                    this.routine = routine;
                    this.preferences = preferences || { adaptive_warnings_enabled: 1, sub_timer_label: 'hurry_goal' };
                    this.subTimerLabelMap = labelMap || {};
                    this.tasks = Array.isArray(routine.tasks) ? [...routine.tasks] : [];
                    this.tasks.sort((a, b) => (parseInt(a.sequence_order, 10) || 0) - (parseInt(b.sequence_order, 10) || 0));
                    this.startButton = card.querySelector('[data-action="start-routine"]');
                    this.finishButton = card.querySelector('[data-action="finish-task"]');
                    this.routineTimerEl = card.querySelector('[data-role="routine-timer"]');
                    this.taskTimerEl = card.querySelector('[data-role="task-timer"]');
                    this.currentTaskTitleEl = card.querySelector('[data-role="current-task-title"]');
                    this.subTimerLabelEl = card.querySelector('[data-role="sub-timer-label"]');
                    this.warningEl = card.querySelector('[data-role="routine-warning"]');
                    this.currentIndex = -1;
                    this.currentTask = null;
                    this.taskInterval = null;
                    this.routineInterval = null;
                    this.overtimeBuffer = [];
                    this.lastCompletedTaskTitle = '';
                    this.remainingRoutineSeconds = 0;
                    this.totalRoutineSeconds = 0;
                    this.init();
                }

                init() {
                    if (this.startButton) {
                        this.startButton.addEventListener('click', () => this.startRoutine());
                    }
                    if (this.finishButton) {
                        this.finishButton.addEventListener('click', () => this.finishTask());
                    }
                }

                startRoutine() {
                    if (!this.tasks.length) {
                        if (this.currentTaskTitleEl) {
                            this.currentTaskTitleEl.textContent = 'No tasks in this routine yet.';
                        }
                        return;
                    }
                    this.clearTimers();
                    this.overtimeBuffer = [];
                    this.lastCompletedTaskTitle = '';
                    this.currentIndex = 0;
                    this.currentTask = null;
                    this.remainingRoutineSeconds = calculateDurationSeconds(this.routine.start_time, this.routine.end_time);
                    if (this.remainingRoutineSeconds === null) {
                        this.remainingRoutineSeconds = this.tasks.reduce((sum, task) => sum + ((parseInt(task.time_limit, 10) || 0) * 60), 0);
                    }
                    this.totalRoutineSeconds = this.remainingRoutineSeconds;
                    if (this.startButton) {
                        this.startButton.textContent = 'Restart Routine';
                    }
                    if (this.finishButton) {
                        this.finishButton.disabled = false;
                    }
                    this.startTask(this.currentIndex);
                    this.updateRoutineDisplay();
                    this.routineInterval = setInterval(() => this.tickRoutine(), 1000);
                }

                startTask(index) {
                    if (index >= this.tasks.length) {
                        this.completeRoutine();
                        return;
                    }
                    this.clearTaskTimer();
                    const task = this.tasks[index];
                    this.currentTask = task;
                    this.currentTaskScheduledSeconds = Math.max(0, (parseInt(task.time_limit, 10) || 0) * 60);
                    this.taskRemainingSeconds = this.currentTaskScheduledSeconds;
                    if (this.currentTaskTitleEl) {
                        this.currentTaskTitleEl.textContent = task.title;
                    }
                    if (this.taskTimerEl) {
                        this.taskTimerEl.textContent = formatSeconds(this.taskRemainingSeconds);
                        this.taskTimerEl.style.color = '';
                    }
                    this.taskInterval = setInterval(() => this.tickTask(), 1000);
                    this.updateWarning();
                }

                finishTask() {
                    if (!this.currentTask) return;
                    const scheduled = this.currentTaskScheduledSeconds || 0;
                    const overtime = Math.max(0, -this.taskRemainingSeconds);
                    const actual = scheduled + overtime;
                    if (overtime > 0) {
                        this.overtimeBuffer.push({
                            routine_id: parseInt(this.routine.id, 10) || 0,
                            routine_task_id: parseInt(this.currentTask.id, 10) || 0,
                            child_user_id: parseInt(this.routine.child_user_id, 10) || 0,
                            scheduled_seconds: scheduled,
                            actual_seconds: actual,
                            overtime_seconds: overtime
                        });
                    }
                    this.lastCompletedTaskTitle = this.currentTask.title;
                    this.currentIndex += 1;
                    this.clearTaskTimer();
                    if (this.currentIndex < this.tasks.length) {
                        this.startTask(this.currentIndex);
                    } else {
                        this.completeRoutine();
                    }
                }

                tickRoutine() {
                    if (this.remainingRoutineSeconds > 0) {
                        this.remainingRoutineSeconds -= 1;
                    }
                    this.updateRoutineDisplay();
                    this.updateWarning();
                    if (this.remainingRoutineSeconds <= 0) {
                        this.clearRoutineTimer();
                    }
                }

                tickTask() {
                    this.taskRemainingSeconds -= 1;
                    if (this.taskTimerEl) {
                        this.taskTimerEl.textContent = formatSignedSeconds(this.taskRemainingSeconds);
                        this.taskTimerEl.style.color = this.taskRemainingSeconds < 0 ? '#e53935' : '';
                    }
                    this.updateWarning();
                }

                updateRoutineDisplay() {
                    if (this.routineTimerEl) {
                        this.routineTimerEl.textContent = formatSeconds(this.remainingRoutineSeconds);
                    }
                }

                updateWarning() {
                    if (!this.preferences || !this.preferences.adaptive_warnings_enabled) {
                        if (this.warningEl) this.warningEl.textContent = '';
                        if (this.subTimerLabelEl) this.subTimerLabelEl.textContent = '';
                        this.card.classList.remove('warning-active');
                        return;
                    }
                    const futureSeconds = this.tasks.slice(this.currentIndex + 1).reduce((sum, task) => sum + ((parseInt(task.time_limit, 10) || 0) * 60), 0);
                    const currentRemaining = Math.max(0, this.taskRemainingSeconds || 0);
                    const scheduledRemaining = currentRemaining + futureSeconds;
                    if (this.remainingRoutineSeconds < scheduledRemaining) {
                        const adjusted = Math.max(0, this.remainingRoutineSeconds - futureSeconds);
                        const template = this.subTimerLabelMap[this.preferences.sub_timer_label] || '';
                        const previousName = this.lastCompletedTaskTitle || (this.currentTask ? this.currentTask.title : 'this task');
                        const formattedTime = formatSeconds(adjusted);
                        const labelText = template
                            .replace(/\[time\]/g, formattedTime)
                            .replace(/\[previous task\]/g, previousName)
                            .replace(/\[task name\]/g, previousName)
                            .replace(/\[task\]/g, previousName);
                        if (this.warningEl) {
                            this.warningEl.textContent = 'Buffer low: keep moving!';
                        }
                        if (this.subTimerLabelEl) {
                            this.subTimerLabelEl.textContent = labelText;
                        }
                        this.card.classList.add('warning-active');
                    } else {
                        if (this.warningEl) this.warningEl.textContent = '';
                        if (this.subTimerLabelEl) this.subTimerLabelEl.textContent = '';
                        this.card.classList.remove('warning-active');
                    }
                }

                completeRoutine() {
                    this.clearTimers();
                    if (this.finishButton) {
                        this.finishButton.disabled = true;
                    }
                    if (this.currentTaskTitleEl) {
                        this.currentTaskTitleEl.textContent = 'Routine finished!';
                    }
                    if (this.taskTimerEl) {
                        this.taskTimerEl.textContent = '--:--';
                        this.taskTimerEl.style.color = '';
                    }
                    this.updateWarning();
                    this.sendOvertimeLogs();
                }

                sendOvertimeLogs() {
                    const entries = this.overtimeBuffer.filter(entry => entry.overtime_seconds > 0);
                    if (!entries.length) return;
                    const formData = new FormData();
                    formData.append('action', 'log_overtime');
                    formData.append('overtime_payload', JSON.stringify(entries));
                    fetch('routine.php', { method: 'POST', body: formData }).catch(() => { /* silent */ });
                }

                clearTaskTimer() {
                    if (this.taskInterval) {
                        clearInterval(this.taskInterval);
                        this.taskInterval = null;
                    }
                }

                clearRoutineTimer() {
                    if (this.routineInterval) {
                        clearInterval(this.routineInterval);
                        this.routineInterval = null;
                    }
                }

                clearTimers() {
                    this.clearTaskTimer();
                    this.clearRoutineTimer();
                }
            }

            document.querySelectorAll('[data-role="toggle-card"]').forEach(button => {
                const content = button.parentElement.querySelector('[data-role="collapsible"]');
                if (!content) return;
                button.addEventListener('click', () => {
                    content.classList.toggle('active');
                });
            });

            document.querySelectorAll('.routine-builder').forEach(container => {
                const id = container.dataset.builderId || '';
                let initial = [];
                if (id === 'create') {
                    initial = Array.isArray(page.createInitial) ? page.createInitial : [];
                } else if (id.startsWith('edit-')) {
                    const key = id.replace('edit-', '');
                    initial = (page.editInitial && page.editInitial[key]) ? page.editInitial[key] : [];
                }
                new RoutineBuilder(container, initial);
            });

            (Array.isArray(page.routines) ? page.routines : []).forEach(routine => {
                const card = document.querySelector(`.routine-card[data-routine-id="${routine.id}"]`);
                if (!card) return;
                if (card.classList.contains('child-view')) {
                    new RoutinePlayer(card, routine, page.preferences, page.subTimerLabels);
                }
            });
        })();
    </script>
</body>
</html>










