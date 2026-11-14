
<?php

$host = "localhost";
$user = "root";
$password = "";
$database = "mrms_db";

$conn = mysqli_connect($host, $user, $password, $database);

if (!$conn) {
    die("Database connection failed.");
}

mysqli_set_charset($conn, "utf8mb4");

?>
