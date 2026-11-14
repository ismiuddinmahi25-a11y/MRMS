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

$doctorUserId = (int)($_SESSION['user_id'] ?? 0);

if ($doctorUserId <= 0) {
    http_response_code(403);
    exit('Invalid doctor session.');
}

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function redirectWithMessage(string $message): void
{
    header('Location: prescriptions.php?message=' . urlencode($message));
    exit;
}

/* ---------------------------------
   1. Verify approved doctor
--------------------------------- */

$stmt = $conn->prepare(
    "SELECT d.id, u.full_name
     FROM doctors d
     INNER JOIN users u ON u.id = d.user_id
     WHERE d.user_id = ?
       AND d.verification_status = 'approved'
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
$doctorName = $doctor['full_name'];

/* ---------------------------------
   2. CSRF protection
--------------------------------- */

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/* ---------------------------------
   3. Get authorized patients
--------------------------------- */

$patients = [];

$stmt = $conn->prepare(
    "SELECT
        p.id AS patient_id,
        u.full_name,
        p.phone,
        p.gender,
        p.date_of_birth
     FROM patient_doctor_access a
     INNER JOIN patients p ON p.id = a.patient_id
     INNER JOIN users u ON u.id = p.user_id
     WHERE a.doctor_id = ?
       AND a.access_status = 'active'
       AND (a.expires_at IS NULL OR a.expires_at > NOW())
     ORDER BY u.full_name ASC"
);

$stmt->bind_param("i", $doctorId);
$stmt->execute();

$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $patients[] = $row;
}

$stmt->close();

$authorizedPatientIds = array_map(
    'intval',
    array_column($patients, 'patient_id')
);

/* ---------------------------------
   4. Handle prescription submission
--------------------------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (
        !isset($_POST['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
    ) {
        http_response_code(403);
        exit('Invalid request token.');
    }

    $patientId = filter_input(
        INPUT_POST,
        'patient_id',
        FILTER_VALIDATE_INT
    );

    $appointmentId = isset($_POST['appointment_id']) &&
                     $_POST['appointment_id'] !== ''
        ? filter_var($_POST['appointment_id'], FILTER_VALIDATE_INT)
        : null;

    $diagnosis = trim($_POST['diagnosis'] ?? '');
    $medicines = trim($_POST['medicines'] ?? '');
    $instructions = trim($_POST['instructions'] ?? '');
    $followUpDate = trim($_POST['follow_up_date'] ?? '');

    if (
        !$patientId ||
        !in_array((int)$patientId, $authorizedPatientIds, true)
    ) {
        redirectWithMessage('invalid_patient');
    }

    if ($diagnosis === '' || $medicines === '') {
        redirectWithMessage('required_fields');
    }

    if (strlen($diagnosis) > 20000 || strlen($medicines) > 20000 ||
        strlen($instructions) > 20000) {
        redirectWithMessage('text_too_long');
    }

    /* Validate optional appointment */
    if ($appointmentId !== null) {

        if (!$appointmentId || $appointmentId <= 0) {
            redirectWithMessage('invalid_appointment');
        }

        $checkAppointment = $conn->prepare(
            "SELECT id
             FROM appointments
             WHERE id = ?
               AND patient_id = ?
               AND doctor_id = ?
             LIMIT 1"
        );

        $checkAppointment->bind_param(
            "iii",
            $appointmentId,
            $patientId,
            $doctorId
        );

        $checkAppointment->execute();
        $appointmentExists = $checkAppointment->get_result()->fetch_assoc();
        $checkAppointment->close();

        if (!$appointmentExists) {
            redirectWithMessage('invalid_appointment');
        }
    }

    /* Validate follow-up date */
    if ($followUpDate !== '') {

        $dateObject = DateTime::createFromFormat('Y-m-d', $followUpDate);

        if (
            !$dateObject ||
            $dateObject->format('Y-m-d') !== $followUpDate
        ) {
            redirectWithMessage('invalid_date');
        }

    } else {
        $followUpDate = null;
    }

    /* Save prescription */
    $stmt = $conn->prepare(
    "INSERT INTO prescriptions
    (
        patient_id,
        doctor_id,
        appointment_id,
        diagnosis,
        medicines,
        medical_advice,
        follow_up_date
    )
    VALUES (?, ?, ?, ?, ?, ?, ?)"
);

    $stmt->bind_param(
        "iiissss",
        $patientId,
        $doctorId,
        $appointmentId,
        $diagnosis,
        $medicines,
        $instructions,
        $followUpDate
    );

    if ($stmt->execute()) {
        $stmt->close();
        redirectWithMessage('saved');
    }

    error_log('MRMS Prescription Save Error: ' . $stmt->error);
    $stmt->close();

    redirectWithMessage('save_failed');
}

/* ---------------------------------
   5. Selected patient
--------------------------------- */

$selectedPatientId = filter_input(
    INPUT_GET,
    'patient_id',
    FILTER_VALIDATE_INT
);

if (
    !$selectedPatientId ||
    !in_array((int)$selectedPatientId, $authorizedPatientIds, true)
) {
    $selectedPatientId = null;
}

$selectedPatient = null;

foreach ($patients as $patient) {
    if ((int)$patient['patient_id'] === (int)$selectedPatientId) {
        $selectedPatient = $patient;
        break;
    }
}

/* ---------------------------------
   6. Appointments of selected patient
--------------------------------- */

$appointments = [];

if ($selectedPatientId !== null) {

    $stmt = $conn->prepare(
        "SELECT id, appointment_date, appointment_time, status
         FROM appointments
         WHERE patient_id = ?
           AND doctor_id = ?
         ORDER BY appointment_date DESC, appointment_time DESC"
    );

    $stmt->bind_param(
        "ii",
        $selectedPatientId,
        $doctorId
    );

    $stmt->execute();

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $appointments[] = $row;
    }

    $stmt->close();
}

/* ---------------------------------
   7. Previous prescriptions
--------------------------------- */

$previousPrescriptions = [];

$stmt = $conn->prepare(
    "SELECT
        pr.id,
        pr.diagnosis,
        pr.medicines,
       pr.medical_advice AS instructions,
        pr.follow_up_date,
        pr.created_at,
        u.full_name AS patient_name
     FROM prescriptions pr
     INNER JOIN patients p ON p.id = pr.patient_id
     INNER JOIN users u ON u.id = p.user_id
     INNER JOIN patient_doctor_access a
        ON a.patient_id = pr.patient_id
       AND a.doctor_id = pr.doctor_id
       AND a.access_status = 'active'
     WHERE pr.doctor_id = ?
       AND (a.expires_at IS NULL OR a.expires_at > NOW())
     ORDER BY pr.created_at DESC
     LIMIT 30"
);

$stmt->bind_param("i", $doctorId);
$stmt->execute();

$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $previousPrescriptions[] = $row;
}

$stmt->close();

$message = $_GET['message'] ?? '';

$messageTexts = [
    'saved' => 'Prescription saved successfully.',
    'invalid_patient' => 'Please select an authorized patient.',
    'required_fields' => 'Diagnosis and medicines are required.',
    'invalid_appointment' => 'Invalid appointment selected.',
    'invalid_date' => 'Please enter a valid follow-up date.',
    'text_too_long' => 'Some text fields are too long.',
    'save_failed' => 'Could not save prescription. Please try again.'
];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Prescriptions | MRMS</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 28px 16px;
            background: #f4f7fb;
            color: #1e293b;
            font-family: Arial, sans-serif;
        }

        .container {
            max-width: 1050px;
            margin: auto;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
            margin-bottom: 24px;
        }

        h1 {
            margin: 0 0 7px;
            font-size: 27px;
        }

        .subtitle {
            margin: 0;
            color: #64748b;
        }

        .back-link {
            color: #2563eb;
            text-decoration: none;
            font-weight: 600;
        }

        .panel {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 13px;
            padding: 24px;
            margin-bottom: 22px;
            box-shadow: 0 3px 12px rgba(15, 23, 42, .04);
        }

        .panel h2 {
            margin: 0 0 20px;
            font-size: 19px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 17px;
        }

        .field {
            margin-bottom: 17px;
        }

        .full {
            grid-column: 1 / -1;
        }

        label {
            display: block;
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 8px;
        }

        input, select, textarea {
            width: 100%;
            padding: 12px 13px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            background: #fff;
            color: #1e293b;
            font: inherit;
            outline: none;
        }

        input:focus, select:focus, textarea:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, .10);
        }

        textarea {
            min-height: 110px;
            resize: vertical;
        }

        .patient-info {
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            border-radius: 9px;
            padding: 14px;
            margin-bottom: 20px;
            line-height: 1.8;
        }

        .btn {
            display: inline-block;
            border: 0;
            border-radius: 8px;
            padding: 12px 19px;
            background: #2563eb;
            color: white;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
        }

        .btn:hover {
            background: #1d4ed8;
        }

        .alert {
            padding: 13px 15px;
            margin-bottom: 18px;
            border-radius: 8px;
            background: #eff6ff;
            color: #1d4ed8;
        }

        .alert.error {
            background: #fef2f2;
            color: #b91c1c;
        }

        .prescription-card {
            border: 1px solid #e2e8f0;
            border-radius: 9px;
            padding: 17px;
            margin-bottom: 13px;
        }

        .prescription-card h3 {
            margin: 0 0 9px;
            font-size: 16px;
        }

        .prescription-card p {
            margin: 7px 0;
            color: #475569;
            line-height: 1.6;
            overflow-wrap: anywhere;
        }

        .muted {
            color: #64748b;
            font-size: 13px;
        }

        .empty {
            color: #64748b;
            padding: 15px 0;
        }

        @media (max-width: 650px) {
            .form-grid {
                grid-template-columns: 1fr;
                gap: 0;
            }

            .full {
                grid-column: auto;
            }

            .panel {
                padding: 18px;
            }

            h1 {
                font-size: 23px;
            }
        }
    </style>
</head>

<body>

<div class="container">

    <div class="topbar">
        <div>
            <h1>Prescriptions</h1>
            <p class="subtitle">
                Create and manage patient prescriptions.
            </p>
        </div>

        <a class="back-link" href="dashboard.php">
            ← Back to Dashboard
        </a>
    </div>

    <?php if (isset($messageTexts[$message])): ?>
        <div class="alert <?= in_array($message, [
            'invalid_patient',
            'required_fields',
            'invalid_appointment',
            'invalid_date',
            'text_too_long',
            'save_failed'
        ], true) ? 'error' : '' ?>">
            <?= e($messageTexts[$message]) ?>
        </div>
    <?php endif; ?>

    <section class="panel">

        <h2>Create New Prescription</h2>

        <?php if (empty($patients)): ?>

            <div class="empty">
                No authorized patients are available.
                A patient must grant you access before you can create
                a prescription.
            </div>

        <?php else: ?>

            <form method="GET" action="prescriptions.php">
                <div class="field">
                    <label for="patient_id">Select Patient</label>

                    <select
                        name="patient_id"
                        id="patient_id"
                        required
                        onchange="this.form.submit()"
                    >
                        <option value="">Choose a patient</option>

                        <?php foreach ($patients as $patient): ?>
                            <option
                                value="<?= (int)$patient['patient_id'] ?>"
                                <?= (int)$selectedPatientId === (int)$patient['patient_id']
                                    ? 'selected'
                                    : '' ?>
                            >
                                <?= e($patient['full_name']) ?>
                                (ID: <?= (int)$patient['patient_id'] ?>)
                            </option>
                        <?php endforeach; ?>

                    </select>
                </div>
            </form>

            <?php if ($selectedPatient): ?>

                <div class="patient-info">
                    <strong><?= e($selectedPatient['full_name']) ?></strong><br>
                    Patient ID: <?= (int)$selectedPatient['patient_id'] ?><br>
                    Phone: <?= e($selectedPatient['phone']) ?><br>
                    Gender: <?= e($selectedPatient['gender']) ?><br>
                    Date of Birth: <?= e($selectedPatient['date_of_birth']) ?>
                </div>

                <form method="POST" action="prescriptions.php">

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= e($_SESSION['csrf_token']) ?>"
                    >

                    <input
                        type="hidden"
                        name="patient_id"
                        value="<?= (int)$selectedPatient['patient_id'] ?>"
                    >

                    <div class="form-grid">

                        <div class="field full">
                            <label for="appointment_id">
                                Related Appointment (Optional)
                            </label>

                            <select name="appointment_id" id="appointment_id">
                                <option value="">No appointment selected</option>

                                <?php foreach ($appointments as $appointment): ?>
                                    <option value="<?= (int)$appointment['id'] ?>">
                                        #<?= (int)$appointment['id'] ?>
                                        -
                                        <?= e($appointment['appointment_date']) ?>
                                        at
                                        <?= e($appointment['appointment_time']) ?>
                                        (<?= e(ucfirst($appointment['status'])) ?>)
                                    </option>
                                <?php endforeach; ?>

                            </select>
                        </div>

                        <div class="field full">
                            <label for="diagnosis">Diagnosis *</label>
                            <textarea
                                name="diagnosis"
                                id="diagnosis"
                                required
                                maxlength="20000"
                                placeholder="Enter patient diagnosis..."
                            ></textarea>
                        </div>

                        <div class="field full">
                            <label for="medicines">Medicines and Dosage *</label>
                            <textarea
                                name="medicines"
                                id="medicines"
                                required
                                maxlength="20000"
                                placeholder="Example:
1. Medicine name - 500 mg
   Dosage: 1 tablet
   Frequency: Twice daily
   Duration: 5 days

2. Medicine name - 10 mg
   Dosage: 1 tablet
   Frequency: Once daily
   Duration: 7 days"
                            ></textarea>
                        </div>

                        <div class="field full">
                            <label for="instructions">
                                Medical Instructions
                            </label>

                            <textarea
                                name="instructions"
                                id="instructions"
                                maxlength="20000"
                                placeholder="Enter additional medical advice..."
                            ></textarea>
                        </div>

                        <div class="field">
                            <label for="follow_up_date">
                                Follow-up Date
                            </label>

                            <input
                                type="date"
                                name="follow_up_date"
                                id="follow_up_date"
                            >
                        </div>

                    </div>

                    <button type="submit" class="btn">
                        Save Prescription
                    </button>

                </form>

            <?php else: ?>

                <div class="empty">
                    Select an authorized patient to create a prescription.
                </div>

            <?php endif; ?>

        <?php endif; ?>

    </section>

    <section class="panel">

        <h2>Previous Prescriptions</h2>

        <?php if (empty($previousPrescriptions)): ?>

            <div class="empty">
                No prescriptions have been created yet.
            </div>

        <?php else: ?>

            <?php foreach ($previousPrescriptions as $prescription): ?>

                <article class="prescription-card">

                    <h3>
                        <?= e($prescription['patient_name']) ?>
                        — Prescription #<?= (int)$prescription['id'] ?>
                    </h3>

                    <p class="muted">
                        Created:
                        <?= e($prescription['created_at']) ?>
                    </p>

                    <p>
                        <strong>Diagnosis:</strong><br>
                        <?= nl2br(e($prescription['diagnosis'])) ?>
                    </p>

                    <p>
                        <strong>Medicines:</strong><br>
                        <?= nl2br(e($prescription['medicines'])) ?>
                    </p>

                    <?php if (!empty($prescription['instructions'])): ?>
                        <p>
                            <strong>Instructions:</strong><br>
                            <?= nl2br(e($prescription['instructions'])) ?>
                        </p>
                    <?php endif; ?>

                    <?php if (!empty($prescription['follow_up_date'])): ?>
                        <p>
                            <strong>Follow-up:</strong>
                            <?= e($prescription['follow_up_date']) ?>
                        </p>
                    <?php endif; ?>

                </article>

            <?php endforeach; ?>

        <?php endif; ?>

    </section>

</div>

</body>
</html>
