<?php
declare(strict_types=1);

$required_role = 'doctor';

require_once __DIR__ . '/../auth/role_guard.php';
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json; charset=utf-8');

function sendResponse(int $status, array $data): void
{
    http_response_code($status);
    echo json_encode($data);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(405, [
        'success' => false,
        'message' => 'GET method required.'
    ]);
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    sendResponse(500, [
        'success' => false,
        'message' => 'Database connection unavailable.'
    ]);
}

$conn->set_charset('utf8mb4');

$userId = (int) $_SESSION['user_id'];

try {
    // Find the logged-in doctor's profile ID
    $stmt = $conn->prepare(
        "SELECT id FROM doctors WHERE user_id = ? LIMIT 1"
    );

    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $stmt->bind_result($doctorId);

    $doctorFound = $stmt->fetch();
    $stmt->close();

    if (!$doctorFound) {
        sendResponse(404, [
            'success' => false,
            'message' => 'Doctor profile not found.'
        ]);
    }

    $doctorId = (int) $doctorId;

    // Fetch only patients who have actively granted access
    $stmt = $conn->prepare(
        "SELECT
            p.id AS patient_id,
            u.full_name,
            p.date_of_birth,
            p.gender,
            p.phone,
            p.address,
            a.granted_at
         FROM patient_doctor_access a
         INNER JOIN patients p
            ON p.id = a.patient_id
         INNER JOIN users u
            ON u.id = p.user_id
         WHERE a.doctor_id = ?
           AND a.access_status = 'active'
         ORDER BY a.granted_at DESC"
    );

    $stmt->bind_param("i", $doctorId);
    $stmt->execute();

    $result = $stmt->get_result();

    $patients = [];

    while ($row = $result->fetch_assoc()) {
        $patients[] = $row;
    }

    $stmt->close();

    sendResponse(200, [
        'success' => true,
        'count' => count($patients),
        'patients' => $patients
    ]);

} catch (Throwable $e) {
    error_log('MRMS Authorized Patients Error: ' . $e->getMessage());

    sendResponse(500, [
        'success' => false,
        'message' => 'Unable to retrieve authorized patients.'
    ]);
}