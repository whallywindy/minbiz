<?php
// Minbiz - WROSYS - Database Connection Sample
// Real credentials are NOT in GitHub for security

$db_host = "YOUR_HOST";
$db_user = "YOUR_DB_USER";
$db_pass = "YOUR_DB_PASSWORD";
$db_name = "YOUR_DB_NAME";

$conn = mysqli_connect($db_host, $db_user, $db_pass, $db_name);

if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}
?>