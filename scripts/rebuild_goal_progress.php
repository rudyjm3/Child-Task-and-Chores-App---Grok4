<?php
// Rebuild goal progress and normalize statuses after logic changes.
// Usage:
//   php scripts/rebuild_goal_progress.php
//   php scripts/rebuild_goal_progress.php --dry-run
//   php scripts/rebuild_goal_progress.php --goal-id=123

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once __DIR__ . '/../includes/functions.php';

$dryRun = in_array('--dry-run', $argv, true);
$goalId = null;
foreach ($argv as $arg) {
    if (strpos($arg, '--goal-id=') === 0) {
        $goalId = (int) substr($arg, strlen('--goal-id='));
    }
}

$goals = [];
if ($goalId) {
    $stmt = $db->prepare("SELECT * FROM goals WHERE id = :goal_id");
    $stmt->execute([':goal_id' => $goalId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $goals[] = $row;
    }
} else {
    $stmt = $db->query("SELECT * FROM goals");
    $goals = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$progressStmt = $db->prepare(
    "INSERT INTO goal_progress (goal_id, child_user_id, current_count, current_streak, last_progress_date, next_needed_hint)
     VALUES (:goal_id, :child_id, :current_count, :current_streak, :last_progress_date, :next_needed_hint)
     ON DUPLICATE KEY UPDATE
        child_user_id = VALUES(child_user_id),
        current_count = VALUES(current_count),
        current_streak = VALUES(current_streak),
        last_progress_date = VALUES(last_progress_date),
        next_needed_hint = VALUES(next_needed_hint)"
);

$statusStmt = $db->prepare(
    "UPDATE goals
     SET status = :status,
         requested_at = :requested_at,
         completed_at = :completed_at,
         rejected_at = :rejected_at,
         rejection_comment = :rejection_comment
     WHERE id = :goal_id"
);

$stats = [
    'scanned' => 0,
    'progress_updated' => 0,
    'status_changed' => 0,
    'status_unchanged' => 0,
    'skipped_parent_reject' => 0,
    'manual_goals' => 0
];

$now = date('Y-m-d H:i:s');

foreach ($goals as $goal) {
    $stats['scanned']++;
    $goalId = (int) ($goal['id'] ?? 0);
    $childId = (int) ($goal['child_user_id'] ?? 0);
    if ($goalId <= 0 || $childId <= 0) {
        continue;
    }

    $goalType = $goal['goal_type'] ?? 'manual';
    $status = $goal['status'] ?? 'active';
    $rejectionComment = (string) ($goal['rejection_comment'] ?? '');
    $isIncomplete = $status === 'rejected' && stripos($rejectionComment, 'Incomplete') === 0;
    $isParentRejected = $status === 'rejected' && !$isIncomplete;

    $calcGoal = $goal;
    if ($goalType !== 'manual') {
        $calcGoal['status'] = 'active';
    }

    $progress = calculateGoalProgress($calcGoal, $childId);

    if (!$dryRun) {
        $progressStmt->execute([
            ':goal_id' => $goalId,
            ':child_id' => $childId,
            ':current_count' => (int) ($progress['current'] ?? 0),
            ':current_streak' => (int) ($progress['current_streak'] ?? 0),
            ':last_progress_date' => $progress['last_progress_date'],
            ':next_needed_hint' => $progress['next_needed']
        ]);
    }
    $stats['progress_updated']++;

    if ($goalType === 'manual') {
        $stats['manual_goals']++;
        continue;
    }
    if ($isParentRejected) {
        $stats['skipped_parent_reject']++;
        continue;
    }

    $requiresApproval = !empty($goal['requires_parent_approval']);
    $isExpired = false;
    if (!empty($goal['end_date'])) {
        $endStamp = strtotime($goal['end_date']);
        if ($endStamp && $endStamp < time()) {
            $isExpired = true;
        }
    }

    if (!empty($progress['is_met'])) {
        $desiredStatus = $requiresApproval ? 'pending_approval' : 'completed';
    } else {
        $desiredStatus = $isExpired ? 'rejected' : 'active';
    }

    if ($desiredStatus === $status) {
        $stats['status_unchanged']++;
        continue;
    }

    $requestedAt = $goal['requested_at'] ?? null;
    $completedAt = $goal['completed_at'] ?? null;
    $rejectedAt = $goal['rejected_at'] ?? null;
    $rejectionCommentNew = $goal['rejection_comment'] ?? null;

    if ($desiredStatus === 'active') {
        $requestedAt = null;
        $completedAt = null;
        if ($isIncomplete || stripos((string) $rejectionCommentNew, 'Incomplete') === 0) {
            $rejectedAt = null;
            $rejectionCommentNew = null;
        }
    } elseif ($desiredStatus === 'pending_approval') {
        $requestedAt = $requestedAt ?: $now;
        $completedAt = null;
        $rejectedAt = null;
        $rejectionCommentNew = null;
    } elseif ($desiredStatus === 'completed') {
        $completedAt = $completedAt ?: $now;
        $requestedAt = null;
        $rejectedAt = null;
        $rejectionCommentNew = null;
    } elseif ($desiredStatus === 'rejected') {
        $rejectedAt = $rejectedAt ?: $now;
        $requestedAt = null;
        $completedAt = null;
        if ($rejectionCommentNew === null || $rejectionCommentNew === '' || stripos((string) $rejectionCommentNew, 'Incomplete') === 0) {
            $rejectionCommentNew = 'Incomplete: End date reached before completing the goal.';
        }
    }

    if (!$dryRun) {
        $statusStmt->execute([
            ':status' => $desiredStatus,
            ':requested_at' => $requestedAt,
            ':completed_at' => $completedAt,
            ':rejected_at' => $rejectedAt,
            ':rejection_comment' => $rejectionCommentNew,
            ':goal_id' => $goalId
        ]);
    }
    $stats['status_changed']++;
}

echo "Goal rebuild complete.\n";
echo "Scanned: {$stats['scanned']}\n";
echo "Progress updated: {$stats['progress_updated']}\n";
echo "Status changed: {$stats['status_changed']}\n";
echo "Status unchanged: {$stats['status_unchanged']}\n";
echo "Manual goals skipped: {$stats['manual_goals']}\n";
echo "Parent-rejected skipped: {$stats['skipped_parent_reject']}\n";
echo $dryRun ? "Dry run: no DB changes were written.\n" : "DB changes written.\n";

