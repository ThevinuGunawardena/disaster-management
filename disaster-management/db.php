<?php
$hosts = ["127.0.0.1", "localhost"];
$passwords = ["", "MySQL@liyasha1234"];
$user = "root";
$dbname = "disaster_management";

$conn = null;
foreach ($hosts as $h) {
    foreach ($passwords as $p) {
        $testConn = @new mysqli($h, $user, $p, $dbname);
        if (!$testConn->connect_error) {
            $conn = $testConn;
            break 2;
        }
    }
}

if (!$conn || $conn->connect_error) {
    die("Database engine communication failure: " . ($conn ? $conn->connect_error : "Unable to establish MySQL connection. Please ensure MySQL is running."));
}
?>