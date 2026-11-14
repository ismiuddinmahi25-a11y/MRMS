<?php

declare(strict_types=1);

$required_role = 'doctor';

require_once __DIR__ . '/../auth/role_guard.php';
require_once __DIR__ . '/../config/db.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    exit('Database connection unavailable.');
}

$conn->set_charset('utf8mb4');

function e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

// ------------------------------------
// LOGGED-IN DOCTOR
// ------------------------------------

$doctorUserId = (int)($_SESSION['user_id'] ?? 0);

if ($doctorUserId <= 0) {
    http_response_code(403);
    exit('Invalid doctor session.');
}

$stmt = $conn->prepare(
    "SELECT id
     FROM doctors
     WHERE user_id = ?
       AND verification_status = 'approved'
     LIMIT 1"
);

$stmt->bind_param("i", $doctorUserId);
$stmt->execute();

$doctor = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$doctor) {
    http_response_code(403);
    exit('Approved doctor profile not found.');
}

$doctorId = (int)$doctor['id'];

// ------------------------------------
// SELECT PATIENT
// ------------------------------------

$patientId = filter_input(INPUT_GET, 'patient_id', FILTER_VALIDATE_INT);

if (!$patientId || $patientId <= 0) {
    http_response_code(400);
    exit('Invalid patient ID.');
}

$stmt = $conn->prepare(
    "SELECT
        p.id,
        p.user_id,
        p.date_of_birth,
        p.gender,
        p.phone,
        p.address,
        u.full_name,
        u.email
     FROM patient_doctor_access pa
     INNER JOIN patients p
        ON p.id = pa.patient_id
     INNER JOIN users u
        ON u.id = p.user_id
     WHERE pa.patient_id = ?
       AND pa.doctor_id = ?
       AND pa.access_status = 'active'
       AND (pa.expires_at IS NULL OR pa.expires_at > NOW())
     LIMIT 1"
);

$stmt->bind_param("ii", $patientId, $doctorId);
$stmt->execute();

$patient = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$patient) {
    http_response_code(403);
    exit('You do not have permission to access this patient history.');
}

// ------------------------------------
// PATIENT MEDICAL REPORTS
// ------------------------------------

$reports = [];

$stmt = $conn->prepare(
    "SELECT
        id,
        report_title,
        test_name,
        test_date,
        original_file_name,
        file_path,
        mime_type,
        file_size,
        remarks,
        verified_at,
        created_at
     FROM medical_reports
     WHERE patient_id = ?
       AND verification_status = 'verified'
     ORDER BY test_date DESC, id DESC"
);

$stmt->bind_param("i", $patientId);
$stmt->execute();

$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $reports[] = $row;
}

$stmt->close();

// ------------------------------------
// PREVIOUS APPOINTMENTS
// ------------------------------------

$appointments = [];

$stmt = $conn->prepare(
    "SELECT
        a.id,
        a.appointment_date,
        a.appointment_time,
        a.reason,
        a.status,
        a.doctor_notes,
        a.created_at,
        u.full_name AS doctor_name,
        d.specialization
     FROM appointments a
     INNER JOIN doctors d
        ON d.id = a.doctor_id
     INNER JOIN users u
        ON u.id = d.user_id
     WHERE a.patient_id = ?
     ORDER BY a.appointment_date DESC,
              a.appointment_time DESC"
);

$stmt->bind_param("i", $patientId);
$stmt->execute();

$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $appointments[] = $row;
}

$stmt->close();

// ------------------------------------
// DOCTOR DIAGNOSIS & PRESCRIPTIONS
// ------------------------------------

$clinicalNotes = [];

$stmt = $conn->prepare(
    "SELECT
        n.id,
        n.diagnosis,
        n.prescription,
        n.medical_advice,
        n.follow_up_date,
        n.created_at,
        mr.report_title,
        mr.test_name,
        mr.test_date,
        u.full_name AS doctor_name,
        d.specialization
     FROM doctor_report_notes n
     INNER JOIN medical_reports mr
        ON mr.id = n.report_id
     INNER JOIN doctors d
        ON d.id = n.doctor_id
     INNER JOIN users u
        ON u.id = d.user_id
     WHERE mr.patient_id = ?
       AND mr.verification_status = 'verified'
     ORDER BY n.created_at DESC, n.id DESC"
);

$stmt->bind_param("i", $patientId);
$stmt->execute();

$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $clinicalNotes[] = $row;
}

$stmt->close();

// ------------------------------------
// DOCTOR-ISSUED PRESCRIPTIONS
// ------------------------------------

$prescriptions = [];

$stmt = $conn->prepare(
    "SELECT
        pr.id,
        pr.patient_id,
        pr.doctor_id,
        pr.appointment_id,
        pr.diagnosis,
        pr.medicines,
        pr.medical_advice,
        pr.follow_up_date,
        pr.created_at,
        u.full_name AS doctor_name,
        d.specialization
     FROM prescriptions pr
     INNER JOIN doctors d
        ON d.id = pr.doctor_id
     INNER JOIN users u
        ON u.id = d.user_id
     WHERE pr.patient_id = ?
     ORDER BY pr.created_at DESC, pr.id DESC"
);

$stmt->bind_param("i", $patientId);
$stmt->execute();

$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $prescriptions[] = $row;
}

$stmt->close();

// ------------------------------------
// SUMMARY
// ------------------------------------

$totalReports = count($reports);
$totalAppointments = count($appointments);
$totalDiagnoses = count($clinicalNotes);
$totalPrescriptions = count($prescriptions);

$completedAppointments = 0;

foreach ($appointments as $appointment) {
    if ($appointment['status'] === 'completed') {
        $completedAppointments++;
    }
}

$patientAge = null;

if (!empty($patient['date_of_birth'])) {
    $dob = new DateTime($patient['date_of_birth']);
    $today = new DateTime();
    $patientAge = $today->diff($dob)->y;
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Medical History - MRMS</title>

    <link rel="stylesheet"
          href="../auth/assets/css/dashboard.css">

    <link rel="stylesheet"
          href="../auth/assets/css/doctor-dashboard.css">

    <style>
        .history-page {
            padding: 25px;
        }

        .history-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 25px;
        }

        .history-header h1 {
            margin: 0 0 7px;
        }

        .history-header p {
            margin: 0;
            color: #64748b;
        }

        .back-btn {
            display: inline-block;
            padding: 10px 16px;
            border-radius: 8px;
            background: #eef2ff;
            color: #3346a8;
            text-decoration: none;
            font-weight: 600;
        }

        /* View Report Button */
        .view-report-btn {
            display: inline-block;
            margin-top: 12px;
            padding: 10px 16px;
            border-radius: 8px;
            background: #2563eb;
            color: #fff;
            text-decoration: none;
            font-weight: 600;
            transition: background 0.2s ease;
        }

        .view-report-btn:hover {
            background: #1d4ed8;
        }

        .patient-card {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 22px;
            margin-bottom: 22px;
        }

        .patient-card h2 {
            margin-top: 0;
            margin-bottom: 18px;
        }

        .patient-info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 18px;
        }

        .info-item small {
            display: block;
            color: #64748b;
            margin-bottom: 6px;
        }

        .info-item strong {
            color: #1e293b;
            overflow-wrap: anywhere;
        }

        .history-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }

        .history-stat {
            background: #fff;
            padding: 20px;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
        }

        .history-stat small {
            color: #64748b;
        }

        .history-stat h2 {
            margin: 8px 0 0;
            font-size: 27px;
        }

        .history-section {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 22px;
            margin-bottom: 22px;
        }

        .history-section h2 {
            margin-top: 0;
            margin-bottom: 20px;
            font-size: 19px;
        }

        .history-item {
            padding: 17px 0;
            border-bottom: 1px solid #edf0f4;
        }

        .history-item:last-child {
            border-bottom: none;
        }

        .history-item h3 {
            margin: 0 0 9px;
            font-size: 16px;
            color: #1e293b;
        }

        .history-item p {
            margin: 7px 0;
            color: #475569;
            line-height: 1.6;
            overflow-wrap: anywhere;
        }

        .history-meta {
            font-size: 13px;
            color: #64748b;
        }

        .status {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            background: #f1f5f9;
            color: #475569;
        }

        .status.completed,
        .status.verified {
            background: #dcfce7;
            color: #166534;
        }

        .status.pending {
            background: #fef3c7;
            color: #92400e;
        }

        .status.rejected,
        .status.cancelled {
            background: #fee2e2;
            color: #991b1b;
        }

        .empty-history {
            padding: 25px 10px;
            text-align: center;
            color: #64748b;
            background: #f8fafc;
            border-radius: 8px;
        }

        @media(max-width: 600px) {
            .history-page {
                padding: 15px;
            }

            .history-section,
            .patient-card {
                padding: 16px;
            }
        }
    </style>
</head>

<body>

<div class="history-page">

    <!-- HEADER -->

    <div class="history-header">

        <div>
            <h1>Medical History</h1>
            <p>Complete medical history and clinical records of the patient.</p>
        </div>

        <a href="dashboard.php" class="back-btn">
            &larr; Back to Dashboard
        </a>

    </div>

    <!-- PATIENT INFORMATION -->

    <section class="patient-card">

        <h2>Patient Information</h2>

        <div class="patient-info">

            <div class="info-item">
                <small>Patient Name</small>
                <strong><?= e($patient['full_name']) ?></strong>
            </div>

            <div class="info-item">
                <small>Patient ID</small>
                <strong>#<?= (int)$patient['id'] ?></strong>
            </div>

            <div class="info-item">
                <small>Age</small>
                <strong>
                    <?= $patientAge !== null ? $patientAge . ' years' : 'N/A' ?>
                </strong>
            </div>

            <div class="info-item">
                <small>Gender</small>
                <strong><?= e($patient['gender']) ?></strong>
            </div>

            <div class="info-item">
                <small>Phone</small>
                <strong><?= e($patient['phone']) ?></strong>
            </div>

            <div class="info-item">
                <small>Email</small>
                <strong><?= e($patient['email']) ?></strong>
            </div>

            <div class="info-item">
                <small>Date of Birth</small>
                <strong><?= e($patient['date_of_birth']) ?></strong>
            </div>

            <div class="info-item">
                <small>Address</small>
                <strong><?= e($patient['address'] ?: 'Not provided') ?></strong>
            </div>

        </div>

    </section>

    <!-- SUMMARY -->

    <div class="history-stats">

        <div class="history-stat">
            <small>Total Medical Reports</small>
            <h2><?= $totalReports ?></h2>
        </div>

        <div class="history-stat">
            <small>Total Appointments</small>
            <h2><?= $totalAppointments ?></h2>
        </div>

        <div class="history-stat">
            <small>Completed Appointments</small>
            <h2><?= $completedAppointments ?></h2>
        </div>

        <div class="history-stat">
            <small>Clinical Notes</small>
            <h2><?= $totalDiagnoses ?></h2>
        </div>

        <div class="history-stat">
            <small>Total Prescriptions</small>
            <h2><?= $totalPrescriptions ?></h2>
        </div>

    </div>

    <!-- MEDICAL REPORTS -->

    
    <!-- MEDICAL REPORTS -->

    <section class="history-section">

        <h2>Medical Reports</h2>

        <?php if (empty($reports)): ?>

            <div class="empty-history">
                No verified medical reports found.
            </div>

        <?php else: ?>

            <?php foreach ($reports as $report): ?>

                <?php
                $reportUrl = 'view_report.php?id='
                    . (int)$report['id']
                    . '&patient_id='
                    . (int)$patientId;

                $mime = strtolower((string)$report['mime_type']);

                $isImage = in_array(
                    $mime,
                    ['image/jpeg', 'image/png'],
                    true
                );

                $isPdf = $mime === 'application/pdf';
                ?>

                <div class="history-item">

                    <h3><?= e($report['report_title']) ?></h3>

                    <p>
                        <strong>Test:</strong>
                        <?= e($report['test_name']) ?>
                    </p>

                    <p>
                        <strong>Test Date:</strong>
                        <?= e($report['test_date']) ?>
                    </p>

                    <p>
                        <strong>File:</strong>
                        <?= e($report['original_file_name']) ?>
                    </p>

                    <p>
                        <strong>Remarks:</strong>
                        <?= e($report['remarks'] ?: 'No remarks') ?>
                    </p>

                    <span class="status verified">Verified</span>

                    <?php if ($isImage): ?>

                        <div style="
                            margin-top: 18px;
                            padding: 12px;
                            background: #f8fafc;
                            border: 1px solid #e5e7eb;
                            border-radius: 10px;
                        ">

                            <p>
                                <strong>Report Image Preview</strong>
                            </p>

                            <a
                                href="<?= e($reportUrl) ?>"
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                <img
                                    src="<?= e($reportUrl) ?>"
                                    alt="Medical report image"
                                    loading="lazy"
                                    style="
                                        display: block;
                                        width: 100%;
                                        max-width: 650px;
                                        max-height: 650px;
                                        object-fit: contain;
                                        margin: 10px auto;
                                        border-radius: 6px;
                                        background: #fff;
                                    "
                                >
                            </a>

                        </div>

                    <?php elseif ($isPdf): ?>

                        <div style="margin-top: 18px;">

                            <p><strong>PDF Report Preview</strong></p>

                            <iframe
                                src="<?= e($reportUrl) ?>"
                                title="Medical report PDF"
                                loading="lazy"
                                style="
                                    width: 100%;
                                    height: 550px;
                                    border: 1px solid #e5e7eb;
                                    border-radius: 8px;
                                "
                            ></iframe>

                        </div>

                    <?php else: ?>

                        <p>
                            Preview is unavailable for this file type.
                        </p>

                    <?php endif; ?>

                    <br>

                    <a
                        href="<?= e($reportUrl) ?>"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="view-report-btn"
                    >
                        View Full Report
                    </a>

                </div>

            <?php endforeach; ?>

        <?php endif; ?>

    </section>

    <!-- CLINICAL NOTES -->

    <section class="history-section">

        <h2>Diagnosis & Prescriptions</h2>

        <?php if (empty($clinicalNotes)): ?>

            <div class="empty-history">
                No diagnosis or prescription records found.
            </div>

        <?php else: ?>

            <?php foreach ($clinicalNotes as $note): ?>

                <div class="history-item">

                    <h3><?= e($note['report_title']) ?></h3>

                    <p>
                        <strong>Related Test:</strong>
                        <?= e($note['test_name']) ?>
                    </p>

                    <p>
                        <strong>Diagnosis:</strong><br>
                        <?= nl2br(e($note['diagnosis'])) ?>
                    </p>

                    <p>
                        <strong>Prescription:</strong><br>
                        <?= nl2br(e($note['prescription'] ?: 'Not provided')) ?>
                    </p>

                    <p>
                        <strong>Medical Advice:</strong><br>
                        <?= nl2br(e($note['medical_advice'] ?: 'Not provided')) ?>
                    </p>

                    <p>
                        <strong>Doctor:</strong>
                        <?= e($note['doctor_name']) ?>
                        (<?= e($note['specialization']) ?>)
                    </p>

                    <p>
                        <strong>Follow-up Date:</strong>
                        <?= e($note['follow_up_date'] ?: 'Not scheduled') ?>
                    </p>

                    <div class="history-meta">
                        Recorded: <?= e($note['created_at']) ?>
                    </div>

                </div>

            <?php endforeach; ?>

        <?php endif; ?>

    </section>

    <!-- DOCTOR-ISSUED PRESCRIPTIONS -->

    <section class="history-section">

        <h2>Prescription History</h2>

        <?php if (empty($prescriptions)): ?>

            <div class="empty-history">
                No prescription records found.
            </div>

        <?php else: ?>

            <?php foreach ($prescriptions as $prescription): ?>

                <div class="history-item">

                    <h3>
                        Prescription #<?= (int)$prescription['id'] ?>
                    </h3>

                    <p>
                        <strong>Diagnosis:</strong><br>
                        <?= nl2br(e($prescription['diagnosis'])) ?>
                    </p>

                    <p>
                        <strong>Medicines:</strong><br>
                        <?= nl2br(e($prescription['medicines'])) ?>
                    </p>

                    <p>
                        <strong>Medical Advice:</strong><br>
                        <?= nl2br(e(
                            $prescription['medical_advice'] ?: 'Not provided'
                        )) ?>
                    </p>

                    <p>
                        <strong>Doctor:</strong>
                        <?= e($prescription['doctor_name']) ?>
                        (<?= e($prescription['specialization']) ?>)
                    </p>

                    <p>
                        <strong>Appointment ID:</strong>
                        <?= $prescription['appointment_id']
                            ? '#' . (int)$prescription['appointment_id']
                            : 'Not linked' ?>
                    </p>

                    <p>
                        <strong>Follow-up Date:</strong>
                        <?= e(
                            $prescription['follow_up_date'] ?: 'Not scheduled'
                        ) ?>
                    </p>

                    <div class="history-meta">
                        Created:
                        <?= e($prescription['created_at']) ?>
                    </div>

                </div>

            <?php endforeach; ?>

        <?php endif; ?>

    </section>

    <!-- APPOINTMENT HISTORY -->

    <section class="history-section">

        <h2>Appointment History</h2>

        <?php if (empty($appointments)): ?>

            <div class="empty-history">
                No appointment history found.
            </div>

        <?php else: ?>

            <?php foreach ($appointments as $appointment): ?>

                <div class="history-item">

                    <h3>
                        Appointment #<?= (int)$appointment['id'] ?>
                    </h3>

                    <p>
                        <strong>Doctor:</strong>
                        <?= e($appointment['doctor_name']) ?>
                    </p>

                    <p>
                        <strong>Specialization:</strong>
                        <?= e($appointment['specialization']) ?>
                    </p>

                    <p>
                        <strong>Date:</strong>
                        <?= e($appointment['appointment_date']) ?>
                    </p>

                    <p>
                        <strong>Time:</strong>
                        <?= e($appointment['appointment_time']) ?>
                    </p>

                    <p>
                        <strong>Reason:</strong><br>
                        <?= nl2br(e($appointment['reason'] ?: 'Not provided')) ?>
                    </p>

                    <p>
                        <strong>Doctor's Notes:</strong><br>
                        <?= nl2br(e($appointment['doctor_notes'] ?: 'No notes')) ?>
                    </p>

                    <span class="status <?= e($appointment['status']) ?>">
                        <?= e(ucfirst($appointment['status'])) ?>
                    </span>

                </div>

            <?php endforeach; ?>

        <?php endif; ?>

    </section>

</div>

</body>
</html>
