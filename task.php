<?php
// task.php - Task and chore management
// Purpose: Allow parents to create tasks and children to view/complete them
// Inputs: POST data for task creation, task ID for completion
// Outputs: Task management interface
// Version: 3.26.0

session_start(); // Ensure session is started to load existing session

require_once __DIR__ . '/includes/functions.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
$currentPage = basename($_SERVER['PHP_SELF']);

// Ensure display name in session for header
if (!isset($_SESSION['name'])) {
    $_SESSION['name'] = getDisplayName($_SESSION['user_id']);
}

$family_root_id = getFamilyRootId($_SESSION['user_id']);

require_once __DIR__ . '/includes/notifications_bootstrap.php';

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
        $points = filter_input(INPUT_POST, 'points', FILTER_VALIDATE_INT);
        $repeat = filter_input(INPUT_POST, 'recurrence', FILTER_SANITIZE_STRING);
        $recurrence = $repeat === 'daily' ? 'daily' : ($repeat === 'weekly' ? 'weekly' : '');
        $recurrence_days = null;
        if ($repeat === 'weekly') {
            $days = $_POST['recurrence_days'] ?? [];
            $days = array_values(array_filter(array_map('trim', (array) $days)));
            $recurrence_days = !empty($days) ? implode(',', $days) : null;
        }
        $time_of_day_input = filter_input(INPUT_POST, 'time_of_day', FILTER_SANITIZE_STRING);
        $time_of_day = in_array($time_of_day_input, ['anytime', 'morning', 'afternoon', 'evening'], true) ? $time_of_day_input : 'anytime';
        $due_date = $start_date;
        if (!empty($due_time)) {
            $due_date .= ' ' . $due_time . ':00';
        } elseif ($recurrence === '' && $time_of_day === 'anytime') {
            $due_date .= ' 23:59:00';
        }
        $category = filter_input(INPUT_POST, 'category', FILTER_SANITIZE_STRING);
        $timing_mode = filter_input(INPUT_POST, 'timing_mode', FILTER_SANITIZE_STRING);
        $timer_minutes = filter_input(INPUT_POST, 'timer_minutes', FILTER_VALIDATE_INT);
        if ($timing_mode !== 'timer') {
            $timer_minutes = null;
        }
        $photo_proof_required = !empty($_POST['photo_proof_required']) ? 1 : 0;

        if (!empty($child_ids) && canCreateContent($_SESSION['user_id'])) {
            $allOk = true;
            foreach ($child_ids as $child_user_id) {
                $ok = createTask($family_root_id, $child_user_id, $title, $description, $due_date, $end_date, $points, $recurrence, $recurrence_days, $category, $timing_mode, $timer_minutes, $time_of_day, $photo_proof_required, $_SESSION['user_id']);
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
        $points = filter_input(INPUT_POST, 'points', FILTER_VALIDATE_INT);
        $repeat = filter_input(INPUT_POST, 'recurrence', FILTER_SANITIZE_STRING);
        $recurrence = $repeat === 'daily' ? 'daily' : ($repeat === 'weekly' ? 'weekly' : '');
        $recurrence_days = null;
        if ($repeat === 'weekly') {
            $days = $_POST['recurrence_days'] ?? [];
            $days = array_values(array_filter(array_map('trim', (array) $days)));
            $recurrence_days = !empty($days) ? implode(',', $days) : null;
        }
        $time_of_day_input = filter_input(INPUT_POST, 'time_of_day', FILTER_SANITIZE_STRING);
        $time_of_day = in_array($time_of_day_input, ['anytime', 'morning', 'afternoon', 'evening'], true) ? $time_of_day_input : 'anytime';
        $due_date = $start_date;
        if (!empty($due_time)) {
            $due_date .= ' ' . $due_time . ':00';
        } elseif ($recurrence === '' && $time_of_day === 'anytime') {
            $due_date .= ' 23:59:00';
        }
        $category = filter_input(INPUT_POST, 'category', FILTER_SANITIZE_STRING);
        $timing_mode = filter_input(INPUT_POST, 'timing_mode', FILTER_SANITIZE_STRING);
        $timer_minutes = filter_input(INPUT_POST, 'timer_minutes', FILTER_VALIDATE_INT);
        if ($timing_mode !== 'timer') {
            $timer_minutes = null;
        }
        $photo_proof_required = !empty($_POST['photo_proof_required']) ? 1 : 0;

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
                                      timer_minutes = :timer_minutes,
                                      time_of_day = :time_of_day,
                                      photo_proof_required = :photo_proof_required
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
                ':time_of_day' => $time_of_day,
                ':photo_proof_required' => $photo_proof_required,
                ':id' => $task_id,
                ':parent_id' => $family_root_id
            ]);
            $allOk = $ok;
            foreach (array_slice($child_ids, 1) as $child_id) {
                $cloneOk = createTask($family_root_id, $child_id, $title, $description, $due_date, $end_date, $points, $recurrence, $recurrence_days, $category, $timing_mode, $timer_minutes, $time_of_day, $photo_proof_required, $_SESSION['user_id']);
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
        $instance_date = filter_input(INPUT_POST, 'instance_date', FILTER_SANITIZE_STRING);
        $photo_proof = null;
        $taskInfoStmt = $db->prepare("SELECT parent_user_id, child_user_id, title, photo_proof_required FROM tasks WHERE id = :id");
        $taskInfoStmt->execute([':id' => $task_id]);
        $taskInfo = $taskInfoStmt->fetch(PDO::FETCH_ASSOC);
        $childIdForComplete = null;
        if ($taskInfo) {
            if (canCreateContent($_SESSION['user_id']) && canAddEditChild($_SESSION['user_id'])) {
                if ((int) $taskInfo['parent_user_id'] === (int) $family_root_id) {
                    $childIdForComplete = (int) $taskInfo['child_user_id'];
                }
            } elseif ((int) $taskInfo['child_user_id'] === (int) $_SESSION['user_id']) {
                $childIdForComplete = (int) $_SESSION['user_id'];
            }
        }
        $isParentCompleting = !empty($taskInfo)
            && canCreateContent($_SESSION['user_id'])
            && (int) $taskInfo['parent_user_id'] === (int) $family_root_id;
        if (!$taskInfo || !$childIdForComplete) {
            $message = "Task not found.";
        } else {
            $photoRequired = !empty($taskInfo['photo_proof_required']) && !$isParentCompleting;
            $hasUpload = isset($_FILES['photo_proof']) && !empty($_FILES['photo_proof']['name']) && is_uploaded_file($_FILES['photo_proof']['tmp_name']);
            if ($photoRequired && !$hasUpload) {
                $message = "Photo proof is required to complete this task.";
            } else {
                if ($hasUpload) {
                    $ext = pathinfo($_FILES['photo_proof']['name'], PATHINFO_EXTENSION);
                    $ext = $ext ? '.' . strtolower($ext) : '';
                    $photo_proof = 'uploads/task_' . (int) $task_id . '_' . time() . $ext;
                }
                if (completeTask($task_id, $childIdForComplete, $photo_proof, $instance_date)) {
                    if ($photo_proof && $hasUpload) {
                        move_uploaded_file($_FILES['photo_proof']['tmp_name'], $photo_proof);
                    }
                    $childName = $_SESSION['name'] ?? $_SESSION['username'] ?? 'Child';
        if (!empty($taskInfo['parent_user_id']) && (int) $taskInfo['parent_user_id'] !== (int) $_SESSION['user_id']) {
            $noteMessage = sprintf('%s completed task: %s', $childName, $taskInfo['title'] ?? 'Task');
            $linkInstanceDate = $instance_date ?: date('Y-m-d');
            $linkUrl = 'task.php?task_id=' . (int) $task_id;
            if (!empty($linkInstanceDate)) {
                $linkUrl .= '&instance_date=' . urlencode($linkInstanceDate);
            }
            $linkUrl .= '#task-' . (int) $task_id;
            addParentNotification((int) $taskInfo['parent_user_id'], 'task_completed', $noteMessage, $linkUrl);
        }
                    if ($isParentCompleting) {
                        if (approveTask($task_id, $instance_date)) {
                            $message = "Task approved!";
                        } else {
                            $message = "Task completed, but approval failed.";
                        }
                    } else {
                        $message = "Task marked as completed (awaiting approval).";
                    }
                } else {
                    $message = "Failed to complete task.";
                }
            }
        }
    } elseif (isset($_POST['approve_task']) && isset($_SESSION['user_id']) && canCreateContent($_SESSION['user_id']) && canAddEditChild($_SESSION['user_id'])) {
        $task_id = filter_input(INPUT_POST, 'task_id', FILTER_VALIDATE_INT);
        $instance_date = filter_input(INPUT_POST, 'instance_date', FILTER_SANITIZE_STRING);
        if (approveTask($task_id, $instance_date)) {
            $message = "Task approved!";
        } else {
            $message = "Failed to approve task.";
        }
    } elseif (isset($_POST['reject_task']) && isset($_SESSION['user_id']) && canCreateContent($_SESSION['user_id']) && canAddEditChild($_SESSION['user_id'])) {
        $task_id = filter_input(INPUT_POST, 'task_id', FILTER_VALIDATE_INT);
        $reject_note = trim((string)filter_input(INPUT_POST, 'reject_note', FILTER_SANITIZE_STRING));
        $reject_action = filter_input(INPUT_POST, 'reject_action', FILTER_SANITIZE_STRING);
        $reactivate = $reject_action === 'reactivate';
        $instance_date = filter_input(INPUT_POST, 'instance_date', FILTER_SANITIZE_STRING);
        if ($task_id && rejectTask($task_id, $family_root_id, $reject_note, $reactivate, $_SESSION['user_id'], $instance_date)) {
            $message = $reactivate ? "Task rejected and reactivated." : "Task rejected and closed.";
        } else {
            $message = "Failed to reject task.";
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
$childAvatarById = [];
foreach ($children as $child) {
    $childAvatarById[(int)$child['child_user_id']] = $child['avatar'] ?? 'images/default-avatar.png';
}
if (!canCreateContent($_SESSION['user_id'])) {
    $stmt = $db->prepare("SELECT child_user_id, child_name, avatar FROM child_profiles WHERE child_user_id = :child_id AND deleted_at IS NULL");
    $stmt->execute([':child_id' => $_SESSION['user_id']]);
    $profile = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($profile) {
        $avatar = !empty($profile['avatar']) ? $profile['avatar'] : 'images/default-avatar.png';
        $childAvatarById[(int)$profile['child_user_id']] = $avatar;
        if (empty($childNameById[(int)$profile['child_user_id']])) {
            $childNameById[(int)$profile['child_user_id']] = $profile['child_name'] ?? '';
        }
    }
}

$tasks = getTasks($_SESSION['user_id']);
$selectedChildId = filter_input(INPUT_GET, 'child_id', FILTER_VALIDATE_INT);
$selectedChildId = $selectedChildId ? (int) $selectedChildId : 0;
if (!empty($selectedChildId) && canCreateContent($_SESSION['user_id']) && !empty($children)) {
    $allowedChildIds = array_map(static function ($child) {
        return (int) ($child['child_user_id'] ?? 0);
    }, $children);
    if (!in_array($selectedChildId, $allowedChildIds, true)) {
        $selectedChildId = 0;
    }
}
if (!empty($selectedChildId)) {
    $tasks = array_values(array_filter($tasks, static function ($task) use ($selectedChildId) {
        return (int) ($task['child_user_id'] ?? 0) === $selectedChildId;
    }));
}
$availableCategories = [];
if (!empty($tasks)) {
    $availableCategories = array_values(array_unique(array_filter(array_map(static function ($task) {
        return $task['category'] ?? '';
    }, $tasks))));
    sort($availableCategories);
}
$filterStatus = trim((string) filter_input(INPUT_GET, 'status', FILTER_UNSAFE_RAW));
$filterCategory = trim((string) filter_input(INPUT_GET, 'category', FILTER_UNSAFE_RAW));
$filterTimeOfDay = trim((string) filter_input(INPUT_GET, 'time_of_day', FILTER_UNSAFE_RAW));
$filterPhoto = trim((string) filter_input(INPUT_GET, 'photo_required', FILTER_UNSAFE_RAW));
$filterTimed = trim((string) filter_input(INPUT_GET, 'timed', FILTER_UNSAFE_RAW));
$filterRepeat = trim((string) filter_input(INPUT_GET, 'repeat', FILTER_UNSAFE_RAW));
$statusAllowed = ['pending', 'completed', 'approved', 'expired'];
if (!in_array($filterStatus, $statusAllowed, true)) {
    $filterStatus = '';
}
if ($filterCategory !== '' && !in_array($filterCategory, $availableCategories, true)) {
    $filterCategory = '';
}
$timeAllowed = ['anytime', 'morning', 'afternoon', 'evening'];
if ($filterTimeOfDay !== '' && !in_array($filterTimeOfDay, $timeAllowed, true)) {
    $filterTimeOfDay = '';
}
$photoAllowed = ['required', 'not_required'];
if ($filterPhoto !== '' && !in_array($filterPhoto, $photoAllowed, true)) {
    $filterPhoto = '';
}
$timedAllowed = ['timed', 'not_timed'];
if ($filterTimed !== '' && !in_array($filterTimed, $timedAllowed, true)) {
    $filterTimed = '';
}
$repeatAllowed = ['once', 'everyday', 'specific_days'];
if ($filterRepeat !== '' && !in_array($filterRepeat, $repeatAllowed, true)) {
    $filterRepeat = '';
}
if ($filterCategory || $filterTimeOfDay || $filterPhoto || $filterTimed || $filterRepeat) {
    $tasks = array_values(array_filter($tasks, static function ($task) use ($filterCategory, $filterTimeOfDay, $filterPhoto, $filterTimed, $filterRepeat) {
        if ($filterCategory && ($task['category'] ?? '') !== $filterCategory) {
            return false;
        }
        if ($filterTimeOfDay && ($task['time_of_day'] ?? 'anytime') !== $filterTimeOfDay) {
            return false;
        }
        $photoRequired = !empty($task['photo_proof_required']);
        if ($filterPhoto === 'required' && !$photoRequired) {
            return false;
        }
        if ($filterPhoto === 'not_required' && $photoRequired) {
            return false;
        }
        $timed = ($task['timing_mode'] ?? '') === 'timer';
        if ($filterTimed === 'timed' && !$timed) {
            return false;
        }
        if ($filterTimed === 'not_timed' && $timed) {
            return false;
        }
        $recurrence = $task['recurrence'] ?? '';
        if ($filterRepeat === 'once' && $recurrence !== '') {
            return false;
        }
        if ($filterRepeat === 'everyday' && $recurrence !== 'daily') {
            return false;
        }
        if ($filterRepeat === 'specific_days' && $recurrence !== 'weekly') {
            return false;
        }
        return true;
    }));
}
$tasksCount = count($tasks);
$filtersActive = !empty($filterStatus) || !empty($filterCategory) || !empty($filterTimeOfDay) || !empty($filterPhoto) || !empty($filterTimed) || !empty($filterRepeat);
// Format due_date for display
foreach ($tasks as &$task) {
    $task['due_date_formatted'] = !empty($task['due_date']) ? date('m/d/Y h:i A', strtotime($task['due_date'])) : 'No date set';
    if (empty($task['child_display_name'])) {
        $task['child_display_name'] = $childNameById[(int)($task['child_user_id'] ?? 0)] ?? '';
    }
}
unset($task);

$taskInstancesByTask = [];
if (!empty($tasks)) {
    $taskIds = array_map(static function ($task) {
        return (int) ($task['id'] ?? 0);
    }, $tasks);
    $taskIds = array_values(array_filter($taskIds));
    if (!empty($taskIds)) {
        $placeholders = implode(',', array_fill(0, count($taskIds), '?'));
        $stmt = $db->prepare("SELECT task_id, date_key, status, note, photo_proof, completed_at, approved_at, rejected_at FROM task_instances WHERE task_id IN ($placeholders)");
        $stmt->execute($taskIds);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $taskId = (int) $row['task_id'];
            $dateKey = $row['date_key'];
            if (!isset($taskInstancesByTask[$taskId])) {
                $taskInstancesByTask[$taskId] = [];
            }
            $taskInstancesByTask[$taskId][$dateKey] = [
                'status' => $row['status'],
                'note' => $row['note'],
                'photo_proof' => $row['photo_proof'],
                'completed_at' => $row['completed_at'],
                'approved_at' => $row['approved_at'],
                'rejected_at' => $row['rejected_at']
            ];
        }
    }
}

// Group tasks by status for sectioned display
$today_key = date('Y-m-d');
$pending_tasks = array_filter($tasks, function($t) { return $t['status'] === 'pending'; });
$pending_tasks = array_values(array_filter($pending_tasks, function($t) use ($today_key) {
    $end_key = !empty($t['end_date']) ? $t['end_date'] : null;
    return !$end_key || $end_key >= $today_key;
}));
$completed_tasks = array_filter($tasks, function($t) { return $t['status'] === 'completed' && empty($t['recurrence']); }); // Waiting approval (non-recurring)
$approved_tasks = array_filter($tasks, function($t) { return $t['status'] === 'approved' && empty($t['recurrence']); });

$taskById = [];
foreach ($tasks as $task) {
    $taskById[(int) $task['id']] = $task;
}

$completed_instances = [];
$approved_instances = [];
foreach ($taskInstancesByTask as $taskId => $instances) {
    $baseTask = $taskById[$taskId] ?? null;
    if (!$baseTask) continue;
    foreach ($instances as $dateKey => $instance) {
        if ($instance['status'] === 'completed') {
            $entry = $baseTask;
            $entry['instance_date'] = $dateKey;
            $entry['instance_status'] = $instance['status'];
            $entry['photo_proof'] = $instance['photo_proof'];
            $entry['completed_at'] = $instance['completed_at'];
            $completed_instances[] = $entry;
        } elseif ($instance['status'] === 'approved') {
            $entry = $baseTask;
            $entry['instance_date'] = $dateKey;
            $entry['instance_status'] = $instance['status'];
            $entry['photo_proof'] = $instance['photo_proof'];
            $entry['approved_at'] = $instance['approved_at'];
            $approved_instances[] = $entry;
        }
    }
}
$completed_tasks = array_values(array_merge($completed_tasks, $completed_instances));
$approved_tasks = array_values(array_merge($approved_tasks, $approved_instances));
$weekStart = new DateTimeImmutable('monday this week');
$weekEnd = $weekStart->modify('+6 days');
$approved_tasks = array_values(array_filter($approved_tasks, function($task) use ($weekStart, $weekEnd) {
    $stamp = $task['approved_at'] ?? $task['completed_at'] ?? null;
    if (!$stamp && !empty($task['instance_date'])) {
        $stamp = $task['instance_date'];
    }
    if (!$stamp) {
        return false;
    }
    $dateKey = date('Y-m-d', strtotime($stamp));
    return $dateKey >= $weekStart->format('Y-m-d') && $dateKey <= $weekEnd->format('Y-m-d');
}));
$expired_tasks = [];
foreach ($tasks as $task) {
    $end_key = !empty($task['end_date']) ? $task['end_date'] : null;
    if (!$end_key || $end_key >= $today_key) {
        continue;
    }
    if (in_array(($task['status'] ?? ''), ['approved', 'completed', 'rejected'], true)) {
        continue;
    }
    $instances = $taskInstancesByTask[(int) ($task['id'] ?? 0)] ?? [];
    $hasCompletion = false;
    foreach ($instances as $instance) {
        if (in_array(($instance['status'] ?? ''), ['completed', 'approved'], true)) {
            $hasCompletion = true;
            break;
        }
    }
    if ($hasCompletion) {
        continue;
    }
    $expired_tasks[] = $task;
}
$statusSections = [
    'pending' => &$pending_tasks,
    'completed' => &$completed_tasks,
    'approved' => &$approved_tasks,
    'expired' => &$expired_tasks
];
if ($filterStatus !== '') {
    foreach ($statusSections as $key => &$section) {
        if ($key !== $filterStatus) {
            $section = [];
        }
    }
    unset($section);
    $tasksCount = isset($statusSections[$filterStatus]) ? count($statusSections[$filterStatus]) : 0;
}

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
$calendarTasks = [];
foreach ($tasks as $task) {
    if (($task['status'] ?? '') === 'rejected') {
        continue;
    }
    $calendarTasks[] = [
        'id' => (int) ($task['id'] ?? 0),
        'title' => $task['title'] ?? '',
        'description' => $task['description'] ?? '',
        'due_date' => $task['due_date'] ?? '',
        'due_date_formatted' => $task['due_date_formatted'] ?? '',
        'end_date' => $task['end_date'] ?? '',
        'points' => (int) ($task['points'] ?? 0),
        'recurrence' => $task['recurrence'] ?? '',
        'recurrence_days' => $task['recurrence_days'] ?? '',
        'time_of_day' => $task['time_of_day'] ?? 'anytime',
        'category' => $task['category'] ?? '',
        'timing_mode' => $task['timing_mode'] ?? '',
        'timer_minutes' => (int) ($task['timer_minutes'] ?? 0),
        'status' => $task['status'] ?? '',
        'completed_at' => $task['completed_at'] ?? '',
        'approved_at' => $task['approved_at'] ?? '',
        'photo_proof' => $task['photo_proof'] ?? '',
        'photo_proof_required' => !empty($task['photo_proof_required']) ? 1 : 0,
        'instances' => $taskInstancesByTask[(int) ($task['id'] ?? 0)] ?? [],
        'child_user_id' => (int) ($task['child_user_id'] ?? 0),
        'child_name' => $task['child_display_name'] ?? '',
        'child_avatar' => $childAvatarById[(int) ($task['child_user_id'] ?? 0)] ?? 'images/default-avatar.png',
        'creator_name' => $task['creator_display_name'] ?? ''
    ];
}
$calendarPremium = !empty($_SESSION['subscription_active']) || !empty($_SESSION['premium_access']) || !empty($_SESSION['is_premium']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Task Management</title>
      <link rel="stylesheet" href="css/main.css?v=3.26.0">
    <link rel="icon" type="image/svg+xml" href="images/favicon.svg">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css" integrity="Evv84Mr4kqVGRNSgIGL/F/aIDqQb7xQ2vcrdIwxfjThSH8CSR7PBEakCr51Ck+w+/U6swU2Im1vVX0SVk9ABhg==" crossorigin="anonymous" referrerpolicy="no-referrer">
    <style>
        .task-form {
            padding: 20px;
            max-width: 900px;
            margin: 0 auto;
        }
        .task-list {
            padding: 0 20px;
            max-width: 100%;
            margin: 0 auto 24px;
        }
        .routine-section { background: #fff; border-radius: 10px; box-shadow: 0 2px 6px rgba(0,0,0,0.08); padding: 20px; margin-bottom: 24px; }
        .routine-section-header { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; }
        .routine-section-header h2 { margin: 0; font-size: 1.2rem; letter-spacing: 0.02em; }
        .form-grid { display: grid; gap: 16px; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); }
        .form-group { display: flex; flex-direction: column; gap: 6px; }
        .form-group label { font-weight: 600; }
        .form-group input, .form-group select, .form-group textarea { padding: 8px; border: 1px solid #ccc; border-radius: 6px; font-size: 0.95rem; }
        .form-actions { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 12px; }
        .repeat-group { grid-column: 1 / -1; }
        .repeat-days { display: none; }
        .repeat-days-label { font-weight: 600; padding: 15px 0; }
        .repeat-days-grid { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; }
        .repeat-day { position: relative; cursor: pointer; }
        .repeat-day input { position: absolute; opacity: 0; width: 0; height: 0; }
        .repeat-day span { width: 36px; height: 36px; border-radius: 50%; background: #ededed; color: #8e8e8e; display: inline-flex; align-items: center; justify-content: center; font-weight: 600; font-size: 0.85rem; transition: background 150ms ease, color 150ms ease; }
        .repeat-day input:checked + span { background: #46a0f4; color: #f9f9f9; }
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
            padding: 10px;
            margin: 5px 0;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-align: left;
        }
        .completed-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background-color: #4caf50;
            border-radius: 50px;
            color: #fff;
            padding: 0px;
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
        .button.secondary { background: #619fd0; }
        .button.danger { background: #e53935; }
        /* Task card layout aligned to parent mockups */
        .task-card {
            margin-bottom: 20px;
            border: 1px solid #e3e7eb;
            border-radius: 18px;
            background: #fff;
            box-shadow: 0 6px 16px rgba(0,0,0,0.08);
            overflow: hidden;
        }
        .task-card[open] { box-shadow: 0 10px 24px rgba(0,0,0,0.12); }
        .task-card summary { list-style: none; cursor: pointer; }
        .task-card summary::-webkit-details-marker { display: none !important; }
        .task-card summary::marker { content: '' !important; }
        .task-card summary { list-style: none !important; }
        .task-card summary::-moz-list-bullet { list-style-type: none; }
        .task-card-summary { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 16px 18px; }
        .task-card-summary-left { display: flex; align-items: center; gap: 12px; min-width: 0; flex: 1; }
        .task-card-icon { width: 46px; height: 46px; border-radius: 14px; background: #f5f7fb; color: #4caf50; display: inline-flex; align-items: center; justify-content: center; font-size: 1.1rem; box-shadow: inset 0 0 0 1px #eef1f5; flex: 0 0 auto; }
        .task-card-title-block { min-width: 0; display: grid; gap: 4px; }
        .task-card-title-row { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .task-card-title { font-weight: 700; font-size: 1.05rem; color: #37474f; }
        .task-card-child-pill { background: #eef1f5; color: #607d8b; padding: 2px 8px; border-radius: 999px; font-size: 0.72rem; font-weight: 700; white-space: nowrap; }
        .task-card-subtitle { font-size: 0.9rem; color: #8a94a6; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .task-card-status { font-weight: 700; font-size: 0.72rem; padding: 2px 8px; border-radius: 999px; background: #fff3e0; color: #ef6c00; text-transform: uppercase; letter-spacing: 0.02em; }
        .task-card-status.is-overdue { background: #ffebee; color: #d32f2f; }
        .task-card-status.is-success { background: #e8f5e9; color: #2e7d32; }
        .task-card-status.is-muted { background: #eceff1; color: #607d8b; }
        .task-card-summary-right { display: flex; align-items: center; gap: 12px; }
        .task-card-points { display: inline-flex; align-items: center; gap: 6px; font-weight: 700; color: #f59e0b; background: #fffbeb; padding: 4px 10px; border-radius: 999px; }
        .task-card-points i { color: #f59e0b; }
        .task-card-chevron { color: #90a4ae; transition: transform 200ms ease; }
        .task-card[open] .task-card-chevron { transform: rotate(90deg); }
        .task-card-body { padding: 16px 18px 18px; display: grid; gap: 12px; border-top: 1px solid #eef1f5; }
        .task-card-note { background: #f7f9fc; border: 1px solid #eef1f5; border-radius: 12px; padding: 12px 14px; color: #546e7a; }
        .task-card-note.text { display: flex; align-items: flex-start; gap: 8px; }
        .task-card-footer { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 12px; align-items: center; }
        .task-card-primary { width: 100%; margin: 0; border-radius: 999px; padding: 12px 16px; font-weight: 700; }
        .task-card-menu { position: relative; }
        .task-card-menu-toggle { width: 42px; height: 42px; border-radius: 14px; border: 1px solid #e0e0e0; background: #f5f7fb; color: #546e7a; display: inline-flex; align-items: center; justify-content: center; cursor: pointer; }
        .task-card-menu-list { position: absolute; bottom: calc(100% + 10px); right: 0; background: #fff; border: 1px solid #e6ebf1; border-radius: 12px; box-shadow: 0 10px 20px rgba(0,0,0,0.12); padding: 8px; display: none; min-width: 190px; z-index: 10; }
        .task-card-menu.open .task-card-menu-list { display: grid; gap: 4px; }
        .task-card-menu-item { width: 100%; border: none; background: transparent; padding: 8px 10px; border-radius: 10px; text-align: left; font-weight: 600; color: #455a64; cursor: pointer; display: flex; align-items: center; gap: 8px; }
        .task-card-menu-item:hover { background: #f5f7fb; }
        .task-card-menu-item.danger { color: #d32f2f; }
        .task-meta { display: grid; gap: 8px; color: #455a64; font-size: 0.95rem; background: #f7f9fc; border: 1px solid #eef1f5; border-radius: 12px; padding: 12px 14px; }
        .task-meta-row { display: flex; flex-wrap: wrap; gap: 10px; }
        .task-meta-row > span { display: flex; align-items: center; gap: 6px; }
        .task-meta-label { font-weight: 600; color: #37474f; display: inline-flex; align-items: center; gap: 6px; }
        .task-description { margin-top: 0; color: #546e7a; text-align: left; }
        .task-description.text { display: flex; align-items: flex-start; gap: 8px; }
        .task-desc-icon { color: #919191; font-size: 0.95rem; margin-top: 2px; }
        .task-meta-icon { color: #919191; }
        .task-meta-avatar { width: 20px; height: 20px; border-radius: 50%; object-fit: cover; border: 1px solid #e0e0e0; margin-right: 6px; }
        .calendar-task-meta .task-meta-icon { color: #919191; margin-right: 6px; }
        .calendar-task-child { display: inline-flex; align-items: center; gap: 6px; font-size: 0.8rem; color: #455a64; font-weight: 600; }
        .calendar-task-child-avatar { width: 20px; height: 20px; border-radius: 50%; object-fit: cover; border: 1px solid #e0e0e0; }
        .task-list-header { display: grid; grid-template-columns: 1fr auto; align-items: center; gap: 8px 16px; margin-bottom: 14px; text-align: left; }
        .task-list-subtitle { margin: 0; color: #7a869a; font-weight: 600; grid-column: 1; }
        .task-create-fab { grid-column: 2; grid-row: 1 / span 2; display: flex; justify-content: flex-end; padding: 0; margin: 0; position: static; }
        @media (max-width: 768px) {
            .task-create-fab { top: 16px; right: 16px; }
        }
        .task-list-title { margin: 0; font-size: 1.8rem; color: #263238; }
        .task-list-subtitle { margin: 0; color: #7a869a; font-weight: 600; }
        .task-list-subtitle strong { color: #0d47a1; }
        .task-filter-row { display: flex; flex-wrap: wrap; gap: 16px; align-items: center; margin-bottom: 18px; }
        .task-child-grid { display: flex; flex-wrap: wrap; gap: 14px; align-items: center; }
        .task-child-card { border: none; border-radius: 50%; padding: 0; background: transparent; display: grid; justify-items: center; gap: 6px; cursor: pointer; position: relative; text-decoration: none; color: #455a64; font-weight: 700; font-size: 0.75rem; }
        .task-child-card img,
        .task-child-icon { width: 52px; height: 52px; border-radius: 50%; object-fit: cover; box-shadow: 0 2px 6px rgba(0,0,0,0.15); display: inline-flex; align-items: center; justify-content: center; background: #f5f7fb; color: #607d8b; transition: box-shadow 150ms ease, transform 150ms ease; }
        .task-child-card.is-active img,
        .task-child-card.is-active .task-child-icon { box-shadow: 0 0 0 4px rgba(100,181,246,0.8), 0 0 14px rgba(100,181,246,0.8); transform: translateY(-2px); color: #0d47a1; }
        .task-child-card span { text-align: center; }
        .task-filter-pill.has-icon { display: inline-flex; align-items: center; gap: 8px; }
        .task-filter-form { display: grid; gap: 10px; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); margin-bottom: 18px; }
        .task-filter-field { display: grid; gap: 6px; }
        .task-filter-field label { font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: #90a4ae; }
        .task-filter-field select { padding: 8px 10px; border: 1px solid #d5def0; border-radius: 10px; font-size: 0.9rem; }
        .task-filter-actions { display: flex; align-items: flex-end; }
        .task-filter-actions .button { margin: 0; width: 100%; }
        .task-filter-toggle { border: 1px solid #d5def0; background: #fff; color: #607d8b; border-radius: 999px; padding: 8px 14px; font-weight: 700; font-size: 0.85rem; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; }
        .task-filter-form { display: none; }
        .task-filter-form.is-open { display: grid; }
        .task-reject-bar { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; justify-content: space-between; }
        .task-reject-actions { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
        .task-section-toggle { margin: 18px 0 10px; border-radius: 10px; padding: 0; background: transparent; box-shadow: none; overflow: hidden; }
        .task-section-toggle > summary { cursor: pointer; font-weight: 700; color: #37474f; display: flex; align-items: center; justify-content: space-between; gap: 10px; list-style: none; padding: 8px 4px; transition: color 150ms ease; }
        .task-section-toggle > summary:hover .task-section-title { color: #0d47a1; }
        .task-section-title { display: inline-flex; align-items: center; gap: 10px; font-size: 1.1rem; font-weight: 700; }
        .task-section-icon { width: 36px; height: 36px; border-radius: 12px; display: inline-flex; align-items: center; justify-content: center; font-size: 1rem; color: #fff; box-shadow: 0 6px 14px rgba(0,0,0,0.12); }
        .task-section-icon.is-active { background: #4caf50; }
        .task-section-icon.is-pending { background: #f59e0b; }
        .task-section-icon.is-approved { background: #22c55e; }
        .task-section-icon.is-expired { background: #ef4444; }
        .task-section-toggle > summary::-webkit-details-marker { display: none !important; }
        .task-section-toggle > summary::marker { content: '' !important; }
        .task-section-toggle > summary { list-style: none !important; }
        .task-section-toggle > summary::-moz-list-bullet { list-style-type: none; }
        .task-section-toggle > summary::after { content: '\f054'; font-family: 'Font Awesome 6 Free'; font-weight: 900; font-size: 0.85rem; color: #607d8b; transition: transform 200ms ease; }
        .task-section-toggle[open] > summary::after { transform: rotate(90deg); }
        .task-section-toggle[open] { border-color: transparent; box-shadow: none; }
        .task-section-content { overflow: hidden; max-height: 0; opacity: 0; transform: translateY(-6px); transition: max-height 280ms ease, opacity 200ms ease, transform 200ms ease; margin-top: 15px; }
        .task-section-toggle[open] .task-section-content { max-height: 12000px; opacity: 1; transform: translateY(0); }
        .task-approved-view-more { display: flex; justify-content: center; margin: 12px 0 4px; }
        .task-count-badge { background: #ff6f61; color: #fff; border-radius: 12px; padding: 2px 8px; font-size: 0.8rem; font-weight: 700; min-width: 24px; text-align: center; }
        .task-calendar-section { width: 100%; max-width: 100%; margin: 0 auto 24px; padding: 0 20px; }
        .task-calendar-card { background: #fff; border-radius: 14px; box-shadow: 0 4px 12px rgba(0,0,0,0.08); padding: 16px; display: grid; gap: 16px; }
        .calendar-header { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; }
        .calendar-header h2 { margin: 0; font-size: 1.2rem; }
        .calendar-subtitle { margin: 4px 0 0; color: #607d8b; font-size: 0.95rem; }
        .calendar-nav { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
        .calendar-nav-button { border: 1px solid #d5def0; background: #eef4ff; color: #0d47a1; font-weight: 700; border-radius: 999px; padding: 6px 12px; cursor: pointer; }
        .calendar-nav-button:hover { background: #dce8ff; }
        .calendar-nav-button[disabled] { opacity: 0.6; cursor: not-allowed; }
        .calendar-range { font-weight: 700; color: #37474f; }
        .calendar-premium-note { font-size: 0.85rem; color: #8d6e63; }
        .calendar-view-toggle { display: inline-flex; align-items: center; gap: 6px; padding: 4px; border-radius: 999px; border: 1px solid #d5def0; background: #f5f7fb; }
        .calendar-view-button { width: 36px; height: 36px; border: none; border-radius: 50%; background: transparent; color: #607d8b; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; }
        .calendar-view-button.active { background: #0d47a1; color: #fff; box-shadow: 0 4px 10px rgba(13, 71, 161, 0.2); }
        .task-week-calendar { border: 1px solid #d5def0; border-radius: 12px; background: #fff; overflow: hidden; position: relative; }
        .task-week-scroll { overflow-x: auto; }
        .week-days { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 6px; }
        .week-day-name { font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.04em; }
        .week-day-num { font-size: 1rem; }
        .week-days-header { background: #f5f7fb; padding: 8px; min-width: 980px; }
        .week-days-header .week-day { background: #fff; border: 1px solid #d5def0; border-radius: 10px; padding: 6px 0; display: grid; gap: 2px; justify-items: center; font-weight: 700; color: #37474f; font-family: inherit; }
        .child-theme .week-days-header .week-day { font-family: 'Sigmar One', 'Sigma One', cursive; }
        .week-days-header .week-day.is-today { background: #ffe0b2; border-color: #ffd28a; color: #ef6c00; }
        .week-grid { display: grid; grid-template-columns: repeat(7, minmax(133px, 1fr)); gap: 6px; background: #f5f7fb; padding: 6px 8px 10px; min-width: 980px; }
        .week-column { background: #fff; border: 1px solid #d5def0; border-radius: 10px; padding: 8px; display: flex; flex-direction: column; gap: 8px; min-height: 140px; }
        .week-column-tasks { display: grid; gap: 8px; }
        .task-week-calendar.is-hidden { display: none; }
        .task-week-list { display: none; border: 1px solid #d5def0; border-radius: 12px; background: #fff; padding: 12px; }
        .task-week-list.active { display: grid; gap: 12px; }
        .week-list-day { border: 1px solid #d5def0; border-radius: 12px; padding: 12px; background: #fdfdfd; display: grid; gap: 10px; }
        .week-list-day.is-today { border-color: #ffd28a; background: #ffe0b2; }
        .week-list-day.is-today .week-list-day-name,
        .week-list-day.is-today .week-list-day-date { color: #ef6c00; }
        .week-list-day-header { display: flex; align-items: center; justify-content: space-between; gap: 10px; font-weight: 700; color: #37474f; }
        .week-list-day-name { text-transform: uppercase; letter-spacing: 0.04em; font-size: 0.8rem; color: #607d8b; }
        .week-list-day-date { color: #0d47a1; }
        .week-list-sections { display: grid; gap: 10px; }
        .week-list-section-title { font-weight: 700; color: #37474f; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.04em; }
        .week-list-items { display: grid; gap: 8px; }
        .week-list-empty { color: #9e9e9e; font-size: 0.9rem; text-align: center; }
        .calendar-section { display: grid; gap: 6px; }
        .calendar-section-title { font-weight: 700; color: #37474f; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.04em; }
        .calendar-task-item { border: 1px solid #ffd28a; background: #fff7e6; border-radius: 10px; padding: 8px; text-align: left; cursor: pointer; display: grid; gap: 4px; font-size: 0.9rem; }
        .calendar-task-item:hover { background: #ffe9c6; }
        .child-theme .calendar-task-item { font-family: inherit; }
        .calendar-task-header { display: flex; flex-direction: column; align-items: flex-start; gap: 6px; }
        .task-week-list .calendar-task-header { flex-direction: row; align-items: center; flex-wrap: wrap; }
        .calendar-task-title-wrap { display: inline-flex; align-items: center; gap: 6px; flex: 1; min-width: 0; }
        .calendar-task-badge { display: inline-flex; align-items: center; gap: 4px; width: fit-content; padding: 2px 8px; border-radius: 999px; font-size: 0.7rem; font-weight: 700; letter-spacing: 0.02em; text-transform: uppercase; }
        .calendar-task-badge.overdue { background: #d9534f; color: #fff; }
        .calendar-task-badge.completed { background: #4caf50; color: #fff; }
        .calendar-task-badge.compact { justify-content: center; width: 20px; height: 20px; padding: 0; border-radius: 50%; font-size: 0.65rem; }
        .calendar-task-badge-group { display: inline-flex; align-items: center; gap: 5px; }
        .calendar-task-title { font-weight: 700; color: #3e2723; }
        .calendar-task-points { color: #f59e0b; font-size: 0.7rem; font-weight: 700; border-radius: 999px; background: #fffbeb; padding: 4px 10px; display: inline-flex; align-items: center; gap: 6px; }
        .calendar-task-points::before { content: '\f51e'; font-family: 'Font Awesome 6 Free'; font-weight: 900; }
        .child-theme .task-card-points { background: #fffbeb; color: #f59e0b; padding: 4px 10px; border-radius: 999px; }
        .child-theme .task-card-points i { color: #f59e0b; }
        .child-theme .calendar-task-points { background: #fffbeb; color: #f59e0b; padding: 4px 10px; border-radius: 999px; display: inline-flex; align-items: center; gap: 6px; }
        .child-theme .calendar-task-points::before { content: '\f51e'; font-family: 'Font Awesome 6 Free'; font-weight: 900; }
        .calendar-task-meta { color: #919191; font-size: 0.85rem; }
        .calendar-task-child { }
        .calendar-day-empty { color: #9e9e9e; font-size: 0.85rem; text-align: center; padding: 8px 0; }
        .calendar-empty { display: none; text-align: center; color: #9e9e9e; font-weight: 600; padding: 18px; }
        .calendar-empty.active { display: block; }
        /* Overdue styles (role-specific colors for autism-friendliness) */
        .overdue { }
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
        .task-modal[data-task-delete-modal] { z-index: 4200; }
        .task-modal[data-task-photo-modal] { z-index: 4300; }
        .task-modal-card { background: #fff; border-radius: 12px; max-width: 760px; width: min(760px, 100%); max-height: 85vh; overflow: hidden; box-shadow: 0 12px 32px rgba(0,0,0,0.25); display: grid; grid-template-rows: auto 1fr; }
        .task-create-fab { position: sticky; top: 0; z-index: 5; display: flex; justify-content: flex-end; padding: 10px 20px; margin: 10px 0 0; }
        .task-create-button { width: 52px; height: 52px; border-radius: 50%; border: none; background: #4caf50; color: #fff; display: inline-flex; align-items: center; justify-content: center; font-size: 1.4rem; cursor: pointer; box-shadow: 0 6px 14px rgba(76, 175, 80, 0.35); }
        .task-create-button:hover { background: #43a047; }
        .task-create-modal { position: fixed; inset: 0; background: rgba(0,0,0,0.45); display: none; align-items: center; justify-content: center; z-index: 4100; padding: 14px; }
        .task-create-modal.open { display: flex; }
        .task-create-card { background: #fff; border-radius: 14px; max-width: 860px; width: min(860px, 100%); max-height: 90vh; overflow: hidden; box-shadow: 0 12px 32px rgba(0,0,0,0.25); display: grid; grid-template-rows: auto 1fr; }
        .task-create-card header { display: flex; align-items: center; justify-content: space-between; padding: 12px 16px; border-bottom: 1px solid #e0e0e0; }
        .task-create-body { padding: 12px 16px 18px; overflow-y: auto; }
        .task-photo-thumb { width: 56px; height: 56px; border-radius: 10px; object-fit: cover; border: 1px solid #d5def0; box-shadow: 0 2px 6px rgba(0,0,0,0.12); cursor: pointer; }
        .task-photo-proof { display: flex; align-items: center; gap: 10px; margin-top: 8px; }
        .task-photo-proof-label { display: flex; flex-direction: column; align-items: center; font-size: 0.95rem; color: #6d6d6d; min-width: 72px; }
        .task-photo-proof-label .task-meta-icon { color: #919191; }
        .task-photo-proof-label span { text-align: center; }
        .task-photo-preview { width: 100%; max-height: 70vh; object-fit: contain; border-radius: 10px; }
        .no-scroll { overflow: hidden; }
        .task-modal-card header { display: flex; align-items: center; justify-content: space-between; padding: 12px 16px; border-bottom: 1px solid #e0e0e0; }
        .task-modal-card h2 { margin: 0; font-size: 1.1rem; }
        .task-modal-close { background: transparent; border: none; font-size: 1.3rem; cursor: pointer; color: #555; }
        .task-modal-body { padding: 12px 16px 16px; overflow-y: auto; }
        .help-modal { position: fixed; inset: 0; background: rgba(0,0,0,0.45); display: none; align-items: center; justify-content: center; z-index: 4300; padding: 14px; }
        .help-modal.open { display: flex; }
        .help-card { background: #fff; border-radius: 12px; max-width: 720px; width: min(720px, 100%); max-height: 85vh; overflow: hidden; box-shadow: 0 12px 32px rgba(0,0,0,0.25); display: grid; grid-template-rows: auto 1fr; }
        .help-card header { display: flex; align-items: center; justify-content: space-between; padding: 12px 16px; border-bottom: 1px solid #e0e0e0; }
        .help-card h2 { margin: 0; font-size: 1.1rem; }
        .help-close { background: transparent; border: none; font-size: 1.3rem; cursor: pointer; color: #555; }
        .help-body { padding: 12px 16px 16px; overflow-y: auto; display: grid; gap: 12px; }
        .help-section h3 { margin: 0 0 6px; font-size: 1rem; color: #37474f; }
        .help-section ul { margin: 0; padding-left: 18px; display: grid; gap: 6px; color: #455a64; }
        .icon-button { width: 36px; height: 36px;     border: none;
         background-color: rgba(0, 0, 0, 0.0);
         color: #9f9f9f; /*border-radius: 50%; border: 1px solid #d5def0; background: #f5f5f5; color: #757575; display: inline-flex; align-items: center; justify-content: center;*/ cursor: pointer; }
        .icon-button.danger { border: none;
         background-color: rgba(0, 0, 0, 0.0);
         color: #9f9f9f; /*background: #f5f5f5; border-color: #d5def0; color: #757575;*/ }
        .task-reject-form { margin-top: 12px; display: grid; gap: 8px; }
        .task-reject-form textarea { width: 100%; min-height: 70px; resize: vertical; }
        .task-reject-actions { display: flex; flex-wrap: wrap; gap: 8px; justify-content: flex-end; }
        .modal-actions { display: flex; flex-wrap: wrap; gap: 12px; justify-content: flex-end; }
        .timer-controls {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 8px;
            position: relative;
        }
        .timer-button {
            padding: 10px 20px;
            background-color: #2196f3;
            color: #fff;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            user-select: none;
            -webkit-user-select: none;
            -ms-user-select: none;
            -webkit-touch-callout: none;
            touch-action: manipulation;
        }
        .timer-cancel-button {
            padding: 10px 20px;
            background-color: #f44336;
            color: #fff;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            display: none;
            user-select: none;
            -webkit-user-select: none;
            -ms-user-select: none;
            -webkit-touch-callout: none;
            touch-action: manipulation;
        }
        .floating-task-timer {
            position: fixed;
            top: 16px;
            right: 16px;
            z-index: 5000;
            width: min(320px, 92vw);
            background: #fff;
            border: 1px solid #d5def0;
            border-radius: 14px;
            box-shadow: 0 12px 26px rgba(0,0,0,0.18);
            padding: 12px;
            display: none;
            gap: 10px;
        }
        .floating-task-timer.active { display: grid; }
        .floating-task-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; }
        .floating-task-title { font-weight: 700; color: #37474f; }
        .floating-task-points {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            width: fit-content;
            margin-top: 4px;
            padding: 4px 10px;
            border-radius: 999px;
            background: #fffbeb;
            color: #f59e0b;
            font-weight: 700;
            font-size: 0.82rem;
            white-space: nowrap;
        }
        .floating-task-points::before {
            content: '\f51e';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
        }
        .floating-task-header-actions { display: flex; gap: 6px; }
        .floating-task-icon {
            border: none;
            background: transparent;
            color: #607d8b;
            cursor: pointer;
            width: 32px;
            height: 32px;
            border-radius: 50%;
        }
        .floating-task-icon:hover { color: #455a64; background: rgba(0,0,0,0.04); }
        .floating-task-time { font-weight: 700; font-size: 1rem; color: #263238; }
        .floating-task-actions { display: flex; align-items: center; gap: 10px; justify-content: flex-end; flex-wrap: wrap; }
        .floating-task-pause {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            border: none;
            background: #2196f3;
            color: #fff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
        }
        .floating-task-pause:hover { background: #1976d2; }
        .pause-hold-countdown {
            display: none;
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            min-width: 40px;
            padding: 8px 12px;
            border-radius: 10px;
            background: rgba(0, 0, 0, 0.7);
            color: #fff;
            font-weight: 700;
            font-size: 1rem;
            text-align: center;
            z-index: 2;
            pointer-events: none;
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
    </style>
    <script>
        const taskCalendarData = <?php echo json_encode($calendarTasks, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        const taskCalendarPremium = <?php echo $calendarPremium ? 'true' : 'false'; ?>;
        const isParentView = <?php echo canCreateContent($_SESSION['user_id']) ? 'true' : 'false'; ?>;
        const canManageTasks = <?php echo (canCreateContent($_SESSION['user_id']) && canAddEditChild($_SESSION['user_id'])) ? 'true' : 'false'; ?>;
        const taskTimers = {};
        let taskCalendarMap = new Map();
        let activePreviewTaskId = null;
        let floatingTaskId = null;
        let floatingTimerEl = null;
        let floatingTitleEl = null;
        let floatingPointsEl = null;
        let floatingOpenBtn = null;
        let floatingCloseBtn = null;
        let floatingFinishBtn = null;
        let openTaskPreview = null;
        let openEditTaskModal = null;
        let openDeleteTaskModal = null;
        let openCreateTaskModal = null;
        let openProofTaskModal = null;
        let openPhotoViewer = null;
        let activePreviewDateKey = null;
        let floatingTaskDateKey = null;

        const updateTimerField = (wrapper, selectEl) => {
            if (!wrapper || !selectEl) return;
            const show = selectEl.value === 'timer';
            wrapper.style.display = show ? 'block' : 'none';
            const input = wrapper.querySelector('input');
            if (input) {
                input.required = show;
                input.disabled = !show;
                if (!show) {
                    input.value = '';
                }
            }
        };
        const updateRepeatDays = (wrapper, selectEl) => {
            if (!wrapper || !selectEl) return;
            const show = selectEl.value === 'weekly';
            wrapper.style.display = show ? 'block' : 'none';
        };
        const updateOnceDateVisibility = (wrapper, selectEl) => {
            if (!wrapper || !selectEl) return;
            const show = selectEl.value === '';
            wrapper.style.display = show ? 'block' : 'none';
            const input = wrapper.querySelector('input[type="date"]');
            if (input) {
                input.required = show;
            }
        };
        const updateDueTimeVisibility = (wrapper, selectEl) => {
            if (!wrapper || !selectEl) return;
            const show = selectEl.value !== 'anytime';
            wrapper.style.display = show ? 'block' : 'none';
            if (!show) {
                const input = wrapper.querySelector('input[type="time"]');
                if (input) {
                    input.value = '';
                }
            }
        };
        const updateEndDate = (wrapper, toggle) => {
            if (!wrapper || !toggle) return;
            wrapper.style.display = toggle.checked ? 'block' : 'none';
        };
        const updateEndToggleVisibility = (toggleField, toggle, endWrapper, selectEl) => {
            if (!toggleField || !toggle || !selectEl) return;
            const showToggle = selectEl.value !== '';
            toggleField.style.display = showToggle ? 'flex' : 'none';
            if (!showToggle) {
                toggle.checked = false;
                updateEndDate(endWrapper, toggle);
            }
        };

        document.addEventListener('DOMContentLoaded', () => {
        bindTimerControls(document);

        const editButtons = document.querySelectorAll('[data-task-edit-open]');
        const deleteButtons = document.querySelectorAll('[data-task-delete-open]');
        const modal = document.querySelector('[data-task-edit-modal]');
        const modalCloses = modal ? modal.querySelectorAll('[data-task-edit-close]') : [];
        const modalForm = modal ? modal.querySelector('[data-task-edit-form]') : null;
        const deleteModal = document.querySelector('[data-task-delete-modal]');
        const deleteCloses = deleteModal ? deleteModal.querySelectorAll('[data-task-delete-close]') : [];
        const deleteForm = deleteModal ? deleteModal.querySelector('[data-task-delete-form]') : null;
        const deleteCopy = deleteModal ? deleteModal.querySelector('[data-task-delete-copy]') : null;
        const proofButtons = document.querySelectorAll('[data-task-proof-open]');
        const proofModal = document.querySelector('[data-task-proof-modal]');
        const proofCloses = proofModal ? proofModal.querySelectorAll('[data-task-proof-close]') : [];
        const proofForm = proofModal ? proofModal.querySelector('[data-task-proof-form]') : null;
        const proofFileInput = proofModal ? proofModal.querySelector('input[name="photo_proof"]') : null;
        const photoThumbs = document.querySelectorAll('[data-task-photo-src]');
        const photoModal = document.querySelector('[data-task-photo-modal]');
        const photoCloses = photoModal ? photoModal.querySelectorAll('[data-task-photo-close]') : [];
        const photoPreview = photoModal ? photoModal.querySelector('[data-task-photo-preview]') : null;
        const approvedSection = document.querySelector('[data-approved-section]');
        const approvedViewMore = approvedSection ? approvedSection.querySelector('[data-approved-view-more]') : null;
        floatingTimerEl = document.querySelector('[data-floating-timer]');
        floatingTitleEl = floatingTimerEl ? floatingTimerEl.querySelector('[data-floating-title]') : null;
        floatingPointsEl = floatingTimerEl ? floatingTimerEl.querySelector('[data-floating-points]') : null;
        floatingOpenBtn = floatingTimerEl ? floatingTimerEl.querySelector('[data-floating-open]') : null;
        floatingCloseBtn = floatingTimerEl ? floatingTimerEl.querySelector('[data-floating-close]') : null;
        floatingFinishBtn = floatingTimerEl ? floatingTimerEl.querySelector('[data-floating-finish]') : null;
        const filterToggle = document.querySelector('[data-filter-toggle]');
        const filterForm = document.querySelector('[data-filter-form]');

        const closeTaskMenus = () => {
            document.querySelectorAll('[data-task-menu].open').forEach((menu) => {
                menu.classList.remove('open');
            });
        };
        document.addEventListener('click', (event) => {
            const toggle = event.target.closest('[data-task-menu-toggle]');
            const menu = event.target.closest('[data-task-menu]');
            const menuItem = event.target.closest('.task-card-menu-item');
            if (toggle) {
                event.preventDefault();
                const menuRoot = toggle.closest('[data-task-menu]');
                if (!menuRoot) return;
                const isOpen = menuRoot.classList.contains('open');
                closeTaskMenus();
                if (!isOpen) {
                    menuRoot.classList.add('open');
                }
                return;
            }
            if (menuItem) {
                closeTaskMenus();
                return;
            }
            if (!menu) {
                closeTaskMenus();
            }
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                closeTaskMenus();
            }
        });
        if (filterToggle && filterForm) {
            const storageKey = 'taskFiltersOpen';
            const storedValue = localStorage.getItem(storageKey);
            const shouldOpen = storedValue === 'true';
            filterForm.classList.toggle('is-open', shouldOpen);
            filterToggle.setAttribute('aria-expanded', shouldOpen ? 'true' : 'false');
            if (storedValue === null) {
                localStorage.setItem(storageKey, 'false');
            }
            filterToggle.addEventListener('click', () => {
                const isOpen = filterForm.classList.toggle('is-open');
                filterToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
                localStorage.setItem(storageKey, isOpen ? 'true' : 'false');
            });
        }

        const openModal = (data) => {
            if (!modal || !modalForm) return;
            const previewModalEl = document.querySelector('[data-task-preview-modal]');
            if (previewModalEl && previewModalEl.classList.contains('open')) {
                previewModalEl.classList.remove('open');
            }
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
            modalForm.querySelector('[name="time_of_day"]').value = data.timeOfDay || 'anytime';
            modalForm.querySelector('[name="category"]').value = data.category || 'household';
            modalForm.querySelector('[name="timing_mode"]').value = data.timingMode || 'no_limit';
            modalForm.querySelector('[name="timer_minutes"]').value = data.timerMinutes || '';
            const photoToggle = modalForm.querySelector('[name="photo_proof_required"]');
            if (photoToggle) {
                photoToggle.checked = !!data.photoRequired;
            }
            updateTimerField(
                modalForm.querySelector('[data-timer-minutes-wrapper]'),
                modalForm.querySelector('[name="timing_mode"]')
            );
            updateRepeatDays(
                modalForm.querySelector('[data-recurrence-days-wrapper]'),
                modalForm.querySelector('[name="recurrence"]')
            );
            updateEndToggleVisibility(
                modalForm.querySelector('[data-end-toggle-field]'),
                modalForm.querySelector('[data-end-date-toggle]'),
                modalForm.querySelector('[data-end-date-wrapper]'),
                modalForm.querySelector('[name="recurrence"]')
            );
            updateOnceDateVisibility(
                modalForm.querySelector('[data-once-date-wrapper]'),
                modalForm.querySelector('[name="recurrence"]')
            );
            updateEndDate(
                modalForm.querySelector('[data-end-date-wrapper]'),
                modalForm.querySelector('[name="end_date_enabled"]')
            );
            updateDueTimeVisibility(
                modalForm.querySelector('[data-due-time-wrapper]'),
                modalForm.querySelector('[name="time_of_day"]')
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
            const previewModalEl = document.querySelector('[data-task-preview-modal]');
            if (previewModalEl && previewModalEl.classList.contains('open')) {
                previewModalEl.classList.remove('open');
            }
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
        const openProofModal = (data) => {
            if (!proofModal || !proofForm) return;
            const previewModalEl = document.querySelector('[data-task-preview-modal]');
            if (previewModalEl && previewModalEl.classList.contains('open')) {
                previewModalEl.classList.remove('open');
            }
            proofForm.querySelector('[name="task_id"]').value = data.id;
            const instanceInput = proofForm.querySelector('[name="instance_date"]');
            if (instanceInput) {
                instanceInput.value = data.dateKey || '';
            }
            if (proofFileInput) {
                proofFileInput.value = '';
            }
            proofModal.classList.add('open');
            document.body.classList.add('no-scroll');
        };
        const closeProofModal = () => {
            if (!proofModal) return;
            proofModal.classList.remove('open');
            document.body.classList.remove('no-scroll');
        };
        const openPhotoModal = (src) => {
            if (!photoModal || !photoPreview) return;
            photoPreview.src = src;
            photoModal.classList.add('open');
            document.body.classList.add('no-scroll');
        };
        const closePhotoModal = () => {
            if (!photoModal) return;
            photoModal.classList.remove('open');
            document.body.classList.remove('no-scroll');
            if (photoPreview) {
                photoPreview.src = '';
            }
        };
        openEditTaskModal = openModal;
        openDeleteTaskModal = openDeleteModal;
        openProofTaskModal = openProofModal;
        openPhotoViewer = openPhotoModal;

        if (approvedSection && approvedViewMore && isParentView) {
            const approvedCards = Array.from(approvedSection.querySelectorAll('[data-approved-card]'));
            const step = parseInt(approvedViewMore.getAttribute('data-approved-step'), 10) || 5;
            let visibleCount = approvedCards.filter((card) => card.style.display !== 'none').length || step;
            const setButtonLabel = (allVisible) => {
                approvedViewMore.textContent = allVisible ? 'View less' : 'View more';
            };
            const applyVisibility = (count) => {
                approvedCards.forEach((card, index) => {
                    card.style.display = index < count ? '' : 'none';
                });
                visibleCount = count;
            };
            const updateViewMore = () => {
                if (approvedCards.length <= step) {
                    approvedViewMore.style.display = 'none';
                    return;
                }
                approvedViewMore.style.display = '';
                setButtonLabel(visibleCount >= approvedCards.length);
            };
            updateViewMore();
            approvedViewMore.addEventListener('click', () => {
                if (visibleCount >= approvedCards.length) {
                    applyVisibility(step);
                } else {
                    const nextCount = Math.min(approvedCards.length, visibleCount + step);
                    applyVisibility(nextCount);
                }
                updateViewMore();
            });
        }
        if (floatingTimerEl) {
            if (floatingOpenBtn) {
                floatingOpenBtn.addEventListener('click', () => {
                    if (floatingTaskId && openTaskPreview) {
                        openTaskPreview(floatingTaskId, floatingTaskDateKey);
                        hideFloatingTimer(floatingTaskId);
                    }
                });
            }
            if (floatingFinishBtn) {
                floatingFinishBtn.addEventListener('click', () => {
                    if (floatingTaskId && openTaskPreview) {
                        openTaskPreview(floatingTaskId, floatingTaskDateKey);
                        hideFloatingTimer(floatingTaskId);
                    }
                });
            }
            if (floatingCloseBtn) {
                floatingCloseBtn.addEventListener('click', () => hideFloatingTimer(floatingTaskId));
            }
        }

        if (modal) {
            bindTaskEditDeleteButtons(document);
            if (modalCloses.length) {
                modalCloses.forEach((btn) => btn.addEventListener('click', closeModal));
            }
            modal.addEventListener('click', (e) => { if (e.target === modal) closeModal(); });
            document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeModal(); });
        }
        const createForm = document.querySelector('[data-create-task-form]');
        const createTimingSelect = createForm ? createForm.querySelector('[name="timing_mode"]') : null;
        const createTimerWrapper = createForm ? createForm.querySelector('[data-create-timer-minutes]') : null;
        const createRepeatSelect = createForm ? createForm.querySelector('[name="recurrence"]') : null;
        const createRepeatWrapper = createForm ? createForm.querySelector('[data-create-recurrence-days]') : null;
        const createOnceDateWrapper = createForm ? createForm.querySelector('[data-once-date-wrapper]') : null;
        const createEndToggle = createForm ? createForm.querySelector('[data-end-date-toggle]') : null;
        const createEndWrapper = createForm ? createForm.querySelector('[data-create-end-date]') : null;
        const createEndToggleField = createForm ? createForm.querySelector('[data-create-end-toggle]') : null;
        const createTimeOfDay = createForm ? createForm.querySelector('[name="time_of_day"]') : null;
        const createDueTimeWrapper = createForm ? createForm.querySelector('[data-due-time-wrapper]') : null;
        if (createTimingSelect && createTimerWrapper) {
            updateTimerField(createTimerWrapper, createTimingSelect);
            createTimingSelect.addEventListener('change', () => updateTimerField(createTimerWrapper, createTimingSelect));
        }
        if (createRepeatSelect && createRepeatWrapper) {
            updateRepeatDays(createRepeatWrapper, createRepeatSelect);
            createRepeatSelect.addEventListener('change', () => updateRepeatDays(createRepeatWrapper, createRepeatSelect));
        }
        if (createRepeatSelect && createOnceDateWrapper) {
            updateOnceDateVisibility(createOnceDateWrapper, createRepeatSelect);
            createRepeatSelect.addEventListener('change', () => updateOnceDateVisibility(createOnceDateWrapper, createRepeatSelect));
        }
        if (createTimeOfDay && createDueTimeWrapper) {
            updateDueTimeVisibility(createDueTimeWrapper, createTimeOfDay);
            createTimeOfDay.addEventListener('change', () => updateDueTimeVisibility(createDueTimeWrapper, createTimeOfDay));
        }
        if (createRepeatSelect && createEndToggleField && createEndToggle) {
            updateEndToggleVisibility(createEndToggleField, createEndToggle, createEndWrapper, createRepeatSelect);
            createRepeatSelect.addEventListener('change', () => updateEndToggleVisibility(createEndToggleField, createEndToggle, createEndWrapper, createRepeatSelect));
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
            const modalEndToggleField = modalForm.querySelector('[data-end-toggle-field]');
            const modalEndToggle = modalForm.querySelector('[data-end-date-toggle]');
            const modalEndWrapper = modalForm.querySelector('[data-end-date-wrapper]');
            if (modalRepeat && modalEndToggleField && modalEndToggle && modalEndWrapper) {
                modalRepeat.addEventListener('change', () => updateEndToggleVisibility(modalEndToggleField, modalEndToggle, modalEndWrapper, modalRepeat));
                updateEndToggleVisibility(modalEndToggleField, modalEndToggle, modalEndWrapper, modalRepeat);
            }
            const modalOnceWrapper = modalForm.querySelector('[data-once-date-wrapper]');
            if (modalRepeat && modalOnceWrapper) {
                modalRepeat.addEventListener('change', () => updateOnceDateVisibility(modalOnceWrapper, modalRepeat));
                updateOnceDateVisibility(modalOnceWrapper, modalRepeat);
            }
            if (modalEndToggle && modalEndWrapper) {
                modalEndToggle.addEventListener('change', () => updateEndDate(modalEndWrapper, modalEndToggle));
            }
            const modalTimeOfDay = modalForm.querySelector('[name="time_of_day"]');
            const modalDueTimeWrapper = modalForm.querySelector('[data-due-time-wrapper]');
            if (modalTimeOfDay && modalDueTimeWrapper) {
                modalTimeOfDay.addEventListener('change', () => updateDueTimeVisibility(modalDueTimeWrapper, modalTimeOfDay));
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
        if (proofModal) {
            bindTaskProofButtons(document);
            if (proofCloses.length) {
                proofCloses.forEach((btn) => btn.addEventListener('click', closeProofModal));
            }
            proofModal.addEventListener('click', (e) => { if (e.target === proofModal) closeProofModal(); });
            document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeProofModal(); });
        }
        if (photoModal) {
            bindPhotoThumbs(document);
            if (photoCloses.length) {
                photoCloses.forEach((btn) => btn.addEventListener('click', closePhotoModal));
            }
            photoModal.addEventListener('click', (e) => { if (e.target === photoModal) closePhotoModal(); });
            document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closePhotoModal(); });
        }
        const helpOpen = document.querySelector('[data-help-open]');
        const helpModal = document.querySelector('[data-help-modal]');
        const helpClose = helpModal ? helpModal.querySelector('[data-help-close]') : null;
        const openHelp = () => {
            if (!helpModal) return;
            helpModal.classList.add('open');
            document.body.classList.add('no-scroll');
        };
        const closeHelp = () => {
            if (!helpModal) return;
            helpModal.classList.remove('open');
            document.body.classList.remove('no-scroll');
        };
        if (helpOpen && helpModal) {
            helpOpen.addEventListener('click', openHelp);
            if (helpClose) helpClose.addEventListener('click', closeHelp);
            helpModal.addEventListener('click', (e) => { if (e.target === helpModal) closeHelp(); });
            document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeHelp(); });
        }
        initTaskCalendar();
    });

        function getTaskTimerMinutes(taskId) {
            const task = taskCalendarMap.get(String(taskId));
            const minutes = parseInt(task?.timer_minutes, 10);
            return Number.isFinite(minutes) && minutes > 0 ? minutes : 0;
        }

        function ensureTimerState(taskId, limitMinutes) {
            if (!taskId) return null;
            const key = String(taskId);
            if (taskTimers[key]) return taskTimers[key];
            const minutes = parseInt(limitMinutes, 10) || getTaskTimerMinutes(key);
            if (!minutes) return null;
            const limitSeconds = minutes * 60;
            taskTimers[key] = {
                remaining: limitSeconds,
                initial: limitSeconds,
                intervalId: null,
                holdIntervalId: null,
                holdRemaining: 0,
                isRunning: false,
                ignoreNextClick: false,
                activePointerId: null,
                holdButton: null
            };
            updateTimerDisplay(key);
            syncTimerUI(key);
            return taskTimers[key];
        }

        function bindTimerControls(container) {
            if (!container) return;
            const holdStartEvents = ['pointerdown', 'touchstart', 'mousedown'];
            const holdEndEvents = ['pointerup', 'pointerleave', 'pointercancel', 'touchend', 'touchcancel', 'mouseup'];

            container.querySelectorAll('[data-timer-action="start"]').forEach((button) => {
                if (button.dataset.timerBound) return;
                const taskId = button.dataset.taskId;
                const limitMinutes = button.dataset.limit;
                const state = ensureTimerState(taskId, limitMinutes);
                if (!state) return;
                button.dataset.timerBound = '1';
                button.addEventListener('click', (event) => handleTimerClick(event, button.dataset.taskId));
                holdStartEvents.forEach((evt) => {
                    button.addEventListener(evt, (event) => beginHold(event, button.dataset.taskId), { passive: false });
                });
                holdEndEvents.forEach((evt) => {
                    button.addEventListener(evt, (event) => cancelHold(button.dataset.taskId, { event }));
                });
                updateTimerDisplay(taskId);
                syncTimerUI(taskId);
            });

            container.querySelectorAll('[data-timer-action="cancel"]').forEach((button) => {
                if (button.dataset.timerBound) return;
                const taskId = button.dataset.taskId;
                if (!ensureTimerState(taskId, button.dataset.limit)) return;
                button.dataset.timerBound = '1';
                button.addEventListener('click', () => cancelTimer(button.dataset.taskId));
            });

            container.querySelectorAll('[data-timer-action="pause-toggle"]').forEach((button) => {
                if (button.dataset.timerBound) return;
                const taskId = button.dataset.taskId;
                if (!ensureTimerState(taskId, button.dataset.limit)) return;
                button.dataset.timerBound = '1';
                button.addEventListener('click', () => toggleTimerPause(button.dataset.taskId));
            });
        }

        function updateTimerDisplay(taskId) {
            const state = taskTimers[String(taskId)];
            if (!state) return;
            const minutes = Math.floor(state.remaining / 60);
            const seconds = state.remaining % 60;
            const label = `${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
            document.querySelectorAll(`[data-timer-display][data-task-id="${taskId}"]`).forEach((el) => {
                el.textContent = label;
            });
        }

        function syncTimerUI(taskId) {
            const state = taskTimers[String(taskId)];
            if (!state) return;
            const startButtons = document.querySelectorAll(`[data-timer-action="start"][data-task-id="${taskId}"]`);
            const cancelButtons = document.querySelectorAll(`[data-timer-action="cancel"][data-task-id="${taskId}"]`);
            const pauseButtons = document.querySelectorAll(`[data-timer-action="pause-toggle"][data-task-id="${taskId}"]`);

            let startLabel = 'Start Timer';
            if (state.isRunning) {
                startLabel = 'Pause Timer';
            } else if (state.remaining <= 0) {
                startLabel = 'Restart';
            } else if (state.remaining < state.initial) {
                startLabel = 'Resume';
            }

            startButtons.forEach((button) => {
                button.textContent = startLabel;
            });

            const showCancel = !state.isRunning && state.remaining < state.initial;
            cancelButtons.forEach((button) => {
                button.style.display = showCancel ? 'inline-block' : 'none';
            });

            pauseButtons.forEach((button) => {
                const icon = button.querySelector('i');
                if (state.isRunning) {
                    if (icon) icon.className = 'fa-solid fa-pause';
                    button.setAttribute('aria-label', 'Pause timer');
                } else {
                    if (icon) icon.className = 'fa-solid fa-play';
                    button.setAttribute('aria-label', 'Resume timer');
                }
            });
        }

        function showFloatingTimer(taskId, dateKey = null) {
            if (!floatingTimerEl) return;
            const task = taskCalendarMap.get(String(taskId));
            if (!task) return;
            ensureTimerState(taskId, task.timer_minutes);
            floatingTaskId = String(taskId);
            floatingTaskDateKey = dateKey;
            if (floatingTitleEl) {
                floatingTitleEl.textContent = task.title || 'Task';
            }
            if (floatingPointsEl) {
                floatingPointsEl.textContent = `${task.points || 0}`;
            }
            floatingTimerEl.querySelectorAll('[data-timer-display]').forEach((el) => {
                el.dataset.taskId = String(taskId);
            });
            floatingTimerEl.querySelectorAll('[data-timer-action="pause-toggle"]').forEach((el) => {
                el.dataset.taskId = String(taskId);
            });
            floatingTimerEl.classList.add('active');
            bindTimerControls(floatingTimerEl);
            updateTimerDisplay(taskId);
            syncTimerUI(taskId);
        }

        function shouldShowFloatingTimer(taskId) {
            const state = taskTimers[String(taskId)];
            if (!state) return false;
            return state.remaining < state.initial && state.remaining > 0;
        }

        function hideFloatingTimer(taskId) {
            if (!floatingTimerEl) return;
            if (taskId && floatingTaskId && String(taskId) !== String(floatingTaskId)) return;
            floatingTimerEl.classList.remove('active');
            floatingTaskId = null;
            floatingTaskDateKey = null;
        }

        function toggleTimerPause(taskId) {
            const state = taskTimers[String(taskId)];
            if (!state) return;
            if (state.isRunning) {
                pauseTimer(taskId);
            } else {
                if (state.remaining <= 0) {
                    state.remaining = state.initial;
                    updateTimerDisplay(taskId);
                }
                startTimer(taskId);
            }
        }

        function bindTaskProofButtons(container) {
            if (!container || !openProofTaskModal) return;
            container.querySelectorAll('[data-task-proof-open]').forEach((btn) => {
                if (btn.dataset.taskProofBound) return;
                btn.dataset.taskProofBound = '1';
                btn.addEventListener('click', () => {
                    openProofTaskModal({
                        id: btn.dataset.taskId,
                        dateKey: btn.dataset.dateKey || null
                    });
                });
            });
        }

        function bindPhotoThumbs(container) {
            if (!container || !openPhotoViewer) return;
            container.querySelectorAll('[data-task-photo-src]').forEach((thumb) => {
                if (thumb.dataset.taskPhotoBound) return;
                thumb.dataset.taskPhotoBound = '1';
                thumb.addEventListener('click', () => {
                    const src = thumb.dataset.taskPhotoSrc;
                    if (src) {
                        openPhotoViewer(src);
                    }
                });
            });
        }

        function bindTaskEditDeleteButtons(container) {
            if (!container) return;
            container.querySelectorAll('[data-task-duplicate-open]').forEach((btn) => {
                if (btn.dataset.taskDuplicateBound) return;
                btn.dataset.taskDuplicateBound = '1';
                btn.addEventListener('click', () => {
                    if (!openCreateTaskModal) return;
                    openCreateTaskModal({
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
                        timeOfDay: btn.dataset.timeOfDay,
                        recurrenceDays: btn.dataset.recurrenceDays,
                        photoRequired: btn.dataset.photoRequired === '1'
                    });
                });
            });
            container.querySelectorAll('[data-task-edit-open]').forEach((btn) => {
                if (btn.dataset.taskEditBound) return;
                btn.dataset.taskEditBound = '1';
                btn.addEventListener('click', () => {
                    if (!openEditTaskModal) return;
                    openEditTaskModal({
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
                        timeOfDay: btn.dataset.timeOfDay,
                        recurrenceDays: btn.dataset.recurrenceDays,
                        photoRequired: btn.dataset.photoRequired === '1'
                    });
                });
            });

            container.querySelectorAll('[data-task-delete-open]').forEach((btn) => {
                if (btn.dataset.taskDeleteBound) return;
                btn.dataset.taskDeleteBound = '1';
                btn.addEventListener('click', () => {
                    if (!openDeleteTaskModal) return;
                    openDeleteTaskModal({
                        id: btn.dataset.taskId,
                        childName: btn.dataset.childName,
                        title: btn.dataset.title
                    });
                });
            });
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
            hideCountdown(taskId);
            syncTimerUI(taskId);

            clearInterval(state.intervalId);
            state.intervalId = setInterval(() => {
                state.remaining -= 1;
                if (state.remaining <= 0) {
                    state.remaining = 0;
                    updateTimerDisplay(taskId);
                    clearInterval(state.intervalId);
                    state.intervalId = null;
                    state.isRunning = false;
                    state.ignoreNextClick = false;
                    hideCountdown(taskId);
                    syncTimerUI(taskId);
                    hideFloatingTimer(taskId);
                    alert("Time's up! Try to hurry and finish up.");
                    return;
                }
                updateTimerDisplay(taskId);
            }, 1000);

            updateTimerDisplay(taskId);
            syncTimerUI(taskId);
        }

        function pauseTimer(taskId) {
            const state = taskTimers[taskId];
            if (!state || !state.isRunning) return;
            clearInterval(state.intervalId);
            state.intervalId = null;
            state.isRunning = false;
            state.ignoreNextClick = true;
            syncTimerUI(taskId);
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
            state.ignoreNextClick = false;
            updateTimerDisplay(taskId);
            syncTimerUI(taskId);
            hideFloatingTimer(taskId);
        }

        function beginHold(event, taskId) {
            const state = taskTimers[taskId];
            if (!state || !state.isRunning) return;
            if (event.type === 'mousedown' && event.button !== 0) return;
            if (state.holdIntervalId) return;

            if (typeof event.preventDefault === 'function') {
                event.preventDefault();
            }
            if (typeof event.stopPropagation === 'function') {
                event.stopPropagation();
            }

            const holdButton = event.currentTarget;
            state.holdButton = holdButton;
            if (event.pointerId !== undefined && holdButton && holdButton.setPointerCapture) {
                try {
                    holdButton.setPointerCapture(event.pointerId);
                    state.activePointerId = event.pointerId;
                } catch (error) {
                    state.activePointerId = null;
                }
            }

            state.holdRemaining = 3;
            showCountdown(taskId, state.holdRemaining);

            state.holdIntervalId = setInterval(() => {
                state.holdRemaining -= 1;
                if (state.holdRemaining > 0) {
                    showCountdown(taskId, state.holdRemaining);
                    return;
                }

                clearInterval(state.holdIntervalId);
                state.holdIntervalId = null;
                showCountdown(taskId, 0);
                pauseTimer(taskId);
                if (state.activePointerId !== null && state.holdButton && state.holdButton.releasePointerCapture) {
                    try {
                        state.holdButton.releasePointerCapture(state.activePointerId);
                    } catch (error) {
                        // ignore release failures
                    }
                }
                state.activePointerId = null;
                setTimeout(() => hideCountdown(taskId), 600);
            }, 1000);
        }

        function cancelHold(taskId, { event } = {}) {
            const state = taskTimers[taskId];
            if (!state) return;
            if (state.holdIntervalId) {
                clearInterval(state.holdIntervalId);
                state.holdIntervalId = null;
            }
            if (event && event.pointerId !== undefined && state.holdButton && state.holdButton.hasPointerCapture && state.holdButton.hasPointerCapture(event.pointerId)) {
                try {
                    state.holdButton.releasePointerCapture(event.pointerId);
                } catch (error) {
                    // ignore release failures
                }
            } else if (state.activePointerId !== null && state.holdButton && state.holdButton.releasePointerCapture) {
                try {
                    state.holdButton.releasePointerCapture(state.activePointerId);
                } catch (error) {
                    // ignore release failures
                }
            }
            state.activePointerId = null;
            state.holdButton = null;
            hideCountdown(taskId);
        }

        function showCountdown(taskId, value) {
            document.querySelectorAll(`[data-timer-countdown][data-task-id="${taskId}"]`).forEach((el) => {
                el.style.display = 'block';
                el.textContent = String(value);
            });
        }

        function hideCountdown(taskId) {
            document.querySelectorAll(`[data-timer-countdown][data-task-id="${taskId}"]`).forEach((el) => {
                el.style.display = 'none';
                el.textContent = '';
            });
        }

        function initTaskCalendar() {
            const calendar = document.querySelector('[data-task-calendar]');
            if (!calendar) return;
            const taskWeekCountEl = document.querySelector('[data-task-week-count]');
            const taskWeekLabelEl = document.querySelector('[data-task-week-label]');
            const weekDaysEl = calendar.querySelector('[data-week-days]');
            const weekGridEl = calendar.querySelector('[data-week-grid]');
            const weekRangeEl = document.querySelector('[data-week-range]');
            const emptyEl = calendar.querySelector('[data-calendar-empty]');
            const listWrap = calendar.closest('.task-calendar-card')?.querySelector('[data-task-list]');
            const viewButtons = Array.from(document.querySelectorAll('[data-calendar-view]'));
            const navButtons = document.querySelectorAll('[data-week-nav]');
            const previewModal = document.querySelector('[data-task-preview-modal]');
            const previewBody = previewModal ? previewModal.querySelector('[data-task-preview-body]') : null;
            const previewCloses = previewModal ? previewModal.querySelectorAll('[data-task-preview-close]') : [];
            const taskById = new Map();
            let currentView = 'calendar';

            (Array.isArray(taskCalendarData) ? taskCalendarData : []).forEach((task) => {
                taskById.set(String(task.id), task);
            });
            taskCalendarMap = taskById;

            let currentWeekStart = startOfWeek(new Date());
            const premiumEnabled = !!taskCalendarPremium;

            navButtons.forEach((btn) => {
                if (!premiumEnabled) {
                    btn.disabled = true;
                    btn.title = 'Premium feature';
                }
                btn.addEventListener('click', () => {
                    if (!premiumEnabled) return;
                    const delta = parseInt(btn.dataset.weekNav, 10);
                    if (Number.isNaN(delta)) return;
                    currentWeekStart = addDays(currentWeekStart, delta * 7);
                    renderWeek();
                });
            });

            const closePreview = () => {
                if (!previewModal) return;
                previewModal.classList.remove('open');
                document.body.classList.remove('no-scroll');
                if (activePreviewTaskId && shouldShowFloatingTimer(activePreviewTaskId)) {
                    showFloatingTimer(activePreviewTaskId, activePreviewDateKey);
                }
                activePreviewTaskId = null;
                activePreviewDateKey = null;
            };

            const openPreview = (taskId, dateKey = null) => {
                if (!previewModal || !previewBody) return;
                const task = taskById.get(String(taskId));
                if (!task) return;
                previewBody.innerHTML = '';
                previewBody.appendChild(buildTaskPreviewCard(task, dateKey));
                previewModal.classList.add('open');
                document.body.classList.add('no-scroll');
                activePreviewTaskId = String(taskId);
                activePreviewDateKey = dateKey;
                hideFloatingTimer(taskId);
                bindTimerControls(previewBody);
                bindTaskProofButtons(previewBody);
                bindTaskEditDeleteButtons(previewBody);
                bindPhotoThumbs(previewBody);
            };
            openTaskPreview = openPreview;

            if (previewModal) {
                previewCloses.forEach((btn) => btn.addEventListener('click', closePreview));
                previewModal.addEventListener('click', (e) => { if (e.target === previewModal) closePreview(); });
                document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closePreview(); });
            }

            const urlParams = new URLSearchParams(window.location.search);
            const taskParam = urlParams.get('task_id');
            if (taskParam && openTaskPreview) {
                const taskId = parseInt(taskParam, 10);
                if (!Number.isNaN(taskId)) {
                    const instanceDate = urlParams.get('instance_date');
                    openTaskPreview(taskId, instanceDate || null);
                }
            }

            const createModal = document.querySelector('[data-task-create-modal]');
            const createOpen = document.querySelector('[data-task-create-open]');
            const createClose = createModal ? createModal.querySelector('[data-task-create-close]') : null;
            const createForm = createModal ? createModal.querySelector('[data-create-task-form]') : null;

            if (createModal && createOpen && createForm) {
                const closeCreate = () => {
                    createModal.classList.remove('open');
                    document.body.classList.remove('no-scroll');
                };
                const openCreate = () => {
                    createModal.classList.add('open');
                    document.body.classList.add('no-scroll');
                };
                const setCreateFormDefaults = (data) => {
                    if (!createForm) return;
                    createForm.reset();
                    const createTimingSelect = createForm.querySelector('[name="timing_mode"]');
                    const createTimerWrapper = createForm.querySelector('[data-create-timer-minutes]');
                    const createRepeatSelect = createForm.querySelector('[name="recurrence"]');
                    const createRepeatWrapper = createForm.querySelector('[data-create-recurrence-days]');
                    const createOnceDateWrapper = createForm.querySelector('[data-once-date-wrapper]');
                    const createEndToggle = createForm.querySelector('[data-end-date-toggle]');
                    const createEndWrapper = createForm.querySelector('[data-create-end-date]');
                    const createEndToggleField = createForm.querySelector('[data-create-end-toggle]');
                    const createTimeOfDay = createForm.querySelector('[name="time_of_day"]');
                    const createDueTimeWrapper = createForm.querySelector('[data-due-time-wrapper]');
                    const childBoxes = createForm.querySelectorAll('input[name="child_user_ids[]"]');
                    childBoxes.forEach((box) => {
                        box.checked = String(box.value) === String(data.childId || '');
                    });
                    if (!Array.from(childBoxes).some((box) => box.checked) && childBoxes.length === 1) {
                        childBoxes[0].checked = true;
                    }
                    createForm.querySelector('[name="title"]').value = data.title || '';
                    createForm.querySelector('[name="description"]').value = data.description || '';
                    createForm.querySelector('[name="points"]').value = data.points || '';
                    createForm.querySelector('[name="recurrence"]').value = data.recurrence || '';
                    createForm.querySelector('[name="time_of_day"]').value = data.timeOfDay || 'anytime';
                    createForm.querySelector('[name="category"]').value = data.category || 'household';
                    createForm.querySelector('[name="timing_mode"]').value = data.timingMode || 'no_limit';
                    createForm.querySelector('[name="timer_minutes"]').value = data.timerMinutes || '';
                    createForm.querySelector('[name="start_date"]').value = data.startDate || formatDateKey(new Date());
                    createForm.querySelector('[name="due_time"]').value = data.dueTime || '';
                    const endDateInput = createForm.querySelector('[name="end_date"]');
                    if (endDateInput) {
                        endDateInput.value = data.endDate || '';
                    }
                    const endToggle = createForm.querySelector('[data-end-date-toggle]');
                    if (endToggle) {
                        endToggle.checked = !!data.endDate;
                    }
                    const photoToggle = createForm.querySelector('[name="photo_proof_required"]');
                    if (photoToggle) {
                        photoToggle.checked = !!data.photoRequired;
                    }
                    const days = (data.recurrenceDays || '').split(',').filter(Boolean);
                    createForm.querySelectorAll('[name="recurrence_days[]"]').forEach((box) => {
                        box.checked = days.includes(box.value);
                    });
                    updateTimerField(createTimerWrapper, createTimingSelect);
                    updateRepeatDays(createRepeatWrapper, createRepeatSelect);
                    updateOnceDateVisibility(createOnceDateWrapper, createRepeatSelect);
                    updateDueTimeVisibility(createDueTimeWrapper, createTimeOfDay);
                    updateEndToggleVisibility(createEndToggleField, createEndToggle, createEndWrapper, createRepeatSelect);
                    updateEndDate(createEndWrapper, createEndToggle);
                };
                openCreateTaskModal = (data) => {
                    setCreateFormDefaults(data || {});
                    openCreate();
                };
                createOpen.addEventListener('click', openCreate);
                if (createClose) {
                    createClose.addEventListener('click', closeCreate);
                }
                createModal.addEventListener('click', (event) => {
                    if (event.target === createModal) {
                        closeCreate();
                    }
                });
                document.addEventListener('keydown', (event) => {
                    if (event.key === 'Escape') {
                        closeCreate();
                    }
                });
            }

            const setView = (view) => {
                currentView = view;
                viewButtons.forEach((btn) => {
                    const isActive = btn.dataset.calendarView === view;
                    btn.classList.toggle('active', isActive);
                    btn.setAttribute('aria-pressed', isActive ? 'true' : 'false');
                });
                calendar.classList.toggle('is-hidden', view === 'list');
                if (listWrap) {
                    listWrap.classList.toggle('active', view === 'list');
                }
            };

            if (viewButtons.length) {
                viewButtons.forEach((btn) => {
                    btn.addEventListener('click', () => {
                        const view = btn.dataset.calendarView;
                        if (!view) return;
                        setView(view);
                    });
                });
                setView(currentView);
            }

            const buildTaskItem = (task, dateKey, timeInfo, useTextDual = false) => {
                const item = document.createElement('button');
                item.type = 'button';
                item.className = 'calendar-task-item';
                item.dataset.taskId = task.id;
                item.dataset.dateKey = dateKey;
                const header = document.createElement('div');
                header.className = 'calendar-task-header';
                const titleWrap = document.createElement('span');
                titleWrap.className = 'calendar-task-title-wrap';
                const title = document.createElement('span');
                title.className = 'calendar-task-title';
                title.textContent = task.title || 'Task';
                titleWrap.appendChild(title);
                  const isCompleted = isTaskCompleted(task, dateKey);
                  const isCompletedLate = isTaskCompletedLate(task, dateKey);
                  const isOverdue = !isCompleted && isTaskOverdue(task, dateKey);
                  let badge = null;
                  if (isCompleted && isCompletedLate) {
                      badge = document.createElement('span');
                      badge.className = 'calendar-task-badge-group';
                      const doneBadge = document.createElement('span');
                      doneBadge.className = useTextDual ? 'calendar-task-badge completed' : 'calendar-task-badge completed compact';
                      doneBadge.title = 'Done';
                      const doneIcon = document.createElement('i');
                      doneIcon.className = 'fa-solid fa-check';
                      doneBadge.appendChild(doneIcon);
                      if (useTextDual) {
                          doneBadge.appendChild(document.createTextNode(' Done'));
                      }
                      const lateBadge = document.createElement('span');
                      lateBadge.className = useTextDual ? 'calendar-task-badge overdue' : 'calendar-task-badge overdue compact';
                      lateBadge.title = 'Overdue';
                      if (useTextDual) {
                          lateBadge.appendChild(document.createTextNode('Overdue'));
                      } else {
                          const lateIcon = document.createElement('i');
                          lateIcon.className = 'fa-solid fa-triangle-exclamation';
                          lateBadge.appendChild(lateIcon);
                      }
                      badge.appendChild(doneBadge);
                      badge.appendChild(lateBadge);
                  } else if (isCompleted) {
                      badge = document.createElement('span');
                      badge.className = 'calendar-task-badge completed';
                      badge.title = 'Done';
                      const icon = document.createElement('i');
                      icon.className = 'fa-solid fa-check';
                      badge.appendChild(icon);
                      badge.appendChild(document.createTextNode(' Done'));
                  } else if (isOverdue) {
                      badge = document.createElement('span');
                      badge.className = 'calendar-task-badge overdue';
                      badge.title = 'Overdue';
                      badge.textContent = 'Overdue';
                  }
                const points = document.createElement('span');
                points.className = 'calendar-task-points';
                points.textContent = `${task.points || 0}`;
                const meta = document.createElement('span');
                meta.className = 'calendar-task-meta';
                const metaIcon = document.createElement('i');
                metaIcon.className = 'fa-solid fa-clock task-meta-icon';
                meta.appendChild(metaIcon);
                meta.appendChild(document.createTextNode(` ${timeInfo.label}`));
                header.appendChild(titleWrap);
                header.appendChild(points);
                if (badge) {
                    header.appendChild(badge);
                }
                item.appendChild(header);
                item.appendChild(meta);
                if (task.child_name) {
                    const child = document.createElement('span');
                    child.className = 'calendar-task-child';
                    if (task.child_avatar) {
                        const avatar = document.createElement('img');
                        avatar.className = 'calendar-task-child-avatar';
                        avatar.src = task.child_avatar;
                        avatar.alt = task.child_name ? `${task.child_name} avatar` : 'Child avatar';
                        child.appendChild(avatar);
                    }
                    child.appendChild(document.createTextNode(task.child_name));
                    item.appendChild(child);
                }
                item.addEventListener('click', () => openPreview(task.id, dateKey));
                return item;
            };

            const renderList = (weekDates, filteredTasks) => {
                if (!listWrap) return 0;
                listWrap.innerHTML = '';
                const todayKey = formatDateKey(new Date());
                let totalItems = 0;
                const sections = [
                    { key: 'anytime', label: 'Due Today' },
                    { key: 'morning', label: 'Morning' },
                    { key: 'afternoon', label: 'Afternoon' },
                    { key: 'evening', label: 'Evening' }
                ];

                weekDates.forEach(({ date, dateKey }) => {
                    const dayShort = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'][date.getDay()];
                    const items = [];
                    filteredTasks.forEach((task) => {
                        if (!isTaskOnDate(task, dateKey, dayShort)) return;
                        const instance = getTaskInstance(task, dateKey);
                        if (instance && instance.status === 'rejected') return;
                        const timeInfo = getTaskTimeInfo(task);
                        items.push({ task, timeInfo });
                    });
                    items.sort((a, b) => {
                        const timeCompare = a.timeInfo.sort.localeCompare(b.timeInfo.sort);
                        if (timeCompare !== 0) return timeCompare;
                        return String(a.task.title || '').localeCompare(String(b.task.title || ''));
                    });
                    totalItems += items.length;

                    const dayCard = document.createElement('div');
                    dayCard.className = `week-list-day${dateKey === todayKey ? ' is-today' : ''}`;
                    const header = document.createElement('div');
                    header.className = 'week-list-day-header';
                    const name = document.createElement('span');
                    name.className = 'week-list-day-name';
                    name.textContent = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'][date.getDay()];
                    const dateLabel = document.createElement('span');
                    dateLabel.className = 'week-list-day-date';
                    dateLabel.textContent = date.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
                    header.appendChild(name);
                    header.appendChild(dateLabel);
                    dayCard.appendChild(header);

                    if (!items.length) {
                        const empty = document.createElement('div');
                        empty.className = 'week-list-empty';
                        empty.textContent = 'No tasks';
                        dayCard.appendChild(empty);
                    } else {
                        const sectionsWrap = document.createElement('div');
                        sectionsWrap.className = 'week-list-sections';
                        sections.forEach((section) => {
                            const sectionItems = items.filter(({ task }) => (task.time_of_day || 'anytime') === section.key);
                            if (!sectionItems.length) return;
                            const sectionWrap = document.createElement('div');
                            const sectionTitle = document.createElement('div');
                            sectionTitle.className = 'week-list-section-title';
                            sectionTitle.textContent = section.label;
                            const itemsWrap = document.createElement('div');
                            itemsWrap.className = 'week-list-items';
                              sectionItems.forEach(({ task, timeInfo }) => {
                                  itemsWrap.appendChild(buildTaskItem(task, dateKey, timeInfo, true));
                              });
                            sectionWrap.appendChild(sectionTitle);
                            sectionWrap.appendChild(itemsWrap);
                            sectionsWrap.appendChild(sectionWrap);
                        });
                        dayCard.appendChild(sectionsWrap);
                    }

                    listWrap.appendChild(dayCard);
                });

                return totalItems;
            };

            const renderWeek = () => {
                if (!weekDaysEl || !weekGridEl) return;
                weekDaysEl.innerHTML = '';
                weekGridEl.innerHTML = '';
                const weekDates = [];
                const todayKey = formatDateKey(new Date());

                for (let i = 0; i < 7; i += 1) {
                    const date = addDays(currentWeekStart, i);
                    const dateKey = formatDateKey(date);
                    weekDates.push({ date, dateKey });
                    const dayName = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'][date.getDay()];
                    const dayCell = document.createElement('div');
                    dayCell.className = `week-day${dateKey === todayKey ? ' is-today' : ''}`;
                    const nameSpan = document.createElement('span');
                    nameSpan.className = 'week-day-name';
                    nameSpan.textContent = dayName;
                    const numSpan = document.createElement('span');
                    numSpan.className = 'week-day-num';
                    numSpan.textContent = String(date.getDate());
                    dayCell.appendChild(nameSpan);
                    dayCell.appendChild(numSpan);
                    weekDaysEl.appendChild(dayCell);
                }

                if (weekRangeEl) {
                    weekRangeEl.textContent = formatWeekRange(currentWeekStart);
                }

                const filteredTasks = Array.isArray(taskCalendarData) ? taskCalendarData : [];

                let totalItems = 0;

                weekDates.forEach(({ date, dateKey }) => {
                    const dayShort = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'][date.getDay()];
                    const items = [];
                    filteredTasks.forEach((task) => {
                        if (!isTaskOnDate(task, dateKey, dayShort)) return;
                        const instance = getTaskInstance(task, dateKey);
                        if (instance && instance.status === 'rejected') return;
                        const timeInfo = getTaskTimeInfo(task);
                        items.push({ task, timeInfo });
                    });
                    items.sort((a, b) => {
                        const timeCompare = a.timeInfo.sort.localeCompare(b.timeInfo.sort);
                        if (timeCompare !== 0) return timeCompare;
                        return String(a.task.title || '').localeCompare(String(b.task.title || ''));
                    });
                    totalItems += items.length;

                    const column = document.createElement('div');
                    column.className = 'week-column';
                    const list = document.createElement('div');
                    list.className = 'week-column-tasks';

                    if (!items.length) {
                        const empty = document.createElement('div');
                        empty.className = 'calendar-day-empty';
                        empty.textContent = 'No tasks';
                        list.appendChild(empty);
                    } else {
                        const sections = [
                            { key: 'anytime', label: 'Due Today' },
                            { key: 'morning', label: 'Morning' },
                            { key: 'afternoon', label: 'Afternoon' },
                            { key: 'evening', label: 'Evening' }
                        ];

                        sections.forEach((section) => {
                            const sectionItems = items.filter(({ task }) => (task.time_of_day || 'anytime') === section.key);
                            if (!sectionItems.length) return;
                            const sectionWrap = document.createElement('div');
                            sectionWrap.className = 'calendar-section';
                            const sectionTitle = document.createElement('div');
                            sectionTitle.className = 'calendar-section-title';
                            sectionTitle.textContent = section.label;
                            sectionWrap.appendChild(sectionTitle);

                              sectionItems.forEach(({ task, timeInfo }) => {
                                  sectionWrap.appendChild(buildTaskItem(task, dateKey, timeInfo));
                              });

                            list.appendChild(sectionWrap);
                        });
                    }

                    column.appendChild(list);
                    weekGridEl.appendChild(column);
                });

                if (emptyEl) {
                    emptyEl.classList.toggle('active', totalItems === 0);
                }
                if (taskWeekCountEl) {
                    taskWeekCountEl.textContent = String(totalItems);
                }
                if (taskWeekLabelEl) {
                    taskWeekLabelEl.textContent = totalItems === 1 ? 'task' : 'tasks';
                }
                renderList(weekDates, filteredTasks);
            };

            renderWeek();
        }

        function startOfWeek(date) {
            const d = new Date(date.getFullYear(), date.getMonth(), date.getDate());
            const day = d.getDay();
            const diff = (day + 6) % 7;
            d.setDate(d.getDate() - diff);
            d.setHours(0, 0, 0, 0);
            return d;
        }

        function addDays(date, days) {
            const d = new Date(date.getTime());
            d.setDate(d.getDate() + days);
            d.setHours(0, 0, 0, 0);
            return d;
        }

        function formatDateKey(date) {
            const year = date.getFullYear();
            const month = String(date.getMonth() + 1).padStart(2, '0');
            const day = String(date.getDate()).padStart(2, '0');
            return `${year}-${month}-${day}`;
        }

        function formatWeekRange(startDate) {
            const endDate = addDays(startDate, 6);
            const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
            const startLabel = `${months[startDate.getMonth()]} ${startDate.getDate()}`;
            const endLabel = `${months[endDate.getMonth()]} ${endDate.getDate()}`;
            const yearLabel = startDate.getFullYear() === endDate.getFullYear()
                ? startDate.getFullYear()
                : `${startDate.getFullYear()}/${endDate.getFullYear()}`;
            return `${startLabel} - ${endLabel}, ${yearLabel}`;
        }

        function getDateKeyFromString(dateString) {
            if (!dateString) return null;
            const parts = String(dateString).split(' ')[0].split('-');
            if (parts.length < 3) return null;
            const year = parts[0];
            const month = parts[1].padStart(2, '0');
            const day = parts[2].padStart(2, '0');
            return `${year}-${month}-${day}`;
        }

        function getTaskTimeInfo(task) {
            const timeOfDay = task.time_of_day || 'anytime';
            const timeParts = getTimeParts(task.due_date);
            const hasExplicitTime = timeParts && (timeParts.hours !== 0 || timeParts.minutes !== 0);
            const fallbackSort = timeOfDay === 'morning' ? '08:00' : (timeOfDay === 'afternoon' ? '13:00' : '18:00');
            if (hasExplicitTime) {
                const timeLabel = formatTime(timeParts.hours, timeParts.minutes);
                return { label: timeLabel, sort: `${String(timeParts.hours).padStart(2, '0')}:${String(timeParts.minutes).padStart(2, '0')}` };
            }
            if (timeOfDay === 'anytime') {
                return { label: 'Anytime', sort: '99:99' };
            }
            return { label: capitalize(timeOfDay), sort: fallbackSort };
        }

        function getTimeParts(dateString) {
            if (!dateString) return null;
            const parts = String(dateString).split(' ');
            if (parts.length < 2) return null;
            const timeParts = parts[1].split(':');
            if (timeParts.length < 2) return null;
            const hours = parseInt(timeParts[0], 10);
            const minutes = parseInt(timeParts[1], 10);
            if (Number.isNaN(hours) || Number.isNaN(minutes)) return null;
            return { hours, minutes };
        }

        function formatTime(hours, minutes) {
            const period = hours >= 12 ? 'PM' : 'AM';
            const hour12 = hours % 12 === 0 ? 12 : hours % 12;
            return `${hour12}:${String(minutes).padStart(2, '0')} ${period}`;
        }

        function isTaskOnDate(task, dateKey, dayShort) {
            const startKey = getDateKeyFromString(task.due_date);
            if (!startKey) return false;
            const endKey = getDateKeyFromString(task.end_date);
            if (dateKey < startKey) return false;
            if (endKey && dateKey > endKey) return false;
            if (task.recurrence === 'daily') {
                return true;
            }
            if (task.recurrence === 'weekly') {
                const days = String(task.recurrence_days || '')
                    .split(',')
                    .map((day) => day.trim())
                    .filter(Boolean);
                return days.includes(dayShort);
            }
            return dateKey === startKey;
        }

        function getTaskInstance(task, dateKey) {
            if (!task || !dateKey) return null;
            const instances = task.instances || null;
            if (!instances) return null;
            return instances[dateKey] || null;
        }

        function isTaskCompleted(task, dateKey) {
            if (!task) return false;
            const repeat = task.recurrence || '';
            if (!repeat) {
                const statusDone = task.status === 'completed' || task.status === 'approved';
                return statusDone;
            }
            if (!dateKey) return false;
            const instance = getTaskInstance(task, dateKey);
            if (instance) {
                return instance.status === 'completed' || instance.status === 'approved';
            }
            return false;
        }

        function getTaskCompletionTimestamp(task, dateKey) {
            if (!task) return null;
            const repeat = task.recurrence || '';
            const instance = repeat ? getTaskInstance(task, dateKey) : null;
            let stamp = null;
            if (!repeat) {
                stamp = task.approved_at || task.completed_at || null;
            } else if (instance) {
                stamp = instance.approved_at || instance.completed_at || null;
            }
            if (!stamp) return null;
            const parsed = Date.parse(stamp);
            return Number.isNaN(parsed) ? null : parsed;
        }

        function isTaskCompletedLate(task, dateKey) {
            if (!isTaskCompleted(task, dateKey)) return false;
            const completionStamp = getTaskCompletionTimestamp(task, dateKey);
            if (!completionStamp) return false;
            const dueStamp = getInstanceDueTimestamp(task, dateKey);
            if (!dueStamp) return false;
            return completionStamp > dueStamp;
        }

        function isTaskOverdue(task, dateKey) {
            if (!task || !dateKey) return false;
            const repeat = task.recurrence || '';
            const instance = repeat ? getTaskInstance(task, dateKey) : null;
            if (instance && instance.status === 'rejected') return false;
            if (isTaskCompleted(task, dateKey)) return false;
            const todayKey = formatDateKey(new Date());
            if (dateKey > todayKey) return false;
            if (!repeat && task.status !== 'pending') return false;
            const stamp = getInstanceDueTimestamp(task, dateKey);
            if (!stamp) return false;
            return stamp < Date.now();
        }

        function getInstanceDueTimestamp(task, dateKey) {
            if (!task || !dateKey) return null;
            const parts = dateKey.split('-').map((value) => parseInt(value, 10));
            if (parts.length !== 3 || parts.some((value) => Number.isNaN(value))) return null;
            let hours = 23;
            let minutes = 59;
            let seconds = 59;
            const timeParts = getTimeParts(task.due_date);
            const hasExplicitTime = timeParts && (timeParts.hours !== 0 || timeParts.minutes !== 0);
            if (hasExplicitTime) {
                hours = timeParts.hours;
                minutes = timeParts.minutes;
                seconds = 0;
            } else if ((task.time_of_day || 'anytime') !== 'anytime') {
                hours = task.time_of_day === 'morning' ? 8 : (task.time_of_day === 'afternoon' ? 13 : 18);
                minutes = 0;
                seconds = 0;
            }
            return new Date(parts[0], parts[1] - 1, parts[2], hours, minutes, seconds).getTime();
        }

        function buildTaskPreviewCard(task, dateKey = null) {
            const card = document.createElement('details');
            card.className = 'task-card';
            const isRecurring = !!(task.recurrence || '');
            const viewDateKey = dateKey || formatDateKey(new Date());
            const instance = isRecurring ? getTaskInstance(task, viewDateKey) : null;
            const statusForView = isRecurring ? (instance ? instance.status : 'pending') : (task.status || 'pending');
            const proofSrc = instance && instance.photo_proof ? instance.photo_proof : task.photo_proof;
            const startDateValue = getDateKeyFromString(task.due_date) || '';
            let dueTimeValue = '';
            const timeParts = getTimeParts(task.due_date);
            if (timeParts) {
                dueTimeValue = `${String(timeParts.hours).padStart(2, '0')}:${String(timeParts.minutes).padStart(2, '0')}`;
            }
            const buildDuplicateButton = (options = {}) => {
                const duplicateButton = document.createElement('button');
                duplicateButton.type = 'button';
                duplicateButton.className = options.className || 'icon-button';
                duplicateButton.setAttribute('aria-label', 'Duplicate task');
                duplicateButton.dataset.taskDuplicateOpen = '';
                duplicateButton.dataset.taskId = String(task.id);
                duplicateButton.dataset.childId = String(task.child_user_id || '');
                duplicateButton.dataset.title = task.title || '';
                duplicateButton.dataset.description = task.description || '';
                duplicateButton.dataset.startDate = startDateValue;
                duplicateButton.dataset.dueTime = dueTimeValue;
                duplicateButton.dataset.endDate = task.end_date || '';
                duplicateButton.dataset.points = String(task.points || 0);
                duplicateButton.dataset.timeOfDay = task.time_of_day || 'anytime';
                duplicateButton.dataset.recurrence = task.recurrence || '';
                duplicateButton.dataset.recurrenceDays = task.recurrence_days || '';
                duplicateButton.dataset.category = task.category || '';
                duplicateButton.dataset.timingMode = task.timing_mode || '';
                duplicateButton.dataset.timerMinutes = String(task.timer_minutes || 0);
                duplicateButton.dataset.photoRequired = task.photo_proof_required ? '1' : '0';
                if (options.label) {
                    duplicateButton.innerHTML = `<i class="fa-solid fa-clone"></i> ${options.label}`;
                } else {
                    duplicateButton.innerHTML = '<i class="fa-solid fa-clone"></i>';
                }
                return duplicateButton;
            };

            const summary = document.createElement('summary');
            summary.className = 'task-card-summary';
            const summaryLeft = document.createElement('div');
            summaryLeft.className = 'task-card-summary-left';
            const iconWrap = document.createElement('div');
            iconWrap.className = 'task-card-icon';
            iconWrap.innerHTML = '<i class="fa-solid fa-list-check"></i>';
            const titleBlock = document.createElement('div');
            titleBlock.className = 'task-card-title-block';
            const titleRow = document.createElement('div');
            titleRow.className = 'task-card-title-row';
            const title = document.createElement('div');
            title.className = 'task-card-title';
            title.textContent = task.title || 'Task';
            titleRow.appendChild(title);
            if (task.child_name) {
                const childPill = document.createElement('span');
                childPill.className = 'task-card-child-pill';
                childPill.textContent = task.child_name;
                titleRow.appendChild(childPill);
            }
            const subtitle = document.createElement('div');
            subtitle.className = 'task-card-subtitle';
            const isCompleted = isTaskCompleted(task, viewDateKey);
            const isOverdue = !isCompleted && isTaskOverdue(task, viewDateKey);
            const dueDisplay = getTaskDueDisplay(task);
            subtitle.textContent = dueDisplay;
            if (statusForView === 'completed') {
                const status = document.createElement('span');
                status.className = 'task-card-status';
                status.textContent = 'Pending Approval';
                subtitle.appendChild(status);
            } else if (statusForView === 'approved') {
                const status = document.createElement('span');
                status.className = 'task-card-status is-success';
                status.textContent = 'Approved';
                subtitle.appendChild(status);
            } else if (isOverdue) {
                const status = document.createElement('span');
                status.className = 'task-card-status is-overdue';
                status.textContent = 'Overdue';
                subtitle.appendChild(status);
            }
            titleBlock.appendChild(titleRow);
            titleBlock.appendChild(subtitle);
            summaryLeft.appendChild(iconWrap);
            summaryLeft.appendChild(titleBlock);
            const summaryRight = document.createElement('div');
            summaryRight.className = 'task-card-summary-right';
            const points = document.createElement('div');
            points.className = 'task-card-points';
            points.innerHTML = `<i class="fa-solid fa-coins"></i> ${task.points || 0}`;
            const chevron = document.createElement('span');
            chevron.className = 'task-card-chevron';
            chevron.innerHTML = '<i class="fa-solid fa-chevron-right"></i>';
            summaryRight.appendChild(points);
            summaryRight.appendChild(chevron);
            summary.appendChild(summaryLeft);
            summary.appendChild(summaryRight);
            card.appendChild(summary);

            const body = document.createElement('div');
            body.className = 'task-card-body';

            const meta = document.createElement('div');
            meta.className = 'task-meta';
            meta.appendChild(buildMetaRow('Due', dueDisplay));

            const infoRow = document.createElement('div');
            infoRow.className = 'task-meta-row';
            infoRow.appendChild(buildMetaItem('Category', task.category || ''));
            infoRow.appendChild(buildMetaItem('Timing', getTimingLabel(task.timing_mode)));
            infoRow.appendChild(buildMetaItem('Repeat', getRepeatLabel(task)));
            meta.appendChild(infoRow);

            meta.appendChild(buildMetaRow('Photo Proof', task.photo_proof_required ? 'Required' : 'Not required'));

            if (task.creator_name) {
                meta.appendChild(buildMetaRow('Created by', task.creator_name));
            }
            if (task.child_name) {
                meta.appendChild(buildMetaRow('Assigned to', task.child_name, { avatarSrc: task.child_avatar || '' }));
            }

            if (task.description) {
                const desc = document.createElement('div');
                desc.className = 'task-card-note text';
                const descIcon = document.createElement('i');
                descIcon.className = 'fa-solid fa-message task-desc-icon';
                const descText = document.createElement('span');
                descText.textContent = task.description;
                desc.appendChild(descIcon);
                desc.appendChild(descText);
                body.appendChild(desc);
            }

            body.appendChild(meta);

            if (task.photo_proof_required && proofSrc) {
                const proofWrap = document.createElement('div');
                proofWrap.className = 'task-photo-proof';
                const label = document.createElement('div');
                label.className = 'task-photo-proof-label';
                const icon = document.createElement('i');
                icon.className = 'fa-solid fa-camera task-meta-icon';
                const labelText = document.createElement('span');
                labelText.textContent = 'Photo proof:';
                label.appendChild(icon);
                label.appendChild(labelText);
                const img = document.createElement('img');
                img.src = proofSrc;
                img.alt = 'Photo proof';
                img.className = 'task-photo-thumb';
                img.dataset.taskPhotoSrc = proofSrc;
                proofWrap.appendChild(label);
                proofWrap.appendChild(img);
                body.appendChild(proofWrap);
            }

            if (task.timing_mode === 'timer' && task.timer_minutes && statusForView === 'pending') {
                const timerText = document.createElement('p');
                timerText.className = 'timer';
                timerText.dataset.timerDisplay = '';
                timerText.dataset.taskId = String(task.id);
                timerText.textContent = `${String(task.timer_minutes).padStart(2, '0')}:00`;
                body.appendChild(timerText);

                const controls = document.createElement('div');
                controls.className = 'timer-controls';
                const countdown = document.createElement('div');
                countdown.className = 'pause-hold-countdown';
                countdown.dataset.timerCountdown = '';
                countdown.dataset.taskId = String(task.id);
                countdown.setAttribute('aria-live', 'polite');
                const startButton = document.createElement('button');
                startButton.type = 'button';
                startButton.className = 'timer-button';
                startButton.dataset.timerAction = 'start';
                startButton.dataset.taskId = String(task.id);
                startButton.dataset.limit = String(task.timer_minutes || 0);
                startButton.textContent = 'Start Timer';
                const cancelButton = document.createElement('button');
                cancelButton.type = 'button';
                cancelButton.className = 'timer-cancel-button';
                cancelButton.dataset.timerAction = 'cancel';
                cancelButton.dataset.taskId = String(task.id);
                cancelButton.textContent = 'Cancel';
                controls.appendChild(countdown);
                controls.appendChild(startButton);
                controls.appendChild(cancelButton);
                body.appendChild(controls);
            }

            const buildMenu = (items) => {
                const menu = document.createElement('div');
                menu.className = 'task-card-menu';
                menu.dataset.taskMenu = '';
                const toggle = document.createElement('button');
                toggle.type = 'button';
                toggle.className = 'task-card-menu-toggle';
                toggle.dataset.taskMenuToggle = '';
                toggle.setAttribute('aria-label', 'Open task actions');
                toggle.innerHTML = '<i class="fa-solid fa-ellipsis-vertical"></i>';
                const list = document.createElement('div');
                list.className = 'task-card-menu-list';
                items.forEach((item) => list.appendChild(item));
                menu.appendChild(toggle);
                menu.appendChild(list);
                return menu;
            };

            if (!isParentView) {
                if (statusForView === 'pending') {
                    if (task.photo_proof_required) {
                        const finishButton = document.createElement('button');
                        finishButton.type = 'button';
                        finishButton.className = 'button';
                        finishButton.dataset.taskProofOpen = '';
                        finishButton.dataset.taskId = String(task.id);
                        finishButton.dataset.taskTitle = task.title || '';
                        if (isRecurring) {
                            finishButton.dataset.dateKey = viewDateKey;
                        }
                        finishButton.textContent = 'Finish Task';
                        body.appendChild(finishButton);
                    } else {
                        const form = document.createElement('form');
                        form.method = 'POST';
                        form.action = 'task.php';
                        const hidden = document.createElement('input');
                        hidden.type = 'hidden';
                        hidden.name = 'task_id';
                        hidden.value = String(task.id);
                        if (isRecurring) {
                            const instanceInput = document.createElement('input');
                            instanceInput.type = 'hidden';
                            instanceInput.name = 'instance_date';
                            instanceInput.value = viewDateKey;
                            form.appendChild(instanceInput);
                        }
                        const button = document.createElement('button');
                        button.type = 'submit';
                        button.name = 'complete_task';
                        button.className = 'button';
                        button.textContent = 'Finish Task';
                        form.appendChild(hidden);
                        form.appendChild(button);
                        body.appendChild(form);
                    }
                } else if (statusForView === 'completed') {
                    const waiting = document.createElement('p');
                    waiting.className = 'waiting-label';
                    waiting.textContent = 'Waiting for approval';
                    body.appendChild(waiting);
                } else if (statusForView === 'approved') {
                    const approved = document.createElement('p');
                    approved.className = 'completed';
                    approved.innerHTML = '<span class="completed-icon"><i class="fa-regular fa-circle-check"></i></span>Approved';
                    body.appendChild(approved);
                }
            } else if (canManageTasks) {
                if (statusForView === 'pending') {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = 'task.php';
                    const hidden = document.createElement('input');
                    hidden.type = 'hidden';
                    hidden.name = 'task_id';
                    hidden.value = String(task.id);
                    if (isRecurring) {
                        const instanceInput = document.createElement('input');
                        instanceInput.type = 'hidden';
                        instanceInput.name = 'instance_date';
                        instanceInput.value = viewDateKey;
                        form.appendChild(instanceInput);
                    }
                    const button = document.createElement('button');
                    button.type = 'submit';
                    button.name = 'complete_task';
                    button.className = 'button';
                    button.textContent = 'Finish Task';
                    form.appendChild(hidden);
                    form.appendChild(button);
                    body.appendChild(form);

                    const footer = document.createElement('div');
                    footer.className = 'task-card-footer';
                    const editButton = document.createElement('button');
                    editButton.type = 'button';
                    editButton.className = 'button task-card-primary';
                    editButton.textContent = 'Edit Task';
                    editButton.dataset.taskEditOpen = '';
                    editButton.dataset.taskId = String(task.id);
                    editButton.dataset.childId = String(task.child_user_id || '');
                    editButton.dataset.title = task.title || '';
                    editButton.dataset.description = task.description || '';
                    editButton.dataset.startDate = startDateValue;
                    editButton.dataset.dueTime = dueTimeValue;
                    editButton.dataset.endDate = task.end_date || '';
                    editButton.dataset.points = String(task.points || 0);
                    editButton.dataset.timeOfDay = task.time_of_day || 'anytime';
                    editButton.dataset.recurrence = task.recurrence || '';
                    editButton.dataset.recurrenceDays = task.recurrence_days || '';
                    editButton.dataset.category = task.category || '';
                    editButton.dataset.timingMode = task.timing_mode || '';
                    editButton.dataset.timerMinutes = String(task.timer_minutes || 0);
                    editButton.dataset.photoRequired = task.photo_proof_required ? '1' : '0';
                    const duplicateMenuItem = buildDuplicateButton({ className: 'task-card-menu-item', label: 'Duplicate Task' });
                    const deleteButton = document.createElement('button');
                    deleteButton.type = 'button';
                    deleteButton.className = 'task-card-menu-item danger';
                    deleteButton.dataset.taskDeleteOpen = '';
                    deleteButton.dataset.taskId = String(task.id);
                    deleteButton.dataset.childName = task.child_name || '';
                    deleteButton.dataset.title = task.title || '';
                    deleteButton.innerHTML = '<i class="fa-regular fa-trash-can"></i> Delete Forever';
                    footer.appendChild(editButton);
                    footer.appendChild(buildMenu([duplicateMenuItem, deleteButton]));
                    body.appendChild(footer);
                } else if (statusForView === 'completed') {
                    const approveForm = document.createElement('form');
                    approveForm.method = 'POST';
                    approveForm.action = 'task.php';
                    const hidden = document.createElement('input');
                    hidden.type = 'hidden';
                    hidden.name = 'task_id';
                    hidden.value = String(task.id);
                    if (isRecurring) {
                        const instanceInput = document.createElement('input');
                        instanceInput.type = 'hidden';
                        instanceInput.name = 'instance_date';
                        instanceInput.value = viewDateKey;
                        approveForm.appendChild(instanceInput);
                    }
                    const approveButton = document.createElement('button');
                    approveButton.type = 'submit';
                    approveButton.name = 'approve_task';
                    approveButton.className = 'button';
                    approveButton.textContent = 'Review & Approve';
                    approveForm.appendChild(hidden);
                    approveForm.appendChild(approveButton);
                    body.appendChild(approveForm);

                    const rejectForm = document.createElement('form');
                    rejectForm.method = 'POST';
                    rejectForm.action = 'task.php';
                    rejectForm.className = 'task-reject-form';
                    const rejectHidden = document.createElement('input');
                    rejectHidden.type = 'hidden';
                    rejectHidden.name = 'task_id';
                    rejectHidden.value = String(task.id);
                    if (isRecurring) {
                        const instanceInput = document.createElement('input');
                        instanceInput.type = 'hidden';
                        instanceInput.name = 'instance_date';
                        instanceInput.value = viewDateKey;
                        rejectForm.appendChild(instanceInput);
                    }
                    const rejectFlag = document.createElement('input');
                    rejectFlag.type = 'hidden';
                    rejectFlag.name = 'reject_task';
                    rejectFlag.value = '1';
                    const rejectLabel = document.createElement('label');
                    rejectLabel.setAttribute('for', `reject_note_modal_${task.id}`);
                    rejectLabel.textContent = 'Rejection note (optional)';
                    const rejectNote = document.createElement('textarea');
                    rejectNote.name = 'reject_note';
                    rejectNote.id = `reject_note_modal_${task.id}`;
                    rejectNote.placeholder = 'Explain why this task was rejected.';
                    const rejectActions = document.createElement('div');
                    rejectActions.className = 'task-reject-actions';
                    const reactivateBtn = document.createElement('button');
                    reactivateBtn.type = 'submit';
                    reactivateBtn.name = 'reject_action';
                    reactivateBtn.value = 'reactivate';
                    reactivateBtn.className = 'button secondary';
                    reactivateBtn.textContent = 'Reject & Reactivate';
                    const closeBtn = document.createElement('button');
                    closeBtn.type = 'submit';
                    closeBtn.name = 'reject_action';
                    closeBtn.value = 'close';
                    closeBtn.className = 'button danger';
                    closeBtn.textContent = 'Reject & Close';
                    rejectActions.appendChild(reactivateBtn);
                    rejectActions.appendChild(closeBtn);
                    rejectForm.appendChild(rejectHidden);
                    rejectForm.appendChild(rejectFlag);
                    rejectForm.appendChild(rejectLabel);
                    rejectForm.appendChild(rejectNote);
                    rejectForm.appendChild(rejectActions);
                    body.appendChild(rejectForm);
                    const footer = document.createElement('div');
                    footer.className = 'task-card-footer';
                    footer.appendChild(document.createElement('div'));
                    const duplicateMenuItem = buildDuplicateButton({ className: 'task-card-menu-item', label: 'Duplicate Task' });
                    footer.appendChild(buildMenu([duplicateMenuItem]));
                    body.appendChild(footer);
                }
            }

            card.appendChild(body);
            return card;
        }

        function buildMetaRow(label, value, options = {}) {
            const row = document.createElement('div');
            row.className = 'task-meta-row';
            const span = document.createElement('span');
            const labelSpan = document.createElement('span');
            labelSpan.className = 'task-meta-label';
            if (label === 'Assigned to' && options.avatarSrc) {
                const avatar = document.createElement('img');
                avatar.className = 'task-meta-avatar';
                avatar.src = options.avatarSrc;
                avatar.alt = value ? `${value} avatar` : 'Avatar';
                labelSpan.appendChild(avatar);
            } else {
                const iconClass = getMetaIconClass(label, value);
                if (iconClass) {
                    const icon = document.createElement('i');
                    icon.className = `${iconClass} task-meta-icon`;
                    labelSpan.appendChild(icon);
                }
            }
            span.appendChild(labelSpan);
            span.appendChild(document.createTextNode(` ${value}`));
            row.appendChild(span);
            return row;
        }

        function buildMetaItem(label, value) {
            const span = document.createElement('span');
            const labelSpan = document.createElement('span');
            labelSpan.className = 'task-meta-label';
            const iconClass = getMetaIconClass(label, value);
            if (iconClass) {
                const icon = document.createElement('i');
                icon.className = `${iconClass} task-meta-icon`;
                labelSpan.appendChild(icon);
            }
            span.appendChild(labelSpan);
            span.appendChild(document.createTextNode(` ${value}`));
            return span;
        }

        function getMetaIconClass(label, value) {
            if (label === 'Due') return 'fa-solid fa-clock';
            if (label === 'Category') return 'fa-solid fa-table-list';
            if (label === 'Timing') return 'fa-solid fa-stopwatch';
            if (label === 'Repeat') return getRepeatIconClass(value);
            if (label === 'Photo Proof') return 'fa-solid fa-camera';
            if (label === 'Created by') return 'fa-solid fa-user-pen';
            return '';
        }

        function getRepeatIconClass(value) {
            if (!value) return 'fa-regular fa-calendar-days';
            if (value.startsWith('Specific Days')) return 'fa-solid fa-calendar-day';
            return 'fa-regular fa-calendar-days';
        }

        function getRepeatLabel(task) {
            if (task.recurrence === 'daily') return 'Every Day';
            if (task.recurrence === 'weekly') {
                const days = String(task.recurrence_days || '').split(',').map((day) => day.trim()).filter(Boolean);
                const label = days.length ? days.join(', ') : 'Specific Days';
                return `Specific Days (${label})`;
            }
            return 'Once';
        }

        function getTimingLabel(value) {
            if (!value) return '';
            if (value === 'no_limit') return 'None';
            return value.charAt(0).toUpperCase() + value.slice(1);
        }

        function getTaskDueDisplay(task) {
            const timeOfDay = task.time_of_day || 'anytime';
            const isOnce = !task.recurrence;
            if (isOnce) {
                return task.due_date_formatted || 'No date set';
            }
            const timeParts = getTimeParts(task.due_date);
            const hasExplicitTime = timeParts && (timeParts.hours !== 0 || timeParts.minutes !== 0);
            if (hasExplicitTime) {
                return formatTime(timeParts.hours, timeParts.minutes);
            }
            if (timeOfDay === 'anytime') {
                return 'Anytime';
            }
            return capitalize(timeOfDay);
        }

        function capitalize(value) {
            if (!value) return '';
            return value.charAt(0).toUpperCase() + value.slice(1);
        }
    </script>
</head>
<body<?php echo !empty($bodyClasses) ? ' class="' . implode(' ', $bodyClasses) . '"' : ''; ?>>
    <?php
        $isParentContext = canCreateContent($_SESSION['user_id']);
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
                <h1>Task Management</h1>
                <p class="page-header-meta">Welcome back, <?php echo htmlspecialchars($_SESSION['name'] ?? $_SESSION['username'] ?? 'Unknown User'); ?>
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
                    <a class="nav-family-button page-header-action" href="dashboard_parent.php#manage-family" aria-label="Family settings">
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
                <span>Rewards Shop</span>
            </a>
            <a class="nav-link<?php echo $profileActive ? ' is-active' : ''; ?>" href="profile.php?self=1"<?php echo $profileActive ? ' aria-current="page"' : ''; ?>>
                <i class="fa-solid fa-user"></i>
                <span>Profile</span>
            </a>
        </nav>
    </header>
    <main>
        <?php if (isset($message)) echo "<p>$message</p>"; ?>
        <div class="task-list">
            <div class="task-list-header">
                <h2 class="task-list-title"></h2>
                <?php if (canCreateContent($_SESSION['user_id'])): ?>
                    <p class="task-list-subtitle" data-task-list-subtitle>Managing <strong data-task-week-count><?php echo (int) $tasksCount; ?></strong> <span data-task-week-label><?php echo ((int) $tasksCount) === 1 ? 'task' : 'tasks'; ?></span> this week</p>
                <?php endif; ?>
                <?php if (canCreateContent($_SESSION['user_id'])): ?>
                    <div class="task-create-fab">
                        <button type="button" class="task-create-button" data-task-create-open aria-label="Create Task">
                            <i class="fa-solid fa-plus"></i>
                        </button>
                    </div>
                <?php endif; ?>
            </div>
            <?php
                $filterParams = array_filter([
                    'status' => $filterStatus,
                    'category' => $filterCategory,
                    'time_of_day' => $filterTimeOfDay,
                    'photo_required' => $filterPhoto,
                    'timed' => $filterTimed,
                    'repeat' => $filterRepeat
                ]);
                $filterQuery = http_build_query($filterParams);
                $filterPrefix = $filterQuery ? ('?' . $filterQuery) : '';
            ?>
            <?php if (canCreateContent($_SESSION['user_id']) && !empty($children)): ?>
                <div class="task-filter-row" aria-label="Filter tasks by child">
                    <div class="task-child-grid">
                        <a class="task-child-card<?php echo empty($selectedChildId) ? ' is-active' : ''; ?>" href="task.php<?php echo $filterPrefix; ?>">
                            <span class="task-child-icon"><i class="fa-solid fa-layer-group"></i></span>
                            <span>All Tasks</span>
                        </a>
                        <?php foreach ($children as $child): ?>
                            <?php $childIdValue = (int) ($child['child_user_id'] ?? 0); ?>
                            <?php
                                $childParams = $filterParams;
                                $childParams['child_id'] = $childIdValue;
                                $childQuery = http_build_query($childParams);
                                $childName = $child['first_name'] ?? $child['child_name'];
                            ?>
                            <a class="task-child-card<?php echo $selectedChildId === $childIdValue ? ' is-active' : ''; ?>" href="task.php?<?php echo htmlspecialchars($childQuery, ENT_QUOTES); ?>">
                                <img src="<?php echo htmlspecialchars($child['avatar']); ?>" alt="<?php echo htmlspecialchars($childName); ?>">
                                <span><?php echo htmlspecialchars($childName); ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="task-filter-toggle" data-filter-toggle aria-expanded="<?php echo $filtersActive ? 'true' : 'false'; ?>">
                        <i class="fa-solid fa-filter"></i>
                        More Filters
                    </button>
                </div>
                <form class="task-filter-form" method="get" action="task.php" data-filter-form>
                    <?php if (!empty($selectedChildId)): ?>
                        <input type="hidden" name="child_id" value="<?php echo (int) $selectedChildId; ?>">
                    <?php endif; ?>
                    <div class="task-filter-field">
                        <label for="task-filter-status">Status</label>
                        <select id="task-filter-status" name="status">
                            <option value="">All</option>
                            <option value="pending"<?php echo $filterStatus === 'pending' ? ' selected' : ''; ?>>Pending</option>
                            <option value="completed"<?php echo $filterStatus === 'completed' ? ' selected' : ''; ?>>Completed</option>
                            <option value="approved"<?php echo $filterStatus === 'approved' ? ' selected' : ''; ?>>Approved</option>
                            <option value="expired"<?php echo $filterStatus === 'expired' ? ' selected' : ''; ?>>Expired</option>
                        </select>
                    </div>
                    <div class="task-filter-field">
                        <label for="task-filter-category">Category</label>
                        <select id="task-filter-category" name="category">
                            <option value="">All</option>
                            <?php foreach ($availableCategories as $category): ?>
                                <option value="<?php echo htmlspecialchars($category); ?>"<?php echo $filterCategory === $category ? ' selected' : ''; ?>>
                                    <?php echo htmlspecialchars(ucfirst($category)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="task-filter-field">
                        <label for="task-filter-time">Due By</label>
                        <select id="task-filter-time" name="time_of_day">
                            <option value="">All</option>
                            <option value="anytime"<?php echo $filterTimeOfDay === 'anytime' ? ' selected' : ''; ?>>Anytime</option>
                            <option value="morning"<?php echo $filterTimeOfDay === 'morning' ? ' selected' : ''; ?>>Morning</option>
                            <option value="afternoon"<?php echo $filterTimeOfDay === 'afternoon' ? ' selected' : ''; ?>>Afternoon</option>
                            <option value="evening"<?php echo $filterTimeOfDay === 'evening' ? ' selected' : ''; ?>>Evening</option>
                        </select>
                    </div>
                    <div class="task-filter-field">
                        <label for="task-filter-photo">Photo Required</label>
                        <select id="task-filter-photo" name="photo_required">
                            <option value="">All</option>
                            <option value="required"<?php echo $filterPhoto === 'required' ? ' selected' : ''; ?>>Required</option>
                            <option value="not_required"<?php echo $filterPhoto === 'not_required' ? ' selected' : ''; ?>>Not required</option>
                        </select>
                    </div>
                    <div class="task-filter-field">
                        <label for="task-filter-timed">Timer</label>
                        <select id="task-filter-timed" name="timed">
                            <option value="">All</option>
                            <option value="timed"<?php echo $filterTimed === 'timed' ? ' selected' : ''; ?>>Timed</option>
                            <option value="not_timed"<?php echo $filterTimed === 'not_timed' ? ' selected' : ''; ?>>Not timed</option>
                        </select>
                    </div>
                    <div class="task-filter-field">
                        <label for="task-filter-repeat">Repeat</label>
                        <select id="task-filter-repeat" name="repeat">
                            <option value="">All</option>
                            <option value="once"<?php echo $filterRepeat === 'once' ? ' selected' : ''; ?>>Once</option>
                            <option value="everyday"<?php echo $filterRepeat === 'everyday' ? ' selected' : ''; ?>>Every day</option>
                            <option value="specific_days"<?php echo $filterRepeat === 'specific_days' ? ' selected' : ''; ?>>Specific days</option>
                        </select>
                    </div>
                    <div class="task-filter-actions">
                        <button type="submit" class="button secondary">Apply Filters</button>
                    </div>
                </form>
            <?php endif; ?>
            <?php if (empty($tasks)): ?>
                <p>No tasks available.</p>
            <?php else: ?>
                <details class="task-section-toggle">
                    <summary>
                        <span class="task-section-title"><span class="task-section-icon is-active"><i class="fa-solid fa-list-check"></i></span>Active Tasks <span class="task-count-badge"><?php echo count($pending_tasks); ?></span></span>
                    </summary>
                    <div class="task-section-content">
                        <?php if (empty($pending_tasks)): ?>
                            <p>No active tasks.</p>
                        <?php else: ?>
                            <?php foreach ($pending_tasks as $task): ?>
                        <?php
                        $today_key = date('Y-m-d');
                        $instance_today = $taskInstancesByTask[(int) $task['id']][$today_key] ?? null;
                        $instance_status = $instance_today['status'] ?? null;
                        $today_day = date('D');
                        $time_of_day = $task['time_of_day'] ?? 'anytime';
                        $is_recurring = !empty($task['recurrence']);
                        $is_overdue = false;
                        $start_key = !empty($task['due_date']) ? date('Y-m-d', strtotime($task['due_date'])) : $today_key;
                        $end_key = !empty($task['end_date']) ? $task['end_date'] : null;
                        if ($end_key && $today_key > $end_key) {
                            continue;
                        }
                        $within_range = $today_key >= $start_key && (!$end_key || $today_key <= $end_key);
                        $day_matches = true;
                        if ($task['recurrence'] === 'weekly') {
                            $days = array_filter(array_map('trim', explode(',', (string)($task['recurrence_days'] ?? ''))));
                            $day_matches = empty($days) ? true : in_array($today_day, $days, true);
                        }
                        if ($within_range && $day_matches) {
                            $time_part = !empty($task['due_date']) ? date('H:i', strtotime($task['due_date'])) : '';
                            $has_time = $time_part !== '' && $time_part !== '00:00';
                            if ($has_time) {
                                $due_stamp = strtotime($today_key . ' ' . $time_part . ':00');
                            } elseif ($time_of_day === 'anytime') {
                                $due_stamp = strtotime($today_key . ' 23:59:59');
                            } else {
                                $fallback_time = $time_of_day === 'morning' ? '08:00' : ($time_of_day === 'afternoon' ? '13:00' : '18:00');
                                $due_stamp = strtotime($today_key . ' ' . $fallback_time . ':00');
                            }
                            $is_overdue = $due_stamp < time();
                        }
                        if ($instance_status) {
                            $is_overdue = false;
                        }
                        ?>
                        <?php
                            $timeOfDay = $task['time_of_day'] ?? 'anytime';
                            $isOnce = empty($task['recurrence']);
                            if ($isOnce) {
                                $dueDisplay = $task['due_date_formatted'];
                            } else {
                                $timeText = !empty($task['due_date']) ? date('g:i A', strtotime($task['due_date'])) : '';
                                if ($timeText === '12:00 AM') {
                                    $timeText = '';
                                }
                                if ($timeText !== '') {
                                    $dueDisplay = $timeText;
                                } elseif ($timeOfDay === 'anytime') {
                                    $dueDisplay = 'Anytime';
                                } else {
                                    $dueDisplay = ucfirst($timeOfDay);
                                }
                            }
                            $childDisplayName = $task['child_display_name'] ?? ($childNameById[(int)($task['child_user_id'] ?? 0)] ?? '');
                        ?>
                        <details class="task-card" id="task-<?php echo (int) $task['id']; ?>" data-task-id="<?php echo $task['id']; ?>">
                            <summary class="task-card-summary">
                                <div class="task-card-summary-left">
                                    <div class="task-card-icon"><i class="fa-solid fa-list-check"></i></div>
                                    <div class="task-card-title-block">
                                        <div class="task-card-title-row">
                                            <div class="task-card-title"><?php echo htmlspecialchars($task['title']); ?></div>
                                            <?php if (!empty($childDisplayName)): ?>
                                                <span class="task-card-child-pill"><?php echo htmlspecialchars($childDisplayName); ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="task-card-subtitle">
                                            <?php echo htmlspecialchars($dueDisplay); ?>
                                            <?php if ($is_overdue): ?>
                                                <span class="task-card-status is-overdue">Overdue</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="task-card-summary-right">
                                    <div class="task-card-points"><i class="fa-solid fa-coins"></i> <?php echo (int)$task['points']; ?></div>
                                    <span class="task-card-chevron"><i class="fa-solid fa-chevron-right"></i></span>
                                </div>
                            </summary>
                            <div class="task-card-body">
                                <?php if (!empty($task['description'])): ?>
                                    <div class="task-card-note text"><i class="fa-solid fa-message task-desc-icon"></i><span><?php echo htmlspecialchars(html_entity_decode($task['description'], ENT_QUOTES)); ?></span></div>
                                <?php endif; ?>
                                <div class="task-meta">
                                    <div class="task-meta-row">
                                        <span><span class="task-meta-label"><i class="fa-solid fa-clock task-meta-icon"></i></span> <?php echo htmlspecialchars($dueDisplay); ?></span>
                                    </div>
                                    <?php if (!empty($task['end_date'])): ?>
                                        <div class="task-meta-row">
                                            <span><span class="task-meta-label">End Date:</span> <?php echo htmlspecialchars(date('m/d/Y', strtotime($task['end_date']))); ?></span>
                                        </div>
                                    <?php endif; ?>
                                    <div class="task-meta-row">
                                        <span><span class="task-meta-label"><i class="fa-solid fa-table-list task-meta-icon"></i></span> <?php echo htmlspecialchars($task['category']); ?></span>
                                        <span><span class="task-meta-label"><i class="fa-solid fa-stopwatch task-meta-icon"></i></span> <?php echo htmlspecialchars($task['timing_mode'] === 'no_limit' ? 'None' : ucfirst($task['timing_mode'])); ?></span>
                                        <span>
                                            <?php
                                                $repeatLabel = 'Once';
                                                if ($task['recurrence'] === 'daily') {
                                                    $repeatLabel = 'Every Day';
                                                } elseif ($task['recurrence'] === 'weekly') {
                                                    $days = !empty($task['recurrence_days']) ? str_replace(',', ', ', $task['recurrence_days']) : 'Specific Days';
                                                    $repeatLabel = 'Specific Days (' . $days . ')';
                                                }
                                                $repeatIconClass = str_starts_with($repeatLabel, 'Specific Days') ? 'fa-solid fa-calendar-day' : 'fa-regular fa-calendar-days';
                                            ?>
                                            <span class="task-meta-label"><i class="<?php echo $repeatIconClass; ?> task-meta-icon"></i></span>
                                            <?php echo htmlspecialchars($repeatLabel); ?>
                                        </span>
                                    </div>
                                    <div class="task-meta-row">
                                        <span><span class="task-meta-label"><i class="fa-solid fa-camera task-meta-icon"></i></span> <?php echo !empty($task['photo_proof_required']) ? 'Required' : 'Not required'; ?></span>
                                    </div>
                                    <?php if (!empty($task['creator_display_name'])): ?>
                                        <div class="task-meta-row">
                                            <span><span class="task-meta-label"><i class="fa-solid fa-user-pen task-meta-icon"></i></span> <?php echo htmlspecialchars($task['creator_display_name']); ?></span>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($task['child_display_name'])): ?>
                                        <div class="task-meta-row">
                                            <?php $assignedAvatar = $childAvatarById[(int)($task['child_user_id'] ?? 0)] ?? 'images/default-avatar.png'; ?>
                                            <span><span class="task-meta-label"><img class="task-meta-avatar" src="<?php echo htmlspecialchars($assignedAvatar); ?>" alt="<?php echo htmlspecialchars($task['child_display_name']); ?>"></span> <?php echo htmlspecialchars($task['child_display_name']); ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($task['photo_proof'])): ?>
                                    <div class="task-description">
                                        <img src="<?php echo htmlspecialchars($task['photo_proof']); ?>" alt="Photo proof" class="task-photo-thumb" data-task-photo-src="<?php echo htmlspecialchars($task['photo_proof'], ENT_QUOTES); ?>">
                                    </div>
                                <?php endif; ?>
                                <?php if (!canCreateContent($_SESSION['user_id'])): ?>
                                    <?php if ($instance_status === 'completed'): ?>
                                        <p class="waiting-label">Waiting for approval</p>
                                    <?php elseif ($instance_status === 'rejected'): ?>
                                        <p class="waiting-label">Rejected</p>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <?php if (canCreateContent($_SESSION['user_id']) && canAddEditChild($_SESSION['user_id'])): ?>
                                    <?php $childName = $childNameById[(int)$task['child_user_id']] ?? 'Child'; ?>
                                    <?php
                                        $dueDateValue = !empty($task['due_date']) ? date('Y-m-d', strtotime($task['due_date'])) : date('Y-m-d');
                                        $dueTimeValue = !empty($task['due_date']) ? date('H:i', strtotime($task['due_date'])) : '';
                                        $repeatValue = $task['recurrence'] === 'daily' ? 'daily' : ($task['recurrence'] === 'weekly' ? 'weekly' : '');
                                    ?>
                                    <div class="task-card-footer">
                                        <button type="button"
                                                class="button task-card-primary"
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
                                                data-time-of-day="<?php echo htmlspecialchars($task['time_of_day'] ?? 'anytime', ENT_QUOTES); ?>"
                                                data-recurrence="<?php echo htmlspecialchars($repeatValue, ENT_QUOTES); ?>"
                                                data-recurrence-days="<?php echo htmlspecialchars($task['recurrence_days'] ?? '', ENT_QUOTES); ?>"
                                                data-category="<?php echo htmlspecialchars($task['category'] ?? '', ENT_QUOTES); ?>"
                                                data-timing-mode="<?php echo htmlspecialchars($task['timing_mode'] ?? '', ENT_QUOTES); ?>"
                                                data-timer-minutes="<?php echo (int)($task['timer_minutes'] ?? 0); ?>"
                                                data-photo-required="<?php echo (int)($task['photo_proof_required'] ?? 0); ?>">
                                            Edit Task
                                        </button>
                                        <div class="task-card-menu" data-task-menu>
                                            <button type="button" class="task-card-menu-toggle" aria-label="Open task actions" data-task-menu-toggle>
                                                <i class="fa-solid fa-ellipsis-vertical"></i>
                                            </button>
                                            <div class="task-card-menu-list">
                                                <button type="button"
                                                        class="task-card-menu-item"
                                                        data-task-duplicate-open
                                                        data-task-id="<?php echo $task['id']; ?>"
                                                        data-child-id="<?php echo (int)$task['child_user_id']; ?>"
                                                        data-child-name="<?php echo htmlspecialchars($childName, ENT_QUOTES); ?>"
                                                        data-title="<?php echo htmlspecialchars($task['title'], ENT_QUOTES); ?>"
                                                        data-description="<?php echo htmlspecialchars($task['description'], ENT_QUOTES); ?>"
                                                        data-start-date="<?php echo htmlspecialchars($dueDateValue, ENT_QUOTES); ?>"
                                                        data-due-time="<?php echo htmlspecialchars($dueTimeValue, ENT_QUOTES); ?>"
                                                        data-end-date="<?php echo !empty($task['end_date']) ? htmlspecialchars($task['end_date'], ENT_QUOTES) : ''; ?>"
                                                        data-points="<?php echo (int)$task['points']; ?>"
                                                        data-time-of-day="<?php echo htmlspecialchars($task['time_of_day'] ?? 'anytime', ENT_QUOTES); ?>"
                                                        data-recurrence="<?php echo htmlspecialchars($repeatValue, ENT_QUOTES); ?>"
                                                        data-recurrence-days="<?php echo htmlspecialchars($task['recurrence_days'] ?? '', ENT_QUOTES); ?>"
                                                        data-category="<?php echo htmlspecialchars($task['category'] ?? '', ENT_QUOTES); ?>"
                                                        data-timing-mode="<?php echo htmlspecialchars($task['timing_mode'] ?? '', ENT_QUOTES); ?>"
                                                        data-timer-minutes="<?php echo (int)($task['timer_minutes'] ?? 0); ?>"
                                                        data-photo-required="<?php echo (int)($task['photo_proof_required'] ?? 0); ?>">
                                                    <i class="fa-solid fa-clone"></i> Duplicate Task
                                                </button>
                                                <button type="button"
                                                        class="task-card-menu-item danger"
                                                        data-task-delete-open
                                                        data-task-id="<?php echo $task['id']; ?>"
                                                        data-child-name="<?php echo htmlspecialchars($childName, ENT_QUOTES); ?>"
                                                        data-title="<?php echo htmlspecialchars($task['title'], ENT_QUOTES); ?>">
                                                    <i class="fa-regular fa-trash-can"></i> Delete Forever
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </details>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </details>

                <details class="task-section-toggle" <?php echo !empty($completed_tasks) ? 'open' : ''; ?>>
                    <summary>
                        <span class="task-section-title"><span class="task-section-icon is-pending"><i class="fa-solid fa-square-check"></i></span>Pending Approval <span class="task-count-badge"><?php echo count($completed_tasks); ?></span></span>
                    </summary>
                    <div class="task-section-content">
                        <?php if (empty($completed_tasks)): ?>
                            <p>No tasks waiting approval.</p>
                        <?php else: ?>
                            <?php foreach ($completed_tasks as $task): ?>
                            <?php
                                $timeOfDay = $task['time_of_day'] ?? 'anytime';
                                $isOnce = empty($task['recurrence']);
                                $completedStamp = $task['completed_at'] ?? null;
                                if (!$completedStamp && !empty($task['instance_date'])) {
                                    $completedStamp = $task['instance_date'];
                                }
                                $completedLabel = '';
                                if (!empty($completedStamp)) {
                                    $completedLabel = !empty($task['completed_at'])
                                        ? date('m/d/Y h:i A', strtotime($completedStamp))
                                        : date('m/d/Y', strtotime($completedStamp));
                                }
                                $childName = $childNameById[(int)$task['child_user_id']] ?? 'Child';
                                $childDisplayName = $task['child_display_name'] ?? $childName;
                                $dueDateValue = !empty($task['due_date']) ? date('Y-m-d', strtotime($task['due_date'])) : date('Y-m-d');
                                $dueTimeValue = !empty($task['due_date']) ? date('H:i', strtotime($task['due_date'])) : '';
                                $repeatValue = $task['recurrence'] === 'daily' ? 'daily' : ($task['recurrence'] === 'weekly' ? 'weekly' : '');
                                if ($isOnce) {
                                    $dueDisplay = $task['due_date_formatted'];
                                } elseif ($timeOfDay === 'anytime') {
                                    $dueDisplay = 'Anytime';
                                } else {
                                    $timeText = !empty($task['due_date']) ? date('g:i A', strtotime($task['due_date'])) : '';
                                    if ($timeText === '12:00 AM') {
                                        $timeText = '';
                                    }
                                    $dueDisplay = $timeText !== '' ? $timeText : ucfirst($timeOfDay);
                                }
                              ?>
                            <details class="task-card" id="task-<?php echo (int) $task['id']; ?>" data-task-id="<?php echo $task['id']; ?>">
                                <summary class="task-card-summary">
                                    <div class="task-card-summary-left">
                                        <div class="task-card-icon"><i class="fa-solid fa-hourglass-half"></i></div>
                                        <div class="task-card-title-block">
                                            <div class="task-card-title-row">
                                                <div class="task-card-title"><?php echo htmlspecialchars($task['title']); ?></div>
                                                <?php if (!empty($childDisplayName)): ?>
                                                    <span class="task-card-child-pill"><?php echo htmlspecialchars($childDisplayName); ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="task-card-subtitle">
                                                <span class="task-card-status">Pending Approval</span>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="task-card-summary-right">
                                        <div class="task-card-points"><i class="fa-solid fa-coins"></i> <?php echo (int)$task['points']; ?></div>
                                        <span class="task-card-chevron"><i class="fa-solid fa-chevron-right"></i></span>
                                    </div>
                                </summary>
                                <div class="task-card-body">
                                    <?php if (!empty($task['description'])): ?>
                                        <div class="task-card-note text"><i class="fa-solid fa-message task-desc-icon"></i><span><?php echo htmlspecialchars($task['description']); ?></span></div>
                                    <?php endif; ?>
                                    <div class="task-meta">
                                        <?php if ($completedLabel !== ''): ?>
                                            <div class="task-meta-row">
                                                <span><span class="task-meta-label">Completed:</span> <?php echo htmlspecialchars($completedLabel); ?></span>
                                            </div>
                                        <?php endif; ?>
                                        <div class="task-meta-row">
                                            <span><span class="task-meta-label"><i class="fa-solid fa-clock task-meta-icon"></i></span> <?php echo htmlspecialchars($dueDisplay); ?></span>
                                        </div>
                                        <?php if (!empty($task['end_date'])): ?>
                                            <div class="task-meta-row">
                                                <span><span class="task-meta-label">End Date:</span> <?php echo htmlspecialchars(date('m/d/Y', strtotime($task['end_date']))); ?></span>
                                            </div>
                                        <?php endif; ?>
                                        <div class="task-meta-row">
                                            <span><span class="task-meta-label"><i class="fa-solid fa-table-list task-meta-icon"></i></span> <?php echo htmlspecialchars($task['category']); ?></span>
                                            <span><span class="task-meta-label"><i class="fa-solid fa-stopwatch task-meta-icon"></i></span> <?php echo htmlspecialchars($task['timing_mode'] === 'no_limit' ? 'None' : ucfirst($task['timing_mode'])); ?></span>
                                            <span>
                                                <?php
                                                    $repeatLabel = 'Once';
                                                    if ($task['recurrence'] === 'daily') {
                                                        $repeatLabel = 'Every Day';
                                                    } elseif ($task['recurrence'] === 'weekly') {
                                                        $days = !empty($task['recurrence_days']) ? str_replace(',', ', ', $task['recurrence_days']) : 'Specific Days';
                                                        $repeatLabel = 'Specific Days (' . $days . ')';
                                                    }
                                                    $repeatIconClass = str_starts_with($repeatLabel, 'Specific Days') ? 'fa-solid fa-calendar-day' : 'fa-regular fa-calendar-days';
                                                ?>
                                                <span class="task-meta-label"><i class="<?php echo $repeatIconClass; ?> task-meta-icon"></i></span>
                                                <?php echo htmlspecialchars($repeatLabel); ?>
                                            </span>
                                        </div>
                                        <div class="task-meta-row">
                                            <span><span class="task-meta-label"><i class="fa-solid fa-camera task-meta-icon"></i></span> <?php echo !empty($task['photo_proof_required']) ? 'Required' : 'Not required'; ?></span>
                                        </div>
                                        <?php if (!empty($task['creator_display_name'])): ?>
                                            <div class="task-meta-row">
                                                <span><span class="task-meta-label"><i class="fa-solid fa-user-pen task-meta-icon"></i></span> <?php echo htmlspecialchars($task['creator_display_name']); ?></span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <?php if (!empty($task['photo_proof'])): ?>
                                        <div class="task-description">
                                            <img src="<?php echo htmlspecialchars($task['photo_proof']); ?>" alt="Photo proof" class="task-photo-thumb" data-task-photo-src="<?php echo htmlspecialchars($task['photo_proof'], ENT_QUOTES); ?>">
                                        </div>
                                    <?php endif; ?>
                                    <?php if (canCreateContent($_SESSION['user_id']) && canAddEditChild($_SESSION['user_id'])): ?>
                                <form method="POST" action="task.php" id="approve-form-<?php echo (int) $task['id']; ?>">
                                    <input type="hidden" name="task_id" value="<?php echo $task['id']; ?>">
                                    <?php if (!empty($task['instance_date'])): ?>
                                        <input type="hidden" name="instance_date" value="<?php echo htmlspecialchars($task['instance_date']); ?>">
                                    <?php endif; ?>
                                    <button type="submit" name="approve_task" class="button">Review &amp; Approve</button>
                                </form>
                                <form method="POST" action="task.php" class="task-reject-form" id="reject-form-<?php echo (int) $task['id']; ?>">
                                    <input type="hidden" name="task_id" value="<?php echo $task['id']; ?>">
                                    <input type="hidden" name="reject_task" value="1">
                                    <?php if (!empty($task['instance_date'])): ?>
                                        <input type="hidden" name="instance_date" value="<?php echo htmlspecialchars($task['instance_date']); ?>">
                                    <?php endif; ?>
                                    <label for="reject_note_<?php echo (int) $task['id']; ?>">Rejection note (optional)</label>
                                    <textarea id="reject_note_<?php echo (int) $task['id']; ?>" name="reject_note" placeholder="Explain why this task was rejected."></textarea>
                                </form>
                                <div class="task-reject-bar">
                                    <div class="task-reject-actions">
                                        <button type="submit" name="reject_action" value="reactivate" class="button secondary" form="reject-form-<?php echo (int) $task['id']; ?>">Reject &amp; Reactivate</button>
                                        <button type="submit" name="reject_action" value="close" class="button danger" form="reject-form-<?php echo (int) $task['id']; ?>">Reject &amp; Close</button>
                                    </div>
                                    <div class="task-card-menu" data-task-menu>
                                        <button type="button" class="task-card-menu-toggle" aria-label="Open task actions" data-task-menu-toggle>
                                            <i class="fa-solid fa-ellipsis-vertical"></i>
                                        </button>
                                        <div class="task-card-menu-list">
                                            <button type="button"
                                                    class="task-card-menu-item"
                                                    data-task-duplicate-open
                                                    data-task-id="<?php echo $task['id']; ?>"
                                                    data-child-id="<?php echo (int)$task['child_user_id']; ?>"
                                                    data-child-name="<?php echo htmlspecialchars($childName, ENT_QUOTES); ?>"
                                                    data-title="<?php echo htmlspecialchars($task['title'], ENT_QUOTES); ?>"
                                                    data-description="<?php echo htmlspecialchars($task['description'], ENT_QUOTES); ?>"
                                                    data-start-date="<?php echo htmlspecialchars($dueDateValue, ENT_QUOTES); ?>"
                                                    data-due-time="<?php echo htmlspecialchars($dueTimeValue, ENT_QUOTES); ?>"
                                                    data-end-date="<?php echo !empty($task['end_date']) ? htmlspecialchars($task['end_date'], ENT_QUOTES) : ''; ?>"
                                                    data-points="<?php echo (int)$task['points']; ?>"
                                                    data-time-of-day="<?php echo htmlspecialchars($task['time_of_day'] ?? 'anytime', ENT_QUOTES); ?>"
                                                    data-recurrence="<?php echo htmlspecialchars($repeatValue, ENT_QUOTES); ?>"
                                                    data-recurrence-days="<?php echo htmlspecialchars($task['recurrence_days'] ?? '', ENT_QUOTES); ?>"
                                                    data-category="<?php echo htmlspecialchars($task['category'] ?? '', ENT_QUOTES); ?>"
                                                    data-timing-mode="<?php echo htmlspecialchars($task['timing_mode'] ?? '', ENT_QUOTES); ?>"
                                                    data-timer-minutes="<?php echo (int)($task['timer_minutes'] ?? 0); ?>"
                                                    data-photo-required="<?php echo (int)($task['photo_proof_required'] ?? 0); ?>">
                                                <i class="fa-solid fa-clone"></i> Duplicate Task
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                    <?php else: ?>
                                        <p class="waiting-label">Waiting for approval</p>
                                    <?php endif; ?>
                                </div>
                            </details>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </details>

                <details class="task-section-toggle" data-approved-section>
                    <summary>
                        <span class="task-section-title"><span class="task-section-icon is-approved"><i class="fa-solid fa-circle-check"></i></span>Approved Tasks <span class="task-count-badge"><?php echo count($approved_tasks); ?></span></span>
                    </summary>
                    <div class="task-section-content">
                        <?php if (empty($approved_tasks)): ?>
                            <p>No approved tasks.</p>
                        <?php else: ?>
                            <?php $approvedIndex = 0; ?>
                            <?php $isParentView = canCreateContent($_SESSION['user_id']); ?>
                            <?php foreach ($approved_tasks as $task): ?>
                            <?php
                                $approvedIndex++;
                                $hideApproved = $isParentView && $approvedIndex > 5;
                            ?>
                            <?php
                                $timeOfDay = $task['time_of_day'] ?? 'anytime';
                                $isOnce = empty($task['recurrence']);
                                $approvedStamp = $task['approved_at'] ?? $task['completed_at'] ?? null;
                                $approvedDateLabel = $approvedStamp ? date('m/d/Y', strtotime($approvedStamp)) : '';
                                if ($isOnce) {
                                    $dueDisplay = $task['due_date_formatted'];
                                } elseif ($timeOfDay === 'anytime') {
                                    $dueDisplay = 'Anytime';
                                } else {
                                    $timeText = !empty($task['due_date']) ? date('g:i A', strtotime($task['due_date'])) : '';
                                    if ($timeText === '12:00 AM') {
                                        $timeText = '';
                                    }
                                    $dueDisplay = $timeText !== '' ? $timeText : ucfirst($timeOfDay);
                                }
                                $childDisplayName = $task['child_display_name'] ?? ($childNameById[(int)($task['child_user_id'] ?? 0)] ?? '');
                              ?>
                            <details class="task-card" id="task-<?php echo (int) $task['id']; ?>" data-task-id="<?php echo $task['id']; ?>" data-approved-card data-approved-index="<?php echo $approvedIndex; ?>"<?php echo $hideApproved ? ' style="display:none;"' : ''; ?>>
                                <summary class="task-card-summary">
                                    <div class="task-card-summary-left">
                                        <div class="task-card-icon"><i class="fa-regular fa-circle-check"></i></div>
                                        <div class="task-card-title-block">
                                            <div class="task-card-title-row">
                                                <div class="task-card-title"><?php echo htmlspecialchars($task['title']); ?></div>
                                                <?php if (!empty($childDisplayName)): ?>
                                                    <span class="task-card-child-pill"><?php echo htmlspecialchars($childDisplayName); ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="task-card-subtitle">
                                                <span class="task-card-status is-success">Approved</span>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="task-card-summary-right">
                                        <div class="task-card-points"><i class="fa-solid fa-coins"></i> <?php echo (int)$task['points']; ?></div>
                                        <span class="task-card-chevron"><i class="fa-solid fa-chevron-right"></i></span>
                                    </div>
                                </summary>
                                <div class="task-card-body">
                                    <?php if (!empty($task['description'])): ?>
                                        <div class="task-card-note text"><i class="fa-solid fa-message task-desc-icon"></i><span><?php echo htmlspecialchars($task['description']); ?></span></div>
                                    <?php endif; ?>
                                    <div class="task-meta">
                                        <?php if (!$isOnce && $approvedDateLabel !== ''): ?>
                                            <div class="task-meta-row">
                                                <span><span class="task-meta-label">Approved date:</span> <?php echo htmlspecialchars($approvedDateLabel); ?></span>
                                            </div>
                                        <?php endif; ?>
                                        <div class="task-meta-row">
                                            <span><span class="task-meta-label"><i class="fa-solid fa-clock task-meta-icon"></i></span> <?php echo htmlspecialchars($dueDisplay); ?></span>
                                        </div>
                                        <?php if (!empty($task['end_date'])): ?>
                                            <div class="task-meta-row">
                                                <span><span class="task-meta-label">End Date:</span> <?php echo htmlspecialchars(date('m/d/Y', strtotime($task['end_date']))); ?></span>
                                            </div>
                                        <?php endif; ?>
                                        <div class="task-meta-row">
                                            <span><span class="task-meta-label"><i class="fa-solid fa-table-list task-meta-icon"></i></span> <?php echo htmlspecialchars($task['category']); ?></span>
                                            <span><span class="task-meta-label"><i class="fa-solid fa-stopwatch task-meta-icon"></i></span> <?php echo htmlspecialchars($task['timing_mode'] === 'no_limit' ? 'None' : ucfirst($task['timing_mode'])); ?></span>
                                            <span>
                                                <?php
                                                    $repeatLabel = 'Once';
                                                    if ($task['recurrence'] === 'daily') {
                                                        $repeatLabel = 'Every Day';
                                                    } elseif ($task['recurrence'] === 'weekly') {
                                                        $days = !empty($task['recurrence_days']) ? str_replace(',', ', ', $task['recurrence_days']) : 'Specific Days';
                                                        $repeatLabel = 'Specific Days (' . $days . ')';
                                                    }
                                                    $repeatIconClass = str_starts_with($repeatLabel, 'Specific Days') ? 'fa-solid fa-calendar-day' : 'fa-regular fa-calendar-days';
                                                ?>
                                                <span class="task-meta-label"><i class="<?php echo $repeatIconClass; ?> task-meta-icon"></i></span>
                                                <?php echo htmlspecialchars($repeatLabel); ?>
                                            </span>
                                        </div>
                                        <div class="task-meta-row">
                                            <span><span class="task-meta-label"><i class="fa-solid fa-camera task-meta-icon"></i></span> <?php echo !empty($task['photo_proof_required']) ? 'Required' : 'Not required'; ?></span>
                                        </div>
                                        <?php if (!empty($task['photo_proof_required']) && !empty($task['photo_proof'])): ?>
                                            <div class="task-photo-proof">
                                                <div class="task-photo-proof-label">
                                                    <i class="fa-solid fa-camera task-meta-icon"></i>
                                                    <span>Photo proof:</span>
                                                </div>
                                                <img src="<?php echo htmlspecialchars($task['photo_proof']); ?>" alt="Photo proof" class="task-photo-thumb" data-task-photo-src="<?php echo htmlspecialchars($task['photo_proof'], ENT_QUOTES); ?>">
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($task['creator_display_name'])): ?>
                                            <div class="task-meta-row">
                                                <span><span class="task-meta-label"><i class="fa-solid fa-user-pen task-meta-icon"></i></span> <?php echo htmlspecialchars($task['creator_display_name']); ?></span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </details>
                            <?php endforeach; ?>
                            <?php if ($isParentView && count($approved_tasks) > 5): ?>
                                <div class="task-approved-view-more">
                                    <button type="button" class="button secondary" data-approved-view-more data-approved-step="5">View more</button>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </details>
                <details class="task-section-toggle">
                    <summary>
                        <span class="task-section-title"><span class="task-section-icon is-expired"><i class="fa-solid fa-calendar-xmark"></i></span>Expired Tasks <span class="task-count-badge"><?php echo count($expired_tasks); ?></span></span>
                    </summary>
                    <div class="task-section-content">
                        <?php if (empty($expired_tasks)): ?>
                            <p>No expired tasks.</p>
                        <?php else: ?>
                            <?php foreach ($expired_tasks as $task): ?>
                                <?php
                                    $dueLabel = !empty($task['due_date']) ? date('m/d/Y', strtotime($task['due_date'])) : 'No due date';
                                ?>
                                <details class="task-card" id="task-<?php echo (int) $task['id']; ?>" data-task-id="<?php echo $task['id']; ?>">
                                    <summary class="task-card-summary">
                                        <div class="task-card-summary-left">
                                            <div class="task-card-icon"><i class="fa-regular fa-calendar-xmark"></i></div>
                                            <div class="task-card-title-block">
                                                <div class="task-card-title-row">
                                                    <div class="task-card-title"><?php echo htmlspecialchars($task['title']); ?></div>
                                                </div>
                                                <div class="task-card-subtitle">
                                                    <span class="task-card-status is-muted">Expired</span>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="task-card-summary-right">
                                            <div class="task-card-points"><i class="fa-solid fa-coins"></i> <?php echo (int)$task['points']; ?></div>
                                            <span class="task-card-chevron"><i class="fa-solid fa-chevron-right"></i></span>
                                        </div>
                                    </summary>
                                    <div class="task-card-body">
                                        <?php if (!empty($task['description'])): ?>
                                            <div class="task-card-note text"><i class="fa-solid fa-message task-desc-icon"></i><span><?php echo htmlspecialchars($task['description']); ?></span></div>
                                        <?php endif; ?>
                                        <div class="task-meta">
                                            <div class="task-meta-row">
                                                <span><span class="task-meta-label"><i class="fa-solid fa-clock task-meta-icon"></i></span> <?php echo htmlspecialchars($dueLabel); ?></span>
                                            </div>
                                            <?php if (!empty($task['end_date'])): ?>
                                                <div class="task-meta-row">
                                                    <span><span class="task-meta-label">End Date:</span> <?php echo htmlspecialchars(date('m/d/Y', strtotime($task['end_date']))); ?></span>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </details>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </details>
            <?php endif; ?>
        </div>
<section class="task-calendar-section">
            <div class="task-calendar-card">
                <div class="calendar-header">
                    <div>
                        <h2>Weekly Calendar</h2>
                        <?php if (canCreateContent($_SESSION['user_id'])): ?>
                            <p class="calendar-subtitle">Weekly schedule view for current task filters.</p>
                        <?php endif; ?>
                    </div>
                    <div class="calendar-nav">
                        <div class="calendar-view-toggle" role="group" aria-label="Calendar view">
                            <button type="button" class="calendar-view-button active" data-calendar-view="calendar" aria-pressed="true" title="Calendar view">
                                <i class="fa-solid fa-calendar-days"></i>
                            </button>
                            <button type="button" class="calendar-view-button" data-calendar-view="list" aria-pressed="false" title="List view">
                                <i class="fa-solid fa-list"></i>
                            </button>
                        </div>
                        <button type="button" class="calendar-nav-button" data-week-nav="-1">Previous Week</button>
                        <div class="calendar-range" data-week-range></div>
                        <button type="button" class="calendar-nav-button" data-week-nav="1">Next Week</button>
                        <?php if (!$calendarPremium): ?>
                            <span class="calendar-premium-note">Premium feature</span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="task-week-calendar" data-task-calendar>
                    <div class="task-week-scroll">
                        <div class="week-days week-days-header" data-week-days></div>
                        <div class="week-grid" data-week-grid></div>
                    </div>
                    <div class="calendar-empty" data-calendar-empty>No tasks match the selected children for this week.</div>
                </div>
                <div class="task-week-list" data-task-list></div>
            </div>
        </section>
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
                                <div class="form-group" data-once-date-wrapper>
                                    <label>Task Date</label>
                                    <input type="date" name="start_date" value="">
                                </div>
                                <div class="form-group" data-recurrence-days-wrapper>
                                    <div class="repeat-days-label">Specific Days</div>
                                    <div class="repeat-days-grid">
                                        <label class="repeat-day"><input type="checkbox" name="recurrence_days[]" value="Sun"><span>Sun</span></label>
                                        <label class="repeat-day"><input type="checkbox" name="recurrence_days[]" value="Mon"><span>Mon</span></label>
                                        <label class="repeat-day"><input type="checkbox" name="recurrence_days[]" value="Tue"><span>Tue</span></label>
                                        <label class="repeat-day"><input type="checkbox" name="recurrence_days[]" value="Wed"><span>Wed</span></label>
                                        <label class="repeat-day"><input type="checkbox" name="recurrence_days[]" value="Thu"><span>Thu</span></label>
                                        <label class="repeat-day"><input type="checkbox" name="recurrence_days[]" value="Fri"><span>Fri</span></label>
                                        <label class="repeat-day"><input type="checkbox" name="recurrence_days[]" value="Sat"><span>Sat</span></label>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label>Time of Day</label>
                                    <select name="time_of_day">
                                        <option value="anytime">Anytime</option>
                                        <option value="morning">Morning</option>
                                        <option value="afternoon">Afternoon</option>
                                        <option value="evening">Evening</option>
                                    </select>
                                </div>
                                <div class="form-group toggle-field" data-end-toggle-field>
                                    <span class="toggle-label">End Date</span>
                                    <label class="toggle-row">
                                        <span class="toggle-switch">
                                            <input type="checkbox" name="end_date_enabled" data-end-date-toggle>
                                            <span class="toggle-slider"></span>
                                        </span>
                                    </label>
                                </div>
                                <div class="form-group toggle-field">
                                    <span class="toggle-label">Photo Proof</span>
                                    <label class="toggle-row">
                                        <span class="toggle-switch">
                                            <input type="checkbox" name="photo_proof_required" value="1">
                                            <span class="toggle-slider"></span>
                                        </span>
                                    </label>
                                </div>
                                <div class="form-group end-date-field" data-end-date-wrapper>
                                    <label>End Date</label>
                                    <input type="date" name="end_date" value="">
                                </div>
                                <div class="form-group" data-due-time-wrapper>
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
        <div class="task-modal" data-task-proof-modal>
            <div class="task-modal-card" role="dialog" aria-modal="true" aria-labelledby="task-proof-title">
                <header>
                    <h2 id="task-proof-title">Photo Proof</h2>
                    <button type="button" class="task-modal-close" aria-label="Close photo proof" data-task-proof-close>&times;</button>
                </header>
                <div class="task-modal-body">
                    <p>Please upload a photo to complete this task.</p>
                    <form method="POST" action="task.php" enctype="multipart/form-data" data-task-proof-form>
                        <input type="hidden" name="task_id" value="">
                        <input type="hidden" name="instance_date" value="">
                        <div class="form-group">
                            <label for="photo_proof">Photo Proof</label>
                            <input type="file" id="photo_proof" name="photo_proof" accept="image/*" capture="environment" required>
                        </div>
                        <div class="modal-actions">
                            <button type="submit" name="complete_task" class="button">Complete Task</button>
                            <button type="button" class="button secondary" data-task-proof-close>Cancel</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <div class="task-modal" data-task-photo-modal>
            <div class="task-modal-card" role="dialog" aria-modal="true" aria-labelledby="task-photo-title">
                <header>
                    <h2 id="task-photo-title">Photo Proof</h2>
                    <button type="button" class="task-modal-close" aria-label="Close photo preview" data-task-photo-close>&times;</button>
                </header>
                <div class="task-modal-body">
                    <img src="" alt="Task photo proof" class="task-photo-preview" data-task-photo-preview>
                </div>
            </div>
        </div>
        <div class="task-modal" data-task-preview-modal>
            <div class="task-modal-card" role="dialog" aria-modal="true" aria-labelledby="task-preview-title">
                <header>
                    <h2 id="task-preview-title">Task Details</h2>
                    <button type="button" class="task-modal-close" aria-label="Close task details" data-task-preview-close>&times;</button>
                </header>
                <div class="task-modal-body" data-task-preview-body></div>
            </div>
        </div>
        <div class="floating-task-timer" data-floating-timer aria-live="polite">
            <div class="floating-task-header">
                <div>
                    <div class="floating-task-title" data-floating-title></div>
                    <div class="floating-task-points" data-floating-points></div>
                </div>
                <div class="floating-task-header-actions">
                    <button type="button" class="floating-task-icon" data-floating-open aria-label="Open task">
                        <i class="fa-solid fa-up-right-from-square"></i>
                    </button>
                    <button type="button" class="floating-task-icon" data-floating-close aria-label="Hide timer">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>
            </div>
            <div class="floating-task-time" data-timer-display data-task-id=""></div>
            <div class="floating-task-actions">
                <button type="button" class="floating-task-pause" data-timer-action="pause-toggle" data-task-id="" aria-label="Pause timer">
                    <i class="fa-solid fa-pause"></i>
                </button>
                <button type="button" class="button" data-floating-finish>Finish Task</button>
            </div>
        </div>
        <div class="help-modal" data-help-modal>
            <div class="help-card" role="dialog" aria-modal="true" aria-labelledby="help-title">
                <header>
                    <h2 id="help-title">Task Help</h2>
                    <button type="button" class="help-close" data-help-close aria-label="Close help">&times;</button>
                </header>
                <div class="help-body">
                    <?php if (canCreateContent($_SESSION['user_id'])): ?>
                        <section class="help-section">
                            <h3>Parent view</h3>
                            <ul>
                                <li>Create one-time or repeating tasks with optional end dates, time-of-day, and due time.</li>
                                <li>Use the calendar or list view and click an item to open Task Details.</li>
                                <li>Start timers in the Task Details modal; a floating timer appears if you close the modal.</li>
                                <li>Finish tasks from Task Details to auto-approve and award points.</li>
                                <li>Approve or reject completed tasks (with optional notes) in Waiting Approval.</li>
                            </ul>
                        </section>
                    <?php else: ?>
                        <section class="help-section">
                            <h3>Child view</h3>
                            <ul>
                                <li>Tap a task in the calendar or list view to open Task Details.</li>
                                <li>Start timers from Task Details; the floating timer keeps running if you close it.</li>
                                <li>Finish tasks in Task Details. Photo proof is required when toggled on.</li>
                                <li>Completed tasks wait for parent approval before points are awarded.</li>
                            </ul>
                        </section>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>
    <?php if (canCreateContent($_SESSION['user_id'])): ?>
        <div class="task-create-modal" data-task-create-modal>
            <div class="task-create-card" role="dialog" aria-modal="true" aria-labelledby="task-create-title">
                <header>
                    <h2 id="task-create-title">Create Task</h2>
                    <button type="button" class="task-modal-close" aria-label="Close create task" data-task-create-close>&times;</button>
                </header>
                <div class="task-create-body">
                    <form method="POST" action="task.php" enctype="multipart/form-data" data-create-task-form>
                        <?php $autoSelectChildId = count($children) === 1 ? (int) $children[0]['child_user_id'] : null; ?>
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Child</label>
                                <div class="child-select-grid">
                                    <?php foreach ($children as $index => $child): ?>
                                        <label class="child-select-card">
                                            <input type="checkbox" name="child_user_ids[]" value="<?php echo (int) $child['child_user_id']; ?>"<?php echo $autoSelectChildId === (int) $child['child_user_id'] ? ' checked' : ''; ?>>
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
                            <div class="form-group repeat-group">
                                <label for="recurrence">Repeat</label>
                                <select id="recurrence" name="recurrence">
                                    <option value="">Once</option>
                                    <option value="daily">Every Day</option>
                                    <option value="weekly">Specific Days</option>
                                </select>
                                <div class="repeat-days" data-create-recurrence-days>
                                    <div class="repeat-days-label">Specific Days</div>
                                    <div class="repeat-days-grid">
                                        <label class="repeat-day"><input type="checkbox" name="recurrence_days[]" value="Sun"><span>Sun</span></label>
                                        <label class="repeat-day"><input type="checkbox" name="recurrence_days[]" value="Mon"><span>Mon</span></label>
                                        <label class="repeat-day"><input type="checkbox" name="recurrence_days[]" value="Tue"><span>Tue</span></label>
                                        <label class="repeat-day"><input type="checkbox" name="recurrence_days[]" value="Wed"><span>Wed</span></label>
                                        <label class="repeat-day"><input type="checkbox" name="recurrence_days[]" value="Thu"><span>Thu</span></label>
                                        <label class="repeat-day"><input type="checkbox" name="recurrence_days[]" value="Fri"><span>Fri</span></label>
                                        <label class="repeat-day"><input type="checkbox" name="recurrence_days[]" value="Sat"><span>Sat</span></label>
                                    </div>
                                </div>
                            </div>
                            <div class="form-group" data-once-date-wrapper>
                                <label for="start_date">Task Date</label>
                                <input type="date" id="start_date" name="start_date" value="<?php echo date('Y-m-d'); ?>">
                            </div>
                            <div class="form-group">
                                <label for="time_of_day">Time of Day</label>
                                <select id="time_of_day" name="time_of_day">
                                    <option value="anytime" selected>Anytime</option>
                                    <option value="morning">Morning</option>
                                    <option value="afternoon">Afternoon</option>
                                    <option value="evening">Evening</option>
                                </select>
                            </div>
                            <div class="form-group toggle-field" data-create-end-toggle>
                                <span class="toggle-label">End Date</span>
                                <label class="toggle-row">
                                    <span class="toggle-switch">
                                        <input type="checkbox" name="end_date_enabled" data-end-date-toggle>
                                        <span class="toggle-slider"></span>
                                    </span>
                                </label>
                            </div>
                            <div class="form-group toggle-field">
                                <span class="toggle-label">Photo Proof</span>
                                <label class="toggle-row">
                                    <span class="toggle-switch">
                                        <input type="checkbox" name="photo_proof_required" value="1">
                                        <span class="toggle-slider"></span>
                                    </span>
                                </label>
                            </div>
                            <div class="form-group end-date-field" data-create-end-date>
                                <label for="end_date">End Date</label>
                                <input type="date" id="end_date" name="end_date" value="">
                            </div>
                            <div class="form-group" data-due-time-wrapper>
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
                </div>
            </div>
        </div>
        <?php endif; ?>
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
  <script src="js/number-stepper.js?v=3.26.0" defer></script>
<?php if (!empty($isParentNotificationUser)): ?>
    <?php include __DIR__ . '/includes/notifications_parent.php'; ?>
<?php endif; ?>
<?php if (!empty($isChildNotificationUser)): ?>
    <?php include __DIR__ . '/includes/notifications_child.php'; ?>
<?php endif; ?>
</body>
</html>



