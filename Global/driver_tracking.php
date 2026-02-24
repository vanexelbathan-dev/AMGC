<?php
require_once '../config/database.php';
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

// Get filter parameters
$driver_name = isset($_GET['driverName']) ? $_GET['driverName'] : '';
$location = isset($_GET['location']) ? $_GET['location'] : '';
$trip_ticket = isset($_GET['tripTicket']) ? $_GET['tripTicket'] : '';
$status = isset($_GET['status']) ? $_GET['status'] : '';

// Handle AJAX request for tracking data
if (isset($_GET['ajax']) && $_GET['ajax'] == 1) {
    header('Content-Type: application/json');
    
    $response = [
        'success' => true,
        'drivers' => [],
        'trips' => [],
        'stats' => [
            'totalDrivers' => 0,
            'activeDrivers' => 0,
            'completedTrips' => 0
        ]
    ];

    // Get today's date
    $today = date('Y-m-d');
    
    // ============ GET DRIVERS WITH LOCATION DATA ============
    $drivers_sql = "SELECT 
                        d.driver_id as id,
                        d.driver_name as name,
                        d.contact_number as phone,
                        d.vehicle_type,
                        d.vehicle_plate_number as vehicle_id,
                        d.status as driver_status,
                        d.license_number,
                        d.license_expiry,
                        b.branch_name as branch,
                        b.city,
                        b.branch_code,
                        dt.latitude,
                        dt.longitude,
                        dt.location_timestamp as last_update,
                        dt.speed_kmh,
                        tt.trip_id as current_trip_id,
                        tt.trip_number as current_trip,
                        tt.trip_status,
                        tt.trip_date,
                        tt.start_time,
                        tt.end_time,
                        tt.total_stops,
                        tt.total_delivered,
                        tt.total_failed
                    FROM drivers d
                    LEFT JOIN branches b ON d.branch_id = b.branch_id
                    LEFT JOIN (
                        SELECT dt1.*
                        FROM driver_tracking dt1
                        INNER JOIN (
                            SELECT driver_id, MAX(location_timestamp) as max_timestamp
                            FROM driver_tracking
                            GROUP BY driver_id
                        ) dt2 ON dt1.driver_id = dt2.driver_id AND dt1.location_timestamp = dt2.max_timestamp
                    ) dt ON d.driver_id = dt.driver_id
                    LEFT JOIN trip_tickets tt ON d.driver_id = tt.driver_id 
                        AND tt.trip_date = ? 
                        AND tt.trip_status IN ('in-progress', 'planned')
                    WHERE 1=1";
    
    $params = [$today];
    $types = "s";
    
    // Driver name filter
    if (!empty($driver_name)) {
        $drivers_sql .= " AND d.driver_name LIKE ?";
        $params[] = "%$driver_name%";
        $types .= "s";
    }
    
    // Location filter
    if (!empty($location)) {
        if ($location == 'warehouse') {
            $drivers_sql .= " AND b.branch_id = 1";
        } elseif ($location == 'branch1') {
            $drivers_sql .= " AND b.branch_id = 1";
        } elseif ($location == 'branch2') {
            $drivers_sql .= " AND b.branch_id = 2";
        } elseif ($location == 'in_transit') {
            $drivers_sql .= " AND tt.trip_status = 'in-progress'";
        }
    }
    
    // Trip ticket filter
    if (!empty($trip_ticket)) {
        $drivers_sql .= " AND (tt.trip_number LIKE ? OR tt.trip_id = ?)";
        $params[] = "%$trip_ticket%";
        $params[] = $trip_ticket;
        $types .= "si";
    }
    
    // Status filter
    if (!empty($status)) {
        if ($status == 'active') {
            $drivers_sql .= " AND tt.trip_status = 'in-progress'";
        } elseif ($status == 'idle') {
            $drivers_sql .= " AND tt.trip_id IS NULL AND d.status = 'active'";
        } elseif ($status == 'off_duty') {
            $drivers_sql .= " AND d.status IN ('inactive', 'on-leave')";
        }
    }
    
    $drivers_sql .= " GROUP BY d.driver_id ORDER BY d.driver_name ASC";
    
    $stmt = $conn->prepare($drivers_sql);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $drivers_result = $stmt->get_result();
    
    $total_drivers = 0;
    $active_drivers = 0;
    $total_drivers_count = 0;
    
    while ($row = $drivers_result->fetch_assoc()) {
        $total_drivers_count++;
        $total_drivers++;
        
        // Determine driver status for tracking
        $tracking_status = 'off_duty';
        $status_badge = 'bg-secondary';
        
        if ($row['driver_status'] == 'active') {
            if ($row['current_trip_id']) {
                $tracking_status = 'active';
                $status_badge = 'bg-success';
                $active_drivers++;
            } else {
                $tracking_status = 'idle';
                $status_badge = 'bg-warning';
            }
        } elseif ($row['driver_status'] == 'on-leave') {
            $tracking_status = 'off_duty';
            $status_badge = 'bg-info';
        } elseif ($row['driver_status'] == 'inactive') {
            $tracking_status = 'off_duty';
            $status_badge = 'bg-secondary';
        }
        
        // Format current location
        $current_location = 'Location unavailable';
        if ($row['latitude'] && $row['longitude']) {
            $current_location = $row['city'] ?? 'In transit';
        } elseif ($row['branch']) {
            $current_location = $row['branch'];
        } else {
            $current_location = 'Unknown';
        }
        
        // Get destination from deliveries
        $destination = 'N/A';
        if ($row['current_trip_id']) {
            $dest_sql = "SELECT 
                            c.city,
                            c.customer_name
                        FROM deliveries del
                        JOIN customers c ON del.customer_id = c.customer_id
                        WHERE del.trip_id = ?
                        ORDER BY del.stop_sequence ASC
                        LIMIT 1";
            $dest_stmt = $conn->prepare($dest_sql);
            $dest_stmt->bind_param("i", $row['current_trip_id']);
            $dest_stmt->execute();
            $dest_result = $dest_stmt->get_result();
            if ($dest_row = $dest_result->fetch_assoc()) {
                $destination = $dest_row['city'] ?? $dest_row['customer_name'];
            }
        }
        
        $response['drivers'][] = [
            'id' => $row['id'],
            'name' => $row['name'],
            'phone' => $row['phone'] ?? 'N/A',
            'vehicle_id' => $row['vehicle_id'] ?? $row['vehicle_type'] ?? 'N/A',
            'branch' => $row['branch'] ?? 'Unassigned',
            'branch_code' => $row['branch_code'] ?? '',
            'current_location' => $current_location,
            'latitude' => $row['latitude'] ?? 14.5995,
            'longitude' => $row['longitude'] ?? 120.9842,
            'current_trip' => $row['current_trip'] ?? null,
            'current_trip_id' => $row['current_trip_id'] ?? null,
            'destination' => $destination,
            'status' => $tracking_status,
            'status_badge' => $status_badge,
            'last_update' => $row['last_update'] ?? date('Y-m-d H:i:s'),
            'speed_kmh' => $row['speed_kmh'] ?? 0,
            'trips_completed_today' => 0
        ];
    }
    
    // Get trips completed today for each driver
    $trips_today_sql = "SELECT 
                            driver_id,
                            COUNT(*) as trips_completed
                        FROM trip_tickets
                        WHERE trip_date = ? 
                            AND trip_status = 'completed'
                        GROUP BY driver_id";
    $trips_today_stmt = $conn->prepare($trips_today_sql);
    $trips_today_stmt->bind_param("s", $today);
    $trips_today_stmt->execute();
    $trips_today_result = $trips_today_stmt->get_result();
    
    $trips_completed_today = [];
    while ($row = $trips_today_result->fetch_assoc()) {
        $trips_completed_today[$row['driver_id']] = $row['trips_completed'];
    }
    
    // Update trips completed today for drivers
    foreach ($response['drivers'] as &$driver) {
        $driver['trips_completed_today'] = $trips_completed_today[$driver['id']] ?? 0;
    }
    
    // ============ GET ACTIVE TRIPS ============
    $trips_sql = "SELECT 
                    tt.trip_id,
                    tt.trip_number,
                    tt.trip_date,
                    tt.trip_status,
                    tt.start_time,
                    tt.end_time,
                    tt.total_stops,
                    tt.total_delivered,
                    tt.total_failed,
                    d.driver_id,
                    d.driver_name,
                    d.vehicle_plate_number as vehicle_id,
                    b.branch_name as origin,
                    COUNT(DISTINCT del.delivery_id) as delivery_count,
                    COUNT(DISTINCT soi.so_item_id) as item_count
                FROM trip_tickets tt
                JOIN drivers d ON tt.driver_id = d.driver_id
                JOIN branches b ON tt.branch_id = b.branch_id
                LEFT JOIN deliveries del ON tt.trip_id = del.trip_id
                LEFT JOIN sales_orders so ON del.so_id = so.so_id
                LEFT JOIN sales_order_items soi ON so.so_id = soi.so_id
                WHERE tt.trip_date = ?
                GROUP BY tt.trip_id
                ORDER BY tt.start_time DESC";
    
    $trips_stmt = $conn->prepare($trips_sql);
    $trips_stmt->bind_param("s", $today);
    $trips_stmt->execute();
    $trips_result = $trips_stmt->get_result();
    
    $completed_trips_count = 0;
    
    while ($row = $trips_result->fetch_assoc()) {
        if ($row['trip_status'] == 'completed') {
            $completed_trips_count++;
        }
        
        // Get destination for this trip
        $dest_sql = "SELECT 
                        c.city,
                        c.customer_name
                    FROM deliveries del
                    JOIN customers c ON del.customer_id = c.customer_id
                    WHERE del.trip_id = ?
                    ORDER BY del.stop_sequence ASC
                    LIMIT 1";
        $dest_stmt = $conn->prepare($dest_sql);
        $dest_stmt->bind_param("i", $row['trip_id']);
        $dest_stmt->execute();
        $dest_result = $dest_stmt->get_result();
        $destination = 'N/A';
        if ($dest_row = $dest_result->fetch_assoc()) {
            $destination = $dest_row['city'] ?? $dest_row['customer_name'];
        }
        
        $response['trips'][] = [
            'trip_id' => $row['trip_id'],
            'trip_number' => $row['trip_number'],
            'driver_name' => $row['driver_name'],
            'driver_id' => $row['driver_id'],
            'origin' => $row['origin'],
            'destination' => $destination,
            'item_count' => $row['item_count'] ?? 0,
            'departure' => $row['start_time'] ?? $row['trip_date'],
            'eta' => $row['trip_date'] . ' 17:00:00',
            'status' => $row['trip_status'],
            'total_stops' => $row['total_stops'] ?? $row['delivery_count'],
            'total_delivered' => $row['total_delivered'] ?? 0,
            'total_failed' => $row['total_failed'] ?? 0
        ];
    }
    
    $response['stats'] = [
        'totalDrivers' => $total_drivers_count,
        'activeDrivers' => $active_drivers,
        'completedTrips' => $completed_trips_count
    ];
    
    echo json_encode($response);
    exit;
}

// Handle AJAX request for driver details
if (isset($_GET['ajax_details']) && isset($_GET['id'])) {
    header('Content-Type: application/json');
    
    $driver_id = intval($_GET['id']);
    $today = date('Y-m-d');
    
    $sql = "SELECT 
                d.driver_id as id,
                d.driver_name as name,
                d.license_number as license_no,
                d.license_expiry,
                d.contact_number as phone,
                d.vehicle_type,
                d.vehicle_plate_number as vehicle_id,
                d.status,
                d.branch_id,
                d.created_at as joined_date,
                b.branch_name as branch,
                b.address as branch_address,
                b.city,
                dt.latitude,
                dt.longitude,
                dt.location_timestamp as last_location_update,
                tt.trip_id as current_trip_id,
                tt.trip_number as current_trip_number,
                tt.trip_status,
                tt.start_time,
                tt.end_time
            FROM drivers d
            LEFT JOIN branches b ON d.branch_id = b.branch_id
            LEFT JOIN (
                SELECT dt1.*
                FROM driver_tracking dt1
                INNER JOIN (
                    SELECT driver_id, MAX(location_timestamp) as max_timestamp
                    FROM driver_tracking
                    GROUP BY driver_id
                ) dt2 ON dt1.driver_id = dt2.driver_id AND dt1.location_timestamp = dt2.max_timestamp
            ) dt ON d.driver_id = dt.driver_id
            LEFT JOIN trip_tickets tt ON d.driver_id = tt.driver_id 
                AND tt.trip_date = ? 
                AND tt.trip_status = 'in-progress'
            WHERE d.driver_id = ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("si", $today, $driver_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        // Get trips completed today
        $trips_sql = "SELECT COUNT(*) as trips_completed
                     FROM trip_tickets
                     WHERE driver_id = ? AND trip_date = ? AND trip_status = 'completed'";
        $trips_stmt = $conn->prepare($trips_sql);
        $trips_stmt->bind_param("is", $driver_id, $today);
        $trips_stmt->execute();
        $trips_result = $trips_stmt->get_result();
        $trips_row = $trips_result->fetch_assoc();
        
        // Get current location text
        $current_location = 'Location unavailable';
        if ($row['latitude'] && $row['longitude']) {
            $current_location = sprintf('%.6f, %.6f', $row['latitude'], $row['longitude']);
        } elseif ($row['branch']) {
            $current_location = $row['branch'] . ', ' . ($row['city'] ?? '');
        }
        
        // Determine status display
        $status_display = $row['status'];
        $status_badge = 'bg-secondary';
        
        if ($row['status'] == 'active') {
            if ($row['current_trip_id']) {
                $status_display = 'active';
                $status_badge = 'bg-success';
            } else {
                $status_display = 'idle';
                $status_badge = 'bg-warning';
            }
        } elseif ($row['status'] == 'on-leave') {
            $status_display = 'off_duty';
            $status_badge = 'bg-info';
        } elseif ($row['status'] == 'inactive') {
            $status_display = 'off_duty';
            $status_badge = 'bg-secondary';
        }
        
        $response = [
            'success' => true,
            'driver' => [
                'id' => $row['id'],
                'name' => $row['name'],
                'license_no' => $row['license_no'],
                'license_expiry' => date('F d, Y', strtotime($row['license_expiry'])),
                'phone' => $row['phone'] ?? 'N/A',
                'vehicle_id' => $row['vehicle_id'] ?? $row['vehicle_type'] ?? 'N/A',
                'vehicle_type' => $row['vehicle_type'] ?? 'N/A',
                'branch' => $row['branch'] ?? 'Unassigned',
                'branch_address' => $row['branch_address'] ?? 'N/A',
                'current_location' => $current_location,
                'latitude' => $row['latitude'],
                'longitude' => $row['longitude'],
                'current_trip' => $row['current_trip_number'] ?? 'None',
                'current_trip_id' => $row['current_trip_id'] ?? null,
                'status' => $status_display,
                'status_badge' => $status_badge,
                'trips_completed_today' => $trips_row['trips_completed'] ?? 0,
                'last_location_update' => $row['last_location_update'] ? date('h:i A', strtotime($row['last_location_update'])) : 'N/A'
            ]
        ];
    } else {
        $response = ['success' => false, 'message' => 'Driver not found'];
    }
    
    echo json_encode($response);
    exit;
}

// Handle AJAX request for trip details
if (isset($_GET['ajax_trip']) && isset($_GET['id'])) {
    header('Content-Type: application/json');
    
    $trip_id = intval($_GET['id']);
    
    $sql = "SELECT 
                tt.*,
                d.driver_name,
                d.vehicle_plate_number,
                b.branch_name as origin_branch,
                b.address as origin_address,
                COUNT(DISTINCT del.delivery_id) as total_deliveries,
                COUNT(DISTINCT soi.so_item_id) as total_items,
                SUM(soi.quantity_ordered * soi.unit_price) as total_value
            FROM trip_tickets tt
            JOIN drivers d ON tt.driver_id = d.driver_id
            JOIN branches b ON tt.branch_id = b.branch_id
            LEFT JOIN deliveries del ON tt.trip_id = del.trip_id
            LEFT JOIN sales_orders so ON del.so_id = so.so_id
            LEFT JOIN sales_order_items soi ON so.so_id = soi.so_id
            WHERE tt.trip_id = ?
            GROUP BY tt.trip_id";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $trip_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        // Get delivery stops
        $deliveries_sql = "SELECT 
                            del.*,
                            c.customer_name,
                            c.address,
                            c.city,
                            c.contact_person,
                            c.phone_number,
                            so.so_number
                          FROM deliveries del
                          JOIN customers c ON del.customer_id = c.customer_id
                          JOIN sales_orders so ON del.so_id = so.so_id
                          WHERE del.trip_id = ?
                          ORDER BY del.stop_sequence ASC";
        $deliveries_stmt = $conn->prepare($deliveries_sql);
        $deliveries_stmt->bind_param("i", $trip_id);
        $deliveries_stmt->execute();
        $deliveries_result = $deliveries_stmt->get_result();
        
        $deliveries = [];
        while ($del = $deliveries_result->fetch_assoc()) {
            $deliveries[] = [
                'stop_sequence' => $del['stop_sequence'],
                'customer_name' => $del['customer_name'],
                'address' => $del['address'] . ', ' . $del['city'],
                'contact' => $del['contact_person'],
                'phone' => $del['phone_number'],
                'so_number' => $del['so_number'],
                'delivery_status' => $del['delivery_status'],
                'delivery_date' => $del['delivery_date'],
                'signed_by' => $del['signed_by'],
                'remarks' => $del['remarks']
            ];
        }
        
        $response = [
            'success' => true,
            'trip' => [
                'trip_id' => $row['trip_id'],
                'trip_number' => $row['trip_number'],
                'driver_name' => $row['driver_name'],
                'vehicle' => $row['vehicle_plate_number'],
                'origin' => $row['origin_branch'],
                'origin_address' => $row['origin_address'],
                'departure' => $row['start_time'] ? date('M d, Y h:i A', strtotime($row['start_time'])) : 'Not started',
                'eta' => $row['trip_date'] ? date('M d, Y', strtotime($row['trip_date'])) . ' 05:00 PM' : 'N/A',
                'actual_arrival' => $row['end_time'] ? date('M d, Y h:i A', strtotime($row['end_time'])) : null,
                'status' => $row['trip_status'],
                'total_stops' => $row['total_stops'] ?? count($deliveries),
                'total_delivered' => $row['total_delivered'] ?? 0,
                'total_failed' => $row['total_failed'] ?? 0,
                'total_items' => $row['total_items'] ?? 0,
                'total_value' => $row['total_value'] ?? 0,
                'deliveries' => $deliveries,
                'remarks' => $row['remarks'] ?? 'No remarks'
            ]
        ];
    } else {
        $response = ['success' => false, 'message' => 'Trip not found'];
    }
    
    echo json_encode($response);
    exit;
}

// Get branches for filter dropdown
$branches_sql = "SELECT branch_id, branch_name FROM branches WHERE status = 'active' ORDER BY branch_name";
$branches_result = $conn->query($branches_sql);
$branches = [];
while ($row = $branches_result->fetch_assoc()) {
    $branches[] = $row;
}

// Get user info from session
$user_name = $_SESSION['user_name'] ?? 'Quality Control';
$user_role = $_SESSION['user_role'] ?? 'QC Officer';
$user_initials = '';
if (!empty($user_name)) {
    $name_parts = explode(' ', $user_name);
    foreach ($name_parts as $part) {
        if (!empty($part)) {
            $user_initials .= strtoupper(substr($part, 0, 1));
        }
    }
}
if (empty($user_initials)) {
    $user_initials = 'AD';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Global - Driver Tracking</title>
    <link rel="icon" type="image/png" href="../Pictures/favicon-96x96.png" sizes="96x96" />
    <link rel="icon" type="image/svg+xml" href="../Pictures/favicon.svg" />
    <link rel="shortcut icon" href="../Pictures/favicon.ico" />
    <link rel="apple-touch-icon" sizes="180x180" href="../Pictures/apple-touch-icon.png" />
    <link rel="manifest" href="../Pictures/site.webmanifest" />
    <link rel="stylesheet" href="../css/global.css">
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <!-- Leaflet CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.min.css" />
    <style>
          /* Mobile responsive adjustments ONLY */
        @media (max-width: 768px) {
            .stat-card {
                padding: 12px;
                min-height: 85px;
                margin-bottom: 8px;
            }
            .stat-icon {
                font-size: 2rem;
                margin-right: 12px;
            }
            .stat-value {
                font-size: 1.5rem;
            }
            .stat-label {
                font-size: 0.8rem;
            }
            .col-md-3 {
                width: 50%;
                padding-left: 8px;
                padding-right: 8px;
            }
            .row.g-3 {
                margin-left: -8px;
                margin-right: -8px;
            }
            .mb-3 {
                margin-bottom: 8px !important;
            }
        }
        @media (max-width: 576px) {
            .stat-card {
                min-height: 80px;
                padding: 10px;
            }
            .stat-icon {
                font-size: 1.8rem;
                margin-right: 10px;
            }
            .stat-value {
                font-size: 1.3rem;
            }
            .stat-label {
                font-size: 0.75rem;
            }
            .col-md-3 {
                width: 50%;
                padding-left: 6px;
                padding-right: 6px;
            }
            .row.g-3 {
                margin-left: -6px;
                margin-right: -6px;
            }
        }
    </style>
</head>
<body>
    <!-- MAIN APPLICATION -->
    <div id="appPage">
        <!-- Sidebar -->
        <div class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <h3>
                    <button class="desktop-toggle-btn" id="desktopToggleBtn">
                        <i class="bi bi-list" id="toggleIcon"></i>
                    </button>
                    <img src="../Pictures/amgc3DLogo.png" alt="Logo" class="logo-icon"> 
                    <span class="nav-text">Global</span>
                </h3>
            </div>
            
            <div class="sidebar-menu">
                <ul class="nav flex-column">
                    <li class="nav-item">
                        <a class="nav-link" href="sales_reports.php">
                            <i class="bi bi-graph-up"></i>
                            <span class="nav-text">Sales Reports</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="branch_records.php">
                            <i class="bi bi-file-text"></i>
                            <span class="nav-text">Branch Records</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="all_items.php">
                            <i class="bi bi-box"></i>
                            <span class="nav-text">All Items</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="drivers.php">
                            <i class="bi bi-person-badge"></i>
                            <span class="nav-text">User Management</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="trip_tickets.php">
                            <i class="bi bi-ticket-perforated"></i>
                            <span class="nav-text">Trip Tickets</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="driver_tracking.php">
                            <i class="bi bi-geo-alt"></i>
                            <span class="nav-text">Driver Tracking</span>
                        </a>
                    </li>
                </ul>
            </div>
            <!-- User Profile Section at the bottom of sidebar -->
            <div class="sidebar-footer">
                <div class="user-profile-sidebar">
                    <div class="user-avatar-sidebar"><?php echo $user_initials; ?></div>
                    <div class="user-details-sidebar">
                        <span class="user-name-sidebar"><?php echo htmlspecialchars($user_name); ?></span>
                        <span class="user-role-sidebar"><?php echo htmlspecialchars($user_role); ?></span>
                    </div>
                </div>
                
                <button class="logout-btn-sidebar" onclick="logout()">
                    <i class="bi bi-box-arrow-right"></i>
                    <span class="logout-text">Logout</span>
                </button>
            </div>
        </div>

        <!-- Main Content -->
        <div class="main-content" id="mainContent">
            <!-- DRIVER TRACKING PAGE -->
            <div id="trackingContent" class="page-content active">
                <div class="navbar-top">
                    <button class="mobile-toggle-btn" id="mobileToggleBtn">
                        <i class="bi bi-list"></i>
                    </button>

                    <div class="page-title">
                        <h2>Driver Tracking</h2>
                        <p>Track all drivers' locations, routes, and trip tickets in real-time</p>
                    </div>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-md-4">
                        <div class="stat-card total">
                            <div class="stat-value" id="totalDrivers">0</div>
                            <div class="stat-label">Total Drivers</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="stat-card sales">
                            <div class="stat-value" id="activeDrivers">0</div>
                            <div class="stat-label">Active On Delivery</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="stat-card complete">
                            <div class="stat-value" id="completedTrips">0</div>
                            <div class="stat-label">Completed Today</div>
                        </div>
                    </div>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-12">
                        <div class="form-card">
                            <div class="d-flex justify-content-between align-items-center">
                                <h5 class="mb-0">Filter Drivers</h5>
                            </div>
                            <div class="row mt-3">
                                <div class="col-md-3">
                                    <label class="form-label">Driver Name</label>
                                    <input type="text" class="form-control" id="driverNameFilter" onchange="loadTracking()" placeholder="Filter by name">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Location</label>
                                    <select class="form-select" id="locationFilter" onchange="loadTracking()">
                                        <option value="">All Locations</option>
                                        <?php foreach ($branches as $branch): ?>
                                            <option value="<?php echo $branch['branch_id'] == 1 ? 'branch1' : 'branch2'; ?>">
                                                <?php echo htmlspecialchars($branch['branch_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                        <option value="in_transit">In Transit</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Trip Ticket</label>
                                    <input type="text" class="form-control" id="tripTicketFilter" onchange="loadTracking()" placeholder="Filter by trip #">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Status</label>
                                    <select class="form-select" id="statusFilter" onchange="loadTracking()">
                                        <option value="">All Status</option>
                                        <option value="active">Active</option>
                                        <option value="idle">Idle</option>
                                        <option value="off_duty">Off Duty</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-12">
                        <div class="data-table">
                            <div class="table-header">
                                <h5><i class="bi bi-map"></i> Live Driver Map</h5>
                            </div>
                            <div id="driverMap" style="width: 100%; height: 500px; border-radius: 8px; overflow: hidden;"></div>
                        </div>
                    </div>
                </div>

                <div class="data-table">
                    <div class="table-header">
                        <h5>Live Driver Locations</h5>
                    </div>
                    <div class="table-responsive">
                        <table class="table custom-table">
                            <thead>
                                <tr>
                                    <th class="id-column">Driver ID</th>
                                    <th>Name</th>
                                    <th>Current Location</th>
                                    <th>Current Trip</th>
                                    <th>Destination</th>
                                    <th>Status</th>
                                    <th>Last Update</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="driversTable">
                                <tr>
                                    <td colspan="8" class="text-center py-4">
                                        <div class="spinner-border text-primary" role="status">
                                            <span class="visually-hidden">Loading...</span>
                                        </div>
                                        <p class="mt-2">Loading driver data...</p>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="row g-3 mt-4">
                    <div class="col-12">
                        <div class="data-table">
                            <div class="table-header">
                                <h5>Today's Trips</h5>
                            </div>
                            <div class="table-responsive">
                                <table class="table custom-table">
                                    <thead>
                                        <tr>
                                            <th>Trip #</th>
                                            <th>Driver</th>
                                            <th>Origin</th>
                                            <th>Destination</th>
                                            <th>Items</th>
                                            <th>Departure</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody id="tripsTable">
                                        <tr>
                                            <td colspan="8" class="text-center py-4">
                                                <div class="spinner-border text-primary" role="status">
                                                    <span class="visually-hidden">Loading...</span>
                                                </div>
                                                <p class="mt-2">Loading trip data...</p>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Driver Details Modal -->
    <div class="modal fade" id="driverModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Driver Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="driverDetails">
                    <!-- Details will be populated here -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeDriverModal()">Close</button>
                    <button type="button" class="btn btn-warning" id="focusOnMapBtn" onclick="focusOnMapFromModal()" style="display: none;">View on Map</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Trip Details Modal -->
    <div class="modal fade" id="tripModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Trip Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="tripDetails">
                    <!-- Details will be populated here -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeTripModal()">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Leaflet JS -->
    <script src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.min.js"></script>
    <!-- jQuery (needed for Bootstrap modals) -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // ============== GLOBAL VARIABLES ==============
        let map;
        let markers = {};
        let currentDriverId = null;
        
        // ============== MODAL FUNCTIONS ==============
        function openDriverModal() {
            $('#driverModal').modal('show');
        }
        
        function closeDriverModal() {
            $('#driverModal').modal('hide');
        }
        
        function openTripModal() {
            $('#tripModal').modal('show');
        }
        
        function closeTripModal() {
            $('#tripModal').modal('hide');
        }
        
        // ============== DRIVER FUNCTIONS ==============
        function viewDriver(id) {
            currentDriverId = id;
            
            // Show loading
            document.getElementById('driverDetails').innerHTML = '<p class="text-center">Loading driver details...</p>';
            
            // Open modal
            $('#driverModal').modal('show');
            
            // Show the View on Map button
            document.getElementById('focusOnMapBtn').style.display = 'inline-block';
            
            // Fetch driver details
            fetch('driver_tracking.php?ajax_details=1&id=' + id)
                .then(function(response) { 
                    return response.json(); 
                })
                .then(function(data) {
                    if (data.success) {
                        var driver = data.driver;
                        var statusBadgeClass = driver.status_badge || 'bg-secondary';
                        var statusText = driver.status || 'unknown';
                        
                        var html = '<dl class="row">';
                        html += '<dt class="col-sm-4">Driver ID:</dt><dd class="col-sm-8">' + escapeHtml(driver.id) + '</dd>';
                        html += '<dt class="col-sm-4">Name:</dt><dd class="col-sm-8"><strong>' + escapeHtml(driver.name) + '</strong></dd>';
                        html += '<dt class="col-sm-4">License No:</dt><dd class="col-sm-8">' + escapeHtml(driver.license_no) + '</dd>';
                        html += '<dt class="col-sm-4">License Expiry:</dt><dd class="col-sm-8"><span class="badge bg-warning">' + escapeHtml(driver.license_expiry) + '</span></dd>';
                        html += '<dt class="col-sm-4">Phone:</dt><dd class="col-sm-8">' + escapeHtml(driver.phone) + '</dd>';
                        html += '<dt class="col-sm-4">Vehicle:</dt><dd class="col-sm-8">' + escapeHtml(driver.vehicle_id) + ' (' + escapeHtml(driver.vehicle_type) + ')</dd>';
                        html += '<dt class="col-sm-4">Branch:</dt><dd class="col-sm-8">' + escapeHtml(driver.branch) + '</dd>';
                        html += '<dt class="col-sm-4">Current Location:</dt><dd class="col-sm-8"><i class="bi bi-geo-alt"></i> ' + escapeHtml(driver.current_location) + '</dd>';
                        html += '<dt class="col-sm-4">Current Trip:</dt><dd class="col-sm-8">' + escapeHtml(driver.current_trip) + '</dd>';
                        html += '<dt class="col-sm-4">Status:</dt><dd class="col-sm-8"><span class="badge ' + statusBadgeClass + '">' + escapeHtml(statusText) + '</span></dd>';
                        html += '<dt class="col-sm-4">Trips Today:</dt><dd class="col-sm-8">' + driver.trips_completed_today + '</dd>';
                        html += '<dt class="col-sm-4">Last Update:</dt><dd class="col-sm-8">' + escapeHtml(driver.last_location_update) + '</dd>';
                        html += '</dl>';
                        
                        document.getElementById('driverDetails').innerHTML = html;
                    } else {
                        document.getElementById('driverDetails').innerHTML = '<p class="text-danger text-center">Failed to load driver details.</p>';
                    }
                })
                .catch(function(error) {
                    console.error('Error:', error);
                    document.getElementById('driverDetails').innerHTML = '<p class="text-danger text-center">Error loading driver details.</p>';
                });
        }
        
        function focusOnDriver(driverId) {
            if (markers[driverId]) {
                var marker = markers[driverId];
                var latlng = marker.getLatLng();
                map.setView([latlng.lat, latlng.lng], 16);
                marker.openPopup();
            }
        }
        
        function focusOnMapFromModal() {
            if (currentDriverId) {
                $('#driverModal').modal('hide');
                setTimeout(function() {
                    focusOnDriver(currentDriverId);
                }, 500);
            }
        }
        
        // ============== TRIP FUNCTIONS ==============
        function viewTrip(tripId) {
            // Show loading
            document.getElementById('tripDetails').innerHTML = '<p class="text-center">Loading trip details...</p>';
            
            // Open modal
            $('#tripModal').modal('show');
            
            // Fetch trip details
            fetch('driver_tracking.php?ajax_trip=1&id=' + tripId)
                .then(function(response) { 
                    return response.json(); 
                })
                .then(function(data) {
                    if (data.success) {
                        var trip = data.trip;
                        var statusBadge = 'bg-info';
                        var statusText = trip.status;
                        
                        if (trip.status === 'completed') {
                            statusBadge = 'bg-success';
                            statusText = 'Completed';
                        } else if (trip.status === 'in-progress') {
                            statusBadge = 'bg-warning';
                            statusText = 'In Progress';
                        } else if (trip.status === 'delayed') {
                            statusBadge = 'bg-danger';
                            statusText = 'Delayed';
                        } else if (trip.status === 'cancelled') {
                            statusBadge = 'bg-secondary';
                            statusText = 'Cancelled';
                        }
                        
                        var html = '<dl class="row">';
                        html += '<dt class="col-sm-4">Trip Number:</dt><dd class="col-sm-8"><strong>' + escapeHtml(trip.trip_number) + '</strong></dd>';
                        html += '<dt class="col-sm-4">Driver:</dt><dd class="col-sm-8">' + escapeHtml(trip.driver_name) + '</dd>';
                        html += '<dt class="col-sm-4">Vehicle:</dt><dd class="col-sm-8">' + escapeHtml(trip.vehicle) + '</dd>';
                        html += '<dt class="col-sm-4">Origin:</dt><dd class="col-sm-8">' + escapeHtml(trip.origin) + '</dd>';
                        html += '<dt class="col-sm-4">Origin Address:</dt><dd class="col-sm-8"><small>' + escapeHtml(trip.origin_address) + '</small></dd>';
                        html += '<dt class="col-sm-4">Destination:</dt><dd class="col-sm-8">' + escapeHtml(trip.destination || 'N/A') + '</dd>';
                        html += '<dt class="col-sm-4">Departure:</dt><dd class="col-sm-8">' + escapeHtml(trip.departure) + '</dd>';
                        html += '<dt class="col-sm-4">ETA:</dt><dd class="col-sm-8">' + escapeHtml(trip.eta) + '</dd>';
                        html += '<dt class="col-sm-4">Actual Arrival:</dt><dd class="col-sm-8">' + escapeHtml(trip.actual_arrival || 'Not arrived') + '</dd>';
                        html += '<dt class="col-sm-4">Status:</dt><dd class="col-sm-8"><span class="badge ' + statusBadge + '">' + escapeHtml(statusText) + '</span></dd>';
                        html += '<dt class="col-sm-4">Total Stops:</dt><dd class="col-sm-8">' + trip.total_stops + '</dd>';
                        html += '<dt class="col-sm-4">Delivered:</dt><dd class="col-sm-8">' + trip.total_delivered + '</dd>';
                        html += '<dt class="col-sm-4">Failed:</dt><dd class="col-sm-8">' + trip.total_failed + '</dd>';
                        html += '<dt class="col-sm-4">Total Items:</dt><dd class="col-sm-8">' + trip.total_items + '</dd>';
                        html += '<dt class="col-sm-4">Total Value:</dt><dd class="col-sm-8">₱' + parseFloat(trip.total_value).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',') + '</dd>';
                        html += '<dt class="col-sm-4">Remarks:</dt><dd class="col-sm-8">' + escapeHtml(trip.remarks) + '</dd>';
                        html += '</dl>';
                        
                        // Add deliveries if any
                        if (trip.deliveries && trip.deliveries.length > 0) {
                            html += '<h6 class="mt-4">Delivery Stops</h6>';
                            html += '<div class="table-responsive">';
                            html += '<table class="table table-sm table-bordered">';
                            html += '<thead><tr><th>Stop</th><th>Customer</th><th>SO #</th><th>Status</th><th>Signed By</th></tr></thead>';
                            html += '<tbody>';
                            
                            for (var i = 0; i < trip.deliveries.length; i++) {
                                var del = trip.deliveries[i];
                                var delStatusBadge = 'bg-info';
                                
                                if (del.delivery_status === 'delivered') delStatusBadge = 'bg-success';
                                else if (del.delivery_status === 'rejected') delStatusBadge = 'bg-danger';
                                else if (del.delivery_status === 'partial') delStatusBadge = 'bg-warning';
                                
                                html += '<tr>';
                                html += '<td>' + (del.stop_sequence || '-') + '</td>';
                                html += '<td>' + escapeHtml(del.customer_name) + '</td>';
                                html += '<td>' + escapeHtml(del.so_number) + '</td>';
                                html += '<td><span class="badge ' + delStatusBadge + '">' + escapeHtml(del.delivery_status) + '</span></td>';
                                html += '<td>' + escapeHtml(del.signed_by || '-') + '</td>';
                                html += '</tr>';
                            }
                            
                            html += '</tbody>';
                            html += '</table>';
                            html += '</div>';
                        }
                        
                        document.getElementById('tripDetails').innerHTML = html;
                    } else {
                        document.getElementById('tripDetails').innerHTML = '<p class="text-danger text-center">Failed to load trip details.</p>';
                    }
                })
                .catch(function(error) {
                    console.error('Error:', error);
                    document.getElementById('tripDetails').innerHTML = '<p class="text-danger text-center">Error loading trip details.</p>';
                });
        }
        
        // ============== HELPER FUNCTIONS ==============
        function escapeHtml(text) {
            if (!text) return '';
            if (typeof text !== 'string') text = String(text);
            return text
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }
        
        // ============== MAP FUNCTIONS ==============
        function initMap() {
            map = L.map('driverMap').setView([14.5995, 120.9842], 12);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; OpenStreetMap contributors',
                maxZoom: 19
            }).addTo(map);
        }
        
        // Create truck icon
        var truckIcon = L.divIcon({
            html: '<div style="background: #FF6B35; border: 2px solid white; border-radius: 50%; width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 8px rgba(0,0,0,0.3); transform: rotate(-45deg);">' +
                  '<i class="bi bi-truck" style="color: white; font-size: 20px; transform: rotate(45deg);"></i>' +
                  '</div>',
            className: 'truck-marker',
            iconSize: [40, 40],
            iconAnchor: [20, 20],
            popupAnchor: [0, -20]
        });
        
        function updateMarker(driver) {
            var lat = parseFloat(driver.latitude);
            var lng = parseFloat(driver.longitude);
            
            if (!isNaN(lat) && !isNaN(lng) && lat != 0 && lng != 0) {
                if (markers[driver.id]) {
                    markers[driver.id].setLatLng([lat, lng]);
                    markers[driver.id].setPopupContent(createPopupContent(driver));
                } else {
                    var marker = L.marker([lat, lng], { icon: truckIcon })
                        .addTo(map)
                        .bindPopup(createPopupContent(driver));
                    markers[driver.id] = marker;
                }
            }
        }
        
        function createPopupContent(driver) {
            var statusColor = '#6c757d';
            if (driver.status === 'active') statusColor = '#28a745';
            else if (driver.status === 'idle') statusColor = '#ffc107';
            
            var statusText = driver.status ? driver.status.replace('_', ' ').toUpperCase() : 'UNKNOWN';
            
            return '<div style="min-width: 200px;">' +
                '<strong>' + escapeHtml(driver.name) + '</strong><br>' +
                'Trip: ' + escapeHtml(driver.current_trip || 'None') + '<br>' +
                'Location: ' + escapeHtml(driver.current_location) + '<br>' +
                'Destination: ' + escapeHtml(driver.destination || 'N/A') + '<br>' +
                '<span style="display: inline-block; margin-top: 5px; padding: 3px 8px; background: ' + statusColor + '; color: white; border-radius: 4px;">' +
                statusText +
                '</span><br>' +
                '<button onclick="focusOnDriver(' + driver.id + ')" style="margin-top: 8px; padding: 4px 8px; background: #FF6B35; color: white; border: none; border-radius: 4px; cursor: pointer;">View Details</button>' +
                '</div>';
        }
        
        // ============== LOAD DATA FUNCTIONS ==============
        function loadTracking() {
            var driverName = document.getElementById('driverNameFilter').value;
            var location = document.getElementById('locationFilter').value;
            var tripTicket = document.getElementById('tripTicketFilter').value;
            var status = document.getElementById('statusFilter').value;
            
            var params = 'ajax=1' +
                '&driverName=' + encodeURIComponent(driverName) +
                '&location=' + encodeURIComponent(location) +
                '&tripTicket=' + encodeURIComponent(tripTicket) +
                '&status=' + encodeURIComponent(status);
            
            fetch('driver_tracking.php?' + params)
                .then(function(response) { 
                    return response.json(); 
                })
                .then(function(data) {
                    if (data.success) {
                        displayDrivers(data.drivers || []);
                        displayTrips(data.trips || []);
                        
                        document.getElementById('totalDrivers').textContent = data.stats.totalDrivers || 0;
                        document.getElementById('activeDrivers').textContent = data.stats.activeDrivers || 0;
                        document.getElementById('completedTrips').textContent = data.stats.completedTrips || 0;
                    }
                })
                .catch(function(error) {
                    console.error('Error:', error);
                });
        }
        
        function displayDrivers(drivers) {
            var tbody = document.getElementById('driversTable');
            
            if (drivers.length === 0) {
                tbody.innerHTML = '<tr><td colspan="8" class="text-center py-4">No drivers found</td></tr>';
                return;
            }
            
            // Clear old markers
            for (var key in markers) {
                map.removeLayer(markers[key]);
            }
            markers = {};
            
            var html = '';
            
            for (var i = 0; i < drivers.length; i++) {
                var driver = drivers[i];
                var statusBadge = driver.status_badge || 'bg-secondary';
                var statusText = driver.status ? driver.status.replace('_', ' ') : 'unknown';
                
                // Update map markers
                updateMarker(driver);
                
                html += '<tr data-driver-id="' + driver.id + '">';
                html += '<td class="id-column">' + driver.id + '</td>';
                html += '<td><strong>' + escapeHtml(driver.name) + '</strong><br><small class="text-muted">' + escapeHtml(driver.vehicle_id) + '</small></td>';
                html += '<td><i class="bi bi-geo-alt"></i> ' + escapeHtml(driver.current_location) + '</td>';
                html += '<td>' + escapeHtml(driver.current_trip || '-') + '</td>';
                html += '<td>' + escapeHtml(driver.destination || '-') + '</td>';
                html += '<td><span class="badge ' + statusBadge + '">' + escapeHtml(statusText) + '</span></td>';
                html += '<td>' + (driver.last_update ? new Date(driver.last_update).toLocaleTimeString() : 'N/A') + '</td>';
                html += '<td>';
                html += '<button class="btn-action btn-map" onclick="focusOnDriver(' + driver.id + ')" title="View on Map"><i class="bi bi-geo-alt-fill"></i></button> ';
                html += '<button class="btn-action btn-view" onclick="viewDriver(' + driver.id + ')"><i class="bi bi-eye"></i></button>';
                html += '</td>';
                html += '</tr>';
            }
            
            tbody.innerHTML = html;
            
            // Fit map to show all markers
            var markerCount = 0;
            for (var key in markers) markerCount++;
            
            if (markerCount > 0) {
                var group = L.featureGroup(Object.values(markers));
                map.fitBounds(group.getBounds().pad(0.1));
            }
        }
        
        function displayTrips(trips) {
            var tbody = document.getElementById('tripsTable');
            
            if (trips.length === 0) {
                tbody.innerHTML = '<tr><td colspan="8" class="text-center py-4">No trips found for today</td></tr>';
                return;
            }
            
            var html = '';
            
            for (var i = 0; i < trips.length; i++) {
                var trip = trips[i];
                var statusBadge = 'bg-info';
                var statusText = trip.status;
                
                if (trip.status === 'completed') {
                    statusBadge = 'bg-success';
                    statusText = 'Completed';
                } else if (trip.status === 'in-progress') {
                    statusBadge = 'bg-warning';
                    statusText = 'In Progress';
                } else if (trip.status === 'delayed') {
                    statusBadge = 'bg-danger';
                    statusText = 'Delayed';
                } else if (trip.status === 'cancelled') {
                    statusBadge = 'bg-secondary';
                    statusText = 'Cancelled';
                }
                
                var departureTime = trip.departure ? new Date(trip.departure).toLocaleTimeString() : 'N/A';
                
                html += '<tr>';
                html += '<td><strong>' + escapeHtml(trip.trip_number) + '</strong></td>';
                html += '<td>' + escapeHtml(trip.driver_name) + '</td>';
                html += '<td>' + escapeHtml(trip.origin) + '</td>';
                html += '<td>' + escapeHtml(trip.destination) + '</td>';
                html += '<td>' + trip.item_count + '</td>';
                html += '<td>' + departureTime + '</td>';
                html += '<td><span class="badge ' + statusBadge + '">' + escapeHtml(statusText) + '</span></td>';
                html += '<td><button class="btn btn-sm btn-primary" onclick="viewTrip(' + trip.trip_id + ')"><i class="bi bi-eye"></i></button></td>';
                html += '</tr>';
            }
            
            tbody.innerHTML = html;
        }
        
        // ============== SIDEBAR FUNCTIONS ==============
        function toggleSidebar() {
            var sidebar = document.getElementById('sidebar');
            var isMobile = window.innerWidth <= 992;
            
            if (isMobile) {
                sidebar.classList.toggle('active');
                
                if (!document.querySelector('.sidebar-overlay')) {
                    var overlay = document.createElement('div');
                    overlay.className = 'sidebar-overlay';
                    document.body.appendChild(overlay);
                    overlay.addEventListener('click', closeMobileSidebar);
                    setTimeout(function() { overlay.classList.add('active'); }, 10);
                } else {
                    var overlay = document.querySelector('.sidebar-overlay');
                    overlay.classList.toggle('active');
                    if (!sidebar.classList.contains('active')) {
                        setTimeout(function() { 
                            if (overlay && overlay.parentNode) overlay.remove(); 
                        }, 300);
                    }
                }
            } else {
                sidebar.classList.toggle('collapsed');
                localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
                
                var navTexts = document.querySelectorAll('.nav-text');
                for (var i = 0; i < navTexts.length; i++) {
                    navTexts[i].style.display = sidebar.classList.contains('collapsed') ? 'none' : 'inline-block';
                }
                
                var mainContent = document.querySelector('.main-content');
                if (mainContent) {
                    mainContent.style.marginLeft = sidebar.classList.contains('collapsed') ? '80px' : '250px';
                }
            }
        }
        
        function closeMobileSidebar() {
            var sidebar = document.getElementById('sidebar');
            var overlay = document.querySelector('.sidebar-overlay');
            
            sidebar.classList.remove('active');
            
            if (overlay) {
                overlay.classList.remove('active');
                setTimeout(function() { 
                    if (overlay.parentNode) overlay.remove(); 
                }, 300);
            }
        }
        
        function initializeSidebar() {
            var sidebar = document.getElementById('sidebar');
            
            if (window.innerWidth > 992) {
                var savedCollapsed = localStorage.getItem('sidebarCollapsed');
                
                if (savedCollapsed === 'true') {
                    sidebar.classList.add('collapsed');
                    var navTexts = document.querySelectorAll('.nav-text');
                    for (var i = 0; i < navTexts.length; i++) {
                        navTexts[i].style.display = 'none';
                    }
                    var mainContent = document.querySelector('.main-content');
                    if (mainContent) mainContent.style.marginLeft = '80px';
                } else {
                    sidebar.classList.remove('collapsed');
                    var navTexts = document.querySelectorAll('.nav-text');
                    for (var i = 0; i < navTexts.length; i++) {
                        navTexts[i].style.display = 'inline-block';
                    }
                    var mainContent = document.querySelector('.main-content');
                    if (mainContent) mainContent.style.marginLeft = '250px';
                }
            } else {
                sidebar.classList.remove('active', 'collapsed');
                var navTexts = document.querySelectorAll('.nav-text');
                for (var i = 0; i < navTexts.length; i++) {
                    navTexts[i].style.display = 'inline-block';
                }
                var mainContent = document.querySelector('.main-content');
                if (mainContent) mainContent.style.marginLeft = '0';
            }
        }
        
        function handleSidebarResize() {
            var sidebar = document.getElementById('sidebar');
            var overlay = document.querySelector('.sidebar-overlay');
            
            if (window.innerWidth > 992) {
                if (overlay) overlay.remove();
                sidebar.classList.remove('active');
                
                var savedCollapsed = localStorage.getItem('sidebarCollapsed');
                
                if (savedCollapsed === 'true') {
                    sidebar.classList.add('collapsed');
                    var navTexts = document.querySelectorAll('.nav-text');
                    for (var i = 0; i < navTexts.length; i++) {
                        navTexts[i].style.display = 'none';
                    }
                    var mainContent = document.querySelector('.main-content');
                    if (mainContent) mainContent.style.marginLeft = '80px';
                } else {
                    sidebar.classList.remove('collapsed');
                    var navTexts = document.querySelectorAll('.nav-text');
                    for (var i = 0; i < navTexts.length; i++) {
                        navTexts[i].style.display = 'inline-block';
                    }
                    var mainContent = document.querySelector('.main-content');
                    if (mainContent) mainContent.style.marginLeft = '250px';
                }
            } else {
                sidebar.classList.remove('collapsed');
                var navTexts = document.querySelectorAll('.nav-text');
                for (var i = 0; i < navTexts.length; i++) {
                    navTexts[i].style.display = 'inline-block';
                }
                var mainContent = document.querySelector('.main-content');
                if (mainContent) mainContent.style.marginLeft = '0';
            }
        }
        
        // ============== LOGOUT ==============
        function logout() {
            window.location.href = '../logout.php';
        }
        
        // ============== INITIALIZATION ==============
        document.addEventListener('DOMContentLoaded', function() {
            initializeSidebar();
            
            var mobileToggleBtn = document.getElementById('mobileToggleBtn');
            if (mobileToggleBtn) {
                mobileToggleBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    toggleSidebar();
                });
            }
            
            var desktopToggleBtn = document.getElementById('desktopToggleBtn');
            if (desktopToggleBtn) {
                desktopToggleBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    toggleSidebar();
                });
            }
            
            var sidebarLinks = document.querySelectorAll('.sidebar .nav-link');
            for (var i = 0; i < sidebarLinks.length; i++) {
                sidebarLinks[i].addEventListener('click', function() {
                    if (window.innerWidth <= 992) closeMobileSidebar();
                });
            }
            
            document.addEventListener('click', function(event) {
                var sidebar = document.getElementById('sidebar');
                var mobileToggleBtn = document.getElementById('mobileToggleBtn');
                var overlay = document.querySelector('.sidebar-overlay');
                var isMobile = window.innerWidth <= 992;
                
                if (isMobile && sidebar && sidebar.classList.contains('active') && 
                    !sidebar.contains(event.target) && 
                    (!mobileToggleBtn || !mobileToggleBtn.contains(event.target)) &&
                    (!overlay || !overlay.contains(event.target))) {
                    closeMobileSidebar();
                }
            });
            
            window.addEventListener('resize', handleSidebarResize);
            
            // Initialize map and load data
            initMap();
            loadTracking();
        });
        
        // Auto-refresh tracking data every 30 seconds
        setInterval(loadTracking, 30000);
    </script>
</body>
</html>
<?php $conn->close(); ?>