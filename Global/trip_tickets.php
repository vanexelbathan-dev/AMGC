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
$user_name = $_SESSION['user_name'] ?? $_SESSION['first_name'] . ' ' . $_SESSION['last_name'] ?? 'Quality Control';
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

// Get filter parameters
$status = isset($_GET['status']) ? $_GET['status'] : '';
$origin = isset($_GET['origin']) ? $_GET['origin'] : '';
$date = isset($_GET['date']) ? $_GET['date'] : ''; // Empty by default to show all data
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'date';

// Handle AJAX request for trips
if (isset($_GET['ajax']) && $_GET['ajax'] == 1) {
    header('Content-Type: application/json');
    
    $response = [
        'success' => true,
        'trips' => [],
        'stats' => [
            'totalTrips' => 0,
            'completedTrips' => 0,
            'inProgressTrips' => 0,
            'delayedTrips' => 0
        ]
    ];

    // Build WHERE clause for filtering
    $where_conditions = [];
    $params = [];
    $types = "";

    // Add branch filter based on user permissions
    if (!$view_all_branches && $user_branch_id > 0) {
        $where_conditions[] = "tt.branch_id = ?";
        $params[] = $user_branch_id;
        $types .= "i";
    }

    // Status filter
    if (!empty($status)) {
        $where_conditions[] = "tt.trip_status = ?";
        $params[] = $status;
        $types .= "s";
    }

    // Origin/Branch filter
    if (!empty($origin) && is_numeric($origin)) {
        $where_conditions[] = "tt.branch_id = ?";
        $params[] = $origin;
        $types .= "i";
    }

    // Date filter - ONLY apply if date is provided and not empty
    if (!empty($date)) {
        $where_conditions[] = "DATE(tt.trip_date) = ?";
        $params[] = $date;
        $types .= "s";
    }

    // Build WHERE clause - if no filters, get ALL records (but with branch filter if applicable)
    $where_clause = "";
    if (!empty($where_conditions)) {
        $where_clause = "WHERE " . implode(" AND ", $where_conditions);
    }

    // Get trip tickets with driver and branch information
    $sql = "SELECT 
                tt.trip_id,
                tt.trip_number,
                tt.trip_date,
                tt.trip_status,
                tt.start_time,
                tt.end_time,
                tt.total_stops,
                tt.total_delivered,
                tt.total_failed,
                tt.remarks,
                tt.created_at,
                d.driver_id,
                d.driver_name,
                d.vehicle_plate_number as vehicle_id,
                d.vehicle_type,
                b.branch_id,
                b.branch_name as origin_name,
                b.branch_code,
                CONCAT(u.first_name, ' ', u.last_name) as created_by_name
            FROM trip_tickets tt
            LEFT JOIN drivers d ON tt.driver_id = d.driver_id
            LEFT JOIN branches b ON tt.branch_id = b.branch_id
            LEFT JOIN users u ON tt.created_by = u.user_id
            $where_clause";

    // Add sorting
    switch ($sort) {
        case 'date':
            $sql .= " ORDER BY tt.trip_date DESC, tt.created_at DESC";
            break;
        case 'status':
            $sql .= " ORDER BY tt.trip_status, tt.trip_date DESC";
            break;
        case 'driver':
            $sql .= " ORDER BY d.driver_name, tt.trip_date DESC";
            break;
        default:
            $sql .= " ORDER BY tt.trip_date DESC, tt.created_at DESC";
    }

    $stmt = $conn->prepare($sql);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();

    $total_trips = 0;
    $completed_count = 0;
    $in_progress_count = 0;
    $delayed_count = 0;

    while ($row = $result->fetch_assoc()) {
        $total_trips++;
        
        // Count by status
        if ($row['trip_status'] == 'completed') $completed_count++;
        elseif ($row['trip_status'] == 'in-progress') $in_progress_count++;
        elseif ($row['trip_status'] == 'delayed') $delayed_count++;

        // Get deliveries for this trip
        $deliveries_sql = "SELECT 
                            COUNT(*) as delivery_count,
                            COUNT(DISTINCT so_id) as order_count
                          FROM deliveries 
                          WHERE trip_id = ?";
        $deliveries_stmt = $conn->prepare($deliveries_sql);
        $deliveries_stmt->bind_param("i", $row['trip_id']);
        $deliveries_stmt->execute();
        $deliveries_result = $deliveries_stmt->get_result();
        $deliveries_row = $deliveries_result->fetch_assoc();
        
        $delivery_count = $deliveries_row['delivery_count'] ?? 0;
        $order_count = $deliveries_row['order_count'] ?? 0;

        // Get destinations
        $destinations_sql = "SELECT 
                                c.city,
                                c.customer_name
                            FROM deliveries del
                            JOIN customers c ON del.customer_id = c.customer_id
                            WHERE del.trip_id = ?
                            LIMIT 2";
        $destinations_stmt = $conn->prepare($destinations_sql);
        $destinations_stmt->bind_param("i", $row['trip_id']);
        $destinations_stmt->execute();
        $destinations_result = $destinations_stmt->get_result();
        
        $destinations = [];
        while ($dest = $destinations_result->fetch_assoc()) {
            $destinations[] = $dest['city'] ?? $dest['customer_name'];
        }
        
        $destination_text = !empty($destinations) ? implode(', ', $destinations) : 'No destinations';
        if ($delivery_count > 2) {
            $destination_text .= ' + ' . ($delivery_count - 2) . ' more';
        }

        // Get item count from sales_order_items
        $items_sql = "SELECT COUNT(soi.so_item_id) as item_count
                     FROM deliveries del
                     JOIN sales_orders so ON del.so_id = so.so_id
                     JOIN sales_order_items soi ON so.so_id = soi.so_id
                     WHERE del.trip_id = ?";
        $items_stmt = $conn->prepare($items_sql);
        $items_stmt->bind_param("i", $row['trip_id']);
        $items_stmt->execute();
        $items_result = $items_stmt->get_result();
        $items_row = $items_result->fetch_assoc();
        $item_count = $items_row['item_count'] ?? 0;

        // Format times
        $departure_time = $row['start_time'] ? date('M d, Y h:i A', strtotime($row['start_time'])) : 'Not started';
        $eta = $row['trip_date'] ? date('M d, Y', strtotime($row['trip_date'])) . ' 05:00 PM' : 'N/A';
        $actual_arrival = $row['end_time'] ? date('M d, Y h:i A', strtotime($row['end_time'])) : null;

        $response['trips'][] = [
            'trip_id' => $row['trip_id'],
            'trip_number' => $row['trip_number'],
            'driver_name' => $row['driver_name'] ?? 'Unassigned',
            'driver_id' => $row['driver_id'],
            'vehicle_id' => $row['vehicle_id'] ?? $row['vehicle_type'] ?? 'N/A',
            'origin' => $row['origin_name'] ?? 'Unknown Branch',
            'origin_code' => $row['branch_code'] ?? '',
            'destination' => $destination_text,
            'item_count' => $item_count,
            'departure' => $departure_time,
            'eta' => $eta,
            'actual_arrival' => $actual_arrival,
            'status' => $row['trip_status'],
            'total_stops' => $row['total_stops'] ?? $delivery_count,
            'total_delivered' => $row['total_delivered'] ?? 0,
            'total_failed' => $row['total_failed'] ?? 0,
            'remarks' => $row['remarks'] ?? '',
            'trip_date' => $row['trip_date'],
            'invoice_no' => 'INV-' . $row['trip_number']
        ];
    }

    $response['stats'] = [
        'totalTrips' => $total_trips,
        'completedTrips' => $completed_count,
        'inProgressTrips' => $in_progress_count,
        'delayedTrips' => $delayed_count
    ];

    echo json_encode($response);
    exit;
}

// Handle AJAX request for trip details
if (isset($_GET['ajax_details']) && isset($_GET['id'])) {
    header('Content-Type: application/json');
    
    $trip_id = intval($_GET['id']);
    
    $sql = "SELECT 
                tt.trip_id,
                tt.trip_number,
                tt.trip_date,
                tt.trip_status,
                tt.start_time,
                tt.end_time,
                tt.total_stops,
                tt.total_delivered,
                tt.total_failed,
                tt.remarks,
                tt.created_at,
                d.driver_id,
                d.driver_name,
                d.vehicle_plate_number as vehicle_id,
                d.vehicle_type,
                d.contact_number as driver_contact,
                b.branch_id,
                b.branch_name as origin_name,
                b.branch_code,
                b.address as origin_address,
                CONCAT(u.first_name, ' ', u.last_name) as created_by_name,
                u.email as created_by_email
            FROM trip_tickets tt
            LEFT JOIN drivers d ON tt.driver_id = d.driver_id
            LEFT JOIN branches b ON tt.branch_id = b.branch_id
            LEFT JOIN users u ON tt.created_by = u.user_id
            WHERE tt.trip_id = ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $trip_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        // Get deliveries for this trip
        $deliveries_sql = "SELECT 
                            del.*,
                            c.customer_name,
                            c.address as customer_address,
                            c.city,
                            c.contact_person,
                            c.phone_number,
                            so.so_number,
                            so.total_amount
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
        $total_items = 0;
        
        while ($del = $deliveries_result->fetch_assoc()) {
            // Get items for this delivery
            $items_sql = "SELECT 
                            i.item_name,
                            i.item_code,
                            soi.quantity_ordered,
                            soi.unit_price,
                            (soi.quantity_ordered * soi.unit_price) as line_total
                          FROM sales_order_items soi
                          JOIN items i ON soi.item_id = i.item_id
                          WHERE soi.so_id = ?";
            $items_stmt = $conn->prepare($items_sql);
            $items_stmt->bind_param("i", $del['so_id']);
            $items_stmt->execute();
            $items_result = $items_stmt->get_result();
            
            $items = [];
            while ($item = $items_result->fetch_assoc()) {
                $items[] = $item;
                $total_items += $item['quantity_ordered'];
            }
            
            $deliveries[] = [
                'so_number' => $del['so_number'],
                'customer_name' => $del['customer_name'],
                'address' => $del['customer_address'] . ', ' . $del['city'],
                'contact_person' => $del['contact_person'],
                'phone' => $del['phone_number'],
                'stop_sequence' => $del['stop_sequence'],
                'delivery_status' => $del['delivery_status'],
                'delivery_date' => $del['delivery_date'],
                'signed_by' => $del['signed_by'],
                'remarks' => $del['remarks'],
                'total_amount' => $del['total_amount'],
                'items' => $items
            ];
        }
        
        // Get pick list information
        $picklist_sql = "SELECT 
                            pl.pick_list_id,
                            pl.pick_list_number,
                            pl.pick_date,
                            pl.pick_status,
                            pl.picked_by,
                            pl.verified_by,
                            CONCAT(u1.first_name, ' ', u1.last_name) as picked_by_name,
                            CONCAT(u2.first_name, ' ', u2.last_name) as verified_by_name
                        FROM pick_lists pl
                        LEFT JOIN users u1 ON pl.picked_by = u1.user_id
                        LEFT JOIN users u2 ON pl.verified_by = u2.user_id
                        WHERE pl.so_id IN (SELECT so_id FROM deliveries WHERE trip_id = ?)
                        LIMIT 1";
        $picklist_stmt = $conn->prepare($picklist_sql);
        $picklist_stmt->bind_param("i", $trip_id);
        $picklist_stmt->execute();
        $picklist_result = $picklist_stmt->get_result();
        $picklist_row = $picklist_result->fetch_assoc();

        $response = [
            'success' => true,
            'trip' => [
                'trip_id' => $row['trip_id'],
                'trip_number' => $row['trip_number'],
                'trip_date' => $row['trip_date'],
                'driver_name' => $row['driver_name'] ?? 'Unassigned',
                'driver_contact' => $row['driver_contact'] ?? 'N/A',
                'vehicle_id' => $row['vehicle_id'] ?? $row['vehicle_type'] ?? 'N/A',
                'vehicle_type' => $row['vehicle_type'] ?? 'N/A',
                'origin' => $row['origin_name'] ?? 'Unknown Branch',
                'origin_code' => $row['branch_code'] ?? '',
                'origin_address' => $row['origin_address'] ?? 'N/A',
                'destination_count' => count($deliveries),
                'deliveries' => $deliveries,
                'total_items' => $total_items,
                'departure' => $row['start_time'] ? date('M d, Y h:i A', strtotime($row['start_time'])) : 'Not started',
                'eta' => $row['trip_date'] ? date('M d, Y', strtotime($row['trip_date'])) . ' 05:00 PM' : 'N/A',
                'actual_arrival' => $row['end_time'] ? date('M d, Y h:i A', strtotime($row['end_time'])) : null,
                'status' => $row['trip_status'],
                'total_stops' => $row['total_stops'] ?? count($deliveries),
                'total_delivered' => $row['total_delivered'] ?? 0,
                'total_failed' => $row['total_failed'] ?? 0,
                'pick_list' => $picklist_row,
                'remarks' => $row['remarks'] ?? 'No remarks',
                'created_by' => $row['created_by_name'] ?? 'System',
                'created_at' => date('M d, Y h:i A', strtotime($row['created_at'])),
                'invoice_no' => 'INV-' . $row['trip_number']
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

// Get user initials for avatar
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

// Set default date to empty string to show all data
$default_date = isset($_GET['date']) ? $_GET['date'] : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Global - Trip Tickets</title>
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
    <!-- SweetAlert2 -->
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        .table td, .table th {
            vertical-align: middle;
        }
        .text-end {
            text-align: right !important;
        }
        .text-start {
            text-align: left !important;
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
        /* ===== FILTER REPORTS & DROPDOWN - GAYA SA BRANCH RECORDS ===== */

/* Form Card - Base */
.form-card {
    background: white;
    border-radius: clamp(14px, 3vw, 20px);
    padding: clamp(0.8rem, 3vw, 1.5rem);
    box-shadow: 0 8px 20px -5px rgba(4, 120, 87, 0.12);
    border: 1px solid rgba(68, 211, 78, 0.2);
    margin-bottom: clamp(1rem, 2vw, 1.5rem);
    transition: all 0.3s ease;
    width: 100%;
}

/* Card Header */
.form-card h5 {
    color: var(--dark-green);
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: clamp(1rem, 4vw, 1.3rem);
    margin-bottom: clamp(0.5rem, 2vw, 1rem);
    padding-bottom: clamp(0.3rem, 1.5vw, 0.5rem);
    border-bottom: 2px solid rgba(68, 211, 78, 0.2);
    width: 100%;
}

.form-card h5 i {
    color: var(--primary-green);
    background: rgba(68, 211, 78, 0.1);
    padding: clamp(0.3rem, 1.5vw, 0.5rem);
    border-radius: clamp(6px, 2vw, 10px);
    font-size: clamp(0.9rem, 3.5vw, 1.2rem);
}

/* Form Labels */
.form-label {
    font-weight: 600;
    color: var(--dark-color);
    margin-bottom: clamp(0.2rem, 1vw, 0.4rem);
    display: flex;
    align-items: center;
    gap: 0.3rem;
    font-size: clamp(0.75rem, 3vw, 0.9rem);
}

.form-label i {
    color: var(--primary-green);
    font-size: clamp(0.8rem, 3.5vw, 1rem);
}

/* FORM CONTROLS - UNIFIED (SELECT & INPUT) */
.form-select, 
.form-control {
    border: 2px solid #e5e7eb;
    border-radius: clamp(6px, 2vw, 10px);
    padding: clamp(0.35rem, 2vw, 0.7rem) clamp(0.7rem, 3vw, 1rem);
    font-size: clamp(0.75rem, 3.5vw, 0.95rem);
    height: auto;
    min-height: clamp(32px, 7vw, 42px);
    width: 100%;
    background-color: white;
    transition: all 0.2s ease;
    line-height: 1.4;
    box-sizing: border-box;
}

/* SELECT SPECIFIC - WITH CUSTOM ARROW */
.form-select {
    background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3e%3cpath fill='none' stroke='%23047857' stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='m2 5 6 6 6-6'/%3e%3c/svg%3e");
    background-repeat: no-repeat;
    background-position: right clamp(0.5rem, 2vw, 0.75rem) center;
    background-size: clamp(10px, 2.5vw, 14px) clamp(8px, 2vw, 12px);
    padding-right: clamp(1.8rem, 6vw, 2.2rem);
    appearance: none;
    -webkit-appearance: none;
    -moz-appearance: none;
}

/* INPUT SPECIFIC */
.form-control {
    padding-right: clamp(0.7rem, 3vw, 1rem);
}

/* Focus States */
.form-select:focus, 
.form-control:focus {
    border-color: var(--primary-green);
    box-shadow: 0 0 0 3px rgba(68, 211, 78, 0.15);
    outline: none;
}

/* Hover States */
.form-select:hover, 
.form-control:hover {
    border-color: var(--primary-green);
    background-color: rgba(68, 211, 78, 0.02);
}

/* Calendar Icon */
input[type="date"]::-webkit-calendar-picker-indicator,
input[type="month"]::-webkit-calendar-picker-indicator {
    width: clamp(14px, 3.5vw, 18px);
    height: clamp(14px, 3.5vw, 18px);
    padding: clamp(1px, 0.5vw, 3px);
    cursor: pointer;
    opacity: 0.6;
    transition: all 0.2s ease;
}

input[type="date"]::-webkit-calendar-picker-indicator:hover,
input[type="month"]::-webkit-calendar-picker-indicator:hover {
    opacity: 1;
    background: rgba(68, 211, 78, 0.1);
    transform: scale(1.1);
}

/* ===== RESPONSIVE GRID - FIXED VERSION ===== */

/* Remove conflicting row styles */
.form-card .row {
    display: flex;
    flex-wrap: wrap;
    margin-right: -0.5rem;
    margin-left: -0.5rem;
}

.form-card .row > [class*="col-"] {
    padding-right: 0.5rem;
    padding-left: 0.5rem;
    margin-bottom: 1rem;
}

/* Gutter spacing */
.g-3 {
    --bs-gutter-x: 1rem;
    --bs-gutter-y: 1rem;
}

/* ===== MOBILE (below 768px) - 2 COLUMNS ===== */
@media (max-width: 767px) {
    /* Force 2 columns sa mobile */
    .form-card .row > .col-12,
    .form-card .row > .col-sm-6,
    .form-card .row > .col-md-3 {
        flex: 0 0 50% !important;
        max-width: 50% !important;
    }
    
    /* Adjust spacing */
    .form-card {
        padding: 1rem;
    }
    
    .form-card h5 {
        font-size: 1.1rem;
        margin-bottom: 0.8rem;
    }
    
    .form-label {
        font-size: 0.8rem;
        margin-bottom: 0.2rem;
    }
    
    .form-select, 
    .form-control {
        font-size: 0.8rem;
        padding: 0.35rem 0.6rem;
        min-height: 36px;
    }
}

/* ===== TABLET TO DESKTOP (768px and up) - 4 COLUMNS ===== */
@media (min-width: 768px) {
    .form-card .row > .col-md-3 {
        flex: 0 0 25%;
        max-width: 25%;
    }
}

/* ===== EXTRA SMALL (below 400px) ===== */
@media (max-width: 399px) {
    .form-card {
        padding: 0.7rem;
    }
    
    .form-card h5 {
        font-size: 1rem;
    }
    
    .form-label {
        font-size: 0.7rem;
    }
    
    .form-select, 
    .form-control {
        font-size: 0.7rem;
        padding: 0.25rem 0.5rem;
        min-height: 32px;
    }
}

/* ===== DROPDOWN OPTIONS ===== */
.form-select option {
    font-size: inherit;
    padding: clamp(0.2rem, 1vw, 0.4rem);
}

/* ===== ANIMATION ===== */
.form-card {
    animation: fadeInUp 0.3s ease-out;
}

@keyframes fadeInUp {
    from {
        opacity: 0;
        transform: translateY(10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
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
                            <i class="bi bi-people"></i>
                            <span class="nav-text">User Management</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="trip_tickets.php">
                            <i class="bi bi-ticket-perforated"></i>
                            <span class="nav-text">Trip Tickets</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="driver_tracking.php">
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
            <!-- TRIP TICKETS PAGE -->
            <div id="tripsContent" class="page-content active">
                <div class="navbar-top">
                    <button class="mobile-toggle-btn" id="mobileToggleBtn">
                        <i class="bi bi-list"></i>
                    </button>
                    <div class="page-title">
                        <h2>All Trip Tickets</h2>
                        <p>Track all deliveries and trip information across all locations</p>
                    </div>
                </div>

              <div class="row stat-card-row g-1 g-sm-2 mb-4">
    <!-- Card 1 - Total Trips -->
    <div class="col">
        <div class="stat-card total">
            <i class="bi bi-truck"></i>
            <div class="stat-content">
                <div class="stat-value" id="totalTrips">0</div>
                <div class="stat-label">Total Trips</div>
            </div>
        </div>
    </div>
    
    <!-- Card 2 - Completed -->
    <div class="col">
        <div class="stat-card sales">
            <i class="bi bi-check-circle"></i>
            <div class="stat-content">
                <div class="stat-value" id="completedTrips">0</div>
                <div class="stat-label">Completed</div>
            </div>
        </div>
    </div>
    
    <!-- Card 3 - In Progress -->
    <div class="col">
        <div class="stat-card complete">
            <i class="bi bi-gear"></i>
            <div class="stat-content">
                <div class="stat-value" id="inProgressTrips">0</div>
                <div class="stat-label">In Progress</div>
            </div>
        </div>
    </div>
    
    <!-- Card 4 - Delayed -->
    <div class="col">
        <div class="stat-card total">
            <i class="bi bi-exclamation-triangle"></i>
            <div class="stat-content">
                <div class="stat-value" id="delayedTrips">0</div>
                <div class="stat-label">Delayed</div>
            </div>
        </div>
    </div>
</div>
               <!-- FILTER SECTION - TRIP TICKETS -->
<div class="row g-3 mb-4">
    <div class="col-12">
        <div class="form-card">
            <div class="filter-header">
                <h5 class="mb-0">
                    <i class="bi bi-funnel"></i> Filter Trip Tickets
                </h5>
                <button class="filter-toggle-btn" id="toggleTripFilter" onclick="toggleFilter('trip')" title="Toggle Filter">
                    <i class="bi bi-chevron-down" id="tripFilterIcon"></i>
                </button>
            </div>
            <div class="filter-content" id="tripFilterContent">
                <div class="row mt-3 g-3">
                    <!-- Status Filter -->
                    <div class="col-12 col-sm-6 col-md-3">
                        <label class="form-label">
                            <i class="bi bi-flag"></i> Status
                        </label>
                        <select class="form-select" id="statusFilter" onchange="loadTrips()">
                            <option value="">All Status</option>
                            <option value="planned">Planned</option>
                            <option value="in-progress">In Progress</option>
                            <option value="completed">Completed</option>
                            <option value="delayed">Delayed</option>
                            <option value="cancelled">Cancelled</option>
                        </select>
                    </div>
                    
                    <!-- Origin/Branch Filter -->
                    <div class="col-12 col-sm-6 col-md-3">
                        <label class="form-label">
                            <i class="bi bi-building"></i> Origin/Branch
                        </label>
                        <select class="form-select" id="originFilter" onchange="loadTrips()">
                            <option value="">All Origins</option>
                            <?php foreach ($branches as $branch): ?>
                                <option value="<?php echo $branch['branch_id']; ?>">
                                    <?php echo htmlspecialchars($branch['branch_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <!-- Trip Date Filter -->
                    <div class="col-12 col-sm-6 col-md-3">
                        <label class="form-label">
                            <i class="bi bi-calendar"></i> Trip Date
                        </label>
                        <input type="date" class="form-control" id="dateFilter" value="<?php echo $default_date; ?>" placeholder="Select date" onchange="loadTrips()">
                    </div>
                    
                    <!-- Sort By Filter -->
                    <div class="col-12 col-sm-6 col-md-3">
                        <label class="form-label">
                            <i class="bi bi-sort-down"></i> Sort By
                        </label>
                        <select class="form-select" id="sortFilter" onchange="loadTrips()">
                            <option value="date">Date (Newest First)</option>
                            <option value="status">Status</option>
                            <option value="driver">Driver Name</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

                <div class="data-table">
                    <div class="table-header">
                        <h5>Trip Ticket Information</h5>
                    </div>
                    <div class="table-container">
                        <table class="table custom-table compact-table">
                            <thead>
                                <tr>
                                    <th>Trip #</th>
                                    <th>Driver</th>
                                    <th>Origin</th>
                                    <th>Destination</th>
                                    <th>Items</th>
                                    <th>Departure</th>
                                    <th>Status</th>
                                    <th>Stops</th>
                                    <th>Delivered</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="tripsTable">
                                <tr>
                                    <td colspan="10" class="text-center py-4">
                                        <div class="spinner-border text-primary" role="status">
                                            <span class="visually-hidden">Loading...</span>
                                        </div>
                                        <p class="mt-2">Loading trips...</p>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
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
                <a class="nav-link" href="drivers.php">
                    <i class="bi bi-people"></i>
                    <span>Users</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link active" href="trip_tickets.php">
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
                        <?php echo $user_initials; ?>
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
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // ================= SIDEBAR FUNCTIONS =================
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const isMobile = window.innerWidth <= 992;
            
            if (isMobile) {
                sidebar.classList.toggle('active');
                
                if (!document.querySelector('.sidebar-overlay')) {
                    const overlay = document.createElement('div');
                    overlay.className = 'sidebar-overlay';
                    document.body.appendChild(overlay);
                    overlay.addEventListener('click', closeMobileSidebar);
                    setTimeout(() => overlay.classList.add('active'), 10);
                } else {
                    const overlay = document.querySelector('.sidebar-overlay');
                    overlay.classList.toggle('active');
                    if (!sidebar.classList.contains('active')) {
                        setTimeout(() => overlay?.remove(), 300);
                    }
                }
            } else {
                sidebar.classList.toggle('collapsed');
                localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
                document.querySelectorAll('.nav-text').forEach(text => {
                    text.style.display = sidebar.classList.contains('collapsed') ? 'none' : 'inline-block';
                });
                const mainContent = document.querySelector('.main-content');
                if (mainContent) {
                    mainContent.style.marginLeft = sidebar.classList.contains('collapsed') ? '80px' : '250px';
                }
            }
        }

        function closeMobileSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.querySelector('.sidebar-overlay');
            sidebar.classList.remove('active');
            if (overlay) {
                overlay.classList.remove('active');
                setTimeout(() => overlay.remove(), 300);
            }
        }

        function initializeSidebar() {
            const sidebar = document.getElementById('sidebar');
            if (window.innerWidth > 992) {
                const savedCollapsed = localStorage.getItem('sidebarCollapsed');
                if (savedCollapsed === 'true') {
                    sidebar.classList.add('collapsed');
                    document.querySelectorAll('.nav-text').forEach(text => text.style.display = 'none');
                    const mainContent = document.querySelector('.main-content');
                    if (mainContent) mainContent.style.marginLeft = '80px';
                } else {
                    sidebar.classList.remove('collapsed');
                    document.querySelectorAll('.nav-text').forEach(text => text.style.display = 'inline-block');
                    const mainContent = document.querySelector('.main-content');
                    if (mainContent) mainContent.style.marginLeft = '250px';
                }
            } else {
                sidebar.classList.remove('active', 'collapsed');
                document.querySelectorAll('.nav-text').forEach(text => text.style.display = 'inline-block');
                const mainContent = document.querySelector('.main-content');
                if (mainContent) mainContent.style.marginLeft = '0';
            }
        }

        function handleSidebarResize() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.querySelector('.sidebar-overlay');
            
            if (window.innerWidth > 992) {
                overlay?.remove();
                sidebar.classList.remove('active');
                const savedCollapsed = localStorage.getItem('sidebarCollapsed');
                if (savedCollapsed === 'true') {
                    sidebar.classList.add('collapsed');
                    document.querySelectorAll('.nav-text').forEach(text => text.style.display = 'none');
                    const mainContent = document.querySelector('.main-content');
                    if (mainContent) mainContent.style.marginLeft = '80px';
                } else {
                    sidebar.classList.remove('collapsed');
                    document.querySelectorAll('.nav-text').forEach(text => text.style.display = 'inline-block');
                    const mainContent = document.querySelector('.main-content');
                    if (mainContent) mainContent.style.marginLeft = '250px';
                }
            } else {
                sidebar.classList.remove('collapsed');
                document.querySelectorAll('.nav-text').forEach(text => text.style.display = 'inline-block');
                const mainContent = document.querySelector('.main-content');
                if (mainContent) mainContent.style.marginLeft = '0';
            }
        }
        // ================= END SIDEBAR FUNCTIONS =================

        // ================= MOBILE NAVIGATION FUNCTIONS =================
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

        // ================= PROFILE/LOGOUT FUNCTIONS =================
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
                    localStorage.removeItem('sidebarCollapsed');
                    window.location.href = '../logout.php';
                }
            });
        }

        // Original logout function for sidebar
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
                    localStorage.removeItem('sidebarCollapsed');
                    window.location.href = '../logout.php';
                }
            });
        }

        // ================= TRIP FUNCTIONS =================
        // Load trips
        async function loadTrips() {
            const tbody = document.getElementById('tripsTable');
            tbody.innerHTML = '<tr><td colspan="10" class="text-center py-4"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div><p class="mt-2">Loading trips...</p></td></tr>';
            
            try {
                const status = document.getElementById('statusFilter').value;
                const origin = document.getElementById('originFilter').value;
                const date = document.getElementById('dateFilter').value;
                const sort = document.getElementById('sortFilter').value;

                const params = new URLSearchParams({
                    ajax: 1,
                    status: status,
                    origin: origin,
                    date: date,
                    sort: sort
                });

                const response = await fetch('trip_tickets.php?' + params);
                const data = await response.json();
                
                if (data.success) {
                    displayTrips(data.trips || []);
                    updateTripStats(data.stats || {});
                }
            } catch (error) {
                console.error('Error loading trips:', error);
                tbody.innerHTML = '<tr><td colspan="10" class="text-center py-4 text-danger">Error loading trips</td></tr>';
            }
        }

        function displayTrips(trips) {
            const tbody = document.getElementById('tripsTable');
            
            if (trips.length === 0) {
                tbody.innerHTML = '<tr><td colspan="10" class="text-center py-4">No trips found</td></tr>';
                return;
            }

            tbody.innerHTML = trips.map(trip => {
                let statusBadge = 'bg-info';
                let statusText = trip.status;
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
                } else if (trip.status === 'planned') {
                    statusBadge = 'bg-info';
                    statusText = 'Planned';
                }

                return `
                <tr>
                    <td><strong>${escapeHtml(trip.trip_number)}</strong></td>
                    <td>${escapeHtml(trip.driver_name)}</td>
                    <td>${escapeHtml(trip.origin)}</td>
                    <td>${escapeHtml(trip.destination)}</td>
                    <td class="text-end">${trip.item_count}</td>
                    <td>${escapeHtml(trip.departure)}</td>
                    <td>
                        <span class="badge ${statusBadge}">
                            ${statusText}
                        </span>
                    </td>
                    <td class="text-end">${trip.total_stops || 0}</td>
                    <td class="text-end">${trip.total_delivered || 0}</td>
                    <td>
                        <button class="btn-action btn-view" onclick="viewTrip(${trip.trip_id})">
                            <i class="bi bi-eye"></i>
                        </button>
                    </td>
                </tr>
            `;
            }).join('');
        }

        function updateTripStats(stats) {
            document.getElementById('totalTrips').textContent = stats.totalTrips || 0;
            document.getElementById('completedTrips').textContent = stats.completedTrips || 0;
            document.getElementById('inProgressTrips').textContent = stats.inProgressTrips || 0;
            document.getElementById('delayedTrips').textContent = stats.delayedTrips || 0;
        }

        function viewTrip(tripId) {
            const modal = new bootstrap.Modal(document.getElementById('tripModal'));
            const details = document.getElementById('tripDetails');
            details.innerHTML = '<div class="text-center py-4"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div><p class="mt-2">Loading trip details...</p></div>';
            modal.show();
            
            fetch('trip_tickets.php?ajax_details=1&id=' + tripId)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const trip = data.trip;
                        let statusBadge = 'bg-info';
                        if (trip.status === 'completed') statusBadge = 'bg-success';
                        else if (trip.status === 'in-progress') statusBadge = 'bg-warning';
                        else if (trip.status === 'delayed') statusBadge = 'bg-danger';
                        else if (trip.status === 'cancelled') statusBadge = 'bg-secondary';
                        
                        let deliveriesHtml = '';
                        if (trip.deliveries && trip.deliveries.length > 0) {
                            deliveriesHtml = '<h6 class="mt-4">Delivery Stops</h6><div class="table-responsive"><table class="table table-sm"><thead><tr><th>Stop</th><th>Customer</th><th>Address</th><th>Status</th><th>Signed By</th></tr></thead><tbody>';
                            trip.deliveries.forEach(del => {
                                let delStatusBadge = 'bg-info';
                                if (del.delivery_status === 'delivered') delStatusBadge = 'bg-success';
                                else if (del.delivery_status === 'rejected') delStatusBadge = 'bg-danger';
                                else if (del.delivery_status === 'partial') delStatusBadge = 'bg-warning';
                                
                                deliveriesHtml += `<tr>
                                    <td>${del.stop_sequence || '-'}</td>
                                    <td>${escapeHtml(del.customer_name)}</td>
                                    <td>${escapeHtml(del.address || 'N/A')}</td>
                                    <td><span class="badge ${delStatusBadge}">${escapeHtml(del.delivery_status)}</span></td>
                                    <td>${escapeHtml(del.signed_by || '-')}</td>
                                </tr>`;
                            });
                            deliveriesHtml += '</tbody></table></div>';
                        }
                        
                        details.innerHTML = `
                            <div class="row">
                                <div class="col-md-6">
                                    <dl class="row">
                                        <dt class="col-sm-5">Trip Number:</dt>
                                        <dd class="col-sm-7"><strong>${escapeHtml(trip.trip_number)}</strong></dd>
                                        
                                        <dt class="col-sm-5">Driver:</dt>
                                        <dd class="col-sm-7">${escapeHtml(trip.driver_name)}</dd>
                                        
                                        <dt class="col-sm-5">Driver Contact:</dt>
                                        <dd class="col-sm-7">${escapeHtml(trip.driver_contact)}</dd>
                                        
                                        <dt class="col-sm-5">Vehicle:</dt>
                                        <dd class="col-sm-7">${escapeHtml(trip.vehicle_id)} (${escapeHtml(trip.vehicle_type)})</dd>
                                        
                                        <dt class="col-sm-5">Origin:</dt>
                                        <dd class="col-sm-7">${escapeHtml(trip.origin)} (${escapeHtml(trip.origin_code)})</dd>
                                        
                                        <dt class="col-sm-5">Origin Address:</dt>
                                        <dd class="col-sm-7">${escapeHtml(trip.origin_address)}</dd>
                                    </dl>
                                </div>
                                <div class="col-md-6">
                                    <dl class="row">
                                        <dt class="col-sm-5">Trip Date:</dt>
                                        <dd class="col-sm-7">${escapeHtml(trip.trip_date)}</dd>
                                        
                                        <dt class="col-sm-5">Departure:</dt>
                                        <dd class="col-sm-7">${escapeHtml(trip.departure)}</dd>
                                        
                                        <dt class="col-sm-5">ETA:</dt>
                                        <dd class="col-sm-7">${escapeHtml(trip.eta)}</dd>
                                        
                                        <dt class="col-sm-5">Actual Arrival:</dt>
                                        <dd class="col-sm-7">${escapeHtml(trip.actual_arrival || 'Not arrived')}</dd>
                                        
                                        <dt class="col-sm-5">Status:</dt>
                                        <dd class="col-sm-7"><span class="badge ${statusBadge}">${escapeHtml(trip.status)}</span></dd>
                                        
                                        <dt class="col-sm-5">Created By:</dt>
                                        <dd class="col-sm-7">${escapeHtml(trip.created_by)} on ${escapeHtml(trip.created_at)}</dd>
                                    </dl>
                                </div>
                            </div>
                            
                            <div class="row mt-3">
                                <div class="col-md-4">
                                    <div class="border p-3 text-center">
                                        <h6>Total Stops</h6>
                                        <h3>${trip.total_stops}</h3>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="border p-3 text-center">
                                        <h6>Delivered</h6>
                                        <h3 class="text-success">${trip.total_delivered}</h3>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="border p-3 text-center">
                                        <h6>Failed</h6>
                                        <h3 class="text-danger">${trip.total_failed}</h3>
                                    </div>
                                </div>
                            </div>
                            
                            ${deliveriesHtml}
                            
                            <div class="mt-3">
                                <h6>Remarks</h6>
                                <p class="border p-2">${escapeHtml(trip.remarks)}</p>
                            </div>
                        `;
                    } else {
                        details.innerHTML = '<p class="text-danger">Failed to load trip details.</p>';
                    }
                })
                .catch(error => {
                    console.error('Error loading trip details:', error);
                    details.innerHTML = '<p class="text-danger">Error loading trip details.</p>';
                });
        }

        function escapeHtml(text) {
            if (!text) return '';
            if (typeof text !== 'string') text = String(text);
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // ================= INITIALIZATION =================
        document.addEventListener('DOMContentLoaded', function() {
            initializeSidebar();
            initMobileNav();
            
            const mobileToggleBtn = document.getElementById('mobileToggleBtn');
            if (mobileToggleBtn) {
                mobileToggleBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    toggleSidebar();
                });
            }
            
            const desktopToggleBtn = document.getElementById('desktopToggleBtn');
            if (desktopToggleBtn) {
                desktopToggleBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    toggleSidebar();
                });
            }
            
            document.querySelectorAll('.sidebar .nav-link').forEach(link => {
                link.addEventListener('click', function() {
                    if (window.innerWidth <= 992) {
                        closeMobileSidebar();
                    }
                });
            });
            
            document.addEventListener('click', function(event) {
                const sidebar = document.getElementById('sidebar');
                const mobileToggleBtn = document.getElementById('mobileToggleBtn');
                const overlay = document.querySelector('.sidebar-overlay');
                const isMobile = window.innerWidth <= 992;
                
                if (isMobile && sidebar.classList.contains('active') && 
                    !sidebar.contains(event.target) && 
                    (!mobileToggleBtn || !mobileToggleBtn.contains(event.target)) &&
                    (!overlay || !overlay.contains(event.target))) {
                    closeMobileSidebar();
                }
            });

            window.addEventListener('resize', function() {
                handleSidebarResize();
                initMobileNav();
            });
            
            // Load trips on page load
            loadTrips();
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 'b' && window.innerWidth > 992) {
                e.preventDefault();
                toggleSidebar();
            }
            else if (e.key === 'Escape' && window.innerWidth <= 992) {
                closeMobileSidebar();
            }
            else if (e.key === 'Escape') {
                const profileModal = document.getElementById('profileModal');
                if (profileModal.classList.contains('show')) {
                    bootstrap.Modal.getInstance(profileModal).hide();
                }
                const tripModal = document.getElementById('tripModal');
                if (tripModal.classList.contains('show')) {
                    bootstrap.Modal.getInstance(tripModal).hide();
                }
            }
            else if (e.ctrlKey && e.key === 'r') {
                e.preventDefault();
                loadTrips();
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