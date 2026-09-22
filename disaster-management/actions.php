<?php

session_start();

include 'db.php';


/*
|--------------------------------------------------------------------------
| CHECK LOGIN
|--------------------------------------------------------------------------
*/

if (!isset($_SESSION['user_id'])) {

    die("Access Denied.");

}


$action = $_GET['action'] ?? '';

$user_id = (int)$_SESSION['user_id'];

$role = $_SESSION['role'] ?? '';

$user_district = $_SESSION['district'] ?? '';



/*
|--------------------------------------------------------------------------
| SAVE ALL - CAMP + FAMILY + SUPPLY REQUESTS
|--------------------------------------------------------------------------
|
| This is used by the new Camp Officer dashboard.
|
| One SAVE ALL button will:
|
| 1. Create the camp
| 2. Save the family
| 3. Update camp population
| 4. Save all supply requests
|
| If something fails, everything is rolled back.
|
|--------------------------------------------------------------------------
*/

if ($action === 'save_all') {


    /*
    |--------------------------------------------------------------------------
    | CHECK ROLE
    |--------------------------------------------------------------------------
    */

    if ($role !== 'Camp Officer') {

        die("Access Denied.");

    }


    /*
    |--------------------------------------------------------------------------
    | GET CAMP DETAILS
    |--------------------------------------------------------------------------
    */

    $camp_name = trim(
        $_POST['camp_name'] ?? ''
    );

    $capacity = (int)(
        $_POST['capacity'] ?? 0
    );

    $latitude = trim(
        $_POST['latitude'] ?? ''
    );

    $longitude = trim(
        $_POST['longitude'] ?? ''
    );


    /*
    |--------------------------------------------------------------------------
    | GET FAMILY DETAILS
    |--------------------------------------------------------------------------
    */

    $family_head = trim(
        $_POST['family_head'] ?? ''
    );

    $members = (int)(
        $_POST['members'] ?? 0
    );

    $infants = (int)(
        $_POST['infants'] ?? 0
    );

    $special_needs = trim(
        $_POST['special_needs'] ?? ''
    );


    /*
    |--------------------------------------------------------------------------
    | GET SUPPLY DETAILS
    |--------------------------------------------------------------------------
    */

    $items = $_POST['item_type'] ?? [];

    $quantities = $_POST['quantity'] ?? [];


    /*
    |--------------------------------------------------------------------------
    | DISTRICT
    |--------------------------------------------------------------------------
    */

    $district = $user_district;



    /*
    |--------------------------------------------------------------------------
    | VALIDATE CAMP
    |--------------------------------------------------------------------------
    */

    if (
        $camp_name === '' ||
        $capacity <= 0 ||
        $latitude === '' ||
        $longitude === ''
    ) {

        die(
            "Please complete all Camp Location details."
        );

    }



    /*
    |--------------------------------------------------------------------------
    | VALIDATE FAMILY
    |--------------------------------------------------------------------------
    */

    if (
        $family_head === '' ||
        $members <= 0 ||
        $infants < 0
    ) {

        die(
            "Please complete all Family Intake details."
        );

    }



    /*
    |--------------------------------------------------------------------------
    | CHECK FAMILY MEMBER COUNT
    |--------------------------------------------------------------------------
    |
    | Infants cannot be greater than total members.
    |
    |--------------------------------------------------------------------------
    */

    if ($infants > $members) {

        die(
            "Number of infants cannot be greater than total members."
        );

    }



    /*
    |--------------------------------------------------------------------------
    | VALIDATE SUPPLY REQUESTS
    |--------------------------------------------------------------------------
    */

    if (
        !is_array($items) ||
        !is_array($quantities) ||
        count($items) === 0
    ) {

        die(
            "Please add at least one supply request."
        );

    }



    /*
    |--------------------------------------------------------------------------
    | START DATABASE TRANSACTION
    |--------------------------------------------------------------------------
    */

    $conn->begin_transaction();


    try {


        /*
        ==============================================================
        1. CREATE CAMP
        ==============================================================
        */

        $stmt = $conn->prepare("
            INSERT INTO camps
            (
                camp_name,
                district,
                capacity,
                current_population,
                latitude,
                longitude,
                managed_by
            )
            VALUES
            (?, ?, ?, 0, ?, ?, ?)
        ");


        if (!$stmt) {

            throw new Exception(
                "Camp database error: " .
                $conn->error
            );

        }


        $stmt->bind_param(
            "ssissi",
            $camp_name,
            $district,
            $capacity,
            $latitude,
            $longitude,
            $user_id
        );


        if (!$stmt->execute()) {

            throw new Exception(
                "Failed to register camp: " .
                $stmt->error
            );

        }


        /*
        |--------------------------------------------------------------------------
        | GET NEW CAMP ID
        |--------------------------------------------------------------------------
        */

        $camp_id = $conn->insert_id;


        $stmt->close();



        /*
        ==============================================================
        2. SAVE FAMILY
        ==============================================================
        */

        $stmt = $conn->prepare("
            INSERT INTO families
            (
                camp_id,
                family_head_name,
                members_count,
                infants_count,
                special_needs_details
            )
            VALUES
            (?, ?, ?, ?, ?)
        ");


        if (!$stmt) {

            throw new Exception(
                "Family database error: " .
                $conn->error
            );

        }


        $stmt->bind_param(
            "isiis",
            $camp_id,
            $family_head,
            $members,
            $infants,
            $special_needs
        );


        if (!$stmt->execute()) {

            throw new Exception(
                "Failed to save family: " .
                $stmt->error
            );

        }


        $stmt->close();



        /*
        ==============================================================
        3. UPDATE CAMP POPULATION
        ==============================================================
        */

        $stmt = $conn->prepare("
            UPDATE camps
            SET current_population = ?
            WHERE id = ?
            AND managed_by = ?
        ");


        if (!$stmt) {

            throw new Exception(
                "Population update error: " .
                $conn->error
            );

        }


        $stmt->bind_param(
            "iii",
            $members,
            $camp_id,
            $user_id
        );


        if (!$stmt->execute()) {

            throw new Exception(
                "Failed to update camp population: " .
                $stmt->error
            );

        }


        $stmt->close();



        /*
        ==============================================================
        4. SAVE SUPPLY REQUESTS
        ==============================================================
        */

        $stmt = $conn->prepare("
            INSERT INTO supply_requests
            (
                camp_id,
                item_type,
                quantity,
                requested_by,
                status
            )
            VALUES
            (?, ?, ?, ?, 'Pending')
        ");


        if (!$stmt) {

            throw new Exception(
                "Supply request database error: " .
                $conn->error
            );

        }


        $valid_supply_count = 0;


        for (
            $i = 0;
            $i < count($items);
            $i++
        ) {


            $item = trim(
                $items[$i] ?? ''
            );


            $qty = (int)(
                $quantities[$i] ?? 0
            );


            /*
            |--------------------------------------------------------------------------
            | Ignore completely empty rows
            |--------------------------------------------------------------------------
            */

            if (
                $item === '' &&
                $qty === 0
            ) {

                continue;

            }


            /*
            |--------------------------------------------------------------------------
            | Validate supply row
            |--------------------------------------------------------------------------
            */

            if (
                $item === '' ||
                $qty <= 0
            ) {

                throw new Exception(
                    "Please enter a valid item and quantity for every supply row."
                );

            }


            /*
            |--------------------------------------------------------------------------
            | Insert supply request
            |--------------------------------------------------------------------------
            */

            $stmt->bind_param(
                "isii",
                $camp_id,
                $item,
                $qty,
                $user_id
            );


            if (!$stmt->execute()) {

                throw new Exception(
                    "Failed to save supply request: " .
                    $stmt->error
                );

            }


            $valid_supply_count++;

        }


        $stmt->close();


        /*
        |--------------------------------------------------------------------------
        | MAKE SURE AT LEAST ONE SUPPLY WAS SAVED
        |--------------------------------------------------------------------------
        */

        if ($valid_supply_count === 0) {

            throw new Exception(
                "Please add at least one supply request."
            );

        }



        /*
        |--------------------------------------------------------------------------
        | EVERYTHING SUCCESSFUL
        |--------------------------------------------------------------------------
        */

        $conn->commit();


        /*
        |--------------------------------------------------------------------------
        | RETURN TO DASHBOARD
        |--------------------------------------------------------------------------
        */

        header(
            "Location: index.php?saved=1"
        );

        exit;

    }


    /*
    |--------------------------------------------------------------------------
    | SOMETHING FAILED
    |--------------------------------------------------------------------------
    */

    catch (Exception $e) {


        /*
        |--------------------------------------------------------------
        | Undo everything
        |--------------------------------------------------------------
        */

        $conn->rollback();


        die(
            "Save failed. No records were saved.<br><br>" .
            htmlspecialchars(
                $e->getMessage()
            )
        );

    }

}



/*
|--------------------------------------------------------------------------
| OLD ADD CAMP ACTION
|--------------------------------------------------------------------------
|
| Kept for compatibility.
|
|--------------------------------------------------------------------------
*/

if ($action === 'add_camp') {


    if ($role !== 'Camp Officer') {

        die("Access Denied.");

    }


    $name = trim(
        $_POST['camp_name'] ?? ''
    );

    $capacity = (int)(
        $_POST['capacity'] ?? 0
    );

    $latitude = trim(
        $_POST['latitude'] ?? ''
    );

    $longitude = trim(
        $_POST['longitude'] ?? ''
    );

    $district = $user_district;



    if (
        empty($name) ||
        $capacity <= 0 ||
        empty($latitude) ||
        empty($longitude)
    ) {

        die(
            "Please enter all camp details."
        );

    }



    $stmt = $conn->prepare("
        INSERT INTO camps
        (
            camp_name,
            district,
            capacity,
            current_population,
            latitude,
            longitude,
            managed_by
        )
        VALUES
        (?, ?, ?, 0, ?, ?, ?)
    ");


    if (!$stmt) {

        die(
            "Database error: " .
            $conn->error
        );

    }


    $stmt->bind_param(
        "ssissi",
        $name,
        $district,
        $capacity,
        $latitude,
        $longitude,
        $user_id
    );


    if (!$stmt->execute()) {

        die(
            "Failed to register camp: " .
            $stmt->error
        );

    }


    $stmt->close();


    header(
        "Location: index.php"
    );

    exit;
}



/*
|--------------------------------------------------------------------------
| OLD ADD FAMILY ACTION
|--------------------------------------------------------------------------
*/

if ($action === 'add_family') {


    if ($role !== 'Camp Officer') {

        die("Access Denied.");

    }


    $camp_id = (int)(
        $_POST['camp_id'] ?? 0
    );

    $head = trim(
        $_POST['family_head'] ?? ''
    );

    $members = (int)(
        $_POST['members'] ?? 0
    );

    $infants = (int)(
        $_POST['infants'] ?? 0
    );

    $special = trim(
        $_POST['special_needs'] ?? ''
    );


    if (
        $camp_id <= 0 ||
        $head === '' ||
        $members <= 0 ||
        $infants < 0
    ) {

        die(
            "Please enter valid family details."
        );

    }


    /*
    |--------------------------------------------------------------------------
    | Check Camp Ownership
    |--------------------------------------------------------------------------
    */

    $stmt = $conn->prepare("
        SELECT id
        FROM camps
        WHERE id = ?
        AND managed_by = ?
        LIMIT 1
    ");


    $stmt->bind_param(
        "ii",
        $camp_id,
        $user_id
    );


    $stmt->execute();

    $result = $stmt->get_result();


    if ($result->num_rows === 0) {

        $stmt->close();

        die(
            "You are not authorized to add families to this camp."
        );

    }


    $stmt->close();



    /*
    |--------------------------------------------------------------------------
    | Insert Family
    |--------------------------------------------------------------------------
    */

    $stmt = $conn->prepare("
        INSERT INTO families
        (
            camp_id,
            family_head_name,
            members_count,
            infants_count,
            special_needs_details
        )
        VALUES
        (?, ?, ?, ?, ?)
    ");


    if (!$stmt) {

        die(
            "Database error: " .
            $conn->error
        );

    }


    $stmt->bind_param(
        "isiis",
        $camp_id,
        $head,
        $members,
        $infants,
        $special
    );


    if (!$stmt->execute()) {

        die(
            "Failed to save family: " .
            $stmt->error
        );

    }


    $stmt->close();



    /*
    |--------------------------------------------------------------------------
    | Update Population
    |--------------------------------------------------------------------------
    */

    $stmt = $conn->prepare("
        UPDATE camps
        SET current_population =
            current_population + ?
        WHERE id = ?
        AND managed_by = ?
    ");


    $stmt->bind_param(
        "iii",
        $members,
        $camp_id,
        $user_id
    );


    $stmt->execute();

    $stmt->close();


    header(
        "Location: index.php"
    );

    exit;
}



/*
|--------------------------------------------------------------------------
| OLD REQUEST SUPPLIES ACTION
|--------------------------------------------------------------------------
*/

if ($action === 'request_supplies') {


    if ($role !== 'Camp Officer') {

        die("Access Denied.");

    }


    $camp_id = (int)(
        $_POST['camp_id'] ?? 0
    );

    $items = $_POST['item_type'] ?? [];

    $quantities = $_POST['quantity'] ?? [];


    if ($camp_id <= 0) {

        die(
            "Please select a camp."
        );

    }



    /*
    |--------------------------------------------------------------------------
    | Check Camp Ownership
    |--------------------------------------------------------------------------
    */

    $stmt = $conn->prepare("
        SELECT id
        FROM camps
        WHERE id = ?
        AND managed_by = ?
        LIMIT 1
    ");


    $stmt->bind_param(
        "ii",
        $camp_id,
        $user_id
    );


    $stmt->execute();

    $result = $stmt->get_result();


    if ($result->num_rows === 0) {

        $stmt->close();

        die(
            "You are not authorized to request supplies for this camp."
        );

    }


    $stmt->close();



    /*
    |--------------------------------------------------------------------------
    | Insert Supply Requests
    |--------------------------------------------------------------------------
    */

    $stmt = $conn->prepare("
        INSERT INTO supply_requests
        (
            camp_id,
            item_type,
            quantity,
            requested_by,
            status
        )
        VALUES
        (?, ?, ?, ?, 'Pending')
    ");


    if (!$stmt) {

        die(
            "Database error: " .
            $conn->error
        );

    }


    for (
        $i = 0;
        $i < count($items);
        $i++
    ) {


        $item = trim(
            $items[$i] ?? ''
        );

        $qty = (int)(
            $quantities[$i] ?? 0
        );


        if (
            $item === '' ||
            $qty <= 0
        ) {

            continue;

        }


        $stmt->bind_param(
            "isii",
            $camp_id,
            $item,
            $qty,
            $user_id
        );


        if (!$stmt->execute()) {

            $stmt->close();

            die(
                "Failed to submit supply request."
            );

        }

    }


    $stmt->close();


    header(
        "Location: index.php"
    );

    exit;
}



/*
|--------------------------------------------------------------------------
| DISTRICT ADMIN - APPROVE REQUEST
|--------------------------------------------------------------------------
*/

if ($action === 'approve_req') {


    if ($role !== 'District Admin') {

        die("Access Denied.");

    }


    $request_id = (int)(
        $_GET['id'] ?? 0
    );


    if ($request_id <= 0) {

        die(
            "Invalid request."
        );

    }


    $stmt = $conn->prepare("
        UPDATE supply_requests r
        INNER JOIN camps c
            ON r.camp_id = c.id
        SET r.status = 'Approved'
        WHERE r.id = ?
        AND r.status = 'Pending'
        AND TRIM(
            LOWER(c.district)
        ) = TRIM(
            LOWER(?)
        )
    ");


    if (!$stmt) {

        die(
            "Database error: " .
            $conn->error
        );

    }


    $stmt->bind_param(
        "is",
        $request_id,
        $user_district
    );


    $stmt->execute();


    $stmt->close();


    header(
        "Location: index.php"
    );

    exit;
}



/*
|--------------------------------------------------------------------------
| DISTRICT ADMIN - REJECT REQUEST
|--------------------------------------------------------------------------
*/

if ($action === 'reject_req') {


    if ($role !== 'District Admin') {

        die("Access Denied.");

    }


    $request_id = (int)(
        $_GET['id'] ?? 0
    );


    if ($request_id <= 0) {

        die(
            "Invalid request."
        );

    }


    $stmt = $conn->prepare("
        UPDATE supply_requests r
        INNER JOIN camps c
            ON r.camp_id = c.id
        SET r.status = 'Rejected'
        WHERE r.id = ?
        AND r.status = 'Pending'
        AND TRIM(
            LOWER(c.district)
        ) = TRIM(
            LOWER(?)
        )
    ");


    if (!$stmt) {

        die(
            "Database error: " .
            $conn->error
        );

    }


    $stmt->bind_param(
        "is",
        $request_id,
        $user_district
    );


    $stmt->execute();


    $stmt->close();


    header(
        "Location: index.php"
    );

    exit;
}



/*
|--------------------------------------------------------------------------
| INVALID ACTION
|--------------------------------------------------------------------------
*/

die(
    "Invalid action."
);

?>