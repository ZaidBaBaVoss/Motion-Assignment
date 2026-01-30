<?php
// Database configuration
$host = 'localhost';
$dbname = 'user_management';
$username = 'root';
$password = '';

// Create connection using MySQLi (Procedural)
$conn = mysqli_connect($host, $username, $password, $dbname);

// Check connection
if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

// Set charset to utf8mb4
mysqli_set_charset($conn, "utf8mb4");
?>
