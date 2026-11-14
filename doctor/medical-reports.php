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

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$userId = (int)($_SESSION['user_id'] ?? 0);

if ($userId <= 0) {
    http_response_code(401);
    exit('Please log in again.');
}

/* Get approved doctor ID */
$doctorStmt = $conn->prepare("
    SELECT id
    FROM doctors
    WHERE user_id = ?
      AND verification_status = 'approved'
    LIMIT 1
");

$doctorStmt->bind_param("i", $userId);
$doctorStmt->execute();
$doctor = $doctorStmt->get_result()->fetch_assoc();
$doctorStmt->close();

if (!$doctor) {
    http_response_code(403);
    exit('Doctor account is not approved.');
}

$doctorId = (int)$doctor['id'];

/* CSRF token */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = '';
$messageType = '';

/* Handle clinical note submission */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $token = $_POST['csrf_token'] ?? '';

    if (!is_string($token) ||
        !hash_equals($_SESSION['csrf_token'], $token)) {

        $message = 'Invalid request. Please refresh the page.';
        $messageType = 'error';

    } else {

        $reportId = filter_input(INPUT_POST, 'report_id', FILTER_VALIDATE_INT);
        $diagnosis = trim($_POST['diagnosis'] ?? '');
        $prescription = trim($_POST['prescription'] ?? '');
        $medicalAdvice = trim($_POST['medical_advice'] ?? '');
        $followUpDate = trim($_POST['follow_up_date'] ?? '');

        if (!$reportId || $diagnosis === '') {

            $message = 'Please select a valid report and enter diagnosis.';
            $messageType = 'error';

        } else {

            /* Verify report belongs to a patient who granted active access */
            $accessStmt = $conn->prepare("
                SELECT mr.id
                FROM medical_reports mr
                INNER JOIN patient_doctor_access a
                    ON a.patient_id = mr.patient_id
                WHERE mr.id = ?
                  AND a.doctor_id = ?
                  AND a.access_status = 'active'
                  AND (
                      a.expires_at IS NULL
                      OR a.expires_at > NOW()
                  )
                LIMIT 1
            ");

            $accessStmt->bind_param("ii", $reportId, $doctorId);
            $accessStmt->execute();
            $authorizedReport = $accessStmt->get_result()->fetch_assoc();
            $accessStmt->close();

            if (!$authorizedReport) {

                $message = 'You do not have permission to access this report.';
                $messageType = 'error';

            } else {

                $followUpValue = null;

                if ($followUpDate !== '') {
                    $date = DateTime::createFromFormat('Y-m-d', $followUpDate);

                    if (!$date || $date->format('Y-m-d') !== $followUpDate) {
                        $message = 'Invalid follow-up date.';
                        $messageType = 'error';
                    } else {
                        $followUpValue = $followUpDate;
                    }
                }

                if ($messageType !== 'error') {

                    /*
                     * Check whether a note already exists for this
                     * doctor and report. Update it instead of duplicating.
                     */
                    $checkStmt = $conn->prepare("
                        SELECT id
                        FROM doctor_report_notes
                        WHERE report_id = ?
                          AND doctor_id = ?
                        LIMIT 1
                    ");

                    $checkStmt->bind_param("ii", $reportId, $doctorId);
                    $checkStmt->execute();
                    $existingNote = $checkStmt->get_result()->fetch_assoc();
                    $checkStmt->close();

                    if ($existingNote) {

                        $noteId = (int)$existingNote['id'];

                        $updateStmt = $conn->prepare("
                            UPDATE doctor_report_notes
                            SET diagnosis = ?,
                                prescription = ?,
                                medical_advice = ?,
                                follow_up_date = ?
                            WHERE id = ?
                              AND report_id = ?
                              AND doctor_id = ?
                        ");

                        if (!$updateStmt) {
                            $message = 'Unable to prepare note update.';
                            $messageType = 'error';
                        } else {

                            $updateStmt->bind_param(
                                "ssssiii",
                                $diagnosis,
                                $prescription,
                                $medicalAdvice,
                                $followUpValue,
                                $noteId,
                                $reportId,
                                $doctorId
                            );

                            if ($updateStmt->execute()) {
                                $message = 'Clinical note updated successfully.';
                                $messageType = 'success';
                            } else {
                                $message = 'Failed to update clinical note.';
                                $messageType = 'error';
                            }

                            $updateStmt->close();
                        }

                    } else {

                        $insertStmt = $conn->prepare("
                            INSERT INTO doctor_report_notes
                            (
                                report_id,
                                doctor_id,
                                diagnosis,
                                prescription,
                                medical_advice,
                                follow_up_date
                            )
                            VALUES (?, ?, ?, ?, ?, ?)
                        ");

                        if (!$insertStmt) {
                            $message = 'Unable to prepare clinical note.';
                            $messageType = 'error';
                        } else {

                            $insertStmt->bind_param(
                                "iissss",
                                $reportId,
                                $doctorId,
                                $diagnosis,
                                $prescription,
                                $medicalAdvice,
                                $followUpValue
                            );

                            if ($insertStmt->execute()) {
                                $message = 'Clinical note saved successfully.';
                                $messageType = 'success';
                            } else {
                                $message = 'Failed to save clinical note.';
                                $messageType = 'error';
                            }

                            $insertStmt->close();
                        }
                    }
                }
            }
        }
    }
}

/* Get reports belonging to patients who granted access */
$reports = [];

$reportStmt = $conn->prepare("
    SELECT
        mr.id AS report_id,
        mr.patient_id,
        mr.report_title,
        mr.test_name,
        mr.test_date,
        mr.file_path,
        mr.verification_status,
        u.full_name AS patient_name,
        drn.id AS note_id,
        drn.diagnosis,
        drn.prescription,
        drn.medical_advice,
        drn.follow_up_date

    FROM medical_reports mr

    INNER JOIN patients p
        ON p.id = mr.patient_id

    INNER JOIN users u
        ON u.id = p.user_id

    INNER JOIN patient_doctor_access a
        ON a.patient_id = p.id

    LEFT JOIN doctor_report_notes drn
        ON drn.report_id = mr.id
       AND drn.doctor_id = ?

    WHERE a.doctor_id = ?
      AND a.access_status = 'active'
      AND (
          a.expires_at IS NULL
          OR a.expires_at > NOW()
      )
      AND (
          mr.verification_status = 'verified'
          OR mr.verification_status = 'Verified'
      )

    ORDER BY mr.test_date DESC, mr.id DESC
");

if ($reportStmt) {

    $reportStmt->bind_param("ii", $doctorId, $doctorId);
    $reportStmt->execute();

    $result = $reportStmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $reports[] = $row;
    }

    $reportStmt->close();
} else {
    error_log('MRMS medical reports query error: ' . $conn->error);
}

/* Escape output */
function mrmsReportEscape($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}
?>

<div class="mrms-medical-reports">

    <h2>Patient Medical Reports</h2>
    <p>View authorized patient reports and add clinical notes.</p>

    <?php if ($message !== ''): ?>
        <div class="mrms-message <?= $messageType === 'success' ? 'success' : 'error' ?>">
            <?= mrmsReportEscape($message) ?>
        </div>
    <?php endif; ?>

    <?php if (empty($reports)): ?>

        <p>No verified medical reports are currently available
           from patients who granted you access.</p>

    <?php else: ?>

        <?php foreach ($reports as $report): ?>

            <article class="mrms-report-card">

                <h3>
                    <?= mrmsReportEscape(
                        $report['report_title'] ?: $report['test_name']
                    ) ?>
                </h3>

                <p>
                    <strong>Patient:</strong>
                    <?= mrmsReportEscape($report['patient_name']) ?>
                </p>

                <p>
                    <strong>Test:</strong>
                    <?= mrmsReportEscape($report['test_name']) ?>
                </p>

                <p>
                    <strong>Test Date:</strong>
                    <?= mrmsReportEscape($report['test_date']) ?>
                </p>

                <?php if (!empty($report['file_path'])): ?>
                    <p>
                        <strong>Report File:</strong>
                        <a href="../<?= mrmsReportEscape($report['file_path']) ?>"
                           target="_blank" rel="noopener">
                            View Report
                        </a>
                    </p>
                <?php endif; ?>

                <hr>

                <h4>
                    <?= !empty($report['note_id'])
                        ? 'Update Clinical Note'
                        : 'Add Clinical Note' ?>
                </h4>

                <form method="POST" action="medical_reports.php">

                    <input type="hidden"
                           name="csrf_token"
                           value="<?= mrmsReportEscape($_SESSION['csrf_token']) ?>">

                    <input type="hidden"
                           name="report_id"
                           value="<?= (int)$report['report_id'] ?>">

                    <label>Diagnosis / Clinical Remarks</label>
                    <textarea name="diagnosis" required rows="3"><?= mrmsReportEscape($report['diagnosis']) ?></textarea>

                    <label>Prescription</label>
                    <textarea name="prescription" rows="3"><?= mrmsReportEscape($report['prescription']) ?></textarea>

                    <label>Medical Advice</label>
                    <textarea name="medical_advice" rows="3"><?= mrmsReportEscape($report['medical_advice']) ?></textarea>

                    <label>Follow-up Date</label>
                    <input type="date"
                           name="follow_up_date"
                           value="<?= mrmsReportEscape($report['follow_up_date']) ?>">

                    <button type="submit">
                        <?= !empty($report['note_id'])
                            ? 'Update Note'
                            : 'Save Clinical Note' ?>
                    </button>

                </form>

            </article>

        <?php endforeach; ?>

    <?php endif; ?>

</div>

<style>
.mrms-medical-reports {
    width: 100%;
}

.mrms-report-card {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    padding: 20px;
    margin: 18px 0;
}

.mrms-report-card label {
    display: block;
    font-weight: 600;
    margin: 12px 0 6px;
}

.mrms-report-card textarea,
.mrms-report-card input[type="date"] {
    width: 100%;
    box-sizing: border-box;
    padding: 10px;
    border: 1px solid #d1d5db;
    border-radius: 7px;
    font: inherit;
}

.mrms-report-card button {
    margin-top: 14px;
    padding: 10px 18px;
    border: 0;
    border-radius: 7px;
    background: #176b55;
    color: white;
    cursor: pointer;
}

.mrms-message {
    padding: 12px;
    border-radius: 7px;
    margin: 12px 0;
}

.mrms-message.success {
    background: #dcfce7;
    color: #166534;
}

.mrms-message.error {
    background: #fee2e2;
    color: #991b1b;
}
</style>
