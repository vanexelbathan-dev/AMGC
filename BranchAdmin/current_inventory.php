<?php
// Enable error reporting for debugging (remove in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../config/database.php';
require_once '../config/session_handler.php';

// Check database connection
if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error());
}

// Get current user info and branch context
$user_id = $_SESSION['user_id'] ?? 0;
$user_name = isset($_SESSION['first_name']) ? $_SESSION['first_name'] . ' ' . $_SESSION['last_name'] : 'Branch Admin';
$user_role = isset($_SESSION['role']) ? $_SESSION['role'] : 'branch_admin';
$branch_id = $_SESSION['branch_id'] ?? 0;
$view_all_branches = $_SESSION['view_all_branches'] ?? false;

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

// ========== HELPER FUNCTIONS FOR UNIT CONVERSION ==========
/**
 * Get default UOM info for an item (using per-item default_unit_type_id)
 */
function getItemDefaultUOMInfo($conn, $item_id) {
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
        SELECT ut.unit_type_name, ut.quantity_smallest_pack as multiplier, ut.unit_type_id
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
 * Get multiplier for a specific unit type
 */
function getUnitTypeMultiplier($conn, $unit_type_name) {
    $query = "
        SELECT COALESCE(quantity_smallest_pack, 1) as multiplier
        FROM unit_types
        WHERE unit_type_name = ? AND status = 'active'
        LIMIT 1
    ";
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        return 1;
    }
    $stmt->bind_param('s', $unit_type_name);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    
    return (int)($row['multiplier'] ?? 1);
}

// Check if branch_id column exists in items table
$items_branch_column_exists = false;
$check_column = $conn->query("SHOW COLUMNS FROM items LIKE 'branch_id'");
if ($check_column && $check_column->num_rows > 0) {
    $items_branch_column_exists = true;
}

// Check if created_by column exists in items table
$created_by_column_exists = false;
$check_created_by = $conn->query("SHOW COLUMNS FROM items LIKE 'created_by'");
if ($check_created_by && $check_created_by->num_rows > 0) {
    $created_by_column_exists = true;
} else {
    $add_created_by = "ALTER TABLE items ADD COLUMN created_by int(11) DEFAULT NULL AFTER created_at";
    $conn->query($add_created_by);
    $created_by_column_exists = true;
}

// Add default_unit_type_id column if not exists
$check_default_unit = $conn->query("SHOW COLUMNS FROM items LIKE 'default_unit_type_id'");
if (!$check_default_unit || $check_default_unit->num_rows == 0) {
    $add_default_unit = "ALTER TABLE items ADD COLUMN default_unit_type_id INT(11) DEFAULT NULL AFTER unit_type";
    $conn->query($add_default_unit);
}

// Check if branch_id column exists in suppliers table
$suppliers_branch_column_exists = false;
$check_suppliers_branch = $conn->query("SHOW COLUMNS FROM suppliers LIKE 'branch_id'");
if ($check_suppliers_branch && $check_suppliers_branch->num_rows > 0) {
    $suppliers_branch_column_exists = true;
}

// Determine branch filter condition for items
$items_branch_condition = "";
if ($items_branch_column_exists && !$view_all_branches && $branch_id > 0) {
    $items_branch_condition = "AND i.branch_id = " . intval($branch_id);
}

// Determine branch filter condition for suppliers
$suppliers_branch_condition = "";
if ($suppliers_branch_column_exists && !$view_all_branches && $branch_id > 0) {
    $suppliers_branch_condition = "AND branch_id = " . intval($branch_id);
}

// Fetch all available price levels from database (filtered by branch)
$priceLevels = [];
$priceLevelQuery = "SELECT DISTINCT iup.price_level 
                   FROM item_unit_pricing iup
                   JOIN items i ON iup.item_id = i.item_id
                   WHERE i.status = 'active'";
if ($items_branch_column_exists && !$view_all_branches && $branch_id > 0) {
    $priceLevelQuery .= " AND i.branch_id = " . intval($branch_id);
}
$priceLevelQuery .= " ORDER BY iup.price_level ASC";

$priceLevelResult = $conn->query($priceLevelQuery);
if ($priceLevelResult) {
    while ($row = $priceLevelResult->fetch_assoc()) {
        if (!empty($row['price_level'])) {
            $priceLevels[] = $row['price_level'];
        }
    }
} else {
    error_log("Price level query failed: " . $conn->error);
}

if (empty($priceLevels) || !in_array('Standard', $priceLevels)) {
    array_unshift($priceLevels, 'Standard');
}

error_log("Price levels found: " . json_encode($priceLevels));

// ========== CREATE/ALTER TABLES IF NEEDED ==========

$create_unit_types = "CREATE TABLE IF NOT EXISTS `unit_types` (
    `unit_type_id` int(11) NOT NULL AUTO_INCREMENT,
    `unit_type_name` varchar(100) NOT NULL,
    `uom_initial` varchar(20) DEFAULT NULL,
    `barcode` varchar(100) DEFAULT NULL,
    `quantity_smallest_pack` int(11) DEFAULT 1,
    `is_default_uom` tinyint(4) DEFAULT 0,
    `description` text DEFAULT NULL,
    `multiplier` decimal(10,2) DEFAULT 1.00,
    `branch_id` int(11) DEFAULT NULL,
    `status` enum('active','inactive') DEFAULT 'active',
    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
    `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`unit_type_id`),
    UNIQUE KEY `unit_type_branch` (`unit_type_name`, `branch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
$conn->query($create_unit_types);

$check_uom_initial_column = $conn->query("SHOW COLUMNS FROM unit_types LIKE 'uom_initial'");
if (!$check_uom_initial_column || $check_uom_initial_column->num_rows == 0) {
    $conn->query("ALTER TABLE unit_types ADD COLUMN uom_initial VARCHAR(20) DEFAULT NULL AFTER unit_type_name");
}

$check_pricing_table = $conn->query("SHOW TABLES LIKE 'item_unit_pricing'");
if ($check_pricing_table && $check_pricing_table->num_rows == 0) {
    $create_pricing = "CREATE TABLE IF NOT EXISTS `item_unit_pricing` (
        `pricing_id` int(11) NOT NULL AUTO_INCREMENT,
        `item_id` int(11) NOT NULL,
        `unit_type_id` int(11) NOT NULL,
        `unit_price` decimal(10,2) NOT NULL,
        `unit_quantity` int(11) DEFAULT 1,
        `effective_date` date DEFAULT NULL,
        `price_level` varchar(50) DEFAULT 'Standard',
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (`pricing_id`),
        UNIQUE KEY `item_unit_price_level_unique` (`item_id`, `unit_type_id`, `price_level`),
        KEY `idx_effective_date` (`effective_date`),
        KEY `idx_price_level` (`price_level`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    $conn->query($create_pricing);
} else {
    $check_effective_date = $conn->query("SHOW COLUMNS FROM item_unit_pricing LIKE 'effective_date'");
    if (!$check_effective_date || $check_effective_date->num_rows == 0) {
        $add_effective_date = "ALTER TABLE item_unit_pricing ADD COLUMN effective_date date DEFAULT NULL AFTER unit_quantity";
        $conn->query($add_effective_date);
    }
    
    $check_price_level = $conn->query("SHOW COLUMNS FROM item_unit_pricing LIKE 'price_level'");
    if (!$check_price_level || $check_price_level->num_rows == 0) {
        $add_price_level = "ALTER TABLE item_unit_pricing ADD COLUMN price_level varchar(50) DEFAULT 'Standard' AFTER effective_date";
        $conn->query($add_price_level);
    }
}

$create_item_unit_pricing_schedule = "CREATE TABLE IF NOT EXISTS `item_unit_pricing_schedule` (
    `schedule_id` int(11) NOT NULL AUTO_INCREMENT,
    `item_id` int(11) NOT NULL,
    `unit_type_id` int(11) NOT NULL,
    `price_level` varchar(50) NOT NULL DEFAULT 'Standard',
    `unit_price` decimal(10,2) NOT NULL,
    `unit_quantity` int(11) DEFAULT 1,
    `effective_date` date NOT NULL,
    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
    `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`schedule_id`),
    UNIQUE KEY `item_unit_price_schedule_unique` (`item_id`, `unit_type_id`, `price_level`, `effective_date`),
    KEY `idx_schedule_effective_date` (`effective_date`),
    KEY `idx_schedule_price_level` (`price_level`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
$conn->query($create_item_unit_pricing_schedule);

$create_item_unit_pricing_history = "CREATE TABLE IF NOT EXISTS `item_unit_pricing_history` (
    `history_id` int(11) NOT NULL AUTO_INCREMENT,
    `item_id` int(11) NOT NULL,
    `unit_type_id` int(11) NOT NULL,
    `price_level` varchar(50) NOT NULL DEFAULT 'Standard',
    `unit_price` decimal(10,2) NOT NULL,
    `unit_quantity` int(11) DEFAULT 1,
    `effective_date` date DEFAULT NULL,
    `history_type` varchar(30) NOT NULL DEFAULT 'previous',
    `created_by` int(11) DEFAULT NULL,
    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`history_id`),
    KEY `idx_price_history_item` (`item_id`),
    KEY `idx_price_history_item_unit_level` (`item_id`, `unit_type_id`, `price_level`),
    KEY `idx_price_history_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
$conn->query($create_item_unit_pricing_history);

$create_item_unit_inventory = "CREATE TABLE IF NOT EXISTS `item_unit_inventory` (
    `inventory_id` int(11) NOT NULL AUTO_INCREMENT,
    `item_id` int(11) NOT NULL,
    `unit_type_id` int(11) NOT NULL,
    `current_inventory` decimal(12,2) NOT NULL DEFAULT 0.00,
    `as_of_date` date DEFAULT NULL,
    `unit_cost` decimal(12,2) NOT NULL DEFAULT 0.00,
    `total_cost` decimal(14,2) NOT NULL DEFAULT 0.00,
    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
    `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`inventory_id`),
    UNIQUE KEY `item_unit_inventory_unique` (`item_id`, `unit_type_id`),
    KEY `idx_item_unit_inventory_item` (`item_id`),
    KEY `idx_item_unit_inventory_unit` (`unit_type_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
$conn->query($create_item_unit_inventory);


// ========== DEDUPLICATION / STRICT UPSERT HELPERS ==========
// These helpers prevent duplicate UoM/pricing rows when updating from Excel export/import
// or from the Edit Item modal. They do not change the UI or color palette.
function amgcColumnExists($conn, $table, $column) {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $column = preg_replace('/[^a-zA-Z0-9_]/', '', $column);
    $result = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    return ($result && $result->num_rows > 0);
}

function amgcIndexExists($conn, $table, $index_name) {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $index_name_escaped = $conn->real_escape_string($index_name);
    $result = $conn->query("SHOW INDEX FROM `$table` WHERE Key_name = '$index_name_escaped'");
    return ($result && $result->num_rows > 0);
}

function amgcDeleteDuplicatePricingRows($conn) {
    $check_table = $conn->query("SHOW TABLES LIKE 'item_unit_pricing'");
    if (!$check_table || $check_table->num_rows == 0) return;

    $conn->query("CREATE TEMPORARY TABLE IF NOT EXISTS tmp_keep_item_unit_pricing AS
        SELECT MAX(pricing_id) AS keep_id
        FROM item_unit_pricing
        GROUP BY item_id, unit_type_id, price_level");
    $conn->query("DELETE iup FROM item_unit_pricing iup
        LEFT JOIN tmp_keep_item_unit_pricing k ON k.keep_id = iup.pricing_id
        WHERE k.keep_id IS NULL");
    $conn->query("DROP TEMPORARY TABLE IF EXISTS tmp_keep_item_unit_pricing");
}

function amgcDeleteDuplicateScheduleRows($conn) {
    $check_table = $conn->query("SHOW TABLES LIKE 'item_unit_pricing_schedule'");
    if (!$check_table || $check_table->num_rows == 0) return;

    $conn->query("CREATE TEMPORARY TABLE IF NOT EXISTS tmp_keep_item_unit_schedule AS
        SELECT MAX(schedule_id) AS keep_id
        FROM item_unit_pricing_schedule
        GROUP BY item_id, unit_type_id, price_level, effective_date");
    $conn->query("DELETE sch FROM item_unit_pricing_schedule sch
        LEFT JOIN tmp_keep_item_unit_schedule k ON k.keep_id = sch.schedule_id
        WHERE k.keep_id IS NULL");
    $conn->query("DROP TEMPORARY TABLE IF EXISTS tmp_keep_item_unit_schedule");
}

function amgcDeleteDuplicateInventoryRows($conn) {
    $check_table = $conn->query("SHOW TABLES LIKE 'item_unit_inventory'");
    if (!$check_table || $check_table->num_rows == 0) return;

    $conn->query("CREATE TEMPORARY TABLE IF NOT EXISTS tmp_keep_item_unit_inventory AS
        SELECT MAX(inventory_id) AS keep_id
        FROM item_unit_inventory
        GROUP BY item_id, unit_type_id");
    $conn->query("DELETE inv FROM item_unit_inventory inv
        LEFT JOIN tmp_keep_item_unit_inventory k ON k.keep_id = inv.inventory_id
        WHERE k.keep_id IS NULL");
    $conn->query("DROP TEMPORARY TABLE IF EXISTS tmp_keep_item_unit_inventory");
}

function amgcEnsureNoDuplicateItemUnitTables($conn) {
    amgcDeleteDuplicatePricingRows($conn);
    amgcDeleteDuplicateScheduleRows($conn);
    amgcDeleteDuplicateInventoryRows($conn);

    if (!amgcIndexExists($conn, 'item_unit_pricing', 'item_unit_price_level_unique')) {
        @$conn->query("ALTER TABLE item_unit_pricing ADD UNIQUE KEY item_unit_price_level_unique (item_id, unit_type_id, price_level)");
    }
    if (!amgcIndexExists($conn, 'item_unit_pricing_schedule', 'item_unit_price_schedule_unique')) {
        @$conn->query("ALTER TABLE item_unit_pricing_schedule ADD UNIQUE KEY item_unit_price_schedule_unique (item_id, unit_type_id, price_level, effective_date)");
    }
    if (!amgcIndexExists($conn, 'item_unit_inventory', 'item_unit_inventory_unique')) {
        @$conn->query("ALTER TABLE item_unit_inventory ADD UNIQUE KEY item_unit_inventory_unique (item_id, unit_type_id)");
    }
}

function amgcUpsertItemUnitPricingStrict($conn, $item_id, $unit_type_id, $unit_price, $unit_quantity, $effective_date, $price_level) {
    $item_id = (int)$item_id;
    $unit_type_id = (int)$unit_type_id;
    $unit_price = is_numeric($unit_price) ? (float)$unit_price : 0;
    $unit_quantity = max(1, (int)$unit_quantity);
    $effective_date = !empty($effective_date) ? $effective_date : null;
    $price_level = trim((string)$price_level) !== '' ? trim((string)$price_level) : 'Standard';

    $check_stmt = $conn->prepare("SELECT pricing_id FROM item_unit_pricing WHERE item_id = ? AND unit_type_id = ? AND price_level = ? ORDER BY pricing_id DESC");
    if (!$check_stmt) throw new Exception('Database prepare error while checking unit pricing');
    $check_stmt->bind_param('iis', $item_id, $unit_type_id, $price_level);
    $check_stmt->execute();
    $result = $check_stmt->get_result();
    $pricing_ids = [];
    while ($row = $result->fetch_assoc()) {
        $pricing_ids[] = (int)$row['pricing_id'];
    }
    $check_stmt->close();

    if (!empty($pricing_ids)) {
        $keep_id = $pricing_ids[0];
        $update_stmt = $conn->prepare("UPDATE item_unit_pricing SET unit_price = ?, unit_quantity = ?, effective_date = ?, updated_at = NOW() WHERE pricing_id = ?");
        if (!$update_stmt) throw new Exception('Database prepare error while updating unit pricing');
        $update_stmt->bind_param('disi', $unit_price, $unit_quantity, $effective_date, $keep_id);
        if (!$update_stmt->execute()) throw new Exception('Failed to update unit pricing: ' . $update_stmt->error);
        $update_stmt->close();

        if (count($pricing_ids) > 1) {
            $delete_ids = array_slice($pricing_ids, 1);
            $placeholders = implode(',', array_fill(0, count($delete_ids), '?'));
            $types = str_repeat('i', count($delete_ids));
            $delete_stmt = $conn->prepare("DELETE FROM item_unit_pricing WHERE pricing_id IN ($placeholders)");
            if ($delete_stmt) {
                $delete_stmt->bind_param($types, ...$delete_ids);
                $delete_stmt->execute();
                $delete_stmt->close();
            }
        }
        return $keep_id;
    }

    $insert_stmt = $conn->prepare("INSERT INTO item_unit_pricing (item_id, unit_type_id, unit_price, unit_quantity, effective_date, price_level) VALUES (?, ?, ?, ?, ?, ?)");
    if (!$insert_stmt) throw new Exception('Database prepare error while inserting unit pricing');
    $insert_stmt->bind_param('iidiss', $item_id, $unit_type_id, $unit_price, $unit_quantity, $effective_date, $price_level);
    if (!$insert_stmt->execute()) throw new Exception('Failed to insert unit pricing: ' . $insert_stmt->error);
    $new_id = (int)$conn->insert_id;
    $insert_stmt->close();
    return $new_id;
}

function amgcUpsertItemUnitScheduleStrict($conn, $item_id, $unit_type_id, $price_level, $unit_price, $unit_quantity, $effective_date) {
    $item_id = (int)$item_id;
    $unit_type_id = (int)$unit_type_id;
    $price_level = trim((string)$price_level) !== '' ? trim((string)$price_level) : 'Standard';
    $unit_price = is_numeric($unit_price) ? (float)$unit_price : 0;
    $unit_quantity = max(1, (int)$unit_quantity);

    $check_stmt = $conn->prepare("SELECT schedule_id FROM item_unit_pricing_schedule WHERE item_id = ? AND unit_type_id = ? AND price_level = ? AND effective_date = ? ORDER BY schedule_id DESC");
    if (!$check_stmt) throw new Exception('Database prepare error while checking scheduled pricing');
    $check_stmt->bind_param('iiss', $item_id, $unit_type_id, $price_level, $effective_date);
    $check_stmt->execute();
    $result = $check_stmt->get_result();
    $schedule_ids = [];
    while ($row = $result->fetch_assoc()) {
        $schedule_ids[] = (int)$row['schedule_id'];
    }
    $check_stmt->close();

    if (!empty($schedule_ids)) {
        $keep_id = $schedule_ids[0];
        $update_stmt = $conn->prepare("UPDATE item_unit_pricing_schedule SET unit_price = ?, unit_quantity = ?, updated_at = NOW() WHERE schedule_id = ?");
        if (!$update_stmt) throw new Exception('Database prepare error while updating scheduled pricing');
        $update_stmt->bind_param('dii', $unit_price, $unit_quantity, $keep_id);
        if (!$update_stmt->execute()) throw new Exception('Failed to update scheduled pricing: ' . $update_stmt->error);
        $update_stmt->close();

        if (count($schedule_ids) > 1) {
            $delete_ids = array_slice($schedule_ids, 1);
            $placeholders = implode(',', array_fill(0, count($delete_ids), '?'));
            $types = str_repeat('i', count($delete_ids));
            $delete_stmt = $conn->prepare("DELETE FROM item_unit_pricing_schedule WHERE schedule_id IN ($placeholders)");
            if ($delete_stmt) {
                $delete_stmt->bind_param($types, ...$delete_ids);
                $delete_stmt->execute();
                $delete_stmt->close();
            }
        }
        return $keep_id;
    }

    $insert_stmt = $conn->prepare("INSERT INTO item_unit_pricing_schedule (item_id, unit_type_id, price_level, unit_price, unit_quantity, effective_date) VALUES (?, ?, ?, ?, ?, ?)");
    if (!$insert_stmt) throw new Exception('Database prepare error while inserting scheduled pricing');
    $insert_stmt->bind_param('iisdis', $item_id, $unit_type_id, $price_level, $unit_price, $unit_quantity, $effective_date);
    if (!$insert_stmt->execute()) throw new Exception('Failed to insert scheduled pricing: ' . $insert_stmt->error);
    $new_id = (int)$conn->insert_id;
    $insert_stmt->close();
    return $new_id;
}

amgcEnsureNoDuplicateItemUnitTables($conn);

$check_beginning_inventory_column = $conn->query("SHOW COLUMNS FROM item_unit_inventory LIKE 'beginning_inventory'");
if (!$check_beginning_inventory_column || $check_beginning_inventory_column->num_rows == 0) {
    $conn->query("ALTER TABLE item_unit_inventory ADD COLUMN beginning_inventory decimal(12,2) NOT NULL DEFAULT 0.00 AFTER current_inventory");
}
$conn->query("UPDATE item_unit_inventory SET beginning_inventory = current_inventory WHERE beginning_inventory IS NULL OR beginning_inventory = 0");
$check_total_cost_column = $conn->query("SHOW COLUMNS FROM item_unit_inventory LIKE 'total_cost'");
if (!$check_total_cost_column || $check_total_cost_column->num_rows == 0) {
    $conn->query("ALTER TABLE item_unit_inventory ADD COLUMN total_cost decimal(14,2) NOT NULL DEFAULT 0.00 AFTER unit_cost");
    $conn->query("UPDATE item_unit_inventory SET total_cost = COALESCE(current_inventory, 0) * COALESCE(unit_cost, 0) WHERE total_cost IS NULL OR total_cost = 0");
}


$create_item_images = "CREATE TABLE IF NOT EXISTS `item_images` (
    `image_id` int(11) NOT NULL AUTO_INCREMENT,
    `item_id` int(11) NOT NULL,
    `image_path` varchar(255) NOT NULL,
    `image_order` int(11) DEFAULT 0,
    `is_primary` tinyint(1) DEFAULT 0,
    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`image_id`),
    KEY `idx_item_id` (`item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
$conn->query($create_item_images);

$check_product_image_column = $conn->query("SHOW COLUMNS FROM items LIKE 'product_image_url'");
if (!$check_product_image_column || $check_product_image_column->num_rows == 0) {
    $add_product_image_column = "ALTER TABLE items ADD COLUMN product_image_url VARCHAR(255) DEFAULT NULL AFTER item_name";
    $conn->query($add_product_image_column);
}

// Principal field for inventory items (optional). Blank values are displayed as "No Principal".
$check_principal_column = $conn->query("SHOW COLUMNS FROM items LIKE 'principal'");
if (!$check_principal_column || $check_principal_column->num_rows == 0) {
    $conn->query("ALTER TABLE items ADD COLUMN principal VARCHAR(150) DEFAULT NULL AFTER category");
}

// Volume field for Oil items
$check_volume_column = $conn->query("SHOW COLUMNS FROM items LIKE 'volume'");
if (!$check_volume_column || $check_volume_column->num_rows == 0) {
    $conn->query("ALTER TABLE items ADD COLUMN volume VARCHAR(100) DEFAULT NULL AFTER principal");
}

// Oil Type field for Oil items (Palm or Coconut)
$check_oil_type_column = $conn->query("SHOW COLUMNS FROM items LIKE 'oil_type'");
if (!$check_oil_type_column || $check_oil_type_column->num_rows == 0) {
    $conn->query("ALTER TABLE items ADD COLUMN oil_type VARCHAR(50) DEFAULT NULL AFTER volume");
}


// Barcode field for inventory items. This is where generated/manual UoM barcode is saved.
$check_item_barcode_column = $conn->query("SHOW COLUMNS FROM items LIKE 'barcode'");
if (!$check_item_barcode_column || $check_item_barcode_column->num_rows == 0) {
    $conn->query("ALTER TABLE items ADD COLUMN barcode VARCHAR(100) DEFAULT NULL AFTER item_code");
}

// ========== CALCULATE AVERAGE DAILY OFFTAKE ==========
// Calculate average daily offtake based on items currently in inventory
// This should be the average daily quantity used/sold of items that are currently stocked
$avg_offtake_query = "
    SELECT 
        COALESCE(SUM(soi.quantity_ordered), 0) as total_quantity_30d,
        COUNT(DISTINCT DATE(so.created_at)) as active_days
    FROM sales_order_items soi
    JOIN sales_orders so ON soi.so_id = so.so_id
    WHERE so.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    AND so.order_status IN ('delivered', 'confirmed', 'processing', 'ready')
    AND soi.item_id IN (
        SELECT DISTINCT i.item_id FROM items i
        LEFT JOIN item_unit_inventory inv ON inv.item_id = i.item_id AND inv.unit_type_id = i.default_unit_type_id
        WHERE COALESCE(inv.current_inventory, 0) > 0
";

if ($items_branch_column_exists && !$view_all_branches && $branch_id > 0) {
    $avg_offtake_query .= " AND i.branch_id = " . intval($branch_id);
}

$avg_offtake_query .= ")";

if ($items_branch_column_exists && !$view_all_branches && $branch_id > 0) {
    $avg_offtake_query .= " AND so.branch_id = " . intval($branch_id);
}

$avg_offtake_result = $conn->query($avg_offtake_query);
if (!$avg_offtake_result) {
    error_log("Offtake Query Error: " . $conn->error);
    $total_quantity_30d = 0;
    $active_days = 0;
} else {
    $offtake_data = $avg_offtake_result->fetch_assoc();
    $total_quantity_30d = $offtake_data['total_quantity_30d'] ?? 0;
    $active_days = $offtake_data['active_days'] ?? 0;
}

$avg_daily_offtake = $active_days > 0 ? round($total_quantity_30d / $active_days, 1) : 0;

// ========== GET BASE64 ENCODED LOGO FOR PRINTING ==========
$logo_path = '../Pictures/amgc3DLogo.png';
$logo_base64 = '';
if (file_exists($logo_path)) {
    $image_data = file_get_contents($logo_path);
    $logo_base64 = 'data:image/png;base64,' . base64_encode($image_data);
}

function saveItemUnitPricingHistory($conn, $item_id, $unit_type_id, $price_level, $unit_price, $unit_quantity, $effective_date, $history_type = 'previous', $created_by = null) {
    $history_check = $conn->query("SHOW TABLES LIKE 'item_unit_pricing_history'");
    if (!$history_check || $history_check->num_rows == 0) {
        return;
    }

    $price_level = $price_level ?: 'Standard';
    $unit_quantity = max(1, (int)$unit_quantity);
    $effective_date = !empty($effective_date) ? $effective_date : null;
    $created_by = $created_by ? (int)$created_by : null;

    $history_query = "INSERT INTO item_unit_pricing_history (item_id, unit_type_id, price_level, unit_price, unit_quantity, effective_date, history_type, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
    $history_stmt = $conn->prepare($history_query);
    if (!$history_stmt) {
        return;
    }
    $history_stmt->bind_param('iisdissi', $item_id, $unit_type_id, $price_level, $unit_price, $unit_quantity, $effective_date, $history_type, $created_by);
    $history_stmt->execute();
    $history_stmt->close();
}
function syncItemPriceSummaryFromPricing($conn, $item_id) {
    $price_query = "SELECT 
            i.default_unit_type_id,
            COALESCE(iup.unit_price, 0) as unit_price
        FROM items i
        LEFT JOIN item_unit_pricing iup 
            ON iup.item_id = i.item_id 
            AND iup.unit_type_id = i.default_unit_type_id
            AND iup.price_level = 'Standard'
        WHERE i.item_id = ?
        LIMIT 1";
    $price_stmt = $conn->prepare($price_query);
    if (!$price_stmt) {
        return;
    }
    $price_stmt->bind_param('i', $item_id);
    $price_stmt->execute();
    $price_result = $price_stmt->get_result();
    $price_row = $price_result ? $price_result->fetch_assoc() : null;
    $price_stmt->close();

    if (!$price_row) {
        return;
    }

    $unit_price = isset($price_row['unit_price']) ? (float)$price_row['unit_price'] : 0;
    $price_case = $unit_price * 12;
    $price_inner_pack = $unit_price * 6;
    $price_box = $unit_price * 24;
    $price_carton = $unit_price * 48;

    $update_query = "UPDATE items
        SET unit_price = ?,
            price_case = ?,
            price_inner_pack = ?,
            price_box = ?,
            price_carton = ?,
            updated_at = NOW()
        WHERE item_id = ?";
    $update_stmt = $conn->prepare($update_query);
    if (!$update_stmt) {
        return;
    }
    $update_stmt->bind_param('dddddi', $unit_price, $price_case, $price_inner_pack, $price_box, $price_carton, $item_id);
    $update_stmt->execute();
    $update_stmt->close();
}

function applyDueScheduledPriceUpdates($conn) {
    $schedule_query = "SELECT schedule_id, item_id, unit_type_id, price_level, unit_price, unit_quantity, effective_date
        FROM item_unit_pricing_schedule
        WHERE effective_date <= CURDATE()
        ORDER BY effective_date ASC, schedule_id ASC";
    $schedule_result = $conn->query($schedule_query);
    if (!$schedule_result) {
        return;
    }

    $affected_items = [];

    while ($schedule = $schedule_result->fetch_assoc()) {
        $item_id = (int)$schedule['item_id'];
        $unit_type_id = (int)$schedule['unit_type_id'];
        $price_level = $schedule['price_level'] ?: 'Standard';
        $unit_price = (float)$schedule['unit_price'];
        $unit_quantity = (int)($schedule['unit_quantity'] ?? 1);
        $effective_date = $schedule['effective_date'];

        $check_query = "SELECT pricing_id FROM item_unit_pricing WHERE item_id = ? AND unit_type_id = ? AND price_level = ? LIMIT 1";
        $check_stmt = $conn->prepare($check_query);
        if (!$check_stmt) {
            continue;
        }
        $check_stmt->bind_param('iis', $item_id, $unit_type_id, $price_level);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        $pricing_row = $check_result ? $check_result->fetch_assoc() : null;
        $check_stmt->close();

        if ($pricing_row) {
            $update_query = "UPDATE item_unit_pricing
                SET unit_price = ?, unit_quantity = ?, effective_date = ?, updated_at = NOW()
                WHERE pricing_id = ?";
            $update_stmt = $conn->prepare($update_query);
            if ($update_stmt) {
                $pricing_id = (int)$pricing_row['pricing_id'];
                $update_stmt->bind_param('disi', $unit_price, $unit_quantity, $effective_date, $pricing_id);
                $update_stmt->execute();
                $update_stmt->close();
            }
        } else {
            $insert_query = "INSERT INTO item_unit_pricing (item_id, unit_type_id, unit_price, unit_quantity, effective_date, price_level)
                VALUES (?, ?, ?, ?, ?, ?)";
            $insert_stmt = $conn->prepare($insert_query);
            if ($insert_stmt) {
                $insert_stmt->bind_param('iidiss', $item_id, $unit_type_id, $unit_price, $unit_quantity, $effective_date, $price_level);
                $insert_stmt->execute();
                $insert_stmt->close();
            }
        }

        $delete_stmt = $conn->prepare("DELETE FROM item_unit_pricing_schedule WHERE schedule_id = ?");
        if ($delete_stmt) {
            $schedule_id = (int)$schedule['schedule_id'];
            $delete_stmt->bind_param('i', $schedule_id);
            $delete_stmt->execute();
            $delete_stmt->close();
        }

        $affected_items[$item_id] = true;
    }

    foreach (array_keys($affected_items) as $affected_item_id) {
        syncItemPriceSummaryFromPricing($conn, (int)$affected_item_id);
    }
}

applyDueScheduledPriceUpdates($conn);

function upsertItemUnitInventory($conn, $item_id, $unit_type_id, $current_inventory, $as_of_date, $unit_cost, $total_cost = null) {
    $current_inventory = is_numeric($current_inventory) ? (float)$current_inventory : 0;
    $unit_cost = is_numeric($unit_cost) ? (float)$unit_cost : 0;
    $as_of_date = !empty($as_of_date) ? $as_of_date : null;
    $beginning_inventory = $current_inventory;
    $total_cost = ($total_cost !== null && is_numeric($total_cost)) ? (float)$total_cost : ($current_inventory * $unit_cost);

    // For add/edit item, save the exact stock and total cost shown in the inventory form.
    // Receive Inventory uses its own additive update so different unit costs are accumulated correctly.
    $query = "INSERT INTO item_unit_inventory (item_id, unit_type_id, current_inventory, beginning_inventory, as_of_date, unit_cost, total_cost)
              VALUES (?, ?, ?, ?, ?, ?, ?)
              ON DUPLICATE KEY UPDATE
                  current_inventory = VALUES(current_inventory),
                  as_of_date = VALUES(as_of_date),
                  unit_cost = VALUES(unit_cost),
                  total_cost = VALUES(total_cost),
                  updated_at = NOW()";
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new Exception('Database prepare error while saving unit inventory');
    }
    $stmt->bind_param('iiddsdd', $item_id, $unit_type_id, $current_inventory, $beginning_inventory, $as_of_date, $unit_cost, $total_cost);
    if (!$stmt->execute()) {
        error_log("[v0] UPSERT ERROR - Item: $item_id, Unit: $unit_type_id, Qty: $current_inventory - Error: " . $stmt->error);
        throw new Exception('Failed to save unit inventory: ' . $stmt->error);
    }
    error_log("[v0] UPSERT SUCCESS - Item: $item_id, Unit: $unit_type_id, New Qty: $current_inventory");
}

function syncItemSummaryFromDefaultInventory($conn, $item_id) {
    $summary_query = "SELECT 
            i.default_unit_type_id,
            ut.unit_type_name,
            inv.current_inventory,
            CASE
                WHEN COALESCE(inv.current_inventory, 0) > 0 AND COALESCE(inv.total_cost, 0) > 0 THEN COALESCE(inv.total_cost, 0) / COALESCE(inv.current_inventory, 1)
                ELSE COALESCE(inv.unit_cost, 0)
            END AS unit_cost
        FROM items i
        LEFT JOIN unit_types ut ON i.default_unit_type_id = ut.unit_type_id
        LEFT JOIN item_unit_inventory inv ON inv.item_id = i.item_id AND inv.unit_type_id = i.default_unit_type_id
        WHERE i.item_id = ?
        LIMIT 1";
    $summary_stmt = $conn->prepare($summary_query);
    if (!$summary_stmt) {
        throw new Exception('Database prepare error while syncing item summary');
    }
    $summary_stmt->bind_param('i', $item_id);
    $summary_stmt->execute();
    $summary_result = $summary_stmt->get_result();
    $summary = $summary_result ? $summary_result->fetch_assoc() : null;

    $stock_value = 0;
    $unit_type_name = 'Piece';
    $unit_cost = 0;
    if ($summary) {
        $stock_value = isset($summary['current_inventory']) ? (float)$summary['current_inventory'] : 0;
        $unit_type_name = !empty($summary['unit_type_name']) ? $summary['unit_type_name'] : 'Piece';
        $unit_cost = isset($summary['unit_cost']) ? (float)$summary['unit_cost'] : 0;
    }

    $price_case = $unit_cost * 12;
    $price_inner_pack = $unit_cost * 6;
    $price_box = $unit_cost * 24;
    $price_carton = $unit_cost * 48;

    $update_query = "UPDATE items
        SET stock = ?,
            unit_type = ?,
            unit_price = ?,
            price_case = ?,
            price_inner_pack = ?,
            price_box = ?,
            price_carton = ?,
            updated_at = NOW()
        WHERE item_id = ?";
    $update_stmt = $conn->prepare($update_query);
    if (!$update_stmt) {
        throw new Exception('Database prepare error while updating item summary');
    }
    $update_stmt->bind_param('dsdddddi', $stock_value, $unit_type_name, $unit_cost, $price_case, $price_inner_pack, $price_box, $price_carton, $item_id);
    if (!$update_stmt->execute()) {
        throw new Exception('Failed to update item summary: ' . $update_stmt->error);
    }
}


/**
 * Recompute visible stock from the inventory ledger without changing the UI.
 * This fixes cases where the same item has stock split across PC/BUNDLE/DOZEN rows.
 * Rule used here: one item stock = max beginning stock + IN transactions - OUT transactions.
 * No unit conversion is applied when the ordered unit matches the inventory unit name.
 */
function amgcSyncItemStockFromLedger(mysqli $conn, int $item_id, int $branch_id = 0): void {
    // SAFE VERSION:
    // Do NOT recompute item_unit_inventory from ledger here.
    // Do NOT insert new item_unit_inventory rows here.
    // Do NOT zero other unit rows here.
    // This function only mirrors the existing item_unit_inventory current stock to items.stock.
    // Source of truth remains item_unit_inventory.
    if ($item_id <= 0) return;

    $hasInvBranch = amgcColumnExists($conn, 'item_unit_inventory', 'branch_id');
    $branchWhere = ($branch_id > 0 && $hasInvBranch) ? ' AND branch_id = ?' : '';

    $totalStock = 0.0;
    $stockSql = "SELECT COALESCE(SUM(current_inventory), 0) AS total_stock
                 FROM item_unit_inventory
                 WHERE item_id = ?
                   AND (status IS NULL OR status = '' OR status = 'active')
                   $branchWhere";
    if ($stmt = $conn->prepare($stockSql)) {
        if ($branchWhere !== '') {
            $stmt->bind_param('ii', $item_id, $branch_id);
        } else {
            $stmt->bind_param('i', $item_id);
        }
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $totalStock = (float)($row['total_stock'] ?? 0);
        $stmt->close();
    }

    if (amgcColumnExists($conn, 'items', 'stock')) {
        if ($stmt = $conn->prepare("UPDATE items SET stock = ?, updated_at = NOW() WHERE item_id = ?")) {
            $stmt->bind_param('di', $totalStock, $item_id);
            $stmt->execute();
            $stmt->close();
        }
    }
}

function amgcSyncAllBranchStocksFromLedger(mysqli $conn, int $branch_id = 0, bool $view_all_branches = false): void {
    if (!amgcColumnExists($conn, 'items', 'item_id')) return;
    $where = "WHERE i.status = 'active'";
    if (!$view_all_branches && $branch_id > 0 && amgcColumnExists($conn, 'items', 'branch_id')) {
        $where .= " AND i.branch_id = " . intval($branch_id);
    }
    $result = $conn->query("SELECT i.item_id FROM items i $where");
    if (!$result) return;
    while ($row = $result->fetch_assoc()) {
        amgcSyncItemStockFromLedger($conn, (int)$row['item_id'], (!$view_all_branches ? $branch_id : 0));
    }
}



// ========== SYNC RECEIVE INVENTORY / RETURNED MERCHANDISE TO PER-UOM INVENTORY ==========
// Current Inventory displays stocks from item_unit_inventory.
// Receive Inventory / Returned Merchandise can record stock in inventory_transactions,
// so this function applies those unprocessed incoming transactions into item_unit_inventory once only.
function syncReceivedInventoryTransactionsToUnitInventory($conn, $branch_id, $user_id, $items_branch_column_exists, $view_all_branches) {
    $check_trans_table = $conn->query("SHOW TABLES LIKE 'inventory_transactions'");
    if (!$check_trans_table || $check_trans_table->num_rows == 0) {
        return;
    }

    $check_unit_inv_table = $conn->query("SHOW TABLES LIKE 'item_unit_inventory'");
    if (!$check_unit_inv_table || $check_unit_inv_table->num_rows == 0) {
        return;
    }

    $conn->query("CREATE TABLE IF NOT EXISTS `item_unit_inventory_receive_sync_log` (
        `sync_id` int(11) NOT NULL AUTO_INCREMENT,
        `transaction_id` int(11) NOT NULL,
        `item_id` int(11) NOT NULL,
        `unit_type_id` int(11) NOT NULL,
        `quantity_added` decimal(12,2) NOT NULL DEFAULT 0.00,
        `reference_type` varchar(100) DEFAULT NULL,
        `reference_id` int(11) DEFAULT NULL,
        `synced_at` timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`sync_id`),
        UNIQUE KEY `unique_transaction_sync` (`transaction_id`),
        KEY `idx_item_unit_sync` (`item_id`, `unit_type_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    $trans_cols = [];
    $trans_cols_result = $conn->query("SHOW COLUMNS FROM inventory_transactions");
    if ($trans_cols_result) {
        while ($col = $trans_cols_result->fetch_assoc()) {
            $trans_cols[] = $col['Field'];
        }
    }

    if (!in_array('transaction_id', $trans_cols, true) || !in_array('item_id', $trans_cols, true)) {
        return;
    }

    $qty_col = null;
    foreach (['quantity_changed', 'quantity', 'qty'] as $candidate_col) {
        if (in_array($candidate_col, $trans_cols, true)) {
            $qty_col = $candidate_col;
            break;
        }
    }
    if (!$qty_col) {
        return;
    }

    $type_col = in_array('transaction_type', $trans_cols, true) ? 'transaction_type' : null;
    $ref_type_col = in_array('reference_type', $trans_cols, true) ? 'reference_type' : null;
    $ref_id_col = in_array('reference_id', $trans_cols, true) ? 'reference_id' : null;
    $trans_branch_col = in_array('branch_id', $trans_cols, true) ? 'branch_id' : null;
    $created_at_col = in_array('created_at', $trans_cols, true) ? 'created_at' : (in_array('transaction_date', $trans_cols, true) ? 'transaction_date' : null);

    $where = "WHERE COALESCE(it.`$qty_col`, 0) > 0";
    if ($type_col) {
        $where .= " AND LOWER(TRIM(it.`$type_col`)) IN ('in', 'receive', 'received')";
    }
    if ($ref_type_col) {
        $where .= " AND LOWER(TRIM(it.`$ref_type_col`)) IN ('purchase_order', 'production', 'rmr', 'return', 'return_merchandise', 'rejected_delivery')";
    }
    if ($trans_branch_col && !$view_all_branches && $branch_id > 0) {
        $where .= " AND it.`$trans_branch_col` = " . intval($branch_id);
    }

    $query = "SELECT
            it.transaction_id,
            it.item_id,
            it.`$qty_col` AS quantity_changed,
            " . ($ref_type_col ? "it.`$ref_type_col`" : "''") . " AS reference_type,
            " . ($ref_id_col ? "it.`$ref_id_col`" : "0") . " AS reference_id,
            " . ($trans_branch_col ? "it.`$trans_branch_col`" : "0") . " AS tx_branch_id,
            " . ($created_at_col ? "it.`$created_at_col`" : "NOW()") . " AS tx_created_at,
            i.default_unit_type_id,
            COALESCE(utd_sync.unit_type_name, 'Piece') AS unit_type,
            i.unit_price,
            " . (in_array('unit_cost', $trans_cols, true) ? "COALESCE(it.unit_cost, 0)" : "0") . " AS tx_unit_cost,
            " . (in_array('total_cost', $trans_cols, true) ? "COALESCE(it.total_cost, 0)" : "0") . " AS tx_total_cost,
            inv.current_inventory,
            inv.unit_cost,
            COALESCE(NULLIF(inv.total_cost, 0), COALESCE(inv.current_inventory, 0) * COALESCE(inv.unit_cost, 0)) AS existing_total_cost,
            log.transaction_id AS already_synced
        FROM inventory_transactions it
        JOIN items i ON i.item_id = it.item_id
        LEFT JOIN unit_types utd_sync ON utd_sync.unit_type_id = i.default_unit_type_id
        LEFT JOIN item_unit_inventory_receive_sync_log log ON log.transaction_id = it.transaction_id
        LEFT JOIN item_unit_inventory inv ON inv.item_id = i.item_id AND inv.unit_type_id = i.default_unit_type_id
        $where
        AND log.transaction_id IS NULL
        ORDER BY it.transaction_id ASC
        LIMIT 500";

    $result = $conn->query($query);
    if (!$result) {
        error_log('Receive inventory sync query failed: ' . $conn->error);
        return;
    }

    $sync_count = 0;
    while ($row = $result->fetch_assoc()) {
        $transaction_id = (int)($row['transaction_id'] ?? 0);
        $item_id = (int)($row['item_id'] ?? 0);
        $quantity_added = (float)($row['quantity_changed'] ?? 0);
        $unit_type_id = (int)($row['default_unit_type_id'] ?? 0);
        $reference_type = (string)($row['reference_type'] ?? '');
        $reference_id = (int)($row['reference_id'] ?? 0);
        $unit_cost = isset($row['tx_unit_cost']) ? (float)$row['tx_unit_cost'] : 0;
        $received_total_cost = isset($row['tx_total_cost']) ? (float)$row['tx_total_cost'] : 0;

        if ($transaction_id <= 0 || $item_id <= 0 || $quantity_added <= 0) {
            error_log("[v0] SYNC SKIP - Trans: $transaction_id, Item: $item_id, Qty: $quantity_added");
            continue;
        }
        
        $sync_count++;

        if ($unit_type_id <= 0) {
            $default_info = getItemDefaultUOMInfo($conn, $item_id);
            $unit_type_id = (int)($default_info['unit_type_id'] ?? 0);
        }

        if ($unit_type_id <= 0) {
            $fallback_unit_type = !empty($row['unit_type']) ? $row['unit_type'] : 'Piece';
            $find_unit_stmt = $conn->prepare("SELECT unit_type_id FROM unit_types WHERE unit_type_name = ? AND status = 'active' LIMIT 1");
            if ($find_unit_stmt) {
                $find_unit_stmt->bind_param('s', $fallback_unit_type);
                $find_unit_stmt->execute();
                $find_unit_result = $find_unit_stmt->get_result();
                if ($find_unit_row = $find_unit_result->fetch_assoc()) {
                    $unit_type_id = (int)$find_unit_row['unit_type_id'];
                }
                $find_unit_stmt->close();
            }
        }

        if ($unit_type_id <= 0) {
            continue;
        }

        if ($unit_cost <= 0 && isset($row['unit_price'])) {
            $unit_cost = (float)$row['unit_price'];
        }

        $current_inventory = isset($row['current_inventory']) ? (float)$row['current_inventory'] : 0;
        $existing_total_cost = isset($row['existing_total_cost']) ? (float)$row['existing_total_cost'] : 0;

        $check_current_stmt = $conn->prepare("SELECT current_inventory, unit_cost, COALESCE(NULLIF(total_cost, 0), COALESCE(current_inventory, 0) * COALESCE(unit_cost, 0)) AS total_cost FROM item_unit_inventory WHERE item_id = ? AND unit_type_id = ? LIMIT 1");
        if ($check_current_stmt) {
            $check_current_stmt->bind_param('ii', $item_id, $unit_type_id);
            $check_current_stmt->execute();
            $check_current_result = $check_current_stmt->get_result();
            if ($check_current_row = $check_current_result->fetch_assoc()) {
                $current_inventory = (float)($check_current_row['current_inventory'] ?? 0);
                $existing_unit_cost = (float)($check_current_row['unit_cost'] ?? 0);
                $existing_total_cost = (float)($check_current_row['total_cost'] ?? $existing_total_cost);
                if ($unit_cost <= 0) {
                    $unit_cost = $existing_unit_cost;
                }
            }
            $check_current_stmt->close();
        }

        if ($received_total_cost <= 0) {
            $received_total_cost = $quantity_added * $unit_cost;
        }
        $new_inventory = $current_inventory + $quantity_added;
        $new_total_cost = $existing_total_cost + $received_total_cost;
        $new_average_unit_cost = $new_inventory > 0 ? ($new_total_cost / $new_inventory) : $unit_cost;

        upsertItemUnitInventory($conn, $item_id, $unit_type_id, $new_inventory, date('Y-m-d'), $new_average_unit_cost, $new_total_cost);
        syncItemSummaryFromDefaultInventory($conn, $item_id);

        $log_stmt = $conn->prepare("INSERT IGNORE INTO item_unit_inventory_receive_sync_log (transaction_id, item_id, unit_type_id, quantity_added, reference_type, reference_id) VALUES (?, ?, ?, ?, ?, ?)");
        if ($log_stmt) {
            $log_stmt->bind_param('iiidsi', $transaction_id, $item_id, $unit_type_id, $quantity_added, $reference_type, $reference_id);
            $log_stmt->execute();
            $log_stmt->close();
        }
    }
    error_log("[v0] SYNC COMPLETE - Processed $sync_count transactions");
}

// ========== IMAGE UPLOAD HANDLER ==========
function handleMultipleImageUpload($files, $item_id) {
    $uploaded_files = [];
    $upload_dir = '../uploads/products/';
    
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    
    foreach ($files['tmp_name'] as $key => $tmp_name) {
        if ($files['error'][$key] === UPLOAD_ERR_OK) {
            $file_info = pathinfo($files['name'][$key]);
            $extension = strtolower($file_info['extension']);
            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            
            if (in_array($extension, $allowed) && $files['size'][$key] <= 5 * 1024 * 1024) {
                $filename = 'item_' . $item_id . '_' . time() . '_' . $key . '.' . $extension;
                $filepath = $upload_dir . $filename;
                
                if (move_uploaded_file($tmp_name, $filepath)) {
                    $uploaded_files[] = $filename;
                }
            }
        }
    }
    
    return $uploaded_files;
}

function generateNextItemCodeValue($conn, $branch_id = 0, $items_branch_column_exists = false, $view_all_branches = false) {
    $query = "SELECT item_code FROM items WHERE item_code LIKE 'ITEM%'";
    if ($items_branch_column_exists && !$view_all_branches && $branch_id > 0) {
        $query .= " AND branch_id = " . intval($branch_id);
    }
    $result = $conn->query($query);
    $maxNumber = 0;
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            if (preg_match('/ITEM(\d+)/', $row['item_code'], $matches)) {
                $num = (int)$matches[1];
                if ($num > $maxNumber) $maxNumber = $num;
            }
        }
    }
    return 'ITEM' . str_pad($maxNumber + 1, 3, '0', STR_PAD_LEFT);
}

function normalizeImportBoolean($value) {
    $value = strtolower(trim((string)$value));
    return in_array($value, ['1', 'yes', 'y', 'true', 'default'], true) ? 1 : 0;
}

function importCreateOrGetUnitType($conn, $unit_type_name, $barcode, $qty_smallest_pack, $is_default_uom, $status, $branch_id, $items_branch_column_exists) {
    $unit_type_name = trim($unit_type_name);
    $check_ut_query = "SELECT unit_type_id FROM unit_types WHERE unit_type_name = ?";
    if ($items_branch_column_exists) {
        $check_ut_query .= " AND (branch_id = ? OR branch_id IS NULL)";
    }

    $check_ut_stmt = $conn->prepare($check_ut_query);
    if (!$check_ut_stmt) {
        throw new Exception('Database prepare error while checking unit type');
    }

    if ($items_branch_column_exists) {
        $check_ut_stmt->bind_param("si", $unit_type_name, $branch_id);
    } else {
        $check_ut_stmt->bind_param("s", $unit_type_name);
    }
    $check_ut_stmt->execute();
    $ut_result = $check_ut_stmt->get_result();

    if ($ut_result && $ut_result->num_rows > 0) {
        $ut_row = $ut_result->fetch_assoc();
        $ut_id = (int)$ut_row['unit_type_id'];
        $update_ut_query = "UPDATE unit_types SET barcode = ?, quantity_smallest_pack = ?, is_default_uom = ?, status = ?, updated_at = NOW() WHERE unit_type_id = ?";
        $update_ut_stmt = $conn->prepare($update_ut_query);
        if ($update_ut_stmt) {
            $update_ut_stmt->bind_param("siisi", $barcode, $qty_smallest_pack, $is_default_uom, $status, $ut_id);
            $update_ut_stmt->execute();
        }
        return $ut_id;
    }

    $insert_ut_query = "INSERT INTO unit_types (unit_type_name, barcode, quantity_smallest_pack, is_default_uom, multiplier, branch_id, status) VALUES (?, ?, ?, ?, 1.00, ?, ?)";
    $insert_ut_stmt = $conn->prepare($insert_ut_query);
    if (!$insert_ut_stmt) {
        throw new Exception('Database prepare error while creating unit type');
    }
    $insert_ut_stmt->bind_param("ssiiis", $unit_type_name, $barcode, $qty_smallest_pack, $is_default_uom, $branch_id, $status);
    if (!$insert_ut_stmt->execute()) {
        throw new Exception('Failed to create unit type: ' . $insert_ut_stmt->error);
    }
    return (int)$conn->insert_id;
}

function importValueProvided($row, $key) {
    return array_key_exists($key, $row) && trim((string)$row[$key]) !== '';
}

function normalizeImportDateValue($value) {
    $value = trim((string)$value);
    if ($value === '') return null;

    if (is_numeric($value) && (float)$value > 20000 && (float)$value < 80000) {
        $timestamp = ((float)$value - 25569) * 86400;
        return gmdate('Y-m-d', (int)$timestamp);
    }

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return $value;
    }

    if (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})$/', $value, $m)) {
        $day = (int)$m[1];
        $month = (int)$m[2];
        $year = (int)$m[3];
        if (checkdate($month, $day, $year)) {
            return sprintf('%04d-%02d-%02d', $year, $month, $day);
        }
    }

    $timestamp = strtotime($value);
    if ($timestamp !== false) {
        return date('Y-m-d', $timestamp);
    }

    throw new Exception('Invalid date format: ' . $value . '. Use YYYY-MM-DD format only.');
}

function importFindExistingItem($conn, $item_code, $item_name, $branch_id, $items_branch_column_exists, $view_all_branches) {
    $item_code = trim((string)$item_code);
    $item_name = trim((string)$item_name);

    if ($item_code !== '') {
        $query = "SELECT item_id, item_code, item_name, description, category, principal, volume, stock, reorder_level, status, unit_type, unit_price, product_image_url, default_unit_type_id FROM items WHERE item_code = ?";
        $types = 's';
        $params = [$item_code];
        if ($items_branch_column_exists && !$view_all_branches && $branch_id > 0) {
            $query .= " AND branch_id = ?";
            $types .= 'i';
            $params[] = $branch_id;
        }
        $query .= " LIMIT 1";
        $stmt = $conn->prepare($query);
        if (!$stmt) throw new Exception('Database prepare error while checking existing item code');
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result && $row = $result->fetch_assoc()) return $row;
    }

    if ($item_name !== '') {
        $query = "SELECT item_id, item_code, item_name, description, category, principal, volume, stock, reorder_level, status, unit_type, unit_price, product_image_url, default_unit_type_id FROM items WHERE LOWER(TRIM(item_name)) = LOWER(TRIM(?))";
        $types = 's';
        $params = [$item_name];
        if ($items_branch_column_exists && !$view_all_branches && $branch_id > 0) {
            $query .= " AND branch_id = ?";
            $types .= 'i';
            $params[] = $branch_id;
        }
        $query .= " LIMIT 1";
        $stmt = $conn->prepare($query);
        if (!$stmt) throw new Exception('Database prepare error while checking existing item name');
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result && $row = $result->fetch_assoc()) return $row;
    }
    return null;
}

function importCreateOrUpdateItemFromGroupedRows($conn, $group, $branch_id, $user_id, $items_branch_column_exists, $view_all_branches) {
    $base = $group['base'];
    $firstRow = $group['rows'][0];
    $item_code = trim((string)($base['item_code'] ?? ''));
    $item_name_from_file = trim((string)($base['item_name'] ?? ''));
    $existing_item = importFindExistingItem($conn, $item_code, $item_name_from_file, $branch_id, $items_branch_column_exists, $view_all_branches);
    $is_update = is_array($existing_item);

    if ($item_code === '') $item_code = $is_update ? $existing_item['item_code'] : generateNextItemCodeValue($conn, $branch_id, $items_branch_column_exists, $view_all_branches);
    $item_name = importValueProvided($base, 'item_name') ? trim((string)$base['item_name']) : ($is_update ? $existing_item['item_name'] : '');
    if ($item_name === '') throw new Exception('Item name is required for new item code ' . $item_code);

    $description = importValueProvided($base, 'description') ? trim((string)$base['description']) : ($is_update ? ($existing_item['description'] ?? $item_name) : $item_name);
    $category = importValueProvided($base, 'category') ? trim((string)$base['category']) : ($is_update ? ($existing_item['category'] ?? 'General') : 'General');
    $principal = importValueProvided($base, 'principal') ? trim((string)$base['principal']) : ($is_update ? ($existing_item['principal'] ?? '') : '');
    $principal = $principal !== '' && strtolower($principal) !== 'no principal' ? $principal : null;
    $stock = importValueProvided($base, 'stock') ? (float)$base['stock'] : ($is_update ? (float)($existing_item['stock'] ?? 0) : (float)($firstRow['current_inventory'] ?? 0));
    $reorder_level = importValueProvided($base, 'reorder_level') ? (int)$base['reorder_level'] : ($is_update ? (int)($existing_item['reorder_level'] ?? 0) : 0);
    $status = importValueProvided($base, 'status') ? trim((string)$base['status']) : ($is_update ? ($existing_item['status'] ?? 'active') : 'active');
    $unit_type = importValueProvided($firstRow, 'unit_type') ? trim((string)$firstRow['unit_type']) : ($is_update ? ($existing_item['unit_type'] ?? 'Piece') : 'Piece');
    $unit_price = importValueProvided($firstRow, 'unit_price') ? (float)$firstRow['unit_price'] : ($is_update ? (float)($existing_item['unit_price'] ?? 0) : 0);
    $price_case = $unit_price * 12;
    $price_inner_pack = $unit_price * 6;
    $price_box = $unit_price * 24;
    $price_carton = $unit_price * 48;
    $product_image_url = $is_update ? ($existing_item['product_image_url'] ?? null) : null;

    if ($is_update) {
        $item_id = (int)$existing_item['item_id'];
        $update_query = "UPDATE items SET item_code = ?, item_name = ?, description = ?, category = ?, stock = ?, unit_type = ?, unit_price = ?, price_case = ?, price_inner_pack = ?, price_box = ?, price_carton = ?, reorder_level = ?, status = ?, updated_at = NOW() WHERE item_id = ?";
        $update_stmt = $conn->prepare($update_query);
        if (!$update_stmt) throw new Exception('Database prepare error while updating imported item');
        $update_stmt->bind_param("ssssdsdddddisi", $item_code, $item_name, $description, $category, $stock, $unit_type, $unit_price, $price_case, $price_inner_pack, $price_box, $price_carton, $reorder_level, $status, $item_id);
        if (!$update_stmt->execute()) throw new Exception('Failed to update imported item: ' . $update_stmt->error);
    } else {
        if ($items_branch_column_exists) {
            $insert_query = "INSERT INTO items (item_code, item_name, description, category, stock, unit_type, unit_price, price_case, price_inner_pack, price_box, price_carton, reorder_level, status, product_image_url, branch_id, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
            $insert_stmt = $conn->prepare($insert_query);
            if (!$insert_stmt) throw new Exception('Database prepare error while inserting item');
            $insert_stmt->bind_param("ssssdsdddddissii", $item_code, $item_name, $description, $category, $stock, $unit_type, $unit_price, $price_case, $price_inner_pack, $price_box, $price_carton, $reorder_level, $status, $product_image_url, $branch_id, $user_id);
        } else {
            $insert_query = "INSERT INTO items (item_code, item_name, description, category, stock, unit_type, unit_price, price_case, price_inner_pack, price_box, price_carton, reorder_level, status, product_image_url, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
            $insert_stmt = $conn->prepare($insert_query);
            if (!$insert_stmt) throw new Exception('Database prepare error while inserting item');
            $insert_stmt->bind_param("ssssdsdddddissi", $item_code, $item_name, $description, $category, $stock, $unit_type, $unit_price, $price_case, $price_inner_pack, $price_box, $price_carton, $reorder_level, $status, $product_image_url, $user_id);
        }
        if (!$insert_stmt->execute()) throw new Exception('Failed to add imported item: ' . $insert_stmt->error);
        $item_id = (int)$conn->insert_id;
    }

            $principal_update_stmt = $conn->prepare("UPDATE items SET principal = ?, volume = ? WHERE item_id = ?");
            if ($principal_update_stmt) {
                $principal_update_stmt->bind_param("ssi", $principal, $volume, $item_id);
                $principal_update_stmt->execute();
                $principal_update_stmt->close();
            }

    $seenPricing = [];
    $default_unit_type_id = $is_update ? (int)($existing_item['default_unit_type_id'] ?? 0) : null;
    foreach ($group['rows'] as $row) {
        $ut_name = importValueProvided($row, 'unit_type') ? trim((string)$row['unit_type']) : 'Piece';
        $ut_barcode = importValueProvided($row, 'barcode') ? trim((string)$row['barcode']) : '';
        $ut_qty_smallest = importValueProvided($row, 'qty_smallest_pack') ? max(1, (int)$row['qty_smallest_pack']) : 1;
        $ut_is_default = importValueProvided($row, 'default_uom') ? normalizeImportBoolean($row['default_uom']) : 0;
        $ut_status = importValueProvided($row, 'unit_status') ? trim((string)$row['unit_status']) : 'active';
        $ut_quantity = importValueProvided($row, 'unit_quantity') ? max(1, (int)$row['unit_quantity']) : $ut_qty_smallest;
        $effective_date = importValueProvided($row, 'effective_date') ? normalizeImportDateValue($row['effective_date']) : null;
        $price_level = importValueProvided($row, 'price_level') ? trim((string)$row['price_level']) : 'Standard';

        $ut_id = importCreateOrGetUnitType($conn, $ut_name, $ut_barcode, $ut_qty_smallest, $ut_is_default, $ut_status, $branch_id, $items_branch_column_exists);
        if ($ut_is_default || !$default_unit_type_id) $default_unit_type_id = $ut_id;

        if (importValueProvided($row, 'current_inventory') || importValueProvided($row, 'stock') || importValueProvided($row, 'unit_cost') || importValueProvided($row, 'as_of_date')) {
            $current_inventory = importValueProvided($row, 'current_inventory') ? (float)$row['current_inventory'] : (importValueProvided($row, 'stock') ? (float)$row['stock'] : 0);
            $as_of_date = importValueProvided($row, 'as_of_date') ? normalizeImportDateValue($row['as_of_date']) : null;
            $unit_cost = importValueProvided($row, 'unit_cost') ? (float)$row['unit_cost'] : (importValueProvided($row, 'unit_price') ? (float)$row['unit_price'] : 0);
            upsertItemUnitInventory($conn, $item_id, $ut_id, $current_inventory, $as_of_date, $unit_cost);
        }

        if (importValueProvided($row, 'unit_price')) {
            $ut_price = (float)$row['unit_price'];
            $pricingKey = $ut_id . '|' . $price_level . '|' . ($effective_date ?: 'current');
            if (isset($seenPricing[$pricingKey])) continue;
            $seenPricing[$pricingKey] = true;

            $today = date('Y-m-d');
            $new_effective_date = normalizeImportDateValue($effective_date ?? '');
            $is_future_effective = !empty($new_effective_date) && $new_effective_date > $today;

            // If imported effective date is in the future, DO NOT overwrite current pricing.
            // Save it to schedule table, same behavior as Batch Update Price Level.
            if ($is_future_effective) {
                $old_price_for_history = null;
                $old_qty_for_history = null;
                $old_effective_for_history = null;
                $should_save_schedule = true;

                $existing_schedule_query = "SELECT unit_price, unit_quantity, effective_date FROM item_unit_pricing_schedule WHERE item_id = ? AND unit_type_id = ? AND price_level = ? AND effective_date = ? LIMIT 1";
                $existing_schedule_stmt = $conn->prepare($existing_schedule_query);
                if (!$existing_schedule_stmt) throw new Exception('Database prepare error while checking imported scheduled pricing');
                $existing_schedule_stmt->bind_param("iiss", $item_id, $ut_id, $price_level, $new_effective_date);
                $existing_schedule_stmt->execute();
                $existing_schedule_result = $existing_schedule_stmt->get_result();
                if ($existing_schedule_result && $existing_schedule_row = $existing_schedule_result->fetch_assoc()) {
                    $old_schedule_price = round((float)$existing_schedule_row['unit_price'], 4);
                    $new_schedule_price = round((float)$ut_price, 4);
                    $old_schedule_qty = (int)($existing_schedule_row['unit_quantity'] ?? 1);
                    $new_schedule_qty = (int)$ut_quantity;
                    $old_price_for_history = (float)$existing_schedule_row['unit_price'];
                    $old_qty_for_history = $old_schedule_qty;
                    $old_effective_for_history = $existing_schedule_row['effective_date'];
                    $should_save_schedule = ($old_schedule_price !== $new_schedule_price) || ($old_schedule_qty !== $new_schedule_qty);
                }
                $existing_schedule_stmt->close();

                if (!$should_save_schedule) {
                    continue;
                }

                if ($old_price_for_history !== null) {
                    saveItemUnitPricingHistory($conn, $item_id, $ut_id, $price_level, $old_price_for_history, $old_qty_for_history, $old_effective_for_history, 'import_previous_scheduled', $user_id);
                }

                amgcUpsertItemUnitScheduleStrict($conn, $item_id, $ut_id, $price_level, $ut_price, $ut_quantity, $new_effective_date);

                continue;
            }

            $check_pricing_query = "SELECT pricing_id, unit_price, unit_quantity, effective_date FROM item_unit_pricing WHERE item_id = ? AND unit_type_id = ? AND price_level = ? ORDER BY pricing_id DESC LIMIT 1";
            $check_pricing_stmt = $conn->prepare($check_pricing_query);
            if (!$check_pricing_stmt) throw new Exception('Database prepare error while checking imported pricing');
            $check_pricing_stmt->bind_param("iis", $item_id, $ut_id, $price_level);
            $check_pricing_stmt->execute();
            $pricing_result = $check_pricing_stmt->get_result();
            if ($pricing_result && $pricing_row = $pricing_result->fetch_assoc()) {
                $old_price = round((float)$pricing_row['unit_price'], 4);
                $new_price = round((float)$ut_price, 4);
                $old_qty = (int)($pricing_row['unit_quantity'] ?? 1);
                $new_qty = (int)$ut_quantity;
                $old_effective_date = normalizeImportDateValue($pricing_row['effective_date'] ?? '');
                $has_price_change = ($old_price !== $new_price) || ($old_qty !== $new_qty) || ((string)$old_effective_date !== (string)$new_effective_date);

                if ($has_price_change) {
                    saveItemUnitPricingHistory($conn, $item_id, $ut_id, $price_level, (float)$pricing_row['unit_price'], $old_qty, $old_effective_date, 'import_previous', $user_id);
                }
            }
            $check_pricing_stmt->close();
            amgcUpsertItemUnitPricingStrict($conn, $item_id, $ut_id, $ut_price, $ut_quantity, $new_effective_date, $price_level);
        }
    }

    if ($default_unit_type_id) {
        $update_default = "UPDATE items SET default_unit_type_id = ? WHERE item_id = ?";
        $upd_stmt = $conn->prepare($update_default);
        if ($upd_stmt) {
            $upd_stmt->bind_param("ii", $default_unit_type_id, $item_id);
            $upd_stmt->execute();
        }
    }
    syncItemSummaryFromDefaultInventory($conn, $item_id);
    syncItemPriceSummaryFromPricing($conn, $item_id);
    return ['item_id' => $item_id, 'item_code' => $item_code, 'item_name' => $item_name, 'mode' => $is_update ? 'updated' : 'created'];
}

// ========== HANDLE AJAX REQUESTS ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json');
    ini_set('display_errors', 0);
    error_reporting(E_ALL);
    
    try {
        $conn->begin_transaction();
        
        // ADD ITEM
        if ($_POST['action'] === 'add_item') {
            $item_code = $_POST['item_code'] ?? '';
            $barcode = trim($_POST['barcode'] ?? '');
            $item_name = $_POST['item_name'] ?? '';
            $description = $_POST['description'] ?? null;
            $category = $_POST['category'] ?? 'General';
            $principal = trim($_POST['principal'] ?? '');
            $principal = ($principal !== '' && strtolower($principal) !== 'no principal') ? $principal : null;
            $volume = (strtolower($category) === 'oil') ? trim($_POST['volume'] ?? '') : null;
            $volume = (empty($volume)) ? null : $volume;
            $oil_type = (strtolower($category) === 'oil') ? trim($_POST['oil_type'] ?? '') : null;
            $oil_type = (empty($oil_type)) ? null : $oil_type;
            $stock = 0;
            
            $unit_types_json = $_POST['unit_types'] ?? '[]';
            $unit_types_array = json_decode($unit_types_json, true);
            
            $pricing_json = $_POST['pricing'] ?? '[]';
            $pricing_data = json_decode($pricing_json, true);
            
            if (!is_array($unit_types_array) || count($unit_types_array) === 0) {
                throw new Exception('At least one unit type with price is required');
            }
            
            $first_unit = $unit_types_array[0];
            $unit_type = $first_unit['unit_type'];
            $unit_price = (float)$first_unit['unit_price'];
            
            $reorder_level = (int)($_POST['reorder_level'] ?? 0);
            $status = $_POST['status'] ?? 'active';
            
            if (empty($item_code)) throw new Exception('Item code is required');
            if (empty($item_name)) throw new Exception('Item name is required');
            if (empty($description)) throw new Exception('Description is required');
            
            $price_case = $unit_price * 12;
            $price_inner_pack = $unit_price * 6;
            $price_box = $unit_price * 24;
            $price_carton = $unit_price * 48;
            
            $check_query = "SELECT item_id FROM items WHERE item_code = ?";
            $check_params = [$item_code];
            $param_types = "s";
            if ($items_branch_column_exists && !$view_all_branches && $branch_id > 0) {
                $check_query .= " AND branch_id = ?";
                $check_params[] = $branch_id;
                $param_types .= "i";
            }
            $check_stmt = $conn->prepare($check_query);
            if (!$check_stmt) throw new Exception('Database prepare error');
            $check_stmt->bind_param($param_types, ...$check_params);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            if ($check_result->num_rows > 0) {
                throw new Exception('Item code already exists' . ($items_branch_column_exists && !$view_all_branches && $branch_id > 0 ? ' in this branch' : ''));
            }

            if ($barcode !== '') {
                $barcode_check_query = "SELECT item_id FROM items WHERE barcode = ?";
                $barcode_check_params = [$barcode];
                $barcode_param_types = "s";
                if ($items_branch_column_exists && !$view_all_branches && $branch_id > 0) {
                    $barcode_check_query .= " AND branch_id = ?";
                    $barcode_check_params[] = $branch_id;
                    $barcode_param_types .= "i";
                }
                $barcode_check_stmt = $conn->prepare($barcode_check_query);
                if (!$barcode_check_stmt) throw new Exception('Database prepare error');
                $barcode_check_stmt->bind_param($barcode_param_types, ...$barcode_check_params);
                $barcode_check_stmt->execute();
                $barcode_check_result = $barcode_check_stmt->get_result();
                if ($barcode_check_result->num_rows > 0) {
                    throw new Exception('Barcode number already exists' . ($items_branch_column_exists && !$view_all_branches && $branch_id > 0 ? ' in this branch' : ''));
                }
            }
            
            $product_image_url = null;
            if (isset($_FILES['item_image']) && $_FILES['item_image']['error'] === UPLOAD_ERR_OK) {
                $file_info = pathinfo($_FILES['item_image']['name']);
                $extension = strtolower($file_info['extension']);
                $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                if (in_array($extension, $allowed) && $_FILES['item_image']['size'] <= 5 * 1024 * 1024) {
                    $upload_dir = '../uploads/products/';
                    if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
                    $filename = 'item_' . time() . '.' . $extension;
                    $filepath = $upload_dir . $filename;
                    if (move_uploaded_file($_FILES['item_image']['tmp_name'], $filepath)) {
                        $product_image_url = $filename;
                    }
                }
            }
            
            // Insert item without default_unit_type_id first
            if ($items_branch_column_exists) {
                $insert_query = "INSERT INTO items (
                    item_code, barcode, item_name, description, category, principal, volume, oil_type, stock, unit_type, unit_price, 
                    price_case, price_inner_pack, price_box, price_carton, reorder_level, status, 
                    product_image_url, branch_id, created_by, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
                $insert_stmt = $conn->prepare($insert_query);
                if (!$insert_stmt) throw new Exception('Database prepare error');
                $insert_stmt->bind_param("ssssssssdsdddddissii", 
                    $item_code, $barcode, $item_name, $description, $category, $principal, $volume, $oil_type, $stock, $unit_type, $unit_price,
                    $price_case, $price_inner_pack, $price_box, $price_carton,
                    $reorder_level, $status, $product_image_url, $branch_id, $user_id
                );
            } else {
                $insert_query = "INSERT INTO items (
                    item_code, barcode, item_name, description, category, principal, volume, oil_type, stock, unit_type, unit_price, 
                    price_case, price_inner_pack, price_box, price_carton, reorder_level, status, 
                    product_image_url, created_by, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
                $insert_stmt = $conn->prepare($insert_query);
                if (!$insert_stmt) throw new Exception('Database prepare error');
                $insert_stmt->bind_param("ssssssssdsdddddissi", 
                    $item_code, $barcode, $item_name, $description, $category, $principal, $volume, $oil_type, $stock, $unit_type, $unit_price,
                    $price_case, $price_inner_pack, $price_box, $price_carton,
                    $reorder_level, $status, $product_image_url, $user_id
                );
            }
            if (!$insert_stmt->execute()) {
                throw new Exception('Failed to add item: ' . $insert_stmt->error);
            }
            $item_id = $conn->insert_id;
            $principal_update_stmt = $conn->prepare("UPDATE items SET principal = ? WHERE item_id = ?");
            if ($principal_update_stmt) {
                $principal_update_stmt->bind_param("si", $principal, $item_id);
                $principal_update_stmt->execute();
                $principal_update_stmt->close();
            }
            
            // Get unique unit types
            $unique_unit_types = [];
            foreach ($unit_types_array as $ut_data) {
                $ut_name = trim($ut_data['unit_type']);
                if (!isset($unique_unit_types[$ut_name])) {
                    $unique_unit_types[$ut_name] = $ut_data;
                }
            }
            
            $default_unit_type_id = null;
            foreach ($unique_unit_types as $ut_name => $ut_data) {
                $ut_price = (float)$ut_data['unit_price'];
                $ut_quantity = (int)($ut_data['unit_quantity'] ?? 1);
                $ut_initial = strtoupper(trim($ut_data['uom_initial'] ?? ''));
                $ut_barcode = ''; // Barcode is saved to items.barcode only
                $ut_qty_smallest = (int)($ut_data['qty_smallest_pack'] ?? 1);
                $ut_is_default = (int)($ut_data['default_uom'] ?? 0);
                $ut_status = $ut_data['status'] ?? 'active';
                $ut_current_inventory = isset($ut_data['current_inventory']) ? (float)$ut_data['current_inventory'] : 0;
                $ut_as_of_date = !empty($ut_data['as_of_date']) ? $ut_data['as_of_date'] : null;
                $ut_unit_cost = isset($ut_data['unit_cost']) ? (float)$ut_data['unit_cost'] : $ut_price;
                
                $check_ut_query = "SELECT unit_type_id FROM unit_types WHERE unit_type_name = ?";
                if ($items_branch_column_exists) {
                    $check_ut_query .= " AND (branch_id = ? OR branch_id IS NULL)";
                }
                $check_ut_stmt = $conn->prepare($check_ut_query);
                if ($items_branch_column_exists) {
                    $check_ut_stmt->bind_param("si", $ut_name, $branch_id);
                } else {
                    $check_ut_stmt->bind_param("s", $ut_name);
                }
                $check_ut_stmt->execute();
                $ut_result = $check_ut_stmt->get_result();
                
                if ($ut_result->num_rows > 0) {
                    $ut_row = $ut_result->fetch_assoc();
                    $ut_id = $ut_row['unit_type_id'];
                    $update_ut_query = "UPDATE unit_types SET uom_initial = ?, barcode = ?, quantity_smallest_pack = ?, is_default_uom = ?, status = ?, updated_at = NOW() WHERE unit_type_id = ?";
                    $update_ut_stmt = $conn->prepare($update_ut_query);
                    if ($update_ut_stmt) {
                        $update_ut_stmt->bind_param("ssiisi", $ut_initial, $ut_barcode, $ut_qty_smallest, $ut_is_default, $ut_status, $ut_id);
                        $update_ut_stmt->execute();
                    }
                } else {
                    $insert_ut_query = "INSERT INTO unit_types (unit_type_name, uom_initial, barcode, quantity_smallest_pack, is_default_uom, multiplier, branch_id, status) VALUES (?, ?, ?, ?, ?, 1.00, ?, ?)";
                    $insert_ut_stmt = $conn->prepare($insert_ut_query);
                    $insert_ut_stmt->bind_param("sssiiis", $ut_name, $ut_initial, $ut_barcode, $ut_qty_smallest, $ut_is_default, $branch_id, $ut_status);
                    if (!$insert_ut_stmt->execute()) {
                        throw new Exception('Failed to create unit type: ' . $insert_ut_stmt->error);
                    }
                    $ut_id = $conn->insert_id;
                }
                
                if ($ut_is_default) {
                    $default_unit_type_id = $ut_id;
                }

                upsertItemUnitInventory($conn, $item_id, $ut_id, $ut_current_inventory, $ut_as_of_date, $ut_unit_cost);
                
                if (!empty($pricing_data) && is_array($pricing_data)) {
                    foreach ($pricing_data as $pricing_row) {
                        if (isset($pricing_row['prices'][$ut_name])) {
                            $ut_price = (float)$pricing_row['prices'][$ut_name];
                            $effective_date = !empty($pricing_row['effective_date']) ? $pricing_row['effective_date'] : null;
                            $price_level = !empty($pricing_row['price_level']) ? $pricing_row['price_level'] : 'Standard';
                            
                            $effective_date = !empty($effective_date) ? normalizeImportDateValue($effective_date) : null;
                            amgcUpsertItemUnitPricingStrict($conn, $item_id, $ut_id, $ut_price, $ut_quantity, $effective_date, $price_level);
                        }
                    }
                }
            }
            
            // If no default was marked, set the first unit type as default
            if (!$default_unit_type_id) {
                $first_ut_query = "SELECT unit_type_id FROM item_unit_pricing WHERE item_id = ? LIMIT 1";
                $first_stmt = $conn->prepare($first_ut_query);
                $first_stmt->bind_param("i", $item_id);
                $first_stmt->execute();
                $first_res = $first_stmt->get_result();
                if ($first_row = $first_res->fetch_assoc()) {
                    $default_unit_type_id = $first_row['unit_type_id'];
                }
            }
            if ($default_unit_type_id) {
                $update_default = "UPDATE items SET default_unit_type_id = ? WHERE item_id = ?";
                $upd_def_stmt = $conn->prepare($update_default);
                $upd_def_stmt->bind_param("ii", $default_unit_type_id, $item_id);
                $upd_def_stmt->execute();
            }

            syncItemSummaryFromDefaultInventory($conn, $item_id);
            
            if (isset($_FILES['itemImages']) && !empty($_FILES['itemImages']['name'][0])) {
                $uploaded_images = handleMultipleImageUpload($_FILES['itemImages'], $item_id);
                foreach ($uploaded_images as $index => $img_file) {
                    $img_query = "INSERT INTO item_images (item_id, image_path, image_order, is_primary) VALUES (?, ?, ?, ?)";
                    $img_stmt = $conn->prepare($img_query);
                    $img_order = $index;
                    $primary = ($index === 0) ? 1 : 0;
                    $img_stmt->bind_param("isii", $item_id, $img_file, $img_order, $primary);
                    $img_stmt->execute();
                }
            }
            
            $conn->commit();
            echo json_encode(['success' => true, 'message' => 'Item added successfully', 'item_id' => $item_id]);
            exit;
        }
        
        // EXPORT ACTUAL ITEMS FOR EXCEL IMPORT/UPDATE
        elseif ($_POST['action'] === 'export_items_data') {
            // Export current item rows in the same import-template format.
            // One row per item + unit_type + price_level so users can edit every current UOM/price level.
            // This exports only the latest/current pricing table, not pricing history or scheduled history rows.
            $export_query = "
                SELECT
                    i.item_code,
                    i.item_name,
                    i.description,
                    i.category,
                    COALESCE(NULLIF(TRIM(i.principal), ''), 'No Principal') AS principal,
                    COALESCE(inv_default.current_inventory, i.stock, 0) AS stock,
                    i.reorder_level,
                    i.status,
                    COALESCE(ut.unit_type_name, i.unit_type, 'Piece') AS unit_type,
                    COALESCE(ut.barcode, '') AS barcode,
                    COALESCE(ut.quantity_smallest_pack, iup.unit_quantity, 1) AS qty_smallest_pack,
                    CASE
                        WHEN i.default_unit_type_id IS NOT NULL AND i.default_unit_type_id = iup.unit_type_id THEN 'yes'
                        WHEN i.default_unit_type_id IS NULL AND COALESCE(ut.unit_type_name, i.unit_type, 'Piece') = COALESCE(i.unit_type, 'Piece') THEN 'yes'
                        ELSE 'no'
                    END AS default_uom,
                    COALESCE(ut.status, 'active') AS unit_status,
                    COALESCE(iup.price_level, 'Standard') AS price_level,
                    COALESCE(iup.effective_date, '') AS effective_date,
                    COALESCE(iup.unit_price, i.unit_price, 0) AS unit_price,
                    COALESCE(iup.unit_quantity, ut.quantity_smallest_pack, 1) AS unit_quantity,
                    COALESCE(inv.current_inventory, 0) AS current_inventory,
                    COALESCE(inv.as_of_date, '') AS as_of_date,
                    COALESCE(inv.unit_cost, i.unit_price, 0) AS unit_cost
                FROM items i
                LEFT JOIN item_unit_pricing iup ON iup.item_id = i.item_id
                LEFT JOIN unit_types ut ON ut.unit_type_id = iup.unit_type_id
                LEFT JOIN item_unit_inventory inv ON inv.item_id = i.item_id AND inv.unit_type_id = iup.unit_type_id
                LEFT JOIN item_unit_inventory inv_default ON inv_default.item_id = i.item_id AND inv_default.unit_type_id = i.default_unit_type_id
                WHERE i.status <> 'deleted'
                $items_branch_condition
                ORDER BY
                    i.category ASC,
                    i.item_name ASC,
                    i.item_code ASC,
                    CASE
                        WHEN i.default_unit_type_id IS NOT NULL AND i.default_unit_type_id = iup.unit_type_id THEN 0
                        ELSE 1
                    END ASC,
                    COALESCE(ut.unit_type_name, i.unit_type, 'Piece') ASC,
                    CASE WHEN COALESCE(iup.price_level, 'Standard') = 'Standard' THEN 0 ELSE 1 END ASC,
                    COALESCE(iup.price_level, 'Standard') ASC
            ";
            $export_result = $conn->query($export_query);
            if (!$export_result) {
                throw new Exception('Failed to export items: ' . $conn->error);
            }

            $export_rows = [];
            while ($export_row = $export_result->fetch_assoc()) {
                $export_rows[] = $export_row;
            }

            $conn->commit();
            echo json_encode([
                'success' => true,
                'message' => count($export_rows) . ' item/unit/price row(s) exported successfully',
                'rows' => $export_rows
            ]);
            exit;
        }
        // IMPORT ITEMS FROM EXCEL/CSV
        elseif ($_POST['action'] === 'import_items') {
            $rows_json = $_POST['rows'] ?? '[]';
            $rows = json_decode($rows_json, true);
            if (!is_array($rows) || count($rows) === 0) {
                throw new Exception('No import rows were received');
            }
            $grouped_items = [];
            foreach ($rows as $index => $row) {
                if (!is_array($row)) continue;
                $item_code = trim((string)($row['item_code'] ?? ''));
                $item_name = trim((string)($row['item_name'] ?? ''));
                $group_key = $item_code !== '' ? strtoupper($item_code) : ('ROW_' . $index . '_' . strtoupper($item_name));
                if (!isset($grouped_items[$group_key])) {
                    $grouped_items[$group_key] = [
                        'base' => [
                            'item_code' => $item_code,
                            'item_name' => $item_name,
                            'description' => $row['description'] ?? '',
                            'category' => $row['category'] ?? 'General',
                            'principal' => $row['principal'] ?? '',
                            'stock' => $row['stock'] ?? 0,
                            'reorder_level' => $row['reorder_level'] ?? 0,
                            'status' => $row['status'] ?? 'active'
                        ],
                        'rows' => []
                    ];
                }
                $grouped_items[$group_key]['rows'][] = $row;
            }
            if (count($grouped_items) === 0) {
                throw new Exception('No valid rows found for import');
            }
            $imported_items = [];
            foreach ($grouped_items as $group) {
                $imported_items[] = importCreateOrUpdateItemFromGroupedRows($conn, $group, $branch_id, $user_id, $items_branch_column_exists, $view_all_branches);
            }
            $conn->commit();
            echo json_encode([
                'success' => true,
                'message' => count($imported_items) . ' item(s) imported successfully',
                'imported_count' => count($imported_items),
                'items' => $imported_items
            ]);
            exit;
        }
        
        // UPDATE ITEM
        elseif ($_POST['action'] === 'update_item') {
            $item_id = (int)($_POST['item_id'] ?? 0);
            if ($item_id <= 0) throw new Exception('Invalid item ID');
            
            $item_name = $_POST['item_name'] ?? '';
            // Barcode is optional. In edit mode, it comes from the typed UoM barcode field,
            // then saves to items.barcode only when Update Item is clicked.
            $barcode = trim($_POST['barcode'] ?? '');
            $description = $_POST['description'] ?? null;
            $category = $_POST['category'] ?? 'General';
            $principal = trim($_POST['principal'] ?? '');
            $principal = ($principal !== '' && strtolower($principal) !== 'no principal') ? $principal : null;
            $volume = (strtolower($category) === 'oil') ? trim($_POST['volume'] ?? '') : null;
            $volume = (empty($volume)) ? null : $volume;
            $oil_type = (strtolower($category) === 'oil') ? trim($_POST['oil_type'] ?? '') : null;
            $oil_type = (empty($oil_type)) ? null : $oil_type;
            $stock = 0;
            
            $unit_types_json = $_POST['unit_types'] ?? '[]';
            $unit_types_array = json_decode($unit_types_json, true);
            
            $pricing_json = $_POST['pricing'] ?? '[]';
            $pricing_data = json_decode($pricing_json, true);
            
            if (!is_array($unit_types_array) || count($unit_types_array) === 0) {
                throw new Exception('At least one unit type with price is required');
            }
            
            $first_unit = $unit_types_array[0];
            $unit_type = $first_unit['unit_type'];
            $unit_price = (float)$first_unit['unit_price'];
            $reorder_level = (int)($_POST['reorder_level'] ?? 0);
            $status = $_POST['status'] ?? 'active';
            
            if ($item_id <= 0) throw new Exception('Invalid item ID');
            if (empty($item_name)) throw new Exception('Item name is required');
            if (empty($description)) throw new Exception('Description is required');
            
            $item_code = $_POST['item_code'] ?? '';
            if ($items_branch_column_exists && !$view_all_branches && $branch_id > 0) {
                $duplicate_check = "SELECT item_id FROM items WHERE item_code = ? AND branch_id = ? AND item_id != ?";
                $dup_stmt = $conn->prepare($duplicate_check);
                if (!$dup_stmt) throw new Exception('Database prepare error');
                $dup_stmt->bind_param("sii", $item_code, $branch_id, $item_id);
                $dup_stmt->execute();
                $dup_result = $dup_stmt->get_result();
                if ($dup_result->num_rows > 0) {
                    throw new Exception('Item code already exists in this branch');
                }
            }

            if ($barcode !== '') {
                $barcode_duplicate_query = "SELECT item_id FROM items WHERE barcode = ? AND item_id != ?";
                $barcode_duplicate_types = "si";
                $barcode_duplicate_params = [$barcode, $item_id];
                if ($items_branch_column_exists && !$view_all_branches && $branch_id > 0) {
                    $barcode_duplicate_query .= " AND branch_id = ?";
                    $barcode_duplicate_types .= "i";
                    $barcode_duplicate_params[] = $branch_id;
                }
                $barcode_dup_stmt = $conn->prepare($barcode_duplicate_query);
                if (!$barcode_dup_stmt) throw new Exception('Database prepare error');
                $barcode_dup_stmt->bind_param($barcode_duplicate_types, ...$barcode_duplicate_params);
                $barcode_dup_stmt->execute();
                $barcode_dup_result = $barcode_dup_stmt->get_result();
                if ($barcode_dup_result->num_rows > 0) {
                    throw new Exception('Barcode number already exists' . ($items_branch_column_exists ? ' in this branch' : ''));
                }
            }
            
            $price_case = $unit_price * 12;
            $price_inner_pack = $unit_price * 6;
            $price_box = $unit_price * 24;
            $price_carton = $unit_price * 48;
            
            $product_image_url = null;
            if (isset($_FILES['item_image']) && $_FILES['item_image']['error'] === UPLOAD_ERR_OK) {
                $file_info = pathinfo($_FILES['item_image']['name']);
                $extension = strtolower($file_info['extension']);
                $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                if (in_array($extension, $allowed) && $_FILES['item_image']['size'] <= 5 * 1024 * 1024) {
                    $upload_dir = '../uploads/products/';
                    if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
                    $filename = 'item_' . $item_id . '_' . time() . '.' . $extension;
                    $filepath = $upload_dir . $filename;
                    if (move_uploaded_file($_FILES['item_image']['tmp_name'], $filepath)) {
                        $product_image_url = $filename;
                    }
                }
            }
            
            if ($product_image_url) {
                $update_query = "UPDATE items SET barcode = ?, item_name = ?, description = ?, category = ?, volume = ?, oil_type = ?, stock = ?, unit_type = ?, unit_price = ?, price_case = ?, price_inner_pack = ?, price_box = ?, price_carton = ?, reorder_level = ?, status = ?, product_image_url = ?, updated_at = NOW() WHERE item_id = ?";
                $update_stmt = $conn->prepare($update_query);
                if (!$update_stmt) throw new Exception('Database prepare error');
                $update_stmt->bind_param("ssssssdsdddddissi", $barcode, $item_name, $description, $category, $volume, $oil_type, $stock, $unit_type, $unit_price, $price_case, $price_inner_pack, $price_box, $price_carton, $reorder_level, $status, $product_image_url, $item_id);
            } else {
                $update_query = "UPDATE items SET barcode = ?, item_name = ?, description = ?, category = ?, volume = ?, oil_type = ?, stock = ?, unit_type = ?, unit_price = ?, price_case = ?, price_inner_pack = ?, price_box = ?, price_carton = ?, reorder_level = ?, status = ?, updated_at = NOW() WHERE item_id = ?";
                $update_stmt = $conn->prepare($update_query);
                if (!$update_stmt) throw new Exception('Database prepare error');
                $update_stmt->bind_param("ssssssdsdddddisi", $barcode, $item_name, $description, $category, $volume, $oil_type, $stock, $unit_type, $unit_price, $price_case, $price_inner_pack, $price_box, $price_carton, $reorder_level, $status, $item_id);
            }
            if (!$update_stmt->execute()) {
                throw new Exception('Failed to update item: ' . $update_stmt->error);
            }
            $principal_update_stmt = $conn->prepare("UPDATE items SET principal = ? WHERE item_id = ?");
            if ($principal_update_stmt) {
                $principal_update_stmt->bind_param("si", $principal, $item_id);
                $principal_update_stmt->execute();
                $principal_update_stmt->close();
            }
            
            // Process unit types and pricing
            $unique_unit_types = [];
            foreach ($unit_types_array as $ut_data) {
                $ut_name = trim($ut_data['unit_type']);
                if (!isset($unique_unit_types[$ut_name])) {
                    $unique_unit_types[$ut_name] = $ut_data;
                }
            }
            
            $default_unit_type_id = null;
            $ut_name_to_id_map = [];
            
            foreach ($unique_unit_types as $ut_name => $ut_data) {
                $ut_initial = strtoupper(trim($ut_data['uom_initial'] ?? ''));
                $ut_barcode = ''; // Barcode is saved to items.barcode only
                $ut_qty_smallest = (int)($ut_data['qty_smallest_pack'] ?? 1);
                $ut_is_default = (int)($ut_data['default_uom'] ?? 0);
                $ut_status = $ut_data['status'] ?? 'active';
                $ut_current_inventory = isset($ut_data['current_inventory']) ? (float)$ut_data['current_inventory'] : 0;
                $ut_as_of_date = !empty($ut_data['as_of_date']) ? $ut_data['as_of_date'] : null;
                $ut_unit_cost = isset($ut_data['unit_cost']) ? (float)$ut_data['unit_cost'] : $ut_price;
                
                $check_ut_query = "SELECT unit_type_id FROM unit_types WHERE unit_type_name = ?";
                if ($items_branch_column_exists) {
                    $check_ut_query .= " AND (branch_id = ? OR branch_id IS NULL)";
                }
                $check_ut_stmt = $conn->prepare($check_ut_query);
                if ($items_branch_column_exists) {
                    $check_ut_stmt->bind_param("si", $ut_name, $branch_id);
                } else {
                    $check_ut_stmt->bind_param("s", $ut_name);
                }
                $check_ut_stmt->execute();
                $ut_result = $check_ut_stmt->get_result();
                
                if ($ut_result->num_rows > 0) {
                    $ut_row = $ut_result->fetch_assoc();
                    $ut_id = $ut_row['unit_type_id'];
                    $update_ut_query = "UPDATE unit_types SET uom_initial = ?, barcode = ?, quantity_smallest_pack = ?, is_default_uom = ?, status = ?, updated_at = NOW() WHERE unit_type_id = ?";
                    $update_ut_stmt = $conn->prepare($update_ut_query);
                    if (!$update_ut_stmt) throw new Exception('Database prepare error');
                    $update_ut_stmt->bind_param("ssiisi", $ut_initial, $ut_barcode, $ut_qty_smallest, $ut_is_default, $ut_status, $ut_id);
                    if (!$update_ut_stmt->execute()) {
                        throw new Exception('Failed to update unit type: ' . $update_ut_stmt->error);
                    }
                } else {
                    $insert_ut_query = "INSERT INTO unit_types (unit_type_name, uom_initial, barcode, quantity_smallest_pack, is_default_uom, multiplier, branch_id, status) VALUES (?, ?, ?, ?, ?, 1.00, ?, ?)";
                    $insert_ut_stmt = $conn->prepare($insert_ut_query);
                    if (!$insert_ut_stmt) throw new Exception('Database prepare error');
                    $insert_ut_stmt->bind_param("sssiiis", $ut_name, $ut_initial, $ut_barcode, $ut_qty_smallest, $ut_is_default, $branch_id, $ut_status);
                    if (!$insert_ut_stmt->execute()) {
                        throw new Exception('Failed to create unit type: ' . $insert_ut_stmt->error);
                    }
                    $ut_id = $conn->insert_id;
                }
                
                $ut_name_to_id_map[$ut_name] = $ut_id;
                if ($ut_is_default) {
                    $default_unit_type_id = $ut_id;
                }

                upsertItemUnitInventory($conn, $item_id, $ut_id, $ut_current_inventory, $ut_as_of_date, $ut_unit_cost);
            }
            
            // Update pricing without creating duplicate rows
            $seenPricingUpdates = [];
            foreach ($unique_unit_types as $ut_name => $ut_data) {
                if (isset($ut_name_to_id_map[$ut_name])) {
                    $ut_id = $ut_name_to_id_map[$ut_name];
                    $ut_quantity = max(1, (int)($ut_data['unit_quantity'] ?? 1));
                    
                    if (!empty($pricing_data) && is_array($pricing_data)) {
                        foreach ($pricing_data as $pricing_row) {
                            if (isset($pricing_row['prices'][$ut_name])) {
                                $ut_price = (float)$pricing_row['prices'][$ut_name];
                                $effective_date = !empty($pricing_row['effective_date']) ? normalizeImportDateValue($pricing_row['effective_date']) : null;
                                $price_level = !empty($pricing_row['price_level']) ? trim($pricing_row['price_level']) : 'Standard';
                                $pricingKey = $item_id . '|' . $ut_id . '|' . $price_level . '|' . ($effective_date ?: 'current');
                                if (isset($seenPricingUpdates[$pricingKey])) {
                                    continue;
                                }
                                $seenPricingUpdates[$pricingKey] = true;

                                // Keep the selected price level when editing.
                                // Future effective dates stay in the schedule table instead of overwriting the current price level row.
                                if (!empty($effective_date) && $effective_date > date('Y-m-d')) {
                                    amgcUpsertItemUnitScheduleStrict($conn, $item_id, $ut_id, $price_level, $ut_price, $ut_quantity, $effective_date);
                                } else {
                                    amgcUpsertItemUnitPricingStrict($conn, $item_id, $ut_id, $ut_price, $ut_quantity, $effective_date, $price_level);
                                }
                            }
                        }
                    }
                }
            }
            
            // Delete removed Unit Types & Pricing for this item.
            // Before this fix, update only inserted/updated submitted UOM rows.
            // Rows removed in the edit modal stayed in item_unit_pricing/item_unit_inventory,
            // so they appeared again when opening the item because get_item reads from both tables.
            $submitted_unit_type_ids = array_values(array_unique(array_map('intval', array_values($ut_name_to_id_map))));
            $submitted_unit_type_ids = array_filter($submitted_unit_type_ids, function($id) { return $id > 0; });

            if (count($submitted_unit_type_ids) > 0) {
                $delete_placeholders = implode(',', array_fill(0, count($submitted_unit_type_ids), '?'));
                $delete_types = 'i' . str_repeat('i', count($submitted_unit_type_ids));
                $delete_params = array_merge([$item_id], $submitted_unit_type_ids);

                $delete_removed_pricing = $conn->prepare("DELETE FROM item_unit_pricing WHERE item_id = ? AND unit_type_id NOT IN ($delete_placeholders)");
                if (!$delete_removed_pricing) throw new Exception('Database prepare error while deleting removed unit pricing');
                $delete_removed_pricing->bind_param($delete_types, ...$delete_params);
                if (!$delete_removed_pricing->execute()) {
                    throw new Exception('Failed to delete removed unit pricing: ' . $delete_removed_pricing->error);
                }
                $delete_removed_pricing->close();

                $delete_removed_schedule = $conn->prepare("DELETE FROM item_unit_pricing_schedule WHERE item_id = ? AND unit_type_id NOT IN ($delete_placeholders)");
                if ($delete_removed_schedule) {
                    $delete_removed_schedule->bind_param($delete_types, ...$delete_params);
                    if (!$delete_removed_schedule->execute()) {
                        throw new Exception('Failed to delete removed scheduled pricing: ' . $delete_removed_schedule->error);
                    }
                    $delete_removed_schedule->close();
                }

                $delete_removed_inventory = $conn->prepare("DELETE FROM item_unit_inventory WHERE item_id = ? AND unit_type_id NOT IN ($delete_placeholders)");
                if ($delete_removed_inventory) {
                    $delete_removed_inventory->bind_param($delete_types, ...$delete_params);
                    if (!$delete_removed_inventory->execute()) {
                        throw new Exception('Failed to delete removed unit inventory: ' . $delete_removed_inventory->error);
                    }
                    $delete_removed_inventory->close();
                }

                if (!$default_unit_type_id || !in_array((int)$default_unit_type_id, $submitted_unit_type_ids, true)) {
                    $default_unit_type_id = (int)$submitted_unit_type_ids[0];
                }
            }

            // Set default_unit_type_id if found
            if ($default_unit_type_id) {
                $update_default = "UPDATE items SET default_unit_type_id = ? WHERE item_id = ?";
                $upd_def_stmt = $conn->prepare($update_default);
                $upd_def_stmt->bind_param("ii", $default_unit_type_id, $item_id);
                $upd_def_stmt->execute();
            } else {
                // No default marked, keep existing or set to first
                $first_ut_query = "SELECT unit_type_id FROM item_unit_pricing WHERE item_id = ? LIMIT 1";
                $first_stmt = $conn->prepare($first_ut_query);
                $first_stmt->bind_param("i", $item_id);
                $first_stmt->execute();
                $first_res = $first_stmt->get_result();
                if ($first_row = $first_res->fetch_assoc()) {
                    $update_default = "UPDATE items SET default_unit_type_id = ? WHERE item_id = ?";
                    $upd_def_stmt = $conn->prepare($update_default);
                    $upd_def_stmt->bind_param("ii", $first_row['unit_type_id'], $item_id);
                    $upd_def_stmt->execute();
                }
            }

            syncItemSummaryFromDefaultInventory($conn, $item_id);
            
            if (isset($_FILES['editItemImages']) && !empty($_FILES['editItemImages']['name'][0])) {
                $uploaded_images = handleMultipleImageUpload($_FILES['editItemImages'], $item_id);
                foreach ($uploaded_images as $index => $img_file) {
                    $img_query = "INSERT INTO item_images (item_id, image_path, image_order, is_primary) VALUES (?, ?, ?, ?)";
                    $img_stmt = $conn->prepare($img_query);
                    $img_order = $index;
                    $existing_count_query = "SELECT COUNT(*) as cnt FROM item_images WHERE item_id = ?";
                    $count_stmt = $conn->prepare($existing_count_query);
                    $count_stmt->bind_param("i", $item_id);
                    $count_stmt->execute();
                    $count_result = $count_stmt->get_result();
                    $existing_count = $count_result->fetch_assoc()['cnt'] ?? 0;
                    $primary = ($existing_count === 0 && $index === 0) ? 1 : 0;
                    $img_stmt->bind_param("isii", $item_id, $img_file, $img_order, $primary);
                    $img_stmt->execute();
                }
            }
            
            $conn->commit();
            echo json_encode(['success' => true, 'message' => 'Item updated successfully']);
            exit;
        }
        
        // BATCH UPDATE PRICE LEVEL
        elseif ($_POST['action'] === 'batch_update_price_level') {
            $effective_date = trim($_POST['effective_date'] ?? '');
            $price_level = trim($_POST['price_level'] ?? 'Standard');
            $updates_json = $_POST['updates'] ?? '[]';
            $updates = json_decode($updates_json, true);

            if ($effective_date === '') {
                throw new Exception('Effective date is required');
            }
            if ($price_level === '') {
                $price_level = 'Standard';
            }
            if (!is_array($updates) || count($updates) === 0) {
                throw new Exception('No price updates were received');
            }

            $today = date('Y-m-d');
            $updated_count = 0;
            $affected_items = [];
            $updated_items = [];

            foreach ($updates as $row) {
                $item_id = (int)($row['item_id'] ?? 0);
                $unit_type_id = (int)($row['unit_type_id'] ?? 0);
                $unit_price = isset($row['unit_price']) ? (float)$row['unit_price'] : null;
                $unit_quantity = max(1, (int)($row['unit_quantity'] ?? 1));

                if ($item_id <= 0 || $unit_type_id <= 0 || $unit_price === null) {
                    continue;
                }

                $row_changed = false;
                $old_price_for_details = null;
                $old_effective_for_details = null;
                $old_quantity_for_details = null;
                $change_type_for_details = ($effective_date > $today) ? 'Scheduled' : 'Applied';

                $details_stmt = $conn->prepare("SELECT i.item_code, i.item_name, i.description, ut.unit_type_name FROM items i JOIN unit_types ut ON ut.unit_type_id = ? WHERE i.item_id = ? LIMIT 1");
                if (!$details_stmt) {
                    throw new Exception('Database prepare error while loading updated item details');
                }
                $details_stmt->bind_param('ii', $unit_type_id, $item_id);
                $details_stmt->execute();
                $details_result = $details_stmt->get_result();
                $details_row = $details_result ? $details_result->fetch_assoc() : null;
                $details_stmt->close();

                if (!$details_row) {
                    continue;
                }

                if ($effective_date > $today) {
                    $existing_schedule_stmt = $conn->prepare("SELECT unit_price, COALESCE(unit_quantity, 1) as unit_quantity, effective_date FROM item_unit_pricing_schedule WHERE item_id = ? AND unit_type_id = ? AND price_level = ? AND effective_date = ? LIMIT 1");
                    if ($existing_schedule_stmt) {
                        $existing_schedule_stmt->bind_param('iiss', $item_id, $unit_type_id, $price_level, $effective_date);
                        $existing_schedule_stmt->execute();
                        $existing_schedule_result = $existing_schedule_stmt->get_result();
                        if ($existing_schedule = $existing_schedule_result->fetch_assoc()) {
                            $old_schedule_price = (float)($existing_schedule['unit_price'] ?? 0);
                            $old_schedule_qty = (int)($existing_schedule['unit_quantity'] ?? 1);
                            $old_price_for_details = $old_schedule_price;
                            $old_quantity_for_details = $old_schedule_qty;
                            $old_effective_for_details = $existing_schedule['effective_date'] ?? null;
                            if (abs($old_schedule_price - $unit_price) > 0.00001 || $old_schedule_qty !== $unit_quantity) {
                                saveItemUnitPricingHistory($conn, $item_id, $unit_type_id, $price_level, $old_schedule_price, $old_schedule_qty, $existing_schedule['effective_date'], 'previous_scheduled', $user_id);
                                $row_changed = true;
                            }
                        }
                        $existing_schedule_stmt->close();
                    }

                    if (!$row_changed && $old_price_for_details === null) {
                        $current_price_stmt = $conn->prepare("SELECT unit_price, COALESCE(unit_quantity, 1) as unit_quantity, effective_date FROM item_unit_pricing WHERE item_id = ? AND unit_type_id = ? AND price_level = ? LIMIT 1");
                        if ($current_price_stmt) {
                            $current_price_stmt->bind_param('iis', $item_id, $unit_type_id, $price_level);
                            $current_price_stmt->execute();
                            $current_price_result = $current_price_stmt->get_result();
                            if ($current_price_row = $current_price_result->fetch_assoc()) {
                                $old_price_for_details = (float)($current_price_row['unit_price'] ?? 0);
                                $old_quantity_for_details = (int)($current_price_row['unit_quantity'] ?? 1);
                                $old_effective_for_details = $current_price_row['effective_date'] ?? null;
                            }
                            $current_price_stmt->close();
                        }
                        $row_changed = true;
                    }

                    if (!$row_changed) {
                        continue;
                    }

                    $schedule_query = "INSERT INTO item_unit_pricing_schedule (item_id, unit_type_id, price_level, unit_price, unit_quantity, effective_date)
                        VALUES (?, ?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE unit_price = VALUES(unit_price), unit_quantity = VALUES(unit_quantity), updated_at = NOW()";
                    $schedule_stmt = $conn->prepare($schedule_query);
                    if (!$schedule_stmt) {
                        throw new Exception('Database prepare error while scheduling price update');
                    }
                    $schedule_stmt->bind_param('iisdis', $item_id, $unit_type_id, $price_level, $unit_price, $unit_quantity, $effective_date);
                    if (!$schedule_stmt->execute()) {
                        throw new Exception('Failed to schedule price update: ' . $schedule_stmt->error);
                    }
                    $schedule_stmt->close();
                } else {
                    $check_query = "SELECT pricing_id FROM item_unit_pricing WHERE item_id = ? AND unit_type_id = ? AND price_level = ? LIMIT 1";
                    $check_stmt = $conn->prepare($check_query);
                    if (!$check_stmt) {
                        throw new Exception('Database prepare error while checking current price');
                    }
                    $check_stmt->bind_param('iis', $item_id, $unit_type_id, $price_level);
                    $check_stmt->execute();
                    $check_result = $check_stmt->get_result();
                    $pricing_row = $check_result ? $check_result->fetch_assoc() : null;
                    $check_stmt->close();

                    if ($pricing_row) {
                        $current_snapshot_stmt = $conn->prepare("SELECT unit_price, COALESCE(unit_quantity, 1) as unit_quantity, effective_date FROM item_unit_pricing WHERE pricing_id = ? LIMIT 1");
                        if ($current_snapshot_stmt) {
                            $pricing_id_for_snapshot = (int)$pricing_row['pricing_id'];
                            $current_snapshot_stmt->bind_param('i', $pricing_id_for_snapshot);
                            $current_snapshot_stmt->execute();
                            $current_snapshot_result = $current_snapshot_stmt->get_result();
                            if ($current_snapshot = $current_snapshot_result->fetch_assoc()) {
                                $old_current_price = (float)($current_snapshot['unit_price'] ?? 0);
                                $old_current_qty = (int)($current_snapshot['unit_quantity'] ?? 1);
                                $old_current_effective = $current_snapshot['effective_date'] ?? null;
                                $old_price_for_details = $old_current_price;
                                $old_quantity_for_details = $old_current_qty;
                                $old_effective_for_details = $old_current_effective;
                                if (abs($old_current_price - $unit_price) > 0.00001 || $old_current_qty !== $unit_quantity || (string)$old_current_effective !== (string)$effective_date) {
                                    saveItemUnitPricingHistory($conn, $item_id, $unit_type_id, $price_level, $old_current_price, $old_current_qty, $old_current_effective, 'previous', $user_id);
                                    $row_changed = true;
                                }
                            }
                            $current_snapshot_stmt->close();
                        }

                        if (!$row_changed) {
                            continue;
                        }

                        $update_query = "UPDATE item_unit_pricing SET unit_price = ?, unit_quantity = ?, effective_date = ?, updated_at = NOW() WHERE pricing_id = ?";
                        $update_stmt = $conn->prepare($update_query);
                        if (!$update_stmt) {
                            throw new Exception('Database prepare error while updating current price');
                        }
                        $pricing_id = (int)$pricing_row['pricing_id'];
                        $update_stmt->bind_param('disi', $unit_price, $unit_quantity, $effective_date, $pricing_id);
                        if (!$update_stmt->execute()) {
                            throw new Exception('Failed to update current price: ' . $update_stmt->error);
                        }
                        $update_stmt->close();
                    } else {
                        $insert_query = "INSERT INTO item_unit_pricing (item_id, unit_type_id, unit_price, unit_quantity, effective_date, price_level) VALUES (?, ?, ?, ?, ?, ?)";
                        $insert_stmt = $conn->prepare($insert_query);
                        if (!$insert_stmt) {
                            throw new Exception('Database prepare error while inserting current price');
                        }
                        $insert_stmt->bind_param('iidiss', $item_id, $unit_type_id, $unit_price, $unit_quantity, $effective_date, $price_level);
                        if (!$insert_stmt->execute()) {
                            throw new Exception('Failed to insert current price: ' . $insert_stmt->error);
                        }
                        $insert_stmt->close();
                        $row_changed = true;
                    }

                    $delete_schedule_stmt = $conn->prepare("DELETE FROM item_unit_pricing_schedule WHERE item_id = ? AND unit_type_id = ? AND price_level = ? AND effective_date <= ?");
                    if ($delete_schedule_stmt) {
                        $delete_schedule_stmt->bind_param('iiss', $item_id, $unit_type_id, $price_level, $effective_date);
                        $delete_schedule_stmt->execute();
                        $delete_schedule_stmt->close();
                    }
                }

                if (!$row_changed) {
                    continue;
                }

                $updated_count++;
                $affected_items[$item_id] = true;
                $updated_items[] = [
                    'item_id' => $item_id,
                    'item_code' => $details_row['item_code'] ?? '',
                    'item_name' => $details_row['item_name'] ?? '',
                    'description' => $details_row['description'] ?? '',
                    'unit_type_id' => $unit_type_id,
                    'unit_type_name' => $details_row['unit_type_name'] ?? '',
                    'price_level' => $price_level,
                    'old_price' => $old_price_for_details,
                    'new_price' => $unit_price,
                    'old_unit_quantity' => $old_quantity_for_details,
                    'new_unit_quantity' => $unit_quantity,
                    'old_effective_date' => $old_effective_for_details,
                    'effective_date' => $effective_date,
                    'update_type' => $change_type_for_details
                ];
            }

            if ($updated_count === 0) {
                throw new Exception('No actual price changes detected. Only edited prices with changes will be updated.');
            }

            foreach (array_keys($affected_items) as $affected_item_id) {
                syncItemPriceSummaryFromPricing($conn, (int)$affected_item_id);
            }

            $conn->commit();
            echo json_encode([
                'success' => true,
                'message' => ($effective_date > $today ? 'Price updates scheduled successfully' : 'Price updates applied successfully'),
                'updated_count' => $updated_count,
                'updated_items' => $updated_items,
                'scheduled' => ($effective_date > $today)
            ]);
            exit;
        }


        // DELETE ITEM
        elseif ($_POST['action'] === 'delete_item') {
            $item_id = (int)($_POST['item_id'] ?? 0);
            if ($item_id <= 0) throw new Exception('Invalid item ID');
            if ($items_branch_column_exists && !$view_all_branches) {
                $check_query = "SELECT item_id FROM items WHERE item_id = ? AND branch_id = ?";
                $check_stmt = $conn->prepare($check_query);
                if (!$check_stmt) throw new Exception('Database prepare error');
                $check_stmt->bind_param("ii", $item_id, $branch_id);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                if ($check_result->num_rows === 0) {
                    throw new Exception('Item not found or access denied');
                }
            }
            $delete_images_query = "DELETE FROM item_images WHERE item_id = ?";
            $delete_images_stmt = $conn->prepare($delete_images_query);
            if ($delete_images_stmt) {
                $delete_images_stmt->bind_param("i", $item_id);
                $delete_images_stmt->execute();
            }
            $delete_pricing_query = "DELETE FROM item_unit_pricing WHERE item_id = ?";
            $delete_pricing_stmt = $conn->prepare($delete_pricing_query);
            if ($delete_pricing_stmt) {
                $delete_pricing_stmt->bind_param("i", $item_id);
                $delete_pricing_stmt->execute();
            }
            $delete_inventory_query = "DELETE FROM item_unit_inventory WHERE item_id = ?";
            $delete_inventory_stmt = $conn->prepare($delete_inventory_query);
            if ($delete_inventory_stmt) {
                $delete_inventory_stmt->bind_param("i", $item_id);
                $delete_inventory_stmt->execute();
            }
            $delete_query = "DELETE FROM items WHERE item_id = ?";
            $delete_stmt = $conn->prepare($delete_query);
            if (!$delete_stmt) throw new Exception('Database prepare error');
            $delete_stmt->bind_param("i", $item_id);
            if (!$delete_stmt->execute()) {
                throw new Exception('Failed to delete item: ' . $delete_stmt->error);
            }
            $conn->commit();
            echo json_encode(['success' => true, 'message' => 'Item deleted successfully']);
            exit;
        }
        
        // GET BATCH PRICE LEVEL ITEMS
        elseif ($_POST['action'] === 'get_batch_price_level_items') {
            $price_level = trim($_POST['price_level'] ?? 'Standard');
            $effective_date = trim($_POST['effective_date'] ?? '');
            if ($price_level === '') {
                $price_level = 'Standard';
            }

            $query = "SELECT
                    i.item_id,
                    i.item_name,
                    i.description,
                    ut.unit_type_id,
                    ut.unit_type_name,
                    COALESCE(selected_schedule.unit_price, selected_current.unit_price, standard_schedule.unit_price, standard_current.unit_price, 0) AS current_price,
                    COALESCE(selected_schedule.unit_quantity, selected_current.unit_quantity, standard_schedule.unit_quantity, standard_current.unit_quantity, 1) AS unit_quantity,
                    CASE
                        WHEN selected_schedule.schedule_id IS NOT NULL THEN selected_schedule.unit_price
                        WHEN selected_current.pricing_id IS NOT NULL THEN selected_current.unit_price
                        ELSE NULL
                    END AS editable_price
                FROM items i
                JOIN item_unit_pricing standard_current
                    ON standard_current.item_id = i.item_id
                    AND standard_current.price_level = 'Standard'
                JOIN unit_types ut
                    ON ut.unit_type_id = standard_current.unit_type_id
                LEFT JOIN item_unit_pricing selected_current
                    ON selected_current.item_id = i.item_id
                    AND selected_current.unit_type_id = standard_current.unit_type_id
                    AND selected_current.price_level = ?
                LEFT JOIN item_unit_pricing_schedule selected_schedule
                    ON selected_schedule.item_id = i.item_id
                    AND selected_schedule.unit_type_id = standard_current.unit_type_id
                    AND selected_schedule.price_level = ?
                    AND selected_schedule.effective_date = ?
                LEFT JOIN item_unit_pricing_schedule standard_schedule
                    ON standard_schedule.item_id = i.item_id
                    AND standard_schedule.unit_type_id = standard_current.unit_type_id
                    AND standard_schedule.price_level = 'Standard'
                    AND standard_schedule.effective_date = ?
                WHERE i.status = 'active'";
            if ($items_branch_column_exists && !$view_all_branches && $branch_id > 0) {
                $query .= " AND i.branch_id = " . intval($branch_id);
            }
            $query .= " ORDER BY i.item_name ASC, ut.unit_type_name ASC";

            $stmt = $conn->prepare($query);
            if (!$stmt) {
                throw new Exception('Database prepare error while loading batch price list');
            }
            $stmt->bind_param('ssss', $price_level, $price_level, $effective_date, $effective_date);
            $stmt->execute();
            $result = $stmt->get_result();
            $items = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
            $stmt->close();

            echo json_encode([
                'success' => true,
                'items' => $items
            ]);
            exit;
        }

        // GET ITEM DETAILS WITH UNIT TYPES AND IMAGES
        elseif ($_POST['action'] === 'get_item') {
            $item_id = (int)($_POST['item_id'] ?? 0);
            if ($item_id <= 0) throw new Exception('Invalid item ID');
            $item_query = "SELECT i.*, ut.unit_type_name as default_uom_name, ut.quantity_smallest_pack as default_multiplier, CONCAT(u.first_name, ' ', u.last_name) as created_by_name FROM items i LEFT JOIN unit_types ut ON i.default_unit_type_id = ut.unit_type_id LEFT JOIN users u ON i.created_by = u.user_id WHERE i.item_id = ?";
            if ($items_branch_column_exists && !$view_all_branches && $branch_id > 0) {
                $item_query .= " AND i.branch_id = ?";
            }
            $item_stmt = $conn->prepare($item_query);
            if (!$item_stmt) throw new Exception('Database prepare error');
            if ($items_branch_column_exists && !$view_all_branches && $branch_id > 0) {
                $item_stmt->bind_param("ii", $item_id, $branch_id);
            } else {
                $item_stmt->bind_param("i", $item_id);
            }
            $item_stmt->execute();
            $item_result = $item_stmt->get_result();
            if ($item_result->num_rows === 0) {
                throw new Exception('Item not found');
            }
            $item = $item_result->fetch_assoc();

            // Query unit types from BOTH item_unit_pricing AND item_unit_inventory
            // This ensures we show all unit types that have received inventory, not just pricing entries
            $unit_types_query = "
            SELECT DISTINCT 
                ut.unit_type_id, 
                ut.unit_type_name, 
                ut.uom_initial,
                CASE 
                    WHEN ut.unit_type_id = i.default_unit_type_id OR ut.is_default_uom = 1 THEN COALESCE(i.barcode, ut.barcode, '')
                    ELSE COALESCE(ut.barcode, '')
                END AS barcode, 
                ut.quantity_smallest_pack, 
                ut.is_default_uom, 
                ut.status as unit_status, 
                COALESCE(iup.unit_quantity, 1) as unit_quantity, 
                COALESCE(inv.current_inventory, 0) as current_inventory, 
                COALESCE(inv.beginning_inventory, inv.current_inventory, 0) as beginning_inventory, 
                inv.as_of_date, 
                COALESCE(inv.unit_cost, 0) as unit_cost,
                CASE
                    WHEN COALESCE(inv.current_inventory, 0) > 0 AND COALESCE(inv.total_cost, 0) > 0 THEN COALESCE(inv.total_cost, 0) / COALESCE(inv.current_inventory, 1)
                    ELSE COALESCE(NULLIF(inv.unit_cost, 0), NULLIF(iup.unit_price, 0), 0)
                END as average_cost,
                COALESCE(NULLIF(inv.total_cost, 0), COALESCE(inv.current_inventory, 0) * COALESCE(NULLIF(inv.unit_cost, 0), NULLIF(iup.unit_price, 0), 0)) as total_cost,
                i.reorder_level
            FROM unit_types ut
            LEFT JOIN item_unit_pricing iup ON iup.unit_type_id = ut.unit_type_id AND iup.item_id = ?
            LEFT JOIN item_unit_inventory inv ON inv.item_id = ? AND inv.unit_type_id = ut.unit_type_id
            LEFT JOIN items i ON i.item_id = ?
            WHERE 
                (iup.item_id = ? OR inv.item_id = ?)
                AND ut.status = 'active'
            ORDER BY ut.is_default_uom DESC, ut.unit_type_name ASC
            ";
            $unit_types_stmt = $conn->prepare($unit_types_query);
            if (!$unit_types_stmt) throw new Exception('Database prepare error');
            $unit_types_stmt->bind_param("iiiii", $item_id, $item_id, $item_id, $item_id, $item_id);
            $unit_types_stmt->execute();
            $unit_types_result = $unit_types_stmt->get_result();
            $unit_types = $unit_types_result->fetch_all(MYSQLI_ASSOC);

            $images_query = "SELECT image_id, image_path, image_order, is_primary FROM item_images WHERE item_id = ? ORDER BY image_order ASC, is_primary DESC";
            $images_stmt = $conn->prepare($images_query);
            if (!$images_stmt) throw new Exception('Database prepare error');
            $images_stmt->bind_param("i", $item_id);
            $images_stmt->execute();
            $images_result = $images_stmt->get_result();
            $images = $images_result->fetch_all(MYSQLI_ASSOC);

            $pricing_details_query = "
                SELECT effective_date, price_level, unit_type_id, unit_type_name, unit_price, unit_quantity, is_default_uom
                FROM (
                    SELECT
                        iup.effective_date,
                        COALESCE(NULLIF(iup.price_level, ''), 'Standard') AS price_level,
                        ut.unit_type_id,
                        ut.unit_type_name,
                        iup.unit_price,
                        COALESCE(iup.unit_quantity, 1) AS unit_quantity,
                        ut.is_default_uom,
                        1 AS source_order
                    FROM item_unit_pricing iup
                    JOIN unit_types ut ON iup.unit_type_id = ut.unit_type_id
                    WHERE iup.item_id = ?
                    UNION ALL
                    SELECT
                        ips.effective_date,
                        COALESCE(NULLIF(ips.price_level, ''), 'Standard') AS price_level,
                        ut.unit_type_id,
                        ut.unit_type_name,
                        ips.unit_price,
                        COALESCE(ips.unit_quantity, 1) AS unit_quantity,
                        ut.is_default_uom,
                        0 AS source_order
                    FROM item_unit_pricing_schedule ips
                    JOIN unit_types ut ON ips.unit_type_id = ut.unit_type_id
                    WHERE ips.item_id = ?
                ) pricing_all
                ORDER BY source_order ASC,
                    CASE WHEN effective_date IS NULL THEN 1 ELSE 0 END,
                    effective_date DESC,
                    price_level ASC,
                    is_default_uom DESC,
                    unit_type_name ASC
            ";
            $pricing_details_stmt = $conn->prepare($pricing_details_query);
            if (!$pricing_details_stmt) throw new Exception('Database prepare error');
            $pricing_details_stmt->bind_param("ii", $item_id, $item_id);
            $pricing_details_stmt->execute();
            $pricing_details_result = $pricing_details_stmt->get_result();
            $pricing_rows_map = [];
            while ($pricing_detail = $pricing_details_result->fetch_assoc()) {
                $pricing_key = ($pricing_detail['effective_date'] ?? '') . '||' . ($pricing_detail['price_level'] ?? 'Standard');
                if (!isset($pricing_rows_map[$pricing_key])) {
                    $pricing_rows_map[$pricing_key] = [
                        'effective_date' => $pricing_detail['effective_date'],
                        'price_level' => $pricing_detail['price_level'] ?: 'Standard',
                        'prices' => []
                    ];
                }
                $pricing_rows_map[$pricing_key]['prices'][$pricing_detail['unit_type_name']] = [
                    'unit_type_id' => (int)$pricing_detail['unit_type_id'],
                    'unit_price' => (float)$pricing_detail['unit_price'],
                    'unit_quantity' => (int)$pricing_detail['unit_quantity']
                ];
            }
            $pricing_rows = array_values($pricing_rows_map);

            $pricing_history = [];
            $pricing_history_query = "SELECT 'current' as history_source, iup.effective_date, iup.price_level, ut.unit_type_name, iup.unit_price, COALESCE(iup.unit_quantity, 1) as unit_quantity, iup.created_at, iup.updated_at, COALESCE(iup.updated_at, iup.created_at) as sort_datetime
                FROM item_unit_pricing iup
                JOIN unit_types ut ON iup.unit_type_id = ut.unit_type_id
                WHERE iup.item_id = ?
                UNION ALL
                SELECT 'scheduled' as history_source, ips.effective_date, ips.price_level, ut.unit_type_name, ips.unit_price, COALESCE(ips.unit_quantity, 1) as unit_quantity, ips.created_at, ips.updated_at, COALESCE(ips.updated_at, ips.created_at) as sort_datetime
                FROM item_unit_pricing_schedule ips
                JOIN unit_types ut ON ips.unit_type_id = ut.unit_type_id
                WHERE ips.item_id = ?
                UNION ALL
                SELECT iuph.history_type as history_source, iuph.effective_date, iuph.price_level, ut.unit_type_name, iuph.unit_price, COALESCE(iuph.unit_quantity, 1) as unit_quantity, iuph.created_at, iuph.created_at as updated_at, iuph.created_at as sort_datetime
                FROM item_unit_pricing_history iuph
                JOIN unit_types ut ON iuph.unit_type_id = ut.unit_type_id
                WHERE iuph.item_id = ?
                ORDER BY
                    CASE
                        WHEN history_source = 'scheduled' THEN 1
                        WHEN history_source = 'current' THEN 2
                        ELSE 3
                    END ASC,
                    effective_date DESC,
                    sort_datetime DESC,
                    price_level ASC,
                    unit_type_name ASC";
            $pricing_history_stmt = $conn->prepare($pricing_history_query);
            if ($pricing_history_stmt) {
                $pricing_history_stmt->bind_param("iii", $item_id, $item_id, $item_id);
                $pricing_history_stmt->execute();
                $pricing_history_result = $pricing_history_stmt->get_result();
                $pricing_history = $pricing_history_result ? $pricing_history_result->fetch_all(MYSQLI_ASSOC) : [];
                $pricing_history_stmt->close();
            }

            $inventory_summary = [
                'beginning_inventory' => 0,
                'total_inventory' => 0,
                'total_cost' => 0,
                'average_cost_month' => 0,
                'ave_daily_offtake' => 0,
                'offtake_total_quantity' => 0,
                'offtake_active_days' => 0
            ];
            foreach ($unit_types as $ut) {
                $inventory_summary['beginning_inventory'] += (float)($ut['beginning_inventory'] ?? 0);
                $inventory_summary['total_inventory'] += (float)($ut['current_inventory'] ?? 0);
                $inventory_summary['total_cost'] += (float)($ut['total_cost'] ?? 0);
            }
            $item['stock'] = $inventory_summary['total_inventory'];
            if ($inventory_summary['total_inventory'] > 0) {
                $inventory_summary['average_cost_month'] = $inventory_summary['total_cost'] / $inventory_summary['total_inventory'];
            }

            $offtake_query = "SELECT COALESCE(SUM(soi.quantity_ordered), 0) as total_quantity_30d,
                    COUNT(DISTINCT DATE(so.created_at)) as active_days
                FROM sales_order_items soi
                JOIN sales_orders so ON soi.so_id = so.so_id
                WHERE soi.item_id = ?
                AND so.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                AND so.order_status IN ('delivered', 'confirmed', 'processing', 'ready')";
            if ($items_branch_column_exists && !$view_all_branches && $branch_id > 0) {
                $offtake_query .= " AND so.branch_id = ?";
            }
            $offtake_stmt = $conn->prepare($offtake_query);
            if ($offtake_stmt) {
                if ($items_branch_column_exists && !$view_all_branches && $branch_id > 0) {
                    $offtake_stmt->bind_param("ii", $item_id, $branch_id);
                } else {
                    $offtake_stmt->bind_param("i", $item_id);
                }
                $offtake_stmt->execute();
                $offtake_result = $offtake_stmt->get_result();
                $offtake_row = $offtake_result ? $offtake_result->fetch_assoc() : null;
                if ($offtake_row) {
                    $inventory_summary['offtake_total_quantity'] = (float)($offtake_row['total_quantity_30d'] ?? 0);
                    $inventory_summary['offtake_active_days'] = (int)($offtake_row['active_days'] ?? 0);
                    $inventory_summary['ave_daily_offtake'] = $inventory_summary['offtake_active_days'] > 0
                        ? $inventory_summary['offtake_total_quantity'] / $inventory_summary['offtake_active_days']
                        : 0;
                }
                $offtake_stmt->close();
            }

            $transactions = [];

            $check_transaction_table = $conn->query("SHOW TABLES LIKE 'inventory_transactions'");
            if ($check_transaction_table && $check_transaction_table->num_rows > 0) {
                $inventory_transaction_columns = [];
                $inventory_transaction_columns_result = $conn->query("SHOW COLUMNS FROM inventory_transactions");
                if ($inventory_transaction_columns_result) {
                    while ($col = $inventory_transaction_columns_result->fetch_assoc()) {
                        $inventory_transaction_columns[] = $col['Field'];
                    }
                }

                $transaction_branch_col = in_array('branch_id', $inventory_transaction_columns, true) ? 'branch_id' : null;
                $qty_col = in_array('quantity_changed', $inventory_transaction_columns, true) ? 'quantity_changed' : (in_array('quantity', $inventory_transaction_columns, true) ? 'quantity' : (in_array('qty', $inventory_transaction_columns, true) ? 'qty' : null));
                $reference_type_col = in_array('reference_type', $inventory_transaction_columns, true) ? 'reference_type' : null;
                $reference_id_col = in_array('reference_id', $inventory_transaction_columns, true) ? 'reference_id' : null;
                $created_by_col = in_array('created_by', $inventory_transaction_columns, true) ? 'created_by' : null;
                $created_at_col = in_array('created_at', $inventory_transaction_columns, true) ? 'created_at' : (in_array('transaction_date', $inventory_transaction_columns, true) ? 'transaction_date' : null);

                if ($qty_col && $created_at_col) {
                    $inventory_select_parts = [
                        "it.item_id",
                        (in_array('transaction_id', $inventory_transaction_columns, true) ? "it.transaction_id AS transaction_id" : "NULL AS transaction_id"),
                        "it.transaction_type",
                        "it.$qty_col AS quantity",
                        (in_array('unit_cost', $inventory_transaction_columns, true) ? "COALESCE(it.unit_cost, 0) AS unit_cost" : "0 AS unit_cost"),
                        (in_array('total_cost', $inventory_transaction_columns, true) ? "COALESCE(it.total_cost, 0) AS total_cost" : "0 AS total_cost"),
                        "COALESCE(ut_tx.unit_type_name, '') AS uom",
                        ($reference_type_col ? "it.$reference_type_col AS reference_type" : "'' AS reference_type"),
                        ($reference_id_col ? "it.$reference_id_col AS reference_id" : "NULL AS reference_id"),
                        "NULL AS notes",
                        "it.$created_at_col AS created_at",
                        "po.po_number",
                        "po.supplier_name",
                        "CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, '')) AS actor_name",
                        "CASE
                            WHEN " . ($reference_type_col ? "it.$reference_type_col" : "''") . " = 'purchase_order' THEN 'Receive Inventory'
                            WHEN " . ($reference_type_col ? "it.$reference_type_col" : "''") . " = 'production' THEN 'Production'
                            WHEN " . ($reference_type_col ? "it.$reference_type_col" : "''") . " IN ('return','return_merchandise','rmr','rejected_delivery') THEN 'Return Merchandise'
                            ELSE 'Receive Inventory'
                        END AS source_label",
                        "CASE
                            WHEN " . ($reference_type_col ? "it.$reference_type_col" : "''") . " = 'purchase_order' THEN COALESCE(po.supplier_name, 'Supplier')
                            WHEN " . ($reference_type_col ? "it.$reference_type_col" : "''") . " = 'production' THEN 'Production'
                            WHEN " . ($reference_type_col ? "it.$reference_type_col" : "''") . " IN ('return','return_merchandise','rmr','rejected_delivery') THEN 'Return Merchandise'
                            ELSE 'Receive Inventory'
                        END AS party_name",
                        "CASE
                            WHEN po.po_number IS NOT NULL AND po.po_number <> '' THEN po.po_number
                            WHEN " . ($reference_id_col ? "it.$reference_id_col" : "NULL") . " IS NOT NULL THEN CONCAT('Ref #', " . ($reference_id_col ? "it.$reference_id_col" : "0") . ")
                            ELSE '—'
                        END AS reference_label"
                    ];

                    $inventory_transactions_query = "SELECT " . implode(", ", $inventory_select_parts) . "
                        FROM inventory_transactions it
                        LEFT JOIN items i ON i.item_id = it.item_id
                        LEFT JOIN unit_types ut_tx ON ut_tx.unit_type_id = i.default_unit_type_id
                        LEFT JOIN purchase_orders po
                            ON " . ($reference_type_col ? "it.$reference_type_col = 'purchase_order'" : "1=0") . "
                            AND " . ($reference_id_col ? "it.$reference_id_col = po.po_id" : "1=0") . "
                        LEFT JOIN users u
                            ON " . ($created_by_col ? "it.$created_by_col = u.user_id" : "1=0") . "
                        WHERE it.item_id = ?" .
                        (($transaction_branch_col && !$view_all_branches && $branch_id > 0) ? " AND it.$transaction_branch_col = ?" : "") .
                        (($reference_type_col) ? " AND it.$reference_type_col IN ('purchase_order','production','return','return_merchandise','rmr','rejected_delivery')" : "");

                    $inventory_transactions_stmt = $conn->prepare($inventory_transactions_query);
                    if ($inventory_transactions_stmt) {
                        if ($transaction_branch_col && !$view_all_branches && $branch_id > 0) {
                            $inventory_transactions_stmt->bind_param("ii", $item_id, $branch_id);
                        } else {
                            $inventory_transactions_stmt->bind_param("i", $item_id);
                        }
                        $inventory_transactions_stmt->execute();
                        $inventory_transactions_result = $inventory_transactions_stmt->get_result();
                        while ($tx = $inventory_transactions_result ? $inventory_transactions_result->fetch_assoc() : null) {
                            $transactions[] = $tx;
                        }
                        $inventory_transactions_stmt->close();
                    }
                }
            }

            $check_sales_order_items_table = $conn->query("SHOW TABLES LIKE 'sales_order_items'");
            $check_sales_orders_table = $conn->query("SHOW TABLES LIKE 'sales_orders'");
            if ($check_sales_order_items_table && $check_sales_order_items_table->num_rows > 0 && $check_sales_orders_table && $check_sales_orders_table->num_rows > 0) {
                $sales_order_columns = [];
                $sales_order_columns_result = $conn->query("SHOW COLUMNS FROM sales_orders");
                if ($sales_order_columns_result) {
                    while ($col = $sales_order_columns_result->fetch_assoc()) {
                        $sales_order_columns[] = $col['Field'];
                    }
                }

                $sales_item_columns = [];
                $sales_item_columns_result = $conn->query("SHOW COLUMNS FROM sales_order_items");
                if ($sales_item_columns_result) {
                    while ($col = $sales_item_columns_result->fetch_assoc()) {
                        $sales_item_columns[] = $col['Field'];
                    }
                }

                $sales_qty_col = in_array('quantity_ordered', $sales_item_columns, true) ? 'quantity_ordered' : (in_array('quantity', $sales_item_columns, true) ? 'quantity' : (in_array('qty', $sales_item_columns, true) ? 'qty' : null));
                $sales_created_at_col = in_array('created_at', $sales_order_columns, true) ? 'created_at' : (in_array('order_date', $sales_order_columns, true) ? 'order_date' : null);
                $sales_branch_col = in_array('branch_id', $sales_order_columns, true) ? 'branch_id' : null;
                $sales_number_col = in_array('so_number', $sales_order_columns, true) ? 'so_number' : (in_array('sales_order_number', $sales_order_columns, true) ? 'sales_order_number' : (in_array('order_number', $sales_order_columns, true) ? 'order_number' : null));
                $sales_customer_col = in_array('customer_name', $sales_order_columns, true) ? 'customer_name' : (in_array('store_name', $sales_order_columns, true) ? 'store_name' : (in_array('customer', $sales_order_columns, true) ? 'customer' : null));
                $sales_created_by_col = in_array('created_by', $sales_order_columns, true) ? 'created_by' : (in_array('encoded_by', $sales_order_columns, true) ? 'encoded_by' : null);
                $sales_status_col = in_array('order_status', $sales_order_columns, true) ? 'order_status' : (in_array('status', $sales_order_columns, true) ? 'status' : null);
                $sales_uom_col = in_array('unit_type', $sales_item_columns, true) ? 'unit_type' : (in_array('uom', $sales_item_columns, true) ? 'uom' : (in_array('unit', $sales_item_columns, true) ? 'unit' : null));

                if ($sales_qty_col && $sales_created_at_col) {
                    $sales_select_parts = [
                        "soi.item_id",
                        (in_array('so_item_id', $sales_item_columns, true) ? "soi.so_item_id AS transaction_id" : (in_array('sales_order_item_id', $sales_item_columns, true) ? "soi.sales_order_item_id AS transaction_id" : "NULL AS transaction_id")),
                        "'sale' AS transaction_type",
                        "(0 - ABS(COALESCE(soi.$sales_qty_col, 0))) AS quantity",
                        ($sales_uom_col ? "COALESCE(soi.$sales_uom_col, ut_sales.unit_type_name, '') AS uom" : "COALESCE(ut_tx.unit_type_name, '') AS uom"),
                        "'sales_order' AS reference_type",
                        "so.so_id AS reference_id",
                        "NULL AS notes",
                        "so.$sales_created_at_col AS created_at",
                        ($sales_number_col ? "so.$sales_number_col AS po_number" : "CONCAT('SO #', so.so_id) AS po_number"),
                        "NULL AS supplier_name",
                        "CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, '')) AS actor_name",
                        "'Sales Order' AS source_label",
                        ($sales_customer_col ? "COALESCE(so.$sales_customer_col, 'Customer') AS party_name" : "'Customer' AS party_name"),
                        ($sales_number_col ? "so.$sales_number_col AS reference_label" : "CONCAT('SO #', so.so_id) AS reference_label")
                    ];

                    $sales_query = "SELECT " . implode(", ", $sales_select_parts) . "
                        FROM sales_order_items soi
                        INNER JOIN sales_orders so ON soi.so_id = so.so_id
                        LEFT JOIN items i ON i.item_id = soi.item_id
                        LEFT JOIN unit_types ut_sales ON ut_sales.unit_type_id = i.default_unit_type_id
                        LEFT JOIN users u ON " . ($sales_created_by_col ? "so.$sales_created_by_col = u.user_id" : "1=0") . "
                        WHERE soi.item_id = ?" . (($sales_branch_col && !$view_all_branches && $branch_id > 0) ? " AND so.$sales_branch_col = ?" : "") . ($sales_status_col ? " AND so.$sales_status_col IN ('pending','confirmed','processing','ready','delivered','completed')" : "");

                    $sales_stmt = $conn->prepare($sales_query);
                    if ($sales_stmt) {
                        if ($sales_branch_col && !$view_all_branches && $branch_id > 0) {
                            $sales_stmt->bind_param("ii", $item_id, $branch_id);
                        } else {
                            $sales_stmt->bind_param("i", $item_id);
                        }
                        $sales_stmt->execute();
                        $sales_result = $sales_stmt->get_result();
                        while ($tx = $sales_result ? $sales_result->fetch_assoc() : null) {
                            $transactions[] = $tx;
                        }
                        $sales_stmt->close();
                    }
                }
            }

            if (!empty($transactions)) {
                usort($transactions, function ($a, $b) {
                    $aTime = strtotime((string)($a['created_at'] ?? '')) ?: 0;
                    $bTime = strtotime((string)($b['created_at'] ?? '')) ?: 0;
                    return $bTime <=> $aTime;
                });
                $transactions = array_slice($transactions, 0, 100);
            }

            echo json_encode([
                'success' => true,
                'item' => $item,
                'unit_types' => $unit_types,
                'images' => $images,
                'pricing_rows' => $pricing_rows,
                'pricing_history' => $pricing_history,
                'inventory_summary' => $inventory_summary,
                'transactions' => $transactions
            ]);
            exit;
        }
        
        // GET ITEM IMAGES
        elseif ($_POST['action'] === 'get_item_images') {
            $item_id = (int)($_POST['item_id'] ?? 0);
            if ($item_id <= 0) throw new Exception('Invalid item ID');
            $images_query = "SELECT image_id, image_path, image_order, is_primary FROM item_images WHERE item_id = ? ORDER BY image_order ASC";
            $images_stmt = $conn->prepare($images_query);
            if (!$images_stmt) throw new Exception('Database prepare error');
            $images_stmt->bind_param("i", $item_id);
            $images_stmt->execute();
            $images_result = $images_stmt->get_result();
            $images = $images_result->fetch_all(MYSQLI_ASSOC);
            echo json_encode(['success' => true, 'images' => $images]);
            exit;
        }
        
        // DELETE ITEM IMAGE
        elseif ($_POST['action'] === 'delete_item_image') {
            $image_id = (int)($_POST['image_id'] ?? 0);
            if ($image_id <= 0) throw new Exception('Invalid image ID');
            $img_query = "SELECT image_path FROM item_images WHERE image_id = ?";
            $img_stmt = $conn->prepare($img_query);
            if (!$img_stmt) throw new Exception('Database prepare error');
            $img_stmt->bind_param("i", $image_id);
            $img_stmt->execute();
            $img_result = $img_stmt->get_result();
            $img_row = $img_result->fetch_assoc();
            if ($img_row) {
                $file_path = '../uploads/products/' . $img_row['image_path'];
                if (file_exists($file_path)) unlink($file_path);
            }
            $delete_query = "DELETE FROM item_images WHERE image_id = ?";
            $delete_stmt = $conn->prepare($delete_query);
            if (!$delete_stmt) throw new Exception('Database prepare error');
            $delete_stmt->bind_param("i", $image_id);
            if (!$delete_stmt->execute()) {
                throw new Exception('Failed to delete image');
            }
            $conn->commit();
            echo json_encode(['success' => true, 'message' => 'Image deleted successfully']);
            exit;
        }
        
        // GET ALL UNIT TYPES FOR AN ITEM (for table display)
        elseif ($_POST['action'] === 'get_item_unit_types') {
            $item_id = (int)($_POST['item_id'] ?? 0);
            if ($item_id <= 0) throw new Exception('Invalid item ID');
            $unit_types_query = "SELECT ut.unit_type_name, MAX(COALESCE(iup.unit_quantity, 1)) as unit_quantity FROM item_unit_pricing iup JOIN unit_types ut ON iup.unit_type_id = ut.unit_type_id WHERE iup.item_id = ? GROUP BY ut.unit_type_id, ut.unit_type_name ORDER BY MAX(ut.is_default_uom) DESC, ut.unit_type_name ASC";
            $unit_types_stmt = $conn->prepare($unit_types_query);
            if (!$unit_types_stmt) throw new Exception('Database prepare error');
            $unit_types_stmt->bind_param('i', $item_id);
            $unit_types_stmt->execute();
            $unit_types_result = $unit_types_stmt->get_result();
            $unit_types = $unit_types_result->fetch_all(MYSQLI_ASSOC);
            echo json_encode(['success' => true, 'unit_types' => $unit_types]);
            exit;
        }
        
        // GET ALL UNIT TYPES (for dropdown)
        elseif ($_POST['action'] === 'get_unit_types') {
            $unit_types_query = "SELECT unit_type_id, unit_type_name, uom_initial, barcode, quantity_smallest_pack, is_default_uom, status FROM unit_types WHERE status = 'active'";
            if ($items_branch_column_exists && !$view_all_branches && $branch_id > 0) {
                $unit_types_query .= " AND (branch_id = $branch_id OR branch_id IS NULL)";
            }
            $unit_types_query .= " ORDER BY is_default_uom DESC, unit_type_name ASC";
            $unit_types_result = $conn->query($unit_types_query);
            if (!$unit_types_result) {
                throw new Exception('Failed to fetch unit types: ' . $conn->error);
            }
            $unit_types = $unit_types_result->fetch_all(MYSQLI_ASSOC);
            echo json_encode(['success' => true, 'unit_types' => $unit_types]);
            exit;
        }
        
        // GET SUPPLIERS
        elseif ($_POST['action'] === 'get_suppliers') {
            $suppliers_query = "SELECT supplier_id, supplier_name, supplier_code, contact_person, email, phone_number FROM suppliers WHERE status = 'active'";
            if ($suppliers_branch_column_exists && !$view_all_branches && $branch_id > 0) {
                $suppliers_query .= " AND branch_id = " . intval($branch_id);
            }
            $suppliers_query .= " ORDER BY supplier_name ASC";
            $suppliers_result = $conn->query($suppliers_query);
            if (!$suppliers_result) {
                throw new Exception('Failed to fetch suppliers: ' . $conn->error);
            }
            $suppliers = $suppliers_result->fetch_all(MYSQLI_ASSOC);
            echo json_encode(['success' => true, 'suppliers' => $suppliers]);
            exit;
        }
        
        // GET SUPPLIER DETAILS
        elseif ($_POST['action'] === 'get_supplier_details') {
            $supplier_id = (int)($_POST['supplier_id'] ?? 0);
            if ($supplier_id <= 0) throw new Exception('Invalid supplier ID');
            $supplier_query = "SELECT * FROM suppliers WHERE supplier_id = ?";
            $supplier_stmt = $conn->prepare($supplier_query);
            if (!$supplier_stmt) throw new Exception('Database prepare error');
            $supplier_stmt->bind_param("i", $supplier_id);
            $supplier_stmt->execute();
            $supplier_result = $supplier_stmt->get_result();
            $supplier = $supplier_result->fetch_assoc();
            if (!$supplier) {
                throw new Exception('Supplier not found');
            }
            $po_query = "SELECT po.*, COUNT(poi.po_item_id) as total_items, SUM(poi.quantity_ordered) as total_quantity, b.branch_name FROM purchase_orders po LEFT JOIN purchase_order_items poi ON po.po_id = poi.po_id LEFT JOIN branches b ON po.branch_id = b.branch_id WHERE po.supplier_id = ? GROUP BY po.po_id ORDER BY po.created_at DESC";
            $po_stmt = $conn->prepare($po_query);
            if (!$po_stmt) throw new Exception('Database prepare error');
            $po_stmt->bind_param("i", $supplier_id);
            $po_stmt->execute();
            $po_result = $po_stmt->get_result();
            $purchase_orders = $po_result->fetch_all(MYSQLI_ASSOC);
            foreach ($purchase_orders as &$po) {
                $items_query = "SELECT poi.*, i.item_name, i.item_code, COALESCE(ut_po.unit_type_name, '') AS unit_type FROM purchase_order_items poi JOIN items i ON poi.item_id = i.item_id LEFT JOIN unit_types ut_po ON ut_po.unit_type_id = i.default_unit_type_id WHERE poi.po_id = ?";
                $items_stmt = $conn->prepare($items_query);
                if (!$items_stmt) throw new Exception('Database prepare error');
                $items_stmt->bind_param("i", $po['po_id']);
                $items_stmt->execute();
                $items_result = $items_stmt->get_result();
                $po['items'] = $items_result->fetch_all(MYSQLI_ASSOC);
            }
            echo json_encode(['success' => true, 'supplier' => $supplier, 'purchase_orders' => $purchase_orders]);
            exit;
        }
        
        // GET LOW STOCK ITEMS
        elseif ($_POST['action'] === 'get_low_stock_items') {
            $low_stock_query = "
                SELECT 
                    i.item_id,
                    i.item_code,
                    i.item_name,
                    COALESCE(inv.current_inventory, 0) as stock,
                    i.reorder_level,
                    COALESCE(ut.unit_type_name, '') as unit_type,
                    i.unit_price,
                    i.category
                FROM items i
                LEFT JOIN unit_types ut ON i.default_unit_type_id = ut.unit_type_id
                LEFT JOIN item_unit_inventory inv ON inv.item_id = i.item_id AND inv.unit_type_id = i.default_unit_type_id
                WHERE COALESCE(inv.current_inventory, 0) <= i.reorder_level
                AND i.status = 'active'
            ";
            
            if ($items_branch_column_exists && !$view_all_branches && $branch_id > 0) {
                $low_stock_query .= " AND i.branch_id = ?";
                $low_stock_stmt = $conn->prepare($low_stock_query);
                if (!$low_stock_stmt) throw new Exception('Database prepare error');
                $low_stock_stmt->bind_param("i", $branch_id);
            } else {
                $low_stock_stmt = $conn->prepare($low_stock_query);
                if (!$low_stock_stmt) throw new Exception('Database prepare error');
            }
            
            $low_stock_stmt->execute();
            $low_stock_result = $low_stock_stmt->get_result();
            $low_stock_items = $low_stock_result->fetch_all(MYSQLI_ASSOC);
            echo json_encode(['success' => true, 'items' => $low_stock_items]);
            exit;
        }
                
        // GET OFFTAKE DATA
        elseif ($_POST['action'] === 'get_offtake_data') {
            $start_date = $_POST['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
            $end_date = $_POST['end_date'] ?? date('Y-m-d');
            $offtake_query = "SELECT DATE(so.created_at) as sale_date, COUNT(DISTINCT so.so_id) as order_count, SUM(soi.quantity_ordered) as total_quantity, SUM(so.total_amount) as total_amount FROM sales_order_items soi JOIN sales_orders so ON soi.so_id = so.so_id WHERE DATE(so.created_at) BETWEEN ? AND ? AND so.order_status IN ('delivered', 'confirmed', 'processing', 'ready')";
            if ($items_branch_column_exists && !$view_all_branches && $branch_id > 0) {
                $offtake_query .= " AND so.branch_id = ? GROUP BY DATE(so.created_at) ORDER BY so.created_at";
                $offtake_stmt = $conn->prepare($offtake_query);
                if (!$offtake_stmt) throw new Exception('Database prepare error');
                $offtake_stmt->bind_param("ssi", $start_date, $end_date, $branch_id);
            } else {
                $offtake_query .= " GROUP BY DATE(so.created_at) ORDER BY so.created_at";
                $offtake_stmt = $conn->prepare($offtake_query);
                if (!$offtake_stmt) throw new Exception('Database prepare error');
                $offtake_stmt->bind_param("ss", $start_date, $end_date);
            }
            $offtake_stmt->execute();
            $offtake_result = $offtake_stmt->get_result();
            $daily_data = $offtake_result->fetch_all(MYSQLI_ASSOC);
            $total_quantity = array_sum(array_column($daily_data, 'total_quantity'));
            $total_orders = array_sum(array_column($daily_data, 'order_count'));
            $total_amount = array_sum(array_column($daily_data, 'total_amount'));
            $active_days = count($daily_data);
            $avg_daily = $active_days > 0 ? round($total_quantity / $active_days, 1) : 0;
            $items_count_query = "SELECT COUNT(*) as total_items FROM items WHERE status = 'active'";
            if ($items_branch_column_exists && !$view_all_branches && $branch_id > 0) {
                $items_count_query .= " AND branch_id = " . intval($branch_id);
            }
            $items_count_result = $conn->query($items_count_query);
            $total_items = $items_count_result ? (int)($items_count_result->fetch_assoc()['total_items'] ?? 1) : 1;
            $avg_per_item = $total_items > 0 ? round($avg_daily / $total_items, 1) : 0;
            echo json_encode([
                'success' => true,
                'daily_data' => $daily_data,
                'summary' => [
                    'total_quantity' => $total_quantity,
                    'total_orders' => $total_orders,
                    'total_amount' => $total_amount,
                    'active_days' => $active_days,
                    'avg_daily' => $avg_daily,
                    'avg_per_item' => $avg_per_item
                ],
                'date_range' => ['start' => $start_date, 'end' => $end_date]
            ]);
            exit;
        }
        
        // PRINT OFFTAKE REPORT
        elseif ($_POST['action'] === 'print_offtake') {
            $filter_data = json_decode($_POST['filter_data'] ?? '{}', true);
            $start_date = $filter_data['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
            $end_date = $filter_data['end_date'] ?? date('Y-m-d');
            $offtake_query = "SELECT DATE(so.created_at) as sale_date, COUNT(DISTINCT so.so_id) as order_count, SUM(soi.quantity_ordered) as total_quantity, SUM(so.total_amount) as total_amount FROM sales_order_items soi JOIN sales_orders so ON soi.so_id = so.so_id WHERE DATE(so.created_at) BETWEEN ? AND ? AND so.order_status IN ('delivered', 'confirmed', 'processing', 'ready')";
            if ($items_branch_column_exists && !$view_all_branches && $branch_id > 0) {
                $offtake_query .= " AND so.branch_id = ? GROUP BY DATE(so.created_at) ORDER BY so.created_at";
                $offtake_stmt = $conn->prepare($offtake_query);
                $offtake_stmt->bind_param("ssi", $start_date, $end_date, $branch_id);
            } else {
                $offtake_query .= " GROUP BY DATE(so.created_at) ORDER BY so.created_at";
                $offtake_stmt = $conn->prepare($offtake_query);
                $offtake_stmt->bind_param("ss", $start_date, $end_date);
            }
            $offtake_stmt->execute();
            $offtake_result = $offtake_stmt->get_result();
            $daily_data = $offtake_result->fetch_all(MYSQLI_ASSOC);
            $total_quantity = array_sum(array_column($daily_data, 'total_quantity'));
            $total_orders = array_sum(array_column($daily_data, 'order_count'));
            $total_amount = array_sum(array_column($daily_data, 'total_amount'));
            $active_days = count($daily_data);
            $avg_daily = $active_days > 0 ? round($total_quantity / $active_days, 1) : 0;
            $items_count_query = "SELECT COUNT(*) as total_items FROM items WHERE status = 'active'";
            if ($items_branch_column_exists && !$view_all_branches && $branch_id > 0) {
                $items_count_query .= " AND branch_id = " . intval($branch_id);
            }
            $items_count_result = $conn->query($items_count_query);
            $total_items = $items_count_result ? (int)($items_count_result->fetch_assoc()['total_items'] ?? 1) : 1;
            $avg_per_item = $total_items > 0 ? round($avg_daily / $total_items, 1) : 0;
            echo json_encode([
                'success' => true,
                'items' => $daily_data,
                'summary' => [
                    'total_quantity' => $total_quantity,
                    'total_orders' => $total_orders,
                    'total_amount' => $total_amount,
                    'active_days' => $active_days,
                    'avg_daily' => $avg_daily,
                    'avg_per_item' => $avg_per_item
                ],
                'date_range' => ['start' => $start_date, 'end' => $end_date],
                'branch_name' => $branch_id ? ('Branch ' . $branch_id) : 'All Branches',
                'view_all' => $view_all_branches
            ]);
            exit;
        }
        
        // UPDATE STOCK AFTER SALES ORDER (per selected unit type)
        elseif ($_POST['action'] === 'update_stock_from_sales') {
            $item_id = (int)($_POST['item_id'] ?? 0);
            $quantity_sold = (float)($_POST['quantity'] ?? 0);
            $unit_type = isset($_POST['unit_type']) ? trim($_POST['unit_type']) : '';
            $so_id = (int)($_POST['so_id'] ?? 0);

            if ($item_id <= 0 || $quantity_sold <= 0 || $so_id <= 0 || $unit_type === '') {
                throw new Exception('Invalid parameters');
            }

            if ($items_branch_column_exists && !$view_all_branches && $branch_id > 0) {
                $check_query = "SELECT item_id FROM items WHERE item_id = ? AND branch_id = ?";
                $check_stmt = $conn->prepare($check_query);
                if (!$check_stmt) throw new Exception('Database prepare error');
                $check_stmt->bind_param('ii', $item_id, $branch_id);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                if ($check_result->num_rows === 0) {
                    throw new Exception('Item not found or access denied');
                }
                $check_stmt->close();
            }

            // Resolve the unit row by selected UOM. If it does not exist, use the item default UOM.
            // Important: if order is BUNDLE and inventory is BUNDLE, deduct exactly the ordered qty.
            // No multiplication by quantity_smallest_pack is done here.
            $inventory_query = "SELECT inv.inventory_id, inv.current_inventory, inv.unit_cost, inv.total_cost, ut.unit_type_id, ut.unit_type_name
                FROM item_unit_inventory inv
                JOIN unit_types ut ON inv.unit_type_id = ut.unit_type_id
                WHERE inv.item_id = ? AND (LOWER(TRIM(ut.unit_type_name)) = LOWER(TRIM(?)) OR LOWER(TRIM(ut.uom_initial)) = LOWER(TRIM(?)))" .
                ((amgcColumnExists($conn, 'item_unit_inventory', 'branch_id') && !$view_all_branches && $branch_id > 0) ? " AND inv.branch_id = " . intval($branch_id) : "") .
                " ORDER BY CASE WHEN inv.current_inventory > 0 THEN 0 ELSE 1 END, inv.inventory_id ASC LIMIT 1";
            $inventory_stmt = $conn->prepare($inventory_query);
            if (!$inventory_stmt) throw new Exception('Database prepare error');
            $inventory_stmt->bind_param('iss', $item_id, $unit_type, $unit_type);
            $inventory_stmt->execute();
            $inventory_row = $inventory_stmt->get_result()->fetch_assoc();
            $inventory_stmt->close();

            if (!$inventory_row) {
                $inventory_query = "SELECT inv.inventory_id, inv.current_inventory, inv.unit_cost, inv.total_cost, ut.unit_type_id, ut.unit_type_name
                    FROM items i
                    JOIN item_unit_inventory inv ON inv.item_id = i.item_id AND inv.unit_type_id = i.default_unit_type_id
                    JOIN unit_types ut ON inv.unit_type_id = ut.unit_type_id
                    WHERE i.item_id = ?" .
                    ((amgcColumnExists($conn, 'item_unit_inventory', 'branch_id') && !$view_all_branches && $branch_id > 0) ? " AND inv.branch_id = " . intval($branch_id) : "") .
                    " LIMIT 1";
                $inventory_stmt = $conn->prepare($inventory_query);
                if (!$inventory_stmt) throw new Exception('Database prepare error');
                $inventory_stmt->bind_param('i', $item_id);
                $inventory_stmt->execute();
                $inventory_row = $inventory_stmt->get_result()->fetch_assoc();
                $inventory_stmt->close();
            }

            if (!$inventory_row) {
                throw new Exception('Inventory record for selected unit type not found');
            }

            // Before deducting, sync this item from the ledger so old PC/BUNDLE split rows do not cause false zero stock.
            amgcSyncItemStockFromLedger($conn, $item_id, (!$view_all_branches ? (int)$branch_id : 0));

            // Reload the row after sync.
            $reload_query = "SELECT inv.inventory_id, inv.current_inventory, inv.unit_cost, inv.total_cost, ut.unit_type_id, ut.unit_type_name
                FROM item_unit_inventory inv
                JOIN unit_types ut ON inv.unit_type_id = ut.unit_type_id
                WHERE inv.inventory_id = ? LIMIT 1";
            $reload_stmt = $conn->prepare($reload_query);
            if (!$reload_stmt) throw new Exception('Database prepare error');
            $reload_stmt->bind_param('i', $inventory_row['inventory_id']);
            $reload_stmt->execute();
            $inventory_row = $reload_stmt->get_result()->fetch_assoc();
            $reload_stmt->close();

            if (!$inventory_row) {
                throw new Exception('Inventory record not found after sync');
            }

            $previous_inventory = (float)$inventory_row['current_inventory'];
            if ($previous_inventory < $quantity_sold) {
                throw new Exception('Insufficient stock. Available: ' . number_format($previous_inventory, 2) . ' ' . $inventory_row['unit_type_name'] . ', Requested: ' . number_format($quantity_sold, 2));
            }

            $unit_cost = 0.0;
            if ($previous_inventory > 0 && (float)$inventory_row['total_cost'] > 0) {
                $unit_cost = (float)$inventory_row['total_cost'] / $previous_inventory;
            } else {
                $unit_cost = (float)($inventory_row['unit_cost'] ?? 0);
            }

            // Prefer the COGS already saved in sales_order_items for this SO/item/unit.
            $deduct_cost = round($quantity_sold * $unit_cost, 2);
            if (amgcColumnExists($conn, 'sales_order_items', 'cogs_amount')) {
                $cogs_sql = "SELECT COALESCE(SUM(cogs_amount), 0) AS cogs_amount
                             FROM sales_order_items
                             WHERE so_id = ? AND item_id = ? AND LOWER(TRIM(unit_type)) = LOWER(TRIM(?))";
                if ($cogs_stmt = $conn->prepare($cogs_sql)) {
                    $cogs_stmt->bind_param('iis', $so_id, $item_id, $unit_type);
                    $cogs_stmt->execute();
                    $cogs_row = $cogs_stmt->get_result()->fetch_assoc();
                    $saved_cogs = (float)($cogs_row['cogs_amount'] ?? 0);
                    $cogs_stmt->close();
                    if ($saved_cogs > 0) {
                        $deduct_cost = round($saved_cogs, 2);
                        $unit_cost = $quantity_sold > 0 ? round($deduct_cost / $quantity_sold, 4) : $unit_cost;
                    }
                }
            }

            $new_inventory = max(0, $previous_inventory - $quantity_sold);
            $new_total_cost = max(0, (float)$inventory_row['total_cost'] - $deduct_cost);
            $new_unit_cost = $new_inventory > 0 ? ($new_total_cost / $new_inventory) : $unit_cost;

            $update_query = "UPDATE item_unit_inventory
                             SET current_inventory = ?, unit_cost = ?, total_cost = ?, updated_at = NOW()
                             WHERE inventory_id = ?";
            $update_stmt = $conn->prepare($update_query);
            if (!$update_stmt) throw new Exception('Database prepare error');
            $update_stmt->bind_param('dddi', $new_inventory, $new_unit_cost, $new_total_cost, $inventory_row['inventory_id']);
            if (!$update_stmt->execute()) {
                throw new Exception('Failed to update stock');
            }
            $update_stmt->close();

            // Mirror stock to items.stock for display only.
            if (amgcColumnExists($conn, 'items', 'stock')) {
                $stock_stmt = $conn->prepare("UPDATE items SET stock = ?, updated_at = NOW() WHERE item_id = ?");
                if ($stock_stmt) {
                    $stock_stmt->bind_param('di', $new_inventory, $item_id);
                    $stock_stmt->execute();
                    $stock_stmt->close();
                }
            }

            $check_transaction_table = $conn->query("SHOW TABLES LIKE 'inventory_transactions'");
            if ($check_transaction_table && $check_transaction_table->num_rows > 0) {
                $dup_sql = "SELECT transaction_id FROM inventory_transactions WHERE item_id = ? AND reference_type = 'sales_order' AND reference_id = ? LIMIT 1";
                $already_logged = false;
                if ($dup_stmt = $conn->prepare($dup_sql)) {
                    $dup_stmt->bind_param('ii', $item_id, $so_id);
                    $dup_stmt->execute();
                    $already_logged = $dup_stmt->get_result()->num_rows > 0;
                    $dup_stmt->close();
                }

                if (!$already_logged) {
                    $qty_out = -abs($quantity_sold);
                    $total_out = -abs($deduct_cost);
                    $memo = 'Auto OUT from sales order using COGS/unit cost only';
                    $trans_query = "INSERT INTO inventory_transactions
                        (branch_id, item_id, transaction_type, quantity_changed, unit_cost, total_cost, reference_type, reference_id, receive_memo, created_by, created_at)
                        VALUES (?, ?, 'out', ?, ?, ?, 'sales_order', ?, ?, ?, NOW())";
                    $trans_stmt = $conn->prepare($trans_query);
                    if ($trans_stmt) {
                        $trans_stmt->bind_param('iidddisi', $branch_id, $item_id, $qty_out, $unit_cost, $total_out, $so_id, $memo, $user_id);
                        $trans_stmt->execute();
                        $trans_stmt->close();
                    }
                }
            }

            $conn->commit();
            echo json_encode(['success' => true, 'message' => 'Stock updated successfully']);
            exit;
        }
        
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('current_inventory.php fatal error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
        exit;
    }
}

// Receive Inventory already updates item_unit_inventory directly.
// Do NOT auto-sync inventory_transactions here, because it can add the same received quantity again on page load.
// syncReceivedInventoryTransactionsToUnitInventory($conn, (int)$branch_id, (int)$user_id, $items_branch_column_exists, (bool)$view_all_branches);

// Sync stock display from ledger before loading the inventory list.
// This fixes items like item_id 170 where 48 + 52 - 74 should remain 26, not 0.
// Disabled: do not recompute inventory on page load. This caused wrong stocks and duplicate rows.
// amgcSyncAllBranchStocksFromLedger($conn, (int)$branch_id, (bool)$view_all_branches);

// FETCH ALL ITEMS FROM items TABLE
// Only show inventory from item_unit_inventory table (which is synced from inventory_transactions)
// This ensures accurate stock count from received inventory
$items_query = "
    SELECT 
        i.item_id,
        i.item_code,
        i.barcode,
        i.item_name,
        i.description,
        i.category,
        COALESCE(NULLIF(TRIM(i.principal), ''), 'No Principal') as principal,
        COALESCE(inv_summary.total_inventory, 0) as quantity_on_hand,
        COALESCE(ut.unit_type_name, '') as unit_type,
        i.unit_price,
        i.price_case,
        i.price_inner_pack,
        i.price_box,
        i.price_carton,
        i.reorder_level,
        i.status,
        i.branch_id,
        i.created_at,
        i.updated_at,
        i.default_unit_type_id
    FROM items i
    LEFT JOIN unit_types ut ON i.default_unit_type_id = ut.unit_type_id
    LEFT JOIN (
        SELECT item_id, SUM(COALESCE(current_inventory, 0)) AS total_inventory
        FROM item_unit_inventory
        WHERE status = 'active'
        GROUP BY item_id
    ) inv_summary ON inv_summary.item_id = i.item_id
    WHERE 1=1
    $items_branch_condition
    ORDER BY i.category, i.item_name ASC
";

$items_result = $conn->query($items_query);
if (!$items_result) {
    error_log("Items Query Error: " . $conn->error);
    $items = [];
} else {
    $items = $items_result->fetch_all(MYSQLI_ASSOC);
    foreach ($items as &$item) {
        $item_id = $item['item_id'];
        $default_info = getItemDefaultUOMInfo($conn, $item_id);
        $default_unit_type = !empty($item['unit_type']) ? $item['unit_type'] : $default_info['unit_type_name'];
        $quantity_on_hand = (float)($item['quantity_on_hand'] ?? 0);
        $default_multiplier = $default_info['multiplier'];
        $item['default_uom_multiplier'] = $default_multiplier;
        $item['default_uom_name'] = $default_info['unit_type_name'];
        $item['stock_display'] = number_format($quantity_on_hand, 2);
        if (floor($quantity_on_hand) == $quantity_on_hand) {
            $item['stock_display'] = number_format($quantity_on_hand, 0);
        }
        $item['stock_display'] .= ' ' . htmlspecialchars($default_unit_type);
        error_log("[Stock Display] Item {$item_id}: Default UOM = {$default_unit_type}, Multiplier = {$default_multiplier}, Current Stock = {$quantity_on_hand}");
    }
    unset($item);
}
$total_value = 0;
$received_total_value = 0;
$cogs_total_value = 0;

$branch_value_filter = (!$view_all_branches && $branch_id > 0)
    ? " AND branch_id = " . intval($branch_id)
    : "";

/* TOTAL RECEIVED ONLY */
$received_query = "
    SELECT COALESCE(SUM(total_cost), 0) AS received_total
    FROM inventory_transactions
    WHERE LOWER(TRIM(transaction_type)) IN ('in','receive','received','return')
    AND COALESCE(total_cost, 0) > 0
    $branch_value_filter
";

$received_result = $conn->query($received_query);
if ($received_result && ($received_row = $received_result->fetch_assoc())) {
    $received_total_value = (float)($received_row['received_total'] ?? 0);
}

/* COGS ONLY - CONSISTENT AND NO DOUBLE COUNT */
$cogs_query = "
    SELECT COALESCE(SUM(order_cogs), 0) AS cogs_total
    FROM (
        SELECT
            so.so_id,

            CASE
                WHEN COALESCE(item_saved.item_cogs_total, 0) > 0
                    THEN COALESCE(item_saved.item_cogs_total, 0)

                WHEN COALESCE(so.cogs_amount, 0) > 0
                    THEN COALESCE(so.cogs_amount, 0)

                ELSE COALESCE(item_computed.computed_cogs_total, 0)
            END AS order_cogs

        FROM sales_orders so

        LEFT JOIN (
            SELECT 
                soi.so_id,
                SUM(COALESCE(soi.cogs_amount, 0)) AS item_cogs_total
            FROM sales_order_items soi
            GROUP BY soi.so_id
        ) item_saved ON item_saved.so_id = so.so_id

        LEFT JOIN (
            SELECT
                soi.so_id,
                SUM(
                    COALESCE(soi.quantity_ordered, 0) *
                    COALESCE(inv.unit_cost, 0)
                ) AS computed_cogs_total
            FROM sales_order_items soi
            LEFT JOIN unit_types ut 
                ON LOWER(TRIM(ut.unit_type_name)) = LOWER(TRIM(soi.unit_type))
            LEFT JOIN item_unit_inventory inv 
                ON inv.item_id = soi.item_id
                AND (
                    inv.unit_type_id = ut.unit_type_id
                    OR inv.unit_type_id IS NULL
                )
            GROUP BY soi.so_id
        ) item_computed ON item_computed.so_id = so.so_id

        WHERE LOWER(COALESCE(so.order_status, '')) <> 'cancelled'
";

if (!$view_all_branches && $branch_id > 0 && amgcColumnExists($conn, 'sales_orders', 'branch_id')) {
    $cogs_query .= " AND so.branch_id = " . intval($branch_id);
}

$cogs_query .= "
    ) final_cogs
";

$cogs_result = $conn->query($cogs_query);
if ($cogs_result && ($cogs_row = $cogs_result->fetch_assoc())) {
    $cogs_total_value = (float)($cogs_row['cogs_total'] ?? 0);
}

$total_value = $received_total_value - $cogs_total_value;

if (!is_numeric($total_value) || $total_value < 0) {
    $total_value = 0;
}

$total_stock = array_sum(array_column($items, 'quantity_on_hand'));
$statInventoryValue = '₱' . number_format($total_value, 2);
$items_by_category = [];
$unique_categories = [];
foreach ($items as $item) {
    $category = $item['category'] ?? 'Uncategorized';
    if (!isset($items_by_category[$category])) {
        $items_by_category[$category] = [];
        $unique_categories[] = $category;
    }
    $items_by_category[$category][] = $item;
}

$principalOptions = [];
$principal_query = "SELECT DISTINCT TRIM(i.principal) AS principal FROM items i WHERE i.status <> 'deleted' AND i.principal IS NOT NULL AND TRIM(i.principal) <> '' $items_branch_condition ORDER BY principal ASC";
$principal_result = $conn->query($principal_query);
if ($principal_result) {
    while ($principal_row = $principal_result->fetch_assoc()) {
        if (!empty($principal_row['principal'])) {
            $principalOptions[] = $principal_row['principal'];
        }
    }
}

$supplier_items_query = "
    SELECT DISTINCT
        s.supplier_id,
        s.supplier_name,
        i.item_id,
        i.item_code,
        i.barcode,
        i.item_name,
        i.description,
        i.category,
        COALESCE(NULLIF(TRIM(i.principal), ''), 'No Principal') as principal,
        COALESCE(inv.current_inventory, 0) as quantity_on_hand,
        COALESCE(ut.unit_type_name, '') as unit_type,
        i.reorder_level,
        i.status,
        i.branch_id
    FROM suppliers s
    JOIN purchase_orders po ON s.supplier_id = po.supplier_id
    JOIN purchase_order_items poi ON po.po_id = poi.po_id
    JOIN items i ON poi.item_id = i.item_id
    LEFT JOIN unit_types ut ON i.default_unit_type_id = ut.unit_type_id
    LEFT JOIN item_unit_inventory inv ON inv.item_id = i.item_id AND inv.unit_type_id = i.default_unit_type_id
    WHERE s.status = 'active' AND i.status = 'active'
";
if ($items_branch_column_exists && !$view_all_branches && $branch_id > 0) {
    $supplier_items_query .= " AND i.branch_id = " . intval($branch_id);
}
$supplier_items_query .= " GROUP BY i.item_id, s.supplier_id ORDER BY s.supplier_name, i.item_name";
$supplier_items_result = $conn->query($supplier_items_query);
if (!$supplier_items_result) {
    error_log("Supplier Items Query Error: " . $conn->error);
    $supplier_items = [];
    $items_by_supplier = [];
} else {
    $supplier_items = $supplier_items_result->fetch_all(MYSQLI_ASSOC);
    foreach ($supplier_items as &$item) {
        $item_id = $item['item_id'];
        $default_unit_type = $item['unit_type'];
        $quantity_on_hand = $item['quantity_on_hand'];
        $default_info = getItemDefaultUOMInfo($conn, $item_id);
        $default_multiplier = $default_info['multiplier'];
        $item['default_uom_multiplier'] = $default_multiplier;
        $item['default_uom_name'] = $default_info['unit_type_name'];
        $item['stock_display'] = number_format($quantity_on_hand) . ' ' . htmlspecialchars($default_unit_type);
    }
    unset($item);
    $items_by_supplier = [];
    foreach ($supplier_items as $item) {
        $supplier = $item['supplier_name'] ?? 'Unknown Supplier';
        if (!isset($items_by_supplier[$supplier])) {
            $items_by_supplier[$supplier] = [];
        }
        $items_by_supplier[$supplier][] = $item;
    }
}

$next_number = 1;
if (!empty($items)) {
    $numbers = [];
    foreach ($items as $item) {
        if (preg_match('/ITEM(\d+)/', $item['item_code'], $matches)) {
            $numbers[] = intval($matches[1]);
        }
    }
    if (!empty($numbers)) {
        $next_number = max($numbers) + 1;
    }
}
$next_item_code = 'ITEM' . str_pad($next_number, 3, '0', STR_PAD_LEFT);

$total_items = count($items);
// Calculate low stock and out of stock counts using item_unit_inventory
$low_stock_items = array_filter($items, function($item) {
    return $item['quantity_on_hand'] <= $item['reorder_level'] && $item['quantity_on_hand'] > 0;
});
$low_stock_count = count($low_stock_items);
$out_of_stock = count(array_filter($items, function($item) {
    return $item['quantity_on_hand'] <= 0;
}));
$unique_categories_count = count(array_unique(array_column($items, 'category')));
$suppliers_count = count(array_unique(array_column($supplier_items, 'supplier_name')));
$total_items_count = count($items);
$avg_per_item = $total_items_count > 0 ? round($avg_daily_offtake / $total_items_count, 1) : 0;
$statNeedsAttention = $low_stock_count + $out_of_stock;

function getStockStatus($stock, $reorder_level) {
    if ($stock <= 0) return ['label' => 'Out of Stock', 'class' => 'bg-danger text-white'];
    if ($stock <= $reorder_level) return ['label' => 'Low Stock', 'class' => 'bg-warning text-dark'];
    if ($stock <= $reorder_level * 2) return ['label' => 'Normal', 'class' => 'bg-info text-white'];
    return ['label' => 'Adequate', 'class' => 'bg-success text-white'];
}

function getItemImagesHtml($item_id) {
    global $conn;
    $images_html = '';
    $images_query = "SELECT image_path FROM item_images WHERE item_id = ? ORDER BY is_primary DESC, image_order ASC LIMIT 1";
    $stmt = $conn->prepare($images_query);
    if ($stmt) {
        $stmt->bind_param("i", $item_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $images_html = '<img src="../uploads/products/' . htmlspecialchars($row['image_path']) . '" alt="Item Image" style="display: block; width: 60px; height: 60px; object-fit: cover;" onerror="this.onerror=null; this.parentNode.innerHTML=\'<i class=\\\'bi bi-image\\\'></i>\';">';
        } else {
            $images_html = '<i class="bi bi-image"></i>';
        }
        $stmt->close();
    } else {
        $images_html = '<i class="bi bi-image"></i>';
    }
    return $images_html;
}

// ================= SYSTEM-WIDE TASK TABLE MODAL =================
$system_tasks = [];
$show_system_task_modal = false;

if (!function_exists('amgcTaskTableExists')) {
    function amgcTaskTableExists($conn, $table) {
        $table = $conn->real_escape_string($table);
        $res = $conn->query("SHOW TABLES LIKE '$table'");
        return ($res && $res->num_rows > 0);
    }
}

if (!function_exists('amgcTaskColumnExists')) {
    function amgcTaskColumnExists($conn, $table, $column) {
        $table = $conn->real_escape_string($table);
        $column = $conn->real_escape_string($column);
        $res = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
        return ($res && $res->num_rows > 0);
    }
}

if (!function_exists('amgcAddSystemTask')) {
    function amgcAddSystemTask(&$system_tasks, $type, $reference, $description, $page, $param, $id, $status = 'Pending') {
        if (empty($id)) return;
        $system_tasks[] = [
            'type' => $type,
            'reference' => $reference,
            'description' => $description,
            'page' => $page,
            'param' => $param,
            'id' => (int)$id,
            'status' => $status
        ];
    }
}

try {
    if (amgcTaskTableExists($conn, 'sales_orders') && amgcTaskColumnExists($conn, 'sales_orders', 'so_id')) {
        $so_number_select = amgcTaskColumnExists($conn, 'sales_orders', 'so_number') ? 'so.so_number' : "CONCAT('SO #', so.so_id)";
        $so_status_col = amgcTaskColumnExists($conn, 'sales_orders', 'order_status') ? 'so.order_status' : "'pending'";
        $so_branch_filter = '';
        if (!$view_all_branches && $branch_id > 0 && amgcTaskColumnExists($conn, 'sales_orders', 'branch_id')) {
            $so_branch_filter = ' AND so.branch_id = ' . intval($branch_id);
        }
        $customer_join = '';
        $customer_select = "'' AS customer_name";
        if (amgcTaskTableExists($conn, 'customers') && amgcTaskColumnExists($conn, 'sales_orders', 'customer_id') && amgcTaskColumnExists($conn, 'customers', 'customer_id')) {
            $customer_join = ' LEFT JOIN customers c ON so.customer_id = c.customer_id ';
            $customer_select = amgcTaskColumnExists($conn, 'customers', 'customer_name') ? "COALESCE(c.customer_name, 'Customer') AS customer_name" : "'Customer' AS customer_name";
        }
        $q = "SELECT so.so_id, $so_number_select AS reference_no, $customer_select, $so_status_col AS task_status FROM sales_orders so $customer_join WHERE LOWER(TRIM($so_status_col)) IN ('pending', 'for approval', 'processing') $so_branch_filter ORDER BY so.so_id DESC LIMIT 5";
        $r = $conn->query($q);
        if ($r) {
            while ($row = $r->fetch_assoc()) {
                amgcAddSystemTask($system_tasks, 'Sales Order', $row['reference_no'] ?? ('SO #' . $row['so_id']), ($row['customer_name'] ?? 'Customer') . ' needs sales order action.', 'sales_order.php', 'open_so', $row['so_id'], ucfirst($row['task_status'] ?? 'Pending'));
            }
        }
    }

    if (amgcTaskTableExists($conn, 'sales_orders') && amgcTaskColumnExists($conn, 'sales_orders', 'so_id') && amgcTaskColumnExists($conn, 'sales_orders', 'order_status')) {
        $so_number_select = amgcTaskColumnExists($conn, 'sales_orders', 'so_number') ? 'so.so_number' : "CONCAT('SO #', so.so_id)";
        $payment_condition = '1=1';
        if (amgcTaskColumnExists($conn, 'sales_orders', 'payment_status')) {
            $payment_condition = "(so.payment_status IS NULL OR LOWER(TRIM(so.payment_status)) NOT IN ('paid', 'fully paid'))";
        } elseif (amgcTaskColumnExists($conn, 'sales_orders', 'paid_status')) {
            $payment_condition = "(so.paid_status IS NULL OR LOWER(TRIM(so.paid_status)) NOT IN ('paid', 'fully paid'))";
        }
        $so_branch_filter = '';
        if (!$view_all_branches && $branch_id > 0 && amgcTaskColumnExists($conn, 'sales_orders', 'branch_id')) {
            $so_branch_filter = ' AND so.branch_id = ' . intval($branch_id);
        }
        $customer_join = '';
        $customer_select = "'' AS customer_name";
        if (amgcTaskTableExists($conn, 'customers') && amgcTaskColumnExists($conn, 'sales_orders', 'customer_id') && amgcTaskColumnExists($conn, 'customers', 'customer_id')) {
            $customer_join = ' LEFT JOIN customers c ON so.customer_id = c.customer_id ';
            $customer_select = amgcTaskColumnExists($conn, 'customers', 'customer_name') ? "COALESCE(c.customer_name, 'Customer') AS customer_name" : "'Customer' AS customer_name";
        }
        $q = "SELECT so.so_id, $so_number_select AS reference_no, $customer_select FROM sales_orders so $customer_join WHERE LOWER(TRIM(so.order_status)) = 'delivered' AND $payment_condition $so_branch_filter ORDER BY so.so_id DESC LIMIT 5";
        $r = $conn->query($q);
        if ($r) {
            while ($row = $r->fetch_assoc()) {
                amgcAddSystemTask($system_tasks, 'Collection / Remit', $row['reference_no'] ?? ('SO #' . $row['so_id']), ($row['customer_name'] ?? 'Customer') . ' has delivered order that needs collection/remit.', 'collections.php', 'open_collection', $row['so_id'], 'Unpaid');
            }
        }
    }

    if (amgcTaskTableExists($conn, 'return_merchandise_requests') && amgcTaskColumnExists($conn, 'return_merchandise_requests', 'rmr_id')) {
        $rmr_status_col = amgcTaskColumnExists($conn, 'return_merchandise_requests', 'status') ? 'status' : "'pending'";
        $rmr_no_col = amgcTaskColumnExists($conn, 'return_merchandise_requests', 'rmr_number') ? 'rmr_number' : "CONCAT('RMR #', rmr_id)";
        $rmr_branch_filter = '';
        if (!$view_all_branches && $branch_id > 0 && amgcTaskColumnExists($conn, 'return_merchandise_requests', 'branch_id')) {
            $rmr_branch_filter = ' AND branch_id = ' . intval($branch_id);
        }
        $q = "SELECT rmr_id, $rmr_no_col AS reference_no, $rmr_status_col AS task_status FROM return_merchandise_requests WHERE LOWER(TRIM($rmr_status_col)) IN ('pending', 'for approval', 'requested') $rmr_branch_filter ORDER BY rmr_id DESC LIMIT 5";
        $r = $conn->query($q);
        if ($r) {
            while ($row = $r->fetch_assoc()) {
                amgcAddSystemTask($system_tasks, 'Bad Order / RMR', $row['reference_no'] ?? ('RMR #' . $row['rmr_id']), 'Returned merchandise request needs review.', 'bad_orders.php', 'open_rmr', $row['rmr_id'], ucfirst($row['task_status'] ?? 'Pending'));
            }
        }
    } elseif (amgcTaskTableExists($conn, 'bad_orders') && amgcTaskColumnExists($conn, 'bad_orders', 'bad_order_id')) {
        $bo_status_col = amgcTaskColumnExists($conn, 'bad_orders', 'status') ? 'status' : "'pending'";
        $bo_no_col = amgcTaskColumnExists($conn, 'bad_orders', 'bad_order_number') ? 'bad_order_number' : "CONCAT('Bad Order #', bad_order_id)";
        $bo_branch_filter = '';
        if (!$view_all_branches && $branch_id > 0 && amgcTaskColumnExists($conn, 'bad_orders', 'branch_id')) {
            $bo_branch_filter = ' AND branch_id = ' . intval($branch_id);
        }
        $q = "SELECT bad_order_id, $bo_no_col AS reference_no, $bo_status_col AS task_status FROM bad_orders WHERE LOWER(TRIM($bo_status_col)) IN ('pending', 'for approval', 'requested') $bo_branch_filter ORDER BY bad_order_id DESC LIMIT 5";
        $r = $conn->query($q);
        if ($r) {
            while ($row = $r->fetch_assoc()) {
                amgcAddSystemTask($system_tasks, 'Bad Order', $row['reference_no'] ?? ('Bad Order #' . $row['bad_order_id']), 'Bad order needs review.', 'bad_orders.php', 'open_bad_order', $row['bad_order_id'], ucfirst($row['task_status'] ?? 'Pending'));
            }
        }
    }

    if (amgcTaskTableExists($conn, 'purchase_orders') && amgcTaskColumnExists($conn, 'purchase_orders', 'po_id')) {
        $po_status_col = amgcTaskColumnExists($conn, 'purchase_orders', 'status') ? 'po.status' : "'pending'";
        $po_no_col = amgcTaskColumnExists($conn, 'purchase_orders', 'po_number') ? 'po.po_number' : "CONCAT('PO #', po.po_id)";
        $po_branch_filter = '';
        if (!$view_all_branches && $branch_id > 0 && amgcTaskColumnExists($conn, 'purchase_orders', 'branch_id')) {
            $po_branch_filter = ' AND po.branch_id = ' . intval($branch_id);
        }
        $supplier_join = '';
        $supplier_select = "'' AS supplier_name";
        if (amgcTaskTableExists($conn, 'suppliers') && amgcTaskColumnExists($conn, 'purchase_orders', 'supplier_id') && amgcTaskColumnExists($conn, 'suppliers', 'supplier_id')) {
            $supplier_join = ' LEFT JOIN suppliers s ON po.supplier_id = s.supplier_id ';
            $supplier_select = amgcTaskColumnExists($conn, 'suppliers', 'supplier_name') ? "COALESCE(s.supplier_name, 'Supplier') AS supplier_name" : "'Supplier' AS supplier_name";
        }
        $q = "SELECT po.po_id, $po_no_col AS reference_no, $supplier_select, $po_status_col AS task_status FROM purchase_orders po $supplier_join WHERE LOWER(TRIM($po_status_col)) IN ('pending', 'approved', 'partial', 'for receiving') $po_branch_filter ORDER BY po.po_id DESC LIMIT 5";
        $r = $conn->query($q);
        if ($r) {
            while ($row = $r->fetch_assoc()) {
                amgcAddSystemTask($system_tasks, 'Purchase Order', $row['reference_no'] ?? ('PO #' . $row['po_id']), ($row['supplier_name'] ?? 'Supplier') . ' purchase order needs receiving/action.', 'purchase_order.php', 'open_po', $row['po_id'], ucfirst($row['task_status'] ?? 'Pending'));
            }
        }
    }

    $show_system_task_modal = !empty($system_tasks);
} catch (Throwable $e) {
    error_log('System task table modal error: ' . $e->getMessage());
    $system_tasks = [];
    $show_system_task_modal = false;
}
// ================= END SYSTEM-WIDE TASK TABLE MODAL =================
$check_barcode_column = $conn->query("SHOW COLUMNS FROM items LIKE 'barcode'");
if (!$check_barcode_column || $check_barcode_column->num_rows == 0) {
    $conn->query("
        ALTER TABLE items
        ADD COLUMN barcode VARCHAR(100) DEFAULT NULL
        AFTER item_code
    ");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Current Inventory - Branch Admin</title>
    <link rel="icon" type="image/png" href="../Pictures/favicon-96x96.png" sizes="96x96" />
    <link rel="stylesheet" href="../css/current_inventory.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@100;200;300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
    <script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
        <!-- Session Checker -->
    <script src="../js/session-checker.js"></script>
    
    <style>
        /* Mobile responsive adjustments */
        @media (max-width: 768px) {
            .stat-card { padding: 12px; min-height: 85px; margin-bottom: 8px; }
            .stat-icon { font-size: 2rem; margin-right: 12px; }
            .stat-value { font-size: 1.5rem; }
            .stat-label { font-size: 0.8rem; }
            .col-md-3, .col-md-4, .col-md-6 { width: 50%; padding-left: 8px; padding-right: 8px; }
            .row.g-3 { margin-left: -8px; margin-right: -8px; }
            .category-tabs { flex-wrap: nowrap; overflow-x: auto; -webkit-overflow-scrolling: touch; padding-bottom: 10px; }
            .category-tab { white-space: nowrap; }
        }
        
        .stat-card.clickable { cursor: pointer; transition: transform 0.2s, box-shadow 0.2s; }
        .stat-card.clickable:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
        
        .item-thumbnail {
            width: 50px; height: 50px; object-fit: cover; border-radius: 8px;
            border: 1px solid #dee2e6; background-color: #f8f9fa;
            display: flex; align-items: center; justify-content: center; overflow: hidden;
        }
        .item-thumbnail i { font-size: 24px; color: #adb5bd; }
        .item-thumbnail img { width: 100%; height: 100%; object-fit: cover; border-radius: 8px; }
        
        .status-toggle { display: flex; justify-content: center; align-items: center; }
        .toggle-switch { position: relative; display: inline-block; width: 46px; height: 22px; margin: 0; }
        .toggle-switch input { opacity: 0; width: 0; height: 0; }
        .toggle-slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #dc3545; transition: .3s; border-radius: 22px; }
        .toggle-slider:before { position: absolute; content: ""; height: 18px; width: 18px; left: 2px; bottom: 2px; background-color: white; transition: .3s; border-radius: 50%; }
        input:checked + .toggle-slider { background-color: #28a745; }
        input:checked + .toggle-slider:before { transform: translateX(24px); }
        
        .view-toggle { display: flex; gap: 5px; margin-left: 15px; }
        .view-btn { padding: 6px 12px; border: 1px solid #dee2e6; background-color: white; border-radius: 6px; color: #6c757d; cursor: pointer; transition: all 0.2s; }
        .view-btn.active { background-color: #2E7D32; border-color: #2E7D32; color: white; }
        
        .category-tabs { border-bottom: 2px solid #dee2e6; margin-bottom: 20px; padding-bottom: 5px; display: flex; flex-wrap: wrap; gap: 5px; }
        .category-tab { display: inline-flex; align-items: center; padding: 10px 20px; background-color: #f8f9fa; border: 1px solid #dee2e6; border-bottom: none; border-radius: 8px 8px 0 0; cursor: pointer; font-weight: 500; color: #495057; transition: all 0.2s; }
        .category-tab.active { background-color: #2E7D32; color: white; border-color: #2E7D32; }
        .tab-content { display: none; }
        .tab-content.active { display: block; }
        
        .offtake-card { background: linear-gradient(135deg, #9C27B0, #7B1FA2); }
        .offtake-summary-card { background-color: #f8f9fa; border-radius: 12px; padding: 20px; text-align: center; border-left: 4px solid #9C27B0; height: 100%; }
        .offtake-summary-value { font-size: 2rem; font-weight: 700; color: #212529; line-height: 1.2; }
        .offtake-summary-label { font-size: 0.85rem; color: #6c757d; text-transform: uppercase; letter-spacing: 0.5px; }
        
        #printFrame { position: absolute; left: -9999px; top: -9999px; width: 1px; height: 1px; opacity: 0; pointer-events: none; }
        
        @media print {
            @page { size: landscape; margin: 0.3in; }
            body * { visibility: hidden; background: white !important; color: black !important; border-color: black !important; }
            #printFrame, #printFrame * { visibility: visible; }
            #printFrame { position: absolute; left: 0; top: 0; width: 100%; height: auto; border: none; }
            #printFrame img { filter: none !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
        
        /* ===== DROPDOWN FIX - FINAL ===== */
        @media (max-width: 992px) {
            .dropdown-more {
                position: relative !important;
                overflow: visible !important;
            }
            
            .more-dropdown {
                position: absolute !important;
                bottom: 100% !important;
                background: white !important;
                border-radius: 10px !important;
                box-shadow: 0 4px 15px rgba(0,0,0,0.15) !important;
                border: 1px solid #e9ecef !important;
                min-width: 150px !important;
                margin-bottom: 8px !important;
                z-index: 9999 !important;
                display: none !important;
                opacity: 0 !important;
                transition: opacity 0.2s ease, transform 0.2s ease !important;
            }
            
            .more-dropdown.show {
                display: block !important;
                opacity: 1 !important;
                transform: translateY(0) !important;
            }
            
            #inventoryDropdown .more-dropdown {
                right: auto !important;
                left: 0 !important;
                transform: translateY(-5px) !important;
            }
            
            #inventoryDropdown .more-dropdown::before {
                content: '' !important;
                position: absolute !important;
                bottom: -6px !important;
                left: 15px !important;
                right: auto !important;
                width: 10px !important;
                height: 10px !important;
                background: white !important;
                border-right: 1px solid #e9ecef !important;
                border-bottom: 1px solid #e9ecef !important;
                transform: rotate(45deg) !important;
            }
            
            #salesDropdown .more-dropdown {
                right: auto !important;
                left: 0 !important;
                transform: translateY(-5px) !important;
            }
            
            #salesDropdown .more-dropdown::before {
                content: '' !important;
                position: absolute !important;
                bottom: -6px !important;
                left: 15px !important;
                right: auto !important;
                width: 10px !important;
                height: 10px !important;
                background: white !important;
                border-right: 1px solid #e9ecef !important;
                border-bottom: 1px solid #e9ecef !important;
                transform: rotate(45deg) !important;
            }
            
            #purchaseDropdown .more-dropdown {
                right: 0 !important;
                left: auto !important;
                transform: translateY(-5px) !important;
            }
            
            #purchaseDropdown .more-dropdown::before {
                content: '' !important;
                position: absolute !important;
                bottom: -6px !important;
                right: 15px !important;
                left: auto !important;
                width: 10px !important;
                height: 10px !important;
                background: white !important;
                border-right: 1px solid #e9ecef !important;
                border-bottom: 1px solid #e9ecef !important;
                transform: rotate(45deg) !important;
            }
            
            #moreDropdown .more-dropdown {
                right: 0 !important;
                left: auto !important;
                transform: translateY(-5px) !important;
            }
            
            #moreDropdown .more-dropdown::before {
                content: '' !important;
                position: absolute !important;
                bottom: -6px !important;
                right: 15px !important;
                left: auto !important;
                width: 10px !important;
                height: 10px !important;
                background: white !important;
                border-right: 1px solid #e9ecef !important;
                border-bottom: 1px solid #e9ecef !important;
                transform: rotate(45deg) !important;
            }
            
            .more-dropdown .dropdown-item {
                display: flex !important;
                align-items: center !important;
                gap: 10px !important;
                padding: 10px 14px !important;
                color: #374151 !important;
                text-decoration: none !important;
                transition: background 0.2s ease !important;
                border-bottom: 1px solid #f0f0f0 !important;
                font-size: 0.8rem !important;
            }
            
            .more-dropdown .dropdown-item:last-child {
                border-bottom: none !important;
            }
            
            .more-dropdown .dropdown-item:hover {
                background: #f9fafb !important;
            }
            
            .more-dropdown .dropdown-item i {
                width: 18px !important;
                font-size: 0.9rem !important;
                color: #6c757d !important;
            }
            
            .more-dropdown .dropdown-item span {
                font-size: 0.8rem !important;
                font-weight: 500 !important;
            }
            
            .more-dropdown .dropdown-divider {
                height: 1px !important;
                background: #e9ecef !important;
                margin: 4px 0 !important;
            }
            
            .more-dropdown .logout-item {
                color: #dc3545 !important;
            }
            
            .more-dropdown .logout-item i {
                color: #dc3545 !important;
            }
            
            .more-dropdown .logout-item:hover {
                background: #fef2f2 !important;
            }
            
            @media (max-width: 480px) {
                .more-dropdown {
                    min-width: 135px !important;
                }
                
                #purchaseDropdown .more-dropdown::before,
                #moreDropdown .more-dropdown::before {
                    right: 12px !important;
                }
            }
        }
        
         /* ===== SUPER RESPONSIVE STAT CARDS - HORIZONTAL SCROLL ON MOBILE ===== */

        /* Base styles - mobile first approach */
        .stat-card {
            border: none;
            border-radius: 12px;
            color: white;
            padding: 0.8rem;
            margin: 0;
            transition: all 0.3s ease;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.08);
            height: 100%;
            width: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            cursor: pointer;
        }

        .stat-card.clickable:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.15);
        }

        /* Stat Card Colors */
        .stat-card.inventory { 
            background: linear-gradient(135deg, #047857, #059669) !important;
        }
        .stat-card.stock { 
            background: linear-gradient(135deg, #047857, #059669) !important;
        }
        .stat-card.delivery { 
            background: linear-gradient(135deg, #047857, #059669) !important;
        }
        .stat-card.pending { 
            background: linear-gradient(135deg, #047857, #059669) !important;
        }
        .stat-card.total { 
            background: linear-gradient(135deg, #047857, #059669) !important;
        }
        .stat-card.offtake-card { 
            background: linear-gradient(135deg, #047857, #059669) !important;
        }
        .stat-card.sales { 
            background: linear-gradient(135deg, #047857, #059669) !important;
        }

        /* Fluid typography */
        .stat-card i {
            font-size: clamp(1.2rem, 5vw, 2rem);
            margin-bottom: 0.25rem;
        }

        .stat-value {
            font-size: clamp(0.9rem, 4vw, 1.6rem);
            font-weight: 700;
            line-height: 1.2;
            margin: 0.1rem 0;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 100%;
        }

        .stat-label {
            font-size: clamp(0.6rem, 2.8vw, 0.8rem);
            font-weight: 500;
            opacity: 0.95;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 100%;
        }

        .stat-card small {
            font-size: clamp(0.45rem, 2vw, 0.65rem);
            opacity: 0.85;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            margin-top: 0.25rem;
        }

        .stat-card small i {
            font-size: clamp(0.45rem, 2vw, 0.65rem);
            margin-right: 3px;
        }

        /* ===== FOR STAT-CARD-ROW - HORIZONTAL SCROLL ON MOBILE ===== */
        .row.stat-card-row {
            display: flex;
            flex-wrap: nowrap;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            margin-right: -6px;
            margin-left: -6px;
            margin-bottom: 1.5rem;
            padding-bottom: 4px;
            scrollbar-width: thin;
        }

        .row.stat-card-row::-webkit-scrollbar {
            height: 4px;
        }

        .row.stat-card-row::-webkit-scrollbar-track {
            background: #e2e8f0;
            border-radius: 10px;
        }

        .row.stat-card-row::-webkit-scrollbar-thumb {
            background: var(--primary-green);
            border-radius: 10px;
        }

        .row.stat-card-row > .col,
        .row.stat-card-row > [class*="col-"] {
            flex: 0 0 auto;
            padding-right: 6px;
            padding-left: 6px;
            margin-bottom: 0;
        }

        @media (max-width: 991px) {
            .row.stat-card-row {
                flex-wrap: nowrap;
                overflow-x: auto;
                margin-right: -8px;
                margin-left: -8px;
                padding-bottom: 8px;
            }
            
            .row.stat-card-row > .col,
            .row.stat-card-row > [class*="col-"] {
                flex: 0 0 calc(25% - 12px);
                min-width: 120px;
                max-width: 160px;
                padding-right: 6px;
                padding-left: 6px;
            }
            
            .stat-card {
                padding: 0.6rem;
            }
            
            .stat-card i {
                font-size: 1.2rem;
                margin-bottom: 0.2rem;
            }
            
            .stat-value {
                font-size: 1rem;
            }
            
            .stat-label {
                font-size: 0.6rem;
            }
            
            .stat-card small {
                font-size: 0.45rem;
                display: none;
            }
        }

        @media (min-width: 992px) {
            .row.stat-card-row {
                flex-wrap: wrap;
                overflow-x: visible;
                margin-right: -8px;
                margin-left: -8px;
            }
            
            .row.stat-card-row > .col,
            .row.stat-card-row > [class*="col-"] {
                flex: 0 0 25%;
                max-width: 25%;
                padding-right: 8px;
                padding-left: 8px;
                margin-bottom: 16px;
            }
            
            .stat-card {
                align-items: flex-start !important;
                text-align: left !important;
                padding: 1rem;
                min-height: 100px;
                max-height: 130px;
            }
            
            .stat-card i {
                align-self: flex-start;
                margin-bottom: 0.15rem;
                font-size: 1.6rem;
            }
            
            .stat-value {
                align-self: flex-start;
                margin: 0.05rem 0;
                font-size: 1.4rem;
                line-height: 1.1;
            }
            
            .stat-label {
                align-self: flex-start;
                font-size: 0.75rem;
                margin-top: 0.1rem;
            }
            
            .stat-card small {
                align-self: flex-start;
                font-size: 0.65rem;
                margin-top: 0.2rem;
                display: block;
            }
        }

        @media (max-width: 399px) {
            .row.stat-card-row > .col,
            .row.stat-card-row > [class*="col-"] {
                flex: 0 0 calc(25% - 8px);
                min-width: 90px;
                padding-right: 4px;
                padding-left: 4px;
            }
            
            .stat-card {
                padding: 0.4rem;
            }
            
            .stat-card i { 
                font-size: 0.9rem; 
                margin-bottom: 0.1rem;
            }
            
            .stat-value { 
                font-size: 0.7rem; 
            }
            
            .stat-label { 
                font-size: 0.45rem; 
            }
        }

        @media (max-height: 500px) and (orientation: landscape) {
            .row.stat-card-row > .col,
            .row.stat-card-row > [class*="col-"] {
                flex: 0 0 calc(25% - 8px);
                min-width: 100px;
            }
            
            .stat-card {
                padding: 0.3rem;
                min-height: 55px;
                max-height: 70px;
            }
            
            .stat-card i {
                font-size: 0.9rem;
                margin-bottom: 0.05rem;
            }
            
            .stat-value {
                font-size: 0.7rem;
            }
            
            .stat-label {
                font-size: 0.45rem;
            }
            
            .stat-card small {
                display: none;
            }
        }


        /* ========== MOBILE CARD VIEW STYLES ========== */
        @media (max-width: 768px) {
            .table-container thead {
                display: none;
            }
            
            .table-container tbody tr {
                display: block;
                background: white;
                border-radius: 16px;
                margin-bottom: 16px;
                padding: 16px;
                box-shadow: 0 2px 8px rgba(0,0,0,0.08);
                border: 1px solid #e9ecef;
                position: relative;
                cursor: pointer;
            }
            
            .table-container tbody tr td {
                display: flex;
                align-items: center;
                justify-content: space-between;
                padding: 8px 0;
                border: none;
                gap: 12px;
            }
            
            .table-container tbody tr td:first-child {
                justify-content: center;
                margin-bottom: 12px;
                padding-bottom: 12px;
                border-bottom: 1px solid #e9ecef;
            }
            
            .item-thumbnail {
                width: 80px;
                height: 80px;
            }
            
            .table-container tbody tr td:nth-child(2) {
                flex-direction: column;
                align-items: flex-start;
                justify-content: flex-start;
                margin-bottom: 12px;
                padding-bottom: 8px;
                border-bottom: 1px solid #e9ecef;
            }
            
            .table-container tbody tr td:last-child {
                position: absolute;
                top: 16px;
                right: 16px;
                padding: 0;
                width: auto;
                gap: 8px;
            }
            
            .btn-action {
                width: 32px;
                height: 32px;
                padding: 0;
                border-radius: 8px;
                display: inline-flex;
                align-items: center;
                justify-content: center;
            }
        }
        
        /* Filter collapsible styles */
        .filter-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            cursor: pointer;
            padding: 12px 0;
        }
        
        .filter-toggle-btn {
            background: none;
            border: none;
            font-size: 1.2rem;
            cursor: pointer;
            color: #6c757d;
        }
        
        .filter-content {
            transition: all 0.3s ease;
            overflow: hidden;
        }
        
        .filter-content.collapsed {
            display: none;
        }
        
        .form-card {
            background: white;
            border-radius: 12px;
            padding: 16px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            margin-bottom: 20px;
        }
        
        /* Unit Types display in table */
        .unit-types-display {
            font-size: 0.85rem;
            color: #495057;
        }
        .unit-type-badge {
            background: #e9ecef;
            padding: 2px 6px;
            border-radius: 12px;
            font-size: 0.7rem;
            margin-right: 4px;
            margin-bottom: 2px;
            display: inline-block;
        }
        
        
        /* Unit type status toggle styles */
        .unit-status-toggle {
            display: flex;
            justify-content: center;
            align-items: center;
        }
        .unit-toggle-switch {
            position: relative;
            display: inline-block;
            width: 40px;
            height: 20px;
            margin: 0;
        }
        .unit-toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        .unit-toggle-slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #dc3545;
            transition: .3s;
            border-radius: 20px;
        }
        .unit-toggle-slider:before {
            position: absolute;
            content: "";
            height: 16px;
            width: 16px;
            left: 2px;
            bottom: 2px;
            background-color: white;
            transition: .3s;
            border-radius: 50%;
        }
        input:checked + .unit-toggle-slider {
            background-color: #28a745;
        }
        input:checked + .unit-toggle-slider:before {
            transform: translateX(20px);
        }
        
        /* Item view modal styles */
        .item-details-container {
            padding: 20px;
        }
        .item-images-carousel {
            display: flex;
            gap: 10px;
            overflow-x: auto;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }
        .item-image-thumb {
            width: 80px;
            height: 80px;
            object-fit: cover;
            border-radius: 8px;
            cursor: pointer;
            border: 2px solid transparent;
        }
        .item-image-thumb.active {
            border-color: #2E7D32;
        }
        .main-image {
            width: 100%;
            max-height: 300px;
            object-fit: contain;
            border-radius: 12px;
            margin-bottom: 15px;
        }
        .detail-section {
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid #e9ecef;
        }
        .detail-section h6 {
            color: #2E7D32;
            font-weight: 600;
            margin-bottom: 12px;
        }
        .info-label {
            color: #6c757d;
            font-weight: 500;
            width: 120px;
        }
        .info-value {
            color: #212529;
        }
        .status-badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
        }
        .status-active {
            background: #d4edda;
            color: #155724;
        }
        .status-inactive {
            background: #fff3cd;
            color: #856404;
        }
        .status-discontinued {
            background: #f8d7da;
            color: #721c24;
        }
        .price-value {
            font-weight: 600;
            color: #2E7D32;
        }
        .stock-value.low-stock {
            color: #f59e0b;
            font-weight: 600;
        }
        .stock-value.out-of-stock {
            color: #dc3545;
            font-weight: 600;
        }
        
        /* Loading overlay */
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 9999;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .loading-spinner {
            background: white;
            padding: 20px 30px;
            border-radius: 12px;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
        }
        .loading-spinner .spinner-border {
            width: 40px;
            height: 40px;
        }
        
        /* Unit type status toggle in edit modal */
        .unit-status-toggle-cell {
            text-align: center;
            vertical-align: middle;
        }
        /* FORCE FILTER TO WORK ON MOBILE - CRITICAL FIX */
@media (max-width: 768px) {
    /* Ensure hidden rows are completely hidden */
    .table-container tbody tr.inventory-row[style*="display: none"],
    .table-container tbody tr.inventory-row[style*="display:none"] {
        display: none !important;
    }
    
    /* Ensure visible rows display as block */
    .table-container tbody tr.inventory-row:not([style*="display: none"]):not([style*="display:none"]) {
        display: block !important;
    }
}
         /* Modal responsive styles */
    #itemModal .modal-dialog,
    #editItemModal .modal-dialog {
        margin: 0.5rem;
    }

    @media (min-width: 1200px) {
        #itemModal .modal-xl,
        #editItemModal .modal-xl {
            max-width: 1380px;
        }
    }
    
    @media (min-width: 576px) {
        #itemModal .modal-dialog,
        #editItemModal .modal-dialog {
            margin: 1.75rem auto;
        }
    }
    
    /* Sticky header with shadow on scroll */
    #itemModal .modal-header.sticky-top {
        position: sticky;
        top: 0;
        background: white;
        z-index: 10;
        box-shadow: 0 1px 0 rgba(0,0,0,0.05);
        transition: box-shadow 0.2s ease;
    }
    
    #itemModal .modal-header.sticky-top.shadow-scroll {
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    }
    
    /* Section headers */
    .section-header {
        color: #495057;
        font-weight: 600;
        border-bottom: 2px solid #0d6efd;
        padding-bottom: 0.5rem;
        margin-bottom: 1.25rem;
        display: inline-block;
    }
    
    /* Pricing table styles */
    .pricing-table-wrapper {
        border-radius: 12px;
        border: 1px solid #e9ecef;
        overflow: hidden;
        background: white;
    }
    
    .pricing-table {
        margin-bottom: 0;
        font-size: 0.85rem;
    }
    
    .pricing-table thead th {
        background: #f8f9fa;
        font-weight: 600;
        padding: 12px 10px;
        border-bottom: 2px solid #dee2e6;
        white-space: nowrap;
    }
    
    .pricing-table tbody td {
        padding: 10px;
        vertical-align: middle;
        border-bottom: 1px solid #e9ecef;
    }
    
    .pricing-table tbody tr:last-child td {
        border-bottom: none;
    }
    
    .unit-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        font-size: 0.75rem;
        font-weight: 600;
        padding: 4px 10px;
        border-radius: 20px;
    }
    
    .unit-badge i {
        font-size: 0.8rem;
    }
    
    .unit-badge-piece { background: #e7f1ff; color: #0d6efd; }
    .unit-badge-inner-pack { background: #fff3cd; color: #856404; }
    .unit-badge-box { background: #d4edda; color: #155724; }
    .unit-badge-case { background: #cce5ff; color: #004085; }
    .unit-badge-carton { background: #f8d7da; color: #721c24; }
    
    .price-input-group {
        display: flex;
        align-items: center;
        gap: 5px;
    }
    
    .price-input-group .currency {
        background: #e9ecef;
        padding: 4px 8px;
        border-radius: 6px;
        font-weight: 600;
        font-size: 0.75rem;
        color: #495057;
    }
    
    /* Form inputs */
    .form-control, .form-select {
        border-radius: 8px;
        border: 1px solid #e0e0e0;
        padding: 8px 12px;
        font-size: 0.9rem;
        transition: all 0.2s ease;
    }
    
    .form-control:focus, .form-select:focus {
        border-color: #0d6efd;
        box-shadow: 0 0 0 3px rgba(13, 110, 253, 0.1);
    }
    
    .input-group-text {
        background: #f8f9fa;
        border-radius: 8px;
    }
    
    /* Mobile responsive adjustments */
    @media (max-width: 768px) {
        #itemModal .modal-body {
            padding: 1rem;
        }
        
        .pricing-table-wrapper {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        
        .pricing-table {
            min-width: 500px;
        }
        
        .pricing-table thead th,
        .pricing-table tbody td {
            padding: 8px 6px;
            font-size: 0.75rem;
        }
        
        .unit-badge {
            font-size: 0.65rem;
            padding: 2px 8px;
        }
        
        .section-header {
            font-size: 0.9rem;
        }
        
        .form-label {
            font-size: 0.8rem;
            font-weight: 500;
        }
        
        .form-control, .form-select {
            font-size: 14px; /* Prevents zoom on mobile inputs */
            padding: 6px 10px;
        }
    }
    
    @media (max-width: 576px) {
        .modal-footer {
            flex-direction: column;
            gap: 8px;
        }
        
        .modal-footer .btn {
            width: 100%;
        }
    }
    /* ============================================ */
/* ADD ITEM MODAL - INTERNAL CONTENT STYLES     */
/* (Input Elements, Tables, Form Controls)     */
/* ============================================ */

/* Form Labels - Clean and readable */
.modal-body .form-label {
    font-weight: 600;
    font-size: 0.8rem;
    margin-bottom: 0.35rem;
    color: #1e293b;
    letter-spacing: -0.2px;
}

/* Required field indicator */
.modal-body .form-label:has(+ .form-control[required])::after,
.modal-body .form-label:has(+ input[required])::after {
    content: '*';
    color: #ef4444;
    margin-left: 4px;
}

/* Form Inputs - Modern and clean */
.modal-body .form-control,
.modal-body .form-select {
    border-radius: 8px;
    border: 1px solid #e2e8f0;
    padding: 0.5rem 0.75rem;
    font-size: 0.85rem;
    transition: all 0.2s ease;
    background-color: #ffffff;
}

.modal-body .form-control:hover,
.modal-body .form-select:hover {
    border-color: #cbd5e1;
}

.modal-body .form-control:focus,
.modal-body .form-select:focus {
    border-color: #2E7D32;
    box-shadow: 0 0 0 3px rgba(46, 125, 50, 0.1);
    outline: none;
}

/* Small form inputs (for tables) */
.modal-body .form-control-sm {
    font-size: 0.75rem;
    padding: 0.35rem 0.6rem;
    border-radius: 6px;
}

/* Readonly inputs */
.modal-body .form-control[readonly] {
    background-color: #f8fafc;
    cursor: not-allowed;
    color: #64748b;
}

.modal-body .form-control[readonly]:focus {
    border-color: #e2e8f0;
    box-shadow: none;
}

/* Textarea */
.modal-body textarea.form-control {
    resize: vertical;
    min-height: 70px;
}

/* Input Group (Item Code with Edit button) */
.modal-body .input-group {
    flex-wrap: nowrap;
}

.modal-body .input-group .form-control {
    border-radius: 8px 0 0 8px;
    border-right: none;
}

.modal-body .input-group .btn {
    border-radius: 0 8px 8px 0;
    font-size: 0.75rem;
    padding: 0.5rem 0.75rem;
    white-space: nowrap;
}

.modal-body .input-group .btn-outline-secondary {
    border-color: #e2e8f0;
    color: #64748b;
}

.modal-body .input-group .btn-outline-secondary:hover {
    background-color: #f1f5f9;
    border-color: #cbd5e1;
    color: #1e293b;
}

.modal-body .input-group .btn-success {
    background-color: #2E7D32;
    border-color: #2E7D32;
}

/* Section Headers */
.modal-body .section-header,
.modal-body h6 {
    font-size: 0.85rem;
    font-weight: 600;
    color: #1e293b;
    margin-bottom: 1rem;
    padding-bottom: 0.5rem;
    border-bottom: 2px solid #2E7D32;
    display: inline-block;
}
/* ============================================ */
/* ADD ITEM MODAL - FIXED MOBILE CARD VIEW     */
/* (Same as Offtake Modal - Clean Layout)      */
/* ============================================ */

/* Desktop: Normal table */
@media (min-width: 769px) {
    #itemModal .table-responsive {
        overflow-x: visible !important;
    }
    
    #itemModal .table {
        min-width: auto !important;
        width: 100% !important;
    }
    
    #itemModal .table thead {
        display: table-header-group !important;
    }
    
    #itemModal .table tbody tr {
        display: table-row !important;
    }
    
    #itemModal .table tbody td {
        display: table-cell !important;
    }
}

/* Mobile: Convert table to card view (like Offtake Modal) */
@media (max-width: 768px) {
    /* Hide table header */
    #itemModal .table thead {
        display: none !important;
    }
    
    /* Table container - allow normal flow */
    #itemModal .table-responsive {
        overflow-x: visible !important;
        overflow-y: visible !important;
        margin: 0 !important;
        padding: 0 !important;
        border: none !important;
    }
    
    /* Convert table to block */
    #itemModal .table,
    #itemModal .table tbody,
    #itemModal .table tbody tr {
        display: block !important;
        width: 100% !important;
    }
    
    /* Each row as a card */
    #itemModal .table tbody tr {
        background: white !important;
        border-radius: 12px !important;
        margin-bottom: 16px !important;
        padding: 0 !important;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08) !important;
        border: 1px solid #e9ecef !important;
        overflow: hidden !important;
    }
    
    /* Each cell as block with its own styling */
    #itemModal .table tbody td {
        display: block !important;
        padding: 12px 16px !important;
        border: none !important;
        border-bottom: 1px solid #f0f0f0 !important;
        background: white !important;
        width: 100% !important;
        box-sizing: border-box !important;
    }
    
    /* Remove border from last cell */
    #itemModal .table tbody td:last-child {
        border-bottom: none !important;
    }
    
    /* ===== LABEL STYLES - Like Offtake Modal ===== */
    #unitTypesTable tbody td::before {
        content: attr(data-label) !important;
        display: block !important;
        font-weight: 600 !important;
        color: #6c757d !important;
        font-size: 11px !important;
        text-transform: uppercase !important;
        letter-spacing: 0.5px !important;
        margin-bottom: 6px !important;
    }
    
    /* Set specific labels for each column */
    #unitTypesTable tbody td:first-child::before {
        content: "Unit Type" !important;
    }
    
    #unitTypesTable tbody td:nth-child(2)::before {
        content: "Barcode" !important;
    }
    
    #unitTypesTable tbody td:nth-child(3)::before {
        content: "Qty (Smallest Pack)" !important;
    }
    
    #unitTypesTable tbody td:nth-child(4)::before {
        content: "Default UoM" !important;
    }
    
    #unitTypesTable tbody td:nth-child(5)::before {
        content: "Status" !important;
    }
    
    #unitTypesTable tbody td:last-child::before {
        content: "Action" !important;
    }
    
    /* Pricing table labels */
    #pricingTable tbody td:first-child::before {
        content: "Effective Date" !important;
    }
    
    #pricingTable tbody td:nth-child(2)::before {
        content: "Price Level" !important;
    }
    
    #pricingTable tbody td:nth-child(n+3)::before {
        content: "Price" !important;
    }
    
    /* ===== INPUT STYLES - Full width ===== */
    #itemModal .table .form-control,
    #itemModal .table .form-select,
    #unitTypesTable input,
    #pricingTable input {
        width: 100% !important;
        display: block !important;
        margin-top: 4px !important;
    }
    
    #itemModal .table .form-control-sm {
        padding: 8px 10px !important;
        font-size: 14px !important;
    }
    
    /* Checkbox - inline with label */
    #unitTypesTable tbody td:nth-child(4) .form-check-input {
        display: inline-block !important;
        width: 20px !important;
        height: 20px !important;
        margin: 0 !important;
    }
    
    /* Toggle switch - inline */
    #unitTypesTable tbody td:nth-child(5) {
        display: flex !important;
        align-items: center !important;
        justify-content: space-between !important;
        flex-direction: row !important;
    }
    
    #unitTypesTable tbody td:nth-child(5)::before {
        margin-bottom: 0 !important;
    }
    
    .unit-toggle-switch {
        width: 40px !important;
        height: 22px !important;
    }
    
    .unit-toggle-slider:before {
        height: 18px !important;
        width: 18px !important;
    }
    
    /* Delete button */
    #unitTypesTable tbody td:last-child {
        display: flex !important;
        align-items: center !important;
        justify-content: space-between !important;
        flex-direction: row !important;
    }
    
    #unitTypesTable tbody td:last-child::before {
        margin-bottom: 0 !important;
    }
    
    .modal-body .btn-outline-danger {
        padding: 6px 12px !important;
        font-size: 0.75rem !important;
    }
    
    /* Price inputs - right aligned like Offtake */
    .pricing-input,
    .edit-pricing-input {
        text-align: left !important;
    }
}

/* ===== SMALL MOBILE (480px and below) ===== */
@media (max-width: 480px) {
    #itemModal .table tbody td {
        padding: 10px 12px !important;
    }
    
    #itemModal .table .form-control-sm {
        padding: 6px 8px !important;
        font-size: 13px !important;
    }
    
    .unit-toggle-switch {
        width: 36px !important;
        height: 20px !important;
    }
    
    .unit-toggle-slider:before {
        height: 16px !important;
        width: 16px !important;
    }
    
    .unit-toggle-switch input:checked + .unit-toggle-slider:before {
        transform: translateX(16px) !important;
    }
    
    .modal-body .btn-outline-danger {
        padding: 4px 10px !important;
        font-size: 0.7rem !important;
    }
}

/* ===== LANDSCAPE MODE ===== */
@media (max-width: 768px) and (orientation: landscape) {
    #itemModal .table tbody tr {
        margin-bottom: 12px !important;
    }
    
    #itemModal .table tbody td {
        padding: 8px 12px !important;
    }
    
    #itemModal .table .form-control-sm {
        padding: 5px 8px !important;
        font-size: 12px !important;
    }
}
/* ===== ROW CURSOR STYLES ===== */
/* Para sa lahat ng table rows - gawing pointer (clickable) */
.table-container tbody tr {
    cursor: pointer;
    transition: background-color 0.2s ease, transform 0.2s ease;
}

/* Desktop hover effect */
@media (min-width: 769px) {
    .table-container tbody tr:hover {
        background-color: rgba(46, 125, 50, 0.08) !important;
    }
    
    /* Para sa edit/delete buttons - ibalik sa default cursor */
    .table-container tbody tr:hover .btn-action {
        cursor: pointer;
    }
}

/* Mobile tap feedback */
@media (max-width: 768px) {
    .table-container tbody tr:active {
        transform: scale(0.99);
        background-color: rgba(46, 125, 50, 0.1);
    }
}

/* Siguraduhin na ang edit at delete buttons ay hindi nagpapalit ng cursor sa pointer (stay as default arrow) */
.btn-action {
    cursor: pointer !important;
}

/* Optional: Para sa status toggle - maintain clickability */
.status-toggle {
    cursor: default;
}

.toggle-switch {
    cursor: pointer;
}

/* ===== UoM TABLE RESPONSIVE IMPROVEMENTS (ADD + EDIT) ===== */
#unitTypesTable,
#editUnitTypesTable {
    table-layout: fixed;
    width: 100%;
}

#unitTypesTable th,
#unitTypesTable td,
#editUnitTypesTable th,
#editUnitTypesTable td {
    vertical-align: middle;
}

#unitTypesTable .form-control,
#editUnitTypesTable .form-control,
#unitTypesTable .form-select,
#editUnitTypesTable .form-select {
    min-width: 0;
}

#unitTypesTable input[readonly],
#editUnitTypesTable input[readonly] {
    background-color: #f8f9fa !important;
}

@media (max-width: 991.98px) {
    #itemModal .table-responsive,
    #editItemModal .table-responsive {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }

    #unitTypesTable,
    #editUnitTypesTable {
        min-width: 1120px;
    }
}

@media (max-width: 768px) {
    #unitTypesTable,
    #editUnitTypesTable {
        min-width: 0 !important;
    }

    #unitTypesTable thead,
    #editUnitTypesTable thead {
        display: none !important;
    }

    #unitTypesTable tbody,
    #unitTypesTable tr,
    #unitTypesTable td,
    #editUnitTypesTable tbody,
    #editUnitTypesTable tr,
    #editUnitTypesTable td {
        display: block !important;
        width: 100% !important;
    }

    #unitTypesTable tbody tr,
    #editUnitTypesTable tbody tr {
        background: #fff !important;
        border: 1px solid #e5e7eb !important;
        border-radius: 12px !important;
        margin-bottom: 14px !important;
        padding: 10px !important;
        box-shadow: 0 2px 6px rgba(0,0,0,0.04) !important;
    }

    #unitTypesTable tbody td,
    #editUnitTypesTable tbody td {
        border: none !important;
        border-bottom: 1px solid #f1f3f5 !important;
        padding: 10px 12px !important;
    }

    #unitTypesTable tbody td:last-child,
    #editUnitTypesTable tbody td:last-child {
        border-bottom: none !important;
    }

    #unitTypesTable tbody td::before,
    #editUnitTypesTable tbody td::before {
        content: attr(data-label) !important;
        display: block !important;
        font-weight: 600 !important;
        color: #6c757d !important;
        font-size: 11px !important;
        text-transform: uppercase !important;
        letter-spacing: 0.5px !important;
        margin-bottom: 6px !important;
    }

    #unitTypesTable tbody td:nth-child(1)::before,
    #editUnitTypesTable tbody td:nth-child(1)::before { content: "UoM" !important; }
    #unitTypesTable tbody td:nth-child(2)::before,
    #editUnitTypesTable tbody td:nth-child(2)::before { content: "Initial" !important; }
    #unitTypesTable tbody td:nth-child(3)::before,
    #editUnitTypesTable tbody td:nth-child(3)::before { content: "Barcode" !important; }
    #unitTypesTable tbody td:nth-child(4)::before,
    #editUnitTypesTable tbody td:nth-child(4)::before { content: "Smallest Pack Qty" !important; }
    #unitTypesTable tbody td:nth-child(5)::before,
    #editUnitTypesTable tbody td:nth-child(5)::before { content: "Current Inventory" !important; }
    #unitTypesTable tbody td:nth-child(6)::before,
    #editUnitTypesTable tbody td:nth-child(6)::before { content: "As Of" !important; }
    #unitTypesTable tbody td:nth-child(7)::before,
    #editUnitTypesTable tbody td:nth-child(7)::before { content: "Default UoM" !important; }
    #unitTypesTable tbody td:nth-child(8)::before,
    #editUnitTypesTable tbody td:nth-child(8)::before { content: "Unit Cost" !important; }
    #unitTypesTable tbody td:nth-child(9)::before,
    #editUnitTypesTable tbody td:nth-child(9)::before { content: "Total Cost" !important; }
    #unitTypesTable tbody td:nth-child(10)::before,
    #editUnitTypesTable tbody td:nth-child(10)::before { content: "Action" !important; }

}
/* ========== STOCK ALERT TOAST - WARNING BACKGROUND ========== */
.plain-toast {
    min-width: 300px;
    max-width: 380px;
    background: #fcde68 !important;  /* Warning yellow background */
    border-radius: 10px;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.12);
    border: 1px solid #ffdb4c;
    margin-bottom: 10px;
}

.plain-toast-content {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 16px;
    gap: 12px;
}

/* Icon styles */
.plain-toast-icon {
    flex-shrink: 0;
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.plain-toast-icon i {
    font-size: 1.3rem;
    color: #1f2937 !important;  /* Black/Dark gray icon */
}

/* Different icon color for out of stock (still dark but can be slightly different) */
.plain-toast.out-of-stock .plain-toast-icon i {
    color: #1f2937 !important;  /* Keep black for consistency */
}

/* Text styles - all black/dark */
.plain-toast-text {
    flex: 1;
}

.plain-toast-text strong {
    display: block;
    font-size: 0.85rem;
    font-weight: 700;
    color: #1f2937 !important;  /* Bold dark text */
    margin-bottom: 4px;
}

.plain-toast-text p {
    font-size: 0.8rem;
    color: #374151 !important;  /* Dark gray text */
    margin: 0;
    line-height: 1.4;
}

/* Make numbers and "item(s)" bold in the message */
.plain-toast-text p strong {
    font-weight: 700;
    color: #1f2937;
    display: inline;
}

/* Close button - centered */
.plain-toast-close {
    flex-shrink: 0;
    background: transparent;
    border: none;
    cursor: pointer;
    font-size: 1.1rem;
    width: 28px;
    height: 28px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #6b7280;
    border-radius: 6px;
    transition: all 0.2s;
    font-weight: 500;
    line-height: 1;
    padding: 0;
}

.plain-toast-close:hover {
    background: rgba(0, 0, 0, 0.1);
    color: #1f2937;
}

/* Animations */
@keyframes slideInRight {
    0% {
        opacity: 0;
        transform: translateX(100%);
    }
    100% {
        opacity: 1;
        transform: translateX(0);
    }
}

@keyframes slideOutRight {
    0% {
        opacity: 1;
        transform: translateX(0);
    }
    100% {
        opacity: 0;
        transform: translateX(100%);
    }
}

.plain-toast {
    animation: slideInRight 0.25s ease-out forwards;
}

.plain-toast.hiding {
    animation: slideOutRight 0.25s ease-in forwards;
}

.batch-new-price.is-muted-price {
    background-color: #f3f4f6;
    color: #9ca3af;
    border-color: #e5e7eb;
    cursor: pointer;
    opacity: 0.78;
}

.batch-new-price.is-muted-price::placeholder {
    color: #9ca3af;
}

.batch-new-price.is-editing-price {
    background-color: #ffffff;
    color: #052A47;
    border-color: #44D34E;
    box-shadow: 0 0 0 0.15rem rgba(68, 211, 78, 0.18);
}

.batch-price-hint {
    display: block;
    margin-top: 4px;
    font-size: 0.72rem;
    color: #9ca3af;
}

.batch-price-hint.is-active {
    color: #047857;
    font-weight: 600;
}



        .inventory-table-scroll,
        .pricing-history-scroll,
        .transactions-history-scroll,
        .detail-section-scroll {
            max-height: 460px;
            overflow-y: auto;
            border-radius: 8px;
            border: 1px solid #d1fae5;
        }
        .inventory-table-scroll table,
        .pricing-history-scroll table,
        .transactions-history-scroll table,
        .detail-section-scroll table {
            margin-bottom: 0;
        }
        .inventory-table-scroll thead th,
        .pricing-history-scroll thead th,
        .transactions-history-scroll thead th,
        .detail-section-scroll thead th {
            position: sticky;
            top: 0;
            z-index: 2;
        }
        .detail-dropdown-toggle {
            width: 100%;
            border: 1px solid #d1fae5;
            background: #ffffff;
            color: #052A47;
            border-radius: 12px;
            padding: 12px 14px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-weight: 700;
            box-shadow: 0 4px 14px rgba(5, 42, 71, 0.05);
            transition: all 0.2s ease;
        }
        .detail-dropdown-toggle:hover {
            background: #ecfdf5;
            border-color: #44D34E;
            color: #047857;
        }
        .detail-dropdown-toggle .dropdown-title {
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .detail-dropdown-toggle .dropdown-chevron {
            transition: transform 0.2s ease;
            color: #047857;
        }
        .detail-dropdown-toggle[aria-expanded="true"] .dropdown-chevron {
            transform: rotate(180deg);
        }
        .detail-dropdown-body {
            margin-top: 10px;
        }
/* UOM */
#unitTypesTable th:nth-child(1),
#unitTypesTable td:nth-child(1),
#editUnitTypesTable th:nth-child(1),
#editUnitTypesTable td:nth-child(1) {
    width: 8% !important;
    min-width: 80px !important;
}

/* INITIAL */
#unitTypesTable th:nth-child(2),
#unitTypesTable td:nth-child(2),
#editUnitTypesTable th:nth-child(2),
#editUnitTypesTable td:nth-child(2) {
    width: 5% !important;
    min-width: 55px !important;
}

/* BARCODE */
#unitTypesTable th:nth-child(3),
#unitTypesTable td:nth-child(3),
#editUnitTypesTable th:nth-child(3),
#editUnitTypesTable td:nth-child(3) {
    width: 15% !important;
    min-width: 280px !important;
}

/* DEFAULT UOM */
#unitTypesTable th:nth-child(7),
#unitTypesTable td:nth-child(7),
#editUnitTypesTable th:nth-child(7),
#editUnitTypesTable td:nth-child(7) {
    width: 6% !important;
}

</style>

<style>
.system-task-table-modal .modal-content{border:none;border-radius:16px;overflow:hidden;box-shadow:0 15px 40px rgba(0,0,0,.18)}
.system-task-table-modal .modal-header{background:linear-gradient(135deg,#047857,#44D34E);color:#fff;border-bottom:none}
.system-task-table-modal .modal-title{color:#fff;font-weight:700}.system-task-table-modal .btn-close{filter:brightness(0) invert(1)}
.system-task-alert{background:#fff7ed;border:1px solid #fed7aa;color:#9a3412;border-radius:12px;padding:12px 14px;margin-bottom:14px;font-size:.9rem}
.system-task-table-wrap{border:1px solid #e5e7eb;border-radius:12px;overflow:hidden}.system-task-table{margin-bottom:0}.system-task-table thead th{background:#052A47;color:#fff;border:none;font-size:.85rem;white-space:nowrap}.system-task-table tbody tr{cursor:pointer;transition:background-color .2s ease}.system-task-table tbody tr:hover{background:#ecfdf5}.system-task-type{font-weight:700;color:#047857;white-space:nowrap}.system-task-ref{font-weight:700;color:#052A47}.system-task-desc{color:#6b7280;font-size:.85rem}.system-task-status{display:inline-flex;align-items:center;justify-content:center;background:#fef3c7;color:#92400e;padding:4px 10px;border-radius:999px;font-size:.75rem;font-weight:700;white-space:nowrap}.system-task-open-btn{background:linear-gradient(135deg,#047857,#44D34E);color:#fff;border:none;border-radius:8px;padding:7px 12px;font-weight:700;font-size:.8rem;white-space:nowrap}.system-task-open-btn:hover{color:#fff;opacity:.95}
@media(max-width:768px){.system-task-table thead{display:none}.system-task-table,.system-task-table tbody,.system-task-table tr,.system-task-table td{display:block;width:100%}.system-task-table tbody tr{padding:12px;border-bottom:1px solid #e5e7eb}.system-task-table tbody tr:last-child{border-bottom:none}.system-task-table td{border:none;padding:4px 0!important}.system-task-table td[data-label]::before{content:attr(data-label) ': ';font-weight:700;color:#052A47}.system-task-table td.system-task-action-cell::before{content:''}.system-task-open-btn{width:100%;margin-top:8px}}
</style>


<style>
.system-task-table tbody tr { cursor: pointer; }
.system-task-table tbody tr:hover { background: rgba(4, 120, 87, 0.06); }
.system-task-table th, .system-task-table td { vertical-align: middle; }
@media (max-width: 768px) {
    .system-task-table thead { display: none; }
    .system-task-table, .system-task-table tbody, .system-task-table tr, .system-task-table td { display: block; width: 100%; }
    .system-task-table tr { border: 1px solid #e5e7eb; border-radius: 12px; margin-bottom: 10px; padding: 10px; background: #fff; }
    .system-task-table td { border: none !important; padding: 4px 0 !important; }
    .system-task-table td::before { content: attr(data-label) ': '; font-weight: 700; color: #052A47; }
}
/* ===== FIX DROPDOWN PARENT ACTIVE COLOR - HUWAG MAGPAKITA NG GREEN ===== */

/* Kapag expanded ang sidebar - huwag i-highlight ang dropdown parent */
.sidebar:not(.collapsed) .dropdown-nav > .nav-link {
    background: transparent !important;
    color: #9ca3af !important;
}

.sidebar:not(.collapsed) .dropdown-nav > .nav-link i {
    color: #9ca3af !important;
}

.sidebar:not(.collapsed) .dropdown-nav > .nav-link:hover {
    background: rgba(16, 185, 129, 0.1) !important;
    color: var(--primary-green) !important;
}

.sidebar:not(.collapsed) .dropdown-nav > .nav-link:hover i {
    color: var(--primary-green) !important;
}

/* Huwag mag-add ng active class sa dropdown parent */
.sidebar .dropdown-nav > .nav-link.active {
    background: transparent !important;
    color: #9ca3af !important;
}

.sidebar .dropdown-nav > .nav-link.active i {
    color: #9ca3af !important;
}

/* Kapag may active child sa loob - huwag i-highlight ang parent (expanded mode) */
.sidebar:not(.collapsed) .dropdown-nav:has(.nav-link.active) > .nav-link {
    background: transparent !important;
    color: #9ca3af !important;
}

.sidebar:not(.collapsed) .dropdown-nav:has(.nav-link.active) > .nav-link i {
    color: #9ca3af !important;
}

/* Kapag collapsed ang sidebar - saka lang mag-green ang parent */
.sidebar.collapsed .dropdown-nav:has(.nav-link.active) > .nav-link {
    background: rgba(16, 185, 129, 0.15) !important;
    color: var(--primary-green) !important;
}

.sidebar.collapsed .dropdown-nav:has(.nav-link.active) > .nav-link i {
    color: var(--primary-green) !important;
}
/* Barcode input + scan button = dikit */
.scan-barcode-btn{
    border-left: 0 !important;
    border-radius: 0 8px 8px 0 !important;
    padding: 0 12px !important;
    background: #ecfdf5 !important;
    color: #065f46 !important;  
}

.uom-barcode-input{
    border-right:0 !important;
    border-radius:8px 0 0 8px !important;
}

.input-group .uom-barcode-input:focus{
    box-shadow:none !important;
    border-color:#ced4da !important;
}

.input-group:focus-within .scan-barcode-btn{
    border-color:#86b7fe !important;
}

.input-group{
    flex-wrap:nowrap !important;
}

/* SAME BARCODE INPUT + SCAN BUTTON STYLE FOR ADD ITEM AND EDIT ITEM */
#unitTypesTable .barcode-group,
#editUnitTypesTable .barcode-group {
    flex-wrap: nowrap !important;
    width: 100% !important;
}

#unitTypesTable .barcode-group .uom-barcode-input,
#editUnitTypesTable .barcode-group .uom-barcode-input {
    border-right: 0 !important;
    border-radius: 8px 0 0 8px !important;
    height: 42px !important;
    min-height: 42px !important;
    font-size: 14px;
    box-shadow: none !important;
}

#unitTypesTable .barcode-group .scan-barcode-btn,
#editUnitTypesTable .barcode-group .scan-barcode-btn {
    border: 1px solid #ced4da !important;
    border-left: 0 !important;
    border-radius: 0 8px 8px 0 !important;
    background: #ecfdf5 !important;
    color: #065f46 !important;
    height: 42px !important;
    min-height: 42px !important;
    padding: 0 12px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    line-height: 1 !important;
}

#unitTypesTable .barcode-group:focus-within .uom-barcode-input,
#editUnitTypesTable .barcode-group:focus-within .uom-barcode-input,
#unitTypesTable .barcode-group:focus-within .scan-barcode-btn,
#editUnitTypesTable .barcode-group:focus-within .scan-barcode-btn {
    border-color: #86b7fe !important;
}

#unitTypesTable .barcode-group .scan-barcode-btn:hover,
#editUnitTypesTable .barcode-group .scan-barcode-btn:hover {
    background: #d1fae5 !important;
    color: #047857 !important;
}

</style>

<style>
/* Keeps nested barcode scanner modal above Add/Edit Item modal without breaking the parent backdrop. */
#uomBarcodeScannerModal { z-index: 1085 !important; }
.modal-backdrop.uom-scanner-backdrop { z-index: 1080 !important; opacity: 0.55 !important; }
#itemModal.show, #editItemModal.show { z-index: 1055 !important; }

/* Search barcode button */
#searchInput + .scan-barcode-btn{
    height:42px !important;
    min-width:48px !important;

    border-left:0 !important;
    border-radius:0 8px 8px 0 !important;

    background:#d1fae5 !important;
    color:#047857 !important;

    border:1px solid #86efac !important; /* mas visible */
    border-left:0 !important;

    transition:.2s ease;
}

#searchInput + .scan-barcode-btn:hover{

    background:#047857 !important;
    color:#d1fae5 !important;

    border-color:#047857 !important;
}

#searchInput + .scan-barcode-btn i{
    font-size:16px;
}

/* Para pantay border ng input + button */
#searchInput{
    border-color:#86efac !important;
}
.responsive-search-box {
    width: 625px;
    max-width: 100%;
}

@media (max-width: 768px) {
    .responsive-search-box {
        width: 100%;
        min-width: 0;
    }

    .responsive-search-box .input-group {
        width: 100%;
    }

    .responsive-search-box .form-control {
        min-width: 0;
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
</head>
<body>
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
                    <span class="nav-text">Dashboard</span>
                </a>
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
            <div id="dashboardContent" class="page-content active">
                <div class="navbar-top">
                    <button class="mobile-toggle-btn" id="mobileToggleBtn"><i class="bi bi-list"></i></button>
                    <div class="page-title">
                        <h2>Current Inventory</h2>
                        <p id="dashboardSubtitle">Real-time inventory from database</p>
                    </div>
                </div>
                <!-- Additional Stats with Modals -->
                <div class="row stat-card-row g-1 g-sm-2">
                    <!-- Stat 1: Total Inventory Value -->
                    <div class="col">
                        <div class="stat-card total clickable" onclick="showInventoryValueDetails()">
                            <i class="bi bi-coin"></i>
                            <div class="stat-content">
                                <div class="stat-value"><?= $statInventoryValue ?></div>
                                <div class="stat-label">Total Inventory Value</div>
                                <small><i class="bi bi-box-seam"></i> <?= number_format($total_stock) ?> units</small>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Stat 2: Average Daily Offtake (CLICKABLE with modal) -->
                    <div class="col">
                        <div class="stat-card offtake-card clickable" onclick="showOfftakeModal()">
                            <i class="bi bi-graph-up-arrow"></i>
                            <div class="stat-content">
                                <div class="stat-value"><?= number_format($avg_daily_offtake, 1) ?></div>
                                <div class="stat-label">Avg Daily Offtake</div>
                                <small><i class="bi bi-calendar"></i> Last 30 days</small>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Stat 3: Suppliers (CLICKABLE with modal) -->
                    <div class="col">
                        <div class="stat-card sales clickable" onclick="showSupplierSelector()">
                            <i class="bi bi-building"></i>
                            <div class="stat-content">
                                <div class="stat-value"><?= $suppliers_count ?></div>
                                <div class="stat-label">Suppliers</div>
                                <small><i class="bi bi-building"></i> Active suppliers</small>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Stat 4: Needs Attention (CLICKABLE with modal) -->
                    <div class="col">
                        <div class="stat-card pending clickable" onclick="showLowStockModal()">
                            <i class="bi bi-exclamation-triangle"></i>
                            <div class="stat-content">
                                <div class="stat-value"><?= $statNeedsAttention ?></div>
                                <div class="stat-label">Needs Attention</div>
                                <small><?= $low_stock_count ?> low, <?= $out_of_stock ?> out</small>
                            </div>
                        </div>
                    </div>
                </div>
                

           <!-- STOCK ALERT TOAST - USING BOOTSTRAP ICONS (NO EXTERNAL CDN NEEDED) -->
<div class="position-fixed top-0 end-0 p-3" style="z-index: 9999; pointer-events: none;">
    <div id="stockAlertToast" class="plain-toast" role="alert" style="pointer-events: auto; display: none;">
        <div class="plain-toast-content">
            <div class="plain-toast-icon">
                <i class="bi bi-exclamation-triangle-fill"></i>
            </div>
            <div class="plain-toast-text">
                <strong id="toastTitle">Stock Alert</strong>
                <p id="toastMessage">Loading...</p>
            </div>
            <button type="button" class="plain-toast-close" onclick="closeStockAlertToast()">×</button>
        </div>
    </div>
</div>
                <!-- FILTER SECTION - INVENTORY ITEMS (Entire card clickable to expand/collapse) -->
<div class="form-card mb-4" id="filterCard">
    <div class="filter-header" id="filterHeader" style="cursor: pointer;">
        <h5>
            <i class="bi bi-funnel"></i> Filter Inventory Items
        </h5>
        <button class="filter-toggle-btn" type="button" id="filterToggleBtn" aria-expanded="false">
            <i class="bi bi-chevron-down" id="filterIcon"></i>
        </button>
    </div>
    
    <div class="filter-content collapsed" id="filterContent">
        <div class="row g-3">
            <!-- Status Filter -->
            <div class="col-12 col-md-3">
                <label class="form-label">
                    <i class="bi bi-flag"></i> Status
                </label>
                <select class="form-select" id="statusFilter">
                    <option value="">All Status</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                    <option value="discontinued">Discontinued</option>
                </select>
            </div>
            
            <!-- Stock Level Filter -->
            <div class="col-12 col-md-3">
                <label class="form-label">
                    <i class="bi bi-box-seam"></i> Stock Level
                </label>
                <select class="form-select" id="stockFilter">
                    <option value="">Stock Level</option>
                    <option value="low">Low Stock</option>
                    <option value="normal">Normal</option>
                    <option value="adequate">Adequate</option>
                    <option value="out">Out of Stock</option>
                </select>
            </div>

            <!-- Principal Filter -->
            <div class="col-12 col-md-3">
                <label class="form-label">
                    <i class="bi bi-person-badge"></i> Principal
                </label>
                <select class="form-select" id="principalFilter">
                    <option value="">All Principal</option>
                    <option value="No Principal">No Principal</option>
                    <?php foreach ($principalOptions as $principalOption): ?>
                        <option value="<?= htmlspecialchars($principalOption) ?>"><?= htmlspecialchars($principalOption) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>
</div>

                
                <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div class="d-flex align-items-center gap-3 flex-wrap">
        <div class="view-toggle">
            <button class="view-btn active" id="viewCategoryBtn" onclick="toggleView('category')"><i class="bi bi-grid"></i> By Category</button>
            <button class="view-btn" id="viewSupplierBtn" onclick="toggleView('supplier')"><i class="bi bi-building"></i> By Supplier</button>
        </div>
        <!-- SEARCH INPUT - MOVED HERE -->
<div class="responsive-search-box">
    <div class="input-group barcode-group">

        <input
            type="text"
            class="form-control uom-barcode-input"
            id="searchInput"
            placeholder="Search item or scan barcode..."
            autocomplete="off">

        <button
            type="button"
            class="btn scan-barcode-btn"
            onclick="startBarcodeSearchScanner()"
            title="Scan Barcode">
            <i class="bi bi-upc-scan"></i>
        </button>


    </div>
</div>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <input type="file" id="importItemsFile" accept=".xlsx,.xls,.csv" style="display:none;" onchange="handleImportItemsFile(event)">
        <button class="btn btn-success" type="button" onclick="showBatchPriceLevelModal()"><i class="bi bi-cash-coin"></i> Update Price Level</button>
        <button class="btn btn-primary" onclick="showAddItemModal()"><i class="bi bi-plus-circle"></i> Add Item</button>
    </div>
</div>

                <!-- CATEGORY VIEW -->
                <div id="categoryView">
                    <?php if (empty($items)): ?>
                        <div class="empty-state text-center py-5"><i class="bi bi-inbox fs-1 d-block text-muted mb-2"></i><p class="text-muted">No items found</p><button class="btn btn-primary mt-2" onclick="showAddItemModal()"><i class="bi bi-plus-circle"></i> Add Item</button></div>
                    <?php else: ?>
                        <div class="category-tabs">
                            <div class="category-tab active" onclick="switchCategoryTab('cat-tab-all', this)" data-tab="cat-tab-all"><i class="bi bi-grid"></i> All Categories<span class="tab-badge"><?= $total_items ?></span></div>
                            <?php foreach ($items_by_category as $category => $category_items): $tab_id = 'cat-tab-' . preg_replace('/[^a-z0-9]/i', '-', strtolower($category)); ?>
                            <div class="category-tab" onclick="switchCategoryTab('<?= $tab_id ?>', this)" data-tab="<?= $tab_id ?>"><i class="bi bi-folder"></i> <?= htmlspecialchars($category) ?><span class="tab-badge"><?= count($category_items) ?></span></div>
                            <?php endforeach; ?>
                        </div>
                        
                        <div id="cat-tab-all" class="tab-content active">
                            <div class="table-container"><table class="table custom-table compact-table"><thead><th class="col-image">Image</th><th>Item Name</th><th>Category</th><th>Principal</th><?php if ($items_branch_column_exists && $view_all_branches): ?><th>Branch</th><?php endif; ?><th>Stock</th><th class="col-status">Active</th><th class="col-actions">Actions</th> </thead><tbody>
                            <?php foreach ($items as $item): $stock_status = getStockStatus($item['quantity_on_hand'], $item['reorder_level']); ?>
                            <tr class="inventory-row" data-id="<?= $item['item_id'] ?>" data-code="<?= htmlspecialchars($item['item_code']) ?>" data-barcode="<?= htmlspecialchars($item['barcode'] ?? '') ?>" data-name="<?= htmlspecialchars($item['item_name']) ?>" data-category="<?= htmlspecialchars($item['category'] ?? '') ?>" data-principal="<?= htmlspecialchars($item['principal'] ?? 'No Principal') ?>" data-status="<?= $item['status'] ?>" data-stock="<?= $item['quantity_on_hand'] ?>" data-reorder="<?= $item['reorder_level'] ?>" data-price="<?= $item['unit_price'] ?>" data-unit="<?= $item['unit_type'] ?>" data-description="<?= htmlspecialchars($item['description'] ?? '') ?>" data-branch="<?= $item['branch_id'] ?? '' ?>">
                                <td class="col-image"><div class="item-thumbnail" data-item-id="<?= $item['item_id'] ?>"><?php if (!empty($item['product_image_url'])): ?><img src="../uploads/products/<?= htmlspecialchars($item['product_image_url']) ?>" alt="<?= htmlspecialchars($item['item_name']) ?>" style="width: 40px; height: 40px; object-fit: cover; border-radius: 4px;" onerror="this.style.display='none';"><?php endif; ?><i class="bi bi-image text-muted" style="<?= !empty($item['product_image_url']) ? 'display:none;' : '' ?>"></i></div></td>
                                <td><strong><?= htmlspecialchars($item['item_name']) ?></strong></td>
                                <td><?= htmlspecialchars($item['category'] ?? 'Uncategorized') ?></td>
                                <td><?= htmlspecialchars($item['principal'] ?? 'No Principal') ?></td>
                                <?php if ($items_branch_column_exists && $view_all_branches): ?><td><span class="badge bg-info">Branch <?= $item['branch_id'] ?? 'N/A' ?></span></td><?php endif; ?>
                                <td><span class="<?= $item['quantity_on_hand'] <= $item['reorder_level'] ? 'text-danger fw-bold' : '' ?>"><?= $item['stock_display'] ?></span><span class="badge <?= $stock_status['class'] ?> ms-1"><?= $stock_status['label'] ?></span></td>
                                <td class="col-status"><div class="status-toggle"><label class="toggle-switch"><input type="checkbox" class="status-checkbox" data-id="<?= $item['item_id'] ?>" <?= $item['status'] === 'active' ? 'checked' : '' ?> onchange="toggleItemStatus(<?= $item['item_id'] ?>, this)"><span class="toggle-slider"></span></label></div></td>
                                <td class="col-actions"><div class="action-buttons"><button class="btn-action btn-edit" onclick="event.stopPropagation(); editItem(<?= $item['item_id'] ?>)" title="Edit"><i class="bi bi-pencil"></i></button><button class="btn-action btn-delete" onclick="event.stopPropagation(); deleteItem(<?= $item['item_id'] ?>)" title="Delete"><i class="bi bi-trash"></i></button></div></td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody></table></div>
                        </div>
                        
                        <?php foreach ($items_by_category as $category => $category_items): $tab_id = 'cat-tab-' . preg_replace('/[^a-z0-9]/i', '-', strtolower($category)); ?>
                        <div id="<?= $tab_id ?>" class="tab-content" data-category="<?= htmlspecialchars($category) ?>">
                            <div class="table-container"><table class="table custom-table compact-table"><thead><th class="col-image">Image</th><th>Item Name</th><th>Category</th><th>Principal</th><?php if ($items_branch_column_exists && $view_all_branches): ?><th>Branch</th><?php endif; ?><th>Stock</th><th class="col-status">Active</th><th class="col-actions">Actions</th> </thead><tbody>
                            <?php foreach ($category_items as $item): $stock_status = getStockStatus($item['quantity_on_hand'], $item['reorder_level']); ?>
                            <tr class="inventory-row" data-id="<?= $item['item_id'] ?>" data-code="<?= htmlspecialchars($item['item_code']) ?>" data-barcode="<?= htmlspecialchars($item['barcode'] ?? '') ?>" data-name="<?= htmlspecialchars($item['item_name']) ?>" data-category="<?= htmlspecialchars($item['category'] ?? '') ?>" data-principal="<?= htmlspecialchars($item['principal'] ?? 'No Principal') ?>" data-status="<?= $item['status'] ?>" data-stock="<?= $item['quantity_on_hand'] ?>" data-reorder="<?= $item['reorder_level'] ?>" data-price="<?= $item['unit_price'] ?>" data-unit="<?= $item['unit_type'] ?>" data-description="<?= htmlspecialchars($item['description'] ?? '') ?>" data-branch="<?= $item['branch_id'] ?? '' ?>">
                                <td class="col-image"><div class="item-thumbnail" data-item-id="<?= $item['item_id'] ?>"><?php if (!empty($item['product_image_url'])): ?><img src="../uploads/products/<?= htmlspecialchars($item['product_image_url']) ?>" alt="<?= htmlspecialchars($item['item_name']) ?>" style="width: 40px; height: 40px; object-fit: cover; border-radius: 4px;" onerror="this.style.display='none';"><?php endif; ?><i class="bi bi-image text-muted" style="<?= !empty($item['product_image_url']) ? 'display:none;' : '' ?>"></i></div></td>
                                <td><strong><?= htmlspecialchars($item['item_name']) ?></strong></td>
                                <td><?= htmlspecialchars($item['category'] ?? 'Uncategorized') ?></td>
                                <td><?= htmlspecialchars($item['principal'] ?? 'No Principal') ?></td>
                                <?php if ($items_branch_column_exists && $view_all_branches): ?><td><span class="badge bg-info">Branch <?= $item['branch_id'] ?? 'N/A' ?></span></td><?php endif; ?>
                                <td><span class="<?= $item['quantity_on_hand'] <= $item['reorder_level'] ? 'text-danger fw-bold' : '' ?>"><?= $item['stock_display'] ?></span><span class="badge <?= $stock_status['class'] ?> ms-1"><?= $stock_status['label'] ?></span></td>
                                <td class="col-status"><div class="status-toggle"><label class="toggle-switch"><input type="checkbox" class="status-checkbox" data-id="<?= $item['item_id'] ?>" <?= $item['status'] === 'active' ? 'checked' : '' ?> onchange="toggleItemStatus(<?= $item['item_id'] ?>, this)"><span class="toggle-slider"></span></label></div></td>
                                <td class="col-actions"><div class="action-buttons"><button class="btn-action btn-edit" onclick="event.stopPropagation(); editItem(<?= $item['item_id'] ?>)" title="Edit"><i class="bi bi-pencil"></i></button><button class="btn-action btn-delete" onclick="event.stopPropagation(); deleteItem(<?= $item['item_id'] ?>)" title="Delete"><i class="bi bi-trash"></i></button></div></td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody></table></div>
                            <div class="no-items-message" id="no-items-<?= $tab_id ?>" style="display: none;"><i class="bi bi-inbox"></i><h5>No items match your filters</h5><p class="text-muted">Try adjusting your search or filter criteria</p><button class="btn btn-sm btn-outline-primary" onclick="clearAllFilters()"><i class="bi bi-x-circle"></i> Clear Filters</button></div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- SUPPLIER VIEW -->
                <div id="supplierView" style="display: none;">
                    <?php if (empty($supplier_items)): ?>
                        <div class="empty-state text-center py-5"><i class="bi bi-building fs-1 d-block text-muted mb-2"></i><p class="text-muted">No items found for any supplier</p></div>
                    <?php else: ?>
                        <div class="category-tabs">
                            <div class="category-tab active" onclick="switchSupplierTab('sup-tab-all', this)" data-tab="sup-tab-all"><i class="bi bi-building"></i> All Suppliers<span class="tab-badge"><?= count($supplier_items) ?></span></div>
                            <?php foreach ($items_by_supplier as $supplier => $supplier_items_group): $tab_id = 'sup-tab-' . preg_replace('/[^a-z0-9]/i', '-', strtolower($supplier)); ?>
                            <div class="category-tab" onclick="switchSupplierTab('<?= $tab_id ?>', this)" data-tab="<?= $tab_id ?>"><i class="bi bi-building"></i> <?= htmlspecialchars($supplier) ?><span class="tab-badge"><?= count($supplier_items_group) ?></span></div>
                            <?php endforeach; ?>
                        </div>
                        
                        <div id="sup-tab-all" class="tab-content active">
                            <div class="table-container"><table class="table custom-table compact-table"><thead><th class="col-image">Image</th><th>Item Name</th><th>Category</th><th>Principal</th><?php if ($items_branch_column_exists && $view_all_branches): ?><th>Branch</th><?php endif; ?><th>Stock</th><th class="col-status">Active</th><th class="col-actions">Actions</th> </thead><tbody>
                            <?php foreach ($supplier_items as $item): $stock_status = getStockStatus($item['quantity_on_hand'], $item['reorder_level']); ?>
                            <tr class="inventory-row" data-id="<?= $item['item_id'] ?>" data-code="<?= htmlspecialchars($item['item_code']) ?>" data-barcode="<?= htmlspecialchars($item['barcode'] ?? '') ?>" data-name="<?= htmlspecialchars($item['item_name']) ?>" data-category="<?= htmlspecialchars($item['category'] ?? '') ?>" data-principal="<?= htmlspecialchars($item['principal'] ?? 'No Principal') ?>" data-status="<?= $item['status'] ?>" data-stock="<?= $item['quantity_on_hand'] ?>" data-reorder="<?= $item['reorder_level'] ?>" data-price="0" data-unit="<?= $item['unit_type'] ?>" data-branch="<?= $item['branch_id'] ?? '' ?>">
                                <td class="col-image"><div class="item-thumbnail" data-item-id="<?= $item['item_id'] ?>"><?php if (!empty($item['product_image_url'])): ?><img src="../uploads/products/<?= htmlspecialchars($item['product_image_url']) ?>" alt="<?= htmlspecialchars($item['item_name']) ?>" style="width: 40px; height: 40px; object-fit: cover; border-radius: 4px;" onerror="this.style.display='none';"><?php endif; ?><i class="bi bi-image text-muted" style="<?= !empty($item['product_image_url']) ? 'display:none;' : '' ?>"></i></div></td>
                                <td><strong><?= htmlspecialchars($item['item_name']) ?></strong></td>
                                <td><?= htmlspecialchars($item['category'] ?? 'Uncategorized') ?></td>
                                <td><?= htmlspecialchars($item['principal'] ?? 'No Principal') ?></td>
                                <?php if ($items_branch_column_exists && $view_all_branches): ?><td><span class="badge bg-info">Branch <?= $item['branch_id'] ?? 'N/A' ?></span></td><?php endif; ?>
                                <td><span class="<?= $item['quantity_on_hand'] <= $item['reorder_level'] ? 'text-danger fw-bold' : '' ?>"><?= $item['stock_display'] ?></span><span class="badge <?= $stock_status['class'] ?> ms-1"><?= $stock_status['label'] ?></span></td>
                                <td class="col-status"><div class="status-toggle"><label class="toggle-switch"><input type="checkbox" class="status-checkbox" data-id="<?= $item['item_id'] ?>" <?= $item['status'] === 'active' ? 'checked' : '' ?> onchange="toggleItemStatus(<?= $item['item_id'] ?>, this)"><span class="toggle-slider"></span></label></div></td>
                                <td class="col-actions"><div class="action-buttons"><button class="btn-action btn-view" onclick="event.stopPropagation(); viewItem(<?= $item['item_id'] ?>)" title="View"><i class="bi bi-eye"></i></button><button class="btn-action btn-edit" onclick="event.stopPropagation(); editItem(<?= $item['item_id'] ?>)" title="Edit"><i class="bi bi-pencil"></i></button><button class="btn-action btn-delete" onclick="event.stopPropagation(); deleteItem(<?= $item['item_id'] ?>)" title="Delete"><i class="bi bi-trash"></i></button></div></td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody></table></div>
                        </div>
                        
                        <?php foreach ($items_by_supplier as $supplier => $supplier_items_group): $tab_id = 'sup-tab-' . preg_replace('/[^a-z0-9]/i', '-', strtolower($supplier)); ?>
                        <div id="<?= $tab_id ?>" class="tab-content" data-supplier="<?= htmlspecialchars($supplier) ?>">
                            <div class="table-container"><table class="table custom-table compact-table"><thead><th class="col-image">Image</th><th>Item Name</th><th>Category</th><th>Principal</th><?php if ($items_branch_column_exists && $view_all_branches): ?><th>Branch</th><?php endif; ?><th>Stock</th><th class="col-status">Active</th><th class="col-actions">Actions</th> </thead><tbody>
                            <?php foreach ($supplier_items_group as $item): $stock_status = getStockStatus($item['quantity_on_hand'], $item['reorder_level']); ?>
                            <tr class="inventory-row" data-id="<?= $item['item_id'] ?>" data-code="<?= htmlspecialchars($item['item_code']) ?>" data-barcode="<?= htmlspecialchars($item['barcode'] ?? '') ?>" data-name="<?= htmlspecialchars($item['item_name']) ?>" data-category="<?= htmlspecialchars($item['category'] ?? '') ?>" data-principal="<?= htmlspecialchars($item['principal'] ?? 'No Principal') ?>" data-status="<?= $item['status'] ?>" data-stock="<?= $item['quantity_on_hand'] ?>" data-reorder="<?= $item['reorder_level'] ?>" data-price="0" data-unit="<?= $item['unit_type'] ?>" data-branch="<?= $item['branch_id'] ?? '' ?>">
                                <td class="col-image"><div class="item-thumbnail" data-item-id="<?= $item['item_id'] ?>"><i class="bi bi-image text-muted"></i></div></td>
                                <td><strong><?= htmlspecialchars($item['item_name']) ?></strong></td>
                                <td><?= htmlspecialchars($item['category'] ?? 'Uncategorized') ?></td>
                                <td><?= htmlspecialchars($item['principal'] ?? 'No Principal') ?></td>
                                <?php if ($items_branch_column_exists && $view_all_branches): ?><td><span class="badge bg-info">Branch <?= $item['branch_id'] ?? 'N/A' ?></span></td><?php endif; ?>
                                <td><span class="<?= $item['quantity_on_hand'] <= $item['reorder_level'] ? 'text-danger fw-bold' : '' ?>"><?= $item['stock_display'] ?></span><span class="badge <?= $stock_status['class'] ?> ms-1"><?= $stock_status['label'] ?></span></td>
                                <td class="col-status"><div class="status-toggle"><label class="toggle-switch"><input type="checkbox" class="status-checkbox" data-id="<?= $item['item_id'] ?>" <?= $item['status'] === 'active' ? 'checked' : '' ?> onchange="toggleItemStatus(<?= $item['item_id'] ?>, this)"><span class="toggle-slider"></span></label></div></td>
                                <td class="col-actions"><div class="action-buttons"><button class="btn-action btn-view" onclick="event.stopPropagation(); viewItem(<?= $item['item_id'] ?>)" title="View"><i class="bi bi-eye"></i></button><button class="btn-action btn-edit" onclick="event.stopPropagation(); editItem(<?= $item['item_id'] ?>)" title="Edit"><i class="bi bi-pencil"></i></button><button class="btn-action btn-delete" onclick="event.stopPropagation(); deleteItem(<?= $item['item_id'] ?>)" title="Delete"><i class="bi bi-trash"></i></button></div></td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody></table></div>
                            <div class="no-items-message" id="no-items-<?= $tab_id ?>" style="display: none;"><i class="bi bi-inbox"></i><h5>No items match your filters</h5><p class="text-muted">Try adjusting your search or filter criteria</p><button class="btn btn-sm btn-outline-primary" onclick="clearAllFilters()"><i class="bi bi-x-circle"></i> Clear Filters</button></div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

            </div>
        </div>
    </div>

<!-- Mobile Bottom Navigation - Clean Version (No Arrows) -->
<div class="mobile-nav" id="mobileNav">
    <ul class="nav">
        <!-- Dashboard -->
        <li class="nav-item">
            <a class="nav-link active" href="branchdashboard.php">
                <i class="bi bi-speedometer2"></i>
                <span>Dashboard</span>
            </a>
        </li>

        <!-- Warehouse Dropdown -->
        <li class="nav-item dropdown-more" id="warehouseMobileDropdown">
            <a class="nav-link more-btn" href="#" onclick="toggleMobileDropdown(event, 'warehouseMobileMenu')">
                <i class="bi bi-shop"></i>
                <span>Warehouse</span>
            </a>
            <div class="more-dropdown" id="warehouseMobileMenu">
                <a href="current_inventory.php" class="dropdown-item">
                    <i class="bi bi-bar-chart-line"></i><span>Current Inventory</span>
                </a>
                <a href="bad_orders.php" class="dropdown-item">
                    <i class="bi bi-recycle"></i><span>Bad Orders</span>
                </a>
                <a href="pick_list_items.php" class="dropdown-item">
                    <i class="bi bi-list-check"></i><span>Pick List Items</span>
                </a>
                <a href="warehouses.php" class="dropdown-item">
                    <i class="bi bi-shop"></i><span>Warehouses</span>
                </a>
            </div>
        </li>

        <!-- Supplier Dropdown -->
        <li class="nav-item dropdown-more" id="supplierMobileDropdown">
            <a class="nav-link more-btn" href="#" onclick="toggleMobileDropdown(event, 'supplierMobileMenu')">
                <i class="bi bi-building"></i>
                <span>Supplier</span>
            </a>
            <div class="more-dropdown" id="supplierMobileMenu">
                <a href="purchase_order.php" class="dropdown-item">
                    <i class="bi bi-box"></i><span>Receive Inventory</span>
                </a>
                <a href="supplier.php" class="dropdown-item">
                    <i class="bi bi-people"></i><span>Supplier List</span>
                </a>
            </div>
        </li>

        <!-- Customer Dropdown -->
        <li class="nav-item dropdown-more" id="customerMobileDropdown">
            <a class="nav-link more-btn" href="#" onclick="toggleMobileDropdown(event, 'customerMobileMenu')">
                <i class="bi bi-people"></i>
                <span>Customer</span>
            </a>
            <div class="more-dropdown" id="customerMobileMenu">
                <a href="customer_list.php" class="dropdown-item">
                    <i class="bi bi-person-badge"></i><span>Customer List</span>
                </a>
                <a href="approve_credit_requests.php" class="dropdown-item">
                    <i class="bi bi-pencil-square"></i><span>Approve Credit Request</span>
                </a>
                <a href="sales_order.php" class="dropdown-item">
                    <i class="bi bi-cart"></i><span>Sales Order</span>
                </a>
                <a href="collections.php" class="dropdown-item">
                    <i class="bi bi-cash-stack"></i><span>Collections</span>
                </a>
            </div>
        </li>

        <!-- Delivery Dropdown -->
        <li class="nav-item dropdown-more" id="deliveryMobileDropdown">
            <a class="nav-link more-btn" href="#" onclick="toggleMobileDropdown(event, 'deliveryMobileMenu')">
                <i class="bi bi-truck"></i>
                <span>Delivery</span>
            </a>
            <div class="more-dropdown" id="deliveryMobileMenu">
                <a href="trip_tickets.php" class="dropdown-item">
                    <i class="bi bi-ticket-perforated"></i><span>Trip Tickets</span>
                </a>
            </div>
        </li>

        <!-- Banking Dropdown -->
        <li class="nav-item dropdown-more" id="bankingMobileDropdown">
            <a class="nav-link more-btn" href="#" onclick="toggleMobileDropdown(event, 'bankingMobileMenu')">
                <i class="bi bi-bank2"></i>
                <span>Banking</span>
            </a>
            <div class="more-dropdown" id="bankingMobileMenu">
                <a href="deposit.php" class="dropdown-item">
                    <i class="bi bi-arrow-down-circle"></i><span>Deposit</span>
                </a>
                <a href="Withdrawal.php" class="dropdown-item">
                    <i class="bi bi-arrow-up-circle"></i><span>Withdrawal</span>
                </a>
                <a href="bank_statement.php" class="dropdown-item">
                    <i class="bi bi-receipt"></i><span>Bank Statement</span>
                </a>
                <a href="expenses.php" class="dropdown-item">
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
            <a class="nav-link" href="drivers.php">
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

   <!-- ADD ITEM MODAL -->
<div class="modal fade" id="itemModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable" style="max-width: 95%;">
        <div class="modal-content">
            <div class="modal-header sticky-top bg-white" style="z-index: 10; border-bottom: 1px solid #dee2e6;">
                <h5 class="modal-title" id="itemModalTitle">
                    <i class="bi bi-plus-circle me-2"></i>Add New Item
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="itemForm" enctype="multipart/form-data">
                    <input type="hidden" id="itemId">
                    <?php if ($items_branch_column_exists && !$view_all_branches && $branch_id > 0): ?>
                        <input type="hidden" name="branch_id" value="<?= $branch_id ?>">
                    <?php endif; ?>

                    <div class="alert alert-light border d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 mb-4">
                        <div>
                            <div class="fw-bold text-dark"><i class="bi bi-file-earmark-arrow-up me-2 text-success"></i>Excel Import / Export</div>
                            <small class="text-muted">Export current items, edit the Excel file, then import it to add or update/overwrite item columns. Supported: XLSX, XLS, CSV.</small>
                        </div>
                        <div class="d-flex flex-wrap gap-2">
                            <button type="button" class="btn btn-outline-success btn-sm" onclick="downloadItemsImportTemplate()">
                                <i class="bi bi-download me-1"></i>Export Items
                            </button>
                            <button type="button" class="btn btn-outline-primary btn-sm" onclick="document.getElementById('importItemsFile').click()">
                                <i class="bi bi-upload me-1"></i>Import File
                            </button>
                        </div>
                    </div>
                    
                    <!-- SECTION 1: Basic Information -->
                    <div style="margin-bottom: 2rem;">
                        <h6 class="mb-3" style="color: #495057; font-weight: 600; border-bottom: 2px solid #0d6efd; padding-bottom: 0.5rem;">
                            <i class="bi bi-info-circle me-2" style="color: #0d6efd;"></i>Basic Information
                        </h6>
                        <div class="row g-3">
                            <div class="col-12 col-md-6">
                                <label for="itemCode" class="form-label">Item Code *</label>
                                <div class="input-group">
                                    <input type="text" class="form-control" id="itemCode" value="<?= $next_item_code ?>" required readonly>
                                    <button type="button" class="btn btn-outline-secondary" id="editItemCodeBtn" onclick="toggleItemCodeEdit()">
                                        <i class="bi bi-pencil"></i> Edit
                                    </button>
                                </div>
                                <small class="text-muted">Auto-generated (click Edit to modify)</small>
                            </div>
                            
                            <div class="col-12 col-md-6">
                                <label for="itemName" class="form-label">Item Name</label>
                                <input type="text" class="form-control" id="itemName" required>
                            </div>
                            
                            <div class="col-12">
                                <label for="description" class="form-label">Description</label>
                                <textarea class="form-control" id="description" rows="2" placeholder="Enter item description..." required></textarea>
                            </div>
                            
                            <div class="col-12 col-md-4">
                                <label for="category" class="form-label">Category *</label>
                                <input 
                                    type="text" 
                                    class="form-control" 
                                    id="category" 
                                    list="categoryList" 
                                    placeholder="Type or select a category..."
                                    autocomplete="off">
                                <datalist id="categoryList">
                                    <option value="Cement">
                                    <option value="Oil">
                                    <option value="General">
                                </datalist>
                            </div>
                            
                            <div class="col-12 col-md-4">
                                <label for="principal" class="form-label">Principal</label>
                                <input
                                    type="text"
                                    class="form-control"
                                    id="principal"
                                    list="principalList"
                                    placeholder="Type or select principal..."
                                    autocomplete="off">
                                <datalist id="principalList">
                                    <?php foreach ($principalOptions as $principalOption): ?>
                                        <option value="<?= htmlspecialchars($principalOption) ?>">
                                    <?php endforeach; ?>
                                </datalist>
                                <small class="text-muted">Leave blank if no principal. Type a new name here to add it automatically.</small>
                            </div>

                            <div class="col-12 col-md-4" id="volumeField" style="display: none;">
                                <label for="volume" class="form-label">Volume (KG)</label>
                                <input
                                    type="text"
                                    class="form-control"
                                    id="volume"
                                    placeholder="e.g., 5, 10, 20...">
                            </div>

                            <div class="col-12 col-md-4" id="oilTypeField" style="display: none;">
                                <label for="oilType" class="form-label">Oil Type</label>
                                <select class="form-select" id="oilType">
                                    <option value="">-- Select Oil Type --</option>
                                    <option value="Palm">Palm</option>
                                    <option value="Coconut">Coconut</option>
                                </select>
                            </div>

                            <div class="col-12 col-md-4">
                                <label for="reorderLevel" class="form-label">Reorder Level </label>
                                <input type="number" class="form-control" id="reorderLevel" min="0" required placeholder="0">
                            </div>
                            
                            <div class="col-12 col-md-4">
                                <label for="status" class="form-label">Status *</label>
                                <select class="form-select" id="status">
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                    <option value="discontinued">Discontinued</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <!-- SECTION 2: Images -->
                    <div style="margin-bottom: 2rem;">
                        <h6 class="mb-3" style="color: #495057; font-weight: 600; border-bottom: 2px solid #198754; padding-bottom: 0.5rem;">
                            <i class="bi bi-images me-2" style="color: #198754;"></i>Item Images
                        </h6>
                        <div class="row g-3">
                            <div class="col-12">
                                <label for="itemImages" class="form-label">Upload Images (Multiple)</label>
                                <input type="file" class="form-control" id="itemImages" name="itemImages[]" accept="image/*" multiple>
                                <small class="text-muted">Supported formats: JPG, PNG, GIF, WebP (Max 5MB per image). First image will be the primary.</small>
                            </div>
                        </div>
                    </div>
                    
                    <!-- SECTION 3: Unit Types & Pricing -->
                    <div style="margin-bottom: 2rem;">
                        <h6 class="mb-3" style="color: #495057; font-weight: 600; border-bottom: 2px solid #6f42c1; padding-bottom: 0.5rem;">
                            <i class="bi bi-boxes me-2" style="color: #6f42c1;"></i>Unit Types & Pricing
                        </h6>
                        
                        <!-- Unit Types Table -->
                        <div class="mb-3">
                            <label class="form-label fw-bold">Unit of Measure (UoM) Types</label>
                            <div class="table-responsive">
                                <table class="table table-bordered" id="unitTypesTable" style="font-size: 0.85rem;">
                                    <thead class="table-light">
                                        <tr>
                                            <th style="width: 12%">UoM</th>
                                            <th style="width: 8%">Initial</th>
                                            <th style="width: 10%">Barcode</th>
                                            <th style="width: 10%">Smallest Pack Qty</th>
                                            <th style="width: 12%">Current Inventory</th>
                                            <th style="width: 12%">As of</th>
                                            <th style="width: 10%">Default UoM</th>
                                            <th style="width: 12%">Unit Cost</th>
                                            <th style="width: 12%">Total Cost</th>
                                            <th style="width: 10%">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody id="unitTypesBody">
                                        <!-- Rows will be added dynamically -->
                                    </tbody>
                                </table>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-primary mt-2" onclick="addUnitTypeRow()">
                                <i class="bi bi-plus-circle"></i> Add Unit Type
                            </button>
                        </div>
                        
                        <!-- Pricing Table -->
                        <div>
                            <label class="form-label fw-bold">Pricing per UoM</label>
                            <div class="table-responsive">
                                <table class="table table-bordered" id="pricingTable" style="font-size: 0.85rem;">
                                    <thead class="table-light" id="pricingTableHead">
                                        <tr>
                                            <th style="width: 20%">Effective Date</th>
                                            <th style="width: 20%">Price Level</th>
                                            <!-- Dynamic UoM columns will be added here -->
                                            <th style="width: 10%">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody id="pricingBody">
                                        <!-- Rows will be added dynamically -->
                                    </tbody>
                                </table>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-primary mt-2" onclick="addPricingRow()">
                                <i class="bi bi-plus-circle"></i> Add Price Row
                            </button>
                            <small class="text-muted d-block mt-1">Add prices for each unit type above. At least one price is required.</small>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer sticky-bottom bg-white" style="border-top: 1px solid #dee2e6; z-index: 10;">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x-circle me-1"></i>Cancel
                </button>
                <button type="button" class="btn btn-primary" onclick="saveItem()">
                    <i class="bi bi-check-circle me-1"></i>Save Item
                </button>
            </div>
        </div>
    </div>
</div>

   <!-- VIEW ITEM MODAL -->
<div class="modal fade" id="batchPriceLevelModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable" style="max-width: 95%;">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-cash-coin me-2"></i>Batch Update Price Level</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3 mb-3">
                    <div class="col-12 col-md-3">
                        <label for="batchPriceEffectiveDate" class="form-label">Effective Date</label>
                        <input type="date" class="form-control" id="batchPriceEffectiveDate">
                    </div>
                    <div class="col-12 col-md-3">
                        <label for="batchPriceLevelSelect" class="form-label">Price Level</label>
                        <select class="form-select" id="batchPriceLevelSelect" onchange="handleBatchPriceLevelSelectionChange()">
                            <?php foreach ($priceLevels as $level): ?>
                                <option value="<?= htmlspecialchars($level) ?>" <?= $level === 'Standard' ? 'selected' : '' ?>><?= htmlspecialchars($level) ?></option>
                            <?php endforeach; ?>
                            <option value="__new__">+ Add New Price Level</option>
                        </select>
                    </div>
                    <div class="col-12 col-md-3" id="batchNewPriceLevelWrapper" style="display:none;">
                        <label for="batchNewPriceLevel" class="form-label">New Price Level</label>
                        <input type="text" class="form-control" id="batchNewPriceLevel" placeholder="Type new price level" oninput="handleBatchNewPriceLevelInput()">
                    </div>
                    <div class="col-12 col-md-3">
                        <label for="batchPriceSearch" class="form-label">Search Item</label>
                        <input type="text" class="form-control" id="batchPriceSearch" placeholder="Search item name, description, or UoM..." oninput="filterBatchPriceLevelRows()">
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-bordered align-middle" id="batchPriceLevelTable">
                        <thead class="table-light">
                            <tr>
                                <th>Item Name</th>
                                <th>Item Description</th>
                                <th>UoM</th>
                                <th>Current Price</th>
                                <th>New Price</th>
                            </tr>
                        </thead>
                        <tbody id="batchPriceLevelBody">
                            <tr>
                                <td colspan="5" class="text-center text-muted py-4">Select the effective date and price level to load items.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="bi bi-x-circle me-1"></i>Close</button>
                <button type="button" class="btn btn-warning" onclick="saveBatchPriceLevelUpdates()"><i class="bi bi-check-circle me-1"></i>Update</button>
            </div>
        </div>
    </div>
</div>

<!-- BATCH PRICE UPDATE DETAILS MODAL -->
<div class="modal fade" id="batchPriceUpdateDetailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable" style="max-width: 95%;">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-list-check me-2"></i>Updated Price Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-success d-flex align-items-start gap-2 mb-3">
                    <i class="bi bi-check-circle-fill fs-5"></i>
                    <div>
                        <strong>Price update details</strong>
                        <div class="small mb-0">Only items with actual updated prices are shown below.</div>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-bordered align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Item Code</th>
                                <th>Item Name</th>
                                <th>UoM</th>
                                <th>Price Level</th>
                                <th>Old Price</th>
                                <th>New Price</th>
                                <th>Effective Date</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody id="batchPriceUpdateDetailsBody">
                            <tr>
                                <td colspan="8" class="text-center text-muted py-4">No updated items.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="bi bi-x-circle me-1"></i>Close</button>
                <button type="button" class="btn btn-primary" data-bs-dismiss="modal"><i class="bi bi-check-circle me-1"></i>OK</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="viewItemModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-box-seam me-2"></i>
                    Item Details
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <div class="item-details-container" id="viewItemContent">
                    <div class="text-center py-5">
                        <div class="spinner-border text-success" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        <p class="mt-3 text-muted">Loading item details...</p>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-success" onclick="printItemBarcodeLabels()">
                    <i class="bi bi-printer me-1"></i> Print Barcode
                </button>
                <button type="button" class="btn btn-warning" onclick="editFromView()">
                    <i class="bi bi-pencil me-1"></i> Edit Item
                </button>
            </div>
        </div>
    </div>
</div>

    <!-- EDIT ITEM MODAL -->
<div class="modal fade" id="editItemModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable" style="max-width: 95%;">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-pencil-square me-2"></i>
                    Edit Item
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="editItemForm" enctype="multipart/form-data">
                    <input type="hidden" id="editItemId">
                    
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="editItemCode" class="form-label">Item Code</label>
                            <input type="text" class="form-control" id="editItemCode" readonly>
                        </div>
                        <div class="col-md-6">
                            <label for="editItemName" class="form-label">Item Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="editItemName" required>
                        </div>
                        
                        <div class="col-12">
                            <label for="editDescription" class="form-label">Description</label>
                            <textarea class="form-control" id="editDescription" rows="2" required></textarea>
                        </div>
                        
                        <div class="col-md-4">
                            <label for="editCategory" class="form-label">Category</label>
                            <input 
                                type="text" 
                                class="form-control" 
                                id="editCategory" 
                                list="editCategoryList" 
                                placeholder="Type or select a category..."
                                autocomplete="off">
                            <datalist id="editCategoryList">
                                <option value="Cement">
                                <option value="Oil">
                                <option value="General">
                            </datalist>
                        </div>
                        
                        <div class="col-md-4">
                            <label for="editPrincipal" class="form-label">Principal</label>
                            <input
                                type="text"
                                class="form-control"
                                id="editPrincipal"
                                list="editPrincipalList"
                                placeholder="Type or select principal...(Optional)"
                                autocomplete="off">
                            <datalist id="editPrincipalList">
                                <?php foreach ($principalOptions as $principalOption): ?>
                                    <option value="<?= htmlspecialchars($principalOption) ?>">
                                <?php endforeach; ?>
                            </datalist>
                            <small class="text-muted">Leave blank if no principal. Type a new name here to add it automatically.</small>
                        </div>

                        <div class="col-md-4" id="editVolumeField" style="display: none;">
                            <label for="editVolume" class="form-label">Volume (KG)</label>
                            <input
                                type="text"
                                class="form-control"
                                id="editVolume"
                                placeholder="e.g., 5, 10, 20...">
                        </div>

                        <div class="col-md-4" id="editOilTypeField" style="display: none;">
                            <label for="editOilType" class="form-label">Oil Type</label>
                            <select class="form-select" id="editOilType">
                                <option value="">-- Select Oil Type --</option>
                                <option value="Palm">Palm</option>
                                <option value="Coconut">Coconut</option>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label for="editReorderLevel" class="form-label">Reorder Level <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="editReorderLevel" min="0" required>
                        </div>
                        
                        <div class="col-md-4">
                            <label for="editStatus" class="form-label">Status</label>
                            <select class="form-select" id="editStatus">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                                <option value="discontinued">Discontinued</option>
                            </select>
                        </div>
                        
                        <!-- Images Section -->
                        <div class="col-12">
                            <label for="editItemImages" class="form-label">Add More Images</label>
                            <input type="file" class="form-control" id="editItemImages" name="editItemImages[]" accept="image/*" multiple>
                            <small class="text-muted">Upload additional images for this item.</small>
                            <div id="existingImagesContainer" class="mt-2"></div>
                        </div>
                        
                        <!-- Unit Types Section -->
                        <div class="col-12">
                            <h6 class="mt-3 mb-2">Unit Types & Pricing</h6>
                            <div class="table-responsive">
                                <table class="table table-bordered" id="editUnitTypesTable" style="font-size: 0.85rem;">
                                    <thead class="table-light">
                                        <tr>
                                            <th style="width: 12%">UoM</th>
                                            <th style="width: 8%">Initial</th>
                                            <th style="width: 10%">Barcode</th>
                                            <th style="width: 10%">Smallest Pack Qty</th>
                                            <th style="width: 12%">Current Inventory</th>
                                            <th style="width: 12%">As of</th>
                                            <th style="width: 10%">Default UoM</th>
                                            <th style="width: 12%">Unit Cost</th>
                                            <th style="width: 12%">Total Cost</th>
                                            <th style="width: 10%">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody id="editUnitTypesBody">
                                        <!-- Rows will be populated dynamically -->
                                    </tbody>
                                </table>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-primary mt-2" onclick="addEditUnitTypeRow()">
                                <i class="bi bi-plus-circle"></i> Add Unit Type
                            </button>
                        </div>
                        
                        <!-- Pricing Section -->
                        <div class="col-12">
                            <div class="table-responsive mt-3">
                                <table class="table table-bordered" id="editPricingTable" style="font-size: 0.85rem;">
                                    <thead class="table-light" id="editPricingTableHead">
                                        <tr>
                                            <th style="width: 20%">Effective Date</th>
                                            <th style="width: 20%">Price Level</th>
                                            <!-- Dynamic UoM columns will be added here -->
                                            <th style="width: 10%">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody id="editPricingBody">
                                        <!-- Rows will be populated dynamically -->
                                    </tbody>
                                </table>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-primary mt-2" onclick="addEditPricingRow()">
                                <i class="bi bi-plus-circle"></i> Add Price Row
                            </button>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x-circle me-1"></i> Cancel
                </button>
                <button type="button" class="btn btn-primary" onclick="updateItem()">
                    <i class="bi bi-check-circle me-1"></i> Update Item
                </button>
            </div>
        </div>
    </div>
</div>

   <!-- SUPPLIER SELECTOR MODAL -->
<div class="modal fade" id="supplierSelectorModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-building me-2"></i>
                    Supplier Information
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="supplier-selector-wrapper mb-4">
                    <label for="supplierSelect" class="form-label fw-bold">
                        <i class="bi bi-search me-1"></i> Select Supplier
                    </label>
                    <select class="form-select select2-supplier" id="supplierSelect" style="width: 100%;">
                        <option value="">-- Choose Supplier --</option>
                    </select>
                </div>
                
                <div id="supplierDetailsContainer" style="display: none;">
                    <div class="supplier-info-card mb-4" id="supplierSummary"></div>
                    <h6 class="fw-bold mt-4 mb-3 section-title">
                        <i class="bi bi-receipt me-2"></i>Purchase Orders
                    </h6>
                    <div id="supplierPurchaseOrders" class="supplier-purchase-orders"></div>
                </div>
                
                <div id="noSupplierSelected" class="text-center py-5">
                    <i class="bi bi-building" style="font-size: 64px; color: #dee2e6;"></i>
                    <p class="mt-3 text-muted">Select a supplier to view details and purchase order history</p>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- LOW STOCK MODAL -->
<div class="modal fade" id="lowStockModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    Low Stock Items
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning mb-4">
                    <i class="bi bi-info-circle me-2"></i>
                    These items are below their reorder level and need attention.
                </div>
                <div id="lowStockItemsContainer" class="low-stock-items-container"></div>
                <div id="noLowStockItems" class="text-center py-5" style="display: none;">
                    <i class="bi bi-check-circle" style="font-size: 64px; color: #10b981;"></i>
                    <p class="mt-3 text-muted">No low stock items found</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" onclick="filterLowStock()">
                    <i class="bi bi-funnel me-1"></i> Show in Table
                </button>
            </div>
        </div>
    </div>
</div>

<!-- OFFTAKE MODAL -->
<div class="modal fade" id="offtakeModal" tabindex="-1" data-bs-backdrop="true" data-bs-keyboard="true" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-graph-up-arrow me-2"></i>
                    Average Daily Offtake Analysis
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <!-- Date Filter Row -->
                <div class="filter-row">
                    <div class="filter-group">
                        <label>
                            <i class="bi bi-calendar3"></i> Start Date
                        </label>
                        <input type="date" id="offtakeStartDate" value="<?= date('Y-m-d', strtotime('-30 days')) ?>">
                    </div>
                    <div class="filter-group">
                        <label>
                            <i class="bi bi-calendar3"></i> End Date
                        </label>
                        <input type="date" id="offtakeEndDate" value="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="filter-group filter-action">
                        <button class="apply-btn" onclick="loadOfftakeData()">
                            <i class="bi bi-funnel"></i> Apply Filter
                        </button>
                    </div>
                </div>

                <!-- Stats Cards -->
                <div class="stats-container">
                    <div class="stats-card stats-card-purple">
                        <div class="stats-icon">
                            <i class="bi bi-graph-up"></i>
                        </div>
                        <div class="stats-content">
                            <div class="stats-value" id="summaryAvgDaily">0</div>
                            <div class="stats-label">Avg Daily Offtake</div>
                        </div>
                    </div>
                    <div class="stats-card stats-card-green">
                        <div class="stats-icon">
                            <i class="bi bi-box-seam"></i>
                        </div>
                        <div class="stats-content">
                            <div class="stats-value" id="summaryTotalQty">0</div>
                            <div class="stats-label">Total Quantity</div>
                        </div>
                    </div>
                    <div class="stats-card stats-card-blue">
                        <div class="stats-icon">
                            <i class="bi bi-calendar-check"></i>
                        </div>
                        <div class="stats-content">
                            <div class="stats-value" id="summaryActiveDays">0</div>
                            <div class="stats-label">Active Days</div>
                        </div>
                    </div>
                    <div class="stats-card stats-card-orange">
                        <div class="stats-icon">
                            <i class="bi bi-pie-chart"></i>
                        </div>
                        <div class="stats-content">
                            <div class="stats-value" id="summaryPerItem">0</div>
                            <div class="stats-label">Per Item Avg</div>
                        </div>
                    </div>
                </div>

                <!-- Daily Breakdown Table -->
                <div class="table-wrapper">
                    <div class="table-header">
                        <div class="table-title">
                            <i class="bi bi-table"></i>
                            <span>Daily Breakdown</span>
                        </div>
                        <div class="table-actions">
                            <button class="btn-excel" onclick="exportOfftakeToExcel()">
                                <i class="bi bi-file-earmark-excel"></i> Export
                            </button>
                            <button class="btn-print" onclick="printOfftakeReport()">
                                <i class="bi bi-printer"></i> Print
                            </button>
                        </div>
                    </div>
                    <div class="table-container">
                        <table class="auto-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th class="text-center">Orders</th>
                                    <th class="text-center">Quantity</th>
                                    <th class="text-end">Amount (₱)</th>
                                </tr>
                            </thead>
                            <tbody id="offtakeTableBody">
                                <tr>
                                    <td colspan="4" class="empty-state">
                                        <i class="bi bi-arrow-up-circle"></i>
                                        <p>Select a date range to view offtake data</p>
                                    </tr>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Info Note -->
                <div class="info-note">
                    <i class="bi bi-info-circle"></i>
                    <span>Showing data for <span id="dateRangeDisplay">selected period</span></span>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Barcode Search Scanner Modal -->
<div class="modal fade" id="barcodeScannerModal" tabindex="-1" aria-labelledby="barcodeScannerModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius: 14px; overflow: hidden;">
            <div class="modal-header" style="background: linear-gradient(135deg, #198754, #146c43); color: #fff;">
                <h5 class="modal-title" id="barcodeScannerModalLabel"><i class="bi bi-upc-scan me-2"></i>Scan Barcode</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="barcodeSearchReader" style="width: 100%; min-height: 280px; border: 2px dashed #d1d5db; border-radius: 12px; overflow: hidden; background: #f8fafc;"></div>
                <div class="small text-muted mt-2">Itapat ang barcode sa camera. Kapag na-scan, automatic itong ise-search sa inventory table.</div>
                <div class="input-group mt-3">
                    <input type="text" class="form-control" id="manualBarcodeSearchInput" placeholder="Or type barcode manually">
                    <button class="btn btn-success" type="button" onclick="searchBarcodeManually()"><i class="bi bi-search"></i> Search</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
// ========== GLOBAL VARIABLES ==========
let currentItemId = null;
let currentView = 'category';
const branchId = <?php echo $branch_id; ?>;
const viewAllBranches = <?php echo $view_all_branches ? 'true' : 'false'; ?>;
const itemsBranchColumnExists = <?php echo $items_branch_column_exists ? 'true' : 'false'; ?>;
const logoBase64 = '<?php echo $logo_base64; ?>';
let suppliersList = [];
let activeFilters = { search: '', status: '', stockLevel: '', principal: '' };
let currentUnitTypes = []; // Store current unit types for pricing table

// ========== HELPER FUNCTIONS ==========
function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

// ========== FIX MODAL BACKDROP ISSUE ==========
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

function fixModalBackdrop(modalId) {
    const modal = document.getElementById(modalId);
    if (!modal) return;
    
    modal.addEventListener('hidden.bs.modal', function() {
        cleanupModalBackdrops();
    });
    
    modal.addEventListener('show.bs.modal', function() {
        cleanupModalBackdrops();
    });
}


// ========== MOBILE BOTTOM NAVBAR FIX ==========
// Keep this global because the mobile nav uses inline onclick="toggleMobileDropdown(...)"
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

// Backward compatibility if some old buttons still use toggleDropdown(...)
window.toggleDropdown = function(event, dropdownId) {
    return window.toggleMobileDropdown(event, dropdownId);
};

window.showProfileModal = function(event) {
    if (event) {
        event.preventDefault();
        event.stopPropagation();
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
});



// ========== MOBILE BOTTOM NAVBAR FUNCTIONS ==========
function getMobileDropdownIds() {
    return [
        'inventoryDropdownMenu',
        'salesDropdownMenu',
        'purchaseDropdownMenu',
        'moreDropdownMenu'
    ];
}

function closeAllDropdowns() {
    getMobileDropdownIds().forEach(function(id) {
        const dropdown = document.getElementById(id);
        if (!dropdown) return;

        dropdown.classList.remove('show');
        dropdown.style.removeProperty('position');
        dropdown.style.removeProperty('left');
        dropdown.style.removeProperty('right');
        dropdown.style.removeProperty('top');
        dropdown.style.removeProperty('bottom');
        dropdown.style.removeProperty('transform');
        dropdown.style.removeProperty('z-index');
    });

    document.querySelectorAll('.mobile-nav .more-btn, .more-btn').forEach(function(btn) {
        btn.classList.remove('active');
        btn.setAttribute('aria-expanded', 'false');
    });
}

function positionMobileDropdown(dropdown, btn) {
    if (!dropdown || !btn) return;

    const nav = btn.closest('.mobile-nav');
    const isMobileBottomNav = !!nav;

    if (!isMobileBottomNav) return;

    const btnRect = btn.getBoundingClientRect();
    const navRect = nav.getBoundingClientRect();
    const viewportWidth = window.innerWidth || document.documentElement.clientWidth;
    const dropdownWidth = Math.min(240, viewportWidth - 24);
    const centerX = btnRect.left + (btnRect.width / 2);
    const halfWidth = dropdownWidth / 2;
    const left = Math.max(12 + halfWidth, Math.min(centerX, viewportWidth - 12 - halfWidth));
    const bottom = Math.max(76, (window.innerHeight - navRect.top) + 10);

    dropdown.style.position = 'fixed';
    dropdown.style.left = left + 'px';
    dropdown.style.right = 'auto';
    dropdown.style.top = 'auto';
    dropdown.style.bottom = bottom + 'px';
    dropdown.style.transform = 'translateX(-50%) translateY(0)';
    dropdown.style.zIndex = '10000';
    dropdown.style.minWidth = dropdownWidth + 'px';
    dropdown.style.maxWidth = 'calc(100vw - 24px)';
}

function toggleDropdown(event, dropdownId) {
    if (event) {
        event.preventDefault();
        event.stopPropagation();
    }

    const dropdown = document.getElementById(dropdownId);
    const btn = event ? event.currentTarget : null;

    if (!dropdown) return;

    const isOpen = dropdown.classList.contains('show');

    closeAllDropdowns();

    if (!isOpen) {
        dropdown.classList.add('show');

        if (btn) {
            btn.classList.add('active');
            btn.setAttribute('aria-expanded', 'true');
            positionMobileDropdown(dropdown, btn);
        }
    }
}

function bindMobileBottomNavbarEvents() {
    if (window.__mobileBottomNavbarBound) return;
    window.__mobileBottomNavbarBound = true;

    document.addEventListener('click', function(e) {
        if (!e.target.closest('.mobile-nav')) {
            closeAllDropdowns();
        }
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeAllDropdowns();
        }
    });

    window.addEventListener('resize', closeAllDropdowns);
    window.addEventListener('orientationchange', closeAllDropdowns);

    document.querySelectorAll('.mobile-nav .dropdown-item').forEach(function(item) {
        item.addEventListener('click', function() {
            closeAllDropdowns();
        });
    });

    const profileModalEl = document.getElementById('profileModal');
    if (profileModalEl) {
        profileModalEl.addEventListener('show.bs.modal', function() {
            closeAllDropdowns();
        });
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bindMobileBottomNavbarEvents);
} else {
    bindMobileBottomNavbarEvents();
}

function showLoading() {
    Swal.fire({ 
        title: 'Processing...', 
        text: 'Please wait', 
        allowOutsideClick: false, 
        didOpen: () => Swal.showLoading(),
        width: '350px',
        padding: '1.5rem',
        customClass: {
            popup: 'loading-swal-popup'
        }
    });
}

// ========== FIXED: Load unit types for all items in table ==========
function loadAllItemUnitTypes() {
    const unitTypeElements = document.querySelectorAll('.unit-types-display');
    console.log('Found unit type elements:', unitTypeElements.length);
    
    unitTypeElements.forEach(element => {
        const itemId = element.getAttribute('data-item-id');
        if (itemId) {
            loadItemUnitTypes(itemId, element);
        }
    });
}

// Fetch unit types for a specific item
function loadItemUnitTypes(itemId, element) {
    const formData = new FormData();
    formData.append('action', 'get_item_unit_types');
    formData.append('item_id', itemId);
    
    fetch('current_inventory.php', { method: 'POST', body: formData })
        .then(response => response.json())
        .then(data => {
            if (data.success && data.unit_types && data.unit_types.length > 0) {
                const badges = data.unit_types.map(ut => 
                    `<span class="unit-type-badge">${escapeHtml(ut.unit_type_name)}: ₱${parseFloat(ut.unit_price).toFixed(2)}</span>`
                ).join('');
                element.innerHTML = badges;
            } else {
                element.innerHTML = '<span class="text-muted">No prices set</span>';
            }
        })
        .catch(error => console.log('Error loading unit types:', error));
}

// ========== TOGGLE FUNCTIONS ==========
function toggleView(view) {
    currentView = view;
    document.getElementById('viewCategoryBtn').classList.toggle('active', view === 'category');
    document.getElementById('viewSupplierBtn').classList.toggle('active', view === 'supplier');
    document.getElementById('categoryView').style.display = view === 'category' ? 'block' : 'none';
    document.getElementById('supplierView').style.display = view === 'supplier' ? 'block' : 'none';
    filterItems();
}

function switchCategoryTab(tabId, element) {
    document.querySelectorAll('#categoryView .category-tab').forEach(tab => tab.classList.remove('active'));
    element.classList.add('active');
    document.querySelectorAll('#categoryView .tab-content').forEach(content => content.classList.remove('active'));
    document.getElementById(tabId).classList.add('active');
    filterItems();
}

function switchSupplierTab(tabId, element) {
    document.querySelectorAll('#supplierView .category-tab').forEach(tab => tab.classList.remove('active'));
    element.classList.add('active');
    document.querySelectorAll('#supplierView .tab-content').forEach(content => content.classList.remove('active'));
    document.getElementById(tabId).classList.add('active');
    filterItems();
}

// ========== FILTER FUNCTIONS ==========
function updateFilterCountBadge() {
    const filterBadge = document.getElementById('filterCountBadge');
    if (filterBadge) {
        const activeFilterCount = Object.values(activeFilters).filter(v => v !== '' && v !== null).length;
        filterBadge.textContent = activeFilterCount;
    }
}

function initFilterToggle() {
    const filterHeader = document.getElementById('filterHeader');
    const filterContent = document.getElementById('filterContent');
    const filterToggleBtn = document.getElementById('filterToggleBtn');
    const filterIcon = document.getElementById('filterIcon');
    
    if (filterHeader && filterContent) {
        // Set initial state - collapsed
        filterContent.classList.add('collapsed');
        if (filterToggleBtn) filterToggleBtn.setAttribute('aria-expanded', 'false');
        
        // Make the entire header clickable
        filterHeader.addEventListener('click', function(e) {
            // Don't toggle if clicking on the button itself (to avoid double toggle)
            if (e.target.closest('.filter-toggle-btn')) {
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
        const isExpanded = filterContent.classList.contains('collapsed');
        
        if (isExpanded) {
            // Expand
            filterContent.classList.remove('collapsed');
            if (filterToggleBtn) filterToggleBtn.setAttribute('aria-expanded', 'true');
            if (filterIcon) filterIcon.classList.remove('bi-chevron-down');
            if (filterIcon) filterIcon.classList.add('bi-chevron-up');
        } else {
            // Collapse
            filterContent.classList.add('collapsed');
            if (filterToggleBtn) filterToggleBtn.setAttribute('aria-expanded', 'false');
            if (filterIcon) filterIcon.classList.remove('bi-chevron-up');
            if (filterIcon) filterIcon.classList.add('bi-chevron-down');
        }
    }
}

function filterItems() {
    const searchInput = document.getElementById('searchInput');
    const statusFilter = document.getElementById('statusFilter');
    const stockFilter = document.getElementById('stockFilter');
    const principalFilter = document.getElementById('principalFilter');
    
    if (!searchInput || !statusFilter || !stockFilter || !principalFilter) {
        console.error('Filter elements not found');
        return;
    }
    
    const searchTerm = searchInput.value.toLowerCase();
    const status = statusFilter.value;
    const stockLevel = stockFilter.value;
    const principal = principalFilter.value;
    
    activeFilters = { search: searchTerm, status: status, stockLevel: stockLevel, principal: principal };
    updateFilterCountBadge();
    
    if (currentView === 'category') {
        filterCategoryView();
    } else {
        filterSupplierView();
    }
}

function filterCategoryView() {
    const activeTab = document.querySelector('#categoryView .tab-content.active');
    if (!activeTab) return;
    
    const rows = activeTab.querySelectorAll('.inventory-row');
    let visibleCount = 0;
    
    rows.forEach(row => {
        const matchesSearch = activeFilters.search === '' || 
            (row.dataset.code && row.dataset.code.toLowerCase().includes(activeFilters.search)) || 
            (row.dataset.barcode && row.dataset.barcode.toLowerCase().includes(activeFilters.search)) ||
            (row.dataset.name && row.dataset.name.toLowerCase().includes(activeFilters.search)) ||
            (row.dataset.category && row.dataset.category.toLowerCase().includes(activeFilters.search)) ||
            (row.dataset.principal && row.dataset.principal.toLowerCase().includes(activeFilters.search));
        
        const matchesStatus = activeFilters.status === '' || 
            (row.dataset.status && row.dataset.status === activeFilters.status);
        const matchesPrincipal = activeFilters.principal === '' ||
            ((row.dataset.principal || 'No Principal') === activeFilters.principal);
        
        let matchesStock = true;
        const stock = parseFloat(row.dataset.stock) || 0;
        const reorder = parseInt(row.dataset.reorder) || 0;
        
        if (activeFilters.stockLevel === 'low') {
            matchesStock = stock <= reorder && stock > 0;
        } else if (activeFilters.stockLevel === 'normal') {
            matchesStock = stock > reorder && stock <= reorder * 2;
        } else if (activeFilters.stockLevel === 'adequate') {
            matchesStock = stock > reorder * 2;
        } else if (activeFilters.stockLevel === 'out') {
            matchesStock = stock <= 0;
        }
        
        if (matchesSearch && matchesStatus && matchesPrincipal && matchesStock) {
            row.style.display = '';
            visibleCount++;
        } else {
            row.style.display = 'none';
        }
    });
    
    const noItemsMsg = document.getElementById(`no-items-${activeTab.id}`);
    if (noItemsMsg) {
        noItemsMsg.style.display = visibleCount === 0 ? 'block' : 'none';
    }
}

function filterSupplierView() {
    const activeTab = document.querySelector('#supplierView .tab-content.active');
    if (!activeTab) return;
    
    const rows = activeTab.querySelectorAll('.inventory-row');
    let visibleCount = 0;
    
    rows.forEach(row => {
        const matchesSearch = activeFilters.search === '' || 
            (row.dataset.code && row.dataset.code.toLowerCase().includes(activeFilters.search)) || 
            (row.dataset.barcode && row.dataset.barcode.toLowerCase().includes(activeFilters.search)) ||
            (row.dataset.name && row.dataset.name.toLowerCase().includes(activeFilters.search)) ||
            (row.dataset.category && row.dataset.category.toLowerCase().includes(activeFilters.search)) ||
            (row.dataset.principal && row.dataset.principal.toLowerCase().includes(activeFilters.search));
        
        const matchesStatus = activeFilters.status === '' || 
            (row.dataset.status && row.dataset.status === activeFilters.status);
        const matchesPrincipal = activeFilters.principal === '' ||
            ((row.dataset.principal || 'No Principal') === activeFilters.principal);
        
        let matchesStock = true;
        const stock = parseFloat(row.dataset.stock) || 0;
        const reorder = parseInt(row.dataset.reorder) || 0;
        
        if (activeFilters.stockLevel === 'low') {
            matchesStock = stock <= reorder && stock > 0;
        } else if (activeFilters.stockLevel === 'normal') {
            matchesStock = stock > reorder && stock <= reorder * 2;
        } else if (activeFilters.stockLevel === 'adequate') {
            matchesStock = stock > reorder * 2;
        } else if (activeFilters.stockLevel === 'out') {
            matchesStock = stock <= 0;
        }
        
        if (matchesSearch && matchesStatus && matchesPrincipal && matchesStock) {
            row.style.display = '';
            visibleCount++;
        } else {
            row.style.display = 'none';
        }
    });
    
    const noItemsMsg = document.getElementById(`no-items-${activeTab.id}`);
    if (noItemsMsg) {
        noItemsMsg.style.display = visibleCount === 0 ? 'block' : 'none';
    }
}

function clearAllFilters() {
    const searchInput = document.getElementById('searchInput');
    const statusFilter = document.getElementById('statusFilter');
    const stockFilter = document.getElementById('stockFilter');
    const principalFilter = document.getElementById('principalFilter');
    
    if (searchInput) searchInput.value = '';
    if (statusFilter) statusFilter.value = '';
    if (stockFilter) stockFilter.value = '';
    if (principalFilter) principalFilter.value = '';
    
    activeFilters = { search: '', status: '', stockLevel: '', principal: '' };
    updateFilterCountBadge();
    filterItems();
}

// ========== BARCODE SEARCH SCANNER ==========
let barcodeSearchScanner = null;
let barcodeSearchScannerRunning = false;
let barcodeSearchStarting = false;

function focusAllItemsTabBeforeBarcodeSearch() {
    if (currentView === 'category') {
        const allTabBtn = document.querySelector('#categoryView .category-tab[data-tab="cat-tab-all"]');
        if (allTabBtn && !allTabBtn.classList.contains('active')) {
            allTabBtn.click();
        }
    }
}

function applyScannedBarcodeToSearch(barcodeValue) {
    const cleanBarcode = String(barcodeValue || '').trim();
    if (!cleanBarcode) return;

    const searchInput = document.getElementById('searchInput');
    if (searchInput) {
        searchInput.value = cleanBarcode;
        searchInput.dispatchEvent(new Event('input', { bubbles: true }));
        searchInput.dispatchEvent(new Event('change', { bubbles: true }));
    }

    focusAllItemsTabBeforeBarcodeSearch();
    filterItems();
    stopBarcodeSearchScanner();

    const modalEl = document.getElementById('barcodeScannerModal');
    if (modalEl && typeof bootstrap !== 'undefined') {
        const modalInstance = bootstrap.Modal.getInstance(modalEl);
        if (modalInstance) modalInstance.hide();
    }

    const hasVisibleRow = Array.from(document.querySelectorAll('.inventory-row')).some(row => row.style.display !== 'none');
    if (!hasVisibleRow && typeof Swal !== 'undefined') {
        Swal.fire({
            icon: 'info',
            title: 'No item found',
            text: 'Walang item na tumugma sa barcode: ' + cleanBarcode,
            confirmButtonColor: '#198754'
        });
    }
}

function startBarcodeSearchScanner() {
    cleanupModalBackdrops();

    const modalEl = document.getElementById('barcodeScannerModal');
    if (!modalEl) return;

    const manualInput = document.getElementById('manualBarcodeSearchInput');
    if (manualInput) manualInput.value = '';

    modalEl.removeEventListener('shown.bs.modal', initBarcodeSearchScanner);
    modalEl.removeEventListener('hidden.bs.modal', stopBarcodeSearchScanner);

    modalEl.addEventListener('shown.bs.modal', initBarcodeSearchScanner, { once: true });
    modalEl.addEventListener('hidden.bs.modal', stopBarcodeSearchScanner, { once: true });

    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    modal.show();
}

function getBarcodeScannerFormats() {
    if (typeof Html5QrcodeSupportedFormats === 'undefined') return undefined;

    const names = [
        'CODE_128',
        'CODE_39',
        'CODE_93',
        'CODABAR',
        'EAN_13',
        'EAN_8',
        'UPC_A',
        'UPC_E',
        'ITF',
        'QR_CODE'
    ];

    return names
        .map(name => Html5QrcodeSupportedFormats[name])
        .filter(format => format !== undefined && format !== null);
}

function showBarcodeScannerMessage(message, type = 'muted') {
    const readerEl = document.getElementById('barcodeSearchReader');
    if (!readerEl) return;

    const alertClass = type === 'danger' ? 'text-danger' : (type === 'success' ? 'text-success' : 'text-muted');
    readerEl.innerHTML = `
        <div class="p-4 text-center ${alertClass}">
            <i class="bi bi-camera-video" style="font-size: 2rem;"></i>
            <div class="mt-2">${message}</div>
        </div>
    `;
}

function initBarcodeSearchScanner() {
    const readerEl = document.getElementById('barcodeSearchReader');
    if (!readerEl || barcodeSearchStarting || barcodeSearchScannerRunning) return;

    if (location.protocol !== 'https:' && location.hostname !== 'localhost' && location.hostname !== '127.0.0.1') {
        showBarcodeScannerMessage('Camera scanning needs HTTPS. I-type muna ang barcode manually sa field sa baba.', 'danger');
        return;
    }

    if (typeof Html5Qrcode === 'undefined') {
        showBarcodeScannerMessage('Barcode scanner library did not load. I-type muna ang barcode manually sa field sa baba.', 'danger');
        return;
    }

    barcodeSearchStarting = true;
    readerEl.innerHTML = '';

    try {
        barcodeSearchScanner = new Html5Qrcode('barcodeSearchReader', { verbose: false });
    } catch (error) {
        barcodeSearchStarting = false;
        console.error('Scanner init error:', error);
        showBarcodeScannerMessage('Hindi ma-start ang scanner. I-type muna ang barcode manually sa field sa baba.', 'danger');
        return;
    }

    const formats = getBarcodeScannerFormats();
    const config = {
        fps: 15,
        qrbox: function(viewfinderWidth, viewfinderHeight) {
            const width = Math.floor(viewfinderWidth * 0.92);
            const height = Math.floor(Math.min(viewfinderHeight * 0.45, 220));
            return { width: width, height: height };
        },
        aspectRatio: 1.7777778,
        rememberLastUsedCamera: true
    };

    if (formats && formats.length) {
        config.formatsToSupport = formats;
    }

    Html5Qrcode.getCameras()
        .then(function(cameras) {
            if (!cameras || cameras.length === 0) {
                throw new Error('No camera found');
            }

            const backCamera = cameras.find(camera => /back|rear|environment/i.test(camera.label || ''));
            const cameraIdOrConfig = backCamera ? backCamera.id : cameras[cameras.length - 1].id;

            return barcodeSearchScanner.start(
                cameraIdOrConfig,
                config,
                function(decodedText) {
                    if (decodedText) {
                        applyScannedBarcodeToSearch(decodedText);
                    }
                },
                function() {
                    // Ignore scan misses while camera is running.
                }
            );
        })
        .then(function() {
            barcodeSearchScannerRunning = true;
            barcodeSearchStarting = false;
        })
        .catch(function(error) {
            barcodeSearchStarting = false;
            barcodeSearchScannerRunning = false;
            console.error('Barcode scanner error:', error);

            // Fallback kapag ayaw ng exact camera id sa ibang browser.
            const fallbackConfig = Object.assign({}, config, {
                videoConstraints: { facingMode: { ideal: 'environment' } }
            });

            try {
                barcodeSearchScanner = new Html5Qrcode('barcodeSearchReader', { verbose: false });
                barcodeSearchScanner.start(
                    { facingMode: 'environment' },
                    fallbackConfig,
                    function(decodedText) {
                        if (decodedText) {
                            applyScannedBarcodeToSearch(decodedText);
                        }
                    },
                    function() {}
                ).then(function() {
                    barcodeSearchScannerRunning = true;
                }).catch(function(fallbackError) {
                    console.error('Barcode scanner fallback error:', fallbackError);
                    showBarcodeScannerMessage('Cannot open camera. Allow camera permission, then try again. Pwede rin i-type manually ang barcode sa baba.', 'danger');
                });
            } catch (fallbackInitError) {
                console.error('Barcode scanner fallback init error:', fallbackInitError);
                showBarcodeScannerMessage('Cannot open camera. Allow camera permission, then try again. Pwede rin i-type manually ang barcode sa baba.', 'danger');
            }
        });
}

function stopBarcodeSearchScanner() {
    barcodeSearchStarting = false;

    if (barcodeSearchScanner) {
        if (barcodeSearchScannerRunning) {
            barcodeSearchScanner.stop().then(function() {
                try { barcodeSearchScanner.clear(); } catch (e) {}
                barcodeSearchScannerRunning = false;
                barcodeSearchScanner = null;
            }).catch(function(error) {
                console.error('Stop scanner error:', error);
                barcodeSearchScannerRunning = false;
                barcodeSearchScanner = null;
            });
        } else {
            try { barcodeSearchScanner.clear(); } catch (e) {}
            barcodeSearchScanner = null;
        }
    }
}

function searchBarcodeManually() {
    const manualInput = document.getElementById('manualBarcodeSearchInput');
    if (manualInput) {
        applyScannedBarcodeToSearch(manualInput.value);
    }
}

document.addEventListener('keydown', function(e) {
    const modalEl = document.getElementById('barcodeScannerModal');
    if (!modalEl || !modalEl.classList.contains('show')) return;

    if (e.key === 'Enter') {
        const manualInput = document.getElementById('manualBarcodeSearchInput');
        if (document.activeElement === manualInput) {
            e.preventDefault();
            searchBarcodeManually();
        }
    }
});

// ========== ITEM STATUS FUNCTIONS ==========
function toggleItemStatus(itemId, checkbox) {
    const newStatus = checkbox.checked ? 'active' : 'inactive';
    showLoading();
    const formData = new FormData();
    formData.append('action', 'toggle_status');
    formData.append('item_id', itemId);
    formData.append('status', newStatus);
    
    fetch('current_inventory.php', { method: 'POST', body: formData })
        .then(response => response.json())
        .then(data => {
            Swal.close();
            if (data.success) {
                Swal.fire({ 
                    icon: 'success', 
                    title: 'Success!', 
                    text: data.message, 
                    timer: 1500, 
                    showConfirmButton: false
                });
                const row = document.querySelector(`.inventory-row[data-id="${itemId}"]`);
                if (row) row.dataset.status = newStatus;
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: data.message });
                checkbox.checked = !checkbox.checked;
            }
        })
        .catch(error => { 
            Swal.close(); 
            Swal.fire({ icon: 'error', title: 'Error', text: 'An error occurred' });
            checkbox.checked = !checkbox.checked; 
        });
}

// ========== SUPPLIER FUNCTIONS ==========
function showSupplierSelector() {
    cleanupModalBackdrops();
    showLoading();
    const formData = new FormData();
    formData.append('action', 'get_suppliers');
    
    fetch('current_inventory.php', { method: 'POST', body: formData })
        .then(response => response.json())
        .then(data => {
            Swal.close();
            if (data.success) {
                suppliersList = data.suppliers || [];
                const select = $('#supplierSelect');
                select.empty().append('<option value="">-- Choose Supplier --</option>');
                suppliersList.forEach(s => select.append(new Option(s.supplier_name, s.supplier_id)));

                document.getElementById('supplierDetailsContainer').style.display = 'none';
                document.getElementById('noSupplierSelected').style.display = 'block';
                new bootstrap.Modal(document.getElementById('supplierSelectorModal')).show();
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: data.message });
            }
        })
        .catch(error => { 
            Swal.close(); 
            Swal.fire({ icon: 'error', title: 'Error', text: 'An error occurred' });
        });
}

$('#supplierSelect').on('change', function() {
    const supplierId = $(this).val();
    if (supplierId) loadSupplierDetails(supplierId);
    else { document.getElementById('supplierDetailsContainer').style.display = 'none'; document.getElementById('noSupplierSelected').style.display = 'block'; }
});

function loadSupplierDetails(supplierId) {
    showLoading();
    const formData = new FormData();
    formData.append('action', 'get_supplier_details');
    formData.append('supplier_id', supplierId);
    
    fetch('current_inventory.php', { method: 'POST', body: formData })
        .then(response => response.json())
        .then(data => {
            Swal.close();
            if (data.success) {
                const supplier = data.supplier, purchaseOrders = data.purchase_orders || [];
                let totalOrders = purchaseOrders.length, totalItems = 0, totalQuantity = 0, totalSpent = 0;
                purchaseOrders.forEach(po => { totalItems += po.total_items || 0; totalQuantity += parseInt(po.total_quantity) || 0; totalSpent += parseFloat(po.total_amount) || 0; });
                
                document.getElementById('supplierSummary').innerHTML = `<h6 class="fw-bold mb-3">${escapeHtml(supplier.supplier_name)}</h6><div class="supplier-stat"><span class="supplier-stat-label">Supplier Code:</span><span class="supplier-stat-value">${escapeHtml(supplier.supplier_code || 'N/A')}</span></div><div class="supplier-stat"><span class="supplier-stat-label">Contact Person:</span><span class="supplier-stat-value">${escapeHtml(supplier.contact_person || 'N/A')}</span></div><div class="supplier-stat"><span class="supplier-stat-label">Email:</span><span class="supplier-stat-value">${escapeHtml(supplier.email || 'N/A')}</span></div><div class="supplier-stat"><span class="supplier-stat-label">Phone:</span><span class="supplier-stat-value">${escapeHtml(supplier.phone_number || 'N/A')}</span></div><div class="supplier-stat"><span class="supplier-stat-label">Total Purchase Orders:</span><span class="supplier-stat-value">${totalOrders}</span></div><div class="supplier-stat"><span class="supplier-stat-label">Total Items Ordered:</span><span class="supplier-stat-value">${totalItems}</span></div><div class="supplier-stat"><span class="supplier-stat-label">Total Quantity:</span><span class="supplier-stat-value">${totalQuantity}</span></div><div class="supplier-stat"><span class="supplier-stat-label">Total Spent:</span><span class="supplier-stat-value">₱${totalSpent.toFixed(2)}</span></div>`;
                
                let poHtml = '';
                if (purchaseOrders.length > 0) {
                    purchaseOrders.forEach(po => {
                        const orderDate = po.order_date ? new Date(po.order_date).toLocaleDateString() : 'N/A';
                        poHtml += `<div class="card mb-3"><div class="card-header bg-light"><div class="d-flex justify-content-between align-items-center"><strong>${escapeHtml(po.po_number)}</strong><span class="badge bg-${po.po_status === 'received' ? 'success' : (po.po_status === 'cancelled' ? 'danger' : 'warning')}">${po.po_status}</span></div></div><div class="card-body"><div class="row mb-2"><div class="col-md-4"><small class="text-muted">Order Date:</small> ${orderDate}</div></div><div class="row"><div class="col-md-12"><small class="text-muted">Items:</small><ul class="list-unstyled mt-1">`;
                        if (po.items && po.items.length) po.items.forEach(item => { poHtml += `<li class="mb-1"><span class="badge bg-secondary me-2">${escapeHtml(item.item_code)}</span> ${escapeHtml(item.item_name)} - ${item.quantity_ordered} x ₱${parseFloat(item.unit_price).toFixed(2)} = ₱${(item.quantity_ordered * item.unit_price).toFixed(2)}</li>`; });
                        else poHtml += '<li class="text-muted">No items found</li>';
                        poHtml += `</ul></div></div><div class="mt-2 text-end"><strong>Total: ₱${parseFloat(po.total_amount).toFixed(2)}</strong></div></div></div>`;
                    });
                } else poHtml = '<div class="text-center py-4 text-muted">No purchase orders found for this supplier</div>';
                document.getElementById('supplierPurchaseOrders').innerHTML = poHtml;
                document.getElementById('supplierDetailsContainer').style.display = 'block';
                document.getElementById('noSupplierSelected').style.display = 'none';
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: data.message });
            }
        })
        .catch(error => { 
            Swal.close(); 
            Swal.fire({ icon: 'error', title: 'Error', text: 'An error occurred' });
        });
}

// ========== LOW STOCK FUNCTIONS ==========
function showLowStockModal() {
    cleanupModalBackdrops();
    showLoading();
    const formData = new FormData();
    formData.append('action', 'get_low_stock_items');
    
    fetch('current_inventory.php', { method: 'POST', body: formData })
        .then(response => response.json())
        .then(data => {
            Swal.close();
            if (data.success) {
                const items = data.items || [];
                if (items.length) {
                    let itemsHtml = '';
                    items.forEach(item => {
                        const stockStatus = parseInt(item.stock) <= 0 ? 'Out of Stock' : 'Low Stock';
                        const statusClass = parseInt(item.stock) <= 0 ? 'stock-badge-danger' : 'stock-badge-warning';
                        itemsHtml += `<div class="low-stock-item"><div class="low-stock-item-info"><div class="low-stock-item-name">${escapeHtml(item.item_name)}</div><div class="low-stock-item-code">${escapeHtml(item.item_code)} | ${escapeHtml(item.category || 'General')}</div></div><div class="low-stock-item-stats"><div class="low-stock-item-current">${item.stock} ${item.unit_type}</div><div class="low-stock-item-reorder">Reorder: ${item.reorder_level}</div></div><div><span class="stock-status-badge ${statusClass}">${stockStatus}</span></div></div>`;
                    });
                    document.getElementById('lowStockItemsContainer').innerHTML = itemsHtml;
                    document.getElementById('lowStockItemsContainer').style.display = 'block';
                    document.getElementById('noLowStockItems').style.display = 'none';
                } else { 
                    document.getElementById('lowStockItemsContainer').style.display = 'none'; 
                    document.getElementById('noLowStockItems').style.display = 'block'; 
                }
                new bootstrap.Modal(document.getElementById('lowStockModal')).show();
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: data.message });
            }
        })
        .catch(error => { 
            Swal.close(); 
            Swal.fire({ icon: 'error', title: 'Error', text: 'An error occurred' });
        });
}

function filterLowStock() { 
    const stockFilter = document.getElementById('stockFilter');
    if (stockFilter) stockFilter.value = 'low';
    filterItems(); 
    const modal = bootstrap.Modal.getInstance(document.getElementById('lowStockModal'));
    if (modal) modal.hide();
}

// ========== OFFTAKE FUNCTIONS ==========
function showOfftakeModal() { 
    cleanupModalBackdrops();
    new bootstrap.Modal(document.getElementById('offtakeModal')).show(); 
    loadOfftakeData(); 
}

function loadOfftakeData() {
    const startDate = document.getElementById('offtakeStartDate').value, endDate = document.getElementById('offtakeEndDate').value;
    if (!startDate || !endDate) { 
        Swal.fire({ icon: 'warning', title: 'Warning', text: 'Please select both start and end dates' });
        return; 
    }
    if (new Date(startDate) > new Date(endDate)) { 
        Swal.fire({ icon: 'warning', title: 'Warning', text: 'Start date cannot be after end date' });
        return; 
    }
    showLoading();
    const formData = new FormData();
    formData.append('action', 'get_offtake_data');
    formData.append('start_date', startDate);
    formData.append('end_date', endDate);
    
    fetch('current_inventory.php', { method: 'POST', body: formData })
        .then(response => response.json())
        .then(data => {
            Swal.close();
            if (data.success) updateOfftakeUI(data);
            else Swal.fire({ icon: 'error', title: 'Error', text: data.message });
        })
        .catch(error => { 
            Swal.close(); 
            Swal.fire({ icon: 'error', title: 'Error', text: 'An error occurred' });
        });
}

function updateOfftakeUI(data) {
    const summary = data.summary, dailyData = data.daily_data, dateRange = data.date_range;
    document.getElementById('summaryAvgDaily').textContent = summary.avg_daily.toFixed(1);
    document.getElementById('summaryTotalQty').textContent = summary.total_quantity.toLocaleString();
    document.getElementById('summaryActiveDays').textContent = summary.active_days;
    document.getElementById('summaryPerItem').textContent = summary.avg_per_item.toFixed(1);
    
    const startDate = new Date(dateRange.start).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
    const endDate = new Date(dateRange.end).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
    document.getElementById('dateRangeDisplay').innerHTML = `<strong>${startDate} - ${endDate}</strong>`;
    
    let tableHtml = '', totalOrders = 0, totalQty = 0, totalAmount = 0;
    if (dailyData.length) {
        dailyData.forEach(day => {
            const date = new Date(day.sale_date).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
            totalOrders += parseInt(day.order_count); totalQty += parseInt(day.total_quantity); totalAmount += parseFloat(day.total_amount);
            tableHtml += `<tr><td class="text-start">${date}</td><td class="text-center">${day.order_count}</td><td class="text-center">${day.total_quantity}</td><td class="text-end">₱${parseFloat(day.total_amount).toLocaleString(undefined, {minimumFractionDigits: 2})}</td></tr>`;
        });
        tableHtml += `<tr class="total-row"><td class="text-start"><strong>TOTAL</strong></td><td class="text-center"><strong>${totalOrders}</strong></td><td class="text-center"><strong>${totalQty.toLocaleString()}</strong></td><td class="text-end"><strong>₱${totalAmount.toLocaleString(undefined, {minimumFractionDigits: 2})}</strong></td></tr>`;
    } else tableHtml = `<tr><td colspan="4" class="text-center py-4 text-muted"><i class="bi bi-info-circle fs-4 d-block mb-2"></i>No data found for the selected date range</div></td></tr>`;
    document.getElementById('offtakeTableBody').innerHTML = tableHtml;
}

function printOfftakeReport() {
    const printBtn = document.querySelector('.btn-outline-primary[onclick="printOfftakeReport()"]');
    if (printBtn) { printBtn.innerHTML = '<i class="bi bi-printer"></i> Preparing...'; printBtn.disabled = true; }
    const startDate = document.getElementById('offtakeStartDate').value, endDate = document.getElementById('offtakeEndDate').value;
    if (!startDate || !endDate) { 
        Swal.fire({ icon: 'warning', title: 'Warning', text: 'Please select both start and end dates' });
        if (printBtn) { printBtn.innerHTML = '<i class="bi bi-printer"></i> Print'; printBtn.disabled = false; } 
        return; 
    }
    showLoading();
    const formData = new FormData();
    formData.append('action', 'print_offtake');
    formData.append('filter_data', JSON.stringify({ start_date: startDate, end_date: endDate }));
    
    fetch('current_inventory.php', { method: 'POST', body: formData })
        .then(response => response.json())
        .then(data => {
            Swal.close();
            if (data.success && data.items.length) {
                const generatePrintHTML = (items, summary, dateRange, branchName) => {
                    let tableRows = ''; let tOrders = 0, tQty = 0, tAmount = 0;
                    items.forEach(item => {
                        tOrders += parseInt(item.order_count); tQty += parseInt(item.total_quantity); tAmount += parseFloat(item.total_amount);
                        tableRows += `<tr><td style="padding:8px;border:1px solid #000">${new Date(item.sale_date).toLocaleDateString()}</td><td style="padding:8px;border:1px solid #000;text-align:center">${item.order_count}</td><td style="padding:8px;border:1px solid #000;text-align:center">${item.total_quantity}</td><td style="padding:8px;border:1px solid #000;text-align:right">₱${parseFloat(item.total_amount).toFixed(2)}</div></td></tr>`;
                    });
                    const currentDate = new Date().toLocaleString();
                    return `<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Offtake Report</title><style>body{font-family:Arial;margin:0;padding:20px;font-size:12px}table{width:100%;border-collapse:collapse}th,td{border:1px solid #000;padding:8px;text-align:left}th{background:#f0f0f0}.summary{display:flex;margin-bottom:20px}.summary-item{flex:1;border:1px solid #000;padding:10px;text-align:center}.print-header{text-align:center;margin-bottom:20px}.print-header img{width:50px}</style></head><body><div class="print-header"><img src="${logoBase64}" alt="Logo"><h2>Average Daily Offtake Report</h2><p>Branch: ${branchName || 'All Branches'}</p></div><div class="summary"><div class="summary-item"><strong>Avg Daily</strong><br>${summary.avg_daily.toFixed(1)}</div><div class="summary-item"><strong>Total Quantity</strong><br>${summary.total_quantity.toLocaleString()}</div><div class="summary-item"><strong>Active Days</strong><br>${summary.active_days}</div><div class="summary-item"><strong>Per Item Avg</strong><br>${summary.avg_per_item.toFixed(1)}</div></div><table><thead><tr><th>Date</th><th>Orders</th><th>Quantity</th><th>Amount</th></tr></thead><tbody>${tableRows}<tr style="font-weight:bold"><td>TOTAL</div><td style="text-align:center">${tOrders}</div><td style="text-align:center">${tQty}</div><td style="text-align:right">₱${tAmount.toFixed(2)}</div></tr></tbody></table><p style="margin-top:20px;font-size:10px">Generated: ${currentDate}</p></body></html>`;
                };
                const htmlContent = generatePrintHTML(data.items, data.summary, data.date_range, data.branch_name);
                const iframe = document.getElementById('printFrame');
                iframe.contentWindow.document.open();
                iframe.contentWindow.document.write(htmlContent);
                iframe.contentWindow.document.close();
                setTimeout(() => iframe.contentWindow.print(), 250);
            } else {
                Swal.fire({ icon: 'warning', title: 'No Data', text: 'No offtake data matches the selected date range' });
            }
            if (printBtn) { printBtn.innerHTML = '<i class="bi bi-printer"></i> Print'; printBtn.disabled = false; }
        })
        .catch(error => { 
            Swal.close(); 
            Swal.fire({ icon: 'error', title: 'Error', text: 'An error occurred' });
            if (printBtn) { printBtn.innerHTML = '<i class="bi bi-printer"></i> Print'; printBtn.disabled = false; } 
        });
}

function exportOfftakeToExcel() {
    const rows = document.querySelectorAll('#offtakeTableBody tr');
    if (rows.length === 0 || (rows.length === 1 && rows[0].querySelector('td[colspan]'))) { 
        Swal.fire({ icon: 'warning', title: 'Warning', text: 'No data to export' });
        return; 
    }
    const excelData = [['Date', 'Orders', 'Quantity', 'Amount (₱)']];
    rows.forEach(row => {
        const cells = row.querySelectorAll('td');
        if (cells.length === 4 && !cells[0].hasAttribute('colspan')) excelData.push([cells[0].innerText, cells[1].innerText, cells[2].innerText, cells[3].innerText.replace('₱', '').replace(/,/g, '')]);
    });
    excelData.push([], ['SUMMARY'], ['Avg Daily Offtake', document.getElementById('summaryAvgDaily').textContent], ['Total Quantity', document.getElementById('summaryTotalQty').textContent], ['Active Days', document.getElementById('summaryActiveDays').textContent], ['Per Item Avg', document.getElementById('summaryPerItem').textContent]);
    const wb = XLSX.utils.book_new(), ws = XLSX.utils.aoa_to_sheet(excelData);
    ws['!cols'] = [{ wch: 15 }, { wch: 10 }, { wch: 12 }, { wch: 15 }];
    XLSX.utils.book_append_sheet(wb, ws, 'Offtake Report');
    XLSX.writeFile(wb, `Offtake_Report_${document.getElementById('offtakeStartDate').value}_to_${document.getElementById('offtakeEndDate').value}.xlsx`);
    Swal.fire({ icon: 'success', title: 'Export Complete', timer: 1500, showConfirmButton: false });
}

const itemImportExportHeaders = [
    'item_code', 'item_name', 'description', 'category', 'principal', 'stock', 'reorder_level', 'status',
    'unit_type', 'barcode', 'qty_smallest_pack', 'default_uom', 'unit_status',
    'price_level', 'effective_date', 'unit_price', 'unit_quantity',
    'current_inventory', 'as_of_date', 'unit_cost'
];

function normalizeExportCellValue(header, value) {
    if (value === null || value === undefined) return '';
    const raw = String(value).trim();
    if (raw === '') return '';
    if (['effective_date', 'as_of_date'].includes(header)) {
        const dateOnly = raw.match(/^(\d{4}-\d{2}-\d{2})/);
        return dateOnly ? dateOnly[1] : raw;
    }
    if (['item_code', 'barcode'].includes(header)) {
        return raw;
    }
    return raw;
}

function buildItemsExportRows(rows) {
    const excelRows = [itemImportExportHeaders];
    (rows || []).forEach(row => {
        excelRows.push(itemImportExportHeaders.map(header => normalizeExportCellValue(header, row[header])));
    });
    return excelRows;
}

function applyItemsExportCellFormats(ws) {
    if (!ws['!ref']) return;
    const range = XLSX.utils.decode_range(ws['!ref']);
    const textHeaders = new Set(itemImportExportHeaders);
    for (let R = range.s.r; R <= range.e.r; ++R) {
        for (let C = range.s.c; C <= range.e.c; ++C) {
            const address = XLSX.utils.encode_cell({ r: R, c: C });
            if (!ws[address]) continue;
            const header = itemImportExportHeaders[C] || '';
            if (R === 0 || textHeaders.has(header)) {
                ws[address].t = 's';
                ws[address].v = ws[address].v === null || ws[address].v === undefined ? '' : String(ws[address].v);
                ws[address].z = '@';
            }
        }
    }
}

function normalizeImportedExcelDateValue(value) {
    if (value === null || value === undefined) return '';
    if (value instanceof Date && !isNaN(value.getTime())) {
        return value.toISOString().slice(0, 10);
    }
    let raw = String(value).trim();
    if (raw === '') return '';
    if (/^\d+(\.\d+)?$/.test(raw)) {
        const num = Number(raw);
        if (num > 20000 && num < 80000) {
            const utcDays = Math.floor(num - 25569);
            const date = new Date(utcDays * 86400 * 1000);
            return date.toISOString().slice(0, 10);
        }
    }
    const ymd = raw.match(/^(\d{4})-(\d{2})-(\d{2})/);
    if (ymd) return `${ymd[1]}-${ymd[2]}-${ymd[3]}`;
    const dmy = raw.match(/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})$/);
    if (dmy) {
        const day = dmy[1].padStart(2, '0');
        const month = dmy[2].padStart(2, '0');
        const year = dmy[3];
        return `${year}-${month}-${day}`;
    }
    return raw;
}

function downloadItemsImportTemplate() {
    showLoading();

    const formData = new FormData();
    formData.append('action', 'export_items_data');

    fetch('current_inventory.php', { method: 'POST', body: formData })
        .then(response => response.json())
        .then(data => {
            Swal.close();

            if (!data.success) {
                Swal.fire({ icon: 'error', title: 'Export Failed', text: data.message || 'Unable to export items.' });
                return;
            }

            const wb = XLSX.utils.book_new();
            const rows = buildItemsExportRows(data.rows || []);
            const ws = XLSX.utils.aoa_to_sheet(rows);
            applyItemsExportCellFormats(ws);

            ws['!cols'] = [
                { wch: 14 }, { wch: 28 }, { wch: 34 }, { wch: 16 }, { wch: 18 }, { wch: 10 }, { wch: 14 }, { wch: 14 },
                { wch: 16 }, { wch: 18 }, { wch: 18 }, { wch: 12 }, { wch: 14 },
                { wch: 16 }, { wch: 14 }, { wch: 12 }, { wch: 14 },
                { wch: 18 }, { wch: 14 }, { wch: 12 }
            ];

            XLSX.utils.book_append_sheet(wb, ws, 'Items Import');
            XLSX.writeFile(wb, `Current_Items_Export_${new Date().toISOString().slice(0, 10)}.xlsx`);

            Swal.fire({
                icon: 'success',
                title: 'Export Complete',
                text: `${data.rows ? data.rows.length : 0} item/unit/price row(s) exported. Edit only the rows or columns you want to update, then import the same file.`,
                timer: 2200,
                showConfirmButton: false
            });
        })
        .catch(error => {
            Swal.close();
            Swal.fire({ icon: 'error', title: 'Export Error', text: 'An error occurred: ' + error.message });
        });
}


function normalizeImportHeaderKey(value) {
    return String(value || '')
        .trim()
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '_')
        .replace(/^_+|_+$/g, '');
}

function handleImportItemsFile(event) {
    const file = event.target.files && event.target.files[0];
    if (!file) return;

    showLoading();
    const reader = new FileReader();

    reader.onload = function(e) {
        try {
            const data = e.target.result;
            const workbook = XLSX.read(data, { type: 'array', cellDates: true });
            const sheetName = workbook.SheetNames[0];
            const sheet = workbook.Sheets[sheetName];
            const rows = XLSX.utils.sheet_to_json(sheet, { header: 1, defval: '', raw: false, dateNF: 'yyyy-mm-dd' });

            if (!rows || rows.length < 2) {
                Swal.close();
                Swal.fire({ icon: 'warning', title: 'Invalid File', text: 'The selected file has no import rows.' });
                event.target.value = '';
                return;
            }

            const headers = rows[0].map(normalizeImportHeaderKey);
            const importedRows = [];

            for (let i = 1; i < rows.length; i++) {
                const row = rows[i];
                if (!row || row.every(cell => String(cell || '').trim() === '')) continue;

                const rowObj = {};
                headers.forEach((header, index) => {
                    rowObj[header] = row[index] ?? '';
                });

                importedRows.push({
                    item_code: rowObj.item_code ?? '',
                    item_name: rowObj.item_name ?? '',
                    description: rowObj.description ?? '',
                    category: rowObj.category ?? '',
                    stock: rowObj.stock ?? '',
                    reorder_level: rowObj.reorder_level ?? '',
                    status: rowObj.status ?? '',
                    unit_type: rowObj.unit_type ?? '',
                    barcode: rowObj.barcode ?? '',
                    qty_smallest_pack: rowObj.qty_smallest_pack ?? '',
                    default_uom: rowObj.default_uom ?? '',
                    unit_status: rowObj.unit_status ?? '',
                    price_level: rowObj.price_level ?? '',
                    effective_date: normalizeImportedExcelDateValue(rowObj.effective_date ?? ''),
                    unit_price: rowObj.unit_price ?? '',
                    unit_quantity: rowObj.unit_quantity ?? '',
                    current_inventory: rowObj.current_inventory ?? '',
                    as_of_date: normalizeImportedExcelDateValue(rowObj.as_of_date ?? ''),
                    unit_cost: rowObj.unit_cost ?? ''
                });
            }

            if (importedRows.length === 0) {
                Swal.close();
                Swal.fire({ icon: 'warning', title: 'No Data', text: 'No valid rows found in the import file.' });
                event.target.value = '';
                return;
            }

            const formData = new FormData();
            formData.append('action', 'import_items');
            formData.append('rows', JSON.stringify(importedRows));

            fetch('current_inventory.php', { method: 'POST', body: formData })
                .then(response => response.json())
                .then(data => {
                    Swal.close();
                    if (data.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Import Complete',
                            text: data.message,
                            confirmButtonText: 'OK'
                        }).then(() => location.reload());
                    } else {
                        Swal.fire({ icon: 'error', title: 'Import Failed', text: data.message || 'Unable to import items.' });
                    }
                })
                .catch(error => {
                    Swal.close();
                    Swal.fire({ icon: 'error', title: 'Import Error', text: 'An error occurred: ' + error.message });
                })
                .finally(() => {
                    event.target.value = '';
                });
        } catch (error) {
            Swal.close();
            Swal.fire({ icon: 'error', title: 'Read Error', text: 'Unable to read the selected file: ' + error.message });
            event.target.value = '';
        }
    };

    reader.onerror = function() {
        Swal.close();
        Swal.fire({ icon: 'error', title: 'Read Error', text: 'Failed to read the selected file.' });
        event.target.value = '';
    };

    reader.readAsArrayBuffer(file);
}

let batchPriceLevelModalInstance = null;

function getSelectedBatchPriceLevel() {
    const select = document.getElementById('batchPriceLevelSelect');
    const newInput = document.getElementById('batchNewPriceLevel');
    if (!select) return 'Standard';
    if (select.value === '__new__') {
        const newValue = (newInput?.value || '').trim();
        return newValue || '';
    }
    return (select.value || 'Standard').trim();
}

function handleBatchPriceLevelSelectionChange() {
    const select = document.getElementById('batchPriceLevelSelect');
    const wrapper = document.getElementById('batchNewPriceLevelWrapper');
    const newInput = document.getElementById('batchNewPriceLevel');
    if (!select || !wrapper || !newInput) return;

    const isNew = select.value === '__new__';
    wrapper.style.display = isNew ? '' : 'none';
    if (!isNew) {
        newInput.value = '';
    }
    loadBatchPriceLevelTable();
}

function handleBatchNewPriceLevelInput() {
    loadBatchPriceLevelTable();
}

function showBatchPriceLevelModal() {
    const effectiveDateInput = document.getElementById('batchPriceEffectiveDate');
    if (effectiveDateInput && !effectiveDateInput.value) {
        effectiveDateInput.value = new Date().toISOString().split('T')[0];
    }

    const select = document.getElementById('batchPriceLevelSelect');
    if (select && !select.value) {
        select.value = 'Standard';
    }

    handleBatchPriceLevelSelectionChange();

    const searchInput = document.getElementById('batchPriceSearch');
    if (searchInput) {
        searchInput.value = '';
    }

    if (!batchPriceLevelModalInstance) {
        batchPriceLevelModalInstance = new bootstrap.Modal(document.getElementById('batchPriceLevelModal'));
    }
    batchPriceLevelModalInstance.show();
}

function loadBatchPriceLevelTable() {
    const effectiveDate = document.getElementById('batchPriceEffectiveDate')?.value || '';
    const priceLevel = getSelectedBatchPriceLevel();
    const tbody = document.getElementById('batchPriceLevelBody');
    if (!tbody) return;

    if (!effectiveDate) {
        tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-4">Please select an effective date first.</td></tr>';
        return;
    }

    const select = document.getElementById('batchPriceLevelSelect');
    if (select?.value === '__new__' && !priceLevel) {
        tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-4">Type the new price level name to load all products.</td></tr>';
        return;
    }

    tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-4">Loading items...</td></tr>';

    const formData = new FormData();
    formData.append('action', 'get_batch_price_level_items');
    formData.append('effective_date', effectiveDate);
    formData.append('price_level', priceLevel || 'Standard');

    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (!data.success || !Array.isArray(data.items) || data.items.length === 0) {
            tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-4">No items found for this price level.</td></tr>';
            return;
        }

        tbody.innerHTML = data.items.map(row => {
            const editablePrice = row.editable_price === null || row.editable_price === '' || typeof row.editable_price === 'undefined'
                ? ''
                : Number(row.editable_price).toFixed(2);
            return `
            <tr class="batch-price-row"
                data-item-name="${escapeHtml((row.item_name || '').toLowerCase())}"
                data-description="${escapeHtml((row.description || '').toLowerCase())}"
                data-uom="${escapeHtml((row.unit_type_name || '').toLowerCase())}">
                <td>
                    <input type="hidden" class="batch-item-id" value="${row.item_id}">
                    <input type="hidden" class="batch-unit-type-id" value="${row.unit_type_id}">
                    <input type="hidden" class="batch-unit-quantity" value="${parseInt(row.unit_quantity || 1, 10)}">
                    <strong>${escapeHtml(row.item_name || '')}</strong>
                </td>
                <td>${escapeHtml(row.description || '-')}</td>
                <td>${escapeHtml(row.unit_type_name || '-')}</td>
                <td><span class="fw-semibold">₱${Number(row.current_price || 0).toFixed(2)}</span></td>
                <td>
                    <input type="number"
                           class="form-control batch-new-price is-muted-price"
                           min="0"
                           step="0.01"
                           value="${editablePrice}"
                           placeholder="Click to edit"
                           readonly
                           data-edited="0"
                           data-original-value="${editablePrice}"
                           onclick="toggleBatchNewPriceInput(this, event)"
                           onkeydown="handleBatchNewPriceKeydown(this, event)"
                           oninput="markBatchNewPriceEdited(this)">
                    <small class="batch-price-hint">Click new price to edit</small>
                </td>
            </tr>`;
        }).join('');
        filterBatchPriceLevelRows();
    })
    .catch(() => {
        tbody.innerHTML = '<tr><td colspan="5" class="text-center text-danger py-4">Failed to load price level items.</td></tr>';
    });
}


function setBatchNewPriceMuted(input) {
    if (!input) return;
    input.readOnly = true;
    input.classList.add('is-muted-price');
    input.classList.remove('is-editing-price');
    input.placeholder = 'Click to edit';

    const hint = input.closest('td')?.querySelector('.batch-price-hint');
    if (hint) {
        hint.textContent = input.dataset.edited === '1' ? 'Edited - click to edit again' : 'Click new price to edit';
        hint.classList.remove('is-active');
    }
}

function activateBatchNewPriceInput(input) {
    if (!input) return;
    input.readOnly = false;
    input.classList.remove('is-muted-price');
    input.classList.add('is-editing-price');
    input.placeholder = 'Enter new price';

    const hint = input.closest('td')?.querySelector('.batch-price-hint');
    if (hint) {
        hint.textContent = 'Editing enabled - click again or press Enter/Esc to mute';
        hint.classList.add('is-active');
    }

    setTimeout(() => {
        try {
            input.focus();
            input.select();
        } catch (e) {}
    }, 0);
}

function toggleBatchNewPriceInput(input, event) {
    if (!input) return;
    if (event) event.preventDefault();

    if (input.readOnly || input.classList.contains('is-muted-price')) {
        activateBatchNewPriceInput(input);
    } else {
        setBatchNewPriceMuted(input);
    }
}

function handleBatchNewPriceKeydown(input, event) {
    if (!input || !event) return;
    if (event.key === 'Enter' || event.key === 'Escape') {
        event.preventDefault();
        setBatchNewPriceMuted(input);
        input.blur();
    }
}

function markBatchNewPriceEdited(input) {
    if (!input) return;
    input.dataset.edited = '1';
    input.classList.remove('is-muted-price');
    input.classList.add('is-editing-price');

    const hint = input.closest('td')?.querySelector('.batch-price-hint');
    if (hint) {
        hint.textContent = 'Editing enabled - click again or press Enter/Esc to mute';
        hint.classList.add('is-active');
    }
}

function filterBatchPriceLevelRows() {
    const keyword = (document.getElementById('batchPriceSearch')?.value || '').toLowerCase().trim();
    document.querySelectorAll('#batchPriceLevelBody .batch-price-row').forEach(row => {
        const haystack = [row.dataset.itemName || '', row.dataset.description || '', row.dataset.uom || ''].join(' ');
        row.style.display = haystack.includes(keyword) ? '' : 'none';
    });
}

function formatBatchPriceAmount(value) {
    if (value === null || value === undefined || value === '') return '-';
    const num = Number(value);
    if (Number.isNaN(num)) return '-';
    return '₱' + num.toFixed(2);
}

function showBatchPriceUpdateDetailsModal(updatedItems, message) {
    const detailsBody = document.getElementById('batchPriceUpdateDetailsBody');
    if (!detailsBody) return Promise.resolve();

    if (!Array.isArray(updatedItems) || updatedItems.length === 0) {
        detailsBody.innerHTML = '<tr><td colspan="8" class="text-center text-muted py-4">No actual price changes detected.</td></tr>';
    } else {
        detailsBody.innerHTML = updatedItems.map(item => `
            <tr>
                <td>${escapeHtml(item.item_code || '-')}</td>
                <td>
                    <strong>${escapeHtml(item.item_name || '-')}</strong>
                    ${item.description ? `<div class="small text-muted">${escapeHtml(item.description)}</div>` : ''}
                </td>
                <td>${escapeHtml(item.unit_type_name || '-')}</td>
                <td>${escapeHtml(item.price_level || 'Standard')}</td>
                <td>${formatBatchPriceAmount(item.old_price)}</td>
                <td><span class="fw-bold text-success">${formatBatchPriceAmount(item.new_price)}</span></td>
                <td>${escapeHtml(item.effective_date || '-')}</td>
                <td><span class="badge ${item.update_type === 'Scheduled' ? 'bg-warning text-dark' : 'bg-success'}">${escapeHtml(item.update_type || 'Applied')}</span></td>
            </tr>
        `).join('');
    }

    return new Promise(resolve => {
        const detailsModalElement = document.getElementById('batchPriceUpdateDetailsModal');
        const detailsModal = bootstrap.Modal.getOrCreateInstance(detailsModalElement);

        const handleHidden = () => {
            detailsModalElement.removeEventListener('hidden.bs.modal', handleHidden);
            Swal.fire({
                icon: 'success',
                title: 'Success',
                text: message || 'Price updates saved successfully.'
            }).then(() => resolve());
        };

        detailsModalElement.addEventListener('hidden.bs.modal', handleHidden);
        detailsModal.show();
    });
}


function saveBatchPriceLevelUpdates() {
    const effectiveDate = document.getElementById('batchPriceEffectiveDate')?.value || '';
    const priceLevel = getSelectedBatchPriceLevel() || 'Standard';

    if (!effectiveDate) {
        Swal.fire({ icon: 'warning', title: 'Required', text: 'Please select an effective date.' });
        return;
    }

    if (!priceLevel) {
        Swal.fire({ icon: 'warning', title: 'Required', text: 'Please select or type a price level.' });
        return;
    }

    const updates = [];
    document.querySelectorAll('#batchPriceLevelBody .batch-price-row').forEach(row => {
        const priceInput = row.querySelector('.batch-new-price');
        const itemId = row.querySelector('.batch-item-id')?.value;
        const unitTypeId = row.querySelector('.batch-unit-type-id')?.value;
        const unitQuantity = row.querySelector('.batch-unit-quantity')?.value || 1;
        if (!priceInput || itemId === undefined || unitTypeId === undefined) return;
        if (priceInput.dataset.edited !== '1') return;

        const rawPrice = (priceInput.value || '').trim();
        if (rawPrice === '') return;

        updates.push({
            item_id: parseInt(itemId, 10),
            unit_type_id: parseInt(unitTypeId, 10),
            unit_quantity: parseInt(unitQuantity, 10) || 1,
            unit_price: parseFloat(rawPrice) || 0
        });
    });

    if (updates.length === 0) {
        Swal.fire({ icon: 'warning', title: 'No Changes', text: 'Click and edit at least one New Price before updating.' });
        return;
    }

    Swal.fire({
        title: 'Saving price updates...',
        text: 'Please wait while the prices are being updated.',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });

    const formData = new FormData();
    formData.append('action', 'batch_update_price_level');
    formData.append('effective_date', effectiveDate);
    formData.append('price_level', priceLevel);
    formData.append('updates', JSON.stringify(updates));

    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            Swal.close();
            showBatchPriceUpdateDetailsModal(data.updated_items || [], data.message)
                .then(() => window.location.reload());
        } else {
            Swal.fire({ icon: 'error', title: 'Error', text: data.message || 'Failed to save price updates.' });
        }
    })
    .catch(() => {
        Swal.fire({ icon: 'error', title: 'Error', text: 'An error occurred while saving the batch price level update.' });
    });
}

document.addEventListener('DOMContentLoaded', function() {
    const batchPriceEffectiveDate = document.getElementById('batchPriceEffectiveDate');
    const batchPriceLevelSelect = document.getElementById('batchPriceLevelSelect');
    const batchNewPriceLevel = document.getElementById('batchNewPriceLevel');
    if (batchPriceEffectiveDate) {
        batchPriceEffectiveDate.addEventListener('change', loadBatchPriceLevelTable);
    }
    if (batchPriceLevelSelect) {
        batchPriceLevelSelect.addEventListener('change', handleBatchPriceLevelSelectionChange);
    }
    if (batchNewPriceLevel) {
        batchNewPriceLevel.addEventListener('blur', loadBatchPriceLevelTable);
    }
});

// Handle Volume field visibility based on category selection
document.addEventListener('DOMContentLoaded', function() {
    const categoryField = document.getElementById('category');
    const volumeField = document.getElementById('volumeField');
    
    if (categoryField) {
        // Check on page load and category change
        function toggleVolumeField() {
            const category = categoryField.value.trim().toLowerCase();
            if (volumeField) {
                volumeField.style.display = (category === 'oil') ? 'block' : 'none';
            }
        }
        
        categoryField.addEventListener('change', toggleVolumeField);
        categoryField.addEventListener('input', toggleVolumeField);
        // Check initial state on load
        toggleVolumeField();
    }
});

// Handle Volume field visibility in edit modal based on category selection
document.addEventListener('DOMContentLoaded', function() {
    const editCategoryField = document.getElementById('editCategory');
    const editVolumeField = document.getElementById('editVolumeField');
    
    if (editCategoryField) {
        // Check on page load and category change
        function toggleEditVolumeField() {
            const category = editCategoryField.value.trim().toLowerCase();
            if (editVolumeField) {
                editVolumeField.style.display = (category === 'oil') ? 'block' : 'none';
            }
        }
        
        editCategoryField.addEventListener('change', toggleEditVolumeField);
        editCategoryField.addEventListener('input', toggleEditVolumeField);
    }
});

// ========== ITEM CRUD FUNCTIONS ==========

function calculateUnitTotalCost(row) {
    const inventoryInput = row.querySelector('input[name="current_inventory[]"], input[name="edit_current_inventory[]"]');
    const unitCostInput = row.querySelector('input[name="unit_cost[]"], input[name="edit_unit_cost[]"]');
    const totalCostInput = row.querySelector('input[name="total_cost[]"], input[name="edit_total_cost[]"]');
    if (!totalCostInput) return;
    const inventory = inventoryInput ? (parseFloat(inventoryInput.value) || 0) : 0;
    const unitCost = unitCostInput ? (parseFloat(unitCostInput.value) || 0) : 0;
    totalCostInput.value = (inventory * unitCost).toFixed(2);
}

function bindUnitInventoryRowEvents(row) {
    const inventoryInput = row.querySelector('input[name="current_inventory[]"], input[name="edit_current_inventory[]"]');
    const unitCostInput = row.querySelector('input[name="unit_cost[]"], input[name="edit_unit_cost[]"]');
    if (inventoryInput) {
        inventoryInput.addEventListener('input', () => calculateUnitTotalCost(row));
    }
    if (unitCostInput) {
        unitCostInput.addEventListener('input', () => calculateUnitTotalCost(row));
    }
    calculateUnitTotalCost(row);
}


function setPrincipalSelectValue(inputEl, value) {
    if (!inputEl) return;
    const cleanValue = (value || '').trim();
    inputEl.value = (cleanValue === '' || cleanValue.toLowerCase() === 'no principal') ? '' : cleanValue;
}

function addPrincipalOptionToDatalists(principalName) {
    const cleanName = (principalName || '').trim();
    if (!cleanName || cleanName.toLowerCase() === 'no principal') return;

    ['principalList', 'editPrincipalList'].forEach(id => {
        const listEl = document.getElementById(id);
        if (!listEl) return;
        const exists = Array.from(listEl.options).some(opt => opt.value.toLowerCase() === cleanName.toLowerCase());
        if (!exists) {
            const option = document.createElement('option');
            option.value = cleanName;
            listEl.appendChild(option);
        }
    });

    const principalFilter = document.getElementById('principalFilter');
    if (principalFilter) {
        const exists = Array.from(principalFilter.options).some(opt => opt.value.toLowerCase() === cleanName.toLowerCase());
        if (!exists) {
            principalFilter.add(new Option(cleanName, cleanName));
        }
    }
}

function normalizePrincipalValue(value) {
    const cleanValue = (value || '').trim();
    if (!cleanValue || cleanValue.toLowerCase() === 'no principal') return '';
    addPrincipalOptionToDatalists(cleanValue);
    return cleanValue;
}

function showAddItemModal() {
    cleanupModalBackdrops();
    
    document.getElementById('itemForm').reset();
    document.getElementById('itemId').value = '';
    document.getElementById('itemCode').value = '<?= $next_item_code ?>';
    document.getElementById('itemCode').readOnly = true;
    document.getElementById('status').value = 'active';
    if (document.getElementById('principal')) document.getElementById('principal').value = '';
    if (document.getElementById('volume')) document.getElementById('volume').value = '';
    
    const unitTypesBody = document.getElementById('unitTypesBody');
    unitTypesBody.innerHTML = '';
    const pricingBody = document.getElementById('pricingBody');
    pricingBody.innerHTML = '';
    currentUnitTypes = [];
    
    // Add default unit type row
    addUnitTypeRow();
    addPricingRow();
    
    const editBtn = document.getElementById('editItemCodeBtn');
    if (editBtn) {
        editBtn.innerHTML = '<i class="bi bi-pencil"></i> Edit';
        editBtn.classList.remove('btn-success');
        editBtn.classList.add('btn-outline-secondary');
    }
    
    // Hide volume field by default for new items
    const volumeField = document.getElementById('volumeField');
    if (volumeField) volumeField.style.display = 'none';
    
    new bootstrap.Modal(document.getElementById('itemModal')).show();
}

// Add Unit Type Row (Add Modal)
function addUnitTypeRow() {
    const tbody = document.getElementById('unitTypesBody');
    const rowId = 'unitType_' + Date.now();
    const isFirstRow = tbody.children.length === 0;
    
    const row = document.createElement('tr');
    row.id = rowId;
    row.innerHTML = `
        <td><input type="text" class="form-control form-control-sm" placeholder="e.g., Piece, Box, Carton" name="unit_type[]" required></td>
        <td><input type="text" class="form-control form-control-sm text-uppercase" placeholder="CS" maxlength="20" name="uom_initial[]"></td>
        <td>
   <div class="input-group barcode-group">
    <input type="text"
        class="form-control form-control-sm uom-barcode-input"
        placeholder="Type or Scan barcode"
        name="barcode[]"
        inputmode="numeric"
        autocomplete="off">

    <button type="button"
        class="btn scan-barcode-btn"
        onclick="scanBarcode(this)"
        title="Scan Barcode">

        <i class="bi bi-upc-scan"></i>

    </button>
</div>
</td>
        <td><input type="number" class="form-control form-control-sm" placeholder="1" min="1" value="1" name="qty_smallest_pack[]"></td>
        <td><input type="number" class="form-control form-control-sm" placeholder="0" min="0" step="0.01" value="0" name="current_inventory[]"></td>
        <td><input type="date" class="form-control form-control-sm" name="as_of_date[]"></td>
        <td class="text-center"><input type="radio" class="form-check-input default-uom-radio" name="default_uom" value="1" ${isFirstRow ? 'checked' : ''} onchange="handleDefaultUOMChange(this)"></td>
        <td><input type="number" class="form-control form-control-sm" placeholder="0.00" min="0" step="0.01" value="0" name="unit_cost[]"></td>
        <td><input type="number" class="form-control form-control-sm" placeholder="0.00" min="0" step="0.01" value="0.00" name="total_cost[]" readonly></td>
        <td class="text-center">
            ${!isFirstRow ? `<button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('tr').remove(); updatePricingTableColumns()"><i class="bi bi-trash"></i></button>` : '<span class="text-muted">Default</span>'}
        </td>
    `;
    tbody.appendChild(row);
    bindUnitInventoryRowEvents(row);
    
    const unitTypeInput = row.querySelector('input[name="unit_type[]"]');
    if (unitTypeInput) {
        unitTypeInput.addEventListener('change', () => updatePricingTableColumns());
        unitTypeInput.addEventListener('keyup', () => updatePricingTableColumns());
    }
    
    updatePricingTableColumns();
}

function updateUnitTypeStatus(checkbox) { return true; }

// Handle Default UoM radio button - only one can be checked at a time
function handleDefaultUOMChange(radio) {
    if (radio.checked) {
        // Uncheck all other default_uom radio buttons in the table
        const allRadios = document.querySelectorAll('input[name="default_uom"]');
        allRadios.forEach(r => {
            if (r !== radio) {
                r.checked = false;
            }
        });
    }
}



function updatePricingTableColumns() {
    const unitTypeRows = document.querySelectorAll('#unitTypesBody tr');
    const unitTypes = [];
    
    unitTypeRows.forEach(row => {
        const unitTypeInput = row.querySelector('input[name="unit_type[]"]');
        if (unitTypeInput && unitTypeInput.value.trim()) {
            unitTypes.push(unitTypeInput.value.trim());
        }
    });
    
    currentUnitTypes = unitTypes;
    
    const pricingTableHead = document.getElementById('pricingTableHead');
    if (!pricingTableHead) return;
    
    const headerRow = pricingTableHead.querySelector('tr');
    if (!headerRow) return;
    
    // Remove all dynamic columns (keep first two + action)
    const thElements = Array.from(headerRow.querySelectorAll('th'));
    for (let i = thElements.length - 1; i >= 2; i--) {
        if (i < thElements.length - 1) { // keep the last (action) column
            thElements[i].remove();
        }
    }
    
    // Insert new columns for each unit type (before the action column)
    const actionTh = headerRow.querySelector('th:last-child');
    unitTypes.forEach((unitType) => {
        const th = document.createElement('th');
        th.style.width = '15%';
        th.textContent = unitType;
        headerRow.insertBefore(th, actionTh);
    });
    
    // Update each pricing row
    const pricingRows = document.querySelectorAll('#pricingBody tr');
    pricingRows.forEach(row => {
        const cells = Array.from(row.querySelectorAll('td'));
        // Remove dynamic price cells (keep first two + action)
        for (let i = cells.length - 2; i >= 2; i--) { // stop before last cell (action)
            cells[i].remove();
        }
        
        // Insert new price inputs for each unit type
        unitTypes.forEach((unitType, index) => {
            const td = document.createElement('td');
            const input = document.createElement('input');
            input.type = 'number';
            input.className = 'form-control form-control-sm pricing-input';
            input.dataset.unitType = unitType;
            input.placeholder = `₱0.00 (${unitType})`;
            input.step = '0.01';
            input.min = '0';
            td.appendChild(input);
            row.insertBefore(td, cells[cells.length - 1]); // insert before the action cell
        });
    });
}

function buildPriceLevelSelectHtml(className, selectedValue) {
    const value = String(selectedValue || 'Standard').trim() || 'Standard';
    return `<input type="text" class="form-control form-control-sm ${className}" list="priceLevelOptions" value="${escapeHtml(value)}" placeholder="Type or select price level" autocomplete="off">`;
}

function addPricingRow() {
    const tbody = document.getElementById('pricingBody');
    const unitTypes = currentUnitTypes.length > 0 ? currentUnitTypes : ['Piece'];
    
    const rowId = 'pricing_' + Date.now();
    const row = document.createElement('tr');
    row.id = rowId;
    
    let rowHTML = `
        <td><input type="date" class="form-control form-control-sm pricing-date"></td>
        <td>${buildPriceLevelSelectHtml('pricing-level', 'Standard')}</td>
    `;
    
    unitTypes.forEach((unitType) => {
        rowHTML += `<td><input type="number" class="form-control form-control-sm pricing-input" data-unit-type="${unitType}" placeholder="₱0.00" step="0.01" min="0"></td>`;
    });
    
    rowHTML += `<td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger" onclick="removePricingRow(this)"><i class="bi bi-trash"></i></button></td>`;
    
    row.innerHTML = rowHTML;
    tbody.appendChild(row);

}

function removePricingRow(btn) {
    const row = btn.closest('tr');
    if (row) row.remove();
}

function collectUnitTypesAndPricing() {
    const unitTypeRows = document.querySelectorAll('#unitTypesBody tr');
    const unitTypesData = [];
    
    unitTypeRows.forEach(row => {
        const unitTypeInput = row.querySelector('input[name="unit_type[]"]');
        const initialInput = row.querySelector('input[name="uom_initial[]"]');
        const barcodeInput = row.querySelector('input[name="barcode[]"]');
        const qtyInput = row.querySelector('input[name="qty_smallest_pack[]"]');
        const currentInventoryInput = row.querySelector('input[name="current_inventory[]"]');
        const asOfDateInput = row.querySelector('input[name="as_of_date[]"]');
        const defaultRadio = row.querySelector('input[name="default_uom"]');
        const unitCostInput = row.querySelector('input[name="unit_cost[]"]');
        
        if (unitTypeInput && unitTypeInput.value.trim()) {
            unitTypesData.push({
                unit_type: unitTypeInput.value.trim(),
                uom_initial: initialInput ? initialInput.value.trim().toUpperCase() : '',
                barcode: barcodeInput ? barcodeInput.value.trim() : '',
                qty_smallest_pack: qtyInput ? parseInt(qtyInput.value) || 1 : 1,
                current_inventory: currentInventoryInput ? parseFloat(currentInventoryInput.value) || 0 : 0,
                as_of_date: asOfDateInput ? asOfDateInput.value : '',
                default_uom: defaultRadio ? (defaultRadio.checked ? 1 : 0) : 0,
                unit_cost: unitCostInput ? parseFloat(unitCostInput.value) || 0 : 0,
                status: 'active',
                unit_price: 0
            });
        }
    });
    
    const pricingRows = document.querySelectorAll('#pricingBody tr');
    const pricingData = [];
    
    pricingRows.forEach((row, rowIndex) => {
        const dateInput = row.querySelector('.pricing-date');
        const levelInput = row.querySelector('.pricing-level');
        const priceInputs = row.querySelectorAll('.pricing-input');
        
        const priceLevel = levelInput ? levelInput.value.trim() : '';
        if (!priceLevel) return; // Skip rows without a price level
        
        const priceRow = {
            effective_date: dateInput ? dateInput.value : null,
            price_level: priceLevel || 'Standard',
            prices: {}
        };
        
        priceInputs.forEach((input, idx) => {
            if (idx < unitTypesData.length) {
                const price = parseFloat(input.value) || 0;
                priceRow.prices[unitTypesData[idx].unit_type] = price;
            }
        });
        
        // Add all rows with a price level, regardless of whether prices are filled
        pricingData.push(priceRow);
    });
    
    if (pricingData.length > 0 && unitTypesData.length > 0) {
        const firstPriceRow = pricingData[0];
        unitTypesData.forEach(ut => {
            ut.unit_price = firstPriceRow.prices[ut.unit_type] || 0;
        });
    }
    
    return { unitTypesData, pricingData };
}

function saveItem() {
    const itemCode = document.getElementById('itemCode').value.trim();
    const itemName = document.getElementById('itemName').value.trim();
    const category = document.getElementById('category').value;
    const principal = normalizePrincipalValue(document.getElementById('principal') ? document.getElementById('principal').value : '');
    const description = document.getElementById('description').value;
        const reorderLevel = document.getElementById('reorderLevel').value;
    const status = document.getElementById('status').value;
    
    if (!itemCode || !itemName || !reorderLevel) {
        Swal.fire({ icon: 'warning', title: 'Required Fields', text: 'Please fill in all required fields' });
        return;
    }
    
    const { unitTypesData, pricingData } = collectUnitTypesAndPricing();
    
    if (unitTypesData.length === 0) {
        Swal.fire({ icon: 'warning', title: 'Unit Types Required', text: 'Please add at least one unit type' });
        return;
    }
    
    let hasPrice = false;
    for (const ut of unitTypesData) {
        if (ut.unit_price > 0) {
            hasPrice = true;
            break;
        }
    }
    
    if (!hasPrice) {
        Swal.fire({ icon: 'warning', title: 'Pricing Required', text: 'Please set at least one price for a unit type' });
        return;
    }
    
    showLoading();
    
    // Barcode is optional. If typed in any UoM barcode field, save it to items.barcode.
    const barcode = (unitTypesData.find(ut => (ut.barcode || '').trim() !== '')?.barcode || '').trim();

    const formData = new FormData();
    formData.append('action', 'add_item');
    formData.append('item_code', itemCode);
    formData.append('barcode', barcode);
    formData.append('item_name', itemName);
    formData.append('description', description);
    formData.append('category', category);
    formData.append('principal', principal);
    const volume = document.getElementById('volume') ? document.getElementById('volume').value.trim() : '';
    formData.append('volume', volume);
    const oilType = document.getElementById('oilType') ? document.getElementById('oilType').value.trim() : '';
    formData.append('oil_type', oilType);
    formData.append('reorder_level', reorderLevel);
    formData.append('status', status);
    formData.append('unit_types', JSON.stringify(unitTypesData));
    formData.append('pricing', JSON.stringify(pricingData));
    
    const imagesInput = document.getElementById('itemImages');
    if (imagesInput && imagesInput.files.length > 0) {
        for (let i = 0; i < imagesInput.files.length; i++) {
            formData.append('itemImages[]', imagesInput.files[i]);
        }
    }
    
    fetch('current_inventory.php', { method: 'POST', body: formData })
        .then(response => response.json())
        .then(data => {
            Swal.close();
            if (data.success) {
                Swal.fire({ icon: 'success', title: 'Success!', text: data.message, timer: 2000, showConfirmButton: false })
                    .then(() => {
                        bootstrap.Modal.getInstance(document.getElementById('itemModal')).hide();
                        location.reload();
                    });
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: data.message });
            }
        })
        .catch(error => {
            Swal.close();
            Swal.fire({ icon: 'error', title: 'Error', text: 'An error occurred: ' + error.message });
        });
}


function showItemTransactionDetails(index) {
    const transactions = window.currentItemTransactions || [];
    const tx = transactions[Number(index)] || null;
    if (!tx) return;

    const esc = (value) => escapeHtml((value === null || value === undefined || String(value).trim() === '') ? '—' : String(value));
    const sourceLabel = tx.source_label || tx.reference_type || tx.transaction_type || 'Transaction';
    const partyLabel = tx.party_name || tx.supplier_name || '—';
    const referenceLabel = tx.reference_label || tx.po_number || (tx.reference_id ? `Ref #${tx.reference_id}` : '—');
    const uomLabel = tx.uom || tx.unit_type || '—';
    const actorName = (tx.actor_name || tx.received_by_name || '').trim() || '—';
    const qty = Number(tx.quantity || 0);
    const qtyClass = qty < 0 ? 'text-danger' : 'text-success';
    const dateValue = tx.created_at ? new Date(tx.created_at).toLocaleString() : '—';

    const html = `
        <div class="text-start">
            <div class="row g-2">
                <div class="col-md-6"><div class="border rounded p-2 h-100"><small class="text-muted">Date</small><div class="fw-semibold">${esc(dateValue)}</div></div></div>
                <div class="col-md-6"><div class="border rounded p-2 h-100"><small class="text-muted">Source</small><div class="fw-semibold">${esc(sourceLabel)}</div></div></div>
                <div class="col-md-6"><div class="border rounded p-2 h-100"><small class="text-muted">Reference</small><div class="fw-semibold">${esc(referenceLabel)}</div></div></div>
                <div class="col-md-6"><div class="border rounded p-2 h-100"><small class="text-muted">Party</small><div class="fw-semibold">${esc(partyLabel)}</div></div></div>
                <div class="col-md-6"><div class="border rounded p-2 h-100"><small class="text-muted">UoM</small><div class="fw-semibold">${esc(uomLabel)}</div></div></div>
                <div class="col-md-6"><div class="border rounded p-2 h-100"><small class="text-muted">Quantity</small><div class="fw-semibold ${qtyClass}">${Number(qty || 0).toLocaleString(undefined, {minimumFractionDigits: 0, maximumFractionDigits: 2})}</div></div></div>
                <div class="col-md-6"><div class="border rounded p-2 h-100"><small class="text-muted">Unit Cost</small><div class="fw-semibold">₱${Number(tx.unit_cost || 0).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</div></div></div>
                <div class="col-md-6"><div class="border rounded p-2 h-100"><small class="text-muted">Total Cost</small><div class="fw-semibold">₱${Number(tx.total_cost || ((tx.unit_cost || 0) * qty) || 0).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</div></div></div>
                <div class="col-md-6"><div class="border rounded p-2 h-100"><small class="text-muted">Encoded / Updated By</small><div class="fw-semibold">${esc(actorName)}</div></div></div>
                <div class="col-md-6"><div class="border rounded p-2 h-100"><small class="text-muted">Transaction Type</small><div class="fw-semibold">${esc(tx.transaction_type)}</div></div></div>
                <div class="col-md-6"><div class="border rounded p-2 h-100"><small class="text-muted">Reference Type</small><div class="fw-semibold">${esc(tx.reference_type)}</div></div></div>
                <div class="col-md-6"><div class="border rounded p-2 h-100"><small class="text-muted">Reference ID</small><div class="fw-semibold">${esc(tx.reference_id)}</div></div></div>
                ${tx.notes ? `<div class="col-12"><div class="border rounded p-2"><small class="text-muted">Notes</small><div class="fw-semibold">${esc(tx.notes)}</div></div></div>` : ''}
            </div>
        </div>`;

    if (typeof Swal !== 'undefined') {
        Swal.fire({
            title: 'Transaction Details',
            html: html,
            width: 760,
            confirmButtonColor: '#047857'
        });
    } else {
        alert(`Transaction Details
Reference: ${referenceLabel}
Party: ${partyLabel}
UoM: ${uomLabel}
Quantity: ${qty}`);
    }
}

let currentViewItemData = null;

function viewItem(id) {
    cleanupModalBackdrops();
    showLoading();
    const formData = new FormData();
    formData.append('action', 'get_item');
    formData.append('item_id', id);
    
    fetch('current_inventory.php', { method: 'POST', body: formData })
        .then(response => response.json())
        .then(data => {
            Swal.close();
            if (data.success) {
                const item = data.item;
                currentViewItemData = item;
                const unitTypes = data.unit_types || [];
                const pricingRows = data.pricing_rows || [];
                const pricingHistory = data.pricing_history || [];
                const inventorySummary = data.inventory_summary || {};
                const transactions = data.transactions || [];
                const images = data.images || [];
                item.status = item.status || 'inactive';
                item.item_code = item.item_code || '';
                item.item_name = item.item_name || '';
                const stock = Number(inventorySummary.total_inventory || item.stock || 0);
                const reorder = Number(item.reorder_level || 0);

                const formatMoney = (value) => `₱${Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
                const formatNumber = (value) => Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 2 });
                
                let stockClass = '';
                if (stock <= 0) stockClass = 'out-of-stock';
                else if (stock <= reorder) stockClass = 'low-stock';
                
                let statusClass = '';
                let statusIcon = '';
                if (item.status === 'active') {
                    statusClass = 'status-active';
                    statusIcon = 'bi-check-circle-fill';
                } else if (item.status === 'inactive') {
                    statusClass = 'status-inactive';
                    statusIcon = 'bi-pause-circle-fill';
                } else {
                    statusClass = 'status-discontinued';
                    statusIcon = 'bi-x-circle-fill';
                }
                
                let imagesHtml = '';
                let mainImageHtml = '';
                
                if (images.length > 0) {
                    const primaryImage = images.find(img => img.is_primary) || images[0];
                    mainImageHtml = `<img src="../uploads/products/${escapeHtml(primaryImage.image_path)}" alt="${escapeHtml(item.item_name)}" class="main-image" id="mainViewImage">`;
                    
                    imagesHtml = `<div class="item-images-carousel">`;
                    images.forEach((img, idx) => {
                        const activeClass = (img.is_primary || idx === 0) ? 'active' : '';
                        imagesHtml += `<img src="../uploads/products/${escapeHtml(img.image_path)}" alt="Thumbnail" class="item-image-thumb ${activeClass}" onclick="document.getElementById('mainViewImage').src = this.src; document.querySelectorAll('.item-image-thumb').forEach(t => t.classList.remove('active')); this.classList.add('active');">`;
                    });
                    imagesHtml += `</div>`;
                } else {
                    mainImageHtml = `<div class="no-image text-center py-4"><i class="bi bi-image" style="font-size: 64px; color: #adb5bd;"></i><p class="text-muted mt-2">No image available</p></div>`;
                }
                
                let unitTypesHtml = '';
                if (pricingRows.length > 0) {
                    unitTypesHtml = `<div class="table-responsive"><table class="table table-sm table-borderless align-middle">`;
                    pricingRows.forEach(pr => {
                        const effectiveDate = pr.effective_date ? new Date(pr.effective_date).toLocaleDateString() : 'Immediate';
                        let priceLines = '';
                        unitTypes.forEach(ut => {
                            const priceInfo = pr.prices && pr.prices[ut.unit_type_name] ? pr.prices[ut.unit_type_name] : null;
                            const formattedPrice = priceInfo ? `₱${parseFloat(priceInfo.unit_price || 0).toFixed(2)}` : '—';
                            const invQty = parseFloat(ut.current_inventory || 0).toFixed(2).replace(/\.00$/, '');
                            const unitCost = parseFloat(ut.unit_cost || 0).toFixed(2);
                            priceLines += `<div><span class="info-label">${escapeHtml(ut.unit_type_name)}:</span> <span class="info-value price-value">${formattedPrice}</span> <small class="text-muted">| Inv: ${invQty} | Cost: ₱${unitCost}</small></div>`;
                        });
                        unitTypesHtml += `<tr><td class="info-label" style="width: 160px;">${escapeHtml(pr.price_level || 'Standard')}<br><small class="text-muted">Effective: ${effectiveDate}</small></td><td>${priceLines}</td></tr>`;
                    });
                    unitTypesHtml += `</table></div>`;
                } else if (unitTypes.length > 0) {
                    unitTypesHtml = `<table class="table table-sm table-borderless">`;
                    unitTypes.forEach(ut => {
                        unitTypesHtml += `<tr><td class="info-label">${escapeHtml(ut.unit_type_name)}:</td><td class="info-value"><span class="badge ${ut.unit_status === 'active' ? 'bg-success' : 'bg-secondary'}">${escapeHtml(ut.unit_status)}</span></td></tr>`;
                    });
                    unitTypesHtml += `</table>`;
                } else {
                    unitTypesHtml = `<p class="text-muted">No unit types configured</p>`;
                }
                
                const stockValue = stock.toLocaleString();
                const stockValueTotal = Number(inventorySummary.total_cost || 0).toFixed(2);
                const createdByName = item.created_by_name || 'System';
                const createdDate = new Date(item.created_at).toLocaleString();
                const updatedDate = new Date(item.updated_at).toLocaleString();

                let beginningInventoryHtml = `<div class="table-responsive"><table class="table table-sm table-bordered align-middle">
                    <thead class="table-light">
                        <tr><th>UoM</th><th>Beginning Inventory</th><th>As Of</th><th>Unit Cost</th><th>Total Cost</th></tr>
                    </thead><tbody>`;
                if (unitTypes.length > 0) {
                    unitTypes.forEach(ut => {
                        beginningInventoryHtml += `<tr>
                            <td>${escapeHtml(ut.unit_type_name)}</td>
                            <td>${formatNumber(ut.beginning_inventory ?? ut.current_inventory)}</td>
                            <td>${ut.as_of_date ? new Date(ut.as_of_date).toLocaleDateString() : '—'}</td>
                            <td>${formatMoney(ut.unit_cost)}</td>
                            <td>${formatMoney(ut.total_cost)}</td>
                        </tr>`;
                    });
                    beginningInventoryHtml += `<tr class="fw-bold table-light">
                        <td>Total</td>
                        <td>${formatNumber(inventorySummary.beginning_inventory)}</td>
                        <td>—</td>
                        <td>—</td>
                        <td>${formatMoney(inventorySummary.total_cost)}</td>
                    </tr>`;
                } else {
                    beginningInventoryHtml += `<tr><td colspan="5" class="text-center text-muted">No inventory records found</td></tr>`;
                }
                beginningInventoryHtml += `</tbody></table></div>`;

                const pricingHistoryUomColumns = [];
                unitTypes.forEach(ut => {
                    const uomName = (ut.unit_type_name || '').trim();
                    if (uomName && !pricingHistoryUomColumns.some(existing => existing.toLowerCase() === uomName.toLowerCase())) {
                        pricingHistoryUomColumns.push(uomName);
                    }
                });
                pricingHistory.forEach(row => {
                    const uomName = (row.unit_type_name || '').trim();
                    if (uomName && !pricingHistoryUomColumns.some(existing => existing.toLowerCase() === uomName.toLowerCase())) {
                        pricingHistoryUomColumns.push(uomName);
                    }
                });

                let pricingHistoryHtml = `<div class="table-responsive pricing-history-scroll inventory-table-scroll"><table class="table table-sm table-bordered align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Updated At</th>
                            <th>Effective Date</th>
                            <th>Price Level</th>
                            ${pricingHistoryUomColumns.map(uomName => `<th>${escapeHtml(uomName)}</th>`).join('')}
                        </tr>
                    </thead><tbody>`;

                if (pricingHistory.length > 0) {
                    const groupedPricingHistory = {};
                    pricingHistory.forEach(row => {
                        const rawEffectiveDate = row.effective_date || '';
                        const displayEffectiveDate = rawEffectiveDate ? new Date(rawEffectiveDate).toLocaleDateString() : 'Immediate';
                        const priceLevel = row.price_level || 'Standard';
                        const rawSortDate = row.sort_datetime || row.updated_at || row.created_at || '';
                        const displayUpdatedAt = rawSortDate ? new Date(rawSortDate).toLocaleString() : '—';
                        const historySource = row.history_source || 'current';
                        const groupKey = `${rawSortDate || 'unknown'}__${rawEffectiveDate || 'immediate'}__${priceLevel}__${historySource}`;
                        const uomName = (row.unit_type_name || '').trim();

                        if (!groupedPricingHistory[groupKey]) {
                            groupedPricingHistory[groupKey] = {
                                effective_date: rawEffectiveDate,
                                sort_datetime: rawSortDate,
                                display_updated_at: displayUpdatedAt,
                                display_effective_date: displayEffectiveDate,
                                price_level: priceLevel,
                                history_source: historySource,
                                prices: {}
                            };
                        }

                        if (uomName && groupedPricingHistory[groupKey].prices[uomName] === undefined) {
                            groupedPricingHistory[groupKey].prices[uomName] = row.unit_price;
                        }
                    });

                    const getPricingHistoryRank = (historySource) => {
                        const source = String(historySource || 'current').toLowerCase();
                        if (source === 'scheduled') return 1;
                        if (source === 'current') return 2;
                        return 3;
                    };

                    const groupedRows = Object.values(groupedPricingHistory).sort((a, b) => {
                        const aRank = getPricingHistoryRank(a.history_source);
                        const bRank = getPricingHistoryRank(b.history_source);
                        if (aRank !== bRank) return aRank - bRank;

                        const aEffectiveTime = a.effective_date ? new Date(a.effective_date).getTime() : 0;
                        const bEffectiveTime = b.effective_date ? new Date(b.effective_date).getTime() : 0;
                        if (bEffectiveTime !== aEffectiveTime) return bEffectiveTime - aEffectiveTime;

                        const aSortTime = a.sort_datetime ? new Date(a.sort_datetime).getTime() : 0;
                        const bSortTime = b.sort_datetime ? new Date(b.sort_datetime).getTime() : 0;
                        return bSortTime - aSortTime;
                    });

                    groupedRows.forEach(row => {
                        const historySourceLower = String(row.history_source || 'current').toLowerCase();
                        const isScheduledHistory = historySourceLower === 'scheduled';
                        const isPreviousHistory = historySourceLower.includes('previous') || historySourceLower.includes('import');
                        const sourceBadgeClass = isScheduledHistory ? 'bg-warning text-dark' : (isPreviousHistory ? 'bg-secondary' : 'bg-success');
                        const sourceLabel = isScheduledHistory ? 'Scheduled' : (isPreviousHistory ? 'Previous' : 'Current');
                        pricingHistoryHtml += `<tr>
                            <td>${escapeHtml(row.display_updated_at)}<br><span class="badge ${sourceBadgeClass}">${sourceLabel}</span></td>
                            <td>${escapeHtml(row.display_effective_date)}</td>
                            <td>${escapeHtml(row.price_level)}</td>
                            ${pricingHistoryUomColumns.map(uomName => {
                                const matchedKey = Object.keys(row.prices).find(key => key.toLowerCase() === uomName.toLowerCase());
                                return `<td>${matchedKey ? formatMoney(row.prices[matchedKey]) : '—'}</td>`;
                            }).join('')}
                        </tr>`;
                    });
                } else {
                    pricingHistoryHtml += `<tr><td colspan="${3 + pricingHistoryUomColumns.length}" class="text-center text-muted">No pricing history found</td></tr>`;
                }
                pricingHistoryHtml += `</tbody></table></div>`;

                window.currentItemTransactions = Array.isArray(transactions) ? transactions : [];
                let transactionsHtml = `<div class="table-responsive transactions-history-scroll inventory-table-scroll"><table class="table table-sm table-bordered align-middle mb-0">
                    <thead class="table-light">
                        <tr><th>Date</th><th>Source</th><th>Reference</th><th>UoM</th><th>Quantity</th></tr>
                    </thead><tbody>`;
                if (window.currentItemTransactions.length > 0) {
                    window.currentItemTransactions.forEach((tx, txIndex) => {
                        const sourceLabel = tx.source_label || tx.reference_type || tx.transaction_type || 'Transaction';
                        const partyLabel = (tx.party_name || tx.supplier_name || '').trim();
                        const referenceLabel = tx.reference_label || tx.po_number || (tx.reference_id ? `Ref #${tx.reference_id}` : '—');
                        const uomLabel = (tx.uom || tx.unit_type || item.unit_type || '—').trim() || '—';
                        const qtyValue = Number(tx.quantity || 0);
                        const qtyClass = qtyValue < 0 ? 'text-danger fw-semibold' : 'text-success fw-semibold';
                        const referenceCell = `${escapeHtml(referenceLabel)}${partyLabel && partyLabel !== '—' ? `<br><small class="text-muted">${escapeHtml(partyLabel)}</small>` : ''}`;
                        transactionsHtml += `<tr class="item-transaction-row" onclick="showItemTransactionDetails(${txIndex})" style="cursor:pointer;">
                            <td>${tx.created_at ? new Date(tx.created_at).toLocaleString() : '—'}</td>
                            <td>${escapeHtml(sourceLabel)}</td>
                            <td>${referenceCell}</td>
                            <td>${escapeHtml(uomLabel)}</td>
                            <td><span class="${qtyClass}">${formatNumber(qtyValue)}</span></td>
                        </tr>`;
                    });
                } else {
                    transactionsHtml += `<tr><td colspan="5" class="text-center text-muted">No transactions found for this item</td></tr>`;
                }
                transactionsHtml += `</tbody></table></div>`;

                document.getElementById('viewItemContent').innerHTML = `
                    <div class="item-details-container">
                        ${mainImageHtml}
                        ${imagesHtml}
                        
                        <div class="detail-section">
                            <button class="detail-dropdown-toggle" type="button" data-bs-toggle="collapse" data-bs-target="#basicInfoDropdown" aria-expanded="false" aria-controls="basicInfoDropdown">
                                <span class="dropdown-title"><i class="bi bi-tag"></i> Basic Information</span>
                                <i class="bi bi-chevron-down dropdown-chevron"></i>
                            </button>
                            <div class="collapse detail-dropdown-body" id="basicInfoDropdown">
                                <div class="table-responsive detail-section-scroll">
                                    <table class="table table-sm table-borderless mb-0">
                                        <tr><td class="info-label">Item Code:</td><td class="info-value"><strong>${escapeHtml(item.item_code)}</strong></td></tr>
                                        <tr>
                                            <td class="info-label">Generated Barcode:</td>
                                            <td class="info-value">
                                                ${item.barcode ? `
                                                    <div class="barcode-preview-card" style="display:inline-block; background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:8px 10px; max-width:100%;">
                                                        <svg id="itemDetailsBarcodeSvg"></svg>
                                                        <div style="font-size:12px; font-weight:700; letter-spacing:1px; text-align:center; color:#052A47; margin-top:2px;">
                                                            ${escapeHtml(item.barcode)}
                                                        </div>
                                                    </div>
                                                ` : '<span class="text-muted">No barcode saved</span>'}
                                            </td>
                                        </tr>
                                        <tr><td class="info-label">Item Name:</td><td class="info-value">${escapeHtml(item.item_name)} <span class="status-badge ${statusClass}"><i class="bi ${statusIcon}"></i> ${item.status.charAt(0).toUpperCase() + item.status.slice(1)}</span></td></tr>
                                        <tr><td class="info-label">Category:</td><td class="info-value">${escapeHtml(item.category || 'Uncategorized')}</td></tr>
                                                <tr><td class="info-label">Principal:</td><td class="info-value">${escapeHtml(item.principal || 'No Principal')}</td></tr>
                                        ${item.volume && item.category && item.category.toLowerCase() === 'oil' ? `<tr><td class="info-label">Volume:</td><td class="info-value">${escapeHtml(item.volume)}</td></tr>` : ''}
                                        ${item.oil_type && item.category && item.category.toLowerCase() === 'oil' ? `<tr><td class="info-label">Oil Type:</td><td class="info-value">${escapeHtml(item.oil_type)}</td></tr>` : ''}
                                        <tr><td class="info-label">Description:</td><td class="info-value">${escapeHtml(item.description || 'No description')}</td></tr>
                                    </table>
                                </div>
                            </div>
                        </div>
                        
                        <div class="detail-section">
                            <button class="detail-dropdown-toggle" type="button" data-bs-toggle="collapse" data-bs-target="#inventoryInfoDropdown" aria-expanded="false" aria-controls="inventoryInfoDropdown">
                                <span class="dropdown-title"><i class="bi bi-box-seam"></i> Inventory Information</span>
                                <i class="bi bi-chevron-down dropdown-chevron"></i>
                            </button>
                            <div class="collapse detail-dropdown-body" id="inventoryInfoDropdown">
                                <div class="table-responsive detail-section-scroll mb-3">
                                    <table class="table table-sm table-bordered mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th>UNIT TYPE</th>
                                                <th>CURRENT STOCK</th>
                                                <th>AVERAGE COST</th>
                                                <th>TOTAL COST</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            ${unitTypes.map(ut => {
                                                const currentStock = parseFloat(ut.current_inventory || 0);
                                                const avgCost = parseFloat(ut.average_cost || ut.unit_cost || 0);
                                                const totalCost = currentStock * avgCost;
                                                return `
                                                <tr>
                                                    <td><strong>${escapeHtml(ut.unit_type_name)}</strong></td>
                                                    <td><span class="stock-value ${currentStock <= (parseFloat(ut.reorder_level || 0)) ? 'text-danger fw-bold' : ''}">${formatNumber(currentStock)}</span></td>
                                                    <td><span class="price-value">${formatMoney(avgCost)}</span></td>
                                                    <td><span class="price-value fw-bold">${formatMoney(totalCost)}</span></td>
                                                </tr>`;
                                            }).join('')}
                                        </tbody>
                                    </table>
                                </div>
                                <div class="table-responsive detail-section-scroll">
                                    <table class="table table-sm table-borderless mb-0">
                                        <tr><td class="info-label">Reorder Level:</td><td class="info-value">${reorder} ${item.unit_type} <span class="badge ${stock <= reorder ? 'bg-warning' : 'bg-info'}">${stock <= reorder ? 'Needs Restock' : 'Adequate'}</span></td></tr>
                                        <tr><td class="info-label">Stock Value:</td><td class="info-value price-value">₱${Number(stockValueTotal).toLocaleString(undefined, {minimumFractionDigits: 2})}</td></tr>
                                        <tr><td class="info-label">Beginning Inventory:</td><td class="info-value">${formatNumber(inventorySummary.beginning_inventory)} total across UoM</td></tr>
                                        <tr><td class="info-label">Average Cost / Month:</td><td class="info-value price-value">${formatMoney(inventorySummary.average_cost_month)}</td></tr>
                                        <tr><td class="info-label">Total Cost:</td><td class="info-value price-value">${formatMoney(inventorySummary.total_cost)}</td></tr>
                                        <tr><td class="info-label">Ave. Daily Offtake:</td><td class="info-value">${formatNumber(inventorySummary.ave_daily_offtake)} <small class="text-muted">(Last 30 days)</small></td></tr>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <div class="detail-section">
                            <button class="detail-dropdown-toggle" type="button" data-bs-toggle="collapse" data-bs-target="#beginningInventoryDropdown" aria-expanded="false" aria-controls="beginningInventoryDropdown">
                                <span class="dropdown-title"><i class="bi bi-archive"></i> Beginning Inventory by UoM</span>
                                <i class="bi bi-chevron-down dropdown-chevron"></i>
                            </button>
                            <div class="collapse detail-dropdown-body" id="beginningInventoryDropdown">
                                ${beginningInventoryHtml}
                            </div>
                        </div>

                        <div class="detail-section">
                            <button class="detail-dropdown-toggle" type="button" data-bs-toggle="collapse" data-bs-target="#unitTypesPricingDropdown" aria-expanded="false" aria-controls="unitTypesPricingDropdown">
                                <span class="dropdown-title"><i class="bi bi-calculator"></i> Unit Types & Pricing</span>
                                <i class="bi bi-chevron-down dropdown-chevron"></i>
                            </button>
                            <div class="collapse detail-dropdown-body" id="unitTypesPricingDropdown">
                                ${unitTypesHtml}
                            </div>
                        </div>

                        <div class="detail-section">
                            <button class="detail-dropdown-toggle" type="button" data-bs-toggle="collapse" data-bs-target="#pricingHistoryDropdown" aria-expanded="false" aria-controls="pricingHistoryDropdown">
                                <span class="dropdown-title"><i class="bi bi-clock-history"></i> Pricing History</span>
                                <i class="bi bi-chevron-down dropdown-chevron"></i>
                            </button>
                            <div class="collapse detail-dropdown-body" id="pricingHistoryDropdown">
                                ${pricingHistoryHtml}
                            </div>
                        </div>

                        <div class="detail-section">
                            <button class="detail-dropdown-toggle" type="button" data-bs-toggle="collapse" data-bs-target="#transactionsHistoryDropdown" aria-expanded="false" aria-controls="transactionsHistoryDropdown">
                                <span class="dropdown-title"><i class="bi bi-arrow-left-right"></i> Transactions</span>
                                <i class="bi bi-chevron-down dropdown-chevron"></i>
                            </button>
                            <div class="collapse detail-dropdown-body" id="transactionsHistoryDropdown">
                                ${transactionsHtml}
                            </div>
                        </div>
                        
                        <div class="detail-section">
                            <button class="detail-dropdown-toggle" type="button" data-bs-toggle="collapse" data-bs-target="#systemInfoDropdown" aria-expanded="false" aria-controls="systemInfoDropdown">
                                <span class="dropdown-title"><i class="bi bi-clock-history"></i> System Information</span>
                                <i class="bi bi-chevron-down dropdown-chevron"></i>
                            </button>
                            <div class="collapse detail-dropdown-body" id="systemInfoDropdown">
                                <div class="table-responsive detail-section-scroll">
                                    <table class="table table-sm table-borderless mb-0">
                                        <tr><td class="info-label">Created By:</td><td class="info-value"><i class="bi bi-person-fill me-1"></i> ${escapeHtml(createdByName)}</td></tr>
                                        <tr><td class="info-label">Created At:</td><td class="info-value"><i class="bi bi-calendar-plus me-1"></i> ${createdDate}</td></tr>
                                        <tr><td class="info-label">Last Updated:</td><td class="info-value"><i class="bi bi-clock-history me-1"></i> ${updatedDate}</td></tr>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                `;

                if (item.barcode && typeof JsBarcode !== 'undefined') {
                    try {
                        JsBarcode('#itemDetailsBarcodeSvg', String(item.barcode), {
                            format: 'CODE128',
                            lineColor: '#052A47',
                            width: 2,
                            height: 54,
                            displayValue: false,
                            margin: 6
                        });
                    } catch (barcodeError) {
                        console.error('Barcode render error:', barcodeError);
                    }
                }

                currentItemId = id;
                new bootstrap.Modal(document.getElementById('viewItemModal')).show();
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: data.message });
            }
        })
        .catch(error => { 
            Swal.close(); 
            Swal.fire({ icon: 'error', title: 'Error', text: 'An error occurred' });
        });
}

function editFromView() { 
    bootstrap.Modal.getInstance(document.getElementById('viewItemModal')).hide(); 
    setTimeout(() => editItem(currentItemId), 300); 
}


function printItemBarcodeLabels() {
    const item = currentViewItemData || null;

    if (!item || !item.barcode) {
        if (typeof Swal !== 'undefined') {
            Swal.fire({
                icon: 'warning',
                title: 'No Barcode',
                text: 'Wala pang saved barcode ang item na ito.',
                confirmButtonColor: '#047857'
            });
        } else {
            alert('Wala pang saved barcode ang item na ito.');
        }
        return;
    }

    const barcodeValue = String(item.barcode || '').trim();
    const itemName = String(item.item_name || '').trim();
    const itemCode = String(item.item_code || '').trim();
    const labelCount = 30;

    const esc = (value) => String(value || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');

    const labelsHtml = Array.from({ length: labelCount }).map((_, index) => `
        <div class="barcode-label">
            <div class="label-item-name">${esc(itemName)}</div>
            <div class="label-item-code">${esc(itemCode)}</div>
            <svg class="barcode-svg" id="barcodeLabel${index}"></svg>
            <div class="label-barcode-text">${esc(barcodeValue)}</div>
        </div>
    `).join('');

    const printWindow = window.open('', '_blank', 'width=900,height=700');
    if (!printWindow) {
        if (typeof Swal !== 'undefined') {
            Swal.fire({
                icon: 'warning',
                title: 'Popup Blocked',
                text: 'Allow popups first para ma-open ang barcode print preview.',
                confirmButtonColor: '#047857'
            });
        } else {
            alert('Allow popups first para ma-open ang barcode print preview.');
        }
        return;
    }

    printWindow.document.open();
    printWindow.document.write(`
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title>Print Barcode - ${esc(itemCode)}</title>
            <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"><\/script>
            <style>
                @page { size: A4 portrait; margin: 8mm; }
                * { box-sizing: border-box; }
                body { margin: 0; padding: 0; background: #fff; color: #111827; font-family: Arial, sans-serif; }
                .barcode-sheet { display: grid; grid-template-columns: repeat(3, 1fr); gap: 4mm; width: 100%; }
                .barcode-label { height: 25mm; border: 1px dashed #cbd5e1; border-radius: 3mm; padding: 2mm 3mm; display: flex; flex-direction: column; align-items: center; justify-content: center; overflow: hidden; page-break-inside: avoid; }
                .label-item-name { width: 100%; font-size: 8.5pt; font-weight: 700; text-align: center; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; line-height: 1.1; }
                .label-item-code { width: 100%; font-size: 7pt; text-align: center; color: #374151; margin-top: 1mm; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
                .barcode-svg { width: 100%; max-width: 55mm; height: 12mm; margin-top: 1mm; }
                .label-barcode-text { font-size: 7pt; font-weight: 700; letter-spacing: 0.5px; text-align: center; line-height: 1; margin-top: 0.5mm; }
                @media print { body { -webkit-print-color-adjust: exact; print-color-adjust: exact; } .barcode-label { border-color: #d1d5db; } }
            </style>
        </head>
        <body>
            <div class="barcode-sheet">${labelsHtml}</div>
            <script>
                const barcodeValue = ${JSON.stringify(barcodeValue)};
                window.onload = function() {
                    for (let i = 0; i < ${labelCount}; i++) {
                        try {
                            JsBarcode('#barcodeLabel' + i, barcodeValue, {
                                format: 'CODE128',
                                lineColor: '#111827',
                                width: 1.4,
                                height: 34,
                                displayValue: false,
                                margin: 0
                            });
                        } catch (error) {
                            console.error(error);
                        }
                    }
                    setTimeout(function() { window.focus(); window.print(); }, 500);
                };
            <\/script>
        </body>
        </html>
    `);
    printWindow.document.close();
}


let currentEditPricingRows = [];

function normalizePricingKeyValue(value) {
    return String(value || '').trim().toLowerCase();
}

function getPricingCellInfo(pricingRow, unitTypeName, unitTypeId) {
    if (!pricingRow || !pricingRow.prices) return null;
    const prices = pricingRow.prices;
    if (Object.prototype.hasOwnProperty.call(prices, unitTypeName)) {
        return prices[unitTypeName];
    }
    const wantedName = normalizePricingKeyValue(unitTypeName);
    const wantedId = String(unitTypeId || '').trim();
    for (const key of Object.keys(prices)) {
        const info = prices[key];
        if (normalizePricingKeyValue(key) === wantedName) {
            return info;
        }
        if (info && wantedId !== '' && String(info.unit_type_id || '').trim() === wantedId) {
            return info;
        }
    }
    return null;
}

function getPricingCellValue(pricingRow, unitTypeName, unitTypeId) {
    const info = getPricingCellInfo(pricingRow, unitTypeName, unitTypeId);
    if (!info) return '';
    const value = (typeof info === 'object') ? info.unit_price : info;
    if (value === null || typeof value === 'undefined' || value === '') return '';
    const num = Number(value);
    return Number.isFinite(num) ? num.toFixed(2) : String(value);
}

function editItem(id) {
    cleanupModalBackdrops();
    showLoading();
    const formData = new FormData();
    formData.append('action', 'get_item');
    formData.append('item_id', id);
    
    fetch('current_inventory.php', { method: 'POST', body: formData })
        .then(response => response.json())
        .then(data => {
            Swal.close();
            if (data.success) {
                const item = data.item;
                const unitTypes = data.unit_types || [];
                const images = data.images || [];
                const pricingRows = data.pricing_rows || [];
                currentEditPricingRows = pricingRows;
                
                document.getElementById('editItemId').value = item.item_id;
                document.getElementById('editItemCode').value = item.item_code;
                document.getElementById('editItemName').value = item.item_name;
                document.getElementById('editDescription').value = item.description || '';
                document.getElementById('editCategory').value = item.category || '';
                setPrincipalSelectValue(document.getElementById('editPrincipal'), item.principal || '');
                if (document.getElementById('editVolume')) {
                    document.getElementById('editVolume').value = item.volume || '';
                    // Show/hide volume field based on category
                    const editVolumeField = document.getElementById('editVolumeField');
                    if (editVolumeField) {
                        editVolumeField.style.display = (item.category && item.category.toLowerCase() === 'oil') ? 'block' : 'none';
                    }
                }
                if (document.getElementById('editOilType')) {
                    document.getElementById('editOilType').value = item.oil_type || '';
                    // Show/hide oil type field based on category
                    const editOilTypeField = document.getElementById('editOilTypeField');
                    if (editOilTypeField) {
                        editOilTypeField.style.display = (item.category && item.category.toLowerCase() === 'oil') ? 'block' : 'none';
                    }
                }
                                document.getElementById('editReorderLevel').value = item.reorder_level;
                document.getElementById('editStatus').value = item.status;
                
                const editUnitTypesBody = document.getElementById('editUnitTypesBody');
                editUnitTypesBody.innerHTML = '';
                currentUnitTypes = [];
                
                if (unitTypes.length > 0) {
                    unitTypes.forEach((ut, index) => {
                        const rowId = 'editUnitType_' + Date.now() + '_' + index;
                        const isFirstRow = index === 0;
                        const isActive = ut.unit_status === 'active';
                        const row = document.createElement('tr');
                        row.id = rowId;
                        currentUnitTypes.push(ut.unit_type_name);
                        row.innerHTML = `
                            <td><input type="text" class="form-control form-control-sm" placeholder="e.g., Piece, Box, Carton" name="edit_unit_type[]" value="${escapeHtml(ut.unit_type_name)}" required></td>
                            <td><input type="text" class="form-control form-control-sm text-uppercase" placeholder="CS" maxlength="20" name="edit_uom_initial[]" value="${escapeHtml(ut.uom_initial || '')}"></td>
                            <td>
    <div class="input-group barcode-group">
        <input type="text"
            class="form-control form-control-sm uom-barcode-input"
            placeholder="Type or Scan barcode"
            name="edit_barcode[]"
            value="${escapeHtml(ut.barcode || '')}"
            inputmode="numeric"
            autocomplete="off">

        <button type="button"
            class="btn scan-barcode-btn"
            onclick="scanBarcode(this)"
            title="Scan Barcode">
            <i class="bi bi-upc-scan"></i>
        </button>
    </div>
</td>
                            <td><input type="number" class="form-control form-control-sm" placeholder="1" min="1" value="${ut.quantity_smallest_pack || 1}" name="edit_qty_smallest_pack[]"></td>
                            <td><input type="number" class="form-control form-control-sm" placeholder="0" min="0" step="0.01" value="${ut.current_inventory || 0}" name="edit_current_inventory[]"></td>
                            <td><input type="date" class="form-control form-control-sm" name="edit_as_of_date[]" value="${ut.as_of_date || ''}"></td>
                            <td class="text-center"><input type="radio" class="form-check-input edit-default-uom-radio" name="edit_default_uom" value="1" ${ut.is_default_uom ? 'checked' : (isFirstRow ? 'checked' : '')} onchange="handleEditDefaultUOMChange(this)"></td>
                            <td><input type="number" class="form-control form-control-sm" placeholder="0.00" min="0" step="0.01" value="${ut.unit_cost || 0}" name="edit_unit_cost[]"></td>
                            <td><input type="number" class="form-control form-control-sm" placeholder="0.00" min="0" step="0.01" value="${((parseFloat(ut.current_inventory || 0)) * (parseFloat(ut.unit_cost || 0))).toFixed(2)}" name="edit_total_cost[]" readonly></td>
                            <td class="text-center">
                                ${!isFirstRow ? `<button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('tr').remove(); updateEditPricingTableColumns()"><i class="bi bi-trash"></i></button>` : '<span class="text-muted">Default</span>'}
                            </td>
                        `;
                        editUnitTypesBody.appendChild(row);
                        bindUnitInventoryRowEvents(row);
                    });
                } else {
                    addEditUnitTypeRow();
                }
                
                const editPricingBody = document.getElementById('editPricingBody');
                editPricingBody.innerHTML = '';
                
                // If there are existing pricing rows, add them
                if (pricingRows.length > 0) {
                    pricingRows.forEach((pricingRow, idx) => {
                        const pricingRowId = 'editPricing_' + Date.now() + '_' + idx;
                        const row = document.createElement('tr');
                        row.id = pricingRowId;
                        
                        let rowHTML = `
                            <td><input type="date" class="form-control form-control-sm edit-pricing-date" value="${pricingRow.effective_date || ''}"></td>
                            <td>${buildPriceLevelSelectHtml('edit-pricing-level', pricingRow.price_level || 'Standard')}</td>
                        `;
                        
                        unitTypes.forEach((ut) => {
                            const rowUnitPrice = getPricingCellValue(pricingRow, ut.unit_type_name, ut.unit_type_id);
                            rowHTML += `<td><input type="number" class="form-control form-control-sm edit-pricing-input" data-unit-type="${escapeHtml(ut.unit_type_name)}" placeholder="₱0.00" step="0.01" min="0" value="${rowUnitPrice}"></td>`;
                        });
                        
                        rowHTML += `<td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger" onclick="removePricingRow(this)"><i class="bi bi-trash"></i></button></td>`;
                        
                        row.innerHTML = rowHTML;
                        editPricingBody.appendChild(row);
                    });
                } else {
                    // Add default pricing row
                    addEditPricingRow();
                }
                
                const existingImagesContainer = document.getElementById('existingImagesContainer');
                if (images.length > 0) {
                    let imagesHtml = '<label class="form-label">Existing Images:</label><div class="d-flex flex-wrap gap-2">';
                    images.forEach(img => {
                        imagesHtml += `
                            <div class="position-relative" style="width: 80px;">
                                <img src="../uploads/products/${escapeHtml(img.image_path)}" class="img-thumbnail" style="width: 80px; height: 80px; object-fit: cover;">
                                <button type="button" class="btn btn-sm btn-danger position-absolute top-0 end-0 rounded-circle" style="width: 20px; height: 20px; padding: 0; font-size: 10px;" onclick="deleteItemImage(${img.image_id})"><i class="bi bi-x"></i></button>
                            </div>
                        `;
                    });
                    imagesHtml += '</div>';
                    existingImagesContainer.innerHTML = imagesHtml;
                } else {
                    existingImagesContainer.innerHTML = '<p class="text-muted">No existing images</p>';
                }
                
                updateEditPricingTableColumns();
                currentItemId = id;
                new bootstrap.Modal(document.getElementById('editItemModal')).show();
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: data.message });
            }
        })
        .catch(error => { 
            Swal.close(); 
            Swal.fire({ icon: 'error', title: 'Error', text: 'An error occurred' });
        });
}

function addEditUnitTypeRow() {
    const tbody = document.getElementById('editUnitTypesBody');
    const rowId = 'editUnitType_' + Date.now();
    const isFirstRow = tbody.children.length === 0;
    
    const row = document.createElement('tr');
    row.id = rowId;
    row.innerHTML = `
        <td><input type="text" class="form-control form-control-sm" placeholder="e.g., Piece, Box, Carton" name="edit_unit_type[]" required></td>
        <td><input type="text" class="form-control form-control-sm text-uppercase" placeholder="CS" maxlength="20" name="edit_uom_initial[]"></td>
        <td>
    <div class="input-group barcode-group">
        <input type="text"
            class="form-control form-control-sm uom-barcode-input"
            placeholder="Type or Scan barcode"
            name="edit_barcode[]"
            inputmode="numeric"
            autocomplete="off">

        <button
            type="button"
            class="btn scan-barcode-btn"
            onclick="scanBarcode(this)"
            title="Scan Barcode">

            <i class="bi bi-upc-scan"></i>

        </button>
    </div>
</td>
        <td><input type="number" class="form-control form-control-sm" placeholder="1" min="1" value="1" name="edit_qty_smallest_pack[]"></td>
        <td><input type="number" class="form-control form-control-sm" placeholder="0" min="0" step="0.01" value="0" name="edit_current_inventory[]"></td>
        <td><input type="date" class="form-control form-control-sm" name="edit_as_of_date[]"></td>
        <td class="text-center"><input type="radio" class="form-check-input edit-default-uom-radio" name="edit_default_uom" value="1" ${isFirstRow ? 'checked' : ''} onchange="handleEditDefaultUOMChange(this)"></td>
        <td><input type="number" class="form-control form-control-sm" placeholder="0.00" min="0" step="0.01" value="0" name="edit_unit_cost[]"></td>
        <td><input type="number" class="form-control form-control-sm" placeholder="0.00" min="0" step="0.01" value="0.00" name="edit_total_cost[]" readonly></td>
        <td class="text-center">
            ${!isFirstRow ? `<button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('tr').remove(); updateEditPricingTableColumns()"><i class="bi bi-trash"></i></button>` : '<span class="text-muted">Default</span>'}
        </td>
    `;
    tbody.appendChild(row);
    bindUnitInventoryRowEvents(row);
    
    const unitTypeInput = row.querySelector('input[name="edit_unit_type[]"]');
    if (unitTypeInput) {
        unitTypeInput.addEventListener('change', () => updateEditPricingTableColumns());
        unitTypeInput.addEventListener('keyup', () => updateEditPricingTableColumns());
    }
    
    updateEditPricingTableColumns();
}

// Handle Edit Default UoM radio button - only one can be checked at a time
function handleEditDefaultUOMChange(radio) {
    if (radio.checked) {
        // Uncheck all other edit_default_uom radio buttons in the table
        const allRadios = document.querySelectorAll('input[name="edit_default_uom"]');
        allRadios.forEach(r => {
            if (r !== radio) {
                r.checked = false;
            }
        });
    }
}

function updateEditUnitTypeStatus(checkbox) { return true; }

function updateEditPricingTableColumns() {
    const unitTypeRows = document.querySelectorAll('#editUnitTypesBody tr');
    const unitTypes = [];
    
    unitTypeRows.forEach(row => {
        const unitTypeInput = row.querySelector('input[name="edit_unit_type[]"]');
        if (unitTypeInput && unitTypeInput.value.trim()) {
            unitTypes.push(unitTypeInput.value.trim());
        }
    });
    
    currentUnitTypes = unitTypes;
    
    const pricingTableHead = document.getElementById('editPricingTableHead');
    if (!pricingTableHead) return;
    
    const headerRow = pricingTableHead.querySelector('tr');
    if (!headerRow) return;

    // Keep the existing price values before rebuilding the dynamic UoM columns.
    // This fixes the issue where prices become 0.00 after opening/editing an item.
    const preservedPrices = new Map();
    document.querySelectorAll('#editPricingBody tr').forEach((row, rowIndex) => {
        row.querySelectorAll('.edit-pricing-input').forEach(input => {
            const unitType = input.dataset.unitType || '';
            preservedPrices.set(rowIndex + '||' + unitType, input.value);
        });
    });
    
    const thElements = Array.from(headerRow.querySelectorAll('th'));
    for (let i = thElements.length - 1; i >= 2; i--) {
        if (i < thElements.length - 1) {
            thElements[i].remove();
        }
    }
    
    const actionTh = headerRow.querySelector('th:last-child');
    unitTypes.forEach((unitType) => {
        const th = document.createElement('th');
        th.style.width = '15%';
        th.textContent = unitType;
        headerRow.insertBefore(th, actionTh);
    });
    
    const pricingRows = document.querySelectorAll('#editPricingBody tr');
    pricingRows.forEach((row, rowIndex) => {
        const cells = Array.from(row.querySelectorAll('td'));
        for (let i = cells.length - 2; i >= 2; i--) {
            cells[i].remove();
        }
        
        unitTypes.forEach((unitType) => {
            const td = document.createElement('td');
            const input = document.createElement('input');
            input.type = 'number';
            input.className = 'form-control form-control-sm edit-pricing-input';
            input.dataset.unitType = unitType;
            input.placeholder = `₱0.00 (${unitType})`;
            input.step = '0.01';
            input.min = '0';
            const preservedValue = preservedPrices.get(rowIndex + '||' + unitType);
            if (typeof preservedValue !== 'undefined' && preservedValue !== '') {
                input.value = preservedValue;
            } else if (Array.isArray(currentEditPricingRows) && currentEditPricingRows[rowIndex]) {
                const fallbackValue = getPricingCellValue(currentEditPricingRows[rowIndex], unitType, '');
                if (fallbackValue !== '') {
                    input.value = fallbackValue;
                }
            }
            td.appendChild(input);
            row.insertBefore(td, cells[cells.length - 1]);
        });
    });
}

function addEditPricingRow() {
    const tbody = document.getElementById('editPricingBody');
    const unitTypes = currentUnitTypes.length > 0 ? currentUnitTypes : ['Piece'];
    
    const rowId = 'editPricing_' + Date.now();
    const row = document.createElement('tr');
    row.id = rowId;
    
    let rowHTML = `
        <td><input type="date" class="form-control form-control-sm edit-pricing-date"></td>
        <td>${buildPriceLevelSelectHtml('edit-pricing-level', 'Standard')}</td>
    `;
    
    unitTypes.forEach((unitType) => {
        rowHTML += `<td><input type="number" class="form-control form-control-sm edit-pricing-input" data-unit-type="${unitType}" placeholder="₱0.00" step="0.01" min="0"></td>`;
    });
    
    rowHTML += `<td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger" onclick="removePricingRow(this)"><i class="bi bi-trash"></i></button></td>`;
    
    row.innerHTML = rowHTML;
    tbody.appendChild(row);
}

function collectEditUnitTypesAndPricing() {
    const unitTypeRows = document.querySelectorAll('#editUnitTypesBody tr');
    const unitTypesData = [];
    
    unitTypeRows.forEach(row => {
        const unitTypeInput = row.querySelector('input[name="edit_unit_type[]"]');
        const initialInput = row.querySelector('input[name="edit_uom_initial[]"]');
        const barcodeInput = row.querySelector('input[name="edit_barcode[]"]');
        const qtyInput = row.querySelector('input[name="edit_qty_smallest_pack[]"]');
        const currentInventoryInput = row.querySelector('input[name="edit_current_inventory[]"]');
        const asOfDateInput = row.querySelector('input[name="edit_as_of_date[]"]');
        const defaultRadio = row.querySelector('input[name="edit_default_uom"]');
        const unitCostInput = row.querySelector('input[name="edit_unit_cost[]"]');
        
        if (unitTypeInput && unitTypeInput.value.trim()) {
            unitTypesData.push({
                unit_type: unitTypeInput.value.trim(),
                uom_initial: initialInput ? initialInput.value.trim().toUpperCase() : '',
                barcode: barcodeInput ? barcodeInput.value.trim() : '',
                qty_smallest_pack: qtyInput ? parseInt(qtyInput.value) || 1 : 1,
                current_inventory: currentInventoryInput ? parseFloat(currentInventoryInput.value) || 0 : 0,
                as_of_date: asOfDateInput ? asOfDateInput.value : '',
                default_uom: defaultRadio ? (defaultRadio.checked ? 1 : 0) : 0,
                unit_cost: unitCostInput ? parseFloat(unitCostInput.value) || 0 : 0,
                status: 'active',
                unit_price: 0
            });
        }
    });
    
    const pricingRows = document.querySelectorAll('#editPricingBody tr');
    const pricingData = [];
    const seenPriceRows = new Set();
    
    pricingRows.forEach((row, rowIndex) => {
        const dateInput = row.querySelector('.edit-pricing-date');
        const levelInput = row.querySelector('.edit-pricing-level');
        const originalPricingRow = Array.isArray(currentEditPricingRows) ? (currentEditPricingRows[rowIndex] || {}) : {};
        const effectiveDate = dateInput ? dateInput.value : (originalPricingRow.effective_date || '');
        const priceLevel = levelInput ? (levelInput.value.trim() || originalPricingRow.price_level || 'Standard') : (originalPricingRow.price_level || 'Standard');
        const priceInputs = row.querySelectorAll('.edit-pricing-input');
        
        const priceRow = {
            effective_date: effectiveDate || null,
            price_level: priceLevel || 'Standard',
            prices: {}
        };
        
        priceInputs.forEach((input) => {
            const inputUnitType = (input.dataset.unitType || '').trim();
            if (!inputUnitType) return;

            if (input.value.trim() !== '') {
                priceRow.prices[inputUnitType] = parseFloat(input.value) || 0;
                return;
            }

            const fallbackValue = getPricingCellValue(originalPricingRow, inputUnitType, '');
            if (fallbackValue !== '') {
                priceRow.prices[inputUnitType] = parseFloat(fallbackValue) || 0;
            }
        });
        
        const priceRowKey = (priceRow.effective_date || '') + '||' + priceRow.price_level;
        if (Object.keys(priceRow.prices).length > 0 && !seenPriceRows.has(priceRowKey)) {
            seenPriceRows.add(priceRowKey);
            pricingData.push(priceRow);
        }
    });
    
    if (pricingData.length > 0 && unitTypesData.length > 0) {
        const standardRow = pricingData.find(row => (row.price_level || '').toLowerCase() === 'standard') || pricingData[0];
        unitTypesData.forEach(ut => {
            ut.unit_price = standardRow.prices[ut.unit_type] || 0;
        });
    }
    
    return { unitTypesData, pricingData };
}

function updateItem() {
    const itemId = document.getElementById('editItemId').value;
    const itemName = document.getElementById('editItemName').value.trim();
    const category = document.getElementById('editCategory').value;
    const principal = normalizePrincipalValue(document.getElementById('editPrincipal') ? document.getElementById('editPrincipal').value : '');
    const description = document.getElementById('editDescription').value;
        const reorderLevel = document.getElementById('editReorderLevel').value;
    const status = document.getElementById('editStatus').value;
    
    if (!itemName || !reorderLevel) {
        Swal.fire({ icon: 'warning', title: 'Required Fields', text: 'Please fill in all required fields' });
        return;
    }
    
    const { unitTypesData, pricingData } = collectEditUnitTypesAndPricing();
    
    if (unitTypesData.length === 0) {
        Swal.fire({ icon: 'warning', title: 'Unit Types Required', text: 'Please add at least one unit type' });
        return;
    }
    
    let hasPrice = false;
    for (const ut of unitTypesData) {
        if (ut.unit_price > 0) {
            hasPrice = true;
            break;
        }
    }
    
    if (!hasPrice) {
        Swal.fire({ icon: 'warning', title: 'Pricing Required', text: 'Please set at least one price for a unit type' });
        return;
    }
    
    showLoading();
    
    // Barcode is optional. If typed in any Edit UoM barcode field, save it to items.barcode.
    const barcode = (unitTypesData.find(ut => (ut.barcode || '').trim() !== '')?.barcode || '').trim();

    const formData = new FormData();
    formData.append('action', 'update_item');
    formData.append('item_id', itemId);
    const editItemCodeEl = document.getElementById('editItemCode');
    formData.append('item_code', editItemCodeEl ? editItemCodeEl.value.trim() : '');
    formData.append('barcode', barcode);
    formData.append('item_name', itemName);
    formData.append('description', description);
    formData.append('category', category);
    formData.append('principal', principal);
    const volume = document.getElementById('editVolume') ? document.getElementById('editVolume').value.trim() : '';
    formData.append('volume', volume);
    const oilType = document.getElementById('editOilType') ? document.getElementById('editOilType').value.trim() : '';
    formData.append('oil_type', oilType);
    formData.append('reorder_level', reorderLevel);
    formData.append('status', status);
    formData.append('unit_types', JSON.stringify(unitTypesData));
    formData.append('pricing', JSON.stringify(pricingData));
    
    const imagesInput = document.getElementById('editItemImages');
    if (imagesInput && imagesInput.files.length > 0) {
        for (let i = 0; i < imagesInput.files.length; i++) {
            formData.append('editItemImages[]', imagesInput.files[i]);
        }
    }
    
    fetch('current_inventory.php', { method: 'POST', body: formData })
        .then(response => response.json())
        .then(data => {
            Swal.close();
            if (data.success) {
                Swal.fire({ icon: 'success', title: 'Success!', text: data.message, timer: 2000, showConfirmButton: false })
                    .then(() => {
                        bootstrap.Modal.getInstance(document.getElementById('editItemModal')).hide();
                        location.reload();
                    });
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: data.message });
            }
        })
        .catch(error => {
            Swal.close();
            Swal.fire({ icon: 'error', title: 'Error', text: 'An error occurred: ' + error.message });
        });
}

function deleteItemImage(imageId) {
    Swal.fire({
        title: 'Delete Image?',
        text: 'This action cannot be undone!',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Yes, delete it!'
    }).then((result) => {
        if (result.isConfirmed) {
            showLoading();
            const formData = new FormData();
            formData.append('action', 'delete_item_image');
            formData.append('image_id', imageId);
            
            fetch('current_inventory.php', { method: 'POST', body: formData })
                .then(response => response.json())
                .then(data => {
                    Swal.close();
                    if (data.success) {
                        Swal.fire({ icon: 'success', title: 'Deleted!', text: data.message, timer: 1500, showConfirmButton: false })
                            .then(() => editItem(currentItemId));
                    } else {
                        Swal.fire({ icon: 'error', title: 'Error', text: data.message });
                    }
                })
                .catch(error => {
                    Swal.close();
                    Swal.fire({ icon: 'error', title: 'Error', text: 'An error occurred' });
                });
        }
    });
}

function deleteItem(id) {
    const row = document.querySelector(`.inventory-row[data-id="${id}"]`);
    const itemCode = row ? row.dataset.code : 'this item';
    const itemName = row ? row.dataset.name : 'item';
    
    Swal.fire({
        title: 'Delete Item?',
        html: `
            <div style="text-align: center;">
                <div style="background: #fef2f2; border-radius: 16px; padding: 1rem; margin: 0.5rem 0;">
                    <i class="bi bi-exclamation-triangle-fill" style="font-size: 36px; color: #f59e0b; margin-bottom: 8px; display: block;"></i>
                    <p style="margin: 0; font-weight: 500; color: #1f2937;">${escapeHtml(itemName)}</p>
                    <p style="margin: 4px 0 0 0; font-size: 0.8rem; color: #6c757d;">${escapeHtml(itemCode)}</p>
                </div>
                <p style="margin: 12px 0 0 0; color: #6c757d; font-size: 0.85rem;">
                    <i class="bi bi-info-circle me-1"></i> This action cannot be undone!
                </p>
            </div>
        `,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        cancelButtonColor: '#6c757d',
        confirmButtonText: '<i class="bi bi-trash me-1"></i> Yes, Delete',
        cancelButtonText: '<i class="bi bi-x-circle me-1"></i> Cancel',
        reverseButtons: true,
        width: '450px',
        padding: '1.5rem',
        backdrop: true,
        allowOutsideClick: false,
        allowEscapeKey: true
    }).then((result) => {
        if (result.isConfirmed) {
            showLoading();
            const formData = new FormData();
            formData.append('action', 'delete_item');
            formData.append('item_id', id);
            
            fetch('current_inventory.php', { method: 'POST', body: formData })
                .then(response => response.json())
                .then(data => {
                    Swal.close();
                    if (data.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Deleted!',
                            text: data.message,
                            timer: 2000,
                            showConfirmButton: false,
                            willClose: () => location.reload()
                        });
                    } else {
                        Swal.fire({ icon: 'error', title: 'Error!', text: data.message, confirmButtonColor: '#dc3545' });
                    }
                })
                .catch(error => {
                    Swal.close();
                    Swal.fire({ icon: 'error', title: 'Error!', text: 'An error occurred while deleting the item.', confirmButtonColor: '#dc3545' });
                });
        } else if (result.dismiss === Swal.DismissReason.cancel) {
            Swal.close();
            setTimeout(() => cleanupModalBackdrops(), 100);
        }
    });
}

function showProfileModal(event) {
    if (event) {
        event.preventDefault();
        event.stopPropagation();
    }

    closeAllDropdowns();
    cleanupModalBackdrops();

    const profileModalEl = document.getElementById('profileModal');
    if (profileModalEl && typeof bootstrap !== 'undefined') {
        const profileModal = bootstrap.Modal.getOrCreateInstance(profileModalEl);
        profileModal.show();
    }
}

function confirmLogout() {
    // Close the modal first
    const modal = bootstrap.Modal.getInstance(document.getElementById('profileModal'));
    if (modal) {
        modal.hide();
    }
    
    // Show confirmation dialog
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

function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    const desktopToggleBtn = document.getElementById('desktopToggleBtn');
    
    if (window.innerWidth <= 992) {
        // Mobile behavior
        sidebar.classList.toggle('active');
        let overlay = document.querySelector('.sidebar-overlay');
        if (!overlay) { 
            overlay = document.createElement('div'); 
            overlay.className = 'sidebar-overlay'; 
            document.body.appendChild(overlay); 
            overlay.addEventListener('click', function() { 
                sidebar.classList.remove('active'); 
                overlay.classList.remove('active'); 
                setTimeout(function() { overlay.remove(); }, 300); 
            }); 
        }
        setTimeout(function() { overlay.classList.add('active'); }, 10);
    } else {
        // Desktop behavior - toggle collapse
        const wasCollapsed = sidebar.classList.contains('collapsed');
        sidebar.classList.toggle('collapsed');
        localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
        
        // If expanding (removing collapsed class), reset hover state
        if (wasCollapsed && !sidebar.classList.contains('collapsed')) {
            // Remove any inline styles that might have been set by hover
            sidebar.style.width = '';
        }
    }
}

function setActiveMobileNav() {
    const currentPage = window.location.pathname.split('/').pop();
    document.querySelectorAll('.mobile-nav .nav-link, .dropdown-item').forEach(link => link.classList.remove('active'));
    document.querySelectorAll('.mobile-nav .nav-link, .dropdown-item').forEach(link => { if (link.getAttribute('href') === currentPage) link.classList.add('active'); });
}

function attachMobileCardClick() {
    const rows = document.querySelectorAll('.table-container tbody tr');
    rows.forEach(row => {
        row.removeEventListener('click', handleRowClick);
        row.addEventListener('click', handleRowClick);
    });
}

function handleRowClick(e) {
    // Kung ang click ay galing sa edit/delete button o toggle switch, huwag mag-view
    if (e.target.closest('.btn-action') || e.target.closest('.toggle-switch')) {
        e.stopPropagation();
        return;
    }
    const itemId = this.getAttribute('data-id');
    if (itemId) {
        viewItem(itemId);
    }
}

function showInventoryValueDetails() {
    Swal.fire({
        title: 'Total Inventory Value',
        html: `
            <div style="text-align: left;">
                <p><strong>Total Value:</strong> <?= $statInventoryValue ?></p>
                <p><strong>Total Units:</strong> <?= number_format($total_stock) ?></p>
                <p><strong>Total Items:</strong> <?= $total_items ?></p>
                <hr>
                <p class="text-muted small mb-0">Value calculated using default unit type prices from item_unit_pricing table.</p>
            </div>
        `,
        icon: 'info',
        confirmButtonText: 'OK',
        confirmButtonColor: '#2E7D32',
        width: '400px',
        padding: '1.5rem'
    });
}

function toggleItemCodeEdit() {
    const itemCodeInput = document.getElementById('itemCode');
    const editBtn = document.getElementById('editItemCodeBtn');
    
    if (itemCodeInput.readOnly) {
        itemCodeInput.readOnly = false;
        itemCodeInput.focus();
        editBtn.innerHTML = '<i class="bi bi-check"></i> Done';
        editBtn.classList.add('btn-success');
        editBtn.classList.remove('btn-outline-secondary');
    } else {
        itemCodeInput.readOnly = true;
        editBtn.innerHTML = '<i class="bi bi-pencil"></i> Edit';
        editBtn.classList.remove('btn-success');
        editBtn.classList.add('btn-outline-secondary');
    }
}

// Global variable for toast and timeout
let stockAlertToast = null;
let autoHideTimeout = null;

function showStockAlertToast(lowStockCount, outOfStockCount) {
    const toastElement = document.getElementById('stockAlertToast');
    if (!toastElement) return;
    
    // Clear any existing timeout to prevent conflicts
    if (autoHideTimeout) {
        clearTimeout(autoHideTimeout);
        autoHideTimeout = null;
    }
    
    const titleElement = document.getElementById('toastTitle');
    const messageElement = document.getElementById('toastMessage');
    
    // Remove hiding class and reset classes
    toastElement.classList.remove('hiding');
    toastElement.classList.remove('out-of-stock');
    
    // Set title and message based on stock status
    if (outOfStockCount > 0) {
        titleElement.innerHTML = 'Out of Stock Alert';
        toastElement.classList.add('out-of-stock');
        if (lowStockCount > 0) {
            messageElement.innerHTML = '<strong>' + outOfStockCount + ' item(s)</strong> are out of stock. <strong>' + lowStockCount + ' item(s)</strong> low stock.';
        } else {
            messageElement.innerHTML = '<strong>' + outOfStockCount + ' item(s)</strong> are out of stock.';
        }
    } else if (lowStockCount > 0) {
        titleElement.innerHTML = 'Low Stock Alert';
        messageElement.innerHTML = '<strong>' + lowStockCount + ' item(s)</strong> are running low on stock.';
    } else {
        titleElement.innerHTML = 'Stock Alert';
        messageElement.innerHTML = '<strong>' + (lowStockCount + outOfStockCount) + ' item(s)</strong> need attention.';
    }
    
    // Show toast
    toastElement.style.display = 'block';
    
    // Auto-hide after 5 seconds
    autoHideTimeout = setTimeout(function() {
        closeStockAlertToast();
    }, 5000);
}

function closeStockAlertToast() {
    const toastElement = document.getElementById('stockAlertToast');
    if (!toastElement) return;
    
    // Clear timeout if exists
    if (autoHideTimeout) {
        clearTimeout(autoHideTimeout);
        autoHideTimeout = null;
    }
    
    toastElement.classList.add('hiding');
    
    setTimeout(function() {
        toastElement.style.display = 'none';
        toastElement.classList.remove('hiding');
    }, 250);
}
// ========== DOCUMENT READY ==========
document.addEventListener('DOMContentLoaded', function() {
    // FIXED: Listen for inventory updates from other windows/tabs (after order placement)
    try {
        const channel = new BroadcastChannel('inventory_update');
        channel.addEventListener('message', (event) => {
            if (event.data.action === 'reload_inventory') {
                console.log('[v0] Inventory update received, reloading...');
                location.reload();
            }
        });
    } catch (e) {
        // BroadcastChannel not supported, use localStorage fallback
        let lastUpdate = localStorage.getItem('inventory_update_timestamp');
        window.addEventListener('storage', (event) => {
            if (event.key === 'inventory_update_timestamp' && event.newValue !== lastUpdate) {
                lastUpdate = event.newValue;
                console.log('[v0] Inventory update signal received, reloading...');
                location.reload();
            }
        });
    }
    
    // Load all unit types for items in the table
    setTimeout(() => {
        loadAllItemUnitTypes();
    }, 500);
    
    const sidebar = document.getElementById('sidebar');
    if (window.innerWidth > 992 && localStorage.getItem('sidebarCollapsed') === 'true') sidebar.classList.add('collapsed');
    
    const mobileToggleBtn = document.getElementById('mobileToggleBtn');
    const desktopToggleBtn = document.getElementById('desktopToggleBtn');
    
    if (mobileToggleBtn) mobileToggleBtn.addEventListener('click', toggleSidebar);
    if (desktopToggleBtn) desktopToggleBtn.addEventListener('click', toggleSidebar);
    
    document.addEventListener('click', function(e) {
        if (window.innerWidth <= 992 && sidebar && sidebar.classList.contains('active') && !sidebar.contains(e.target) && mobileToggleBtn && !mobileToggleBtn.contains(e.target)) {
            sidebar.classList.remove('active');
            const overlay = document.querySelector('.sidebar-overlay');
            if (overlay) overlay.remove();
        }
    });
    
    window.addEventListener('resize', function() {
        if (window.innerWidth > 992 && sidebar) {
            sidebar.classList.remove('active');
            const overlay = document.querySelector('.sidebar-overlay');
            if (overlay) overlay.remove();
        } else if (window.innerWidth <= 992 && sidebar) {
            sidebar.classList.remove('collapsed');
        }
        attachMobileCardClick();
    });
    
    setActiveMobileNav();
    initFilterToggle();
    
    const modals = ['profileModal', 'supplierSelectorModal', 'lowStockModal', 'offtakeModal', 'itemModal', 'editItemModal', 'viewItemModal'];
    modals.forEach(modalId => {
        fixModalBackdrop(modalId);
    });
    
    document.addEventListener('click', function() {
        setTimeout(function() {
            const backdrops = document.querySelectorAll('.modal-backdrop');
            if (backdrops.length > 1) {
                for (let i = 1; i < backdrops.length; i++) {
                    backdrops[i].remove();
                }
            }
        }, 300);
    });
    
    const searchInput = document.getElementById('searchInput');
    const statusFilter = document.getElementById('statusFilter');
    const stockFilter = document.getElementById('stockFilter');
    const principalFilter = document.getElementById('principalFilter');
    
    if (searchInput) {
        searchInput.removeEventListener('keyup', filterItems);
        searchInput.removeEventListener('input', filterItems);
        searchInput.addEventListener('input', filterItems);
    }
    if (statusFilter) {
        statusFilter.removeEventListener('change', filterItems);
        statusFilter.addEventListener('change', filterItems);
    }
    if (stockFilter) {
        stockFilter.removeEventListener('change', filterItems);
        stockFilter.addEventListener('change', filterItems);
    }
    if (principalFilter) {
        principalFilter.removeEventListener('change', filterItems);
        principalFilter.addEventListener('change', filterItems);
    }
    
    attachMobileCardClick();
    
    setTimeout(() => {
        filterItems();
    }, 100);
    
   // ========== STOCK ALERT - LALABAS EVERY REFRESH (NO AUTO CLOSE) ==========
const lowStockCount = <?php echo $low_stock_count; ?>;
const outOfStockCount = <?php echo $out_of_stock; ?>;

if (lowStockCount > 0 || outOfStockCount > 0) {
    // Show alert immediately (no delay, no auto-close)
    showStockAlertToast(lowStockCount, outOfStockCount);
}
});

if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
    const originalHide = bootstrap.Modal.prototype.hide;
    bootstrap.Modal.prototype.hide = function() {
        const result = originalHide.apply(this, arguments);
        setTimeout(() => {
            cleanupModalBackdrops();
        }, 150);
        return result;
    };
}

window.updateInventoryFromSales = function(itemId, quantity, soId) {
    showLoading();
    const formData = new FormData();
    formData.append('action', 'update_stock_from_sales');
    formData.append('item_id', itemId);
    formData.append('quantity', quantity);
    formData.append('so_id', soId);
    fetch('current_inventory.php', { method: 'POST', body: formData }).then(r => r.json()).then(d => { Swal.close(); if (!d.success) console.error(d.message); }).catch(e => { Swal.close(); console.error(e); });
};

// Function to add category to datalist dynamically
function addCategoryToDatalist(categoryName) {
    if (!categoryName || categoryName.trim() === '') return;
    
    const categoryName_trimmed = categoryName.trim();
    
    // Add to both datalists (Add and Edit modals)
    const datalists = ['categoryList', 'editCategoryList'];
    datalists.forEach(listId => {
        const datalist = document.getElementById(listId);
        if (datalist) {
            // Check if option already exists
            const existingOption = Array.from(datalist.options).find(opt => opt.value.toLowerCase() === categoryName_trimmed.toLowerCase());
            if (!existingOption) {
                const newOption = document.createElement('option');
                newOption.value = categoryName_trimmed;
                datalist.appendChild(newOption);
            }
        }
    });
}

// Load item images for table thumbnails
function loadItemImages(itemId) {
    return new Promise((resolve) => {
        const formData = new FormData();
        formData.append('action', 'get_item');
        formData.append('item_id', itemId);
        
        fetch(window.location.href, { method: 'POST', body: formData })
            .then(response => response.json())
            .then(data => {
                if (data.success && data.images && data.images.length > 0) {
                    const primaryImage = data.images.find(img => img.is_primary) || data.images[0];
                    const thumbnail = document.querySelector(`.item-thumbnail[data-item-id="${itemId}"]`);
                    if (thumbnail) {
                        thumbnail.innerHTML = `<img src="../uploads/products/${primaryImage.image_path}" alt="Product" style="width: 40px; height: 40px; object-fit: cover; border-radius: 4px;" onerror="this.style.display='none';">`;
                    }
                }
                resolve();
            })
            .catch(() => {
                resolve();
            });
    });
}

// Load all item images
function loadAllItemImages() {
    const thumbnails = document.querySelectorAll('.item-thumbnail[data-item-id]');
    const promises = Array.from(thumbnails).map(thumb => {
        const itemId = thumb.getAttribute('data-item-id');
        return loadItemImages(itemId);
    });
    return Promise.all(promises);
}

// Monitor category input field for blur event to add new categories
document.addEventListener('DOMContentLoaded', function() {
    const categoryInput = document.getElementById('category');
    if (categoryInput) {
        categoryInput.addEventListener('blur', function() {
            const value = this.value.trim();
            if (value && value !== '') {
                addCategoryToDatalist(value);
            }
        });
        // Show/hide volume and oil type fields when category changes
        categoryInput.addEventListener('input', function() {
            const isOil = this.value.toLowerCase() === 'oil';
            const volumeField = document.getElementById('volumeField');
            const oilTypeField = document.getElementById('oilTypeField');
            if (volumeField) volumeField.style.display = isOil ? 'block' : 'none';
            if (oilTypeField) oilTypeField.style.display = isOil ? 'block' : 'none';
        });
    }
    
    // Also monitor the edit category field
    const editCategoryInput = document.getElementById('editCategory');
    if (editCategoryInput) {
        editCategoryInput.addEventListener('blur', function() {
            const value = this.value.trim();
            if (value && value !== '') {
                addCategoryToDatalist(value);
            }
        });
        // Show/hide volume and oil type fields when category changes
        editCategoryInput.addEventListener('input', function() {
            const isOil = this.value.toLowerCase() === 'oil';
            const editVolumeField = document.getElementById('editVolumeField');
            const editOilTypeField = document.getElementById('editOilTypeField');
            if (editVolumeField) editVolumeField.style.display = isOil ? 'block' : 'none';
            if (editOilTypeField) editOilTypeField.style.display = isOil ? 'block' : 'none';
        });
    }
    
    // Load all item images for table thumbnails
    loadAllItemImages();
});

// ========== SIDEBAR DROPDOWN HANDLING ==========

// Set active state for current page and highlight parent dropdown
function setActiveSidebarItem() {
    const currentPage = window.location.pathname.split('/').pop();
    
    // Remove active class from all nav links
    document.querySelectorAll('.sidebar .nav-link').forEach(link => {
        link.classList.remove('active');
    });
    
    // Find and activate the matching link
    document.querySelectorAll('.sidebar .nav-link').forEach(link => {
        const href = link.getAttribute('href');
        if (href === currentPage) {
            link.classList.add('active');
            
            // If this link is inside a dropdown, expand the dropdown and mark parent as active
            const collapseDiv = link.closest('.collapse');
            if (collapseDiv) {
                const dropdownToggle = document.querySelector(`[data-bs-target="#${collapseDiv.id}"]`);
                if (dropdownToggle) {
                    // Expand the collapse
                    const bsCollapse = new bootstrap.Collapse(collapseDiv, { toggle: false });
                    bsCollapse.show();
                    dropdownToggle.setAttribute('aria-expanded', 'true');
                    dropdownToggle.classList.add('active-parent');
                }
            }
        }
    });
}

// Handle sidebar dropdown toggles to prevent closing when clicking inside
document.addEventListener('DOMContentLoaded', function() {
    // Initialize sidebar dropdowns
    const dropdownToggles = document.querySelectorAll('.sidebar .dropdown-toggle');
    dropdownToggles.forEach(toggle => {
        toggle.addEventListener('click', function(e) {
            e.preventDefault();
            const targetId = this.getAttribute('data-bs-target');
            const target = document.querySelector(targetId);
            if (target) {
                const bsCollapse = bootstrap.Collapse.getOrCreateInstance(target);
                bsCollapse.toggle();
                const expanded = this.getAttribute('aria-expanded') === 'true';
                this.setAttribute('aria-expanded', !expanded);
            }
        });
    });
    
    // Set active sidebar item based on current page
    setActiveSidebarItem();
    
    // Handle sidebar collapse state with dropdowns
    const sidebar = document.getElementById('sidebar');
    if (sidebar && window.innerWidth > 992) {
        if (localStorage.getItem('sidebarCollapsed') === 'true') {
            sidebar.classList.add('collapsed');
        }
    }
    
    // When sidebar collapses, ensure dropdowns are closed
    const desktopToggleBtn = document.getElementById('desktopToggleBtn');
    if (desktopToggleBtn) {
        desktopToggleBtn.addEventListener('click', function() {
            setTimeout(() => {
                if (sidebar.classList.contains('collapsed')) {
                    // Close all dropdowns when sidebar collapses
                    document.querySelectorAll('.sidebar .collapse.show').forEach(collapse => {
                        const bsCollapse = bootstrap.Collapse.getInstance(collapse);
                        if (bsCollapse) bsCollapse.hide();
                    });
                }
            }, 300);
        });
    }
});

function toggleSidebarDropdown(event, targetId) {
    event.preventDefault();
    event.stopPropagation();
    
    const target = document.getElementById(targetId);
    const btn = event.currentTarget;
    const arrow = btn.querySelector('.dropdown-arrow');
    const sidebar = document.getElementById('sidebar');
    
    // If sidebar is collapsed, don't open dropdown - just expand sidebar if needed
    if (sidebar.classList.contains('collapsed')) {
        // Expand the sidebar first
        sidebar.classList.remove('collapsed');
        localStorage.setItem('sidebarCollapsed', 'false');
        
        // Small delay to let CSS transition complete, then open dropdown
        setTimeout(() => {
            // Close all other dropdowns first
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
            
            // Open the clicked dropdown
            target.classList.add('show');
            if (arrow) arrow.style.transform = 'translateY(-50%) rotate(180deg)';
        }, 50);
        return;
    }
    
    // Normal behavior when sidebar is already expanded
    if (target.classList.contains('show')) {
        target.classList.remove('show');
        if (arrow) arrow.style.transform = 'translateY(-50%) rotate(0deg)';
    } else {
        // Close all other open dropdowns
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

// ========== SIDEBAR HOVER TO EXPAND - STAY AFTER CLICK ==========
let isSidebarPinned = false;

function initSidebarHoverBehavior() {
    const sidebar = document.getElementById('sidebar');
    if (!sidebar) return;
    
    // Only apply on desktop (above 992px)
    if (window.innerWidth <= 992) return;
    
    // When mouse leaves sidebar, collapse unless pinned
    sidebar.addEventListener('mouseleave', function() {
        if (!isSidebarPinned && sidebar.classList.contains('collapsed')) {
            // Remove any inline styles and let CSS handle the rest
            sidebar.style.width = '';
        }
    });
    
    // Handle clicks inside sidebar when collapsed
    sidebar.addEventListener('click', function(e) {
        // Check if click is on a nav-link or inside dropdown
        const navLink = e.target.closest('.nav-link');
        const isDropdownToggle = e.target.closest('.dropdown-nav .nav-link');
        
        if (navLink && sidebar.classList.contains('collapsed')) {
            // Pin the sidebar to stay expanded after click
            isSidebarPinned = true;
            
            // Remove collapsed class to keep it permanently expanded
            sidebar.classList.remove('collapsed');
            localStorage.setItem('sidebarCollapsed', 'false');
            
            // If it's a dropdown toggle, we need to handle it after expansion
            if (isDropdownToggle) {
                // Wait for CSS transition then trigger the dropdown
                setTimeout(() => {
                    const onClickAttr = navLink.getAttribute('onclick');
                    if (onClickAttr && onClickAttr.includes('toggleSidebarDropdown')) {
                        // Extract the targetId from the onclick
                        const match = onClickAttr.match(/toggleSidebarDropdown\(event,\s*['"]([^'"]+)['"]\)/);
                        if (match && match[1]) {
                            // Trigger the dropdown manually
                            toggleSidebarDropdown(e, match[1]);
                        }
                    }
                }, 200);
            }
        }
    });
    
    // Reset pin when manually toggling
    const desktopToggleBtn = document.getElementById('desktopToggleBtn');
    if (desktopToggleBtn) {
        desktopToggleBtn.addEventListener('click', function() {
            isSidebarPinned = false;
        });
    }
}

function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    
    if (window.innerWidth <= 992) {
        // Mobile behavior
        sidebar.classList.toggle('active');
        let overlay = document.querySelector('.sidebar-overlay');
        if (!overlay) { 
            overlay = document.createElement('div'); 
            overlay.className = 'sidebar-overlay'; 
            document.body.appendChild(overlay); 
            overlay.addEventListener('click', function() { 
                sidebar.classList.remove('active'); 
                overlay.classList.remove('active'); 
                setTimeout(function() { overlay.remove(); }, 300); 
            }); 
        }
        setTimeout(function() { overlay.classList.add('active'); }, 10);
    } else {
        // Desktop behavior - simple toggle collapse
        sidebar.classList.toggle('collapsed');
        localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
        
        // If expanding, reset any hover state
        if (!sidebar.classList.contains('collapsed')) {
            sidebar.style.width = '';
        }
    }
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    initSidebarHoverBehavior();
    
    // Restore sidebar state from localStorage
    const sidebar = document.getElementById('sidebar');
    if (sidebar && window.innerWidth > 992) {
        const isCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
        if (isCollapsed) {
            sidebar.classList.add('collapsed');
        } else {
            sidebar.classList.remove('collapsed');
            isSidebarPinned = true; // If expanded by default, consider it pinned
        }
    }
});

function setActiveSidebarItem() {
    const currentPage = window.location.pathname.split('/').pop();
    
    // Remove active class from all nav links
    document.querySelectorAll('.sidebar .nav-link').forEach(link => {
        link.classList.remove('active');
    });
    
    // Find and activate the matching link (ONLY the actual page link)
    document.querySelectorAll('.sidebar .nav-link').forEach(link => {
        const href = link.getAttribute('href');
        if (href === currentPage) {
            link.classList.add('active');
            
            // Open parent dropdown if exists (for expanded sidebar only)
            const collapseDiv = link.closest('.collapse');
            const sidebar = document.getElementById('sidebar');
            if (collapseDiv && sidebar && !sidebar.classList.contains('collapsed')) {
                collapseDiv.classList.add('show');
                const parentBtn = document.querySelector(`[onclick*="${collapseDiv.id}"]`);
                if (parentBtn) {
                    const arrow = parentBtn.querySelector('.dropdown-arrow');
                    if (arrow) arrow.style.transform = 'translateY(-50%) rotate(180deg)';
                }
            }
        }
    });
    
    // NEVER add active class to dropdown parent links when sidebar is expanded
    const sidebar = document.getElementById('sidebar');
    if (sidebar && !sidebar.classList.contains('collapsed')) {
        document.querySelectorAll('.sidebar .dropdown-nav > .nav-link').forEach(link => {
            link.classList.remove('active');
        });
    }
}

// Initialize sidebar
document.addEventListener('DOMContentLoaded', function() {
    setActiveSidebarItem();
    
    const sidebar = document.getElementById('sidebar');
    const desktopToggleBtn = document.getElementById('desktopToggleBtn');
    
    // Restore sidebar state from localStorage
    if (sidebar && window.innerWidth > 992) {
        const isCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
        if (isCollapsed) {
            sidebar.classList.add('collapsed');
        } else {
            sidebar.classList.remove('collapsed');
        }
    }
    
    // Handle desktop toggle button
    if (desktopToggleBtn) {
        desktopToggleBtn.addEventListener('click', function() {
            setTimeout(() => {
                if (sidebar.classList.contains('collapsed')) {
                    // Close all dropdowns when collapsing
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
    
    // Prevent dropdown from closing when clicking inside it
    document.querySelectorAll('.sidebar .collapse').forEach(collapse => {
        collapse.addEventListener('click', function(e) {
            e.stopPropagation();
        });
    });
});

// Set active sidebar item
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

// Initialize sidebar
document.addEventListener('DOMContentLoaded', function() {
    setActiveSidebarItem();
    
    // Handle sidebar collapse
    const sidebar = document.getElementById('sidebar');
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
            }, 300);
        });
    }
});

// Update setActiveSidebarItem function to work with manual toggle
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
                // Open the parent dropdown
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

// Update active state for dropdown parent when sidebar is collapsed
function updateDropdownParentActiveState() {
    const sidebar = document.getElementById('sidebar');
    if (!sidebar) return;
    
    if (sidebar.classList.contains('collapsed')) {
        // Find all dropdown-nav items that have an active child link
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

// Call this after sidebar toggle and on page load
function updateActiveStates() {
    // First, ensure the current page link has active class
    const currentPage = window.location.pathname.split('/').pop();
    document.querySelectorAll('.sidebar .nav-link').forEach(link => {
        const href = link.getAttribute('href');
        if (href === currentPage) {
            link.classList.add('active');
        }
    });
    
    // Then update parent dropdown active states
    updateDropdownParentActiveState();
}

// Call this when sidebar is toggled
const originalToggleSidebar = toggleSidebar;
window.toggleSidebar = function() {
    originalToggleSidebar();
    setTimeout(updateDropdownParentActiveState, 300);
};

function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    const desktopToggleBtn = document.getElementById('desktopToggleBtn');
    
    if (window.innerWidth <= 992) {
        // Mobile behavior
        sidebar.classList.toggle('active');
        let overlay = document.querySelector('.sidebar-overlay');
        if (!overlay) { 
            overlay = document.createElement('div'); 
            overlay.className = 'sidebar-overlay'; 
            document.body.appendChild(overlay); 
            overlay.addEventListener('click', function() { 
                sidebar.classList.remove('active'); 
                overlay.classList.remove('active'); 
                setTimeout(function() { overlay.remove(); }, 300); 
            }); 
        }
        setTimeout(function() { overlay.classList.add('active'); }, 10);
    } else {
        // Desktop behavior - toggle collapse
        const wasCollapsed = sidebar.classList.contains('collapsed');
        sidebar.classList.toggle('collapsed');
        localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
        
        // If expanding from collapsed state (was collapsed, now not collapsed)
        if (wasCollapsed && !sidebar.classList.contains('collapsed')) {
            // Remove any inline styles that might have been set by hover
            sidebar.style.width = '';
            
            // AFTER expanding, find any active child link and open its parent dropdown
            setTimeout(function() {
                expandActiveDropdownContainers();
            }, 150);
        }
    }
}

// Update active state for dropdown parent when sidebar is collapsed
function updateDropdownParentActiveState() {
    const sidebar = document.getElementById('sidebar');
    if (!sidebar) return;
    
    if (sidebar.classList.contains('collapsed')) {
        // Find all dropdown-nav items that have an active child link
        document.querySelectorAll('.dropdown-nav').forEach(function(dropdownNav) {
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

// Function to expand all dropdown containers that contain active links
function expandActiveDropdownContainers() {
    const sidebar = document.getElementById('sidebar');
    if (!sidebar) return;
    
    // Find all dropdown-nav containers
    const dropdownNavs = document.querySelectorAll('.sidebar .dropdown-nav');
    
    dropdownNavs.forEach(function(dropdownNav) {
        // Check if this dropdown contains any active link
        const activeLink = dropdownNav.querySelector('.nav-link.active');
        
        if (activeLink) {
            // Find the collapse element inside this dropdown
            const collapseDiv = dropdownNav.querySelector('.collapse');
            
            if (collapseDiv && !collapseDiv.classList.contains('show')) {
                // Open the dropdown
                collapseDiv.classList.add('show');
                
                // Rotate the arrow of the parent link
                const parentLink = dropdownNav.querySelector(':scope > .nav-link');
                if (parentLink) {
                    const arrow = parentLink.querySelector('.dropdown-arrow');
                    if (arrow) {
                        arrow.style.transform = 'translateY(-50%) rotate(180deg)';
                    }
                    // Also add active class to parent if sidebar is collapsed
                    if (sidebar.classList.contains('collapsed')) {
                        parentLink.classList.add('active');
                    }
                }
            }
        }
    });
}

function setActiveSidebarItem() {
    const currentPage = window.location.pathname.split('/').pop();
    
    // Remove active class from all nav links
    document.querySelectorAll('.sidebar .nav-link').forEach(link => {
        link.classList.remove('active');
    });
    
    // Find and activate the matching link
    document.querySelectorAll('.sidebar .nav-link').forEach(link => {
        const href = link.getAttribute('href');
        if (href === currentPage) {
            link.classList.add('active');
        }
    });
    
    // Expand dropdown containers that have active links (only if sidebar is expanded)
    const sidebar = document.getElementById('sidebar');
    if (sidebar && !sidebar.classList.contains('collapsed')) {
        expandActiveDropdownContainers();
    }
}
</script>

<datalist id="priceLevelOptions">
    <?php foreach ($priceLevels as $level): ?>
        <option value="<?= htmlspecialchars($level) ?>"></option>
    <?php endforeach; ?>
</datalist>

<script>
window.availablePriceLevels = <?php echo json_encode(array_values($priceLevels)); ?>;
</script>


<?php if (!empty($show_system_task_modal) && !empty($system_tasks)): ?>
<div class="modal fade system-task-table-modal" id="systemTaskTableModal" tabindex="-1" aria-labelledby="systemTaskTableModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="systemTaskTableModalLabel"><i class="bi bi-list-task me-2"></i> Pending System Tasks</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="system-task-alert"><i class="bi bi-exclamation-triangle-fill me-1"></i> These records need action. Click any row to open the correct page and automatically open the selected record.</div>
                <div class="system-task-table-wrap">
                    <table class="table table-hover align-middle system-task-table">
                        <thead><tr><th style="width:20%">Task</th><th style="width:20%">Reference</th><th>Description</th><th style="width:15%">Status</th></tr></thead>
                        <tbody>
                            <?php foreach ($system_tasks as $task): ?>
                            <tr onclick="openSystemTaskRecord('<?php echo htmlspecialchars($task['page'], ENT_QUOTES); ?>','<?php echo htmlspecialchars($task['param'], ENT_QUOTES); ?>','<?php echo (int)$task['id']; ?>')">
                                <td data-label="Task"><span class="system-task-type"><?php echo htmlspecialchars($task['type']); ?></span></td>
                                <td data-label="Reference"><span class="system-task-ref"><?php echo htmlspecialchars($task['reference']); ?></span></td>
                                <td data-label="Description"><div class="system-task-desc"><?php echo htmlspecialchars($task['description']); ?></div></td>
                                <td data-label="Status"><span class="system-task-status"><?php echo htmlspecialchars($task['status']); ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
<script>
function openSystemTaskRecord(page,param,id){
    if(!page||!param||!id) return;
    const sep = page.indexOf('?') === -1 ? '?' : '&';
    window.location.href = page + sep
        + encodeURIComponent(param) + '=' + encodeURIComponent(id)
        + '&auto_open=1&open_record_id=' + encodeURIComponent(id)
        + '&open_param=' + encodeURIComponent(param);
}
document.addEventListener('DOMContentLoaded',function(){<?php if (!empty($show_system_task_modal) && !empty($system_tasks)): ?>const el=document.getElementById('systemTaskTableModal');if(el&&typeof bootstrap!=='undefined'){new bootstrap.Modal(el).show()}<?php endif; ?>});

// Barcode numbers are manually typed by the user and processed only when Save Item is clicked.
// No separate Generate button is needed.
</script>
<script>
let uomBarcodeScanner = null;
let uomBarcodeTargetInput = null;
let uomBarcodeScannerRunning = false;
let uomBarcodeScannerStarting = false;
let uomBarcodeScannerShouldRun = false;
let uomBarcodeScannerStartToken = 0;

function ensureUomBarcodeScannerModal() {
    let existingModal = document.getElementById('uomBarcodeScannerModal');
    if (existingModal) return existingModal;

    const wrapper = document.createElement('div');
    wrapper.innerHTML = `
        <div class="modal fade uom-scanner-modal" id="uomBarcodeScannerModal" tabindex="-1" aria-labelledby="uomBarcodeScannerModalLabel" aria-hidden="true" data-bs-backdrop="true" data-bs-keyboard="true">
            <div class="modal-dialog modal-dialog-centered modal-lg">
                <div class="modal-content" style="border-radius: 14px; overflow: hidden;">
                    <div class="modal-header" style="background: linear-gradient(135deg, #198754, #146c43); color: #fff;">
                        <h5 class="modal-title" id="uomBarcodeScannerModalLabel">
                            <i class="bi bi-upc-scan me-2"></i>Scan UoM Barcode
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div id="uomBarcodeReader" style="width: 100%; min-height: 320px; border: 2px dashed #d1d5db; border-radius: 12px; overflow: hidden; background: #f8fafc;"></div>
                        <div class="small text-muted mt-2">Itapat ang barcode sa camera. Kapag na-scan, automatic na ilalagay sa UoM barcode field.</div>
                    </div>
                </div>
            </div>
        </div>
    `;

    document.body.appendChild(wrapper.firstElementChild);
    const modalEl = document.getElementById('uomBarcodeScannerModal');

    modalEl.addEventListener('show.bs.modal', function () {
        prepareUomScannerModalLayer();
    });

    modalEl.addEventListener('shown.bs.modal', function () {
        prepareUomScannerModalLayer();
    });

    // IMPORTANT: stop camera as soon as the scanner starts closing, not after animation.
    modalEl.addEventListener('hide.bs.modal', function () {
        stopUomBarcodeScanner(true);
    });

    modalEl.addEventListener('hidden.bs.modal', function () {
        stopUomBarcodeScanner(true);
        restoreParentItemModalBackdrop();
    });

    return modalEl;
}

function prepareUomScannerModalLayer() {
    const scannerModal = document.getElementById('uomBarcodeScannerModal');
    if (!scannerModal) return;

    scannerModal.style.zIndex = '1085';

    setTimeout(function () {
        const backdrops = Array.from(document.querySelectorAll('.modal-backdrop'));
        const scannerBackdrop = backdrops[backdrops.length - 1];
        if (scannerBackdrop) {
            scannerBackdrop.classList.add('uom-scanner-backdrop');
            scannerBackdrop.style.zIndex = '1080';
            scannerBackdrop.style.opacity = '0.55';
        }
        document.body.classList.add('modal-open');
    }, 20);
}

function restoreParentItemModalBackdrop() {
    const parentModal = document.querySelector('#itemModal.show, #editItemModal.show');

    // Remove only the scanner backdrop. Do not remove all .modal-backdrop elements,
    // because Add Item/Edit Item still needs its dark background.
    document.querySelectorAll('.modal-backdrop.uom-scanner-backdrop').forEach(function (backdrop) {
        backdrop.remove();
    });

    if (!parentModal) {
        if (!document.querySelector('.modal.show')) {
            document.body.classList.remove('modal-open');
            document.body.style.removeProperty('padding-right');
            document.querySelectorAll('.modal-backdrop').forEach(function (backdrop) {
                backdrop.remove();
            });
        }
        return;
    }

    document.body.classList.add('modal-open');

    let parentBackdrop = document.querySelector('.modal-backdrop:not(.uom-scanner-backdrop)');
    if (!parentBackdrop) {
        parentBackdrop = document.createElement('div');
        parentBackdrop.className = 'modal-backdrop fade show';
        document.body.appendChild(parentBackdrop);
    }

    parentBackdrop.style.zIndex = '1040';
    parentBackdrop.style.opacity = '0.5';
    parentModal.style.zIndex = '1055';
}

function showUomBarcodeScannerMessage(message, type = 'muted') {
    const readerEl = document.getElementById('uomBarcodeReader');
    if (!readerEl) return;
    const alertClass = type === 'danger' ? 'text-danger' : (type === 'success' ? 'text-success' : 'text-muted');
    readerEl.innerHTML = `
        <div class="p-4 text-center ${alertClass}">
            <i class="bi bi-camera-video" style="font-size: 2rem;"></i>
            <div class="mt-2">${message}</div>
        </div>
    `;
}

function scanBarcode(btn) {
    const inputGroup = btn ? btn.closest('.input-group') : null;
    const input = inputGroup ? inputGroup.querySelector('.uom-barcode-input') : null;
    if (!input) return;

    uomBarcodeTargetInput = input;
    const modalEl = ensureUomBarcodeScannerModal();
    const modal = bootstrap.Modal.getOrCreateInstance(modalEl, {
        backdrop: true,
        keyboard: true,
        focus: true
    });

    modalEl.removeEventListener('shown.bs.modal', startUomBarcodeScanner);
    modalEl.addEventListener('shown.bs.modal', startUomBarcodeScanner, { once: true });
    modal.show();
}

function startUomBarcodeScanner() {
    const readerEl = document.getElementById('uomBarcodeReader');
    if (!readerEl || uomBarcodeScannerRunning || uomBarcodeScannerStarting) return;

    uomBarcodeScannerShouldRun = true;
    uomBarcodeScannerStarting = true;
    const startToken = ++uomBarcodeScannerStartToken;

    if (location.protocol !== 'https:' && location.hostname !== 'localhost' && location.hostname !== '127.0.0.1') {
        uomBarcodeScannerStarting = false;
        showUomBarcodeScannerMessage('Camera scanning needs HTTPS. Pwede pa rin i-type manually ang barcode sa UoM field.', 'danger');
        return;
    }

    if (typeof Html5Qrcode === 'undefined') {
        uomBarcodeScannerStarting = false;
        showUomBarcodeScannerMessage('Barcode scanner library did not load. Pwede pa rin i-type manually ang barcode sa UoM field.', 'danger');
        return;
    }

    readerEl.innerHTML = '';

    try {
        if (uomBarcodeScanner) {
            try { uomBarcodeScanner.clear(); } catch (e) {}
        }
        uomBarcodeScanner = new Html5Qrcode('uomBarcodeReader', { verbose: false });
    } catch (error) {
        console.error('UoM scanner init error:', error);
        uomBarcodeScannerStarting = false;
        showUomBarcodeScannerMessage('Hindi ma-start ang scanner. Pwede pa rin i-type manually ang barcode sa UoM field.', 'danger');
        return;
    }

    const formats = typeof getBarcodeScannerFormats === 'function' ? getBarcodeScannerFormats() : undefined;
    const config = {
        fps: 15,
        qrbox: function(viewfinderWidth, viewfinderHeight) {
            const width = Math.floor(viewfinderWidth * 0.92);
            const height = Math.floor(Math.min(viewfinderHeight * 0.45, 220));
            return { width: width, height: height };
        },
        aspectRatio: 1.7777778,
        rememberLastUsedCamera: true
    };
    if (formats && formats.length) config.formatsToSupport = formats;

    Html5Qrcode.getCameras()
        .then(function(cameras) {
            if (!uomBarcodeScannerShouldRun || startToken !== uomBarcodeScannerStartToken) return Promise.reject(new Error('Scanner was closed'));
            if (!cameras || cameras.length === 0) throw new Error('No camera found');
            const backCamera = cameras.find(camera => /back|rear|environment/i.test(camera.label || ''));
            const cameraIdOrConfig = backCamera ? backCamera.id : cameras[cameras.length - 1].id;
            return uomBarcodeScanner.start(cameraIdOrConfig, config, handleUomBarcodeScanned, function() {});
        })
        .then(function() {
            uomBarcodeScannerStarting = false;
            if (!uomBarcodeScannerShouldRun || startToken !== uomBarcodeScannerStartToken) {
                stopUomBarcodeScanner(true);
                return;
            }
            uomBarcodeScannerRunning = true;
        })
        .catch(function(error) {
            if (!uomBarcodeScannerShouldRun || startToken !== uomBarcodeScannerStartToken) {
                uomBarcodeScannerStarting = false;
                stopUomBarcodeScanner(true);
                return;
            }

            console.error('UoM scanner camera error:', error);
            try {
                uomBarcodeScanner = new Html5Qrcode('uomBarcodeReader', { verbose: false });
                uomBarcodeScanner.start({ facingMode: 'environment' }, config, handleUomBarcodeScanned, function() {})
                    .then(function() {
                        uomBarcodeScannerStarting = false;
                        if (!uomBarcodeScannerShouldRun || startToken !== uomBarcodeScannerStartToken) {
                            stopUomBarcodeScanner(true);
                            return;
                        }
                        uomBarcodeScannerRunning = true;
                    })
                    .catch(function(fallbackError) {
                        uomBarcodeScannerStarting = false;
                        console.error('UoM scanner fallback error:', fallbackError);
                        showUomBarcodeScannerMessage('Cannot open camera. Allow camera permission, then try again. Pwede pa rin i-type manually ang barcode sa UoM field.', 'danger');
                    });
            } catch (fallbackInitError) {
                uomBarcodeScannerStarting = false;
                console.error('UoM scanner fallback init error:', fallbackInitError);
                showUomBarcodeScannerMessage('Cannot open camera. Allow camera permission, then try again. Pwede pa rin i-type manually ang barcode sa UoM field.', 'danger');
            }
        });
}

function handleUomBarcodeScanned(decodedText) {
    const cleanBarcode = String(decodedText || '').trim();
    if (!cleanBarcode || !uomBarcodeTargetInput) return;

    uomBarcodeTargetInput.value = cleanBarcode;
    uomBarcodeTargetInput.dispatchEvent(new Event('input', { bubbles: true }));
    uomBarcodeTargetInput.dispatchEvent(new Event('change', { bubbles: true }));

    const modalEl = document.getElementById('uomBarcodeScannerModal');
    const modal = modalEl ? bootstrap.Modal.getOrCreateInstance(modalEl) : null;
    stopUomBarcodeScanner(true);
    if (modal) modal.hide();
}

function stopUomBarcodeScanner(forceStop = false) {
    uomBarcodeScannerShouldRun = false;
    uomBarcodeScannerStarting = false;
    uomBarcodeScannerStartToken++;

    const scanner = uomBarcodeScanner;
    uomBarcodeScanner = null;
    uomBarcodeScannerRunning = false;

    if (!scanner) return Promise.resolve();

    const cleanup = function () {
        try { scanner.clear(); } catch (e) {}
        const readerEl = document.getElementById('uomBarcodeReader');
        if (readerEl && forceStop) readerEl.innerHTML = '';
    };

    try {
        const stopPromise = scanner.stop();
        if (stopPromise && typeof stopPromise.then === 'function') {
            return stopPromise
                .then(function () { cleanup(); })
                .catch(function (error) {
                    console.error('Stop UoM scanner error:', error);
                    cleanup();
                });
        }
        cleanup();
        return Promise.resolve();
    } catch (error) {
        console.error('Stop UoM scanner exception:', error);
        cleanup();
        return Promise.resolve();
    }
}

window.addEventListener('beforeunload', function () {
    stopUomBarcodeScanner(true);
});
</script> 




<style id="amgc-uom-scanner-scroll-final-fix">
/* FINAL FIX: keep Add/Edit Item modal scrollable while nested UoM scanner is opened/closed */
#itemModal .modal-dialog,
#editItemModal .modal-dialog {
    height: calc(100vh - 1rem) !important;
    max-height: calc(100vh - 1rem) !important;
    margin-top: .5rem !important;
    margin-bottom: .5rem !important;
}

@media (min-width: 576px) {
    #itemModal .modal-dialog,
    #editItemModal .modal-dialog {
        height: calc(100vh - 3.5rem) !important;
        max-height: calc(100vh - 3.5rem) !important;
        margin-top: 1.75rem !important;
        margin-bottom: 1.75rem !important;
    }
}

#itemModal .modal-content,
#editItemModal .modal-content {
    height: 100% !important;
    max-height: 100% !important;
    display: flex !important;
    flex-direction: column !important;
    overflow: hidden !important;
}

#itemModal .modal-body,
#editItemModal .modal-body {
    flex: 1 1 auto !important;
    min-height: 0 !important;
    overflow-y: auto !important;
    overflow-x: hidden !important;
    -webkit-overflow-scrolling: touch !important;
}

#itemModal .modal-footer,
#editItemModal .modal-footer {
    flex-shrink: 0 !important;
}

#uomBarcodeScannerModal {
    z-index: 1095 !important;
}

.modal-backdrop.uom-scanner-backdrop {
    z-index: 1090 !important;
    opacity: .62 !important;
}

.modal-backdrop.item-parent-backdrop {
    z-index: 1040 !important;
    opacity: .5 !important;
}

#itemModal.show,
#editItemModal.show {
    z-index: 1055 !important;
}
</style>

<script id="amgc-uom-scanner-camera-backdrop-final-fix">
(function () {
    function getOpenParentItemModal() {
        return document.querySelector('#itemModal.show, #editItemModal.show');
    }

    function forceStopUomVideoTracks() {
        document.querySelectorAll('#uomBarcodeReader video').forEach(function (video) {
            try {
                if (video.srcObject && typeof video.srcObject.getTracks === 'function') {
                    video.srcObject.getTracks().forEach(function (track) {
                        try { track.stop(); } catch (e) {}
                    });
                    video.srcObject = null;
                }
            } catch (e) {}
        });
    }

    function normalizeNestedModalBackdrops() {
        const parentModal = getOpenParentItemModal();
        const scannerModal = document.getElementById('uomBarcodeScannerModal');

        document.querySelectorAll('.modal-backdrop').forEach(function (backdrop) {
            const z = Number.parseInt(backdrop.style.zIndex || window.getComputedStyle(backdrop).zIndex || '0', 10);
            if (backdrop.classList.contains('uom-scanner-backdrop') || z >= 1080) {
                if (!scannerModal || !scannerModal.classList.contains('show')) {
                    backdrop.remove();
                }
            }
        });

        if (parentModal) {
            document.body.classList.add('modal-open');
            document.body.style.overflow = 'hidden';
            parentModal.style.zIndex = '1055';

            let parentBackdrop = Array.from(document.querySelectorAll('.modal-backdrop'))
                .find(function (backdrop) { return !backdrop.classList.contains('uom-scanner-backdrop'); });

            if (!parentBackdrop) {
                parentBackdrop = document.createElement('div');
                parentBackdrop.className = 'modal-backdrop fade show item-parent-backdrop';
                document.body.appendChild(parentBackdrop);
            }

            parentBackdrop.classList.add('item-parent-backdrop');
            parentBackdrop.style.zIndex = '1040';
            parentBackdrop.style.opacity = '0.5';
        } else if (!document.querySelector('.modal.show')) {
            document.body.classList.remove('modal-open');
            document.body.style.removeProperty('overflow');
            document.body.style.removeProperty('padding-right');
            document.querySelectorAll('.modal-backdrop').forEach(function (backdrop) { backdrop.remove(); });
        }
    }

    window.prepareUomScannerModalLayer = function () {
        const scannerModal = document.getElementById('uomBarcodeScannerModal');
        if (!scannerModal) return;

        scannerModal.style.zIndex = '1095';
        document.body.classList.add('modal-open');
        document.body.style.overflow = 'hidden';

        setTimeout(function () {
            const backdrops = Array.from(document.querySelectorAll('.modal-backdrop'));
            let scannerBackdrop = backdrops[backdrops.length - 1];

            if (!scannerBackdrop || scannerBackdrop.classList.contains('item-parent-backdrop')) {
                scannerBackdrop = document.createElement('div');
                scannerBackdrop.className = 'modal-backdrop fade show';
                document.body.appendChild(scannerBackdrop);
            }

            scannerBackdrop.classList.add('uom-scanner-backdrop');
            scannerBackdrop.classList.remove('item-parent-backdrop');
            scannerBackdrop.style.zIndex = '1090';
            scannerBackdrop.style.opacity = '0.62';

            const parentModal = getOpenParentItemModal();
            if (parentModal) parentModal.style.zIndex = '1055';
        }, 30);
    };

    window.restoreParentItemModalBackdrop = function () {
        forceStopUomVideoTracks();

        document.querySelectorAll('.modal-backdrop.uom-scanner-backdrop').forEach(function (backdrop) {
            backdrop.remove();
        });

        setTimeout(normalizeNestedModalBackdrops, 30);
        setTimeout(normalizeNestedModalBackdrops, 160);
    };

    window.stopUomBarcodeScanner = function (forceStop = false) {
        window.uomBarcodeScannerShouldRun = false;
        window.uomBarcodeScannerStarting = false;
        window.uomBarcodeScannerStartToken = (window.uomBarcodeScannerStartToken || 0) + 1;

        const scanner = window.uomBarcodeScanner || (typeof uomBarcodeScanner !== 'undefined' ? uomBarcodeScanner : null);
        try { uomBarcodeScanner = null; } catch (e) { window.uomBarcodeScanner = null; }
        try { uomBarcodeScannerRunning = false; } catch (e) { window.uomBarcodeScannerRunning = false; }
        try { uomBarcodeScannerStarting = false; } catch (e) { window.uomBarcodeScannerStarting = false; }
        try { uomBarcodeScannerShouldRun = false; } catch (e) { window.uomBarcodeScannerShouldRun = false; }

        forceStopUomVideoTracks();

        const cleanup = function () {
            try { if (scanner && typeof scanner.clear === 'function') scanner.clear(); } catch (e) {}
            forceStopUomVideoTracks();
            const readerEl = document.getElementById('uomBarcodeReader');
            if (readerEl && forceStop) readerEl.innerHTML = '';
        };

        if (!scanner) {
            cleanup();
            return Promise.resolve();
        }

        try {
            const state = typeof scanner.getState === 'function' ? String(scanner.getState()) : '';
            if (state && !/SCANNING|PAUSED|2|3/i.test(state)) {
                cleanup();
                return Promise.resolve();
            }
        } catch (e) {}

        try {
            const stopPromise = scanner.stop();
            if (stopPromise && typeof stopPromise.then === 'function') {
                return stopPromise.then(cleanup).catch(function () { cleanup(); });
            }
            cleanup();
            return Promise.resolve();
        } catch (e) {
            cleanup();
            return Promise.resolve();
        }
    };

    window.ensureUomBarcodeScannerModal = function () {
        let existingModal = document.getElementById('uomBarcodeScannerModal');
        if (existingModal) return existingModal;

        const wrapper = document.createElement('div');
        wrapper.innerHTML = `
            <div class="modal fade uom-scanner-modal" id="uomBarcodeScannerModal" tabindex="-1" aria-labelledby="uomBarcodeScannerModalLabel" aria-hidden="true" data-bs-backdrop="true" data-bs-keyboard="true">
                <div class="modal-dialog modal-dialog-centered modal-lg">
                    <div class="modal-content" style="border-radius: 14px; overflow: hidden;">
                        <div class="modal-header" style="background: linear-gradient(135deg, #198754, #146c43); color: #fff;">
                            <h5 class="modal-title" id="uomBarcodeScannerModalLabel">
                                <i class="bi bi-upc-scan me-2"></i>Scan UoM Barcode
                            </h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div id="uomBarcodeReader" style="width: 100%; min-height: 320px; border: 2px dashed #d1d5db; border-radius: 12px; overflow: hidden; background: #f8fafc;"></div>
                            <div class="small text-muted mt-2">Itapat ang barcode sa camera. Kapag na-scan, automatic na ilalagay sa UoM barcode field.</div>
                        </div>
                    </div>
                </div>
            </div>
        `;

        document.body.appendChild(wrapper.firstElementChild);
        const modalEl = document.getElementById('uomBarcodeScannerModal');

        modalEl.addEventListener('show.bs.modal', window.prepareUomScannerModalLayer);
        modalEl.addEventListener('shown.bs.modal', window.prepareUomScannerModalLayer);
        modalEl.addEventListener('hide.bs.modal', function () {
            window.stopUomBarcodeScanner(true);
        });
        modalEl.addEventListener('hidden.bs.modal', function () {
            window.stopUomBarcodeScanner(true);
            window.restoreParentItemModalBackdrop();
        });

        return modalEl;
    };

    window.scanBarcode = function (btn) {
        const inputGroup = btn ? btn.closest('.input-group') : null;
        const input = inputGroup ? inputGroup.querySelector('.uom-barcode-input') : null;
        if (!input) return;

        try { uomBarcodeTargetInput = input; } catch (e) { window.uomBarcodeTargetInput = input; }

        const modalEl = window.ensureUomBarcodeScannerModal();
        const modal = bootstrap.Modal.getOrCreateInstance(modalEl, {
            backdrop: true,
            keyboard: true,
            focus: true
        });

        modalEl.removeEventListener('shown.bs.modal', startUomBarcodeScanner);
        modalEl.addEventListener('shown.bs.modal', startUomBarcodeScanner, { once: true });
        modal.show();
        window.prepareUomScannerModalLayer();
    };

    document.addEventListener('hidden.bs.modal', function (event) {
        if (event.target && event.target.id === 'uomBarcodeScannerModal') {
            window.restoreParentItemModalBackdrop();
        }
    });

    document.addEventListener('shown.bs.modal', function (event) {
        if (event.target && (event.target.id === 'itemModal' || event.target.id === 'editItemModal')) {
            setTimeout(normalizeNestedModalBackdrops, 60);
        }
    });
})();
</script>

</body>
</html>
