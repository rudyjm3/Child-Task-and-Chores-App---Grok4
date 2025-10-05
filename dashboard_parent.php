<?php
// dashboard_parent.php - Parent dashboard
// Purpose: Display parent dashboard with child overview and management links
// Inputs: Session data
// Outputs: Dashboard interface
// Version: 3.4.8 (Added Routine Management UI: Routine Tasks pool and quick routine creation)

require_once __DIR__ . '/includes/functions.php';

session_start(); // Force session start to load existing session
error_log("Dashboard Parent: user_id=" . (isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 'null') . ", role=" . (isset($_SESSION['role']) ? $_SESSION['role'] : 'null') . ", session_id=" . session_id() . ", cookie=" . (isset($_SERVER['HTTP_COOKIE']) ? $_SERVER['HTTP_COOKIE'] : 'none'));
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'parent') {
    header("Location: login.php");
    exit;
}

// Set username in session if not already set
if (!isset($_SESSION['username'])) {
    $userStmt = $db->prepare("SELECT username FROM users WHERE id = :id");
    $userStmt->execute([':id' => $_SESSION['user_id']]);
    $_SESSION['username'] = $userStmt->fetchColumn() ?: 'Unknown User';
}

$data = getDashboardData($_SESSION['user_id']);

// Fetch Routine Tasks for parent dashboard
$routine_tasks = getRoutineTasks($_SESSION['user_id']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create_reward'])) {
        $title = filter_input(INPUT_POST, 'reward_title', FILTER_SANITIZE_STRING);
        $description = filter_input(INPUT_POST, 'reward_description', FILTER_SANITIZE_STRING);
        $point_cost = filter_input(INPUT_POST, 'point_cost', FILTER_VALIDATE_INT);
        if (createReward($_SESSION['user_id'], $title, $description, $point_cost)) {
            $message = "Reward created successfully!";
        } else {
            $message = "Failed to create reward.";
        }
    } elseif (isset($_POST['create_goal'])) {
        $child_user_id = filter_input(INPUT_POST, 'child_user_id', FILTER_VALIDATE_INT);
        $title = filter_input(INPUT_POST, 'goal_title', FILTER_SANITIZE_STRING);
        $target_points = filter_input(INPUT_POST, 'target_points', FILTER_VALIDATE_INT);
        $start_date = filter_input(INPUT_POST, 'start_date', FILTER_SANITIZE_STRING);
        $end_date = filter_input(INPUT_POST, 'end_date', FILTER_SANITIZE_STRING);
        $reward_id = filter_input(INPUT_POST, 'reward_id', FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE);
        if (createGoal($_SESSION['user_id'], $child_user_id, $title, $target_points, $start_date, $end_date, $reward_id)) {
            $message = "Goal created successfully!";
        } else {
            $message = "Failed to create goal. Check date range or reward ID.";
        }
    } elseif (isset($_POST['approve_goal']) || isset($_POST['reject_goal'])) {
        $goal_id = filter_input(INPUT_POST, 'goal_id', FILTER_VALIDATE_INT);
        $action = isset($_POST['approve_goal']) ? 'approve' : 'reject';
        $comment = filter_input(INPUT_POST, 'rejection_comment', FILTER_SANITIZE_STRING);
        $points = approveGoal($_SESSION['user_id'], $goal_id, $action === 'approve', $comment);
        if ($points !== false) {
            $message = $action === 'approve' ? "Goal approved! Child earned $points points." : "Goal rejected.";
            $data = getDashboardData($_SESSION['user_id']); // Refresh data
        } else {
            $message = "Failed to $action goal.";
        }
    } elseif (isset($_POST['create_routine_task'])) {
        $title = filter_input(INPUT_POST, 'title', FILTER_SANITIZE_STRING);
        $description = filter_input(INPUT_POST, 'description', FILTER_SANITIZE_STRING);
        $time_limit = filter_input(INPUT_POST, 'time_limit', FILTER_VALIDATE_INT);
        $point_value = filter_input(INPUT_POST, 'point_value', FILTER_VALIDATE_INT);
        $category = filter_input(INPUT_POST, 'category', FILTER_SANITIZE_STRING);
        if (createRoutineTask($_SESSION['user_id'], $title, $description, $time_limit, $point_value, $category)) {
            $message = "Routine Task created successfully!";
            $routine_tasks = getRoutineTasks($_SESSION['user_id']); // Refresh
        } else {
            $message = "Failed to create Routine Task.";
        }
    } elseif (isset($_POST['delete_routine_task'])) {
        $routine_task_id = filter_input(INPUT_POST, 'routine_task_id', FILTER_VALIDATE_INT);
        if (deleteRoutineTask($routine_task_id, $_SESSION['user_id'])) {
            $message = "Routine Task deleted successfully!";
            $routine_tasks = getRoutineTasks($_SESSION['user_id']); // Refresh
        } else {
            $message = "Failed to delete Routine Task.";
        }
    } elseif (isset($_POST['create_routine'])) {
        $child_user_id = filter_input(INPUT_POST, 'child_user_id', FILTER_VALIDATE_INT);
        $title = filter_input(INPUT_POST, 'title', FILTER_SANITIZE_STRING);
        $start_time = filter_input(INPUT_POST, 'start_time', FILTER_SANITIZE_STRING);
        $end_time = filter_input(INPUT_POST, 'end_time', FILTER_SANITIZE_STRING);
        $recurrence = filter_input(INPUT_POST, 'recurrence', FILTER_SANITIZE_STRING);
        $bonus_points = filter_input(INPUT_POST, 'bonus_points', FILTER_VALIDATE_INT);
        $routine_task_ids = $_POST['routine_task_ids'] ?? []; // Array of selected IDs

        $routine_id = createRoutine($_SESSION['user_id'], $child_user_id, $title, $start_time, $end_time, $recurrence, $bonus_points);
        if ($routine_id) {
            foreach ($routine_task_ids as $order => $routine_task_id) {
                addRoutineTaskToRoutine($routine_id, $routine_task_id, $order + 1);
            }
            $message = "Routine created successfully!";
        } else {
            $message = "Failed to create routine.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Parent Dashboard</title>
    <link rel="stylesheet" href="css/main.css">
    <style>
        .dashboard { padding: 20px; max-width: 800px; margin: 0 auto; }
        .children-overview, .management-links, .active-rewards, .redeemed-rewards, .pending-approvals, .completed-goals, .routine-management { margin-top: 20px; }
        .child-item, .reward-item, .goal-item, .routine-task-item, .routine-item { background-color: #f5f5f5; padding: 10px; margin: 5px 0; border-radius: 5px; }
        .button { padding: 10px 20px; margin: 5px; background-color: #4caf50; color: white; border: none; border-radius: 5px; cursor: pointer; text-decoration: none; display: inline-block; }
        .approve-button { background-color: #4caf50; }
        .reject-button { background-color: #f44336; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; }
        .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 8px; }
        /* Routine Management Styles - Mobile Responsive */
        .routine-management { display: flex; flex-wrap: wrap; gap: 20px; }
        .routine-pool, .routine-form { flex: 1; min-width: 300px; }
        .routine-task-list { list-style: none; padding: 0; }
        .routine-task-item { display: flex; justify-content: space-between; align-items: center; background: #e3f2fd; border: 1px solid #bbdefb; padding: 10px; margin: 5px 0; border-radius: 8px; }
        .routine-task-item button { background: #2196f3; color: white; border: none; border-radius: 4px; padding: 5px 10px; cursor: pointer; }
        /* Autism-Friendly: Large buttons, high contrast */
        .button { font-size: 16px; min-height: 44px; }
        @media (max-width: 768px) { .routine-management { flex-direction: column; } }
    </style>
</head>
<body>
   <header>
      <h1>Parent Dashboard</h1>
      <p>Welcome, <?php echo htmlspecialchars($_SESSION['username'] ?? 'Unknown User'); ?> (<?php echo htmlspecialchars($_SESSION['role']); ?>)</p>
      <a href="goal.php">Goals</a> | <a href="task.php">Tasks</a> | <a href="routine.php">Routines</a> | <a href="profile.php">Profile</a> | <a href="logout.php">Logout</a>
   </header>
   <main class="dashboard">
      <?php if (isset($message)) echo "<p>$message</p>"; ?>
      <div class="children-overview">
         <h2>Children Overview</h2>
         <?php if (isset($data['children']) && is_array($data['children']) && !empty($data['children'])): ?>
               <?php foreach ($data['children'] as $child): ?>
                  <div class="child-item">
                     <p>Child: <?php echo htmlspecialchars($child['username']); ?>, Avatar=<?php echo htmlspecialchars($child['avatar'] ?? 'No Avatar'); ?>, Age=<?php echo htmlspecialchars($child['age'] ?? 'N/A'); ?></p>
                  </div>
               <?php endforeach; ?>
         <?php else: ?>
               <p>No children registered.</p>
         <?php endif; ?>
      </div>
      <div class="management-links">
         <h2>Management Links</h2>
         <a href="task.php" class="button">Create Task</a>
         <div>
               <h3>Create Reward</h3>
               <form method="POST" action="dashboard_parent.php">
                  <div class="form-group">
                     <label for="reward_title">Title:</label>
                     <input type="text" id="reward_title" name="reward_title" required>
                  </div>
                  <div class="form-group">
                     <label for="reward_description">Description:</label>
                     <textarea id="reward_description" name="reward_description"></textarea>
                  </div>
                  <div class="form-group">
                     <label for="point_cost">Point Cost:</label>
                     <input type="number" id="point_cost" name="point_cost" min="1" required>
                  </div>
                  <button type="submit" name="create_reward" class="button">Create Reward</button>
               </form>
         </div>
         <div>
               <h3>Create Goal</h3>
               <form method="POST" action="dashboard_parent.php">
                  <div class="form-group">
                     <label for="child_user_id">Child:</label>
                     <select id="child_user_id" name="child_user_id" required>
                        <?php
                        $stmt = $db->prepare("SELECT cp.child_user_id, u.username FROM child_profiles cp JOIN users u ON cp.child_user_id = u.id WHERE cp.parent_user_id = :parent_id");
                        $stmt->execute([':parent_id' => $_SESSION['user_id']]);
                        $children = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        foreach ($children as $child): ?>
                            <option value="<?php echo $child['child_user_id']; ?>"><?php echo htmlspecialchars($child['username']); ?></option>
                        <?php endforeach; ?>
                     </select>
                  </div>
                  <div class="form-group">
                     <label for="goal_title">Title:</label>
                     <input type="text" id="goal_title" name="goal_title" required>
                  </div>
                  <div class="form-group">
                     <label for="target_points">Target Points:</label>
                     <input type="number" id="target_points" name="target_points" min="1" required>
                  </div>
                  <div class="form-group">
                     <label for="start_date">Start Date:</label>
                     <input type="datetime-local" id="start_date" name="start_date">
                  </div>
                  <div class="form-group">
                     <label for="end_date">End Date:</label>
                     <input type="datetime-local" id="end_date" name="end_date">
                  </div>
                  <div class="form-group">
                     <label for="reward_id">Reward (optional):</label>
                     <select id="reward_id" name="reward_id">
                        <option value="">None</option>
                        <?php foreach ($data['active_rewards'] as $reward): ?>
                            <option value="<?php echo $reward['id']; ?>"><?php echo htmlspecialchars($reward['title']); ?></option>
                        <?php endforeach; ?>
                     </select>
                  </div>
                  <button type="submit" name="create_goal" class="button">Create Goal</button>
               </form>
         </div>
      </div>
      <div class="routine-management">
         <h2>Routine Management</h2>
         <a href="routine.php" class="button">Full Routine Editor</a>
         <div class="routine-pool">
            <h3>Routine Tasks Pool</h3>
            <form method="POST" action="dashboard_parent.php">
               <div class="form-group">
                  <label for="title">Title:</label>
                  <input type="text" id="title" name="title" required>
               </div>
               <div class="form-group">
                  <label for="description">Description:</label>
                  <textarea id="description" name="description"></textarea>
               </div>
               <div class="form-group">
                  <label for="time_limit">Time Limit (min):</label>
                  <input type="number" id="time_limit" name="time_limit" min="1">
               </div>
               <div class="form-group">
                  <label for="point_value">Point Value:</label>
                  <input type="number" id="point_value" name="point_value" min="0">
               </div>
               <div class="form-group">
                  <label for="category">Category:</label>
                  <select id="category" name="category">
                     <option value="hygiene">Hygiene</option>
                     <option value="homework">Homework</option>
                     <option value="household">Household</option>
                  </select>
               </div>
               <button type="submit" name="create_routine_task" class="button">Add Routine Task</button>
            </form>
            <ul class="routine-task-list">
               <?php foreach ($routine_tasks as $rt): ?>
                  <li class="routine-task-item">
                     <span><?php echo htmlspecialchars($rt['title']); ?> (<?php echo htmlspecialchars($rt['category']); ?>, <?php echo htmlspecialchars($rt['time_limit']); ?>min)</span>
                     <div>
                        <a href="routine.php?edit_rt=<?php echo $rt['id']; ?>" class="button" style="background: #ff9800; font-size: 12px; padding: 2px 8px;">Edit</a>
                        <form method="POST" style="display: inline;">
                           <input type="hidden" name="routine_task_id" value="<?php echo $rt['id']; ?>">
                           <button type="submit" name="delete_routine_task" class="button" style="background: #f44336; font-size: 12px; padding: 2px 8px;" onclick="return confirm('Delete this Routine Task?')">Delete</button>
                        </form>
                     </div>
                  </li>
               <?php endforeach; ?>
            </ul>
         </div>
         <div class="routine-form">
            <h3>Quick Create Routine</h3>
            <form method="POST" action="dashboard_parent.php">
               <div class="form-group">
                  <label for="child_user_id_routine">Child:</label>
                  <select id="child_user_id_routine" name="child_user_id" required>
                     <?php foreach ($data['children'] as $child): ?>
                        <option value="<?php echo $child['child_user_id']; ?>"><?php echo htmlspecialchars($child['username']); ?></option>
                     <?php endforeach; ?>
                  </select>
               </div>
               <div class="form-group">
                  <label for="title_routine">Title:</label>
                  <input type="text" id="title_routine" name="title" required>
               </div>
               <div class="form-group">
                  <label for="start_time">Start Time:</label>
                  <input type="time" id="start_time" name="start_time" required>
               </div>
               <div class="form-group">
                  <label for="end_time">End Time:</label>
                  <input type="time" id="end_time" name="end_time" required>
               </div>
               <div class="form-group">
                  <label for="recurrence">Recurrence:</label>
                  <select id="recurrence" name="recurrence">
                     <option value="">None</option>
                     <option value="daily">Daily</option>
                     <option value="weekly">Weekly</option>
                  </select>
               </div>
               <div class="form-group">
                  <label for="bonus_points">Bonus Points:</label>
                  <input type="number" id="bonus_points" name="bonus_points" min="0" value="0" required>
               </div>
               <div class="form-group">
                  <label>Routine Tasks:</label>
                  <select multiple name="routine_task_ids[]" size="5">
                     <?php foreach ($routine_tasks as $rt): ?>
                        <option value="<?php echo $rt['id']; ?>"><?php echo htmlspecialchars($rt['title']); ?> (<?php echo $rt['time_limit']; ?>min)</option>
                     <?php endforeach; ?>
                  </select>
               </div>
               <button type="submit" name="create_routine" class="button">Create Routine</button>
            </form>
         </div>
      </div>
      <div class="active-rewards">
         <h2>Active Rewards</h2>
         <?php if (isset($data['active_rewards']) && is_array($data['active_rewards']) && !empty($data['active_rewards'])): ?>
               <?php foreach ($data['active_rewards'] as $reward): ?>
                  <div class="reward-item">
                     <p>Reward: <?php echo htmlspecialchars($reward['title']); ?> (<?php echo htmlspecialchars($reward['point_cost']); ?> points)</p>
                     <p>Description: <?php echo htmlspecialchars($reward['description']); ?></p>
                  </div>
               <?php endforeach; ?>
         <?php else: ?>
               <p>No rewards available.</p>
         <?php endif; ?>
      </div>
      <div class="redeemed-rewards">
         <h2>Redeemed Rewards</h2>
         <?php if (isset($data['redeemed_rewards']) && is_array($data['redeemed_rewards']) && !empty($data['redeemed_rewards'])): ?>
               <?php foreach ($data['redeemed_rewards'] as $reward): ?>
                  <div class="reward-item">
                     <p>Reward: <?php echo htmlspecialchars($reward['title']); ?> (<?php echo htmlspecialchars($reward['point_cost']); ?> points)</p>
                     <p>Description: <?php echo htmlspecialchars($reward['description']); ?></p>
                     <p>Redeemed by: <?php echo htmlspecialchars($reward['child_username'] ?? 'Unknown'); ?></p>
                     <p>Redeemed on: <?php echo !empty($reward['redeemed_on']) ? htmlspecialchars(date('m/d/Y h:i A', strtotime($reward['redeemed_on']))) : 'Date unavailable'; ?></p>
                  </div>
               <?php endforeach; ?>
         <?php else: ?>
               <p>No rewards redeemed yet.</p>
         <?php endif; ?>
      </div>
      <div class="pending-approvals">
         <h2>Pending Goal Approvals</h2>
         <?php if (isset($data['pending_approvals']) && is_array($data['pending_approvals']) && !empty($data['pending_approvals'])): ?>
               <?php foreach ($data['pending_approvals'] as $approval): ?>
                  <div class="goal-item">
                     <p>Goal: <?php echo htmlspecialchars($approval['title']); ?> (Target: <?php echo htmlspecialchars($approval['target_points']); ?> points)</p>
                     <p>Child: <?php echo htmlspecialchars($approval['child_username']); ?></p>
                     <p>Requested on: <?php echo htmlspecialchars($approval['requested_at']); ?></p>
                     <form method="POST" action="dashboard_parent.php">
                           <input type="hidden" name="goal_id" value="<?php echo $approval['id']; ?>">
                           <button type="submit" name="approve_goal" class="button approve-button">Approve</button>
                           <button type="submit" name="reject_goal" class="button reject-button">Reject</button>
                           <div class="form-group">
                              <label for="rejection_comment_<?php echo $approval['id']; ?>">Comment (optional):</label>
                              <textarea id="rejection_comment_<?php echo $approval['id']; ?>" name="rejection_comment"></textarea>
                           </div>
                     </form>
                  </div>
               <?php endforeach; ?>
         <?php else: ?>
               <p>No pending approvals.</p>
         <?php endif; ?>
      </div>
      <div class="completed-goals">
         <h2>Completed Goals</h2>
         <?php
         $all_completed_goals = [];
         $parent_id = $_SESSION['user_id'];
         $stmt = $db->prepare("SELECT g.id, g.title, g.target_points, g.start_date, g.end_date, g.completed_at, u.username as child_username 
                              FROM goals g 
                              JOIN users u ON g.child_user_id = u.id 
                              WHERE g.parent_user_id = :parent_id AND g.status = 'completed'");
         $stmt->execute([':parent_id' => $parent_id]);
         $all_completed_goals = $stmt->fetchAll(PDO::FETCH_ASSOC);
         ?>
         <?php if (!empty($all_completed_goals)): ?>
               <?php foreach ($all_completed_goals as $goal): ?>
                  <div class="goal-item">
                     <p>Goal: <?php echo htmlspecialchars($goal['title']); ?> (Target: <?php echo htmlspecialchars($goal['target_points']); ?> points)</p>
                     <p>Child: <?php echo htmlspecialchars($goal['child_username']); ?></p>
                     <p>Period: <?php echo htmlspecialchars(date('m/d/Y h:i A', strtotime($goal['start_date']))); ?> to <?php echo htmlspecialchars(date('m/d/Y h:i A', strtotime($goal['end_date']))); ?></p>
                     <p>Completed on: <?php echo htmlspecialchars(date('m/d/Y h:i A', strtotime($goal['completed_at']))); ?></p>
                  </div>
               <?php endforeach; ?>
         <?php else: ?>
               <p>No goals completed yet.</p>
         <?php endif; ?>
      </div>
      <div class="rejected-goals">
         <h2>Rejected Goals</h2>
         <?php
         $stmt = $db->prepare("SELECT g.id, g.title, g.target_points, g.start_date, g.end_date, g.rejected_at, g.rejection_comment, u.username as child_username, r.title as reward_title 
                              FROM goals g 
                              JOIN users u ON g.child_user_id = u.id 
                              LEFT JOIN rewards r ON g.reward_id = r.id 
                              WHERE g.parent_user_id = :parent_id AND g.status = 'rejected'");
         $stmt->execute([':parent_id' => $_SESSION['user_id']]);
         $rejected_goals = $stmt->fetchAll(PDO::FETCH_ASSOC);
         foreach ($rejected_goals as &$goal) {
               $goal['start_date_formatted'] = date('m/d/Y h:i A', strtotime($goal['start_date']));
               $goal['end_date_formatted'] = date('m/d/Y h:i A', strtotime($goal['end_date']));
               $goal['rejected_at_formatted'] = date('m/d/Y h:i A', strtotime($goal['rejected_at']));
         }
         unset($goal);
         ?>
         <?php if (empty($rejected_goals)): ?>
               <p>No rejected goals.</p>
         <?php else: ?>
               <?php foreach ($rejected_goals as $goal): ?>
                  <div class="goal-item">
                     <p>Goal: <?php echo htmlspecialchars($goal['title']); ?> (Target: <?php echo htmlspecialchars($goal['target_points']); ?> points)</p>
                     <p>Child: <?php echo htmlspecialchars($goal['child_username']); ?></p>
                     <p>Period: <?php echo htmlspecialchars($goal['start_date_formatted']); ?> to <?php echo htmlspecialchars($goal['end_date_formatted']); ?></p>
                     <p>Reward: <?php echo htmlspecialchars($goal['reward_title'] ?? 'None'); ?></p>
                     <p>Status: Rejected</p>
                     <p>Rejected on: <?php echo htmlspecialchars($goal['rejected_at_formatted']); ?></p>
                     <p>Comment: <?php echo htmlspecialchars($goal['rejection_comment'] ?? 'No comments available.'); ?></p>
                  </div>
               <?php endforeach; ?>
         <?php endif; ?>
      </div>
   </main>
   <footer>
      <p>Child Task and Chores App - Ver 3.4.8</p>
   </footer>
</body>
</html>