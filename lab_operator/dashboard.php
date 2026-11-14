<?php
declare(strict_types=1);

/* MRMS - Lab Operator Dashboard (MRMS/lab_operator/dashboard.php) */

$required_role = 'lab_operator';

require_once __DIR__ . '/../auth/role_guard.php';
require_once __DIR__ . '/../config/db.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    exit('Database connection unavailable.');
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$userId   = (int) $_SESSION['user_id'];
$fullName = trim((string) ($_SESSION['full_name'] ?? ''));
if ($fullName === '') {
    $fullName = 'Lab Operator';
}

/* ---------------------------------------------------------
   Helpers
   --------------------------------------------------------- */
function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$icons = [
    'grid'      => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
    'clipboard' => '<rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 4h6v3H9zM9 12h6M9 16h4"/>',
    'flask'     => '<path d="M9 3h6M10 3v6L4.5 19a1.5 1.5 0 0 0 1.3 2.2h12.4a1.5 1.5 0 0 0 1.3-2.2L14 9V3"/><path d="M7.5 15h9"/>',
    'upload'    => '<path d="M12 16V4M7 9l5-5 5 5M4 20h16"/>',
    'file'      => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5M9 13h6M9 17h6"/>',
    'user'      => '<circle cx="12" cy="8" r="4"/><path d="M4 21v-1a6 6 0 0 1 6-6h4a6 6 0 0 1 6 6v1"/>',
    'pulse'     => '<path d="M3 12h4l2-6 4 12 2-6h6"/>',
    'logout'    => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>',
    'menu'      => '<path d="M4 7h16M4 12h16M4 17h16"/>',
    'close'     => '<path d="M6 6l12 12M18 6 6 18"/>',
    'clock'     => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    'bell'      => '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/>',
    'chevron'   => '<path d="m6 9 6 6 6-6"/>',
    'check'     => '<circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/>',
    'hourglass' => '<path d="M6 2h12M6 22h12M7 2v4a5 5 0 0 0 2 4l3 2-3 2a5 5 0 0 0-2 4v4M17 2v4a5 5 0 0 1-2 4l-3 2 3 2a5 5 0 0 1 2 4v4"/>',
    'activity'  => '<path d="M21 12a9 9 0 1 1-6.2-8.55"/><path d="M21 3v6h-6"/>',
    'search'    => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
];

function icon(string $name, int $size = 20): string
{
    global $icons;
    return '<svg class="ico" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($icons[$name] ?? '') . '</svg>';
}

function initials_from(string $name): string
{
    $out = '';
    foreach (array_slice(preg_split('/\s+/', trim($name)) ?: [], 0, 2) as $part) {
        $out .= mb_strtoupper(mb_substr($part, 0, 1));
    }
    return $out !== '' ? $out : 'L';
}

function fmt_date(string $value, string $format = 'd M Y'): string
{
    $ts = strtotime($value);
    return $ts ? date($format, $ts) : $value;
}

function first_existing(array $files): ?string
{
    foreach ($files as $f) {
        if (is_file(__DIR__ . '/' . $f)) {
            return $f;
        }
    }
    return null;
}

/** Normalise any status text to: pending | in_progress | completed | cancelled | other */
function norm_status(string $status): string
{
    $k = strtolower(trim((string) preg_replace('/[\s-]+/', '_', $status)));
    $map = [
        'pending' => 'pending', 'requested' => 'pending', 'new' => 'pending',
        'in_progress' => 'in_progress', 'processing' => 'in_progress', 'sample_collected' => 'in_progress',
        'completed' => 'completed', 'done' => 'completed', 'resulted' => 'completed',
        'cancelled' => 'cancelled', 'canceled' => 'cancelled', 'rejected' => 'cancelled',
    ];
    return $map[$k] ?? 'other';
}

function status_badge(string $normalised, string $raw): string
{
    $labels = ['pending' => 'Pending', 'in_progress' => 'In progress', 'completed' => 'Completed', 'cancelled' => 'Cancelled'];
    $label  = $labels[$normalised] ?? ($raw !== '' ? ucfirst($raw) : 'Not set');
    return '<span class="badge badge-' . e($normalised) . '">' . e($label) . '</span>';
}

function view_btn(?string $link, string $label = 'View details'): string
{
    if ($link) {
        return '<a href="' . e($link) . '" class="btn-sm">' . e($label) . '</a>';
    }
    return '<span class="btn-sm disabled" aria-disabled="true">' . e($label) . '</span>';
}

/* ---------------------------------------------------------
   Lab operator profile (existing tables: users, lab_operators)
   --------------------------------------------------------- */
$operator      = ['id' => null, 'laboratory_name' => '', 'designation' => '', 'employee_id' => ''];
$email         = '';
$profileFound  = false;

try {
    $s = $conn->prepare('SELECT id, laboratory_name, designation, employee_id FROM lab_operators WHERE user_id = ? LIMIT 1');
    $s->bind_param('i', $userId);
    $s->execute();
    $row = $s->get_result()->fetch_assoc();
    $s->close();
    if ($row) {
        $operator     = $row;
        $profileFound = true;
    }

    $s = $conn->prepare('SELECT email FROM users WHERE id = ? LIMIT 1');
    $s->bind_param('i', $userId);
    $s->execute();
    $u = $s->get_result()->fetch_assoc();
    $s->close();
    $email = (string) ($u['email'] ?? '');
} catch (Throwable $ex) {
    error_log('MRMS lab operator profile error: ' . $ex->getMessage());
}

/* =========================================================
   INTEGRATION POINTS
   The test-request and test-result tables are not part of the
   known schema, so nothing below queries them. Connect them here.
   ========================================================= */

/**
 * INTEGRATION POINT 1 - Test requests.
 * Return an array of rows with these keys:
 *   patient_name  (string)  patient_id (string)  test_name (string)
 *   requested_at  (string, parseable date)       status    (string)
 *   completed_at  (string, optional)             link      (string|null, details page)
 */
function load_test_requests(mysqli $conn, ?int $operatorId): array
{
    return [];
}

/**
 * INTEGRATION POINT 2 - Notifications for this lab operator.
 * Return rows with: text (string), time (string, parseable date).
 */
function load_notifications(mysqli $conn, int $userId): array
{
    return [];
}

/* INTEGRATION POINT 3 - set to true once the two functions above are implemented. */
$testModuleConnected = false;

$requests = load_test_requests($conn, $operator['id'] !== null ? (int) $operator['id'] : null);
foreach ($requests as $i => $r) {
    $requests[$i]['_raw']    = trim((string) ($r['status'] ?? ''));
    $requests[$i]['_status'] = norm_status($requests[$i]['_raw']);
    $requests[$i]['_ts']     = strtotime((string) ($r['requested_at'] ?? '')) ?: 0;
}
usort($requests, static fn(array $a, array $b): int => $b['_ts'] <=> $a['_ts']);

$notifications = load_notifications($conn, $userId);

$by = static fn(string $st): array => array_values(array_filter($requests, static fn(array $r): bool => $r['_status'] === $st));
$pending    = $by('pending');
$inProgress = $by('in_progress');
$completed  = $by('completed');

$pendingOldestFirst = array_reverse($pending);
$recentRows = array_slice($requests, 0, 8);

$stats = [
    ['clipboard', 'Total Test Requests', count($requests),   'tone-navy'],
    ['hourglass', 'Pending Tests',       count($pending),    'tone-amber'],
    ['activity',  'Tests In Progress',   count($inProgress), 'tone-blue'],
    ['check',     'Completed Tests',     count($completed),  'tone-green'],
];

$nav_items = [
    ['grid',      'Dashboard',              'dashboard.php'],
    ['clipboard', 'Patient Test Requests',  first_existing(['test_requests.php', 'patient_test_requests.php'])],
    ['flask',     'Manage Laboratory Tests', first_existing(['manage_tests.php', 'laboratory_tests.php'])],
    ['upload',    'Upload Test Results',    first_existing(['upload_results.php', 'upload_test_results.php'])],
    ['file',      'Medical Reports',        first_existing(['medical_reports.php', 'reports.php'])],
    ['user',      'My Profile',             first_existing(['profile.php', 'my_profile.php'])],
];

$actions = [
    ['clipboard', 'Review requests',  'See new patient test requests',  $nav_items[1][2]],
    ['upload',    'Upload results',   'Attach results to a completed test', $nav_items[3][2]],
    ['flask',     'Manage tests',     'Edit the laboratory test catalogue', $nav_items[2][2]],
    ['file',      'Medical reports',  'Browse reports on file',         $nav_items[4][2]],
];

$initials  = initials_from($fullName);
$firstName = explode(' ', $fullName)[0];
$labName   = trim((string) $operator['laboratory_name']);
$roleLine  = trim(implode(' · ', array_filter([(string) $operator['designation'], $labName])));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lab Operator Dashboard - MRMS</title>
    <link rel="stylesheet" href="../auth/assets/css/lab-operator-dashboard.css">
</head>
<body>

<div class="app">

    <aside class="sidebar" id="sidebar" aria-label="Sidebar">
        <div class="sidebar-top">
            <a href="../index.php" class="brand" aria-label="MRMS home">
                <span class="brand-mark"><?= icon('pulse', 22) ?></span>
                <span class="brand-text">MRMS<small>Laboratory Portal</small></span>
            </a>
            <button class="icon-btn sidebar-close" id="sidebarClose" type="button" aria-label="Close menu"><?= icon('close', 22) ?></button>
        </div>

        <nav class="side-nav" aria-label="Main">
            <p class="nav-title">Menu</p>
            <?php foreach ($nav_items as $item): ?>
                <?php if ($item[2] !== null): ?>
                    <a href="<?= e($item[2]) ?>" class="nav-link<?= $item[2] === 'dashboard.php' ? ' active' : '' ?>"<?= $item[2] === 'dashboard.php' ? ' aria-current="page"' : '' ?>>
                        <?= icon($item[0]) ?><span><?= e($item[1]) ?></span>
                    </a>
                <?php else: ?>
                    <span class="nav-link disabled" aria-disabled="true">
                        <?= icon($item[0]) ?><span><?= e($item[1]) ?></span><em>Soon</em>
                    </span>
                <?php endif; ?>
            <?php endforeach; ?>
        </nav>

        <div class="sidebar-bottom">
            <?php if ($labName !== ''): ?>
                <div class="lab-card">
                    <span class="lab-card__icon"><?= icon('flask', 18) ?></span>
                    <div><strong><?= e($labName) ?></strong><small><?= e($operator['designation'] !== '' ? $operator['designation'] : 'Laboratory') ?></small></div>
                </div>
            <?php endif; ?>
            <a href="../auth/logout.php" class="nav-link logout"><?= icon('logout') ?><span>Logout</span></a>
        </div>
    </aside>
    <div class="overlay" id="overlay"></div>

    <div class="main">

        <header class="topbar">
            <div class="topbar-left">
                <button class="icon-btn menu-btn" id="menuBtn" type="button" aria-label="Open menu" aria-expanded="false" aria-controls="sidebar"><?= icon('menu', 22) ?></button>
                <div>
                    <h1>Dashboard</h1>
                    <p class="topbar-date"><?= e(date('l, d F Y')) ?></p>
                </div>
            </div>

            <div class="topbar-right">
                <div class="dropdown" data-dropdown>
                    <button class="icon-btn bell" type="button" data-dropdown-toggle aria-label="Notifications" aria-haspopup="true" aria-expanded="false">
                        <?= icon('bell', 22) ?>
                        <?php if (!empty($notifications)): ?><span class="bell-dot"></span><?php endif; ?>
                    </button>
                    <div class="dropdown-menu notif-menu">
                        <div class="dropdown-head">Notifications</div>
                        <?php if (empty($notifications)): ?>
                            <div class="notif-empty">
                                <span class="empty-icon"><?= icon('bell', 22) ?></span>
                                <strong>You're all caught up</strong>
                                <p>New test requests and result reminders will show here.</p>
                            </div>
                        <?php else: ?>
                            <ul class="notif-list">
                                <?php foreach (array_slice($notifications, 0, 6) as $n): ?>
                                    <li><p><?= e($n['text'] ?? '') ?></p><small><?= e(fmt_date((string) ($n['time'] ?? ''), 'd M Y, g:i A')) ?></small></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="dropdown" data-dropdown>
                    <button class="profile profile-btn" type="button" data-dropdown-toggle aria-haspopup="true" aria-expanded="false">
                        <span class="avatar"><?= e($initials) ?></span>
                        <span class="profile-info">
                            <strong><?= e($fullName) ?></strong>
                            <small>Lab Operator</small>
                        </span>
                        <span class="chev"><?= icon('chevron', 16) ?></span>
                    </button>
                    <div class="dropdown-menu profile-menu">
                        <div class="dropdown-head">
                            <strong><?= e($fullName) ?></strong>
                            <small><?= e($email !== '' ? $email : 'Lab Operator') ?></small>
                        </div>
                        <?php if ($nav_items[5][2] !== null): ?>
                            <a href="<?= e($nav_items[5][2]) ?>" class="dropdown-item"><?= icon('user', 18) ?><span>My Profile</span></a>
                        <?php else: ?>
                            <span class="dropdown-item disabled" aria-disabled="true"><?= icon('user', 18) ?><span>My Profile</span><em>Soon</em></span>
                        <?php endif; ?>
                        <a href="../auth/logout.php" class="dropdown-item danger"><?= icon('logout', 18) ?><span>Logout</span></a>
                    </div>
                </div>
            </div>
        </header>

        <main class="content">

            <section class="welcome">
                <div>
                    <h2>Welcome, <?= e($firstName) ?></h2>
                    <p>Here is the current state of test requests in your laboratory.</p>
                </div>
                <?php if ($roleLine !== '' || $operator['employee_id'] !== ''): ?>
                    <dl class="welcome-meta">
                        <?php if ($roleLine !== ''): ?><div><dt>Role</dt><dd><?= e($roleLine) ?></dd></div><?php endif; ?>
                        <?php if ($operator['employee_id'] !== ''): ?><div><dt>Employee ID</dt><dd><?= e($operator['employee_id']) ?></dd></div><?php endif; ?>
                    </dl>
                <?php endif; ?>
            </section>

            <?php if (!$profileFound): ?>
                <div class="notice" role="status">Your laboratory profile was not found. Please contact an administrator.</div>
            <?php endif; ?>
            <?php if (!$testModuleConnected): ?>
                <div class="notice notice--info" role="status">Test request tracking is not connected yet, so counts and lists below are empty.</div>
            <?php endif; ?>

            <section aria-labelledby="overviewTitle">
                <h3 class="section-title" id="overviewTitle">Overview</h3>
                <div class="stats stats-4">
                    <?php foreach ($stats as $s): ?>
                        <article class="stat <?= e($s[3]) ?>">
                            <div class="stat-icon"><?= icon($s[0], 24) ?></div>
                            <div class="stat-body">
                                <span class="stat-label"><?= e($s[1]) ?></span>
                                <span class="stat-value"><?= number_format((int) $s[2]) ?></span>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="panel block" aria-labelledby="recentTitle">
                <div class="panel-head">
                    <h3 id="recentTitle">Recent test requests</h3>
                    <?= view_btn($nav_items[1][2], 'View all') ?>
                </div>

                <?php if (empty($recentRows)): ?>
                    <div class="empty">
                        <span class="empty-icon"><?= icon('clipboard', 26) ?></span>
                        <strong>No test requests yet</strong>
                        <p>Tests requested for patients will be listed here with their status.</p>
                    </div>
                <?php else: ?>
                    <div class="table-tools">
                        <div class="tabs" role="group" aria-label="Filter by status">
                            <button type="button" class="tab is-active" data-filter="all" aria-pressed="true">All <span><?= count($recentRows) ?></span></button>
                            <button type="button" class="tab" data-filter="pending" aria-pressed="false">Pending</button>
                            <button type="button" class="tab" data-filter="in_progress" aria-pressed="false">In progress</button>
                            <button type="button" class="tab" data-filter="completed" aria-pressed="false">Completed</button>
                        </div>
                        <label class="search">
                            <?= icon('search', 18) ?>
                            <span class="sr-only">Search test requests</span>
                            <input type="search" id="requestSearch" placeholder="Search patient or test" autocomplete="off">
                        </label>
                    </div>
                    <div class="table-wrap">
                        <table class="data-table">
                            <thead>
                                <tr><th>Patient name</th><th>Patient ID</th><th>Test name</th><th>Request date</th><th>Status</th><th class="col-action">Action</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentRows as $r): ?>
                                    <?php
                                    $pName = (string) ($r['patient_name'] ?? '');
                                    $pId   = (string) ($r['patient_id'] ?? '');
                                    $tName = (string) ($r['test_name'] ?? '');
                                    $search = strtolower($pName . ' ' . $pId . ' ' . $tName);
                                    ?>
                                    <tr data-request-row data-status="<?= e($r['_status']) ?>" data-search="<?= e($search) ?>">
                                        <td data-label="Patient name"><span class="person"><span class="mini-avatar"><?= e(initials_from($pName)) ?></span><strong><?= e($pName !== '' ? $pName : 'Unknown') ?></strong></span></td>
                                        <td data-label="Patient ID"><?= e($pId !== '' ? $pId : '–') ?></td>
                                        <td data-label="Test name"><?= e($tName !== '' ? $tName : '–') ?></td>
                                        <td data-label="Request date"><?= e($r['_ts'] ? fmt_date((string) $r['requested_at']) : '–') ?></td>
                                        <td data-label="Status"><?= status_badge($r['_status'], $r['_raw']) ?></td>
                                        <td data-label="Action" class="col-action"><?= view_btn($r['link'] ?? null) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="empty" data-filter-empty hidden>
                        <span class="empty-icon"><?= icon('search', 26) ?></span>
                        <strong>No matching requests</strong>
                        <p>Try a different status or search term.</p>
                    </div>
                <?php endif; ?>
            </section>

            <div class="two-col even">

                <section class="panel" aria-labelledby="pendingTitle">
                    <div class="panel-head">
                        <h3 id="pendingTitle">Pending test requests</h3>
                        <?= view_btn($nav_items[1][2], 'View all') ?>
                    </div>
                    <?php if (empty($pendingOldestFirst)): ?>
                        <div class="empty">
                            <span class="empty-icon"><?= icon('hourglass', 26) ?></span>
                            <strong>Nothing waiting</strong>
                            <p>Requests waiting to be processed will appear here, oldest first.</p>
                        </div>
                    <?php else: ?>
                        <ul class="list">
                            <?php foreach (array_slice($pendingOldestFirst, 0, 5) as $r): ?>
                                <li>
                                    <span class="mini-avatar lg"><?= e(initials_from((string) ($r['patient_name'] ?? ''))) ?></span>
                                    <div class="list-text">
                                        <strong><?= e($r['test_name'] ?? 'Test') ?></strong>
                                        <small><?= e($r['patient_name'] ?? 'Unknown') ?><?= !empty($r['patient_id']) ? ' · ' . e($r['patient_id']) : '' ?> · <?= e($r['_ts'] ? fmt_date((string) $r['requested_at']) : '–') ?></small>
                                    </div>
                                    <?= view_btn($r['link'] ?? null, 'View') ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </section>

                <section class="panel" aria-labelledby="doneTitle">
                    <div class="panel-head">
                        <h3 id="doneTitle">Recently completed tests</h3>
                        <?= view_btn($nav_items[3][2], 'View all') ?>
                    </div>
                    <?php if (empty($completed)): ?>
                        <div class="empty">
                            <span class="empty-icon"><?= icon('check', 26) ?></span>
                            <strong>No completed tests yet</strong>
                            <p>Finished tests and uploaded results will be listed here.</p>
                        </div>
                    <?php else: ?>
                        <ul class="list">
                            <?php foreach (array_slice($completed, 0, 5) as $r): ?>
                                <?php $done = (string) ($r['completed_at'] ?? $r['requested_at'] ?? ''); ?>
                                <li>
                                    <span class="icon-chip"><?= icon('check', 20) ?></span>
                                    <div class="list-text">
                                        <strong><?= e($r['test_name'] ?? 'Test') ?></strong>
                                        <small><?= e($r['patient_name'] ?? 'Unknown') ?> · <?= e($done !== '' ? fmt_date($done) : '–') ?></small>
                                    </div>
                                    <?= view_btn($r['link'] ?? null, 'View') ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </section>

            </div>

            <div class="two-col even">

                <section class="panel" aria-labelledby="actionsTitle">
                    <div class="panel-head"><h3 id="actionsTitle">Quick actions</h3></div>
                    <div class="actions">
                        <?php foreach ($actions as $a): ?>
                            <?php if ($a[3] !== null): ?>
                                <a href="<?= e($a[3]) ?>" class="action">
                                    <span class="action__icon"><?= icon($a[0], 20) ?></span>
                                    <span class="action__text"><strong><?= e($a[1]) ?></strong><small><?= e($a[2]) ?></small></span>
                                </a>
                            <?php else: ?>
                                <span class="action disabled" aria-disabled="true">
                                    <span class="action__icon"><?= icon($a[0], 20) ?></span>
                                    <span class="action__text"><strong><?= e($a[1]) ?></strong><small><?= e($a[2]) ?></small></span>
                                    <em>Soon</em>
                                </span>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </section>

                <section class="panel" aria-labelledby="notifTitle">
                    <div class="panel-head"><h3 id="notifTitle">Notifications</h3></div>
                    <?php if (empty($notifications)): ?>
                        <div class="empty">
                            <span class="empty-icon"><?= icon('bell', 26) ?></span>
                            <strong>No notifications</strong>
                            <p>Alerts about new requests and overdue results will appear here.</p>
                        </div>
                    <?php else: ?>
                        <ul class="list">
                            <?php foreach (array_slice($notifications, 0, 5) as $n): ?>
                                <li>
                                    <span class="icon-chip"><?= icon('bell', 20) ?></span>
                                    <div class="list-text">
                                        <strong><?= e($n['text'] ?? '') ?></strong>
                                        <small><?= e(fmt_date((string) ($n['time'] ?? ''), 'd M Y, g:i A')) ?></small>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </section>

            </div>

        </main>

        <footer class="page-foot">&copy; <?= e(date('Y')) ?> MRMS. Medical Record Management System.</footer>
    </div>
</div>
<script src="../auth/assets/js/lab-operator-dashboard.js"></script>
</body>
</html>