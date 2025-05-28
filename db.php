<?php
$host = 'localhost'; // Database host
$db = 'fianlproject'; // Replace with your actual database name
$user = 'root'; // Default username for XAMPP
$pass = ''; // Default password for XAMPP (usually empty)

$mysqli = new mysqli($host, $user, $pass, $db);

// Check connection
if ($mysqli->connect_error) {
    die("Connection failed: " . $mysqli->connect_error);
}
?>
