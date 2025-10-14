<?php
// dashboard_parent.php - Parent dashboard
// Purpose: Display parent dashboard with child overview and management links
// Inputs: Session data
// Outputs: Dashboard interface
// Version: 3.5.1 (Age number input, photo upload save/resize, name display, caregiver child access)

require_once __DIR__ . '/includes/functions.php';

session_start(); // Force session start to load existing session
error_log("Dashboard Parent: user_id=" . (isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 'null') . ", role=" . (isset($_SESSION['role']) ? $_SESSION['role'] : 'null') . ", session_id=" . session_id() . ", cookie=" . (isset($_SERVER['HTTP_COOKIE']) ? $_SERVER['HTTP_COOKIE'] : 'none'));
if (!isset($_SESSION['user_id']) || !canCreateContent($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
// Set role_type for permission checks
$role_type = getUserRole($_SESSION['user_id']);

// Set username and name in session if not already set
if (!isset($_SESSION['username']) || !isset($_SESSION['name'])) {
    $userStmt = $db->prepare("SELECT username, name FROM users WHERE id = :id");
    $userStmt->execute([':id' => $_SESSION['user_id']]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);
    $_SESSION['username'] = $user['username'];
    $_SESSION['name'] = $user['name'] ?: $user['username']; // fallback to username if name is null
}

$data = getDashboardData($_SESSION['user_id']);

// Fetch Routine Tasks for parent dashboard (fix undefined)
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
    } elseif (isset($_POST['add_child'])) {
        // Get and split child's name into first and last name
        $first_name = filter_input(INPUT_POST, 'first_name', FILTER_SANITIZE_STRING);
        $last_name = filter_input(INPUT_POST, 'last_name', FILTER_SANITIZE_STRING);
        $child_username = filter_input(INPUT_POST, 'child_username', FILTER_SANITIZE_STRING);
        $child_password = filter_input(INPUT_POST, 'child_password', FILTER_SANITIZE_STRING);
        $birthday = filter_input(INPUT_POST, 'birthday', FILTER_SANITIZE_STRING);
        $avatar = filter_input(INPUT_POST, 'avatar', FILTER_SANITIZE_STRING);
        $gender = filter_input(INPUT_POST, 'child_gender', FILTER_SANITIZE_STRING);
        // Handle upload
        $upload_path = '';
        if (isset($_FILES['avatar_upload']) && $_FILES['avatar_upload']['error'] == 0) {
            $upload_dir = __DIR__ . '/uploads/avatars/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            $file_ext = pathinfo($_FILES['avatar_upload']['name'], PATHINFO_EXTENSION);
            $file_name = uniqid() . '_' . pathinfo($_FILES['avatar_upload']['name'], PATHINFO_FILENAME) . '.' . $file_ext;
            $upload_path = 'uploads/avatars/' . $file_name;
            if (move_uploaded_file($_FILES['avatar_upload']['tmp_name'], __DIR__ . '/' . $upload_path)) {
                // Resize image (GD library)
                $image = imagecreatefromstring(file_get_contents(__DIR__ . '/' . $upload_path));
                $resized = imagecreatetruecolor(100, 100);
                imagecopyresampled($resized, $image, 0, 0, 0, 0, 100, 100, imagesx($image), imagesy($image));
                imagejpeg($resized, __DIR__ . '/' . $upload_path, 90);
                imagedestroy($image);
                imagedestroy($resized);
                $avatar = $upload_path; // Use uploaded path
            } else {
                $message = "Upload failed; using default avatar.";
            }
        }
        if (createChildProfile($_SESSION['user_id'], $first_name, $last_name, $child_username, $child_password, $birthday, $avatar, $gender)) {
            $message = "Child added successfully! Username: $child_username, Password: $child_password (share securely).";
        } else {
            $message = "Failed to add child. Check for duplicate username.";
        }
    } elseif (isset($_POST['add_new_user'])) {
        $name = filter_input(INPUT_POST, 'secondary_name', FILTER_SANITIZE_STRING);
        $username = filter_input(INPUT_POST, 'secondary_username', FILTER_SANITIZE_STRING);
        $password = filter_input(INPUT_POST, 'secondary_password', FILTER_SANITIZE_STRING);
        $role_type = filter_input(INPUT_POST, 'role_type', FILTER_SANITIZE_STRING);
        
        if ($role_type && in_array($role_type, ['secondary_parent', 'family_member', 'caregiver'])) {
            if (addLinkedUser($_SESSION['user_id'], $username, $password, $name, $role_type)) {
                $role_display = str_replace('_', ' ', ucwords($role_type));
                $message = "$role_display added successfully! Username: $username";
            } else {
                $message = "Failed to add user. Check for duplicate username.";
            }
        } else {
            $message = "Invalid role type selected.";
        }
    } elseif (isset($_POST['add_secondary_parent'])) {
        $secondary_username = filter_input(INPUT_POST, 'secondary_username', FILTER_SANITIZE_STRING);
        $secondary_password = filter_input(INPUT_POST, 'secondary_password', FILTER_SANITIZE_STRING);
        $secondary_name = filter_input(INPUT_POST, 'secondary_name', FILTER_SANITIZE_STRING);
        if (addLinkedUser($_SESSION['user_id'], $secondary_username, $secondary_password, $secondary_name, $_POST['role_type'])) {
            $role_label = [
                'secondary_parent' => 'Secondary parent',
                'family_member' => 'Family member', 
                'caregiver' => 'Caregiver'
            ][$_POST['role_type']] ?? 'User';
            $message = "$role_label added successfully! Username: $secondary_username, Password: $secondary_password (share securely).";
        } else {
            $message = "Failed to add secondary parent. Check for duplicate username.";
        }
    } elseif (isset($_POST['delete_user']) && $role_type === 'main_parent') {
        $delete_user_id = filter_input(INPUT_POST, 'delete_user_id', FILTER_VALIDATE_INT);
        if ($delete_user_id) {
            $stmt = $db->prepare("DELETE FROM users WHERE id = :user_id AND id IN 
                             (SELECT linked_user_id FROM family_links 
                              WHERE main_parent_id = :main_parent_id)");
            if ($stmt->execute([':user_id' => $delete_user_id, ':main_parent_id' => $_SESSION['user_id']])) {
                $message = "User removed successfully.";
            } else {
                $message = "Failed to remove user.";
            }
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
        .children-overview, .management-links, .active-rewards, .redeemed-rewards, .pending-approvals, .completed-goals, .manage-family { margin-top: 20px; }
        .child-item, .reward-item, .goal-item { background-color: #f5f5f5; padding: 10px; margin: 5px 0; border-radius: 5px; }
        .button { padding: 10px 20px; margin: 5px; background-color: #4caf50; color: white; border: none; border-radius: 5px; cursor: pointer; text-decoration: none; display: inline-block; font-size: 16px; min-height: 44px; }
        .approve-button { background-color: #4caf50; }
        .reject-button { background-color: #f44336; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; }
        .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 8px; }
        /* Manage Family Styles - Mobile Responsive, Autism-Friendly Wizard */
        .manage-family { background: #f9f9f9; border-radius: 8px; padding: 20px; }
        .family-form { display: none; } /* JS toggle for wizard */
        .family-form.active { display: block; }
        .avatar-preview { width: 50px; height: 50px; border-radius: 50%; margin: 5px; cursor: pointer; }
        .avatar-options { display: flex; flex-wrap: wrap; justify-content: center; gap: 10px; }
        .avatar-option { width: 60px; height: 60px; border-radius: 50%; cursor: pointer; border: 2px solid #ddd; }
        .avatar-option.selected { border-color: #4caf50; }
        .upload-preview { max-width: 100px; max-height: 100px; border-radius: 50%; }
        .mother-badge { background: #e91e63; color: white; padding: 2px 6px; border-radius: 3px; font-size: 0.8em; }
        .father-badge { background: #2196f3; color: white; padding: 2px 6px; border-radius: 3px; font-size: 0.8em; }
        @media (max-width: 768px) { .manage-family { padding: 10px; } .button { width: 100%; } }
    </style>
    <script>
        // JS for Manage Family Wizard (step-by-step)
        document.addEventListener('DOMContentLoaded', function() {
            const addChildBtn = document.getElementById('add-child-btn');
            const addCaregiverBtn = document.getElementById('add-caregiver-btn');
            const childForm = document.getElementById('child-form');
            const caregiverForm = document.getElementById('caregiver-form');
            const avatarOptions = document.querySelectorAll('.avatar-option');

            addChildBtn.addEventListener('click', () => {
                childForm.classList.add('active');
                caregiverForm.classList.remove('active');
            });

            addCaregiverBtn.addEventListener('click', () => {
                caregiverForm.classList.add('active');
                childForm.classList.remove('active');
            });

            // Avatar preview
            avatarOptions.forEach(option => {
                option.addEventListener('click', () => {
                    avatarOptions.forEach(opt => opt.classList.remove('selected'));
                    option.classList.add('selected');
                    document.getElementById('avatar-preview').src = option.dataset.avatar;
                    document.getElementById('avatar').value = option.dataset.avatar;
                });
            });

            // Upload preview
            document.getElementById('avatar-upload').addEventListener('change', function(e) {
                const file = e.target.files[0];
                if (file) {
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        document.getElementById('avatar-preview').src = e.target.result;
                    };
                    reader.readAsDataURL(file);
                }
            });
        });
    </script>
</head>
<body>
   <header>
      <h1>Parent Dashboard</h1>
      <p>Welcome, <?php echo htmlspecialchars($_SESSION['name'] ?? $_SESSION['username']); ?> 
         <?php if ($role_type === 'main_parent'): ?>
            <span class="role-badge">(Main Account Owner)</span>
         <?php elseif ($role_type === 'secondary_parent'): ?>
            <span class="role-badge">(Secondary Parent)</span>
         <?php elseif ($role_type === 'family_member'): ?>
            <span class="role-badge">(Family Member)</span>
         <?php elseif ($role_type === 'caregiver'): ?>
            <span class="role-badge">(Caregiver)</span>
         <?php endif; ?>
      </p>
      <a href="goal.php">Goals</a> | <a href="task.php">Tasks</a> | <a href="routine.php">Routines</a> | <a href="profile.php">Profile</a> | <a href="logout.php">Logout</a>
   </header>
   <main class="dashboard">
      <?php if (isset($message)) echo "<p>$message</p>"; ?>
      <div class="children-overview">
         <h2>Children Overview</h2>
         <?php if (isset($data['children']) && is_array($data['children']) && !empty($data['children'])): ?>
               <?php foreach ($data['children'] as $child): ?>
                  <div class="child-item">
                     <p>Child: <?php echo htmlspecialchars($child['child_name']); ?>, Age=<?php echo htmlspecialchars($child['age'] ?? 'N/A'); ?></p>
                     <img src="<?php echo htmlspecialchars($child['avatar'] ?? 'default-avatar.png'); ?>" alt="Avatar" style="width: 50px; border-radius: 50%;">
                     <a href="profile.php?user_id=<?php echo $child['child_user_id']; ?>&type=child" class="button">Edit Child</a>
                  </div>
               <?php endforeach; ?>
         <?php else: ?>
               <p>No children added yet. Add your first child below!</p>
         <?php endif; ?>
      </div>
      <div class="family-members-list">
         <h2>Family Members</h2>
         <?php
         $stmt = $db->prepare("SELECT u.id, u.name, u.username, fl.role_type 
                              FROM users u 
                              JOIN family_links fl ON u.id = fl.linked_user_id 
                              WHERE fl.main_parent_id = :main_parent_id 
                              AND fl.role_type IN ('secondary_parent', 'family_member') 
                              ORDER BY fl.role_type, u.name");
         $stmt->execute([':main_parent_id' => $_SESSION['user_id']]);
         $family_members = $stmt->fetchAll(PDO::FETCH_ASSOC);
         
         if (!empty($family_members)): ?>
             <?php foreach ($family_members as $member): ?>
                 <div class="member-item">
                     <p><?php echo htmlspecialchars($member['name'] ?? $member['username']); ?> 
                        <span class="role-type">(<?php echo ucfirst(str_replace('_', ' ', $member['role_type'])); ?>)</span>
                     </p>
                     <?php if ($role_type === 'main_parent'): ?>
                         <a href="profile.php?edit_user=<?php echo $member['id']; ?>" class="button edit-btn">Edit</a>
                         <form method="POST" style="display: inline;">
                             <input type="hidden" name="delete_user_id" value="<?php echo $member['id']; ?>">
                             <button type="submit" name="delete_user" class="button delete-btn" 
                                     onclick="return confirm('Are you sure you want to remove this family member?')">
                                 Remove
                             </button>
                         </form>
                     <?php endif; ?>
                 </div>
             <?php endforeach; ?>
         <?php else: ?>
             <p>No family members added yet.</p>
         <?php endif; ?>

         <h2>Caregivers</h2>
         <?php
         $stmt = $db->prepare("SELECT u.id, u.name, u.username, fl.role_type 
                              FROM users u 
                              JOIN family_links fl ON u.id = fl.linked_user_id 
                              WHERE fl.main_parent_id = :main_parent_id 
                              AND fl.role_type = 'caregiver' 
                              ORDER BY u.name");
         $stmt->execute([':main_parent_id' => $_SESSION['user_id']]);
         $caregivers = $stmt->fetchAll(PDO::FETCH_ASSOC);
         
         if (!empty($caregivers)): ?>
             <?php foreach ($caregivers as $caregiver): ?>
                 <div class="member-item">
                     <p><?php echo htmlspecialchars($caregiver['name'] ?? $caregiver['username']); ?></p>
                     <?php if ($role_type === 'main_parent'): ?>
                         <a href="profile.php?edit_user=<?php echo $caregiver['id']; ?>" class="button edit-btn">Edit</a>
                         <form method="POST" style="display: inline;">
                             <input type="hidden" name="delete_user_id" value="<?php echo $caregiver['id']; ?>">
                             <button type="submit" name="delete_user" class="button delete-btn" 
                                     onclick="return confirm('Are you sure you want to remove this caregiver?')">
                                 Remove
                             </button>
                         </form>
                     <?php endif; ?>
                 </div>
             <?php endforeach; ?>
         <?php else: ?>
             <p>No caregivers added yet.</p>
         <?php endif; ?>
     </div>
      <div class="manage-family">
         <h2>Manage Family</h2>
         <button id="add-child-btn" class="button">Add Child</button>
         <button id="add-caregiver-btn" class="button" style="background: #ff9800;">Add New User</button>
         <div id="child-form" class="family-form">
            <h3>Add Child</h3>
            <form method="POST" action="dashboard_parent.php" enctype="multipart/form-data">
               <div class="form-group">
                  <label for="first_name">First Name:</label>
                  <input type="text" id="first_name" name="first_name" required>
               </div>
               <div class="form-group">
                  <label for="last_name">Last Name:</label>
                  <input type="text" id="last_name" name="last_name" required>
               </div>
               <div class="form-group">
                  <label for="child_username">Username (for login):</label>
                  <input type="text" id="child_username" name="child_username" required>
               </div>
               <div class="form-group">
                  <label for="child_password">Password (parent sets):</label>
                  <input type="password" id="child_password" name="child_password" required>
               </div>
               <div class="form-group">
                  <label for="birthday">Birthday:</label>
                  <input type="date" id="birthday" name="birthday" required>
               </div>
               <div class="form-group">
                  <label for="child_gender">Gender:</label>
                  <select id="child_gender" name="child_gender" required>
                      <option value="">Select...</option>
                      <option value="male">Male</option>
                      <option value="female">Female</option>
                  </select>
               </div>
               <div class="form-group">
                  <label>Avatar:</label>
                  <div class="avatar-options">
                     <img class="avatar-option" data-avatar="images/avatar_images/default-avatar.png" src="images/avatar_images/default-avatar.png" alt="Avatar default">
                     <img class="avatar-option" data-avatar="images/avatar_images/boy-1.png" src="images/avatar_images/boy-1.png" alt="Avatar 1">
                     <img class="avatar-option" data-avatar="images/avatar_images/girl-1.png" src="images/avatar_images/girl-1.png" alt="Avatar 2">
                     <img class="avatar-option" data-avatar="images/avatar_images/xmas-elf-boy.png" src="images/avatar_images/xmas-elf-boy.png" alt="Avatar 3">
                     <!-- Add more based on uploaded files -->
                  </div>
                  <input type="file" id="avatar-upload" name="avatar_upload" accept="image/*">
                  <img id="avatar-preview" src="images/avatar_images/default-avatar.png" alt="Preview" style="width: 100px; border-radius: 50%;">
                  <input type="hidden" id="avatar" name="avatar">
               </div>
               <button type="submit" name="add_child" class="button">Add Child</button>
            </form>
         </div>
         <div id="caregiver-form" class="family-form">
            <h3>Add Family Member/Caregiver</h3>
            <form method="POST" action="dashboard_parent.php">
               <div class="form-group">
                  <label for="secondary_name">Full Name:</label>
                  <input type="text" id="secondary_name" name="secondary_name" required placeholder="Enter full name">
               </div>
               <div class="form-group">
                  <label for="secondary_username">Username (for login):</label>
                  <input type="text" id="secondary_username" name="secondary_username" required placeholder="Choose a username">
               </div>
               <div class="form-group">
                  <label for="secondary_password">Password:</label>
                  <input type="password" id="secondary_password" name="secondary_password" required>
               </div>
               <div class="form-group">
                  <label for="role_type">Role Type:</label>
                  <select id="role_type" name="role_type" required>
                     <option value="secondary_parent">Secondary Parent (Full Access)</option>
                     <option value="family_member">Family Member (Limited Access)</option>
                     <option value="caregiver">Caregiver (Task Management Only)</option>
                  </select>
               </div>
               <button type="submit" name="add_new_user" class="button">Add New User</button>
            </form>
         </div>
      </div>

      <!-- Rest of sections (Management Links, Rewards, etc.) with name display updates -->
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
                        $stmt = $db->prepare("SELECT cp.child_user_id, cp.child_name 
                                             FROM child_profiles cp 
                                             WHERE cp.parent_user_id = :parent_id");
                        $stmt->execute([':parent_id' => $_SESSION['user_id']]);
                        $children = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        foreach ($children as $child): ?>
                            <option value="<?php echo $child['child_user_id']; ?>">
                                <?php echo htmlspecialchars($child['child_name']); ?>
                            </option>
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
                        <option value="<?php echo $child['child_user_id']; ?>"><?php echo htmlspecialchars($child['child_name']); ?></option>
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
      <p>Child Task and Chores App - Ver 3.5.1</p>
   </footer>
</body>
</html>