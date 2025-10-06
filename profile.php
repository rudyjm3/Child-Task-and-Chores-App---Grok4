<?php
// profile.php - User profile management
// Purpose: Edit profile details based on role (child: avatar/password; parent: family)
// Version: 3.5.2 (Fixed child edit upload: enctype, validation, your avatar filenames)

require_once __DIR__ . '/includes/functions.php';

session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$role = $_SESSION['role'];

// Handle GET params for editing child (parent only)
$edit_user_id = $_SESSION['user_id'];
$edit_type = null;
if ($role === 'parent' && isset($_GET['type']) && $_GET['type'] === 'child' && isset($_GET['user_id'])) {
    $edit_user_id = filter_input(INPUT_GET, 'user_id', FILTER_VALIDATE_INT);
    $edit_type = 'child';
    // Verify linkage (security)
    $link_stmt = $db->prepare("SELECT 1 FROM child_profiles WHERE child_user_id = :child_id AND parent_user_id = :parent_id");
    $link_stmt->execute([':child_id' => $edit_user_id, ':parent_id' => $_SESSION['user_id']]);
    if (!$link_stmt->fetchColumn()) {
        $message = "Access denied: Not your child.";
        $edit_user_id = $_SESSION['user_id']; // Fallback
        $edit_type = null;
    }
}

$user_id = $edit_user_id;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_password'])) {
        $new_password = filter_input(INPUT_POST, 'new_password', FILTER_SANITIZE_STRING);
        if (updateUserPassword($user_id, $new_password)) {
            $message = "Password updated successfully!";
        } else {
            $message = "Failed to update password.";
        }
    } elseif (isset($_POST['update_child_profile'])) {
        $child_name = filter_input(INPUT_POST, 'child_name', FILTER_SANITIZE_STRING);
        $age = filter_input(INPUT_POST, 'age', FILTER_VALIDATE_INT);
        $avatar = filter_input(INPUT_POST, 'avatar', FILTER_SANITIZE_STRING);
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
                $file_ext = $file_type;
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
        if (updateChildProfile($user_id, $child_name, $age, $upload_path)) {
            $message = "Profile updated successfully!";
            $_SESSION['name'] = $child_name; // Update session if self
        } else {
            $message = "Failed to update profile.";
        }
    } elseif (isset($_POST['update_parent_profile'])) {
        $name = filter_input(INPUT_POST, 'name', FILTER_SANITIZE_STRING);
        $gender = filter_input(INPUT_POST, 'gender', FILTER_SANITIZE_STRING);
        $stmt = $db->prepare("UPDATE users SET name = :name, gender = :gender WHERE id = :id");
        if ($stmt->execute([':name' => $name, ':gender' => $gender, ':id' => $user_id])) {
            $message = "Profile updated successfully!";
            $_SESSION['name'] = $name;
        } else {
            $message = "Failed to update profile.";
        }
    }
}

// Fetch user data
$stmt = $db->prepare("SELECT * FROM users WHERE id = :id");
$stmt->execute([':id' => $user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if ($role === 'child' || $edit_type === 'child') {
    $profile_stmt = $db->prepare("SELECT * FROM child_profiles WHERE child_user_id = :id");
    $profile_stmt->execute([':id' => $user_id]);
    $profile = $profile_stmt->fetch(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile - Child Task and Chore App</title>
    <link rel="stylesheet" href="css/main.css">
    <style>
        .profile { padding: 20px; max-width: 600px; margin: 0 auto; text-align: center; }
        .profile-form { background: #f5f5f5; padding: 20px; border-radius: 8px; }
        .avatar-preview { width: 100px; height: 100px; border-radius: 50%; margin: 10px; }
        .button { padding: 10px 20px; background-color: #4caf50; color: white; border: none; border-radius: 5px; cursor: pointer; }
        .avatar-options { display: flex; flex-wrap: wrap; justify-content: center; gap: 10px; }
        .avatar-option { width: 60px; height: 60px; border-radius: 50%; cursor: pointer; border: 2px solid #ddd; }
        .avatar-option.selected { border-color: #4caf50; }
        .mother-badge { background: #e91e63; color: white; padding: 2px 6px; border-radius: 3px; font-size: 0.8em; }
        .father-badge { background: #2196f3; color: white; padding: 2px 6px; border-radius: 3px; font-size: 0.8em; }
        .child-profile { background: linear-gradient(135deg, #e3f2fd, #f3e5f5); }
        .parent-profile { background: #f9f9f9; }
        .editing-child { border: 2px solid #ff9800; }
        @media (max-width: 768px) { .avatar-options { gap: 5px; } .avatar-option { width: 50px; height: 50px; } }
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
        });
    </script>
</head>
<body>
    <div class="profile">
        <h1>Profile</h1>
        <?php if (isset($message)) echo "<p>$message</p>"; ?>
        <?php if ($role === 'child' || $edit_type === 'child'): ?>
            <div class="profile-form child-profile <?php if ($edit_type === 'child') echo 'editing-child'; ?>">
                <h2><?php if ($edit_type === 'child') echo 'Edit Child: '; ?><?php echo htmlspecialchars($profile['child_name'] ?? $user['name'] ?? $user['username']); ?>'s Profile</h2>
                <img id="avatar-preview" src="<?php echo htmlspecialchars($profile['avatar'] ?? 'default-avatar.png'); ?>" alt="Avatar" class="avatar-preview">
                <form method="POST" action="profile.php<?php if ($edit_type === 'child') echo '?user_id=' . $user_id . '&type=child'; ?>" enctype="multipart/form-data">
                    <div class="form-group">
                        <label for="child_name">Name:</label>
                        <input type="text" id="child_name" name="child_name" value="<?php echo htmlspecialchars($profile['child_name'] ?? $user['name'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label for="age">Age:</label>
                        <input type="number" id="age" name="age" min="1" max="18" value="<?php echo htmlspecialchars($profile['age'] ?? ''); ?>">
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
                    <h3>Change Password</h3>
                    <form method="POST" action="profile.php">
                        <div class="form-group">
                            <label for="new_password">New Password:</label>
                            <input type="password" id="new_password" name="new_password" required>
                        </div>
                        <button type="submit" name="update_password" class="button">Update Password</button>
                    </form>
                <?php endif; ?>
            </div>
        <?php elseif ($role === 'parent'): ?>
            <div class="profile-form parent-profile">
                <h2>Your Profile</h2>
                <p>Name: <?php echo htmlspecialchars($user['name'] ?? $user['username']); ?> <?php if ($user['gender'] == 'female') echo '<span class="mother-badge">Mother</span>'; elseif ($user['gender'] == 'male') echo '<span class="father-badge">Father</span>'; ?></p>
                <form method="POST" action="profile.php">
                    <div class="form-group">
                        <label for="name">Name:</label>
                        <input type="text" id="name" name="name" value="<?php echo htmlspecialchars($user['name'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label for="gender">Gender:</label>
                        <select id="gender" name="gender">
                            <option value="">Select</option>
                            <option value="male" <?php if ($user['gender'] == 'male') echo 'selected'; ?>>Male (Father)</option>
                            <option value="female" <?php if ($user['gender'] == 'female') echo 'selected'; ?>>Female (Mother)</option>
                        </select>
                    </div>
                    <button type="submit" name="update_parent_profile" class="button">Update Profile</button>
                </form>
                <h3>Change Password</h3>
                <form method="POST" action="profile.php">
                    <div class="form-group">
                        <label for="new_password">New Password:</label>
                        <input type="password" id="new_password" name="new_password" required>
                    </div>
                    <button type="submit" name="update_password" class="button">Update Password</button>
                </form>
                <h3>Manage Family</h3>
                <a href="dashboard_parent.php#manage-family" class="button">Go to Manage Family</a>
            </div>
        <?php endif; ?>
        <a href="dashboard_<?php echo $role; ?>.php" class="button">Back to Dashboard</a>
    </div>
</body>
</html>