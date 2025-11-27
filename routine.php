<?php
// routine.php - Routine management (Phase 5 upgrade)
// Provides parent routine builder with validation, timer warnings for children, and overtime tracking.

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
        $min_minutes_input = filter_input(INPUT_POST, 'rt_min_time', FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);

        $time_limit = ($time_limit !== false && $time_limit > 0) ? $time_limit : null;
        $point_value = ($point_value !== false && $point_value >= 0) ? $point_value : 0;
        $category = in_array($category, ['hygiene', 'homework', 'household'], true) ? $category : 'household';
        $minimum_seconds = null;
        if ($min_minutes_input !== null && $min_minutes_input !== false && $min_minutes_input !== '') {
            $min_minutes = (float) $min_minutes_input;
            if ($min_minutes >= 0) {
                $minimum_seconds = (int) round($min_minutes * 60);
            }
        }
        $min_toggle = ($minimum_seconds !== null && $minimum_seconds > 0) ? 1 : 0;

        if ($title === '' || $time_limit === null) {
            $messages[] = ['type' => 'error', 'text' => 'Routine task needs a title and a positive time limit.'];
        } else {
            if (createRoutineTask($family_root_id, $title, $description, $time_limit, $point_value, $category, $minimum_seconds, $min_toggle, null, null, $_SESSION['user_id'])) {
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

        if (!isset($routine_tasks) || !is_array($routine_tasks)) {
            $routine_tasks = getRoutineTasks($family_root_id);
        }
        $existingTask = null;
        foreach ($routine_tasks as $candidateTask) {
            if ((int) ($candidateTask['id'] ?? 0) === (int) $routine_task_id) {
                $existingTask = $candidateTask;
                break;
            }
        }
        $minToggle = isset($_POST['edit_rt_min_enabled']) ? 1 : 0;
        $min_minutes_input = filter_input(INPUT_POST, 'edit_rt_min_minutes', FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
        $minSecondsFromInput = null;
        if ($min_minutes_input !== null && $min_minutes_input !== false && $min_minutes_input !== '') {
            $minMinutesCast = (float) $min_minutes_input;
            if ($minMinutesCast >= 0) {
                $minSecondsFromInput = (int) round($minMinutesCast * 60);
            }
        }
        $existingMinSeconds = $existingTask ? (int) ($existingTask['minimum_seconds'] ?? 0) : 0;
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
        if ($minSecondsFromInput !== null) {
            if ($minSecondsFromInput > 0) {
                $updates['minimum_seconds'] = $minSecondsFromInput;
            } else {
                $updates['minimum_seconds'] = null;
            }
        }
        $minimumToggleError = false;
        if ($minToggle) {
            $effectiveMin = null;
            if (array_key_exists('minimum_seconds', $updates)) {
                $effectiveMin = $updates['minimum_seconds'];
            } else {
                $effectiveMin = $existingMinSeconds;
            }
            if ($effectiveMin !== null && $effectiveMin > 0) {
                $updates['minimum_enabled'] = 1;
            } else {
                $updates['minimum_enabled'] = 0;
                $minimumToggleError = true;
            }
        } else {
            $updates['minimum_enabled'] = 0;
        }

        if (!$routine_task_id || empty($updates)) {
            $messages[] = ['type' => 'error', 'text' => 'Unable to update routine task.'];
        } else {
            if (updateRoutineTask($routine_task_id, $updates)) {
                $messages[] = ['type' => 'success', 'text' => 'Routine task updated.'];
                $routine_tasks = getRoutineTasks($family_root_id);
                if ($minimumToggleError) {
                    $messages[] = ['type' => 'info', 'text' => 'Minimum time remained disabled because no positive duration was provided.'];
                }
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
        $timerWarnings = isset($_POST['timer_warnings_enabled']) ? 1 : 0;
        $showCountdown = isset($_POST['show_countdown']) ? 1 : 0;
        $label = $routinePreferences['sub_timer_label'] ?? 'hurry_goal';
        if (!is_string($label) || $label === '') {
            $label = 'hurry_goal';
        }
        $progressStyle = isset($_POST['progress_style']) ? $_POST['progress_style'] : 'bar';
        if (saveRoutinePreferences($family_root_id, $timerWarnings, $label, $showCountdown, $progressStyle)) {
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

foreach ($routines as &$routineEntry) {
    $timerWarningEnabled = !empty($routinePreferences['timer_warnings_enabled']) ? 1 : 0;
    $routineEntry['timer_warnings_enabled'] = $timerWarningEnabled;
    $routineEntry['show_countdown'] = isset($routinePreferences['show_countdown'])
        ? (int) $routinePreferences['show_countdown']
        : 1;
}
unset($routineEntry);

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

$pagePreferences = [
    'timer_warnings_enabled' => isset($routinePreferences['timer_warnings_enabled'])
        ? (int) $routinePreferences['timer_warnings_enabled']
        : 1,
    'show_countdown' => isset($routinePreferences['show_countdown'])
        ? (int) $routinePreferences['show_countdown']
        : 1,
    'progress_style' => isset($routinePreferences['progress_style']) && in_array($routinePreferences['progress_style'], ['bar', 'circle', 'pie'], true)
        ? $routinePreferences['progress_style']
        : 'bar'
];

$jsonOptions = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP;
$pageState = [
    'tasks' => $routine_tasks,
    'createInitial' => $createBuilderInitial,
    'editInitial' => $editBuilderInitial,
    'routines' => $routines,
    'preferences' => $pagePreferences,
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
        .routine-card-grid { display: grid; gap: 20px; grid-template-columns: repeat(2, minmax(0, 1fr)); }
        @media (max-width: 768px) {
            .routine-card-grid { grid-template-columns: 1fr; }
        }
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
        .routine-section-header { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; }
        .task-modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.65); display: flex; align-items: center; justify-content: center; padding: 20px; z-index: 2000; opacity: 0; pointer-events: none; transition: opacity 200ms ease; }
        .task-modal-overlay.active { opacity: 1; pointer-events: auto; }
        .task-modal { background: #fff; border-radius: 14px; max-width: 520px; width: min(520px, 100%); max-height: 90vh; overflow-y: auto; padding: 28px; position: relative; box-shadow: 0 18px 36px rgba(0,0,0,0.25); }
        .task-modal h3 { margin-top: 0; }
        .task-modal-close { position: absolute; top: 12px; right: 12px; border: none; background: transparent; font-size: 1.5rem; line-height: 1; cursor: pointer; color: #455a64; }
        .summary-row { display: flex; flex-wrap: wrap; gap: 16px; font-weight: 600; margin-top: 12px; }
        .summary-row .warning { color: #c62828; }
        .library-card-list { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 16px; margin: 12px auto 0; }
        @media (min-width: 900px) {
            .library-card-list { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        }
        @media (min-width: 1300px) {
            .library-card-list { grid-template-columns: repeat(4, minmax(0, 1fr)); }
        }
        .library-task-card { background: #fff; border: 1px solid #e0e0e0; border-radius: 10px; padding: 16px; display: flex; flex-direction: column; gap: 10px; box-shadow: 0 2px 6px rgba(0,0,0,0.05); }
        .library-task-card header { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
        .library-task-card h4 { margin: 0; font-size: 1.1rem; }
        .library-task-points { font-weight: 700; color: #1e88e5; }
        .library-task-description { margin: 0; font-size: 0.9rem; color: #546e7a; }
        .library-task-meta { display: flex; flex-wrap: wrap; gap: 8px; font-size: 0.85rem; color: #37474f; }
        .library-task-meta span { background: #f0f4f7; border-radius: 999px; padding: 4px 10px; }
        .library-task-actions { margin-top: auto; display: flex; flex-direction: column; gap: 8px; }
        .routine-card { border: 1px solid #e0e0e0; border-radius: 12px; padding: 18px; margin-bottom: 20px; background: linear-gradient(145deg, #ffffff, #f5f5f5); box-shadow: 0 3px 8px rgba(0,0,0,0.08); }
        .routine-card.child-view { background: linear-gradient(160deg, #e3f2fd, #e8f5e9); border-color: #bbdefb; }
        .routine-card header { display: flex; flex-direction: column; gap: 4px; margin-bottom: 12px; }
        .routine-card h3 { margin: 0; font-size: 1.25rem; }
        .routine-details { font-size: 0.9rem; color: #455a64; display: grid; gap: 4px; }
        .routine-assignee { display: inline-flex; align-items: center; gap: 8px; font-size: 0.9rem; color: #37474f; }
        .routine-assignee img { width: 52px; height: 52px; border-radius: 50%; object-fit: cover; border: 2px solid rgba(0,0,0,0.05); }
        .task-list { list-style: none; margin: 0; padding: 0; display: grid; gap: 8px; }
        .task-list li { background: rgba(255,255,255,0.85); border-radius: 8px; padding: 10px 12px; border-left: 4px solid #64b5f6; }
        .task-list li .dependency { font-size: 0.8rem; color: #6d4c41; }
        .card-actions { margin-top: 16px; display: flex; flex-wrap: wrap; gap: 12px; }
        .routine-card-actions { margin-top: 16px; display: flex; flex-wrap: wrap; gap: 12px; align-items: center; justify-content: center; }
        .routine-card-actions .button { flex: 1 1 45%; min-width: 0; text-align: center; }
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
        .sub-timer-label { font-size: 0.80rem; font-weight: 600; color: #ef6c00; margin-top: 0px; }
        .warning-active .timer-widget { border-color: #e53935; box-shadow: 0 0 12px rgba(229,57,53,0.25); }
        .library-table-wrap { margin-top: 12px; }
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
        .routine-flow-container { width: min(1040px, 95vw); max-height: 95vh; height: min(95vh, 860px); background: linear-gradient(155deg, #7bc4ff, #a077ff); border-radius: 26px; padding: clamp(20px, 4vh, 32px); box-shadow: 0 18px 48px rgba(0,0,0,0.25); color: #fff; display: flex; flex-direction: column; position: relative; overflow: hidden; }
        .routine-flow-overlay.status-active .routine-flow-container { background: linear-gradient(155deg, #7bc4ff, #a077ff); }
        body.routine-flow-locked { overflow: hidden; overscroll-behavior: contain; touch-action: none; }
        .routine-flow-header { display: flex; flex-direction: column; align-items: flex-start; gap: clamp(10px, 2vh, 14px); margin-bottom: clamp(16px, 3vh, 24px); }
        .routine-flow-close { /*align-self: flex-start;*/ touch-action: none; }
        .routine-flow-heading { display: flex; flex-direction: column; gap: 10px; flex: 1; width: 100%;}
        .routine-flow-bar { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; width: 100%;}
        .routine-flow-controls { display: inline-flex; align-items: center; gap: 12px; flex-wrap: wrap; justify-content: flex-end; }
        .routine-flow-controls .routine-flow-close { align-self: center; }
        .routine-flow-title { font-size: clamp(1.4rem, 2vw, 1.9rem); font-weight: 700; margin: 0; }
        .routine-flow-next-inline { display: flex; align-items: baseline; gap: 8px; font-size: 1rem; font-weight: 600; color: rgba(255,255,255,0.85); }
        .routine-flow-next-inline .label { text-transform: uppercase; letter-spacing: 0.08em; font-size: 0.78rem; opacity: 0.8; }
        .routine-flow-next-inline .value { font-size: 1.05rem; font-weight: 700; }
        .summary-heading { display: none; flex-direction: row; align-items: center; justify-content: center; text-align: left; gap: 12px; padding: 12px 0 0; }
        .summary-heading-avatar { width: 64px; height: 64px; border-radius: 50%; object-fit: cover; border: 3px solid rgba(255,255,255,0.55); box-shadow: 0 4px 12px rgba(0,0,0,0.25); background: rgba(255,255,255,0.25); flex-shrink: 0; }
        .summary-heading-text { display: flex; flex-direction: column; gap: 2px; }
        .summary-heading-title { font-size: 1.8rem; font-weight: 700; color: #fff; margin: 0; }
        .summary-heading-label { text-transform: uppercase; font-family: 'Sigmar One', cursive; font-weight: 500; font-size: 1.1rem; letter-spacing: 0.03em; text-shadow: 0 1px 2px rgba(0, 0, 0, 0.18); color: #fff; }
        .routine-flow-overlay.summary-active .routine-flow-bar { display: none; }
        .routine-flow-overlay.summary-active .summary-heading { display: flex; }
        .routine-flow-overlay.summary-active [data-action="flow-exit"] { display: none; }
        .routine-flow-close { background: #d71919; border: none; color: #fff; font-weight: 600; padding: 8px 18px; border-radius: 999px; cursor: pointer; transition: background 200ms ease; }
        .routine-flow-close:hover { background: #b71515; }
        .routine-flow-stage { flex: 1; display: grid; min-height: 0; }
        .routine-scene { display: none; height: 100%; }
        .routine-scene.active { display: grid; grid-template-rows: auto minmax(0, 1fr) auto; gap: 18px; }
        .routine-scene-status.active { /*background: linear-gradient(155deg, #7bc4ff, #a077ff);*/ padding-top: 75px; border-radius: 18px; padding: 12px; }
        .routine-scene-task .task-top { display: grid; gap: 18px; }
        .flow-progress-area { display: grid; gap: 8px; }
        .flow-progress-track { position: relative; height: clamp(40px, 7vh, 52px); background: rgba(255,255,255,0.22); border-radius: 24px; overflow: hidden; border: 3px solid #c3c3c3; box-sizing: border-box; transition: background 900ms ease, box-shadow 900ms ease; }
        .flow-progress-fill { --fill1: #43d67e; --fill2: #8fdc5d; position: absolute; inset: 0; background: linear-gradient(90deg, var(--fill1), var(--fill2)); transform: scaleX(0); transform-origin: left center; transition: background 900ms ease, box-shadow 900ms ease, filter 900ms ease; z-index: 2; }
        .flow-progress-fill.warning { --fill1: #ffcc00; --fill2: #ffb300; box-shadow: 0 0 12px rgba(255,204,0,0.45); }
        .flow-progress-fill.critical { --fill1: #ff7043; --fill2: #ef5350; box-shadow: 0 0 14px rgba(255,120,80,0.55); }
        .flow-progress-min { position: absolute; inset: 0; background: #fcb932; transform: scaleX(0); transform-origin: left center; pointer-events: none; z-index: 1; transition: transform 200ms ease, opacity 200ms ease; opacity: 0; }
        .flow-progress-min.active { opacity: 1; }
        .flow-countdown { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; font-size: 1.36rem; font-weight: 700; color: #f9f9f9; text-shadow: 0 2px 6px rgba(0,0,0,0.45); letter-spacing: 0.04em; z-index: 3; pointer-events: none; transition: color 200ms ease; }
        .flow-min-label { position: absolute; left: 50%; bottom: -2px; transform: translateX(-50%); font-size: 0.70rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: rgba(255,255,255,0.92); text-shadow: 0 2px 4px rgba(0,0,0,0.45); pointer-events: none; opacity: 0; transition: opacity 180ms ease, color 180ms ease; z-index: 4; }
        .flow-min-label.active { opacity: 1; }
        .flow-min-label.met { color: #c8e6c9; }
        .flow-progress-labels { display: flex; align-items: center; gap: 16px; font-size: 0.95rem; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 600; }
        .flow-progress-labels .start-label,
        .flow-progress-labels .limit-label { flex: 0 0 auto; opacity: 0.85; }

        .flow-progress-track[data-style="circle"],
        .flow-progress-track[data-style="pie"] {
            --track-fill: #43d67e;
            width: min(220px, 70vw);
            height: min(220px, 70vw);
            border-radius: 50%;
            margin: 0 auto;
            border-width: 0;
            background: conic-gradient(var(--track-fill) calc(var(--progress-ratio, 0) * 1turn), rgba(255,255,255,0.18) 0);
            box-shadow: 0 6px 16px rgba(0,0,0,0.2);
            transition: background 900ms ease, box-shadow 900ms ease;
        }
        .flow-progress-track[data-style="circle"] { --ring-thickness: 32px; }
        .flow-progress-track[data-style="circle"]::after {
            content: '';
            position: absolute;
            inset: 18%;
            border-radius: 50%;
            background: rgba(0,0,0,0.25);
            box-shadow: inset 0 2px 6px rgba(0,0,0,0.35);
        }
        .flow-progress-track[data-style="circle"] {
            -webkit-mask: radial-gradient(closest-side, transparent calc(100% - var(--ring-thickness)), #000 calc(100% - var(--ring-thickness) + 1px));
            mask: radial-gradient(closest-side, transparent calc(100% - var(--ring-thickness)), #000 calc(100% - var(--ring-thickness) + 1px));
        }
        .flow-progress-track[data-style="circle"] .flow-progress-fill,
        .flow-progress-track[data-style="pie"] .flow-progress-fill,
        .flow-progress-track[data-style="circle"] .flow-progress-min,
        .flow-progress-track[data-style="pie"] .flow-progress-min {
            display: none;
        }
        .flow-progress-track[data-style="circle"] .flow-countdown,
        .flow-progress-track[data-style="pie"] .flow-countdown {
            font-size: 1.4rem;
        }
        .flow-progress-track[data-style="circle"].warning,
        .flow-progress-track[data-style="pie"].warning {
            --track-fill: #ffcc00;
        }
        .flow-progress-track[data-style="circle"].critical,
        .flow-progress-track[data-style="pie"].critical {
            --track-fill: #ff7043;
        }
        .flow-progress-track[data-style="circle"].warning,
        .flow-progress-track[data-style="circle"].critical {
            -webkit-mask: radial-gradient(closest-side, transparent calc(100% - var(--ring-thickness)), #000 calc(100% - var(--ring-thickness) + 1px));
            mask: radial-gradient(closest-side, transparent calc(100% - var(--ring-thickness)), #000 calc(100% - var(--ring-thickness) + 1px));
        }
        .flow-warning { flex: 1; display: inline-flex; justify-content: center; align-items: center; min-height: 1.4em; font-size: 0.80rem; font-weight: 700; color: #ffe082; text-shadow: 0 2px 6px rgba(0,0,0,0.35); opacity: 0; transform: translateY(-4px); transition: opacity 200ms ease, transform 200ms ease, color 200ms ease; pointer-events: none; }
        .flow-warning.visible { opacity: 1; transform: translateY(0); }
        .flow-warning.warning { color: #ffcc00; }
        .flow-warning.critical { color: #ffae42; }
        .routine-flow-container { position: relative; }
        .routine-flow-container > * { position: relative; z-index: 1; }
        .routine-flow-container .illustration { position: absolute; inset: 0; background: url('images/background_images/boys_bedroom_background.jpg') center/cover no-repeat; z-index: 0; pointer-events: none; }
        .routine-flow-container .illustration::after { content: ''; position: absolute; inset: 0; background: linear-gradient(180deg, rgba(10,24,64,0.38), rgba(10,24,64,0.68)); }
        .routine-flow-container .illustration.hidden { opacity: 0; visibility: hidden; }
        .routine-flow-overlay.status-active .illustration,
        .routine-flow-overlay.summary-active .illustration { display: none; }
        .routine-primary-button { 
         /* align-self: flex-end;  */
         background: #ffeb3b; 
         border: none; 
         color: #1a237e; 
         font-weight: 800; 
         padding: 10px 22px; 
         border-radius: 18px; 
         font-size: 1.05rem; 
         cursor: pointer; 
         transition: transform 150ms ease, box-shadow 150ms ease; }
        .routine-primary-button:hover { transform: translateY(-2px); box-shadow: 0 10px 24px rgba(0,0,0,0.25); }
        .routine-action-row { display: flex; justify-content: space-between; gap: 12px; align-items: flex-end; margin-top: 6px; }
        .status-stars { display: flex; gap: 12px; justify-content: center; }
        .status-stars span { width: clamp(44px, 8vh, 60px); height: clamp(44px, 8vh, 60px); background: radial-gradient(circle at 30% 30%, #fff59d, #fbc02d); clip-path: polygon(50% 0%, 61% 35%, 98% 35%, 68% 57%, 79% 91%, 50% 70%, 21% 91%, 32% 57%, 2% 35%, 39% 35%); box-shadow: 0 6px 16px rgba(0,0,0,0.3); opacity: 0.2; transform: scale(0.8); transition: transform 200ms ease, opacity 200ms ease; }
        .status-stars span.active { opacity: 1; transform: scale(1); }
        .status-stars span.sparkle { animation: starSparkle 480ms ease-out forwards; }
        @keyframes starSparkle {
            0% { transform: scale(0.4) rotate(-18deg); opacity: 0; }
            60% { transform: scale(1.2) rotate(8deg); opacity: 1; }
            100% { transform: scale(1) rotate(0deg); opacity: 1; }
        }
        .status-summary { text-align: center; font-size: 1.1rem; display: grid; gap: 8px; height: max-content;}
        .status-summary strong { font-size: 1.4rem; }
        .summary-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; }
        .summary-card { background: rgba(255,255,255,0.18); border-radius: 14px; padding: 14px 16px; display: flex; justify-content: space-between; font-weight: 600; }
        .summary-footer { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 16px; margin-top: 16px; font-size: 1.05rem; align-items: end; }
        .summary-footer strong { display: block; font-size: 1.6rem; }
        .summary-bonus { text-align: center; font-size: 1rem; font-weight: 600; margin-top: 12px; }
        .routine-scene-summary { overflow-y: auto; scrollbar-width: none; }
        .routine-scene-summary::-webkit-scrollbar { width: 0; height: 0; }
        .hold-overlay { position: absolute; inset: 0; display: none; align-items: center; justify-content: center; pointer-events: none; z-index: 1400; }
        .hold-overlay.active { display: flex; }
        .hold-overlay .hold-overlay-box { background: rgba(0,0,0,0.65); color: #ffffff; padding: 18px 32px; border-radius: 10px; font-size: 2.4rem; font-weight: 700; letter-spacing: 0.05em; box-shadow: 0 8px 24px rgba(0,0,0,0.35); transition: font-size 160ms ease; }
        .hold-overlay .hold-overlay-box.is-message { font-size: 1.8rem; }
        .toggle-inline { display: inline-flex; align-items: center; gap: 8px; margin-top: 6px; font-weight: 600; }
        .toggle-inline input[type="checkbox"] { margin: 0; }
        .routine-flow-container audio[data-role="flow-music"],
        .routine-flow-container audio[data-role="status-sound"],
        .routine-flow-container audio[data-role="status-coin"],
        .routine-flow-container audio[data-role="summary-sound"] { display: none; }
        .toggle-control { display: flex; align-items: center; gap: 14px; padding: 10px 0; }
        .toggle-switch { position: relative; display: inline-block; width: 52px; height: 28px; }
        .toggle-switch input { opacity: 0; width: 0; height: 0; }
        .toggle-slider { position: absolute; inset: 0; border-radius: 999px; background: #b0bec5; transition: background 160ms ease; cursor: pointer; }
        .toggle-slider::before { content: ''; position: absolute; width: 22px; height: 22px; left: 4px; top: 3px; border-radius: 50%; background: #fff; transition: transform 160ms ease; }
        .toggle-switch input:checked + .toggle-slider { background: linear-gradient(135deg, #42a5f5, #1e88e5); }
        .toggle-switch input:checked + .toggle-slider::before { transform: translateX(24px); }
        .toggle-copy { display: flex; flex-direction: column; gap: 2px; }
        .toggle-title { font-weight: 700; color: #0d47a1; }
        .toggle-sub { font-size: 0.8rem; color: #546e7a; }
        body.countdown-disabled .flow-countdown { display: none !important; }
        .library-grid { display: flex; flex-direction: column; gap: 20px; }
        .library-card { width: 100%; background: #fff; border-radius: 16px; box-shadow: 0 6px 18px rgba(15,70,140,0.12); padding: 20px 22px; display: flex; flex-direction: column; gap: 16px; }
        .library-card h3 { margin: 0; font-size: 1.4rem; color: #0d47a1; font-weight: 700; }
        .library-form .input-group { display: grid; gap: 6px; margin-bottom: 12px; }
        .library-form label { font-weight: 600; color: #37474f; }
        .library-form input,
        .library-form textarea,
        .library-form select { border: 1px solid #cfd8dc; border-radius: 10px; padding: 10px 12px; font-size: 0.95rem; transition: border-color 160ms ease, box-shadow 160ms ease; }
        .library-form input:focus,
        .library-form textarea:focus,
        .library-form select:focus { border-color: #64b5f6; outline: none; box-shadow: 0 0 0 3px rgba(100,181,246,0.25); }
        .library-form textarea { resize: vertical; min-height: 76px; }
        .library-form small { font-size: 0.78rem; color: #607d8b; }
        .dual-inputs { display: grid; grid-template-columns: repeat(2, minmax(0,1fr)); gap: 14px; }
        .form-actions { margin-top: 10px; display: flex; justify-content: flex-end; }
        .button.primary { background: linear-gradient(135deg, #2196f3, #42a5f5); color: #fff; border: none; padding: 10px 20px; border-radius: 10px; font-weight: 700; cursor: pointer; transition: transform 140ms ease, box-shadow 140ms ease; }
        .button.primary:hover { transform: translateY(-1px); box-shadow: 0 6px 16px rgba(33,150,243,0.35); }
        .library-header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; }
        .library-filters { display: inline-flex; align-items: center; gap: 10px; font-weight: 600; color: #37474f; }
        .library-filters select { border: 1px solid #c5cae9; border-radius: 10px; padding: 8px 12px; font-size: 0.9rem; }
        .library-collapse { border: 1px solid rgba(13, 71, 161, 0.1); border-radius: 12px; padding: 0 0 6px; background: rgba(236, 245, 255, 0.55); }
        .library-toggle { cursor: pointer; font-weight: 700; padding: 12px 16px; position: relative; display: flex; align-items: center; gap: 10px; color: #1565c0; }
        .library-toggle::after { content: '\25BC'; font-size: 0.95rem; transition: transform 200ms ease; }
        .library-collapse[open] .library-toggle::after { transform: rotate(180deg); }
        .library-table-wrap { overflow: hidden; max-height: 0; opacity: 0; transform: translateY(-6px); transition: max-height 260ms ease, opacity 220ms ease, transform 220ms ease; padding: 0 12px; }
        .library-collapse[open] .library-table-wrap { max-height: 2000px; opacity: 1; transform: translateY(0); padding: 0 12px 12px; overflow: auto; }
        .library-table { width: 100%; border-collapse: collapse; font-size: 0.92rem; }
        .library-table thead th { text-align: left; padding: 10px 8px; background: rgba(21,101,192,0.15); color: #0d47a1; font-weight: 700; }
        .library-table tbody tr { border-bottom: 1px solid rgba(96,125,139,0.18); transition: background 140ms ease; }
        .library-table tbody tr:hover { background: rgba(227,242,253,0.45); }
        .library-table td { padding: 9px 8px; vertical-align: top; }
        .library-description { max-width: 320px; color: #455a64; font-size: 0.88rem; }
        .library-category { text-transform: capitalize; font-weight: 600; color: #00796b; }
        @media (max-width: 720px) {
            .selected-task-item { grid-template-columns: auto 1fr; grid-template-rows: auto auto; align-items: flex-start; }
            .selected-task-item .button { grid-column: 1 / -1; }
            .card-actions { flex-direction: column; }
            .routine-card-actions { flex-direction: column; align-items: stretch; }
            .routine-flow-container { padding: 22px; border-radius: 20px; }
            .routine-flow-header { flex-direction: column; align-items: stretch; }
            .routine-flow-bar { /*flex-direction: column;*/ align-items: center; gap: 6px; }
            .routine-flow-controls { width: max-content; justify-content: flex-start; }
            .routine-flow-title { font-size: 1.6rem; }
            .routine-flow-next-inline { /*align-items: flex-start;*/ flex-direction: column; gap: 4px; font-size: 0.95rem; }
            .routine-primary-button { /*width: 100%;*/ width: fit-content; text-align: center; }
        }
        @media (max-height: 620px) {
            .routine-flow-container { padding: 18px; border-radius: 20px; }
            .routine-flow-header { gap: 8px; }
            .routine-flow-title { font-size: 1.45rem; }
            .routine-flow-stage { gap: 12px; }
            .routine-scene.active { gap: 12px; }
            .flow-progress-track { height: clamp(36px, 6vh, 46px); }
            .routine-primary-button { padding: 10px 24px; font-size: 1rem; }
            .summary-grid { gap: 12px; }
            .summary-footer { gap: 12px; }
            /* .flow-progress-labels { width: 90px; } */
            .flow-progess-labels > span {
               width: auto;
            }
        }
        @media (max-height: 520px) {
            .routine-flow-container { padding: 16px; }
            .routine-flow-title { font-size: 1.35rem; }
            .routine-flow-next-inline { font-size: 0.9rem; }
            .flow-progress-labels { flex-direction: row; justify-content: space-around; align-items: flex-start; gap: 6px; font-size: 0.85rem; }
            .limit-label { width: 90px; }
            .status-summary { font-size: 1rem; }
            .summary-footer strong { font-size: 1.3rem; }
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
                    <div class="toggle-control">
                        <label class="toggle-switch">
                            <input type="checkbox" name="timer_warnings_enabled" value="1" <?php echo !empty($routinePreferences['timer_warnings_enabled']) ? 'checked' : ''; ?>>
                            <span class="toggle-slider"></span>
                        </label>
                        <div class="toggle-copy">
                            <span class="toggle-title">Timer Warnings</span>
                            <span class="toggle-sub">Show reminder messages as the task timer nears its limit.</span>
                        </div>
                    </div>
                    <div class="toggle-control">
                        <label class="toggle-switch">
                            <input type="checkbox" name="show_countdown" value="1" <?php echo !empty($routinePreferences['show_countdown']) ? 'checked' : ''; ?>>
                            <span class="toggle-slider"></span>
                        </label>
                        <div class="toggle-copy">
                            <span class="toggle-title">Show Countdown Timer</span>
                            <span class="toggle-sub">Display remaining time inside the task progress bar.</span>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="progress_style">Progress Timer Style</label>
                        <select id="progress_style" name="progress_style">
                            <?php
                                $progressStyle = $routinePreferences['progress_style'] ?? 'bar';
                                $options = [
                                    'bar' => 'Horizontal Bar (default)',
                                    'circle' => 'Circular Ring',
                                    'pie' => 'Pie Fill'
                                ];
                                foreach ($options as $value => $label):
                            ?>
                                <option value="<?php echo $value; ?>" <?php echo $progressStyle === $value ? 'selected' : ''; ?>><?php echo $label; ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small>Choose how the timer animates during a task.</small>
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
                <div class="routine-section-header">
                    <h2>Routine Task Library</h2>
                    <button type="button" class="button primary" data-action="open-task-modal">Add Routine Task</button>
                </div>
                <div class="library-grid">
                    <div class="library-card">
                        <div class="library-header">
                            <h3>Task Library</h3>
                            <div class="library-filters">
                                <label for="library-filter">Filter by category:</label>
                                <select id="library-filter" data-role="library-filter">
                                    <option value="all">All Tasks</option>
                                    <option value="hygiene">Hygiene</option>
                                    <option value="homework">Homework</option>
                                    <option value="household">Household</option>
                                </select>
                            </div>
                        </div>
                        <?php if (empty($routine_tasks)): ?>
                            <p class="no-data">No routine tasks available yet. Add a task to start building routines.</p>
                        <?php else: ?>
                            <details class="library-collapse">
                                <summary class="library-toggle">Show Library Tasks</summary>
                                <div class="library-table-wrap">
                                    <div class="library-card-list">
                                        <?php foreach ($routine_tasks as $task): ?>
                                            <?php
                                                $taskMinSeconds = isset($task['minimum_seconds']) ? (int) $task['minimum_seconds'] : 0;
                                                $taskMinEnabled = !empty($task['minimum_enabled']);
                                                if ($taskMinSeconds > 0) {
                                                    $taskMinMinutesPart = floor($taskMinSeconds / 60);
                                                    $taskMinSecondsPart = $taskMinSeconds % 60;
                                                    $taskMinDisplayBase = sprintf('%02d:%02d', $taskMinMinutesPart, $taskMinSecondsPart);
                                                    $taskMinDisplay = $taskMinEnabled ? $taskMinDisplayBase : $taskMinDisplayBase . ' (off)';
                                                } else {
                                                    $taskMinDisplay = '--';
                                                }
                                                $taskMinMinutesValue = $taskMinSeconds > 0
                                                    ? rtrim(rtrim(number_format($taskMinSeconds / 60, 2, '.', ''), '0'), '.')
                                                    : '';
                                                $taskDescription = trim((string) ($task['description'] ?? ''));
                                            ?>
                                            <article class="library-task-card" data-role="library-item" data-category="<?php echo htmlspecialchars($task['category']); ?>">
                                                <header>
                                                    <h4><?php echo htmlspecialchars($task['title']); ?></h4>
                                                    <span class="library-task-points"><?php echo (int) $task['point_value']; ?> pts</span>
                                                </header>
                                                <p class="library-task-description">
                                                    <?php echo $taskDescription !== '' ? htmlspecialchars($taskDescription) : 'No description provided.'; ?>
                                                </p>
                                                <div class="library-task-meta">
                                                    <span><?php echo (int) $task['time_limit']; ?> min</span>
                                                    <span>Min: <?php echo htmlspecialchars($taskMinDisplay); ?></span>
                                                    <span><?php echo htmlspecialchars(ucfirst($task['category'])); ?></span>
                                                </div>
                                                <?php if ((int) $task['parent_user_id'] === $family_root_id): ?>
                                                    <div class="library-task-actions">
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
                                                                    Minimum Time (min)
                                                                    <input type="number" name="edit_rt_min_minutes" min="0" step="0.1" value="<?php echo htmlspecialchars($taskMinMinutesValue); ?>">
                                                                </label>
                                                                <label class="toggle-inline">
                                                                    <input type="checkbox" name="edit_rt_min_enabled" value="1" <?php echo $taskMinEnabled ? 'checked' : ''; ?>>
                                                                    Require minimum time before completion
                                                                </label>
                                                                <small>Children must stay on this task at least this long before moving on.</small>
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
                                                        <form method="POST" onsubmit="return confirm('Delete this task?');">
                                                            <input type="hidden" name="routine_task_id" value="<?php echo (int) $task['id']; ?>">
                                                            <button type="submit" name="delete_routine_task" class="button danger">Delete</button>
                                                        </form>
                                                    </div>
                                                <?php endif; ?>
                                            </article>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </details>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="task-modal-overlay" data-role="task-modal" aria-hidden="true">
                    <div class="task-modal" role="dialog" aria-modal="true" aria-labelledby="task-modal-title">
                        <button type="button" class="task-modal-close" data-action="close-task-modal" aria-label="Close add routine task dialog">&times;</button>
                        <h3 id="task-modal-title">Create Routine Task</h3>
                        <form method="POST" class="library-form" autocomplete="off">
                            <div class="input-group">
                                <label for="rt_title">Task Title</label>
                                <input type="text" id="rt_title" name="rt_title" required>
                            </div>
                            <div class="input-group">
                                <label for="rt_description">Description</label>
                                <textarea id="rt_description" name="rt_description" rows="3" placeholder="Describe what the child needs to do"></textarea>
                            </div>
                            <div class="dual-inputs">
                                <div class="input-group">
                                    <label for="rt_time_limit">Time Limit (minutes)</label>
                                    <input type="number" id="rt_time_limit" name="rt_time_limit" min="1" required>
                                </div>
                                <div class="input-group">
                                    <label for="rt_point_value">Point Value</label>
                                    <input type="number" id="rt_point_value" name="rt_point_value" min="0" value="0">
                                </div>
                            </div>
                            <div class="input-group">
                                <label for="rt_min_time">Minimum Time Before Completion (minutes)</label>
                                <input type="number" id="rt_min_time" name="rt_min_time" min="0" step="0.1" placeholder="Optional">
                                <small>Leave blank if the child can move on at any time.</small>
                            </div>
                            <div class="input-group">
                                <label for="rt_category">Category</label>
                                <select id="rt_category" name="rt_category">
                                    <option value="hygiene">Hygiene</option>
                                    <option value="homework">Homework</option>
                                    <option value="household">Household</option>
                                </select>
                            </div>
                            <div class="form-actions">
                                <button type="submit" name="create_routine_task" class="button primary">Add Routine Task</button>
                            </div>
                        </form>
                    </div>
                </div>
            </section>

        <?php endif; ?>

        <section class="routine-section">
            <h2><?php echo ($isParentContext ? 'Family Routines' : 'My Routines'); ?></h2>
            <?php if (empty($routines)): ?>
                <p class="no-data">No routines available.</p>
            <?php else: ?>
               <div class="routine-card-grid">
                <?php foreach ($routines as $routine): ?>
                    <?php
                        $isChildView = (getEffectiveRole($_SESSION['user_id']) === 'child');
                        $cardClasses = 'routine-card' . ($isChildView ? ' child-view' : '');
                    ?>
                    <?php
        $timerWarningAttr = isset($routine['timer_warnings_enabled'])
            ? (int) $routine['timer_warnings_enabled']
            : (int) ($pagePreferences['timer_warnings_enabled'] ?? 1);
        $countdownAttr = isset($routine['show_countdown'])
            ? (int) $routine['show_countdown']
            : (int) ($pagePreferences['show_countdown'] ?? 1);
        $progressStyleAttr = isset($routinePreferences['progress_style']) && in_array($routinePreferences['progress_style'], ['bar', 'circle', 'pie'], true)
            ? $routinePreferences['progress_style']
            : 'bar';
                        $totalRoutinePoints = 0;
                        foreach ($routine['tasks'] as $taskPoints) {
                            $totalRoutinePoints += (int) ($taskPoints['point_value'] ?? 0);
                        }
                        $detailsId = 'routine-details-' . (int) $routine['id'];
                    ?>
                    <article class="<?php echo $cardClasses; ?>"
                        data-routine-id="<?php echo (int) $routine['id']; ?>"
                        data-timer-warnings="<?php echo $timerWarningAttr; ?>"
                        data-show-countdown="<?php echo $countdownAttr; ?>">
                        <header>
                            <h3><?php echo htmlspecialchars($routine['title']); ?></h3>
                            <div class="routine-details">
                                <?php if (!empty($routine['child_display_name'])): ?>
                                    <span class="routine-assignee">
                                        <img src="<?php echo htmlspecialchars($routine['child_avatar'] ?: 'images/default-avatar.png'); ?>" alt="<?php echo htmlspecialchars($routine['child_display_name']); ?>">
                                        Assigned to: <?php echo htmlspecialchars($routine['child_display_name']); ?>
                                    </span>
                                <?php endif; ?>
                                <span>Timeframe: <?php echo date('g:i A', strtotime($routine['start_time'])) . ' - ' . date('g:i A', strtotime($routine['end_time'])); ?></span>
                                <span>Routine: <?php echo $totalRoutinePoints; ?> pts - Bonus: <?php echo (int) $routine['bonus_points']; ?> pts</span>
                                <span>Recurrence: <?php echo htmlspecialchars($routine['recurrence'] ?: 'None'); ?></span>
                                <?php if (!empty($routine['creator_display_name'])): ?>
                                    <span>Created by: <?php echo htmlspecialchars($routine['creator_display_name']); ?></span>
                                <?php endif; ?>
                            </div>
                        </header>
                        <div class="routine-card-actions">
                            <?php if ($isChildView): ?>
                                <button type="button" class="button start-next-button" data-action="open-flow">Start Routine</button>
                            <?php endif; ?>
                            <button type="button" class="button secondary view-details-button" data-toggle-details="<?php echo $detailsId; ?>" aria-expanded="false">View Routine Details</button>
                            <?php if ($isParentContext): ?>
                                <button type="submit" class="button" name="parent_complete_routine" form="parent-complete-form-<?php echo (int) $routine['id']; ?>">Complete Routine</button>
                            <?php endif; ?>
                        </div>
                        <details id="<?php echo $detailsId; ?>" class="collapsible-card" data-role="collapsible-wrapper">
                            <summary class="sr-only">View Routine Details</summary>
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
                                                <?php echo (int) $task['time_limit']; ?> min · <?php echo (int) ($task['point_value'] ?? $task['points'] ?? 0); ?> pts
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
                            </div>
                        </details>
                        <?php if ($isChildView): ?>
                        <div class="routine-flow-overlay"
                            data-role="routine-flow"
                            data-timer-warnings="<?php echo $timerWarningAttr; ?>"
                            data-show-countdown="<?php echo $countdownAttr; ?>"
                            data-progress-style="<?php echo htmlspecialchars($progressStyleAttr); ?>"
                            aria-hidden="true">
                                    <div class="routine-flow-container" role="dialog" aria-modal="true">
                                        <header class="routine-flow-header">
                                            <div class="routine-flow-heading">
                                                <div class="routine-flow-bar">
                                                    <h2 class="routine-flow-title" data-role="flow-title">Ready to begin</h2>
                                                    <div class="routine-flow-controls">
                                                        <div class="routine-flow-next-inline">
                                                            <span class="label">Next</span>
                                                            <span class="value" data-role="flow-next-label">First task</span>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="summary-heading" data-role="summary-heading" aria-hidden="true">
                                                    <img class="summary-heading-avatar" src="<?php echo htmlspecialchars($routine['child_avatar'] ?: 'images/default-avatar.png'); ?>" alt="<?php echo htmlspecialchars($routine['child_display_name'] ?? 'Child Avatar'); ?>">
                                                    <div class="summary-heading-text">
                                                        <h2 class="summary-heading-title" data-role="summary-title"><?php echo htmlspecialchars($routine['title']); ?></h2>
                                                        <span class="summary-heading-label">Summary</span>
                                                    </div>
                                                </div>
                                            </div>
                                        </header>
                                        <div class="hold-overlay" data-role="hold-overlay" aria-hidden="true">
                                            <div class="hold-overlay-box" data-role="hold-countdown">5</div>
                                        </div>
                                        <audio data-role="flow-music" preload="auto" loop>
                                            <source src="sounds/backgroundMusic/music-for-game-fun-kid-game-163649.mp3" type="audio/mpeg">
                                        </audio>
                                        <audio data-role="status-sound" preload="auto">
                                            <source src="sounds/sfx/charming-twinkle-sound-for-fantasy-and-magic-1.mp3" type="audio/mpeg">
                                        </audio>
                                        <audio data-role="status-coin" preload="auto">
                                            <source src="sounds/sfx/coin-257878.mp3" type="audio/mpeg">
                                        </audio>
                                        <audio data-role="summary-sound" preload="auto">
                                            <source src="sounds/sfx/068232_successwav-82815.mp3" type="audio/mpeg">
                                        </audio>
                                        <div class="illustration" data-role="flow-illustration" aria-hidden="true"></div>
                                        <main class="routine-flow-stage">
                                            <section class="routine-scene routine-scene-task active" data-scene="task">
                                                <div class="task-top">
                                                    <div class="flow-progress-area">
                                                        <div class="flow-progress-track">
                                                            <div class="flow-progress-min" data-role="flow-min"></div>
                                                            <div class="flow-progress-fill" data-role="flow-progress"></div>
                                                            <span class="flow-countdown" data-role="flow-countdown">--:--</span>
                                                            <span class="flow-min-label" data-role="flow-min-label">&nbsp;</span>
                                                        </div>
                                                        <div class="flow-progress-labels">
                                                            <span class="start-label">Start</span>
                                                            <span class="flow-warning sub-timer-label" data-role="flow-warning" aria-live="polite">&nbsp;</span>
                                                            <span class="limit-label" data-role="flow-limit">Time Limit: --</span>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="routine-action-row">
                                                    <button type="button" class="routine-flow-close" data-action="flow-exit">Stop</button>
                                                    <button type="button" class="routine-primary-button" data-action="flow-complete-task">Next</button>
                                                </div>
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
                                                <div class="routine-action-row">
                                                    <button type="button" class="routine-flow-close" data-action="flow-exit">Stop</button>
                                                    <button type="button" class="routine-primary-button" data-action="flow-next-task">Next Task</button>
                                                </div>
                                            </section>
                                            <section class="routine-scene routine-scene-summary" data-scene="summary">
                                                <div class="summary-grid" data-role="summary-list"></div>
                                                <p class="summary-bonus" data-role="summary-bonus"></p>
                                                <div class="summary-footer">
                                                    <div>
                                                        <span>Routine Points</span>
                                                        <strong data-role="summary-routine-total">+0</strong>
                                                    </div>
                                                    <div>
                                                        <span>Bonus Points</span>
                                                        <strong data-role="summary-bonus-total">+0</strong>
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
                        <?php endif; ?>
                        <?php if ($isParentContext): ?>
                                <form method="POST" action="routine.php" class="parent-complete-form" id="parent-complete-form-<?php echo (int) $routine['id']; ?>">
                                    <input type="hidden" name="routine_id" value="<?php echo (int) $routine['id']; ?>">
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
                    </article>
                <?php endforeach; ?>
               </div>
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
                preferences: { timer_warnings_enabled: 1, show_countdown: 1 }
            };
            const hasCountdownPreference = page.preferences && typeof page.preferences.show_countdown !== 'undefined';
            const countdownEnabled = hasCountdownPreference ? Number(page.preferences.show_countdown) > 0 : true;
            document.body.classList.toggle('countdown-disabled', !countdownEnabled);
            const taskLookup = new Map((Array.isArray(page.tasks) ? page.tasks : []).map(task => [String(task.id), task]));
            const htmlDecodeField = document.createElement('textarea');

            function decodeHtmlEntities(value) {
                if (typeof value !== 'string') {
                    return value;
                }
                htmlDecodeField.innerHTML = value;
                return htmlDecodeField.value;
            }

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
                        ? initialTasks
                            .map(task => ({ id: parseInt(task.id, 10) }))
                            .filter(task => Number.isFinite(task.id) && task.id > 0)
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
                            this.selectedTasks.push({ id: numeric });
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
                        const metaSegments = [];
                        const timeLimitMinutes = parseInt(taskData.time_limit, 10) || 0;
                        metaSegments.push(`${timeLimitMinutes} min`);
                        const taskMinimumSeconds = parseInt(taskData.minimum_seconds, 10);
                        const taskMinimumEnabled = parseInt(taskData.minimum_enabled, 10) > 0;
                        if (taskMinimumEnabled && Number.isFinite(taskMinimumSeconds) && taskMinimumSeconds > 0) {
                            metaSegments.push(`Min ${formatSeconds(taskMinimumSeconds)}`);
                        }
                        metaSegments.push(taskData.category);
                        meta.textContent = metaSegments.join(` ${String.fromCharCode(0x2022)} `);
                        body.appendChild(title);
                        body.appendChild(meta);

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
                            id: task.id
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

            class NumberCounter {
                constructor(element, formatter = value => String(value)) {
                    this.el = element;
                    this.formatter = formatter;
                    this.frameId = null;
                    this.timeoutId = null;
                    this.intervalId = null;
                    this.currentValue = 0;
                    this.debugName = element && element.dataset ? element.dataset.role : 'counter';
                }

                setValue(value) {
                    if (!this.el) return;
                    this.currentValue = value;
                    this.el.textContent = this.formatter(value);
                }

                cancelTimers() {
                    if (this.frameId) {
                        cancelAnimationFrame(this.frameId);
                        this.frameId = null;
                    }
                    if (this.timeoutId) {
                        clearTimeout(this.timeoutId);
                        this.timeoutId = null;
                    }
                    if (this.intervalId) {
                        clearInterval(this.intervalId);
                        this.intervalId = null;
                    }
                }

                animate({ from, to, duration = 1000, delay = 0, mode = 'ease' } = {}) {
                    if (!this.el) return;
                    const startValue = Number.isFinite(from) ? from : this.currentValue;
                    const targetValue = Number.isFinite(to) ? to : startValue;
                    this.cancelTimers();
                    if (startValue === targetValue) {
                        this.setValue(targetValue);
                        return;
                    }

                    const startAnimation = () => {
                        if (mode === 'step') {
                            const diff = targetValue - startValue;
                            const step = diff > 0 ? 1 : -1;
                            const steps = Math.max(1, Math.abs(diff));
                            const interval = Math.max(16, duration / steps);
                            let current = startValue;
                            this.setValue(current);
                            this.intervalId = setInterval(() => {
                                current += step;
                                if ((step > 0 && current >= targetValue) || (step < 0 && current <= targetValue)) {
                                    current = targetValue;
                                }
                                this.setValue(current);
                                if (current === targetValue) {
                                    this.cancelTimers();
                                }
                            }, interval);
                            return;
                        }

                        const startTime = performance.now();
                        const animateFrame = (timestamp) => {
                            const progress = Math.min((timestamp - startTime) / duration, 1);
                            const eased = 1 - Math.pow(1 - progress, 3);
                            const value = Math.round(startValue + (targetValue - startValue) * eased);
                            this.setValue(value);
                            if (progress < 1) {
                                this.frameId = requestAnimationFrame(animateFrame);
                            } else {
                                this.frameId = null;
                            }
                        };
                        this.frameId = requestAnimationFrame(animateFrame);
                    };

                    if (delay > 0) {
                        this.timeoutId = setTimeout(startAnimation, delay);
                    } else {
                        startAnimation();
                    }
                }
            }

            class RoutinePlayer {
                constructor(card, routine, preferences) {
                    this.card = card;
                    this.routine = routine;
                    this.preferences = (preferences && typeof preferences === 'object') ? preferences : {};
                    this.tasks = Array.isArray(routine.tasks) ? [...routine.tasks] : [];
                    this.tasks.sort((a, b) => (parseInt(a.sequence_order, 10) || 0) - (parseInt(b.sequence_order, 10) || 0));

                    this.openButton = card.querySelector("[data-action='open-flow']");
                    this.overlay = card.querySelector("[data-role='routine-flow']");
                    this.overlayMounted = false;
                    this.bodyLocked = false;
                    this.exitButtons = this.overlay ? Array.from(this.overlay.querySelectorAll("[data-action='flow-exit']")) : [];
                    this.exitButton = this.exitButtons[0] || null;
                    this.progressTrackEl = this.overlay ? this.overlay.querySelector(".flow-progress-track") : null;
                    this.flowTitleEl = this.overlay ? this.overlay.querySelector("[data-role='flow-title']") : null;
                    this.nextLabelEl = this.overlay ? this.overlay.querySelector("[data-role='flow-next-label']") : null;
                    this.progressFillEl = this.overlay ? this.overlay.querySelector("[data-role='flow-progress']") : null;
                    this.countdownEl = this.overlay ? this.overlay.querySelector("[data-role='flow-countdown']") : null;
                    this.limitLabelEl = this.overlay ? this.overlay.querySelector("[data-role='flow-limit']") : null;
                    this.minMarkerEl = this.overlay ? this.overlay.querySelector("[data-role='flow-min']") : null;
                    this.minLabelEl = this.overlay ? this.overlay.querySelector("[data-role='flow-min-label']") : null;
                    this.warningEl = this.overlay ? this.overlay.querySelector("[data-role='flow-warning']") : null;
                    this.backgroundAudio = this.overlay ? this.overlay.querySelector("[data-role='flow-music']") : null;
                    this.statusPointsEl = this.overlay ? this.overlay.querySelector("[data-role='status-points']") : null;
                    this.statusTimeEl = this.overlay ? this.overlay.querySelector("[data-role='status-time']") : null;
                    this.statusFeedbackEl = this.overlay ? this.overlay.querySelector("[data-role='status-feedback']") : null;
                    this.statusStars = this.overlay ? Array.from(this.overlay.querySelectorAll('.status-stars span')) : [];
                    this.summaryListEl = this.overlay ? this.overlay.querySelector("[data-role='summary-list']") : null;
                    this.summaryTotalEl = this.overlay ? this.overlay.querySelector("[data-role='summary-routine-total']") : null;
                    this.summaryAccountEl = this.overlay ? this.overlay.querySelector("[data-role='summary-account-total']") : null;
                    this.summaryBonusTotalEl = this.overlay ? this.overlay.querySelector("[data-role='summary-bonus-total']") : null;
                    this.summaryBonusEl = this.overlay ? this.overlay.querySelector("[data-role='summary-bonus']") : null;
                    this.summaryHeadingEl = this.overlay ? this.overlay.querySelector("[data-role='summary-heading']") : null;
                    this.summaryHeadingTitleEl = this.overlay ? this.overlay.querySelector("[data-role='summary-title']") : null;
                    this.illustrationEl = this.overlay ? this.overlay.querySelector("[data-role='flow-illustration']") : null;
                    this.statusChime = this.overlay ? this.overlay.querySelector("[data-role='status-sound']") : null;
                    this.statusCoinSound = this.overlay ? this.overlay.querySelector("[data-role='status-coin']") : null;
                    this.summaryChime = this.overlay ? this.overlay.querySelector("[data-role='summary-sound']") : null;
                    this.statusSequenceToken = 0;
                    this.starAnimationTimers = [];
                    this.pendingStarTargets = [];
                    this.activeCoinClips = [];
                    this.summaryCounters = {
                        routine: this.summaryTotalEl ? new NumberCounter(this.summaryTotalEl, value => `+${value}`) : null,
                        bonus: this.summaryBonusTotalEl ? new NumberCounter(this.summaryBonusTotalEl, value => `+${value}`) : null,
                        account: this.summaryAccountEl ? new NumberCounter(this.summaryAccountEl, value => `${value}`) : null
                    };
                    const initialAccount = typeof page.childPoints === 'number' ? page.childPoints : 0;
                    this.summaryStats = { routine: 0, bonus: 0, account: initialAccount };
                    this.summaryStatsInitialized = false;
                    this.holdOverlay = this.overlay ? this.overlay.querySelector("[data-role='hold-overlay']") : null;
                    this.holdCountdownEl = this.overlay ? this.overlay.querySelector("[data-role='hold-countdown']") : null;
                    this.bonusPossible = Math.max(0, parseInt(this.routine.bonus_points, 10) || 0);
                    this.bonusAwarded = 0;
                    if (this.backgroundAudio) {
                        this.backgroundAudio.loop = true;
                        this.backgroundAudio.volume = 0.35;
                    }

                    const resolvedToggle = Number(this.preferences.timer_warnings_enabled) > 0;
                    this.timerWarningsEnabled = resolvedToggle;

                    const resolvedCountdown = Number(this.preferences.show_countdown) > 0;
                    this.showCountdown = resolvedCountdown;
                    if (this.countdownEl) {
                        this.countdownEl.style.display = this.showCountdown ? '' : 'none';
                    }

                    const overlayStyle = this.overlay ? this.overlay.getAttribute('data-progress-style') : null;
                    this.progressStyle = overlayStyle || this.preferences.progress_style || 'bar';
                    this.applyProgressStyle();
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
                    this.childPointsStart = this.childPoints;
                    this.accountDisplayValue = this.childPoints;
                    this.summaryStats = { routine: 0, bonus: 0, account: this.childPointsStart };
                    this.summaryStatsInitialized = false;
                    this.taskScheduledSeconds = [];
                    this.currentScene = 'task';
                    this.warningState = { visible: false, message: '', state: '' };
                    this.minimumSeconds = 0;
                    this.minimumSecondsActive = false;
                    this.holdInterval = null;
                    this.holdTimeout = null;
                    this.holdActive = false;
                    this.holdRemaining = 3;
                    this.exitPointerId = null;
                    this.messageTimeout = null;
                    this.starAnimationTimers = [];
                    this.summaryPlayed = false;

                    this.initializeTaskDurations();
                    this.resetWarningState();

                    this.init();
                }

                init() {
                    if (!this.openButton || !this.overlay) {
                        return;
                    }
                    const supportsTouch = typeof window !== 'undefined' && (
                        'ontouchstart' in window ||
                        (typeof navigator !== 'undefined' && (navigator.maxTouchPoints > 0 || navigator.msMaxTouchPoints > 0))
                    );
                    const touchEventOptions = supportsTouch ? { passive: false } : undefined;
                    this.openButton.addEventListener('click', () => {
                        try {
                            console.log('[RoutinePlayer] open button click', { routineId: this.routine.id });
                        } catch (e) {}
                        this.openFlow();
                    });
                    if (Array.isArray(this.exitButtons)) {
                        this.exitButtons.forEach(btn => {
                            btn.addEventListener('pointerdown', event => this.startHoldToExit(event, false, btn));
                            btn.addEventListener('pointerup', () => this.cancelHoldToExit());
                            btn.addEventListener('pointerleave', () => this.cancelHoldToExit());
                            btn.addEventListener('pointercancel', () => this.cancelHoldToExit());
                            btn.addEventListener('keydown', event => {
                                if (event.code === 'Space' || event.code === 'Enter') {
                                    this.startHoldToExit(event, true, btn);
                                }
                            });
                            btn.addEventListener('keyup', event => {
                                if (event.code === 'Space' || event.code === 'Enter') {
                                    this.cancelHoldToExit();
                                }
                            });
                            if (supportsTouch) {
                                btn.addEventListener('touchstart', event => this.startHoldToExit(event, false, btn), touchEventOptions);
                                btn.addEventListener('touchend', () => this.cancelHoldToExit(), touchEventOptions);
                                btn.addEventListener('touchcancel', () => this.cancelHoldToExit(), touchEventOptions);
                            }
                        });
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

                mountOverlay() {
                    if (!this.overlay || this.overlayMounted) {
                        return;
                    }
                    const target = document.body || document.documentElement;
                    if (target && this.overlay.parentElement !== target) {
                        target.appendChild(this.overlay);
                    }
                    this.overlayMounted = true;
                }

                lockBodyScroll() {
                    if (this.bodyLocked) {
                        return;
                    }
                    const target = document.body;
                    if (!target) {
                        return;
                    }
                    target.classList.add('routine-flow-locked');
                    this.bodyLocked = true;
                }

                unlockBodyScroll() {
                    if (!this.bodyLocked) {
                        return;
                    }
                    const target = document.body;
                    if (target) {
                        target.classList.remove('routine-flow-locked');
                    }
                    this.bodyLocked = false;
                }

                initializeTaskDurations() {
                    this.taskScheduledSeconds = this.tasks.map(task => {
                        const raw = parseInt(task.time_limit, 10);
                        return Math.max(0, (Number.isFinite(raw) ? raw : 0) * 60);
                    });
                }

                applyProgressStyle() {
                    if (!this.progressTrackEl) return;
                    const style = ['bar', 'circle', 'pie'].includes(this.progressStyle) ? this.progressStyle : 'bar';
                    this.progressTrackEl.setAttribute('data-style', style);
                }

                setWarning(message, state = '') {
                    if (!this.warningEl) {
                        return;
                    }
                    const visible = !!message;
                    const last = this.warningState || { visible: false, message: '', state: '' };
                    if (!visible && !last.visible) {
                        return;
                    }
                    if (visible && last.visible && last.message === message && last.state === state) {
                        return;
                    }
                    if (!visible) {
                        this.warningEl.textContent = '\u00A0';
                        this.warningEl.classList.remove('visible', 'warning', 'critical', 'late');
                    } else {
                        this.warningEl.textContent = message;
                        this.warningEl.classList.add('visible');
                        this.warningEl.classList.remove('warning', 'critical', 'late');
                        if (state) {
                            this.warningEl.classList.add(state);
                        }
                    }
                    this.warningState = { visible, message: visible ? message : '', state: visible ? state : '' };
                }

                resetWarningState() {
                    if (this.progressFillEl) {
                        this.progressFillEl.classList.remove('warning', 'critical');
                    }
                    if (this.progressTrackEl) {
                        this.progressTrackEl.classList.remove('warning', 'critical');
                    }
                    if (this.minMarkerEl) {
                        this.minMarkerEl.classList.remove('active');
                        this.minMarkerEl.style.transform = 'scaleX(0)';
                    }
                    if (this.minLabelEl) {
                        this.minLabelEl.textContent = '\u00A0';
                        this.minLabelEl.classList.remove('active', 'met');
                    }
                    this.minimumSecondsActive = false;
                    this.setWarning('');
                }

                updateTaskWarning(progressRatio, isOvertime) {
                    if (!this.progressFillEl && !this.progressTrackEl) {
                        return;
                    }
                    if (this.progressFillEl) {
                        this.progressFillEl.classList.remove('warning', 'critical');
                    }
                    if (this.progressTrackEl) {
                        this.progressTrackEl.classList.remove('warning', 'critical');
                    }
                    const canEvaluate = this.currentTask && this.currentScene === 'task' && this.scheduledSeconds > 0;
                    if (!canEvaluate) {
                        this.setWarning('');
                        return;
                    }

                    const ratio = Math.max(0, Math.min(1, progressRatio));
                    const criticalState = ratio >= 0.9 || isOvertime;
                    const warningState = !criticalState && ratio >= 0.75;

                    if (criticalState) {
                        if (this.progressFillEl) this.progressFillEl.classList.add('critical');
                        if (this.progressTrackEl) this.progressTrackEl.classList.add('critical');
                    } else if (warningState) {
                        if (this.progressFillEl) this.progressFillEl.classList.add('warning');
                        if (this.progressTrackEl) this.progressTrackEl.classList.add('warning');
                    }

                    if (!this.timerWarningsEnabled) {
                        this.setWarning('');
                        return;
                    }

                    if (criticalState) {
                        this.setWarning('Not much time left, finish soon to earn full points.', 'critical');
                    } else if (warningState) {
                        this.setWarning('Time is almost up, hurry to finish task on time.', 'warning');
                    } else {
                        this.setWarning('');
                    }
                }

                updateMinimumIndicators() {
                    if (!this.minMarkerEl || !this.minLabelEl) {
                        return;
                    }
                    const totalSeconds = Math.max(0, this.scheduledSeconds || 0);
                    const minSeconds = Math.max(0, this.minimumSeconds || 0);
                    this.minimumSecondsActive = minSeconds > 0 && totalSeconds > 0;
                    if (!this.minimumSecondsActive) {
                        this.minMarkerEl.style.transform = 'scaleX(0)';
                        this.minMarkerEl.classList.remove('active');
                        this.minLabelEl.textContent = '\u00A0';
                        this.minLabelEl.classList.remove('active', 'met');
                        return;
                    }
                    const ratio = Math.min(1, minSeconds / Math.max(1, totalSeconds));
                    this.minMarkerEl.style.transform = `scaleX(${ratio})`;
                    this.minMarkerEl.classList.add('active');
                    this.minLabelEl.textContent = `Minimum duration (${formatSeconds(minSeconds)})`;
                    this.minLabelEl.classList.add('active');
                    this.minLabelEl.classList.toggle('met', this.elapsedSeconds >= minSeconds);
                }

                updateMinimumProgressState() {
                    if (!this.minimumSecondsActive || !this.minLabelEl) {
                        return;
                    }
                    const minSeconds = Math.max(0, this.minimumSeconds || 0);
                    this.minLabelEl.classList.toggle('met', this.elapsedSeconds >= minSeconds);
                }

                updateCountdownVisibility() {
                    if (!this.countdownEl) {
                        return;
                    }
                    this.countdownEl.style.display = this.showCountdown ? '' : 'none';
                }

                playBackgroundMusic() {
                    if (!this.backgroundAudio) {
                        return;
                    }
                    if (this.backgroundAudio.paused) {
                        const attempt = this.backgroundAudio.play();
                        if (attempt && typeof attempt.catch === 'function') {
                            attempt.catch(() => {});
                        }
                    }
                }

                pauseBackgroundMusic(reset = false) {
                    if (!this.backgroundAudio) {
                        return;
                    }
                    this.backgroundAudio.pause();
                    if (reset) {
                        try {
                            this.backgroundAudio.currentTime = 0;
                        } catch (e) {
                            // ignore resetting issues
                        }
                    }
                }

                playAudioClip(audio, reset = true) {
                    if (!audio) {
                        return;
                    }
                    try {
                        if (reset) {
                            audio.currentTime = 0;
                        }
                        const attempt = audio.play();
                        if (attempt && typeof attempt.catch === 'function') {
                            attempt.catch(() => {});
                        }
                    } catch (e) {
                        // ignore play issues
                    }
                }

                playAudioClipAsync(audio, reset = true) {
                    return new Promise(resolve => {
                        if (!audio) {
                            resolve();
                            return;
                        }
                        let resolved = false;
                        let fallbackTimer = null;
                        const cleanup = () => {
                            if (resolved) return;
                            resolved = true;
                            audio.removeEventListener('ended', onResolve);
                            audio.removeEventListener('error', onResolve);
                            audio.removeEventListener('loadedmetadata', onMetadata);
                            if (fallbackTimer) {
                                clearTimeout(fallbackTimer);
                            }
                            resolve();
                        };
                        const onResolve = () => cleanup();
                        const durationFallback = () => Math.max(200, ((isFinite(audio.duration) && audio.duration > 0 ? audio.duration : 0.35) * 1000) + 80);
                        const onMetadata = () => {
                            if (fallbackTimer) {
                                clearTimeout(fallbackTimer);
                            }
                            if (isFinite(audio.duration) && audio.duration > 0) {
                                fallbackTimer = setTimeout(cleanup, durationFallback());
                            }
                        };
                        audio.addEventListener('ended', onResolve, { once: true });
                        audio.addEventListener('error', onResolve, { once: true });
                        audio.addEventListener('loadedmetadata', onMetadata);
                        try {
                            audio.pause();
                            if (reset) {
                                audio.currentTime = 0;
                            }
                        } catch (e) {
                            // ignore
                        }
                        const playPromise = audio.play();
                        if (playPromise && typeof playPromise.catch === 'function') {
                            playPromise.catch(() => cleanup());
                        }
                        if (audio.readyState >= 1 && isFinite(audio.duration) && audio.duration > 0) {
                            fallbackTimer = setTimeout(cleanup, durationFallback());
                        } else if (!fallbackTimer) {
                            fallbackTimer = setTimeout(cleanup, 5000);
                        }
                    });
                }

                clearStatusAnimations(incrementToken = true) {
                    if (incrementToken) {
                        this.statusSequenceToken += 1;
                    }
                    if (!Array.isArray(this.starAnimationTimers)) {
                        this.starAnimationTimers = [];
                    }
                    if (Array.isArray(this.starAnimationTimers) && this.starAnimationTimers.length) {
                        this.starAnimationTimers.forEach(timer => clearTimeout(timer));
                        this.starAnimationTimers = [];
                    }
                    if (this.statusChime) {
                        try {
                            this.statusChime.pause();
                            this.statusChime.currentTime = 0;
                        } catch (e) {}
                    }
                    if (this.statusCoinSound) {
                        try {
                            this.statusCoinSound.pause();
                            this.statusCoinSound.currentTime = 0;
                        } catch (e) {}
                    }
                    if (!Array.isArray(this.activeCoinClips)) {
                        this.activeCoinClips = [];
                    }
                    if (this.activeCoinClips.length) {
                        this.activeCoinClips.forEach(record => {
                            if (!record || !record.clip) {
                                return;
                            }
                            try {
                                record.clip.pause();
                            } catch (e) {}
                            try {
                                record.clip.currentTime = 0;
                            } catch (e) {}
                            if (typeof record.finish === 'function') {
                                record.finish();
                            }
                        });
                        this.activeCoinClips = [];
                    }
                    if (Array.isArray(this.statusStars)) {
                        this.statusStars.forEach(star => star.classList.remove('sparkle'));
                    }
                    if (incrementToken) {
                        this.pendingStarTargets = Array.isArray(this.pendingStarTargets)
                            ? this.pendingStarTargets.filter(star => star && star.classList.contains('will-activate'))
                            : [];
                    }
                    return this.statusSequenceToken;
                }

                handleStatusSceneEnter() {
                    const sequenceToken = this.clearStatusAnimations();
                    const targetStars = Array.isArray(this.pendingStarTargets) && this.pendingStarTargets.length
                        ? [...this.pendingStarTargets]
                        : (Array.isArray(this.statusStars)
                            ? this.statusStars.filter(star => star.classList.contains('will-activate'))
                            : []);
                    this.runStatusSequence(sequenceToken, targetStars);
                }

                runStatusSequence(sequenceToken, starsInput) {
                    const stars = Array.isArray(starsInput) && starsInput.length
                        ? starsInput.filter(star => star && star.classList.contains('will-activate'))
                        : (Array.isArray(this.statusStars)
                            ? this.statusStars.filter(star => star.classList.contains('will-activate'))
                            : []);
                    if (!Array.isArray(this.pendingStarTargets)) {
                        this.pendingStarTargets = [];
                    } else {
                        this.pendingStarTargets = this.pendingStarTargets.filter(star => star && star.classList.contains('will-activate'));
                    }
                    this.playAudioClip(this.statusChime, true);
                    if (!stars.length) {
                        return;
                    }
                    if (!Array.isArray(this.starAnimationTimers)) {
                        this.starAnimationTimers = [];
                    }
                    const baseDelay = 1500;
                    stars.forEach((star, index) => {
                        const delay = baseDelay + index * 260;
                        const timerId = setTimeout(() => {
                            const storedIndex = this.starAnimationTimers.indexOf(timerId);
                            if (storedIndex !== -1) {
                                this.starAnimationTimers.splice(storedIndex, 1);
                            }
                            if (this.statusSequenceToken !== sequenceToken || !star) {
                                return;
                            }
                            star.classList.remove('will-activate');
                            if (Array.isArray(this.pendingStarTargets)) {
                                this.pendingStarTargets = this.pendingStarTargets.filter(item => item !== star);
                            }
                            star.classList.add('active');
                            star.classList.remove('sparkle');
                            void star.offsetWidth;
                            star.classList.add('sparkle');
                            star.addEventListener('animationend', () => {
                                star.classList.remove('sparkle');
                            }, { once: true });
                            this.playCoinSoundOverlap(sequenceToken);
                        }, delay);
                        this.starAnimationTimers.push(timerId);
                    });
                }

                playCoinSoundOverlap(sequenceToken) {
                    return new Promise(resolve => {
                        if (!this.statusCoinSound) {
                            resolve();
                            return;
                        }
                        const src = this.statusCoinSound.currentSrc || this.statusCoinSound.src;
                        const clip = src ? new Audio(src) : this.statusCoinSound.cloneNode(true);
                        if (!clip) {
                            resolve();
                            return;
                        }
                        clip.volume = typeof this.statusCoinSound.volume === 'number' ? this.statusCoinSound.volume : 1;
                        if (!Array.isArray(this.activeCoinClips)) {
                            this.activeCoinClips = [];
                        }
                        const record = { clip, finish: null };
                        this.activeCoinClips.push(record);
                        let finished = false;
                        let resolved = false;
                        const resolveIfNeeded = () => {
                            if (resolved) return;
                            resolved = true;
                            resolve();
                        };
                        const finalize = () => {
                            if (finished) return;
                            finished = true;
                            clip.removeEventListener('ended', handleEnd);
                            clip.removeEventListener('error', handleError);
                            clearTimeout(timer);
                            const idx = this.activeCoinClips.indexOf(record);
                            if (idx !== -1) {
                                this.activeCoinClips.splice(idx, 1);
                            }
                            resolveIfNeeded();
                        };
                        const handleEnd = () => {
                            finalize();
                        };
                        const handleError = () => {
                            finalize();
                        };
                        const waitMs = 220;
                        const timer = setTimeout(() => {
                            resolveIfNeeded();
                        }, waitMs);
                        record.finish = () => {
                            try {
                                clip.pause();
                            } catch (e) {}
                            try {
                                clip.currentTime = 0;
                            } catch (e) {}
                            finalize();
                        };
                        clip.addEventListener('ended', handleEnd, { once: true });
                        clip.addEventListener('error', handleError, { once: true });
                        clip.play().catch(() => {
                            finalize();
                        });
                    });
                }

                parseElementNumber(el) {
                    if (!el) return 0;
                    const text = (el.textContent || '').replace(/[^0-9-]/g, '');
                    const value = parseInt(text, 10);
                    return Number.isFinite(value) ? value : 0;
                }

                updateSummaryStats({ routineTarget, bonusTarget, accountTarget, reset = false } = {}) {
                    const desiredRoutine = Math.max(0, Number.isFinite(routineTarget) ? routineTarget : (this.totalEarnedPoints || 0));
                    const desiredBonus = Math.max(0, Number.isFinite(bonusTarget) ? bonusTarget : (this.bonusAwarded || 0));
                    const desiredAccount = Math.max(0, Number.isFinite(accountTarget) ? accountTarget : (this.childPoints || 0));

                    const previous = this.summaryStats || {
                        routine: 0,
                        bonus: 0,
                        account: Number.isFinite(this.childPointsStart) ? this.childPointsStart : (this.childPoints || 0)
                    };

                    const routineStart = reset ? 0 : previous.routine;
                    const bonusStart = reset ? 0 : previous.bonus;
                    const accountStart = reset
                        ? (Number.isFinite(this.childPointsStart) ? this.childPointsStart : previous.account)
                        : previous.account;

                    if (reset && this.summaryCounters.routine) {
                        this.summaryCounters.routine.setValue(0);
                    }
                    if (reset && this.summaryCounters.bonus) {
                        this.summaryCounters.bonus.setValue(0);
                    }
                    if (reset && this.summaryCounters.account) {
                        const baseAccount = Number.isFinite(this.childPointsStart) ? this.childPointsStart : 0;
                        this.summaryCounters.account.setValue(baseAccount);
                    }

                    const baseDelay = reset ? 400 : 0;
                    const routineChanged = reset || desiredRoutine !== previous.routine;
                    const bonusChanged = reset || desiredBonus !== previous.bonus;
                    const accountChanged = reset || desiredAccount !== previous.account;

                    if (this.summaryCounters.routine) {
                        if (routineChanged) {
                        this.summaryCounters.routine.animate({
                            from: routineStart,
                            to: desiredRoutine,
                            duration: 3000,
                            delay: baseDelay,
                            mode: 'step'
                        });
                        } else {
                            this.summaryCounters.routine.setValue(desiredRoutine);
                        }
                    }
                    if (this.summaryCounters.bonus) {
                        if (bonusChanged) {
                        this.summaryCounters.bonus.animate({
                            from: bonusStart,
                            to: desiredBonus,
                            duration: 3000,
                            delay: reset ? baseDelay + 250 : 0
                        });
                        } else {
                            this.summaryCounters.bonus.setValue(desiredBonus);
                        }
                    }
                    if (this.summaryCounters.account) {
                        if (accountChanged) {
                        this.summaryCounters.account.animate({
                            from: accountStart,
                            to: desiredAccount,
                            duration: 3000,
                            delay: reset ? baseDelay + 500 : 0
                        });
                        } else {
                            this.summaryCounters.account.setValue(desiredAccount);
                        }
                    }

                    this.summaryStats = {
                        routine: desiredRoutine,
                        bonus: desiredBonus,
                        account: desiredAccount
                    };
                    this.accountDisplayValue = desiredAccount;
                }

                playSummaryCelebration() {
                    if (this.summaryPlayed) {
                        return;
                    }
                    this.summaryPlayed = true;
                    this.playAudioClip(this.summaryChime);
                }

                displayMinimumTimeMessage() {
                    const message = '\u23F1\uFE0F Too quick, keep going!';
                    this.clearHoldTimers();
                    this.clearMessageTimeout();
                    this.holdActive = false;
                    this.showHoldOverlay('message', message);
                    this.messageTimeout = setTimeout(() => {
                        this.hideHoldOverlay();
                        this.messageTimeout = null;
                    }, 1800);
                }

                clearMessageTimeout() {
                    if (this.messageTimeout) {
                        clearTimeout(this.messageTimeout);
                        this.messageTimeout = null;
                    }
                }

                startHoldToExit(event, fromKeyboard, triggerButton = null) {
                    if (event) {
                        event.preventDefault();
                        event.stopPropagation();
                    }
                    if (!this.exitButton || this.holdActive) {
                        return;
                    }
                    this.exitHoldButton = triggerButton || this.exitButton;
                    this.clearMessageTimeout();
                    this.holdActive = true;
                    this.holdRemaining = 3;
                    if (!fromKeyboard && event && typeof event.pointerId === 'number' && this.exitHoldButton) {
                        this.exitPointerId = event.pointerId;
                        if (this.exitHoldButton.setPointerCapture) {
                            try {
                                this.exitHoldButton.setPointerCapture(this.exitPointerId);
                            } catch (e) {
                                this.exitPointerId = null;
                            }
                        }
                    }
                        this.showHoldOverlay('countdown', String(this.holdRemaining));
                        this.updateHoldCountdown(this.holdRemaining);
                    this.clearHoldTimers();
                    this.holdInterval = setInterval(() => {
                        this.holdRemaining -= 1;
                        if (this.holdRemaining <= 0) {
                            this.completeHoldExit();
                        } else {
                            this.updateHoldCountdown(this.holdRemaining);
                        }
                    }, 1000);
                    this.holdTimeout = setTimeout(() => this.completeHoldExit(), 5000);
                }

                cancelHoldToExit() {
                    if (!this.holdActive) {
                        return;
                    }
                    this.holdActive = false;
                    this.clearHoldTimers();
                    this.clearMessageTimeout();
                    this.holdRemaining = 3;
                    this.hideHoldOverlay();
                    if (this.exitPointerId !== null && this.exitHoldButton && this.exitHoldButton.releasePointerCapture) {
                        try {
                            this.exitHoldButton.releasePointerCapture(this.exitPointerId);
                        } catch (e) {
                            // ignore
                        }
                    }
                    this.exitPointerId = null;
                    this.exitHoldButton = null;
                }

                completeHoldExit() {
                    if (!this.holdActive) {
                        return;
                    }
                    this.holdActive = false;
                    this.clearHoldTimers();
                    this.clearMessageTimeout();
                    this.hideHoldOverlay();
                    if (this.exitPointerId !== null && this.exitHoldButton && this.exitHoldButton.releasePointerCapture) {
                        try {
                            this.exitHoldButton.releasePointerCapture(this.exitPointerId);
                        } catch (e) {
                            // ignore
                        }
                    }
                    this.exitPointerId = null;
                    this.exitHoldButton = null;
                    this.handleExit();
                }

                clearHoldTimers() {
                    if (this.holdTimeout) {
                        clearTimeout(this.holdTimeout);
                        this.holdTimeout = null;
                    }
                    if (this.holdInterval) {
                        clearInterval(this.holdInterval);
                        this.holdInterval = null;
                    }
                }

                showHoldOverlay(mode = 'countdown', text = null) {
                    if (!this.holdOverlay) {
                        return;
                    }
                    this.holdOverlay.classList.add('active');
                    this.holdOverlay.setAttribute('aria-hidden', 'false');
                    if (this.holdCountdownEl) {
                        if (mode === 'message') {
                            this.holdCountdownEl.classList.add('is-message');
                            this.holdCountdownEl.textContent = text ?? '';
                        } else {
                            this.holdCountdownEl.classList.remove('is-message');
                            this.holdCountdownEl.textContent = text ?? String(this.holdRemaining);
                        }
                    }
                }

                hideHoldOverlay() {
                    if (!this.holdOverlay) {
                        return;
                    }
                    this.clearMessageTimeout();
                    this.holdOverlay.classList.remove('active');
                    this.holdOverlay.setAttribute('aria-hidden', 'true');
                    this.holdRemaining = 3;
                    if (this.holdCountdownEl) {
                        this.holdCountdownEl.classList.remove('is-message');
                        this.holdCountdownEl.textContent = '3';
                    }
                }

                updateHoldCountdown(value) {
                    if (!this.holdCountdownEl) {
                        return;
                    }
                    this.holdCountdownEl.classList.remove('is-message');
                    this.holdCountdownEl.textContent = String(value);
                }

                openFlow() {
                    if (!this.tasks.length) {
                        alert('No tasks are available in this routine yet.');
                        return;
                    }
                    try {
                        console.log('[RoutinePlayer] openFlow', { routineId: this.routine.id });
                    } catch (e) {}
                    this.mountOverlay();
                    this.overlay.classList.add('active');
                    this.overlay.setAttribute('aria-hidden', 'false');
                    this.lockBodyScroll();
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
                    this.resetWarningState();
                    this.clearStatusAnimations();
                }

                closeOverlay(resetTitle = true) {
                    this.stopTaskAnimation();
                    if (this.overlay) {
                        this.overlay.classList.remove('active');
                        this.overlay.setAttribute('aria-hidden', 'true');
                    }
                    this.unlockBodyScroll();
                    if (resetTitle && this.flowTitleEl) {
                        this.flowTitleEl.textContent = this.routine.title || 'Routine';
                    }
                    if (this.summaryBonusEl) {
                        this.summaryBonusEl.textContent = '';
                    }
                    if (this.completeButton) {
                        this.completeButton.disabled = false;
                    }
                    this.clearHoldTimers();
                    this.clearMessageTimeout();
                    this.hideHoldOverlay();
                    this.holdActive = false;
                    this.exitPointerId = null;
                    this.pauseBackgroundMusic(true);
                    this.resetWarningState();
                    this.clearStatusAnimations();
                }

                startRoutine() {
                    this.initializeTaskDurations();
                    this.resetWarningState();
                    this.currentScene = 'task';
                    this.resetRoutineStatuses();
                    this.summaryPlayed = false;
                    this.childPointsStart = this.childPoints;
                    this.accountDisplayValue = this.childPoints;
                    this.clearStatusAnimations();
                    if (Array.isArray(this.statusStars) && this.statusStars.length) {
                        this.statusStars.forEach(star => star.classList.remove('active', 'will-activate'));
                    }
                    this.pendingStarTargets = [];
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
                    const predefined = this.taskScheduledSeconds && this.taskScheduledSeconds[index] !== undefined
                        ? this.taskScheduledSeconds[index]
                        : Math.max(0, (parseInt(this.currentTask.time_limit, 10) || 0) * 60);
                    this.scheduledSeconds = predefined;
                    const rawMinimum = parseInt(this.currentTask.minimum_seconds, 10);
                    const minimumEnabled = parseInt(this.currentTask.minimum_enabled, 10) > 0;
                    this.minimumSeconds = (minimumEnabled && Number.isFinite(rawMinimum) && rawMinimum > 0) ? rawMinimum : 0;
                    this.taskStartTime = null;
                    this.elapsedSeconds = 0;
                    this.stopTaskAnimation();
                    this.resetWarningState();
                    this.updateMinimumIndicators();
                    this.updateCountdownVisibility();
                    this.updateTaskHeader();
                    this.updateNextLabel();
                    this.updateTimeLimitLabel();
                    if (this.countdownEl && this.showCountdown) {
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
                    let ratio = 1;
                    let displayValue = '--:--';
                    let isOvertime = false;

                    if (scheduled > 0) {
                        ratio = this.elapsedSeconds / Math.max(1, scheduled);
                        const remaining = scheduled - this.elapsedSeconds;
                        displayValue = formatCountdownDisplay(remaining);
                        isOvertime = remaining < 0;
                    } else {
                        ratio = 0;
                        displayValue = formatSeconds(Math.ceil(this.elapsedSeconds));
                    }

                    const clamped = Math.max(0, Math.min(1, ratio));
                    if (this.progressFillEl) {
                        this.progressFillEl.style.transform = `scaleX(${clamped})`;
                    }
                    if (this.progressTrackEl) {
                        this.progressTrackEl.style.setProperty('--progress-ratio', clamped);
                    }
                    if (this.countdownEl) {
                        if (this.showCountdown) {
                            this.countdownEl.textContent = displayValue;
                            this.countdownEl.style.color = '#f9f9f9';
                            this.countdownEl.style.textShadow = isOvertime
                                ? '0 2px 6px rgba(0,0,0,0.6)'
                                : '0 2px 6px rgba(0,0,0,0.45)';
                        } else {
                            this.countdownEl.textContent = '';
                        }
                    }
                    this.updateTaskWarning(ratio, isOvertime);
                    this.updateMinimumProgressState();
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
                    const elapsedSeconds = Math.max(0, this.elapsedSeconds || 0);
                    if (this.minimumSeconds > 0 && elapsedSeconds < this.minimumSeconds) {
                        this.displayMinimumTimeMessage();
                        return;
                    }
                    this.stopTaskAnimation();
                    if (this.completeButton) {
                        this.completeButton.disabled = true;
                    }
                    const actualSeconds = Math.ceil(elapsedSeconds);
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
                    this.resetWarningState();
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
                    this.resetWarningState();
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
                            feedback = 'No timer on this task-keep up the pace!';
                            stars = 3;
                        } else {
                            const overtimeSeconds = actualSeconds - scheduledSeconds;
                            if (overtimeSeconds <= 0) {
                                feedback = 'Right on time!';
                                stars = 3;
                            } else if (overtimeSeconds <= 60) {
                                feedback = 'A little late-half points earned.';
                                stars = 2;
                            } else {
                                feedback = 'Over the limit-no points this time.';
                                stars = 1;
                            }
                        }
                        this.statusFeedbackEl.textContent = feedback;
                        this.pendingStarTargets = [];
                        this.statusStars.forEach((star, idx) => {
                            star.classList.remove('active');
                            if (idx < stars) {
                                star.classList.add('will-activate');
                                this.pendingStarTargets.push(star);
                            } else {
                                star.classList.remove('will-activate');
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
                    this.renderSummary(this.taskResults, { resetAnimation: true });
                    this.finalizeRoutine();
                }

                renderSummary(results, options = {}) {
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
                    const routineTotal = Array.isArray(results)
                        ? results.reduce((sum, item) => sum + Math.max(0, parseInt(item.awarded_points, 10) || 0), 0)
                        : 0;
                    if (!Number.isFinite(this.totalEarnedPoints)) {
                        this.totalEarnedPoints = routineTotal;
                    }
                    const skipStats = !!options.skipStats;
                    if (!skipStats) {
                        const forceReset = !!options.resetAnimation;
                        const shouldReset = forceReset || !this.summaryStatsInitialized;
                        this.summaryStatsInitialized = true;
                        this.updateSummaryStats({
                            reset: shouldReset,
                            routineTarget: routineTotal,
                            bonusTarget: this.bonusAwarded || 0,
                            accountTarget: this.childPoints
                        });
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
                                this.renderSummary(this.taskResults, { skipStats: true });
                            }
                            if (typeof data.new_total_points === 'number') {
                                this.childPoints = data.new_total_points;
                                page.childPoints = this.childPoints;
                            }
                            if (typeof data.task_points_awarded === 'number') {
                                this.totalEarnedPoints = data.task_points_awarded;
                            }
                            const bonusPossible = typeof data.bonus_possible === 'number' ? data.bonus_possible : this.bonusPossible;
                            if (typeof data.bonus_possible === 'number') {
                                this.bonusPossible = data.bonus_possible;
                            }
                            const bonusAwarded = typeof data.bonus_points_awarded === 'number' ? data.bonus_points_awarded : 0;
                            this.bonusAwarded = bonusAwarded;
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
                                this.summaryBonusEl.textContent = 'Could not update totals?check your connection.';
                            }
                        })
                        .finally(() => {
                            this.sendOvertimeLogs();
                            const currentStats = this.summaryStats || { routine: 0, bonus: 0, account: this.childPointsStart || 0 };
                            const routineTarget = Math.max(0, this.totalEarnedPoints || 0);
                            const bonusTarget = Math.max(0, this.bonusAwarded || 0);
                            const accountTarget = Math.max(0, this.childPoints || 0);
                            const shouldAnimate = routineTarget !== currentStats.routine
                                || bonusTarget !== currentStats.bonus
                                || accountTarget !== currentStats.account;
                            if (shouldAnimate) {
                                this.updateSummaryStats({
                                    routineTarget,
                                    bonusTarget,
                                    accountTarget
                                });
                            }
                        });
                }

                showScene(name) {
                    this.currentScene = name;
                    this.sceneMap.forEach((scene, key) => {
                        if (key === name) {
                            scene.classList.add('active');
                        } else {
                            scene.classList.remove('active');
                        }
                    });
                    if (this.illustrationEl) {
                        this.illustrationEl.classList.toggle('hidden', name !== 'task');
                    }
                    if (this.overlay) {
                        this.overlay.classList.toggle('summary-active', name === 'summary');
                        this.overlay.classList.toggle('status-active', name === 'status');
                    }
                    if (this.summaryHeadingEl) {
                        this.summaryHeadingEl.setAttribute('aria-hidden', name === 'summary' ? 'false' : 'true');
                    }
                    if (this.summaryHeadingTitleEl) {
                        this.summaryHeadingTitleEl.textContent = this.routine.title || 'Routine';
                    }
                    if (name === 'task') {
                        this.clearMessageTimeout();
                        this.hideHoldOverlay();
                        this.updateCountdownVisibility();
                        this.playBackgroundMusic();
                        const scheduled = this.scheduledSeconds;
                        const ratio = scheduled > 0 ? this.elapsedSeconds / Math.max(1, scheduled) : 0;
                        const isOvertime = scheduled > 0 && this.elapsedSeconds > scheduled;
                        this.updateTaskWarning(ratio, isOvertime);
                    } else {
                        const resetAudio = name === 'summary' || name === 'status';
                        this.pauseBackgroundMusic(resetAudio);
                        this.resetWarningState();
                    }
                    if (name === 'status') {
                        this.handleStatusSceneEnter();
                    } else {
                        this.clearStatusAnimations();
                        if (name === 'summary') {
                            this.playSummaryCelebration();
                        }
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

            const libraryFilter = document.querySelector('[data-role="library-filter"]');
            const libraryItems = libraryFilter ? Array.from(document.querySelectorAll('[data-role="library-item"]')) : [];
            if (libraryFilter && libraryItems.length) {
                const updateLibraryVisibility = () => {
                    const value = libraryFilter.value || 'all';
                    libraryItems.forEach(card => {
                        const category = card.getAttribute('data-category') || '';
                        const visible = value === 'all' || category === value;
                        card.style.display = visible ? '' : 'none';
                    });
                };
                libraryFilter.addEventListener('change', updateLibraryVisibility);
                updateLibraryVisibility();
            }

            const taskModal = document.querySelector('[data-role="task-modal"]');
            const openTaskModalButton = document.querySelector('[data-action="open-task-modal"]');
            const closeTaskModalButton = taskModal ? taskModal.querySelector('[data-action="close-task-modal"]') : null;
            let taskModalLastFocus = null;
            const toggleTaskModal = (shouldOpen) => {
                if (!taskModal) return;
                if (shouldOpen) {
                    taskModalLastFocus = document.activeElement;
                    taskModal.classList.add('active');
                    taskModal.setAttribute('aria-hidden', 'false');
                    const firstField = taskModal.querySelector('input, textarea, select');
                    if (firstField) {
                        firstField.focus();
                    }
                } else {
                    taskModal.classList.remove('active');
                    taskModal.setAttribute('aria-hidden', 'true');
                    if (taskModalLastFocus && typeof taskModalLastFocus.focus === 'function') {
                        taskModalLastFocus.focus();
                    }
                }
            };
            if (openTaskModalButton && taskModal) {
                openTaskModalButton.addEventListener('click', () => toggleTaskModal(true));
            }
            if (closeTaskModalButton && taskModal) {
                closeTaskModalButton.addEventListener('click', () => toggleTaskModal(false));
            }
            if (taskModal) {
                taskModal.addEventListener('click', (event) => {
                    if (event.target === taskModal) {
                        toggleTaskModal(false);
                    }
                });
                document.addEventListener('keydown', (event) => {
                    if (event.key === 'Escape' && taskModal.classList.contains('active')) {
                        toggleTaskModal(false);
                    }
                });
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

            const routinePlayers = [];
            (Array.isArray(page.routines) ? page.routines : []).forEach(routine => {
                const card = document.querySelector(`.routine-card[data-routine-id="${routine.id}"]`);
                if (!card) return;
                if (card.classList.contains('child-view')) {
                    const player = new RoutinePlayer(card, routine, page.preferences);
                    routinePlayers.push({ id: String(routine.id), player });
                }
            });

            document.querySelectorAll('[data-toggle-details]').forEach(button => {
                const targetId = button.getAttribute('data-toggle-details');
                const details = targetId ? document.getElementById(targetId) : null;
                if (!details) {
                    return;
                }
                const sync = () => {
                    button.setAttribute('aria-expanded', details.open ? 'true' : 'false');
                };
                button.addEventListener('click', () => {
                    details.open = !details.open;
                    try {
                        console.log('[RoutineCard] toggle details', { details: targetId, open: details.open });
                    } catch (e) {}
                    sync();
                });
                details.addEventListener('toggle', sync);
                sync();
            });

            const params = new URLSearchParams(window.location.search);
            const startParam = params.get('start');
            if (startParam) {
                const match = routinePlayers.find(entry => entry.id === String(startParam));
                if (match) {
                    try {
                        console.log('[RoutinePage] auto-start routine', { routineId: match.id });
                    } catch (e) {}
                    match.player.openFlow();
                    params.delete('start');
                    const newQuery = params.toString();
                    const newUrl = `${window.location.pathname}${newQuery ? `?${newQuery}` : ''}`;
                    window.history.replaceState({}, document.title, newUrl);
                }
            }
        })();
    </script>
</body>
</html>
<?php






