<?php
require_once '../config/database.php';
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

// Get user info for display
$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? $_SESSION['first_name'] . ' ' . $_SESSION['last_name'] ?? 'User';
$user_role = $_SESSION['role'] ?? 'global';
$view_all_branches = $_SESSION['view_all_branches'] ?? true;

// Get user's branch name for display (if applicable)
$branch_name = 'All Branches';
$user_branch_id = $_SESSION['branch_id'] ?? 0;
if (!$view_all_branches && $user_branch_id > 0) {
    $branch_query = "SELECT branch_name FROM branches WHERE branch_id = ?";
    $branch_stmt = $conn->prepare($branch_query);
    $branch_stmt->bind_param("i", $user_branch_id);
    $branch_stmt->execute();
    $branch_result = $branch_stmt->get_result();
    if ($branch_row = $branch_result->fetch_assoc()) {
        $branch_name = $branch_row['branch_name'];
    }
    $branch_stmt->close();
}

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
        
        // Apply branch filter based on user permissions
        if (!$view_all_branches && $user_branch_id > 0) {
            $drivers_sql .= " AND d.branch_id = ?";
            $params[] = $user_branch_id;
            $types .= "i";
        }
        
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
                    LEFT JOIN deliveries del ON tt.trip_id = del.trip_id";
        
        // Apply branch filter to trips based on user permissions
        if (!$view_all_branches && $user_branch_id > 0) {
            $trips_sql .= " WHERE tt.branch_id = ?";
            $trip_params[] = $user_branch_id;
        }
        
        $trips_sql .= " GROUP BY tt.trip_id
                        ORDER BY tt.start_time DESC
                        LIMIT 20";
        
        $trip_stmt = $conn->prepare($trips_sql);
        
        if (!$view_all_branches && $user_branch_id > 0 && isset($trip_params)) {
            $trip_stmt->bind_param("i", $user_branch_id);
        }
        
        $trip_stmt->execute();
        $trips_result = $trip_stmt->get_result();
        
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
        
        $trip_stmt->close();
        
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
        /* Status badges */
        .badge.bg-success {
            background-color: #28a745 !important;
            color: #fff !important;
        }
        .badge.bg-warning {
            background-color: #ffc107 !important;
            color: #fff !important;
        }
        .badge.bg-secondary {
            background-color: #6c757d !important;
            color: #fff !important;
        }
        .badge.bg-info {
            background-color: #17a2b8 !important;
            color: #fff !important;
        }

        
        @keyframes pulse {
            0% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.5; transform: scale(1.2); }
            100% { opacity: 1; transform: scale(1); }
        }
        
        /* Map Container */
        #driverMap {
            width: 100%;
            height: 500px;
            border-radius: 8px;
            overflow: hidden;
        }
        /* Mobile Profile Modal Styles */
        .user-avatar-large {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: linear-gradient(135deg, #047857, #44D34E);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.5rem;
            font-weight: bold;
            margin: 0 auto;
            border: 4px solid #d1fae5;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        #profileModal .modal-content {
            border: none;
            border-radius: 20px;
            overflow: hidden;
        }

        #profileModal .modal-header {
            background: linear-gradient(135deg, #047857, #44D34E);
            color: white;
            border-bottom: none;
            padding: 1.5rem;
        }

        #profileModal .modal-header .modal-title {
            color: white;
            font-weight: 600;
        }

        #profileModal .modal-header .btn-close {
            filter: brightness(0) invert(1);
            opacity: 0.9;
        }

        #profileModal .modal-header .btn-close:hover {
            opacity: 1;
            transform: rotate(90deg);
        }

        #profileModal .modal-body {
            padding: 2rem;
            background: linear-gradient(135deg, #f9fefc 0%, #f0fdf4 100%);
        }

        #profileModal .branch-info {
            background: #d1fae5;
            color: #047857;
            padding: 0.5rem 1rem;
            border-radius: 50px;
            display: inline-block;
            font-weight: 500;
        }

        #profileModal .btn-danger {
            background: linear-gradient(135deg, #dc3545, #f87171);
            border: none;
            padding: 1rem;
            border-radius: 50px;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        #profileModal .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(220, 53, 69, 0.3);
        }

        /* Mobile Logout Button in Bottom Nav */
        .mobile-nav .nav-link.logout-btn {
            color: #dc3545;
        }

        .mobile-nav .nav-link.logout-btn i {
            color: #dc3545;
        }

        .mobile-nav .nav-link.logout-btn.active,
        .mobile-nav .nav-link.logout-btn:hover {
            background: rgba(220, 53, 69, 0.1);
            color: #dc3545;
        }

        .mobile-nav .nav-link.logout-btn.active i,
        .mobile-nav .nav-link.logout-btn:hover i {
            color: #dc3545;
        }
        /* ===== UNIFIED STAT CARD STYLES - PARA MAGKAPAREHO LAHAT ===== */

/* Base styles - gaya ng sa All Items Catalog */
.stat-card {
    border: none;
    border-radius: 12px;
    color: white;
    padding: 1rem;
    margin: 0;
    transition: all 0.3s ease;
    box-shadow: 0 4px 10px rgba(0, 0, 0, 0.08);
    height: 100%;
    width: 100%;
    display: flex;
    align-items: center;
    justify-content: flex-start;
    gap: 1rem;
}

/* Icons - gaya ng sa All Items Catalog */
.stat-card i {
    font-size: 2rem;
    opacity: 0.9;
}

/* Content container */
.stat-content {
    display: flex;
    flex-direction: column;
}

/* Value - gaya ng sa All Items Catalog (mas maliit) */
.stat-value {
    font-size: 1.5rem;
    font-weight: 700;
    line-height: 1.2;
    margin: 0;
}

/* Label - gaya ng sa All Items Catalog */
.stat-label {
    font-size: 0.8rem;
    font-weight: 500;
    opacity: 0.9;
    margin-top: 0.1rem;
}

/* ===== RESPONSIVE ADJUSTMENTS ===== */

/* Tablet */
@media (max-width: 768px) {
    .stat-card {
        padding: 0.8rem;
        gap: 0.8rem;
    }
    .stat-card i {
        font-size: 1.6rem;
    }
    .stat-value {
        font-size: 1.3rem;
    }
    .stat-label {
        font-size: 0.7rem;
    }
}

/* Mobile */
@media (max-width: 576px) {
    .stat-card {
        padding: 0.6rem;
        gap: 0.6rem;
    }
    .stat-card i {
        font-size: 1.4rem;
    }
    .stat-value {
        font-size: 1.1rem;
    }
    .stat-label {
        font-size: 0.65rem;
    }
}

/* Small mobile */
@media (max-width: 400px) {
    .stat-card {
        padding: 0.5rem;
        gap: 0.5rem;
    }
    .stat-card i {
        font-size: 1.2rem;
    }
    .stat-value {
        font-size: 1rem;
    }
    .stat-label {
        font-size: 0.6rem;
    }
}

/* Landscape mode */
@media (max-height: 500px) and (orientation: landscape) {
    .stat-card {
        padding: 0.4rem;
        gap: 0.4rem;
    }
    .stat-card i {
        font-size: 1rem;
    }
    .stat-value {
        font-size: 0.9rem;
    }
    .stat-label {
        font-size: 0.55rem;
    }
}
/* ===== MOBILE-FIRST FILTER STYLES ===== */

/* Form Card */
.form-card {
    background: white;
    border-radius: 16px;
    padding: 1.25rem;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.03);
    border: 1px solid #eef2f6;
}

.form-card h5 {
    font-size: 1rem;
    font-weight: 600;
        color: var(--dark-green);
    margin-bottom: 1rem;
}
.form-card h5 i {
    color: var(--primary-green);
    background: rgba(68, 211, 78, 0.1);
    padding: clamp(0.3rem, 1.5vw, 0.5rem);
    border-radius: clamp(6px, 2vw, 10px);
    font-size: clamp(0.9rem, 3.5vw, 1.2rem);
}

/* Form Labels */
.form-card .form-label {
    font-size: 0.75rem;
    font-weight: 600;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    margin-bottom: 0.25rem;
}

/* Form Controls */
.form-card .form-control,
.form-card .form-select {
    border: 1.5px solid #e2e8f0;
    border-radius: 10px;
    padding: 0.6rem 0.75rem;
    font-size: 0.9rem;
    height: 44px;
    background-color: #fff;
    transition: all 0.2s ease;
}

.form-card .form-control:focus,
.form-card .form-select:focus {
    border-color: var(--primary-green);
    box-shadow: 0 0 0 3px rgba(68, 211, 78, 0.15);
    outline: none;
}

/* Buttons */
.form-card .btn {
    border-radius: 10px;
    padding: 0.6rem 1rem;
    font-size: 0.9rem;
    font-weight: 500;
    height: 44px;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s ease;
}

.form-card .btn-primary {
    background: var(--primary-green);
    border: none;
    color: white;
}

.form-card .btn-outline-secondary {
    border: 1.5px solid #e2e8f0;
    color: #64748b;
    background: white;
}

/* ===== MOBILE SPECIFIC (up to 768px) ===== */
@media (max-width: 768px) {
    .form-card {
        padding: 1rem;
        border-radius: 14px;
    }
    
    .form-card h5 {
        font-size: 0.95rem;
        margin-bottom: 0.75rem;
    }
    
    /* Magkatabi ang Branch at Status (50% each) */
    .form-card .col-6 {
        width: 50%;
        padding-left: 4px;
        padding-right: 4px;
    }
    
    /* Driver Name at Trip Ticket - full width */
    .form-card .col-12 {
        width: 100%;
        padding-left: 4px;
        padding-right: 4px;
    }
    
    /* Adjust row spacing */
    .form-card .row {
        margin-left: -4px;
        margin-right: -4px;
    }
    
    /* Smaller form controls sa mobile */
    .form-card .form-control,
    .form-card .form-select {
        padding: 0.5rem 0.6rem;
        font-size: 0.85rem;
        height: 42px;
        border-radius: 8px;
    }
    
    .form-card .form-label {
        font-size: 0.7rem;
        margin-bottom: 0.2rem;
    }
    
    /* Buttons sa mobile */
    .form-card .btn {
        padding: 0.5rem 0.75rem;
        font-size: 0.85rem;
        height: 42px;
        border-radius: 8px;
    }
    
    .form-card .btn i {
        font-size: 0.9rem;
    }
    
    /* Bawasan ang spacing */
    .form-card .mb-3 {
        margin-bottom: 0.75rem !important;
    }
    
    .form-card .g-2 {
        --bs-gutter-y: 0.5rem;
    }
}

/* Small phones (576px and below) */
@media (max-width: 576px) {
    .form-card {
        padding: 0.875rem;
    }
    
    .form-card h5 {
        font-size: 0.9rem;
        margin-bottom: 0.5rem;
    }
    
    /* Mas maliit pa para sa small phones */
    .form-card .form-control,
    .form-card .form-select {
        padding: 0.4rem 0.5rem;
        font-size: 0.8rem;
        height: 40px;
    }
    
    .form-card .form-label {
        font-size: 0.65rem;
    }
    
    .form-card .btn {
        padding: 0.4rem 0.5rem;
        font-size: 0.8rem;
        height: 40px;
    }
    
    .form-card .btn i {
        font-size: 0.8rem;
        margin-right: 0.25rem;
    }
}

/* Very small phones (400px and below) */
@media (max-width: 400px) {
    .form-card {
        padding: 0.75rem;
    }
    
    .form-card .form-control,
    .form-card .form-select {
        padding: 0.35rem 0.4rem;
        font-size: 0.75rem;
        height: 38px;
    }
    
    .form-card .form-label {
        font-size: 0.6rem;
    }
    
    .form-card .btn {
        padding: 0.35rem 0.4rem;
        font-size: 0.75rem;
        height: 38px;
    }
}

/* Tablet (768px to 992px) */
@media (min-width: 768px) and (max-width: 992px) {
    .form-card {
        padding: 1.15rem;
    }
    
    /* Sa tablet: 2 columns */
    .form-card [class*="col-md-6"] {
        width: 50%;
        margin-bottom: 0.5rem;
    }
}

/* Desktop (992px and above) */
@media (min-width: 992px) {
    .form-card {
        padding: 1.25rem;
    }
    
    /* Sa desktop: 4 columns */
    .form-card [class*="col-lg-3"] {
        width: 25%;
    }
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
                            <i class="bi bi-people"></i>
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
<div class="row stat-card-row g-1 g-sm-2 mb-4">
    <!-- Total Drivers -->
    <div class="col">
        <div class="stat-card total">
            <i class="bi bi-people"></i>
            <div class="stat-content">
                <div class="stat-value" id="totalDrivers">0</div>
                <div class="stat-label">Total Drivers</div>
            </div>
        </div>
    </div>
    
    <!-- Online Now -->
    <div class="col">
        <div class="stat-card sales">
            <i class="bi bi-wifi"></i>
            <div class="stat-content">
                <div class="stat-value" id="onlineDrivers">0</div>
                <div class="stat-label">Online Now</div>
            </div>
        </div>
    </div>
    
    <!-- On Delivery -->
    <div class="col">
        <div class="stat-card complete">
            <i class="bi bi-truck"></i>
            <div class="stat-content">
                <div class="stat-value" id="activeDrivers">0</div>
                <div class="stat-label">On Delivery</div>
            </div>
        </div>
    </div>
    
    <!-- Last Update -->
    <div class="col">
        <div class="stat-card inventory">
            <i class="bi bi-clock-history"></i>
            <div class="stat-content">
                <div class="stat-value" id="updateTime">-</div>
                <div class="stat-label">Last Update</div>
            </div>
        </div>
    </div>
</div>
<!-- FILTER SECTION - DRIVER TRACKING -->
<div class="form-card mb-4">
    <div class="filter-header">
        <h5 class="mb-0">
            <i class="bi bi-funnel"></i> Filter Drivers
        </h5>
        <button class="filter-toggle-btn" id="toggleDriverFilter" onclick="toggleFilter('driver')" title="Toggle Filter">
            <i class="bi bi-chevron-down" id="driverFilterIcon"></i>
        </button>
    </div>
    <div class="filter-content" id="driverFilterContent">
        <!-- Filter Fields -->
        <div class="row g-2 g-md-3 mt-3 mb-3">
            <!-- Driver Name -->
            <div class="col-12 col-md-6 col-lg-3">
                <label class="form-label">Driver Name</label>
                <input type="text" class="form-control" id="filterName" placeholder="Enter driver name...">
            </div>
            
            <!-- Branch -->
            <div class="col-6 col-md-6 col-lg-3">
                <label class="form-label">Branch</label>
                <select class="form-select" id="filterBranch">
                    <option value="">All Branches</option>
                    <?php foreach ($branches as $branch): ?>
                    <option value="<?php echo htmlspecialchars($branch['branch_name']); ?>">
                        <?php echo htmlspecialchars($branch['branch_name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <!-- Status -->
            <div class="col-6 col-md-6 col-lg-3">
                <label class="form-label">Status</label>
                <select class="form-select" id="filterStatus">
                    <option value="">All Status</option>
                    <option value="active">Active (On Delivery)</option>
                    <option value="idle">Idle (Online)</option>
                    <option value="offline">Offline</option>
                </select>
            </div>
            
            <!-- Trip Ticket -->
            <div class="col-12 col-md-6 col-lg-3">
                <label class="form-label">Trip Ticket</label>
                <input type="text" class="form-control" id="filterTrip" placeholder="Enter trip number...">
            </div>
        </div>
        
        <!-- Filter Buttons -->
        <div class="row g-2">
            <div class="col-6">
                <button class="btn btn-primary w-100" onclick="applyFilters()">
                    <i class="bi bi-funnel me-1"></i> Apply Filters
                </button>
            </div>
            <div class="col-6">
                <button class="btn btn-outline-secondary w-100" onclick="clearFilters()">
                    <i class="bi bi-x-circle me-1"></i> Clear Filters
                </button>
            </div>
        </div>
    </div>
</div>
            <!-- Map Container -->
<div class="data-table mb-4">
    <div class="table-header">
        <h5><i class="bi bi-map"></i> Live Driver Locations</h5>
        <div class="status-indicators">
            <span><span class="location-indicator online"></span> Online (Active)</span>
            <span><span class="location-indicator idle"></span> Online (Idle)</span>
            <span><span class="location-indicator offline"></span> Offline</span>
        </div>
    </div>
    <div id="driverMap"></div>
</div>

          <!-- Drivers Table -->
<div class="data-table">
    <div class="table-header">
        <h5><i class="bi bi-list"></i> Live Driver Locations</h5>
        <div class="status-indicators">
            <span class="badge bg-primary" id="driverCount">0</span>
        </div>
    </div>
    <div class="table-container">
        <table class="table custom-table compact-table" id="itemsTable">
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
                 <div class="table-container">
                        <table class="table custom-table compact-table" id="itemsTable">
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

    <!-- Mobile Bottom Navigation -->
    <div class="mobile-nav" id="mobileNav">
        <ul class="nav">
            <li class="nav-item">
                <a class="nav-link" href="sales_reports.php">
                    <i class="bi bi-graph-up"></i>
                    <span>Reports</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="branch_records.php">
                    <i class="bi bi-file-text"></i>
                    <span>Records</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="all_items.php">
                    <i class="bi bi-box"></i>
                    <span>Items</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link active" href="drivers.php">
                    <i class="bi bi-people"></i>
                    <span>Users</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="trip_tickets.php">
                    <i class="bi bi-ticket-perforated"></i>
                    <span>Tickets</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="driver_tracking.php">
                    <i class="bi bi-geo-alt"></i>
                    <span>Tracking</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link logout-btn" href="#" onclick="showProfileModal(); return false;">
                    <i class="bi bi-box-arrow-right"></i>
                    <span>Logout</span>
                </a>
            </li>
        </ul>
    </div>

    <!-- Mobile Profile/Logout Modal -->
    <div class="modal fade" id="profileModal" tabindex="-1" aria-labelledby="profileModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="profileModalLabel">
                        <i class="bi bi-person-circle me-2"></i>User Profile
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-center">
                    <!-- User Avatar -->
                    <div class="user-avatar-large mb-3">
                        <?php echo $initials; ?>
                    </div>
                    
                    <!-- User Name -->
                    <h4 class="mb-1"><?php echo htmlspecialchars($user_name); ?></h4>
                    
                    <!-- User Role -->
                    <p class="text-muted mb-3">
                        <span class="badge bg-success"><?php echo ucfirst($user_role); ?></span>
                    </p>
                    
                    <!-- Branch Info (if applicable) -->
                    <?php if (!$view_all_branches && $user_branch_id > 0): ?>
                    <div class="branch-info mb-3">
                        <i class="bi bi-building me-1"></i>
                        <span><?php echo htmlspecialchars($branch_name); ?></span>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Logout Button -->
                    <button class="btn btn-danger btn-lg w-100" onclick="confirmLogout()">
                        <i class="bi bi-box-arrow-right me-2"></i>Logout
                    </button>
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
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
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
                    <button onclick="viewDriver(${driver.id})" class="btn-action btn-view">
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
                            <button class="btn-action btn-view" onclick="viewTrip(${trip.trip_id})">
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

        // ============== PROFILE/LOGOUT FUNCTIONS ==============
        function showProfileModal() {
            const profileModal = new bootstrap.Modal(document.getElementById('profileModal'));
            profileModal.show();
        }

        function confirmLogout() {
            // Close the modal first
            const modal = bootstrap.Modal.getInstance(document.getElementById('profileModal'));
            if (modal) {
                modal.hide();
            }
            
            // Show confirmation dialog
            Swal.fire({
                title: 'Are you sure?',
                text: 'You will be logged out of the system',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, logout'
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = '../logout.php';
                }
            });
        }

        function logout() {
            Swal.fire({
                title: 'Are you sure?',
                text: 'You will be logged out of the system',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#07d826',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, logout'
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = '../logout.php';
                }
            });
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

        // ============== MOBILE NAVIGATION FUNCTIONS =================
        function initMobileNav() {
            const mobileNav = document.getElementById('mobileNav');
            const isMobile = window.innerWidth <= 992;
            
            if (isMobile) {
                mobileNav.style.display = 'block';
                
                // Set active state based on current page (excluding logout)
                const currentPage = window.location.pathname.split('/').pop();
                const navLinks = mobileNav.querySelectorAll('.nav-link:not(.logout-btn)');
                
                navLinks.forEach(link => {
                    link.classList.remove('active');
                    const href = link.getAttribute('href');
                    if (currentPage === href) {
                        link.classList.add('active');
                    }
                });
            } else {
                mobileNav.style.display = 'none';
            }
        }

        // ============== INITIALIZATION ==============
        document.addEventListener('DOMContentLoaded', function() {
            initializeSidebar();
            initMobileNav();
            
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
            
            window.addEventListener('resize', function() {
                if (window.innerWidth > 992) closeMobileSidebar();
                initMobileNav();
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
        
        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl + B to toggle sidebar (desktop only)
            if (e.ctrlKey && e.key === 'b' && window.innerWidth > 992) {
                e.preventDefault();
                toggleSidebar();
            }
            // Escape to close sidebar on mobile
            else if (e.key === 'Escape' && window.innerWidth <= 992) {
                closeMobileSidebar();
            }
            // Escape to close modals
            else if (e.key === 'Escape') {
                const profileModal = document.getElementById('profileModal');
                if (profileModal.classList.contains('show')) {
                    bootstrap.Modal.getInstance(profileModal).hide();
                }
            }
        });

        // ================= FILTER TOGGLE FUNCTIONS =================
// Toggle filter section visibility with localStorage
function toggleFilter(filterType) {
    const contentId = filterType + 'FilterContent';
    const iconId = filterType + 'FilterIcon';
    
    const content = document.getElementById(contentId);
    const icon = document.getElementById(iconId);
    
    if (content && icon) {
        if (content.classList.contains('collapsed')) {
            // Show filter
            content.classList.remove('collapsed');
            icon.style.transform = 'rotate(0deg)';
            localStorage.setItem(filterType + 'FilterHidden', 'false');
        } else {
            // Hide filter
            content.classList.add('collapsed');
            icon.style.transform = 'rotate(-90deg)';
            localStorage.setItem(filterType + 'FilterHidden', 'true');
        }
    }
}

// ================= FILTER TOGGLE FUNCTIONS =================
// Toggle filter section visibility with localStorage
function toggleFilter(filterType) {
    const contentId = filterType + 'FilterContent';
    const iconId = filterType + 'FilterIcon';
    
    const content = document.getElementById(contentId);
    const icon = document.getElementById(iconId);
    
    if (content && icon) {
        if (content.classList.contains('collapsed')) {
            // Show filter
            content.classList.remove('collapsed');
            icon.style.transform = 'rotate(0deg)';
            localStorage.setItem(filterType + 'FilterHidden', 'false');
        } else {
            // Hide filter
            content.classList.add('collapsed');
            icon.style.transform = 'rotate(-90deg)';
            localStorage.setItem(filterType + 'FilterHidden', 'true');
        }
    }
}

// ================= FILTER TOGGLE FUNCTIONS =================
// Toggle filter section visibility with localStorage
function toggleFilter(filterType) {
    const contentId = filterType + 'FilterContent';
    const iconId = filterType + 'FilterIcon';
    
    const content = document.getElementById(contentId);
    const icon = document.getElementById(iconId);
    
    if (content && icon) {
        if (content.classList.contains('collapsed')) {
            // Show filter
            content.classList.remove('collapsed');
            icon.style.transform = 'rotate(0deg)';
            localStorage.setItem(filterType + 'FilterHidden', 'false');
        } else {
            // Hide filter
            content.classList.add('collapsed');
            icon.style.transform = 'rotate(-90deg)';
            localStorage.setItem(filterType + 'FilterHidden', 'true');
        }
    }
}

// Initialize filter states on page load - DEFAULT CLOSED
function initFilterStates() {
    const filterTypes = ['sales', 'branch', 'items', 'driver', 'trip'];
    
    filterTypes.forEach(type => {
        const contentId = type + 'FilterContent';
        const iconId = type + 'FilterIcon';
        
        const content = document.getElementById(contentId);
        const icon = document.getElementById(iconId);
        
        if (content && icon) {
            // DEFAULT: CLOSED sa simula
            content.classList.add('collapsed');
            icon.style.transform = 'rotate(-90deg)';
            
            // Save sa localStorage na closed para consistent
            localStorage.setItem(type + 'FilterHidden', 'true');
        }
    });
}

// Call this sa loob ng DOMContentLoaded
document.addEventListener('DOMContentLoaded', function() {
    // ... existing code ...
    
    // Initialize filter states - lahat closed
    initFilterStates();
});
    </script>
</body>
</html>
<?php $conn->close(); ?>