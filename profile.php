<?php
// profile.php - User profile management
// Purpose: Edit profile details based on role (child: avatar/password; parent: family)
// Version: 3.5.0 (Role-based edits: child avatar/password, parent family management)

require_once __DIR__ . '/includes/functions.php';

session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_password'])) {
        $new_password = filter_input(INPUT_POST, 'new_password', FILTER_SANITIZE_STRING);
        if (updateUserPassword($user_id, $new_password)) {
            $message = "Password updated successfully!";
        } else {
            $message = "Failed to update password.";
        }
    } elseif (isset($_POST['update_child_profile']) && $role === 'child') {
        $child_name = filter_input(INPUT_POST, 'child_name', FILTER_SANITIZE_STRING);
        $age = filter_input(INPUT_POST, 'age', FILTER_VALIDATE_INT);
        $avatar = filter_input(INPUT_POST, 'avatar', FILTER_SANITIZE_STRING);
        if (updateChildProfile($user_id, $child_name, $age, $avatar)) {
            $message = "Profile updated successfully!";
        } else {
            $message = "Failed to update profile.";
        }
    }
}

// Fetch user data
$stmt = $db->prepare("SELECT * FROM users WHERE id = :id");
$stmt->execute([':id' => $user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if ($role === 'child') {
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
        /* Role-Based Styles */
        .child-profile { background: linear-gradient(135deg, #e3f2fd, #f3e5f5); }
        .parent-profile { background: #f9f9f9; }
        @media (max-width: 768px) { .avatar-options { gap: 5px; } .avatar-option { width: 50px; height: 50px; } }
    </style>
    <script>
        // JS for avatar selection (child view)
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
        <?php if ($role === 'child'): ?>
            <div class="profile-form child-profile">
                <h2>Your Profile</h2>
                <img id="avatar-preview" src="<?php echo htmlspecialchars($profile['avatar'] ?? 'default-avatar.png'); ?>" alt="Avatar" class="avatar-preview">
                <form method="POST" action="profile.php">
                    <div class="form-group">
                        <label for="child_name">Name:</label>
                        <input type="text" id="child_name" name="child_name" value="<?php echo htmlspecialchars($profile['child_name'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label for="age">Age:</label>
                        <select id="age" name="age">
                            <?php for ($i = 5; $i <= 13; $i++): ?>
                                <option value="<?php echo $i; ?>" <?php if (($profile['age'] ?? 0) == $i) echo 'selected'; ?>><?php echo $i; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Avatar:</label>
                        <div class="avatar-options">
                            <img class="avatar-option <?php if (($profile['avatar'] ?? '') == 'avatars/boy1.png') echo 'selected'; ?>" data-avatar="avatars/boy1.png" src="avatars/boy1.png" alt="Avatar 1">
                            <img class="avatar-option <?php if (($profile['avatar'] ?? '') == 'avatars/girl1.png') echo 'selected'; ?>" data-avatar="avatars/girl1.png" src="avatars/girl1.png" alt="Avatar 2">
                            <!-- Add more -->
                        </div>
                        <input type="file" name="avatar_upload" accept="image/*">
                        <input type="hidden" id="avatar" name="avatar" value="<?php echo htmlspecialchars($profile['avatar'] ?? ''); ?>">
                    </div>
                    <button type="submit" name="update_child_profile" class="button">Update Profile</button>
                </form>
                <h3>Change Password</h3>
                <form method="POST" action="profile.php">
                    <div class="form-group">
                        <label for="new_password">New Password:</label>
                        <input type="password" id="new_password" name="new_password" required>
                    </div>
                    <button type="submit" name="update_password" class="button">Update Password</button>
                </form>
            </div>
        <?php elseif ($role === 'parent'): ?>
            <div class="profile-form parent-profile">
                <h2>Your Profile</h2>
                <p>Username: <?php echo htmlspecialchars($user['username']); ?></p>
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