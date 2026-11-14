<?php

session_start();

require_once __DIR__ . '/config/db.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function registrationError($message) {
    $_SESSION['doctor_register_error'] = $message;
    header("Location: doctor_register.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    exit("Invalid request.");
}

try {

    // CSRF validation
    if (
        !isset($_POST['csrf_token'], $_SESSION['doctor_register_csrf']) ||
        !hash_equals(
            $_SESSION['doctor_register_csrf'],
            $_POST['csrf_token']
        )
    ) {
        throw new Exception("Invalid request. Please try again.");
    }

    // Collect form data
    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');

    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    $qualification = trim($_POST['qualification'] ?? '');
    $specialization = trim($_POST['specialization'] ?? '');
    $license_number = trim($_POST['license_number'] ?? '');

    $experience_years = filter_var(
        $_POST['experience_years'] ?? null,
        FILTER_VALIDATE_INT
    );

    $workplace = trim($_POST['workplace'] ?? '');
    $designation = trim($_POST['designation'] ?? '');
    $chamber_address = trim($_POST['chamber_address'] ?? '');

    // Required field validation
    if (
        $full_name === '' ||
        $email === '' ||
        $phone === '' ||
        $password === '' ||
        $confirm_password === '' ||
        $qualification === '' ||
        $specialization === '' ||
        $license_number === '' ||
        $experience_years === false ||
        $workplace === '' ||
        $designation === '' ||
        $chamber_address === ''
    ) {
        throw new Exception("Please fill in all required fields.");
    }

    // Length validation
    if (mb_strlen($full_name) > 150) {
        throw new Exception("Name is too long.");
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL) ||
        strlen($email) > 150) {
        throw new Exception("Enter a valid email address.");
    }

    if (strlen($phone) > 20) {
        throw new Exception("Phone number is too long.");
    }

    if (
    mb_strlen($qualification) > 255 ||
    mb_strlen($specialization) > 255 ||
    mb_strlen($license_number) > 100 ||
    mb_strlen($workplace) > 200 ||
    mb_strlen($designation) > 150
) {
    throw new Exception("One or more professional fields are too long.");
}

    if ($experience_years < 0 || $experience_years > 70) {
        throw new Exception("Invalid experience years.");
    }

    if (strlen($password) < 8) {
        throw new Exception("Password must contain at least 8 characters.");
    }

    if ($password !== $confirm_password) {
        throw new Exception("Passwords do not match.");
    }

    // Password hashing
    $password_hash = password_hash(
        $password,
        PASSWORD_DEFAULT
    );

    // Check duplicate email
    $checkEmail = $conn->prepare(
        "SELECT id FROM users WHERE email = ? LIMIT 1"
    );

    $checkEmail->bind_param("s", $email);
    $checkEmail->execute();
    $checkEmail->store_result();

    if ($checkEmail->num_rows > 0) {
        $checkEmail->close();
        throw new Exception("This email is already registered.");
    }

    $checkEmail->close();

    // Check duplicate BMDC number
    $checkLicense = $conn->prepare(
        "SELECT id FROM doctors WHERE license_number = ? LIMIT 1"
    );

    $checkLicense->bind_param("s", $license_number);
    $checkLicense->execute();
    $checkLicense->store_result();

    if ($checkLicense->num_rows > 0) {
        $checkLicense->close();
        throw new Exception("This BMDC registration number is already registered.");
    }

    $checkLicense->close();

    // Start transaction
    $conn->begin_transaction();

    try {

        // Insert user account
        $userStmt = $conn->prepare(
            "INSERT INTO users
            (
                full_name,
                email,
                phone,
                password_hash,
                role,
                account_status
            )
            VALUES (?, ?, ?, ?, 'doctor', 'pending')"
        );

        $userStmt->bind_param(
            "ssss",
            $full_name,
            $email,
            $phone,
            $password_hash
        );

        $userStmt->execute();

        $user_id = $conn->insert_id;

        $userStmt->close();

        // Insert doctor professional profile
        $doctorStmt = $conn->prepare(
            "INSERT INTO doctors
            (
                user_id,
                specialization,
                qualification,
                license_number,
                workplace,
                experience_years,
                verification_status,
                designation,
                chamber_address
            )
            VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, ?)"
        );


        $doctorStmt->bind_param(
            "issssiss",
            $user_id,
            $specialization,
            $qualification,
            $license_number,
            $workplace,
            $experience_years,
            $designation,
            $chamber_address
        );

        $doctorStmt->execute();
        $doctorStmt->close();

        // Save both records
        $conn->commit();

        unset($_SESSION['doctor_register_csrf']);

        // Success response
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport"
                  content="width=device-width, initial-scale=1.0">
            <title>Registration Successful | MRMS</title>
            <style>
                body {
                    font-family: Arial, sans-serif;
                    background: #f4f7fa;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    min-height: 100vh;
                    margin: 0;
                    padding: 15px;
                }
                .box {
                    background: white;
                    padding: 35px;
                    border-radius: 12px;
                    text-align: center;
                    max-width: 450px;
                }
                h2 { color: #16804a; }
                p { color: #555; line-height: 1.6; }
                a {
                    display: inline-block;
                    margin-top: 15px;
                    padding: 12px 25px;
                    background: #2878b5;
                    color: white;
                    text-decoration: none;
                    border-radius: 6px;
                }
            </style>
        </head>
        <body>
            <div class="box">
                <h2>Registration Successful!</h2>
                <p>
                    Your doctor account and professional
                    information have been saved successfully.
                </p>
                <p>
                    Your account is currently waiting
                    for admin approval.
                </p>
                <a href="login.php">Go to Login</a>
            </div>
        </body>
        </html>
        <?php

    } catch (Throwable $e) {

        $conn->rollback();
        throw $e;
    }

} catch (mysqli_sql_exception $e) {

    error_log("Doctor registration error: " . $e->getMessage());

    if ((int)$e->getCode() === 1062) {
        registrationError(
            "Email or BMDC registration number is already registered."
        );
    }

    registrationError(
        "Registration failed. Please try again."
    );

} catch (Throwable $e) {

    registrationError($e->getMessage());
}

?>
