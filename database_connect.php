<?php
define('DB_HOST', 'mysql_db');      // Database host
define('DB_USER', 'root');           // Database gebruikersnaam
define('DB_PASSWORD', 'root');           // Database wachtwoord
define('DB_NAME', 'pubquiz');        // Database naam

$conn = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$conn->set_charset("utf8");

?>
