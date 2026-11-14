<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| MRMS - Lab Operator Registration
| File: MRMS/lab_register.php
|--------------------------------------------------------------------------
*/

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config/db.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    exit('Database connection unavailable.');
}

$conn->set_charset('utf8mb4');

$errors = [];
$success = '';

$full_name = '';
$email = '';
$laboratory_name = '';
$designation = '';
$employee_id = '';
$phone = '';

if (empty($_SESSION['lab_register_csrf'])) {
    $_SESSION['lab_register_csrf'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $full_name = trim($_POST['full_name'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $laboratory_name = trim($_POST['laboratory_name'] ?? '');
    $designation = trim($_POST['designation'] ?? '');
    $employee_id = trim($_POST['employee_id'] ?? '');
    $phone = trim($_POST['phone'] ?? '');

    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    $csrf_token = $_POST['csrf_token'] ?? '';

    // CSRF validation
    if (
        !is_string($csrf_token) ||
        !hash_equals($_SESSION['lab_register_csrf'], $csrf_token)
    ) {
        $errors[] = 'Invalid form submission. Please refresh and try again.';
    }

    // Required field validation
    if ($full_name === '') {
        $errors[] = 'Full name is required.';
    }

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }

    if ($laboratory_name === '') {
        $errors[] = 'Laboratory name is required.';
    }

    if (strlen($full_name) > 150) {
        $errors[] = 'Full name is too long.';
    }

    if (strlen($email) > 150) {
        $errors[] = 'Email address is too long.';
    }

    if (strlen($laboratory_name) > 200) {
        $errors[] = 'Laboratory name is too long.';
    }

    if (strlen($designation) > 150) {
        $errors[] = 'Designation is too long.';
    }

    if (strlen($employee_id) > 100) {
        $errors[] = 'Employee ID is too long.';
    }

    if (strlen($phone) > 20) {
        $errors[] = 'Phone number is too long.';
    }

    if ($password === '' || strlen($password) < 8) {
        $errors[] = 'Password must contain at least 8 characters.';
    }

    if ($password !== $confirm_password) {
        $errors[] = 'Passwords do not match.';
    }

    if (!$errors) {

        try {

            $conn->begin_transaction();

            // Check duplicate email
            $checkEmail = $conn->prepare(
                "SELECT id FROM users WHERE email = ? LIMIT 1"
            );

            $checkEmail->bind_param("s", $email);
            $checkEmail->execute();

            if ($checkEmail->get_result()->num_rows > 0) {
                throw new RuntimeException(
                    'This email address is already registered.'
                );
            }

            $checkEmail->close();

            // Check duplicate employee ID if provided
            if ($employee_id !== '') {

                $checkEmployee = $conn->prepare(
                    "SELECT id FROM lab_operators
                     WHERE employee_id = ? LIMIT 1"
                );

                $checkEmployee->bind_param("s", $employee_id);
                $checkEmployee->execute();

                if ($checkEmployee->get_result()->num_rows > 0) {
                    throw new RuntimeException(
                        'This Employee ID is already registered.'
                    );
                }

                $checkEmployee->close();
            }

            // Hash password
            $passwordHash = password_hash(
                $password,
                PASSWORD_DEFAULT
            );

            // Insert user account
            // Every new Lab Operator requires admin approval.

            $role = 'lab_operator';
            $accountStatus = 'pending';

            $userStmt = $conn->prepare(
                "INSERT INTO users
                (full_name, email, password_hash, role, account_status)
                VALUES (?, ?, ?, ?, ?)"
            );

            $userStmt->bind_param(
                "sssss",
                $full_name,
                $email,
                $passwordHash,
                $role,
                $accountStatus
            );

            $userStmt->execute();

            $userId = (int)$conn->insert_id;

            $userStmt->close();

            // Insert Lab Operator profile
            $designationValue = $designation !== ''
                ? $designation
                : null;

            $employeeIdValue = $employee_id !== ''
                ? $employee_id
                : null;

            $phoneValue = $phone !== ''
                ? $phone
                : null;

            $labStmt = $conn->prepare(
                "INSERT INTO lab_operators
                (user_id, laboratory_name, designation, employee_id, phone)
                VALUES (?, ?, ?, ?, ?)"
            );

            $labStmt->bind_param(
                "issss",
                $userId,
                $laboratory_name,
                $designationValue,
                $employeeIdValue,
                $phoneValue
            );

            $labStmt->execute();
            $labStmt->close();

            // Both records saved successfully
            $conn->commit();

            // Clear password-related values
            $full_name = '';
            $email = '';
            $laboratory_name = '';
            $designation = '';
            $employee_id = '';
            $phone = '';

            $_SESSION['lab_register_csrf'] = bin2hex(random_bytes(32));

            $success = 'Registration successful! Your account is pending admin approval. You will be able to access your dashboard after approval.';

        } catch (Throwable $e) {

            $conn->rollback();

            if ($e instanceof RuntimeException) {
                $errors[] = $e->getMessage();
            } else {
                error_log('Lab registration error: ' . $e->getMessage());
                $errors[] = 'Registration failed due to a server error. Please try again.';
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Lab Operator Registration | MRMS</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 30px 15px;
            font-family: Arial, sans-serif;
            background: #f3f6fb;
            color: #263445;
        }

        .container {
            max-width: 650px;
            margin: 30px auto;
            padding: 35px;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 5px 25px rgba(0,0,0,.07);
        }

        h1 {
            text-align: center;
            margin-bottom: 8px;
            color: #174b77;
        }

        .subtitle {
            text-align: center;
            color: #777;
            margin-bottom: 28px;
        }

        .form-group {
            margin-bottom: 18px;
        }

        label {
            display: block;
            margin-bottom: 7px;
            font-weight: 600;
        }

        input {
            width: 100%;
            padding: 12px;
            border: 1px solid #d6dce5;
            border-radius: 6px;
            font-size: 15px;
        }

        input:focus {
            outline: none;
            border-color: #2878bd;
        }

        button {
            width: 100%;
            padding: 14px;
            border: none;
            border-radius: 6px;
            background: #1769aa;
            color: white;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
        }

        button:hover {
            background: #10558c;
        }

        .error {
            padding: 13px;
            margin-bottom: 18px;
            background: #ffe9e9;
            color: #a52828;
            border-radius: 6px;
        }

        .success {
            padding: 18px;
            background: #e7f7ec;
            color: #216b38;
            border-radius: 6px;
            line-height: 1.6;
        }

        .notice {
            margin-top: 20px;
            padding: 14px;
            background: #fff7df;
            color: #785b12;
            border-radius: 6px;
            font-size: 14px;
            line-height: 1.6;
        }

        .login-link {
            text-align: center;
            margin-top: 22px;
        }

        .login-link a {
            color: #1769aa;
            text-decoration: none;
            font-weight: bold;
        }

        .required {
            color: #d22;
        }

        @media(max-width: 500px) {
            .container {
                padding: 22px;
            }
        }
    </style>
</head>

<body>

<div class="container">

    <h1>Lab Operator Registration</h1>

    <p class="subtitle">
        Medical Record Management System (MRMS)
    </p>

    <?php if ($success): ?>

        <div class="success">
            <strong>Registration Completed!</strong>
            <p><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></p>
        </div>

    <?php else: ?>

        <?php if ($errors): ?>

            <div class="error">
                <strong>Please fix the following:</strong>
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>

        <?php endif; ?>

        <form method="POST" action="" autocomplete="on">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= htmlspecialchars($_SESSION['lab_register_csrf'], ENT_QUOTES, 'UTF-8') ?>"
            >

            <div class="form-group">
                <label>Full Name <span class="required">*</span></label>
                <input
                    type="text"
                    name="full_name"
                    maxlength="150"
                    value="<?= htmlspecialchars($full_name, ENT_QUOTES, 'UTF-8') ?>"
                    required
                >
            </div>

            <div class="form-group">
                <label>Email Address <span class="required">*</span></label>
                <input
                    type="email"
                    name="email"
                    maxlength="150"
                    value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>"
                    required
                >
            </div>

            <div class="form-group">
                <label>Laboratory Name <span class="required">*</span></label>
                <input
                    type="text"
                    name="laboratory_name"
                    maxlength="200"
                    value="<?= htmlspecialchars($laboratory_name, ENT_QUOTES, 'UTF-8') ?>"
                    required
                >
            </div>

            <div class="form-group">
                <label>Designation</label>
                <input
                    type="text"
                    name="designation"
                    maxlength="150"
                    value="<?= htmlspecialchars($designation, ENT_QUOTES, 'UTF-8') ?>"
                >
            </div>

            <div class="form-group">
                <label>Employee ID</label>
                <input
                    type="text"
                    name="employee_id"
                    maxlength="100"
                    value="<?= htmlspecialchars($employee_id, ENT_QUOTES, 'UTF-8') ?>"
                >
            </div>

            <div class="form-group">
                <label>Phone Number</label>
                <input
                    type="tel"
                    name="phone"
                    maxlength="20"
                    value="<?= htmlspecialchars($phone, ENT_QUOTES, 'UTF-8') ?>"
                >
            </div>

            <div class="form-group">
                <label>Password <span class="required">*</span></label>
                <input
                    type="password"
                    name="password"
                    minlength="8"
                    autocomplete="new-password"
                    required
                >
            </div>

            <div class="form-group">
                <label>Confirm Password <span class="required">*</span></label>
                <input
                    type="password"
                    name="confirm_password"
                    minlength="8"
                    autocomplete="new-password"
                    required
                >
            </div>

            <button type="submit">
                Register as Lab Operator
            </button>

        </form>

    <?php endif; ?>

    <div class="notice">
        <strong>Important:</strong>
        Your account will remain pending until an administrator
        reviews and approves your registration.
    </div>

    <div class="login-link">
        Already registered?
        <a href="auth/login.php">Login Here</a>
    </div>

</div>

</body>
</html>
