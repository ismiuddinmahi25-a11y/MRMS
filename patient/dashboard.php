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



function mrmsGetColumns(mysqli $conn, string $table): array
{
    $allowedTables = [
        'users',
        'patients',
        'appointments',
        'doctors',
        'medical_reports',
        'notifications'
    ];

    if (!in_array($table, $allowedTables, true)) {
        return [];
    }

    try {
        $result = $conn->query("SHOW COLUMNS FROM $table");

        if (!$result) {
            return [];
        }

        $columns = [];

        while ($row = $result->fetch_assoc()) {
            $columns[] = $row['Field'];
        }

        return $columns;

    } catch (Throwable $e) {
        error_log('MRMS schema error: ' . $e->getMessage());
        return [];
    }
}

function mrmsFindOne(
    mysqli $conn,
    string $table,
    array $columns,
    string $column,
    $value
): ?array {

    if (!in_array($column, $columns, true)) {
        return null;
    }

    try {
        $sql = "SELECT * FROM $table
                WHERE $column = ?
                LIMIT 1";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param('s', $value);
        $stmt->execute();

        $result = $stmt->get_result();
        $data = $result->fetch_assoc() ?: null;

        $stmt->close();

        return $data;

    } catch (Throwable $e) {
        error_log('MRMS fetch error: ' . $e->getMessage());
        return null;
    }
}

function mrmsFindAll(
    mysqli $conn,
    string $table,
    array $columns,
    string $column,
    $value
): array {

    if (!in_array($column, $columns, true)) {
        return [];
    }

    try {
        $sql = "SELECT * FROM $table
                WHERE $column = ?
                ORDER BY id DESC";

        // Use ordering only when the table has an id column.
        if (!in_array('id', $columns, true)) {
            $sql = "SELECT * FROM $table
                    WHERE $column = ?";
        }

        $stmt = $conn->prepare($sql);
        $stmt->bind_param('s', $value);
        $stmt->execute();

        $result = $stmt->get_result();
        $rows = $result->fetch_all(MYSQLI_ASSOC);

        $stmt->close();

        return $rows;

    } catch (Throwable $e) {
        error_log('MRMS fetch error: ' . $e->getMessage());
        return [];
    }
}



$usersColumns = mrmsGetColumns($conn, 'users');
$patientsColumns = mrmsGetColumns($conn, 'patients');
$appointmentsColumns = mrmsGetColumns($conn, 'appointments');
$doctorsColumns = mrmsGetColumns($conn, 'doctors');
$reportsColumns = mrmsGetColumns($conn, 'medical_reports');
$notificationsColumns = mrmsGetColumns($conn, 'notifications');



$user = null;

foreach (['user_id', 'id'] as $key) {
    if (in_array($key, $usersColumns, true)) {
        $user = mrmsFindOne(
            $conn,
            'users',
            $usersColumns,
            $key,
            $userId
        );

        if ($user !== null) {
            break;
        }
    }
}

if ($user === null) {
    http_response_code(404);
    exit('Patient account not found.');
}



$patient = null;

if (in_array('user_id', $patientsColumns, true)) {
    $patient = mrmsFindOne(
        $conn,
        'patients',
        $patientsColumns,
        'user_id',
        $userId
    );
}



$patientId = null;

if ($patient !== null) {
    $patientId = $patient['id']
        ?? $patient['patient_id']
        ?? null;
}

$doctorAccessList = [];

if ($patientId !== null) {

    try {

        $stmt = $conn->prepare("
            SELECT
                d.id AS doctor_id,
                u.full_name,
                d.specialization,
                d.workplace,
                MAX(
                    CASE
                        WHEN pda.access_status = 'active' THEN 1
                        ELSE 0
                    END
                ) AS has_access

            FROM appointments a

            INNER JOIN doctors d
                ON d.id = a.doctor_id

            INNER JOIN users u
                ON u.id = d.user_id

            LEFT JOIN patient_doctor_access pda
                ON pda.patient_id = a.patient_id
                AND pda.doctor_id = a.doctor_id

            WHERE a.patient_id = ?
              AND a.status = 'confirmed'

            GROUP BY
                d.id,
                u.full_name,
                d.specialization,
                d.workplace

            ORDER BY u.full_name ASC
        ");

        $stmt->bind_param("i", $patientId);
        $stmt->execute();

        $result = $stmt->get_result();

        $doctorAccessList = $result->fetch_all(MYSQLI_ASSOC);

        $stmt->close();

    } catch (Throwable $e) {

        error_log(
            'MRMS patient doctor access fetch error: ' . $e->getMessage()
        );

    }
}

$availableDoctors = [];

try {
    $sql = "
        SELECT
            d.id AS doctor_id,
            d.user_id,
            u.full_name,
            u.email,
            d.specialization,
            d.qualification,
            d.workplace,
            d.experience_years
        FROM doctors d
        INNER JOIN users u ON u.id = d.user_id
        WHERE d.verification_status = 'approved'
          AND u.account_status = 'active'
        ORDER BY u.full_name ASC
    ";

    $result = $conn->query($sql);

    if ($result) {
        $availableDoctors = $result->fetch_all(MYSQLI_ASSOC);
        $result->free();
    }

} catch (Throwable $e) {
    error_log('MRMS doctor fetch error: ' . $e->getMessage());
}




$appointments = [];

if (
    $patientId !== null &&
    in_array('patient_id', $appointmentsColumns, true)
) {
    $appointments = mrmsFindAll(
        $conn,
        'appointments',
        $appointmentsColumns,
        'patient_id',
        $patientId
    );
}



$medicalReports = [];

if (
    $patientId !== null &&
    in_array('patient_id', $reportsColumns, true)
) {
    $medicalReports = mrmsFindAll(
        $conn,
        'medical_reports',
        $reportsColumns,
        'patient_id',
        $patientId
    );
}



$prescriptions = [];

if ($patientId !== null) {

    try {

        $sql = "
            SELECT
                p.id,
                p.patient_id,
                p.doctor_id,
                p.appointment_id,
                p.diagnosis,
                p.medicines,
                p.medical_advice AS instructions,
                p.follow_up_date,
                p.created_at,

                u.full_name AS doctor_name,
                d.specialization AS doctor_specialization

            FROM prescriptions p

            INNER JOIN doctors d
                ON d.id = p.doctor_id

            INNER JOIN users u
                ON u.id = d.user_id

            WHERE p.patient_id = ?

            ORDER BY p.created_at DESC, p.id DESC
        ";

        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            throw new RuntimeException(
                'Prescription query preparation failed.'
            );
        }

        $stmt->bind_param("i", $patientId);
        $stmt->execute();

        $result = $stmt->get_result();

        $prescriptions = $result->fetch_all(MYSQLI_ASSOC);

        $stmt->close();

    } catch (Throwable $e) {

        error_log(
            'MRMS patient prescription error: ' . $e->getMessage()
        );

        $prescriptions = [];
    }
}


$verifiedLabReports = [];
$pendingLabReports = [];

foreach ($medicalReports as $report) {

    if (
        isset($report['verification_status']) &&
        $report['verification_status'] === 'verified'
    ) {
        $verifiedLabReports[] = $report;
    }

    elseif (
        isset($report['verification_status']) &&
        $report['verification_status'] === 'pending'
    ) {
        $pendingLabReports[] = $report;
    }
}


$patientDashboardData['lab_reports'] = $verifiedLabReports;


$notifications = [];

if (in_array('user_id', $notificationsColumns, true)) {
    $notifications = mrmsFindAll(
        $conn,
        'notifications',
        $notificationsColumns,
        'user_id',
        $userId
    );

} elseif (
    $patientId !== null &&
    in_array('patient_id', $notificationsColumns, true)
) {
    $notifications = mrmsFindAll(
        $conn,
        'notifications',
        $notificationsColumns,
        'patient_id',
        $patientId
    );
}


$totalAppointments = count($appointments);

$upcomingAppointments = 0;
$completedAppointments = 0;
$pendingAppointments = 0;

$today = date('Y-m-d');

foreach ($appointments as $appointment) {

    $status = strtolower(
        trim((string)($appointment['status'] ?? ''))
    );

    $appointmentDate = $appointment['appointment_date'] ?? '';

    if ($status === 'completed') {
        $completedAppointments++;
    }

    if ($status === 'pending') {
        $pendingAppointments++;
    }

    if (
        $appointmentDate >= $today &&
        in_array($status, ['pending', 'approved', 'confirmed'], true)
    ) {
        $upcomingAppointments++;
    }
}


$patientDashboardData = [
    'user' => $user,
    'profile' => $patient ?? [],
    'appointments' => $appointments,
    'available_doctors' => $availableDoctors,
    'medical_records' => $verifiedLabReports,
    'lab_reports' => $verifiedLabReports,
'prescriptions' => $prescriptions,
    'billing' => [],
    'notifications' => $notifications,

    'statistics' => [
        'total_appointments' => $totalAppointments,
        'upcoming_appointments' => $upcomingAppointments,
        'completed_appointments' => $completedAppointments,
        'pending_appointments' => $pendingAppointments,
        'medical_records' => count($medicalReports),
        'notifications' => count($notifications)
    ]
];


function e($value): string
{
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}



function pdIcon(string $name, string $class = 'pd-i'): string
{
    return '<svg class="' . e($class) . '" aria-hidden="true" focusable="false"><use href="#i-' . e($name) . '"></use></svg>';
}

function pdLabel(string $key): string
{
    return ucwords(str_replace('_', ' ', $key));
}

function pdDate($value, string $format = 'M j, Y'): string
{
    $ts = strtotime((string)$value);
    return $ts ? date($format, $ts) : (string)$value;
}

function pdTime($value): string
{
    $ts = strtotime('1970-01-01 ' . (string)$value);
    return $ts ? date('g:i A', $ts) : (string)$value;
}


function pdFirst(array $row, array $keys): ?array
{
    foreach ($keys as $k) {
        if (isset($row[$k]) && is_scalar($row[$k]) && trim((string)$row[$k]) !== '') {
            return [$k, trim((string)$row[$k])];
        }
    }
    return null;
}


function pdFields(array $row, array $hide = []): array
{
    $out = [];
    foreach ($row as $k => $v) {
        $k = (string)$k;
        if (in_array($k, $hide, true)) {
            continue;
        }
        if (preg_match('/(^id$|_id$|pass|token|hash|secret|salt|otp|path)/i', $k)) {
            continue;
        }
        if (!is_scalar($v)) {
            continue;
        }
        $s = trim((string)$v);
        if ($s === '' || strpos($s, '0000-00-00') === 0) {
            continue;
        }
        if (preg_match('/(date|_at)$/i', $k) && strtotime($s)) {
            $s = pdDate($s, strlen($s) > 10 ? 'M j, Y g:i A' : 'M j, Y');
        }
        $out[$k] = $s;
    }
    return $out;
}

function pdStatusClass(string $status): string
{
    switch ($status) {
        case 'completed':
            return 'is-success';
        case 'approved':
        case 'confirmed':
            return 'is-info';
        case 'pending':
            return 'is-warn';
        case 'cancelled':
        case 'canceled':
        case 'rejected':
        case 'declined':
            return 'is-danger';
        default:
            return 'is-neutral';
    }
}

function pdEmpty(string $icon, string $title, string $text): void
{
    echo '<div class="pd-empty">'
        . '<span class="pd-empty__icon">' . pdIcon($icon) . '</span>'
        . '<h3>' . e($title) . '</h3>'
        . '<p>' . e($text) . '</p>'
        . '</div>';
}


function pdNotificationRead(array $n): ?bool
{
    if (array_key_exists('is_read', $n) && $n['is_read'] !== null) {
        return in_array(strtolower((string)$n['is_read']), ['1', 'true', 'yes', 'read'], true);
    }
    if (array_key_exists('read_at', $n)) {
        return trim((string)$n['read_at']) !== '' && strpos((string)$n['read_at'], '0000-00-00') !== 0;
    }
    return null;
}

function pdNotificationParts(array $n): array
{
    $title = pdFirst($n, ['title', 'subject']);
    $body = pdFirst($n, ['message', 'body', 'content', 'description']);
    $time = pdFirst($n, ['created_at', 'date']);
    return [
        'title' => $title[1] ?? '',
        'body' => $body[1] ?? '',
        'time' => $time ? pdDate($time[1], 'M j, Y g:i A') : '',
    ];
}

function pdAppointmentRow(array $v, bool $filterable): void
{
    $a = $v['row'];
    $date = (string)($a['appointment_date'] ?? '');
    $time = (string)($a['appointment_time'] ?? '');
    $reason = trim((string)($a['reason'] ?? ''));
    $notes = trim((string)($a['doctor_notes'] ?? ''));
    $status = $v['status'];
    $ts = $date !== '' ? strtotime($date) : false;

    $search = strtolower(implode(' ', [$date, $time, $reason, $status, $notes]));
    $attrs = $filterable
        ? ' data-appointment data-searchable="' . e($search) . '" data-group="' . ($v['upcoming'] ? 'upcoming' : 'past') . '"'
        : '';

    echo '<article class="pd-appt"' . $attrs . '>';
    echo '<div class="pd-appt__date" aria-hidden="true"><span>' . e($ts ? date('M', $ts) : '') . '</span><strong>' . e($ts ? date('j', $ts) : '–') . '</strong></div>';
    echo '<div class="pd-appt__body">';
    echo '<div class="pd-appt__head"><h3>' . e($reason !== '' ? $reason : 'Appointment') . '</h3>';
    echo '<span class="pd-badge ' . e(pdStatusClass($status)) . '">' . e($status !== '' ? ucfirst($status) : 'No status') . '</span></div>';
    echo '<p class="pd-appt__meta">' . pdIcon('calendar') . '<span>' . e($date !== '' ? pdDate($date, 'l, M j, Y') : 'Date not set') . '</span>';
    if ($time !== '') {
        echo pdIcon('clock') . '<span>' . e(pdTime($time)) . '</span>';
    }
    echo '</p>';
    if ($notes !== '') {
        echo '<div class="pd-note"><strong>Doctor notes</strong><p>' . e($notes) . '</p></div>';
    }
    echo '</div></article>';
}


function pdRecordCards(
    array $rows,
    string $emptyIcon,
    string $emptyTitle,
    string $emptyText
): void {

    if (!$rows) {
        pdEmpty($emptyIcon, $emptyTitle, $emptyText);
        return;
    }

    echo '<div class="pd-record-grid">';

    foreach ($rows as $row) {

        if (!is_array($row)) {
            continue;
        }

        $isMedicalReport = (
            isset($row['id'], $row['verification_status']) &&
            array_key_exists('file_path', $row)
        );

        $titleHit = pdFirst($row, [
            'title',
            'test_name',
            'report_title',
            'report_name',
            'report_type',
            'diagnosis',
            'name',
            'original_file_name'
        ]);

        $dateHit = pdFirst($row, [
            'test_date',
            'report_date',
            'date',
            'created_at'
        ]);

        $hide = [];

        if ($titleHit) {
            $hide[] = $titleHit[0];
        }

        if ($dateHit) {
            $hide[] = $dateHit[0];
        }

        if ($isMedicalReport) {
            $hide = array_merge($hide, [
                'id',
                'patient_id',
                'uploaded_by',
                'verified_by',
                'file_path',
                'mime_type',
                'file_size',
                'verification_status',
                'verified_at',
                'created_at',
                'updated_at',
                'original_file_name'
            ]);
        }

        $fields = pdFields($row, $hide);

        $title = $titleHit[1] ??
            ('Record' . (isset($row['id']) ? ' #' . $row['id'] : ''));

        $search = strtolower($title . ' ' . implode(' ', $fields));

        echo '<article class="pd-record" data-searchable="' .
            e($search) . '">';

        echo '<header>';
        echo '<span class="pd-record__icon">' .
            pdIcon('file') . '</span>';

        echo '<div>';
        echo '<h3>' . e($title) . '</h3>';

        if ($dateHit) {
            echo '<time>' .
                e(pdDate(
                    $dateHit[1],
                    strlen($dateHit[1]) > 10
                        ? 'M j, Y g:i A'
                        : 'M j, Y'
                )) .
                '</time>';
        }

        echo '</div>';
        echo '</header>';

        if ($fields) {
            echo '<dl>';

            foreach ($fields as $k => $val) {
                echo '<div>';
                echo '<dt>' . e(pdLabel($k)) . '</dt>';
                echo '<dd>' . e($val) . '</dd>';
                echo '</div>';
            }

            echo '</dl>';
        }

        /*
         * Secure medical report preview
         */
        if (
            $isMedicalReport &&
            (int)$row['id'] > 0 &&
            ($row['verification_status'] ?? '') === 'verified'
        ) {

            $reportUrl = 'view_report.php?id=' .
                (int)$row['id'];

            $mime = strtolower(
                trim((string)($row['mime_type'] ?? ''))
            );

            echo '<div class="pd-report-preview">';

            /*
             * Image preview
             */
            if (in_array($mime, [
                'image/png',
                'image/jpeg'
            ], true)) {

                echo '<div style="
                    margin:18px 0;
                    padding:12px;
                    background:#f8fafc;
                    border:1px solid #e2e8f0;
                    border-radius:12px;
                    text-align:center;
                ">';

                echo '<img
                    src="' . e($reportUrl) . '"
                    alt="' . e($title) . '"
                    loading="lazy"
                    style="
                        display:block;
                        width:100%;
                        max-width:100%;
                        max-height:420px;
                        object-fit:contain;
                        margin:auto;
                        border-radius:8px;
                    "
                >';

                echo '</div>';

            }

            /*
             * PDF preview
             */
            elseif ($mime === 'application/pdf') {

                echo '<iframe
                    src="' . e($reportUrl) . '"
                    title="' . e($title) . '"
                    loading="lazy"
                    style="
                        width:100%;
                        height:500px;
                        margin:18px 0;
                        border:1px solid #e2e8f0;
                        border-radius:10px;
                        background:#fff;
                    "
                ></iframe>';

            }

            /*
             * View / Download
             */
            echo '<a
                class="pd-btn pd-btn--ghost"
                href="' . e($reportUrl) . '"
                target="_blank"
                rel="noopener"
            >View / Download Report</a>';

            echo '</div>';
        }

        echo '</article>';
    }

    echo '</div>';
}



$stats = $patientDashboardData['statistics'] ?? [];
$profile = $patientDashboardData['profile'] ?? [];
$account = $patientDashboardData['user'] ?? [];


$displayName = '';
foreach ([$profile, $account] as $src) {
    $hit = pdFirst($src, ['full_name', 'name']);
    if ($hit) {
        $displayName = $hit[1];
        break;
    }
    $first = trim((string)($src['first_name'] ?? ''));
    $last = trim((string)($src['last_name'] ?? ''));
    if ($first !== '' || $last !== '') {
        $displayName = trim($first . ' ' . $last);
        break;
    }
}
if ($displayName === '') {
    $hit = pdFirst($account, ['username', 'email']);
    $displayName = $hit[1] ?? 'Patient';
}
$firstName = trim((string)(explode(' ', $displayName)[0] ?? $displayName));

$initials = '';
foreach (array_slice(preg_split('/\s+/', $displayName) ?: [], 0, 2) as $part) {
    $initials .= strtoupper(mb_substr($part, 0, 1));
}
$initials = $initials !== '' ? $initials : 'P';

$emailHit = pdFirst($account, ['email']) ?? pdFirst($profile, ['email']);
$email = $emailHit[1] ?? '';

$hour = (int)date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');


$upcomingStatuses = ['pending', 'approved', 'confirmed'];
$apptView = [];
foreach ($appointments as $a) {
    $st = strtolower(trim((string)($a['status'] ?? '')));
    $d = (string)($a['appointment_date'] ?? '');
    $apptView[] = [
        'row' => $a,
        'status' => $st,
        'upcoming' => $d !== '' && $d >= $today && in_array($st, $upcomingStatuses, true),
    ];
}
$upcomingView = array_values(array_filter($apptView, static fn(array $v): bool => $v['upcoming']));
usort($upcomingView, static function (array $x, array $y): int {
    return strcmp(
        (string)($x['row']['appointment_date'] ?? '') . ' ' . (string)($x['row']['appointment_time'] ?? ''),
        (string)($y['row']['appointment_date'] ?? '') . ' ' . (string)($y['row']['appointment_time'] ?? '')
    );
});
$nextAppointment = $upcomingView[0] ?? null;


$unreadKnown = false;
$unreadCount = 0;
foreach ($notifications as $n) {
    $state = pdNotificationRead($n);
    if ($state !== null) {
        $unreadKnown = true;
        if ($state === false) {
            $unreadCount++;
        }
    }
}
$bellCount = $unreadKnown ? $unreadCount : (int)($stats['notifications'] ?? 0);
$bellLabel = $unreadKnown ? 'unread notifications' : 'notifications';

$profileFields = pdFields($profile);
$accountFields = pdFields($account);

$sections = [
    'overview' => 'Overview',
    'profile' => 'My Profile',
    'appointments' => 'Appointments',
    'book' => 'Book Appointment',
    'records' => 'Medical Records',
    'doctor-access' => 'Doctor Access',
    'prescriptions' => 'Prescriptions',
    'labs' => 'Lab Reports',
    'billing' => 'Billing & Payments',
    'notifications' => 'Notifications',
    'settings' => 'Settings',
];
$navIcons = [
    'overview' => 'home', 'profile' => 'user', 'appointments' => 'calendar', 'book' => 'plus',
    'records' => 'file', 'doctor-access' => 'shield-check','prescriptions' => 'pill', 'labs' => 'flask', 'billing' => 'card',
    'notifications' => 'bell', 'settings' => 'settings',
];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Patient Dashboard | MRMS</title>
    <link rel="stylesheet" href="../auth/assets/css/patient-dashboard.css">
</head>
<body class="mrms-pd">

<svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false">
    <defs>
        <symbol id="i-home" viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></symbol>
        <symbol id="i-user" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></symbol>
        <symbol id="i-calendar" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></symbol>
        <symbol id="i-plus" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></symbol>
        <symbol id="i-file" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></symbol>
        <symbol id="i-pill" viewBox="0 0 24 24"><path d="M10.5 20.5a5 5 0 0 1-7-7l10-10a5 5 0 0 1 7 7z"/><line x1="8.5" y1="8.5" x2="15.5" y2="15.5"/></symbol>
        <symbol id="i-flask" viewBox="0 0 24 24"><path d="M9 3h6"/><path d="M10 3v6L4.5 19a1.5 1.5 0 0 0 1.3 2.2h12.4a1.5 1.5 0 0 0 1.3-2.2L14 9V3"/></symbol>
        <symbol id="i-card" viewBox="0 0 24 24"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></symbol>
        <symbol id="i-bell" viewBox="0 0 24 24"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/></symbol>
        <symbol id="i-settings" viewBox="0 0 24 24"><line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/><line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/><line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="20" y2="3"/><line x1="1" y1="14" x2="7" y2="14"/><line x1="9" y1="8" x2="15" y2="8"/><line x1="17" y1="16" x2="23" y2="16"/></symbol>
        <symbol id="i-logout" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></symbol>
        <symbol id="i-search" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></symbol>
        <symbol id="i-menu" viewBox="0 0 24 24"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></symbol>
        <symbol id="i-chevron-left" viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></symbol>
        <symbol id="i-chevron-down" viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></symbol>
        <symbol id="i-clock" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></symbol>
        <symbol id="i-check" viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></symbol>
        <symbol id="i-info" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></symbol>
        <symbol id="i-inbox" viewBox="0 0 24 24"><polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/></symbol>
        <symbol id="i-cross" viewBox="0 0 24 24"><path d="M9 3h6v6h6v6h-6v6H9v-6H3V9h6z"/></symbol>
    </defs>
</svg>

<a class="pd-skip" href="#pd-main">Skip to main content</a>

<div class="pd-shell" id="pd-shell">

    <!-- Sidebar -->
    <aside class="pd-sidebar" id="pd-sidebar" aria-label="Patient navigation">
        <div class="pd-sidebar__brand">
            <a href="../index.php" class="pd-brand" aria-label="MRMS home">
                <span class="pd-brand__mark"><?= pdIcon('cross') ?></span>
                <span class="pd-brand__name">MRMS</span>
            </a>
            <button type="button" class="pd-icon-btn pd-sidebar__close" data-nav-close aria-label="Close navigation"><?= pdIcon('chevron-left') ?></button>
        </div>
        
<nav class="pd-nav">
    <ul>
        <li>
            <a href="request_lab_test.php"
               class="pd-nav__link"
               title="Request Lab Test">
                <?= pdIcon('flask') ?>
                <span class="pd-nav__text">Request Lab Test</span>
            </a>
        </li>

        <?php foreach ($sections as $key => $label): ?>
            <li>
                <a href="#<?= e($key) ?>"
                   class="pd-nav__link"
                   data-section-link="<?= e($key) ?>"
                   title="<?= e($label) ?>">
                    <?= pdIcon($navIcons[$key]) ?>
                    <span class="pd-nav__text"><?= e($label) ?></span>

                    <?php if ($key === 'notifications' && $bellCount > 0): ?>
                        <span class="pd-nav__count">
                            <?= e($bellCount) ?>
                        </span>
                    <?php endif; ?>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
</nav>

        <div class="pd-sidebar__foot">
            <a href="../auth/logout.php" class="pd-nav__link" data-logout title="Logout">
                <?= pdIcon('logout') ?><span class="pd-nav__text">Logout</span>
            </a>
        </div>
    </aside>
    <div class="pd-overlay" data-nav-close hidden></div>

    <!-- Main column -->
    <div class="pd-main">
        <header class="pd-topbar">
            <button type="button" class="pd-icon-btn" id="pd-sidebar-toggle" aria-controls="pd-sidebar" aria-expanded="true" aria-label="Toggle navigation"><?= pdIcon('menu') ?></button>

            <div class="pd-topbar__title">
                <span class="pd-crumbs">Patient portal / <span data-current-title>Overview</span></span>
            </div>

            <label class="pd-search">
                <?= pdIcon('search') ?>
                <span class="pd-sr">Search this section</span>
                <input type="search" id="pd-search" placeholder="Search this section" autocomplete="off">
            </label>

            <div class="pd-dropdown">
                <button type="button" class="pd-icon-btn pd-bell" data-dropdown-toggle="pd-notif-menu" aria-expanded="false" aria-haspopup="true" aria-label="Notifications: <?= e($bellCount) ?> <?= e($bellLabel) ?>">
                    <?= pdIcon('bell') ?>
                    <?php if ($bellCount > 0): ?><span class="pd-bell__count"><?= e($bellCount > 99 ? '99+' : $bellCount) ?></span><?php endif; ?>
                </button>
                <div class="pd-menu pd-menu--wide" id="pd-notif-menu" hidden>
                    <div class="pd-menu__head">Notifications</div>
                    <?php if (!$notifications): ?>
                        <p class="pd-menu__empty">You're all caught up.</p>
                    <?php else: ?>
                        <ul class="pd-menu__list">
                            <?php foreach (array_slice($notifications, 0, 5) as $n): $p = pdNotificationParts($n); $read = pdNotificationRead($n); ?>
                                <li class="<?= $read === false ? 'is-unread' : '' ?>">
                                    <strong><?= e($p['title'] !== '' ? $p['title'] : ($p['body'] !== '' ? $p['body'] : 'Notification')) ?></strong>
                                    <?php if ($p['title'] !== '' && $p['body'] !== ''): ?><span><?= e($p['body']) ?></span><?php endif; ?>
                                    <?php if ($p['time'] !== ''): ?><time><?= e($p['time']) ?></time><?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <a href="#notifications" class="pd-menu__foot" data-section-link="notifications">View all notifications</a>
                </div>
            </div>

            <div class="pd-dropdown">
                <button type="button" class="pd-profile-btn" data-dropdown-toggle="pd-profile-menu" aria-expanded="false" aria-haspopup="true">
                    <span class="pd-avatar"><?= e($initials) ?></span>
                    <span class="pd-profile-btn__name"><?= e($displayName) ?></span>
                    <?= pdIcon('chevron-down', 'pd-i pd-i--sm') ?>
                </button>
                <div class="pd-menu" id="pd-profile-menu" hidden>
                    <div class="pd-menu__head">
                        <strong><?= e($displayName) ?></strong>
                        <?php if ($email !== ''): ?><span><?= e($email) ?></span><?php endif; ?>
                    </div>
                    <a href="#profile" role="menuitem" data-section-link="profile"><?= pdIcon('user') ?>My Profile</a>
                    <a href="#settings" role="menuitem" data-section-link="settings"><?= pdIcon('settings') ?>Settings</a>
                    <a href="../auth/logout.php" role="menuitem" data-logout><?= pdIcon('logout') ?>Logout</a>
                </div>
            </div>
        </header>

        <main class="pd-content" id="pd-main" tabindex="-1">

            
            <section class="pd-section" data-section="overview" aria-labelledby="h-overview">
                <div class="pd-page-head">
                    <div>
                        <h1 id="h-overview"><?= e($greeting) ?>, <?= e($firstName) ?></h1>
                        <p>Here's a summary of your care and appointments.</p>
                    </div>
                    <a href="#book" class="pd-btn pd-btn--primary" data-section-link="book"><?= pdIcon('plus') ?>Book appointment</a>
                </div>

                <div class="pd-stats">
                    <?php
                    $statCards = [
                        ['Total appointments', $stats['total_appointments'] ?? 0, 'calendar', 'is-blue'],
                        ['Upcoming', $stats['upcoming_appointments'] ?? 0, 'clock', 'is-teal'],
                        ['Completed', $stats['completed_appointments'] ?? 0, 'check', 'is-green'],
                        ['Pending', $stats['pending_appointments'] ?? 0, 'info', 'is-amber'],
                    ];
                    foreach ($statCards as [$label, $value, $icon, $tone]): ?>
                        <div class="pd-stat">
                            <span class="pd-stat__icon <?= e($tone) ?>"><?= pdIcon($icon) ?></span>
                            <div><strong><?= e($value) ?></strong><span><?= e($label) ?></span></div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="pd-grid pd-grid--2">
                    <div class="pd-card">
                        <div class="pd-card__head"><h2>Next appointment</h2><a href="#appointments" data-section-link="appointments">All appointments</a></div>
                        <?php if ($nextAppointment): pdAppointmentRow($nextAppointment, false); else:
                            pdEmpty('calendar', 'No upcoming appointments', 'When you have a pending or confirmed visit, it will appear here.');
                        endif; ?>
                    </div>

                    <div class="pd-card">
                        <div class="pd-card__head"><h2>Quick actions</h2></div>
                        <div class="pd-actions">
                            <a href="#book" data-section-link="book"><?= pdIcon('plus') ?><span>Book appointment</span></a>
                                                        <a href="request_lab_test.php">
                                <?= pdIcon('flask') ?>
                                <span>Request Lab Test</span>
                            </a>
                            <a href="#records" data-section-link="records"><?= pdIcon('file') ?><span>Medical records</span></a>
                            <a href="#notifications" data-section-link="notifications"><?= pdIcon('bell') ?><span>Notifications</span></a>
                            <a href="#profile" data-section-link="profile"><?= pdIcon('user') ?><span>My profile</span></a>
                        </div>
                    </div>

                    <div class="pd-card">
                        <div class="pd-card__head"><h2>Recent medical records</h2><a href="#records" data-section-link="records">View all</a></div>
                        <?php if (!$medicalReports):
                            pdEmpty('file', 'No medical records yet', 'Reports added to your file will show up here.');
                        else: ?>
                            <ul class="pd-list">
                                <?php foreach (array_slice($medicalReports, 0, 3) as $r):
                                    $t = pdFirst($r, ['title', 'report_title', 'report_name', 'report_type', 'diagnosis', 'name']);
                                    $d = pdFirst($r, ['report_date', 'date', 'created_at']); ?>
                                    <li>
                                        <span class="pd-list__icon"><?= pdIcon('file') ?></span>
                                        <div><strong><?= e($t[1] ?? ('Record' . (isset($r['id']) ? ' #' . $r['id'] : ''))) ?></strong>
                                            <?php if ($d): ?><span><?= e(pdDate($d[1])) ?></span><?php endif; ?></div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>

                    <div class="pd-card">
                        <div class="pd-card__head"><h2>Recent notifications</h2><a href="#notifications" data-section-link="notifications">View all</a></div>
                        <?php if (!$notifications):
                            pdEmpty('bell', 'No notifications', 'Updates about your care will appear here.');
                        else: ?>
                            <ul class="pd-list">
                                <?php foreach (array_slice($notifications, 0, 4) as $n): $p = pdNotificationParts($n); $read = pdNotificationRead($n); ?>
                                    <li class="<?= $read === false ? 'is-unread' : '' ?>">
                                        <span class="pd-list__icon"><?= pdIcon('bell') ?></span>
                                        <div><strong><?= e($p['title'] !== '' ? $p['title'] : ($p['body'] !== '' ? $p['body'] : 'Notification')) ?></strong>
                                            <?php if ($p['time'] !== ''): ?><span><?= e($p['time']) ?></span><?php endif; ?></div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>
            </section>

            
            <section class="pd-section" data-section="profile" aria-labelledby="h-profile" hidden>
                <div class="pd-page-head"><div><h1 id="h-profile">My Profile</h1><p>Your personal and account details on file.</p></div></div>

                <div class="pd-card pd-profile-head">
                    <span class="pd-avatar pd-avatar--lg"><?= e($initials) ?></span>
                    <div><h2><?= e($displayName) ?></h2><?php if ($email !== ''): ?><p><?= e($email) ?></p><?php endif; ?></div>
                </div>

                <div class="pd-grid pd-grid--2">
                    <div class="pd-card">
                        <div class="pd-card__head"><h2>Patient information</h2></div>
                        <?php if ($profileFields): ?>
                            <dl class="pd-dl">
                                <?php foreach ($profileFields as $k => $val): ?>
                                    <div><dt><?= e(pdLabel($k)) ?></dt><dd><?= e($val) ?></dd></div>
                                <?php endforeach; ?>
                            </dl>
                        <?php else:
                            pdEmpty('user', 'No patient profile on file', 'Your profile details will appear here once they are recorded.');
                        endif; ?>
                    </div>
                    <div class="pd-card">
                        <div class="pd-card__head"><h2>Account</h2></div>
                        <?php if ($accountFields): ?>
                            <dl class="pd-dl">
                                <?php foreach ($accountFields as $k => $val): ?>
                                    <div><dt><?= e(pdLabel($k)) ?></dt><dd><?= e($val) ?></dd></div>
                                <?php endforeach; ?>
                            </dl>
                        <?php else:
                            pdEmpty('user', 'No account details', 'Account details are not available.');
                        endif; ?>
                    </div>
                </div>
            </section>

            
            <section class="pd-section" data-section="appointments" aria-labelledby="h-appointments" hidden>
                <div class="pd-page-head">
                    <div><h1 id="h-appointments">Appointments</h1><p>Review your upcoming and past visits.</p></div>
                    <a href="#book" class="pd-btn pd-btn--primary" data-section-link="book"><?= pdIcon('plus') ?>Book appointment</a>
                                    <a href="request_lab_test.php" class="pd-btn pd-btn--primary">
                        <?= pdIcon('flask') ?>Request Lab Test
                    </a>
                </div>

                <div class="pd-card">
                    <?php if (!$apptView):
                        pdEmpty('calendar', 'No appointments yet', 'Your appointments will be listed here once they are scheduled.');
                    else: ?>
                        <div class="pd-tabs" role="tablist" aria-label="Filter appointments">
                            <button type="button" class="is-active" role="tab" aria-selected="true" data-filter="all">All <span><?= e(count($apptView)) ?></span></button>
                            <button type="button" role="tab" aria-selected="false" data-filter="upcoming">Upcoming <span><?= e(count($upcomingView)) ?></span></button>
                            <button type="button" role="tab" aria-selected="false" data-filter="past">Past <span><?= e(count($apptView) - count($upcomingView)) ?></span></button>
                        </div>
                        <div class="pd-appt-list" data-appointment-list>
                            <?php foreach ($apptView as $v) { pdAppointmentRow($v, true); } ?>
                        </div>
                        <div data-filter-empty hidden><?php pdEmpty('calendar', 'No matching appointments', 'Try another filter or search term.'); ?></div>
                    <?php endif; ?>
                </div>
            </section>

           
            <section class="pd-section" data-section="book" aria-labelledby="h-book" hidden>
                <div class="pd-page-head"><div><h1 id="h-book">Book an appointment</h1><p>Tell us when you'd like to be seen and why.</p></div></div>

                <div class="pd-grid pd-grid--book">
                    <div class="pd-card">
                        

<form id="pd-booking-form"
      class="pd-form"
      novalidate
      data-endpoint="api/book_appointment.php">

    <!-- Doctor Selection -->
    <div class="pd-field">
        <label for="pd-book-doctor">Select Doctor</label>

        <select id="pd-book-doctor" name="doctor_id" required>
            <option value="">Choose a doctor</option>

            <?php if (!empty($availableDoctors)): ?>

                <?php foreach ($availableDoctors as $doctor): ?>
                    <option value="<?= (int)$doctor['doctor_id'] ?>">
                        <?= e($doctor['full_name']) ?>
                        <?php if (!empty($doctor['specialization'])): ?>
                            - <?= e($doctor['specialization']) ?>
                        <?php endif; ?>
                    </option>
                <?php endforeach; ?>

            <?php else: ?>

                <option value="" disabled>
                    No approved doctors available
                </option>

            <?php endif; ?>
        </select>

        <small class="pd-error"
               data-error-for="pd-book-doctor"
               hidden></small>
    </div>

    <!-- Date and Time -->
    <div class="pd-form__row">

        <div class="pd-field">
            <label for="pd-book-date">Preferred date</label>

            <input
                type="date"
                id="pd-book-date"
                name="appointment_date"
                required
            >

            <small class="pd-error"
                   data-error-for="pd-book-date"
                   hidden></small>
        </div>

        <div class="pd-field">
            <label for="pd-book-time">Preferred time</label>

            <input
                type="time"
                id="pd-book-time"
                name="appointment_time"
                required
            >

            <small class="pd-error"
                   data-error-for="pd-book-time"
                   hidden></small>
        </div>

    </div>

    <!-- Reason -->
    <div class="pd-field">
        <label for="pd-book-reason">Reason for visit</label>

        <textarea
            id="pd-book-reason"
            name="reason"
            rows="5"
            maxlength="500"
            required
            placeholder="Briefly describe your symptoms or the purpose of the visit"
        ></textarea>

        <small class="pd-error"
               data-error-for="pd-book-reason"
               hidden></small>
    </div>

    <!-- Actions -->
    <div class="pd-form__actions">

        <button type="reset" class="pd-btn pd-btn--ghost">
            Clear
        </button>

        <button type="submit" class="pd-btn pd-btn--primary">
            Request appointment
        </button>

    </div>

    <!-- Response Message -->
    <div
        class="pd-alert"
        id="pd-booking-notice"
        role="status"
        aria-live="polite"
        hidden
    ></div>

</form>

                    </div>
                    <div class="pd-card pd-tips">
                        <div class="pd-card__head"><h2>Before you book</h2></div>
                        <ul>
                            <li><?= pdIcon('check') ?><span>Choose a date that is today or later.</span></li>
                            <li><?= pdIcon('check') ?><span>Describe your reason clearly so staff can prepare.</span></li>
                            <li><?= pdIcon('check') ?><span>You currently have <strong><?= e($stats['upcoming_appointments'] ?? 0) ?></strong> upcoming appointment(s).</span></li>
                        </ul>
                    </div>
                </div>
            </section>

            
            <section class="pd-section" data-section="records" aria-labelledby="h-records" hidden>
                <div class="pd-page-head"><div><h1 id="h-records">Medical Records</h1><p>Reports and documents in your medical file.</p></div></div>
                <?php pdRecordCards($patientDashboardData['medical_records'] ?? [], 'file', 'No medical records yet', 'Reports added to your medical file will appear here.'); ?>
            </section>

            <!-- Doctor Access Management -->
<section class="pd-section" data-section="doctor-access" aria-labelledby="h-doctor-access" hidden>

    <div class="pd-page-head">
        <div>
            <h1 id="h-doctor-access">Doctor Access</h1>
            <p>Control which doctors can view your medical records.</p>
        </div>
    </div>

    <div class="pd-card">
        <div class="pd-card__head">
            <h2>Manage Doctor Permissions</h2>
        </div>

        <p>
            You can grant access to doctors with whom you have a confirmed
            appointment. You can revoke access whenever you want.
        </p>

        <div id="doctor-access-message"
             role="status"
             aria-live="polite"
             hidden>
        </div>

        <?php if (empty($doctorAccessList)): ?>

            <div class="pd-empty">
                <span class="pd-empty__icon">
                    <?= pdIcon('user') ?>
                </span>
                <h3>No eligible doctors found</h3>
                <p>
                    Doctors with confirmed appointments will appear here.
                </p>
            </div>

        <?php else: ?>

            <div class="pd-record-list">

                <?php foreach ($doctorAccessList as $doctor): ?>

                    <div class="pd-card" style="margin-top:16px;">

                        <div class="pd-card__head">
                            <div>
                                <h3>
                                    <?= e($doctor['full_name'] ?? 'Doctor') ?>
                                </h3>

                                <p>
                                    <?= e($doctor['specialization'] ?? 'General Physician') ?>
                                </p>

                                <?php if (!empty($doctor['workplace'])): ?>
                                    <p>
                                        <?= e($doctor['workplace']) ?>
                                    </p>
                                <?php endif; ?>
                            </div>
                        </div>

                        <p>
                            Access status:
                            <?php if ((int)($doctor['has_access'] ?? 0) === 1): ?>
                                <strong>Granted</strong>
                            <?php else: ?>
                                <strong>Not Granted</strong>
                            <?php endif; ?>
                        </p>

                        <form method="POST"
                              action="manage_doctor_access.php"
                              class="doctor-access-form">

                            <input
                                type="hidden"
                                name="doctor_id"
                                value="<?= (int)$doctor['doctor_id'] ?>"
                            >

                            <?php if ((int)($doctor['has_access'] ?? 0) === 1): ?>

                                <button
                                    type="submit"
                                    name="action"
                                    value="revoke"
                                    class="pd-btn pd-btn--ghost"
                                >
                                    Revoke Access
                                </button>

                            <?php else: ?>

                                <button
                                    type="submit"
                                    name="action"
                                    value="grant"
                                    class="pd-btn pd-btn--primary"
                                >
                                    Grant Access
                                </button>

                            <?php endif; ?>

                        </form>

                    </div>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>

    </div>

</section>
            <section class="pd-section" data-section="prescriptions" aria-labelledby="h-prescriptions" hidden>
                <div class="pd-page-head"><div><h1 id="h-prescriptions">Prescriptions</h1><p>Medication prescribed by your care team.</p></div></div>
                <?php pdRecordCards($patientDashboardData['prescriptions'] ?? [], 'pill', 'No prescriptions available', 'Prescriptions from your doctor will appear here when they are issued.'); ?>
            </section>

            
            <section class="pd-section" data-section="labs" aria-labelledby="h-labs" hidden>
                <div class="pd-page-head"><div><h1 id="h-labs">Lab Reports</h1><p>Test results from your laboratory visits.</p></div></div>
                <?php pdRecordCards($patientDashboardData['lab_reports'] ?? [], 'flask', 'No lab reports available', 'Lab results will appear here when they are uploaded to your file.'); ?>
            </section>

            
            <section class="pd-section" data-section="billing" aria-labelledby="h-billing" hidden>
                <div class="pd-page-head"><div><h1 id="h-billing">Billing &amp; Payments</h1><p>Invoices and payment history.</p></div></div>
                <?php pdRecordCards($patientDashboardData['billing'] ?? [], 'card', 'No billing information', 'Invoices and payments will appear here once billing is connected to your account.'); ?>
            </section>

            
            <section class="pd-section" data-section="notifications" aria-labelledby="h-notifications" hidden>
                <div class="pd-page-head"><div><h1 id="h-notifications">Notifications</h1><p>Updates about your appointments and records.</p></div></div>
                <div class="pd-card">
                    <?php if (!$notifications):
                        pdEmpty('bell', 'No notifications', 'You\'re all caught up. New updates will appear here.');
                    else: ?>
                        <ul class="pd-notifs">
                            <?php foreach ($notifications as $n): $p = pdNotificationParts($n); $read = pdNotificationRead($n);
                                $search = strtolower($p['title'] . ' ' . $p['body'] . ' ' . $p['time']); ?>
                                <li class="<?= $read === false ? 'is-unread' : '' ?>" data-searchable="<?= e($search) ?>">
                                    <span class="pd-list__icon"><?= pdIcon('bell') ?></span>
                                    <div>
                                        <strong><?= e($p['title'] !== '' ? $p['title'] : ($p['body'] !== '' ? $p['body'] : 'Notification')) ?></strong>
                                        <?php if ($p['title'] !== '' && $p['body'] !== ''): ?><p><?= e($p['body']) ?></p><?php endif; ?>
                                        <?php if ($p['time'] !== ''): ?><time><?= e($p['time']) ?></time><?php endif; ?>
                                    </div>
                                    <?php if ($read === false): ?><span class="pd-badge is-info">Unread</span><?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </section>

            
            <section class="pd-section" data-section="settings" aria-labelledby="h-settings" hidden>
                <div class="pd-page-head"><div><h1 id="h-settings">Settings</h1><p>Manage how MRMS works for you.</p></div></div>

                <div class="pd-alert is-info" role="note"><?= pdIcon('info') ?><span>Settings can't be saved yet. These options are shown for preview and will be enabled when preference storage is connected.</span></div>

                <div class="pd-grid pd-grid--2">
                    <div class="pd-card">
                        <div class="pd-card__head"><h2>Notification preferences</h2></div>
                        <ul class="pd-settings">
                            <li><div><strong>Appointment reminders</strong><span>Get reminded before a scheduled visit.</span></div><label class="pd-switch"><input type="checkbox" disabled><span></span><span class="pd-sr">Appointment reminders</span></label></li>
                            <li><div><strong>Record updates</strong><span>Be told when a new report is added.</span></div><label class="pd-switch"><input type="checkbox" disabled><span></span><span class="pd-sr">Record updates</span></label></li>
                        </ul>
                    </div>
                    <div class="pd-card">
                        <div class="pd-card__head"><h2>Privacy &amp; security</h2></div>
                        <ul class="pd-settings">
                            <li><div><strong>Two-step sign-in</strong><span>Add an extra layer of account protection.</span></div><label class="pd-switch"><input type="checkbox" disabled><span></span><span class="pd-sr">Two-step sign-in</span></label></li>
                            <li><div><strong>Change password</strong><span>Update your sign-in password.</span></div><button type="button" class="pd-btn pd-btn--ghost" disabled>Change</button></li>
                        </ul>
                    </div>
                </div>
                <div class="pd-form__actions"><button type="button" class="pd-btn pd-btn--primary" disabled>Save changes</button></div>
            </section>

            <div class="pd-empty pd-search-empty" data-search-empty hidden>
                <span class="pd-empty__icon"><?= pdIcon('search') ?></span>
                <h3>No results found</h3>
                <p>Nothing in this section matches your search.</p>
            </div>
        </main>
    </div>
</div>

<!-- Logout confirmation -->
<dialog class="pd-dialog" id="pd-logout-dialog" aria-labelledby="pd-logout-title">
    <h2 id="pd-logout-title">Log out of MRMS?</h2>
    <p>You will need to sign in again to view your records.</p>
    <div class="pd-dialog__actions">
        <button type="button" class="pd-btn pd-btn--ghost" data-dialog-cancel>Stay signed in</button>
        <a href="../auth/logout.php" class="pd-btn pd-btn--primary">Log out</a>
    </div>
</dialog>
<script>
document.addEventListener('DOMContentLoaded', function () {

    const forms = document.querySelectorAll('.doctor-access-form');
    const notice = document.getElementById('doctor-access-notice');

    forms.forEach(function (form) {

        form.addEventListener('submit', async function (event) {

            event.preventDefault();

            const action = form.querySelector('[name="action"]').value;

            const confirmation = action === 'grant'
                ? 'Do you want to allow this doctor to view your medical records?'
                : 'Are you sure you want to revoke this doctor’s access?';

            if (!confirm(confirmation)) {
                return;
            }

            const button = form.querySelector('button');
            button.disabled = true;

            try {

                const response = await fetch(form.action, {
                    method: 'POST',
                    body: new FormData(form)
                });

                const result = await response.json();

                notice.hidden = false;
                notice.textContent = result.message;

                if (!response.ok || !result.success) {
                    button.disabled = false;
                    return;
                }

                window.location.reload();

            } catch (error) {

                notice.hidden = false;
                notice.textContent = 'Something went wrong. Please try again.';
                button.disabled = false;

            }

        });

    });

});
</script>

<script src="../auth/assets/js/patient-dashboard.js" defer></script>
</body>
</html>