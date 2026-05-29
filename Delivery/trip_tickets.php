<?php
require_once '../config/database.php';
require_once '../config/session_handler.php';

// Check login
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

// ================= USER CONTEXT =================
$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['first_name'] . ' ' . $_SESSION['last_name'];
$user_role = $_SESSION['role'];
$branch_id = $_SESSION['branch_id'];
$view_all_branches = $_SESSION['view_all_branches'] ?? false;

// Get user's branch name for display
$branch_name = 'All Branches';
if (!$view_all_branches && $branch_id > 0) {
    $branch_query = "SELECT branch_name FROM branches WHERE branch_id = ?";
    $branch_stmt = $conn->prepare($branch_query);
    $branch_stmt->bind_param("i", $branch_id);
    $branch_stmt->execute();
    $branch_result = $branch_stmt->get_result();
    if ($branch_row = $branch_result->fetch_assoc()) {
        $branch_name = $branch_row['branch_name'];
    }
    $branch_stmt->close();
}

// ================= GET DRIVER ID FOR DELIVERY ROLE =================
$driver_id = 0;
if ($user_role == 'delivery') {
    // Try to get driver_id from session first
    $driver_id = $_SESSION['driver_id'] ?? 0;
    
    // If not in session, get from users table
    if ($driver_id == 0) {
        $driver_query = "SELECT driver_id FROM users WHERE user_id = ? AND driver_id IS NOT NULL";
        $driver_stmt = $conn->prepare($driver_query);
        $driver_stmt->bind_param("i", $user_id);
        $driver_stmt->execute();
        $driver_result = $driver_stmt->get_result();
        if ($driver_row = $driver_result->fetch_assoc()) {
            $driver_id = $driver_row['driver_id'];
            $_SESSION['driver_id'] = $driver_id;
        }
        $driver_stmt->close();
    }
}

// ================= CHECK COLUMNS =================
$tt_branch_column_exists = false;
$check_tt_column = $conn->query("SHOW COLUMNS FROM trip_tickets LIKE 'branch_id'");
if ($check_tt_column && $check_tt_column->num_rows > 0) {
    $tt_branch_column_exists = true;
}

$drivers_branch_column_exists = false;
$check_drivers_column = $conn->query("SHOW COLUMNS FROM drivers LIKE 'branch_id'");
if ($check_drivers_column && $check_drivers_column->num_rows > 0) {
    $drivers_branch_column_exists = true;
}

// ================= HANDLE STATUS UPDATE =================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_trip_status') {
    header('Content-Type: application/json');
    
    try {
        $trip_id = intval($_POST['trip_id']);
        $new_status = $_POST['status'];
        
        // Verify that the trip exists and user has permission
        $check_query = "SELECT tt.trip_id, tt.branch_id, tt.driver_id, tt.trip_status 
                       FROM trip_tickets tt
                       WHERE tt.trip_id = ?";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->bind_param("i", $trip_id);
        $check_stmt->execute();
        $result = $check_stmt->get_result();
        $trip = $result->fetch_assoc();
        
        if (!$trip) {
            throw new Exception('Trip ticket not found');
        }
        
        // Check branch permission
        if (!$view_all_branches && $trip['branch_id'] != $branch_id) {
            throw new Exception('You do not have permission to update this trip ticket');
        }
        
        // For delivery role, check if they are the assigned driver
        if ($user_role == 'delivery' && $trip['driver_id'] != $driver_id) {
            throw new Exception('You are not assigned to this trip');
        }
        
        // Update the status
        $update_query = "UPDATE trip_tickets SET trip_status = ?, updated_at = NOW() WHERE trip_id = ?";
        $update_stmt = $conn->prepare($update_query);
        $update_stmt->bind_param("si", $new_status, $trip_id);
        
        if (!$update_stmt->execute()) {
            throw new Exception('Failed to update status');
        }
        
        echo json_encode(['success' => true, 'message' => 'Status updated successfully']);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ================= FUNCTION TO AUTO-UPDATE TRIP STATUS BASED ON DELIVERIES =================
function updateTripStatusBasedOnDeliveries($conn, $trip_id) {
    try {
        // Check all deliveries for this trip
        $check_query = "
            SELECT 
                COUNT(*) as total_deliveries,
                SUM(CASE WHEN delivery_status = 'delivered' THEN 1 ELSE 0 END) as delivered_count,
                SUM(CASE WHEN delivery_status IN ('pending', 'in-transit') THEN 1 ELSE 0 END) as pending_count
            FROM deliveries 
            WHERE trip_id = ?
        ";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->bind_param("i", $trip_id);
        $check_stmt->execute();
        $result = $check_stmt->get_result();
        $delivery_stats = $result->fetch_assoc();
        
        // If all deliveries are delivered, update trip status to completed
        if ($delivery_stats['total_deliveries'] > 0 && 
            $delivery_stats['delivered_count'] == $delivery_stats['total_deliveries']) {
            
            $update_query = "UPDATE trip_tickets SET trip_status = 'completed', updated_at = NOW() WHERE trip_id = ? AND trip_status != 'completed'";
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->bind_param("i", $trip_id);
            $update_stmt->execute();
            
            return true;
        }
        
        return false;
    } catch (Exception $e) {
        error_log("Error auto-updating trip status: " . $e->getMessage());
        return false;
    }
}

// Check if we need to auto-update trip status (called when a delivery is completed)
if (isset($_GET['check_trip_status']) && isset($_GET['trip_id'])) {
    header('Content-Type: application/json');
    $trip_id = intval($_GET['trip_id']);
    $updated = updateTripStatusBasedOnDeliveries($conn, $trip_id);
    echo json_encode(['updated' => $updated]);
    exit;
}

// ================= ADD TRIP =================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_trip') {

    $prefix = "TRP" . date("Ymd");
    $random = rand(1000, 9999);
    $trip_number = $prefix . $random;

    $driver_id_post = $_POST['driver_id'];
    $trip_date = $_POST['trip_date'];
    $trip_status = $_POST['trip_status'];
    $total_stops = $_POST['total_stops'] ?: 0;
    $created_by = $user_id;
    $remarks = $_POST['remarks'] ?: NULL;

    // Always use session branch_id for security
    $insert_branch_id = $branch_id;

    $stmt = $conn->prepare("
        INSERT INTO trip_tickets 
        (trip_number, driver_id, branch_id, trip_date, trip_status, total_stops, created_by, remarks) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->bind_param("siisssis",
        $trip_number,
        $driver_id_post,
        $insert_branch_id,
        $trip_date,
        $trip_status,
        $total_stops,
        $created_by,
        $remarks
    );

    if ($stmt->execute()) {
        $success_message = "Trip ticket created successfully!";
    } else {
        $error_message = "Error: " . $stmt->error;
    }

    $stmt->close();
}

// ================= STATISTICS =================
// Use the same filters for statistics
$stats_where = "WHERE 1=1";

if (!$view_all_branches && $branch_id > 0) {
    $stats_where .= " AND tt.branch_id = $branch_id";
}

// Add driver filter for delivery role
if ($user_role == 'delivery' && $driver_id > 0) {
    $stats_where .= " AND tt.driver_id = $driver_id";
}

// Get total trips
$stats_query = "
    SELECT 
        COUNT(DISTINCT tt.trip_id) as total_trips,
        SUM(CASE WHEN tt.trip_status = 'completed' THEN 1 ELSE 0 END) as completed,
        SUM(CASE WHEN tt.trip_status = 'in-progress' THEN 1 ELSE 0 END) as in_transit,
        SUM(CASE WHEN tt.trip_status = 'planned' THEN 1 ELSE 0 END) as pending
    FROM trip_tickets tt
    $stats_where
";

$stats_result = $conn->query($stats_query);
$stats = $stats_result->fetch_assoc();

// ================= DRIVERS =================
$drivers_query = "
    SELECT driver_id, driver_name 
    FROM drivers 
    WHERE status = 'active'
";

if ($drivers_branch_column_exists && !$view_all_branches && $branch_id > 0) {
    $drivers_query .= " AND branch_id = $branch_id";
}

// For delivery role, only show their own driver
if ($user_role == 'delivery' && $driver_id > 0) {
    $drivers_query .= " AND driver_id = $driver_id";
}

$drivers_result = $conn->query($drivers_query);
$drivers = $drivers_result ? $drivers_result->fetch_all(MYSQLI_ASSOC) : [];

// ================= BRANCH DROPDOWN =================
$branches_query = "
    SELECT branch_id, branch_name 
    FROM branches
";

if (!$view_all_branches && $branch_id > 0) {
    $branches_query .= " WHERE branch_id = $branch_id";
}

$branches_result = $conn->query($branches_query);
$branches = $branches_result ? $branches_result->fetch_all(MYSQLI_ASSOC) : [];

// ================= TRIP LIST =================
$trip_tickets_query = "
    SELECT 
        tt.trip_id,
        tt.trip_number,
        tt.driver_id,
        tt.branch_id,
        tt.trip_date,
        tt.trip_status,
        tt.total_stops,
        tt.total_delivered,
        tt.total_failed,
        tt.remarks,
        tt.created_at,
        tt.updated_at,
        tt.created_by,
        tt.picklist_id,
        -- Get driver info from drivers table
        d.driver_name,
        d.license_number,
        d.contact_number,
        d.vehicle_type,
        d.vehicle_plate_number,
        b.branch_name,
        pl.pick_list_number,
        pl.so_id,
        so.so_number,
        c.customer_name,
        c.address,
        c.city
    FROM trip_tickets tt
    LEFT JOIN drivers d ON tt.driver_id = d.driver_id
    LEFT JOIN branches b ON tt.branch_id = b.branch_id
    LEFT JOIN pick_lists pl ON tt.picklist_id = pl.pick_list_id
    LEFT JOIN sales_orders so ON pl.so_id = so.so_id
    LEFT JOIN customers c ON so.customer_id = c.customer_id
    WHERE 1=1
";

// Add branch filter
if (!$view_all_branches && $branch_id > 0) {
    $trip_tickets_query .= " AND tt.branch_id = $branch_id";
}

// Add driver filter for delivery role
if ($user_role == 'delivery' && $driver_id > 0) {
    $trip_tickets_query .= " AND tt.driver_id = $driver_id";
}

$trip_tickets_query .= " ORDER BY 
    CASE 
        WHEN tt.trip_status IN ('planned', 'in-progress') THEN 1
        WHEN tt.trip_status = 'completed' THEN 2
        ELSE 3
    END,
    tt.created_at DESC";

$result = $conn->query($trip_tickets_query);

if (!$result) {
    error_log("SQL Error in trip_tickets.php: " . $conn->error);
    $trip_tickets = [];
} else {
    $trip_tickets = $result->fetch_all(MYSQLI_ASSOC);
}

// ================= GET DRIVER INFO FOR DISPLAY =================
$driver_info = null;
if ($user_role == 'delivery' && $driver_id > 0) {
    $driver_info_query = "SELECT * FROM drivers WHERE driver_id = ?";
    $driver_info_stmt = $conn->prepare($driver_info_query);
    $driver_info_stmt->bind_param("i", $driver_id);
    $driver_info_stmt->execute();
    $driver_info_result = $driver_info_stmt->get_result();
    $driver_info = $driver_info_result->fetch_assoc();
    $driver_info_stmt->close();
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
    $user_initials = 'DV';
}

// Helper function for trip status badge
function getTripStatusBadge($status) {
    return match($status) {
        'planned' => 'bg-warning',
        'pending' => 'bg-warning',
        'in-progress' => 'bg-primary',
        'completed' => 'bg-success',
        'cancelled' => 'bg-danger',
        'delayed' => 'bg-info',
        default => 'bg-secondary'
    };
}

function getTripStatusText($status) {
    return match($status) {
        'planned' => 'Pending',
        'pending' => 'Pending',
        'in-progress' => 'In Transit',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
        'delayed' => 'Delayed',
        default => ucfirst(str_replace('-', ' ', $status))
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Trip Tickets - Delivery Management</title>
    <link rel="icon" type="image/png" href="../Pictures/favicon-96x96.png" sizes="96x96" />
    <link rel="icon" type="image/svg+xml" href="../Pictures/favicon.svg" />
    <link rel="shortcut icon" href="../Pictures/favicon.ico" />
    <link rel="apple-touch-icon" sizes="180x180" href="../Pictures/apple-touch-icon.png" />
    <link rel="manifest" href="../Pictures/site.webmanifest" />
    <link rel="stylesheet" href="../css/delivery.css">
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
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

        /* Status option styling */
        .status-option {
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 10px;
            margin-bottom: 10px;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .status-option:hover {
            background-color: #f8f9fa;
            border-color: #0d6efd;
        }
        
        .status-option.selected {
            background-color: #e7f1ff;
            border-color: #0d6efd;
        }
        
        .status-badge {
            min-width: 80px;
            text-align: center;
        }

        .auto-update-badge {
            background-color: #ffc107;
            color: #212529;
            font-size: 0.7rem;
            padding: 2px 6px;
            border-radius: 4px;
            font-weight: 600;
            display: inline-block;
            margin-top: 4px;
        }

        .delivery-count {
            font-size: 0.85rem;
            padding: 4px 8px;
        }

        .stat-card-row {
            margin-bottom: 1.5rem !important;
        }

        @media (max-width: 768px) {
            .stat-card-row {
                margin-bottom: 1rem !important;
            }
        }

        /* ===== CLICKABLE ROW STYLES (both desktop & mobile) ===== */
        .trip-row {
            cursor: pointer;
            transition: background-color 0.2s ease;
        }
        
        .trip-row:hover {
            background-color: rgba(4, 120, 87, 0.05);
        }
        
        .trip-row:active {
            background-color: rgba(4, 120, 87, 0.1);
        }

        /* ===== MOBILE LAYOUT - TRIP NUMBER | SO NUMBER, PICK LIST | STATUS, CUSTOMER ===== */
        @media (max-width: 768px) {
            /* Hide table headers */
            .custom-table thead {
                display: none !important;
            }
            
            /* Make table behave like blocks */
            .custom-table,
            .custom-table tbody,
            .custom-table tr,
            .custom-table td {
                display: block !important;
                width: 100% !important;
            }
            
            /* Style each row as a card */
            .custom-table tbody tr {
                background: white !important;
                border-radius: 12px !important;
                margin-bottom: 12px !important;
                padding: 12px !important;
                box-shadow: 0 2px 8px rgba(0,0,0,0.08) !important;
                border: 1px solid #e9ecef !important;
            }
            
            /* Hide all original desktop columns */
            .custom-table td.col-trip-number,
            .custom-table td.col-driver,
            .custom-table td.col-branch,
            .custom-table td.col-date,
            .custom-table td.col-status,
            .custom-table td.col-customer {
                display: none !important;
            }
            
            /* Show mobile card */
            .custom-table td.mobile-trip-card {
                display: block !important;
                padding: 0 !important;
                border: none !important;
            }
            
            /* ===== ROW 1: Trip Number (left) and SO Number (right) ===== */
            .trip-first-row {
                display: flex !important;
                justify-content: space-between !important;
                align-items: center !important;
                margin-bottom: 4px !important;
            }
            
            .trip-first-row .trip-number {
                font-size: clamp(0.9rem, 4vw, 1.2rem) !important;
                font-weight: 700 !important;
                color: #047857 !important;
                white-space: nowrap !important;
                overflow: hidden !important;
                text-overflow: ellipsis !important;
                max-width: 60% !important;
            }
            
            .trip-first-row .so-number {
                font-size: clamp(0.75rem, 3.5vw, 0.95rem) !important;
                color: #6c757d !important;
                white-space: nowrap !important;
                overflow: hidden !important;
                text-overflow: ellipsis !important;
                max-width: 40% !important;
                text-align: right !important;
            }
            
            /* ===== ROW 2: Pick List (left) and Status Badge (right) ===== */
            .trip-second-row {
                display: flex !important;
                justify-content: space-between !important;
                align-items: center !important;
                margin-bottom: 4px !important;
            }
            
            .trip-second-row .pick-list {
                font-size: clamp(0.75rem, 3.5vw, 0.95rem) !important;
                color: #6c757d !important;
                white-space: nowrap !important;
                overflow: hidden !important;
                text-overflow: ellipsis !important;
                max-width: 60% !important;
            }
            
            .trip-second-row .status-badge {
                font-size: clamp(0.7rem, 3vw, 0.9rem) !important;
                padding: 3px 10px !important;
                border-radius: 20px !important;
                font-weight: 600 !important;
                color: white !important;
                white-space: nowrap !important;
                max-width: 40% !important;
                text-align: center !important;
            }
            
            /* Status badge colors */
            .trip-second-row .status-badge.bg-warning {
                background-color: #f59e0b !important;
            }
            
            .trip-second-row .status-badge.bg-success {
                background-color: #28a745 !important;
            }
            
            .trip-second-row .status-badge.bg-primary {
                background-color: #0d6efd !important;
            }
            
            .trip-second-row .status-badge.bg-info {
                background-color: #0dcaf0 !important;
            }
            
            .trip-second-row .status-badge.bg-danger {
                background-color: #dc3545 !important;
            }
            
            .trip-second-row .status-badge.bg-secondary {
                background-color: #6c757d !important;
            }
            
            /* ===== ROW 3: Customer Name (left) and Driver (right) ===== */
            .trip-third-row {
                display: flex !important;
                justify-content: space-between !important;
                align-items: center !important;
                gap: 8px !important;
            }
            
            .trip-third-row .customer-name {
                font-size: clamp(0.95rem, 4.5vw, 1.2rem) !important;
                font-weight: 500 !important;
                color: #212529 !important;
                white-space: nowrap !important;
                overflow: hidden !important;
                text-overflow: ellipsis !important;
                flex: 1 !important;
                min-width: 0 !important;
            }
            
            .trip-third-row .driver-name {
                font-size: clamp(0.7rem, 3.2vw, 0.85rem) !important;
                color: #6c757d !important;
                white-space: nowrap !important;
                flex-shrink: 0 !important;
            }
        }

        /* Small phones (400px - 568px) */
        @media (min-width: 400px) and (max-width: 568px) {
            .custom-table tbody tr {
                padding: 10px !important;
            }
            
            .trip-first-row {
                margin-bottom: 3px !important;
            }
            
            .trip-second-row {
                margin-bottom: 3px !important;
            }
        }

        /* Small phones (below 400px) */
        @media (max-width: 399px) {
            .custom-table tbody tr {
                padding: 8px !important;
            }
            
            .trip-first-row {
                margin-bottom: 2px !important;
            }
            
            .trip-second-row {
                margin-bottom: 2px !important;
            }
            
            .trip-first-row .trip-number {
                font-size: 0.9rem !important;
            }
            
            .trip-first-row .so-number {
                font-size: 0.7rem !important;
            }
            
            .trip-second-row .pick-list {
                font-size: 0.7rem !important;
            }
            
            .trip-second-row .status-badge {
                font-size: 0.65rem !important;
                padding: 2px 8px !important;
            }
            
            .trip-third-row .customer-name {
                font-size: 0.9rem !important;
            }
            
            .trip-third-row .driver-name {
                font-size: 0.65rem !important;
            }
        }

        /* Extra small phones */
        @media (max-width: 320px) {
            .custom-table tbody tr {
                padding: 6px !important;
            }
            
            .trip-first-row .trip-number {
                font-size: 0.8rem !important;
            }
            
            .trip-first-row .so-number {
                font-size: 0.65rem !important;
            }
            
            .trip-second-row .pick-list {
                font-size: 0.65rem !important;
            }
            
            .trip-second-row .status-badge {
                font-size: 0.6rem !important;
                padding: 2px 6px !important;
            }
            
            .trip-third-row .customer-name {
                font-size: 0.8rem !important;
            }
            
            .trip-third-row .driver-name {
                font-size: 0.6rem !important;
            }
        }

        /* Desktop - table layout */
        @media (min-width: 769px) {
            .custom-table thead {
                display: table-header-group !important;
            }
            
            .custom-table tbody tr {
                display: table-row !important;
                background: none !important;
                border-radius: 0 !important;
                margin-bottom: 0 !important;
                padding: 0 !important;
                box-shadow: none !important;
                border: none !important;
            }
            
            .custom-table td {
                display: table-cell !important;
                padding: 0.75rem 1rem !important;
                vertical-align: middle !important;
            }
            
            .custom-table td.mobile-trip-card {
                display: none !important;
            }
            
            /* Status badge in desktop table */
            .col-status .badge {
                font-size: 0.75rem;
                padding: 0.35rem 0.65rem;
            }
        }

        /* Modal height adjustments for mobile */
        @media (max-width: 768px) {
            .modal-dialog-scrollable {
                margin: 0.5rem;
                height: calc(100% - 1rem);
                max-height: none;
            }
            
            .modal-dialog-scrollable .modal-content {
                max-height: 95vh;
                border-radius: 16px;
            }
            
            .modal-dialog-scrollable .modal-body {
                max-height: calc(95vh - 120px);
                padding: 1rem;
            }
        }

        @media (max-width: 480px) {
            .modal-dialog-scrollable {
                margin: 0.3rem;
                height: calc(100% - 0.6rem);
            }
            
            .modal-dialog-scrollable .modal-content {
                max-height: 96vh;
                border-radius: 14px;
            }
            
            .modal-dialog-scrollable .modal-body {
                max-height: calc(96vh - 110px);
                padding: 0.9rem;
            }
        }

        @media (max-width: 360px) {
            .modal-dialog-scrollable {
                margin: 0.2rem;
                height: calc(100% - 0.4rem);
            }
            
            .modal-dialog-scrollable .modal-content {
                max-height: 97vh;
                border-radius: 12px;
            }
            
            .modal-dialog-scrollable .modal-body {
                max-height: calc(97vh - 100px);
                padding: 0.8rem;
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
                    <span class="nav-text">Delivery</span>
                </h3>
            </div>
            
             <div class="sidebar-menu">
                <ul class="nav flex-column">
                     <li class="nav-item">
                        <a class="nav-link" href="fordelivery.php">
                            <i class="bi bi-truck"></i>
                            <span class="nav-text">For Delivery</span>
                        </a>
                    </li>

                    <li class="nav-item">
                        <a class="nav-link active" href="trip_tickets.php">
                            <i class="bi bi-ticket"></i>
                            <span class="nav-text">Trip Tickets</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="driver_collections.php">
                            <i class="bi bi-cash-stack"></i>
                            <span class="nav-text">Collections</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="vehicle.php">
                            <i class="bi bi-car-front"></i>
                            <span class="nav-text">Vehicle</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="rejecteddelivery.php">
                            <i class="bi bi-exclamation-circle"></i>
                            <span class="nav-text">Rejected Delivery</span>
                        </a>
                    </li>
                </ul>
            </div>
            <hr class="sidebar-divider">
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

        <!-- Main Content Area -->
        <div class="main-content">
            <!-- Header Section -->
            <div class="navbar-top">
                <button class="mobile-toggle-btn" id="mobileToggleBtn">
                    <i class="bi bi-list"></i>
                </button>
                <div class="page-title">
                    <h2>Trip Tickets</h2>
                    <p>Track and manage delivery trip tickets</p>
                </div>
                <?php if ($user_role != 'delivery'): ?>
                <div class="ms-auto me-3">
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addTicketModal">
                        <i class="bi bi-plus-circle me-1"></i> New Trip Ticket
                    </button>
                </div>
                <?php endif; ?>
            </div>

            <!-- Branch Info Alerts -->
            <?php if (!$tt_branch_column_exists): ?>
                <div class="alert alert-info alert-dismissible fade show" role="alert">
                    <i class="bi bi-info-circle"></i> 
                    <strong>Branch filtering for trip tickets not yet set up.</strong> Please run this SQL in phpMyAdmin to enable branch-specific trip ticket data:
                    <br><br>
                    <code>ALTER TABLE trip_tickets ADD COLUMN branch_id INT NULL;</code>
                    <br>
                    <code>ALTER TABLE trip_tickets ADD FOREIGN KEY (branch_id) REFERENCES branches(branch_id);</code>
                    <br><br>
                    <button type="button" class="btn btn-sm btn-primary" onclick="copySQL('trip_tickets')">
                        <i class="bi bi-files"></i> Copy SQL
                    </button>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if (!$drivers_branch_column_exists): ?>
                <div class="alert alert-info alert-dismissible fade show" role="alert">
                    <i class="bi bi-info-circle"></i> 
                    <strong>Branch filtering for drivers not yet set up.</strong> Please run this SQL in phpMyAdmin to enable branch-specific driver data:
                    <br><br>
                    <code>ALTER TABLE drivers ADD COLUMN branch_id INT NULL;</code>
                    <br>
                    <code>ALTER TABLE drivers ADD FOREIGN KEY (branch_id) REFERENCES branches(branch_id);</code>
                    <br><br>
                    <button type="button" class="btn btn-sm btn-primary" onclick="copySQL('drivers')">
                        <i class="bi bi-files"></i> Copy SQL
                    </button>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Success/Error Messages -->
            <?php if (isset($success_message)): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?php echo $success_message; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <?php if (isset($error_message)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?php echo $error_message; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <!-- Trip Tickets Stats -->
            <div class="row stat-card-row g-1 g-sm-2">
                <!-- Card 1 - Total Trips -->
                <div class="col">
                    <div class="stat-card inventory">
                        <i class="bi bi-ticket"></i>
                        <div class="stat-content">
                            <div class="stat-value"><?php echo $stats['total_trips'] ?? 0; ?></div>
                            <div class="stat-label">Total Trips</div>
                            <?php if ($user_role == 'delivery'): ?>
                            <?php elseif ($tt_branch_column_exists && !$view_all_branches): ?>
                                <small class="text-white-50 d-block mt-1">Your Branch</small>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Card 2 - Completed -->
                <div class="col">
                    <div class="stat-card sales">
                        <i class="bi bi-check-circle"></i>
                        <div class="stat-content">
                            <div class="stat-value"><?php echo $stats['completed'] ?? 0; ?></div>
                            <div class="stat-label">Completed</div>
                        </div>
                    </div>
                </div>

                <!-- Card 3 - In Transit -->
                <div class="col">
                    <div class="stat-card pending">
                        <i class="bi bi-arrow-right-circle"></i>
                        <div class="stat-content">
                            <div class="stat-value"><?php echo $stats['in_transit'] ?? 0; ?></div>
                            <div class="stat-label">In Transit</div>
                        </div>
                    </div>
                </div>

                <!-- Card 4 - Pending -->
                <div class="col">
                    <div class="stat-card delivery">
                        <i class="bi bi-exclamation-circle"></i>
                        <div class="stat-content">
                            <div class="stat-value"><?php echo $stats['pending'] ?? 0; ?></div>
                            <div class="stat-label">Pending</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- No Trip Tickets Message -->
            <?php if (empty($trip_tickets)): ?>
                <div class="alert alert-info text-center py-4">
                    <i class="bi bi-ticket" style="font-size: 2rem;"></i>
                    <p class="mt-3 mb-0">
                        No trip tickets found.
                        <?php if ($user_role == 'delivery'): ?>
                            <br><small>You don't have any trip tickets assigned yet.</small>
                        <?php elseif ($tt_branch_column_exists && !$view_all_branches): ?>
                            <br><small>No trip tickets for your branch yet.</small>
                        <?php endif; ?>
                    </p>
                </div>
            <?php else: ?>

            <!-- Trip Tickets Table - Click anywhere on row to view details -->
            <div class="card">
                <div class="table-container">
                    <table class="table custom-table compact-table">
                        <thead class="table-light">
                            <tr>
                                <th class="col-trip-number">TRIP NUMBER</th>
                                <th class="col-driver">DRIVER</th>
                                <th class="col-branch">BRANCH</th>
                                <th class="col-date">TRIP DATE</th>
                                <th class="col-status">STATUS</th>
                                <th class="col-customer">CUSTOMER / SO #</th>
                            </tr>
                        </thead>
                        <tbody id="tripTableBody">
                            <?php foreach ($trip_tickets as $row): 
                                $customer_display = !empty($row['customer_name']) ? $row['customer_name'] : 'N/A';
                                $so_display = !empty($row['so_number']) ? $row['so_number'] : '';
                                $picklist_display = !empty($row['pick_list_number']) ? $row['pick_list_number'] : '';
                            ?>
                            <tr class="trip-row" 
                                data-trip-id="<?php echo $row['trip_id']; ?>"
                                data-status="<?php echo $row['trip_status']; ?>"
                                data-driver-id="<?php echo $row['driver_id']; ?>"
                                onclick="viewTripDetails(<?php echo $row['trip_id']; ?>)">
                                
                                <!-- DESKTOP VIEW - visible on desktop, hidden on mobile via CSS -->
                                <td class="col-trip-number"><?php echo htmlspecialchars($row['trip_number']); ?></td>
                                <td class="col-driver"><?php echo htmlspecialchars($row['driver_name'] ?? 'N/A'); ?></td>
                                <td class="col-branch"><?php echo htmlspecialchars($row['branch_name'] ?? 'N/A'); ?></td>
                                <td class="col-date"><?php echo date('Y-m-d', strtotime($row['trip_date'])); ?></td>
                                <td class="col-status">
                                    <span class="badge <?php echo getTripStatusBadge($row['trip_status']); ?> text-white">
                                        <?php echo getTripStatusText($row['trip_status']); ?>
                                    </span>
                                </td>
                                <td class="col-customer">
                                    <span class="fw-semibold"><?php echo htmlspecialchars($customer_display); ?></span>
                                    <?php if (!empty($so_display)): ?>
                                        <br><small class="text-muted"><?php echo htmlspecialchars($so_display); ?></small>
                                    <?php endif; ?>
                                </td>
                                
                                <!-- MOBILE VIEW - visible on mobile, hidden on desktop via CSS -->
                                <td class="mobile-trip-card" colspan="6">
                                    <!-- ROW 1: Trip Number | SO Number -->
                                    <div class="trip-first-row">
                                        <span class="trip-number"><?php echo htmlspecialchars($row['trip_number']); ?></span>
                                        <?php if (!empty($so_display)): ?>
                                            <span class="so-number"><?php echo htmlspecialchars($so_display); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <!-- ROW 2: Pick List | Status Badge -->
                                    <div class="trip-second-row">
                                        <?php if (!empty($picklist_display)): ?>
                                            <span class="pick-list">PL: <?php echo htmlspecialchars($picklist_display); ?></span>
                                        <?php endif; ?>
                                        <span class="status-badge <?php echo getTripStatusBadge($row['trip_status']); ?>">
                                            <?php echo getTripStatusText($row['trip_status']); ?>
                                        </span>
                                    </div>
                                    
                                    <!-- ROW 3: Customer Name | Driver Name -->
                                    <div class="trip-third-row">
                                        <span class="customer-name"><?php echo htmlspecialchars($customer_display); ?></span>
                                        <span class="driver-name">
                                            <i class="bi bi-person-badge me-1"></i><?php echo htmlspecialchars($row['driver_name'] ?? 'N/A'); ?>
                                        </span>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Mobile Bottom Navigation -->
    <div class="mobile-nav" id="mobileNav">
        <ul class="nav">
            <li class="nav-item">
                <a class="nav-link" href="fordelivery.php">
                    <i class="bi bi-truck"></i>
                    <span>Delivery</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link active" href="trip_tickets.php">
                    <i class="bi bi-ticket-perforated"></i>
                    <span>Tickets</span>
                </a>
            </li>
            <li class="nav-item">
                        <a class="nav-link" href="driver_collections.php">
                            <i class="bi bi-cash-stack"></i>
                            <span class="nav-text">Collections</span>
                        </a>
                    </li>
            <li class="nav-item">
                <a class="nav-link" href="rejecteddelivery.php">
                    <i class="bi bi-exclamation-circle"></i>
                    <span>Rejected</span>
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
                    <?php if (!$view_all_branches && $branch_id > 0): ?>
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

    <!-- Add Trip Ticket Modal (for non-delivery roles only) -->
    <?php if ($user_role != 'delivery'): ?>
    <div class="modal fade" id="addTicketModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Create New Trip Ticket</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="addTicketForm" method="POST">
                    <input type="hidden" name="action" value="add_trip">
                    <div class="modal-body">
                        <?php if ($tt_branch_column_exists && !$view_all_branches): ?>
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle"></i> Creating trip ticket for Branch <?php echo $branch_id; ?>
                                <input type="hidden" name="branch_id" value="<?php echo $branch_id; ?>">
                            </div>
                        <?php endif; ?>

                        <?php if (empty($drivers)): ?>
                            <div class="alert alert-warning">
                                <i class="bi bi-exclamation-triangle"></i> No drivers available for your branch.
                                <?php if ($view_all_branches): ?>
                                    Please add drivers first.
                                <?php else: ?>
                                    Please contact admin to assign drivers to your branch.
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Driver <span class="text-danger">*</span></label>
                                <select class="form-select" name="driver_id" required <?php echo empty($drivers) ? 'disabled' : ''; ?>>
                                    <option value="">Select Driver</option>
                                    <?php foreach ($drivers as $driver): ?>
                                        <option value="<?php echo $driver['driver_id']; ?>"><?php echo htmlspecialchars($driver['driver_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Branch <span class="text-danger">*</span></label>
                                <?php if ($tt_branch_column_exists && !$view_all_branches): ?>
                                    <input type="text" class="form-control" value="Branch <?php echo $branch_id; ?>" readonly>
                                    <input type="hidden" name="branch_id" value="<?php echo $branch_id; ?>">
                                <?php else: ?>
                                    <select class="form-select" name="branch_id" required>
                                        <option value="">Select Branch</option>
                                        <?php foreach ($branches as $branch): ?>
                                            <option value="<?php echo $branch['branch_id']; ?>"><?php echo htmlspecialchars($branch['branch_name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Trip Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" name="trip_date" required value="<?php echo date('Y-m-d'); ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Status <span class="text-danger">*</span></label>
                                <select class="form-select" name="trip_status" required>
                                    <option value="planned" selected>Planned</option>
                                    <option value="in-progress">In Progress</option>
                                    <option value="completed">Completed</option>
                                    <option value="delayed">Delayed</option>
                                    <option value="cancelled">Cancelled</option>
                                </select>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Total Stops</label>
                                <input type="number" class="form-control" name="total_stops" placeholder="Number of stops" min="0" value="0">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Created By</label>
                                <input type="text" class="form-control" value="<?php echo htmlspecialchars($user_name); ?>" readonly>
                                <small class="text-muted">Auto-filled from your session</small>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Remarks</label>
                            <textarea class="form-control" name="remarks" placeholder="Additional notes..." rows="3"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary" <?php echo empty($drivers) ? 'disabled' : ''; ?>>Create Ticket</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- View Details Modal -->
    <div class="modal fade" id="viewDetailsModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Trip Ticket Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="tripDetailsContent">
                    <!-- Content will be loaded by JavaScript -->
                </div>
            </div>
        </div>
    </div>

    <!-- Update Status Modal -->
    <div class="modal fade" id="statusModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title"><i class="bi bi-arrow-repeat me-2"></i>Update Trip Status</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="statusTripId">
                    
                    <div class="alert alert-info mb-3">
                        <strong>Trip #: <span id="statusTripNumber"></span></strong>
                    </div>
                    
                    <div class="alert alert-success mb-3" id="autoUpdateNotice" style="display: none;">
                        <i class="bi bi-info-circle-fill me-2"></i>
                        <strong>Note:</strong> This trip can be auto-completed because all deliveries are marked as delivered.
                    </div>
                    
                    <p class="mb-3">Select new status:</p>
                    
                    <div class="status-option" onclick="selectStatus('planned')" id="opt-planned">
                        <span class="badge bg-warning text-dark status-badge">Pending</span>
                        <span>Trip is planned but not yet started</span>
                    </div>
                    
                    <div class="status-option" onclick="selectStatus('in-progress')" id="opt-in-progress">
                        <span class="badge bg-primary text-white status-badge">In Transit</span>
                        <span>Delivery is in progress</span>
                    </div>
                    
                    <div class="status-option" onclick="selectStatus('completed')" id="opt-completed">
                        <span class="badge bg-success text-white status-badge">Completed</span>
                        <span>All deliveries completed</span>
                    </div>
                    
                    <div class="status-option" onclick="selectStatus('delayed')" id="opt-delayed">
                        <span class="badge bg-info text-white status-badge">Delayed</span>
                        <span>Delivery is delayed</span>
                    </div>
                    
                    <div class="status-option" onclick="selectStatus('cancelled')" id="opt-cancelled">
                        <span class="badge bg-danger text-white status-badge">Cancelled</span>
                        <span>Trip has been cancelled</span>
                    </div>
                    
                    <input type="hidden" id="selectedStatus" value="">
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Branch context variables
        const branchId = <?php echo $branch_id; ?>;
        const viewAllBranches = <?php echo $view_all_branches ? 'true' : 'false'; ?>;
        const ttBranchColumnExists = <?php echo $tt_branch_column_exists ? 'true' : 'false'; ?>;
        const driversBranchColumnExists = <?php echo $drivers_branch_column_exists ? 'true' : 'false'; ?>;
        const userRole = '<?php echo $user_role; ?>';
        const driverId = <?php echo $driver_id ?: 0; ?>;

        let currentTripId = null;
        let currentTripNumber = null;

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
                    
                    overlay.addEventListener('click', () => {
                        closeMobileSidebar();
                    });
                    
                    setTimeout(() => {
                        overlay.classList.add('active');
                    }, 10);
                } else {
                    const overlay = document.querySelector('.sidebar-overlay');
                    overlay.classList.toggle('active');
                    if (!sidebar.classList.contains('active')) {
                        setTimeout(() => {
                            if (overlay && overlay.parentNode) {
                                overlay.remove();
                            }
                        }, 300);
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
                setTimeout(() => {
                    if (overlay.parentNode) {
                        overlay.remove();
                    }
                }, 300);
            }
        }

        function initializeSidebar() {
            const sidebar = document.getElementById('sidebar');
            
            if (window.innerWidth > 992) {
                const savedCollapsed = localStorage.getItem('sidebarCollapsed');
                if (savedCollapsed === 'true') {
                    sidebar.classList.add('collapsed');
                    document.querySelectorAll('.nav-text').forEach(text => {
                        text.style.display = 'none';
                    });
                    
                    const mainContent = document.querySelector('.main-content');
                    if (mainContent) {
                        mainContent.style.marginLeft = '80px';
                    }
                } else {
                    sidebar.classList.remove('collapsed');
                    document.querySelectorAll('.nav-text').forEach(text => {
                        text.style.display = 'inline-block';
                    });
                    
                    const mainContent = document.querySelector('.main-content');
                    if (mainContent) {
                        mainContent.style.marginLeft = '250px';
                    }
                }
            } else {
                sidebar.classList.remove('active');
                sidebar.classList.remove('collapsed');
                document.querySelectorAll('.nav-text').forEach(text => {
                    text.style.display = 'inline-block';
                });
                
                const mainContent = document.querySelector('.main-content');
                if (mainContent) {
                    mainContent.style.marginLeft = '0';
                }
            }
        }

        function handleSidebarResize() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.querySelector('.sidebar-overlay');
            
            if (window.innerWidth > 992) {
                if (overlay) {
                    overlay.remove();
                }
                sidebar.classList.remove('active');
                
                const savedCollapsed = localStorage.getItem('sidebarCollapsed');
                if (savedCollapsed === 'true') {
                    sidebar.classList.add('collapsed');
                    document.querySelectorAll('.nav-text').forEach(text => {
                        text.style.display = 'none';
                    });
                    
                    const mainContent = document.querySelector('.main-content');
                    if (mainContent) {
                        mainContent.style.marginLeft = '80px';
                    }
                } else {
                    sidebar.classList.remove('collapsed');
                    document.querySelectorAll('.nav-text').forEach(text => {
                        text.style.display = 'inline-block';
                    });
                    
                    const mainContent = document.querySelector('.main-content');
                    if (mainContent) {
                        mainContent.style.marginLeft = '250px';
                    }
                }
            } else {
                sidebar.classList.remove('collapsed');
                document.querySelectorAll('.nav-text').forEach(text => {
                    text.style.display = 'inline-block';
                });
                
                const mainContent = document.querySelector('.main-content');
                if (mainContent) {
                    mainContent.style.marginLeft = '0';
                }
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

        // Logout function
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

        // View trip details
        function viewTripDetails(tripId) {
            fetch('get_trip_details.php?trip_id=' + tripId + '&branch_id=' + branchId + '&view_all=' + viewAllBranches)
                .then(response => response.text())
                .then(data => {
                    document.getElementById('tripDetailsContent').innerHTML = data;
                    const modal = new bootstrap.Modal(document.getElementById('viewDetailsModal'));
                    modal.show();
                })
                .catch(error => {
                    console.error('Error:', error);
                    Swal.fire('Error', 'Failed to load trip details', 'error');
                });
        }

        // Show status update modal
        function showStatusModal(tripId, currentStatus) {
            currentTripId = tripId;
            
            // Get trip number from the row
            const row = document.querySelector(`.trip-row[data-trip-id="${tripId}"]`);
            if (row) {
                const tripNumber = row.querySelector('.trip-number')?.textContent || row.querySelector('.fw-semibold')?.textContent;
                document.getElementById('statusTripNumber').textContent = tripNumber;
            }
            
            // Check if this trip can be auto-completed
            const deliveredCount = parseInt(row.querySelector('.text-success')?.textContent || '0');
            const deliveryCount = parseInt(row.querySelector('.badge.bg-primary')?.textContent.replace(/[^0-9]/g, '') || '0');
            
            const autoUpdateNotice = document.getElementById('autoUpdateNotice');
            if (deliveryCount > 0 && deliveredCount == deliveryCount && currentStatus != 'completed') {
                autoUpdateNotice.style.display = 'block';
            } else {
                autoUpdateNotice.style.display = 'none';
            }
            
            // Remove selected class from all options
            document.querySelectorAll('.status-option').forEach(opt => {
                opt.classList.remove('selected');
            });
            
            // Add selected class to current status option
            const currentOpt = document.getElementById(`opt-${currentStatus}`);
            if (currentOpt) {
                currentOpt.classList.add('selected');
                document.getElementById('selectedStatus').value = currentStatus;
            }
            
            const modal = new bootstrap.Modal(document.getElementById('statusModal'));
            modal.show();
        }

        // Select status option
        function selectStatus(status) {
            document.querySelectorAll('.status-option').forEach(opt => {
                opt.classList.remove('selected');
            });
            document.getElementById(`opt-${status}`).classList.add('selected');
            document.getElementById('selectedStatus').value = status;
        }

        // Update trip status
        function updateTripStatus() {
            const newStatus = document.getElementById('selectedStatus').value;
            
            if (!newStatus) {
                Swal.fire('Warning', 'Please select a status', 'warning');
                return;
            }
            
            Swal.fire({
                title: 'Are you sure?',
                text: 'Do you want to update this trip status?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#ffc107',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, update'
            }).then((result) => {
                if (result.isConfirmed) {
                    const formData = new FormData();
                    formData.append('action', 'update_trip_status');
                    formData.append('trip_id', currentTripId);
                    formData.append('status', newStatus);
                    
                    Swal.fire({
                        title: 'Updating...',
                        text: 'Please wait',
                        allowOutsideClick: false,
                        didOpen: () => {
                            Swal.showLoading();
                        }
                    });
                    
                    fetch(window.location.href, {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        Swal.close();
                        if (data.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Success!',
                                text: data.message,
                                timer: 1500,
                                showConfirmButton: false
                            }).then(() => {
                                location.reload();
                            });
                        } else {
                            Swal.fire('Error', data.message || 'Failed to update status', 'error');
                        }
                    })
                    .catch(error => {
                        Swal.close();
                        console.error('Error:', error);
                        Swal.fire('Error', 'Failed to update status', 'error');
                    });
                }
            });
        }

        // Check if trip needs auto-update
        function checkTripAutoUpdate(tripId) {
            fetch('?check_trip_status=1&trip_id=' + tripId)
                .then(response => response.json())
                .then(data => {
                    if (data.updated) {
                        // Reload to show updated status
                        location.reload();
                    }
                })
                .catch(error => console.error('Error checking trip status:', error));
        }

        // Auto-check trips that might need update when page loads
        document.addEventListener('DOMContentLoaded', function() {
            // Check all trips that have all deliveries delivered but not completed
            document.querySelectorAll('.trip-row .auto-update-badge').forEach(badge => {
                const row = badge.closest('.trip-row');
                if (row) {
                    const tripId = row.dataset.tripId;
                    if (tripId) {
                        checkTripAutoUpdate(tripId);
                    }
                }
            });
        });

        // Copy SQL for database setup
        function copySQL(table) {
            let sql = '';
            if (table === 'trip_tickets') {
                sql = "ALTER TABLE trip_tickets ADD COLUMN branch_id INT NULL;\nALTER TABLE trip_tickets ADD FOREIGN KEY (branch_id) REFERENCES branches(branch_id);";
            } else if (table === 'drivers') {
                sql = "ALTER TABLE drivers ADD COLUMN branch_id INT NULL;\nALTER TABLE drivers ADD FOREIGN KEY (branch_id) REFERENCES branches(branch_id);";
            }
            
            navigator.clipboard.writeText(sql).then(() => {
                Swal.fire('Success', 'SQL copied to clipboard!', 'success');
            });
        }

        // ================= INITIALIZATION =================
        document.addEventListener('DOMContentLoaded', function() {
            console.log("Trip Tickets page loaded");
            
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
                const mobileBtn = document.getElementById('mobileToggleBtn');
                const overlay = document.querySelector('.sidebar-overlay');
                const isMobile = window.innerWidth <= 992;
                
                if (isMobile && sidebar && sidebar.classList.contains('active') && 
                    !sidebar.contains(event.target) && 
                    (!mobileBtn || !mobileBtn.contains(event.target)) &&
                    (!overlay || !overlay.contains(event.target))) {
                    closeMobileSidebar();
                }
            });

            window.addEventListener('resize', function() {
                handleSidebarResize();
                initMobileNav();
            });
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
                const viewModal = document.getElementById('viewDetailsModal');
                if (viewModal.classList.contains('show')) {
                    bootstrap.Modal.getInstance(viewModal).hide();
                }
                const statusModal = document.getElementById('statusModal');
                if (statusModal.classList.contains('show')) {
                    bootstrap.Modal.getInstance(statusModal).hide();
                }
            }
        });
    </script>
</body>
</html>
<?php $conn->close(); ?>