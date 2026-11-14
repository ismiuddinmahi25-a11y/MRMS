<?php

declare(strict_types=1);

$required_role = 'doctor';

require_once __DIR__ . '/../auth/role_guard.php';
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

function jsonResponse(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

try {

    if (!isset($conn) || !($conn instanceof mysqli)) {
        throw new RuntimeException('Database connection unavailable.');
    }

    $conn->set_charset('utf8mb4');

    $userId = (int) ($_SESSION['user_id'] ?? 0);

    if ($userId <= 0) {
        jsonResponse(['success' => false, 'message' => 'Invalid session.'], 401);
    }

    // Get approved doctor's profile ID
    $doctorStmt = $conn->prepare(
        "SELECT id
         FROM doctors
         WHERE user_id = ?
           AND verification_status = 'approved'
         LIMIT 1"
    );

    $doctorStmt->bind_param('i', $userId);
    $doctorStmt->execute();

    $doctor = $doctorStmt->get_result()->fetch_assoc();
    $doctorStmt->close();

    if (!$doctor) {
        jsonResponse(['success' => false, 'message' => 'Doctor profile not found.'], 403);
    }

    $doctorId = (int) $doctor['id'];

    // Total unique patients with appointment history
    $stmt = $conn->prepare(
        "SELECT COUNT(DISTINCT patient_id) AS total
         FROM appointments
         WHERE doctor_id = ?"
    );

    $stmt->bind_param('i', $doctorId);
    $stmt->execute();

    $totalPatients = (int) $stmt->get_result()->fetch_assoc()['total'];
    $stmt->close();

    // Today's appointments count
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM appointments
         WHERE doctor_id = ?
           AND appointment_date = CURDATE()
           AND status NOT IN ('rejected', 'cancelled', 'canceled', 'declined')"
    );

    $stmt->bind_param('i', $doctorId);
    $stmt->execute();

    $todayAppointments = (int) $stmt->get_result()->fetch_assoc()['total'];
    $stmt->close();

    // Today's appointment list
    $stmt = $conn->prepare(
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
           AND a.appointment_date = CURDATE()
           AND a.status NOT IN ('rejected', 'cancelled', 'canceled', 'declined')
         ORDER BY a.appointment_time ASC"
    );

    $stmt->bind_param('i', $doctorId);
    $stmt->execute();

    $result = $stmt->get_result();
    $todayList = [];

    while ($row = $result->fetch_assoc()) {
        $todayList[] = [
            'id' => (int) $row['id'],
            'patient_id' => (int) $row['patient_id'],
            'patient' => $row['patient_name'],
            'date' => $row['appointment_date'],
            'time' => date('h:i A', strtotime($row['appointment_time'])),
            'reason' => $row['reason'] ?? 'Not specified',
            'status' => $row['status']
        ];
    }

    $stmt->close();

    // Pending appointment requests
    $stmt = $conn->prepare(
        "SELECT
            a.id,
            a.patient_id,
            a.appointment_date,
            a.appointment_time,
            a.reason,
            u.full_name AS patient_name
         FROM appointments a
         INNER JOIN patients p ON p.id = a.patient_id
         INNER JOIN users u ON u.id = p.user_id
         WHERE a.doctor_id = ?
           AND a.status = 'pending'
           AND a.appointment_date >= CURDATE()
         ORDER BY a.appointment_date ASC, a.appointment_time ASC"
    );

    $stmt->bind_param('i', $doctorId);
    $stmt->execute();

    $result = $stmt->get_result();
    $pendingList = [];

    while ($row = $result->fetch_assoc()) {
        $pendingList[] = [
            'id' => (int) $row['id'],
            'patient_id' => (int) $row['patient_id'],
            'patient' => $row['patient_name'],
            'date' => $row['appointment_date'],
            'time' => date('h:i A', strtotime($row['appointment_time'])),
            'reason' => $row['reason'] ?? 'Not specified',
            'status' => 'pending'
        ];
    }

    $stmt->close();

    // Upcoming confirmed appointments
    $stmt = $conn->prepare(
        "SELECT
            a.id,
            a.patient_id,
            a.appointment_date,
            a.appointment_time,
            a.reason,
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

    $stmt->bind_param('i', $doctorId);
    $stmt->execute();

    $result = $stmt->get_result();
    $upcomingList = [];

    while ($row = $result->fetch_assoc()) {
        $upcomingList[] = [
            'id' => (int) $row['id'],
            'patient_id' => (int) $row['patient_id'],
            'patient' => $row['patient_name'],
            'date' => $row['appointment_date'],
            'time' => date('h:i A', strtotime($row['appointment_time'])),
            'reason' => $row['reason'] ?? 'Not specified',
            'status' => 'confirmed'
        ];
    }

    $stmt->close();

    // Recent patients from appointment history
    $stmt = $conn->prepare(
        "SELECT
            p.id AS patient_id,
            u.full_name AS patient_name,
            MAX(a.appointment_date) AS last_visit
         FROM appointments a
         INNER JOIN patients p ON p.id = a.patient_id
         INNER JOIN users u ON u.id = p.user_id
         WHERE a.doctor_id = ?
           AND a.status IN ('confirmed', 'completed')
         GROUP BY p.id, u.full_name
         ORDER BY last_visit DESC
         LIMIT 5"
    );

    $stmt->bind_param('i', $doctorId);
    $stmt->execute();

    $result = $stmt->get_result();
    $recentPatients = [];

    while ($row = $result->fetch_assoc()) {
        $recentPatients[] = [
            'id' => (int) $row['patient_id'],
            'name' => $row['patient_name'],
            'last_visit' => $row['last_visit']
        ];
    }

    $stmt->close();

    jsonResponse([
        'success' => true,
        'total_patients' => $totalPatients,
        'today_appointments' => $todayAppointments,
        'today_appointments_list' => $todayList,
        'pending_appointments' => $pendingList,
        'pending_appointments_count' => count($pendingList),
        'upcoming_appointments' => $upcomingList,
        'recent_patients' => $recentPatients
    ]);

} catch (Throwable $e) {

    error_log('Doctor Dashboard Data Error: ' . $e->getMessage());

    jsonResponse([
        'success' => false,
        'message' => 'Unable to load dashboard data.'
    ], 500);
}
