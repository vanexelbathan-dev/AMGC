<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Log errors to file
ini_set('log_errors', 1);
ini_set('error_log', '../logs/php_errors.log');

require_once '../config/database.php';
require_once '../config/session_handler.php';

// Protect page - only Sales role can access
requireLogin();
requireRole(['sales']);

// Get current user info and branch context
$user_id = $_SESSION['user_id'] ?? 0;
$user_name = isset($_SESSION['first_name']) ? $_SESSION['first_name'] . ' ' . $_SESSION['last_name'] : 'Sales User';
$user_role = isset($_SESSION['role']) ? $_SESSION['role'] : 'sales';
$branch_id = $_SESSION['branch_id'] ?? 0;
$view_all_branches = $_SESSION['view_all_branches'] ?? false;

// Check database connection
if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error());
}

// Check if branch_id column exists in customers table
$branch_column_exists = false;
$check_column = $conn->query("SHOW COLUMNS FROM customers LIKE 'branch_id'");
if ($check_column && $check_column->num_rows > 0) {
    $branch_column_exists = true;
}

// Check if branch_id column exists in items table
$items_branch_column_exists = false;
$check_items_column = $conn->query("SHOW COLUMNS FROM items LIKE 'branch_id'");
if ($check_items_column && $check_items_column->num_rows > 0) {
    $items_branch_column_exists = true;
}

// Get all items with available stock - filter by branch if not admin AND if branch_id column exists
$items = [];

if ($items_branch_column_exists) {
    // Branch column exists - apply filtering
    if ($view_all_branches) {
        // Admin sees all branches
        $items_query = "SELECT i.item_id, i.item_code, i.item_name, i.description, i.category, 
                       i.stock, i.unit_type, i.unit_price, i.price_case, i.price_inner_pack, 
                       i.price_box, i.price_carton, i.reorder_level, i.status,
                       i.product_image_url,
                       b.branch_name
                       FROM items i
                       LEFT JOIN branches b ON i.branch_id = b.branch_id
                       WHERE i.status = 'active'
                       ORDER BY i.item_code ASC";
    } else {
        // Regular user sees only their branch
        $items_query = "SELECT i.item_id, i.item_code, i.item_name, i.description, i.category, 
                       i.stock, i.unit_type, i.unit_price, i.price_case, i.price_inner_pack, 
                       i.price_box, i.price_carton, i.reorder_level, i.status,
                       i.product_image_url,
                       b.branch_name
                       FROM items i
                       LEFT JOIN branches b ON i.branch_id = b.branch_id
                       WHERE i.status = 'active' AND i.branch_id = $branch_id
                       ORDER BY i.item_code ASC";
    }
} else {
    // Branch column doesn't exist - show all items
    $items_query = "SELECT i.item_id, i.item_code, i.item_name, i.description, i.category, 
                   i.stock, i.unit_type, i.unit_price, i.price_case, i.price_inner_pack, 
                   i.price_box, i.price_carton, i.reorder_level, i.status,
                   i.product_image_url
                   FROM items i
                   WHERE i.status = 'active'
                   ORDER BY i.item_code ASC";
}

$items_result = $conn->query($items_query);
if ($items_result) {
    $items = $items_result->fetch_all(MYSQLI_ASSOC);
} else {
    error_log("Items query error: " . $conn->error);
}

// Get all customers - filter by branch if not admin AND if branch_id column exists
$customers = [];

if ($branch_column_exists) {
    // Branch column exists - apply filtering
    if ($view_all_branches) {
        // Admin sees all customers
        $customers_query = "SELECT c.customer_id, c.customer_name, c.email, c.phone_number, c.address, c.city,
                           b.branch_name
                           FROM customers c
                           LEFT JOIN branches b ON c.branch_id = b.branch_id
                           WHERE c.status = 'active'
                           ORDER BY c.customer_name ASC";
    } else {
        // Regular user sees only their branch
        $customers_query = "SELECT c.customer_id, c.customer_name, c.email, c.phone_number, c.address, c.city
                           FROM customers c
                           WHERE c.status = 'active' AND c.branch_id = $branch_id
                           ORDER BY c.customer_name ASC";
    }
} else {
    // Branch column doesn't exist - show all customers
    $customers_query = "SELECT customer_id, customer_name, email, phone_number, address, city
                       FROM customers
                       WHERE status = 'active'
                       ORDER BY customer_name ASC";
}

$customers_result = $conn->query($customers_query);
if ($customers_result) {
    $customers = $customers_result->fetch_all(MYSQLI_ASSOC);
} else {
    error_log("Customers query error: " . $conn->error);
}

// Handle order submission via AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_order') {
    header('Content-Type: application/json');
    
    try {
        if (!$conn) {
            throw new Exception("Database connection failed");
        }
        
        $conn->begin_transaction();
        
        // Log incoming data for debugging
        error_log("========== ORDER SUBMISSION STARTED ==========");
        error_log("POST data: " . print_r($_POST, true));
        
        $customer_id = isset($_POST['customer_id']) ? (int)$_POST['customer_id'] : 0;
        $customer_name = isset($_POST['customer_name']) ? trim($_POST['customer_name']) : '';
        $email = isset($_POST['email']) ? trim($_POST['email']) : '';
        $phone = isset($_POST['phone']) ? trim($_POST['phone']) : '';
        $address = isset($_POST['address']) ? trim($_POST['address']) : '';
        $items_data = isset($_POST['items']) ? json_decode($_POST['items'], true) : [];
        
        error_log("Customer ID: $customer_id, Customer Name: $customer_name");
        error_log("Items data count: " . count($items_data));
        error_log("Items data: " . print_r($items_data, true));
        
        if (empty($items_data)) {
            throw new Exception("No items in cart");
        }
        
        // Get user ID and branch ID from session
        $user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
        $branch_id = isset($_SESSION['branch_id']) ? (int)$_SESSION['branch_id'] : 0;
        $view_all_branches = isset($_SESSION['view_all_branches']) ? $_SESSION['view_all_branches'] : false;
        
        error_log("User ID from session: $user_id, Branch ID: $branch_id");
        
        if ($user_id === 0) {
            throw new Exception("User session invalid. Please log in again.");
        }
        
        // If customer_id is 0 and customer_name is provided, create new customer
        if ($customer_id === 0 && !empty($customer_name)) {
            error_log("Creating/updating customer: $customer_name");
            
            // Check if customer already exists with this name for this branch
            if ($branch_column_exists && !$view_all_branches) {
                $check_sql = "SELECT customer_id FROM customers WHERE customer_name = ? AND branch_id = ? AND status = 'active'";
                $check_stmt = $conn->prepare($check_sql);
                if (!$check_stmt) {
                    throw new Exception("Prepare failed: " . $conn->error);
                }
                $check_stmt->bind_param('si', $customer_name, $branch_id);
            } else {
                $check_sql = "SELECT customer_id FROM customers WHERE customer_name = ? AND status = 'active'";
                $check_stmt = $conn->prepare($check_sql);
                if (!$check_stmt) {
                    throw new Exception("Prepare failed: " . $conn->error);
                }
                $check_stmt->bind_param('s', $customer_name);
            }
            
            if (!$check_stmt->execute()) {
                throw new Exception("Execute failed: " . $check_stmt->error);
            }
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows > 0) {
                $existing_customer = $check_result->fetch_assoc();
                $customer_id = $existing_customer['customer_id'];
                
                // Update existing customer info
                $update_sql = "UPDATE customers SET email = ?, phone_number = ?, address = ? WHERE customer_id = ?";
                $update_stmt = $conn->prepare($update_sql);
                if (!$update_stmt) {
                    throw new Exception("Prepare failed: " . $conn->error);
                }
                $update_stmt->bind_param('sssi', $email, $phone, $address, $customer_id);
                if (!$update_stmt->execute()) {
                    throw new Exception("Failed to update customer: " . $update_stmt->error);
                }
                error_log("Updated existing customer ID: $customer_id");
            } else {
                // Create new customer - generate customer code
                $customer_code = 'CUST-' . date('Ymd') . '-' . str_pad(rand(1000, 9999), 4, '0', STR_PAD_LEFT);
                
                if ($branch_column_exists && !$view_all_branches) {
                    $sql_new_cust = "INSERT INTO customers (customer_name, customer_code, email, phone_number, address, status, branch_id) 
                                   VALUES (?, ?, ?, ?, ?, 'active', ?)";
                    $stmt_new_cust = $conn->prepare($sql_new_cust);
                    if (!$stmt_new_cust) {
                        throw new Exception("Database error preparing new customer: " . $conn->error);
                    }
                    $stmt_new_cust->bind_param('sssssi', $customer_name, $customer_code, $email, $phone, $address, $branch_id);
                } else {
                    $sql_new_cust = "INSERT INTO customers (customer_name, customer_code, email, phone_number, address, status) 
                                   VALUES (?, ?, ?, ?, ?, 'active')";
                    $stmt_new_cust = $conn->prepare($sql_new_cust);
                    if (!$stmt_new_cust) {
                        throw new Exception("Database error preparing new customer: " . $conn->error);
                    }
                    $stmt_new_cust->bind_param('sssss', $customer_name, $customer_code, $email, $phone, $address);
                }
                
                if (!$stmt_new_cust->execute()) {
                    throw new Exception("Failed to create new customer: " . $stmt_new_cust->error);
                }
                $customer_id = $stmt_new_cust->insert_id;
                error_log("Created new customer ID: $customer_id for branch ID: $branch_id");
            }
        }
        
        if ($customer_id === 0) {
            throw new Exception("Customer is required");
        }
        
        error_log("Using customer ID: $customer_id");
        
        // Validate stock availability before processing
        foreach ($items_data as $item) {
            $item_id = (int)$item['id'];
            $quantity = (int)$item['quantity'];
            $unit_type = isset($item['unit_type']) ? $item['unit_type'] : 'piece';
            
            // Fixed conversion rates (pieces per unit type)
            $pieces_multiplier = 1; // default for piece
            if ($unit_type === 'case') {
                $pieces_multiplier = 12;
            } elseif ($unit_type === 'inner-pack') {
                $pieces_multiplier = 6;
            } elseif ($unit_type === 'box') {
                $pieces_multiplier = 24;
            } elseif ($unit_type === 'carton') {
                $pieces_multiplier = 48;
            }
            
            // Check current stock
            if ($items_branch_column_exists && !$view_all_branches) {
                $stock_check = $conn->query("SELECT COALESCE(stock, 0) as stock FROM items WHERE item_id = $item_id AND branch_id = $branch_id");
            } else {
                $stock_check = $conn->query("SELECT COALESCE(stock, 0) as stock FROM items WHERE item_id = $item_id");
            }
            
            if (!$stock_check) {
                throw new Exception("Error checking stock: " . $conn->error);
            }
            
            $stock_row = $stock_check->fetch_assoc();
            $current_stock = $stock_row ? (int)$stock_row['stock'] : 0;
            
            $pieces_needed = $quantity * $pieces_multiplier;
            
            if ($pieces_needed > $current_stock) {
                $item_name = isset($item['name']) ? $item['name'] : "Item ID: $item_id";
                throw new Exception("Insufficient stock for $item_name (Unit: $unit_type). Available: $current_stock pieces, Requested: $pieces_needed pieces");
            }
        }
        
        // Create sales order
        $total_amount = 0;
        foreach ($items_data as $item) {
            $total_amount += $item['price'] * $item['quantity'];
        }
        
        $so_number = 'SO-' . date('Ymd') . '-' . substr(time(), -4) . rand(100, 999);
        $order_date = date('Y-m-d H:i:s');

        error_log("Creating sales order: SO Number: $so_number, User ID: $user_id, Branch ID: $branch_id, Customer ID: $customer_id, Total: $total_amount");

        // First, check if sales_orders table exists and get its structure
        $table_check = $conn->query("SHOW TABLES LIKE 'sales_orders'");
        if ($table_check->num_rows == 0) {
            throw new Exception("sales_orders table does not exist");
        }
        
        // Get the columns in sales_orders table
        $columns_check = $conn->query("SHOW COLUMNS FROM sales_orders");
        $columns = [];
        while ($col = $columns_check->fetch_assoc()) {
            $columns[] = $col['Field'];
        }
        error_log("sales_orders columns: " . print_r($columns, true));
        
        // Check if all required columns exist
        $required_columns = ['so_number', 'customer_id', 'branch_id', 'order_date', 'total_amount', 'order_status', 'created_by'];
        $missing_columns = array_diff($required_columns, $columns);
        if (!empty($missing_columns)) {
            throw new Exception("Missing columns in sales_orders table: " . implode(', ', $missing_columns));
        }
        
        $sql = "INSERT INTO sales_orders (so_number, customer_id, branch_id, order_date, total_amount, order_status, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception("Prepare failed for sales_orders: " . $conn->error);
        }
        
        $status = 'pending';
        $stmt->bind_param('siisdss', $so_number, $customer_id, $branch_id, $order_date, $total_amount, $status, $user_id);

        if (!$stmt->execute()) {
            throw new Exception("Error creating order: " . $stmt->error);
        }
        
        $so_id = $stmt->insert_id;
        error_log("Sales order created with ID: $so_id");
        
        // Check if sales_order_items table exists
        $table_check = $conn->query("SHOW TABLES LIKE 'sales_order_items'");
        if ($table_check->num_rows == 0) {
            throw new Exception("sales_order_items table does not exist");
        }
        
        // Get the columns in sales_order_items table
        $columns_check = $conn->query("SHOW COLUMNS FROM sales_order_items");
        $columns = [];
        while ($col = $columns_check->fetch_assoc()) {
            $columns[] = $col['Field'];
        }
        error_log("sales_order_items columns: " . print_r($columns, true));
        
        // Check if unit_type column exists
        if (!in_array('unit_type', $columns)) {
            // Add the unit_type column if it doesn't exist
            $alter_sql = "ALTER TABLE sales_order_items ADD COLUMN unit_type VARCHAR(50) DEFAULT 'piece' AFTER item_id";
            if (!$conn->query($alter_sql)) {
                throw new Exception("Failed to add unit_type column: " . $conn->error);
            }
            error_log("Added unit_type column to sales_order_items table");
        }
        
        // Insert order items and deduct inventory
        $sql_items = "INSERT INTO sales_order_items (so_id, item_id, unit_type, quantity_ordered, unit_price)
                     VALUES (?, ?, ?, ?, ?)";
        $stmt_items = $conn->prepare($sql_items);
        if (!$stmt_items) {
            throw new Exception("Prepare failed for order items: " . $conn->error);
        }
        
        $updated_stock_data = [];
        
        foreach ($items_data as $item) {
            $item_id = (int)$item['id'];
            $quantity = (int)$item['quantity'];
            $unit_price = (float)$item['price'];
            $unit_type = isset($item['unit_type']) ? $item['unit_type'] : 'piece';
            
            // Fixed conversion rates
            $pieces_multiplier = 1; // default for piece
            if ($unit_type === 'case') {
                $pieces_multiplier = 12;
            } elseif ($unit_type === 'inner-pack') {
                $pieces_multiplier = 6;
            } elseif ($unit_type === 'box') {
                $pieces_multiplier = 24;
            } elseif ($unit_type === 'carton') {
                $pieces_multiplier = 48;
            }
            
            // Calculate total pieces to deduct
            $pieces_to_deduct = $quantity * $pieces_multiplier;
            
            $stmt_items->bind_param('iisid', $so_id, $item_id, $unit_type, $quantity, $unit_price);
            if (!$stmt_items->execute()) {
                throw new Exception("Error adding order item: " . $stmt_items->error);
            }
            
            error_log("Added order item: Item ID: $item_id, Unit Type: $unit_type, Qty: $quantity, Multiplier: $pieces_multiplier, Pieces to deduct: $pieces_to_deduct, Price: $unit_price");
            
            // Deduct inventory from items table stock column with branch filter
            if ($items_branch_column_exists && !$view_all_branches) {
                $sql_deduct = "UPDATE items 
                              SET stock = COALESCE(stock, 0) - ? 
                              WHERE item_id = ? AND branch_id = ? 
                              AND COALESCE(stock, 0) >= ?";
                $stmt_deduct = $conn->prepare($sql_deduct);
                if (!$stmt_deduct) {
                    throw new Exception("Prepare failed for stock update: " . $conn->error);
                }
                $stmt_deduct->bind_param('iiii', $pieces_to_deduct, $item_id, $branch_id, $pieces_to_deduct);
            } else {
                $sql_deduct = "UPDATE items 
                              SET stock = COALESCE(stock, 0) - ? 
                              WHERE item_id = ? 
                              AND COALESCE(stock, 0) >= ?";
                $stmt_deduct = $conn->prepare($sql_deduct);
                if (!$stmt_deduct) {
                    throw new Exception("Prepare failed for stock update: " . $conn->error);
                }
                $stmt_deduct->bind_param('iii', $pieces_to_deduct, $item_id, $pieces_to_deduct);
            }
            
            if (!$stmt_deduct->execute()) {
                throw new Exception("Error updating inventory: " . $stmt_deduct->error);
            }
            
            if ($stmt_deduct->affected_rows === 0) {
                throw new Exception("Failed to deduct inventory for item ID: $item_id. Stock may have changed.");
            }
            
            // Get updated stock for this item
            if ($items_branch_column_exists && !$view_all_branches) {
                $stock_query = "SELECT COALESCE(stock, 0) as stock FROM items WHERE item_id = ? AND branch_id = ?";
                $stock_stmt = $conn->prepare($stock_query);
                $stock_stmt->bind_param('ii', $item_id, $branch_id);
            } else {
                $stock_query = "SELECT COALESCE(stock, 0) as stock FROM items WHERE item_id = ?";
                $stock_stmt = $conn->prepare($stock_query);
                $stock_stmt->bind_param('i', $item_id);
            }
            $stock_stmt->execute();
            $stock_result = $stock_stmt->get_result();
            $stock_row = $stock_result->fetch_assoc();
            
            $updated_stock_data[] = [
                'item_id' => $item_id,
                'stock' => (int)$stock_row['stock']
            ];
            
            error_log("Updated item stock: Item ID: $item_id, Deducted: $pieces_to_deduct pieces, New Stock: " . $stock_row['stock']);
        }
        
        $conn->commit();
        error_log("Order submitted successfully! SO Number: $so_number");
        error_log("========== ORDER SUBMISSION COMPLETED ==========");
        
        echo json_encode([
            'success' => true, 
            'message' => 'Order submitted successfully!', 
            'so_number' => $so_number,
            'so_id' => $so_id,
            'updated_stock' => $updated_stock_data
        ]);
        exit;
        
    } catch (Exception $e) {
        $conn->rollback();
        error_log("========== ORDER SUBMISSION ERROR ==========");
        error_log("Order submission error: " . $e->getMessage());
        error_log("Error trace: " . $e->getTraceAsString());
        
        echo json_encode([
            'success' => false, 
            'message' => $e->getMessage()
        ]);
        exit;
    }
}

// Handle cancel order via AJAX (to restore stock)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel_order') {
    header('Content-Type: application/json');
    
    try {
        $order_id = isset($_POST['order_id']) ? (int)$_POST['order_id'] : 0;
        
        if ($order_id <= 0) {
            throw new Exception("Invalid order ID");
        }
        
        $conn->begin_transaction();
        
        // Verify order belongs to user's branch (if branch column exists and not admin)
        if ($items_branch_column_exists && !$view_all_branches) {
            $check_sql = "SELECT so_id FROM sales_orders WHERE so_id = ? AND branch_id = ?";
            $check_stmt = $conn->prepare($check_sql);
            $check_stmt->bind_param('ii', $order_id, $branch_id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows === 0) {
                throw new Exception("Order not found or access denied");
            }
        }
        
        // Get order items to restore stock
        $items_sql = "SELECT soi.item_id, soi.quantity_ordered, soi.unit_type
                     FROM sales_order_items soi 
                     WHERE soi.so_id = ?";
        $items_stmt = $conn->prepare($items_sql);
        $items_stmt->bind_param('i', $order_id);
        $items_stmt->execute();
        $items_result = $items_stmt->get_result();
        $order_items = $items_result->fetch_all(MYSQLI_ASSOC);
        
        // Restore stock for each item
        $restored_stock_data = [];
        
        foreach ($order_items as $item) {
            $item_id = (int)$item['item_id'];
            $quantity = (int)$item['quantity_ordered'];
            $unit_type = $item['unit_type'];
            
            // Fixed conversion rates
            $pieces_multiplier = 1; // default for piece
            if ($unit_type === 'case') {
                $pieces_multiplier = 12;
            } elseif ($unit_type === 'inner-pack') {
                $pieces_multiplier = 6;
            } elseif ($unit_type === 'box') {
                $pieces_multiplier = 24;
            } elseif ($unit_type === 'carton') {
                $pieces_multiplier = 48;
            }
            
            // Calculate total pieces to restore
            $pieces_to_restore = $quantity * $pieces_multiplier;
            
            // Restore stock
            if ($items_branch_column_exists && !$view_all_branches) {
                $sql_restore = "UPDATE items 
                               SET stock = COALESCE(stock, 0) + ? 
                               WHERE item_id = ? AND branch_id = ?";
                $restore_stmt = $conn->prepare($sql_restore);
                $restore_stmt->bind_param('iii', $pieces_to_restore, $item_id, $branch_id);
            } else {
                $sql_restore = "UPDATE items 
                               SET stock = COALESCE(stock, 0) + ? 
                               WHERE item_id = ?";
                $restore_stmt = $conn->prepare($sql_restore);
                $restore_stmt->bind_param('ii', $pieces_to_restore, $item_id);
            }
            
            if (!$restore_stmt->execute()) {
                throw new Exception("Error restoring stock for item ID: $item_id");
            }
            
            // Get updated stock
            if ($items_branch_column_exists && !$view_all_branches) {
                $stock_query = "SELECT COALESCE(stock, 0) as stock FROM items WHERE item_id = ? AND branch_id = ?";
                $stock_stmt = $conn->prepare($stock_query);
                $stock_stmt->bind_param('ii', $item_id, $branch_id);
            } else {
                $stock_query = "SELECT COALESCE(stock, 0) as stock FROM items WHERE item_id = ?";
                $stock_stmt = $conn->prepare($stock_query);
                $stock_stmt->bind_param('i', $item_id);
            }
            $stock_stmt->execute();
            $stock_result = $stock_stmt->get_result();
            $stock_row = $stock_result->fetch_assoc();
            
            $restored_stock_data[] = [
                'item_id' => $item_id,
                'stock' => (int)$stock_row['stock']
            ];
        }
        
        // Update order status to cancelled
        $update_sql = "UPDATE sales_orders SET order_status = 'cancelled' WHERE so_id = ?";
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param('i', $order_id);
        
        if (!$update_stmt->execute()) {
            throw new Exception("Error updating order status");
        }
        
        $conn->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Order cancelled and stock restored successfully',
            'restored_stock' => $restored_stock_data
        ]);
        exit;
        
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
        exit;
    }
}
?>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Product - Sales</title>
    <link rel="icon" type="image/png" href="../Pictures/favicon-96x96.png" sizes="96x96" />
    <link rel="icon" type="image/svg+xml" href="../Pictures/favicon.svg" />
    <link rel="shortcut icon" href="../Pictures/favicon.ico" />
    <link rel="apple-touch-icon" sizes="180x180" href="../Pictures/apple-touch-icon.png" />
    <link rel="manifest" href="../Pictures/site.webmanifest" />
    <link rel="stylesheet" href="../css/sales.css">
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <style>
        :root {
            --primary-green: #2E7D32;
            --dark-green: #1B5E20;
            --deep-sea: #0D4C14;
            --forest-green: #1B4D1F;
            --warning-yellow: #FFC107;
            --light-gray: #F5F5F5;
            --white: #FFFFFF;
            --black: #212121;
        }

        .cart-item {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-left: 4px solid var(--primary-green);
        }
        
        .order-summary {
            background: linear-gradient(135deg, var(--dark-green), var(--primary-green));
            color: white;
            padding: 20px;
            border-radius: 8px;
            position: sticky;
            top: 20px;
        }
        
        /* Cart Icon Button in Header */
        .navbar-top .btn-success {
            padding: 8px 14px;
            border-radius: 8px;
            font-size: 18px;
            transition: all 0.3s ease;
            flex-shrink: 0;
        }
        
        .navbar-top .btn-success:hover {
            transform: scale(1.1);
            box-shadow: 0 4px 12px rgba(46, 125, 50, 0.3);
        }
        
        .navbar-top .btn-success .badge {
            font-size: 11px;
            padding: 2px 5px;
            top: -5px;
            right: -8px;
        }
        
        /* Customer Information Card */
        .customer-card {
            border: 1px solid #e0e0e0;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
        }
        
        .customer-card .card-header {
            background: linear-gradient(135deg, #2e7d32 0%, #1b5e20 100%);
            color: white;
            border: none;
            padding: 18px 20px;
        }
        
        .customer-card .card-header h5 {
            font-weight: 600;
            font-size: 16px;
            margin: 0;
        }
        
        .customer-card .card-header i {
            margin-right: 8px;
            font-size: 18px;
        }
        
        .customer-card .card-body {
            padding: 24px;
            background: #fafafa;
        }
        
        /* Customer Form Styling */
        .customer-form {
            display: flex;
            flex-direction: column;
            gap: 18px;
        }
        
        .form-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }
        
        @media (max-width: 768px) {
            .form-row {
                grid-template-columns: 1fr;
                gap: 12px;
            }
        }
        
        .customer-form .form-label {
            font-size: 13px;
            font-weight: 600;
            color: #333;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .customer-form .form-label i {
            margin-right: 6px;
            color: #2e7d32;
        }
        
        .customer-form .form-select,
        .customer-form .form-control {
            padding: 10px 12px;
            border: 1px solid #d0d0d0;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s ease;
            background-color: white;
        }
        
        .customer-form .form-select:focus,
        .customer-form .form-control:focus {
            border-color: #2e7d32;
            box-shadow: 0 0 0 3px rgba(46, 125, 50, 0.1);
            outline: none;
        }
        
        .customer-form textarea.form-control {
            resize: vertical;
            min-height: 100px;
            font-family: inherit;
        }
        
        .error-message {
            color: #f87171;
            font-size: 0.875rem;
            margin-top: 5px;
            min-height: 18px;
        }
        
        /* Custom quantity input with plus/minus buttons */
        .quantity-control {
            display: flex;
            align-items: center;
            width: 100% 
        }
        
        .quantity-control button {
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 1px solid #dee2e6;
            background: white;
            font-size: 18px;
            font-weight: bold;
            cursor: pointer;
        }
        
        .quantity-control button:hover {
            background: #f8f9fa;
        }
        
        .quantity-control button:active {
            background: #e9ecef;
        }
        
        .quantity-control button:disabled {
            background: #e9ecef;
            color: #adb5bd;
            cursor: not-allowed;
        }
        
        .quantity-control .decrease-btn {
            border-radius: 6px 0 0 6px;
            color: #dc3545;
            border-right: none;
        }
        
        .quantity-control .increase-btn {
            border-radius: 0 6px 6px 0;
            color: #28a745;
            border-left: none;
        }
        
        .quantity-control input {
            width: 60px;
            height: 38px;
            text-align: center;
            border: 1px solid #dee2e6;
            font-size: 16px;
            -moz-appearance: textfield;
        }
        
        /* Hide number input arrows */
        .quantity-control input::-webkit-outer-spin-button,
        .quantity-control input::-webkit-inner-spin-button {
            -webkit-appearance: none;
            margin: 0;
        }
        
        .quantity-control input:focus {
            outline: none;
        }
        
        .quantity-control input:disabled {
            background: #e9ecef;
            cursor: not-allowed;
        }
        
        /* Add to Cart Button */
        .btn-add-to-cart {
            height: 38px;
            font-size: 14px;
            padding: 6px 12px;
            border-radius: 6px;
            background: #28a745;
            color: white;
            border: none !important;
            font-weight: 500;
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
            margin-top: 12px;
            cursor: pointer;
        }
        
        .btn-add-to-cart:hover {
            background: #218838;
        }
        
        .btn-add-to-cart:active {
            background: #1e7e34;
        }
        
        .btn-add-to-cart:disabled {
            background: #6c757d;
            color: white;
            cursor: not-allowed;
        }
        
        .btn-add-to-cart i {
            font-size: 16px;
        }
        
        /* Stock warning */
        .stock-warning {
            color: #ff6b6b;
            font-size: 12px;
            font-weight: 500;
            margin-bottom: 5px;
        }
        
        /* Toast notification */
        .toast-notification {
            position: fixed;
            top: 20px;
            right: 20px;
            background: var(--primary-green);
            color: white;
            padding: 12px 20px;
            border-radius: 8px;
            z-index: 9999;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            animation: slideIn 0.3s ease-out;
        }
        
        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        
        @keyframes slideOut {
            from { transform: translateX(0); opacity: 1; }
            to { transform: translateX(100%); opacity: 0; }
        }
        
        /* Success Modal Styles */
        .success-icon {
            font-size: 4rem;
            color: #28a745;
            margin-bottom: 20px;
        }
        
        .order-details {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            margin: 15px 0;
        }
        
        .order-details h6 {
            color: #495057;
            margin-bottom: 10px;
        }
        
        /* Branch Badge */
        .branch-badge {
            background-color: #e7f1ff;
            color: #0d6efd;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
            margin-left: 5px;
        }
        
        /* Navbar Top - Header Section */
        .navbar-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .page-title {
            flex: 1;
        }
        
        .page-title h2 {
            margin: 0;
            font-size: 28px;
            font-weight: 600;
            color: #333;
        }
        
        .page-title p {
            margin: 5px 0 0 0;
            color: #666;
            font-size: 14px;
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

        /* ===== FIXED PRODUCT CONTAINER WIDTH - ONLY THIS SECTION CHANGED ===== */
        .card-body.p-4 {
            padding: 1.5rem !important;
            width: 100%;
        }

        /* Products Container - Full width responsive grid within card bounds */
        #productsContainer {
            display: grid !important;
            gap: 24px !important;
            width: 100% !important;
            margin: 0 !important;
            padding: 0 !important;
        }

        /* Desktop - 4 columns for larger cards */
        @media (min-width: 1200px) {
            #productsContainer {
                grid-template-columns: repeat(4, 1fr) !important;
            }
        }

        /* Desktop - 3 columns for medium */
        @media (min-width: 992px) and (max-width: 1199px) {
            #productsContainer {
                grid-template-columns: repeat(3, 1fr) !important;
            }
        }

        /* Tablet - 2 columns */
        @media (min-width: 768px) and (max-width: 991px) {
            #productsContainer {
                grid-template-columns: repeat(2, 1fr) !important;
            }
        }

        /* Mobile - 2 columns */
        @media (max-width: 767px) {
            #productsContainer {
                grid-template-columns: repeat(2, 1fr) !important;
                gap: 16px !important;
            }
        }

        /* Very small mobile - 1 column */
        @media (max-width: 480px) {
            #productsContainer {
                grid-template-columns: 1fr !important;
            }
        }

        /* Product card styling */
        .product-card-mobile {
            width: 100% !important;
            height: 100% !important;
            display: flex !important;
        }

        .product-card-mobile .card {
            width: 100% !important;
            height: 100% !important;
            display: flex;
            flex-direction: column;
            border: 1px solid #e0e0e0;
            border-radius: 12px;
            overflow: hidden;
            transition: all 0.3s ease;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
      
        }

        .product-card-mobile .card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 16px rgba(0, 0, 0, 0.1);
        }

        .product-card-mobile .card-body {
            flex: 1;
            display: flex;
            flex-direction: column;
            padding: 20px;
            background: white;
           
        }

        /* Product image container */
        .product-image-container {
            position: relative;
            width: 100%;
            aspect-ratio: 1/1;
            background: linear-gradient(135deg, #f5f5f5 0%, #e8e8e8 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }

        .product-image {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.4s ease;
        }

        .product-card-mobile .card:hover .product-image {
            transform: scale(1.08);
        }

        /* Out of stock overlay */
        .out-of-stock-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: rgba(0, 0, 0, 0.65);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 18px;
            z-index: 10;
            backdrop-filter: blur(2px);
        }

        /* Product card typography */
        .product-card-mobile .card-title {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 4px;
        }
        
        .product-card-mobile .badge {
            font-size: 0.75rem;
            padding: 4px 8px;
        }
        
        .product-stock-mobile {
            font-size: 0.9rem;
            margin: 8px 0;
        }
        
        .product-price-mobile {
            font-size: 1.3rem;
            font-weight: 700;
            margin: 8px 0 12px 0;
        }
        
        .product-input-group-mobile .form-label {
            font-size: 0.85rem;
            margin-bottom: 4px;
        }
        
        .product-input-group-mobile .form-select {
            font-size: 0.9rem;
            padding: 8px;
            height: 38px;
            margin-top: 4px;
        }
        
        .error-message {
            font-size: 0.8rem;
            margin-top: 6px;
        }
        .product-input-group-mobile .form-label {
    font-size: 0.85rem;
    margin-bottom: 2px;
}

.quantity-control {
    display: flex;
    align-items: center;
    width: 100%;
    margin-bottom: 2px;
}

.btn-add-to-cart {
    margin-top: 6px;
}

.error-message {
    margin-top: 2px;
    min-height: 0;
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
                    <span class="nav-text">Sales</span>
                </h3>
            </div>
            <div class="sidebar-menu">
                <ul class="nav flex-column">
                   <li class="nav-item">
                        <a class="nav-link" href="currentinventory.php">
                            <i class="bi bi-boxes"></i>
                            <span class="nav-text">Current Inventory</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="orderproduct.php">
                            <i class="bi bi-bag"></i>
                            <span class="nav-text">Order Product</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="sales_order.php">
                            <i class="bi bi-list-check"></i>
                            <span class="nav-text">Sales Orders</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="customer.php">
                            <i class="bi bi-people"></i>
                            <span class="nav-text">Customer</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="returnedmerchandise.php">
                            <i class="bi bi-arrow-counterclockwise"></i>
                            <span class="nav-text">Returned Merchandise</span>
                        </a>
                    </li>
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

        <!-- Main Content Area -->
        <div class="main-content">
            <!-- Header Section -->
            <div class="navbar-top">
                <button class="mobile-toggle-btn" id="mobileToggleBtn">
                    <i class="bi bi-list"></i>
                </button>
                <div class="page-title">
                    <h2>Order Products</h2>
                    <p>Select products and quantities to create an order</p>
                </div>
                <button class="btn btn-success position-relative" type="button" data-bs-toggle="modal" data-bs-target="#cartSummaryModal" title="View Cart">
                    <i class="bi bi-cart3"></i>
                    <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" id="cartBadge" style="display: none;">
                        <span id="cartItemCount">0</span>
                    </span>
                </button>
            </div>

            <!-- Branch Info Alert (if no branch_id column in items or customers) -->
            <?php if (!$items_branch_column_exists): ?>
                <div class="alert alert-info alert-dismissible fade show" role="alert">
                    <i class="bi bi-info-circle"></i> 
                    <strong>Branch filtering for products not yet set up.</strong> Please run this SQL in phpMyAdmin to enable branch-specific product data:
                    <br><br>
                    <code>ALTER TABLE items ADD COLUMN branch_id INT NULL;</code>
                    <br>
                    <code>ALTER TABLE items ADD FOREIGN KEY (branch_id) REFERENCES branches(branch_id);</code>
                    <br><br>
                    <button type="button" class="btn btn-sm btn-primary" onclick="copyItemsSQL()">
                        <i class="bi bi-files"></i> Copy SQL
                    </button>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <script>
                    function copyItemsSQL() {
                        const sql = "ALTER TABLE items ADD COLUMN branch_id INT NULL;\nALTER TABLE items ADD FOREIGN KEY (branch_id) REFERENCES branches(branch_id);";
                        navigator.clipboard.writeText(sql).then(() => {
                            alert('SQL copied to clipboard!');
                        });
                    }
                </script>
            <?php endif; ?>

            <?php if (!$branch_column_exists): ?>
                <div class="alert alert-info alert-dismissible fade show" role="alert">
                    <i class="bi bi-info-circle"></i> 
                    <strong>Branch filtering for customers not yet set up.</strong> Please run this SQL in phpMyAdmin to enable branch-specific customer data:
                    <br><br>
                    <code>ALTER TABLE customers ADD COLUMN branch_id INT NULL;</code>
                    <br>
                    <code>ALTER TABLE customers ADD FOREIGN KEY (branch_id) REFERENCES branches(branch_id);</code>
                    <br><br>
                    <button type="button" class="btn btn-sm btn-primary" onclick="copyCustomersSQL()">
                        <i class="bi bi-files"></i> Copy SQL
                    </button>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <script>
                    function copyCustomersSQL() {
                        const sql = "ALTER TABLE customers ADD COLUMN branch_id INT NULL;\nALTER TABLE customers ADD FOREIGN KEY (branch_id) REFERENCES branches(branch_id);";
                        navigator.clipboard.writeText(sql).then(() => {
                            alert('SQL copied to clipboard!');
                        });
                    }
                </script>
            <?php endif; ?>

            <!-- Customer Information Section -->
            <div class="row g-3">
                <div class="col-12">
                    <!-- Customer Information -->
                    <div class="card customer-card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0"><i class="bi bi-person-check"></i>Select Customer</h5>
                            <?php if ($branch_column_exists && !$view_all_branches): ?>
                            <?php elseif ($view_all_branches): ?>
                                <span class="badge bg-success">All Branches</span>
                            <?php endif; ?>
                        </div>
                        <div class="card-body">
                            <?php if ($branch_column_exists && !$view_all_branches && count($customers) === 0): ?>
                                <div class="alert alert-warning mb-3">
                                    <i class="bi bi-exclamation-triangle"></i> No customers found for your branch. You can add new customers.
                                </div>
                            <?php endif; ?>
                            <div class="customer-form">
                                <div class="form-group">
                                    <select class="form-select" id="customerSelect">
                                        <option value="">-- Choose Customer --</option>
                                        <?php foreach ($customers as $customer): ?>
                                            <option value="<?php echo $customer['customer_id']; ?>" 
                                                    data-email="<?php echo htmlspecialchars($customer['email'] ?? ''); ?>"
                                                    data-phone="<?php echo htmlspecialchars($customer['phone_number'] ?? ''); ?>"
                                                    data-address="<?php echo htmlspecialchars($customer['address'] ?? ''); ?>">
                                                <?php echo htmlspecialchars($customer['customer_name']); ?>
                                                <?php if (isset($customer['branch_name']) && $view_all_branches): ?>
                                                    (<?php echo htmlspecialchars($customer['branch_name'] ?? 'No Branch'); ?>)
                                                <?php endif; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <!-- Products Section -->
            <div class="col-12">
                <!-- Available Products -->
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <div class="d-flex align-items-center gap-2">
                            <h5 class="mb-0">Available Products</h5>
                            <?php if ($items_branch_column_exists && !$view_all_branches): ?>
                            <?php elseif ($view_all_branches): ?>
                                <span class="badge bg-success">All Branches</span>
                            <?php endif; ?>
                        </div>
                        <button type="button" class="btn btn-success btn-sm" onclick="addAllSelectedToCart()">
                            <i class="bi bi-cart-check"></i> Add All to Cart
                        </button>
                    </div>
                    <div class="card-body p-4">
                        <?php if (count($items) === 0): ?>
                            <div class="alert alert-warning">
                                <i class="bi bi-exclamation-triangle"></i> No products available for your branch.
                            </div>
                        <?php endif; ?>
                        <div class="row g-4" id="productsContainer">
                            <!-- Products will be populated here -->
                        </div>
                    </div>
                </div>
            </div>

            <!-- Cart Section - Hidden, shown in modal instead -->
            <div class="row" style="display: none;">
                <div class="col-lg-4">
                    <div class="order-summary">
                        <h5 class="mb-3"><i class="bi bi-cart3"></i> Order Summary</h5>
                        
                        <div id="cartItems" class="mb-3">
                            <p class="text-white-50 text-center">No items in cart</p>
                        </div>

                        <hr class="bg-white-50">

                        <div class="mb-2">
                            <div class="d-flex justify-content-between">
                                <span>Subtotal:</span>
                                <span id="subtotal">₱0.00</span>
                            </div>
                        </div>
                        <div class="mb-3">
                            <div class="d-flex justify-content-between">
                                <span>Total Items:</span>
                                <span id="totalItems">0</span>
                            </div>
                        </div>

                        <hr class="bg-white-50">

                        <div class="mb-4">
                            <h6 class="mb-2">Total</h6>
                            <h3 id="totalPrice" class="mb-0">₱0.00</h3>
                        </div>

                        <div class="btn-group-mobile">
                            <button class="btn btn-light w-100 mb-2" onclick="viewCart()">
                                <i class="bi bi-eye"></i> View & Confirm Order
                            </button>
                            <button class="btn btn-outline-light w-100" onclick="clearCart()">
                                <i class="bi bi-trash"></i> Clear Cart
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Cart Modal -->
    <div class="modal fade" id="cartModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Review & Confirm Order</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <h6 class="mb-3">Order Items</h6>
                    <div id="reviewItems" class="mb-4"></div>

                    <h6 class="mb-3">Delivery Information</h6>
                    <div class="alert alert-light">
                        <p class="mb-2"><strong>Customer:</strong> <span id="reviewCustomer">-</span></p>
                        <p class="mb-2"><strong>Email:</strong> <span id="reviewEmail">-</span></p>
                        <p class="mb-2"><strong>Phone:</strong> <span id="reviewPhone">-</span></p>
                        <p class="mb-0"><strong>Address:</strong> <span id="reviewAddress">-</span></p>
                    </div>

                    <h6 class="mb-3">Order Total</h6>
                    <div class="alert alert-light">
                        <div class="d-flex justify-content-between mb-2">
                            <span>Subtotal:</span>
                            <span id="reviewSubtotal">₱0.00</span>
                        </div>
                        <div class="d-flex justify-content-between">
                            <strong>Total:</strong>
                            <strong id="reviewTotal" class="text-success">₱0.00</strong>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Add Order</button>
                    <button type="button" class="btn btn-success" id="confirmOrderBtn" onclick="submitOrder()">
                        <i class="bi bi-check-circle"></i> Confirm & Submit Order
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Cart Summary Modal (Shopping Cart Icon) -->
    <div class="modal fade" id="cartSummaryModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="bi bi-cart3"></i> Order Summary</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="cartModalItems" class="mb-3">
                        <p class="text-muted text-center">No items in cart</p>
                    </div>

                    <hr>

                    <div class="mb-2">
                        <div class="d-flex justify-content-between">
                            <span><strong>Subtotal:</strong></span>
                            <span id="cartModalSubtotal">₱0.00</span>
                        </div>
                    </div>
                    <div class="mb-3">
                        <div class="d-flex justify-content-between">
                            <span><strong>Total Items:</strong></span>
                            <span id="cartModalTotalItems">0</span>
                        </div>
                    </div>

                    <hr>

                    <div class="mb-4">
                        <h6 class="mb-2"><strong>Total</strong></h6>
                        <h3 id="cartModalTotalPrice" class="mb-0 text-success">₱0.00</h3>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-success" onclick="viewCart()">
                        <i class="bi bi-eye"></i> View & Confirm Order
                    </button>
                    <button type="button" class="btn btn-outline-danger" onclick="clearCart()">
                        <i class="bi bi-trash"></i> Clear Cart
                    </button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Success Modal -->
    <div class="modal fade" id="successModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header border-0">
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center py-4">
                    <div class="success-icon">
                        <i class="bi bi-check-circle-fill"></i>
                    </div>
                    <h4 class="modal-title mb-3">Order Submitted Successfully!</h4>
                    
                    <div class="order-details">
                        <h6>Order Details</h6>
                        <p class="mb-2"><strong>Order Number:</strong> <span id="successSoNumber">-</span></p>
                        <p class="mb-2"><strong>Date:</strong> <span id="successOrderDate">-</span></p>
                        <p class="mb-2"><strong>Branch:</strong> <span id="successBranch">Branch <?php echo $branch_id; ?></span></p>
                        <p class="mb-0"><strong>Status:</strong> <span class="badge bg-success">Pending</span></p>
                    </div>
                    
                    <p class="text-muted">Your order has been submitted and is being processed. You can track its status in the orders section.</p>
                </div>
                <div class="modal-footer border-0 justify-content-center">
                    <button type="button" class="btn btn-primary" onclick="createNewOrder()">
                        <i class="bi bi-plus-circle me-2"></i> Create New Order
                    </button>
                    <button type="button" class="btn btn-outline-secondary" onclick="viewOrders()">
                        <i class="bi bi-list-ul me-2"></i> View Orders
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Cancel Order Confirmation Modal -->
    <div class="modal fade" id="cancelOrderModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Cancel Order</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to cancel this order? All items will be returned to inventory.</p>
                    <p class="text-danger"><small>This action cannot be undone.</small></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">No, Keep Order</button>
                    <button type="button" class="btn btn-danger" id="confirmCancelBtn">Yes, Cancel Order</button>
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript -->
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

        // Fixed conversion rates (pieces per unit type)
        const UNIT_CONVERSIONS = {
            'piece': 1,
            'case': 12,
            'inner-pack': 6,
            'box': 24,
            'carton': 48
        };

        // Inventory data from database with branch context
        const inventory = <?php echo json_encode(array_map(function($item) {
            return [
                'id' => (int)$item['item_id'],
                'name' => $item['item_name'],
                'sku' => $item['item_code'],
                'unit_price' => (float)$item['unit_price'],
                'price_case' => isset($item['price_case']) ? (float)$item['price_case'] : null,
                'price_inner_pack' => isset($item['price_inner_pack']) ? (float)$item['price_inner_pack'] : null,
                'price_box' => isset($item['price_box']) ? (float)$item['price_box'] : null,
                'price_carton' => isset($item['price_carton']) ? (float)$item['price_carton'] : null,
                'stock' => (int)($item['stock'] ?? 0),
                'image' => $item['product_image_url'] ?? null
            ];
        }, $items)); ?>;

        // Branch context
        const branchId = <?php echo $branch_id; ?>;
        const viewAllBranches = <?php echo $view_all_branches ? 'true' : 'false'; ?>;
        const itemsBranchColumnExists = <?php echo $items_branch_column_exists ? 'true' : 'false'; ?>;
        const customersBranchColumnExists = <?php echo $branch_column_exists ? 'true' : 'false'; ?>;

        let cart = [];

        // Initialize page
        function init() {
            renderProducts();
            updateCart();
            
            // Check if we have a pending order to restore stock (from URL parameter)
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('cancel') === 'success') {
                showToast('Order cancelled and stock restored successfully!');
                // Remove the parameter from URL
                const url = new URL(window.location);
                url.searchParams.delete('cancel');
                window.history.replaceState({}, document.title, url.toString());
            }
        }

        // Calculate available stock for a product (considering items in cart)
        function getAvailableStock(productId) {
            const product = inventory.find(p => p.id === productId);
            if (!product) return 0;
            
            // Calculate total pieces in cart for this product
            const cartItems = cart.filter(item => item.id === productId);
            const piecesInCart = cartItems.reduce((total, item) => {
                return total + (item.quantity * UNIT_CONVERSIONS[item.unit_type]);
            }, 0);
            
            return Math.max(0, product.stock - piecesInCart);
        }

        // Render product cards with plus/minus buttons - FIXED IMAGE PATH
        function renderProducts() {
            const container = document.getElementById('productsContainer');
            if (!container) return;
            
            if (inventory.length === 0) {
                container.innerHTML = `
                    <div class="col-12">
                        <div class="alert alert-info text-center py-4">
                            <i class="bi bi-box-seam" style="font-size: 2rem;"></i>
                            <p class="mt-3 mb-0">No products available for your branch.</p>
                        </div>
                    </div>
                `;
                return;
            }
            
            container.innerHTML = inventory.map(product => {
                const availableStock = getAvailableStock(product.id);
                const outOfStock = availableStock === 0;
                const lowStock = availableStock > 0 && availableStock < 50;
                const placeholderImage = 'data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%22200%22 height=%22200%22%3E%3Crect fill=%22%23e0e0e0%22 width=%22200%22 height=%22200%22/%3E%3Ctext x=%2250%25%22 y=%2250%25%22 text-anchor=%22middle%22 dy=%22.3em%22 fill=%22%23999%22 font-family=%22sans-serif%22 font-size=%2214%22%3ENo Image%3C/text%3E%3C/svg%3E';
                
                // FIXED: Use correct path to products folder
                const imageUrl = product.image ? '../uploads/products/' + product.image : placeholderImage;
                
                return `
                <div class="product-card-mobile" id="product-card-${product.id}">
                    <div class="card h-100">
                        <div class="product-image-container">
                            <img src="${imageUrl}" alt="${product.name}" class="product-image" onerror="this.src='${placeholderImage}'">
                            ${outOfStock ? '<div class="out-of-stock-overlay">Out of Stock</div>' : ''}
                        </div>
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <h6 class="card-title mb-0">${product.name}</h6>
                                <span class="badge bg-light text-dark">${product.sku}</span>
                            </div>
                            <p class="text-muted small mb-2 product-stock-mobile" id="stock-info-${product.id}">
                                Available: <strong class="${lowStock ? 'text-danger' : ''}">${availableStock} pieces</strong>
                                ${lowStock && !outOfStock ? '<span class="stock-warning"> - Low Stock</span>' : ''}
                            </p>
                            <p class="h5 text-success mb-3 product-price-mobile">
                                <span id="price-display-${product.id}">₱${product.unit_price.toFixed(2)}</span>
                            </p>
                            <div class="product-input-group-mobile">
                                <label class="form-label small text-muted">Unit Type:</label>
                                <select class="form-select form-select-sm" id="unit-${product.id}" onchange="updateUnitPrice(${product.id})" ${outOfStock ? 'disabled' : ''}>
                                    <option value="piece">piece (1 piece)</option>
                                    ${product.price_case ? `<option value="case">case (12 pieces) - ₱${product.price_case.toFixed(2)}</option>` : ''}
                                    ${product.price_inner_pack ? `<option value="inner-pack">inner-pack (6 pieces) - ₱${product.price_inner_pack.toFixed(2)}</option>` : ''}
                                    ${product.price_box ? `<option value="box">box (24 pieces) - ₱${product.price_box.toFixed(2)}</option>` : ''}
                                    ${product.price_carton ? `<option value="carton">carton (48 pieces) - ₱${product.price_carton.toFixed(2)}</option>` : ''}
                                </select>
                                
                                <label class="form-label small text-muted mt-3">Quantity:</label>
                                <div class="quantity-control" style="justify-content:center;">
                                    <button type="button" class="decrease-btn" onclick="decreaseQuantity(${product.id})" ${outOfStock ? 'disabled' : ''}>
                                        <i class="bi bi-dash-lg"></i>
                                    </button>
                                    <input type="number" class="form-control" id="qty-${product.id}" 
                                           min="0" max="999" value="0" 
                                           onchange="updateQuantityInput(${product.id})"
                                           oninput="validateQuantity(${product.id})"
                                           ${outOfStock ? 'disabled' : ''}>
                                    <button type="button" class="increase-btn" onclick="increaseQuantity(${product.id})" ${outOfStock ? 'disabled' : ''}>
                                        <i class="bi bi-plus-lg"></i>
                                    </button>
                                </div>
                                
                                <button class="btn-add-to-cart" id="btn-add-${product.id}" onclick="addToCart(${product.id})" ${outOfStock ? 'disabled' : ''}>
                                    <i class="bi bi-cart-plus"></i> Add to Cart
                                </button>
                                <div class="error-message" id="error-${product.id}"></div>
                            </div>
                        </div>
                    </div>
                </div>
                `;
            }).join('');
        }

        // Update unit price display when selection changes
        function updateUnitPrice(productId) {
            const product = inventory.find(p => p.id === productId);
            const unitSelect = document.getElementById(`unit-${productId}`);
            const priceDisplay = document.getElementById(`price-display-${productId}`);
            
            if (unitSelect && priceDisplay && product) {
                const selectedUnit = unitSelect.value;
                let price = product.unit_price;
                
                if (selectedUnit === 'case' && product.price_case) {
                    price = product.price_case;
                } else if (selectedUnit === 'inner-pack' && product.price_inner_pack) {
                    price = product.price_inner_pack;
                } else if (selectedUnit === 'box' && product.price_box) {
                    price = product.price_box;
                } else if (selectedUnit === 'carton' && product.price_carton) {
                    price = product.price_carton;
                }
                
                priceDisplay.textContent = `₱${price.toFixed(2)}`;
            }
        }

        // Update product stock display after order
        function updateProductStock(itemId, newStock) {
            // Update inventory array
            const product = inventory.find(p => p.id === itemId);
            if (product) {
                product.stock = newStock;
            }
            
            // Update stock info display
            const stockInfo = document.getElementById(`stock-info-${itemId}`);
            const qtyInput = document.getElementById(`qty-${itemId}`);
            const addButton = document.getElementById(`btn-add-${itemId}`);
            const decreaseBtn = document.querySelector(`#product-card-${itemId} .decrease-btn`);
            const increaseBtn = document.querySelector(`#product-card-${itemId} .increase-btn`);
            
            if (stockInfo) {
                const availableStock = getAvailableStock(itemId);
                const lowStock = availableStock > 0 && availableStock < 50;
                stockInfo.innerHTML = `Available: <strong class="${lowStock ? 'text-danger' : ''}">${availableStock} pieces</strong> ${lowStock ? '<span class="stock-warning"> - Low Stock</span>' : ''}`;
            }
            
            // Update input max and state
            if (qtyInput) {
                const availableStock = getAvailableStock(itemId);
                qtyInput.value = 0;
                
                if (availableStock === 0) {
                    qtyInput.disabled = true;
                    if (addButton) addButton.disabled = true;
                    if (decreaseBtn) decreaseBtn.disabled = true;
                    if (increaseBtn) increaseBtn.disabled = true;
                } else {
                    qtyInput.disabled = false;
                    if (addButton) addButton.disabled = false;
                    if (decreaseBtn) decreaseBtn.disabled = false;
                    if (increaseBtn) increaseBtn.disabled = false;
                }
            }
            
            // Highlight the updated product
            const productCard = document.getElementById(`product-card-${itemId}`);
            if (productCard) {
                productCard.classList.add('stock-updated');
                setTimeout(() => {
                    productCard.classList.remove('stock-updated');
                }, 1000);
            }
        }

        // Decrease quantity
        function decreaseQuantity(productId) {
            const qtyInput = document.getElementById(`qty-${productId}`);
            if (qtyInput) {
                let currentValue = parseInt(qtyInput.value) || 0;
                if (currentValue > 0) {
                    qtyInput.value = currentValue - 1;
                    validateQuantity(productId);
                }
            }
        }

        // Increase quantity
        function increaseQuantity(productId) {
            const qtyInput = document.getElementById(`qty-${productId}`);
            if (qtyInput) {
                let currentValue = parseInt(qtyInput.value) || 0;
                qtyInput.value = currentValue + 1;
                validateQuantity(productId);
            }
        }

        // Update quantity input max value based on available stock
        function updateQuantityInput(productId) {
            validateQuantity(productId);
        }

        // Validate quantity input and update button state
        function validateQuantity(productId) {
            const qtyInput = document.getElementById(`qty-${productId}`);
            const addButton = document.getElementById(`btn-add-${productId}`);
            const errorDiv = document.getElementById(`error-${productId}`);
            
            if (!qtyInput) return 0;
            
            let value = parseInt(qtyInput.value) || 0;
            
            if (value < 0) {
                value = 0;
                qtyInput.value = 0;
            }
            
            if (value === 0) {
                if (errorDiv) errorDiv.textContent = '';
                if (addButton) addButton.disabled = true;
            } else {
                if (errorDiv) errorDiv.textContent = '';
                if (addButton) addButton.disabled = false;
            }
            
            return value;
        }

        // Add to cart with validation
        function addToCart(productId) {
            const product = inventory.find(p => p.id === productId);
            const qtyInput = document.getElementById(`qty-${product.id}`);
            const unitSelect = document.getElementById(`unit-${product.id}`);
            const quantity = parseInt(qtyInput?.value) || 0;
            const unitType = unitSelect?.value || 'piece';
            const errorDiv = document.getElementById(`error-${product.id}`);
            const availableStock = getAvailableStock(product.id);

            if (quantity <= 0) {
                if (errorDiv) errorDiv.textContent = 'Please enter a quantity';
                return;
            }

            // Calculate pieces needed based on unit type
            const piecesPerUnit = UNIT_CONVERSIONS[unitType];
            const piecesNeeded = quantity * piecesPerUnit;

            if (piecesNeeded > availableStock) {
                if (errorDiv) errorDiv.textContent = `Only ${availableStock} pieces available (${quantity} ${unitType} = ${piecesNeeded} pieces)`;
                return;
            }

            // Get the correct price based on unit type
            let unitPrice = product.unit_price;
            if (unitType === 'case' && product.price_case) {
                unitPrice = product.price_case;
            } else if (unitType === 'inner-pack' && product.price_inner_pack) {
                unitPrice = product.price_inner_pack;
            } else if (unitType === 'box' && product.price_box) {
                unitPrice = product.price_box;
            } else if (unitType === 'carton' && product.price_carton) {
                unitPrice = product.price_carton;
            }

            const existingItem = cart.find(item => item.id === productId && item.unit_type === unitType);
            
            if (existingItem) {
                existingItem.quantity += quantity;
            } else {
                cart.push({
                    id: productId,
                    name: product.name,
                    price: unitPrice,
                    quantity: quantity,
                    sku: product.sku,
                    unit_type: unitType
                });
            }

            if (qtyInput) qtyInput.value = '0';
            updateCart();
            renderProducts();
            
            if (piecesNeeded > quantity) {
                showToast(`${quantity} × ${product.name} (${unitType} = ${piecesNeeded} pieces) added to cart!`);
            } else {
                showToast(`${quantity} × ${product.name} (${unitType}) added to cart!`);
            }
        }

        // Add all selected products to cart at once
        function addAllSelectedToCart() {
            let totalAdded = 0;
            let itemsAdded = [];
            let hasErrors = false;
            
            // First, validate all items
            for (const product of inventory) {
                const qtyInput = document.getElementById(`qty-${product.id}`);
                const unitSelect = document.getElementById(`unit-${product.id}`);
                if (qtyInput) {
                    const quantity = parseInt(qtyInput.value) || 0;
                    
                    if (quantity > 0) {
                        const unitType = unitSelect?.value || 'piece';
                        const piecesPerUnit = UNIT_CONVERSIONS[unitType];
                        const piecesNeeded = quantity * piecesPerUnit;
                        const availableStock = getAvailableStock(product.id);
                        
                        if (piecesNeeded > availableStock) {
                            const errorDiv = document.getElementById(`error-${product.id}`);
                            if (errorDiv) errorDiv.textContent = `Only ${availableStock} pieces available (${quantity} ${unitType} = ${piecesNeeded} pieces)`;
                            hasErrors = true;
                        }
                    }
                }
            }
            
            if (hasErrors) {
                showToast('Please fix quantity errors before adding to cart');
                return;
            }
            
            // If no errors, add all items to cart
            for (const product of inventory) {
                const qtyInput = document.getElementById(`qty-${product.id}`);
                const unitSelect = document.getElementById(`unit-${product.id}`);
                if (qtyInput) {
                    const quantity = parseInt(qtyInput.value) || 0;
                    
                    if (quantity > 0) {
                        const unitType = unitSelect?.value || 'piece';
                        
                        // Get the correct price based on unit type
                        let unitPrice = product.unit_price;
                        if (unitType === 'case' && product.price_case) {
                            unitPrice = product.price_case;
                        } else if (unitType === 'inner-pack' && product.price_inner_pack) {
                            unitPrice = product.price_inner_pack;
                        } else if (unitType === 'box' && product.price_box) {
                            unitPrice = product.price_box;
                        } else if (unitType === 'carton' && product.price_carton) {
                            unitPrice = product.price_carton;
                        }
                        
                        const existingItem = cart.find(item => item.id === product.id && item.unit_type === unitType);
                        if (existingItem) {
                            existingItem.quantity += quantity;
                        } else {
                            cart.push({
                                id: product.id,
                                name: product.name,
                                price: unitPrice,
                                quantity: quantity,
                                sku: product.sku,
                                unit_type: unitType
                            });
                        }
                        
                        const piecesNeeded = quantity * UNIT_CONVERSIONS[unitType];
                        itemsAdded.push(`${quantity}× ${product.name} (${unitType} = ${piecesNeeded} pieces)`);
                        totalAdded += quantity;
                        qtyInput.value = '0';
                        unitSelect.value = 'piece';
                        updateUnitPrice(product.id);
                    }
                }
            }
            
            if (totalAdded === 0) {
                showToast('Please select quantities for products');
                return;
            }
            
            updateCart();
            renderProducts();
            
            showToast(`${totalAdded} items added to cart!`);
        }

        // Update cart display
        function updateCart() {
            const cartItemsDiv = document.getElementById('cartItems');
            const subtotalDiv = document.getElementById('subtotal');
            const totalItemsDiv = document.getElementById('totalItems');
            const totalPriceDiv = document.getElementById('totalPrice');
            
            // Modal elements
            const cartModalItemsDiv = document.getElementById('cartModalItems');
            const cartModalSubtotal = document.getElementById('cartModalSubtotal');
            const cartModalTotalItems = document.getElementById('cartModalTotalItems');
            const cartModalTotalPrice = document.getElementById('cartModalTotalPrice');
            
            // Badge elements
            const cartBadge = document.getElementById('cartBadge');
            const cartItemCount = document.getElementById('cartItemCount');

            if (!cartItemsDiv) return;

            const cartItemsHTML = cart.map(item => {
                const product = inventory.find(p => p.id === item.id);
                const piecesInCart = item.quantity * UNIT_CONVERSIONS[item.unit_type];
                const remainingStock = product ? product.stock - piecesInCart : 0;
                const lowStockWarning = remainingStock < 50 ? `<div class="text-warning small mt-1">${remainingStock} pieces left in stock</div>` : '';
                const unitTypeDisplay = item.unit_type ? ` (${item.unit_type})` : '';
                
                return `
                <div class="cart-item">
                    <div style="flex: 1;">
                        <div class="text-black-50 small">${item.name}${unitTypeDisplay}</div>
                        <div class="text-black-50 small">${item.sku}</div>
                        <div class="text-black-50 small">₱${item.price.toFixed(2)} × ${item.quantity}</div>
                        <div class="text-black-50 small">Total pieces: ${piecesInCart}</div>
                        ${lowStockWarning}
                    </div>
                    <div class="text-end">
                        <div class="text-black fw-bold">₱${(item.price * item.quantity).toFixed(2)}</div>
                        <button class="btn btn-sm btn-outline-light mt-1" onclick="removeFromCart(${item.id}, '${item.unit_type}')">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                </div>
                `;
            }).join('');

            const subtotal = cart.reduce((sum, item) => sum + (item.price * item.quantity), 0);
            const totalItems = cart.reduce((sum, item) => sum + item.quantity, 0);

            // Update sidebar cart (hidden)
            if (cart.length === 0) {
                cartItemsDiv.innerHTML = '<p class="text-white-50 text-center">No items in cart</p>';
                if (subtotalDiv) subtotalDiv.textContent = '₱0.00';
                if (totalItemsDiv) totalItemsDiv.textContent = '0';
                if (totalPriceDiv) totalPriceDiv.textContent = '₱0.00';
            } else {
                cartItemsDiv.innerHTML = cartItemsHTML;
                if (subtotalDiv) subtotalDiv.textContent = `₱${subtotal.toFixed(2)}`;
                if (totalItemsDiv) totalItemsDiv.textContent = totalItems;
                if (totalPriceDiv) totalPriceDiv.textContent = `₱${subtotal.toFixed(2)}`;
            }

            // Update modal cart
            if (cart.length === 0) {
                if (cartModalItemsDiv) cartModalItemsDiv.innerHTML = '<p class="text-muted text-center">No items in cart</p>';
                if (cartModalSubtotal) cartModalSubtotal.textContent = '₱0.00';
                if (cartModalTotalItems) cartModalTotalItems.textContent = '0';
                if (cartModalTotalPrice) cartModalTotalPrice.textContent = '₱0.00';
            } else {
                if (cartModalItemsDiv) cartModalItemsDiv.innerHTML = cartItemsHTML;
                if (cartModalSubtotal) cartModalSubtotal.textContent = `₱${subtotal.toFixed(2)}`;
                if (cartModalTotalItems) cartModalTotalItems.textContent = totalItems;
                if (cartModalTotalPrice) cartModalTotalPrice.textContent = `₱${subtotal.toFixed(2)}`;
            }

            // Update badge - FIXED: Hide badge when cart is empty
            if (cartBadge && cartItemCount) {
                if (totalItems > 0) {
                    cartItemCount.textContent = totalItems;
                    cartBadge.style.display = 'inline-block';
                } else {
                    cartBadge.style.display = 'none';
                    cartItemCount.textContent = '0';
                }
            }
        }

        // Clear cart - FIXED: Ensure badge is hidden after clearing
        function clearCart() {
            if (cart.length === 0) {
                showToast('Cart is already empty');
                return;
            }
            
            if (confirm('Clear all items from cart?')) {
                cart = [];
                updateCart();
                renderProducts();
                
                // Force hide badge
                const cartBadge = document.getElementById('cartBadge');
                const cartItemCount = document.getElementById('cartItemCount');
                if (cartBadge) {
                    cartBadge.style.display = 'none';
                }
                if (cartItemCount) {
                    cartItemCount.textContent = '0';
                }
                
                showToast('Cart cleared');
            }
        }

        // Remove from cart
        function removeFromCart(productId, unitType = null) {
            if (unitType) {
                cart = cart.filter(item => !(item.id === productId && item.unit_type === unitType));
            } else {
                cart = cart.filter(item => item.id !== productId);
            }
            updateCart();
            renderProducts();
            showToast('Item removed from cart');
        }

        // Show toast notification
        function showToast(message) {
            const existingToast = document.querySelector('.toast-notification');
            if (existingToast) {
                existingToast.remove();
            }
            
            const toast = document.createElement('div');
            toast.className = 'toast-notification';
            toast.innerHTML = `
                <div class="d-flex align-items-center">
                    <i class="bi bi-check-circle-fill me-2"></i>
                    <span>${message}</span>
                </div>
            `;
            
            document.body.appendChild(toast);
            
            setTimeout(() => {
                toast.style.animation = 'slideOut 0.3s ease-out';
                setTimeout(() => toast.remove(), 300);
            }, 3000);
        }

        // View cart and confirm
        function viewCart() {
            if (cart.length === 0) {
                showToast('Please add items to cart first');
                return;
            }

            const customerSelect = document.getElementById('customerSelect');
            
            if (!customerSelect?.value) {
                showToast('Please select a customer');
                return;
            }

            const selectedCustomer = customerSelect?.options[customerSelect.selectedIndex];
            const customerName = selectedCustomer?.text.split('(')[0].trim() || '';
            const email = selectedCustomer?.getAttribute('data-email') || '';
            const phone = selectedCustomer?.getAttribute('data-phone') || '';
            const address = selectedCustomer?.getAttribute('data-address') || '';

            // Close the cart summary modal
            const cartSummaryModal = bootstrap.Modal.getInstance(document.getElementById('cartSummaryModal'));
            if (cartSummaryModal) {
                cartSummaryModal.hide();
            }

            populateReviewModal(customerName, email, phone, address);
            
            // Open the review modal
            const reviewModal = new bootstrap.Modal(document.getElementById('cartModal'));
            reviewModal.show();
        }

        // Populate review modal
        function populateReviewModal(customerName, email, phone, address) {
            const reviewItems = document.getElementById('reviewItems');
            if (reviewItems) {
                reviewItems.innerHTML = `
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead class="table-light">
                                <tr>
                                    <th>Product</th>
                                    <th>SKU</th>
                                    <th>Unit</th>
                                    <th>Pieces</th>
                                    <th>Price per Unit</th>
                                    <th>Qty</th>
                                    <th>Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${cart.map(item => {
                                    const product = inventory.find(p => p.id === item.id);
                                    const piecesInCart = item.quantity * UNIT_CONVERSIONS[item.unit_type];
                                    const remainingStock = product ? product.stock - piecesInCart : 0;
                                    return `
                                    <tr>
                                        <td>
                                            ${item.name}
                                            ${remainingStock < 50 ? 
                                                `<br><small class="text-warning">${remainingStock} pieces left</small>` : ''}
                                        </td>
                                        <td>${item.sku}</td>
                                        <td>${item.unit_type || 'piece'}</td>
                                        <td>${piecesInCart}</td>
                                        <td>₱${item.price.toFixed(2)}</td>
                                        <td>${item.quantity}</td>
                                        <td>₱${(item.price * item.quantity).toFixed(2)}</td>
                                    </tr>
                                    `;
                                }).join('')}
                            </tbody>
                        </table>
                    </div>
                `;
            }

            const reviewCustomer = document.getElementById('reviewCustomer');
            const reviewEmail = document.getElementById('reviewEmail');
            const reviewPhone = document.getElementById('reviewPhone');
            const reviewAddress = document.getElementById('reviewAddress');
            const reviewSubtotal = document.getElementById('reviewSubtotal');
            const reviewTotal = document.getElementById('reviewTotal');
            
            if (reviewCustomer) reviewCustomer.textContent = customerName;
            if (reviewEmail) reviewEmail.textContent = email;
            if (reviewPhone) reviewPhone.textContent = phone;
            if (reviewAddress) reviewAddress.textContent = address;

            const subtotal = cart.reduce((sum, item) => sum + (item.price * item.quantity), 0);
            if (reviewSubtotal) reviewSubtotal.textContent = `₱${subtotal.toFixed(2)}`;
            if (reviewTotal) reviewTotal.textContent = `₱${subtotal.toFixed(2)}`;
        }

        // Submit order
        function submitOrder() {
            const customerSelect = document.getElementById('customerSelect');
            const customer_id = customerSelect?.value ? parseInt(customerSelect.value) : 0;
            const selectedCustomer = customerSelect?.options[customerSelect.selectedIndex];
            const customer_name = selectedCustomer?.text.split('(')[0].trim() || '';
            const email = selectedCustomer?.getAttribute('data-email') || '';
            const phone = selectedCustomer?.getAttribute('data-phone') || '';
            const address = selectedCustomer?.getAttribute('data-address') || '';
            
            if (!customer_id) {
                showToast('Please select a customer');
                return;
            }
            
            if (cart.length === 0) {
                showToast('Cart is empty');
                return;
            }
            
            const cartData = cart.map(item => ({
                id: item.id,
                name: item.name,
                price: item.price,
                quantity: item.quantity,
                sku: item.sku,
                unit_type: item.unit_type || 'piece'
            }));
            
            const formData = new FormData();
            formData.append('action', 'submit_order');
            formData.append('customer_id', customer_id);
            formData.append('customer_name', customer_name);
            formData.append('email', email);
            formData.append('phone', phone);
            formData.append('address', address);
            formData.append('items', JSON.stringify(cartData));
            
            const confirmBtn = document.getElementById('confirmOrderBtn');
            let originalText = '';
            if (confirmBtn) {
                originalText = confirmBtn.innerHTML;
                confirmBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Processing...';
                confirmBtn.disabled = true;
            }
            
            console.log('Submitting order...');

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => {
                console.log('Response status:', response.status);
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                return response.text();
            })
            .then(text => {
                console.log('Raw response:', text);
                
                if (!text || text.trim() === '') {
                    throw new Error('Empty response from server');
                }
                
                if (text.trim().startsWith('<')) {
                    console.error('HTML response received instead of JSON:', text.substring(0, 500));
                    throw new Error('Server returned HTML instead of JSON');
                }
                
                try {
                    return JSON.parse(text);
                } catch (e) {
                    console.error('JSON parse error:', e);
                    console.error('Response text:', text);
                    throw new Error('Invalid JSON response from server');
                }
            })
            .then(data => {
                console.log('Parsed response:', data);
                
                const cartModal = bootstrap.Modal.getInstance(document.getElementById('cartModal'));
                if (cartModal) {
                    cartModal.hide();
                }
                
                if (data.success) {
                    // Update local inventory with new stock levels
                    if (data.updated_stock && data.updated_stock.length > 0) {
                        data.updated_stock.forEach(item => {
                            updateProductStock(item.item_id, item.stock);
                        });
                    }
                    
                    cart = [];
                    updateCart();
                    
                    const successSoNumber = document.getElementById('successSoNumber');
                    const successOrderDate = document.getElementById('successOrderDate');
                    const successBranch = document.getElementById('successBranch');
                    
                    if (successSoNumber) successSoNumber.textContent = data.so_number;
                    if (successOrderDate) successOrderDate.textContent = new Date().toLocaleDateString();
                    if (successBranch) successBranch.textContent = `Branch ${branchId}`;
                    
                    const successModal = new bootstrap.Modal(document.getElementById('successModal'));
                    successModal.show();
                    
                    // Reset customer form
                    setTimeout(() => {
                        const customerSelect = document.getElementById('customerSelect');
                        if (customerSelect) customerSelect.value = '';
                    }, 500);
                    
                } else {
                    showToast('Error: ' + (data.message || 'Failed to submit order'));
                }
                
                // Reset confirm button regardless of success or failure
                if (confirmBtn) {
                    confirmBtn.innerHTML = originalText;
                    confirmBtn.disabled = false;
                }
            })
            .catch(error => {
                console.error('Error:', error.message);
                showToast('Error: ' + error.message);
                if (confirmBtn) {
                    confirmBtn.innerHTML = originalText;
                    confirmBtn.disabled = false;
                }
            });
        }

        // Cancel order function (to be called from sales_order.php)
        function cancelOrder(orderId) {
            return new Promise((resolve, reject) => {
                const formData = new FormData();
                formData.append('action', 'cancel_order');
                formData.append('order_id', orderId);
                
                fetch('', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Update local inventory with restored stock
                        if (data.restored_stock && data.restored_stock.length > 0) {
                            data.restored_stock.forEach(item => {
                                updateProductStock(item.item_id, item.stock);
                            });
                        }
                        resolve(data);
                    } else {
                        reject(new Error(data.message));
                    }
                })
                .catch(error => reject(error));
            });
        }

        // Create new order
        function createNewOrder() {
            const successModal = bootstrap.Modal.getInstance(document.getElementById('successModal'));
            if (successModal) {
                successModal.hide();
            }
        }

        // View orders
        function viewOrders() {
            window.location.href = 'sales_order.php';
        }

        function logout() {
            window.location.href = '../logout.php';
        }

        // Initialize when page loads
        document.addEventListener('DOMContentLoaded', function() {
            console.log("Sales Order page loaded!");
            console.log("Branch ID:", branchId);
            console.log("View All Branches:", viewAllBranches);
            console.log("Items Branch Column Exists:", itemsBranchColumnExists);
            console.log("Customers Branch Column Exists:", customersBranchColumnExists);
            console.log("Products loaded:", inventory.length);
            
            // Initialize sidebar
            initializeSidebar();
            
            // Setup mobile toggle button
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
            
            // Add click listeners to sidebar links to close on mobile
            document.querySelectorAll('.sidebar .nav-link').forEach(link => {
                link.addEventListener('click', function() {
                    if (window.innerWidth <= 992) {
                        closeMobileSidebar();
                    }
                });
            });
            
            // Close sidebar when clicking outside on mobile
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

            // Add resize event listener
            window.addEventListener('resize', handleSidebarResize);
            
            // Initialize the order product functionality
            init();
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 'b' && window.innerWidth > 992) {
                e.preventDefault();
                toggleSidebar();
            } else if (e.key === 'Escape' && window.innerWidth <= 992) {
                closeMobileSidebar();
            } else if (e.ctrlKey && e.key === 'n') {
                e.preventDefault();
                createNewOrder();
            } else if (e.ctrlKey && e.key === 'v') {
                e.preventDefault();
                viewCart();
            }
        });

        // Expose cancelOrder function globally
        window.cancelOrder = cancelOrder;
    </script>
</body>
</html>