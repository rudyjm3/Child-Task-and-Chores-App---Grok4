<?php
require_once __DIR__ . '/../includes/db_connect.php';

function extractRewardId(?string $link): ?int {
    if (!$link) {
        return null;
    }
    $parts = parse_url($link);
    if (!empty($parts['query'])) {
        parse_str($parts['query'], $queryVars);
        if (!empty($queryVars['highlight_reward'])) {
            return (int) $queryVars['highlight_reward'];
        }
        if (!empty($queryVars['reward_id'])) {
            return (int) $queryVars['reward_id'];
        }
    }
    if (!empty($parts['fragment']) && preg_match('/reward-(\d+)/', $parts['fragment'], $matches)) {
        return (int) $matches[1];
    }
    return null;
}

$totalAll = (int) $db->query("SELECT COUNT(1) FROM parent_notifications")->fetchColumn();
$typeStmt = $db->prepare("SELECT type, COUNT(1) AS total FROM parent_notifications GROUP BY type ORDER BY total DESC");
$typeStmt->execute();
$typeCounts = $typeStmt->fetchAll(PDO::FETCH_ASSOC);

$select = $db->prepare("SELECT id, parent_user_id, link_url FROM parent_notifications WHERE type = 'reward_redeemed'");
$select->execute();
$notes = $select->fetchAll(PDO::FETCH_ASSOC);
$totalNotes = count($notes);

$rewardStmt = $db->prepare("SELECT title, status, denied_on, denied_note, fulfilled_on FROM rewards WHERE id = :id AND parent_user_id = :parent_id LIMIT 1");
$updateStmt = $db->prepare("UPDATE parent_notifications SET type = :type, message = :message, is_read = 1 WHERE id = :id AND parent_user_id = :parent_id");

$updated = 0;
$skipped = 0;
$missingReward = 0;
$missingUpdated = 0;

foreach ($notes as $note) {
    $noteId = (int) ($note['id'] ?? 0);
    $parentId = (int) ($note['parent_user_id'] ?? 0);
    $rewardId = extractRewardId($note['link_url'] ?? '');
    if (!$noteId || !$parentId || !$rewardId) {
        $skipped++;
        continue;
    }
    $rewardStmt->execute([':id' => $rewardId, ':parent_id' => $parentId]);
    $reward = $rewardStmt->fetch(PDO::FETCH_ASSOC);
    if (!$reward) {
        $missingReward++;
        $message = 'Reward denied: Reward no longer available | ' . date('m/d/Y h:i A');
        $message = substr($message, 0, 255);
        $updateStmt->execute([
            ':type' => 'reward_denied',
            ':message' => $message,
            ':id' => $noteId,
            ':parent_id' => $parentId
        ]);
        if ($updateStmt->rowCount() > 0) {
            $missingUpdated++;
        }
        continue;
    }
    $title = $reward['title'] ?? 'Reward';
    $type = null;
    $message = null;
    if (!empty($reward['fulfilled_on'])) {
        $type = 'reward_fulfilled';
        $timestamp = date('m/d/Y h:i A', strtotime($reward['fulfilled_on']));
        $message = 'Reward fulfilled: ' . $title . ' | ' . $timestamp;
    } elseif (!empty($reward['denied_on']) && ($reward['status'] ?? '') === 'available') {
        $type = 'reward_denied';
        $timestamp = date('m/d/Y h:i A', strtotime($reward['denied_on']));
        $message = 'Reward denied: ' . $title . ' | ' . $timestamp;
        if (!empty($reward['denied_note'])) {
            $message .= ' | Reason: ' . $reward['denied_note'];
        }
    } else {
        $skipped++;
        continue;
    }
    $message = substr($message, 0, 255);
    $updateStmt->execute([
        ':type' => $type,
        ':message' => $message,
        ':id' => $noteId,
        ':parent_id' => $parentId
    ]);
    if ($updateStmt->rowCount() > 0) {
        $updated++;
    }
}

echo "Cleanup complete.\n";
echo "Total notifications: {$totalAll}\n";
if (!empty($typeCounts)) {
    echo "Types:\n";
    foreach ($typeCounts as $row) {
        $label = $row['type'] ?? '(null)';
        $count = (int) ($row['total'] ?? 0);
        echo "- {$label}: {$count}\n";
    }
}
echo "Found: {$totalNotes}\n";
echo "Updated: {$updated}\n";
echo "Skipped: {$skipped}\n";
echo "Missing rewards: {$missingReward}\n";
echo "Missing rewards updated: {$missingUpdated}\n";
