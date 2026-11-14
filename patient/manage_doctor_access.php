<?php
declare(strict_types=1);

/*
 * MRMS - Patient Doctor Access Management
 * File: patient/manage_doctor_access.php
 */

$required_role = 'patient';

require_once __DIR__ . '/../auth/role_guard.php';
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json; charset=utf-8');

function sendResponse(
    int $status,
    string $message
): void {
    http_response_code($status);

    echo json_encode([
        'success' => $status >= 200 && $status < 300,
        'message' => $message
    ]);

    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(405, 'POST method required.');
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    sendResponse(500, 'Database connection unavailable.');
}

$conn->set_charset('utf8mb4');

$userId = (int) $_SESSION['user_id'];

$doctorId = filter_input(
    INPUT_POST,
    'doctor_id',
    FILTER_VALIDATE_INT
);

$action = $_POST['action'] ?? '';

if ($userId <= 0 || !$doctorId || $doctorId <= 0) {
    sendResponse(400, 'Invalid user or doctor ID.');
}

if (!in_array($action, ['grant', 'revoke'], true)) {
    sendResponse(400, 'Invalid access action.');
}

try {

    // 1. Get logged-in patient's profile ID

    $stmt = $conn->prepare(
        "SELECT id
         FROM patients
         WHERE user_id = ?
         LIMIT 1"
    );

    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $stmt->bind_result($patientId);

    $patientFound = $stmt->fetch();
    $stmt->close();

    if (!$patientFound) {
        sendResponse(404, 'Patient profile not found.');
    }

    $patientId = (int) $patientId;

    // 2. Grant medical record access

    if ($action === 'grant') {

        // Patient must have a confirmed appointment
        // with this doctor.

        $stmt = $conn->prepare(
            "SELECT id
             FROM appointments
             WHERE patient_id = ?
               AND doctor_id = ?
               AND status = 'confirmed'
             LIMIT 1"
        );

        $stmt->bind_param(
            "ii",
            $patientId,
            $doctorId
        );

        $stmt->execute();
        $stmt->store_result();

        $hasAppointment = $stmt->num_rows > 0;
        $stmt->close();

        if (!$hasAppointment) {
            sendResponse(
                403,
                'A confirmed appointment is required before granting access.'
            );
        }

        // Existing revoked access will be reactivated.
        // Access remains active until patient revokes it.

        $stmt = $conn->prepare(
            "INSERT INTO patient_doctor_access
                (
                    patient_id,
                    doctor_id,
                    access_status,
                    granted_at,
                    revoked_at,
                    expires_at
                )
             VALUES (?, ?, 'active', CURRENT_TIMESTAMP, NULL, NULL)

             ON DUPLICATE KEY UPDATE
                access_status = 'active',
                granted_at = CURRENT_TIMESTAMP,
                revoked_at = NULL,
                expires_at = NULL"
        );

        $stmt->bind_param(
            "ii",
            $patientId,
            $doctorId
        );

        $stmt->execute();
        $stmt->close();

        sendResponse(
            200,
            'Medical record access granted successfully.'
        );
    }

    // 3. Revoke medical record access

    if ($action === 'revoke') {

        $stmt = $conn->prepare(
            "UPDATE patient_doctor_access
             SET
                access_status = 'revoked',
                revoked_at = CURRENT_TIMESTAMP,
                expires_at = NULL
             WHERE patient_id = ?
               AND doctor_id = ?
               AND access_status = 'active'"
        );

        $stmt->bind_param(
            "ii",
            $patientId,
            $doctorId
        );

        $stmt->execute();
        $stmt->close();

        sendResponse(
            200,
            'Doctor medical record access revoked successfully.'
        );
    }

} catch (Throwable $e) {

    error_log(
        'MRMS Doctor Access Error: ' . $e->getMessage()
    );

    sendResponse(
        500,
        'Unable to update medical record access.'
    );
}
