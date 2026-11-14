<?php
declare(strict_types=1);

$required_role = 'lab_operator';

require_once __DIR__ . '/../auth/role_guard.php';
require_once __DIR__ . '/../config/db.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    exit('Database connection unavailable.');
}

$conn->set_charset('utf8mb4');

$userId = (int) $_SESSION['user_id'];

function e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

$stmt = $conn->prepare(
    "SELECT id, laboratory_name
     FROM lab_operators
     WHERE user_id = ?
     LIMIT 1"
);
$stmt->bind_param('i', $userId);
$stmt->execute();
$operator = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$operator) {
    exit('Lab operator profile not found.');
}

$operatorId = (int)$operator['id'];

if (empty($_SESSION['csrf_upload_lab_report'])) {
    $_SESSION['csrf_upload_lab_report'] = bin2hex(random_bytes(32));
}

$message = '';
$messageType = 'success';

if (isset($_SESSION['lab_upload_flash'])) {
    $message = $_SESSION['lab_upload_flash']['message'];
    $messageType = $_SESSION['lab_upload_flash']['type'];
    unset($_SESSION['lab_upload_flash']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $requestId = filter_input(INPUT_POST, 'request_id', FILTER_VALIDATE_INT);
    $token = $_POST['csrf_token'] ?? '';
    $testDate = trim((string)($_POST['test_date'] ?? ''));
    $reportTitle = trim((string)($_POST['report_title'] ?? ''));
    $remarks = trim((string)($_POST['remarks'] ?? ''));

    $uploadedPath = null;

    try {
        if (
            !is_string($token) ||
            !hash_equals($_SESSION['csrf_upload_lab_report'], $token)
        ) {
            throw new RuntimeException('Invalid request. Refresh and try again.');
        }

        if (!$requestId || $requestId <= 0) {
            throw new RuntimeException('Invalid test request.');
        }

        $date = DateTime::createFromFormat('!Y-m-d', $testDate);

        if (!$date || $date->format('Y-m-d') !== $testDate) {
            throw new RuntimeException('Select a valid test date.');
        }

        if (
            !isset($_FILES['report_file']) ||
            $_FILES['report_file']['error'] !== UPLOAD_ERR_OK
        ) {
            throw new RuntimeException('Please select a valid report file.');
        }

        $file = $_FILES['report_file'];

        if (!is_uploaded_file($file['tmp_name'])) {
            throw new RuntimeException('Invalid uploaded file.');
        }

        if ($file['size'] <= 0 || $file['size'] > 10 * 1024 * 1024) {
            throw new RuntimeException('Report file must be between 1 byte and 10 MB.');
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);

        $allowed = [
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png'
        ];

        if (!isset($allowed[$mime])) {
    throw new RuntimeException(
        'Unsupported file type detected: ' . ($mime ?: 'Unknown MIME type')
    );
}

        if (str_starts_with($mime, 'image/')) {
            if (@getimagesize($file['tmp_name']) === false) {
                throw new RuntimeException('Invalid image file.');
            }
        }

        $originalName = basename(
            str_replace('\\', '/', (string)$file['name'])
        );

        if ($reportTitle === '') {
            // Will be set from the request's test name below.
            $reportTitle = '';
        }

        if (mb_strlen($reportTitle) > 200) {
            throw new RuntimeException('Report title is too long.');
        }

        $uploadDirectory = __DIR__ . '/../private_uploads/medical_reports';

        if (
            !is_dir($uploadDirectory) &&
            !mkdir($uploadDirectory, 0750, true)
        ) {
            throw new RuntimeException('Unable to create report storage directory.');
        }

        // Block direct browser access to uploaded medical files.
        $htaccess = $uploadDirectory . '/.htaccess';

        if (!file_exists($htaccess)) {
            $written = file_put_contents(
                $htaccess,
                "Require all denied\nOptions -Indexes\n"
            );

            if ($written === false) {
                throw new RuntimeException('Unable to secure report storage.');
            }
        }

        $storedName = bin2hex(random_bytes(24)) . '.' . $allowed[$mime];

        $absolutePath = $uploadDirectory . DIRECTORY_SEPARATOR . $storedName;
        $relativePath = 'private_uploads/medical_reports/' . $storedName;

        $conn->begin_transaction();

        // Lock and verify the request belongs to this operator.
        $stmt = $conn->prepare(
            "SELECT r.id, r.patient_id, r.test_name
             FROM lab_test_requests r
             WHERE r.id = ?
               AND r.lab_operator_id = ?
               AND r.status IN ('accepted', 'processing')
               AND r.report_id IS NULL
             LIMIT 1
             FOR UPDATE"
        );

        $stmt->bind_param('ii', $requestId, $operatorId);
        $stmt->execute();
        $request = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$request) {
            throw new RuntimeException(
                'This request is unavailable or already has a report.'
            );
        }

        if ($reportTitle === '') {
            $reportTitle = $request['test_name'] . ' Report';
        }

        if (!move_uploaded_file($file['tmp_name'], $absolutePath)) {
            throw new RuntimeException('Unable to save the uploaded report.');
        }

        $uploadedPath = $absolutePath;

        $patientId = (int)$request['patient_id'];
        $fileSize = (int)$file['size'];

        // Save report and mark it verified by the uploading lab operator.
        $stmt = $conn->prepare(
            "INSERT INTO medical_reports
                (
                    patient_id,
                    report_title,
                    test_name,
                    test_date,
                    uploaded_by,
                    verified_by,
                    original_file_name,
                    file_path,
                    mime_type,
                    file_size,
                    verification_status,
                    verified_at,
                    remarks
                )
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'verified', NOW(), ?)"
        );

        $testName = (string)$request['test_name'];

        $stmt->bind_param(
            'isssiisssis',
            $patientId,
            $reportTitle,
            $testName,
            $testDate,
            $operatorId,
            $operatorId,
            $originalName,
            $relativePath,
            $mime,
            $fileSize,
            $remarks
        );

        $stmt->execute();
        $reportId = (int)$conn->insert_id;
        $stmt->close();

        // Link report to the original patient request.
        $stmt = $conn->prepare(
            "UPDATE lab_test_requests
             SET report_id = ?, status = 'completed'
             WHERE id = ?
               AND lab_operator_id = ?
               AND report_id IS NULL
               AND status IN ('accepted', 'processing')"
        );

        $stmt->bind_param('iii', $reportId, $requestId, $operatorId);
        $stmt->execute();

        if ($stmt->affected_rows !== 1) {
            $stmt->close();
            throw new RuntimeException('Unable to link report to the test request.');
        }

        $stmt->close();

        $conn->commit();

        $_SESSION['lab_upload_flash'] = [
            'message' => 'Report uploaded successfully. The patient can now view it.',
            'type' => 'success'
        ];

        header('Location: upload_results.php');
        exit;

    } catch (Throwable $e) {

        try {
            $conn->rollback();
        } catch (Throwable $ignored) {
        }

        if ($uploadedPath && is_file($uploadedPath)) {
            @unlink($uploadedPath);
        }

        error_log('MRMS Lab Report Upload: ' . $e->getMessage());

        $message = $e instanceof RuntimeException
            ? $e->getMessage()
            : 'Unable to upload report. Please try again.';

        $messageType = 'error';
    }
}

// Load accepted/processing requests that do not have reports yet.
$stmt = $conn->prepare(
    "SELECT
        r.id,
        r.patient_id,
        r.test_name,
        r.reason,
        r.preferred_date,
        r.status,
        r.created_at,
        u.full_name AS patient_name
     FROM lab_test_requests r
     INNER JOIN patients p ON p.id = r.patient_id
     INNER JOIN users u ON u.id = p.user_id
     WHERE r.lab_operator_id = ?
       AND r.status IN ('accepted', 'processing')
       AND r.report_id IS NULL
     ORDER BY r.created_at ASC"
);

$stmt->bind_param('i', $operatorId);
$stmt->execute();
$requests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload Lab Reports - MRMS</title>

    <style>
        body {
            font-family: Arial, sans-serif;
            background: #f5f7fb;
            color: #222;
            margin: 0;
            padding: 24px;
        }

        .container {
            max-width: 1000px;
            margin: auto;
        }

        .request {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            padding: 22px;
            margin: 18px 0;
        }

        .request h3 {
            margin-top: 0;
        }

        .info {
            margin: 9px 0;
            color: #555;
        }

        label {
            display: block;
            margin: 14px 0 6px;
            font-weight: 600;
        }

        input, textarea {
            box-sizing: border-box;
            width: 100%;
            padding: 11px;
            border: 1px solid #ccc;
            border-radius: 6px;
            font: inherit;
        }

        textarea {
            min-height: 85px;
            resize: vertical;
        }

        button {
            margin-top: 16px;
            padding: 11px 20px;
            border: 0;
            border-radius: 6px;
            background: #16834a;
            color: white;
            cursor: pointer;
            font-size: 15px;
        }

        .alert {
            padding: 13px;
            border-radius: 7px;
            margin: 18px 0;
        }

        .success {
            background: #d1fae5;
            color: #065f46;
        }

        .error {
            background: #fee2e2;
            color: #991b1b;
        }

        .empty {
            background: white;
            padding: 30px;
            border-radius: 10px;
            text-align: center;
            color: #666;
        }
    </style>
</head>

<body>
<div class="container">

    <h2>Upload Test Results</h2>
    <p>Laboratory: <?= e($operator['laboratory_name']) ?></p>

    <?php if ($message !== ''): ?>
        <div class="alert <?= e($messageType) ?>">
            <?= e($message) ?>
        </div>
    <?php endif; ?>

    <?php if (!$requests): ?>

        <div class="empty">
            <h3>No reports waiting for upload</h3>
            <p>Accepted test requests without reports will appear here.</p>
        </div>

    <?php else: ?>

        <?php foreach ($requests as $request): ?>

            <div class="request">

                <h3><?= e($request['test_name']) ?></h3>

                <p class="info">
                    <strong>Patient:</strong>
                    <?= e($request['patient_name']) ?>
                </p>

                <p class="info">
                    <strong>Patient ID:</strong>
                    <?= (int)$request['patient_id'] ?>
                </p>

                <p class="info">
                    <strong>Reason:</strong>
                    <?= e($request['reason'] ?: 'Not provided') ?>
                </p>

                <p class="info">
                    <strong>Status:</strong>
                    <?= e($request['status']) ?>
                </p>

                <form method="POST" enctype="multipart/form-data">

                    <input type="hidden" name="csrf_token"
                           value="<?= e($_SESSION['csrf_upload_lab_report']) ?>">

                    <input type="hidden" name="request_id"
                           value="<?= (int)$request['id'] ?>">

                    <label>Report Title</label>
                    <input type="text" name="report_title"
                           maxlength="200"
                           placeholder="<?= e($request['test_name']) ?> Report">

                    <label>Test Date *</label>
                    <input type="date" name="test_date"
                           value="<?= e(date('Y-m-d')) ?>" required>

                    <label>Report File (PDF, JPG, PNG — Max 10 MB) *</label>
                    <input type="file" name="report_file"
                           accept=".pdf,.jpg,.jpeg,.png"
                           required>

                    <label>Remarks (Optional)</label>
                    <textarea name="remarks"
                              placeholder="Additional information about this report"></textarea>

                    <button type="submit">
                        Upload Report & Complete Request
                    </button>

                </form>

            </div>

        <?php endforeach; ?>

    <?php endif; ?>

</div>
</body>
</html>
