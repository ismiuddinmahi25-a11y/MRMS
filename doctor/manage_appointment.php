<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| MRMS - Doctor Appointment Management API
|--------------------------------------------------------------------------
*/

$required_role = 'doctor';

require_once __DIR__ . '/../auth/role_guard.php';
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json; charset=utf-8');

function response(int $status, array $data): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    response(405, [
        'success' => false,
        'message' => 'POST method required.'
    ]);
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    response(500, [
        'success' => false,
        'message' => 'Database connection unavailable.'
    ]);
}

$conn->set_charset('utf8mb4');

$userId = (int)($_SESSION['user_id'] ?? 0);

$appointmentId = filter_var(
    $_POST['appointment_id'] ?? null,
    FILTER_VALIDATE_INT
);

$action = strtolower(trim($_POST['action'] ?? ''));

$allowedActions = [
    'accept' => 'confirmed',
    'reject' => 'rejected'
];

if (
    $userId <= 0 ||
    !$appointmentId ||
    $appointmentId <= 0 ||
    !isset($allowedActions[$action])
) {
    response(422, [
        'success' => false,
        'message' => 'Invalid appointment information.'
    ]);
}

$newStatus = $allowedActions[$action];

$notes = trim($_POST['doctor_notes'] ?? '');

if (strlen($notes) > 5000) {
    response(422, [
        'success' => false,
        'message' => 'Doctor notes are too long.'
    ]);
}

/* Find logged-in doctor's profile */

$stmt = $conn->prepare(
    "SELECT id
     FROM doctors
     WHERE user_id = ?
     AND LOWER(verification_status) = 'approved'
     LIMIT 1"
);

if (!$stmt) {
    response(500, [
        'success' => false,
        'message' => 'Doctor verification failed.'
    ]);
}

$stmt->bind_param('i', $userId);
$stmt->execute();

$result = $stmt->get_result();
$doctor = $result->fetch_assoc();

$stmt->close();

if (!$doctor) {
    response(403, [
        'success' => false,
        'message' => 'Approved doctor profile not found.'
    ]);
}

$doctorId = (int)$doctor['id'];

/* Update only this doctor's pending appointment */

$stmt = $conn->prepare(
    "UPDATE appointments
     SET status = ?, doctor_notes = ?
     WHERE id = ?
       AND doctor_id = ?
       AND status = 'pending'"
);

if (!$stmt) {
    response(500, [
        'success' => false,
        'message' => 'Unable to prepare appointment update.'
    ]);
}

$stmt->bind_param(
    'ssii',
    $newStatus,
    $notes,
    $appointmentId,
    $doctorId
);

if (!$stmt->execute()) {
    $stmt->close();

    response(500, [
        'success' => false,
        'message' => 'Appointment update failed.'
    ]);
}

$affectedRows = $stmt->affected_rows;
$stmt->close();

if ($affectedRows !== 1) {
    response(409, [
        'success' => false,
        'message' => 'Appointment not found, already processed, or not assigned to you.'
    ]);
}

response(200, [
    'success' => true,
    'message' => $action === 'accept'
        ? 'Appointment confirmed successfully.'
        : 'Appointment rejected successfully.',
    'appointment_id' => $appointmentId,
    'status' => $newStatus
]);
