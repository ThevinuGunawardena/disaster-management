<?php

session_start();

include 'db.php';

header('Content-Type: application/json; charset=utf-8');


/*
|--------------------------------------------------------------------------
| Check Login
|--------------------------------------------------------------------------
*/

if (!isset($_SESSION['user_id'])) {

    http_response_code(401);

    echo json_encode([
        "error" => "Access Denied"
    ]);

    exit;
}


$role = $_SESSION['role'] ?? '';



/*
|--------------------------------------------------------------------------
| Only these roles can see the camp map
|--------------------------------------------------------------------------
*/

if (
    $role !== 'Camp Officer' &&
    $role !== 'National Authority'
) {

    http_response_code(403);

    echo json_encode([
        "error" => "Access Denied"
    ]);

    exit;
}



/*
|--------------------------------------------------------------------------
| Get all camps
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        id,
        camp_name,
        district,
        capacity,
        current_population,
        latitude,
        longitude
    FROM camps
    WHERE latitude IS NOT NULL
    AND longitude IS NOT NULL
    ORDER BY id DESC
";


$result = $conn->query($sql);


if (!$result) {

    http_response_code(500);

    echo json_encode([
        "error" => "Database query failed"
    ]);

    exit;
}



$camps = [];


while ($row = $result->fetch_assoc()) {

    $camps[] = [

        "id" => (int)$row["id"],

        "camp_name" => $row["camp_name"],

        "district" => $row["district"],

        "capacity" => (int)$row["capacity"],

        "current_population" =>
            (int)$row["current_population"],

        "latitude" =>
            (float)$row["latitude"],

        "longitude" =>
            (float)$row["longitude"]

    ];

}



echo json_encode(
    $camps,
    JSON_UNESCAPED_UNICODE
);

?>