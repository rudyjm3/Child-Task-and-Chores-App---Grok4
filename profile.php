<?php
// profile.php - User profile management
// Purpose: Edit profile details based on role (child: avatar/password; parent: family)
// Version: 3.15.0

require_once __DIR__ . '/includes/functions.php';

session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// Prevent any client/proxy caching so the profile view always reflects the current request context
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$role = $_SESSION['role'];
$current_user_id = $_SESSION['user_id'];
// Resolve precise role type for permission checks
$current_role_type = getEffectiveRole($current_user_id);

// Determine the family root (main account owner) for relationship checks
$family_root_id = $current_user_id;
if ($current_role_type !== 'main_parent') {
    $stmt = $db->prepare("SELECT main_parent_id FROM family_links WHERE linked_user_id = :uid LIMIT 1");
    $stmt->execute([':uid' => $current_user_id]);
    $root = $stmt->fetchColumn();
    if ($root) {
        $family_root_id = $root;
    }
}

// Work out requested profile target
$requested_user_id = null;
$requested_context = null; // 'child' or 'adult'

if (isset($_GET['self'])) {
    $requested_user_id = $current_user_id;
    $requested_context = ($current_role_type === 'child') ? 'child' : 'adult';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $requested_user_id = filter_input(INPUT_POST, 'edit_user_id', FILTER_VALIDATE_INT, ['flags' => FILTER_NULL_ON_FAILURE]);
    $requested_context = filter_input(INPUT_POST, 'edit_type', FILTER_SANITIZE_STRING);
} else {
    if (isset($_GET['type'], $_GET['user_id']) && $_GET['type'] === 'child') {
        $requested_user_id = filter_input(INPUT_GET, 'user_id', FILTER_VALIDATE_INT);
        $requested_context = 'child';
    } elseif (isset($_GET['edit_user'])) {
        $requested_user_id = filter_input(INPUT_GET, 'edit_user', FILTER_VALIDATE_INT);
        $requested_context = filter_input(INPUT_GET, 'role_type', FILTER_SANITIZE_STRING);
    }
}

$requested_context = $requested_context ? strtolower($requested_context) : null;

// Default target is the logged-in user
$edit_user_id = $current_user_id;
$edit_type = ($current_role_type === 'child') ? 'child' : 'adult';

// Helper closures for validation
$isChildOfParent = function($child_id) use ($db, $family_root_id) {
    $stmt = $db->prepare("SELECT 1 FROM child_profiles WHERE parent_user_id = :parent_id AND child_user_id = :child_id AND deleted_at IS NULL LIMIT 1");
    $stmt->execute([':parent_id' => $family_root_id, ':child_id' => $child_id]);
    return (bool)$stmt->fetchColumn();
  };

$isLinkedAdult = function($linked_id) use ($db, $family_root_id) {
    $stmt = $db->prepare("SELECT role_type FROM family_links WHERE main_parent_id = :parent_id AND linked_user_id = :linked_id LIMIT 1");
    $stmt->execute([':parent_id' => $family_root_id, ':linked_id' => $linked_id]);
    return $stmt->fetchColumn() ?: null;
};

// Determine final target
if ($requested_user_id && $requested_user_id !== $current_user_id) {
    if (in_array($current_role_type, ['main_parent', 'secondary_parent'])) {
        $context = $requested_context;
        if ($context === 'child' || !$context) {
            if ($isChildOfParent($requested_user_id)) {
                $edit_user_id = $requested_user_id;
                $edit_type = 'child';
            } elseif (!$context) {
                // If context missing but user is actually a child, infer it
                $requested_role = getUserRole($requested_user_id);
                if ($requested_role === 'child' && $isChildOfParent($requested_user_id)) {
                    $edit_user_id = $requested_user_id;
                    $edit_type = 'child';
                }
            }
        }
        if ($edit_user_id === $current_user_id) {
            // Not resolved as child; try linked adults
            $linked_role = $isLinkedAdult($requested_user_id);
            if ($linked_role) {
                $edit_user_id = $requested_user_id;
                $edit_type = ($linked_role === 'child') ? 'child' : 'adult';
            }
        }
    }
} else {
    // Self-view - ensure context is accurate
    if ($requested_context === 'child' && $current_role_type === 'child') {
        $edit_type = 'child';
    } else {
        $edit_type = ($current_role_type === 'child') ? 'child' : 'adult';
    }
}

$edit_type = ($edit_type === 'child') ? 'child' : 'adult';

$user_id = $edit_user_id;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_password'])) {
        if ($user_id != $_SESSION['user_id'] && !in_array($current_role_type, ['main_parent', 'secondary_parent'])) {
            $message = "Access denied.";
        } else {
            $new_password = filter_input(INPUT_POST, 'new_password', FILTER_SANITIZE_STRING);
            if (updateUserPassword($user_id, $new_password)) {
                $message = "Password updated successfully!";
                if ($user_id != $_SESSION['user_id']) {
                    // Redirect back to the specific profile after managing someone else
                    if ($edit_type === 'child') {
                        $redirect = 'profile.php?user_id=' . $user_id . '&type=child';
                    } else {
                        $linked_role = getFamilyLinkRole($user_id);
                        $redirect = 'profile.php?edit_user=' . $user_id;
                        if ($linked_role) {
                            $redirect .= '&role_type=' . urlencode($linked_role);
                        }
                    }
                    header('Location: ' . $redirect);
                    exit;
                }
            } else {
                $message = "Failed to update password.";
            }
        }
    } elseif (isset($_POST['update_child_profile'])) {
        if ($user_id != $_SESSION['user_id'] && !in_array($current_role_type, ['main_parent', 'secondary_parent'])) {
            $message = "Access denied.";
        } else {
            $first_name = filter_input(INPUT_POST, 'first_name', FILTER_SANITIZE_STRING);
            $last_name = filter_input(INPUT_POST, 'last_name', FILTER_SANITIZE_STRING);
            $birthday = filter_input(INPUT_POST, 'birthday', FILTER_SANITIZE_STRING);
            $avatar = filter_input(INPUT_POST, 'avatar', FILTER_SANITIZE_STRING);
            $child_gender = filter_input(INPUT_POST, 'child_gender', FILTER_SANITIZE_STRING);
            $allowed_genders = ['male', 'female', 'nonbinary', 'prefer_not_to_say'];
            if (!in_array($child_gender, $allowed_genders, true)) {
                $child_gender = null;
            }
            // Handle upload (for parent editing child or child self-upload)
            $upload_path = $avatar; // Default to selected avatar
            if (isset($_FILES['avatar_upload']) && $_FILES['avatar_upload']['error'] == 0) {
                $file_size = $_FILES['avatar_upload']['size'];
                $file_type = strtolower(pathinfo($_FILES['avatar_upload']['name'], PATHINFO_EXTENSION));
                if ($file_size > 3 * 1024 * 1024 || !in_array($file_type, ['jpg', 'jpeg', 'png'])) {
                    $message = "Upload failed: File too large (>3MB) or invalid type (JPG/PNG only).";
                } else {
                    $upload_dir = __DIR__ . '/uploads/avatars/';
                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0755, true);
                    }
                    $file_ext = strtolower(pathinfo($_FILES['avatar_upload']['name'], PATHINFO_EXTENSION));
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
                    } else {
                        $message = "Upload failed; using selected avatar.";
                    }
                }
            }
            if (updateChildProfile($user_id, $first_name, $last_name, $birthday, $upload_path, $child_gender)) {
                $message = "Profile updated successfully!";
                // Only update session display name if the logged-in user edited their own profile
                if ($user_id == $_SESSION['user_id']) {
                    $_SESSION['name'] = getDisplayName($_SESSION['user_id']);
                }
                // PRG: Redirect to avoid resubmission and to reset context if editing another profile
                if ($user_id != $_SESSION['user_id']) {
                    header('Location: profile.php?user_id=' . $user_id . '&type=child');
                    exit;
                }
            } else {
                $message = "Failed to update profile.";
            }
        }
    } elseif (isset($_POST['update_parent_profile'])) {
        if ($user_id != $_SESSION['user_id'] && !in_array($current_role_type, ['main_parent', 'secondary_parent'])) {
            $message = "Access denied.";
        } else {
            $first_name = filter_input(INPUT_POST, 'first_name', FILTER_SANITIZE_STRING);
            $last_name = filter_input(INPUT_POST, 'last_name', FILTER_SANITIZE_STRING);
            $role_badge_label = filter_input(INPUT_POST, 'role_badge_label', FILTER_SANITIZE_STRING);
            $use_role_badge_label = !empty($_POST['use_role_badge_label']) ? 1 : 0;
            $target_effective_role_for_update = getEffectiveRole($user_id);

            if (in_array($target_effective_role_for_update, ['main_parent', 'secondary_parent'], true)) {
                $gender = filter_input(INPUT_POST, 'gender', FILTER_SANITIZE_STRING);
                if (!in_array($gender, ['male', 'female'], true)) {
                    $gender = null;
                }
                $allowed_parent_titles = ['mother', 'father'];
                $parent_title = filter_input(INPUT_POST, 'parent_title', FILTER_SANITIZE_STRING);
                if (!in_array($parent_title, $allowed_parent_titles, true)) {
                    $parent_title = null;
                }

                $parent_conflict = false;
                if ($parent_title) {
                    $family_owner_id = $family_root_id;
                    if ($target_effective_role_for_update === 'main_parent') {
                        $family_owner_id = $user_id;
                    }
                    $conflictStmt = $db->prepare("SELECT u.id 
                                                 FROM users u
                                                 LEFT JOIN family_links fl ON u.id = fl.linked_user_id
                                                 WHERE (
                                                         u.id = :main_parent_id
                                                         OR (fl.main_parent_id = :main_parent_id AND fl.role_type = 'secondary_parent')
                                                       )
                                                   AND u.parent_title = :parent_title
                                                   AND u.id != :current_user
                                                 LIMIT 1");
                    $conflictStmt->execute([
                        ':main_parent_id' => $family_owner_id,
                        ':parent_title' => $parent_title,
                        ':current_user' => $user_id
                    ]);
                    if ($conflictStmt->fetchColumn()) {
                        $parent_conflict = true;
                        $message = ucfirst($parent_title) . " has already been assigned to another parent in this family. Please choose a different option or leave it blank.";
                    }
                }

                if (!$parent_conflict) {
                    $stmt = $db->prepare("UPDATE users SET first_name = :first_name, last_name = :last_name, gender = :gender, parent_title = :parent_title, role_badge_label = :role_badge_label, use_role_badge_label = :use_role_badge_label WHERE id = :id");
                    if ($stmt->execute([
                        ':first_name' => $first_name,
                        ':last_name' => $last_name,
                        ':gender' => $gender ?: null,
                        ':parent_title' => $parent_title,
                        ':role_badge_label' => $role_badge_label,
                        ':use_role_badge_label' => $use_role_badge_label,
                        ':id' => $user_id
                    ])) {
                        $message = "Profile updated successfully!";
                        if ($user_id == $_SESSION['user_id']) {
                            $_SESSION['name'] = getDisplayName($_SESSION['user_id']);
                        }
                        if ($user_id != $_SESSION['user_id']) {
                            $linked_role = getFamilyLinkRole($user_id);
                            $redirect = 'profile.php?edit_user=' . $user_id;
                            if ($linked_role) {
                                $redirect .= '&role_type=' . urlencode($linked_role);
                            }
                            header('Location: ' . $redirect);
                            exit;
                        }
                    } else {
                        $message = "Failed to update profile.";
                    }
                }
            } else {
                $stmt = $db->prepare("UPDATE users SET first_name = :first_name, last_name = :last_name, parent_title = NULL, role_badge_label = :role_badge_label, use_role_badge_label = :use_role_badge_label WHERE id = :id");
                if ($stmt->execute([
                    ':first_name' => $first_name,
                    ':last_name' => $last_name,
                    ':role_badge_label' => $role_badge_label,
                    ':use_role_badge_label' => $use_role_badge_label,
                    ':id' => $user_id
                ])) {
                    $message = "Profile updated successfully!";
                    if ($user_id == $_SESSION['user_id']) {
                        $_SESSION['name'] = getDisplayName($_SESSION['user_id']);
                    }
                    if ($user_id != $_SESSION['user_id']) {
                        $linked_role = getFamilyLinkRole($user_id);
                        $redirect = 'profile.php?edit_user=' . $user_id;
                        if ($linked_role) {
                            $redirect .= '&role_type=' . urlencode($linked_role);
                        }
                        header('Location: ' . $redirect);
                        exit;
                    }
                } else {
                    $message = "Failed to update profile.";
                }
            }
        }
    }
}

// Fetch user data
$stmt = $db->prepare("SELECT * FROM users WHERE id = :id");
$stmt->execute([':id' => $user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if ($role === 'child' || $edit_type === 'child') {
    $profile_stmt = $db->prepare("SELECT * FROM child_profiles WHERE child_user_id = :id AND deleted_at IS NULL");
    $profile_stmt->execute([':id' => $user_id]);
    $profile = $profile_stmt->fetch(PDO::FETCH_ASSOC);
    if ($profile) {
        $profile['age'] = calculateAge($profile['birthday'] ?? null);
    }
}

$target_effective_role = getEffectiveRole($user_id);
$target_parent_title = $user['parent_title'] ?? null;
$target_role_label = getUserRoleLabel($user_id);
$display_name = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
if ($display_name === '') {
    $display_name = $user['username'];
}
$child_display_name = $profile['child_name'] ?? $display_name;

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
    <title>Profile - Child Task and Chore App</title>
    <link rel="stylesheet" href="css/main.css?v=3.15.0">
    <style>
        .profile { padding: 20px; max-width: 600px; margin: 0 auto; text-align: center; }
        .profile-form { background: #f5f5f5; padding: 20px; border-radius: 8px; }
        .profile-form form { display: grid; gap: 14px; }
        .profile-form .form-group { text-align: left; margin: 0; }
        .profile-form input,
        .profile-form select,
        .profile-form textarea { padding: 10px; }
        .profile-form button { margin-top: 6px; }
        .avatar-preview { width: 100px; height: 100px; border-radius: 50%; margin: 10px; }
        .button { padding: 10px 20px; background-color: #4caf50; color: white; border: none; border-radius: 5px; cursor: pointer; }
        .avatar-options { display: flex; flex-wrap: wrap; justify-content: center; gap: 10px; margin-bottom: 15px;}
        .avatar-option { width: 60px; height: 60px; border-radius: 50%; cursor: pointer; border: 2px solid #ddd; }
        .avatar-option.selected { border-color: #4caf50; }
        .role-badge {
            background: #4caf50;
            color: #fff;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 0.9em;
            margin-left: 8px;
            display: inline-block;
        }
        .child-profile { 
            background: linear-gradient(135deg, #e3f2fd, #f3e5f5);
            border-radius: 10px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .parent-profile { 
            background: #f9f9f9;
            border-radius: 10px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .editing-child { 
            border: 2px solid #ff9800;
            position: relative;
        }
        .editing-child::before {
            content: 'Editing Child Profile';
            position: absolute;
            top: -12px;
            left: 50%;
            transform: translateX(-50%);
            background: #ff9800;
            color: white;
            padding: 2px 10px;
            border-radius: 4px;
            font-size: 0.8em;
        }
        .profile-name {
            font-size: 1.2em;
            font-weight: 500;
            margin: 15px 0;
        }
        @media (max-width: 768px) { 
            .avatar-options { gap: 5px; } 
            .avatar-option { width: 50px; height: 50px; } 
            .profile-form { padding: 15px; }
        }
        .section-divider { border-top: 1px solid #e0e0e0; margin: 18px 0 10px; padding-top: 14px; }
    </style>
    <script>
        // JS for avatar selection
        document.addEventListener('DOMContentLoaded', function() {
            const avatarOptions = document.querySelectorAll('.avatar-option');
            avatarOptions.forEach(option => {
                option.addEventListener('click', () => {
                    avatarOptions.forEach(opt => opt.classList.remove('selected'));
                    option.classList.add('selected');
                    document.getElementById('avatar').value = option.dataset.avatar;
                    document.getElementById('avatar-preview').src = option.dataset.avatar;
                });
            });

            const genderSelect = document.getElementById('gender');
            const parentTitleSelect = document.getElementById('parent_title');
            if (genderSelect && parentTitleSelect) {
                genderSelect.addEventListener('change', function() {
                    if (this.value === 'male') {
                        parentTitleSelect.value = 'father';
                    } else if (this.value === 'female') {
                        parentTitleSelect.value = 'mother';
                    } else {
                        parentTitleSelect.value = '';
                    }
                });
            }
        });
    </script>
</head>
<body<?php echo !empty($bodyClasses) ? ' class="' . implode(' ', $bodyClasses) . '"' : ''; ?>>
    <div class="profile">
        <h1>Profile</h1>
        <?php if (isset($message)) echo "<p>$message</p>"; ?>
        <?php if ($role === 'child' || $edit_type === 'child'): ?>
            <div class="profile-form child-profile <?php if ($edit_type === 'child') echo 'editing-child'; ?>">
                <h2>
                    <?php if ($edit_type === 'child') echo 'Edit Child: '; ?>
                    <?php echo htmlspecialchars($child_display_name); ?>'s Profile
                    <?php if ($target_role_label): ?>
                        <span class="role-badge"><?php echo htmlspecialchars($target_role_label); ?></span>
                    <?php endif; ?>
                </h2>
                <img id="avatar-preview" src="<?php echo htmlspecialchars($profile['avatar'] ?? 'default-avatar.png'); ?>" alt="Avatar" class="avatar-preview">
                <form method="POST" action="profile.php" enctype="multipart/form-data">
                    <input type="hidden" name="edit_user_id" value="<?php echo (int)$user_id; ?>">
                    <input type="hidden" name="edit_type" value="child">
                    <div class="form-group">
                        <label for="first_name">First Name:</label>
                        <input type="text" id="first_name" name="first_name" value="<?php echo htmlspecialchars($user['first_name'] ?? ''); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="last_name">Last Name:</label>
                        <input type="text" id="last_name" name="last_name" value="<?php echo htmlspecialchars($user['last_name'] ?? ''); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="role_badge_label">Role Badge (optional):</label>
                        <input type="text" id="role_badge_label" name="role_badge_label" value="<?php echo htmlspecialchars($user['role_badge_label'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label>
                            <input type="checkbox" name="use_role_badge_label" value="1" <?php echo !empty($user['use_role_badge_label']) ? 'checked' : ''; ?>>
                            Use custom role badge
                        </label>
                        <small style="display:block;margin-top:5px;color:#555;">This label appears wherever your role badge is shown.</small>
                    </div>
                    <div class="form-group">
                        <label for="role_badge_label">Role Badge (optional):</label>
                        <input type="text" id="role_badge_label" name="role_badge_label" value="<?php echo htmlspecialchars($user['role_badge_label'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label>
                            <input type="checkbox" name="use_role_badge_label" value="1" <?php echo !empty($user['use_role_badge_label']) ? 'checked' : ''; ?>>
                            Use custom role badge
                        </label>
                        <small style="display:block;margin-top:5px;color:#555;">This label appears wherever your role badge is shown.</small>
                    </div>
                    <div class="form-group">
                        <label for="birthday">Birthday:</label>
                        <input type="date" id="birthday" name="birthday" value="<?php echo htmlspecialchars($profile['birthday'] ?? ''); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="child_gender">Gender:</label>
                        <select id="child_gender" name="child_gender" required>
                            <option value="">Select...</option>
                            <option value="male" <?php if (($user['gender'] ?? '') === 'male') echo 'selected'; ?>>Male</option>
                            <option value="female" <?php if (($user['gender'] ?? '') === 'female') echo 'selected'; ?>>Female</option>
                            <option value="nonbinary" <?php if (($user['gender'] ?? '') === 'nonbinary') echo 'selected'; ?>>Non-binary</option>
                            <option value="prefer_not_to_say" <?php if (($user['gender'] ?? '') === 'prefer_not_to_say') echo 'selected'; ?>>Prefer not to say</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Age:</label>
                        <span class="readonly-value"><?php echo ($profile['age'] ?? null) !== null ? htmlspecialchars($profile['age']) : 'N/A'; ?></span>
                    </div>
                    <div class="form-group">
                        <label>Avatar:</label>
                        <div class="avatar-options">
                           <img class="avatar-option <?php if (($profile['avatar'] ?? '') == 'images/avatar_images/default-avatar.png') echo 'selected'; ?>" data-avatar="images/avatar_images/default-avatar.png" src="images/avatar_images/default-avatar.png" alt="Avatar Default">
                            <img class="avatar-option <?php if (($profile['avatar'] ?? '') == 'images/avatar_images/boy-1.png') echo 'selected'; ?>" data-avatar="images/avatar_images/boy-1.png" src="images/avatar_images/boy-1.png" alt="Avatar 1">
                            <img class="avatar-option <?php if (($profile['avatar'] ?? '') == 'images/avatar_images/girl-1.png') echo 'selected'; ?>" data-avatar="images/avatar_images/girl-1.png" src="images/avatar_images/girl-1.png" alt="Avatar 2">
                            <img class="avatar-option <?php if (($profile['avatar'] ?? '') == 'images/avatar_images/xmas-elf-boy.png') echo 'selected'; ?>" data-avatar="images/avatar_images/xmas-elf-boy.png" src="images/avatar_images/xmas-elf-boy.png" alt="Avatar 3">
                            <!-- Add more -->
                        </div>
                        <input type="file" name="avatar_upload" accept="image/*">
                        <input type="hidden" id="avatar" name="avatar" value="<?php echo htmlspecialchars($profile['avatar'] ?? ''); ?>">
                    </div>
                    <button type="submit" name="update_child_profile" class="button">Update Profile</button>
                </form>
                <?php if ($role === 'child'): ?>
                    <div class="section-divider"></div>
                    <h3>Change Password</h3>
                    <form method="POST" action="profile.php">
                        <input type="hidden" name="edit_user_id" value="<?php echo (int)$_SESSION['user_id']; ?>">
                        <input type="hidden" name="edit_type" value="child">
                        <div class="form-group">
                            <label for="new_password">New Password:</label>
                            <input type="password" id="new_password" name="new_password" required>
                        </div>
                        <button type="submit" name="update_password" class="button">Update Password</button>
                    </form>
                <?php endif; ?>
            </div>
        <?php elseif (in_array($target_effective_role, ['main_parent', 'secondary_parent'], true)): ?>
            <div class="profile-form parent-profile">
                <h2>
                    <?php echo ($user_id == $_SESSION['user_id']) ? 'Your Profile' : 'Edit Parent Profile'; ?>
                </h2>
                <p class="profile-name">
                    <?php echo htmlspecialchars($display_name); ?>
                    <?php if ($target_role_label): ?>
                        <span class="role-badge"><?php echo htmlspecialchars($target_role_label); ?></span>
                    <?php endif; ?>
                </p>
                <form method="POST" action="profile.php">
                    <input type="hidden" name="edit_user_id" value="<?php echo (int)$user_id; ?>">
                    <input type="hidden" name="edit_type" value="adult">
                    <div class="form-group">
                        <label for="first_name">First Name:</label>
                        <input type="text" id="first_name" name="first_name" value="<?php echo htmlspecialchars($user['first_name'] ?? ''); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="last_name">Last Name:</label>
                        <input type="text" id="last_name" name="last_name" value="<?php echo htmlspecialchars($user['last_name'] ?? ''); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="role_badge_label">Role Badge (optional):</label>
                        <input type="text" id="role_badge_label" name="role_badge_label" value="<?php echo htmlspecialchars($user['role_badge_label'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label>
                            <input type="checkbox" name="use_role_badge_label" value="1" <?php echo !empty($user['use_role_badge_label']) ? 'checked' : ''; ?>>
                            Use custom role badge
                        </label>
                        <small style="display:block;margin-top:5px;color:#555;">This label appears wherever your role badge is shown.</small>
                    </div>
                    <div class="form-group">
                        <label for="gender">Gender:</label>
                        <select id="gender" name="gender">
                            <option value="">Select</option>
                            <option value="male" <?php if (($user['gender'] ?? '') === 'male') echo 'selected'; ?>>Male</option>
                            <option value="female" <?php if (($user['gender'] ?? '') === 'female') echo 'selected'; ?>>Female</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="parent_title">Parent Role (optional):</label>
                        <select id="parent_title" name="parent_title">
                            <option value="">Not specified</option>
                            <option value="mother" <?php if ($target_parent_title === 'mother') echo 'selected'; ?>>Mother</option>
                            <option value="father" <?php if ($target_parent_title === 'father') echo 'selected'; ?>>Father</option>
                        </select>
                        <small style="display:block;margin-top:5px;color:#555;">Each family can assign Mother and Father once. Leave blank if not applicable.</small>
                    </div>
                    <button type="submit" name="update_parent_profile" class="button">Update Profile</button>
                </form>
                <div class="section-divider"></div>
                <h3>Change Password</h3>
                <form method="POST" action="profile.php">
                    <input type="hidden" name="edit_user_id" value="<?php echo (int)$user_id; ?>">
                    <input type="hidden" name="edit_type" value="adult">
                    <div class="form-group">
                        <label for="new_password">New Password:</label>
                        <input type="password" id="new_password" name="new_password" required>
                    </div>
                    <button type="submit" name="update_password" class="button">Update Password</button>
                </form>
                <?php if ($current_role_type === 'main_parent'): ?>
                    <h3>Manage Family</h3>
                    <a href="dashboard_parent.php#manage-family" class="button">Go to Manage Family</a>
                <?php endif; ?>
            </div>
        <?php elseif (in_array($target_effective_role, ['family_member', 'caregiver'], true)): ?>
            <div class="profile-form parent-profile">
                <h2>
                    <?php
                        if ($user_id == $_SESSION['user_id']) {
                            echo 'Your Profile';
                        } else {
                            echo 'Edit ' . htmlspecialchars($target_role_label ?? ucfirst($target_effective_role));
                        }
                    ?>
                </h2>
                <p class="profile-name">
                    <?php echo htmlspecialchars($display_name); ?>
                    <?php if ($target_role_label): ?>
                        <span class="role-badge"><?php echo htmlspecialchars($target_role_label); ?></span>
                    <?php endif; ?>
                </p>
                <form method="POST" action="profile.php">
                    <input type="hidden" name="edit_user_id" value="<?php echo (int)$user_id; ?>">
                    <input type="hidden" name="edit_type" value="adult">
                    <div class="form-group">
                        <label for="first_name">First Name:</label>
                        <input type="text" id="first_name" name="first_name" value="<?php echo htmlspecialchars($user['first_name'] ?? ''); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="last_name">Last Name:</label>
                        <input type="text" id="last_name" name="last_name" value="<?php echo htmlspecialchars($user['last_name'] ?? ''); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="role_badge_label">Role Badge (optional):</label>
                        <input type="text" id="role_badge_label" name="role_badge_label" value="<?php echo htmlspecialchars($user['role_badge_label'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label>
                            <input type="checkbox" name="use_role_badge_label" value="1" <?php echo !empty($user['use_role_badge_label']) ? 'checked' : ''; ?>>
                            Use custom role badge
                        </label>
                        <small style="display:block;margin-top:5px;color:#555;">This label appears wherever your role badge is shown.</small>
                    </div>
                    <button type="submit" name="update_parent_profile" class="button">Update Profile</button>
                </form>
                <div class="section-divider"></div>
                <h3>Change Password</h3>
                <form method="POST" action="profile.php">
                    <input type="hidden" name="edit_user_id" value="<?php echo (int)$user_id; ?>">
                    <input type="hidden" name="edit_type" value="adult">
                    <div class="form-group">
                        <label for="new_password">New Password:</label>
                        <input type="password" id="new_password" name="new_password" required>
                    </div>
                    <button type="submit" name="update_password" class="button">Update Password</button>
                </form>
            </div>
        <?php endif; ?>
        <a href="dashboard_<?php echo $role; ?>.php" class="button">Back to Dashboard</a>
    </div>
</body>
</html>

