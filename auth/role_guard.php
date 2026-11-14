
<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check whether user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header("Location: ../index.php");
    exit;
}

// Check required role
if (!isset($required_role) || $_SESSION['role'] !== $required_role) {
    http_response_code(403);
    exit("Access Denied: You are not authorized to access this page.");
}

?>
