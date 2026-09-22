<?php
session_start();
include 'db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$role = $_SESSION['role'] ?? '';
$username = $_SESSION['username'] ?? '';
$user_district = $_SESSION['district'] ?? '';

/*
|--------------------------------------------------------------------------
| Get camps managed by this Camp Officer
|--------------------------------------------------------------------------
*/
$my_camps = [];

if ($role === 'Camp Officer') {

    $user_id = (int)$_SESSION['user_id'];

    $stmt = $conn->prepare("
        SELECT id, camp_name, capacity, current_population
        FROM camps
        WHERE managed_by = ?
        ORDER BY camp_name
    ");

    if ($stmt) {

        $stmt->bind_param("i", $user_id);
        $stmt->execute();

        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $my_camps[] = $row;
        }

        $stmt->close();
    }
}


/*
|--------------------------------------------------------------------------
| District Admin - Get Pending Supply Requests
|--------------------------------------------------------------------------
*/
$district_requests = [];

if ($role === 'District Admin') {

    $stmt = $conn->prepare("
        SELECT
            r.id,
            r.item_type,
            r.quantity,
            r.status,
            c.camp_name,
            c.district
        FROM supply_requests AS r
        INNER JOIN camps AS c
            ON r.camp_id = c.id
        WHERE r.status = 'Pending'
        AND TRIM(LOWER(c.district)) = TRIM(LOWER(?))
        ORDER BY r.id DESC
    ");

    if ($stmt) {

        $stmt->bind_param("s", $user_district);
        $stmt->execute();

        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $district_requests[] = $row;
        }

        $stmt->close();
    }
}


/*
|--------------------------------------------------------------------------
| National Authority Statistics
|--------------------------------------------------------------------------
*/
$total_camps = 0;
$total_population = 0;

if ($role === 'National Authority') {

    $result = $conn->query("
        SELECT COUNT(*) AS total
        FROM camps
    ");

    if ($result) {

        $row = $result->fetch_assoc();
        $total_camps = (int)$row['total'];
    }


    $result = $conn->query("
        SELECT COALESCE(SUM(members_count), 0) AS total
        FROM families
    ");

    if ($result) {

        $row = $result->fetch_assoc();
        $total_population = (int)$row['total'];
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Disaster Management System Dashboard</title>


    <!-- Leaflet CSS -->
    <link
        rel="stylesheet"
        href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
    >


    <style>

        /* =====================================================
           GENERAL
        ===================================================== */

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #f4f6f8;
            color: #333;
        }


        /* =====================================================
           HEADER
        ===================================================== */

        header {
            background: #1f4e78;
            color: white;
            padding: 20px 30px;
            position: relative;
        }

        header h1 {
            margin: 0 0 8px 0;
            font-size: 26px;
        }

        header p {
            margin: 5px 0;
        }

        .logout-btn {
            position: absolute;
            right: 30px;
            top: 25px;
            background: #e74c3c;
            color: white;
            text-decoration: none;
            padding: 10px 18px;
            border-radius: 5px;
        }

        .logout-btn:hover {
            background: #c0392b;
        }


        /* =====================================================
           MAIN CONTAINER
        ===================================================== */

        .container {
            width: 95%;
            max-width: 1500px;
            margin: 25px auto;
        }


        /* =====================================================
           CAMP OFFICER GRID
        ===================================================== */

        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            align-items: start;
        }


        /* =====================================================
           CARDS
        ===================================================== */

        .card {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }

        .card h2 {
            margin-top: 0;
            margin-bottom: 15px;
            color: #1f4e78;
            font-size: 20px;
        }

        .instruction {
            color: #666;
            font-size: 14px;
        }


        /* =====================================================
           INPUTS
        ===================================================== */

        input,
        textarea,
        select {
            width: 100%;
            padding: 11px;
            margin-bottom: 12px;
            border: 1px solid #ccc;
            border-radius: 5px;
            font-size: 14px;
        }

        textarea {
            min-height: 90px;
            resize: vertical;
        }

        input:focus,
        textarea:focus {
            outline: none;
            border-color: #1f4e78;
        }


        /* =====================================================
           MAP
        ===================================================== */

        .map-card {
            grid-column: 1 / -1;
        }

        #campMap {
            width: 100%;
            height: 400px;
            border-radius: 8px;
        }


        /* =====================================================
           SUPPLY TABLE
        ===================================================== */

        #supplyTable {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 12px;
        }

        #supplyTable th {
            background: #1f4e78;
            color: white;
            padding: 8px;
            text-align: left;
        }

        #supplyTable td {
            padding: 5px;
            vertical-align: middle;
        }

        #supplyTable input {
            margin-bottom: 0;
        }


        /* =====================================================
           BUTTONS
        ===================================================== */

        button {
            border: none;
            border-radius: 5px;
            padding: 9px 14px;
            cursor: pointer;
            font-size: 14px;
        }

        button:hover {
            opacity: 0.9;
        }

        #supplyTable button {
            background: #e74c3c;
            color: white;
        }

        .add-item-btn {
            background: #3498db;
            color: white;
        }


        /* =====================================================
           SAVE BUTTON
        ===================================================== */

        .save-card {
            grid-column: 1 / -1;
        }

        .save-all-btn {
            width: 100%;
            background: #27ae60;
            color: white;
            padding: 15px;
            font-size: 18px;
            font-weight: bold;
        }

        .save-all-btn:hover {
            background: #219150;
        }


        /* =====================================================
           DATA TABLE
        ===================================================== */

        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }

        .data-table th {
            background: #1f4e78;
            color: white;
            padding: 12px;
            text-align: left;
        }

        .data-table td {
            padding: 12px;
            border-bottom: 1px solid #ddd;
        }

        .data-table tr:hover {
            background: #f5f5f5;
        }


        /* =====================================================
           ACTION BUTTONS
        ===================================================== */

        .btn-action {
            display: inline-block;
            padding: 7px 12px;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            margin-right: 5px;
            font-size: 13px;
        }

        .approve {
            background: #27ae60;
        }

        .reject {
            background: #e74c3c;
        }


        /* =====================================================
           NATIONAL AUTHORITY
        ===================================================== */

        #nationalMap {
            width: 100%;
            height: 450px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .metrics-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .metric-card {
            padding: 25px;
            border-radius: 8px;
            text-align: center;
            background: #f5f5f5;
        }

        .metric-card h3 {
            margin-top: 0;
        }

        .metric-card strong {
            font-size: 35px;
        }

        .metric-card.blue {
            border-left: 6px solid #3498db;
        }

        .metric-card.red {
            border-left: 6px solid #e74c3c;
        }


        /* =====================================================
           RESPONSIVE
        ===================================================== */

        @media (max-width: 1000px) {

            .dashboard-grid {
                grid-template-columns: 1fr 1fr;
            }

            .map-card,
            .save-card {
                grid-column: 1 / -1;
            }

        }


        @media (max-width: 650px) {

            .dashboard-grid {
                grid-template-columns: 1fr;
            }

            .map-card,
            .save-card {
                grid-column: auto;
            }

            .metrics-grid {
                grid-template-columns: 1fr;
            }

            header {
                padding-bottom: 70px;
            }

            .logout-btn {
                left: 30px;
                right: auto;
                top: auto;
                bottom: 15px;
            }

        }

    </style>

</head>


<body>


<header>

    <h1>Disaster Management System Dashboard</h1>

    <p>
        Operational Profile:

        <strong>
            <?php echo htmlspecialchars($username); ?>

            (<?php echo htmlspecialchars($role); ?>)
        </strong>
    </p>


    <?php if (!empty($user_district)): ?>

        <p>
            District:

            <strong>
                <?php echo htmlspecialchars($user_district); ?>
            </strong>
        </p>

    <?php endif; ?>


    <a
        href="logout.php"
        class="logout-btn"
    >
        Sign Out
    </a>

</header>


<div class="container">


<?php if ($role === 'Camp Officer'): ?>


<form
    action="actions.php?action=save_all"
    method="POST"
    id="saveAllForm"
>


    <div class="dashboard-grid">


        <!-- =================================================
             CAMP LOCATION
        ================================================== -->

        <div class="card">

            <h2>1. Register Camp Location</h2>

            <p class="instruction">
                Click on the map to select the camp location.
            </p>

            <input
                type="text"
                name="camp_name"
                placeholder="Camp Name"
                required
            >

            <input
                type="number"
                name="capacity"
                placeholder="Resource Capacity"
                min="1"
                required
            >

            <div style="display:flex; gap:10px;">

                <input
                    type="text"
                    id="lat"
                    name="latitude"
                    placeholder="Latitude"
                    readonly
                    required
                >

                <input
                    type="text"
                    id="lng"
                    name="longitude"
                    placeholder="Longitude"
                    readonly
                    required
                >

            </div>

        </div>


        <!-- =================================================
             FAMILY INTAKE
        ================================================== -->

        <div class="card">

            <h2>2. Family Intake</h2>

            <input
                type="text"
                name="family_head"
                placeholder="Family Head Full Name"
                required
            >

            <input
                type="number"
                name="members"
                placeholder="Total Members"
                min="1"
                required
            >

            <input
                type="number"
                name="infants"
                placeholder="Number of Infants"
                min="0"
                value="0"
                required
            >

            <textarea
                name="special_needs"
                placeholder="Special requirements details..."
            ></textarea>

        </div>


        <!-- =================================================
             SUPPLY REQUESTS
        ================================================== -->

        <div class="card">

            <h2>3. Submit Supply Requests</h2>

            <table id="supplyTable">

                <thead>

                    <tr>
                        <th>Item</th>
                        <th>Qty</th>
                        <th></th>
                    </tr>

                </thead>


                <tbody>

                    <tr>

                        <td>

                            <input
                                type="text"
                                name="item_type[]"
                                placeholder="Item"
                                required
                            >

                        </td>


                        <td>

                            <input
                                type="number"
                                name="quantity[]"
                                placeholder="Qty"
                                min="1"
                                required
                            >

                        </td>


                        <td>

                            <button
                                type="button"
                                onclick="removeItem(this)"
                            >
                                ✕
                            </button>

                        </td>

                    </tr>

                </tbody>

            </table>


            <button
                type="button"
                class="add-item-btn"
                onclick="addItem()"
            >
                + Add Item
            </button>

        </div>


        <!-- =================================================
             MAP
        ================================================== -->

        <div class="card map-card">

            <h2>
                Geographical Interface Control Map
            </h2>

            <div id="campMap"></div>

        </div>


        <!-- =================================================
             SAVE ALL
        ================================================== -->

        <div class="save-card">

            <button
                type="submit"
                class="save-all-btn"
            >
                SAVE ALL
            </button>

        </div>


    </div>

</form>


<?php elseif ($role === 'District Admin'): ?>


<!-- ==========================================================
     DISTRICT ADMIN
========================================================== -->

<div class="card">

    <h2>Pending Supply Requests</h2>

    <p>
        District:

        <strong>
            <?php echo htmlspecialchars($user_district); ?>
        </strong>
    </p>


    <?php if (count($district_requests) > 0): ?>

        <table class="data-table">

            <thead>

                <tr>
                    <th>Camp Name</th>
                    <th>District</th>
                    <th>Requested Item</th>
                    <th>Quantity</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>

            </thead>


            <tbody>

                <?php foreach ($district_requests as $req): ?>

                    <tr>

                        <td>
                            <?php
                            echo htmlspecialchars(
                                $req['camp_name']
                            );
                            ?>
                        </td>

                        <td>
                            <?php
                            echo htmlspecialchars(
                                $req['district']
                            );
                            ?>
                        </td>

                        <td>
                            <?php
                            echo htmlspecialchars(
                                $req['item_type']
                            );
                            ?>
                        </td>

                        <td>
                            <?php
                            echo (int)$req['quantity'];
                            ?>
                        </td>

                        <td>
                            <?php
                            echo htmlspecialchars(
                                $req['status']
                            );
                            ?>
                        </td>

                        <td>

                            <a
                                href="actions.php?action=approve_req&id=<?php echo (int)$req['id']; ?>"
                                class="btn-action approve"
                                onclick="return confirm('Approve this supply request?');"
                            >
                                Approve
                            </a>

                            <a
                                href="actions.php?action=reject_req&id=<?php echo (int)$req['id']; ?>"
                                class="btn-action reject"
                                onclick="return confirm('Reject this supply request?');"
                            >
                                Reject
                            </a>

                        </td>

                    </tr>

                <?php endforeach; ?>

            </tbody>

        </table>


    <?php else: ?>

        <p style="padding:15px;">

            No pending supply requests found for

            <strong>
                <?php echo htmlspecialchars($user_district); ?>
            </strong>.

        </p>

    <?php endif; ?>

</div>


<?php elseif ($role === 'National Authority'): ?>


<!-- ==========================================================
     NATIONAL AUTHORITY
========================================================== -->

<div class="card">

    <h2>
        National Spatial Monitoring Intelligence Grid
    </h2>


    <div id="nationalMap"></div>


    <div class="metrics-grid">


        <div class="metric-card blue">

            <h3>
                Active Registered Camps
            </h3>

            <strong>
                <?php echo $total_camps; ?>
            </strong>

        </div>


        <div class="metric-card red">

            <h3>
                Total Displaced Occupants
            </h3>

            <strong>
                <?php echo $total_population; ?>
            </strong>

        </div>


    </div>

</div>


<?php else: ?>


<div class="card">

    <h2>Access Denied</h2>

    <p>
        Your account does not have a valid dashboard role.
    </p>

</div>


<?php endif; ?>


</div>


<!-- ==========================================================
     LEAFLET JS
========================================================== -->

<script
    src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
></script>


<script>

/* ==========================================================
   ADD SUPPLY ITEM
========================================================== */

function addItem() {

    const table =
        document.querySelector("#supplyTable tbody");

    const row =
        document.createElement("tr");

    row.innerHTML = `

        <td>

            <input
                type="text"
                name="item_type[]"
                placeholder="Item"
                required
            >

        </td>

        <td>

            <input
                type="number"
                name="quantity[]"
                placeholder="Qty"
                min="1"
                required
            >

        </td>

        <td>

            <button
                type="button"
                onclick="removeItem(this)"
            >
                ✕
            </button>

        </td>

    `;

    table.appendChild(row);
}


/* ==========================================================
   REMOVE SUPPLY ITEM
========================================================== */

function removeItem(button) {

    const rows =
        document.querySelectorAll(
            "#supplyTable tbody tr"
        );

    if (rows.length > 1) {

        button.closest("tr").remove();

    }
}


/* ==========================================================
   CAMP OFFICER MAP
========================================================== */

<?php if ($role === 'Camp Officer'): ?>

const campMapElement =
    document.getElementById("campMap");


if (campMapElement) {

    const campMap =
        L.map("campMap").setView(
            [7.8731, 80.7718],
            7
        );


    L.tileLayer(
        "https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png",
        {
            attribution:
                "&copy; OpenStreetMap contributors"
        }
    ).addTo(campMap);


    let currentMarker = null;


    campMap.on("click", function(e) {

        document.getElementById("lat").value =
            e.latlng.lat.toFixed(6);

        document.getElementById("lng").value =
            e.latlng.lng.toFixed(6);


        if (currentMarker) {

            campMap.removeLayer(currentMarker);

        }


        currentMarker =
            L.marker([
                e.latlng.lat,
                e.latlng.lng
            ]).addTo(campMap);

    });


    /*
    |--------------------------------------------------------------------------
    | Load Existing Camps
    |--------------------------------------------------------------------------
    */

    fetch("get_camps.php")

        .then(response => {

            if (!response.ok) {

                throw new Error(
                    "Failed to load camps"
                );

            }

            return response.json();

        })


        .then(data => {

            data.forEach(camp => {

                const lat =
                    parseFloat(camp.latitude);

                const lng =
                    parseFloat(camp.longitude);


                if (
                    !isNaN(lat) &&
                    !isNaN(lng)
                ) {

                    L.marker([
                        lat,
                        lng
                    ])

                    .addTo(campMap)

                    .bindPopup(

                        "<b>" +
                        escapeHtml(
                            camp.camp_name
                        ) +
                        "</b><br>" +

                        "District: " +
                        escapeHtml(
                            camp.district
                        ) +

                        "<br>Occupants: " +

                        camp.current_population +

                        "/" +

                        camp.capacity

                    );

                }

            });

        })


        .catch(error => {

            console.error(
                "Camp loading error:",
                error
            );

        });

}


/* ==========================================================
   NATIONAL AUTHORITY MAP
========================================================== */

<?php elseif ($role === 'National Authority'): ?>

const nationalMapElement =
    document.getElementById("nationalMap");


if (nationalMapElement) {

    const nationalMap =
        L.map("nationalMap").setView(
            [7.8731, 80.7718],
            7
        );


    L.tileLayer(
        "https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png",
        {
            attribution:
                "&copy; OpenStreetMap contributors"
        }
    ).addTo(nationalMap);


    fetch("get_camps.php")

        .then(response => {

            if (!response.ok) {

                throw new Error(
                    "Failed to load camps"
                );

            }

            return response.json();

        })


        .then(data => {

            data.forEach(camp => {

                const lat =
                    parseFloat(camp.latitude);

                const lng =
                    parseFloat(camp.longitude);


                if (
                    !isNaN(lat) &&
                    !isNaN(lng)
                ) {

                    L.marker([
                        lat,
                        lng
                    ])

                    .addTo(nationalMap)

                    .bindPopup(

                        "<b>" +
                        escapeHtml(
                            camp.camp_name
                        ) +
                        "</b><br>" +

                        "District: " +
                        escapeHtml(
                            camp.district
                        ) +

                        "<br>Occupants: " +

                        camp.current_population +

                        "/" +

                        camp.capacity

                    );

                }

            });

        })


        .catch(error => {

            console.error(
                "National map error:",
                error
            );

        });

}


<?php endif; ?>


/* ==========================================================
   SAFE HTML
========================================================== */

function escapeHtml(value) {

    return String(value)

        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");

}

</script>


</body>
</html>