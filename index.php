<?php
// index.php - Landing page
// Purpose: Welcome and login/register links
// Version: 3.26.0

session_start();
if (isset($_SESSION['user_id'])) {
    $role = $_SESSION['role'];
    header("Location: dashboard_$role.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Child Chore App</title>
    <link rel="stylesheet" href="css/main.css?v=3.26.0">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&family=Quicksand:wght@600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --brand-ink: #2f3340;
            --brand-muted: #6b7280;
            --brand-primary: #1f6ed4;
            --brand-primary-dark: #1b57a8;
            --brand-secondary: #f59e0b;
            --brand-accent: #22c55e;
            --brand-card: #ffffff;
            --brand-shadow: 0 18px 40px rgba(55, 35, 95, 0.18);
        }
        body {
            margin: 0;
            font-family: 'Poppins', 'Trebuchet MS', sans-serif;
            background: radial-gradient(circle at top, #e7d6ff 0%, #f1e6ff 35%, #f6efe4 70%, #f8f2e9 100%);
            color: var(--brand-ink);
            min-height: 100vh;
        }
        .landing-shell {
            width: min(1100px, 92vw);
            margin: 0 auto;
            padding: 40px 0 64px;
            display: grid;
            gap: 22px;
        }
        .hero {
            display: grid;
            gap: 18px;
            align-items: center;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
        }
        .hero-card {
            background: var(--brand-card);
            border-radius: 28px;
            padding: 24px;
            box-shadow: var(--brand-shadow);
            border: 1px solid rgba(255, 255, 255, 0.8);
        }
        .hero h1 {
            font-family: 'Quicksand', 'Poppins', sans-serif;
            font-size: clamp(2.2rem, 4vw, 3.1rem);
            margin: 0 0 8px;
        }
        .hero p {
            margin: 0;
            color: var(--brand-muted);
            font-size: 1.05rem;
        }
        .logo-pill {
            display: grid;
            gap: 8px;
            place-items: center;
            text-align: center;
        }
        .logo-circle {
            width: 92px;
            height: 92px;
            border-radius: 50%;
            background: #0f172a;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 16px 30px rgba(15, 23, 42, 0.25);
        }
        .logo-circle img {
            width: 62px;
            height: 62px;
        }
        .cta-row {
            display: grid;
            gap: 12px;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        }
        .cta-button {
            border: none;
            border-radius: 18px;
            padding: 14px 18px;
            font-weight: 700;
            font-size: 1.05rem;
            cursor: pointer;
            text-decoration: none;
            text-align: center;
            color: #fff;
            background: linear-gradient(135deg, var(--brand-primary) 0%, var(--brand-primary-dark) 100%);
            box-shadow: 0 14px 30px rgba(31, 110, 212, 0.3);
        }
        .cta-button.secondary {
            background: linear-gradient(135deg, #f59e0b 0%, #f97316 100%);
            box-shadow: 0 14px 30px rgba(245, 158, 11, 0.3);
        }
        .feature-grid {
            display: grid;
            gap: 16px;
            grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
        }
        .feature-card {
            background: var(--brand-card);
            border-radius: 22px;
            padding: 20px;
            box-shadow: var(--brand-shadow);
            border: 1px solid rgba(255, 255, 255, 0.7);
        }
        .feature-card h3 {
            margin: 0 0 6px;
            font-size: 1.2rem;
        }
        .feature-card p {
            margin: 0;
            color: var(--brand-muted);
            font-size: 0.98rem;
        }
        .benefits {
            display: grid;
            gap: 16px;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        }
        .benefit-list {
            background: var(--brand-card);
            border-radius: 22px;
            padding: 20px;
            box-shadow: var(--brand-shadow);
        }
        .benefit-list h3 {
            margin: 0 0 10px;
        }
        .benefit-item {
            display: flex;
            gap: 10px;
            align-items: flex-start;
            margin-bottom: 10px;
            color: var(--brand-muted);
        }
        .benefit-item span {
            width: 26px;
            height: 26px;
            border-radius: 8px;
            background: #e0f2fe;
            color: #0369a1;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
        }
        .pricing {
            display: grid;
            gap: 16px;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
        }
        .pricing-card {
            background: var(--brand-card);
            border-radius: 22px;
            padding: 20px;
            box-shadow: var(--brand-shadow);
            border: 1px solid rgba(255, 255, 255, 0.8);
        }
        .pricing-card h3 {
            margin: 0 0 6px;
        }
        .price {
            font-size: 2rem;
            font-weight: 700;
            margin: 8px 0;
        }
        .price small {
            font-size: 0.9rem;
            color: var(--brand-muted);
        }
        .pricing-tag {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 999px;
            background: #dcfce7;
            color: #15803d;
            font-weight: 700;
            font-size: 0.8rem;
        }
        .footer-note {
            text-align: center;
            color: var(--brand-muted);
            font-size: 0.95rem;
        }
        .plan-compare {
            display: grid;
            gap: 16px;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
        }
        .plan-card {
            background: var(--brand-card);
            border-radius: 22px;
            padding: 20px;
            box-shadow: var(--brand-shadow);
            border: 1px solid rgba(255, 255, 255, 0.85);
        }
        .plan-card h3 {
            margin: 0 0 10px;
            font-size: 1.25rem;
        }
        .plan-card ul {
            list-style: none;
            padding: 0;
            margin: 0;
            display: grid;
            gap: 8px;
            color: var(--brand-muted);
        }
        .plan-card li {
            display: flex;
            gap: 8px;
            align-items: flex-start;
        }
        .plan-card li span {
            width: 22px;
            height: 22px;
            border-radius: 7px;
            background: #e0f2fe;
            color: #0369a1;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 0.8rem;
            flex-shrink: 0;
        }
        .plan-card.premium {
            border: 1px solid rgba(245, 158, 11, 0.35);
            background: linear-gradient(180deg, #fffaf0 0%, #ffffff 100%);
        }
        .plan-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-weight: 700;
            color: #92400e;
            background: #fef3c7;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 0.8rem;
            margin-bottom: 10px;
        }
        .reveal {
            animation: fadeUp 550ms ease forwards;
            opacity: 0;
        }
        .reveal.delay-1 { animation-delay: 80ms; }
        .reveal.delay-2 { animation-delay: 160ms; }
        .reveal.delay-3 { animation-delay: 240ms; }
        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(14px); }
            to { opacity: 1; transform: translateY(0); }
        }
    </style>
</head>
<body>
    <main class="landing-shell">
        <section class="hero">
            <div class="hero-card reveal">
                <h1>Child Chore App</h1>
                <p>Build routines, reward progress, and make family goals fun.</p>
                <div class="cta-row" style="margin-top:16px;">
                    <a class="cta-button" href="login.php">Login</a>
                    <a class="cta-button secondary" href="register.php">Create Account</a>
                </div>
            </div>
            <div class="hero-card logo-pill reveal delay-1">
                <div class="logo-circle">
                    <img src="images/favicon.svg" alt="Child Chore App logo">
                </div>
                <strong>It’s not a chore!</strong>
                <span style="color: var(--brand-muted);">A brighter way to manage family chores.</span>
            </div>
        </section>

        <section class="feature-grid">
            <div class="feature-card reveal">
                <h3>Gamified routines</h3>
                <p>Turn daily tasks into streaks and levels that kids actually want to complete.</p>
            </div>
            <div class="feature-card reveal delay-1">
                <h3>Smart rewards</h3>
                <p>Create rewards, control access, and keep purchases tied to real progress.</p>
            </div>
            <div class="feature-card reveal delay-2">
                <h3>Family visibility</h3>
                <p>Parents stay informed with status updates, approvals, and weekly summaries.</p>
            </div>
        </section>

        <section class="benefits">
            <div class="benefit-list reveal">
                <h3>Benefits for parents</h3>
                <div class="benefit-item"><span>✓</span>Less nagging, more clarity</div>
                <div class="benefit-item"><span>✓</span>Shared ownership across the family</div>
                <div class="benefit-item"><span>✓</span>Track progress in one place</div>
            </div>
            <div class="benefit-list reveal delay-1">
                <h3>Benefits for kids</h3>
                <div class="benefit-item"><span>★</span>Earn points and unlock rewards</div>
                <div class="benefit-item"><span>★</span>Celebrate streaks and milestones</div>
                <div class="benefit-item"><span>★</span>Feel proud of their contributions</div>
            </div>
        </section>

        <section class="pricing">
            <div class="pricing-card reveal">
                <h3>Monthly Plan</h3>
                <div class="price">$4.99 <small>/ month</small></div>
                <p style="color: var(--brand-muted); margin: 0 0 10px;">Perfect for testing the waters.</p>
                <span class="pricing-tag">Cancel anytime</span>
            </div>
            <div class="pricing-card reveal delay-1">
                <h3>Annual Plan</h3>
                <div class="price">$49.99 <small>/ year</small></div>
                <p style="color: var(--brand-muted); margin: 0 0 10px;">Save 2 months with yearly billing.</p>
                <span class="pricing-tag">Best value</span>
            </div>
        </section>

        <section class="plan-compare">
            <div class="plan-card reveal">
                <h3>Free Features</h3>
                <ul>
                    <li><span>✓</span>Task and routine tracking</li>
                    <li><span>✓</span>Basic rewards shop</li>
                    <li><span>✓</span>Weekly progress snapshots</li>
                    <li><span>✓</span>One parent account</li>
                </ul>
            </div>
            <div class="plan-card premium reveal delay-1">
                <div class="plan-badge">Premium</div>
                <h3>Premium Features</h3>
                <ul>
                    <li><span>★</span>Unlimited family members</li>
                    <li><span>★</span>Advanced reward controls</li>
                    <li><span>★</span>Custom goals and streak boosts</li>
                    <li><span>★</span>Priority support</li>
                </ul>
            </div>
        </section>

        <div class="footer-note reveal delay-2">
            Questions? Start with a free family account and explore the demo experience.
        </div>
    </main>
</body>
</html>
