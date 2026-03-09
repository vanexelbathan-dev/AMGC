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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>For Delivery - Delivery Management</title>
    <link rel="icon" type="image/png" href="../Pictures/favicon-96x96.png" sizes="96x96" />
    <link rel="icon" type="image/svg+xml" href="../Pictures/favicon.svg" />
    <link rel="shortcut icon" href="../Pictures/favicon.ico" />
    <link rel="apple-touch-icon" sizes="180x180" href="../Pictures/apple-touch-icon.png" />
    <link rel="manifest" href="../Pictures/site.webmanifest" />
    <link rel="stylesheet" href="../css/delivery.css">
    <link rel="stylesheet" href="../css/fordelivery.css">
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
            height: 95vh; /* Almost full height on all devices */
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
            cursor: move; /* draggable on desktop */
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
        
        /* Mobile adjustments */
        @media (max-width: 768px) {
            #trackingModal .modal-dialog {
                height: 100vh;
                margin: 0;
                max-width: 100%;
            }
            
            #trackingModal .modal-content {
                border-radius: 0;
            }
            
            .status-panel {
                position: absolute;
                top: auto !important;
                bottom: 0 !important;
                left: 0 !important;
                right: 0 !important;
                width: 100%;
                max-width: 100%;
                border-radius: 16px 16px 0 0;
                padding: 16px;
                box-shadow: 0 -4px 20px rgba(0,0,0,0.15);
                max-height: 50%; /* Limit height on mobile */
                overflow-y: auto;
            }
            
            .status-panel.collapsed {
                padding: 10px 16px;
                height: auto;
                min-height: 50px;
            }
            
            .status-panel h6 {
                cursor: default; /* not draggable on mobile */
            }
            
            /* Stack distance/time boxes */
            .status-panel .row.g-2 {
                flex-direction: column;
            }
            .status-panel .col-6 {
                width: 100%;
                max-width: 100%;
                flex: 0 0 100%;
                margin-bottom: 8px;
            }
            
            /* Make buttons stack */
            .status-panel .d-grid.gap-2.mt-3 {
                display: flex;
                flex-direction: column;
            }
            .status-panel .d-grid.gap-2.mt-3 .btn {
                width: 100%;
                margin-bottom: 5px;
            }
            
            /* Smaller text */
            .status-panel .info-label {
                font-size: 0.75rem;
            }
            .status-panel .info-value {
                font-size: 0.85rem;
            }
            .status-panel .coordinates-text {
                font-size: 0.7rem;
            }
            
            /* Adjust distance/time boxes */
            .status-panel .bg-light.p-2.rounded {
                padding: 10px !important;
            }
            .status-panel .info-value[style*="font-size: 1.2rem"] {
                font-size: 1rem !important;
            }
            
            /* Smaller retry button */
            .status-panel .retry-btn {
                padding: 2px 8px;
                font-size: 0.7rem;
            }
        }

        /* Extra small devices */
        @media (max-width: 576px) {
            .status-panel {
                padding: 12px;
            }
            .status-panel h6 {
                font-size: 0.9rem;
            }
        }
        
        .btn-tracking {
            background-color: #28a745;
            color: white;
            border: none;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 0.85rem;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        
        .btn-tracking:hover {
            background-color: #218838;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(40, 167, 69, 0.3);
        }
        
        .btn-tracking i {
            font-size: 1rem;
        }

        /* Navigation Status Bar (main page) */
        .navigation-status-bar {
            background: white;
            border-radius: 12px;
            padding: 12px 16px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            border: 1px solid #e9ecef;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .location-info-group {
            display: flex;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
        }
        
        .location-item {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .location-icon {
            width: 32px;
            height: 32px;
            background: #f8f9fa;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #0d6efd;
        }
        
        .location-details {
            line-height: 1.3;
        }
        
        .location-label {
            font-size: 0.7rem;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .location-address {
            font-weight: 600;
            font-size: 0.9rem;
            color: #212529;
        }
        
        .location-coords {
            font-family: monospace;
            font-size: 0.8rem;
            color: #6c757d;
        }
        
        .distance-time-group {
            display: flex;
            align-items: center;
            gap: 15px;
            background: #f8f9fa;
            padding: 8px 15px;
            border-radius: 30px;
        }
        
        .distance-time-item {
            text-align: center;
            min-width: 70px;
        }
        
        .distance-time-label {
            font-size: 0.7rem;
            color: #6c757d;
            text-transform: uppercase;
        }
        
        .distance-time-value {
            font-weight: 700;
            font-size: 1.1rem;
            color: #212529;
            line-height: 1.2;
        }
        
        .distance-time-unit {
            font-size: 0.7rem;
            color: #6c757d;
            font-weight: normal;
        }
        
        .gps-accuracy-badge {
            display: flex;
            align-items: center;
            gap: 8px;
            background: #f8f9fa;
            padding: 5px 12px;
            border-radius: 30px;
        }
        
        .accuracy-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
        }
        
        .accuracy-dot.high { background-color: #28a745; }
        .accuracy-dot.medium { background-color: #ffc107; }
        .accuracy-dot.low { background-color: #dc3545; }
        
        .nav-actions {
            display: flex;
            gap: 8px;
        }
        
        .nav-action-btn {
            background: white;
            border: 1px solid #dee2e6;
            border-radius: 30px;
            padding: 6px 15px;
            font-size: 0.85rem;
            color: #495057;
            display: flex;
            align-items: center;
            gap: 5px;
            transition: all 0.2s;
        }
        
        .nav-action-btn:hover {
            background: #f8f9fa;
            border-color: #0d6efd;
            color: #0d6efd;
        }
        
        .nav-action-btn i {
            font-size: 1rem;
        }
        
        .retry-btn {
            background: #e7f1ff;
            color: #0d6efd;
            border: none;
            padding: 4px 12px;
            border-radius: 30px;
            font-size: 0.75rem;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .retry-btn:hover {
            background: #0d6efd;
            color: white;
        }
        
        /* General mobile adjustments (for main page) */
        @media (max-width: 768px) {
            .navigation-status-bar {
                flex-direction: column;
                align-items: stretch;
            }
            
            .location-info-group {
                justify-content: space-between;
            }
            
            .distance-time-group {
                justify-content: center;
            }
            
            .nav-actions {
                justify-content: center;
            }
        }
        
        @media (max-width: 576px) {
            .location-map {
                height: 300px;
            }
            .modal-dialog {
                margin: 10px;
            }
            .btn-group {
                display: flex;
                flex-direction: column;
            }
            .btn-group .btn {
                margin-bottom: 2px;
                width: 100%;
            }
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
            
            .location-info-group {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
            
            .location-item {
                width: 100%;
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
                    <div class="user-avatar-sidebar"><?php echo substr($user_name, 0, 2); ?></div>
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
                    <button class="btn btn-success btn-sm" id="trackingBtn">
                        <i class="bi bi-play-circle"></i> Start Tracking
                    </button>
                    <div id="locationIndicator" class="badge bg-secondary" style="padding: 8px 12px;">
                        <span class="tracking-indicator tracking-inactive"></span>
                        <span id="locationStatus">Offline</span>
                        <span id="updateCount" class="update-counter"></span>
                    </div>
                </div>
            </div>

            <!-- Delivery Stats -->
            <div class="row g-3 mb-4 delivery-stats">
                <div class="col-md-3 mb-3">
                    <div class="stat-card inventory">
                        <div class="stat-icon">
                            <i class="bi bi-clock"></i>
                        </div>
                        <div>
                            <div class="stat-value"><?php echo $stats['pending_count'] ?? 0; ?></div>
                            <div class="stat-label">Pending</div>
                        </div>
                    </div>
                </div>

                <div class="col-md-3 mb-3">
                    <div class="stat-card pending">
                        <div class="stat-icon">
                            <i class="bi bi-truck"></i>
                        </div>
                        <div>
                            <div class="stat-value"><?php echo $stats['active_count'] ?? 0; ?></div>
                            <div class="stat-label">Active (In Transit/Partial)</div>
                        </div>
                    </div>
                </div>

                <div class="col-md-3 mb-3">
                    <div class="stat-card complete">
                        <div class="stat-icon">
                            <i class="bi bi-check-circle"></i>
                        </div>
                        <div>
                            <div class="stat-value"><?php echo $stats['completed_today'] ?? 0; ?></div>
                            <div class="stat-label">Completed Today</div>
                        </div>
                    </div>
                </div>

                <div class="col-md-3 mb-3">
                    <div class="stat-card sales">
                        <div class="stat-icon">
                            <i class="bi bi-archive"></i>
                        </div>
                        <div>
                            <div class="stat-value"><?php echo $stats['total_completed'] ?? 0; ?></div>
                            <div class="stat-label">Total Completed</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Search and Filter -->
            <div class="card mb-4">
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="input-group">
                                <span class="input-group-text">
                                    <i class="bi bi-search"></i>
                                </span>
                                <input type="text" class="form-control" id="searchInput" placeholder="Search by order ID, customer name, address...">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="d-flex gap-2">
                                <select class="form-select flex-grow-1" id="statusFilter">
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

            <!-- Delivery Orders Table -->
            <div class="card">
                <div class="table-container">
                    <table class="table custom-table compact-table">
                        <thead>
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
                                <td><?php echo htmlspecialchars($order['customer_name']); ?></td>
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
                                    <div class="action-buttons" style="display: flex; justify-content: center; gap: 8px;">
                                        <button class="btn-action btn-view" title="View Details" onclick="viewDeliveryDetails(<?php echo $order['delivery_id']; ?>)">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                        
                                        <?php 
                                        $has_coordinates = !empty($order['latitude']) && !empty($order['longitude']) && 
                                                           $order['latitude'] != 0 && $order['longitude'] != 0;
                                        if ($has_coordinates): 
                                        ?>
                                            <!-- Live Navigation Button (Pinalitan ang pangalan ng function) -->
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
                                            <button class="btn-action btn-start" title="Start Delivery" onclick="updateDeliveryStatus(<?php echo $order['delivery_id']; ?>, 'in-transit')">
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

    <!-- Hidden thermal receipt container -->
    <div id="thermalReceipt" style="display: none;"></div>

    <!-- Live Tracking Modal (taller, collapsible panel, draggable on desktop) -->
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
                    
                    <!-- Navigation Status Panel (collapsible, draggable on desktop) -->
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
        <div class="modal-dialog modal-xl">
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
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Location Map Modal -->
    <div class="modal fade" id="locationMapModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
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
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary" onclick="openInGoogleMaps()">
                        <i class="bi bi-google"></i> Open in Google Maps
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Photo Modal -->
    <div class="modal fade" id="photoModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
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
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <a href="#" class="btn btn-primary" id="downloadPhotoBtn" download>
                        <i class="bi bi-download"></i> Download
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Delivery Modal -->
    <div class="modal fade" id="deliveryModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
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
                        
                        <div class="modal-footer py-2 px-0">
                            <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-sm btn-primary">Confirm Delivery</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Thermal Receipt Modal -->
    <div class="modal fade" id="receiptModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-receipt me-2"></i>
                        Receipt Preview
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="receiptContent">
                    <!-- Receipt preview will be loaded here -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary" onclick="printThermalReceipt()">
                        <i class="bi bi-printer me-2"></i>Print Receipt
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Partial Delivery Modal -->
    <div class="modal fade" id="partialModal" tabindex="-1">
        <div class="modal-dialog">
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

        // ================= LIVE TRACKING VARIABLES (OPTIMIZED) =================
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
                // Check for active deliveries before stopping (using badge classes)
                const rows = document.querySelectorAll('tbody tr');
                let hasActiveDelivery = false;
                
                rows.forEach(row => {
                    const statusCell = row.cells[5]; // Status column index
                    if (statusCell) {
                        const badge = statusCell.querySelector('.badge');
                        if (badge) {
                            const badgeClass = badge.className;
                            // 'in-transit' has class 'bg-primary', 'partial' has 'bg-info'
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
                startTracking(); // this is the main shift start function
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
            
            let btn = document.getElementById('trackingBtn');
            btn.innerHTML = '<i class="bi bi-stop-circle"></i> Stop Tracking';
            btn.classList.remove('btn-success');
            btn.classList.add('btn-danger');
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

        // ================= LIVE NAVIGATION FUNCTIONS (RENAMED & OPTIMIZED) =================
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

            // Update destination info agad
            document.getElementById('destinationText').textContent = customerName || 'Customer Location';
            document.getElementById('destinationCoordinates').textContent = 
                `${destinationPosition.lat.toFixed(6)}, ${destinationPosition.lng.toFixed(6)}`;

            // Initialize map agad (huwag maghintay ng GPS)
            setTimeout(() => {
                initLiveTrackingMap(destinationPosition.lat, destinationPosition.lng, customerName, address);
                // Simulan ang pagkuha ng GPS (fast acquisition)
                startFastGPSAcquisition();
            }, 300); // Bahagyang delay para mag-render muna ang modal
        }

        function initLiveTrackingMap(destLat, destLng, customerName, address) {
            // Remove existing map if any
            if (liveTrackingMap) {
                liveTrackingMap.remove();
            }

            // Create map centered on destination (mas mabilis kaysa hintayin ang user location)
            liveTrackingMap = L.map('trackingMap').setView([destLat, destLng], 13);

            // Add OpenStreetMap tiles
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '© OpenStreetMap contributors'
            }).addTo(liveTrackingMap);

            // Add destination marker (red)
            destinationMarker = L.marker([destLat, destLng], {
                icon: L.divIcon({
                    className: 'custom-destination-icon',
                    html: '<div class="custom-destination-icon"></div>',
                    iconSize: [20, 20],
                    iconAnchor: [10, 10]
                })
            }).addTo(liveTrackingMap);
            
            destinationMarker.bindPopup(`
                <b>${customerName || 'Customer'}</b><br>
                ${address || 'Destination'}<br>
                <small>${destLat.toFixed(6)}, ${destLng.toFixed(6)}</small>
            `).openPopup();

            // Add user marker (blue) - itatago muna, ipapakita pag may GPS na
            userMarker = L.marker([destLat, destLng], {
                icon: L.divIcon({
                    className: 'custom-user-icon',
                    html: '<div class="custom-user-icon"></div>',
                    iconSize: [20, 20],
                    iconAnchor: [10, 10]
                })
            }).addTo(liveTrackingMap);
            
            userMarker.bindPopup('<b>Your Location</b><br>Waiting for GPS...');

            // Add accuracy circle
            accuracyCircle = L.circle([destLat, destLng], {
                color: '#007bff',
                fillColor: '#007bff',
                fillOpacity: 0.1,
                radius: 100
            }).addTo(liveTrackingMap);
        }

        // Mabilis na pagkuha ng GPS (low accuracy, cached)
        function startFastGPSAcquisition() {
            // Kung may cached na posisyon at bago pa (<= 30 seconds), gamitin muna
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

            // Subukang kumuha ng mabilis na low-accuracy fix
            navigator.geolocation.getCurrentPosition(
                function(position) {
                    // I-save sa cache
                    lastKnownPosition = {
                        lat: position.coords.latitude,
                        lng: position.coords.longitude,
                        accuracy: position.coords.accuracy,
                        timestamp: Date.now()
                    };
                    livePositionSuccess(position);
                    // Pag nakuha na, mag-watch na ng high accuracy
                    startLiveWatching();
                },
                function(error) {
                    console.log('Fast GPS error:', error);
                    // Kung walang makuha, subukan ang high accuracy watch
                    startLiveWatching();
                },
                {
                    enableHighAccuracy: false,
                    timeout: 5000,
                    maximumAge: 30000 // Gumamit ng naka-cache hanggang 30s
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

            // I-update ang cache
            lastKnownPosition = {
                lat: lat,
                lng: lng,
                accuracy: accuracy,
                timestamp: Date.now()
            };

            // Update user marker position
            if (userMarker) {
                userMarker.setLatLng([lat, lng]);
                userMarker.setPopupContent(`
                    <b>Your Location</b><br>
                    <small>${lat.toFixed(6)}, ${lng.toFixed(6)}</small><br>
                    <small>Accuracy: ${accuracy.toFixed(1)}m</small>
                `);
            }

            // Update accuracy circle
            if (accuracyCircle) {
                accuracyCircle.setLatLng([lat, lng]);
                accuracyCircle.setRadius(accuracy);
            }

            // Update UI with location info (no error message)
            document.getElementById('yourLocationText').innerHTML = '<i class="bi bi-check-circle-fill text-success me-1"></i> Location acquired';
            document.getElementById('yourCoordinates').innerHTML = `${lat.toFixed(6)}, ${lng.toFixed(6)}`;

            // Update or create route (huwag i-fit para hindi mag-reset ang view)
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
                        fitSelectedRoutes: false, // Huwag i-auto fit para hindi mag-jump ang view
                        lineOptions: {
                            styles: [{ color: '#007bff', opacity: 0.8, weight: 5 }]
                        },
                        createMarker: function() { return null; } // Don't create markers (we have our own)
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
            let accuracyText = 'High accuracy';
            
            if (accuracy < 10) {
                accuracyClass = 'bg-success';
                accuracyText = 'Excellent accuracy';
            } else if (accuracy < 30) {
                accuracyClass = 'bg-info';
                accuracyText = 'Good accuracy';
            } else if (accuracy < 100) {
                accuracyClass = 'bg-warning';
                accuracyText = 'Fair accuracy';
            } else {
                accuracyClass = 'bg-danger';
                accuracyText = 'Poor accuracy';
            }
            
            document.getElementById('accuracyBar').className = 'progress-bar ' + accuracyClass;
            document.getElementById('accuracyText').innerHTML = `<i class="bi bi-${accuracyClass === 'bg-success' ? 'check-circle' : accuracyClass === 'bg-info' ? 'info-circle' : accuracyClass === 'bg-warning' ? 'exclamation-triangle' : 'x-circle'} me-1"></i> ${accuracyText}`;

            // Huwag i-center ang map sa user para hindi mawala ang view
        }

        function livePositionError(error) {
            // Don't show error message, just update the UI with retry option
            let accuracyClass = 'bg-warning';
            let accuracyText = 'Waiting for GPS...';
            
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
            // Check for active deliveries before stopping navigation (same as before)
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
                return; // Do not stop navigation
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

        // Confirmation before stopping live navigation (bagong function)
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
                // If no current position, try to get one
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

        // ================= DELIVERY FUNCTIONS WITH GPS VALIDATION =================

        // Update delivery status (for start and partial) - WITH GPS VALIDATION
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

        // Show delivery modal - WITH GPS VALIDATION
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

        // Load items for partial delivery
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

        // Display items for partial delivery
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

        // Submit Partial Delivery - WITH GPS VALIDATION
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

        // View delivery details in modal
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

        // Show photo modal
        function showPhotoModal(photoUrl) {
            const modalImg = document.getElementById('photoModalImg');
            const downloadBtn = document.getElementById('downloadPhotoBtn');
            
            modalImg.src = photoUrl;
            downloadBtn.href = photoUrl;
            
            const modal = new bootstrap.Modal(document.getElementById('photoModal'));
            modal.show();
        }

        // Generate thermal receipt HTML
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

        // Show thermal receipt modal
        function showReceiptModal(deliveryId, soNumber, customerName, address, signedBy, deliveryDate, itemsRaw) {
            const receiptContent = document.getElementById('receiptContent');
            
            currentThermalReceipt = generateThermalReceipt(deliveryId, soNumber, customerName, address, signedBy, deliveryDate, itemsRaw);
            receiptContent.innerHTML = currentThermalReceipt;
            
            const modal = new bootstrap.Modal(document.getElementById('receiptModal'));
            modal.show();
        }

        // Print thermal receipt
        function printThermalReceipt() {
            const thermalDiv = document.getElementById('thermalReceipt');
            thermalDiv.style.display = 'block';
            thermalDiv.innerHTML = currentThermalReceipt;
            
            window.print();
            
            setTimeout(() => {
                thermalDiv.style.display = 'none';
            }, 100);
        }

        // Show location on map
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

        // Initialize map
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

        // Open in Google Maps
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

        // Copy SQL
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

        // Logout
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

        // ================= COLLAPSIBLE PANEL & DRAGGABLE =================
        function initCollapsiblePanel() {
            const panel = document.getElementById('navigationStatusPanel');
            const toggleBtn = document.getElementById('toggleStatusPanel');
            if (!panel || !toggleBtn) return;

            let isCollapsed = false;

            // Prevent drag from starting when clicking the toggle button
            toggleBtn.addEventListener('mousedown', function(e) {
                e.stopPropagation(); // Stop event from reaching the h6 drag handler
            });
            toggleBtn.addEventListener('touchstart', function(e) {
                e.stopPropagation(); // For mobile touch
            });

            toggleBtn.addEventListener('click', function() {
                isCollapsed = !isCollapsed;
                panel.classList.toggle('collapsed', isCollapsed);
                const icon = toggleBtn.querySelector('i');
                if (isCollapsed) {
                    icon.classList.remove('bi-chevron-up');
                    icon.classList.add('bi-chevron-down');
                } else {
                    icon.classList.remove('bi-chevron-down');
                    icon.classList.add('bi-chevron-up');
                }
                // Notify map to resize
                if (liveTrackingMap) {
                    setTimeout(() => liveTrackingMap.invalidateSize(), 200);
                }
            });
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
                    // On mobile: stick to bottom (handled by CSS)
                    panel.style.left = '';
                    panel.style.top = '';
                    panel.style.right = '';
                } else {
                    // Desktop: 20px from right edge
                    const defaultLeft = containerRect.width - panelWidth - 20;
                    const defaultTop = 20;
                    panel.style.left = defaultLeft + 'px';
                    panel.style.top = defaultTop + 'px';
                    panel.style.right = 'auto';
                }
            }

            // Reset position when modal opens
            const trackingModal = document.getElementById('trackingModal');
            trackingModal.addEventListener('shown.bs.modal', resetPosition);

            // Only enable dragging on desktop
            function enableDragging() {
                if (window.innerWidth <= 768) return; // no dragging on mobile

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
                }

                function stopDrag() {
                    isDragging = false;
                    document.removeEventListener('mousemove', drag);
                    document.removeEventListener('mouseup', stopDrag);
                    document.removeEventListener('touchmove', drag);
                    document.removeEventListener('touchend', stopDrag);
                    document.removeEventListener('touchcancel', stopDrag);
                }

                // Attach drag start to handle
                handle.addEventListener('mousedown', startDrag);
                handle.addEventListener('touchstart', startDrag, { passive: false });
            }

            // Initial enable
            enableDragging();

            // Re-evaluate on resize
            window.addEventListener('resize', function() {
                resetPosition();
                // Could also dynamically remove/add listeners, but not strictly necessary
            });
        }

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            initializeSidebar();
            
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

            window.addEventListener('resize', handleSidebarResize);
            
            const locationModal = document.getElementById('locationMapModal');
            if (locationModal) {
                locationModal.addEventListener('hidden.bs.modal', function () {
                    if (map) {
                        map.remove();
                        map = null;
                    }
                });
            }

            // Clean up live tracking on modal close
            const trackingModal = document.getElementById('trackingModal');
            if (trackingModal) {
                trackingModal.addEventListener('hidden.bs.modal', function () {
                    // Do not call stopLiveNavigation() here because it might have been called already
                    // Just clean up map resources
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

            // Setup tracking button click handler
            const trackingBtn = document.getElementById('trackingBtn');
            if (trackingBtn) {
                trackingBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    
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
                        toggleTracking();
                    }
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

            // Initialize collapsible panel and draggable if tracking modal exists
            if (document.getElementById('trackingModal')) {
                initCollapsiblePanel();
                makePanelDraggable();
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
            else if (e.ctrlKey && e.key === 'f' && !e.target.matches('input, textarea')) {
                e.preventDefault();
                const searchInput = document.getElementById('searchInput');
                if (searchInput) {
                    searchInput.focus();
                }
            }
        });
    </script>
</body>
</html>