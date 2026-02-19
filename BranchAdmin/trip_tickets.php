<?php
require_once '../config/database.php';
require_once '../config/session_handler.php';

// Get current user info and branch context
$user_id = $_SESSION['user_id'] ?? 0;
$user_name = isset($_SESSION['first_name']) ? $_SESSION['first_name'] . ' ' . $_SESSION['last_name'] : 'Branch Admin';
$user_role = isset($_SESSION['role']) ? $_SESSION['role'] : 'branch_admin';
$branch_id = $_SESSION['branch_id'] ?? 0;
$view_all_branches = $_SESSION['view_all_branches'] ?? false;

// Check if branch_id column exists in trip_tickets table
$tt_branch_column_exists = false;
$check_tt_column = $conn->query("SHOW COLUMNS FROM trip_tickets LIKE 'branch_id'");
if ($check_tt_column && $check_tt_column->num_rows > 0) {
    $tt_branch_column_exists = true;
}

// Check if branch_id column exists in drivers table
$drivers_branch_column_exists = false;
$check_drivers_column = $conn->query("SHOW COLUMNS FROM drivers LIKE 'branch_id'");
if ($check_drivers_column && $check_drivers_column->num_rows > 0) {
    $drivers_branch_column_exists = true;
}

// Determine branch filter condition
$branch_condition = "";
if ($tt_branch_column_exists && !$view_all_branches) {
    $branch_condition = "AND tt.branch_id = $branch_id";
}

// ========== HANDLE AJAX REQUESTS ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    try {
        $conn->begin_transaction();
        
        // CREATE TRIP TICKET
        if ($_POST['action'] === 'create_trip') {
            $so_id = (int)$_POST['so_id'];
            $picklist_id = (int)$_POST['picklist_id'];
            $trip_date = $_POST['trip_date'];
            $trip_status = $_POST['trip_status'];
            $remarks = $_POST['remarks'] ?? '';
            
            // Validate required fields
            if (!$so_id) {
                throw new Exception('Sales Order is required');
            }
            
            if (!$picklist_id) {
                throw new Exception('Pick List is required');
            }
            
            if (!$trip_date) {
                throw new Exception('Trip date is required');
            }
            
            // Verify SO belongs to user's branch
            $check_so_query = "SELECT so_id FROM sales_orders WHERE so_id = ? AND branch_id = ?";
            $check_so_stmt = $conn->prepare($check_so_query);
            $check_so_stmt->bind_param("ii", $so_id, $branch_id);
            $check_so_stmt->execute();
            if ($check_so_stmt->get_result()->num_rows === 0 && !$view_all_branches) {
                throw new Exception('Sales Order not found or access denied');
            }
            
            // Verify pick list belongs to user's branch and get driver_id
            $pl_query = "SELECT pick_list_id, driver_id FROM pick_lists WHERE pick_list_id = ? AND branch_id = ?";
            $pl_stmt = $conn->prepare($pl_query);
            $pl_stmt->bind_param("ii", $picklist_id, $branch_id);
            $pl_stmt->execute();
            $pl_result = $pl_stmt->get_result();
            
            if ($pl_result->num_rows === 0 && !$view_all_branches) {
                throw new Exception('Pick List not found or access denied');
            }
            
            $picklist = $pl_result->fetch_assoc();
            $driver_id = $picklist['driver_id'];
            
            if (!$driver_id) {
                throw new Exception('Selected pick list has no assigned driver. Please assign a driver to the pick list first.');
            }
            
            // Generate trip number
            $trip_number = 'TT-' . date('Ymd') . '-' . str_pad($so_id, 5, '0', STR_PAD_LEFT);
            
            // Check if trip number already exists
            $check_trip_query = "SELECT trip_id FROM trip_tickets WHERE trip_number = ?";
            $check_trip_stmt = $conn->prepare($check_trip_query);
            $check_trip_stmt->bind_param("s", $trip_number);
            $check_trip_stmt->execute();
            
            if ($check_trip_stmt->get_result()->num_rows > 0) {
                // Generate a unique number with random suffix
                $trip_number = 'TT-' . date('Ymd') . '-' . rand(1000, 9999);
            }
            
            // Create trip ticket
            $insert_query = "INSERT INTO trip_tickets (trip_number, so_id, picklist_id, driver_id, branch_id, trip_date, trip_status, remarks, created_by, created_at) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
            $insert_stmt = $conn->prepare($insert_query);
            $insert_stmt->bind_param("siiissssi", $trip_number, $so_id, $picklist_id, $driver_id, $branch_id, $trip_date, $trip_status, $remarks, $user_id);
            
            if (!$insert_stmt->execute()) {
                throw new Exception('Failed to create trip ticket: ' . $insert_stmt->error);
            }
            
            $trip_id = $conn->insert_id;
            
            $conn->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'Trip ticket created successfully',
                'trip_id' => $trip_id,
                'trip_number' => $trip_number
            ]);
            exit;
        }
        
        // UPDATE TRIP TICKET
        elseif ($_POST['action'] === 'update_trip') {
            $trip_id = (int)$_POST['trip_id'];
            $trip_status = $_POST['trip_status'];
            $trip_date = $_POST['trip_date'];
            $total_stops = (int)$_POST['total_stops'];
            $total_delivered = (int)$_POST['total_delivered'];
            $total_failed = (int)$_POST['total_failed'];
            $remarks = $_POST['remarks'] ?? '';
            
            // Verify trip ticket belongs to user's branch
            $check_query = "SELECT trip_id, so_id, picklist_id FROM trip_tickets WHERE trip_id = ?";
            if (!$view_all_branches) {
                $check_query .= " AND branch_id = ?";
                $check_stmt = $conn->prepare($check_query);
                $check_stmt->bind_param("ii", $trip_id, $branch_id);
            } else {
                $check_stmt = $conn->prepare($check_query);
                $check_stmt->bind_param("i", $trip_id);
            }
            
            $check_stmt->execute();
            $result = $check_stmt->get_result();
            
            if ($result->num_rows === 0) {
                throw new Exception('Trip ticket not found or access denied');
            }
            
            $trip = $result->fetch_assoc();
            
            // Validate totals
            if ($total_stops < 0 || $total_delivered < 0 || $total_failed < 0) {
                throw new Exception('Invalid values for stops or deliveries');
            }
            
            // Update trip ticket
            $update_query = "UPDATE trip_tickets 
                           SET trip_status = ?, trip_date = ?, total_stops = ?, total_delivered = ?, total_failed = ?, remarks = ?, updated_at = NOW() 
                           WHERE trip_id = ?";
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->bind_param("ssiiiis", $trip_status, $trip_date, $total_stops, $total_delivered, $total_failed, $remarks, $trip_id);
            
            if (!$update_stmt->execute()) {
                throw new Exception('Failed to update trip ticket');
            }
            
            // If status is 'completed', update pick list and sales order
            if ($trip_status === 'completed') {
                // Update pick list status
                $update_pl_query = "UPDATE pick_lists SET pick_status = 'completed' WHERE pick_list_id = ?";
                $update_pl_stmt = $conn->prepare($update_pl_query);
                $update_pl_stmt->bind_param("i", $trip['picklist_id']);
                $update_pl_stmt->execute();
                
                // Update sales order status
                $update_so_query = "UPDATE sales_orders SET order_status = 'delivered' WHERE so_id = ?";
                $update_so_stmt = $conn->prepare($update_so_query);
                $update_so_stmt->bind_param("i", $trip['so_id']);
                $update_so_stmt->execute();
            }
            
            $conn->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'Trip ticket updated successfully'
            ]);
            exit;
        }
        
        // FINALIZE TRIP TICKET
        elseif ($_POST['action'] === 'finalize_trip') {
            $trip_id = (int)$_POST['trip_id'];
            
            // Verify trip ticket belongs to user's branch
            $check_query = "SELECT trip_id, so_id, picklist_id FROM trip_tickets WHERE trip_id = ?";
            if (!$view_all_branches) {
                $check_query .= " AND branch_id = ?";
                $check_stmt = $conn->prepare($check_query);
                $check_stmt->bind_param("ii", $trip_id, $branch_id);
            } else {
                $check_stmt = $conn->prepare($check_query);
                $check_stmt->bind_param("i", $trip_id);
            }
            
            $check_stmt->execute();
            $result = $check_stmt->get_result();
            
            if ($result->num_rows === 0) {
                throw new Exception('Trip ticket not found or access denied');
            }
            
            $trip = $result->fetch_assoc();
            
            // Update trip ticket status to completed
            $update_query = "UPDATE trip_tickets SET trip_status = 'completed', updated_at = NOW() WHERE trip_id = ?";
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->bind_param("i", $trip_id);
            
            if (!$update_stmt->execute()) {
                throw new Exception('Failed to finalize trip ticket');
            }
            
            // Update pick list status
            $update_pl_query = "UPDATE pick_lists SET pick_status = 'completed' WHERE pick_list_id = ?";
            $update_pl_stmt = $conn->prepare($update_pl_query);
            $update_pl_stmt->bind_param("i", $trip['picklist_id']);
            $update_pl_stmt->execute();
            
            // Update sales order status
            $update_so_query = "UPDATE sales_orders SET order_status = 'delivered' WHERE so_id = ?";
            $update_so_stmt = $conn->prepare($update_so_query);
            $update_so_stmt->bind_param("i", $trip['so_id']);
            $update_so_stmt->execute();
            
            $conn->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'Trip ticket finalized successfully'
            ]);
            exit;
        }
        
        // DELETE TRIP TICKET(S)
        elseif ($_POST['action'] === 'delete_trips') {
            $trip_ids = $_POST['trip_ids'];
            
            if (empty($trip_ids)) {
                throw new Exception('No trip tickets selected');
            }
            
            // Convert to array if string
            if (is_string($trip_ids)) {
                $trip_ids = explode(',', $trip_ids);
            }
            
            // Sanitize IDs
            $trip_ids = array_map('intval', $trip_ids);
            $placeholders = implode(',', array_fill(0, count($trip_ids), '?'));
            
            // Verify all trip tickets belong to user's branch
            $check_query = "SELECT COUNT(*) as count FROM trip_tickets WHERE trip_id IN ($placeholders)";
            if (!$view_all_branches) {
                $check_query .= " AND branch_id = ?";
                $check_stmt = $conn->prepare($check_query);
                
                $types = str_repeat('i', count($trip_ids)) . 'i';
                $params = array_merge($trip_ids, [$branch_id]);
                $check_stmt->bind_param($types, ...$params);
            } else {
                $check_stmt = $conn->prepare($check_query);
                $check_stmt->bind_param(str_repeat('i', count($trip_ids)), ...$trip_ids);
            }
            
            $check_stmt->execute();
            $result = $check_stmt->get_result();
            $count = $result->fetch_assoc()['count'];
            
            if ($count !== count($trip_ids)) {
                throw new Exception('Some trip tickets not found or access denied');
            }
            
            // Delete trip tickets
            $delete_query = "DELETE FROM trip_tickets WHERE trip_id IN ($placeholders)";
            $delete_stmt = $conn->prepare($delete_query);
            $delete_stmt->bind_param(str_repeat('i', count($trip_ids)), ...$trip_ids);
            
            if (!$delete_stmt->execute()) {
                throw new Exception('Failed to delete trip tickets');
            }
            
            $conn->commit();
            
            echo json_encode([
                'success' => true,
                'message' => count($trip_ids) . ' trip ticket(s) deleted successfully'
            ]);
            exit;
        }
        
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
        exit;
    }
}

// FETCH TRIP TICKETS FROM DATABASE WITH PROPER JOINS
$trip_query = "
    SELECT 
        tt.trip_id,
        tt.trip_number,
        tt.so_id,
        tt.picklist_id,
        tt.driver_id as trip_driver_id,
        -- Get driver from pick list (priority) or fallback to trip ticket driver
        COALESCE(pl.driver_name, d.driver_name) as driver_name,
        COALESCE(pl.vehicle_plate_number, d.vehicle_plate_number) as vehicle_plate_number,
        COALESCE(pl.vehicle_type, d.vehicle_type) as vehicle_type,
        tt.branch_id,
        b.branch_name,
        tt.trip_date,
        tt.trip_status,
        tt.total_stops,
        tt.total_delivered,
        tt.total_failed,
        tt.remarks,
        tt.created_at,
        tt.updated_at,
        tt.photo_1,
        so.so_number,
        so.order_status,
        so.customer_id,
        c.customer_name,
        pl.pick_list_number,
        pl.pick_status,
        pl.driver_id as picklist_driver_id,
        pl.driver_name as picklist_driver_name,
        pl.vehicle_plate_number as picklist_vehicle_plate,
        pl.vehicle_type as picklist_vehicle_type,
        -- Calculate completion percentage from actual pick list items
        CASE 
            WHEN (SELECT SUM(quantity_to_pick) FROM pick_list_items WHERE pick_list_id = tt.picklist_id) > 0 
            THEN ROUND(
                (SELECT SUM(quantity_picked) FROM pick_list_items WHERE pick_list_id = tt.picklist_id) / 
                (SELECT SUM(quantity_to_pick) FROM pick_list_items WHERE pick_list_id = tt.picklist_id) * 100, 1)
            ELSE 0
        END as completion_percentage,
        -- Get actual counts from pick_list_items
        (SELECT COUNT(*) FROM pick_list_items WHERE pick_list_id = tt.picklist_id) as actual_stops,
        (SELECT SUM(quantity_picked) FROM pick_list_items WHERE pick_list_id = tt.picklist_id) as actual_delivered,
        (SELECT SUM(quantity_to_pick - quantity_picked) FROM pick_list_items WHERE pick_list_id = tt.picklist_id AND quantity_to_pick > quantity_picked) as actual_failed
    FROM trip_tickets tt
    LEFT JOIN drivers d ON tt.driver_id = d.driver_id
    LEFT JOIN branches b ON tt.branch_id = b.branch_id
    LEFT JOIN sales_orders so ON tt.so_id = so.so_id
    LEFT JOIN customers c ON so.customer_id = c.customer_id
    LEFT JOIN (
        SELECT 
            pl.pick_list_id,
            pl.pick_list_number,
            pl.pick_status,
            pl.driver_id,
            d.driver_name,
            d.vehicle_plate_number,
            d.vehicle_type
        FROM pick_lists pl
        LEFT JOIN drivers d ON pl.driver_id = d.driver_id
    ) pl ON tt.picklist_id = pl.pick_list_id
    WHERE 1=1
    $branch_condition
    ORDER BY tt.trip_date DESC, tt.trip_id DESC
";

$trip_result = $conn->query($trip_query);
if (!$trip_result) {
    die("Query failed: " . $conn->error);
}
$trip_tickets = $trip_result->fetch_all(MYSQLI_ASSOC);

// FETCH DRIVERS FOR FILTER - BRANCH SPECIFIC
$drivers_query = "SELECT driver_id, driver_name FROM drivers WHERE status = 'active'";

// Only add branch condition if column exists and not viewing all branches
if ($drivers_branch_column_exists && !$view_all_branches) {
    $drivers_query .= " AND branch_id = $branch_id";
}

$drivers_query .= " ORDER BY driver_name";
$drivers_result = $conn->query($drivers_query);
$drivers = $drivers_result->fetch_all(MYSQLI_ASSOC);

// CALCULATE STATISTICS FROM REAL DATA (branch-specific)
$total_tickets = count($trip_tickets);
$pending_tickets = count(array_filter($trip_tickets, fn($t) => $t['trip_status'] === 'planned' || $t['trip_status'] === 'pending'));
$in_transit_tickets = count(array_filter($trip_tickets, fn($t) => $t['trip_status'] === 'in-progress'));
$completed_tickets = count(array_filter($trip_tickets, fn($t) => $t['trip_status'] === 'completed'));

// STAT CARD VALUES
$statTotalTickets = $total_tickets;
$statPendingTickets = $pending_tickets;
$statActiveTrips = $in_transit_tickets;
$statCompletedTrips = $completed_tickets;

// Helper function for status badge
function getTripStatusClass($status) {
    return match($status) {
        'planned' => 'bg-warning text-dark',
        'pending' => 'bg-warning text-dark',
        'in-progress' => 'bg-primary text-white',
        'completed' => 'bg-success text-white',
        'cancelled' => 'bg-danger text-white',
        'delayed' => 'bg-info text-white',
        default => 'bg-secondary text-white'
    };
}

function getTripStatusText($status) {
    return match($status) {
        'planned' => 'Pending',
        'pending' => 'Pending',
        'in-progress' => 'In Transit',
        'completed' => 'Delivered',
        'cancelled' => 'Cancelled',
        'delayed' => 'Delayed',
        default => ucfirst(str_replace('-', ' ', $status))
    };
}

function formatDateOnly($dateStr) {
    if (!$dateStr) return '';
    $date = new DateTime($dateStr);
    return $date->format('M d, Y');
}

function formatDateTime($dateStr) {
    if (!$dateStr) return '';
    $date = new DateTime($dateStr);
    return $date->format('M d, Y h:i A');
}

function formatCompletion($percentage) {
    if ($percentage === null) return '0%';
    return number_format($percentage, 1) . '%';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trip Tickets - Branch Admin</title>
    <link rel="icon" type="image/png" href="../Pictures/favicon-96x96.png" sizes="96x96" />
    <link rel="icon" type="image/svg+xml" href="../Pictures/favicon.svg" />
    <link rel="shortcut icon" href="../Pictures/favicon.ico" />
    <link rel="apple-touch-icon" sizes="180x180" href="../Pictures/apple-touch-icon.png" />
    <link rel="manifest" href="../Pictures/site.webmanifest" />

    <link rel="stylesheet" href="../css/current_inventory.css">
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <!-- Font Awesome for more icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- SheetJS for Excel Export -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <!-- SweetAlert2 -->
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        /* Branch badge styling */
        .branch-badge {
            background-color: #e7f1ff;
            color: #0d6efd;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
            margin-left: 5px;
        }
        
        /* Alert for missing branch column */
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
        
        /* Completion percentage styling */
        .completion-cell {
            min-width: 120px;
        }
        .progress {
            width: 100px;
            height: 20px;
            background-color: #e9ecef;
            border-radius: 10px;
            overflow: hidden;
        }
        .progress-bar {
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            font-weight: 600;
            color: white;
            text-shadow: 0 0 2px rgba(0,0,0,0.2);
        }
        
        /* Responsive adjustments */
        @media (max-width: 768px) {
            .col-md-3, .col-md-4 {
                width: 50%;
                padding-left: 8px;
                padding-right: 8px;
            }
        }

        /* Trip ticket details styling */
        .ticket-details-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
        }
        
        .detail-card {
            background-color: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            border-left: 4px solid #0d6efd;
        }
        
        .detail-label {
            font-size: 12px;
            color: #6c757d;
            margin-bottom: 5px;
        }
        
        .detail-value {
            font-size: 16px;
            font-weight: 600;
            color: #212529;
        }
        
        .photo-view {
            background-color: white;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 15px;
            min-height: 150px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
        }
        
        .photo-view img {
            max-width: 100%;
            max-height: 200px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .photo-view i {
            font-size: 40px;
            color: #6c757d;
            margin-bottom: 10px;
        }
        
        .table-actions {
            display: flex;
            gap: 5px;
        }
        
        .btn-icon-sm {
            padding: 4px 8px;
            font-size: 12px;
        }
        
        .status-badge {
            display: inline-block;
            padding: 5px 12px;
            font-size: 12px;
            font-weight: 500;
            border-radius: 20px;
            text-align: center;
            min-width: 85px;
        }
        
        .compact-table th {
            font-size: 12px;
            padding: 10px 8px;
        }
        
        .compact-table td {
            font-size: 13px;
            padding: 10px 8px;
            vertical-align: middle;
        }

        .driver-info {
            font-size: 12px;
        }
        .driver-source {
            font-size: 10px;
            color: #6c757d;
            display: block;
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
                    <i class="bi bi-list"></i>
                </button>
                 <img src="../Pictures/amgc3DLogo.png" alt="Logo" class="logo-icon"> 
                 <span class="nav-text">Branch Admin</span>
                 </h3>
            </div>
            
            <div class="sidebar-menu">
                <ul class="nav flex-column">
                    <li class="nav-item">
                        <a class="nav-link" href="current_inventory.php">
                            <i class="bi bi-bar-chart-line"></i>
                            <span class="nav-text">Current Inventory</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="sales_order.php">
                            <i class="bi bi-bag"></i>
                            <span class="nav-text">Sales Orders</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="pick_list_items.php">
                            <i class="bi bi-list-check"></i>
                            <span class="nav-text">Pick List Items</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="bad_orders.php">
                            <i class="bi bi-recycle"></i>
                            <span class="nav-text">Bad Orders</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="purchase_order.php">
                            <i class="bi bi-box"></i>
                            <span class="nav-text">Purchase Orders</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="drivers.php">
                            <i class="bi bi-truck"></i>
                            <span class="nav-text">Drivers</span>
                        </a>
                    </li>
                    <!-- TRIP TICKETS ACTIVE LINK -->
                    <li class="nav-item">
                        <a class="nav-link active" href="trip_tickets.php">
                            <i class="bi bi-ticket-perforated"></i>
                            <span class="nav-text">Trip Tickets</span>
                        </a>
                    </li>
                    <hr class="sidebar-divider">
                </ul>
            </div>
            <!-- User Profile Section at the bottom of sidebar -->
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

        <!-- Main Content -->
        <div class="main-content" id="mainContent">
            <!-- TRIP TICKETS PAGE -->
            <div id="tripTicketsContent" class="page-content active">
                <div class="navbar-top">
                    <button class="mobile-menu-btn" id="mobileMenuBtn">
                        <i class="bi bi-list"></i>
                    </button>
                    <div class="page-title">
                        <h2>Trip Tickets</h2>
                        <p id="dashboardSubtitle">
                            Manage and track trip tickets for deliveries
                        </p>
                    </div>
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

                <!-- No Trip Tickets Warning -->
                <?php if (empty($trip_tickets) && $tt_branch_column_exists && !$view_all_branches): ?>
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle"></i> 
                        No trip tickets found for your branch.
                    </div>
                <?php endif; ?>

                <!-- No Drivers Warning -->
                <?php if (empty($drivers) && $drivers_branch_column_exists && !$view_all_branches): ?>
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle"></i> 
                        No active drivers found for your branch. Please contact admin to assign drivers.
                    </div>
                <?php endif; ?>

                <!-- Quick Stats Cards - REAL DATA FROM DATABASE (BRANCH-SPECIFIC) -->
                <div class="row g-3 mb-4">
                    <div class="col-xl-3 col-md-6">
                        <div class="stat-card total">
                            <i class="bi bi-ticket-perforated stat-icon"></i>
                            <div class="stat-value" id="totalTripTickets"><?= $statTotalTickets ?></div>
                            <div class="stat-label">Total Tickets</div>
                            <small class="d-block mt-2">
                                <?php if ($tt_branch_column_exists && !$view_all_branches): ?>
                                    Your branch
                                <?php else: ?>
                                    All time trips
                                <?php endif; ?>
                            </small>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="stat-card pending">
                            <i class="bi bi-clock stat-icon"></i>
                            <div class="stat-value" id="pendingTrips"><?= $statPendingTickets ?></div>
                            <div class="stat-label">Pending</div>
                            <small class="d-block mt-2">Waiting for dispatch</small>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="stat-card delivery">
                            <i class="bi bi-truck stat-icon"></i>
                            <div class="stat-value" id="activeTrips"><?= $statActiveTrips ?></div>
                            <div class="stat-label">In Transit</div>
                            <small class="d-block mt-2">Currently on delivery</small>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="stat-card complete">
                            <i class="bi bi-check-circle stat-icon"></i>
                            <div class="stat-value" id="completedTrips"><?= $statCompletedTrips ?></div>
                            <div class="stat-label">Completed</div>
                            <small class="d-block mt-2">Delivered successfully</small>
                        </div>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="row g-3 mb-4">
                    <div class="col-12">
                        <div class="form-card">
                            <div class="row g-3 align-items-center">
                                <div class="col-md-3">
                                    <div class="d-flex gap-2">
                                        <button class="btn btn-primary" onclick="showCreateModal()">
                                            <i class="bi bi-plus-circle me-2"></i> New Trip Ticket
                                        </button>
                                        <button class="btn btn-outline-primary" onclick="printTripTickets()">
                                            <i class="bi bi-printer me-2"></i> Print
                                        </button>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="search-box">
                                        <i class="bi bi-search"></i>
                                        <input type="text" class="form-control" placeholder="Search trip number, driver, SO, pick list..." id="searchInput" onkeyup="filterTripTickets()">
                                    </div>
                                </div>
                                <div class="col-md-2">
                                    <select class="form-select" id="statusFilter" onchange="filterTripTickets()">
                                        <option value="">All Status</option>
                                        <option value="planned">Pending</option>
                                        <option value="in-progress">In Transit</option>
                                        <option value="completed">Delivered</option>
                                        <option value="cancelled">Cancelled</option>
                                        <option value="delayed">Delayed</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <select class="form-select" id="driverFilter" onchange="filterTripTickets()">
                                        <option value="">All Drivers</option>
                                        <?php foreach ($drivers as $driver): ?>
                                            <option value="<?= htmlspecialchars($driver['driver_name']) ?>">
                                                <?= htmlspecialchars($driver['driver_name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Main Table -->
                <div class="data-table">
                    <div class="table-header d-flex justify-content-between align-items-center">
                        <h5>Trip Ticket List</h5>
                        <div class="d-flex gap-2">
                            <?php if ($tt_branch_column_exists && $view_all_branches): ?>
                                <span class="badge bg-success align-self-center">All Branches</span>
                            <?php endif; ?>
                            <button class="btn btn-sm btn-outline-primary" onclick="refreshTripTickets()">
                                <i class="bi bi-arrow-clockwise"></i> Refresh
                            </button>
                            <button class="btn btn-sm btn-outline-success" onclick="exportToExcel()">
                                <i class="bi bi-file-earmark-excel"></i> Export to Excel
                            </button>
                            <button class="btn btn-sm btn-outline-danger" onclick="deleteSelected()">
                                <i class="bi bi-trash"></i> Delete Selected
                            </button>
                        </div>
                    </div>
                    <div class="table-container">
                        <table class="table custom-table compact-table" id="tripTicketsTable">
                            <thead>
                                <tr>
                                    <th width="50">
                                        <input type="checkbox" class="form-check-input" id="selectAll" onclick="toggleSelectAll()">
                                    </th>
                                    <th width="120">Trip Number</th>
                                    <th width="120">SO Number</th>
                                    <th width="120">Pick List</th>
                                    <th width="150">Driver</th>
                                    <th width="100">Branch</th>
                                    <?php if ($tt_branch_column_exists && $view_all_branches): ?>
                                        <th width="80">Branch ID</th>
                                    <?php endif; ?>
                                    <th width="100">Trip Date</th>
                                    <th width="100">Status</th>
                                    <th width="100">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="tripTicketsTableBody">
                                <?php if (empty($trip_tickets)): ?>
                                <tr>
                                    <td colspan="<?= ($tt_branch_column_exists && $view_all_branches) ? '10' : '9' ?>" class="text-center py-4">
                                        <i class="bi bi-inbox fs-1 d-block text-muted mb-2"></i>
                                        <p class="text-muted mb-0">
                                            No trip tickets found
                                            <?php if ($tt_branch_column_exists && !$view_all_branches): ?>
                                                for your branch
                                            <?php endif; ?>
                                        </p>
                                    </td>
                                </tr>
                                <?php else: ?>
                                    <?php foreach ($trip_tickets as $ticket):
                                        // Determine driver display
                                        $driver_display = $ticket['picklist_driver_name'] ?: $ticket['driver_name'] ?: 'Unassigned';
                                    ?>
                                    <tr class="trip-row" 
                                        data-id="<?= $ticket['trip_id'] ?>"
                                        data-trip-number="<?= htmlspecialchars($ticket['trip_number']) ?>"
                                        data-driver="<?= htmlspecialchars($driver_display) ?>"
                                        data-status="<?= $ticket['trip_status'] ?>"
                                        data-branch="<?= $ticket['branch_id'] ?? '' ?>">
                                        <td>
                                            <input type="checkbox" class="form-check-input ticket-checkbox" value="<?= $ticket['trip_id'] ?>">
                                        </td>
                                        <td><strong><?= htmlspecialchars($ticket['trip_number']) ?></strong></td>
                                        <td>
                                            <?php if ($ticket['so_number']): ?>
                                                <?= htmlspecialchars($ticket['so_number']) ?>
                                            <?php else: ?>
                                                <span class="text-muted">N/A</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($ticket['pick_list_number']): ?>
                                                <?= htmlspecialchars($ticket['pick_list_number']) ?>
                                            <?php else: ?>
                                                <span class="text-muted">N/A</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="driver-info">
                                                <?= htmlspecialchars($driver_display) ?>
                                            </div>
                                        </td>
                                        <td><?= htmlspecialchars($ticket['branch_name']) ?></td>
                                        <?php if ($tt_branch_column_exists && $view_all_branches): ?>
                                            <td>
                                                <span class="badge bg-info">
                                                    <?= $ticket['branch_id'] ?? 'N/A' ?>
                                                </span>
                                            </td>
                                        <?php endif; ?>
                                        <td><?= formatDateOnly($ticket['trip_date']) ?></td>
                                        <td>
                                            <span class="status-badge <?= getTripStatusClass($ticket['trip_status']) ?>">
                                                <?= getTripStatusText($ticket['trip_status']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="btn-action btn-view" onclick="viewTripTicket('<?= htmlspecialchars($ticket['trip_number']) ?>')" title="View">
                                                    <i class="bi bi-eye"></i>
                                                </button>
                                                <button class="btn-action btn-edit" onclick="editTripTicket('<?= htmlspecialchars($ticket['trip_number']) ?>')" title="Edit">
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                                <?php if ($ticket['trip_status'] != 'completed'): ?>
                                                <button class="btn-action btn-finalize" onclick="finalizeTripTicket(<?= $ticket['trip_id'] ?>)" title="Finalize">
                                                    <i class="bi bi-check-circle"></i>
                                                </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
            </div>
        </div>
    </div>

    <!-- View Modal -->
    <div class="modal fade" id="viewModal" tabindex="-1" aria-labelledby="viewModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-lg-custom">
            <div class="modal-content action-modal">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title" id="viewModalLabel"><i class="bi bi-eye me-2"></i>Trip Ticket Details</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="ticket-details-grid" id="ticketDetails">
                        <!-- Details will be populated by JavaScript -->
                    </div>
                    <div class="row mt-3">
                        <div class="col-md-6">
                            <h6><i class="bi bi-camera me-2"></i>Photo Proof</h6>
                            <div class="row">
                                <div class="col-md-12 mb-3">
                                    <div class="detail-card">
                                        <div class="detail-label">Delivery Photo (1)</div>
                                        <div class="photo-view" id="photoProof1">
                                            <div id="photoPreview1" class="text-center"></div>
                                            <div id="photoPlaceholder1" class="text-center text-muted py-3">
                                                <i class="bi bi-image" style="font-size: 40px;"></i>
                                                <p class="mt-2">No photo available</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <h6><i class="bi bi-clock-history me-2"></i>Timestamps</h6>
                            <div class="detail-card">
                                <div class="row">
                                    <div class="col-md-12">
                                        <div class="detail-label">Created At</div>
                                        <div class="detail-value" id="viewCreatedAt"></div>
                                    </div>
                                    <div class="col-md-12 mt-2">
                                        <div class="detail-label">Updated At</div>
                                        <div class="detail-value" id="viewUpdatedAt"></div>
                                    </div>
                                </div>
                                <?php if ($tt_branch_column_exists && $view_all_branches): ?>
                                <div class="row mt-2">
                                    <div class="col-md-12">
                                        <div class="detail-label">Branch</div>
                                        <div class="detail-value" id="viewBranch"></div>
                                    </div>
                                </div>
                                <?php endif; ?>
                                <div class="row mt-2">
                                    <div class="col-md-12">
                                        <div class="detail-label">Remarks</div>
                                        <div class="detail-value" id="viewRemarks"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-warning" onclick="editCurrentTicket()">Edit</button>
                    <button type="button" class="btn btn-success" onclick="finalizeCurrentTicket()">Finalize</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Modal -->
    <div class="modal fade" id="editModal" tabindex="-1" aria-labelledby="editModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-warning">
                    <h5 class="modal-title" id="editModalLabel"><i class="bi bi-pencil me-2"></i>Edit Trip Ticket</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="editForm">
                        <input type="hidden" id="editTripId">
                        <?php if ($tt_branch_column_exists && !$view_all_branches): ?>
                            <input type="hidden" name="branch_id" value="<?= $branch_id ?>">
                        <?php endif; ?>
                        
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="editTripNumber" class="form-label">Trip Number</label>
                                <input type="text" class="form-control" id="editTripNumber" readonly>
                            </div>
                            <div class="col-md-6">
                                <label for="editDriverName" class="form-label">Driver (from pick list)</label>
                                <input type="text" class="form-control" id="editDriverName" readonly>
                                <small class="text-muted">Driver is managed in the pick list</small>
                            </div>
                            <div class="col-md-6">
                                <label for="editSONumber" class="form-label">Sales Order</label>
                                <input type="text" class="form-control" id="editSONumber" readonly>
                            </div>
                            <div class="col-md-6">
                                <label for="editPickListNumber" class="form-label">Pick List</label>
                                <input type="text" class="form-control" id="editPickListNumber" readonly>
                            </div>
                            <div class="col-md-6">
                                <label for="editStatus" class="form-label">Status</label>
                                <select class="form-select" id="editStatus">
                                    <option value="planned">Pending</option>
                                    <option value="in-progress">In Transit</option>
                                    <option value="completed">Delivered</option>
                                    <option value="cancelled">Cancelled</option>
                                    <option value="delayed">Delayed</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="editTripDate" class="form-label">Trip Date</label>
                                <input type="date" class="form-control" id="editTripDate">
                            </div>
                            <div class="col-md-4">
                                <label for="editTotalStops" class="form-label">Total Stops</label>
                                <input type="number" class="form-control" id="editTotalStops" min="0">
                            </div>
                            <div class="col-md-4">
                                <label for="editTotalDelivered" class="form-label">Delivered</label>
                                <input type="number" class="form-control" id="editTotalDelivered" min="0">
                            </div>
                            <div class="col-md-4">
                                <label for="editTotalFailed" class="form-label">Failed</label>
                                <input type="number" class="form-control" id="editTotalFailed" min="0">
                            </div>
                            <div class="col-md-12">
                                <label for="editRemarks" class="form-label">Remarks</label>
                                <textarea class="form-control" id="editRemarks" rows="2"></textarea>
                            </div>
                        </div>
                        
                        <?php if ($tt_branch_column_exists && $view_all_branches): ?>
                        <div class="row g-3 mt-2">
                            <div class="col-md-6">
                                <label for="editBranch" class="form-label">Branch</label>
                                <input type="text" class="form-control" id="editBranch" readonly>
                            </div>
                        </div>
                        <?php endif; ?>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="saveEdit()">Save Changes</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Create Modal -->
    <div class="modal fade" id="createModal" tabindex="-1" aria-labelledby="createModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title" id="createModalLabel"><i class="bi bi-plus-circle me-2"></i>Create New Trip Ticket</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="createForm">
                        <?php if ($tt_branch_column_exists && !$view_all_branches): ?>
                            <input type="hidden" name="branch_id" value="<?= $branch_id ?>">
                            <div class="alert alert-info mb-3">
                                <i class="bi bi-info-circle me-2"></i>
                                Creating trip ticket for Branch <?= $branch_id ?>
                            </div>
                        <?php endif; ?>
                        
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="createSOId" class="form-label">Sales Order *</label>
                                <select class="form-select" id="createSOId" required onchange="loadPickListForSO()">
                                    <option value="">Select Sales Order</option>
                                    <?php
                                    $so_query = "SELECT so_id, so_number FROM sales_orders WHERE order_status IN ('confirmed', 'processing')";
                                    if (!$view_all_branches) {
                                        $so_query .= " AND branch_id = $branch_id";
                                    }
                                    $so_query .= " ORDER BY so_number DESC";
                                    $so_result = $conn->query($so_query);
                                    while ($so = $so_result->fetch_assoc()):
                                    ?>
                                    <option value="<?= $so['so_id'] ?>"><?= htmlspecialchars($so['so_number']) ?></option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="createPickListId" class="form-label">Pick List *</label>
                                <select class="form-select" id="createPickListId" required>
                                    <option value="">Select Pick List</option>
                                </select>
                                <small class="text-muted">Driver will be taken from the selected pick list</small>
                            </div>
                            <div class="col-md-6">
                                <label for="createTripDate" class="form-label">Trip Date *</label>
                                <input type="date" class="form-control" id="createTripDate" required>
                            </div>
                            <div class="col-md-6">
                                <label for="createStatus" class="form-label">Status *</label>
                                <select class="form-select" id="createStatus" required>
                                    <option value="planned">Pending</option>
                                    <option value="in-progress">In Transit</option>
                                    <option value="completed">Delivered</option>
                                    <option value="cancelled">Cancelled</option>
                                    <option value="delayed">Delayed</option>
                                </select>
                            </div>
                            <div class="col-md-12">
                                <label for="createRemarks" class="form-label">Remarks</label>
                                <textarea class="form-control" id="createRemarks" rows="3"></textarea>
                            </div>
                        </div>
                        <div class="alert alert-info mt-3">
                            <i class="bi bi-info-circle me-2"></i>
                            Fields marked with * are required. The driver will be automatically assigned from the selected pick list.
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-success" onclick="createNewTripTicket()">Create Ticket</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Finalize Modal -->
    <div class="modal fade" id="finalizeModal" tabindex="-1" aria-labelledby="finalizeModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-warning">
                    <h5 class="modal-title" id="finalizeModalLabel"><i class="bi bi-exclamation-triangle me-2"></i>Finalize Trip Ticket</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to finalize this trip ticket?</p>
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-circle me-2"></i>
                        <strong>Warning:</strong> Once finalized, no further changes can be made to this trip ticket.
                    </div>
                    <div class="ticket-info mb-3 p-2 bg-light rounded">
                        <strong>Trip Number:</strong> <span id="finalizeTripNumber"></span><br>
                        <strong>Driver:</strong> <span id="finalizeDriverName"></span><br>
                        <strong>Status:</strong> <span id="finalizeStatus"></span>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-warning" onclick="confirmFinalize()">Finalize Ticket</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title" id="deleteModalLabel"><i class="bi bi-trash me-2"></i>Confirm Delete</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete the selected trip ticket(s)?</p>
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-circle me-2"></i>
                        <strong>Warning:</strong> This action cannot be undone.
                    </div>
                    <p><strong id="deleteCount">0</strong> ticket(s) selected for deletion.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" onclick="confirmDelete()">Delete Selected</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
    // ========== GLOBAL VARIABLES ==========
    let currentTicket = null;
    let tripTickets = <?= json_encode($trip_tickets) ?>;
    const branchId = <?php echo $branch_id; ?>;
    const viewAllBranches = <?php echo $view_all_branches ? 'true' : 'false'; ?>;
    const ttBranchColumnExists = <?php echo $tt_branch_column_exists ? 'true' : 'false'; ?>;
    const driversBranchColumnExists = <?php echo $drivers_branch_column_exists ? 'true' : 'false'; ?>;
    let selectedTrips = [];

    // ========== SIDEBAR FUNCTIONS ==========
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

    // ========== SHOW LOADING ==========
    function showLoading() {
        Swal.fire({
            title: 'Processing...',
            text: 'Please wait',
            allowOutsideClick: false,
            didOpen: () => {
                Swal.showLoading();
            }
        });
    }

    // ========== TRIP TICKET FUNCTIONS ==========
    document.addEventListener('DOMContentLoaded', function() {
        console.log("Trip Tickets - Live Database Mode");
        console.log("Branch ID:", branchId);
        console.log("View All Branches:", viewAllBranches);
        console.log("Trip Tickets Branch Column Exists:", ttBranchColumnExists);
        console.log("Drivers Branch Column Exists:", driversBranchColumnExists);
        
        initializeSidebar();
        
        const mobileMenuBtn = document.getElementById('mobileMenuBtn');
        if (mobileMenuBtn) {
            mobileMenuBtn.addEventListener('click', function(e) {
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
            const mobileBtn = document.getElementById('mobileMenuBtn');
            const overlay = document.querySelector('.sidebar-overlay');
            const isMobile = window.innerWidth <= 992;
            
            if (isMobile && sidebar.classList.contains('active') && 
                !sidebar.contains(event.target) && 
                !mobileBtn.contains(event.target) &&
                !overlay?.contains(event.target)) {
                closeMobileSidebar();
            }
        });

        window.addEventListener('resize', handleSidebarResize);
        
        // Set default date for create modal
        const today = new Date();
        const formattedDate = today.toISOString().slice(0, 10);
        const createTripDate = document.getElementById('createTripDate');
        if (createTripDate) {
            createTripDate.value = formattedDate;
        }
    });

    // Filter trip tickets
    function filterTripTickets() {
        const searchText = document.getElementById('searchInput').value.toLowerCase();
        const statusFilter = document.getElementById('statusFilter').value;
        const driverFilter = document.getElementById('driverFilter').value;
        
        const rows = document.querySelectorAll('.trip-row');
        
        rows.forEach(row => {
            const tripNumber = row.dataset.tripNumber?.toLowerCase() || '';
            const driver = row.dataset.driver?.toLowerCase() || '';
            const status = row.dataset.status || '';
            
            let matchesSearch = searchText === '' || 
                tripNumber.includes(searchText) || 
                driver.includes(searchText);
            
            let matchesStatus = statusFilter === '' || status === statusFilter;
            let matchesDriver = driverFilter === '' || row.dataset.driver === driverFilter;
            
            if (matchesSearch && matchesStatus && matchesDriver) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
    }

    // Toggle select all checkboxes
    function toggleSelectAll() {
        const selectAll = document.getElementById('selectAll');
        const checkboxes = document.querySelectorAll('.ticket-checkbox');
        
        selectedTrips = [];
        checkboxes.forEach(checkbox => {
            checkbox.checked = selectAll.checked;
            if (selectAll.checked) {
                selectedTrips.push(checkbox.value);
            }
        });
    }

    // Update selected trips when checkbox clicked
    document.addEventListener('change', function(e) {
        if (e.target.classList.contains('ticket-checkbox')) {
            const checkbox = e.target;
            const tripId = checkbox.value;
            
            if (checkbox.checked) {
                if (!selectedTrips.includes(tripId)) {
                    selectedTrips.push(tripId);
                }
            } else {
                selectedTrips = selectedTrips.filter(id => id != tripId);
            }
            
            // Update select all checkbox
            const selectAll = document.getElementById('selectAll');
            const checkboxes = document.querySelectorAll('.ticket-checkbox');
            if (selectedTrips.length === checkboxes.length) {
                selectAll.checked = true;
                selectAll.indeterminate = false;
            } else if (selectedTrips.length === 0) {
                selectAll.checked = false;
                selectAll.indeterminate = false;
            } else {
                selectAll.indeterminate = true;
            }
        }
    });

    // View sales order
    function viewSalesOrder(soId) {
        window.location.href = 'sales_order.php?view=' + soId;
    }

    // View pick list
    function viewPickList(picklistId) {
        window.location.href = 'pick_list_items.php?view=' + picklistId;
    }

    // Load pick lists for selected SO
    function loadPickListForSO() {
        const soId = document.getElementById('createSOId').value;
        const picklistSelect = document.getElementById('createPickListId');
        
        if (!soId) {
            picklistSelect.innerHTML = '<option value="">Select Pick List</option>';
            return;
        }
        
        // Clear current options
        picklistSelect.innerHTML = '<option value="">Loading...</option>';
        
        // Fetch pick lists for this SO via AJAX
        fetch('get_picklists_for_so.php?so_id=' + soId)
            .then(response => response.json())
            .then(data => {
                let options = '<option value="">Select Pick List</option>';
                if (data.length > 0) {
                    data.forEach(pl => {
                        options += `<option value="${pl.pick_list_id}" data-driver="${pl.driver_name || 'Unassigned'}">${pl.pick_list_number} - Driver: ${pl.driver_name || 'Unassigned'}</option>`;
                    });
                } else {
                    options += '<option value="">No pick lists found</option>';
                }
                picklistSelect.innerHTML = options;
            })
            .catch(error => {
                console.error('Error:', error);
                picklistSelect.innerHTML = '<option value="">Error loading pick lists</option>';
            });
    }

    // View trip ticket
    function viewTripTicket(tripNumber) {
        const ticket = tripTickets.find(t => t.trip_number === tripNumber);
        if (!ticket) {
            Swal.fire('Error', 'Trip ticket not found', 'error');
            return;
        }
        
        currentTicket = ticket;
        
        // Determine the correct driver (pick list driver takes precedence)
        const driverName = ticket.picklist_driver_name || ticket.driver_name || 'Unassigned';
        const driverSource = ticket.picklist_driver_name ? '(from pick list)' : '(from trip ticket)';
        
        // Populate details grid
        const detailsGrid = document.getElementById('ticketDetails');
        detailsGrid.innerHTML = `
            <div class="detail-card">
                <div class="detail-label">Trip Number</div>
                <div class="detail-value">${ticket.trip_number}</div>
            </div>
            <div class="detail-card">
                <div class="detail-label">Driver</div>
                <div class="detail-value">${driverName} <small class="text-muted">${driverSource}</small></div>
            </div>
            <div class="detail-card">
                <div class="detail-label">Sales Order</div>
                <div class="detail-value">${ticket.so_number || 'N/A'}</div>
            </div>
            <div class="detail-card">
                <div class="detail-label">Pick List</div>
                <div class="detail-value">${ticket.pick_list_number || 'N/A'}</div>
            </div>
            <div class="detail-card">
                <div class="detail-label">Branch</div>
                <div class="detail-value">${ticket.branch_name || 'N/A'}</div>
            </div>
            <div class="detail-card">
                <div class="detail-label">Trip Date</div>
                <div class="detail-value">${formatDate(ticket.trip_date)}</div>
            </div>
            <div class="detail-card">
                <div class="detail-label">Status</div>
                <div class="detail-value"><span class="status-badge ${getStatusClass(ticket.trip_status)}">${getStatusText(ticket.trip_status)}</span></div>
            </div>
            <div class="detail-card">
                <div class="detail-label">Customer</div>
                <div class="detail-value">${ticket.customer_name || 'N/A'}</div>
            </div>
        `;
        
        // Populate timestamps
        document.getElementById('viewCreatedAt').textContent = ticket.created_at ? formatDateTime(ticket.created_at) : 'N/A';
        document.getElementById('viewUpdatedAt').textContent = ticket.updated_at ? formatDateTime(ticket.updated_at) : 'N/A';
        document.getElementById('viewRemarks').textContent = ticket.remarks || 'No remarks';
        
        if (ttBranchColumnExists && viewAllBranches) {
            document.getElementById('viewBranch').textContent = `${ticket.branch_name} (ID: ${ticket.branch_id})`;
        }
        
        // Load photo preview if exists
        const photoPreview = document.getElementById('photoPreview1');
        const photoPlaceholder = document.getElementById('photoPlaceholder1');
        if (ticket.photo_1) {
            photoPreview.innerHTML = `<img src="../uploads/trip_photos/${ticket.photo_1}" alt="Delivery Photo" style="max-width: 100%; max-height: 200px;" class="img-fluid rounded">`;
            if (photoPlaceholder) photoPlaceholder.style.display = 'none';
        } else {
            photoPreview.innerHTML = '';
            if (photoPlaceholder) photoPlaceholder.style.display = 'block';
        }
        
        // Show modal
        const modal = new bootstrap.Modal(document.getElementById('viewModal'));
        modal.show();
    }

    // Edit trip ticket
    function editTripTicket(tripNumber) {
        const ticket = tripTickets.find(t => t.trip_number === tripNumber);
        if (!ticket) {
            Swal.fire('Error', 'Trip ticket not found', 'error');
            return;
        }
        
        currentTicket = ticket;
        
        // Populate form fields
        document.getElementById('editTripId').value = ticket.trip_id || '';
        document.getElementById('editTripNumber').value = ticket.trip_number;
        document.getElementById('editDriverName').value = ticket.picklist_driver_name || ticket.driver_name || 'Unassigned';
        document.getElementById('editSONumber').value = ticket.so_number || 'N/A';
        document.getElementById('editPickListNumber').value = ticket.pick_list_number || 'N/A';
        document.getElementById('editStatus').value = ticket.trip_status;
        document.getElementById('editTripDate').value = ticket.trip_date;
        document.getElementById('editTotalStops').value = ticket.actual_stops || ticket.total_stops || 0;
        document.getElementById('editTotalDelivered').value = ticket.actual_delivered || ticket.total_delivered || 0;
        document.getElementById('editTotalFailed').value = ticket.actual_failed || ticket.total_failed || 0;
        document.getElementById('editRemarks').value = ticket.remarks || '';
        
        if (ttBranchColumnExists && viewAllBranches) {
            document.getElementById('editBranch').value = ticket.branch_name || '';
        }
        
        // Show modal
        const modal = new bootstrap.Modal(document.getElementById('editModal'));
        modal.show();
    }

    // Save edit
    function saveEdit() {
        const tripId = document.getElementById('editTripId').value;
        const tripStatus = document.getElementById('editStatus').value;
        const tripDate = document.getElementById('editTripDate').value;
        const totalStops = document.getElementById('editTotalStops').value;
        const totalDelivered = document.getElementById('editTotalDelivered').value;
        const totalFailed = document.getElementById('editTotalFailed').value;
        const remarks = document.getElementById('editRemarks').value;
        
        if (!tripDate) {
            Swal.fire('Warning', 'Trip date is required', 'warning');
            return;
        }
        
        showLoading();
        
        const formData = new FormData();
        formData.append('action', 'update_trip');
        formData.append('trip_id', tripId);
        formData.append('trip_status', tripStatus);
        formData.append('trip_date', tripDate);
        formData.append('total_stops', totalStops);
        formData.append('total_delivered', totalDelivered);
        formData.append('total_failed', totalFailed);
        formData.append('remarks', remarks);
        
        fetch('trip_tickets.php', {
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
                    timer: 2000,
                    showConfirmButton: false
                }).then(() => {
                    bootstrap.Modal.getInstance(document.getElementById('editModal')).hide();
                    location.reload();
                });
            } else {
                Swal.fire('Error', data.message, 'error');
            }
        })
        .catch(error => {
            Swal.close();
            Swal.fire('Error', 'An error occurred while updating the trip ticket', 'error');
        });
    }

    // Finalize trip ticket
    function finalizeTripTicket(tripId) {
        showLoading();
        
        const formData = new FormData();
        formData.append('action', 'finalize_trip');
        formData.append('trip_id', tripId);
        
        fetch('trip_tickets.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            Swal.close();
            
            if (data.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Finalized!',
                    text: data.message,
                    timer: 2000,
                    showConfirmButton: false
                }).then(() => {
                    location.reload();
                });
            } else {
                Swal.fire('Error', data.message, 'error');
            }
        })
        .catch(error => {
            Swal.close();
            Swal.fire('Error', 'An error occurred while finalizing the trip ticket', 'error');
        });
    }

    // Show create modal
    function showCreateModal() {
        const modal = new bootstrap.Modal(document.getElementById('createModal'));
        modal.show();
    }

    // Create new trip ticket
    function createNewTripTicket() {
        const soId = document.getElementById('createSOId').value;
        const picklistId = document.getElementById('createPickListId').value;
        const tripDate = document.getElementById('createTripDate').value;
        const status = document.getElementById('createStatus').value;
        const remarks = document.getElementById('createRemarks').value;
        
        if (!soId) {
            Swal.fire('Warning', 'Please select a sales order', 'warning');
            return;
        }
        
        if (!picklistId) {
            Swal.fire('Warning', 'Please select a pick list', 'warning');
            return;
        }
        
        if (!tripDate) {
            Swal.fire('Warning', 'Trip date is required', 'warning');
            return;
        }
        
        showLoading();
        
        const formData = new FormData();
        formData.append('action', 'create_trip');
        formData.append('so_id', soId);
        formData.append('picklist_id', picklistId);
        formData.append('trip_date', tripDate);
        formData.append('trip_status', status);
        formData.append('remarks', remarks);
        
        fetch('trip_tickets.php', {
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
                    timer: 2000,
                    showConfirmButton: false
                }).then(() => {
                    bootstrap.Modal.getInstance(document.getElementById('createModal')).hide();
                    location.reload();
                });
            } else {
                Swal.fire('Error', data.message, 'error');
            }
        })
        .catch(error => {
            Swal.close();
            Swal.fire('Error', 'An error occurred while creating the trip ticket', 'error');
        });
    }

    // Edit current ticket from view modal
    function editCurrentTicket() {
        bootstrap.Modal.getInstance(document.getElementById('viewModal')).hide();
        setTimeout(() => {
            if (currentTicket) {
                editTripTicket(currentTicket.trip_number);
            }
        }, 300);
    }

    // Finalize current ticket from view modal
    function finalizeCurrentTicket() {
        bootstrap.Modal.getInstance(document.getElementById('viewModal')).hide();
        setTimeout(() => {
            if (currentTicket) {
                finalizeTripTicket(currentTicket.trip_id);
            }
        }, 300);
    }

    // Delete selected tickets
    function deleteSelected() {
        if (selectedTrips.length === 0) {
            Swal.fire('Warning', 'Please select at least one ticket to delete', 'warning');
            return;
        }
        
        document.getElementById('deleteCount').textContent = selectedTrips.length;
        const modal = new bootstrap.Modal(document.getElementById('deleteModal'));
        modal.show();
    }

    // Confirm delete
    function confirmDelete() {
        showLoading();
        
        const formData = new FormData();
        formData.append('action', 'delete_trips');
        formData.append('trip_ids', selectedTrips.join(','));
        
        fetch('trip_tickets.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            Swal.close();
            
            if (data.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Deleted!',
                    text: data.message,
                    timer: 2000,
                    showConfirmButton: false
                }).then(() => {
                    bootstrap.Modal.getInstance(document.getElementById('deleteModal')).hide();
                    location.reload();
                });
            } else {
                Swal.fire('Error', data.message, 'error');
            }
        })
        .catch(error => {
            Swal.close();
            Swal.fire('Error', 'An error occurred while deleting the trip tickets', 'error');
        });
    }

    // Refresh trip tickets
    function refreshTripTickets() {
        location.reload();
    }

    // Print trip tickets
    function printTripTickets() {
        window.print();
    }

    // ========== EXCEL EXPORT FUNCTION ==========
    function exportToExcel() {
        const rows = document.querySelectorAll('.trip-row:not([style*="display: none"])');
        if (rows.length === 0) {
            Swal.fire('Warning', 'No trip tickets to export', 'warning');
            return;
        }
        
        // Prepare data array for Excel
        const excelData = [];
        
        // Add headers
        const headers = [
            'Trip Number',
            'SO Number',
            'Pick List',
            'Driver',
            'Branch',
            ...(ttBranchColumnExists && viewAllBranches ? ['Branch ID'] : []),
            'Trip Date',
            'Status'
        ];
        excelData.push(headers);

        // Add data rows
        rows.forEach(row => {
            if (row.style.display !== 'none') {
                const cells = row.querySelectorAll('td');
                let cellIndex = 0;
                
                // Skip checkbox column
                cellIndex++;
                
                const tripNumber = cells[cellIndex++]?.innerText || '';
                const soNumber = cells[cellIndex++]?.innerText || '';
                const pickList = cells[cellIndex++]?.innerText || '';
                
                // Driver info
                const driverCell = cells[cellIndex++];
                const driverName = driverCell?.querySelector('.driver-info')?.innerText.trim().split('\n')[0] || driverCell?.innerText || '';
                
                const branch = cells[cellIndex++]?.innerText || '';
                
                let branchId = '';
                if (ttBranchColumnExists && viewAllBranches) {
                    branchId = cells[cellIndex++]?.innerText || '';
                }
                
                const tripDate = cells[cellIndex++]?.innerText || '';
                const status = cells[cellIndex++]?.innerText || '';
                
                const rowData = [
                    tripNumber,
                    soNumber,
                    pickList,
                    driverName,
                    branch,
                    ...(ttBranchColumnExists && viewAllBranches ? [branchId] : []),
                    tripDate,
                    status
                ];
                
                excelData.push(rowData);
            }
        });

        // Create workbook and worksheet
        const wb = XLSX.utils.book_new();
        const ws = XLSX.utils.aoa_to_sheet(excelData);

        // Set column widths
        const colWidths = [
            { wch: 15 }, // Trip Number
            { wch: 15 }, // SO Number
            { wch: 15 }, // Pick List
            { wch: 20 }, // Driver
            { wch: 15 }, // Branch
            ...(ttBranchColumnExists && viewAllBranches ? [{ wch: 10 }] : []), // Branch ID
            { wch: 15 }, // Trip Date
            { wch: 15 }  // Status
        ];
        ws['!cols'] = colWidths;

        // Add worksheet to workbook
        XLSX.utils.book_append_sheet(wb, ws, 'Trip Tickets');

        // Generate filename with current date and branch info
        const date = new Date();
        const dateStr = date.toISOString().slice(0,10).replace(/-/g, '');
        let filename = `Trip_Tickets_${dateStr}`;
        if (ttBranchColumnExists && !viewAllBranches) {
            filename += `_Branch_${branchId}`;
        }
        filename += '.xlsx';

        // Export Excel file
        XLSX.writeFile(wb, filename);
        
        Swal.fire({
            icon: 'success',
            title: 'Export Complete',
            text: 'Excel export completed successfully!',
            timer: 2000,
            showConfirmButton: false
        });
    }

    // ========== COPY SQL FUNCTION ==========
    function copySQL(table) {
        let sql = '';
        if (table === 'trip_tickets') {
            sql = "ALTER TABLE trip_tickets ADD COLUMN branch_id INT NULL;\nALTER TABLE trip_tickets ADD FOREIGN KEY (branch_id) REFERENCES branches(branch_id);";
        } else if (table === 'drivers') {
            sql = "ALTER TABLE drivers ADD COLUMN branch_id INT NULL;\nALTER TABLE drivers ADD FOREIGN KEY (branch_id) REFERENCES branches(branch_id);";
        }
        
        navigator.clipboard.writeText(sql).then(() => {
            Swal.fire({
                icon: 'success',
                title: 'Copied!',
                text: 'SQL copied to clipboard',
                timer: 1500,
                showConfirmButton: false
            });
        });
    }

    // Helper functions
    function getStatusClass(status) {
        const classes = {
            'planned': 'bg-warning text-dark',
            'pending': 'bg-warning text-dark',
            'in-progress': 'bg-primary text-white',
            'completed': 'bg-success text-white',
            'cancelled': 'bg-danger text-white',
            'delayed': 'bg-info text-white'
        };
        return classes[status] || 'bg-secondary text-white';
    }

    function getStatusText(status) {
        const texts = {
            'planned': 'Pending',
            'pending': 'Pending',
            'in-progress': 'In Transit',
            'completed': 'Delivered',
            'cancelled': 'Cancelled',
            'delayed': 'Delayed'
        };
        return texts[status] || status;
    }

    function formatCompletion(percentage) {
        if (percentage === null || percentage === undefined) return '0%';
        return parseFloat(percentage).toFixed(1) + '%';
    }
    
    function formatDate(dateStr) {
        if (!dateStr) return '';
        const date = new Date(dateStr);
        return date.toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric'
        });
    }
    
    function formatDateTime(dateStr) {
        if (!dateStr) return '';
        const date = new Date(dateStr);
        return date.toLocaleString('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
    }

    // Logout function
    function logout() {
        Swal.fire({
            title: 'Are you sure?',
            text: 'You will be logged out of the system',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#0d6efd',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Yes, logout'
        }).then((result) => {
            if (result.isConfirmed) {
                localStorage.removeItem('sidebarCollapsed');
                window.location.href = '../logout.php';
            }
        });
    }

    // Keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        if (e.ctrlKey && e.key === 'b' && window.innerWidth > 992) {
            e.preventDefault();
            toggleSidebar();
        } else if (e.key === 'Escape' && window.innerWidth <= 992) {
            closeMobileSidebar();
        } else if (e.ctrlKey && e.key === 'n') {
            e.preventDefault();
            showCreateModal();
        } else if (e.ctrlKey && e.key === 'f') {
            e.preventDefault();
            document.getElementById('searchInput')?.focus();
        }
    });
    </script>
</body>
</html>