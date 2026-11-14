<?php
/**
 * MRMS - Login page (login.php)
 *
 * Display only. No database or authentication logic lives here.
 * Your future login_process.php can redirect back with an error code,
 * for example:  header('Location: login.php?error=invalid');
 */

/* Known error codes -> messages (anything else is ignored) */
$messages = [
    'empty'   => 'Please enter your email and password.',
    'invalid' => 'The email or password you entered is incorrect.',
    'session' => 'Your session has expired. Please log in again.',
];
$errorCode = isset($_GET['error']) ? $_GET['error'] : '';
$errorText = isset($messages[$errorCode]) ? $messages[$errorCode] : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | MRMS</title>
    <link rel="stylesheet" href="assets/css/login.css">
</head>
<body>

<div class="login-page">

    <!-- Brand panel -->
    <aside class="brand-panel">
        <a href="../index.php" class="brand" aria-label="MRMS home">
            <span class="brand-mark">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 12h4l2-6 4 12 2-6h6"/></svg>
            </span>
            <span class="brand-text">MRMS<small>Medical Record Management</small></span>
        </a>

        <div class="brand-copy">
            <h1>Welcome back to your records.</h1>
            <p>Log in to open the workspace for your role.</p>
            <ul class="role-list">
                <li>Admin</li>
                <li>Doctor</li>
                <li>Lab Operator</li>
                <li>Patient</li>
            </ul>
        </div>

        <p class="brand-foot">&copy; <?= date('Y') ?> MRMS</p>
    </aside>

    <!-- Form panel -->
    <main class="form-panel">
        <div class="login-card">

            <h2>Log in to MRMS</h2>
            <p class="subtitle">Enter your account details to continue.</p>

            <!-- Message area: filled by PHP (?error=...) or by login.js -->
            <div id="formMessage" class="form-message<?= $errorText ? ' show' : '' ?>" role="alert" aria-live="polite">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16.5v.5"/></svg>
                <span id="formMessageText"><?= htmlspecialchars($errorText) ?></span>
            </div>

            <form id="loginForm" action="login_process.php" method="POST" novalidate>

                <div class="field">
                    <label for="email">Email address</label>
                    <div class="input-wrap">
                        <svg class="input-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg>
                        <input type="email" id="email" name="email" placeholder="name@example.com" autocomplete="email" required>
                    </div>
                    <span class="field-error" id="emailError"></span>
                </div>

                <div class="field">
                    <label for="password">Password</label>
                    <div class="input-wrap">
                        <svg class="input-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
                        <input type="password" id="password" name="password" placeholder="Enter your password" autocomplete="current-password" required>
                        <button type="button" class="toggle-pass" id="togglePassword" aria-label="Show password" aria-pressed="false">
                            <!-- eye (shown while password is hidden) -->
                            <svg class="eye-on" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>
                            <!-- eye-off (shown while password is visible) -->
                            <svg class="eye-off" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 3l18 18M10.6 5.1A9.7 9.7 0 0 1 12 5c6.5 0 10 7 10 7a17 17 0 0 1-3.2 4M6.5 6.6A17 17 0 0 0 2 12s3.5 7 10 7a9.8 9.8 0 0 0 4.2-.9M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg>
                        </button>
                    </div>
                    <span class="field-error" id="passwordError"></span>
                </div>

                <div class="form-row">
                    <label class="remember" for="remember">
                        <input type="checkbox" id="remember" name="remember" value="1">
                        <span>Remember me</span>
                    </label>
                    <a href="#" class="forgot" id="forgotLink">Forgot password?</a>
                </div>

                <button type="submit" class="btn-login" id="loginBtn">Login</button>
            </form>

            <p class="alt-action">New patient? <a href="register.php">Create an account</a></p>
            <a href="../index.php" class="back-link">&larr; Back to homepage</a>
        </div>
    </main>

</div>

<script src="assets/js/login.js"></script>
</body>
</html>
