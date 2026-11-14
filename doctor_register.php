<?php
session_start();

if (!isset($_SESSION['doctor_register_csrf'])) {
    $_SESSION['doctor_register_csrf'] = bin2hex(random_bytes(32));
}

$error = $_SESSION['doctor_register_error'] ?? '';
unset($_SESSION['doctor_register_error']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Doctor Registration | MRMS</title>

<style>
* {
    box-sizing: border-box;
}

body {
    margin: 0;
    padding: 35px 15px;
    font-family: Arial, sans-serif;
    background: #f4f7fa;
    color: #263445;
}

.container {
    max-width: 750px;
    margin: auto;
    padding: 30px;
    background: white;
    border-radius: 12px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.06);
}

h2 {
    text-align: center;
    margin-bottom: 8px;
}

.subtitle {
    text-align: center;
    color: #788594;
    margin-bottom: 30px;
}

.section-title {
    font-size: 17px;
    font-weight: bold;
    margin: 25px 0 18px;
    padding-bottom: 10px;
    border-bottom: 1px solid #e5eaf0;
}

.form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 18px;
}

.form-group {
    margin-bottom: 16px;
}

.full {
    grid-column: 1 / -1;
}

label {
    display: block;
    margin-bottom: 7px;
    font-size: 14px;
    font-weight: 600;
}

input, textarea {
    width: 100%;
    padding: 12px;
    border: 1px solid #d5dce5;
    border-radius: 6px;
    font-size: 14px;
}

input:focus, textarea:focus {
    outline: none;
    border-color: #2878b5;
}

textarea {
    min-height: 85px;
    resize: vertical;
}

button {
    width: 100%;
    padding: 14px;
    background: #2878b5;
    color: white;
    border: none;
    border-radius: 6px;
    font-size: 16px;
    font-weight: bold;
    cursor: pointer;
    margin-top: 15px;
}

button:hover {
    background: #1e6092;
}

.error {
    padding: 12px;
    margin-bottom: 20px;
    background: #fee2e2;
    color: #b91c1c;
    border-radius: 6px;
}

.login-link {
    text-align: center;
    margin-top: 20px;
    font-size: 14px;
}

a {
    color: #2878b5;
    text-decoration: none;
}

@media(max-width: 550px) {
    .form-grid {
        grid-template-columns: 1fr;
    }

    .container {
        padding: 20px;
    }
}
</style>
</head>

<body>

<div class="container">

    <h2>Doctor Registration</h2>

    <p class="subtitle">
        Create your professional MRMS account
    </p>

    <?php if ($error): ?>
        <div class="error">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <form action="doctor_register_process.php"
          method="POST">

        <input type="hidden"
               name="csrf_token"
               value="<?= htmlspecialchars($_SESSION['doctor_register_csrf']) ?>">

        <div class="section-title">
            Account Information
        </div>

        <div class="form-grid">

            <div class="form-group full">
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
                <label>Phone Number *</label>
                <input type="tel" name="phone"
                       maxlength="20" required>
            </div>

            <div class="form-group">
                <label>Password *</label>
                <input type="password" name="password"
                       minlength="8" required>
            </div>

            <div class="form-group">
                <label>Confirm Password *</label>
                <input type="password"
                       name="confirm_password"
                       minlength="8" required>
            </div>

        </div>

        <div class="section-title">
            Professional Information
        </div>

        <div class="form-grid">

            <div class="form-group">
                <label>Medical Degree *</label>
                <input type="text" name="qualification"
                       placeholder="e.g. MBBS, FCPS" required>
            </div>

            <div class="form-group">
                <label>Specialization *</label>
                <input type="text" name="specialization"
                       placeholder="e.g. Cardiology" required>
            </div>

            <div class="form-group">
                <label>BMDC Registration Number *</label>
                <input type="text" name="license_number"
                       maxlength="100" required>
            </div>

            <div class="form-group">
                <label>Years of Experience *</label>
                <input type="number" name="experience_years"
                       min="0" max="70" required>
            </div>

            <div class="form-group full">
                <label>Hospital / Workplace *</label>
                <input type="text" name="workplace"
                       maxlength="200" required>
            </div>
            <p style="color:red; font-size:18px;">
    DESIGNATION TEST
</p>
<div class="form-group">
    <label>Designation *</label>
    <input type="text"
           name="designation"
           placeholder="e.g. Consultant"
           maxlength="150"
           required>
</div>
        </div>

        <div class="section-title">
            Chamber Information
        </div>

        <div class="form-group">
            <label>Chamber Address *</label>
            <textarea name="chamber_address"
                      required></textarea>
        </div>

        <button type="submit">
            Create Doctor Account
        </button>

    </form>

    <div class="login-link">
        Already registered?
        <a href="login.php">Login Here</a>
    </div>

</div>

</body>
</html>
