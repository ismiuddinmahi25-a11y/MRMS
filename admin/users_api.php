<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$required_role = 'admin';

require_once __DIR__ . '/../auth/role_guard.php';
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json; charset=utf-8');

function respond($success, $message, $data = null) {
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data
    ]);
    exit;
}

$action = $_GET['action'] ?? '';

$allowedRoles = [
    'admin',
    'doctor',
    'lab_operator',
    'patient'
];

$allowedStatuses = [
    'active',
    'pending',
    'suspended',
    'rejected'
];


// ========================================
// GET: FETCH ALL USERS
// ========================================

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'list') {

    $sql = "SELECT
                u.id,
                u.full_name,
                u.email,
                u.role,
                u.account_status,
                u.created_at,
                d.verification_status
            FROM users u
            LEFT JOIN doctors d ON d.user_id = u.id
            ORDER BY u.id DESC";

    $result = mysqli_query($conn, $sql);

    if (!$result) {
        respond(false, 'Failed to fetch users.');
    }

    $users = [];

    while ($row = mysqli_fetch_assoc($result)) {
        $users[] = $row;
    }

    respond(true, 'Users fetched successfully.', $users);
}


// ========================================
// POST REQUESTS
// ========================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $input = json_decode(file_get_contents('php://input'), true);

    if (!is_array($input)) {
        respond(false, 'Invalid request data.');
    }


    // ====================================
    // ADD NEW USER
    // ====================================

    if ($action === 'add') {

        $name = trim($input['full_name'] ?? '');
        $email = trim($input['email'] ?? '');
        $password = $input['password'] ?? '';
        $role = $input['role'] ?? '';
        $status = $input['account_status'] ?? 'active';

        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            respond(false, 'Enter a valid name and email.');
        }

        if (strlen($password) < 8) {
            respond(false, 'Password must contain at least 8 characters.');
        }

        if (
            !in_array($role, $allowedRoles, true) ||
            !in_array($status, $allowedStatuses, true)
        ) {
            respond(false, 'Invalid role or account status.');
        }

        $check = mysqli_prepare(
            $conn,
            "SELECT id FROM users WHERE email = ?"
        );

        mysqli_stmt_bind_param($check, "s", $email);
        mysqli_stmt_execute($check);
        mysqli_stmt_store_result($check);

        if (mysqli_stmt_num_rows($check) > 0) {
            respond(false, 'Email already exists.');
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);

        $stmt = mysqli_prepare(
            $conn,
            "INSERT INTO users
            (full_name, email, password_hash, role, account_status)
            VALUES (?, ?, ?, ?, ?)"
        );

        mysqli_stmt_bind_param(
            $stmt,
            "sssss",
            $name,
            $email,
            $hash,
            $role,
            $status
        );

        if (mysqli_stmt_execute($stmt)) {
            respond(true, 'User added successfully.');
        }

        respond(false, 'Failed to add user.');
    }


    // ====================================
    // UPDATE USER
    // ====================================

    if ($action === 'update') {

        $id = (int)($input['id'] ?? 0);
        $name = trim($input['full_name'] ?? '');
        $email = trim($input['email'] ?? '');
        $role = $input['role'] ?? '';
        $status = $input['account_status'] ?? '';

        if (
            $id <= 0 ||
            $name === '' ||
            !filter_var($email, FILTER_VALIDATE_EMAIL)
        ) {
            respond(false, 'Invalid user information.');
        }

        if (
            !in_array($role, $allowedRoles, true) ||
            !in_array($status, $allowedStatuses, true)
        ) {
            respond(false, 'Invalid role or status.');
        }

        if (
            $id === (int)$_SESSION['user_id'] &&
            ($role !== 'admin' || $status !== 'active')
        ) {
            respond(false, 'You cannot change your own admin access.');
        }

        $emailCheck = mysqli_prepare(
            $conn,
            "SELECT id FROM users WHERE email = ? AND id != ?"
        );

        mysqli_stmt_bind_param($emailCheck, "si", $email, $id);
        mysqli_stmt_execute($emailCheck);
        mysqli_stmt_store_result($emailCheck);

        if (mysqli_stmt_num_rows($emailCheck) > 0) {
            respond(false, 'Email already exists.');
        }

        $stmt = mysqli_prepare(
            $conn,
            "UPDATE users
             SET full_name = ?,
                 email = ?,
                 role = ?,
                 account_status = ?
             WHERE id = ?"
        );

        mysqli_stmt_bind_param(
            $stmt,
            "ssssi",
            $name,
            $email,
            $role,
            $status,
            $id
        );

        if (mysqli_stmt_execute($stmt)) {
            respond(true, 'User updated successfully.');
        }

        respond(false, 'Failed to update user.');
    }


    // ====================================
    // PATIENT AND LAB OPERATOR APPROVAL
    // ====================================

    if ($action === 'approval') {

        $id = (int)($input['id'] ?? 0);
        $decision = $input['decision'] ?? '';

        if (
            $id <= 0 ||
            !in_array($decision, ['approve', 'reject'], true)
        ) {
            respond(false, 'Invalid approval request.');
        }

        // Find pending patient or lab operator

        $check = mysqli_prepare(
            $conn,
            "SELECT id, role, account_status
             FROM users
             WHERE id = ?
             AND role IN ('patient', 'lab_operator')
             LIMIT 1"
        );

        mysqli_stmt_bind_param($check, "i", $id);
        mysqli_stmt_execute($check);

        $result = mysqli_stmt_get_result($check);
        $user = mysqli_fetch_assoc($result);

        if (!$user) {
            respond(false, 'Patient or Lab Operator account not found.');
        }

        $role = $user['role'];

        if ($user['account_status'] !== 'pending') {
            respond(false, 'This account is no longer pending.');
        }

        // Determine new account status

        $newStatus = ($decision === 'approve')
            ? 'active'
            : 'rejected';

        // Update account status

        $stmt = mysqli_prepare(
            $conn,
            "UPDATE users
             SET account_status = ?
             WHERE id = ?
             AND role IN ('patient', 'lab_operator')
             AND account_status = 'pending'"
        );

        mysqli_stmt_bind_param($stmt, "si", $newStatus, $id);

        mysqli_stmt_execute($stmt);

        if (mysqli_stmt_affected_rows($stmt) === 1) {

            $roleName = ($role === 'lab_operator')
                ? 'Lab Operator'
                : 'Patient';

            $message = ($decision === 'approve')
                ? $roleName . ' approved successfully.'
                : $roleName . ' rejected successfully.';

            respond(true, $message, [
                'id' => $id,
                'role' => $role,
                'account_status' => $newStatus
            ]);
        }

        respond(false, 'Account approval could not be completed.');
    }


    // ====================================
    // DOCTOR VERIFICATION APPROVAL
    // ====================================

    if ($action === 'doctor_approval') {

        $id = (int)($input['id'] ?? 0);
        $decision = $input['decision'] ?? '';

        if (
            $id <= 0 ||
            !in_array($decision, ['approve', 'reject'], true)
        ) {
            respond(false, 'Invalid doctor verification request.');
        }

        $check = mysqli_prepare(
            $conn,
            "SELECT
                u.id,
                u.role,
                d.verification_status
             FROM users u
             INNER JOIN doctors d ON d.user_id = u.id
             WHERE u.id = ?
             LIMIT 1"
        );

        mysqli_stmt_bind_param($check, "i", $id);
        mysqli_stmt_execute($check);

        $result = mysqli_stmt_get_result($check);
        $doctor = mysqli_fetch_assoc($result);

        if (!$doctor) {
            respond(false, 'Doctor profile not found.');
        }

        if ($doctor['role'] !== 'doctor') {
            respond(false, 'This account is not a doctor account.');
        }

        if ($doctor['verification_status'] !== 'pending') {
            respond(false, 'This doctor verification is no longer pending.');
        }

        $newVerificationStatus = ($decision === 'approve')
            ? 'approved'
            : 'rejected';

        $stmt = mysqli_prepare(
            $conn,
            "UPDATE doctors
             SET verification_status = ?
             WHERE user_id = ?
             AND verification_status = 'pending'"
        );

        mysqli_stmt_bind_param(
            $stmt,
            "si",
            $newVerificationStatus,
            $id
        );

        mysqli_stmt_execute($stmt);

        if (mysqli_stmt_affected_rows($stmt) === 1) {

            $message = ($decision === 'approve')
                ? 'Doctor verification approved successfully.'
                : 'Doctor verification rejected successfully.';

            respond(true, $message);
        }

        respond(false, 'Doctor verification could not be completed.');
    }


    // ====================================
    // CHANGE ACCOUNT STATUS
    // ====================================

    if ($action === 'status') {

        $id = (int)($input['id'] ?? 0);
        $status = $input['account_status'] ?? '';

        if (
            $id <= 0 ||
            !in_array($status, $allowedStatuses, true)
        ) {
            respond(false, 'Invalid status request.');
        }

        if (
            $id === (int)$_SESSION['user_id'] &&
            $status !== 'active'
        ) {
            respond(false, 'You cannot deactivate your own account.');
        }

        $stmt = mysqli_prepare(
            $conn,
            "UPDATE users
             SET account_status = ?
             WHERE id = ?"
        );

        mysqli_stmt_bind_param($stmt, "si", $status, $id);

        if (mysqli_stmt_execute($stmt)) {
            respond(true, 'Account status updated.');
        }

        respond(false, 'Failed to update account status.');
    }


    // ====================================
    // DELETE USER
    // ====================================

    if ($action === 'delete') {

        $id = (int)($input['id'] ?? 0);

        if ($id <= 0) {
            respond(false, 'Invalid user ID.');
        }

        if ($id === (int)$_SESSION['user_id']) {
            respond(false, 'You cannot delete your own account.');
        }

        $check = mysqli_prepare(
            $conn,
            "SELECT role, account_status
             FROM users
             WHERE id = ?"
        );

        mysqli_stmt_bind_param($check, "i", $id);
        mysqli_stmt_execute($check);

        $result = mysqli_stmt_get_result($check);
        $target = mysqli_fetch_assoc($result);

        if (!$target) {
            respond(false, 'User not found.');
        }

        // Protect last active admin

        if (
            $target['role'] === 'admin' &&
            $target['account_status'] === 'active'
        ) {

            $adminCheck = mysqli_query(
                $conn,
                "SELECT COUNT(*) AS total
                 FROM users
                 WHERE role = 'admin'
                 AND account_status = 'active'"
            );

            $adminCount = mysqli_fetch_assoc($adminCheck);

            if ((int)$adminCount['total'] <= 1) {
                respond(false, 'Cannot delete the last active admin.');
            }
        }

        $stmt = mysqli_prepare(
            $conn,
            "DELETE FROM users WHERE id = ?"
        );

        mysqli_stmt_bind_param($stmt, "i", $id);

        if (mysqli_stmt_execute($stmt)) {
            respond(true, 'User deleted successfully.');
        }

        respond(false, 'Failed to delete user.');
    }

}

respond(false, 'Invalid API action.');

