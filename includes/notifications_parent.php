
<?php
if (!isset($isParentNotificationUser) || !$isParentNotificationUser) {
    return;
}

$parentNew = $parentNew ?? [];
$parentRead = $parentRead ?? [];
$parentDeleted = $parentDeleted ?? [];
$parentNotificationActionSummary = $parentNotificationActionSummary ?? '';
$parentNotificationActionTab = $parentNotificationActionTab ?? '';

if (!isset($formatParentNotificationMessage)) {
    $formatParentNotificationMessage = static function (array $note): string {
        $message = (string) ($note['message'] ?? '');
        $type = (string) ($note['type'] ?? '');
        $highlight = static function (string $text, int $start, int $length): string {
            $prefix = substr($text, 0, $start);
            $title = substr($text, $start, $length);
            $suffix = substr($text, $start + $length);
            return htmlspecialchars($prefix)
                . '<span class="parent-notification-title">' . htmlspecialchars($title) . '</span>'
                . htmlspecialchars($suffix);
        };

        if ($type === 'reward_redeemed') {
            if (preg_match('/"([^"]+)"/', $message, $match, PREG_OFFSET_CAPTURE)) {
                return $highlight($message, $match[1][1], strlen($match[1][0]));
            }
        }

        if (in_array($type, ['routine_completed', 'task_completed'], true)) {
            if (preg_match('/\\bcompleted\\s+([^\\.]+)\\./', $message, $match, PREG_OFFSET_CAPTURE)) {
                return $highlight($message, $match[1][1], strlen($match[1][0]));
            }
        }

        if (in_array($type, ['task_approved', 'task_rejected', 'task_rejected_closed', 'goal_completed', 'goal_ready', 'goal_reward_earned', 'reward_denied', 'reward_fulfilled'], true)) {
            if (preg_match('/:\\s*([^|]+?)(?=\\s*(\\||$))/', $message, $match, PREG_OFFSET_CAPTURE)) {
                return $highlight($message, $match[1][1], strlen($match[1][0]));
            }
        }

        if (preg_match('/"([^"]+)"/', $message, $match, PREG_OFFSET_CAPTURE)) {
            return $highlight($message, $match[1][1], strlen($match[1][0]));
        }
        if (preg_match('/\\bcompleted\\s+([^\\.]+)\\./', $message, $match, PREG_OFFSET_CAPTURE)) {
            return $highlight($message, $match[1][1], strlen($match[1][0]));
        }
        if (preg_match('/:\\s*([^|]+?)(?=\\s*(\\||$))/', $message, $match, PREG_OFFSET_CAPTURE)) {
            return $highlight($message, $match[1][1], strlen($match[1][0]));
        }

        return htmlspecialchars($message);
    };
}

if (!isset($getRewardFulfillMeta)) {
    $getRewardFulfillMeta = function ($rewardId) use ($db) {
        static $cache = [];
        $rewardId = (int) $rewardId;
        if ($rewardId <= 0) {
            return null;
        }
        if (isset($cache[$rewardId])) {
            return $cache[$rewardId];
        }
        $stmt = $db->prepare("SELECT status, redeemed_on, fulfilled_on, fulfilled_by, denied_on, denied_by, denied_note FROM rewards WHERE id = :id");
        $stmt->execute([':id' => $rewardId]);
        $cache[$rewardId] = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        return $cache[$rewardId];
    };
}
?>
<style>
    .no-scroll { overflow: hidden; }
    .parent-notification-trigger { position: relative; }
    .parent-notification-trigger i { font-size: 1.1rem; }
    .parent-notification-badge { position: absolute; top: -6px; right: -8px; background: #e53935; color: #fff; border-radius: 12px; padding: 2px 6px; font-size: 0.75rem; font-weight: 700; min-width: 22px; text-align: center; }
    .parent-notifications-modal { position: fixed; inset: 0; background: rgba(0,0,0,0.5); display: none; align-items: center; justify-content: center; z-index: 4000; padding: 14px; }
    .parent-notifications-modal.open { display: flex; }
    .parent-notifications-card { background: #fff; border-radius: 10px; max-width: 680px; width: min(680px, 100%); max-height: 80vh; overflow: hidden; box-shadow: 0 12px 32px rgba(0,0,0,0.25); display: grid; grid-template-rows: auto auto 1fr; }
    .parent-notifications-card header { display: flex; align-items: center; justify-content: space-between; padding: 12px 14px; border-bottom: 1px solid #e0e0e0; }
    .parent-notifications-card h2 { margin: 0; font-size: 1.1rem; }
    .parent-notifications-close { background: transparent; border: none; font-size: 1.3rem; cursor: pointer; color: #555; }
    .parent-notification-tabs { display: grid; grid-template-columns: repeat(3, minmax(0,1fr)); gap: 8px; padding: 10px 14px 0 14px; }
    .parent-tab-button { padding: 8px; border: 1px solid #c8e6c9; background: #fff; border-radius: 8px; font-weight: 700; color: #1565c0; cursor: pointer; }
    .parent-tab-button.active { background: #e8f5e9; }
    .parent-notification-body { padding: 0 14px 14px 14px; overflow-y: auto; }
    .parent-notification-panel { display: none; }
    .parent-notification-panel.active { display: block; }
    .parent-notification-list { list-style: none; padding: 0; margin: 12px 0; display: grid; gap: 8px; }
    .parent-notification-bulk { display: flex; align-items: center; gap: 8px; margin-top: 10px; font-weight: 600; color: #37474f; }
    .parent-notification-bulk input { width: 18px; height: 18px; }
    .parent-notification-item { padding: 10px; background: #fff; border: 1px solid #e0e0e0; border-radius: 8px; display: grid; grid-template-columns: auto 1fr auto; gap: 10px; align-items: center; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
    .parent-notification-item input[type="checkbox"] { width: 19.8px; height: 19.8px; }
    .parent-notification-actions { display: flex; gap: 8px; justify-content: flex-end; margin-top: 8px; }
    .parent-notification-actions .button.delete-danger { background-color: #d32f2f; }
    .parent-notification-title { font-weight: 700; color: #ef6c00; }
    .parent-notification-action-summary { margin-top: 10px; padding: 8px 10px; border-radius: 8px; background: #fff3e0; color: #ef6c00; font-weight: 700; }
    .parent-task-photo-thumb { width: 54px; height: 54px; border-radius: 10px; object-fit: cover; border: 1px solid #d5def0; box-shadow: 0 2px 6px rgba(0,0,0,0.12); cursor: pointer; }
    .parent-trash-button { border: none; background: transparent; cursor: pointer; font-size: 1.1rem; padding: 4px; color: #d32f2f; }
</style>
<div class="parent-notifications-modal" data-parent-notifications-modal>
    <div class="parent-notifications-card">
        <header>
            <h2>Notifications</h2>
            <button type="button" class="parent-notifications-close" aria-label="Close notifications" data-parent-notifications-close><i class="fa-solid fa-xmark"></i></button>
        </header>
        <div class="parent-notification-tabs" data-role="parent-notification-tabs">
            <button type="button" class="parent-tab-button active" data-tab="new">New (<?php echo count($parentNew); ?>)</button>
            <button type="button" class="parent-tab-button" data-tab="read">Read (<?php echo count($parentRead); ?>)</button>
            <button type="button" class="parent-tab-button" data-tab="deleted">Deleted (<?php echo count($parentDeleted); ?>)</button>
        </div>
        <div class="parent-notification-body">
            <?php if (!empty($parentNotificationActionSummary)): ?>
                <div class="parent-notification-action-summary"><?php echo htmlspecialchars($parentNotificationActionSummary); ?></div>
            <?php endif; ?>
            <form method="POST" action="dashboard_parent.php" data-tab-panel="new" class="parent-notification-panel active">
                <?php if (!empty($parentNew)): ?>
                    <label class="parent-notification-bulk">
                        <input type="checkbox" data-parent-bulk-action="mark_parent_notifications_read">
                        Mark all as read
                    </label>
                    <ul class="parent-notification-list">
                        <?php foreach ($parentNew as $note): ?>
                            <li class="parent-notification-item">
                                <input type="checkbox" name="parent_notification_ids[]" value="<?php echo (int) $note['id']; ?>" aria-label="Mark notification as read">
                                <div>
                                    <div><?php echo $formatParentNotificationMessage($note); ?></div>
                                    <?php
                                        $taskIdFromLink = null;
                                        $taskInstanceDate = null;
                                        $taskPhoto = null;
                                        $taskStatus = null;
                                        $taskApprovedAt = null;
                                        $taskRejectedAt = null;
                                        if ($note['type'] === 'task_completed' && !empty($note['link_url'])) {
                                            $urlParts = parse_url($note['link_url']);
                                            if (!empty($urlParts['query'])) {
                                                parse_str($urlParts['query'], $queryVars);
                                                if (!empty($queryVars['task_id'])) {
                                                    $taskIdFromLink = (int) $queryVars['task_id'];
                                                }
                                                if (!empty($queryVars['instance_date'])) {
                                                    $taskInstanceDate = $queryVars['instance_date'];
                                                }
                                            }
                                            if (!$taskIdFromLink && !empty($urlParts['fragment']) && preg_match('/task-(\d+)/', $urlParts['fragment'], $matches)) {
                                                $taskIdFromLink = (int) $matches[1];
                                            }
                                            if ($taskIdFromLink) {
                                                $taskPhotoStmt = $db->prepare("SELECT photo_proof, status, approved_at, rejected_at, recurrence FROM tasks WHERE id = :id LIMIT 1");
                                                $taskPhotoStmt->execute([':id' => $taskIdFromLink]);
                                                $taskRow = $taskPhotoStmt->fetch(PDO::FETCH_ASSOC);
                                                $taskIsRecurring = !empty($taskRow['recurrence']);
                                                if ($taskIsRecurring) {
                                                    if ($taskInstanceDate) {
                                                        $instStmt = $db->prepare("SELECT date_key, photo_proof, status, approved_at, rejected_at FROM task_instances WHERE task_id = :id AND date_key = :date_key LIMIT 1");
                                                        $instStmt->execute([':id' => $taskIdFromLink, ':date_key' => $taskInstanceDate]);
                                                    } else {
                                                        $instStmt = $db->prepare("SELECT date_key, photo_proof, status, approved_at, rejected_at FROM task_instances WHERE task_id = :id ORDER BY completed_at DESC LIMIT 1");
                                                        $instStmt->execute([':id' => $taskIdFromLink]);
                                                    }
                                                    $instRow = $instStmt->fetch(PDO::FETCH_ASSOC);
                                                    $taskInstanceDate = $taskInstanceDate ?: ($instRow['date_key'] ?? null);
                                                    $taskPhoto = $instRow['photo_proof'] ?? null;
                                                    $taskStatus = $instRow['status'] ?? null;
                                                    $taskApprovedAt = $instRow['approved_at'] ?? null;
                                                    $taskRejectedAt = $instRow['rejected_at'] ?? null;
                                                } else {
                                                    $taskPhoto = $taskRow['photo_proof'] ?? null;
                                                    $taskStatus = $taskRow['status'] ?? null;
                                                    $taskApprovedAt = $taskRow['approved_at'] ?? null;
                                                    $taskRejectedAt = $taskRow['rejected_at'] ?? null;
                                                }
                                            }
                                        }
                                    ?>
                                    <div class="parent-notification-meta">
                                        <?php echo htmlspecialchars(date('m/d/Y h:i A', strtotime($note['created_at']))); ?>
                                        <?php if (!empty($note['type'])): ?> | <?php echo htmlspecialchars(str_replace('_', ' ', $note['type'])); ?><?php endif; ?>
                                    <?php
                                        $rewardIdFromLink = null;
                                        $viewLink = !empty($note['link_url']) ? $note['link_url'] : null;
                                        if (!empty($note['link_url'])) {
                                            $urlParts = parse_url($note['link_url']);
                                            if (!empty($urlParts['query'])) {
                                                parse_str($urlParts['query'], $queryVars);
                                                if (!empty($queryVars['highlight_reward'])) {
                                                    $rewardIdFromLink = (int) $queryVars['highlight_reward'];
                                                } elseif (!empty($queryVars['reward_id'])) {
                                                    $rewardIdFromLink = (int) $queryVars['reward_id'];
                                                }
                                            }
                                        }
                                        if ($rewardIdFromLink && in_array($note['type'], ['reward_redeemed', 'goal_reward_earned'], true)) {
                                            $viewLink = 'rewards.php?highlight_reward=' . (int) $rewardIdFromLink . '#reward-' . (int) $rewardIdFromLink;
                                        }
                                        if ($note['type'] === 'task_completed' && $taskIdFromLink) {
                                            if (!empty($note['link_url'])) {
                                                $viewLink = $note['link_url'];
                                            } else {
                                                $viewLink = 'task.php?task_id=' . (int) $taskIdFromLink;
                                                if (!empty($taskInstanceDate)) {
                                                    $viewLink .= '&instance_date=' . urlencode($taskInstanceDate);
                                                }
                                                $viewLink .= '#task-' . (int) $taskIdFromLink;
                                            }
                                        }
                                        if (in_array($note['type'], ['goal_completed', 'goal_ready'], true)) {
                                            $viewLink = 'goal.php';
                                        }
                                        if ($note['type'] === 'routine_completed' && empty($viewLink)) {
                                            $viewLink = 'routine.php';
                                        }
                                        if ($note['type'] === 'reward_redeemed' && empty($viewLink)) {
                                            $viewLink = 'rewards.php';
                                        }
                                        if ($viewLink) {
                                            echo ' | <a href="' . htmlspecialchars($viewLink) . '">View</a>';
                                        }
                                        $fulfillMeta = $rewardIdFromLink ? $getRewardFulfillMeta($rewardIdFromLink) : null;
                                    ?>
                                    </div>
                                    <?php if (!empty($taskPhoto)): ?>
                                        <div style="margin-top:8px;">
                                            <img src="<?php echo htmlspecialchars($taskPhoto); ?>" alt="Task photo proof" class="parent-task-photo-thumb" data-parent-photo-src="<?php echo htmlspecialchars($taskPhoto, ENT_QUOTES); ?>">
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($note['type'] === 'reward_redeemed' && $rewardIdFromLink): ?>
                                        <div class="inline-form" style="margin-top:6px;">
                                            <button type="submit" name="fulfill_reward" value="<?php echo (int) $rewardIdFromLink; ?>|<?php echo (int) $note['id']; ?>" class="button approve-button">Fulfill</button>
                                        </div>
                                        <div class="inline-form" style="margin-top:6px;">
                                            <textarea name="deny_reward_note[<?php echo (int) $note['id']; ?>]" rows="2" placeholder="Optional deny note" style="width:100%; max-width:360px;"></textarea>
                                            <button type="submit" name="deny_reward" value="<?php echo (int) $rewardIdFromLink; ?>|<?php echo (int) $note['id']; ?>" class="button secondary">Deny</button>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($note['type'] === 'task_completed' && $taskIdFromLink): ?>
                                        <div class="inline-form" style="margin-top:6px;">
                                            <?php if ($taskStatus === 'approved'): ?>
                                                <div class="parent-notification-meta">
                                                    Approved!<?php if (!empty($taskApprovedAt)): ?> <?php echo htmlspecialchars(date('m/d/Y h:i A', strtotime($taskApprovedAt))); ?><?php endif; ?>
                                                </div>
                                            <?php elseif ($taskStatus === 'rejected'): ?>
                                                <div class="parent-notification-meta">
                                                    Rejected<?php if (!empty($taskRejectedAt)): ?> <?php echo htmlspecialchars(date('m/d/Y h:i A', strtotime($taskRejectedAt))); ?><?php endif; ?>
                                                </div>
                                            <?php else: ?>
                                                <?php if (!empty($taskInstanceDate)): ?>
                                                    <input type="hidden" name="instance_date_map[<?php echo (int) $taskIdFromLink; ?>]" value="<?php echo htmlspecialchars($taskInstanceDate, ENT_QUOTES); ?>">
                                                <?php endif; ?>
                                                <input type="hidden" name="parent_notification_map[<?php echo (int) $taskIdFromLink; ?>]" value="<?php echo (int) $note['id']; ?>">
                                                <button type="submit" name="approve_task_notification" value="<?php echo (int) $taskIdFromLink; ?>" class="button approve-button">Approve Task Completed</button>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <div class="parent-notification-actions">
                        <button type="submit" name="mark_parent_notifications_read" class="button secondary">Mark Selected as Read</button>
                    </div>
                <?php else: ?>
                    <p class="parent-notification-meta" style="margin: 12px 0;">No new notifications.</p>
                <?php endif; ?>
            </form>

            <form method="POST" action="dashboard_parent.php" data-tab-panel="read" class="parent-notification-panel">
                <?php if (!empty($parentRead)): ?>
                    <label class="parent-notification-bulk">
                        <input type="checkbox" data-parent-bulk-action="move_parent_notifications_trash">
                        Move all to deleted
                    </label>
                    <ul class="parent-notification-list">
                        <?php foreach ($parentRead as $note): ?>
                            <li class="parent-notification-item">
                                <input type="checkbox" name="parent_notification_ids[]" value="<?php echo (int) $note['id']; ?>" aria-label="Move to trash">
                                <div>
                                    <div><?php echo $formatParentNotificationMessage($note); ?></div>
                                    <?php
                                        $taskIdFromLink = null;
                                        $taskInstanceDate = null;
                                        $taskPhoto = null;
                                        $taskStatus = null;
                                        $taskApprovedAt = null;
                                        $taskRejectedAt = null;
                                        if ($note['type'] === 'task_completed' && !empty($note['link_url'])) {
                                            $urlParts = parse_url($note['link_url']);
                                            if (!empty($urlParts['query'])) {
                                                parse_str($urlParts['query'], $queryVars);
                                                if (!empty($queryVars['task_id'])) {
                                                    $taskIdFromLink = (int) $queryVars['task_id'];
                                                }
                                                if (!empty($queryVars['instance_date'])) {
                                                    $taskInstanceDate = $queryVars['instance_date'];
                                                }
                                            }
                                            if (!$taskIdFromLink && !empty($urlParts['fragment']) && preg_match('/task-(\d+)/', $urlParts['fragment'], $matches)) {
                                                $taskIdFromLink = (int) $matches[1];
                                            }
                                            if ($taskIdFromLink) {
                                                $taskPhotoStmt = $db->prepare("SELECT photo_proof, status, approved_at, rejected_at, recurrence FROM tasks WHERE id = :id LIMIT 1");
                                                $taskPhotoStmt->execute([':id' => $taskIdFromLink]);
                                                $taskRow = $taskPhotoStmt->fetch(PDO::FETCH_ASSOC);
                                                $taskIsRecurring = !empty($taskRow['recurrence']);
                                                if ($taskIsRecurring) {
                                                    if ($taskInstanceDate) {
                                                        $instStmt = $db->prepare("SELECT date_key, photo_proof, status, approved_at, rejected_at FROM task_instances WHERE task_id = :id AND date_key = :date_key LIMIT 1");
                                                        $instStmt->execute([':id' => $taskIdFromLink, ':date_key' => $taskInstanceDate]);
                                                    } else {
                                                        $instStmt = $db->prepare("SELECT date_key, photo_proof, status, approved_at, rejected_at FROM task_instances WHERE task_id = :id ORDER BY completed_at DESC LIMIT 1");
                                                        $instStmt->execute([':id' => $taskIdFromLink]);
                                                    }
                                                    $instRow = $instStmt->fetch(PDO::FETCH_ASSOC);
                                                    $taskInstanceDate = $taskInstanceDate ?: ($instRow['date_key'] ?? null);
                                                    $taskPhoto = $instRow['photo_proof'] ?? null;
                                                    $taskStatus = $instRow['status'] ?? null;
                                                    $taskApprovedAt = $instRow['approved_at'] ?? null;
                                                    $taskRejectedAt = $instRow['rejected_at'] ?? null;
                                                } else {
                                                    $taskPhoto = $taskRow['photo_proof'] ?? null;
                                                    $taskStatus = $taskRow['status'] ?? null;
                                                    $taskApprovedAt = $taskRow['approved_at'] ?? null;
                                                    $taskRejectedAt = $taskRow['rejected_at'] ?? null;
                                                }
                                            }
                                        }
                                    ?>
                                    <div class="parent-notification-meta">
                                        <?php echo htmlspecialchars(date('m/d/Y h:i A', strtotime($note['created_at']))); ?>
                                        <?php if (!empty($note['type'])): ?> | <?php echo htmlspecialchars(str_replace('_', ' ', $note['type'])); ?><?php endif; ?>
                                    <?php
                                        $rewardIdFromLink = null;
                                        $viewLink = !empty($note['link_url']) ? $note['link_url'] : null;
                                        if (!empty($note['link_url'])) {
                                            $urlParts = parse_url($note['link_url']);
                                            if (!empty($urlParts['query'])) {
                                                parse_str($urlParts['query'], $queryVars);
                                                if (!empty($queryVars['highlight_reward'])) {
                                                    $rewardIdFromLink = (int) $queryVars['highlight_reward'];
                                                } elseif (!empty($queryVars['reward_id'])) {
                                                    $rewardIdFromLink = (int) $queryVars['reward_id'];
                                                }
                                            }
                                        }
                                        if ($rewardIdFromLink && in_array($note['type'], ['reward_redeemed', 'goal_reward_earned'], true)) {
                                            $viewLink = 'rewards.php?highlight_reward=' . (int) $rewardIdFromLink . '#reward-' . (int) $rewardIdFromLink;
                                        }
                                        if ($note['type'] === 'task_completed' && $taskIdFromLink) {
                                            if (!empty($note['link_url'])) {
                                                $viewLink = $note['link_url'];
                                            } else {
                                                $viewLink = 'task.php?task_id=' . (int) $taskIdFromLink;
                                                if (!empty($taskInstanceDate)) {
                                                    $viewLink .= '&instance_date=' . urlencode($taskInstanceDate);
                                                }
                                                $viewLink .= '#task-' . (int) $taskIdFromLink;
                                            }
                                        }
                                        if (in_array($note['type'], ['goal_completed', 'goal_ready'], true)) {
                                            $viewLink = 'goal.php';
                                        }
                                        if ($note['type'] === 'routine_completed' && empty($viewLink)) {
                                            $viewLink = 'routine.php';
                                        }
                                        if ($note['type'] === 'reward_redeemed' && empty($viewLink)) {
                                            $viewLink = 'rewards.php';
                                        }
                                        if ($viewLink) {
                                            echo ' | <a href="' . htmlspecialchars($viewLink) . '">View</a>';
                                        }
                                        $fulfillMeta = $rewardIdFromLink ? $getRewardFulfillMeta($rewardIdFromLink) : null;
                                    ?>
                                    </div>
                                    <?php if (!empty($taskPhoto)): ?>
                                        <div style="margin-top:8px;">
                                            <img src="<?php echo htmlspecialchars($taskPhoto); ?>" alt="Task photo proof" class="parent-task-photo-thumb" data-parent-photo-src="<?php echo htmlspecialchars($taskPhoto, ENT_QUOTES); ?>">
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <button type="submit" name="trash_parent_single" value="<?php echo (int) $note['id']; ?>" class="parent-trash-button" aria-label="Move to deleted"><i class="fa-solid fa-trash"></i></button>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <div class="parent-notification-actions">
                        <button type="submit" name="move_parent_notifications_trash" class="button danger">Move Selected to Trash</button>
                    </div>
                <?php else: ?>
                    <p class="parent-notification-meta" style="margin: 12px 0;">No read notifications.</p>
                <?php endif; ?>
            </form>

            <form method="POST" action="dashboard_parent.php" data-tab-panel="deleted" class="parent-notification-panel">
                <?php if (!empty($parentDeleted)): ?>
                    <label class="parent-notification-bulk">
                        <input type="checkbox" data-parent-bulk-action="delete_parent_notifications_perm">
                        Delete all
                    </label>
                    <ul class="parent-notification-list">
                        <?php foreach ($parentDeleted as $note): ?>
                            <li class="parent-notification-item">
                                <input type="checkbox" name="parent_notification_ids[]" value="<?php echo (int) $note['id']; ?>" aria-label="Delete permanently">
                                <div>
                                    <div><?php echo $formatParentNotificationMessage($note); ?></div>
                                    <div class="parent-notification-meta">
                                        Deleted: <?php echo htmlspecialchars(date('m/d/Y h:i A', strtotime($note['deleted_at']))); ?>
                                    </div>
                                </div>
                                <button type="submit" name="delete_parent_single_perm" value="<?php echo (int) $note['id']; ?>" class="parent-trash-button" aria-label="Delete permanently"><i class="fa-solid fa-trash"></i></button>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <div class="parent-notification-actions">
                        <button type="submit" name="delete_parent_notifications_perm" class="button delete-danger">Delete Selected</button>
                    </div>
                <?php else: ?>
                    <p class="parent-notification-meta" style="margin: 12px 0;">Deleted is empty.</p>
                <?php endif; ?>
            </form>
        </div>
    </div>
</div>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const parentNotifyTrigger = document.querySelector('[data-parent-notify-trigger]');
        const parentModal = document.querySelector('[data-parent-notifications-modal]');
        const parentClose = parentModal ? parentModal.querySelector('[data-parent-notifications-close]') : null;
        const parentTabButtons = parentModal ? parentModal.querySelectorAll('.parent-tab-button') : [];
        const parentPanels = parentModal ? parentModal.querySelectorAll('.parent-notification-panel') : [];
        const parentAction = {
            summary: <?php echo json_encode($parentNotificationActionSummary); ?>,
            tab: <?php echo json_encode($parentNotificationActionTab); ?>
        };
        const setParentTab = (target) => {
            parentTabButtons.forEach(btn => btn.classList.toggle('active', btn.getAttribute('data-tab') === target));
            parentPanels.forEach(panel => panel.classList.toggle('active', panel.getAttribute('data-tab-panel') === target));
        };
        if (parentModal) {
            parentModal.querySelectorAll('[data-parent-bulk-action]').forEach((bulk) => {
                bulk.addEventListener('change', () => {
                    const form = bulk.closest('form');
                    if (!form) return;
                    const shouldCheck = bulk.checked;
                    form.querySelectorAll('input[name="parent_notification_ids[]"]').forEach((input) => {
                        input.checked = shouldCheck;
                    });
                });
            });
        }
        const openParentModal = () => {
            if (!parentModal) return;
            parentModal.classList.add('open');
            document.body.classList.add('no-scroll');
        };
        const closeParentModal = () => {
            if (!parentModal) return;
            parentModal.classList.remove('open');
            document.body.classList.remove('no-scroll');
        };
        if (parentNotifyTrigger && parentModal) {
            parentNotifyTrigger.addEventListener('click', openParentModal);
            if (parentClose) parentClose.addEventListener('click', closeParentModal);
            parentModal.addEventListener('click', (e) => { if (e.target === parentModal) closeParentModal(); });
            document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeParentModal(); });
            parentTabButtons.forEach(btn => {
                btn.addEventListener('click', () => setParentTab(btn.getAttribute('data-tab')));
            });
        }
        if (parentAction.summary && parentModal) {
            openParentModal();
            if (parentAction.tab) {
                setParentTab(parentAction.tab);
            }
        }
    });
</script>
