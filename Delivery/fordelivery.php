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

// If still no driver_id, try to get from drivers table using user_id
if ($driver_id == 0 && $user_role == 'delivery') {
    $driver_query = "SELECT driver_id FROM drivers WHERE user_id = ? LIMIT 1";
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

// Get the current trip ID for the driver (if any) - but we'll track even without trip
$current_trip_id = null;
if ($driver_id > 0) {
    $trip_query = "SELECT trip_id FROM trip_tickets 
                   WHERE driver_id = ? AND trip_status IN ('in-progress', 'planned') 
                   ORDER BY trip_date DESC LIMIT 1";
    $trip_stmt = $conn->prepare($trip_query);
    $trip_stmt->bind_param("i", $driver_id);
    $trip_stmt->execute();
    $trip_result = $trip_stmt->get_result();
    if ($trip_row = $trip_result->fetch_assoc()) {
        $current_trip_id = $trip_row['trip_id'];
    }
    $trip_stmt->close();
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

// Get driver status from database
$driver_status = 'unknown';
if ($driver_id > 0 && $driver_info) {
    $driver_status = $driver_info['status'] ?? 'active';
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
        
        .receipt-btn {
            background-color: #6f42c1;
            color: white;
            border: none;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 0.75rem;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .receipt-btn:hover {
            background-color: #5a32a3;
            color: white;
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
        
        /* Thermal Paper Receipt - SINGLE RECEIPT, SINGLE PAGE */
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
        
        #receiptModal .modal-body::-webkit-scrollbar {
            width: 8px;
        }
        
        #receiptModal .modal-body::-webkit-scrollbar-track {
            background: #f1f1f1;
        }
        
        #receiptModal .modal-body::-webkit-scrollbar-thumb {
            background: #888;
            border-radius: 4px;
        }
        
        #receiptModal .modal-body::-webkit-scrollbar-thumb:hover {
            background: #555;
        }
        
        #receiptModal .modal-footer {
            background: #f8f9fa;
            border-top: 1px solid #dee2e6;
        }
        
        /* Print styles - SINGLE RECEIPT, SINGLE PAGE, ANY PAPER SIZE */
        @media print {
            /* Hide everything except the receipt */
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
            
            /* Receipt fixed at 72mm width, auto height */
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
            
            /* No fixed page size - let printer handle it */
            @page {
                margin: 0;
            }
        }
        
        @media (max-width: 768px) {
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
        }

        /* Location tracking status indicator */
        .tracking-status {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: rgba(0,0,0,0.8);
            color: white;
            padding: 8px 15px;
            border-radius: 30px;
            font-size: 12px;
            z-index: 9999;
            display: flex;
            align-items: center;
            gap: 8px;
            pointer-events: none;
            box-shadow: 0 2px 10px rgba(0,0,0,0.2);
            transition: background-color 0.3s;
        }

        .tracking-dot {
            width: 10px;
            height: 10px;
            background-color: #4caf50;
            border-radius: 50%;
            display: inline-block;
            animation: pulse 2s infinite;
        }

        .tracking-dot.idle {
            background-color: #ffc107;
            animation: pulse-idle 2s infinite;
        }

        .tracking-dot.error {
            background-color: #f44336;
            animation: none;
        }

        @keyframes pulse {
            0% {
                box-shadow: 0 0 0 0 rgba(76, 175, 80, 0.7);
            }
            70% {
                box-shadow: 0 0 0 10px rgba(76, 175, 80, 0);
            }
            100% {
                box-shadow: 0 0 0 0 rgba(76, 175, 80, 0);
            }
        }

        @keyframes pulse-idle {
            0% {
                box-shadow: 0 0 0 0 rgba(255, 193, 7, 0.7);
            }
            70% {
                box-shadow: 0 0 0 10px rgba(255, 193, 7, 0);
            }
            100% {
                box-shadow: 0 0 0 0 rgba(255, 193, 7, 0);
            }
        }

        .driver-status-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            margin-left: 8px;
        }
        
        .driver-status-badge.active {
            background-color: #28a745;
            color: white;
        }
        
        .driver-status-badge.idle {
            background-color: #ffc107;
            color: #212529;
        }
        
        .driver-status-badge.off-duty {
            background-color: #6c757d;
            color: white;
        }
    </style>
</head>
<body>
    <!-- Location tracking status indicator (only for drivers) - ALWAYS ON when logged in -->
    <?php if ($user_role == 'delivery' && $driver_id > 0): ?>
    <div class="tracking-status" id="trackingStatus">
        <span class="tracking-dot" id="trackingDot"></span>
        <span id="trackingStatusText">Location tracking active - Updating every 10 seconds</span>
        <span class="driver-status-badge <?php echo $driver_status; ?>" id="driverStatusBadge">
            <?php echo ucfirst($driver_status); ?>
        </span>
    </div>
    <?php endif; ?>

    <!-- Display success/error messages -->
    <?php if (isset($_SESSION['success_message'])): ?>
        <div class="alert alert-success alert-dismissible fade show m-3" role="alert">
            <?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    
    <?php if (isset($_SESSION['error_message'])): ?>
        <div class="alert alert-danger alert-dismissible fade show m-3" role="alert">
            <?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

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
                            <span class="nav-text">Rejected Delivery Advice</span>
                        </a>
                    </li>
                </ul>
            </div>
            <div class="sidebar-footer">
                <div class="user-profile-sidebar">
                    <div class="user-avatar-sidebar"><?php echo substr($user_name, 0, 2); ?></div>
                    <div class="user-details-sidebar">
                        <span class="user-name-sidebar"><?php echo htmlspecialchars($user_name); ?></span>
                        <small class="d-block text-muted"><?php echo ucfirst($driver_status); ?></small>
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
                            <br><small>You don't have any deliveries assigned yet. Your location is still being tracked.</small>
                        <?php elseif ($delivery_branch_column_exists && !$view_all_branches): ?>
                            <br><small>No deliveries found for your branch.</small>
                        <?php endif; ?>
                    </p>
                </div>
            <?php else: ?>

            <!-- Delivery Orders Table -->
            <div class="card">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Order ID</th>
                                <th>Customer Name</th>
                                <th>Address</th>
                                <th>Contact</th>
                                <th>Items</th>
                                <th>Status</th>
                                <th>Stop</th>
                                <th>Actions</th>
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
                                <td>
                                    <?php if ($order['stop_sequence']): ?>
                                        <span class="badge bg-secondary">Stop #<?php echo $order['stop_sequence']; ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="btn-group" role="group">
                                        <button class="btn btn-sm btn-info" title="View Details" onclick="viewDeliveryDetails(<?php echo $order['delivery_id']; ?>)">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                        
                                        <?php 
                                        $has_coordinates = !empty($order['latitude']) && !empty($order['longitude']) && 
                                                           $order['latitude'] != 0 && $order['longitude'] != 0;
                                        if ($has_coordinates): 
                                        ?>
                                            <button class="btn btn-sm map-icon-btn" title="View on Map" onclick="showLocation(
                                                <?php echo $order['latitude']; ?>, 
                                                <?php echo $order['longitude']; ?>, 
                                                '<?php echo htmlspecialchars(addslashes($order['customer_name'])); ?>', 
                                                '<?php echo htmlspecialchars(addslashes($order['address'] . ', ' . $order['city'])); ?>'
                                            )">
                                                <i class="bi bi-geo-alt-fill"></i>
                                            </button>
                                        <?php endif; ?>
                                        
                                        <?php if ($order['delivery_status'] == 'pending'): ?>
                                            <button class="btn btn-sm btn-primary" title="Start Delivery" onclick="updateDeliveryStatus(<?php echo $order['delivery_id']; ?>, 'in-transit')">
                                                <i class="bi bi-truck"></i>
                                            </button>
                                        <?php elseif ($order['delivery_status'] == 'in-transit'): ?>
                                            <button class="btn btn-sm btn-success" title="Mark as Delivered" onclick="showDeliveryModal(<?php echo $order['delivery_id']; ?>, <?php echo $order['so_id']; ?>, '<?php echo htmlspecialchars(addslashes($order['so_number'])); ?>')">
                                                <i class="bi bi-check-lg"></i>
                                            </button>
                                            <button class="btn btn-sm btn-warning" title="Mark as Partial" onclick="updateDeliveryStatus(<?php echo $order['delivery_id']; ?>, 'partial')">
                                                <i class="bi bi-exclamation-triangle"></i>
                                            </button>
                                        <?php elseif ($order['delivery_status'] == 'partial'): ?>
                                            <button class="btn btn-sm btn-success" title="Complete Remaining Items" onclick="showDeliveryModal(<?php echo $order['delivery_id']; ?>, <?php echo $order['so_id']; ?>, '<?php echo htmlspecialchars(addslashes($order['so_number'])); ?>')">
                                                <i class="bi bi-check-lg"></i>
                                            </button>
                                        <?php endif; ?>
                                        
                                        <?php if ($order['delivery_status'] == 'delivered'): ?>
                                            <button class="btn btn-sm receipt-btn" title="Print Receipt" onclick="showReceiptModal(<?php echo $order['delivery_id']; ?>, '<?php echo htmlspecialchars(addslashes($order['so_number'])); ?>', '<?php echo htmlspecialchars(addslashes($order['customer_name'])); ?>', '<?php echo htmlspecialchars(addslashes($order['address'] . ', ' . $order['city'])); ?>', '<?php echo htmlspecialchars(addslashes($order['signed_by'])); ?>', '<?php echo $order['delivery_date']; ?>', '<?php echo htmlspecialchars(addslashes($order['items_receipt'])); ?>')">
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

    <!-- View Details Modal (NO MAP) -->
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

    <!-- Location Map Modal (Original Style) -->
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
    <script>
        const branchId = <?php echo $branch_id; ?>;
        const viewAllBranches = <?php echo $view_all_branches ? 'true' : 'false'; ?>;
        const userRole = '<?php echo $user_role; ?>';
        const driverId = <?php echo $driver_id ?: 0; ?>;
        const currentTripId = <?php echo $current_trip_id ?: 'null'; ?>;
        const driverStatus = '<?php echo $driver_status; ?>';

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
        let locationUpdateCount = 0;

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

        // Update delivery status (for start and partial)
        function updateDeliveryStatus(deliveryId, newStatus) {
            if (newStatus === 'in-transit') {
                if (confirm('Start this delivery?')) {
                    const formData = new FormData();
                    formData.append('delivery_id', deliveryId);
                    formData.append('status', newStatus);
                    formData.append('branch_id', branchId);
                    
                    fetch('update_delivery_status.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            location.reload();
                        } else {
                            alert('Error: ' + data.message);
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        alert('Error updating status');
                    });
                }
            } else if (newStatus === 'partial') {
                // Load items for partial delivery
                loadItemsForPartial(deliveryId);
            }
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
                        alert('Error loading items: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error loading items');
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

        // Submit Partial Delivery
        function submitPartialDelivery() {
            const reason = document.getElementById('partialReason').value;
            let details = document.getElementById('partialDetails').value;
            
            if (!reason) {
                alert('Please select a reason');
                return;
            }
            
            let finalReason = reason;
            if (reason === 'Other') {
                const otherReason = document.getElementById('otherReason').value;
                if (!otherReason) {
                    alert('Please specify the reason');
                    return;
                }
                finalReason = otherReason;
            }
            
            // Get selected items
            const checkboxes = document.querySelectorAll('#itemsList input[type="checkbox"]:checked');
            if (checkboxes.length === 0) {
                alert('Please select at least one item that was delivered');
                return;
            }
            
            const deliveredItems = Array.from(checkboxes).map(cb => cb.value);
            
            if (details) {
                finalReason += ' - ' + details;
            }
            
            finalReason += ` [Delivered items: ${checkboxes.length} of ${currentItems.length}]`;
            
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
                if (data.success) {
                    // Close modal
                    const modal = bootstrap.Modal.getInstance(document.getElementById('partialModal'));
                    modal.hide();
                    // Reload to show updated status
                    location.reload();
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error updating status');
            });
        }

        // View delivery details in modal (NO MAP)
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
            
            // Fetch delivery details via AJAX
            fetch('get_delivery_details.php?delivery_id=' + deliveryId)
                .then(response => response.text())
                .then(data => {
                    modalBody.innerHTML = data;
                    
                    // Add click handlers for photo links
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

        // Show delivery modal (for complete delivery)
        function showDeliveryModal(deliveryId, soId, orderNumber) {
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
            
            // Parse items
            const items = itemsRaw ? itemsRaw.split('||') : [];
            
            // Format the current date for receipt
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
            
            // Receipt number
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

        // Print thermal receipt - SINGLE PAGE, ANY PAPER SIZE
        function printThermalReceipt() {
            const thermalDiv = document.getElementById('thermalReceipt');
            thermalDiv.style.display = 'block';
            thermalDiv.innerHTML = currentThermalReceipt;
            
            // Print - no fixed page size
            window.print();
            
            // Clean up
            setTimeout(() => {
                thermalDiv.style.display = 'none';
            }, 100);
        }

        // Show location on map (from map icon)
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
            
            // Small delay to ensure modal is rendered
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
            if (confirm('Are you sure you want to logout?')) {
                window.location.href = '../logout.php';
            }
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

    <!-- Driver Location Tracking -->
    <script src="../js/driver-location-tracker.js"></script>
    <script>
        // FIXED: ALWAYS track driver location as soon as they log in, even when idle
        let driverTracker = null;
        
        // Update tracking status indicator
        function updateTrackingStatus(status, message) {
            const statusEl = document.getElementById('trackingStatus');
            const dotEl = document.getElementById('trackingDot');
            const textEl = document.getElementById('trackingStatusText');
            
            if (!statusEl || !dotEl || !textEl) return;
            
            if (status === 'active') {
                dotEl.className = 'tracking-dot';
                statusEl.style.backgroundColor = 'rgba(76, 175, 80, 0.9)';
                textEl.textContent = message || 'Location tracking active - Updating every 10 seconds';
            } else if (status === 'idle') {
                dotEl.className = 'tracking-dot idle';
                statusEl.style.backgroundColor = 'rgba(255, 193, 7, 0.9)';
                textEl.textContent = message || 'Location tracking active (idle) - Updating every 10 seconds';
            } else if (status === 'error') {
                dotEl.className = 'tracking-dot error';
                statusEl.style.backgroundColor = 'rgba(244, 67, 54, 0.9)';
                textEl.textContent = message || 'Location tracking error';
            }
        }
        
        // This function will start tracking immediately for ANY logged-in driver
        function initDriverTracking() {
            // Check if user is a driver (any driver, regardless of status)
            if (userRole === 'delivery' && driverId > 0) {
                console.log('[v0] Delivery driver detected - Starting automatic location tracking (IDLE OR ACTIVE)');
                
                // Show initial status
                if (currentTripId) {
                    updateTrackingStatus('active', 'Location tracking active - On delivery');
                } else {
                    updateTrackingStatus('idle', 'Location tracking active (idle) - Updating every 10 seconds');
                }
                
                // Create tracker instance with correct parameters
                driverTracker = new DriverLocationTracker({
                    updateInterval: 10000, // 10 seconds
                    enableHighAccuracy: true,
                    apiEndpoint: './update_driver_location.php',
                    onSuccess: function(data) {
                        locationUpdateCount++;
                        console.log('[v0] Location updated successfully #' + locationUpdateCount, new Date().toLocaleTimeString());
                        
                        // Update status based on trip
                        if (currentTripId) {
                            updateTrackingStatus('active', `Location tracking active - Updated ${new Date().toLocaleTimeString()}`);
                        } else {
                            updateTrackingStatus('idle', `Location tracking active (idle) - Updated ${new Date().toLocaleTimeString()}`);
                        }
                    },
                    onError: function(error) {
                        console.log('[v0] Location tracking error:', error);
                        updateTrackingStatus('error', 'Location error - ' + (error.message || 'Unknown error'));
                    }
                });

                // Pass the current trip ID if available (null is fine - still track)
                if (currentTripId) {
                    console.log('[v0] Driver has active trip ID:', currentTripId);
                } else {
                    console.log('[v0] Driver is idle - tracking location without trip');
                }
                
                // Start tracking immediately
                setTimeout(function() {
                    driverTracker.startTracking();
                }, 1000); // Small delay to ensure page is fully loaded
            } else {
                console.log('[v0] Not a delivery driver, tracking not started. Role:', userRole, 'Driver ID:', driverId);
            }
        }

        // Start tracking when DOM is loaded
        document.addEventListener('DOMContentLoaded', function() {
            initDriverTracking();
        });

        // Also try to start tracking immediately if the script loads after DOM
        if (document.readyState === 'complete' || document.readyState === 'interactive') {
            setTimeout(initDriverTracking, 100);
        }

        // Handle page visibility change (when user switches tabs and comes back)
        document.addEventListener('visibilitychange', function() {
            if (!document.hidden && driverTracker && userRole === 'delivery' && driverId > 0) {
                console.log('[v0] Page visible again, ensuring tracking is active');
                // Check if tracking is still active
                if (driverTracker.watchId === null) {
                    console.log('[v0] Tracking was stopped, restarting...');
                    driverTracker.startTracking();
                    
                    // Update status
                    if (currentTripId) {
                        updateTrackingStatus('active', 'Location tracking resumed - On delivery');
                    } else {
                        updateTrackingStatus('idle', 'Location tracking resumed (idle)');
                    }
                }
            }
        });

        // Handle before unload - send one last location update
        window.addEventListener('beforeunload', function() {
            if (driverTracker && navigator.geolocation && driverTracker.watchId !== null) {
                navigator.geolocation.getCurrentPosition(function(position) {
                    // Try to send one last update synchronously
                    const data = {
                        latitude: position.coords.latitude,
                        longitude: position.coords.longitude,
                        speed: position.coords.speed ? position.coords.speed * 3.6 : 0,
                        trip_id: currentTripId // This can be null - that's fine
                    };
                    
                    // Use sendBeacon for reliable last update
                    const blob = new Blob([JSON.stringify(data)], {type: 'application/json'});
                    navigator.sendBeacon('./update_driver_location.php', blob);
                    console.log('[v0] Final location sent before exit');
                }, null, {
                    timeout: 2000,
                    maximumAge: 0
                });
            }
        });

        // Periodically check if tracking is still active (every 30 seconds)
        setInterval(function() {
            if (userRole === 'delivery' && driverId > 0 && driverTracker) {
                if (driverTracker.watchId === null) {
                    console.log('[v0] Tracking stopped unexpectedly, restarting...');
                    driverTracker.startTracking();
                }
            }
        }, 30000);
    </script>
</body>
</html>