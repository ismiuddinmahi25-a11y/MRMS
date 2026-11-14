<?php

declare(strict_types=1);

$required_role = 'doctor';

require_once __DIR__ . '/../auth/role_guard.php';
require_once __DIR__ . '/../config/db.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    exit('Database connection unavailable.');
}

$conn->set_charset('utf8mb4');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/*
|--------------------------------------------------------------------------
| HELPER FUNCTIONS
|--------------------------------------------------------------------------
*/

function e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function initials_from($name): string
{
    $name = trim((string)$name);
    $name = preg_replace('/^dr\.?\s+/i', '', $name);

    if ($name === '') {
        return 'D';
    }

    $parts = preg_split('/\s+/', $name);
    $result = '';

    foreach (array_slice($parts, 0, 2) as $part) {
        $result .= mb_strtoupper(mb_substr($part, 0, 1));
    }

    return $result ?: 'D';
}

$icons = [
    'grid' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
    'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/>',
    'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21v-1a6 6 0 0 1 6-6h4a6 6 0 0 1 6 6v1"/>',
    'file' => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5M9 13h6M9 17h6"/>',
    'calendar' => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
    'pulse' => '<path d="M3 12h4l2-6 4 12 2-6h6"/>',
    'logout' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>',
    'menu' => '<path d="M4 7h16M4 12h16M4 17h16"/>',
    'close' => '<path d="M6 6l12 12M18 6 6 18"/>',
    'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    'bell' => '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/>',
    'pill' => '<path d="m10.5 20.5 10-10a4.95 4.95 0 0 0-7-7l-10 10a4.95 4.95 0 0 0 7 7z"/><path d="m8.5 8.5 7 7"/>',
    'history' => '<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5M12 7v5l3 2"/>',
    'chevron' => '<path d="m6 9 6 6 6-6"/>',
    'check' => '<circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/>',
    'hourglass' => '<path d="M6 2h12M6 22h12M7 2v4a5 5 0 0 0 2 4l3 2-3 2a5 5 0 0 0-2 4v4M17 2v4a5 5 0 0 1-2 4l-3 2 3 2a5 5 0 0 1 2 4v4"/>',
];

function icon($name, $size = 20): string
{
    global $icons;

    $svg = $icons[$name] ?? '';

    return '<svg class="ico" width="' . (int)$size . '" height="' . (int)$size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $svg . '</svg>';
}

function status_badge($status): string
{
    $labels = [
        'confirmed' => 'Confirmed',
        'waiting' => 'Waiting',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
        'rejected' => 'Rejected',
        'pending' => 'Pending',
    ];

    $key = isset($labels[$status]) ? $status : 'pending';

    return '<span class="badge badge-' . e($key) . '">' .
        e($labels[$key]) . '</span>';
}

function view_btn($link, $label = 'View'): string
{
    if (!$link) {
        return '<span class="btn-sm disabled" aria-disabled="true">' .
            e($label) . '</span>';
    }

    return '<a href="' . e($link) . '" class="btn-sm">' .
        e($label) . '</a>';
}

/*
|--------------------------------------------------------------------------
| AUTHENTICATED DOCTOR
|--------------------------------------------------------------------------
*/

$doctorUserId = (int)($_SESSION['user_id'] ?? 0);
$doctor_name = trim((string)($_SESSION['full_name'] ?? ''));

if ($doctorUserId <= 0) {
    http_response_code(403);
    exit('Invalid doctor session.');
}

try {

    $stmt = $conn->prepare(
        "SELECT id
         FROM doctors
         WHERE user_id = ?
           AND verification_status = 'approved'
         LIMIT 1"
    );

    $stmt->bind_param('i', $doctorUserId);
    $stmt->execute();

    $doctorRecord = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$doctorRecord) {
        http_response_code(403);
        exit('Approved doctor profile not found.');
    }

    $doctorId = (int)$doctorRecord['id'];

    /*
    |--------------------------------------------------------------------------
    | CSRF TOKEN
    |--------------------------------------------------------------------------
    */

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    /*
    |--------------------------------------------------------------------------
    | APPOINTMENT ACCEPT / REJECT
    |--------------------------------------------------------------------------
    */

    if (
        $_SERVER['REQUEST_METHOD'] === 'POST' &&
        isset($_POST['appointment_action'])
    ) {

        $token = (string)($_POST['csrf_token'] ?? '');

        if (!hash_equals($_SESSION['csrf_token'], $token)) {
            http_response_code(403);
            exit('Invalid request token.');
        }

        $appointmentId = filter_var(
            $_POST['appointment_id'] ?? null,
            FILTER_VALIDATE_INT
        );

        $action = (string)$_POST['appointment_action'];

        $allowedActions = [
            'accept' => 'confirmed',
            'reject' => 'rejected',
        ];

        if (
            !$appointmentId ||
            $appointmentId <= 0 ||
            !isset($allowedActions[$action])
        ) {
            http_response_code(400);
            exit('Invalid appointment request.');
        }

        $newStatus = $allowedActions[$action];

        $conn->begin_transaction();

        try {

            $checkStmt = $conn->prepare(
                "SELECT id, patient_id, status
                 FROM appointments
                 WHERE id = ?
                   AND doctor_id = ?
                 FOR UPDATE"
            );

            $checkStmt->bind_param(
                'ii',
                $appointmentId,
                $doctorId
            );

            $checkStmt->execute();

            $appointment = $checkStmt->get_result()->fetch_assoc();
            $checkStmt->close();

            if (!$appointment) {
                $conn->rollback();
                header('Location: dashboard.php?appointment_action=not_found');
                exit;
            }

            if ($appointment['status'] !== 'pending') {
                $conn->rollback();
                header('Location: dashboard.php?appointment_action=unchanged');
                exit;
            }

            $updateStmt = $conn->prepare(
                "UPDATE appointments
                 SET status = ?,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = ?
                   AND doctor_id = ?
                   AND status = 'pending'"
            );

            $updateStmt->bind_param(
                'sii',
                $newStatus,
                $appointmentId,
                $doctorId
            );

            $updateStmt->execute();

            if ($updateStmt->affected_rows !== 1) {
                $updateStmt->close();
                $conn->rollback();

                header('Location: dashboard.php?appointment_action=unchanged');
                exit;
            }

            $updateStmt->close();
            $conn->commit();

            header(
                'Location: dashboard.php?appointment_action=' .
                ($action === 'accept' ? 'accepted' : 'rejected')
            );

            exit;

        } catch (Throwable $exception) {

            $conn->rollback();
            throw $exception;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | DASHBOARD DATA
    |--------------------------------------------------------------------------
    */

    $pending_appointments = [];
    $appointments_today = [];
    $upcoming_appointments = [];
    $recent_patients = [];

    $stmt = $conn->prepare(
        "SELECT COUNT(DISTINCT patient_id) AS total
         FROM appointments
         WHERE doctor_id = ?
           AND status IN ('confirmed', 'completed')"
    );

    $stmt->bind_param('i', $doctorId);
    $stmt->execute();

    $total_patients = (int)(
        $stmt->get_result()->fetch_assoc()['total'] ?? 0
    );

    $stmt->close();

    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM appointments
         WHERE doctor_id = ?
           AND appointment_date = CURDATE()
           AND status IN ('pending', 'confirmed', 'completed')"
    );

    $stmt->bind_param('i', $doctorId);
    $stmt->execute();

    $todays_appointments = (int)(
        $stmt->get_result()->fetch_assoc()['total'] ?? 0
    );

    $stmt->close();

    /*
    |--------------------------------------------------------------------------
    | PENDING APPOINTMENTS
    |--------------------------------------------------------------------------
    */

    $pendingStmt = $conn->prepare(
        "SELECT
            a.id,
            a.appointment_date,
            a.appointment_time,
            a.reason,
            u.full_name AS patient_name
         FROM appointments a
         INNER JOIN patients p ON p.id = a.patient_id
         INNER JOIN users u ON u.id = p.user_id
         WHERE a.doctor_id = ?
           AND a.status = 'pending'
         ORDER BY a.appointment_date ASC, a.appointment_time ASC"
    );

    $pendingStmt->bind_param('i', $doctorId);
    $pendingStmt->execute();

    $result = $pendingStmt->get_result();

    while ($row = $result->fetch_assoc()) {

        $timestamp = strtotime($row['appointment_time']);

        $pending_appointments[] = [
            'id' => (int)$row['id'],
            'patient' => $row['patient_name'],
            'date' => $row['appointment_date'],
            'time' => $timestamp !== false
                ? date('h:i A', $timestamp)
                : $row['appointment_time'],
            'reason' => $row['reason'] ?: 'Not specified',
        ];
    }

    $pendingStmt->close();

    /*
    |--------------------------------------------------------------------------
    | TODAY'S APPOINTMENTS
    |--------------------------------------------------------------------------
    */

    $todayStmt = $conn->prepare(
        "SELECT
            a.id,
            a.patient_id,
            a.appointment_time,
            a.appointment_date,
            a.reason,
            a.status,
            u.full_name AS patient_name
         FROM appointments a
         INNER JOIN patients p ON p.id = a.patient_id
         INNER JOIN users u ON u.id = p.user_id
         WHERE a.doctor_id = ?
           AND a.appointment_date = CURDATE()
           AND a.status IN ('pending', 'confirmed', 'completed')
         ORDER BY a.appointment_time ASC"
    );

    $todayStmt->bind_param('i', $doctorId);
    $todayStmt->execute();

    $result = $todayStmt->get_result();

    while ($row = $result->fetch_assoc()) {

        $timestamp = strtotime($row['appointment_time']);

        $appointments_today[] = [
            'id' => (int)$row['id'],
            'patient' => $row['patient_name'],
            'time' => $timestamp !== false
                ? date('h:i A', $timestamp)
                : $row['appointment_time'],
            'reason' => $row['reason'] ?: 'Not specified',
            'status' => $row['status'],
        ];
    }

    $todayStmt->close();

    /*
    |--------------------------------------------------------------------------
    | UPCOMING APPOINTMENTS
    |--------------------------------------------------------------------------
    */

    $upcomingStmt = $conn->prepare(
        "SELECT
            a.id,
            a.patient_id,
            a.appointment_date,
            a.appointment_time,
            a.reason,
            a.status,
            u.full_name AS patient_name
         FROM appointments a
         INNER JOIN patients p ON p.id = a.patient_id
         INNER JOIN users u ON u.id = p.user_id
         WHERE a.doctor_id = ?
           AND a.appointment_date > CURDATE()
           AND a.status = 'confirmed'
         ORDER BY a.appointment_date ASC, a.appointment_time ASC
         LIMIT 10"
    );

    $upcomingStmt->bind_param('i', $doctorId);
    $upcomingStmt->execute();

    $result = $upcomingStmt->get_result();

    while ($row = $result->fetch_assoc()) {

        $timestamp = strtotime($row['appointment_time']);

        $upcoming_appointments[] = [
            'id' => (int)$row['id'],
            'patient' => $row['patient_name'],
            'date' => $row['appointment_date'],
            'time' => $timestamp !== false
                ? date('h:i A', $timestamp)
                : $row['appointment_time'],
            'reason' => $row['reason'] ?: 'Not specified',
            'status' => $row['status'],
        ];
    }

    $upcomingStmt->close();

    /*
    |--------------------------------------------------------------------------
    | RECENT PATIENTS
    |--------------------------------------------------------------------------
    */

    $recentStmt = $conn->prepare(
        "SELECT
            a.patient_id,
            u.full_name AS patient_name,
            MAX(a.appointment_date) AS last_visit,
            COUNT(a.id) AS appointment_count
         FROM appointments a
         INNER JOIN patients p ON p.id = a.patient_id
         INNER JOIN users u ON u.id = p.user_id
         WHERE a.doctor_id = ?
           AND a.status IN ('confirmed', 'completed')
         GROUP BY a.patient_id, u.full_name
         ORDER BY last_visit DESC
         LIMIT 5"
    );

    $recentStmt->bind_param('i', $doctorId);
    $recentStmt->execute();

    $result = $recentStmt->get_result();

    while ($row = $result->fetch_assoc()) {

        $recent_patients[] = [
            'id' => (int)$row['patient_id'],
            'name' => $row['patient_name'],
            'meta' => (int)$row['appointment_count'] . ' appointment(s)',
            'last_visit' => date('d M Y', strtotime($row['last_visit'])),
        ];
    }

    $recentStmt->close();

    /*
    |--------------------------------------------------------------------------
    | REPORT COUNTS
    |--------------------------------------------------------------------------
    |
    | Report integration will be connected after verifying the actual
    | medical_reports schema and its authorization fields.
    |
    */

    $pending_reports = 0;
    $completed_reports = 0;

    $stats = [
        ['users', 'Total Patients', $total_patients, 'tone-navy'],
        ['calendar', "Today's Appointments", $todays_appointments, 'tone-teal'],
        ['hourglass', 'Pending Reports', $pending_reports, 'tone-blue'],
        ['check', 'Completed Reports', $completed_reports, 'tone-green'],
    ];

} catch (Throwable $exception) {

    error_log(
        'MRMS Doctor Dashboard Error: ' . $exception->getMessage()
    );

    http_response_code(500);
    exit('Dashboard could not load. Please check the application error log.');
}

/*
|--------------------------------------------------------------------------
| DISPLAY DATA
|--------------------------------------------------------------------------
*/

$doctor_name = $doctor_name ?: 'Doctor';

$display_name = preg_match('/^dr\.?\s/i', $doctor_name)
    ? $doctor_name
    : 'Dr. ' . $doctor_name;

$initials = initials_from($doctor_name);

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Doctor Dashboard - MRMS</title>

    <link rel="stylesheet" href="../auth/assets/css/dashboard.css">
    <link rel="stylesheet" href="../auth/assets/css/doctor-dashboard.css">
</head>

<body>

<div class="app">

    <aside class="sidebar" id="sidebar" aria-label="Sidebar">

        <div class="sidebar-top">

            <a href="../index.php" class="brand" aria-label="MRMS home">

                <span class="brand-mark">
                    <?= icon('pulse', 22) ?>
                </span>

                <span class="brand-text">
                    MRMS
                    <small>Doctor Portal</small>
                </span>

            </a>

            <button class="icon-btn sidebar-close"
                    id="sidebarClose"
                    aria-label="Close menu">

                <?= icon('close', 22) ?>

            </button>

        </div>

        <nav class="side-nav">

            <p class="nav-title">Menu</p>

            <a href="dashboard.php" class="nav-link active">
                <?= icon('grid') ?>
                <span>Dashboard</span>
            </a>

            <a href="patients.php" class="nav-link">
                <?= icon('users') ?>
                <span>My Patients</span>
            </a>

            <a href="appointments.php" class="nav-link">
                <?= icon('calendar') ?>
                <span>Appointments</span>
            </a>

            <a href="medical-reports.php" class="nav-link">
                <?= icon('file') ?>
                <span>Medical Reports</span>
            </a>

            <a href="prescriptions.php" class="nav-link">
                <?= icon('pill') ?>
                <span>Prescriptions</span>
            </a>

            <a href="medical-history.php" class="nav-link">
                <?= icon('history') ?>
                <span>Medical History</span>
            </a>

            <a href="profile.php" class="nav-link">
                <?= icon('user') ?>
                <span>My Profile</span>
            </a>

        </nav>

        <div class="sidebar-bottom">

            <a href="../auth/logout.php" class="nav-link logout">
                <?= icon('logout') ?>
                <span>Logout</span>
            </a>

        </div>

    </aside>

    <div class="overlay" id="overlay"></div>

    <div class="main">

        <header class="topbar">

            <div class="topbar-left">

                <button class="icon-btn menu-btn"
                        id="menuBtn"
                        aria-label="Open menu"
                        aria-expanded="false">

                    <?= icon('menu', 22) ?>

                </button>

                <div>
                    <h1>Dashboard</h1>
                    <p class="topbar-date"><?= date('l, d F Y') ?></p>
                </div>

            </div>

            <div class="topbar-right">

                <div class="dropdown" data-dropdown>

                    <button class="icon-btn bell"
                            type="button"
                            data-dropdown-toggle
                            aria-label="Notifications"
                            aria-haspopup="true"
                            aria-expanded="false">

                        <?= icon('bell', 22) ?>

                    </button>

                    <div class="dropdown-menu notif-menu" role="menu">

                        <div class="dropdown-head">Notifications</div>

                        <div class="notif-empty">

                            <span class="empty-icon">
                                <?= icon('bell', 22) ?>
                            </span>

                            <strong>You're all caught up</strong>

                            <p>New alerts will appear here when notification integration is enabled.</p>

                        </div>

                    </div>

                </div>

                <div class="dropdown" data-dropdown>

                    <button class="profile profile-btn"
                            type="button"
                            data-dropdown-toggle
                            aria-haspopup="true"
                            aria-expanded="false">

                        <span class="avatar">
                            <?= e($initials) ?>
                        </span>

                        <span class="profile-info">

                            <strong><?= e($display_name) ?></strong>
                            <small>Doctor</small>

                        </span>

                        <span class="chev">
                            <?= icon('chevron', 16) ?>
                        </span>

                    </button>

                    <div class="dropdown-menu profile-menu" role="menu">

                        <div class="dropdown-head">

                            <strong><?= e($display_name) ?></strong>
                            <small>Doctor</small>

                        </div>

                        <a href="profile.php" class="dropdown-item">
                            <?= icon('user', 18) ?>
                            <span>My Profile</span>
                        </a>

                        <a href="../auth/logout.php" class="dropdown-item danger">
                            <?= icon('logout', 18) ?>
                            <span>Logout</span>
                        </a>

                    </div>

                </div>

            </div>

        </header>

        <main class="content">

            <section class="welcome">

                <div>

                    <h2>Welcome, <?= e($display_name) ?></h2>

                    <p>Here is your schedule and patient activity for today.</p>

                </div>

            </section>

            <section aria-labelledby="overviewTitle">

                <h3 class="section-title" id="overviewTitle">Overview</h3>

                <div class="stats stats-4">

                    <?php foreach ($stats as $s): ?>

                        <article class="stat <?= e($s[3]) ?>">

                            <div class="stat-icon">
                                <?= icon($s[0], 24) ?>
                            </div>

                            <div class="stat-body">

                                <span class="stat-label">
                                    <?= e($s[1]) ?>
                                </span>

                                <span class="stat-value">
                                    <?= number_format((int)$s[2]) ?>
                                </span>

                            </div>

                        </article>

                    <?php endforeach; ?>

                </div>

            </section>

            <section class="panel block" aria-labelledby="pendingTitle">

                <div class="panel-head">

                    <h3 id="pendingTitle">Pending Appointment Requests</h3>

                    <span class="badge badge-pending">
                        <?= count($pending_appointments) ?> Pending
                    </span>

                </div>

                <?php if (isset($_GET['appointment_action'])): ?>

                    <?php
                    $feedback = [
                        'accepted' => 'Appointment accepted successfully.',
                        'rejected' => 'Appointment rejected successfully.',
                        'unchanged' => 'Appointment was already processed or could not be updated.',
                        'not_found' => 'Appointment not found or not assigned to your account.',
                    ];

                    $feedbackKey = (string)$_GET['appointment_action'];
                    ?>

                    <?php if (isset($feedback[$feedbackKey])): ?>

                        <p class="appointment-feedback">
                            <?= e($feedback[$feedbackKey]) ?>
                        </p>

                    <?php endif; ?>

                <?php endif; ?>

                <?php if (empty($pending_appointments)): ?>

                    <div class="empty">

                        <span class="empty-icon">
                            <?= icon('calendar', 26) ?>
                        </span>

                        <strong>No pending appointment requests</strong>

                        <p>New appointment requests from patients will appear here.</p>

                    </div>

                <?php else: ?>

                    <div class="table-wrap">

                        <table class="data-table">

                            <thead>
                                <tr>
                                    <th>Patient</th>
                                    <th>Date</th>
                                    <th>Time</th>
                                    <th>Reason</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>

                            <tbody>

                                <?php foreach ($pending_appointments as $a): ?>

                                    <tr>

                                        <td data-label="Patient">

                                            <span class="person">

                                                <span class="mini-avatar">
                                                    <?= e(initials_from($a['patient'])) ?>
                                                </span>

                                                <?= e($a['patient']) ?>

                                            </span>

                                        </td>

                                        <td data-label="Date">
                                            <?= e(date('d M Y', strtotime($a['date']))) ?>
                                        </td>

                                        <td data-label="Time">
                                            <?= e($a['time']) ?>
                                        </td>

                                        <td data-label="Reason">
                                            <?= e($a['reason']) ?>
                                        </td>

                                        <td data-label="Status">
                                            <?= status_badge('pending') ?>
                                        </td>

                                        <td data-label="Action" class="col-action">

                                            <div class="appointment-actions">

                                                <form method="POST" action="dashboard.php">

                                                    <input type="hidden"
                                                           name="csrf_token"
                                                           value="<?= e($_SESSION['csrf_token']) ?>">

                                                    <input type="hidden"
                                                           name="appointment_id"
                                                           value="<?= (int)$a['id'] ?>">

                                                    <button type="submit"
                                                            name="appointment_action"
                                                            value="accept"
                                                            class="btn-sm appointment-accept">
                                                        Accept
                                                    </button>

                                                </form>

                                                <form method="POST"
                                                      action="dashboard.php"
                                                      onsubmit="return confirm('Are you sure you want to reject this appointment?');">

                                                    <input type="hidden"
                                                           name="csrf_token"
                                                           value="<?= e($_SESSION['csrf_token']) ?>">

                                                    <input type="hidden"
                                                           name="appointment_id"
                                                           value="<?= (int)$a['id'] ?>">

                                                    <button type="submit"
                                                            name="appointment_action"
                                                            value="reject"
                                                            class="btn-sm appointment-reject">
                                                        Reject
                                                    </button>

                                                </form>

                                            </div>

                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            </tbody>

                        </table>

                    </div>

                <?php endif; ?>

            </section>

            <section class="panel block" aria-labelledby="todayTitle">

                <div class="panel-head">

                    <h3 id="todayTitle">Today's appointments</h3>

                    <?= view_btn('appointments.php', 'View all') ?>

                </div>

                <?php if (empty($appointments_today)): ?>

                    <div class="empty">

                        <span class="empty-icon">
                            <?= icon('calendar', 26) ?>
                        </span>

                        <strong>No appointments today</strong>

                        <p>Appointments booked for today will be listed here.</p>

                    </div>

                <?php else: ?>

                    <div class="table-wrap">

                        <table class="data-table">

                            <thead>

                                <tr>
                                    <th>Time</th>
                                    <th>Patient</th>
                                    <th>Reason</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>

                            </thead>

                            <tbody>

                                <?php foreach ($appointments_today as $a): ?>

                                    <tr>

                                        <td data-label="Time">
                                            <strong><?= e($a['time']) ?></strong>
                                        </td>

                                        <td data-label="Patient">

                                            <span class="person">

                                                <span class="mini-avatar">
                                                    <?= e(initials_from($a['patient'])) ?>
                                                </span>

                                                <?= e($a['patient']) ?>

                                            </span>

                                        </td>

                                        <td data-label="Reason">
                                            <?= e($a['reason']) ?>
                                        </td>

                                        <td data-label="Status">
                                            <?= status_badge($a['status']) ?>
                                        </td>

                                        <td data-label="Action" class="col-action">
                                            <?= view_btn('appointments.php', 'View') ?>
                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            </tbody>

                        </table>

                    </div>

                <?php endif; ?>

            </section>

            <div class="two-col even">

                <section class="panel" aria-labelledby="patientsTitle">

                    <div class="panel-head">

                        <h3 id="patientsTitle">Recent patients</h3>

                        <?= view_btn('patients.php', 'View all') ?>

                    </div>

                    <?php if (empty($recent_patients)): ?>

                        <div class="empty">

                            <span class="empty-icon">
                                <?= icon('users', 26) ?>
                            </span>

                            <strong>No associated patients yet</strong>

                            <p>Patients with confirmed or completed appointments will appear here.</p>

                        </div>

                    <?php else: ?>

                        <ul class="list">

                            <?php foreach ($recent_patients as $p): ?>

                                <li>

                                    <span class="mini-avatar lg">
                                        <?= e(initials_from($p['name'])) ?>
                                    </span>

                                    <div class="list-text">

                                        <strong><?= e($p['name']) ?></strong>

                                        <small>
                                            <?= e($p['meta']) ?>
                                            &middot; Last visit
                                            <?= e($p['last_visit']) ?>
                                        </small>

                                    </div>

                                    <?= view_btn('patients.php', 'View') ?>

                                </li>

                            <?php endforeach; ?>

                        </ul>

                    <?php endif; ?>

                </section>

                <section class="panel" aria-labelledby="upcomingTitle">

                    <div class="panel-head">

                        <h3 id="upcomingTitle">Upcoming appointments</h3>

                        <?= view_btn('appointments.php', 'View all') ?>

                    </div>

                    <?php if (empty($upcoming_appointments)): ?>

                        <div class="empty">

                            <span class="empty-icon">
                                <?= icon('clock', 26) ?>
                            </span>

                            <strong>Nothing scheduled ahead</strong>

                            <p>Confirmed future appointments will appear here.</p>

                        </div>

                    <?php else: ?>

                        <ul class="list">

                            <?php foreach ($upcoming_appointments as $u): ?>

                                <?php $ts = strtotime($u['date']); ?>

                                <li>

                                    <span class="date-chip">

                                        <b><?= e(date('d', $ts)) ?></b>

                                        <small><?= e(date('M', $ts)) ?></small>

                                    </span>

                                    <div class="list-text">

                                        <strong><?= e($u['patient']) ?></strong>

                                        <small>
                                            <?= e($u['time']) ?>
                                            &middot;
                                            <?= e($u['reason']) ?>
                                        </small>

                                    </div>

                                </li>

                            <?php endforeach; ?>

                        </ul>

                    <?php endif; ?>

                </section>

            </div>

            <section class="panel block" aria-labelledby="reportsTitle">

                <div class="panel-head">

                    <h3 id="reportsTitle">Recent medical reports</h3>

                    <?= view_btn('medical-reports.php', 'View reports') ?>

                </div>

                <div class="empty">

                    <span class="empty-icon">
                        <?= icon('file', 26) ?>
                    </span>

                    <strong>Medical report integration pending</strong>

                    <p>
                        Reports will appear here after report access
                        authorization and database integration are completed.
                    </p>

                </div>

            </section>

        </main>

        <footer class="page-foot">
            &copy; <?= date('Y') ?> MRMS.
            Medical Record Management System.
        </footer>

    </div>

</div>

<script src="../auth/assets/js/dashboard.js"></script>
<script src="../auth/assets/js/doctor-dashboard.js"></script>

</body>
</html>
