<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| MRMS - Patient Dashboard Data API
|--------------------------------------------------------------------------
*/

$required_role = 'patient';

require_once __DIR__ . '/../../auth/role_guard.php';
require_once __DIR__ . '/../../config/db.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database connection unavailable.'
    ]);
    exit;
}

$conn->set_charset('utf8mb4');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$userId = (int)($_SESSION['user_id'] ?? 0);

/*
|--------------------------------------------------------------------------
| Helper Functions
|--------------------------------------------------------------------------
*/

function pdJsonResponse(int $status, array $data): void
{
    http_response_code($status);

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );

    exit;
}

function pdGetColumns(mysqli $conn, string $table): array
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

    $result = $conn->query("SHOW COLUMNS FROM `$table`");

    if (!$result) {
        return [];
    }

    $columns = [];

    while ($row = $result->fetch_assoc()) {
        $columns[] = $row['Field'];
    }

    $result->free();

    return $columns;
}

function pdFindColumn(array $columns, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }

    return null;
}

function pdSelectRow(
    mysqli $conn,
    string $table,
    string $primaryColumn,
    int $id,
    array $allowedFields
): ?array {
    $columns = pdGetColumns($conn, $table);

    if (!in_array($primaryColumn, $columns, true)) {
        return null;
    }

    $selected = [];

    foreach ($allowedFields as $field) {
        if (in_array($field, $columns, true)) {
            $selected[] = "`$field`";
        }
    }

    if (empty($selected)) {
        return null;
    }

    $sql = "SELECT " . implode(', ', $selected) .
           " FROM `$table` WHERE `$primaryColumn` = ? LIMIT 1";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('i', $id);

    if (!$stmt->execute()) {
        $stmt->close();
        return null;
    }

    $result = $stmt->get_result();
    $row = $result->fetch_assoc();

    $stmt->close();

    return $row ?: null;
}

function pdFetchRows(
    mysqli $conn,
    string $table,
    string $ownerColumn,
    int $ownerId,
    array $allowedFields,
    ?string $statusColumn = null,
    array $allowedStatuses = []
): array {
    $columns = pdGetColumns($conn, $table);

    if (
        empty($columns) ||
        !in_array($ownerColumn, $columns, true)
    ) {
        return [];
    }

    $selected = [];

    foreach ($allowedFields as $field) {
        if (in_array($field, $columns, true)) {
            $selected[] = "`$field`";
        }
    }

    if (empty($selected)) {
        return [];
    }

    $sql = "SELECT " . implode(', ', $selected) .
           " FROM `$table` WHERE `$ownerColumn` = ?";

    $useStatus = (
        $statusColumn !== null &&
        in_array($statusColumn, $columns, true) &&
        !empty($allowedStatuses)
    );

    if ($useStatus) {
        $placeholders = implode(
            ', ',
            array_fill(0, count($allowedStatuses), '?')
        );

        $sql .= " AND LOWER(`$statusColumn`) IN ($placeholders)";
    }

    $orderColumn = pdFindColumn(
        $columns,
        ['created_at', 'uploaded_at', 'appointment_date', 'id']
    );

    if ($orderColumn !== null) {
        $sql .= " ORDER BY `$orderColumn` DESC";
    }

    $sql .= " LIMIT 100";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        return [];
    }

    if ($useStatus) {
        $types = 'i' . str_repeat('s', count($allowedStatuses));
        $params = array_merge([$ownerId], $allowedStatuses);

        $stmt->bind_param($types, ...$params);
    } else {
        $stmt->bind_param('i', $ownerId);
    }

    if (!$stmt->execute()) {
        $stmt->close();
        return [];
    }

    $result = $stmt->get_result();
    $rows = [];

    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }

    $stmt->close();

    return $rows;
}

/*
|--------------------------------------------------------------------------
| 1. Authentication
|--------------------------------------------------------------------------
*/

if ($userId <= 0) {
    pdJsonResponse(401, [
        'success' => false,
        'message' => 'Authentication required.'
    ]);
}

/*
|--------------------------------------------------------------------------
| 2. Verify User Account
|--------------------------------------------------------------------------
*/

$userColumns = pdGetColumns($conn, 'users');

$userPrimaryKey = pdFindColumn(
    $userColumns,
    ['id', 'user_id']
);

if ($userPrimaryKey === null) {
    pdJsonResponse(500, [
        'success' => false,
        'message' => 'User table structure could not be identified.'
    ]);
}

$user = pdSelectRow(
    $conn,
    'users',
    $userPrimaryKey,
    $userId,
    [
        'id',
        'user_id',
        'name',
        'full_name',
        'first_name',
        'last_name',
        'email',
        'phone',
        'contact_number',
        'account_status',
        'status',
        'created_at'
    ]
);

if (!$user) {
    pdJsonResponse(404, [
        'success' => false,
        'message' => 'User account not found.'
    ]);
}

$accountStatusColumn = pdFindColumn(
    $userColumns,
    ['account_status', 'status']
);

if ($accountStatusColumn !== null) {
    $accountStatus = strtolower(
        trim((string)($user[$accountStatusColumn] ?? ''))
    );

    if ($accountStatus !== 'active') {
        pdJsonResponse(403, [
            'success' => false,
            'message' => 'Your account is not active. Please contact the administrator.',
            'account_status' => $accountStatus
        ]);
    }
}

/*
|--------------------------------------------------------------------------
| 3. Find Patient Profile
|--------------------------------------------------------------------------
*/

$patientColumns = pdGetColumns($conn, 'patients');

$patientUserColumn = pdFindColumn(
    $patientColumns,
    ['user_id']
);

$patientPrimaryKey = pdFindColumn(
    $patientColumns,
    ['id', 'patient_id']
);

if (
    $patientUserColumn === null ||
    $patientPrimaryKey === null
) {
    pdJsonResponse(500, [
        'success' => false,
        'message' => 'Patient table structure could not be identified.'
    ]);
}

$stmt = $conn->prepare(
    "SELECT * FROM `patients`
     WHERE `$patientUserColumn` = ?
     LIMIT 1"
);

if (!$stmt) {
    pdJsonResponse(500, [
        'success' => false,
        'message' => 'Unable to retrieve patient information.'
    ]);
}

$stmt->bind_param('i', $userId);
$stmt->execute();

$result = $stmt->get_result();
$patientRow = $result->fetch_assoc();

$stmt->close();

if (!$patientRow) {
    pdJsonResponse(404, [
        'success' => false,
        'message' => 'Patient profile not found.'
    ]);
}

$patientId = (int)$patientRow[$patientPrimaryKey];

/*
|--------------------------------------------------------------------------
| 4. Prepare Patient Profile
|--------------------------------------------------------------------------
*/

$profileFields = [
    'id',
    'patient_id',
    'user_id',
    'full_name',
    'name',
    'date_of_birth',
    'dob',
    'gender',
    'phone',
    'contact_phone',
    'email',
    'address',
    'emergency_contact',
    'blood_group',
    'created_at'
];

$profile = [];

foreach ($profileFields as $field) {
    if (array_key_exists($field, $patientRow)) {
        $profile[$field] = $patientRow[$field];
    }
}

/*
|--------------------------------------------------------------------------
| 5. Appointments
|--------------------------------------------------------------------------
*/

$appointments = pdFetchRows(
    $conn,
    'appointments',
    'patient_id',
    $patientId,
    [
        'id',
        'appointment_id',
        'patient_id',
        'doctor_id',
        'appointment_date',
        'appointment_time',
        'reason',
        'status',
        'doctor_notes',
        'created_at',
        'updated_at'
    ]
);

/*
|--------------------------------------------------------------------------
| 6. Available Verified Doctors
|--------------------------------------------------------------------------
*/

$doctors = [];

$doctorColumns = pdGetColumns($conn, 'doctors');

$doctorPrimaryKey = pdFindColumn(
    $doctorColumns,
    ['id', 'doctor_id']
);

$doctorUserColumn = pdFindColumn(
    $doctorColumns,
    ['user_id']
);

$doctorVerificationColumn = pdFindColumn(
    $doctorColumns,
    ['verification_status', 'verification_state']
);

if (
    $doctorPrimaryKey !== null &&
    $doctorUserColumn !== null &&
    $doctorVerificationColumn !== null
) {
    $doctorUserPrimaryKey = pdFindColumn(
        $userColumns,
        ['id', 'user_id']
    );

    if ($doctorUserPrimaryKey !== null) {
        $doctorFields = [
            'id',
            'doctor_id',
            'user_id',
            'specialization',
            'qualification',
            'experience',
            'consultation_fee',
            'bio',
            'department',
            'availability_status'
        ];

        $selectParts = [];

        foreach ($doctorFields as $field) {
            if (in_array($field, $doctorColumns, true)) {
                $selectParts[] = "d.`$field`";
            }
        }

        $doctorNameColumn = pdFindColumn(
            $userColumns,
            ['name', 'full_name']
        );

        $doctorEmailColumn = pdFindColumn(
            $userColumns,
            ['email']
        );

        $doctorPhoneColumn = pdFindColumn(
            $userColumns,
            ['phone', 'contact_number']
        );

        if ($doctorNameColumn !== null) {
            $selectParts[] = "u.`$doctorNameColumn` AS doctor_name";
        }

        if ($doctorEmailColumn !== null) {
            $selectParts[] = "u.`$doctorEmailColumn` AS doctor_email";
        }

        if ($doctorPhoneColumn !== null) {
            $selectParts[] = "u.`$doctorPhoneColumn` AS doctor_phone";
        }

        if (!empty($selectParts)) {
            $doctorAccountStatusColumn = pdFindColumn(
                $userColumns,
                ['account_status', 'status']
            );

            $doctorSql = "
                SELECT " . implode(', ', $selectParts) . "
                FROM doctors d
                INNER JOIN users u
                    ON d.`$doctorUserColumn` = u.`$doctorUserPrimaryKey`
                WHERE LOWER(d.`$doctorVerificationColumn`) = 'approved'
            ";

            if ($doctorAccountStatusColumn !== null) {
                $doctorSql .= "
                    AND LOWER(u.`$doctorAccountStatusColumn`) = 'active'
                ";
            }

            $doctorSql .= " ORDER BY doctor_name ASC LIMIT 100";

            $doctorResult = $conn->query($doctorSql);

            if ($doctorResult) {
                while ($doctorRow = $doctorResult->fetch_assoc()) {
                    $doctors[] = $doctorRow;
                }

                $doctorResult->free();
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| 7. Verified Medical Reports
|--------------------------------------------------------------------------
*/

$reportColumns = pdGetColumns($conn, 'medical_reports');

$reportStatusColumn = pdFindColumn(
    $reportColumns,
    [
        'verification_status',
        'verification_state',
        'report_status',
        'status'
    ]
);

$medicalRecords = pdFetchRows(
    $conn,
    'medical_reports',
    'patient_id',
    $patientId,
    [
        'id',
        'report_id',
        'patient_id',
        'test_category',
        'test_type',
        'test_name',
        'report_title',
        'file_name',
        'file_format',
        'file_size',
        'upload_date',
        'uploaded_at',
        'created_at',
        'verification_status',
        'verification_state',
        'report_status',
        'status'
    ],
    $reportStatusColumn,
    ['verified']
);

/*
|--------------------------------------------------------------------------
| 8. Notifications
|--------------------------------------------------------------------------
*/

$notificationColumns = pdGetColumns(
    $conn,
    'notifications'
);

$notificationConditions = [];
$notificationParams = [];
$notificationTypes = '';

if (in_array('user_id', $notificationColumns, true)) {
    $notificationConditions[] = '`user_id` = ?';
    $notificationParams[] = $userId;
    $notificationTypes .= 'i';
}

if (in_array('patient_id', $notificationColumns, true)) {
    $notificationConditions[] = '`patient_id` = ?';
    $notificationParams[] = $patientId;
    $notificationTypes .= 'i';
}

$notifications = [];

$notificationFields = [
    'id',
    'notification_id',
    'user_id',
    'patient_id',
    'title',
    'message',
    'type',
    'is_read',
    'read_at',
    'created_at'
];

$notificationSelected = [];

foreach ($notificationFields as $field) {
    if (in_array($field, $notificationColumns, true)) {
        $notificationSelected[] = "`$field`";
    }
}

if (
    !empty($notificationConditions) &&
    !empty($notificationSelected)
) {
    $notificationSql = "
        SELECT " . implode(', ', $notificationSelected) . "
        FROM notifications
        WHERE (" . implode(' OR ', $notificationConditions) . ")
        ORDER BY created_at DESC
        LIMIT 100
    ";

    $notificationStmt = $conn->prepare($notificationSql);

    if ($notificationStmt) {
        $notificationStmt->bind_param(
            $notificationTypes,
            ...$notificationParams
        );

        if ($notificationStmt->execute()) {
            $notificationResult = $notificationStmt->get_result();

            while ($notificationRow = $notificationResult->fetch_assoc()) {
                $notifications[] = $notificationRow;
            }
        }

        $notificationStmt->close();
    }
}

/*
|--------------------------------------------------------------------------
| 9. Dashboard Statistics
|--------------------------------------------------------------------------
*/

$totalAppointments = count($appointments);
$upcomingAppointments = 0;
$completedAppointments = 0;
$pendingAppointments = 0;
$cancelledAppointments = 0;

$today = date('Y-m-d');

foreach ($appointments as $appointment) {
    $status = strtolower(
        trim((string)($appointment['status'] ?? ''))
    );

    $appointmentDate = substr(
        (string)($appointment['appointment_date'] ?? ''),
        0,
        10
    );

    if ($status === 'completed') {
        $completedAppointments++;
    }

    if ($status === 'pending') {
        $pendingAppointments++;
    }

    if (in_array($status, ['cancelled', 'canceled'], true)) {
        $cancelledAppointments++;
    }

    if (
        $appointmentDate !== '' &&
        $appointmentDate >= $today &&
        !in_array(
            $status,
            ['completed', 'cancelled', 'canceled', 'rejected'],
            true
        )
    ) {
        $upcomingAppointments++;
    }
}

$unreadNotifications = 0;

foreach ($notifications as $notification) {
    if (
        isset($notification['is_read']) &&
        (string)$notification['is_read'] === '0'
    ) {
        $unreadNotifications++;
    }
}

/*
|--------------------------------------------------------------------------
| 10. Final JSON Response
|--------------------------------------------------------------------------
*/

pdJsonResponse(200, [
    'success' => true,

    'user' => $user,

    'profile' => $profile,

    // Compatibility with the previous API response.
    'patient' => $profile,

    'appointments' => $appointments,

    'available_doctors' => $doctors,

    'medical_records' => $medicalRecords,

    // These modules will be connected through their own backend steps.
    'lab_reports' => [],

    'prescriptions' => [],

    'billing' => [],

    'notifications' => $notifications,

    'statistics' => [
        'total_appointments' => $totalAppointments,
        'upcoming_appointments' => $upcomingAppointments,
        'completed_appointments' => $completedAppointments,
        'pending_appointments' => $pendingAppointments,
        'cancelled_appointments' => $cancelledAppointments,
        'total_medical_records' => count($medicalRecords),
        'unread_notifications' => $unreadNotifications
    ]
]);
