<?php
// dashboard_parent.php - Parent dashboard
// Purpose: Display parent dashboard with child overview and management links
// Inputs: Session data
// Outputs: Dashboard interface
// Version: 3.3.13

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
        $points = approveGoal($_SESSION['user_id'], $goal_id, $action === 'approve');
        if ($points !== false) {
            $message = $action === 'approve' ? "Goal approved! Child earned $points points." : "Goal rejected.";
            $data = getDashboardData($_SESSION['user_id']); // Refresh data
        } else {
            $message = "Failed to $action goal.";
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
        .children-overview, .management-links, .active-rewards, .redeemed-rewards, .pending-approvals, .completed-goals { margin-top: 20px; }
        .child-item, .reward-item, .goal-item { background-color: #f5f5f5; padding: 10px; margin: 5px 0; border-radius: 5px; }
        .button { padding: 10px 20px; margin: 5px; background-color: #4caf50; color: white; border: none; border-radius: 5px; cursor: pointer; text-decoration: none; display: inline-block; }
        .approve-button { background-color: #4caf50; }
        .reject-button { background-color: #f44336; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; }
        .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 8px; }
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
                  <button type="submit" name="create_reward">Create Reward</button>
               </form>
         </div>
         <div>
               <h3>Create Goal</h3>
               <form method="POST" action="dashboard_parent.php">
                  <div class="form-group">
                     <label for="child_user_id">Child:</label>
                     <select id="child_user_id" name="child_user_id" required>
                           <?php foreach ($data['children'] as $child): ?>
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
                     <input type="datetime-local" id="start_date" name="start_date" required>
                  </div>
                  <div class="form-group">
                     <label for="end_date">End Date:</label>
                     <input type="datetime-local" id="end_date" name="end_date" required>
                  </div>
                  <div class="form-group">
                     <label for="reward_id">Reward (optional):</label>
                     <select id="reward_id" name="reward_id">
                           <option value="">None</option>
                           <?php
                           $stmt = $db->prepare("SELECT id, title FROM rewards WHERE parent_user_id = :parent_id AND status = 'available'");
                           $stmt->execute([':parent_id' => $_SESSION['user_id']]);
                           $rewards = $stmt->fetchAll(PDO::FETCH_ASSOC);
                           foreach ($rewards as $reward): ?>
                              <option value="<?php echo $reward['id']; ?>"><?php echo htmlspecialchars($reward['title']); ?></option>
                           <?php endforeach; ?>
                     </select>
                  </div>
                  <button type="submit" name="create_goal">Create Goal</button>
               </form>
         </div>
         <a href="#" class="button">Summary Charts</a>
         <p>Points Earned: <?php echo isset($data['total_points_earned']) ? htmlspecialchars($data['total_points_earned']) : '0'; ?></p>
         <p>Goals Met: <?php echo isset($data['goals_met']) ? htmlspecialchars($data['goals_met']) : '0'; ?></p>
      </div>
      <div class="active-rewards">
         <h2>Active Rewards</h2>
         <?php if (isset($data['active_rewards']) && is_array($data['active_rewards']) && !empty($data['active_rewards'])): ?>
               <?php foreach ($data['active_rewards'] as $reward): ?>
                  <div class="reward-item">
                     <p><?php echo htmlspecialchars($reward['title']); ?> (<?php echo htmlspecialchars($reward['point_cost']); ?> points)</p>
                     <p><?php echo htmlspecialchars($reward['description']); ?></p>
                     <p>Created on: 
                           <?php 
                              echo htmlspecialchars(
                                 date('m/d/Y h:i A', strtotime($reward['created_on']))
                              ); 
                           ?>
                     </p>
                  </div>
               <?php endforeach; ?>
         <?php else: ?>
               <p>No active rewards available.</p>
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
                           <div class="reject-comment">
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
      <p>Child Task and Chores App - Ver 3.4.1</p>
   </footer>
</body>
</html>