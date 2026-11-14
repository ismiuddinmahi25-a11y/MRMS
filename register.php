
<?php


session_start();

require_once __DIR__ . '/config/db.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$error = '';
$success = '';

if (!isset($_SESSION['register_csrf'])) {
    $_SESSION['register_csrf'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    try {

        if (
            !isset($_POST['csrf_token']) ||
            !hash_equals($_SESSION['register_csrf'], $_POST['csrf_token'])
        ) {
            throw new Exception("Invalid request. Please try again.");
        }

        $full_name = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        $dob = $_POST['date_of_birth'] ?? '';
        $gender = $_POST['gender'] ?? '';
        $phone = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $emergency_name = trim($_POST['emergency_contact_name'] ?? '');
        $emergency_phone = trim($_POST['emergency_contact_phone'] ?? '');

        // Basic validation
        if (
            $full_name === '' ||
            $email === '' ||
            $password === '' ||
            $confirm_password === '' ||
            $dob === '' ||
            $gender === ''
        ) {
            throw new Exception("Please fill in all required fields.");
        }

        if (mb_strlen($full_name) > 150) {
            throw new Exception("Name is too long.");
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150) {
            throw new Exception("Enter a valid email address.");
        }

        if (strlen($password) < 8) {
            throw new Exception("Password must contain at least 8 characters.");
        }

        if ($password !== $confirm_password) {
            throw new Exception("Passwords do not match.");
        }

        if (!in_array($gender, ['Male', 'Female', 'Other'], true)) {
            throw new Exception("Invalid gender selection.");
        }

        $date = DateTime::createFromFormat('!Y-m-d', $dob);

        if (!$date || $date->format('Y-m-d') !== $dob ||
            $dob > date('Y-m-d')) {
            throw new Exception("Enter a valid date of birth.");
        }

        if (
            strlen($phone) > 20 ||
            strlen($emergency_phone) > 20 ||
            mb_strlen($emergency_name) > 150
        ) {
            throw new Exception("One or more fields exceed the allowed length.");
        }

        // Hash password
        $password_hash = password_hash($password, PASSWORD_DEFAULT);

        // Start database transaction
        $conn->begin_transaction();

        try {

            // Insert into users table
            $stmt = $conn->prepare(
                "INSERT INTO users
                (full_name, email, password_hash, role, account_status)
                VALUES (?, ?, ?, 'patient', 'pending')"
            );

            $stmt->bind_param(
                "sss",
                $full_name,
                $email,
                $password_hash
            );

            $stmt->execute();

            // Get newly created user ID
            $user_id = $conn->insert_id;

            // Insert into patients table
            $stmt2 = $conn->prepare(
                "INSERT INTO patients
                (
                    user_id,
                    date_of_birth,
                    gender,
                    phone,
                    address,
                    emergency_contact_name,
                    emergency_contact_phone
                )
                VALUES (?, ?, ?, ?, ?, ?, ?)"
            );

            $stmt2->bind_param(
                "issssss",
                $user_id,
                $dob,
                $gender,
                $phone,
                $address,
                $emergency_name,
                $emergency_phone
            );

            $stmt2->execute();

            // Commit both insertions
            $conn->commit();

            $success = "Registration successful! Your account is pending admin approval.";

            $_SESSION['register_csrf'] = bin2hex(random_bytes(32));

        } catch (Throwable $e) {

            $conn->rollback();

            throw $e;
        }

    } catch (mysqli_sql_exception $e) {

        if ((int)$e->getCode() === 1062) {
            $error = "This email is already registered.";
        } else {
            error_log($e->getMessage());
            $error = "Registration failed. Please try again.";
        }

    } catch (Throwable $e) {

        $error = $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Patient Registration | MRMS</title>

<style>
* {
    box-sizing: border-box;
}

body {
    margin: 0;
    padding: 30px 15px;
    font-family: Arial, sans-serif;
    background: #f4f6f9;
    color: #333;
}

.container {
    max-width: 650px;
    margin: auto;
    padding: 30px;
    background: white;
    border-radius: 8px;
    border: 1px solid #e5e7eb;
}

h2 {
    text-align: center;
    margin-bottom: 8px;
}

.subtitle {
    text-align: center;
    color: #777;
    margin-bottom: 25px;
}

.form-group {
    margin-bottom: 16px;
}

label {
    display: block;
    margin-bottom: 7px;
    font-size: 14px;
    font-weight: bold;
}

input, select, textarea {
    width: 100%;
    padding: 11px;
    border: 1px solid #ccc;
    border-radius: 5px;
    font-size: 14px;
}

input:focus, select:focus, textarea:focus {
    outline: none;
    border-color: #2878b5;
}

textarea {
    resize: vertical;
    min-height: 75px;
}

button {
    width: 100%;
    padding: 12px;
    border: none;
    border-radius: 5px;
    background: #2878b5;
    color: white;
    font-size: 16px;
    cursor: pointer;
}

button:hover {
    background: #1e6092;
}

.message {
    padding: 12px;
    margin-bottom: 18px;
    border-radius: 5px;
    font-size: 14px;
}

.error {
    background: #fee2e2;
    color: #b91c1c;
}

.success {
    background: #dcfce7;
    color: #166534;
}

.login-link {
    text-align: center;
    margin-top: 20px;
    font-size: 14px;
}

.login-link a {
    color: #2878b5;
    text-decoration: none;
}

@media(max-width: 480px) {
    .container {
        padding: 20px;
    }
}
</style>
</head>

<body>

<div class="container">

    <h2>Patient Registration</h2>
    <p class="subtitle">Create your MRMS patient account</p>

    <?php if ($error): ?>
        <div class="message error">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="message success">
            <?= htmlspecialchars($success) ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="">

        <input type="hidden" name="csrf_token"
        value="<?= htmlspecialchars($_SESSION['register_csrf']) ?>">

        <div class="form-group">
            <label>Full Name *</label>
            <input type="text" name="full_name"
            maxlength="150" required>
        </div>

        <div class="form-group">
            <label>Email Address *</label>
            <input type="email" name="email"
            maxlength="150" required>
        </div>

        <div class="form-group">
            <label>Password *</label>
            <input type="password" name="password"
            minlength="8" required>
        </div>

        <div class="form-group">
            <label>Confirm Password *</label>
            <input type="password" name="confirm_password"
            minlength="8" required>
        </div>

        <div class="form-group">
            <label>Date of Birth *</label>
            <input type="date" name="date_of_birth"
            max="<?= date('Y-m-d') ?>" required>
        </div>

        <div class="form-group">
            <label>Gender *</label>
            <select name="gender" required>
                <option value="">Select Gender</option>
                <option value="Male">Male</option>
                <option value="Female">Female</option>
                <option value="Other">Other</option>
            </select>
        </div>

        <div class="form-group">
            <label>Phone</label>
            <input type="tel" name="phone" maxlength="20">
        </div>

        <div class="form-group">
            <label>Address</label>
            <textarea name="address"></textarea>
        </div>

        <div class="form-group">
            <label>Emergency Contact Name</label>
            <input type="text" name="emergency_contact_name"
            maxlength="150">
        </div>

        <div class="form-group">
            <label>Emergency Contact Phone</label>
            <input type="tel" name="emergency_contact_phone"
            maxlength="20">
        </div>

        <button type="submit">Register</button>

    </form>

    <div class="login-link">
        Already have an account?
        <a href="login.php">Login</a>
    </div>

</div>

</body>
</html>
