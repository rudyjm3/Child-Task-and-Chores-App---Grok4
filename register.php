<?php
// register.php - User registration
// Purpose: Register new parent account (child creation now parent-driven)
// Version: 3.26.0

require_once __DIR__ . '/includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = filter_input(INPUT_POST, 'username', FILTER_SANITIZE_STRING);
    $password = filter_input(INPUT_POST, 'password', FILTER_SANITIZE_STRING);
    $confirm_password = filter_input(INPUT_POST, 'confirm_password', FILTER_SANITIZE_STRING);
    $first_name = filter_input(INPUT_POST, 'first_name', FILTER_SANITIZE_STRING);
    $last_name = filter_input(INPUT_POST, 'last_name', FILTER_SANITIZE_STRING);
    $gender = filter_input(INPUT_POST, 'gender', FILTER_SANITIZE_STRING);
    $role = 'main_parent'; // primary account creator

    if ($password !== $confirm_password) {
        $error = "Passwords do not match.";
    } elseif (registerUser($username, $password, $role, $first_name, $last_name, $gender)) {
        // Auto-login after registration
        $_SESSION['user_id'] = $db->lastInsertId();
        $_SESSION['role'] = 'parent'; // UI-level
        $_SESSION['role_type'] = $role; // detailed
        $_SESSION['username'] = $username;
        header("Location: dashboard_parent.php?setup_family=1");
        exit;
    } else {
        $error = "Registration failed. Username may already exist.";
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="auth-root">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - Child Task and Chore App</title>
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
        .auth-top-row {
            display: flex;
            align-items: center;
            gap: 14px;
            color: var(--auth-text);
        }
        .auth-back {
            width: 46px;
            height: 46px;
            border-radius: 14px;
            background: #ffffff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            color: var(--auth-text);
            border: 1px solid rgba(0, 0, 0, 0.06);
            box-shadow: 0 10px 22px rgba(50, 30, 90, 0.12);
            font-size: 1.4rem;
            font-weight: 700;
        }
        .auth-top-row h1 {
            margin: 0;
            font-size: clamp(1.8rem, 3.2vw, 2.4rem);
        }
        .auth-logo-card {
            text-align: center;
            display: grid;
            gap: 10px;
            max-width: 620px;
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
            max-width: 620px;
            width: 100%;
            margin: 0 auto;
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
        .input-row input,
        .input-row select {
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
            max-width: 620px;
            width: 100%;
            margin: 0 auto;
        }
        .auth-footer a {
            color: var(--auth-primary);
            font-weight: 600;
            text-decoration: none;
        }
        .role-note {
            color: var(--auth-muted);
            font-size: 0.9rem;
            margin-top: 10px;
            text-align: center;
        }
        @media (min-width: 900px) {
            .auth-shell {
                gap: 24px;
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
            .auth-top-row {
                gap: 10px;
            }
            .auth-top-row h1 {
                font-size: 1.8rem;
            }
        }
    </style>
</head>
<body class="auth-page">
    <main class="auth-shell">
        <section class="auth-top-row">
            <a class="auth-back" href="login.php" aria-label="Back">&larr;</a>
            <h1>Create Account</h1>
        </section>

        <section class="auth-card auth-logo-card">
            <div class="auth-logo">
                <img src="images/favicon.svg" alt="Child Chore App logo">
            </div>
            <h2>Join Child Chore App</h2>
            <span>Create your family account</span>
        </section>

        <section class="auth-card auth-form-card">
            <?php if (isset($error)): ?>
                <div class="auth-error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <form method="POST">
                <div class="form-group">
                    <label class="input-row" for="first_name">
                        <span class="input-icon"><i class="fa-solid fa-user"></i></span>
                        <input type="text" id="first_name" name="first_name" placeholder="First Name" required>
                    </label>
                </div>
                <div class="form-group">
                    <label class="input-row" for="last_name">
                        <span class="input-icon"><i class="fa-solid fa-user"></i></span>
                        <input type="text" id="last_name" name="last_name" placeholder="Last Name" required>
                    </label>
                </div>
                <div class="form-group">
                    <label class="input-row" for="gender">
                        <span class="input-icon"><i class="fa-solid fa-venus-mars"></i></span>
                        <select id="gender" name="gender">
                            <option value="">Select Gender</option>
                            <option value="male">Male (Father)</option>
                            <option value="female">Female (Mother)</option>
                        </select>
                    </label>
                </div>
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
                <div class="form-group">
                    <label class="input-row input-row--pw" for="confirm_password">
                        <span class="input-icon"><i class="fa-solid fa-lock"></i></span>
                        <input type="password" id="confirm_password" name="confirm_password" placeholder="Confirm Password" required>
                        <button type="button" class="toggle-pw" aria-label="Toggle confirm password visibility" data-target="confirm_password">
                            <i class="fa-solid fa-eye"></i>
                        </button>
                    </label>
                </div>
                <button type="submit" class="auth-button">Sign Up</button>
                <div class="role-note">Child accounts are created by parents during setup.</div>
            </form>
        </section>

        <div class="auth-card auth-footer">
            Already have an account? <a href="login.php">Login</a>
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
