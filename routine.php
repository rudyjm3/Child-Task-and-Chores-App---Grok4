<?php
// routine.php - Routine management (Phase 5 upgrade)
// Provides parent routine builder with validation, timer warnings for children, and overtime tracking.

session_start();

require_once __DIR__ . '/includes/functions.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
$currentPage = basename($_SERVER['PHP_SELF']);

if (!isset($_SESSION['name'])) {
    $_SESSION['name'] = getDisplayName($_SESSION['user_id']);
}

$family_root_id = getFamilyRootId($_SESSION['user_id']);
$isParentContext = canCreateContent($_SESSION['user_id']);

$routinePreferences = getRoutinePreferences($family_root_id);

require_once __DIR__ . '/includes/notifications_bootstrap.php';

$messages = [];
$createRoutineState = ['tasks' => []];
$editRoutineStates = [];
$createFormHasErrors = false;
$editFormErrors = [];
$editFieldOverrides = [];

function routineChildBelongsToFamily(int $child_user_id, int $family_root_id): bool {
    global $db;
    $stmt = $db->prepare("SELECT 1 FROM child_profiles WHERE child_user_id = :child_id AND parent_user_id = :parent_id AND deleted_at IS NULL LIMIT 1");
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

function routineIsScheduledToday(array $routine, string $todayDate, string $todayDay, ?string &$scheduledLabel = null): bool {
    $recurrence = (string) ($routine['recurrence'] ?? '');
    $scheduledLabel = null;
    if ($recurrence === 'daily') {
        return true;
    }
    if ($recurrence === 'weekly') {
        $days = array_filter(array_map('trim', explode(',', (string) ($routine['recurrence_days'] ?? ''))));
        if (empty($days)) {
            return true;
        }
        $scheduledLabel = implode(', ', $days);
        return in_array($todayDay, $days, true);
    }
    $routineDate = $routine['routine_date'] ?? null;
    if (!$routineDate && !empty($routine['created_at'])) {
        $routineDate = date('Y-m-d', strtotime($routine['created_at']));
    }
    if ($routineDate) {
        $scheduledLabel = $routineDate;
        return $routineDate === $todayDate;
    }
    return true;
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
        $routine = getRoutineWithTasks($routineId);
        if (!$routine) {
            http_response_code(404);
            echo json_encode(['status' => 'error', 'message' => 'Routine not found.']);
            exit;
        }
        $todayDate = date('Y-m-d');
        $todayDay = date('D');
        $scheduleLabel = null;
        if (!routineIsScheduledToday($routine, $todayDate, $todayDay, $scheduleLabel)) {
            $message = 'This routine can only be completed on its scheduled day.';
            if ($scheduleLabel) {
                if ($routine['recurrence'] === 'weekly') {
                    $message = 'This routine can only be completed on: ' . $scheduleLabel . '.';
                } else {
                    $message = 'This routine is scheduled for ' . date('m/d/Y', strtotime($scheduleLabel)) . ' and can only be completed that day.';
                }
            }
            echo json_encode(['status' => 'not_today', 'message' => $message]);
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
        $flowStartTs = isset($_POST['flow_start_ts']) ? (int) $_POST['flow_start_ts'] : null;
        $flowEndTs = isset($_POST['flow_end_ts']) ? (int) $_POST['flow_end_ts'] : null;
        $overtimeCountInput = isset($_POST['overtime_count']) ? (int) $_POST['overtime_count'] : 0;
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
        $todayDate = date('Y-m-d');
        $todayDay = date('D');
        $scheduleLabel = null;
        if (!routineIsScheduledToday($routine, $todayDate, $todayDay, $scheduleLabel)) {
            $message = 'This routine can only be completed on its scheduled day.';
            if ($scheduleLabel) {
                if ($routine['recurrence'] === 'weekly') {
                    $message = 'This routine can only be completed on: ' . $scheduleLabel . '.';
                } else {
                    $message = 'This routine is scheduled for ' . date('m/d/Y', strtotime($scheduleLabel)) . ' and can only be completed that day.';
                }
            }
            echo json_encode(['status' => 'not_today', 'message' => $message]);
            exit;
        }
        $childId = (int) $_SESSION['user_id'];
        ensureRoutinePointsLogsTable();
        $stmt = $db->prepare("SELECT created_at FROM routine_points_logs WHERE routine_id = :routine_id AND child_user_id = :child_id AND DATE(created_at) = :today ORDER BY created_at DESC LIMIT 1");
        $stmt->execute([
            ':routine_id' => $routineId,
            ':child_id' => $childId,
            ':today' => $todayDate
        ]);
        $lastCompletion = $stmt->fetchColumn();
        if ($lastCompletion) {
            $formatted = date('m/d/Y h:i A', strtotime($lastCompletion));
            echo json_encode([
                'status' => 'already_completed',
                'message' => 'You already completed this routine on ' . $formatted . '. It cannot be completed again today.',
                'completed_at' => $formatted
            ]);
            exit;
        }
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
                        'scheduled_seconds' => max(0, (int) ($entry['scheduled_seconds'] ?? 0)),
                        'completed_at_ms' => max(0, (int) ($entry['completed_at_ms'] ?? 0)),
                        'status_screen_seconds' => max(0, (int) ($entry['status_screen_seconds'] ?? 0))
                    ];
                }
            }
        }

        $awards = [];
        $taskPointsAwarded = 0;
        $allWithinLimits = true;
        $overtimeCount = 0;

        foreach ($taskLookup as $taskId => $taskRow) {
            $scheduledSeconds = max(0, (int) ($taskRow['time_limit'] ?? 0) * 60);
            $pointValue = max(0, (int) ($taskRow['point_value'] ?? 0));
            $actualSeconds = $scheduledSeconds;
            if (isset($metricsById[$taskId])) {
                $actualSeconds = max(0, (int) $metricsById[$taskId]['actual_seconds']);
            }
            $awardedPoints = calculateRoutineTaskAwardPoints($pointValue, $scheduledSeconds, $actualSeconds);
            $starsAwarded = calculateRoutineTaskStars($scheduledSeconds, $actualSeconds);
            if ($scheduledSeconds > 0 && $actualSeconds > $scheduledSeconds) {
                $allWithinLimits = false;
                $overtimeCount++;
            }
            $taskPointsAwarded += $awardedPoints;
            $awards[] = [
                'id' => $taskId,
                'title' => $taskRow['title'],
                'point_value' => $pointValue,
                'scheduled_seconds' => $scheduledSeconds,
                'actual_seconds' => $actualSeconds,
                'awarded_points' => $awardedPoints,
                'stars_awarded' => $starsAwarded
            ];
            setRoutineTaskStatus($routineId, $taskId, 'completed');
        }

        $bonusPossible = max(0, (int) ($routine['bonus_points'] ?? 0));
        $maxRoutinePoints = array_reduce($taskLookup, static function ($carry, $task) {
            return $carry + max(0, (int) ($task['point_value'] ?? 0));
        }, 0);
        $routineDurationSeconds = array_reduce($awards, static function ($carry, $entry) {
            return $carry + max(0, (int) ($entry['actual_seconds'] ?? 0));
        }, 0);

          if ($taskPointsAwarded > 0) {
              updateChildPoints($childId, $taskPointsAwarded);
        }

        $grantBonus = $allWithinLimits && count($awards) === count($taskLookup);
        $bonus = completeRoutine($routineId, $childId, $grantBonus);
        $bonusAwarded = is_numeric($bonus) ? (int) $bonus : 0;
        $_SESSION['routine_awards'][$routineId] = true;
        $newTotal = getChildTotalPoints($childId);
        logRoutinePointsAward($routineId, $childId, $taskPointsAwarded, $bonusAwarded);
  
          refreshRoutineGoalsForChild($childId, $routineId);
        $parentIdForLog = (int) ($routine['parent_user_id'] ?? 0);
        $startedAt = null;
        if (!empty($flowStartTs)) {
            $startedAt = date('Y-m-d H:i:s', (int) floor($flowStartTs / 1000));
        }
        $completedAt = null;
        if (!empty($flowEndTs)) {
            $completedAt = date('Y-m-d H:i:s', (int) floor($flowEndTs / 1000));
        }
        if (!$completedAt) {
            $completedAt = date('Y-m-d H:i:s');
        }
        $completionTasks = [];
        foreach ($taskLookup as $taskId => $taskRow) {
            $metric = $metricsById[$taskId] ?? [];
            $taskCompletedAt = null;
            if (!empty($metric['completed_at_ms'])) {
                $taskCompletedAt = date('Y-m-d H:i:s', (int) floor($metric['completed_at_ms'] / 1000));
            }
            $scheduledSeconds = null;
            if (array_key_exists('scheduled_seconds', $metric)) {
                $scheduledSeconds = (int) ($metric['scheduled_seconds'] ?? 0);
            }
            $actualSeconds = null;
            if (array_key_exists('actual_seconds', $metric)) {
                $actualSeconds = (int) ($metric['actual_seconds'] ?? 0);
            }
            $completionTasks[] = [
                'routine_task_id' => $taskId,
                'sequence_order' => (int) ($taskRow['sequence_order'] ?? 0),
                'completed_at' => $taskCompletedAt,
                'scheduled_seconds' => $scheduledSeconds,
                'actual_seconds' => $actualSeconds,
                'status_screen_seconds' => max(0, (int) ($metric['status_screen_seconds'] ?? 0)),
                'stars_awarded' => calculateRoutineTaskStars((int) ($scheduledSeconds ?? 0), (int) ($actualSeconds ?? 0))
            ];
        }
        if ($parentIdForLog > 0) {
            logRoutineCompletionSession($routineId, $childId, $parentIdForLog, 'child', $startedAt, $completedAt, $completionTasks);
            updateChildLevelState($childId, $parentIdForLog, true);
        }

        if (!empty($routine['parent_user_id'])) {
            $parentIdForNote = (int) $routine['parent_user_id'];
            $endTsLocal = time();
            $childName = $_SESSION['name'] ?? $_SESSION['username'] ?? 'Child';
            $totalEarned = $taskPointsAwarded + $bonusAwarded;
            $bonusNote = $bonusAwarded > 0
                ? "Bonus {$bonusAwarded} unlocked"
                : "Bonus {$bonusAwarded}/{$bonusPossible}. Bonus Criteria Not Met";
            $message = sprintf(
                '%s completed %s. Points %d/%d. %s. Overtime tasks: %d. Total earned: %d.',
                substr((string) $childName, 0, 30),
                substr((string) ($routine['title'] ?? 'Routine'), 0, 40),
                $taskPointsAwarded,
                $maxRoutinePoints,
                $bonusNote,
                max($overtimeCountInput, $overtimeCount),
                $totalEarned
            );
            $link = 'dashboard_parent.php?overtime_routine=' . $routineId . '#overtime';
            addParentNotification($parentIdForNote, 'routine_completed', $message, $link);
        }

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
        $childIds = array_values(array_filter(array_map('intval', $_POST['child_user_ids'] ?? [])));
        if (empty($childIds)) {
            $duplicateChildId = filter_input(INPUT_POST, 'duplicate_child_id', FILTER_VALIDATE_INT);
            if ($duplicateChildId) {
                $childIds = [(int) $duplicateChildId];
            }
        }
        $title = trim((string) filter_input(INPUT_POST, 'title', FILTER_SANITIZE_STRING));
        $childIds = array_values(array_filter(array_map('intval', $_POST['child_user_ids'] ?? [])));
        if (empty($childIds)) {
            $child_id = filter_input(INPUT_POST, 'child_user_id', FILTER_VALIDATE_INT);
            if ($child_id) {
                $childIds = [(int) $child_id];
            }
        }
        $start_time = filter_input(INPUT_POST, 'start_time', FILTER_SANITIZE_STRING);
        $end_time = filter_input(INPUT_POST, 'end_time', FILTER_SANITIZE_STRING);
        $recurrence = filter_input(INPUT_POST, 'recurrence', FILTER_SANITIZE_STRING);
        $bonus_points = filter_input(INPUT_POST, 'bonus_points', FILTER_VALIDATE_INT);
        $time_of_day_input = filter_input(INPUT_POST, 'time_of_day', FILTER_SANITIZE_STRING);
        $time_of_day = in_array($time_of_day_input, ['anytime', 'morning', 'afternoon', 'evening'], true) ? $time_of_day_input : 'anytime';
        $routine_date = filter_input(INPUT_POST, 'routine_date', FILTER_SANITIZE_STRING);
        $recurrence_days = null;
        $structureRaw = $_POST['routine_structure'] ?? '';

        $recurrence = in_array($recurrence, ['daily', 'weekly'], true) ? $recurrence : '';
        $bonus_points = ($bonus_points !== false && $bonus_points >= 0) ? $bonus_points : 0;
        if ($recurrence === 'weekly') {
            $days = $_POST['recurrence_days'] ?? [];
            $days = array_values(array_filter(array_map('trim', (array) $days)));
            $recurrence_days = !empty($days) ? implode(',', $days) : null;
        }
        if ($recurrence !== '') {
            $routine_date = null;
        }

        $errors = [];
        if (empty($childIds)) {
            $errors[] = 'Select at least one child for this routine.';
        } else {
            $childIds = array_values(array_filter($childIds, static function ($id) use ($family_root_id) {
                return $id > 0 && routineChildBelongsToFamily($id, $family_root_id);
            }));
            if (empty($childIds)) {
                $errors[] = 'Select a child from your family for this routine.';
            }
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
                $createdCount = 0;
                foreach ($childIds as $cid) {
                    $routineId = createRoutine($family_root_id, $cid, $title, $start_time, $end_time, $recurrence, $bonus_points, $time_of_day, $recurrence_days, $routine_date, $_SESSION['user_id']);
                    replaceRoutineTasks($routineId, $normalizedTasks);
                    $createdCount++;
                }
                $db->commit();
                $messages[] = ['type' => 'success', 'text' => $createdCount > 1 ? "Routine created for {$createdCount} children." : 'Routine created successfully.'];
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
        $childIds = array_values(array_filter(array_map('intval', $_POST['child_user_ids'] ?? [])));
        if (empty($childIds)) {
            $child_id = filter_input(INPUT_POST, 'child_user_id', FILTER_VALIDATE_INT);
            if ($child_id) {
                $childIds = [(int) $child_id];
            }
        }
        $start_time = filter_input(INPUT_POST, 'start_time', FILTER_SANITIZE_STRING);
        $end_time = filter_input(INPUT_POST, 'end_time', FILTER_SANITIZE_STRING);
        $recurrence = filter_input(INPUT_POST, 'recurrence', FILTER_SANITIZE_STRING);
        $bonus_points = filter_input(INPUT_POST, 'bonus_points', FILTER_VALIDATE_INT);
        $time_of_day_input = filter_input(INPUT_POST, 'time_of_day', FILTER_SANITIZE_STRING);
        $time_of_day = in_array($time_of_day_input, ['anytime', 'morning', 'afternoon', 'evening'], true) ? $time_of_day_input : 'anytime';
        $routine_date = filter_input(INPUT_POST, 'routine_date', FILTER_SANITIZE_STRING);
        $recurrence_days = null;
        $structureRaw = $_POST['routine_structure'] ?? '';

        $recurrence = in_array($recurrence, ['daily', 'weekly'], true) ? $recurrence : '';
        $bonus_points = ($bonus_points !== false && $bonus_points >= 0) ? $bonus_points : 0;
        if ($recurrence === 'weekly') {
            $days = $_POST['recurrence_days'] ?? [];
            $days = array_values(array_filter(array_map('trim', (array) $days)));
            $recurrence_days = !empty($days) ? implode(',', $days) : null;
        }
        if ($recurrence !== '') {
            $routine_date = null;
        }

        $errors = [];
        if (!$routine_id || !routineBelongsToParent($routine_id, $family_root_id)) {
            $errors[] = 'Unable to locate that routine for editing.';
        }
        if (empty($childIds)) {
            $errors[] = 'Select a child from your family for this routine.';
        } else {
            $childIds = array_values(array_filter($childIds, static function ($id) use ($family_root_id) {
                return $id > 0 && routineChildBelongsToFamily($id, $family_root_id);
            }));
            if (empty($childIds)) {
                $errors[] = 'Select a child from your family for this routine.';
            }
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
                $currentChildId = null;
                $childStmt = $db->prepare("SELECT child_user_id FROM routines WHERE id = :id AND parent_user_id = :parent_id");
                $childStmt->execute([':id' => $routine_id, ':parent_id' => $family_root_id]);
                $currentChildId = (int) $childStmt->fetchColumn();

                $primaryChildId = $childIds[0];
                if ($currentChildId && in_array($currentChildId, $childIds, true)) {
                    $primaryChildId = $currentChildId;
                }
                updateRoutine($routine_id, $primaryChildId, $title, $start_time, $end_time, $recurrence, $bonus_points, $time_of_day, $recurrence_days, $routine_date, $family_root_id);
                replaceRoutineTasks($routine_id, $normalizedTasks);
                $extraChildren = array_values(array_filter($childIds, static function ($id) use ($primaryChildId) {
                    return (int) $id !== (int) $primaryChildId;
                }));
                foreach ($extraChildren as $cid) {
                    $newRoutineId = createRoutine($family_root_id, $cid, $title, $start_time, $end_time, $recurrence, $bonus_points, $time_of_day, $recurrence_days, $routine_date, $_SESSION['user_id']);
                    replaceRoutineTasks($newRoutineId, $normalizedTasks);
                }
                $db->commit();
                if (!empty($extraChildren)) {
                    $messages[] = ['type' => 'success', 'text' => 'Routine updated and copied to additional children.'];
                } else {
                    $messages[] = ['type' => 'success', 'text' => 'Routine updated successfully.'];
                }
            } catch (Exception $e) {
                $db->rollBack();
                error_log('Routine update failed: ' . $e->getMessage());
                $messages[] = ['type' => 'error', 'text' => 'Failed to update routine. Please retry.'];
                $editRoutineStates[$routine_id] = $sanitizedStructure;
                $editFormErrors[$routine_id] = true;
                $editFieldOverrides[$routine_id] = [
                    'child_user_ids' => $childIds,
                    'title' => $title,
                    'start_time' => $start_time,
                    'end_time' => $end_time,
                    'bonus_points' => $bonus_points,
                    'recurrence' => $recurrence,
                    'time_of_day' => $time_of_day,
                    'recurrence_days' => $recurrence_days,
                    'routine_date' => $routine_date
                ];
            }
        } else {
            $messages[] = ['type' => 'error', 'text' => implode(' ', array_unique($errors))];
            if ($routine_id) {
                $editRoutineStates[$routine_id] = $sanitizedStructure;
                $editFormErrors[$routine_id] = true;
                $editFieldOverrides[$routine_id] = [
                    'child_user_ids' => $childIds,
                    'title' => $title,
                    'start_time' => $start_time,
                    'end_time' => $end_time,
                    'bonus_points' => $bonus_points,
                    'recurrence' => $recurrence,
                    'time_of_day' => $time_of_day,
                    'recurrence_days' => $recurrence_days,
                    'routine_date' => $routine_date
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
        $soundEffectsEnabled = isset($_POST['sound_effects_enabled']) ? 1 : 0;
        $backgroundMusicEnabled = isset($_POST['background_music_enabled']) ? 1 : 0;
        $label = $routinePreferences['sub_timer_label'] ?? 'hurry_goal';
        if (!is_string($label) || $label === '') {
            $label = 'hurry_goal';
        }
        $progressStyle = isset($_POST['progress_style']) ? $_POST['progress_style'] : 'bar';
        if (saveRoutinePreferences($family_root_id, $timerWarnings, $label, $showCountdown, $progressStyle, $soundEffectsEnabled, $backgroundMusicEnabled)) {
            $routinePreferences = getRoutinePreferences($family_root_id);
            $messages[] = ['type' => 'success', 'text' => 'Routine timer preferences saved.'];
        } else {
            $messages[] = ['type' => 'error', 'text' => 'Failed to save routine preferences.'];
        }
    } elseif ($isParentContext && isset($_POST['parent_complete_routine'])) {
        $routine_id = filter_input(INPUT_POST, 'routine_id', FILTER_VALIDATE_INT);
        $completedRaw = $_POST['parent_completed'] ?? [];
        $completedAtRaw = $_POST['parent_completed_at'] ?? '';
        $selected = [];
        if (is_array($completedRaw)) {
            foreach ($completedRaw as $value) {
                $selected[] = (int) $value;
            }
        }
        $completedAtMap = [];
        if (is_string($completedAtRaw) && $completedAtRaw !== '') {
            $decoded = json_decode($completedAtRaw, true);
            if (is_array($decoded)) {
                foreach ($decoded as $taskId => $timestamp) {
                    $taskKey = (int) $taskId;
                    $timeValue = (int) $timestamp;
                    if ($taskKey > 0 && $timeValue > 0) {
                        $completedAtMap[$taskKey] = $timeValue;
                    }
                }
            }
        }
        if (!$routine_id || !routineBelongsToParent($routine_id, $family_root_id)) {
            $messages[] = ['type' => 'error', 'text' => 'Unable to complete routine for this child.'];
        } else {
            $routineData = getRoutineWithTasks($routine_id);
            if (!$routineData) {
                $messages[] = ['type' => 'error', 'text' => 'Routine could not be loaded.'];
            } else {
                $childId = (int) ($routineData['child_user_id'] ?? 0);
                $todayDate = date('Y-m-d');
                $alreadyCompleted = false;
                if ($childId > 0) {
                    ensureRoutinePointsLogsTable();
                    $logStmt = $db->prepare("SELECT created_at FROM routine_points_logs WHERE routine_id = :routine_id AND child_user_id = :child_id AND DATE(created_at) = :today ORDER BY created_at DESC LIMIT 1");
                    $logStmt->execute([
                        ':routine_id' => $routine_id,
                        ':child_id' => $childId,
                        ':today' => $todayDate
                    ]);
                    $lastCompletion = $logStmt->fetchColumn();
                    if ($lastCompletion) {
                        $formatted = date('m/d/Y h:i A', strtotime($lastCompletion));
                        $messages[] = ['type' => 'error', 'text' => 'Routine already completed today at ' . $formatted . '.'];
                        $alreadyCompleted = true;
                    }
                }
                if ($alreadyCompleted) {
                    // Skip updates if already completed today.
                } else {
                $tasks = $routineData['tasks'] ?? [];
                $pendingBefore = 0;
                $taskMap = [];
                $completedTodayMap = [];
                foreach ($tasks as $task) {
                    $taskId = (int) $task['id'];
                    $taskMap[$taskId] = $task;
                    $completedToday = false;
                    $completedAt = $task['completed_at'] ?? null;
                    if (!empty($completedAt) && ($task['status'] ?? 'pending') === 'completed') {
                        $completedDate = date('Y-m-d', strtotime($completedAt));
                        $completedToday = ($completedDate === $todayDate);
                    }
                    $completedTodayMap[$taskId] = $completedToday;
                    if (!$completedToday) {
                        $pendingBefore++;
                    }
                }
                $selected = array_values(array_unique(array_filter($selected, function ($id) use ($taskMap) {
                    return isset($taskMap[$id]);
                })));

                $awardedPoints = 0;
                $completionTimestampMap = [];
                foreach ($tasks as $task) {
                    $taskId = (int) $task['id'];
                    $isSelected = in_array($taskId, $selected, true);
                    $status = $isSelected ? 'completed' : 'pending';
                    $completedAtValue = null;
                    if ($isSelected) {
                        if (!empty($completedTodayMap[$taskId]) && !empty($taskMap[$taskId]['completed_at'])) {
                            $completedAtValue = $taskMap[$taskId]['completed_at'];
                        } elseif (!empty($completedAtMap[$taskId])) {
                            $completedAtValue = date('Y-m-d H:i:s', (int) floor($completedAtMap[$taskId] / 1000));
                        } else {
                            $completedAtValue = date('Y-m-d H:i:s');
                        }
                        $completionTimestampMap[$taskId] = $completedAtValue;
                    }
                    setRoutineTaskStatus($routine_id, $taskId, $status, $completedAtValue);
                    if ($isSelected && empty($completedTodayMap[$taskId])) {
                        $awardedPoints += max(0, (int) ($task['point_value'] ?? $task['points'] ?? 0));
                    }
                }

                if ($awardedPoints > 0 && $childId > 0) {
                    updateChildPoints($childId, $awardedPoints);
                }
                $awardCount = count($selected);
                $allSelected = !empty($tasks) && $awardCount === count($tasks);
                $grantBonus = $pendingBefore > 0 && $allSelected;
                $bonusAwarded = 0;
                if ($childId > 0) {
                    $bonusAwarded = completeRoutine($routine_id, $childId, $grantBonus);
                }
                $shouldLogCompletion = $childId > 0 && $allSelected && $pendingBefore > 0;
                if ($childId > 0 && ($awardedPoints > 0 || $bonusAwarded > 0 || $shouldLogCompletion)) {
                    logRoutinePointsAward($routine_id, $childId, $awardedPoints, $bonusAwarded);
                }
                if ($shouldLogCompletion) {
                    $parentIdForLog = (int) ($routineData['parent_user_id'] ?? 0);
                    if ($parentIdForLog > 0) {
                        $parentStartedAt = null;
                        $parentCompletedAt = null;
                        if (!empty($completionTimestampMap)) {
                            $timestampValues = [];
                            foreach ($completionTimestampMap as $stamp) {
                                $parsed = strtotime($stamp);
                                if ($parsed !== false) {
                                    $timestampValues[] = $parsed;
                                }
                            }
                            if (!empty($timestampValues)) {
                                $parentStartedAt = date('Y-m-d H:i:s', min($timestampValues));
                                $parentCompletedAt = date('Y-m-d H:i:s', max($timestampValues));
                            }
                        }
                        $completionTasks = [];
                        foreach ($tasks as $task) {
                            $taskId = (int) ($task['id'] ?? 0);
                            $completionTasks[] = [
                                'routine_task_id' => $taskId,
                                'sequence_order' => (int) ($task['sequence_order'] ?? 0),
                                'completed_at' => $completionTimestampMap[$taskId] ?? null,
                                'scheduled_seconds' => null,
                                'actual_seconds' => null,
                                'status_screen_seconds' => 0,
                                'stars_awarded' => 3
                            ];
                        }
                        logRoutineCompletionSession($routine_id, $childId, $parentIdForLog, 'parent', $parentStartedAt, $parentCompletedAt, $completionTasks);
                        updateChildLevelState($childId, $parentIdForLog, true);
                    }
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
}

$routine_tasks = $isParentContext ? getRoutineTasks($family_root_id) : [];
$routines = getRoutines($_SESSION['user_id']);
$routineCompletionMap = [];
if (getEffectiveRole($_SESSION['user_id']) === 'child') {
    ensureRoutinePointsLogsTable();
    $todayDate = date('Y-m-d');
    $stmt = $db->prepare("SELECT routine_id, created_at FROM routine_points_logs WHERE child_user_id = :child_id AND DATE(created_at) = :today ORDER BY created_at DESC");
    $stmt->execute([':child_id' => (int) $_SESSION['user_id'], ':today' => $todayDate]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rid = (int) ($row['routine_id'] ?? 0);
        if ($rid && empty($routineCompletionMap[$rid])) {
            $routineCompletionMap[$rid] = $row['created_at'];
        }
    }
}
$todayDate = date('Y-m-d');

foreach ($routines as &$routineEntry) {
    $tasks = $routineEntry['tasks'] ?? [];
    $completedTodayCount = 0;
    foreach ($tasks as &$task) {
        $completedAt = $task['completed_at'] ?? null;
        $completedToday = false;
        if (!empty($completedAt)) {
            $completedDate = date('Y-m-d', strtotime($completedAt));
            $completedToday = $completedDate === $todayDate && ($task['status'] ?? 'pending') === 'completed';
        }
        $task['completed_today'] = $completedToday;
        if (!$completedToday) {
            $task['status'] = 'pending';
        }
        if ($completedToday) {
            $completedTodayCount++;
        }
    }
    unset($task);
    $routineEntry['tasks'] = $tasks;
    $routineEntry['completed_today'] = !empty($tasks) && $completedTodayCount === count($tasks);
    if (!empty($routineCompletionMap[(int) $routineEntry['id']])) {
        $routineEntry['completed_today'] = true;
        $routineEntry['last_completed_at'] = $routineCompletionMap[(int) $routineEntry['id']];
    }
}
unset($routineEntry);

foreach ($routines as &$routineEntry) {
    $timerWarningEnabled = !empty($routinePreferences['timer_warnings_enabled']) ? 1 : 0;
    $routineEntry['timer_warnings_enabled'] = $timerWarningEnabled;
    $routineEntry['show_countdown'] = isset($routinePreferences['show_countdown'])
        ? (int) $routinePreferences['show_countdown']
        : 1;
    $routineEntry['sound_effects_enabled'] = isset($routinePreferences['sound_effects_enabled'])
        ? (int) $routinePreferences['sound_effects_enabled']
        : 1;
    $routineEntry['background_music_enabled'] = isset($routinePreferences['background_music_enabled'])
        ? (int) $routinePreferences['background_music_enabled']
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
$stmt = $db->prepare("
    SELECT 
        cp.child_user_id, 
        cp.child_name, 
        COALESCE(cp.avatar, 'images/default-avatar.png') AS child_avatar
    FROM child_profiles cp
    WHERE cp.parent_user_id = :parent AND cp.deleted_at IS NULL
    ORDER BY cp.child_name ASC
");
$stmt->execute([':parent' => $family_root_id]);
$children = $stmt->fetchAll(PDO::FETCH_ASSOC);
$selectedCreateChildIds = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_routine'])) {
    $selectedCreateChildIds = array_values(array_filter(array_map('intval', $_POST['child_user_ids'] ?? [])));
}
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
        : 'bar',
    'sound_effects_enabled' => isset($routinePreferences['sound_effects_enabled'])
        ? (int) $routinePreferences['sound_effects_enabled']
        : 1,
    'background_music_enabled' => isset($routinePreferences['background_music_enabled'])
        ? (int) $routinePreferences['background_music_enabled']
        : 1
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
    <link rel="stylesheet" href="css/main.css?v=3.26.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css" integrity="Evv84Mr4kqVGRNSgIGL/F/aIDqQb7xQ2vcrdIwxfjThSH8CSR7PBEakCr51Ck+w+/U6swU2Im1vVX0SVk9ABhg==" crossorigin="anonymous" referrerpolicy="no-referrer">
    <style>
        .page-messages { max-width: 960px; margin: 0 auto 20px; }
        .page-alert { padding: 12px 16px; border-radius: 6px; margin-bottom: 12px; font-weight: 600; }
        .page-alert.success { background: #e8f5e9; color: #2e7d32; border: 1px solid #c8e6c9; }
        .page-alert.error { background: #ffebee; color: #c62828; border: 1px solid #ffcdd2; }
        .page-alert.info { background: #e3f2fd; color: #1565c0; border: 1px solid #bbdefb; }
        .role-badge { margin-left: 8px; padding: 2px 8px; border-radius: 999px; background: #4caf50; color: #fff; font-size: 0.82rem; }
        .routine-layout { max-width: 1080px; margin: 0 auto; padding: 0 16px 40px; }
        .routine-section { background: #fff; border-radius: 10px; box-shadow: 0 2px 6px rgba(0,0,0,0.08); padding: 20px; margin-bottom: 24px; }
        /* .routine-section-header { background: linear-gradient(135deg, #333333ff, #5e6164ff); padding: 16px 20px; margin: -20px -20px 16px; border-radius: 10px 10px 0px 0px; color: #f5f7fa; display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; box-shadow: 0 8px 20px rgba(0,0,0,0.18); } */
        /* .routine-section-header {background: linear-gradient(90deg, #1c2c63 0%, #325d93 100%);
  color: #f5f7fa;
  padding: 16px 24px;
  border-radius: 10px 10px 0 0;
  font-size: 1.3rem;
  letter-spacing: 0.4px;
display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; box-shadow: 0 8px 20px rgba(0,0,0,0.18);} */
.routine-section-header { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; /*background: rgba(255, 255, 255, 0.6); background: linear-gradient(90deg, #1c2c63 0%, #325d93 100%);
  backdrop-filter: blur(12px);
  border: 1px solid rgba(255,255,255,0.35);
  border-radius: 12px;
  box-shadow: 0 4px 12px rgba(0,0,0,0.06); */
  padding: 14px 22px;
  font-size: 1.3rem;
  letter-spacing: 0.4px;
  font-weight: 500;
  color: #5b5b5b;
margin-bottom: 20px;}
        .routine-section-header h2 { margin: 0; /*color: #f5f7fa;*/ font-size: 1.2rem; letter-spacing: 0.02em; }
        .routine-header-actions { display: inline-flex; align-items: center; gap: 12px; flex-wrap: wrap; }
        .routine-view-row { display: flex; justify-content: flex-start; align-items: center; margin-bottom: 12px; }
        .routine-view-toggle { display: inline-flex; align-items: center; gap: 6px; padding: 4px; border-radius: 999px; border: 1px solid #d5def0; background: #f5f7fb; }
        .routine-view-button { width: 36px; height: 36px; border: none; border-radius: 50%; background: transparent; color: #607d8b; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; }
        .routine-view-button.active { background: #0d47a1; color: #fff; box-shadow: 0 4px 10px rgba(13, 71, 161, 0.2); }
        .routine-filters { display: grid; gap: 12px; margin-bottom: 16px; }
        .routine-filter-header { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; }
        .routine-filter-title { font-weight: 700; color: #37474f; }
        .routine-select-all { display: inline-flex; align-items: center; gap: 8px; font-weight: 600; color: #37474f; }
        .routine-select-all input { width: 18px; height: 18px; }
        .routine-child-grid { display: flex; flex-wrap: wrap; gap: 14px; }
        .routine-child-card { border: none; border-radius: 50%; padding: 0; background: transparent; display: grid; justify-items: center; gap: 6px; cursor: pointer; position: relative; }
        .routine-child-card input[type="checkbox"] { position: absolute; opacity: 0; width: 0; height: 0; pointer-events: none; }
        .routine-child-card img { width: 56px; height: 56px; border-radius: 50%; object-fit: cover; box-shadow: 0 2px 6px rgba(0,0,0,0.15); transition: box-shadow 150ms ease, transform 150ms ease; }
        .routine-child-card span { font-size: 13px; text-align: center; transition: color 150ms ease, text-shadow 150ms ease; }
        .routine-child-card input[type="checkbox"]:checked + img { box-shadow: 0 0 0 4px rgba(100,181,246,0.8), 0 0 14px rgba(100,181,246,0.8); transform: translateY(-2px); }
        .routine-child-card input[type="checkbox"]:checked + img + span { color: #0d47a1; text-shadow: 0 1px 8px rgba(100,181,246,0.8); }
        .routine-card-grid { display: grid; gap: 20px; grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .routine-card-grid.list-view { grid-template-columns: 1fr; }
        .routine-card.is-hidden { display: none; }
        @media (max-width: 768px) {
            .routine-card-grid { grid-template-columns: 1fr; }
        }
        .routine-section h2 { margin-top: 0; font-size: 1.5rem; }
        .form-grid { display: grid; gap: 16px; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); }
        .form-group { display: flex; flex-direction: column; gap: 6px; }
        .child-select-group { grid-column: 1 / -1; display: grid; grid-template-columns: auto 1fr; gap: 12px; align-items: center; }
        @media (max-width: 640px) {
            .child-select-group { grid-template-columns: 1fr; }
        }
        .form-group label { font-weight: 600; }
        .form-group input, .form-group select, .form-group textarea { padding: 8px; border: 1px solid #ccc; border-radius: 6px; font-size: 0.95rem; }
        .form-actions { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 12px; }
        .repeat-days { display: none; }
        .repeat-days-label { font-weight: 600; padding: 10px 0 4px; }
        .repeat-days-grid { display: flex; flex-wrap: wrap; gap: 8px; }
        .repeat-day { position: relative; cursor: pointer; }
        .repeat-day input { position: absolute; opacity: 0; width: 0; height: 0; }
        .repeat-day span { width: 36px; height: 36px; border-radius: 50%; background: #ededed; color: #8e8e8e; display: inline-flex; align-items: center; justify-content: center; font-weight: 600; font-size: 0.85rem; transition: background 150ms ease, color 150ms ease; }
        .repeat-day input:checked + span { background: #46a0f4; color: #f9f9f9; }
        .button { padding: 10px 18px; border: none; border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 0.95rem; background: #4caf50; color: #fff; height: max-content; }
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
        .task-meta { font-size: 0.85rem; color: #616161; display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
        .task-modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.65); display: flex; align-items: center; justify-content: center; padding: 20px; z-index: 4400; opacity: 0; pointer-events: none; transition: opacity 200ms ease; }
        .task-modal-overlay.active { opacity: 1; pointer-events: auto; }
        .task-modal { background: #fff; border-radius: 14px; max-width: 520px; width: min(520px, 100%); max-height: 90vh; overflow: hidden; padding: 28px; position: relative; box-shadow: 0 18px 36px rgba(0,0,0,0.25); display: flex; flex-direction: column; }
        .task-modal h3 { margin-top: 0; }
        .task-modal-close { position: absolute; top: 12px; right: 12px; border: none; background: transparent; font-size: 1.5rem; line-height: 1; cursor: pointer; color: #455a64; }
        .task-modal .library-form { overflow-y: auto; flex: 1 1 auto; padding-right: 4px; }
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
        .library-task-points { background-color: #4caf50; color: #fff; padding: 2px 8px; border-radius: 50px; font-size: 0.7rem; font-weight: 700; white-space: nowrap; }
        .child-theme .library-task-points { background: #fffbeb; color: #f59e0b; padding: 4px 10px; border-radius: 999px; display: inline-flex; align-items: center; gap: 6px; }
        .child-theme .library-task-points::before { content: '\f005'; font-family: 'Font Awesome 6 Free'; font-weight: 900; }
        .points-badge { background: #fffbeb; color: #f59e0b; padding: 4px 10px; border-radius: 999px; font-weight: 700; font-size: 0.8rem; display: inline-flex; align-items: center; gap: 6px; white-space: nowrap; }
        .points-badge::before { content: '\f005'; font-family: 'Font Awesome 6 Free'; font-weight: 900; }
        .points-badge.bonus { background: #e8f5e9; color: #2e7d32; }
        .library-task-description { margin: 0; font-size: 0.9rem; color: #546e7a; }
        .library-task-meta { display: flex; flex-wrap: wrap; gap: 8px; font-size: 0.85rem; color: #37474f; }
        .library-task-meta span { background: #f0f4f7; border-radius: 999px; padding: 4px 10px; }
        .library-task-actions { margin-top: auto; display: inline-flex; gap: 8px; align-items: center; align-self: flex-end; }
        .routine-card { border: 1px solid #e0e0e0; border-radius: 12px; padding: 18px; margin-bottom: 20px; background: linear-gradient(145deg, #ffffff, #f5f5f5); box-shadow: 0 3px 8px rgba(0,0,0,0.08); }
        .routine-card.child-view { background: linear-gradient(160deg, #e3f2fd, #e8f5e9); border-color: #bbdefb; }
        .routine-card header { display: flex; flex-direction: column; gap: 4px; margin-bottom: 12px; }
        .routine-card h3 { margin: 0; font-size: 1.25rem; }
        .routine-details { font-size: 0.9rem; color: #455a64; display: grid; gap: 4px; }
        .routine-details span { display: flex; align-items: center; gap: 6px; }
        .routine-meta-icon { color: #919191; }
        .routine-assignee { display: inline-flex; align-items: center; gap: 8px; font-size: 0.9rem; color: #37474f; }
        .routine-assignee img { width: 32px; height: 32px; border-radius: 50%; object-fit: cover; border: 2px solid rgba(0,0,0,0.05); }
        .task-list { list-style: none; margin: 0; padding: 0; display: grid; gap: 8px; }
        .task-list li { background: rgba(255,255,255,0.85); border-radius: 8px; padding: 10px 12px; border-left: 4px solid #64b5f6; }
        .task-list li .dependency { font-size: 0.8rem; color: #6d4c41; }
        .card-actions { margin-top: 16px; display: flex; flex-wrap: wrap; gap: 12px; }
        .routine-card-actions { margin-top: 16px; display: flex; flex-wrap: wrap; gap: 12px; align-items: center; justify-content: center; }
        .routine-card-actions .button { flex: 1 1 45%; min-width: 0; text-align: center; }
        .routine-card-title-row { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
        .routine-actions-menu { position: relative; }
        .routine-actions-toggle { list-style: none; width: 42px; height: 42px; border-radius: 14px; border: 1px solid #e0e0e0; background: #f5f7fb; color: #546e7a; display: inline-flex; align-items: center; justify-content: center; cursor: pointer; }
        .routine-actions-toggle::-webkit-details-marker,
        .routine-actions-toggle::marker { display: none; }
        .routine-actions-dropdown { position: absolute; right: 0; top: 44px; background: #fff; border: 1px solid #e0e0e0; border-radius: 12px; padding: 6px; box-shadow: 0 8px 18px rgba(0,0,0,0.12); display: grid; gap: 4px; min-width: 180px; z-index: 50; }
        .routine-actions-menu:not([open]) .routine-actions-dropdown { display: none; }
        .routine-actions-dropdown button { background: transparent; border: none; text-align: left; padding: 8px 10px; border-radius: 8px; display: flex; gap: 8px; align-items: center; font-weight: 600; color: #37474f; cursor: pointer; width: 100%; }
        .routine-actions-dropdown button:hover { background: #f5f5f5; }
        .routine-actions-dropdown .danger { color: #d32f2f; }
        .routine-action-icons { display: inline-flex; gap: 8px; align-items: center; }
        .routine-card-footer { display: flex; justify-content: flex-end; margin-top: 12px; }
        .icon-button { width: 36px; height: 36px; border: none; background: transparent; color: #919191; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; cursor: pointer; }
        .icon-button.danger { color: #919191; }
        .collapsible-card { border: none; margin: 12px 0 0; padding: 0; }
        .collapsible-card summary { list-style: none; }
        .collapsible-card summary::-webkit-details-marker,
        .collapsible-card summary::marker { display: none; }
        .child-select-grid { /*display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 14px;*/ display: flex; gap: 15px;}
        .child-select-card { border: none; border-radius: 50%; padding: 0; background: transparent; display: grid; justify-items: center; gap: 8px; cursor: pointer; position: relative; }
        .child-select-card input[type="checkbox"], .child-select-card input[type="radio"] { position: absolute; opacity: 0; width: 0; height: 0; pointer-events: none; }
        .child-select-card img { width: 52px; height: 52px; border-radius: 50%; object-fit: cover; box-shadow: 0 2px 6px rgba(0,0,0,0.15); transition: box-shadow 150ms ease, transform 150ms ease; }
        .child-select-card strong { font-size: 13px; width: min-content; text-align: center; transition: color 150ms ease, text-shadow 150ms ease; }
        .child-select-card:has(input[type="checkbox"]:checked) img, .child-select-card:has(input[type="radio"]:checked) img { box-shadow: 0 0 0 4px rgba(100,181,246,0.8), 0 0 14px rgba(100,181,246,0.8); transform: translateY(-2px); }
        .child-select-card:has(input[type="checkbox"]:checked) strong, .child-select-card:has(input[type="radio"]:checked) strong { color: #0d47a1; text-shadow: 0 1px 8px rgba(100,181,246,0.8); }
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
        .routine-flow-container { width: 95vw; height: 95vh; background: linear-gradient(155deg, #7bc4ff, #a077ff); border-radius: 26px; padding: clamp(20px, 4vh, 32px); box-shadow: 0 18px 48px rgba(0,0,0,0.25); color: #fff; display: flex; flex-direction: column; position: relative; overflow: hidden; }
        .routine-flow-overlay.status-active .routine-flow-container { background: linear-gradient(155deg, #7bc4ff, #a077ff); }
        body.routine-flow-locked { overflow: hidden; overscroll-behavior: contain; touch-action: none; }
        .routine-flow-header { display: flex; flex-direction: column; align-items: flex-start; gap: clamp(10px, 2vh, 14px); margin-bottom: clamp(16px, 3vh, 24px); }
        .routine-flow-close { /*align-self: flex-start;*/ touch-action: none; }
        .routine-flow-heading { display: flex; flex-direction: column; gap: 10px; flex: 1; width: 100%;}
        .routine-flow-bar { display: flex; align-items: center; justify-content: space-between; gap: 16px; width: 100%;}
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
        .audio-toggle { background: rgba(255,255,255,0.9); color: #0d47a1; border: none; border-radius: 50%; width: 42px; height: 42px; display: inline-flex; align-items: center; justify-content: center; font-size: 1.2rem; box-shadow: 0 6px 16px rgba(0,0,0,0.15); cursor: pointer; transition: transform 120ms ease, box-shadow 200ms ease, background 160ms ease; }
        .audio-toggle:hover { transform: translateY(-1px); box-shadow: 0 10px 22px rgba(0,0,0,0.25); }
        .audio-toggle.muted { background: rgba(0,0,0,0.15); color: #fff; }
        .audio-toggle:disabled { opacity: 0.6; cursor: not-allowed; transform: none; box-shadow: 0 4px 10px rgba(0,0,0,0.12); }
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
            --min-ratio: 0;
            width: min(220px, 70vw);
            height: min(220px, 70vw);
            border-radius: 50%;
            margin: 0 auto;
            border-width: 0;
            background:
                conic-gradient(var(--track-fill) calc(var(--progress-ratio, 0) * 1turn), rgba(255,255,255,0.18) 0),
                conic-gradient(rgba(252,185,50,0.75) calc(var(--min-ratio, 0) * 1turn), transparent 0);
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
        .flow-progress-track[data-style="circle"] .flow-min-label,
        .flow-progress-track[data-style="pie"] .flow-min-label {
            position: absolute;
            left: 50%;
            bottom: 70px;
            transform: translateX(-50%);
            white-space: nowrap;
            opacity: 1;
            text-shadow: 0 3px 8px rgba(0,0,0,0.5);
        }
        .flow-progress-track[data-style="circle"] .flow-min-label.active,
        .flow-progress-track[data-style="pie"] .flow-min-label.active {
            opacity: 1;
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
        .routine-action-row { display: flex; justify-content: flex-start; gap: 12px; align-items: center; margin-top: 6px; }
        .routine-action-row .routine-primary-button { margin-left: auto; }
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
        .toggle-switch { position: relative; display: inline-block; width: 54px; height: 30px; flex-shrink: 0; }
        .toggle-switch input { position: absolute; inset: 0; opacity: 0; width: 100%; height: 100%; margin: 0; cursor: pointer; }
        .toggle-slider { position: absolute; inset: 0; border-radius: 999px; background: #b0bec5; transition: background 160ms ease; cursor: pointer; box-sizing: border-box; }
        .toggle-slider::before { content: ''; position: absolute; width: 24px; height: 24px; left: 3px; top: 3px; border-radius: 50%; background: #fff; transition: transform 160ms ease; box-shadow: 0 2px 6px rgba(0,0,0,0.2); }
        .toggle-switch input:checked + .toggle-slider { background: linear-gradient(135deg, #42a5f5, #1e88e5); }
        .toggle-switch input:checked + .toggle-slider::before { transform: translateX(24px); }
        .toggle-switch input:focus-visible + .toggle-slider { outline: 3px solid rgba(66,165,245,0.35); outline-offset: 2px; }
        .toggle-copy { display: flex; flex-direction: column; gap: 2px; }
        .toggle-title { font-weight: 700; color: #0d47a1; }
        .toggle-sub { font-size: 0.8rem; color: #546e7a; }
        body.countdown-disabled .flow-countdown { display: none !important; }
        .library-grid { display: flex; flex-direction: column; gap: 20px; }
        .library-card { width: 100%; background: #fff; border-radius: 10px 10px 0 0; box-shadow: 0 6px 18px rgba(15,70,140,0.12); padding: 20px 22px; display: flex; flex-direction: column; gap: 16px; }
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
        .button.primary { background: linear-gradient(135deg, #619fd0, #42a5f5); color: #fff; border: none; padding: 10px 20px; border-radius: 10px; font-weight: 700; cursor: pointer; transition: transform 140ms ease, box-shadow 140ms ease; }
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
        .page-header { padding: 18px 16px 12px; display: grid; gap: 12px; text-align: left; }
        .page-header-top { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; }
        .page-header-title { display: grid; gap: 6px; }
        .page-header-title h1 { margin: 0; font-size: 1.1rem; color: #2c2c2c; }
        .page-header-meta { margin: 0; color: #616161; display: flex; flex-wrap: wrap; gap: 8px; align-items: center; font-size: 0.6rem; }
        .page-header-actions { display: flex; gap: 10px; align-items: center; }
        .page-header-action { position: relative; display: inline-flex; align-items: center; justify-content: center; width: 40px; height: 40px; border-radius: 50%; border: 1px solid #dfe8df; background: #fff; color: #6d6d6d; box-shadow: 0 6px 14px rgba(0,0,0,0.08); cursor: pointer; }
        .page-header-action i { font-size: 1.1rem; }
        .page-header-action:hover { color: #4caf50; border-color: #c8e6c9; }
        .nav-links { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; justify-content: center; padding: 10px 12px; border-radius: 18px; background: #fff; border: 1px solid #eceff4; box-shadow: 0 8px 18px rgba(0,0,0,0.06); }
        .nav-link,
        .nav-mobile-link { flex: 1 1 90px; display: grid; justify-items: center; gap: 4px; text-decoration: none; color: #6d6d6d; font-weight: 600; font-size: 0.75rem; border-radius: 12px; padding: 6px 4px; }
        .nav-link i,
        .nav-mobile-link i { font-size: 1.2rem; }
        .nav-link.is-active,
        .nav-mobile-link.is-active { color: #4caf50; }
        .nav-link.is-active i,
        .nav-mobile-link.is-active i { color: #4caf50; }
        .nav-link:hover,
        .nav-mobile-link:hover { color: #4caf50; }
        .nav-link-button { background: transparent; border: none; cursor: pointer; }
        .nav-mobile-bottom { display: none; gap: 6px; padding: 10px 12px; border-top: 1px solid #e0e0e0; background: #fff; position: fixed; left: 0; right: 0; bottom: 0; z-index: 900; }
        .nav-mobile-bottom .nav-mobile-link { flex: 1; }
        @media (max-width: 768px) {
            .nav-links { display: none; }
            .nav-mobile-bottom { display: flex; justify-content: space-between; }
            body { padding-bottom: 72px; }
        }
        .routine-action-bar { display: flex; justify-content: flex-end; align-items: center; gap: 10px; margin: 10px 0 18px; }
        .routine-create-button { width: 52px; height: 52px; border-radius: 50%; border: none; background: #ff9800; color: #fff; display: inline-flex; align-items: center; justify-content: center; font-size: 1.4rem; cursor: pointer; box-shadow: 0 6px 14px rgba(255, 152, 0, 0.35); }
        .routine-create-button:hover { background: #fb8c00; }
        .routine-pref-button,
        .routine-library-button { border: none; background: transparent; color: #919191; font-size: 1.4rem; cursor: pointer; padding: 6px; }
        .routine-pref-button:hover,
        .routine-library-button:hover { color: #7a7a7a; }
        .routine-modal { position: fixed; inset: 0; background: rgba(0,0,0,0.45); display: none; align-items: center; justify-content: center; z-index: 4200; padding: 14px; }
        .routine-modal.open { display: flex; }
        body.modal-open { overflow: hidden; }
        .routine-modal-card { background: #fff; border-radius: 14px; max-width: 920px; width: min(920px, 100%); max-height: 90vh; overflow: hidden; box-shadow: 0 12px 32px rgba(0,0,0,0.25); display: grid; grid-template-rows: auto 1fr; }
        .routine-modal-card header { display: flex; align-items: center; justify-content: space-between; flex-direction: row;font-weight: 600; padding: 12px 16px; border-bottom: 1px solid #e0e0e0; }
        .routine-modal-card h2 { margin: 0; font-size: 1.1rem; }
        .routine-modal-header-actions { display: inline-flex; align-items: center; gap: 10px; }
        .routine-modal-close { background: transparent; border: none; font-size: 1.3rem; font-weight: 600; cursor: pointer; color: #555; }
        .routine-modal-body { padding: 12px 16px 18px; overflow-y: auto; }
        .routine-modal-actions { margin-top: 16px; display: flex; justify-content: flex-end; }
        .routine-modal.blocked { background: rgba(0,0,0,0.65); }
        .routine-modal.blocked .routine-modal-card { max-width: 520px; }
        .routine-modal.blocked .routine-modal-body { color: #455a64; font-size: 0.95rem; display: grid; gap: 8px; }
        .help-modal { position: fixed; inset: 0; background: rgba(0,0,0,0.45); display: none; align-items: center; justify-content: center; z-index: 4300; padding: 14px; }
        .help-modal.open { display: flex; }
        .help-card { background: #fff; border-radius: 12px; max-width: 720px; width: min(720px, 100%); max-height: 85vh; overflow: hidden; box-shadow: 0 12px 32px rgba(0,0,0,0.25); display: grid; grid-template-rows: auto 1fr; }
        .help-card header { display: flex; align-items: center; justify-content: space-between; padding: 12px 16px; border-bottom: 1px solid #e0e0e0; }
        .help-card h2 { margin: 0; font-size: 1.1rem; }
        .help-close { background: transparent; border: none; font-size: 1.3rem; cursor: pointer; color: #555; }
        .help-body { padding: 12px 16px 16px; overflow-y: auto; display: grid; gap: 12px; }
        .help-section h3 { margin: 0 0 6px; font-size: 1rem; color: #37474f; }
        .help-section ul { margin: 0; padding-left: 18px; display: grid; gap: 6px; color: #455a64; }
    </style>
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
</head>
<body<?php echo !empty($bodyClasses) ? ' class="' . implode(' ', $bodyClasses) . '"' : ''; ?>>
    <?php
        $dashboardPage = $isParentContext ? 'dashboard_parent.php' : 'dashboard_child.php';
        $dashboardActive = $currentPage === $dashboardPage;
        $routinesActive = $currentPage === 'routine.php';
        $tasksActive = $currentPage === 'task.php';
        $goalsActive = $currentPage === 'goal.php';
        $rewardsActive = $currentPage === 'rewards.php';
        $profileActive = $currentPage === 'profile.php';
    ?>
    <header class="page-header">
        <div class="page-header-top">
            <div class="page-header-title">
                <h1>Routine Management</h1>
                <p class="page-header-meta">
                    Welcome back, <?php echo htmlspecialchars($_SESSION['name'] ?? $_SESSION['username'] ?? 'User'); ?>
                    <?php if ($welcome_role_label): ?>
                        <span class="role-badge"><?php echo htmlspecialchars($welcome_role_label); ?></span>
                    <?php endif; ?>
                </p>
            </div>
            <div class="page-header-actions">
                <?php if (!empty($isParentNotificationUser)): ?>
                    <button type="button" class="page-header-action parent-notification-trigger" data-parent-notify-trigger aria-label="Notifications">
                        <i class="fa-solid fa-bell"></i>
                        <?php if ($parentNotificationCount > 0): ?>
                            <span class="parent-notification-badge"><?php echo (int) $parentNotificationCount; ?></span>
                        <?php endif; ?>
                    </button>
                    <a class="page-header-action" href="dashboard_parent.php#manage-family" aria-label="Family settings">
                        <i class="fa-solid fa-gear"></i>
                    </a>
                <?php elseif (!empty($isChildNotificationUser)): ?>
                    <button type="button" class="page-header-action notification-trigger" data-child-notify-trigger aria-label="Notifications">
                        <i class="fa-solid fa-bell"></i>
                        <?php if ($notificationCount > 0): ?>
                            <span class="notification-badge"><?php echo (int) $notificationCount; ?></span>
                        <?php endif; ?>
                    </button>
                <?php endif; ?>
                <a class="page-header-action" href="logout.php" aria-label="Logout">
                    <i class="fa-solid fa-right-from-bracket"></i>
                </a>
            </div>
        </div>
        <nav class="nav-links" aria-label="Primary">
            <a class="nav-link<?php echo $dashboardActive ? ' is-active' : ''; ?>" href="<?php echo htmlspecialchars($dashboardPage); ?>"<?php echo $dashboardActive ? ' aria-current="page"' : ''; ?>>
                <i class="fa-solid fa-house"></i>
                <span>Dashboard</span>
            </a>
            <a class="nav-link<?php echo $routinesActive ? ' is-active' : ''; ?>" href="routine.php"<?php echo $routinesActive ? ' aria-current="page"' : ''; ?>>
                <i class="fa-solid fa-repeat week-item-icon"></i>
                <span>Routines</span>
            </a>
            <a class="nav-link<?php echo $tasksActive ? ' is-active' : ''; ?>" href="task.php"<?php echo $tasksActive ? ' aria-current="page"' : ''; ?>>
                <i class="fa-solid fa-list-check"></i>
                <span>Tasks</span>
            </a>
            <a class="nav-link<?php echo $goalsActive ? ' is-active' : ''; ?>" href="goal.php"<?php echo $goalsActive ? ' aria-current="page"' : ''; ?>>
                <i class="fa-solid fa-bullseye"></i>
                <span>Goals</span>
            </a>
            <a class="nav-link<?php echo $rewardsActive ? ' is-active' : ''; ?>" href="rewards.php"<?php echo $rewardsActive ? ' aria-current="page"' : ''; ?>>
                <i class="fa-solid fa-gift"></i>
                <span>Rewards</span>
            </a>
            <a class="nav-link<?php echo $profileActive ? ' is-active' : ''; ?>" href="profile.php?self=1"<?php echo $profileActive ? ' aria-current="page"' : ''; ?>>
                <i class="fa-solid fa-user"></i>
                <span>Profile</span>
            </a>
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
            <div class="routine-action-bar">
                <button type="button" class="routine-library-button" data-routine-library-open aria-label="Open routine task library">
                    <i class="fa-solid fa-rectangle-list"></i>
                </button>
                <button type="button" class="routine-pref-button" data-routine-pref-open aria-label="Routine timer preferences">
                    <i class="fa-solid fa-sliders"></i>
                </button>
                <button type="button" class="routine-create-button" data-routine-create-open aria-label="Create routine">
                    <i class="fa-solid fa-plus"></i>
                </button>
            </div>

            <div class="routine-modal" data-routine-pref-modal>
                <div class="routine-modal-card" role="dialog" aria-modal="true" aria-labelledby="routine-pref-title">
                    <header>
                        <h2 id="routine-pref-title">Routine Timer Preferences</h2>
                        <button type="button" class="routine-modal-close" data-routine-pref-close aria-label="Close preferences">&times;</button>
                    </header>
                    <div class="routine-modal-body">
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
                            <div class="toggle-control">
                                <label class="toggle-switch">
                                    <input type="checkbox" name="sound_effects_enabled" value="1" <?php echo !empty($routinePreferences['sound_effects_enabled']) ? 'checked' : ''; ?>>
                                    <span class="toggle-slider"></span>
                                </label>
                                <div class="toggle-copy">
                                    <span class="toggle-title">Sound Effects</span>
                                    <span class="toggle-sub">Turn off to disable chimes and task reward sounds during routines.</span>
                                </div>
                            </div>
                            <div class="toggle-control">
                                <label class="toggle-switch">
                                    <input type="checkbox" name="background_music_enabled" value="1" <?php echo !empty($routinePreferences['background_music_enabled']) ? 'checked' : ''; ?>>
                                    <span class="toggle-slider"></span>
                                </label>
                                <div class="toggle-copy">
                                    <span class="toggle-title">Background Music</span>
                                    <span class="toggle-sub">Turn off to disable routine background music.</span>
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
                    </div>
                </div>
            </div>

            <div class="routine-modal<?php echo $createFormHasErrors ? ' open' : ''; ?>" data-routine-create-modal>
                <div class="routine-modal-card" role="dialog" aria-modal="true" aria-labelledby="routine-create-title">
                    <header>
                        <h2 id="routine-create-title">Create Routine</h2>
                        <button type="button" class="routine-modal-close" data-routine-create-close aria-label="Close create routine">&times;</button>
                    </header>
                    <div class="routine-modal-body">
                        <?php if (empty($children)): ?>
                            <p class="no-data">Add children to your family profile before creating routines.</p>
                        <?php else: ?>
                            <form method="POST" autocomplete="off">
                                <div class="form-grid">
                                    <div class="form-group child-select-group">
                                        <label>Assign to Child(ren)</label>
                                        <div class="child-select-grid">
                                            <?php foreach ($children as $child): 
                                                $cid = (int) $child['child_user_id'];
                                                $checked = in_array($cid, $selectedCreateChildIds, true) ? 'checked' : '';
                                                $avatar = !empty($child['child_avatar']) ? $child['child_avatar'] : 'images/default-avatar.png';
                                            ?>
                                                <?php
                                                    $childName = trim((string) ($child['child_name'] ?? ''));
                                                    $childParts = $childName === '' ? [] : preg_split('/\s+/', $childName);
                                                    $childFirst = $childParts[0] ?? $childName;
                                                ?>
                                                <label class="child-select-card">
                                                    <input type="checkbox" name="child_user_ids[]" value="<?php echo $cid; ?>" <?php echo $checked; ?>>
                                                    <img src="<?php echo htmlspecialchars($avatar); ?>" alt="<?php echo htmlspecialchars($child['child_name']); ?>">
                                                    <strong><?php echo htmlspecialchars($childFirst); ?></strong>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <input type="hidden" name="duplicate_child_id" data-role="duplicate-child-id" value="">
                                    <div class="form-group">
                                        <label for="title">Routine Title</label>
                                        <input type="text" id="title" name="title" required <?php echo $createFormHasErrors ? 'value="' . htmlspecialchars($_POST['title'] ?? '', ENT_QUOTES) . '"' : ''; ?>>
                                    </div>
                                    <?php $createTimeOfDay = $createFormHasErrors ? ($_POST['time_of_day'] ?? 'anytime') : 'anytime'; ?>
                                    <div class="form-group">
                                        <label for="time_of_day">Time of Day</label>
                                        <select id="time_of_day" name="time_of_day">
                                            <option value="anytime" <?php echo $createTimeOfDay === 'anytime' ? 'selected' : ''; ?>>Anytime</option>
                                            <option value="morning" <?php echo $createTimeOfDay === 'morning' ? 'selected' : ''; ?>>Morning</option>
                                            <option value="afternoon" <?php echo $createTimeOfDay === 'afternoon' ? 'selected' : ''; ?>>Afternoon</option>
                                            <option value="evening" <?php echo $createTimeOfDay === 'evening' ? 'selected' : ''; ?>>Evening</option>
                                        </select>
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
                                        <label for="recurrence">Repeat</label>
                                        <select id="recurrence" name="recurrence">
                                            <option value="" <?php echo empty($_POST['recurrence'] ?? '') ? 'selected' : ''; ?>>Once</option>
                                            <option value="daily" <?php echo (($_POST['recurrence'] ?? '') === 'daily') ? 'selected' : ''; ?>>Every Day</option>
                                            <option value="weekly" <?php echo (($_POST['recurrence'] ?? '') === 'weekly') ? 'selected' : ''; ?>>Specific Days</option>
                                        </select>
                                        <?php
                                            $createRepeatDays = $createFormHasErrors ? ($_POST['recurrence_days'] ?? []) : [];
                                            $createRepeatDays = array_values(array_filter(array_map('trim', (array) $createRepeatDays)));
                                        ?>
                                        <div class="repeat-days" data-create-recurrence-days>
                                            <div class="repeat-days-label">Specific Days</div>
                                            <div class="repeat-days-grid">
                                                <label class="repeat-day"><input type="checkbox" name="recurrence_days[]" value="Sun" <?php echo in_array('Sun', $createRepeatDays, true) ? 'checked' : ''; ?>><span>Sun</span></label>
                                                <label class="repeat-day"><input type="checkbox" name="recurrence_days[]" value="Mon" <?php echo in_array('Mon', $createRepeatDays, true) ? 'checked' : ''; ?>><span>Mon</span></label>
                                                <label class="repeat-day"><input type="checkbox" name="recurrence_days[]" value="Tue" <?php echo in_array('Tue', $createRepeatDays, true) ? 'checked' : ''; ?>><span>Tue</span></label>
                                                <label class="repeat-day"><input type="checkbox" name="recurrence_days[]" value="Wed" <?php echo in_array('Wed', $createRepeatDays, true) ? 'checked' : ''; ?>><span>Wed</span></label>
                                                <label class="repeat-day"><input type="checkbox" name="recurrence_days[]" value="Thu" <?php echo in_array('Thu', $createRepeatDays, true) ? 'checked' : ''; ?>><span>Thu</span></label>
                                                <label class="repeat-day"><input type="checkbox" name="recurrence_days[]" value="Fri" <?php echo in_array('Fri', $createRepeatDays, true) ? 'checked' : ''; ?>><span>Fri</span></label>
                                                <label class="repeat-day"><input type="checkbox" name="recurrence_days[]" value="Sat" <?php echo in_array('Sat', $createRepeatDays, true) ? 'checked' : ''; ?>><span>Sat</span></label>
                                            </div>
                                        </div>
                                    </div>
                                    <?php
                                        $createRoutineDate = $createFormHasErrors ? ($_POST['routine_date'] ?? '') : date('Y-m-d');
                                    ?>
                                    <div class="form-group" data-create-routine-date>
                                        <label for="routine_date">Date</label>
                                        <input type="date" id="routine_date" name="routine_date" value="<?php echo htmlspecialchars($createRoutineDate, ENT_QUOTES); ?>">
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
                    </div>
                </div>
            </div>

            <div class="routine-modal" data-routine-library-modal>
                <div class="routine-modal-card" role="dialog" aria-modal="true" aria-labelledby="routine-library-title">
                    <header>
                        <h2 id="routine-library-title">Routine Task Library</h2>
                        <div class="routine-modal-header-actions">
                            <button type="button" class="button primary" data-action="open-task-modal">Add Routine Task</button>
                            <button type="button" class="routine-modal-close" data-routine-library-close aria-label="Close routine task library">&times;</button>
                        </div>
                    </header>
                    <div class="routine-modal-body">
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
                                        <summary class="library-toggle">View Saved Library Tasks</summary>
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
                                                        <button type="button"
                                                                class="icon-button"
                                                                data-routine-task-edit-open
                                                                data-task-id="<?php echo (int) $task['id']; ?>"
                                                                data-task-title="<?php echo htmlspecialchars($task['title']); ?>"
                                                                data-task-description="<?php echo htmlspecialchars($taskDescription); ?>"
                                                                data-task-time-limit="<?php echo (int) $task['time_limit']; ?>"
                                                                data-task-minutes="<?php echo htmlspecialchars($taskMinMinutesValue); ?>"
                                                                data-task-min-enabled="<?php echo $taskMinEnabled ? '1' : '0'; ?>"
                                                                data-task-point-value="<?php echo (int) $task['point_value']; ?>"
                                                                data-task-category="<?php echo htmlspecialchars($task['category']); ?>"
                                                                aria-label="Edit routine task">
                                                            <i class="fa-solid fa-pen"></i>
                                                        </button>
                                                        <button type="button"
                                                                class="icon-button"
                                                                data-routine-task-duplicate-open
                                                                data-task-title="<?php echo htmlspecialchars($task['title']); ?>"
                                                                data-task-description="<?php echo htmlspecialchars($taskDescription); ?>"
                                                                data-task-time-limit="<?php echo (int) $task['time_limit']; ?>"
                                                                data-task-minutes="<?php echo htmlspecialchars($taskMinMinutesValue); ?>"
                                                                data-task-min-enabled="<?php echo $taskMinEnabled ? '1' : '0'; ?>"
                                                                data-task-point-value="<?php echo (int) $task['point_value']; ?>"
                                                                data-task-category="<?php echo htmlspecialchars($task['category']); ?>"
                                                                aria-label="Duplicate routine task">
                                                            <i class="fa-solid fa-clone"></i>
                                                        </button>
                                                        <form method="POST" onsubmit="return confirm('Delete this task?');">
                                                            <input type="hidden" name="routine_task_id" value="<?php echo (int) $task['id']; ?>">
                                                            <button type="submit" name="delete_routine_task" class="icon-button danger" aria-label="Delete routine task">
                                                                <i class="fa-solid fa-trash"></i>
                                                            </button>
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
                                <button type="button" class="task-modal-close" data-action="close-task-modal" aria-label="Close add routine task dialog"><i class="fa-solid fa-xmark"></i></button>
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
                        <div class="task-modal-overlay" data-role="task-edit-modal" aria-hidden="true">
                            <div class="task-modal" role="dialog" aria-modal="true" aria-labelledby="task-edit-title">
                                <button type="button" class="task-modal-close" data-action="close-task-edit-modal" aria-label="Close edit routine task dialog"><i class="fa-solid fa-xmark"></i></button>
                                <h3 id="task-edit-title">Edit Routine Task</h3>
                                <form method="POST" class="library-form" autocomplete="off">
                                    <input type="hidden" name="routine_task_id" value="">
                                    <div class="input-group">
                                        <label for="edit_rt_title">Title</label>
                                        <input type="text" id="edit_rt_title" name="edit_rt_title" required>
                                    </div>
                                    <div class="input-group">
                                        <label for="edit_rt_description">Description</label>
                                        <textarea id="edit_rt_description" name="edit_rt_description" rows="3"></textarea>
                                    </div>
                                    <div class="dual-inputs">
                                        <div class="input-group">
                                            <label for="edit_rt_time_limit">Time Limit (min)</label>
                                            <input type="number" id="edit_rt_time_limit" name="edit_rt_time_limit" min="1" required>
                                        </div>
                                        <div class="input-group">
                                            <label for="edit_rt_point_value">Point Value</label>
                                            <input type="number" id="edit_rt_point_value" name="edit_rt_point_value" min="0" value="0">
                                        </div>
                                    </div>
                                    <div class="input-group">
                                        <label for="edit_rt_min_minutes">Minimum Time (min)</label>
                                        <input type="number" id="edit_rt_min_minutes" name="edit_rt_min_minutes" min="0" step="0.1">
                                        <small>Children must stay on this task at least this long before moving on.</small>
                                    </div>
                                    <div class="input-group">
                                        <label class="toggle-inline">
                                            <input type="checkbox" id="edit_rt_min_enabled" name="edit_rt_min_enabled" value="1">
                                            Require minimum time before completion
                                        </label>
                                    </div>
                                    <div class="input-group">
                                        <label for="edit_rt_category">Category</label>
                                        <select id="edit_rt_category" name="edit_rt_category">
                                            <option value="hygiene">Hygiene</option>
                                            <option value="homework">Homework</option>
                                            <option value="household">Household</option>
                                        </select>
                                    </div>
                                    <div class="form-actions">
                                        <button type="submit" name="update_routine_task" class="button">Save Changes</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        <?php endif; ?>

        <section class="routine-section" data-routine-section>
         <div class="routine-section-header">
             <h2><?php echo ($isParentContext ? 'Family Routines' : 'My Routines'); ?></h2>
          </div>
          <?php if ($isParentContext): ?>
              <div class="routine-view-row">
                  <div class="routine-view-toggle" role="group" aria-label="Routine view">
                      <button type="button" class="routine-view-button active" data-routine-view="card" aria-pressed="true" title="Card view">
                          <i class="fa-solid fa-table-cells"></i>
                      </button>
                      <button type="button" class="routine-view-button" data-routine-view="list" aria-pressed="false" title="List view">
                          <i class="fa-solid fa-list"></i>
                      </button>
                  </div>
              </div>
          <?php endif; ?>
          <?php if ($isParentContext && !empty($children)): ?>
              <div class="routine-filters" data-routine-filters>
                  <div class="routine-filter-header">
                      <div class="routine-filter-title">Filter by child</div>
                      <label class="routine-select-all">
                          <input type="checkbox" data-routine-select-all checked>
                          Select all
                      </label>
                  </div>
                  <div class="routine-child-grid">
                      <?php foreach ($children as $child): ?>
                          <?php
                              $cid = (int) ($child['child_user_id'] ?? 0);
                              $avatar = !empty($child['child_avatar']) ? $child['child_avatar'] : 'images/default-avatar.png';
                              $childName = trim((string) ($child['child_name'] ?? ''));
                              $childParts = $childName === '' ? [] : preg_split('/\s+/', $childName);
                              $childFirst = $childParts[0] ?? $childName;
                          ?>
                          <label class="routine-child-card">
                              <input type="checkbox" data-routine-child value="<?php echo $cid; ?>" checked>
                              <img src="<?php echo htmlspecialchars($avatar); ?>" alt="<?php echo htmlspecialchars($childName); ?>">
                              <span><?php echo htmlspecialchars($childFirst); ?></span>
                          </label>
                      <?php endforeach; ?>
                  </div>
              </div>
          <?php endif; ?>
            
            <?php if (empty($routines)): ?>
                <p class="no-data">No routines available.</p>
            <?php else: ?>
                <div class="routine-card-grid" data-routine-grid>
                  <?php foreach ($routines as $routine): ?>
                      <?php
                          $isChildView = (getEffectiveRole($_SESSION['user_id']) === 'child');
                          $cardClasses = 'routine-card' . ($isChildView ? ' child-view' : '');
                          $timeOfDay = $routine['time_of_day'] ?? 'anytime';
                          $startTimeValue = !empty($routine['start_time']) ? date('H:i', strtotime($routine['start_time'])) : '99:99';
                          $routineChildId = (int) ($routine['child_user_id'] ?? 0);
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
                        $duplicatePayload = [
                            'id' => (int) $routine['id'],
                            'child_user_id' => (int) ($routine['child_user_id'] ?? 0),
                            'title' => $routine['title'] ?? '',
                            'time_of_day' => $routine['time_of_day'] ?? 'anytime',
                            'start_time' => !empty($routine['start_time']) ? substr($routine['start_time'], 0, 5) : '',
                            'end_time' => !empty($routine['end_time']) ? substr($routine['end_time'], 0, 5) : '',
                            'bonus_points' => (int) ($routine['bonus_points'] ?? 0),
                            'recurrence' => $routine['recurrence'] ?? '',
                            'recurrence_days' => array_values(array_filter(array_map('trim', explode(',', (string) ($routine['recurrence_days'] ?? ''))))),
                            'routine_date' => $routine['routine_date'] ?? '',
                            'tasks' => array_map(static function ($task) {
                                return ['id' => (int) ($task['id'] ?? 0)];
                            }, $routine['tasks'] ?? [])
                        ];
                        $duplicatePayloadJson = htmlspecialchars(json_encode($duplicatePayload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP), ENT_QUOTES, 'UTF-8');
                    ?>
                      <article class="<?php echo $cardClasses; ?>"
                          data-routine-id="<?php echo (int) $routine['id']; ?>"
                          data-time-of-day="<?php echo htmlspecialchars($timeOfDay); ?>"
                          data-start-time="<?php echo htmlspecialchars($startTimeValue); ?>"
                          data-child-id="<?php echo $routineChildId; ?>"
                          data-timer-warnings="<?php echo $timerWarningAttr; ?>"
                          data-show-countdown="<?php echo $countdownAttr; ?>">
                        <header>
                            <div class="routine-card-title-row">
                                <h3><?php echo htmlspecialchars($routine['title']); ?></h3>
                                <?php if ($isParentContext): ?>
                                    <details class="routine-actions-menu">
                                        <summary class="routine-actions-toggle" aria-label="Routine actions">
                                            <i class="fa-solid fa-ellipsis-vertical"></i>
                                        </summary>
                                        <div class="routine-actions-dropdown">
                                            <button type="button" data-routine-edit-open data-routine-id="<?php echo (int) $routine['id']; ?>">
                                                <i class="fa-solid fa-pen"></i>
                                                Edit Routine
                                            </button>
                                            <button type="button" data-routine-duplicate-open data-routine-payload="<?php echo $duplicatePayloadJson; ?>">
                                                <i class="fa-solid fa-clone"></i>
                                                Duplicate Routine
                                            </button>
                                            <form method="POST" action="routine.php">
                                                <input type="hidden" name="routine_id" value="<?php echo (int) $routine['id']; ?>">
                                                <button type="submit" name="delete_routine" class="danger" onclick="return confirm('Delete this routine?');">
                                                    <i class="fa-solid fa-trash"></i>
                                                    Delete Routine
                                                </button>
                                            </form>
                                        </div>
                                    </details>
                                <?php endif; ?>
                            </div>
                            <div class="routine-details">
                                <?php if (!empty($routine['child_display_name']) && !$isChildView): ?>
                                    <span class="routine-assignee">
                                        <img src="<?php echo htmlspecialchars($routine['child_avatar'] ?: 'images/default-avatar.png'); ?>" alt="<?php echo htmlspecialchars($routine['child_display_name']); ?>">
                                        <?php echo htmlspecialchars($routine['child_display_name']); ?>
                                      </span>
                                  <?php endif; ?>
                                  <span><i class="fa-solid fa-clock routine-meta-icon"></i><?php echo date('g:i A', strtotime($routine['start_time'])) . ' - ' . date('g:i A', strtotime($routine['end_time'])); ?></span>
                                  <span class="routine-points-row">
                                      <i class="fa-solid fa-list-check routine-meta-icon"></i>
                                      <span class="points-badge"><?php echo (int) $totalRoutinePoints; ?></span>
                                      <?php if ((int) ($routine['bonus_points'] ?? 0) > 0): ?>
                                          <span class="points-badge bonus">Bonus <?php echo (int) $routine['bonus_points']; ?></span>
                                      <?php endif; ?>
                                  </span>
                                  <span><i class="fa-regular fa-calendar-days routine-meta-icon"></i><?php echo htmlspecialchars($routine['recurrence'] ?: 'None'); ?></span>
                                  <?php if (!empty($routine['creator_display_name'])): ?>
                                      <span><i class="fa-solid fa-user-pen routine-meta-icon"></i><?php echo htmlspecialchars($routine['creator_display_name']); ?></span>
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
                                                    <input type="checkbox"
                                                        name="parent_completed[]"
                                                        value="<?php echo (int) $task['id']; ?>"
                                                        form="parent-complete-form-<?php echo (int) $routine['id']; ?>"
                                                        data-parent-complete-task
                                                        data-task-id="<?php echo (int) $task['id']; ?>"
                                                        data-completed-at="<?php echo !empty($task['completed_at']) ? htmlspecialchars($task['completed_at']) : ''; ?>"
                                                        <?php echo $isCompleted ? 'checked' : ''; ?>>
                                                    <span class="sr-only">Mark <?php echo htmlspecialchars($task['title']); ?> completed</span>
                                                </label>
                                            <?php endif; ?>
                                            <strong><?php echo htmlspecialchars($task['title']); ?></strong>
                                            <div class="task-meta">
                                                <span><?php echo (int) $task['time_limit']; ?> min</span>
                                                <span class="points-badge"><?php echo (int) ($task['point_value'] ?? $task['points'] ?? 0); ?></span>
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
                        <?php if ($isParentContext): ?>
                            <form method="POST" action="routine.php" class="parent-complete-form" id="parent-complete-form-<?php echo (int) $routine['id']; ?>">
                                <input type="hidden" name="routine_id" value="<?php echo (int) $routine['id']; ?>">
                                <input type="hidden" name="parent_completed_at" value="{}" data-role="parent-completed-at">
                                <p class="parent-complete-note">Check the tasks completed to award points. Bonus points apply only when all tasks are checked.</p>
                            </form>
                        <?php endif; ?>
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
                                                    <button type="button" class="audio-toggle" data-role="audio-toggle" aria-pressed="true" aria-label="Mute all routine sounds" title="Mute all routine sounds">
                                                    <span aria-hidden="true" data-audio-icon><i class="fa-solid fa-volume-high"></i></span>
                                                    </button>
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
                                                    <button type="button" class="audio-toggle" data-role="audio-toggle" aria-pressed="true" aria-label="Mute all routine sounds" title="Mute all routine sounds">
                                                    <span aria-hidden="true" data-audio-icon><i class="fa-solid fa-volume-high"></i></span>
                                                    </button>
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
                                    $timeOfDayValue = $override['time_of_day'] ?? ($routine['time_of_day'] ?? 'anytime');
                                    $recurrenceDaysRaw = $override['recurrence_days'] ?? ($routine['recurrence_days'] ?? '');
                                    $recurrenceDays = array_values(array_filter(array_map('trim', explode(',', (string) $recurrenceDaysRaw))));
                                    $routineDateValue = $override['routine_date'] ?? ($routine['routine_date'] ?? '');
                                    $childOverride = $override['child_user_ids'] ?? null;
                                    $selectedChildIds = [];
                                    if (is_array($childOverride)) {
                                        $selectedChildIds = array_values(array_filter(array_map('intval', $childOverride)));
                                    }
                                    if (empty($selectedChildIds)) {
                                        $selectedChildIds = [(int) ($routine['child_user_id'] ?? 0)];
                                    }
                                    if ($routineDateValue === '' && !empty($routine['created_at'])) {
                                        $routineDateValue = date('Y-m-d', strtotime($routine['created_at']));
                                    }
                                ?>
                                <div class="routine-modal routine-edit-modal" data-routine-edit-modal="<?php echo (int) $routine['id']; ?>">
                                    <div class="routine-modal-card" role="dialog" aria-modal="true" aria-labelledby="routine-edit-title-<?php echo (int) $routine['id']; ?>">
                                        <header>
                                            <h2 id="routine-edit-title-<?php echo (int) $routine['id']; ?>">Edit Routine</h2>
                                            <button type="button" class="routine-modal-close" data-routine-edit-close aria-label="Close edit routine">&times;</button>
                                        </header>
                                        <div class="routine-modal-body">
                                            <form method="POST" autocomplete="off">
                                                <input type="hidden" name="routine_id" value="<?php echo (int) $routine['id']; ?>">
                                                <div class="form-grid">
                                                    <?php if (!empty($children)): ?>
                                                        <div class="form-group child-select-group">
                                                            <label>Assign to Child(ren)</label>
                                                            <div class="child-select-grid">
                                                                <?php foreach ($children as $child): ?>
                                                                    <?php
                                                                        $cid = (int) ($child['child_user_id'] ?? 0);
                                                                        $checked = in_array($cid, $selectedChildIds, true) ? 'checked' : '';
                                                                        $avatar = !empty($child['child_avatar']) ? $child['child_avatar'] : 'images/default-avatar.png';
                                                                        $childName = trim((string) ($child['child_name'] ?? ''));
                                                                        $childParts = $childName === '' ? [] : preg_split('/\s+/', $childName);
                                                                        $childFirst = $childParts[0] ?? $childName;
                                                                    ?>
                                                                    <label class="child-select-card">
                                                                        <input type="checkbox" name="child_user_ids[]" value="<?php echo $cid; ?>" <?php echo $checked; ?>>
                                                                        <img src="<?php echo htmlspecialchars($avatar); ?>" alt="<?php echo htmlspecialchars($child['child_name'] ?? ''); ?>">
                                                                        <strong><?php echo htmlspecialchars($childFirst); ?></strong>
                                                                    </label>
                                                                <?php endforeach; ?>
                                                            </div>
                                                        </div>
                                                    <?php else: ?>
                                                        <p class="no-data">Add children to your family profile before editing routines.</p>
                                                    <?php endif; ?>
                                                    <div class="form-group">
                                                        <label>Title</label>
                                                        <input type="text" name="title" value="<?php echo $titleValue; ?>" required>
                                                    </div>
                                                    <div class="form-group">
                                                        <label>Time of Day</label>
                                                        <select name="time_of_day">
                                                            <option value="anytime" <?php echo $timeOfDayValue === 'anytime' ? 'selected' : ''; ?>>Anytime</option>
                                                            <option value="morning" <?php echo $timeOfDayValue === 'morning' ? 'selected' : ''; ?>>Morning</option>
                                                            <option value="afternoon" <?php echo $timeOfDayValue === 'afternoon' ? 'selected' : ''; ?>>Afternoon</option>
                                                            <option value="evening" <?php echo $timeOfDayValue === 'evening' ? 'selected' : ''; ?>>Evening</option>
                                                        </select>
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
                                                        <label>Repeat</label>
                                                        <select name="recurrence">
                                                            <option value="" <?php echo empty($recurrenceValue) ? 'selected' : ''; ?>>Once</option>
                                                            <option value="daily" <?php echo ($recurrenceValue === 'daily') ? 'selected' : ''; ?>>Every Day</option>
                                                            <option value="weekly" <?php echo ($recurrenceValue === 'weekly') ? 'selected' : ''; ?>>Specific Days</option>
                                                        </select>
                                                        <div class="repeat-days" data-recurrence-days-wrapper>
                                                            <div class="repeat-days-label">Specific Days</div>
                                                            <div class="repeat-days-grid">
                                                                <label class="repeat-day"><input type="checkbox" name="recurrence_days[]" value="Sun" <?php echo in_array('Sun', $recurrenceDays, true) ? 'checked' : ''; ?>><span>Sun</span></label>
                                                                <label class="repeat-day"><input type="checkbox" name="recurrence_days[]" value="Mon" <?php echo in_array('Mon', $recurrenceDays, true) ? 'checked' : ''; ?>><span>Mon</span></label>
                                                                <label class="repeat-day"><input type="checkbox" name="recurrence_days[]" value="Tue" <?php echo in_array('Tue', $recurrenceDays, true) ? 'checked' : ''; ?>><span>Tue</span></label>
                                                                <label class="repeat-day"><input type="checkbox" name="recurrence_days[]" value="Wed" <?php echo in_array('Wed', $recurrenceDays, true) ? 'checked' : ''; ?>><span>Wed</span></label>
                                                                <label class="repeat-day"><input type="checkbox" name="recurrence_days[]" value="Thu" <?php echo in_array('Thu', $recurrenceDays, true) ? 'checked' : ''; ?>><span>Thu</span></label>
                                                                <label class="repeat-day"><input type="checkbox" name="recurrence_days[]" value="Fri" <?php echo in_array('Fri', $recurrenceDays, true) ? 'checked' : ''; ?>><span>Fri</span></label>
                                                                <label class="repeat-day"><input type="checkbox" name="recurrence_days[]" value="Sat" <?php echo in_array('Sat', $recurrenceDays, true) ? 'checked' : ''; ?>><span>Sat</span></label>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="form-group" data-routine-date-wrapper>
                                                        <label>Date</label>
                                                        <input type="date" name="routine_date" value="<?php echo htmlspecialchars($routineDateValue, ENT_QUOTES); ?>">
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
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
               </div>
            <?php endif; ?>
        </section>
    </main>
    <div class="routine-modal blocked" data-routine-blocked-modal>
        <div class="routine-modal-card" role="dialog" aria-modal="true" aria-labelledby="routine-blocked-title">
            <header>
                <h2 id="routine-blocked-title">Routine Notice</h2>
                <button type="button" class="routine-modal-close" data-routine-blocked-close aria-label="Close notice">&times;</button>
            </header>
            <div class="routine-modal-body">
                <p data-routine-blocked-message></p>
                <div class="routine-modal-actions">
                    <button type="button" class="button" data-routine-blocked-close>OK</button>
                </div>
            </div>
        </div>
    </div>
    <div class="help-modal" data-help-modal>
        <div class="help-card" role="dialog" aria-modal="true" aria-labelledby="help-title">
            <header>
                <h2 id="help-title">Routine Help</h2>
                <button type="button" class="help-close" data-help-close aria-label="Close help">&times;</button>
            </header>
            <div class="help-body">
                <?php if ($isParentContext): ?>
                    <section class="help-section">
                        <h3>Parent view</h3>
                        <ul>
                            <li>Create routines with tasks, bonuses, and schedule rules.</li>
                            <li>Use the routine builder to set order and time limits.</li>
                            <li>Track completion in dashboards and approve as needed.</li>
                        </ul>
                    </section>
                <?php else: ?>
                    <section class="help-section">
                        <h3>Child view</h3>
                        <ul>
                            <li>Start a routine and follow tasks in order.</li>
                            <li>Timers and progress bars help track each task.</li>
                            <li>Completed routines show up as done in your schedule.</li>
                        </ul>
                    </section>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <nav class="nav-mobile-bottom" aria-label="Primary">
        <a class="nav-mobile-link<?php echo $dashboardActive ? ' is-active' : ''; ?>" href="<?php echo htmlspecialchars($dashboardPage); ?>"<?php echo $dashboardActive ? ' aria-current="page"' : ''; ?>>
            <i class="fa-solid fa-house"></i>
            <span>Dashboard</span>
        </a>
        <a class="nav-mobile-link<?php echo $routinesActive ? ' is-active' : ''; ?>" href="routine.php"<?php echo $routinesActive ? ' aria-current="page"' : ''; ?>>
            <i class="fa-solid fa-repeat week-item-icon"></i>
            <span>Routines</span>
        </a>
        <a class="nav-mobile-link<?php echo $tasksActive ? ' is-active' : ''; ?>" href="task.php"<?php echo $tasksActive ? ' aria-current="page"' : ''; ?>>
            <i class="fa-solid fa-list-check"></i>
            <span>Tasks</span>
        </a>
        <a class="nav-mobile-link<?php echo $goalsActive ? ' is-active' : ''; ?>" href="goal.php"<?php echo $goalsActive ? ' aria-current="page"' : ''; ?>>
            <i class="fa-solid fa-bullseye"></i>
            <span>Goals</span>
        </a>
        <a class="nav-mobile-link<?php echo $rewardsActive ? ' is-active' : ''; ?>" href="rewards.php"<?php echo $rewardsActive ? ' aria-current="page"' : ''; ?>>
            <i class="fa-solid fa-gift"></i>
            <span>Rewards</span>
        </a>
    </nav>
    <footer>
        <p>Child Task and Chore App - Ver 3.26.0</p>
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
                preferences: { timer_warnings_enabled: 1, show_countdown: 1, progress_style: 'bar', sound_effects_enabled: 1, background_music_enabled: 1 }
            };
            const hasCountdownPreference = page.preferences && typeof page.preferences.show_countdown !== 'undefined';
            const countdownEnabled = hasCountdownPreference ? Number(page.preferences.show_countdown) > 0 : true;
            document.body.classList.toggle('countdown-disabled', !countdownEnabled);
            const taskLookup = new Map((Array.isArray(page.tasks) ? page.tasks : []).map(task => [String(task.id), task]));
            const htmlDecodeField = document.createElement('textarea');
            const helpOpen = document.querySelector('[data-help-open]');
            const helpModal = document.querySelector('[data-help-modal]');
            const helpClose = helpModal ? helpModal.querySelector('[data-help-close]') : null;
            const openHelp = () => {
                if (!helpModal) return;
                helpModal.classList.add('open');
                document.body.classList.add('modal-open');
            };
            const closeHelp = () => {
                if (!helpModal) return;
                helpModal.classList.remove('open');
                document.body.classList.remove('modal-open');
            };
            if (helpOpen && helpModal) {
                helpOpen.addEventListener('click', openHelp);
                if (helpClose) helpClose.addEventListener('click', closeHelp);
                helpModal.addEventListener('click', (e) => { if (e.target === helpModal) closeHelp(); });
                document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeHelp(); });
            }
            const prefOpen = document.querySelector('[data-routine-pref-open]');
            const prefModal = document.querySelector('[data-routine-pref-modal]');
            const prefClose = prefModal ? prefModal.querySelector('[data-routine-pref-close]') : null;
            const libraryOpen = document.querySelector('[data-routine-library-open]');
            const libraryModal = document.querySelector('[data-routine-library-modal]');
            const libraryClose = libraryModal ? libraryModal.querySelector('[data-routine-library-close]') : null;
            const blockedModal = document.querySelector('[data-routine-blocked-modal]');
            const blockedMessage = blockedModal ? blockedModal.querySelector('[data-routine-blocked-message]') : null;
            const blockedCloses = blockedModal ? blockedModal.querySelectorAll('[data-routine-blocked-close]') : [];
            const createOpen = document.querySelector('[data-routine-create-open]');
            const createModal = document.querySelector('[data-routine-create-modal]');
            const createClose = createModal ? createModal.querySelector('[data-routine-create-close]') : null;
            const openRoutineModal = (modal) => {
                if (!modal) return;
                modal.classList.add('open');
                document.body.classList.add('modal-open');
            };
            const closeRoutineModal = (modal) => {
                if (!modal) return;
                modal.classList.remove('open');
                document.body.classList.remove('modal-open');
            };
            const openRoutineBlockedModal = (message) => {
                if (!blockedModal) return;
                if (blockedMessage) {
                    blockedMessage.textContent = message || 'This routine cannot be completed right now.';
                }
                openRoutineModal(blockedModal);
            };
            if (blockedModal && blockedCloses.length) {
                blockedCloses.forEach(btn => btn.addEventListener('click', () => closeRoutineModal(blockedModal)));
                blockedModal.addEventListener('click', (e) => { if (e.target === blockedModal) closeRoutineModal(blockedModal); });
            }
            page.openRoutineBlockedModal = openRoutineBlockedModal;
            if (prefOpen && prefModal) {
                prefOpen.addEventListener('click', () => openRoutineModal(prefModal));
                if (prefClose) prefClose.addEventListener('click', () => closeRoutineModal(prefModal));
                prefModal.addEventListener('click', (e) => { if (e.target === prefModal) closeRoutineModal(prefModal); });
            }
            if (libraryOpen && libraryModal) {
                libraryOpen.addEventListener('click', () => openRoutineModal(libraryModal));
                if (libraryClose) libraryClose.addEventListener('click', () => closeRoutineModal(libraryModal));
                libraryModal.addEventListener('click', (e) => { if (e.target === libraryModal) closeRoutineModal(libraryModal); });
            }
            document.querySelectorAll('[data-routine-edit-open]').forEach((btn) => {
                const routineId = btn.getAttribute('data-routine-id');
                if (!routineId) return;
                const modal = document.querySelector(`[data-routine-edit-modal="${routineId}"]`);
                if (!modal) return;
                const closeBtn = modal.querySelector('[data-routine-edit-close]');
                btn.addEventListener('click', () => openRoutineModal(modal));
                if (closeBtn) {
                    closeBtn.addEventListener('click', () => closeRoutineModal(modal));
                }
                modal.addEventListener('click', (e) => { if (e.target === modal) closeRoutineModal(modal); });
            });
            if (createOpen && createModal) {
                createOpen.addEventListener('click', () => openRoutineModal(createModal));
                if (createClose) createClose.addEventListener('click', () => closeRoutineModal(createModal));
                createModal.addEventListener('click', (e) => { if (e.target === createModal) closeRoutineModal(createModal); });
            }
            if (createModal && createModal.classList.contains('open')) {
                document.body.classList.add('modal-open');
            }
            if (Array.isArray(page.editFormErrors) && page.editFormErrors.length) {
                page.editFormErrors.forEach((rid) => {
                    const modal = document.querySelector(`[data-routine-edit-modal="${rid}"]`);
                    if (modal) {
                        openRoutineModal(modal);
                    }
                });
            }

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

            function getLocalDateKey(date) {
                const year = date.getFullYear();
                const month = String(date.getMonth() + 1).padStart(2, '0');
                const day = String(date.getDate()).padStart(2, '0');
                return `${year}-${month}-${day}`;
            }

            function getWeekdayLabel(date) {
                const labels = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
                return labels[date.getDay()] || '';
            }

            function parseTimeToMinutes(value) {
                if (!value) return null;
                const parts = String(value).split(':').map(Number);
                if (parts.length < 2 || parts.some(Number.isNaN)) return null;
                return (parts[0] * 60) + parts[1];
            }

            function formatMinutesToTimeLabel(totalMinutes) {
                const safe = Math.max(0, Math.round(totalMinutes));
                let hours = Math.floor(safe / 60);
                const minutes = safe % 60;
                const suffix = hours >= 12 ? 'PM' : 'AM';
                if (hours === 0) hours = 12;
                if (hours > 12) hours -= 12;
                return `${hours}:${String(minutes).padStart(2, '0')} ${suffix}`;
            }

            function isRoutineScheduledToday(routine) {
                const today = new Date();
                const todayKey = getLocalDateKey(today);
                const todayLabel = getWeekdayLabel(today);
                const recurrence = routine.recurrence || '';
                if (recurrence === 'daily') {
                    return { scheduled: true };
                }
                if (recurrence === 'weekly') {
                    const daysRaw = Array.isArray(routine.recurrence_days)
                        ? routine.recurrence_days
                        : String(routine.recurrence_days || '').split(',');
                    const days = daysRaw.map(day => day.trim()).filter(Boolean);
                    if (!days.length) {
                        return { scheduled: true };
                    }
                    return { scheduled: days.includes(todayLabel), label: days.join(', ') };
                }
                const routineDate = routine.routine_date || routine.created_at || '';
                if (routineDate) {
                    const rawDate = String(routineDate);
                    const dateOnly = /^\d{4}-\d{2}-\d{2}$/.test(rawDate);
                    const parsed = dateOnly ? null : new Date(rawDate);
                    const dateKey = dateOnly
                        ? rawDate
                        : (Number.isNaN(parsed.getTime()) ? rawDate.slice(0, 10) : getLocalDateKey(parsed));
                    return { scheduled: dateKey === todayKey, dateKey };
                }
                return { scheduled: true };
            }

            function formatDateKeyLabel(dateKey) {
                if (!dateKey) return '';
                const parts = String(dateKey).split('-');
                if (parts.length !== 3) {
                    return dateKey;
                }
                return `${parts[1]}/${parts[2]}/${parts[0]}`;
            }

            function getRoutineStartGate(routine) {
                const schedule = isRoutineScheduledToday(routine);
                if (!schedule.scheduled) {
                    if ((routine.recurrence || '') === 'weekly' && schedule.label) {
                        return { allowed: false, message: `This routine is scheduled for: ${schedule.label}.` };
                    }
                    if (schedule.dateKey) {
                        return { allowed: false, message: `This routine is scheduled for ${formatDateKeyLabel(schedule.dateKey)} and can only be started that day.` };
                    }
                    return { allowed: false, message: 'This routine can only be started on its scheduled day.' };
                }
                const startMinutes = parseTimeToMinutes(routine.start_time);
                if (startMinutes === null) {
                    return { allowed: true };
                }
                const now = new Date();
                const nowMinutes = now.getHours() * 60 + now.getMinutes();
                const earliest = Math.max(0, startMinutes - 60);
                if (nowMinutes < earliest) {
                    return { allowed: false, message: `You can start this routine at ${formatMinutesToTimeLabel(earliest)}.` };
                }
                return { allowed: true };
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

                setTasks(tasks) {
                    this.selectedTasks = Array.isArray(tasks)
                        ? tasks
                            .map(task => ({ id: parseInt(task.id, 10) }))
                            .filter(task => Number.isFinite(task.id) && task.id > 0)
                        : [];
                    this.render();
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
                    this.audioToggleButtons = this.overlay ? Array.from(this.overlay.querySelectorAll("[data-role='audio-toggle']")) : [];
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
                    this.allowSoundEffects = Number(this.preferences.sound_effects_enabled ?? 1) > 0;
                    this.allowBackgroundMusic = Number(this.preferences.background_music_enabled ?? 1) > 0;
                    this.masterAudioEnabled = (this.allowSoundEffects || this.allowBackgroundMusic);
                    this.audioLocked = !this.allowSoundEffects && !this.allowBackgroundMusic;
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
                    this.flowStartTs = null;
                    this.statusScreenStartTs = null;
                    this.pendingStatusTaskId = null;

                    this.initializeTaskDurations();
                    this.resetWarningState();
                    if (Array.isArray(this.audioToggleButtons) && this.audioToggleButtons.length) {
                        this.audioToggleButtons.forEach(btn => {
                            btn.addEventListener('click', () => this.toggleAudio());
                        });
                    }
                    this.syncAudioToggleState();

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
                        if (this.progressTrackEl && ['circle', 'pie'].includes(this.progressTrackEl.getAttribute('data-style') || '')) {
                            this.progressTrackEl.style.setProperty('--min-ratio', 0);
                        }
                        return;
                    }
                    const ratio = Math.min(1, minSeconds / Math.max(1, totalSeconds));
                    this.minMarkerEl.style.transform = `scaleX(${ratio})`;
                    this.minMarkerEl.classList.add('active');
                    this.minLabelEl.textContent = `Minimum duration (${formatSeconds(minSeconds)})`;
                    this.minLabelEl.classList.add('active');
                    this.minLabelEl.classList.toggle('met', this.elapsedSeconds >= minSeconds);
                    if (this.progressTrackEl && ['circle', 'pie'].includes(this.progressTrackEl.getAttribute('data-style') || '')) {
                        this.progressTrackEl.style.setProperty('--min-ratio', ratio);
                    }
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

                areSoundEffectsEnabled() {
                    return !this.audioLocked && this.masterAudioEnabled && this.allowSoundEffects;
                }

                isMusicEnabled() {
                    return !this.audioLocked && this.masterAudioEnabled && this.allowBackgroundMusic;
                }

                syncAudioToggleState() {
                    const audioOn = !this.audioLocked && this.masterAudioEnabled;
                    if (Array.isArray(this.audioToggleButtons)) {
                        this.audioToggleButtons.forEach(btn => {
                            btn.disabled = this.audioLocked;
                            btn.classList.toggle('muted', !audioOn);
                            btn.setAttribute('aria-pressed', audioOn ? 'true' : 'false');
                            const icon = btn.querySelector('[data-audio-icon]');
                            if (icon) {
                                icon.innerHTML = audioOn
                                    ? '<i class="fa-solid fa-volume-high"></i>'
                                    : '<i class="fa-solid fa-volume-xmark"></i>';
                            }
                            const label = this.audioLocked
                                ? 'Audio disabled by parent preferences'
                                : audioOn ? 'Mute all routine sounds' : 'Enable all routine sounds';
                            btn.setAttribute('aria-label', label);
                            btn.title = label;
                        });
                    }
                }

                toggleAudio() {
                    if (this.audioLocked) {
                        return;
                    }
                    this.masterAudioEnabled = !this.masterAudioEnabled;
                    if (!this.masterAudioEnabled) {
                        this.silenceAllAudio();
                    } else if (this.currentScene === 'task') {
                        this.playBackgroundMusic();
                    }
                    this.syncAudioToggleState();
                }

                silenceAllAudio() {
                    this.pauseBackgroundMusic(true);
                    [this.statusChime, this.statusCoinSound, this.summaryChime].forEach(audio => {
                        if (!audio) return;
                        try {
                            audio.pause();
                            audio.currentTime = 0;
                        } catch (e) {}
                    });
                    if (Array.isArray(this.activeCoinClips) && this.activeCoinClips.length) {
                        this.activeCoinClips.forEach(record => {
                            if (!record || !record.clip) return;
                            try { record.clip.pause(); } catch (e) {}
                            try { record.clip.currentTime = 0; } catch (e) {}
                            if (typeof record.finish === 'function') {
                                record.finish();
                            }
                        });
                        this.activeCoinClips = [];
                    }
                }

                playBackgroundMusic() {
                    if (!this.backgroundAudio) {
                        return;
                    }
                    if (!this.isMusicEnabled()) {
                        this.pauseBackgroundMusic(true);
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
                    if (!this.areSoundEffectsEnabled()) {
                        return;
                    }
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
                        if (!this.areSoundEffectsEnabled()) {
                            resolve();
                            return;
                        }
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
                        if (!this.statusCoinSound || !this.areSoundEffectsEnabled()) {
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
                    if (!this.areSoundEffectsEnabled()) {
                        return;
                    }
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
                    const startGate = getRoutineStartGate(this.routine || {});
                    if (!startGate.allowed) {
                        const message = startGate.message || 'This routine cannot be started yet.';
                        if (page.openRoutineBlockedModal) {
                            page.openRoutineBlockedModal(message);
                        }
                        return;
                    }
                    if (this.routine && this.routine.completed_today) {
                        const completedAt = this.routine.last_completed_at
                            ? new Date(this.routine.last_completed_at).toLocaleString()
                            : '';
                        const message = completedAt
                            ? `You already completed this routine on ${completedAt}. It cannot be completed again today.`
                            : 'You already completed this routine today. It cannot be completed again.';
                        if (page.openRoutineBlockedModal) {
                            page.openRoutineBlockedModal(message);
                        }
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
                    this.statusScreenStartTs = null;
                    this.pendingStatusTaskId = null;
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
                    this.flowStartTs = Date.now();
                    this.statusScreenStartTs = null;
                    this.pendingStatusTaskId = null;
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
                    const completedAtMs = Date.now();
                    this.taskResults.push({
                        id: parseInt(this.currentTask.id, 10) || 0,
                        title: this.currentTask.title,
                        point_value: pointValue,
                        actual_seconds: actualSeconds,
                        scheduled_seconds: scheduled,
                        awarded_points: awardedPoints,
                        completed_at_ms: completedAtMs,
                        status_screen_seconds: 0
                    });
                    this.pendingStatusTaskId = parseInt(this.currentTask.id, 10) || 0;

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

                captureStatusDuration() {
                    if (!this.statusScreenStartTs || !this.pendingStatusTaskId) {
                        this.statusScreenStartTs = null;
                        return;
                    }
                    const durationSeconds = Math.max(0, Math.round((Date.now() - this.statusScreenStartTs) / 1000));
                    this.statusScreenStartTs = null;
                    const taskId = this.pendingStatusTaskId;
                    this.pendingStatusTaskId = null;
                    for (let i = this.taskResults.length - 1; i >= 0; i--) {
                        if (this.taskResults[i].id === taskId) {
                            this.taskResults[i].status_screen_seconds = durationSeconds;
                            break;
                        }
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
                        scheduled_seconds: result.scheduled_seconds,
                        completed_at_ms: result.completed_at_ms || 0,
                        status_screen_seconds: result.status_screen_seconds || 0
                    }));
                    const overtimeCount = this.taskResults.filter(result => result.scheduled_seconds > 0 && result.actual_seconds > result.scheduled_seconds).length;
                    payload.append('task_metrics', JSON.stringify(metrics));
                    if (typeof this.flowStartTs === 'number') {
                        payload.append('flow_start_ts', String(this.flowStartTs));
                    }
                    payload.append('flow_end_ts', String(Date.now()));
                    payload.append('overtime_count', String(overtimeCount));
                    fetch('routine.php', { method: 'POST', body: payload })
                        .then(response => response.json())
                        .then(data => {
                            if (data && ['duplicate', 'already_completed', 'not_today'].includes(data.status)) {
                                const message = data.message || 'This routine cannot be completed right now.';
                                this.closeOverlay();
                                if (page.openRoutineBlockedModal) {
                                    page.openRoutineBlockedModal(message);
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
                    const previousScene = this.currentScene;
                    if (previousScene === 'status' && name !== 'status') {
                        this.captureStatusDuration();
                    }
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
                        this.statusScreenStartTs = Date.now();
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
                    fetch('routine.php', { method: 'POST', body: payload })
                        .then(response => response.json())
                        .then(data => {
                            if (data && data.status === 'not_today') {
                                const message = data.message || 'This routine cannot be completed right now.';
                                this.closeOverlay();
                                if (page.openRoutineBlockedModal) {
                                    page.openRoutineBlockedModal(message);
                                }
                            }
                        })
                        .catch(() => {});
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
            const taskEditModal = document.querySelector('[data-role="task-edit-modal"]');
            const closeTaskEditModalButton = taskEditModal ? taskEditModal.querySelector('[data-action="close-task-edit-modal"]') : null;
            const taskEditButtons = document.querySelectorAll('[data-routine-task-edit-open]');
            let taskEditModalLastFocus = null;
            const toggleTaskEditModal = (shouldOpen) => {
                if (!taskEditModal) return;
                if (shouldOpen) {
                    taskEditModalLastFocus = document.activeElement;
                    taskEditModal.classList.add('active');
                    taskEditModal.setAttribute('aria-hidden', 'false');
                    const firstField = taskEditModal.querySelector('input, textarea, select');
                    if (firstField) {
                        firstField.focus();
                    }
                } else {
                    taskEditModal.classList.remove('active');
                    taskEditModal.setAttribute('aria-hidden', 'true');
                    if (taskEditModalLastFocus && typeof taskEditModalLastFocus.focus === 'function') {
                        taskEditModalLastFocus.focus();
                    }
                }
            };
            const populateTaskEditModal = (button) => {
                if (!taskEditModal || !button) return;
                const idField = taskEditModal.querySelector('input[name="routine_task_id"]');
                const titleField = taskEditModal.querySelector('input[name="edit_rt_title"]');
                const descriptionField = taskEditModal.querySelector('textarea[name="edit_rt_description"]');
                const timeLimitField = taskEditModal.querySelector('input[name="edit_rt_time_limit"]');
                const minMinutesField = taskEditModal.querySelector('input[name="edit_rt_min_minutes"]');
                const minEnabledField = taskEditModal.querySelector('input[name="edit_rt_min_enabled"]');
                const pointValueField = taskEditModal.querySelector('input[name="edit_rt_point_value"]');
                const categoryField = taskEditModal.querySelector('select[name="edit_rt_category"]');
                const taskId = button.getAttribute('data-task-id') || '';
                const taskTitle = decodeHtmlEntities(button.getAttribute('data-task-title') || '');
                const taskDescription = decodeHtmlEntities(button.getAttribute('data-task-description') || '');
                const taskTimeLimit = button.getAttribute('data-task-time-limit') || '';
                const taskMinMinutes = button.getAttribute('data-task-minutes') || '';
                const taskMinEnabled = button.getAttribute('data-task-min-enabled') === '1';
                const taskPointValue = button.getAttribute('data-task-point-value') || '0';
                const taskCategory = button.getAttribute('data-task-category') || '';
                if (idField) idField.value = taskId;
                if (titleField) titleField.value = taskTitle;
                if (descriptionField) descriptionField.value = taskDescription;
                if (timeLimitField) timeLimitField.value = taskTimeLimit;
                if (minMinutesField) minMinutesField.value = taskMinMinutes;
                if (minEnabledField) minEnabledField.checked = taskMinEnabled;
                if (pointValueField) pointValueField.value = taskPointValue;
                if (categoryField) categoryField.value = taskCategory;
            };
            const populateTaskCreateModal = (button) => {
                if (!taskModal || !button) return;
                const createForm = taskModal.querySelector('form');
                if (createForm) {
                    createForm.reset();
                }
                const titleField = taskModal.querySelector('input[name="rt_title"]');
                const descriptionField = taskModal.querySelector('textarea[name="rt_description"]');
                const timeLimitField = taskModal.querySelector('input[name="rt_time_limit"]');
                const minMinutesField = taskModal.querySelector('input[name="rt_min_time"]');
                const pointValueField = taskModal.querySelector('input[name="rt_point_value"]');
                const categoryField = taskModal.querySelector('select[name="rt_category"]');
                const taskTitle = decodeHtmlEntities(button.getAttribute('data-task-title') || '');
                const taskDescription = decodeHtmlEntities(button.getAttribute('data-task-description') || '');
                const taskTimeLimit = button.getAttribute('data-task-time-limit') || '';
                const taskMinMinutes = button.getAttribute('data-task-minutes') || '';
                const taskMinEnabled = button.getAttribute('data-task-min-enabled') === '1';
                const taskPointValue = button.getAttribute('data-task-point-value') || '0';
                const taskCategory = button.getAttribute('data-task-category') || '';
                if (titleField) titleField.value = taskTitle;
                if (descriptionField) descriptionField.value = taskDescription;
                if (timeLimitField) timeLimitField.value = taskTimeLimit;
                if (minMinutesField) minMinutesField.value = taskMinEnabled ? taskMinMinutes : '';
                if (pointValueField) pointValueField.value = taskPointValue;
                if (categoryField) categoryField.value = taskCategory;
            };
            if (taskEditButtons.length && taskEditModal) {
                taskEditButtons.forEach((button) => {
                    button.addEventListener('click', () => {
                        populateTaskEditModal(button);
                        toggleTaskEditModal(true);
                    });
                });
            }
            const taskDuplicateButtons = document.querySelectorAll('[data-routine-task-duplicate-open]');
            if (taskDuplicateButtons.length && taskModal) {
                taskDuplicateButtons.forEach((button) => {
                    button.addEventListener('click', () => {
                        populateTaskCreateModal(button);
                        toggleTaskModal(true);
                    });
                });
            }
            if (closeTaskEditModalButton && taskEditModal) {
                closeTaskEditModalButton.addEventListener('click', () => toggleTaskEditModal(false));
            }
            if (taskEditModal) {
                taskEditModal.addEventListener('click', (event) => {
                    if (event.target === taskEditModal) {
                        toggleTaskEditModal(false);
                    }
                });
                document.addEventListener('keydown', (event) => {
                    if (event.key === 'Escape' && taskEditModal.classList.contains('active')) {
                        toggleTaskEditModal(false);
                    }
                });
            }

            const parentCompleteForms = document.querySelectorAll('.parent-complete-form');
            parentCompleteForms.forEach((form) => {
                const hiddenField = form.querySelector('[data-role="parent-completed-at"]');
                if (!hiddenField || !form.id) {
                    return;
                }
                const checkboxes = Array.from(document.querySelectorAll(`input[data-parent-complete-task][form="${form.id}"]`));
                if (!checkboxes.length) {
                    return;
                }
                const timestamps = {};
                const syncField = () => {
                    hiddenField.value = JSON.stringify(timestamps);
                };
                checkboxes.forEach((checkbox) => {
                    const taskId = checkbox.dataset.taskId || '';
                    const initialCompletedAt = checkbox.dataset.completedAt || '';
                    if (checkbox.checked && initialCompletedAt) {
                        const parsed = Date.parse(initialCompletedAt);
                        if (!Number.isNaN(parsed)) {
                            timestamps[taskId] = parsed;
                        }
                    }
                    checkbox.addEventListener('change', () => {
                        if (checkbox.checked) {
                            if (!timestamps[taskId]) {
                                timestamps[taskId] = Date.now();
                            }
                        } else {
                            delete timestamps[taskId];
                        }
                        syncField();
                    });
                });
                syncField();
            });

            const routineBuilders = {};
            document.querySelectorAll('.routine-builder').forEach(container => {
                const id = container.dataset.builderId || '';
                let initial = [];
                if (id === 'create') {
                    initial = Array.isArray(page.createInitial) ? page.createInitial : [];
                } else if (id.startsWith('edit-')) {
                    const key = id.replace('edit-', '');
                    initial = (page.editInitial && page.editInitial[key]) ? page.editInitial[key] : [];
                }
                const builder = new RoutineBuilder(container, initial);
                if (id) {
                    routineBuilders[id] = builder;
                }
            });

            const openRoutineDuplicate = (payload) => {
                if (!createModal) return;
                const form = createModal.querySelector('form');
                if (!form) return;
                form.reset();

                const childInputs = form.querySelectorAll('input[name="child_user_ids[]"]');
                childInputs.forEach(input => {
                    input.checked = String(input.value) === String(payload.child_user_id || '');
                });
                const duplicateChildInput = form.querySelector('[data-role="duplicate-child-id"]');
                if (duplicateChildInput) {
                    duplicateChildInput.value = payload.child_user_id ? String(payload.child_user_id) : '';
                }
                if (!form.dataset.duplicateChildBound) {
                    form.addEventListener('change', (event) => {
                        const target = event.target;
                        if (!(target instanceof HTMLInputElement)) {
                            return;
                        }
                        if (target.name !== 'child_user_ids[]') {
                            return;
                        }
                        const holder = form.querySelector('[data-role="duplicate-child-id"]');
                        if (!holder) {
                            return;
                        }
                        if (target.checked) {
                            holder.value = String(target.value || '');
                        } else {
                            const anyChecked = Array.from(form.querySelectorAll('input[name="child_user_ids[]"]'))
                                .some(input => input.checked);
                            if (!anyChecked) {
                                holder.value = '';
                            }
                        }
                    });
                    form.dataset.duplicateChildBound = '1';
                }

                const titleInput = form.querySelector('input[name="title"]');
                const timeOfDaySelect = form.querySelector('select[name="time_of_day"]');
                const startInput = form.querySelector('input[name="start_time"]');
                const endInput = form.querySelector('input[name="end_time"]');
                const bonusInput = form.querySelector('input[name="bonus_points"]');
                const recurrenceSelect = form.querySelector('select[name="recurrence"]');
                const dateInput = form.querySelector('input[name="routine_date"]');

                if (titleInput) titleInput.value = payload.title || '';
                if (timeOfDaySelect) timeOfDaySelect.value = payload.time_of_day || 'anytime';
                if (startInput) startInput.value = payload.start_time || '';
                if (endInput) endInput.value = payload.end_time || '';
                if (bonusInput) {
                    const bonusValue = Number.isFinite(Number(payload.bonus_points)) ? Number(payload.bonus_points) : 0;
                    bonusInput.value = bonusValue;
                }
                if (recurrenceSelect) {
                    recurrenceSelect.value = payload.recurrence || '';
                    recurrenceSelect.dispatchEvent(new Event('change'));
                }
                const repeatDays = Array.isArray(payload.recurrence_days) ? payload.recurrence_days.map(String) : [];
                form.querySelectorAll('input[name="recurrence_days[]"]').forEach(input => {
                    input.checked = repeatDays.includes(String(input.value));
                });
                if (dateInput) dateInput.value = payload.routine_date || '';

                const builder = routineBuilders.create;
                if (builder) {
                    builder.setTasks(Array.isArray(payload.tasks) ? payload.tasks : []);
                }

                openRoutineModal(createModal);
            };

            document.querySelectorAll('[data-routine-duplicate-open]').forEach((button) => {
                button.addEventListener('click', () => {
                    let payload = {};
                    try {
                        payload = JSON.parse(button.dataset.routinePayload || '{}');
                    } catch (err) {
                        payload = {};
                    }
                    openRoutineDuplicate(payload);
                });
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

            const routineSection = document.querySelector('[data-routine-section]');
            const routineGrid = routineSection ? routineSection.querySelector('[data-routine-grid]') : null;
            if (routineGrid) {
                const routineCards = Array.from(routineGrid.querySelectorAll('.routine-card'));
                const orderMap = { morning: 0, afternoon: 1, evening: 2, anytime: 3 };
                routineCards.sort((a, b) => {
                    const orderA = orderMap[a.dataset.timeOfDay] ?? 3;
                    const orderB = orderMap[b.dataset.timeOfDay] ?? 3;
                    if (orderA !== orderB) return orderA - orderB;
                    const timeA = a.dataset.startTime || '99:99';
                    const timeB = b.dataset.startTime || '99:99';
                    const timeCompare = timeA.localeCompare(timeB);
                    if (timeCompare !== 0) return timeCompare;
                    const titleA = a.querySelector('h3')?.textContent || '';
                    const titleB = b.querySelector('h3')?.textContent || '';
                    return titleA.localeCompare(titleB);
                });
                routineCards.forEach(card => routineGrid.appendChild(card));

                const viewButtons = Array.from(routineSection.querySelectorAll('[data-routine-view]'));
                const setView = (view) => {
                    const isList = view === 'list';
                    routineGrid.classList.toggle('list-view', isList);
                    viewButtons.forEach((btn) => {
                        const active = btn.getAttribute('data-routine-view') === (isList ? 'list' : 'card');
                        btn.classList.toggle('active', active);
                        btn.setAttribute('aria-pressed', active ? 'true' : 'false');
                    });
                };
                viewButtons.forEach((btn) => {
                    btn.addEventListener('click', () => setView(btn.getAttribute('data-routine-view')));
                });

                const childFilters = Array.from(routineSection.querySelectorAll('[data-routine-child]'));
                const selectAll = routineSection.querySelector('[data-routine-select-all]');
                const updateSelectAllState = () => {
                    if (!selectAll || !childFilters.length) return;
                    const allChecked = childFilters.every((input) => input.checked);
                    const anyChecked = childFilters.some((input) => input.checked);
                    selectAll.checked = allChecked;
                    selectAll.indeterminate = !allChecked && anyChecked;
                };
                const applyChildFilter = () => {
                    if (!childFilters.length) return;
                    const selectedIds = childFilters
                        .filter((input) => input.checked)
                        .map((input) => input.value);
                    routineCards.forEach((card) => {
                        const cardChildId = card.dataset.childId || '';
                        const visible = selectedIds.length ? selectedIds.includes(cardChildId) : false;
                        card.classList.toggle('is-hidden', !visible);
                    });
                };
                childFilters.forEach((input) => {
                    input.addEventListener('change', () => {
                        updateSelectAllState();
                        applyChildFilter();
                    });
                });
                if (selectAll) {
                    selectAll.addEventListener('change', () => {
                        const checked = selectAll.checked;
                        childFilters.forEach((input) => {
                            input.checked = checked;
                        });
                        updateSelectAllState();
                        applyChildFilter();
                    });
                    updateSelectAllState();
                }
                applyChildFilter();
            }

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

            const updateRepeatDays = (selectEl, wrapper) => {
                if (!selectEl || !wrapper) return;
                const show = selectEl.value === 'weekly';
                wrapper.style.display = show ? 'block' : 'none';
            };
            const updateRoutineDateVisibility = (selectEl, wrapper) => {
                if (!selectEl || !wrapper) return;
                const show = selectEl.value === '';
                wrapper.style.display = show ? 'block' : 'none';
            };
            const createRepeat = document.querySelector('#recurrence');
            const createRepeatDays = document.querySelector('[data-create-recurrence-days]');
            const createRoutineDate = document.querySelector('[data-create-routine-date]');
            if (createRepeat && createRepeatDays) {
                updateRepeatDays(createRepeat, createRepeatDays);
                createRepeat.addEventListener('change', () => updateRepeatDays(createRepeat, createRepeatDays));
            }
            if (createRepeat && createRoutineDate) {
                updateRoutineDateVisibility(createRepeat, createRoutineDate);
                createRepeat.addEventListener('change', () => updateRoutineDateVisibility(createRepeat, createRoutineDate));
            }
            document.querySelectorAll('[data-recurrence-days-wrapper]').forEach(wrapper => {
                const form = wrapper.closest('form');
                const selectEl = form ? form.querySelector('select[name="recurrence"]') : null;
                if (!selectEl) return;
                updateRepeatDays(selectEl, wrapper);
                selectEl.addEventListener('change', () => updateRepeatDays(selectEl, wrapper));
            });
            document.querySelectorAll('[data-routine-date-wrapper]').forEach(wrapper => {
                const form = wrapper.closest('form');
                const selectEl = form ? form.querySelector('select[name="recurrence"]') : null;
                if (!selectEl) return;
                updateRoutineDateVisibility(selectEl, wrapper);
                selectEl.addEventListener('change', () => updateRoutineDateVisibility(selectEl, wrapper));
            });

            const routineMenus = document.querySelectorAll('.routine-actions-menu');
            if (routineMenus.length) {
                const closeRoutineMenus = (except) => {
                    routineMenus.forEach(menu => {
                        if (menu !== except) menu.removeAttribute('open');
                    });
                };
                document.addEventListener('click', (event) => {
                    if (!event.target.closest('.routine-actions-menu')) {
                        closeRoutineMenus();
                    }
                });
                routineMenus.forEach(menu => {
                    const toggle = menu.querySelector('.routine-actions-toggle');
                    if (toggle) {
                        toggle.addEventListener('click', (event) => {
                            event.stopPropagation();
                            closeRoutineMenus(menu);
                        });
                    }
                    menu.querySelectorAll('.routine-actions-dropdown button').forEach(btn => {
                        btn.addEventListener('click', () => {
                            menu.removeAttribute('open');
                        });
                    });
                });
            }

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
  <script src="js/number-stepper.js" defer></script>
<?php if (!empty($isParentNotificationUser)): ?>
    <?php include __DIR__ . '/includes/notifications_parent.php'; ?>
<?php endif; ?>
<?php if (!empty($isChildNotificationUser)): ?>
    <?php include __DIR__ . '/includes/notifications_child.php'; ?>
<?php endif; ?>
</body>
</html>
<?php













