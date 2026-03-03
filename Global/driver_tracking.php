<?php
require_once '../config/database.php';
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

// Get user info for display
$user_name = $_SESSION['user_name'] ?? $_SESSION['first_name'] . ' ' . $_SESSION['last_name'] ?? 'User';
$user_role = $_SESSION['role'] ?? 'global';

// Get initials for avatar
$name_parts = explode(' ', $user_name);
$initials = '';
foreach ($name_parts as $part) {
    if (!empty($part)) {
        $initials .= strtoupper(substr($part, 0, 1));
    }
}
if (empty($initials)) $initials = 'AD';

// Get branches for filter
$branches = [];
$branch_query = "SELECT branch_id, branch_name FROM branches WHERE status = 'active' ORDER BY branch_name";
$branch_result = $conn->query($branch_query);
if ($branch_result) {
    while ($row = $branch_result->fetch_assoc()) {
        $branches[] = $row;
    }
}

// Handle AJAX request for tracking data
if (isset($_GET['ajax']) && $_GET['ajax'] == 1) {
    header('Content-Type: application/json');
    
    try {
        // Get filter parameters
        $filter_name = isset($_GET['driverName']) ? trim($_GET['driverName']) : '';
        $filter_branch = isset($_GET['location']) ? trim($_GET['location']) : '';
        $filter_trip = isset($_GET['tripTicket']) ? trim($_GET['tripTicket']) : '';
        $filter_status = isset($_GET['status']) ? trim($_GET['status']) : '';
        
        $response = [
            'success' => true,
            'drivers' => [],
            'trips' => [],
            'stats' => [
                'totalDrivers' => 0,
                'activeDrivers' => 0,
                'onlineDrivers' => 0
            ]
        ];

        // Get all drivers with their latest location from driver_locations table
        $drivers_sql = "SELECT 
                            d.driver_id,
                            d.driver_name,
                            d.contact_number,
                            d.vehicle_type,
                            d.vehicle_plate_number,
                            d.status as driver_status,
                            b.branch_name,
                            b.city,
                            dl.latitude,
                            dl.longitude,
                            dl.speed,
                            dl.last_update,
                            dl.is_active,
                            TIMESTAMPDIFF(SECOND, dl.last_update, NOW()) as seconds_ago,
                            tt.trip_id,
                            tt.trip_number,
                            tt.trip_status
                        FROM drivers d
                        LEFT JOIN branches b ON d.branch_id = b.branch_id
                        LEFT JOIN (
                            SELECT dl1.*
                            FROM driver_locations dl1
                            INNER JOIN (
                                SELECT driver_id, MAX(last_update) as max_update
                                FROM driver_locations
                                GROUP BY driver_id
                            ) dl2 ON dl1.driver_id = dl2.driver_id AND dl1.last_update = dl2.max_update
                        ) dl ON d.driver_id = dl.driver_id
                        LEFT JOIN trip_tickets tt ON d.driver_id = tt.driver_id 
                            AND tt.trip_status IN ('in-progress', 'planned')
                        WHERE 1=1";
        
        // Apply filters
        $params = [];
        $types = "";
        
        if (!empty($filter_name)) {
            $drivers_sql .= " AND d.driver_name LIKE ?";
            $params[] = "%$filter_name%";
            $types .= "s";
        }
        
        if (!empty($filter_branch) && $filter_branch != 'in_transit') {
            $drivers_sql .= " AND b.branch_name = ?";
            $params[] = $filter_branch;
            $types .= "s";
        }
        
        if (!empty($filter_trip)) {
            $drivers_sql .= " AND tt.trip_number LIKE ?";
            $params[] = "%$filter_trip%";
            $types .= "s";
        }
        
        $drivers_sql .= " ORDER BY d.driver_name ASC";
        
        $stmt = $conn->prepare($drivers_sql);
        
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        
        $stmt->execute();
        $drivers_result = $stmt->get_result();
        
        $active_drivers = 0;
        $online_drivers = 0;
        $total_drivers = 0;
        
        while ($row = $drivers_result->fetch_assoc()) {
            $total_drivers++;
            
            // Determine online status (based on last_update within 30 seconds)
            $is_online = false;
            $has_location = false;
            $status_badge = 'bg-secondary';
            $tracking_status = 'offline';
            $last_seen = null;
            $speed = 0;
            $location_text = 'No location';
            $latitude = null;
            $longitude = null;
            
            // Check if driver has recent location (within 30 seconds)
            if (!empty($row['last_update']) && isset($row['seconds_ago'])) {
                $seconds_ago = intval($row['seconds_ago']);
                $last_seen = $seconds_ago;
                
                if ($seconds_ago <= 30) {
                    $is_online = true;
                    $online_drivers++;
                    
                    // Check if driver has an active trip
                    if (!empty($row['trip_status']) && $row['trip_status'] == 'in-progress') {
                        $tracking_status = 'active';
                        $status_badge = 'bg-success';
                        $active_drivers++;
                    } else {
                        $tracking_status = 'idle';
                        $status_badge = 'bg-warning';
                    }
                }
            }
            
            // Check if driver has location data
            if (!empty($row['latitude']) && !empty($row['longitude'])) {
                $has_location = true;
                $latitude = floatval($row['latitude']);
                $longitude = floatval($row['longitude']);
                $speed = floatval($row['speed'] ?? 0);
                $location_text = sprintf('%.4f, %.4f', $latitude, $longitude);
            } elseif ($row['branch_name']) {
                $location_text = $row['branch_name'] . ', ' . ($row['city'] ?? '');
                // Use branch coordinates if available (default to Manila if not)
                $latitude = 14.5995;
                $longitude = 120.9842;
            }
            
            // Apply status filter
            if (!empty($filter_status)) {
                if ($filter_status == 'active' && $tracking_status != 'active') continue;
                if ($filter_status == 'idle' && $tracking_status != 'idle') continue;
                if ($filter_status == 'offline' && $tracking_status != 'offline') continue;
            }
            
            $response['drivers'][] = [
                'id' => intval($row['driver_id']),
                'name' => $row['driver_name'],
                'phone' => $row['contact_number'] ?? 'N/A',
                'vehicle_id' => $row['vehicle_plate_number'] ?? $row['vehicle_type'] ?? 'N/A',
                'branch' => $row['branch_name'] ?? 'Unassigned',
                'current_location' => $location_text,
                'latitude' => $latitude ?: 14.5995,
                'longitude' => $longitude ?: 120.9842,
                'current_trip' => $row['trip_number'] ?? '-',
                'destination' => 'N/A',
                'status' => $tracking_status,
                'status_badge' => $status_badge,
                'last_update' => $row['last_update'] ?? null,
                'speed_kmh' => round($speed * 3.6), // Convert to km/h
                'last_seen' => $last_seen,
                'is_online' => $is_online,
                'has_location' => $has_location,
                'driver_status' => $row['driver_status']
            ];
        }
        
        $stmt->close();
        
        // Get active trips (unfiltered)
        $trips_sql = "SELECT 
                        tt.trip_id,
                        tt.trip_number,
                        tt.trip_status,
                        tt.start_time,
                        d.driver_id,
                        d.driver_name,
                        b.branch_name as origin,
                        COUNT(DISTINCT del.delivery_id) as total_stops
                    FROM trip_tickets tt
                    JOIN drivers d ON tt.driver_id = d.driver_id
                    JOIN branches b ON tt.branch_id = b.branch_id
                    LEFT JOIN deliveries del ON tt.trip_id = del.trip_id
                    WHERE tt.trip_status IN ('in-progress', 'planned')
                    GROUP BY tt.trip_id
                    ORDER BY tt.start_time DESC
                    LIMIT 20";
        
        $trips_result = $conn->query($trips_sql);
        
        if ($trips_result) {
            while ($row = $trips_result->fetch_assoc()) {
                $status_badge = 'bg-secondary';
                $status_text = 'Planned';
                
                if ($row['trip_status'] == 'in-progress') {
                    $status_badge = 'bg-success';
                    $status_text = 'In Progress';
                }
                
                $response['trips'][] = [
                    'trip_id' => $row['trip_id'],
                    'trip_number' => $row['trip_number'],
                    'driver_id' => $row['driver_id'],
                    'driver_name' => $row['driver_name'],
                    'origin' => $row['origin'],
                    'destination' => 'Various',
                    'status' => $row['trip_status'],
                    'status_badge' => $status_badge,
                    'status_text' => $status_text,
                    'departure' => $row['start_time'],
                    'item_count' => $row['total_stops'] ?? 0
                ];
            }
        }
        
        $response['stats'] = [
            'totalDrivers' => $total_drivers,
            'activeDrivers' => $active_drivers,
            'onlineDrivers' => $online_drivers
        ];
        
        echo json_encode($response);
        exit;
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Database error: ' . $e->getMessage()
        ]);
        exit;
    }
}

// Handle AJAX request for driver details
if (isset($_GET['ajax_details']) && isset($_GET['id'])) {
    header('Content-Type: application/json');
    
    try {
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
                    dl.latitude,
                    dl.longitude,
                    dl.last_update,
                    dl.speed,
                    dl.is_active,
                    TIMESTAMPDIFF(SECOND, dl.last_update, NOW()) as seconds_ago,
                    tt.trip_id as current_trip_id,
                    tt.trip_number as current_trip_number,
                    tt.trip_status
                FROM drivers d
                LEFT JOIN branches b ON d.branch_id = b.branch_id
                LEFT JOIN (
                    SELECT dl1.*
                    FROM driver_locations dl1
                    INNER JOIN (
                        SELECT driver_id, MAX(last_update) as max_update
                        FROM driver_locations
                        GROUP BY driver_id
                    ) dl2 ON dl1.driver_id = dl2.driver_id AND dl1.last_update = dl2.max_update
                ) dl ON d.driver_id = dl.driver_id
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
            
            // Determine online status
            $is_online = false;
            $last_seen = null;
            $tracking_status = 'offline';
            $status_badge = 'bg-secondary';
            
            if (!empty($row['last_update'])) {
                $seconds_ago = intval($row['seconds_ago']);
                $last_seen = $seconds_ago;
                
                if ($seconds_ago <= 30) {
                    $is_online = true;
                    
                    if (!empty($row['current_trip_id'])) {
                        $tracking_status = 'active';
                        $status_badge = 'bg-success';
                    } else {
                        $tracking_status = 'idle';
                        $status_badge = 'bg-warning';
                    }
                }
            }
            
            // Use location
            $latitude = $row['latitude'] ?? null;
            $longitude = $row['longitude'] ?? null;
            $last_update = $row['last_update'] ?? null;
            
            // Get current location text
            $current_location = 'Location unavailable';
            if ($latitude && $longitude) {
                $current_location = sprintf('%.6f, %.6f', $latitude, $longitude);
            } elseif ($row['branch']) {
                $current_location = $row['branch'] . ', ' . ($row['city'] ?? '');
            }
            
            $response = [
                'success' => true,
                'driver' => [
                    'id' => $row['id'],
                    'name' => $row['name'],
                    'license_no' => $row['license_no'],
                    'license_expiry' => $row['license_expiry'] ? date('F d, Y', strtotime($row['license_expiry'])) : 'N/A',
                    'phone' => $row['phone'] ?? 'N/A',
                    'vehicle_id' => $row['vehicle_id'] ?? $row['vehicle_type'] ?? 'N/A',
                    'vehicle_type' => $row['vehicle_type'] ?? 'N/A',
                    'branch' => $row['branch'] ?? 'Unassigned',
                    'branch_address' => $row['branch_address'] ?? 'N/A',
                    'current_location' => $current_location,
                    'latitude' => $latitude ? floatval($latitude) : null,
                    'longitude' => $longitude ? floatval($longitude) : null,
                    'current_trip' => $row['current_trip_number'] ?? 'None',
                    'current_trip_id' => $row['current_trip_id'] ?? null,
                    'status' => $tracking_status,
                    'status_badge' => $status_badge,
                    'trips_completed_today' => $trips_row['trips_completed'] ?? 0,
                    'last_location_update' => $last_update ? date('h:i:s A', strtotime($last_update)) : 'N/A',
                    'last_seen' => $last_seen,
                    'speed' => $row['speed'] ?? 0,
                    'is_online' => $is_online
                ]
            ];
        } else {
            $response = ['success' => false, 'message' => 'Driver not found'];
        }
        
        echo json_encode($response);
        exit;
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Error: ' . $e->getMessage()
        ]);
        exit;
    }
}

// Handle AJAX request for trip details
if (isset($_GET['ajax_trip']) && isset($_GET['id'])) {
    header('Content-Type: application/json');
    
    try {
        $trip_id = intval($_GET['id']);
        
        $sql = "SELECT 
                    tt.*,
                    d.driver_name,
                    d.vehicle_plate_number,
                    b.branch_name as origin_branch,
                    b.address as origin_address,
                    COUNT(DISTINCT del.delivery_id) as total_deliveries
                FROM trip_tickets tt
                JOIN drivers d ON tt.driver_id = d.driver_id
                JOIN branches b ON tt.branch_id = b.branch_id
                LEFT JOIN deliveries del ON tt.trip_id = del.trip_id
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
                    'so_number' => $del['so_number'],
                    'delivery_status' => $del['delivery_status']
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
                    'status' => $row['trip_status'],
                    'total_stops' => $row['total_stops'] ?? count($deliveries),
                    'deliveries' => $deliveries
                ]
            ];
        } else {
            $response = ['success' => false, 'message' => 'Trip not found'];
        }
        
        echo json_encode($response);
        exit;
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Error: ' . $e->getMessage()
        ]);
        exit;
    }
}

// If not AJAX request, display the HTML page
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
    <link rel="stylesheet" href="../css/global.css">
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <!-- Leaflet CSS -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <style>
        /* Global Styles */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f8f9fa;
            overflow-x: hidden;
        }
        
        #appPage {
            display: flex;
            min-height: 100vh;
        }
        
        /* Sidebar */
        .sidebar {
            width: 250px;
            background: linear-gradient(180deg, #2c3e50 0%, #1a2634 100%);
            color: white;
            height: 100vh;
            position: fixed;
            left: 0;
            top: 0;
            transition: width 0.3s ease;
            z-index: 1000;
            overflow-y: auto;
            box-shadow: 2px 0 10px rgba(0,0,0,0.1);
        }
        
        .sidebar.collapsed {
            width: 80px;
        }
        
        .sidebar.collapsed .nav-text,
        .sidebar.collapsed .user-name-sidebar,
        .sidebar.collapsed .logout-text {
            display: none;
        }
        
        .sidebar-header {
            padding: 20px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        
        .sidebar-header h3 {
            margin: 0;
            font-size: 1.2rem;
            display: flex;
            align-items: center;
            gap: 10px;
            color: white;
        }
        
        .logo-icon {
            width: 32px;
            height: 32px;
            object-fit: contain;
        }
        
        .desktop-toggle-btn {
            background: transparent;
            border: none;
            color: white;
            font-size: 1.2rem;
            cursor: pointer;
            padding: 5px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .sidebar-menu {
            flex: 1;
            padding: 20px 0;
        }
        
        .sidebar-menu .nav-link {
            color: rgba(255,255,255,0.7);
            padding: 12px 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
            transition: all 0.3s;
            border-left: 3px solid transparent;
        }
        
        .sidebar-menu .nav-link i {
            font-size: 1.2rem;
            width: 24px;
            text-align: center;
        }
        
        .sidebar-menu .nav-link:hover {
            background: rgba(255,255,255,0.1);
            color: white;
        }
        
        .sidebar-menu .nav-link.active {
            background: rgba(255,255,255,0.15);
            color: white;
            border-left-color: #FF6B35;
        }
        
        .sidebar-footer {
            padding: 20px;
            border-top: 1px solid rgba(255,255,255,0.1);
        }
        
        .user-profile-sidebar {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 15px;
        }
        
        .user-avatar-sidebar {
            width: 40px;
            height: 40px;
            background: #FF6B35;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 1.1rem;
            color: white;
        }
        
        .user-details-sidebar {
            overflow: hidden;
        }
        
        .user-name-sidebar {
            font-weight: 500;
            font-size: 0.9rem;
            white-space: nowrap;
        }
        
        .logout-btn-sidebar {
            width: 100%;
            padding: 10px;
            background: rgba(255,255,255,0.1);
            border: 1px solid rgba(255,255,255,0.2);
            border-radius: 8px;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            transition: all 0.3s;
            cursor: pointer;
            border: none;
        }
        
        .logout-btn-sidebar:hover {
            background: rgba(255,255,255,0.2);
        }
        
        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: 250px;
            transition: margin-left 0.3s ease;
            padding: 20px;
        }
        
        .navbar-top {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 25px;
            background: white;
            padding: 12px 20px;
            border-radius: 10px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        
        .mobile-toggle-btn {
            display: none;
            background: transparent;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 8px 12px;
            cursor: pointer;
        }
        
        .page-title h2 {
            margin: 0;
            font-size: 1.4rem;
            font-weight: 600;
            color: #333;
        }
        
        .page-title p {
            margin: 5px 0 0;
            font-size: 0.85rem;
            color: #666;
        }
        
        /* Stat Cards */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .stat-card.total .stat-icon { color: #6c5ce7; }
        .stat-card.sales .stat-icon { color: #00b894; }
        .stat-card.complete .stat-icon { color: #0984e3; }
        
        .stat-icon {
            font-size: 2.5rem;
        }
        
        .stat-value {
            font-size: 1.8rem;
            font-weight: 600;
            color: #333;
            line-height: 1.2;
        }
        
        .stat-label {
            font-size: 0.9rem;
            color: #666;
        }
        
        /* Form Card */
        .form-card {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        
        /* Data Table */
        .data-table {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            overflow: hidden;
            margin-bottom: 20px;
        }
        
        .table-header {
            padding: 15px 20px;
            border-bottom: 1px solid #dee2e6;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .table-header h5 {
            margin: 0;
            font-size: 1.1rem;
            font-weight: 600;
            color: #333;
        }
        
        .custom-table {
            width: 100%;
            margin: 0;
        }
        
        .custom-table thead th {
            background: #f8f9fa;
            padding: 12px 15px;
            font-size: 0.85rem;
            font-weight: 600;
            color: #333;
            border-bottom: 2px solid #dee2e6;
        }
        
        .custom-table tbody td {
            padding: 12px 15px;
            font-size: 0.9rem;
            border-bottom: 1px solid #dee2e6;
            vertical-align: middle;
        }
        
        .id-column {
            width: 80px;
        }
        
        /* Status badges */
        .badge.bg-success {
            background-color: #28a745 !important;
        }
        .badge.bg-warning {
            background-color: #ffc107 !important;
            color: #212529 !important;
        }
        .badge.bg-secondary {
            background-color: #6c757d !important;
        }
        .badge.bg-info {
            background-color: #17a2b8 !important;
        }
        
        /* Location update indicator */
        .location-indicator {
            display: inline-block;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            margin-right: 5px;
        }
        .location-indicator.online {
            background-color: #28a745;
            box-shadow: 0 0 8px #28a745;
            animation: pulse 1.5s infinite;
        }
        .location-indicator.idle {
            background-color: #ffc107;
            box-shadow: 0 0 8px #ffc107;
        }
        .location-indicator.offline {
            background-color: #6c757d;
        }
        
        @keyframes pulse {
            0% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.5; transform: scale(1.2); }
            100% { opacity: 1; transform: scale(1); }
        }
        
        /* Action buttons */
        .btn-action {
            width: 32px;
            height: 32px;
            border: none;
            border-radius: 6px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin: 0 2px;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .btn-action.btn-map {
            background: #e3f2fd;
            color: #1976d2;
        }
        
        .btn-action.btn-view {
            background: #e8f5e9;
            color: #388e3c;
        }
        
        .btn-action:hover {
            transform: scale(1.1);
        }
        
        /* Map Container */
        #driverMap {
            width: 100%;
            height: 500px;
            border-radius: 8px;
            overflow: hidden;
        }
        
        /* Mobile responsive */
        @media (max-width: 992px) {
            .sidebar {
                left: -250px;
            }
            .sidebar.active {
                left: 0;
            }
            .main-content {
                margin-left: 0 !important;
            }
            .mobile-toggle-btn {
                display: block;
            }
            .stats-row {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        @media (max-width: 768px) {
            .stats-row {
                grid-template-columns: 1fr;
            }
            .stat-card {
                padding: 15px;
            }
            .stat-icon {
                font-size: 2rem;
            }
            .stat-value {
                font-size: 1.5rem;
            }
        }

        .filter-badge {
            cursor: pointer;
            padding: 5px 10px;
            border-radius: 20px;
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            margin-right: 5px;
            display: inline-block;
        }
        
        .filter-badge.active {
            background: #007bff;
            color: white;
            border-color: #007bff;
        }
    </style>
</head>
<body>
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
            
            <div class="sidebar-footer">
                <div class="user-profile-sidebar">
                    <div class="user-avatar-sidebar"><?php echo $initials; ?></div>
                    <div class="user-details-sidebar">
                        <span class="user-name-sidebar"><?php echo htmlspecialchars($user_name); ?></span>
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
            <div class="navbar-top">
                <button class="mobile-toggle-btn" id="mobileToggleBtn">
                    <i class="bi bi-list"></i>
                </button>

                <div class="page-title">
                    <h2>Driver Tracking</h2>
                    <p>Real-time tracking of all delivery drivers</p>
                </div>
            </div>

            <!-- Stats Cards -->
            <div class="stats-row">
                <div class="stat-card total">
                    <div class="stat-icon">
                        <i class="bi bi-people"></i>
                    </div>
                    <div>
                        <div class="stat-value" id="totalDrivers">0</div>
                        <div class="stat-label">Total Drivers</div>
                    </div>
                </div>
                
                <div class="stat-card sales">
                    <div class="stat-icon">
                        <i class="bi bi-wifi"></i>
                    </div>
                    <div>
                        <div class="stat-value" id="onlineDrivers">0</div>
                        <div class="stat-label">Online Now</div>
                    </div>
                </div>
                
                <div class="stat-card complete">
                    <div class="stat-icon">
                        <i class="bi bi-truck"></i>
                    </div>
                    <div>
                        <div class="stat-value" id="activeDrivers">0</div>
                        <div class="stat-label">On Delivery</div>
                    </div>
                </div>
                
                <div class="stat-card inventory">
                    <div class="stat-icon">
                        <i class="bi bi-clock-history"></i>
                    </div>
                    <div>
                        <div class="stat-value" id="updateTime">-</div>
                        <div class="stat-label">Last Update</div>
                    </div>
                </div>
            </div>

            <!-- Filter Card -->
            <div class="form-card mb-4">
                <h5 class="mb-3">Filter Drivers</h5>
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Driver Name</label>
                        <input type="text" class="form-control" id="filterName" placeholder="Enter driver name...">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Branch</label>
                        <select class="form-select" id="filterBranch">
                            <option value="">All Branches</option>
                            <?php foreach ($branches as $branch): ?>
                            <option value="<?php echo htmlspecialchars($branch['branch_name']); ?>"><?php echo htmlspecialchars($branch['branch_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Trip Ticket</label>
                        <input type="text" class="form-control" id="filterTrip" placeholder="Enter trip number...">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Status</label>
                        <select class="form-select" id="filterStatus">
                            <option value="">All Status</option>
                            <option value="active">Active (On Delivery)</option>
                            <option value="idle">Idle (Online)</option>
                            <option value="offline">Offline</option>
                        </select>
                    </div>
                </div>
                <div class="row mt-3">
                    <div class="col-12">
                        <button class="btn btn-primary" onclick="applyFilters()">
                            <i class="bi bi-search"></i> Apply Filters
                        </button>
                        <button class="btn btn-secondary" onclick="clearFilters()">
                            <i class="bi bi-x-circle"></i> Clear Filters
                        </button>
                    </div>
                </div>
            </div>

            <!-- Map Container -->
            <div class="data-table mb-4">
                <div class="table-header">
                    <h5><i class="bi bi-map"></i> Live Driver Locations</h5>
                    <div>
                        <span class="me-3"><span class="location-indicator online"></span> Online (Active)</span>
                        <span class="me-3"><span class="location-indicator idle"></span> Online (Idle)</span>
                        <span><span class="location-indicator offline"></span> Offline</span>
                    </div>
                </div>
                <div id="driverMap"></div>
            </div>

            <!-- Drivers Table -->
            <div class="data-table">
                <div class="table-header">
                    <h5><i class="bi bi-list"></i> Live Driver Locations</h5>
                    <span class="badge bg-primary" id="driverCount">0</span>
                </div>
                <div class="table-responsive">
                    <table class="table custom-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Driver</th>
                                <th>Vehicle</th>
                                <th>Location</th>
                                <th>Trip</th>
                                <th>Status</th>
                                <th>Last Seen</th>
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

            <!-- Trips Table -->
            <div class="data-table mt-4">
                <div class="table-header">
                    <h5><i class="bi bi-truck"></i> Active Trips</h5>
                </div>
                <div class="table-responsive">
                    <table class="table custom-table">
                        <thead>
                            <tr>
                                <th>Trip #</th>
                                <th>Driver</th>
                                <th>Origin</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody id="tripsTable">
                            <tr>
                                <td colspan="5" class="text-center py-4">
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

    <!-- Driver Details Modal -->
    <div class="modal fade" id="driverModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Driver Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="driverModalBody">
                    Loading...
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-warning" id="focusMapBtn" onclick="focusOnMapFromModal()">View on Map</button>
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
                <div class="modal-body" id="tripModalBody">
                    Loading...
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    
    <script>
        // ============== GLOBAL VARIABLES ==============
        let map;
        let markers = {};
        let currentDriverId = null;
        let refreshInterval;
        let lastUpdateTime = Date.now();

        // Custom truck icons for different statuses
        function getTruckIcon(status) {
            let color;
            let className;
            
            switch(status) {
                case 'active':
                    color = '#28a745'; // Green
                    className = 'truck-active';
                    break;
                case 'idle':
                    color = '#ffc107'; // Yellow
                    className = 'truck-idle';
                    break;
                default:
                    color = '#6c757d'; // Gray
                    className = 'truck-offline';
            }
            
            return L.divIcon({
                html: `<div style="background: ${color}; border: 2px solid white; border-radius: 50%; width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 8px rgba(0,0,0,0.3); transform: rotate(-45deg);">
                       <i class="bi bi-truck" style="color: white; font-size: 20px; transform: rotate(45deg);"></i>
                       </div>`,
                className: `truck-marker ${className}`,
                iconSize: [40, 40],
                iconAnchor: [20, 20],
                popupAnchor: [0, -20]
            });
        }

        // ============== INITIALIZE MAP ==============
        function initMap() {
            const mapElement = document.getElementById('driverMap');
            if (!mapElement) return;
            
            map = L.map('driverMap').setView([14.5995, 120.9842], 12);
            
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '© OpenStreetMap contributors',
                maxZoom: 19
            }).addTo(map);
            
            console.log('Map initialized');
        }

        // ============== LOAD DATA ==============
        function loadTracking() {
            // Get filter values
            const filterName = document.getElementById('filterName').value;
            const filterBranch = document.getElementById('filterBranch').value;
            const filterTrip = document.getElementById('filterTrip').value;
            const filterStatus = document.getElementById('filterStatus').value;
            
            // Build URL with filters
            let url = 'driver_tracking.php?ajax=1';
            if (filterName) url += '&driverName=' + encodeURIComponent(filterName);
            if (filterBranch) url += '&location=' + encodeURIComponent(filterBranch);
            if (filterTrip) url += '&tripTicket=' + encodeURIComponent(filterTrip);
            if (filterStatus) url += '&status=' + encodeURIComponent(filterStatus);
            
            fetch(url)
                .then(response => {
                    if (!response.ok) throw new Error('HTTP error ' + response.status);
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        updateMap(data.drivers);
                        updateTable(data.drivers);
                        updateTrips(data.trips);
                        updateStats(data.stats);
                        
                        lastUpdateTime = Date.now();
                        document.getElementById('updateTime').textContent = 'just now';
                    }
                })
                .catch(error => {
                    console.error('Error loading data:', error);
                });
        }

        // ============== UPDATE MAP ==============
        function updateMap(drivers) {
            if (!map) return;
            
            // Track which drivers are still active
            let activeIds = new Set();
            
            // Update or add markers
            drivers.forEach(driver => {
                activeIds.add(driver.id);
                
                let icon = getTruckIcon(driver.status);
                let popupContent = createPopupContent(driver);
                
                if (markers[driver.id]) {
                    markers[driver.id].setLatLng([driver.latitude, driver.longitude]);
                    markers[driver.id].setIcon(icon);
                    markers[driver.id].setPopupContent(popupContent);
                } else {
                    let marker = L.marker([driver.latitude, driver.longitude], { icon: icon })
                        .addTo(map)
                        .bindPopup(popupContent);
                    
                    marker.on('click', () => {
                        currentDriverId = driver.id;
                    });
                    
                    markers[driver.id] = marker;
                }
            });
            
            // Remove markers for drivers no longer active
            for (let id in markers) {
                if (!activeIds.has(parseInt(id))) {
                    map.removeLayer(markers[id]);
                    delete markers[id];
                }
            }
        }

        function createPopupContent(driver) {
            let statusText = driver.status.toUpperCase();
            let lastSeen = driver.last_seen ? driver.last_seen + 's ago' : 'Never';
            let speed = driver.speed_kmh ? driver.speed_kmh + ' km/h' : '0 km/h';
            
            return `
                <div style="min-width: 200px;">
                    <strong>${escapeHtml(driver.name)}</strong><br>
                    Vehicle: ${escapeHtml(driver.vehicle_id)}<br>
                    Trip: ${escapeHtml(driver.current_trip || 'None')}<br>
                    Speed: ${speed}<br>
                    Last Seen: ${lastSeen}<br>
                    Status: <span class="badge ${driver.status_badge}">${statusText}</span><br>
                    <button onclick="viewDriver(${driver.id})" class="btn btn-sm btn-primary mt-2 w-100">
                        View Details
                    </button>
                </div>
            `;
        }

        // ============== UPDATE TABLE ==============
        function updateTable(drivers) {
            let tbody = document.getElementById('driversTable');
            
            if (!drivers || drivers.length === 0) {
                tbody.innerHTML = '<tr><td colspan="8" class="text-center py-4">No drivers found</td></tr>';
                document.getElementById('driverCount').textContent = '0';
                return;
            }
            
            let html = '';
            let onlineCount = 0;
            
            drivers.forEach(driver => {
                if (driver.is_online) onlineCount++;
                
                let statusText = driver.status.charAt(0).toUpperCase() + driver.status.slice(1);
                let lastSeen = driver.last_seen ? driver.last_seen + 's ago' : 'Never';
                let indicatorClass = driver.is_online ? (driver.status === 'active' ? 'online' : 'idle') : 'offline';
                
                html += `
                    <tr>
                        <td>${driver.id}</td>
                        <td><strong>${escapeHtml(driver.name)}</strong></td>
                        <td>${escapeHtml(driver.vehicle_id)}</td>
                        <td><span class="location-indicator ${indicatorClass}"></span> ${escapeHtml(driver.current_location)}</td>
                        <td>${escapeHtml(driver.current_trip)}</td>
                        <td><span class="badge ${driver.status_badge}">${statusText}</span></td>
                        <td>${lastSeen}</td>
                        <td>
                            <button class="btn-action btn-map" onclick="focusOnDriver(${driver.id})" title="View on Map">
                                <i class="bi bi-geo-alt-fill"></i>
                            </button>
                            <button class="btn-action btn-view" onclick="viewDriver(${driver.id})" title="View Details">
                                <i class="bi bi-eye"></i>
                            </button>
                        </td>
                    </tr>
                `;
            });
            
            tbody.innerHTML = html;
            document.getElementById('driverCount').textContent = onlineCount;
        }

        // ============== UPDATE TRIPS ==============
        function updateTrips(trips) {
            let tbody = document.getElementById('tripsTable');
            
            if (!trips || trips.length === 0) {
                tbody.innerHTML = '<tr><td colspan="5" class="text-center py-4">No active trips</td></tr>';
                return;
            }
            
            let html = '';
            
            trips.forEach(trip => {
                let departure = trip.departure ? new Date(trip.departure).toLocaleTimeString() : 'N/A';
                
                html += `
                    <tr>
                        <td><strong>${escapeHtml(trip.trip_number)}</strong></td>
                        <td>${escapeHtml(trip.driver_name)}</td>
                        <td>${escapeHtml(trip.origin)}</td>
                        <td><span class="badge ${trip.status_badge}">${escapeHtml(trip.status_text)}</span></td>
                        <td>
                            <button class="btn btn-sm btn-primary" onclick="viewTrip(${trip.trip_id})">
                                <i class="bi bi-eye"></i>
                            </button>
                        </td>
                    </tr>
                `;
            });
            
            tbody.innerHTML = html;
        }

        // ============== UPDATE STATS ==============
        function updateStats(stats) {
            document.getElementById('totalDrivers').textContent = stats.totalDrivers || 0;
            document.getElementById('onlineDrivers').textContent = stats.onlineDrivers || 0;
            document.getElementById('activeDrivers').textContent = stats.activeDrivers || 0;
        }

        // ============== FILTER FUNCTIONS ==============
        function applyFilters() {
            loadTracking();
        }
        
        function clearFilters() {
            document.getElementById('filterName').value = '';
            document.getElementById('filterBranch').value = '';
            document.getElementById('filterTrip').value = '';
            document.getElementById('filterStatus').value = '';
            loadTracking();
        }

        // ============== VIEW FUNCTIONS ==============
        function viewDriver(id) {
            currentDriverId = id;
            
            $('#driverModal').modal('show');
            document.getElementById('driverModalBody').innerHTML = '<p class="text-center">Loading...</p>';
            
            fetch('driver_tracking.php?ajax_details=1&id=' + id)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        let d = data.driver;
                        let statusBadge = d.status_badge || 'bg-secondary';
                        let statusText = d.status || 'unknown';
                        
                        let html = `
                            <dl class="row">
                                <dt class="col-sm-4">Name:</dt><dd class="col-sm-8"><strong>${escapeHtml(d.name)}</strong></dd>
                                <dt class="col-sm-4">License:</dt><dd class="col-sm-8">${escapeHtml(d.license_no)}</dd>
                                <dt class="col-sm-4">Expiry:</dt><dd class="col-sm-8">${escapeHtml(d.license_expiry)}</dd>
                                <dt class="col-sm-4">Phone:</dt><dd class="col-sm-8">${escapeHtml(d.phone)}</dd>
                                <dt class="col-sm-4">Vehicle:</dt><dd class="col-sm-8">${escapeHtml(d.vehicle_id)} (${escapeHtml(d.vehicle_type)})</dd>
                                <dt class="col-sm-4">Branch:</dt><dd class="col-sm-8">${escapeHtml(d.branch)}</dd>
                                <dt class="col-sm-4">Location:</dt><dd class="col-sm-8">${escapeHtml(d.current_location)}</dd>
                                <dt class="col-sm-4">Current Trip:</dt><dd class="col-sm-8">${escapeHtml(d.current_trip)}</dd>
                                <dt class="col-sm-4">Status:</dt><dd class="col-sm-8"><span class="badge ${statusBadge}">${escapeHtml(statusText)}</span></dd>
                                <dt class="col-sm-4">Online:</dt><dd class="col-sm-8">${d.is_online ? 'Yes' : 'No'}</dd>
                                <dt class="col-sm-4">Last Seen:</dt><dd class="col-sm-8">${d.last_seen ? d.last_seen + ' seconds ago' : 'Never'}</dd>
                                <dt class="col-sm-4">Trips Today:</dt><dd class="col-sm-8">${d.trips_completed_today}</dd>
                                <dt class="col-sm-4">Last Update:</dt><dd class="col-sm-8">${escapeHtml(d.last_location_update)}</dd>
                                <dt class="col-sm-4">Speed:</dt><dd class="col-sm-8">${d.speed ? d.speed + ' km/h' : '0 km/h'}</dd>
                            </dl>
                        `;
                        document.getElementById('driverModalBody').innerHTML = html;
                        document.getElementById('focusMapBtn').style.display = 'inline-block';
                    } else {
                        document.getElementById('driverModalBody').innerHTML = '<p class="text-danger">Failed to load driver details</p>';
                    }
                })
                .catch(error => {
                    document.getElementById('driverModalBody').innerHTML = '<p class="text-danger">Error loading details</p>';
                });
        }

        function viewTrip(id) {
            $('#tripModal').modal('show');
            document.getElementById('tripModalBody').innerHTML = '<p class="text-center">Loading...</p>';
            
            fetch('driver_tracking.php?ajax_trip=1&id=' + id)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        let t = data.trip;
                        let statusBadge = t.status === 'in-progress' ? 'bg-success' : 'bg-info';
                        
                        let html = `
                            <dl class="row">
                                <dt class="col-sm-4">Trip #:</dt><dd class="col-sm-8"><strong>${escapeHtml(t.trip_number)}</strong></dd>
                                <dt class="col-sm-4">Driver:</dt><dd class="col-sm-8">${escapeHtml(t.driver_name)}</dd>
                                <dt class="col-sm-4">Vehicle:</dt><dd class="col-sm-8">${escapeHtml(t.vehicle)}</dd>
                                <dt class="col-sm-4">Origin:</dt><dd class="col-sm-8">${escapeHtml(t.origin)}</dd>
                                <dt class="col-sm-4">Departure:</dt><dd class="col-sm-8">${escapeHtml(t.departure)}</dd>
                                <dt class="col-sm-4">Status:</dt><dd class="col-sm-8"><span class="badge ${statusBadge}">${escapeHtml(t.status)}</span></dd>
                                <dt class="col-sm-4">Total Stops:</dt><dd class="col-sm-8">${t.total_stops}</dd>
                            </dl>
                        `;
                        
                        if (t.deliveries && t.deliveries.length > 0) {
                            html += '<h6 class="mt-4">Delivery Stops</h6>';
                            html += '<div class="table-responsive">';
                            html += '<table class="table table-sm table-bordered">';
                            html += '<thead><tr><th>Stop</th><th>Customer</th><th>Address</th><th>SO #</th><th>Status</th></tr></thead>';
                            html += '<tbody>';
                            
                            t.deliveries.forEach(d => {
                                let delStatusBadge = 'bg-info';
                                if (d.delivery_status === 'delivered') delStatusBadge = 'bg-success';
                                else if (d.delivery_status === 'rejected') delStatusBadge = 'bg-danger';
                                else if (d.delivery_status === 'partial') delStatusBadge = 'bg-warning';
                                
                                html += `
                                    <tr>
                                        <td>${d.stop_sequence || '-'}</td>
                                        <td>${escapeHtml(d.customer_name)}</td>
                                        <td><small>${escapeHtml(d.address)}</small></td>
                                        <td>${escapeHtml(d.so_number)}</td>
                                        <td><span class="badge ${delStatusBadge}">${escapeHtml(d.delivery_status)}</span></td>
                                    </tr>
                                `;
                            });
                            
                            html += '</tbody></table></div>';
                        }
                        
                        document.getElementById('tripModalBody').innerHTML = html;
                    } else {
                        document.getElementById('tripModalBody').innerHTML = '<p class="text-danger">Failed to load trip details</p>';
                    }
                });
        }

        function focusOnDriver(id) {
            if (markers[id]) {
                let latlng = markers[id].getLatLng();
                map.setView([latlng.lat, latlng.lng], 16);
                markers[id].openPopup();
            }
        }

        function focusOnMapFromModal() {
            if (currentDriverId) {
                $('#driverModal').modal('hide');
                setTimeout(() => focusOnDriver(currentDriverId), 500);
            }
        }

        // ============== HELPER FUNCTIONS ==============
        function escapeHtml(text) {
            if (!text) return '';
            return String(text)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function logout() {
            window.location.href = '../logout.php';
        }

        // ============== SIDEBAR FUNCTIONS ==============
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const isMobile = window.innerWidth <= 992;
            
            if (isMobile) {
                sidebar.classList.toggle('active');
                if (sidebar.classList.contains('active')) {
                    document.body.style.overflow = 'hidden';
                } else {
                    document.body.style.overflow = '';
                }
            } else {
                sidebar.classList.toggle('collapsed');
                localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
            }
        }

        function closeMobileSidebar() {
            document.getElementById('sidebar').classList.remove('active');
            document.body.style.overflow = '';
        }

        function initializeSidebar() {
            const sidebar = document.getElementById('sidebar');
            
            if (window.innerWidth > 992) {
                const saved = localStorage.getItem('sidebarCollapsed');
                if (saved === 'true') {
                    sidebar.classList.add('collapsed');
                }
            }
        }

        // ============== INITIALIZATION ==============
        document.addEventListener('DOMContentLoaded', function() {
            initializeSidebar();
            
            document.getElementById('mobileToggleBtn').addEventListener('click', toggleSidebar);
            document.getElementById('desktopToggleBtn').addEventListener('click', toggleSidebar);
            
            // Close sidebar when clicking outside on mobile
            document.addEventListener('click', function(event) {
                const sidebar = document.getElementById('sidebar');
                const mobileBtn = document.getElementById('mobileToggleBtn');
                
                if (window.innerWidth <= 992 && 
                    sidebar.classList.contains('active') && 
                    !sidebar.contains(event.target) && 
                    !mobileBtn.contains(event.target)) {
                    closeMobileSidebar();
                }
            });
            
            window.addEventListener('resize', () => {
                if (window.innerWidth > 992) closeMobileSidebar();
            });
            
            initMap();
            loadTracking();
            
            refreshInterval = setInterval(loadTracking, 5000);
            
            setInterval(() => {
                let seconds = Math.floor((Date.now() - lastUpdateTime) / 1000);
                document.getElementById('updateTime').textContent = seconds + 's ago';
            }, 1000);
        });

        window.addEventListener('beforeunload', () => {
            if (refreshInterval) clearInterval(refreshInterval);
        });
    </script>
</body>
</html>
<?php $conn->close(); ?>