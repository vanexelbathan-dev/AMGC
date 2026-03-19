<?php
require_once '../config/database.php';
require_once '../config/session_handler.php';

// Get current user info and branch context
$user_id = $_SESSION['user_id'] ?? 0;
$user_name = isset($_SESSION['first_name']) ? $_SESSION['first_name'] . ' ' . $_SESSION['last_name'] : 'Branch Admin';
$user_role = isset($_SESSION['role']) ? $_SESSION['role'] : 'branch_admin';
$branch_id = $_SESSION['branch_id'] ?? 0;
$view_all_branches = $_SESSION['view_all_branches'] ?? false;

// Check if branch_id column exists in sales_orders table
$so_branch_column_exists = false;
$check_column = $conn->query("SHOW COLUMNS FROM sales_orders LIKE 'branch_id'");
if ($check_column && $check_column->num_rows > 0) {
    $so_branch_column_exists = true;
}

// Check if branch_id column exists in customers table
$customers_branch_column_exists = false;
$check_customers_column = $conn->query("SHOW COLUMNS FROM customers LIKE 'branch_id'");
if ($check_customers_column && $check_customers_column->num_rows > 0) {
    $customers_branch_column_exists = true;
}

// Check if branch_id column exists in drivers table
$drivers_branch_column_exists = false;
$check_drivers_column = $conn->query("SHOW COLUMNS FROM drivers LIKE 'branch_id'");
if ($check_drivers_column && $check_drivers_column->num_rows > 0) {
    $drivers_branch_column_exists = true;
}

// Check if so_id column exists in invoices table
$invoice_so_column_exists = false;
$check_invoice_column = $conn->query("SHOW COLUMNS FROM invoices LIKE 'so_id'");
if ($check_invoice_column && $check_invoice_column->num_rows > 0) {
    $invoice_so_column_exists = true;
}

// Check if trip_tickets has additional columns
$trip_has_so_id = false;
$check_trip_so = $conn->query("SHOW COLUMNS FROM trip_tickets LIKE 'so_id'");
if ($check_trip_so && $check_trip_so->num_rows > 0) {
    $trip_has_so_id = true;
}

$trip_has_picklist_id = false;
$check_trip_picklist = $conn->query("SHOW COLUMNS FROM trip_tickets LIKE 'picklist_id'");
if ($check_trip_picklist && $check_trip_picklist->num_rows > 0) {
    $trip_has_picklist_id = true;
}

// Check if inventory_transactions table exists
$inventory_transactions_exists = false;
$check_inv_trans = $conn->query("SHOW TABLES LIKE 'inventory_transactions'");
if ($check_inv_trans && $check_inv_trans->num_rows > 0) {
    $inventory_transactions_exists = true;
}

// Determine branch filter condition
$branch_condition = "";
if ($so_branch_column_exists && !$view_all_branches) {
    $branch_condition = "AND so.branch_id = $branch_id";
}

$customers_branch_condition = "";
if ($customers_branch_column_exists && !$view_all_branches) {
    $customers_branch_condition = "AND branch_id = $branch_id";
}

$drivers_branch_condition = "";
if ($drivers_branch_column_exists && !$view_all_branches) {
    $drivers_branch_condition = "AND branch_id = $branch_id";
}

// Calculate current week sales for default stat card (Monday to Saturday)
$current_week_sales_query = "
    SELECT COALESCE(SUM(total_amount), 0) as week_total 
    FROM sales_orders 
    WHERE order_status IN ('delivered', 'confirmed', 'processing', 'ready')
    AND YEARWEEK(created_at, 1) = YEARWEEK(NOW(), 1)
    AND DATE(created_at) <= CURDATE() -- Only include up to today (Saturday)
";

if ($so_branch_column_exists && !$view_all_branches && $branch_id > 0) {
    $current_week_sales_query .= " AND branch_id = $branch_id";
}

$current_week_sales_result = $conn->query($current_week_sales_query);
$current_week_sales = $current_week_sales_result->fetch_assoc()['week_total'] ?? 0;
$statCurrentWeekSales = '₱' . number_format($current_week_sales / 1000, 1) . 'K';

// Get current week start and end dates for display (Monday to Saturday)
$week_start = new DateTime();
$week_start->modify('monday this week');
$week_end = new DateTime();
$week_end->modify('saturday this week');
$week_start_str = $week_start->format('Y-m-d');
$week_end_str = $week_end->format('Y-m-d');

// Set default date range to current week (Monday to Saturday)
$default_start_date = $week_start_str;
$default_end_date = $week_end_str;

// ========== GET AVAILABLE DRIVERS FOR DROPDOWN ==========
// Updated logic: Only exclude drivers with active IN-TRANSIT trips
// Drivers with pending/planned deliveries can still be assigned
$available_drivers_query = "
    SELECT 
        d.driver_id, 
        d.driver_name, 
        d.vehicle_plate_number, 
        d.vehicle_type,
        d.status,
        (
            SELECT COUNT(*) 
            FROM pick_lists pl 
            JOIN sales_orders so ON pl.so_id = so.so_id 
            WHERE pl.driver_id = d.driver_id 
            AND so.order_status IN ('confirmed', 'processing', 'ready')
            AND pl.pick_status NOT IN ('completed', 'cancelled')
        ) as pending_deliveries,
        (
            SELECT COUNT(*) 
            FROM trip_tickets tt 
            WHERE tt.driver_id = d.driver_id 
            AND tt.trip_status = 'in-progress'
        ) as active_trips
    FROM drivers d
    WHERE d.status = 'active'
";

// Add branch filter
if ($drivers_branch_column_exists && !$view_all_branches && $branch_id > 0) {
    $available_drivers_query .= " AND d.branch_id = $branch_id";
}

// Only exclude drivers with ACTIVE IN-TRANSIT trips
// Drivers with pending deliveries can still be assigned
$available_drivers_query .= " HAVING active_trips = 0 ORDER BY pending_deliveries DESC, d.driver_name";

$available_drivers_result = $conn->query($available_drivers_query);
$available_drivers = $available_drivers_result ? $available_drivers_result->fetch_all(MYSQLI_ASSOC) : [];

// Get drivers with pending deliveries for grouping
$drivers_with_pending = array_filter($available_drivers, function($d) { 
    return $d['pending_deliveries'] > 0; 
});
$available_drivers_without_pending = array_filter($available_drivers, function($d) { 
    return $d['pending_deliveries'] == 0; 
});

// ========== HANDLE AJAX REQUESTS ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    try {
        $conn->begin_transaction();
        
        // UPDATE SALES ORDER
        if ($_POST['action'] === 'update_order') {
            $so_id = (int)$_POST['so_id'];
            $created_at = $_POST['created_at'];
            $order_status = $_POST['order_status'];
            $total_amount = (float)$_POST['total_amount'];
            $selected_driver_id = isset($_POST['driver_id']) && !empty($_POST['driver_id']) ? (int)$_POST['driver_id'] : null;
            
            // Get the old status and branch info
            $status_query = "SELECT order_status, customer_id, branch_id, so_number FROM sales_orders WHERE so_id = ?";
            $status_stmt = $conn->prepare($status_query);
            $status_stmt->bind_param("i", $so_id);
            $status_stmt->execute();
            $order_info = $status_stmt->get_result()->fetch_assoc();
            $old_status = $order_info['order_status'];
            $order_branch_id = $order_info['branch_id'];
            
            // Verify order belongs to user's branch
            if ($so_branch_column_exists && !$view_all_branches) {
                $check_query = "SELECT so_id FROM sales_orders WHERE so_id = ? AND branch_id = ?";
                $check_stmt = $conn->prepare($check_query);
                $check_stmt->bind_param("ii", $so_id, $branch_id);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                
                if ($check_result->num_rows === 0) {
                    throw new Exception('Order not found or access denied');
                }
            }
            
            // Update the sales order
            $update_query = "UPDATE sales_orders 
                           SET created_at = ?, order_status = ?, total_amount = ?, updated_at = NOW() 
                           WHERE so_id = ?";
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->bind_param("ssdi", $created_at, $order_status, $total_amount, $so_id);
            
            if (!$update_stmt->execute()) {
                throw new Exception('Failed to update sales order');
            }
            
            // If order is being confirmed (from pending to confirmed)
            if ($order_status === 'confirmed' && $old_status === 'pending') {
                
                // Validate that a driver was selected
                if (!$selected_driver_id) {
                    throw new Exception('Please select a driver for this delivery');
                }
                
                // Verify that the selected driver exists and belongs to the correct branch
                $check_driver_query = "SELECT driver_id, driver_name FROM drivers WHERE driver_id = ? AND status = 'active'";
                if ($drivers_branch_column_exists && !$view_all_branches) {
                    $check_driver_query .= " AND branch_id = ?";
                    $check_driver_stmt = $conn->prepare($check_driver_query);
                    $check_driver_stmt->bind_param("ii", $selected_driver_id, $order_branch_id);
                } else {
                    $check_driver_stmt = $conn->prepare($check_driver_query);
                    $check_driver_stmt->bind_param("i", $selected_driver_id);
                }
                
                $check_driver_stmt->execute();
                $driver_result = $check_driver_stmt->get_result();
                
                if ($driver_result->num_rows === 0) {
                    throw new Exception('Selected driver is not available or does not belong to this branch');
                }
                
                $driver_data = $driver_result->fetch_assoc();
                $driver_name = $driver_data['driver_name'];
                
                // 1. CREATE PICK LIST with selected driver
                $pick_list_number = 'PL-' . date('Ymd') . '-' . str_pad($so_id, 5, '0', STR_PAD_LEFT);
                
                $picklist_query = "INSERT INTO pick_lists (pick_list_number, so_id, branch_id, driver_id, pick_status, created_at) 
                                  VALUES (?, ?, ?, ?, 'open', NOW())";
                $picklist_stmt = $conn->prepare($picklist_query);
                $picklist_stmt->bind_param("siii", $pick_list_number, $so_id, $order_branch_id, $selected_driver_id);
                
                if (!$picklist_stmt->execute()) {
                    throw new Exception('Failed to create pick list');
                }
                $picklist_id = $conn->insert_id;
                
                // ADD ITEMS TO PICK LIST
                $items_query = "SELECT item_id, quantity_ordered FROM sales_order_items WHERE so_id = ?";
                $items_stmt = $conn->prepare($items_query);
                $items_stmt->bind_param("i", $so_id);
                $items_stmt->execute();
                $items_result = $items_stmt->get_result();
                
                $pick_items_query = "INSERT INTO pick_list_items (pick_list_id, item_id, quantity_to_pick) VALUES (?, ?, ?)";
                $pick_items_stmt = $conn->prepare($pick_items_query);
                
                while ($item = $items_result->fetch_assoc()) {
                    $pick_items_stmt->bind_param("iii", $picklist_id, $item['item_id'], $item['quantity_ordered']);
                    $pick_items_stmt->execute();
                }
                
                // 2. CREATE INVOICE (if so_id column exists)
                if ($invoice_so_column_exists) {
                    $invoice_number = 'INV-' . date('Ymd') . '-' . str_pad($so_id, 5, '0', STR_PAD_LEFT);
                    $invoice_date = date('Y-m-d');
                    $due_date = date('Y-m-d', strtotime('+30 days'));
                    
                    $invoice_query = "INSERT INTO invoices (invoice_number, so_id, customer_id, branch_id, invoice_date, due_date, total_amount, status) 
                                     VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')";
                    $invoice_stmt = $conn->prepare($invoice_query);
                    $invoice_stmt->bind_param("siiissd", $invoice_number, $so_id, $order_info['customer_id'], $order_branch_id, $invoice_date, $due_date, $total_amount);
                    
                    if (!$invoice_stmt->execute()) {
                        throw new Exception('Failed to create invoice');
                    }
                    $invoice_id = $conn->insert_id;
                }
                
                // 3. CREATE TRIP TICKET with selected driver
                $trip_ticket_number = 'TT-' . date('Ymd') . '-' . str_pad($so_id, 5, '0', STR_PAD_LEFT);
                $trip_date = date('Y-m-d');
                
                // Base required fields for trip_tickets
                $trip_fields = "trip_number, driver_id, branch_id, trip_date, trip_status, created_by, created_at";
                $trip_values = "?, ?, ?, ?, 'planned', ?, NOW()";
                $trip_types = "siisi"; // string, int, int, string, int
                $trip_params = [$trip_ticket_number, $selected_driver_id, $order_branch_id, $trip_date, $user_id];
                
                // Add optional fields if they exist
                if ($trip_has_so_id) {
                    $trip_fields .= ", so_id";
                    $trip_values .= ", ?";
                    $trip_types .= "i";
                    $trip_params[] = $so_id;
                }
                
                if ($trip_has_picklist_id) {
                    $trip_fields .= ", picklist_id";
                    $trip_values .= ", ?";
                    $trip_types .= "i";
                    $trip_params[] = $picklist_id;
                }
                
                $trip_ticket_query = "INSERT INTO trip_tickets ($trip_fields) VALUES ($trip_values)";
                $trip_ticket_stmt = $conn->prepare($trip_ticket_query);
                
                // Dynamically bind parameters
                $trip_ticket_stmt->bind_param($trip_types, ...$trip_params);
                
                if (!$trip_ticket_stmt->execute()) {
                    throw new Exception('Failed to create trip ticket: ' . $trip_ticket_stmt->error);
                }
                
                // 4. DEDUCT INVENTORY WHEN ORDER IS CONFIRMED
                $items_query = "SELECT item_id, quantity_ordered FROM sales_order_items WHERE so_id = ?";
                $items_stmt = $conn->prepare($items_query);
                $items_stmt->bind_param("i", $so_id);
                $items_stmt->execute();
                $items_result = $items_stmt->get_result();
                
                while ($item = $items_result->fetch_assoc()) {
                    $item_id = $item['item_id'];
                    $quantity = $item['quantity_ordered'];
                    
                    // Check current stock
                    $stock_query = "SELECT stock FROM items WHERE item_id = ?";
                    $stock_stmt = $conn->prepare($stock_query);
                    $stock_stmt->bind_param("i", $item_id);
                    $stock_stmt->execute();
                    $stock_result = $stock_stmt->get_result();
                    $current_stock = $stock_result->fetch_assoc()['stock'];
                    
                    if ($current_stock < $quantity) {
                        throw new Exception("Insufficient stock for item ID: $item_id. Available: $current_stock, Required: $quantity");
                    }
                    
                    // Update stock
                    $new_stock = $current_stock - $quantity;
                    $update_stock_query = "UPDATE items SET stock = ?, updated_at = NOW() WHERE item_id = ?";
                    $update_stock_stmt = $conn->prepare($update_stock_query);
                    $update_stock_stmt->bind_param("ii", $new_stock, $item_id);
                    
                    if (!$update_stock_stmt->execute()) {
                        throw new Exception("Failed to update stock for item ID: $item_id");
                    }
                    
                    // Record inventory transaction if table exists
                    if ($inventory_transactions_exists) {
                        $trans_query = "INSERT INTO inventory_transactions 
                                       (branch_id, item_id, transaction_type, quantity_changed, reference_type, reference_id, created_by, created_at) 
                                       VALUES (?, ?, 'out', ?, 'sales_order', ?, ?, NOW())";
                        $trans_stmt = $conn->prepare($trans_query);
                        $trans_stmt->bind_param("iiiii", $order_branch_id, $item_id, $quantity, $so_id, $user_id);
                        $trans_stmt->execute();
                    }
                }
            }
            
            // If order is being marked as delivered - UPDATE RELATED RECORDS
            if ($order_status === 'delivered' && $old_status !== 'delivered') {
                
                // 1. Update pick list status to completed
                $update_pl_query = "UPDATE pick_lists SET pick_status = 'completed', updated_at = NOW() WHERE so_id = ?";
                $update_pl_stmt = $conn->prepare($update_pl_query);
                $update_pl_stmt->bind_param("i", $so_id);
                $update_pl_stmt->execute();
                
                // 2. Update trip ticket status to completed
                if ($trip_has_so_id) {
                    $update_tt_query = "UPDATE trip_tickets SET trip_status = 'completed', updated_at = NOW() WHERE so_id = ?";
                    $update_tt_stmt = $conn->prepare($update_tt_query);
                    $update_tt_stmt->bind_param("i", $so_id);
                    $update_tt_stmt->execute();
                }
                
                // 3. Update invoice status to paid
                if ($invoice_so_column_exists) {
                    $update_invoice_query = "UPDATE invoices SET status = 'paid' WHERE so_id = ?";
                    $update_invoice_stmt = $conn->prepare($update_invoice_query);
                    $update_invoice_stmt->bind_param("i", $so_id);
                    $update_invoice_stmt->execute();
                }
            }
            
            // If order is being cancelled - UPDATE RELATED RECORDS
            if ($order_status === 'cancelled' && $old_status !== 'cancelled') {
                
                // 1. Update pick list status to cancelled
                $update_pl_query = "UPDATE pick_lists SET pick_status = 'cancelled', updated_at = NOW() WHERE so_id = ?";
                $update_pl_stmt = $conn->prepare($update_pl_query);
                $update_pl_stmt->bind_param("i", $so_id);
                $update_pl_stmt->execute();
                
                // 2. Update trip ticket status to cancelled
                if ($trip_has_so_id) {
                    $update_tt_query = "UPDATE trip_tickets SET trip_status = 'cancelled', updated_at = NOW() WHERE so_id = ?";
                    $update_tt_stmt = $conn->prepare($update_tt_query);
                    $update_tt_stmt->bind_param("i", $so_id);
                    $update_tt_stmt->execute();
                }
                
                // 3. Update invoice status to cancelled
                if ($invoice_so_column_exists) {
                    $update_invoice_query = "UPDATE invoices SET status = 'cancelled' WHERE so_id = ?";
                    $update_invoice_stmt = $conn->prepare($update_invoice_query);
                    $update_invoice_stmt->bind_param("i", $so_id);
                    $update_invoice_stmt->execute();
                }
            }
            
            $conn->commit();
            
            // Prepare response message
            $response_message = 'Sales order updated successfully';
            $generated_docs = [];
            
            if ($order_status === 'confirmed' && $old_status === 'pending') {
                $response_message = 'Order confirmed successfully! Pick List, Trip Ticket have been generated. Inventory has been updated.';
                $generated_docs = [
                    'picklist' => $pick_list_number,
                    'trip_ticket' => $trip_ticket_number,
                    'driver_id' => $selected_driver_id,
                    'driver_name' => $driver_name
                ];
                
                if ($invoice_so_column_exists) {
                    $response_message = 'Order confirmed successfully! Pick List, Invoice, and Trip Ticket have been generated. Inventory has been updated.';
                    $generated_docs['invoice'] = $invoice_number;
                }
            }
            
            echo json_encode([
                'success' => true,
                'message' => $response_message,
                'generated_docs' => $generated_docs
            ]);
            exit;
        }
        
        // GET AVAILABLE DRIVERS (for refreshing the dropdown)
        elseif ($_POST['action'] === 'get_available_drivers') {
            $branch_id_param = (int)$_POST['branch_id'];
            
            $query = "
                SELECT 
                    d.driver_id, 
                    d.driver_name, 
                    d.vehicle_plate_number, 
                    d.vehicle_type,
                    d.status,
                    (
                        SELECT COUNT(*) 
                        FROM pick_lists pl 
                        JOIN sales_orders so ON pl.so_id = so.so_id 
                        WHERE pl.driver_id = d.driver_id 
                        AND so.order_status IN ('confirmed', 'processing', 'ready')
                        AND pl.pick_status NOT IN ('completed', 'cancelled')
                    ) as pending_deliveries,
                    (
                        SELECT COUNT(*) 
                        FROM trip_tickets tt 
                        WHERE tt.driver_id = d.driver_id 
                        AND tt.trip_status = 'in-progress'
                    ) as active_trips
                FROM drivers d
                WHERE d.status = 'active'
                AND d.branch_id = ?
                HAVING active_trips = 0
                ORDER BY pending_deliveries DESC, d.driver_name
            ";
            
            $stmt = $conn->prepare($query);
            $stmt->bind_param("i", $branch_id_param);
            $stmt->execute();
            $result = $stmt->get_result();
            $drivers = $result->fetch_all(MYSQLI_ASSOC);
            
            echo json_encode([
                'success' => true,
                'drivers' => $drivers
            ]);
            exit;
        }
        
        // DELETE SALES ORDER
        elseif ($_POST['action'] === 'delete_order') {
            $so_id = (int)$_POST['so_id'];
            
            // Verify order belongs to user's branch
            if ($so_branch_column_exists && !$view_all_branches) {
                $check_query = "SELECT so_id FROM sales_orders WHERE so_id = ? AND branch_id = ?";
                $check_stmt = $conn->prepare($check_query);
                $check_stmt->bind_param("ii", $so_id, $branch_id);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                
                if ($check_result->num_rows === 0) {
                    throw new Exception('Order not found or access denied');
                }
            }
            
            // Check if order has related records
            $check_picklist_query = "SELECT COUNT(*) as count FROM pick_lists WHERE so_id = ?";
            $check_picklist_stmt = $conn->prepare($check_picklist_query);
            $check_picklist_stmt->bind_param("i", $so_id);
            $check_picklist_stmt->execute();
            $picklist_count = $check_picklist_stmt->get_result()->fetch_assoc()['count'];
            
            if ($picklist_count > 0) {
                throw new Exception('Cannot delete order with existing pick lists');
            }
            
            // Check for invoices (if column exists)
            if ($invoice_so_column_exists) {
                $check_invoice_query = "SELECT COUNT(*) as count FROM invoices WHERE so_id = ?";
                $check_invoice_stmt = $conn->prepare($check_invoice_query);
                $check_invoice_stmt->bind_param("i", $so_id);
                $check_invoice_stmt->execute();
                $invoice_count = $check_invoice_stmt->get_result()->fetch_assoc()['count'];
                
                if ($invoice_count > 0) {
                    throw new Exception('Cannot delete order with existing invoices');
                }
            }
            
            // Check for trip tickets
            if ($trip_has_so_id) {
                $check_trip_query = "SELECT COUNT(*) as count FROM trip_tickets WHERE so_id = ?";
                $check_trip_stmt = $conn->prepare($check_trip_query);
                $check_trip_stmt->bind_param("i", $so_id);
                $check_trip_stmt->execute();
                $trip_count = $check_trip_stmt->get_result()->fetch_assoc()['count'];
                
                if ($trip_count > 0) {
                    throw new Exception('Cannot delete order with existing trip tickets');
                }
            }
            
            // Delete order items first
            $delete_items_query = "DELETE FROM sales_order_items WHERE so_id = ?";
            $delete_items_stmt = $conn->prepare($delete_items_query);
            $delete_items_stmt->bind_param("i", $so_id);
            $delete_items_stmt->execute();
            
            // Delete the order
            $delete_order_query = "DELETE FROM sales_orders WHERE so_id = ?";
            $delete_order_stmt = $conn->prepare($delete_order_query);
            $delete_order_stmt->bind_param("i", $so_id);
            
            if (!$delete_order_stmt->execute()) {
                throw new Exception('Failed to delete sales order');
            }
            
            $conn->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'Sales order deleted successfully'
            ]);
            exit;
        }
        
        // GET SALES ORDER DETAILS
        elseif ($_POST['action'] === 'get_order') {
            $so_id = (int)$_POST['so_id'];
            
            $query = "
                SELECT 
                    so.*,
                    c.customer_name,
                    c.customer_id,
                    c.address,
                    c.phone_number as contact_number,
                    c.email,
                    b.branch_name,
                    COUNT(soi.so_item_id) as total_items,
                    SUM(soi.quantity_ordered) as total_quantity
                FROM sales_orders so
                JOIN customers c ON so.customer_id = c.customer_id
                LEFT JOIN branches b ON so.branch_id = b.branch_id
                LEFT JOIN sales_order_items soi ON so.so_id = soi.so_id
                WHERE so.so_id = ?
                GROUP BY so.so_id
            ";
            
            if ($so_branch_column_exists && !$view_all_branches) {
                $query .= " AND so.branch_id = ?";
                $stmt = $conn->prepare($query);
                $stmt->bind_param("ii", $so_id, $branch_id);
            } else {
                $stmt = $conn->prepare($query);
                $stmt->bind_param("i", $so_id);
            }
            
            $stmt->execute();
            $result = $stmt->get_result();
            $order = $result->fetch_assoc();
            
            if ($order) {
                // Get order items - use soi.unit_type for historical accuracy
                $items_query = "
                    SELECT 
                        soi.*,
                        i.item_code,
                        i.item_name,
                        soi.unit_type,
                        i.price_case,
                        i.price_inner_pack,
                        i.price_box,
                        i.price_carton
                    FROM sales_order_items soi
                    JOIN items i ON soi.item_id = i.item_id
                    WHERE soi.so_id = ?
                ";
                $items_stmt = $conn->prepare($items_query);
                $items_stmt->bind_param("i", $so_id);
                $items_stmt->execute();
                $items_result = $items_stmt->get_result();
                $items = $items_result->fetch_all(MYSQLI_ASSOC);
                
                // Get generated documents including driver info
                $documents = [];
                
                // Get pick list info
                $pl_query = "SELECT pick_list_number, driver_id FROM pick_lists WHERE so_id = ? LIMIT 1";
                $pl_stmt = $conn->prepare($pl_query);
                $pl_stmt->bind_param("i", $so_id);
                $pl_stmt->execute();
                $pl_result = $pl_stmt->get_result();
                if ($pl_row = $pl_result->fetch_assoc()) {
                    $documents['pick_list_number'] = $pl_row['pick_list_number'];
                    $documents['picklist_driver_id'] = $pl_row['driver_id'];
                    
                    if (!empty($pl_row['driver_id'])) {
                        $driver_query = "SELECT driver_name FROM drivers WHERE driver_id = ?";
                        $driver_stmt = $conn->prepare($driver_query);
                        $driver_stmt->bind_param("i", $pl_row['driver_id']);
                        $driver_stmt->execute();
                        $driver_result = $driver_stmt->get_result();
                        $driver = $driver_result->fetch_assoc();
                        $documents['driver_name'] = $driver['driver_name'] ?? 'Unknown Driver';
                    }
                }
                
                // Get trip ticket info
                if ($trip_has_so_id) {
                    $tt_query = "SELECT trip_number FROM trip_tickets WHERE so_id = ? LIMIT 1";
                    $tt_stmt = $conn->prepare($tt_query);
                    $tt_stmt->bind_param("i", $so_id);
                    $tt_stmt->execute();
                    $tt_result = $tt_stmt->get_result();
                    if ($tt_row = $tt_result->fetch_assoc()) {
                        $documents['trip_ticket_number'] = $tt_row['trip_number'];
                    }
                }
                
                // Get invoice data
                $invoice = null;
                if ($invoice_so_column_exists) {
                    $invoice_query = "SELECT invoice_id, invoice_number, status as invoice_status, due_date FROM invoices WHERE so_id = ? LIMIT 1";
                    $invoice_stmt = $conn->prepare($invoice_query);
                    $invoice_stmt->bind_param("i", $so_id);
                    $invoice_stmt->execute();
                    $invoice_result = $invoice_stmt->get_result();
                    $invoice = $invoice_result->fetch_assoc();
                }
                
                echo json_encode([
                    'success' => true,
                    'order' => $order,
                    'items' => $items,
                    'documents' => $documents,
                    'invoice' => $invoice
                ]);
            } else {
                throw new Exception('Sales order not found');
            }
            exit;
        }
        
        // GET DETAILED ORDER ITEMS FOR EXPORT/PRINT
        elseif ($_POST['action'] === 'get_order_items_detailed') {
            $so_id = (int)$_POST['so_id'];
            
            $query = "
                SELECT 
                    soi.*,
                    so.so_number,
                    so.created_at as order_date,
                    so.order_status,
                    so.total_amount as order_total,
                    c.customer_name,
                    c.customer_id,
                    b.branch_name,
                    b.branch_id,
                    i.item_code,
                    i.item_name,
                    i.unit_type as item_unit_type
                FROM sales_order_items soi
                JOIN sales_orders so ON soi.so_id = so.so_id
                JOIN customers c ON so.customer_id = c.customer_id
                LEFT JOIN branches b ON so.branch_id = b.branch_id
                JOIN items i ON soi.item_id = i.item_id
                WHERE soi.so_id = ?
            ";
            
            if ($so_branch_column_exists && !$view_all_branches) {
                $query .= " AND so.branch_id = ?";
                $stmt = $conn->prepare($query);
                $stmt->bind_param("ii", $so_id, $branch_id);
            } else {
                $stmt = $conn->prepare($query);
                $stmt->bind_param("i", $so_id);
            }
            
            $stmt->execute();
            $result = $stmt->get_result();
            $items = $result->fetch_all(MYSQLI_ASSOC);
            
            echo json_encode([
                'success' => true,
                'items' => $items
            ]);
            exit;
        }
        
        // GET ALL ORDER ITEMS FOR EXPORT/PRINT WITH FILTERS
        elseif ($_POST['action'] === 'get_all_order_items') {
            $filter_data = json_decode($_POST['filter_data'] ?? '{}', true);
            
            $query = "
                SELECT 
                    soi.so_item_id,
                    soi.so_id,
                    soi.item_id,
                    soi.quantity_ordered,
                    soi.quantity_delivered,
                    soi.unit_price,
                    soi.unit_type,
                    so.so_number,
                    so.created_at as order_date,
                    so.order_status,
                    so.total_amount as order_total,
                    c.customer_name,
                    c.customer_id,
                    b.branch_name,
                    b.branch_id,
                    i.item_code,
                    i.item_name
                FROM sales_order_items soi
                JOIN sales_orders so ON soi.so_id = so.so_id
                JOIN customers c ON so.customer_id = c.customer_id
                LEFT JOIN branches b ON so.branch_id = b.branch_id
                JOIN items i ON soi.item_id = i.item_id
                WHERE 1=1
            ";
            
            // Apply filters
            if (!empty($filter_data['status']) && $filter_data['status'] !== '') {
                $query .= " AND so.order_status = '" . $conn->real_escape_string($filter_data['status']) . "'";
            }
            
            if (!empty($filter_data['customer']) && $filter_data['customer'] !== '') {
                $query .= " AND c.customer_name = '" . $conn->real_escape_string($filter_data['customer']) . "'";
            }
            
            if (!empty($filter_data['search']) && $filter_data['search'] !== '') {
                $search = $conn->real_escape_string($filter_data['search']);
                $query .= " AND (so.so_number LIKE '%$search%' OR c.customer_name LIKE '%$search%' OR i.item_name LIKE '%$search%' OR i.item_code LIKE '%$search%')";
            }
            
            // Apply date range filter
            if (!empty($filter_data['start_date']) && !empty($filter_data['end_date'])) {
                $start_date = $conn->real_escape_string($filter_data['start_date']);
                $end_date = $conn->real_escape_string($filter_data['end_date']);
                $query .= " AND DATE(so.created_at) BETWEEN '$start_date' AND '$end_date'";
            }
            
            // Apply branch filter
            if ($so_branch_column_exists && !$view_all_branches) {
                $query .= " AND so.branch_id = $branch_id";
            }
            
            $query .= " ORDER BY so.created_at DESC, so.so_id DESC, soi.so_item_id";
            
            $result = $conn->query($query);
            $items = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
            
            echo json_encode([
                'success' => true,
                'items' => $items,
                'branch_name' => $branch_id ? ('Branch ' . $branch_id) : 'All Branches',
                'view_all' => $view_all_branches
            ]);
            exit;
        }
        
        // PRINT SALES ORDER (single order with detailed items)
        elseif ($_POST['action'] === 'print_order') {
            $so_id = (int)$_POST['so_id'];
            
            $query = "
                SELECT 
                    soi.so_item_id,
                    soi.so_id,
                    soi.item_id,
                    soi.quantity_ordered,
                    soi.quantity_delivered,
                    soi.unit_price,
                    soi.unit_type,
                    so.so_number,
                    so.created_at as order_date,
                    so.order_status,
                    so.total_amount as order_total,
                    c.customer_name,
                    c.customer_id,
                    c.address,
                    c.phone_number as contact_number,
                    c.email,
                    b.branch_name,
                    b.address as branch_address,
                    b.contact_number as branch_contact,
                    u.first_name,
                    u.last_name,
                    i.item_code,
                    i.item_name
                FROM sales_order_items soi
                JOIN sales_orders so ON soi.so_id = so.so_id
                JOIN customers c ON so.customer_id = c.customer_id
                LEFT JOIN branches b ON so.branch_id = b.branch_id
                LEFT JOIN users u ON so.created_by = u.user_id
                JOIN items i ON soi.item_id = i.item_id
                WHERE soi.so_id = ?
                ORDER BY soi.so_item_id
            ";
            
            $stmt = $conn->prepare($query);
            $stmt->bind_param("i", $so_id);
            $stmt->execute();
            $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            
            $driver_query = "
                SELECT d.driver_name, d.vehicle_plate_number, d.vehicle_type
                FROM pick_lists pl
                JOIN drivers d ON pl.driver_id = d.driver_id
                WHERE pl.so_id = ?
                LIMIT 1
            ";
            $driver_stmt = $conn->prepare($driver_query);
            $driver_stmt->bind_param("i", $so_id);
            $driver_stmt->execute();
            $driver = $driver_stmt->get_result()->fetch_assoc();
            
            // Get order summary from first item
            $order_summary = !empty($items) ? $items[0] : null;
            
            echo json_encode([
                'success' => true,
                'order' => $order_summary,
                'items' => $items,
                'driver' => $driver
            ]);
            exit;
        }
        
        // GET INVOICE DETAILS
        elseif ($_POST['action'] === 'get_invoice') {
            if (!$invoice_so_column_exists) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Invoice functionality not available. Please run SQL to add relationship: ALTER TABLE invoices ADD COLUMN so_id INT NULL;'
                ]);
                exit;
            }
            
            $so_id = (int)$_POST['so_id'];
            
            $query = "SELECT * FROM invoices WHERE so_id = ?";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("i", $so_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $invoice = $result->fetch_assoc();
            
            if ($invoice) {
                echo json_encode([
                    'success' => true,
                    'invoice' => $invoice
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => 'Invoice not found'
                ]);
            }
            exit;
        }
        
        // UPDATE INVOICE STATUS
        elseif ($_POST['action'] === 'update_invoice_status') {
            if (!$invoice_so_column_exists) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Invoice functionality not available'
                ]);
                exit;
            }
            
            $invoice_id = (int)$_POST['invoice_id'];
            $status = $_POST['status'];
            
            $update_query = "UPDATE invoices SET status = ? WHERE invoice_id = ?";
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->bind_param("si", $status, $invoice_id);
            
            if ($update_stmt->execute()) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Invoice status updated successfully'
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => 'Failed to update invoice status'
                ]);
            }
            exit;
        }
        
        // CHECK STOCK AVAILABILITY
        elseif ($_POST['action'] === 'check_stock') {
            $so_id = (int)$_POST['so_id'];
            
            $items_query = "
                SELECT 
                    soi.item_id,
                    soi.quantity_ordered,
                    i.item_code,
                    i.item_name,
                    i.stock as available_stock
                FROM sales_order_items soi
                JOIN items i ON soi.item_id = i.item_id
                WHERE soi.so_id = ?
            ";
            $items_stmt = $conn->prepare($items_query);
            $items_stmt->bind_param("i", $so_id);
            $items_stmt->execute();
            $items = $items_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            
            $insufficient_items = [];
            foreach ($items as $item) {
                if ($item['available_stock'] < $item['quantity_ordered']) {
                    $insufficient_items[] = [
                        'item_code' => $item['item_code'],
                        'item_name' => $item['item_name'],
                        'required' => $item['quantity_ordered'],
                        'available' => $item['available_stock']
                    ];
                }
            }
            
            if (empty($insufficient_items)) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Stock is sufficient',
                    'sufficient' => true
                ]);
            } else {
                echo json_encode([
                    'success' => true,
                    'message' => 'Some items have insufficient stock',
                    'sufficient' => false,
                    'insufficient_items' => $insufficient_items
                ]);
            }
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

// FETCH SALES ORDERS WITH CUSTOMER, ITEM COUNTS, AND INVOICE DATA
$sales_query = "
    SELECT 
        so.so_id,
        so.so_number,
        so.created_at,
        so.total_amount,
        so.order_status,
        so.branch_id,
        c.customer_name,
        c.customer_id,
        b.branch_name,
        u.first_name,
        u.last_name,
        COUNT(DISTINCT soi.so_item_id) as total_items,
        SUM(soi.quantity_ordered) as total_quantity,
        " . ($invoice_so_column_exists ? "inv.invoice_id, inv.invoice_number, inv.status as invoice_status" : "NULL as invoice_id, NULL as invoice_number, NULL as invoice_status") . ",
        (SELECT driver_name FROM drivers WHERE driver_id = pl.driver_id LIMIT 1) as assigned_driver
    FROM sales_orders so
    JOIN customers c ON so.customer_id = c.customer_id
    LEFT JOIN branches b ON so.branch_id = b.branch_id
    LEFT JOIN users u ON so.created_by = u.user_id
    LEFT JOIN sales_order_items soi ON so.so_id = soi.so_id
    LEFT JOIN pick_lists pl ON so.so_id = pl.so_id
    " . ($invoice_so_column_exists ? "LEFT JOIN invoices inv ON so.so_id = inv.so_id" : "") . "
    WHERE 1=1
    $branch_condition
    GROUP BY so.so_id
    ORDER BY so.created_at DESC, so.so_id DESC
";
$sales_result = $conn->query($sales_query);
if (!$sales_result) {
    die("Query failed: " . $conn->error);
}
$sales_orders = $sales_result->fetch_all(MYSQLI_ASSOC);

// CALCULATE STATISTICS
$total_orders = count($sales_orders);
$pending_orders = count(array_filter($sales_orders, fn($so) => $so['order_status'] === 'pending'));
$processing_orders = count(array_filter($sales_orders, fn($so) => $so['order_status'] === 'processing'));
$ready_orders = count(array_filter($sales_orders, fn($so) => $so['order_status'] === 'ready'));
$delivered_orders = count(array_filter($sales_orders, fn($so) => $so['order_status'] === 'delivered'));
$cancelled_orders = count(array_filter($sales_orders, fn($so) => $so['order_status'] === 'cancelled'));

$statTotalOrders = $total_orders;
$statPendingOrders = $pending_orders;
$statCompletedOrders = $delivered_orders;

// Get unique customers for filter
$customers_query = "SELECT customer_id, customer_name FROM customers WHERE status = 'active' $customers_branch_condition ORDER BY customer_name";
$customers_result = $conn->query($customers_query);
$customers = $customers_result->fetch_all(MYSQLI_ASSOC);

// Get base64 encoded logo for printing
$logo_path = '../Pictures/amgc3DLogo.png';
$logo_base64 = '';
if (file_exists($logo_path)) {
    $image_data = file_get_contents($logo_path);
    $logo_base64 = 'data:image/png;base64,' . base64_encode($image_data);
}

// Helper functions
function getOrderStatusBadge($status) {
    return match($status) {
        'pending' => 'badge bg-warning text-dark',
        'confirmed' => 'badge bg-info text-white',
        'processing' => 'badge bg-primary text-white',
        'ready' => 'badge bg-info text-white',
        'delivered' => 'badge bg-success text-white',
        'cancelled' => 'badge bg-danger text-white',
        default => 'badge bg-secondary text-white'
    };
}

function getOrderStatusText($status) {
    return match($status) {
        'pending' => 'Pending',
        'confirmed' => 'Confirmed',
        'processing' => 'Processing',
        'ready' => 'For Delivery',
        'delivered' => 'Delivered',
        'cancelled' => 'Cancelled',
        default => ucfirst($status)
    };
}

function getPaymentStatus($order_status, $invoice_status = null) {
    if ($order_status === 'cancelled') return ['status' => 'Cancelled', 'class' => 'bg-danger'];
    if ($order_status === 'delivered') return ['status' => 'Paid', 'class' => 'bg-success'];
    
    if ($invoice_status) {
        return match($invoice_status) {
            'paid' => ['status' => 'Paid', 'class' => 'bg-success'],
            'pending' => ['status' => 'Pending', 'class' => 'bg-warning text-dark'],
            'cancelled' => ['status' => 'Cancelled', 'class' => 'bg-danger'],
            default => ['status' => 'Pending', 'class' => 'bg-warning text-dark']
        };
    }
    
    return ['status' => 'Pending', 'class' => 'bg-warning text-dark'];
}

function formatDate($dateStr) {
    if (!$dateStr) return '';
    $date = new DateTime($dateStr);
    return $date->format('M d, Y');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales Orders - Branch Admin</title>
    <link rel="icon" type="image/png" href="../Pictures/favicon-96x96.png" sizes="96x96" />
    <link rel="icon" type="image/svg+xml" href="../Pictures/favicon.svg" />
    <link rel="shortcut icon" href="../Pictures/favicon.ico" />
    <link rel="apple-touch-icon" sizes="180x180" href="../Pictures/apple-touch-icon.png" />
    <link rel="manifest" href="../Pictures/site.webmanifest" />
    <link rel="stylesheet" href="../css/current_inventory.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://fonts.googleapis.com/css2?family=Tenor+Sans&family=Alice&display=swap" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    
    <style>
        :root {
            --green: #2E7D32;
            --green-haze: #1B5E20;
            --deep-sea: #0D4C14;
            --forest-green: #1B4D1F;
            --yellow: #FFC107;
            --white: #FFFFFF;
            --light-gray: #F5F5F5;
            --black: #212121;
        }

        .branch-badge {
            background-color: #e7f1ff;
            color: #0d6efd;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
            margin-left: 5px;
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
        
        .driver-badge {
            background-color: #e3f2fd;
            color: #0d6efd;
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .driver-badge i {
            font-size: 12px;
        }
        
        .available-badge {
            background-color: #d4edda;
            color: #155724;
            padding: 2px 6px;
            border-radius: 12px;
            font-size: 10px;
            margin-left: 5px;
        }
        
        .busy-badge {
            background-color: #f8d7da;
            color: #721c24;
            padding: 2px 6px;
            border-radius: 12px;
            font-size: 10px;
            margin-left: 5px;
        }

        /* Select2 Custom Styling - Matching Color Palette */
        .select2-container--default .select2-selection--single {
            height: 38px;
            border: 1px solid #ced4da;
            border-radius: 4px;
            background-color: white;
        }

        .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: 38px;
            padding-left: 12px;
            color: #212121;
        }

        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 36px;
        }

        .select2-container--default .select2-results__option--highlighted.select2-results__option--selectable {
            background-color: #2E7D32;
            color: white;
        }

        .select2-container--default .select2-results__option--selected {
            background-color: #e8f5e9;
            color: #1B5E20;
        }

        .select2-container--default .select2-results__group {
            background-color: #f5f5f5;
            color: #0D4C14;
            font-weight: 600;
            padding: 8px 12px;
            border-bottom: 1px solid #dee2e6;
        }

        .select2-container--default .select2-results__option {
            padding: 8px 12px;
        }

        .driver-option {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .driver-option .driver-name {
            font-weight: 500;
            color: #212121;
        }

        .driver-option .driver-vehicle {
            font-size: 11px;
            color: #6c757d;
            margin-left: 8px;
        }

        .driver-option .pending-count {
            background-color: #FFC107;
            color: #212121;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 600;
        }

        .select2-container--default .select2-results__option--highlighted .driver-option .driver-name,
        .select2-container--default .select2-results__option--highlighted .driver-option .driver-vehicle,
        .select2-container--default .select2-results__option--highlighted .driver-option .pending-count {
            color: white;
        }

        .select2-container--default .select2-results__option--highlighted .driver-option .pending-count {
            background-color: rgba(255,255,255,0.3);
            color: white;
        }

        .driver-info-tooltip {
            font-size: 11px;
            padding: 4px 8px;
            background-color: #f8f9fa;
            border-radius: 4px;
            margin-top: 4px;
        }

        .driver-info-tooltip i {
            color: #2E7D32;
            margin-right: 4px;
        }
        
        @media (max-width: 768px) {
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
            .col-md-3, .col-md-4, .col-md-5, .col-md-6 {
                width: 50%;
                padding-left: 8px;
                padding-right: 8px;
            }
            .row.g-3 {
                margin-left: -8px;
                margin-right: -8px;
            }
        }
        
        .action-buttons {
            display: flex;
            gap: 5px;
        }
        
        .btn-action {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
            border: 1px solid transparent;
        }
        
        .btn-view {
            background-color: #e3f2fd;
            color: #1976d2;
            border-color: #bbdefb;
        }
        
        .btn-view:hover {
            background-color: #bbdefb;
            transform: translateY(-2px);
        }
        
        .btn-edit {
            background-color: #fff3e0;
            color: #f57c00;
            border-color: #ffe0b2;
        }
        
        .btn-edit:hover {
            background-color: #ffe0b2;
            transform: translateY(-2px);
        }
        
        .btn-delete {
            background-color: #ffebee;
            color: #d32f2f;
            border-color: #ffcdd2;
        }
        
        .btn-delete:hover {
            background-color: #ffcdd2;
            transform: translateY(-2px);
        }
        
        .btn-print {
            background-color: #e8f5e9;
            color: var(--green);
            border-color: #c8e6c9;
        }
        
        .btn-print:hover {
            background-color: #c8e6c9;
            transform: translateY(-2px);
        }
        
        .db-fix-card {
            background: #fff3cd;
            border: 1px solid #ffe69c;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .db-fix-card pre {
            background: #212529;
            color: #fff;
            padding: 15px;
            border-radius: 5px;
            overflow-x: auto;
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
            
            /* Remove all background colors from badges */
            #printFrame .status-badge-print,
            #printFrame .branch-badge-print,
            #printFrame .driver-badge-print {
                background: white !important;
                border: 1px solid #000 !important;
                color: black !important;
                padding: 2px 6px;
            }
        }
        
        /* Print iframe styles */
        #printFrame {
            position: absolute;
            left: -9999px;
            top: -9999px;
            width: 1px;
            height: 1px;
            opacity: 0;
            pointer-events: none;
        }
        
        .sidebar, .navbar-top, .footer, .action-buttons, 
        .btn, .table-header .btn, .form-card, 
        .mobile-menu-btn, #desktopToggleBtn, .sidebar-footer,
        .stat-card, .alert, .badge:not(.print-badge), .branch-badge,
        .modal, .data-table .table-header button,
        .filter-section, .row.g-3.mb-4, #dashboardSubtitle,
        .db-fix-card, .stock-warning, #dashboardContent .row:first-child,
        .search-box, select, option, .table-header .d-flex,
        .data-table .table-header .btn, .page-title p,
        .modal-backdrop, .modal, .btn-action, .btn-group,
        .page-title i, .action-buttons, .btn-success, .btn-primary,
        .no-print {
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        
        /* Date range summary box */
        .date-range-summary {
            background: linear-gradient(135deg, #e3f2fd, #bbdefb);
            border-left: 4px solid #1976d2;
            border-radius: 8px;
            padding: 12px 16px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        .date-range-summary i {
            color: #1976d2;
            margin-right: 8px;
        }
        .date-range-summary strong {
            color: #0d47a1;
        }
        
        /* Stat card hover effect */
        .stat-card {
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        /* Date range filter styling */
        .date-filter-container {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }
        .date-input-group {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .date-input-group label {
            font-size: 12px;
            font-weight: 600;
            color: #495057;
            margin-bottom: 0;
            white-space: nowrap;
        }
        .date-input-group input {
            width: 140px;
            padding: 8px 12px;
            border: 1px solid #ced4da;
            border-radius: 4px;
            font-size: 13px;
        }
        .date-filter-btn {
            padding: 8px 16px;
            background-color: #1976d2;
            color: white;
            border: none;
            border-radius: 4px;
            font-size: 13px;
            cursor: pointer;
            transition: background-color 0.2s;
        }
        .date-filter-btn:hover {
            background-color: #0d47a1;
        }
        .date-filter-btn:disabled {
            background-color: #6c757d;
            cursor: not-allowed;
        }
        .date-clear-btn {
            padding: 8px 16px;
            background-color: #6c757d;
            color: white;
            border: none;
            border-radius: 4px;
            font-size: 13px;
            cursor: pointer;
            transition: background-color 0.2s;
        }
        .date-clear-btn:hover {
            background-color: #5a6268;
        }
        .date-reset-week-btn {
            padding: 8px 16px;
            background-color: #2E7D32;
            color: white;
            border: none;
            border-radius: 4px;
            font-size: 13px;
            cursor: pointer;
            transition: background-color 0.2s;
        }
        .date-reset-week-btn:hover {
            background-color: #1B5E20;
        }
    </style>
</head>
<body>
    <div id="appPage">
        <!-- Sidebar -->
        <div class="sidebar no-print" id="sidebar">
            <div class="sidebar-header">
                <h3>
                    <button class="desktop-toggle-btn" id="desktopToggleBtn">
                        <i class="bi bi-list" id="toggleIcon"></i>
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
                        <a class="nav-link active" href="sales_order.php">
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
                        <a class="nav-link" href="supplier.php" data-title="Suppliers">
                            <i class="bi bi-bar-chart-line"></i>
                            <span class="nav-text">Suppliers</span>
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
                    <li class="nav-item">
                        <a class="nav-link" href="trip_tickets.php">
                            <i class="bi bi-ticket-perforated"></i>
                            <span class="nav-text">Trip Tickets</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="approve_credit_requests.php">
                            <i class="bi bi-pencil-square"></i>
                            <span class="nav-text">Approve Requests</span>
                        </a>
                    </li>
                    <hr class="sidebar-divider">
                </ul>
            </div>
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
            <div id="dashboardContent" class="page-content active">
                <div class="navbar-top no-print">
                    <button class="mobile-menu-btn" id="mobileMenuBtn">
                        <i class="bi bi-list"></i>
                    </button>
                    
                    <div class="page-title">
                        <h2>Sales Orders</h2>
                        <p id="dashboardSubtitle">
                            Manage and track all sales orders
                        </p>
                    </div>
                </div>

                <!-- Database Fix Alert -->
                <?php if (!$invoice_so_column_exists): ?>
                <div class="db-fix-card no-print">
                    <div class="d-flex align-items-center mb-3">
                        <i class="bi bi-database fs-1 me-3 text-warning"></i>
                        <div>
                            <h4 class="mb-1 text-warning">Database Relationship Missing</h4>
                            <p class="mb-0 text-muted">The invoices table doesn't have a column linking to sales_orders.</p>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-8">
                            <p class="fw-bold mb-2">Run this SQL in phpMyAdmin to fix:</p>
                            <pre class="mb-3"><code>ALTER TABLE invoices ADD COLUMN so_id INT NULL;
ALTER TABLE invoices ADD FOREIGN KEY (so_id) REFERENCES sales_orders(so_id);</code></pre>
                        </div>
                        <div class="col-md-4 d-flex align-items-center">
                            <button class="btn btn-warning w-100" onclick="copyFixSQL()">
                                <i class="bi bi-files me-2"></i>Copy SQL
                            </button>
                        </div>
                    </div>
                    
                    <div class="alert alert-info mt-3 mb-0">
                        <i class="bi bi-info-circle me-2"></i>
                        <strong>Workaround Mode:</strong> Invoice features are currently disabled. The system will work normally for sales orders, pick lists, and trip tickets.
                    </div>
                </div>
                <?php endif; ?>

                <!-- Branch Info Alerts -->
                <?php if (!$so_branch_column_exists): ?>
                    <div class="alert alert-info alert-dismissible fade show no-print" role="alert">
                        <i class="bi bi-info-circle"></i> 
                        <strong>Branch filtering for sales orders not yet set up.</strong> Run this SQL:
                        <br><br>
                        <code>ALTER TABLE sales_orders ADD COLUMN branch_id INT NULL;</code>
                        <br>
                        <code>ALTER TABLE sales_orders ADD FOREIGN KEY (branch_id) REFERENCES branches(branch_id);</code>
                        <br><br>
                        <button type="button" class="btn btn-sm btn-primary" onclick="copySQL('sales_orders')">
                            <i class="bi bi-files"></i> Copy SQL
                        </button>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if (!$customers_branch_column_exists): ?>
                    <div class="alert alert-info alert-dismissible fade show no-print" role="alert">
                        <i class="bi bi-info-circle"></i> 
                        <strong>Branch filtering for customers not yet set up.</strong> Run this SQL:
                        <br><br>
                        <code>ALTER TABLE customers ADD COLUMN branch_id INT NULL;</code>
                        <br>
                        <code>ALTER TABLE customers ADD FOREIGN KEY (branch_id) REFERENCES branches(branch_id);</code>
                        <br><br>
                        <button type="button" class="btn btn-sm btn-primary" onclick="copySQL('customers')">
                            <i class="bi bi-files"></i> Copy SQL
                        </button>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if (!$drivers_branch_column_exists): ?>
                    <div class="alert alert-info alert-dismissible fade show no-print" role="alert">
                        <i class="bi bi-info-circle"></i> 
                        <strong>Branch filtering for drivers not yet set up.</strong> Run this SQL:
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

                <!-- No Orders Warning -->
                <?php if (empty($sales_orders) && $so_branch_column_exists && !$view_all_branches): ?>
                    <div class="alert alert-warning no-print">
                        <i class="bi bi-exclamation-triangle"></i> 
                        No sales orders found for your branch.
                    </div>
                <?php endif; ?>
                
                <!-- Quick Stats -->
                <div class="row g-3 mb-4 no-print">
                    <div class="col-md-3">
                        <div class="stat-card total">
                            <i class="bi bi-cart-check stat-icon"></i>
                            <div class="stat-value"><?= $statTotalOrders ?></div>
                            <div class="stat-label">Total Orders</div>
                            <small class="d-block mt-2">
                                <?php if ($so_branch_column_exists && !$view_all_branches): ?>
                                    Your branch
                                <?php else: ?>
                                    All time sales orders
                                <?php endif; ?>
                            </small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card pending">
                            <i class="bi bi-clock-history stat-icon"></i>
                            <div class="stat-value"><?= $statPendingOrders ?></div>
                            <div class="stat-label">Pending</div>
                            <small class="d-block mt-2">Awaiting confirmation</small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card" style="background: linear-gradient(135deg, #9C27B0, #7B1FA2);" title="Sales for selected date range">
                            <i class="bi bi-calendar-week stat-icon"></i>
                            <div class="stat-value" id="dateRangeSalesValue"><?= $statCurrentWeekSales ?></div>
                            <div class="stat-label" id="dateRangeSalesLabel">Period Sales</div>
                            <small class="d-block mt-2" id="dateRangeSalesSubtitle">
                                <i class="bi bi-calendar-range"></i> <span id="dateRangeText">Current week (Mon-Sat)</span>
                                <?php if (!$view_all_branches && $branch_id > 0): ?>
                                    <br><span class="badge bg-light text-dark mt-1">Your branch</span>
                                <?php endif; ?>
                            </small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card complete">
                            <i class="bi bi-check-circle stat-icon"></i>
                            <div class="stat-value"><?= $statCompletedOrders ?></div>
                            <div class="stat-label">Completed</div>
                            <small class="d-block mt-2">Successfully delivered</small>
                        </div>
                    </div>
                </div>

                <!-- Date Range Summary Box (shows when date range filter is active) -->
                <div class="date-range-summary no-print" id="dateRangeSummary" style="display: none;">
                    <i class="bi bi-calendar-range"></i>
                    <span id="dateRangeSummaryText"></span>
                </div>

                <!-- Search and Filter with Manual Date Range -->
                <div class="row g-3 mb-4 no-print">
                    <div class="col-12">
                        <div class="form-card">
                            <div class="row g-3 align-items-end">
                                <div class="col-md-12">
                                    <div class="date-filter-container">
                                        <div class="date-input-group">
                                            <label for="startDate">From:</label>
                                            <input type="date" class="form-control" id="startDate" value="<?= $default_start_date ?>">
                                        </div>
                                        <div class="date-input-group">
                                            <label for="endDate">To:</label>
                                            <input type="date" class="form-control" id="endDate" value="<?= $default_end_date ?>">
                                        </div>
                                        <button class="date-filter-btn" onclick="applyManualDateRangeFilter()">
                                            <i class="bi bi-funnel"></i> Apply Filter
                                        </button>
                                        <button class="date-reset-week-btn" onclick="resetToCurrentWeek()">
                                            <i class="bi bi-calendar-week"></i> Current Week
                                        </button>
                                        <button class="date-clear-btn" onclick="clearDateRangeFilter()">
                                            <i class="bi bi-x-circle"></i> Clear All Filters
                                        </button>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="search-box">
                                        <i class="bi bi-search"></i>
                                        <input type="text" class="form-control" id="searchInput" placeholder="Search by order number or customer..." onkeyup="applyManualFilters()">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <select class="form-select" id="statusFilter" onchange="applyManualFilters()">
                                        <option value="">All Status</option>
                                        <option value="pending">Pending</option>
                                        <option value="confirmed">Confirmed</option>
                                        <option value="processing">Processing</option>
                                        <option value="ready">For Delivery</option>
                                        <option value="delivered">Delivered</option>
                                        <option value="cancelled">Cancelled</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <select class="form-select" id="customerFilter" onchange="applyManualFilters()">
                                        <option value="">All Customers</option>
                                        <?php foreach ($customers as $customer): ?>
                                            <option value="<?= htmlspecialchars($customer['customer_name']) ?>">
                                                <?= htmlspecialchars($customer['customer_name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="mb-3 d-flex gap-2 no-print">
                    <button class="btn btn-primary" onclick="printAllOrders()">
                        <i class="bi bi-printer"></i> Print All Orders
                    </button>
                    <button class="btn btn-success" onclick="exportToExcel()">
                        <i class="bi bi-file-earmark-excel"></i> Export to Excel
                    </button>
                    <button class="btn btn-info" onclick="refreshOrders()">
                        <i class="bi bi-arrow-clockwise"></i> Refresh
                    </button>
                </div>

                <!-- Sales Orders Table -->
                <div class="data-table">
                    <div class="table-header d-flex justify-content-between align-items-center no-print">
                        <h5 class="mb-0">Sales Orders</h5>
                        <div class="d-flex gap-2 align-items-center">
                            <?php if ($so_branch_column_exists && $view_all_branches): ?>
                                <span class="badge bg-success">All Branches</span>
                            <?php endif; ?>
                            <span class="text-muted me-2">Total: ₱<?= number_format(array_sum(array_column($sales_orders, 'total_amount')), 2) ?></span>
                        </div>
                    </div>
                    <div class="table-container">
                        <table class="table custom-table compact-table" id="salesOrdersTable">
                            <thead>
                                <tr>
                                    <th>Order No.</th>
                                    <th>Date</th>
                                    <th>Customer</th>
                                    <?php if ($so_branch_column_exists && $view_all_branches): ?>
                                        <th>Branch</th>
                                    <?php endif; ?>
                                    <th>Items</th>
                                    <th>Qty</th>
                                    <th>Total Amount</th>
                                    <th>Assigned Driver</th>
                                    <th>Order Status</th>
                                    <th>Invoice</th>
                                    <th>Payment Status</th>
                                    <th class="no-print">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="salesOrdersTableBody">
                                <?php if (empty($sales_orders)): ?>
                                <tr>
                                    <td colspan="<?= ($so_branch_column_exists && $view_all_branches) ? '12' : '11' ?>" class="text-center py-4">
                                        <i class="bi bi-inbox fs-1 d-block text-muted mb-2"></i>
                                        <p class="text-muted mb-0">No sales orders found</p>
                                    </td>
                                </tr>
                                <?php else: ?>
                                    <?php foreach ($sales_orders as $order): 
                                        $payment = getPaymentStatus($order['order_status'], $order['invoice_status'] ?? null);
                                    ?>
                                    <tr class="sales-order-row" 
                                        data-id="<?= $order['so_id'] ?>"
                                        data-order-number="<?= htmlspecialchars($order['so_number']) ?>"
                                        data-customer="<?= htmlspecialchars($order['customer_name']) ?>"
                                        data-status="<?= $order['order_status'] ?>"
                                        data-date="<?= $order['created_at'] ?>"
                                        data-amount="<?= $order['total_amount'] ?>"
                                        data-items="<?= $order['total_items'] ?? 0 ?>"
                                        data-qty="<?= $order['total_quantity'] ?? 0 ?>"
                                        data-invoice="<?= htmlspecialchars($order['invoice_number'] ?? '') ?>"
                                        data-invoice-status="<?= $order['invoice_status'] ?? '' ?>"
                                        data-driver="<?= htmlspecialchars($order['assigned_driver'] ?? '') ?>">
                                        <td><strong><?= htmlspecialchars($order['so_number']) ?></strong></td>
                                        <td><?= formatDate($order['created_at']) ?></td>
                                        <td><?= htmlspecialchars($order['customer_name']) ?></td>
                                        <?php if ($so_branch_column_exists && $view_all_branches): ?>
                                            <td>
                                                <span class="badge bg-info">
                                                    <?= htmlspecialchars($order['branch_name'] ?? 'Branch ' . $order['branch_id']) ?>
                                                </span>
                                            </td>
                                        <?php endif; ?>
                                        <td class="text-center"><?= $order['total_items'] ?? 0 ?></td>
                                        <td class="text-center"><?= $order['total_quantity'] ?? 0 ?></td>
                                        <td class="text-end">₱<?= number_format($order['total_amount'] ?? 0, 2) ?></td>
                                        <td>
                                            <?php if (!empty($order['assigned_driver'])): ?>
                                                <span class="driver-badge">
                                                    <i class="bi bi-truck"></i> <?= htmlspecialchars($order['assigned_driver']) ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">No Driver</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="<?= getOrderStatusBadge($order['order_status']) ?>">
                                                <?= getOrderStatusText($order['order_status']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($order['invoice_number']): ?>
                                                <span class="badge bg-success"><?= htmlspecialchars($order['invoice_number']) ?></span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">No Invoice</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge <?= $payment['class'] ?>"><?= $payment['status'] ?></span>
                                        </td>
                                        <td class="no-print">
                                            <div class="action-buttons">
                                                <button class="btn-action btn-view" onclick="viewOrder(<?= $order['so_id'] ?>)" title="View">
                                                    <i class="bi bi-eye"></i>
                                                </button>
                                                <button class="btn-action btn-print" onclick="printSingleOrder(<?= $order['so_id'] ?>)" title="Print Order">
                                                    <i class="bi bi-printer"></i>
                                                </button>
                                                <?php if ($order['order_status'] == 'pending'): ?>
                                                    <button class="btn-action btn-edit" onclick="editOrder(<?= $order['so_id'] ?>)" title="Edit">
                                                        <i class="bi bi-pencil"></i>
                                                    </button>
                                                    <button class="btn-action btn-delete" onclick="deleteOrder(<?= $order['so_id'] ?>)" title="Delete">
                                                        <i class="bi bi-trash"></i>
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

    <!-- VIEW ORDER MODAL -->
    <div class="modal fade no-print" id="viewOrderModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title"><i class="bi bi-eye me-2"></i>Sales Order Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="viewOrderContent"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary" onclick="printOrder(currentOrderId)" id="printOrderBtn">Print Order</button>
                    <button type="button" class="btn btn-warning" onclick="editFromView()" id="editFromViewBtn">Edit Order</button>
                </div>
            </div>
        </div>
    </div>

    <!-- EDIT ORDER MODAL -->
    <div class="modal fade no-print" id="editOrderModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Edit Sales Order</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="editOrderForm">
                        <input type="hidden" id="editOrderId">
                        <?php if ($so_branch_column_exists && !$view_all_branches): ?>
                            <input type="hidden" name="branch_id" value="<?= $branch_id ?>">
                        <?php endif; ?>
                        
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="editOrderNumber" class="form-label">Order Number</label>
                                <input type="text" class="form-control" id="editOrderNumber" readonly>
                            </div>
                            <div class="col-md-6">
                                <label for="editOrderDate" class="form-label">Order Date *</label>
                                <input type="date" class="form-control" id="editOrderDate" required>
                            </div>
                            <div class="col-md-6">
                                <label for="editCustomerName" class="form-label">Customer</label>
                                <input type="text" class="form-control" id="editCustomerName" readonly>
                            </div>
                            <div class="col-md-6">
                                <label for="editOrderStatus" class="form-label">Order Status *</label>
                                <select class="form-select" id="editOrderStatus" onchange="onOrderStatusChange()" required>
                                    <option value="pending">Pending</option>
                                    <option value="confirmed">Confirm Order (Generate Documents & Deduct Stock)</option>
                                </select>
                            </div>
                            
                            <!-- Driver Selection - Only shown when confirming -->
                            <div class="col-md-12" id="driverSelectionContainer" style="display: none;">
                                <label for="editDriverSelect" class="form-label fw-bold">Select Driver *</label>
                                <select class="form-select select2-driver" id="editDriverSelect" style="width: 100%;">
                                    <option value="">-- Choose Driver --</option>
                                    
                                    <!-- Drivers with existing deliveries -->
                                    <?php if (!empty($drivers_with_pending)): ?>
                                    <optgroup label="Drivers with existing deliveries (can be assigned)">
                                        <?php foreach ($drivers_with_pending as $driver): ?>
                                            <option value="<?= $driver['driver_id'] ?>" 
                                                    data-vehicle="<?= htmlspecialchars($driver['vehicle_type'] ?? 'N/A') ?>"
                                                    data-plate="<?= htmlspecialchars($driver['vehicle_plate_number'] ?? 'N/A') ?>"
                                                    data-pending="<?= $driver['pending_deliveries'] ?>">
                                                <?= htmlspecialchars($driver['driver_name']) ?> 
                                                <?php if (!empty($driver['vehicle_plate_number'])): ?>
                                                    - <?= htmlspecialchars($driver['vehicle_plate_number']) ?>
                                                <?php endif; ?>
                                                <span class="badge bg-warning text-dark ms-2"><?= $driver['pending_deliveries'] ?> pending</span>
                                            </option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                    <?php endif; ?>
                                    
                                    <!-- Available drivers -->
                                    <?php if (!empty($available_drivers_without_pending)): ?>
                                    <optgroup label="Available Drivers">
                                        <?php foreach ($available_drivers_without_pending as $driver): ?>
                                            <option value="<?= $driver['driver_id'] ?>" 
                                                    data-vehicle="<?= htmlspecialchars($driver['vehicle_type'] ?? 'N/A') ?>"
                                                    data-plate="<?= htmlspecialchars($driver['vehicle_plate_number'] ?? 'N/A') ?>">
                                                <?= htmlspecialchars($driver['driver_name']) ?> 
                                                <?php if (!empty($driver['vehicle_plate_number'])): ?>
                                                    - <?= htmlspecialchars($driver['vehicle_plate_number']) ?>
                                                <?php endif; ?>
                                                <span class="available-badge">Available</span>
                                            </option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                    <?php endif; ?>
                                </select>
                                <div class="driver-info-tooltip">
                                    <i class="bi bi-info-circle"></i> 
                                    Drivers with existing deliveries can still be assigned. They will be delivered together in one trip.
                                </div>
                            </div>
                            
                            <div class="col-md-4">
                                <label for="editTotalItems" class="form-label">Items</label>
                                <input type="number" class="form-control" id="editTotalItems" readonly>
                            </div>
                            <div class="col-md-4">
                                <label for="editTotalQty" class="form-label">Total Quantity</label>
                                <input type="number" class="form-control" id="editTotalQty" readonly>
                            </div>
                            <div class="col-md-4">
                                <label for="editTotalAmount" class="form-label">Total Amount (₱) *</label>
                                <input type="number" class="form-control" id="editTotalAmount" step="0.01" min="0" required>
                            </div>
                        </div>
                        
                        <!-- STOCK CHECK MESSAGE -->
                        <div class="alert alert-info mt-3" id="stockCheckMessage" style="display: none;">
                            <i class="bi bi-info-circle me-2"></i>
                            <span id="stockCheckText"></span>
                        </div>
                        
                        <!-- NO DRIVERS MESSAGE -->
                        <div class="alert alert-warning mt-3" id="noDriversMessage" style="display: none;">
                            <i class="bi bi-exclamation-triangle me-2"></i>
                            <strong>No available drivers found for your branch.</strong> 
                            Please add drivers or mark existing drivers as active.
                        </div>
                        
                        <!-- PAYMENT NOTICE -->
                        <div class="alert alert-success mt-3" id="paymentNotice" style="display: none;">
                            <i class="bi bi-info-circle me-2"></i>
                            <span id="paymentNoticeText"></span>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="updateOrder()" id="updateOrderBtn">Update Order</button>
                </div>
            </div>
        </div>
    </div>

    <!-- DELETE CONFIRMATION MODAL -->
    <div class="modal fade no-print" id="deleteOrderModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="bi bi-trash me-2"></i>Confirm Delete</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete this sales order?</p>
                    <p class="fw-bold" id="deleteOrderNumber"></p>
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        This action cannot be undone and will remove all associated order items.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" onclick="confirmDelete()">Delete Order</button>
                </div>
            </div>
        </div>
    </div>

    <!-- STOCK WARNING MODAL -->
    <div class="modal fade no-print" id="stockWarningModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title"><i class="bi bi-exclamation-triangle me-2"></i>Insufficient Stock</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>The following items have insufficient stock:</p>
                    <div id="insufficientStockList"></div>
                    <p class="mt-3">Please update inventory or adjust quantities before confirming this order.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Print Frame (hidden) -->
    <iframe id="printFrame" name="printFrame"></iframe>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
    // ========== GLOBAL VARIABLES ==========
    let currentOrderId = null;
    let currentBranchId = <?php echo $branch_id; ?>;
    const viewAllBranches = <?php echo $view_all_branches ? 'true' : 'false'; ?>;
    const soBranchColumnExists = <?php echo $so_branch_column_exists ? 'true' : 'false'; ?>;
    const invoiceSoColumnExists = <?php echo $invoice_so_column_exists ? 'true' : 'false'; ?>;
    const driversBranchColumnExists = <?php echo $drivers_branch_column_exists ? 'true' : 'false'; ?>;
    const logoBase64 = '<?php echo $logo_base64; ?>';
    let availableDrivers = <?= json_encode($available_drivers) ?>;
    let activeDateRange = { start: '', end: '' }; // Initially no active date filter
    const defaultStartDate = '<?= $default_start_date ?>';
    const defaultEndDate = '<?= $default_end_date ?>';

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
                overlay.addEventListener('click', closeMobileSidebar);
                setTimeout(() => overlay.classList.add('active'), 10);
            }
        } else {
            sidebar.classList.toggle('collapsed');
            localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
            document.querySelectorAll('.nav-text').forEach(text => {
                text.style.display = sidebar.classList.contains('collapsed') ? 'none' : 'inline-block';
            });
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
            } else {
                sidebar.classList.remove('collapsed');
                document.querySelectorAll('.nav-text').forEach(text => {
                    text.style.display = 'inline-block';
                });
            }
        } else {
            sidebar.classList.remove('collapsed');
            document.querySelectorAll('.nav-text').forEach(text => {
                text.style.display = 'inline-block';
            });
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

    // ========== DOM READY ==========
    document.addEventListener('DOMContentLoaded', function() {
        initializeSidebar();
        
        // Initialize Select2 with custom styling
        $('.select2-driver').select2({
            placeholder: 'Select a driver',
            allowClear: true,
            dropdownParent: $('#editOrderModal'),
            width: '100%',
            templateResult: formatDriverOption,
            templateSelection: formatDriverSelection,
            escapeMarkup: function(m) { return m; }
        });
        
        // Mobile menu toggle
        document.getElementById('mobileMenuBtn').addEventListener('click', function() {
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
                }
            } else {
                toggleSidebar();
            }
        });
        
        const desktopToggleBtn = document.getElementById('desktopToggleBtn');
        if (desktopToggleBtn) {
            desktopToggleBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                toggleSidebar();
            });
        }
        
        document.querySelectorAll('.sidebar .nav-link').forEach(link => {
            link.addEventListener('click', function() {
                if (window.innerWidth <= 992) closeMobileSidebar();
            });
        });

        document.addEventListener('click', function(event) {
            const sidebar = document.getElementById('sidebar');
            const mobileBtn = document.getElementById('mobileMenuBtn');
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
    });

    // Format driver options in Select2 with enhanced styling
    function formatDriverOption(driver) {
        if (!driver.id) return driver.text;
        
        const element = $(driver.element);
        const driverName = driver.text.split('<span')[0] || driver.text;
        const vehicle = element.data('vehicle') || 'No vehicle';
        const plate = element.data('plate') || 'No plate';
        const pending = element.data('pending') || 0;
        
        let badge = '';
        if (pending > 0) {
            badge = `<span class="pending-count ms-2">${pending} pending</span>`;
        } else {
            badge = `<span class="available-badge ms-2">Available</span>`;
        }
        
        return $(`
            <div class="driver-option">
                <span class="driver-name">${driverName}</span>
                <span class="driver-vehicle">${vehicle} - ${plate}</span>
                ${badge}
            </div>
        `);
    }

    function formatDriverSelection(driver) {
        if (!driver.id) return driver.text;
        
        const element = $(driver.element);
        const driverName = driver.text.split('<span')[0] || driver.text;
        const vehicle = element.data('vehicle') || '';
        const plate = element.data('plate') || '';
        
        return driverName + (vehicle ? ` (${vehicle} - ${plate})` : '');
    }

    // When order status changes
    function onOrderStatusChange() {
        const status = document.getElementById('editOrderStatus').value;
        const driverContainer = document.getElementById('driverSelectionContainer');
        const noDriversMsg = document.getElementById('noDriversMessage');
        const paymentNotice = document.getElementById('paymentNotice');
        const paymentNoticeText = document.getElementById('paymentNoticeText');
        
        if (status === 'confirmed') {
            if (availableDrivers.length > 0) {
                driverContainer.style.display = 'block';
                noDriversMsg.style.display = 'none';
                paymentNotice.style.display = 'none';
                $('#editDriverSelect').trigger('change');
            } else {
                driverContainer.style.display = 'none';
                noDriversMsg.style.display = 'block';
                paymentNotice.style.display = 'none';
            }
        } else if (status === 'delivered') {
            driverContainer.style.display = 'none';
            noDriversMsg.style.display = 'none';
            paymentNotice.style.display = 'block';
            paymentNoticeText.innerHTML = 'Marking this order as delivered will automatically update payment status to <strong>Paid</strong>.';
        } else if (status === 'cancelled') {
            driverContainer.style.display = 'none';
            noDriversMsg.style.display = 'none';
            paymentNotice.style.display = 'block';
            paymentNoticeText.innerHTML = 'Cancelling this order will update payment status to <strong>Cancelled</strong>.';
        } else {
            driverContainer.style.display = 'none';
            noDriversMsg.style.display = 'none';
            paymentNotice.style.display = 'none';
        }
    }

    // Refresh available drivers
    function refreshAvailableDrivers() {
        showLoading();
        
        const formData = new FormData();
        formData.append('action', 'get_available_drivers');
        formData.append('branch_id', currentBranchId);
        
        fetch('sales_order.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            Swal.close();
            
            if (data.success) {
                availableDrivers = data.drivers;
                
                const select = $('#editDriverSelect');
                select.empty();
                select.append('<option value="">-- Choose Driver --</option>');
                
                // Group drivers by pending status
                const withPending = data.drivers.filter(d => d.pending_deliveries > 0);
                const withoutPending = data.drivers.filter(d => d.pending_deliveries == 0);
                
                if (withPending.length > 0) {
                    const group = $('<optgroup label="Drivers with existing deliveries (can be assigned)">');
                    withPending.forEach(driver => {
                        const option = new Option(
                            driver.driver_name + ' - ' + (driver.vehicle_plate_number || 'No vehicle'),
                            driver.driver_id,
                            false,
                            false
                        );
                        $(option).data('vehicle', driver.vehicle_type || 'N/A');
                        $(option).data('plate', driver.vehicle_plate_number || 'N/A');
                        $(option).data('pending', driver.pending_deliveries);
                        group.append(option);
                    });
                    select.append(group);
                }
                
                if (withoutPending.length > 0) {
                    const group = $('<optgroup label="Available Drivers">');
                    withoutPending.forEach(driver => {
                        const option = new Option(
                            driver.driver_name + ' - ' + (driver.vehicle_plate_number || 'No vehicle'),
                            driver.driver_id,
                            false,
                            false
                        );
                        $(option).data('vehicle', driver.vehicle_type || 'N/A');
                        $(option).data('plate', driver.vehicle_plate_number || 'N/A');
                        group.append(option);
                    });
                    select.append(group);
                }
                
                select.trigger('change');
                onOrderStatusChange();
            }
        })
        .catch(error => {
            Swal.close();
            console.error('Error refreshing drivers:', error);
        });
    }

    // ========== CHECK STOCK FUNCTION ==========
    function checkStockBeforeConfirm(soId) {
        showLoading();
        
        const formData = new FormData();
        formData.append('action', 'check_stock');
        formData.append('so_id', soId);
        
        return fetch('sales_order.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            Swal.close();
            
            if (data.success) {
                if (data.sufficient) {
                    document.getElementById('stockCheckMessage').style.display = 'block';
                    document.getElementById('stockCheckText').innerHTML = '<i class="bi bi-check-circle-fill text-success"></i> Stock is sufficient for all items.';
                    return true;
                } else {
                    let html = '<ul class="list-group">';
                    data.insufficient_items.forEach(item => {
                        html += `<li class="list-group-item list-group-item-warning">
                            <strong>${item.item_code}</strong> - ${item.item_name}<br>
                            Required: ${item.required}, Available: ${item.available}
                        </li>`;
                    });
                    html += '</ul>';
                    
                    document.getElementById('insufficientStockList').innerHTML = html;
                    new bootstrap.Modal(document.getElementById('stockWarningModal')).show();
                    return false;
                }
            } else {
                Swal.fire('Error', data.message, 'error');
                return false;
            }
        });
    }

    // ========== MANUAL FILTER FUNCTIONS ==========
    function applyManualDateRangeFilter() {
        const startDate = document.getElementById('startDate').value;
        const endDate = document.getElementById('endDate').value;
        
        if (!startDate || !endDate) {
            Swal.fire('Warning', 'Please select both start and end dates', 'warning');
            return;
        }
        
        if (new Date(startDate) > new Date(endDate)) {
            Swal.fire('Warning', 'Start date cannot be after end date', 'warning');
            return;
        }
        
        activeDateRange = { start: startDate, end: endDate };
        applyManualFilters();
    }
    
    function resetToCurrentWeek() {
        // Reset to current week (Monday to Saturday)
        const today = new Date();
        const day = today.getDay(); // 0 = Sunday, 1 = Monday, etc.
        
        // Calculate Monday (start of week)
        const monday = new Date(today);
        const diff = monday.getDate() - day + (day === 0 ? -6 : 1);
        monday.setDate(diff);
        
        // Calculate Saturday (end of week - today if it's before Saturday, otherwise Saturday)
        const saturday = new Date(monday);
        saturday.setDate(monday.getDate() + 5); // Monday + 5 days = Saturday
        
        // If today is before Saturday, use today as end date
        const endDate = today < saturday ? today : saturday;
        
        const formatDate = (date) => {
            const year = date.getFullYear();
            const month = String(date.getMonth() + 1).padStart(2, '0');
            const day = String(date.getDate()).padStart(2, '0');
            return `${year}-${month}-${day}`;
        };
        
        document.getElementById('startDate').value = formatDate(monday);
        document.getElementById('endDate').value = formatDate(endDate);
        
        activeDateRange = { 
            start: formatDate(monday), 
            end: formatDate(endDate) 
        };
        
        applyManualFilters();
    }
    
    function clearDateRangeFilter() {
        // Clear all filters (show all orders)
        document.getElementById('startDate').value = '';
        document.getElementById('endDate').value = '';
        document.getElementById('searchInput').value = '';
        document.getElementById('statusFilter').value = '';
        document.getElementById('customerFilter').value = '';
        
        activeDateRange = { start: '', end: '' };
        
        applyManualFilters();
        
        // Reset stat card to default period sales (current week)
        updatePeriodSalesStat('', '', 0, true);
    }

    // Apply all manual filters (search, status, customer, date range)
    function applyManualFilters() {
        const searchTerm = document.getElementById('searchInput').value.toLowerCase();
        const statusFilter = document.getElementById('statusFilter').value;
        const customerFilter = document.getElementById('customerFilter').value;
        const startDate = activeDateRange.start;
        const endDate = activeDateRange.end;
        
        // Create date objects for comparison
        const startDateTime = startDate ? new Date(startDate) : null;
        const endDateTime = endDate ? new Date(endDate) : null;
        if (endDateTime) {
            endDateTime.setHours(23, 59, 59, 999); // Include entire end day
        }
        
        let visibleCount = 0;
        let totalAmount = 0;
        let dateRangeTotal = 0;
        
        document.querySelectorAll('.sales-order-row').forEach(row => {
            const orderNumber = row.dataset.orderNumber?.toLowerCase() || '';
            const customer = row.dataset.customer?.toLowerCase() || '';
            const status = row.dataset.status || '';
            const driver = row.dataset.driver?.toLowerCase() || '';
            const orderDateStr = row.dataset.date || '';
            const amount = parseFloat(row.dataset.amount) || 0;
            
            // Parse order date
            let orderDate = null;
            let isInDateRange = true;
            
            if (orderDateStr) {
                orderDate = new Date(orderDateStr);
                
                // Apply date range filter only if active
                if (startDateTime && endDateTime) {
                    isInDateRange = orderDate >= startDateTime && orderDate <= endDateTime;
                }
            }
            
            const matchesSearch = searchTerm === '' || orderNumber.includes(searchTerm) || customer.includes(searchTerm) || driver.includes(searchTerm);
            const matchesStatus = statusFilter === '' || status === statusFilter;
            const matchesCustomer = customerFilter === '' || row.dataset.customer === customerFilter;
            
            const showRow = matchesSearch && matchesStatus && matchesCustomer && isInDateRange;
            row.style.display = showRow ? '' : 'none';
            
            if (showRow) {
                visibleCount++;
                totalAmount += amount;
                if (isInDateRange) {
                    dateRangeTotal += amount;
                }
            }
        });
        
        // Update date range summary
        updateDateRangeSummary(startDate, endDate, visibleCount, totalAmount);
        
        // Update Period Sales stat card
        updatePeriodSalesStat(startDate, endDate, dateRangeTotal);
    }

    // Update Period Sales stat card
    function updatePeriodSalesStat(startDate, endDate, totalAmount, isReset = false) {
        const salesValue = document.getElementById('dateRangeSalesValue');
        const salesLabel = document.getElementById('dateRangeSalesLabel');
        const dateRangeText = document.getElementById('dateRangeText');
        
        if (isReset || !startDate || !endDate) {
            // Reset to default period sales (current week)
            salesValue.textContent = '<?= $statCurrentWeekSales ?>';
            salesLabel.textContent = 'Period Sales';
            dateRangeText.textContent = 'Current week (Mon-Sat)';
            return;
        }
        
        // Format the amount in thousands with 1 decimal
        const amountInK = (totalAmount / 1000).toFixed(1);
        salesValue.textContent = '₱' + amountInK + 'K';
        
        // Format dates for display
        const start = new Date(startDate);
        const end = new Date(endDate);
        const options = { month: 'short', day: 'numeric' };
        const startStr = start.toLocaleDateString('en-US', options);
        const endStr = end.toLocaleDateString('en-US', options);
        
        if (startDate === endDate) {
            dateRangeText.textContent = startStr;
            salesLabel.textContent = 'Daily Sales';
        } else {
            dateRangeText.textContent = `${startStr} - ${endStr}`;
            salesLabel.textContent = 'Period Sales';
        }
    }

    // Update date range summary box
    function updateDateRangeSummary(startDate, endDate, visibleCount, totalAmount) {
        const summaryDiv = document.getElementById('dateRangeSummary');
        const summaryText = document.getElementById('dateRangeSummaryText');
        
        if (!startDate || !endDate) {
            summaryDiv.style.display = 'none';
            return;
        }
        
        const start = new Date(startDate);
        const end = new Date(endDate);
        const options = { year: 'numeric', month: 'long', day: 'numeric' };
        const startStr = start.toLocaleDateString('en-US', options);
        const endStr = end.toLocaleDateString('en-US', options);
        
        summaryText.innerHTML = `<strong>${startStr} - ${endStr}:</strong> ${visibleCount} orders, Total: ₱${totalAmount.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
        summaryDiv.style.display = 'block';
    }

    // ========== VIEW ORDER ==========
    function viewOrder(id) {
        showLoading();
        
        const formData = new FormData();
        formData.append('action', 'get_order');
        formData.append('so_id', id);
        
        fetch('sales_order.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            Swal.close();
            
            if (data.success) {
                const order = data.order;
                const items = data.items;
                const documents = data.documents || {};
                const invoice = data.invoice || null;
                
                const orderDate = new Date(order.created_at);
                const formattedDate = orderDate.toLocaleDateString('en-US', {
                    year: 'numeric',
                    month: 'short',
                    day: 'numeric'
                });
                
                const statusBadge = getStatusBadge(order.order_status);
                const statusText = getStatusText(order.order_status);
                
                let itemsHtml = '';
                if (items && items.length > 0) {
                    itemsHtml = '<h6 class="mt-4 mb-3 fw-bold">Order Items</h6><div class="table-responsive"><table class="table table-sm table-bordered"><thead class="table-light"><tr><th>Item Code</th><th>Item Name</th><th>Unit</th><th>Quantity</th><th>Unit Price</th><th>Subtotal</th></tr></thead><tbody>';
                    items.forEach(item => {
                        const subtotal = item.quantity_ordered * item.unit_price;
                        itemsHtml += `<tr>
                            <td>${item.item_code}</td>
                            <td>${item.item_name}</td>
                            <td class="text-center">${item.unit_type || ''}</td>
                            <td class="text-center">${item.quantity_ordered}</td>
                            <td class="text-end">₱${Number(item.unit_price).toFixed(2)}</td>
                            <td class="text-end">₱${Number(subtotal).toFixed(2)}</td>
                        </tr>`;
                    });
                    itemsHtml += '</tbody></table></div>';
                }
                
                let documentsHtml = '<div class="mt-4"><h6 class="fw-bold">Generated Documents</h6><div class="row g-2">';
                
                if (documents.pick_list_number) {
                    documentsHtml += `<div class="col-md-4"><div class="card bg-light"><div class="card-body p-2"><small class="text-muted">Pick List</small><br><strong>${documents.pick_list_number}</strong></div></div></div>`;
                }
                
                if (documents.driver_name) {
                    documentsHtml += `<div class="col-md-4"><div class="card bg-light"><div class="card-body p-2"><small class="text-muted">Assigned Driver</small><br><strong><i class="bi bi-truck"></i> ${documents.driver_name}</strong></div></div></div>`;
                }
                
                if (invoice) {
                    const invoiceStatusClass = invoice.invoice_status === 'paid' ? 'success' : 
                                               (invoice.invoice_status === 'cancelled' ? 'danger' : 'warning');
                    documentsHtml += `<div class="col-md-4"><div class="card bg-light"><div class="card-body p-2"><small class="text-muted">Invoice</small><br><strong>${invoice.invoice_number}</strong><br><span class="badge bg-${invoiceStatusClass}">${invoice.invoice_status}</span></div></div></div>`;
                }
                
                if (documents.trip_ticket_number) {
                    documentsHtml += `<div class="col-md-4"><div class="card bg-light"><div class="card-body p-2"><small class="text-muted">Trip Ticket</small><br><strong>${documents.trip_ticket_number}</strong></div></div></div>`;
                }
                
                documentsHtml += '</div></div>';
                
                const content = document.getElementById('viewOrderContent');
                content.innerHTML = `
                    <div class="row">
                        <div class="col-md-6">
                            <div class="card mb-3">
                                <div class="card-header bg-light">
                                    <h6 class="mb-0 fw-bold">Order Information</h6>
                                </div>
                                <div class="card-body">
                                    <table class="table table-sm table-borderless mb-0">
                                        <tr>
                                            <td width="40%">Order Number:</td>
                                            <td><strong>${order.so_number}</strong></td>
                                        </tr>
                                        <tr>
                                            <td>Order Date:</td>
                                            <td>${formattedDate}</td>
                                        </tr>
                                        <tr>
                                            <td>Customer:</td>
                                            <td><strong>${order.customer_name}</strong></td>
                                        </tr>
                                        ${order.address ? `<tr><td>Address:</td><td>${order.address}</td></tr>` : ''}
                                        ${order.contact_number ? `<tr><td>Contact:</td><td>${order.contact_number}</td></tr>` : ''}
                                        ${order.branch_name ? `<tr><td>Branch:</td><td><span class="badge bg-info">${order.branch_name}</span></td></tr>` : ''}
                                        <tr>
                                            <td>Order Status:</td>
                                            <td><span class="${statusBadge}">${statusText}</span></td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card mb-3">
                                <div class="card-header bg-light">
                                    <h6 class="mb-0 fw-bold">Order Summary</h6>
                                </div>
                                <div class="card-body">
                                    <table class="table table-sm table-borderless mb-0">
                                        <tr>
                                            <td width="40%">Total Items:</td>
                                            <td>${order.total_items || 0}</td>
                                        </tr>
                                        <tr>
                                            <td>Total Quantity:</td>
                                            <td>${order.total_quantity || 0}</td>
                                        </tr>
                                        <tr>
                                            <td>Total Amount:</td>
                                            <td class="fw-bold fs-5">₱${Number(order.total_amount).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    ${itemsHtml}
                    ${documentsHtml}
                `;
                
                currentOrderId = id;
                
                const editBtn = document.getElementById('editFromViewBtn');
                const printBtn = document.getElementById('printOrderBtn');
                
                if (order.order_status === 'pending') {
                    editBtn.style.display = 'inline-block';
                    printBtn.style.display = 'none';
                } else {
                    editBtn.style.display = 'none';
                    printBtn.style.display = 'inline-block';
                }
                
                new bootstrap.Modal(document.getElementById('viewOrderModal')).show();
            } else {
                Swal.fire('Error', data.message, 'error');
            }
        })
        .catch(error => {
            Swal.close();
            Swal.fire('Error', 'An error occurred while fetching order details', 'error');
        });
    }

    function editFromView() {
        bootstrap.Modal.getInstance(document.getElementById('viewOrderModal')).hide();
        setTimeout(() => {
            editOrder(currentOrderId);
        }, 300);
    }

    function editOrder(id) {
        showLoading();
        
        const formData = new FormData();
        formData.append('action', 'get_order');
        formData.append('so_id', id);
        
        fetch('sales_order.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            Swal.close();
            
            if (data.success) {
                const order = data.order;
                
                const orderDate = order.created_at.split(' ')[0];
                
                document.getElementById('editOrderId').value = order.so_id;
                document.getElementById('editOrderNumber').value = order.so_number;
                document.getElementById('editOrderDate').value = orderDate;
                document.getElementById('editCustomerName').value = order.customer_name;
                document.getElementById('editOrderStatus').value = order.order_status;
                document.getElementById('editTotalItems').value = order.total_items || 0;
                document.getElementById('editTotalQty').value = order.total_quantity || 0;
                document.getElementById('editTotalAmount').value = order.total_amount;
                
                $('#editDriverSelect').val('').trigger('change');
                
                currentOrderId = id;
                
                document.getElementById('stockCheckMessage').style.display = 'none';
                document.getElementById('driverSelectionContainer').style.display = 'none';
                document.getElementById('noDriversMessage').style.display = 'none';
                document.getElementById('paymentNotice').style.display = 'none';
                
                if (order.order_status !== 'pending') {
                    if (order.order_status === 'confirmed' || order.order_status === 'processing' || order.order_status === 'ready') {
                        document.getElementById('editOrderStatus').innerHTML = `
                            <option value="confirmed" ${order.order_status === 'confirmed' ? 'selected' : ''}>Confirmed</option>
                            <option value="processing" ${order.order_status === 'processing' ? 'selected' : ''}>Processing</option>
                            <option value="ready" ${order.order_status === 'ready' ? 'selected' : ''}>For Delivery</option>
                            <option value="delivered">Mark as Delivered</option>
                            <option value="cancelled">Cancel Order</option>
                        `;
                    } else if (order.order_status === 'delivered') {
                        document.getElementById('editOrderStatus').innerHTML = `
                            <option value="delivered" selected>Delivered</option>
                            <option value="cancelled">Cancel Order</option>
                        `;
                    } else if (order.order_status === 'cancelled') {
                        document.getElementById('editOrderStatus').innerHTML = `
                            <option value="cancelled" selected>Cancelled</option>
                        `;
                        document.getElementById('updateOrderBtn').disabled = true;
                    }
                    
                    refreshAvailableDrivers();
                } else {
                    document.getElementById('editOrderStatus').innerHTML = `
                        <option value="pending">Pending</option>
                        <option value="confirmed">Confirm Order (Generate Documents & Deduct Stock)</option>
                        <option value="delivered">Mark as Delivered</option>
                        <option value="cancelled">Cancel Order</option>
                    `;
                    document.getElementById('editOrderStatus').disabled = false;
                    document.getElementById('updateOrderBtn').disabled = false;
                    refreshAvailableDrivers();
                }
                
                new bootstrap.Modal(document.getElementById('editOrderModal')).show();
            } else {
                Swal.fire('Error', data.message, 'error');
            }
        })
        .catch(error => {
            Swal.close();
            Swal.fire('Error', 'An error occurred while fetching order details', 'error');
        });
    }

    function updateOrder() {
        const orderId = document.getElementById('editOrderId').value;
        const orderDate = document.getElementById('editOrderDate').value;
        const orderStatus = document.getElementById('editOrderStatus').value;
        const totalAmount = document.getElementById('editTotalAmount').value;
        const selectedDriver = document.getElementById('editDriverSelect').value;
        
        if (!orderDate) {
            Swal.fire('Warning', 'Order Date is required', 'warning');
            return;
        }
        
        if (!totalAmount || totalAmount < 0) {
            Swal.fire('Warning', 'Valid Total Amount is required', 'warning');
            return;
        }
        
        if (orderStatus === 'confirmed') {
            if (!selectedDriver) {
                Swal.fire('Warning', 'Please select a driver for this delivery', 'warning');
                return;
            }
            
            checkStockBeforeConfirm(orderId).then(proceed => {
                if (proceed) {
                    proceedWithUpdate(orderId, orderDate, orderStatus, totalAmount, selectedDriver);
                }
            });
        } else {
            proceedWithUpdate(orderId, orderDate, orderStatus, totalAmount, null);
        }
    }

    function proceedWithUpdate(orderId, orderDate, orderStatus, totalAmount, driverId) {
        showLoading();
        
        const formData = new FormData();
        formData.append('action', 'update_order');
        formData.append('so_id', orderId);
        formData.append('created_at', orderDate);
        formData.append('order_status', orderStatus);
        formData.append('total_amount', totalAmount);
        
        if (driverId) {
            formData.append('driver_id', driverId);
        }
        
        fetch('sales_order.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            Swal.close();
            
            if (data.success) {
                if (data.generated_docs && data.generated_docs.picklist) {
                    let docsList = `
                        <ul class="list-unstyled">
                            <li><i class="bi bi-check-circle-fill text-success"></i> Pick List: ${data.generated_docs.picklist}</li>
                            <li><i class="bi bi-check-circle-fill text-success"></i> Trip Ticket: ${data.generated_docs.trip_ticket}</li>
                            <li><i class="bi bi-check-circle-fill text-primary"></i> Assigned Driver: ${data.generated_docs.driver_name || 'Driver ID: ' + data.generated_docs.driver_id}</li>
                    `;
                    
                    if (data.generated_docs.invoice) {
                        docsList += `<li><i class="bi bi-check-circle-fill text-success"></i> Invoice: ${data.generated_docs.invoice}</li>`;
                    }
                    
                    docsList += '</ul>';
                    
                    Swal.fire({
                        icon: 'success',
                        title: 'Order Confirmed!',
                        html: `
                            <div style="text-align: left;">
                                <p>${data.message}</p>
                                <hr>
                                <h6 class="fw-bold">Generated Documents:</h6>
                                ${docsList}
                            </div>
                        `,
                        confirmButtonText: 'OK',
                        confirmButtonColor: '#0d6efd'
                    }).then(() => {
                        bootstrap.Modal.getInstance(document.getElementById('editOrderModal')).hide();
                        location.reload();
                    });
                } else if (orderStatus === 'delivered') {
                    Swal.fire({
                        icon: 'success',
                        title: 'Order Delivered!',
                        text: 'Order has been marked as delivered. Payment status updated to Paid.',
                        confirmButtonColor: '#0d6efd'
                    }).then(() => {
                        bootstrap.Modal.getInstance(document.getElementById('editOrderModal')).hide();
                        location.reload();
                    });
                } else if (orderStatus === 'cancelled') {
                    Swal.fire({
                        icon: 'success',
                        title: 'Order Cancelled!',
                        text: 'Order has been cancelled. Payment status updated to Cancelled.',
                        confirmButtonColor: '#0d6efd'
                    }).then(() => {
                        bootstrap.Modal.getInstance(document.getElementById('editOrderModal')).hide();
                        location.reload();
                    });
                } else {
                    Swal.fire({
                        icon: 'success',
                        title: 'Success!',
                        text: data.message,
                        timer: 2000,
                        showConfirmButton: false
                    }).then(() => {
                        bootstrap.Modal.getInstance(document.getElementById('editOrderModal')).hide();
                        location.reload();
                    });
                }
            } else {
                Swal.fire('Error', data.message, 'error');
            }
        })
        .catch(error => {
            Swal.close();
            Swal.fire('Error', 'An error occurred while updating the order', 'error');
        });
    }

    function deleteOrder(id) {
        const row = document.querySelector(`.sales-order-row[data-id="${id}"]`);
        if (!row) return;
        
        document.getElementById('deleteOrderNumber').textContent = row.dataset.orderNumber;
        currentOrderId = id;
        new bootstrap.Modal(document.getElementById('deleteOrderModal')).show();
    }

    function confirmDelete() {
        showLoading();
        
        const formData = new FormData();
        formData.append('action', 'delete_order');
        formData.append('so_id', currentOrderId);
        
        fetch('sales_order.php', {
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
                    bootstrap.Modal.getInstance(document.getElementById('deleteOrderModal')).hide();
                    location.reload();
                });
            } else {
                Swal.fire('Error', data.message, 'error');
            }
        })
        .catch(error => {
            Swal.close();
            Swal.fire('Error', 'An error occurred while deleting the order', 'error');
        });
    }

    // ========== PRINT FUNCTIONS ==========
    function printAllOrders() {
        const filterData = {
            status: document.getElementById('statusFilter').value,
            customer: document.getElementById('customerFilter').value,
            search: document.getElementById('searchInput').value,
            start_date: activeDateRange.start,
            end_date: activeDateRange.end
        };
        
        // Show loading indicator on button
        const printBtn = document.querySelector('.btn-primary[onclick="printAllOrders()"]');
        if (printBtn) {
            const originalText = printBtn.innerHTML;
            printBtn.innerHTML = '<i class="bi bi-printer"></i> Preparing...';
            printBtn.disabled = true;
        }
        
        showLoading();
        
        const formData = new FormData();
        formData.append('action', 'get_all_order_items');
        formData.append('filter_data', JSON.stringify(filterData));
        
        fetch('sales_order.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            Swal.close();
            
            if (data.success) {
                const items = data.items;
                
                if (items.length === 0) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'No Data',
                        text: 'No sales order items match the current filters',
                        confirmButtonColor: '#0d6efd'
                    });
                    return;
                }
                
                // Generate compact HTML with each item as a separate row
                const htmlContent = generateAllOrdersHTML(items);
                
                // Use hidden iframe for printing
                const iframe = document.getElementById('printFrame');
                const iframeDoc = iframe.contentWindow.document;
                
                iframeDoc.open();
                iframeDoc.write(htmlContent);
                iframeDoc.close();
                
                // Restore button
                setTimeout(() => {
                    if (printBtn) {
                        printBtn.innerHTML = '<i class="bi bi-printer"></i> Print All Orders';
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
                    text: data.message || 'Failed to load order data',
                    confirmButtonColor: '#0d6efd'
                });
                if (printBtn) {
                    printBtn.innerHTML = '<i class="bi bi-printer"></i> Print All Orders';
                    printBtn.disabled = false;
                }
            }
        })
        .catch(error => {
            Swal.close();
            console.error('Error:', error);
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: 'An error occurred while preparing print: ' + error.message,
                confirmButtonColor: '#0d6efd'
            });
            if (printBtn) {
                printBtn.innerHTML = '<i class="bi bi-printer"></i> Print All Orders';
                printBtn.disabled = false;
            }
        });
    }

    function printSingleOrder(orderId) {
        currentOrderId = orderId;
        
        const printBtn = event ? event.target.closest('button') : null;
        if (printBtn) {
            const originalHTML = printBtn.innerHTML;
            printBtn.innerHTML = '<i class="bi bi-printer"></i>';
            printBtn.disabled = true;
        }
        
        showLoading();
        
        const formData = new FormData();
        formData.append('action', 'print_order');
        formData.append('so_id', orderId);
        
        fetch('sales_order.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            Swal.close();
            
            if (data.success) {
                const order = data.order;
                const items = data.items;
                const driver = data.driver || null;
                
                // Generate compact HTML with each item as a separate row
                const htmlContent = generateSingleOrderHTML(order, items, driver);
                
                // Use hidden iframe for printing
                const iframe = document.getElementById('printFrame');
                const iframeDoc = iframe.contentWindow.document;
                
                iframeDoc.open();
                iframeDoc.write(htmlContent);
                iframeDoc.close();
                
                // Restore button
                setTimeout(() => {
                    if (printBtn) {
                        printBtn.innerHTML = '<i class="bi bi-printer"></i>';
                        printBtn.disabled = false;
                    }
                }, 1000);
                
                // Trigger print dialog
                setTimeout(() => {
                    iframe.contentWindow.focus();
                    iframe.contentWindow.print();
                }, 250);
            } else {
                Swal.fire('Error', 'Failed to load order details', 'error');
                if (printBtn) {
                    printBtn.innerHTML = '<i class="bi bi-printer"></i>';
                    printBtn.disabled = false;
                }
            }
        })
        .catch(error => {
            Swal.close();
            console.error('Error:', error);
            Swal.fire('Error', 'Network error: ' + error.message, 'error');
            if (printBtn) {
                printBtn.innerHTML = '<i class="bi bi-printer"></i>';
                printBtn.disabled = false;
            }
        });
    }

    function printOrder(id) {
        printSingleOrder(id);
        const modal = bootstrap.Modal.getInstance(document.getElementById('viewOrderModal'));
        if (modal) modal.hide();
    }

    // Compact HTML generator for multiple orders
    function generateAllOrdersHTML(items) {
        let tableRows = '';
        let totalAmount = 0;
        let totalQuantity = 0;
        
        items.forEach((item, index) => {
            const subtotal = item.quantity_ordered * item.unit_price;
            totalAmount += subtotal;
            totalQuantity += parseInt(item.quantity_ordered);
            
            tableRows += '<tr>';
            tableRows += `<td style="padding: 3px; border: 1px solid #000;">${item.so_number}</td>`;
            tableRows += `<td style="padding: 3px; border: 1px solid #000;">${item.order_date ? formatDate(item.order_date) : ''}</td>`;
            tableRows += `<td style="padding: 3px; border: 1px solid #000;">${item.customer_name}</td>`;
            if (soBranchColumnExists && viewAllBranches) {
                tableRows += `<td style="padding: 3px; border: 1px solid #000;">${item.branch_name || 'Branch ' + item.branch_id}</td>`;
            }
            tableRows += `<td style="padding: 3px; border: 1px solid #000; text-align: center;">${item.item_code}</td>`;
            tableRows += `<td style="padding: 3px; border: 1px solid #000;">${item.item_name}</td>`;
            tableRows += `<td style="padding: 3px; border: 1px solid #000; text-align: center;">${item.unit_type || 'N/A'}</td>`;
            tableRows += `<td style="padding: 3px; border: 1px solid #000; text-align: center;">${item.quantity_ordered}</td>`;
            tableRows += `<td style="padding: 3px; border: 1px solid #000; text-align: right;">₱${parseFloat(item.unit_price).toFixed(2)}</td>`;
            tableRows += `<td style="padding: 3px; border: 1px solid #000; text-align: right;">₱${parseFloat(subtotal).toFixed(2)}</td>`;
            tableRows += '</tr>';
        });
        
        const currentDate = new Date().toLocaleDateString('en-US', { 
            year: 'numeric', 
            month: 'short', 
            day: 'numeric' 
        });
        
        return `
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset="UTF-8">
                <title>Sales Orders - Detailed</title>
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
                    th { border: 1px solid #000; padding: 3px; text-align: left; font-weight: bold; }
                    td { border: 1px solid #000; padding: 3px; }
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
                                <p>Sales Orders - Detailed</p>
                            </div>
                        </div>
                        <div class="report-title">
                            <h2>SALES ORDERS</h2>
                            <div class="date-info">${currentDate}</div>
                        </div>
                    </div>
                    
                    <div class="summary-box">
                        <div class="summary-item"><div class="summary-label">Total Items</div><div class="summary-value">${items.length}</div></div>
                        <div class="summary-item"><div class="summary-label">Total Qty</div><div class="summary-value">${totalQuantity}</div></div>
                        <div class="summary-item"><div class="summary-label">Total Amount</div><div class="summary-value">₱${totalAmount.toFixed(2)}</div></div>
                        <div class="summary-item"><div class="summary-label">Branch</div><div class="summary-value">${!viewAllBranches && currentBranchId > 0 ? `Branch ${currentBranchId}` : 'All'}</div></div>
                    </div>
                    
                    <table>
                        <thead>
                            <tr>
                                <th>Order #</th>
                                <th>Date</th>
                                <th>Customer</th>
                                ${soBranchColumnExists && viewAllBranches ? '<th>Branch</th>' : ''}
                                <th>Item Code</th>
                                <th>Item Name</th>
                                <th>Unit</th>
                                <th style="text-align: center;">Qty</th>
                                <th style="text-align: right;">Unit Price</th>
                                <th style="text-align: right;">Subtotal</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${tableRows}
                            <tr class="total-row">
                                <td colspan="${soBranchColumnExists && viewAllBranches ? '7' : '6'}" style="text-align: right;">TOTAL</td>
                                <td style="text-align: center;">${totalQuantity}</td>
                                <td></td>
                                <td style="text-align: right;">₱${totalAmount.toFixed(2)}</td>
                            </tr>
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

    // Compact HTML generator for single order
    function generateSingleOrderHTML(order, items, driver) {
        let itemsHtml = '';
        let totalQty = 0;
        
        if (items && items.length > 0) {
            itemsHtml = items.map(item => {
                const subtotal = item.quantity_ordered * item.unit_price;
                totalQty += parseInt(item.quantity_ordered);
                return `
                    <tr>
                        <td style="padding: 3px; border: 1px solid #000;">${item.item_code}</td>
                        <td style="padding: 3px; border: 1px solid #000;">${item.item_name}</td>
                        <td style="padding: 3px; border: 1px solid #000; text-align: center;">${item.unit_type || ''}</td>
                        <td style="padding: 3px; border: 1px solid #000; text-align: center;">${item.quantity_ordered}</td>
                        <td style="padding: 3px; border: 1px solid #000; text-align: right;">₱${parseFloat(item.unit_price).toFixed(2)}</td>
                        <td style="padding: 3px; border: 1px solid #000; text-align: right;">₱${parseFloat(subtotal).toFixed(2)}</td>
                    </tr>
                `;
            }).join('');
        }
        
        const createdByName = order ? (order.first_name ? `${order.first_name} ${order.last_name || ''}` : 'Branch Admin') : 'Branch Admin';
        const currentDate = new Date().toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
        const orderDate = order ? (order.order_date ? new Date(order.order_date).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' }) : currentDate) : currentDate;
        const customerName = order ? order.customer_name : '';
        const orderNumber = order ? order.so_number : '';
        const orderStatus = order ? order.order_status : '';
        const totalAmount = order ? order.order_total : 0;
        
        return `
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset="UTF-8">
                <title>Order #${orderNumber}</title>
                <style>
                    body { font-family: Arial, sans-serif; margin: 0; padding: 0; font-size: 10px; }
                    .print-container { max-width: 100%; margin: 0; }
                    .print-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 5px; border-bottom: 1px solid #000; padding-bottom: 3px; }
                    .logo-section { display: flex; align-items: center; gap: 5px; }
                    .company-logo { width: 30px; height: auto; }
                    .company-info h1 { font-size: 14px; margin: 0; font-weight: bold; }
                    .company-info p { font-size: 8px; margin: 0; }
                    .report-title h2 { font-size: 12px; margin: 0; }
                    .report-title .date-info { font-size: 8px; }
                    .customer-section { border: 1px solid #000; padding: 5px; margin-bottom: 5px; }
                    .section-title { font-size: 10px; font-weight: bold; margin-bottom: 3px; border-bottom: 1px solid #000; }
                    .info-row { display: flex; font-size: 9px; margin-bottom: 2px; }
                    .info-label { width: 80px; font-weight: bold; }
                    .info-value { flex: 1; }
                    table { width: 100%; border-collapse: collapse; font-size: 9px; }
                    th { border: 1px solid #000; padding: 3px; text-align: left; font-weight: bold; }
                    td { border: 1px solid #000; padding: 3px; }
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
                                <p>Sales Order</p>
                            </div>
                        </div>
                        <div class="report-title">
                            <h2>${orderNumber}</h2>
                            <div class="date-info">${currentDate}</div>
                        </div>
                    </div>
                    
                    <div class="customer-section">
                        <div class="section-title">Order Info</div>
                        <div style="display: flex; flex-wrap: wrap;">
                            <div class="info-row" style="width: 50%;"><span class="info-label">Date:</span><span class="info-value">${orderDate}</span></div>
                            <div class="info-row" style="width: 50%;"><span class="info-label">Status:</span><span class="info-value">${orderStatus}</span></div>
                            <div class="info-row" style="width: 50%;"><span class="info-label">Customer:</span><span class="info-value">${customerName}</span></div>
                            ${driver ? `<div class="info-row" style="width: 50%;"><span class="info-label">Driver:</span><span class="info-value">${driver.driver_name}</span></div>` : ''}
                        </div>
                    </div>
                    
                    <div class="section-title">Items</div>
                    <table>
                        <thead>
                            <tr>
                                <th>Item Code</th>
                                <th>Item Name</th>
                                <th style="text-align: center;">Unit</th>
                                <th style="text-align: center;">Qty</th>
                                <th style="text-align: right;">Unit Price</th>
                                <th style="text-align: right;">Subtotal</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${itemsHtml}
                            <tr class="total-row">
                                <td colspan="3" style="text-align: right;">TOTAL</td>
                                <td style="text-align: center;">${totalQty}</td>
                                <td></td>
                                <td style="text-align: right;">₱${parseFloat(totalAmount).toFixed(2)}</td>
                            </tr>
                        </tbody>
                    </table>
                    
                    <div class="print-footer">
                        <div>Created by: ${createdByName}</div>
                        <div>${currentDate}</div>
                    </div>
                </div>
            </body>
            </html>
        `;
    }

    // ========== EXCEL EXPORT FUNCTION ==========
    function exportToExcel() {
        const filterData = {
            status: document.getElementById('statusFilter').value,
            customer: document.getElementById('customerFilter').value,
            search: document.getElementById('searchInput').value,
            start_date: activeDateRange.start,
            end_date: activeDateRange.end
        };
        
        showLoading();
        
        const formData = new FormData();
        formData.append('action', 'get_all_order_items');
        formData.append('filter_data', JSON.stringify(filterData));
        
        fetch('sales_order.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            Swal.close();
            
            if (data.success) {
                const items = data.items;
                
                if (items.length === 0) {
                    Swal.fire('Warning', 'No orders to export', 'warning');
                    return;
                }
                
                // Prepare data array for Excel - each item as separate row
                const excelData = [];
                
                // Add headers
                const headers = [
                    'Order Number',
                    'Order Date',
                    'Customer Name',
                    ...(soBranchColumnExists && viewAllBranches ? ['Branch'] : []),
                    'Item Code',
                    'Item Name',
                    'Unit Type',
                    'Quantity',
                    'Unit Price (₱)',
                    'Subtotal (₱)'
                ];
                excelData.push(headers);

                // Add data rows
                items.forEach(item => {
                    const subtotal = item.quantity_ordered * item.unit_price;
                    
                    const rowData = [
                        item.so_number,
                        item.order_date ? formatDate(item.order_date) : '',
                        item.customer_name,
                        ...(soBranchColumnExists && viewAllBranches ? [item.branch_name || 'Branch ' + item.branch_id] : []),
                        item.item_code,
                        item.item_name,
                        item.unit_type || 'N/A',
                        item.quantity_ordered,
                        parseFloat(item.unit_price),
                        parseFloat(subtotal.toFixed(2))
                    ];
                    
                    excelData.push(rowData);
                });

                // Add total row
                const totalQty = items.reduce((sum, item) => sum + parseInt(item.quantity_ordered), 0);
                const totalAmount = items.reduce((sum, item) => sum + (item.quantity_ordered * item.unit_price), 0);
                
                const totalRow = [
                    'TOTAL',
                    '',
                    '',
                    ...(soBranchColumnExists && viewAllBranches ? [''] : []),
                    '',
                    '',
                    '',
                    totalQty,
                    '',
                    parseFloat(totalAmount.toFixed(2))
                ];
                excelData.push(totalRow);

                // Create workbook and worksheet
                const wb = XLSX.utils.book_new();
                const ws = XLSX.utils.aoa_to_sheet(excelData);

                // Set column widths
                const colWidths = [
                    { wch: 15 }, // Order Number
                    { wch: 15 }, // Order Date
                    { wch: 30 }, // Customer Name
                    ...(soBranchColumnExists && viewAllBranches ? [{ wch: 15 }] : []), // Branch
                    { wch: 15 }, // Item Code
                    { wch: 30 }, // Item Name
                    { wch: 10 }, // Unit Type
                    { wch: 12 }, // Quantity
                    { wch: 15 }, // Unit Price
                    { wch: 18 }  // Subtotal
                ];
                ws['!cols'] = colWidths;

                // Add worksheet to workbook
                XLSX.utils.book_append_sheet(wb, ws, 'Sales Orders');

                // Generate filename with current date
                const date = new Date();
                const dateStr = date.toISOString().slice(0,10).replace(/-/g, '');
                let filename = `Sales_Orders_${dateStr}`;
                if (!viewAllBranches && currentBranchId > 0) {
                    filename += `_Branch_${currentBranchId}`;
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
            } else {
                Swal.fire('Error', data.message || 'Failed to load order data', 'error');
            }
        })
        .catch(error => {
            Swal.close();
            console.error('Error:', error);
            Swal.fire('Error', 'An error occurred while exporting: ' + error.message, 'error');
        });
    }

    function refreshOrders() {
        location.reload();
    }

    function formatDate(dateStr) {
        if (!dateStr) return '';
        const date = new Date(dateStr);
        return date.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
    }

    function getStatusBadge(status) {
        const classes = {
            'pending': 'badge bg-warning text-dark',
            'confirmed': 'badge bg-info text-white',
            'processing': 'badge bg-primary text-white',
            'ready': 'badge bg-info text-white',
            'delivered': 'badge bg-success text-white',
            'cancelled': 'badge bg-danger text-white'
        };
        return classes[status] || 'badge bg-secondary text-white';
    }

    function getStatusText(status) {
        const texts = {
            'pending': 'Pending',
            'confirmed': 'Confirmed',
            'processing': 'Processing',
            'ready': 'For Delivery',
            'delivered': 'Delivered',
            'cancelled': 'Cancelled'
        };
        return texts[status] || status;
    }

    function copyFixSQL() {
        const sql = "ALTER TABLE invoices ADD COLUMN so_id INT NULL;\nALTER TABLE invoices ADD FOREIGN KEY (so_id) REFERENCES sales_orders(so_id);";
        navigator.clipboard.writeText(sql).then(() => {
            Swal.fire({ icon: 'success', title: 'Copied!', text: 'SQL copied to clipboard', timer: 1500, showConfirmButton: false });
        });
    }

    function copySQL(table) {
        let sql = '';
        if (table === 'sales_orders') {
            sql = "ALTER TABLE sales_orders ADD COLUMN branch_id INT NULL;\nALTER TABLE sales_orders ADD FOREIGN KEY (branch_id) REFERENCES branches(branch_id);";
        } else if (table === 'customers') {
            sql = "ALTER TABLE customers ADD COLUMN branch_id INT NULL;\nALTER TABLE customers ADD FOREIGN KEY (branch_id) REFERENCES branches(branch_id);";
        } else if (table === 'drivers') {
            sql = "ALTER TABLE drivers ADD COLUMN branch_id INT NULL;\nALTER TABLE drivers ADD FOREIGN KEY (branch_id) REFERENCES branches(branch_id);";
        }
        navigator.clipboard.writeText(sql).then(() => {
            Swal.fire({ icon: 'success', title: 'Copied!', timer: 1500, showConfirmButton: false });
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
        else if (e.ctrlKey && e.key === 'r' && !e.target.matches('input, textarea')) {
            e.preventDefault();
            refreshOrders();
        }
        else if (e.ctrlKey && e.key === 'p' && !e.target.matches('input, textarea')) {
            e.preventDefault();
            printAllOrders();
        }
    });
    </script>
</body>
</html>