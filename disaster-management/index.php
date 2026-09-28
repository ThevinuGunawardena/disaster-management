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
$camp_families_map = [];

if ($role === 'District Admin') {

    // 1. Supply requests with camp special requirements
    $stmt = $conn->prepare("
        SELECT
            r.id,
            r.camp_id,
            r.item_type,
            r.quantity,
            r.status,
            r.created_at,
            c.camp_name,
            c.district,
            c.capacity,
            c.current_population,
            COALESCE(u.username, 'Officer') AS officer_name,
            (
                SELECT GROUP_CONCAT(DISTINCT CONCAT(f.family_head_name, ' (', f.members_count, ' mem): ', f.special_needs_details) SEPARATOR ' \n• ')
                FROM families AS f
                WHERE f.camp_id = c.id
                  AND TRIM(f.special_needs_details) != ''
                  AND LOWER(TRIM(f.special_needs_details)) != 'none'
            ) AS camp_special_needs
        FROM supply_requests AS r
        INNER JOIN camps AS c
            ON r.camp_id = c.id
        LEFT JOIN users AS u
            ON r.requested_by = u.id
        WHERE (? = 'All' OR TRIM(LOWER(c.district)) = TRIM(LOWER(?)))
        ORDER BY r.id DESC
    ");

    if ($stmt) {

        $stmt->bind_param("ss", $user_district, $user_district);
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
            f.camp_id,
            f.family_head_name,
            f.members_count,
            f.infants_count,
            f.special_needs_details,
            f.created_at,
            c.camp_name,
            c.district,
            c.capacity,
            c.current_population,
            COALESCE(u.username, 'Camp Officer') AS officer_name
        FROM families AS f
        INNER JOIN camps AS c
            ON f.camp_id = c.id
        LEFT JOIN users AS u
            ON c.managed_by = u.id
        WHERE (? = 'All' OR TRIM(LOWER(c.district)) = TRIM(LOWER(?)))
        ORDER BY f.id DESC
    ");

    if ($stmt_fam) {

        $stmt_fam->bind_param("ss", $user_district, $user_district);
        $stmt_fam->execute();

        $result_fam = $stmt_fam->get_result();

        while ($row = $result_fam->fetch_assoc()) {
            $district_families[] = $row;
            $cid = (int)$row['camp_id'];
            if (!isset($camp_families_map[$cid])) {
                $camp_families_map[$cid] = [];
            }
            $camp_families_map[$cid][] = $row;
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
            border: none;
            cursor: pointer;
            font-family: inherit;
        }

        .btn-action:hover {
            opacity: 0.9;
        }

        .approve {
            background: #27ae60;
        }

        .reject {
            background: #e74c3c;
        }

        .view-btn {
            background: #4f46e5;
            color: white;
            font-weight: 600;
        }

        .view-btn:hover {
            background: #4338ca;
        }

        /* View Toggle Tabs */
        .view-toggle-btn {
            background: #f1f5f9;
            color: #475569;
            border: 1px solid #cbd5e1;
            padding: 8px 14px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .view-toggle-btn:hover {
            background: #e2e8f0;
            color: #1e293b;
        }

        .view-toggle-btn.active {
            background: #1e40af;
            color: #ffffff;
            border-color: #1e40af;
            box-shadow: 0 2px 4px rgba(30, 64, 175, 0.25);
        }

        /* Modal Overlay */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            background: rgba(15, 23, 42, 0.65);
            backdrop-filter: blur(3px);
            z-index: 99999;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            box-sizing: border-box;
        }

        @keyframes fadeInModal {
            from { opacity: 0; transform: translateY(-10px) scale(0.98); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }

        .modal-animated {
            animation: fadeInModal 0.2s ease-out;
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

<?php if (isset($_GET['deleted'])): ?>
    <div style="background:#fee2e2; border:1px solid #fca5a5; color:#b91c1c; padding:12px 18px; border-radius:8px; margin-bottom:20px; font-weight:600; display:flex; justify-content:space-between; align-items:center;">
        <span>✓ Camp location <?php echo !empty($_GET['camp_name']) ? '"' . htmlspecialchars($_GET['camp_name']) . '" ' : ''; ?>was deleted successfully.</span>
        <button type="button" onclick="this.parentElement.remove()" style="background:none; border:none; color:#b91c1c; font-size:16px; cursor:pointer;">✕</button>
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
                Select an existing camp below or click a map marker to modify its details and coordinates. Click an empty spot to create a new camp.
            </p>

            <div style="margin-bottom:12px;">
                <select id="campSelect" style="width:100%; padding:9px 12px; border-radius:6px; border:1px solid #cbd5e1; font-size:14px; background:#fff;">
                    <option value="">-- Choose Existing Camp to Modify / View --</option>
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
                <button type="button" id="updateCampOnlyBtn" onclick="submitCampUpdateOnly()" style="display:none; background:#0284c7; color:white; border:none; padding:8px 12px; border-radius:6px; font-size:12px; cursor:pointer; font-weight:600;">
                    💾 Save Modified Details
                </button>
                <button type="button" id="deleteCampBtn" onclick="deleteSelectedCamp()" style="display:none; background:#ef4444; color:white; border:none; padding:8px 12px; border-radius:6px; font-size:12px; cursor:pointer; font-weight:600;">
                    🗑️ Delete Location
                </button>
                <button type="button" id="resetCampBtn" onclick="resetToNewCamp()" style="display:none; background:#64748b; color:white; border:none; padding:8px 12px; border-radius:6px; font-size:12px; cursor:pointer; font-weight:600;">
                    ↺ Switch to New Camp Mode
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


<!-- ==========================================================
     MY MANAGED CAMPS & MAP LOCATIONS
========================================================== -->
<div class="card" style="margin-top:25px; width:100%;">

    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
        <h2 style="margin:0;">My Managed Camps & Map Locations</h2>
        <span style="background:#e0f2fe; color:#0369a1; padding:4px 10px; border-radius:12px; font-size:12px; font-weight:600;">
            Managed Camps: <?php echo count($my_camps); ?>
        </span>
    </div>

    <p style="margin-bottom:15px; color:#64748b;">
        Review your camp details, modify information and coordinates, or delete locations when camps close.
    </p>

    <?php if (count($my_camps) > 0): ?>

        <table class="data-table">
            <thead>
                <tr>
                    <th>Camp Name</th>
                    <th>District</th>
                    <th>Capacity</th>
                    <th>Current Population</th>
                    <th>Coordinates</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($my_camps as $c): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($c['camp_name']); ?></strong></td>
                        <td><?php echo htmlspecialchars($c['district']); ?></td>
                        <td><?php echo (int)$c['capacity']; ?></td>
                        <td>
                            <span style="background:#f1f5f9; padding:2px 8px; border-radius:4px; font-weight:600;">
                                <?php echo (int)$c['current_population']; ?> / <?php echo (int)$c['capacity']; ?>
                            </span>
                        </td>
                        <td style="font-size:12px; color:#64748b; font-family:monospace;">
                            <?php echo number_format((float)$c['latitude'], 5); ?>, <?php echo number_format((float)$c['longitude'], 5); ?>
                        </td>
                        <td style="white-space:nowrap;">
                            <button
                                type="button"
                                class="btn-action"
                                style="background:#0284c7; margin-right:5px; border:none; cursor:pointer;"
                                onclick="editCampFromTable(<?php echo htmlspecialchars(json_encode($c)); ?>)"
                            >
                                ✏️ Modify Details
                            </button>
                            <a
                                href="actions.php?action=delete_camp&id=<?php echo (int)$c['id']; ?>"
                                class="btn-action reject"
                                onclick="return confirm('Are you sure you want to delete camp location <?php echo htmlspecialchars(addslashes($c['camp_name'])); ?>? This will remove the map marker and all associated records.');"
                            >
                                🗑️ Delete Location
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

    <?php endif; ?>

</div>


<?php elseif ($role === 'District Admin'): ?>


<!-- ==========================================================
     DISTRICT ADMIN DASHBOARD
========================================================== -->

<!-- Top Summary & View Option Control Bar -->
<div class="card" style="margin-bottom:20px;">
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:15px; margin-bottom:15px;">
        <div>
            <h2 style="margin:0 0 5px 0; color:#1e293b;">District Logistics & Population Command</h2>
            <p style="margin:0; color:#64748b; font-size:14px;">
                Jurisdiction: <strong style="color:#0f172a;"><?php echo htmlspecialchars($user_district); ?> District</strong>
            </p>
        </div>

        <!-- View Option Controls -->
        <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
            <span style="font-size:13px; font-weight:600; color:#475569; margin-right:4px;">👁️ View Option:</span>
            <button type="button" class="view-toggle-btn active" id="btnViewAll" onclick="switchDistrictView('all')">
                📋 View All
            </button>
            <button type="button" class="view-toggle-btn" id="btnViewItems" onclick="switchDistrictView('items')">
                📦 View Option: Items (<?php echo count($district_requests); ?>)
            </button>
            <button type="button" class="view-toggle-btn" id="btnViewPeople" onclick="switchDistrictView('people')">
                👥 View Option: People (<?php echo count($district_families); ?>)
            </button>
        </div>
    </div>

    <!-- Quick Stats Cards Row -->
    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:15px; margin-bottom:15px;">
        <?php
            $pending_count = 0;
            $approved_count = 0;
            foreach ($district_requests as $r) {
                if ($r['status'] === 'Pending') $pending_count++;
                if ($r['status'] === 'Approved') $approved_count++;
            }
            $special_needs_count = 0;
            $total_infants = 0;
            $total_members = 0;
            foreach ($district_families as $f) {
                $total_members += (int)$f['members_count'];
                $total_infants += (int)$f['infants_count'];
                $sn = trim($f['special_needs_details'] ?? '');
                if ($sn !== '' && strtolower($sn) !== 'none') {
                    $special_needs_count++;
                }
            }
        ?>
        <div style="background:#f8fafc; border:1px solid #e2e8f0; border-left:4px solid #3b82f6; padding:12px 16px; border-radius:8px;">
            <div style="font-size:12px; font-weight:600; color:#64748b; text-transform:uppercase;">Relief Supply Items</div>
            <div style="font-size:24px; font-weight:700; color:#1e293b; margin:4px 0;"><?php echo count($district_requests); ?> <span style="font-size:13px; font-weight:500; color:#64748b;">requests</span></div>
            <div style="font-size:12px; color:#475569;">
                <span style="color:#d97706; font-weight:600;">⏳ <?php echo $pending_count; ?> Pending</span> &bull; 
                <span style="color:#16a34a; font-weight:600;">✓ <?php echo $approved_count; ?> Approved</span>
            </div>
        </div>

        <div style="background:#f8fafc; border:1px solid #e2e8f0; border-left:4px solid #10b981; padding:12px 16px; border-radius:8px;">
            <div style="font-size:12px; font-weight:600; color:#64748b; text-transform:uppercase;">Camp Population (People)</div>
            <div style="font-size:24px; font-weight:700; color:#1e293b; margin:4px 0;"><?php echo $total_members; ?> <span style="font-size:13px; font-weight:500; color:#64748b;">people</span></div>
            <div style="font-size:12px; color:#475569;">
                <span><?php echo count($district_families); ?> families</span> &bull; 
                <span style="color:#0284c7; font-weight:600;">🍼 <?php echo $total_infants; ?> Infants</span>
            </div>
        </div>

        <div style="background:#fef2f2; border:1px solid #fecaca; border-left:4px solid #ef4444; padding:12px 16px; border-radius:8px;">
            <div style="font-size:12px; font-weight:600; color:#991b1b; text-transform:uppercase;">Uploaded Special Requirements</div>
            <div style="font-size:24px; font-weight:700; color:#b91c1c; margin:4px 0;"><?php echo $special_needs_count; ?> <span style="font-size:13px; font-weight:500; color:#991b1b;">cases</span></div>
            <div style="font-size:12px; color:#b91c1c; font-weight:500;">
                ⚠️ Critical medical, dietary, & disability needs
            </div>
        </div>
    </div>

    <!-- Live Search Filter -->
    <div style="display:flex; gap:10px; align-items:center;">
        <input 
            type="text" 
            id="districtSearchInput" 
            placeholder="🔍 Live filter by Item name, Person / Family head, Camp, or Special requirement keyword..." 
            oninput="filterDistrictRecords()" 
            style="width:100%; padding:10px 14px; border:1px solid #cbd5e1; border-radius:6px; font-size:14px; background:#fff;"
        >
        <button type="button" onclick="clearDistrictSearch()" style="padding:10px 14px; background:#e2e8f0; color:#475569; border:none; border-radius:6px; font-size:13px; cursor:pointer; font-weight:600; white-space:nowrap;">
            ✕ Clear
        </button>
    </div>
</div>

<!-- ==========================================================
     SECTION 1: ITEMS (SUPPLY REQUESTS)
========================================================== -->
<div class="card" id="districtItemsCard" style="margin-bottom:25px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; flex-wrap:wrap; gap:10px;">
        <div>
            <h2 style="margin:0; display:flex; align-items:center; gap:8px;">
                <span>📦 View Option: Supply Requests (Items)</span>
            </h2>
            <p style="margin:4px 0 0 0; color:#64748b; font-size:13px;">
                Officer submitted logistics requirements paired with camp-level special requirement details.
            </p>
        </div>
        <span style="background:#e0f2fe; color:#0369a1; padding:4px 12px; border-radius:12px; font-size:12px; font-weight:600;">
            <?php echo count($district_requests); ?> Total Items
        </span>
    </div>

    <?php if (count($district_requests) > 0): ?>
        <table class="data-table" id="districtItemsTable">
            <thead>
                <tr>
                    <th style="width:20%;">Requested Item</th>
                    <th style="width:10%;">Quantity</th>
                    <th style="width:18%;">Camp Location</th>
                    <th style="width:12%;">Officer</th>
                    <th style="width:10%;">Status</th>
                    <th style="width:20%;">Camp Special Requirements</th>
                    <th style="width:10%; text-align:center;">View / Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($district_requests as $req): ?>
                    <tr class="district-item-row" data-search="<?php echo htmlspecialchars(strtolower($req['item_type'] . ' ' . $req['camp_name'] . ' ' . ($req['officer_name'] ?? '') . ' ' . $req['status'] . ' ' . ($req['camp_special_needs'] ?? ''))); ?>">
                        <td>
                            <strong style="color:#0f172a; font-size:14px;"><?php echo htmlspecialchars($req['item_type']); ?></strong>
                        </td>
                        <td>
                            <span style="background:#f1f5f9; color:#0f172a; padding:3px 8px; border-radius:4px; font-weight:700; font-size:13px;">
                                <?php echo (int)$req['quantity']; ?>
                            </span>
                        </td>
                        <td>
                            <strong><?php echo htmlspecialchars($req['camp_name']); ?></strong><br>
                            <span style="color:#64748b; font-size:11px;">
                                Occ: <?php echo (int)$req['current_population']; ?> / Cap: <?php echo (int)$req['capacity']; ?>
                            </span>
                        </td>
                        <td>
                            <span style="color:#475569; font-size:13px;">
                                👮 <?php echo htmlspecialchars($req['officer_name'] ?? 'Officer'); ?>
                            </span>
                        </td>
                        <td>
                            <?php
                                $st = $req['status'];
                                $badge_style = 'background:#fef9c3; color:#854d0e;';
                                if ($st === 'Approved') $badge_style = 'background:#dcfce7; color:#166534;';
                                if ($st === 'Rejected') $badge_style = 'background:#fee2e2; color:#991b1b;';
                            ?>
                            <span style="<?php echo $badge_style; ?> padding:3px 8px; border-radius:4px; font-size:12px; font-weight:600;">
                                <?php echo htmlspecialchars($st); ?>
                            </span>
                        </td>
                        <td>
                            <?php if (!empty($req['camp_special_needs'])): ?>
                                <div style="background:#fef2f2; border:1px solid #fecaca; color:#b91c1c; padding:6px 10px; border-radius:6px; font-size:12px; font-weight:500; line-height:1.4; max-height:80px; overflow-y:auto;">
                                    <strong>⚠️ Special Requirements:</strong><br>
                                    <?php echo nl2br(htmlspecialchars($req['camp_special_needs'])); ?>
                                </div>
                            <?php else: ?>
                                <span style="color:#94a3b8; font-size:12px; font-style:italic;">None reported</span>
                            <?php endif; ?>
                        </td>
                        <td style="white-space:nowrap; text-align:center;">
                            <!-- View Option Button -->
                            <button 
                                type="button" 
                                class="btn-action view-btn"
                                onclick="openItemModal(<?php echo htmlspecialchars(json_encode($req)); ?>, <?php echo htmlspecialchars(json_encode($camp_families_map[(int)$req['camp_id']] ?? [])); ?>)"
                                title="View complete details of this requested item and associated camp special needs"
                            >
                                👁️ View
                            </button>

                            <?php if ($req['status'] === 'Pending'): ?>
                                <a
                                    href="actions.php?action=approve_req&id=<?php echo (int)$req['id']; ?>"
                                    class="btn-action approve"
                                    onclick="return confirm('Approve this supply request?');"
                                    title="Approve supply dispatch"
                                >
                                    ✓
                                </a>
                                <a
                                    href="actions.php?action=reject_req&id=<?php echo (int)$req['id']; ?>"
                                    class="btn-action reject"
                                    onclick="return confirm('Reject this supply request?');"
                                    title="Reject request"
                                >
                                    ✕
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p style="padding:15px; color:#64748b;">No supply requests found for <strong><?php echo htmlspecialchars($user_district); ?></strong>.</p>
    <?php endif; ?>
</div>

<!-- ==========================================================
     SECTION 2: PEOPLE (FAMILIES & SPECIAL REQUIREMENTS)
========================================================== -->
<div class="card" id="districtPeopleCard" style="margin-bottom:25px; width:100%;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; flex-wrap:wrap; gap:10px;">
        <div>
            <h2 style="margin:0; display:flex; align-items:center; gap:8px;">
                <span>👥 View Option: Camp People & Special Requirements</span>
            </h2>
            <p style="margin:4px 0 0 0; color:#64748b; font-size:13px;">
                Officer uploaded family intakes with full visibility into critical medical, infant, dietary, and accessibility details.
            </p>
        </div>
        <span style="background:#e0f2fe; color:#0369a1; padding:4px 12px; border-radius:12px; font-size:12px; font-weight:600;">
            <?php echo count($district_families); ?> Total Families
        </span>
    </div>

    <?php if (count($district_families) > 0): ?>
        <table class="data-table" id="districtPeopleTable">
            <thead>
                <tr>
                    <th style="width:18%;">Person / Family Head</th>
                    <th style="width:16%;">Camp Location</th>
                    <th style="width:12%;">Officer</th>
                    <th style="width:10%;">Members</th>
                    <th style="width:8%;">Infants</th>
                    <th style="width:26%;">Uploaded Special Requirement Details</th>
                    <th style="width:10%; text-align:center;">View Option</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($district_families as $fam): ?>
                    <?php 
                        $special = trim($fam['special_needs_details'] ?? '');
                        $has_special = ($special !== '' && strtolower($special) !== 'none');
                    ?>
                    <tr class="district-people-row" data-search="<?php echo htmlspecialchars(strtolower($fam['family_head_name'] . ' ' . $fam['camp_name'] . ' ' . ($fam['officer_name'] ?? '') . ' ' . $special)); ?>">
                        <td>
                            <strong style="color:#0f172a; font-size:14px;"><?php echo htmlspecialchars($fam['family_head_name']); ?></strong>
                            <div style="color:#64748b; font-size:11px;"><?php echo htmlspecialchars(date('M d, Y H:i', strtotime($fam['created_at']))); ?></div>
                        </td>
                        <td>
                            <strong><?php echo htmlspecialchars($fam['camp_name']); ?></strong><br>
                            <span style="color:#64748b; font-size:11px;"><?php echo htmlspecialchars($fam['district']); ?></span>
                        </td>
                        <td>
                            <span style="color:#475569; font-size:13px;">
                                👮 <?php echo htmlspecialchars($fam['officer_name'] ?? 'Officer'); ?>
                            </span>
                        </td>
                        <td>
                            <span style="background:#f1f5f9; color:#0f172a; padding:2px 8px; border-radius:4px; font-weight:600; font-size:13px;">
                                👥 <?php echo (int)$fam['members_count']; ?>
                            </span>
                        </td>
                        <td>
                            <?php if ((int)$fam['infants_count'] > 0): ?>
                                <span style="background:#fef3c7; color:#92400e; padding:3px 8px; border-radius:4px; font-weight:700; font-size:12px;">
                                    🍼 <?php echo (int)$fam['infants_count']; ?>
                                </span>
                            <?php else: ?>
                                <span style="color:#94a3b8; font-size:12px;">0</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($has_special): ?>
                                <div style="background:#fef2f2; border:1px solid #fecaca; color:#b91c1c; padding:6px 10px; border-radius:6px; font-size:13px; font-weight:600; line-height:1.4;">
                                    ⚠️ <?php echo htmlspecialchars($special); ?>
                                </div>
                            <?php else: ?>
                                <span style="color:#94a3b8; font-style:italic; font-size:12px;">None reported</span>
                            <?php endif; ?>
                        </td>
                        <td style="white-space:nowrap; text-align:center;">
                            <!-- View Option Button -->
                            <button 
                                type="button" 
                                class="btn-action view-btn"
                                onclick="openPersonModal(<?php echo htmlspecialchars(json_encode($fam)); ?>)"
                                title="View complete dossier for this person / family"
                            >
                                👁️ View Profile
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p style="padding:15px; color:#64748b;">No family intakes recorded for <strong><?php echo htmlspecialchars($user_district); ?></strong> yet.</p>
    <?php endif; ?>
</div>

<!-- ==========================================================
     DISTRICT MODAL (VIEW OPTION FOR ITEM & PEOPLE)
========================================================== -->
<div id="districtDetailModal" class="modal-overlay" style="display:none;" onclick="handleModalBackdropClick(event)">
    <div class="modal-content modal-animated" style="max-width:680px; width:90%; background:#fff; border-radius:12px; box-shadow:0 20px 25px -5px rgba(0,0,0,0.2), 0 10px 10px -5px rgba(0,0,0,0.1); padding:0; overflow:hidden;">
        <!-- Modal Header -->
        <div id="districtModalHeader" style="padding:16px 22px; background:#1e40af; color:#fff; display:flex; justify-content:space-between; align-items:center;">
            <h3 id="districtModalTitle" style="margin:0; font-size:17px; font-weight:700; color:#fff;">View Details</h3>
            <button type="button" onclick="closeDistrictModal()" style="background:rgba(255,255,255,0.2); border:none; color:#fff; font-size:18px; line-height:1; width:30px; height:30px; border-radius:50%; cursor:pointer; display:flex; align-items:center; justify-content:center;">✕</button>
        </div>
        <!-- Modal Body -->
        <div id="districtModalBody" style="padding:22px; max-height:75vh; overflow-y:auto;">
            <!-- Injected via JavaScript -->
        </div>
        <!-- Modal Footer -->
        <div id="districtModalFooter" style="padding:14px 22px; background:#f8fafc; border-top:1px solid #e2e8f0; display:flex; justify-content:flex-end; gap:10px; align-items:center;">
            <button type="button" onclick="closeDistrictModal()" style="padding:8px 16px; background:#64748b; color:#fff; border:none; border-radius:6px; font-size:13px; font-weight:600; cursor:pointer;">Close</button>
        </div>
    </div>
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

const currentUserId = <?php echo (int)$user_id; ?>;
const userDistrict = <?php echo json_encode($user_district); ?>;

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
        badge.innerText = "Modifying Camp #" + camp.id + ": " + camp.camp_name;
        badge.style.background = "#fef3c7";
        badge.style.color = "#92400e";
    }

    const resetBtn = document.getElementById("resetCampBtn");
    if (resetBtn) resetBtn.style.display = "inline-block";

    const updateOnlyBtn = document.getElementById("updateCampOnlyBtn");
    if (updateOnlyBtn) updateOnlyBtn.style.display = "inline-block";

    const deleteCampBtn = document.getElementById("deleteCampBtn");
    if (deleteCampBtn) deleteCampBtn.style.display = "inline-block";

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

    const deleteCampBtn = document.getElementById("deleteCampBtn");
    if (deleteCampBtn) deleteCampBtn.style.display = "none";

    if (currentMarker && campMap) {
        campMap.removeLayer(currentMarker);
        currentMarker = null;
    }
}

function deleteSelectedCamp() {
    const id = document.getElementById("camp_id").value;
    const name = document.getElementById("camp_name").value || "this camp";
    if (!id) {
        alert("Please select a camp to delete.");
        return;
    }
    if (confirm("Are you sure you want to delete camp location \"" + name + "\"? This will remove the map marker and associated records.")) {
        window.location.href = "actions.php?action=delete_camp&id=" + encodeURIComponent(id);
    }
}

function editCampFromTable(camp) {
    selectCampForUpdate(camp);
    const card = document.querySelector(".card");
    if (card) {
        card.scrollIntoView({ behavior: 'smooth', block: 'start' });
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
        const currentCampId = document.getElementById("camp_id").value;

        // Check if user clicked near an existing camp marker
        const matchedCamp = allLoadedCamps.find(c => {
            return Math.abs(parseFloat(c.latitude) - clickedLat) < 0.0005 &&
                   Math.abs(parseFloat(c.longitude) - clickedLng) < 0.0005;
        });

        if (matchedCamp) {
            selectCampForUpdate(matchedCamp);
        } else if (currentCampId) {
            // Modifying an existing camp: update coordinates on map click!
            document.getElementById("lat").value = clickedLat.toFixed(6);
            document.getElementById("lng").value = clickedLng.toFixed(6);

            const badge = document.getElementById("campModeBadge");
            if (badge) {
                badge.innerText = "📍 Coordinates Relocated for Camp #" + currentCampId;
                badge.style.background = "#fef08a";
                badge.style.color = "#854d0e";
            }

            if (currentMarker) {
                campMap.removeLayer(currentMarker);
            }
            currentMarker = L.marker([clickedLat, clickedLng]).addTo(campMap);
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

            const deleteCampBtn = document.getElementById("deleteCampBtn");
            if (deleteCampBtn) deleteCampBtn.style.display = "none";

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

                    const isMyCamp = (
                        camp.managed_by === currentUserId ||
                        (camp.district && camp.district.toLowerCase() === userDistrict.toLowerCase())
                    );

                    const popupDiv = document.createElement("div");
                    popupDiv.innerHTML = `
                        <div style="font-family:sans-serif; min-width:190px; padding:2px;">
                            <strong style="font-size:14px; color:#1e293b; display:block; margin-bottom:4px;">${escapeHtml(camp.camp_name)}</strong>
                            <div style="color:#64748b; font-size:12px; line-height:1.5;">
                                <strong>District:</strong> ${escapeHtml(camp.district)}<br>
                                <strong>Occupants:</strong> ${camp.current_population} / ${camp.capacity}<br>
                                <strong>Coords:</strong> ${lat.toFixed(5)}, ${lng.toFixed(5)}
                            </div>
                            <div style="margin-top:10px; display:flex; flex-direction:column; gap:6px;">
                                <button type="button" class="popup-modify-btn" style="padding:6px 10px; width:100%; background:#0284c7; color:white; border:none; border-radius:4px; cursor:pointer; font-weight:600; font-size:12px;">
                                    ✏️ Modify Camp Details
                                </button>
                                ${isMyCamp ? `
                                    <a href="actions.php?action=delete_camp&id=${camp.id}" onclick="return confirm('Are you sure you want to delete camp location \\'${escapeHtml(camp.camp_name)}\\'? This will remove the map marker.');" style="display:block; text-align:center; padding:5px 10px; background:#ef4444; color:white; border-radius:4px; text-decoration:none; font-weight:600; font-size:12px;">
                                        🗑️ Delete Location
                                    </a>
                                ` : ''}
                            </div>
                        </div>
                    `;

                    popupDiv.querySelector(".popup-modify-btn").addEventListener("click", function(ev) {
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
   DISTRICT ADMIN CONTROLS & MODALS
========================================================== */

<?php elseif ($role === 'District Admin'): ?>

let currentDistrictView = 'all';

function switchDistrictView(view) {
    currentDistrictView = view;
    const itemsCard = document.getElementById('districtItemsCard');
    const peopleCard = document.getElementById('districtPeopleCard');
    const btnAll = document.getElementById('btnViewAll');
    const btnItems = document.getElementById('btnViewItems');
    const btnPeople = document.getElementById('btnViewPeople');

    if (btnAll) btnAll.classList.toggle('active', view === 'all');
    if (btnItems) btnItems.classList.toggle('active', view === 'items');
    if (btnPeople) btnPeople.classList.toggle('active', view === 'people');

    if (itemsCard) {
        itemsCard.style.display = (view === 'all' || view === 'items') ? 'block' : 'none';
    }
    if (peopleCard) {
        peopleCard.style.display = (view === 'all' || view === 'people') ? 'block' : 'none';
    }
}

function filterDistrictRecords() {
    const searchInput = document.getElementById('districtSearchInput');
    const query = (searchInput ? searchInput.value : '').toLowerCase().trim();
    const itemRows = document.querySelectorAll('.district-item-row');
    const peopleRows = document.querySelectorAll('.district-people-row');

    itemRows.forEach(function(row) {
        const text = (row.getAttribute('data-search') || '').toLowerCase();
        row.style.display = (!query || text.indexOf(query) !== -1) ? '' : 'none';
    });

    peopleRows.forEach(function(row) {
        const text = (row.getAttribute('data-search') || '').toLowerCase();
        row.style.display = (!query || text.indexOf(query) !== -1) ? '' : 'none';
    });
}

function clearDistrictSearch() {
    const input = document.getElementById('districtSearchInput');
    if (input) {
        input.value = '';
        filterDistrictRecords();
        input.focus();
    }
}

function openItemModal(req, families) {
    const modal = document.getElementById('districtDetailModal');
    const title = document.getElementById('districtModalTitle');
    const body = document.getElementById('districtModalBody');
    const footer = document.getElementById('districtModalFooter');
    if (!modal || !title || !body) return;

    title.innerHTML = '📦 Relief Supply Item: ' + escapeHtml(req.item_type);

    let statusBadge = '<span style="background:#fef9c3; color:#854d0e; padding:3px 8px; border-radius:4px; font-size:12px; font-weight:600;">' + escapeHtml(req.status) + '</span>';
    if (req.status === 'Approved') {
        statusBadge = '<span style="background:#dcfce7; color:#166534; padding:3px 8px; border-radius:4px; font-size:12px; font-weight:600;">✓ Approved</span>';
    } else if (req.status === 'Rejected') {
        statusBadge = '<span style="background:#fee2e2; color:#991b1b; padding:3px 8px; border-radius:4px; font-size:12px; font-weight:600;">✕ Rejected</span>';
    }

    // Filter families with special needs in this camp
    const famsWithSpecial = (families || []).filter(function(f) {
        const sn = (f.special_needs_details || '').trim();
        return sn !== '' && sn.toLowerCase() !== 'none';
    });

    let specialNeedsHtml = '';
    if (famsWithSpecial.length > 0) {
        let listItems = famsWithSpecial.map(function(f) {
            return '<div style="background:#fff; border:1px solid #fecaca; border-radius:6px; padding:10px 14px; margin-bottom:8px;">' +
                '<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px;">' +
                    '<strong style="color:#0f172a; font-size:13px;">👤 ' + escapeHtml(f.family_head_name) + '</strong>' +
                    '<span style="font-size:11px; color:#64748b;">' +
                        parseInt(f.members_count) + ' members ' + (parseInt(f.infants_count) > 0 ? '&bull; 🍼 ' + parseInt(f.infants_count) + ' infants' : '') +
                    '</span>' +
                '</div>' +
                '<div style="color:#b91c1c; font-size:13px; font-weight:600;">' +
                    '⚠️ ' + escapeHtml(f.special_needs_details) +
                '</div>' +
            '</div>';
        }).join('');

        specialNeedsHtml = '<div style="margin-top:18px; background:#fef2f2; border:1px solid #fca5a5; border-radius:8px; padding:14px;">' +
            '<div style="font-weight:700; color:#991b1b; font-size:13px; margin-bottom:10px; display:flex; align-items:center; gap:6px;">' +
                '⚠️ Camp Officer Uploaded Special Requirements (' + famsWithSpecial.length + ' Cases in this Camp):' +
            '</div>' +
            listItems +
        '</div>';
    } else if (req.camp_special_needs && req.camp_special_needs.trim() !== '') {
        specialNeedsHtml = '<div style="margin-top:18px; background:#fef2f2; border:1px solid #fca5a5; border-radius:8px; padding:14px;">' +
            '<div style="font-weight:700; color:#991b1b; font-size:13px; margin-bottom:6px;">' +
                '⚠️ Camp Special Requirements:' +
            '</div>' +
            '<div style="color:#b91c1c; font-size:13px; font-weight:600;">' +
                escapeHtml(req.camp_special_needs) +
            '</div>' +
        '</div>';
    } else {
        specialNeedsHtml = '<div style="margin-top:18px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:14px; color:#64748b; font-size:13px;">' +
            '✓ No special medical, mobility, or infant requirements currently reported for this camp location.' +
        '</div>';
    }

    body.innerHTML = 
        '<div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:14px;">' +
            '<div style="background:#f8fafc; padding:12px; border-radius:8px; border:1px solid #e2e8f0;">' +
                '<div style="font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase;">Requested Item</div>' +
                '<div style="font-size:16px; font-weight:700; color:#0f172a; margin-top:2px;">' + escapeHtml(req.item_type) + '</div>' +
            '</div>' +
            '<div style="background:#f8fafc; padding:12px; border-radius:8px; border:1px solid #e2e8f0;">' +
                '<div style="font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase;">Quantity & Status</div>' +
                '<div style="display:flex; align-items:center; gap:8px; margin-top:4px;">' +
                    '<span style="font-size:18px; font-weight:700; color:#0f172a;">' + parseInt(req.quantity) + ' units</span>' +
                    statusBadge +
                '</div>' +
            '</div>' +
        '</div>' +

        '<div style="background:#f8fafc; padding:12px; border-radius:8px; border:1px solid #e2e8f0; margin-bottom:14px;">' +
            '<div style="font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase;">Camp & Logistics Context</div>' +
            '<div style="margin-top:6px; font-size:13px; color:#334155; line-height:1.6;">' +
                '<div>📍 <strong>Camp Name:</strong> ' + escapeHtml(req.camp_name) + ' (' + escapeHtml(req.district) + ')</div>' +
                '<div>👥 <strong>Occupancy:</strong> ' + parseInt(req.current_population) + ' people / Capacity: ' + parseInt(req.capacity) + '</div>' +
                '<div>👮 <strong>Requested By Officer:</strong> ' + escapeHtml(req.officer_name || 'Camp Officer') + '</div>' +
                '<div>📅 <strong>Submitted:</strong> ' + escapeHtml(req.created_at || 'Recently') + '</div>' +
            '</div>' +
        '</div>' +

        specialNeedsHtml;

    let actionButtons = '';
    if (req.status === 'Pending') {
        actionButtons = 
            '<a href="actions.php?action=approve_req&id=' + parseInt(req.id) + '" class="btn-action approve" onclick="return confirm(\'Approve this supply request?\')">' +
                '✓ Approve Request' +
            '</a>' +
            '<a href="actions.php?action=reject_req&id=' + parseInt(req.id) + '" class="btn-action reject" onclick="return confirm(\'Reject this supply request?\')">' +
                '✕ Reject Request' +
            '</a>';
    }
    footer.innerHTML = 
        actionButtons +
        '<button type="button" onclick="closeDistrictModal()" style="padding:8px 16px; background:#64748b; color:#fff; border:none; border-radius:6px; font-size:13px; font-weight:600; cursor:pointer;">' +
            'Close' +
        '</button>';

    modal.style.display = 'flex';
}

function openPersonModal(fam) {
    const modal = document.getElementById('districtDetailModal');
    const title = document.getElementById('districtModalTitle');
    const body = document.getElementById('districtModalBody');
    const footer = document.getElementById('districtModalFooter');
    if (!modal || !title || !body) return;

    title.innerHTML = '👥 Person / Family Dossier: ' + escapeHtml(fam.family_head_name);

    const special = (fam.special_needs_details || '').trim();
    const hasSpecial = special !== '' && special.toLowerCase() !== 'none';

    let specialBox = '';
    if (hasSpecial) {
        specialBox = 
            '<div style="margin-top:16px; background:#fff1f2; border:2px solid #f43f5e; border-radius:8px; padding:16px;">' +
                '<div style="font-weight:700; color:#be123c; font-size:14px; margin-bottom:6px; display:flex; align-items:center; gap:6px;">' +
                    '⚠️ Camp Officer Uploaded Special Requirement Details:' +
                '</div>' +
                '<div style="background:#fff; border:1px solid #fecdd3; border-radius:6px; padding:12px 14px; font-size:14px; color:#881337; font-weight:600; line-height:1.5;">' +
                    escapeHtml(special) +
                '</div>' +
                '<p style="margin:8px 0 0 0; font-size:12px; color:#9f1239;">' +
                    'High Priority: Verify emergency relief, dietary rations, or mobility assistance has been coordinated with the camp officer.' +
                '</p>' +
            '</div>';
    } else {
        specialBox = 
            '<div style="margin-top:16px; background:#f0fdf4; border:1px solid #86efac; border-radius:8px; padding:14px; color:#15803d; font-size:13px;">' +
                '✓ No special medical, mobility, or dietary needs reported for this family.' +
            '</div>';
    }

    body.innerHTML = 
        '<div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:14px;">' +
            '<div style="background:#f8fafc; padding:12px; border-radius:8px; border:1px solid #e2e8f0;">' +
                '<div style="font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase;">Family Head / Primary Contact</div>' +
                '<div style="font-size:16px; font-weight:700; color:#0f172a; margin-top:2px;">' + escapeHtml(fam.family_head_name) + '</div>' +
            '</div>' +
            '<div style="background:#f8fafc; padding:12px; border-radius:8px; border:1px solid #e2e8f0;">' +
                '<div style="font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase;">Family Members Composition</div>' +
                '<div style="margin-top:4px; font-size:14px; font-weight:600; color:#0f172a; display:flex; gap:12px; align-items:center;">' +
                    '<span>👥 Total: ' + parseInt(fam.members_count) + '</span>' +
                    '<span style="background:#fef3c7; color:#92400e; padding:2px 8px; border-radius:4px; font-size:12px;">🍼 Infants: ' + parseInt(fam.infants_count) + '</span>' +
                '</div>' +
            '</div>' +
        '</div>' +

        '<div style="background:#f8fafc; padding:12px; border-radius:8px; border:1px solid #e2e8f0; margin-bottom:14px;">' +
            '<div style="font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase;">Camp & Officer Reference</div>' +
            '<div style="margin-top:6px; font-size:13px; color:#334155; line-height:1.6;">' +
                '<div>📍 <strong>Camp:</strong> ' + escapeHtml(fam.camp_name) + ' (' + escapeHtml(fam.district) + ')</div>' +
                '<div>👮 <strong>Intake Officer:</strong> ' + escapeHtml(fam.officer_name || 'Camp Officer') + '</div>' +
                '<div>📅 <strong>Registration Date:</strong> ' + escapeHtml(fam.created_at || 'Recently') + '</div>' +
            '</div>' +
        '</div>' +

        specialBox;

    footer.innerHTML = 
        '<button type="button" onclick="closeDistrictModal()" style="padding:8px 16px; background:#64748b; color:#fff; border:none; border-radius:6px; font-size:13px; font-weight:600; cursor:pointer;">' +
            'Close' +
        '</button>';

    modal.style.display = 'flex';
}

function closeDistrictModal() {
    const modal = document.getElementById('districtDetailModal');
    if (modal) modal.style.display = 'none';
}

function handleModalBackdropClick(event) {
    if (event.target && event.target.id === 'districtDetailModal') {
        closeDistrictModal();
    }
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeDistrictModal();
    }
});


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