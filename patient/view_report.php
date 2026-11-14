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

$userId = (int)($_SESSION['user_id'] ?? 0);
$reportId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if ($userId <= 0 || !$reportId || $reportId <= 0) {
    http_response_code(400);
    exit('Invalid report request.');
}

/*
|--------------------------------------------------------------------------
| Find the logged-in patient's profile
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT id
    FROM patients
    WHERE user_id = ?
    LIMIT 1
");

if (!$stmt) {
    http_response_code(500);
    exit('Unable to process request.');
}

$stmt->bind_param('i', $userId);
$stmt->execute();

$patient = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$patient) {
    http_response_code(403);
    exit('Patient profile not found.');
}

$patientId = (int)$patient['id'];

/*
|--------------------------------------------------------------------------
| Fetch only this patient's verified report
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT
        original_file_name,
        file_path,
        mime_type
    FROM medical_reports
    WHERE id = ?
      AND patient_id = ?
      AND verification_status = 'verified'
    LIMIT 1
");

if (!$stmt) {
    http_response_code(500);
    exit('Unable to process report request.');
}

$stmt->bind_param('ii', $reportId, $patientId);
$stmt->execute();

$report = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$report) {
    http_response_code(404);
    exit('Report not found or access denied.');
}

/*
|--------------------------------------------------------------------------
| Resolve the private report path
|--------------------------------------------------------------------------
*/

$projectRoot = realpath(__DIR__ . '/..');

if ($projectRoot === false) {
    http_response_code(500);
    exit('Project directory unavailable.');
}

$privateUploadRoot = realpath(
    $projectRoot . DIRECTORY_SEPARATOR . 'private_uploads'
);

if ($privateUploadRoot === false) {
    http_response_code(404);
    exit('Report storage unavailable.');
}

$storedPath = str_replace(
    ['/', '\\'],
    DIRECTORY_SEPARATOR,
    (string)$report['file_path']
);

/*
 * Only accept paths stored relative to the project root.
 * Absolute paths and traversal attempts are rejected.
 */
if (
    $storedPath === '' ||
    str_starts_with($storedPath, DIRECTORY_SEPARATOR) ||
    preg_match('/^[A-Za-z]:/', $storedPath) ||
    preg_match('/(^|\\\\)\.\.(\\\\|$)/', $storedPath)
) {
    http_response_code(400);
    exit('Invalid report path.');
}

$expectedPrefix = 'private_uploads' . DIRECTORY_SEPARATOR . 'medical_reports' . DIRECTORY_SEPARATOR;

if (!str_starts_with($storedPath, $expectedPrefix)) {
    http_response_code(403);
    exit('Invalid report storage location.');
}

$filePath = realpath(
    $projectRoot . DIRECTORY_SEPARATOR . $storedPath
);

if ($filePath === false || !is_file($filePath) || !is_readable($filePath)) {
    http_response_code(404);
    exit('Report file not found.');
}

/*
 * Ensure the resolved file remains inside medical_reports.
 * This also prevents symlink/path traversal outside the folder.
 */
$medicalReportsRoot = realpath(
    $privateUploadRoot . DIRECTORY_SEPARATOR . 'medical_reports'
);

if ($medicalReportsRoot === false) {
    http_response_code(404);
    exit('Report storage unavailable.');
}

$allowedBase = rtrim($medicalReportsRoot, DIRECTORY_SEPARATOR)
    . DIRECTORY_SEPARATOR;

if (!str_starts_with($filePath, $allowedBase)) {
    http_response_code(403);
    exit('Access to this file is not allowed.');
}

/*
|--------------------------------------------------------------------------
| Validate the actual file MIME type
|--------------------------------------------------------------------------
*/

$finfo = new finfo(FILEINFO_MIME_TYPE);
$actualMime = $finfo->file($filePath);

$allowedMimes = [
    'application/pdf',
    'image/jpeg',
    'image/png'
];

if (!in_array($actualMime, $allowedMimes, true)) {
    http_response_code(415);
    exit('Unsupported report file type.');
}

/*
|--------------------------------------------------------------------------
| Serve the report securely
|--------------------------------------------------------------------------
*/

$originalName = (string)($report['original_file_name'] ?? 'medical-report');

$originalName = basename(str_replace('\\', '/', $originalName));

if ($originalName === '') {
    $originalName = 'medical-report';
}

$asciiName = preg_replace('/[^A-Za-z0-9._-]/', '_', $originalName);

if ($asciiName === '' || $asciiName === null) {
    $asciiName = 'medical-report';
}

$dispositionName = rawurlencode($originalName);

while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: ' . $actualMime);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Content-Disposition: inline; filename="' . $asciiName . '"; filename*=UTF-8\'\'' . $dispositionName);
header('Content-Length: ' . (string)filesize($filePath));

readfile($filePath);
exit;
