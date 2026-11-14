
<?php

session_start();

require_once '../config/db.php';

// Only accept POST requests
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    exit("Invalid request. Please submit the login form.");
}

// Get login information
$email = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';

// Validate empty fields
if (empty($email) || empty($password)) {
    exit("Email and password are required.");
}

// Find user by email
$sql = "SELECT id, full_name, email, password_hash, role, account_status
        FROM users
        WHERE email = ?
        LIMIT 1";

$stmt = mysqli_prepare($conn, $sql);

if (!$stmt) {
    exit("Database error. Please try again.");
}

mysqli_stmt_bind_param($stmt, "s", $email);
mysqli_stmt_execute($stmt);

mysqli_stmt_bind_result(
    $stmt,
    $user_id,
    $full_name,
    $user_email,
    $password_hash,
    $role,
    $account_status
);

if (!mysqli_stmt_fetch($stmt)) {
    mysqli_stmt_close($stmt);
    exit("Invalid email or password.");
}

mysqli_stmt_close($stmt);

// Verify password
if (!password_verify($password, $password_hash)) {
    exit("Invalid email or password.");
}

// Check account status
if ($account_status !== 'active') {

    if ($account_status === 'pending') {
        exit("Your account is waiting for admin approval.");
    }

    if ($account_status === 'rejected') {
        exit("Your account registration has been rejected.");
    }

    if ($account_status === 'suspended') {
        exit("Your account has been suspended.");
    }

    exit("Your account cannot access the system.");
}

// Regenerate session ID for security
session_regenerate_id(true);

// Store user information in session
$_SESSION['user_id'] = $user_id;
$_SESSION['full_name'] = $full_name;
$_SESSION['email'] = $user_email;
$_SESSION['role'] = $role;

// Update last login time
$update = mysqli_prepare(
    $conn,
    "UPDATE users SET last_login_at = NOW() WHERE id = ?"
);

if ($update) {
    mysqli_stmt_bind_param($update, "i", $user_id);
    mysqli_stmt_execute($update);
    mysqli_stmt_close($update);
}

// Redirect according to user role
$dashboards = [
    'admin' => '../admin/dashboard.php',
    'doctor' => '../doctor/dashboard.php',
    'patient' => '../patient/dashboard.php',
    'lab_operator' => '../lab_operator/dashboard.php'
];

if (isset($dashboards[$role])) {
    header("Location: " . $dashboards[$role]);
    exit;
}

// Invalid role fallback
session_unset();
session_destroy();

exit("Invalid user role.");

?>
