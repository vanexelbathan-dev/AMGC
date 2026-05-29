<?php
// Enable error reporting for debugging - only log, don't display
error_reporting(E_ALL);
ini_set('display_errors', 0);  // CRITICAL: prevent HTML errors from breaking JSON
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

// Get customer ID and name from URL parameters
$pre_selected_customer_id = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : 0;
$pre_selected_customer_name = isset($_GET['customer_name']) ? htmlspecialchars($_GET['customer_name']) : '';
$is_customer_locked = ($pre_selected_customer_id > 0); // Lock customer if selected from customer list

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
    $user_initials = 'SL';
}

// Check database connection
if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error());
}

// ========== HELPER FUNCTIONS FOR UNIT CONVERSION ==========

/**
 * Get default UOM info for an item (using per-item default_unit_type_id)
 */
function getItemDefaultUOMInfo($conn, $item_id, $branch_id = 0, $items_branch_column_exists = false) {
    $query = "
        SELECT ut.unit_type_name, COALESCE(ut.quantity_smallest_pack, 1) as multiplier, ut.unit_type_id
        FROM items i
        JOIN unit_types ut ON i.default_unit_type_id = ut.unit_type_id
        WHERE i.item_id = ? AND ut.status = 'active'
        LIMIT 1
    ";
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        return ['unit_type_name' => 'Piece', 'multiplier' => 1, 'unit_type_id' => 0];
    }
    $stmt->bind_param('i', $item_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    
    if ($row) {
        return [
            'unit_type_name' => $row['unit_type_name'],
            'multiplier' => (int)($row['multiplier'] ?? 1),
            'unit_type_id' => (int)$row['unit_type_id']
        ];
    }
    // Fallback: use the first active unit type for this item
    $fallback_query = "
        SELECT ut.unit_type_name, COALESCE(ut.quantity_smallest_pack, 1) as multiplier, ut.unit_type_id
        FROM item_unit_pricing iup
        JOIN unit_types ut ON iup.unit_type_id = ut.unit_type_id
        WHERE iup.item_id = ? AND ut.status = 'active'
        LIMIT 1
    ";
    $stmt2 = $conn->prepare($fallback_query);
    if ($stmt2) {
        $stmt2->bind_param('i', $item_id);
        $stmt2->execute();
        $result2 = $stmt2->get_result();
        $row2 = $result2->fetch_assoc();
        if ($row2) {
            return [
                'unit_type_name' => $row2['unit_type_name'],
                'multiplier' => (int)($row2['multiplier'] ?? 1),
                'unit_type_id' => (int)$row2['unit_type_id']
            ];
        }
    }
    return ['unit_type_name' => 'Piece', 'multiplier' => 1, 'unit_type_id' => 0];
}

/**
 * Get the number of pieces (smallest unit) for a given item and unit type.
 * Uses quantity_smallest_pack from unit_types table.
 */
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


/**
 * Resolve the current stock stored in DEFAULT UOM for an item.
 * Priority:
 * 1) stock_in_default_uom when > 0
 * 2) stock column as fallback (legacy setups where stock is saved in default UOM)
 */
function getCurrentItemDefaultStock($conn, $item_id, $branch_id = 0, $items_branch_column_exists = false, $view_all_branches = false, $stock_in_default_uom_column_exists = false) {
    if ($items_branch_column_exists && !$view_all_branches) {
        $query = $stock_in_default_uom_column_exists
            ? "SELECT COALESCE(stock, 0) AS stock, COALESCE(stock_in_default_uom, 0) AS stock_in_default_uom FROM items WHERE item_id = ? AND branch_id = ? LIMIT 1"
            : "SELECT COALESCE(stock, 0) AS stock, 0 AS stock_in_default_uom FROM items WHERE item_id = ? AND branch_id = ? LIMIT 1";
        $stmt = $conn->prepare($query);
        if (!$stmt) {
            return ['default_stock' => 0.0, 'raw_stock' => 0.0, 'stock_in_default_uom' => 0.0];
        }
        $stmt->bind_param('ii', $item_id, $branch_id);
    } else {
        $query = $stock_in_default_uom_column_exists
            ? "SELECT COALESCE(stock, 0) AS stock, COALESCE(stock_in_default_uom, 0) AS stock_in_default_uom FROM items WHERE item_id = ? LIMIT 1"
            : "SELECT COALESCE(stock, 0) AS stock, 0 AS stock_in_default_uom FROM items WHERE item_id = ? LIMIT 1";
        $stmt = $conn->prepare($query);
        if (!$stmt) {
            return ['default_stock' => 0.0, 'raw_stock' => 0.0, 'stock_in_default_uom' => 0.0];
        }
        $stmt->bind_param('i', $item_id);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    $raw_stock = (float)($row['stock'] ?? 0);
    $stock_in_default_uom = (float)($row['stock_in_default_uom'] ?? 0);
    $default_stock = $stock_in_default_uom > 0 ? $stock_in_default_uom : $raw_stock;

    return [
        'default_stock' => $default_stock,
        'raw_stock' => $raw_stock,
        'stock_in_default_uom' => $stock_in_default_uom
    ];
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


// Check if stock_in_default_uom column exists in items table
$stock_in_default_uom_column_exists = false;
$check_default_stock_column = $conn->query("SHOW COLUMNS FROM items LIKE 'stock_in_default_uom'");
if ($check_default_stock_column && $check_default_stock_column->num_rows > 0) {
    $stock_in_default_uom_column_exists = true;
}

// Get all items (stock is already in pieces)
$items = [];

if ($items_branch_column_exists) {
    if ($view_all_branches) {
        $items_query = "SELECT i.item_id, i.item_code, i.item_name, i.description, i.category, 
                    i.stock, i.stock_in_default_uom, i.unit_type, i.unit_price, i.price_case, i.price_inner_pack, 
                    i.price_box, i.price_carton, i.reorder_level, i.status,
                    i.product_image_url,
                    b.branch_name
                    FROM items i
                    LEFT JOIN branches b ON i.branch_id = b.branch_id
                    WHERE i.status = 'active'
                    ORDER BY i.category ASC, i.item_name ASC";
    } else {
        $items_query = "SELECT i.item_id, i.item_code, i.item_name, i.description, i.category, 
                    i.stock, i.stock_in_default_uom, i.unit_type, i.unit_price, i.price_case, i.price_inner_pack, 
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
                i.stock, i.stock_in_default_uom, i.unit_type, i.unit_price, i.price_case, i.price_inner_pack, 
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

// Get all unit types and quantities for each item (using smallest pack as base)
$all_items_unit_types = [];
foreach ($items as $item) {
    $item_id = $item['item_id'];
    
    $unit_query = "
        SELECT ut.unit_type_id, ut.unit_type_name, COALESCE(ut.quantity_smallest_pack, 1) as quantity_smallest_pack
        FROM item_unit_pricing iup
        JOIN unit_types ut ON iup.unit_type_id = ut.unit_type_id
        WHERE iup.item_id = ? AND ut.status = 'active'
        ORDER BY ut.is_default_uom DESC
    ";
    
    $unit_stmt = $conn->prepare($unit_query);
    if ($unit_stmt) {
        $unit_stmt->bind_param('i', $item_id);
        $unit_stmt->execute();
        $unit_result = $unit_stmt->get_result();
        
        $item_units = [];
        while ($unit_row = $unit_result->fetch_assoc()) {
            $item_units[$unit_row['unit_type_name']] = (int)$unit_row['quantity_smallest_pack'];
        }
        $unit_stmt->close();
        
        $all_items_unit_types[$item_id] = $item_units;
    }
}

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

// Build inventory array with per-item default UOM for JavaScript
$inventory_data = [];
foreach ($items as $item) {
    $default_info = getItemDefaultUOMInfo($conn, $item['item_id'], $branch_id, $items_branch_column_exists);
    
    $default_multiplier = max(1, (int)($default_info['multiplier'] ?? 1));
    $stock_in_default_uom = ($stock_in_default_uom_column_exists && isset($item['stock_in_default_uom'])) ? (float)$item['stock_in_default_uom'] : 0;
    $raw_stock = isset($item['stock']) ? (float)$item['stock'] : 0;
    $default_stock = $stock_in_default_uom > 0 ? $stock_in_default_uom : $raw_stock;
    $stock_smallest = $default_stock * $default_multiplier;

    $inventory_data[] = [
        'id' => (int)$item['item_id'],
        'name' => $item['item_name'],
        'sku' => $item['item_code'],
        'category' => !empty($item['category']) ? $item['category'] : 'Uncategorized',
        'unit_price' => (float)$item['unit_price'],
        'price_case' => isset($item['price_case']) ? (float)$item['price_case'] : null,
        'price_inner_pack' => isset($item['price_inner_pack']) ? (float)$item['price_inner_pack'] : null,
        'price_box' => isset($item['price_box']) ? (float)$item['price_box'] : null,
        'price_carton' => isset($item['price_carton']) ? (float)$item['price_carton'] : null,
        'default_stock' => (float)$default_stock,
        'stock_smallest' => (float)$stock_smallest,
        'stock_in_default_uom' => (float)$stock_in_default_uom,
        'raw_stock' => (float)$raw_stock,
        'unit_type' => $item['unit_type'],
        'default_unit_type_name' => $default_info['unit_type_name'],
        'default_unit_multiplier' => $default_multiplier,
        'default_unit_type_id' => $default_info['unit_type_id'],
        'image' => $item['product_image_url'] ?? null
    ];
}
$inventory_json = json_encode($inventory_data);

// Store unit conversions for JavaScript
$unit_conversions_json = json_encode($all_items_unit_types);

// Handle AJAX request to get product unit types with conversions
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
        
        echo json_encode([
            'success' => true,
            'unit_types' => $unit_types
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

// Handle AJAX request for product details
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
                            i.stock, i.stock_in_default_uom, i.unit_type, i.unit_price, i.price_case, i.price_inner_pack, 
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
                            i.stock, i.stock_in_default_uom, i.unit_type, i.unit_price, i.price_case, i.price_inner_pack, 
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
        
        $images_query = "SELECT image_id, image_path, image_order, is_primary FROM item_images WHERE item_id = ? ORDER BY is_primary DESC, image_order ASC";
        $images_stmt = $conn->prepare($images_query);
        $images_stmt->bind_param('i', $product_id);
        $images_stmt->execute();
        $images_result = $images_stmt->get_result();
        $images = $images_result->fetch_all(MYSQLI_ASSOC);
        
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
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
        exit;
    }
}

// Handle order submission via AJAX (with stock deduction using conversion)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_order') {
    header('Content-Type: application/json');
    
    try {
        if (!$conn) {
            throw new Exception("Database connection failed");
        }
        
        $conn->begin_transaction();
        
        error_log("========== ORDER SUBMISSION STARTED ==========");
        error_log("POST data: " . print_r($_POST, true));
        
        $customer_id = isset($_POST['customer_id']) ? (int)$_POST['customer_id'] : 0;
        $customer_name = isset($_POST['customer_name']) ? trim($_POST['customer_name']) : '';
        $email = isset($_POST['email']) ? trim($_POST['email']) : '';
        $phone = isset($_POST['phone']) ? trim($_POST['phone']) : '';
        $address = isset($_POST['address']) ? trim($_POST['address']) : '';
        $items_data = isset($_POST['items']) ? json_decode($_POST['items'], true) : [];
        $discount_percent = isset($_POST['discount_percent']) ? (float)$_POST['discount_percent'] : 0;
        $agent_location = isset($_POST['agent_location']) ? trim($_POST['agent_location']) : '';
        
        error_log("Customer ID: $customer_id, Customer Name: $customer_name, Discount: $discount_percent%");
        error_log("Items data count: " . count($items_data));
        
        if (empty($items_data)) {
            throw new Exception("No items in cart");
        }
        
        $user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
        $branch_id = isset($_SESSION['branch_id']) ? (int)$_SESSION['branch_id'] : 0;
        $view_all_branches = isset($_SESSION['view_all_branches']) ? $_SESSION['view_all_branches'] : false;
        
        error_log("User ID from session: $user_id, Branch ID: $branch_id");
        
        if ($user_id === 0) {
            throw new Exception("User session invalid. Please log in again.");
        }
        
        // Create/update customer
        if ($customer_id === 0 && !empty($customer_name)) {
            error_log("Creating/updating customer: $customer_name");
            
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
                error_log("Updated existing customer ID: $customer_id");
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
                error_log("Created new customer ID: $customer_id");
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
        $insert_values = [$so_number, $customer_id, $branch_id, $order_date, $total_amount, 'pending', $user_id];
        
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
        error_log("Sales order created with ID: $so_id");
        
        // Insert order items and deduct inventory using proper conversion
        $sql_items = "INSERT INTO sales_order_items (so_id, item_id, unit_type, quantity_ordered, unit_price)
                    VALUES (?, ?, ?, ?, ?)";
        $stmt_items = $conn->prepare($sql_items);
        
        $updated_stock_data = [];
        
        foreach ($items_data as $item) {
            $item_id = (int)$item['id'];
            $quantity = (int)$item['quantity'];
            $unit_price = (float)$item['price'];
            $unit_type = isset($item['unit_type']) ? $item['unit_type'] : 'Piece';
            
            error_log("========== PROCESSING ITEM ==========");
            error_log("Item ID: $item_id, Order Unit Type: $unit_type, Order Quantity: $quantity");
            // Get conversion multiplier for the ordered unit type (smallest units per selected unit)
            $ordered_unit_multiplier = max(1, getItemUnitQuantity($conn, $item_id, $unit_type, $branch_id, $items_branch_column_exists, $view_all_branches));
            $default_info = getItemDefaultUOMInfo($conn, $item_id, $branch_id, $items_branch_column_exists);
            $default_unit_multiplier = max(1, (int)($default_info['multiplier'] ?? 1));

            error_log("Ordered Unit Type: $unit_type (multiplier: $ordered_unit_multiplier smallest units per unit)");
            error_log("Default UOM Multiplier: $default_unit_multiplier");

            // Convert selected-unit quantity to smallest units first
            $smallest_units_to_deduct = $quantity * $ordered_unit_multiplier;
            // Then convert smallest units back to DEFAULT UOM stock
            $default_units_to_deduct = $smallest_units_to_deduct / $default_unit_multiplier;

            error_log("Calculation: $quantity × $ordered_unit_multiplier = $smallest_units_to_deduct smallest units");
            error_log("Deduct in default UOM: $smallest_units_to_deduct / $default_unit_multiplier = $default_units_to_deduct");

            $stmt_items->bind_param('iisid', $so_id, $item_id, $unit_type, $quantity, $unit_price);
            $stmt_items->execute();

            $current_stock_info = getCurrentItemDefaultStock($conn, $item_id, $branch_id, $items_branch_column_exists, $view_all_branches, $stock_in_default_uom_column_exists);
            $current_default_stock = (float)$current_stock_info['default_stock'];
            $new_default_stock = $current_default_stock - $default_units_to_deduct;
            $new_smallest_stock = $new_default_stock * $default_unit_multiplier;

            error_log("Current Default Stock: $current_default_stock");
            error_log("New Default Stock: $new_default_stock");
            error_log("New Smallest Stock: $new_smallest_stock");

            if ($items_branch_column_exists && !$view_all_branches) {
                if ($stock_in_default_uom_column_exists) {
                    $sql_deduct = "UPDATE items SET stock_in_default_uom = ?, stock = ? WHERE item_id = ? AND branch_id = ?";
                    $stmt_deduct = $conn->prepare($sql_deduct);
                    $stmt_deduct->bind_param('ddii', $new_default_stock, $new_smallest_stock, $item_id, $branch_id);
                } else {
                    $sql_deduct = "UPDATE items SET stock = ? WHERE item_id = ? AND branch_id = ?";
                    $stmt_deduct = $conn->prepare($sql_deduct);
                    $stmt_deduct->bind_param('dii', $new_default_stock, $item_id, $branch_id);
                }
            } else {
                if ($stock_in_default_uom_column_exists) {
                    $sql_deduct = "UPDATE items SET stock_in_default_uom = ?, stock = ? WHERE item_id = ?";
                    $stmt_deduct = $conn->prepare($sql_deduct);
                    $stmt_deduct->bind_param('ddi', $new_default_stock, $new_smallest_stock, $item_id);
                } else {
                    $sql_deduct = "UPDATE items SET stock = ? WHERE item_id = ?";
                    $stmt_deduct = $conn->prepare($sql_deduct);
                    $stmt_deduct->bind_param('di', $new_default_stock, $item_id);
                }
            }
            $stmt_deduct->execute();

            $updated_stock_data[] = [
                'item_id' => $item_id,
                'stock' => $new_default_stock,
                'stock_smallest' => $new_smallest_stock
            ];
            
            error_log("Stock Update Summary:");
            error_log("  - Deducted: $pieces_to_deduct pieces");
            error_log("  - New Stock (in pieces): $new_stock_pieces");
            error_log("========== ITEM PROCESSING COMPLETE ==========");
        }
        
        $conn->commit();
        error_log("Order submitted successfully! SO Number: $so_number");
        
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

// Handle cancel order (restore stock)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel_order') {
    header('Content-Type: application/json');
    
    try {
        $order_id = isset($_POST['order_id']) ? (int)$_POST['order_id'] : 0;
        
        if ($order_id <= 0) {
            throw new Exception("Invalid order ID");
        }
        
        $conn->begin_transaction();
        
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
        
        $items_sql = "SELECT soi.item_id, soi.quantity_ordered, soi.unit_type
                    FROM sales_order_items soi 
                    WHERE soi.so_id = ?";
        $items_stmt = $conn->prepare($items_sql);
        $items_stmt->bind_param('i', $order_id);
        $items_stmt->execute();
        $items_result = $items_stmt->get_result();
        $order_items = $items_result->fetch_all(MYSQLI_ASSOC);
        
        $restored_stock_data = [];
        
        foreach ($order_items as $item) {
            $item_id = (int)$item['item_id'];
            $quantity = (int)$item['quantity_ordered'];
            $unit_type = $item['unit_type'];
            $pieces_multiplier = max(1, getItemUnitQuantity($conn, $item_id, $unit_type, $branch_id, $items_branch_column_exists, $view_all_branches));
            $default_info = getItemDefaultUOMInfo($conn, $item_id, $branch_id, $items_branch_column_exists);
            $default_unit_multiplier = max(1, (int)($default_info['multiplier'] ?? 1));

            $smallest_units_to_restore = $quantity * $pieces_multiplier;
            $default_units_to_restore = $smallest_units_to_restore / $default_unit_multiplier;

            $current_stock_info = getCurrentItemDefaultStock($conn, $item_id, $branch_id, $items_branch_column_exists, $view_all_branches, $stock_in_default_uom_column_exists);
            $current_default_stock = (float)$current_stock_info['default_stock'];
            $new_default_stock = $current_default_stock + $default_units_to_restore;
            $new_smallest_stock = $new_default_stock * $default_unit_multiplier;

            if ($items_branch_column_exists && !$view_all_branches) {
                if ($stock_in_default_uom_column_exists) {
                    $sql_restore = "UPDATE items SET stock_in_default_uom = ?, stock = ? WHERE item_id = ? AND branch_id = ?";
                    $restore_stmt = $conn->prepare($sql_restore);
                    $restore_stmt->bind_param('ddii', $new_default_stock, $new_smallest_stock, $item_id, $branch_id);
                } else {
                    $sql_restore = "UPDATE items SET stock = ? WHERE item_id = ? AND branch_id = ?";
                    $restore_stmt = $conn->prepare($sql_restore);
                    $restore_stmt->bind_param('dii', $new_default_stock, $item_id, $branch_id);
                }
            } else {
                if ($stock_in_default_uom_column_exists) {
                    $sql_restore = "UPDATE items SET stock_in_default_uom = ?, stock = ? WHERE item_id = ?";
                    $restore_stmt = $conn->prepare($sql_restore);
                    $restore_stmt->bind_param('ddi', $new_default_stock, $new_smallest_stock, $item_id);
                } else {
                    $sql_restore = "UPDATE items SET stock = ? WHERE item_id = ?";
                    $restore_stmt = $conn->prepare($sql_restore);
                    $restore_stmt->bind_param('di', $new_default_stock, $item_id);
                }
            }
            $restore_stmt->execute();

            $restored_stock_data[] = [
                'item_id' => $item_id,
                'stock' => $new_default_stock,
                'stock_smallest' => $new_smallest_stock
            ];
        }
        
        $update_sql = "UPDATE sales_orders SET order_status = 'cancelled' WHERE so_id = ?";
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param('i', $order_id);
        $update_stmt->execute();
        
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
?>
<!DOCTYPE html>
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
    <!-- SweetAlert2 -->
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        * {
            box-sizing: border-box;
            -webkit-tap-highlight-color: transparent;
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
            background: #1B5E20;
        }
        
        .navbar-top .btn-success .badge {
            font-size: 10px;
            padding: 3px 5px;
            top: -5px;
            right: -5px;
            background: #1B5E20 !important;
            border: 2px solid #FFFFFF;
        }
                
        /* Alert for missing branch column */
        .alert-info {
            background-color: #F5F5F5;
            border-color: #e0e0e0;
            color: #212121;
            border-radius: 8px;
            margin: 10px;
            font-size: 13px;
        }

        /* ===== CATEGORY TABS DESIGN ===== */
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
            -webkit-overflow-scrolling: touch;
            scrollbar-width: thin;
            padding-bottom: 5px;
        }
        
        .tabs-scroll::-webkit-scrollbar {
            height: 4px;
        }
        
        .tabs-scroll::-webkit-scrollbar-track {
            background: #F5F5F5;
            border-radius: 4px;
        }
        
        .tabs-scroll::-webkit-scrollbar-thumb {
            background: #2E7D32;
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
            background: #F5F5F5;
            color: #047857;
        }
        
        .tab-btn.active {
            background: #047857;
            color: #FFFFFF;
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
            background: #FFFFFF;
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
            background: #047857;
            color: #FFFFFF;
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
            border-bottom: 1px solid #e0e0e0;
            vertical-align: middle;
        }
        
        /* Desktop default - full columns */
        .product-table th:nth-child(1) { width: 8%; }
        .product-table th:nth-child(2) { width: 22%; }
        .product-table th:nth-child(3) { width: 20%; }
        .product-table th:nth-child(4) { width: 12%; }
        .product-table th:nth-child(5) { width: 15%; }
        
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
            font-size: 11px;
            margin-bottom: 2px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        /* Stock info - can show negative numbers */
        .stock-info {
            font-size: 9px;
            color: #2E7D32;
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
            background: #047857;
            border: none;
            color: #FFFFFF;
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
            background: #1B5E20;
        }
        
        /* No disabled state - always enabled */
        .add-cart-btn:disabled {
            background: #ccc;
            cursor: not-allowed;
            opacity: 0.6;
        }
        
        .view-btn {
            background: #17a2b8;
            border: none;
            color: #FFFFFF;
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
        
        /* Unit type buttons */
        .unit-buttons {
            display: flex;
            gap: 2px;
            flex-wrap: wrap;
            justify-content: center;
        }
        
        .unit-btn {
            background: #FFFFFF;
            border: 1px solid #e0e0e0;
            border-radius: 3px;
            padding: 2px 3px;
            font-size: 8px;
            font-weight: 600;
            color: #212121;
            min-width: 22px;
            cursor: pointer;
        }
        
        .unit-btn:active {
            transform: scale(0.95);
            background: #F5F5F5;
        }
        
        .unit-btn.active {
            background: #047857;
            color: #FFFFFF;
            border-color: #047857;
        }
        
        /* Unit dropdown for mobile */
        .unit-dropdown {
            display: none;
            width: 100%;
            padding: 8px 10px;
            border: 1px solid #e0e0e0;
            border-radius: 6px;
            font-size: 13px;
            background: white;
            cursor: pointer;
        }
        
        .unit-dropdown:focus {
            outline: none;
            border-color: #047857;
        }
        
        /* Price Column */
        .price-cell {
            font-weight: 700;
            color: #047857;
            font-size: 11px;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 4px;
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
            background: #FFFFFF;
            border: 1px solid #e0e0e0;
            border-radius: 4px;
            font-size: 12px;
            font-weight: bold;
            color: #212121;
            cursor: pointer;
            padding: 0;
        }
        
        .qty-btn:active {
            transform: scale(0.95);
            background: #F5F5F5;
        }
        
        .qty-input {
            width: 67px !important;
            height: 25px !important;
            text-align: center;
            border: 1px solid #e0e0e0;
            border-radius: 4px;
            font-size: 13px !important;
            padding: 0 4px;
            -moz-appearance: textfield;
        }
        
        .qty-input:focus {
            outline: none;
            border-color: #047857;
        }
        
        /* Desktop/Web at Mobile visibility classes */
        .desktop-only {
            display: flex;
        }
        
        .mobile-only {
            display: none;
        }
        
        .mobile-unit-qty-container {
            display: none;
        }
        
        @media (min-width: 769px) {
            .mobile-price-display {
                display: none !important;
            }
            
            .product-table th:nth-child(1) { width: 6%; }
            .product-table th:nth-child(2) { width: 22%; text-align: left !important; }
            .product-table th:nth-child(3) { width: 20%; }
            .product-table th:nth-child(4) { width: 12%; }
            .product-table th:nth-child(5) { width: 20%; }
            
            .product-table th:nth-child(5) {
                display: table-cell;
            }
            
            .product-table td:nth-child(5) {
                display: table-cell;
            }
            
            .price-cell {
                display: flex !important;
            }
            
            .product-thumbnail {
                width: 40px;
                height: 40px;
            }
            
            .product-name {
                font-size: 13px;
            }
            
            .stock-info, .stock-warning {
                font-size: 10px;
            }
            
            .unit-btn {
                padding: 4px 6px;
                font-size: 10px;
                min-width: 32px;
            }
            
            .qty-btn {
                width: 28px;
                height: 28px;
                font-size: 12px;
            }
            
            .qty-input {
                width: 55px;
                height: 28px;
                font-size: 11px;
            }
            
            .add-cart-btn, .view-btn {
                width: 32px;
                height: 32px;
                font-size: 14px;
            }
            
            .desktop-only {
                display: flex !important;
            }
            
            .mobile-only {
                display: none !important;
            }
        }
        
        /* ===== MOBILE VIEW ===== */
        @media (max-width: 768px) {
            body, .main-content {
                overflow-x: hidden;
                width: 100%;
                max-width: 100%;
            }
            
            .main-content {
                padding: 6px !important;
                padding-bottom: 100px !important;
                width: 100%;
                max-width: 100%;
                overflow-x: hidden;
            }
            
            .navbar-top {
                padding: 6px 10px;
            }
            
            .page-title h2 {
                font-size: 14px;
            }
            
            .page-title p {
                font-size: 9px;
                display: none;
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
            
            /* Table container */
            .product-table-container {
                overflow-x: visible !important;
                width: 100%;
                margin: 0;
            }
            
            .product-table {
                width: 100%;
                min-width: 100%;
                table-layout: fixed;
            }
            
            .product-table thead tr th {
                text-align: center !important;
                font-size: 9px;
                padding: 6px 2px;
                white-space: nowrap;
            }
            
            /* Column widths - balanced */
            .product-table th:nth-child(1) { width: 20%; }
            .product-table th:nth-child(2) { width: 34%; }
            .product-table th:nth-child(3) { width: 18%; }
            .product-table th:nth-child(4) { width: 13%; }
            
            /* Hide original price column */
            .product-table th:nth-child(5) {
                display: none;
            }
            
            .product-table td:nth-child(5) {
                display: none;
            }
            
            .product-table td {
                font-size: 9px;
                padding: 6px 2px;
                vertical-align: middle;
                word-break: break-word;
            }
            
            /* Product cell */
            .product-table td:nth-child(2) {
                text-align: left;
                padding: 4px 2px;
            }
            
            .product-name {
                font-size: 10px;
                font-weight: 600;
                margin-bottom: 2px;
                white-space: normal;
                word-break: break-word;
                line-height: 1.2;
            }
            
            /* Stock info */
            .stock-info, .stock-warning {
                font-size: 8px;
                display: block;
            }
            
            /* Mobile price display - inside product column */
            .mobile-price-display {
                display: flex;
                align-items: center;
                flex-wrap: wrap;
                gap: 4px;
                margin-top: 4px;
                padding-top: 4px;
                border-top: 1px dashed #e0e0e0;
                width: 100%;
            }
            
            .mobile-price-label {
                font-size: 8px;
                color: #666;
                font-weight: normal;
            }
            
            .mobile-price-input {
                width: 60px !important;
                font-size: 9px !important;
                padding: 2px 3px !important;
                height: 24px !important;
            }
            
            .mobile-price-unit {
                font-size: 8px;
                color: #999;
            }
            
            /* Product image */
            .product-thumbnail {
                width: 50px;
                height: 50px;
            }
            
            /* Unit buttons - compact */
            .unit-buttons {
                display: flex;
                flex-wrap: wrap;
                gap: 2px;
                justify-content: center;
            }
            
            .unit-btn {
                padding: 2px 3px;
                font-size: 7px;
                min-width: 30px;
                min-height: 25px;
            }
            
            .unit-dropdown {
                display: none !important;
            }
            
            /* Quantity controls - mas malaki ng konti */
            .quantity-controls {
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 2px;
            }
            
            .qty-btn {
                width: 20px;
                height: 20px;
                font-size: 9px;
                padding: 0;
                margin: 0;
            }
            
            .qty-input {
                width: 32px;
                height: 20px;
                font-size: 9px;
                text-align: center;
                padding: 0;
                margin: 0;
            }
            
            /* ACTION column - mas maliit */
            .product-table th:nth-child(6) {
                width: 40px;
                padding: 6px 2px;
            }
            
            .product-table td:nth-child(6) {
                padding: 2px 2px !important;
                text-align: center;
                width: 40px;
            }
            
            .action-buttons {
                display: flex;
                gap: 1px;
                justify-content: center;
                align-items: center;
                margin: 0;
                padding: 0;
            }
            
            .add-cart-btn {
                width: 28px;
                height: 28px;
                font-size: 12px;
                padding: 0;
                margin: 0;
                display: flex;
                align-items: center;
                justify-content: center;
                border-radius: 4px;
                background: #047857;
                border: none;
                color: white;
                cursor: pointer;
            }
            
            /* Hide desktop-only elements */
            .mobile-unit-qty-container {
                display: none !important;
            }
            
            .desktop-only {
                display: flex !important;
            }
            
            .mobile-only {
                display: none !important;
            }
            
            /* Hide original price cell completely on mobile */
            .price-cell {
                display: none !important;
            }
            
            /* Adjust bulk add button */
            .products-section .d-flex {
                justify-content: flex-end;
                margin-bottom: 8px;
            }
            
            .products-section .btn-success {
                font-size: 10px;
                padding: 5px 10px;
                white-space: nowrap;
            }
        }
        
        .toast-notification {
            position: fixed;
            top: 20px;
            right: 20px;
            left: 20px;
            background: #047857;
            color: #FFFFFF;
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
        
        .navbar-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            padding: 12px 15px;
            margin-bottom: 15px;
            background: #FFFFFF;
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
            color: #212121;
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
            color: #2E7D32;
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
        
        .no-results {
            text-align: center;
            padding: 40px;
            background: #FFFFFF;
            border-radius: 10px;
            color: #666;
        }
        
        .no-results i {
            font-size: 48px;
            color: #e0e0e0;
            margin-bottom: 10px;
        }

        .modal-header {
            background: #047857;
            color: #FFFFFF;
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
        
        .customer-selection {
            background: #F5F5F5;
            border-radius: 8px;
            padding: 12px;
            margin-bottom: 15px;
            border-left: 4px solid #2E7D32;
        }
        
        .customer-selection .form-select {
            border: 1px solid #e0e0e0;
            border-radius: 6px;
            padding: 8px 10px;
            font-size: 13px;
            height: auto;
            cursor: pointer;
        }
        
        .product-info-container {
            padding: 15px;
        }
        
        .product-header-section {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
            background: #F5F5F5;
            padding: 15px;
            border-radius: 10px;
        }
        
        .product-image-large {
            width: 120px;
            height: 120px;
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
        
        .history-table {
            width: 100%;
            font-size: 12px;
            border-collapse: collapse;
        }
        
        .history-table th {
            background: #047857;
            color: #FFFFFF;
            padding: 8px 5px;
            font-size: 11px;
        }
        
        .history-table td {
            padding: 6px 5px;
            border-bottom: 1px solid #e0e0e0;
            text-align: center;
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
        
        .loading-state {
            text-align: center;
            padding: 40px;
        }
        
        .history-table th,
        .history-table td {
            text-align: center;
            vertical-align: middle;
        }

        .discount-line {
            color: #dc3545;
            font-weight: 500;
        }

        .credit-terms-line {
            color: #17a2b8;
            font-weight: 500;
            border-top: 1px dashed #e0e0e0;
            padding-top: 8px;
            margin-top: 8px;
        }

        /* Sweet Alert Styles */
        .swal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 10000;
            animation: fadeIn 0.2s ease;
        }
        
        .swal-modal {
            background: white;
            border-radius: 16px;
            width: 90%;
            max-width: 400px;
            padding: 0;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            animation: slideIn 0.3s ease;
            overflow: hidden;
        }
        
        .swal-header {
            padding: 20px 20px 10px;
            text-align: center;
            border-bottom: none;
        }
        
        .swal-icon {
            margin: 0 auto 15px;
            width: 80px;
            height: 80px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #2E7D32 0%, #1B5E20 100%);
        }
        
        .swal-icon i {
            font-size: 48px;
            color: white;
        }
        
        .swal-icon.warning {
            background: linear-gradient(135deg, #FF9800 0%, #F57C00 100%);
        }
        
        .swal-icon.success {
            background: linear-gradient(135deg, #2E7D32 0%, #1B5E20 100%);
        }
        
        .swal-icon.info {
            background: linear-gradient(135deg, #2196F3 0%, #1976D2 100%);
        }
        
        .swal-icon.offline {
            background: linear-gradient(135deg, #FF9800 0%, #F57C00 100%);
        }
        
        .swal-title {
            font-size: 22px;
            font-weight: 700;
            color: #212121;
            margin-bottom: 8px;
        }
        
        .swal-text {
            color: #666;
            font-size: 15px;
            line-height: 1.5;
            padding: 0 20px;
            text-align: center;
        }
        
        .swal-content {
            padding: 10px 20px 20px;
        }
        
        .swal-details {
            background: #F5F5F5;
            border-radius: 12px;
            padding: 15px;
            margin-top: 15px;
            text-align: left;
        }
        
        .swal-detail-row {
            display: flex;
            justify-content: space-between;
            padding: 6px 0;
            border-bottom: 1px dashed #e0e0e0;
        }
        
        .swal-detail-row:last-child {
            border-bottom: none;
        }
        
        .swal-detail-label {
            font-weight: 600;
            color: #555;
            font-size: 13px;
        }
        
        .swal-detail-value {
            color: #212121;
            font-weight: 500;
            font-size: 13px;
        }
        
        .swal-footer {
            padding: 15px 20px 20px;
            display: flex;
            gap: 10px;
            justify-content: center;
            border-top: 1px solid #e0e0e0;
        }
        
        .swal-btn {
            padding: 12px 24px;
            border: none;
            border-radius: 25px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            flex: 1;
            max-width: 160px;
        }
        
        .swal-btn-primary {
            background: linear-gradient(135deg, #2E7D32 0%, #1B5E20 100%);
            color: white;
        }
        
        .swal-btn-primary:hover,
        .swal-btn-primary:active {
            background: #1B5E20;
            transform: scale(0.98);
        }
        
        .swal-btn-secondary {
            background: #f0f0f0;
            color: #666;
        }
        
        .swal-btn-secondary:hover,
        .swal-btn-secondary:active {
            background: #e0e0e0;
            transform: scale(0.98);
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .pending-orders-badge {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: linear-gradient(135deg, #FF9800 0%, #F57C00 100%);
            color: white;
            padding: 12px 20px;
            border-radius: 30px;
            z-index: 9999;
            font-size: 14px;
            font-weight: 600;
            box-shadow: 0 4px 15px rgba(255, 152, 0, 0.4);
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 10px;
            transition: all 0.3s ease;
            border: none;
            animation: slideUp 0.3s ease;
        }
        
        .pending-orders-badge:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(255, 152, 0, 0.5);
        }
        
        .pending-orders-badge i {
            font-size: 20px;
        }
        
        .pending-orders-badge .badge-count {
            background: rgba(255, 255, 255, 0.3);
            padding: 2px 8px;
            border-radius: 15px;
            font-size: 12px;
            margin-left: 8px;
        }
        
        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
               /* ===== FIXED TABLE LAYOUT PARA PANTAY ANG COLUMNS ===== */
.receipt-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 12px;
    table-layout: fixed; /* IMPORTANT: Fixed layout para pantay ang columns */
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

/* FIXED COLUMN WIDTHS - pantay at proportionado */
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
    width: 15%; 
    text-align: center; 
}

.receipt-table th:nth-child(4), 
.receipt-table td:nth-child(4) { 
    width: 18%; 
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

/* Review modal quantity input */
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

/* Pieces text styling */
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

/* Unit column */
.receipt-table td:nth-child(2) span {
    font-size: 11px;
}

/* Price and total values */
.receipt-table td:nth-child(4) span,
.receipt-table td:nth-child(5) span {
    font-weight: 600;
    color: #047857;
}

/* Delete button styling */
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

.delete-item-btn .btn-text {
    display: none;
}

/* ===== MOBILE RESPONSIVE ===== */
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
    
    /* Adjust column widths for mobile */
    .receipt-table th:nth-child(1), 
    .receipt-table td:nth-child(1) { width: 28%; }
    
    .receipt-table th:nth-child(2), 
    .receipt-table td:nth-child(2) { width: 10%; }
    
    .receipt-table th:nth-child(3), 
    .receipt-table td:nth-child(3) { width: 18%; }
    
    .receipt-table th:nth-child(4), 
    .receipt-table td:nth-child(4) { width: 17%; }
    
    .receipt-table th:nth-child(5), 
    .receipt-table td:nth-child(5) { width: 20%; }
    
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
    
    .delete-item-btn .btn-text {
        display: inline;
        font-size: 8px;
        margin-left: 2px;
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
}
        /* Modal body adjustments */
        @media (max-width: 768px) {
            #cartModal .modal-body {
                padding: 8px !important;
            }
            
            #cartModal .customer-selection {
                padding: 8px;
                margin-bottom: 10px;
            }
            
            #cartModal .customer-selection h6 {
                font-size: 12px;
                margin-bottom: 6px;
            }
            
            #cartModal .customer-selection .form-select {
                font-size: 11px;
                padding: 5px 8px;
            }
            
            #cartModal .alert.bg-light {
                padding: 8px;
                font-size: 10px;
            }
            
            #cartModal .alert.bg-light p {
                margin-bottom: 3px;
            }
            
            #cartModal .alert.bg-light strong {
                font-size: 10px;
            }
            
            .discount-line, .credit-terms-line {
                font-size: 10px;
            }
            
            #cartModal .modal-footer .btn {
                padding: 6px 12px;
                font-size: 12px;
            }
        }
                /* ===== COMPACT CUSTOMER SELECTION STYLES ===== */
        
        /* Customer selection section - mas compact */
        .customer-selection {
            background: #F5F5F5;
            border-radius: 8px;
            padding: 10px 12px;
            margin-bottom: 15px;
            border-left: 4px solid #2E7D32;
        }
        
        .customer-selection h6 {
            font-size: 13px;
            margin-bottom: 8px;
            font-weight: 600;
        }
        
        .customer-selection .form-select {
            border: 1px solid #e0e0e0;
            border-radius: 6px;
            padding: 6px 10px;
            font-size: 12px;
            height: auto;
            cursor: pointer;
        }
        
        /* Locked customer alert - mas compact */
        .customer-selection .alert-info {
            font-size: 11px;
            padding: 6px 10px;
            margin-top: 8px;
            margin-bottom: 0;
            background-color: #e8f8e9;
            border-color: #b8f0c4;
            color: #0c6014;
            border-radius: 6px;
        }
        
        .customer-selection .alert-info i {
            font-size: 11px;
        }
        
        /* Para sa mobile - mas compact */
        @media (max-width: 768px) {
            .customer-selection {
                padding: 8px 10px;
                margin-bottom: 12px;
            }
            
            .customer-selection h6 {
                font-size: 12px;
                margin-bottom: 6px;
            }
            
            .customer-selection .form-select {
                padding: 5px 8px;
                font-size: 11px;
            }
            
            .customer-selection .alert-info {
                font-size: 10px;
                padding: 5px 8px;
                margin-top: 6px;
                line-height: 1.3;
            }
            
            .customer-selection .alert-info i {
                font-size: 10px;
            }
        }
        
        /* Extra small devices */
        @media (max-width: 480px) {
            .customer-selection {
                padding: 6px 8px;
            }
            
            .customer-selection h6 {
                font-size: 11px;
                margin-bottom: 5px;
            }
            
            .customer-selection .form-select {
                padding: 4px 6px;
                font-size: 10px;
            }
            
            .customer-selection .alert-info {
                font-size: 9px;
                padding: 4px 6px;
                margin-top: 5px;
            }
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
                            <i class="bi bi-speedometer2"></i>
                            <span class="nav-text">Dashboard</span>
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
                    <li class="nav-item">
                        <a class="nav-link" href="sales_order.php">
                            <i class="bi bi-list-check"></i>
                            <span class="nav-text">Sales Orders</span>
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
            <div class="navbar-top">
                <button class="mobile-toggle-btn" id="mobileToggleBtn">
                    <i class="bi bi-list"></i>
                </button>
                <div class="page-title">
                    <h2>Order Products</h2>
                    <p>Select products and quantities to create an order</p>
                </div>
                <button class="btn btn-success position-relative" type="button" onclick="viewCart()" title="View Cart">
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
                                <th></th>
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
                                            <th style="display: none;">Price</th>
                                            <th style="display: none;">Total</th>
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
                            <?php 
                                // Get the full customer data for the locked customer
                                $locked_customer = null;
                                foreach ($customers as $customer) {
                                    if ($customer['customer_id'] == $pre_selected_customer_id) {
                                        $locked_customer = $customer;
                                        break;
                                    }
                                }
                                // Use customer name from database, not from URL
                                $locked_customer_name = $locked_customer ? $locked_customer['customer_name'] : $pre_selected_customer_name;
                            ?>
                            <input type="hidden" id="lockedCustomerId" value="<?php echo $pre_selected_customer_id; ?>">
                            <input type="hidden" id="lockedCustomerName" value="<?php echo htmlspecialchars($locked_customer_name); ?>">
                            <input type="hidden" id="lockedCustomerEmail" value="<?php echo htmlspecialchars($locked_customer['email'] ?? ''); ?>">
                            <input type="hidden" id="lockedCustomerPhone" value="<?php echo htmlspecialchars($locked_customer['phone_number'] ?? ''); ?>">
                            <input type="hidden" id="lockedCustomerAddress" value="<?php echo htmlspecialchars($locked_customer['address'] ?? ''); ?>">
                            <input type="hidden" id="lockedPriceLevel" value="<?php echo htmlspecialchars($locked_customer['price_level'] ?? 'Standard'); ?>">
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

                    <h6 class="mb-3">Order Total</h6>
                    <div class="alert bg-light">
                        <div class="d-flex justify-content-between mb-2">
                            <span>Subtotal:</span>
                            <span id="reviewSubtotal">₱0.00</span>
                        </div>
                        <div class="d-flex justify-content-between mb-2 discount-line" id="discountLine" style="display: none;">
                            <span>Discount (<span id="discountPercent">0</span>%):</span>
                            <span>-₱<span id="discountAmount">0.00</span></span>
                        </div>
                        <div class="d-flex justify-content-between credit-terms-line" id="creditTermsLine" style="display: none;">
                            <span>Credit Terms:</span>
                            <span><span id="creditTermsDays">0</span> days, Limit: ₱<span id="creditLimitAmount">0</span></span>
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

    <!-- Mobile Bottom Navigation -->
    <div class="mobile-nav" id="mobileNav">
        <ul class="nav">
            <li class="nav-item">
                <a class="nav-link" href="currentinventory.php">
                    <i class="bi bi-speedometer2"></i>
                    <span>Dashboard</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="customer.php">
                    <i class="bi bi-people"></i>
                    <span>Customers</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="returnedmerchandise.php">
                    <i class="bi bi-arrow-counterclockwise"></i>
                    <span>Returns</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="sales_order.php">
                    <i class="bi bi-list-check"></i>
                    <span>Sales Orders</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link logout-btn" href="#" onclick="showProfileModal(); return false;">
                    <i class="bi bi-box-arrow-right"></i>
                    <span>Logout</span>
                </a>
            </li>
        </ul>
    </div>

    <!-- Mobile Profile/Logout Modal -->
    <div class="modal fade" id="profileModal" tabindex="-1" aria-labelledby="profileModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="profileModalLabel">
                        <i class="bi bi-person-circle me-2"></i>User Profile
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-center">
                    <div class="user-avatar-large mb-3">
                        <?php echo $user_initials; ?>
                    </div>
                    <h4 class="mb-1"><?php echo htmlspecialchars($user_name); ?></h4>
                    <p class="text-muted mb-3">
                        <span class="badge bg-success"><?php echo ucfirst($user_role); ?></span>
                    </p>
                    <?php if (!$view_all_branches && $branch_id > 0): ?>
                    <div class="branch-info mb-3">
                        <i class="bi bi-building me-1"></i>
                        <span><?php echo htmlspecialchars($branch_name); ?></span>
                    </div>
                    <?php endif; ?>
                    <button class="btn btn-danger btn-lg w-100" onclick="confirmLogout()">
                        <i class="bi bi-box-arrow-right me-2"></i>Logout
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Helper to escape HTML to prevent XSS
        function escapeHtml(str) {
            if (!str) return '';
            return String(str).replace(/[&<>]/g, function(m) {
                if (m === '&') return '&amp;';
                if (m === '<') return '&lt;';
                if (m === '>') return '&gt;';
                return m;
            });
        }

        // Safe modal show function with fallback
        function safeShowModal(modalElementId) {
            const modalEl = document.getElementById(modalElementId);
            if (!modalEl) {
                console.error('Modal element not found:', modalElementId);
                showToast('Error: Modal not found');
                return false;
            }
            
            // Try Bootstrap 5 modal API
            if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                try {
                    const modal = new bootstrap.Modal(modalEl);
                    modal.show();
                    return true;
                } catch (e) {
                    console.warn('Bootstrap modal failed, using fallback:', e);
                }
            }
            
            // Fallback: manual show
            modalEl.style.display = 'block';
            modalEl.classList.add('show');
            document.body.classList.add('modal-open');
            
            // Add backdrop if not exists
            let backdrop = document.querySelector('.modal-backdrop');
            if (!backdrop) {
                backdrop = document.createElement('div');
                backdrop.className = 'modal-backdrop fade show';
                document.body.appendChild(backdrop);
            }
            
            // Handle close on backdrop click
            backdrop.onclick = function() {
                modalEl.style.display = 'none';
                modalEl.classList.remove('show');
                document.body.classList.remove('modal-open');
                backdrop.remove();
            };
            
            return true;
        }

        // Copy SQL functions
        function copyItemsSQL() {
            const sql = "ALTER TABLE items ADD COLUMN branch_id INT NULL;\nALTER TABLE items ADD FOREIGN KEY (branch_id) REFERENCES branches(branch_id);";
            navigator.clipboard.writeText(sql).then(() => showToast('SQL copied!'));
        }
        function copyCustomersSQL() {
            const sql = "ALTER TABLE customers ADD COLUMN branch_id INT NULL;\nALTER TABLE customers ADD FOREIGN KEY (branch_id) REFERENCES branches(branch_id);";
            navigator.clipboard.writeText(sql).then(() => showToast('SQL copied!'));
        }

        // Unit conversions - defaults, will be overridden by database values
        const UNIT_CONVERSIONS = {
            'piece': 1, 'case': 12, 'inner-pack': 6, 'box': 24, 'carton': 48
        };

        // Inventory data (base stock is DEFAULT UOM, with stock_smallest derived for conversion)
        const inventory = <?php echo $inventory_json; ?>;
        
        // Store for product unit types and pricing
        const productUnitTypes = {};
        
        // Store product images loaded from item_images table
        const productImages_data = {};
        
        // Store dynamic unit conversions per product from database (quantity_smallest_pack)
        const productUnitConversions = <?php echo $unit_conversions_json; ?>;

        const branchId = <?php echo $branch_id; ?>;
        let cart = [];
        let activeUnitTypes = {};
        let toastTimeout = null;
        let currentFilter = 'all';
        let searchTerm = '';
        let currentDiscountPercent = 0;
        let currentCreditTermsDays = 0;
        let currentCreditLimit = 0;
        let currentModalProductId = null;
        let currentCustomerPriceLevel = 'Standard';

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

        // ============= SWEET ALERT FUNCTIONS =============
        function showSweetAlert(options) {
            const {
                icon = 'success',
                title = '',
                text = '',
                details = null,
                confirmText = 'OK',
                cancelText = null,
                onConfirm = null,
                onCancel = null
            } = options;
            
            const existing = document.querySelector('.swal-overlay');
            if (existing) existing.remove();
            
            const overlay = document.createElement('div');
            overlay.className = 'swal-overlay';
            
            const modal = document.createElement('div');
            modal.className = 'swal-modal';
            
            let iconHtml = '';
            if (icon === 'success') {
                iconHtml = '<div class="swal-icon success"><i class="bi bi-check-lg"></i></div>';
            } else if (icon === 'warning') {
                iconHtml = '<div class="swal-icon warning"><i class="bi bi-exclamation-lg"></i></div>';
            } else if (icon === 'info') {
                iconHtml = '<div class="swal-icon info"><i class="bi bi-info-lg"></i></div>';
            } else if (icon === 'offline') {
                iconHtml = '<div class="swal-icon offline"><i class="bi bi-wifi-off"></i></div>';
            }
            
            let detailsHtml = '';
            if (details) {
                detailsHtml = '<div class="swal-details">';
                Object.entries(details).forEach(([label, value]) => {
                    detailsHtml += `<div class="swal-detail-row"><span class="swal-detail-label">${escapeHtml(label)}:</span><span class="swal-detail-value">${escapeHtml(value)}</span></div>`;
                });
                detailsHtml += '</div>';
            }
            
            let footerHtml = '';
            if (cancelText) {
                footerHtml = `
                    <div class="swal-footer">
                        <button class="swal-btn swal-btn-secondary cancel-btn">${escapeHtml(cancelText)}</button>
                        <button class="swal-btn swal-btn-primary confirm-btn">${escapeHtml(confirmText)}</button>
                    </div>
                `;
            } else {
                footerHtml = `
                    <div class="swal-footer">
                        <button class="swal-btn swal-btn-primary confirm-btn">${escapeHtml(confirmText)}</button>
                    </div>
                `;
            }
            
            modal.innerHTML = `
                <div class="swal-header">
                    ${iconHtml}
                    <div class="swal-title">${escapeHtml(title)}</div>
                </div>
                <div class="swal-content">
                    <div class="swal-text">${escapeHtml(text)}</div>
                    ${detailsHtml}
                </div>
                ${footerHtml}
            `;
            
            overlay.appendChild(modal);
            document.body.appendChild(overlay);
            
            const confirmBtn = modal.querySelector('.confirm-btn');
            const cancelBtn = modal.querySelector('.cancel-btn');
            
            const close = () => {
                overlay.style.animation = 'fadeIn 0.2s ease reverse';
                setTimeout(() => overlay.remove(), 200);
            };
            
            confirmBtn.addEventListener('click', () => {
                close();
                if (onConfirm) onConfirm();
            });
            
            if (cancelBtn) {
                cancelBtn.addEventListener('click', () => {
                    close();
                    if (onCancel) onCancel();
                });
            }
            
            overlay.addEventListener('click', (e) => {
                if (e.target === overlay) close();
            });
        }

        function showPendingOrdersDetails(orders) {
            const count = orders.length;
            let totalItems = 0;
            let totalAmount = 0;
            
            orders.forEach(order => {
                totalItems += order.items.length;
                totalAmount += order.total_amount || 0;
            });
            
            const details = {
                'Pending Orders': count,
                'Total Items': totalItems,
                'Total Amount': formatCurrency(totalAmount)
            };
            
            showSweetAlert({
                icon: 'offline',
                title: '📦 Pending Orders',
                text: `You have ${count} order(s) saved offline. They will be automatically submitted when your internet connection returns.`,
                details: details,
                confirmText: 'Got it'
            });
        }

        function updatePendingOrdersBadge() {
            const existing = document.querySelector('.pending-orders-badge');
            if (existing) existing.remove();
            
            const saved = localStorage.getItem('pending_orders');
            if (!saved) return;
            
            try {
                const orders = JSON.parse(saved);
                if (orders.length === 0) return;
                
                const badge = document.createElement('button');
                badge.className = 'pending-orders-badge';
                badge.innerHTML = `
                    <i class="bi bi-wifi-off"></i>
                    <span>Pending Orders</span>
                    <span class="badge-count">${orders.length}</span>
                `;
                
                badge.onclick = () => showPendingOrdersDetails(orders);
                document.body.appendChild(badge);
            } catch (e) {
                console.error('Error parsing pending orders:', e);
            }
        }

        // Function to get device location
        function captureLocation() {
            return new Promise((resolve) => {
                let locationData = '';
                
                if (navigator.geolocation) {
                    const timeoutId = setTimeout(() => {
                        resolve('');
                    }, 5000);
                    
                    navigator.geolocation.getCurrentPosition(
                        function(position) {
                            clearTimeout(timeoutId);
                            locationData = position.coords.latitude + ',' + position.coords.longitude;
                            resolve(locationData);
                        },
                        function(error) {
                            clearTimeout(timeoutId);
                            console.log('Location error:', error.message);
                            resolve('');
                        },
                        {
                            enableHighAccuracy: false,
                            timeout: 5000,
                            maximumAge: 60000
                        }
                    );
                } else {
                    resolve('');
                }
            });
        }

        // Get customer's price level
        function getSelectedPriceLevel() {
            const customerSelect = document.getElementById('modalCustomerSelect');
            if (!customerSelect) return 'Standard';
            
            const selectedOption = customerSelect.options[customerSelect.selectedIndex];
            return selectedOption ? selectedOption.dataset.priceLevel || 'Standard' : 'Standard';
        }
        
        // Reload prices for all products based on price level
        function reloadProductPrices(priceLevel) {
            const promises = inventory.map(product => {
                return loadProductUnitTypes(product.id, priceLevel);
            });
            return Promise.all(promises).then(() => {
                renderProducts();
            });
        }
        
        // Load unit types for all products
        function loadAllProductUnitTypes() {
            const priceLevel = getSelectedPriceLevel();
            const promises = inventory.map(product => {
                return loadProductUnitTypes(product.id, priceLevel);
            });
            return Promise.all(promises);
        }
        
        // Load unit types with a specific price level
        function loadAllProductUnitTypesWithPriceLevel(priceLevel) {
            const promises = inventory.map(product => {
                return loadProductUnitTypes(product.id, priceLevel);
            });
            return Promise.all(promises);
        }
        
        // Load all product images
        function loadAllProductImages() {
            const promises = inventory.map(product => {
                return loadProductImages(product.id);
            });
            return Promise.all(promises);
        }
        
        // Fetch images for a specific product
        function loadProductImages(productId) {
            return new Promise((resolve) => {
                const formData = new FormData();
                formData.append('action', 'get_product_details');
                formData.append('product_id', productId);
                formData.append('price_level', getSelectedPriceLevel());
                
                fetch('orderproduct.php', { method: 'POST', body: formData })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success && data.images && data.images.length > 0) {
                            productImages_data[productId] = data.images;
                        }
                        resolve();
                    })
                    .catch(() => {
                        resolve();
                    });
            });
        }
        
        // Fetch unit types for a specific product
        function loadProductUnitTypes(productId, priceLevel = 'Standard') {
            return new Promise((resolve) => {
                const formData = new FormData();
                formData.append('action', 'get_product_unit_types');
                formData.append('product_id', productId);
                formData.append('price_level', priceLevel);
                
                fetch('orderproduct.php', { method: 'POST', body: formData })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success && data.unit_types && data.unit_types.length > 0) {
                            const uniqueUnitTypes = [];
                            const seenUnitTypes = new Set();

                            data.unit_types.forEach(ut => {
                                const unitName = (ut.unit_type_name || '').trim();
                                if (!unitName || seenUnitTypes.has(unitName)) {
                                    return;
                                }
                                seenUnitTypes.add(unitName);
                                uniqueUnitTypes.push(ut);
                            });

                            productUnitTypes[productId] = uniqueUnitTypes;
                            const smallestUnit = uniqueUnitTypes.find(ut => parseInt(ut.quantity_smallest_pack) === 1) || uniqueUnitTypes[0];
                            if (smallestUnit) {
                                activeUnitTypes[productId] = smallestUnit.unit_type_name;
                            }
                            
                            // Store conversion using quantity_smallest_pack from database
                            productUnitConversions[productId] = {};
                            uniqueUnitTypes.forEach(ut => {
                                productUnitConversions[productId][ut.unit_type_name] = parseInt(ut.quantity_smallest_pack) || 1;
                            });
                        }
                        resolve();
                    })
                    .catch(() => {
                        resolve();
                    });
            });
        }
        
        // Initialize
        async function init() {
            await loadAllProductUnitTypes();
            await loadAllProductImages();
            
            // Set active unit type for each product to its default UOM
            inventory.forEach(product => {
                if (product.default_unit_type_name) {
                    activeUnitTypes[product.id] = product.default_unit_type_name;
                } else {
                    const unitTypes = productUnitTypes[product.id];
                    if (unitTypes && unitTypes.length > 0) {
                        activeUnitTypes[product.id] = unitTypes[0].unit_type_name;
                    } else {
                        activeUnitTypes[product.id] = product.unit_type || 'piece';
                    }
                }
            });
            
            renderProducts();
            updateCartBadge();
            setupSearch();
            updatePendingOrdersBadge();
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

        // Get unit conversion using quantity_smallest_pack (pieces per unit)
        function getUnitConversion(productId, unitType) {
            if (productUnitConversions[productId] && productUnitConversions[productId][unitType]) {
                return productUnitConversions[productId][unitType];
            }
            return UNIT_CONVERSIONS[unitType] || 1;
        }

        // Get cart quantity in smallest units
        function getCartItemSmallestUnits(item) {
            return (parseFloat(item.quantity) || 0) * (parseInt(getUnitConversion(item.id, item.unit_type)) || 1);
        }

        // Get available stock in smallest units
        function getAvailableStockSmallest(productId) {
            const p = inventory.find(p => p.id === productId);
            if (!p) return 0;

            const stockSmallest = parseFloat(p.stock_smallest || 0);
            const inCartSmallest = cart
                .filter(i => i.id === productId)
                .reduce((t, i) => t + getCartItemSmallestUnits(i), 0);

            return stockSmallest - inCartSmallest;
        }

        // Backward-compatible alias
        function getAvailableStock(productId) {
            return getAvailableStockSmallest(productId);
        }

        // Convert available stock from smallest units to selected unit type
        function getConvertedStock(productId, unitType) {
            const availableSmallest = getAvailableStockSmallest(productId);
            const conversion = Math.max(1, parseInt(getUnitConversion(productId, unitType)) || 1);
            return availableSmallest / conversion;
        }

        function getCartItemPieces(item) {
            return getCartItemSmallestUnits(item);
        }

        function getProductById(id) {
            return inventory.find(p => p.id === id);
        }

        function renderFilteredProducts(filteredInventory) {
            const container = document.getElementById('productsContainer');
            
            let html = '';
            filteredInventory.forEach(p => {
                const unitTypes = productUnitTypes[p.id] || [];
                
                // Initialize active unit: use default UOM if not set
                if (!activeUnitTypes[p.id]) {
                    if (unitTypes.length > 0) {
                        // First unit type is the default UOM
                        activeUnitTypes[p.id] = unitTypes[0].unit_type_name;
                    } else {
                        // Default to product's unit_type from database
                        activeUnitTypes[p.id] = p.unit_type || 'piece';
                    }
                }
                
                const activeUnit = activeUnitTypes[p.id];
                const availableSmallest = getAvailableStockSmallest(p.id);
                const conversion = getUnitConversion(p.id, activeUnit);
                const convertedStock = availableSmallest / conversion;
                const low = convertedStock < 5 && convertedStock > 0;
                const out = convertedStock <= 0;
                
                const placeholder = 'data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%2240%22 height=%2240%22%3E%3Crect fill=%22%23e0e0e0%22 width=%2240%22 height=%2240%22/%3E%3Ctext x=%2250%25%22 y=%2250%25%22 text-anchor=%22middle%22 dy=%22.3em%22 fill=%22%23999%22 font-size=%229%22%3ENo%3C/text%3E%3C/svg%3E';
                let img = placeholder;
                const productImages = productImages_data[p.id] || [];
                if (productImages.length > 0) {
                    const primaryImage = productImages.find(img => img.is_primary) || productImages[0];
                    img = '../uploads/products/' + primaryImage.image_path;
                } else if (p.image) {
                    img = '../uploads/products/' + p.image;
                }
                
                let currPrice = p.unit_price, currUnit = 'piece';
                const currType = activeUnitTypes[p.id];
                
                if (unitTypes.length > 0) {
                    const currentUT = unitTypes.find(ut => ut.unit_type_name === currType);
                    if (currentUT) {
                        currPrice = parseFloat(currentUT.unit_price);
                        currUnit = currentUT.unit_type_name;
                    }
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
                
                // Display stock with possible negative
                const stockDisplay = formatStockDisplay(convertedStock, activeUnit);
                
                html += `<tr id="row-${p.id}" onclick="showProductInfo(${p.id})" style="cursor: pointer;">
                    <td class="product-image-cell"><img src="${img}" class="product-thumbnail" onerror="this.src='${placeholder}'"></td>
                    <td class="product-cell">
                        <div class="product-info">
                            <span class="product-name">${escapeHtml(p.name)}</span>
                            <span id="stock-${p.id}" class="${convertedStock < 0 ? 'stock-warning' : 'stock-info'}">Stock: ${stockDisplay}</span>
                            <!-- Mobile price display - editable input -->
                            <div class="mobile-price-display" onclick="event.stopPropagation();">
                                <div class="input-group input-group-sm" style="width: auto;">
                                    <span class="input-group-text" style="padding: 2px 6px; font-size: 11px;">₱</span>
                                    <input type="number" class="form-control mobile-price-input" 
                                           id="mobile-price-input-${p.id}" 
                                           data-product-id="${p.id}"
                                           value="${currPrice.toFixed(2)}" 
                                           min="0" 
                                           step="0.01" 
                                           style="width: 90px; font-size: 12px; padding: 4px 6px; text-align: right;"
                                           onclick="event.stopPropagation();">
                                </div>
                                <span class="mobile-price-unit" id="mobile-unit-${p.id}">/${escapeHtml(currUnit)}</span>
                            </div>
                        </div>
                      </div>
                    </td>
                    <td class="unit-column">
                        <div class="unit-buttons desktop-only">
                            ${unitButtonsHtml}
                        </div>
                        <div class="mobile-unit-qty-container mobile-only">
                            <select class="unit-dropdown" id="unit-dropdown-${p.id}" onchange="event.stopPropagation(); setActiveUnitFromDropdown(${p.id}, this.value)" onclick="event.stopPropagation()">
                                ${unitDropdownOptions}
                            </select>
                            <div class="quantity-controls">
                                <button class="qty-btn" onclick="event.stopPropagation(); decQty(${p.id})"><i class="bi bi-dash"></i></button>
                                <input type="number" class="qty-input" id="qty-${p.id}" min="0" value="0" placeholder="0" onchange="validateQuantity(${p.id})" onclick="event.stopPropagation(); clearZeroOnFocus(this)" onfocus="clearZeroOnFocus(this)" onblur="restoreZeroIfEmpty(this)">
                                <button class="qty-btn" onclick="event.stopPropagation(); incQty(${p.id})"><i class="bi bi-plus"></i></button>
                            </div>
                        </div>
                      </div>
                    </td>
                    <td class="qty-column">
                        <div class="quantity-controls desktop-only">
                            <input type="number" class="qty-input" id="qty-desktop-${p.id}" min="0" value="0" placeholder="0" onchange="validateQuantityDesktop(${p.id})" onclick="event.stopPropagation(); clearZeroOnFocus(this)" onfocus="clearZeroOnFocus(this)" onblur="restoreZeroIfEmpty(this)">
                        </div>
                      </div>
                    </td>
                    <td class="price-cell desktop-price-cell" id="price-display-${p.id}" onclick="event.stopPropagation()">
                        <div class="input-group input-group-sm" style="width: 140px;">
                            <span class="input-group-text">₱</span>
                            <input type="number" class="form-control table-price-edit" data-product-id="${p.id}" data-standard="${currPrice}" value="${currPrice.toFixed(2)}" min="${currPrice}" step="0.01" style="text-align: right;">
                        </div>
                        <small class="d-block text-muted" style="font-size: 0.75rem; margin-top: 2px;">/${escapeHtml(currUnit)}</small>
                      </div>
                    </td>
                  </tr>`;
            });
            
            container.innerHTML = html;
        }

        function setActiveUnitFromDropdown(pid, type) {
            setActiveUnit(pid, type);
        }

        function clearZeroOnFocus(input) {
            if (!input) return;
            if (input.value === '0') {
                input.value = '';
            }
        }

        function restoreZeroIfEmpty(input) {
            if (!input) return;
            if (input.value.trim() === '') {
                input.value = '0';
            }
        }

        function validateQuantityDesktop(pid) {
            const desktopInp = document.getElementById(`qty-desktop-${pid}`);
            if (!desktopInp) return 0;

            let rawValue = desktopInp.value.trim();
            if (rawValue === '') {
                const mobileInp = document.getElementById(`qty-${pid}`);
                if (mobileInp) mobileInp.value = '';
                return 0;
            }

            let v = parseInt(rawValue, 10);
            if (isNaN(v) || v < 0) v = 0;
            desktopInp.value = v;

            const mobileInp = document.getElementById(`qty-${pid}`);
            if (mobileInp) mobileInp.value = v;

            return v;
        }

        function renderProducts() {
            filterProducts();
        }

        function setActiveUnit(pid, type) {
            activeUnitTypes[pid] = type;
            
            const mobileInp = document.getElementById(`qty-${pid}`);
            const desktopInp = document.getElementById(`qty-desktop-${pid}`);
            if (mobileInp) mobileInp.value = 0;
            if (desktopInp) desktopInp.value = 0;
            
            const convertedStock = getConvertedStock(pid, type);
            const stockEl = document.getElementById(`stock-${pid}`);
            if (stockEl) {
                stockEl.innerHTML = `Stock: ${formatStockDisplay(convertedStock, type)}`;
                stockEl.className = convertedStock < 0 ? 'stock-warning' : 'stock-info';
            }
            
            const priceEl = document.getElementById(`price-display-${pid}`);
            if (priceEl) {
                const product = getProductById(pid);
                const unitTypes = productUnitTypes[pid] || [];
                let currPrice = product.unit_price;
                
                if (unitTypes.length > 0) {
                    const currentUT = unitTypes.find(ut => ut.unit_type_name === type);
                    if (currentUT) {
                        currPrice = parseFloat(currentUT.unit_price);
                    }
                } else {
                    if (type === 'case' && product.price_case) currPrice = product.price_case;
                    else if (type === 'inner-pack' && product.price_inner_pack) currPrice = product.price_inner_pack;
                    else if (type === 'box' && product.price_box) currPrice = product.price_box;
                    else if (type === 'carton' && product.price_carton) currPrice = product.price_carton;
                }
                
                // Update the price input field
                const priceInput = priceEl.querySelector('input.table-price-edit');
                if (priceInput) {
                    priceInput.value = currPrice.toFixed(2);
                    priceInput.setAttribute('data-standard', currPrice);
                }
                
                // Update the unit label
                const unitLabel = priceEl.querySelector('small');
                if (unitLabel) {
                    unitLabel.textContent = `/${type}`;
                }
            }
            
            // Update mobile price input
            const mobilePriceInput = document.getElementById(`mobile-price-input-${pid}`);
            const mobileUnitSpan = document.getElementById(`mobile-unit-${pid}`);
            if (mobilePriceInput && mobileUnitSpan) {
                const product = getProductById(pid);
                const unitTypes = productUnitTypes[pid] || [];
                let currPrice = product.unit_price;
                
                if (unitTypes.length > 0) {
                    const currentUT = unitTypes.find(ut => ut.unit_type_name === type);
                    if (currentUT) {
                        currPrice = parseFloat(currentUT.unit_price);
                    }
                } else {
                    if (type === 'case' && product.price_case) currPrice = product.price_case;
                    else if (type === 'inner-pack' && product.price_inner_pack) currPrice = product.price_inner_pack;
                    else if (type === 'box' && product.price_box) currPrice = product.price_box;
                    else if (type === 'carton' && product.price_carton) currPrice = product.price_carton;
                }
                
                mobilePriceInput.value = currPrice.toFixed(2);
                mobileUnitSpan.textContent = `/${type}`;
            }
            
            document.querySelectorAll(`[data-product-id="${pid}"]`).forEach(btn => {
                btn.classList.remove('active');
                if (btn.getAttribute('data-unit-type') === type) {
                    btn.classList.add('active');
                }
            });
            
            if (currentModalProductId === pid) {
                updateModalStock(pid);
            }
        }

        function validateQuantity(pid) {
            const inp = document.getElementById(`qty-${pid}`);
            if (!inp) return 0;

            let rawValue = inp.value.trim();
            if (rawValue === '') {
                const desktopInp = document.getElementById(`qty-desktop-${pid}`);
                if (desktopInp) desktopInp.value = '';
                return 0;
            }

            let v = parseInt(rawValue, 10);
            if (isNaN(v) || v < 0) v = 0;
            inp.value = v;

            const desktopInp = document.getElementById(`qty-desktop-${pid}`);
            if (desktopInp) desktopInp.value = v;

            return v;
        }

        function incQty(pid) {
            const inp = document.getElementById(`qty-${pid}`);
            if (inp) {
                inp.value = (parseInt(inp.value) || 0) + 1;
                const desktopInp = document.getElementById(`qty-desktop-${pid}`);
                if (desktopInp) desktopInp.value = inp.value;
            }
        }

        function decQty(pid) {
            const inp = document.getElementById(`qty-${pid}`);
            if (inp) {
                let v = parseInt(inp.value) || 0;
                if (v > 0) inp.value = v - 1;
                const desktopInp = document.getElementById(`qty-desktop-${pid}`);
                if (desktopInp) desktopInp.value = inp.value;
            }
        }

        function addToCart(pid) {
            const p = getProductById(pid);
            if (!p) return;
            
            const type = activeUnitTypes[pid] || 'piece';
            const qty = parseInt(document.getElementById(`qty-${pid}`)?.value) || 0;
            
            if (qty <= 0) {
                showToast('Please enter quantity');
                return;
            }
            
            // Get price from mobile input (if on mobile) or table price input
            let price;
            const mobilePriceInput = document.getElementById(`mobile-price-input-${pid}`);
            if (mobilePriceInput && window.innerWidth <= 768) {
                price = parseFloat(mobilePriceInput.value) || p.unit_price;
            } else {
                const priceInput = document.querySelector(`input.table-price-edit[data-product-id="${pid}"]`);
                price = parseFloat(priceInput?.value) || p.unit_price;
            }
            
            const existing = cart.find(i => i.id === pid && i.unit_type === type);
            if (existing) {
                existing.quantity += qty;
                existing.price = price;
            } else {
                cart.push({ id: pid, name: p.name, price, quantity: qty, sku: p.sku, unit_type: type });
            }
            
            document.getElementById(`qty-${pid}`).value = '0';
            const desktopInp = document.getElementById(`qty-desktop-${pid}`);
            if (desktopInp) desktopInp.value = '0';
            
            updateCartBadge();
            renderProducts();
            showToast('Added to cart!');
        }

        function bulkAddToCart() {
            let itemsAdded = 0;
            
            // Get all products
            const allProducts = document.querySelectorAll('#productsContainer tr');
            
            allProducts.forEach(row => {
                // Get product ID from row
                const rowId = row.id;
                if (!rowId) return;
                
                const pid = parseInt(rowId.replace('row-', ''));
                const p = getProductById(pid);
                if (!p) return;
                
                const type = activeUnitTypes[pid] || 'piece';
                
                // Get quantity from mobile input
                const mobileQtyInput = document.getElementById(`qty-${pid}`);
                const desktopQtyInput = document.getElementById(`qty-desktop-${pid}`);
                const qty = parseInt(mobileQtyInput?.value) || parseInt(desktopQtyInput?.value) || 0;
                
                if (qty > 0) {
                    // Get price from mobile input (if on mobile) or table price input
                    let price;
                    const mobilePriceInput = document.getElementById(`mobile-price-input-${pid}`);
                    if (mobilePriceInput && window.innerWidth <= 768) {
                        price = parseFloat(mobilePriceInput.value) || p.unit_price;
                    } else {
                        const priceInput = document.querySelector(`input.table-price-edit[data-product-id="${pid}"]`);
                        price = parseFloat(priceInput?.value) || p.unit_price;
                    }
                    
                    const existing = cart.find(i => i.id === pid && i.unit_type === type);
                    if (existing) {
                        existing.quantity += qty;
                        existing.price = price;
                    } else {
                        cart.push({ id: pid, name: p.name, price, quantity: qty, sku: p.sku, unit_type: type });
                    }
                    
                    // Clear quantity after adding
                    if (mobileQtyInput) mobileQtyInput.value = '0';
                    if (desktopQtyInput) desktopQtyInput.value = '0';
                    
                    itemsAdded++;
                }
            });
            
            if (itemsAdded === 0) {
                showToast('Please enter quantity for at least one item');
                return;
            }
            
            updateCartBadge();
            renderProducts();
            showToast(`Added ${itemsAdded} product(s) to cart!`);
        }

        function updateModalStock(productId) {
            const product = inventory.find(p => p.id === productId);
            if (!product) return;
            
            const unitType = activeUnitTypes[productId] || 'piece';
            const conversion = getUnitConversion(productId, unitType);
            const convertedStock = getAvailableStockSmallest(productId) / conversion;
            
            document.getElementById('modalProductStock').innerHTML = formatStockDisplay(convertedStock, unitType);
        }

        function showProductInfo(pid) {
            currentModalProductId = pid;
            document.getElementById('loadingState').style.display = 'block';
            document.getElementById('productContent').style.display = 'none';
            
            const modal = new bootstrap.Modal(document.getElementById('productInfoModal'));
            modal.show();
            
            const fd = new FormData();
            fd.append('action', 'get_product_details');
            fd.append('product_id', pid);
            fd.append('price_level', getSelectedPriceLevel());
            
            fetch(window.location.href, { 
                method: 'POST', 
                body: fd,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
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
                    
                    let mainImg = placeholder;
                    let imagesHtml = '';
                    if (data.images && data.images.length > 0) {
                        const primaryImage = data.images.find(img => img.is_primary) || data.images[0];
                        mainImg = '../uploads/products/' + primaryImage.image_path;
                        
                        imagesHtml = '<div class="item-images-carousel" style="display: flex; gap: 10px; overflow-x: auto; padding-bottom: 10px; margin-bottom: 20px;">';
                        data.images.forEach((img, idx) => {
                            const activeClass = (img.is_primary || idx === 0) ? 'active' : '';
                            imagesHtml += `<img src="../uploads/products/${img.image_path}" alt="Thumbnail" style="width: 80px; height: 80px; object-fit: cover; border-radius: 8px; cursor: pointer; border: 2px solid ${activeClass === 'active' ? '#2E7D32' : 'transparent'};" onclick="document.getElementById('modalProductImage').src = this.src; document.querySelectorAll('.carousel-thumb-${pid}').forEach(t => t.style.borderColor = 'transparent'); this.style.borderColor = '#2E7D32';" class="carousel-thumb-${pid}">`;
                        });
                        imagesHtml += '</div>';
                    }
                    
                    document.getElementById('modalProductImage').src = mainImg;
                    
                    const existingCarousel = document.querySelector('.item-images-carousel');
                    if (existingCarousel) {
                        existingCarousel.remove();
                    }
                    
                    const headerSection = document.querySelector('.product-header-section');
                    if (headerSection && imagesHtml) {
                        headerSection.insertAdjacentHTML('afterend', imagesHtml);
                    }
                    
                    document.getElementById('modalProductCode').textContent = p.item_code || '-';
                    document.getElementById('modalProductCategory').textContent = p.category || '-';
                    document.getElementById('modalProductDescription').textContent = p.description || '-';
                    document.getElementById('modalProductPrice').textContent = formatCurrency(parseFloat(p.unit_price || 0));
                    
                    updateModalStock(pid);
                    
                    let histHtml = '';
                    if (data.order_history && data.order_history.length) {
                        data.order_history.forEach(o => {
                            const d = new Date(o.order_date).toLocaleDateString();
                            const sc = o.order_status === 'pending' ? 'status-pending' : (o.order_status === 'cancelled' ? 'status-cancelled' : 'status-completed');
                            histHtml += `<tr>
                                <td>${d}</td>
                                <td>${o.so_number}</td>
                                <td>${o.customer_name}</td>
                                <td>${o.unit_type}</td>
                                <td>${o.quantity_ordered}</td>
                                <td style="display: none;">${formatCurrency(parseFloat(o.unit_price))}</td>
                                <td style="display: none;">${formatCurrency(parseFloat(o.total_price))}</td>
                                <td><span class="status-badge ${sc}">${o.order_status}</span></td>
                              </tr>`;
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
                        const pieces = i.quantity * getUnitConversion(i.id, i.unit_type);
                        html += `<div class="cart-item">
                            <div>
                                <div class="fw-bold">${escapeHtml(i.name)} (${escapeHtml(i.unit_type)})</div>
                                <div class="text-muted small">${escapeHtml(i.sku)}</div>
                                <div class="text-muted small">${formatCurrency(i.price)} × ${i.quantity}</div>
                                <div class="text-muted small">Total pieces: ${pieces}</div>
                            </div>
                            <div class="text-end">
                                <div class="fw-bold text-success">${formatCurrency(i.price * i.quantity)}</div>
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
            
            if (subtotalEl) subtotalEl.textContent = formatCurrency(subtotal);
            if (totalItemsEl) totalItemsEl.textContent = totalQty;
            if (totalPriceEl) totalPriceEl.textContent = formatCurrency(subtotal);
        }

        function clearCart() {
            if (cart.length === 0) {
                showToast('Cart is already empty');
                return;
            }
            
            showSweetAlert({
                icon: 'warning',
                title: 'Clear Cart?',
                text: 'Are you sure you want to remove all items from your cart?',
                confirmText: 'Yes, Clear',
                cancelText: 'Cancel',
                onConfirm: () => {
                    cart = [];
                    updateCartBadge();
                    
                    const reviewItems = document.getElementById('reviewItems');
                    if (reviewItems) {
                        reviewItems.innerHTML = '<p class="text-muted text-center">No items in cart</p>';
                    }
                    
                    document.getElementById('reviewSubtotal').textContent = formatCurrency(0);
                    document.getElementById('discountLine').style.display = 'none';
                    document.getElementById('creditTermsLine').style.display = 'none';
                    document.getElementById('reviewTotal').textContent = formatCurrency(0);
                    document.getElementById('reviewCustomer').textContent = '-';
                    document.getElementById('reviewEmail').textContent = '-';
                    document.getElementById('reviewPhone').textContent = '-';
                    document.getElementById('reviewAddress').textContent = '-';
                    
                    const customerSelect = document.getElementById('modalCustomerSelect');
                    if (customerSelect) customerSelect.value = '';
                    currentDiscountPercent = 0;
                    currentCreditTermsDays = 0;
                    currentCreditLimit = 0;
                    
                    // Close the review modal if open
                    const cartModal = bootstrap.Modal.getInstance(document.getElementById('cartModal'));
                    if (cartModal) cartModal.hide();
                    
                    renderProducts();
                    showToast('Cart cleared successfully');
                }
            });
        }

        function removeFromCart(id, unit) {
            cart = cart.filter(i => !(i.id === id && i.unit_type === unit));
            updateCartBadge();
            
            const reviewItems = document.getElementById('reviewItems');
            if (reviewItems && cart.length > 0) {
                let html = '<div class="table-responsive"><table class="table table-sm"><thead class="table-light"><tr><th>Product</th><th>Unit</th><th>Qty</th><th>Price</th><th>Total</th></td></thead><tbody>';
                cart.forEach(i => {
                    const pieces = getCartItemPieces(i);
                    html += `<tr><td style="text-align:left">${escapeHtml(i.name)}</td>
                            <td style="text-align:center">${escapeHtml(i.unit_type)}</td>
                            <td style="text-align:center">${i.quantity} (${pieces} pcs)</td>
                            <td style="text-align:right">${formatCurrency(i.price)}</td>
                            <td style="text-align:right">${formatCurrency(i.price * i.quantity)}</td>
                          </tr>`;
                });
                html += '</tbody></div>';
                reviewItems.innerHTML = html;
            } else if (reviewItems) {
                reviewItems.innerHTML = '<p class="text-muted text-center">No items in cart</p>';
            }
            
            const subtotal = cart.reduce((s, i) => s + i.price * i.quantity, 0);
            document.getElementById('reviewSubtotal').textContent = formatCurrency(subtotal);
            if (currentDiscountPercent > 0) {
                const discountAmount = subtotal * currentDiscountPercent / 100;
                document.getElementById('discountAmount').textContent = discountAmount.toFixed(2);
                document.getElementById('reviewTotal').textContent = formatCurrency(subtotal - discountAmount);
            } else {
                document.getElementById('reviewTotal').textContent = formatCurrency(subtotal);
            }
            
            showToast('Item removed from cart');
            renderProducts();
        }

        function showToast(msg) {
            if (toastTimeout) clearTimeout(toastTimeout);
            
            const existing = document.querySelector('.toast-notification');
            if (existing) existing.remove();
            
            const toast = document.createElement('div');
            toast.className = 'toast-notification';
            toast.innerHTML = `<i class="bi bi-check-circle-fill me-2"></i>${escapeHtml(msg)}`;
            document.body.appendChild(toast);
            
            toastTimeout = setTimeout(() => {
                toast.classList.add('fade-out');
                setTimeout(() => toast.remove(), 300);
            }, 2000);
        }

        function viewCart() {
    try {
        console.log('viewCart called');
        if (!cart.length) {
            showToast('Cart is empty');
            return;
        }
        
        const isLocked = <?php echo $is_customer_locked ? 'true' : 'false'; ?>;
        const preSelectedCustomerId = <?php echo json_encode($pre_selected_customer_id); ?>;
        
        const reviewDiv = document.getElementById('reviewItems');
        if (!reviewDiv) throw new Error('reviewItems element not found');
        
        // Build review items table with EDITABLE quantity input (no +/- buttons)
        let html = '<table class="receipt-table">';
        html += '<thead><tr>';
        html += '<th>Product</th>';
        html += '<th>Unit</th>';
        html += '<th>Qty</th>';
        html += '<th>Price</th>';
        html += '<th>Total</th>';
        html += '<th></th>';
        html += '</tr></thead><tbody>';
        
        cart.forEach((i, idx) => {
            const pieces = getCartItemPieces(i);
            const itemId = i.id;
            const unitType = i.unit_type;
            // Create unique IDs for quantity input and total display
            const qtyInputId = `review_qty_${itemId}_${unitType.replace(/\s/g, '_')}`;
            const totalSpanId = `review_total_${itemId}_${unitType.replace(/\s/g, '_')}`;
            
           html += `<tr id="review-row-${itemId}-${unitType.replace(/\s/g, '_')}">
    <td class="product-name-cell">${escapeHtml(i.name)}</td>
    <td><span>${escapeHtml(i.unit_type)}</span></td>
    <td>
        <input type="number" id="${qtyInputId}" class="review-qty-input" value="${i.quantity}" min="1" 
               onchange="updateReviewQuantityFromInput(${itemId}, '${escapeHtml(unitType)}', this.value)">
        <div class="pieces-small">(${pieces} pcs)</div>
    </td>
    <td><span>${formatCurrency(i.price)}</span></td>
    <td><span id="${totalSpanId}"><strong>${formatCurrency(i.price * i.quantity)}</strong></span></td>
    <td>
        <button class="delete-item-btn" onclick="removeFromCartAndRefresh(${i.id}, '${escapeHtml(i.unit_type)}')">
            <i class="bi bi-trash"></i>
            <span class="btn-text"></span>
        </button>
    </td>
</tr>`;
        });
        html += '</tbody></table>';
        reviewDiv.innerHTML = html;
        
        // Calculate and display totals
        updateReviewTotals();
        
        document.getElementById('discountLine').style.display = 'none';
        document.getElementById('creditTermsLine').style.display = 'none';
        currentDiscountPercent = 0;
        currentCreditTermsDays = 0;
        currentCreditLimit = 0;
        
        const select = document.getElementById('modalCustomerSelect');
        
        if (select) {
            if (!isLocked) {
                // Only setup change listener if customer is NOT locked
                const newSelect = select.cloneNode(true);
                select.parentNode.replaceChild(newSelect, select);
                
                if (preSelectedCustomerId > 0) {
                    const option = Array.from(newSelect.options).find(opt => opt.value == preSelectedCustomerId);
                    if (option) {
                        newSelect.value = preSelectedCustomerId;
                    }
                }
                
                newSelect.addEventListener('change', function() {
                    const opt = this.options[this.selectedIndex];
                    if (opt.value) {
                        document.getElementById('reviewCustomer').textContent = opt.text.split('(')[0].trim();
                        document.getElementById('reviewEmail').textContent = opt.dataset.email || '-';
                        document.getElementById('reviewPhone').textContent = opt.dataset.phone || '-';
                        document.getElementById('reviewAddress').textContent = opt.dataset.address || '-';
                        
                        const priceLevel = opt.dataset.priceLevel || 'Standard';
                        reloadProductPrices(priceLevel);
                        
                        fetchCustomerDiscount(opt.value);
                        fetchCustomerCreditTerms(opt.value);
                    } else {
                        document.getElementById('reviewCustomer').textContent = '-';
                        document.getElementById('reviewEmail').textContent = '-';
                        document.getElementById('reviewPhone').textContent = '-';
                        document.getElementById('reviewAddress').textContent = '-';
                        currentDiscountPercent = 0;
                        currentCreditTermsDays = 0;
                        currentCreditLimit = 0;
                        document.getElementById('discountLine').style.display = 'none';
                        document.getElementById('creditTermsLine').style.display = 'none';
                        updateReviewTotals();
                    }
                });
                
                if (preSelectedCustomerId > 0) {
                    newSelect.dispatchEvent(new Event('change'));
                }
            } else {
                // Handle locked customer
                const lockedCustomerName = document.getElementById('lockedCustomerName')?.value || '';
                const lockedCustomerEmail = document.getElementById('lockedCustomerEmail')?.value || '';
                const lockedCustomerPhone = document.getElementById('lockedCustomerPhone')?.value || '';
                const lockedCustomerAddress = document.getElementById('lockedCustomerAddress')?.value || '';
                const lockedPriceLevel = document.getElementById('lockedPriceLevel')?.value || 'Standard';
                
                document.getElementById('reviewCustomer').textContent = lockedCustomerName || '-';
                document.getElementById('reviewEmail').textContent = lockedCustomerEmail || '-';
                document.getElementById('reviewPhone').textContent = lockedCustomerPhone || '-';
                document.getElementById('reviewAddress').textContent = lockedCustomerAddress || '-';
                
                if (lockedPriceLevel) {
                    reloadProductPrices(lockedPriceLevel);
                }
                
                if (preSelectedCustomerId > 0) {
                    fetchCustomerDiscount(preSelectedCustomerId);
                    fetchCustomerCreditTerms(preSelectedCustomerId);
                }
            }
        }
        
        // Open the review modal directly
        safeShowModal('cartModal');
    } catch (err) {
        console.error('viewCart error:', err);
        showToast('Error opening review: ' + err.message);
    }
}

// Update review quantity from input field
function updateReviewQuantityFromInput(itemId, unitType, newValue) {
    // Find the cart item
    const cartIndex = cart.findIndex(i => i.id === itemId && i.unit_type === unitType);
    if (cartIndex === -1) return;
    
    let newQty = parseInt(newValue);
    if (isNaN(newQty) || newQty < 1) {
        newQty = 1;
    }
    
    // Update cart quantity
    cart[cartIndex].quantity = newQty;
    
    // Update the input field
    const qtyInputId = `review_qty_${itemId}_${unitType.replace(/\s/g, '_')}`;
    const qtyInput = document.getElementById(qtyInputId);
    if (qtyInput) {
        qtyInput.value = newQty;
    }
    
    // Update total for this item
    const totalSpanId = `review_total_${itemId}_${unitType.replace(/\s/g, '_')}`;
    const price = cart[cartIndex].price;
    const totalSpan = document.getElementById(totalSpanId);
    if (totalSpan) {
        totalSpan.innerHTML = `<strong>${formatCurrency(price * newQty)}</strong>`;
    }
    
    // Update the pieces display
    const pieces = getCartItemPieces(cart[cartIndex]);
    const row = document.getElementById(`review-row-${itemId}-${unitType.replace(/\s/g, '_')}`);
    if (row) {
        const piecesSpan = row.querySelector('.pieces-small');
        if (piecesSpan) {
            piecesSpan.textContent = `(${pieces} pcs)`;
        }
    }
    
    // Update overall totals
    updateReviewTotals();
    updateCartBadge();
}

// Update all totals in the review modal
function updateReviewTotals() {
    const subtotal = cart.reduce((sum, item) => sum + (item.price * item.quantity), 0);
    document.getElementById('reviewSubtotal').textContent = formatCurrency(subtotal);
    
    if (currentDiscountPercent > 0 && subtotal > 0) {
        const discountAmount = subtotal * currentDiscountPercent / 100;
        document.getElementById('discountAmount').textContent = discountAmount.toFixed(2);
        document.getElementById('discountLine').style.display = 'flex';
        document.getElementById('reviewTotal').textContent = formatCurrency(subtotal - discountAmount);
    } else {
        document.getElementById('discountLine').style.display = 'none';
        document.getElementById('reviewTotal').textContent = formatCurrency(subtotal);
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
            // Rebuild the table with editable quantity inputs
            let html = '<table class="receipt-table"><thead><tr>';
            html += '<th>Product</th><th>Unit</th><th>Qty</th><th>Price</th><th>Total</th><th></th>';
            html += '</tr></thead><tbody>';
            
            cart.forEach(i => {
                const pieces = getCartItemPieces(i);
                const qtyInputId = `review_qty_${i.id}_${i.unit_type.replace(/\s/g, '_')}`;
                const totalSpanId = `review_total_${i.id}_${i.unit_type.replace(/\s/g, '_')}`;
                
                html += `<tr id="review-row-${i.id}-${i.unit_type.replace(/\s/g, '_')}">
                    <td class="product-name-cell">${escapeHtml(i.name)}</td>
                    <td><span>${escapeHtml(i.unit_type)}</span></td>
                    <td>
                        <input type="number" id="${qtyInputId}" class="review-qty-input" value="${i.quantity}" min="1" 
                               style="width: 80px; text-align: center; padding: 6px; border: 1px solid #ddd; border-radius: 4px;"
                               onchange="updateReviewQuantityFromInput(${i.id}, '${escapeHtml(i.unit_type)}', this.value)">
                        <small class="pieces-small" style="display: block; text-align: center; margin-top: 4px;">(${pieces} pcs)</small>
                    </td>
                    <td><span>${formatCurrency(i.price)}</span></td>
                    <td><span id="${totalSpanId}"><strong>${formatCurrency(i.price * i.quantity)}</strong></span></td>
                    <td>
                        <button class="delete-item-btn" onclick="removeFromCartAndRefresh(${i.id}, '${escapeHtml(i.unit_type)}')">
                            <i class="bi bi-trash"></i>
                        </button>
                    </td>
                </tr>`;
            });
            html += '</tbody></table>';
            reviewDiv.innerHTML = html;
        }
        
        updateReviewTotals();
    }
    
    renderProducts();
    showToast('Item removed from cart');
}

        function fetchCustomerDiscount(customerId) {
            if (!customerId) {
                currentDiscountPercent = 0;
                document.getElementById('discountLine').style.display = 'none';
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'get_customer_discount');
            formData.append('customer_id', customerId);
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    currentDiscountPercent = data.discount;
                    document.getElementById('discountPercent').textContent = currentDiscountPercent;
                    
                    const subtotal = cart.reduce((s, i) => s + i.price * i.quantity, 0);
                    if (currentDiscountPercent > 0 && subtotal > 0) {
                        const discountAmount = subtotal * currentDiscountPercent / 100;
                        document.getElementById('discountAmount').textContent = discountAmount.toFixed(2);
                        document.getElementById('discountLine').style.display = 'flex';
                        document.getElementById('reviewTotal').textContent = formatCurrency(subtotal - discountAmount);
                    } else {
                        document.getElementById('discountLine').style.display = 'none';
                        document.getElementById('reviewTotal').textContent = formatCurrency(subtotal);
                    }
                }
            })
            .catch(err => console.error('Fetch discount error:', err));
        }

        function updateCartTotals() {
            const subtotal = cart.reduce((s, i) => s + i.price * i.quantity, 0);
            document.getElementById('reviewSubtotal').textContent = formatCurrency(subtotal);
            document.getElementById('reviewTotal').textContent = formatCurrency(subtotal);
        }

        function fetchCustomerCreditTerms(customerId) {
            if (!customerId) {
                currentCreditTermsDays = 0;
                currentCreditLimit = 0;
                document.getElementById('creditTermsLine').style.display = 'none';
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'get_customer_credit_terms');
            formData.append('customer_id', customerId);
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    currentCreditTermsDays = data.credit_terms_days;
                    currentCreditLimit = data.credit_limit;
                    
                    if (currentCreditTermsDays > 0 || currentCreditLimit > 0) {
                        document.getElementById('creditTermsDays').textContent = currentCreditTermsDays;
                        document.getElementById('creditLimitAmount').textContent = formatNumberWithCommas(currentCreditLimit);
                        document.getElementById('creditTermsLine').style.display = 'flex';
                    } else {
                        document.getElementById('creditTermsLine').style.display = 'none';
                    }
                }
            })
            .catch(err => console.error('Fetch credit terms error:', err));
        }

        // ========== SUBMIT ORDER FUNCTION (with locked customer support) ==========
        function submitOrder() {
            const isLocked = <?php echo $is_customer_locked ? 'true' : 'false'; ?>;
            let custId = 0;
            let opt = null;
            let customerName = '';
            let customerEmail = '';
            let customerPhone = '';
            let customerAddress = '';
            let priceLevel = 'Standard';
            
            if (isLocked) {
                // Get locked customer info from hidden inputs
                custId = parseInt(document.getElementById('lockedCustomerId')?.value) || 0;
                customerName = document.getElementById('lockedCustomerName')?.value || '';
                customerEmail = document.getElementById('lockedCustomerEmail')?.value || '';
                customerPhone = document.getElementById('lockedCustomerPhone')?.value || '';
                customerAddress = document.getElementById('lockedCustomerAddress')?.value || '';
                priceLevel = document.getElementById('lockedPriceLevel')?.value || 'Standard';
                
                if (!custId) {
                    showToast('Customer information not found');
                    return;
                }
            } else {
                const select = document.getElementById('modalCustomerSelect');
                custId = select?.value ? parseInt(select.value) : 0;
                opt = select?.options[select.selectedIndex];
                
                if (!custId) {
                    showToast('Please select a customer');
                    return;
                }
                
                customerName = opt.text.split('(')[0].trim();
                customerEmail = opt.dataset.email || '';
                customerPhone = opt.dataset.phone || '';
                customerAddress = opt.dataset.address || '';
                priceLevel = opt.dataset.priceLevel || 'Standard';
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
            
            const items = cart.map(i => ({
                id: i.id, name: i.name, price: i.price, quantity: i.quantity, sku: i.sku, unit_type: i.unit_type
            }));
            const subtotal = cart.reduce((s, i) => s + i.price * i.quantity, 0);
            const discountAmount = subtotal * (currentDiscountPercent / 100);
            const totalAmount = subtotal - discountAmount;
            
            if (!navigator.onLine) {
                saveOrderOffline(custId, { text: customerName, dataset: { email: customerEmail, phone: customerPhone, address: customerAddress } }, items, subtotal, totalAmount);
                return;
            }
            
            const btn = document.getElementById('confirmOrderBtn');
            const orig = btn.innerHTML;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Processing...';
            btn.disabled = true;
            
            captureLocation().then(locationData => {
                const postData = {
                    action: 'submit_order',
                    customer_id: custId,
                    customer_name: customerName,
                    email: customerEmail,
                    phone: customerPhone,
                    address: customerAddress,
                    items: JSON.stringify(items),
                    discount_percent: currentDiscountPercent,
                    agent_location: locationData
                };
                
                const formBody = Object.keys(postData).map(key => 
                    encodeURIComponent(key) + '=' + encodeURIComponent(postData[key])
                ).join('&');
                
                fetch(window.location.href, { 
                    method: 'POST', 
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: formBody
                })
                .then(async response => {
                    if (!response.ok) {
                        throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                    }
                    const text = await response.text();
                    if (text.trim().startsWith('<')) {
                        throw new Error('Server returned HTML instead of JSON');
                    }
                    return JSON.parse(text);
                })
                .then(data => {
                    if (data.success) {
                        if (data.updated_stock && Array.isArray(data.updated_stock)) {
                            data.updated_stock.forEach(item => {
                                const product = inventory.find(p => p.id === item.item_id);
                                if (product) {
                                    const defaultMultiplier = Math.max(1, parseInt(product.default_unit_multiplier || 1));
                                    product.default_stock = parseFloat(item.stock || 0);
                                    product.stock_in_default_uom = parseFloat(item.stock || 0);
                                    product.stock_smallest = item.stock_smallest !== undefined ? parseFloat(item.stock_smallest) : (product.default_stock * defaultMultiplier);
                                    product.raw_stock = product.default_stock;
                                }
                            });
                        }
                        cart = [];
                        updateCartBadge();
                        bootstrap.Modal.getInstance(document.getElementById('cartModal'))?.hide();
                        
                        showSweetAlert({
                        icon: 'success',
                        title: '✅ Order Submitted!',
                        text: 'Your order has been successfully processed.',
                        details: {
                            'Order Number': data.so_number,
                            'Customer': customerName,
                            'Total': formatCurrency(totalAmount)
                        },
                        confirmText: 'View Order',
                        cancelText: 'New Order',
                        onConfirm: () => {
                            // I-redirect sa sales_order.php na may view_order parameter
                            window.location.href = `sales_order.php?view_order=${data.so_id}`;
                        },
                        onCancel: () => {
                            if (select) select.value = '';
                            currentDiscountPercent = 0;
                            renderProducts();
                            // Clear cart
                            cart = [];
                            updateCartBadge();
                        }
                    });
                        currentDiscountPercent = 0;
                        renderProducts();
                    } else {
                        showSweetAlert({
                            icon: 'warning',
                            title: 'Error',
                            text: data.message || 'Failed to submit order. Please try again.',
                            confirmText: 'OK'
                        });
                    }
                })
                .catch(error => {
                    console.error('Fetch error:', error);
                    if (error.message.includes('Failed to fetch') || error.message.includes('NetworkError')) {
                        saveOrderOffline(custId, { text: customerName, dataset: { email: customerEmail, phone: customerPhone, address: customerAddress } }, items, subtotal, totalAmount);
                    } else {
                        showSweetAlert({
                            icon: 'error',
                            title: 'Server Error',
                            text: 'Unable to process order due to a server issue. Please try again later.',
                            confirmText: 'OK'
                        });
                    }
                })
                .finally(() => {
                    btn.innerHTML = orig;
                    btn.disabled = false;
                });
            });
        }

        function saveOrderOffline(custId, opt, items, subtotal, totalAmount) {
            const orderData = {
                id: 'OFFLINE-' + Date.now(),
                date: new Date().toLocaleString(),
                customer_id: custId,
                customer_name: opt.text.split('(')[0].trim(),
                email: opt.dataset.email || '',
                phone: opt.dataset.phone || '',
                address: opt.dataset.address || '',
                items: items,
                discount_percent: currentDiscountPercent || 0,
                subtotal: subtotal,
                total_amount: totalAmount
            };
            
            let pendingOrders = [];
            const saved = localStorage.getItem('pending_orders');
            if (saved) pendingOrders = JSON.parse(saved);
            pendingOrders.push(orderData);
            localStorage.setItem('pending_orders', JSON.stringify(pendingOrders));
            
            cart = [];
            updateCartBadge();
            bootstrap.Modal.getInstance(document.getElementById('cartModal'))?.hide();
            
            showSweetAlert({
                icon: 'offline',
                title: '📶 Offline Mode',
                text: 'Your order has been saved locally and will be submitted automatically when you\'re back online.',
                details: {
                    'Order ID': orderData.id,
                    'Customer': orderData.customer_name,
                    'Total': formatCurrency(totalAmount)
                },
                confirmText: 'Continue',
                onConfirm: () => {
                    renderProducts();
                    updatePendingOrdersBadge();
                }
            });
            renderProducts();
            updatePendingOrdersBadge();
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

        function initMobileNav() {
            const mobileNav = document.getElementById('mobileNav');
            if (!mobileNav) return;
            
            const isMobile = window.innerWidth <= 992;
            
            if (isMobile) {
                mobileNav.style.display = 'block';
                
                const currentPage = window.location.pathname.split('/').pop();
                const navLinks = mobileNav.querySelectorAll('.nav-link:not(.logout-btn)');
                
                navLinks.forEach(link => {
                    const href = link.getAttribute('href');
                    if (currentPage === href) {
                        link.classList.add('active');
                    } else {
                        link.classList.remove('active');
                    }
                });
            } else {
                mobileNav.style.display = 'none';
            }
        }

        // Auto-sync when back online
        window.addEventListener('online', function() {
            const saved = localStorage.getItem('pending_orders');
            if (!saved) return;
            
            try {
                const pendingOrders = JSON.parse(saved);
                if (pendingOrders.length === 0) return;
                
                showSweetAlert({
                    icon: 'info',
                    title: '🔄 Syncing Orders',
                    text: `Submitting ${pendingOrders.length} pending order(s)...`,
                    confirmText: 'OK'
                });
                
                let successCount = 0;
                let failCount = 0;
                
                const submitPromises = pendingOrders.map((order, index) => {
                    return new Promise((resolve) => {
                        setTimeout(() => {
                            const formData = new URLSearchParams();
                            formData.append('action', 'submit_order');
                            formData.append('customer_id', order.customer_id);
                            formData.append('customer_name', order.customer_name);
                            formData.append('email', order.email);
                            formData.append('phone', order.phone);
                            formData.append('address', order.address);
                            formData.append('items', JSON.stringify(order.items));
                            formData.append('discount_percent', order.discount_percent);
                            formData.append('agent_location', '');
                            
                            fetch(window.location.href, {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                                body: formData.toString()
                            })
                            .then(r => r.json())
                            .then(d => {
                                if (d.success) {
                                    successCount++;
                                } else {
                                    failCount++;
                                }
                                resolve();
                            })
                            .catch(() => {
                                failCount++;
                                resolve();
                            });
                        }, index * 2000);
                    });
                });
                
                Promise.all(submitPromises).then(() => {
                    if (failCount === 0) {
                        localStorage.removeItem('pending_orders');
                        updatePendingOrdersBadge();
                        
                        showSweetAlert({
                            icon: 'success',
                            title: '✅ Sync Complete',
                            text: `Successfully synced ${successCount} order(s)!`,
                            confirmText: 'Great!',
                            onConfirm: () => {
                                renderProducts();
                            }
                        });
                    } else {
                        showSweetAlert({
                            icon: 'warning',
                            title: '⚠️ Partial Sync',
                            text: `${successCount} order(s) synced, ${failCount} failed. Failed orders remain saved offline.`,
                            confirmText: 'OK'
                        });
                    }
                });
                
            } catch (e) {
                console.error('Sync error:', e);
            }
        });

        // Profile Modal Functions
        function showProfileModal() {
            const profileModal = new bootstrap.Modal(document.getElementById('profileModal'));
            profileModal.show();
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

        // Service Worker Registration
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('/AMGC/service-worker.js', { scope: '/AMGC/' })
                .then(reg => console.log('✅ SW registered!'))
                .catch(err => console.log('❌ SW failed:', err));
        }

        // Start - DOMContentLoaded
        document.addEventListener('DOMContentLoaded', function() {
            initializeSidebar();
            initMobileNav();
            
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
            
            window.addEventListener('resize', function() {
                handleSidebarResize();
                initMobileNav();
            });
            
            const preSelectedCustomerId = <?php echo json_encode($pre_selected_customer_id); ?>;
            const preSelectedCustomerName = "<?php echo addslashes($pre_selected_customer_name); ?>";
            
            if (preSelectedCustomerId > 0 && preSelectedCustomerName) {
                const customerSelect = document.getElementById('modalCustomerSelect');
                if (customerSelect) {
                    const option = Array.from(customerSelect.options).find(opt => opt.value == preSelectedCustomerId);
                    if (option) {
                        customerSelect.value = preSelectedCustomerId;
                        const priceLevel = option.dataset.priceLevel || 'Standard';
                        loadAllProductUnitTypesWithPriceLevel(priceLevel).then(() => {
                            loadAllProductImages().then(() => {
                                init();
                                updateCartBadge();
                                setupSearch();
                                updatePendingOrdersBadge();
                                customerSelect.dispatchEvent(new Event('change'));
                            });
                        });
                    } else {
                        init();
                    }
                } else {
                    init();
                }
            } else {
                init();
            }
        });
        
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
    </script>
</body>
</html>