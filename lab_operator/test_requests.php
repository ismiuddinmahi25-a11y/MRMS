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

// Get logged-in lab operator
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

$operatorId = (int) $operator['id'];

// CSRF token
if (empty($_SESSION['csrf_lab_operator_requests'])) {
    $_SESSION['csrf_lab_operator_requests'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_lab_operator_requests'];

$message = '';
$messageType = 'success';

// Handle Accept / Reject
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $submittedToken = $_POST['csrf_token'] ?? '';
    $requestId = filter_input(INPUT_POST, 'request_id', FILTER_VALIDATE_INT);
    $action = $_POST['action'] ?? '';

    if (!is_string($submittedToken) ||
        !hash_equals($csrfToken, $submittedToken)) {

        $message = 'Invalid request. Please try again.';
        $messageType = 'error';

    } elseif (!$requestId || !in_array($action, ['accept', 'reject'], true)) {

        $message = 'Invalid action.';
        $messageType = 'error';

    } else {

        $newStatus = ($action === 'accept') ? 'accepted' : 'rejected';

        $rejectionReason = ($action === 'reject')
            ? 'Rejected by lab operator.'
            : null;

        $update = $conn->prepare(
            "UPDATE lab_test_requests
             SET status = ?, rejection_reason = ?
             WHERE id = ?
               AND lab_operator_id = ?
               AND status = 'pending'"
        );

        $update->bind_param(
            'ssii',
            $newStatus,
            $rejectionReason,
            $requestId,
            $operatorId
        );

        $update->execute();

        if ($update->affected_rows === 1) {
            $_SESSION['lab_request_flash'] = [
                'message' => $action === 'accept'
                    ? 'Test request accepted successfully.'
                    : 'Test request rejected successfully.',
                'type' => 'success'
            ];
        } else {
            $_SESSION['lab_request_flash'] = [
                'message' => 'Request not found or it has already been processed.',
                'type' => 'error'
            ];
        }

        $update->close();

        header('Location: test_requests.php');
        exit;
    }
}

// Show flash message
if (isset($_SESSION['lab_request_flash'])) {
    $message = $_SESSION['lab_request_flash']['message'];
    $messageType = $_SESSION['lab_request_flash']['type'];
    unset($_SESSION['lab_request_flash']);
}

// Load only requests assigned to this operator
$sql = "
    SELECT
        r.id,
        r.test_name,
        r.reason,
        r.preferred_date,
        r.status,
        r.rejection_reason,
        r.created_at,
        p.id AS patient_id,
        u.full_name AS patient_name
    FROM lab_test_requests r
    INNER JOIN patients p ON p.id = r.patient_id
    INNER JOIN users u ON u.id = p.user_id
    WHERE r.lab_operator_id = ?
    ORDER BY r.created_at DESC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $operatorId);
$stmt->execute();

$result = $stmt->get_result();
$requests = [];

while ($row = $result->fetch_assoc()) {
    $requests[] = $row;
}

$stmt->close();

function e($value): string
{
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Patient Test Requests</title>

    <style>
        body {
            font-family: Arial, sans-serif;
            background: #f5f7fb;
            margin: 0;
            padding: 25px;
            color: #222;
        }

        .container {
            max-width: 1100px;
            margin: auto;
        }

        h2 {
            margin-bottom: 5px;
        }

        .subtitle {
            color: #666;
            margin-bottom: 25px;
        }

        .request {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 15px;
        }

        .request h3 {
            margin-top: 0;
        }

        .info {
            margin: 9px 0;
            line-height: 1.5;
        }

        .status {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 20px;
            background: #fff3cd;
            color: #856404;
            font-size: 13px;
            text-transform: capitalize;
        }

        .status.accepted {
            background: #d1fae5;
            color: #065f46;
        }

        .status.rejected {
            background: #fee2e2;
            color: #991b1b;
        }

        .status.processing {
            background: #dbeafe;
            color: #1e40af;
        }

        .status.completed {
            background: #e0e7ff;
            color: #3730a3;
        }

        .actions {
            display: flex;
            gap: 10px;
            margin-top: 18px;
        }

        button {
            border: 0;
            border-radius: 6px;
            padding: 10px 20px;
            color: white;
            cursor: pointer;
            font-size: 14px;
        }

        .accept-btn {
            background: #16834a;
        }

        .reject-btn {
            background: #dc3545;
        }

        button:hover {
            opacity: 0.88;
        }

        .alert {
            padding: 12px 15px;
            border-radius: 7px;
            margin-bottom: 18px;
        }

        .alert.success {
            background: #d1fae5;
            color: #065f46;
        }

        .alert.error {
            background: #fee2e2;
            color: #991b1b;
        }

        .empty {
            background: #fff;
            padding: 30px;
            text-align: center;
            border-radius: 10px;
            color: #666;
        }

        .rejection {
            color: #991b1b;
        }
    </style>
</head>

<body>

<div class="container">

    <h2>Patient Test Requests</h2>

    <p class="subtitle">
        Laboratory: <?= e($operator['laboratory_name']) ?>
    </p>

    <?php if ($message !== ''): ?>
        <div class="alert <?= e($messageType) ?>">
            <?= e($message) ?>
        </div>
    <?php endif; ?>

    <?php if (empty($requests)): ?>

        <div class="empty">
            <h3>No Test Requests Yet</h3>
            <p>Patient requests assigned to you will appear here.</p>
        </div>

    <?php else: ?>

        <?php foreach ($requests as $request): ?>

            <div class="request">

                <h3><?= e($request['test_name']) ?></h3>

                <div class="info">
                    <strong>Patient:</strong>
                    <?= e($request['patient_name']) ?>
                </div>

                <div class="info">
                    <strong>Patient ID:</strong>
                    <?= e($request['patient_id']) ?>
                </div>

                <div class="info">
                    <strong>Reason:</strong>
                    <?= e($request['reason'] ?: 'Not provided') ?>
                </div>

                <div class="info">
                    <strong>Preferred Date:</strong>
                    <?= e($request['preferred_date'] ?: 'Not specified') ?>
                </div>

                <div class="info">
                    <strong>Requested At:</strong>
                    <?= e($request['created_at']) ?>
                </div>

                <div class="info">
                    <strong>Status:</strong>

                    <span class="status <?= e($request['status']) ?>">
                        <?= e($request['status']) ?>
                    </span>
                </div>

                <?php if ($request['status'] === 'rejected'): ?>
                    <div class="info rejection">
                        <strong>Rejection Reason:</strong>
                        <?= e($request['rejection_reason']) ?>
                    </div>
                <?php endif; ?>

                <?php if ($request['status'] === 'pending'): ?>

                    <form method="POST" class="actions">

                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= e($csrfToken) ?>"
                        >

                        <input
                            type="hidden"
                            name="request_id"
                            value="<?= (int)$request['id'] ?>"
                        >

                        <button
                            type="submit"
                            name="action"
                            value="accept"
                            class="accept-btn"
                            onclick="return confirm('Accept this test request?');"
                        >
                            Accept
                        </button>

                        <button
                            type="submit"
                            name="action"
                            value="reject"
                            class="reject-btn"
                            onclick="return confirm('Reject this test request?');"
                        >
                            Reject
                        </button>

                    </form>

                <?php endif; ?>

            </div>

        <?php endforeach; ?>

    <?php endif; ?>

</div>

</body>
</html>
