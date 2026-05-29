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

        $drivers_sql = "SELECT 
                            d.driver_id,
                            d.driver_name,
                            d.contact_number,
                            d.vehicle_type,
                            d.vehicle_plate_number,
                            d.status as driver_status,
                            b.branch_name,
                            b.city,
                            dt.latitude,
                            dt.longitude,
                            dt.speed_kmh,
                            dt.location_timestamp as last_update,
                            TIMESTAMPDIFF(SECOND, dt.location_timestamp, NOW()) as seconds_ago,
                            tt.trip_id,
                            tt.trip_number,
                            tt.trip_status
                        FROM drivers d
                        LEFT JOIN branches b ON d.branch_id = b.branch_id
                        LEFT JOIN (
                            SELECT dt1.*
                            FROM driver_tracking dt1
                            INNER JOIN (
                                SELECT driver_id, MAX(location_timestamp) as max_update
                                FROM driver_tracking
                                GROUP BY driver_id
                            ) dt2 ON dt1.driver_id = dt2.driver_id AND dt1.location_timestamp = dt2.max_update
                        ) dt ON d.driver_id = dt.driver_id
                        LEFT JOIN (
                            SELECT t1.*
                            FROM trip_tickets t1
                            WHERE t1.trip_status IN ('in-progress', 'planned')
                            AND t1.trip_id = (
                                SELECT trip_id
                                FROM trip_tickets t2
                                WHERE t2.driver_id = t1.driver_id
                                AND t2.trip_status IN ('in-progress', 'planned')
                                ORDER BY CASE t2.trip_status WHEN 'in-progress' THEN 1 WHEN 'planned' THEN 2 ELSE 3 END ASC
                                LIMIT 1
                            )
                        ) tt ON d.driver_id = tt.driver_id
                        WHERE 1=1";
        
        $params = [];
        $types = "";
        if (!$view_all_branches && $user_branch_id > 0) {
            $drivers_sql .= " AND d.branch_id = ?";
            $params[] = $user_branch_id;
            $types .= "i";
        }
        
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
        $processed_drivers = [];
        
        while ($row = $drivers_result->fetch_assoc()) {
            if (in_array($row['driver_id'], $processed_drivers)) {
                continue;
            }
            $processed_drivers[] = $row['driver_id'];
            $total_drivers++;
            
            $is_online = false;
            $has_location = false;
            $status_badge = 'bg-secondary';
            $tracking_status = 'offline';
            $last_seen = null;
            $speed = 0;
            $location_text = 'No location';
            $latitude = null;
            $longitude = null;
            
            if (!empty($row['last_update']) && isset($row['seconds_ago'])) {
                $seconds_ago = intval($row['seconds_ago']);
                $last_seen = $seconds_ago;
                
                if ($seconds_ago <= 70) {
                    $is_online = true;
                    $online_drivers++;
                    
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
            
            if (!empty($row['latitude']) && !empty($row['longitude'])) {
                $has_location = true;
                $latitude = floatval($row['latitude']);
                $longitude = floatval($row['longitude']);
                $speed = floatval($row['speed_kmh'] ?? 0);
                $location_text = sprintf('%.4f, %.4f', $latitude, $longitude);
            } elseif ($row['branch_name']) {
                $location_text = $row['branch_name'] . ', ' . ($row['city'] ?? '');
                $latitude = 13.9170;
                $longitude = 121.0500;
            } else {
                $latitude = 13.9170;
                $longitude = 121.0500;
            }
            
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
                'latitude' => $latitude ?: 13.9170,
                'longitude' => $longitude ?: 121.0500,
                'current_trip' => $row['trip_number'] ?? '-',
                'destination' => 'N/A',
                'status' => $tracking_status,
                'status_badge' => $status_badge,
                'last_update' => $row['last_update'] ?? null,
                'speed_kmh' => round($speed),
                'last_seen' => $last_seen,
                'is_online' => $is_online,
                'has_location' => $has_location,
                'driver_status' => $row['driver_status']
            ];
        }
        
        $stmt->close();
        
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
        
        $trip_params = [];
        $trip_types = "";
        if (!$view_all_branches && $user_branch_id > 0) {
            $trips_sql .= " WHERE tt.branch_id = ?";
            $trip_params[] = $user_branch_id;
            $trip_types .= "i";
        }
        
        $trips_sql .= " GROUP BY tt.trip_id
                        ORDER BY tt.start_time DESC
                        LIMIT 20";
        
        $trip_stmt = $conn->prepare($trips_sql);
        
        if (!empty($trip_params)) {
            $trip_stmt->bind_param($trip_types, ...$trip_params);
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
                    dt.latitude,
                    dt.longitude,
                    dt.speed_kmh as speed,
                    dt.location_timestamp as last_update,
                    TIMESTAMPDIFF(SECOND, dt.location_timestamp, NOW()) as seconds_ago,
                    tt.trip_id as current_trip_id,
                    tt.trip_number as current_trip_number,
                    tt.trip_status
                FROM drivers d
                LEFT JOIN branches b ON d.branch_id = b.branch_id
                LEFT JOIN (
                    SELECT dt1.*
                    FROM driver_tracking dt1
                    INNER JOIN (
                        SELECT driver_id, MAX(location_timestamp) as max_update
                        FROM driver_tracking
                        GROUP BY driver_id
                    ) dt2 ON dt1.driver_id = dt2.driver_id AND dt1.location_timestamp = dt2.max_update
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
            $trips_sql = "SELECT COUNT(*) as trips_completed
                         FROM trip_tickets
                         WHERE driver_id = ? AND trip_date = ? AND trip_status = 'completed'";
            $trips_stmt = $conn->prepare($trips_sql);
            $trips_stmt->bind_param("is", $driver_id, $today);
            $trips_stmt->execute();
            $trips_result = $trips_stmt->get_result();
            $trips_row = $trips_result->fetch_assoc();
            
            $is_online = false;
            $last_seen = null;
            $tracking_status = 'offline';
            $status_badge = 'bg-secondary';
            
            if (!empty($row['last_update'])) {
                $seconds_ago = intval($row['seconds_ago']);
                $last_seen = $seconds_ago;
                
                if ($seconds_ago <= 70) {
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
            
            $latitude = $row['latitude'] ?? null;
            $longitude = $row['longitude'] ?? null;
            $last_update = $row['last_update'] ?? null;
            
            $current_location = 'Location unavailable';
            if ($latitude && $longitude) {
                $current_location = sprintf('%.6f, %.6f', $latitude, $longitude);
            } elseif ($row['branch']) {
                $current_location = $row['branch'] . ', ' . ($row['city'] ?? '');
                $latitude = 13.9170;
                $longitude = 121.0500;
            } else {
                $latitude = 13.9170;
                $longitude = 121.0500;
            }
            
            $response = [
                'success' => true,
                'driver' => [
                    'id' => $row['id'],
                    'name' => $row['name'],
                    'license_no' => $row['license_no'] ?? 'N/A',
                    'license_expiry' => $row['license_expiry'] ? date('F d, Y', strtotime($row['license_expiry'])) : 'N/A',
                    'phone' => $row['phone'] ?? 'N/A',
                    'vehicle_id' => $row['vehicle_id'] ?? $row['vehicle_type'] ?? 'N/A',
                    'vehicle_type' => $row['vehicle_type'] ?? 'N/A',
                    'branch' => $row['branch'] ?? 'Unassigned',
                    'branch_address' => $row['branch_address'] ?? 'N/A',
                    'current_location' => $current_location,
                    'latitude' => $latitude ? floatval($latitude) : 13.9170,
                    'longitude' => $longitude ? floatval($longitude) : 121.0500,
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
            $response = [
                'success' => true,
                'driver' => [
                    'id' => $driver_id,
                    'name' => 'Unknown Driver',
                    'license_no' => 'N/A',
                    'license_expiry' => 'N/A',
                    'phone' => 'N/A',
                    'vehicle_id' => 'N/A',
                    'vehicle_type' => 'N/A',
                    'branch' => 'Unassigned',
                    'branch_address' => 'N/A',
                    'current_location' => 'Location unavailable',
                    'latitude' => 13.9170,
                    'longitude' => 121.0500,
                    'current_trip' => 'None',
                    'current_trip_id' => null,
                    'status' => 'offline',
                    'status_badge' => 'bg-secondary',
                    'trips_completed_today' => 0,
                    'last_location_update' => 'N/A',
                    'last_seen' => null,
                    'speed' => 0,
                    'is_online' => false
                ]
            ];
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

// ==================== ROUTE HISTORY FROM DATABASE ====================
if (isset($_GET['ajax_route_history']) && isset($_GET['id'])) {
    header('Content-Type: application/json');
    
    try {
        $driver_id = intval($_GET['id']);
        $history_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
        
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $history_date)) {
            $history_date = date('Y-m-d');
        }
        
        $route_sql = "SELECT 
                        latitude, 
                        longitude, 
                        speed_kmh,
                        location_timestamp as time
                      FROM driver_tracking 
                      WHERE driver_id = ? AND DATE(location_timestamp) = ?
                      ORDER BY location_timestamp ASC
                      LIMIT 500";
        $route_stmt = $conn->prepare($route_sql);
        $route_stmt->bind_param("is", $driver_id, $history_date);
        $route_stmt->execute();
        $route_result = $route_stmt->get_result();
        
        $route = [];
        while ($row = $route_result->fetch_assoc()) {
            if (!empty($row['latitude']) && !empty($row['longitude'])) {
                $route[] = [
                    'lat' => floatval($row['latitude']),
                    'lng' => floatval($row['longitude']),
                    'time' => $row['time'],
                    'speed' => floatval($row['speed_kmh'] ?? 0)
                ];
            }
        }
        $route_stmt->close();
        
        $deliveries_sql = "SELECT 
                            d.delivery_id,
                            d.delivery_status,
                            c.customer_name,
                            c.latitude,
                            c.longitude,
                            c.address,
                            c.city
                          FROM deliveries d
                          JOIN trip_tickets tt ON d.trip_id = tt.trip_id
                          JOIN customers c ON d.customer_id = c.customer_id
                          WHERE tt.driver_id = ? AND DATE(tt.trip_date) = ?";
        $deliveries_stmt = $conn->prepare($deliveries_sql);
        $deliveries_stmt->bind_param("is", $driver_id, $history_date);
        $deliveries_result = $deliveries_stmt->get_result();
        
        $deliveries = [];
        while ($row = $deliveries_result->fetch_assoc()) {
            if (!empty($row['latitude']) && !empty($row['longitude'])) {
                $deliveries[] = [
                    'id' => $row['delivery_id'],
                    'name' => $row['customer_name'],
                    'lat' => floatval($row['latitude']),
                    'lng' => floatval($row['longitude']),
                    'status' => $row['delivery_status'],
                    'address' => $row['address'] . ', ' . $row['city']
                ];
            }
        }
        $deliveries_stmt->close();
        
        $total_distance_km = 0;
        $estimated_minutes = 0;
        
        if (count($route) >= 2) {
            for ($i = 0; $i < count($route) - 1; $i++) {
                $total_distance_km += calculateDistance(
                    $route[$i]['lat'], $route[$i]['lng'],
                    $route[$i+1]['lat'], $route[$i+1]['lng']
                );
            }
            $estimated_minutes = round(($total_distance_km / 30) * 60, 1);
        }
        
        echo json_encode([
            'success' => true,
            'route' => $route,
            'deliveries' => $deliveries,
            'date' => $history_date,
            'stats' => [
                'total_points' => count($route),
                'total_deliveries' => count($deliveries),
                'total_distance_km' => round($total_distance_km, 2),
                'estimated_minutes' => $estimated_minutes
            ]
        ]);
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

function calculateDistance($lat1, $lon1, $lat2, $lon2) {
    $earthRadius = 6371;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) * sin($dLat / 2) +
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
         sin($dLon / 2) * sin($dLon / 2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return $earthRadius * $c;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Global - Driver Tracking</title>
    <link rel="icon" type="image/png" href="../Pictures/favicon-96x96.png" sizes="96x96" />
    <link rel="icon" type="image/svg+xml" href="../Pictures/favicon.svg" />
    <link rel="shortcut icon" href="../Pictures/favicon.ico" />
    <link rel="stylesheet" href="../css/global.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <style>
        .badge.bg-success { background-color: #28a745 !important; color: #fff !important; }
        .badge.bg-warning { background-color: #ffc107 !important; color: #fff !important; }
        .badge.bg-secondary { background-color: #6c757d !important; color: #fff !important; }
        .badge.bg-info { background-color: #17a2b8 !important; color: #fff !important; }
        .badge.bg-danger { background-color: #dc3545 !important; color: #fff !important; }
        
        .custom-truck-icon {
            background: transparent !important;
            border: none !important;
            box-shadow: none !important;
        }

        @keyframes pulse {
            0% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.5; transform: scale(1.2); }
            100% { opacity: 1; transform: scale(1); }
        }
        
        #driverMap { width: 100%; height: 500px; border-radius: 8px; overflow: hidden; }
        #driverHistoryMap { border-radius: 8px; border: 2px solid #dee2e6; background-color: #f5f5f5; min-height: 300px; height: 300px; width: 100%; }
        #driverHistoryStats { max-height: 200px; overflow-y: auto; padding-right: 5px; }
        
        .user-avatar-large {
            width: 100px; height: 100px; border-radius: 50%;
            background: linear-gradient(135deg, #047857, #44D34E);
            color: white; display: flex; align-items: center; justify-content: center;
            font-size: 2.5rem; font-weight: bold; margin: 0 auto;
            border: 4px solid #d1fae5; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        #profileModal .modal-content { border: none; border-radius: 20px; overflow: hidden; }
        #profileModal .modal-header { background: linear-gradient(135deg, #047857, #44D34E); color: white; border-bottom: none; padding: 1.5rem; }
        #profileModal .modal-header .modal-title { color: white; font-weight: 600; }
        #profileModal .modal-header .btn-close { filter: brightness(0) invert(1); opacity: 0.9; }
        #profileModal .modal-body { padding: 2rem; background: linear-gradient(135deg, #f9fefc 0%, #f0fdf4 100%); }
        #profileModal .branch-info { background: #d1fae5; color: #047857; padding: 0.5rem 1rem; border-radius: 50px; display: inline-block; font-weight: 500; }
        #profileModal .btn-danger { background: linear-gradient(135deg, #dc3545, #f87171); border: none; padding: 1rem; border-radius: 50px; font-weight: 600; transition: all 0.3s ease; }

        .mobile-nav .nav-link.logout-btn { color: #dc3545; }
        .mobile-nav .nav-link.logout-btn i { color: #dc3545; }
        
        .form-card {
            background: white; border-radius: 16px; padding: 1.25rem;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.03); border: 1px solid #eef2f6;
        }
        .filter-header { display: flex; justify-content: space-between; align-items: center; cursor: pointer; user-select: none; }
        .filter-toggle-btn { background: none; border: none; font-size: 1.2rem; color: #28a745; padding: 0; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; border-radius: 50%; transition: all 0.2s ease; cursor: pointer; }
        .filter-toggle-btn:hover { background: rgba(40, 167, 69, 0.1); }
        .filter-toggle-btn i { transition: transform 0.3s ease; }
        .filter-content { transition: all 0.3s ease; overflow: hidden; }
        .filter-content.collapsed { display: none; }
        .form-card h5 { font-size: 1rem; font-weight: 600; color: #28a745; margin-bottom: 0; }
        .form-card h5 i { color: #28a745; background: rgba(68, 211, 78, 0.1); padding: 0.3rem 0.5rem; border-radius: 8px; font-size: 1.2rem; }
        .form-card .form-label { font-size: 0.75rem; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.3px; margin-bottom: 0.25rem; }
        .form-card .form-control, .form-card .form-select { border: 1.5px solid #e2e8f0; border-radius: 10px; padding: 0.6rem 0.75rem; font-size: 0.9rem; height: 44px; background-color: #fff; transition: all 0.2s ease; }
        .form-card .form-control:focus, .form-card .form-select:focus { border-color: #28a745; box-shadow: 0 0 0 3px rgba(68, 211, 78, 0.15); outline: none; }
        .form-card .btn-primary { background: #28a745; border: none; color: white; }
        
        .btn-action { width: 32px; height: 32px; border-radius: 6px; border: none; display: inline-flex; align-items: center; justify-content: center; margin: 0 2px; transition: all 0.2s; }
        .btn-action.btn-map { color: #388e3c; background-color: #e8f5e9; }
        .btn-action.btn-view { color: #1976d2; background-color: #e3f2fd; }
        .btn-action:hover { opacity: 0.8; transform: translateY(-2px); }
        
        .location-indicator { display: inline-block; width: 10px; height: 10px; border-radius: 50%; margin-right: 5px; }
        .location-indicator.online { background-color: #28a745; animation: pulse 1.5s infinite; }
        .location-indicator.idle { background-color: #ffc107; }
        .location-indicator.offline { background-color: #6c757d; }
        
        .table-scrollable {
            max-height: calc(5 * 50px + 60px);
            overflow-y: auto;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
        }
        .table-scrollable::-webkit-scrollbar { width: 8px; }
        .table-scrollable::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 10px; }
        .table-scrollable::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        .table-scrollable table thead { position: sticky; top: 0; background: white; z-index: 10; box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05); }
        
        /* Row clickable style */
        .driver-row, .trip-row {
            cursor: pointer;
            transition: background-color 0.2s ease;
        }
        .driver-row:hover, .trip-row:hover {
            background-color: #f8f9fa;
        }
        
        @media (min-width: 769px) {
            .custom-table th, .custom-table td { text-align: center; vertical-align: middle; }
            .custom-table th:first-child, .custom-table td:first-child { text-align: left; }
            .custom-table th:nth-child(2), .custom-table td:nth-child(2) { text-align: left; }
        }
        
        /* ===== MOBILE CARD STYLES - PLAIN CARD TYPE ===== */
        @media (max-width: 768px) {
            .custom-table thead { display: none; }
            .custom-table, .custom-table tbody, .custom-table tr, .custom-table td {
                display: block;
                width: 100%;
            }
            .custom-table tbody tr {
                background: white;
                border-radius: 12px;
                margin-bottom: 12px;
                padding: 14px;
                box-shadow: 0 2px 6px rgba(0,0,0,0.06);
                border: 1px solid #e9ecef;
                display: flex;
                align-items: center;
                justify-content: space-between;
            }
            .custom-table tbody tr td {
                display: none;
            }
            
            .custom-table tbody tr .mobile-card-left {
                flex: 1;
                display: flex;
                flex-direction: column;
                gap: 6px;
            }
            
            /* Driver Card */
            .mobile-driver-name {
                font-size: 0.95rem;
                font-weight: 700;
                color: #047857;
            }
            .mobile-vehicle {
                font-size: 0.75rem;
                color: #374151;
            }
            .mobile-location {
                font-size: 0.7rem;
                color: #6c757d;
            }
            .mobile-trip {
                font-size: 0.7rem;
                color: #0d6efd;
            }
            .mobile-status .badge {
                font-size: 0.65rem;
                padding: 3px 8px;
            }
            .mobile-last-seen {
                font-size: 0.65rem;
                color: #9ca3af;
            }
            
            /* Trip Card */
            .mobile-trip-number {
                font-size: 0.9rem;
                font-weight: 700;
                color: #047857;
            }
            .mobile-trip-driver {
                font-size: 0.75rem;
                color: #374151;
            }
            .mobile-trip-origin {
                font-size: 0.7rem;
                color: #6c757d;
            }
            .mobile-trip-status .badge {
                font-size: 0.65rem;
                padding: 3px 8px;
            }
            
            .mobile-action {
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 6px;
                margin-left: 12px;
            }
            .mobile-action .btn-action {
                width: 32px;
                height: 32px;
                font-size: 0.9rem;
            }
            
            .table-scrollable {
                max-height: 480px;
                overflow-y: auto;
            }
        }
        
        @media (max-width: 480px) {
            .custom-table tbody tr { padding: 12px; }
            .mobile-driver-name { font-size: 0.85rem; }
            .mobile-vehicle, .mobile-location, .mobile-trip, .mobile-trip-driver, .mobile-trip-origin { font-size: 0.7rem; }
            .mobile-action .btn-action { width: 28px; height: 28px; font-size: 0.8rem; }
            .table-scrollable { max-height: 440px; }
        }
    </style>
</head>
<body>
    <div id="appPage">
        <div class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <h3>
                    <button class="desktop-toggle-btn" id="desktopToggleBtn"><i class="bi bi-list" id="toggleIcon"></i></button>
                    <img src="../Pictures/amgc3DLogo.png" alt="Logo" class="logo-icon"> 
                    <span class="nav-text">Global</span>
                </h3>
            </div>
            
            <div class="sidebar-menu">
                <ul class="nav flex-column">
                    <li class="nav-item">
                        <a class="nav-link" href="dashboard.php">
                            <i class="bi bi-speedometer2"></i>
                            <span class="nav-text">Dashboard</span>
                        </a>
                    </li>
                    <li class="nav-item"><a class="nav-link" href="sales_reports.php"><i class="bi bi-graph-up"></i><span class="nav-text">Sales Reports</span></a></li>
                    <li class="nav-item"><a class="nav-link" href="branch_records.php"><i class="bi bi-file-text"></i><span class="nav-text">Branch Records</span></a></li>
                    <li class="nav-item"><a class="nav-link" href="all_items.php"><i class="bi bi-box"></i><span class="nav-text">All Items</span></a></li>
                    <li class="nav-item"><a class="nav-link" href="location_verification.php"><i class="bi bi-geo-alt-fill"></i><span class="nav-text">Location Verification</span></a></li>
                    <li class="nav-item"><a class="nav-link" href="drivers.php"><i class="bi bi-people"></i><span class="nav-text">User Management</span></a></li>
                    <li class="nav-item"><a class="nav-link" href="trip_tickets.php"><i class="bi bi-ticket-perforated"></i><span class="nav-text">Trip Tickets</span></a></li>
                    <li class="nav-item"><a class="nav-link active" href="driver_tracking.php"><i class="bi bi-geo-alt"></i><span class="nav-text">Driver Tracking</span></a></li>
                </ul>
            </div>
            
            <div class="sidebar-footer">
                <div class="user-profile-sidebar">
                    <div class="user-avatar-sidebar"><?php echo $initials; ?></div>
                    <div class="user-details-sidebar"><span class="user-name-sidebar"><?php echo htmlspecialchars($user_name); ?></span></div>
                </div>
                <button class="logout-btn-sidebar" onclick="logout()"><i class="bi bi-box-arrow-right"></i><span class="logout-text">Logout</span></button>
            </div>
        </div>

        <div class="main-content" id="mainContent">
            <div class="navbar-top">
                <button class="mobile-toggle-btn" id="mobileToggleBtn"><i class="bi bi-list"></i></button>
                <div class="page-title">
                    <h2>Driver Tracking</h2>
                    <p>Real-time tracking of all delivery drivers</p>
                </div>
            </div>

            <div class="row stat-card-row g-1 g-sm-2 mb-4">
                <div class="col"><div class="stat-card total"><i class="bi bi-people"></i><div class="stat-content"><div class="stat-value" id="totalDrivers">0</div><div class="stat-label">Total Drivers</div></div></div></div>
                <div class="col"><div class="stat-card sales"><i class="bi bi-wifi"></i><div class="stat-content"><div class="stat-value" id="onlineDrivers">0</div><div class="stat-label">Online Now</div></div></div></div>
                <div class="col"><div class="stat-card complete"><i class="bi bi-truck"></i><div class="stat-content"><div class="stat-value" id="activeDrivers">0</div><div class="stat-label">On Delivery</div></div></div></div>
                <div class="col"><div class="stat-card inventory"><i class="bi bi-clock-history"></i><div class="stat-content"><div class="stat-value" id="updateTime">-</div><div class="stat-label">Last Update</div></div></div></div>
            </div>
            
            <div class="form-card mb-4">
                <div class="filter-header" onclick="toggleFilter('driver')">
                    <h5 class="mb-0"><i class="bi bi-funnel"></i> Filter Drivers</h5>
                    <button class="filter-toggle-btn" id="toggleDriverFilter" title="Toggle Filter"><i class="bi bi-chevron-down" id="driverFilterIcon"></i></button>
                </div>
                <div class="filter-content" id="driverFilterContent">
                    <div class="row g-2 g-md-3 mt-3 mb-3">
                        <div class="col-12 col-md-6 col-lg-3"><label class="form-label">Driver Name</label><input type="text" class="form-control" id="filterName" placeholder="Enter driver name..."></div>
                        <div class="col-6 col-md-6 col-lg-3"><label class="form-label">Branch</label><select class="form-select" id="filterBranch"><option value="">All Branches</option><?php foreach ($branches as $branch): ?><option value="<?php echo htmlspecialchars($branch['branch_name']); ?>"><?php echo htmlspecialchars($branch['branch_name']); ?></option><?php endforeach; ?></select></div>
                        <div class="col-6 col-md-6 col-lg-3"><label class="form-label">Status</label><select class="form-select" id="filterStatus"><option value="">All Status</option><option value="active">Active (On Delivery)</option><option value="idle">Idle (Online)</option><option value="offline">Offline</option></select></div>
                        <div class="col-12 col-md-6 col-lg-3"><label class="form-label">Trip Ticket</label><input type="text" class="form-control" id="filterTrip" placeholder="Enter trip number..."></div>
                    </div>
                    <div class="row g-2"><div class="col-6"><button class="btn btn-primary w-100" onclick="applyFilters()"><i class="bi bi-funnel me-1"></i> Apply Filters</button></div><div class="col-6"><button class="btn btn-outline-secondary w-100" onclick="clearFilters()"><i class="bi bi-x-circle me-1"></i> Clear Filters</button></div></div>
                </div>
            </div>
            
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

            <div class="data-table">
                <div class="table-header">
                    <h5><i class="bi bi-list"></i> Live Driver Locations</h5>
                    <div class="status-indicators"><span class="badge bg-primary" id="driverCount">0</span></div>
                </div>
                <div class="table-container table-scrollable" id="driversTableContainer">
                    <table class="table custom-table compact-table" id="driversDataTable">
                        <thead>
                            <tr>
                                <th>ID</th><th>Driver</th><th>Vehicle</th><th>Location</th><th>Trip</th><th>Status</th><th>Last Seen</th><th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="driversTable">
                            <tr><td colspan="8" class="text-center py-4"><div class="spinner-border text-primary" role="status"></div><p class="mt-2">Loading driver data...</p></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <div class="data-table mt-4">
                <div class="table-header"><h5><i class="bi bi-truck"></i> Active Trips</h5></div>
                <div class="table-container table-scrollable" id="tripsTableContainer">
                    <table class="table custom-table compact-table" id="tripsDataTable">
                        <thead>
                            <tr>
                                <th>Trip #</th><th>Driver</th><th>Origin</th><th>Status</th>
                            </tr>
                        </thead>
                        <tbody id="tripsTable">
                            <tr><td colspan="4" class="text-center py-4"><div class="spinner-border text-primary" role="status"></div><p class="mt-2">Loading trip data...</p></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="mobile-nav" id="mobileNav">
        <ul class="nav">
            <li class="nav-item"><a class="nav-link" href="sales_reports.php"><i class="bi bi-graph-up"></i><span>Reports</span></a></li>
            <li class="nav-item"><a class="nav-link" href="branch_records.php"><i class="bi bi-file-text"></i><span>Records</span></a></li>
            <li class="nav-item"><a class="nav-link" href="all_items.php"><i class="bi bi-box"></i><span>Items</span></a></li>
            <li class="nav-item"><a class="nav-link" href="drivers.php"><i class="bi bi-people"></i><span>Users</span></a></li>
            <li class="nav-item"><a class="nav-link" href="trip_tickets.php"><i class="bi bi-ticket-perforated"></i><span>Tickets</span></a></li>
            <li class="nav-item"><a class="nav-link active" href="driver_tracking.php"><i class="bi bi-geo-alt"></i><span>Tracking</span></a></li>
            <li class="nav-item"><a class="nav-link logout-btn" href="#" onclick="showProfileModal(); return false;"><i class="bi bi-box-arrow-right"></i><span>Logout</span></a></li>
        </ul>
    </div>

    <div class="modal fade" id="profileModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header"><h5 class="modal-title"><i class="bi bi-person-circle me-2"></i>User Profile</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body text-center">
                    <div class="user-avatar-large mb-3"><?php echo $initials; ?></div>
                    <h4 class="mb-1"><?php echo htmlspecialchars($user_name); ?></h4>
                    <p class="text-muted mb-3"><span class="badge bg-success"><?php echo ucfirst($user_role); ?></span></p>
                    <?php if (!$view_all_branches && $user_branch_id > 0): ?>
                    <div class="branch-info mb-3"><i class="bi bi-building me-1"></i><span><?php echo htmlspecialchars($branch_name); ?></span></div>
                    <?php endif; ?>
                    <button class="btn btn-danger btn-lg w-100" onclick="confirmLogout()"><i class="bi bi-box-arrow-right me-2"></i>Logout</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="driverModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header"><h5 class="modal-title">Driver Details</h5><button type="button" class="btn-close" data-bs-dismiss="modal" onclick="cleanupDriverHistoryMap();"></button></div>
                <div class="modal-body" id="driverModalBody">Loading...</div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal" onclick="cleanupDriverHistoryMap();">Close</button><button type="button" class="btn btn-warning" id="focusMapBtn" onclick="focusOnMapFromModal()">View on Map</button></div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="tripModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header"><h5 class="modal-title">Trip Details</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body" id="tripModalBody">Loading...</div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
        let map, markers = {}, currentDriverId = null, refreshInterval, lastUpdateTime = Date.now(), driverHistoryMap = null;

        function getTruckIcon(status) {
            let color = status === 'active' ? '#28a745' : (status === 'idle' ? '#ffc107' : '#6c757d');
            return L.divIcon({
                className: 'custom-truck-icon', 
                html: `<div style="background: ${color}; border: 2px solid white; border-radius: 50%; width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; box-shadow: none; transform: rotate(-45deg);"><i class="bi bi-truck" style="color: white; font-size: 20px; transform: rotate(45deg);"></i></div>`,
                iconSize: [40, 40], 
                iconAnchor: [20, 20], 
                popupAnchor: [0, -20]
            });
        }

        function initMap() {
            const mapElement = document.getElementById('driverMap');
            if (!mapElement) return;
            map = L.map('driverMap').setView([13.9170, 121.0500], 12);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '© OpenStreetMap contributors', maxZoom: 19 }).addTo(map);
        }

        function loadTracking() {
            let url = 'driver_tracking.php?ajax=1';
            const filterName = document.getElementById('filterName').value;
            const filterBranch = document.getElementById('filterBranch').value;
            const filterTrip = document.getElementById('filterTrip').value;
            const filterStatus = document.getElementById('filterStatus').value;
            if (filterName) url += '&driverName=' + encodeURIComponent(filterName);
            if (filterBranch) url += '&location=' + encodeURIComponent(filterBranch);
            if (filterTrip) url += '&tripTicket=' + encodeURIComponent(filterTrip);
            if (filterStatus) url += '&status=' + encodeURIComponent(filterStatus);
            
            fetch(url).then(response => response.json()).then(data => {
                if (data.success) {
                    updateMap(data.drivers);
                    updateTable(data.drivers);
                    updateTrips(data.trips);
                    updateStats(data.stats);
                    lastUpdateTime = Date.now();
                    document.getElementById('updateTime').textContent = 'just now';
                }
            }).catch(error => console.error('Error:', error));
        }

        function updateMap(drivers) {
            if (!map) return;
            let activeIds = new Set();
            drivers.forEach(driver => {
                activeIds.add(driver.id);
                let icon = getTruckIcon(driver.status);
                let popupContent = `<div><strong>${escapeHtml(driver.name)}</strong><br>Vehicle: ${escapeHtml(driver.vehicle_id)}<br>Trip: ${escapeHtml(driver.current_trip || 'None')}<br>Status: <span class="badge ${driver.status_badge}">${driver.status}</span><br><button class="btn btn-sm btn-info mt-2" onclick="viewDriver(${driver.id})">View Details</button></div>`;
                if (markers[driver.id]) {
                    markers[driver.id].setLatLng([driver.latitude, driver.longitude]);
                    markers[driver.id].setIcon(icon);
                    markers[driver.id].setPopupContent(popupContent);
                } else {
                    let marker = L.marker([driver.latitude, driver.longitude], { icon: icon }).addTo(map).bindPopup(popupContent);
                    marker.on('click', () => { currentDriverId = driver.id; });
                    markers[driver.id] = marker;
                }
            });
            for (let id in markers) { if (!activeIds.has(parseInt(id))) { map.removeLayer(markers[id]); delete markers[id]; } }
        }

        function updateTable(drivers) {
            let tbody = document.getElementById('driversTable');
            if (!drivers || drivers.length === 0) { tbody.innerHTML = '<tr><td colspan="8" class="text-center py-4">No drivers found</td></tr>'; document.getElementById('driverCount').textContent = '0'; return; }
            let onlineCount = 0;
            let html = '';
            drivers.forEach(driver => {
                if (driver.is_online) onlineCount++;
                let statusText = driver.status.charAt(0).toUpperCase() + driver.status.slice(1);
                let lastSeen = driver.last_seen ? driver.last_seen + 's ago' : 'Never';
                let indicatorClass = driver.is_online ? (driver.status === 'active' ? 'online' : 'idle') : 'offline';
                html += `<tr class="driver-row" data-driver-id="${driver.id}" data-driver-name="${escapeHtml(driver.name)}" data-vehicle="${escapeHtml(driver.vehicle_id)}" data-location="${escapeHtml(driver.current_location)}" data-trip="${escapeHtml(driver.current_trip)}" data-status="${driver.status}" data-status-text="${statusText}" data-status-badge="${driver.status_badge}" data-last-seen="${lastSeen}" onclick="viewDriver(${driver.id})">
                    <td>${driver.id}</td>
                    <td><strong>${escapeHtml(driver.name)}</strong></td>
                    <td>${escapeHtml(driver.vehicle_id)}</td>
                    <td><span class="location-indicator ${indicatorClass}"></span> ${escapeHtml(driver.current_location)}</td>
                    <td>${escapeHtml(driver.current_trip)}</td>
                    <td><span class="badge ${driver.status_badge}">${statusText}</span></td>
                    <td>${lastSeen}</td>
                    <td class="text-center">
                        <button class="btn-action btn-map" onclick="event.stopPropagation(); focusOnDriver(${driver.id})" title="View on Map"><i class="bi bi-geo-alt-fill"></i></button>
                    </td>
                 </tr>`;
            });
            tbody.innerHTML = html;
            document.getElementById('driverCount').textContent = onlineCount;
            if (window.innerWidth <= 768) addMobileCardStructure();
        }

        function addMobileCardStructure() {
            const rows = document.querySelectorAll('#driversTable tr.driver-row');
            rows.forEach(row => {
                if (row.querySelector('.mobile-card-left')) return;
                if (!row.hasAttribute('data-original-html')) {
                    const originalCells = [], cells = row.querySelectorAll('td');
                    cells.forEach(cell => originalCells.push(cell.innerHTML));
                    row.setAttribute('data-original-html', JSON.stringify(originalCells));
                }
                const driverName = row.getAttribute('data-driver-name') || '';
                const vehicle = row.getAttribute('data-vehicle') || '';
                const location = row.getAttribute('data-location') || '';
                const trip = row.getAttribute('data-trip') || 'None';
                const statusText = row.getAttribute('data-status-text') || '';
                const statusBadge = row.getAttribute('data-status-badge') || 'bg-secondary';
                const lastSeen = row.getAttribute('data-last-seen') || 'Never';
                const actionHtml = row.cells[7]?.innerHTML || '';
                row.innerHTML = '';
                const leftDiv = document.createElement('div'); leftDiv.className = 'mobile-card-left';
                leftDiv.innerHTML = `<div class="mobile-driver-name">${escapeHtml(driverName)}</div><div class="mobile-vehicle">${escapeHtml(vehicle)}</div><div class="mobile-location">${escapeHtml(location)}</div><div class="mobile-trip">Trip: ${escapeHtml(trip)}</div><div class="mobile-status"><span class="badge ${statusBadge}">${escapeHtml(statusText)}</span></div><div class="mobile-last-seen">Last seen: ${escapeHtml(lastSeen)}</div>`;
                const rightDiv = document.createElement('div'); rightDiv.className = 'mobile-action';
                rightDiv.innerHTML = actionHtml;
                row.appendChild(leftDiv); row.appendChild(rightDiv);
                
                // Re-attach click event for mobile view
                row.style.cursor = 'pointer';
                row.onclick = (function(id) { return function() { viewDriver(id); }; })(parseInt(row.getAttribute('data-driver-id')));
            });
        }

        function removeMobileCardStructure() {
            const rows = document.querySelectorAll('#driversTable tr.driver-row');
            rows.forEach(row => {
                if (row.hasAttribute('data-original-html')) {
                    const originalCells = JSON.parse(row.getAttribute('data-original-html'));
                    const cells = row.querySelectorAll('td');
                    if (cells.length === 0) {
                        const newRow = document.createElement('tr');
                        newRow.className = row.className;
                        Array.from(row.attributes).forEach(attr => { if (attr.name !== 'class') newRow.setAttribute(attr.name, attr.value); });
                        for (let i = 0; i < originalCells.length; i++) { const td = document.createElement('td'); td.innerHTML = originalCells[i]; newRow.appendChild(td); }
                        row.parentNode.replaceChild(newRow, row);
                    }
                }
            });
        }

        function updateTrips(trips) {
            let tbody = document.getElementById('tripsTable');
            if (!trips || trips.length === 0) { tbody.innerHTML = '<tr><td colspan="4" class="text-center py-4">No active trips</td></tr>'; return; }
            let html = '';
            trips.forEach(trip => {
                let statusBadge = 'bg-secondary', statusText = 'Planned';
                if (trip.trip_status === 'in-progress') { statusBadge = 'bg-success'; statusText = 'In Progress'; }
                else if (trip.trip_status === 'completed') { statusBadge = 'bg-success'; statusText = 'Completed'; }
                else if (trip.trip_status === 'delayed') { statusBadge = 'bg-danger'; statusText = 'Delayed'; }
                else if (trip.trip_status === 'cancelled') { statusBadge = 'bg-secondary'; statusText = 'Cancelled'; }
                html += `<tr class="trip-row" data-trip-id="${trip.trip_id}" data-trip-number="${escapeHtml(trip.trip_number)}" data-driver-name="${escapeHtml(trip.driver_name)}" data-origin="${escapeHtml(trip.origin)}" data-status="${trip.trip_status}" data-status-text="${statusText}" data-status-badge="${statusBadge}" onclick="viewTrip(${trip.trip_id})">
                    <td><strong>${escapeHtml(trip.trip_number)}</strong></td>
                    <td>${escapeHtml(trip.driver_name)}</td>
                    <td>${escapeHtml(trip.origin)}</td>
                    <td><span class="badge ${statusBadge}">${statusText}</span></td>
                 </tr>`;
            });
            tbody.innerHTML = html;
            if (window.innerWidth <= 768) addTripsMobileCardStructure();
        }

        function addTripsMobileCardStructure() {
            const rows = document.querySelectorAll('#tripsTable tr.trip-row');
            rows.forEach(row => {
                if (row.querySelector('.mobile-card-left')) return;
                if (!row.hasAttribute('data-original-html')) {
                    const originalCells = [], cells = row.querySelectorAll('td');
                    cells.forEach(cell => originalCells.push(cell.innerHTML));
                    row.setAttribute('data-original-html', JSON.stringify(originalCells));
                }
                const tripNumber = row.getAttribute('data-trip-number') || '';
                const driverName = row.getAttribute('data-driver-name') || '';
                const origin = row.getAttribute('data-origin') || '';
                const statusText = row.getAttribute('data-status-text') || '';
                const statusBadge = row.getAttribute('data-status-badge') || 'bg-secondary';
                row.innerHTML = '';
                const leftDiv = document.createElement('div'); leftDiv.className = 'mobile-card-left';
                leftDiv.innerHTML = `<div class="mobile-trip-number">${escapeHtml(tripNumber)}</div><div class="mobile-trip-driver">${escapeHtml(driverName)}</div><div class="mobile-trip-origin">${escapeHtml(origin)}</div><div class="mobile-trip-status"><span class="badge ${statusBadge}">${escapeHtml(statusText)}</span></div>`;
                row.appendChild(leftDiv);
                
                // Re-attach click event for mobile view
                row.style.cursor = 'pointer';
                row.onclick = (function(id) { return function() { viewTrip(id); }; })(parseInt(row.getAttribute('data-trip-id')));
            });
        }

        function removeTripsMobileCardStructure() {
            const rows = document.querySelectorAll('#tripsTable tr.trip-row');
            rows.forEach(row => {
                if (row.hasAttribute('data-original-html')) {
                    const originalCells = JSON.parse(row.getAttribute('data-original-html'));
                    const cells = row.querySelectorAll('td');
                    if (cells.length === 0) {
                        const newRow = document.createElement('tr');
                        newRow.className = row.className;
                        Array.from(row.attributes).forEach(attr => { if (attr.name !== 'class') newRow.setAttribute(attr.name, attr.value); });
                        for (let i = 0; i < originalCells.length; i++) { const td = document.createElement('td'); td.innerHTML = originalCells[i]; newRow.appendChild(td); }
                        row.parentNode.replaceChild(newRow, row);
                    }
                }
            });
        }

        function handleResponsiveLayout() {
            if (window.innerWidth <= 768) { addMobileCardStructure(); addTripsMobileCardStructure(); }
            else { removeMobileCardStructure(); removeTripsMobileCardStructure(); }
        }

        function updateStats(stats) {
            document.getElementById('totalDrivers').textContent = stats.totalDrivers || 0;
            document.getElementById('onlineDrivers').textContent = stats.onlineDrivers || 0;
            document.getElementById('activeDrivers').textContent = stats.activeDrivers || 0;
        }

        function applyFilters() { loadTracking(); }
        function clearFilters() { document.getElementById('filterName').value = ''; document.getElementById('filterBranch').value = ''; document.getElementById('filterTrip').value = ''; document.getElementById('filterStatus').value = ''; loadTracking(); }

        function viewDriver(id) {
            currentDriverId = id;
            $('#driverModal').modal('show');
            document.getElementById('driverModalBody').innerHTML = '<p class="text-center">Loading driver details...</p>';
            fetch('driver_tracking.php?ajax_details=1&id=' + id).then(response => response.json()).then(data => {
                if (data.success) {
                    let d = data.driver;
                    let html = `<dl class="row"><dt class="col-sm-4">Name:</dt><dd class="col-sm-8"><strong>${escapeHtml(d.name)}</strong></dd><dt class="col-sm-4">License:</dt><dd class="col-sm-8">${escapeHtml(d.license_no)}</dd><dt class="col-sm-4">Expiry:</dt><dd class="col-sm-8">${escapeHtml(d.license_expiry)}</dd><dt class="col-sm-4">Phone:</dt><dd class="col-sm-8">${escapeHtml(d.phone)}</dd><dt class="col-sm-4">Vehicle:</dt><dd class="col-sm-8">${escapeHtml(d.vehicle_id)} (${escapeHtml(d.vehicle_type)})</dd><dt class="col-sm-4">Branch:</dt><dd class="col-sm-8">${escapeHtml(d.branch)}</dd><dt class="col-sm-4">Location:</dt><dd class="col-sm-8">${escapeHtml(d.current_location)}</dd><dt class="col-sm-4">Current Trip:</dt><dd class="col-sm-8">${escapeHtml(d.current_trip)}</dd><dt class="col-sm-4">Status:</dt><dd class="col-sm-8"><span class="badge ${d.status_badge}">${escapeHtml(d.status)}</span></dd><dt class="col-sm-4">Online:</dt><dd class="col-sm-8">${d.is_online ? 'Yes' : 'No'}</dd><dt class="col-sm-4">Last Seen:</dt><dd class="col-sm-8">${d.last_seen ? d.last_seen + ' seconds ago' : 'Never'}</dd><dt class="col-sm-4">Trips Today:</dt><dd class="col-sm-8">${d.trips_completed_today}</dd><dt class="col-sm-4">Last Update:</dt><dd class="col-sm-8">${escapeHtml(d.last_location_update)}</dd><dt class="col-sm-4">Speed:</dt><dd class="col-sm-8">${d.speed ? d.speed + ' km/h' : '0 km/h'}</dd></dl><hr><h6>Route History</h6><div class="mb-2"><label for="driverHistoryDate" class="form-label">Select Date</label><input type="date" class="form-control" id="driverHistoryDate" value="${new Date().toISOString().slice(0,10)}" onchange="loadDriverHistory(${id})"></div><div id="driverHistoryMap" style="height: 300px; border-radius: 8px; border: 1px solid #ddd;"></div><div id="driverHistoryStats" class="mt-3"></div>`;
                    document.getElementById('driverModalBody').innerHTML = html;
                    document.getElementById('focusMapBtn').style.display = 'inline-block';
                    setTimeout(() => { initDriverHistoryMap(); loadDriverHistory(id); }, 100);
                }
            });
        }

        function initDriverHistoryMap() {
            if (driverHistoryMap) { driverHistoryMap.remove(); driverHistoryMap = null; }
            driverHistoryMap = L.map('driverHistoryMap').setView([13.9170, 121.0500], 12);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '© OpenStreetMap contributors', maxZoom: 19 }).addTo(driverHistoryMap);
        }

        function loadDriverHistory(driverId) {
            const date = document.getElementById('driverHistoryDate').value;
            if (!date) return;
            const statsDiv = document.getElementById('driverHistoryStats');
            statsDiv.innerHTML = '<div class="text-center"><div class="spinner-border text-primary" role="status"></div><p>Loading route...</p></div>';
            fetch(`driver_tracking.php?ajax_route_history=1&id=${driverId}&date=${date}`).then(response => response.json()).then(data => {
                if (!data.success) { statsDiv.innerHTML = '<p class="text-danger">Failed to load route history</p>'; return; }
                if (driverHistoryMap) {
                    driverHistoryMap.eachLayer(layer => { if (layer instanceof L.Polyline || layer instanceof L.Marker || layer instanceof L.CircleMarker) driverHistoryMap.removeLayer(layer); });
                }
                if (data.route.length >= 2) { const latlngs = data.route.map(p => [p.lat, p.lng]); L.polyline(latlngs, { color: '#3388ff', weight: 3, opacity: 0.7 }).addTo(driverHistoryMap); }
                if (data.route.length > 0) { const start = data.route[0]; L.circleMarker([start.lat, start.lng], { radius: 8, fillColor: '#28a745', color: '#fff', weight: 2, fillOpacity: 0.8 }).addTo(driverHistoryMap).bindPopup(`<strong>Start</strong><br>${new Date(start.time).toLocaleString()}`); }
                if (data.route.length > 0) { const end = data.route[data.route.length-1]; L.circleMarker([end.lat, end.lng], { radius: 8, fillColor: '#dc3545', color: '#fff', weight: 2, fillOpacity: 0.8 }).addTo(driverHistoryMap).bindPopup(`<strong>End</strong><br>${new Date(end.time).toLocaleString()}`); }
                data.deliveries.forEach(del => { const color = del.status === 'delivered' ? '#28a745' : '#ffc107'; L.circleMarker([del.lat, del.lng], { radius: 10, fillColor: color, color: '#fff', weight: 2, fillOpacity: 0.8 }).addTo(driverHistoryMap).bindPopup(`<strong>${escapeHtml(del.name)}</strong><br>Status: ${del.status}<br>${escapeHtml(del.address)}`); });
                if (data.route.length > 0) { const latlngs = data.route.map(p => [p.lat, p.lng]); const group = new L.featureGroup(latlngs.map(ll => L.marker(ll))); driverHistoryMap.fitBounds(group.getBounds().pad(0.1)); }
                const stats = data.stats;
                const startTime = data.route.length > 0 ? new Date(data.route[0].time).toLocaleTimeString() : 'N/A';
                const endTime = data.route.length > 0 ? new Date(data.route[data.route.length-1].time).toLocaleTimeString() : 'N/A';
                statsDiv.innerHTML = `<div class="alert alert-info"><strong>Route Statistics</strong><br><small>Date: ${data.date}</small><br><small>Start: ${startTime} | End: ${endTime}</small><br><small>Points: ${stats.total_points}</small><br><small>Distance: ${stats.total_distance_km} km</small><br><small>Est. Time: ${stats.estimated_minutes} min</small></div><div class="alert alert-light"><strong>Deliveries (${stats.total_deliveries})</strong>${data.deliveries.map(d => `<div class="mb-2 pb-2 border-bottom"><strong>${escapeHtml(d.name)}</strong><br><small class="text-muted">${escapeHtml(d.address)}</small><br><small>Status: <span class="badge ${d.status === 'delivered' ? 'bg-success' : 'bg-warning'}">${d.status}</span></small></div>`).join('') || '<small class="text-muted">No deliveries recorded</small>'}</div>`;
            });
        }

        function cleanupDriverHistoryMap() { if (driverHistoryMap) { driverHistoryMap.remove(); driverHistoryMap = null; } }
        
        function viewTrip(id) { 
            $('#tripModal').modal('show'); 
            document.getElementById('tripModalBody').innerHTML = '<p class="text-center">Loading...</p>'; 
            fetch('driver_tracking.php?ajax_trip=1&id=' + id).then(response => response.json()).then(data => { 
                if (data.success) { 
                    let t = data.trip; 
                    let statusBadge = t.status === 'in-progress' ? 'bg-success' : 'bg-info'; 
                    let html = `<dl class="row"><dt class="col-sm-4">Trip #:</dt><dd class="col-sm-8"><strong>${escapeHtml(t.trip_number)}</strong></dd><dt class="col-sm-4">Driver:</dt><dd class="col-sm-8">${escapeHtml(t.driver_name)}</dd><dt class="col-sm-4">Vehicle:</dt><dd class="col-sm-8">${escapeHtml(t.vehicle)}</dd><dt class="col-sm-4">Origin:</dt><dd class="col-sm-8">${escapeHtml(t.origin)}</dd><dt class="col-sm-4">Departure:</dt><dd class="col-sm-8">${escapeHtml(t.departure)}</dd><dt class="col-sm-4">Status:</dt><dd class="col-sm-8"><span class="badge ${statusBadge}">${escapeHtml(t.status)}</span></dd><dt class="col-sm-4">Total Stops:</dt><dd class="col-sm-8">${t.total_stops}</dd></dl>`; 
                    if (t.deliveries && t.deliveries.length > 0) { 
                        html += '<h6 class="mt-4">Delivery Stops</h6><div class="table-responsive"><table class="table table-sm table-bordered"><thead><tr><th>Stop</th><th>Customer</th><th>Address</th><th>SO #</th><th>Status</th></tr></thead><tbody>'; 
                        t.deliveries.forEach(d => { 
                            let delStatusBadge = 'bg-info'; 
                            if (d.delivery_status === 'delivered') delStatusBadge = 'bg-success'; 
                            else if (d.delivery_status === 'rejected') delStatusBadge = 'bg-danger'; 
                            else if (d.delivery_status === 'partial') delStatusBadge = 'bg-warning'; 
                            html += `<tr><td>${d.stop_sequence || '-'}</td><td>${escapeHtml(d.customer_name)}</td><td><small>${escapeHtml(d.address)}</small></td><td>${escapeHtml(d.so_number)}</td><td><span class="badge ${delStatusBadge}">${escapeHtml(d.delivery_status)}</span></td></tr>`; 
                        }); 
                        html += '</tbody></table></div>'; 
                    } 
                    document.getElementById('tripModalBody').innerHTML = html; 
                } else { 
                    document.getElementById('tripModalBody').innerHTML = '<p class="text-danger">Failed to load trip details</p>'; 
                } 
            }); 
        }
        
        function focusOnDriver(id) { if (markers[id]) { let latlng = markers[id].getLatLng(); map.setView([latlng.lat, latlng.lng], 16); markers[id].openPopup(); } }
        function focusOnMapFromModal() { if (currentDriverId) { $('#driverModal').modal('hide'); setTimeout(() => focusOnDriver(currentDriverId), 500); } }
        function escapeHtml(text) { if (!text) return ''; return String(text).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;'); }
        function showProfileModal() { new bootstrap.Modal(document.getElementById('profileModal')).show(); }
        function confirmLogout() { bootstrap.Modal.getInstance(document.getElementById('profileModal'))?.hide(); Swal.fire({ title: 'Are you sure?', text: 'You will be logged out', icon: 'question', showCancelButton: true, confirmButtonColor: '#dc3545', confirmButtonText: 'Yes' }).then((result) => { if (result.isConfirmed) window.location.href = '../logout.php'; }); }
        function logout() { Swal.fire({ title: 'Are you sure?', text: 'You will be logged out', icon: 'question', showCancelButton: true, confirmButtonColor: '#07d826', confirmButtonText: 'Yes' }).then((result) => { if (result.isConfirmed) window.location.href = '../logout.php'; }); }
        function toggleSidebar() { const sidebar = document.getElementById('sidebar'); if (window.innerWidth <= 992) { sidebar.classList.toggle('active'); document.body.style.overflow = sidebar.classList.contains('active') ? 'hidden' : ''; } else { sidebar.classList.toggle('collapsed'); localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed')); } }
        function closeMobileSidebar() { document.getElementById('sidebar').classList.remove('active'); document.body.style.overflow = ''; }
        function initializeSidebar() { if (window.innerWidth > 992 && localStorage.getItem('sidebarCollapsed') === 'true') document.getElementById('sidebar').classList.add('collapsed'); }
        function initMobileNav() { const mobileNav = document.getElementById('mobileNav'); if (window.innerWidth <= 992) { mobileNav.style.display = 'block'; const currentPage = window.location.pathname.split('/').pop(); document.querySelectorAll('#mobileNav .nav-link:not(.logout-btn)').forEach(link => { link.classList.remove('active'); if (link.getAttribute('href') === currentPage) link.classList.add('active'); }); } else { mobileNav.style.display = 'none'; } }
        function toggleFilter(filterType) { const content = document.getElementById(filterType + 'FilterContent'); const icon = document.getElementById(filterType + 'FilterIcon'); if (content && icon) { if (content.classList.contains('collapsed')) { content.classList.remove('collapsed'); icon.style.transform = 'rotate(0deg)'; localStorage.setItem(filterType + 'FilterHidden', 'false'); } else { content.classList.add('collapsed'); icon.style.transform = 'rotate(-90deg)'; localStorage.setItem(filterType + 'FilterHidden', 'true'); } } }
        function initFilterStates() { const content = document.getElementById('driverFilterContent'); const icon = document.getElementById('driverFilterIcon'); if (content && icon) { const isHidden = localStorage.getItem('driverFilterHidden'); if (isHidden === 'false') { content.classList.remove('collapsed'); icon.style.transform = 'rotate(0deg)'; } else { content.classList.add('collapsed'); icon.style.transform = 'rotate(-90deg)'; } } }

        document.addEventListener('DOMContentLoaded', function() {
            initializeSidebar(); initMobileNav(); initFilterStates();
            document.getElementById('mobileToggleBtn').addEventListener('click', toggleSidebar);
            document.getElementById('desktopToggleBtn').addEventListener('click', toggleSidebar);
            document.querySelector('.filter-header').style.cursor = 'pointer';
            document.addEventListener('click', function(event) { if (window.innerWidth <= 992 && document.getElementById('sidebar').classList.contains('active') && !document.getElementById('sidebar').contains(event.target) && !document.getElementById('mobileToggleBtn').contains(event.target)) closeMobileSidebar(); });
            window.addEventListener('resize', function() { if (window.innerWidth > 992) closeMobileSidebar(); initMobileNav(); handleResponsiveLayout(); });
            initMap(); loadTracking();
            refreshInterval = setInterval(loadTracking, 60000);
            setInterval(() => { let seconds = Math.floor((Date.now() - lastUpdateTime) / 1000); document.getElementById('updateTime').textContent = seconds + 's ago'; }, 1000);
            handleResponsiveLayout();
            let resizeTimer; window.addEventListener('resize', function() { clearTimeout(resizeTimer); resizeTimer = setTimeout(() => handleResponsiveLayout(), 150); });
        });
        window.addEventListener('beforeunload', () => { if (refreshInterval) clearInterval(refreshInterval); cleanupDriverHistoryMap(); });
        document.addEventListener('keydown', function(e) { if (e.ctrlKey && e.key === 'b' && window.innerWidth > 992) { e.preventDefault(); toggleSidebar(); } else if (e.key === 'Escape' && window.innerWidth <= 992) { closeMobileSidebar(); } });
    </script>
</body>
</html>
<?php $conn->close(); ?>