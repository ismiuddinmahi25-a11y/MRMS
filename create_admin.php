
<?php
require_once 'config/db.php';

$message = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $name = trim($_POST['full_name']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "Invalid email address.";

    } elseif (strlen($password) < 8) {
        $message = "Password must be at least 8 characters.";

    } elseif ($password !== $confirm_password) {
        $message = "Passwords do not match.";

    } else {

        // Prevent duplicate admin accounts
        $check = mysqli_query(
            $conn,
            "SELECT id FROM users WHERE role = 'admin' LIMIT 1"
        );

        if (mysqli_num_rows($check) > 0) {
            $message = "An admin account already exists.";

        } else {

            // Check duplicate email
            $stmt = mysqli_prepare(
                $conn,
                "SELECT id FROM users WHERE email = ?"
            );

            mysqli_stmt_bind_param($stmt, "s", $email);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_store_result($stmt);

            if (mysqli_stmt_num_rows($stmt) > 0) {
                $message = "This email is already registered.";

            } else {

                // Hash password
                $hashed_password = password_hash(
                    $password,
                    PASSWORD_DEFAULT
                );

                // Create admin account
                $insert = mysqli_prepare(
                    $conn,
                    "INSERT INTO users
                    (full_name, email, password_hash, role,
                    account_status, created_at, updated_at)
                    VALUES (?, ?, ?, 'admin', 'active', NOW(), NOW())"
                );

                mysqli_stmt_bind_param(
                    $insert,
                    "sss",
                    $name,
                    $email,
                    $hashed_password
                );

                if (mysqli_stmt_execute($insert)) {
                    $message = "Admin account created successfully!";
                } else {
                    $message = "Error: " . mysqli_error($conn);
                }

                mysqli_stmt_close($insert);
            }

            mysqli_stmt_close($stmt);
        }

        mysqli_free_result($check);
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Create Admin</title>
</head>
<body>

<h2>Create MRMS Admin Account</h2>

<p><?php echo htmlspecialchars($message); ?></p>

<form method="POST">

    <label>Full Name:</label><br>
    <input type="text" name="full_name"
           value="Ismi Uddin Mahi" required><br><br>

    <label>Email:</label><br>
    <input type="email" name="email"
           value="ismiuddinmahi25@gmail.com" required><br><br>

    <label>Password:</label><br>
    <input type="password" name="password" required><br><br>

    <label>Confirm Password:</label><br>
    <input type="password" name="confirm_password" required><br><br>

    <button type="submit">Create Admin Account</button>

</form>

</body>
</html>
