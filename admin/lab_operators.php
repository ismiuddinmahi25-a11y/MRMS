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

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = '';
$error = '';

function e($value): string
{
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

// ========================================
// APPROVE / REJECT LAB OPERATOR
// ========================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $token = $_POST['csrf_token'] ?? '';
    $userId = filter_input(
        INPUT_POST,
        'user_id',
        FILTER_VALIDATE_INT
    );

    $action = $_POST['action'] ?? '';

    if (
        !is_string($token) ||
        !hash_equals($_SESSION['csrf_token'], $token)
    ) {
        $error = 'Invalid security token. Please refresh the page.';

    } elseif (
        !$userId ||
        !in_array($action, ['approve', 'reject'], true)
    ) {
        $error = 'Invalid approval request.';

    } else {

        try {

            $conn->begin_transaction();

            // Lock the user account
            $stmt = $conn->prepare(
                "SELECT id, role, account_status
                 FROM users
                 WHERE id = ?
                 AND role = 'lab_operator'
                 FOR UPDATE"
            );

            $stmt->bind_param('i', $userId);
            $stmt->execute();

            $result = $stmt->get_result();
            $operator = $result->fetch_assoc();

            $stmt->close();

            if (!$operator) {
                throw new Exception('Lab Operator account not found.');
            }

            if ($operator['account_status'] !== 'pending') {
                throw new Exception(
                    'This account is no longer pending approval.'
                );
            }

            $newStatus = ($action === 'approve')
                ? 'active'
                : 'rejected';

            // Update users table using users.id
            $stmt = $conn->prepare(
                "UPDATE users
                 SET account_status = ?
                 WHERE id = ?
                 AND role = 'lab_operator'
                 AND account_status = 'pending'"
            );

            $stmt->bind_param(
                'si',
                $newStatus,
                $userId
            );

            $stmt->execute();

            if ($stmt->affected_rows !== 1) {
                throw new Exception(
                    'Unable to update Lab Operator account.'
                );
            }

            $stmt->close();

            $conn->commit();

            $message = ($action === 'approve')
                ? 'Lab Operator approved successfully.'
                : 'Lab Operator rejected successfully.';

        } catch (Throwable $e) {

            try {
                $conn->rollback();
            } catch (Throwable $rollbackError) {
                // Ignore rollback errors.
            }

            error_log('Lab Operator approval error: ' . $e->getMessage());

            $error = $e->getMessage();
        }
    }
}

// ========================================
// FETCH LAB OPERATORS
// ========================================

$sql = "
    SELECT
        u.id AS user_id,
        lo.id AS operator_id,
        u.full_name,
        u.email,
        u.account_status,
        lo.laboratory_name,
        lo.designation,
        lo.employee_id,
        lo.phone,
        lo.created_at

    FROM users u

    INNER JOIN lab_operators lo
        ON lo.user_id = u.id

    WHERE u.role = 'lab_operator'

    ORDER BY
        FIELD(
            u.account_status,
            'pending',
            'active',
            'rejected',
            'suspended'
        ),
        lo.created_at DESC
";

$result = $conn->query($sql);

if (!$result) {
    http_response_code(500);
    exit('Unable to retrieve Lab Operators.');
}

$operators = $result->fetch_all(MYSQLI_ASSOC);

// ========================================
// COUNTS
// ========================================

$counts = [
    'all' => count($operators),
    'pending' => 0,
    'active' => 0,
    'rejected' => 0,
    'suspended' => 0
];

foreach ($operators as $operator) {

    $status = $operator['account_status'];

    if (isset($counts[$status])) {
        $counts[$status]++;
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport"
      content="width=device-width, initial-scale=1.0">

<title>Lab Operator Management | MRMS</title>

<style>

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    padding: 25px;
    font-family: Arial, sans-serif;
    background: #f4f7fb;
    color: #1e293b;
}

.container {
    max-width: 1400px;
    margin: auto;
}

h1 {
    margin-bottom: 8px;
}

.subtitle {
    color: #64748b;
    margin-bottom: 25px;
}

.message {
    padding: 13px 16px;
    border-radius: 8px;
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

.stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 15px;
    margin-bottom: 25px;
}

.stat {
    background: white;
    padding: 20px;
    border-radius: 10px;
    border: 1px solid #e2e8f0;
}

.stat span {
    display: block;
    color: #64748b;
    font-size: 14px;
}

.stat strong {
    display: block;
    font-size: 26px;
    margin-top: 8px;
}

.table-container {
    background: white;
    border-radius: 10px;
    border: 1px solid #e2e8f0;
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    min-width: 1050px;
}

th, td {
    padding: 14px;
    text-align: left;
    border-bottom: 1px solid #e2e8f0;
    font-size: 14px;
}

th {
    background: #f8fafc;
    color: #475569;
}

.status {
    display: inline-block;
    padding: 5px 10px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: bold;
    text-transform: capitalize;
}

.pending {
    background: #fef3c7;
    color: #92400e;
}

.active {
    background: #dcfce7;
    color: #166534;
}

.rejected {
    background: #fee2e2;
    color: #991b1b;
}

.suspended {
    background: #e2e8f0;
    color: #334155;
}

button {
    border: none;
    padding: 9px 13px;
    border-radius: 6px;
    cursor: pointer;
    font-weight: bold;
    margin: 2px;
}

.approve {
    background: #16a34a;
    color: white;
}

.reject {
    background: #dc2626;
    color: white;
}

.approve:hover {
    background: #15803d;
}

.reject:hover {
    background: #b91c1c;
}

.empty {
    padding: 30px;
    text-align: center;
    color: #64748b;
}

.back-link {
    display: inline-block;
    margin-bottom: 20px;
    color: #2563eb;
    text-decoration: none;
}

@media (max-width: 600px) {
    body {
        padding: 14px;
    }
}

</style>

</head>

<body>

<div class="container">

    <a href="dashboard.php" class="back-link">
        &larr; Back to Admin Dashboard
    </a>

    <h1>Lab Operator Management</h1>

    <p class="subtitle">
        Manage Lab Operator accounts and approve registration requests.
    </p>

    <?php if ($message !== ''): ?>

        <div class="message success">
            <?= e($message) ?>
        </div>

    <?php endif; ?>

    <?php if ($error !== ''): ?>

        <div class="message error">
            <?= e($error) ?>
        </div>

    <?php endif; ?>

    <h2>Overview</h2>

    <div class="stats">

        <div class="stat">
            <span>Total Lab Operators</span>
            <strong><?= $counts['all'] ?></strong>
        </div>

        <div class="stat">
            <span>Pending</span>
            <strong><?= $counts['pending'] ?></strong>
        </div>

        <div class="stat">
            <span>Approved</span>
            <strong><?= $counts['active'] ?></strong>
        </div>

        <div class="stat">
            <span>Rejected</span>
            <strong><?= $counts['rejected'] ?></strong>
        </div>

    </div>

    <h2>Lab Operators List</h2>

    <div class="table-container">

        <table>

            <thead>

                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Laboratory</th>
                    <th>Designation</th>
                    <th>Employee ID</th>
                    <th>Phone</th>
                    <th>Account Status</th>
                    <th>Registered At</th>
                    <th>Actions</th>
                </tr>

            </thead>

            <tbody>

            <?php if (empty($operators)): ?>

                <tr>
                    <td colspan="9" class="empty">
                        No Lab Operators found.
                    </td>
                </tr>

            <?php else: ?>

                <?php foreach ($operators as $operator): ?>

                    <tr>

                        <td>
                            <?= e($operator['full_name']) ?>
                        </td>

                        <td>
                            <?= e($operator['email']) ?>
                        </td>

                        <td>
                            <?= e($operator['laboratory_name']) ?>
                        </td>

                        <td>
                            <?= e($operator['designation'] ?: 'N/A') ?>
                        </td>

                        <td>
                            <?= e($operator['employee_id'] ?: 'N/A') ?>
                        </td>

                        <td>
                            <?= e($operator['phone'] ?: 'N/A') ?>
                        </td>

                        <td>

                            <span class="status <?= e($operator['account_status']) ?>">
                                <?= e($operator['account_status']) ?>
                            </span>

                        </td>

                        <td>
                            <?= e($operator['created_at']) ?>
                        </td>

                        <td>

                            <?php if ($operator['account_status'] === 'pending'): ?>

                                <form method="POST"
                                      onsubmit="return confirm('Are you sure you want to process this request?');">

                                    <input
                                        type="hidden"
                                        name="csrf_token"
                                        value="<?= e($_SESSION['csrf_token']) ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="user_id"
                                        value="<?= (int)$operator['user_id'] ?>"
                                    >

                                    <button
                                        type="submit"
                                        name="action"
                                        value="approve"
                                        class="approve"
                                    >
                                        Approve
                                    </button>

                                    <button
                                        type="submit"
                                        name="action"
                                        value="reject"
                                        class="reject"
                                    >
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

    </div>

</div>

</body>
</html>
