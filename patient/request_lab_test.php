<?php
declare(strict_types=1);

$required_role = 'patient';

require_once __DIR__ . '/../auth/role_guard.php';
require_once __DIR__ . '/../config/db.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    exit('Database connection unavailable.');
}

$conn->set_charset('utf8mb4');

$userId = (int) $_SESSION['user_id'];

if (empty($_SESSION['csrf_lab_request'])) {
    $_SESSION['csrf_lab_request'] = bin2hex(random_bytes(32));
}

$message = '';
$messageType = '';

function e($value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

// -----------------------------------------
// 1. FIND LOGGED-IN PATIENT
// -----------------------------------------

$stmt = $conn->prepare(
    "SELECT id FROM patients WHERE user_id = ? LIMIT 1"
);

$stmt->bind_param('i', $userId);
$stmt->execute();

$patient = $stmt->get_result()->fetch_assoc();

$stmt->close();

if (!$patient) {
    http_response_code(404);
    exit('Patient profile not found.');
}

$patientId = (int) $patient['id'];

// -----------------------------------------
// 2. LOAD ACTIVE LAB OPERATORS
// -----------------------------------------

$operators = [];

$stmt = $conn->prepare(
    "SELECT
        lo.id AS operator_id,
        lo.laboratory_name,
        lo.designation,
        u.full_name
     FROM lab_operators lo
     INNER JOIN users u ON u.id = lo.user_id
     WHERE u.role = 'lab_operator'
       AND u.account_status = 'active'
     ORDER BY lo.laboratory_name ASC, u.full_name ASC"
);

if (!$stmt) {
    error_log('Operator query error: ' . $conn->error);
    http_response_code(500);
    exit('Unable to load lab operators.');
}

$stmt->execute();

$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $operators[] = $row;
}

$stmt->close();

// -----------------------------------------
// 3. HANDLE NEW LAB TEST REQUEST
// -----------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $token = $_POST['csrf_token'] ?? '';

    if (
        !is_string($token) ||
        !hash_equals($_SESSION['csrf_lab_request'], $token)
    ) {
        $message = 'Invalid request. Please refresh the page and try again.';
        $messageType = 'error';

    } else {

        $testName = isset($_POST['test_name']) && is_string($_POST['test_name'])
            ? trim($_POST['test_name'])
            : '';

        $reason = isset($_POST['reason']) && is_string($_POST['reason'])
            ? trim($_POST['reason'])
            : '';

        $preferredDate = isset($_POST['preferred_date']) && is_string($_POST['preferred_date'])
            ? trim($_POST['preferred_date'])
            : '';

        $operatorRaw = isset($_POST['lab_operator_id']) && is_string($_POST['lab_operator_id'])
            ? $_POST['lab_operator_id']
            : '';

        // Validate test name.
        if ($testName === '') {

            $message = 'Please enter the test name.';
            $messageType = 'error';

        } elseif (mb_strlen($testName) > 150) {

            $message = 'Test name must not exceed 150 characters.';
            $messageType = 'error';

        } elseif (mb_strlen($reason) > 5000) {

            $message = 'Reason is too long.';
            $messageType = 'error';

        } elseif (
            $operatorRaw === '' ||
            !ctype_digit($operatorRaw) ||
            (int) $operatorRaw <= 0
        ) {

            $message = 'Please select a valid lab operator.';
            $messageType = 'error';

        } else {

            $operatorId = (int) $operatorRaw;

            // Validate preferred date.
            $dateValue = null;

            if ($preferredDate !== '') {

                $date = DateTime::createFromFormat('!Y-m-d', $preferredDate);
                $today = new DateTime('today');

                if (
                    !$date ||
                    $date->format('Y-m-d') !== $preferredDate ||
                    $date < $today
                ) {

                    $message = 'Please select a valid date that is today or later.';
                    $messageType = 'error';

                } else {

                    $dateValue = $preferredDate;
                }
            }

            // Confirm selected operator exists and is active.
            if ($message === '') {

                $stmt = $conn->prepare(
                    "SELECT lo.id
                     FROM lab_operators lo
                     INNER JOIN users u ON u.id = lo.user_id
                     WHERE lo.id = ?
                       AND u.role = 'lab_operator'
                       AND u.account_status = 'active'
                     LIMIT 1"
                );

                $stmt->bind_param('i', $operatorId);
                $stmt->execute();

                $validOperator = $stmt->get_result()->fetch_assoc();

                $stmt->close();

                if (!$validOperator) {

                    $message = 'Selected lab operator is unavailable. Please select another operator.';
                    $messageType = 'error';

                } else {

                    // Save request with selected operator ID.
                    $stmt = $conn->prepare(
                        "INSERT INTO lab_test_requests
                        (
                            patient_id,
                            lab_operator_id,
                            test_name,
                            reason,
                            preferred_date,
                            status
                        )
                        VALUES (?, ?, ?, ?, ?, 'pending')"
                    );

                    if (!$stmt) {

                        error_log('Lab request prepare error: ' . $conn->error);

                        $message = 'Unable to submit your request right now.';
                        $messageType = 'error';

                    } else {

                        $stmt->bind_param(
                            'iisss',
                            $patientId,
                            $operatorId,
                            $testName,
                            $reason,
                            $dateValue
                        );

                        if ($stmt->execute()) {

                            $message = 'Your lab test request has been submitted successfully to the selected lab operator.';
                            $messageType = 'success';

                        } else {

                            error_log('Lab request insert error: ' . $stmt->error);

                            $message = 'Request submission failed. Please try again.';
                            $messageType = 'error';
                        }

                        $stmt->close();
                    }
                }
            }
        }
    }
}

// -----------------------------------------
// 4. LOAD PATIENT'S PREVIOUS REQUESTS
// -----------------------------------------

$requests = [];

$stmt = $conn->prepare(
    "SELECT
        r.id,
        r.test_name,
        r.reason,
        r.preferred_date,
        r.status,
        r.rejection_reason,
        r.report_id,
        r.created_at,
        r.lab_operator_id,
        u.full_name AS operator_name,
        lo.laboratory_name,
        lo.designation
     FROM lab_test_requests r
     LEFT JOIN lab_operators lo
        ON lo.id = r.lab_operator_id
     LEFT JOIN users u
        ON u.id = lo.user_id
     WHERE r.patient_id = ?
     ORDER BY r.id DESC"
);

if (!$stmt) {
    error_log('Request history query error: ' . $conn->error);
    http_response_code(500);
    exit('Unable to load lab request history.');
}

$stmt->bind_param('i', $patientId);
$stmt->execute();

$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $requests[] = $row;
}

$stmt->close();

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Request Lab Test | MRMS</title>

    <style>

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 30px 16px;
            background: #f4f7fb;
            color: #243047;
            font-family: Arial, sans-serif;
        }

        .container {
            max-width: 850px;
            margin: auto;
        }

        .top {
            margin-bottom: 24px;
        }

        .top a {
            color: #2563eb;
            text-decoration: none;
        }

        .panel {
            background: #fff;
            padding: 25px;
            border-radius: 12px;
            margin-bottom: 24px;
            box-shadow: 0 3px 15px rgba(0,0,0,.05);
        }

        h2 {
            margin-top: 0;
        }

        label {
            display: block;
            margin: 16px 0 7px;
            font-weight: 600;
        }

        input,
        textarea,
        select {
            width: 100%;
            padding: 12px;
            border: 1px solid #d5dce7;
            border-radius: 7px;
            font-size: 15px;
            background: #fff;
            color: #243047;
        }

        textarea {
            min-height: 100px;
            resize: vertical;
        }

        button {
            margin-top: 18px;
            padding: 12px 22px;
            border: 0;
            border-radius: 7px;
            background: #2563eb;
            color: white;
            font-size: 15px;
            cursor: pointer;
        }

        button:hover {
            background: #1d4ed8;
        }

        button:disabled {
            background: #94a3b8;
            cursor: not-allowed;
        }

        .message {
            padding: 13px;
            border-radius: 7px;
            margin-bottom: 18px;
        }

        .success {
            background: #dcfce7;
            color: #166534;
        }

        .error {
            background: #fee2e2;
            color: #991b1b;
        }

        .request {
            padding: 17px 0;
            border-bottom: 1px solid #e5eaf1;
        }

        .request:last-child {
            border-bottom: 0;
        }

        .status {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 20px;
            background: #e0e7ff;
            color: #3730a3;
            font-size: 12px;
            font-weight: bold;
            text-transform: capitalize;
        }

        .muted {
            color: #64748b;
            font-size: 14px;
        }

        .operator-info {
            margin-top: 12px;
            padding: 13px;
            background: #eff6ff;
            border-radius: 8px;
            border-left: 4px solid #2563eb;
        }

        .operator-info p {
            margin: 5px 0;
        }

        .no-operator {
            padding: 13px;
            background: #fff7ed;
            color: #9a3412;
            border-radius: 7px;
            margin-top: 12px;
        }

    </style>

</head>

<body>

<div class="container">

    <div class="top">
        <a href="dashboard.php">&larr; Back to Patient Dashboard</a>
    </div>


    <!-- REQUEST FORM -->

    <section class="panel">

        <h2>Request a Lab Test</h2>

        <p class="muted">
            Select a lab operator and submit your test request.
        </p>


        <?php if ($message !== ''): ?>

            <div class="message <?= e($messageType) ?>">
                <?= e($message) ?>
            </div>

        <?php endif; ?>


        <form method="POST" action="">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= e($_SESSION['csrf_lab_request']) ?>"
            >


            <label for="lab_operator_id">
                Select Lab Operator *
            </label>

            <select
                id="lab_operator_id"
                name="lab_operator_id"
                required
            >

                <option value="">-- Select a Lab Operator --</option>

                <?php foreach ($operators as $operator): ?>

                    <option value="<?= (int) $operator['operator_id'] ?>">

                        <?= e($operator['full_name']) ?>
                        -
                        <?= e($operator['laboratory_name']) ?>

                        <?php if (!empty($operator['designation'])): ?>
                            (<?= e($operator['designation']) ?>)
                        <?php endif; ?>

                    </option>

                <?php endforeach; ?>

            </select>


            <?php if (empty($operators)): ?>

                <div class="no-operator">
                    No active lab operators are currently available.
                    Please try again later.
                </div>

            <?php endif; ?>


            <label for="test_name">
                Test Name *
            </label>

            <input
                type="text"
                id="test_name"
                name="test_name"
                maxlength="150"
                placeholder="Example: Complete Blood Count (CBC)"
                required
            >


            <label for="reason">
                Reason for Test
            </label>

            <textarea
                id="reason"
                name="reason"
                maxlength="5000"
                placeholder="Why do you need this test?"
            ></textarea>


            <label for="preferred_date">
                Preferred Date
            </label>

            <input
                type="date"
                id="preferred_date"
                name="preferred_date"
                min="<?= e(date('Y-m-d')) ?>"
            >


            <button
                type="submit"
                <?= empty($operators) ? 'disabled' : '' ?>
            >
                Submit Test Request
            </button>

        </form>

    </section>


    <!-- REQUEST HISTORY -->

    <section class="panel">

        <h2>My Lab Test Requests</h2>


        <?php if (empty($requests)): ?>

            <p class="muted">
                You have not submitted any lab test requests yet.
            </p>

        <?php else: ?>


            <?php foreach ($requests as $request): ?>

                <div class="request">

                    <h3>
                        <?= e($request['test_name']) ?>
                    </h3>


                    <p class="muted">
                        Request ID:
                        #<?= (int) $request['id'] ?>
                    </p>


                    <p>
                        <strong>Status:</strong>

                        <span class="status">
                            <?= e($request['status']) ?>
                        </span>
                    </p>


                    <!-- ASSIGNED OPERATOR INFORMATION -->

                    <div class="operator-info">

                        <p>
                            <strong>Lab Operator:</strong>

                            <?= !empty($request['operator_name'])
                                ? e($request['operator_name'])
                                : 'Not assigned' ?>
                        </p>


                        <p>
                            <strong>Laboratory:</strong>

                            <?= !empty($request['laboratory_name'])
                                ? e($request['laboratory_name'])
                                : 'Not available' ?>
                        </p>


                        <?php if (!empty($request['designation'])): ?>

                            <p>
                                <strong>Designation:</strong>

                                <?= e($request['designation']) ?>
                            </p>

                        <?php endif; ?>

                    </div>


                    <?php if (!empty($request['reason'])): ?>

                        <p>
                            <strong>Reason:</strong>

                            <?= nl2br(e($request['reason'])) ?>
                        </p>

                    <?php endif; ?>


                    <?php if (!empty($request['preferred_date'])): ?>

                        <p>
                            <strong>Preferred date:</strong>

                            <?= e($request['preferred_date']) ?>
                        </p>

                    <?php endif; ?>


                    <?php if (!empty($request['rejection_reason'])): ?>

                        <p>
                            <strong>Operator's note:</strong>

                            <?= e($request['rejection_reason']) ?>
                        </p>

                    <?php endif; ?>


                    <p class="muted">
                        Submitted:

                        <?= e($request['created_at']) ?>
                    </p>


                    <?php if (!empty($request['report_id'])): ?>

                        <p>
                            <strong>Report ID:</strong>

                            #<?= (int) $request['report_id'] ?>
                        </p>

                    <?php endif; ?>

                </div>

            <?php endforeach; ?>


        <?php endif; ?>

    </section>

</div>

</body>
</html>
