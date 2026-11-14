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

function denyAccess(int $status, string $message): void
{
    http_response_code($status);
    exit($message);
}

// ------------------------------------
// AUTHENTICATED DOCTOR
// ------------------------------------

$doctorUserId = (int)($_SESSION['user_id'] ?? 0);

if ($doctorUserId <= 0) {
    denyAccess(403, 'Invalid doctor session.');
}

$stmt = $conn->prepare(
    "SELECT id
     FROM doctors
     WHERE user_id = ?
       AND verification_status = 'approved'
     LIMIT 1"
);

$stmt->bind_param('i', $doctorUserId);
$stmt->execute();

$doctor = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$doctor) {
    denyAccess(403, 'Approved doctor profile not found.');
}

$doctorId = (int)$doctor['id'];

// ------------------------------------
// VALIDATE REQUEST
// ------------------------------------

$reportId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$patientId = filter_input(INPUT_GET, 'patient_id', FILTER_VALIDATE_INT);

if (!$reportId || $reportId <= 0 || !$patientId || $patientId <= 0) {
    denyAccess(400, 'Invalid report request.');
}

// ------------------------------------
// VERIFY PATIENT ACCESS AND OWNERSHIP
// ------------------------------------

$stmt = $conn->prepare(
    "SELECT
        mr.id,
        mr.patient_id,
        mr.report_title,
        mr.original_file_name,
        mr.file_path,
        mr.mime_type,
        mr.verification_status
     FROM medical_reports mr
     INNER JOIN patient_doctor_access pa
        ON pa.patient_id = mr.patient_id
     WHERE mr.id = ?
       AND mr.patient_id = ?
       AND mr.verification_status = 'verified'
       AND pa.doctor_id = ?
       AND pa.access_status = 'active'
       AND (
            pa.expires_at IS NULL
            OR pa.expires_at > NOW()
       )
     LIMIT 1"
);

$stmt->bind_param(
    'iii',
    $reportId,
    $patientId,
    $doctorId
);

$stmt->execute();
$report = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$report) {
    denyAccess(
        403,
        'You do not have permission to view this report. Patient access may have been revoked.'
    );
}

// ------------------------------------
// RESOLVE PROJECT AND UPLOAD DIRECTORY
// ------------------------------------

$projectRoot = realpath(__DIR__ . '/..');

if ($projectRoot === false) {
    denyAccess(500, 'Project directory not found.');
}

$uploadDirectory = realpath(
    $projectRoot . DIRECTORY_SEPARATOR .
    'private_uploads' . DIRECTORY_SEPARATOR .
    'medical_reports'
);

if (
    $uploadDirectory === false ||
    !is_dir($uploadDirectory)
) {
    denyAccess(404, 'Medical report storage directory not found.');
}

// ------------------------------------
// RESOLVE DATABASE FILE PATH
// ------------------------------------

$storedPath = trim(
    str_replace('\\', '/', (string)$report['file_path'])
);

if ($storedPath === '') {
    denyAccess(404, 'Report file path is empty.');
}

/*
 * Database stores:
 *
 * private_uploads/medical_reports/filename.jpg
 *
 * Extract the portion after medical_reports/
 * so that the file is resolved from the actual
 * private upload directory.
 */

$pathMarker = 'private_uploads/medical_reports/';

$markerPosition = strpos($storedPath, $pathMarker);

if ($markerPosition !== false) {
    $relativeFilePath = substr(
        $storedPath,
        $markerPosition + strlen($pathMarker)
    );
} else {
    // Also support a filename stored directly.
    $relativeFilePath = basename($storedPath);
}

$relativeFilePath = trim($relativeFilePath, '/');

if (
    $relativeFilePath === '' ||
    strpos($relativeFilePath, "\0") !== false
) {
    denyAccess(404, 'Invalid report file path.');
}

// Reject directory traversal.
$pathParts = explode('/', $relativeFilePath);

foreach ($pathParts as $part) {
    if ($part === '..' || $part === '.') {
        denyAccess(403, 'Invalid file path.');
    }
}

$relativeFilePath = str_replace(
    '/',
    DIRECTORY_SEPARATOR,
    $relativeFilePath
);

$filePath = realpath(
    $uploadDirectory . DIRECTORY_SEPARATOR . $relativeFilePath
);

// Ensure the resolved file stays inside the report directory.
$allowedPrefix = rtrim(
    $uploadDirectory,
    DIRECTORY_SEPARATOR
) . DIRECTORY_SEPARATOR;

if (
    $filePath === false ||
    !is_file($filePath) ||
    strpos($filePath, $allowedPrefix) !== 0 ||
    !is_readable($filePath)
) {
    denyAccess(
        404,
        'Report file not found. Please check the stored file path and upload directory.'
    );
}

// ------------------------------------
// DETECT ACTUAL FILE MIME TYPE
// ------------------------------------

if (!class_exists('finfo')) {
    denyAccess(500, 'PHP Fileinfo extension is unavailable.');
}

$finfo = new finfo(FILEINFO_MIME_TYPE);

$actualMime = $finfo->file($filePath);

$allowedMimes = [
    'application/pdf',
    'image/jpeg',
    'image/png'
];

if (
    !is_string($actualMime) ||
    !in_array($actualMime, $allowedMimes, true)
) {
    denyAccess(415, 'Unsupported or invalid report file type.');
}

$mimeType = $actualMime;

// ------------------------------------
// FILE INFORMATION
// ------------------------------------

$fileSize = filesize($filePath);

if ($fileSize === false) {
    denyAccess(500, 'Unable to read report file.');
}

$downloadName = trim(
    (string)$report['original_file_name']
);

if ($downloadName === '') {
    $downloadName = 'medical-report';
}

// Prevent header injection.
$downloadName = str_replace(
    ["\r", "\n", '"', '\\'],
    '_',
    $downloadName
);

// ------------------------------------
// SEND FILE TO BROWSER
// ------------------------------------

session_write_close();

// Remove any accidental output before sending binary data.
while (ob_get_level() > 0) {
    ob_end_clean();
}

if (headers_sent()) {
    denyAccess(500, 'Cannot send report headers.');
}

header('Content-Type: ' . $mimeType);

header('Content-Length: ' . $fileSize);

header(
    'Content-Disposition: inline; filename="' .
    $downloadName .
    '"; filename*=UTF-8\'\'' .
    rawurlencode($downloadName)
);

header('X-Content-Type-Options: nosniff');

header('X-Frame-Options: SAMEORIGIN');

header('Cache-Control: private, no-store, no-cache, must-revalidate');

header('Pragma: no-cache');

header('Expires: 0');

readfile($filePath);

exit;
