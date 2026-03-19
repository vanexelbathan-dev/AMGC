<?php
// Start session and include database connection
require_once '../config/database.php';
require_once '../config/session_handler.php';

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: ../login.php");
    exit();
}

// Get current user info and branch context
$user_id = $_SESSION['user_id'];
$user_name = isset($_SESSION['first_name']) ? $_SESSION['first_name'] . ' ' . $_SESSION['last_name'] : 'Driver User';
$user_role = isset($_SESSION['role']) ? $_SESSION['role'] : 'delivery';
$branch_id = $_SESSION['branch_id'] ?? 0;
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

// AUTO-FIX: Kung ang user ay delivery role at walang branch_id, i-set sa 1 (Main Branch)
if ($user_role == 'delivery' && $branch_id == 0) {
    $branch_id = 1;
    $_SESSION['branch_id'] = 1;
}

// Check if driver_id exists in session or get from users table
$driver_id = $_SESSION['driver_id'] ?? 0;
if ($driver_id == 0 && $user_role == 'delivery') {
    // Try to get driver_id from users table
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

// Check if branch_id column exists in deliveries table
$delivery_branch_column_exists = false;
$check_delivery_column = $conn->query("SHOW COLUMNS FROM deliveries LIKE 'branch_id'");
if ($check_delivery_column && $check_delivery_column->num_rows > 0) {
    $delivery_branch_column_exists = true;
}

// Check if driver_id column exists in deliveries table
$delivery_driver_column_exists = false;
$check_delivery_driver_column = $conn->query("SHOW COLUMNS FROM deliveries LIKE 'driver_id'");
if ($check_delivery_driver_column && $check_delivery_driver_column->num_rows > 0) {
    $delivery_driver_column_exists = true;
}

// Determine filter conditions
$delivery_branch_condition = "";
$delivery_driver_condition = "";

if ($delivery_branch_column_exists && !$view_all_branches && $branch_id > 0) {
    $delivery_branch_condition = "AND d.branch_id = $branch_id";
}

// For delivery role, filter by driver_id
if ($user_role == 'delivery' && $driver_id > 0 && $delivery_driver_column_exists) {
    $delivery_driver_condition = "AND d.driver_id = $driver_id";
} elseif ($user_role == 'delivery' && !$delivery_driver_column_exists) {
    // If driver_id column doesn't exist, show a warning but still show orders
    $driver_column_warning = true;
}

// AUTO-CREATE DELIVERIES FROM WAREHOUSE READY ORDERS
try {
    // For delivery role, filter by driver
    if ($user_role == 'delivery' && $driver_id > 0) {
        // Get trip tickets assigned to this driver
        $trip_ids_query = "SELECT trip_id FROM trip_tickets WHERE driver_id = ?";
        $trip_stmt = $conn->prepare($trip_ids_query);
        $trip_stmt->bind_param("i", $driver_id);
        $trip_stmt->execute();
        $trip_result = $trip_stmt->get_result();
        
        $trip_ids = [];
        while ($trip_row = $trip_result->fetch_assoc()) {
            $trip_ids[] = $trip_row['trip_id'];
        }
        $trip_stmt->close();
        
        // Create deliveries for trips without deliveries yet
        if (!empty($trip_ids)) {
            $trip_ids_str = implode(',', $trip_ids);
            
            $create_deliveries_query = "
                INSERT INTO deliveries (trip_id, so_id, customer_id, stop_sequence, delivery_status, branch_id, driver_id, created_at)
                SELECT DISTINCT
                    tt.trip_id,
                    tt.so_id,
                    so.customer_id,
                    1 as stop_sequence,
                    'pending' as delivery_status,
                    tt.branch_id,
                    tt.driver_id,
                    NOW()
                FROM trip_tickets tt
                INNER JOIN sales_orders so ON tt.so_id = so.so_id
                LEFT JOIN deliveries d ON tt.trip_id = d.trip_id AND tt.so_id = d.so_id
                WHERE tt.trip_id IN ($trip_ids_str)
                AND tt.trip_status IN ('planned', 'pending', 'in-progress')
                AND d.delivery_id IS NULL
            ";
            $conn->query($create_deliveries_query);
        }
    }
} catch (Exception $e) {
    error_log("Error auto-creating deliveries: " . $e->getMessage());
}

// Build the WHERE clause for the main query
$where_clause = "WHERE d.delivery_status IN ('pending', 'in-transit', 'partial', 'delivered')";
$where_clause .= $delivery_branch_condition;

if ($user_role == 'delivery' && $driver_id > 0 && $delivery_driver_column_exists) {
    $where_clause .= " AND d.driver_id = $driver_id";
} elseif ($user_role == 'delivery' && $delivery_driver_column_exists) {
    // If driver_id exists but no driver_id assigned, show nothing
    $where_clause .= " AND 1=0"; // No results
}

// Get delivery statistics including delivered
try {
    $stats_query = "
        SELECT 
            SUM(CASE WHEN delivery_status = 'pending' THEN 1 ELSE 0 END) as pending_count,
            SUM(CASE WHEN delivery_status IN ('in-transit', 'partial') THEN 1 ELSE 0 END) as active_count,
            SUM(CASE WHEN delivery_status = 'delivered' AND DATE(delivery_date) = CURDATE() THEN 1 ELSE 0 END) as completed_today,
            SUM(CASE WHEN delivery_status = 'delivered' THEN 1 ELSE 0 END) as total_completed
        FROM deliveries d
        $where_clause
    ";
    $stats_result = $conn->query($stats_query);
    $stats = $stats_result->fetch_assoc();
    
    // Get delivery orders data from deliveries table
    $query = "
        SELECT 
            d.delivery_id,
            d.so_id,
            d.trip_id,
            d.stop_sequence,
            d.delivery_date,
            d.delivery_status,
            d.signed_by,
            d.remarks,
            d.branch_id,
            d.driver_id,
            so.so_number,
            so.total_amount,
            so.created_at,
            c.customer_id,
            c.customer_name,
            c.contact_person,
            c.phone_number,
            c.address,
            c.city,
            c.longitude,
            c.latitude,
            dr.driver_name,
            dr.vehicle_plate_number,
            GROUP_CONCAT(CONCAT(IFNULL(i.item_name, 'Unknown'), ' (', soi.quantity_ordered, ')') SEPARATOR '; ') as items,
            GROUP_CONCAT(CONCAT(soi.quantity_ordered, ' x ', IFNULL(i.item_name, 'Unknown'), ' - ₱', soi.unit_price) SEPARATOR '||') as items_receipt
        FROM deliveries d
        INNER JOIN sales_orders so ON d.so_id = so.so_id
        INNER JOIN customers c ON d.customer_id = c.customer_id
        LEFT JOIN drivers dr ON d.driver_id = dr.driver_id
        LEFT JOIN sales_order_items soi ON so.so_id = soi.so_id
        LEFT JOIN items i ON soi.item_id = i.item_id
        $where_clause
        GROUP BY d.delivery_id
        ORDER BY 
            CASE 
                WHEN d.delivery_status = 'pending' THEN 1
                WHEN d.delivery_status = 'in-transit' THEN 2
                WHEN d.delivery_status = 'partial' THEN 3
                WHEN d.delivery_status = 'delivered' THEN 4
                ELSE 5
            END,
            d.delivery_date DESC
    ";
    
    $result = $conn->query($query);
    $delivery_orders = [];
    
    if ($result) {
        $delivery_orders = $result->fetch_all(MYSQLI_ASSOC);
    }
    
} catch (Exception $e) {
    error_log("Database error in fordelivery.php: " . $e->getMessage());
    $delivery_orders = [];
    $stats = ['pending_count' => 0, 'active_count' => 0, 'completed_today' => 0, 'total_completed' => 0];
    $driver_column_warning = true;
}

// Get driver info if applicable
$driver_info = null;
if ($user_role == 'delivery' && $driver_id > 0) {
    $driver_query = "SELECT * FROM drivers WHERE driver_id = ?";
    $driver_stmt = $conn->prepare($driver_query);
    $driver_stmt->bind_param("i", $driver_id);
    $driver_stmt->execute();
    $driver_result = $driver_stmt->get_result();
    $driver_info = $driver_result->fetch_assoc();
    $driver_stmt->close();
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>For Delivery - Delivery Management</title>
    <link rel="icon" type="image/png" href="../Pictures/favicon-96x96.png" sizes="96x96" />
    <link rel="icon" type="image/svg+xml" href="../Pictures/favicon.svg" />
    <link rel="shortcut icon" href="../Pictures/favicon.ico" />
    <link rel="apple-touch-icon" sizes="180x180" href="../Pictures/apple-touch-icon.png" />
    <link rel="manifest" href="../Pictures/site.webmanifest" />
    <link rel="stylesheet" href="../css/fordelivery.css">
    <link rel="stylesheet" href="../css/delivery.css">
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <!-- Leaflet CSS for maps -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <!-- Leaflet Routing Machine for directions -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet-routing-machine@3.2.12/dist/leaflet-routing-machine.css" />
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        .branch-badge {
            background-color: #e7f1ff;
            color: #0d6efd;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
            margin-left: 5px;
        }
        
        .driver-badge {
            background-color: #e7f1ff;
            color: #0d6efd;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-block;
        }
        
        .driver-info-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
        }
        
        .driver-info-card h5 {
            color: white;
            border-bottom: 1px solid rgba(255,255,255,0.3);
            padding-bottom: 10px;
        }
        
        .driver-info-card .info-label {
            color: rgba(255,255,255,0.8);
            font-size: 0.9rem;
        }
        
        .driver-info-card .info-value {
            color: white;
            font-weight: 600;
        }
        
        .alert-info {
            background-color: #d1ecf1;
            border-color: #bee5eb;
            color: #0c5460;
        }
        
        .alert-info code {
            background-color: #f8f9fa;
            padding: 2px 4px;
            border-radius: 4px;
            color: #c7254e;
        }
        
        .btn-group .btn {
            margin-right: 2px;
        }
        
        .modal-xl {
            max-width: 800px;
        }
        
        .map-icon-btn {
            background-color: #28a745;
            color: white;
            border: none;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 0.75rem;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 3px;
        }
        
        .map-icon-btn:hover {
            background-color: #218838;
            color: white;
        }
        
        .map-icon-btn i {
            font-size: 0.9rem;
        }
        
        .status-badge-delivered {
            background-color: #28a745;
            color: white;
        }
        
        .delivered-row {
            background-color: #f8f9fa;
        }
        
        /* Map Modal Styles */
        .location-map {
            height: 400px;
            width: 100%;
            border-radius: 8px;
            margin-bottom: 15px;
        }
        
        .location-info {
            background-color: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            margin-top: 15px;
        }
        
        .location-info p {
            margin-bottom: 8px;
        }
        
        .location-info i {
            color: #dc3545;
            margin-right: 8px;
        }
        
        .coordinates-badge {
            background-color: #e7f1ff;
            color: #0d6efd;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.85rem;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        
        .coordinates-badge i {
            font-size: 1rem;
        }
        
        /* Photo Modal Styles */
        .photo-modal-img {
            max-width: 100%;
            max-height: 70vh;
            display: block;
            margin: 0 auto;
        }
        
        /* Thermal Paper Receipt */
        .thermal-receipt {
            font-family: 'Courier New', monospace;
            width: 72mm;
            margin: 0 auto;
            padding: 3mm;
            background: white;
            color: black;
            font-size: 11px;
            line-height: 1.3;
            box-sizing: border-box;
            border: 1px solid #ddd;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .receipt-header {
            text-align: center;
            margin-bottom: 4px;
            padding-bottom: 2px;
            border-bottom: 1px dashed #333;
        }
        
        .receipt-header .company-name {
            font-size: 16px;
            font-weight: bold;
            letter-spacing: 1px;
        }
        
        .receipt-header .receipt-title {
            font-size: 12px;
            font-weight: bold;
        }
        
        .receipt-header .receipt-no {
            font-size: 10px;
        }
        
        .receipt-info {
            margin: 4px 0;
            padding: 4px;
            background: #f5f5f5;
            font-size: 10px;
        }
        
        .info-line {
            display: flex;
            margin: 2px 0;
        }
        
        .info-label {
            font-weight: bold;
            width: 70px;
            color: #333;
        }
        
        .info-value {
            flex: 1;
            text-align: left;
        }
        
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin: 4px 0;
            font-size: 10px;
        }
        
        .items-table th {
            text-align: left;
            border-bottom: 1px solid #333;
            padding: 2px 0;
        }
        
        .items-table td {
            padding: 2px 0;
            border-bottom: 1px dotted #999;
            vertical-align: top;
        }
        
        .items-table .item-name {
            max-width: 100px;
            word-wrap: break-word;
        }
        
        .items-table .text-right {
            text-align: right;
        }
        
        .items-table .text-center {
            text-align: center;
        }
        
        .receipt-total {
            margin-top: 4px;
            padding-top: 2px;
            border-top: 2px solid #333;
            text-align: right;
            font-weight: bold;
            font-size: 12px;
        }
        
        .receipt-footer {
            text-align: center;
            margin-top: 4px;
            padding-top: 2px;
            border-top: 1px dashed #333;
            font-size: 9px;
            color: #666;
        }
        
        /* Receipt Modal */
        #receiptModal .modal-dialog {
            max-width: 500px;
            margin: 20px auto;
        }
        
        #receiptModal .modal-content {
            border-radius: 10px;
            overflow: hidden;
        }
        
        #receiptModal .modal-header {
            background: #f8f9fa;
            border-bottom: 1px solid #dee2e6;
        }
        
        #receiptModal .modal-body {
            padding: 20px;
            background: #fff;
            min-height: 500px;
            max-height: 700px;
            overflow-y: auto;
            display: flex;
            justify-content: center;
        }
        
        #receiptModal .modal-footer {
            background: #f8f9fa;
            border-top: 1px solid #dee2e6;
        }
        
        /* Print styles */
        @media print {
            body * {
                visibility: hidden;
            }
            
            #thermalReceipt, #thermalReceipt * {
                visibility: visible;
            }
            
            #thermalReceipt {
                position: absolute;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                display: flex;
                justify-content: center;
                align-items: center;
                background: white;
                margin: 0;
                padding: 0;
            }
            
            .thermal-receipt {
                width: 72mm;
                height: auto;
                margin: 0 auto;
                padding: 2mm;
                background: white;
                font-family: 'Courier New', monospace;
                page-break-inside: avoid;
                page-break-after: avoid;
                box-sizing: border-box;
                border: none;
                box-shadow: none;
            }
            
            @page {
                margin: 0;
            }
        }
        
        /* GPS Tracking Styles */
        .tracking-indicator {
            display: inline-block;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            margin-right: 5px;
        }
        
        .tracking-active {
            background-color: #28a745;
            animation: pulse 1.5s infinite;
        }
        
        .tracking-inactive {
            background-color: #6c757d;
        }
        
        @keyframes pulse {
            0% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.5; transform: scale(1.1); }
            100% { opacity: 1; transform: scale(1); }
        }
        
        #locationIndicator {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 8px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            transition: all 0.3s ease;
        }
        
        #locationIndicator.bg-success {
            animation: pulse-green 2s infinite;
        }
        
        @keyframes pulse-green {
            0% { box-shadow: 0 0 0 0 rgba(40, 167, 69, 0.4); }
            70% { box-shadow: 0 0 0 10px rgba(40, 167, 69, 0); }
            100% { box-shadow: 0 0 0 0 rgba(40, 167, 69, 0); }
        }
        
        .update-counter {
            font-size: 0.75rem;
            margin-left: 5px;
            opacity: 0.8;
        }

        /* Tracking Modal Styles - Taller modal */
        #trackingModal .modal-dialog {
            max-width: 1200px;
            margin: 10px auto;
            height: 95vh;
        }
        
        #trackingModal .modal-content {
            border: none;
            border-radius: 12px;
            overflow: hidden;
            height: 100%;
            display: flex;
            flex-direction: column;
        }
        
        #trackingModal .modal-body {
            flex: 1;
            padding: 0;
            position: relative;
            overflow: hidden;
        }
        
        #trackingMap {
            height: 100%;
            width: 100%;
        }
        
        /* Status Panel - Collapsible & Draggable on desktop */
        .status-panel {
            position: absolute;
            top: 20px;
            right: 20px;
            width: 320px;
            background: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(5px);
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
            z-index: 1000;
            border: 1px solid rgba(0,0,0,0.1);
            transition: all 0.3s ease;
            max-height: calc(100% - 40px);
            overflow-y: auto;
        }
        
        .status-panel.collapsed {
            padding: 10px 20px;
            width: auto;
            min-width: 200px;
        }
        
        .status-panel.collapsed .panel-content {
            display: none;
        }
        
        .status-panel h6 {
            color: #333;
            font-weight: 600;
            margin-bottom: 0;
            padding-bottom: 0;
            border-bottom: none;
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: move;
        }
        
        .status-panel.collapsed h6 {
            margin-bottom: 0;
        }
        
        .status-panel h6 i:first-child {
            color: #0d6efd;
        }
        
        .toggle-panel-btn {
            background: transparent;
            border: none;
            color: #6c757d;
            font-size: 1.2rem;
            padding: 0 5px;
            margin-left: auto;
            cursor: pointer;
            transition: color 0.2s;
        }
        
        .toggle-panel-btn:hover {
            color: #0d6efd;
        }
        
        .panel-content {
            margin-top: 15px;
        }
        
        .info-row {
            margin-bottom: 12px;
        }
        
        .info-label {
            font-size: 0.8rem;
            color: #6c757d;
            margin-bottom: 2px;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .info-value {
            font-weight: 600;
            color: #333;
            font-size: 0.95rem;
        }
        
        .coordinates-text {
            font-family: monospace;
            font-size: 0.8rem;
            color: #6c757d;
            margin-top: 2px;
        }
        
        .progress {
            background-color: #e9ecef;
            border-radius: 10px;
            overflow: hidden;
            height: 8px;
        }
        
        .progress-bar {
            transition: width 0.3s ease;
        }
        
        .progress-bar.bg-success { background-color: #28a745 !important; }
        .progress-bar.bg-info { background-color: #17a2b8 !important; }
        .progress-bar.bg-warning { background-color: #ffc107 !important; }
        .progress-bar.bg-danger { background-color: #dc3545 !important; }
        
        .custom-user-icon {
            background-color: #007bff;
            border: 3px solid white;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.3);
            animation: pulse-blue 1.5s infinite;
        }
        
        .custom-destination-icon {
            background-color: #dc3545;
            border: 3px solid white;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.3);
        }
        
        @keyframes pulse-blue {
            0% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.3); opacity: 0.8; }
            100% { transform: scale(1); opacity: 1; }
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
        
        /* ===== RESPONSIVE MOBILE LAYOUT WITH DYNAMIC SIZING ===== */
        @media (max-width: 768px) {
            /* Hide table headers on mobile */
            .custom-table thead {
                display: none;
            }
            
            /* Make table behave like blocks */
            .custom-table,
            .custom-table tbody,
            .custom-table tr,
            .custom-table td {
                display: block;
                width: 100%;
            }
            
            /* Style each row as a card with minimal padding */
            .custom-table tbody tr {
                background: white;
                border-radius: 12px;
                margin-bottom: 10px;
                padding: 12px;
                box-shadow: 0 2px 6px rgba(0,0,0,0.06);
                border: 1px solid #e9ecef;
            }
            
            /* First row: Order ID - dynamic sizing */
            .custom-table td:first-child {
                font-size: clamp(0.85rem, 3.5vw, 1rem);
                font-weight: 600;
                color: #047857;
                margin-bottom: 4px;
                padding: 0 !important;
                border: none !important;
                line-height: 1.2;
            }
            
            .custom-table td:first-child .badge {
                font-size: inherit;
                padding: 0;
                background: transparent !important;
                color: #047857 !important;
                font-weight: 600;
            }
            
            /* Second row: Customer Name + Action Buttons - flexible layout */
            .custom-table td:nth-child(2) {
                display: flex !important;
                align-items: center;
                justify-content: space-between;
                gap: 8px;
                margin-bottom: 4px;
                padding: 0 !important;
                border: none !important;
                width: 100%;
            }
            
            /* Customer name - dynamic sizing, no wrap */
            .custom-table td:nth-child(2) .customer-name-text {
                font-size: clamp(0.95rem, 4.5vw, 1.2rem);
                font-weight: 600;
                color: #212529;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
                flex: 1;
                min-width: 0;
            }
            
            /* Action buttons container - fixed width based on content */
            .custom-table td:nth-child(2) .action-buttons {
                display: flex !important;
                gap: 5px;
                flex-shrink: 0;
            }
            
            /* Dynamic button sizing */
            .custom-table td:nth-child(2) .btn-action {
                width: clamp(30px, 7vw, 36px) !important;
                height: clamp(30px, 7vw, 36px) !important;
                border-radius: 8px !important;
                font-size: clamp(0.85rem, 3.5vw, 1rem) !important;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                padding: 0;
            }
            
            /* Hide the original actions column */
            .custom-table td:last-child {
                display: none !important;
            }
            
            /* Third row: Status only - dynamic sizing */
            .custom-table td:nth-child(6) {
                display: block !important;
                font-size: clamp(0.8rem, 3.2vw, 0.95rem);
                font-weight: 500;
                color: #f59e0b;
                padding: 0 !important;
                border: none !important;
                margin-top: 2px;
                line-height: 1.2;
            }
            
            /* Status colors */
            .custom-table td:nth-child(6) .badge {
                all: unset;
                font-size: inherit;
                font-weight: 500;
                padding: 0;
                background: transparent !important;
            }
            
            .custom-table td:nth-child(6) .badge.bg-warning {
                color: #f59e0b;
            }
            
            .custom-table td:nth-child(6) .badge.bg-primary {
                color: #0d6efd;
            }
            
            .custom-table td:nth-child(6) .badge.bg-info {
                color: #0dcaf0;
            }
            
            .custom-table td:nth-child(6) .badge.bg-success {
                color: #198754;
            }
            
            .custom-table td:nth-child(6) .badge.bg-secondary {
                color: #6c757d;
            }
            
            /* Hide all other columns */
            .custom-table td:nth-child(3),
            .custom-table td:nth-child(4),
            .custom-table td:nth-child(5) {
                display: none;
            }
            
            /* Driver badge - smaller */
            .custom-table td .driver-badge {
                font-size: 0.65rem;
                margin-top: 2px;
                display: inline-block;
                padding: 2px 6px;
            }
        }

        /* Medium phones (400px - 568px) */
        @media (min-width: 400px) and (max-width: 568px) {
            .custom-table tbody tr {
                padding: 10px;
                margin-bottom: 8px;
            }
            
            .custom-table td:first-child {
                font-size: 0.9rem;
                margin-bottom: 3px;
            }
            
            .custom-table td:nth-child(2) .customer-name-text {
                font-size: 1rem;
            }
            
            .custom-table td:nth-child(2) .btn-action {
                width: 32px !important;
                height: 32px !important;
                font-size: 0.9rem !important;
            }
            
            .custom-table td:nth-child(6) {
                font-size: 0.85rem;
            }
        }

        /* Small phones (below 400px) */
        @media (max-width: 399px) {
            .custom-table tbody tr {
                padding: 8px;
                margin-bottom: 6px;
            }
            
            .custom-table td:first-child {
                font-size: 0.8rem;
                margin-bottom: 2px;
            }
            
            .custom-table td:nth-child(2) .customer-name-text {
                font-size: 0.9rem;
            }
            
            .custom-table td:nth-child(2) .btn-action {
                width: 28px !important;
                height: 28px !important;
                font-size: 0.8rem !important;
            }
            
            .custom-table td:nth-child(2) .action-buttons {
                gap: 4px;
            }
            
            .custom-table td:nth-child(6) {
                font-size: 0.75rem;
            }
        }

        /* Extra small phones */
        @media (max-width: 320px) {
            .custom-table td:nth-child(2) .btn-action {
                width: 26px !important;
                height: 26px !important;
                font-size: 0.75rem !important;
            }
        }

        /* ===== DESKTOP FIX - HIDE MOBILE ACTION BUTTONS ===== */
        @media (min-width: 769px) {
            /* Hide mobile action buttons on desktop */
            .custom-table td:nth-child(2) .action-buttons.d-flex.d-md-none {
                display: none !important;
            }
            
            /* Also hide any action buttons in customer name column */
            .custom-table td:nth-child(2) .action-buttons {
                display: none !important;
            }
            
            /* Ensure desktop action buttons are visible in Actions column */
            .custom-table td:last-child .action-buttons.d-none.d-md-inline-flex {
                display: inline-flex !important;
            }
            
            /* Fix alignment for desktop actions */
            .custom-table td:last-child {
                white-space: nowrap;
                text-align: center;
                vertical-align: middle;
            }
            
            .custom-table td:last-child .action-buttons {
                justify-content: center !important;
                gap: 8px;
            }
            
            /* Style for action buttons in desktop */
            .custom-table td:last-child .btn-action {
                width: 36px;
                height: 36px;
                border-radius: 8px;
                font-size: 1.1rem;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                margin: 0 2px;
            }
        }
        
        .stat-card-row {
            margin-bottom: 1.5rem !important;
        }

        @media (max-width: 768px) {
            .stat-card-row {
                margin-bottom: 1rem !important;
            }
            
            .form-card {
                margin-top: 0.5rem;
            }
        }
        /* ===== ULTIMATE DESKTOP FIX - FORCE HIDE MOBILE BUTTONS ===== */
@media (min-width: 769px) {
    /* Target all possible mobile button containers */
    .custom-table td:nth-child(2) .action-buttons,
    .custom-table td:nth-child(2) div[class*="action-buttons"],
    .custom-table td:nth-child(2) .d-flex.d-md-none,
    .custom-table td:nth-child(2) [class*="d-md-none"] {
        display: none !important;
        visibility: hidden !important;
        opacity: 0 !important;
        pointer-events: none !important;
        width: 0 !important;
        height: 0 !important;
        overflow: hidden !important;
        position: absolute !important;
        z-index: -9999 !important;
    }
    
    /* Ensure desktop buttons are visible */
    .custom-table td:last-child .action-buttons,
    .custom-table td:last-child .d-none.d-md-inline-flex,
    .custom-table td:last-child [class*="d-md-inline-flex"] {
        display: inline-flex !important;
        visibility: visible !important;
        opacity: 1 !important;
        pointer-events: auto !important;
    }
}
/* Thermal Receipt Modal - MAS MALAKI ANG HEIGHT, WHITE TEXT */
#receiptModal .modal-dialog {
    max-width: 500px;
    margin: 1.75rem auto;
}

#receiptModal .modal-content {
    border-radius: 12px;
    border: none;
    box-shadow: 0 10px 30px rgba(0,0,0,0.2);
    overflow: hidden;
}

#receiptModal .modal-header {
    background: linear-gradient(135deg, #047857, #44D34E); /* Green gradient */
    border-bottom: 1px solid rgba(255,255,255,0.2);
    padding: 1rem 1.5rem;
}

#receiptModal .modal-header .modal-title {
    color: white !important; /* White text */
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 8px;
}

#receiptModal .modal-header .modal-title i {
    color: white !important; /* White icon */
    font-size: 1.2rem;
}

#receiptModal .modal-header .btn-close {
    filter: brightness(0) invert(1); /* White close button */
    opacity: 0.9;
}

#receiptModal .modal-header .btn-close:hover {
    opacity: 1;
    transform: rotate(90deg);
}

#receiptModal .modal-body {
    padding: 20px;
    background: #f5f5f5;
    display: flex;
    justify-content: center;
    align-items: flex-start; /* Start from top */
    min-height: auto;
    max-height: 80vh; /* INCREASED HEIGHT from 70vh to 80vh */
    overflow-y: auto;
    /* Hide scrollbar but keep functionality */
    scrollbar-width: none; /* Firefox */
    -ms-overflow-style: none; /* IE/Edge */
}

#receiptModal .modal-body::-webkit-scrollbar {
    display: none; /* Chrome/Safari/Opera */
}

/* Thermal receipt - EXACT SIZE, NEVER CHANGES */
#receiptModal .thermal-receipt {
    font-family: 'Courier New', monospace;
    width: 72mm !important;
    max-width: 72mm !important;
    min-width: 72mm !important;
    margin: 0 auto;
    padding: 3mm;
    background: white;
    color: black;
    font-size: 11px;
    line-height: 1.3;
    box-sizing: border-box;
    border: 1px solid #ddd;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

/* Mobile - MAS MALAKI ANG HEIGHT */
@media (max-width: 768px) {
    #receiptModal .modal-dialog {
        max-width: 500px;
        margin: 1rem auto;
    }
    
    #receiptModal .modal-body {
        padding: 15px;
        max-height: 85vh; /* INCREASED from 80vh to 85vh */
    }
    
    /* Receipt stays EXACTLY THE SAME SIZE */
    #receiptModal .thermal-receipt {
        width: 72mm !important;
        max-width: 72mm !important;
        min-width: 72mm !important;
        font-size: 11px !important;
        padding: 3mm !important;
    }
}

/* Very small phones - MAS MALAKI ANG HEIGHT */
@media (max-width: 480px) {
    #receiptModal .modal-dialog {
        max-width: 95%;
        margin: 0.5rem auto;
    }
    
    #receiptModal .modal-body {
        padding: 10px;
        max-height: 90vh; /* INCREASED from 85vh to 90vh */
    }
    
    /* Receipt STAYS EXACTLY THE SAME */
    #receiptModal .thermal-receipt {
        width: 72mm !important;
        max-width: 72mm !important;
        min-width: 72mm !important;
        font-size: 11px !important;
        padding: 3mm !important;
    }
}

/* Extra small phones - MAS MALAKI ANG HEIGHT */
@media (max-width: 360px) {
    #receiptModal .modal-body {
        padding: 5px;
        max-height: 95vh; /* INCREASED from 90vh to 95vh */
    }
    
    /* Receipt NEVER CHANGES SIZE */
    #receiptModal .thermal-receipt {
        width: 72mm !important;
        max-width: 72mm !important;
        min-width: 72mm !important;
    }
}
/* Thermal Receipt Modal - SAME HEIGHT AS DELIVERY DETAILS */
#receiptModal .modal-dialog {
    max-width: 500px;
    margin: 1.75rem auto;
}

#receiptModal .modal-content {
    border-radius: 12px;
    border: none;
    box-shadow: 0 10px 30px rgba(0,0,0,0.2);
    overflow: hidden;
    min-height: 400px; /* MINIMUM HEIGHT para di masyadong liit */
}

#receiptModal .modal-header {
    background: linear-gradient(135deg, #047857, #44D34E);
    border-bottom: 1px solid rgba(255,255,255,0.2);
    padding: 1rem 1.5rem;
}

#receiptModal .modal-header .modal-title {
    color: white !important;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 8px;
}

#receiptModal .modal-header .modal-title i {
    color: white !important;
    font-size: 1.2rem;
}

#receiptModal .modal-header .btn-close {
    filter: brightness(0) invert(1);
    opacity: 0.9;
}

#receiptModal .modal-body {
    padding: 20px;
    background: #f5f5f5;
    display: flex;
    justify-content: center;
    align-items: flex-start;
    min-height: 350px; /* MINIMUM HEIGHT para kapareho ng details */
    max-height: 70vh;
    overflow-y: auto;
    scrollbar-width: none;
    -ms-overflow-style: none;
}

#receiptModal .modal-body::-webkit-scrollbar {
    display: none;
}

/* Thermal receipt - exact size */
#receiptModal .thermal-receipt {
    font-family: 'Courier New', monospace;
    width: 72mm !important;
    max-width: 72mm !important;
    min-width: 72mm !important;
    margin: 0 auto;
    padding: 3mm;
    background: white;
    color: black;
    font-size: 11px;
    line-height: 1.3;
    box-sizing: border-box;
    border: 1px solid #ddd;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

/* Mobile - SAME HEIGHT AS DETAILS */
@media (max-width: 768px) {
    #receiptModal .modal-dialog {
        max-width: 500px;
        margin: 1rem auto;
    }
    
    #receiptModal .modal-content {
        min-height: 450px; /* Mas malaki sa mobile */
    }
    
    #receiptModal .modal-body {
        padding: 15px;
        min-height: 400px; /* SAME AS DETAILS */
        max-height: 85vh;
    }
    
    #receiptModal .thermal-receipt {
        width: 72mm !important;
        max-width: 72mm !important;
        min-width: 72mm !important;
        font-size: 11px !important;
        padding: 3mm !important;
    }
}

@media (max-width: 480px) {
    #receiptModal .modal-dialog {
        max-width: 95%;
        margin: 0.5rem auto;
    }
    
    #receiptModal .modal-content {
        min-height: 500px; /* Mas malaki sa maliit na phone */
    }
    
    #receiptModal .modal-body {
        padding: 10px;
        min-height: 450px; /* SAME AS DETAILS */
        max-height: 90vh;
    }
    
    #receiptModal .thermal-receipt {
        width: 72mm !important;
        max-width: 72mm !important;
        min-width: 72mm !important;
        font-size: 11px !important;
        padding: 3mm !important;
    }
}

@media (max-width: 360px) {
    #receiptModal .modal-content {
        min-height: 550px;
    }
    
    #receiptModal .modal-body {
        min-height: 500px;
        padding: 5px;
        max-height: 95vh;
    }
    
    #receiptModal .thermal-receipt {
        width: 72mm !important;
        max-width: 72mm !important;
        min-width: 72mm !important;
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
                        <a class="nav-link active" href="fordelivery.php">
                            <i class="bi bi-truck"></i>
                            <span class="nav-text">For Delivery</span>
                        </a>
                    </li>

                    <li class="nav-item">
                        <a class="nav-link" href="trip_tickets.php">
                            <i class="bi bi-ticket"></i>
                            <span class="nav-text">Trip Tickets</span>
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
            <div class="navbar-top">
                <button class="mobile-toggle-btn" id="mobileToggleBtn">
                    <i class="bi bi-list"></i>
                </button>
                <div class="page-title">
                    <h2>For Delivery</h2>
                    <p>Manage and track deliveries in progress</p>
                </div>
                <!-- GPS Tracking Button with Shift Management -->
                <div style="margin-left: auto; display: flex; gap: 10px; align-items: center;">
                    <div id="locationIndicator" class="badge bg-secondary" style="padding: 8px 12px;">
                        <span class="tracking-indicator tracking-inactive"></span>
                        <span id="locationStatus">Offline</span>
                        <span id="updateCount" class="update-counter"></span>
                    </div>
                </div>
            </div>

       <!-- Delivery Stats -->
<div class="row stat-card-row g-1 g-sm-2">
    <!-- Card 1 - Pending -->
    <div class="col">
        <div class="stat-card inventory">
            <i class="bi bi-clock"></i>
            <div class="stat-content">
                <div class="stat-value"><?php echo $stats['pending_count'] ?? 0; ?></div>
                <div class="stat-label">Pending</div>
            </div>
        </div>
    </div>

    <!-- Card 2 - Active -->
    <div class="col">
        <div class="stat-card pending">
            <i class="bi bi-truck"></i>
            <div class="stat-content">
                <div class="stat-value"><?php echo $stats['active_count'] ?? 0; ?></div>
                <div class="stat-label">Active</div>
            </div>
        </div>
    </div>

    <!-- Card 3 - Completed Today -->
    <div class="col">
        <div class="stat-card complete">
            <i class="bi bi-check-circle"></i>
            <div class="stat-content">
                <div class="stat-value"><?php echo $stats['completed_today'] ?? 0; ?></div>
                <div class="stat-label">Completed Today</div>
            </div>
        </div>
    </div>

    <!-- Card 4 - Total Completed -->
    <div class="col">
        <div class="stat-card sales">
            <i class="bi bi-archive"></i>
            <div class="stat-content">
                <div class="stat-value"><?php echo $stats['total_completed'] ?? 0; ?></div>
                <div class="stat-label">Total Completed</div>
            </div>
        </div>
    </div>
</div>

            <!-- FILTER SECTION - SALES ORDERS -->
<div class="form-card mb-4">
    <div class="filter-header">
        <h5>
            <i class="bi bi-funnel"></i> Filter Sales Orders
        </h5>
        <button class="filter-toggle-btn" type="button" id="salesFilterToggle" aria-expanded="false">
            <i class="bi bi-chevron-down" id="salesFilterIcon"></i>
        </button>
    </div>
    
    <div class="filter-content collapsed" id="salesFilterContent">
        <div class="row g-3">
            <!-- Search Field -->
            <div class="col-12 col-md-6">
                <label class="form-label">
                    <i class="bi bi-search"></i> Search
                </label>
                <div class="input-group">
                    <input type="text" class="form-control" id="searchInput" placeholder="Search by order ID, customer name...">
                </div>
            </div>
            
            <!-- Status Filter -->
            <div class="col-12 col-md-4">
                <label class="form-label">
                    <i class="bi bi-flag"></i> Status
                </label>
                <select class="form-select" id="statusFilter">
                    <option value="">All Status</option>
                    <option value="pending">Pending</option>
                    <option value="in-transit">In Transit</option>
                    <option value="partial">Partial</option>
                    <option value="delivered">Delivered</option>
                </select>
            </div>
        </div>
    </div>
</div>

            <!-- No Orders Message -->
            <?php if (empty($delivery_orders)): ?>
                <div class="alert alert-info text-center py-4">
                    <i class="bi bi-truck" style="font-size: 2rem;"></i>
                    <p class="mt-3 mb-0">
                        No deliveries found.
                        <?php if ($user_role == 'delivery'): ?>
                            <br><small>You don't have any deliveries assigned yet.</small>
                        <?php elseif ($delivery_branch_column_exists && !$view_all_branches): ?>
                            <br><small>No deliveries found for your branch.</small>
                        <?php endif; ?>
                    </p>
                </div>
            <?php else: ?>

            <!-- Delivery Orders Table - RESPONSIVE LAYOUT -->
            <div class="card">
                <div class="table-container">
                    <table class="table custom-table compact-table">
                        <thead class="table-light">
                            <tr>
                                <th>Order ID</th>
                                <th>Customer Name</th>
                                <th>Address</th>
                                <th>Contact</th>
                                <th>Items</th>
                                <th>Status</th>
                                <th class="text-center align-middle">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($delivery_orders as $order): ?>
                            <tr class="<?php echo $order['delivery_status'] == 'delivered' ? 'delivered-row' : ''; ?>">
                                <td>
                                    <span class="badge bg-light text-dark"><?php echo htmlspecialchars($order['so_number']); ?></span>
                                    <?php if ($user_role != 'delivery' && isset($order['driver_name']) && $order['driver_name']): ?>
                                        <br><small class="driver-badge"><?php echo htmlspecialchars($order['driver_name']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="customer-name-text"><?php echo htmlspecialchars($order['customer_name']); ?></span>
                                            <!-- Action buttons for MOBILE only (hidden on desktop) -->
                                            <div class="action-buttons d-flex d-md-none" style="display: inline-flex !important; margin-left: 8px;">
                                        <button class="btn-action btn-view" title="View Details" onclick="viewDeliveryDetails(<?php echo $order['delivery_id']; ?>)">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                        
                                        <?php 
                                        $has_coordinates = !empty($order['latitude']) && !empty($order['longitude']) && 
                                                           $order['latitude'] != 0 && $order['longitude'] != 0;
                                        if ($has_coordinates): 
                                        ?>
                                            <button class="btn-action btn-map" title="Navigate to Customer" onclick="openLiveNavigation(
                                                <?php echo $order['latitude']; ?>, 
                                                <?php echo $order['longitude']; ?>, 
                                                '<?php echo htmlspecialchars(addslashes($order['customer_name'])); ?>', 
                                                '<?php echo htmlspecialchars(addslashes($order['address'] . ', ' . $order['city'])); ?>'
                                            )">
                                                <i class="bi bi-geo-alt-fill"></i>
                                            </button>
                                        <?php endif; ?>
                                        
                                        <?php if ($order['delivery_status'] == 'pending'): ?>
                                            <button class="btn-action btn-truck" title="Start Delivery" onclick="updateDeliveryStatus(<?php echo $order['delivery_id']; ?>, 'in-transit')">
                                                <i class="bi bi-truck"></i>
                                            </button>
                                        <?php elseif ($order['delivery_status'] == 'in-transit'): ?>
                                            <button class="btn-action btn-success" title="Mark as Delivered" onclick="showDeliveryModal(<?php echo $order['delivery_id']; ?>, <?php echo $order['so_id']; ?>, '<?php echo htmlspecialchars(addslashes($order['so_number'])); ?>')">
                                                <i class="bi bi-check-lg"></i>
                                            </button>
                                            <button class="btn-action btn-warning" title="Mark as Partial" onclick="updateDeliveryStatus(<?php echo $order['delivery_id']; ?>, 'partial')">
                                                <i class="bi bi-exclamation-triangle"></i>
                                            </button>
                                        <?php elseif ($order['delivery_status'] == 'partial'): ?>
                                            <button class="btn-action btn-success" title="Complete Remaining Items" onclick="showDeliveryModal(<?php echo $order['delivery_id']; ?>, <?php echo $order['so_id']; ?>, '<?php echo htmlspecialchars(addslashes($order['so_number'])); ?>')">
                                                <i class="bi bi-check-lg"></i>
                                            </button>
                                        <?php endif; ?>
                                        
                                        <?php if ($order['delivery_status'] == 'delivered'): ?>
                                            <button class="btn-action btn-print" title="Print Receipt" onclick="showReceiptModal(<?php echo $order['delivery_id']; ?>, '<?php echo htmlspecialchars(addslashes($order['so_number'])); ?>', '<?php echo htmlspecialchars(addslashes($order['customer_name'])); ?>', '<?php echo htmlspecialchars(addslashes($order['address'] . ', ' . $order['city'])); ?>', '<?php echo htmlspecialchars(addslashes($order['signed_by'])); ?>', '<?php echo $order['delivery_date']; ?>', '<?php echo htmlspecialchars(addslashes($order['items_receipt'])); ?>')">
                                                <i class="bi bi-receipt"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td><?php echo htmlspecialchars($order['address'] . ', ' . $order['city']); ?></td>
                                <td><?php echo htmlspecialchars($order['phone_number']); ?></td>
                                <td>
                                    <?php 
                                    if (!empty($order['items'])) {
                                        $items = explode('; ', $order['items']);
                                        $display_items = array_slice($items, 0, 2);
                                        foreach ($display_items as $index => $item):
                                    ?>
                                        <small class="d-block"><?php echo htmlspecialchars($item); ?></small>
                                    <?php 
                                        endforeach;
                                        if (count($items) > 2) {
                                            echo '<small class="text-muted">+' . (count($items) - 2) . ' more</small>';
                                        }
                                    } else {
                                        echo '<small class="text-muted">No items listed</small>';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <?php
                                    $status_badge = '';
                                    $status_text = '';
                                    switch ($order['delivery_status']) {
                                        case 'pending':
                                            $status_badge = 'bg-warning';
                                            $status_text = 'Pending';
                                            break;
                                        case 'in-transit':
                                            $status_badge = 'bg-primary';
                                            $status_text = 'In Transit';
                                            break;
                                        case 'partial':
                                            $status_badge = 'bg-info';
                                            $status_text = 'Partial';
                                            break;
                                        case 'delivered':
                                            $status_badge = 'bg-success';
                                            $status_text = 'Delivered';
                                            break;
                                        default:
                                            $status_badge = 'bg-secondary';
                                            $status_text = ucfirst($order['delivery_status']);
                                    }
                                    ?>
                                    <span class="badge <?php echo $status_badge; ?>">
                                        <?php echo $status_text; ?>
                                    </span>
                                </td>
                                <td class="text-center align-middle">
    <!-- Desktop action buttons (hidden on mobile) -->
    <div class="action-buttons d-none d-md-inline-flex" style="justify-content: center; gap: 8px;">
                                        <button class="btn-action btn-view" title="View Details" onclick="viewDeliveryDetails(<?php echo $order['delivery_id']; ?>)">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                        
                                        <?php if ($has_coordinates): ?>
                                            <button class="btn-action btn-map" title="Navigate to Customer" onclick="openLiveNavigation(
                                                <?php echo $order['latitude']; ?>, 
                                                <?php echo $order['longitude']; ?>, 
                                                '<?php echo htmlspecialchars(addslashes($order['customer_name'])); ?>', 
                                                '<?php echo htmlspecialchars(addslashes($order['address'] . ', ' . $order['city'])); ?>'
                                            )">
                                                <i class="bi bi-geo-alt-fill"></i>
                                            </button>
                                        <?php endif; ?>
                                        
                                        <?php if ($order['delivery_status'] == 'pending'): ?>
                                            <button class="btn-action btn-truck" title="Start Delivery" onclick="updateDeliveryStatus(<?php echo $order['delivery_id']; ?>, 'in-transit')">
                                                <i class="bi bi-truck"></i>
                                            </button>
                                        <?php elseif ($order['delivery_status'] == 'in-transit'): ?>
                                            <button class="btn-action btn-success" title="Mark as Delivered" onclick="showDeliveryModal(<?php echo $order['delivery_id']; ?>, <?php echo $order['so_id']; ?>, '<?php echo htmlspecialchars(addslashes($order['so_number'])); ?>')">
                                                <i class="bi bi-check-lg"></i>
                                            </button>
                                            <button class="btn-action btn-warning" title="Mark as Partial" onclick="updateDeliveryStatus(<?php echo $order['delivery_id']; ?>, 'partial')">
                                                <i class="bi bi-exclamation-triangle"></i>
                                            </button>
                                        <?php elseif ($order['delivery_status'] == 'partial'): ?>
                                            <button class="btn-action btn-success" title="Complete Remaining Items" onclick="showDeliveryModal(<?php echo $order['delivery_id']; ?>, <?php echo $order['so_id']; ?>, '<?php echo htmlspecialchars(addslashes($order['so_number'])); ?>')">
                                                <i class="bi bi-check-lg"></i>
                                            </button>
                                        <?php endif; ?>
                                        
                                        <?php if ($order['delivery_status'] == 'delivered'): ?>
                                            <button class="btn-action btn-print" title="Print Receipt" onclick="showReceiptModal(<?php echo $order['delivery_id']; ?>, '<?php echo htmlspecialchars(addslashes($order['so_number'])); ?>', '<?php echo htmlspecialchars(addslashes($order['customer_name'])); ?>', '<?php echo htmlspecialchars(addslashes($order['address'] . ', ' . $order['city'])); ?>', '<?php echo htmlspecialchars(addslashes($order['signed_by'])); ?>', '<?php echo $order['delivery_date']; ?>', '<?php echo htmlspecialchars(addslashes($order['items_receipt'])); ?>')">
                                                <i class="bi bi-receipt"></i>
                                            </button>
                                        <?php endif; ?>
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
                <a class="nav-link active" href="fordelivery.php">
                    <i class="bi bi-truck"></i>
                    <span>Delivery</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="trip_tickets.php">
                    <i class="bi bi-ticket-perforated"></i>
                    <span>Tickets</span>
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

    <!-- Hidden thermal receipt container -->
    <div id="thermalReceipt" style="display: none;"></div>

    <!-- Live Tracking Modal -->
    <div class="modal fade" id="trackingModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">
                        <i class="bi bi-navigation me-2"></i>
                        Live Navigation to Customer
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-0 position-relative">
                    <div id="trackingMap" class="location-map"></div>
                    
                    <!-- Navigation Status Panel -->
                    <div class="status-panel" id="navigationStatusPanel">
                        <h6>
                            <i class="bi bi-info-circle-fill text-primary me-2"></i>
                            Navigation Status
                            <button class="toggle-panel-btn" id="toggleStatusPanel" title="Expand/Collapse">
                                <i class="bi bi-chevron-up"></i>
                            </button>
                        </h6>
                        
                        <div class="panel-content">
                            <!-- Your Location -->
                            <div class="info-row">
                                <div class="info-label">
                                    <i class="bi bi-geo-alt-fill text-primary"></i>
                                    Your Location:
                                </div>
                                <div class="info-value" id="yourLocationText">Acquiring GPS...</div>
                                <div class="coordinates-text" id="yourCoordinates">--</div>
                            </div>
                            
                            <!-- Destination -->
                            <div class="info-row">
                                <div class="info-label">
                                    <i class="bi bi-pin-map-fill text-danger"></i>
                                    Destination:
                                </div>
                                <div class="info-value" id="destinationText">Customer Location</div>
                                <div class="coordinates-text" id="destinationCoordinates">--</div>
                            </div>
                            
                            <!-- Distance & Time -->
                            <div class="row g-2 mb-3">
                                <div class="col-6">
                                    <div class="bg-light p-2 rounded text-center">
                                        <div class="info-label">Distance</div>
                                        <div class="info-value" style="font-size: 1.2rem;">
                                            <span id="distanceText">--</span> <small>km</small>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="bg-light p-2 rounded text-center">
                                        <div class="info-label">Est. Time</div>
                                        <div class="info-value" style="font-size: 1.2rem;">
                                            <span id="timeText">--</span> <small>min</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- GPS Accuracy -->
                            <div class="info-row">
                                <div class="info-label">
                                    <i class="bi bi-satellite me-1"></i>
                                    GPS Accuracy:
                                </div>
                                <div class="progress mb-1" style="height: 8px;">
                                    <div id="accuracyBar" class="progress-bar bg-success" style="width: 100%"></div>
                                </div>
                                <div class="d-flex justify-content-between align-items-center">
                                    <small id="accuracyText" class="text-muted">High accuracy</small>
                                    <button class="retry-btn" onclick="retryGPSTracking()" title="Retry GPS">
                                        <i class="bi bi-arrow-repeat"></i> Retry
                                    </button>
                                </div>
                            </div>
                            
                            <!-- Navigation Actions -->
                            <div class="d-grid gap-2 mt-3">
                                <button class="btn btn-sm btn-success" onclick="centerOnYourLocation()">
                                    <i class="bi bi-crosshair me-2"></i>Center on Me
                                </button>
                                <button class="btn btn-sm btn-outline-primary" onclick="openGoogleMaps()">
                                    <i class="bi bi-google me-2"></i>Open in Google Maps
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-danger" onclick="confirmStopLiveNavigation()">
                        <i class="bi bi-stop-circle me-2"></i>Stop Navigation
                    </button>
                </div>
            </div>
        </div>
    </div>

  <!-- View Details Modal -->
<div class="modal fade" id="viewDetailsModal" tabindex="-1" aria-labelledby="viewDetailsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h5 class="modal-title" id="viewDetailsModalLabel">
                    <i class="bi bi-truck text-custom me-2"></i>
                    Delivery Details
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-3" id="viewDetailsModalBody">
                <div class="text-center py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-3">Loading delivery details...</p>
                </div>
            </div>
            <!-- Modal footer removed -->
        </div>
    </div>
</div>

<!-- Location Map Modal -->
<div class="modal fade" id="locationMapModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-geo-alt-fill text-custom me-2"></i>
                    Customer Location
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="customerLocationMap" class="location-map"></div>
                
                <div class="location-info">
                    <h6 id="modalCustomerName" class="mb-3"></h6>
                    <p>
                        <i class="bi bi-geo-alt"></i>
                        <strong>Address:</strong> <span id="modalCustomerAddress"></span>
                    </p>
                    <p>
                        <i class="bi bi-geo"></i>
                        <strong>Coordinates:</strong>
                        <span id="modalCoordinates" class="coordinates-badge">
                            <i class="bi bi-crosshair"></i>
                            <span id="modalLat"></span>, <span id="modalLng"></span>
                        </span>
                    </p>
                </div>
            </div>
            <!-- Modal footer removed -->
        </div>
    </div>
</div>

<!-- Photo Modal -->
<div class="modal fade" id="photoModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-image text-primary me-2"></i>
                    Proof of Delivery Photo
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center">
                <img src="" alt="Proof of Delivery" class="photo-modal-img" id="photoModalImg">
            </div>
            <!-- Modal footer removed -->
        </div>
    </div>
</div>

<!-- Live Tracking Modal -->
<div class="modal fade" id="trackingModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">
                    <i class="bi bi-navigation me-2"></i>
                    Live Navigation to Customer
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0 position-relative">
                <div id="trackingMap" class="location-map"></div>
                
                <!-- Navigation Status Panel -->
                <div class="status-panel" id="navigationStatusPanel">
                    <h6>
                        <i class="bi bi-info-circle-fill text-primary me-2"></i>
                        Navigation Status
                        <button class="toggle-panel-btn" id="toggleStatusPanel" title="Expand/Collapse">
                            <i class="bi bi-chevron-up"></i>
                        </button>
                    </h6>
                    
                    <div class="panel-content">
                        <!-- Your Location -->
                        <div class="info-row">
                            <div class="info-label">
                                <i class="bi bi-geo-alt-fill text-primary"></i>
                                Your Location:
                            </div>
                            <div class="info-value" id="yourLocationText">Acquiring GPS...</div>
                            <div class="coordinates-text" id="yourCoordinates">--</div>
                        </div>
                        
                        <!-- Destination -->
                        <div class="info-row">
                            <div class="info-label">
                                <i class="bi bi-pin-map-fill text-danger"></i>
                                Destination:
                            </div>
                            <div class="info-value" id="destinationText">Customer Location</div>
                            <div class="coordinates-text" id="destinationCoordinates">--</div>
                        </div>
                        
                        <!-- Distance & Time -->
                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <div class="bg-light p-2 rounded text-center">
                                    <div class="info-label">Distance</div>
                                    <div class="info-value" style="font-size: 1.2rem;">
                                        <span id="distanceText">--</span> <small>km</small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="bg-light p-2 rounded text-center">
                                    <div class="info-label">Est. Time</div>
                                    <div class="info-value" style="font-size: 1.2rem;">
                                        <span id="timeText">--</span> <small>min</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- GPS Accuracy -->
                        <div class="info-row">
                            <div class="info-label">
                                <i class="bi bi-satellite me-1"></i>
                                GPS Accuracy:
                            </div>
                            <div class="progress mb-1" style="height: 8px;">
                                <div id="accuracyBar" class="progress-bar bg-success" style="width: 100%"></div>
                            </div>
                            <div class="d-flex justify-content-between align-items-center">
                                <small id="accuracyText" class="text-muted">High accuracy</small>
                                <button class="retry-btn" onclick="retryGPSTracking()" title="Retry GPS">
                                    <i class="bi bi-arrow-repeat"></i> Retry
                                </button>
                            </div>
                        </div>
                        
                        <!-- Navigation Actions -->
                        <div class="d-grid gap-2 mt-3">
                            <button class="btn btn-sm btn-success" onclick="centerOnYourLocation()">
                                <i class="bi bi-crosshair me-2"></i>Center on Me
                            </button>
                            <button class="btn btn-sm btn-outline-primary" onclick="openGoogleMaps()">
                                <i class="bi bi-google me-2"></i>Open in Google Maps
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            <!-- Modal footer removed - Close button nasa header na -->
        </div>
    </div>
</div>

<!-- Delivery Modal -->
<div class="modal fade" id="deliveryModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h5 class="modal-title">Complete Delivery</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-3">
                <form id="deliveryForm" enctype="multipart/form-data" action="update_delivery.php" method="POST">
                    <input type="hidden" name="delivery_id" id="modalDeliveryId">
                    <input type="hidden" name="so_id" id="modalSoId">
                    <input type="hidden" name="so_number" id="modalSoNumber">
                    <input type="hidden" name="branch_id" value="<?php echo $branch_id; ?>">
                    
                    <div class="alert alert-info py-2">
                        <strong id="orderIdDisplay"></strong> - Delivery Confirmation Required
                    </div>

                    <div class="row mb-2">
                        <div class="col-md-6">
                            <label class="form-label small">Delivery Date</label>
                            <input type="datetime-local" class="form-control form-control-sm" name="delivery_date" required value="<?php echo date('Y-m-d\TH:i'); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small">Signed By</label>
                            <input type="text" class="form-control form-control-sm" name="signed_by" placeholder="Customer name" required>
                        </div>
                    </div>

                    <div class="mb-2">
                        <label class="form-label small">Proof of Delivery Photo</label>
                        <input type="file" class="form-control form-control-sm" name="proof_photo" accept="image/*" required>
                        <small class="text-muted">Upload photo of delivered package</small>
                    </div>

                    <div class="mb-2">
                        <label class="form-label small">Remarks</label>
                        <textarea class="form-control form-control-sm" name="remarks" rows="2" placeholder="Any notes..."></textarea>
                    </div>

                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" name="confirm_delivery" required>
                        <label class="form-check-label small">
                            I confirm this delivery is complete
                        </label>
                    </div>
                    
                    <div class="d-flex justify-content-end gap-2 mt-3">
                        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-sm btn-primary">Confirm Delivery</button>
                    </div>
                </form>
            </div>
            <!-- Modal footer removed from modal level -->
        </div>
    </div>
</div>

<!-- Thermal Receipt Modal - MAS MALAKI ANG HEIGHT, WHITE TEXT -->
<div class="modal fade" id="receiptModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable" style="max-width: 500px;">
        <div class="modal-content">
            <div class="modal-header" style="background: linear-gradient(135deg, #047857, #44D34E);">
                <h5 class="modal-title" style="color: white;">
                    <i class="bi bi-receipt me-2" style="color: white;"></i>
                    Receipt Preview
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" style="filter: brightness(0) invert(1);"></button>
            </div>
            <div class="modal-body" id="receiptContent" style="padding: 20px; min-height: auto;">
                <!-- Receipt preview will be loaded here -->
            </div>
            <!-- Modal footer removed -->
        </div>
    </div>
</div>

<!-- Partial Delivery Modal -->
<div class="modal fade" id="partialModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Partial Delivery</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="partialForm">
                    <div class="mb-3">
                        <label class="form-label">Reason for Partial Delivery</label>
                        <select class="form-select" id="partialReason" required>
                            <option value="">Select reason</option>
                            <option value="Out of stock">Out of stock</option>
                            <option value="Damaged items">Damaged items</option>
                            <option value="Customer refused some items">Customer refused some items</option>
                            <option value="Wrong items">Wrong items</option>
                            <option value="Quantity mismatch">Quantity mismatch</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    <div class="mb-3" id="otherReasonDiv" style="display: none;">
                        <label class="form-label">Please specify</label>
                        <input type="text" class="form-control" id="otherReason" placeholder="Enter reason">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Items Delivered</label>
                        <div id="itemsList" class="border p-2 rounded mb-2" style="max-height: 200px; overflow-y: auto;">
                            <!-- Items will be loaded here -->
                        </div>
                        <small class="text-muted">Check the items that were successfully delivered</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Additional Details</label>
                        <textarea class="form-control" id="partialDetails" rows="3" placeholder="Provide more details about the partial delivery..."></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-warning" onclick="submitPartialDelivery()">Submit Partial</button>
            </div>
        </div>
    </div>
</div>

<!-- Mobile Profile/Logout Modal -->
<div class="modal fade" id="profileModal" tabindex="-1" aria-labelledby="profileModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
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
            <!-- Modal footer removed -->
        </div>
    </div>
</div>


    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="https://unpkg.com/leaflet-routing-machine@3.2.12/dist/leaflet-routing-machine.js"></script>
   <script>
        const branchId = <?php echo $branch_id; ?>;
        const viewAllBranches = <?php echo $view_all_branches ? 'true' : 'false'; ?>;
        const userRole = '<?php echo $user_role; ?>';
        const driverId = <?php echo $driver_id ?: 0; ?>;

        let currentDeliveryId = null;
        let currentSoId = null;
        let currentOrderNumber = null;
        let map = null;
        let marker = null;
        let currentLat = null;
        let currentLng = null;
        let currentCustomerName = null;
        let currentAddress = null;
        let currentPartialDeliveryId = null;
        let currentItems = [];
        let currentThermalReceipt = '';

        // ================= GPS TRACKING VARIABLES =================
        let watchId = null;
        let trackingActive = false;
        let updateCount = 0;
        let retryCount = 0;
        let currentDriverId = <?php echo $driver_id ?: 0; ?>;

        // ================= LIVE TRACKING VARIABLES =================
        let liveTrackingMap = null;
        let routingControl = null;
        let userMarker = null;
        let destinationMarker = null;
        let watchPositionId = null;
        let currentPosition = null;
        let destinationPosition = null;
        let accuracyCircle = null;
        let gpsRetryTimeout = null;

        // Cache ng huling posisyon para sa mabilis na initial load
        let lastKnownPosition = null;

        // ================= MOBILE NAVIGATION FUNCTIONS =================
        function initMobileNav() {
            const mobileNav = document.getElementById('mobileNav');
            if (!mobileNav) return;
            
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

        // ================= GPS TRACKING FUNCTIONS (Main Shift) =================
        function toggleTracking() {
            if (!navigator.geolocation) {
                Swal.fire('Error', 'Geolocation is not supported by your browser.', 'error');
                return;
            }

            if (trackingActive) {
                // Check for active deliveries before stopping
                const rows = document.querySelectorAll('tbody tr');
                let hasActiveDelivery = false;
                
                rows.forEach(row => {
                    const statusCell = row.cells[5];
                    if (statusCell) {
                        const badge = statusCell.querySelector('.badge');
                        if (badge) {
                            const badgeClass = badge.className;
                            if (badgeClass.includes('bg-primary') || badgeClass.includes('bg-info')) {
                                hasActiveDelivery = true;
                            }
                        }
                    }
                });
                
                if (hasActiveDelivery) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Cannot Stop Tracking',
                        text: 'You have active deliveries. Please complete all deliveries first.',
                        confirmButtonColor: '#28a745'
                    });
                    return;
                }
                
                stopTracking();
            } else {
                startTracking();
            }
        }

        function startTracking() {
            updateUI('requesting', 'Starting shift...');
            startShift();
        }

        function startShift() {
            fetch('../Global/gps_shift_start.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'start_shift',
                    driver_id: currentDriverId,
                    force: true
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    console.log('Shift started:', data);
                    updateUI('success', 'Shift started');
                    startGPSTracking();
                } else {
                    console.error('Failed to start shift:', data.error);
                    updateUI('error', 'Shift failed: ' + data.error);
                    
                    if (data.error && data.error.includes('active shift')) {
                        Swal.fire({
                            title: 'Active Shift Found',
                            text: 'You have an active shift. Do you want to end it?',
                            icon: 'warning',
                            showCancelButton: true,
                            confirmButtonColor: '#dc3545',
                            cancelButtonColor: '#6c757d',
                            confirmButtonText: 'Yes, End Shift'
                        }).then((result) => {
                            if (result.isConfirmed) {
                                endExistingShift();
                            }
                        });
                    }
                }
            })
            .catch(error => {
                console.error('Error starting shift:', error);
                updateUI('error', 'Connection error');
            });
        }

        function endExistingShift() {
            updateUI('requesting', 'Ending previous shift...');
            
            fetch('../Global/gps_shift_start.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'force_end_shift',
                    driver_id: currentDriverId
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    console.log('Previous shift ended:', data);
                    startShift();
                } else {
                    updateUI('error', 'Failed to end shift');
                }
            });
        }

        function startGPSTracking() {
            updateUI('requesting', 'Getting GPS location...');
            
            navigator.geolocation.getCurrentPosition(
                function(position) {
                    sendLocation(position.coords);
                    startWatching();
                },
                function(error) {
                    console.log('GPS Error:', error.code, error.message);
                    retryCount++;
                    
                    if (retryCount <= 3) {
                        updateUI('retry', 'Retrying GPS... (' + retryCount + '/3)');
                        
                        setTimeout(function() {
                            startGPSTracking();
                        }, 2000);
                    } else {
                        updateUI('error', 'GPS Error: ' + getErrorMessage(error));
                        retryCount = 0;
                    }
                },
                {
                    enableHighAccuracy: false,
                    timeout: 10000,
                    maximumAge: 60000
                }
            );
        }

        function getErrorMessage(error) {
            switch(error.code) {
                case error.PERMISSION_DENIED:
                    return 'Please enable location permissions';
                case error.POSITION_UNAVAILABLE:
                    return 'Location unavailable - check GPS';
                case error.TIMEOUT:
                    return 'GPS timeout - try again';
                default:
                    return error.message;
            }
        }

        function updateUI(status, message) {
            let indicator = document.getElementById('locationIndicator');
            let statusSpan = document.getElementById('locationStatus');
            let updateSpan = document.getElementById('updateCount');
            
            switch(status) {
                case 'requesting':
                    indicator.className = 'badge bg-warning';
                    statusSpan.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>' + message;
                    break;
                case 'retry':
                    indicator.className = 'badge bg-info';
                    statusSpan.innerHTML = '<i class="bi bi-arrow-repeat me-1"></i>' + message;
                    break;
                case 'success':
                    indicator.className = 'badge bg-success';
                    statusSpan.innerHTML = '<i class="bi bi-check-circle-fill me-1"></i>' + message;
                    break;
                case 'error':
                    indicator.className = 'badge bg-danger';
                    statusSpan.innerHTML = '<i class="bi bi-exclamation-triangle-fill me-1"></i>' + message;
                    break;
                default:
                    indicator.className = 'badge bg-secondary';
                    statusSpan.innerHTML = message || 'Offline';
            }
            
            if (updateSpan) {
                updateSpan.innerHTML = updateCount > 0 ? '(' + updateCount + ')' : '';
            }
        }

        function startWatching() {
            if (watchId) return;

            watchId = navigator.geolocation.watchPosition(
                function(position) {
                    sendLocation(position.coords);
                    updateCount++;
                    
                    updateUI('success', 'LIVE');
                },
                function(error) {
                    console.log('Watch error:', error.message);
                },
                {
                    enableHighAccuracy: true,
                    timeout: 15000,
                    maximumAge: 10000
                }
            );

            trackingActive = true;
            
        }

        function stopTracking() {
            if (watchId) {
                navigator.geolocation.clearWatch(watchId);
                watchId = null;
            }
            
            fetch('../Global/gps_shift_start.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'end_shift',
                    driver_id: currentDriverId
                })
            })
            .then(response => response.json())
            .then(data => {
                console.log('Shift ended:', data);
            })
            .catch(error => {
                console.error('Error ending shift:', error);
            });
            
            trackingActive = false;
            updateCount = 0;
            retryCount = 0;
            
            let btn = document.getElementById('trackingBtn');
            btn.innerHTML = '<i class="bi bi-play-circle"></i> Start Tracking';
            btn.classList.remove('btn-danger');
            btn.classList.add('btn-success');
            
            updateUI('offline', 'Offline');
        }

        function sendLocation(coords) {
            let data = {
                action: 'update_location',
                driver_id: currentDriverId,
                latitude: coords.latitude,
                longitude: coords.longitude,
                accuracy: coords.accuracy,
                speed: coords.speed || 0,
                heading: coords.heading || 0,
                timestamp: new Date().toISOString()
            };

            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), 5000);

            fetch('../Global/gps_shift_start.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(data),
                signal: controller.signal
            })
            .then(response => {
                clearTimeout(timeoutId);
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }
                return response.json();
            })
            .then(result => {
                if (!result.success) {
                    console.log('Location update failed:', result.error);
                }
            })
            .catch(error => {
                if (error.name === 'AbortError') {
                    console.log('Location update timeout');
                } else {
                    console.log('Location update error:', error.message);
                }
            });
        }

        // ================= LIVE NAVIGATION FUNCTIONS =================
        function openLiveNavigation(destLat, destLng, customerName, address) {
            // Check if browser supports geolocation
            if (!navigator.geolocation) {
                Swal.fire('Error', 'Geolocation is not supported by your browser.', 'error');
                return;
            }

            // Store destination
            destinationPosition = { lat: parseFloat(destLat), lng: parseFloat(destLng) };

            // Show modal
            const modal = new bootstrap.Modal(document.getElementById('trackingModal'));
            modal.show();

            // Update destination info immediately
            document.getElementById('destinationText').textContent = customerName || 'Customer Location';
            document.getElementById('destinationCoordinates').textContent = 
                `${destinationPosition.lat.toFixed(6)}, ${destinationPosition.lng.toFixed(6)}`;

            // Initialize map immediately
            setTimeout(() => {
                initLiveTrackingMap(destinationPosition.lat, destinationPosition.lng, customerName, address);
                // Start GPS acquisition
                startFastGPSAcquisition();
            }, 300);
        }

        function initLiveTrackingMap(destLat, destLng, customerName, address) {
            // Remove existing map if any
            if (liveTrackingMap) {
                liveTrackingMap.remove();
            }

            // Create map with mobile-friendly options
            liveTrackingMap = L.map('trackingMap', {
                zoomControl: false
            }).setView([destLat, destLng], 13);

            // Add zoom control in top-right for better mobile access
            L.control.zoom({
                position: 'topright'
            }).addTo(liveTrackingMap);

            // Add OpenStreetMap tiles
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '© OpenStreetMap contributors',
                maxZoom: 19
            }).addTo(liveTrackingMap);

            // Add destination marker (red) - larger for mobile
            const destIconSize = window.innerWidth <= 768 ? 28 : 20;
            destinationMarker = L.marker([destLat, destLng], {
                icon: L.divIcon({
                    className: 'custom-destination-icon',
                    html: '<div class="custom-destination-icon"></div>',
                    iconSize: [destIconSize, destIconSize],
                    iconAnchor: [destIconSize/2, destIconSize/2]
                })
            }).addTo(liveTrackingMap);
            
            destinationMarker.bindPopup(`
                <b>${customerName || 'Customer'}</b><br>
                ${address || 'Destination'}<br>
                <small>${destLat.toFixed(6)}, ${destLng.toFixed(6)}</small>
            `).openPopup();

            // Add user marker (blue)
            userMarker = L.marker([destLat, destLng], {
                icon: L.divIcon({
                    className: 'custom-user-icon',
                    html: '<div class="custom-user-icon"></div>',
                    iconSize: [destIconSize, destIconSize],
                    iconAnchor: [destIconSize/2, destIconSize/2]
                })
            }).addTo(liveTrackingMap);
            
            userMarker.bindPopup('<b>Your Location</b><br>Waiting for GPS...');

            // Add accuracy circle
            accuracyCircle = L.circle([destLat, destLng], {
                color: '#44D34E',
                fillColor: '#44D34E',
                fillOpacity: 0.1,
                radius: 100,
                weight: 2
            }).addTo(liveTrackingMap);
            
            // On mobile, ensure panel starts expanded and properly positioned
            if (window.innerWidth <= 768) {
                const panel = document.getElementById('navigationStatusPanel');
                if (panel) {
                    panel.classList.remove('collapsed');
                    
                    const toggleBtn = document.getElementById('toggleStatusPanel');
                    if (toggleBtn) {
                        const icon = toggleBtn.querySelector('i');
                        icon.classList.remove('bi-chevron-down');
                        icon.classList.add('bi-chevron-up');
                    }
                    
                    panel.style.top = 'auto';
                    panel.style.bottom = '0';
                    panel.style.left = '0';
                    panel.style.right = '0';
                    panel.style.width = '100%';
                    panel.style.maxHeight = '60vh';
                }
            }
            
            // Force map resize after panel is positioned
            setTimeout(() => {
                if (liveTrackingMap) {
                    liveTrackingMap.invalidateSize();
                }
            }, 300);
        }

        // Fast GPS acquisition
        function startFastGPSAcquisition() {
            // Use cached position if fresh (<= 30 seconds)
            if (lastKnownPosition && (Date.now() - lastKnownPosition.timestamp < 30000)) {
                console.log('Using cached position');
                livePositionSuccess({
                    coords: {
                        latitude: lastKnownPosition.lat,
                        longitude: lastKnownPosition.lng,
                        accuracy: lastKnownPosition.accuracy || 50
                    }
                });
            }

            // Try to get fast low-accuracy fix
            navigator.geolocation.getCurrentPosition(
                function(position) {
                    // Save to cache
                    lastKnownPosition = {
                        lat: position.coords.latitude,
                        lng: position.coords.longitude,
                        accuracy: position.coords.accuracy,
                        timestamp: Date.now()
                    };
                    livePositionSuccess(position);
                    // Start high accuracy watching
                    startLiveWatching();
                },
                function(error) {
                    console.log('Fast GPS error:', error);
                    // If no fix, try high accuracy watch
                    startLiveWatching();
                },
                {
                    enableHighAccuracy: false,
                    timeout: 5000,
                    maximumAge: 30000
                }
            );
        }

        function startLiveWatching() {
            // Clear any existing watch
            if (watchPositionId) {
                navigator.geolocation.clearWatch(watchPositionId);
            }
            
            // Options for high accuracy (continuous tracking)
            const options = {
                enableHighAccuracy: true,
                timeout: 10000,
                maximumAge: 5000
            };

            watchPositionId = navigator.geolocation.watchPosition(
                livePositionSuccess,
                livePositionError,
                options
            );
        }

        function livePositionSuccess(position) {
            const lat = position.coords.latitude;
            const lng = position.coords.longitude;
            const accuracy = position.coords.accuracy;

            currentPosition = { lat, lng, accuracy };

            // Update cache
            lastKnownPosition = {
                lat: lat,
                lng: lng,
                accuracy: accuracy,
                timestamp: Date.now()
            };

            // Update user marker position
            if (userMarker) {
                userMarker.setLatLng([lat, lng]);
                
                if (window.innerWidth <= 768) {
                    userMarker.setPopupContent(`
                        <b>You</b><br>
                        <small>${accuracy.toFixed(0)}m accuracy</small>
                    `);
                } else {
                    userMarker.setPopupContent(`
                        <b>Your Location</b><br>
                        <small>${lat.toFixed(6)}, ${lng.toFixed(6)}</small><br>
                        <small>Accuracy: ${accuracy.toFixed(1)}m</small>
                    `);
                }
            }

            // Update accuracy circle
            if (accuracyCircle) {
                accuracyCircle.setLatLng([lat, lng]);
                accuracyCircle.setRadius(accuracy);
            }

            // Update UI
            const isMobile = window.innerWidth <= 768;
            const locationText = isMobile ? '📍 You' : 'Your Location:';
            document.getElementById('yourLocationText').innerHTML = `<i class="bi bi-check-circle-fill text-success me-1"></i> ${locationText}`;
            document.getElementById('yourCoordinates').innerHTML = isMobile ? 
                `${lat.toFixed(5)}, ${lng.toFixed(5)}` : 
                `${lat.toFixed(6)}, ${lng.toFixed(6)}`;

            // Update or create route
            if (destinationPosition) {
                if (!routingControl) {
                    // Create route for the first time
                    routingControl = L.Routing.control({
                        waypoints: [
                            L.latLng(lat, lng),
                            L.latLng(destinationPosition.lat, destinationPosition.lng)
                        ],
                        routeWhileDragging: false,
                        showAlternatives: false,
                        fitSelectedRoutes: false,
                        lineOptions: {
                            styles: [{ color: '#44D34E', opacity: 0.8, weight: 5 }]
                        },
                        createMarker: function() { return null; }
                    }).addTo(liveTrackingMap);

                    // Listen for route calculation
                    routingControl.on('routesfound', function(e) {
                        const routes = e.routes;
                        const summary = routes[0].summary;

                        // Update distance and time
                        const distance = (summary.totalDistance / 1000).toFixed(2);
                        const time = Math.round(summary.totalTime / 60);

                        document.getElementById('distanceText').textContent = distance;
                        document.getElementById('timeText').textContent = time;
                    });
                } else {
                    // Update waypoints for existing route
                    routingControl.setWaypoints([
                        L.latLng(lat, lng),
                        L.latLng(destinationPosition.lat, destinationPosition.lng)
                    ]);
                }
            }

            // Update accuracy bar and text
            const accuracyPercent = Math.max(0, Math.min(100, 100 - (accuracy / 10)));
            document.getElementById('accuracyBar').style.width = accuracyPercent + '%';

            let accuracyClass = 'bg-success';
            let accuracyText = 'Excellent';
            
            if (accuracy < 10) {
                accuracyClass = 'bg-success';
                accuracyText = 'Excellent';
            } else if (accuracy < 30) {
                accuracyClass = 'bg-info';
                accuracyText = 'Good';
            } else if (accuracy < 100) {
                accuracyClass = 'bg-warning';
                accuracyText = 'Fair';
            } else {
                accuracyClass = 'bg-danger';
                accuracyText = 'Poor';
            }
            
            document.getElementById('accuracyBar').className = 'progress-bar ' + accuracyClass;
            document.getElementById('accuracyText').innerHTML = `<i class="bi bi-${accuracyClass === 'bg-success' ? 'check-circle' : accuracyClass === 'bg-info' ? 'info-circle' : accuracyClass === 'bg-warning' ? 'exclamation-triangle' : 'x-circle'} me-1"></i> ${accuracyText}`;
        }

        function livePositionError(error) {
            // Update UI with retry option
            document.getElementById('yourLocationText').innerHTML = '<i class="bi bi-hourglass-split me-1"></i> Acquiring GPS...';
            document.getElementById('yourCoordinates').innerHTML = '--';
            document.getElementById('accuracyBar').className = 'progress-bar bg-warning';
            document.getElementById('accuracyText').innerHTML = '<i class="bi bi-hourglass-split me-1"></i> Acquiring GPS signal...';
        }

        function retryGPSTracking() {
            if (watchPositionId) {
                navigator.geolocation.clearWatch(watchPositionId);
            }
            
            document.getElementById('yourLocationText').innerHTML = '<i class="bi bi-arrow-repeat me-1"></i> Retrying GPS...';
            document.getElementById('yourCoordinates').innerHTML = '--';
            document.getElementById('accuracyBar').className = 'progress-bar bg-info';
            document.getElementById('accuracyText').innerHTML = '<i class="bi bi-arrow-repeat me-1"></i> Retrying...';
            
            // Restart fast acquisition
            startFastGPSAcquisition();
        }

        function stopLiveNavigation() {
            // Check for active deliveries before stopping navigation
            const rows = document.querySelectorAll('tbody tr');
            let hasActiveDelivery = false;
            rows.forEach(row => {
                const statusCell = row.cells[5];
                if (statusCell) {
                    const badge = statusCell.querySelector('.badge');
                    if (badge) {
                        const badgeClass = badge.className;
                        if (badgeClass.includes('bg-primary') || badgeClass.includes('bg-info')) {
                            hasActiveDelivery = true;
                        }
                    }
                }
            });

            if (hasActiveDelivery) {
                Swal.fire({
                    icon: 'error',
                    title: 'Cannot Stop Navigation',
                    text: 'You have active deliveries. Please complete them before stopping navigation.',
                    confirmButtonColor: '#28a745'
                });
                return;
            }

            // Clear watch position
            if (watchPositionId) {
                navigator.geolocation.clearWatch(watchPositionId);
                watchPositionId = null;
            }

            // Clear any pending retry timeout
            if (gpsRetryTimeout) {
                clearTimeout(gpsRetryTimeout);
                gpsRetryTimeout = null;
            }

            // Remove routing control
            if (routingControl && liveTrackingMap) {
                liveTrackingMap.removeControl(routingControl);
                routingControl = null;
            }

            // Close modal
            const modal = bootstrap.Modal.getInstance(document.getElementById('trackingModal'));
            if (modal) {
                modal.hide();
            }
        }

        function confirmStopLiveNavigation() {
            Swal.fire({
                title: 'Stop Navigation?',
                text: 'Are you sure you want to stop navigating?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, Stop'
            }).then((result) => {
                if (result.isConfirmed) {
                    stopLiveNavigation();
                }
            });
        }

        function centerOnYourLocation() {
            if (currentPosition && liveTrackingMap) {
                liveTrackingMap.setView([currentPosition.lat, currentPosition.lng], 16);
            } else {
                retryGPSTracking();
            }
        }

        function openGoogleMaps() {
            if (currentPosition && destinationPosition) {
                const url = `https://www.google.com/maps/dir/${currentPosition.lat},${currentPosition.lng}/${destinationPosition.lat},${destinationPosition.lng}`;
                window.open(url, '_blank');
            } else if (destinationPosition) {
                const url = `https://www.google.com/maps?q=${destinationPosition.lat},${destinationPosition.lng}`;
                window.open(url, '_blank');
            }
        }

        // ================= TOUCH-FRIENDLY PANEL TOGGLE FOR MOBILE =================
        function initMobilePanelToggle() {
            const panel = document.getElementById('navigationStatusPanel');
            const header = panel ? panel.querySelector('h6') : null;
            
            if (panel && header && window.innerWidth <= 768) {
                header.removeEventListener('click', handlePanelHeaderClick);
                header.addEventListener('click', handlePanelHeaderClick);
            }
        }

        function handlePanelHeaderClick(e) {
            if (window.innerWidth <= 768 && !e.target.closest('.toggle-panel-btn')) {
                const toggleBtn = document.getElementById('toggleStatusPanel');
                if (toggleBtn) {
                    toggleBtn.click();
                }
            }
        }

        // ================= DELIVERY FUNCTIONS WITH GPS VALIDATION =================

        function updateDeliveryStatus(deliveryId, newStatus) {
            if (newStatus === 'in-transit') {
                // Check if GPS tracking is active
                if (!trackingActive) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'GPS Tracking Required',
                        text: 'Please start GPS tracking before starting delivery.',
                        confirmButtonColor: '#28a745',
                        confirmButtonText: 'Start Tracking Now'
                    }).then((result) => {
                        if (result.isConfirmed) {
                            toggleTracking();
                        }
                    });
                    return;
                }
                
                Swal.fire({
                    title: 'Start Delivery?',
                    text: 'Make sure you have loaded all items for this delivery.',
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonColor: '#28a745',
                    cancelButtonColor: '#6c757d',
                    confirmButtonText: 'Yes, Start Delivery'
                }).then((result) => {
                    if (result.isConfirmed) {
                        const formData = new FormData();
                        formData.append('delivery_id', deliveryId);
                        formData.append('status', newStatus);
                        formData.append('branch_id', branchId);
                        
                        Swal.fire({
                            title: 'Starting Delivery...',
                            text: 'Please wait',
                            allowOutsideClick: false,
                            didOpen: () => {
                                Swal.showLoading();
                            }
                        });
                        
                        fetch('update_delivery_status.php', {
                            method: 'POST',
                            body: formData
                        })
                        .then(response => response.json())
                        .then(data => {
                            Swal.close();
                            
                            if (data.success) {
                                Swal.fire({
                                    icon: 'success',
                                    title: 'Delivery Started!',
                                    text: 'You are now on your way.',
                                    timer: 1500,
                                    showConfirmButton: false
                                }).then(() => {
                                    location.reload();
                                });
                            } else {
                                Swal.fire('Error', data.message || 'Failed to start delivery', 'error');
                            }
                        })
                        .catch(error => {
                            Swal.close();
                            console.error('Error:', error);
                            Swal.fire('Error', 'Error updating status', 'error');
                        });
                    }
                });
            } else if (newStatus === 'partial') {
                // Check if GPS tracking is active
                if (!trackingActive) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'GPS Tracking Required',
                        text: 'Please enable GPS tracking to record partial delivery.',
                        confirmButtonColor: '#28a745',
                        confirmButtonText: 'Start Tracking Now'
                    }).then((result) => {
                        if (result.isConfirmed) {
                            toggleTracking();
                        }
                    });
                    return;
                }
                
                // Load items for partial delivery
                loadItemsForPartial(deliveryId);
            }
        }

        function showDeliveryModal(deliveryId, soId, orderNumber) {
            // Check if GPS tracking is active
            if (!trackingActive) {
                Swal.fire({
                    icon: 'warning',
                    title: 'GPS Tracking Required',
                    text: 'Please keep GPS tracking active to complete delivery.',
                    confirmButtonColor: '#28a745',
                    confirmButtonText: 'Start Tracking Now'
                }).then((result) => {
                    if (result.isConfirmed) {
                        toggleTracking();
                    }
                });
                return;
            }
            
            currentDeliveryId = deliveryId;
            currentSoId = soId;
            currentOrderNumber = orderNumber;
            
            document.getElementById('orderIdDisplay').textContent = orderNumber;
            document.getElementById('modalDeliveryId').value = deliveryId;
            document.getElementById('modalSoId').value = soId;
            document.getElementById('modalSoNumber').value = orderNumber;
            document.getElementById('deliveryForm').reset();
            
            const now = new Date();
            const year = now.getFullYear();
            const month = String(now.getMonth() + 1).padStart(2, '0');
            const day = String(now.getDate()).padStart(2, '0');
            const hours = String(now.getHours()).padStart(2, '0');
            const minutes = String(now.getMinutes()).padStart(2, '0');
            document.querySelector('input[name="delivery_date"]').value = `${year}-${month}-${day}T${hours}:${minutes}`;
            
            const modal = new bootstrap.Modal(document.getElementById('deliveryModal'));
            modal.show();
        }

        function loadItemsForPartial(deliveryId) {
            currentPartialDeliveryId = deliveryId;
            
            fetch('get_delivery_items.php?delivery_id=' + deliveryId)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        currentItems = data.items;
                        displayItemsForPartial();
                        
                        const modal = new bootstrap.Modal(document.getElementById('partialModal'));
                        modal.show();
                    } else {
                        Swal.fire('Error', 'Error loading items: ' + data.message, 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    Swal.fire('Error', 'Error loading items', 'error');
                });
        }

        function displayItemsForPartial() {
            const itemsDiv = document.getElementById('itemsList');
            let html = '';
            
            currentItems.forEach((item, index) => {
                html += `
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" value="${item.item_id}" id="item_${index}" checked>
                        <label class="form-check-label" for="item_${index}">
                            ${item.item_name} - Qty: ${item.quantity} - ₱${item.price}
                        </label>
                    </div>
                `;
            });
            
            itemsDiv.innerHTML = html;
        }

        function submitPartialDelivery() {
            // Check if GPS tracking is still active
            if (!trackingActive) {
                Swal.fire({
                    icon: 'error',
                    title: 'GPS Tracking Stopped',
                    text: 'GPS tracking was turned off. Please restart tracking to record partial delivery.',
                    confirmButtonColor: '#28a745',
                    confirmButtonText: 'Restart Tracking'
                }).then((result) => {
                    if (result.isConfirmed) {
                        toggleTracking();
                    }
                });
                return;
            }
            
            const reason = document.getElementById('partialReason').value;
            let details = document.getElementById('partialDetails').value;
            
            if (!reason) {
                Swal.fire('Warning', 'Please select a reason', 'warning');
                return;
            }
            
            let finalReason = reason;
            if (reason === 'Other') {
                const otherReason = document.getElementById('otherReason').value;
                if (!otherReason) {
                    Swal.fire('Warning', 'Please specify the reason', 'warning');
                    return;
                }
                finalReason = otherReason;
            }
            
            // Get selected items
            const checkboxes = document.querySelectorAll('#itemsList input[type="checkbox"]:checked');
            if (checkboxes.length === 0) {
                Swal.fire('Warning', 'Please select at least one item that was delivered', 'warning');
                return;
            }
            
            const deliveredItems = Array.from(checkboxes).map(cb => cb.value);
            
            if (details) {
                finalReason += ' - ' + details;
            }
            
            finalReason += ` [Delivered items: ${checkboxes.length} of ${currentItems.length}]`;
            
            Swal.fire({
                title: 'Submitting Partial Delivery...',
                text: 'Please wait',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });
            
            const formData = new FormData();
            formData.append('delivery_id', currentPartialDeliveryId);
            formData.append('status', 'partial');
            formData.append('remarks', finalReason);
            formData.append('branch_id', branchId);
            
            fetch('update_delivery_status.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                Swal.close();
                
                if (data.success) {
                    const modal = bootstrap.Modal.getInstance(document.getElementById('partialModal'));
                    modal.hide();
                    
                    Swal.fire({
                        icon: 'success',
                        title: 'Partial Delivery Recorded',
                        text: 'You can complete the remaining items later.',
                        timer: 2000,
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
                Swal.fire('Error', 'Error updating status', 'error');
            });
        }

        function viewDeliveryDetails(deliveryId) {
            const modalBody = document.getElementById('viewDetailsModalBody');
            modalBody.innerHTML = `
                <div class="text-center py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-3">Loading delivery details...</p>
                </div>
            `;
            
            const modal = new bootstrap.Modal(document.getElementById('viewDetailsModal'));
            modal.show();
            
            fetch('get_delivery_details.php?delivery_id=' + deliveryId)
                .then(response => response.text())
                .then(data => {
                    modalBody.innerHTML = data;
                    
                    document.querySelectorAll('.view-photo-btn').forEach(btn => {
                        btn.addEventListener('click', function(e) {
                            e.preventDefault();
                            const photoUrl = this.getAttribute('data-photo');
                            showPhotoModal(photoUrl);
                        });
                    });
                })
                .catch(error => {
                    console.error('Error:', error);
                    modalBody.innerHTML = `
                        <div class="alert alert-danger m-3">
                            <i class="bi bi-exclamation-triangle"></i>
                            Error loading delivery details. Please try again.
                        </div>
                    `;
                });
        }

        function showPhotoModal(photoUrl) {
            const modalImg = document.getElementById('photoModalImg');
            const downloadBtn = document.getElementById('downloadPhotoBtn');
            
            modalImg.src = photoUrl;
            downloadBtn.href = photoUrl;
            
            const modal = new bootstrap.Modal(document.getElementById('photoModal'));
            modal.show();
        }

        function generateThermalReceipt(deliveryId, soNumber, customerName, address, signedBy, deliveryDate, itemsRaw) {
            const date = new Date(deliveryDate);
            const formattedDate = date.toLocaleString('en-PH', {
                year: 'numeric',
                month: '2-digit',
                day: '2-digit',
                hour: '2-digit',
                minute: '2-digit',
                hour12: true
            });
            
            const items = itemsRaw ? itemsRaw.split('||') : [];
            
            const today = new Date();
            const receiptDate = today.toLocaleDateString('en-PH', {
                year: 'numeric',
                month: '2-digit',
                day: '2-digit'
            });
            const receiptTime = today.toLocaleTimeString('en-PH', {
                hour: '2-digit',
                minute: '2-digit',
                hour12: true
            });
            
            const receiptNumber = 'DR' + today.getFullYear() + 
                                 String(today.getMonth() + 1).padStart(2, '0') + 
                                 String(today.getDate()).padStart(2, '0') + 
                                 String(deliveryId).padStart(4, '0');
            
            let itemsHtml = '';
            let total = 0;
            
            if (items.length === 0) {
                itemsHtml = '<tr><td colspan="4" style="text-align: center; padding: 8px;">No items</td></tr>';
            } else {
                items.forEach(item => {
                    const parts = item.split(' x ');
                    if (parts.length === 2) {
                        const qtyPrice = parts[1].split(' - ₱');
                        if (qtyPrice.length === 2) {
                            const qty = parseInt(parts[0]);
                            const itemName = qtyPrice[0];
                            const price = parseFloat(qtyPrice[1]);
                            const subtotal = qty * price;
                            total += subtotal;
                            
                            itemsHtml += `
                                <tr>
                                    <td class="item-name">${itemName}</td>
                                    <td class="text-center">${qty}</td>
                                    <td class="text-right">₱${price.toFixed(2)}</td>
                                    <td class="text-right">₱${subtotal.toFixed(2)}</td>
                                </tr>
                            `;
                        }
                    }
                });
            }
            
            return `
                <div class="thermal-receipt">
                    <div class="receipt-header">
                        <div class="company-name">AMGC</div>
                        <div class="receipt-title">DELIVERY RECEIPT</div>
                        <div class="receipt-no">#${receiptNumber}</div>
                        <div>${receiptDate} ${receiptTime}</div>
                    </div>
                    
                    <div class="receipt-info">
                        <div class="info-line"><span class="info-label">Order:</span><span class="info-value">${soNumber}</span></div>
                        <div class="info-line"><span class="info-label">Customer:</span><span class="info-value">${customerName}</span></div>
                        <div class="info-line"><span class="info-label">Address:</span><span class="info-value">${address}</span></div>
                        <div class="info-line"><span class="info-label">Received:</span><span class="info-value">${signedBy}</span></div>
                        <div class="info-line"><span class="info-label">Date:</span><span class="info-value">${formattedDate}</span></div>
                    </div>
                    
                    <table class="items-table">
                        <thead>
                            <tr>
                                <th>Item</th>
                                <th class="text-center">Qty</th>
                                <th class="text-right">Price</th>
                                <th class="text-right">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${itemsHtml}
                        </tbody>
                    </table>
                    
                    <div class="receipt-total">
                        TOTAL: ₱${total.toFixed(2)}
                    </div>
                    
                    <div class="receipt-footer">
                        *** Thank you! ***
                    </div>
                </div>
            `;
        }

        function showReceiptModal(deliveryId, soNumber, customerName, address, signedBy, deliveryDate, itemsRaw) {
            const receiptContent = document.getElementById('receiptContent');
            
            currentThermalReceipt = generateThermalReceipt(deliveryId, soNumber, customerName, address, signedBy, deliveryDate, itemsRaw);
            receiptContent.innerHTML = currentThermalReceipt;
            
            const modal = new bootstrap.Modal(document.getElementById('receiptModal'));
            modal.show();
        }

        function printThermalReceipt() {
            const thermalDiv = document.getElementById('thermalReceipt');
            thermalDiv.style.display = 'block';
            thermalDiv.innerHTML = currentThermalReceipt;
            
            window.print();
            
            setTimeout(() => {
                thermalDiv.style.display = 'none';
            }, 100);
        }

        function showLocation(lat, lng, customerName, address) {
            currentLat = parseFloat(lat);
            currentLng = parseFloat(lng);
            currentCustomerName = customerName;
            currentAddress = address;
            
            document.getElementById('modalCustomerName').textContent = customerName;
            document.getElementById('modalCustomerAddress').textContent = address;
            document.getElementById('modalLat').textContent = currentLat.toFixed(6);
            document.getElementById('modalLng').textContent = currentLng.toFixed(6);
            
            const modal = new bootstrap.Modal(document.getElementById('locationMapModal'));
            modal.show();
            
            setTimeout(() => {
                initMap(currentLat, currentLng, customerName);
            }, 500);
        }

        function initMap(lat, lng, customerName) {
            const mapElement = document.getElementById('customerLocationMap');
            
            if (map) {
                map.remove();
                map = null;
            }
            
            if (!lat || !lng || isNaN(lat) || isNaN(lng)) {
                mapElement.innerHTML = '<div class="alert alert-danger p-2">Invalid coordinates</div>';
                return;
            }
            
            try {
                map = L.map('customerLocationMap').setView([lat, lng], 15);
                
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    attribution: '© OpenStreetMap'
                }).addTo(map);
                
                marker = L.marker([lat, lng]).addTo(map);
                marker.bindPopup(`<b>${customerName}</b><br>Delivery Location`).openPopup();
                
            } catch (error) {
                console.error('Map error:', error);
                mapElement.innerHTML = '<div class="alert alert-danger p-2">Map unavailable</div>';
            }
        }

        function openInGoogleMaps() {
            if (currentLat && currentLng) {
                const url = `https://www.google.com/maps/search/?api=1&query=${currentLat},${currentLng}`;
                window.open(url, '_blank');
            }
        }

        // Handle reason change in partial modal
        document.addEventListener('change', function(e) {
            if (e.target && e.target.id === 'partialReason') {
                const otherDiv = document.getElementById('otherReasonDiv');
                if (e.target.value === 'Other') {
                    otherDiv.style.display = 'block';
                    document.getElementById('otherReason').required = true;
                } else {
                    otherDiv.style.display = 'none';
                    document.getElementById('otherReason').required = false;
                }
            }
        });

        function copySQL(table) {
            let sql = '';
            if (table === 'deliveries') {
                sql = "ALTER TABLE deliveries ADD COLUMN branch_id INT NULL;\nALTER TABLE deliveries ADD FOREIGN KEY (branch_id) REFERENCES branches(branch_id);";
            } else if (table === 'deliveries_driver') {
                sql = "ALTER TABLE deliveries ADD COLUMN driver_id INT NULL;\nALTER TABLE deliveries ADD FOREIGN KEY (driver_id) REFERENCES drivers(driver_id);";
            }
            
            navigator.clipboard.writeText(sql).then(() => {
                alert('SQL copied to clipboard!');
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
                    localStorage.removeItem('sidebarCollapsed');
                    window.location.href = '../logout.php';
                }
            });
        }

        // Search functionality
        const searchInput = document.getElementById('searchInput');
        if (searchInput) {
            searchInput.addEventListener('keyup', function() {
                const filter = this.value.toLowerCase();
                const rows = document.querySelectorAll('tbody tr');
                
                rows.forEach(row => {
                    const text = row.textContent.toLowerCase();
                    row.style.display = text.includes(filter) ? '' : 'none';
                });
            });
        }

        // Status filter
        const statusFilter = document.getElementById('statusFilter');
        if (statusFilter) {
            statusFilter.addEventListener('change', function() {
                const filter = this.value.toLowerCase();
                const rows = document.querySelectorAll('tbody tr');
                
                rows.forEach(row => {
                    const statusCell = row.cells[5];
                    if (statusCell) {
                        const status = statusCell.textContent.toLowerCase().trim();
                        row.style.display = (filter === '' || status.includes(filter)) ? '' : 'none';
                    }
                });
            });
        }

        // ================= IMPROVED COLLAPSIBLE PANEL & DRAGGABLE FOR MOBILE =================
        function initCollapsiblePanel() {
            const panel = document.getElementById('navigationStatusPanel');
            const toggleBtn = document.getElementById('toggleStatusPanel');
            if (!panel || !toggleBtn) return;

            let isCollapsed = false;

            toggleBtn.addEventListener('mousedown', function(e) {
                e.stopPropagation();
            });
            toggleBtn.addEventListener('touchstart', function(e) {
                e.stopPropagation();
            });

            toggleBtn.addEventListener('click', function() {
                isCollapsed = !isCollapsed;
                panel.classList.toggle('collapsed', isCollapsed);
                const icon = toggleBtn.querySelector('i');
                if (isCollapsed) {
                    icon.classList.remove('bi-chevron-up');
                    icon.classList.add('bi-chevron-down');
                    
                    if (window.innerWidth <= 768) {
                        panel.style.maxHeight = '60px';
                    }
                } else {
                    icon.classList.remove('bi-chevron-down');
                    icon.classList.add('bi-chevron-up');
                    
                    if (window.innerWidth <= 768) {
                        panel.style.maxHeight = '60vh';
                    }
                }
                
                if (liveTrackingMap) {
                    setTimeout(() => liveTrackingMap.invalidateSize(), 200);
                }
            });
            
            const header = panel.querySelector('h6');
            if (header) {
                header.addEventListener('click', function(e) {
                    if (window.innerWidth <= 768 && !e.target.closest('.toggle-panel-btn')) {
                        toggleBtn.click();
                    }
                });
            }
        }

        function makePanelDraggable() {
            const panel = document.getElementById('navigationStatusPanel');
            if (!panel) return;

            const handle = panel.querySelector('h6');
            const container = panel.parentElement;

            function resetPosition() {
                const containerRect = container.getBoundingClientRect();
                const panelWidth = panel.offsetWidth;
                const isMobile = window.innerWidth <= 768;

                if (isMobile) {
                    panel.style.left = '0';
                    panel.style.top = 'auto';
                    panel.style.bottom = '0';
                    panel.style.right = '0';
                    panel.style.width = '100%';
                    panel.style.maxWidth = '100%';
                    panel.style.borderRadius = '20px 20px 0 0';
                    panel.style.transform = 'none';
                    
                    if (panel.classList.contains('collapsed')) {
                        panel.style.maxHeight = '60px';
                    } else {
                        panel.style.maxHeight = '60vh';
                        panel.style.overflowY = 'auto';
                    }
                } else {
                    panel.style.width = '320px';
                    panel.style.maxWidth = '320px';
                    panel.style.left = (containerRect.width - panelWidth - 20) + 'px';
                    panel.style.top = '20px';
                    panel.style.bottom = 'auto';
                    panel.style.right = 'auto';
                    panel.style.borderRadius = '12px';
                    panel.style.maxHeight = 'calc(100% - 40px)';
                    panel.style.overflowY = 'auto';
                    panel.style.transform = 'none';
                }
            }

            const trackingModal = document.getElementById('trackingModal');
            trackingModal.addEventListener('shown.bs.modal', resetPosition);
            
            window.addEventListener('resize', resetPosition);

            function enableDragging() {
                if (window.innerWidth <= 768) return;

                let isDragging = false;
                let startX, startY, startLeft, startTop;

                function startDrag(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    isDragging = true;

                    const panelRect = panel.getBoundingClientRect();
                    startLeft = panelRect.left;
                    startTop = panelRect.top;

                    if (e.type === 'mousedown') {
                        startX = e.clientX;
                        startY = e.clientY;
                    } else {
                        startX = e.touches[0].clientX;
                        startY = e.touches[0].clientY;
                    }

                    document.addEventListener('mousemove', drag);
                    document.addEventListener('mouseup', stopDrag);
                    document.addEventListener('touchmove', drag, { passive: false });
                    document.addEventListener('touchend', stopDrag);
                    document.addEventListener('touchcancel', stopDrag);
                }

                function drag(e) {
                    if (!isDragging) return;
                    e.preventDefault();

                    let clientX, clientY;
                    if (e.type === 'mousemove') {
                        clientX = e.clientX;
                        clientY = e.clientY;
                    } else {
                        clientX = e.touches[0].clientX;
                        clientY = e.touches[0].clientY;
                    }

                    const dx = clientX - startX;
                    const dy = clientY - startY;
                    let newLeft = startLeft + dx;
                    let newTop = startTop + dy;

                    const containerRect = container.getBoundingClientRect();
                    const panelWidth = panel.offsetWidth;
                    const panelHeight = panel.offsetHeight;

                    const minLeft = containerRect.left;
                    const maxLeft = containerRect.right - panelWidth;
                    const minTop = containerRect.top;
                    const maxTop = containerRect.bottom - panelHeight;

                    newLeft = Math.max(minLeft, Math.min(newLeft, maxLeft));
                    newTop = Math.max(minTop, Math.min(newTop, maxTop));

                    const relativeLeft = newLeft - containerRect.left;
                    const relativeTop = newTop - containerRect.top;

                    panel.style.left = relativeLeft + 'px';
                    panel.style.top = relativeTop + 'px';
                    panel.style.right = 'auto';
                    panel.style.bottom = 'auto';
                }

                function stopDrag() {
                    isDragging = false;
                    document.removeEventListener('mousemove', drag);
                    document.removeEventListener('mouseup', stopDrag);
                    document.removeEventListener('touchmove', drag);
                    document.removeEventListener('touchend', stopDrag);
                    document.removeEventListener('touchcancel', stopDrag);
                }

                handle.addEventListener('mousedown', startDrag);
                handle.addEventListener('touchstart', startDrag, { passive: false });
            }

            enableDragging();

            window.addEventListener('resize', function() {
                resetPosition();
                if (window.innerWidth <= 768) {
                    handle.removeEventListener('mousedown', enableDragging);
                    handle.removeEventListener('touchstart', enableDragging);
                } else {
                    enableDragging();
                }
            });
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
                initMobilePanelToggle();
                
                if (liveTrackingMap) {
                    setTimeout(() => liveTrackingMap.invalidateSize(), 200);
                }
            });
            
            const locationModal = document.getElementById('locationMapModal');
            if (locationModal) {
                locationModal.addEventListener('hidden.bs.modal', function () {
                    if (map) {
                        map.remove();
                        map = null;
                    }
                });
            }

            const trackingModal = document.getElementById('trackingModal');
            if (trackingModal) {
                trackingModal.addEventListener('hidden.bs.modal', function () {
                    if (watchPositionId) {
                        navigator.geolocation.clearWatch(watchPositionId);
                        watchPositionId = null;
                    }
                    
                    if (liveTrackingMap) {
                        liveTrackingMap.remove();
                        liveTrackingMap = null;
                    }
                    userMarker = null;
                    destinationMarker = null;
                    routingControl = null;
                    accuracyCircle = null;
                    currentPosition = null;
                });
            }

            // Auto-start for delivery drivers
            <?php if ($user_role == 'delivery' && $driver_id > 0): ?>
            fetch('../Global/gps_shift_start.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'get_shift_status',
                    driver_id: <?php echo $driver_id; ?>
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.has_active_shift) {
                    console.log('May active shift:', data);
                    startGPSTracking();
                } else {
                    console.log('Walang active shift, mag-start ng bago');
                    setTimeout(function() {
                        toggleTracking();
                    }, 2000);
                }
            });
            <?php endif; ?>

            if (document.getElementById('trackingModal')) {
                initCollapsiblePanel();
                makePanelDraggable();
                initMobilePanelToggle();
            }
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
            }
            else if (e.ctrlKey && e.key === 'f' && !e.target.matches('input, textarea')) {
                e.preventDefault();
                const searchInput = document.getElementById('searchInput');
                if (searchInput) {
                    searchInput.focus();
                }
            }
        });
       
       // ================= FOR DELIVERY FILTER FUNCTIONS =================

        // Toggle filter visibility
        function toggleDeliveryFilter() {
            const content = document.getElementById('salesFilterContent');
            const icon = document.getElementById('salesFilterIcon');
            const toggleBtn = document.getElementById('salesFilterToggle');
            
            if (content && icon && toggleBtn) {
                const isExpanded = toggleBtn.getAttribute('aria-expanded') === 'true';
                
                if (isExpanded) {
                    content.classList.add('collapsed');
                    toggleBtn.setAttribute('aria-expanded', 'false');
                    icon.style.transform = 'rotate(0deg)';
                    localStorage.setItem('deliveryFilterHidden', 'true');
                } else {
                    content.classList.remove('collapsed');
                    toggleBtn.setAttribute('aria-expanded', 'true');
                    icon.style.transform = 'rotate(180deg)';
                    localStorage.setItem('deliveryFilterHidden', 'false');
                }
            }
        }

        // Apply filters
        function applyDeliveryFilters() {
            const search = document.getElementById('searchInput')?.value?.toLowerCase() || '';
            const status = document.getElementById('statusFilter')?.value?.toLowerCase() || '';
            
            const rows = document.querySelectorAll('tbody tr');
            let visibleCount = 0;
            
            rows.forEach(row => {
                const orderId = row.cells[0]?.textContent?.toLowerCase() || '';
                const customerName = row.cells[1]?.textContent?.toLowerCase() || '';
                const address = row.cells[2]?.textContent?.toLowerCase() || '';
                const contact = row.cells[3]?.textContent?.toLowerCase() || '';
                const items = row.cells[4]?.textContent?.toLowerCase() || '';
                const rowStatus = row.cells[5]?.textContent?.toLowerCase().trim() || '';
                
                const searchableText = orderId + ' ' + customerName + ' ' + address + ' ' + contact + ' ' + items;
                
                const matchesSearch = search === '' || searchableText.includes(search);
                const matchesStatus = status === '' || rowStatus.includes(status);
                
                if (matchesSearch && matchesStatus) {
                    row.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            });
            
            const table = document.querySelector('tbody');
            const noResultsRow = document.getElementById('noResultsRow');
            
            if (visibleCount === 0) {
                if (!noResultsRow) {
                    const newRow = document.createElement('tr');
                    newRow.id = 'noResultsRow';
                    newRow.innerHTML = '<td colspan="7" class="text-center py-4"><i class="bi bi-search"></i> No matching deliveries found</td>';
                    table.appendChild(newRow);
                }
            } else if (noResultsRow) {
                noResultsRow.remove();
            }
        }

        // Clear filters
        function clearDeliveryFilters() {
            document.getElementById('searchInput') && (document.getElementById('searchInput').value = '');
            document.getElementById('statusFilter') && (document.getElementById('statusFilter').value = '');
            applyDeliveryFilters();
        }

        // Initialize filter state - DEFAULT CLOSED
        function initDeliveryFilterState() {
            const content = document.getElementById('salesFilterContent');
            const icon = document.getElementById('salesFilterIcon');
            const toggleBtn = document.getElementById('salesFilterToggle');
            
            if (content && icon && toggleBtn) {
                content.classList.add('collapsed');
                toggleBtn.setAttribute('aria-expanded', 'false');
                icon.style.transform = 'rotate(0deg)';
                localStorage.setItem('deliveryFilterHidden', 'true');
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            initDeliveryFilterState();
            
            document.getElementById('salesFilterToggle')?.addEventListener('click', toggleDeliveryFilter);
            document.getElementById('searchInput')?.addEventListener('input', applyDeliveryFilters);
            document.getElementById('statusFilter')?.addEventListener('change', applyDeliveryFilters);
            
            document.getElementById('searchInput')?.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    applyDeliveryFilters();
                }
            });
        });
    </script>
</body>
</html>