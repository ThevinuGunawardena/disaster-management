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
        SELECT id, camp_name, capacity, current_population, latitude, longitude, district
        FROM camps
        WHERE managed_by = ? OR district = ?
        ORDER BY camp_name
    ");

    if ($stmt) {

        $stmt->bind_param("is", $user_id, $user_district);
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
$district_families = [];

if ($role === 'District Admin') {

    // 1. Pending supply requests with camp special requirements
    $stmt = $conn->prepare("
        SELECT
            r.id,
            r.item_type,
            r.quantity,
            r.status,
            c.camp_name,
            c.district,
            (
                SELECT GROUP_CONCAT(DISTINCT f.special_needs_details SEPARATOR ' • ')
                FROM families AS f
                WHERE f.camp_id = c.id
                  AND TRIM(f.special_needs_details) != ''
                  AND LOWER(TRIM(f.special_needs_details)) != 'none'
            ) AS camp_special_needs
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

    // 2. All registered families in this district with special requirements details
    $stmt_fam = $conn->prepare("
        SELECT
            f.id,
            f.family_head_name,
            f.members_count,
            f.infants_count,
            f.special_needs_details,
            f.created_at,
            c.camp_name,
            c.district
        FROM families AS f
        INNER JOIN camps AS c
            ON f.camp_id = c.id
        WHERE TRIM(LOWER(c.district)) = TRIM(LOWER(?))
        ORDER BY f.id DESC
    ");

    if ($stmt_fam) {

        $stmt_fam->bind_param("s", $user_district);
        $stmt_fam->execute();

        $result_fam = $stmt_fam->get_result();

        while ($row = $result_fam->fetch_assoc()) {
            $district_families[] = $row;
        }

        $stmt_fam->close();
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

<?php if (isset($_GET['saved'])): ?>
    <div style="background:#dcfce7; border:1px solid #86efac; color:#15803d; padding:12px 18px; border-radius:8px; margin-bottom:20px; font-weight:600; display:flex; justify-content:space-between; align-items:center;">
        <span>✓ Camp details, intake, and requests processed successfully.</span>
        <button type="button" onclick="this.parentElement.remove()" style="background:none; border:none; color:#15803d; font-size:16px; cursor:pointer;">✕</button>
    </div>
<?php endif; ?>

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

            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                <h2 style="margin:0;">1. Camp Location</h2>
                <span id="campModeBadge" style="background:#e0f2fe; color:#0369a1; padding:4px 10px; border-radius:12px; font-size:12px; font-weight:600;">
                    New Camp Mode
                </span>
            </div>

            <p class="instruction" style="margin-bottom:12px;">
                Select an existing camp below or click a map marker to re-update it. Click an empty spot to create a new camp.
            </p>

            <div style="margin-bottom:12px;">
                <select id="campSelect" style="width:100%; padding:9px 12px; border-radius:6px; border:1px solid #cbd5e1; font-size:14px; background:#fff;">
                    <option value="">-- Choose Existing Camp to Re-update --</option>
                    <?php foreach ($my_camps as $c): ?>
                        <option 
                            value="<?php echo (int)$c['id']; ?>"
                            data-name="<?php echo htmlspecialchars($c['camp_name']); ?>"
                            data-capacity="<?php echo (int)$c['capacity']; ?>"
                            data-lat="<?php echo htmlspecialchars($c['latitude']); ?>"
                            data-lng="<?php echo htmlspecialchars($c['longitude']); ?>"
                            data-pop="<?php echo (int)$c['current_population']; ?>"
                        >
                            📍 <?php echo htmlspecialchars($c['camp_name']); ?> (Cap: <?php echo (int)$c['capacity']; ?>, Pop: <?php echo (int)$c['current_population']; ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <input type="hidden" name="camp_id" id="camp_id" value="">

            <input
                type="text"
                id="camp_name"
                name="camp_name"
                placeholder="Camp Name"
                required
            >

            <input
                type="number"
                id="capacity"
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

            <div id="campActionButtons" style="display:flex; gap:10px; margin-top:12px; flex-wrap:wrap;">
                <button type="button" id="resetCampBtn" onclick="resetToNewCamp()" style="display:none; background:#64748b; color:white; border:none; padding:8px 12px; border-radius:6px; font-size:12px; cursor:pointer; font-weight:600;">
                    ↺ Switch to New Camp Mode
                </button>
                <button type="button" id="updateCampOnlyBtn" onclick="submitCampUpdateOnly()" style="display:none; background:#0284c7; color:white; border:none; padding:8px 12px; border-radius:6px; font-size:12px; cursor:pointer; font-weight:600;">
                    💾 Re-update Camp Details Only
                </button>
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

    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
        <h2 style="margin:0;">Pending Supply Requests</h2>
        <span style="background:#fef3c7; color:#92400e; padding:4px 10px; border-radius:12px; font-size:12px; font-weight:600;">
            Pending: <?php echo count($district_requests); ?>
        </span>
    </div>

    <p style="margin-bottom:15px; color:#64748b;">
        District: <strong><?php echo htmlspecialchars($user_district); ?></strong>
    </p>


    <?php if (count($district_requests) > 0): ?>

        <table class="data-table">

            <thead>

                <tr>
                    <th>Camp Name</th>
                    <th>District</th>
                    <th>Requested Item</th>
                    <th>Quantity</th>
                    <th>Camp Special Requirements</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>

            </thead>


            <tbody>

                <?php foreach ($district_requests as $req): ?>

                    <tr>

                        <td>
                            <strong>
                                <?php echo htmlspecialchars($req['camp_name']); ?>
                            </strong>
                        </td>

                        <td>
                            <?php echo htmlspecialchars($req['district']); ?>
                        </td>

                        <td>
                            <strong><?php echo htmlspecialchars($req['item_type']); ?></strong>
                        </td>

                        <td>
                            <?php echo (int)$req['quantity']; ?>
                        </td>

                        <td>
                            <?php if (!empty($req['camp_special_needs'])): ?>
                                <span style="display:inline-block; background:#fef2f2; color:#b91c1c; border:1px solid #fecaca; padding:4px 8px; border-radius:4px; font-size:12px; font-weight:600; line-height:1.4;">
                                    ⚠️ <?php echo htmlspecialchars($req['camp_special_needs']); ?>
                                </span>
                            <?php else: ?>
                                <span style="color:#94a3b8; font-size:12px; font-style:italic;">None reported</span>
                            <?php endif; ?>
                        </td>

                        <td>
                            <span style="background:#fef9c3; color:#854d0e; padding:3px 8px; border-radius:4px; font-size:12px; font-weight:600;">
                                <?php echo htmlspecialchars($req['status']); ?>
                            </span>
                        </td>

                        <td style="white-space:nowrap;">

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

        <p style="padding:15px; color:#64748b;">
            No pending supply requests found for
            <strong><?php echo htmlspecialchars($user_district); ?></strong>.
        </p>

    <?php endif; ?>

</div>


<!-- ==========================================================
     DISTRICT REGISTERED FAMILIES & SPECIAL REQUIREMENTS
========================================================== -->

<div class="card" style="margin-top:25px; width:100%;">

    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
        <h2 style="margin:0;">Camp Families & Special Requirements</h2>
        <span style="background:#e0f2fe; color:#0369a1; padding:4px 10px; border-radius:12px; font-size:12px; font-weight:600;">
            Total Registered: <?php echo count($district_families); ?> Families
        </span>
    </div>

    <p style="margin-bottom:15px; color:#64748b;">
        Family intake details and special medical/dietary/accessibility requirements entered by Camp Officers across camps in <strong><?php echo htmlspecialchars($user_district); ?></strong>.
    </p>

    <?php if (count($district_families) > 0): ?>

        <table class="data-table">

            <thead>
                <tr>
                    <th>Camp Location</th>
                    <th>Family Head</th>
                    <th>Total Members</th>
                    <th>Infants</th>
                    <th>Special Requirement Details</th>
                    <th>Date Recorded</th>
                </tr>
            </thead>

            <tbody>
                <?php foreach ($district_families as $fam): ?>
                    <tr>
                        <td>
                            <strong><?php echo htmlspecialchars($fam['camp_name']); ?></strong>
                        </td>
                        <td>
                            <?php echo htmlspecialchars($fam['family_head_name']); ?>
                        </td>
                        <td>
                            <?php echo (int)$fam['members_count']; ?>
                        </td>
                        <td>
                            <?php if ((int)$fam['infants_count'] > 0): ?>
                                <span style="background:#fef3c7; color:#92400e; padding:2px 8px; border-radius:4px; font-weight:600; font-size:12px;">
                                    🍼 <?php echo (int)$fam['infants_count']; ?>
                                </span>
                            <?php else: ?>
                                0
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php
                            $special = trim($fam['special_needs_details'] ?? '');
                            if ($special !== '' && strtolower($special) !== 'none'):
                            ?>
                                <span style="display:inline-block; background:#fef2f2; color:#b91c1c; border:1px solid #fecaca; padding:4px 10px; border-radius:4px; font-size:12px; font-weight:600; line-height:1.4;">
                                    ⚠️ <?php echo htmlspecialchars($special); ?>
                                </span>
                            <?php else: ?>
                                <span style="color:#94a3b8; font-style:italic; font-size:12px;">None reported</span>
                            <?php endif; ?>
                        </td>
                        <td style="color:#64748b; font-size:12px; white-space:nowrap;">
                            <?php echo htmlspecialchars(date('M d, Y H:i', strtotime($fam['created_at']))); ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>

        </table>

    <?php else: ?>

        <p style="padding:15px; color:#64748b;">
            No family intakes recorded for <strong><?php echo htmlspecialchars($user_district); ?></strong> yet.
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

let allLoadedCamps = [];
let currentMarker = null;
let campMap = null;

function selectCampForUpdate(camp) {
    const idEl = document.getElementById("camp_id");
    const nameEl = document.getElementById("camp_name");
    const capEl = document.getElementById("capacity");
    const latEl = document.getElementById("lat");
    const lngEl = document.getElementById("lng");

    if (idEl) idEl.value = camp.id;
    if (nameEl) nameEl.value = camp.camp_name;
    if (capEl) capEl.value = camp.capacity;
    if (latEl) latEl.value = parseFloat(camp.latitude).toFixed(6);
    if (lngEl) lngEl.value = parseFloat(camp.longitude).toFixed(6);

    const campSelect = document.getElementById("campSelect");
    if (campSelect) {
        campSelect.value = camp.id;
    }

    const badge = document.getElementById("campModeBadge");
    if (badge) {
        badge.innerText = "Re-updating Existing Camp #" + camp.id;
        badge.style.background = "#fef3c7";
        badge.style.color = "#92400e";
    }

    const resetBtn = document.getElementById("resetCampBtn");
    if (resetBtn) resetBtn.style.display = "inline-block";

    const updateOnlyBtn = document.getElementById("updateCampOnlyBtn");
    if (updateOnlyBtn) updateOnlyBtn.style.display = "inline-block";

    if (campMap) {
        if (currentMarker) {
            campMap.removeLayer(currentMarker);
        }
        currentMarker = L.marker([parseFloat(camp.latitude), parseFloat(camp.longitude)]).addTo(campMap);
        campMap.panTo([parseFloat(camp.latitude), parseFloat(camp.longitude)]);
    }
}

function resetToNewCamp() {
    const idEl = document.getElementById("camp_id");
    const nameEl = document.getElementById("camp_name");
    const capEl = document.getElementById("capacity");
    const latEl = document.getElementById("lat");
    const lngEl = document.getElementById("lng");

    if (idEl) idEl.value = "";
    if (nameEl) nameEl.value = "";
    if (capEl) capEl.value = "";
    if (latEl) latEl.value = "";
    if (lngEl) lngEl.value = "";

    const campSelect = document.getElementById("campSelect");
    if (campSelect) {
        campSelect.value = "";
    }

    const badge = document.getElementById("campModeBadge");
    if (badge) {
        badge.innerText = "New Camp Mode";
        badge.style.background = "#e0f2fe";
        badge.style.color = "#0369a1";
    }

    const resetBtn = document.getElementById("resetCampBtn");
    if (resetBtn) resetBtn.style.display = "none";

    const updateOnlyBtn = document.getElementById("updateCampOnlyBtn");
    if (updateOnlyBtn) updateOnlyBtn.style.display = "none";

    if (currentMarker && campMap) {
        campMap.removeLayer(currentMarker);
        currentMarker = null;
    }
}

function submitCampUpdateOnly() {
    const form = document.getElementById("saveAllForm");
    form.action = "actions.php?action=update_camp";

    const famHead = form.querySelector("[name='family_head']");
    const famMem = form.querySelector("[name='members']");
    const famInf = form.querySelector("[name='infants']");
    if (famHead) famHead.required = false;
    if (famMem) famMem.required = false;
    if (famInf) famInf.required = false;

    const items = form.querySelectorAll("[name='item_type[]']");
    const qtys = form.querySelectorAll("[name='quantity[]']");
    items.forEach(el => el.required = false);
    qtys.forEach(el => el.required = false);

    form.submit();
}

if (campMapElement) {

    campMap =
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


    const campSelect = document.getElementById("campSelect");
    if (campSelect) {
        campSelect.addEventListener("change", function() {
            const selectedId = this.value;
            if (!selectedId) {
                resetToNewCamp();
                return;
            }
            const found = allLoadedCamps.find(c => c.id == selectedId);
            if (found) {
                selectCampForUpdate(found);
            }
        });
    }


    campMap.on("click", function(e) {

        const clickedLat = e.latlng.lat;
        const clickedLng = e.latlng.lng;

        // Check if user clicked near an existing camp marker
        const matchedCamp = allLoadedCamps.find(c => {
            return Math.abs(parseFloat(c.latitude) - clickedLat) < 0.0005 &&
                   Math.abs(parseFloat(c.longitude) - clickedLng) < 0.0005;
        });

        if (matchedCamp) {
            selectCampForUpdate(matchedCamp);
        } else {
            document.getElementById("lat").value =
                clickedLat.toFixed(6);

            document.getElementById("lng").value =
                clickedLng.toFixed(6);

            document.getElementById("camp_id").value = "";

            if (campSelect) campSelect.value = "";

            const badge = document.getElementById("campModeBadge");
            if (badge) {
                badge.innerText = "New Camp Mode";
                badge.style.background = "#e0f2fe";
                badge.style.color = "#0369a1";
            }

            const resetBtn = document.getElementById("resetCampBtn");
            if (resetBtn) resetBtn.style.display = "none";

            const updateOnlyBtn = document.getElementById("updateCampOnlyBtn");
            if (updateOnlyBtn) updateOnlyBtn.style.display = "none";

            if (currentMarker) {
                campMap.removeLayer(currentMarker);
            }

            currentMarker =
                L.marker([
                    clickedLat,
                    clickedLng
                ]).addTo(campMap);
        }

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

            allLoadedCamps = data;

            data.forEach(camp => {

                const lat =
                    parseFloat(camp.latitude);

                const lng =
                    parseFloat(camp.longitude);


                if (
                    !isNaN(lat) &&
                    !isNaN(lng)
                ) {

                    const marker = L.marker([
                        lat,
                        lng
                    ])
                    .addTo(campMap);

                    const popupDiv = document.createElement("div");
                    popupDiv.innerHTML = `
                        <div style="font-family:sans-serif; min-width:180px; padding:2px;">
                            <strong style="font-size:14px; color:#1e293b; display:block; margin-bottom:4px;">${escapeHtml(camp.camp_name)}</strong>
                            <div style="color:#64748b; font-size:12px; line-height:1.5;">
                                <strong>District:</strong> ${escapeHtml(camp.district)}<br>
                                <strong>Occupants:</strong> ${camp.current_population} / ${camp.capacity}<br>
                                <strong>Coords:</strong> ${lat.toFixed(5)}, ${lng.toFixed(5)}
                            </div>
                            <button type="button" class="popup-reupdate-btn" style="margin-top:8px; padding:6px 10px; width:100%; background:#0284c7; color:white; border:none; border-radius:4px; cursor:pointer; font-weight:600; font-size:12px;">
                                ✏️ Re-update This Camp
                            </button>
                        </div>
                    `;

                    popupDiv.querySelector(".popup-reupdate-btn").addEventListener("click", function(ev) {
                        ev.stopPropagation();
                        selectCampForUpdate(camp);
                        campMap.closePopup();
                    });

                    marker.bindPopup(popupDiv);

                    marker.on("click", function() {
                        selectCampForUpdate(camp);
                    });

                }

            });

            // If URL has ?camp_id=, pre-select it
            const urlParams = new URLSearchParams(window.location.search);
            const activeCampId = urlParams.get("camp_id");
            if (activeCampId) {
                const found = allLoadedCamps.find(c => c.id == activeCampId);
                if (found) {
                    selectCampForUpdate(found);
                }
            }

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