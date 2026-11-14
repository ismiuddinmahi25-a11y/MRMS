<?php
declare(strict_types=1);

$required_role = 'admin';

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

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = '';
$error = '';

// Handle doctor approval/rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $token = $_POST['csrf_token'] ?? '';
    $doctorId = filter_input(INPUT_POST, 'doctor_id', FILTER_VALIDATE_INT);
    $action = $_POST['action'] ?? '';

    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        $error = 'Invalid security token.';
    } elseif (!$doctorId || !in_array($action, ['approve', 'reject'], true)) {
        $error = 'Invalid request.';
    } else {

        $status = ($action === 'approve') ? 'approved' : 'rejected';
        $accountStatus = ($action === 'approve') ? 'active' : 'rejected';

        try {
            $conn->begin_transaction();

            // Lock doctor record
            $stmt = $conn->prepare(
                "SELECT user_id FROM doctors WHERE id = ? FOR UPDATE"
            );
            $stmt->bind_param("i", $doctorId);
            $stmt->execute();

            $doctor = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$doctor) {
                throw new Exception('Doctor not found.');
            }

            $userId = (int) $doctor['user_id'];

            // Update doctor verification
            $stmt = $conn->prepare(
                "UPDATE doctors SET verification_status = ? WHERE id = ?"
            );
            $stmt->bind_param("si", $status, $doctorId);
            $stmt->execute();
            $stmt->close();

            // Update user account status
            $stmt = $conn->prepare(
                "UPDATE users
                 SET account_status = ?
                 WHERE id = ? AND role = 'doctor'"
            );
            $stmt->bind_param("si", $accountStatus, $userId);
            $stmt->execute();

            if ($stmt->affected_rows < 0) {
                throw new Exception('Account update failed.');
            }

            $stmt->close();

            $conn->commit();

            $message = ($action === 'approve')
                ? 'Doctor approved successfully.'
                : 'Doctor rejected successfully.';

        } catch (Throwable $e) {
            $conn->rollback();
            $error = $e->getMessage();
        }
    }
}

// Fetch doctors
$sql = "
    SELECT
        d.id AS doctor_id,
        d.user_id,
        u.full_name,
        u.email,
        u.account_status,
        d.specialization,
        d.qualification,
        d.license_number,
        d.workplace,
        d.experience_years,
        d.verification_status,
        d.created_at
    FROM doctors d
    INNER JOIN users u ON u.id = d.user_id
    WHERE u.role = 'doctor'
    ORDER BY
        FIELD(d.verification_status, 'pending', 'approved', 'rejected'),
        d.created_at DESC
";

$result = $conn->query($sql);

if (!$result) {
    http_response_code(500);
    exit('Unable to retrieve doctors.');
}

$doctors = $result->fetch_all(MYSQLI_ASSOC);

$counts = [
    'all' => count($doctors),
    'pending' => 0,
    'approved' => 0,
    'rejected' => 0
];

foreach ($doctors as $doctor) {
    $status = $doctor['verification_status'];

    if (isset($counts[$status])) {
        $counts[$status]++;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Doctor Management | MRMS</title>
</head>
<body>

<h1>Doctor Management</h1>

<p>Manage doctor verification and account approvals.</p>

<?php if ($message): ?>
    <p style="color:green;">
        <?= htmlspecialchars($message) ?>
    </p>
<?php endif; ?>

<?php if ($error): ?>
    <p style="color:red;">
        <?= htmlspecialchars($error) ?>
    </p>
<?php endif; ?>

<h2>Overview</h2>

<p>Total Doctors: <?= $counts['all'] ?></p>
<p>Pending: <?= $counts['pending'] ?></p>
<p>Approved: <?= $counts['approved'] ?></p>
<p>Rejected: <?= $counts['rejected'] ?></p>

<h2>Doctors List</h2>

<table border="1" cellpadding="10" cellspacing="0">
    <thead>
        <tr>
            <th>Name</th>
            <th>Email</th>
            <th>Specialization</th>
            <th>Qualification</th>
            <th>License</th>
            <th>Experience</th>
            <th>Workplace</th>
            <th>Doctor Status</th>
            <th>Account Status</th>
            <th>Actions</th>
        </tr>
    </thead>

    <tbody>

    <?php if (empty($doctors)): ?>
        <tr>
            <td colspan="10">No doctors found.</td>
        </tr>
    <?php else: ?>

        <?php foreach ($doctors as $doctor): ?>
            <tr>
                <td><?= htmlspecialchars($doctor['full_name']) ?></td>
                <td><?= htmlspecialchars($doctor['email']) ?></td>
                <td><?= htmlspecialchars($doctor['specialization']) ?></td>
                <td><?= htmlspecialchars($doctor['qualification']) ?></td>
                <td><?= htmlspecialchars($doctor['license_number']) ?></td>
                <td><?= (int) $doctor['experience_years'] ?> years</td>
                <td><?= htmlspecialchars($doctor['workplace'] ?? 'N/A') ?></td>

                <td>
                    <?= htmlspecialchars($doctor['verification_status']) ?>
                </td>

                <td>
                    <?= htmlspecialchars($doctor['account_status']) ?>
                </td>

                <td>
                    <?php if ($doctor['verification_status'] === 'pending'): ?>

                        <form method="POST">
                            <input type="hidden" name="csrf_token"
                                   value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

                            <input type="hidden" name="doctor_id"
                                   value="<?= (int) $doctor['doctor_id'] ?>">

                            <button type="submit" name="action"
                                    value="approve">
                                Approve
                            </button>

                            <button type="submit" name="action"
                                    value="reject">
                                Reject
                            </button>
                        </form>

                    <?php else: ?>
                        No action
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>

    <?php endif; ?>

    </tbody>
</table>

</body>
</html>