<?php
// index.php - Landing page
// Purpose: Welcome and login/register links
// Version: 3.28.0

session_start();
if (isset($_SESSION['user_id'])) {
    $role = $_SESSION['role'];
    header("Location: dashboard_$role.php");
    exit;
}

$aboutExists = file_exists(__DIR__ . '/about.php');
$termsExists = file_exists(__DIR__ . '/terms.php');
$privacyExists = file_exists(__DIR__ . '/privacy.php');
?>
<!DOCTYPE html>
<html lang="en" class="landing-root">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Child Chore App</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&family=Quicksand:wght@600;700&display=swap" rel="stylesheet">
    <style>
        *,
        *::before,
        *::after {
            box-sizing: border-box;
        }
        :root {
            --brand-ink: #2f3340;
            --brand-muted: #6b7280;
            --brand-primary: #1f6ed4;
            --brand-primary-dark: #1b57a8;
            --brand-card: #ffffff;
            --brand-shadow: 0 18px 40px rgba(55, 35, 95, 0.18);
            --page-gutter: clamp(16px, 3vw, 28px);
        }
        html.landing-root,
        body.landing-page {
            width: 100%;
            min-height: 100%;
            margin: 0;
            padding: 0;
            scroll-behavior: smooth;
            scroll-padding-top: 92px;
        }
        body.landing-page {
            margin: 0;
            font-family: 'Poppins', 'Trebuchet MS', sans-serif;
            background: radial-gradient(circle at top, #e7d6ff 0%, #f1e6ff 35%, #f6efe4 70%, #f8f2e9 100%);
            color: var(--brand-ink);
            min-height: 100vh;
            overflow-x: hidden;
            overflow-y: auto;
            width: 100%;
            padding: 0;
        }
        .site-header {
            position: sticky;
            top: 0;
            z-index: 30;
            backdrop-filter: blur(8px);
            background: rgba(255, 255, 255, 0.86);
            border-bottom: 1px solid rgba(47, 51, 64, 0.08);
        }
        .header-inner {
            max-width: 1100px;
            margin: 0 auto;
            padding: 10px 12px;
            display: flex;
            align-items: center;
            gap: 12px;
            justify-content: space-between;
        }
        .brand {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
            color: inherit;
            font-weight: 700;
        }
        .brand img {
            width: 34px;
            height: 34px;
            border-radius: 8px;
            background: #0f172a;
            padding: 4px;
        }
        .main-nav {
            display: none;
            align-items: center;
            gap: 14px;
        }
        .main-nav a {
            text-decoration: none;
            color: var(--brand-ink);
            font-weight: 600;
            font-size: 0.92rem;
            opacity: 0.9;
        }
        .main-nav a:hover {
            opacity: 1;
        }
        .nav-auth {
            display: flex;
            gap: 8px;
            align-items: center;
        }
        .menu-toggle {
            border: 1px solid rgba(47, 51, 64, 0.2);
            background: rgba(255, 255, 255, 0.88);
            color: var(--brand-ink);
            border-radius: 8px;
            padding: 8px 10px;
            font-weight: 700;
            font-size: 0.88rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 68px;
        }
        .mobile-menu {
            display: none;
            max-width: 1100px;
            margin: 0 auto;
            padding: 0 12px 12px;
        }
        .mobile-menu.is-open {
            display: block;
        }
        .mobile-panel {
            background: var(--brand-card);
            border-radius: 8px;
            padding: 12px;
            box-shadow: var(--brand-shadow);
            border: 1px solid rgba(47, 51, 64, 0.08);
            display: grid;
            gap: 10px;
        }
        .mobile-links {
            display: grid;
            gap: 8px;
        }
        .mobile-links a {
            text-decoration: none;
            color: var(--brand-ink);
            font-weight: 600;
            background: #f8fafc;
            border-radius: 8px;
            padding: 10px;
        }
        .mobile-auth {
            display: grid;
            gap: 8px;
        }
        .nav-auth .cta-button {
            font-size: 0.88rem;
            border-radius: 8px;
            padding: 9px 12px;
            box-shadow: 0 4px 12px rgba(31, 110, 212, 0.28);
        }
        .nav-auth .cta-button.secondary {
            box-shadow: 0 4px 12px rgba(245, 158, 11, 0.28);
        }
        main.landing-shell {
            width: 100%;
            max-width: 1100px;
            margin: 0 auto;
            padding: 30px var(--page-gutter) 72px;
            display: grid;
            gap: 34px;
        }
        .section-title {
            margin: 0;
            font-family: 'Quicksand', 'Poppins', sans-serif;
            font-size: clamp(1.45rem, 2.8vw, 2rem);
        }
        .section-kicker {
            margin: 0;
            color: var(--brand-muted);
            font-size: 0.98rem;
        }
        .hero {
            display: grid;
            gap: 18px;
            align-items: center;
            grid-template-columns: 1fr;
        }
        .hero-card {
            background: var(--brand-card);
            border-radius: 8px;
            padding: 24px;
            box-shadow: var(--brand-shadow);
            border: 1px solid rgba(255, 255, 255, 0.8);
        }
        .hero h1 {
            font-family: 'Quicksand', 'Poppins', sans-serif;
            font-size: clamp(2rem, 4vw, 3rem);
            margin: 0 0 10px;
            line-height: 1.15;
        }
        .hero p {
            margin: 0;
            color: var(--brand-muted);
            font-size: 1.03rem;
        }
        .cta-row {
            display: grid;
            gap: 12px;
            grid-template-columns: 1fr;
            margin-top: 16px;
        }
        .cta-button {
            border: none;
            border-radius: 8px;
            padding: 14px 18px;
            font-weight: 700;
            font-size: 1.05rem;
            cursor: pointer;
            text-decoration: none;
            text-align: center;
            color: #fff;
            background: linear-gradient(135deg, var(--brand-primary) 0%, var(--brand-primary-dark) 100%);
            box-shadow: 0 5px 15px rgba(31, 110, 212, 0.3);
        }
        .cta-button.secondary {
            background: linear-gradient(135deg, #f59e0b 0%, #f97316 100%);
            box-shadow: 0 5px 15px rgba(245, 158, 11, 0.3);
        }
        .trust-strip {
            margin-top: 14px;
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        .trust-chip {
            border-radius: 8px;
            padding: 6px 10px;
            background: #edf6ff;
            color: #0b4f86;
            font-size: 0.8rem;
            font-weight: 700;
            letter-spacing: 0.01em;
        }
        .availability {
            margin-top: 10px;
            color: var(--brand-muted);
            font-size: 0.9rem;
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
            border-radius: 8px;
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
        .full-bleed {
            width: 100vw;
            margin-left: calc(50% - 50vw);
            margin-right: calc(50% - 50vw);
            padding: 34px 0;
            border-radius: 0;
        }
        .full-bleed-inner {
            width: 100%;
            max-width: 1100px;
            margin: 0 auto;
            padding: 0 var(--page-gutter);
        }
        .band-soft-blue {
            background: rgba(232, 243, 255, 0.56);
            border-top: 1px solid rgba(59, 130, 246, 0.12);
            border-bottom: 1px solid rgba(59, 130, 246, 0.12);
        }
        .band-soft-amber {
            background: rgba(255, 247, 230, 0.62);
            border-top: 1px solid rgba(245, 158, 11, 0.15);
            border-bottom: 1px solid rgba(245, 158, 11, 0.15);
        }
        #how-it-works {
            background-image: url('assets/landing_page_images/core-feat-bg.jpg');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
        }
        #how-it-works .step-card {
            background: rgba(255, 255, 255, 0.74);
            border: 1px solid rgba(255, 255, 255, 0.62);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            box-shadow: 0 12px 28px rgba(31, 41, 55, 0.16);
        }
        .proof {
            background: transparent;
            border-radius: 0;
            padding: 0;
            box-shadow: none;
            border: none;
            display: grid;
            gap: 14px;
        }
        .proof-stats,
        .step-grid,
        .feature-grid,
        .benefits,
        .pricing,
        .faq-grid,
        .plan-compare {
            display: grid;
            gap: 16px;
            grid-template-columns: 1fr;
        }
        .proof-stat,
        .step-card,
        .feature-card,
        .benefit-list,
        .pricing-card,
        .faq-card,
        .plan-card {
            background: var(--brand-card);
            border-radius: 8px;
            padding: 20px;
            box-shadow: var(--brand-shadow);
            border: 1px solid rgba(255, 255, 255, 0.8);
        }
        .proof-stat strong {
            display: block;
            font-size: 1.4rem;
            line-height: 1;
            margin-bottom: 4px;
        }
        .proof-stat span {
            color: var(--brand-muted);
            font-size: 0.88rem;
        }
        .proof-quote {
            margin: 0;
            padding: 12px 14px;
            border-left: 4px solid #f59e0b;
            background: var(--brand-card);
            border-radius: 8px;
            color: #4b5563;
            font-size: 0.95rem;
            box-shadow: var(--brand-shadow);
        }
        .proof-note {
            margin: 0;
            color: var(--brand-muted);
            font-size: 0.8rem;
        }
        .step-number {
            width: 30px;
            height: 30px;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #dbeafe;
            color: #1d4ed8;
            font-weight: 700;
            margin-bottom: 10px;
        }
        .feature-card h3,
        .step-card h3,
        .benefit-list h3,
        .pricing-card h3,
        .faq-card h3,
        .plan-card h3 {
            margin: 0 0 8px;
            font-size: 1.15rem;
        }
        .feature-card p,
        .step-card p,
        .pricing-card p,
        .faq-card p {
            margin: 0;
            color: var(--brand-muted);
            font-size: 0.95rem;
        }
        .benefit-item {
            display: flex;
            gap: 10px;
            align-items: flex-start;
            margin-bottom: 10px;
            color: var(--brand-muted);
            font-size: 0.95rem;
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
            flex-shrink: 0;
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
            border-radius: 8px;
            background: #dcfce7;
            color: #15803d;
            font-weight: 700;
            font-size: 0.8rem;
        }
        .billing-note {
            margin: 0;
            color: var(--brand-muted);
            font-size: 0.86rem;
        }
        .plan-card ul {
            list-style: none;
            padding: 0;
            margin: 0;
            display: grid;
            gap: 8px;
            color: var(--brand-muted);
            font-size: 0.95rem;
        }
        .plan-card li {
            display: flex;
            gap: 8px;
            align-items: flex-start;
        }
        .plan-card li span {
            width: 22px;
            height: 22px;
            border-radius: 8px;
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
            border-radius: 8px;
            font-size: 0.8rem;
            margin-bottom: 10px;
        }
        .site-footer {
            background: var(--brand-card);
            border-radius: 8px;
            padding: 18px 20px;
            box-shadow: var(--brand-shadow);
            display: grid;
            gap: 8px;
        }
        .footer-links {
            display: inline-flex;
            flex-wrap: wrap;
            gap: 12px;
        }
        .footer-links a {
            color: #1f6ed4;
            text-decoration: none;
            font-weight: 600;
            font-size: 0.92rem;
        }
        .footer-meta {
            margin: 0;
            color: var(--brand-muted);
            font-size: 0.9rem;
        }
        .reveal {
            animation: fadeUp 500ms ease forwards;
            opacity: 0;
        }
        .reveal.delay-1 { animation-delay: 100ms; }
        .reveal.delay-2 { animation-delay: 180ms; }
        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(14px); }
            to { opacity: 1; transform: translateY(0); }
        }
        @media (min-width: 640px) {
            .cta-row {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
            .pricing {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
            .proof-stats {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }
        }
        @media (min-width: 768px) {
            .main-nav {
                display: inline-flex;
            }
            .menu-toggle,
            .mobile-menu {
                display: none !important;
            }
            .hero {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
            .step-grid,
            .feature-grid,
            .faq-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
            .benefits,
            .plan-compare {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }
        @media (min-width: 1024px) {
            .feature-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }
        }
        @media (max-width: 800px) {
            .nav-auth {
                display: none;
            }
            .brand span {
                display: none;
            }
        }
        @media (max-width: 520px) {
            .hero-card,
            .proof-stat,
            .step-card,
            .feature-card,
            .benefit-list,
            .pricing-card,
            .faq-card,
            .plan-card,
            .site-footer {
                padding: 16px;
                border-radius: 8px;
            }
            .header-inner {
                padding: 10px;
            }
        }
    </style>
</head>
<body class="landing-page">
    <header class="site-header">
        <div class="header-inner">
            <a href="index.php" class="brand" aria-label="Child Chore App home">
                <img src="images/favicon.svg" alt="">
                <span>Child Chore App</span>
            </a>
            <nav class="main-nav" aria-label="Landing sections">
                <a href="#features">Features</a>
                <a href="#how-it-works">How It Works</a>
                <a href="#pricing">Pricing</a>
                <a href="#faq">FAQ</a>
            </nav>
            <button class="menu-toggle" type="button" aria-expanded="false" aria-controls="mobile-menu">Menu</button>
            <div class="nav-auth">
                <a class="cta-button" href="login.php">Login</a>
                <a class="cta-button secondary" href="register.php">Create Account</a>
            </div>
        </div>
        <div id="mobile-menu" class="mobile-menu" aria-label="Mobile navigation">
            <div class="mobile-panel">
                <div class="mobile-links">
                    <a href="#features">Features</a>
                    <a href="#how-it-works">How It Works</a>
                    <a href="#pricing">Pricing</a>
                    <a href="#faq">FAQ</a>
                </div>
                <div class="mobile-auth">
                    <a class="cta-button" href="login.php">Login</a>
                    <a class="cta-button secondary" href="register.php">Create Account</a>
                </div>
            </div>
        </div>
    </header>

    <main class="landing-shell">
        <section class="hero">
            <div class="hero-card">
                <h1>Build calmer family habits with less daily friction.</h1>
                <p>Child Chore App helps parents guide routines with clear visual steps, especially for neurodivergent kids including children with Autism and ADHD.</p>
                <div class="cta-row">
                    <a class="cta-button" href="login.php">Login</a>
                    <a class="cta-button secondary" href="register.php">Create Account</a>
                </div>
                <div class="trust-strip" aria-label="Trust indicators">
                    <span class="trust-chip">Parent-friendly</span>
                    <span class="trust-chip">Kid-motivating</span>
                    <span class="trust-chip">Predictable structure</span>
                </div>
                <p class="availability">Use it on phone, tablet, or desktop with one family account.</p>
            </div>
            <div class="hero-card logo-pill">
                <div class="logo-circle">
                    <img src="images/favicon.svg" alt="Child Chore App logo">
                </div>
                <strong>It&apos;s not a chore.</strong>
                <span style="color: var(--brand-muted);">A brighter way to manage tasks, routines, and rewards.</span>
            </div>
        </section>

        <section class="full-bleed band-soft-blue reveal">
            <div class="full-bleed-inner">
                <div class="proof">
                    <h2 class="section-title" style="font-size:1.45rem;">Built for real family routines</h2>
                    <p class="section-kicker">Designed with consistency, visual clarity, and reduced friction for kids with Autism and ADHD.</p>
                    <div class="proof-stats">
                        <div class="proof-stat">
                            <strong>10+ min</strong>
                            <span>average daily family check-in time saved*</span>
                        </div>
                        <div class="proof-stat">
                            <strong>3x / week</strong>
                            <span>fewer reminder loops reported by parents*</span>
                        </div>
                        <div class="proof-stat">
                            <strong>80%</strong>
                            <span>of tasks completed on schedule in pilot use*</span>
                        </div>
                    </div>
                    <blockquote class="proof-quote">
                        "We stopped repeating ourselves every night. The kids now check their list before we even ask."
                    </blockquote>
                    <p class="proof-note">*Placeholder proof metrics for layout and messaging refinement; replace with verified production metrics.</p>
                </div>
            </div>
        </section>

        <section id="how-it-works" class="full-bleed reveal">
            <div class="full-bleed-inner">
                <h2 class="section-title">How it works</h2>
                <p class="section-kicker">A clear three-step routine designed to support focus, predictability, and follow-through.</p>
                <div class="step-grid" style="margin-top:12px;">
                    <article class="step-card">
                        <div class="step-number">1</div>
                        <h3>Create your family setup</h3>
                        <p>Add parent and child accounts so expectations are visible and consistent for everyone.</p>
                    </article>
                    <article class="step-card">
                        <div class="step-number">2</div>
                        <h3>Assign tasks and rewards</h3>
                        <p>Connect routines to motivating rewards with simple structure and minimal overwhelm.</p>
                    </article>
                    <article class="step-card">
                        <div class="step-number">3</div>
                        <h3>Track progress together</h3>
                        <p>Review completions, streaks, and wins in one shared flow that is easy to revisit daily.</p>
                    </article>
                </div>
            </div>
        </section>

        <section id="features" class="reveal">
            <h2 class="section-title">Core features</h2>
            <p class="section-kicker">Everything needed to run consistent routines without complexity.</p>
            <div class="feature-grid" style="margin-top:12px;">
                <div class="feature-card">
                    <h3>Gamified routines</h3>
                    <p>Turn recurring tasks into streaks and visible progress that kids can follow at a glance.</p>
                </div>
                <div class="feature-card">
                    <h3>Smart reward controls</h3>
                    <p>Approve redemptions, manage balances, and keep rewards tied to real effort.</p>
                </div>
                <div class="feature-card">
                    <h3>Visual clarity tools</h3>
                    <p>Use predictable task flow and simple signals that support kids with Autism and ADHD.</p>
                </div>
            </div>
        </section>

        <section class="benefits reveal delay-1">
            <div class="benefit-list">
                <h3>For parents</h3>
                <div class="benefit-item"><span>&#10003;</span>Give fewer repeated reminders each day.</div>
                <div class="benefit-item"><span>&#10003;</span>Set clear expectations that kids can see.</div>
                <div class="benefit-item"><span>&#10003;</span>Monitor progress without extra spreadsheets.</div>
            </div>
            <div class="benefit-list">
                <h3>For kids</h3>
                <div class="benefit-item"><span>&#9733;</span>Earn points and unlock rewards with consistency.</div>
                <div class="benefit-item"><span>&#9733;</span>Celebrate streaks and milestone achievements.</div>
                <div class="benefit-item"><span>&#9733;</span>Build confidence through daily contribution.</div>
            </div>
        </section>

        <section id="pricing" class="full-bleed band-soft-blue reveal">
            <div class="full-bleed-inner">
                <h2 class="section-title">Simple pricing</h2>
                <p class="section-kicker">Start small, then upgrade when your family is ready.</p>
                <div class="pricing" style="margin-top:12px;">
                    <div class="pricing-card">
                        <h3>Monthly Plan</h3>
                        <div class="price">$4.99 <small>/ month</small></div>
                        <p>Best for families trying the full workflow with flexibility.</p>
                        <p style="color: var(--brand-muted); margin: 8px 0 10px;">Great for short-term testing and seasonal routine resets.</p>
                        <span class="pricing-tag">Cancel anytime</span>
                    </div>
                    <div class="pricing-card">
                        <h3>Annual Plan</h3>
                        <div class="price">$49.99 <small>/ year</small></div>
                        <p>Best for families committed to year-round habits and savings.</p>
                        <p style="color: var(--brand-muted); margin: 8px 0 10px;">Save 2 months compared with monthly billing.</p>
                        <span class="pricing-tag">Best value</span>
                    </div>
                </div>
                <p class="billing-note">Billing renews automatically unless canceled before renewal. Upgrade or cancel any time from your account.</p>
            </div>
        </section>

        <section class="plan-compare reveal delay-1">
            <div class="plan-card">
                <h3>Free Features</h3>
                <ul>
                    <li><span>&#10003;</span>Task and routine tracking</li>
                    <li><span>&#10003;</span>Basic rewards shop</li>
                    <li><span>&#10003;</span>Weekly progress snapshots</li>
                    <li><span>&#10003;</span>One parent account</li>
                </ul>
            </div>
            <div class="plan-card premium">
                <div class="plan-badge">Premium</div>
                <h3>Premium Features</h3>
                <ul>
                    <li><span>&#9733;</span>Unlimited family members</li>
                    <li><span>&#9733;</span>Advanced reward controls</li>
                    <li><span>&#9733;</span>Custom goals and streak boosts</li>
                    <li><span>&#9733;</span>Priority support</li>
                </ul>
            </div>
        </section>

        <section id="faq" class="reveal delay-2">
            <h2 class="section-title">Frequently asked questions</h2>
            <div class="faq-grid" style="margin-top:12px;">
                <article class="faq-card">
                    <h3>How much does it cost?</h3>
                    <p>Use the free features first, then choose monthly or annual premium when you want more controls.</p>
                </article>
                <article class="faq-card">
                    <h3>Can I use it with multiple kids?</h3>
                    <p>Yes. Premium supports larger family setups, while free mode is designed for simple starts.</p>
                </article>
                <article class="faq-card">
                    <h3>Is this suitable for kids with Autism or ADHD?</h3>
                    <p>Yes. The app is designed around visual structure, predictable routines, and clear progress cues.</p>
                </article>
                <article class="faq-card">
                    <h3>Do kids need their own device?</h3>
                    <p>No. Families can share devices, and children can still track tasks through the same household setup.</p>
                </article>
            </div>
        </section>

        <footer class="site-footer reveal delay-2">
            <p class="footer-meta">Questions? Contact us at <a href="mailto:support@childchoreapp.com">support@childchoreapp.com</a>.</p>
            <div class="footer-links">
                <a href="#features">Features</a>
                <a href="#pricing">Pricing</a>
                <a href="#faq">FAQ</a>
                <?php if ($aboutExists): ?>
                    <a href="about.php">About</a>
                <?php endif; ?>
                <?php if ($termsExists): ?>
                    <a href="terms.php">Terms</a>
                <?php endif; ?>
                <?php if ($privacyExists): ?>
                    <a href="privacy.php">Privacy</a>
                <?php endif; ?>
            </div>
            <p class="footer-meta">&copy; <?php echo date('Y'); ?> Child Chore App. All rights reserved.</p>
        </footer>
    </main>
<script>
(() => {
    const toggle = document.querySelector('.menu-toggle');
    const menu = document.getElementById('mobile-menu');
    if (!toggle || !menu) {
        return;
    }

    const closeMenu = () => {
        menu.classList.remove('is-open');
        toggle.setAttribute('aria-expanded', 'false');
    };

    toggle.addEventListener('click', () => {
        const open = menu.classList.toggle('is-open');
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    });

    menu.querySelectorAll('a[href^="#"]').forEach((link) => {
        link.addEventListener('click', closeMenu);
    });

    window.addEventListener('resize', () => {
        if (window.innerWidth >= 768) {
            closeMenu();
        }
    });
})();
</script>
</body>
</html>
