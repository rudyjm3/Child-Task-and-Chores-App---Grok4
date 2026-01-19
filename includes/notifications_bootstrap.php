<?php
if (!isset($_SESSION['user_id'])) {
    return;
}

$notificationRole = getEffectiveRole($_SESSION['user_id']);
$isParentNotificationUser = in_array($notificationRole, ['main_parent', 'secondary_parent', 'family_member', 'caregiver'], true);
$isChildNotificationUser = $notificationRole === 'child';

$parentNotificationActionSummary = $parentNotificationActionSummary ?? '';
$parentNotificationActionTab = $parentNotificationActionTab ?? '';
$notificationActionSummary = $notificationActionSummary ?? '';
$notificationActionTab = $notificationActionTab ?? '';
$parentNotificationCount = $parentNotificationCount ?? 0;
$notificationCount = $notificationCount ?? 0;

if ($isParentNotificationUser) {
    $notificationParentId = isset($main_parent_id) ? (int) $main_parent_id : getFamilyRootId($_SESSION['user_id']);
    $parentNotices = getParentNotifications($notificationParentId);
    $parentNew = $parentNotices['new'] ?? [];
    $parentRead = $parentNotices['read'] ?? [];
    $parentDeleted = $parentNotices['deleted'] ?? [];
    $parentNotificationCount = count($parentNew);
}

if ($isChildNotificationUser) {
    $childNotices = getChildNotifications($_SESSION['user_id']);
    $notificationsNew = $childNotices['new'] ?? [];
    $notificationsRead = $childNotices['read'] ?? [];
    $notificationsDeleted = $childNotices['deleted'] ?? [];
    $notificationCount = count($notificationsNew);
}
