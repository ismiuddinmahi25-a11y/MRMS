<?php

$required_role = 'admin';

require_once '../auth/role_guard.php';
require_once '../config/db.php';

$admin_name = $_SESSION['full_name'];

function getTotal($conn, $table) {

    $allowed_tables = [
        'users',
        'patients',
        'doctors',
        'lab_operators',
        'appointments',
        'medical_reports'
    ];

    if (!in_array($table, $allowed_tables, true)) {
        return 0;
    }

    $result = mysqli_query(
        $conn,
        "SELECT COUNT(*) AS total FROM `$table`"
    );

    if ($result) {
        $row = mysqli_fetch_assoc($result);
        return (int) $row['total'];
    }

    return 0;
}

$total_users = getTotal($conn, 'users');
$total_patients = getTotal($conn, 'patients');
$total_doctors = getTotal($conn, 'doctors');
$total_lab_operators = getTotal($conn, 'lab_operators');
$total_appointments = getTotal($conn, 'appointments');
$total_reports = getTotal($conn, 'medical_reports');

?>
<?php

$icons = [
    'grid'     => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
    'users'    => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
    'user'     => '<circle cx="12" cy="8" r="4"/><path d="M4 21v-1a6 6 0 0 1 6-6h4a6 6 0 0 1 6 6v1"/>',
    'doctor'   => '<path d="M6 3v6a4 4 0 0 0 8 0V3"/><path d="M10 13v2a5 5 0 0 0 10 0v-2"/><circle cx="20" cy="11" r="2"/>',
    'flask'    => '<path d="M9 3h6M10 3v6L4.5 19a1.5 1.5 0 0 0 1.3 2h12.4a1.5 1.5 0 0 0 1.3-2L14 9V3"/><path d="M7.5 14h9"/>',
    'file'     => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5M9 13h6M9 17h6"/>',
    'calendar' => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
    'pulse'    => '<path d="M3 12h4l2-6 4 12 2-6h6"/>',
    'logout'   => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>',
    'menu'     => '<path d="M4 7h16M4 12h16M4 17h16"/>',
    'close'    => '<path d="M6 6l12 12M18 6 6 18"/>',
    'clock'    => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    'plus'     => '<path d="M12 5v14M5 12h14"/>',
];

function icon($name, $size = 20)
{
    global $icons;
    return '<svg class="ico" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $icons[$name] . '</svg>';
}


$initials = '';
foreach (array_slice(preg_split('/\s+/', trim($admin_name)), 0, 2) as $part) {
    $initials .= mb_strtoupper(mb_substr($part, 0, 1));
}
if ($initials === '') {
    $initials = 'A';
}


$nav_items = [
    ['grid',     'Dashboard',        'dashboard.php'],
    ['users',    'Users',            'users.php'],
    ['user',     'Patients',         'patients.php'],
    ['doctor',   'Doctors',          'doctors.php'],
    ['flask',    'Lab Operators',    'lab_operators.php'],
    ['calendar', 'Appointments',     'appointments.php'],
    ['file',     'Medical Reports',  'medical_reports.php'],
];

$quick_actions = [
    ['users', 'Manage users', 'Create and update accounts', 'users.php'],
    ['doctor', 'Manage doctors', 'Add or edit doctor profiles', 'doctors.php'],
    ['flask', 'Manage lab staff', 'Add or edit lab operators', 'lab_operators.php'],
    ['file', 'View reports', 'Browse medical reports', 'medical_reports.php'],
];

$stats = [
    ['users',    'Total Users',           $total_users,         'tone-navy'],
    ['user',     'Patients',              $total_patients,      'tone-teal'],
    ['doctor',   'Doctors',               $total_doctors,       'tone-blue'],
    ['flask',    'Lab Operators',         $total_lab_operators, 'tone-green'],
    ['calendar', 'Appointments',          $total_appointments,  'tone-navy'],
    ['file',     'Medical Reports',       $total_reports,       'tone-teal'],
];


$recent_activities = [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - MRMS</title>
   <link rel="stylesheet" href="../auth/assets/css/dashboard.css">
</head>
<body>

<div class="app">

    
    <aside class="sidebar" id="sidebar" aria-label="Sidebar">
        <div class="sidebar-top">
            <a href="../index.php" class="brand" aria-label="MRMS home">
                <span class="brand-mark"><?= icon('pulse', 22) ?></span>
                <span class="brand-text">MRMS<small>Admin Console</small></span>
            </a>
            <button class="icon-btn sidebar-close" id="sidebarClose" aria-label="Close menu"><?= icon('close', 22) ?></button>
        </div>

        <nav class="side-nav">
            <p class="nav-title">Menu</p>
            
            <?php foreach ($nav_items as $item): ?>
    <a href="<?= htmlspecialchars($item[2]) ?>"
       class="nav-link<?= $item[2] === 'dashboard.php' ? ' active' : '' ?>">
        <?= icon($item[0]) ?>
        <span><?= htmlspecialchars($item[1]) ?></span>
    </a>
<?php endforeach; ?>
        </nav>

        <div class="sidebar-bottom">
            <a href="../auth/logout.php" class="nav-link logout"><?= icon('logout') ?><span>Logout</span></a>
        </div>
    </aside>
    <div class="overlay" id="overlay"></div>

    
    <div class="main">

        
        <header class="topbar">
            <div class="topbar-left">
                <button class="icon-btn menu-btn" id="menuBtn" aria-label="Open menu" aria-expanded="false"><?= icon('menu', 22) ?></button>
                <div>
                    <h1>Dashboard</h1>
                    <p class="topbar-date"><?= date('l, d F Y') ?></p>
                </div>
            </div>

            <div class="topbar-right">
                <div class="profile">
                    <span class="avatar"><?= htmlspecialchars($initials) ?></span>
                    <span class="profile-info">
                        <strong><?php echo htmlspecialchars($admin_name); ?></strong>
                        <small>Administrator</small>
                    </span>
                </div>
                <a href="../auth/logout.php" class="btn-logout"><?= icon('logout', 18) ?><span>Logout</span></a>
            </div>
        </header>

        <main class="content">

            
            <section class="welcome">
                <div>
                    <h2>Welcome, <?php echo htmlspecialchars($admin_name); ?></h2>
                    <p>Here is the current state of your Medical Record Management System.</p>
                </div>
            </section>

            
            <section aria-labelledby="overviewTitle">
                <h3 class="section-title" id="overviewTitle">System overview</h3>
                <div class="stats">
                    <?php foreach ($stats as $s): ?>
                        <article class="stat <?= $s[3] ?>">
                            <div class="stat-icon"><?= icon($s[0], 24) ?></div>
                            <div class="stat-body">
                                <span class="stat-label"><?= htmlspecialchars($s[1]) ?></span>
                                <span class="stat-value"><?= number_format($s[2]) ?></span>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>

            
            <div class="two-col">

                <section class="panel" aria-labelledby="activityTitle">
                    <div class="panel-head">
                        <h3 id="activityTitle">Recent activity</h3>
                    </div>

                    <?php if (empty($recent_activities)): ?>
                        <div class="empty">
                            <span class="empty-icon"><?= icon('clock', 26) ?></span>
                            <strong>No activity to show yet</strong>
                            <p>Recent system events will appear here once activity logging is connected.</p>
                        </div>
                    <?php else: ?>
                        <ul class="activity">
                            <?php foreach ($recent_activities as $a): ?>
                                <li>
                                    <span class="dot"></span>
                                    <div>
                                        <p><?= htmlspecialchars($a['text']) ?></p>
                                        <small><?= htmlspecialchars($a['time']) ?></small>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </section>

                <section class="panel" aria-labelledby="quickTitle">
                    <div class="panel-head">
                        <h3 id="quickTitle">Quick actions</h3>
                    </div>
                    <div class="quick">
                        <?php foreach ($quick_actions as $q): ?>
                            <?php if ($q[3] !== null): ?>
                                <a href="<?= htmlspecialchars($q[3]) ?>" class="quick-item">
                            <?php else: ?>
                                <div class="quick-item disabled" aria-disabled="true">
                            <?php endif; ?>
                                <span class="quick-icon"><?= icon($q[0], 20) ?></span>
                                <span class="quick-text">
                                    <strong><?= htmlspecialchars($q[1]) ?></strong>
                                    <small><?= htmlspecialchars($q[2]) ?></small>
                                </span>
                            <?php if ($q[3] !== null): ?>
                                </a>
                            <?php else: ?>
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </section>

            </div>
        </main>

        <footer class="page-foot">&copy; <?= date('Y') ?> MRMS. Medical Record Management System.</footer>
    </div>
</div>
<script src="../auth/assets/js/dashboard.js"></script>
</body>
</html>