<?php
header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Include database connection
require_once '../config/database.php';

// Get today's date
$today = date('Y-m-d');

try {
    // Get drivers with their latest tracking data
    $drivers_sql = "SELECT 
                        d.driver_id,
                        d.driver_name,
                        d.contact_number,
                        d.vehicle_type,
                        d.vehicle_plate_number,
                        d.status,
                        b.branch_name,
                        b.city,
                        dt.latitude,
                        dt.longitude,
                        dt.location_timestamp,
                        dt.speed_kmh,
                        tt.trip_id,
                        tt.trip_number,
                        tt.trip_status,
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
                    WHERE d.status != 'inactive'
                    ORDER BY d.driver_name";
    
    $stmt = $conn->prepare($drivers_sql);
    if (!$stmt) {
        throw new Exception('Prepare failed: ' . $conn->error);
    }
    
    $stmt->bind_param('s', $today);
    
    if (!$stmt->execute()) {
        throw new Exception('Execute failed: ' . $stmt->error);
    }
    
    $result = $stmt->get_result();
    $drivers = [];
    $stats = [
        'totalDrivers' => 0,
        'activeDrivers' => 0,
        'completedTrips' => 0
    ];
    
    while ($row = $result->fetch_assoc()) {
        $drivers[] = $row;
        $stats['totalDrivers']++;
        
        if ($row['latitude'] && $row['longitude']) {
            $stats['activeDrivers']++;
        }
        
        if ($row['trip_status'] == 'completed') {
            $stats['completedTrips']++;
        }
    }
    
    // Get trips for today
    $trips_sql = "SELECT 
                    tt.trip_id,
                    tt.trip_number,
                    d.driver_name,
                    b.city as origin,
                    tt.destination,
                    COUNT(tl.trip_line_id) as items,
                    tt.start_time,
                    tt.trip_status
                FROM trip_tickets tt
                LEFT JOIN drivers d ON tt.driver_id = d.driver_id
                LEFT JOIN branches b ON tt.branch_id = b.branch_id
                LEFT JOIN trip_lines tl ON tt.trip_id = tl.trip_id
                WHERE tt.trip_date = ?
                GROUP BY tt.trip_id
                ORDER BY tt.trip_number";
    
    $stmt2 = $conn->prepare($trips_sql);
    if (!$stmt2) {
        throw new Exception('Prepare failed: ' . $conn->error);
    }
    
    $stmt2->bind_param('s', $today);
    
    if (!$stmt2->execute()) {
        throw new Exception('Execute failed: ' . $stmt2->error);
    }
    
    $result2 = $stmt2->get_result();
    $trips = [];
    
    while ($row = $result2->fetch_assoc()) {
        $trips[] = $row;
    }
    
    echo json_encode([
        'success' => true,
        'drivers' => $drivers,
        'trips' => $trips,
        'stats' => $stats
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

$conn->close();
?>
