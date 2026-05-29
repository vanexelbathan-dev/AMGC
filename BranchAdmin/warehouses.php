<?php
require_once '../config/database.php';
require_once '../config/session_handler.php';

$user_id = $_SESSION['user_id'] ?? 0;
$user_name = isset($_SESSION['first_name']) ? trim($_SESSION['first_name'] . ' ' . ($_SESSION['last_name'] ?? '')) : 'Branch Admin';
$user_role = $_SESSION['role'] ?? 'branch_admin';
$branch_id = (int)($_SESSION['branch_id'] ?? 0);
$view_all_branches = $_SESSION['view_all_branches'] ?? false;

$user_initials = '';
foreach (explode(' ', trim($user_name)) as $part) {
    if ($part !== '') $user_initials .= strtoupper(substr($part, 0, 1));
}
if ($user_initials === '') $user_initials = 'BA';

function tableExists(mysqli $conn, string $table): bool {
    $safe = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '{$safe}'");
    return $result && $result->num_rows > 0;
}

function columnExists(mysqli $conn, string $table, string $column): bool {
    $safeTable = preg_replace('/[^A-Za-z0-9_]/', '', $table);
    $safeColumn = $conn->real_escape_string($column);
    $result = $conn->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
    return $result && $result->num_rows > 0;
}

function enumValues(mysqli $conn, string $table, string $column): array {
    $safeTable = preg_replace('/[^A-Za-z0-9_]/', '', $table);
    $safeColumn = $conn->real_escape_string($column);
    $result = $conn->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
    if (!$result || $result->num_rows === 0) return [];
    $row = $result->fetch_assoc();
    $type = $row['Type'] ?? '';
    if (!preg_match("/^enum\\((.*)\\)$/", $type, $m)) return [];
    $values = [];
    foreach (str_getcsv($m[1], ',', "'") as $v) {
        $values[] = $v;
    }
    return $values;
}

function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function moneyFmt($amount): string {
    return '₱' . number_format((float)$amount, 2);
}

function makeInitials(string $name): string {
    $out = '';
    foreach (explode(' ', trim($name)) as $part) {
        if ($part !== '') $out .= strtoupper(substr($part, 0, 1));
    }
    return $out !== '' ? substr($out, 0, 3) : 'R';
}

function ensureRollingTransferTables(mysqli $conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS `rolling_inventory_transfers` (
        `transfer_id` int(11) NOT NULL AUTO_INCREMENT,
        `transfer_number` varchar(60) NOT NULL,
        `source_branch_id` int(11) NOT NULL,
        `rolling_branch_id` int(11) NOT NULL,
        `received_by` int(11) DEFAULT NULL,
        `receive_date` date NOT NULL,
        `remarks` text DEFAULT NULL,
        `status` varchar(30) NOT NULL DEFAULT 'received',
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`transfer_id`),
        UNIQUE KEY `unique_transfer_number` (`transfer_number`),
        KEY `idx_rolling_transfer_source` (`source_branch_id`),
        KEY `idx_rolling_transfer_rolling` (`rolling_branch_id`),
        KEY `idx_rolling_transfer_received_by` (`received_by`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    $conn->query("CREATE TABLE IF NOT EXISTS `rolling_inventory_transfer_items` (
        `transfer_item_id` int(11) NOT NULL AUTO_INCREMENT,
        `transfer_id` int(11) NOT NULL,
        `item_id` int(11) NOT NULL,
        `unit_type_id` int(11) DEFAULT NULL,
        `unit_type_name` varchar(100) DEFAULT NULL,
        `quantity_received` decimal(12,2) NOT NULL DEFAULT 0.00,
        `unit_price` decimal(12,2) NOT NULL DEFAULT 0.00,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`transfer_item_id`),
        KEY `idx_transfer_item_transfer` (`transfer_id`),
        KEY `idx_transfer_item_item_unit` (`item_id`, `unit_type_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}

ensureRollingTransferTables($conn);

$branch_name = 'All Branches';
if (!$view_all_branches && $branch_id > 0 && tableExists($conn, 'branches')) {
    $branch_stmt = $conn->prepare("SELECT branch_name FROM branches WHERE branch_id = ? LIMIT 1");
    if ($branch_stmt) {
        $branch_stmt->bind_param('i', $branch_id);
        $branch_stmt->execute();
        $branch_row = $branch_stmt->get_result()->fetch_assoc();
        if ($branch_row) $branch_name = $branch_row['branch_name'];
        $branch_stmt->close();
    }
}

$warehouses_table_exists = tableExists($conn, 'warehouses');
$users_table_exists = tableExists($conn, 'users');
$drivers_table_exists = tableExists($conn, 'drivers');
$branches_table_exists = tableExists($conn, 'branches');
$items_table_exists = tableExists($conn, 'items');
$unit_types_table_exists = tableExists($conn, 'unit_types');
$pricing_table_exists = tableExists($conn, 'item_unit_pricing');
$sales_orders_table_exists = tableExists($conn, 'sales_orders');
$sales_order_items_table_exists = tableExists($conn, 'sales_order_items');
$transfer_table_exists = tableExists($conn, 'rolling_inventory_transfers');
$transfer_items_table_exists = tableExists($conn, 'rolling_inventory_transfer_items');

if ($warehouses_table_exists) {
    if (!columnExists($conn, 'warehouses', 'warehouse_code')) @$conn->query("ALTER TABLE warehouses ADD COLUMN warehouse_code VARCHAR(50) NULL AFTER warehouse_id");
    if (!columnExists($conn, 'warehouses', 'warehouse_type')) @$conn->query("ALTER TABLE warehouses ADD COLUMN warehouse_type VARCHAR(50) NOT NULL DEFAULT 'rolling' AFTER warehouse_name");
    if (!columnExists($conn, 'warehouses', 'category')) @$conn->query("ALTER TABLE warehouses ADD COLUMN category VARCHAR(150) NULL AFTER warehouse_type");
    if (!columnExists($conn, 'warehouses', 'truck_plate')) @$conn->query("ALTER TABLE warehouses ADD COLUMN truck_plate VARCHAR(50) NULL AFTER category");
    if (!columnExists($conn, 'warehouses', 'driver_name')) @$conn->query("ALTER TABLE warehouses ADD COLUMN driver_name VARCHAR(150) NULL AFTER truck_plate");
    if (!columnExists($conn, 'warehouses', 'description')) @$conn->query("ALTER TABLE warehouses ADD COLUMN description TEXT NULL AFTER driver_name");
    if (!columnExists($conn, 'warehouses', 'branch_id')) @$conn->query("ALTER TABLE warehouses ADD COLUMN branch_id INT(11) NULL AFTER description");
    if (!columnExists($conn, 'warehouses', 'status')) @$conn->query("ALTER TABLE warehouses ADD COLUMN status VARCHAR(30) NOT NULL DEFAULT 'active' AFTER branch_id");
    if (!columnExists($conn, 'warehouses', 'created_by')) @$conn->query("ALTER TABLE warehouses ADD COLUMN created_by INT(11) NULL AFTER status");
}

$warehouses_branch_col = $warehouses_table_exists && columnExists($conn, 'warehouses', 'branch_id');
$warehouse_type_enum = $warehouses_table_exists ? enumValues($conn, 'warehouses', 'warehouse_type') : [];
$status_enum = $warehouses_table_exists ? enumValues($conn, 'warehouses', 'status') : [];

function normalizeWarehouseTypeForDb(array $enumValues, string $type): string {
    $type = strtolower(trim($type));
    if (in_array($type, ['building', 'fixed'], true)) {
        return in_array('fixed', $enumValues, true) ? 'fixed' : 'building';
    }
    if (in_array('rolling', $enumValues, true)) return 'rolling';
    if (in_array('rolling sales', $enumValues, true)) return 'rolling sales';
    return 'rolling';
}

function normalizeStatusForDb(array $enumValues, string $status): string {
    $status = strtolower(trim($status));
    if (!in_array($status, ['active', 'inactive'], true)) $status = 'active';
    if (!empty($enumValues) && !in_array($status, $enumValues, true)) return $enumValues[0];
    return $status;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    try {
        if ($_POST['action'] === 'save_warehouse') {
            if (!$warehouses_table_exists) throw new Exception('Missing warehouses table.');

            $warehouse_id = (int)($_POST['warehouse_id'] ?? 0);
            $warehouse_name = trim($_POST['warehouse_name'] ?? '');
            $warehouse_type = normalizeWarehouseTypeForDb($warehouse_type_enum, $_POST['warehouse_type'] ?? 'rolling');
            $warehouse_category = trim($_POST['warehouse_category'] ?? '');
            $truck_plate = trim($_POST['truck_plate'] ?? '');
            $driver_name = trim($_POST['driver_name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $status = normalizeStatusForDb($status_enum, $_POST['status'] ?? 'active');

            if ($warehouse_name === '') throw new Exception('Warehouse name is required.');
            if (in_array($warehouse_type, ['fixed', 'building'], true) && $warehouse_category === '') {
                throw new Exception('Category is required when warehouse type is Building.');
            }
            if (!in_array($warehouse_type, ['fixed', 'building'], true)) $warehouse_category = '';

            if ($warehouse_id > 0) {
                $query = "UPDATE warehouses SET warehouse_name=?, warehouse_type=?, category=?, truck_plate=?, driver_name=?, description=?, status=?, updated_at=NOW() WHERE warehouse_id=?";
                $types = 'sssssssi';
                $params = [$warehouse_name, $warehouse_type, $warehouse_category, $truck_plate, $driver_name, $description, $status, $warehouse_id];
                if ($warehouses_branch_col && !$view_all_branches) {
                    $query .= " AND branch_id=?";
                    $types .= 'i';
                    $params[] = $branch_id;
                }
                $stmt = $conn->prepare($query);
                if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
                $stmt->bind_param($types, ...$params);
                if (!$stmt->execute()) throw new Exception('Failed to update warehouse: ' . $stmt->error);
                $stmt->close();
                echo json_encode(['success' => true, 'message' => 'Warehouse updated successfully.']);
                exit;
            }

            $warehouse_code = 'WH-' . date('YmdHis') . '-' . rand(100,999);
            if ($warehouses_branch_col) {
                $stmt = $conn->prepare("INSERT INTO warehouses (warehouse_code, warehouse_name, warehouse_type, category, truck_plate, driver_name, description, branch_id, status, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
                if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
                $stmt->bind_param('sssssssisi', $warehouse_code, $warehouse_name, $warehouse_type, $warehouse_category, $truck_plate, $driver_name, $description, $branch_id, $status, $user_id);
            } else {
                $stmt = $conn->prepare("INSERT INTO warehouses (warehouse_code, warehouse_name, warehouse_type, category, truck_plate, driver_name, description, status, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
                if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
                $stmt->bind_param('ssssssssi', $warehouse_code, $warehouse_name, $warehouse_type, $warehouse_category, $truck_plate, $driver_name, $description, $status, $user_id);
            }
            if (!$stmt->execute()) throw new Exception('Failed to add warehouse: ' . $stmt->error);
            $stmt->close();
            echo json_encode(['success' => true, 'message' => 'Warehouse added successfully.']);
            exit;
        }

        if ($_POST['action'] === 'toggle_warehouse_status') {
            if (!$warehouses_table_exists) throw new Exception('Missing warehouses table.');
            $warehouse_id = (int)($_POST['warehouse_id'] ?? 0);
            $status = normalizeStatusForDb($status_enum, $_POST['status'] ?? 'inactive');
            if ($warehouse_id <= 0) throw new Exception('Invalid warehouse.');
            $query = "UPDATE warehouses SET status=?, updated_at=NOW() WHERE warehouse_id=?";
            $types = 'si';
            $params = [$status, $warehouse_id];
            if ($warehouses_branch_col && !$view_all_branches) {
                $query .= " AND branch_id=?";
                $types .= 'i';
                $params[] = $branch_id;
            }
            $stmt = $conn->prepare($query);
            if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
            $stmt->bind_param($types, ...$params);
            if (!$stmt->execute()) throw new Exception('Failed to update warehouse status: ' . $stmt->error);
            $stmt->close();
            echo json_encode(['success' => true, 'message' => 'Warehouse status updated.']);
            exit;
        }

        throw new Exception('Invalid action.');
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

$warehouse_categories = [];
if (tableExists($conn, 'categories')) {
    $cols = [];
    $r = $conn->query("SHOW COLUMNS FROM categories");
    if ($r) while ($c = $r->fetch_assoc()) $cols[] = $c['Field'];
    $nameCol = in_array('category_name', $cols, true) ? 'category_name' : (in_array('name', $cols, true) ? 'name' : (in_array('category', $cols, true) ? 'category' : ''));
    $statusCol = in_array('status', $cols, true) ? 'status' : '';
    if ($nameCol !== '') {
        $q = "SELECT DISTINCT `$nameCol` AS category_name FROM categories WHERE `$nameCol` IS NOT NULL AND TRIM(`$nameCol`)<>''";
        if ($statusCol !== '') $q .= " AND (LOWER(`$statusCol`)='active' OR `$statusCol` IS NULL OR `$statusCol`='')";
        $q .= " ORDER BY `$nameCol` ASC";
        $res = $conn->query($q);
        if ($res) while ($row = $res->fetch_assoc()) $warehouse_categories[] = trim((string)$row['category_name']);
    }
}
if (empty($warehouse_categories) && $items_table_exists && columnExists($conn, 'items', 'category')) {
    $q = "SELECT DISTINCT category AS category_name FROM items WHERE category IS NOT NULL AND TRIM(category)<>''";
    if (columnExists($conn, 'items', 'branch_id') && !$view_all_branches && $branch_id > 0) $q .= " AND branch_id=" . (int)$branch_id;
    $q .= " ORDER BY category ASC";
    $res = $conn->query($q);
    if ($res) while ($row = $res->fetch_assoc()) $warehouse_categories[] = trim((string)$row['category_name']);
}
$warehouse_categories = array_values(array_unique(array_filter($warehouse_categories, fn($c) => trim((string)$c) !== '')));

$registered_warehouses = [];
if ($warehouses_table_exists) {
    $query = "SELECT w.*" . ($branches_table_exists && $warehouses_branch_col ? ", b.branch_name" : ", '' AS branch_name") . " FROM warehouses w";
    if ($branches_table_exists && $warehouses_branch_col) $query .= " LEFT JOIN branches b ON b.branch_id = w.branch_id";
    $query .= " WHERE 1=1";
    if ($warehouses_branch_col && !$view_all_branches && $branch_id > 0) $query .= " AND w.branch_id = " . (int)$branch_id;
    $query .= " ORDER BY w.status='active' DESC, w.warehouse_name ASC";
    $res = $conn->query($query);
    if ($res) $registered_warehouses = $res->fetch_all(MYSQLI_ASSOC);
}

$rolling_accounts = [];
if ($users_table_exists) {
    $driverJoin = '';
    $driverSelect = ", '' AS driver_name, '' AS vehicle_plate_number, '' AS vehicle_type";
    if ($drivers_table_exists) {
        $driverSelect = ", COALESCE(d.driver_name, '') AS driver_name, COALESCE(d.vehicle_plate_number, '') AS vehicle_plate_number, COALESCE(d.vehicle_type, '') AS vehicle_type";
        $driverJoin = " LEFT JOIN drivers d ON (d.driver_id = u.driver_id OR d.user_id = u.user_id)";
    }
    $branchSelect = $branches_table_exists ? ", COALESCE(b.branch_name, '') AS branch_name" : ", '' AS branch_name";
    $branchJoin = $branches_table_exists ? " LEFT JOIN branches b ON b.branch_id = u.branch_id" : "";
    $query = "
        SELECT
            u.user_id,
            TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) AS rolling_name,
            COALESCE(u.email,'') AS email,
            COALESCE(u.status,'active') AS status,
            u.branch_id,
            COALESCE(u.category,'') AS category
            {$driverSelect}
            {$branchSelect},
            COUNT(DISTINCT rt.transfer_id) AS receive_count,
            MAX(COALESCE(rt.created_at, rt.receive_date)) AS last_receive_at
        FROM users u
        {$driverJoin}
        {$branchJoin}
        LEFT JOIN rolling_inventory_transfers rt
            ON rt.received_by = u.user_id
           AND LOWER(TRIM(COALESCE(rt.status,'received'))) = 'received'
        WHERE u.role = 'rolling'
    ";
    if (!$view_all_branches && $branch_id > 0) {
        $query .= " AND (u.branch_id = " . (int)$branch_id . " OR rt.rolling_branch_id = " . (int)$branch_id . ")";
    }
    $query .= " GROUP BY u.user_id ORDER BY u.status='active' DESC, rolling_name ASC";
    $res = $conn->query($query);
    if ($res) $rolling_accounts = $res->fetch_all(MYSQLI_ASSOC);
}

if ($transfer_table_exists && $users_table_exists) {
    $known = array_fill_keys(array_map(fn($r) => (int)$r['user_id'], $rolling_accounts), true);
    $query = "
        SELECT
            rt.received_by AS user_id,
            CONCAT('Rolling User #', rt.received_by) AS rolling_name,
            '' AS email,
            'active' AS status,
            rt.rolling_branch_id AS branch_id,
            '' AS category,
            '' AS driver_name,
            '' AS vehicle_plate_number,
            '' AS vehicle_type,
            " . ($branches_table_exists ? "COALESCE(b.branch_name,'')" : "''") . " AS branch_name,
            COUNT(DISTINCT rt.transfer_id) AS receive_count,
            MAX(COALESCE(rt.created_at, rt.receive_date)) AS last_receive_at
        FROM rolling_inventory_transfers rt
        " . ($branches_table_exists ? "LEFT JOIN branches b ON b.branch_id = rt.rolling_branch_id" : "") . "
        WHERE rt.received_by IS NOT NULL AND rt.received_by > 0 AND LOWER(TRIM(COALESCE(rt.status,'received'))) = 'received'
    ";
    if (!$view_all_branches && $branch_id > 0) $query .= " AND rt.rolling_branch_id = " . (int)$branch_id;
    $query .= " GROUP BY rt.received_by, rt.rolling_branch_id ORDER BY last_receive_at DESC";
    $res = $conn->query($query);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            if (!isset($known[(int)$row['user_id']])) {
                $rolling_accounts[] = $row;
                $known[(int)$row['user_id']] = true;
            }
        }
    }
}

$selected_rolling_user_id = isset($_GET['rolling_user_id']) ? (int)$_GET['rolling_user_id'] : 0;
if ($selected_rolling_user_id <= 0 && !empty($rolling_accounts)) {
    $selected_rolling_user_id = (int)$rolling_accounts[0]['user_id'];
}

$selected_rolling = null;
foreach ($rolling_accounts as $ra) {
    if ((int)$ra['user_id'] === $selected_rolling_user_id) {
        $selected_rolling = $ra;
        break;
    }
}

$selected_rolling_branch_id = $branch_id;
if ($selected_rolling && (int)($selected_rolling['branch_id'] ?? 0) > 0) {
    $selected_rolling_branch_id = (int)$selected_rolling['branch_id'];
}

$warehouse_inventory = [];
$inventory_error = '';
if ($selected_rolling_user_id > 0 && $items_table_exists && $unit_types_table_exists && $sales_orders_table_exists && $sales_order_items_table_exists && $transfer_table_exists && $transfer_items_table_exists) {
    $priceJoin = '';
    $priceSelect = "COALESCE(rx.unit_price, i.unit_price, 0) AS unit_price";
    if ($pricing_table_exists) {
        $priceSelect = "COALESCE(iup.unit_price, rx.unit_price, i.unit_price, 0) AS unit_price";
        $priceJoin = "
            LEFT JOIN item_unit_pricing iup
                ON iup.pricing_id = (
                    SELECT iup2.pricing_id
                    FROM item_unit_pricing iup2
                    WHERE iup2.item_id = rx.item_id
                      AND (iup2.unit_type_id = rx.unit_type_id OR rx.unit_type_id = 0)
                      AND (iup2.price_level = 'Standard' OR iup2.price_level IS NULL OR iup2.price_level = '')
                      AND (iup2.effective_date IS NULL OR iup2.effective_date <= CURDATE())
                      AND (iup2.effective_until IS NULL OR iup2.effective_until >= CURDATE())
                    ORDER BY COALESCE(iup2.effective_date, '1000-01-01') DESC, iup2.pricing_id DESC
                    LIMIT 1
                )
        ";
    }

    $categorySelect = columnExists($conn, 'items', 'category') ? "COALESCE(i.category, 'Uncategorized') AS category" : "'Uncategorized' AS category";
    $uomInitialSelect = columnExists($conn, 'unit_types', 'uom_initial') ? "COALESCE(NULLIF(TRIM(ut.uom_initial), ''), '') AS uom_initial" : "'' AS uom_initial";

    $query = "
        SELECT
            i.item_id,
            COALESCE(i.item_code, '') AS item_code,
            COALESCE(i.item_name, 'Unknown Item') AS item_name,
            COALESCE(i.description, '') AS item_description,
            {$categorySelect},
            rx.unit_type_id,
            COALESCE(NULLIF(TRIM(rx.unit_type_name), ''), ut.unit_type_name, i.unit_type, 'Piece') AS unit_type,
            {$uomInitialSelect},
            COALESCE(rx.received_quantity, 0) AS received_quantity,
            COALESCE(sold.sold_quantity, 0) AS sold_quantity,
            GREATEST(COALESCE(rx.received_quantity, 0) - COALESCE(sold.sold_quantity, 0), 0) AS current_inventory,
            {$priceSelect},
            rx.last_receive_date AS as_of_date,
            rx.last_receive_at AS updated_at
        FROM (
            SELECT
                rti.item_id,
                COALESCE(rti.unit_type_id, 0) AS unit_type_id,
                COALESCE(NULLIF(TRIM(rti.unit_type_name), ''), MAX(ut_rx.unit_type_name), 'Piece') AS unit_type_name,
                SUM(COALESCE(rti.quantity_received, 0)) AS received_quantity,
                MAX(COALESCE(rti.unit_price, 0)) AS unit_price,
                MAX(rt.receive_date) AS last_receive_date,
                MAX(COALESCE(rt.created_at, rt.receive_date)) AS last_receive_at
            FROM rolling_inventory_transfer_items rti
            INNER JOIN rolling_inventory_transfers rt
                ON rt.transfer_id = rti.transfer_id
               AND rt.rolling_branch_id = ?
               AND rt.received_by = ?
               AND LOWER(TRIM(COALESCE(rt.status, 'received'))) = 'received'
            LEFT JOIN unit_types ut_rx ON ut_rx.unit_type_id = rti.unit_type_id
            WHERE COALESCE(rti.quantity_received, 0) > 0
            GROUP BY rti.item_id, COALESCE(rti.unit_type_id, 0), COALESCE(NULLIF(TRIM(rti.unit_type_name), ''), ut_rx.unit_type_name, 'Piece')
        ) rx
        INNER JOIN items i ON i.item_id = rx.item_id
        LEFT JOIN unit_types ut ON ut.unit_type_id = NULLIF(rx.unit_type_id, 0)
        LEFT JOIN (
            SELECT
                soi.item_id,
                LOWER(TRIM(COALESCE(soi.unit_type, 'Piece'))) AS unit_key,
                SUM(COALESCE(soi.quantity_ordered, 0)) AS sold_quantity
            FROM sales_order_items soi
            INNER JOIN sales_orders so ON so.so_id = soi.so_id
            WHERE so.branch_id = ?
              AND so.created_by = ?
              AND LOWER(TRIM(COALESCE(so.order_status, ''))) <> 'cancelled'
            GROUP BY soi.item_id, LOWER(TRIM(COALESCE(soi.unit_type, 'Piece')))
        ) sold ON sold.item_id = rx.item_id
              AND sold.unit_key = LOWER(TRIM(COALESCE(NULLIF(TRIM(rx.unit_type_name), ''), ut.unit_type_name, i.unit_type, 'Piece')))
        {$priceJoin}
        WHERE (i.status IS NULL OR LOWER(TRIM(i.status)) = 'active')
        ORDER BY category ASC, item_name ASC, unit_type ASC
    ";
    $stmt = $conn->prepare($query);
    if ($stmt) {
        $stmt->bind_param('iiii', $selected_rolling_branch_id, $selected_rolling_user_id, $selected_rolling_branch_id, $selected_rolling_user_id);
        if ($stmt->execute()) {
            $warehouse_inventory = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        } else {
            $inventory_error = $stmt->error;
        }
        $stmt->close();
    } else {
        $inventory_error = $conn->error;
    }
} elseif ($selected_rolling_user_id > 0) {
    $missing = [];
    foreach ([
        'items' => $items_table_exists,
        'unit_types' => $unit_types_table_exists,
        'sales_orders' => $sales_orders_table_exists,
        'sales_order_items' => $sales_order_items_table_exists,
        'rolling_inventory_transfers' => $transfer_table_exists,
        'rolling_inventory_transfer_items' => $transfer_items_table_exists,
    ] as $tbl => $ok) {
        if (!$ok) $missing[] = $tbl;
    }
    $inventory_error = 'Missing required table(s): ' . implode(', ', $missing);
}

$total_inventory_qty = 0;
$total_received_qty = 0;
$total_sold_qty = 0;
$total_inventory_value = 0;
foreach ($warehouse_inventory as $row) {
    $qty = (float)($row['current_inventory'] ?? 0);
    $received = (float)($row['received_quantity'] ?? 0);
    $sold = (float)($row['sold_quantity'] ?? 0);
    $price = (float)($row['unit_price'] ?? 0);
    $total_inventory_qty += $qty;
    $total_received_qty += $received;
    $total_sold_qty += $sold;
    $total_inventory_value += ($qty * $price);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Warehouses - <?php echo h($branch_name); ?></title>
    <link rel="icon" type="image/png" href="../Pictures/favicon-96x96.png" sizes="96x96" />
    <link rel="stylesheet" href="../css/current_inventory.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <script src="../js/session-checker.js"></script>
    <style>
    :root {
        --primary-green: #44D34E;
        --secondary-green: #44D34E;
        --light-green: #d1fae5;
        --dark-green: #047857;
        --dark-color: #052A47;
        --light-color: #f9fafb;
    }

    body {
        background: #f8fafc;
        color: #1e293b;
    }

    .main-content {
        padding: 1.5rem;
    }

    .navbar-top {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 1.5rem;
        gap: 1rem;
    }

    .mobile-menu-btn {
        display: none;
        background: transparent;
        border: none;
        font-size: 1.5rem;
        color: #052A47;
    }

    .page-title h2 {
        color: #052A47;
        margin: 0;
    }

    .page-title p {
        margin: 0;
        color: #64748b;
    }

    .top-actions {
        display: flex;
        gap: .5rem;
        align-items: center;
        flex-wrap: wrap;
        justify-content: flex-end;
    }

    .btn-amgc-primary {
        background: #047857;
        color: #fff;
        border: none;
        border-radius: 10px;
        min-height: 44px;
        font-weight: 700;
    }

    .btn-amgc-primary:hover {
        color: #fff;
        opacity: .95;
    }

    .btn-amgc-dark {
        background: #052A47;
        color: #fff;
        border: none;
        border-radius: 10px;
        min-height: 44px;
        font-weight: 700;
    }

    .btn-amgc-dark:hover {
        color: #fff;
        opacity: .96;
    }

    .stat-card {
        background: linear-gradient(135deg, #047857, #059669);
        border: none;
        border-radius: 18px;
        padding: 1rem;
        color: white;
        box-shadow: 0 6px 18px rgba(4, 120, 87, .18);
        height: 100%;
    }

    .stat-card i {
        font-size: 1.75rem;
    }

    .stat-value {
        font-size: 1.45rem;
        font-weight: 800;
    }

    .stat-label {
        font-size: .78rem;
        opacity: .95;
        text-transform: uppercase;
        letter-spacing: .4px;
    }

    .category-tabs {
        border-bottom: 2px solid #dee2e6;
        margin-bottom: 20px;
        padding-bottom: 5px;
        display: flex;
        flex-wrap: wrap;
        gap: 5px;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }

    .category-tab {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        padding: 10px 20px;
        background-color: #f8f9fa;
        border: 1px solid #dee2e6;
        border-bottom: none;
        border-radius: 8px 8px 0 0;
        cursor: pointer;
        font-weight: 500;
        color: #495057;
        transition: all .2s;
        text-decoration: none;
        white-space: nowrap;
    }

    .category-tab:hover {
        color: #2E7D32;
        background: #fff;
        text-decoration: none;
    }

    .category-tab.active {
        background-color: #2E7D32;
        color: white;
        border-color: #2E7D32;
    }

    .category-tab .tab-badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 22px;
        height: 22px;
        padding: 0 7px;
        border-radius: 999px;
        background: #e9ecef;
        color: #495057;
        font-size: .75rem;
        font-weight: 700;
        line-height: 1;
    }

    .category-tab.active .tab-badge {
        background: rgba(255, 255, 255, .22);
        color: white;
    }

    .category-tab .status-dot {
        width: 8px;
        height: 8px;
        border-radius: 50%;
        display: inline-block;
        background: #adb5bd;
    }

    .category-tab .status-dot.active {
        background: #44D34E;
        box-shadow: 0 0 0 3px rgba(68, 211, 78, .22);
    }

    .form-card,
    .table-card {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 16px;
        box-shadow: 0 6px 18px rgba(15, 23, 42, .06);
        overflow: hidden;
    }

    .card-header-amgc {
        padding: 1rem 1.25rem;
        border-bottom: 1px solid #e2e8f0;
        background: #fff;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
    }

    .card-header-amgc h5 {
        margin: 0;
        color: #052A47;
    }

    .table thead th {
        background: #047857 !important;
        color: white !important;
        font-size: .78rem;
        text-transform: uppercase;
        letter-spacing: .35px;
        border: 0 !important;
    }

    .table tbody td {
        vertical-align: middle;
        font-size: .88rem;
    }

    .search-box {
        position: relative;
    }

    .search-box i {
        position: absolute;
        top: 50%;
        left: .75rem;
        transform: translateY(-50%);
        color: #94a3b8;
    }

    .search-box input {
        padding-left: 2.25rem;
        border-radius: 10px;
    }

    .badge-soft-success {
        background: #d1fae5;
        color: #047857;
        border: 1px solid rgba(4, 120, 87, .15);
    }

    .badge-soft-danger {
        background: #fee2e2;
        color: #b91c1c;
        border: 1px solid rgba(185, 28, 28, .12);
    }

    .badge-soft-info {
        background: #e0f2fe;
        color: #0369a1;
        border: 1px solid rgba(3, 105, 161, .14);
    }

    .rolling-avatar {
        width: 42px;
        height: 42px;
        border-radius: 50%;
        background: linear-gradient(135deg, #047857, #44D34E);
        color: white;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 800;
        flex: 0 0 auto;
    }

    .muted-small {
        font-size: .78rem;
        color: #64748b;
    }

    .section-label {
        font-size: .76rem;
        text-transform: uppercase;
        letter-spacing: .45px;
        color: #64748b;
        font-weight: 800;
        margin: 1rem 0 .5rem;
    }

    .modal-content {
        border-radius: 18px;
        border: 0;
    }

    .modal-header {
        background: linear-gradient(135deg, #047857, #44D34E);
        color: #fff;
        border-radius: 18px 18px 0 0;
    }

    .modal-header .btn-close {
        filter: brightness(0) invert(1);
    }

    @media (max-width: 768px) {
        .main-content {
            padding: 1rem;
        }

        .mobile-menu-btn {
            display: block;
        }

        .navbar-top {
            align-items: flex-start;
        }

        .page-title h2 {
            font-size: 1.35rem;
        }

        .top-actions .btn {
            width: 100%;
        }

        .table-responsive {
            border: 0;
        }

        .category-tabs {
            flex-wrap: nowrap;
            padding-bottom: 10px;
        }

        .stat-card {
            padding: .85rem;
        }

        .stat-value {
            font-size: 1.15rem;
        }

        .table thead {
            display: none;
        }

        .inventory-table tbody tr {
            display: block;
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            margin-bottom: .85rem;
            padding: .85rem;
            background: #fff;
        }

        .inventory-table tbody td {
            display: flex;
            justify-content: space-between;
            gap: 1rem;
            border: 0 !important;
            padding: .35rem 0 !important;
            text-align: right !important;
        }

        .inventory-table tbody td::before {
            content: attr(data-label);
            font-weight: 700;
            color: #052A47;
            text-align: left;
        }

        .inventory-table tbody td.item-main {
            display: block;
            text-align: left !important;
        }

        .inventory-table tbody td.item-main::before {
            display: none;
        }

        .card-header-amgc {
            align-items: flex-start;
        }

        .search-box {
            width: 100%;
            min-width: 0 !important;
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
<div id="appPage">
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
                <!-- Warehouse Dropdown -->
<li class="nav-item dropdown-nav">
    <a class="nav-link" href="#" onclick="toggleSidebarDropdown(event, 'warehouseMenu')">
        <i class="bi bi-shop"></i>
        <span class="nav-text">Warehouse</span>
        <i class="bi bi-chevron-down dropdown-arrow"></i>
    </a>
    <div class="collapse" id="warehouseMenu">
        <ul class="nav flex-column ps-4">
            <li class="nav-item">
                <a class="nav-link" href="current_inventory.php">
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
                                    <a class="nav-link active" href="warehouses.php">
                                    <i class="bi bi-shop"></i>
                                    <span class="nav-text">Warehouses</span></a>
                                </li>
        </ul>
    </div>
</li>

                
                <!-- Supplier Dropdown -->
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

<!-- Delivery Dropdown -->
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

    <div class="main-content" id="mainContent">
        <div class="navbar-top">
            <button class="mobile-menu-btn" id="mobileMenuBtn"><i class="bi bi-list"></i></button>
            <div class="page-title flex-grow-1"><h2>Warehouses</h2><p>Accurate rolling inventory from received transfers and rolling sales for <?php echo h($branch_name); ?></p></div>
            <div class="top-actions"><button class="btn btn-amgc-primary" onclick="openWarehouseModal()"><i class="bi bi-plus-circle me-1"></i> Add Warehouse</button></div>
        </div>

        <?php if (!$users_table_exists || !$transfer_table_exists || !$transfer_items_table_exists): ?>
            <div class="alert alert-warning">
                <strong>Database setup needed.</strong> Required tables: <code>users</code>, <code>rolling_inventory_transfers</code>, and <code>rolling_inventory_transfer_items</code>.
            </div>
        <?php endif; ?>
        <?php if ($inventory_error !== ''): ?>
            <div class="alert alert-danger"><strong>Inventory query error:</strong> <?php echo h($inventory_error); ?></div>
        <?php endif; ?>

        <div class="row g-2 g-md-3 mb-4">
            <div class="col-6 col-lg-3"><div class="stat-card"><i class="bi bi-truck-front"></i><div class="stat-value"><?php echo count($rolling_accounts); ?></div><div class="stat-label">Rolling Accounts</div></div></div>
            <div class="col-6 col-lg-3"><div class="stat-card"><i class="bi bi-box-seam"></i><div class="stat-value"><?php echo count($warehouse_inventory); ?></div><div class="stat-label">Item Rows</div></div></div>
            <div class="col-6 col-lg-3"><div class="stat-card"><i class="bi bi-stack"></i><div class="stat-value"><?php echo number_format($total_inventory_qty, 2); ?></div><div class="stat-label">Current Qty</div></div></div>
            <div class="col-6 col-lg-3"><div class="stat-card"><i class="bi bi-cash-coin"></i><div class="stat-value"><?php echo moneyFmt($total_inventory_value); ?></div><div class="stat-label">Inventory Value</div></div></div>
        </div>

        <div class="category-tabs">
            <?php foreach ($rolling_accounts as $ra): ?>
                <a class="category-tab <?php echo ((int)$ra['user_id'] === $selected_rolling_user_id) ? 'active' : ''; ?>" href="warehouses.php?rolling_user_id=<?php echo (int)$ra['user_id']; ?>">
                    <i class="bi bi-truck"></i>
                    <span><?php echo h($ra['rolling_name'] ?: ('Rolling User #' . $ra['user_id'])); ?></span>
                    <span class="tab-badge"><?php echo (int)($ra['receive_count'] ?? 0); ?></span>
                    <span class="status-dot <?php echo strtolower((string)($ra['status'] ?? 'active')) === 'active' ? 'active' : ''; ?>" title="<?php echo h($ra['status'] ?: 'active'); ?>"></span>
                </a>
            <?php endforeach; ?>
            <?php if (empty($rolling_accounts)): ?>
                <span class="text-muted">No rolling account found yet.</span>
            <?php endif; ?>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-lg-12">
                <div class="table-card">
                    <div class="card-header-amgc flex-wrap">
                        <div>
                            <h5>Rolling Inventory Table</h5>
                        </div>
                        <div class="search-box" style="min-width:240px"><i class="bi bi-search"></i><input type="text" id="inventorySearch" class="form-control" placeholder="Search item/category/UOM"></div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 inventory-table" id="warehouseInventoryTable">
                            <thead>
                                <tr>
                                    <th>Item</th>
                                    <th>Category</th>
                                    <th>UOM</th>
                                    <th class="text-end">Received</th>
                                    <th class="text-end">Sold</th>
                                    <th class="text-end">Current Inventory</th>
                                    <th class="text-end">Unit Price</th>
                                    <th class="text-end">Total Value</th>
                                    <th>As Of</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($warehouse_inventory)): ?>
                                    <tr><td colspan="9" class="text-center text-muted py-5"><i class="bi bi-inbox fs-1 d-block mb-2"></i>No rolling inventory found for this account yet.<br><small>Stocks will appear after this rolling account receives inventory from Branch Admin.</small></td></tr>
                                <?php else: ?>
                                    <?php foreach ($warehouse_inventory as $row): $qty=(float)$row['current_inventory']; $received=(float)$row['received_quantity']; $sold=(float)$row['sold_quantity']; $price=(float)$row['unit_price']; ?>
                                        <tr>
                                            <td class="item-main" data-label="Item"><strong><?php echo h($row['item_name']); ?></strong></td>
                                            <td data-label="Category"><?php echo h($row['category'] ?: 'Uncategorized'); ?></td>
                                            <td data-label="UOM"><span class="fw-bold"><?php echo h($row['unit_type'] ?: 'Piece'); ?></span></td>
                                            <td data-label="Received" class="text-end"><?php echo number_format($received, 2); ?></td>
                                            <td data-label="Sold" class="text-end"><?php echo number_format($sold, 2); ?></td>
                                            <td data-label="Current Inventory" class="text-end fw-bold"><?php echo number_format($qty, 2); ?></td>
                                            <td data-label="Unit Price" class="text-end"><?php echo moneyFmt($price); ?></td>
                                            <td data-label="Total Value" class="text-end fw-bold"><?php echo moneyFmt($qty * $price); ?></td>
                                            <td data-label="As Of"><?php echo h($row['as_of_date'] ?: date('Y-m-d', strtotime($row['updated_at'] ?? 'now'))); ?></td>
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
</div>

<div class="modal fade" id="warehouseModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" id="warehouseForm">
            <div class="modal-header"><h5 class="modal-title" id="warehouseModalTitle">Add Warehouse</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <input type="hidden" id="warehouseId" name="warehouse_id">
                <div class="mb-3"><label class="form-label fw-bold">Warehouse / Truck Name</label><input type="text" class="form-control" id="warehouseName" name="warehouse_name" required placeholder="Example: Rolling Truck 1"></div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-bold">Warehouse Type</label>
                        <select class="form-select" id="warehouseType" name="warehouse_type" required onchange="toggleWarehouseCategoryField()">
                            <option value="rolling">Rolling Sales</option>
                            <option value="fixed">Building</option>
                        </select>
                    </div>
                    <div class="col-md-6" id="warehouseCategoryWrap" style="display:none;">
                        <label class="form-label fw-bold">Category</label>
                        <select class="form-select" id="warehouseCategory" name="warehouse_category">
                            <option value="">Select Category</option>
                            <?php foreach ($warehouse_categories as $category): ?>
                                <option value="<?php echo h($category); ?>"><?php echo h($category); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (empty($warehouse_categories)): ?><small class="text-muted">No category found from database yet.</small><?php endif; ?>
                    </div>
                </div>
                <div class="row g-3 mt-1">
                    <div class="col-md-6"><label class="form-label fw-bold">Truck Plate</label><input type="text" class="form-control" id="truckPlate" name="truck_plate" placeholder="Optional"></div>
                    <div class="col-md-6"><label class="form-label fw-bold">Driver Name</label><input type="text" class="form-control" id="driverName" name="driver_name" placeholder="Optional"></div>
                </div>
                <div class="mt-3"><label class="form-label fw-bold">Description</label><textarea class="form-control" id="warehouseDescription" name="description" rows="3" placeholder="Optional notes"></textarea></div>
                <div class="mt-3"><label class="form-label fw-bold">Status</label><select class="form-select" id="warehouseStatus" name="status"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-amgc-primary"><i class="bi bi-save me-1"></i> Save</button></div>
        </form>
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


<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>

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


// Sidebar dropdown: close others when opening a new one
function toggleSidebarDropdown(event, id) {
    event.preventDefault();
    const target = document.getElementById(id);
    if (!target) return;

    // List of all dropdown menu IDs in the sidebar
    const allMenus = ['warehouseMenu', 'supplierMenu', 'customerMenu', 'deliveryMenu', 'bankingMenu'];

    // Close all menus except the target
    allMenus.forEach(menuId => {
        const menu = document.getElementById(menuId);
        if (menu && menu !== target && menu.classList.contains('show')) {
            menu.classList.remove('show');
        }
    });

    // Toggle the target menu
    target.classList.toggle('show');
}

function logout() {
    window.location.href = '../logout.php';
}

// Sidebar toggle for mobile and desktop
const sidebar = document.getElementById('sidebar');
const mobileMenuBtn = document.getElementById('mobileMenuBtn');
if (mobileMenuBtn) {
    mobileMenuBtn.addEventListener('click', () => sidebar?.classList.toggle('show'));
}
const desktopToggleBtn = document.getElementById('desktopToggleBtn');
if (desktopToggleBtn) {
    desktopToggleBtn.addEventListener('click', () => {
        sidebar?.classList.toggle('collapsed');
        document.getElementById('mainContent')?.classList.toggle('expanded');
    });
}

// Warehouse modal category field visibility
function toggleWarehouseCategoryField() {
    const type = (document.getElementById('warehouseType')?.value || '').toLowerCase();
    const wrap = document.getElementById('warehouseCategoryWrap');
    const category = document.getElementById('warehouseCategory');
    if (!wrap || !category) return;
    if (type === 'fixed' || type === 'building') {
        wrap.style.display = '';
        category.setAttribute('required', 'required');
    } else {
        wrap.style.display = 'none';
        category.removeAttribute('required');
        category.value = '';
    }
}

function openWarehouseModal(data = null) {
    document.getElementById('warehouseForm').reset();
    document.getElementById('warehouseId').value = '';
    document.getElementById('warehouseModalTitle').textContent = 'Add Warehouse';
    if (data) {
        document.getElementById('warehouseModalTitle').textContent = 'Edit Warehouse';
        const dbType = (data.warehouse_type || 'rolling').toLowerCase();
        document.getElementById('warehouseId').value = data.warehouse_id || '';
        document.getElementById('warehouseName').value = data.warehouse_name || '';
        document.getElementById('warehouseType').value = (dbType === 'fixed' || dbType === 'building') ? 'fixed' : 'rolling';
        document.getElementById('warehouseCategory').value = data.category || '';
        document.getElementById('truckPlate').value = data.truck_plate || '';
        document.getElementById('driverName').value = data.driver_name || '';
        document.getElementById('warehouseDescription').value = data.description || '';
        document.getElementById('warehouseStatus').value = data.status || 'active';
    }
    toggleWarehouseCategoryField();
    new bootstrap.Modal(document.getElementById('warehouseModal'), { keyboard: true }).show();
}

document.getElementById('warehouseForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const fd = new FormData(this);
    fd.append('action', 'save_warehouse');
    fetch('warehouses.php', { method: 'POST', body: fd })
        .then(r => r.text())
        .then(t => {
            let d;
            try { d = JSON.parse(t); } catch (e) { throw new Error(t); }
            if (!d.success) throw new Error(d.message || 'Failed to save warehouse.');
            return Swal.fire('Saved', d.message, 'success');
        })
        .then(() => location.reload())
        .catch(err => Swal.fire('Error', err.message, 'error'));
});

function toggleWarehouseStatus(id, status) {
    Swal.fire({
        icon: 'question',
        title: 'Update status?',
        text: 'Set this warehouse to ' + status + '?',
        showCancelButton: true,
        confirmButtonColor: '#047857'
    }).then(res => {
        if (!res.isConfirmed) return;
        const fd = new FormData();
        fd.append('action', 'toggle_warehouse_status');
        fd.append('warehouse_id', id);
        fd.append('status', status);
        fetch('warehouses.php', { method: 'POST', body: fd })
            .then(r => r.text())
            .then(t => {
                let d;
                try { d = JSON.parse(t); } catch (e) { throw new Error(t); }
                if (!d.success) throw new Error(d.message || 'Failed to update.');
                return Swal.fire('Updated', d.message, 'success');
            })
            .then(() => location.reload())
            .catch(err => Swal.fire('Error', err.message, 'error'));
    });
}

document.getElementById('inventorySearch')?.addEventListener('input', function() {
    const term = this.value.toLowerCase().trim();
    document.querySelectorAll('#warehouseInventoryTable tbody tr').forEach(row => {
        row.style.display = row.textContent.toLowerCase().includes(term) ? '' : 'none';
    });
});

// On page load, open only the menu that matches the current page
function expandCurrentMenu() {
    const currentPath = window.location.pathname;
    const currentFile = currentPath.substring(currentPath.lastIndexOf('/') + 1);
    const menuMap = {
        'warehouses.php': 'warehouseMenu',
        'current_inventory.php': 'warehouseMenu',
        'bad_orders.php': 'warehouseMenu',
        'pick_list_items.php': 'warehouseMenu',
        'purchase_order.php': 'supplierMenu',
        'supplier.php': 'supplierMenu',
        'customer_list.php': 'customerMenu',
        'approve_credit_requests.php': 'customerMenu',
        'sales_order.php': 'customerMenu',
        'collections.php': 'customerMenu',
        'trip_tickets.php': 'deliveryMenu',
        'deposit.php': 'bankingMenu',
        'Withdrawal.php': 'bankingMenu',
        'bank_statement.php': 'bankingMenu',
        'expenses.php': 'bankingMenu'
    };
    const menuId = menuMap[currentFile];
    // First close all menus
    const allMenus = ['warehouseMenu', 'supplierMenu', 'customerMenu', 'deliveryMenu', 'bankingMenu'];
    allMenus.forEach(id => {
        const menu = document.getElementById(id);
        if (menu) menu.classList.remove('show');
    });
    // Then open the one for the current page
    if (menuId) {
        const menu = document.getElementById(menuId);
        if (menu) menu.classList.add('show');
    }
}

document.addEventListener('DOMContentLoaded', expandCurrentMenu);
</script>
</body>
</html>