<?php
// login.php - User login
// Purpose: Authenticate and redirect to dashboard
// Version: 3.26.0

require_once __DIR__ . '/includes/functions.php';

session_start();
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard_" . $_SESSION['role'] . ".php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = filter_input(INPUT_POST, 'username', FILTER_SANITIZE_STRING);
    $password = filter_input(INPUT_POST, 'password', FILTER_SANITIZE_STRING);
    if (loginUser($username, $password)) {
        $userStmt = $db->prepare("SELECT id, role, name FROM users WHERE username = :username");
        $userStmt->execute([':username' => $username]);
        $user = $userStmt->fetch(PDO::FETCH_ASSOC);
        $_SESSION['user_id'] = $user['id'];
        // For backward compatibility the UI expects 'parent' or 'child' dashboards.
        // We'll set a generic session role for UI and a detailed role_type for permissions.
        $_SESSION['role'] = ($user['role'] === 'child') ? 'child' : 'parent';
        $_SESSION['role_type'] = $user['role']; // 'main_parent', 'family_member', 'caregiver', or 'child'
        $_SESSION['username'] = $username;
        // Normalize display name consistently
        $_SESSION['name'] = getDisplayName($user['id']);
        error_log("Login successful for user_id=" . $user['id'] . ", role_type=" . $user['role']);
        header("Location: dashboard_" . $_SESSION['role'] . ".php");
        exit;
    } else {
        $error = "Invalid username or password.";
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="auth-root">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Child Task and Chore App</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
    <style>
        *,
        *::before,
        *::after {
            box-sizing: border-box;
        }
        :root {
            --auth-bg-top: #d8c4ff;
            --auth-bg-mid: #efe1ff;
            --auth-bg-bottom: #f7efe2;
            --auth-card: #ffffff;
            --auth-text: #384152;
            --auth-muted: #6b7280;
            --auth-primary: #1f6ed4;
            --auth-primary-dark: #1b57a8;
            --auth-accent: #7dd3fc;
            --auth-shadow: 0 18px 36px rgba(50, 30, 90, 0.18);
        }
        html.auth-root,
        body.auth-page {
            width: 100%;
            min-height: 100%;
            margin: 0;
            padding: 0;
        }
        body.auth-page {
            margin: 0;
            font-family: 'Poppins', 'Trebuchet MS', 'Segoe UI', sans-serif;
            background: linear-gradient(180deg, var(--auth-bg-top) 0%, var(--auth-bg-mid) 45%, var(--auth-bg-bottom) 100%);
            color: var(--auth-text);
            min-height: 100vh;
            overflow-x: hidden;
            overflow-y: auto;
            max-width: none;
            width: 100%;
            padding: 0;
            display: block !important;
        }
        main.auth-shell {
            width: 100%;
            max-width: 980px;
            margin: 0 auto;
            padding: 32px 12px 48px;
            display: grid;
            gap: 20px;
        }
        .auth-card {
            background: var(--auth-card);
            border-radius: 10px;
            padding: 20px 24px;
            box-shadow: var(--auth-shadow);
            border: 1px solid rgba(255, 255, 255, 0.8);
        }
        .auth-hero {
            display: grid;
            grid-template-columns: 1fr;
            gap: 16px;
            align-items: center;
            max-width: 560px;
            width: 100%;
            margin: 0 auto;
            text-align: center;
        }
        .auth-hero h1 {
            margin: 0 0 6px;
            font-size: clamp(1.8rem, 3.4vw, 2.4rem);
        }
        .auth-hero p {
            margin: 0;
            color: var(--auth-muted);
            font-size: 1.05rem;
        }
        .auth-logo-card {
            text-align: center;
            display: grid;
            gap: 10px;
            max-width: 560px;
            width: 100%;
            margin: 0 auto;
        }
        .auth-logo {
            width: 76px;
            height: 76px;
            border-radius: 50%;
            background: #0f172a;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto;
            box-shadow: 0 12px 26px rgba(15, 23, 42, 0.2);
        }
        .auth-logo img {
            width: 54px;
            height: 54px;
        }
        .auth-logo-card h2 {
            margin: 0;
            font-size: 1.6rem;
        }
        .auth-logo-card span {
            color: var(--auth-muted);
            font-size: 1rem;
        }
        .auth-form-card {
            max-width: 560px;
            width: 100%;
            margin: 0 auto;
        }
        .auth-form-title {
            margin: 0 0 16px;
            font-size: 1.2rem;
        }
        .auth-error {
            background: #fee2e2;
            color: #b91c1c;
            border-radius: 12px;
            padding: 10px 12px;
            margin-bottom: 14px;
            font-weight: 600;
        }
        .form-group {
            margin-bottom: 14px;
        }
        .input-row {
            display: grid;
            grid-template-columns: 46px 1fr;
            gap: 10px;
            align-items: center;
            background: #f8fafc;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 10px 12px;
        }
        .input-icon {
            width: 40px;
            height: 40px;
            border-radius: 12px;
            background: #e0f2fe;
            color: #0369a1;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            flex-shrink: 0;
        }
        .input-row--pw {
            grid-template-columns: 46px 1fr auto;
        }
        .toggle-pw {
            background: none;
            border: none;
            cursor: pointer;
            color: var(--auth-muted);
            padding: 4px 2px;
            display: inline-flex;
            align-items: center;
            font-size: 1rem;
            line-height: 1;
        }
        .toggle-pw:hover {
            color: var(--auth-primary);
        }
        .input-row input {
            border: none;
            outline: none;
            background: transparent;
            font-size: 1rem;
            color: var(--auth-text);
            min-width: 0;
            width: 100%;
            height: 45px;
            border-radius: 8px;
            padding: 0 8px;
        }
        .auth-button {
            width: 100%;
            border: none;
            border-radius: 8px;
            padding: 14px 16px;
            font-size: 1.05rem;
            font-weight: 700;
            color: #fff;
            background: linear-gradient(135deg, var(--auth-primary) 0%, var(--auth-primary-dark) 100%);
            box-shadow: 0 5px 15px rgba(31, 110, 212, 0.28);
            cursor: pointer;
        }
        .auth-footer {
            text-align: center;
            color: var(--auth-muted);
            font-size: 0.95rem;
            max-width: 560px;
            width: 100%;
            margin: 0 auto;
        }
        .auth-footer a {
            color: var(--auth-primary);
            font-weight: 600;
            text-decoration: none;
        }
        @media (min-width: 700px) {
            .auth-hero {
                grid-template-columns: 1fr auto;
                text-align: left;
            }
        }
        @media (min-width: 900px) {
            .auth-shell {
                gap: 24px;
            }
            .auth-hero h1 {
                font-size: 2.6rem;
            }
            .auth-logo {
                width: 90px;
                height: 90px;
            }
            .auth-logo img {
                width: 64px;
                height: 64px;
            }
        }
        @media (max-width: 520px) {
            .auth-card {
                padding: 16px;
                border-radius: 10px;
            }
        }
    </style>
</head>
<body class="auth-page">
    <main class="auth-shell">
        <section class="auth-card auth-hero">
            <div>
                <h1>Welcome!</h1>
                <p>Ready to start your family's journey?</p>
            </div>
            <div class="auth-logo">
                <img src="images/favicon.svg" alt="Child Chore App logo">
            </div>
        </section>

        <section class="auth-card auth-logo-card">
            <div class="auth-logo">
                <img src="images/favicon.svg" alt="Child Chore App logo">
            </div>
            <h2>Child Chore App</h2>
            <span>It's not a chore!</span>
        </section>

        <section class="auth-card auth-form-card">
            <h3 class="auth-form-title">Login</h3>
            <?php if (isset($error)): ?>
                <div class="auth-error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <form method="POST">
                <div class="form-group">
                    <label class="input-row" for="username">
                        <span class="input-icon"><i class="fa-solid fa-circle-user"></i></span>
                        <input type="text" id="username" name="username" placeholder="Username" required>
                    </label>
                </div>
                <div class="form-group">
                    <label class="input-row input-row--pw" for="password">
                        <span class="input-icon"><i class="fa-solid fa-lock"></i></span>
                        <input type="password" id="password" name="password" placeholder="Password" required>
                        <button type="button" class="toggle-pw" aria-label="Toggle password visibility" data-target="password">
                            <i class="fa-solid fa-eye"></i>
                        </button>
                    </label>
                </div>
                <button type="submit" class="auth-button">Login</button>
            </form>
        </section>

        <div class="auth-card auth-footer">
            Don't have an account? <a href="register.php">Sign Up</a>
        </div>
    </main>
<script>
document.querySelectorAll('.toggle-pw').forEach(btn => {
    btn.addEventListener('click', () => {
        const input = document.getElementById(btn.dataset.target);
        const icon = btn.querySelector('i');
        if (input.type === 'password') {
            input.type = 'text';
            icon.classList.replace('fa-eye', 'fa-eye-slash');
        } else {
            input.type = 'password';
            icon.classList.replace('fa-eye-slash', 'fa-eye');
        }
    });
});
</script>
</html>
