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

$userId = (int) ($_SESSION['user_id'] ?? 0);

if ($userId <= 0) {
    http_response_code(401);
    exit('Unauthorized access.');
}

// Verify doctor account and retrieve doctor ID
$doctorStmt = $conn->prepare("
    SELECT d.id, u.full_name
    FROM doctors d
    INNER JOIN users u ON u.id = d.user_id
    WHERE d.user_id = ?
      AND d.verification_status = 'approved'
      AND u.role = 'doctor'
      AND u.account_status = 'active'
    LIMIT 1
");

if (!$doctorStmt) {
    http_response_code(500);
    exit('Unable to verify doctor account.');
}

$doctorStmt->bind_param("i", $userId);
$doctorStmt->execute();

$doctorResult = $doctorStmt->get_result();
$doctor = $doctorResult->fetch_assoc();

$doctorStmt->close();

if (!$doctor) {
    http_response_code(403);
    exit('Your doctor account is not approved or active.');
}

$doctorId = (int) $doctor['id'];
$doctorName = $doctor['full_name'];

// Search input
$search = trim((string) ($_GET['q'] ?? ''));

if (strlen($search) > 100) {
    $search = substr($search, 0, 100);
}

$searchPattern = '%' . $search . '%';

// Fetch only patients who have granted active access
$sql = "
    SELECT
        p.id AS patient_id,
        p.date_of_birth,
        p.gender,
        p.phone,
        p.address,
        p.created_at AS patient_since,
        u.full_name,
        u.email,
        a.granted_at,
        TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) AS age
    FROM patient_doctor_access a
    INNER JOIN patients p
        ON p.id = a.patient_id
    INNER JOIN users u
        ON u.id = p.user_id
    WHERE a.doctor_id = ?
      AND a.access_status = 'active'
      AND (a.expires_at IS NULL OR a.expires_at > NOW())
      AND u.role = 'patient'
      AND u.account_status = 'active'
      AND (
          u.full_name LIKE ?
          OR u.email LIKE ?
          OR p.phone LIKE ?
      )
    ORDER BY a.granted_at DESC
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    http_response_code(500);
    exit('Unable to load authorized patients.');
}

$stmt->bind_param(
    "isss",
    $doctorId,
    $searchPattern,
    $searchPattern,
    $searchPattern
);

$stmt->execute();

$result = $stmt->get_result();

$patients = [];

while ($row = $result->fetch_assoc()) {
    $patients[] = $row;
}

$stmt->close();

$totalPatients = count($patients);

function patientEscape($value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

function patientFormatDate($date): string
{
    if (empty($date)) {
        return 'N/A';
    }

    $timestamp = strtotime((string) $date);

    return $timestamp
        ? date('d M Y', $timestamp)
        : 'N/A';
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>My Patients | MRMS</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 25px;
            font-family: Arial, sans-serif;
            background: #f5f7fb;
            color: #263445;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 25px;
        }

        .page-header h1 {
            margin: 0 0 8px;
            font-size: 27px;
        }

        .page-header p {
            margin: 0;
            color: #718096;
        }

        .back-link {
            text-decoration: none;
            color: #2563eb;
            font-weight: 600;
        }

        .summary {
            background: #fff;
            padding: 22px;
            border-radius: 12px;
            margin-bottom: 22px;
            box-shadow: 0 2px 10px rgba(0,0,0,.04);
        }

        .summary span {
            color: #718096;
            font-size: 14px;
        }

        .summary h2 {
            margin: 8px 0 0;
            font-size: 30px;
            color: #2563eb;
        }

        .patients-card {
            background: #fff;
            padding: 22px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,.04);
        }

        .toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 20px;
        }

        .toolbar h2 {
            margin: 0;
            font-size: 20px;
        }

        .search-form {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .search-form input {
            padding: 11px 13px;
            border: 1px solid #dce2ea;
            border-radius: 7px;
            min-width: 230px;
            outline: none;
        }

        .search-form button {
            padding: 11px 17px;
            border: none;
            border-radius: 7px;
            background: #2563eb;
            color: #fff;
            cursor: pointer;
        }

        .table-wrapper {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 850px;
        }

        th, td {
            padding: 15px 12px;
            text-align: left;
            border-bottom: 1px solid #edf0f4;
            font-size: 14px;
        }

        th {
            background: #f8fafc;
            color: #64748b;
            font-weight: 600;
        }

        .patient-name {
            font-weight: 600;
            color: #263445;
        }

        .patient-email {
            color: #718096;
            font-size: 12px;
            margin-top: 5px;
        }

        .status {
            display: inline-block;
            padding: 6px 10px;
            border-radius: 20px;
            background: #dcfce7;
            color: #15803d;
            font-size: 12px;
            font-weight: 600;
        }

        .view-btn {
            display: inline-block;
            padding: 9px 13px;
            background: #2563eb;
            color: white;
            text-decoration: none;
            border-radius: 6px;
            font-size: 13px;
            white-space: nowrap;
        }

        .empty-state {
            text-align: center;
            padding: 45px 15px;
            color: #718096;
        }

        .empty-state h3 {
            color: #334155;
            margin-bottom: 8px;
        }

        @media (max-width: 600px) {
            body {
                padding: 15px;
            }

            .patients-card {
                padding: 15px;
            }

            .search-form,
            .search-form input {
                width: 100%;
            }
        }
    </style>
</head>

<body>

<div class="container">

    <header class="page-header">

        <div>
            <h1>My Patients</h1>
            <p>
                Welcome, Dr.
                <?= patientEscape($doctorName) ?>.
                Here are your authorized patients.
            </p>
        </div>

        <a href="dashboard.php" class="back-link">
            ← Back to Dashboard
        </a>

    </header>

    <section class="summary">
        <span>Total Authorized Patients</span>
        <h2><?= $totalPatients ?></h2>
    </section>

    <section class="patients-card">

        <div class="toolbar">

            <h2>Authorized Patient List</h2>

            <form method="GET" action="patients.php" class="search-form">

                <input
                    type="text"
                    name="q"
                    placeholder="Search name, email or phone..."
                    value="<?= patientEscape($search) ?>"
                    maxlength="100"
                >

                <button type="submit">Search</button>

            </form>

        </div>

        <?php if ($totalPatients > 0): ?>

            <div class="table-wrapper">

                <table>

                    <thead>
                        <tr>
                            <th>Patient</th>
                            <th>Age</th>
                            <th>Gender</th>
                            <th>Phone</th>
                            <th>Access Granted</th>
                            <th>Access Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php foreach ($patients as $patient): ?>

                        <tr>

                            <td>
                                <div class="patient-name">
                                    <?= patientEscape($patient['full_name']) ?>
                                </div>

                                <div class="patient-email">
                                    <?= patientEscape($patient['email']) ?>
                                </div>
                            </td>

                            <td>
                                <?= patientEscape($patient['age']) ?> years
                            </td>

                            <td>
                                <?= patientEscape($patient['gender']) ?>
                            </td>

                            <td>
                                <?= patientEscape($patient['phone']) ?>
                            </td>

                            <td>
                                <?= patientFormatDate($patient['granted_at']) ?>
                            </td>

                            <td>
                                <span class="status">Active</span>
                            </td>

                            <td>
                                <a
                                    class="view-btn"
                                    href="medical-history.php?patient_id=<?= (int) $patient['patient_id'] ?>"
                                >
                                    View Medical History
                                </a>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        <?php else: ?>

            <div class="empty-state">

                <h3>No Authorized Patients Found</h3>

                <?php if ($search !== ''): ?>

                    <p>
                        No patient matched your search.
                        Try another name, email or phone number.
                    </p>

                <?php else: ?>

                    <p>
                        Patients who grant you medical record access
                        will appear here.
                    </p>

                <?php endif; ?>

            </div>

        <?php endif; ?>

    </section>

</div>

</body>
</html>
