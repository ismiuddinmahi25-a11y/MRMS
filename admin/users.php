
<?php


$required_role = 'admin';

require_once '../auth/role_guard.php';


$admin_name = $_SESSION['full_name'];



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
    'plus'     => '<path d="M12 5v14M5 12h14"/>',
    'search'   => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
    'check'    => '<circle cx="12" cy="12" r="9"/><path d="m8.5 12.5 2.5 2.5 4.5-5"/>',
    'clock'    => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    'ban'      => '<circle cx="12" cy="12" r="9"/><path d="M5.6 5.6l12.8 12.8"/>',
    'info'     => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8v.5"/>',
];

function icon($name, $size = 20)
{
    global $icons;

    return '<svg class="ico" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $icons[$name] . '</svg>';
}

// Admin initials
$initials = '';

foreach (array_slice(preg_split('/\s+/', trim($admin_name)), 0, 2) as $part) {
    $initials .= mb_strtoupper(mb_substr($part, 0, 1));
}

if ($initials === '') {
    $initials = 'A';
}

// Sidebar navigation
$nav_items = [
    ['grid',     'Dashboard',       'dashboard.php'],
    ['users',    'Users',           'users.php'],
    ['user',     'Patients',        null],
    ['doctor',   'Doctors',         null],
    ['flask',    'Lab Operators',   null],
    ['calendar', 'Appointments',    null],
    ['file',     'Medical Reports', null],
];

// Summary cards
$summary_cards = [
    ['users', 'Total Users',     'statTotal',     'tone-navy'],
    ['check', 'Active Users',    'statActive',    'tone-green'],
    ['clock', 'Pending Users',   'statPending',   'tone-teal'],
    ['ban',   'Suspended Users', 'statSuspended', 'tone-blue'],
];

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>User Management - MRMS</title>

    <link rel="stylesheet" href="../auth/assets/css/dashboard.css">
    <link rel="stylesheet" href="../auth/assets/css/users.css">
</head>

<body>

<div class="app">

    <!-- SIDEBAR -->
    <aside class="sidebar" id="sidebar" aria-label="Sidebar">

        <div class="sidebar-top">

            <a href="../index.php" class="brand" aria-label="MRMS home">

                <span class="brand-mark">
                    <?= icon('pulse', 22) ?>
                </span>

                <span class="brand-text">
                    MRMS
                    <small>Admin Console</small>
                </span>

            </a>

            <button class="icon-btn sidebar-close" id="sidebarClose" aria-label="Close menu">
                <?= icon('close', 22) ?>
            </button>

        </div>

        <nav class="side-nav">

            <p class="nav-title">Menu</p>

            <?php foreach ($nav_items as $item): ?>

                <?php if ($item[2] !== null): ?>

                    <a href="<?= htmlspecialchars($item[2]) ?>"
                       class="nav-link<?= $item[2] === 'users.php' ? ' active' : '' ?>">

                        <?= icon($item[0]) ?>

                        <span><?= htmlspecialchars($item[1]) ?></span>

                    </a>

                <?php else: ?>

                    <span class="nav-link disabled" aria-disabled="true">

                        <?= icon($item[0]) ?>

                        <span><?= htmlspecialchars($item[1]) ?></span>

                        <em>Soon</em>

                    </span>

                <?php endif; ?>

            <?php endforeach; ?>

        </nav>

        <div class="sidebar-bottom">

            <a href="../auth/logout.php" class="nav-link logout">

                <?= icon('logout') ?>

                <span>Logout</span>

            </a>

        </div>

    </aside>

    <div class="overlay" id="overlay"></div>

    <!-- MAIN -->
    <div class="main">

        <!-- TOPBAR -->
        <header class="topbar">

            <div class="topbar-left">

                <button class="icon-btn menu-btn" id="menuBtn"
                        aria-label="Open menu" aria-expanded="false">

                    <?= icon('menu', 22) ?>

                </button>

                <div>

                    <h1>User Management</h1>

                    <p class="topbar-date">
                        <?= date('l, d F Y') ?>
                    </p>

                </div>

            </div>

            <div class="topbar-right">

                <div class="profile">

                    <span class="avatar">
                        <?= htmlspecialchars($initials) ?>
                    </span>

                    <span class="profile-info">

                        <strong>
                            <?= htmlspecialchars($admin_name) ?>
                        </strong>

                        <small>Administrator</small>

                    </span>

                </div>

                <a href="../auth/logout.php" class="btn-logout">

                    <?= icon('logout', 18) ?>

                    <span>Logout</span>

                </a>

            </div>

        </header>

        <main class="content">

            <!-- DATABASE CONNECTION NOTICE -->
            <div class="demo-note" role="note">

                <?= icon('info', 18) ?>

                <span>
                    User data is connected to the database.
                    Changes are saved to the system.
                </span>

            </div>

            <!-- SUMMARY CARDS -->
            <section aria-label="User summary">

                <div class="stats stats-4">

                    <?php foreach ($summary_cards as $c): ?>

                        <article class="stat <?= $c[3] ?>">

                            <div class="stat-icon">
                                <?= icon($c[0], 24) ?>
                            </div>

                            <div class="stat-body">

                                <span class="stat-label">
                                    <?= htmlspecialchars($c[1]) ?>
                                </span>

                                <span class="stat-value" id="<?= $c[2] ?>">
                                    0
                                </span>

                            </div>

                        </article>

                    <?php endforeach; ?>

                </div>

            </section>

            <!-- USERS PANEL -->
            <section class="panel users-panel" aria-labelledby="usersTitle">

                <div class="panel-head">

                    <div>

                        <h3 id="usersTitle">All users</h3>

                        <p class="panel-sub">
                            Search, filter and manage system accounts.
                        </p>

                    </div>

                    <button type="button" class="btn btn-primary" id="addUserBtn">

                        <?= icon('plus', 18) ?>

                        <span>Add New User</span>

                    </button>

                </div>

                <!-- SEARCH AND FILTERS -->
                <div class="toolbar">

                    <div class="search-box">

                        <?= icon('search', 18) ?>

                        <input
                            type="search"
                            id="userSearch"
                            placeholder="Search by name, email or ID"
                            aria-label="Search users"
                            autocomplete="off"
                        >

                    </div>

                    <select id="roleFilter" class="filter-select"
                            aria-label="Filter by role">

                        <option value="all">All roles</option>
                        <option value="admin">Admin</option>
                        <option value="doctor">Doctor</option>
                        <option value="lab_operator">Lab Operator</option>
                        <option value="patient">Patient</option>

                    </select>

                    <select id="statusFilter" class="filter-select"
                            aria-label="Filter by account status">

                        <option value="all">All statuses</option>
                        <option value="active">Active</option>
                        <option value="pending">Pending</option>
                        <option value="suspended">Suspended</option>
                        <option value="rejected">Rejected</option>

                    </select>

                    <button type="button" class="btn btn-ghost" id="resetFilters">
                        Reset
                    </button>

                </div>

                <!-- TABLE -->
                <div class="table-wrap" id="tableWrap">

                    <table class="data-table">

                        <thead>

                            <tr>

                                <th scope="col">ID</th>
                                <th scope="col">Full Name</th>
                                <th scope="col">Email</th>
                                <th scope="col">Role</th>
                                <th scope="col">Account Status</th>
                                <th scope="col">Created Date</th>
                                <th scope="col" class="col-actions">Actions</th>

                            </tr>

                        </thead>

                        <tbody id="userTableBody"></tbody>

                    </table>

                </div>

                <!-- EMPTY STATE -->
                <div class="empty-state" id="emptyState" hidden>

                    <span class="empty-icon">
                        <?= icon('search', 26) ?>
                    </span>

                    <strong>No users found</strong>

                    <p>
                        Try a different search or clear the filters.
                    </p>

                    <button type="button" class="btn btn-ghost" id="emptyReset">
                        Clear filters
                    </button>

                </div>

                <!-- PAGINATION -->
                <div class="pagination-bar" id="paginationBar">

                    <p class="result-info" id="resultInfo" aria-live="polite"></p>

                    <nav class="pagination" id="pagination" aria-label="Pagination"></nav>

                </div>

            </section>

        </main>

        <footer class="page-foot">

            &copy; <?= date('Y') ?> MRMS. Medical Record Management System.

        </footer>

    </div>

</div>

<!-- ADD / EDIT USER MODAL -->
<div class="modal" id="userModal" hidden>

    <div class="modal-backdrop" data-close></div>

    <div class="modal-card" role="dialog" aria-modal="true"
         aria-labelledby="modalTitle">

        <div class="modal-head">

            <h3 id="modalTitle">Add New User</h3>

            <button type="button" class="icon-btn" data-close aria-label="Close">
                <?= icon('close', 20) ?>
            </button>

        </div>

        <form id="userForm" novalidate>

            <div class="form-error" id="formError" role="alert" hidden></div>

            <!-- FULL NAME -->
            <div class="form-field">

                <label for="fullName">Full name</label>

                <input
                    type="text"
                    id="fullName"
                    name="full_name"
                    placeholder="Enter full name"
                    autocomplete="off"
                >

            </div>

            <!-- EMAIL -->
            <div class="form-field">

                <label for="userEmail">Email address</label>

                <input
                    type="email"
                    id="userEmail"
                    name="email"
                    placeholder="name@example.com"
                    autocomplete="off"
                >

            </div>

            <!-- PASSWORD -->
            <div class="form-field" id="passwordField">

                <label for="userPassword">Password</label>

                <input
                    type="password"
                    id="userPassword"
                    name="password"
                    placeholder="Enter password (minimum 8 characters)"
                    autocomplete="new-password"
                >

            </div>

            <!-- ROLE AND STATUS -->
            <div class="form-grid">

                <div class="form-field">

                    <label for="userRole">Role</label>

                    <select id="userRole" name="role">

                        <option value="">Select role</option>
                        <option value="admin">Admin</option>
                        <option value="doctor">Doctor</option>
                        <option value="lab_operator">Lab Operator</option>
                        <option value="patient">Patient</option>

                    </select>

                </div>

                <div class="form-field">

                    <label for="userStatus">Account status</label>

                    <select id="userStatus" name="account_status">

                        <option value="active">Active</option>
                        <option value="pending">Pending</option>
                        <option value="suspended">Suspended</option>
                        <option value="rejected">Rejected</option>

                    </select>

                </div>

            </div>

            <!-- MODAL FOOTER -->
            <div class="modal-foot">

                <button type="button" class="btn btn-ghost" data-close>
                    Cancel
                </button>

                <button type="submit" class="btn btn-primary" id="saveUserBtn">
                    Save User
                </button>

            </div>

        </form>

    </div>

</div>

<!-- TOAST -->
<div class="toast" id="toast" role="status" aria-live="polite"></div>

<!-- JAVASCRIPT -->
<script src="../auth/assets/js/dashboard.js"></script>
<script src="../auth/assets/js/users.js"></script>

</body>
</html>
