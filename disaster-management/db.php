<?php
$host = "localhost";
$user = "root";
$pass = "MySQL@liyasha1234";
$dbname = "disaster_management";

$conn = new mysqli($host, $user, $pass, $dbname);
if ($conn->connect_error) {
    die("Database engine communication failure: " . $conn->connect_error);
}
?>