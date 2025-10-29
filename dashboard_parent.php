<?php
// dashboard_parent.php - Parent dashboard
// Purpose: Display parent dashboard with child overview and management links
// Inputs: Session data
// Outputs: Dashboard interface
// Version: 3.5.2 (Fixed family list display for non-main parents by fetching correct main_parent_id; updated name display to use CONCAT(first_name, ' ', last_name))

require_once __DIR__ . '/includes/functions.php';

session_start(); // Force session start to load existing session
error_log("Dashboard Parent: user_id=" . (isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 'null') . ", role=" . (isset($_SESSION['role']) ? $_SESSION['role'] : 'null') . ", session_id=" . session_id() . ", cookie=" . (isset($_SERVER['HTTP_COOKIE']) ? $_SERVER['HTTP_COOKIE'] : 'none'));
if (!isset($_SESSION['user_id']) || !canCreateContent($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
// Set role_type for permission checks
$role_type = getEffectiveRole($_SESSION['user_id']);

// Compute the family context's main parent id for later queries
$main_parent_id = $_SESSION['user_id'];
if ($role_type !== 'main_parent') {
    $stmt = $db->prepare("SELECT main_parent_id FROM family_links WHERE linked_user_id = :linked_id LIMIT 1");
    $stmt->execute([':linked_id' => $_SESSION['user_id']]);
    $fetched_main_id = $stmt->fetchColumn();
    if ($fetched_main_id) {
        $main_parent_id = $fetched_main_id;
    }
}

if ($role_type === 'family_member') {
    $stmt = $db->prepare("SELECT role_type FROM family_links WHERE linked_user_id = :id");
    $stmt->execute([':id' => $_SESSION['user_id']]);
    $linked_role_type = $stmt->fetchColumn();
    if ($linked_role_type) {
        $role_type = $linked_role_type;
    }
}

// Ensure display name in session
if (!isset($_SESSION['name'])) {
    $_SESSION['name'] = getDisplayName($_SESSION['user_id']);
}
if (!isset($_SESSION['username'])) {
    $uStmt = $db->prepare("SELECT username FROM users WHERE id = :id");
    $uStmt->execute([':id' => $_SESSION['user_id']]);
    $_SESSION['username'] = $uStmt->fetchColumn() ?: 'Unknown';
}

$data = getDashboardData($_SESSION['user_id']);

$routine_overtime_logs = getRoutineOvertimeLogs($main_parent_id, 25);
$routine_overtime_stats = getRoutineOvertimeStats($main_parent_id);
$overtimeByChild = $routine_overtime_stats['by_child'] ?? [];
$overtimeByRoutine = $routine_overtime_stats['by_routine'] ?? [];
$formatDuration = function($seconds) {
    $seconds = max(0, (int) $seconds);
    $minutes = intdiv($seconds, 60);
    $remaining = $seconds % 60;
    return sprintf('%02d:%02d', $minutes, $remaining);
};

$welcome_role_label = getUserRoleLabel($_SESSION['user_id']);
if (!$welcome_role_label) {
    $fallback_role = $role_type ?: ($_SESSION['role'] ?? null);
    if ($fallback_role) {
        $welcome_role_label = ucfirst(str_replace('_', ' ', $fallback_role));
    }
}

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
        if (createGoal($main_parent_id, $child_user_id, $title, $target_points, $start_date, $end_date, $reward_id, $_SESSION['user_id'])) {
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
        if (!canAddEditChild($_SESSION['user_id'])) {
            $message = "You do not have permission to add children.";
        } else {
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
                $data = getDashboardData($_SESSION['user_id']);
            } else {
                $message = "Failed to add child. Check for duplicate username.";
            }
        }
    } elseif (isset($_POST['add_new_user'])) {
        if (!canAddEditFamilyMember($_SESSION['user_id'])) {
            $message = "You do not have permission to add family members or caregivers.";
        } else {
            $first_name = filter_input(INPUT_POST, 'secondary_first_name', FILTER_SANITIZE_STRING);
            $last_name = filter_input(INPUT_POST, 'secondary_last_name', FILTER_SANITIZE_STRING);
            $username = filter_input(INPUT_POST, 'secondary_username', FILTER_SANITIZE_STRING);
            $password = filter_input(INPUT_POST, 'secondary_password', FILTER_SANITIZE_STRING);
            $role_type = filter_input(INPUT_POST, 'role_type', FILTER_SANITIZE_STRING);
            
            if ($role_type && in_array($role_type, ['secondary_parent', 'family_member', 'caregiver'])) {
                if (addLinkedUser($_SESSION['user_id'], $username, $password, $first_name, $last_name, $role_type)) {
                    $role_display = str_replace('_', ' ', ucwords($role_type));
                    $message = "$role_display added successfully! Username: $username";
                } else {
                    $message = "Failed to add user. Check for duplicate username.";
                }
            } else {
                $message = "Invalid role type selected.";
            }
        }
    } elseif (isset($_POST['add_new_user'])) {
        $secondary_username   = filter_input(INPUT_POST, 'secondary_username', FILTER_SANITIZE_STRING);
        $secondary_password   = filter_input(INPUT_POST, 'secondary_password', FILTER_SANITIZE_STRING);
        $secondary_first_name = filter_input(INPUT_POST, 'secondary_first_name', FILTER_SANITIZE_STRING);
        $secondary_last_name  = filter_input(INPUT_POST, 'secondary_last_name', FILTER_SANITIZE_STRING);
        $role_type_sel        = filter_input(INPUT_POST, 'role_type', FILTER_SANITIZE_STRING);

        if (addLinkedUser($main_parent_id, $secondary_username, $secondary_password, $secondary_first_name, $secondary_last_name, $role_type_sel)) {
            $role_label = [
                'secondary_parent' => 'Secondary parent',
                'family_member' => 'Family member',
                'caregiver' => 'Caregiver'
            ][$role_type_sel] ?? 'User';
            $message = "$role_label added successfully! Username: $secondary_username.";
        } else {
            $message = "Failed to add user. Check for duplicate username.";
        }
    } elseif (isset($_POST['delete_user']) && in_array($role_type, ['main_parent', 'secondary_parent'])) {
        $delete_user_id = filter_input(INPUT_POST, 'delete_user_id', FILTER_VALIDATE_INT);
        if ($delete_user_id) {
            if ($delete_user_id == $main_parent_id) {
                $message = "Cannot remove the main account owner.";
            } else {
            // Try removing a linked adult first
            $stmt = $db->prepare("DELETE FROM users 
                                  WHERE id = :user_id AND id IN (
                                      SELECT linked_user_id FROM family_links WHERE main_parent_id = :main_parent_id
                                  )");
            $stmt->execute([':user_id' => $delete_user_id, ':main_parent_id' => $main_parent_id]);

            if ($stmt->rowCount() === 0) {
                // If not an adult link, try deleting a child of this parent
                $stmt2 = $db->prepare("DELETE FROM users 
                                       WHERE id = :user_id AND id IN (
                                           SELECT child_user_id FROM child_profiles WHERE parent_user_id = :main_parent_id
                                       )");
                $stmt2->execute([':user_id' => $delete_user_id, ':main_parent_id' => $main_parent_id]);
                if ($stmt2->rowCount() > 0) {
                    $message = "Child removed successfully.";
                } else {
                    $message = "Failed to remove user.";
                }
            } else {
                $message = "User removed successfully.";
            }
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
        .dashboard { padding: 20px; max-width: 900px; margin: 0 auto; }
        .children-overview, .management-links, .active-rewards, .redeemed-rewards, .pending-approvals, .completed-goals, .manage-family { margin-top: 20px; }
        .children-overview-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; }
        .child-info-card, .reward-item, .goal-item { background-color: #f5f5f5; padding: 15px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        .child-info-card { display: flex; flex-direction: column; gap: 16px; min-height: 100%; }
        .child-info-header { display: flex; align-items: center; gap: 16px; }
        .child-info-header img { width: 64px; height: 64px; border-radius: 50%; object-fit: cover; border: 2px solid #ececec; }
        .child-info-header-details { display: flex; flex-direction: column; gap: 4px; }
        .child-info-name { font-size: 1.15em; font-weight: 600; margin: 0; color: #333; }
        .child-info-meta { margin: 0; font-size: 0.9em; color: #666; }
        .child-info-body { display: flex; gap: 16px; align-items: flex-start; }
        .child-info-stats { display: flex; flex-direction: column; gap: 12px; min-width: 160px; }
        .child-info-stats .stat { }
        .child-info-stats .stat-label { display: block; font-size: 0.85em; color: #666; }
        .child-info-stats .stat-value { font-size: 1.4em; font-weight: 600; color: #2e7d32; }
        .child-info-stats .stat-subvalue { display: block; font-size: 0.85em; color: #888; margin-top: 2px; }
        .points-progress-wrapper { display: flex; flex-direction: column; align-items: center; gap: 10px; flex: 1; }
        .points-progress-label { font-size: 0.9em; color: #555; text-align: center; }
        .points-progress-container { width: 70px; height: 160px; background: #e0e0e0; border-radius: 35px; display: flex; align-items: flex-end; justify-content: center; position: relative; overflow: hidden; }
        .points-progress-fill { width: 100%; height: 90%; background: linear-gradient(180deg, #81c784, #4caf50); border-radius: 5px; transition: height 1.2s ease-out; }
        .points-progress-target { position: absolute; top: 25px; left: 50%; transform: translateX(-50%); font-size: 1em; font-weight: 700; width: 100%; color: #fff; text-shadow: 0 2px 2px rgba(0,0,0,0.4); opacity: 0.9; }
        .child-info-actions { display: flex; flex-wrap: wrap; gap: 10px; }
        .child-info-actions form { margin: 0; flex-grow: 1; }
         .child-info-actions a { flex-grow: 1; }
         .child-info-actions form button{ width: 100%;}
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
        .routine-analytics { margin-top: 20px; background: #fafafa; border-radius: 8px; padding: 20px; box-shadow: 0 2px 6px rgba(0,0,0,0.08); }
        .routine-analytics h2 { margin-top: 0; }
        .overtime-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 16px; margin-top: 16px; }
        .overtime-card { background: #ffffff; border-radius: 8px; padding: 16px; box-shadow: 0 1px 4px rgba(0,0,0,0.05); }
        .overtime-card h3 { margin-top: 0; font-size: 1.05em; }
        .overtime-table { width: 100%; border-collapse: collapse; margin-top: 16px; font-size: 0.95em; }
        .overtime-table th, .overtime-table td { border: 1px solid #e0e0e0; padding: 8px; text-align: left; }
        .overtime-table th { background: #f0f4f8; font-weight: 600; }
        .overtime-empty { font-style: italic; color: #666; margin-top: 12px; }
        @media (max-width: 768px) {
            .manage-family { padding: 10px; }
            .button { width: 100%; }
            .child-info-header { flex-direction: column; align-items: flex-start; }
            .child-info-header img { width: 56px; height: 56px; }
            .child-info-body { flex-direction: column; }
            .points-progress-container { width: 100%; height: 140px; }
        }
    </style>
    <script>
        // JS for Manage Family Wizard (step-by-step)
        document.addEventListener('DOMContentLoaded', function() {
            const addChildBtn = document.getElementById('add-child-btn');
            const addCaregiverBtn = document.getElementById('add-caregiver-btn');
            const childForm = document.getElementById('child-form');
            const caregiverForm = document.getElementById('caregiver-form');
            const avatarPreview = document.getElementById('avatar-preview');
            const avatarInput = document.getElementById('avatar');

            if (addChildBtn && childForm) {
                addChildBtn.addEventListener('click', () => {
                    childForm.classList.add('active');
                    if (caregiverForm) caregiverForm.classList.remove('active');
                });
            }

            if (addCaregiverBtn && caregiverForm) {
                addCaregiverBtn.addEventListener('click', () => {
                    caregiverForm.classList.add('active');
                    if (childForm) childForm.classList.remove('active');
                });
            }

            if (avatarPreview && avatarInput) {
                const avatarOptions = document.querySelectorAll('.avatar-option');

                avatarOptions.forEach(option => {
                    option.addEventListener('click', () => {
                        avatarOptions.forEach(opt => opt.classList.remove('selected'));
                        option.classList.add('selected');
                        avatarPreview.src = option.dataset.avatar;
                        avatarInput.value = option.dataset.avatar;
                    });
                });

                const avatarUpload = document.getElementById('avatar-upload');
                if (avatarUpload) {
                    avatarUpload.addEventListener('change', function(e) {
                        const file = e.target.files[0];
                        if (file) {
                            const reader = new FileReader();
                            reader.onload = function(evt) {
                                avatarPreview.src = evt.target.result;
                            };
                            reader.readAsDataURL(file);
                        }
                    });
                }
            }

            const verticalBars = document.querySelectorAll('.points-progress-container');
            verticalBars.forEach(bar => {
                const fill = bar.querySelector('.points-progress-fill');
                const target = parseInt(bar.dataset.progress, 10) || 0;
                if (fill) {
                    requestAnimationFrame(() => {
                        fill.style.height = `${Math.min(100, Math.max(0, target))}%`;
                    });
                }
            });
        });
    </script>
</head>
<body>
   <header>
      <h1>Parent Dashboard</h1>
      <p>Welcome, <?php echo htmlspecialchars($_SESSION['name'] ?? $_SESSION['username']); ?> 
         <?php if ($welcome_role_label): ?>
            <span class="role-badge">(<?php echo htmlspecialchars($welcome_role_label); ?>)</span>
         <?php endif; ?>
      </p>
      <a href="goal.php">Goals</a> | <a href="task.php">Tasks</a> | <a href="routine.php">Routines</a> | <a href="profile.php?self=1">Profile</a> | <a href="logout.php">Logout</a>
   </header>
   <main class="dashboard">
      <?php if (isset($message)) echo "<p>$message</p>"; ?>
      <div class="children-overview">
         <h2>Children Overview</h2>
         <?php if (isset($data['children']) && is_array($data['children']) && !empty($data['children'])): ?>
               <div class="children-overview-grid">
               <?php foreach ($data['children'] as $child): ?>
                  <div class="child-info-card">
                     <div class="child-info-header">
                        <img src="<?php echo htmlspecialchars($child['avatar'] ?? 'default-avatar.png'); ?>" alt="Avatar for <?php echo htmlspecialchars($child['child_name']); ?>">
                        <div class="child-info-header-details">
                           <p class="child-info-name"><?php echo htmlspecialchars($child['child_name']); ?></p>
                           <p class="child-info-meta">Age: <?php echo htmlspecialchars($child['age'] ?? 'N/A'); ?></p>
                        </div>
                     </div>
                     <div class="child-info-body">
                        <div class="child-info-stats">
                           <div class="stat">
                              <span class="stat-label">Tasks Assigned</span>
                              <span class="stat-value"><?php echo (int)($child['task_count'] ?? 0); ?></span>
                           </div>
                           <div class="stat">
                              <span class="stat-label">Goals</span>
                              <span class="stat-value"><?php echo (int)($child['goals_assigned'] ?? 0); ?></span>
                              <span class="stat-subvalue">Target: <?php echo (int)($child['goal_target_points'] ?? 0); ?> pts</span>
                           </div>
                           <div class="stat">
                              <span class="stat-label">Rewards Claimed</span>
                              <span class="stat-value"><?php echo (int)($child['rewards_claimed'] ?? 0); ?></span>
                           </div>
                        </div>
                        <div class="points-progress-wrapper">
                           <div class="points-progress-label">Points Earned</div>
                           <div class="points-progress-container" data-progress="<?php echo (int)($child['points_progress_percent'] ?? 0); ?>" aria-label="Points progress for <?php echo htmlspecialchars($child['child_name']); ?>">
                              <div class="points-progress-fill"></div>
                              <span class="points-progress-target"><?php echo (int)($child['points_earned'] ?? 0); ?> pts</span>
                           </div>
                        </div>
                     </div>
                     <div class="child-info-actions">
                        <?php if (in_array($role_type, ['main_parent', 'secondary_parent'])): ?>
                            <a href="profile.php?user_id=<?php echo $child['child_user_id']; ?>&type=child" class="button">Edit Child</a>
                        <?php endif; ?>
                        <?php if ($role_type === 'main_parent'): ?>
                            <form method="POST">
                                <input type="hidden" name="delete_user_id" value="<?php echo $child['child_user_id']; ?>">
                                <button type="submit" name="delete_user" class="button delete-btn" onclick="return confirm('Remove this child and all their data?')">Remove</button>
                            </form>
                        <?php endif; ?>
                     </div>
                  </div>
               <?php endforeach; ?>
               </div>
         <?php else: ?>
               <p>No children added yet. Add your first child below!</p>
         <?php endif; ?>
      </div>
      <div class="family-members-list">
         <?php // Use precomputed $main_parent_id from top of file ?>
         <h2>Family Members</h2>
         <?php
        $stmt = $db->prepare("SELECT u.id, CONCAT(u.first_name, ' ', u.last_name) AS name, u.username, fl.role_type 
                              FROM users u 
                              JOIN family_links fl ON u.id = fl.linked_user_id 
                              WHERE fl.main_parent_id = :main_parent_id 
                              AND fl.role_type IN ('secondary_parent', 'family_member') 
                              ORDER BY fl.role_type, u.name");
        $stmt->execute([':main_parent_id' => $main_parent_id]);
        $family_members = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($role_type !== 'main_parent') {
            $ownerStmt = $db->prepare("SELECT id, CONCAT(first_name, ' ', last_name) AS name, username FROM users WHERE id = :id");
            $ownerStmt->execute([':id' => $main_parent_id]);
            $mainOwner = $ownerStmt->fetch(PDO::FETCH_ASSOC);
            if ($mainOwner) {
                $mainOwner['role_type'] = 'main_parent';
                array_unshift($family_members, $mainOwner);
            }
        }
         
         if (!empty($family_members)): ?>
             <?php foreach ($family_members as $member): ?>
                 <div class="member-item">
                    <p><?php echo htmlspecialchars($member['name'] ?? $member['username']); ?> 
                        <span class="role-type">(<?php
                            $memberBadge = getUserRoleLabel($member['id']) ?? ($member['role_type'] ?? '');
                            if (!$memberBadge && isset($member['role_type'])) {
                                $memberBadge = ucfirst(str_replace('_', ' ', $member['role_type']));
                            }
                            echo htmlspecialchars($memberBadge);
                        ?>)</span>
                     </p>
                     <?php if (in_array($role_type, ['main_parent', 'secondary_parent']) && ($member['role_type'] ?? '') !== 'main_parent'): ?>
                         <a href="profile.php?edit_user=<?php echo $member['id']; ?>&role_type=<?php echo urlencode($member['role_type']); ?>" class="button edit-btn">Edit</a>
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
         $stmt = $db->prepare("SELECT u.id, CONCAT(u.first_name, ' ', u.last_name) AS name, u.username, fl.role_type 
                               FROM users u 
                               JOIN family_links fl ON u.id = fl.linked_user_id 
                               WHERE fl.main_parent_id = :main_parent_id 
                               AND fl.role_type = 'caregiver' 
                               ORDER BY u.name");
         $stmt->execute([':main_parent_id' => $main_parent_id]);
         $caregivers = $stmt->fetchAll(PDO::FETCH_ASSOC);
         
         if (!empty($caregivers)): ?>
             <?php foreach ($caregivers as $caregiver): ?>
                 <div class="member-item">
                     <p><?php echo htmlspecialchars($caregiver['name'] ?? $caregiver['username']); ?></p>
                     <?php if (in_array($role_type, ['main_parent', 'secondary_parent'])): ?>
                         <a href="profile.php?edit_user=<?php echo $caregiver['id']; ?>&role_type=<?php echo urlencode($caregiver['role_type']); ?>" class="button edit-btn">Edit</a>
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
      <?php if (in_array($role_type, ['main_parent', 'secondary_parent', 'family_member'])): ?>
      <div class="manage-family" id="manage-family">
         <h2>Manage Family</h2>
         <?php if (in_array($role_type, ['main_parent', 'secondary_parent'])): ?>
            <button id="add-child-btn" class="button">Add Child</button>
         <?php endif; ?>
         <button id="add-caregiver-btn" class="button" style="background: #ff9800;">Add New User</button>
         <?php if (in_array($role_type, ['main_parent', 'secondary_parent'])): ?>
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
         <?php endif; ?>
         <div id="caregiver-form" class="family-form">
            <h3>Add Family Member/Caregiver</h3>
            <form method="POST" action="dashboard_parent.php">
               <div class="form-group">
                  <label for="secondary_first_name">First Name:</label>
                  <input type="text" id="secondary_first_name" name="secondary_first_name" required placeholder="Enter first name">
               </div>
               <div class="form-group">
                  <label for="secondary_last_name">Last Name:</label>
                  <input type="text" id="secondary_last_name" name="secondary_last_name" required placeholder="Enter last name">
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
      <?php endif; ?>

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
                        $stmt->execute([':parent_id' => $main_parent_id]);
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
      </div>
      <div class="routine-analytics">
         <h2>Routine Overtime Insights</h2>
         <p>Track where routines run long so you can coach kids on timing and adjust expectations.</p>
         <div class="overtime-grid">
            <div class="overtime-card">
               <h3>Top Overtime by Child</h3>
               <?php $topChild = array_slice($overtimeByChild, 0, 5); ?>
               <?php if (!empty($topChild)): ?>
                   <table class="overtime-table">
                      <thead>
                         <tr>
                            <th>Child</th>
                            <th>Occurrences</th>
                            <th>Total OT (min)</th>
                         </tr>
                      </thead>
                      <tbody>
                         <?php foreach ($topChild as $childRow): ?>
                             <tr>
                                <td><?php echo htmlspecialchars($childRow['child_display_name']); ?></td>
                                <td><?php echo (int) $childRow['occurrences']; ?></td>
                                <td><?php echo round(((int) $childRow['total_overtime_seconds']) / 60, 1); ?></td>
                             </tr>
                         <?php endforeach; ?>
                      </tbody>
                   </table>
               <?php else: ?>
                   <p class="overtime-empty">No overtime data recorded yet.</p>
               <?php endif; ?>
            </div>
            <div class="overtime-card">
               <h3>Routines with Most Overtime</h3>
               <?php $topRoutine = array_slice($overtimeByRoutine, 0, 5); ?>
               <?php if (!empty($topRoutine)): ?>
                   <table class="overtime-table">
                      <thead>
                         <tr>
                            <th>Routine</th>
                            <th>Occurrences</th>
                            <th>Total OT (min)</th>
                         </tr>
                      </thead>
                      <tbody>
                         <?php foreach ($topRoutine as $routineRow): ?>
                             <tr>
                                <td><?php echo htmlspecialchars($routineRow['routine_title']); ?></td>
                                <td><?php echo (int) $routineRow['occurrences']; ?></td>
                                <td><?php echo round(((int) $routineRow['total_overtime_seconds']) / 60, 1); ?></td>
                             </tr>
                         <?php endforeach; ?>
                      </tbody>
                   </table>
               <?php else: ?>
                   <p class="overtime-empty">No recurring overtime yet. Great job!</p>
               <?php endif; ?>
            </div>
         </div>
         <div class="overtime-card" style="margin-top: 20px;">
            <h3>Most Recent Overtime Events</h3>
            <?php if (!empty($routine_overtime_logs)): ?>
                <table class="overtime-table">
                   <thead>
                      <tr>
                         <th>When</th>
                         <th>Child</th>
                         <th>Routine</th>
                         <th>Task</th>
                         <th>Scheduled</th>
                         <th>Actual</th>
                         <th>Overtime</th>
                      </tr>
                   </thead>
                   <tbody>
                      <?php foreach ($routine_overtime_logs as $log): ?>
                          <tr>
                             <td><?php echo htmlspecialchars(date('m/d/Y h:i A', strtotime($log['occurred_at']))); ?></td>
                             <td><?php echo htmlspecialchars($log['child_display_name']); ?></td>
                             <td><?php echo htmlspecialchars($log['routine_title']); ?></td>
                             <td><?php echo htmlspecialchars($log['task_title']); ?></td>
                             <td><?php echo $formatDuration($log['scheduled_seconds']); ?></td>
                             <td><?php echo $formatDuration($log['actual_seconds']); ?></td>
                             <td><?php echo $formatDuration($log['overtime_seconds']); ?></td>
                          </tr>
                      <?php endforeach; ?>
                   </tbody>
                </table>
            <?php else: ?>
                <p class="overtime-empty">No overtime events have been logged yet.</p>
            <?php endif; ?>
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
                    <?php if (!empty($approval['creator_display_name'])): ?>
                        <p>Created by: <?php echo htmlspecialchars($approval['creator_display_name']); ?></p>
                    <?php endif; ?>
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
         $stmt = $db->prepare("SELECT 
                              g.id, 
                              g.title, 
                              g.target_points, 
                              g.start_date, 
                              g.end_date, 
                              g.completed_at, 
                              u.username as child_username,
                              COALESCE(
                                 NULLIF(TRIM(CONCAT(COALESCE(creator.first_name, ''), ' ', COALESCE(creator.last_name, ''))), ''),
                                 NULLIF(creator.name, ''),
                                 creator.username,
                                 'Unknown'
                              ) AS creator_display_name
                              FROM goals g 
                              JOIN users u ON g.child_user_id = u.id 
                              LEFT JOIN users creator ON g.created_by = creator.id
                              WHERE g.parent_user_id = :parent_id AND g.status = 'completed'");
         $stmt->execute([':parent_id' => $parent_id]);
         $all_completed_goals = $stmt->fetchAll(PDO::FETCH_ASSOC);
         ?>
         <?php if (!empty($all_completed_goals)): ?>
              <?php foreach ($all_completed_goals as $goal): ?>
                 <div class="goal-item">
                    <p>Goal: <?php echo htmlspecialchars($goal['title']); ?> (Target: <?php echo htmlspecialchars($goal['target_points']); ?> points)</p>
                    <p>Child: <?php echo htmlspecialchars($goal['child_username']); ?></p>
                    <?php if (!empty($goal['creator_display_name'])): ?>
                        <p>Created by: <?php echo htmlspecialchars($goal['creator_display_name']); ?></p>
                    <?php endif; ?>
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
         $stmt = $db->prepare("SELECT 
                              g.id, 
                              g.title, 
                              g.target_points, 
                              g.start_date, 
                              g.end_date, 
                              g.rejected_at, 
                              g.rejection_comment, 
                              u.username as child_username, 
                              r.title as reward_title,
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
                    <?php if (!empty($goal['creator_display_name'])): ?>
                        <p>Created by: <?php echo htmlspecialchars($goal['creator_display_name']); ?></p>
                    <?php endif; ?>
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
      <p>Child Task and Chores App - Ver 3.10.14</p>
   </footer>
</body>
</html>
