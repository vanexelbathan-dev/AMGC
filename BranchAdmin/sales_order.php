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

// Determine branch filter condition
$branch_condition = "";
if ($so_branch_column_exists && !$view_all_branches) {
    $branch_condition = "AND so.branch_id = $branch_id";
}

$customers_branch_condition = "";
if ($customers_branch_column_exists && !$view_all_branches) {
    $customers_branch_condition = "AND branch_id = $branch_id";
}

// ========== HANDLE AJAX REQUESTS ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    try {
        $conn->begin_transaction();
        
        // UPDATE SALES ORDER
        if ($_POST['action'] === 'update_order') {
            $so_id = (int)$_POST['so_id'];
            $order_date = $_POST['order_date'];
            $order_status = $_POST['order_status'];
            $total_amount = (float)$_POST['total_amount'];
            
            // Get the old status to check if it's being confirmed
            $status_query = "SELECT order_status, customer_id, branch_id, so_number FROM sales_orders WHERE so_id = ?";
            $status_stmt = $conn->prepare($status_query);
            $status_stmt->bind_param("i", $so_id);
            $status_stmt->execute();
            $order_info = $status_stmt->get_result()->fetch_assoc();
            $old_status = $order_info['order_status'];
            
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
            
            $update_query = "UPDATE sales_orders 
                           SET order_date = ?, order_status = ?, total_amount = ?, updated_at = NOW() 
                           WHERE so_id = ?";
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->bind_param("ssdi", $order_date, $order_status, $total_amount, $so_id);
            
            if (!$update_stmt->execute()) {
                throw new Exception('Failed to update sales order');
            }
            
            // GENERATE PICK LIST, INVOICE, AND TRIP TICKET WHEN ORDER IS CONFIRMED
            if ($order_status === 'confirmed' && $old_status !== 'confirmed') {
                
                // 1. CREATE PICK LIST
                $pick_list_number = 'PL-' . date('Ymd') . '-' . str_pad($so_id, 5, '0', STR_PAD_LEFT);
                $picklist_query = "INSERT INTO pick_lists (pick_list_number, so_id, branch_id, pick_status, created_at) 
                                  VALUES (?, ?, ?, 'open', NOW())";
                $picklist_stmt = $conn->prepare($picklist_query);
                $picklist_stmt->bind_param("sii", $pick_list_number, $so_id, $branch_id);
                
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
                    $invoice_stmt->bind_param("siiissd", $invoice_number, $so_id, $order_info['customer_id'], $branch_id, $invoice_date, $due_date, $total_amount);
                    
                    if (!$invoice_stmt->execute()) {
                        throw new Exception('Failed to create invoice');
                    }
                    $invoice_id = $conn->insert_id;
                }
                
                // 3. CREATE TRIP TICKET - FIXED with driver_id
                // First, get an available driver for this branch
                $driver_query = "SELECT driver_id FROM drivers WHERE branch_id = ? AND status = 'active' LIMIT 1";
                $driver_stmt = $conn->prepare($driver_query);
                $driver_stmt->bind_param("i", $branch_id);
                $driver_stmt->execute();
                $driver_result = $driver_stmt->get_result();
                $driver = $driver_result->fetch_assoc();
                
                if (!$driver) {
                    // If no active driver found, try to get any driver
                    $driver_query = "SELECT driver_id FROM drivers WHERE branch_id = ? LIMIT 1";
                    $driver_stmt = $conn->prepare($driver_query);
                    $driver_stmt->bind_param("i", $branch_id);
                    $driver_stmt->execute();
                    $driver_result = $driver_stmt->get_result();
                    $driver = $driver_result->fetch_assoc();
                }
                
                if (!$driver) {
                    // If still no driver, use a default driver ID (1) as fallback
                    $driver_id = 1;
                } else {
                    $driver_id = $driver['driver_id'];
                }
                
                $trip_ticket_number = 'TT-' . date('Ymd') . '-' . str_pad($so_id, 5, '0', STR_PAD_LEFT);
                $branch_to_use = ($so_branch_column_exists && !$view_all_branches) ? $branch_id : $order_info['branch_id'];
                $trip_date = date('Y-m-d');
                
                // Base required fields for trip_tickets from your database
                // From your structure: trip_number, driver_id, branch_id, trip_date, trip_status, created_by, created_at
                $trip_fields = "trip_number, driver_id, branch_id, trip_date, trip_status, created_by, created_at";
                $trip_values = "?, ?, ?, ?, 'planned', ?, NOW()";
                $trip_types = "siisi"; // string, int, int, string, int
                $trip_params = [$trip_ticket_number, $driver_id, $branch_to_use, $trip_date, $user_id];
                
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
                
                $conn->commit();
                
                $response = [
                    'success' => true,
                    'message' => 'Order confirmed successfully! Pick List and Trip Ticket have been generated.',
                    'generated_docs' => [
                        'picklist' => $pick_list_number,
                        'trip_ticket' => $trip_ticket_number,
                        'driver_assigned' => $driver_id
                    ]
                ];
                
                if ($invoice_so_column_exists) {
                    $response['message'] = 'Order confirmed successfully! Pick List, Invoice, and Trip Ticket have been generated.';
                    $response['generated_docs']['invoice'] = $invoice_number;
                }
                
                echo json_encode($response);
                exit;
            }
            
            $conn->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'Sales order updated successfully'
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
            
            // Check for trip tickets - need to check if so_id exists in trip_tickets first
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
            
            // Add branch filter if needed
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
                // Get order items
                $items_query = "
                    SELECT 
                        soi.*,
                        i.item_code,
                        i.item_name,
                        i.unit_type,
                        i.branch_id as item_branch_id
                    FROM sales_order_items soi
                    JOIN items i ON soi.item_id = i.item_id
                    WHERE soi.so_id = ?
                ";
                $items_stmt = $conn->prepare($items_query);
                $items_stmt->bind_param("i", $so_id);
                $items_stmt->execute();
                $items_result = $items_stmt->get_result();
                $items = $items_result->fetch_all(MYSQLI_ASSOC);
                
                // Get generated documents
                $docs_query = "
                    SELECT 
                        (SELECT pick_list_number FROM pick_lists WHERE so_id = ? LIMIT 1) as pick_list_number,
                        (SELECT trip_number FROM trip_tickets WHERE " . ($trip_has_so_id ? "so_id = ?" : "1=0") . " LIMIT 1) as trip_ticket_number
                ";
                $docs_stmt = $conn->prepare($docs_query);
                
                if ($trip_has_so_id) {
                    $docs_stmt->bind_param("ii", $so_id, $so_id);
                } else {
                    $docs_stmt->bind_param("i", $so_id);
                }
                
                $docs_stmt->execute();
                $documents = $docs_stmt->get_result()->fetch_assoc();
                
                // Get invoice data if column exists
                $invoice = null;
                if ($invoice_so_column_exists) {
                    $invoice_query = "SELECT invoice_number, status as invoice_status FROM invoices WHERE so_id = ? LIMIT 1";
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
        
        // PRINT SALES ORDER
        elseif ($_POST['action'] === 'print_order') {
            $so_id = (int)$_POST['so_id'];
            
            $query = "
                SELECT 
                    so.*,
                    c.customer_name,
                    c.address,
                    c.phone_number as contact_number,
                    c.email,
                    b.branch_name,
                    b.address as branch_address,
                    b.contact_number as branch_contact,
                    COUNT(soi.so_item_id) as total_items,
                    SUM(soi.quantity_ordered) as total_quantity
                FROM sales_orders so
                JOIN customers c ON so.customer_id = c.customer_id
                LEFT JOIN branches b ON so.branch_id = b.branch_id
                LEFT JOIN sales_order_items soi ON so.so_id = soi.so_id
                WHERE so.so_id = ?
                GROUP BY so.so_id
            ";
            
            $stmt = $conn->prepare($query);
            $stmt->bind_param("i", $so_id);
            $stmt->execute();
            $order = $stmt->get_result()->fetch_assoc();
            
            // Get items
            $items_query = "
                SELECT 
                    soi.*,
                    i.item_code,
                    i.item_name,
                    i.unit_type
                FROM sales_order_items soi
                JOIN items i ON soi.item_id = i.item_id
                WHERE soi.so_id = ?
            ";
            $items_stmt = $conn->prepare($items_query);
            $items_stmt->bind_param("i", $so_id);
            $items_stmt->execute();
            $items = $items_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            
            echo json_encode([
                'success' => true,
                'order' => $order,
                'items' => $items
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
        so.order_date,
        so.total_amount,
        so.order_status,
        so.branch_id,
        c.customer_name,
        c.customer_id,
        b.branch_name,
        COUNT(soi.so_item_id) as total_items,
        SUM(soi.quantity_ordered) as total_quantity,
        " . ($invoice_so_column_exists ? "inv.invoice_number, inv.status as invoice_status" : "NULL as invoice_number, NULL as invoice_status") . "
    FROM sales_orders so
    JOIN customers c ON so.customer_id = c.customer_id
    LEFT JOIN branches b ON so.branch_id = b.branch_id
    LEFT JOIN sales_order_items soi ON so.so_id = soi.so_id
    " . ($invoice_so_column_exists ? "LEFT JOIN invoices inv ON so.so_id = inv.so_id" : "") . "
    WHERE 1=1
    $branch_condition
    GROUP BY so.so_id
    ORDER BY so.order_date DESC, so.so_id DESC
";
$sales_result = $conn->query($sales_query);
if (!$sales_result) {
    die("Query failed: " . $conn->error);
}
$sales_orders = $sales_result->fetch_all(MYSQLI_ASSOC);

// CALCULATE STATISTICS FROM REAL DATA
$total_orders = count($sales_orders);
$pending_orders = count(array_filter($sales_orders, fn($so) => $so['order_status'] === 'pending'));
$processing_orders = count(array_filter($sales_orders, fn($so) => $so['order_status'] === 'processing'));
$ready_orders = count(array_filter($sales_orders, fn($so) => $so['order_status'] === 'ready'));
$delivered_orders = count(array_filter($sales_orders, fn($so) => $so['order_status'] === 'delivered'));
$cancelled_orders = count(array_filter($sales_orders, fn($so) => $so['order_status'] === 'cancelled'));

// STAT CARD VALUES
$statTotalOrders = $total_orders;
$statPendingOrders = $pending_orders;
$statForDelivery = $ready_orders;
$statCompletedOrders = $delivered_orders;

// Get unique customers for filter - branch-specific
$customers_query = "SELECT customer_id, customer_name FROM customers WHERE status = 'active' $customers_branch_condition ORDER BY customer_name";
$customers_result = $conn->query($customers_query);
$customers = $customers_result->fetch_all(MYSQLI_ASSOC);

// Helper function for order status badge
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

// Payment status based on invoice if available, otherwise simplified
function getPaymentStatus($order_status, $invoice_status = null) {
    if ($order_status === 'cancelled') return ['status' => 'Cancelled', 'class' => 'badge-danger'];
    
    if ($invoice_status) {
        return match($invoice_status) {
            'paid' => ['status' => 'Paid', 'class' => 'badge-success'],
            'pending' => ['status' => 'Pending', 'class' => 'badge-warning'],
            'cancelled' => ['status' => 'Cancelled', 'class' => 'badge-danger'],
            default => ['status' => 'Pending', 'class' => 'badge-warning']
        };
    }
    
    return ['status' => 'No Invoice', 'class' => 'badge-secondary'];
}

function formatDate($dateStr) {
    if (!$dateStr) return '';
    $date = new DateTime($dateStr);
    return $date->format('M d, Y');
}

function formatDateTime($dateStr) {
    if (!$dateStr) return '';
    $date = new DateTime($dateStr);
    return $date->format('M d, Y H:i');
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
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <!-- SheetJS for Excel Export -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <!-- SweetAlert2 -->
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <style media="print">
        @page {
            size: A4;
            margin: 0.5in;
        }
        body {
            background-color: white;
            font-family: Arial, Helvetica, sans-serif;
            color: black;
            margin: 0;
            padding: 0;
        }
        .sidebar, .navbar-top, .footer, .action-buttons, 
        .btn, .table-header .btn, .form-card, 
        .mobile-menu-btn, #desktopToggleBtn, .sidebar-footer,
        .stat-card, .alert, .page-title p, .badge, .branch-badge,
        .modal, .data-table .table-header button {
            display: none !important;
        }
        .main-content {
            margin-left: 0 !important;
            padding: 0 !important;
            width: 100% !important;
        }
        #dashboardContent {
            display: block !important;
        }
        .data-table {
            box-shadow: none !important;
            border: 1px solid #ddd !important;
            padding: 20px !important;
            margin: 0 !important;
        }
        .custom-table {
            width: 100% !important;
            border-collapse: collapse !important;
        }
        .custom-table th {
            background-color: #f0f0f0 !important;
            color: black !important;
            font-weight: bold !important;
            border: 1px solid #333 !important;
            padding: 10px !important;
        }
        .custom-table td {
            border: 1px solid #ddd !important;
            padding: 8px !important;
        }
        .page-title h2 {
            color: black !important;
            margin-bottom: 20px !important;
            display: block !important;
            font-size: 24px !important;
        }
        .page-title h2 i {
            display: none !important;
        }
        .row.g-3.mb-4 {
            display: none !important;
        }
        #dashboardContent:before {
            content: "AMGC Branch System";
            display: block;
            font-size: 28px;
            font-weight: bold;
            color: #333;
            text-align: center;
            margin-bottom: 20px;
            border-bottom: 2px solid #333;
            padding-bottom: 10px;
        }
        #dashboardContent:after {
            content: "Sales Orders Report - " attr(data-print-date);
            display: block;
            font-size: 14px;
            color: #666;
            text-align: center;
            margin-bottom: 30px;
        }
        .text-end {
            text-align: right !important;
        }
        .text-center {
            text-align: center !important;
        }
    </style>
    
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
        @keyframes slideIn {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }
        .document-notification {
            animation: slideIn 0.5s ease-out;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 12px;
            margin-top: 20px;
        }
        .print-order-btn {
            transition: all 0.3s ease;
        }
        .print-order-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
        }
        .invoice-badge {
            font-size: 0.7rem;
            padding: 2px 6px;
            margin-left: 5px;
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
    </style>
</head>
<body>
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
                        <a class="nav-link" href="purchase_order.php">
                            <i class="bi bi-box"></i>
                            <span class="nav-text">Purchase Orders</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="trip_tickets.php">
                            <i class="bi bi-ticket-perforated"></i>
                            <span class="nav-text">Trip Tickets</span>
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
            <div id="dashboardContent" class="page-content active" data-print-date="<?php echo date('F d, Y H:i:s'); ?>">
                <div class="navbar-top">
                    <button class="mobile-menu-btn" id="mobileMenuBtn">
                        <i class="bi bi-list"></i>
                    </button>
                    
                    <div class="page-title">
                        <h2><i class="bi bi-bag me-2"></i>Sales Orders</h2>
                        <p id="dashboardSubtitle">
                            Manage and track all sales orders
                        </p>
                    </div>
                </div>

                <!-- Database Fix Alert - Only show if invoice_so_column doesn't exist -->
                <?php if (!$invoice_so_column_exists): ?>
                <div class="db-fix-card">
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
                    <div class="alert alert-info alert-dismissible fade show" role="alert">
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
                    <div class="alert alert-info alert-dismissible fade show" role="alert">
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

                <!-- No Orders Warning -->
                <?php if (empty($sales_orders) && $so_branch_column_exists && !$view_all_branches): ?>
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle"></i> 
                        No sales orders found for your branch.
                    </div>
                <?php endif; ?>

                <!-- Quick Stats -->
                <div class="row g-3 mb-4">
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
                        <div class="stat-card delivery">
                            <i class="bi bi-truck stat-icon"></i>
                            <div class="stat-value"><?= $statForDelivery ?></div>
                            <div class="stat-label">For Delivery</div>
                            <small class="d-block mt-2">Ready to ship</small>
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

                <!-- Search and Filter -->
                <div class="row g-3 mb-4">
                    <div class="col-12">
                        <div class="form-card">
                            <div class="row g-3">
                                <div class="col-md-5">
                                    <div class="search-box">
                                        <i class="bi bi-search"></i>
                                        <input type="text" class="form-control" id="searchInput" placeholder="Search by order number or customer..." onkeyup="filterTable()">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <select class="form-select" id="statusFilter" onchange="filterTable()">
                                        <option value="">All Status</option>
                                        <option value="pending">Pending</option>
                                        <option value="confirmed">Confirmed</option>
                                        <option value="processing">Processing</option>
                                        <option value="ready">For Delivery</option>
                                        <option value="delivered">Delivered</option>
                                        <option value="cancelled">Cancelled</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <select class="form-select" id="customerFilter" onchange="filterTable()">
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

                <!-- Sales Orders Table -->
                <div class="data-table">
                    <div class="table-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="bi bi-list-ul me-2"></i>Sales Orders</h5>
                        <div class="d-flex gap-2 align-items-center">
                            <?php if ($so_branch_column_exists && $view_all_branches): ?>
                                <span class="badge bg-success">All Branches</span>
                            <?php endif; ?>
                            <span class="text-muted me-2">Total: ₱<?= number_format(array_sum(array_column($sales_orders, 'total_amount')), 2) ?></span>
                            <button class="btn btn-sm btn-outline-primary" onclick="printReport()">
                                <i class="bi bi-printer me-1"></i> Print
                            </button>
                            <button class="btn btn-sm btn-outline-success" onclick="exportToExcel()">
                                <i class="bi bi-file-earmark-excel me-1"></i> Export to Excel
                            </button>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table custom-table" id="salesOrdersTable">
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
                                    <th>Invoice</th>
                                    <th>Payment Status</th>
                                    <th>Order Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="salesOrdersTableBody">
                                <?php if (empty($sales_orders)): ?>
                                <tr>
                                    <td colspan="<?= ($so_branch_column_exists && $view_all_branches) ? '11' : '10' ?>" class="text-center py-4">
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
                                        data-date="<?= $order['order_date'] ?>"
                                        data-amount="<?= $order['total_amount'] ?>"
                                        data-items="<?= $order['total_items'] ?? 0 ?>"
                                        data-qty="<?= $order['total_quantity'] ?? 0 ?>"
                                        data-invoice="<?= htmlspecialchars($order['invoice_number'] ?? '') ?>"
                                        data-invoice-status="<?= $order['invoice_status'] ?? '' ?>">
                                        <td><strong><?= htmlspecialchars($order['so_number']) ?></strong></td>
                                        <td><?= formatDate($order['order_date']) ?></td>
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
                                            <?php if ($order['invoice_number']): ?>
                                                <span class="badge bg-success"><?= htmlspecialchars($order['invoice_number']) ?></span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">No Invoice</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge <?= $payment['class'] ?>"><?= $payment['status'] ?></span>
                                        </td>
                                        <td>
                                            <span class="<?= getOrderStatusBadge($order['order_status']) ?>">
                                                <?= getOrderStatusText($order['order_status']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="btn btn-sm btn-outline-primary" onclick="viewOrder(<?= $order['so_id'] ?>)" title="View">
                                                    <i class="bi bi-eye"></i>
                                                </button>
                                                <?php if ($order['order_status'] == 'pending'): ?>
                                                    <button class="btn btn-sm btn-outline-warning" onclick="editOrder(<?= $order['so_id'] ?>)" title="Edit">
                                                        <i class="bi bi-pencil"></i>
                                                    </button>
                                                <?php elseif (in_array($order['order_status'], ['confirmed', 'processing', 'ready', 'delivered'])): ?>
                                                    <button class="btn btn-sm btn-outline-success" onclick="printOrder(<?= $order['so_id'] ?>)" title="Print Order">
                                                        <i class="bi bi-printer"></i>
                                                    </button>
                                                <?php endif; ?>
                                                <?php if ($order['order_status'] == 'pending'): ?>
                                                    <button class="btn btn-sm btn-outline-danger" onclick="deleteOrder(<?= $order['so_id'] ?>)" title="Delete">
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
    <div class="modal fade" id="viewOrderModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title"><i class="bi bi-eye me-2"></i>Sales Order Details</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="viewOrderContent">
                        <!-- Content populated by JavaScript -->
                    </div>
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
    <div class="modal fade" id="editOrderModal" tabindex="-1" aria-hidden="true">
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
                                <select class="form-select" id="editOrderStatus" required>
                                    <option value="pending">Pending</option>
                                    <option value="confirmed">Confirm Order (Generate Documents)</option>
                                </select>
                                <small class="text-muted">Confirming will generate Pick List and Trip Ticket</small>
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
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="updateOrder()">Update Order</button>
                </div>
            </div>
        </div>
    </div>

    <!-- DELETE CONFIRMATION MODAL -->
    <div class="modal fade" id="deleteOrderModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="bi bi-trash me-2"></i>Confirm Delete</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
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

    <!-- PRINT PREVIEW MODAL -->
    <div class="modal fade" id="printPreviewModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="bi bi-printer me-2"></i>Print Sales Order</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="printPreviewContent" class="print-preview"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary" onclick="executePrint()">
                        <i class="bi bi-printer"></i> Print
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
    // ========== GLOBAL VARIABLES ==========
    let currentOrderId = null;
    const branchId = <?php echo $branch_id; ?>;
    const viewAllBranches = <?php echo $view_all_branches ? 'true' : 'false'; ?>;
    const soBranchColumnExists = <?php echo $so_branch_column_exists ? 'true' : 'false'; ?>;
    const invoiceSoColumnExists = <?php echo $invoice_so_column_exists ? 'true' : 'false'; ?>;
    
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
    });

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
                
                const orderDate = new Date(order.order_date);
                const formattedDate = orderDate.toLocaleDateString('en-US', {
                    year: 'numeric',
                    month: 'short',
                    day: 'numeric'
                });
                
                const statusBadge = getStatusBadge(order.order_status);
                const statusText = getStatusText(order.order_status);
                
                // Build items table
                let itemsHtml = '';
                if (items && items.length > 0) {
                    itemsHtml = '<h6 class="mt-4 mb-3 fw-bold">Order Items</h6><div class="table-responsive"><table class="table table-sm table-bordered"><thead class="table-light"><tr><th>Item Code</th><th>Item Name</th><th>Quantity</th><th>Unit Price</th><th>Subtotal</th></tr></thead><tbody>';
                    items.forEach(item => {
                        const subtotal = item.quantity_ordered * item.unit_price;
                        itemsHtml += `<tr>
                            <td>${item.item_code}</td>
                            <td>${item.item_name}</td>
                            <td class="text-center">${item.quantity_ordered} ${item.unit_type || ''}</td>
                            <td class="text-end">₱${Number(item.unit_price).toFixed(2)}</td>
                            <td class="text-end">₱${Number(subtotal).toFixed(2)}</td>
                        </tr>`;
                    });
                    itemsHtml += '</tbody></table></div>';
                }
                
                // Build documents section
                let documentsHtml = '<div class="mt-4"><h6 class="fw-bold">Generated Documents</h6><div class="row g-2">';
                
                if (documents.pick_list_number) {
                    documentsHtml += `<div class="col-md-4"><div class="card bg-light"><div class="card-body p-2"><small class="text-muted">Pick List</small><br><strong>${documents.pick_list_number}</strong></div></div></div>`;
                }
                
                if (invoice) {
                    documentsHtml += `<div class="col-md-4"><div class="card bg-light"><div class="card-body p-2"><small class="text-muted">Invoice</small><br><strong>${invoice.invoice_number}</strong><br><span class="badge bg-${invoice.invoice_status === 'paid' ? 'success' : 'warning'}">${invoice.invoice_status}</span></div></div></div>`;
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
                
                // Show/hide buttons based on status
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

    // Edit from View Modal
    function editFromView() {
        bootstrap.Modal.getInstance(document.getElementById('viewOrderModal')).hide();
        setTimeout(() => {
            editOrder(currentOrderId);
        }, 300);
    }

    // Edit Order
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
                
                const orderDate = order.order_date.split(' ')[0];
                
                document.getElementById('editOrderId').value = order.so_id;
                document.getElementById('editOrderNumber').value = order.so_number;
                document.getElementById('editOrderDate').value = orderDate;
                document.getElementById('editCustomerName').value = order.customer_name;
                document.getElementById('editOrderStatus').value = order.order_status;
                document.getElementById('editTotalItems').value = order.total_items || 0;
                document.getElementById('editTotalQty').value = order.total_quantity || 0;
                document.getElementById('editTotalAmount').value = order.total_amount;
                
                currentOrderId = id;
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

    // Update Order
    function updateOrder() {
        const orderId = document.getElementById('editOrderId').value;
        const orderDate = document.getElementById('editOrderDate').value;
        const orderStatus = document.getElementById('editOrderStatus').value;
        const totalAmount = document.getElementById('editTotalAmount').value;
        
        if (!orderDate) {
            Swal.fire('Warning', 'Order Date is required', 'warning');
            return;
        }
        
        if (!totalAmount || totalAmount < 0) {
            Swal.fire('Warning', 'Valid Total Amount is required', 'warning');
            return;
        }
        
        showLoading();
        
        const formData = new FormData();
        formData.append('action', 'update_order');
        formData.append('so_id', orderId);
        formData.append('order_date', orderDate);
        formData.append('order_status', orderStatus);
        formData.append('total_amount', totalAmount);
        
        fetch('sales_order.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            Swal.close();
            
            if (data.success) {
                if (data.generated_docs) {
                    let docsList = `
                        <ul class="list-unstyled">
                            <li><i class="bi bi-check-circle-fill text-success"></i> Pick List: ${data.generated_docs.picklist}</li>
                            <li><i class="bi bi-check-circle-fill text-success"></i> Trip Ticket: ${data.generated_docs.trip_ticket}</li>
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

    // Delete Order
    function deleteOrder(id) {
        const row = document.querySelector(`.sales-order-row[data-id="${id}"]`);
        if (!row) return;
        
        document.getElementById('deleteOrderNumber').textContent = row.dataset.orderNumber;
        currentOrderId = id;
        new bootstrap.Modal(document.getElementById('deleteOrderModal')).show();
    }

    // Confirm Delete
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
    function printOrder(id) {
        showLoading();
        
        const formData = new FormData();
        formData.append('action', 'print_order');
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
                
                const printContent = generateSalesOrderDocument(order, items);
                document.getElementById('printPreviewContent').innerHTML = printContent;
                new bootstrap.Modal(document.getElementById('printPreviewModal')).show();
            } else {
                Swal.fire('Error', data.message, 'error');
            }
        })
        .catch(error => {
            Swal.close();
            Swal.fire('Error', 'An error occurred', 'error');
        });
    }

    function generateSalesOrderDocument(order, items) {
        const orderDate = new Date(order.order_date);
        const formattedDate = orderDate.toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'long',
            day: 'numeric'
        });
        
        let itemsHtml = '';
        let subtotal = 0;
        
        items.forEach((item, index) => {
            const itemSubtotal = item.quantity_ordered * item.unit_price;
            subtotal += itemSubtotal;
            
            itemsHtml += `<tr>
                <td style="padding: 8px; border: 1px solid #ddd; text-align: center;">${index + 1}</td>
                <td style="padding: 8px; border: 1px solid #ddd;">${item.item_code}</td>
                <td style="padding: 8px; border: 1px solid #ddd;">${item.item_name}</td>
                <td style="padding: 8px; border: 1px solid #ddd; text-align: center;">${item.quantity_ordered} ${item.unit_type || ''}</td>
                <td style="padding: 8px; border: 1px solid #ddd; text-align: right;">₱${Number(item.unit_price).toFixed(2)}</td>
                <td style="padding: 8px; border: 1px solid #ddd; text-align: right;">₱${Number(itemSubtotal).toFixed(2)}</td>
            </tr>`;
        });
        
        const tax = subtotal * 0.12;
        const total = order.total_amount || (subtotal + tax);
        
        return `
            <div style="font-family: Arial, sans-serif; max-width: 900px; margin: 0 auto; padding: 30px; background: white; border: 2px solid #333; border-radius: 8px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; padding-bottom: 20px; border-bottom: 2px solid #333;">
                    <div>
                        <h1 style="margin: 0; color: #0d6efd; font-size: 28px; font-weight: bold;">AMGC BRANCH SYSTEM</h1>
                        <p style="margin: 5px 0; color: #666;">Sales Order Document</p>
                    </div>
                    <div style="text-align: right;">
                        <h2 style="margin: 0; font-size: 24px; color: #333;">SALES ORDER</h2>
                        <p style="margin: 5px 0; font-size: 18px; font-weight: bold;">${order.so_number}</p>
                    </div>
                </div>
                
                <div style="display: flex; justify-content: space-between; margin-bottom: 30px;">
                    <div style="width: 48%;">
                        <div style="background: #f8f9fa; padding: 15px; border-radius: 5px;">
                            <h4 style="margin-top: 0; margin-bottom: 15px; color: #0d6efd;">From:</h4>
                            <p style="margin: 5px 0;"><strong>${order.branch_name || 'AMGC Branch'}</strong></p>
                            <p style="margin: 5px 0;">${order.branch_address || 'Branch Address'}</p>
                            <p style="margin: 5px 0;">Contact: ${order.branch_contact || 'N/A'}</p>
                        </div>
                    </div>
                    <div style="width: 48%;">
                        <div style="background: #f8f9fa; padding: 15px; border-radius: 5px;">
                            <h4 style="margin-top: 0; margin-bottom: 15px; color: #0d6efd;">Bill To:</h4>
                            <p style="margin: 5px 0;"><strong>${order.customer_name}</strong></p>
                            <p style="margin: 5px 0;">${order.address || 'Customer Address'}</p>
                            <p style="margin: 5px 0;">Contact: ${order.contact_number || 'N/A'}</p>
                        </div>
                    </div>
                </div>
                
                <div style="display: flex; justify-content: space-between; margin-bottom: 30px; background: #e7f1ff; padding: 15px; border-radius: 5px;">
                    <div>
                        <p style="margin: 5px 0;"><strong>Order Date:</strong> ${formattedDate}</p>
                        <p style="margin: 5px 0;"><strong>Order Status:</strong> ${getStatusText(order.order_status)}</p>
                    </div>
                    <div>
                        <p style="margin: 5px 0;"><strong>Total Items:</strong> ${order.total_items || 0}</p>
                        <p style="margin: 5px 0;"><strong>Total Quantity:</strong> ${order.total_quantity || 0}</p>
                    </div>
                </div>
                
                <h4 style="margin-bottom: 15px; color: #0d6efd;">Order Items</h4>
                <table style="width: 100%; border-collapse: collapse; margin-bottom: 30px;">
                    <thead>
                        <tr style="background: #0d6efd; color: white;">
                            <th style="padding: 12px; border: 1px solid #0d6efd;">#</th>
                            <th style="padding: 12px; border: 1px solid #0d6efd;">Item Code</th>
                            <th style="padding: 12px; border: 1px solid #0d6efd;">Description</th>
                            <th style="padding: 12px; border: 1px solid #0d6efd;">Quantity</th>
                            <th style="padding: 12px; border: 1px solid #0d6efd;">Unit Price</th>
                            <th style="padding: 12px; border: 1px solid #0d6efd;">Subtotal</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${itemsHtml}
                    </tbody>
                </table>
                
                <div style="display: flex; justify-content: flex-end; margin-bottom: 30px;">
                    <div style="width: 40%;">
                        <table style="width: 100%; border-collapse: collapse;">
                            <tr>
                                <td style="padding: 8px; border: 1px solid #ddd; background: #f8f9fa;"><strong>Subtotal:</strong></td>
                                <td style="padding: 8px; border: 1px solid #ddd; text-align: right;">₱${Number(subtotal).toFixed(2)}</td>
                            </tr>
                            <tr>
                                <td style="padding: 8px; border: 1px solid #ddd; background: #f8f9fa;"><strong>VAT (12%):</strong></td>
                                <td style="padding: 8px; border: 1px solid #ddd; text-align: right;">₱${Number(tax).toFixed(2)}</td>
                            </tr>
                            <tr>
                                <td style="padding: 8px; border: 1px solid #ddd; background: #0d6efd; color: white;"><strong>TOTAL:</strong></td>
                                <td style="padding: 8px; border: 1px solid #ddd; text-align: right; font-size: 18px; font-weight: bold;">₱${Number(total).toFixed(2)}</td>
                            </tr>
                        </table>
                    </div>
                </div>
                
                <div style="margin-top: 50px; border-top: 2px solid #333; padding-top: 20px; text-align: center;">
                    <div style="display: flex; justify-content: space-between;">
                        <div style="width: 45%;">
                            <p style="border-top: 1px solid #333; padding-top: 10px; margin-top: 30px;"><strong>Prepared by:</strong></p>
                            <p>${order.branch_name || 'Branch Admin'}</p>
                        </div>
                        <div style="width: 45%;">
                            <p style="border-top: 1px solid #333; padding-top: 10px; margin-top: 30px;"><strong>Received by:</strong></p>
                            <p>_________________________</p>
                        </div>
                    </div>
                    <p style="margin-top: 30px; color: #666; font-size: 12px;">Generated on: ${new Date().toLocaleString()}</p>
                </div>
            </div>
        `;
    }

    function executePrint() {
        const printContent = document.getElementById('printPreviewContent').innerHTML;
        const printWindow = window.open('', '_blank', 'width=900,height=700');
        
        printWindow.document.write(`
            <!DOCTYPE html>
            <html>
            <head>
                <title>Sales Order</title>
                <style>
                    @page { size: A4; margin: 0.5in; }
                    body { font-family: Arial, sans-serif; margin: 0; padding: 20px; background: white; }
                    @media print { body { padding: 0; } }
                </style>
            </head>
            <body>${printContent}</body>
            </html>
        `);
        
        printWindow.document.close();
        setTimeout(() => { printWindow.print(); }, 250);
        bootstrap.Modal.getInstance(document.getElementById('printPreviewModal')).hide();
    }

    // ========== FILTER FUNCTIONS ==========
    function filterTable() {
        const searchTerm = document.getElementById('searchInput').value.toLowerCase();
        const statusFilter = document.getElementById('statusFilter').value;
        const customerFilter = document.getElementById('customerFilter').value;
        
        document.querySelectorAll('.sales-order-row').forEach(row => {
            const orderNumber = row.dataset.orderNumber?.toLowerCase() || '';
            const customer = row.dataset.customer?.toLowerCase() || '';
            const status = row.dataset.status || '';
            
            const matchesSearch = searchTerm === '' || orderNumber.includes(searchTerm) || customer.includes(searchTerm);
            const matchesStatus = statusFilter === '' || status === statusFilter;
            const matchesCustomer = customerFilter === '' || row.dataset.customer === customerFilter;
            
            row.style.display = matchesSearch && matchesStatus && matchesCustomer ? '' : 'none';
        });
    }

    // ========== UTILITY FUNCTIONS ==========
    function formatDate(dateStr) {
        if (!dateStr) return '';
        return new Date(dateStr).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
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

    // ========== EXPORT FUNCTIONS ==========
    function printReport() {
        document.getElementById('dashboardContent').setAttribute('data-print-date', new Date().toLocaleString());
        document.title = 'Sales Orders Report - ' + new Date().toLocaleDateString();
        window.print();
        document.title = 'Sales Orders - Branch Admin';
    }

    function exportToExcel() {
        const rows = document.querySelectorAll('.sales-order-row:not([style*="display: none"])');
        if (rows.length === 0) {
            Swal.fire('Warning', 'No orders to export', 'warning');
            return;
        }
        
        const excelData = [];
        const headers = ['Order Number', 'Order Date', 'Customer Name', 'Items', 'Qty', 'Total Amount (₱)', 'Invoice Number', 'Payment Status', 'Order Status'];
        excelData.push(headers);

        rows.forEach(row => {
            if (row.style.display !== 'none') {
                const cells = row.querySelectorAll('td');
                let cellIndex = 0;
                
                const orderNo = cells[cellIndex++]?.innerText || '';
                const date = cells[cellIndex++]?.innerText || '';
                const customer = cells[cellIndex++]?.innerText || '';
                
                if (soBranchColumnExists && viewAllBranches) cellIndex++;
                
                const items = cells[cellIndex++]?.innerText || '0';
                const qty = cells[cellIndex++]?.innerText || '0';
                const amount = cells[cellIndex++]?.innerText.replace('₱', '').replace(/,/g, '') || '0';
                const invoice = cells[cellIndex++]?.innerText || 'No Invoice';
                const payment = cells[cellIndex++]?.innerText || 'Pending';
                const orderStatus = cells[cellIndex]?.innerText || '';
                
                excelData.push([orderNo, date, customer, items, qty, amount, invoice, payment, orderStatus]);
            }
        });

        const wb = XLSX.utils.book_new();
        const ws = XLSX.utils.aoa_to_sheet(excelData);
        ws['!cols'] = [{ wch: 18 }, { wch: 15 }, { wch: 30 }, { wch: 10 }, { wch: 10 }, { wch: 15 }, { wch: 15 }, { wch: 15 }, { wch: 15 }];
        XLSX.utils.book_append_sheet(wb, ws, 'Sales Orders');
        XLSX.writeFile(wb, `Sales_Orders_${new Date().toISOString().slice(0,10).replace(/-/g, '')}.xlsx`);
        
        Swal.fire({ icon: 'success', title: 'Export Complete', timer: 2000, showConfirmButton: false });
    }

    // ========== COPY SQL FUNCTION ==========
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
        }
        navigator.clipboard.writeText(sql).then(() => {
            Swal.fire({ icon: 'success', title: 'Copied!', timer: 1500, showConfirmButton: false });
        });
    }

    // ========== LOGOUT ==========
    function logout() {
        Swal.fire({
            title: 'Are you sure?',
            text: 'You will be logged out of the system',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#0d6efd',
            confirmButtonText: 'Yes, logout'
        }).then((result) => {
            if (result.isConfirmed) {
                localStorage.removeItem('sidebarCollapsed');
                window.location.href = '../logout.php';
            }
        });
    }
    </script>
</body>
</html>