<?php
// goal.php - Goal management
// Purpose: Allow parents to create/edit/delete/reactivate goals and children to view/request completion
// Inputs: POST for create/update/delete/reactivate, goal ID for request completion
// Outputs: Goal management interface
// Version: 3.12.2

session_start();
require_once __DIR__ . '/includes/functions.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// Ensure a friendly display name is available in session
if (!isset($_SESSION['name'])) {
    $_SESSION['name'] = getDisplayName($_SESSION['user_id']);
}

$family_root_id = getFamilyRootId($_SESSION['user_id']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create_goal']) && isset($_SESSION['user_id']) && canCreateContent($_SESSION['user_id'])) {
        $child_user_id = filter_input(INPUT_POST, 'child_user_id', FILTER_VALIDATE_INT);
        $title = filter_input(INPUT_POST, 'title', FILTER_SANITIZE_STRING);
        $target_points = filter_input(INPUT_POST, 'target_points', FILTER_VALIDATE_INT);
        $start_date = filter_input(INPUT_POST, 'start_date', FILTER_SANITIZE_STRING);
        $end_date = filter_input(INPUT_POST, 'end_date', FILTER_SANITIZE_STRING);
        $reward_id = filter_input(INPUT_POST, 'reward_id', FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE);

        if (createGoal($family_root_id, $child_user_id, $title, $target_points, $start_date, $end_date, $reward_id, $_SESSION['user_id'])) {
            $message = "Goal created successfully!";
        } else {
            $message = "Failed to create goal.";
        }
    } elseif (isset($_POST['update_goal']) && isset($_SESSION['user_id']) && canCreateContent($_SESSION['user_id'])) {
        $goal_id = filter_input(INPUT_POST, 'goal_id', FILTER_VALIDATE_INT);
        $title = filter_input(INPUT_POST, 'title', FILTER_SANITIZE_STRING);
        $target_points = filter_input(INPUT_POST, 'target_points', FILTER_VALIDATE_INT);
        $start_date = filter_input(INPUT_POST, 'start_date', FILTER_SANITIZE_STRING);
        $end_date = filter_input(INPUT_POST, 'end_date', FILTER_SANITIZE_STRING);
        $reward_id = filter_input(INPUT_POST, 'reward_id', FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE);

        if (updateGoal($goal_id, $family_root_id, $title, $target_points, $start_date, $end_date, $reward_id)) {
            $message = "Goal updated successfully!";
        } else {
            $message = "Failed to update goal.";
        }
    } elseif (isset($_POST['delete_goal']) && isset($_SESSION['user_id']) && canCreateContent($_SESSION['user_id'])) {
        $goal_id = filter_input(INPUT_POST, 'goal_id', FILTER_VALIDATE_INT);
        if (deleteGoal($goal_id, $family_root_id)) {
            $message = "Goal deleted successfully!";
        } else {
            $message = "Failed to delete goal.";
        }
    } elseif (isset($_POST['reactivate_goal']) && isset($_SESSION['user_id']) && canCreateContent($_SESSION['user_id'])) {
        $goal_id = filter_input(INPUT_POST, 'goal_id', FILTER_VALIDATE_INT);
        if (reactivateGoal($goal_id, $family_root_id)) {
            $message = "Goal reactivated successfully!";
        } else {
            $message = "Failed to reactivate goal.";
        }
    } elseif (isset($_POST['request_completion']) && isset($_SESSION['user_id']) && getUserRole($_SESSION['user_id']) === 'child') {
        $goal_id = filter_input(INPUT_POST, 'goal_id', FILTER_VALIDATE_INT);
        if (requestGoalCompletion($goal_id, $_SESSION['user_id'])) {
            $message = "Completion requested! Awaiting parent approval.";
        } else {
            $message = "Failed to request completion.";
        }
    } elseif (isset($_POST['approve_goal']) || isset($_POST['reject_goal'])) {
        $goal_id = filter_input(INPUT_POST, 'goal_id', FILTER_VALIDATE_INT);
        $action = isset($_POST['approve_goal']) ? 'approve' : 'reject';
        $rejection_comment = $action === 'reject' ? filter_input(INPUT_POST, 'rejection_comment', FILTER_UNSAFE_RAW, FILTER_FLAG_STRIP_LOW) : null;

        if (!canCreateContent($_SESSION['user_id'])) {
            $message = "Access denied.";
        } else {
            if ($action === 'approve') {
                $pointsStmt = $db->prepare("SELECT target_points FROM goals WHERE id = :goal_id AND parent_user_id = :parent_id");
                $pointsStmt->execute([':goal_id' => $goal_id, ':parent_id' => $family_root_id]);
                $points_value = $pointsStmt->fetchColumn();

                if (approveGoal($goal_id, $family_root_id)) {
                    if ($points_value !== false) {
                        $message = "Goal approved! Child earned " . (int)$points_value . " points.";
                    } else {
                        $message = "Goal approved!";
                    }
                } else {
                    $message = "Failed to approve goal.";
                }
            } else {
                $rejectError = null;
                if (rejectGoal($goal_id, $family_root_id, $rejection_comment, $rejectError)) {
                    $message = "Goal rejected.";
                } else {
                    $message = "Failed to reject goal." . ($rejectError ? " Reason: " . htmlspecialchars($rejectError) : "");
                }
            }
        }
    }
}

// Fetch goals for the user
$goals = [];
if (isset($_SESSION['user_id']) && canCreateContent($_SESSION['user_id'])) {
    $stmt = $db->prepare("SELECT 
                             g.id, 
                             g.title, 
                             g.target_points, 
                             g.start_date, 
                             g.end_date, 
                             g.status, 
                             g.reward_id, 
                             r.title AS reward_title, 
                             COALESCE(
                                 NULLIF(TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))), ''),
                                 NULLIF(u.name, ''),
                                 u.username
                             ) AS child_display_name, 
                             g.rejected_at, 
                             g.rejection_comment, 
                             g.created_at,
                             COALESCE(
                                 NULLIF(TRIM(CONCAT(COALESCE(creator.first_name, ''), ' ', COALESCE(creator.last_name, ''))), ''),
                                 NULLIF(creator.name, ''),
                                 creator.username,
                                 'Unknown'
                             ) AS creator_display_name
                         FROM goals g 
                         JOIN users u ON g.child_user_id = u.id 
                         LEFT JOIN rewards r ON g.reward_id = r.id 
                         LEFT JOIN users creator ON g.created_by = creator.id
                         WHERE g.parent_user_id = :parent_id 
                         ORDER BY g.start_date ASC");
    $stmt->execute([':parent_id' => $family_root_id]);
    $goals = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else { // Child
    $stmt = $db->prepare("SELECT 
                             g.id, 
                             g.title, 
                             g.target_points, 
                             g.start_date, 
                             g.end_date, 
                             g.status, 
                             g.reward_id, 
                             r.title AS reward_title, 
                             g.rejected_at, 
                             g.rejection_comment, 
                             g.created_at,
                             COALESCE(
                                 NULLIF(TRIM(CONCAT(COALESCE(creator.first_name, ''), ' ', COALESCE(creator.last_name, ''))), ''),
                                 NULLIF(creator.name, ''),
                                 creator.username,
                                 'Unknown'
                             ) AS creator_display_name
                         FROM goals g 
                         LEFT JOIN rewards r ON g.reward_id = r.id 
                         LEFT JOIN users creator ON g.created_by = creator.id
                         WHERE g.child_user_id = :child_id 
                         ORDER BY g.start_date ASC");
    $stmt->execute([':child_id' => $_SESSION['user_id']]);
    $goals = $stmt->fetchAll(PDO::FETCH_ASSOC);
    error_log("Child goals fetched: " . print_r($goals, true)); // Debugging log
}

// Format dates for all goals
foreach ($goals as &$goal) {
    $goal['start_date_formatted'] = date('m/d/Y h:i A', strtotime($goal['start_date']));
    $goal['end_date_formatted'] = date('m/d/Y h:i A', strtotime($goal['end_date']));
    if (isset($goal['rejected_at'])) {
        $goal['rejected_at_formatted'] = date('m/d/Y h:i A', strtotime($goal['rejected_at']));
    }
    $goal['created_at_formatted'] = date('m/d/Y h:i A', strtotime($goal['created_at']));
}
unset($goal);

// Group goals by status
$active_goals = array_filter($goals, function($g) { return $g['status'] === 'active' || $g['status'] === 'pending_approval'; });
$completed_goals = array_filter($goals, function($g) { return $g['status'] === 'completed'; });
$rejected_goals = array_filter($goals, function($g) { return $g['status'] === 'rejected'; });

// Fetch all goals for parent view (including active, pending, completed, rejected)
$all_goals = [];
if (isset($_SESSION['user_id']) && canCreateContent($_SESSION['user_id'])) {
    $stmt = $db->prepare("SELECT 
                             g.id, 
                             g.title, 
                             g.target_points, 
                             g.start_date, 
                             g.end_date, 
                             g.status, 
                             g.reward_id, 
                             r.title AS reward_title, 
                             COALESCE(
                                 NULLIF(TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))), ''),
                                 NULLIF(u.name, ''),
                                 u.username
                             ) AS child_display_name, 
                             g.rejected_at, 
                             g.rejection_comment, 
                             g.created_at,
                             COALESCE(
                                 NULLIF(TRIM(CONCAT(COALESCE(creator.first_name, ''), ' ', COALESCE(creator.last_name, ''))), ''),
                                 NULLIF(creator.name, ''),
                                 creator.username,
                                 'Unknown'
                             ) AS creator_display_name
                         FROM goals g 
                         JOIN users u ON g.child_user_id = u.id 
                         LEFT JOIN rewards r ON g.reward_id = r.id 
                         LEFT JOIN users creator ON g.created_by = creator.id
                         WHERE g.parent_user_id = :parent_id 
                         ORDER BY g.start_date ASC");
    $stmt->execute([':parent_id' => $family_root_id]);
    $all_goals = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format dates for all goals in parent view
    foreach ($all_goals as &$goal) {
        $goal['start_date_formatted'] = date('m/d/Y h:i A', strtotime($goal['start_date']));
        $goal['end_date_formatted'] = date('m/d/Y h:i A', strtotime($goal['end_date']));
        if (isset($goal['rejected_at'])) {
            $goal['rejected_at_formatted'] = date('m/d/Y h:i A', strtotime($goal['rejected_at']));
        }
        $goal['created_at_formatted'] = date('m/d/Y h:i A', strtotime($goal['created_at']));
    }
    unset($goal);

    $active_goals = array_filter($all_goals, function($g) { return $g['status'] === 'active' || $g['status'] === 'pending_approval'; });
    $completed_goals = array_filter($all_goals, function($g) { return $g['status'] === 'completed'; });
    $rejected_goals = array_filter($all_goals, function($g) { return $g['status'] === 'rejected'; });
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Goal Management</title>
    <link rel="stylesheet" href="css/main.css?v=3.12.2">
    <style>
        .goal-form, .goal-list {
            padding: 20px;
            max-width: 800px;
            margin: 0 auto;
        }
        .goal-form label, .goal-list label {
            display: block;
            margin-bottom: 5px;
        }
        .goal-form input, .goal-form select, .goal-form textarea {
            width: 100%;
            margin-bottom: 10px;
            padding: 8px;
        }
        .goal-card {
            padding: 15px;
            margin-bottom: 20px;
            border: 1px solid #ddd;
            border-radius: 8px;
            background: #f9f9f9;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .button {
            padding: 10px 20px;
            margin: 5px;
            background-color: <?php echo (isset($_SESSION['user_id']) && canCreateContent($_SESSION['user_id'])) ? '#4caf50' : '#ff9800'; ?>;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
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
        .edit-delete a {
            margin-right: 10px;
            color: #007bff;
            text-decoration: none;
        }
        .edit-delete a:hover {
            text-decoration: underline;
        }
        .reject-comment {
            margin-top: 10px;
        }
        /* Autism-friendly styles for children */
        .child-view .goal-card {
            background-color: #e6f3fa;
            border: 2px solid #2196f3;
            font-size: 1.2em;
        }
        .child-view .button {
            font-size: 1.1em;
            padding: 12px 24px;
        }
        .rejected-card {
            background-color: #ffebee; /* Light red for rejected goals */
            border-left: 5px solid #f44336;
        }
    </style>
</head>
<body<?php echo !empty($bodyClasses) ? ' class="' . implode(' ', $bodyClasses) . '"' : ''; ?>>
    <header>
        <h1>Goal Management</h1>
        <p>Welcome, <?php echo htmlspecialchars($_SESSION['name'] ?? $_SESSION['username'] ?? 'Unknown User'); ?>
            <?php if ($welcome_role_label): ?>
                <span class="role-badge">(<?php echo htmlspecialchars($welcome_role_label); ?>)</span>
            <?php endif; ?>
        </p>
        <a href="dashboard_<?php echo $_SESSION['role']; ?>.php">Dashboard</a> | 
        <a href="task.php">Tasks</a> | 
        <a href="routine.php">Routines</a> | 
        <a href="profile.php?self=1">Profile</a> | 
        <a href="logout.php">Logout</a>
    </header>
    <main class="<?php echo ($_SESSION['role'] === 'child') ? 'child-view' : ''; ?>">
        <?php if (isset($message)) echo "<p>$message</p>"; ?>
        <?php if (isset($_SESSION['user_id']) && canCreateContent($_SESSION['user_id'])): ?>
            <div class="goal-form">
                <h2>Create Goal</h2>
                <form method="POST" action="goal.php">
                    <label for="child_user_id">Child:</label>
                    <select id="child_user_id" name="child_user_id" required>
                        <?php
                        $stmt = $db->prepare("SELECT cp.child_user_id, cp.child_name 
                                             FROM child_profiles cp 
                                             WHERE cp.parent_user_id = :parent_id");
                        $stmt->execute([':parent_id' => $family_root_id]);
                        $children = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        foreach ($children as $child): ?>
                            <option value="<?php echo $child['child_user_id']; ?>">
                                <?php echo htmlspecialchars($child['child_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <label for="title">Title:</label>
                    <input type="text" id="title" name="title" required>
                    <label for="target_points">Target Points:</label>
                    <input type="number" id="target_points" name="target_points" min="1" required>
                    <label for="start_date">Start Date/Time:</label>
                    <input type="datetime-local" id="start_date" name="start_date" required>
                    <label for="end_date">End Date/Time:</label>
                    <input type="datetime-local" id="end_date" name="end_date" required>
                    <label for="reward_id">Reward (optional):</label>
                    <select id="reward_id" name="reward_id">
                        <option value="">None</option>
                        <?php
                        $stmt = $db->prepare("SELECT id, title FROM rewards WHERE parent_user_id = :parent_id AND status = 'available'");
                        $stmt->execute([':parent_id' => $family_root_id]);
                        $rewards = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        foreach ($rewards as $reward): ?>
                            <option value="<?php echo $reward['id']; ?>"><?php echo htmlspecialchars($reward['title']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" name="create_goal" class="button">Create Goal</button>
                </form>
            </div>
        <?php endif; ?>
        <div class="goal-list">
            <h2><?php echo (isset($_SESSION['user_id']) && canCreateContent($_SESSION['user_id'])) ? 'Created Goals' : 'Your Goals'; ?></h2>
            <?php if (empty($goals)): ?>
                <p>No goals available.</p>
            <?php else: ?>
                <h3>Active Goals</h3>
                <?php if (empty($active_goals)): ?>
                    <p>No active goals.</p>
                <?php else: ?>
                    <?php foreach ($active_goals as $goal): ?>
                        <div class="goal-card">
                            <p>Title: <?php echo htmlspecialchars($goal['title']); ?></p>
                            <p>Target Points: <?php echo htmlspecialchars($goal['target_points']); ?></p>
                            <p>Period: <?php echo htmlspecialchars($goal['start_date_formatted']); ?> to <?php echo htmlspecialchars($goal['end_date_formatted']); ?></p>
                            <p>Reward: <?php echo htmlspecialchars($goal['reward_title'] ?? 'None'); ?></p>
                            <?php if (!empty($goal['creator_display_name'])): ?>
                                <p>Created by: <?php echo htmlspecialchars($goal['creator_display_name']); ?></p>
                            <?php endif; ?>
                            <p>Status: <?php echo htmlspecialchars($goal['status']); ?></p>
                            <?php if (isset($_SESSION['user_id']) && canCreateContent($_SESSION['user_id'])): ?>
                                <p>Assigned to: <?php echo htmlspecialchars($goal['child_display_name']); ?></p>
                                <?php if ($goal['status'] === 'pending_approval'): ?>
                                    <form method="POST" action="goal.php">
                                        <input type="hidden" name="goal_id" value="<?php echo $goal['id']; ?>">
                                        <button type="submit" name="approve_goal" class="button">Approve</button>
                                        <button type="submit" name="reject_goal" class="button" style="background-color: #f44336;">Reject</button>
                                        <div class="reject-comment">
                                            <label for="rejection_comment_<?php echo $goal['id']; ?>">Comment (optional):</label>
                                            <textarea id="rejection_comment_<?php echo $goal['id']; ?>" name="rejection_comment"></textarea>
                                        </div>
                                    </form>
                                <?php endif; ?>
                                <div class="edit-delete">
                                    <a href="edit_goal.php?id=<?php echo $goal['id']; ?>">Edit</a>
                                    <form method="POST" action="goal.php" style="display:inline;">
                                        <input type="hidden" name="goal_id" value="<?php echo $goal['id']; ?>">
                                        <button type="submit" name="delete_goal" class="button" style="background-color: #f44336;">Delete</button>
                                    </form>
                                </div>
                            <?php elseif ($_SESSION['role'] === 'child' && $goal['status'] === 'active'): ?>
                                <form method="POST" action="goal.php">
                                    <input type="hidden" name="goal_id" value="<?php echo $goal['id']; ?>">
                                    <button type="submit" name="request_completion" class="button">Request Completion</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
                <h3>Completed Goals</h3>
                <?php if (empty($completed_goals)): ?>
                    <p>No completed goals.</p>
                <?php else: ?>
                    <?php foreach ($completed_goals as $goal): ?>
                        <div class="goal-card">
                            <p>Title: <?php echo htmlspecialchars($goal['title']); ?></p>
                            <p>Target Points: <?php echo htmlspecialchars($goal['target_points']); ?></p>
                            <p>Period: <?php echo htmlspecialchars($goal['start_date_formatted']); ?> to <?php echo htmlspecialchars($goal['end_date_formatted']); ?></p>
                            <p>Reward: <?php echo htmlspecialchars($goal['reward_title'] ?? 'None'); ?></p>
                            <p>Status: Completed</p>
                            <?php if (!empty($goal['creator_display_name'])): ?>
                                <p>Created by: <?php echo htmlspecialchars($goal['creator_display_name']); ?></p>
                            <?php endif; ?>
                            <?php if (isset($_SESSION['user_id']) && canCreateContent($_SESSION['user_id'])): ?>
                                <p>Child: <?php echo htmlspecialchars($goal['child_display_name']); ?></p>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
                <h3>Rejected Goals</h3>
                <?php if (empty($rejected_goals)): ?>
                    <p>No rejected goals.</p>
                <?php else: ?>
                    <?php foreach ($rejected_goals as $goal): ?>
                        <div class="goal-card rejected-card">
                            <p>Title: <?php echo htmlspecialchars($goal['title']); ?></p>
                            <p>Target Points: <?php echo htmlspecialchars($goal['target_points']); ?></p>
                            <p>Period: <?php echo htmlspecialchars($goal['start_date_formatted']); ?> to <?php echo htmlspecialchars($goal['end_date_formatted']); ?></p>
                            <p>Reward: <?php echo htmlspecialchars($goal['reward_title'] ?? 'None'); ?></p>
                            <?php if (!empty($goal['creator_display_name'])): ?>
                                <p>Created by: <?php echo htmlspecialchars($goal['creator_display_name']); ?></p>
                            <?php endif; ?>
                            <p>Status: Rejected</p>
                            <p>Rejected on: <?php echo htmlspecialchars($goal['rejected_at_formatted']); ?></p>
                            <p>Comment: <?php echo htmlspecialchars($goal['rejection_comment'] ?? 'No comments available.'); ?></p>
                            <?php if (isset($_SESSION['user_id']) && canCreateContent($_SESSION['user_id'])): ?>
                                <p>Child: <?php echo htmlspecialchars($goal['child_display_name']); ?></p>
                                <form method="POST" action="goal.php">
                                    <input type="hidden" name="goal_id" value="<?php echo $goal['id']; ?>">
                                    <button type="submit" name="reactivate_goal" class="button">Reactivate</button>
                                </form>
                                <div class="edit-delete">
                                    <form method="POST" action="goal.php" style="display:inline;">
                                        <input type="hidden" name="goal_id" value="<?php echo $goal['id']; ?>">
                                        <button type="submit" name="delete_goal" class="button" style="background-color: #f44336;">Delete</button>
                                    </form>
                                </div>
                            <?php elseif ($_SESSION['role'] === 'child'): ?>
                                <p>Created on: <?php echo htmlspecialchars($goal['created_at_formatted']); ?></p>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </main>
    <footer>
        <p>Child Task and Chore App - Ver 3.12.2</p>
    </footer>
</body>
</html>
