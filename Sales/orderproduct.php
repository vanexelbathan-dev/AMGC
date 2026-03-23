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
                       ORDER BY i.category ASC, i.item_name ASC";
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
                       ORDER BY i.category ASC, i.item_name ASC";
    }
} else {
    // Branch column doesn't exist - show all items
    $items_query = "SELECT i.item_id, i.item_code, i.item_name, i.description, i.category, 
                   i.stock, i.unit_type, i.unit_price, i.price_case, i.price_inner_pack, 
                   i.price_box, i.price_carton, i.reorder_level, i.status,
                   i.product_image_url
                   FROM items i
                   WHERE i.status = 'active'
                   ORDER BY i.category ASC, i.item_name ASC";
}

$items_result = $conn->query($items_query);
if ($items_result) {
    $items = $items_result->fetch_all(MYSQLI_ASSOC);
} else {
    error_log("Items query error: " . $conn->error);
}

// Get all unique categories
$categories = array_unique(array_column($items, 'category'));
$categories = array_filter($categories); // Remove empty categories
sort($categories); // Sort alphabetically

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

// Handle AJAX request for product details
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_product_details') {
    header('Content-Type: application/json');
    
    try {
        $product_id = isset($_POST['product_id']) ? (int)$_POST['product_id'] : 0;
        
        if ($product_id <= 0) {
            throw new Exception("Invalid product ID");
        }
        
        // Get product details
        if ($items_branch_column_exists && !$view_all_branches) {
            $product_query = "SELECT i.item_id, i.item_code, i.item_name, i.description, i.category, 
                             i.stock, i.unit_type, i.unit_price, i.price_case, i.price_inner_pack, 
                             i.price_box, i.price_carton, i.reorder_level, i.status,
                             i.product_image_url,
                             b.branch_name
                             FROM items i
                             LEFT JOIN branches b ON i.branch_id = b.branch_id
                             WHERE i.item_id = ? AND i.branch_id = ?";
            $stmt = $conn->prepare($product_query);
            $stmt->bind_param('ii', $product_id, $branch_id);
        } else {
            $product_query = "SELECT i.item_id, i.item_code, i.item_name, i.description, i.category, 
                             i.stock, i.unit_type, i.unit_price, i.price_case, i.price_inner_pack, 
                             i.price_box, i.price_carton, i.reorder_level, i.status,
                             i.product_image_url
                             FROM items i
                             WHERE i.item_id = ?";
            $stmt = $conn->prepare($product_query);
            $stmt->bind_param('i', $product_id);
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        $product = $result->fetch_assoc();
        
        if (!$product) {
            throw new Exception("Product not found");
        }
        
        // Get order history for this product
        $history_query = "SELECT so.so_number, so.order_date, c.customer_name, so.order_status,
                         soi.quantity_ordered, soi.unit_type, soi.unit_price,
                         (soi.quantity_ordered * soi.unit_price) as total_price
                         FROM sales_order_items soi
                         JOIN sales_orders so ON soi.so_id = so.so_id
                         JOIN customers c ON so.customer_id = c.customer_id
                         WHERE soi.item_id = ?";
        
        if ($items_branch_column_exists && !$view_all_branches) {
            $history_query .= " AND so.branch_id = ?";
            $history_query .= " ORDER BY so.order_date DESC LIMIT 50";
            $history_stmt = $conn->prepare($history_query);
            $history_stmt->bind_param('ii', $product_id, $branch_id);
        } else {
            $history_query .= " ORDER BY so.order_date DESC LIMIT 50";
            $history_stmt = $conn->prepare($history_query);
            $history_stmt->bind_param('i', $product_id);
        }
        
        $history_stmt->execute();
        $history_result = $history_stmt->get_result();
        $order_history = $history_result->fetch_all(MYSQLI_ASSOC);
        
        echo json_encode([
            'success' => true,
            'product' => $product,
            'order_history' => $order_history
        ]);
        exit;
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
        exit;
    }
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
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
        * {
            box-sizing: border-box;
            -webkit-tap-highlight-color: transparent;
        }

        :root {
            --primary-green: #2E7D32;
            --dark-green: #1B5E20;
            --light-gray: #F5F5F5;
            --white: #FFFFFF;
            --black: #212121;
            --border-gray: #e0e0e0;
        }

        /* Cart Item Styling */
        .cart-item {
            background: var(--light-gray);
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-left: 4px solid var(--primary-green);
        }
        
        /* Cart Icon Button in Header */
        .navbar-top .btn-success {
            background: var(--primary-green);
            border: none;
            padding: 8px 12px;
            border-radius: 8px;
            font-size: 18px;
            transition: all 0.2s;
            flex-shrink: 0;
            min-width: 40px;
            min-height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
        }
        
        .navbar-top .btn-success:active {
            transform: scale(0.95);
            background: var(--dark-green);
        }
        
        .navbar-top .btn-success .badge {
            font-size: 10px;
            padding: 3px 5px;
            top: -5px;
            right: -5px;
            background: var(--dark-green) !important;
            border: 2px solid var(--white);
        }
                
        /* Alert for missing branch column */
        .alert-info {
            background-color: var(--light-gray);
            border-color: var(--border-gray);
            color: var(--black);
            border-radius: 8px;
            margin: 10px;
            font-size: 13px;
        }

        /* ===== CATEGORY TABS DESIGN ===== */
        .category-tabs-container {
            background: var(--white);
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            margin-bottom: 20px;
            padding: 15px 15px 0 15px;
        }
        
        .tabs-header {
            display: flex;
            align-items: center;
            gap: 15px;
            border-bottom: 2px solid var(--border-gray);
            padding-bottom: 10px;
        }
        
        .tabs-scroll {
            flex: 1;
            overflow-x: auto;
            overflow-y: hidden;
            white-space: nowrap;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: thin;
            padding-bottom: 5px;
        }
        
        .tabs-scroll::-webkit-scrollbar {
            height: 4px;
        }
        
        .tabs-scroll::-webkit-scrollbar-track {
            background: var(--light-gray);
            border-radius: 4px;
        }
        
        .tabs-scroll::-webkit-scrollbar-thumb {
            background: var(--primary-green);
            border-radius: 4px;
        }
        
        .category-tabs {
            display: inline-flex;
            gap: 5px;
            padding: 2px 0;
        }
        
        .tab-btn {
            padding: 8px 20px;
            border: none;
            background: none;
            color: #666;
            font-weight: 600;
            font-size: 14px;
            border-radius: 20px;
            cursor: pointer;
            transition: all 0.2s;
            white-space: nowrap;
        }
        
        .tab-btn:hover {
            background: var(--light-gray);
            color: var(--primary-green);
        }
        
        .tab-btn.active {
            background: var(--primary-green);
            color: var(--white);
        }
        
        .search-wrapper {
            position: relative;
            min-width: 250px;
        }
        
        .search-icon {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #999;
            font-size: 14px;
        }
        
        .search-input {
            width: 100%;
            padding: 10px 15px 10px 40px;
            border: 1px solid var(--border-gray);
            border-radius: 25px;
            font-size: 14px;
            transition: all 0.2s;
        }
        
        .search-input:focus {
            outline: none;
            border-color: var(--primary-green);
            box-shadow: 0 0 0 3px rgba(46,125,50,0.1);
        }
        
        .search-reset {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #999;
            font-size: 18px;
            cursor: pointer;
            display: none;
            padding: 0 5px;
        }
        
        .search-reset:hover {
            color: #dc3545;
        }
        
        .search-reset.visible {
            display: block;
        }
        
        /* Products container */
        .products-section {
            margin-top: 20px;
        }
        
        /* ===== COMPACT TABLE VIEW ===== */
        .product-table-container {
            background: var(--white);
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            overflow-x: auto;
            margin-bottom: 20px;
        }
        
        .product-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 100%;
        }
        
        .product-table thead {
            background: var(--primary-green);
            color: var(--white);
        }
        
        .product-table th {
            padding: 8px 4px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            text-align: center;
            vertical-align: middle;
        }
        
        .product-table td {
            padding: 6px 4px;
            font-size: 11px;
            border-bottom: 1px solid var(--border-gray);
            vertical-align: middle;
        }
        
        /* Desktop default - full columns */
        .product-table th:nth-child(1) { width: 8%; } /* Image */
        .product-table th:nth-child(2) { width: 22%; } /* Product */
        .product-table th:nth-child(3) { width: 20%; } /* Unit */
        .product-table th:nth-child(4) { width: 12%; } /* Qty */
        .product-table th:nth-child(5) { width: 15%; } /* Price */
        .product-table th:nth-child(6) { width: 10%; } /* Action */
        
        /* Product column left aligned */
        .product-table td:nth-child(2) {
            text-align: left;
        }
        
        /* Other columns center aligned */
        .product-table td:not(:nth-child(2)) {
            text-align: center;
        }
        
        /* Product image */
        .product-image-cell {
            padding: 4px !important;
        }
        
        .product-thumbnail {
            width: 30px;
            height: 30px;
            object-fit: cover;
            border-radius: 4px;
            border: 1px solid var(--border-gray);
            background: var(--light-gray);
        }
        
        .product-info {
            display: flex;
            flex-direction: column;
        }
        
        .product-name {
            font-weight: 600;
            color: var(--black);
            font-size: 11px;
            margin-bottom: 2px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .stock-info {
            font-size: 9px;
            color: var(--primary-green);
            font-weight: 600;
        }
        
        .stock-warning {
            color: #dc3545 !important;
            font-weight: 600;
        }
        
        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 3px;
            justify-content: center;
            align-items: center;
        }
        
        .add-cart-btn {
            background: var(--primary-green);
            border: none;
            color: var(--white);
            width: 24px;
            height: 24px;
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            cursor: pointer;
            padding: 0;
        }
        
        .add-cart-btn:active {
            transform: scale(0.95);
            background: var(--dark-green);
        }
        
        .add-cart-btn:disabled {
            background: #ccc;
            cursor: not-allowed;
            opacity: 0.6;
        }
        
        .view-btn {
            background: #17a2b8;
            border: none;
            color: var(--white);
            width: 24px;
            height: 24px;
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            cursor: pointer;
            padding: 0;
        }
        
        .view-btn:active {
            transform: scale(0.95);
            background: #138496;
        }
        
        /* Unit type buttons - PARA SA DESKTOP/WEB VIEW LANG */
        .unit-buttons {
            display: flex;
            gap: 2px;
            flex-wrap: wrap;
            justify-content: center;
        }
        
        .unit-btn {
            background: var(--white);
            border: 1px solid var(--border-gray);
            border-radius: 3px;
            padding: 2px 3px;
            font-size: 8px;
            font-weight: 600;
            color: var(--black);
            min-width: 22px;
            cursor: pointer;
        }
        
        .unit-btn:active {
            transform: scale(0.95);
            background: var(--light-gray);
        }
        
        .unit-btn.active {
            background: var(--primary-green);
            color: var(--white);
            border-color: var(--primary-green);
        }
        
        .unit-btn.sold-out {
            opacity: 0.4;
            cursor: not-allowed;
            background: var(--light-gray);
            pointer-events: none;
        }
        
        /* Unit dropdown - PARA SA MOBILE VIEW LANG */
        .unit-dropdown {
            display: none; /* Nakatago sa desktop */
            width: 100%;
            padding: 8px 10px;
            border: 1px solid var(--border-gray);
            border-radius: 6px;
            font-size: 13px;
            background: white;
            cursor: pointer;
        }
        
        .unit-dropdown:focus {
            outline: none;
            border-color: var(--primary-green);
        }
        
        .unit-dropdown.sold-out {
            opacity: 0.6;
            background: var(--light-gray);
            cursor: not-allowed;
        }
        
        /* Price Column */
        .price-cell {
            font-weight: 700;
            color: var(--primary-green);
            font-size: 11px;
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        
        .price-unit-label {
            font-size: 7px;
            color: #6c757d;
            font-weight: normal;
            margin-top: 1px;
        }
        
        /* Quantity Controls */
        .quantity-controls {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 2px;
        }
        
        .qty-btn {
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--white);
            border: 1px solid var(--border-gray);
            border-radius: 4px;
            font-size: 12px;
            font-weight: bold;
            color: var(--black);
            cursor: pointer;
            padding: 0;
        }
        
        .qty-btn:active {
            transform: scale(0.95);
            background: var(--light-gray);
        }
        
        .qty-btn:disabled {
            background: var(--light-gray);
            color: #adb5bd;
            cursor: not-allowed;
        }
        
        .qty-input {
            width: 35px;
            height: 24px;
            text-align: center;
            border: 1px solid var(--border-gray);
            border-radius: 4px;
            font-size: 11px;
            padding: 0;
            margin: 0 2px;
            -moz-appearance: textfield;
        }
        
        .qty-input:focus {
            outline: none;
            border-color: var(--primary-green);
        }
        
        /* Desktop/Web at Mobile visibility classes */
        .desktop-only {
            display: flex;
        }
        
        .mobile-only {
            display: none;
        }
        
        /* ===== MOBILE VIEW - UNIT AT QTY SA IISANG COLUMN ===== */
        @media (max-width: 768px) {
            .main-content {
                padding: 8px !important;
            }
            
            .navbar-top {
                padding: 6px 10px;
            }
            
            .page-title h2 {
                font-size: 16px;
            }
            
            .page-title p {
                font-size: 10px;
            }
            
            .category-tabs-container {
                padding: 8px 8px 0 8px;
            }
            
            .tab-btn {
                padding: 5px 12px;
                font-size: 11px;
            }
            
            .search-input {
                padding: 6px 10px 6px 30px;
                font-size: 11px;
            }
            
            /* Mobile table layout - 5 columns lang */
            .product-table th:nth-child(1) { width: 15%; } /* Image */
            .product-table th:nth-child(2) { width: 30%; } /* Product */
            .product-table th:nth-child(3) { width: 30%; } /* Unit (dropdown) */
            .product-table th:nth-child(4) { width: 15%; } /* Price */
            .product-table th:nth-child(5) { width: 15%; } /* Action */
            
            /* Itago ang original Qty column header */
            .product-table th:nth-child(4) { 
                display: table-cell; /* Price column */
            }
            
            /* Itago ang Qty column cells */
            .product-table td:nth-child(4) { 
                display: none; 
            }
            
            /* Desktop elements - itago sa mobile */
            .desktop-only {
                display: none !important;
            }
            
            /* Mobile elements - ipakita sa mobile */
            .mobile-only {
                display: block !important;
            }
            
            /* Unit dropdown sa mobile */
            .unit-dropdown.mobile-only {
                display: block !important;
                width: 100%;
                margin-bottom: 8px;
            }
            
            /* Quantity controls sa mobile - dapat flex */
            .quantity-controls.mobile-only {
                display: flex !important;
                justify-content: center;
                align-items: center;
                gap: 5px;
            }
            
            /* Adjustments for mobile */
            .qty-btn {
                width: 32px;
                height: 32px;
                font-size: 14px;
            }
            
            .qty-input {
                width: 45px;
                height: 32px;
                font-size: 13px;
            }
            
            .product-thumbnail {
                width: 45px;
                height: 45px;
            }
            
            .product-name {
                font-size: 13px;
            }
            
            .stock-info {
                font-size: 10px;
            }
            
            .price-cell {
                font-size: 13px;
            }
            
            .add-cart-btn, .view-btn {
                width: 36px;
                height: 36px;
                font-size: 16px;
            }
        }
        
        /* Desktop view adjustments */
        @media (min-width: 769px) {
            .product-table th:nth-child(1) { width: 6%; }
            .product-table th:nth-child(2) { width: 22%; }
            .product-table th:nth-child(3) { width: 20%; }
            .product-table th:nth-child(4) { width: 12%; }
            .product-table th:nth-child(5) { width: 15%; }
            .product-table th:nth-child(6) { width: 12%; }
            
            .product-thumbnail {
                width: 40px;
                height: 40px;
            }
            
            .product-name {
                font-size: 13px;
            }
            
            .unit-btn {
                padding: 4px 6px;
                font-size: 10px;
                min-width: 32px;
            }
            
            .qty-btn {
                width: 24px;
                height: 24px;
                font-size: 12px;
            }
            
            .qty-input {
                width: 35px;
                height: 24px;
                font-size: 11px;
            }
            
            .add-cart-btn, .view-btn {
                width: 28px;
                height: 28px;
                font-size: 14px;
            }
        }
        
        /* Toast notification */
        .toast-notification {
            position: fixed;
            top: 20px;
            right: 20px;
            left: 20px;
            background: var(--primary-green);
            color: var(--white);
            padding: 12px 20px;
            border-radius: 8px;
            z-index: 9999;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            max-width: 300px;
            margin: 0 auto;
            text-align: center;
            font-size: 14px;
            opacity: 1;
            transition: opacity 0.3s ease;
        }
        
        .toast-notification.fade-out {
            opacity: 0;
        }
        
        /* Navbar Top */
        .navbar-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            padding: 12px 15px;
            margin-bottom: 15px;
            background: var(--white);
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        
        .page-title {
            flex: 1;
        }
        
        .page-title h2 {
            margin: 0;
            font-size: 20px;
            font-weight: 600;
            color: var(--black);
        }
        
        .page-title p {
            margin: 3px 0 0 0;
            color: #666;
            font-size: 12px;
        }
        
        .mobile-toggle-btn {
            display: none;
            background: none;
            border: none;
            font-size: 22px;
            color: var(--primary-green);
            padding: 8px;
            border-radius: 8px;
            width: 40px;
            height: 40px;
            align-items: center;
            justify-content: center;
            cursor: pointer;
        }
        
        @media (max-width: 992px) {
            .mobile-toggle-btn {
                display: flex;
            }
            
            .tabs-header {
                flex-direction: column;
                align-items: stretch;
            }
            
            .search-wrapper {
                width: 100%;
            }
        }
        
        /* No results */
        .no-results {
            text-align: center;
            padding: 40px;
            background: var(--white);
            border-radius: 10px;
            color: #666;
        }
        
        .no-results i {
            font-size: 48px;
            color: var(--border-gray);
            margin-bottom: 10px;
        }

        /* Modal Styles */
        .modal-header {
            background: var(--primary-green);
            color: var(--white);
            border: none;
            padding: 12px 15px;
        }
        
        .modal-header .btn-close {
            filter: brightness(0) invert(1);
            opacity: 0.8;
            width: 28px;
            height: 28px;
            font-size: 14px;
            cursor: pointer;
        }
        
        /* Customer Selection */
        .customer-selection {
            background: var(--light-gray);
            border-radius: 8px;
            padding: 12px;
            margin-bottom: 15px;
            border-left: 4px solid var(--primary-green);
        }
        
        .customer-selection .form-select {
            border: 1px solid var(--border-gray);
            border-radius: 6px;
            padding: 8px 10px;
            font-size: 13px;
            height: auto;
            cursor: pointer;
        }
        
        /* Product Info Modal */
        .product-info-container {
            padding: 15px;
        }
        
        .product-header-section {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
            background: var(--light-gray);
            padding: 15px;
            border-radius: 10px;
        }
        
        .product-image-large {
            width: 120px;
            height: 120px;
            object-fit: cover;
            border-radius: 10px;
            border: 2px solid var(--primary-green);
            background: var(--white);
            padding: 3px;
        }
        
        .info-row {
            display: flex;
            align-items: baseline;
            padding: 8px 0;
            border-bottom: 1px dashed var(--border-gray);
            font-size: 13px;
        }
        
        .info-label {
            width: 100px;
            font-weight: 600;
            color: var(--black);
        }
        
        .info-value {
            flex: 1;
            color: var(--primary-green);
            font-weight: 600;
        }
        
        .price-tag {
            font-size: 20px;
            font-weight: 700;
        }
        
        .stock-tag {
            background: var(--primary-green);
            color: var(--white);
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }
        
        .history-table {
            width: 100%;
            font-size: 12px;
            border-collapse: collapse;
        }
        
        .history-table th {
            background: var(--primary-green);
            color: var(--white);
            padding: 8px 5px;
            font-size: 11px;
        }
        
        .history-table td {
            padding: 6px 5px;
            border-bottom: 1px solid var(--border-gray);
            text-align: center;
        }
        
        .status-badge {
            padding: 3px 8px;
            border-radius: 15px;
            font-size: 10px;
            font-weight: 600;
            display: inline-block;
        }
        
        .status-pending { background: #ffc107; color: var(--black); }
        .status-completed { background: var(--primary-green); color: var(--white); }
        .status-cancelled { background: #dc3545; color: var(--white); }
        
        .loading-state {
            text-align: center;
            padding: 40px;
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
                    <p>Pindutin ang 👁️ para makita ang details</p>
                </div>
                <button class="btn btn-success position-relative" type="button" onclick="viewCartSummary()" title="View Cart">
                    <i class="bi bi-cart3"></i>
                    <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill" id="cartBadge" style="display: none;">
                        <span id="cartItemCount">0</span>
                    </span>
                </button>
            </div>

            <!-- Branch Info Alerts -->
            <?php if (!$items_branch_column_exists): ?>
                <div class="alert alert-info alert-dismissible fade show" role="alert">
                    <i class="bi bi-info-circle"></i> 
                    <strong>Branch filtering for products not yet set up.</strong>
                    <button type="button" class="btn btn-sm btn-primary mt-2" onclick="copyItemsSQL()">
                        <i class="bi bi-files"></i> Copy SQL
                    </button>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if (!$branch_column_exists): ?>
                <div class="alert alert-info alert-dismissible fade show" role="alert">
                    <i class="bi bi-info-circle"></i> 
                    <strong>Branch filtering for customers not yet set up.</strong>
                    <button type="button" class="btn btn-sm btn-primary mt-2" onclick="copyCustomersSQL()">
                        <i class="bi bi-files"></i> Copy SQL
                    </button>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Category Tabs with Search -->
            <div class="category-tabs-container">
                <div class="tabs-header">
                    <div class="tabs-scroll">
                        <div class="category-tabs" id="categoryTabs">
                            <button class="tab-btn active" onclick="filterByCategory('all')">All Products</button>
                            <?php foreach ($categories as $category): ?>
                                <button class="tab-btn" onclick="filterByCategory('<?php echo htmlspecialchars($category); ?>')">
                                    <?php echo htmlspecialchars($category); ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="search-wrapper">
                        <i class="bi bi-search search-icon"></i>
                        <input type="text" class="search-input" id="searchInput" placeholder="Search products...">
                        <button class="search-reset" id="searchReset" onclick="resetSearch()"><i class="bi bi-x"></i></button>
                    </div>
                </div>
            </div>

            <!-- Products Section - Isang table lang -->
            <div class="col-12 products-section">
                <div class="product-table-container">
                    <table class="product-table" id="productsTable">
                        <thead>
                            <tr>
                                <th></th> <!-- Image -->
                                <th>Product</th>
                                <th>Unit</th>
                                <th>Qty</th>
                                <th>Price</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody id="productsContainer">
                            <!-- Products will be loaded here -->
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Product Info Modal -->
    <div class="modal fade" id="productInfoModal" tabindex="-1">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-box-seam me-2"></i><span id="modalProductName">Product Details</span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-0">
                    <div class="product-info-container">
                        <div id="loadingState" class="loading-state">
                            <div class="spinner-border text-success mb-3" role="status">
                                <span class="visually-hidden">Loading...</span>
                            </div>
                            <p>Loading product details...</p>
                        </div>
                        
                        <div id="productContent" style="display: none;">
                            <div class="product-header-section">
                                <img id="modalProductImage" src="" alt="Product Image" class="product-image-large">
                                <div class="product-basic-info">
                                    <div class="info-row">
                                        <span class="info-label">Code:</span>
                                        <span class="info-value" id="modalProductCode">-</span>
                                    </div>
                                    <div class="info-row">
                                        <span class="info-label">Category:</span>
                                        <span class="info-value" id="modalProductCategory">-</span>
                                    </div>
                                    <div class="info-row">
                                        <span class="info-label">Price:</span>
                                        <span class="info-value price-tag" id="modalProductPrice">₱0.00</span>
                                    </div>
                                    <div class="info-row">
                                        <span class="info-label">Stock:</span>
                                        <span class="info-value"><span class="stock-tag" id="modalProductStock">0 pcs</span></span>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="px-3 mb-3">
                                <h6 class="fw-bold text-success">Description</h6>
                                <p class="text-muted" id="modalProductDescription">-</p>
                            </div>
                            
                            <h6 class="fw-bold text-success px-3"><i class="bi bi-clock-history"></i> Order History</h6>
                            <div class="table-responsive px-3 pb-3">
                                <table class="history-table">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Order #</th>
                                            <th>Customer</th>
                                            <th>Unit</th>
                                            <th>Qty</th>
                                            <th>Price</th>
                                            <th>Total</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody id="modalOrderHistory">
                                        <tr><td colspan="8" class="text-center">No order history</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Cart Summary Modal -->
    <div class="modal fade" id="cartSummaryModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-cart3"></i> Order Summary</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="cartModalItems" class="mb-3">
                        <p class="text-muted text-center">No items in cart</p>
                    </div>
                    <hr>
                    <div class="mb-2 d-flex justify-content-between">
                        <span><strong>Subtotal:</strong></span>
                        <span id="cartModalSubtotal">₱0.00</span>
                    </div>
                    <div class="mb-3 d-flex justify-content-between">
                        <span><strong>Total Items:</strong></span>
                        <span id="cartModalTotalItems">0</span>
                    </div>
                    <hr>
                    <div class="mb-4">
                        <h6 class="mb-2"><strong>Total</strong></h6>
                        <h3 id="cartModalTotalPrice" class="mb-0 text-success">₱0.00</h3>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-success" onclick="viewCart()">View & Confirm</button>
                    <button type="button" class="btn btn-outline-danger" onclick="clearCart()">Clear Cart</button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Cart Modal (Review) -->
    <div class="modal fade" id="cartModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-cart-check"></i> Review & Confirm Order</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="customer-selection">
                        <h6><i class="bi bi-person-check"></i> Select Customer</h6>
                        <select class="form-select" id="modalCustomerSelect">
                            <option value="">-- Choose Customer --</option>
                            <?php foreach ($customers as $customer): ?>
                                <option value="<?php echo $customer['customer_id']; ?>" 
                                        data-email="<?php echo htmlspecialchars($customer['email'] ?? ''); ?>"
                                        data-phone="<?php echo htmlspecialchars($customer['phone_number'] ?? ''); ?>"
                                        data-address="<?php echo htmlspecialchars($customer['address'] ?? ''); ?>">
                                    <?php echo htmlspecialchars($customer['customer_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <h6 class="mb-3">Order Items</h6>
                    <div id="reviewItems" class="mb-4"></div>

                    <h6 class="mb-3">Delivery Information</h6>
                    <div class="alert bg-light">
                        <p class="mb-2"><strong>Customer:</strong> <span id="reviewCustomer">-</span></p>
                        <p class="mb-2"><strong>Email:</strong> <span id="reviewEmail">-</span></p>
                        <p class="mb-2"><strong>Phone:</strong> <span id="reviewPhone">-</span></p>
                        <p class="mb-0"><strong>Address:</strong> <span id="reviewAddress">-</span></p>
                    </div>

                    <h6 class="mb-3">Order Total</h6>
                    <div class="alert bg-light">
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
                    <button type="button" class="btn btn-success" id="confirmOrderBtn" onclick="submitOrder()">Confirm & Submit</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Success Modal -->
    <div class="modal fade" id="successModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Success!</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center py-4">
                    <div class="display-4 text-success mb-3">✓</div>
                    <h5 class="mb-3">Order Submitted Successfully!</h5>
                    <div class="bg-light p-3 rounded">
                        <p class="mb-2"><strong>Order #:</strong> <span id="successSoNumber">-</span></p>
                        <p class="mb-2"><strong>Date:</strong> <span id="successOrderDate">-</span></p>
                        <p class="mb-0"><strong>Branch:</strong> <span id="successBranch">Branch <?php echo $branch_id; ?></span></p>
                    </div>
                </div>
                <div class="modal-footer justify-content-center">
                    <button type="button" class="btn btn-primary" onclick="createNewOrder()">New Order</button>
                    <button type="button" class="btn btn-outline-secondary" onclick="viewOrders()">View Orders</button>
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Copy SQL functions
        function copyItemsSQL() {
            const sql = "ALTER TABLE items ADD COLUMN branch_id INT NULL;\nALTER TABLE items ADD FOREIGN KEY (branch_id) REFERENCES branches(branch_id);";
            navigator.clipboard.writeText(sql).then(() => alert('SQL copied!'));
        }
        function copyCustomersSQL() {
            const sql = "ALTER TABLE customers ADD COLUMN branch_id INT NULL;\nALTER TABLE customers ADD FOREIGN KEY (branch_id) REFERENCES branches(branch_id);";
            navigator.clipboard.writeText(sql).then(() => alert('SQL copied!'));
        }

        // Unit conversions
        const UNIT_CONVERSIONS = {
            'piece': 1, 'case': 12, 'inner-pack': 6, 'box': 24, 'carton': 48
        };

        // Inventory data
        const inventory = <?php echo json_encode(array_map(function($item) {
            return [
                'id' => (int)$item['item_id'],
                'name' => $item['item_name'],
                'sku' => $item['item_code'],
                'category' => !empty($item['category']) ? $item['category'] : 'Uncategorized',
                'unit_price' => (float)$item['unit_price'],
                'price_case' => isset($item['price_case']) ? (float)$item['price_case'] : null,
                'price_inner_pack' => isset($item['price_inner_pack']) ? (float)$item['price_inner_pack'] : null,
                'price_box' => isset($item['price_box']) ? (float)$item['price_box'] : null,
                'price_carton' => isset($item['price_carton']) ? (float)$item['price_carton'] : null,
                'stock' => (int)($item['stock'] ?? 0),
                'image' => $item['product_image_url'] ?? null
            ];
        }, $items)); ?>;

        const branchId = <?php echo $branch_id; ?>;
        let cart = [];
        let activeUnitTypes = {};
        let toastTimeout = null;
        let currentFilter = 'all';
        let searchTerm = '';

        // Initialize
        function init() {
            renderProducts();
            updateCartBadge();
            setupSearch();
        }

        function setupSearch() {
            const searchInput = document.getElementById('searchInput');
            const resetBtn = document.getElementById('searchReset');
            
            searchInput.addEventListener('input', function() {
                searchTerm = this.value.toLowerCase();
                resetBtn.classList.toggle('visible', searchTerm.length > 0);
                filterProducts();
            });
        }

        function resetSearch() {
            const searchInput = document.getElementById('searchInput');
            const resetBtn = document.getElementById('searchReset');
            searchInput.value = '';
            searchTerm = '';
            resetBtn.classList.remove('visible');
            filterProducts();
        }

        function filterByCategory(category) {
            currentFilter = category;
            
            // Update active tab
            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            event.target.classList.add('active');
            
            filterProducts();
        }

        function filterProducts() {
            const container = document.getElementById('productsContainer');
            if (!container) return;
            
            const filtered = inventory.filter(product => {
                if (currentFilter !== 'all' && product.category !== currentFilter) {
                    return false;
                }
                if (searchTerm) {
                    return product.name.toLowerCase().includes(searchTerm) || 
                           product.sku.toLowerCase().includes(searchTerm);
                }
                return true;
            });
            
            if (filtered.length === 0) {
                container.innerHTML = `
                    <tr>
                        <td colspan="6" class="text-center p-4">
                            <i class="bi bi-search fs-1 d-block mb-2" style="color: #ccc;"></i>
                            <p class="text-muted">No products found matching your criteria</p>
                        </td>
                    </tr>
                `;
                return;
            }
            
            renderFilteredProducts(filtered);
        }

        function renderFilteredProducts(filteredInventory) {
            const container = document.getElementById('productsContainer');
            
            let html = '';
            filteredInventory.forEach(p => {
                const avail = getAvailableStock(p.id);
                const low = avail > 0 && avail < 50;
                const out = avail === 0;
                
                if (!activeUnitTypes[p.id]) activeUnitTypes[p.id] = 'piece';
                
                const placeholder = 'data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%2240%22 height=%2240%22%3E%3Crect fill=%22%23e0e0e0%22 width=%2240%22 height=%2240%22/%3E%3Ctext x=%2250%25%22 y=%2250%25%22 text-anchor=%22middle%22 dy=%22.3em%22 fill=%22%23999%22 font-size=%229%22%3ENo%3C/text%3E%3C/svg%3E';
                const img = p.image ? '../uploads/products/' + p.image : placeholder;
                
                let currPrice = p.unit_price, currUnit = 'piece';
                const currType = activeUnitTypes[p.id];
                if (currType === 'case' && p.price_case) { currPrice = p.price_case; currUnit = 'case'; }
                else if (currType === 'inner-pack' && p.price_inner_pack) { currPrice = p.price_inner_pack; currUnit = 'inner-pack'; }
                else if (currType === 'box' && p.price_box) { currPrice = p.price_box; currUnit = 'box'; }
                else if (currType === 'carton' && p.price_carton) { currPrice = p.price_carton; currUnit = 'carton'; }
                
                // Build unit buttons HTML (para sa desktop)
                let unitButtonsHtml = '';
                // Build unit dropdown options (para sa mobile)
                let unitDropdownOptions = '';
                
                const opts = [
                    { type: 'piece', label: 'PC', fullLabel: 'Piece', avail: true },
                    { type: 'inner-pack', label: 'IP', fullLabel: 'Inner Pack', avail: p.price_inner_pack !== null },
                    { type: 'case', label: 'CS', fullLabel: 'Case', avail: p.price_case !== null },
                    { type: 'box', label: 'BX', fullLabel: 'Box', avail: p.price_box !== null },
                    { type: 'carton', label: 'CTN', fullLabel: 'Carton', avail: p.price_carton !== null }
                ];
                
                opts.forEach(o => {
                    if (o.avail) {
                        const sold = avail < UNIT_CONVERSIONS[o.type];
                        // Buttons para sa desktop
                        unitButtonsHtml += `<button class="unit-btn ${activeUnitTypes[p.id] === o.type ? 'active' : ''} ${sold ? 'sold-out' : ''}" data-product-id="${p.id}" data-unit-type="${o.type}" onclick="setActiveUnit(${p.id}, '${o.type}')" ${sold ? 'disabled' : ''}>${o.label}</button>`;
                        
                        // Options para sa mobile dropdown
                        unitDropdownOptions += `<option value="${o.type}" ${activeUnitTypes[p.id] === o.type ? 'selected' : ''} ${sold ? 'disabled' : ''}>${o.fullLabel} (${o.label})</option>`;
                    }
                });
                
                html += `<tr id="row-${p.id}">
                    <td class="product-image-cell"><img src="${img}" class="product-thumbnail" onerror="this.src='${placeholder}'"></td>
                    <td>
                        <div class="product-info">
                            <span class="product-name">${p.name}</span>
                            <span id="stock-${p.id}" class="${low && !out ? 'stock-warning' : 'stock-info'}">Stock: ${avail} pcs</span>
                        </div>
                    </td>
                    <td class="unit-column">
                        <!-- Desktop: Unit buttons lang -->
                        <div class="unit-buttons desktop-only">
                            ${unitButtonsHtml}
                        </div>
                        <!-- Mobile: Unit dropdown LANG -->
                        <select class="unit-dropdown mobile-only" id="unit-dropdown-${p.id}" onchange="setActiveUnitFromDropdown(${p.id}, this.value)">
                            ${unitDropdownOptions}
                        </select>
                    </td>
                    <td class="qty-column">
                        <!-- Desktop: Quantity controls lang -->
                        <div class="quantity-controls desktop-only">
                            <button class="qty-btn" onclick="decQty(${p.id})" ${out ? 'disabled' : ''}><i class="bi bi-dash"></i></button>
                            <input type="number" class="qty-input" id="qty-desktop-${p.id}" min="0" value="0" onchange="validateQuantityDesktop(${p.id})" ${out ? 'disabled' : ''}>
                            <button class="qty-btn" onclick="incQty(${p.id})" ${out ? 'disabled' : ''}><i class="bi bi-plus"></i></button>
                        </div>
                        <!-- Mobile: Quantity controls lang -->
                        <div class="quantity-controls mobile-only">
                            <button class="qty-btn" onclick="decQty(${p.id})" ${out ? 'disabled' : ''}><i class="bi bi-dash"></i></button>
                            <input type="number" class="qty-input" id="qty-${p.id}" min="0" value="0" onchange="validateQuantity(${p.id})" ${out ? 'disabled' : ''}>
                            <button class="qty-btn" onclick="incQty(${p.id})" ${out ? 'disabled' : ''}><i class="bi bi-plus"></i></button>
                        </div>
                    </td>
                    <td>
                        <div class="price-cell" id="price-display-${p.id}">
                            <span class="price-value">₱${currPrice.toFixed(0)}</span>
                            <span class="price-unit-label">/${currUnit}</span>
                        </div>
                    </td>
                    <td>
                        <div class="action-buttons">
                            <button class="add-cart-btn" id="add-btn-${p.id}" onclick="addToCart(${p.id})" ${out ? 'disabled' : ''}><i class="bi bi-cart-plus-fill"></i></button>
                            <button class="view-btn" onclick="showProductInfo(${p.id})"><i class="bi bi-eye-fill"></i></button>
                        </div>
                    </td>
                </tr>`;
            });
            
            container.innerHTML = html;
        }

        function setActiveUnitFromDropdown(pid, type) {
            activeUnitTypes[pid] = type;
            renderProducts(); // Re-render to update UI
        }

        function validateQuantityDesktop(pid) {
            const desktopInp = document.getElementById(`qty-desktop-${pid}`);
            if (!desktopInp) return 0;
            let v = parseInt(desktopInp.value) || 0;
            if (v < 0) v = 0;
            desktopInp.value = v;
            
            // Sync mobile qty
            const mobileInp = document.getElementById(`qty-${pid}`);
            if (mobileInp) mobileInp.value = v;
            
            return v;
        }

        function renderProducts() {
            filterProducts();
        }

        function getAvailableStock(productId) {
            const p = inventory.find(p => p.id === productId);
            if (!p) return 0;
            const inCart = cart.filter(i => i.id === productId).reduce((t, i) => t + (i.quantity * UNIT_CONVERSIONS[i.unit_type]), 0);
            return Math.max(0, p.stock - inCart);
        }

        function getProductById(id) {
            return inventory.find(p => p.id === id);
        }

        function setActiveUnit(pid, type) {
            activeUnitTypes[pid] = type;
            renderProducts(); // Re-render to update UI
        }

        function validateQuantity(pid) {
            const inp = document.getElementById(`qty-${pid}`);
            if (!inp) return 0;
            let v = parseInt(inp.value) || 0;
            if (v < 0) v = 0;
            inp.value = v;
            
            // Sync desktop qty
            const desktopInp = document.getElementById(`qty-desktop-${pid}`);
            if (desktopInp) desktopInp.value = v;
            
            return v;
        }

        function incQty(pid) {
            const inp = document.getElementById(`qty-${pid}`);
            if (inp) {
                inp.value = (parseInt(inp.value) || 0) + 1;
                
                // Sync desktop qty
                const desktopInp = document.getElementById(`qty-desktop-${pid}`);
                if (desktopInp) desktopInp.value = inp.value;
            }
        }

        function decQty(pid) {
            const inp = document.getElementById(`qty-${pid}`);
            if (inp) {
                let v = parseInt(inp.value) || 0;
                if (v > 0) inp.value = v - 1;
                
                // Sync desktop qty
                const desktopInp = document.getElementById(`qty-desktop-${pid}`);
                if (desktopInp) desktopInp.value = inp.value;
            }
        }

        function addToCart(pid) {
            console.log('addToCart', pid);
            const p = getProductById(pid);
            if (!p) return;
            
            const type = activeUnitTypes[pid] || 'piece';
            const qty = parseInt(document.getElementById(`qty-${pid}`)?.value) || 0;
            
            if (qty <= 0) {
                showToast('Please enter quantity');
                return;
            }
            
            const needed = qty * UNIT_CONVERSIONS[type];
            const avail = getAvailableStock(pid);
            
            if (needed > avail) {
                showToast(`Only ${avail} pieces available`);
                return;
            }
            
            let price = p.unit_price;
            if (type === 'case' && p.price_case) price = p.price_case;
            else if (type === 'inner-pack' && p.price_inner_pack) price = p.price_inner_pack;
            else if (type === 'box' && p.price_box) price = p.price_box;
            else if (type === 'carton' && p.price_carton) price = p.price_carton;
            
            const existing = cart.find(i => i.id === pid && i.unit_type === type);
            if (existing) {
                existing.quantity += qty;
            } else {
                cart.push({ id: pid, name: p.name, price, quantity: qty, sku: p.sku, unit_type: type });
            }
            
            document.getElementById(`qty-${pid}`).value = '0';
            const desktopInp = document.getElementById(`qty-desktop-${pid}`);
            if (desktopInp) desktopInp.value = '0';
            
            updateCartBadge();
            renderProducts(); // Re-render to update stock display
            showToast('Added to cart!');
        }

        function showProductInfo(pid) {
            console.log('showProductInfo', pid);
            document.getElementById('loadingState').style.display = 'block';
            document.getElementById('productContent').style.display = 'none';
            
            const modal = new bootstrap.Modal(document.getElementById('productInfoModal'));
            modal.show();
            
            const fd = new FormData();
            fd.append('action', 'get_product_details');
            fd.append('product_id', pid);
            
            fetch(window.location.href, { 
                method: 'POST', 
                body: fd,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(r => r.text())
            .then(text => {
                if (text.trim().startsWith('<')) throw new Error('Server error');
                return JSON.parse(text);
            })
            .then(data => {
                if (data.success) {
                    const p = data.product;
                    document.getElementById('modalProductName').textContent = p.item_name || 'Product';
                    
                    const placeholder = 'data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%22120%22 height=%22120%22%3E%3Crect fill=%22%23e0e0e0%22 width=%22120%22 height=%22120%22/%3E%3Ctext x=%2250%25%22 y=%2250%25%22 text-anchor=%22middle%22 dy=%22.3em%22 fill=%22%23999%22 font-size=%2212%22%3ENo Image%3C/text%3E%3C/svg%3E';
                    document.getElementById('modalProductImage').src = p.product_image_url ? '../uploads/products/' + p.product_image_url : placeholder;
                    
                    document.getElementById('modalProductCode').textContent = p.item_code || '-';
                    document.getElementById('modalProductCategory').textContent = p.category || '-';
                    document.getElementById('modalProductDescription').textContent = p.description || '-';
                    document.getElementById('modalProductPrice').textContent = `₱${parseFloat(p.unit_price || 0).toFixed(2)}`;
                    document.getElementById('modalProductStock').textContent = `${p.stock || 0} pcs`;
                    
                    let histHtml = '';
                    if (data.order_history && data.order_history.length) {
                        data.order_history.forEach(o => {
                            const d = new Date(o.order_date).toLocaleDateString();
                            const sc = o.order_status === 'pending' ? 'status-pending' : (o.order_status === 'cancelled' ? 'status-cancelled' : 'status-completed');
                            histHtml += `<tr><td>${d}</td><td>${o.so_number}</td><td>${o.customer_name}</td><td>${o.unit_type}</td><td>${o.quantity_ordered}</td><td>₱${parseFloat(o.unit_price).toFixed(2)}</td><td>₱${parseFloat(o.total_price).toFixed(2)}</td><td><span class="status-badge ${sc}">${o.order_status}</span></td></tr>`;
                        });
                    } else {
                        histHtml = '<tr><td colspan="8" class="text-center">No history</td></tr>';
                    }
                    document.getElementById('modalOrderHistory').innerHTML = histHtml;
                    
                    document.getElementById('loadingState').style.display = 'none';
                    document.getElementById('productContent').style.display = 'block';
                } else {
                    showToast('Error: ' + data.message);
                    modal.hide();
                }
            })
            .catch(e => {
                console.error('Error:', e);
                showToast('Error loading details');
                modal.hide();
            });
        }

        function updateCartBadge() {
            const badge = document.getElementById('cartBadge');
            const countSpan = document.getElementById('cartItemCount');
            const total = cart.reduce((s, i) => s + i.quantity, 0);
            
            if (badge && countSpan) {
                if (total > 0) {
                    countSpan.textContent = total;
                    badge.style.display = 'inline-block';
                } else {
                    badge.style.display = 'none';
                    countSpan.textContent = '0';
                }
            }
            
            const itemsDiv = document.getElementById('cartModalItems');
            if (itemsDiv) {
                if (cart.length === 0) {
                    itemsDiv.innerHTML = '<p class="text-muted text-center">No items in cart</p>';
                } else {
                    let html = '';
                    cart.forEach(i => {
                        const pieces = i.quantity * UNIT_CONVERSIONS[i.unit_type];
                        html += `<div class="cart-item">
                            <div>
                                <div class="fw-bold">${i.name} (${i.unit_type})</div>
                                <div class="text-muted small">${i.sku}</div>
                                <div class="text-muted small">₱${i.price.toFixed(2)} × ${i.quantity}</div>
                                <div class="text-muted small">Total pieces: ${pieces}</div>
                            </div>
                            <div class="text-end">
                                <div class="fw-bold text-success">₱${(i.price * i.quantity).toFixed(2)}</div>
                                <button class="btn btn-sm btn-outline-danger mt-1" onclick="removeFromCart(${i.id}, '${i.unit_type}')">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </div>`;
                    });
                    itemsDiv.innerHTML = html;
                }
            }
            
            const subtotal = cart.reduce((s, i) => s + i.price * i.quantity, 0);
            const totalQty = cart.reduce((s, i) => s + i.quantity, 0);
            
            const subtotalEl = document.getElementById('cartModalSubtotal');
            const totalItemsEl = document.getElementById('cartModalTotalItems');
            const totalPriceEl = document.getElementById('cartModalTotalPrice');
            
            if (subtotalEl) subtotalEl.textContent = `₱${subtotal.toFixed(2)}`;
            if (totalItemsEl) totalItemsEl.textContent = totalQty;
            if (totalPriceEl) totalPriceEl.textContent = `₱${subtotal.toFixed(2)}`;
        }

        function clearCart() {
            if (cart.length === 0) {
                showToast('Cart is already empty');
                return;
            }
            
            if (confirm('Clear all items from cart?')) {
                cart = [];
                updateCartBadge();
                
                const reviewItems = document.getElementById('reviewItems');
                if (reviewItems) {
                    reviewItems.innerHTML = '<p class="text-muted text-center">No items in cart</p>';
                }
                
                document.getElementById('reviewSubtotal').textContent = '₱0.00';
                document.getElementById('reviewTotal').textContent = '₱0.00';
                document.getElementById('reviewCustomer').textContent = '-';
                document.getElementById('reviewEmail').textContent = '-';
                document.getElementById('reviewPhone').textContent = '-';
                document.getElementById('reviewAddress').textContent = '-';
                
                const customerSelect = document.getElementById('modalCustomerSelect');
                if (customerSelect) customerSelect.value = '';
                
                showToast('Cart cleared successfully');
                
                const cartSummaryModal = bootstrap.Modal.getInstance(document.getElementById('cartSummaryModal'));
                if (cartSummaryModal) cartSummaryModal.hide();
                
                const cartModal = bootstrap.Modal.getInstance(document.getElementById('cartModal'));
                if (cartModal) cartModal.hide();
                
                renderProducts();
            }
        }

        function removeFromCart(id, unit) {
            cart = cart.filter(i => !(i.id === id && i.unit_type === unit));
            updateCartBadge();
            
            const reviewItems = document.getElementById('reviewItems');
            if (reviewItems && cart.length > 0) {
                let html = '<div class="table-responsive"><table class="table table-sm"><thead class="table-light"><tr><th>Product</th><th>Unit</th><th>Qty</th><th>Price</th><th>Total</th></tr></thead><tbody>';
                cart.forEach(i => {
                    const pieces = i.quantity * UNIT_CONVERSIONS[i.unit_type];
                    html += `<tr><td>${i.name}</td><td>${i.unit_type}</td><td>${i.quantity} (${pieces} pcs)</td><td>₱${i.price.toFixed(2)}</td><td>₱${(i.price * i.quantity).toFixed(2)}</td></tr>`;
                });
                html += '</tbody></table></div>';
                reviewItems.innerHTML = html;
            } else if (reviewItems) {
                reviewItems.innerHTML = '<p class="text-muted text-center">No items in cart</p>';
            }
            
            const subtotal = cart.reduce((s, i) => s + i.price * i.quantity, 0);
            document.getElementById('reviewSubtotal').textContent = `₱${subtotal.toFixed(2)}`;
            document.getElementById('reviewTotal').textContent = `₱${subtotal.toFixed(2)}`;
            
            showToast('Item removed from cart');
            renderProducts();
        }

        function showToast(msg) {
            if (toastTimeout) clearTimeout(toastTimeout);
            
            const existing = document.querySelector('.toast-notification');
            if (existing) existing.remove();
            
            const toast = document.createElement('div');
            toast.className = 'toast-notification';
            toast.innerHTML = `<i class="bi bi-check-circle-fill me-2"></i>${msg}`;
            document.body.appendChild(toast);
            
            toastTimeout = setTimeout(() => {
                toast.classList.add('fade-out');
                setTimeout(() => toast.remove(), 300);
            }, 2000);
        }

        function viewCartSummary() {
            updateCartBadge();
            new bootstrap.Modal(document.getElementById('cartSummaryModal')).show();
        }

        function viewCart() {
            if (!cart.length) {
                showToast('Cart is empty');
                return;
            }
            
            bootstrap.Modal.getInstance(document.getElementById('cartSummaryModal'))?.hide();
            
            document.getElementById('modalCustomerSelect').value = '';
            document.getElementById('reviewCustomer').textContent = '-';
            document.getElementById('reviewEmail').textContent = '-';
            document.getElementById('reviewPhone').textContent = '-';
            document.getElementById('reviewAddress').textContent = '-';
            
            const reviewDiv = document.getElementById('reviewItems');
            let html = '<div class="table-responsive"><table class="table table-sm"><thead class="table-light"><tr><th>Product</th><th>Unit</th><th>Qty</th><th>Price</th><th>Total</th></tr></thead><tbody>';
            cart.forEach(i => {
                const pieces = i.quantity * UNIT_CONVERSIONS[i.unit_type];
                html += `<tr><td>${i.name}</td><td>${i.unit_type}</td><td>${i.quantity} (${pieces} pcs)</td><td>₱${i.price.toFixed(2)}</td><td>₱${(i.price * i.quantity).toFixed(2)}</td></tr>`;
            });
            html += '</tbody></table></div>';
            reviewDiv.innerHTML = html;
            
            const subtotal = cart.reduce((s, i) => s + i.price * i.quantity, 0);
            document.getElementById('reviewSubtotal').textContent = `₱${subtotal.toFixed(2)}`;
            document.getElementById('reviewTotal').textContent = `₱${subtotal.toFixed(2)}`;
            
            const select = document.getElementById('modalCustomerSelect');
            if (select) {
                const newSelect = select.cloneNode(true);
                select.parentNode.replaceChild(newSelect, select);
                
                newSelect.addEventListener('change', function() {
                    const opt = this.options[this.selectedIndex];
                    if (opt.value) {
                        document.getElementById('reviewCustomer').textContent = opt.text.split('(')[0].trim();
                        document.getElementById('reviewEmail').textContent = opt.dataset.email || '-';
                        document.getElementById('reviewPhone').textContent = opt.dataset.phone || '-';
                        document.getElementById('reviewAddress').textContent = opt.dataset.address || '-';
                    } else {
                        document.getElementById('reviewCustomer').textContent = '-';
                        document.getElementById('reviewEmail').textContent = '-';
                        document.getElementById('reviewPhone').textContent = '-';
                        document.getElementById('reviewAddress').textContent = '-';
                    }
                });
            }
            
            new bootstrap.Modal(document.getElementById('cartModal')).show();
        }

        function submitOrder() {
            const select = document.getElementById('modalCustomerSelect');
            const custId = select?.value ? parseInt(select.value) : 0;
            const opt = select?.options[select.selectedIndex];
            
            if (!custId) {
                showToast('Please select a customer');
                return;
            }
            
            const items = cart.map(i => ({
                id: i.id, name: i.name, price: i.price, quantity: i.quantity, sku: i.sku, unit_type: i.unit_type
            }));
            
            const fd = new FormData();
            fd.append('action', 'submit_order');
            fd.append('customer_id', custId);
            fd.append('customer_name', opt.text.split('(')[0].trim());
            fd.append('email', opt.dataset.email || '');
            fd.append('phone', opt.dataset.phone || '');
            fd.append('address', opt.dataset.address || '');
            fd.append('items', JSON.stringify(items));
            
            const btn = document.getElementById('confirmOrderBtn');
            const orig = btn.innerHTML;
            btn.innerHTML = 'Processing...';
            btn.disabled = true;
            
            fetch(window.location.href, { 
                method: 'POST', 
                body: fd,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(r => r.text())
            .then(t => {
                if (!t || t.trim().startsWith('<')) throw new Error('Invalid response');
                return JSON.parse(t);
            })
            .then(d => {
                bootstrap.Modal.getInstance(document.getElementById('cartModal'))?.hide();
                
                if (d.success) {
                    if (d.updated_stock) {
                        d.updated_stock.forEach(i => {
                            const p = inventory.find(p => p.id === i.item_id);
                            if (p) p.stock = i.stock;
                        });
                    }
                    cart = [];
                    updateCartBadge();
                    
                    document.getElementById('successSoNumber').textContent = d.so_number;
                    document.getElementById('successOrderDate').textContent = new Date().toLocaleDateString();
                    document.getElementById('successBranch').textContent = `Branch ${branchId}`;
                    
                    new bootstrap.Modal(document.getElementById('successModal')).show();
                    if (select) select.value = '';
                    
                    renderProducts();
                } else {
                    showToast('Error: ' + (d.message || 'Failed'));
                }
                btn.innerHTML = orig;
                btn.disabled = false;
            })
            .catch(e => {
                console.error('Submit error:', e);
                showToast('Error: ' + e.message);
                btn.innerHTML = orig;
                btn.disabled = false;
            });
        }

        // Sidebar functions
        function toggleSidebar() {
            const s = document.getElementById('sidebar');
            if (window.innerWidth <= 992) {
                s.classList.toggle('active');
                if (!document.querySelector('.sidebar-overlay')) {
                    const o = document.createElement('div');
                    o.className = 'sidebar-overlay';
                    document.body.appendChild(o);
                    o.addEventListener('click', closeMobileSidebar);
                    setTimeout(() => o.classList.add('active'), 10);
                }
            } else {
                s.classList.toggle('collapsed');
                localStorage.setItem('sidebarCollapsed', s.classList.contains('collapsed'));
                document.querySelectorAll('.nav-text').forEach(t => t.style.display = s.classList.contains('collapsed') ? 'none' : 'inline-block');
                document.querySelector('.main-content').style.marginLeft = s.classList.contains('collapsed') ? '80px' : '250px';
            }
        }

        function closeMobileSidebar() {
            document.getElementById('sidebar').classList.remove('active');
            const o = document.querySelector('.sidebar-overlay');
            if (o) {
                o.classList.remove('active');
                setTimeout(() => o.remove(), 300);
            }
        }

        function initializeSidebar() {
            const s = document.getElementById('sidebar');
            if (window.innerWidth > 992) {
                const saved = localStorage.getItem('sidebarCollapsed') === 'true';
                s.classList.toggle('collapsed', saved);
                document.querySelectorAll('.nav-text').forEach(t => t.style.display = saved ? 'none' : 'inline-block');
                document.querySelector('.main-content').style.marginLeft = saved ? '80px' : '250px';
            } else {
                s.classList.remove('active', 'collapsed');
                document.querySelectorAll('.nav-text').forEach(t => t.style.display = 'inline-block');
                document.querySelector('.main-content').style.marginLeft = '0';
            }
        }

        function handleSidebarResize() {
            const s = document.getElementById('sidebar');
            const o = document.querySelector('.sidebar-overlay');
            if (window.innerWidth > 992) {
                if (o) o.remove();
                s.classList.remove('active');
                const saved = localStorage.getItem('sidebarCollapsed') === 'true';
                s.classList.toggle('collapsed', saved);
                document.querySelectorAll('.nav-text').forEach(t => t.style.display = saved ? 'none' : 'inline-block');
                document.querySelector('.main-content').style.marginLeft = saved ? '80px' : '250px';
            } else {
                s.classList.remove('collapsed');
                document.querySelectorAll('.nav-text').forEach(t => t.style.display = 'inline-block');
                document.querySelector('.main-content').style.marginLeft = '0';
            }
        }

        // Start
        document.addEventListener('DOMContentLoaded', function() {
            initializeSidebar();
            
            document.getElementById('mobileToggleBtn')?.addEventListener('click', (e) => {
                e.stopPropagation();
                toggleSidebar();
            });
            
            document.getElementById('desktopToggleBtn')?.addEventListener('click', (e) => {
                e.stopPropagation();
                toggleSidebar();
            });
            
            document.querySelectorAll('.sidebar .nav-link').forEach(l => {
                l.addEventListener('click', () => {
                    if (window.innerWidth <= 992) closeMobileSidebar();
                });
            });
            
            window.addEventListener('resize', handleSidebarResize);
            
            init();
        });

        function createNewOrder() {
            bootstrap.Modal.getInstance(document.getElementById('successModal'))?.hide();
        }

        function viewOrders() {
            window.location.href = 'sales_order.php';
        }

        function logout() {
            window.location.href = '../logout.php';
        }

        window.cancelOrder = function(id) {
            return new Promise((resolve, reject) => {
                const fd = new FormData();
                fd.append('action', 'cancel_order');
                fd.append('order_id', id);
                fetch(window.location.href, { 
                    method: 'POST', 
                    body: fd,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                })
                .then(r => r.json())
                .then(d => d.success ? resolve(d) : reject(new Error(d.message)))
                .catch(reject);
            });
        };
    </script>
</body>
</html>