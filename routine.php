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

function routineBelongsToChild(int $routine_id, int $child_user_id): bool {
    global $db;
    $stmt = $db->prepare("SELECT child_user_id FROM routines WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $routine_id]);
    $ownerId = $stmt->fetchColumn();
    return (int) $ownerId === $child_user_id;
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

function calculateRoutineTaskAwardPoints(int $pointValue, int $scheduledSeconds, int $actualSeconds): int {
    if ($pointValue <= 0) {
        return 0;
    }
    if ($scheduledSeconds <= 0) {
        return $pointValue;
    }
    if ($actualSeconds <= $scheduledSeconds) {
        return $pointValue;
    }
    if ($actualSeconds <= $scheduledSeconds + 60) {
        return (int) max(1, (int) ceil($pointValue / 2));
    }
    return 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = is_string($_POST['action']) ? $_POST['action'] : '';

    if ($action === 'log_overtime') {
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
    } elseif ($action === 'reset_routine_tasks') {
        header('Content-Type: application/json');
        if (!isset($_SESSION['user_id'])) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Not authenticated.']);
            exit;
        }
        if (getEffectiveRole($_SESSION['user_id']) !== 'child') {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Only children can reset routines.']);
            exit;
        }
        $routineId = filter_input(INPUT_POST, 'routine_id', FILTER_VALIDATE_INT);
        if (!$routineId) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Missing routine ID.']);
            exit;
        }
        if (!routineBelongsToChild($routineId, (int) $_SESSION['user_id'])) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Routine not assigned to this child.']);
            exit;
        }
        $reset = resetRoutineTaskStatuses($routineId);
        if ($reset && isset($_SESSION['routine_awards'][$routineId])) {
            unset($_SESSION['routine_awards'][$routineId]);
        }
        echo json_encode(['status' => $reset ? 'ok' : 'error']);
        exit;
    } elseif ($action === 'set_routine_task_status') {
        header('Content-Type: application/json');
        if (!isset($_SESSION['user_id'])) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Not authenticated.']);
            exit;
        }
        if (getEffectiveRole($_SESSION['user_id']) !== 'child') {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Only children can update task status.']);
            exit;
        }
        $routineId = filter_input(INPUT_POST, 'routine_id', FILTER_VALIDATE_INT);
        $taskId = filter_input(INPUT_POST, 'routine_task_id', FILTER_VALIDATE_INT);
        $status = isset($_POST['status']) ? (string) $_POST['status'] : '';
        if (!$routineId || !$taskId || !in_array($status, ['pending', 'completed'], true)) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Invalid task status payload.']);
            exit;
        }
        if (!routineBelongsToChild($routineId, (int) $_SESSION['user_id'])) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Routine not assigned to this child.']);
            exit;
        }
        $updated = setRoutineTaskStatus($routineId, $taskId, $status);
        echo json_encode(['status' => $updated ? 'ok' : 'error']);
        exit;
    } elseif ($action === 'complete_routine_flow') {
        header('Content-Type: application/json');
        if (!isset($_SESSION['user_id'])) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Not authenticated.']);
            exit;
        }
        if (getEffectiveRole($_SESSION['user_id']) !== 'child') {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Only children can complete routines.']);
            exit;
        }
        $routineId = filter_input(INPUT_POST, 'routine_id', FILTER_VALIDATE_INT);
        $metricsRaw = $_POST['task_metrics'] ?? '[]';
        $metrics = json_decode($metricsRaw, true);
        if (!$routineId) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Missing routine ID.']);
            exit;
        }
        if (!routineBelongsToChild($routineId, (int) $_SESSION['user_id'])) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Routine not assigned to this child.']);
            exit;
        }
        if ($metricsRaw !== '[]' && !is_array($metrics)) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Malformed metrics payload.']);
            exit;
        }

        $routine = getRoutineWithTasks($routineId);
        if (!$routine) {
            http_response_code(404);
            echo json_encode(['status' => 'error', 'message' => 'Routine not found.']);
            exit;
        }
        $childId = (int) $_SESSION['user_id'];
        if (!isset($_SESSION['routine_awards'])) {
            $_SESSION['routine_awards'] = [];
        }
        if (!empty($_SESSION['routine_awards'][$routineId])) {
            $currentTotal = getChildTotalPoints($childId);
            echo json_encode([
                'status' => 'duplicate',
                'message' => 'Routine already finalized for this session.',
                'task_points_awarded' => 0,
                'bonus_points_awarded' => 0,
                'new_total_points' => $currentTotal
            ]);
            exit;
        }

        $tasks = $routine['tasks'] ?? [];
        $taskLookup = [];
        foreach ($tasks as $taskRow) {
            $taskLookup[(int) $taskRow['id']] = $taskRow;
        }
        if (empty($taskLookup)) {
            echo json_encode([
                'status' => 'error',
                'message' => 'No tasks found for this routine.'
            ]);
            exit;
        }

        $metricsById = [];
        if (is_array($metrics)) {
            foreach ($metrics as $entry) {
                $tid = isset($entry['id']) ? (int) $entry['id'] : 0;
                if ($tid > 0 && !isset($metricsById[$tid])) {
                    $metricsById[$tid] = [
                        'actual_seconds' => max(0, (int) ($entry['actual_seconds'] ?? 0)),
                        'scheduled_seconds' => max(0, (int) ($entry['scheduled_seconds'] ?? 0))
                    ];
                }
            }
        }

        $awards = [];
        $taskPointsAwarded = 0;
        $allWithinLimits = true;

        foreach ($taskLookup as $taskId => $taskRow) {
            $scheduledSeconds = max(0, (int) ($taskRow['time_limit'] ?? 0) * 60);
            $pointValue = max(0, (int) ($taskRow['point_value'] ?? 0));
            $actualSeconds = $scheduledSeconds;
            if (isset($metricsById[$taskId])) {
                $actualSeconds = max(0, (int) $metricsById[$taskId]['actual_seconds']);
            }
            $awardedPoints = calculateRoutineTaskAwardPoints($pointValue, $scheduledSeconds, $actualSeconds);
            if ($scheduledSeconds > 0 && $actualSeconds > $scheduledSeconds) {
                $allWithinLimits = false;
            }
            $taskPointsAwarded += $awardedPoints;
            $awards[] = [
                'id' => $taskId,
                'title' => $taskRow['title'],
                'point_value' => $pointValue,
                'scheduled_seconds' => $scheduledSeconds,
                'actual_seconds' => $actualSeconds,
                'awarded_points' => $awardedPoints
            ];
            setRoutineTaskStatus($routineId, $taskId, 'completed');
        }

        $bonusPossible = max(0, (int) ($routine['bonus_points'] ?? 0));

        if ($taskPointsAwarded > 0) {
            updateChildPoints($childId, $taskPointsAwarded);
        }

        $grantBonus = $allWithinLimits && count($awards) === count($taskLookup);
        $bonus = completeRoutine($routineId, $childId, $grantBonus);
        $bonusAwarded = is_numeric($bonus) ? (int) $bonus : 0;
        $_SESSION['routine_awards'][$routineId] = true;
        $newTotal = getChildTotalPoints($childId);

        echo json_encode([
            'status' => 'ok',
            'task_points_awarded' => $taskPointsAwarded,
            'bonus_points_awarded' => $bonusAwarded,
            'bonus_possible' => $bonusPossible,
            'bonus_eligible' => $grantBonus,
            'new_total_points' => $newTotal,
            'task_results' => $awards,
            'all_within_limits' => $allWithinLimits
        ]);
        exit;
    }
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
                $routine_tasks = getRoutineTasks($family_root_id);
            } else {
                $messages[] = ['type' => 'error', 'text' => 'Failed to add routine task.'];
            }
        }
    } elseif ($isParentContext && isset($_POST['update_routine_task'])) {
        $routine_task_id = filter_input(INPUT_POST, 'routine_task_id', FILTER_VALIDATE_INT);
        $title = trim((string) filter_input(INPUT_POST, 'edit_rt_title', FILTER_SANITIZE_STRING));
        $description = trim((string) filter_input(INPUT_POST, 'edit_rt_description', FILTER_SANITIZE_STRING));
        $time_limit = filter_input(INPUT_POST, 'edit_rt_time_limit', FILTER_VALIDATE_INT);
        $point_value = filter_input(INPUT_POST, 'edit_rt_point_value', FILTER_VALIDATE_INT);
        $category = filter_input(INPUT_POST, 'edit_rt_category', FILTER_SANITIZE_STRING);

        $updates = [];
        if ($title !== '') {
            $updates['title'] = $title;
        }
        if ($description !== '') {
            $updates['description'] = $description;
        } else {
            $updates['description'] = null;
        }
        if ($time_limit !== false && $time_limit > 0) {
            $updates['time_limit'] = $time_limit;
        }
        if ($point_value !== false && $point_value >= 0) {
            $updates['point_value'] = $point_value;
        }
        $updates['category'] = in_array($category, ['hygiene', 'homework', 'household'], true) ? $category : 'household';

        if (!$routine_task_id || empty($updates)) {
            $messages[] = ['type' => 'error', 'text' => 'Unable to update routine task.'];
        } else {
            if (updateRoutineTask($routine_task_id, $updates)) {
                $messages[] = ['type' => 'success', 'text' => 'Routine task updated.'];
                $routine_tasks = getRoutineTasks($family_root_id);
            } else {
                $messages[] = ['type' => 'error', 'text' => 'Failed to update routine task.'];
            }
        }
    } elseif ($isParentContext && isset($_POST['delete_routine_task'])) {
        $routine_task_id = filter_input(INPUT_POST, 'routine_task_id', FILTER_VALIDATE_INT);
        if ($routine_task_id && deleteRoutineTask($routine_task_id, $family_root_id)) {
            $messages[] = ['type' => 'success', 'text' => 'Routine task removed from the library.'];
            $routine_tasks = getRoutineTasks($family_root_id);
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
    } elseif ($isParentContext && isset($_POST['parent_complete_routine'])) {
        $routine_id = filter_input(INPUT_POST, 'routine_id', FILTER_VALIDATE_INT);
        $completedRaw = $_POST['parent_completed'] ?? [];
        $selected = [];
        if (is_array($completedRaw)) {
            foreach ($completedRaw as $value) {
                $selected[] = (int) $value;
            }
        }
        if (!$routine_id || !routineBelongsToParent($routine_id, $family_root_id)) {
            $messages[] = ['type' => 'error', 'text' => 'Unable to complete routine for this child.'];
        } else {
            $routineData = getRoutineWithTasks($routine_id);
            if (!$routineData) {
                $messages[] = ['type' => 'error', 'text' => 'Routine could not be loaded.'];
            } else {
                $tasks = $routineData['tasks'] ?? [];
                $pendingBefore = 0;
                $taskMap = [];
                foreach ($tasks as $task) {
                    $taskMap[(int) $task['id']] = $task;
                    if (($task['status'] ?? 'pending') !== 'completed') {
                        $pendingBefore++;
                    }
                }
                $selected = array_values(array_unique(array_filter($selected, function ($id) use ($taskMap) {
                    return isset($taskMap[$id]);
                })));

                $awardedPoints = 0;
                foreach ($tasks as $task) {
                    $taskId = (int) $task['id'];
                    $status = in_array($taskId, $selected, true) ? 'completed' : 'pending';
                    setRoutineTaskStatus($routine_id, $taskId, $status);
                    if ($status === 'completed' && (($task['status'] ?? 'pending') !== 'completed')) {
                        $awardedPoints += max(0, (int) ($task['point_value'] ?? 0));
                    }
                }

                $childId = (int) ($routineData['child_user_id'] ?? 0);
                if ($awardedPoints > 0 && $childId > 0) {
                    updateChildPoints($childId, $awardedPoints);
                }
                $awardCount = count($selected);
                $grantBonus = $pendingBefore > 0 && $awardCount > 0 && $awardCount === count($tasks);
                $bonusAwarded = 0;
                if ($childId > 0) {
                    $bonusAwarded = completeRoutine($routine_id, $childId, $grantBonus);
                }
                $summaryParts = [];
                if ($awardedPoints > 0) {
                    $summaryParts[] = "{$awardedPoints} routine points applied";
                }
                if ($grantBonus && $bonusAwarded > 0) {
                    $summaryParts[] = "{$bonusAwarded} bonus points added";
                } elseif ($grantBonus && $bonusAwarded === 0 && (int) ($routineData['bonus_points'] ?? 0) > 0) {
                    $summaryParts[] = 'Bonus points not available outside the routine window';
                } elseif (!$grantBonus && (int) ($routineData['bonus_points'] ?? 0) > 0) {
                    $summaryParts[] = 'Bonus points withheld (not all tasks checked)';
                }
                if (empty($summaryParts)) {
                    $summaryParts[] = 'No points were awarded';
                }
                $messages[] = ['type' => 'success', 'text' => 'Routine updated manually: ' . implode('. ', $summaryParts) . '.'];
            }
        }
    }
}

$routine_tasks = $isParentContext ? getRoutineTasks($family_root_id) : [];
$routines = getRoutines($_SESSION['user_id']);

$childStartingPoints = 0;
if (getEffectiveRole($_SESSION['user_id']) === 'child') {
    $childStartingPoints = getChildTotalPoints((int) $_SESSION['user_id']);
}

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
    'editFormErrors' => array_keys($editFormErrors),
    'childPoints' => $childStartingPoints
];

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
        .collapsible-card { border: none; margin: 12px 0 0; padding: 0; }
        .collapsible-card summary { list-style: none; }
        .collapsible-card summary::-webkit-details-marker,
        .collapsible-card summary::marker { display: none; }
        .collapse-toggle { background: #1e88e5; color: #fff; border: none; padding: 8px 14px; border-radius: 6px; cursor: pointer; font-weight: 600; display: inline-flex; align-items: center; gap: 8px; }
        .collapsible-card[open] .collapse-toggle { background: #1565c0; }
        .collapsible-content { margin-top: 12px; display: none; }
        .collapsible-card[open] .collapsible-content { display: block; }
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
        .routine-task-edit { margin-top: 8px; }
        .routine-task-edit summary { cursor: pointer; font-weight: 600; }
        .routine-task-edit-form { display: grid; gap: 8px; margin-top: 8px; }
        .routine-task-edit-form label { display: flex; flex-direction: column; gap: 4px; font-size: 0.9em; }
        .task-list li.task-completed,
        .checklist li.completed { border-left-color: #4caf50; background: #e8f5e9; }
        .status-pill { display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: 0.8em; background: #eceff1; color: #37474f; margin-left: 6px; text-transform: capitalize; }
        .status-pill.completed { background: #4caf50; color: #fff; }
        .status-pill.pending { background: #ff9800; color: #fff; }
        .sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0; }
        .task-list li { display: flex; align-items: flex-start; gap: 12px; }
        .task-checkbox { display: inline-flex; align-items: center; margin-top: 4px; }
        .task-checkbox input { width: 18px; height: 18px; }
        .parent-complete-form { margin-top: 16px; display: flex; flex-direction: column; gap: 8px; }
        .parent-complete-form .button { align-self: flex-start; }
        .parent-complete-note { font-size: 0.85rem; color: #546e7a; margin: 0; }
        .routine-flow-overlay { position: fixed; inset: 0; display: flex; align-items: center; justify-content: center; background: rgba(10, 24, 64, 0.72); z-index: 1200; opacity: 0; pointer-events: none; transition: opacity 250ms ease; }
        .routine-flow-overlay.active { opacity: 1; pointer-events: auto; }
        .routine-flow-container { width: min(1040px, 95vw); max-height: 90vh; background: linear-gradient(155deg, #7bc4ff, #a077ff); border-radius: 26px; padding: 32px; box-shadow: 0 18px 48px rgba(0,0,0,0.25); color: #fff; display: flex; flex-direction: column; position: relative; overflow: hidden; }
        .routine-flow-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 18px; margin-bottom: 24px; }
        .routine-flow-heading { display: flex; flex-direction: column; gap: 10px; flex: 1; }
        .routine-flow-bar { display: flex; align-items: baseline; justify-content: space-between; gap: 16px; }
        .routine-flow-title { font-size: 1.9rem; font-weight: 700; margin: 0; }
        .routine-flow-next-inline { display: flex; align-items: baseline; gap: 8px; font-size: 1rem; font-weight: 600; color: rgba(255,255,255,0.85); }
        .routine-flow-next-inline .label { text-transform: uppercase; letter-spacing: 0.08em; font-size: 0.78rem; opacity: 0.8; }
        .routine-flow-next-inline .value { font-size: 1.05rem; font-weight: 700; }
        .routine-flow-close { background: #d71919; border: none; color: #fff; font-weight: 600; padding: 8px 18px; border-radius: 999px; cursor: pointer; transition: background 200ms ease; }
        .routine-flow-close:hover { background: #b71515; }
        .routine-flow-stage { flex: 1; display: grid; }
        .routine-scene { display: none; height: 100%; }
        .routine-scene.active { display: grid; grid-template-rows: auto 1fr auto; gap: 24px; }
        .routine-scene-task .task-top { display: grid; gap: 18px; }
        .flow-progress-area { display: grid; gap: 8px; }
        .flow-progress-track { position: relative; height: 34px; background: rgba(255,255,255,0.22); border-radius: 20px; overflow: hidden; }
        .flow-progress-fill { position: absolute; inset: 0; background: linear-gradient(90deg, #43d67e, #8fdc5d); transform: scaleX(0); transform-origin: left center; }
        .flow-countdown { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; font-size: 1.28rem; font-weight: 700; color: #f9f9f9; text-shadow: 0 2px 6px rgba(0,0,0,0.45); letter-spacing: 0.04em; z-index: 1; pointer-events: none; transition: color 200ms ease; }
        .flow-progress-labels { display: flex; align-items: center; justify-content: space-between; font-size: 0.95rem; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 600; }
        .flow-progress-labels .start-label { opacity: 0.85; }
        .flow-progress-labels .limit-label { opacity: 0.85; }
        .routine-scene .illustration { min-height: 240px; border-radius: 22px; background: rgba(255,255,255,0.18); display: flex; align-items: center; justify-content: center; position: relative; overflow: hidden; }
        .routine-scene .illustration::after { content: ''; position: absolute; inset: 10%; border-radius: 20px; border: 2px dashed rgba(255,255,255,0.32); }
        .routine-scene .illustration .character { width: 140px; height: 140px; border-radius: 50%; background: linear-gradient(145deg, #ffe082, #ffca28); position: relative; }
        .routine-scene .illustration .character::after { content: ''; position: absolute; inset: 18px; border-radius: 50%; background: #fff3e0; }
        .routine-primary-button { align-self: flex-end; background: #ffeb3b; border: none; color: #1a237e; font-weight: 800; padding: 12px 36px; border-radius: 18px; font-size: 1.1rem; cursor: pointer; transition: transform 150ms ease, box-shadow 150ms ease; }
        .routine-primary-button:hover { transform: translateY(-2px); box-shadow: 0 10px 24px rgba(0,0,0,0.25); }
        .status-stars { display: flex; gap: 12px; justify-content: center; }
        .status-stars span { width: 60px; height: 60px; background: radial-gradient(circle at 30% 30%, #fff59d, #fbc02d); clip-path: polygon(50% 0%, 61% 35%, 98% 35%, 68% 57%, 79% 91%, 50% 70%, 21% 91%, 32% 57%, 2% 35%, 39% 35%); box-shadow: 0 6px 16px rgba(0,0,0,0.3); opacity: 0.2; transform: scale(0.8); transition: transform 200ms ease, opacity 200ms ease; }
        .status-stars span.active { opacity: 1; transform: scale(1); }
        .status-summary { text-align: center; font-size: 1.1rem; display: grid; gap: 8px; }
        .status-summary strong { font-size: 1.4rem; }
        .summary-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; }
        .summary-card { background: rgba(255,255,255,0.18); border-radius: 14px; padding: 14px 16px; display: flex; justify-content: space-between; font-weight: 600; }
        .summary-footer { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 16px; margin-top: 16px; font-size: 1.05rem; align-items: end; }
        .summary-footer strong { display: block; font-size: 1.6rem; }
        .summary-bonus { text-align: center; font-size: 1rem; font-weight: 600; margin-top: 12px; }
        @media (max-width: 720px) {
            .selected-task-item { grid-template-columns: 1fr; }
            .drag-handle { display: none; }
            .card-actions { flex-direction: column; }
            .routine-flow-container { padding: 22px; border-radius: 20px; }
            .routine-flow-header { flex-direction: column; align-items: stretch; }
            .routine-flow-bar { flex-direction: column; align-items: flex-start; gap: 6px; }
            .routine-flow-title { font-size: 1.6rem; }
            .routine-flow-next-inline { align-items: flex-start; }
            .routine-primary-button { width: 100%; text-align: center; }
        }
    </style>
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
</head>
<body<?php echo !empty($bodyClasses) ? ' class="' . implode(' ', $bodyClasses) . '"' : ''; ?>>
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
                                            <details class="routine-task-edit">
                                                <summary>Edit</summary>
                                                <form method="POST" class="routine-task-edit-form">
                                                    <input type="hidden" name="routine_task_id" value="<?php echo (int) $task['id']; ?>">
                                                    <label>
                                                        Title
                                                        <input type="text" name="edit_rt_title" value="<?php echo htmlspecialchars($task['title']); ?>" required>
                                                    </label>
                                                    <label>
                                                        Description
                                                        <textarea name="edit_rt_description" rows="2"><?php echo htmlspecialchars($task['description'] ?? ''); ?></textarea>
                                                    </label>
                                                    <label>
                                                        Time Limit (min)
                                                        <input type="number" name="edit_rt_time_limit" min="1" value="<?php echo (int) $task['time_limit']; ?>" required>
                                                    </label>
                                                    <label>
                                                        Point Value
                                                        <input type="number" name="edit_rt_point_value" min="0" value="<?php echo (int) $task['point_value']; ?>">
                                                    </label>
                                                    <label>
                                                        Category
                                                        <select name="edit_rt_category">
                                                            <option value="hygiene" <?php echo ($task['category'] === 'hygiene') ? 'selected' : ''; ?>>Hygiene</option>
                                                            <option value="homework" <?php echo ($task['category'] === 'homework') ? 'selected' : ''; ?>>Homework</option>
                                                            <option value="household" <?php echo ($task['category'] === 'household') ? 'selected' : ''; ?>>Household</option>
                                                        </select>
                                                    </label>
                                                    <button type="submit" name="update_routine_task" class="button">Save Changes</button>
                                                </form>
                                            </details>
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
                        <details class="collapsible-card" data-role="collapsible-wrapper">
                            <summary class="collapse-toggle">Toggle Routine Details</summary>
                            <div class="collapsible-content" data-role="collapsible">
                            <ul class="task-list">
                                <?php foreach ($routine['tasks'] as $task): ?>
                                    <?php
                                        $taskStatus = $task['status'] ?? 'pending';
                                        $isCompleted = ($taskStatus === 'completed');
                                        $itemClasses = [];
                                        if ($isCompleted) {
                                            $itemClasses[] = $isChildView ? 'task-completed' : 'completed';
                                        }
                                        $classAttr = !empty($itemClasses) ? ' class="' . implode(' ', $itemClasses) . '"' : '';
                                    ?>
                                    <li data-routine-task-id="<?php echo (int) $task['id']; ?>"<?php echo $classAttr; ?>>
                                        <?php if ($isChildView): ?>
                                            <input class="task-checkbox" type="checkbox" <?php echo ($taskStatus === 'completed') ? 'checked' : ''; ?> disabled>
                                        <?php elseif ($isParentContext): ?>
                                            <label class="task-checkbox">
                                                <input type="checkbox" name="parent_completed[]" value="<?php echo (int) $task['id']; ?>" form="parent-complete-form-<?php echo (int) $routine['id']; ?>" <?php echo $isCompleted ? 'checked' : ''; ?>>
                                                <span class="sr-only">Mark <?php echo htmlspecialchars($task['title']); ?> completed</span>
                                            </label>
                                        <?php endif; ?>
                                        <strong><?php echo htmlspecialchars($task['title']); ?></strong>
                                        <div class="task-meta">
                                            <?php echo (int) $task['time_limit']; ?> min
                                            <span class="status-pill status-<?php echo htmlspecialchars($taskStatus); ?> <?php echo htmlspecialchars($taskStatus); ?>">
                                                <?php echo htmlspecialchars($taskStatus); ?>
                                            </span>
                                        </div>
                                        <?php if (!empty($task['dependency_id'])): ?>
                                            <div class="dependency">Depends on Task ID: <?php echo (int) $task['dependency_id']; ?></div>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                            <?php if ($isChildView): ?>
                                <div class="card-actions">
                                    <button type="button" class="button start-next-button" data-action="open-flow">Start Routine</button>
                                </div>
                                <div class="routine-flow-overlay" data-role="routine-flow" aria-hidden="true">
                                    <div class="routine-flow-container" role="dialog" aria-modal="true">
                                        <header class="routine-flow-header">
                                            <div class="routine-flow-heading">
                                                <div class="routine-flow-bar">
                                                    <h2 class="routine-flow-title" data-role="flow-title">Ready to begin</h2>
                                                    <div class="routine-flow-next-inline">
                                                        <span class="label">Next</span>
                                                        <span class="value" data-role="flow-next-label">First task</span>
                                                    </div>
                                                </div>
                                            </div>
                        <button type="button" class="routine-flow-close" data-action="flow-exit">Stop</button>
                                        </header>
                                        <main class="routine-flow-stage">
                                            <section class="routine-scene routine-scene-task active" data-scene="task">
                                                <div class="task-top">
                                                    <div class="flow-progress-area">
                                                        <div class="flow-progress-track">
                                                            <div class="flow-progress-fill" data-role="flow-progress"></div>
                                                            <span class="flow-countdown" data-role="flow-countdown">--:--</span>
                                                        </div>
                                                        <div class="flow-progress-labels">
                                                            <span class="start-label">Start</span>
                                                            <span class="limit-label" data-role="flow-limit">Time Limit: --</span>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="illustration">
                                                    <div class="character"></div>
                                                </div>
                                                <button type="button" class="routine-primary-button" data-action="flow-complete-task">Next</button>
                                            </section>
                                            <section class="routine-scene routine-scene-status" data-scene="status">
                                                <div class="status-stars">
                                                    <span></span>
                                                    <span></span>
                                                    <span></span>
                                                </div>
                                                <div class="status-summary">
                                                    <strong data-role="status-points">+0 points</strong>
                                                    <span data-role="status-time">You finished in 0:00.</span>
                                                    <span data-role="status-feedback">Great job!</span>
                                                </div>
                                                <button type="button" class="routine-primary-button" data-action="flow-next-task">Next Task</button>
                                            </section>
                                            <section class="routine-scene routine-scene-summary" data-scene="summary">
                                                <div class="summary-grid" data-role="summary-list"></div>
                                                <p class="summary-bonus" data-role="summary-bonus"></p>
                                                <div class="summary-footer">
                                                    <div>
                                                        <span>Routine Points</span>
                                                        <strong data-role="summary-total">0</strong>
                                                    </div>
                                                    <div>
                                                        <span>Bonus Points</span>
                                                        <strong data-role="summary-bonus-total">0</strong>
                                                    </div>
                                                    <div>
                                                        <span>Total Points Now</span>
                                                        <strong data-role="summary-account-total">0</strong>
                                                    </div>
                                                </div>
                                                <button type="button" class="routine-primary-button" data-action="flow-finish">Done</button>
                                            </section>
                                        </main>
                                    </div>
                                </div>
                            <?php elseif ($isParentContext): ?>
                                <form method="POST" action="routine.php" class="parent-complete-form" id="parent-complete-form-<?php echo (int) $routine['id']; ?>">
                                    <input type="hidden" name="routine_id" value="<?php echo (int) $routine['id']; ?>">
                                    <button type="submit" name="parent_complete_routine" class="button">Complete Routine</button>
                                    <p class="parent-complete-note">Check the tasks completed to award points. Bonus points apply only when all tasks are checked.</p>
                                </form>
                                <details class="routine-section" style="margin-top: 16px; background: rgba(250,250,250,0.9);">
                                    <summary><strong>Edit Routine</strong></summary>
                                    <?php
                                        $rid = (int) $routine['id'];
                                        $override = $editFieldOverrides[$rid] ?? null;
                                        $titleValue = htmlspecialchars($override['title'] ?? $routine['title'], ENT_QUOTES);
                                        $startRaw = $override['start_time'] ?? $routine['start_time'];
                                        $endRaw = $override['end_time'] ?? $routine['end_time'];
                                        $startValue = htmlspecialchars(substr($startRaw, 0, 5), ENT_QUOTES);
                                        $endValue = htmlspecialchars(substr($endRaw, 0, 5), ENT_QUOTES);
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
                        </details>
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

            function formatCountdownDisplay(seconds) {
                if (!Number.isFinite(seconds)) {
                    return '--:--';
                }
                if (seconds >= 0) {
                    const safe = Math.max(0, Math.ceil(seconds));
                    const minutes = Math.floor(safe / 60);
                    const secs = safe % 60;
                    return `${minutes}:${secs.toString().padStart(2, '0')}`;
                }
                const over = Math.ceil(Math.abs(seconds));
                const minutes = Math.floor(over / 60);
                const secs = over % 60;
                return `+${minutes}:${secs.toString().padStart(2, '0')}`;
            }

            function calculateRoutineTaskAwardPoints(pointValue, scheduledSeconds, actualSeconds) {
                const points = Math.max(0, parseInt(pointValue, 10) || 0);
                const normaliseSeconds = (value) => {
                    if (Number.isFinite(value)) {
                        return value;
                    }
                    const numeric = parseFloat(value);
                    return Number.isFinite(numeric) ? numeric : 0;
                };
                const scheduled = Math.max(0, Math.floor(normaliseSeconds(scheduledSeconds)));
                const actual = Math.max(0, Math.floor(normaliseSeconds(actualSeconds)));
                if (points === 0) {
                    return 0;
                }
                if (scheduled === 0) {
                    return points;
                }
                if (actual <= scheduled) {
                    return points;
                }
                if (actual <= scheduled + 60) {
                    return Math.max(1, Math.ceil(points / 2));
                }
                return 0;
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
                        handle.textContent = String.fromCharCode(0x283F);

                        const body = document.createElement('div');
                        const title = document.createElement('div');
                        title.innerHTML = `<strong>${taskData.title}</strong>`;
                        const meta = document.createElement('div');
                        meta.className = 'task-meta';
                        meta.textContent = `${taskData.time_limit || 0} min ${String.fromCharCode(0x2022)} ${taskData.category}`;
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
                            if (allowedTask.id === task.dependency_id) {
                                option.selected = true;
                            }
                            select.appendChild(option);
                        }
                        if (task.dependency_id !== null) {
                            select.value = String(task.dependency_id);
                        } else {
                            select.value = '';
                        }
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
                    this.preferences = preferences || {};
                    this.subTimerLabelMap = labelMap || {};
                    this.tasks = Array.isArray(routine.tasks) ? [...routine.tasks] : [];
                    this.tasks.sort((a, b) => (parseInt(a.sequence_order, 10) || 0) - (parseInt(b.sequence_order, 10) || 0));

                    this.openButton = card.querySelector("[data-action='open-flow']");
                    this.overlay = card.querySelector("[data-role='routine-flow']");
                    this.flowTitleEl = this.overlay ? this.overlay.querySelector("[data-role='flow-title']") : null;
                    this.nextLabelEl = this.overlay ? this.overlay.querySelector("[data-role='flow-next-label']") : null;
                    this.progressFillEl = this.overlay ? this.overlay.querySelector("[data-role='flow-progress']") : null;
                    this.countdownEl = this.overlay ? this.overlay.querySelector("[data-role='flow-countdown']") : null;
                    this.limitLabelEl = this.overlay ? this.overlay.querySelector("[data-role='flow-limit']") : null;
                    this.statusPointsEl = this.overlay ? this.overlay.querySelector("[data-role='status-points']") : null;
                    this.statusTimeEl = this.overlay ? this.overlay.querySelector("[data-role='status-time']") : null;
                    this.statusFeedbackEl = this.overlay ? this.overlay.querySelector("[data-role='status-feedback']") : null;
                    this.statusStars = this.overlay ? Array.from(this.overlay.querySelectorAll('.status-stars span')) : [];
                    this.summaryListEl = this.overlay ? this.overlay.querySelector("[data-role='summary-list']") : null;
                    this.summaryTotalEl = this.overlay ? this.overlay.querySelector("[data-role='summary-total']") : null;
                    this.summaryAccountEl = this.overlay ? this.overlay.querySelector("[data-role='summary-account-total']") : null;
                    this.summaryBonusTotalEl = this.overlay ? this.overlay.querySelector("[data-role='summary-bonus-total']") : null;
                    this.summaryBonusEl = this.overlay ? this.overlay.querySelector("[data-role='summary-bonus']") : null;
                    this.bonusPossible = Math.max(0, parseInt(this.routine.bonus_points, 10) || 0);
                    this.bonusAwarded = 0;

                    this.sceneMap = new Map();
                    if (this.overlay) {
                        this.overlay.querySelectorAll('.routine-scene').forEach(scene => {
                            this.sceneMap.set(scene.dataset.scene, scene);
                        });
                    }
                    this.exitButton = this.overlay ? this.overlay.querySelector("[data-action='flow-exit']") : null;
                    this.completeButton = this.overlay ? this.overlay.querySelector("[data-action='flow-complete-task']") : null;
                    this.statusNextButton = this.overlay ? this.overlay.querySelector("[data-action='flow-next-task']") : null;
                    this.finishButton = this.overlay ? this.overlay.querySelector("[data-action='flow-finish']") : null;

                    this.currentIndex = 0;
                    this.currentTask = null;
                    this.taskStartTime = null;
                    this.elapsedSeconds = 0;
                    this.scheduledSeconds = 0;
                    this.taskAnimationFrame = null;
                    this.overtimeBuffer = [];
                    this.taskResults = [];
                    this.totalEarnedPoints = 0;
                    this.allWithinLimit = true;
                    this.childPoints = typeof page.childPoints === 'number' ? page.childPoints : 0;

                    this.init();
                }

                init() {
                    if (!this.openButton || !this.overlay) {
                        return;
                    }
                    this.openButton.addEventListener('click', () => this.openFlow());
                    if (this.exitButton) {
                        this.exitButton.addEventListener('click', () => this.handleExit());
                    }
                    if (this.completeButton) {
                        this.completeButton.addEventListener('click', () => this.handleTaskComplete());
                    }
                    if (this.statusNextButton) {
                        this.statusNextButton.addEventListener('click', () => this.advanceFromStatus());
                    }
                    if (this.finishButton) {
                        this.finishButton.addEventListener('click', () => this.closeOverlay(false));
                    }
                    this.updateNextLabel();
                }

                openFlow() {
                    if (!this.tasks.length) {
                        alert('No tasks are available in this routine yet.');
                        return;
                    }
                    this.overlay.classList.add('active');
                    this.overlay.setAttribute('aria-hidden', 'false');
                    this.startRoutine();
                }

                handleExit() {
                    this.closeOverlay();
                    this.resetRoutineStatuses();
                    this.tasks.forEach(task => this.markTaskPending(task.id));
                    this.taskResults = [];
                    this.totalEarnedPoints = 0;
                    this.overtimeBuffer = [];
                    if (this.openButton) {
                        this.openButton.textContent = 'Start Routine';
                    }
                    if (this.completeButton) {
                        this.completeButton.disabled = false;
                    }
                    if (this.countdownEl) {
                        this.countdownEl.textContent = '--:--';
                        this.countdownEl.style.color = '#f9f9f9';
                        this.countdownEl.style.textShadow = '0 2px 6px rgba(0,0,0,0.45)';
                    }
                }

                closeOverlay(resetTitle = true) {
                    this.stopTaskAnimation();
                    if (this.overlay) {
                        this.overlay.classList.remove('active');
                        this.overlay.setAttribute('aria-hidden', 'true');
                    }
                    if (resetTitle && this.flowTitleEl) {
                        this.flowTitleEl.textContent = this.routine.title || 'Routine';
                    }
                    if (this.summaryBonusEl) {
                        this.summaryBonusEl.textContent = '';
                    }
                    if (this.completeButton) {
                        this.completeButton.disabled = false;
                    }
                }

                startRoutine() {
                    this.resetRoutineStatuses();
                    this.tasks.forEach(task => {
                        task.status = 'pending';
                        this.markTaskPending(task.id);
                    });
                    this.taskResults = [];
                    this.totalEarnedPoints = 0;
                    this.overtimeBuffer = [];
                    this.allWithinLimit = true;
                    this.bonusAwarded = 0;
                    this.currentIndex = 0;
                    this.childPoints = typeof page.childPoints === 'number' ? page.childPoints : this.childPoints;
                    if (this.openButton) {
                        this.openButton.textContent = 'Restart Routine';
                    }
                    this.showScene('task');
                    this.startTask(this.currentIndex);
                    if (this.summaryBonusTotalEl) {
                        this.summaryBonusTotalEl.textContent = '0';
                    }
                    if (this.summaryBonusEl) {
                        this.summaryBonusEl.textContent = '';
                    }
                }

                startTask(index) {
                    if (index >= this.tasks.length) {
                        this.displaySummary();
                        return;
                    }
                    if (this.completeButton) {
                        this.completeButton.disabled = false;
                    }
                    this.currentTask = this.tasks[index];
                    this.scheduledSeconds = Math.max(0, (parseInt(this.currentTask.time_limit, 10) || 0) * 60);
                    this.taskStartTime = null;
                    this.elapsedSeconds = 0;
                    this.stopTaskAnimation();
                    this.updateTaskHeader();
                    this.updateNextLabel();
                    this.updateTimeLimitLabel();
                    if (this.countdownEl) {
                        this.countdownEl.style.color = '#f9f9f9';
                        this.countdownEl.style.textShadow = '0 2px 6px rgba(0,0,0,0.45)';
                    }
                    this.updateProgressDisplay();
                    this.startTaskAnimation();
                }

                startTaskAnimation() {
                    const step = (timestamp) => {
                        if (this.taskStartTime === null) {
                            this.taskStartTime = timestamp;
                        }
                        const elapsedMs = timestamp - this.taskStartTime;
                        this.elapsedSeconds = elapsedMs / 1000;
                        this.updateProgressDisplay();
                        this.taskAnimationFrame = requestAnimationFrame(step);
                    };
                    this.taskAnimationFrame = requestAnimationFrame(step);
                }

                stopTaskAnimation() {
                    if (this.taskAnimationFrame) {
                        cancelAnimationFrame(this.taskAnimationFrame);
                        this.taskAnimationFrame = null;
                    }
                }

                updateProgressDisplay() {
                    if (!this.progressFillEl) return;
                    const scheduled = this.scheduledSeconds;
                    let progress = 1;
                    let displayValue = '--:--';
                    let isOvertime = false;

                    if (scheduled > 0) {
                        progress = Math.min(1, this.elapsedSeconds / Math.max(1, scheduled));
                        const remaining = scheduled - this.elapsedSeconds;
                        displayValue = formatCountdownDisplay(remaining);
                        isOvertime = remaining < 0;
                    } else {
                        displayValue = formatSeconds(Math.ceil(this.elapsedSeconds));
                    }

                    this.progressFillEl.style.transform = `scaleX(${Math.max(0, Math.min(1, progress))})`;
                    if (this.countdownEl) {
                        this.countdownEl.textContent = displayValue;
                        if (isOvertime) {
                            this.countdownEl.style.color = '#d71919';
                            this.countdownEl.style.textShadow = '0 2px 6px rgba(0,0,0,0.6)';
                        } else {
                            this.countdownEl.style.color = '#f9f9f9';
                            this.countdownEl.style.textShadow = '0 2px 6px rgba(0,0,0,0.45)';
                        }
                    }
                }

                updateTimeLimitLabel() {
                    if (!this.limitLabelEl) return;
                    if (this.scheduledSeconds > 0) {
                        this.limitLabelEl.textContent = `Time Limit: ${formatSeconds(this.scheduledSeconds)}`;
                    } else {
                        this.limitLabelEl.textContent = 'Time Limit: --';
                    }
                }

                handleTaskComplete() {
                    if (!this.currentTask) return;
                    this.stopTaskAnimation();
                    if (this.completeButton) {
                        this.completeButton.disabled = true;
                    }
                    const actualSeconds = Math.ceil(Math.max(0, this.elapsedSeconds));
                    const scheduled = this.scheduledSeconds;
                    const pointValue = parseInt(this.currentTask.point_value, 10) || 0;
                    if (scheduled > 0 && actualSeconds > scheduled) {
                        this.overtimeBuffer.push({
                            routine_id: parseInt(this.routine.id, 10) || 0,
                            routine_task_id: parseInt(this.currentTask.id, 10) || 0,
                            child_user_id: parseInt(this.routine.child_user_id, 10) || 0,
                            scheduled_seconds: scheduled,
                            actual_seconds: actualSeconds,
                            overtime_seconds: actualSeconds - scheduled
                        });
                        this.allWithinLimit = false;
                    }
                    const awardedPoints = calculateRoutineTaskAwardPoints(pointValue, scheduled, actualSeconds);
                    if (scheduled > 0 && actualSeconds > scheduled) {
                        this.allWithinLimit = false;
                    }
                    this.totalEarnedPoints += awardedPoints;
                    this.taskResults.push({
                        id: parseInt(this.currentTask.id, 10) || 0,
                        title: this.currentTask.title,
                        point_value: pointValue,
                        actual_seconds: actualSeconds,
                        scheduled_seconds: scheduled,
                        awarded_points: awardedPoints
                    });

                    this.updateTaskStatus(this.currentTask.id, 'completed');
                    this.currentTask.status = 'completed';
                    this.markTaskCompleted(this.currentTask.id);
                    this.presentStatus(awardedPoints, actualSeconds, scheduled);
                    this.currentIndex += 1;
                }

                presentStatus(points, actualSeconds, scheduledSeconds) {
                    if (this.statusPointsEl) {
                        this.statusPointsEl.textContent = `+${points} points`;
                    }
                    if (this.statusTimeEl) {
                        this.statusTimeEl.textContent = `You finished in ${formatSeconds(actualSeconds)}.`;
                    }
                    if (this.statusFeedbackEl) {
                        let feedback = 'Nice work!';
                        let stars = 1;
                        if (scheduledSeconds <= 0) {
                            feedback = 'No timer on this task—keep up the pace!';
                            stars = 3;
                        } else {
                            const ratio = actualSeconds / Math.max(1, scheduledSeconds);
                            if (ratio <= 1) {
                                feedback = 'Right on time!';
                                stars = 3;
                            } else if (ratio <= 1.4) {
                                feedback = 'A little late—half points earned.';
                                stars = 2;
                            } else {
                                feedback = 'Over the limit—no points this time.';
                                stars = 1;
                            }
                        }
                        this.statusFeedbackEl.textContent = feedback;
                        this.statusStars.forEach((star, idx) => {
                            if (idx < stars) {
                                star.classList.add('active');
                            } else {
                                star.classList.remove('active');
                            }
                        });
                    }
                    this.showScene('status');
                    if (this.statusNextButton) {
                        const label = this.currentIndex + 1 < this.tasks.length ? 'Next Task' : 'Summary';
                        this.statusNextButton.textContent = label;
                    }
                }

                advanceFromStatus() {
                    if (this.completeButton) {
                        this.completeButton.disabled = false;
                    }
                    if (this.currentIndex < this.tasks.length) {
                        this.showScene('task');
                        this.startTask(this.currentIndex);
                    } else {
                        this.displaySummary();
                    }
                }

                displaySummary() {
                    if (this.completeButton) {
                        this.completeButton.disabled = true;
                    }
                    this.showScene('summary');
                    this.renderSummary(this.taskResults);
                    this.finalizeRoutine();
                }

                renderSummary(results) {
                    if (this.summaryListEl) {
                        this.summaryListEl.innerHTML = '';
                        results.forEach(result => {
                            const card = document.createElement('div');
                            card.className = 'summary-card';
                            const title = document.createElement('span');
                            title.textContent = result.title;
                            const points = document.createElement('span');
                            points.textContent = `+${result.awarded_points}`;
                            card.append(title, points);
                            this.summaryListEl.appendChild(card);
                        });
                    }
                    if (this.summaryTotalEl) {
                        this.summaryTotalEl.textContent = this.totalEarnedPoints;
                    }
                    if (this.summaryBonusTotalEl) {
                        this.summaryBonusTotalEl.textContent = String(this.bonusAwarded);
                    }
                    if (this.summaryAccountEl) {
                        this.summaryAccountEl.textContent = this.childPoints;
                    }
                }

                finalizeRoutine() {
                    const payload = new FormData();
                    payload.append('action', 'complete_routine_flow');
                    payload.append('routine_id', this.routine.id);
                    const metrics = this.taskResults.map(result => ({
                        id: result.id,
                        actual_seconds: result.actual_seconds,
                        scheduled_seconds: result.scheduled_seconds
                    }));
                    payload.append('task_metrics', JSON.stringify(metrics));
                    fetch('routine.php', { method: 'POST', body: payload })
                        .then(response => response.json())
                        .then(data => {
                            if (data && data.status === 'duplicate') {
                                if (this.summaryBonusEl) {
                                    this.summaryBonusEl.textContent = data.message || '';
                                }
                                return;
                            }
                            if (Array.isArray(data.task_results)) {
                                this.taskResults = data.task_results;
                                this.renderSummary(this.taskResults);
                            }
                            if (typeof data.new_total_points === 'number') {
                                this.childPoints = data.new_total_points;
                                page.childPoints = this.childPoints;
                                if (this.summaryAccountEl) {
                                    this.summaryAccountEl.textContent = this.childPoints;
                                }
                            }
                            if (typeof data.task_points_awarded === 'number') {
                                this.totalEarnedPoints = data.task_points_awarded;
                                if (this.summaryTotalEl) {
                                    this.summaryTotalEl.textContent = data.task_points_awarded;
                                }
                            }
                            const bonusPossible = typeof data.bonus_possible === 'number' ? data.bonus_possible : this.bonusPossible;
                            if (typeof data.bonus_possible === 'number') {
                                this.bonusPossible = data.bonus_possible;
                            }
                            const bonusAwarded = typeof data.bonus_points_awarded === 'number' ? data.bonus_points_awarded : 0;
                            this.bonusAwarded = bonusAwarded;
                            if (this.summaryBonusTotalEl) {
                                this.summaryBonusTotalEl.textContent = String(bonusAwarded);
                            }
                            const bonusEligible = typeof data.bonus_eligible === 'boolean' ? data.bonus_eligible : !!data.bonus_eligible;
                            if (this.summaryBonusEl) {
                                let message = '';
                                if (bonusPossible > 0) {
                                    if (bonusAwarded > 0) {
                                        message = `Bonus earned: +${bonusAwarded}`;
                                    } else if (!bonusEligible) {
                                        message = 'Bonus locked: finish every task on time.';
                                    }
                                }
                                this.summaryBonusEl.textContent = message;
                            }
                        })
                        .catch(() => {
                            if (this.summaryBonusEl) {
                                this.summaryBonusEl.textContent = 'Could not update totals—check your connection.';
                            }
                        })
                        .finally(() => {
                            this.sendOvertimeLogs();
                        });
                }

                showScene(name) {
                    this.sceneMap.forEach((scene, key) => {
                        if (key === name) {
                            scene.classList.add('active');
                        } else {
                            scene.classList.remove('active');
                        }
                    });
                    if (name === 'summary' && this.summaryAccountEl) {
                        this.summaryAccountEl.textContent = this.childPoints;
                    }
                }

                updateTaskHeader() {
                    if (this.flowTitleEl) {
                        this.flowTitleEl.textContent = this.currentTask ? this.currentTask.title : 'Routine Task';
                    }
                }

                updateNextLabel() {
                    if (!this.nextLabelEl) return;
                    const nextTask = this.tasks[this.currentIndex + 1];
                    this.nextLabelEl.textContent = nextTask ? nextTask.title : 'All done!';
                }

                resetRoutineStatuses() {
                    const payload = new FormData();
                    payload.append('action', 'reset_routine_tasks');
                    payload.append('routine_id', this.routine.id);
                    fetch('routine.php', { method: 'POST', body: payload }).catch(() => {});
                }

                updateTaskStatus(taskId, status) {
                    const payload = new FormData();
                    payload.append('action', 'set_routine_task_status');
                    payload.append('routine_id', this.routine.id);
                    payload.append('routine_task_id', taskId);
                    payload.append('status', status);
                    fetch('routine.php', { method: 'POST', body: payload }).catch(() => {});
                }

                markTaskCompleted(taskId) {
                    const item = this.card.querySelector(`li[data-routine-task-id="${taskId}"]`);
                    if (item) {
                        item.classList.add('task-completed');
                        const checkbox = item.querySelector('input[type="checkbox"]');
                        if (checkbox) checkbox.checked = true;
                        const pill = item.querySelector('.status-pill');
                        if (pill) {
                            pill.textContent = 'completed';
                            pill.classList.add('completed');
                            pill.classList.remove('pending');
                        }
                    }
                }

                markTaskPending(taskId) {
                    const item = this.card.querySelector(`li[data-routine-task-id="${taskId}"]`);
                    if (item) {
                        item.classList.remove('task-completed');
                        const checkbox = item.querySelector('input[type="checkbox"]');
                        if (checkbox) checkbox.checked = false;
                        const pill = item.querySelector('.status-pill');
                        if (pill) {
                            pill.textContent = 'pending';
                            pill.classList.remove('completed');
                            pill.classList.add('pending');
                        }
                    }
                }

                sendOvertimeLogs() {
                    if (!this.overtimeBuffer.length) {
                        return;
                    }
                    const payload = new FormData();
                    payload.append('action', 'log_overtime');
                    payload.append('overtime_payload', JSON.stringify(this.overtimeBuffer));
                    fetch('routine.php', { method: 'POST', body: payload }).catch(() => {});
                    this.overtimeBuffer = [];
                }
            }

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






































