<?php
session_start();
require_once __DIR__ . '/includes/functions.php';

if (!isset($_SESSION['user_id']) || !canCreateContent($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$role_type = getEffectiveRole($_SESSION['user_id']);
$main_parent_id = getFamilyRootId($_SESSION['user_id']);
$messages = [];

// Load children for this family
$childStmt = $db->prepare("SELECT child_user_id, child_name, avatar FROM child_profiles WHERE parent_user_id = :parent_id ORDER BY child_name");
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
        $messages[] = ($reward_id && fulfillReward($reward_id, $main_parent_id, $_SESSION['user_id']))
            ? "Reward fulfillment recorded."
            : "Unable to mark reward as fulfilled.";
    } elseif (isset($_POST['create_template'])) {
        $title = trim((string)filter_input(INPUT_POST, 'template_title', FILTER_SANITIZE_STRING));
        $description = trim((string)filter_input(INPUT_POST, 'template_description', FILTER_SANITIZE_STRING));
        $point_cost = filter_input(INPUT_POST, 'template_point_cost', FILTER_VALIDATE_INT);
        if ($title !== '' && $point_cost !== false && $point_cost !== null && $point_cost > 0) {
            $messages[] = createRewardTemplate($main_parent_id, $title, $description, $point_cost, $_SESSION['user_id'])
                ? "Template created."
                : "Unable to create template.";
        } else {
            $messages[] = "Enter a title and point cost for the template.";
        }
    } elseif (isset($_POST['update_template'])) {
        $template_id = filter_input(INPUT_POST, 'template_id', FILTER_VALIDATE_INT);
        $title = trim((string)filter_input(INPUT_POST, 'template_title', FILTER_SANITIZE_STRING));
        $description = trim((string)filter_input(INPUT_POST, 'template_description', FILTER_SANITIZE_STRING));
        $point_cost = filter_input(INPUT_POST, 'template_point_cost', FILTER_VALIDATE_INT);
        if ($template_id && $title !== '' && $point_cost !== false && $point_cost !== null && $point_cost > 0) {
            $messages[] = updateRewardTemplate($main_parent_id, $template_id, $title, $description, $point_cost)
                ? "Template updated."
                : "Template could not be updated.";
        } else {
            $messages[] = "Provide a title and point cost to update the template.";
        }
    } elseif (isset($_POST['delete_template'])) {
        $template_id = filter_input(INPUT_POST, 'template_id', FILTER_VALIDATE_INT);
        if ($template_id) {
            $messages[] = deleteRewardTemplate($main_parent_id, $template_id)
                ? "Template deleted."
                : "Template could not be deleted.";
        }
    } elseif (isset($_POST['assign_template'])) {
        $template_id = filter_input(INPUT_POST, 'template_id', FILTER_VALIDATE_INT);
        $selected_children = array_map('intval', $_POST['child_user_ids'] ?? []);
        $assign_all = isset($_POST['assign_all_children']);
        if ($assign_all) {
            $selected_children = array_column($children, 'child_user_id');
        }
        if ($template_id && !empty($selected_children)) {
            $count = assignTemplateToChildren($main_parent_id, $template_id, $selected_children, $_SESSION['user_id']);
            $messages[] = $count > 0
                ? "Assigned to {$count} child" . ($count === 1 ? '' : 'ren') . "."
                : "No rewards were created (they may already exist for those children).";
        } else {
            $messages[] = "Select a template and at least one child.";
        }
    }
}

$templates = getRewardTemplates($main_parent_id);

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
    ORDER BY r.created_on DESC
    LIMIT 50
");
$activeRewardStmt->execute([':parent_id' => $main_parent_id]);
$recentRewards = $activeRewardStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
$dashboardData = getDashboardData($_SESSION['user_id']);
$activeRewards = $dashboardData['active_rewards'] ?? [];
$redeemedRewards = $dashboardData['redeemed_rewards'] ?? [];
$redeemedByChild = [];
foreach ($redeemedRewards as $redeemedReward) {
    $cid = (int)($redeemedReward['child_user_id'] ?? 0);
    if (!isset($redeemedByChild[$cid])) {
        $redeemedByChild[$cid] = 0;
    }
    $redeemedByChild[$cid]++;
}
$redeemedRewardsByChild = [];
foreach ($redeemedRewards as $redeemedReward) {
    $cid = (int)($redeemedReward['child_user_id'] ?? 0);
    if (!isset($redeemedRewardsByChild[$cid])) {
        $redeemedRewardsByChild[$cid] = [];
    }
    $redeemedRewardsByChild[$cid][] = $redeemedReward;
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
        'rewards' => []
    ];
}
// Group active rewards under each child (and "All Children" bucket if needed)
foreach ($activeRewards as $reward) {
    $cid = (int)($reward['child_user_id'] ?? 0);
    $key = $cid ?: 0;
    $avatar = !empty($reward['child_avatar']) ? $reward['child_avatar'] : 'images/default-avatar.png';
    $first = '';
    if (!empty($reward['child_first_name'])) {
        $first = $reward['child_first_name'];
    } elseif (!empty($reward['child_name'])) {
        $parts = explode(' ', trim((string)$reward['child_name']));
        $first = $parts[0] ?? '';
    }
    $displayName = $first !== '' ? $first : (!empty($reward['child_name']) ? $reward['child_name'] : 'All Children');
    if ($cid === 0) {
        $displayName = 'All Children';
    }
    if (!isset($childRewards[$key])) {
        $childRewards[$key] = [
            'child_user_id' => $cid,
            'name' => $displayName,
            'avatar' => $avatar,
            'rewards' => []
        ];
    }
    $childRewards[$key]['rewards'][] = $reward;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reward Library</title>
    <link rel="stylesheet" href="css/main.css?v=3.11.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css" integrity="Evv84Mr4kqVGRNSgIGL/F/aIDqQb7xQ2vcrdIwxfjThSH8CSR7PBEakCr51Ck+w+/U6swU2Im1vVX0SVk9ABhg==" crossorigin="anonymous" referrerpolicy="no-referrer">
    <style>
        body { font-family: Arial, sans-serif; background: #f5f7fb; }
        .page { max-width: 960px; margin: 20px auto; padding: 20px; background: #fff; border-radius: 10px; box-shadow: 0 6px 20px rgba(0,0,0,0.08); }
        h1, h2 { margin-top: 0; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 16px; }
        .card { background: #fafbff; border: 1px solid #e4e7ef; border-radius: 8px; padding: 16px; box-shadow: 0 2px 6px rgba(0,0,0,0.04); }
        .form-group { margin-bottom: 12px; }
        .form-group label { display: block; margin-bottom: 6px; }
        .form-group input, .form-group textarea, .form-group select { width: 100%; padding: 8px; }
        .button { padding: 10px 18px; background: #4caf50; color: #fff; border: none; border-radius: 6px; cursor: pointer; display: inline-block; text-decoration: none; font-weight: 700; }
        .button.secondary { background: #1565c0; }
        .button.danger { background: #c62828; }
        .template-grid { display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-start; }
        .template-card { flex: 1 1 285px; border: 1px solid #e0e4ee; border-radius: 10px; padding: 14px; background: #fff; box-shadow: 0 2px 6px rgba(0,0,0,0.05); display: grid; gap: 10px; position: relative; max-width: 288px; }
        .template-actions { display: flex; gap: 8px; justify-content: flex-start; flex-wrap: wrap; align-items: center; }
        .template-icon-button { border: none; background: transparent; color: #9f9f9f; padding: 6px 8px; border-radius: 8px; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 6px; }
        .template-icon-button:hover { background: rgba(0,0,0,0.04); color: #7a7a7a; }
        .template-icon-button.danger { color: #9f9f9f; }
        .template-icon-button.danger:hover { background: rgba(0,0,0,0.04); color: #7a7a7a; }
        .badge { display: inline-block; background: #e3f2fd; color: #0d47a1; padding: 4px 8px; border-radius: 12px; font-size: 0.85em; }
        .message { background: #e8f5e9; color: #2e7d32; border: 1px solid #c8e6c9; padding: 10px 12px; border-radius: 6px; margin-bottom: 10px; }
        .recent-list { display: grid; gap: 8px; }
        .recent-item { border: 1px solid #eceff4; border-radius: 8px; padding: 10px; background: #fff; display: flex; justify-content: space-between; gap: 10px; }
        .child-select-group { display: grid; gap: 10px; }
        .child-select-grid { display: flex; flex-wrap: wrap; gap: 12px; }
        .child-select-card { border: none; border-radius: 50%; padding: 0; background: transparent; display: grid; justify-items: center; gap: 8px; cursor: pointer; position: relative; }
        .child-select-card input[type="checkbox"] { position: absolute; opacity: 0; width: 0; height: 0; pointer-events: none; }
        .child-select-card img { width: 52px; height: 52px; border-radius: 50%; object-fit: cover; box-shadow: 0 2px 6px rgba(0,0,0,0.15); transition: box-shadow 150ms ease, transform 150ms ease; }
        .child-select-card strong { font-size: 13px; width: min-content; text-align: center; transition: color 150ms ease, text-shadow 150ms ease; }
        .child-select-card:has(input[type="checkbox"]:checked) img { box-shadow: 0 0 0 4px rgba(100,181,246,0.8), 0 0 14px rgba(100,181,246,0.8); transform: translateY(-2px); }
        .child-select-card:has(input[type="checkbox"]:checked) strong { color: #0d47a1; text-shadow: 0 1px 8px rgba(100,181,246,0.8); }
        .assign-all { display: inline-flex; align-items: center; gap: 8px; margin-top: 8px; cursor: pointer; font-weight: 600; color: #0d47a1; }
        .assign-all input { width: 18px; height: 18px; margin: 0; }
        .assign-all.active { background: rgba(100,181,246,0.1); padding: 6px 10px; border-radius: 999px; box-shadow: 0 0 0 3px rgba(100,181,246,0.2); }
        .hidden { display: none !important; }
        .assigned-child-pill { display: inline-flex; align-items: center; gap: 8px; padding: 6px 10px; border-radius: 999px; background: #eef4ff; color: #0d47a1; font-weight: 700; box-shadow: 0 1px 4px rgba(0,0,0,0.08); margin: 8px 0; }
        .assigned-child-pill img { width: 42px; height: 42px; border-radius: 50%; object-fit: cover; box-shadow: 0 1px 6px rgba(0,0,0,0.12); }
        .child-reward-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px; margin-top: 12px; justify-content: flex-start; justify-items: start; }
        .child-reward-card { border: 1px solid #e0e4ee; border-radius: 12px; padding: 14px; background: #fff; box-shadow: 0 2px 8px rgba(0,0,0,0.06); display: grid; gap: 12px; max-width: fit-content; }
        .child-header { display: flex; align-items: center; gap: 12px; }
        .child-header img { width: 64px; height: 64px; border-radius: 50%; object-fit: cover; box-shadow: 0 2px 8px rgba(0,0,0,0.15); }
        .child-meta { display: flex; gap: 10px; flex-wrap: wrap; font-weight: 700; color: #2c3e50; }
        .child-meta .badge { background: #eef4ff; color: #0d47a1; cursor: pointer; border: none; }
        .child-meta .badge-link { border-radius: 12px; padding: 4px 8px; }
        .reward-list { width: 100%; }
        .reward-card { gap: 8px; width: 100%; max-width: none; }
        .reward-card-header { display: flex; justify-content: space-between; align-items: center; gap: 10px; }
        .reward-actions { display: inline-flex; gap: 8px; }
        .icon-button { border: none; background: transparent; cursor: pointer; color: #1e3a8a; padding: 6px; border-radius: 6px; }
        .icon-button:hover { background: #eef2ff; }
        .icon-button.danger { color: #b91c1c; }
        .icon-button.danger:hover { background: #fff1f2; }
        .reward-card-body { color: #444; font-size: 0.95em; }
        .reward-edit-actions { display: flex; gap: 8px; align-items: center; }
        .modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.45); display: none; align-items: center; justify-content: center; z-index: 999; padding: 16px; }
        .modal-backdrop.open { display: flex; }
        .modal { background: #fff; border-radius: 12px; padding: 16px; max-width: 520px; width: 100%; box-shadow: 0 12px 30px rgba(0,0,0,0.18); max-height: 85vh; display: flex; flex-direction: column; }
        .modal header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
        .modal-close { border: none; background: transparent; font-size: 20px; cursor: pointer; }
        #modal-body { overflow-y: auto; max-height: 70vh; }
        body.modal-open { overflow: hidden; }
    </style>
</head>
<body>
    <div class="page">
        <div style="display:flex; justify-content: space-between; align-items:center; gap:12px;">
            <h1>Reward Library</h1>
            <a href="dashboard_parent.php" class="button secondary">Back to Dashboard</a>
        </div>

        <?php foreach ($messages as $msg): ?>
            <div class="message"><?php echo htmlspecialchars($msg); ?></div>
        <?php endforeach; ?>

        <div class="grid">
            <div class="card">
                <h2>Create Template</h2>
                <form method="POST" action="rewards.php">
                    <div class="form-group">
                        <label for="template_title">Title</label>
                        <input type="text" id="template_title" name="template_title" required>
                    </div>
                    <div class="form-group">
                        <label for="template_description">Description</label>
                        <textarea id="template_description" name="template_description"></textarea>
                    </div>
                    <div class="form-group">
                        <label for="template_point_cost">Point Cost</label>
                        <input type="number" id="template_point_cost" name="template_point_cost" min="1" required>
                    </div>
                    <button type="submit" name="create_template" class="button">Save Template</button>
                </form>
            </div>

            <div class="card">
                <h2>Assign Template</h2>
                <form method="POST" action="rewards.php">
                    <div class="form-group">
                        <label for="template_id">Template</label>
                        <select id="template_id" name="template_id" required>
                            <option value="">Select a template</option>
                            <?php foreach ($templates as $template): ?>
                                <option value="<?php echo (int)$template['id']; ?>">
                                    <?php echo htmlspecialchars($template['title']); ?> (<?php echo (int)$template['point_cost']; ?> pts)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group child-select-group">
                        <label>Choose Children</label>
                        <div class="child-select-grid">
                            <?php if (!empty($children)): ?>
                                <?php foreach ($children as $child): 
                                    $avatar = !empty($child['avatar']) ? $child['avatar'] : 'images/default-avatar.png';
                                ?>
                                    <label class="child-select-card">
                                        <input type="checkbox" name="child_user_ids[]" value="<?php echo (int)$child['child_user_id']; ?>">
                                        <img src="<?php echo htmlspecialchars($avatar); ?>" alt="<?php echo htmlspecialchars($child['child_name']); ?>">
                                        <strong><?php echo htmlspecialchars($child['child_name']); ?></strong>
                                    </label>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p>No children found.</p>
                            <?php endif; ?>
                        </div>
                        <label class="assign-all">
                            <input type="checkbox" name="assign_all_children" value="1">
                            Assign to all children
                        </label>
                    </div>
                    <button type="submit" name="assign_template" class="button">Create Rewards</button>
                </form>
            </div>
        </div>

        <div class="card" style="margin-top:20px;">
            <h2>Templates</h2>
            <?php if (!empty($templates)): ?>
                <div class="template-grid">
                    <?php foreach ($templates as $template): ?>
                        <div class="template-card" data-template-card="<?php echo (int)$template['id']; ?>">
                            <div>
                                <div style="display:flex; justify-content: space-between; align-items:center; gap:10px;">
                                    <div>
                                        <strong><?php echo htmlspecialchars($template['title']); ?></strong>
                                        <span class="badge"><?php echo (int)$template['point_cost']; ?> pts</span>
                                    </div>
                                </div>
                                <?php if (!empty($template['description'])): ?>
                                    <p><?php echo nl2br(htmlspecialchars($template['description'])); ?></p>
                                <?php endif; ?>
                            </div>
                            <div class="template-actions">
                                <button type="button" class="template-icon-button" data-action="edit-template" data-template-id="<?php echo (int)$template['id']; ?>" aria-label="Edit template">
                                    <i class="fa fa-pen"></i>
                                </button>
                                <form method="POST" action="rewards.php" onsubmit="return confirm('Delete this template?');" style="margin:0;">
                                    <input type="hidden" name="template_id" value="<?php echo (int)$template['id']; ?>">
                                    <button type="submit" name="delete_template" class="template-icon-button danger" aria-label="Delete template">
                                        <i class="fa fa-trash"></i>
                                    </button>
                                </form>
                            </div>
                            <form method="POST" action="rewards.php" class="reward-edit-form hidden" data-template-form="<?php echo (int)$template['id']; ?>" style="display:grid; gap:10px; margin-top:8px;">
                                <input type="hidden" name="template_id" value="<?php echo (int)$template['id']; ?>">
                                <div class="form-group">
                                    <label for="template_title_<?php echo (int)$template['id']; ?>">Title</label>
                                    <input type="text" id="template_title_<?php echo (int)$template['id']; ?>" name="template_title" value="<?php echo htmlspecialchars($template['title']); ?>" required>
                                </div>
                                <div class="form-group">
                                    <label for="template_description_<?php echo (int)$template['id']; ?>">Description</label>
                                    <textarea id="template_description_<?php echo (int)$template['id']; ?>" name="template_description"><?php echo htmlspecialchars($template['description']); ?></textarea>
                                </div>
                                <div class="form-group">
                                    <label for="template_point_cost_<?php echo (int)$template['id']; ?>">Point Cost</label>
                                    <input type="number" id="template_point_cost_<?php echo (int)$template['id']; ?>" name="template_point_cost" min="1" value="<?php echo (int)$template['point_cost']; ?>" required>
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

        <div class="card" style="margin-top:20px;">
            <h2>Recently Created Rewards</h2>
            <?php if (!empty($recentRewards)): ?>
                <div class="recent-list">
                    <?php foreach ($recentRewards as $reward): ?>
                        <div class="recent-item">
                            <div>
                                <strong><?php echo htmlspecialchars($reward['title']); ?></strong>
                                <span class="badge"><?php echo (int)$reward['point_cost']; ?> pts</span>
                                <div style="font-size:0.9em; color:#555;">For: <?php echo htmlspecialchars($reward['child_name'] ?? 'All children'); ?></div>
                            </div>
                            <div style="font-size:0.9em; color:#666; text-align:right;"><?php echo htmlspecialchars(date('m/d/Y', strtotime($reward['created_on']))); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p>No rewards available yet.</p>
            <?php endif; ?>
        </div>

        <div class="card" style="margin-top:20px;">
            <h2>Active Rewards</h2>
            <?php if (!empty($childRewards)): ?>
                <div class="child-reward-grid">
                    <?php foreach ($childRewards as $childCard): 
                        $cid = (int)$childCard['child_user_id'];
                        $activeCount = count($childCard['rewards']);
                        $redeemedCount = $redeemedByChild[$cid] ?? 0;
                    ?>
                        <div class="child-reward-card">
                            <div class="child-header">
                                <img src="<?php echo htmlspecialchars($childCard['avatar']); ?>" alt="<?php echo htmlspecialchars($childCard['name']); ?>">
                                <div>
                                    <strong><?php echo htmlspecialchars($childCard['name']); ?></strong>
                                    <div class="child-meta">
                                        <button type="button" class="badge badge-link" data-action="show-active-modal" data-child-id="<?php echo $cid; ?>"><?php echo $activeCount; ?> active</button>
                                        <button type="button" class="badge badge-link" data-action="show-redeemed-modal" data-child-id="<?php echo $cid; ?>"><?php echo $redeemedCount; ?> redeemed</button>
                                    </div>
                                </div>
                            </div>
                            <div class="hidden" data-child-active-list="<?php echo $cid; ?>" style="width:100%;">
                                <div class="reward-list" style="display:grid; gap:12px; width:100%;">
                                    <?php if (!empty($childCard['rewards'])): ?>
                                        <?php foreach ($childCard['rewards'] as $reward): ?>
                                            <div class="template-card reward-card" data-reward-card="<?php echo (int)$reward['id']; ?>" style="width:100%;">
                                                <div class="reward-card-header">
                                                    <div>
                                                        <strong><?php echo htmlspecialchars($reward['title']); ?></strong>
                                                        <span class="badge"><?php echo (int)$reward['point_cost']; ?> pts</span>
                                                    </div>
                                                    <div class="reward-actions">
                                                        <button type="button" class="icon-button" data-action="edit-reward" data-reward-id="<?php echo (int)$reward['id']; ?>" aria-label="Edit reward"><i class="fa fa-pen"></i></button>
                                                        <button type="button" class="icon-button danger" data-action="delete-reward" data-reward-id="<?php echo (int)$reward['id']; ?>" aria-label="Delete reward"><i class="fa fa-trash"></i></button>
                                                    </div>
                                                </div>
                                                <?php if (!empty($reward['description'])): ?>
                                                    <div class="reward-card-body">
                                                        <p><?php echo nl2br(htmlspecialchars($reward['description'])); ?></p>
                                                    </div>
                                                <?php endif; ?>
                                                <form method="POST" action="rewards.php" class="reward-edit-form hidden" data-reward-form="<?php echo (int)$reward['id']; ?>" style="display:grid; gap:10px;">
                                                    <input type="hidden" name="reward_id" value="<?php echo (int)$reward['id']; ?>">
                                                    <div class="form-group">
                                                        <label for="reward_title_<?php echo (int)$reward['id']; ?>">Title</label>
                                                        <input type="text" id="reward_title_<?php echo (int)$reward['id']; ?>" name="reward_title" value="<?php echo htmlspecialchars($reward['title']); ?>" required>
                                                    </div>
                                                    <div class="form-group">
                                                        <label for="reward_description_<?php echo (int)$reward['id']; ?>">Description</label>
                                                        <textarea id="reward_description_<?php echo (int)$reward['id']; ?>" name="reward_description"><?php echo htmlspecialchars($reward['description'] ?? ''); ?></textarea>
                                                    </div>
                                                    <div class="form-group">
                                                        <label for="reward_cost_<?php echo (int)$reward['id']; ?>">Point Cost</label>
                                                        <input type="number" id="reward_cost_<?php echo (int)$reward['id']; ?>" name="point_cost" min="1" value="<?php echo (int)$reward['point_cost']; ?>" required>
                                                    </div>
                                                    <div class="reward-edit-actions">
                                                        <button type="submit" name="update_reward" class="button">Save Changes</button>
                                                        <button type="button" class="button secondary" data-action="cancel-edit" data-reward-id="<?php echo (int)$reward['id']; ?>">Cancel</button>
                                                    </div>
                                                </form>
                                                <form method="POST" action="rewards.php" class="hidden" data-reward-delete-form="<?php echo (int)$reward['id']; ?>">
                                                    <input type="hidden" name="reward_id" value="<?php echo (int)$reward['id']; ?>">
                                                    <input type="hidden" name="delete_reward" value="1">
                                                </form>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <p>No active rewards for this child.</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="hidden" data-child-redeemed-list="<?php echo $cid; ?>" style="width:100%;">
                                <div class="reward-list" style="display:grid; gap:12px; width:100%;">
                                    <?php if (!empty($redeemedRewardsByChild[$cid])): ?>
                                        <?php foreach ($redeemedRewardsByChild[$cid] as $reward): ?>
                                            <div class="template-card reward-card" style="width:100%;">
                                                <div class="reward-card-header">
                                                    <div>
                                                        <strong><?php echo htmlspecialchars($reward['title']); ?></strong>
                                                        <span class="badge"><?php echo (int)$reward['point_cost']; ?> pts</span>
                                                    </div>
                                                </div>
                                                <div class="reward-card-body">
                                                    <p>Redeemed on: <?php echo !empty($reward['redeemed_on']) ? htmlspecialchars(date('m/d/Y h:i A', strtotime($reward['redeemed_on']))) : 'Date unavailable'; ?></p>
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
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <p>No redeemed rewards for this child.</p>
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

        <div class="card" style="margin-top:20px;">
            <h2>Redeemed Rewards</h2>
            <?php if (!empty($redeemedRewards)): ?>
                <?php foreach ($redeemedRewards as $reward): ?>
                    <div class="reward-item" id="redeemed-reward-<?php echo (int)$reward['id']; ?>">
                        <p>Reward: <?php echo htmlspecialchars($reward['title']); ?> (<?php echo htmlspecialchars($reward['point_cost']); ?> points)</p>
                        <p>Description: <?php echo htmlspecialchars($reward['description']); ?></p>
                        <p>Redeemed by: <?php echo htmlspecialchars($reward['child_username'] ?? 'Unknown'); ?></p>
                        <p>Redeemed on: <?php echo !empty($reward['redeemed_on']) ? htmlspecialchars(date('m/d/Y h:i A', strtotime($reward['redeemed_on']))) : 'Date unavailable'; ?></p>
                        <?php if (!empty($reward['fulfilled_on'])): ?>
                            <p>Fulfilled on: <?php echo htmlspecialchars(date('m/d/Y h:i A', strtotime($reward['fulfilled_on']))); ?><?php if (!empty($reward['fulfilled_by_name'])): ?> by <?php echo htmlspecialchars($reward['fulfilled_by_name']); ?><?php endif; ?></p>
                        <?php else: ?>
                            <p class="awaiting-label">Awaiting fulfillment by parent.</p>
                            <form method="POST" action="rewards.php" class="inline-form">
                                <input type="hidden" name="reward_id" value="<?php echo (int)$reward['id']; ?>">
                                <button type="submit" name="fulfill_reward" class="button secondary">Mark Fulfilled</button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p>No rewards redeemed yet.</p>
            <?php endif; ?>
        </div>
    </div>
</body>
<div class="modal-backdrop" id="modal-backdrop" aria-hidden="true">
    <div class="modal" role="dialog" aria-modal="true">
        <header>
            <h3 id="modal-title">Edit</h3>
            <button class="modal-close" type="button" aria-label="Close">&times;</button>
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
        let modalStack = [];

        function openModal(title, contentElement) {
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
            modalBackdrop.classList.add('open');
            modalBackdrop.setAttribute('aria-hidden', 'false');
            document.body.classList.add('modal-open');
            const input = clone.querySelector('input, textarea, select');
            if (input) {
                setTimeout(() => input.focus(), 50);
            }
            attachRewardListeners(modalBody);
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

        attachRewardListeners();

        document.querySelectorAll('[data-action="show-active-modal"]').forEach(btn => {
            btn.addEventListener('click', () => {
                const cid = btn.getAttribute('data-child-id');
                const list = document.querySelector(`[data-child-active-list="${cid}"]`);
                const name = btn.closest('.child-header')?.querySelector('strong')?.textContent || 'Active rewards';
                if (list) {
                    openModal(`${name} - Active Rewards`, list);
                    attachRewardListeners(modalBody);
                }
            });
        });

        document.querySelectorAll('[data-action="show-redeemed-modal"]').forEach(btn => {
            btn.addEventListener('click', () => {
                const cid = btn.getAttribute('data-child-id');
                const list = document.querySelector(`[data-child-redeemed-list="${cid}"]`);
                const name = btn.closest('.child-header')?.querySelector('strong')?.textContent || 'Redeemed rewards';
                if (list) {
                    openModal(`${name} - Redeemed Rewards`, list);
                }
            });
        });
    })();
</script>
</html>




