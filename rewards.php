<?php
session_start();
require_once __DIR__ . '/includes/functions.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
$role_type = getEffectiveRole($_SESSION['user_id']);
$child_id = (int) $_SESSION['user_id'];
$main_parent_id = getFamilyRootId($_SESSION['user_id']);

if ($role_type === 'child') {
    $parent_id = $main_parent_id;
    $messages = [];
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['purchase_template'])) {
        $template_id = filter_input(INPUT_POST, 'template_id', FILTER_VALIDATE_INT);
        $purchaseError = null;
        $reward_id = ($template_id && $parent_id)
            ? purchaseRewardTemplate($child_id, $template_id, $purchaseError)
            : false;
        if ($reward_id) {
            $_SESSION['flash_message'] = "Reward purchased successfully! Awaiting parent fulfillment.";
        } else {
            switch ($purchaseError) {
                case 'points':
                    $_SESSION['flash_message'] = "Not enough points to purchase this reward. Try completing more task and routines to earn more points.";
                    break;
                case 'level':
                    $_SESSION['flash_message'] = "Reach a higher level to unlock this reward.";
                    break;
                case 'disabled':
                    $_SESSION['flash_message'] = "This reward is disabled for you.";
                    break;
                default:
                    $_SESSION['flash_message'] = "Unable to purchase reward right now.";
                    break;
            }
        }
        header("Location: rewards.php");
        exit;
    }

    $profileStmt = $db->prepare("SELECT u.first_name, u.name, u.username, cp.child_name, cp.avatar
                                 FROM users u
                                 LEFT JOIN child_profiles cp ON cp.child_user_id = u.id
                                 WHERE u.id = :child_id AND u.deleted_at IS NULL
                                 LIMIT 1");
    $profileStmt->execute([':child_id' => $child_id]);
    $profile = $profileStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $childFirstName = trim((string) ($profile['first_name'] ?? ''));
    $childDisplayName = $childFirstName !== ''
        ? $childFirstName
        : (trim((string) ($profile['child_name'] ?? '')) ?: getDisplayName($child_id));
    $childAvatar = !empty($profile['avatar']) ? $profile['avatar'] : 'images/default-avatar.png';
    $childPoints = getChildTotalPoints($child_id);
    $levelState = $parent_id ? getChildLevelState($child_id, (int) $parent_id) : ['level' => 1];
    $childLevel = (int) ($levelState['level'] ?? 1);

    $templates = $parent_id ? getRewardTemplates($parent_id) : [];
    $disabledMap = $parent_id ? getRewardTemplateDisabledMap($parent_id, [$child_id]) : [];
    $disabledTemplates = $disabledMap[$child_id] ?? [];

    $purchasedToday = [];
    $templateIds = array_values(array_filter(array_map('intval', array_column($templates, 'id'))));
    if (!empty($templateIds)) {
        $placeholders = implode(',', array_fill(0, count($templateIds), '?'));
        $params = array_merge([$child_id], $templateIds);
        $stmt = $db->prepare("SELECT template_id, COUNT(*) AS purchase_count
                              FROM rewards
                              WHERE redeemed_by = ?
                                AND status = 'redeemed'
                                AND template_id IN ($placeholders)
                                AND DATE(redeemed_on) = CURDATE()
                              GROUP BY template_id");
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $purchasedToday[(int) ($row['template_id'] ?? 0)] = (int) ($row['purchase_count'] ?? 0);
        }
    }

    $flashMessage = $_SESSION['flash_message'] ?? null;
    if ($flashMessage !== null) {
        unset($_SESSION['flash_message']);
    }

    $iconPalette = [
        ['color' => '#48bfe3', 'icon' => 'fa-solid fa-gift'],
        ['color' => '#f8961e', 'icon' => 'fa-solid fa-star'],
        ['color' => '#43aa8b', 'icon' => 'fa-solid fa-book'],
        ['color' => '#f94144', 'icon' => 'fa-solid fa-gamepad'],
        ['color' => '#f9c74f', 'icon' => 'fa-solid fa-smile'],
        ['color' => '#577590', 'icon' => 'fa-solid fa-puzzle-piece']
    ];
    $iconCount = count($iconPalette);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Rewards Shop</title>
        <link rel="stylesheet" href="css/main.css?v=3.26.0">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css" integrity="Evv84Mr4kqVGRNSgIGL/F/aIDqQb7xQ2vcrdIwxfjThSH8CSR7PBEakCr51Ck+w+/U6swU2Im1vVX0SVk9ABhg==" crossorigin="anonymous" referrerpolicy="no-referrer">
        <style>
            body.rewards-shop-body {
                margin: 0;
                max-width: none;
                padding: 0;
                background: linear-gradient(180deg, #4cbad6 0%, #79d9ee 45%, #6fcbe1 100%);
                color: #1f2937;
                min-height: 100vh;
            }
            body.rewards-shop-body header,
            body.rewards-shop-body main,
            body.rewards-shop-body footer {
                text-align: initial;
                margin: 0;
            }
            .shop-hero {
                background: #3db0d1;
                padding: 32px 16px 24px;
                text-align: center;
                color: #fff;
            }
            .shop-hero h1 {
                margin: 0;
                font-size: clamp(2rem, 4vw, 3.4rem);
                line-height: 1.1;
            }
            .shop-main {
                padding: 20px 16px 48px;
                display: flex;
                justify-content: center;
            }
            .shop-shell {
                width: min(1120px, 100%);
                background: rgba(255, 255, 255, 0.75);
                border-radius: 26px;
                padding: 14px;
                box-shadow: 0 18px 36px rgba(0, 0, 0, 0.15);
                border: 2px solid rgba(255, 255, 255, 0.6);
            }
            .shop-panel {
                background: #f8fdff;
                border-radius: 22px;
                border: 2px solid #d7f1fb;
                overflow: hidden;
            }
            .shop-panel-header {
                background: #79d1e6;
                color: #fff;
                padding: 14px 18px;
                display: flex;
                align-items: center;
                justify-content: space-between;
                flex-wrap: wrap;
                gap: 14px;
            }
            .shop-welcome {
                display: flex;
                align-items: center;
                gap: 12px;
                font-weight: 700;
            }
            .shop-avatar {
                width: 46px;
                height: 46px;
                border-radius: 50%;
                object-fit: cover;
                border: 3px solid rgba(255, 255, 255, 0.8);
                box-shadow: 0 4px 10px rgba(0, 0, 0, 0.15);
            }
            .shop-meta {
                display: flex;
                align-items: center;
                gap: 12px;
                font-weight: 700;
                flex-wrap: wrap;
            }
            .shop-coins {
                display: inline-flex;
                align-items: center;
                gap: 6px;
            }
            .shop-coins i {
                color: #fde68a;
            }
            .shop-exit {
                display: inline-flex;
                align-items: center;
                gap: 6px;
                color: #fff;
                text-decoration: none;
                padding: 6px 12px;
                border-radius: 999px;
                background: rgba(255, 255, 255, 0.2);
                transition: background 150ms ease;
            }
            .shop-exit:hover {
                background: rgba(255, 255, 255, 0.3);
            }
            .shop-alert {
                margin: 0 0 12px;
                padding: 10px 14px;
                border-radius: 12px;
                background: #fff7ed;
                color: #b45309;
                border: 1px solid #fde68a;
                font-weight: 600;
            }
            .shop-grid {
                padding: 18px;
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
                gap: 16px;
            }
            .shop-card {
                background: #fff;
                border-radius: 18px;
                padding: 14px;
                box-shadow: 0 8px 18px rgba(0, 0, 0, 0.08);
                display: grid;
                gap: 10px;
                position: relative;
            }
            .shop-card.is-muted {
                filter: grayscale(0.9);
                opacity: 0.65;
            }
            .shop-card-header {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 10px;
            }
            .shop-icon {
                width: 52px;
                height: 52px;
                border-radius: 50%;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                color: #fff;
                font-size: 1.4rem;
                flex-shrink: 0;
            }
            .shop-title {
                font-size: 1.1rem;
                font-weight: 700;
                color: #111827;
            }
            .shop-price {
                display: inline-flex;
                align-items: center;
                gap: 6px;
                font-weight: 700;
                color: #f59e0b;
            }
            .shop-info {
                display: inline-flex;
                align-items: center;
                gap: 8px;
                color: #6b7280;
                font-size: 0.95rem;
            }
            .shop-info i {
                color: #6b7280;
            }
            .shop-status {
                display: flex;
                align-items: center;
                gap: 8px;
                flex-wrap: wrap;
            }
            .shop-status-badge {
                display: inline-flex;
                align-items: center;
                padding: 4px 10px;
                border-radius: 999px;
                font-size: 0.8rem;
                font-weight: 700;
                background: #fee2e2;
                color: #b91c1c;
            }
            .shop-status-badge.is-locked {
                background: #e0f2fe;
                color: #0369a1;
            }
            .shop-details {
                margin: 0;
            }
            .shop-details summary {
                list-style: none;
                cursor: pointer;
                color: #0284c7;
                font-weight: 600;
            }
            .shop-details summary::-webkit-details-marker {
                display: none;
            }
            .shop-details p {
                margin: 6px 0 0;
                color: #4b5563;
                font-size: 0.95rem;
            }
            .shop-buy-form {
                margin: 0;
            }
            .shop-buy {
                width: 100%;
                border: none;
                border-radius: 12px;
                padding: 10px 12px;
                background: #7fd3e8;
                color: #fff;
                font-weight: 700;
                letter-spacing: 0.02em;
                cursor: pointer;
                transition: transform 120ms ease, box-shadow 120ms ease;
            }
            .shop-buy:hover {
                transform: translateY(-1px);
                box-shadow: 0 6px 14px rgba(10, 100, 130, 0.18);
            }
            .shop-buy:disabled {
                background: #cbdfe7;
                cursor: not-allowed;
                box-shadow: none;
                transform: none;
            }
            .shop-empty {
                padding: 20px;
                text-align: center;
                color: #6b7280;
                font-weight: 600;
            }
            @media (max-width: 720px) {
                .shop-panel-header {
                    flex-direction: column;
                    align-items: flex-start;
                }
                .shop-shell {
                    padding: 10px;
                }
                .shop-grid {
                    padding: 14px;
                }
            }
        </style>
    </head>
    <body class="child-theme rewards-shop-body">
        <header class="shop-hero">
            <h1>Buy rewards from the shop!</h1>
        </header>
        <main class="shop-main">
            <section class="shop-shell">
                <?php if ($flashMessage): ?>
                    <div class="shop-alert" role="status"><?php echo htmlspecialchars($flashMessage); ?></div>
                <?php endif; ?>
                <div class="shop-panel">
                    <div class="shop-panel-header">
                        <div class="shop-welcome">
                            <img class="shop-avatar" src="<?php echo htmlspecialchars($childAvatar); ?>" alt="<?php echo htmlspecialchars($childDisplayName !== '' ? $childDisplayName : 'Child'); ?>">
                            <div>Welcome, <?php echo htmlspecialchars($childDisplayName !== '' ? $childDisplayName : 'Child'); ?>!</div>
                        </div>
                        <div class="shop-meta">
                            <div class="shop-coins">Available points: <?php echo (int) $childPoints; ?> <i class="fa-solid fa-star"></i></div>
                            <a class="shop-exit" href="dashboard_child.php">Exit Shop <i class="fa-solid fa-right-from-bracket"></i></a>
                        </div>
                    </div>
                    <?php if (!empty($templates)): ?>
                        <div class="shop-grid">
                            <?php foreach ($templates as $index => $template): ?>
                                <?php
                                    $templateId = (int) ($template['id'] ?? 0);
                                    $requiredLevel = max(1, (int) ($template['level_required'] ?? 1));
                                    $isDisabled = in_array($templateId, $disabledTemplates, true);
                                    $isLocked = $childLevel < $requiredLevel;
                                    $isMuted = $isDisabled || $isLocked;
                                    $palette = $iconPalette[$iconCount > 0 ? ($index % $iconCount) : 0];
                                    $iconColor = $palette['color'] ?? '#48bfe3';
                                    $iconClass = $palette['icon'] ?? 'fa-solid fa-gift';
                                    $purchasedCount = $purchasedToday[$templateId] ?? 0;
                                ?>
                                <article class="shop-card<?php echo $isMuted ? ' is-muted' : ''; ?>">
                                    <div class="shop-card-header">
                                        <span class="shop-icon" style="background: <?php echo htmlspecialchars($iconColor); ?>;">
                                            <i class="<?php echo htmlspecialchars($iconClass); ?>"></i>
                                        </span>
                                        <span class="shop-price"><?php echo (int) ($template['point_cost'] ?? 0); ?> <i class="fa-solid fa-coins"></i></span>
                                    </div>
                                    <div class="shop-title"><?php echo htmlspecialchars($template['title'] ?? 'Reward'); ?></div>
                                    <div class="shop-info">
                                        <i class="fa-solid fa-circle-info"></i>
                                        <span>Purchased <?php echo (int) $purchasedCount; ?> today</span>
                                    </div>
                                    <?php if ($isDisabled || $isLocked): ?>
                                        <div class="shop-status">
                                            <?php if ($isDisabled): ?>
                                                <span class="shop-status-badge">Disabled</span>
                                            <?php else: ?>
                                                <span class="shop-status-badge is-locked">Reach Level <?php echo (int) $requiredLevel; ?> to Unlock</span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($template['description'])): ?>
                                        <details class="shop-details">
                                            <summary>View Details</summary>
                                            <p><?php echo nl2br(htmlspecialchars($template['description'])); ?></p>
                                        </details>
                                    <?php endif; ?>
                                    <form method="POST" action="rewards.php" class="shop-buy-form">
                                        <input type="hidden" name="template_id" value="<?php echo $templateId; ?>">
                                        <button type="submit" name="purchase_template" class="shop-buy" <?php echo $isMuted ? 'disabled' : ''; ?>>BUY REWARD</button>
                                    </form>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="shop-empty">No rewards available yet.</div>
                    <?php endif; ?>
                </div>
            </section>
        </main>
    </body>
    </html>
    <?php
    exit;
}

if (!canCreateContent($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
$currentPage = basename($_SERVER['PHP_SELF']);
$messages = [];

require_once __DIR__ . '/includes/notifications_bootstrap.php';

// Load children for this family
$childStmt = $db->prepare("SELECT child_user_id, child_name, avatar FROM child_profiles WHERE parent_user_id = :parent_id AND deleted_at IS NULL ORDER BY child_name");
$childStmt->execute([':parent_id' => $main_parent_id]);
$children = $childStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_reward'])) {
        $reward_id = filter_input(INPUT_POST, 'reward_id', FILTER_VALIDATE_INT);
        $title = trim((string)filter_input(INPUT_POST, 'reward_title', FILTER_SANITIZE_STRING));
        $description = trim((string)filter_input(INPUT_POST, 'reward_description', FILTER_SANITIZE_STRING));
        $point_cost = filter_input(INPUT_POST, 'point_cost', FILTER_VALIDATE_INT);
        if ($reward_id && $title !== '' && $point_cost !== false && $point_cost !== null && $point_cost > 0) {
            $messages[] = updateReward($main_parent_id, $reward_id, $title, $description, $point_cost)
                ? "Reward updated."
                : "Unable to update reward. It may have been redeemed or removed.";
        } else {
            $messages[] = "Provide a title and point cost to update the reward.";
        }
    } elseif (isset($_POST['delete_reward'])) {
        $reward_id = filter_input(INPUT_POST, 'reward_id', FILTER_VALIDATE_INT);
        if ($reward_id) {
            $messages[] = deleteReward($main_parent_id, $reward_id)
                ? "Reward deleted."
                : "Unable to delete reward. Only available rewards can be removed.";
        } else {
            $messages[] = "Invalid reward selected for deletion.";
        }
    } elseif (isset($_POST['fulfill_reward'])) {
        $reward_id = filter_input(INPUT_POST, 'reward_id', FILTER_VALIDATE_INT);
        if (!$reward_id && isset($_POST['fulfill_reward'])) {
            $reward_id = filter_input(INPUT_POST, 'fulfill_reward', FILTER_VALIDATE_INT);
        }
        $fulfilled = ($reward_id && fulfillReward($reward_id, $main_parent_id, $_SESSION['user_id']));
        if ($fulfilled) {
            $messages[] = "Reward fulfillment recorded.";
            ensureParentNotificationsTable();
            $rewardTitleStmt = $db->prepare("SELECT title FROM rewards WHERE id = :id AND parent_user_id = :parent_id");
            $rewardTitleStmt->execute([':id' => $reward_id, ':parent_id' => $main_parent_id]);
            $rewardTitle = $rewardTitleStmt->fetchColumn() ?: 'Reward';
            $resolvedMessage = 'Reward fulfilled: ' . $rewardTitle . ' | ' . date('m/d/Y h:i A');
            $update = $db->prepare("UPDATE parent_notifications
                                    SET type = 'reward_fulfilled',
                                        message = :message,
                                        is_read = 1
                                    WHERE parent_user_id = :parent_id
                                      AND type = 'reward_redeemed'
                                      AND link_url LIKE :link");
            $update->execute([
                ':message' => $resolvedMessage,
                ':parent_id' => $main_parent_id,
                ':link' => '%highlight_reward=' . (int) $reward_id . '%'
            ]);
        } else {
            $messages[] = "Unable to mark reward as fulfilled.";
        }
    } elseif (isset($_POST['deny_reward'])) {
        $reward_id = filter_input(INPUT_POST, 'reward_id', FILTER_VALIDATE_INT);
        if (!$reward_id && isset($_POST['deny_reward'])) {
            $reward_id = filter_input(INPUT_POST, 'deny_reward', FILTER_VALIDATE_INT);
        }
        $deny_note = trim(filter_input(INPUT_POST, 'deny_reward_note', FILTER_SANITIZE_STRING) ?? '');
        $denied = ($reward_id && denyReward($reward_id, $main_parent_id, $_SESSION['user_id'], $deny_note));
        if (!$denied && $reward_id) {
            $statusStmt = $db->prepare("SELECT status, denied_on FROM rewards WHERE id = :id AND parent_user_id = :parent_id");
            $statusStmt->execute([':id' => $reward_id, ':parent_id' => $main_parent_id]);
            $statusRow = $statusStmt->fetch(PDO::FETCH_ASSOC);
            if (!empty($statusRow) && ($statusRow['status'] ?? '') === 'available' && !empty($statusRow['denied_on'])) {
                $denied = true;
            }
        }
        if ($denied) {
            $messages[] = "Reward request denied.";
            ensureParentNotificationsTable();
            $rewardTitleStmt = $db->prepare("SELECT title FROM rewards WHERE id = :id AND parent_user_id = :parent_id");
            $rewardTitleStmt->execute([':id' => $reward_id, ':parent_id' => $main_parent_id]);
            $rewardTitle = $rewardTitleStmt->fetchColumn() ?: 'Reward';
            $resolvedMessage = 'Reward denied: ' . $rewardTitle . ' | ' . date('m/d/Y h:i A');
            if ($deny_note !== '') {
                $resolvedMessage .= ' | Reason: ' . $deny_note;
            }
            $update = $db->prepare("UPDATE parent_notifications
                                    SET type = 'reward_denied',
                                        message = :message,
                                        is_read = 1
                                    WHERE parent_user_id = :parent_id
                                      AND type = 'reward_redeemed'
                                      AND link_url LIKE :link");
            $update->execute([
                ':message' => $resolvedMessage,
                ':parent_id' => $main_parent_id,
                ':link' => '%highlight_reward=' . (int) $reward_id . '%'
            ]);
        } else {
            $messages[] = "Unable to deny reward request.";
        }
    } elseif (isset($_POST['create_template'])) {
        $title = trim((string)filter_input(INPUT_POST, 'template_title', FILTER_SANITIZE_STRING));
        $description = trim((string)filter_input(INPUT_POST, 'template_description', FILTER_SANITIZE_STRING));
        $point_cost = filter_input(INPUT_POST, 'template_point_cost', FILTER_VALIDATE_INT);
        $level_required = filter_input(INPUT_POST, 'template_level_required', FILTER_VALIDATE_INT);
        $disabled_children = array_map('intval', $_POST['disabled_child_ids'] ?? []);
        $disable_all = !empty($_POST['disable_all_children']);
        if ($disable_all) {
            $disabled_children = array_column($children, 'child_user_id');
        }
        if ($title !== '' && $point_cost !== false && $point_cost !== null && $point_cost > 0) {
            $templateId = createRewardTemplate($main_parent_id, $title, $description, $point_cost, (int) ($level_required ?: 1), $_SESSION['user_id']);
            if ($templateId) {
                setRewardTemplateDisabledChildren($main_parent_id, $templateId, $disabled_children);
                $messages[] = "Template created.";
            } else {
                $messages[] = "Unable to create template.";
            }
        } else {
            $messages[] = "Enter a title and point cost for the template.";
        }
    } elseif (isset($_POST['update_template'])) {
        $template_id = filter_input(INPUT_POST, 'template_id', FILTER_VALIDATE_INT);
        $title = trim((string)filter_input(INPUT_POST, 'template_title', FILTER_SANITIZE_STRING));
        $description = trim((string)filter_input(INPUT_POST, 'template_description', FILTER_SANITIZE_STRING));
        $point_cost = filter_input(INPUT_POST, 'template_point_cost', FILTER_VALIDATE_INT);
        $level_required = filter_input(INPUT_POST, 'template_level_required', FILTER_VALIDATE_INT);
        $disabled_children = array_map('intval', $_POST['disabled_child_ids'] ?? []);
        $disable_all = !empty($_POST['disable_all_children']);
        if ($disable_all) {
            $disabled_children = array_column($children, 'child_user_id');
        }
        if ($template_id && $title !== '' && $point_cost !== false && $point_cost !== null && $point_cost > 0) {
            $updated = updateRewardTemplate($main_parent_id, $template_id, $title, $description, $point_cost, (int) ($level_required ?: 1));
            $disabledUpdated = setRewardTemplateDisabledChildren($main_parent_id, $template_id, $disabled_children);
            $messages[] = ($updated || $disabledUpdated)
                ? "Template updated."
                : "Template could not be updated.";
        } else {
            $messages[] = "Provide a title and point cost to update the template.";
        }
    } elseif (isset($_POST['duplicate_template'])) {
        $template_id = filter_input(INPUT_POST, 'template_id', FILTER_VALIDATE_INT);
        if ($template_id) {
            $newId = duplicateRewardTemplate($main_parent_id, $template_id, $_SESSION['user_id']);
            $messages[] = $newId ? "Reward duplicated." : "Unable to duplicate reward.";
        } else {
            $messages[] = "Invalid reward selected for duplication.";
        }
    } elseif (isset($_POST['delete_template'])) {
        $template_id = filter_input(INPUT_POST, 'template_id', FILTER_VALIDATE_INT);
        if ($template_id) {
            $messages[] = deleteRewardTemplate($main_parent_id, $template_id)
                ? "Template deleted."
                : "Template could not be deleted.";
        }
    } elseif (isset($_POST['update_level_settings'])) {
        $stars_per_level = filter_input(INPUT_POST, 'stars_per_level', FILTER_VALIDATE_INT);
        if ($stars_per_level && $stars_per_level > 0) {
            updateFamilyStarsPerLevel($main_parent_id, (int) $stars_per_level);
            $messages[] = "Level settings updated.";
        } else {
            $messages[] = "Enter a valid star count per level.";
        }
    }
}

$starsPerLevel = getFamilyStarsPerLevel($main_parent_id);
$templates = getRewardTemplates($main_parent_id);
$childIds = array_values(array_unique(array_filter(array_map('intval', array_column($children, 'child_user_id')))));
$disabledMapByChild = !empty($childIds) ? getRewardTemplateDisabledMap($main_parent_id, $childIds) : [];
$disabledByTemplate = [];
foreach ($disabledMapByChild as $childId => $templateIds) {
    foreach ($templateIds as $templateId) {
        $disabledByTemplate[$templateId][] = $childId;
    }
}
foreach ($disabledByTemplate as $templateId => $childList) {
    $uniqueChildIds = array_values(array_unique(array_map('intval', $childList)));
    sort($uniqueChildIds);
    $disabledByTemplate[$templateId] = $uniqueChildIds;
}
$templatesById = [];
foreach ($templates as $template) {
    $templateId = (int) ($template['id'] ?? 0);
    if ($templateId > 0) {
        $templatesById[$templateId] = $template;
    }
}

$templatePurchaseCounts = [];
$templateIds = array_values(array_filter(array_map('intval', array_column($templates, 'id'))));
if (!empty($templateIds)) {
    $placeholders = implode(',', array_fill(0, count($templateIds), '?'));
    $params = array_merge([(int) $main_parent_id], $templateIds);
    $purchaseStmt = $db->prepare("SELECT template_id, COUNT(*) AS purchase_count
                                  FROM rewards
                                  WHERE parent_user_id = ?
                                    AND status = 'redeemed'
                                    AND template_id IN ($placeholders)
                                    AND DATE(redeemed_on) = CURDATE()
                                  GROUP BY template_id");
    $purchaseStmt->execute($params);
    foreach ($purchaseStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $templatePurchaseCounts[(int) ($row['template_id'] ?? 0)] = (int) ($row['purchase_count'] ?? 0);
    }
}

$shopIconPalette = [
    ['color' => '#48bfe3', 'icon' => 'fa-solid fa-gift'],
    ['color' => '#f8961e', 'icon' => 'fa-solid fa-star'],
    ['color' => '#43aa8b', 'icon' => 'fa-solid fa-book'],
    ['color' => '#f94144', 'icon' => 'fa-solid fa-gamepad'],
    ['color' => '#f9c74f', 'icon' => 'fa-solid fa-smile'],
    ['color' => '#577590', 'icon' => 'fa-solid fa-puzzle-piece']
];
$shopIconCount = count($shopIconPalette);

$activeRewardStmt = $db->prepare("
    SELECT 
        r.id,
        r.title,
        r.point_cost,
        r.created_on,
        r.child_user_id,
        COALESCE(
            NULLIF(TRIM(CONCAT(COALESCE(cu.first_name, ''), ' ', COALESCE(cu.last_name, ''))), ''),
            NULLIF(cu.name, ''),
            cu.username,
            'All children'
        ) AS child_name
    FROM rewards r
    LEFT JOIN users cu ON r.child_user_id = cu.id
    WHERE r.parent_user_id = :parent_id AND r.status = 'available'
      AND NOT EXISTS (
          SELECT 1 FROM goals g
          WHERE g.reward_id = r.id
            AND g.award_mode IN ('reward', 'both')
            AND g.status IN ('active', 'pending_approval', 'rejected')
      )
    ORDER BY r.created_on DESC
    LIMIT 50
");
$activeRewardStmt->execute([':parent_id' => $main_parent_id]);
$recentRewards = $activeRewardStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
$dashboardData = getDashboardData($_SESSION['user_id']);
$totalPointsEarned = (int) ($dashboardData['total_points_earned'] ?? 0);
$redeemedRewards = $dashboardData['redeemed_rewards'] ?? [];
$childLevelMap = [];
if (!empty($dashboardData['children']) && is_array($dashboardData['children'])) {
    foreach ($dashboardData['children'] as $childRow) {
        $childId = (int) ($childRow['child_user_id'] ?? 0);
        if ($childId > 0) {
            $childLevelMap[$childId] = (int) ($childRow['level'] ?? 1);
        }
    }
}
$redeemedRewardsByChild = [];
foreach ($redeemedRewards as $redeemedReward) {
    $cid = (int)($redeemedReward['child_user_id'] ?? 0);
    if (!isset($redeemedRewardsByChild[$cid])) {
        $redeemedRewardsByChild[$cid] = [];
    }
    $redeemedRewardsByChild[$cid][] = $redeemedReward;
}
$weekStart = new DateTime('monday this week');
$weekStart->setTime(0, 0, 0);
$weekEnd = new DateTime('sunday this week');
$weekEnd->setTime(23, 59, 59);
$weekStartTs = $weekStart->getTimestamp();
$weekEndTs = $weekEnd->getTimestamp();
$purchasedThisWeekByChild = [];
foreach ($redeemedRewards as $redeemedReward) {
    $cid = (int) ($redeemedReward['child_user_id'] ?? 0);
    $redeemedOn = $redeemedReward['redeemed_on'] ?? null;
    if ($cid <= 0 || empty($redeemedOn)) {
        continue;
    }
    $stamp = strtotime($redeemedOn);
    if ($stamp === false || $stamp < $weekStartTs || $stamp > $weekEndTs) {
        continue;
    }
    if (!isset($purchasedThisWeekByChild[$cid])) {
        $purchasedThisWeekByChild[$cid] = [];
    }
    $purchasedThisWeekByChild[$cid][] = $redeemedReward;
}
$pendingFulfillmentByChild = [];
$pendingRewardsByChild = [];
foreach ($redeemedRewardsByChild as $cid => $rewardsList) {
    $pendingCount = 0;
    $pendingRewardsByChild[$cid] = [];
    foreach ($rewardsList as $r) {
        if (empty($r['fulfilled_on'])) {
            $pendingCount++;
            $pendingRewardsByChild[$cid][] = $r;
        }
    }
    $pendingFulfillmentByChild[$cid] = $pendingCount;
}
$disabledRewardsByChild = [];
foreach ($children as $child) {
    $cid = (int) ($child['child_user_id'] ?? 0);
    $disabledRewardsByChild[$cid] = [];
    $templateIds = $disabledMapByChild[$cid] ?? [];
    foreach ($templateIds as $templateId) {
        if (isset($templatesById[$templateId])) {
            $disabledRewardsByChild[$cid][] = $templatesById[$templateId];
        }
    }
}
$childRewards = [];
// Seed all children so they always show
foreach ($children as $child) {
    $cid = (int)($child['child_user_id'] ?? 0);
    $name = trim((string)$child['child_name']);
    $first = $name !== '' ? explode(' ', $name)[0] : 'Child';
    $avatar = !empty($child['avatar']) ? $child['avatar'] : 'images/default-avatar.png';
    $childRewards[$cid] = [
        'child_user_id' => $cid,
        'name' => $first,
        'avatar' => $avatar,
        'level' => $childLevelMap[$cid] ?? 1,
        'rewards' => []
    ];
}
// Child reward cards remain tied to active children only.
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rewards Shop</title>
    <link rel="stylesheet" href="css/main.css?v=3.26.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css" integrity="Evv84Mr4kqVGRNSgIGL/F/aIDqQb7xQ2vcrdIwxfjThSH8CSR7PBEakCr51Ck+w+/U6swU2Im1vVX0SVk9ABhg==" crossorigin="anonymous" referrerpolicy="no-referrer">
    <style>
        body { font-family: Arial, sans-serif; background: #f5f7fb; }
        .page { max-width: 960px; margin: 20px auto; padding: 20px; background: #fff; border-radius: 10px; box-shadow: 0 6px 20px rgba(0,0,0,0.08); }
        h1, h2 { margin-top: 0; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 16px; margin-top: 20px;}
        .card { background: #fafbff; border: 1px solid #e4e7ef; border-radius: 8px; padding: 16px; box-shadow: 0 2px 6px rgba(0,0,0,0.04); }
        .shop-parent-card { padding: 0; background: #e9f9ff; border: 2px solid #d7f1fb; overflow: hidden; }
        .shop-parent-header { background: #79d1e6; color: #fff; padding: 16px 18px; display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; }
        .shop-parent-title { display: grid; gap: 4px; }
        .shop-parent-title h2 { margin: 0; font-size: 1.2rem; }
        .shop-parent-subtitle { font-size: 0.85rem; opacity: 0.9; }
        .shop-parent-meta { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
        .shop-parent-points { display: inline-flex; align-items: center; gap: 6px; font-weight: 700; }
        .shop-parent-points i { color: #fde68a; }
        .shop-parent-actions { display: flex; gap: 8px; align-items: center; }
        .shop-parent-body { padding: 18px; background: #f8fdff; }
        .card-title-row { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin-bottom: 15px; }
        .form-group { margin-bottom: 12px; }
        .form-group label { display: block; margin-bottom: 6px; }
        .form-group input, .form-group textarea, .form-group select { width: 100%; padding: 8px; }
        input, textarea, select { border-radius: 8px; border: 1px solid #9f9f9f; background-color: #fff; transition: border-color 150ms ease, box-shadow 150ms ease; }
        input:focus, textarea:focus, select:focus { outline: none; border-color: #4caf50; box-shadow: 0 0 0 3px rgba(76, 175, 80, 0.15); }
        .button { padding: 10px 18px; background: #4caf50; color: #fff; border: none; border-radius: 6px; cursor: pointer; display: inline-block; text-decoration: none; font-weight: 700; }
        .button.secondary { background: #1565c0; }
        .button.danger { background: #c62828; }
        .reward-create-button { width: 52px; height: 52px; border-radius: 50%; border: none; background: #f59e0b; color: #fff; display: inline-flex; align-items: center; justify-content: center; font-size: 1.4rem; cursor: pointer; box-shadow: 0 6px 14px rgba(245, 158, 11, 0.35); }
        .reward-create-button:hover { background: #d97706; }
        .template-grid { display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-start; }
        .shop-template-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); gap: 16px; }
        .template-card { flex: 1 1 285px; border: 1px solid #e0e4ee; border-radius: 10px; padding: 14px; background: #fff; box-shadow: 0 2px 6px rgba(0,0,0,0.05); display: grid; gap: 10px; position: relative; max-width: 288px; }
        .shop-template-card { border: none; border-radius: 18px; padding: 14px; background: #fff; box-shadow: 0 8px 18px rgba(0,0,0,0.08); display: flex; flex-direction: column; gap: 10px; max-width: none; }
        .shop-template-header { display: grid; grid-template-columns: auto 1fr; gap: 12px; align-items: center; }
        .shop-template-icon { width: 52px; height: 52px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; color: #fff; font-size: 1.4rem; flex-shrink: 0; }
        .shop-template-title-row { display: flex; align-items: center; justify-content: space-between; gap: 10px; }
        .shop-template-title-row strong { font-size: 1.05rem; color: #111827; }
        .shop-template-content { display: grid; gap: 6px; }
        .shop-template-meta { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
        .shop-template-info { display: inline-flex; align-items: center; gap: 8px; color: #6b7280; font-size: 0.95rem; }
        .shop-template-actions { display: flex; justify-content: flex-end; margin-top: auto; }
        .shop-details { margin: 0; }
        .shop-details summary { list-style: none; cursor: pointer; color: #0284c7; font-weight: 600; }
        .shop-details summary::-webkit-details-marker { display: none; }
        .shop-details p { margin: 6px 0 0; color: #4b5563; font-size: 0.95rem; }
        .template-actions { display: flex; gap: 8px; justify-content: flex-end; flex-wrap: wrap; align-items: center; }
        .template-icon-button { border: none; background: transparent; color: #919191; padding: 6px 8px; border-radius: 8px; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 6px; }
        .template-icon-button:hover { background: rgba(0,0,0,0.04); color: #7a7a7a; }
        .template-icon-button.danger { color: #9f9f9f; }
        .template-icon-button.danger:hover { background: rgba(0,0,0,0.04); color: #7a7a7a; }
        .badge { display: inline-block; background: #4caf50; color: #fff; padding: 4px 8px; border-radius: 12px; font-size: 0.85em; font-weight: 600; }
        .level-badge { display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 999px; background: #fffbeb; color: #b45309; font-weight: 700; font-size: 0.85rem; border: 1px solid #fde68a; }
        .points-badge { background: #fffbeb; color: #f59e0b; padding: 4px 10px; border-radius: 999px; font-weight: 700; font-size: 0.85rem; display: inline-flex; align-items: center; gap: 6px; white-space: nowrap; }
        .points-badge::before { content: '\f005'; font-family: 'Font Awesome 6 Free'; font-weight: 900; }
        .level-pill { background: #eef2ff; color: #4338ca; padding: 4px 10px; border-radius: 999px; font-weight: 700; font-size: 0.85rem; display: inline-flex; align-items: center; }
        [data-child-disabled-list] .badge { background: #4caf50; color: #fff; font-weight: 600; }
        .message { background: #e8f5e9; color: #2e7d32; border: 1px solid #c8e6c9; padding: 10px 12px; border-radius: 6px; margin-bottom: 10px; }
        .recent-list { display: grid; gap: 8px; }
        .recent-item { border: 1px solid #eceff4; border-radius: 8px; padding: 10px; background: #fff; display: flex; justify-content: space-between; gap: 10px; }
        .child-select-group { display: grid; gap: 10px; }
        .child-select-grid { display: flex; flex-wrap: wrap; gap: 12px; }
        .child-select-card { border: none; border-radius: 50%; padding: 0; background: transparent; display: grid; justify-items: center; gap: 8px; cursor: pointer; position: relative; }
        .child-select-card input[type="checkbox"] { position: absolute; opacity: 0; width: 0; height: 0; pointer-events: none; }
        .child-select-card img { width: 52px; height: 52px; border-radius: 50%; object-fit: cover; box-shadow: 0 2px 6px rgba(0,0,0,0.15); transition: box-shadow 150ms ease, transform 150ms ease; }
        .child-select-icon { width: 52px; height: 52px; border-radius: 50%; display: grid; place-items: center; background: #e8f1ff; color: #1e3a8a; box-shadow: 0 2px 6px rgba(0,0,0,0.12); transition: box-shadow 150ms ease, transform 150ms ease; }
        .child-select-icon i { font-size: 1.1rem; }
        .child-select-card strong { font-size: 13px; width: min-content; text-align: center; transition: color 150ms ease, text-shadow 150ms ease; }
        .child-select-card:has(input[type="checkbox"]:checked) img { box-shadow: 0 0 0 4px rgba(100,181,246,0.8), 0 0 14px rgba(100,181,246,0.8); transform: translateY(-2px); }
        .child-select-card:has(input[type="checkbox"]:checked) .child-select-icon { box-shadow: 0 0 0 4px rgba(100,181,246,0.8), 0 0 14px rgba(100,181,246,0.8); transform: translateY(-2px); }
        .child-select-card:has(input[type="checkbox"]:checked) strong { color: #0d47a1; text-shadow: 0 1px 8px rgba(100,181,246,0.8); }
        .hidden { display: none !important; }
        .child-reward-grid { display: flex; justify-content: flex-start; gap: 12px; flex-wrap: wrap; margin-top: 12px; }
        .child-reward-card { border: 1px solid #e0e4ee; border-radius: 12px; padding: 14px; background: #fff; box-shadow: 0 2px 8px rgba(0,0,0,0.06); display: grid; gap: 12px; max-width: fit-content; }
        .child-header { display: flex; align-items: center; gap: 12px; }
        .child-header img { width: 64px; height: 64px; border-radius: 50%; object-fit: cover; box-shadow: 0 2px 8px rgba(0,0,0,0.15); }
        .child-meta { display: flex; gap: 10px; flex-wrap: wrap; width: 185px; margin-bottom: 10px; font-weight: 700; color: #2c3e50; }
        .reward-badge-title-header {font-size: 12px;width: 100%; color: #9f9f9f;}
        .child-meta .badge { background: #4caf50; color: #fff; cursor: pointer; border: none; font-weight: 600; }
        .child-meta .badge-link { border-radius: 12px; padding: 4px 8px; }
        .child-badge-row { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
        .child-pending-badge { display: inline-flex; align-items: center; gap: 6px; padding: 6px 10px; border-radius: 999px; background: #eef4ff; color: #0d47a1; font-weight: 700; border: 1px solid #d5def0; font-size: 0.9em; }
        .child-pending-badge i { font-size: 0.95em; }
        .reward-list { width: 100%; }
        .reward-card { gap: 8px; width: 100%; max-width: none; }
        .reward-card-header { display: flex; justify-content: space-between; align-items: center; gap: 10px; }
        .reward-card-meta { display: inline-flex; align-items: center; gap: 10px; margin-left: auto; }
        .reward-actions { display: inline-flex; gap: 8px; }
        .reward-actions-menu { position: relative; }
        .reward-actions-toggle { list-style: none; width: 42px; height: 42px; border-radius: 14px; border: 1px solid #e0e0e0; background: #f5f7fb; color: #546e7a; display: inline-flex; align-items: center; justify-content: center; cursor: pointer; }
        .reward-actions-toggle::-webkit-details-marker,
        .reward-actions-toggle::marker { display: none; }
        .reward-actions-dropdown { position: absolute; right: 0; top: 44px; background: #fff; border: 1px solid #e0e0e0; border-radius: 12px; padding: 6px; box-shadow: 0 8px 18px rgba(0,0,0,0.12); display: grid; gap: 4px; min-width: 180px; z-index: 50; }
        .reward-actions-menu:not([open]) .reward-actions-dropdown { display: none; }
        .reward-actions-dropdown button { background: transparent; border: none; text-align: left; padding: 8px 10px; border-radius: 8px; display: flex; gap: 8px; align-items: center; font-weight: 600; color: #37474f; cursor: pointer; }
        .reward-actions-dropdown button:hover { background: #f5f5f5; }
        .reward-actions-dropdown .danger { color: #d32f2f; }
        .shop-template-actions .reward-actions-dropdown { top: auto; bottom: 44px; }
        .reward-title-row { display: flex; align-items: center; gap: 10px; }
        .reward-library-header { display: flex; align-items: center; justify-content: space-between; gap: 10px; }
        .recent-meta { display: inline-flex; flex-direction: column; gap: 6px; align-items: flex-end; text-align: right; }
        .reward-card.highlight { border: 2px solid #f9a825; box-shadow: 0 0 0 3px rgba(249,168,37,0.2); }
        .icon-button { border: none; background: transparent; cursor: pointer; color: #919191; padding: 6px 8px; border-radius: 8px; display: inline-flex; align-items: center; justify-content: center; }
        .icon-button:hover { background: rgba(0,0,0,0.04); color: #7a7a7a; }
        .icon-button.danger { color: #919191; }
        .icon-button.danger:hover { background: rgba(0,0,0,0.04); color: #7a7a7a; }
        .reward-card-body { color: #444; font-size: 0.95em; }
        .reward-edit-actions { display: flex; gap: 8px; align-items: center; }
        .modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.45); display: none; align-items: center; justify-content: center; z-index: 999; padding: 16px; }
        .modal-backdrop.open { display: flex; }
        .modal { background: #fff; border-radius: 12px; padding: 16px; max-width: 520px; width: 100%; box-shadow: 0 12px 30px rgba(0,0,0,0.18); max-height: 85vh; display: flex; flex-direction: column; }
        .modal header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
        .modal-close { border: none; background: transparent; font-size: 20px; cursor: pointer; }
        #modal-body { overflow-y: auto; max-height: 70vh; }
        body.modal-open { overflow: hidden; }
        @media (max-width: 640px) {
            .template-card { max-width: 100%; width: 100%; padding-right: 64px; }
            .template-card .template-actions { position: absolute; top: 10px; right: 10px; justify-content: flex-end; }
            .shop-template-card { padding-right: 14px; }
            .child-header { flex-direction: column; align-items: flex-start; }
            .child-meta { width: 100%; }
            .add-child-reward-btn { width: 100%; justify-content: center; }
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
        .help-modal { position: fixed; inset: 0; background: rgba(0,0,0,0.45); display: none; align-items: center; justify-content: center; z-index: 1200; padding: 16px; }
        .help-modal.open { display: flex; }
        .help-card { background: #fff; border-radius: 12px; max-width: 720px; width: min(720px, 100%); max-height: 85vh; overflow: hidden; box-shadow: 0 12px 30px rgba(0,0,0,0.18); display: grid; grid-template-rows: auto 1fr; }
        .help-card header { display: flex; justify-content: space-between; align-items: center; padding: 12px 16px; border-bottom: 1px solid #e0e0e0; }
        .help-card h2 { margin: 0; font-size: 1.1rem; }
        .help-close { border: none; background: transparent; font-size: 20px; cursor: pointer; }
        .help-body { padding: 12px 16px 16px; overflow-y: auto; display: grid; gap: 12px; }
        .help-section h3 { margin: 0 0 6px; font-size: 1rem; color: #37474f; }
        .help-section ul { margin: 0; padding-left: 18px; display: grid; gap: 6px; color: #455a64; }
    </style>
</head>
<body>
    <?php
        $dashboardPage = canCreateContent($_SESSION['user_id']) ? 'dashboard_parent.php' : 'dashboard_child.php';
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
                <h1>Rewards Shop</h1>
                <p class="page-header-meta">Welcome back, <?php echo htmlspecialchars($_SESSION['name'] ?? $_SESSION['username'] ?? 'User'); ?></p>
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
                    <span>Rewards Shop</span>
                </a>
                <a class="nav-link<?php echo $profileActive ? ' is-active' : ''; ?>" href="profile.php?self=1"<?php echo $profileActive ? ' aria-current="page"' : ''; ?>>
                    <i class="fa-solid fa-user"></i>
                    <span>Profile</span>
                </a>
            </nav>
        </header>

        <?php foreach ($messages as $msg): ?>
            <div class="message"><?php echo htmlspecialchars($msg); ?></div>
        <?php endforeach; ?>

        <div class="card" style="margin-top:20px;">
            <div class="card-title-row">
                <h2>Level Settings</h2>
            </div>
            <form method="POST" action="rewards.php" style="display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end;">
                <div class="form-group" style="min-width:220px;">
                    <label for="stars_per_level">Stars per level</label>
                    <input type="number" id="stars_per_level" name="stars_per_level" min="1" value="<?php echo (int) $starsPerLevel; ?>" required>
                </div>
                <button type="submit" name="update_level_settings" class="button secondary">Save Level Settings</button>
            </form>
            <p style="margin:0; color:#666; font-size:0.9rem;">Based on a rolling 4-week average of routine stars.</p>
        </div>

        <div class="card" style="margin-top:20px;">
            <h2>Reward Stats</h2>
            <?php if (!empty($childRewards)): ?>
                <div class="child-reward-grid">
                        <?php foreach ($childRewards as $childCard): 
                            $cid = (int)$childCard['child_user_id'];
                            $disabledCount = count($disabledRewardsByChild[$cid] ?? []);
                            $purchasedCount = count($purchasedThisWeekByChild[$cid] ?? []);
                        ?>
                        <div class="child-reward-card">
                            <div class="child-header">
                                <img src="<?php echo htmlspecialchars($childCard['avatar']); ?>" alt="<?php echo htmlspecialchars($childCard['name']); ?>">
                                <div>
                                        <strong><?php echo htmlspecialchars($childCard['name']); ?></strong>
                                        <div class="child-meta">
                                        <p class="reward-badge-title-header">Rewards Status</p> 
                                            <button type="button" class="badge badge-link" data-action="show-disabled-modal" data-child-id="<?php echo $cid; ?>"><?php echo $disabledCount; ?> disabled</button>
                                            <button type="button" class="badge badge-link" data-action="show-purchased-modal" data-child-id="<?php echo $cid; ?>"><?php echo $purchasedCount; ?> purchased</button>
                                        </div>
                                        <?php $pendingFulfill = $pendingFulfillmentByChild[$cid] ?? 0; ?>
                                <?php if ($pendingFulfill > 0): ?>
                                    <div class="child-badge-row">
                                        <button type="button" class="child-pending-badge" data-action="show-pending-modal" data-child-id="<?php echo $cid; ?>">
                                            <i class="fa-solid fa-gift"></i> Awaiting fulfillment: <?php echo $pendingFulfill; ?>
                                        </button>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="level-badge">
                                <i class="fa-solid fa-star"></i>
                                <span>Level <?php echo (int) ($childCard['level'] ?? 1); ?></span>
                            </div>
                        </div>
                            <div class="hidden" data-child-disabled-list="<?php echo $cid; ?>" id="disabled-child-<?php echo $cid; ?>" style="width:100%;">
                                <div class="reward-list" style="display:grid; gap:12px; width:100%;">
                                    <?php if (!empty($disabledRewardsByChild[$cid])): ?>
                                        <?php foreach ($disabledRewardsByChild[$cid] as $reward): ?>
                                            <div class="template-card reward-card" data-template-id="<?php echo (int)$reward['id']; ?>" style="width:100%;">
                                                <div class="reward-card-header">
                                                    <strong><?php echo htmlspecialchars($reward['title']); ?></strong>
                                                    <div class="reward-card-meta">
                                                        <span class="points-badge"><?php echo (int)$reward['point_cost']; ?></span>
                                                        <span class="level-pill">Lvl <?php echo (int) ($reward['level_required'] ?? 1); ?></span>
                                                    </div>
                                                </div>
                                                <div class="reward-card-body">
                                                    <?php if (!empty($reward['description'])): ?>
                                                        <p><?php echo nl2br(htmlspecialchars($reward['description'])); ?></p>
                                                    <?php endif; ?>
                                                    <p class="awaiting-label">Disabled for this child.</p>
                                                    <button type="button" class="button secondary" data-action="edit-template" data-template-id="<?php echo (int)$reward['id']; ?>">
                                                        <i class="fa fa-pen"></i>
                                                        Edit Reward
                                                    </button>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <p>No disabled rewards for this child.</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="hidden" data-child-purchased-list="<?php echo $cid; ?>" id="purchased-child-<?php echo $cid; ?>" style="width:100%;">
                                <div class="reward-list" style="display:grid; gap:12px; width:100%;">
                                    <?php $purchasedList = $purchasedThisWeekByChild[$cid] ?? []; ?>
                                    <?php if (!empty($purchasedList)): ?>
                                        <?php foreach ($purchasedList as $reward): ?>
                                            <div class="template-card reward-card" id="purchased-reward-<?php echo (int)$reward['id']; ?>" data-reward-card-id="<?php echo (int)$reward['id']; ?>" style="width:100%;">
                                                <div class="reward-card-header">
                                                    <strong><?php echo htmlspecialchars($reward['title']); ?></strong>
                                                    <div class="reward-card-meta">
                                                        <span class="points-badge"><?php echo (int)$reward['point_cost']; ?></span>
                                                    </div>
                                                </div>
                                                <div class="reward-card-body">
                                                    <p>Purchased on: <?php echo !empty($reward['redeemed_on']) ? htmlspecialchars(date('m/d/Y h:i A', strtotime($reward['redeemed_on']))) : 'Date unavailable'; ?></p>
                                                    <?php if (!empty($reward['description'])): ?>
                                                        <p><?php echo nl2br(htmlspecialchars($reward['description'])); ?></p>
                                                    <?php endif; ?>
                                                    <?php if (!empty($reward['fulfilled_on'])): ?>
                                                        <p>Fulfilled on: <?php echo htmlspecialchars(date('m/d/Y h:i A', strtotime($reward['fulfilled_on']))); ?><?php if (!empty($reward['fulfilled_by_name'])): ?> by <?php echo htmlspecialchars($reward['fulfilled_by_name']); ?><?php endif; ?></p>
                                                    <?php else: ?>
                                                        <p class="awaiting-label">Awaiting fulfillment by parent.</p>
                                                    <form method="POST" action="rewards.php" class="inline-form">
                                                        <input type="hidden" name="reward_id" value="<?php echo (int)$reward['id']; ?>">
                                                        <button type="submit" name="fulfill_reward" class="button approve-button">Mark Fulfilled</button>
                                                    </form>
                                                    <form method="POST" action="rewards.php" class="inline-form" style="margin-top:6px;">
                                                        <input type="hidden" name="reward_id" value="<?php echo (int)$reward['id']; ?>">
                                                        <textarea name="deny_reward_note" rows="2" placeholder="Optional deny note" style="width:100%; max-width:360px;"></textarea>
                                                        <button type="submit" name="deny_reward" class="button secondary">Deny</button>
                                                    </form>
                                                <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <p>No purchases yet this week.</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="hidden" data-child-pending-list="<?php echo $cid; ?>" id="pending-child-<?php echo $cid; ?>" style="width:100%;">
                                <div class="reward-list" style="display:grid; gap:12px; width:100%;">
                                    <?php if (!empty($pendingRewardsByChild[$cid])): ?>
                                        <?php foreach ($pendingRewardsByChild[$cid] as $reward): ?>
                                            <div class="template-card reward-card" id="pending-reward-<?php echo (int)$reward['id']; ?>" data-reward-card-id="<?php echo (int)$reward['id']; ?>" style="width:100%;">
                                                <div class="reward-card-header">
                                                    <strong><?php echo htmlspecialchars($reward['title']); ?></strong>
                                                    <div class="reward-card-meta">
                                                        <span class="points-badge"><?php echo (int)$reward['point_cost']; ?></span>
                                                    </div>
                                                </div>
                                                <div class="reward-card-body">
                                                    <p>Purchased on: <?php echo !empty($reward['redeemed_on']) ? htmlspecialchars(date('m/d/Y h:i A', strtotime($reward['redeemed_on']))) : 'Date unavailable'; ?></p>
                                                    <?php if (!empty($reward['description'])): ?>
                                                        <p><?php echo nl2br(htmlspecialchars($reward['description'])); ?></p>
                                                    <?php endif; ?>
                                                    <p class="awaiting-label">Awaiting fulfillment by parent.</p>
                                                    <form method="POST" action="rewards.php" class="inline-form">
                                                        <input type="hidden" name="reward_id" value="<?php echo (int)$reward['id']; ?>">
                                                        <button type="submit" name="fulfill_reward" class="button approve-button">Mark Fulfilled</button>
                                                    </form>
                                                    <form method="POST" action="rewards.php" class="inline-form" style="margin-top:6px;">
                                                        <input type="hidden" name="reward_id" value="<?php echo (int)$reward['id']; ?>">
                                                        <textarea name="deny_reward_note" rows="2" placeholder="Optional deny note" style="width:100%; max-width:360px;"></textarea>
                                                        <button type="submit" name="deny_reward" class="button secondary">Deny</button>
                                                    </form>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <p>No rewards awaiting fulfillment for this child.</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p>No rewards available.</p>
            <?php endif; ?>
        </div>

        <div class="card shop-parent-card" style="margin-top:20px;">
            <div class="shop-parent-header">
                <div class="shop-parent-title">
                    <h2>Rewards Shop</h2>
                    <span class="shop-parent-subtitle">Rewards available for the family shop.</span>
                </div>
                <div class="shop-parent-meta">
                    <div class="shop-parent-points">Available points: <?php echo (int) $totalPointsEarned; ?> <i class="fa-solid fa-star"></i></div>
                    <div class="shop-parent-actions">
                        <button type="button" class="button secondary" data-action="toggle-template-grid" aria-expanded="true">
                            <span data-template-toggle-label>Close Shop</span>
                            <i class="fa-solid fa-caret-up" data-template-toggle-icon></i>
                        </button>
                        <button type="button" class="reward-create-button" data-action="open-create-template-modal" aria-label="Create reward">
                            <i class="fa-solid fa-plus"></i>
                        </button>
                    </div>
                </div>
            </div>
            <div class="shop-parent-body">
                <?php if (!empty($templates)): ?>
                    <div class="template-grid shop-template-grid" data-template-grid>
                        <?php foreach ($templates as $index => $template): ?>
                                <?php
                                $templateId = (int) ($template['id'] ?? 0);
                                $disabledChildren = $disabledByTemplate[$templateId] ?? [];
                                $disableAllChildren = !empty($childIds)
                                    && empty(array_diff($childIds, $disabledChildren))
                                    && !empty($disabledChildren);
                                $purchaseCount = $templatePurchaseCounts[$templateId] ?? 0;
                                $palette = $shopIconPalette[$shopIconCount > 0 ? ($index % $shopIconCount) : 0] ?? [];
                                $iconColor = $palette['color'] ?? '#48bfe3';
                                $iconClass = $palette['icon'] ?? 'fa-solid fa-gift';
                                ?>
                                <div class="template-card shop-template-card" data-template-card="<?php echo $templateId; ?>">
                                    <div class="shop-template-header">
                                        <span class="shop-template-icon" style="background: <?php echo htmlspecialchars($iconColor); ?>;">
                                            <i class="<?php echo htmlspecialchars($iconClass); ?>"></i>
                                        </span>
                                        <div class="shop-template-content">
                                            <div class="shop-template-title-row">
                                                <strong><?php echo htmlspecialchars($template['title']); ?></strong>
                                                <span class="points-badge"><?php echo (int)$template['point_cost']; ?></span>
                                            </div>
                                            <div class="shop-template-meta">
                                                <span class="level-pill">Lvl <?php echo (int) ($template['level_required'] ?? 1); ?></span>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="shop-template-info">
                                        <i class="fa-solid fa-circle-info"></i>
                                        <span>Purchased <?php echo (int) $purchaseCount; ?> today</span>
                                    </div>
                                    <?php if (!empty($template['description'])): ?>
                                        <details class="shop-details">
                                            <summary>View Details</summary>
                                            <p><?php echo nl2br(htmlspecialchars($template['description'])); ?></p>
                                        </details>
                                    <?php endif; ?>
                                    <div class="shop-template-actions">
                                        <details class="reward-actions-menu">
                                            <summary class="reward-actions-toggle" aria-label="Reward template actions">
                                                <i class="fa-solid fa-ellipsis-vertical"></i>
                                            </summary>
                                            <div class="reward-actions-dropdown">
                                                <button type="button" data-action="edit-template" data-template-id="<?php echo (int)$template['id']; ?>">
                                                    <i class="fa-solid fa-pen"></i>
                                                    Edit Reward
                                                </button>
                                                <form method="POST" action="rewards.php">
                                                    <input type="hidden" name="template_id" value="<?php echo (int)$template['id']; ?>">
                                                    <button type="submit" name="duplicate_template">
                                                        <i class="fa-solid fa-copy"></i>
                                                        Duplicate Reward
                                                    </button>
                                                </form>
                                                <form method="POST" action="rewards.php" onsubmit="return confirm('Delete this template?');">
                                                    <input type="hidden" name="template_id" value="<?php echo (int)$template['id']; ?>">
                                                    <button type="submit" name="delete_template" class="danger">
                                                        <i class="fa-solid fa-trash"></i>
                                                        Delete Reward
                                                    </button>
                                                </form>
                                            </div>
                                        </details>
                                    </div>
                                <form method="POST" action="rewards.php" class="reward-edit-form hidden" data-template-form="<?php echo $templateId; ?>" style="display:grid; gap:10px; margin-top:8px;">
                                <input type="hidden" name="template_id" value="<?php echo $templateId; ?>">
                                <div class="form-group">
                                    <label for="template_title_<?php echo $templateId; ?>">Title</label>
                                    <input type="text" id="template_title_<?php echo $templateId; ?>" name="template_title" value="<?php echo htmlspecialchars($template['title']); ?>" required>
                                </div>
                                <div class="form-group">
                                    <label for="template_description_<?php echo $templateId; ?>">Description</label>
                                    <textarea id="template_description_<?php echo $templateId; ?>" name="template_description"><?php echo htmlspecialchars($template['description']); ?></textarea>
                                </div>
                                <div class="form-group">
                                    <label for="template_point_cost_<?php echo $templateId; ?>">Point Cost</label>
                                    <div class="number-stepper">
                                        <button type="button" class="stepper-btn" data-step="-1" aria-label="Decrease points"><i class="fa fa-minus"></i></button>
                                        <input class="stepper-input" type="number" id="template_point_cost_<?php echo $templateId; ?>" name="template_point_cost" min="1" value="<?php echo (int)$template['point_cost']; ?>" required>
                                        <button type="button" class="stepper-btn" data-step="1" aria-label="Increase points"><i class="fa fa-plus"></i></button>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label for="template_level_required_<?php echo $templateId; ?>">Level Required</label>
                                    <div class="number-stepper">
                                        <button type="button" class="stepper-btn" data-step="-1" aria-label="Decrease level"><i class="fa fa-minus"></i></button>
                                        <input class="stepper-input" type="number" id="template_level_required_<?php echo $templateId; ?>" name="template_level_required" min="1" value="<?php echo (int) ($template['level_required'] ?? 1); ?>" required>
                                        <button type="button" class="stepper-btn" data-step="1" aria-label="Increase level"><i class="fa fa-plus"></i></button>
                                    </div>
                                </div>
                                <div class="form-group child-select-group">
                                    <label>Disable for Children</label>
                                    <div class="child-select-grid" data-disable-children-grid>
                                        <label class="child-select-card">
                                            <input type="checkbox" name="disable_all_children" value="1" data-disable-all<?php echo $disableAllChildren ? ' checked' : ''; ?>>
                                            <span class="child-select-icon"><i class="fa-solid fa-layer-group"></i></span>
                                            <strong>All Children</strong>
                                        </label>
                                        <?php if (!empty($children)): ?>
                                            <?php foreach ($children as $child): 
                                                $avatar = !empty($child['avatar']) ? $child['avatar'] : 'images/default-avatar.png';
                                                $childId = (int) ($child['child_user_id'] ?? 0);
                                                $checked = $disableAllChildren || in_array($childId, $disabledChildren, true);
                                            ?>
                                                <label class="child-select-card">
                                                    <input type="checkbox" name="disabled_child_ids[]" value="<?php echo $childId; ?>"<?php echo $checked ? ' checked' : ''; ?>>
                                                    <img src="<?php echo htmlspecialchars($avatar); ?>" alt="<?php echo htmlspecialchars($child['child_name']); ?>">
                                                    <strong><?php echo htmlspecialchars($child['child_name']); ?></strong>
                                                </label>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <p>No children found.</p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="reward-edit-actions">
                                    <button type="submit" name="update_template" class="button">Save Changes</button>
                                </div>
                            </form>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p>No templates yet.</p>
                <?php endif; ?>
            </div>
        </div>

<?php 
$recentLimit = 4;
$recentTotal = !empty($recentRewards) ? count($recentRewards) : 0;
$hasRecent = $recentTotal > 0;
$hasRecentMore = $recentTotal > $recentLimit;
?>
<div class="card" style="margin-top:20px;">
    <div class="card-title-row">
        <h2>Recently Added Rewards</h2>
        <button type="button" class="button secondary" data-action="toggle-recent-list" aria-expanded="<?php echo $hasRecent ? 'true' : 'false'; ?>" <?php if (!$hasRecent) echo 'disabled'; ?>>
            <span data-recent-toggle-label><?php echo $hasRecent ? 'Close' : 'View'; ?></span>
            <i class="fa-solid <?php echo $hasRecent ? 'fa-caret-up' : 'fa-caret-down'; ?>" data-recent-toggle-icon></i>
        </button>
    </div>
    <?php if ($hasRecent): ?>
        <div class="recent-list" data-recent-list>
            <?php foreach ($recentRewards as $idx => $reward): 
                $isExtra = $idx >= $recentLimit;
            ?>
                <div class="recent-item<?php echo $isExtra ? ' hidden' : ''; ?>" <?php if ($isExtra) echo 'data-recent-extra="1"'; ?>>
                    <div>
                        <strong><?php echo htmlspecialchars($reward['title']); ?></strong>
                        <div style="font-size:0.9em; color:#555;">For: <?php echo htmlspecialchars($reward['child_name'] ?? 'All children'); ?></div>
                    </div>
                    <div class="recent-meta">
                        <span class="points-badge"><?php echo (int)$reward['point_cost']; ?></span>
                        <div style="font-size:0.9em; color:#666;"><?php echo htmlspecialchars(date('m/d/Y', strtotime($reward['created_on']))); ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php if ($hasRecentMore): ?>
            <button type="button" class="button secondary" data-action="toggle-recent-more" aria-expanded="false" style="margin-top:10px;">
                View more
            </button>
        <?php endif; ?>
    <?php else: ?>
        <p>No rewards available yet.</p>
    <?php endif; ?>
</div>

        <div class="hidden" id="create-template-modal-content">
            <form method="POST" action="rewards.php" style="display:grid; gap:10px;">
                <div class="form-group">
                    <label for="template_title_modal">Title</label>
                    <input type="text" id="template_title_modal" name="template_title" required>
                </div>
                <div class="form-group">
                    <label for="template_description_modal">Description</label>
                    <textarea id="template_description_modal" name="template_description"></textarea>
                </div>
                <div class="form-group">
                    <label for="template_point_cost_modal">Point Cost</label>
                    <div class="number-stepper">
                        <button type="button" class="stepper-btn" data-step="-1" aria-label="Decrease points"><i class="fa fa-minus"></i></button>
                        <input class="stepper-input" type="number" id="template_point_cost_modal" name="template_point_cost" min="1" required>
                        <button type="button" class="stepper-btn" data-step="1" aria-label="Increase points"><i class="fa fa-plus"></i></button>
                    </div>
                </div>
                <div class="form-group">
                    <label for="template_level_required_modal">Level Required</label>
                    <div class="number-stepper">
                        <button type="button" class="stepper-btn" data-step="-1" aria-label="Decrease level"><i class="fa fa-minus"></i></button>
                        <input class="stepper-input" type="number" id="template_level_required_modal" name="template_level_required" min="1" value="1" required>
                        <button type="button" class="stepper-btn" data-step="1" aria-label="Increase level"><i class="fa fa-plus"></i></button>
                    </div>
                </div>
                <div class="form-group child-select-group">
                    <label>Disable for Children</label>
                    <div class="child-select-grid" data-disable-children-grid>
                        <label class="child-select-card">
                            <input type="checkbox" name="disable_all_children" value="1" data-disable-all>
                            <span class="child-select-icon"><i class="fa-solid fa-layer-group"></i></span>
                            <strong>All Children</strong>
                        </label>
                        <?php if (!empty($children)): ?>
                            <?php foreach ($children as $child): 
                                $avatar = !empty($child['avatar']) ? $child['avatar'] : 'images/default-avatar.png';
                            ?>
                                <label class="child-select-card">
                                    <input type="checkbox" name="disabled_child_ids[]" value="<?php echo (int)$child['child_user_id']; ?>">
                                    <img src="<?php echo htmlspecialchars($avatar); ?>" alt="<?php echo htmlspecialchars($child['child_name']); ?>">
                                    <strong><?php echo htmlspecialchars($child['child_name']); ?></strong>
                                </label>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p>No children found.</p>
                        <?php endif; ?>
                    </div>
                </div>
                <button type="submit" name="create_template" class="button">Save Template</button>
            </form>
        </div>
    <div class="help-modal" data-help-modal>
        <div class="help-card" role="dialog" aria-modal="true" aria-labelledby="help-title">
            <header>
                <h2 id="help-title">Rewards Help</h2>
                <button type="button" class="help-close" data-help-close aria-label="Close help"><i class="fa-solid fa-xmark"></i></button>
            </header>
            <div class="help-body">
                <section class="help-section">
                    <h3>Rewards</h3>
                    <ul>
                        <li>Create rewards for the shop and disable them for specific children if needed.</li>
                        <li>Use the child cards to review disabled rewards, weekly purchases, and pending fulfillment.</li>
                        <li>Fulfill purchased rewards from the parent dashboard notifications.</li>
                    </ul>
                </section>
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
            <span>Rewards Shop</span>
        </a>
    </nav>
  <script src="js/number-stepper.js" defer></script>
<?php if (!empty($isParentNotificationUser)): ?>
    <?php include __DIR__ . '/includes/notifications_parent.php'; ?>
<?php endif; ?>
<?php if (!empty($isChildNotificationUser)): ?>
    <?php include __DIR__ . '/includes/notifications_child.php'; ?>
<?php endif; ?>
</body>
<div class="modal-backdrop" id="modal-backdrop" aria-hidden="true">
    <div class="modal" role="dialog" aria-modal="true">
        <header>
            <h3 id="modal-title">Edit</h3>
            <button class="modal-close" type="button" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
        </header>
        <div id="modal-body"></div>
    </div>
</div>
<script>
    (function() {
        const editButtons = document.querySelectorAll('[data-action="edit-template"]');
        editButtons.forEach(btn => {
            btn.addEventListener('click', () => {
                const id = btn.getAttribute('data-template-id');
                const form = document.querySelector(`[data-template-form="${id}"]`);
                if (!form) return;
                openModal('Edit Template', form);
            });
        });

        const modalBackdrop = document.getElementById('modal-backdrop');
        const modalBody = document.getElementById('modal-body');
        const modalTitle = document.getElementById('modal-title');
        const modalCloseBtn = document.querySelector('.modal-close');
        const createTemplateButton = document.querySelector('[data-action="open-create-template-modal"]');
        const toggleTemplateButton = document.querySelector('[data-action="toggle-template-grid"]');
        const createTemplateModalContent = document.getElementById('create-template-modal-content');
        const templateGrid = document.querySelector('[data-template-grid]');
        const toggleRecentButton = document.querySelector('[data-action="toggle-recent-list"]');
        const recentList = document.querySelector('[data-recent-list]');
        const toggleRecentMoreButton = document.querySelector('[data-action="toggle-recent-more"]');
        const recentExtras = document.querySelectorAll('[data-recent-extra]');
        let modalStack = [];

        function openModal(title, contentElement, onMount) {
            if (!modalBackdrop || !modalBody || !modalTitle) return;
            if (!modalBackdrop.classList.contains('open')) {
                modalStack = [];
            } else {
                modalStack.push({ title: modalTitle.textContent, content: modalBody.innerHTML });
            }
            modalTitle.textContent = title;
            modalBody.innerHTML = '';
            const clone = contentElement.cloneNode(true);
            clone.classList.remove('hidden');
            clone.style.display = 'block';
            clone.style.width = '100%';
            modalBody.appendChild(clone);
            if (typeof onMount === 'function') {
                onMount(clone);
            }
            modalBackdrop.classList.add('open');
            modalBackdrop.setAttribute('aria-hidden', 'false');
            document.body.classList.add('modal-open');
            const input = clone.querySelector('input, textarea, select');
            if (input) {
                setTimeout(() => input.focus(), 50);
            }
            attachRewardListeners(modalBody);
            attachStepperListeners(modalBody);
            attachDisableAllHandlers(modalBody);
        }

        function closeModal() {
            if (!modalBackdrop) return;
            if (modalStack.length > 0) {
                const prev = modalStack.pop();
                modalTitle.textContent = prev.title || '';
                modalBody.innerHTML = prev.content || '';
                attachRewardListeners(modalBody);
                return;
            }
            modalBackdrop.classList.remove('open');
            modalBackdrop.setAttribute('aria-hidden', 'true');
            modalBody.innerHTML = '';
            modalStack = [];
            document.body.classList.remove('modal-open');
        }

        function openConfirm(message, onConfirm) {
            if (!modalBackdrop.classList.contains('open')) {
                modalStack = [];
            } else {
                modalStack.push({ title: modalTitle.textContent, content: modalBody.innerHTML });
            }
            modalTitle.textContent = 'Confirm';
            modalBody.innerHTML = '';
            const wrapper = document.createElement('div');
            wrapper.style.display = 'grid';
            wrapper.style.gap = '12px';
            const msg = document.createElement('p');
            msg.textContent = message;
            const actions = document.createElement('div');
            actions.style.display = 'flex';
            actions.style.gap = '8px';
            const yesBtn = document.createElement('button');
            yesBtn.type = 'button';
            yesBtn.className = 'button danger';
            yesBtn.textContent = 'Confirm';
            yesBtn.addEventListener('click', () => {
                closeModal();
                if (typeof onConfirm === 'function') onConfirm();
            });
            const cancelBtn = document.createElement('button');
            cancelBtn.type = 'button';
            cancelBtn.className = 'button secondary';
            cancelBtn.textContent = 'Cancel';
            cancelBtn.addEventListener('click', closeModal);
            actions.appendChild(yesBtn);
            actions.appendChild(cancelBtn);
            wrapper.appendChild(msg);
            wrapper.appendChild(actions);
            modalBody.appendChild(wrapper);
            modalBackdrop.classList.add('open');
            modalBackdrop.setAttribute('aria-hidden', 'false');
            document.body.classList.add('modal-open');
        }

        if (modalCloseBtn) {
            modalCloseBtn.addEventListener('click', closeModal);
        }
        if (modalBackdrop) {
            modalBackdrop.addEventListener('click', (e) => {
                if (e.target === modalBackdrop) {
                    closeModal();
                }
            });
        }
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

        if (createTemplateButton && createTemplateModalContent) {
            createTemplateButton.addEventListener('click', () => {
                openModal('Create Reward', createTemplateModalContent);
            });
        }

        if (toggleTemplateButton) {
            if (!templateGrid) {
                toggleTemplateButton.disabled = true;
                toggleTemplateButton.innerHTML = 'No templates';
            } else {
                toggleTemplateButton.addEventListener('click', () => {
                    const isHidden = templateGrid.classList.toggle('hidden');
                    toggleTemplateButton.setAttribute('aria-expanded', (!isHidden).toString());
                    const label = toggleTemplateButton.querySelector('[data-template-toggle-label]');
                    const icon = toggleTemplateButton.querySelector('[data-template-toggle-icon]');
                    if (label) {
                        label.textContent = isHidden ? 'View Shop' : 'Close Shop';
                    }
                    if (icon) {
                        icon.className = isHidden ? 'fa-solid fa-caret-down' : 'fa-solid fa-caret-up';
                    }
                });
            }
        }

        if (toggleRecentButton) {
            if (!recentList) {
                toggleRecentButton.disabled = true;
                toggleRecentButton.innerHTML = 'No recent rewards';
            } else {
                toggleRecentButton.addEventListener('click', () => {
                    const isHidden = recentList.classList.toggle('hidden');
                    toggleRecentButton.setAttribute('aria-expanded', (!isHidden).toString());
                    const label = toggleRecentButton.querySelector('[data-recent-toggle-label]');
                    const icon = toggleRecentButton.querySelector('[data-recent-toggle-icon]');
                    if (label) {
                        label.textContent = isHidden ? 'View' : 'Close';
                    }
                    if (icon) {
                        icon.className = isHidden ? 'fa-solid fa-caret-down' : 'fa-solid fa-caret-up';
                    }
                });
            }
        }

        if (toggleRecentMoreButton) {
            let expanded = false;
            toggleRecentMoreButton.addEventListener('click', () => {
                expanded = !expanded;
                recentExtras.forEach(item => item.classList.toggle('hidden', !expanded));
                toggleRecentMoreButton.setAttribute('aria-expanded', expanded.toString());
                toggleRecentMoreButton.textContent = expanded ? 'View less' : 'View more';
            });
        }

        // Event delegation safety: ensure cancel buttons always close modals on first click
        document.addEventListener('click', (e) => {
            const closeBtn = e.target.closest('.modal-close');
            if (closeBtn) {
                e.preventDefault();
                e.stopPropagation();
                if (typeof e.stopImmediatePropagation === 'function') {
                    e.stopImmediatePropagation();
                }
                closeModal();
            }
        });

        function attachRewardListeners(scope) {
            const editButtons = (scope || document).querySelectorAll('[data-action="edit-reward"]');
            editButtons.forEach(btn => {
                btn.addEventListener('click', () => {
                    const id = btn.getAttribute('data-reward-id');
                    if (!id) return;
                    const form = (scope || document).querySelector(`[data-reward-form="${id}"]`);
                    if (!form) return;
                    // Hide any other edit forms in this scope
                    (scope || document).querySelectorAll('[data-reward-form]').forEach(f => f.classList.add('hidden'));
                    form.classList.remove('hidden');
                    form.style.display = 'grid';
                    const input = form.querySelector('input, textarea, select');
                    if (input) input.focus();
                });
            });

            const deleteButtons = (scope || document).querySelectorAll('[data-action="delete-reward"]');
            deleteButtons.forEach(btn => {
                btn.addEventListener('click', () => {
                    const id = btn.getAttribute('data-reward-id');
                    if (!id) return;
                    const form = document.querySelector(`[data-reward-delete-form="${id}"]`);
                    if (!form) return;
                    openConfirm('Delete this reward?', () => form.submit());
                });
            });

            const rewardMenus = (scope || document).querySelectorAll('.reward-actions-menu');
            if (rewardMenus.length) {
                const closeRewardMenus = (except) => {
                    rewardMenus.forEach(menu => {
                        if (menu !== except) menu.removeAttribute('open');
                    });
                };
                document.addEventListener('click', (e) => {
                    if (!e.target.closest('.reward-actions-menu')) {
                        closeRewardMenus();
                    }
                });
                rewardMenus.forEach(menu => {
                    const toggle = menu.querySelector('.reward-actions-toggle');
                    if (toggle) {
                        toggle.addEventListener('click', (e) => {
                            e.stopPropagation();
                            closeRewardMenus(menu);
                        });
                    }
                    menu.querySelectorAll('.reward-actions-dropdown button').forEach(btn => {
                        btn.addEventListener('click', () => {
                            menu.removeAttribute('open');
                        });
                    });
                });
            }

            const cancelButtons = (scope || document).querySelectorAll('[data-action="cancel-edit"]');
            cancelButtons.forEach(btn => {
                btn.addEventListener('click', (e) => {
                    e.preventDefault();
                    const form = btn.closest('[data-reward-form]');
                    if (form) {
                        form.classList.add('hidden');
                    }
                });
            });
        }

        function attachStepperListeners(scope) {
            (scope || document).querySelectorAll('.stepper-btn').forEach(btn => {
                btn.addEventListener('click', () => {
                    const step = parseInt(btn.getAttribute('data-step'), 10) || 1;
                    const input = btn.closest('.number-stepper')?.querySelector('input[type="number"]');
                    if (!input) return;
                    const min = parseInt(input.getAttribute('min') || '0', 10);
                    const current = parseInt(input.value || input.getAttribute('value') || '0', 10);
                    const next = Math.max(min, current + step);
                    input.value = next;
                    input.dispatchEvent(new Event('input', { bubbles: true }));
                });
            });
        }

        function attachDisableAllHandlers(scope) {
            (scope || document).querySelectorAll('[data-disable-children-grid]').forEach(grid => {
                const allToggle = grid.querySelector('[data-disable-all]');
                const childBoxes = grid.querySelectorAll('input[name="disabled_child_ids[]"]');
                if (!allToggle || !childBoxes.length) return;
                const sync = () => {
                    if (allToggle.checked) {
                        childBoxes.forEach(box => {
                            box.checked = true;
                            box.disabled = true;
                        });
                    } else {
                        childBoxes.forEach(box => {
                            box.disabled = false;
                        });
                    }
                };
                allToggle.addEventListener('change', sync);
                sync();
            });
        }

        attachRewardListeners();
        attachStepperListeners();
        attachDisableAllHandlers();

        document.querySelectorAll('[data-action="show-disabled-modal"]').forEach(btn => {
            btn.addEventListener('click', () => {
                const cid = btn.getAttribute('data-child-id');
                const list = document.querySelector(`[data-child-disabled-list="${cid}"]`);
                const name = btn.closest('.child-header')?.querySelector('strong')?.textContent || 'Disabled rewards';
                if (list) {
                    openModal(`${name} - Disabled Rewards`, list);
                    attachRewardListeners(modalBody);
                    attachStepperListeners(modalBody);
                }
            });
        });

        document.querySelectorAll('[data-action="show-purchased-modal"]').forEach(btn => {
            btn.addEventListener('click', () => {
                const cid = btn.getAttribute('data-child-id');
                const list = document.querySelector(`[data-child-purchased-list="${cid}"]`);
                const name = btn.closest('.child-header')?.querySelector('strong')?.textContent || 'Purchased rewards';
                if (list) {
                    openModal(`${name} - Purchased This Week`, list);
                }
            });
        });

        document.querySelectorAll('[data-action="show-pending-modal"]').forEach(btn => {
            btn.addEventListener('click', () => {
                const cid = btn.getAttribute('data-child-id');
                const list = document.querySelector(`[data-child-pending-list="${cid}"]`);
                const name = btn.closest('.child-header')?.querySelector('strong')?.textContent || 'Pending rewards';
                if (list) {
                    openModal(`${name} - Awaiting Fulfillment`, list);
                }
            });
        });

        // Open modal when arriving via anchor link for disabled/purchased/pending
        const hash = window.location.hash || '';
        const openFromHash = (selector, title) => {
            const list = document.querySelector(selector);
            const headerName = list?.closest('.child-reward-card')?.querySelector('.child-header strong')?.textContent || title;
            if (list) {
                openModal(`${headerName} - ${title}`, list);
            }
        };
        const params = new URLSearchParams(window.location.search);
        const highlightReward = params.get('highlight_reward');
        if (highlightReward) {
            const rewardCards = Array.from(document.querySelectorAll(`[data-reward-card-id="${highlightReward}"]`));
            const card = rewardCards.find(item => item.closest('[data-child-pending-list]')) || rewardCards[0];
            if (card) {
                const list = card.closest('[data-child-disabled-list], [data-child-purchased-list], [data-child-pending-list]');
                let title = 'Rewards';
                if (list?.hasAttribute('data-child-disabled-list')) {
                    title = 'Disabled Rewards';
                } else if (list?.hasAttribute('data-child-purchased-list')) {
                    title = 'Purchased This Week';
                } else if (list?.hasAttribute('data-child-pending-list')) {
                    title = 'Awaiting Fulfillment';
                }
                const headerName = list?.closest('.child-reward-card')?.querySelector('.child-header strong')?.textContent || title;
                if (list) {
                    openModal(`${headerName} - ${title}`, list);
                    attachRewardListeners(modalBody);
                    attachStepperListeners(modalBody);
                }
                card.classList.add('highlight');
                card.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        }
        if (hash.startsWith('#pending-child-')) {
            const cid = hash.replace('#pending-child-', '');
            openFromHash(`[data-child-pending-list="${cid}"]`, 'Awaiting Fulfillment');
        } else if (hash.startsWith('#disabled-child-')) {
            const cid = hash.replace('#disabled-child-', '');
            openFromHash(`[data-child-disabled-list="${cid}"]`, 'Disabled Rewards');
        } else if (hash.startsWith('#purchased-child-')) {
            const cid = hash.replace('#purchased-child-', '');
            openFromHash(`[data-child-purchased-list="${cid}"]`, 'Purchased This Week');
        }
    })();
</script>
</html>





