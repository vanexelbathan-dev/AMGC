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

<<<<<<< HEAD

// ===== DISCOUNT/TOTAL COLUMN SAFETY =====
// These columns are required so total_amount saves the discounted total,
// while discount details remain available for Branch Admin approval/view/export.
if (!function_exists('amgcColumnExists')) {
    function amgcColumnExists($conn, $table, $column) {
        $table = $conn->real_escape_string($table);
        $column = $conn->real_escape_string($column);
        $res = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
        return ($res && $res->num_rows > 0);
    }
}

if (!function_exists('amgcAddColumnIfMissing')) {
    function amgcAddColumnIfMissing($conn, $table, $column, $definition) {
        if (!amgcColumnExists($conn, $table, $column)) {
            @$conn->query("ALTER TABLE `$table` ADD COLUMN $definition");
        }
    }
}

amgcAddColumnIfMissing($conn, 'sales_orders', 'discount_percent', 'discount_percent DECIMAL(8,4) NOT NULL DEFAULT 0.0000 AFTER total_amount');
amgcAddColumnIfMissing($conn, 'sales_orders', 'discount_amount', 'discount_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER discount_percent');
amgcAddColumnIfMissing($conn, 'sales_orders', 'discount_calculation_type', "discount_calculation_type ENUM('percentage','amount_based') NOT NULL DEFAULT 'percentage' AFTER discount_amount");
amgcAddColumnIfMissing($conn, 'sales_orders', 'discount_based_amount', 'discount_based_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER discount_calculation_type');
amgcAddColumnIfMissing($conn, 'sales_orders', 'order_amount', 'order_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER discount_based_amount');
amgcAddColumnIfMissing($conn, 'sales_orders', 'total_discount_amount', 'total_discount_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER order_amount');

amgcAddColumnIfMissing($conn, 'sales_order_items', 'gross_price', 'gross_price DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER quantity_delivered');
amgcAddColumnIfMissing($conn, 'sales_order_items', 'discount_type', "discount_type ENUM('computed','percentage','peso') NOT NULL DEFAULT 'computed' AFTER gross_price");
amgcAddColumnIfMissing($conn, 'sales_order_items', 'discount_value', 'discount_value DECIMAL(12,4) NOT NULL DEFAULT 0.0000 AFTER discount_type');
amgcAddColumnIfMissing($conn, 'sales_order_items', 'discount_amount', 'discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER discount_value');
amgcAddColumnIfMissing($conn, 'sales_order_items', 'net_price', 'net_price DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER discount_amount');
amgcAddColumnIfMissing($conn, 'sales_order_items', 'order_amount', 'order_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER net_price');
amgcAddColumnIfMissing($conn, 'sales_order_items', 'total_discount', 'total_discount DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER order_amount');
amgcAddColumnIfMissing($conn, 'unit_types', 'uom_initial', 'uom_initial VARCHAR(20) DEFAULT NULL AFTER unit_type_name');

=======
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
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
<<<<<<< HEAD
<<<<<<< HEAD
=======
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
                    i.stock, i.stock_in_default_uom, i.unit_type, i.unit_price, i.price_case, i.price_inner_pack, 
                    i.price_box, i.price_carton, i.reorder_level, i.status,
                    i.product_image_url,
                    b.branch_name
                    FROM items i
                    LEFT JOIN branches b ON i.branch_id = b.branch_id
                    WHERE i.status = 'active'
                    ORDER BY i.category ASC, i.item_name ASC";
<<<<<<< HEAD
=======
                       i.stock, i.unit_type, i.unit_price, i.price_case, i.price_inner_pack, 
                       i.price_box, i.price_carton, i.reorder_level, i.status,
                       i.product_image_url,
                       b.branch_name
                       FROM items i
                       LEFT JOIN branches b ON i.branch_id = b.branch_id
                       WHERE i.status = 'active'
                       ORDER BY i.category ASC, i.item_name ASC";
>>>>>>> 1bcf3b66714c50eace882b1c946948f48fb2be54
    } else {
        $items_query = "SELECT i.item_id, i.item_code, i.item_name, i.description, i.category, 
<<<<<<< HEAD
=======
    } else {
        $items_query = "SELECT i.item_id, i.item_code, i.item_name, i.description, i.category, 
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
                    i.stock, i.stock_in_default_uom, i.unit_type, i.unit_price, i.price_case, i.price_inner_pack, 
                    i.price_box, i.price_carton, i.reorder_level, i.status,
                    i.product_image_url,
                    b.branch_name
                    FROM items i
                    LEFT JOIN branches b ON i.branch_id = b.branch_id
                    WHERE i.status = 'active' AND i.branch_id = $branch_id
                    ORDER BY i.category ASC, i.item_name ASC";
<<<<<<< HEAD
=======
                       i.stock, i.unit_type, i.unit_price, i.price_case, i.price_inner_pack, 
                       i.price_box, i.price_carton, i.reorder_level, i.status,
                       i.product_image_url,
                       b.branch_name
                       FROM items i
                       LEFT JOIN branches b ON i.branch_id = b.branch_id
                       WHERE i.status = 'active' AND i.branch_id = $branch_id
                       ORDER BY i.category ASC, i.item_name ASC";
>>>>>>> 1bcf3b66714c50eace882b1c946948f48fb2be54
    }
} else {
    $items_query = "SELECT i.item_id, i.item_code, i.item_name, i.description, i.category, 
<<<<<<< HEAD
=======
    }
} else {
    $items_query = "SELECT i.item_id, i.item_code, i.item_name, i.description, i.category, 
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
                i.stock, i.stock_in_default_uom, i.unit_type, i.unit_price, i.price_case, i.price_inner_pack, 
                i.price_box, i.price_carton, i.reorder_level, i.status,
                i.product_image_url
                FROM items i
                WHERE i.status = 'active'
                ORDER BY i.category ASC, i.item_name ASC";
<<<<<<< HEAD
=======
                   i.stock, i.unit_type, i.unit_price, i.price_case, i.price_inner_pack, 
                   i.price_box, i.price_carton, i.reorder_level, i.status,
                   i.product_image_url
                   FROM items i
                   WHERE i.status = 'active'
                   ORDER BY i.category ASC, i.item_name ASC";
>>>>>>> 1bcf3b66714c50eace882b1c946948f48fb2be54
=======
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
}

$items_result = $conn->query($items_query);
if ($items_result) {
    $items = $items_result->fetch_all(MYSQLI_ASSOC);
} else {
    error_log("Items query error: " . $conn->error);
}

<<<<<<< HEAD
<<<<<<< HEAD
=======
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
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

<<<<<<< HEAD
// Get all unique categories
$categories = array_unique(array_column($items, 'category'));
$categories = array_filter($categories);
sort($categories);

// Get all customers
=======
=======
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
// Get all unique categories
$categories = array_unique(array_column($items, 'category'));
$categories = array_filter($categories);
sort($categories);

<<<<<<< HEAD
// Get all customers - filter by branch if not admin AND if branch_id column exists
>>>>>>> 1bcf3b66714c50eace882b1c946948f48fb2be54
=======
// Get all customers
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
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

<<<<<<< HEAD
<<<<<<< HEAD
// Resolve selected customer from customer_id.
// This makes the customer label work even if customer.php only sends customer_id in the URL.
$selected_customer = null;
if ($pre_selected_customer_id > 0 && !empty($customers)) {
    foreach ($customers as $customer) {
        if ((int)$customer['customer_id'] === (int)$pre_selected_customer_id) {
            $selected_customer = $customer;
            break;
        }
    }

    if ($selected_customer) {
        $pre_selected_customer_name = $selected_customer['customer_name'] ?? $pre_selected_customer_name;
    }
}

=======
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
// Build inventory array with per-item default UOM for JavaScript
$inventory_data = [];
foreach ($items as $item) {
    $default_info = getItemDefaultUOMInfo($conn, $item['item_id'], $branch_id, $items_branch_column_exists);
<<<<<<< HEAD
    $default_multiplier = max(1, (int)($default_info['multiplier'] ?? 1));
    $default_unit_name = $default_info['unit_type_name'] ?? $item['unit_type'];

    $unit_stocks = [];
    $default_stock = 0.0;
    $stock_smallest = 0.0;

    $stock_stmt = $conn->prepare("
        SELECT ut.unit_type_name, ut.quantity_smallest_pack, iui.current_inventory
        FROM item_unit_inventory iui
        JOIN unit_types ut ON iui.unit_type_id = ut.unit_type_id
        WHERE iui.item_id = ?
        ORDER BY ut.is_default_uom DESC, ut.unit_type_name ASC
    ");
    if ($stock_stmt) {
        $item_id_for_stock = (int)$item['item_id'];
        $stock_stmt->bind_param('i', $item_id_for_stock);
        $stock_stmt->execute();
        $stock_res = $stock_stmt->get_result();
        while ($stock_row = $stock_res->fetch_assoc()) {
            $ut_name = trim((string)($stock_row['unit_type_name'] ?? ''));
            if ($ut_name === '') continue;
            $current_inventory = (float)($stock_row['current_inventory'] ?? 0);
            $qty_smallest_pack = max(1, (int)($stock_row['quantity_smallest_pack'] ?? 1));
            $unit_stocks[$ut_name] = $current_inventory;
            if (strcasecmp($ut_name, $default_unit_name) === 0) {
                $default_stock = $current_inventory;
                $stock_smallest = $current_inventory * $qty_smallest_pack;
            }
        }
        $stock_stmt->close();
    }

    if ($default_stock == 0.0 && !empty($unit_stocks)) {
        foreach ($unit_stocks as $ut_name => $ut_stock) {
            $default_stock = (float)$ut_stock;
            $conv = max(1, (int)($all_items_unit_types[$item['item_id']][$ut_name] ?? 1));
            $stock_smallest = $default_stock * $conv;
            break;
        }
    }
=======
    
    $default_multiplier = max(1, (int)($default_info['multiplier'] ?? 1));
    $stock_in_default_uom = ($stock_in_default_uom_column_exists && isset($item['stock_in_default_uom'])) ? (float)$item['stock_in_default_uom'] : 0;
    $raw_stock = isset($item['stock']) ? (float)$item['stock'] : 0;
    $default_stock = $stock_in_default_uom > 0 ? $stock_in_default_uom : $raw_stock;
    $stock_smallest = $default_stock * $default_multiplier;
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928

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
<<<<<<< HEAD
        'stock_in_default_uom' => (float)$default_stock,
        'raw_stock' => (float)$default_stock,
        'unit_stocks' => $unit_stocks,
        'unit_type' => $item['unit_type'],
        'default_unit_type_name' => $default_unit_name,
=======
        'stock_in_default_uom' => (float)$stock_in_default_uom,
        'raw_stock' => (float)$raw_stock,
        'unit_type' => $item['unit_type'],
        'default_unit_type_name' => $default_info['unit_type_name'],
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
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
<<<<<<< HEAD
            SELECT unit_type_id, unit_type_name, uom_initial, unit_price, quantity_smallest_pack
=======
            SELECT unit_type_id, unit_type_name, unit_price, quantity_smallest_pack
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
            FROM (
                SELECT 
                    ut.unit_type_id,
                    ut.unit_type_name,
<<<<<<< HEAD
                    ut.uom_initial,
=======
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
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

<<<<<<< HEAD
=======
>>>>>>> 1bcf3b66714c50eace882b1c946948f48fb2be54
=======
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
// Handle AJAX request for product details
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_product_details') {
    header('Content-Type: application/json');
    
    try {
        $product_id = isset($_POST['product_id']) ? (int)$_POST['product_id'] : 0;
<<<<<<< HEAD
<<<<<<< HEAD
        $price_level = isset($_POST['price_level']) ? trim($_POST['price_level']) : 'Standard';
=======
>>>>>>> 1bcf3b66714c50eace882b1c946948f48fb2be54
=======
        $price_level = isset($_POST['price_level']) ? trim($_POST['price_level']) : 'Standard';
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
        
        if ($product_id <= 0) {
            throw new Exception("Invalid product ID");
        }
        
<<<<<<< HEAD
<<<<<<< HEAD
        if ($items_branch_column_exists && !$view_all_branches) {
            $product_query = "SELECT i.item_id, i.item_code, i.item_name, i.description, i.category, 
                            i.stock, i.stock_in_default_uom, i.unit_type, i.unit_price, i.price_case, i.price_inner_pack, 
                            i.price_box, i.price_carton, i.reorder_level, i.status,
                            i.product_image_url,
                            b.branch_name
                            FROM items i
                            LEFT JOIN branches b ON i.branch_id = b.branch_id
                            WHERE i.item_id = ? AND i.branch_id = ?";
=======
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
>>>>>>> 1bcf3b66714c50eace882b1c946948f48fb2be54
=======
        if ($items_branch_column_exists && !$view_all_branches) {
            $product_query = "SELECT i.item_id, i.item_code, i.item_name, i.description, i.category, 
                            i.stock, i.stock_in_default_uom, i.unit_type, i.unit_price, i.price_case, i.price_inner_pack, 
                            i.price_box, i.price_carton, i.reorder_level, i.status,
                            i.product_image_url,
                            b.branch_name
                            FROM items i
                            LEFT JOIN branches b ON i.branch_id = b.branch_id
                            WHERE i.item_id = ? AND i.branch_id = ?";
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
            $stmt = $conn->prepare($product_query);
            $stmt->bind_param('ii', $product_id, $branch_id);
        } else {
            $product_query = "SELECT i.item_id, i.item_code, i.item_name, i.description, i.category, 
<<<<<<< HEAD
<<<<<<< HEAD
=======
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
                            i.stock, i.stock_in_default_uom, i.unit_type, i.unit_price, i.price_case, i.price_inner_pack, 
                            i.price_box, i.price_carton, i.reorder_level, i.status,
                            i.product_image_url
                            FROM items i
                            WHERE i.item_id = ?";
<<<<<<< HEAD
=======
                             i.stock, i.unit_type, i.unit_price, i.price_case, i.price_inner_pack, 
                             i.price_box, i.price_carton, i.reorder_level, i.status,
                             i.product_image_url
                             FROM items i
                             WHERE i.item_id = ?";
>>>>>>> 1bcf3b66714c50eace882b1c946948f48fb2be54
=======
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
            $stmt = $conn->prepare($product_query);
            $stmt->bind_param('i', $product_id);
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        $product = $result->fetch_assoc();
        
        if (!$product) {
            throw new Exception("Product not found");
        }
        
<<<<<<< HEAD
<<<<<<< HEAD
        $unit_types_query = "
            SELECT unit_type_id, unit_type_name, uom_initial, unit_price, quantity_smallest_pack
=======
        $unit_types_query = "
            SELECT unit_type_id, unit_type_name, unit_price, quantity_smallest_pack
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
            FROM (
                SELECT 
                    ut.unit_type_id,
                    ut.unit_type_name,
<<<<<<< HEAD
                    ut.uom_initial,
=======
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
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
        
<<<<<<< HEAD
        $history_query = "SELECT so.so_number, so.order_date, c.customer_name, so.order_status,
                        soi.quantity_ordered, soi.unit_type, soi.unit_price,
                        (soi.quantity_ordered * soi.unit_price) as total_price
                        FROM sales_order_items soi
                        JOIN sales_orders so ON soi.so_id = so.so_id
                        JOIN customers c ON so.customer_id = c.customer_id
                        WHERE soi.item_id = ?";
=======
        // Get order history for this product
        $history_query = "SELECT so.so_number, so.order_date, c.customer_name, so.order_status,
                         soi.quantity_ordered, soi.unit_type, soi.unit_price,
                         (soi.quantity_ordered * soi.unit_price) as total_price
                         FROM sales_order_items soi
                         JOIN sales_orders so ON soi.so_id = so.so_id
                         JOIN customers c ON so.customer_id = c.customer_id
                         WHERE soi.item_id = ?";
>>>>>>> 1bcf3b66714c50eace882b1c946948f48fb2be54
=======
        $history_query = "SELECT so.so_number, so.order_date, c.customer_name, so.order_status,
                        soi.quantity_ordered, soi.unit_type, soi.unit_price,
                        (soi.quantity_ordered * soi.unit_price) as total_price
                        FROM sales_order_items soi
                        JOIN sales_orders so ON soi.so_id = so.so_id
                        JOIN customers c ON so.customer_id = c.customer_id
                        WHERE soi.item_id = ?";
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
        
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
<<<<<<< HEAD
<<<<<<< HEAD
            'unit_types' => $unit_types,
            'images' => $images,
=======
>>>>>>> 1bcf3b66714c50eace882b1c946948f48fb2be54
=======
            'unit_types' => $unit_types,
            'images' => $images,
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
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

<<<<<<< HEAD
<<<<<<< HEAD
// Handle order submission via AJAX (with stock deduction using conversion)
=======
// Handle order submission via AJAX
>>>>>>> 1bcf3b66714c50eace882b1c946948f48fb2be54
=======
// Handle order submission via AJAX (with stock deduction using conversion)
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
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
        
<<<<<<< HEAD
        error_log("========== ORDER SUBMISSION ==========");
        error_log("Raw items data: " . json_encode($items_data));
=======
        error_log("Customer ID: $customer_id, Customer Name: $customer_name, Discount: $discount_percent%");
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
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
        
<<<<<<< HEAD
        $discount_percent = isset($_POST['discount_percent']) ? (float)$_POST['discount_percent'] : 0;
        $discount_calculation_type = isset($_POST['discount_calculation_type']) ? trim($_POST['discount_calculation_type']) : 'percentage';
        $discount_based_amount = isset($_POST['discount_based_amount']) ? (float)$_POST['discount_based_amount'] : 0;
        
        if (!in_array($discount_calculation_type, ['percentage', 'amount_based'], true)) {
            $discount_calculation_type = 'percentage';
        }
=======
        $discount_amount = $subtotal * ($discount_percent / 100);
        $total_amount = $subtotal - $discount_amount;
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
        
        if ($discount_calculation_type === 'amount_based') {
            // Fixed peso discount from approved credit/discount request.
            $discount_amount = max(0, min($subtotal, $discount_based_amount));
            // Keep a computed percent for legacy sales_orders.discount_percent column, if it exists.
            $discount_percent = $subtotal > 0 ? (($discount_amount / $subtotal) * 100) : 0;
        } else {
            $discount_percent = max(0, min(100, $discount_percent));
            $discount_amount = $subtotal * ($discount_percent / 100);
            $discount_based_amount = 0;
        }
        
        $total_amount = max(0, $subtotal - $discount_amount);
        
        $so_number_mode = isset($_POST['so_number_mode']) ? trim((string)$_POST['so_number_mode']) : 'auto';
        $manual_so_last = isset($_POST['manual_so_last']) ? trim((string)$_POST['manual_so_last']) : '';
        $so_prefix = 'SO-' . date('Ymd') . '-';

        if ($so_number_mode === 'manual') {
            if (!preg_match('/^\d{5,6}$/', $manual_so_last)) {
                throw new Exception('Manual SO number must be 5 to 6 digits only.');
            }
            $so_number = $so_prefix . $manual_so_last;
        } else {
            $so_number_mode = 'auto';
            do {
                $so_number = $so_prefix . str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                $check_so_stmt = $conn->prepare("SELECT so_id FROM sales_orders WHERE so_number = ? LIMIT 1");
                if (!$check_so_stmt) {
                    throw new Exception('Failed to validate SO number: ' . $conn->error);
                }
                $check_so_stmt->bind_param('s', $so_number);
                $check_so_stmt->execute();
                $check_so_result = $check_so_stmt->get_result();
                $so_exists = $check_so_result && $check_so_result->num_rows > 0;
                $check_so_stmt->close();
            } while ($so_exists);
        }

        $check_manual_so_stmt = $conn->prepare("SELECT so_id FROM sales_orders WHERE so_number = ? LIMIT 1");
        if (!$check_manual_so_stmt) {
            throw new Exception('Failed to validate SO number: ' . $conn->error);
        }
        $check_manual_so_stmt->bind_param('s', $so_number);
        $check_manual_so_stmt->execute();
        $check_manual_so_result = $check_manual_so_stmt->get_result();
        if ($check_manual_so_result && $check_manual_so_result->num_rows > 0) {
            $check_manual_so_stmt->close();
            throw new Exception('SO number already exists. Please enter another number.');
        }
        $check_manual_so_stmt->close();

        $order_date = date('Y-m-d H:i:s');
        
        // Check sales_orders table columns
        $columns_check = $conn->query("SHOW COLUMNS FROM sales_orders");
        $columns = [];
        while ($col = $columns_check->fetch_assoc()) {
            $columns[] = $col['Field'];
        }
        $has_discount_column = in_array('discount_percent', $columns);
<<<<<<< HEAD
        $has_discount_amount_column = in_array('discount_amount', $columns);
        $has_discount_calculation_type_column = in_array('discount_calculation_type', $columns);
        $has_discount_based_amount_column = in_array('discount_based_amount', $columns);
        $has_order_amount_column = in_array('order_amount', $columns);
        $has_total_discount_amount_column = in_array('total_discount_amount', $columns);
=======
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
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
        
<<<<<<< HEAD
        // Save additional discount details when these optional columns exist in sales_orders.
        if ($has_discount_amount_column) {
            $insert_fields[] = 'discount_amount';
            $insert_placeholders[] = '?';
            $insert_types .= 'd';
            $insert_values[] = $discount_amount;
        }
        if ($has_discount_calculation_type_column) {
            $insert_fields[] = 'discount_calculation_type';
            $insert_placeholders[] = '?';
            $insert_types .= 's';
            $insert_values[] = $discount_calculation_type;
        }
        if ($has_discount_based_amount_column) {
            $insert_fields[] = 'discount_based_amount';
            $insert_placeholders[] = '?';
            $insert_types .= 'd';
            $insert_values[] = $discount_based_amount;
        }
        if ($has_order_amount_column) {
            // order_amount stores the discounted/net order amount for reports.
            $insert_fields[] = 'order_amount';
            $insert_placeholders[] = '?';
            $insert_types .= 'd';
            $insert_values[] = $total_amount;
        }
        if ($has_total_discount_amount_column) {
            $insert_fields[] = 'total_discount_amount';
            $insert_placeholders[] = '?';
            $insert_types .= 'd';
            $insert_values[] = $discount_amount;
        }
        
=======
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
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
        
<<<<<<< HEAD
        // Insert order items only. Stock is deducted later by Branch Admin upon confirmation.
        // IMPORTANT: Save line-level gross/net/discount so Branch Admin reports do not recompute the wrong amount.
        $soi_columns_check = $conn->query("SHOW COLUMNS FROM sales_order_items");
        $soi_columns = [];
        if ($soi_columns_check) {
            while ($col = $soi_columns_check->fetch_assoc()) {
                $soi_columns[] = $col['Field'];
            }
        }
        $has_soi_gross_price = in_array('gross_price', $soi_columns, true);
        $has_soi_discount_type = in_array('discount_type', $soi_columns, true);
        $has_soi_discount_value = in_array('discount_value', $soi_columns, true);
        $has_soi_discount_amount = in_array('discount_amount', $soi_columns, true);
        $has_soi_net_price = in_array('net_price', $soi_columns, true);
        $has_soi_order_amount = in_array('order_amount', $soi_columns, true);
        $has_soi_total_discount = in_array('total_discount', $soi_columns, true);

=======
        // Insert order items and deduct inventory using proper conversion
        $sql_items = "INSERT INTO sales_order_items (so_id, item_id, unit_type, quantity_ordered, unit_price)
                    VALUES (?, ?, ?, ?, ?)";
        $stmt_items = $conn->prepare($sql_items);
        
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
        $updated_stock_data = [];
        
        foreach ($items_data as $item) {
            $item_id = (int)$item['id'];
            $quantity = (float)$item['quantity'];
            $unit_price = (float)$item['price'];
<<<<<<< HEAD
            $unit_type = isset($item['unit_type']) ? trim((string)$item['unit_type']) : 'Piece';
            
            // FIXED: If unit_type is empty after trim, use 'Piece' as default
            if (empty($unit_type)) {
                $unit_type = 'Piece';
            }
            
            // FIXED: Get the CORRECT case-matching unit_type from database
            // The database might have "Piece" but JavaScript might send "piece" or vice versa
            // ALSO check if item has this unit type at all
            $correct_unit_type = $unit_type;
            $correct_unit_type_id = 0;
            $has_unit_type = false;
            
            $find_unit_stmt = $conn->prepare("
                SELECT ut.unit_type_id, ut.unit_type_name
                FROM item_unit_pricing iup
                JOIN unit_types ut ON iup.unit_type_id = ut.unit_type_id
                WHERE iup.item_id = ? AND LOWER(ut.unit_type_name) = LOWER(?)
                LIMIT 1
            ");
            if ($find_unit_stmt) {
                $find_unit_stmt->bind_param('is', $item_id, $unit_type);
                $find_unit_stmt->execute();
                $find_result = $find_unit_stmt->get_result();
                if ($find_row = $find_result->fetch_assoc()) {
                    $correct_unit_type = $find_row['unit_type_name'];
                    $correct_unit_type_id = (int)($find_row['unit_type_id'] ?? 0);
                    $has_unit_type = true;
                }
                $find_unit_stmt->close();
            }
            
            if (!$has_unit_type) {
                // Unit type not found for this item - list available ones
                $avail_units = [];
                $avail_stmt = $conn->prepare("
                    SELECT DISTINCT ut.unit_type_name
                    FROM item_unit_pricing iup
                    JOIN unit_types ut ON iup.unit_type_id = ut.unit_type_id
                    WHERE iup.item_id = ?
                ");
                if ($avail_stmt) {
                    $avail_stmt->bind_param('i', $item_id);
                    $avail_stmt->execute();
                    $avail_result = $avail_stmt->get_result();
                    while ($avail_row = $avail_result->fetch_assoc()) {
                        $avail_units[] = $avail_row['unit_type_name'];
                    }
                    $avail_stmt->close();
                }
                throw new Exception("Unit type '$unit_type' not configured for item. Available: " . implode(', ', $avail_units));
            }
            
            $unit_type = $correct_unit_type;

            error_log("========== PROCESSING ITEM ==========");
            error_log("Item ID: $item_id, Order Unit Type: '$unit_type', Order Quantity: $quantity, Raw unit_type from POST: '" . ($item['unit_type'] ?? 'NULL') . "'");

            if ($item_id <= 0 || $quantity <= 0 || $unit_type === '') {
                throw new Exception('Invalid order item data');
            }

            $line_gross_price = $unit_price;
            $line_gross_total = $line_gross_price * $quantity;
            if ($discount_calculation_type === 'percentage') {
                $line_discount_type = $discount_percent > 0 ? 'percentage' : 'computed';
                $line_discount_value = $discount_percent;
                $line_discount_per_unit = $line_gross_price * ($discount_percent / 100);
            } else {
                // Fixed amount-based discount is distributed proportionally across all items.
                $line_discount_type = 'computed';
                $line_discount_total = $subtotal > 0 ? ($discount_amount * ($line_gross_total / $subtotal)) : 0;
                $line_discount_total = max(0, min($line_gross_total, $line_discount_total));
                $line_discount_per_unit = $quantity > 0 ? ($line_discount_total / $quantity) : 0;
                $line_discount_value = $line_discount_per_unit;
            }
            $line_discount_per_unit = max(0, min($line_gross_price, $line_discount_per_unit));
            $line_net_price = max(0, $line_gross_price - $line_discount_per_unit);
            $line_order_amount = $line_net_price * $quantity;
            $line_total_discount = $line_discount_per_unit * $quantity;

            $item_fields = ['so_id', 'item_id', 'unit_type', 'quantity_ordered', 'unit_price'];
            $item_placeholders = ['?', '?', '?', '?', '?'];
            $item_types = 'iisid';
            $item_values = [$so_id, $item_id, $unit_type, $quantity, $line_net_price];

            if ($has_soi_gross_price) { $item_fields[] = 'gross_price'; $item_placeholders[] = '?'; $item_types .= 'd'; $item_values[] = $line_gross_price; }
            if ($has_soi_discount_type) { $item_fields[] = 'discount_type'; $item_placeholders[] = '?'; $item_types .= 's'; $item_values[] = $line_discount_type; }
            if ($has_soi_discount_value) { $item_fields[] = 'discount_value'; $item_placeholders[] = '?'; $item_types .= 'd'; $item_values[] = $line_discount_value; }
            if ($has_soi_discount_amount) { $item_fields[] = 'discount_amount'; $item_placeholders[] = '?'; $item_types .= 'd'; $item_values[] = $line_discount_per_unit; }
            if ($has_soi_net_price) { $item_fields[] = 'net_price'; $item_placeholders[] = '?'; $item_types .= 'd'; $item_values[] = $line_net_price; }
            if ($has_soi_order_amount) { $item_fields[] = 'order_amount'; $item_placeholders[] = '?'; $item_types .= 'd'; $item_values[] = $line_order_amount; }
            if ($has_soi_total_discount) { $item_fields[] = 'total_discount'; $item_placeholders[] = '?'; $item_types .= 'd'; $item_values[] = $line_total_discount; }

            $sql_items = "INSERT INTO sales_order_items (" . implode(', ', $item_fields) . ") VALUES (" . implode(', ', $item_placeholders) . ")";
            $stmt_items = $conn->prepare($sql_items);
            if (!$stmt_items) {
                throw new Exception('Failed to prepare order item insert: ' . $conn->error);
            }
            $stmt_items->bind_param($item_types, ...$item_values);
            $stmt_items->execute();
            $stmt_items->close();

            // IMPORTANT: Do NOT deduct inventory here.
            // Sales order submission must only save the order and line items as pending.
            // Actual stock deduction happens once in BranchAdmin/sales_order.php when the branch admin confirms the order.
            $stock_stmt = $conn->prepare("
                SELECT COALESCE(iui.current_inventory, 0) AS current_inventory
                FROM item_unit_inventory iui
                JOIN unit_types ut ON iui.unit_type_id = ut.unit_type_id
                WHERE iui.item_id = ? AND LOWER(TRIM(ut.unit_type_name)) = LOWER(TRIM(?))
                LIMIT 1
            ");
            $current_unit_stock = null;
            if ($stock_stmt) {
                $stock_stmt->bind_param('is', $item_id, $unit_type);
                $stock_stmt->execute();
                $stock_result = $stock_stmt->get_result();
                $stock_row = $stock_result ? $stock_result->fetch_assoc() : null;
                $stock_stmt->close();
                if ($stock_row) {
                    $current_unit_stock = (float)($stock_row['current_inventory'] ?? 0);
                }
            }

            $updated_stock_data[] = [
                'item_id' => $item_id,
                'unit_type' => $unit_type,
                'new_stock' => $current_unit_stock
            ];

            error_log("Order item saved without stock deduction: Item $item_id | $unit_type | qty $quantity");
=======
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
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
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
<<<<<<< HEAD
            'discount_calculation_type' => $discount_calculation_type,
            'discount_based_amount' => $discount_based_amount,
            'discount_amount' => $discount_amount,
=======
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
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

<<<<<<< HEAD
// Handle cancel order
=======
// Handle cancel order (restore stock)
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
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
        
<<<<<<< HEAD
        // Since stock is no longer deducted when sales submits a pending order,
        // cancelling from the Sales side must not add stock back.
        // Stock restoration should only be handled in Branch Admin if a confirmed order is cancelled after deduction.
=======
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
        
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
        $update_sql = "UPDATE sales_orders SET order_status = 'cancelled' WHERE so_id = ?";
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param('i', $order_id);
        $update_stmt->execute();
        
        $conn->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Order cancelled successfully'
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
        
<<<<<<< HEAD
        // Support both discount types from credit_discount_request.php:
        // percentage discount and fixed amount-based discount.
        $cdr_columns_result = $conn->query("SHOW COLUMNS FROM credit_discount_requests");
        $cdr_columns = [];
        if ($cdr_columns_result) {
            while ($col = $cdr_columns_result->fetch_assoc()) {
                $cdr_columns[] = $col['Field'];
            }
        }
        
        $select_discount_type = in_array('discount_calculation_type', $cdr_columns, true) ? "discount_calculation_type" : "'percentage' AS discount_calculation_type";
        $select_discount_based_amount = in_array('discount_based_amount', $cdr_columns, true) ? "discount_based_amount" : "0 AS discount_based_amount";
        $select_calculated_discount_amount = in_array('calculated_discount_amount', $cdr_columns, true) ? "calculated_discount_amount" : "0 AS calculated_discount_amount";
        
        $query = "SELECT requested_discount_percent,
                         $select_discount_type,
                         $select_discount_based_amount,
                         $select_calculated_discount_amount
=======
        $query = "SELECT requested_discount_percent 
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
                  FROM credit_discount_requests 
                  WHERE customer_id = ? 
                    AND status = 'approved' 
                    AND (request_type = 'discount' OR request_type = 'both')
<<<<<<< HEAD
                    AND (effective_until IS NULL OR effective_until > NOW())
                  ORDER BY approved_at DESC, request_id DESC
=======
                  ORDER BY approved_at DESC 
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
                  LIMIT 1";
        $stmt = $conn->prepare($query);
        $stmt->bind_param('i', $customer_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
<<<<<<< HEAD
        $discount = 0;
        $discount_type = 'percentage';
        $discount_based_amount = 0;
        $calculated_discount_amount = 0;
        
        if ($row = $result->fetch_assoc()) {
            $discount = (float)($row['requested_discount_percent'] ?? 0);
            $discount_type = $row['discount_calculation_type'] ?? 'percentage';
            $discount_based_amount = (float)($row['discount_based_amount'] ?? 0);
            $calculated_discount_amount = (float)($row['calculated_discount_amount'] ?? 0);
            
            if (!in_array($discount_type, ['percentage', 'amount_based'], true)) {
                $discount_type = 'percentage';
            }
=======
        if ($row = $result->fetch_assoc()) {
            $discount = (float)$row['requested_discount_percent'];
        } else {
            $discount = 0;
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
        }
        
        echo json_encode([
            'success' => true,
<<<<<<< HEAD
            'discount' => $discount,
            'discount_type' => $discount_type,
            'discount_based_amount' => $discount_based_amount,
            'calculated_discount_amount' => $calculated_discount_amount
=======
            'discount' => $discount
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
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
<<<<<<< HEAD
=======

>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
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
<<<<<<< HEAD
// ============= HANDLE GET ORDER DETAILS (for modal) =============
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_order_details') {
    header('Content-Type: application/json');
    
    try {
        $order_id = (int)$_POST['order_id'];
        
        // Get order details with saved discount totals.
        // Column-safe expressions keep the modal working on older databases.
        $so_discount_percent_expr = amgcColumnExists($conn, 'sales_orders', 'discount_percent') ? "COALESCE(so.discount_percent, 0)" : "0";
        $so_discount_amount_expr = amgcColumnExists($conn, 'sales_orders', 'discount_amount') ? "COALESCE(so.discount_amount, 0)" : "0";
        $so_total_discount_expr = amgcColumnExists($conn, 'sales_orders', 'total_discount_amount') ? "COALESCE(so.total_discount_amount, 0)" : "0";
        $so_discount_type_expr = amgcColumnExists($conn, 'sales_orders', 'discount_calculation_type') ? "COALESCE(so.discount_calculation_type, 'percentage')" : "'percentage'";
        $so_discount_based_expr = amgcColumnExists($conn, 'sales_orders', 'discount_based_amount') ? "COALESCE(so.discount_based_amount, 0)" : "0";
        $soi_subtotal_price_expr = amgcColumnExists($conn, 'sales_order_items', 'gross_price')
            ? "COALESCE(NULLIF(soi_sub.gross_price, 0), NULLIF(soi_sub.unit_price, 0), 0)"
            : "COALESCE(NULLIF(soi_sub.unit_price, 0), 0)";
        
        $sql = "SELECT 
                    so.so_id,
                    so.so_number,
                    so.order_date,
                    so.total_amount,
                    ($so_discount_percent_expr) AS discount_percent,
                    ($so_discount_amount_expr) AS discount_amount,
                    ($so_total_discount_expr) AS total_discount_amount,
                    ($so_discount_type_expr) AS discount_calculation_type,
                    ($so_discount_based_expr) AS discount_based_amount,
                    (
                        SELECT COALESCE(SUM(soi_sub.quantity_ordered * $soi_subtotal_price_expr), 0)
                        FROM sales_order_items soi_sub
                        WHERE soi_sub.so_id = so.so_id
                    ) AS order_subtotal,
                    so.order_status,
                    so.branch_id,
                    c.customer_name,
                    c.store_name,
                    c.customer_code,
                    c.email,
                    c.phone_number,
                    c.address,
                    u.first_name as created_by,
                    b.branch_name,
                    COALESCE(d.driver_name, 'No Driver') as assigned_driver
                FROM sales_orders so
                LEFT JOIN customers c ON so.customer_id = c.customer_id
                LEFT JOIN users u ON so.created_by = u.user_id
                LEFT JOIN branches b ON so.branch_id = b.branch_id
                LEFT JOIN pick_lists pl ON so.so_id = pl.so_id
                LEFT JOIN drivers d ON pl.driver_id = d.driver_id
                WHERE so.so_id = ?";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $order_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            echo json_encode(['success' => false, 'message' => 'Order not found']);
            exit;
        }
        
        $order = $result->fetch_assoc();
        
        // Get order items with saved gross/net/discount values.
        $soi_gross_price_expr = amgcColumnExists($conn, 'sales_order_items', 'gross_price') ? "COALESCE(soi.gross_price, 0)" : "0";
        $soi_net_price_expr = amgcColumnExists($conn, 'sales_order_items', 'net_price') ? "COALESCE(soi.net_price, soi.unit_price, 0)" : "COALESCE(soi.unit_price, 0)";
        $soi_order_amount_expr = amgcColumnExists($conn, 'sales_order_items', 'order_amount') ? "COALESCE(soi.order_amount, 0)" : "0";
        $soi_total_discount_expr = amgcColumnExists($conn, 'sales_order_items', 'total_discount') ? "COALESCE(soi.total_discount, 0)" : "0";
        $soi_discount_amount_expr = amgcColumnExists($conn, 'sales_order_items', 'discount_amount') ? "COALESCE(soi.discount_amount, 0)" : "0";
        
        $items_sql = "SELECT 
                        soi.so_item_id,
                        soi.so_id,
                        soi.item_id,
                        soi.quantity_ordered,
                        soi.quantity_delivered,
                        soi.unit_price,
                        soi.line_total,
                        ($soi_gross_price_expr) AS gross_price,
                        ($soi_net_price_expr) AS net_price,
                        ($soi_order_amount_expr) AS order_amount,
                        ($soi_total_discount_expr) AS total_discount,
                        ($soi_discount_amount_expr) AS discount_amount,
                        i.item_name,
                        i.item_code,
                        soi.unit_type
                     FROM sales_order_items soi
                     JOIN items i ON soi.item_id = i.item_id
                     WHERE soi.so_id = ?
                     ORDER BY soi.so_item_id";
        $items_stmt = $conn->prepare($items_sql);
        $items_stmt->bind_param('i', $order_id);
        $items_stmt->execute();
        $items_result = $items_stmt->get_result();
        $items = $items_result->fetch_all(MYSQLI_ASSOC);
        
        echo json_encode([
            'success' => true,
            'order' => $order,
            'items' => $items
        ]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ============= HANDLE PRINT ORDER =============
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'print_order') {
    header('Content-Type: application/json');
    
    try {
        $so_id = (int)$_POST['so_id'];
        
        $sql = "SELECT 
                    soi.so_item_id,
                    soi.so_id,
                    soi.item_id,
                    soi.quantity_ordered,
                    soi.quantity_delivered,
                    soi.unit_price,
                    soi.unit_type,
                    so.so_number,
                    so.order_date,
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
                    i.item_name,
                    COALESCE(d.driver_name, 'No Driver') as assigned_driver,
                    d.vehicle_plate_number,
                    d.vehicle_type
                FROM sales_order_items soi
                JOIN sales_orders so ON soi.so_id = so.so_id
                JOIN customers c ON so.customer_id = c.customer_id
                LEFT JOIN branches b ON so.branch_id = b.branch_id
                LEFT JOIN users u ON so.created_by = u.user_id
                LEFT JOIN pick_lists pl ON so.so_id = pl.so_id
                LEFT JOIN drivers d ON pl.driver_id = d.driver_id
                JOIN items i ON soi.item_id = i.item_id
                WHERE soi.so_id = ?
                ORDER BY soi.so_item_id";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $so_id);
        $stmt->execute();
        $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        $driver_query = "SELECT d.driver_name, d.vehicle_plate_number, d.vehicle_type FROM pick_lists pl JOIN drivers d ON pl.driver_id = d.driver_id WHERE pl.so_id = ? LIMIT 1";
        $driver_stmt = $conn->prepare($driver_query);
        $driver_stmt->bind_param("i", $so_id);
        $driver_stmt->execute();
        $driver = $driver_stmt->get_result()->fetch_assoc();
        
        $order_summary = !empty($items) ? $items[0] : null;
        
        echo json_encode([
            'success' => true,
            'order' => $order_summary,
            'items' => $items,
            'driver' => $driver
        ]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}
=======
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
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
<<<<<<< HEAD
        <!-- Session Checker -->
    <script src="../js/session-checker.js"></script>
=======
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
    <style>
<<<<<<< HEAD
        html,
body {
    height: 100dvh; /* mobile-safe viewport */
    overflow: hidden !important;
    margin: 0;
    padding: 0;
}

body {
    display: flex;
    flex-direction: column;
}

/* main wrapper/content */
.main-content,
.content-wrapper,
.container-fluid {
    overflow: hidden;
    height: calc(100dvh - 70px); /* adjust depende sa header/navbar */
}

/* table lang ang scroll */
.product-table-container {
    overflow-y: auto !important;
    overflow-x: auto;
    height: calc(100dvh - 220px); /* allowance sa header/search/tabs */
    padding-bottom: 120px; /* para di matakpan ng mobile bottom bar */
    -webkit-overflow-scrolling: touch;
}

/* sticky table header */
.product-table thead {
    position: sticky;
    top: 0;
    z-index: 100;
    background: #047857;
}
        * {
            box-sizing: border-box;
            -webkit-tap-highlight-color: transparent;
=======
        * {
            box-sizing: border-box;
            -webkit-tap-highlight-color: transparent;
        }

<<<<<<< HEAD
        :root {
            --primary-green: #2E7D32;
            --dark-green: #1B5E20;
            --light-gray: #F5F5F5;
            --white: #FFFFFF;
            --black: #212121;
            --border-gray: #e0e0e0;
>>>>>>> 1bcf3b66714c50eace882b1c946948f48fb2be54
        }

        /* Cart Item Styling */
        .cart-item {
<<<<<<< HEAD
            background: #F5F5F5;
=======
            background: var(--light-gray);
>>>>>>> 1bcf3b66714c50eace882b1c946948f48fb2be54
=======
        /* Cart Item Styling */
        .cart-item {
            background: #F5F5F5;
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
<<<<<<< HEAD
<<<<<<< HEAD
            border-left: 4px solid #047857;
=======
            border-left: 4px solid var(--primary-green);
>>>>>>> 1bcf3b66714c50eace882b1c946948f48fb2be54
=======
            border-left: 4px solid #047857;
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
        }
        
        /* Cart Icon Button in Header */
        .navbar-top .btn-success {
<<<<<<< HEAD
<<<<<<< HEAD
            background: #047857;
=======
            background: var(--primary-green);
>>>>>>> 1bcf3b66714c50eace882b1c946948f48fb2be54
=======
            background: #047857;
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
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
<<<<<<< HEAD
<<<<<<< HEAD
            background: #1B5E20;
        }
        
        /* Fix cart badge text centering */
.navbar-top .btn-success .badge {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    line-height: 1 !important;
    padding: 0 !important;
    min-width: 18px;
    height: 18px;
    background: #1B5E20 !important;
}

.navbar-top .btn-success .badge span {
    display: inline-block;
    line-height: 1;
    margin-top: -1px;
}
                
        /* Alert for missing branch column */
        .alert-info {
            background-color: #F5F5F5;
            border-color: #e0e0e0;
            color: #212121;
=======
            background: var(--dark-green);
=======
            background: #1B5E20;
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
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
<<<<<<< HEAD
            background-color: var(--light-gray);
            border-color: var(--border-gray);
            color: var(--black);
>>>>>>> 1bcf3b66714c50eace882b1c946948f48fb2be54
=======
            background-color: #F5F5F5;
            border-color: #e0e0e0;
            color: #212121;
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
            border-radius: 8px;
            margin: 10px;
            font-size: 13px;
        }

        /* ===== CATEGORY TABS DESIGN ===== */
        .category-tabs-container {
<<<<<<< HEAD
<<<<<<< HEAD
            background: #FFFFFF;
=======
            background: var(--white);
>>>>>>> 1bcf3b66714c50eace882b1c946948f48fb2be54
=======
            background: #FFFFFF;
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            margin-bottom: 20px;
            padding: 15px 15px 0 15px;
        }
        
        .tabs-header {
<<<<<<< HEAD
    display: flex;
    align-items: center;
    justify-content: space-between;
    border-bottom: 2px solid #e0e0e0;
    padding-bottom: 10px;
    flex-wrap: wrap;
    gap: 10px;
}

/* Customer display styling */
.customer-display {
    background: #f0fdf4;
    padding: 6px 14px;
    border-radius: 20px;
    border: 1px solid #bbf7d0;
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
    white-space: nowrap;
}

.customer-display .customer-label {
    color: #047857;
    font-weight: 600;
}

.customer-display .customer-name {
    color: #166534;
    font-weight: 500;
    max-width: 200px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

@media (max-width: 768px) {
    .tabs-header {
        flex-direction: column !important;
        align-items: flex-start !important;
        gap: 10px;
    }
    
    /* Customer display - PINAKA ITAAS */
    .customer-display {
        display: flex;
        align-items: center;
        justify-content: flex-start;
        gap: 6px;
        background: #f0fdf4;
        padding: 6px 12px;
        border-radius: 20px;
        font-size: 11px;
        width: fit-content;
        max-width: 100%;
        margin: 0;
        order: 1; /* Ensure it's first */
    }
    
    .customer-display .customer-label {
        font-size: 11px;
    }
    
    .customer-display .customer-label i {
        font-size: 11px;
    }
    
    .customer-display .customer-name {
        font-size: 11px;
        font-weight: 500;
        max-width: 150px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    
    /* Tabs scroll - NASA IBABA NG CUSTOMER NAME */
    .tabs-scroll {
        flex: 1;
        width: 100%;
        overflow-x: auto;
        white-space: nowrap;
        -webkit-overflow-scrolling: touch;
        order: 2; /* Ensure it's second */
    }
    
    .category-tabs {
        display: inline-flex;
        gap: 5px;
        white-space: nowrap;
    }
    
    .tab-btn {
        padding: 5px 12px;
        font-size: 11px;
    }
}
=======
            display: flex;
            align-items: center;
            gap: 15px;
            border-bottom: 2px solid #e0e0e0;
            padding-bottom: 10px;
        }
>>>>>>> 1bcf3b66714c50eace882b1c946948f48fb2be54
        
        .tabs-scroll {
            flex: 1;
            overflow-x: auto;
            overflow-y: hidden;
            white-space: nowrap;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: thin;
            padding-bottom: 5px;
<<<<<<< HEAD
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
    margin-bottom: 20px;

    /* scroll sa products table lang */
    max-height: 65vh; /* adjust mo kung gusto mas mataas */
    overflow-y: auto;
    overflow-x: auto;

    -webkit-overflow-scrolling: touch;
    scrollbar-width: thin;
}

/* scrollbar */
.product-table-container::-webkit-scrollbar {
    width: 8px;
    height: 8px;
}

.product-table-container::-webkit-scrollbar-thumb {
    background: #047857;
    border-radius: 10px;
}

.product-table-container::-webkit-scrollbar-track {
    background: #f1f1f1;
}

/* sticky header */
.product-table thead {
    position: sticky;
    top: 0;
    z-index: 100;
    background: #047857;
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
            width: 50px !important;
            height: 30px !important;
            text-align: center;
            border: 1px solid #e0e0e0;
            border-radius: 4px;
            font-size: 15px !important;
            padding: 0 4px;
=======
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
<<<<<<< HEAD
            font-size: 11px;
            padding: 0;
            margin: 0 2px;
>>>>>>> 1bcf3b66714c50eace882b1c946948f48fb2be54
=======
            font-size: 13px !important;
            padding: 0 4px;
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
            -moz-appearance: textfield;
        }
        
        .qty-input:focus {
            outline: none;
<<<<<<< HEAD
<<<<<<< HEAD
            border-color: #047857;
=======
            border-color: var(--primary-green);
>>>>>>> 1bcf3b66714c50eace882b1c946948f48fb2be54
=======
            border-color: #047857;
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
        }
        
        /* Desktop/Web at Mobile visibility classes */
        .desktop-only {
            display: flex;
        }
        
        .mobile-only {
            display: none;
        }
        
<<<<<<< HEAD
<<<<<<< HEAD
=======
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
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
<<<<<<< HEAD
=======
        /* ===== MOBILE VIEW - UNIT AT QTY SA IISANG COLUMN ===== */
=======
            
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
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
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
<<<<<<< HEAD
        }
        
        /* Desktop view adjustments */
        @media (min-width: 769px) {
            .product-table th:nth-child(1) { width: 6%; }
            .product-table th:nth-child(2) { width: 22%; }
            .product-table th:nth-child(3) { width: 20%; }
            .product-table th:nth-child(4) { width: 12%; }
            .product-table th:nth-child(5) { width: 15%; }
            .product-table th:nth-child(6) { width: 12%; }
>>>>>>> 1bcf3b66714c50eace882b1c946948f48fb2be54
=======
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
            
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
            
<<<<<<< HEAD
            .stock-info, .stock-warning {
                font-size: 10px;
            }
            
=======
>>>>>>> 1bcf3b66714c50eace882b1c946948f48fb2be54
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
<<<<<<< HEAD
<<<<<<< HEAD
                width: 28px;
                height: 28px;
=======
                width: 24px;
                height: 24px;
>>>>>>> 1bcf3b66714c50eace882b1c946948f48fb2be54
                font-size: 12px;
            }
            
            .qty-input {
<<<<<<< HEAD
                width: 55px;
                height: 28px;
=======
                width: 35px;
                height: 24px;
>>>>>>> 1bcf3b66714c50eace882b1c946948f48fb2be54
                font-size: 11px;
            }
            
            .add-cart-btn, .view-btn {
<<<<<<< HEAD
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
                padding: 7px 12px 7px 32px;
                font-size: 13px;
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
                font-size: 11px;
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
                font-size: 15px;
                font-weight: 600;
                margin-bottom: 2px;
                white-space: normal;
                word-break: break-word;
                line-height: 1.2;
            }
            
            /* Stock info */
            .stock-info, .stock-warning {
                font-size: 10px;
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
                font-size: 15px !important;
                padding: 2px 3px !important;
                height: 24px !important;
            }
            
            .mobile-price-unit {
                font-size: 12px;
                color: #999;
            }
            
            /* Product image */
            .product-thumbnail {
                width: 80px;
                height: 80px;
            }
            
            /* Unit buttons - compact */
            .unit-buttons {
                display: flex;
                flex-wrap: wrap;
                gap: 2px;
                justify-content: center;
            }
            
            .unit-btn {
                padding: 3px 4px;
                font-size: 13px;
                min-width: 35px;
                min-height: 30px;
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
            
=======
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
            
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
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
<<<<<<< HEAD
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
                font-size: 13px;
                padding: 7px 12px;
                white-space: nowrap;
            }
        }
        
=======
=======
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
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
        
<<<<<<< HEAD
        /* Toast notification */
>>>>>>> 1bcf3b66714c50eace882b1c946948f48fb2be54
=======
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
        .toast-notification {
            position: fixed;
            top: 20px;
            right: 20px;
            left: 20px;
<<<<<<< HEAD
<<<<<<< HEAD
            background: #047857;
            color: #FFFFFF;
=======
            background: var(--primary-green);
            color: var(--white);
>>>>>>> 1bcf3b66714c50eace882b1c946948f48fb2be54
=======
            background: #047857;
            color: #FFFFFF;
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
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
        
<<<<<<< HEAD
<<<<<<< HEAD
=======
        /* Navbar Top */
>>>>>>> 1bcf3b66714c50eace882b1c946948f48fb2be54
=======
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
        .navbar-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            padding: 12px 15px;
            margin-bottom: 15px;
<<<<<<< HEAD
<<<<<<< HEAD
            background: #FFFFFF;
=======
            background: var(--white);
>>>>>>> 1bcf3b66714c50eace882b1c946948f48fb2be54
=======
            background: #FFFFFF;
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
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
<<<<<<< HEAD
<<<<<<< HEAD
            color: #212121;
=======
            color: var(--black);
>>>>>>> 1bcf3b66714c50eace882b1c946948f48fb2be54
=======
            color: #212121;
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
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
<<<<<<< HEAD
<<<<<<< HEAD
            color: #2E7D32;
=======
            color: var(--primary-green);
>>>>>>> 1bcf3b66714c50eace882b1c946948f48fb2be54
=======
            color: #2E7D32;
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
            padding: 8px;
            border-radius: 8px;
            width: 40px;
            height: 40px;
            align-items: center;
            justify-content: center;
            cursor: pointer;
<<<<<<< HEAD
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
            white-space: nowrap;
        }

        /* Mobile view para sa stock-tag - hindi mag-break ang text */
        @media (max-width: 768px) {
            .stock-tag {
                padding: 4px 10px;
                font-size: 10px;
                white-space: nowrap;
                display: inline-block;
            }
        }

        /* Extra small devices */
        @media (max-width: 480px) {
            .stock-tag {
                padding: 3px 8px;
                font-size: 9px;
                white-space: nowrap;
                display: inline-block;
            }
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
=======
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
>>>>>>> 1bcf3b66714c50eace882b1c946948f48fb2be54
            height: auto;
            cursor: pointer;
        }
        
<<<<<<< HEAD
<<<<<<< HEAD
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
      /* ===== ORDER DETAILS MODAL STYLES ===== */
.order-details-card {
    background: white;
    border-radius: 12px;
    overflow: hidden;
}

.order-header-section {
    background: linear-gradient(135deg, #f8f9fa, #e9ecef);
    padding: 16px 20px;
    border-bottom: 2px solid #059669;
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 8px;
}

.order-badge {
    background: #059669;
    color: white;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 500;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    width: fit-content;
}

.order-number {
    font-size: 1.25rem;
    font-weight: 700;
    color: #1f2937;
    font-family: monospace;
    margin: 0;
}

.order-info-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 12px;
    padding: 16px 20px;
    background: white;
    border-bottom: 1px solid #e5e7eb;
}

.order-info-item {
    display: flex;
    flex-direction: column;
}

.order-info-label {
    font-size: 0.7rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #6c757d;
    margin-bottom: 4px;
    display: flex;
    align-items: center;
    gap: 4px;
}

.order-info-value {
    font-size: 0.9rem;
    font-weight: 500;
    color: #2c3e50;
}

.order-info-value .badge {
    font-size: 0.75rem;
    padding: 4px 10px;
}

.customer-section {
    padding: 16px 20px;
    background: #f8f9fa;
    border-bottom: 1px solid #e5e7eb;
}

.customer-section h6, .items-section h6 {
    font-size: 0.85rem;
    font-weight: 600;
    color: #1f2937;
    margin-bottom: 12px;
    display: flex;
    align-items: center;
    gap: 6px;
}

.customer-info-card {
    background: white;
    border-radius: 8px;
    padding: 12px;
}

.customer-detail-row {
    display: flex;
    flex-wrap: wrap;
    margin-bottom: 8px;
    font-size: 0.8rem;
}

.customer-detail-label {
    width: 120px;
    font-weight: 600;
    color: #6c757d;
}

.customer-detail-value {
    flex: 1;
    color: #2c3e50;
}

.items-section {
    padding: 16px 20px;
}

.items-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.8rem;
}

.items-table th,
.items-table td {
    padding: 10px 8px;
    border-bottom: 1px solid #e5e7eb;
}

.items-table th {
    background: #f8f9fa;
    font-weight: 600;
    color: #1f2937;
    text-align: left;
}

.items-table td:nth-child(2),
.items-table td:nth-child(3),
.items-table td:nth-child(4),
.items-table td:nth-child(5) {
    text-align: center;
}

.items-table td:nth-child(4),
.items-table td:nth-child(5) {
    text-align: right;
}

.total-row td {
    border-top: 2px solid #e5e7eb;
    font-weight: 700;
    background: #e5e7eb;
}

.driver-badge-modal {
    background: #e3f2fd;
    color: #0d6efd;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 500;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}

@media (max-width: 576px) {
    .order-info-grid {
        grid-template-columns: 1fr;
        gap: 10px;
        padding: 12px 16px;
    }
    
    .customer-detail-row {
        flex-direction: column;
        margin-bottom: 10px;
    }
    
    .customer-detail-label {
        width: 100%;
        margin-bottom: 2px;
    }
    
    .order-header-section {
        flex-direction: column;
        text-align: left;
        gap: 8px;
    }
    
    .items-table th,
    .items-table td {
        padding: 6px 4px;
        font-size: 0.7rem;
    }
    
    .customer-section,
    .items-section {
        padding: 12px 16px;
    }
}


/* ===== PRODUCT LOADING DESIGN - MOBILE FRIENDLY ===== */
.product-table.loading-products thead {
    display: none;
}
.product-loading-row td {
    padding: 0 !important;
    border: none !important;
    background: transparent !important;
}
.product-loading-panel {
    width: 100%;
    min-height: 255px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 0.65rem;
    background: linear-gradient(135deg, #ffffff, #f8fff9);
    border: 1px solid #d1fae5;
    border-radius: 16px;
    box-shadow: 0 4px 15px rgba(4, 120, 87, 0.08), inset 0 0 0 1px rgba(68, 211, 78, 0.08);
    padding: 1.4rem 1rem;
}
.product-loading-logo {
    width: 58px;
    height: 58px;
    border-radius: 50%;
    background: linear-gradient(135deg, #047857, #44D34E);
    display: flex;
    align-items: center;
    justify-content: center;
    color: #ffffff;
    font-size: 1.55rem;
    box-shadow: 0 8px 22px rgba(4, 120, 87, 0.24);
    position: relative;
}
.product-loading-logo::after {
    content: '';
    position: absolute;
    inset: -6px;
    border-radius: 50%;
    border: 3px solid rgba(68, 211, 78, 0.22);
    border-top-color: #047857;
    animation: productSpin 0.9s linear infinite;
}
.product-loading-title {
    margin: 0;
    font-weight: 800;
    color: #052A47;
    font-size: 1rem;
    text-align: center;
}
.product-loading-subtitle {
    margin: 0;
    max-width: 520px;
    color: #64748b;
    font-size: 0.84rem;
    text-align: center;
    line-height: 1.45;
}
.product-skeleton-list {
    width: min(620px, 100%);
    margin-top: 0.45rem;
    display: grid;
    gap: 0.55rem;
}
.product-skeleton-item {
    display: grid;
    grid-template-columns: 46px 1fr 74px;
    gap: 0.7rem;
    align-items: center;
    background: #ffffff;
    border: 1px solid #edf2f7;
    border-radius: 13px;
    padding: 0.65rem;
}
.skeleton-block {
    background: linear-gradient(90deg, #eef2f7 25%, #f8fafc 50%, #eef2f7 75%);
    background-size: 200% 100%;
    animation: productSkeleton 1.2s ease-in-out infinite;
    border-radius: 10px;
    min-height: 14px;
}
.skeleton-img { width: 46px; height: 46px; border-radius: 12px; }
.skeleton-line-lg { height: 15px; width: 82%; }
.skeleton-line-sm { height: 11px; width: 52%; margin-top: 8px; }
.skeleton-pill { height: 28px; border-radius: 999px; }
@keyframes productSpin { to { transform: rotate(360deg); } }
@keyframes productSkeleton { 0% { background-position: 200% 0; } 100% { background-position: -200% 0; } }
@media (max-width: 576px) {
    .products-section .d-flex.justify-content-end.mb-3 {
        margin-bottom: 0.75rem !important;
    }
    .product-table-container {
        min-height: auto !important;
    }
    .product-loading-panel {
        min-height: 220px;
        border-radius: 14px;
        padding: 1.05rem 0.75rem;
        gap: 0.5rem;
    }
    .product-loading-logo {
        width: 48px;
        height: 48px;
        font-size: 1.3rem;
    }
    .product-loading-logo::after {
        inset: -5px;
        border-width: 2px;
    }
    .product-loading-title {
        font-size: 0.92rem;
    }
    .product-loading-subtitle {
        font-size: 0.76rem;
        line-height: 1.35;
        padding: 0 0.25rem;
    }
    .product-skeleton-list {
        gap: 0.45rem;
        margin-top: 0.35rem;
    }
    .product-skeleton-item {
        grid-template-columns: 38px 1fr;
        gap: 0.55rem;
        padding: 0.55rem;
        border-radius: 12px;
    }
    .product-skeleton-item .skeleton-pill {
        display: none;
    }
    .skeleton-img {
        width: 38px;
        height: 38px;
        border-radius: 10px;
    }
    .skeleton-line-lg { height: 13px; width: 90%; }
    .skeleton-line-sm { height: 10px; width: 60%; margin-top: 7px; }
}



        /* ===== ORDER DETAILS TOTALS SUMMARY ===== */
        .order-totals-summary {
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            background: #ffffff;
            padding: 0.75rem;
            margin-left: auto;
            max-width: 360px;
            margin-top: 0.9rem;
        }
        .order-totals-summary .order-total-line {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
            padding: 0.65rem 0.85rem;
            border-radius: 10px;
            margin-bottom: 0.45rem;
            background: #f8fafc;
            color: #052A47;
        }
        .order-totals-summary .order-total-line:last-child { margin-bottom: 0; }
        .order-totals-summary .order-total-line span {
            font-weight: 700;
            letter-spacing: 0.02em;
        }
        .order-totals-summary .order-total-line strong {
            font-weight: 800;
            white-space: nowrap;
        }
        .order-totals-summary .discount-summary-line strong { color: #dc3545; }
        .order-totals-summary .grand-total-summary-line {
            background: #d1fae5;
            border-top: 2px solid #44D34E;
        }
        .order-totals-summary .grand-total-summary-line span,
        .order-totals-summary .grand-total-summary-line strong { color: #047857; }
        @media (max-width: 767px) {
            .order-totals-summary {
                width: 100%;
                max-width: 100%;
                margin-top: 0.75rem;
                padding: 0.65rem;
            }
            .order-totals-summary .order-total-line {
                font-size: 0.86rem;
                padding: 0.6rem 0.75rem;
            }
        }
        /* Order History Card Styles - Clean Layout */
/* Order History Card Styles - Clean Layout (Walang internal scroll) */
.order-history-cards {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.history-card {
    background: #FFFFFF;
    border: 1px solid #e9ecef;
    border-radius: 10px;
    padding: 12px 14px;
    transition: all 0.2s ease;
}

.history-card:hover {
    background: #fafafa;
    border-color: #d1fae5;
}

/* Row 1: Date and Status */
.history-row-1 {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 8px;
    padding-bottom: 6px;
    border-bottom: 1px dashed #eee;
}

.history-date {
    font-size: 13px;
    color: #666;
}

.history-status {
    font-size: 12px;
    padding: 2px 10px;
    border-radius: 20px;
    font-weight: 600;
    white-space: nowrap;
}

.history-status-pending {
    background: #fff3cd;
    color: #856404;
}

.history-status-completed {
    background: #d4edda;
    color: #155724;
}

.history-status-cancelled {
    background: #f8d7da;
    color: #721c24;
}

/* Row 2: SO Number */
.history-row-2 {
    margin-bottom: 6px;
}

.history-so-number {
    font-weight: 800;
    color: #047857;
    font-size: 15px;
    font-family: monospace;
    letter-spacing: 0.5px;
}

/* Row 3: Customer Name */
.history-row-3 {
    margin-bottom: 6px;
}

.history-product-name {
    font-size: 14px;
    font-weight: 500;
    color: #333;
}

/* Row 4: Unit, Qty, and Amount */
.history-row-4 {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: 4px;
    padding-top: 6px;
    border-top: 1px dashed #eee;
}

.history-unit-qty {
    font-size: 13px;
    color: #666;
}

.history-unit-qty strong {
    color: #047857;
    font-weight: 600;
}

.history-amount {
    font-size: 15px;
    font-weight: 700;
    color: #047857;
}

/* Mobile view */
@media (max-width: 768px) {
    .history-card {
        padding: 10px 12px;
    }
    
    .history-date {
        font-size: 12px;
    }
    
    .history-status {
        font-size: 11px;
        padding: 2px 8px;
    }
    
    .history-so-number {
        font-size: 13px;
    }
    
    .history-product-name {
        font-size: 15px;
    }
    
    .history-unit-qty {
        font-size: 11px;
    }
    
    .history-amount {
        font-size: 14px;
    }
}
/* Hide table header when no results */
.product-table.no-results-mode thead {
    display: none;
}
=======
        /* Product Info Modal */
=======
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
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
<<<<<<< HEAD
>>>>>>> 1bcf3b66714c50eace882b1c946948f48fb2be54
=======
        
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
      
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
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
<<<<<<< HEAD
<<<<<<< HEAD
                <button class="btn btn-success position-relative" type="button" onclick="viewCart()" title="View Cart">
=======
                <button class="btn btn-success position-relative" type="button" onclick="viewCartSummary()" title="View Cart">
>>>>>>> 1bcf3b66714c50eace882b1c946948f48fb2be54
=======
                <button class="btn btn-success position-relative" type="button" onclick="viewCart()" title="View Cart">
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
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

<<<<<<< HEAD
            <!-- Category Tabs na may customer display -->
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
=======
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
>>>>>>> 1bcf3b66714c50eace882b1c946948f48fb2be54
            </div>
        </div>
        <div class="customer-display" id="customerDisplay">
    <span class="customer-label">
        <i class="bi bi-person-circle"></i>
    </span>

<<<<<<< HEAD
<<<<<<< HEAD
    <span class="customer-name" id="selectedCustomerName">
        <?php echo !empty($pre_selected_customer_name)
            ? htmlspecialchars($pre_selected_customer_name)
            : 'Not Selected'; ?>
    </span>
</div>
    </div>
</div>

<!-- Products Section na may kasamang search at Add All button -->
<div class="col-12 products-section">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div class="search-wrapper" style="flex: 1; min-width: 200px;">
            <i class="bi bi-search search-icon"></i>
            <input type="text" class="search-input" id="searchInput" placeholder="Search products...">
            <button class="search-reset" id="searchReset" onclick="resetSearch()"><i class="bi bi-x"></i></button>
        </div>
        <button class="btn btn-success" id="bulkAddToCartBtn" onclick="bulkAddToCart()">
            <i class="bi bi-cart-plus"></i> Add All to Cart
        </button>
    </div>
                <div class="product-table-container">
                    <table class="product-table loading-products" id="productsTable">
                        <thead>
                              <tr>
                                <th></th>
=======
            <!-- Products Section - Isang table lang -->
=======
            <!-- Products Section -->
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
            <div class="col-12 products-section">
                <div class="d-flex justify-content-end mb-3">
                    <button class="btn btn-success" id="bulkAddToCartBtn" onclick="bulkAddToCart()">
                        <i class="bi bi-cart-plus"></i> Add All to Cart
                    </button>
                </div>
                <div class="product-table-container">
                    <table class="product-table" id="productsTable">
                        <thead>
<<<<<<< HEAD
                            <tr>
                                <th></th> <!-- Image -->
>>>>>>> 1bcf3b66714c50eace882b1c946948f48fb2be54
=======
                              <tr>
                                <th></th>
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
                                <th>Product</th>
                                <th>Unit</th>
                                <th>Qty</th>
                                <th>Price</th>
<<<<<<< HEAD
<<<<<<< HEAD
                              </tr>
                        </thead>
                        <tbody id="productsContainer">
                            <tr class="product-loading-row" id="productLoadingRow">
                                <td colspan="6">
                                    <div class="product-loading-panel">
                                        <div class="product-loading-logo"><i class="bi bi-box-seam"></i></div>
                                        <p class="product-loading-title">Loading products...</p>
                                        <p class="product-loading-subtitle">Preparing product prices, UoM, stock, and images.</p>
                                        <div class="product-skeleton-list">
                                            <div class="product-skeleton-item"><div class="skeleton-block skeleton-img"></div><div><div class="skeleton-block skeleton-line-lg"></div><div class="skeleton-block skeleton-line-sm"></div></div><div class="skeleton-block skeleton-pill"></div></div>
                                            <div class="product-skeleton-item"><div class="skeleton-block skeleton-img"></div><div><div class="skeleton-block skeleton-line-lg"></div><div class="skeleton-block skeleton-line-sm"></div></div><div class="skeleton-block skeleton-pill"></div></div>
                                            <div class="product-skeleton-item"><div class="skeleton-block skeleton-img"></div><div><div class="skeleton-block skeleton-line-lg"></div><div class="skeleton-block skeleton-line-sm"></div></div><div class="skeleton-block skeleton-pill"></div></div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
=======
                                <th>Action</th>
                            </tr>
=======
                              </tr>
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
                        </thead>
                        <tbody id="productsContainer">
                            <!-- Products will be loaded here -->
>>>>>>> 1bcf3b66714c50eace882b1c946948f48fb2be54
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Product Info Modal -->
<<<<<<< HEAD
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
                        
                        <h6 class="fw-bold text-success px-3 mt-3"><i class="bi bi-clock-history"></i> Order History</h6>
                        <div id="modalOrderHistory" class="order-history-cards px-3 pb-3">
                            <div class="text-center py-4 text-muted">
                                <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                                <span>No order history</span>
                            </div>
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
=======
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
<<<<<<< HEAD
                        <select class="form-select" id="modalCustomerSelect">
>>>>>>> 1bcf3b66714c50eace882b1c946948f48fb2be54
=======
                        <select class="form-select" id="modalCustomerSelect" <?php echo $is_customer_locked ? 'disabled' : ''; ?>>
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
                            <option value="">-- Choose Customer --</option>
                            <?php foreach ($customers as $customer): ?>
                                <option value="<?php echo $customer['customer_id']; ?>" 
                                        data-email="<?php echo htmlspecialchars($customer['email'] ?? ''); ?>"
                                        data-phone="<?php echo htmlspecialchars($customer['phone_number'] ?? ''); ?>"
<<<<<<< HEAD
<<<<<<< HEAD
                                        data-address="<?php echo htmlspecialchars($customer['address'] ?? ''); ?>"
                                        data-price-level="<?php echo htmlspecialchars($customer['price_level'] ?? 'Standard'); ?>"
                                        <?php echo ($pre_selected_customer_id == $customer['customer_id']) ? 'selected' : ''; ?>>
=======
                                        data-address="<?php echo htmlspecialchars($customer['address'] ?? ''); ?>">
>>>>>>> 1bcf3b66714c50eace882b1c946948f48fb2be54
=======
                                        data-address="<?php echo htmlspecialchars($customer['address'] ?? ''); ?>"
                                        data-price-level="<?php echo htmlspecialchars($customer['price_level'] ?? 'Standard'); ?>"
                                        <?php echo ($pre_selected_customer_id == $customer['customer_id']) ? 'selected' : ''; ?>>
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
                                    <?php echo htmlspecialchars($customer['customer_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
<<<<<<< HEAD
<<<<<<< HEAD
                        <?php if ($is_customer_locked): ?>
                            <?php 
                                // Use the customer resolved near the top of the file.
                                $locked_customer = $selected_customer;
=======
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
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
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
<<<<<<< HEAD
=======
>>>>>>> 1bcf3b66714c50eace882b1c946948f48fb2be54
=======
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
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
<<<<<<< HEAD
                            <span id="discountLabel">Discount (<span id="discountPercent">0</span>%):</span>
=======
                            <span>Discount (<span id="discountPercent">0</span>%):</span>
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
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
<<<<<<< HEAD
                </div>
            </div>
        </div>
    </div>

<<<<<<< HEAD
        <!-- Order Details Modal -->
    <div class="modal fade no-print" id="orderDetailsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Order Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="orderDetailsContent">
                    <!-- Content loaded via AJAX -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-danger" id="cancelOrderBtn" style="display: none;" onclick="cancelOrderFromOrderProduct()">Cancel Order</button>
                    <button type="button" class="btn btn-primary" id="printOrderFromDetails" style="display: none;" onclick="printOrderFromOrderProduct()">Print Order</button>
=======
>>>>>>> 1bcf3b66714c50eace882b1c946948f48fb2be54
                </div>
            </div>
        </div>
    </div>
    
<!-- Mobile Bottom Navigation -->
=======
    <!-- Mobile Bottom Navigation -->
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
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
<<<<<<< HEAD
                <a class="nav-link" href="sales_collections.php">
                    <i class="bi bi-cash-stack"></i>
                    <span>Collections</span>
                </a>
            </li>
            <li class="nav-item">
=======
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
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
<<<<<<< HEAD
<<<<<<< HEAD
=======
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
                    <h5 class="modal-title" id="profileModalLabel">
                        <i class="bi bi-person-circle me-2"></i>User Profile
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
<<<<<<< HEAD
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
=======
                    <h5 class="modal-title">Success!</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
=======
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
                </div>
                <div class="modal-body text-center">
                    <div class="user-avatar-large mb-3">
                        <?php echo $user_initials; ?>
                    </div>
<<<<<<< HEAD
                </div>
                <div class="modal-footer justify-content-center">
                    <button type="button" class="btn btn-primary" onclick="createNewOrder()">New Order</button>
                    <button type="button" class="btn btn-outline-secondary" onclick="viewOrders()">View Orders</button>
>>>>>>> 1bcf3b66714c50eace882b1c946948f48fb2be54
=======
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
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
<<<<<<< HEAD
<<<<<<< HEAD
=======
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
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

<<<<<<< HEAD
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
=======
=======
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
        // Copy SQL functions
        function copyItemsSQL() {
            const sql = "ALTER TABLE items ADD COLUMN branch_id INT NULL;\nALTER TABLE items ADD FOREIGN KEY (branch_id) REFERENCES branches(branch_id);";
            navigator.clipboard.writeText(sql).then(() => showToast('SQL copied!'));
        }
        function copyCustomersSQL() {
            const sql = "ALTER TABLE customers ADD COLUMN branch_id INT NULL;\nALTER TABLE customers ADD FOREIGN KEY (branch_id) REFERENCES branches(branch_id);";
            navigator.clipboard.writeText(sql).then(() => showToast('SQL copied!'));
        }

<<<<<<< HEAD
        // Unit conversions
>>>>>>> 1bcf3b66714c50eace882b1c946948f48fb2be54
=======
        // Unit conversions - defaults, will be overridden by database values
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
        const UNIT_CONVERSIONS = {
            'piece': 1, 'case': 12, 'inner-pack': 6, 'box': 24, 'carton': 48
        };

<<<<<<< HEAD
<<<<<<< HEAD
=======
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
        // Inventory data (base stock is DEFAULT UOM, with stock_smallest derived for conversion)
        const inventory = <?php echo $inventory_json; ?>;
        
        // Store for product unit types and pricing
        const productUnitTypes = {};
        
        // Store product images loaded from item_images table
        const productImages_data = {};
        
        // Store dynamic unit conversions per product from database (quantity_smallest_pack)
        const productUnitConversions = <?php echo $unit_conversions_json; ?>;
<<<<<<< HEAD
=======
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
>>>>>>> 1bcf3b66714c50eace882b1c946948f48fb2be54
=======
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928

        const branchId = <?php echo $branch_id; ?>;
        let cart = [];
        let activeUnitTypes = {};
        let toastTimeout = null;
        let currentFilter = 'all';
        let searchTerm = '';
<<<<<<< HEAD
<<<<<<< HEAD
        let currentDiscountPercent = 0;
        let currentDiscountType = 'percentage';
        let currentDiscountFixedAmount = 0;
=======
        let currentDiscountPercent = 0;
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
        let currentCreditTermsDays = 0;
        let currentCreditLimit = 0;
        let currentModalProductId = null;
        let currentCustomerPriceLevel = 'Standard';
<<<<<<< HEAD

        // ============= CURRENCY FORMATTING FUNCTIONS =============

        // Format number to currency with comma separators (for display)
        function formatCurrency(amount) {
            if (amount === null || amount === undefined || isNaN(amount)) return '₱0.00';
            return '₱' + parseFloat(amount).toLocaleString('en-PH', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }


        function getAppliedDiscountAmount(subtotal) {
            subtotal = parseFloat(subtotal || 0);
            if (subtotal <= 0) return 0;
            if (currentDiscountType === 'amount_based') {
                const fixedAmount = Math.max(0, parseFloat(currentDiscountFixedAmount || 0));
                return Math.min(subtotal, fixedAmount);
            }
            const percent = Math.max(0, Math.min(100, parseFloat(currentDiscountPercent || 0)));
            return subtotal * (percent / 100);
        }

        function resetCurrentDiscount() {
            currentDiscountPercent = 0;
            currentDiscountType = 'percentage';
            currentDiscountFixedAmount = 0;
            const discountPercentEl = document.getElementById('discountPercent');
            const discountLabelEl = document.getElementById('discountLabel');
            const discountLineEl = document.getElementById('discountLine');
            if (discountPercentEl) discountPercentEl.textContent = '0';
            if (discountLabelEl) discountLabelEl.innerHTML = 'Discount (<span id="discountPercent">0</span>%):';
            if (discountLineEl) discountLineEl.style.display = 'none';
        }

        function renderDiscountLine(subtotal) {
            subtotal = parseFloat(subtotal || 0);
            const discountLineEl = document.getElementById('discountLine');
            const discountLabelEl = document.getElementById('discountLabel');
            const discountAmountEl = document.getElementById('discountAmount');
            if (!discountLineEl || !discountLabelEl || !discountAmountEl) return 0;

            const discountAmount = getAppliedDiscountAmount(subtotal);
            if (discountAmount > 0 && subtotal > 0) {
                if (currentDiscountType === 'amount_based') {
                    discountLabelEl.textContent = 'Discount (Amount Based):';
                } else {
                    discountLabelEl.innerHTML = 'Discount (<span id="discountPercent">' + formatNumberWithCommas(currentDiscountPercent) + '</span>%):';
                }
                discountAmountEl.textContent = formatNumberWithCommas(discountAmount);
                discountLineEl.style.display = 'flex';
            } else {
                discountLineEl.style.display = 'none';
            }
            return discountAmount;
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
        
        function getUomInitial(unitType) {
            if (!unitType) return '';
            const initial = (unitType.uom_initial || '').toString().trim();
            if (initial) return initial.toUpperCase();
            const name = (unitType.unit_type_name || '').toString().trim();
            return name ? name.substring(0, 2).toUpperCase() : '';
        }

        function getUomDisplayName(unitType) {
            if (!unitType) return '';
            const name = (unitType.unit_type_name || '').toString().trim();
            const initial = (unitType.uom_initial || '').toString().trim().toUpperCase();
            return initial && initial !== name.toUpperCase() ? `${name} (${initial})` : name;
        }
function formatStockDisplay(stockValue, unitType) {
            const rounded = Math.floor(stockValue * 100) / 100;
            const formattedStock = rounded.toLocaleString('en-PH', {
                minimumFractionDigits: 0,
                maximumFractionDigits: 2
            });
            if (rounded < 0) return `<span class="stock-warning">${formattedStock} ${unitType}</span>`;
            return `${formattedStock} ${unitType}`;
        }

                // ============= ORDER DETAILS FUNCTIONS =============
        let currentOrderIdFromOrderProduct = null;

        // Helper function for status badge class
        function getOrderStatusBadgeClass(status) {
            switch(status) {
                case 'pending': return 'bg-warning text-dark';
                case 'processing': return 'bg-info text-white';
                case 'shipped': return 'bg-primary text-white';
                case 'delivered': return 'bg-success text-white';
                case 'cancelled': return 'bg-danger text-white';
                default: return 'bg-secondary text-white';
            }
        }

        // Helper function for status text
        function getOrderStatusText(status) {
            switch(status) {
                case 'pending': return 'Pending';
                case 'processing': return 'Processing';
                case 'shipped': return 'Shipped';
                case 'delivered': return 'Delivered';
                case 'cancelled': return 'Cancelled';
                default: return status || 'Unknown';
            }
        }

        // Function to view order details in modal
        function viewOrderFromOrderProduct(orderId) {
            currentOrderIdFromOrderProduct = orderId;
            const modal = new bootstrap.Modal(document.getElementById('orderDetailsModal'));
            
            // Show loading
            const orderDetailsContent = document.getElementById('orderDetailsContent');
            if (orderDetailsContent) {
                orderDetailsContent.innerHTML = `
                    <div class="loading-state text-center py-5">
                        <div class="spinner-border text-success" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        <p class="mt-3 text-muted">Loading order details...</p>
                    </div>
                `;
            }
            
            modal.show();
            
            fetch('orderproduct.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: 'action=get_order_details&order_id=' + orderId
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    const order = data.order;
                    const items = data.items;
                    
                    // Build items table HTML
                    let itemsHtml = '';
                    let computedSubtotal = 0;
                    let computedGrandTotal = 0;
                    
                    if (items && items.length > 0) {
                        items.forEach(item => {
                            const qty = parseFloat(item.quantity_ordered) || 0;
                            const grossPrice = parseFloat(item.gross_price) > 0 ? parseFloat(item.gross_price) : parseFloat(item.unit_price || 0);
                            const netPrice = parseFloat(item.net_price) > 0 ? parseFloat(item.net_price) : parseFloat(item.unit_price || 0);
                            const total = parseFloat(item.order_amount) > 0
                                ? parseFloat(item.order_amount)
                                : (parseFloat(item.line_total) > 0 ? parseFloat(item.line_total) : (qty * netPrice));
                            computedSubtotal += qty * grossPrice;
                            computedGrandTotal += total;
                            itemsHtml += `
                                <tr>
                                    <td><strong>${escapeHtml(item.item_name)}</strong><br><small class="text-muted">${escapeHtml(item.item_code)}</small></td>
                                    <td class="text-center">${escapeHtml(item.unit_type || 'N/A')}</td>
                                    <td class="text-center">${Number.isInteger(qty) ? parseInt(qty) : qty}</td>
                                    <td class="text-end">${formatCurrency(netPrice)}</td>
                                    <td class="text-end">${formatCurrency(total)}</td>
                                </tr>
                            `;
                        });
                    } else {
                        itemsHtml = `<tr><td colspan="5" class="text-center text-muted py-4">No items found</td></tr>`;
                    }

                    const savedSubtotal = parseFloat(order.order_subtotal) || 0;
                    const savedGrandTotal = parseFloat(order.total_amount) || 0;
                    const subtotal = savedSubtotal > 0 ? savedSubtotal : computedSubtotal;
                    const grandTotal = savedGrandTotal > 0 ? savedGrandTotal : computedGrandTotal;
                    let discountTotal = parseFloat(order.total_discount_amount) || parseFloat(order.discount_amount) || 0;
                    if (discountTotal <= 0 && subtotal > grandTotal) {
                        discountTotal = subtotal - grandTotal;
                    }
                    discountTotal = Math.max(0, discountTotal);
                    
                    orderDetailsContent.innerHTML = `
                        <div class="order-details-card">
                            <div class="order-header-section">
                                <div class="order-badge"><i class="bi bi-receipt"></i> Order Information</div>
                                <div class="order-number">${escapeHtml(order.so_number)}</div>
                            </div>
                            <div class="order-info-grid">
                                <div class="order-info-item">
                                    <div class="order-info-label"><i class="bi bi-calendar3"></i> Order Date</div>
                                    <div class="order-info-value">${new Date(order.order_date).toLocaleString()}</div>
                                </div>
                                <div class="order-info-item">
                                    <div class="order-info-label"><i class="bi bi-flag"></i> Status</div>
                                    <div class="order-info-value"><span class="badge ${getOrderStatusBadgeClass(order.order_status)}">${getOrderStatusText(order.order_status)}</span></div>
                                </div>
                                <div class="order-info-item">
                                    <div class="order-info-label"><i class="bi bi-building"></i> Branch</div>
                                    <div class="order-info-value">${escapeHtml(order.branch_name || 'N/A')}</div>
                                </div>
                                <div class="order-info-item">
                                    <div class="order-info-label"><i class="bi bi-person"></i> Created By</div>
                                    <div class="order-info-value">${escapeHtml(order.created_by || 'System')}</div>
                                </div>
                                <div class="order-info-item">
                                    <div class="order-info-label"><i class="bi bi-truck"></i> Assigned Driver</div>
                                    <div class="order-info-value">${order.assigned_driver && order.assigned_driver !== 'No Driver' ? `<span class="driver-badge-modal"><i class="bi bi-person-badge"></i> ${escapeHtml(order.assigned_driver)}</span>` : '<span class="text-muted">No Driver Assigned</span>'}</div>
                                </div>
                            </div>
                            <div class="customer-section">
                                <h6><i class="bi bi-person-badge"></i> Customer Information</h6>
                                <div class="customer-info-card">
                                    <div class="customer-detail-row"><span class="customer-detail-label">Customer Name:</span><span class="customer-detail-value">${escapeHtml(order.customer_name || 'N/A')}</span></div>
                                    <div class="customer-detail-row"><span class="customer-detail-label">Store Name:</span><span class="customer-detail-value">${escapeHtml(order.store_name || 'N/A')}</span></div>
                                    <div class="customer-detail-row"><span class="customer-detail-label">Customer Code:</span><span class="customer-detail-value">${escapeHtml(order.customer_code || 'N/A')}</span></div>
                                    <div class="customer-detail-row"><span class="customer-detail-label">Email:</span><span class="customer-detail-value">${escapeHtml(order.email || 'N/A')}</span></div>
                                    <div class="customer-detail-row"><span class="customer-detail-label">Phone:</span><span class="customer-detail-value">${escapeHtml(order.phone_number || 'N/A')}</span></div>
                                    <div class="customer-detail-row"><span class="customer-detail-label">Address:</span><span class="customer-detail-value">${escapeHtml(order.address || 'N/A')}</span></div>
                                </div>
                            </div>
                            <div class="items-section">
                                <h6><i class="bi bi-box-seam"></i> Order Items</h6>
                                <div class="table-responsive">
                                    <table class="items-table">
                                        <thead><tr><th>Product</th><th class="text-center">Unit</th><th class="text-center">Qty</th><th class="text-end">Unit Price</th><th class="text-end">Total</th></tr></thead>
                                        <tbody>${itemsHtml}</tbody>
                                    </table>
                                </div>
                                <div class="order-totals-summary">
                                    <div class="order-total-line subtotal-summary-line">
                                        <span>SUBTOTAL</span>
                                        <strong>${formatCurrency(subtotal)}</strong>
                                    </div>
                                    <div class="order-total-line discount-summary-line">
                                        <span>DISCOUNT</span>
                                        <strong>${discountTotal > 0 ? '-' : ''}${formatCurrency(discountTotal)}</strong>
                                    </div>
                                    <div class="order-total-line grand-total-summary-line">
                                        <span>GRAND TOTAL</span>
                                        <strong>${formatCurrency(grandTotal)}</strong>
                                    </div>
                                </div>
                            </div>
                        </div>
                    `;
                    
                    document.getElementById('printOrderFromDetails').style.display = 'inline-block';
                    document.getElementById('cancelOrderBtn').style.display = 'inline-block';
                } else {
                    orderDetailsContent.innerHTML = `<div class="error-state text-center py-5"><i class="bi bi-exclamation-triangle fs-1 text-danger"></i><p class="mt-3">${escapeHtml(data.message || 'Error loading order details.')}</p></div>`;
                    document.getElementById('printOrderFromDetails').style.display = 'none';
                    document.getElementById('cancelOrderBtn').style.display = 'none';
                }
            })
            .catch(error => {
                orderDetailsContent.innerHTML = `<div class="error-state text-center py-5"><i class="bi bi-wifi-off fs-1 text-danger"></i><p class="mt-3">Network error: ${escapeHtml(error.message)}</p></div>`;
                document.getElementById('printOrderFromDetails').style.display = 'none';
                document.getElementById('cancelOrderBtn').style.display = 'none';
            });
        }

        // Print order from modal
        function printOrderFromOrderProduct() {
            if (currentOrderIdFromOrderProduct) {
                printSingleOrderFromOrderProduct(currentOrderIdFromOrderProduct);
                const modal = bootstrap.Modal.getInstance(document.getElementById('orderDetailsModal'));
                if (modal) modal.hide();
            }
        }

        function printSingleOrderFromOrderProduct(orderId) {
            const printBtn = document.querySelector('#printOrderFromDetails');
            if (printBtn) {
                printBtn.innerHTML = '<i class="bi bi-printer"></i> Printing...';
                printBtn.disabled = true;
            }
            
            const formData = new FormData();
            formData.append('action', 'print_order');
            formData.append('so_id', orderId);
            
            fetch('orderproduct.php', { method: 'POST', body: formData })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const htmlContent = generateSingleOrderHTML(data.order, data.items, data.driver);
                        const iframe = document.getElementById('printFrame') || createPrintFrame();
                        const iframeDoc = iframe.contentWindow.document;
                        iframeDoc.open();
                        iframeDoc.write(htmlContent);
                        iframeDoc.close();
                        setTimeout(() => iframe.contentWindow.print(), 250);
                    } else {
                        Swal.fire('Error', 'Failed to load order details', 'error');
                    }
                    if (printBtn) {
                        printBtn.innerHTML = '<i class="bi bi-printer"></i> Print Order';
                        printBtn.disabled = false;
                    }
                })
                .catch(error => {
                    Swal.fire('Error', 'Network error: ' + error.message, 'error');
                    if (printBtn) {
                        printBtn.innerHTML = '<i class="bi bi-printer"></i> Print Order';
                        printBtn.disabled = false;
                    }
                });
        }

        // Cancel order from modal
        function cancelOrderFromOrderProduct() {
            if (!currentOrderIdFromOrderProduct) {
                Swal.fire('Error', 'No order selected', 'error');
                return;
            }

            Swal.fire({
                title: 'Cancel Order?',
                text: 'This action cannot be undone.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                confirmButtonText: 'Yes, cancel it'
            }).then((result) => {
                if (result.isConfirmed) {
                    const cancelBtn = document.getElementById('cancelOrderBtn');
                    if (cancelBtn) cancelBtn.disabled = true;

                    fetch('orderproduct.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
                        body: 'action=cancel_order&order_id=' + currentOrderIdFromOrderProduct
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            Swal.fire('Success!', 'Order cancelled successfully', 'success');
                            const modal = bootstrap.Modal.getInstance(document.getElementById('orderDetailsModal'));
                            if (modal) modal.hide();
                            currentOrderIdFromOrderProduct = null;
                        } else {
                            Swal.fire('Error', data.message || 'Failed to cancel order', 'error');
                            if (cancelBtn) cancelBtn.disabled = false;
                        }
                    })
                    .catch(error => {
                        Swal.fire('Error', 'Error cancelling order: ' + error.message, 'error');
                        if (cancelBtn) cancelBtn.disabled = false;
                    });
                }
            });
        }

        function createPrintFrame() {
            let iframe = document.getElementById('printFrame');
            if (!iframe) {
                iframe = document.createElement('iframe');
                iframe.id = 'printFrame';
                iframe.style.position = 'absolute';
                iframe.style.left = '-9999px';
                iframe.style.top = '-9999px';
                document.body.appendChild(iframe);
            }
            return iframe;
        }

        function generateSingleOrderHTML(order, items, driver) {
            let itemsHtml = '';
            let totalQty = 0;
            if (items && items.length > 0) {
                items.forEach(item => {
                    totalQty += parseInt(item.quantity_ordered);
                    itemsHtml += `<tr><td>${escapeHtml(item.item_code)}</td><td>${escapeHtml(item.item_name)}</td><td class="text-center">${escapeHtml(item.unit_type || '')}</td><td class="text-center">${item.quantity_ordered}</td><td class="text-end">${formatCurrency(item.unit_price)}</td><td class="text-end">${formatCurrency(item.quantity_ordered * item.unit_price)}</td></tr>`;
                });
            }
            const totalAmount = order ? order.order_total : 0;
            const logoBase64 = '<?php $logo_path = "../Pictures/amgc3DLogo.png"; $logo_base64 = file_exists($logo_path) ? "data:image/png;base64," . base64_encode(file_get_contents($logo_path)) : ""; echo $logo_base64; ?>';
            return `<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Order #${order?.so_number || ''}</title><style>body{font-family:Arial;margin:0;padding:0;font-size:10px}.print-header{display:flex;justify-content:space-between;border-bottom:1px solid #000;padding-bottom:3px;margin-bottom:5px}.logo-section{display:flex;gap:5px}.company-logo{width:30px}table{width:100%;border-collapse:collapse}th,td{border:1px solid #000;padding:3px}th{background:#f0f0f0}.total-row{font-weight:bold}</style></head><body><div class="print-container"><div class="print-header"><div class="logo-section"><img src="${logoBase64}" class="company-logo"><div><h1>AMGC</h1><p>Sales Order</p></div></div><div><h2>${order?.so_number || ''}</h2><div>${new Date().toLocaleDateString()}</div></div></div><div class="customer-section"><div><strong>Customer:</strong> ${escapeHtml(order?.customer_name || '')}</div><div><strong>Driver:</strong> ${escapeHtml(driver?.driver_name || order?.assigned_driver || 'No Driver')}</div><div><strong>Date:</strong> ${order?.order_date ? new Date(order.order_date).toLocaleDateString() : ''}</div><div><strong>Status:</strong> ${order?.order_status || ''}</div></div><table><thead><tr><th>Item Code</th><th>Item Name</th><th>Unit</th><th>Qty</th><th>Unit Price</th><th>Subtotal</th></tr></thead><tbody>${itemsHtml}<tr class="total-row"><td colspan="4" class="text-end">TOTAL</td><td class="text-center">${totalQty}</td><td class="text-end">${formatCurrency(totalAmount)}</td></tr></tbody></table><div class="print-footer"><div>Created by: ${order?.first_name ? order.first_name + ' ' + (order.last_name || '') : 'Sales User'}</div><div>${new Date().toLocaleDateString()}</div></div></div></body></html>`;
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
        

        function getProductsLoadingHtml(message = 'Loading products...', subtitle = 'Preparing product prices, UoM, stock, and images.') {
            return `
                <tr class="product-loading-row" id="productLoadingRow">
                    <td colspan="6">
                        <div class="product-loading-panel">
                            <div class="product-loading-logo"><i class="bi bi-box-seam"></i></div>
                            <p class="product-loading-title">${escapeHtml(message)}</p>
                            <p class="product-loading-subtitle">${escapeHtml(subtitle)}</p>
                            <div class="product-skeleton-list">
                                <div class="product-skeleton-item"><div class="skeleton-block skeleton-img"></div><div><div class="skeleton-block skeleton-line-lg"></div><div class="skeleton-block skeleton-line-sm"></div></div><div class="skeleton-block skeleton-pill"></div></div>
                                <div class="product-skeleton-item"><div class="skeleton-block skeleton-img"></div><div><div class="skeleton-block skeleton-line-lg"></div><div class="skeleton-block skeleton-line-sm"></div></div><div class="skeleton-block skeleton-pill"></div></div>
                                <div class="product-skeleton-item"><div class="skeleton-block skeleton-img"></div><div><div class="skeleton-block skeleton-line-lg"></div><div class="skeleton-block skeleton-line-sm"></div></div><div class="skeleton-block skeleton-pill"></div></div>
                            </div>
                        </div>
                    </td>
                </tr>`;
        }

        function showProductsLoading(message = 'Loading products...', subtitle = 'Preparing product prices, UoM, stock, and images.') {
            const table = document.getElementById('productsTable');
            const container = document.getElementById('productsContainer');
            if (table) table.classList.add('loading-products');
            if (container) container.innerHTML = getProductsLoadingHtml(message, subtitle);
        }

        function hideProductsLoading() {
            const table = document.getElementById('productsTable');
            if (table) table.classList.remove('loading-products');
        }

        // Reload prices for all products based on price level
        function reloadProductPrices(priceLevel) {
            showProductsLoading('Updating products...', 'Applying the selected customer price level.');
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
                            const firstUnit = uniqueUnitTypes[0];
                            if (firstUnit) {
                                activeUnitTypes[productId] = firstUnit.unit_type_name;
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
            showProductsLoading('Loading products...', 'Preparing product prices, UoM, stock, and images.');
            await loadAllProductUnitTypes();
            await loadAllProductImages();
            
            // Set active unit type for each product - ALLOW USER TO SELECT ANY UNIT TYPE
            inventory.forEach(product => {
                if (!activeUnitTypes[product.id]) {
                    const unitTypes = productUnitTypes[product.id];
                    if (unitTypes && unitTypes.length > 0) {
                        // Default to the first available unit type (usually Piece)
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
            hideProductsLoading();
            
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
    // Hide the table header when no products found
    const table = document.getElementById('productsTable');
    if (table) {
        table.classList.add('no-results-mode');
    }
    
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

// Show header again when there are results
const table = document.getElementById('productsTable');
if (table) {
    table.classList.remove('no-results-mode');
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

        // Get available stock directly from the selected unit type
        function getAvailableStockSmallest(productId) {
            const p = inventory.find(p => p.id === productId);
            if (!p) return 0;
            const activeUnit = activeUnitTypes[productId] || p.default_unit_type_name || p.unit_type || 'Piece';
            return getConvertedStock(productId, activeUnit);
        }

        // Backward-compatible alias
        function getAvailableStock(productId) {
            const p = inventory.find(p => p.id === productId);
            if (!p) return 0;
            const activeUnit = activeUnitTypes[productId] || p.default_unit_type_name || p.unit_type || 'Piece';
            return getConvertedStock(productId, activeUnit);
        }

        // Get available stock directly from the selected unit type inventory
        function getConvertedStock(productId, unitType) {
            const p = inventory.find(p => p.id === productId);
            if (!p) return 0;
            const unitStocks = p.unit_stocks || {};
            const currentUnitStock = parseFloat(unitStocks[unitType] ?? 0);
            const inCartSameUnit = cart
                .filter(i => i.id === productId && (i.unit_type || '') === unitType)
                .reduce((t, i) => t + parseFloat(i.quantity || 0), 0);
            return currentUnitStock - inCartSameUnit;
        }

        function getCartItemPieces(item) {
            return parseFloat(item.quantity || 0);
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
                // FIXED: getConvertedStock already returns the stock for the selected unit type
                // Don't divide by conversion again - that causes double conversion and wrong display
                const convertedStock = getConvertedStock(p.id, activeUnit);
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
                        const shortLabel = getUomInitial(ut);
                        const isActive = activeUnitTypes[p.id] === ut.unit_type_name ? 'active' : '';
                        
                        unitButtonsHtml += `<button class="unit-btn ${isActive}" data-product-id="${p.id}" data-unit-type="${ut.unit_type_name}" onclick="event.stopPropagation(); setActiveUnit(${p.id}, '${ut.unit_type_name}')">${shortLabel}</button>`;
                        unitDropdownOptions += `<option value="${ut.unit_type_name}" ${isActive ? 'selected' : ''}>${getUomDisplayName(ut)}</option>`;
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
            
            const type = activeUnitTypes[pid] || p.default_unit_type_name || p.unit_type || 'Piece';
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
            
            const qtyMobileInput = document.getElementById(`qty-${pid}`);
            const qtyDesktopInput = document.getElementById(`qty-desktop-${pid}`);
            if (qtyMobileInput) qtyMobileInput.value = '0';
            if (qtyDesktopInput) qtyDesktopInput.value = '0';
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
                
                const type = activeUnitTypes[pid] || p.default_unit_type_name || p.unit_type || 'Piece';
                
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
            
            // GAMITIN ANG BAGONG CARD FUNCTION DITO
            if (data.order_history && data.order_history.length) {
                renderOrderHistoryAsCards(data.order_history);
            } else {
                const container = document.getElementById('modalOrderHistory');
                container.innerHTML = '<div class="text-center py-4 text-muted"><i class="bi bi-inbox fs-1 d-block mb-2"></i><span>No order history</span></div>';
            }
            
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
=======
=======
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928

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
>>>>>>> 1bcf3b66714c50eace882b1c946948f48fb2be54
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

<<<<<<< HEAD
=======
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

>>>>>>> 1bcf3b66714c50eace882b1c946948f48fb2be54
        function clearCart() {
            if (cart.length === 0) {
                showToast('Cart is already empty');
                return;
            }
            
<<<<<<< HEAD
<<<<<<< HEAD
=======
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
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
<<<<<<< HEAD
                    resetCurrentDiscount();
=======
                    currentDiscountPercent = 0;
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
                    currentCreditTermsDays = 0;
                    currentCreditLimit = 0;
                    
                    // Close the review modal if open
                    const cartModal = bootstrap.Modal.getInstance(document.getElementById('cartModal'));
                    if (cartModal) cartModal.hide();
                    
                    renderProducts();
                    showToast('Cart cleared successfully');
<<<<<<< HEAD
                }
            });
=======
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
>>>>>>> 1bcf3b66714c50eace882b1c946948f48fb2be54
=======
                }
            });
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
        }

        function removeFromCart(id, unit) {
            cart = cart.filter(i => !(i.id === id && i.unit_type === unit));
            updateCartBadge();
            
            const reviewItems = document.getElementById('reviewItems');
            if (reviewItems && cart.length > 0) {
<<<<<<< HEAD
<<<<<<< HEAD
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
=======
                let html = '<div class="table-responsive"><table class="table table-sm"><thead class="table-light"><tr><th>Product</th><th>Unit</th><th>Qty</th><th>Price</th><th>Total</th></tr></thead><tbody>';
=======
                let html = '<div class="table-responsive"><table class="table table-sm"><thead class="table-light"><tr><th>Product</th><th>Unit</th><th>Qty</th><th>Price</th><th>Total</th></td></thead><tbody>';
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
                cart.forEach(i => {
                    const pieces = getCartItemPieces(i);
                    html += `<tr><td style="text-align:left">${escapeHtml(i.name)}</td>
                            <td style="text-align:center">${escapeHtml(i.unit_type)}</td>
                            <td style="text-align:center">${i.quantity} (${pieces} pcs)</td>
                            <td style="text-align:right">${formatCurrency(i.price)}</td>
                            <td style="text-align:right">${formatCurrency(i.price * i.quantity)}</td>
                          </tr>`;
                });
<<<<<<< HEAD
                html += '</tbody></table></div>';
>>>>>>> 1bcf3b66714c50eace882b1c946948f48fb2be54
=======
                html += '</tbody></div>';
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
                reviewItems.innerHTML = html;
            } else if (reviewItems) {
                reviewItems.innerHTML = '<p class="text-muted text-center">No items in cart</p>';
            }
            
            const subtotal = cart.reduce((s, i) => s + i.price * i.quantity, 0);
<<<<<<< HEAD
<<<<<<< HEAD
            document.getElementById('reviewSubtotal').textContent = formatCurrency(subtotal);
            const discountAmount = renderDiscountLine(subtotal);
            document.getElementById('reviewTotal').textContent = formatCurrency(Math.max(0, subtotal - discountAmount));
=======
            document.getElementById('reviewSubtotal').textContent = `₱${subtotal.toFixed(2)}`;
            document.getElementById('reviewTotal').textContent = `₱${subtotal.toFixed(2)}`;
>>>>>>> 1bcf3b66714c50eace882b1c946948f48fb2be54
=======
            document.getElementById('reviewSubtotal').textContent = formatCurrency(subtotal);
            if (currentDiscountPercent > 0) {
                const discountAmount = subtotal * currentDiscountPercent / 100;
                document.getElementById('discountAmount').textContent = discountAmount.toFixed(2);
                document.getElementById('reviewTotal').textContent = formatCurrency(subtotal - discountAmount);
            } else {
                document.getElementById('reviewTotal').textContent = formatCurrency(subtotal);
            }
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
            
            showToast('Item removed from cart');
            renderProducts();
        }

        function showToast(msg) {
            if (toastTimeout) clearTimeout(toastTimeout);
            
            const existing = document.querySelector('.toast-notification');
            if (existing) existing.remove();
            
            const toast = document.createElement('div');
            toast.className = 'toast-notification';
<<<<<<< HEAD
<<<<<<< HEAD
            toast.innerHTML = `<i class="bi bi-check-circle-fill me-2"></i>${escapeHtml(msg)}`;
=======
            toast.innerHTML = `<i class="bi bi-check-circle-fill me-2"></i>${msg}`;
>>>>>>> 1bcf3b66714c50eace882b1c946948f48fb2be54
=======
            toast.innerHTML = `<i class="bi bi-check-circle-fill me-2"></i>${escapeHtml(msg)}`;
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
            document.body.appendChild(toast);
            
            toastTimeout = setTimeout(() => {
                toast.classList.add('fade-out');
                setTimeout(() => toast.remove(), 300);
            }, 2000);
<<<<<<< HEAD
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
        
        resetCurrentDiscount();
        document.getElementById('creditTermsLine').style.display = 'none';
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
                
=======
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
                
<<<<<<< HEAD
>>>>>>> 1bcf3b66714c50eace882b1c946948f48fb2be54
=======
                if (preSelectedCustomerId > 0) {
                    const option = Array.from(newSelect.options).find(opt => opt.value == preSelectedCustomerId);
                    if (option) {
                        newSelect.value = preSelectedCustomerId;
                    }
                }
                
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
                newSelect.addEventListener('change', function() {
                    const opt = this.options[this.selectedIndex];
                    if (opt.value) {
                        document.getElementById('reviewCustomer').textContent = opt.text.split('(')[0].trim();
                        document.getElementById('reviewEmail').textContent = opt.dataset.email || '-';
                        document.getElementById('reviewPhone').textContent = opt.dataset.phone || '-';
                        document.getElementById('reviewAddress').textContent = opt.dataset.address || '-';
<<<<<<< HEAD
<<<<<<< HEAD
=======
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
                        
                        const priceLevel = opt.dataset.priceLevel || 'Standard';
                        reloadProductPrices(priceLevel);
                        
                        fetchCustomerDiscount(opt.value);
                        fetchCustomerCreditTerms(opt.value);
<<<<<<< HEAD
=======
>>>>>>> 1bcf3b66714c50eace882b1c946948f48fb2be54
=======
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
                    } else {
                        document.getElementById('reviewCustomer').textContent = '-';
                        document.getElementById('reviewEmail').textContent = '-';
                        document.getElementById('reviewPhone').textContent = '-';
                        document.getElementById('reviewAddress').textContent = '-';
<<<<<<< HEAD
<<<<<<< HEAD
                        resetCurrentDiscount();
                        currentCreditTermsDays = 0;
                        currentCreditLimit = 0;
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
    const discountAmount = renderDiscountLine(subtotal);
    document.getElementById('reviewTotal').textContent = formatCurrency(Math.max(0, subtotal - discountAmount));
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
                resetCurrentDiscount();
                updateReviewTotals();
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
                    currentDiscountType = data.discount_type || 'percentage';
                    currentDiscountPercent = parseFloat(data.discount || 0);
                    currentDiscountFixedAmount = parseFloat(data.calculated_discount_amount || data.discount_based_amount || 0);
                    updateReviewTotals();
=======
=======
                        currentDiscountPercent = 0;
                        currentCreditTermsDays = 0;
                        currentCreditLimit = 0;
                        document.getElementById('discountLine').style.display = 'none';
                        document.getElementById('creditTermsLine').style.display = 'none';
                        updateReviewTotals();
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
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
<<<<<<< HEAD
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
>>>>>>> 1bcf3b66714c50eace882b1c946948f48fb2be54
                }
                btn.innerHTML = orig;
                btn.disabled = false;
            })
<<<<<<< HEAD
            .catch(err => console.error('Fetch discount error:', err));
        }

        function updateCartTotals() {
            updateReviewTotals();
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

        function toggleManualSoNumber() {
            const manualRadio = document.getElementById('soNumberManual');
            const manualBox = document.getElementById('manualSoNumberBox');
            const manualInput = document.getElementById('manualSoLastNumber');
            if (!manualRadio || !manualBox) return;

            const isManual = manualRadio.checked;
            manualBox.style.display = isManual ? 'block' : 'none';
            if (manualInput) {
                manualInput.required = isManual;
                if (!isManual) manualInput.value = '';
            }
        }

    function showSONumberForm(soPrefix, callback) {
        // Generate auto-SO number
        const autoSoSuffix = String(Date.now()).slice(-6);

        const cartModalEl = document.getElementById('cartModal');
        const cartModalInstance = cartModalEl ? bootstrap.Modal.getInstance(cartModalEl) : null;

        const showReviewAgain = () => {
            if (cartModalEl) {
                bootstrap.Modal.getOrCreateInstance(cartModalEl, {
                    backdrop: 'static',
                    keyboard: false
                }).show();
            }
        };

        const openSoModal = () => {
            // Remove existing SO modal first
            const existingModal = document.getElementById('soNumberModal');
            if (existingModal) {
                const oldModalInstance = bootstrap.Modal.getInstance(existingModal);
                if (oldModalInstance) oldModalInstance.dispose();
                existingModal.remove();
            }

            // Create modal HTML
            const modalHtml = `
                <div class="modal fade so-number-modal" id="soNumberModal" tabindex="-1" aria-labelledby="soNumberModalLabel" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content" style="border:0; border-radius:4px; overflow:hidden; box-shadow:0 18px 45px rgba(0,0,0,.35);">
                            <div class="modal-header" style="background:#047857; color:#ffffff; border-bottom:0; padding:18px 22px;">
                                <h5 class="modal-title d-flex align-items-center gap-2 mb-0" id="soNumberModalLabel" style="font-weight:700;">
                                    <i class="bi bi-receipt"></i>
                                    <span>SO Number</span>
                                </h5>
                                <button type="button"
                                        class="btn-close btn-close-white"
                                        id="soCloseBtn"
                                        aria-label="Close"
                                        style="opacity:1; box-shadow:none; filter:brightness(0) invert(1);">
                                </button>
                            </div>

                            <div class="modal-body text-center" style="padding:34px 36px 28px;">
                                <p class="mb-4" style="color:#6b7280; font-weight:600;">
                                    SO Prefix: <span style="color:#374151;">${soPrefix}</span>
                                </p>

                                <div class="mb-4">
                                    <h6 class="mb-3" style="font-weight:700; color:#6b7280;">Auto-generated SO Number</h6>
                                    <div class="d-flex justify-content-center align-items-center flex-wrap gap-3">
                                        <div style="display:inline-block; padding:12px 18px; background:#f1f3f5; border-radius:8px; font-weight:800; color:#047857; font-size:20px; letter-spacing:2px;">
                                            ${soPrefix}<span id="autoSoNumber">${autoSoSuffix}</span>
                                        </div>
                                        <button type="button" class="btn btn-success btn-sm" id="useAutoBtn" style="background:#059669; border-color:#059669; padding:9px 14px;">
                                            Use Auto-generated
                                        </button>
                                    </div>
                                </div>

                                <hr style="margin:26px 0;">

                                <div>
                                    <h6 class="mb-3" style="font-weight:700; color:#6b7280;">Or Enter Custom SO Number</h6>

                                    <div style="display:inline-block; padding:12px 18px; background:#f1f3f5; border-radius:8px; font-weight:800; color:#047857; margin-bottom:18px; font-size:20px; letter-spacing:2px;">
                                        ${soPrefix}<span id="soPreviewDigits">_____</span>
                                    </div>

                                    <div>
                                        <input type="text"
                                               id="soNumberInput"
                                               class="form-control form-control-lg text-center"
                                               placeholder="Last 5 to 6 numbers (optional)"
                                               style="font-size:18px; font-weight:700; letter-spacing:2px; height:58px; border-radius:6px;"
                                               autocomplete="off">

                                        <small class="form-text text-muted mt-2 d-block">
                                            Type the last 5 to 6 digits only, or leave blank to use auto-generated.
                                        </small>

                                        <div id="soError" class="alert alert-danger mt-3" style="display:none;"></div>
                                    </div>
                                </div>
                            </div>

                            <div class="modal-footer" style="border-top:1px solid #e5e7eb; padding:18px 22px;">
                                <button type="button" class="btn btn-secondary" id="soCancelBtn" style="padding:9px 16px;">
                                    Cancel
                                </button>
                                <button type="button" class="btn btn-success" id="soConfirmBtn" style="background:#059669; border-color:#059669; padding:9px 16px;">
                                    Continue
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            `;

            document.body.insertAdjacentHTML('beforeend', modalHtml);

            const soModalEl = document.getElementById('soNumberModal');

            const modal = new bootstrap.Modal(soModalEl, {
                backdrop: 'static',
                keyboard: false
            });

            const input = document.getElementById('soNumberInput');
            const preview = document.getElementById('soPreviewDigits');
            const errorDiv = document.getElementById('soError');
            const confirmBtn = document.getElementById('soConfirmBtn');
            const useAutoBtn = document.getElementById('useAutoBtn');
            const closeBtn = document.getElementById('soCloseBtn');
            const cancelBtn = document.getElementById('soCancelBtn');

            let isClosing = false;

            const closeAndCallback = (value, reopenReview = false) => {
                if (isClosing) return;
                isClosing = true;

                soModalEl.addEventListener('hidden.bs.modal', () => {
                    const modalInstance = bootstrap.Modal.getInstance(soModalEl);
                    if (modalInstance) modalInstance.dispose();

                    soModalEl.remove();

                    if (reopenReview) {
                        showReviewAgain();
                    }

                    callback(value);
                }, { once: true });

                modal.hide();
            };

            // Handle input - only allow numbers
            input.addEventListener('input', function() {
                this.value = this.value.replace(/\D/g, '').slice(0, 6);
                preview.textContent = this.value || '_____';
                errorDiv.style.display = 'none';
            });

            // Handle Enter key
            input.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    confirmBtn.click();
                }
            });

            // Handle auto-generated button
            useAutoBtn.addEventListener('click', function() {
                input.value = '';
                preview.textContent = '_____';
                errorDiv.style.display = 'none';
                closeAndCallback(autoSoSuffix, false);
            });

            // Handle confirm button
            confirmBtn.addEventListener('click', function() {
                const value = input.value.trim();

                // If empty, use auto-generated
                if (value === '') {
                    closeAndCallback(autoSoSuffix, false);
                    return;
                }

                // If not empty, validate it's 5-6 digits
                if (!/^\d{5,6}$/.test(value)) {
                    errorDiv.textContent = 'Please enter 5 to 6 numbers only, or leave blank for auto-generated.';
                    errorDiv.style.display = 'block';
                    input.focus();
                    return;
                }

                closeAndCallback(value, false);
            });

            // Handle cancel / close: show Review & Confirm modal again
            closeBtn.addEventListener('click', function() {
                closeAndCallback(null, true);
            });

            cancelBtn.addEventListener('click', function() {
                closeAndCallback(null, true);
            });

            modal.show();

            setTimeout(() => {
                input.focus();
            }, 300);
        };

        // Hide Review & Confirm modal first before opening SO Number modal
        if (cartModalEl && cartModalEl.classList.contains('show')) {
            cartModalEl.addEventListener('hidden.bs.modal', openSoModal, { once: true });

            if (cartModalInstance) {
                cartModalInstance.hide();
            } else {
                bootstrap.Modal.getOrCreateInstance(cartModalEl).hide();
            }
        } else {
            openSoModal();
        }
    }

        // ========== SUBMIT ORDER FUNCTION (with locked customer support) ==========
        function submitOrder() {
            const isLocked = <?php echo $is_customer_locked ? 'true' : 'false'; ?>;
            let custId = 0;
            let opt = null;
            let select = null;
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
                select = document.getElementById('modalCustomerSelect');
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
                const cartItem = cart[i];
                const product = inventory.find(p => p.id === cartItem.id);
                const productUnits = productUnitTypes[cartItem.id] || [];
                let standardPrice = cartItem.price;

                const matchedUnit = productUnits.find(ut =>
                    String(ut.unit_type_name || '').trim() === String(cartItem.unit_type || '').trim()
                );
                if (matchedUnit && matchedUnit.unit_price !== undefined && matchedUnit.unit_price !== null) {
                    standardPrice = parseFloat(matchedUnit.unit_price) || cartItem.price;
                } else if (product) {
                    if (String(product.default_unit_type_name || product.unit_type || '').trim() === String(cartItem.unit_type || '').trim()) {
                        standardPrice = parseFloat(product.unit_price || cartItem.price) || cartItem.price;
                    }
                }

                if (parseFloat(cartItem.price) < parseFloat(standardPrice)) {
                    showToast(`Item "${cartItem.name}" price is below standard price ${formatCurrency(standardPrice)}`);
                    allPricesValid = false;
                    break;
                }
            }
            if (!allPricesValid) return;
            
            const items = cart.map(i => ({
                id: i.id, name: i.name, price: i.price, quantity: i.quantity, sku: i.sku, unit_type: i.unit_type
            }));
            console.log("[v0] Cart items being sent:", JSON.stringify(items, null, 2));
            const subtotal = cart.reduce((s, i) => s + i.price * i.quantity, 0);
            const discountAmount = getAppliedDiscountAmount(subtotal);
            const totalAmount = Math.max(0, subtotal - discountAmount);
            
            if (!navigator.onLine) {
                saveOrderOffline(custId, { text: customerName, dataset: { email: customerEmail, phone: customerPhone, address: customerAddress } }, items, subtotal, totalAmount);
                return;
            }

            const todayDate = new Date();
            const y = todayDate.getFullYear();
            const m = String(todayDate.getMonth() + 1).padStart(2, '0');
            const d = String(todayDate.getDate()).padStart(2, '0');
            const soPrefix = `SO-${y}${m}${d}-`;

            const submitWithSoSuffix = (manualSoSuffix) => {
                if (!manualSoSuffix) return;
            const btn = document.getElementById('confirmOrderBtn');
            const orig = btn.innerHTML;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Processing...';
            btn.disabled = true;
            
            captureLocation().then(locationData => {
                const postData = {
                    action: 'submit_order',
                    so_number_mode: 'manual',
                    manual_so_last: manualSoSuffix,
                    customer_id: custId,
                    customer_name: customerName,
                    email: customerEmail,
                    phone: customerPhone,
                    address: customerAddress,
                    items: JSON.stringify(items),
                    discount_percent: currentDiscountPercent,
                    discount_calculation_type: currentDiscountType,
                    discount_based_amount: currentDiscountType === 'amount_based' ? currentDiscountFixedAmount : 0,
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
                                    const unitType = item.unit_type || product.default_unit_type_name || product.unit_type || 'Piece';
                                    if (!product.unit_stocks) product.unit_stocks = {};
                                    product.unit_stocks[unitType] = parseFloat(item.new_stock || 0);
                                    if (unitType === (product.default_unit_type_name || product.unit_type || 'Piece')) {
                                        product.default_stock = parseFloat(item.new_stock || 0);
                                        product.stock_in_default_uom = product.default_stock;
                                        product.raw_stock = product.default_stock;
                                        const defaultMultiplier = Math.max(1, parseInt(product.default_unit_multiplier || 1));
                                        product.stock_smallest = product.default_stock * defaultMultiplier;
                                    }
                                }
                            });
                        }
                        cart = [];
                        updateCartBadge();
                        bootstrap.Modal.getInstance(document.getElementById('cartModal'))?.hide();
                        
                        // FIXED: Trigger inventory table reload in other window/tab
                        if (window.opener || window.parent !== window) {
                            try {
                                // Broadcast to other tabs/windows
                                const channel = new BroadcastChannel('inventory_update');
                                channel.postMessage({ action: 'reload_inventory', items: data.updated_stock });
                                channel.close();
                            } catch (e) {
                                // BroadcastChannel not supported, try localStorage
                                localStorage.setItem('inventory_update_timestamp', Date.now().toString());
                            }
                        } else {
                            // Try localStorage fallback
                            localStorage.setItem('inventory_update_timestamp', Date.now().toString());
                        }
                        
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
                            viewOrderFromOrderProduct(data.so_id);
                        },
                        onCancel: () => {
                            if (select) select.value = '';
                            resetCurrentDiscount();
                            // Clear cart
                            cart = [];
                            updateCartBadge();
                            // FIXED: Re-render with updated stock after dialog closes
                            requestAnimationFrame(() => {
                                renderProducts();
                                filterProducts();
                            });
                        }
                    });
                        resetCurrentDiscount();
                        // FIXED: Ensure stock display updates immediately after order success
                        // Use requestAnimationFrame to ensure DOM is ready, then re-render products with updated stock
                        requestAnimationFrame(() => {
                            renderProducts();
                        });
                    } else {
                        showSweetAlert({
                            icon: 'warning',
                            title: 'Error',
                            text: data.message || 'Failed to submit order. Please try again.',
                            confirmText: 'OK'
                        });
                    }
=======
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
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
<<<<<<< HEAD
            });
            };

            showSONumberForm(soPrefix, (manualSoSuffix) => submitWithSoSuffix(manualSoSuffix));
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
                discount_calculation_type: currentDiscountType || 'percentage',
                discount_based_amount: currentDiscountType === 'amount_based' ? (currentDiscountFixedAmount || 0) : 0,
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
                            formData.append('discount_percent', order.discount_percent || 0);
                            formData.append('discount_calculation_type', order.discount_calculation_type || 'percentage');
                            formData.append('discount_based_amount', order.discount_based_amount || 0);
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
        // Function to render order history as cards with clean layout
function renderOrderHistoryAsCards(orders) {
    const container = document.getElementById('modalOrderHistory');
    if (!orders || orders.length === 0) {
        container.innerHTML = '<div class="text-center py-4 text-muted"><i class="bi bi-inbox fs-1 d-block mb-2"></i><span>No order history</span></div>';
        return;
    }
    
    let html = '<div class="order-history-cards">';
    orders.forEach(o => {
        let statusClass = '';
        let statusText = '';
        if (o.order_status === 'pending') {
            statusClass = 'history-status-pending';
            statusText = 'Pending';
        } else if (o.order_status === 'completed' || o.order_status === 'delivered') {
            statusClass = 'history-status-completed';
            statusText = 'Completed';
        } else if (o.order_status === 'cancelled') {
            statusClass = 'history-status-cancelled';
            statusText = 'Cancelled';
        } else {
            statusClass = 'history-status-pending';
            statusText = o.order_status || 'Pending';
        }
        
        const orderDate = new Date(o.order_date).toLocaleDateString();
        const totalAmount = parseFloat(o.total_price) || 0;
        
        html += `
            <div class="history-card">
                <div class="history-row-1">
                    <span class="history-date"> ${orderDate}</span>
                    <span class="history-status ${statusClass}">${statusText}</span>
                </div>
                <div class="history-row-2">
                    <span class="history-so-number"> ${escapeHtml(o.so_number)}</span>
                </div>
                <div class="history-row-3">
                    <span class="history-product-name"> ${escapeHtml(o.customer_name)}</span>
                </div>
                <div class="history-row-4">
                    <span class="history-unit-qty">${escapeHtml(o.unit_type)} / <strong>${o.quantity_ordered}</strong> pcs</span>
                    <span class="history-amount">₱${parseFloat(o.total_price).toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</span>
                </div>
            </div>
        `;
    });
    html += '</div>';
    container.innerHTML = html;
}
document.addEventListener('DOMContentLoaded', function () {
    const customerName = "<?php echo addslashes($pre_selected_customer_name); ?>";

    if (customerName) {
        const display = document.getElementById('selectedCustomerName');
        if (display) {
            display.textContent = customerName;
        }
    }
});
=======
            .catch(e => {
                console.error('Submit error:', e);
                showToast('Error: ' + e.message);
                btn.innerHTML = orig;
                btn.disabled = false;
=======
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
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
<<<<<<< HEAD
        };
>>>>>>> 1bcf3b66714c50eace882b1c946948f48fb2be54
=======
            
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
>>>>>>> 97aee82aa9dc5d65ae46ea5072f4ceb2156ef928
    </script>
</body>
</html>
