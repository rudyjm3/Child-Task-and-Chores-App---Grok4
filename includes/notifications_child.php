
<?php
if (!isset($isChildNotificationUser) || !$isChildNotificationUser) {
    return;
}

$notificationsNew = $notificationsNew ?? [];
$notificationsRead = $notificationsRead ?? [];
$notificationsDeleted = $notificationsDeleted ?? [];
$notificationActionSummary = $notificationActionSummary ?? '';
$notificationActionTab = $notificationActionTab ?? '';

if (!isset($formatChildNotificationMessage)) {
    $formatChildNotificationMessage = static function (array $note): string {
        $message = (string) ($note['message'] ?? '');
        $type = (string) ($note['type'] ?? '');
        $highlight = static function (string $text, int $start, int $length): string {
            $prefix = substr($text, 0, $start);
            $title = substr($text, $start, $length);
            $suffix = substr($text, $start + $length);
            return htmlspecialchars($prefix)
                . '<span class="notification-title">' . htmlspecialchars($title) . '</span>'
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

        if (in_array($type, ['task_approved', 'task_rejected', 'task_rejected_closed', 'goal_completed', 'goal_ready', 'goal_reward_earned', 'goal_points_awarded', 'reward_denied', 'reward_fulfilled'], true)) {
            if (preg_match('/:\\s*([^|]+?)(?=\\s*(\\||$))/', $message, $match, PREG_OFFSET_CAPTURE)) {
                return $highlight($message, $match[1][1], strlen($match[1][0]));
            }
        }

        return htmlspecialchars($message);
    };
}

if (!isset($buildChildNotificationViewLink)) {
    $buildChildNotificationViewLink = static function (array $note): ?string {
        $linkUrl = trim((string) ($note['link_url'] ?? ''));
        $type = (string) ($note['type'] ?? '');
        $viewLink = $linkUrl !== '' ? $linkUrl : null;
        $taskIdFromLink = null;
        $taskInstanceDate = null;
        $rewardIdFromLink = null;

        if ($linkUrl !== '') {
            $urlParts = parse_url($linkUrl);
            if (!empty($urlParts['query'])) {
                parse_str($urlParts['query'], $queryVars);
                if (!empty($queryVars['task_id'])) {
                    $taskIdFromLink = (int) $queryVars['task_id'];
                }
                if (!empty($queryVars['instance_date'])) {
                    $taskInstanceDate = $queryVars['instance_date'];
                }
                if (!empty($queryVars['highlight_reward'])) {
                    $rewardIdFromLink = (int) $queryVars['highlight_reward'];
                } elseif (!empty($queryVars['reward_id'])) {
                    $rewardIdFromLink = (int) $queryVars['reward_id'];
                }
            }
            if (!$taskIdFromLink && !empty($urlParts['fragment']) && preg_match('/task-(\d+)/', $urlParts['fragment'], $matches)) {
                $taskIdFromLink = (int) $matches[1];
            }
        }

        if (in_array($type, ['task_completed', 'task_approved', 'task_rejected', 'task_rejected_closed'], true)) {
            if ($taskIdFromLink) {
                $viewLink = 'task.php?task_id=' . (int) $taskIdFromLink;
                if (!empty($taskInstanceDate)) {
                    $viewLink .= '&instance_date=' . urlencode($taskInstanceDate);
                }
                $viewLink .= '#task-' . (int) $taskIdFromLink;
            } elseif ($viewLink === null) {
                $viewLink = 'task.php';
            }
        }

        if (in_array($type, ['goal_completed', 'goal_ready', 'goal_points_awarded'], true)) {
            if ($viewLink === null || strpos($viewLink, 'dashboard_child.php') === 0) {
                $viewLink = 'goal.php';
            }
        }

        if (in_array($type, ['reward_redeemed', 'reward_denied', 'reward_fulfilled'], true)) {
            if ($rewardIdFromLink) {
                $viewLink = 'rewards.php?highlight_reward=' . (int) $rewardIdFromLink . '#reward-' . (int) $rewardIdFromLink;
            } elseif ($viewLink === null || strpos($viewLink, 'dashboard_child.php') === 0) {
                $viewLink = 'rewards.php';
            }
        }

        if ($type === 'routine_completed' && ($viewLink === null || strpos($viewLink, 'dashboard_child.php') === 0)) {
            $viewLink = 'routine.php';
        }

        return $viewLink;
    };
}
?>
<style>
    .no-scroll { overflow: hidden; }
    .notification-trigger { position: relative; }
    .notification-badge { position: absolute; top: -6px; right: -8px; background: #d32f2f; color: #fff; border-radius: 12px; padding: 2px 6px; font-size: 0.75rem; font-weight: 700; min-width: 22px; text-align: center; }
    .notifications-modal { position: fixed; inset: 0; background: rgba(0,0,0,0.45); display: none; align-items: center; justify-content: center; z-index: 4000; padding: 14px; }
    .notifications-modal.open { display: flex; }
    .notifications-card { background: #fff; border-radius: 10px; max-width: 620px; width: min(620px, 100%); max-height: 80vh; overflow: hidden; box-shadow: 0 12px 32px rgba(0,0,0,0.25); display: grid; grid-template-rows: auto auto 1fr; }
    .notifications-card header { display: flex; align-items: center; justify-content: space-between; padding: 12px 14px; border-bottom: 1px solid #e0e0e0; }
    .notifications-card h2 { margin: 0; font-size: 1.05rem; }
    .notifications-close { background: transparent; border: none; font-size: 1.3rem; cursor: pointer; color: #555; }
    .notification-tabs { display: grid; grid-template-columns: repeat(3, minmax(0,1fr)); gap: 8px; padding: 10px 14px 0 14px; }
    .tab-button { padding: 8px; border: 1px solid #ffd28a; background: #fff; border-radius: 8px; font-weight: 700; color: #ef6c00; cursor: pointer; }
    .tab-button.active { background: #ffe0b2; }
    .notification-body { padding: 0 14px 14px 14px; overflow-y: auto; }
    .notification-panel { display: none; }
    .notification-panel.active { display: block; }
    .notification-list { list-style: none; padding: 0; margin: 12px 0; display: grid; gap: 10px; }
    .notification-bulk { display: flex; align-items: center; gap: 8px; margin-top: 10px; font-weight: 600; color: #37474f; }
    .notification-bulk input { width: 18px; height: 18px; }
    .notification-item { padding: 10px; background: #fff; border: 1px solid #e0e0e0; border-radius: 8px; display: grid; grid-template-columns: auto 1fr auto; gap: 10px; align-items: center; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
    .notification-item input[type="checkbox"] { width: 19.8px; height: 19.8px; }
    .notification-actions { display: flex; gap: 8px; justify-content: flex-end; margin-top: 8px; }
    .notification-actions .button.danger { background-color: #d32f2f; }
    .notification-title { font-weight: 700; color: #ef6c00; }
    .notification-action-summary { margin-top: 10px; padding: 8px 10px; border-radius: 8px; background: #fff3e0; color: #ef6c00; font-weight: 700; }
    .trash-button { border: none; background: transparent; cursor: pointer; font-size: 1.1rem; padding: 4px; color: #d32f2f; }
</style>
<div class="notifications-modal" data-child-notifications-modal>
    <div class="notifications-card">
        <header>
            <h2>Notifications</h2>
            <button type="button" class="notifications-close" aria-label="Close notifications" data-child-notifications-close>&times;</button>
        </header>
        <div class="notification-tabs" data-role="notification-tabs">
            <button type="button" class="tab-button active" data-tab="new">New (<?php echo count($notificationsNew); ?>)</button>
            <button type="button" class="tab-button" data-tab="read">Read (<?php echo count($notificationsRead); ?>)</button>
            <button type="button" class="tab-button" data-tab="deleted">Deleted (<?php echo count($notificationsDeleted); ?>)</button>
        </div>
        <div class="notification-body">
            <?php if (!empty($notificationActionSummary)): ?>
                <div class="notification-action-summary"><?php echo htmlspecialchars($notificationActionSummary); ?></div>
            <?php endif; ?>
            <form method="POST" action="dashboard_child.php" data-tab-panel="new" class="notification-panel active">
                <?php if (!empty($notificationsNew)): ?>
                    <label class="notification-bulk">
                        <input type="checkbox" data-child-bulk-action="mark_notifications_read">
                        Mark all as read
                    </label>
                    <ul class="notification-list">
                        <?php foreach ($notificationsNew as $note): ?>
                            <li class="notification-item">
                                <input type="checkbox" name="notification_ids[]" value="<?php echo (int) $note['id']; ?>" aria-label="Mark notification as read">
                                <div>
                                    <div><?php echo $formatChildNotificationMessage($note); ?></div>
                                    <div class="notification-meta">
                                        <?php echo htmlspecialchars(date('m/d/Y h:i A', strtotime($note['created_at']))); ?>
                                        <?php if (!empty($note['type'])): ?> | <?php echo htmlspecialchars(str_replace('_', ' ', $note['type'])); ?><?php endif; ?>
                                        <?php
                                            $viewLink = $buildChildNotificationViewLink($note);
                                            if (!empty($viewLink)) {
                                                echo ' | <a href="' . htmlspecialchars($viewLink) . '">View</a>';
                                            }
                                        ?>
                                    </div>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <div class="notification-actions">
                        <button type="submit" name="mark_notifications_read" class="button">Mark Selected as Read</button>
                    </div>
                <?php else: ?>
                    <p class="notification-meta" style="margin: 12px 0;">No new notifications.</p>
                <?php endif; ?>
            </form>

            <form method="POST" action="dashboard_child.php" data-tab-panel="read" class="notification-panel">
                <?php if (!empty($notificationsRead)): ?>
                    <label class="notification-bulk">
                        <input type="checkbox" data-child-bulk-action="move_notifications_trash">
                        Move all to deleted
                    </label>
                    <ul class="notification-list">
                        <?php foreach ($notificationsRead as $note): ?>
                            <li class="notification-item">
                                <input type="checkbox" name="notification_ids[]" value="<?php echo (int) $note['id']; ?>" aria-label="Move to deleted">
                                <div>
                                    <div><?php echo $formatChildNotificationMessage($note); ?></div>
                                    <div class="notification-meta">
                                        <?php echo htmlspecialchars(date('m/d/Y h:i A', strtotime($note['created_at']))); ?>
                                        <?php if (!empty($note['type'])): ?> | <?php echo htmlspecialchars(str_replace('_', ' ', $note['type'])); ?><?php endif; ?>
                                        <?php
                                            $viewLink = $buildChildNotificationViewLink($note);
                                            if (!empty($viewLink)) {
                                                echo ' | <a href="' . htmlspecialchars($viewLink) . '">View</a>';
                                            }
                                        ?>
                                    </div>
                                </div>
                                <button type="submit" name="trash_single" value="<?php echo (int) $note['id']; ?>" class="trash-button" aria-label="Move to deleted"><i class="fa-solid fa-trash"></i></button>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <div class="notification-actions">
                        <button type="submit" name="move_notifications_trash" class="button">Move Selected to Deleted</button>
                    </div>
                <?php else: ?>
                    <p class="notification-meta" style="margin: 12px 0;">No read notifications.</p>
                <?php endif; ?>
            </form>

            <form method="POST" action="dashboard_child.php" data-tab-panel="deleted" class="notification-panel">
                <?php if (!empty($notificationsDeleted)): ?>
                    <label class="notification-bulk">
                        <input type="checkbox" data-child-bulk-action="delete_notifications_perm">
                        Delete all
                    </label>
                    <ul class="notification-list">
                        <?php foreach ($notificationsDeleted as $note): ?>
                            <li class="notification-item">
                                <input type="checkbox" name="notification_ids[]" value="<?php echo (int) $note['id']; ?>" aria-label="Delete permanently">
                                <div>
                                    <div><?php echo $formatChildNotificationMessage($note); ?></div>
                                    <div class="notification-meta">
                                        Deleted: <?php echo htmlspecialchars(date('m/d/Y h:i A', strtotime($note['deleted_at']))); ?>
                                    </div>
                                </div>
                                <button type="submit" name="delete_single_perm" value="<?php echo (int) $note['id']; ?>" class="trash-button" aria-label="Delete permanently"><i class="fa-solid fa-trash"></i></button>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <div class="notification-actions">
                        <button type="submit" name="delete_notifications_perm" class="button danger">Delete Selected</button>
                    </div>
                <?php else: ?>
                    <p class="notification-meta" style="margin: 12px 0;">Deleted is empty.</p>
                <?php endif; ?>
            </form>
        </div>
    </div>
</div>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const childNotifyTrigger = document.querySelector('[data-child-notify-trigger]');
        const childModal = document.querySelector('[data-child-notifications-modal]');
        const childClose = childModal ? childModal.querySelector('[data-child-notifications-close]') : null;
        const childTabButtons = childModal ? childModal.querySelectorAll('.tab-button') : [];
        const childPanels = childModal ? childModal.querySelectorAll('.notification-panel') : [];
        const childAction = {
            summary: <?php echo json_encode($notificationActionSummary); ?>,
            tab: <?php echo json_encode($notificationActionTab); ?>
        };
        const setChildTab = (target) => {
            childTabButtons.forEach(btn => btn.classList.toggle('active', btn.getAttribute('data-tab') === target));
            childPanels.forEach(panel => panel.classList.toggle('active', panel.getAttribute('data-tab-panel') === target));
        };
        if (childModal) {
            childModal.querySelectorAll('[data-child-bulk-action]').forEach((bulk) => {
                bulk.addEventListener('change', () => {
                    const form = bulk.closest('form');
                    if (!form) return;
                    const shouldCheck = bulk.checked;
                    form.querySelectorAll('input[name="notification_ids[]"]').forEach((input) => {
                        input.checked = shouldCheck;
                    });
                });
            });
        }
        const openChildModal = () => {
            if (!childModal) return;
            childModal.classList.add('open');
            document.body.classList.add('no-scroll');
        };
        const closeChildModal = () => {
            if (!childModal) return;
            childModal.classList.remove('open');
            document.body.classList.remove('no-scroll');
        };
        if (childNotifyTrigger && childModal) {
            childNotifyTrigger.addEventListener('click', openChildModal);
            if (childClose) childClose.addEventListener('click', closeChildModal);
            childModal.addEventListener('click', (e) => { if (e.target === childModal) closeChildModal(); });
            document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeChildModal(); });
            childTabButtons.forEach(btn => {
                btn.addEventListener('click', () => setChildTab(btn.getAttribute('data-tab')));
            });
        }
        if (childAction.summary && childModal) {
            openChildModal();
            if (childAction.tab) {
                setChildTab(childAction.tab);
            }
        }
    });
</script>
