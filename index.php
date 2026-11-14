<?php

$icons = [
    'users'    => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
    'doctor'   => '<path d="M6 3v6a4 4 0 0 0 8 0V3"/><path d="M10 13v2a5 5 0 0 0 10 0v-2"/><circle cx="20" cy="11" r="2"/>',
    'flask'    => '<path d="M9 3h6M10 3v6L4.5 19a1.5 1.5 0 0 0 1.3 2h12.4a1.5 1.5 0 0 0 1.3-2L14 9V3"/><path d="M7.5 14h9"/>',
    'file'     => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5M9 13h6M9 17h6"/>',
    'calendar' => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
    'chart'    => '<path d="M3 3v18h18"/><path d="m7 15 4-4 3 3 5-6"/>',
    'shield'   => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/>',
    'user'     => '<circle cx="12" cy="8" r="4"/><path d="M4 21v-1a6 6 0 0 1 6-6h4a6 6 0 0 1 6 6v1"/>',
    'arrow'    => '<path d="M5 12h14M13 6l6 6-6 6"/>',
    'pulse'    => '<path d="M3 12h4l2-6 4 12 2-6h6"/>',
    'lock'     => '<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
    'menu'     => '<path d="M4 7h16M4 12h16M4 17h16"/>',
];

function icon($name, $size = 22)
{
    global $icons;
    return '<svg class="ico" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $icons[$name] . '</svg>';
}

/* Page content */
$features = [
    ['users',    'Patient Management',     'Register patients and keep each profile and contact detail in one place.'],
    ['doctor',   'Doctor Management',      'Maintain doctor profiles and link them to the patients they treat.'],
    ['flask',    'Laboratory Management',  'Record requested lab tests and track them from order to result.'],
    ['file',     'Medical Records',        'Store diagnoses, prescriptions and visit history in a single record.'],
    ['calendar', 'Appointment Management', 'Book and manage appointments between patients and doctors.'],
    ['chart',    'Medical Reports',        'View lab results and medical reports whenever they are needed.'],
];

$roles = [
    ['shield', 'Admin',        'Oversees the whole system.',            ['Manage doctors and lab operators', 'Control user accounts', 'Monitor system records']],
    ['doctor', 'Doctor',       'Provides care and records it.',         ['View patient history', 'Add diagnoses and prescriptions', 'Request lab tests']],
    ['flask',  'Lab Operator', 'Handles diagnostic testing.',           ['See requested tests', 'Enter test results', 'Upload lab reports']],
    ['user',   'Patient',      'Owns their health information.',        ['Create a profile', 'Book appointments', 'View own records and reports']],
];

$steps = [
    ['Register or log in', 'Patients register. Admin, doctors and lab operators log in with their accounts.'],
    ['Open your dashboard', 'Each role lands on a dashboard built for its own tasks.'],
    ['Record and update', 'Doctors and lab staff add visits, tests and results to the patient record.'],
    ['Access securely', 'Authorized users view records and reports from the database.'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MRMS | Medical Record Management System</title>
    <meta name="description" content="MRMS is a role-based Medical Record Management System for patients, doctors, lab operators and admins.">

    <style>
        /* ---------- Design tokens ---------- */
        :root {
            --navy-900: #071f31;
            --navy-800: #0c2d44;
            --navy-700: #12405c;
            --teal-500: #14b8a6;
            --teal-400: #3fd9c7;
            --teal-100: #d9f6f1;
            --ink: #12293a;
            --muted: #5d7384;
            --line: #dde8ee;
            --bg: #f4f8fb;
            --white: #ffffff;
            --serif: "Iowan Old Style", "Palatino Linotype", Palatino, Georgia, serif;
            --sans: "Segoe UI", system-ui, -apple-system, Roboto, Arial, sans-serif;
            --radius: 14px;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        html { scroll-behavior: smooth; scroll-padding-top: 80px; }
        body { font-family: var(--sans); background: var(--bg); color: var(--ink); line-height: 1.65; -webkit-font-smoothing: antialiased; }
        a { color: inherit; text-decoration: none; }
        ul { list-style: none; }
        .ico { flex-shrink: 0; }
        :focus-visible { outline: 3px solid var(--teal-400); outline-offset: 3px; border-radius: 6px; }
        .container { width: min(1180px, 90%); margin: 0 auto; }

        /* ---------- Buttons ---------- */
        .btn { display: inline-flex; align-items: center; gap: 9px; padding: 13px 24px; border-radius: 10px; font-weight: 600; font-size: 15px; border: 1px solid transparent; cursor: pointer; transition: transform .2s, background .2s, box-shadow .2s, color .2s; }
        .btn:hover { transform: translateY(-2px); }
        .btn-primary { background: var(--teal-400); color: var(--navy-900); box-shadow: 0 8px 24px rgba(63, 217, 199, .28); }
        .btn-primary:hover { background: #66e6d7; }
        .btn-ghost { border-color: rgba(255, 255, 255, .35); color: #fff; }
        .btn-ghost:hover { background: rgba(255, 255, 255, .1); }

        /* ---------- Navbar ---------- */
        .navbar { position: sticky; top: 0; z-index: 50; background: rgba(7, 31, 49, .92); backdrop-filter: blur(12px); border-bottom: 1px solid rgba(255, 255, 255, .08); }
        .nav-inner { display: flex; align-items: center; justify-content: space-between; height: 70px; }
        .brand { display: flex; align-items: center; gap: 11px; color: #fff; }
        .brand-mark { width: 38px; height: 38px; border-radius: 10px; background: linear-gradient(135deg, var(--teal-400), var(--teal-500)); display: grid; place-items: center; color: var(--navy-900); }
        .brand-text { font-family: var(--serif); font-size: 23px; font-weight: 700; letter-spacing: .5px; line-height: 1; }
        .brand-text small { display: block; font-family: var(--sans); font-size: 10.5px; font-weight: 400; color: #8fb0c2; letter-spacing: .4px; margin-top: 3px; }
        .nav-links { display: flex; align-items: center; gap: 30px; }
        .nav-links a.link { color: #c4d6e0; font-size: 14.5px; transition: color .2s; }
        .nav-links a.link:hover { color: var(--teal-400); }
        .nav-actions { display: flex; align-items: center; gap: 12px; }
        .nav-actions .btn { padding: 9px 20px; font-size: 14px; }
        .nav-toggle { display: none; background: none; border: 0; color: #fff; cursor: pointer; padding: 6px; }

        /* ---------- Hero ---------- */
        .hero { position: relative; overflow: hidden; background: radial-gradient(900px 500px at 85% 10%, rgba(20, 184, 166, .25), transparent 60%), linear-gradient(160deg, var(--navy-900), var(--navy-700)); color: #fff; padding: 90px 0 110px; }
        .hero::after { content: ""; position: absolute; inset: 0; background-image: radial-gradient(rgba(255, 255, 255, .07) 1px, transparent 1px); background-size: 26px 26px; mask-image: linear-gradient(to bottom, #000, transparent 85%); -webkit-mask-image: linear-gradient(to bottom, #000, transparent 85%); pointer-events: none; }
        .hero-grid { position: relative; z-index: 1; display: grid; grid-template-columns: 1.05fr 1fr; gap: 60px; align-items: center; }
        .hero-badge { display: inline-flex; align-items: center; gap: 8px; padding: 6px 14px; border-radius: 99px; background: rgba(63, 217, 199, .12); border: 1px solid rgba(63, 217, 199, .3); color: var(--teal-400); font-size: 13px; margin-bottom: 24px; }
        .hero h1 { font-family: var(--serif); font-size: clamp(36px, 5vw, 58px); line-height: 1.12; font-weight: 700; letter-spacing: -.5px; margin-bottom: 22px; }
        .hero p.lead { color: #bcd0db; font-size: 17px; max-width: 520px; margin-bottom: 34px; }
        .hero-buttons { display: flex; flex-wrap: wrap; gap: 14px; }
        .hero-note { margin-top: 28px; display: flex; align-items: center; gap: 10px; color: #8fb0c2; font-size: 13.5px; }

        /* Hero visual: interface preview (no real data) */
        .preview { background: #f7fbfd; border-radius: 18px; color: var(--ink); box-shadow: 0 40px 80px rgba(0, 0, 0, .4), 0 0 0 1px rgba(255, 255, 255, .2); overflow: hidden; transform: perspective(1400px) rotateY(-6deg) rotateX(2deg); transition: transform .5s; }
        .preview:hover { transform: perspective(1400px) rotateY(0) rotateX(0); }
        .pv-bar { display: flex; align-items: center; gap: 7px; padding: 12px 16px; background: #fff; border-bottom: 1px solid var(--line); }
        .pv-bar i { width: 10px; height: 10px; border-radius: 50%; background: #d4e0e7; }
        .pv-bar span { margin-left: 10px; font-size: 12px; color: var(--muted); }
        .pv-body { display: grid; grid-template-columns: 56px 1fr; min-height: 320px; }
        .pv-side { background: var(--navy-800); display: flex; flex-direction: column; align-items: center; gap: 18px; padding-top: 18px; color: #7fa3b8; }
        .pv-side .on { color: var(--teal-400); }
        .pv-main { padding: 20px; }
        .pv-head { display: flex; align-items: center; gap: 12px; margin-bottom: 18px; }
        .pv-avatar { width: 44px; height: 44px; border-radius: 50%; background: var(--teal-100); color: var(--teal-500); display: grid; place-items: center; }
        .sk { display: block; height: 9px; border-radius: 6px; background: #dfe9ef; }
        .sk.w60 { width: 60%; } .sk.w40 { width: 40%; } .sk.w80 { width: 80%; }
        .sk.dark { background: #c4d5df; height: 11px; margin-bottom: 8px; }
        .pv-tabs { display: flex; gap: 8px; margin-bottom: 16px; flex-wrap: wrap; }
        .pv-tabs span { font-size: 12px; padding: 5px 12px; border-radius: 99px; background: #e7f0f4; color: var(--muted); }
        .pv-tabs span.on { background: var(--navy-800); color: #fff; }
        .pv-ecg { background: #fff; border: 1px solid var(--line); border-radius: 12px; padding: 12px 14px 8px; margin-bottom: 14px; }
        .pv-ecg small { font-size: 11.5px; color: var(--muted); }
        .pv-ecg svg { width: 100%; height: 56px; display: block; }
        .ecg-line { stroke: var(--teal-500); stroke-width: 2.4; fill: none; stroke-linecap: round; stroke-linejoin: round; stroke-dasharray: 520; stroke-dashoffset: 520; animation: draw 3.2s ease-out .4s forwards; }
        @keyframes draw { to { stroke-dashoffset: 0; } }
        .pv-rows { display: grid; gap: 10px; }
        .pv-row { display: flex; align-items: center; gap: 10px; padding: 10px 12px; background: #fff; border: 1px solid var(--line); border-radius: 10px; }
        .pv-row .dot { width: 26px; height: 26px; border-radius: 8px; background: var(--teal-100); color: var(--teal-500); display: grid; place-items: center; }
        .pv-row .sk { flex: 1; }
        .pv-caption { text-align: center; font-size: 11.5px; color: var(--muted); padding: 0 0 12px; }

        /* ---------- Sections ---------- */
        .section { padding: 92px 0; }
        .section.alt { background: #fff; border-block: 1px solid var(--line); }
        .section-head { max-width: 640px; margin-bottom: 52px; }
        .section-head h2 { font-family: var(--serif); font-size: clamp(28px, 3.4vw, 40px); line-height: 1.2; color: var(--navy-800); margin-bottom: 14px; }
        .section-head p { color: var(--muted); font-size: 16.5px; }

        /* Features */
        .grid-3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 24px; }
        .card { background: #fff; border: 1px solid var(--line); border-radius: var(--radius); padding: 30px 28px; transition: transform .25s, box-shadow .25s, border-color .25s; }
        .card:hover { transform: translateY(-6px); border-color: #b9e6df; box-shadow: 0 20px 40px rgba(12, 45, 68, .1); }
        .card-icon { width: 50px; height: 50px; border-radius: 13px; background: var(--teal-100); color: #0e8f81; display: grid; place-items: center; margin-bottom: 20px; transition: background .25s, color .25s; }
        .card:hover .card-icon { background: var(--navy-800); color: var(--teal-400); }
        .card h3 { font-size: 18px; color: var(--navy-800); margin-bottom: 8px; }
        .card p { color: var(--muted); font-size: 15px; }

        /* Roles */
        .grid-4 { display: grid; grid-template-columns: repeat(4, 1fr); gap: 22px; }
        .role { background: var(--bg); border: 1px solid var(--line); border-radius: var(--radius); padding: 28px 24px; border-top: 4px solid var(--teal-500); }
        .role:nth-child(2) { border-top-color: var(--navy-700); }
        .role:nth-child(3) { border-top-color: #4aa8c9; }
        .role:nth-child(4) { border-top-color: #2f8f83; }
        .role .ico { color: var(--navy-700); margin-bottom: 14px; }
        .role h3 { font-family: var(--serif); font-size: 22px; color: var(--navy-800); }
        .role > p { color: var(--muted); font-size: 14.5px; margin: 4px 0 16px; }
        .role li { display: flex; gap: 10px; font-size: 14.5px; padding: 8px 0; border-top: 1px solid var(--line); }
        .role li::before { content: ""; width: 7px; height: 7px; margin-top: 9px; border-radius: 50%; background: var(--teal-500); flex-shrink: 0; }

        /* How it works (a real sequence, so numbered) */
        .steps { display: grid; grid-template-columns: repeat(4, 1fr); gap: 24px; position: relative; counter-reset: step; }
        .steps::before { content: ""; position: absolute; top: 25px; left: 12%; right: 12%; height: 2px; background: repeating-linear-gradient(90deg, var(--teal-500) 0 8px, transparent 8px 16px); opacity: .5; }
        .step { position: relative; text-align: center; padding: 0 8px; counter-increment: step; }
        .step::before { content: counter(step); width: 52px; height: 52px; margin: 0 auto 20px; border-radius: 50%; background: var(--navy-800); color: var(--teal-400); display: grid; place-items: center; font-family: var(--serif); font-size: 22px; font-weight: 700; box-shadow: 0 0 0 6px var(--bg); position: relative; z-index: 1; }
        .step h3 { font-size: 17px; color: var(--navy-800); margin-bottom: 8px; }
        .step p { font-size: 14.5px; color: var(--muted); }

        /* About */
        .about { display: grid; grid-template-columns: 1.1fr 1fr; gap: 60px; align-items: center; }
        .about h2 { font-family: var(--serif); font-size: clamp(28px, 3.4vw, 40px); line-height: 1.2; color: var(--navy-800); margin-bottom: 18px; }
        .about p { color: var(--muted); font-size: 16.5px; margin-bottom: 14px; }
        .about-panel { background: linear-gradient(160deg, var(--navy-900), var(--navy-700)); border-radius: 20px; padding: 36px; color: #fff; }
        .about-panel h3 { font-family: var(--serif); font-size: 20px; margin-bottom: 18px; }
        .about-panel li { display: flex; align-items: center; gap: 14px; padding: 14px 0; border-top: 1px solid rgba(255, 255, 255, .12); color: #cfe0e8; font-size: 15px; }
        .about-panel .ico { color: var(--teal-400); }

        /* ---------- Footer ---------- */
        footer { background: var(--navy-900); color: #a9c0cd; position: relative; }
        footer::before { content: ""; display: block; height: 3px; background: linear-gradient(90deg, var(--teal-500), var(--teal-400), var(--navy-700)); }
        .footer-grid { display: grid; grid-template-columns: 1.6fr 1fr 1fr 1fr; gap: 48px; padding: 64px 0 48px; }
        footer .brand { margin-bottom: 18px; }
        footer p { font-size: 14.5px; max-width: 340px; }
        footer h4 { color: #fff; font-size: 15px; margin-bottom: 18px; }
        footer li a { display: inline-block; font-size: 14.5px; padding: 5px 0; transition: color .2s, transform .2s; }
        footer li a:hover { color: var(--teal-400); transform: translateX(4px); }
        .footer-bottom { border-top: 1px solid rgba(255, 255, 255, .1); padding: 22px 0; display: flex; justify-content: space-between; flex-wrap: wrap; gap: 10px; font-size: 13.5px; color: #7f9aab; }

        /* ---------- Responsive ---------- */
        @media (max-width: 1000px) {
            .hero-grid, .about { grid-template-columns: 1fr; gap: 48px; }
            .preview { transform: none; max-width: 520px; }
            .grid-3 { grid-template-columns: repeat(2, 1fr); }
            .grid-4 { grid-template-columns: repeat(2, 1fr); }
            .steps { grid-template-columns: repeat(2, 1fr); row-gap: 40px; }
            .steps::before { display: none; }
            .footer-grid { grid-template-columns: 1fr 1fr; }
        }
        @media (max-width: 820px) {
            .nav-toggle { display: block; }
            .nav-menu { position: absolute; top: 70px; left: 0; right: 0; flex-direction: column; align-items: stretch; gap: 0; background: var(--navy-900); padding: 8px 5% 22px; border-bottom: 1px solid rgba(255, 255, 255, .1); display: none; }
            .nav-menu.open { display: flex; }
            .nav-links { flex-direction: column; align-items: stretch; gap: 0; }
            .nav-links a.link { padding: 14px 0; border-bottom: 1px solid rgba(255, 255, 255, .08); }
            .nav-actions { flex-direction: column; align-items: stretch; margin-top: 16px; }
            .nav-actions .btn { justify-content: center; }
        }
        @media (max-width: 620px) {
            .section { padding: 68px 0; }
            .hero { padding: 60px 0 80px; }
            .grid-3, .grid-4, .steps, .footer-grid { grid-template-columns: 1fr; }
            .hero-buttons .btn { width: 100%; justify-content: center; }
        }
        @media (prefers-reduced-motion: reduce) {
            html { scroll-behavior: auto; }
            * { transition: none !important; }
            .ecg-line { animation: none; stroke-dashoffset: 0; }
        }
    </style>
</head>

<body>

    <!-- ============ NAVIGATION ============ -->
    <header class="navbar">
        <div class="container nav-inner">
            <a href="auth/index.php" class="brand" aria-label="MRMS home">
                <span class="brand-mark"><?= icon('pulse', 22) ?></span>
                <span class="brand-text">MRMS<small>Medical Record Management</small></span>
            </a>

            <button class="nav-toggle" id="navToggle" aria-label="Open menu" aria-expanded="false"><?= icon('menu', 26) ?></button>

            <div class="nav-menu" id="navMenu" style="display:contents">
                <nav class="nav-links" aria-label="Main">
                    <a class="link" href="index.php">Home</a>
                    <a class="link" href="#features">Features</a>
                    <a class="link" href="#about">About</a>
                    <a class="link" href="#how-it-works">How It Works</a>
                </nav>
               <div class="nav-actions">
    <a href="register.php" class="btn btn-ghost">
        Patient Registration
    </a>

    <a href="doctor_register.php" class="btn btn-ghost">
        Doctor Registration
    </a>

    <a href="lab_register.php" class="btn btn-ghost">
        Lab Operator Registration
    </a>

    <a href="auth/login.php" class="btn btn-primary">
        Login
    </a>
</div>
            </div>
        </div>
    </header>

    <main>

        <!-- ============ HERO ============ -->
        <section class="hero" id="home">
            <div class="container hero-grid">
                <div>
                    <span class="hero-badge"><?= icon('lock', 15) ?> Role-based access for every user</span>
                    <h1>Every patient record, organized in one secure place.</h1>
                    <p class="lead">MRMS brings patients, doctors, lab operators and administrators together on one platform, so medical history, lab results and reports are always easy to find.</p>
                    <div class="hero-buttons">
    <a href="auth/login.php" class="btn btn-primary">
        Login to MRMS <?= icon('arrow', 18) ?>
    </a>

    <a href="register.php" class="btn btn-ghost">
        Register as a patient
    </a>

    <a href="doctor_register.php" class="btn btn-ghost">
        Register as a doctor
    </a>

    <a href="lab_register.php" class="btn btn-ghost">
        Register as a Lab Operator
    </a>
</div>
                    <div class="hero-note"><?= icon('shield', 18) ?> Built with PHP and MySQL</div>
                </div>

                <!-- Interface preview built with CSS only; shows layout, not real data -->
                <div class="preview" aria-hidden="true">
                    <div class="pv-bar"><i></i><i></i><i></i><span>MRMS / Patient record</span></div>
                    <div class="pv-body">
                        <div class="pv-side">
                            <span class="on"><?= icon('file', 20) ?></span>
                            <?= icon('users', 20) ?>
                            <?= icon('flask', 20) ?>
                            <?= icon('calendar', 20) ?>
                        </div>
                        <div class="pv-main">
                            <div class="pv-head">
                                <div class="pv-avatar"><?= icon('user', 22) ?></div>
                                <div style="flex:1"><span class="sk dark w60"></span><span class="sk w40"></span></div>
                            </div>
                            <div class="pv-tabs"><span class="on">History</span><span>Lab tests</span><span>Reports</span></div>
                            <div class="pv-ecg">
                                <small>Sample vitals view</small>
                                <svg viewBox="0 0 400 56" preserveAspectRatio="none"><path class="ecg-line" d="M0 32 H70 L84 32 L94 8 L108 50 L120 32 H190 L204 32 L214 12 L228 48 L240 32 H310 L324 32 L334 10 L348 50 L360 32 H400"/></svg>
                            </div>
                            <div class="pv-rows">
                                <div class="pv-row"><span class="dot"><?= icon('file', 15) ?></span><span class="sk w80"></span></div>
                                <div class="pv-row"><span class="dot"><?= icon('flask', 15) ?></span><span class="sk w60"></span></div>
                            </div>
                        </div>
                    </div>
                    <p class="pv-caption">Interface preview. No real patient data.</p>
                </div>
            </div>
        </section>

        <!-- ============ FEATURES ============ -->
        <section class="section" id="features">
            <div class="container">
                <div class="section-head">
                    <h2>Everything a clinic needs to keep records in order</h2>
                    <p>Six connected modules replace paper files and scattered spreadsheets.</p>
                </div>
                <div class="grid-3">
                    <?php foreach ($features as $f): ?>
                        <article class="card">
                            <div class="card-icon"><?= icon($f[0], 24) ?></div>
                            <h3><?= htmlspecialchars($f[1]) ?></h3>
                            <p><?= htmlspecialchars($f[2]) ?></p>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <!-- ============ USER ROLES ============ -->
        <section class="section alt" id="roles">
            <div class="container">
                <div class="section-head">
                    <h2>Four roles, each with its own workspace</h2>
                    <p>People see only what their role needs, which keeps medical information organized and protected.</p>
                </div>
                <div class="grid-4">
                    <?php foreach ($roles as $r): ?>
                        <article class="role">
                            <?= icon($r[0], 30) ?>
                            <h3><?= htmlspecialchars($r[1]) ?></h3>
                            <p><?= htmlspecialchars($r[2]) ?></p>
                            <ul>
                                <?php foreach ($r[3] as $item): ?>
                                    <li><?= htmlspecialchars($item) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <!-- ============ HOW IT WORKS ============ -->
        <section class="section" id="how-it-works">
            <div class="container">
                <div class="section-head">
                    <h2>How MRMS works</h2>
                    <p>From first login to finished report, in four steps.</p>
                </div>
                <div class="steps">
                    <?php foreach ($steps as $s): ?>
                        <div class="step">
                            <h3><?= htmlspecialchars($s[0]) ?></h3>
                            <p><?= htmlspecialchars($s[1]) ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <!-- ============ ABOUT ============ -->
        <section class="section alt" id="about">
            <div class="container about">
                <div>
                    <h2>About MRMS</h2>
                    <p>MRMS is a Medical Record Management System developed as a university software engineering project. It shows how healthcare information can be managed digitally with a PHP and MySQL backend.</p>
                    <p>The goal is simple: give patients, doctors, lab operators and administrators one organized place for registration, medical history, laboratory tests, appointments and reports.</p>
                </div>
                <div class="about-panel">
                    <h3>Project at a glance</h3>
                    <ul>
                        <li><?= icon('users', 22) ?> Four user roles with separate dashboards</li>
                        <li><?= icon('file', 22) ?> Database-driven medical records</li>
                        <li><?= icon('flask', 22) ?> Laboratory tests, results and reports</li>
                        <li><?= icon('lock', 22) ?> Role-based login and access</li>
                    </ul>
                </div>
            </div>
        </section>

    </main>

    <!-- ============ FOOTER ============ -->
    <footer>
        <div class="container">
            <div class="footer-grid">
                <div>
                    <a href="index.php" class="brand">
                        <span class="brand-mark"><?= icon('pulse', 22) ?></span>
                        <span class="brand-text">MRMS<small>Medical Record Management</small></span>
                    </a>
                    <p>A centralized platform for organized and secure digital healthcare records.</p>
                </div>
                <div>
                    <h4>Quick links</h4>
                    <ul>
                        <li><a href="auth/index.php">Home</a></li>
                        <li><a href="#features">Features</a></li>
                        <li><a href="#about">About</a></li>
                        <li><a href="#how-it-works">How it works</a></li>
                    </ul>
                </div>
                <div>
                    <h4>The system</h4>
                    <ul>
                        <li><a href="#features">Modules</a></li>
                        <li><a href="#roles">User roles</a></li>
                        <li><a href="#how-it-works">Workflow</a></li>
                    </ul>
                </div>
                <div>
    <h4>User access</h4>
    <ul>
        <li><a href="auth/login.php">Login</a></li>
        <li><a href="register.php">Patient registration</a></li>
        <li><a href="doctor_register.php">Doctor registration</a></li>
        <li><a href="lab_register.php">Lab Operator registration</a></li>
    </ul>
</div>
            </div>
            <div class="footer-bottom">
                <span>&copy; <?= date('Y') ?> MRMS. All rights reserved.</span>
                <span>Software Engineering Project</span>
            </div>
        </div>
    </footer>

    <!-- Mobile menu toggle -->
    <script>
        
(function () {
    var btn = document.getElementById('navToggle');
    var menu = document.getElementById('navMenu');
    var mq = window.matchMedia('(max-width: 820px)');

    function closeMenu() {
        menu.classList.remove('open');
        btn.setAttribute('aria-expanded', 'false');
    }

    function sync() {
        menu.style.display = mq.matches ? '' : 'contents';
        closeMenu();
    }

    sync();

    if (mq.addEventListener) {
        mq.addEventListener('change', sync);
    } else {
        mq.addListener(sync);
    }

    btn.addEventListener('click', function () {
        var open = menu.classList.toggle('open');
        btn.setAttribute('aria-expanded', open);
    });

    menu.querySelectorAll('a').forEach(function (a) {
        a.addEventListener('click', closeMenu);
    });
})();

    </script>
</body>
</html>