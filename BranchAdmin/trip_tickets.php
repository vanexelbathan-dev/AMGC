<?php
require_once '../config/database.php';
require_once '../config/session_handler.php';

// Get current user info and branch context
$user_id = $_SESSION['user_id'] ?? 0;
$user_name = isset($_SESSION['first_name']) ? $_SESSION['first_name'] . ' ' . $_SESSION['last_name'] : 'Branch Admin';
$user_role = isset($_SESSION['role']) ? $_SESSION['role'] : 'branch_admin';
$branch_id = $_SESSION['branch_id'] ?? 0;
$view_all_branches = $_SESSION['view_all_branches'] ?? false;

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
    $user_initials = 'BA';
}

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
                    pl.driver_name as picklist_driver_name,
                    (SELECT COUNT(*) FROM deliveries WHERE trip_id = tt.trip_id) as actual_stops,
                    (SELECT COUNT(*) FROM deliveries WHERE trip_id = tt.trip_id AND delivery_status = 'delivered') as actual_delivered,
                    (SELECT COUNT(*) FROM deliveries WHERE trip_id = tt.trip_id AND delivery_status = 'rejected') as actual_failed
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
                        d.vehicle_plate_number,
                        d.vehicle_type
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

// ========== MODIFIED SQL: FETCH proof_delivery_photo FROM deliveries ==========
$trip_query = "
    SELECT 
        tt.trip_id,
        tt.trip_number,
        tt.so_id,
        tt.picklist_id,
        tt.driver_id as trip_driver_id,
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
        -- FIX: Get proof_delivery_photo from deliveries table (instead of photo_1 from trip_tickets)
        d_photo.proof_delivery_photo as photo_1,
        d_photo.remarks as delivery_remarks,
        d_photo.delivery_status,
        d_photo.signed_by,
        d_photo.delivery_date,
        -- Completion percentage from pick list items
        CASE 
            WHEN (SELECT SUM(quantity_to_pick) FROM pick_list_items WHERE pick_list_id = tt.picklist_id) > 0 
            THEN ROUND(
                (SELECT SUM(quantity_picked) FROM pick_list_items WHERE pick_list_id = tt.picklist_id) / 
                (SELECT SUM(quantity_to_pick) FROM pick_list_items WHERE pick_list_id = tt.picklist_id) * 100, 1)
            ELSE 0
        END as completion_percentage,
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
    -- JOIN deliveries to get the proof_delivery_photo (only one photo per trip, e.g., first delivery)
    LEFT JOIN (
        SELECT trip_id, proof_delivery_photo, remarks, delivery_status, signed_by, delivery_date,
               ROW_NUMBER() OVER (PARTITION BY trip_id ORDER BY delivery_id) as rn
        FROM deliveries 
        WHERE proof_delivery_photo IS NOT NULL AND proof_delivery_photo != ''
    ) d_photo ON tt.trip_id = d_photo.trip_id AND d_photo.rn = 1
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

// Helper functions
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
        <!-- Session Checker -->
    <script src="../js/session-checker.js"></script>
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
      /* ===== TRIP TICKETS STAT CARDS - SAME AS SALES ORDERS ===== */
/* Force remove white background and border from current_inventory.css */
.trip-stats-row .stat-card {
    background: transparent !important;
    border: none !important;
    box-shadow: 0 4px 10px rgba(0, 0, 0, 0.08) !important;
    min-height: auto !important;
    height: auto !important;
    padding: 0.8rem !important;
    transition: transform 0.2s ease, box-shadow 0.2s ease !important;
    cursor: default !important;
}

/* Force gradient backgrounds for each type */
.trip-stats-row .stat-card.total {
    background: linear-gradient(135deg, #047857, #059669) !important;
    border: none !important;
}

.trip-stats-row .stat-card.pending {
    background: linear-gradient(135deg, #047857, #059669) !important;
    border: none !important;
}

.trip-stats-row .stat-card.delivery {
    background: linear-gradient(135deg, #047857, #059669) !important;
    border: none !important;
}

.trip-stats-row .stat-card.complete {
    background: linear-gradient(135deg, #047857, #059669) !important;
    border: none !important;
}

/* Force text colors to white */
.trip-stats-row .stat-card .stat-value,
.trip-stats-row .stat-card .stat-label,
.trip-stats-row .stat-card .stat-content,
.trip-stats-row .stat-card small,
.trip-stats-row .stat-card small i,
.trip-stats-row .stat-card .badge {
    color: white !important;
}

/* Remove any white background from stat-content or other children */
.trip-stats-row .stat-card .stat-content,
.trip-stats-row .stat-card .stat-icon {
    background: transparent !important;
}

/* ===== MOBILE: SQUARE CARDS WITH CENTERED ICON ===== */
@media (max-width: 991px) {
    .trip-stats-row .stat-card {
        aspect-ratio: 1 / 1 !important;
        display: flex !important;
        flex-direction: column !important;
        justify-content: center !important;
        align-items: center !important;
        text-align: center !important;
        padding: 0.5rem !important;
    }
    
    /* Force icon to be centered */
    .trip-stats-row .stat-card i,
    .trip-stats-row .stat-card .stat-icon {
        display: block !important;
        text-align: center !important;
        margin: 0 auto 0.3rem auto !important;
        font-size: 1.6rem !important;
        width: auto !important;
        float: none !important;
        position: static !important;
        left: auto !important;
        right: auto !important;
        top: auto !important;
        bottom: auto !important;
    }
    
    .trip-stats-row .stat-card .stat-value {
        display: block !important;
        text-align: center !important;
        font-size: 1.2rem !important;
        font-weight: bold !important;
        line-height: 1.2 !important;
        margin: 0.2rem 0 !important;
        width: 100% !important;
    }
    
    .trip-stats-row .stat-card .stat-label {
        display: block !important;
        text-align: center !important;
        font-size: 0.7rem !important;
        font-weight: 500 !important;
        width: 100% !important;
    }
    
    .trip-stats-row .stat-card small {
        display: none !important;
    }
    
    /* Badge styling for mobile */
    .trip-stats-row .stat-card .badge {
        display: inline-block !important;
        font-size: 0.5rem !important;
        padding: 0.2rem 0.4rem !important;
        margin-top: 0.2rem !important;
        text-align: center !important;
    }
}

/* ===== DESKTOP: HORIZONTAL LAYOUT ===== */
@media (min-width: 992px) {
    .trip-stats-row .stat-card {
        align-items: flex-start !important;
        text-align: left !important;
        padding: 1rem !important;
        aspect-ratio: auto !important;
        min-height: 120px !important;
        max-height: 130px !important;
        display: flex !important;
        flex-direction: row !important;
        justify-content: flex-start !important;
    }
    
    .trip-stats-row .stat-card i,
    .trip-stats-row .stat-card .stat-icon {
        align-self: flex-start !important;
        margin: 0 0.75rem 0 0 !important;
        font-size: 1.6rem !important;
        display: inline-block !important;
        text-align: left !important;
    }
    
    .trip-stats-row .stat-card .stat-content {
        display: flex !important;
        flex-direction: column !important;
        align-items: flex-start !important;
        text-align: left !important;
        flex: 1 !important;
    }
    
    .trip-stats-row .stat-card .stat-value {
        align-self: flex-start !important;
        margin: 0 0 0.05rem 0 !important;
        font-size: 1.4rem !important;
        line-height: 1.2 !important;
        text-align: left !important;
    }
    
    .trip-stats-row .stat-card .stat-label {
        align-self: flex-start !important;
        margin-top: 0.1rem !important;
        font-size: 0.75rem !important;
        font-weight: 500 !important;
        text-align: left !important;
    }
    
    .trip-stats-row .stat-card small {
        align-self: flex-start !important;
        margin-top: 0.2rem !important;
        display: block !important;
        font-size: 0.65rem !important;
        opacity: 0.9 !important;
        text-align: left !important;
    }
}

/* ===== TABLET (768px - 991px) ===== */
@media (min-width: 768px) and (max-width: 991px) {
    .trip-stats-row .stat-card i,
    .trip-stats-row .stat-card .stat-icon {
        font-size: 1.4rem !important;
        margin-bottom: 0.25rem !important;
    }
    
    .trip-stats-row .stat-card .stat-value {
        font-size: 1rem !important;
    }
    
    .trip-stats-row .stat-card .stat-label {
        font-size: 0.6rem !important;
    }
}

/* ===== EXTRA SMALL MOBILE (below 400px) ===== */
@media (max-width: 399px) {
    .trip-stats-row .stat-card {
        padding: 0.3rem !important;
    }
    
    .trip-stats-row .stat-card i,
    .trip-stats-row .stat-card .stat-icon {
        font-size: 1.2rem !important;
        margin-bottom: 0.2rem !important;
    }
    
    .trip-stats-row .stat-card .stat-value {
        font-size: 0.9rem !important;
    }
    
    .trip-stats-row .stat-card .stat-label {
        font-size: 0.55rem !important;
    }
}

/* ===== LANDSCAPE MODE ===== */
@media (max-height: 500px) and (orientation: landscape) {
    .trip-stats-row .stat-card {
        aspect-ratio: auto !important;
        min-height: 55px !important;
        max-height: 70px !important;
        padding: 0.3rem !important;
        flex-direction: row !important;
        align-items: center !important;
    }
    
    .trip-stats-row .stat-card i,
    .trip-stats-row .stat-card .stat-icon {
        font-size: 1rem !important;
        margin: 0 0.5rem 0 0 !important;
    }
    
    .trip-stats-row .stat-card .stat-value {
        font-size: 0.75rem !important;
    }
    
    .trip-stats-row .stat-card .stat-label {
        font-size: 0.5rem !important;
    }
    
    .trip-stats-row .stat-card small {
        display: none !important;
    }
}

/* Row styling for trip stat cards */
.trip-stats-row {
    margin-bottom: 1.5rem;
}

/* Hover effect for stat cards */
.trip-stats-row .stat-card:hover {
    transform: translateY(-2px) !important;
    box-shadow: 0 6px 15px rgba(0, 0, 0, 0.15) !important;
}
/* ===== TRIP TICKETS FILTER SECTION ===== */
.supplier-filter-card {
    background: white;
    border-radius: 12px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
    border: 1px solid #e2e8f0;
    margin-bottom: 1.5rem;
    overflow: hidden;
}

.supplier-filter-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.875rem 1.25rem;
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
    cursor: pointer;
}

.supplier-filter-header h5 {
    margin: 0;
    font-size: 0.95rem;
    font-weight: 600;
    color: #1e293b;
    display: flex;
    align-items: center;
    gap: 8px;
}

.supplier-filter-header h5 i {
    color: #44d34e;
    font-size: 1rem;
}

.supplier-filter-toggle-btn {
    background: transparent;
    border: none;
    color: #64748b;
    width: 32px;
    height: 32px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.2s ease;
}

.supplier-filter-toggle-btn i {
    font-size: 1rem;
    transition: transform 0.3s ease;
}

.supplier-filter-toggle-btn:hover {
    background: rgba(68, 211, 78, 0.1);
}

.supplier-filter-toggle-btn[aria-expanded="true"] i {
    transform: rotate(180deg);
}

.supplier-filter-content {
    transition: all 0.3s ease-in-out;
    overflow: hidden;
}

.supplier-filter-content.collapsed {
    display: none;
}

.supplier-filter-content:not(.collapsed) {
    display: block;
    padding: 1.25rem;
}

/* ONE LINE LAYOUT (DESKTOP) */
.supplier-filter-one-line {
    display: flex;
    align-items: flex-end;
    gap: 1rem;
    flex-wrap: nowrap;
}

.filter-item {
    flex: 1;
    min-width: 0;
}

.filter-item.search-item {
    flex: 1.5;
}

.supplier-filter-label {
    font-weight: 600;
    color: #1e293b;
    margin-bottom: 0.35rem;
    font-size: 0.7rem;
    display: block;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.supplier-filter-select,
.supplier-filter-input {
    width: 100%;
    border: 1px solid #dee2e6;
    border-radius: 8px;
    padding: 0.5rem 0.75rem;
    font-size: 0.85rem;
    transition: all 0.2s ease;
    background-color: #fff;
    height: 40px;
}

.supplier-filter-select:focus,
.supplier-filter-input:focus {
    border-color: #44d34e;
    outline: none;
    box-shadow: 0 0 0 3px rgba(68, 211, 78, 0.1);
}

.supplier-search-wrapper {
    position: relative;
    width: 100%;
}

.supplier-search-wrapper .search-icon {
    position: absolute;
    left: 0.75rem;
    top: 50%;
    transform: translateY(-50%);
    color: #94a3b8;
    font-size: 0.9rem;
    pointer-events: none;
}

.supplier-search-wrapper .supplier-filter-input {
    padding-left: 2.25rem;
}

/* MOBILE: STACK VERTICALLY */
@media (max-width: 992px) {
    .supplier-filter-one-line {
        flex-direction: column;
        align-items: stretch;
        gap: 0.75rem;
    }
    
    .filter-item,
    .filter-item.search-item {
        width: 100%;
    }
}

@media (max-width: 768px) {
    .supplier-filter-content:not(.collapsed) {
        padding: 1rem;
    }
    
    .supplier-filter-select,
    .supplier-filter-input {
        height: 36px;
        font-size: 0.8rem;
        padding: 0.4rem 0.6rem;
    }
    
    .supplier-filter-label {
        font-size: 0.65rem;
        margin-bottom: 0.25rem;
    }
}
/* Add this to your CSS - make all rows clickable */
.trip-row {
    cursor: pointer;
    transition: background-color 0.2s ease;
}

.trip-row:hover {
    background-color: #f8f9fa !important;
}

/* Remove any button-related styles that might interfere */
.btn-action {
    display: none !important;
}

/* Hide action buttons container completely */
.action-buttons {
    display: none !important;
}
@media (max-width: 576px) {
    .supplier-filter-content:not(.collapsed) {
        padding: 0.85rem;
    }
    
    .supplier-filter-header {
        padding: 0.7rem 1rem;
    }
    
    .supplier-filter-header h5 {
        font-size: 0.85rem;
    }
    
    .supplier-filter-select,
    .supplier-filter-input {
        height: 34px;
        font-size: 0.75rem;
    }
}
/* ===== TRIP TICKETS CARD VIEW - WITH TAP TO VIEW ===== */
@media (max-width: 768px) {
    #tripTicketsTable tbody tr {
        display: block !important;
        background: white !important;
        border-radius: 12px !important;
        margin-bottom: 12px !important;
        padding: 12px !important;
        padding-bottom: 28px !important;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1) !important;
        border: 1px solid #e9ecef !important;
        position: relative !important;
        cursor: pointer !important;
    }
    
    /* Checkbox */
    #tripTicketsTable tbody tr td:first-child {
        position: absolute !important;
        top: 12px !important;
        left: 12px !important;
        width: auto !important;
        padding: 0 !important;
    }
    
    /* Trip Number - GREEN TEXT ONLY */
    #tripTicketsTable tbody tr td:nth-child(2) {
        display: block !important;
        margin-bottom: 8px !important;
        padding: 0 !important;
        margin-left: 30px !important;
    }
    
    #tripTicketsTable tbody tr td:nth-child(2) strong {
        font-size: 12px !important;
        color: #047857 !important;
        font-weight: 600 !important;
        background: transparent !important;
        padding: 0 !important;
    }
    
    /* Hide SO and Pick List */
    #tripTicketsTable tbody tr td:nth-child(3),
    #tripTicketsTable tbody tr td:nth-child(4) {
        display: none !important;
    }
    
    /* Items */
    #tripTicketsTable tbody tr td:nth-child(5) {
        display: block !important;
        margin-bottom: 10px !important;
        padding: 0 !important;
    }
    
    /* Driver cell */
    #tripTicketsTable tbody tr td:nth-child(6) {
        display: inline-block !important;
        margin-right: 12px !important;
        padding: 0 !important;
        font-size: 12px !important;
    }
    
    #tripTicketsTable tbody tr td:nth-child(6)::before {
        content: "Driver: " !important;
        font-weight: 600 !important;
        color: #6c757d !important;
    }
    
    /* Branch cell */
    #tripTicketsTable tbody tr td:nth-child(7) {
        display: inline-block !important;
        margin-right: 12px !important;
        padding: 0 !important;
        font-size: 12px !important;
    }
    
    #tripTicketsTable tbody tr td:nth-child(7)::before {
        content: "Branch: " !important;
        font-weight: 600 !important;
        color: #6c757d !important;
    }
    
    /* Date cell */
    #tripTicketsTable tbody tr td:nth-child(8) {
        display: inline-block !important;
        margin-right: 12px !important;
        padding: 0 !important;
        font-size: 12px !important;
    }
    
    #tripTicketsTable tbody tr td:nth-child(8)::before {
        content: "Date: " !important;
        font-weight: 600 !important;
        color: #6c757d !important;
    }
    
    /* Status cell */
    #tripTicketsTable tbody tr td:nth-child(9) {
        display: inline-block !important;
        padding: 0 !important;
        font-size: 12px !important;
    }
    
    #tripTicketsTable tbody tr td:nth-child(9)::before {
        content: "Status: " !important;
        font-weight: 600 !important;
        color: #6c757d !important;
    }
    
    /* HIDE FINALIZE BUTTON on mobile */
    #tripTicketsTable tbody tr td:last-child .btn-finalize {
        display: none !important;
    }
    
    /* Actions - keep view and edit */
    #tripTicketsTable tbody tr td:last-child {
        position: absolute !important;
        top: 12px !important;
        right: 12px !important;
        padding: 0 !important;
        width: auto !important;
    }
    
    .action-buttons {
        display: flex !important;
        gap: 5px !important;
    }
    
    .btn-action {
        width: 30px !important;
        height: 30px !important;
        border-radius: 6px !important;
        background: #f8f9fa !important;
        border: 1px solid #e9ecef !important;
    }
    
    /* ADD "Tap to view details" at bottom right */
    #tripTicketsTable tbody tr::after {
        content: "Tap to view details" !important;
        position: absolute !important;
        bottom: 8px !important;
        right: 12px !important;
        font-size: 10px !important;
        color: #9ca3af !important;
        background: transparent !important;
        padding: 0 !important;
        pointer-events: none !important;
        display: block !important;
    }
    
    /* Line break after items */
    #tripTicketsTable tbody tr td:nth-child(5)::after {
        content: "" !important;
        display: block !important;
    }
}
/* ===== FORCE FILTER TO HIDE ROWS PROPERLY ON MOBILE ===== */
@media (max-width: 768px) {
    /* Ensure hidden rows are completely hidden */
    #tripTicketsTable tbody tr.trip-row[style*="display: none"],
    #tripTicketsTable tbody tr.trip-row[style="display: none"] {
        display: none !important;
    }
    
    /* Ensure visible rows are displayed as block */
    #tripTicketsTable tbody tr.trip-row:not([style*="display: none"]) {
        display: block !important;
    }
    
    /* Fix for when style is empty string (visible) */
    #tripTicketsTable tbody tr.trip-row[style=""] {
        display: block !important;
    }
    
    /* Fix for when style is not set */
    #tripTicketsTable tbody tr.trip-row:not([style]) {
        display: block !important;
    }
}
/* ===== HIDE TAP TO VIEW ON NO RESULT ROW - OVERRIDE ===== */
@media (max-width: 768px) {
    /* This specifically targets the no result row to hide the "Tap to view details" */
    #noResultMessage::after,
    #noResultMessage tr::after,
    tr#noResultMessage::after,
    #tripTicketsTable tbody tr#noResultMessage::after {
        display: none !important;
        content: none !important;
    }
    
    /* Also hide any pseudo-element on the td inside no result row */
    #noResultMessage td::after {
        display: none !important;
        content: none !important;
    }
    
    /* Make sure no result row doesn't show the tap indicator */
    #noResultMessage {
        cursor: default !important;
    }
    
    #noResultMessage td {
        cursor: default !important;
    }
}
/* ===== MODERN MODAL STYLING FOR TRIP TICKETS ===== */

/* View Modal */
#viewModal .modal-content {
    border: none !important;
    border-radius: 20px !important;
    overflow: hidden !important;
    box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15) !important;
    max-height: 90vh !important;
    display: flex !important;
    flex-direction: column !important;
}

#viewModal .modal-header {
    background: linear-gradient(135deg, #047857, #44D34E) !important;
    color: white !important;
    border-bottom: none !important;
    padding: 1rem 1.5rem !important;
    flex-shrink: 0 !important;
    position: relative !important;
}

#viewModal .modal-header .modal-title {
    font-weight: 600 !important;
    font-size: 1.2rem !important;
    display: flex !important;
    align-items: center !important;
    gap: 8px !important;
    color: white !important;
}

#viewModal .modal-header .modal-title i {
    font-size: 1.3rem !important;
    color: white !important;
}

/* Remove the duplicate close button - hide the one in footer */
#viewModal .modal-footer .btn-close {
    display: none !important;
}

/* Style the header close button - centered vertically */
#viewModal .modal-header .btn-close {
    filter: brightness(0) invert(1) !important;
    opacity: 0.8 !important;
    background: transparent !important;
    position: absolute !important;
    right: 1rem !important;
    top: 50% !important;
    transform: translateY(-50%) !important;
    margin: 0 !important;
    padding: 0.5rem !important;
}

#viewModal .modal-header .btn-close:hover {
    opacity: 1 !important;
    transform: translateY(-50%) rotate(90deg) !important;
    background: rgba(255, 255, 255, 0.1) !important;
    border-radius: 50% !important;
}

#viewModal .modal-body {
    padding: 1.5rem !important;
    overflow-y: auto !important;
    flex: 1 !important;
    background: #f8fafc !important;
}

#viewModal .modal-body::-webkit-scrollbar {
    width: 6px;
}

#viewModal .modal-body::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 3px;
}

#viewModal .modal-body::-webkit-scrollbar-thumb {
    background: #44D34E;
    border-radius: 3px;
}

#viewModal .modal-body::-webkit-scrollbar-thumb:hover {
    background: #047857;
}

#viewModal .modal-footer {
    border-top: 1px solid #e9ecef !important;
    padding: 1rem 1.5rem !important;
    background: white !important;
    flex-shrink: 0 !important;
    gap: 0.75rem !important;
    display: flex !important;
    flex-direction: row !important;
    justify-content: flex-end !important;
}

#viewModal .modal-footer .btn {
    padding: 0.5rem 1.25rem !important;
    font-size: 0.9rem !important;
    font-weight: 500 !important;
    border-radius: 8px !important;
    transition: all 0.2s ease !important;
    margin: 0 !important;
    flex: 0 0 auto !important;
}

#viewModal .modal-footer .btn-secondary {
    background: #6c757d !important;
    border: none !important;
    color: white !important;
}

#viewModal .modal-footer .btn-secondary:hover {
    background: #5a6268 !important;
    transform: translateY(-1px) !important;
}

#viewModal .modal-footer .btn-warning {
    background: linear-gradient(135deg, #f59e0b, #fbbf24) !important;
    border: none !important;
    color: white !important;
}

#viewModal .modal-footer .btn-warning:hover {
    transform: translateY(-1px) !important;
    box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3) !important;
}

#viewModal .modal-footer .btn-success {
    background: linear-gradient(135deg, #10b981, #34d399) !important;
    border: none !important;
    color: white !important;
}

#viewModal .modal-footer .btn-success:hover {
    transform: translateY(-1px) !important;
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3) !important;
}

/* Detail Cards inside View Modal */
#viewModal .ticket-details-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 1rem;
    margin-bottom: 1rem;
}

#viewModal .detail-card {
    background: white !important;
    border-radius: 12px !important;
    padding: 1rem !important;
    border: 1px solid #e9ecef !important;
    transition: all 0.2s ease !important;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05) !important;
}

#viewModal .detail-card:hover {
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08) !important;
    border-color: #d1fae5 !important;
}

#viewModal .detail-label {
    font-size: 0.7rem !important;
    font-weight: 600 !important;
    color: #6c757d !important;
    text-transform: uppercase !important;
    letter-spacing: 0.5px !important;
    margin-bottom: 0.25rem !important;
}

#viewModal .detail-value {
    font-size: 0.9rem !important;
    font-weight: 500 !important;
    color: #1f2937 !important;
    word-break: break-word !important;
}

/* Photo View */
#viewModal .photo-view {
    background: white !important;
    border-radius: 12px !important;
    padding: 1rem !important;
    border: 1px solid #e9ecef !important;
    min-height: 150px;
    display: flex;
    align-items: center;
    justify-content: center;
}

#viewModal .photo-view img {
    max-width: 100%;
    max-height: 200px;
    border-radius: 8px;
}

/* ===== EDIT MODAL - SAME COLOR AS VIEW MODAL ===== */
#editModal .modal-content {
    border: none !important;
    border-radius: 20px !important;
    overflow: hidden !important;
    box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15) !important;
    max-height: 90vh !important;
    display: flex !important;
    flex-direction: column !important;
}

#editModal .modal-header {
    background: linear-gradient(135deg, #047857, #44D34E) !important;
    color: white !important;
    border-bottom: none !important;
    padding: 1rem 1.5rem !important;
    flex-shrink: 0 !important;
    position: relative !important;
}

#editModal .modal-header .modal-title {
    font-weight: 600 !important;
    font-size: 1.2rem !important;
    display: flex !important;
    align-items: center !important;
    gap: 8px !important;
    color: white !important;
}

#editModal .modal-header .modal-title i {
    font-size: 1.3rem !important;
    color: white !important;
}

#editModal .modal-header .btn-close {
    filter: brightness(0) invert(1) !important;
    opacity: 0.8 !important;
    background: transparent !important;
    position: absolute !important;
    right: 1rem !important;
    top: 50% !important;
    transform: translateY(-50%) !important;
    margin: 0 !important;
    padding: 0.5rem !important;
}

#editModal .modal-header .btn-close:hover {
    opacity: 1 !important;
    transform: translateY(-50%) rotate(90deg) !important;
    background: rgba(255, 255, 255, 0.1) !important;
    border-radius: 50% !important;
}

#editModal .modal-body {
    padding: 1.5rem !important;
    overflow-y: auto !important;
    flex: 1 !important;
    background: #f8fafc !important;
}

#editModal .modal-body::-webkit-scrollbar {
    width: 6px;
}

#editModal .modal-body::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 3px;
}

#editModal .modal-body::-webkit-scrollbar-thumb {
    background: #44D34E;
    border-radius: 3px;
}

#editModal .modal-footer {
    border-top: 1px solid #e9ecef !important;
    padding: 1rem 1.5rem !important;
    background: white !important;
    flex-shrink: 0 !important;
    gap: 0.75rem !important;
    display: flex !important;
    flex-direction: row !important;
    justify-content: flex-end !important;
}

#editModal .modal-footer .btn {
    padding: 0.5rem 1.25rem !important;
    font-size: 0.9rem !important;
    font-weight: 500 !important;
    border-radius: 8px !important;
    margin: 0 !important;
    flex: 0 0 auto !important;
}

#editModal .modal-footer .btn-secondary {
    background: #6c757d !important;
    border: none !important;
    color: white !important;
}

#editModal .modal-footer .btn-secondary:hover {
    background: #5a6268 !important;
    transform: translateY(-1px) !important;
}

#editModal .modal-footer .btn-primary {
    background: linear-gradient(135deg, #047857, #44D34E) !important;
    border: none !important;
    color: white !important;
}

#editModal .modal-footer .btn-primary:hover {
    transform: translateY(-1px) !important;
    box-shadow: 0 4px 12px rgba(68, 211, 78, 0.3) !important;
}

/* Edit Modal Form Styling */
#editModal .form-label {
    font-weight: 600 !important;
    color: #374151 !important;
    margin-bottom: 0.35rem !important;
    font-size: 0.85rem !important;
}

#editModal .form-control,
#editModal .form-select {
    border: 1px solid #e2e8f0 !important;
    border-radius: 10px !important;
    padding: 0.6rem 0.85rem !important;
    font-size: 0.9rem !important;
    transition: all 0.2s ease !important;
    background-color: #ffffff !important;
}

#editModal .form-control:focus,
#editModal .form-select:focus {
    border-color: #44D34E !important;
    box-shadow: 0 0 0 3px rgba(68, 211, 78, 0.15) !important;
    outline: none !important;
}

#editModal .form-control:read-only {
    background-color: #f1f5f9 !important;
    cursor: not-allowed !important;
}

/* ===== FINALIZE MODAL ===== */
#finalizeModal .modal-content {
    border: none !important;
    border-radius: 20px !important;
    overflow: hidden !important;
    box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15) !important;
}

#finalizeModal .modal-header {
    background: linear-gradient(135deg, #f59e0b, #fbbf24) !important;
    color: white !important;
    border-bottom: none !important;
    padding: 1rem 1.5rem !important;
    position: relative !important;
}

#finalizeModal .modal-header .modal-title {
    font-weight: 600 !important;
    font-size: 1.1rem !important;
    display: flex !important;
    align-items: center !important;
    gap: 8px !important;
    color: white !important;
}

#finalizeModal .modal-header .btn-close {
    filter: brightness(0) invert(1) !important;
    opacity: 0.8 !important;
    background: transparent !important;
    position: absolute !important;
    right: 1rem !important;
    top: 50% !important;
    transform: translateY(-50%) !important;
    margin: 0 !important;
    padding: 0.5rem !important;
}

#finalizeModal .modal-header .btn-close:hover {
    opacity: 1 !important;
    transform: translateY(-50%) rotate(90deg) !important;
    background: rgba(255, 255, 255, 0.1) !important;
    border-radius: 50% !important;
}

#finalizeModal .modal-body {
    padding: 1.5rem !important;
    background: #f8fafc !important;
}

#finalizeModal .alert-warning {
    background: #fef3c7 !important;
    border-left: 4px solid #f59e0b !important;
    color: #92400e !important;
    border-radius: 12px !important;
}

#finalizeModal .ticket-info {
    background: white !important;
    border-radius: 12px !important;
    border: 1px solid #e9ecef !important;
}

#finalizeModal .modal-footer {
    border-top: 1px solid #e9ecef !important;
    padding: 1rem 1.5rem !important;
    background: white !important;
    gap: 0.75rem !important;
    display: flex !important;
    flex-direction: row !important;
    justify-content: flex-end !important;
}

#finalizeModal .modal-footer .btn-secondary {
    background: #6c757d !important;
    border: none !important;
    color: white !important;
}

#finalizeModal .modal-footer .btn-warning {
    background: linear-gradient(135deg, #f59e0b, #fbbf24) !important;
    border: none !important;
    color: white !important;
}

/* ===== DELETE MODAL ===== */
#deleteModal .modal-content {
    border: none !important;
    border-radius: 20px !important;
    overflow: hidden !important;
    box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15) !important;
}

#deleteModal .modal-header {
    background: linear-gradient(135deg, #dc3545, #f87171) !important;
    color: white !important;
    border-bottom: none !important;
    padding: 1rem 1.5rem !important;
    position: relative !important;
}

#deleteModal .modal-header .modal-title {
    font-weight: 600 !important;
    font-size: 1.1rem !important;
    display: flex !important;
    align-items: center !important;
    gap: 8px !important;
    color: white !important;
}

#deleteModal .modal-header .btn-close {
    filter: brightness(0) invert(1) !important;
    opacity: 0.8 !important;
    background: transparent !important;
    position: absolute !important;
    right: 1rem !important;
    top: 50% !important;
    transform: translateY(-50%) !important;
    margin: 0 !important;
    padding: 0.5rem !important;
}

#deleteModal .modal-header .btn-close:hover {
    opacity: 1 !important;
    transform: translateY(-50%) rotate(90deg) !important;
    background: rgba(255, 255, 255, 0.1) !important;
    border-radius: 50% !important;
}

#deleteModal .modal-body {
    padding: 1.5rem !important;
    background: #f8fafc !important;
}

#deleteModal .alert-warning {
    background: #fef3c7 !important;
    border-left: 4px solid #f59e0b !important;
    color: #92400e !important;
    border-radius: 12px !important;
}

#deleteModal .modal-footer {
    border-top: 1px solid #e9ecef !important;
    padding: 1rem 1.5rem !important;
    background: white !important;
    gap: 0.75rem !important;
    display: flex !important;
    flex-direction: row !important;
    justify-content: flex-end !important;
}

#deleteModal .modal-footer .btn-secondary {
    background: #6c757d !important;
    border: none !important;
    color: white !important;
}

#deleteModal .modal-footer .btn-danger {
    background: linear-gradient(135deg, #dc3545, #f87171) !important;
    border: none !important;
    color: white !important;
}

/* ===== MOBILE RESPONSIVE FOR MODALS ===== */
@media (max-width: 768px) {
    #viewModal .modal-dialog,
    #editModal .modal-dialog,
    #finalizeModal .modal-dialog,
    #deleteModal .modal-dialog {
        margin: 0.5rem !important;
        max-width: calc(100% - 1rem) !important;
    }
    
    #viewModal .modal-header,
    #editModal .modal-header,
    #finalizeModal .modal-header,
    #deleteModal .modal-header {
        padding: 0.85rem 1rem !important;
    }
    
    #viewModal .modal-header .modal-title,
    #editModal .modal-header .modal-title {
        font-size: 1rem !important;
    }
    
    #viewModal .modal-header .btn-close,
    #editModal .modal-header .btn-close,
    #finalizeModal .modal-header .btn-close,
    #deleteModal .modal-header .btn-close {
        right: 0.75rem !important;
    }
    
    #viewModal .modal-body,
    #editModal .modal-body,
    #finalizeModal .modal-body,
    #deleteModal .modal-body {
        padding: 1rem !important;
    }
    
    /* BUTTONS SIDE BY SIDE ON MOBILE TOO */
    #viewModal .modal-footer,
    #editModal .modal-footer,
    #finalizeModal .modal-footer,
    #deleteModal .modal-footer {
        padding: 0.75rem 1rem !important;
        flex-direction: row !important;
        flex-wrap: wrap !important;
        gap: 0.5rem !important;
    }
    
    #viewModal .modal-footer .btn,
    #editModal .modal-footer .btn,
    #finalizeModal .modal-footer .btn,
    #deleteModal .modal-footer .btn {
        flex: 1 !important;
        min-width: 100px !important;
        padding: 0.45rem 0.75rem !important;
        font-size: 0.85rem !important;
        justify-content: center !important;
    }
    
    #viewModal .ticket-details-grid {
        grid-template-columns: 1fr !important;
        gap: 0.75rem !important;
    }
    
    #viewModal .detail-card {
        padding: 0.75rem !important;
    }
    
    #viewModal .detail-label {
        font-size: 0.65rem !important;
    }
    
    #viewModal .detail-value {
        font-size: 0.85rem !important;
    }
}

@media (max-width: 480px) {
    #viewModal .modal-footer .btn,
    #editModal .modal-footer .btn {
        font-size: 0.75rem !important;
        padding: 0.4rem 0.5rem !important;
        min-width: 80px !important;
    }
    
    #viewModal .detail-card {
        padding: 0.6rem !important;
    }
    
    #viewModal .detail-label {
        font-size: 0.6rem !important;
    }
    
    #viewModal .detail-value {
        font-size: 0.8rem !important;
    }
}

/* Landscape mode */
@media (max-width: 768px) and (orientation: landscape) {
    #viewModal .modal-footer,
    #editModal .modal-footer,
    #finalizeModal .modal-footer,
    #deleteModal .modal-footer {
        flex-direction: row !important;
    }
    
    #viewModal .modal-footer .btn,
    #editModal .modal-footer .btn {
        flex: 0 0 auto !important;
        padding: 0.35rem 0.8rem !important;
        font-size: 0.75rem !important;
    }
}
/* ===== MOBILE BOTTOM NAVIGATION - FIXED DROPDOWN ===== */
.mobile-nav {
    position: fixed;
    bottom: 0;
    left: 0;
    right: 0;
    background: white;
    border-top: 1px solid #e5e7eb;
    box-shadow: 0 -2px 10px rgba(0, 0, 0, 0.05);
    z-index: 9999;
    display: none;
    padding: 8px 0 12px 0;
    overflow: visible !important;
}

@media (max-width: 992px) {
    .mobile-nav {
        display: block;
    }

    .main-content {
        padding-bottom: 80px !important;
    }
}

.mobile-nav .nav {
    display: flex;
    justify-content: space-around;
    align-items: center;
    margin: 0;
    padding: 0;
    list-style: none;
    overflow: visible !important;
    scrollbar-width: none;
}

.mobile-nav .nav::-webkit-scrollbar {
    display: none;
}

.mobile-nav .nav-item {
    position: relative;
    flex-shrink: 0;
    text-align: center;
    overflow: visible !important;
}

.mobile-nav .nav-link {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 6px 12px;
    color: #9ca3af;
    font-size: 0.7rem;
    text-decoration: none;
    border-radius: 12px;
    gap: 4px;
    white-space: nowrap;
    background: transparent;
    border: none;
    cursor: pointer;
}

.mobile-nav .nav-link i {
    font-size: 1.3rem;
    margin: 0;
}

.mobile-nav .nav-link span {
    font-size: 0.65rem;
    font-weight: 500;
}

.mobile-nav .nav-link.active {
    color: #059669;
    background: rgba(5, 150, 105, 0.1);
}

.mobile-nav .more-dropdown {
    position: absolute;
    bottom: calc(100% + 8px);
    left: 50%;
    transform: translateX(-50%) translateY(8px);
    background: white;
    border-radius: 12px;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
    border: 1px solid #e5e7eb;
    min-width: 180px;
    z-index: 10000;
    display: none !important;
    opacity: 0;
    visibility: hidden;
    pointer-events: none;
    transition: opacity 0.2s ease, transform 0.2s ease;
}

.mobile-nav .more-dropdown.show {
    display: block !important;
    opacity: 1 !important;
    visibility: visible !important;
    pointer-events: auto !important;
    transform: translateX(-50%) translateY(0) !important;
}

.mobile-nav .more-dropdown::before {
    content: '';
    position: absolute;
    bottom: -6px;
    left: 50%;
    transform: translateX(-50%) rotate(45deg);
    width: 12px;
    height: 12px;
    background: white;
    border-right: 1px solid #e5e7eb;
    border-bottom: 1px solid #e5e7eb;
}

.mobile-nav .dropdown-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 16px;
    color: #374151;
    text-decoration: none;
    transition: background 0.2s ease;
    border-bottom: 1px solid #f3f4f6;
    font-size: 0.85rem;
    background: white;
    width: 100%;
    text-align: left;
    cursor: pointer;
}

.mobile-nav .dropdown-item:last-child {
    border-bottom: none;
}

.mobile-nav .dropdown-item:hover {
    background: #f9fafb;
}

.mobile-nav .dropdown-item.active {
    background: rgba(5, 150, 105, 0.1);
    color: #059669;
}

.mobile-nav .dropdown-item i {
    width: 20px;
    font-size: 1rem;
    color: #6b7280;
}

.mobile-nav .dropdown-item.active i {
    color: #059669;
}

@media (max-width: 480px) {
    .mobile-nav .nav-link {
        padding: 4px 8px;
    }

    .mobile-nav .nav-link i {
        font-size: 1.1rem;
    }

    .mobile-nav .nav-link span {
        font-size: 0.55rem;
    }

    .mobile-nav .more-dropdown {
        min-width: 160px;
    }

    .mobile-nav .dropdown-item {
        padding: 10px 12px;
        font-size: 0.75rem;
    }
}
</style>
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
                <i class="bi bi-list" id="toggleIcon"></i>
            </button>
            <img src="../Pictures/amgc3DLogo.png" alt="Logo" class="logo-icon"> 
            <span class="nav-text">Branch Admin</span>
        </h3>
    </div>
    
    <div class="sidebar-content">
        <div class="sidebar-menu">
            <ul class="nav flex-column">
                <li class="nav-item">
                <a class="nav-link" href="branchdashboard.php">
                <i class="bi bi-speedometer2"></i>
                <span class="nav-text">Dashboard</span></a>
            </li>
                <!-- Warehouse Dropdown - walang dropdown-toggle class -->
<li class="nav-item dropdown-nav">
    <a class="nav-link" href="#" onclick="toggleSidebarDropdown(event, 'warehouseMenu')">
        <i class="bi bi-shop"></i>
        <span class="nav-text">Warehouse</span>
        <i class="bi bi-chevron-down dropdown-arrow"></i>
    </a>
    <div class="collapse" id="warehouseMenu">
        <ul class="nav flex-column ps-4">
            <li class="nav-item">
                <a class="nav-link active" href="current_inventory.php">
                        <i class="bi bi-bar-chart-line"></i>
                        <span class="nav-text">Current Inventory</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="bad_orders.php">
                    <i class="bi bi-recycle"></i>
                    <span class="nav-text">Bad Orders</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="pick_list_items.php">
                    <i class="bi bi-list-check"></i>
                    <span class="nav-text">Pick List Items</span>
                </a>
            </li>
                                            <li class="nav-item">
                                    <a class="nav-link" href="warehouses.php">
                                    <i class="bi bi-shop"></i>
                                    <span class="nav-text">Warehouses</span></a>
                                </li>
        </ul>
    </div>
</li>

                
                <!-- Supplier Dropdown - walang dropdown-toggle class -->
<li class="nav-item dropdown-nav">
    <a class="nav-link" href="#" onclick="toggleSidebarDropdown(event, 'supplierMenu')">
        <i class="bi bi-building"></i>
        <span class="nav-text">Supplier</span>
        <i class="bi bi-chevron-down dropdown-arrow"></i>
    </a>
    <div class="collapse" id="supplierMenu">
        <ul class="nav flex-column ps-4">
            <li class="nav-item">
                <a class="nav-link" href="purchase_order.php">
                    <i class="bi bi-box"></i>
                    <span class="nav-text">Receive Inventory</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="supplier.php">
                    <i class="bi bi-people"></i>
                    <span class="nav-text">Supplier List</span>
                </a>
            </li>
        </ul>
    </div>
</li>

<li class="nav-item dropdown-nav">
                            <a class="nav-link" href="#" onclick="toggleSidebarDropdown(event, 'customerMenu')">
                                <i class="bi bi-people"></i><span class="nav-text">Customer</span><i class="bi bi-chevron-down dropdown-arrow"></i>
                            </a>
                            <div class="collapse" id="customerMenu">
                                <ul class="nav flex-column ps-4">
                                    <li class="nav-item"><a class="nav-link" href="customer_list.php"><i class="bi bi-person-badge"></i><span class="nav-text">Customer List</span></a></li>
                                    <li class="nav-item"><a class="nav-link" href="approve_credit_requests.php"><i class="bi bi-pencil-square"></i><span class="nav-text">Approve Credit Request</span></a></li>
                                    <li class="nav-item"><a class="nav-link" href="sales_order.php"><i class="bi bi-cart"></i><span class="nav-text">Sales Order</span></a></li>
                                    <li class="nav-item"><a class="nav-link active" href="collections.php"><i class="bi bi-cash-stack"></i><span class="nav-text">Collections</span></a></li>
                                </ul>
                            </div>
                        </li>

<!-- Delivery Dropdown - walang dropdown-toggle class -->
<li class="nav-item dropdown-nav">
    <a class="nav-link" href="#" onclick="toggleSidebarDropdown(event, 'deliveryMenu')">
        <i class="bi bi-truck"></i>
        <span class="nav-text">Delivery</span>
        <i class="bi bi-chevron-down dropdown-arrow"></i>
    </a>
    <div class="collapse" id="deliveryMenu">
        <ul class="nav flex-column ps-4">
            <li class="nav-item">
                <a class="nav-link" href="trip_tickets.php">
                    <i class="bi bi-ticket-perforated"></i>
                    <span class="nav-text">Trip Tickets</span>
                </a>
            </li>
        </ul>
    </div>
</li>
                    <!-- Banking Dropdown -->
                    <li class="nav-item dropdown-nav">
                        <a class="nav-link" href="#" onclick="toggleSidebarDropdown(event, 'bankingMenu')">
                            <i class="bi bi-bank2"></i>
                            <span class="nav-text">Banking</span>
                            <i class="bi bi-chevron-down dropdown-arrow"></i>
                        </a>

                        <div class="collapse" id="bankingMenu">
                            <ul class="nav flex-column ps-4">
                                <li class="nav-item">
                                    <a class="nav-link" href="deposit.php">
                                        <i class="bi bi-arrow-down-circle"></i>
                                        <span class="nav-text">Deposit</span>
                                    </a>
                                </li>

                                <li class="nav-item">
                                    <a class="nav-link" href="Withdrawal.php">
                                        <i class="bi bi-arrow-up-circle"></i>
                                        <span class="nav-text">Withdrawal</span>
                                    </a>
                                </li>

                                <li class="nav-item">
                                    <a class="nav-link" href="bank_statement.php">
                                        <i class="bi bi-receipt"></i>
                                        <span class="nav-text">Bank Statement</span>
                                    </a>
                                </li>

                                <li class="nav-item">
                                    <a class="nav-link" href="expenses.php">
                                        <i class="bi bi-cash-stack"></i>
                                        <span class="nav-text">Expenses</span>
                                    </a>
                                </li>
                            </ul>
                        </div>
                    </li>
                    
                                    <!-- Shared Services Dropdown -->
<li class="nav-item dropdown-nav">
    <a class="nav-link" href="#" onclick="toggleSidebarDropdown(event, 'sharedServicesMenu')">
        <i class="bi bi-grid-3x3-gap"></i>
        <span class="nav-text">Shared Services</span>
        <i class="bi bi-chevron-down dropdown-arrow"></i>
    </a>
    <div class="collapse" id="sharedServicesMenu">
        <ul class="nav flex-column ps-4">
            <li class="nav-item">
                <a class="nav-link" href="motorpool.php">
                    <i class="bi bi-truck"></i>
                    <span class="nav-text">Motorpool</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="central_warehouse.php">
                    <i class="bi bi-box-seam"></i>
                    <span class="nav-text">Central Warehouse</span>
                </a>
            </li>
        </ul>
    </div>
</li>
                    
                <!-- Users -->
                <li class="nav-item">
                    <a class="nav-link" href="drivers.php">
                        <i class="bi bi-people-fill"></i>
                        <span class="nav-text">Users</span>
                    </a>
                </li>
                
            </ul>
        </div>
    </div>
    
    <div class="sidebar-footer">
        <div class="user-profile-sidebar">
            <div class="user-avatar-sidebar"><?php echo $user_initials; ?></div>
            <div class="user-details-sidebar">
                <span class="user-name-sidebar"><?php echo htmlspecialchars($user_name); ?></span>
                <span class="user-role-sidebar"><?php echo ucfirst($user_role); ?></span>
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

           <div class="row trip-stats-row g-1 g-sm-2 mb-4">
    <!-- Stat 1: Total Tickets -->
    <div class="col">
        <div class="stat-card total">
            <i class="bi bi-ticket-perforated stat-icon"></i>
            <div class="stat-content">
                <div class="stat-value" id="totalTripTickets"><?= $statTotalTickets ?></div>
                <div class="stat-label">Total Tickets</div>
                <?php if ($tt_branch_column_exists && !$view_all_branches): ?>
                    <small class="d-block">Your branch</small>
                <?php else: ?>
                    <small class="d-block">All time trips</small>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Stat 2: Pending -->
    <div class="col">
        <div class="stat-card pending">
            <i class="bi bi-clock stat-icon"></i>
            <div class="stat-content">
                <div class="stat-value" id="pendingTrips"><?= $statPendingTickets ?></div>
                <div class="stat-label">Pending</div>
                <small class="d-block">Waiting for dispatch</small>
            </div>
        </div>
    </div>
    
    <!-- Stat 3: In Transit -->
    <div class="col">
        <div class="stat-card delivery">
            <i class="bi bi-truck stat-icon"></i>
            <div class="stat-content">
                <div class="stat-value" id="activeTrips"><?= $statActiveTrips ?></div>
                <div class="stat-label">In Transit</div>
                <small class="d-block">Currently on delivery</small>
            </div>
        </div>
    </div>
    
    <!-- Stat 4: Completed -->
    <div class="col">
        <div class="stat-card complete">
            <i class="bi bi-check-circle stat-icon"></i>
            <div class="stat-content">
                <div class="stat-value" id="completedTrips"><?= $statCompletedTrips ?></div>
                <div class="stat-label">Completed</div>
                <small class="d-block">Delivered successfully</small>
            </div>
        </div>
    </div>
</div>

                                <!-- FILTER SECTION - COLLAPSIBLE DESIGN (Entire header clickable) -->
<div class="supplier-filter-card mb-4" id="filterCard">
    <div class="supplier-filter-header" id="filterHeader" style="cursor: pointer;">
        <h5>
            <i class="bi bi-funnel"></i> Filter Trip Tickets
        </h5>
        <button class="supplier-filter-toggle-btn" type="button" id="tripFilterToggleBtn" aria-expanded="false">
            <i class="bi bi-chevron-down" id="tripFilterIcon"></i>
        </button>
    </div>
    
    <div class="supplier-filter-content collapsed" id="tripFilterContent">
        <!-- ONE LINE LAYOUT ON DESKTOP -->
        <div class="supplier-filter-one-line">
            <!-- Status Filter -->
            <div class="filter-item">
                <label class="supplier-filter-label">STATUS</label>
                <select class="supplier-filter-select" id="statusFilter" onchange="filterTripTickets()">
                    <option value="">All Status</option>
                    <option value="planned">Pending</option>
                    <option value="in-progress">In Transit</option>
                    <option value="completed">Delivered</option>
                    <option value="cancelled">Cancelled</option>
                    <option value="delayed">Delayed</option>
                </select>
            </div>
            
            <!-- Driver Filter -->
            <div class="filter-item">
                <label class="supplier-filter-label">DRIVER</label>
                <select class="supplier-filter-select" id="driverFilter" onchange="filterTripTickets()">
                    <option value="">All Drivers</option>
                    <?php foreach ($drivers as $driver): ?>
                        <option value="<?= htmlspecialchars($driver['driver_name']) ?>">
                            <?= htmlspecialchars($driver['driver_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <!-- Global Search -->
            <div class="filter-item search-item">
                <label class="supplier-filter-label">SEARCH</label>
                <div class="supplier-search-wrapper">
                    <i class="bi bi-search search-icon"></i>
                    <input type="text" class="supplier-filter-input" id="searchInput" placeholder="Trip #, SO, item..." onkeyup="filterTripTickets()">
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
                                        data-branch-name="<?= htmlspecialchars($ticket['branch_name'] ?? 'N/A') ?>"
                                        data-so-number="<?= htmlspecialchars($ticket['so_number'] ?? '') ?>"
                                        data-picklist="<?= htmlspecialchars($ticket['pick_list_number'] ?? '') ?>"
                                        data-items="<?= htmlspecialchars($item_names) ?>"
                                        data-date="<?= $ticket['trip_date'] ?? '' ?>"
                                        data-customer-name="<?= htmlspecialchars($ticket['customer_name'] ?? 'N/A') ?>"
                                        data-remarks="<?= htmlspecialchars($ticket['remarks'] ?? '') ?>"
                                        data-created-at="<?= $ticket['created_at'] ?? '' ?>"
                                        data-updated-at="<?= $ticket['updated_at'] ?? '' ?>"
                                        data-photo-1="<?= htmlspecialchars($ticket['photo_1'] ?? '') ?>">
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
                            <h6><i class="bi bi-camera me-2"></i>Proof of Delivery Photo</h6>
                            <div class="row">
                                <div class="col-md-12 mb-3">
                                    <div class="detail-card">
                                        <div class="detail-label">Delivery Photo</div>
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
                    <button type="button" class="btn btn-warning" onclick="editCurrentTicket()">Edit</button>
                    <button type="button" class="btn btn-success" onclick="finalizeCurrentTicket()">Finalize</button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
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

         <!-- Mobile Bottom Navigation - Clean Version (No Arrows) -->
<div class="mobile-nav" id="mobileNav">
    <?php 
    $current_page = basename($_SERVER['PHP_SELF']);
    $is_warehouse_page = in_array($current_page, ['current_inventory.php', 'bad_orders.php', 'pick_list_items.php', 'warehouses.php']);
    $is_supplier_page = in_array($current_page, ['purchase_order.php', 'supplier.php']);
    $is_customer_page = in_array($current_page, ['customer_list.php', 'approve_credit_requests.php', 'sales_order.php', 'collections.php']);
    $is_delivery_page = ($current_page == 'trip_tickets.php');
    $is_banking_page = in_array($current_page, ['deposit.php', 'Withdrawal.php', 'bank_statement.php', 'expenses.php']);
    ?>
    <ul class="nav">
        <!-- Dashboard -->
        <li class="nav-item">
            <a class="nav-link <?php echo ($current_page == 'branchdashboard.php') ? 'active' : ''; ?>" href="branchdashboard.php">
                <i class="bi bi-speedometer2"></i>
                <span>Dashboard</span>
            </a>
        </li>

        <!-- Warehouse Dropdown -->
        <li class="nav-item dropdown-more" id="warehouseMobileDropdown">
            <a class="nav-link more-btn <?php echo $is_warehouse_page ? 'active' : ''; ?>" href="#" onclick="toggleMobileDropdown(event, 'warehouseMobileMenu')">
                <i class="bi bi-shop"></i>
                <span>Warehouse</span>
            </a>
            <div class="more-dropdown" id="warehouseMobileMenu">
                <a href="current_inventory.php" class="dropdown-item <?php echo ($current_page == 'current_inventory.php') ? 'active' : ''; ?>">
                    <i class="bi bi-bar-chart-line"></i><span>Current Inventory</span>
                </a>
                <a href="bad_orders.php" class="dropdown-item <?php echo ($current_page == 'bad_orders.php') ? 'active' : ''; ?>">
                    <i class="bi bi-recycle"></i><span>Bad Orders</span>
                </a>
                <a href="pick_list_items.php" class="dropdown-item <?php echo ($current_page == 'pick_list_items.php') ? 'active' : ''; ?>">
                    <i class="bi bi-list-check"></i><span>Pick List Items</span>
                </a>
                <a href="warehouses.php" class="dropdown-item <?php echo ($current_page == 'warehouses.php') ? 'active' : ''; ?>">
                    <i class="bi bi-shop"></i><span>Warehouses</span>
                </a>
            </div>
        </li>

        <!-- Supplier Dropdown -->
        <li class="nav-item dropdown-more" id="supplierMobileDropdown">
            <a class="nav-link more-btn <?php echo $is_supplier_page ? 'active' : ''; ?>" href="#" onclick="toggleMobileDropdown(event, 'supplierMobileMenu')">
                <i class="bi bi-building"></i>
                <span>Supplier</span>
            </a>
            <div class="more-dropdown" id="supplierMobileMenu">
                <a href="purchase_order.php" class="dropdown-item <?php echo ($current_page == 'purchase_order.php') ? 'active' : ''; ?>">
                    <i class="bi bi-box"></i><span>Receive Inventory</span>
                </a>
                <a href="supplier.php" class="dropdown-item <?php echo ($current_page == 'supplier.php') ? 'active' : ''; ?>">
                    <i class="bi bi-people"></i><span>Supplier List</span>
                </a>
            </div>
        </li>

        <!-- Customer Dropdown -->
        <li class="nav-item dropdown-more" id="customerMobileDropdown">
            <a class="nav-link more-btn <?php echo $is_customer_page ? 'active' : ''; ?>" href="#" onclick="toggleMobileDropdown(event, 'customerMobileMenu')">
                <i class="bi bi-people"></i>
                <span>Customer</span>
            </a>
            <div class="more-dropdown" id="customerMobileMenu">
                <a href="customer_list.php" class="dropdown-item <?php echo ($current_page == 'customer_list.php') ? 'active' : ''; ?>">
                    <i class="bi bi-person-badge"></i><span>Customer List</span>
                </a>
                <a href="approve_credit_requests.php" class="dropdown-item <?php echo ($current_page == 'approve_credit_requests.php') ? 'active' : ''; ?>">
                    <i class="bi bi-pencil-square"></i><span>Approve Credit Request</span>
                </a>
                <a href="sales_order.php" class="dropdown-item <?php echo ($current_page == 'sales_order.php') ? 'active' : ''; ?>">
                    <i class="bi bi-cart"></i><span>Sales Order</span>
                </a>
                <a href="collections.php" class="dropdown-item <?php echo ($current_page == 'collections.php') ? 'active' : ''; ?>">
                    <i class="bi bi-cash-stack"></i><span>Collections</span>
                </a>
            </div>
        </li>

        <!-- Delivery Dropdown -->
        <li class="nav-item dropdown-more" id="deliveryMobileDropdown">
            <a class="nav-link more-btn <?php echo $is_delivery_page ? 'active' : ''; ?>" href="#" onclick="toggleMobileDropdown(event, 'deliveryMobileMenu')">
                <i class="bi bi-truck"></i>
                <span>Delivery</span>
            </a>
            <div class="more-dropdown" id="deliveryMobileMenu">
                <a href="trip_tickets.php" class="dropdown-item <?php echo ($current_page == 'trip_tickets.php') ? 'active' : ''; ?>">
                    <i class="bi bi-ticket-perforated"></i><span>Trip Tickets</span>
                </a>
            </div>
        </li>

        <!-- Banking Dropdown -->
        <li class="nav-item dropdown-more" id="bankingMobileDropdown">
            <a class="nav-link more-btn <?php echo $is_banking_page ? 'active' : ''; ?>" href="#" onclick="toggleMobileDropdown(event, 'bankingMobileMenu')">
                <i class="bi bi-bank2"></i>
                <span>Banking</span>
            </a>
            <div class="more-dropdown" id="bankingMobileMenu">
                <a href="deposit.php" class="dropdown-item <?php echo ($current_page == 'deposit.php') ? 'active' : ''; ?>">
                    <i class="bi bi-arrow-down-circle"></i><span>Deposit</span>
                </a>
                <a href="Withdrawal.php" class="dropdown-item <?php echo ($current_page == 'Withdrawal.php') ? 'active' : ''; ?>">
                    <i class="bi bi-arrow-up-circle"></i><span>Withdrawal</span>
                </a>
                <a href="bank_statement.php" class="dropdown-item <?php echo ($current_page == 'bank_statement.php') ? 'active' : ''; ?>">
                    <i class="bi bi-receipt"></i><span>Bank Statement</span>
                </a>
                <a href="expenses.php" class="dropdown-item <?php echo ($current_page == 'expenses.php') ? 'active' : ''; ?>">
                    <i class="bi bi-cash-stack"></i><span>Expenses</span>
                </a>
            </div>
        </li>
        
                <!-- Shared Services -->
         <li class="nav-item dropdown-more" id="sharedServicesMobileDropdown">
            <a class="nav-link more-btn" href="#" onclick="toggleMobileDropdown(event, 'sharedServicesMobileMenu')">
                <i class="bi bi-grid-3x3-gap"></i>
                <span>Shared Services</span>
            </a>
            <div class="more-dropdown" id="sharedServicesMobileMenu">
                <a class="dropdown-item" href="motorpool.php">
                    <i class="bi bi-truck"></i>
                    <span class="nav-text">Motorpool</span>
                </a>
                <a class="dropdown-item" href="central_warehouse.php">
                    <i class="bi bi-box-seam"></i>
                    <span class="nav-text">Central Warehouse</span>
                </a>
            </div>  
         </li>

        <!-- Users -->
        <li class="nav-item">
            <a class="nav-link <?php echo ($current_page == 'drivers.php') ? 'active' : ''; ?>" href="drivers.php">
                <i class="bi bi-people-fill"></i>
                <span>Users</span>
            </a>
        </li>

        <!-- Profile / Logout -->
        <li class="nav-item" id="profileMobileBtn">
            <a href="#" class="nav-link"
                data-bs-toggle="modal"
                data-bs-target="#profileModal">
                    <i class="bi bi-box-arrow-right"></i>
                    <span>Logout</span>
                </a>
        </li>
    </ul>
</div>

    <!-- Mobile Profile Modal -->
    <div class="modal fade" id="profileModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title"><i class="bi bi-person-circle me-2"></i>User Profile</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body text-center"><div class="user-avatar-large mb-3"><?php echo $user_initials; ?></div><h4 class="mb-1"><?php echo htmlspecialchars($user_name); ?></h4><p class="text-muted mb-3"><span class="badge bg-success"><?php echo ucfirst($user_role); ?></span></p><?php if (!$view_all_branches && $branch_id > 0): ?><div class="branch-info mb-3"><i class="bi bi-building me-1"></i><span><?php echo htmlspecialchars($branch_name); ?></span></div><?php endif; ?><div class="user-id text-muted small mb-4"><i class="bi bi-hash"></i> User ID: <?php echo $user_id; ?></div><button class="btn btn-danger btn-lg w-100" onclick="confirmLogout()"><i class="bi bi-box-arrow-right me-2"></i>Logout</button></div></div></div></div>

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
    let scrollTimeout;
    let dropdownScrollTimeout;


    // ========== MOBILE BOTTOM NAVBAR FIX ==========
    // Global functions because mobile bottom nav uses inline onclick handlers.
    window.closeAllMobileDropdowns = function() {
        const dropdowns = document.querySelectorAll(
            '.mobile-nav .more-dropdown, #inventoryDropdownMenu, #salesDropdownMenu, #purchaseDropdownMenu, #moreDropdownMenu'
        );

        dropdowns.forEach(function(dropdown) {
            dropdown.classList.remove('show');
        });

        document.querySelectorAll('.mobile-nav .more-btn, .more-btn').forEach(function(btn) {
            btn.classList.remove('active');
            btn.setAttribute('aria-expanded', 'false');
        });
    };

    window.toggleMobileDropdown = function(event, dropdownId) {
        if (event) {
            event.preventDefault();
            event.stopPropagation();
        }

        const dropdown = document.getElementById(dropdownId);
        const btn = event ? event.currentTarget : null;

        if (!dropdown) {
            console.error('Mobile dropdown not found:', dropdownId);
            return false;
        }

        const isOpen = dropdown.classList.contains('show');

        window.closeAllMobileDropdowns();

        if (!isOpen) {
            dropdown.classList.add('show');

            if (btn) {
                btn.classList.add('active');
                btn.setAttribute('aria-expanded', 'true');
            }
        }

        return false;
    };

    // Compatibility for old onclick="toggleDropdown(...)" buttons.
    window.toggleDropdown = function(event, dropdownId) {
        return window.toggleMobileDropdown(event, dropdownId);
    };

    window.showProfileModal = function(event) {
        if (event) {
            event.preventDefault();
            event.stopPropagation();
        }

        if (typeof cleanupModalBackdrops === 'function') {
            cleanupModalBackdrops();
        }

        window.closeAllMobileDropdowns();

        const profileModalEl = document.getElementById('profileModal');

        if (profileModalEl && typeof bootstrap !== 'undefined') {
            bootstrap.Modal.getOrCreateInstance(profileModalEl).show();
        } else {
            console.error('Profile modal or Bootstrap is missing.');
        }

        return false;
    };

    document.addEventListener('click', function(e) {
        if (!e.target.closest('.mobile-nav')) {
            window.closeAllMobileDropdowns();
        }
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            window.closeAllMobileDropdowns();
        }
    });

    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('.mobile-nav .dropdown-item').forEach(function(item) {
            item.addEventListener('click', function() {
                window.closeAllMobileDropdowns();
            });
        });

        const profileModalEl = document.getElementById('profileModal');
        if (profileModalEl) {
            profileModalEl.addEventListener('show.bs.modal', function() {
                window.closeAllMobileDropdowns();
            });
        }

        if (typeof setActiveMobileNav === 'function') {
            setActiveMobileNav();
        }
    });


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
    // ===== FILTER TOGGLE - ENTIRE HEADER CLICKABLE =====
document.addEventListener('DOMContentLoaded', function() {
    const filterHeader = document.getElementById('filterHeader');
    const filterContent = document.getElementById('tripFilterContent');
    const filterToggleBtn = document.getElementById('tripFilterToggleBtn');
    const filterIcon = document.getElementById('tripFilterIcon');
    
    if (filterHeader && filterContent) {
        // Set initial state - collapsed
        filterContent.classList.add('collapsed');
        if (filterToggleBtn) filterToggleBtn.setAttribute('aria-expanded', 'false');
        
        // Make the entire header clickable
        filterHeader.addEventListener('click', function(e) {
            // Don't toggle if clicking on the button itself (to avoid double toggle)
            if (e.target.closest('.supplier-filter-toggle-btn')) {
                e.stopPropagation();
            }
            toggleFilterContent();
        });
        
        // Also keep the button click as a fallback
        if (filterToggleBtn) {
            filterToggleBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                toggleFilterContent();
            });
        }
    }
    
    function toggleFilterContent() {
        const isExpanded = !filterContent.classList.contains('collapsed');
        
        if (isExpanded) {
            // Collapse
            filterContent.classList.add('collapsed');
            if (filterToggleBtn) filterToggleBtn.setAttribute('aria-expanded', 'false');
            if (filterIcon) {
                filterIcon.classList.remove('bi-chevron-up');
                filterIcon.classList.add('bi-chevron-down');
            }
        } else {
            // Expand
            filterContent.classList.remove('collapsed');
            if (filterToggleBtn) filterToggleBtn.setAttribute('aria-expanded', 'true');
            if (filterIcon) {
                filterIcon.classList.remove('bi-chevron-down');
                filterIcon.classList.add('bi-chevron-up');
            }
            
            // Re-initialize row click to view after expand (for mobile)
            setTimeout(function() {
                if (typeof initRowClickToView === 'function') {
                    initRowClickToView();
                }
            }, 100);
        }
    }
});

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
        
        // Call setActiveMobileNav
        setActiveMobileNav();
        
        // Initialize row click to view (works on both desktop and mobile)
        initRowClickToView();
        
        // Set up filter event listeners
        var searchInput = document.getElementById('searchInput');
        var statusFilter = document.getElementById('statusFilter');
        var driverFilter = document.getElementById('driverFilter');
        
        if (searchInput) {
            searchInput.addEventListener('keyup', function() { filterTripTickets(); });
            searchInput.addEventListener('search', function() { filterTripTickets(); });
        }
        if (statusFilter) {
            statusFilter.addEventListener('change', function() { filterTripTickets(); });
        }
        if (driverFilter) {
            driverFilter.addEventListener('change', function() { filterTripTickets(); });
        }
        
        // Re-initialize on window resize
        var resizeTimer;
        window.addEventListener('resize', function() {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(function() {
                initRowClickToView();
            }, 250);
        });
        
        // Re-initialize when filter toggle button is clicked
        var filterToggleBtn = document.getElementById('tripFilterToggleBtn');
        if (filterToggleBtn) {
            filterToggleBtn.addEventListener('click', function() {
                setTimeout(function() {
                    initRowClickToView();
                }, 300);
            });
        }
    });

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
        const itemsCodeDisplay = ticket.item_codes ? '<br><small class="text-muted">' + ticket.item_codes + '</small>' : '';
        
        // Populate details grid
        const detailsGrid = document.getElementById('ticketDetails');
        detailsGrid.innerHTML = `
            <div class="detail-card">
                <div class="detail-label">Trip Number</div>
                <div class="detail-value">${escapeHtml(ticket.trip_number)}</div>
            </div>
            <div class="detail-card">
                <div class="detail-label">Driver</div>
                <div class="detail-value">${escapeHtml(driverName)} <small class="text-muted">${driverSource}</small></div>
            </div>
            <div class="detail-card">
                <div class="detail-label">Sales Order</div>
                <div class="detail-value">${escapeHtml(ticket.so_number || 'N/A')}</div>
            </div>
            <div class="detail-card">
                <div class="detail-label">Pick List</div>
                <div class="detail-value">${escapeHtml(ticket.pick_list_number || 'N/A')}</div>
            </div>
            <div class="detail-card">
                <div class="detail-label">Items</div>
                <div class="detail-value">${escapeHtml(itemsDisplay)}${itemsCodeDisplay}</div>
            </div>
            <div class="detail-card">
                <div class="detail-label">Branch</div>
                <div class="detail-value">${escapeHtml(ticket.branch_name || 'N/A')}</div>
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
                <div class="detail-value">${escapeHtml(ticket.customer_name || 'N/A')}</div>
            </div>
        `;
        
        // Populate timestamps
        document.getElementById('viewCreatedAt').textContent = ticket.created_at ? formatDateTime(ticket.created_at) : 'N/A';
        document.getElementById('viewUpdatedAt').textContent = ticket.updated_at ? formatDateTime(ticket.updated_at) : 'N/A';
        document.getElementById('viewRemarks').textContent = ticket.remarks || 'No remarks';
        
        if (ttBranchColumnExists && viewAllBranches) {
            document.getElementById('viewBranch').textContent = ticket.branch_name + ' (ID: ' + ticket.branch_id + ')';
        }
        
        // Load photo preview if exists (from deliveries table)
        const photoPreview = document.getElementById('photoPreview1');
        const photoPlaceholder = document.getElementById('photoPlaceholder1');
        if (ticket.photo_1) {
            photoPreview.innerHTML = '<img src="../uploads/deliveries/' + escapeHtml(ticket.photo_1) + '" alt="Delivery Photo" style="max-width: 100%; max-height: 200px;" class="img-fluid rounded" onerror="this.onerror=null; this.src=\'\'; this.parentElement.innerHTML=\'<i class=\\\'bi bi-image\\\' style=\\\'font-size: 40px;\\\'></i><p class=\\\'mt-2\\\'>Photo file missing</p>\';">';
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

    // ========== PRINT FUNCTION ==========
    function printTripTickets() {
        const printBtn = document.querySelector('.btn-outline-primary[onclick="printTripTickets()"]');
        if (printBtn) {
            const originalText = printBtn.innerHTML;
            printBtn.innerHTML = '<i class="bi bi-printer"></i> Preparing...';
            printBtn.disabled = true;
        }

        const statusFilter = document.getElementById('statusFilter').value;
        const driverFilter = document.getElementById('driverFilter').value;
        
        const filterData = {
            status: statusFilter || '',
            driver: driverFilter || '',
            branch: 'all'
        };
        
        showLoading();
        
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
                
                const htmlContent = generatePrintHTML(items);
                const iframe = document.getElementById('printFrame');
                const iframeDoc = iframe.contentWindow.document;
                
                iframeDoc.open();
                iframeDoc.write(htmlContent);
                iframeDoc.close();
                
                setTimeout(() => {
                    if (printBtn) {
                        printBtn.innerHTML = '<i class="bi bi-printer"></i> Print';
                        printBtn.disabled = false;
                    }
                }, 1000);
                
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

    // Compact HTML generator for trip tickets print
    function generatePrintHTML(items) {
        let tableRows = '';
        let totalStops = 0;
        let totalDelivered = 0;
        
        items.forEach(item => {
            const driverName = item.picklist_driver_name || item.driver_name || 'Unassigned';
            totalStops += parseInt(item.actual_stops || item.total_stops || 0);
            totalDelivered += parseInt(item.actual_delivered || item.total_delivered || 0);
            
            let tripDate = item.trip_date || '';
            if (tripDate) {
                const date = new Date(tripDate);
                tripDate = date.toLocaleDateString('en-US', {
                    year: 'numeric',
                    month: 'short',
                    day: 'numeric'
                });
            }
            
            let itemNames = item.item_names || 'No items';
            
            tableRows += '<tr>';
            tableRows += '<td style="padding: 3px; border: 1px solid #000;">' + (item.trip_number || '') + '</td>';
            tableRows += '<td style="padding: 3px; border: 1px solid #000;">' + (item.so_number || 'N/A') + '</td>';
            tableRows += '<td style="padding: 3px; border: 1px solid #000;">' + (item.pick_list_number || 'N/A') + '</td>';
            tableRows += '<td style="padding: 3px; border: 1px solid #000;">' + escapeHtml(itemNames) + '</td>';
            tableRows += '<td style="padding: 3px; border: 1px solid #000;">' + escapeHtml(driverName) + '</td>';
            tableRows += '<td style="padding: 3px; border: 1px solid #000;">' + (item.branch_name || 'N/A') + '</td>';
            if (ttBranchColumnExists && viewAllBranches) {
                tableRows += '<td style="padding: 3px; border: 1px solid #000; text-align: center;">' + (item.branch_id || 'N/A') + '</td>';
            }
            tableRows += '<td style="padding: 3px; border: 1px solid #000;">' + tripDate + '</td>';
            tableRows += '<td style="padding: 3px; border: 1px solid #000;">' + getStatusText(item.trip_status) + '</td>';
            tableRows += '</tr>';
        });
        
        const currentDate = new Date().toLocaleDateString('en-US', { 
            year: 'numeric', 
            month: 'short', 
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
        
        return '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Trip Tickets Report</title><style>' +
            'body { font-family: Arial, sans-serif; margin: 0; padding: 0; font-size: 9px; }' +
            '.print-container { max-width: 100%; margin: 0; }' +
            '.print-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 5px; border-bottom: 1px solid #000; padding-bottom: 3px; }' +
            '.logo-section { display: flex; align-items: center; gap: 5px; }' +
            '.company-logo { width: 30px; height: auto; }' +
            '.company-info h1 { font-size: 14px; margin: 0; font-weight: bold; }' +
            '.company-info p { font-size: 8px; margin: 0; }' +
            '.report-title h2 { font-size: 12px; margin: 0; }' +
            '.report-title .date-info { font-size: 8px; }' +
            '.summary-box { border: 1px solid #000; padding: 3px; margin-bottom: 5px; display: flex; }' +
            '.summary-item { flex: 1; text-align: center; border-right: 1px solid #000; }' +
            '.summary-item:last-child { border-right: none; }' +
            '.summary-label { font-size: 8px; font-weight: bold; }' +
            '.summary-value { font-size: 11px; font-weight: bold; }' +
            'table { width: 100%; border-collapse: collapse; font-size: 8px; }' +
            'th { border: 1px solid #000; padding: 3px; text-align: left; font-weight: bold; background: white !important; color: black !important; }' +
            'td { border: 1px solid #000; padding: 3px; }' +
            '.total-row { font-weight: bold; }' +
            '.print-footer { margin-top: 5px; border-top: 1px solid #000; padding-top: 3px; display: flex; justify-content: space-between; font-size: 8px; }' +
            '</style></head><body>' +
            '<div class="print-container">' +
            '<div class="print-header">' +
            '<div class="logo-section">' +
            '<img src="' + logoBase64 + '" alt="AMGC Logo" class="company-logo">' +
            '<div class="company-info">' +
            '<h1>AMGC</h1>' +
            '<p>Trip Tickets Report</p>' +
            '</div>' +
            '</div>' +
            '<div class="report-title">' +
            '<h2>TRIP TICKETS</h2>' +
            '<div class="date-info">' + currentDate + '</div>' +
            '</div>' +
            '</div>' +
            '<div class="summary-box">' +
            '<div class="summary-item"><div class="summary-label">Total Trips</div><div class="summary-value">' + items.length + '</div></div>' +
            '<div class="summary-item"><div class="summary-label">Total Stops</div><div class="summary-value">' + totalStops + '</div></div>' +
            '<div class="summary-item"><div class="summary-label">Total Delivered</div><div class="summary-value">' + totalDelivered + '</div></div>' +
            '<div class="summary-item"><div class="summary-label">Branch</div><div class="summary-value">' + (!viewAllBranches ? ('Branch ' + branchId) : 'All') + '</div></div>' +
            '</div>' +
            '<thead><tr><th>Trip #</th><th>SO #</th><th>Pick List</th><th>Items</th><th>Driver</th><th>Branch</th>' + (ttBranchColumnExists && viewAllBranches ? '<th>Branch ID</th>' : '') + '<th>Trip Date</th><th>Status</th></tr></thead>' +
            '<tbody>' + tableRows + '</tbody>' +
            '</table>' +
            '<div class="print-footer">' +
            '<div>Generated: ' + currentDate + '</div>' +
            '<div>' + (document.querySelector('.user-name-sidebar')?.textContent || 'Branch Admin') + '</div>' +
            '</div>' +
            '</div>' +
            '</body></html>';
    }

    // ========== EXCEL EXPORT FUNCTION ==========
    function exportToExcel() {
        const rows = document.querySelectorAll('.trip-row:not([style*="display: none"])');
        if (rows.length === 0) {
            Swal.fire('Warning', 'No trip tickets to export', 'warning');
            return;
        }
        
        const excelData = [];
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

        rows.forEach(row => {
            if (row.style.display !== 'none') {
                const cells = row.querySelectorAll('td');
                let cellIndex = 0;
                
                cellIndex++;
                const tripNumber = cells[cellIndex++]?.innerText || '';
                const soNumber = cells[cellIndex++]?.innerText || '';
                const pickList = cells[cellIndex++]?.innerText || '';
                const itemsCell = cells[cellIndex++];
                const items = itemsCell?.querySelector('.item-names')?.innerText.trim() || itemsCell?.innerText || '';
                const driverCell = cells[cellIndex++];
                const driverName = driverCell?.querySelector('.driver-info')?.innerText.trim().split('\n')[0] || driverCell?.innerText || '';
                const branch = cells[cellIndex++]?.innerText || '';
                
                let branchIdVal = '';
                if (ttBranchColumnExists && viewAllBranches) {
                    branchIdVal = cells[cellIndex++]?.innerText || '';
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
                    ...(ttBranchColumnExists && viewAllBranches ? [branchIdVal] : []),
                    tripDate,
                    status
                ];
                excelData.push(rowData);
            }
        });

        const wb = XLSX.utils.book_new();
        const ws = XLSX.utils.aoa_to_sheet(excelData);
        const colWidths = [
            { wch: 15 }, { wch: 15 }, { wch: 15 }, { wch: 40 },
            { wch: 20 }, { wch: 15 },
            ...(ttBranchColumnExists && viewAllBranches ? [{ wch: 10 }] : []),
            { wch: 15 }, { wch: 15 }
        ];
        ws['!cols'] = colWidths;
        XLSX.utils.book_append_sheet(wb, ws, 'Trip Tickets');

        const date = new Date();
        const dateStr = date.toISOString().slice(0,10).replace(/-/g, '');
        let filename = 'Trip_Tickets_' + dateStr;
        if (ttBranchColumnExists && !viewAllBranches) {
            filename += '_Branch_' + branchId;
        }
        filename += '.xlsx';
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

    function cleanupModalBackdrops() {
        document.querySelectorAll('.modal-backdrop').forEach(backdrop => backdrop.remove());
        document.body.classList.remove('modal-open');
        document.body.style.removeProperty('padding-right');
        document.body.style.removeProperty('overflow');
        if (document.body.hasAttribute('style')) {
            const style = document.body.getAttribute('style');
            if (style && (style.includes('padding-right') || style.includes('overflow'))) {
                document.body.removeAttribute('style');
            }
        }
    }
function confirmLogout() {
        const modal = bootstrap.Modal.getInstance(document.getElementById('profileModal'));
        if (modal) {
            modal.hide();
        }
        
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

    function logout() { confirmLogout(); }

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
// ================= PURCHASE DROPDOWN POSITION FIX =================
    function fixPurchaseDropdownPosition() {
        const purchaseDropdown = document.querySelector('#purchaseDropdown .more-dropdown');
        if (purchaseDropdown) {
            purchaseDropdown.style.setProperty('right', '0', 'important');
            purchaseDropdown.style.setProperty('left', 'auto', 'important');
        }
    }
    
    document.addEventListener('DOMContentLoaded', function() {
        fixPurchaseDropdownPosition();
        setActiveMobileNav();
    });
    window.addEventListener('resize', fixPurchaseDropdownPosition);
    
    const purchaseMenu = document.getElementById('purchaseDropdownMenu');
    if (purchaseMenu) {
        new MutationObserver(mutations => {
            mutations.forEach(mutation => {
                if (mutation.type === 'attributes' && mutation.attributeName === 'class' && purchaseMenu.classList.contains('show')) {
                    fixPurchaseDropdownPosition();
                }
            });
        }).observe(purchaseMenu, { attributes: true });
    }

    // ========== MOBILE NAV ACTIVE STATE ==========
    function setActiveMobileNav() {
        const currentPage = window.location.pathname.split('/').pop();
        
        document.querySelectorAll('.mobile-nav .nav-link, .more-btn, .dropdown-item, .has-active').forEach(el => {
            el.classList.remove('active', 'has-active');
        });
        
        const mainNavLinks = document.querySelectorAll('.mobile-nav .nav-link:not(.more-btn)');
        mainNavLinks.forEach(link => {
            const href = link.getAttribute('href');
            if (href === currentPage) {
                link.classList.add('active');
            }
        });
        
        document.querySelectorAll('.more-dropdown .dropdown-item').forEach(item => {
            const href = item.getAttribute('href');
            if (href === currentPage) {
                item.classList.add('active');
                const parentDropdown = item.closest('.dropdown-more');
                if (parentDropdown) {
                    const parentBtn = parentDropdown.querySelector('.more-btn');
                    if (parentBtn) {
                        parentBtn.classList.add('has-active');
                    }
                }
            }
        });
        
        if (currentPage === 'trip_tickets.php') {
            const tripLink = document.querySelector('#mobileNav .nav-link[href="trip_tickets.php"]');
            if (tripLink) tripLink.classList.add('active');
        }
    }

    // Trip Filter Toggle
    const tripFilterToggleBtn = document.getElementById('tripFilterToggleBtn');
    const tripFilterContent = document.getElementById('tripFilterContent');
    const tripFilterIcon = document.getElementById('tripFilterIcon');

    if (tripFilterToggleBtn && tripFilterContent && tripFilterIcon) {
        tripFilterToggleBtn.addEventListener('click', function() {
            const isExpanded = tripFilterContent.classList.contains('collapsed');
            
            if (isExpanded) {
                tripFilterContent.classList.remove('collapsed');
                tripFilterIcon.classList.remove('bi-chevron-down');
                tripFilterIcon.classList.add('bi-chevron-up');
                tripFilterToggleBtn.setAttribute('aria-expanded', 'true');
            } else {
                tripFilterContent.classList.add('collapsed');
                tripFilterIcon.classList.remove('bi-chevron-up');
                tripFilterIcon.classList.add('bi-chevron-down');
                tripFilterToggleBtn.setAttribute('aria-expanded', 'false');
            }
        });
    }

    // ===== FILTER FUNCTION =====
    function filterTripTickets() {
        var searchText = document.getElementById('searchInput').value.toLowerCase();
        var statusFilter = document.getElementById('statusFilter').value;
        var driverFilter = document.getElementById('driverFilter').value;
        
        var rows = document.querySelectorAll('#tripTicketsTable tbody tr.trip-row');
        var visibleCount = 0;
        
        for (var i = 0; i < rows.length; i++) {
            var row = rows[i];
            var tripNumber = (row.getAttribute('data-trip-number') || '').toLowerCase();
            var driver = (row.getAttribute('data-driver') || '').toLowerCase();
            var status = row.getAttribute('data-status') || '';
            var soNumber = (row.getAttribute('data-so-number') || '').toLowerCase();
            var picklist = (row.getAttribute('data-picklist') || '').toLowerCase();
            var items = (row.getAttribute('data-items') || '').toLowerCase();
            
            var matchesSearch = searchText === '' || 
                tripNumber.indexOf(searchText) !== -1 || 
                driver.indexOf(searchText) !== -1 ||
                soNumber.indexOf(searchText) !== -1 ||
                picklist.indexOf(searchText) !== -1 ||
                items.indexOf(searchText) !== -1;
            
            var matchesStatus = statusFilter === '' || status === statusFilter;
            var matchesDriver = driverFilter === '' || driver === driverFilter.toLowerCase();
            
            if (matchesSearch && matchesStatus && matchesDriver) {
                row.style.display = '';
                visibleCount++;
            } else {
                row.style.display = 'none';
            }
        }
        
        updateNoResultsMessage(visibleCount);
        
        // RE-INITIALIZE row click to view after filter
        initRowClickToView();
    }

    // Update no results message
    function updateNoResultsMessage(visibleCount) {
        var tbody = document.querySelector('#tripTicketsTable tbody');
        var existingNoResult = document.querySelector('#noResultMessage');
        
        if (existingNoResult) {
            existingNoResult.remove();
        }
        
        if (visibleCount === 0) {
            var thCount = document.querySelectorAll('#tripTicketsTable thead th').length;
            var noResultRow = document.createElement('tr');
            noResultRow.id = 'noResultMessage';
            noResultRow.innerHTML = `
                <td colspan="${thCount}" class="text-center py-4">
                    <p class="text-muted mb-0">No trip tickets found matching your filters</p>
                </td>
            `;
            tbody.appendChild(noResultRow);
            
            if (window.innerWidth <= 768) {
                noResultRow.style.cursor = 'default';
            }
        }
    }

    // ========== SIDEBAR DROPDOWN HANDLING ==========

    function toggleSidebarDropdown(event, targetId) {
        event.preventDefault();
        event.stopPropagation();
        
        const target = document.getElementById(targetId);
        const btn = event.currentTarget;
        const arrow = btn.querySelector('.dropdown-arrow');
        const sidebar = document.getElementById('sidebar');
        
        if (sidebar.classList.contains('collapsed')) {
            sidebar.classList.remove('collapsed');
            localStorage.setItem('sidebarCollapsed', 'false');
            
            setTimeout(() => {
                document.querySelectorAll('.sidebar .collapse.show').forEach(collapse => {
                    if (collapse.id !== targetId) {
                        collapse.classList.remove('show');
                        const otherBtn = document.querySelector(`[onclick*="${collapse.id}"]`);
                        if (otherBtn) {
                            const otherArrow = otherBtn.querySelector('.dropdown-arrow');
                            if (otherArrow) otherArrow.style.transform = 'translateY(-50%) rotate(0deg)';
                        }
                    }
                });
                
                target.classList.add('show');
                if (arrow) arrow.style.transform = 'translateY(-50%) rotate(180deg)';
            }, 50);
            return;
        }
        
        if (target.classList.contains('show')) {
            target.classList.remove('show');
            if (arrow) arrow.style.transform = 'translateY(-50%) rotate(0deg)';
        } else {
            document.querySelectorAll('.sidebar .collapse.show').forEach(collapse => {
                if (collapse.id !== targetId) {
                    collapse.classList.remove('show');
                    const otherBtn = document.querySelector(`[onclick*="${collapse.id}"]`);
                    if (otherBtn) {
                        const otherArrow = otherBtn.querySelector('.dropdown-arrow');
                        if (otherArrow) otherArrow.style.transform = 'translateY(-50%) rotate(0deg)';
                    }
                }
            });
            
            target.classList.add('show');
            if (arrow) arrow.style.transform = 'translateY(-50%) rotate(180deg)';
        }
    }

    function setActiveSidebarItem() {
        const currentPage = window.location.pathname.split('/').pop();
        
        document.querySelectorAll('.sidebar .nav-link').forEach(link => {
            link.classList.remove('active');
        });
        
        document.querySelectorAll('.sidebar .nav-link').forEach(link => {
            const href = link.getAttribute('href');
            if (href === currentPage) {
                link.classList.add('active');
                
                const collapseDiv = link.closest('.collapse');
                if (collapseDiv) {
                    collapseDiv.classList.add('show');
                    const parentBtn = document.querySelector(`[onclick*="${collapseDiv.id}"]`);
                    if (parentBtn) {
                        const arrow = parentBtn.querySelector('.dropdown-arrow');
                        if (arrow) arrow.style.transform = 'translateY(-50%) rotate(180deg)';
                    }
                }
            }
        });
    }

    function updateDropdownParentActiveState() {
        const sidebar = document.getElementById('sidebar');
        if (!sidebar) return;
        
        if (sidebar.classList.contains('collapsed')) {
            document.querySelectorAll('.dropdown-nav').forEach(dropdownNav => {
                const hasActiveChild = dropdownNav.querySelector('.nav-link.active');
                const parentLink = dropdownNav.querySelector(':scope > .nav-link');
                
                if (hasActiveChild && parentLink) {
                    parentLink.classList.add('active');
                } else if (parentLink) {
                    parentLink.classList.remove('active');
                }
            });
        }
    }

    function expandActiveDropdownContainers() {
        const sidebar = document.getElementById('sidebar');
        if (!sidebar) return;
        
        const dropdownNavs = document.querySelectorAll('.sidebar .dropdown-nav');
        
        dropdownNavs.forEach(dropdownNav => {
            const activeLink = dropdownNav.querySelector('.nav-link.active');
            
            if (activeLink) {
                const collapseDiv = dropdownNav.querySelector('.collapse');
                
                if (collapseDiv && !collapseDiv.classList.contains('show')) {
                    collapseDiv.classList.add('show');
                    
                    const parentLink = dropdownNav.querySelector(':scope > .nav-link');
                    if (parentLink) {
                        const arrow = parentLink.querySelector('.dropdown-arrow');
                        if (arrow) {
                            arrow.style.transform = 'translateY(-50%) rotate(180deg)';
                        }
                        if (sidebar.classList.contains('collapsed')) {
                            parentLink.classList.add('active');
                        }
                    }
                }
            }
        });
    }

    // Initialize sidebar on DOM load
    document.addEventListener('DOMContentLoaded', function() {
        const sidebar = document.getElementById('sidebar');
        if (sidebar && window.innerWidth > 992) {
            const isCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
            if (isCollapsed) {
                sidebar.classList.add('collapsed');
            } else {
                sidebar.classList.remove('collapsed');
            }
        }
        
        setActiveSidebarItem();
        updateDropdownParentActiveState();
        
        document.querySelectorAll('.sidebar .collapse').forEach(collapse => {
            collapse.addEventListener('click', function(e) {
                e.stopPropagation();
            });
        });
        
        const desktopToggleBtn = document.getElementById('desktopToggleBtn');
        if (desktopToggleBtn) {
            desktopToggleBtn.addEventListener('click', function() {
                setTimeout(() => {
                    if (sidebar.classList.contains('collapsed')) {
                        document.querySelectorAll('.sidebar .collapse.show').forEach(collapse => {
                            collapse.classList.remove('show');
                            const parentBtn = document.querySelector(`[onclick*="${collapse.id}"]`);
                            if (parentBtn) {
                                const arrow = parentBtn.querySelector('.dropdown-arrow');
                                if (arrow) arrow.style.transform = 'translateY(-50%) rotate(0deg)';
                            }
                        });
                    }
                }, 50);
            });
        }
        
        const mobileMenuBtn = document.getElementById('mobileMenuBtn');
        if (mobileMenuBtn) {
            mobileMenuBtn.addEventListener('click', toggleSidebar);
        }
        
        document.addEventListener('click', function(e) {
            if (window.innerWidth <= 992 && sidebar && sidebar.classList.contains('active') && 
                !sidebar.contains(e.target) && mobileMenuBtn && !mobileMenuBtn.contains(e.target)) {
                sidebar.classList.remove('active');
                const overlay = document.querySelector('.sidebar-overlay');
                if (overlay) overlay.remove();
            }
        });
    });

    // ===== UNIFIED ROW CLICK FUNCTIONALITY (WORKS ON BOTH DESKTOP AND MOBILE) =====
    function initRowClickToView() {
        var tripRows = document.querySelectorAll('#tripTicketsTable tbody tr.trip-row');
        
        for (var i = 0; i < tripRows.length; i++) {
            var row = tripRows[i];
            var isVisible = row.style.display !== 'none';
            
            if (row.hasAttribute('data-row-listener')) {
                row.onclick = null;
                row.removeAttribute('data-row-listener');
            }
            
            if (isVisible) {
                row.setAttribute('data-row-listener', 'true');
                row.onclick = function(event) {
                    if (event.target.closest('.form-check-input')) {
                        event.stopPropagation();
                        return;
                    }
                    openViewModalFromRow(this);
                };
                row.style.cursor = 'pointer';
            } else {
                row.style.cursor = '';
            }
        }
    }

    // Function to open view modal using row data attributes
    function openViewModalFromRow(row) {
        var ticket = {
            trip_id: row.getAttribute('data-id') || '',
            trip_number: row.getAttribute('data-trip-number') || '',
            driver_name: row.getAttribute('data-driver') || 'Unassigned',
            picklist_driver_name: row.getAttribute('data-driver') || '',
            trip_status: row.getAttribute('data-status') || '',
            so_number: row.getAttribute('data-so-number') || 'N/A',
            pick_list_number: row.getAttribute('data-picklist') || 'N/A',
            item_names: row.getAttribute('data-items') || 'No items',
            branch_name: getBranchNameFromRow(row),
            branch_id: row.getAttribute('data-branch') || '',
            trip_date: row.getAttribute('data-date') || '',
            customer_name: row.getAttribute('data-customer-name') || 'N/A',
            remarks: row.getAttribute('data-remarks') || 'No remarks',
            created_at: row.getAttribute('data-created-at') || '',
            updated_at: row.getAttribute('data-updated-at') || '',
            photo_1: row.getAttribute('data-photo-1') || ''
        };
        
        currentTicket = ticket;
        populateViewModal(ticket);
        
        var modal = new bootstrap.Modal(document.getElementById('viewModal'));
        modal.show();
    }

    // Helper function to get branch name from row
    function getBranchNameFromRow(row) {
        var branchName = row.getAttribute('data-branch-name');
        if (branchName) return branchName;
        
        var branchCell = row.querySelector('td:nth-child(7)');
        if (branchCell) {
            return branchCell.innerText.trim();
        }
        return row.getAttribute('data-branch') || 'N/A';
    }

    // Populate view modal with ticket data
    function populateViewModal(ticket) {
        var driverName = ticket.picklist_driver_name || ticket.driver_name || 'Unassigned';
        var driverSource = ticket.picklist_driver_name ? '(from pick list)' : '(from trip ticket)';
        var itemsDisplay = ticket.item_names || 'No items';
        
        var detailsGrid = document.getElementById('ticketDetails');
        if (detailsGrid) {
            detailsGrid.innerHTML = `
                <div class="detail-card">
                    <div class="detail-label">Trip Number</div>
                    <div class="detail-value">${escapeHtml(ticket.trip_number)}</div>
                </div>
                <div class="detail-card">
                    <div class="detail-label">Driver</div>
                    <div class="detail-value">${escapeHtml(driverName)} <small class="text-muted">${driverSource}</small></div>
                </div>
                <div class="detail-card">
                    <div class="detail-label">Sales Order</div>
                    <div class="detail-value">${escapeHtml(ticket.so_number)}</div>
                </div>
                <div class="detail-card">
                    <div class="detail-label">Pick List</div>
                    <div class="detail-value">${escapeHtml(ticket.pick_list_number)}</div>
                </div>
                <div class="detail-card">
                    <div class="detail-label">Items</div>
                    <div class="detail-value">${escapeHtml(itemsDisplay)}</div>
                </div>
                <div class="detail-card">
                    <div class="detail-label">Branch</div>
                    <div class="detail-value">${escapeHtml(ticket.branch_name)}</div>
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
                    <div class="detail-value">${escapeHtml(ticket.customer_name)}</div>
                </div>
            `;
        }
        
        var viewCreatedAt = document.getElementById('viewCreatedAt');
        var viewUpdatedAt = document.getElementById('viewUpdatedAt');
        var viewRemarks = document.getElementById('viewRemarks');
        var viewBranch = document.getElementById('viewBranch');
        
        if (viewCreatedAt) viewCreatedAt.textContent = ticket.created_at ? formatDateTime(ticket.created_at) : 'N/A';
        if (viewUpdatedAt) viewUpdatedAt.textContent = ticket.updated_at ? formatDateTime(ticket.updated_at) : 'N/A';
        if (viewRemarks) viewRemarks.textContent = ticket.remarks || 'No remarks';
        
        if (ttBranchColumnExists && viewAllBranches && viewBranch) {
            viewBranch.textContent = ticket.branch_name + ' (ID: ' + ticket.branch_id + ')';
        }
        
        var photoPreview = document.getElementById('photoPreview1');
        var photoPlaceholder = document.getElementById('photoPlaceholder1');
        if (photoPreview && photoPlaceholder) {
            if (ticket.photo_1) {
                photoPreview.innerHTML = '<img src="../uploads/deliveries/' + escapeHtml(ticket.photo_1) + '" alt="Delivery Photo" style="max-width: 100%; max-height: 200px;" class="img-fluid rounded" onerror="this.onerror=null; this.src=\'\'; this.parentElement.innerHTML=\'<i class=\\\'bi bi-image\\\' style=\\\'font-size: 40px;\\\'></i><p class=\\\'mt-2\\\'>Photo file missing</p>\';">';
                photoPlaceholder.style.display = 'none';
            } else {
                photoPreview.innerHTML = '';
                photoPlaceholder.style.display = 'block';
            }
        }
    }

    // Helper function to escape HTML
    function escapeHtml(text) {
        if (!text) return '';
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
</script>
</body>
</html>