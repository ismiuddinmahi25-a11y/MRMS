<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| MRMS - Book Appointment API
|--------------------------------------------------------------------------
*/

$required_role = 'patient';

require_once __DIR__ . '/../../auth/role_guard.php';
require_once __DIR__ . '/../../config/db.php';

header('Content-Type: application/json; charset=utf-8');

function bookingResponse(int $status, array $data): void
{
    http_response_code($status);
    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
}

/* ---------- Request validation ---------- */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    bookingResponse(405, [
        'success' => false,
        'message' => 'POST method required.'
    ]);
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    bookingResponse(500, [
        'success' => false,
        'message' => 'Database connection unavailable.'
    ]);
}

$conn->set_charset('utf8mb4');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$userId = (int)($_SESSION['user_id'] ?? 0);

if ($userId <= 0) {
    bookingResponse(401, [
        'success' => false,
        'message' => 'Authentication required.'
    ]);
}

/* ---------- Read form data ---------- */

$doctorId = filter_var(
    $_POST['doctor_id'] ?? null,
    FILTER_VALIDATE_INT
);

$appointmentDate = trim($_POST['appointment_date'] ?? '');
$appointmentTime = trim($_POST['appointment_time'] ?? '');
$reason = trim($_POST['reason'] ?? '');

if (
    $doctorId === false ||
    $doctorId === null ||
    $doctorId <= 0 ||
    $appointmentDate === '' ||
    $appointmentTime === '' ||
    $reason === ''
) {
    bookingResponse(422, [
        'success' => false,
        'message' => 'Please provide all required appointment details.'
    ]);
}

/* ---------- Validate date and time ---------- */

$dateObject = DateTime::createFromFormat('!Y-m-d', $appointmentDate);
$timeObject = DateTime::createFromFormat('!H:i', $appointmentTime);

if (
    !$dateObject ||
    $dateObject->format('Y-m-d') !== $appointmentDate ||
    !$timeObject ||
    $timeObject->format('H:i') !== $appointmentTime
) {
    bookingResponse(422, [
        'success' => false,
        'message' => 'Invalid appointment date or time.'
    ]);
}

if ($appointmentDate < date('Y-m-d')) {
    bookingResponse(422, [
        'success' => false,
        'message' => 'Appointment date cannot be in the past.'
    ]);
}

if (strlen($reason) < 5 || strlen($reason) > 1000) {
    bookingResponse(422, [
        'success' => false,
        'message' => 'Reason must contain 5 to 1000 characters.'
    ]);
}

/* ---------- Find patient ---------- */

$patientStmt = $conn->prepare(
    "SELECT id FROM patients WHERE user_id = ? LIMIT 1"
);

if (!$patientStmt) {
    bookingResponse(500, [
        'success' => false,
        'message' => 'Unable to verify patient.'
    ]);
}

$patientStmt->bind_param('i', $userId);

if (!$patientStmt->execute()) {
    $patientStmt->close();

    bookingResponse(500, [
        'success' => false,
        'message' => 'Unable to verify patient.'
    ]);
}

$patientResult = $patientStmt->get_result();
$patient = $patientResult->fetch_assoc();
$patientStmt->close();

if (!$patient) {
    bookingResponse(404, [
        'success' => false,
        'message' => 'Patient profile not found.'
    ]);
}

$patientId = (int)$patient['id'];

/* ---------- Verify doctor ---------- */

$doctorStmt = $conn->prepare(
    "SELECT d.id
     FROM doctors d
     INNER JOIN users u ON d.user_id = u.id
     WHERE d.id = ?
       AND LOWER(d.verification_status) = 'approved'
       AND LOWER(u.account_status) = 'active'
     LIMIT 1"
);

if (!$doctorStmt) {
    bookingResponse(500, [
        'success' => false,
        'message' => 'Unable to verify doctor.'
    ]);
}

$doctorStmt->bind_param('i', $doctorId);

if (!$doctorStmt->execute()) {
    $doctorStmt->close();

    bookingResponse(500, [
        'success' => false,
        'message' => 'Unable to verify doctor.'
    ]);
}

$doctorResult = $doctorStmt->get_result();
$doctor = $doctorResult->fetch_assoc();
$doctorStmt->close();

if (!$doctor) {
    bookingResponse(422, [
        'success' => false,
        'message' => 'Selected doctor is unavailable.'
    ]);
}

/* ---------- Inspect appointment columns ---------- */

$columnResult = $conn->query("SHOW COLUMNS FROM appointments");

if (!$columnResult) {
    bookingResponse(500, [
        'success' => false,
        'message' => 'Unable to inspect appointment table.'
    ]);
}

$existingColumns = [];

while ($column = $columnResult->fetch_assoc()) {
    $existingColumns[] = $column['Field'];
}

$columnResult->free();

/* ---------- Prepare appointment data ---------- */

$appointmentData = [
    'patient_id' => [$patientId, 'i'],
    'doctor_id' => [$doctorId, 'i'],
    'appointment_date' => [$appointmentDate, 's'],
    'appointment_time' => [$appointmentTime, 's'],
    'reason' => [$reason, 's'],
    'status' => ['pending', 's'],
    'created_at' => [date('Y-m-d H:i:s'), 's']
];

$requiredColumns = [
    'patient_id',
    'doctor_id',
    'appointment_date',
    'appointment_time'
];

foreach ($requiredColumns as $column) {
    if (!in_array($column, $existingColumns, true)) {
        bookingResponse(500, [
            'success' => false,
            'message' => "Appointment table is missing required column: $column"
        ]);
    }
}

/* ---------- Build insert query ---------- */

$insertColumns = [];
$insertValues = [];
$types = '';

foreach ($appointmentData as $column => $data) {
    if (in_array($column, $existingColumns, true)) {
        $insertColumns[] = "`$column`";
        $insertValues[] = $data[0];
        $types .= $data[1];
    }
}

$placeholders = implode(
    ', ',
    array_fill(0, count($insertColumns), '?')
);

$sql = "INSERT INTO appointments (" .
       implode(', ', $insertColumns) .
       ") VALUES ($placeholders)";

$insertStmt = $conn->prepare($sql);

if (!$insertStmt) {
    bookingResponse(500, [
        'success' => false,
        'message' => 'Unable to prepare appointment request.'
    ]);
}

/* ---------- Insert appointment ---------- */

$insertStmt->bind_param($types, ...$insertValues);

if (!$insertStmt->execute()) {
    $insertStmt->close();

    bookingResponse(500, [
        'success' => false,
        'message' => 'Appointment booking failed.'
    ]);
}

$appointmentId = $conn->insert_id;

$insertStmt->close();

/* ---------- Success response ---------- */

bookingResponse(201, [
    'success' => true,
    'message' => 'Appointment request submitted successfully.',
    'appointment_id' => $appointmentId,
    'status' => 'pending'
]);
