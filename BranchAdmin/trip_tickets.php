<?php
require_once '../config/database.php';
require_once '../config/session_handler.php';

// Get current user info and branch context
$user_id = $_SESSION['user_id'] ?? 0;
$user_name = isset($_SESSION['first_name']) ? $_SESSION['first_name'] . ' ' . $_SESSION['last_name'] : 'Branch Admin';
$user_role = isset($_SESSION['role']) ? $_SESSION['role'] : 'branch_admin';
$branch_id = $_SESSION['branch_id'] ?? 0;
$view_all_branches = $_SESSION['view_all_branches'] ?? false;

// Get base64 encoded logo for printing
$logo_path = '../Pictures/amgc3DLogo.png';
$logo_base64 = '';
if (file_exists($logo_path)) {
    $image_data = file_get_contents($logo_path);
    $logo_base64 = 'data:image/png;base64,' . base64_encode($image_data);
}

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
        
        // UPDATE TRIP TICKET
        if ($_POST['action'] === 'update_trip') {
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
        
        // PRINT TRIP TICKETS
        elseif ($_POST['action'] === 'print_trip_tickets') {
            $filter_data = json_decode($_POST['filter_data'] ?? '{}', true);
            
            // Build query based on filters
            $print_query = "
                SELECT 
                    tt.trip_id,
                    tt.trip_number,
                    tt.so_id,
                    tt.picklist_id,
                    tt.driver_id as trip_driver_id,
                    COALESCE(pl.driver_name, d.driver_name) as driver_name,
                    COALESCE(pl.vehicle_plate_number, d.vehicle_plate_number) as vehicle_plate_number,
                    tt.branch_id,
                    b.branch_name,
                    tt.trip_date,
                    tt.trip_status,
                    tt.total_stops,
                    tt.total_delivered,
                    tt.total_failed,
                    tt.remarks,
                    tt.created_at,
                    so.so_number,
                    so.order_status,
                    c.customer_name,
                    GROUP_CONCAT(DISTINCT i.item_name SEPARATOR ', ') as item_names,
                    GROUP_CONCAT(DISTINCT i.item_code SEPARATOR ', ') as item_codes,
                    pl.pick_list_number,
                    pl.driver_name as picklist_driver_name
                FROM trip_tickets tt
                LEFT JOIN drivers d ON tt.driver_id = d.driver_id
                LEFT JOIN branches b ON tt.branch_id = b.branch_id
                LEFT JOIN sales_orders so ON tt.so_id = so.so_id
                LEFT JOIN customers c ON so.customer_id = c.customer_id
                LEFT JOIN pick_list_items pli ON tt.picklist_id = pli.pick_list_id
                LEFT JOIN items i ON pli.item_id = i.item_id
                LEFT JOIN (
                    SELECT 
                        pl.pick_list_id,
                        pl.pick_list_number,
                        pl.driver_id,
                        d.driver_name,
                        d.vehicle_plate_number
                    FROM pick_lists pl
                    LEFT JOIN drivers d ON pl.driver_id = d.driver_id
                ) pl ON tt.picklist_id = pl.pick_list_id
                WHERE 1=1
            ";
            
            // Apply filters
            if (!empty($filter_data['status']) && $filter_data['status'] !== '') {
                $print_query .= " AND tt.trip_status = '" . $conn->real_escape_string($filter_data['status']) . "'";
            }
            
            if (!empty($filter_data['driver']) && $filter_data['driver'] !== '') {
                $print_query .= " AND COALESCE(pl.driver_name, d.driver_name) LIKE '%" . $conn->real_escape_string($filter_data['driver']) . "%'";
            }
            
            if (!$view_all_branches && $tt_branch_column_exists) {
                $print_query .= " AND tt.branch_id = $branch_id";
            }
            
            $print_query .= " GROUP BY tt.trip_id ORDER BY tt.trip_date DESC, tt.trip_id DESC";
            
            $print_result = $conn->query($print_query);
            $print_items = $print_result ? $print_result->fetch_all(MYSQLI_ASSOC) : [];
            
            echo json_encode([
                'success' => true,
                'items' => $print_items,
                'branch_name' => $branch_id ? ('Branch ' . $branch_id) : 'All Branches',
                'view_all' => $view_all_branches,
                'tt_branch_column_exists' => $tt_branch_column_exists
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
        GROUP_CONCAT(DISTINCT i.item_name SEPARATOR ', ') as item_names,
        GROUP_CONCAT(DISTINCT i.item_code SEPARATOR ', ') as item_codes,
        COUNT(DISTINCT i.item_id) as item_count,
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
    LEFT JOIN pick_list_items pli ON tt.picklist_id = pli.pick_list_id
    LEFT JOIN items i ON pli.item_id = i.item_id
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
    GROUP BY tt.trip_id
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
        'planned', 'pending' => 'status-pending',
        'in-progress' => 'status-in-progress',
        'completed' => 'status-completed',
        'cancelled' => 'status-cancelled',
        'delayed' => 'status-delayed',
        default => 'status-default'
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
        
        /* Status text styling - no boxes */
        .status-text {
            display: inline-block;
            font-size: 13px;
            font-weight: 500;
        }
        
        .status-pending {
            color: #856404;
        }
        
        .status-in-progress {
            color: #004085;
        }
        
        .status-completed {
            color: #155724;
        }
        
        .status-cancelled {
            color: #721c24;
        }
        
        .status-delayed {
            color: #0c5460;
        }
        
        .status-default {
            color: #6c757d;
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

        /* Item name styling - truncated in table, full on hover */
        .item-names {
            font-size: 12px;
            max-width: 200px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            cursor: help;
            position: relative;
        }
        
        .item-names:hover {
            white-space: normal;
            overflow: visible;
            background-color: #f8f9fa;
            position: absolute;
            z-index: 1000;
            padding: 8px;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
            max-width: 300px;
            word-wrap: break-word;
        }
        
        .item-names small {
            color: #6c757d;
            display: block;
            font-size: 10px;
            margin-top: 4px;
        }

        /* Button styling - matching refresh button */
        .btn-outline-primary {
            color: #0d6efd;
            border-color: #0d6efd;
            background-color: transparent;
            transition: all 0.2s ease-in-out;
        }
        
        .btn-outline-primary:hover {
            color: #fff;
            background-color: #0d6efd;
            border-color: #0d6efd;
            transform: translateY(-1px);
            box-shadow: 0 2px 4px rgba(13, 110, 253, 0.2);
        }
        
        .btn-outline-primary:active {
            transform: translateY(0);
            box-shadow: none;
        }
        
        .btn-outline-primary:disabled {
            color: #6c757d;
            border-color: #dee2e6;
            background-color: #e9ecef;
            pointer-events: none;
            opacity: 0.65;
        }

        /* Print Frame */
        #printFrame {
            position: absolute;
            left: -9999px;
            top: -9999px;
            width: 1px;
            height: 1px;
            opacity: 0;
            pointer-events: none;
        }

        /* Compact print styles - only logo has color */
        @media print {
            @page {
                size: landscape;
                margin: 0.3in;
            }
            
            body * {
                visibility: hidden;
                background: white !important;
                color: black !important;
                border-color: black !important;
            }
            
            #printFrame, #printFrame * {
                visibility: visible;
            }
            
            #printFrame {
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
                height: auto;
                border: none;
            }
            
            /* Only keep the logo colored */
            #printFrame img {
                filter: none !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            
            /* Everything else black and white */
            #printFrame * {
                background: white !important;
                color: black !important;
                border-color: #000 !important;
                box-shadow: none !important;
                text-shadow: none !important;
                -webkit-print-color-adjust: economy;
                print-color-adjust: economy;
            }
            
            /* Table borders in black */
            #printFrame table, 
            #printFrame th, 
            #printFrame td {
                border: 1px solid #000 !important;
            }
            
            /* Header background to white with black text */
            #printFrame th {
                background: white !important;
                color: black !important;
                font-weight: bold;
            }
            
            /* Remove any gradient backgrounds */
            #printFrame .summary-box,
            #printFrame .customer-section,
            #printFrame .total-row {
                background: white !important;
                border: 1px solid #000 !important;
            }
            
            /* Show full items in print */
            #printFrame .item-names {
                white-space: normal !important;
                overflow: visible !important;
                max-width: none !important;
                position: static !important;
                padding: 0 !important;
                border: none !important;
                box-shadow: none !important;
                background: transparent !important;
            }
        }
    </style>
</head>
<body>
    <!-- Loading Overlay -->
    <div id="loadingOverlay" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(255,255,255,0.8); z-index: 9999; justify-content: center; align-items: center;">
        <div class="spinner-border text-primary" style="width: 3rem; height: 3rem;" role="status">
            <span class="visually-hidden">Loading...</span>
        </div>
    </div>

    <!-- Print Frame (hidden) -->
    <iframe id="printFrame" name="printFrame"></iframe>

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
                            <span class="nav-text">Users</span>
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

                <!-- Filter Section -->
                <div class="row g-3 mb-4">
                    <div class="col-12">
                        <div class="form-card">
                            <div class="row g-3 align-items-center">
                                <div class="col-md-4">
                                    <div class="search-box">
                                        <i class="bi bi-search"></i>
                                        <input type="text" class="form-control" placeholder="Search trip number, driver, SO, pick list, item..." id="searchInput" onkeyup="filterTripTickets()">
                                    </div>
                                </div>
                                <div class="col-md-3">
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
                                <div class="col-md-2">
                                    <!-- Empty for spacing -->
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
                            <button class="btn btn-sm btn-outline-primary" onclick="printTripTickets()">
                                <i class="bi bi-printer"></i> Print
                            </button>
                            <button class="btn btn-sm btn-outline-success" onclick="exportToExcel()">
                                <i class="bi bi-file-earmark-excel"></i> Export
                            </button>
                            <button class="btn btn-sm btn-outline-danger" onclick="deleteSelected()">
                                <i class="bi bi-trash"></i> Delete
                            </button>
                        </div>
                    </div>
                    <div class="table-container">
                        <table class="table custom-table compact-table" id="tripTicketsTable">
                            <thead>
                                <tr>
                                    <th width="40">
                                        <input type="checkbox" class="form-check-input" id="selectAll" onclick="toggleSelectAll()">
                                    </th>
                                    <th width="110">Trip Number</th>
                                    <th width="110">SO Number</th>
                                    <th width="110">Pick List</th>
                                    <th width="200">Items</th>
                                    <th width="130">Driver</th>
                                    <th width="90">Branch</th>
                                    <?php if ($tt_branch_column_exists && $view_all_branches): ?>
                                        <th width="70">Branch ID</th>
                                    <?php endif; ?>
                                    <th width="90">Trip Date</th>
                                    <th width="80">Status</th>
                                    <th width="100">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="tripTicketsTableBody">
                                <?php if (empty($trip_tickets)): ?>
                                <tr>
                                    <td colspan="<?= ($tt_branch_column_exists && $view_all_branches) ? '12' : '11' ?>" class="text-center py-4">
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
                                        // Get item names
                                        $item_names = $ticket['item_names'] ?? 'No items';
                                        $item_codes = $ticket['item_codes'] ?? '';
                                        $item_count = $ticket['item_count'] ?? 0;
                                        
                                        // Create truncated display for table
                                        $truncated_items = $item_names;
                                        if (strlen($truncated_items) > 50) {
                                            $truncated_items = substr($truncated_items, 0, 47) . '...';
                                        }
                                    ?>
                                    <tr class="trip-row" 
                                        data-id="<?= $ticket['trip_id'] ?>"
                                        data-trip-number="<?= htmlspecialchars($ticket['trip_number']) ?>"
                                        data-driver="<?= htmlspecialchars($driver_display) ?>"
                                        data-status="<?= $ticket['trip_status'] ?>"
                                        data-branch="<?= $ticket['branch_id'] ?? '' ?>"
                                        data-so-number="<?= htmlspecialchars($ticket['so_number'] ?? '') ?>"
                                        data-picklist="<?= htmlspecialchars($ticket['pick_list_number'] ?? '') ?>"
                                        data-items="<?= htmlspecialchars($item_names) ?>"
                                        data-date="<?= $ticket['trip_date'] ?? '' ?>">
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
                                            <div class="item-names" title="<?= htmlspecialchars($item_names) ?>">
                                                <?= htmlspecialchars($truncated_items) ?>
                                                <?php if ($item_count > 1): ?>
                                                    <small><?= $item_count ?> items</small>
                                                <?php endif; ?>
                                            </div>
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
                                            <span class="status-text <?= getTripStatusClass($ticket['trip_status']) ?>">
                                                <?= getTripStatusText($ticket['trip_status']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="btn-action btn-view" onclick='viewTripTicket("<?= htmlspecialchars($ticket['trip_number']) ?>")' title="View">
                                                    <i class="bi bi-eye"></i>
                                                </button>
                                                <button class="btn-action btn-edit" onclick='editTripTicket("<?= htmlspecialchars($ticket['trip_number']) ?>")' title="Edit">
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
    const logoBase64 = '<?php echo $logo_base64; ?>';
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
        document.getElementById('loadingOverlay').style.display = 'flex';
    }

    function hideLoading() {
        document.getElementById('loadingOverlay').style.display = 'none';
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
            const soNumber = row.dataset.soNumber?.toLowerCase() || '';
            const picklist = row.dataset.picklist?.toLowerCase() || '';
            const items = row.dataset.items?.toLowerCase() || '';
            
            let matchesSearch = searchText === '' || 
                tripNumber.includes(searchText) || 
                driver.includes(searchText) ||
                soNumber.includes(searchText) ||
                picklist.includes(searchText) ||
                items.includes(searchText);
            
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
        
        // Format items for display
        const itemsDisplay = ticket.item_names || 'No items';
        const itemsCodeDisplay = ticket.item_codes ? `<br><small class="text-muted">${ticket.item_codes}</small>` : '';
        
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
                <div class="detail-label">Items</div>
                <div class="detail-value">${itemsDisplay}${itemsCodeDisplay}</div>
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
                <div class="detail-value"><span class="status-text ${getStatusClass(ticket.trip_status)}">${getStatusText(ticket.trip_status)}</span></div>
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
            hideLoading();
            
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
            hideLoading();
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
            hideLoading();
            
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
            hideLoading();
            Swal.fire('Error', 'An error occurred while finalizing the trip ticket', 'error');
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
            hideLoading();
            
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
            hideLoading();
            Swal.fire('Error', 'An error occurred while deleting the trip tickets', 'error');
        });
    }

    // Refresh trip tickets
    function refreshTripTickets() {
        location.reload();
    }

    // ========== PRINT FUNCTION - FIXED VERSION ==========
    function printTripTickets() {
        // Show loading indicator on button
        const printBtn = document.querySelector('.btn-outline-primary[onclick="printTripTickets()"]');
        if (printBtn) {
            const originalText = printBtn.innerHTML;
            printBtn.innerHTML = '<i class="bi bi-printer"></i> Preparing...';
            printBtn.disabled = true;
        }

        // Get current filter values
        const statusFilter = document.getElementById('statusFilter').value;
        const driverFilter = document.getElementById('driverFilter').value;
        
        const filterData = {
            status: statusFilter || '',
            driver: driverFilter || '',
            branch: 'all'
        };
        
        showLoading();
        
        // Fetch filtered data from server
        const formData = new FormData();
        formData.append('action', 'print_trip_tickets');
        formData.append('filter_data', JSON.stringify(filterData));
        
        fetch('trip_tickets.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            hideLoading();
            
            if (data.success) {
                const items = data.items;
                
                if (items.length === 0) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'No Data',
                        text: 'No trip tickets match the current filters',
                        confirmButtonColor: '#0d6efd'
                    });
                    return;
                }
                
                // Generate compact HTML
                const htmlContent = generatePrintHTML(items);
                
                // Use hidden iframe for printing
                const iframe = document.getElementById('printFrame');
                const iframeDoc = iframe.contentWindow.document;
                
                iframeDoc.open();
                iframeDoc.write(htmlContent);
                iframeDoc.close();
                
                // Restore button
                setTimeout(() => {
                    if (printBtn) {
                        printBtn.innerHTML = '<i class="bi bi-printer"></i> Print';
                        printBtn.disabled = false;
                    }
                }, 1000);
                
                // Trigger print dialog
                setTimeout(() => {
                    iframe.contentWindow.focus();
                    iframe.contentWindow.print();
                }, 250);
            } else {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: data.message || 'Failed to load trip ticket data',
                    confirmButtonColor: '#0d6efd'
                });
                if (printBtn) {
                    printBtn.innerHTML = '<i class="bi bi-printer"></i> Print';
                    printBtn.disabled = false;
                }
            }
        })
        .catch(error => {
            hideLoading();
            console.error('Error:', error);
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: 'An error occurred while preparing print: ' + error.message,
                confirmButtonColor: '#0d6efd'
            });
            if (printBtn) {
                printBtn.innerHTML = '<i class="bi bi-printer"></i> Print';
                printBtn.disabled = false;
            }
        });
    }

    // Compact HTML generator for trip tickets print - shows full items
    function generatePrintHTML(items) {
        let tableRows = '';
        let totalStops = 0;
        let totalDelivered = 0;
        
        items.forEach(item => {
            const driverName = item.picklist_driver_name || item.driver_name || 'Unassigned';
            totalStops += parseInt(item.actual_stops || item.total_stops || 0);
            totalDelivered += parseInt(item.actual_delivered || item.total_delivered || 0);
            
            // Format date for display
            let tripDate = item.trip_date || '';
            if (tripDate) {
                const date = new Date(tripDate);
                tripDate = date.toLocaleDateString('en-US', {
                    year: 'numeric',
                    month: 'short',
                    day: 'numeric'
                });
            }
            
            // Show full item names in print (no truncation)
            let itemNames = item.item_names || 'No items';
            
            tableRows += '<tr>';
            tableRows += `<td style="padding: 3px; border: 1px solid #000;">${item.trip_number || ''}</td>`;
            tableRows += `<td style="padding: 3px; border: 1px solid #000;">${item.so_number || 'N/A'}</td>`;
            tableRows += `<td style="padding: 3px; border: 1px solid #000;">${item.pick_list_number || 'N/A'}</td>`;
            tableRows += `<td style="padding: 3px; border: 1px solid #000;">${itemNames}</td>`;
            tableRows += `<td style="padding: 3px; border: 1px solid #000;">${driverName}</td>`;
            tableRows += `<td style="padding: 3px; border: 1px solid #000;">${item.branch_name || 'N/A'}</td>`;
            if (ttBranchColumnExists && viewAllBranches) {
                tableRows += `<td style="padding: 3px; border: 1px solid #000; text-align: center;">${item.branch_id || 'N/A'}</td>`;
            }
            tableRows += `<td style="padding: 3px; border: 1px solid #000;">${tripDate}</td>`;
            tableRows += `<td style="padding: 3px; border: 1px solid #000;">${getStatusText(item.trip_status)}</td>`;
            tableRows += '</tr>';
        });
        
        const currentDate = new Date().toLocaleDateString('en-US', { 
            year: 'numeric', 
            month: 'short', 
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
        
        return `
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset="UTF-8">
                <title>Trip Tickets Report</title>
                <style>
                    body { font-family: Arial, sans-serif; margin: 0; padding: 0; font-size: 9px; }
                    .print-container { max-width: 100%; margin: 0; }
                    .print-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 5px; border-bottom: 1px solid #000; padding-bottom: 3px; }
                    .logo-section { display: flex; align-items: center; gap: 5px; }
                    .company-logo { width: 30px; height: auto; }
                    .company-info h1 { font-size: 14px; margin: 0; font-weight: bold; }
                    .company-info p { font-size: 8px; margin: 0; }
                    .report-title h2 { font-size: 12px; margin: 0; }
                    .report-title .date-info { font-size: 8px; }
                    .summary-box { border: 1px solid #000; padding: 3px; margin-bottom: 5px; display: flex; }
                    .summary-item { flex: 1; text-align: center; border-right: 1px solid #000; }
                    .summary-item:last-child { border-right: none; }
                    .summary-label { font-size: 8px; font-weight: bold; }
                    .summary-value { font-size: 11px; font-weight: bold; }
                    table { width: 100%; border-collapse: collapse; font-size: 8px; }
                    th { border: 1px solid #000; padding: 3px; text-align: left; font-weight: bold; background: white !important; color: black !important; }
                    td { border: 1px solid #000; padding: 3px; }
                    .print-badge { border: 1px solid #000; padding: 2px 4px; font-size: 8px; }
                    .total-row { font-weight: bold; }
                    .print-footer { margin-top: 5px; border-top: 1px solid #000; padding-top: 3px; display: flex; justify-content: space-between; font-size: 8px; }
                </style>
            </head>
            <body>
                <div class="print-container">
                    <div class="print-header">
                        <div class="logo-section">
                            <img src="${logoBase64}" alt="AMGC Logo" class="company-logo">
                            <div class="company-info">
                                <h1>AMGC</h1>
                                <p>Trip Tickets Report</p>
                            </div>
                        </div>
                        <div class="report-title">
                            <h2>TRIP TICKETS</h2>
                            <div class="date-info">${currentDate}</div>
                        </div>
                    </div>
                    
                    <div class="summary-box">
                        <div class="summary-item"><div class="summary-label">Total Trips</div><div class="summary-value">${items.length}</div></div>
                        <div class="summary-item"><div class="summary-label">Total Stops</div><div class="summary-value">${totalStops}</div></div>
                        <div class="summary-item"><div class="summary-label">Total Delivered</div><div class="summary-value">${totalDelivered}</div></div>
                        <div class="summary-item"><div class="summary-label">Branch</div><div class="summary-value">${!viewAllBranches ? ('Branch ' + branchId) : 'All'}</div></div>
                    </div>
                    
                    <table>
                        <thead>
                            <tr>
                                <th>Trip #</th>
                                <th>SO #</th>
                                <th>Pick List</th>
                                <th>Items</th>
                                <th>Driver</th>
                                <th>Branch</th>
                                ${(ttBranchColumnExists && viewAllBranches) ? '<th>Branch ID</th>' : ''}
                                <th>Trip Date</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${tableRows}
                        </tbody>
                    </table>
                    
                    <div class="print-footer">
                        <div>Generated: ${currentDate}</div>
                        <div>${document.querySelector('.user-name-sidebar')?.textContent || 'Branch Admin'}</div>
                    </div>
                </div>
            </body>
            </html>
        `;
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
            'Items',
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
                
                // Items
                const itemsCell = cells[cellIndex++];
                const items = itemsCell?.querySelector('.item-names')?.innerText.trim() || itemsCell?.innerText || '';
                
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
                    items,
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
            { wch: 40 }, // Items (wider for full names)
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
            'planned': 'status-pending',
            'pending': 'status-pending',
            'in-progress': 'status-in-progress',
            'completed': 'status-completed',
            'cancelled': 'status-cancelled',
            'delayed': 'status-delayed'
        };
        return classes[status] || 'status-default';
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

    // Keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        if (e.ctrlKey && e.key === 'b' && window.innerWidth > 992) {
            e.preventDefault();
            toggleSidebar();
        } else if (e.key === 'Escape' && window.innerWidth <= 992) {
            closeMobileSidebar();
        } else if (e.ctrlKey && e.key === 'f') {
            e.preventDefault();
            document.getElementById('searchInput')?.focus();
        } else if (e.ctrlKey && e.key === 'p') {
            e.preventDefault();
            printTripTickets();
        }
    });
    </script>
</body>
</html>