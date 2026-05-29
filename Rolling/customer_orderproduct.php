<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Log errors to file
ini_set('log_errors', 1);
ini_set('error_log', '../logs/php_errors.log');

require_once '../config/database.php';
require_once '../config/session_handler.php';

// Protect page - only Rolling Account role can access
requireLogin();
requireRole(['rolling_account']);

// Get current user info and branch context
$user_id = $_SESSION['user_id'] ?? 0;
$user_name = isset($_SESSION['first_name']) ? $_SESSION['first_name'] . ' ' . $_SESSION['last_name'] : 'Rolling Account';
$user_role = isset($_SESSION['role']) ? $_SESSION['role'] : 'rolling_account';
$branch_id = $_SESSION['rolling_branch_id'] ?? $_SESSION['branch_id'] ?? 0;
$view_all_branches = false; // Rolling accounts are restricted to their assigned branch

// Get customer ID and name from URL parameters
$pre_selected_customer_id = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : 0;
$pre_selected_customer_name = isset($_GET['customer_name']) ? htmlspecialchars($_GET['customer_name']) : '';
$is_customer_locked = ($pre_selected_customer_id > 0); 

// Get branch name for display
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
    $user_initials = 'RA';
}

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

// Get all items
$items = [];

if ($items_branch_column_exists) {
    if ($view_all_branches) {
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

// Get all unit types and quantities for each item
$all_items_unit_types = getAllItemsUnitTypes($conn, $items, $branch_id, $items_branch_column_exists, $view_all_branches);

// Get all unique categories
$categories = array_unique(array_column($items, 'category'));
$categories = array_filter($categories);
sort($categories);

// Get all customers
$customers = [];

if ($branch_column_exists) {
    if ($view_all_branches) {
        $customers_query = "SELECT c.customer_id, c.customer_name, c.email, c.phone_number, c.address, c.city,
                        c.price_level
                        FROM customers c
                        LEFT JOIN branches b ON c.branch_id = b.branch_id
                        WHERE c.status = 'active'
                        ORDER BY c.customer_name ASC";
    } else {
        $customers_query = "SELECT c.customer_id, c.customer_name, c.email, c.phone_number, c.address, c.city,
                        c.price_level
                        FROM customers c
                        WHERE c.status = 'active' AND c.branch_id = $branch_id
                        ORDER BY c.customer_name ASC";
    }
} else {
    $customers_query = "SELECT customer_id, customer_name, email, phone_number, address, city, price_level
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

function getItemUnitQuantity($conn, $item_id, $unit_type_name, $branch_id = 0, $items_branch_column_exists = false, $view_all_branches = false) {
    $unit_type_name = trim((string)$unit_type_name);
    if ($item_id <= 0 || $unit_type_name === '') {
        return 1;
    }

    $query = "
        SELECT COALESCE(ut.quantity_smallest_pack, 1) AS quantity_smallest_pack
        FROM unit_types ut
        WHERE ut.unit_type_name = ?
          AND ut.status = 'active'
    ";

    if ($items_branch_column_exists && !$view_all_branches) {
        $query .= " AND (ut.branch_id = ? OR ut.branch_id IS NULL)";
    }

    $query .= " ORDER BY ut.is_default_uom DESC LIMIT 1";

    $stmt = $conn->prepare($query);
    if (!$stmt) {
        return 1;
    }

    if ($items_branch_column_exists && !$view_all_branches) {
        $stmt->bind_param('si', $unit_type_name, $branch_id);
    } else {
        $stmt->bind_param('s', $unit_type_name);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    $qty = (int)($row['quantity_smallest_pack'] ?? 1);
    return $qty > 0 ? $qty : 1;
}

function getAllItemsUnitTypes($conn, $items_array, $branch_id = 0, $items_branch_column_exists = false, $view_all_branches = false) {
    $unit_types_by_item = [];
    
    foreach ($items_array as $item) {
        $item_id = $item['item_id'];
        
        // Simplified query - remove DISTINCT and get ALL unit types
        $query = "
            SELECT ut.unit_type_name, COALESCE(ut.quantity_smallest_pack, 1) AS quantity_smallest_pack
            FROM item_unit_pricing iup
            JOIN unit_types ut ON iup.unit_type_id = ut.unit_type_id
            WHERE iup.item_id = ? AND ut.status = 'active'
        ";
        
        if ($items_branch_column_exists && !$view_all_branches) {
            $query .= " AND (ut.branch_id = ? OR ut.branch_id IS NULL)";
        }
        
        $query .= " ORDER BY ut.is_default_uom DESC, ut.quantity_smallest_pack ASC";
        
        $stmt = $conn->prepare($query);
        if (!$stmt) {
            error_log("Prepare failed for item $item_id: " . $conn->error);
            continue;
        }
        
        if ($items_branch_column_exists && !$view_all_branches) {
            $stmt->bind_param('ii', $item_id, $branch_id);
        } else {
            $stmt->bind_param('i', $item_id);
        }
        
        if (!$stmt->execute()) {
            error_log("Execute failed for item $item_id: " . $stmt->error);
            continue;
        }
        
        $result = $stmt->get_result();
        
        $conversions = [];
        while ($row = $result->fetch_assoc()) {
            $unit_name = $row['unit_type_name'];
            $conversions[$unit_name] = (int)$row['quantity_smallest_pack'];
        }
        
        // Kung walang nahanap sa item_unit_pricing, gamitin ang default unit_type from items table
        if (empty($conversions)) {
            $default_unit = $item['unit_type'] ?? 'piece';
            $conversions[$default_unit] = 1;
            error_log("No unit types found for item {$item['item_name']} (ID: $item_id), using default: $default_unit");
        }
        
        $unit_types_by_item[$item_id] = $conversions;
        $stmt->close();
    }
    
    return $unit_types_by_item;
}

// Handle order submission via AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_order') {
    header('Content-Type: application/json');
    
    try {
        if (!$conn) {
            throw new Exception("Database connection failed");
        }
        
        $conn->begin_transaction();
        
        $customer_id = isset($_POST['customer_id']) ? (int)$_POST['customer_id'] : 0;
        $customer_name = isset($_POST['customer_name']) ? trim($_POST['customer_name']) : '';
        $email = isset($_POST['email']) ? trim($_POST['email']) : '';
        $phone = isset($_POST['phone']) ? trim($_POST['phone']) : '';
        $address = isset($_POST['address']) ? trim($_POST['address']) : '';
        $items_data = isset($_POST['items']) ? json_decode($_POST['items'], true) : [];
        $discount_percent = isset($_POST['discount_percent']) ? (float)$_POST['discount_percent'] : 0;
        $agent_location = isset($_POST['agent_location']) ? trim($_POST['agent_location']) : '';
        $order_status = isset($_POST['order_status']) ? trim($_POST['order_status']) : 'pending'; // Get order status from POST
        
        if (empty($items_data)) {
            throw new Exception("No items in cart");
        }
        
        $user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
        $branch_id = isset($_SESSION['branch_id']) ? (int)$_SESSION['branch_id'] : 0;
        $view_all_branches = isset($_SESSION['view_all_branches']) ? $_SESSION['view_all_branches'] : false;
        
        if ($user_id === 0) {
            throw new Exception("User session invalid. Please log in again.");
        }
        
        // Create/update customer
        if ($customer_id === 0 && !empty($customer_name)) {
            if ($branch_column_exists && !$view_all_branches) {
                $check_sql = "SELECT customer_id FROM customers WHERE customer_name = ? AND branch_id = ? AND status = 'active'";
                $check_stmt = $conn->prepare($check_sql);
                $check_stmt->bind_param('si', $customer_name, $branch_id);
            } else {
                $check_sql = "SELECT customer_id FROM customers WHERE customer_name = ? AND status = 'active'";
                $check_stmt = $conn->prepare($check_sql);
                $check_stmt->bind_param('s', $customer_name);
            }
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows > 0) {
                $existing_customer = $check_result->fetch_assoc();
                $customer_id = $existing_customer['customer_id'];
                
                $update_sql = "UPDATE customers SET email = ?, phone_number = ?, address = ? WHERE customer_id = ?";
                $update_stmt = $conn->prepare($update_sql);
                $update_stmt->bind_param('sssi', $email, $phone, $address, $customer_id);
                $update_stmt->execute();
            } else {
                $customer_code = 'CUST-' . date('Ymd') . '-' . str_pad(rand(1000, 9999), 4, '0', STR_PAD_LEFT);
                
                if ($branch_column_exists && !$view_all_branches) {
                    $sql_new_cust = "INSERT INTO customers (customer_name, customer_code, email, phone_number, address, status, branch_id) 
                                VALUES (?, ?, ?, ?, ?, 'active', ?)";
                    $stmt_new_cust = $conn->prepare($sql_new_cust);
                    $stmt_new_cust->bind_param('sssssi', $customer_name, $customer_code, $email, $phone, $address, $branch_id);
                } else {
                    $sql_new_cust = "INSERT INTO customers (customer_name, customer_code, email, phone_number, address, status) 
                                VALUES (?, ?, ?, ?, ?, 'active')";
                    $stmt_new_cust = $conn->prepare($sql_new_cust);
                    $stmt_new_cust->bind_param('sssss', $customer_name, $customer_code, $email, $phone, $address);
                }
                $stmt_new_cust->execute();
                $customer_id = $stmt_new_cust->insert_id;
            }
        }
        
        if ($customer_id === 0) {
            throw new Exception("Customer is required");
        }
        
        // Calculate subtotal
        $subtotal = 0;
        foreach ($items_data as $item) {
            $subtotal += $item['price'] * $item['quantity'];
        }
        
        $discount_amount = $subtotal * ($discount_percent / 100);
        $total_amount = $subtotal - $discount_amount;
        
        $so_number = 'SO-' . date('Ymd') . '-' . substr(time(), -4) . rand(100, 999);
        $order_date = date('Y-m-d H:i:s');
        
        // Check sales_orders table columns
        $columns_check = $conn->query("SHOW COLUMNS FROM sales_orders");
        $columns = [];
        while ($col = $columns_check->fetch_assoc()) {
            $columns[] = $col['Field'];
        }
        $has_discount_column = in_array('discount_percent', $columns);
        $has_agent_location_column = in_array('agent_location', $columns);
        
        $insert_fields = ['so_number', 'customer_id', 'branch_id', 'order_date', 'total_amount', 'order_status', 'created_by'];
        $insert_placeholders = ['?', '?', '?', '?', '?', '?', '?'];
        $insert_types = 'siisdss';
        $insert_values = [$so_number, $customer_id, $branch_id, $order_date, $total_amount, $order_status, $user_id];
        
        if ($has_discount_column) {
            $insert_fields[] = 'discount_percent';
            $insert_placeholders[] = '?';
            $insert_types .= 'd';
            $insert_values[] = $discount_percent;
        }
        
        if ($has_agent_location_column && !empty($agent_location)) {
            $insert_fields[] = 'agent_location';
            $insert_placeholders[] = '?';
            $insert_types .= 's';
            $insert_values[] = $agent_location;
        }
        
        $sql = "INSERT INTO sales_orders (" . implode(', ', $insert_fields) . ") VALUES (" . implode(', ', $insert_placeholders) . ")";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($insert_types, ...$insert_values);
        $stmt->execute();
        $so_id = $stmt->insert_id;
        
        // Insert order items and deduct inventory
        $sql_items = "INSERT INTO sales_order_items (so_id, item_id, unit_type, quantity_ordered, unit_price)
                    VALUES (?, ?, ?, ?, ?)";
        $stmt_items = $conn->prepare($sql_items);
        
        $updated_stock_data = [];
        
        foreach ($items_data as $item) {
            $item_id = (int)$item['id'];
            $quantity = (int)$item['quantity'];
            $unit_price = (float)$item['price'];
            $unit_type = isset($item['unit_type']) ? $item['unit_type'] : 'piece';
            
            $pieces_multiplier = getItemUnitQuantity($conn, $item_id, $unit_type, $branch_id, $items_branch_column_exists, $view_all_branches);
            $pieces_to_deduct = $quantity * $pieces_multiplier;
            
            $stmt_items->bind_param('iisid', $so_id, $item_id, $unit_type, $quantity, $unit_price);
            $stmt_items->execute();
            
            // Deduct inventory
            if ($items_branch_column_exists && !$view_all_branches) {
                $sql_deduct = "UPDATE items 
                            SET stock = COALESCE(stock, 0) - ? 
                            WHERE item_id = ? AND branch_id = ?";
                $stmt_deduct = $conn->prepare($sql_deduct);
                $stmt_deduct->bind_param('iii', $pieces_to_deduct, $item_id, $branch_id);
            } else {
                $sql_deduct = "UPDATE items 
                            SET stock = COALESCE(stock, 0) - ? 
                            WHERE item_id = ?";
                $stmt_deduct = $conn->prepare($sql_deduct);
                $stmt_deduct->bind_param('ii', $pieces_to_deduct, $item_id);
            }
            $stmt_deduct->execute();
            
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
            
            $updated_stock_data[] = [
                'item_id' => $item_id,
                'stock' => (int)$stock_row['stock']
            ];
        }
        
        $conn->commit();
        
        echo json_encode([
            'success' => true, 
            'message' => 'Order submitted successfully!', 
            'so_number' => $so_number,
            'so_id' => $so_id,
            'updated_stock' => $updated_stock_data,
            'discount_percent' => $discount_percent,
            'total_amount' => $total_amount
        ]);
        exit;
        
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Order submission error: " . $e->getMessage());
        echo json_encode([
            'success' => false, 
            'message' => $e->getMessage()
        ]);
        exit;
    }
}

// AJAX handler to get approved discount for a customer
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_customer_discount') {
    header('Content-Type: application/json');
    
    try {
        $customer_id = (int)$_POST['customer_id'];
        
        if (!$view_all_branches && $branch_column_exists) {
            $check_sql = "SELECT customer_id FROM customers WHERE customer_id = ? AND branch_id = ?";
            $check_stmt = $conn->prepare($check_sql);
            $check_stmt->bind_param('ii', $customer_id, $branch_id);
            $check_stmt->execute();
            if ($check_stmt->get_result()->num_rows === 0) {
                throw new Exception("Customer not found or access denied");
            }
        }
        
        $query = "SELECT requested_discount_percent 
                  FROM credit_discount_requests 
                  WHERE customer_id = ? 
                    AND status = 'approved' 
                    AND (request_type = 'discount' OR request_type = 'both')
                  ORDER BY approved_at DESC 
                  LIMIT 1";
        $stmt = $conn->prepare($query);
        $stmt->bind_param('i', $customer_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($row = $result->fetch_assoc()) {
            $discount = (float)$row['requested_discount_percent'];
        } else {
            $discount = 0;
        }
        
        echo json_encode([
            'success' => true,
            'discount' => $discount
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

// AJAX handler to get approved credit terms for a customer
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_customer_credit_terms') {
    header('Content-Type: application/json');
    
    try {
        $customer_id = (int)$_POST['customer_id'];
        
        if (!$view_all_branches && $branch_column_exists) {
            $check_sql = "SELECT customer_id FROM customers WHERE customer_id = ? AND branch_id = ?";
            $check_stmt = $conn->prepare($check_sql);
            $check_stmt->bind_param('ii', $customer_id, $branch_id);
            $check_stmt->execute();
            if ($check_stmt->get_result()->num_rows === 0) {
                throw new Exception("Customer not found or access denied");
            }
        }
        
        $query = "SELECT requested_credit_limit, credit_terms_days 
                  FROM credit_discount_requests 
                  WHERE customer_id = ? 
                    AND status = 'approved' 
                    AND (request_type = 'credit_terms' OR request_type = 'both')
                  ORDER BY approved_at DESC 
                  LIMIT 1";
        $stmt = $conn->prepare($query);
        $stmt->bind_param('i', $customer_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $credit_limit = 0;
        $credit_terms_days = 0;
        
        if ($row = $result->fetch_assoc()) {
            $credit_limit = (float)($row['requested_credit_limit'] ?? 0);
            $credit_terms_days = (int)($row['credit_terms_days'] ?? 0);
        }
        
        echo json_encode([
            'success' => true,
            'credit_limit' => $credit_limit,
            'credit_terms_days' => $credit_terms_days
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
// Handle AJAX request to get product unit types
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_product_unit_types') {
    header('Content-Type: application/json');
    
    try {
        $product_id = isset($_POST['product_id']) ? (int)$_POST['product_id'] : 0;
        $price_level = isset($_POST['price_level']) ? trim($_POST['price_level']) : 'Standard';
        
        if ($product_id <= 0) {
            throw new Exception("Invalid product ID");
        }
        
        $unit_types_query = "
            SELECT unit_type_id, unit_type_name, unit_price, quantity_smallest_pack
            FROM (
                SELECT 
                    ut.unit_type_id,
                    ut.unit_type_name,
                    iup.unit_price,
                    COALESCE(ut.quantity_smallest_pack, 1) AS quantity_smallest_pack,
                    ROW_NUMBER() OVER (
                        PARTITION BY ut.unit_type_id
                        ORDER BY 
                            CASE 
                                WHEN iup.price_level = ? THEN 0
                                ELSE 1
                            END,
                            CASE 
                                WHEN iup.effective_date IS NULL THEN 1
                                WHEN iup.effective_date <= CURDATE() THEN 0
                                ELSE 2
                            END,
                            CASE WHEN iup.effective_date <= CURDATE() THEN iup.effective_date END DESC,
                            CASE WHEN iup.effective_date > CURDATE() THEN iup.effective_date END ASC,
                            iup.pricing_id DESC
                    ) AS rn,
                    ut.is_default_uom
                FROM item_unit_pricing iup
                JOIN unit_types ut ON iup.unit_type_id = ut.unit_type_id
                WHERE iup.item_id = ? AND ut.status = 'active' AND (iup.price_level = ? OR iup.price_level = 'Standard')
            ) ranked_unit_types
            WHERE rn = 1
            ORDER BY is_default_uom DESC, quantity_smallest_pack ASC, unit_type_name ASC
        ";
        
        $unit_types_stmt = $conn->prepare($unit_types_query);
        $unit_types_stmt->bind_param('sis', $price_level, $product_id, $price_level);
        $unit_types_stmt->execute();
        $unit_types_result = $unit_types_stmt->get_result();
        $unit_types = $unit_types_result->fetch_all(MYSQLI_ASSOC);
        
        // Debug: Log kung may nahanap
        error_log("Product ID: $product_id, Found " . count($unit_types) . " unit types");
        
        echo json_encode([
            'success' => true,
            'unit_types' => $unit_types
        ]);
        exit;
        
    } catch (Exception $e) {
        error_log("get_product_unit_types error: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
        exit;
    }
}

// AJAX handler for product details
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_product_details') {
    header('Content-Type: application/json');
    
    try {
        $product_id = isset($_POST['product_id']) ? (int)$_POST['product_id'] : 0;
        $price_level = isset($_POST['price_level']) ? trim($_POST['price_level']) : 'Standard';
        
        if ($product_id <= 0) {
            throw new Exception("Invalid product ID");
        }
        
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
        
        // Get unit types with pricing
        $unit_types_query = "
            SELECT unit_type_id, unit_type_name, unit_price, quantity_smallest_pack
            FROM (
                SELECT 
                    ut.unit_type_id,
                    ut.unit_type_name,
                    iup.unit_price,
                    COALESCE(ut.quantity_smallest_pack, 1) AS quantity_smallest_pack,
                    ROW_NUMBER() OVER (
                        PARTITION BY ut.unit_type_id
                        ORDER BY 
                            CASE 
                                WHEN iup.price_level = ? THEN 0
                                ELSE 1
                            END,
                            CASE 
                                WHEN iup.effective_date IS NULL THEN 1
                                WHEN iup.effective_date <= CURDATE() THEN 0
                                ELSE 2
                            END,
                            CASE WHEN iup.effective_date <= CURDATE() THEN iup.effective_date END DESC,
                            CASE WHEN iup.effective_date > CURDATE() THEN iup.effective_date END ASC,
                            iup.pricing_id DESC
                    ) AS rn,
                    ut.is_default_uom
                FROM item_unit_pricing iup
                JOIN unit_types ut ON iup.unit_type_id = ut.unit_type_id
                WHERE iup.item_id = ? AND ut.status = 'active' AND (iup.price_level = ? OR iup.price_level = 'Standard')
            ) ranked_unit_types
            WHERE rn = 1
            ORDER BY is_default_uom DESC, quantity_smallest_pack ASC, unit_type_name ASC
        ";
        
        $unit_types_stmt = $conn->prepare($unit_types_query);
        $unit_types_stmt->bind_param('sis', $price_level, $product_id, $price_level);
        $unit_types_stmt->execute();
        $unit_types_result = $unit_types_stmt->get_result();
        $unit_types = $unit_types_result->fetch_all(MYSQLI_ASSOC);
        
        // Get images
        $images_query = "SELECT image_id, image_path, image_order, is_primary FROM item_images WHERE item_id = ? ORDER BY is_primary DESC, image_order ASC";
        $images_stmt = $conn->prepare($images_query);
        $images_stmt->bind_param('i', $product_id);
        $images_stmt->execute();
        $images_result = $images_stmt->get_result();
        $images = $images_result->fetch_all(MYSQLI_ASSOC);
        
        // Get order history
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
            'unit_types' => $unit_types,
            'images' => $images,
            'order_history' => $order_history
        ]);
        exit;
        
    } catch (Exception $e) {
        error_log("get_product_details error: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Product - Branch Admin</title>
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
    <!-- SweetAlert2 -->
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
        <!-- Session Checker -->
    <script src="../js/session-checker.js"></script>
    <style>
        * {
            box-sizing: border-box;
        }

        /* Cart Item Styling */
        .cart-item {
            background: #F5F5F5;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-left: 4px solid #047857;
        }
        
        /* Cart Icon Button in Header */
        .navbar-top .btn-success {
            background: #047857;
            border: none;
            padding: 8px 12px;
            border-radius: 8px;
            font-size: 18px;
            flex-shrink: 0;
            min-width: 40px;
            min-height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            position: relative;
        }
        
        .navbar-top .btn-success .badge {
            font-size: 10px;
            padding: 3px 5px;
            top: -5px;
            right: -5px;
            background: #1B5E20 !important;
            border: 2px solid #FFFFFF;
        }
        
        /* Category Tabs */
        .category-tabs-container {
            background: #FFFFFF;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            margin-bottom: 20px;
            padding: 15px 15px 0 15px;
        }
        
        .tabs-header {
            display: flex;
            align-items: center;
            gap: 15px;
            border-bottom: 2px solid #e0e0e0;
            padding-bottom: 10px;
        }
        
        .tabs-scroll {
            flex: 1;
            overflow-x: auto;
            overflow-y: hidden;
            white-space: nowrap;
            padding-bottom: 5px;
        }
        
        .category-tabs {
            display: inline-flex;
            gap: 5px;
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
            background: #F5F5F5;
            color: #047857;
        }
        
        .tab-btn.active {
            background: #047857;
            color: #FFFFFF;
        }
        
        /* Search */
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
            border: 1px solid #e0e0e0;
            border-radius: 25px;
            font-size: 14px;
            transition: all 0.2s;
        }
        
        .search-input:focus {
            outline: none;
            border-color: #2E7D32;
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
        }
        
        .search-reset.visible {
            display: block;
        }
        
        /* Product Table */
        .product-table-container {
            background: #FFFFFF;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            overflow-x: auto;
            margin-bottom: 20px;
        }
        
        .product-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 700px;
        }
        
        .product-table thead {
            background: #047857;
            color: #FFFFFF;
        }
        
        .product-table th {
            padding: 10px 8px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            text-align: center;
            vertical-align: middle;
        }
        
        .product-table td {
            padding: 8px 6px;
            font-size: 12px;
            border-bottom: 1px solid #e0e0e0;
            vertical-align: middle;
            cursor: pointer;
        }
        
        .product-table td:first-child,
        .product-table td:nth-child(3),
        .product-table td:nth-child(4),
        .product-table td:nth-child(5) {
            cursor: pointer;
        }
        
        .product-table th:nth-child(1) { width: 8%; }
        .product-table th:nth-child(2) { width: 30%; }
        .product-table th:nth-child(3) { width: 22%; }
        .product-table th:nth-child(4) { width: 18%; }
        .product-table th:nth-child(5) { width: 10%; }
        
        .product-table td:nth-child(2) { text-align: left; }
        .product-table td:not(:nth-child(2)) { text-align: center; }
        
        /* Product image */
        .product-thumbnail {
            width: 80px;
            height: 80px;
            object-fit: cover;
            border-radius: 4px;
            border: 1px solid #e0e0e0;
            background: #F5F5F5;
        }
        
        .product-info {
            display: flex;
            flex-direction: column;
        }
        
        .product-name {
            font-weight: 600;
            color: #212121;
            font-size: 18px;
            margin-bottom: 4px;
        }
        
        .stock-info {
            font-size: 15px;
            color: #2E7D32;
            font-weight: 600;
        }
        
        .stock-warning {
            color: #dc3545 !important;
            font-weight: 600;
        }
        
        /* Unit buttons */
        .unit-buttons {
            display: flex;
            gap: 3px;
            flex-wrap: wrap;
            justify-content: center;
        }
        
        .unit-btn {
            background: #FFFFFF;
            border: 1px solid #e0e0e0;
            border-radius: 4px;
            padding: 4px 8px;
            font-size: 15px;
            font-weight: 600;
            color: #212121;
            min-width: 38px;
            min-height: 40px;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .unit-btn:hover {
            background: #e0e0e0;
        }
        
        .unit-btn.active {
            background: #047857;
            color: #FFFFFF;
            border-color: #047857;
        }
        
        /* Quantity controls */
        .quantity-controls {
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .qty-input {
            width: 80px;
            height: 40px;
            text-align: center;
            border: 1px solid #e0e0e0;
            border-radius: 4px;
            font-size: 15px;
            padding: 0 4px;
        }
        
        .qty-input:focus {
            outline: none;
            border-color: #047857;
        }
        
        /* Price cell */
        .price-cell {
            font-weight: 700;
            color: #047857;
            font-size: 12px;
        }
        
        .price-input {
            width: 100px;
            height: 40px;
            text-align: right;
        }
        
        /* Toast notification - CENTERED */
        .toast-notification {
            position: fixed;
            top: 20px;
            left: 50%;
            transform: translateX(-50%);
            background: #047857;
            color: #FFFFFF;
            padding: 12px 24px;
            border-radius: 8px;
            z-index: 9999;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            font-size: 14px;
            animation: slideInDown 0.3s ease;
            white-space: nowrap;
        }

        @keyframes slideInDown {
            from {
                transform: translateX(-50%) translateY(-100%);
                opacity: 0;
            }
            to {
                transform: translateX(-50%) translateY(0);
                opacity: 1;
            }
        }

        .toast-notification.fade-out {
            animation: fadeOut 0.3s ease forwards;
        }

        @keyframes fadeOut {
            to {
                opacity: 0;
                visibility: hidden;
            }
        }

        /* Para sa mobile - adjust padding at font size */
        @media (max-width: 576px) {
            .toast-notification {
                padding: 10px 16px;
                font-size: 12px;
                white-space: nowrap;
                top: 15px;
            }
        }

        /* Para sa sobrang habang message - gawing multi-line */
        @media (max-width: 480px) {
            .toast-notification {
                white-space: normal;
                max-width: 90%;
                text-align: center;
                line-height: 1.4;
            }
        }
        
        /* Navbar */
        .navbar-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            padding: 12px 20px;
            margin-bottom: 20px;
            background: #FFFFFF;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        
        
        .mobile-toggle-btn {
            display: none;
        }
        
        /* Modal styles */
        .modal-header {
            background: #047857;
            color: #FFFFFF;
            border: none;
            padding: 12px 20px;
        }
        
        .modal-header .btn-close {
            filter: brightness(0) invert(1);
            opacity: 0.8;
        }
        
        .customer-selection {
            background: #F5F5F5;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
            border-left: 4px solid #2E7D32;
        }
        
        .discount-line {
            color: #dc3545;
            font-weight: 500;
        }
        
        .credit-terms-line {
            color: #17a2b8;
            font-weight: 500;
            border-top: 1px dashed #e0e0e0;
            padding-top: 10px;
            margin-top: 10px;
        }
        
        /* Product info modal */
        .product-info-container {
            padding: 20px;
        }
        
        .product-header-section {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
            background: #F8F9FA;
            padding: 20px;
            border-radius: 10px;
        }
        
        .product-image-large {
            width: 150px;
            height: 150px;
            object-fit: cover;
            border-radius: 10px;
            border: 2px solid #047857;
            background: #FFFFFF;
            padding: 3px;
        }
        
        .info-row {
            display: flex;
            align-items: baseline;
            padding: 8px 0;
            border-bottom: 1px dashed #e0e0e0;
            font-size: 13px;
        }
        
        .info-label {
            width: 100px;
            font-weight: 600;
            color: #212121;
        }
        
        .info-value {
            flex: 1;
            color: #047857;
            font-weight: 600;
        }
        
        .price-tag {
            font-size: 20px;
            font-weight: 700;
        }
        
        .stock-tag {
            background: #047857;
            color: #FFFFFF;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }
        
        .loading-state {
            text-align: center;
            padding: 50px;
        }
        
        .history-table {
            width: 100%;
            font-size: 12px;
            border-collapse: collapse;
        }
        
        .history-table th {
            background: #F8F9FA;
            padding: 10px;
            text-align: center;
        }
        
        .history-table td {
            padding: 8px;
            text-align: center;
            border-bottom: 1px solid #e0e0e0;
        }
        
        .status-badge {
            padding: 3px 8px;
            border-radius: 15px;
            font-size: 10px;
            font-weight: 600;
            display: inline-block;
        }
        
        .status-pending { background: #ffc107; color: #212121; }
        .status-completed { background: #2E7D32; color: #FFFFFF; }
        .status-cancelled { background: #dc3545; color: #FFFFFF; }
        
        /* No results */
        .no-results {
            text-align: center;
            padding: 40px;
            background: #FFFFFF;
            border-radius: 10px;
            color: #666;
        }
        
        .no-results i {
            font-size: 48px;
            color: #ccc;
            margin-bottom: 10px;
        }
        
        /* Alert */
        .alert-info {
            background-color: #F5F5F5;
            border-color: #e0e0e0;
            color: #212121;
            border-radius: 8px;
            margin-bottom: 15px;
            font-size: 13px;
        }
        
        /* Hide mobile-specific elements */
        .mobile-price-display,
        .mobile-unit-qty-container,
        .mobile-only {
            display: none !important;
        }
        
        /* Row hover effect */
        .product-table tbody tr:hover {
            background-color: #f8f9fa;
        }
        /* Currency input styling */
.currency-input {
    text-align: right;
    font-weight: 500;
}

.currency-display {
    font-weight: 600;
    letter-spacing: 0.3px;
}

/* Number input spinner hide */
input[type="number"]::-webkit-inner-spin-button,
input[type="number"]::-webkit-outer-spin-button {
    opacity: 0.5;
}

input[type="number"]:focus::-webkit-inner-spin-button,
input[type="number"]:focus::-webkit-outer-spin-button {
    opacity: 1;
}
/* ===== RECEIPT TABLE STYLES ===== */
.receipt-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 12px;
    table-layout: fixed;
}

.receipt-table th,
.receipt-table td {
    padding: 10px 6px;
    border-bottom: 1px solid #e0e0e0;
    vertical-align: middle;
    word-wrap: break-word;
}

.receipt-table th {
    background: #f8f9fa;
    font-weight: 600;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    color: #666;
}

/* Fixed column widths */
.receipt-table th:nth-child(1), 
.receipt-table td:nth-child(1) { 
    width: 30%; 
    text-align: left; 
}

.receipt-table th:nth-child(2), 
.receipt-table td:nth-child(2) { 
    width: 12%; 
    text-align: center; 
}

.receipt-table th:nth-child(3), 
.receipt-table td:nth-child(3) { 
    width: 18%; 
    text-align: center; 
}

.receipt-table th:nth-child(4), 
.receipt-table td:nth-child(4) { 
    width: 15%; 
    text-align: right; 
}

.receipt-table th:nth-child(5), 
.receipt-table td:nth-child(5) { 
    width: 18%; 
    text-align: right; 
}

.receipt-table th:nth-child(6), 
.receipt-table td:nth-child(6) { 
    width: 7%; 
    text-align: center; 
}

/* Price and Total cell styling - same color */
.receipt-table td:nth-child(4) span,
.receipt-table td:nth-child(5) span,
.receipt-table td:nth-child(5) strong {
    font-weight: 600;
    color: #047857;
}
.receipt-table td:nth-child(5) span,
.receipt-table td:nth-child(5) strong {
    font-weight: 700;
    color: #047857;
}

/* Quantity input styling */
.review-qty-input {
    width: 90%;
    max-width: 80px;
    text-align: center;
    padding: 6px 4px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 12px;
    box-sizing: border-box;
}

.review-qty-input:focus {
    outline: none;
    border-color: #047857 !important;
    box-shadow: 0 0 0 2px rgba(4, 120, 87, 0.1);
}

/* Pieces small text */
.pieces-small {
    font-size: 9px;
    color: #999;
    display: block;
    text-align: center;
    margin-top: 4px;
}

/* Product name cell */
.product-name-cell {
    font-weight: 600;
    color: #212121;
    font-size: 12px;
    word-break: break-word;
}

/* Delete button */
.delete-item-btn {
    background: none;
    border: none;
    color: #dc3545;
    font-size: 16px;
    cursor: pointer;
    padding: 4px 8px;
    border-radius: 4px;
    transition: all 0.2s;
}

.delete-item-btn:hover {
    background: #dc3545;
    color: white;
}

/* Mobile responsive */
@media (max-width: 768px) {
    .receipt-table {
        font-size: 10px;
    }
    
    .receipt-table th,
    .receipt-table td {
        padding: 8px 3px;
    }
    
    .receipt-table th {
        font-size: 9px;
        padding: 6px 3px;
    }
    
    .receipt-table th:nth-child(1), 
    .receipt-table td:nth-child(1) { width: 28%; }
    
    .receipt-table th:nth-child(2), 
    .receipt-table td:nth-child(2) { width: 10%; }
    
    .receipt-table th:nth-child(3), 
    .receipt-table td:nth-child(3) { width: 22%; }
    
    .receipt-table th:nth-child(4), 
    .receipt-table td:nth-child(4) { width: 15%; }
    
    .receipt-table th:nth-child(5), 
    .receipt-table td:nth-child(5) { width: 18%; }
    
    .receipt-table th:nth-child(6), 
    .receipt-table td:nth-child(6) { width: 10%; }
    
    .review-qty-input {
        width: 80%;
        min-width: 50px;
        font-size: 10px !important;
        padding: 4px 2px !important;
    }
    
    .product-name-cell {
        font-size: 10px;
    }
    
    .pieces-small {
        font-size: 7px;
        margin-top: 2px;
    }
    
    .delete-item-btn {
        padding: 2px 4px;
        font-size: 12px;
    }
    
    /* Price and Total - mobile */
    .receipt-table td:nth-child(4) span,
    .receipt-table td:nth-child(5) span,
    .receipt-table td:nth-child(5) strong {
        font-size: 10px;
    }
}

/* Extra small devices */
@media (max-width: 480px) {
    .receipt-table th,
    .receipt-table td {
        padding: 6px 2px;
    }
    
    .receipt-table th {
        font-size: 8px;
    }
    
    .product-name-cell {
        font-size: 9px;
    }
    
    .review-qty-input {
        font-size: 9px !important;
        min-width: 45px;
    }
    
    /* Price and Total - extra small */
    .receipt-table td:nth-child(4) span,
    .receipt-table td:nth-child(5) span,
    .receipt-table td:nth-child(5) strong {
        font-size: 9px;
    }
}
/* Order Alert Animations - remove order-alert-title reference */
@keyframes slideInUp {
    from {
        transform: translateY(30px);
        opacity: 0;
    }
    to {
        transform: translateY(0);
        opacity: 1;
    }
}

@keyframes pulse {
    0% { transform: scale(1); }
    50% { transform: scale(1.05); }
    100% { transform: scale(1); }
}

.swal2-popup.animated-order-alert {
    animation: slideInUp 0.4s ease-out;
    border-radius: 20px;
    padding: 20px;
}

.swal2-popup.animated-order-alert .swal2-title {
    font-size: 22px;
    font-weight: 700;
    color: #212121;
}

.swal2-popup.animated-order-alert .swal2-icon.swal2-success {
    animation: pulse 0.5s ease;
    border-color: #047857;
}

.swal2-popup.animated-order-alert .swal2-icon.swal2-success .swal2-success-ring {
    border-color: #047857;
}

.swal2-popup.animated-order-alert .swal2-icon.swal2-success [class^='swal2-success-line'] {
    background-color: #047857;
}

.order-confirm-btn {
    background: linear-gradient(135deg, #047857 0%, #065f46 100%) !important;
    border: none !important;
    font-weight: 600 !important;
    padding: 12px 24px !important;
    border-radius: 50px !important;
    transition: all 0.3s ease !important;
}

.order-confirm-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(4, 120, 87, 0.3);
}

.order-cancel-btn {
    background: #f8f9fa !important;
    color: #6c757d !important;
    border: 1px solid #e0e0e0 !important;
    font-weight: 600 !important;
    padding: 12px 24px !important;
    border-radius: 50px !important;
    transition: all 0.3s ease !important;
}

.order-cancel-btn:hover {
    background: #e9ecef !important;
    transform: translateY(-2px);
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
                    <span class="nav-text">Rolling Account</span>
                </h3>
            </div>
            <div class="sidebar-content">
                <div class="sidebar-menu">
                    <ul class="nav flex-column">
                        <li class="nav-item">
                            <a class="nav-link" href="current_inventory.php">
                                <i class="bi bi-box-seam"></i>
                                <span class="nav-text">Current Inventory</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link active" href="customer_orderproduct.php">
                                <i class="bi bi-person-plus"></i>
                                <span class="nav-text">Orders</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="collections.php">
                                <i class="bi bi-cash-stack"></i>
                                <span class="nav-text">Collections</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="sales_order.php">
                                <i class="bi bi-cart"></i>
                                <span class="nav-text">Sales Orders</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="purchase_order.php">
                                <i class="bi bi-truck"></i>
                                <span class="nav-text">Purchase Orders</span>
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

        <!-- Main Content Area -->
        <div class="main-content">
            <div class="navbar-top">
                <button class="mobile-toggle-btn" id="mobileToggleBtn">
                    <i class="bi bi-list"></i>
                </button>
                <div class="page-title">
                    <h2>Order Products</h2>
                    <p>Select products and quantities to create an order</p>
                </div>
                <!-- Update the cart button in the navbar -->
                <button class="btn btn-success position-relative" type="button" onclick="viewCart()" title="View Cart">
                    <i class="bi bi-cart3"></i>
                    <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill" id="cartBadge" style="display: none;">
                        <span id="cartItemCount">0</span>
                    </span>
                </button>
            </div>

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

            <!-- Products Section -->
            <div class="col-12 products-section">
                <div class="d-flex justify-content-end mb-3">
                    <button class="btn btn-success" id="bulkAddToCartBtn" onclick="bulkAddToCart()">
                        <i class="bi bi-cart-plus"></i> Add All to Cart
                    </button>
                </div>
                <div class="product-table-container">
                    <table class="product-table" id="productsTable">
                        <thead>
                            <tr>
                                <th>Image</th>
                                <th>Product</th>
                                <th>Unit</th>
                                <th>Qty</th>
                                <th>Price</th>
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
                                        <tr><th>Date</th><th>Order #</th><th>Customer</th><th>Unit</th><th>Qty</th><th>Status</th></tr>
                                    </thead>
                                    <tbody id="modalOrderHistory">
                                        <tr><td colspan="6" class="text-center">No order history</td></tr>
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
                    <select class="form-select" id="modalCustomerSelect" <?php echo $is_customer_locked ? 'disabled' : ''; ?>>
                        <option value="">-- Choose Customer --</option>
                        <?php foreach ($customers as $customer): ?>
                            <option value="<?php echo $customer['customer_id']; ?>" 
                                    data-email="<?php echo htmlspecialchars($customer['email'] ?? ''); ?>"
                                    data-phone="<?php echo htmlspecialchars($customer['phone_number'] ?? ''); ?>"
                                    data-address="<?php echo htmlspecialchars($customer['address'] ?? ''); ?>"
                                    data-price-level="<?php echo htmlspecialchars($customer['price_level'] ?? 'Standard'); ?>"
                                    <?php echo ($pre_selected_customer_id == $customer['customer_id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($customer['customer_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($is_customer_locked): ?>
                    <input type="hidden" id="lockedCustomerId" value="<?php echo $pre_selected_customer_id; ?>">
                    <input type="hidden" id="lockedCustomerName" value="<?php echo $pre_selected_customer_name; ?>">
                    <input type="hidden" id="lockedCustomerEmail" value="<?php 
                        foreach ($customers as $customer) {
                            if ($customer['customer_id'] == $pre_selected_customer_id) {
                                echo htmlspecialchars($customer['email'] ?? '');
                                break;
                            }
                        }
                    ?>">
                    <input type="hidden" id="lockedCustomerPhone" value="<?php 
                        foreach ($customers as $customer) {
                            if ($customer['customer_id'] == $pre_selected_customer_id) {
                                echo htmlspecialchars($customer['phone_number'] ?? '');
                                break;
                            }
                        }
                    ?>">
                    <input type="hidden" id="lockedCustomerAddress" value="<?php 
                        foreach ($customers as $customer) {
                            if ($customer['customer_id'] == $pre_selected_customer_id) {
                                echo htmlspecialchars($customer['address'] ?? '');
                                break;
                            }
                        }
                    ?>">
                    <input type="hidden" id="lockedPriceLevel" value="<?php 
                        foreach ($customers as $customer) {
                            if ($customer['customer_id'] == $pre_selected_customer_id) {
                                echo htmlspecialchars($customer['price_level'] ?? 'Standard');
                                break;
                            }
                        }
                    ?>">
                    <div class="alert alert-info mt-2 mb-0 py-2">
                        <i class="bi bi-info-circle"></i> Customer is locked. Please go back to Customer List to change customer.
                    </div>
                    <?php endif; ?>
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

                <!-- Delivery Type Selection -->
<div id="deliveryTypeSection">
    <h6 class="mb-3"><i class="bi bi-truck"></i> Delivery Type</h6>
    <div class="alert bg-light mb-3">
        <div class="row">
            <div class="col-md-6">
                <div class="form-check mb-2">
                    <input class="form-check-input" type="radio" name="deliveryType" id="deliveryPickup" value="pickup" checked>
                    <label class="form-check-label" for="deliveryPickup">
                        <i class="bi bi-box-seam"></i> Pick Up
                        <small class="d-block text-muted">Customer will pick up the order</small>
                    </label>
                </div>
            </div>
            <div class="col-md-6">
                <div class="form-check mb-2">
                    <input class="form-check-input" type="radio" name="deliveryType" id="deliveryDeliver" value="deliver">
                    <label class="form-check-label" for="deliveryDeliver">
                        <i class="bi bi-truck"></i> Deliver
                        <small class="d-block text-muted">Order will be delivered to customer</small>
                    </label>
                </div>
            </div>
        </div>
        <div id="deliveryAddressGroup" style="display: none;" class="mt-3 p-3 bg-white rounded border">
            <div class="d-flex align-items-start">
                <i class="bi bi-geo-alt-fill text-success me-2 mt-1"></i>
                <div>
                    <strong class="d-block mb-1" style="font-size: 0.9rem;">Delivery Address:</strong>
                    <span id="deliveryAddressDisplay" class="text-muted" style="font-size: 0.85rem;">-</span>
                </div>
            </div>
            <small class="text-muted d-block mt-2" style="font-size: 0.7rem;">
                <i class="bi bi-info-circle"></i> Order will be delivered to customer's registered address
            </small>
        </div>
    </div>
</div>
                <h6 class="mb-3">Order Total</h6>
                <div class="alert bg-light">
                    <div class="d-flex justify-content-between">
                        <strong>Total Amount:</strong>
                        <strong id="reviewTotal" class="text-success">₱0.00</strong>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success" id="confirmOrderBtn" onclick="submitOrder()">Confirm & Submit</button>
            </div>
        </div>
    </div>
</div>

    <!-- Mobile Bottom Navigation -->
    <div class="mobile-nav" id="mobileNav">
        <ul class="nav">
            <li class="nav-item"><a class="nav-link" href="current_inventory.php"><i class="bi bi-box-seam"></i><span>Inventory</span></a></li>
            <li class="nav-item"><a class="nav-link active" href="customer_orderproduct.php"><i class="bi bi-person-plus"></i><span>Orders</span></a></li>
            <li class="nav-item"><a class="nav-link" href="collections.php"><i class="bi bi-cash-stack"></i><span>Collections</span></a></li>
            <li class="nav-item"><a class="nav-link" href="sales_order.php"><i class="bi bi-cart"></i><span>Sales</span></a></li>
            <li class="nav-item"><a class="nav-link" href="purchase_order.php"><i class="bi bi-truck"></i><span>Purchase</span></a></li>
            <li class="nav-item dropdown-more" id="moreDropdown"><a class="nav-link more-btn" href="#" onclick="toggleDropdown(event, 'moreDropdownMenu')"><i class="bi bi-three-dots-vertical"></i><span>More</span></a><div class="more-dropdown" id="moreDropdownMenu"><div class="dropdown-divider"></div><a href="#" class="dropdown-item logout-item" onclick="showProfileModal(); return false;"><i class="bi bi-box-arrow-right"></i><span>Logout</span></a></div></li>
        </ul>
    </div>

    <!-- Mobile Profile/Logout Modal -->
    <div class="modal fade" id="profileModal" tabindex="-1" aria-labelledby="profileModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="profileModalLabel"><i class="bi bi-person-circle me-2"></i>User Profile</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center">
                    <div class="user-avatar-large mb-3"><?php echo $user_initials; ?></div>
                    <h4 class="mb-1"><?php echo htmlspecialchars($user_name); ?></h4>
                    <p class="text-muted mb-3"><span class="badge bg-success"><?php echo ucfirst($user_role); ?></span></p>
                    <?php if (!$view_all_branches && $branch_id > 0): ?>
                    <div class="branch-info mb-3"><i class="bi bi-building me-1"></i><span><?php echo htmlspecialchars($branch_name); ?></span></div>
                    <?php endif; ?>
                    <button class="btn btn-danger btn-lg w-100" onclick="confirmLogout()"><i class="bi bi-box-arrow-right me-2"></i>Logout</button>
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    // Inventory data from PHP
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
    
    const productUnitTypes = {};
    const productImages_data = {};
    const productUnitConversions = <?php echo json_encode($all_items_unit_types); ?>;
    
    let cart = [];
    let activeUnitTypes = {};
    let toastTimeout = null;
    let currentFilter = 'all';
    let searchTerm = '';
    
    // ============= CURRENCY FORMATTING FUNCTIONS =============
    
    // Format number to currency with comma separators (for display)
    function formatCurrency(amount) {
        if (amount === null || amount === undefined || isNaN(amount)) return '₱0.00';
        return '₱' + parseFloat(amount).toLocaleString('en-PH', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }
    
    // Format number with commas only (no peso sign)
    function formatNumberWithCommas(amount) {
        if (amount === null || amount === undefined || isNaN(amount)) return '0.00';
        return parseFloat(amount).toLocaleString('en-PH', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }
    
    // Format stock display with commas
    function formatStockDisplay(stockValue, unitType) {
        const rounded = Math.floor(stockValue * 100) / 100;
        const formattedStock = rounded.toLocaleString('en-PH', {
            minimumFractionDigits: 0,
            maximumFractionDigits: 2
        });
        if (rounded < 0) return `<span class="stock-warning">${formattedStock} ${unitType}</span>`;
        return `${formattedStock} ${unitType}`;
    }
    
    // Helper functions
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
    
    function getUnitConversion(productId, unitType) {
        if (productUnitConversions[productId] && productUnitConversions[productId][unitType]) {
            return productUnitConversions[productId][unitType];
        }
        const defaults = { 'piece': 1, 'case': 12, 'inner-pack': 6, 'box': 24, 'carton': 48 };
        return defaults[unitType] || 1;
    }
    
    function getAvailableStock(productId) {
        const p = inventory.find(p => p.id === productId);
        if (!p) return 0;
        const inCart = cart.filter(i => i.id === productId).reduce((t, i) => t + (i.quantity * getUnitConversion(i.id, i.unit_type)), 0);
        return p.stock - inCart;
    }
    
    function getProductById(id) { 
        return inventory.find(p => p.id === id); 
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
        const subtotal = cart.reduce((s, i) => s + i.price * i.quantity, 0);
        const totalQty = cart.reduce((s, i) => s + i.quantity, 0);
        
        const subtotalEl = document.getElementById('cartModalSubtotal');
        const totalItemsEl = document.getElementById('cartModalTotalItems');
        const totalPriceEl = document.getElementById('cartModalTotalPrice');
        
        if (subtotalEl) subtotalEl.textContent = formatCurrency(subtotal);
        if (totalItemsEl) totalItemsEl.textContent = totalQty;
        if (totalPriceEl) totalPriceEl.textContent = formatCurrency(subtotal);
    }
    
    function loadProductUnitTypes(productId, priceLevel = 'Standard') {
        return new Promise((resolve) => {
            const formData = new FormData();
            formData.append('action', 'get_product_unit_types');
            formData.append('product_id', productId);
            formData.append('price_level', priceLevel);
            fetch(window.location.href, { method: 'POST', body: formData })
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.unit_types && data.unit_types.length > 0) {
                        const uniqueUnitTypes = [];
                        const seenUnitTypes = new Set();
                        data.unit_types.forEach(ut => {
                            const unitName = (ut.unit_type_name || '').trim();
                            if (!unitName || seenUnitTypes.has(unitName)) return;
                            seenUnitTypes.add(unitName);
                            uniqueUnitTypes.push(ut);
                        });
                        productUnitTypes[productId] = uniqueUnitTypes;
                        const smallestUnit = uniqueUnitTypes.find(ut => parseInt(ut.quantity_smallest_pack) === 1) || uniqueUnitTypes[0];
                        activeUnitTypes[productId] = smallestUnit.unit_type_name;
                        productUnitConversions[productId] = {};
                        uniqueUnitTypes.forEach(ut => {
                            productUnitConversions[productId][ut.unit_type_name] = parseInt(ut.quantity_smallest_pack) || 1;
                        });
                    }
                    resolve();
                })
                .catch(() => resolve());
        });
    }
    
    function loadAllProductUnitTypes() {
        const promises = inventory.map(product => loadProductUnitTypes(product.id, 'Standard'));
        return Promise.all(promises);
    }
    
    function loadProductImages(productId) {
        return new Promise((resolve) => {
            const formData = new FormData();
            formData.append('action', 'get_product_details');
            formData.append('product_id', productId);
            formData.append('price_level', 'Standard');
            fetch(window.location.href, { method: 'POST', body: formData })
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.images && data.images.length > 0) {
                        productImages_data[productId] = data.images;
                    }
                    resolve();
                })
                .catch(() => resolve());
        });
    }
    
    function loadAllProductImages() {
        const promises = inventory.map(product => loadProductImages(product.id));
        return Promise.all(promises);
    }
    
    function getSelectedPriceLevel() {
        const customerSelect = document.getElementById('modalCustomerSelect');
        if (!customerSelect) return 'Standard';
        const selectedOption = customerSelect.options[customerSelect.selectedIndex];
        return selectedOption ? selectedOption.dataset.priceLevel || 'Standard' : 'Standard';
    }
    
    function reloadProductPrices(priceLevel) {
        const promises = inventory.map(product => loadProductUnitTypes(product.id, priceLevel));
        return Promise.all(promises).then(() => renderProducts());
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
        document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
        event.target.classList.add('active');
        filterProducts();
    }
    
    function filterProducts() {
        const container = document.getElementById('productsContainer');
        if (!container) return;
        const filtered = inventory.filter(product => {
            if (currentFilter !== 'all' && product.category !== currentFilter) return false;
            if (searchTerm) return product.name.toLowerCase().includes(searchTerm) || product.sku.toLowerCase().includes(searchTerm);
            return true;
        });
        if (filtered.length === 0) {
            container.innerHTML = `<tr><td colspan="5" class="text-center p-4"><i class="bi bi-search fs-1 d-block mb-2" style="color: #ccc;"></i><p class="text-muted">No products found matching your criteria</p></td></tr>`;
            return;
        }
        renderFilteredProducts(filtered);
    }
    
    function renderFilteredProducts(filteredInventory) {
        const container = document.getElementById('productsContainer');
        let html = '';
        filteredInventory.forEach(p => {
            const availPieces = getAvailableStock(p.id);
            const activeUnit = activeUnitTypes[p.id] || 'piece';
            const conversion = getUnitConversion(p.id, activeUnit);
            const convertedStock = availPieces / conversion;
            const unitTypes = productUnitTypes[p.id] || [];
            
            if (!activeUnitTypes[p.id]) {
                if (unitTypes.length > 0) activeUnitTypes[p.id] = unitTypes[0].unit_type_name;
                else activeUnitTypes[p.id] = 'piece';
            }
            
            const placeholder = 'data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%2240%22 height=%2240%22%3E%3Crect fill=%22%23e0e0e0%22 width=%2240%22 height=%2240%22/%3E%3Ctext x=%2250%25%22 y=%2250%25%22 text-anchor=%22middle%22 dy=%22.3em%22 fill=%22%23999%22 font-size=%229%22%3ENo%3C/text%3E%3C/svg%3E';
            let img = placeholder;
            const productImages = productImages_data[p.id] || [];
            if (productImages.length > 0) {
                const primaryImage = productImages.find(img => img.is_primary) || productImages[0];
                img = '../uploads/products/' + primaryImage.image_path;
            } else if (p.image) { img = '../uploads/products/' + p.image; }
            
            let currPrice = p.unit_price, currUnit = 'piece';
            const currType = activeUnitTypes[p.id];
            if (unitTypes.length > 0) {
                const currentUT = unitTypes.find(ut => ut.unit_type_name === currType);
                if (currentUT) { currPrice = parseFloat(currentUT.unit_price); currUnit = currentUT.unit_type_name; }
            }
            
            let unitButtonsHtml = '';
            let unitDropdownOptions = '';
            if (unitTypes.length > 0) {
                unitTypes.forEach(ut => {
                    const shortLabel = ut.unit_type_name.substring(0, 2).toUpperCase();
                    const isActive = activeUnitTypes[p.id] === ut.unit_type_name ? 'active' : '';
                    unitButtonsHtml += `<button class="unit-btn ${isActive}" data-product-id="${p.id}" data-unit-type="${ut.unit_type_name}" onclick="event.stopPropagation(); setActiveUnit(${p.id}, '${ut.unit_type_name}')">${shortLabel}</button>`;
                    unitDropdownOptions += `<option value="${ut.unit_type_name}" ${isActive ? 'selected' : ''}>${ut.unit_type_name}</option>`;
                });
            } else {
                const opts = [
                    { type: 'piece', label: 'PC', fullLabel: 'Piece', avail: true },
                    { type: 'inner-pack', label: 'IP', fullLabel: 'Inner Pack', avail: p.price_inner_pack !== null },
                    { type: 'case', label: 'CS', fullLabel: 'Case', avail: p.price_case !== null },
                    { type: 'box', label: 'BX', fullLabel: 'Box', avail: p.price_box !== null },
                    { type: 'carton', label: 'CTN', fullLabel: 'Carton', avail: p.price_carton !== null }
                ];
                opts.forEach(o => {
                    if (o.avail) {
                        unitButtonsHtml += `<button class="unit-btn ${activeUnitTypes[p.id] === o.type ? 'active' : ''}" data-product-id="${p.id}" data-unit-type="${o.type}" onclick="event.stopPropagation(); setActiveUnit(${p.id}, '${o.type}')">${o.label}</button>`;
                        unitDropdownOptions += `<option value="${o.type}" ${activeUnitTypes[p.id] === o.type ? 'selected' : ''}>${o.fullLabel} (${o.label})</option>`;
                    }
                });
            }
            
            const stockDisplay = formatStockDisplay(convertedStock, activeUnit);
            html += `<tr id="row-${p.id}" onclick="showProductInfo(${p.id})" style="cursor: pointer;">
                <td class="product-image-cell"><img src="${img}" class="product-thumbnail" onerror="this.src='${placeholder}'">\n</td>
                <td class="product-cell"><div class="product-info"><span class="product-name">${p.name}</span><span id="stock-${p.id}" class="${convertedStock < 0 ? 'stock-warning' : 'stock-info'}">Stock: ${stockDisplay}</span>
                <div class="mobile-price-display" onclick="event.stopPropagation();"><span class="mobile-price-label">Price:</span><div class="input-group input-group-sm" style="width: auto;"><span class="input-group-text" style="padding: 2px 6px;">₱</span><input type="number" class="form-control mobile-price-input" id="mobile-price-input-${p.id}" value="${currPrice.toFixed(2)}" min="0" step="0.01" style="width: 90px; text-align: right;" onclick="event.stopPropagation();"></div><span class="mobile-price-unit" id="mobile-unit-${p.id}">/${currUnit}</span></div></div>\n</td>
                <td class="unit-column"><div class="unit-buttons desktop-only">${unitButtonsHtml}</div>
                <div class="mobile-unit-qty-container mobile-only"><select class="unit-dropdown" id="unit-dropdown-${p.id}" onchange="event.stopPropagation(); setActiveUnitFromDropdown(${p.id}, this.value)" onclick="event.stopPropagation()">${unitDropdownOptions}</select>
                <div class="quantity-controls"><button class="qty-btn" onclick="event.stopPropagation(); decQty(${p.id})"><i class="bi bi-dash"></i></button><input type="number" class="qty-input" id="qty-${p.id}" min="0" value="0" onchange="validateQuantity(${p.id})" onclick="event.stopPropagation()"><button class="qty-btn" onclick="event.stopPropagation(); incQty(${p.id})"><i class="bi bi-plus"></i></button></div></div>\n</td>
                <td class="qty-column">
                    <div class="quantity-controls desktop-only">
                        <input type="number" class="qty-input" id="qty-desktop-${p.id}" min="0" value="0" placeholder="0" onchange="validateQuantityDesktop(${p.id})" onclick="event.stopPropagation(); clearZeroOnFocus(this)" onfocus="clearZeroOnFocus(this)" onblur="restoreZeroIfEmpty(this)">
                    </div>
                \n</td>
                <td class="price-cell desktop-price-cell" id="price-display-${p.id}" onclick="event.stopPropagation()">
                    <div class="input-group input-group-sm" style="width: 130px;">
                        <span class="input-group-text">₱</span>
                        <input type="number" class="form-control price-input" id="price-${p.id}" value="${currPrice.toFixed(2)}" min="0" step="0.01" onclick="event.stopPropagation()">
                    </div>
                    <small class="d-block text-muted" style="font-size: 0.75rem; color: #2E7D32 !important;">/${currUnit}</small>
                \n</td>
             </tr>`;
        });
        container.innerHTML = html;
    }
    
    function clearZeroOnFocus(input) {
        if (input.value === '0') {
            input.value = '';
        }
    }
    
    function restoreZeroIfEmpty(input) {
        if (input.value === '' || input.value === null) {
            input.value = '0';
        }
    }
    
    function renderProducts() { 
        filterProducts(); 
    }
    
    function setActiveUnit(pid, type) {
        activeUnitTypes[pid] = type;
        const qtyInput = document.getElementById(`qty-${pid}`);
        if (qtyInput) qtyInput.value = 0;
        const convertedStock = getAvailableStock(pid) / getUnitConversion(pid, type);
        const stockEl = document.getElementById(`stock-${pid}`);
        if (stockEl) { 
            stockEl.innerHTML = `Stock: ${formatStockDisplay(convertedStock, type)}`; 
            stockEl.className = convertedStock < 0 ? 'stock-warning' : 'stock-info'; 
        }
        const product = getProductById(pid);
        const unitTypes = productUnitTypes[pid] || [];
        let currPrice = product.unit_price;
        if (unitTypes.length > 0) { 
            const currentUT = unitTypes.find(ut => ut.unit_type_name === type); 
            if (currentUT) currPrice = parseFloat(currentUT.unit_price); 
        } else { 
            if (type === 'case' && product.price_case) currPrice = product.price_case; 
            else if (type === 'inner-pack' && product.price_inner_pack) currPrice = product.price_inner_pack; 
            else if (type === 'box' && product.price_box) currPrice = product.price_box; 
            else if (type === 'carton' && product.price_carton) currPrice = product.price_carton; 
        }
        
        const priceInput = document.getElementById(`price-${pid}`);
        if (priceInput) priceInput.value = currPrice.toFixed(2);
        
        const priceCell = document.getElementById(`price-display-${pid}`);
        if (priceCell) {
            const unitLabel = priceCell.querySelector('small');
            if (unitLabel) unitLabel.textContent = `/${type}`;
        }
        
        const mobileUnitSpan = document.getElementById(`mobile-unit-${pid}`);
        if (mobileUnitSpan) mobileUnitSpan.textContent = `/${type}`;
        
        document.querySelectorAll(`[data-product-id="${pid}"]`).forEach(btn => { 
            btn.classList.remove('active'); 
            if (btn.getAttribute('data-unit-type') === type) btn.classList.add('active'); 
        });
    }
    
    function validateQuantity(pid) { 
        const inp = document.getElementById(`qty-${pid}`); 
        if (!inp) return 0; 
        let v = parseInt(inp.value) || 0; 
        if (v < 0) v = 0; 
        inp.value = v; 
        return v; 
    }
    
    function validateQuantityDesktop(pid) {
        const desktopInp = document.getElementById(`qty-desktop-${pid}`);
        if (!desktopInp) return 0;
        let v = parseInt(desktopInp.value) || 0;
        if (v < 0) v = 0;
        desktopInp.value = v;
        const mobileInp = document.getElementById(`qty-${pid}`);
        if (mobileInp) mobileInp.value = v;
        return v;
    }
    
    function bulkAddToCart() {
        let itemsAdded = 0;
        const allProducts = document.querySelectorAll('#productsContainer tr');
        allProducts.forEach(row => {
            const rowId = row.id;
            if (!rowId) return;
            const pid = parseInt(rowId.replace('row-', ''));
            const p = getProductById(pid);
            if (!p) return;
            const type = activeUnitTypes[pid] || 'piece';
            const qtyInput = document.getElementById(`qty-${pid}`);
            const qty = parseInt(qtyInput?.value) || 0;
            if (qty > 0) {
                const price = parseFloat(document.getElementById(`price-${pid}`)?.value) || p.unit_price;
                const existing = cart.find(i => i.id === pid && i.unit_type === type);
                if (existing) { existing.quantity += qty; existing.price = price; }
                else { cart.push({ id: pid, name: p.name, price, quantity: qty, sku: p.sku, unit_type: type }); }
                if (qtyInput) qtyInput.value = '0';
                itemsAdded++;
            }
        });
        if (itemsAdded === 0) { showToast('Please enter quantity for at least one item'); return; }
        updateCartBadge();
        renderProducts();
        showToast(`Added ${itemsAdded} product(s) to cart!`);
    }
    
    function escapeHtml(str) {
        if (!str) return '';
        return str.replace(/[&<>]/g, function(m) {
            if (m === '&') return '&amp;';
            if (m === '<') return '&lt;';
            if (m === '>') return '&gt;';
            return m;
        });
    }
    
    // Check if customer is walk-in and hide delivery options
    function checkIfWalkinCustomer() {
        const select = document.getElementById('modalCustomerSelect');
        const deliveryTypeSection = document.getElementById('deliveryTypeSection');
        
        if (!select || !deliveryTypeSection) return;
        
        const isLocked = <?php echo $is_customer_locked ? 'true' : 'false'; ?>;
        let isWalkin = false;
        
        if (isLocked) {
            // Check locked customer
            const lockedCustomerName = document.getElementById('lockedCustomerName')?.value || '';
            isWalkin = (lockedCustomerName.toLowerCase() === 'walk-in customer');
        } else {
            const selectedOption = select.options[select.selectedIndex];
            if (selectedOption && selectedOption.value) {
                const customerName = selectedOption.text.split('(')[0].trim().toLowerCase();
                isWalkin = (customerName === 'walk-in customer');
            }
        }
        
        if (isWalkin) {
            // For walk-in customer, hide delivery section and force pickup
            deliveryTypeSection.style.display = 'none';
            const pickupRadio = document.getElementById('deliveryPickup');
            if (pickupRadio) pickupRadio.checked = true;
            const deliveryAddressGroup = document.getElementById('deliveryAddressGroup');
            if (deliveryAddressGroup) deliveryAddressGroup.style.display = 'none';
        } else {
            // For regular customers, show delivery section
            deliveryTypeSection.style.display = 'block';
        }
    }
    
    // Setup delivery type listeners
    function setupDeliveryTypeListeners() {
        const pickupRadio = document.getElementById('deliveryPickup');
        const deliverRadio = document.getElementById('deliveryDeliver');
        const deliveryAddressGroup = document.getElementById('deliveryAddressGroup');
        
        if (pickupRadio && deliverRadio && deliveryAddressGroup) {
            pickupRadio.addEventListener('change', function() {
                if (this.checked) {
                    deliveryAddressGroup.style.display = 'none';
                }
            });
            
            deliverRadio.addEventListener('change', function() {
                if (this.checked) {
                    // Get current customer address
                    const select = document.getElementById('modalCustomerSelect');
                    let address = '';
                    let customerName = '';
                    
                    if (select) {
                        const selectedOption = select.options[select.selectedIndex];
                        if (selectedOption && selectedOption.value) {
                            address = selectedOption.dataset.address || 'No address available';
                            customerName = selectedOption.text.split('(')[0].trim();
                        } else {
                            // Check if locked customer
                            const lockedAddress = document.getElementById('lockedCustomerAddress')?.value;
                            const lockedName = document.getElementById('lockedCustomerName')?.value;
                            if (lockedAddress) {
                                address = lockedAddress;
                                customerName = lockedName || '';
                            }
                        }
                    }
                    
                    const deliveryAddressDisplay = document.getElementById('deliveryAddressDisplay');
                    if (deliveryAddressDisplay) {
                        if (address && address !== '-') {
                            deliveryAddressDisplay.innerHTML = `${customerName ? '<strong>' + escapeHtml(customerName) + '</strong><br>' : ''}${escapeHtml(address)}`;
                        } else {
                            deliveryAddressDisplay.innerHTML = '<span class="text-warning">No address on file. Please update customer address.</span>';
                        }
                    }
                    deliveryAddressGroup.style.display = 'block';
                }
            });
        }
    }
    
   function viewCart() {
    if (!cart.length) { 
        showToast('Cart is empty'); 
        return; 
    }
    
    const reviewDiv = document.getElementById('reviewItems');
    
    // Build receipt table with editable quantity inputs
    let html = '<table class="receipt-table">';
    html += '<thead><tr>';
    html += '<th>Product</th>';
    html += '<th>Unit</th>';
    html += '<th>Qty</th>';
    html += '<th>Price</th>';
    html += '<th>Total</th>';
    html += '<th></th>';
    html += '</tr></thead><tbody>';
    
    cart.forEach((i) => { 
        const pieces = i.quantity * getUnitConversion(i.id, i.unit_type);
        const qtyInputId = `review_qty_${i.id}_${i.unit_type.replace(/\s/g, '_')}`;
        const totalSpanId = `review_total_${i.id}_${i.unit_type.replace(/\s/g, '_')}`;
        
        html += `<tr id="review-row-${i.id}-${i.unit_type.replace(/\s/g, '_')}">
            <td class="product-name-cell">${escapeHtml(i.name)}</td>
            <td class="unit-cell"><span>${escapeHtml(i.unit_type)}</span></td>
            <td class="qty-cell">
                <input type="number" id="${qtyInputId}" class="review-qty-input" value="${i.quantity}" min="1" 
                       onchange="updateReviewQuantityFromInput(${i.id}, '${escapeHtml(i.unit_type)}', this.value)">
                <div class="pieces-small">(${pieces} pcs)</div>
            </td>
            <td class="price-cell"><span>${formatCurrency(i.price)}</span></td>
            <td class="total-cell"><span id="${totalSpanId}"><strong>${formatCurrency(i.price * i.quantity)}</strong></span></td>
            <td class="action-cell">
                <button class="delete-item-btn" onclick="removeFromCartAndRefresh(${i.id}, '${escapeHtml(i.unit_type)}')">
                    <i class="bi bi-trash"></i>
                </button>
            </td>
        </tr>`;
    });
    
    html += '</tbody>';
    reviewDiv.innerHTML = html;
    
    const subtotal = cart.reduce((s, i) => s + i.price * i.quantity, 0);
    document.getElementById('reviewTotal').textContent = formatCurrency(subtotal);
    
    // Reset delivery type to pickup
    const pickupRadio = document.getElementById('deliveryPickup');
    const deliveryAddressGroup = document.getElementById('deliveryAddressGroup');
    if (pickupRadio) pickupRadio.checked = true;
    if (deliveryAddressGroup) deliveryAddressGroup.style.display = 'none';
    
    const select = document.getElementById('modalCustomerSelect');
    const isLocked = <?php echo $is_customer_locked ? 'true' : 'false'; ?>;
    
    if (select) {
        if (!isLocked) {
            const newSelect = select.cloneNode(true);
            select.parentNode.replaceChild(newSelect, select);
            const preSelectedCustomerId = <?php echo $pre_selected_customer_id; ?>;
            if (preSelectedCustomerId > 0) {
                const option = Array.from(newSelect.options).find(opt => opt.value == preSelectedCustomerId);
                if (option) newSelect.value = preSelectedCustomerId;
            }
            newSelect.addEventListener('change', function() {
                handleCustomerChange(this);
                checkIfWalkinCustomer();
            });
            if (preSelectedCustomerId > 0) {
                handleCustomerChange(newSelect);
                checkIfWalkinCustomer();
            }
        } else {
            const lockedCustomerId = document.getElementById('lockedCustomerId')?.value;
            const lockedCustomerName = document.getElementById('lockedCustomerName')?.value;
            const lockedCustomerEmail = document.getElementById('lockedCustomerEmail')?.value;
            const lockedCustomerPhone = document.getElementById('lockedCustomerPhone')?.value;
            const lockedCustomerAddress = document.getElementById('lockedCustomerAddress')?.value;
            const lockedPriceLevel = document.getElementById('lockedPriceLevel')?.value;
            
            if (lockedCustomerId) {
                document.getElementById('reviewCustomer').textContent = lockedCustomerName || '-';
                document.getElementById('reviewEmail').textContent = lockedCustomerEmail || '-';
                document.getElementById('reviewPhone').textContent = lockedCustomerPhone || '-';
                document.getElementById('reviewAddress').textContent = lockedCustomerAddress || '-';
                
                if (lockedPriceLevel) {
                    reloadProductPrices(lockedPriceLevel);
                }
            }
            checkIfWalkinCustomer();
        }
    }
    
    new bootstrap.Modal(document.getElementById('cartModal')).show();
}

function updateReviewQuantityFromInput(itemId, unitType, newValue) {
    const cartIndex = cart.findIndex(i => i.id === itemId && i.unit_type === unitType);
    if (cartIndex === -1) return;
    
    let newQty = parseInt(newValue);
    if (isNaN(newQty) || newQty < 1) {
        newQty = 1;
    }
    
    cart[cartIndex].quantity = newQty;
    
    const qtyInputId = `review_qty_${itemId}_${unitType.replace(/\s/g, '_')}`;
    const qtyInput = document.getElementById(qtyInputId);
    if (qtyInput) qtyInput.value = newQty;
    
    const totalSpanId = `review_total_${itemId}_${unitType.replace(/\s/g, '_')}`;
    const price = cart[cartIndex].price;
    const totalSpan = document.getElementById(totalSpanId);
    if (totalSpan) {
        totalSpan.innerHTML = `<strong>${formatCurrency(price * newQty)}</strong>`;
    }
    
    const pieces = newQty * getUnitConversion(itemId, unitType);
    const row = document.getElementById(`review-row-${itemId}-${unitType.replace(/\s/g, '_')}`);
    if (row) {
        const piecesSpan = row.querySelector('.pieces-small');
        if (piecesSpan) {
            piecesSpan.textContent = `(${pieces} pcs)`;
        }
    }
    
    const subtotal = cart.reduce((sum, item) => sum + (item.price * item.quantity), 0);
    document.getElementById('reviewTotal').textContent = formatCurrency(subtotal);
    updateCartBadge();
}
    
    function handleCustomerChange(selectElement) {
        const opt = selectElement.options[selectElement.selectedIndex];
        if (opt && opt.value) {
            document.getElementById('reviewCustomer').textContent = opt.text.split('(')[0].trim();
            document.getElementById('reviewEmail').textContent = opt.dataset.email || '-';
            document.getElementById('reviewPhone').textContent = opt.dataset.phone || '-';
            document.getElementById('reviewAddress').textContent = opt.dataset.address || '-';
            const priceLevel = opt.dataset.priceLevel || 'Standard';
            reloadProductPrices(priceLevel);
            
            // Update delivery address display if deliver is selected
            const deliverRadio = document.getElementById('deliveryDeliver');
            if (deliverRadio && deliverRadio.checked) {
                const deliveryAddressDisplay = document.getElementById('deliveryAddressDisplay');
                const address = opt.dataset.address || 'No address available';
                const customerName = opt.text.split('(')[0].trim();
                if (deliveryAddressDisplay) {
                    if (address && address !== '-') {
                        deliveryAddressDisplay.innerHTML = `${customerName ? '<strong>' + escapeHtml(customerName) + '</strong><br>' : ''}${escapeHtml(address)}`;
                    } else {
                        deliveryAddressDisplay.innerHTML = '<span class="text-warning">No address on file. Please update customer address.</span>';
                    }
                }
            }
            
            // Check if walk-in and hide delivery options
            checkIfWalkinCustomer();
        } else {
            document.getElementById('reviewCustomer').textContent = '-';
            document.getElementById('reviewEmail').textContent = '-';
            document.getElementById('reviewPhone').textContent = '-';
            document.getElementById('reviewAddress').textContent = '-';
            
            // Show delivery section for no selection
            const deliveryTypeSection = document.getElementById('deliveryTypeSection');
            if (deliveryTypeSection) deliveryTypeSection.style.display = 'block';
        }
    }
    
    function removeFromCartAndRefresh(id, unit_type) {
    cart = cart.filter(i => !(i.id === id && i.unit_type === unit_type));
    updateCartBadge();
    
    const cartModal = document.getElementById('cartModal');
    if (cartModal && cartModal.classList.contains('show')) {
        const reviewDiv = document.getElementById('reviewItems');
        
        if (cart.length === 0) {
            reviewDiv.innerHTML = '<p class="text-muted text-center">No items in cart</p>';
            setTimeout(() => {
                const modal = bootstrap.Modal.getInstance(cartModal);
                if (modal) modal.hide();
            }, 1500);
        } else {
            // Rebuild the table
            let html = '<table class="receipt-table"><thead><tr>';
            html += '<th>Product</th><th>Unit</th><th>Qty</th><th>Price</th><th>Total</th><th></th>';
            html += '</tr></thead><tbody>';
            
            cart.forEach(i => {
                const pieces = i.quantity * getUnitConversion(i.id, i.unit_type);
                const qtyInputId = `review_qty_${i.id}_${i.unit_type.replace(/\s/g, '_')}`;
                const totalSpanId = `review_total_${i.id}_${i.unit_type.replace(/\s/g, '_')}`;
                
                html += `<tr id="review-row-${i.id}-${i.unit_type.replace(/\s/g, '_')}">
                    <td class="product-name-cell">${escapeHtml(i.name)}</td>
                    <td class="unit-cell"><span>${escapeHtml(i.unit_type)}</span></td>
                    <td class="qty-cell">
                        <input type="number" id="${qtyInputId}" class="review-qty-input" value="${i.quantity}" min="1" 
                               onchange="updateReviewQuantityFromInput(${i.id}, '${escapeHtml(i.unit_type)}', this.value)">
                        <div class="pieces-small">(${pieces} pcs)</div>
                    </td>
                    <td class="price-cell"><span>${formatCurrency(i.price)}</span></td>
                    <td class="total-cell"><span id="${totalSpanId}"><strong>${formatCurrency(i.price * i.quantity)}</strong></span></td>
                    <td class="action-cell">
                        <button class="delete-item-btn" onclick="removeFromCartAndRefresh(${i.id}, '${escapeHtml(i.unit_type)}')">
                            <i class="bi bi-trash"></i>
                        </button>
                    </td>
                </tr>`;
            });
            html += '</tbody>';
            reviewDiv.innerHTML = html;
            
            const subtotal = cart.reduce((s, i) => s + i.price * i.quantity, 0);
            document.getElementById('reviewTotal').textContent = formatCurrency(subtotal);
        }
    }
    
    renderProducts();
    showToast('Item removed from cart');
}
    
    function clearCart() {
        if (cart.length === 0) { showToast('Cart is already empty'); return; }
        Swal.fire({ title: 'Clear Cart?', text: 'Are you sure you want to remove all items from your cart?', icon: 'warning', showCancelButton: true, confirmButtonColor: '#dc3545', cancelButtonColor: '#6c757d', confirmButtonText: 'Yes, Clear' }).then((result) => {
            if (result.isConfirmed) {
                cart = [];
                updateCartBadge();
                document.getElementById('reviewItems').innerHTML = '<p class="text-muted text-center">No items in cart</p>';
                document.getElementById('reviewTotal').textContent = formatCurrency(0);
                document.getElementById('reviewCustomer').textContent = '-';
                document.getElementById('reviewEmail').textContent = '-';
                document.getElementById('reviewPhone').textContent = '-';
                document.getElementById('reviewAddress').textContent = '-';
                const customerSelect = document.getElementById('modalCustomerSelect');
                if (customerSelect) customerSelect.value = '';
                // Reset delivery type
                const pickupRadio = document.getElementById('deliveryPickup');
                if (pickupRadio) pickupRadio.checked = true;
                const deliveryAddressGroup = document.getElementById('deliveryAddressGroup');
                if (deliveryAddressGroup) deliveryAddressGroup.style.display = 'none';
                const cartModal = bootstrap.Modal.getInstance(document.getElementById('cartModal'));
                if (cartModal) cartModal.hide();
                renderProducts();
                showToast('Cart cleared successfully');
            }
        });
    }
    
    function submitOrder() {
    const select = document.getElementById('modalCustomerSelect');
    const custId = select?.value ? parseInt(select.value) : 0;
    const opt = select?.options[select.selectedIndex];
    if (!custId) { showToast('Please select a customer'); return; }
    
    // Check if customer is walk-in
    let customerName = '';
    let isWalkin = false;
    
    if (opt && opt.value) {
        customerName = opt.text.split('(')[0].trim().toLowerCase();
        isWalkin = (customerName === 'walk-in customer');
    } else {
        const lockedCustomerName = document.getElementById('lockedCustomerName')?.value || '';
        isWalkin = (lockedCustomerName.toLowerCase() === 'walk-in customer');
    }
    
    let deliveryType = 'pickup';
    let deliveryAddress = '';
    
    if (!isWalkin) {
        deliveryType = document.querySelector('input[name="deliveryType"]:checked')?.value || 'pickup';
        
        if (deliveryType === 'deliver') {
            if (opt && opt.value) {
                deliveryAddress = opt.dataset.address || '';
            } else {
                deliveryAddress = document.getElementById('lockedCustomerAddress')?.value || '';
            }
            
            if (!deliveryAddress || deliveryAddress === '-') {
                showToast('Customer has no address on file. Please update customer address before scheduling delivery.');
                return;
            }
        }
    }
    
    let allPricesValid = true;
    for (let i = 0; i < cart.length; i++) {
        const standardPrice = inventory.find(p => p.id === cart[i].id)?.unit_price || cart[i].price;
        if (cart[i].price < standardPrice) { 
            showToast(`Item "${cart[i].name}" price is below standard price ${formatCurrency(standardPrice)}`); 
            allPricesValid = false; 
            break; 
        }
    }
    if (!allPricesValid) return;
    
    const items = cart.map(i => ({ id: i.id, name: i.name, price: i.price, quantity: i.quantity, sku: i.sku, unit_type: i.unit_type }));
    const subtotal = cart.reduce((s, i) => s + i.price * i.quantity, 0);
    
    const btn = document.getElementById('confirmOrderBtn');
    const orig = btn.innerHTML;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Processing...';
    btn.disabled = true;
    
    const orderStatus = isWalkin ? 'delivered' : 'pending';
    
    const postData = { 
        action: 'submit_order', 
        customer_id: custId, 
        customer_name: opt ? opt.text.split('(')[0].trim() : document.getElementById('lockedCustomerName')?.value || '', 
        email: opt?.dataset?.email || document.getElementById('lockedCustomerEmail')?.value || '', 
        phone: opt?.dataset?.phone || document.getElementById('lockedCustomerPhone')?.value || '', 
        address: opt?.dataset?.address || document.getElementById('lockedCustomerAddress')?.value || '', 
        items: JSON.stringify(items), 
        discount_percent: 0,
        agent_location: deliveryType === 'deliver' ? deliveryAddress : '',
        order_status: orderStatus
    };
    
    const formBody = Object.keys(postData).map(key => encodeURIComponent(key) + '=' + encodeURIComponent(postData[key])).join('&');
    fetch(window.location.href, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' }, body: formBody })
        .then(r => r.text()).then(t => { if (!t || t.trim().startsWith('<')) throw new Error('Invalid response'); return JSON.parse(t); })
        .then(d => {
            // Hide modal first
            const cartModal = bootstrap.Modal.getInstance(document.getElementById('cartModal'));
            if (cartModal) cartModal.hide();
            
            if (d.success) {
                if (d.updated_stock) { d.updated_stock.forEach(i => { const p = inventory.find(p => p.id === i.item_id); if (p) p.stock = i.stock; }); }
                cart = [];
                updateCartBadge();
                
                // Calculate totals
                const totalAmount = d.total_amount || subtotal;
                const itemCount = items.reduce((sum, item) => sum + item.quantity, 0);
                
                // Build HTML content for alert
                let alertHtml = `
                    <div style="text-align: left; margin-top: 15px; padding: 15px; background: #f8f9fa; border-radius: 12px;">
                        <div style="display: flex; justify-content: space-between; margin-bottom: 10px; padding-bottom: 8px; border-bottom: 1px solid #e0e0e0;">
                            <span style="font-weight: 600; color: #555;">Order Number:</span>
                            <span style="color: #047857; font-weight: 700;">${d.so_number}</span>
                        </div>
                        <div style="display: flex; justify-content: space-between; margin-bottom: 10px; padding-bottom: 8px; border-bottom: 1px solid #e0e0e0;">
                            <span style="font-weight: 600; color: #555;">Customer:</span>
                            <span style="color: #333;">${escapeHtml(postData.customer_name)}</span>
                        </div>
                        <div style="display: flex; justify-content: space-between; margin-bottom: 10px; padding-bottom: 8px; border-bottom: 1px solid #e0e0e0;">
                            <span style="font-weight: 600; color: #555;">Items:</span>
                            <span style="color: #333;">${itemCount} pcs</span>
                        </div>
                        <div style="display: flex; justify-content: space-between; margin-bottom: 10px; padding-bottom: 8px; border-bottom: 1px solid #e0e0e0;">
                            <span style="font-weight: 600; color: #555;">Total Amount:</span>
                            <span style="color: #047857; font-weight: 700; font-size: 18px;">${formatCurrency(totalAmount)}</span>
                        </div>
                        ${!isWalkin && deliveryType === 'deliver' ? `
                        <div style="display: flex; justify-content: space-between; margin-top: 8px;">
                            <span style="font-weight: 600; color: #555;"><i class="bi bi-geo-alt"></i> Delivery:</span>
                            <span style="color: #666; font-size: 12px;">${escapeHtml(deliveryAddress)}</span>
                        </div>
                        ` : ''}
                        ${isWalkin ? `
                        <div style="margin-top: 10px; padding: 8px; background: #e8f5e9; border-radius: 8px; text-align: center;">
                            <i class="bi bi-check-circle-fill" style="color: #047857;"></i>
                            <span style="color: #047857; font-size: 12px;"> Walk-in order completed</span>
                        </div>
                        ` : deliveryType === 'pickup' ? `
                        <div style="margin-top: 10px; padding: 8px; background: #e3f2fd; border-radius: 8px; text-align: center;">
                            <i class="bi bi-box-seam" style="color: #2196F3;"></i>
                            <span style="color: #2196F3; font-size: 12px;"> Ready for pickup at branch</span>
                        </div>
                        ` : ''}
                    </div>
                `;
                
                // Show alert
                Swal.fire({
                    icon: 'success',
                    title: isWalkin ? 'Order Completed!' : 'Order Submitted!',
                    html: alertHtml,
                    confirmButtonText: 'View Order',
                    cancelButtonText: 'Close',
                    showCancelButton: true,
                    confirmButtonColor: '#047857',
                    cancelButtonColor: '#6c757d',
                    background: '#ffffff',
                    backdrop: `rgba(0,0,0,0.4)`,
                    allowOutsideClick: false,
                    customClass: {
                        popup: 'animated-order-alert',
                        confirmButton: 'order-confirm-btn',
                        cancelButton: 'order-cancel-btn'
                    }
                }).then((result) => {
                    if (result.isConfirmed) {
                        // View Order - go to sales_order.php with order ID
                        window.location.href = `sales_order.php?view_order=${d.so_id}`;
                    } else {
                        // Close - go to customer_list.php
                        window.location.href = 'customer_list.php';
                    }
                });
            } else { 
                Swal.fire({ 
                    icon: 'error', 
                    title: 'Order Failed', 
                    text: d.message || 'Failed to submit order. Please try again.',
                    confirmButtonColor: '#dc3545'
                }).then(() => {
                    // Reload the page to reset form
                    window.location.reload();
                });
            }
            btn.innerHTML = orig;
            btn.disabled = false;
        })
        .catch(e => { 
            console.error('Submit error:', e); 
            Swal.fire({ 
                icon: 'error', 
                title: 'Connection Error', 
                text: 'Unable to connect to server. Please check your internet connection and try again.',
                confirmButtonColor: '#dc3545'
            }).then(() => {
                window.location.reload();
            });
            btn.innerHTML = orig; 
            btn.disabled = false; 
        });
}
    
    function showProductInfo(pid) {
        const modal = new bootstrap.Modal(document.getElementById('productInfoModal'));
        document.getElementById('loadingState').style.display = 'block';
        document.getElementById('productContent').style.display = 'none';
        modal.show();
        const fd = new FormData();
        fd.append('action', 'get_product_details');
        fd.append('product_id', pid);
        fd.append('price_level', getSelectedPriceLevel());
        fetch(window.location.href, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    const p = data.product;
                    document.getElementById('modalProductName').textContent = p.item_name || 'Product';
                    const placeholder = 'data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%22120%22 height=%22120%22%3E%3Crect fill=%22%23e0e0e0%22 width=%22120%22 height=%22120%22/%3E%3Ctext x=%2250%25%22 y=%2250%25%22 text-anchor=%22middle%22 dy=%22.3em%22 fill=%22%23999%22 font-size=%2212%22%3ENo Image%3C/text%3E%3C/svg%3E';
                    let mainImg = placeholder;
                    if (data.images && data.images.length > 0) {
                        const primaryImage = data.images.find(img => img.is_primary) || data.images[0];
                        mainImg = '../uploads/products/' + primaryImage.image_path;
                    } else if (p.product_image_url) { mainImg = '../uploads/products/' + p.product_image_url; }
                    document.getElementById('modalProductImage').src = mainImg;
                    document.getElementById('modalProductCode').textContent = p.item_code || '-';
                    document.getElementById('modalProductCategory').textContent = p.category || '-';
                    document.getElementById('modalProductDescription').textContent = p.description || '-';
                    document.getElementById('modalProductPrice').textContent = formatCurrency(parseFloat(p.unit_price || 0));
                    const unitType = activeUnitTypes[pid] || 'piece';
                    const conversion = getUnitConversion(pid, unitType);
                    const convertedStock = getAvailableStock(pid) / conversion;
                    document.getElementById('modalProductStock').innerHTML = formatStockDisplay(convertedStock, unitType);
                    let histHtml = '';
                    if (data.order_history && data.order_history.length) {
                        data.order_history.forEach(o => {
                            const d = new Date(o.order_date).toLocaleDateString();
                            const sc = o.order_status === 'pending' ? 'status-pending' : (o.order_status === 'cancelled' ? 'status-cancelled' : 'status-completed');
                            histHtml += `<tr><td>${d}</td><td>${o.so_number}</td><td>${o.customer_name}</td><td>${o.unit_type}</td><td>${o.quantity_ordered}</td><td><span class="status-badge ${sc}">${o.order_status}</span></td></tr>`;
                        });
                    } else { histHtml = '<tr><td colspan="6" class="text-center">No history</td></tr>'; }
                    document.getElementById('modalOrderHistory').innerHTML = histHtml;
                    document.getElementById('loadingState').style.display = 'none';
                    document.getElementById('productContent').style.display = 'block';
                } else { showToast('Error loading product details'); modal.hide(); }
            })
            .catch(e => { console.error('Error:', e); showToast('Error loading details'); modal.hide(); });
    }
    
    // Sidebar functions
    function toggleSidebarDropdown(event, targetId) {
        event.preventDefault(); event.stopPropagation();
        const target = document.getElementById(targetId);
        const btn = event.currentTarget;
        const arrow = btn.querySelector('.dropdown-arrow');
        const sidebar = document.getElementById('sidebar');
        if (sidebar.classList.contains('collapsed')) {
            sidebar.classList.remove('collapsed');
            localStorage.setItem('sidebarCollapsed', 'false');
            setTimeout(() => { document.querySelectorAll('.sidebar .collapse.show').forEach(collapse => { if (collapse.id !== targetId) collapse.classList.remove('show'); }); target.classList.add('show'); if (arrow) arrow.style.transform = 'translateY(-50%) rotate(180deg)'; }, 50);
            return;
        }
        if (target.classList.contains('show')) { target.classList.remove('show'); if (arrow) arrow.style.transform = 'translateY(-50%) rotate(0deg)'; }
        else { document.querySelectorAll('.sidebar .collapse.show').forEach(collapse => collapse.classList.remove('show')); target.classList.add('show'); if (arrow) arrow.style.transform = 'translateY(-50%) rotate(180deg)'; }
    }
    
    function toggleDropdown(event, dropdownId) {
        event.preventDefault(); event.stopPropagation();
        const dropdown = document.getElementById(dropdownId);
        const btn = event.currentTarget;
        if (!dropdown) return;
        if (dropdown.classList.contains('show')) { dropdown.classList.remove('show'); btn.classList.remove('active'); }
        else { ['inventoryDropdownMenu', 'salesDropdownMenu', 'purchaseDropdownMenu', 'moreDropdownMenu'].forEach(id => { const d = document.getElementById(id); if (d && d !== dropdown) d.classList.remove('show'); }); document.querySelectorAll('.more-btn').forEach(b => b.classList.remove('active')); dropdown.classList.add('show'); btn.classList.add('active'); setTimeout(() => { document.addEventListener('click', function closeHandler(e) { if (!dropdown.contains(e.target) && !btn.contains(e.target)) { dropdown.classList.remove('show'); btn.classList.remove('active'); document.removeEventListener('click', closeHandler); } }); }, 100); }
    }
    
    function toggleSidebar() {
        const s = document.getElementById('sidebar');
        if (window.innerWidth <= 992) {
            s.classList.toggle('active');
            if (!document.querySelector('.sidebar-overlay')) { const o = document.createElement('div'); o.className = 'sidebar-overlay'; document.body.appendChild(o); o.addEventListener('click', closeMobileSidebar); setTimeout(() => o.classList.add('active'), 10); }
        } else {
            s.classList.toggle('collapsed');
            localStorage.setItem('sidebarCollapsed', s.classList.contains('collapsed'));
            document.querySelectorAll('.nav-text').forEach(t => t.style.display = s.classList.contains('collapsed') ? 'none' : 'inline-block');
            document.querySelector('.main-content').style.marginLeft = s.classList.contains('collapsed') ? '80px' : '250px';
        }
    }
    
    function closeMobileSidebar() { document.getElementById('sidebar').classList.remove('active'); const o = document.querySelector('.sidebar-overlay'); if (o) { o.classList.remove('active'); setTimeout(() => o.remove(), 300); } }
    
    function initializeSidebar() {
        const s = document.getElementById('sidebar');
        if (window.innerWidth > 992) {
            const saved = localStorage.getItem('sidebarCollapsed') === 'true';
            s.classList.toggle('collapsed', saved);
            document.querySelectorAll('.nav-text').forEach(t => t.style.display = saved ? 'none' : 'inline-block');
            document.querySelector('.main-content').style.marginLeft = saved ? '80px' : '250px';
        } else { s.classList.remove('active', 'collapsed'); document.querySelectorAll('.nav-text').forEach(t => t.style.display = 'inline-block'); document.querySelector('.main-content').style.marginLeft = '0'; }
    }
    
    function handleSidebarResize() {
        const s = document.getElementById('sidebar');
        const o = document.querySelector('.sidebar-overlay');
        if (window.innerWidth > 992) { if (o) o.remove(); s.classList.remove('active'); const saved = localStorage.getItem('sidebarCollapsed') === 'true'; s.classList.toggle('collapsed', saved); document.querySelectorAll('.nav-text').forEach(t => t.style.display = saved ? 'none' : 'inline-block'); document.querySelector('.main-content').style.marginLeft = saved ? '80px' : '250px'; }
        else { s.classList.remove('collapsed'); document.querySelectorAll('.nav-text').forEach(t => t.style.display = 'inline-block'); document.querySelector('.main-content').style.marginLeft = '0'; }
    }
    
    function showProfileModal() { new bootstrap.Modal(document.getElementById('profileModal')).show(); }
    
    function confirmLogout() {
        const modal = bootstrap.Modal.getInstance(document.getElementById('profileModal'));
        if (modal) modal.hide();
        Swal.fire({ title: 'Are you sure?', text: 'You will be logged out', icon: 'question', showCancelButton: true, confirmButtonColor: '#07d826', confirmButtonText: 'Yes, logout' }).then((result) => { if (result.isConfirmed) { localStorage.removeItem('sidebarCollapsed'); window.location.href = '../logout.php'; } });
    }
    
    function logout() { confirmLogout(); }
    
    function initMobileNav() {
        const mobileNav = document.getElementById('mobileNav');
        if (!mobileNav) return;
        if (window.innerWidth <= 992) {
            mobileNav.style.display = 'block';
            const currentPage = window.location.pathname.split('/').pop();
            mobileNav.querySelectorAll('.nav-link:not(.logout-btn)').forEach(link => { if (link.getAttribute('href') === currentPage) link.classList.add('active'); else link.classList.remove('active'); });
        } else { mobileNav.style.display = 'none'; }
    }
    
    document.addEventListener('DOMContentLoaded', function() {
        initializeSidebar();
        initMobileNav();
        document.getElementById('mobileToggleBtn')?.addEventListener('click', (e) => { e.stopPropagation(); toggleSidebar(); });
        document.getElementById('desktopToggleBtn')?.addEventListener('click', (e) => { e.stopPropagation(); toggleSidebar(); });
        document.querySelectorAll('.sidebar .nav-link').forEach(l => { l.addEventListener('click', () => { if (window.innerWidth <= 992) closeMobileSidebar(); }); });
        window.addEventListener('resize', function() { handleSidebarResize(); initMobileNav(); });
        loadAllProductUnitTypes().then(() => { loadAllProductImages().then(() => { renderProducts(); updateCartBadge(); setupSearch(); }); });
        
        // Setup delivery type listeners
        setupDeliveryTypeListeners();
    });
</script>
</body>
</html>
