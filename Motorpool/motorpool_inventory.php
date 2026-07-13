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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(($_POST['action'] ?? ''), ['get_tire_serial_items','save_tire_serial_item','delete_tire_serial_item'], true)) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/json');
    ini_set('display_errors', 0);
    error_reporting(E_ALL);

    $jsonOut = function(array $payload): void { echo json_encode($payload); exit; };
    $money = function($v): float {
        $raw = trim(str_replace(['₱', ',', ' '], '', (string)$v));
        return is_numeric($raw) ? (float)$raw : 0.0;
    };
    $dateOrNull = function($v): ?string {
        $v = trim((string)$v);
        if ($v === '') return null;
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) return $v;
        $ts = strtotime($v);
        return $ts ? date('Y-m-d', $ts) : null;
    };
    $colExists = function(mysqli $conn, string $table, string $column): bool {
        $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
        $column = $conn->real_escape_string($column);
        $res = @$conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
        return $res && $res->num_rows > 0;
    };
    $ensureCol = function(mysqli $conn, string $table, string $column, string $definition) use ($colExists): void {
        $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
        if (!$colExists($conn, $table, $column)) { @$conn->query("ALTER TABLE `$table` ADD COLUMN $definition"); }
    };
    $isTireParent = function(mysqli $conn, int $itemId): array {
        $stmt = $conn->prepare("SELECT item_id, item_code, item_name, category, unit_type FROM motorpool_inventory_items WHERE item_id = ? AND COALESCE(status,'active') <> 'deleted' LIMIT 1");
        if (!$stmt) throw new Exception('Unable to check tire item: ' . $conn->error);
        $stmt->bind_param('i', $itemId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) throw new Exception('Parent item not found.');
        $cat = strtolower(trim((string)($row['category'] ?? '')));
        if (!in_array($cat, ['tire','tires'], true)) throw new Exception('Serial items are only allowed for Tire category.');
        return $row;
    };

    try {
        $conn->query("CREATE TABLE IF NOT EXISTS motorpool_tire_serial_items (
            serial_item_id INT AUTO_INCREMENT PRIMARY KEY,
            parent_item_id INT NOT NULL,
            serial_number VARCHAR(120) NOT NULL,
            item_code VARCHAR(100) DEFAULT NULL,
            barcode VARCHAR(120) DEFAULT NULL,
            quantity DECIMAL(12,2) NOT NULL DEFAULT 1.00,
            unit_type VARCHAR(80) DEFAULT 'Piece',
            beginning_inventory DECIMAL(12,2) NOT NULL DEFAULT 1.00,
            as_of_date DATE DEFAULT NULL,
            unit_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            total_cost DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            unit_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            price_level VARCHAR(80) DEFAULT 'Standard',
            supplier VARCHAR(255) DEFAULT NULL,
            description TEXT DEFAULT NULL,
            remarks TEXT DEFAULT NULL,
            status VARCHAR(30) DEFAULT 'active',
            created_by INT DEFAULT NULL,
            updated_by INT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_tire_parent_serial (parent_item_id, serial_number),
            KEY idx_tire_parent (parent_item_id),
            KEY idx_tire_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        foreach ([
            'item_code' => "`item_code` VARCHAR(100) DEFAULT NULL AFTER `serial_number`",
            'barcode' => "`barcode` VARCHAR(120) DEFAULT NULL AFTER `item_code`",
            'quantity' => "`quantity` DECIMAL(12,2) NOT NULL DEFAULT 1.00 AFTER `barcode`",
            'unit_type' => "`unit_type` VARCHAR(80) DEFAULT 'Piece' AFTER `quantity`",
            'beginning_inventory' => "`beginning_inventory` DECIMAL(12,2) NOT NULL DEFAULT 1.00 AFTER `unit_type`",
            'as_of_date' => "`as_of_date` DATE DEFAULT NULL AFTER `beginning_inventory`",
            'unit_cost' => "`unit_cost` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `as_of_date`",
            'total_cost' => "`total_cost` DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER `unit_cost`",
            'unit_price' => "`unit_price` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `total_cost`",
            'price_level' => "`price_level` VARCHAR(80) DEFAULT 'Standard' AFTER `unit_price`",
            'supplier' => "`supplier` VARCHAR(255) DEFAULT NULL AFTER `price_level`",
            'description' => "`description` TEXT DEFAULT NULL AFTER `supplier`",
            'remarks' => "`remarks` TEXT DEFAULT NULL AFTER `description`",
            'status' => "`status` VARCHAR(30) DEFAULT 'active' AFTER `remarks`"
        ] as $c => $def) { $ensureCol($conn, 'motorpool_tire_serial_items', $c, $def); }

        // Tire Profiling is now stored per Tire Serial child item.
        // This early AJAX handler exits before the normal page setup, so ensure the profile table here too.
        $conn->query("CREATE TABLE IF NOT EXISTS motorpool_tire_profiles (
            tire_profile_id INT AUTO_INCREMENT PRIMARY KEY,
            item_id INT NOT NULL UNIQUE,
            serial_number VARCHAR(120) DEFAULT NULL,
            brand VARCHAR(150) DEFAULT NULL,
            tire_size VARCHAR(120) DEFAULT NULL,
            pattern VARCHAR(120) DEFAULT NULL,
            purchase_date DATE DEFAULT NULL,
            supplier_name VARCHAR(255) DEFAULT NULL,
            invoice_no VARCHAR(120) DEFAULT NULL,
            purchase_cost DECIMAL(14,2) DEFAULT 0.00,
            current_status VARCHAR(60) DEFAULT 'Warehouse',
            current_truck VARCHAR(180) DEFAULT NULL,
            current_plate VARCHAR(80) DEFAULT NULL,
            current_position VARCHAR(80) DEFAULT NULL,
            remaining_tread VARCHAR(80) DEFAULT NULL,
            remarks TEXT DEFAULT NULL,
            created_by INT DEFAULT NULL,
            updated_by INT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_tire_profile_item (item_id),
            KEY idx_tire_profile_serial (serial_number),
            KEY idx_tire_profile_status (current_status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        foreach ([
            'serial_number' => "`serial_number` VARCHAR(120) DEFAULT NULL AFTER `item_id`",
            'brand' => "`brand` VARCHAR(150) DEFAULT NULL AFTER `serial_number`",
            'tire_size' => "`tire_size` VARCHAR(120) DEFAULT NULL AFTER `brand`",
            'pattern' => "`pattern` VARCHAR(120) DEFAULT NULL AFTER `tire_size`",
            'purchase_date' => "`purchase_date` DATE DEFAULT NULL AFTER `pattern`",
            'supplier_name' => "`supplier_name` VARCHAR(255) DEFAULT NULL AFTER `purchase_date`",
            'invoice_no' => "`invoice_no` VARCHAR(120) DEFAULT NULL AFTER `supplier_name`",
            'purchase_cost' => "`purchase_cost` DECIMAL(14,2) DEFAULT 0.00 AFTER `invoice_no`",
            'current_status' => "`current_status` VARCHAR(60) DEFAULT 'Warehouse' AFTER `purchase_cost`",
            'current_truck' => "`current_truck` VARCHAR(180) DEFAULT NULL AFTER `current_status`",
            'current_plate' => "`current_plate` VARCHAR(80) DEFAULT NULL AFTER `current_truck`",
            'current_position' => "`current_position` VARCHAR(80) DEFAULT NULL AFTER `current_plate`",
            'remaining_tread' => "`remaining_tread` VARCHAR(80) DEFAULT NULL AFTER `current_position`",
            'remarks' => "`remarks` TEXT DEFAULT NULL AFTER `remaining_tread`",
            'created_by' => "`created_by` INT DEFAULT NULL AFTER `remarks`",
            'updated_by' => "`updated_by` INT DEFAULT NULL AFTER `created_by`"
        ] as $c => $def) { $ensureCol($conn, 'motorpool_tire_profiles', $c, $def); }

        $action = (string)($_POST['action'] ?? '');
        $parentId = (int)($_POST['parent_item_id'] ?? $_POST['item_id'] ?? 0);
        if ($parentId <= 0) throw new Exception('Invalid Tire item.');
        $parent = $isTireParent($conn, $parentId);
        $userId = (int)($_SESSION['user_id'] ?? 0);

        if ($action === 'get_tire_serial_items') {
            // Load tire serial children from the SAME Add Item table so the form/fields/pricing are exactly like Add Item.
            $ensureCol($conn, 'motorpool_inventory_items', 'parent_item_id', "`parent_item_id` INT DEFAULT NULL");
            $ensureCol($conn, 'motorpool_inventory_items', 'is_tire_serial_child', "`is_tire_serial_child` TINYINT(1) NOT NULL DEFAULT 0");

            $stmt = $conn->prepare("
                SELECT 
                    i.item_id AS serial_item_id,
                    i.item_id,
                    i.item_code,
                    i.barcode,
                    i.item_name AS serial_number,
                    i.item_name,
                    i.description,
                    i.status,
                    i.unit_type,
                    i.unit_price,
                    COALESCE(inv.current_inventory, i.stock, i.current_stock, 0) AS quantity,
                    COALESCE(inv.beginning_inventory, inv.current_inventory, i.stock, i.current_stock, 0) AS beginning_inventory,
                    inv.as_of_date,
                    COALESCE(inv.unit_cost, i.unit_cost, i.unit_price, 0) AS unit_cost,
                    COALESCE(inv.total_cost, COALESCE(inv.current_inventory, i.stock, i.current_stock, 0) * COALESCE(inv.unit_cost, i.unit_cost, i.unit_price, 0)) AS total_cost,
                    COALESCE(p.price_level, 'Standard') AS price_level,
                    tp.tire_profile_id,
                    COALESCE(tp.brand, i.principal, '') AS tire_brand,
                    COALESCE(tp.tire_size, '') AS tire_size,
                    COALESCE(tp.pattern, '') AS tire_pattern,
                    tp.purchase_date AS tire_purchase_date,
                    COALESCE(tp.invoice_no, '') AS tire_invoice_no,
                    COALESCE(tp.purchase_cost, COALESCE(inv.unit_cost, i.unit_cost, 0)) AS tire_purchase_cost,
                    COALESCE(tp.current_status, 'Warehouse') AS tire_status,
                    COALESCE(tp.current_truck, '') AS tire_truck,
                    COALESCE(tp.current_plate, '') AS tire_plate,
                    COALESCE(tp.current_position, '') AS tire_position,
                    COALESCE(tp.remaining_tread, '') AS tire_remaining_tread,
                    COALESCE(tp.remarks, '') AS tire_profile_remarks
                FROM motorpool_inventory_items i
                LEFT JOIN motorpool_item_unit_inventory inv 
                    ON inv.item_id = i.item_id 
                    AND inv.unit_type_id = i.default_unit_type_id
                LEFT JOIN motorpool_item_unit_pricing p 
                    ON p.item_id = i.item_id 
                    AND p.unit_type_id = i.default_unit_type_id
                    AND COALESCE(p.price_level,'Standard') = 'Standard'
                LEFT JOIN motorpool_tire_profiles tp
                    ON tp.item_id = i.item_id
                WHERE i.parent_item_id = ? 
                  AND COALESCE(i.is_tire_serial_child,0) = 1
                  AND COALESCE(i.status,'active') <> 'deleted'
                ORDER BY i.item_name ASC, i.item_id DESC
            ");
            if (!$stmt) throw new Exception('Unable to load tire serial items: ' . $conn->error);
            $stmt->bind_param('i', $parentId);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            $summary = ['total'=>0, 'active'=>0, 'inactive'=>0, 'total_cost'=>0.0, 'total_value'=>0.0];
            foreach ($rows as $r) {
                $qty = (float)($r['quantity'] ?? 0);
                $summary['total'] += $qty;
                if (strtolower((string)($r['status'] ?? 'active')) === 'active') $summary['active'] += $qty; else $summary['inactive'] += $qty;
                $summary['total_cost'] += (float)($r['total_cost'] ?? 0);
                $summary['total_value'] += $qty * (float)($r['unit_price'] ?? 0);
            }
            $jsonOut(['success'=>true, 'parent'=>$parent, 'serial_items'=>$rows, 'summary'=>$summary]);
        }

        if ($action === 'save_tire_serial_item') {
            // IMPORTANT FIX:
            // The list loader reads Tire serials from motorpool_inventory_items
            // where parent_item_id = parent tire and is_tire_serial_child = 1.
            // The old handler saved into motorpool_tire_serial_items, so it said
            // "Saved" but nothing appeared in the modal. This saves to the SAME
            // Add Item tables used by the loader.
            $serialId = (int)($_POST['serial_item_id'] ?? 0);
            $serialNo = trim((string)($_POST['serial_number'] ?? ''));
            if ($serialNo === '') throw new Exception('Serial number is required.');

            $itemCode = trim((string)($_POST['item_code'] ?? ''));
            $barcode = trim((string)($_POST['barcode'] ?? ''));
            $qty = $money($_POST['quantity'] ?? 1);
            if ($qty <= 0) $qty = 1;
            $unitType = trim((string)($_POST['unit_type'] ?? ($parent['unit_type'] ?? 'Piece')));
            if ($unitType === '') $unitType = 'Piece';
            $beginning = $money($_POST['beginning_inventory'] ?? $qty);
            if ($beginning <= 0) $beginning = $qty;
            $asOf = $dateOrNull($_POST['as_of_date'] ?? null);
            $unitCost = $money($_POST['unit_cost'] ?? 0);
            $totalCost = $qty * $unitCost;
            $unitPrice = $money($_POST['unit_price'] ?? 0);
            $priceLevel = trim((string)($_POST['price_level'] ?? 'Standard')) ?: 'Standard';
            $description = trim((string)($_POST['description'] ?? ''));
            if ($description === '') $description = $serialNo;
            $remarks = trim((string)($_POST['remarks'] ?? ''));
            if ($remarks !== '') $description .= "\n" . $remarks;
            $status = trim((string)($_POST['status'] ?? 'active')) ?: 'active';

            $ensureCol($conn, 'motorpool_inventory_items', 'parent_item_id', "`parent_item_id` INT DEFAULT NULL");
            $ensureCol($conn, 'motorpool_inventory_items', 'is_tire_serial_child', "`is_tire_serial_child` TINYINT(1) NOT NULL DEFAULT 0");
            $ensureCol($conn, 'motorpool_inventory_items', 'barcode', "`barcode` VARCHAR(120) DEFAULT NULL");
            $ensureCol($conn, 'motorpool_inventory_items', 'unit_cost', "`unit_cost` DECIMAL(12,2) NOT NULL DEFAULT 0.00");
            $ensureCol($conn, 'motorpool_inventory_items', 'total_cost', "`total_cost` DECIMAL(14,2) NOT NULL DEFAULT 0.00");
            $ensureCol($conn, 'motorpool_inventory_items', 'branch_id', "`branch_id` INT DEFAULT NULL");
            $ensureCol($conn, 'motorpool_inventory_items', 'updated_by', "`updated_by` INT DEFAULT NULL");

            $conn->query("CREATE TABLE IF NOT EXISTS motorpool_unit_types (unit_type_id INT AUTO_INCREMENT PRIMARY KEY, unit_type_name VARCHAR(100) NOT NULL, uom_initial VARCHAR(20) DEFAULT NULL, quantity_smallest_pack INT DEFAULT 1, is_default_uom TINYINT DEFAULT 0, multiplier DECIMAL(10,2) DEFAULT 1.00, branch_id INT DEFAULT NULL, status VARCHAR(30) DEFAULT 'active', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, KEY idx_motorpool_unit_name (unit_type_name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $conn->query("CREATE TABLE IF NOT EXISTS motorpool_item_unit_inventory (inventory_id INT AUTO_INCREMENT PRIMARY KEY, item_id INT NOT NULL, unit_type_id INT NOT NULL, current_inventory DECIMAL(12,2) NOT NULL DEFAULT 0.00, beginning_inventory DECIMAL(12,2) NOT NULL DEFAULT 0.00, as_of_date DATE DEFAULT NULL, unit_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00, total_cost DECIMAL(14,2) NOT NULL DEFAULT 0.00, status VARCHAR(30) DEFAULT 'active', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, KEY idx_motorpool_inv_item_unit (item_id, unit_type_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $conn->query("CREATE TABLE IF NOT EXISTS motorpool_item_unit_pricing (pricing_id INT AUTO_INCREMENT PRIMARY KEY, item_id INT NOT NULL, unit_type_id INT NOT NULL, unit_price DECIMAL(12,2) NOT NULL DEFAULT 0.00, unit_quantity INT DEFAULT 1, effective_date DATE DEFAULT NULL, price_level VARCHAR(50) DEFAULT 'Standard', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, KEY idx_motorpool_price_item_unit_level (item_id, unit_type_id, price_level)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $conn->query("CREATE TABLE IF NOT EXISTS motorpool_item_unit_types (item_unit_type_id INT AUTO_INCREMENT PRIMARY KEY, item_id INT NOT NULL, unit_type_id INT NOT NULL, unit_type_name VARCHAR(100) NOT NULL, uom_initial VARCHAR(20) DEFAULT NULL, barcode VARCHAR(100) DEFAULT NULL, smallest_pack_quantity DECIMAL(12,4) NOT NULL DEFAULT 1.0000, is_default_uom TINYINT(1) NOT NULL DEFAULT 0, branch_id INT DEFAULT NULL, status VARCHAR(30) DEFAULT 'active', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, KEY idx_motorpool_iut_item_unit (item_id, unit_type_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            if ($itemCode === '') {
                $base = trim((string)($parent['item_code'] ?? 'TIRE'));
                $safe = preg_replace('/[^A-Za-z0-9_-]+/', '-', $serialNo);
                $safe = trim($safe, '-');
                $itemCode = $base . '-' . ($safe !== '' ? $safe : date('YmdHis'));
            }

            // Prevent duplicate serial numbers under the same parent.
            $dupSql = "SELECT item_id FROM motorpool_inventory_items WHERE parent_item_id = ? AND COALESCE(is_tire_serial_child,0)=1 AND item_name = ? AND COALESCE(status,'active') <> 'deleted'";
            if ($serialId > 0) $dupSql .= " AND item_id <> ?";
            $dupSql .= " LIMIT 1";
            $dup = $conn->prepare($dupSql);
            if (!$dup) throw new Exception('Unable to check duplicate serial: ' . $conn->error);
            if ($serialId > 0) $dup->bind_param('isi', $parentId, $serialNo, $serialId); else $dup->bind_param('is', $parentId, $serialNo);
            $dup->execute();
            if ($dup->get_result()->fetch_assoc()) { $dup->close(); throw new Exception('Serial number already exists under this Tire item.'); }
            $dup->close();

            // Prevent duplicate item code globally/within branch, except the edited child.
            $codeSql = "SELECT item_id FROM motorpool_inventory_items WHERE item_code = ? AND COALESCE(status,'active') <> 'deleted'";
            if ($serialId > 0) $codeSql .= " AND item_id <> ?";
            $codeSql .= " LIMIT 1";
            $chk = $conn->prepare($codeSql);
            if (!$chk) throw new Exception('Unable to check item code: ' . $conn->error);
            if ($serialId > 0) $chk->bind_param('si', $itemCode, $serialId); else $chk->bind_param('s', $itemCode);
            $chk->execute();
            if ($chk->get_result()->fetch_assoc()) { $chk->close(); throw new Exception('Item code already exists: ' . $itemCode); }
            $chk->close();

            $branchId = (int)($_SESSION['branch_id'] ?? 0);
            $pointsEligible = 1;
            $pcase = $unitPrice * 12;
            $pinner = $unitPrice * 6;
            $pbox = $unitPrice * 24;
            $pcarton = $unitPrice * 48;

            if ($serialId > 0) {
                $stmt = $conn->prepare("UPDATE motorpool_inventory_items
                    SET item_code=?, barcode=?, item_name=?, description=?, category='Tire', principal=NULL,
                        stock=?, current_stock=?, unit_type=?, unit_price=?, unit_cost=?, total_cost=?,
                        price_case=?, price_inner_pack=?, price_box=?, price_carton=?, status=?,
                        parent_item_id=?, is_tire_serial_child=1, updated_by=?, updated_at=NOW()
                    WHERE item_id=? AND parent_item_id=? AND COALESCE(is_tire_serial_child,0)=1");
                if (!$stmt) throw new Exception('Unable to update tire serial item: ' . $conn->error);
                $stmt->bind_param('ssssddsddddddssiiii', $itemCode, $barcode, $serialNo, $description, $qty, $qty, $unitType, $unitPrice, $unitCost, $totalCost, $pcase, $pinner, $pbox, $pcarton, $status, $parentId, $userId, $serialId, $parentId);
                if (!$stmt->execute()) throw new Exception('Unable to update tire serial item: ' . $stmt->error);
                if ($stmt->affected_rows < 0) throw new Exception('Unable to update tire serial item.');
                $stmt->close();
                $childItemId = $serialId;
            } else {
                $stmt = $conn->prepare("INSERT INTO motorpool_inventory_items
                    (item_code, barcode, item_name, description, category, stock, current_stock, unit_type, unit_price, unit_cost, total_cost,
                     price_case, price_inner_pack, price_box, price_carton, reorder_level, status, branch_id, points_eligible,
                     created_by, updated_by, parent_item_id, is_tire_serial_child, created_at, updated_at)
                    VALUES (?, ?, ?, ?, 'Tire', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())");
                if (!$stmt) throw new Exception('Unable to insert tire serial item: ' . $conn->error);
                $stmt->bind_param('ssssddsdddddddsiiiii', $itemCode, $barcode, $serialNo, $description, $qty, $qty, $unitType, $unitPrice, $unitCost, $totalCost, $pcase, $pinner, $pbox, $pcarton, $status, $branchId, $pointsEligible, $userId, $userId, $parentId);
                if (!$stmt->execute()) throw new Exception('Unable to insert tire serial item: ' . $stmt->error);
                $childItemId = (int)$conn->insert_id;
                $stmt->close();
            }

            // Resolve/create UoM.
            $unitId = 0;
            $uStmt = $conn->prepare("SELECT unit_type_id FROM motorpool_unit_types WHERE unit_type_name=? AND COALESCE(status,'active')='active' ORDER BY unit_type_id ASC LIMIT 1");
            if ($uStmt) {
                $uStmt->bind_param('s', $unitType);
                $uStmt->execute();
                $uRow = $uStmt->get_result()->fetch_assoc();
                $uStmt->close();
                if ($uRow) $unitId = (int)$uRow['unit_type_id'];
            }
            if ($unitId <= 0) {
                $initial = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $unitType), 0, 3));
                if ($initial === '') $initial = 'PC';
                $uStatus = 'active';
                $uQty = 1;
                $uDefault = 0;
                $uIns = $conn->prepare("INSERT INTO motorpool_unit_types (unit_type_name, uom_initial, quantity_smallest_pack, is_default_uom, multiplier, branch_id, status) VALUES (?, ?, ?, ?, 1.00, ?, ?)");
                if (!$uIns) throw new Exception('Unable to create UoM: ' . $conn->error);
                $uIns->bind_param('ssiiis', $unitType, $initial, $uQty, $uDefault, $branchId, $uStatus);
                if (!$uIns->execute()) throw new Exception('Unable to create UoM: ' . $uIns->error);
                $unitId = (int)$conn->insert_id;
                $uIns->close();
            }

            // Upsert UoM row.
            $iut = $conn->prepare("SELECT item_unit_type_id FROM motorpool_item_unit_types WHERE item_id=? AND unit_type_id=? LIMIT 1");
            if ($iut) {
                $iut->bind_param('ii', $childItemId, $unitId);
                $iut->execute();
                $iutRow = $iut->get_result()->fetch_assoc();
                $iut->close();
                if ($iutRow) {
                    $iutId = (int)$iutRow['item_unit_type_id'];
                    $isDefault = 1; $smallest = 1.0;
                    $up = $conn->prepare("UPDATE motorpool_item_unit_types SET unit_type_name=?, barcode=?, smallest_pack_quantity=?, is_default_uom=?, branch_id=?, status='active', updated_at=NOW() WHERE item_unit_type_id=?");
                    if ($up) { $up->bind_param('ssdiii', $unitType, $barcode, $smallest, $isDefault, $branchId, $iutId); $up->execute(); $up->close(); }
                } else {
                    $isDefault = 1; $smallest = 1.0; $initial = strtoupper(substr($unitType,0,3));
                    $ins = $conn->prepare("INSERT INTO motorpool_item_unit_types (item_id, unit_type_id, unit_type_name, uom_initial, barcode, smallest_pack_quantity, is_default_uom, branch_id, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active', NOW(), NOW())");
                    if ($ins) { $ins->bind_param('iisssdii', $childItemId, $unitId, $unitType, $initial, $barcode, $smallest, $isDefault, $branchId); $ins->execute(); $ins->close(); }
                }
            }

            // Upsert inventory.
            $inv = $conn->prepare("SELECT inventory_id FROM motorpool_item_unit_inventory WHERE item_id=? AND unit_type_id=? LIMIT 1");
            if (!$inv) throw new Exception('Inventory check failed: ' . $conn->error);
            $inv->bind_param('ii', $childItemId, $unitId);
            $inv->execute();
            $invRow = $inv->get_result()->fetch_assoc();
            $inv->close();
            if ($invRow) {
                $invId = (int)$invRow['inventory_id'];
                $upInv = $conn->prepare("UPDATE motorpool_item_unit_inventory SET current_inventory=?, beginning_inventory=?, as_of_date=?, unit_cost=?, total_cost=?, status='active', updated_at=NOW() WHERE inventory_id=?");
                if (!$upInv) throw new Exception('Inventory update failed: ' . $conn->error);
                $upInv->bind_param('ddsddi', $qty, $beginning, $asOf, $unitCost, $totalCost, $invId);
                if (!$upInv->execute()) throw new Exception('Inventory update failed: ' . $upInv->error);
                $upInv->close();
            } else {
                $inInv = $conn->prepare("INSERT INTO motorpool_item_unit_inventory (item_id, unit_type_id, current_inventory, beginning_inventory, as_of_date, unit_cost, total_cost, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'active')");
                if (!$inInv) throw new Exception('Inventory insert failed: ' . $conn->error);
                $inInv->bind_param('iiddsdd', $childItemId, $unitId, $qty, $beginning, $asOf, $unitCost, $totalCost);
                if (!$inInv->execute()) throw new Exception('Inventory insert failed: ' . $inInv->error);
                $inInv->close();
            }

            // Upsert Standard/selected price level.
            $priceCheck = $conn->prepare("SELECT pricing_id FROM motorpool_item_unit_pricing WHERE item_id=? AND unit_type_id=? AND price_level=? LIMIT 1");
            if (!$priceCheck) throw new Exception('Price check failed: ' . $conn->error);
            $priceCheck->bind_param('iis', $childItemId, $unitId, $priceLevel);
            $priceCheck->execute();
            $priceRow = $priceCheck->get_result()->fetch_assoc();
            $priceCheck->close();
            if ($priceRow) {
                $pricingId = (int)$priceRow['pricing_id'];
                $unitQty = 1;
                $upPrice = $conn->prepare("UPDATE motorpool_item_unit_pricing SET unit_price=?, unit_quantity=?, updated_at=NOW() WHERE pricing_id=?");
                if (!$upPrice) throw new Exception('Price update failed: ' . $conn->error);
                $upPrice->bind_param('dii', $unitPrice, $unitQty, $pricingId);
                if (!$upPrice->execute()) throw new Exception('Price update failed: ' . $upPrice->error);
                $upPrice->close();
            } else {
                $unitQty = 1;
                $inPrice = $conn->prepare("INSERT INTO motorpool_item_unit_pricing (item_id, unit_type_id, unit_price, unit_quantity, price_level) VALUES (?, ?, ?, ?, ?)");
                if (!$inPrice) throw new Exception('Price insert failed: ' . $conn->error);
                $inPrice->bind_param('iidis', $childItemId, $unitId, $unitPrice, $unitQty, $priceLevel);
                if (!$inPrice->execute()) throw new Exception('Price insert failed: ' . $inPrice->error);
                $inPrice->close();
            }

            $defUpd = $conn->prepare("UPDATE motorpool_inventory_items SET default_unit_type_id=?, stock=?, current_stock=?, total_cost=?, updated_at=NOW() WHERE item_id=?");
            if ($defUpd) { $defUpd->bind_param('idddi', $unitId, $qty, $qty, $totalCost, $childItemId); $defUpd->execute(); $defUpd->close(); }

            // Save Tire Profile on the serial child item, not on the parent Tire item.
            $profileBrand = trim((string)($_POST['tire_brand'] ?? ''));
            $profileSize = trim((string)($_POST['tire_size'] ?? ''));
            $profilePattern = trim((string)($_POST['tire_pattern'] ?? ''));
            $profilePurchaseDate = $dateOrNull($_POST['tire_purchase_date'] ?? null);
            $profileInvoice = trim((string)($_POST['tire_invoice_no'] ?? ''));
            $profilePurchaseCost = $money($_POST['tire_purchase_cost'] ?? $unitCost);
            if ($profilePurchaseCost <= 0) $profilePurchaseCost = $unitCost;
            $profileStatus = trim((string)($_POST['tire_status'] ?? 'Warehouse')) ?: 'Warehouse';
            $profileTruck = trim((string)($_POST['tire_truck'] ?? ''));
            $profilePlate = trim((string)($_POST['tire_plate'] ?? ''));
            $profilePosition = trim((string)($_POST['tire_position'] ?? ''));
            $profileTread = trim((string)($_POST['tire_remaining_tread'] ?? ''));
            $profileRemarks = trim((string)($_POST['tire_profile_remarks'] ?? ''));
            $existingProfileId = 0;
            $profileCheck = $conn->prepare("SELECT tire_profile_id FROM motorpool_tire_profiles WHERE item_id = ? LIMIT 1");
            if ($profileCheck) {
                $profileCheck->bind_param('i', $childItemId);
                $profileCheck->execute();
                $profileRow = $profileCheck->get_result()->fetch_assoc();
                $profileCheck->close();
                if ($profileRow) $existingProfileId = (int)$profileRow['tire_profile_id'];
            }
            if ($existingProfileId > 0) {
                $profileStmt = $conn->prepare("UPDATE motorpool_tire_profiles SET serial_number=?, brand=?, tire_size=?, pattern=?, purchase_date=?, invoice_no=?, purchase_cost=?, current_status=?, current_truck=?, current_plate=?, current_position=?, remaining_tread=?, remarks=?, updated_by=?, updated_at=NOW() WHERE tire_profile_id=?");
                if ($profileStmt) {
                    $profileStmt->bind_param('ssssssdssssssii', $serialNo, $profileBrand, $profileSize, $profilePattern, $profilePurchaseDate, $profileInvoice, $profilePurchaseCost, $profileStatus, $profileTruck, $profilePlate, $profilePosition, $profileTread, $profileRemarks, $userId, $existingProfileId);
                    $profileStmt->execute();
                    $profileStmt->close();
                }
            } else {
                $profileStmt = $conn->prepare("INSERT INTO motorpool_tire_profiles (item_id, serial_number, brand, tire_size, pattern, purchase_date, invoice_no, purchase_cost, current_status, current_truck, current_plate, current_position, remaining_tread, remarks, created_by, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                if ($profileStmt) {
                    $profileStmt->bind_param('issssssdssssssii', $childItemId, $serialNo, $profileBrand, $profileSize, $profilePattern, $profilePurchaseDate, $profileInvoice, $profilePurchaseCost, $profileStatus, $profileTruck, $profilePlate, $profilePosition, $profileTread, $profileRemarks, $userId, $userId);
                    $profileStmt->execute();
                    $profileStmt->close();
                }
            }

            // Keep parent Tire stock/value synced from visible child serial Add Item rows.
            $sumStmt = $conn->prepare("SELECT COALESCE(SUM(current_stock),0) qty, COALESCE(SUM(COALESCE(NULLIF(total_cost,0), current_stock * unit_cost)),0) cost FROM motorpool_inventory_items WHERE parent_item_id=? AND COALESCE(is_tire_serial_child,0)=1 AND COALESCE(status,'active')='active'");
            if ($sumStmt) {
                $sumStmt->bind_param('i', $parentId);
                $sumStmt->execute();
                $sum = $sumStmt->get_result()->fetch_assoc();
                $sumStmt->close();
                $newQty = (float)($sum['qty'] ?? 0);
                $newCost = (float)($sum['cost'] ?? 0);
                $upd = $conn->prepare("UPDATE motorpool_inventory_items SET stock=?, current_stock=?, total_cost=?, updated_at=NOW() WHERE item_id=?");
                if ($upd) { $upd->bind_param('dddi', $newQty, $newQty, $newCost, $parentId); $upd->execute(); $upd->close(); }
            }

            $jsonOut(['success'=>true, 'message'=>'Tire serial item saved.', 'serial_item_id'=>$childItemId]);
        }

        if ($action === 'delete_tire_serial_item') {
            $serialId = (int)($_POST['serial_item_id'] ?? 0);
            if ($serialId <= 0) throw new Exception('Invalid serial item.');
            $ensureCol($conn, 'motorpool_inventory_items', 'parent_item_id', "`parent_item_id` INT DEFAULT NULL");
            $ensureCol($conn, 'motorpool_inventory_items', 'is_tire_serial_child', "`is_tire_serial_child` TINYINT(1) NOT NULL DEFAULT 0");

            $conn->begin_transaction();

            $check = $conn->prepare("SELECT item_id FROM motorpool_inventory_items WHERE item_id=? AND parent_item_id=? AND COALESCE(is_tire_serial_child,0)=1 LIMIT 1");
            if (!$check) throw new Exception('Unable to validate serial item: ' . $conn->error);
            $check->bind_param('ii', $serialId, $parentId);
            $check->execute();
            $exists = $check->get_result()->fetch_assoc();
            $check->close();
            if (!$exists) throw new Exception('Serial item not found under this Tire item.');

            foreach (['motorpool_item_unit_pricing','motorpool_item_unit_inventory','motorpool_item_unit_types','motorpool_item_images'] as $tbl) {
                $safeTbl = preg_replace('/[^a-zA-Z0-9_]/', '', $tbl);
                $tableCheck = $conn->query("SHOW TABLES LIKE '$safeTbl'");
                if ($tableCheck && $tableCheck->num_rows > 0) {
                    $del = $conn->prepare("DELETE FROM `$safeTbl` WHERE item_id=?");
                    if ($del) { $del->bind_param('i', $serialId); $del->execute(); $del->close(); }
                }
            }

            $profileTableCheck = $conn->query("SHOW TABLES LIKE 'motorpool_tire_profiles'");
            if ($profileTableCheck && $profileTableCheck->num_rows > 0) {
                $historyTableCheck = $conn->query("SHOW TABLES LIKE 'motorpool_tire_history'");
                if ($historyTableCheck && $historyTableCheck->num_rows > 0) {
                    $delHist = $conn->prepare("DELETE h FROM motorpool_tire_history h INNER JOIN motorpool_tire_profiles p ON p.tire_profile_id = h.tire_profile_id WHERE p.item_id=?");
                    if ($delHist) { $delHist->bind_param('i', $serialId); $delHist->execute(); $delHist->close(); }
                }
                $delProfile = $conn->prepare("DELETE FROM motorpool_tire_profiles WHERE item_id=?");
                if ($delProfile) { $delProfile->bind_param('i', $serialId); $delProfile->execute(); $delProfile->close(); }
            }

            $stmt = $conn->prepare("DELETE FROM motorpool_inventory_items WHERE item_id=? AND parent_item_id=? AND COALESCE(is_tire_serial_child,0)=1");
            if (!$stmt) throw new Exception('Unable to delete tire serial item: ' . $conn->error);
            $stmt->bind_param('ii', $serialId, $parentId);
            if (!$stmt->execute()) throw new Exception('Unable to delete tire serial item: ' . $stmt->error);
            $stmt->close();

            $sumStmt = $conn->prepare("SELECT COALESCE(SUM(COALESCE(child_inv.total_inventory, c.current_stock, c.stock, 0)),0) qty, COALESCE(SUM(COALESCE(child_inv.total_cost, NULLIF(c.total_cost,0), COALESCE(c.current_stock,c.stock,0) * COALESCE(c.unit_cost,0))),0) cost FROM motorpool_inventory_items c LEFT JOIN (SELECT item_id, SUM(COALESCE(current_inventory,0)) AS total_inventory, SUM(CASE WHEN COALESCE(total_cost,0) > 0 THEN total_cost ELSE COALESCE(current_inventory, beginning_inventory, 0) * COALESCE(unit_cost,0) END) AS total_cost FROM motorpool_item_unit_inventory WHERE COALESCE(status,'active')='active' GROUP BY item_id) child_inv ON child_inv.item_id=c.item_id WHERE c.parent_item_id=? AND COALESCE(c.is_tire_serial_child,0)=1 AND COALESCE(c.status,'active')='active'");
            if ($sumStmt) {
                $sumStmt->bind_param('i', $parentId);
                $sumStmt->execute();
                $sum = $sumStmt->get_result()->fetch_assoc();
                $sumStmt->close();
                $newQty = (float)($sum['qty'] ?? 0);
                $newCost = (float)($sum['cost'] ?? 0);
                $upd = $conn->prepare("UPDATE motorpool_inventory_items SET stock=?, current_stock=?, total_cost=?, updated_at=NOW() WHERE item_id=?");
                if ($upd) { $upd->bind_param('dddi', $newQty, $newQty, $newCost, $parentId); $upd->execute(); $upd->close(); }
            }

            $conn->commit();
            $jsonOut(['success'=>true, 'message'=>'Tire serial item permanently deleted.']);
        }
    } catch (Throwable $e) {
        error_log('Tire serial item ajax error: ' . $e->getMessage());
        $jsonOut(['success'=>false, 'message'=>$e->getMessage()]);
    }
}



// ========== FAST SAVE/UPDATE ITEM HANDLER ==========
// This runs before the long compatibility/setup section so Add Item and Update Item will not stay on Processing.
// It saves Motorpool UOM, inventory, and price levels directly to the Motorpool tables.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(($_POST['action'] ?? ''), ['add_item', 'update_item'], true)) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/json');
    ini_set('display_errors', 0);
    error_reporting(E_ALL);

    $fastRespond = function(array $payload): void {
        echo json_encode($payload);
        exit;
    };

    $fastColumnExists = function(mysqli $conn, string $table, string $column): bool {
        $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
        $columnEsc = $conn->real_escape_string($column);
        $res = @$conn->query("SHOW COLUMNS FROM `$table` LIKE '$columnEsc'");
        return $res && $res->num_rows > 0;
    };

    $fastEnsureColumn = function(mysqli $conn, string $table, string $column, string $definition) use ($fastColumnExists): void {
        $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
        if (!$fastColumnExists($conn, $table, $column)) {
            @$conn->query("ALTER TABLE `$table` ADD COLUMN $definition");
        }
    };

    $fastMoney = function($value): float {
        $raw = trim(str_replace(['₱', ',', ' '], '', (string)$value));
        return is_numeric($raw) ? (float)$raw : 0.0;
    };

    $fastDate = function($value): ?string {
        $value = trim((string)$value);
        if ($value === '') return null;
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return $value;
        $ts = strtotime($value);
        return $ts ? date('Y-m-d', $ts) : null;
    };

    $fastUploadOne = function(string $field): ?string {
        if (empty($_FILES[$field]['name']) || !is_uploaded_file($_FILES[$field]['tmp_name'])) return null;
        $ext = strtolower(pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg','jpeg','png','gif','webp'], true)) return null;
        if ((int)($_FILES[$field]['size'] ?? 0) > 5 * 1024 * 1024) return null;
        $dir = '../uploads/motorpool_inventory/';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $filename = 'item_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        return move_uploaded_file($_FILES[$field]['tmp_name'], $dir . $filename) ? $filename : null;
    };

    $fastResolveUnitType = function(mysqli $conn, string $unitName, string $initial, int $branchId) use ($fastMoney): int {
        $unitName = trim($unitName) !== '' ? trim($unitName) : 'Piece';
        $initial = strtoupper(trim($initial));
        $stmt = $conn->prepare("SELECT unit_type_id FROM motorpool_unit_types WHERE unit_type_name = ? AND (branch_id = ? OR branch_id IS NULL OR branch_id = 0) ORDER BY CASE WHEN branch_id = ? THEN 0 WHEN branch_id IS NULL OR branch_id = 0 THEN 1 ELSE 2 END, unit_type_id ASC LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('sii', $unitName, $branchId, $branchId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row) return (int)$row['unit_type_id'];
        }
        $status = 'active';
        $qty = 1;
        $isDefault = 0;
        $stmt = $conn->prepare("INSERT INTO motorpool_unit_types (unit_type_name, uom_initial, quantity_smallest_pack, is_default_uom, multiplier, branch_id, status) VALUES (?, ?, ?, ?, 1.00, ?, ?)");
        if (!$stmt) throw new Exception('Unable to create UOM: ' . $conn->error);
        $stmt->bind_param('ssiiis', $unitName, $initial, $qty, $isDefault, $branchId, $status);
        if (!$stmt->execute()) throw new Exception('Unable to create UOM: ' . $stmt->error);
        $newId = (int)$conn->insert_id;
        $stmt->close();
        return $newId;
    };

    $fastUpsertInventory = function(mysqli $conn, int $itemId, int $unitId, float $qty, ?string $asOf, float $unitCost): void {
        $stmt = $conn->prepare("SELECT inventory_id FROM motorpool_item_unit_inventory WHERE item_id = ? AND unit_type_id = ? LIMIT 1");
        if (!$stmt) throw new Exception('Inventory check failed: ' . $conn->error);
        $stmt->bind_param('ii', $itemId, $unitId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $total = $qty * $unitCost;
        if ($row) {
            $id = (int)$row['inventory_id'];
            $stmt = $conn->prepare("UPDATE motorpool_item_unit_inventory SET current_inventory = ?, beginning_inventory = ?, as_of_date = ?, unit_cost = ?, total_cost = ?, status = 'active', updated_at = NOW() WHERE inventory_id = ?");
            if (!$stmt) throw new Exception('Inventory update failed: ' . $conn->error);
            $stmt->bind_param('ddsddi', $qty, $qty, $asOf, $unitCost, $total, $id);
        } else {
            $stmt = $conn->prepare("INSERT INTO motorpool_item_unit_inventory (item_id, unit_type_id, current_inventory, beginning_inventory, as_of_date, unit_cost, total_cost, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'active')");
            if (!$stmt) throw new Exception('Inventory insert failed: ' . $conn->error);
            $stmt->bind_param('iiddsdd', $itemId, $unitId, $qty, $qty, $asOf, $unitCost, $total);
        }
        if (!$stmt->execute()) throw new Exception('Inventory save failed: ' . $stmt->error);
        $stmt->close();
    };

    $fastUpsertUomRow = function(mysqli $conn, int $itemId, int $unitId, string $unitName, string $initial, string $barcode, float $smallestQty, int $isDefault, int $branchId, string $status): void {
        $stmt = $conn->prepare("SELECT item_unit_type_id FROM motorpool_item_unit_types WHERE item_id = ? AND unit_type_id = ? LIMIT 1");
        if (!$stmt) throw new Exception('UOM row check failed: ' . $conn->error);
        $stmt->bind_param('ii', $itemId, $unitId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $smallestQty = max(1, $smallestQty);
        $isDefault = $isDefault === 1 ? 1 : 0;
        $status = trim($status) !== '' ? trim($status) : 'active';
        if ($row) {
            $id = (int)$row['item_unit_type_id'];
            $stmt = $conn->prepare("UPDATE motorpool_item_unit_types SET unit_type_name = ?, uom_initial = ?, barcode = ?, smallest_pack_quantity = ?, is_default_uom = ?, branch_id = ?, status = ?, updated_at = NOW() WHERE item_unit_type_id = ?");
            if (!$stmt) throw new Exception('UOM row update failed: ' . $conn->error);
            $stmt->bind_param('sssdiisi', $unitName, $initial, $barcode, $smallestQty, $isDefault, $branchId, $status, $id);
        } else {
            $stmt = $conn->prepare("INSERT INTO motorpool_item_unit_types (item_id, unit_type_id, unit_type_name, uom_initial, barcode, smallest_pack_quantity, is_default_uom, branch_id, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
            if (!$stmt) throw new Exception('UOM row insert failed: ' . $conn->error);
            $stmt->bind_param('iisssdiis', $itemId, $unitId, $unitName, $initial, $barcode, $smallestQty, $isDefault, $branchId, $status);
        }
        if (!$stmt->execute()) throw new Exception('UOM row save failed: ' . $stmt->error);
        $stmt->close();
    };

    $fastUpsertPrice = function(mysqli $conn, int $itemId, int $unitId, string $priceLevel, float $price, int $unitQty, ?string $effectiveDate): void {
        $priceLevel = trim($priceLevel) !== '' ? trim($priceLevel) : 'Standard';
        $unitQty = max(1, $unitQty);
        $stmt = $conn->prepare("SELECT pricing_id FROM motorpool_item_unit_pricing WHERE item_id = ? AND unit_type_id = ? AND price_level = ? ORDER BY pricing_id DESC LIMIT 1");
        if (!$stmt) throw new Exception('Price check failed: ' . $conn->error);
        $stmt->bind_param('iis', $itemId, $unitId, $priceLevel);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            $id = (int)$row['pricing_id'];
            $stmt = $conn->prepare("UPDATE motorpool_item_unit_pricing SET unit_price = ?, unit_quantity = ?, effective_date = ?, updated_at = NOW() WHERE pricing_id = ?");
            if (!$stmt) throw new Exception('Price update failed: ' . $conn->error);
            $stmt->bind_param('disi', $price, $unitQty, $effectiveDate, $id);
        } else {
            $stmt = $conn->prepare("INSERT INTO motorpool_item_unit_pricing (item_id, unit_type_id, unit_price, unit_quantity, effective_date, price_level) VALUES (?, ?, ?, ?, ?, ?)");
            if (!$stmt) throw new Exception('Price insert failed: ' . $conn->error);
            $stmt->bind_param('iidiss', $itemId, $unitId, $price, $unitQty, $effectiveDate, $priceLevel);
        }
        if (!$stmt->execute()) throw new Exception('Price level save failed: ' . $stmt->error);
        $stmt->close();
    };

    try {
        $conn->begin_transaction();

        // Minimal table/column safety only. No heavy sync, no dedupe loops, no full page setup.
        $conn->query("CREATE TABLE IF NOT EXISTS motorpool_unit_types (unit_type_id INT AUTO_INCREMENT PRIMARY KEY, unit_type_name VARCHAR(100) NOT NULL, uom_initial VARCHAR(20) DEFAULT NULL, barcode VARCHAR(100) DEFAULT NULL, quantity_smallest_pack INT DEFAULT 1, is_default_uom TINYINT DEFAULT 0, description TEXT DEFAULT NULL, multiplier DECIMAL(10,2) DEFAULT 1.00, branch_id INT DEFAULT NULL, status VARCHAR(30) DEFAULT 'active', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, KEY idx_motorpool_unit_name (unit_type_name), KEY idx_motorpool_unit_branch (branch_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $conn->query("CREATE TABLE IF NOT EXISTS motorpool_item_unit_pricing (pricing_id INT AUTO_INCREMENT PRIMARY KEY, item_id INT NOT NULL, unit_type_id INT NOT NULL, unit_price DECIMAL(12,2) NOT NULL DEFAULT 0.00, unit_quantity INT DEFAULT 1, effective_date DATE DEFAULT NULL, price_level VARCHAR(50) DEFAULT 'Standard', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, KEY idx_motorpool_price_item_unit_level (item_id, unit_type_id, price_level)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $conn->query("CREATE TABLE IF NOT EXISTS motorpool_item_unit_inventory (inventory_id INT AUTO_INCREMENT PRIMARY KEY, item_id INT NOT NULL, unit_type_id INT NOT NULL, current_inventory DECIMAL(12,2) NOT NULL DEFAULT 0.00, beginning_inventory DECIMAL(12,2) NOT NULL DEFAULT 0.00, as_of_date DATE DEFAULT NULL, unit_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00, total_cost DECIMAL(14,2) NOT NULL DEFAULT 0.00, status VARCHAR(30) DEFAULT 'active', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, KEY idx_motorpool_inv_item_unit (item_id, unit_type_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $conn->query("CREATE TABLE IF NOT EXISTS motorpool_item_unit_types (item_unit_type_id INT AUTO_INCREMENT PRIMARY KEY, item_id INT NOT NULL, unit_type_id INT NOT NULL, unit_type_name VARCHAR(100) NOT NULL, uom_initial VARCHAR(20) DEFAULT NULL, barcode VARCHAR(100) DEFAULT NULL, smallest_pack_quantity DECIMAL(12,4) NOT NULL DEFAULT 1.0000, is_default_uom TINYINT(1) NOT NULL DEFAULT 0, branch_id INT DEFAULT NULL, status VARCHAR(30) DEFAULT 'active', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, KEY idx_motorpool_iut_item_unit (item_id, unit_type_id), KEY idx_motorpool_iut_barcode (barcode)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        foreach ([
            'principal' => "`principal` VARCHAR(150) DEFAULT NULL AFTER `category`",
            'volume' => "`volume` VARCHAR(100) DEFAULT NULL AFTER `principal`",
            'oil_type' => "`oil_type` VARCHAR(50) DEFAULT NULL AFTER `volume`",
            'income_account_id' => "`income_account_id` INT DEFAULT NULL AFTER `oil_type`",
            'cogs_account_id' => "`cogs_account_id` INT DEFAULT NULL AFTER `income_account_id`",
            'asset_account_id' => "`asset_account_id` INT DEFAULT NULL AFTER `cogs_account_id`",
            'barcode' => "`barcode` VARCHAR(120) DEFAULT NULL AFTER `unit_type`",
            'default_unit_type_id' => "`default_unit_type_id` INT DEFAULT NULL AFTER `item_image`",
            'branch_id' => "`branch_id` INT DEFAULT NULL AFTER `default_unit_type_id`",
            'points_eligible' => "`points_eligible` TINYINT(1) NOT NULL DEFAULT 1 AFTER `branch_id`",
            'product_image_url' => "`product_image_url` VARCHAR(255) DEFAULT NULL AFTER `supplier`",
            'updated_by' => "`updated_by` INT DEFAULT NULL AFTER `created_by`",
            'parent_item_id' => "`parent_item_id` INT DEFAULT NULL AFTER `updated_by`",
            'is_tire_serial_child' => "`is_tire_serial_child` TINYINT(1) NOT NULL DEFAULT 0 AFTER `parent_item_id`"
        ] as $col => $def) {
            $fastEnsureColumn($conn, 'motorpool_inventory_items', $col, $def);
        }
        foreach ([
            'beginning_inventory' => "`beginning_inventory` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `current_inventory`",
            'total_cost' => "`total_cost` DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER `unit_cost`",
            'status' => "`status` VARCHAR(30) DEFAULT 'active' AFTER `total_cost`"
        ] as $col => $def) {
            $fastEnsureColumn($conn, 'motorpool_item_unit_inventory', $col, $def);
        }

        $action = $_POST['action'] ?? '';
        $user_id = (int)($_SESSION['user_id'] ?? 0);
        $branch_id = (int)($_SESSION['branch_id'] ?? 0);
        $view_all_branches = (bool)($_SESSION['view_all_branches'] ?? false);
        $hasBranchColumn = $fastColumnExists($conn, 'motorpool_inventory_items', 'branch_id');

        $item_id = (int)($_POST['item_id'] ?? 0);
        $item_code = trim((string)($_POST['item_code'] ?? ''));
        $item_name = trim((string)($_POST['item_name'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        $category = trim((string)($_POST['category'] ?? 'General')) ?: 'General';
        $principal = trim((string)($_POST['principal'] ?? ''));
        $principal = ($principal !== '' && strtolower($principal) !== 'no principal') ? $principal : null;
        $volume = strtolower($category) === 'oil' ? trim((string)($_POST['volume'] ?? '')) : null;
        if ($volume === '') $volume = null;
        $oil_type = strtolower($category) === 'oil' ? trim((string)($_POST['oil_type'] ?? '')) : null;
        if ($oil_type === '') $oil_type = null;
        $income_account_id = (int)($_POST['income_account_id'] ?? 0);
        $cogs_account_id = (int)($_POST['cogs_account_id'] ?? 0);
        $asset_account_id = (int)($_POST['asset_account_id'] ?? 0);
        $reorder_level = $fastMoney($_POST['reorder_level'] ?? 0);
        $status = trim((string)($_POST['status'] ?? 'active')) ?: 'active';
        $points_eligible = isset($_POST['points_eligible']) && (int)$_POST['points_eligible'] === 0 ? 0 : 1;
        $barcode = trim((string)($_POST['barcode'] ?? ''));
        $is_tire_serial_child = isset($_POST['is_tire_serial_child']) && (int)$_POST['is_tire_serial_child'] === 1 ? 1 : 0;
        $parent_item_id = (int)($_POST['parent_item_id'] ?? 0);
        if ($is_tire_serial_child === 1) {
            if ($parent_item_id <= 0) throw new Exception('Invalid parent Tire item.');
            $parentStmt = $conn->prepare("SELECT item_id, item_name, category FROM motorpool_inventory_items WHERE item_id = ? AND COALESCE(status,'active') <> 'deleted' LIMIT 1");
            if (!$parentStmt) throw new Exception('Unable to check parent Tire item: ' . $conn->error);
            $parentStmt->bind_param('i', $parent_item_id);
            $parentStmt->execute();
            $parentRow = $parentStmt->get_result()->fetch_assoc();
            $parentStmt->close();
            if (!$parentRow) throw new Exception('Parent Tire item not found.');
            $parentCategory = strtolower(trim((string)($parentRow['category'] ?? '')));
            if (!in_array($parentCategory, ['tire','tires'], true)) throw new Exception('Serial item can only be added under Tire category.');
            $category = 'Tire';
        }

        $uoms = json_decode((string)($_POST['motorpool_unit_types'] ?? '[]'), true);
        $pricing = json_decode((string)($_POST['pricing'] ?? '[]'), true);
        if (!is_array($uoms) || count($uoms) === 0) throw new Exception('At least one unit type with price is required.');
        if (!is_array($pricing)) $pricing = [];
        if ($item_name === '') throw new Exception('Item name is required.');
        if ($description === '') $description = $item_name;

        $first = $uoms[0];
        $defaultUnitName = trim((string)($first['unit_type'] ?? 'Piece')) ?: 'Piece';
        $defaultPrice = $fastMoney($first['unit_price'] ?? 0);
        $imageName = $fastUploadOne('item_image');

        if ($action === 'add_item' && $is_tire_serial_child === 1) {
            $bulkRaw = trim((string)($_POST['serial_numbers_bulk'] ?? ''));
            $serialLines = [];
            if ($bulkRaw !== '') {
                foreach (preg_split('/\r\n|\r|\n/', $bulkRaw) as $line) {
                    $line = trim($line);
                    if ($line !== '') $serialLines[] = $line;
                }
            }
            if (count($serialLines) === 0) $serialLines[] = $item_name;
            if (count($serialLines) === 1 && trim((string)$serialLines[0]) === '') throw new Exception('At least one serial number is required.');

            $createdIds = [];
            $baseCode = $item_code !== '' ? $item_code : ('TIRE-' . date('YmdHis'));
            $whereBranch = ($hasBranchColumn && !$view_all_branches && $branch_id > 0) ? ' AND branch_id = ?' : '';
            $rowNo = 0;

            foreach ($serialLines as $serialLine) {
                $rowNo++;
                $parts = array_map('trim', explode('|', $serialLine));
                if (count($parts) >= 2 && $parts[0] !== '' && $parts[1] !== '') {
                    $childCode = $parts[0];
                    $childName = $parts[1];
                } else {
                    $childName = $serialLine;
                    $safeSerial = preg_replace('/[^A-Za-z0-9_-]+/', '-', $childName);
                    $safeSerial = trim($safeSerial, '-');
                    $childCode = count($serialLines) > 1 ? ($baseCode . '-' . ($safeSerial !== '' ? $safeSerial : str_pad((string)$rowNo, 3, '0', STR_PAD_LEFT))) : $baseCode;
                }
                if ($childName === '') continue;
                $childDesc = $description !== '' ? $description : $childName;

                $stmt = $conn->prepare("SELECT item_id FROM motorpool_inventory_items WHERE item_code = ? $whereBranch LIMIT 1");
                if (!$stmt) throw new Exception('Item code check failed: ' . $conn->error);
                if ($whereBranch !== '') $stmt->bind_param('si', $childCode, $branch_id); else $stmt->bind_param('s', $childCode);
                $stmt->execute();
                if ($stmt->get_result()->fetch_assoc()) { $stmt->close(); throw new Exception('Item code already exists: ' . $childCode); }
                $stmt->close();

                if ($hasBranchColumn) {
                    $stmt = $conn->prepare("INSERT INTO motorpool_inventory_items (item_code, barcode, item_name, description, category, principal, volume, oil_type, income_account_id, cogs_account_id, asset_account_id, stock, current_stock, unit_type, unit_price, price_case, price_inner_pack, price_box, price_carton, reorder_level, status, product_image_url, branch_id, points_eligible, created_by, updated_by, parent_item_id, is_tire_serial_child, created_at, updated_at) VALUES (?, ?, ?, ?, 'Tire', ?, ?, ?, ?, ?, ?, 0, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())");
                    if (!$stmt) throw new Exception('Add tire serial item prepare failed: ' . $conn->error);
                    $pcase=$defaultPrice*12; $pinner=$defaultPrice*6; $pbox=$defaultPrice*24; $pcarton=$defaultPrice*48;
                    $stmt->bind_param('sssssssiiisddddddssiiiii', $childCode, $barcode, $childName, $childDesc, $principal, $volume, $oil_type, $income_account_id, $cogs_account_id, $asset_account_id, $defaultUnitName, $defaultPrice, $pcase, $pinner, $pbox, $pcarton, $reorder_level, $status, $imageName, $branch_id, $points_eligible, $user_id, $user_id, $parent_item_id);
                } else {
                    $stmt = $conn->prepare("INSERT INTO motorpool_inventory_items (item_code, barcode, item_name, description, category, principal, volume, oil_type, income_account_id, cogs_account_id, asset_account_id, stock, current_stock, unit_type, unit_price, price_case, price_inner_pack, price_box, price_carton, reorder_level, status, product_image_url, points_eligible, created_by, updated_by, parent_item_id, is_tire_serial_child, created_at, updated_at) VALUES (?, ?, ?, ?, 'Tire', ?, ?, ?, ?, ?, ?, 0, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())");
                    if (!$stmt) throw new Exception('Add tire serial item prepare failed: ' . $conn->error);
                    $pcase=$defaultPrice*12; $pinner=$defaultPrice*6; $pbox=$defaultPrice*24; $pcarton=$defaultPrice*48;
                    $stmt->bind_param('sssssssiiisddddddssiiii', $childCode, $barcode, $childName, $childDesc, $principal, $volume, $oil_type, $income_account_id, $cogs_account_id, $asset_account_id, $defaultUnitName, $defaultPrice, $pcase, $pinner, $pbox, $pcarton, $reorder_level, $status, $imageName, $points_eligible, $user_id, $user_id, $parent_item_id);
                }
                if (!$stmt->execute()) throw new Exception('Failed to add tire serial item: ' . $stmt->error);
                $childItemId = (int)$conn->insert_id;
                $stmt->close();
                $createdIds[] = $childItemId;

                $defaultUnitId = 0;
                $firstUnitId = 0;
                $unitNameToId = [];
                $unitIdToQty = [];
                $totalStock = 0.0;
                $totalCostStock = 0.0;
                foreach ($uoms as $u) {
                    if (!is_array($u)) continue;
                    $unitName = trim((string)($u['unit_type'] ?? 'Piece')) ?: 'Piece';
                    $initial = strtoupper(trim((string)($u['uom_initial'] ?? '')));
                    $uBarcode = trim((string)($u['barcode'] ?? ''));
                    $smallest = $fastMoney($u['qty_smallest_pack'] ?? ($u['smallest_pack_quantity'] ?? 1));
                    $isDefault = (int)($u['default_uom'] ?? $u['is_default_uom'] ?? 0) === 1 ? 1 : 0;
                    $uStatus = trim((string)($u['status'] ?? 'active')) ?: 'active';
                    $qty = $fastMoney($u['current_inventory'] ?? 1);
                    if ($qty <= 0) $qty = 1;
                    $asOf = $fastDate($u['as_of_date'] ?? null);
                    $unitCost = $fastMoney($u['unit_cost'] ?? ($u['unit_price'] ?? 0));
                    $unitPrice = $fastMoney($u['unit_price'] ?? 0);
                    $totalCostStock += ($qty * $unitCost);
                    $unitQty = max(1, (int)$fastMoney($u['unit_quantity'] ?? 1));
                    $unitId = $fastResolveUnitType($conn, $unitName, $initial, $branch_id);
                    if ($firstUnitId <= 0) $firstUnitId = $unitId;
                    if ($isDefault === 1) $defaultUnitId = $unitId;
                    $unitNameToId[strtolower($unitName)] = $unitId;
                    $unitNameToId[$unitName] = $unitId;
                    $unitIdToQty[$unitId] = $unitQty;
                    $totalStock += $qty;
                    $fastUpsertInventory($conn, $childItemId, $unitId, $qty, $asOf, $unitCost);
                    $fastUpsertUomRow($conn, $childItemId, $unitId, $unitName, $initial, $uBarcode, $smallest, $isDefault, $branch_id, $uStatus);
                    $fastUpsertPrice($conn, $childItemId, $unitId, 'Standard', $unitPrice, $unitQty, null);
                }
                if ($defaultUnitId <= 0) $defaultUnitId = $firstUnitId;
                foreach ($pricing as $pr) {
                    if (!is_array($pr)) continue;
                    $priceLevel = trim((string)($pr['price_level'] ?? 'Standard')) ?: 'Standard';
                    $effectiveDate = $fastDate($pr['effective_date'] ?? null);
                    $prices = isset($pr['prices']) && is_array($pr['prices']) ? $pr['prices'] : [];
                    foreach ($prices as $key => $value) {
                        if (trim((string)$value) === '') continue;
                        $lookupKey = trim((string)$key);
                        $unitId = 0;
                        if (is_numeric($lookupKey)) $unitId = (int)$lookupKey;
                        if ($unitId <= 0 && isset($unitNameToId[$lookupKey])) $unitId = (int)$unitNameToId[$lookupKey];
                        if ($unitId <= 0 && isset($unitNameToId[strtolower($lookupKey)])) $unitId = (int)$unitNameToId[strtolower($lookupKey)];
                        if ($unitId <= 0) continue;
                        $fastUpsertPrice($conn, $childItemId, $unitId, $priceLevel, $fastMoney($value), $unitIdToQty[$unitId] ?? 1, $effectiveDate);
                    }
                }
                if ($defaultUnitId > 0) {
                    $stmt = $conn->prepare("UPDATE motorpool_inventory_items SET default_unit_type_id = ?, stock = ?, current_stock = ?, total_cost = ?, unit_cost = CASE WHEN ? > 0 THEN ? / ? ELSE unit_cost END, updated_at = NOW() WHERE item_id = ?");
                    if ($stmt) { $stmt->bind_param('iddddddi', $defaultUnitId, $totalStock, $totalStock, $totalCostStock, $totalStock, $totalCostStock, $totalStock, $childItemId); $stmt->execute(); $stmt->close(); }
                } else {
                    $stmt = $conn->prepare("UPDATE motorpool_inventory_items SET stock = ?, current_stock = ?, total_cost = ?, unit_cost = CASE WHEN ? > 0 THEN ? / ? ELSE unit_cost END, updated_at = NOW() WHERE item_id = ?");
                    if ($stmt) { $stmt->bind_param('ddddddi', $totalStock, $totalStock, $totalCostStock, $totalStock, $totalCostStock, $totalStock, $childItemId); $stmt->execute(); $stmt->close(); }
                }
            }

            $sumStmt = $conn->prepare("SELECT COALESCE(SUM(COALESCE(NULLIF(total_cost,0), current_stock * unit_cost)),0) cost, COALESCE(SUM(current_stock),0) qty FROM motorpool_inventory_items WHERE parent_item_id=? AND COALESCE(is_tire_serial_child,0)=1 AND COALESCE(status,'active')='active'");
            if ($sumStmt) {
                $sumStmt->bind_param('i', $parent_item_id);
                $sumStmt->execute();
                $sum = $sumStmt->get_result()->fetch_assoc();
                $sumStmt->close();
                $newQty = (float)($sum['qty'] ?? 0);
                $newCost = (float)($sum['cost'] ?? 0);
                $upd = $conn->prepare("UPDATE motorpool_inventory_items SET stock=?, current_stock=?, total_cost=?, updated_at=NOW() WHERE item_id=?");
                if ($upd) { $upd->bind_param('dddi', $newQty, $newQty, $newCost, $parent_item_id); $upd->execute(); $upd->close(); }
            }

            $conn->commit();
            $fastRespond(['success' => true, 'message' => count($createdIds) . ' Tire serial item(s) added successfully.', 'item_ids' => $createdIds]);
        }

        if ($action === 'add_item') {
            if ($item_code === '') throw new Exception('Item code is required.');
            $whereBranch = ($hasBranchColumn && !$view_all_branches && $branch_id > 0) ? ' AND branch_id = ?' : '';
            $stmt = $conn->prepare("SELECT item_id FROM motorpool_inventory_items WHERE item_code = ? $whereBranch LIMIT 1");
            if (!$stmt) throw new Exception('Item code check failed: ' . $conn->error);
            if ($whereBranch !== '') $stmt->bind_param('si', $item_code, $branch_id); else $stmt->bind_param('s', $item_code);
            $stmt->execute();
            if ($stmt->get_result()->fetch_assoc()) throw new Exception('Item code already exists.');
            $stmt->close();

            if ($hasBranchColumn) {
                $stmt = $conn->prepare("INSERT INTO motorpool_inventory_items (item_code, barcode, item_name, description, category, principal, volume, oil_type, income_account_id, cogs_account_id, asset_account_id, stock, current_stock, unit_type, unit_price, price_case, price_inner_pack, price_box, price_carton, reorder_level, status, product_image_url, branch_id, points_eligible, created_by, updated_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
                if (!$stmt) throw new Exception('Add item prepare failed: ' . $conn->error);
                $pcase=$defaultPrice*12; $pinner=$defaultPrice*6; $pbox=$defaultPrice*24; $pcarton=$defaultPrice*48;
                $stmt->bind_param('ssssssssiiisddddddssiiii', $item_code, $barcode, $item_name, $description, $category, $principal, $volume, $oil_type, $income_account_id, $cogs_account_id, $asset_account_id, $defaultUnitName, $defaultPrice, $pcase, $pinner, $pbox, $pcarton, $reorder_level, $status, $imageName, $branch_id, $points_eligible, $user_id, $user_id);
            } else {
                $stmt = $conn->prepare("INSERT INTO motorpool_inventory_items (item_code, barcode, item_name, description, category, principal, volume, oil_type, income_account_id, cogs_account_id, asset_account_id, stock, current_stock, unit_type, unit_price, price_case, price_inner_pack, price_box, price_carton, reorder_level, status, product_image_url, points_eligible, created_by, updated_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
                if (!$stmt) throw new Exception('Add item prepare failed: ' . $conn->error);
                $pcase=$defaultPrice*12; $pinner=$defaultPrice*6; $pbox=$defaultPrice*24; $pcarton=$defaultPrice*48;
                $stmt->bind_param('ssssssssiiisddddddssiii', $item_code, $barcode, $item_name, $description, $category, $principal, $volume, $oil_type, $income_account_id, $cogs_account_id, $asset_account_id, $defaultUnitName, $defaultPrice, $pcase, $pinner, $pbox, $pcarton, $reorder_level, $status, $imageName, $points_eligible, $user_id, $user_id);
            }
            if (!$stmt->execute()) throw new Exception('Failed to add item: ' . $stmt->error);
            $item_id = (int)$conn->insert_id;
            $stmt->close();
        } else {
            if ($item_id <= 0) throw new Exception('Invalid item ID.');
            $setImage = $imageName !== null ? ', product_image_url = ?' : '';
            $sql = "UPDATE motorpool_inventory_items SET barcode = ?, item_name = ?, description = ?, category = ?, principal = ?, volume = ?, oil_type = ?, income_account_id = ?, cogs_account_id = ?, asset_account_id = ?, unit_type = ?, unit_price = ?, price_case = ?, price_inner_pack = ?, price_box = ?, price_carton = ?, reorder_level = ?, status = ?, points_eligible = ?, updated_by = ?, updated_at = NOW() $setImage WHERE item_id = ?";
            $stmt = $conn->prepare($sql);
            if (!$stmt) throw new Exception('Update item prepare failed: ' . $conn->error);
            $pcase=$defaultPrice*12; $pinner=$defaultPrice*6; $pbox=$defaultPrice*24; $pcarton=$defaultPrice*48;
            if ($imageName !== null) {
                $stmt->bind_param('sssssssiiisddddddsiisi', $barcode, $item_name, $description, $category, $principal, $volume, $oil_type, $income_account_id, $cogs_account_id, $asset_account_id, $defaultUnitName, $defaultPrice, $pcase, $pinner, $pbox, $pcarton, $reorder_level, $status, $points_eligible, $user_id, $imageName, $item_id);
            } else {
                $stmt->bind_param('sssssssiiisddddddsiii', $barcode, $item_name, $description, $category, $principal, $volume, $oil_type, $income_account_id, $cogs_account_id, $asset_account_id, $defaultUnitName, $defaultPrice, $pcase, $pinner, $pbox, $pcarton, $reorder_level, $status, $points_eligible, $user_id, $item_id);
            }
            if (!$stmt->execute()) throw new Exception('Failed to update item: ' . $stmt->error);
            $stmt->close();

            // Remove UOM rows not submitted anymore, but keep pricing history table untouched.
            $submittedNames = [];
            foreach ($uoms as $u) { $nm = trim((string)($u['unit_type'] ?? '')); if ($nm !== '') $submittedNames[] = strtolower($nm); }
        }

        if ($fastColumnExists($conn, 'motorpool_inventory_items', 'parent_item_id') && $fastColumnExists($conn, 'motorpool_inventory_items', 'is_tire_serial_child')) {
            if ($is_tire_serial_child === 1) {
                $childUpd = $conn->prepare("UPDATE motorpool_inventory_items SET parent_item_id = ?, is_tire_serial_child = 1, category = 'Tire', updated_at = NOW() WHERE item_id = ?");
                if ($childUpd) {
                    $childUpd->bind_param('ii', $parent_item_id, $item_id);
                    $childUpd->execute();
                    $childUpd->close();
                }
            } else {
                $childUpd = $conn->prepare("UPDATE motorpool_inventory_items SET parent_item_id = NULL, is_tire_serial_child = 0, updated_at = NOW() WHERE item_id = ?");
                if ($childUpd) {
                    $childUpd->bind_param('i', $item_id);
                    $childUpd->execute();
                    $childUpd->close();
                }
            }
        }

        $defaultUnitId = 0;
        $firstUnitId = 0;
        $unitNameToId = [];
        $unitIdToQty = [];
        $totalStock = 0.0;

        foreach ($uoms as $u) {
            if (!is_array($u)) continue;
            $unitName = trim((string)($u['unit_type'] ?? 'Piece')) ?: 'Piece';
            $initial = strtoupper(trim((string)($u['uom_initial'] ?? '')));
            $uBarcode = trim((string)($u['barcode'] ?? ''));
            $smallest = $fastMoney($u['qty_smallest_pack'] ?? ($u['smallest_pack_quantity'] ?? 1));
            $isDefault = (int)($u['default_uom'] ?? $u['is_default_uom'] ?? 0) === 1 ? 1 : 0;
            $uStatus = trim((string)($u['status'] ?? 'active')) ?: 'active';
            $qty = $fastMoney($u['current_inventory'] ?? 0);
            $asOf = $fastDate($u['as_of_date'] ?? null);
            $unitCost = $fastMoney($u['unit_cost'] ?? ($u['unit_price'] ?? 0));
            $unitPrice = $fastMoney($u['unit_price'] ?? 0);
            $unitQty = max(1, (int)$fastMoney($u['unit_quantity'] ?? 1));
            $unitId = $fastResolveUnitType($conn, $unitName, $initial, $branch_id);
            if ($firstUnitId <= 0) $firstUnitId = $unitId;
            if ($isDefault === 1) $defaultUnitId = $unitId;
            $unitNameToId[strtolower($unitName)] = $unitId;
            $unitNameToId[$unitName] = $unitId;
            $unitIdToQty[$unitId] = $unitQty;
            $totalStock += $qty;

            $fastUpsertInventory($conn, $item_id, $unitId, $qty, $asOf, $unitCost);
            $fastUpsertUomRow($conn, $item_id, $unitId, $unitName, $initial, $uBarcode, $smallest, $isDefault, $branch_id, $uStatus);

            // Always save base unit price as Standard so it appears even when price level rows are empty.
            $fastUpsertPrice($conn, $item_id, $unitId, 'Standard', $unitPrice, $unitQty, null);
        }
        if ($defaultUnitId <= 0) $defaultUnitId = $firstUnitId;

        // Save all custom price levels from the pricing grid.
        foreach ($pricing as $pr) {
            if (!is_array($pr)) continue;
            $priceLevel = trim((string)($pr['price_level'] ?? 'Standard')) ?: 'Standard';
            $effectiveDate = $fastDate($pr['effective_date'] ?? null);
            $prices = isset($pr['prices']) && is_array($pr['prices']) ? $pr['prices'] : [];
            foreach ($prices as $key => $value) {
                if (trim((string)$value) === '') continue;
                $lookupKey = trim((string)$key);
                $unitId = 0;
                if (is_numeric($lookupKey)) $unitId = (int)$lookupKey;
                if ($unitId <= 0 && isset($unitNameToId[$lookupKey])) $unitId = (int)$unitNameToId[$lookupKey];
                if ($unitId <= 0 && isset($unitNameToId[strtolower($lookupKey)])) $unitId = (int)$unitNameToId[strtolower($lookupKey)];
                if ($unitId <= 0) continue;
                $fastUpsertPrice($conn, $item_id, $unitId, $priceLevel, $fastMoney($value), $unitIdToQty[$unitId] ?? 1, $effectiveDate);
            }
        }

        if ($defaultUnitId > 0) {
            $defaultBarcode = '';
            $stmt = $conn->prepare("SELECT barcode FROM motorpool_item_unit_types WHERE item_id = ? AND unit_type_id = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('ii', $item_id, $defaultUnitId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $defaultBarcode = trim((string)($row['barcode'] ?? ''));
            }
            $stmt = $conn->prepare("UPDATE motorpool_inventory_items SET default_unit_type_id = ?, stock = ?, current_stock = ?, barcode = CASE WHEN ? <> '' THEN ? ELSE barcode END, updated_at = NOW() WHERE item_id = ?");
            if ($stmt) {
                $stmt->bind_param('iddssi', $defaultUnitId, $totalStock, $totalStock, $defaultBarcode, $defaultBarcode, $item_id);
                $stmt->execute();
                $stmt->close();
            }
        }

        $conn->commit();
        $fastRespond(['success' => true, 'message' => $action === 'add_item' ? 'Item added successfully.' : 'Item updated successfully.', 'item_id' => $item_id]);
    } catch (Throwable $e) {
        if ($conn && $conn->errno === 0) { /* connection still usable */ }
        @$conn->rollback();
        error_log('Fast motorpool item save error: ' . $e->getMessage());
        $fastRespond(['success' => false, 'message' => $e->getMessage()]);
    }
}

// ========== FAST AJAX HANDLER: BATCH UPDATE PRICE LEVEL LOADER ==========
// This runs before the long page setup so the Batch Update modal will not stay on Loading.
// It uses Motorpool tables only and does NOT create fake Standard price rows.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'get_batch_price_level_items') {
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/json');
    ini_set('display_errors', 0);
    error_reporting(E_ALL);

    try {
        $ajax_branch_id = (int)($_SESSION['branch_id'] ?? 0);
        $ajax_view_all_branches = (bool)($_SESSION['view_all_branches'] ?? false);
        $price_level = trim((string)($_POST['price_level'] ?? 'Standard'));
        $effective_date = trim((string)($_POST['effective_date'] ?? date('Y-m-d')));
        if ($price_level === '') $price_level = 'Standard';
        if ($effective_date === '') $effective_date = date('Y-m-d');

        // Small safety checks only. Do not run the whole page setup for this AJAX request.
        $table_check = $conn->query("SHOW TABLES LIKE 'motorpool_inventory_items'");
        if (!$table_check || $table_check->num_rows === 0) {
            echo json_encode(['success' => true, 'items' => [], 'message' => 'No inventory table found.']);
            exit;
        }

        $has_branch_column = false;
        $branch_col_check = $conn->query("SHOW COLUMNS FROM motorpool_inventory_items LIKE 'branch_id'");
        if ($branch_col_check && $branch_col_check->num_rows > 0) $has_branch_column = true;

        $piece_unit_type_id = 0;
        $uom_check = $conn->query("SHOW TABLES LIKE 'motorpool_unit_types'");
        if ($uom_check && $uom_check->num_rows > 0) {
            @$conn->query("INSERT IGNORE INTO motorpool_unit_types (unit_type_name, uom_initial, quantity_smallest_pack, is_default_uom, multiplier, branch_id, status) VALUES ('Piece', 'PC', 1, 1, 1.00, NULL, 'active')");
            if ($pieceRes = $conn->query("SELECT unit_type_id FROM motorpool_unit_types WHERE unit_type_name='Piece' ORDER BY unit_type_id ASC LIMIT 1")) {
                if ($pieceRow = $pieceRes->fetch_assoc()) $piece_unit_type_id = (int)$pieceRow['unit_type_id'];
            }
        }
        if ($piece_unit_type_id <= 0) $piece_unit_type_id = 1;

        // This query is based on Branch Current Inventory behavior:
        // Load from inventory/UOM/default item rows first, then LEFT JOIN price rows.
        // No INNER JOIN to Standard pricing, so items still appear even if no price level exists.
        $query = "
            SELECT
                i.item_id,
                COALESCE(i.item_code, '') AS item_code,
                COALESCE(i.item_name, '') AS item_name,
                COALESCE(i.description, '') AS description,
                COALESCE(b.unit_type_id, i.default_unit_type_id, $piece_unit_type_id) AS unit_type_id,
                COALESCE(ut.unit_type_name, iut.unit_type_name, NULLIF(i.unit_type,''), 'Piece') AS unit_type_name,
                COALESCE(selected_schedule.unit_quantity, selected_current.unit_quantity, standard_schedule.unit_quantity, standard_current.unit_quantity, 1) AS unit_quantity,
                CASE
                    WHEN selected_schedule.schedule_id IS NOT NULL THEN selected_schedule.unit_price
                    WHEN selected_current.pricing_id IS NOT NULL THEN selected_current.unit_price
                    WHEN standard_schedule.schedule_id IS NOT NULL THEN standard_schedule.unit_price
                    WHEN standard_current.pricing_id IS NOT NULL THEN standard_current.unit_price
                    ELSE NULL
                END AS current_price,
                CASE
                    WHEN selected_schedule.schedule_id IS NOT NULL THEN selected_schedule.unit_price
                    WHEN selected_current.pricing_id IS NOT NULL THEN selected_current.unit_price
                    ELSE NULL
                END AS editable_price,
                CASE
                    WHEN selected_schedule.schedule_id IS NOT NULL OR selected_current.pricing_id IS NOT NULL OR standard_schedule.schedule_id IS NOT NULL OR standard_current.pricing_id IS NOT NULL THEN 1
                    ELSE 0
                END AS has_existing_price,
                CASE
                    WHEN selected_schedule.schedule_id IS NOT NULL OR selected_current.pricing_id IS NOT NULL OR standard_schedule.schedule_id IS NOT NULL OR standard_current.pricing_id IS NOT NULL THEN ''
                    ELSE 'No existing price levels'
                END AS current_price_label,
                COALESCE(inv.current_inventory, i.stock, i.current_stock, 0) AS current_inventory
            FROM motorpool_inventory_items i
            LEFT JOIN (
                SELECT item_id, unit_type_id FROM motorpool_item_unit_inventory
                UNION
                SELECT item_id, unit_type_id FROM motorpool_item_unit_types WHERE COALESCE(status,'active') = 'active'
                UNION
                SELECT item_id, unit_type_id FROM motorpool_item_unit_pricing
                UNION
                SELECT item_id, COALESCE(default_unit_type_id, $piece_unit_type_id) AS unit_type_id FROM motorpool_inventory_items
            ) b ON b.item_id = i.item_id AND b.unit_type_id IS NOT NULL AND b.unit_type_id > 0
            LEFT JOIN motorpool_unit_types ut ON ut.unit_type_id = COALESCE(b.unit_type_id, i.default_unit_type_id, $piece_unit_type_id)
            LEFT JOIN motorpool_item_unit_types iut ON iut.item_id = i.item_id AND iut.unit_type_id = COALESCE(b.unit_type_id, i.default_unit_type_id, $piece_unit_type_id)
            LEFT JOIN motorpool_item_unit_inventory inv ON inv.item_id = i.item_id AND inv.unit_type_id = COALESCE(b.unit_type_id, i.default_unit_type_id, $piece_unit_type_id)
            LEFT JOIN motorpool_item_unit_pricing standard_current
                ON standard_current.item_id = i.item_id
                AND standard_current.unit_type_id = COALESCE(b.unit_type_id, i.default_unit_type_id, $piece_unit_type_id)
                AND standard_current.price_level = 'Standard'
            LEFT JOIN motorpool_item_unit_pricing selected_current
                ON selected_current.item_id = i.item_id
                AND selected_current.unit_type_id = COALESCE(b.unit_type_id, i.default_unit_type_id, $piece_unit_type_id)
                AND selected_current.price_level = ?
            LEFT JOIN motorpool_item_unit_pricing_schedule selected_schedule
                ON selected_schedule.item_id = i.item_id
                AND selected_schedule.unit_type_id = COALESCE(b.unit_type_id, i.default_unit_type_id, $piece_unit_type_id)
                AND selected_schedule.price_level = ?
                AND selected_schedule.effective_date = ?
            LEFT JOIN motorpool_item_unit_pricing_schedule standard_schedule
                ON standard_schedule.item_id = i.item_id
                AND standard_schedule.unit_type_id = COALESCE(b.unit_type_id, i.default_unit_type_id, $piece_unit_type_id)
                AND standard_schedule.price_level = 'Standard'
                AND standard_schedule.effective_date = ?
            WHERE COALESCE(i.status,'active') = 'active'";
        if ($has_branch_column && !$ajax_view_all_branches && $ajax_branch_id > 0) {
            $query .= " AND (i.branch_id = " . (int)$ajax_branch_id . " OR i.branch_id IS NULL OR i.branch_id = 0)";
        }
        $query .= " GROUP BY i.item_id, unit_type_id ORDER BY i.item_name ASC, unit_type_name ASC";

        $stmt = $conn->prepare($query);
        if (!$stmt) throw new Exception('Unable to prepare batch price loader: ' . $conn->error);
        $stmt->bind_param('ssss', $price_level, $price_level, $effective_date, $effective_date);
        $stmt->execute();
        $result = $stmt->get_result();
        $items = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();

        echo json_encode(['success' => true, 'items' => $items, 'message' => 'Loaded ' . count($items) . ' item(s).']);
        exit;
    } catch (Throwable $e) {
        error_log('Fast batch price loader error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'items' => [], 'message' => 'Batch price loader error: ' . $e->getMessage()]);
        exit;
    }
}




// ========== ULTRA FAST AJAX HANDLERS (NO FULL PAGE SETUP) ==========
// These common loaders return JSON before the heavy compatibility/page queries run.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $ultra_action = (string)($_POST['action'] ?? '');
    $ultra_actions = [
        'get_item',
        'get_motorpool_unit_types',
        'get_motorpool_item_unit_types',
        'get_motorpool_item_images',
        'get_suppliers',
        'get_supplier_details',
        'delete_item_image',
        'delete_item',
        'get_low_stock_items'
    ];

    if (in_array($ultra_action, $ultra_actions, true)) {
        while (ob_get_level() > 0) { ob_end_clean(); }
        header('Content-Type: application/json');
        ini_set('display_errors', 0);
        error_reporting(E_ALL);

        $jsonOut = function(array $payload): void {
            echo json_encode($payload);
            exit;
        };
        $colExistsFast = function(mysqli $conn, string $table, string $column): bool {
            $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
            $column = $conn->real_escape_string($column);
            $res = @$conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
            return $res && $res->num_rows > 0;
        };

        try {
            $branch_id_fast = (int)($_SESSION['branch_id'] ?? 0);
            $view_all_fast = (bool)($_SESSION['view_all_branches'] ?? false);
            $has_item_branch_fast = $colExistsFast($conn, 'motorpool_inventory_items', 'branch_id');

            if ($ultra_action === 'get_motorpool_unit_types') {
                $rows = [];
                $res = $conn->query("SELECT unit_type_id, unit_type_name, uom_initial, '' AS barcode, quantity_smallest_pack, is_default_uom, status FROM motorpool_unit_types WHERE COALESCE(status,'active') = 'active' ORDER BY unit_type_name ASC");
                if ($res) $rows = $res->fetch_all(MYSQLI_ASSOC);
                $jsonOut(['success' => true, 'unit_types' => $rows, 'motorpool_unit_types' => $rows]);
            }

            if ($ultra_action === 'get_suppliers') {
                $rows = [];
                $sql = "SELECT DISTINCT supplier AS supplier_name, supplier AS supplier_key FROM motorpool_inventory_items WHERE supplier IS NOT NULL AND TRIM(supplier) <> ''";
                if ($has_item_branch_fast && !$view_all_fast && $branch_id_fast > 0) {
                    $sql .= " AND (branch_id = " . intval($branch_id_fast) . " OR branch_id IS NULL OR branch_id = 0)";
                }
                $sql .= " ORDER BY supplier ASC";
                $res = $conn->query($sql);
                if ($res) {
                    while ($r = $res->fetch_assoc()) {
                        $rows[] = [
                            'supplier_id' => $r['supplier_key'],
                            'supplier_key' => $r['supplier_key'],
                            'supplier_name' => $r['supplier_name']
                        ];
                    }
                }
                $jsonOut(['success' => true, 'suppliers' => $rows]);
            }

            if ($ultra_action === 'get_supplier_details') {
                $name = trim((string)($_POST['supplier_id'] ?? $_POST['supplier_key'] ?? $_POST['supplier_name'] ?? ''));
                $jsonOut(['success' => true, 'supplier' => ['supplier_id' => $name, 'supplier_key' => $name, 'supplier_name' => $name]]);
            }

            if ($ultra_action === 'get_motorpool_item_images') {
                $item_id = (int)($_POST['item_id'] ?? 0);
                if ($item_id <= 0) throw new Exception('Invalid item ID');
                $images = [];
                if ($stmt = $conn->prepare("SELECT image_id, image_path, image_order, is_primary FROM motorpool_item_images WHERE item_id = ? ORDER BY image_order ASC, is_primary DESC")) {
                    $stmt->bind_param('i', $item_id);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    $images = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
                    $stmt->close();
                }
                if (empty($images)) {
                    if ($stmt = $conn->prepare("SELECT product_image_url, item_image FROM motorpool_inventory_items WHERE item_id = ? LIMIT 1")) {
                        $stmt->bind_param('i', $item_id);
                        $stmt->execute();
                        if ($row = $stmt->get_result()->fetch_assoc()) {
                            $path = trim((string)($row['product_image_url'] ?? ''));
                            if ($path === '') $path = trim((string)($row['item_image'] ?? ''));
                            if ($path !== '') $images[] = ['image_id' => 0, 'image_path' => $path, 'image_order' => 0, 'is_primary' => 1];
                        }
                        $stmt->close();
                    }
                }
                $jsonOut(['success' => true, 'images' => $images]);
            }

            if ($ultra_action === 'delete_item_image') {
                $image_id = (int)($_POST['image_id'] ?? 0);
                if ($image_id <= 0) throw new Exception('Invalid image ID');
                if ($stmt = $conn->prepare("DELETE FROM motorpool_item_images WHERE image_id = ?")) {
                    $stmt->bind_param('i', $image_id);
                    $stmt->execute();
                    $stmt->close();
                }
                $jsonOut(['success' => true, 'message' => 'Image deleted.']);
            }

            if ($ultra_action === 'delete_item') {
                $item_id = (int)($_POST['item_id'] ?? 0);
                if ($item_id <= 0) throw new Exception('Invalid item ID');
                if ($stmt = $conn->prepare("UPDATE motorpool_inventory_items SET status = 'deleted', updated_at = NOW() WHERE item_id = ?")) {
                    $stmt->bind_param('i', $item_id);
                    $stmt->execute();
                    $stmt->close();
                }
                $jsonOut(['success' => true, 'message' => 'Item deleted successfully.']);
            }

            if ($ultra_action === 'get_motorpool_item_unit_types') {
                $item_id = (int)($_POST['item_id'] ?? 0);
                if ($item_id <= 0) throw new Exception('Invalid item ID');
                $rows = [];
                $sql = "SELECT
                        COALESCE(iut.unit_type_id, inv.unit_type_id, iup.unit_type_id, i.default_unit_type_id) AS unit_type_id,
                        COALESCE(iut.unit_type_name, ut.unit_type_name, i.unit_type, 'Piece') AS unit_type_name,
                        COALESCE(iut.uom_initial, ut.uom_initial, '') AS uom_initial,
                        COALESCE(NULLIF(iut.barcode,''), i.barcode, '') AS barcode,
                        COALESCE(iut.smallest_pack_quantity, ut.quantity_smallest_pack, 1) AS quantity_smallest_pack,
                        CASE WHEN COALESCE(iut.unit_type_id, inv.unit_type_id, iup.unit_type_id, i.default_unit_type_id) = i.default_unit_type_id THEN 1 ELSE COALESCE(iut.is_default_uom, 0) END AS is_default_uom,
                        COALESCE(iut.status, 'active') AS unit_status,
                        COALESCE(iup.unit_quantity, 1) AS unit_quantity,
                        COALESCE(iup.unit_price, 0) AS unit_price,
                        COALESCE(inv.current_inventory, i.stock, i.current_stock, 0) AS current_inventory,
                        COALESCE(inv.beginning_inventory, inv.current_inventory, i.stock, i.current_stock, 0) AS beginning_inventory,
                        inv.as_of_date,
                        COALESCE(inv.unit_cost, i.unit_cost, i.unit_price, 0) AS unit_cost,
                        CASE WHEN COALESCE(inv.current_inventory,0) > 0 AND COALESCE(inv.total_cost,0) > 0 THEN inv.total_cost / inv.current_inventory ELSE COALESCE(inv.unit_cost, i.unit_cost, i.unit_price, 0) END AS average_cost,
                        COALESCE(inv.total_cost, COALESCE(inv.current_inventory, i.stock, i.current_stock, 0) * COALESCE(inv.unit_cost, i.unit_cost, i.unit_price, 0)) AS total_cost,
                        COALESCE(i.reorder_level, 0) AS reorder_level
                    FROM motorpool_inventory_items i
                    LEFT JOIN (
                        SELECT item_id, unit_type_id FROM motorpool_item_unit_inventory WHERE item_id = ?
                        UNION
                        SELECT item_id, unit_type_id FROM motorpool_item_unit_types WHERE item_id = ?
                        UNION
                        SELECT item_id, unit_type_id FROM motorpool_item_unit_pricing WHERE item_id = ?
                    ) u ON u.item_id = i.item_id
                    LEFT JOIN motorpool_item_unit_types iut ON iut.item_id = i.item_id AND iut.unit_type_id = u.unit_type_id
                    LEFT JOIN motorpool_item_unit_inventory inv ON inv.item_id = i.item_id AND inv.unit_type_id = u.unit_type_id
                    LEFT JOIN motorpool_item_unit_pricing iup ON iup.item_id = i.item_id AND iup.unit_type_id = u.unit_type_id AND COALESCE(iup.price_level,'Standard')='Standard'
                    LEFT JOIN motorpool_unit_types ut ON ut.unit_type_id = COALESCE(u.unit_type_id, i.default_unit_type_id)
                    WHERE i.item_id = ?
                    ORDER BY is_default_uom DESC, unit_type_name ASC";
                if ($stmt = $conn->prepare($sql)) {
                    $stmt->bind_param('iiii', $item_id, $item_id, $item_id, $item_id);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
                    $stmt->close();
                }
                $jsonOut(['success' => true, 'unit_types' => $rows, 'motorpool_unit_types' => $rows]);
            }

            if ($ultra_action === 'get_low_stock_items') {
                $where = "WHERE COALESCE(i.status,'active') = 'active' AND COALESCE(inv.total_inventory, i.stock, i.current_stock, 0) <= COALESCE(i.reorder_level, 0) AND COALESCE(i.reorder_level,0) > 0";
                if ($has_item_branch_fast && !$view_all_fast && $branch_id_fast > 0) {
                    $where .= " AND (i.branch_id = " . intval($branch_id_fast) . " OR i.branch_id IS NULL OR i.branch_id = 0)";
                }
                $sql = "SELECT i.item_id, i.item_code, i.item_name, i.category, COALESCE(inv.total_inventory, i.stock, i.current_stock, 0) AS current_stock, i.reorder_level
                    FROM motorpool_inventory_items i
                    LEFT JOIN (SELECT item_id, SUM(current_inventory) AS total_inventory FROM motorpool_item_unit_inventory GROUP BY item_id) inv ON inv.item_id = i.item_id
                    $where
                    ORDER BY i.item_name ASC
                    LIMIT 200";
                $rows = [];
                $res = $conn->query($sql);
                if ($res) $rows = $res->fetch_all(MYSQLI_ASSOC);
                $jsonOut(['success' => true, 'items' => $rows]);
            }

            if ($ultra_action === 'get_item') {
                $item_id = (int)($_POST['item_id'] ?? 0);
                if ($item_id <= 0) throw new Exception('Invalid item ID');

                $item_sql = "SELECT i.*, ut.unit_type_name AS default_uom_name, ut.quantity_smallest_pack AS default_multiplier,
                            CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,'')) AS created_by_name,
                            CONCAT(COALESCE(uu.first_name,''), ' ', COALESCE(uu.last_name,'')) AS updated_by_name
                        FROM motorpool_inventory_items i
                        LEFT JOIN motorpool_unit_types ut ON i.default_unit_type_id = ut.unit_type_id
                        LEFT JOIN users u ON i.created_by = u.user_id
                        LEFT JOIN users uu ON i.updated_by = uu.user_id
                        WHERE i.item_id = ? AND COALESCE(i.status,'active') <> 'deleted'";
                if ($has_item_branch_fast && !$view_all_fast && $branch_id_fast > 0) {
                    $item_sql .= " AND (i.branch_id = ? OR i.branch_id IS NULL OR i.branch_id = 0)";
                }
                $stmt = $conn->prepare($item_sql);
                if (!$stmt) throw new Exception('Unable to load item: ' . $conn->error);
                if ($has_item_branch_fast && !$view_all_fast && $branch_id_fast > 0) $stmt->bind_param('ii', $item_id, $branch_id_fast);
                else $stmt->bind_param('i', $item_id);
                $stmt->execute();
                $item = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$item) throw new Exception('Item not found');

                $unitTypes = [];
                $unit_sql = "SELECT
                        COALESCE(u.unit_type_id, i.default_unit_type_id) AS unit_type_id,
                        COALESCE(iut.unit_type_name, ut.unit_type_name, i.unit_type, 'Piece') AS unit_type_name,
                        COALESCE(iut.uom_initial, ut.uom_initial, '') AS uom_initial,
                        COALESCE(NULLIF(iut.barcode,''), i.barcode, '') AS barcode,
                        COALESCE(iut.smallest_pack_quantity, ut.quantity_smallest_pack, 1) AS quantity_smallest_pack,
                        CASE WHEN COALESCE(u.unit_type_id, i.default_unit_type_id) = i.default_unit_type_id THEN 1 ELSE COALESCE(iut.is_default_uom, 0) END AS is_default_uom,
                        COALESCE(iut.status, 'active') AS unit_status,
                        COALESCE(iup.unit_quantity, 1) AS unit_quantity,
                        COALESCE(iup.unit_price, 0) AS unit_price,
                        COALESCE(inv.current_inventory, i.stock, i.current_stock, 0) AS current_inventory,
                        COALESCE(inv.beginning_inventory, inv.current_inventory, i.stock, i.current_stock, 0) AS beginning_inventory,
                        inv.as_of_date,
                        COALESCE(inv.unit_cost, i.unit_cost, i.unit_price, 0) AS unit_cost,
                        CASE WHEN COALESCE(inv.current_inventory,0) > 0 AND COALESCE(inv.total_cost,0) > 0 THEN inv.total_cost / inv.current_inventory ELSE COALESCE(inv.unit_cost, i.unit_cost, i.unit_price, 0) END AS average_cost,
                        COALESCE(inv.total_cost, COALESCE(inv.current_inventory, i.stock, i.current_stock, 0) * COALESCE(inv.unit_cost, i.unit_cost, i.unit_price, 0)) AS total_cost,
                        COALESCE(i.reorder_level, 0) AS reorder_level
                    FROM motorpool_inventory_items i
                    LEFT JOIN (
                        SELECT item_id, unit_type_id FROM motorpool_item_unit_inventory WHERE item_id = ?
                        UNION
                        SELECT item_id, unit_type_id FROM motorpool_item_unit_types WHERE item_id = ?
                        UNION
                        SELECT item_id, unit_type_id FROM motorpool_item_unit_pricing WHERE item_id = ?
                        UNION
                        SELECT item_id, default_unit_type_id AS unit_type_id FROM motorpool_inventory_items WHERE item_id = ? AND default_unit_type_id IS NOT NULL
                    ) u ON u.item_id = i.item_id
                    LEFT JOIN motorpool_item_unit_types iut ON iut.item_id = i.item_id AND iut.unit_type_id = u.unit_type_id
                    LEFT JOIN motorpool_item_unit_inventory inv ON inv.item_id = i.item_id AND inv.unit_type_id = u.unit_type_id
                    LEFT JOIN motorpool_item_unit_pricing iup ON iup.item_id = i.item_id AND iup.unit_type_id = u.unit_type_id AND COALESCE(iup.price_level,'Standard') = 'Standard'
                    LEFT JOIN motorpool_unit_types ut ON ut.unit_type_id = COALESCE(u.unit_type_id, i.default_unit_type_id)
                    WHERE i.item_id = ?
                    GROUP BY unit_type_id
                    ORDER BY is_default_uom DESC, unit_type_name ASC";
                if ($stmt = $conn->prepare($unit_sql)) {
                    $stmt->bind_param('iiiii', $item_id, $item_id, $item_id, $item_id, $item_id);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    $unitTypes = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
                    $stmt->close();
                }

                if (empty($unitTypes)) {
                    $unitTypes[] = [
                        'unit_type_id' => (int)($item['default_unit_type_id'] ?? 0),
                        'unit_type_name' => $item['unit_type'] ?: 'Piece',
                        'uom_initial' => '',
                        'barcode' => $item['barcode'] ?? '',
                        'quantity_smallest_pack' => 1,
                        'is_default_uom' => 1,
                        'unit_status' => 'active',
                        'unit_quantity' => 1,
                        'unit_price' => (float)($item['unit_price'] ?? 0),
                        'current_inventory' => (float)($item['stock'] ?? $item['current_stock'] ?? 0),
                        'beginning_inventory' => (float)($item['stock'] ?? $item['current_stock'] ?? 0),
                        'as_of_date' => $item['created_at'] ?? null,
                        'unit_cost' => (float)($item['unit_cost'] ?? 0),
                        'average_cost' => (float)($item['unit_cost'] ?? 0),
                        'total_cost' => (float)($item['total_cost'] ?? 0),
                        'reorder_level' => (float)($item['reorder_level'] ?? 0)
                    ];
                }

                // FIX: Build inventory summary from per-UoM inventory rows, not from
                // motorpool_inventory_items.total_cost/unit_cost. Imported costs are stored
                // in motorpool_item_unit_inventory, so Item Details and the Total Inventory
                // Value must use these per-UoM values.
                $inventory_summary = [
                    'beginning_inventory' => 0,
                    'total_inventory' => 0,
                    'total_cost' => 0,
                    'average_cost_month' => 0,
                    'ave_daily_offtake' => 0,
                    'offtake_total_quantity' => 0,
                    'offtake_active_days' => 0
                ];
                $defaultUnitTypeIdForCost = (int)($item['default_unit_type_id'] ?? 0);
                $defaultCostRow = null;
                foreach ($unitTypes as &$utFix) {
                    $currentQty = (float)($utFix['current_inventory'] ?? 0);
                    $beginQty = (float)($utFix['beginning_inventory'] ?? $currentQty);
                    $unitCostVal = (float)($utFix['unit_cost'] ?? 0);
                    $totalCostVal = (float)($utFix['total_cost'] ?? 0);
                    if ($totalCostVal <= 0 && $unitCostVal > 0) {
                        $qtyForCost = $currentQty > 0 ? $currentQty : $beginQty;
                        $totalCostVal = $qtyForCost * $unitCostVal;
                        $utFix['total_cost'] = $totalCostVal;
                    }
                    if ((float)($utFix['average_cost'] ?? 0) <= 0) {
                        $utFix['average_cost'] = $unitCostVal;
                    }
                    $inventory_summary['beginning_inventory'] += $beginQty;
                    $inventory_summary['total_inventory'] += $currentQty;
                    $inventory_summary['total_cost'] += $totalCostVal;
                    if ($defaultCostRow === null) {
                        $defaultCostRow = $utFix;
                    }
                    if ($defaultUnitTypeIdForCost > 0 && (int)($utFix['unit_type_id'] ?? 0) === $defaultUnitTypeIdForCost) {
                        $defaultCostRow = $utFix;
                    }
                }
                unset($utFix);
                if ($inventory_summary['total_inventory'] > 0) {
                    $inventory_summary['average_cost_month'] = $inventory_summary['total_cost'] / $inventory_summary['total_inventory'];
                }
                $item['stock'] = $inventory_summary['total_inventory'];
                $item['current_stock'] = $inventory_summary['total_inventory'];
                $item['total_cost'] = $inventory_summary['total_cost'];
                if ($defaultCostRow !== null) {
                    $item['unit_cost'] = (float)($defaultCostRow['unit_cost'] ?? 0);
                    $item['unit_type'] = $defaultCostRow['unit_type_name'] ?? ($item['unit_type'] ?? 'Piece');
                }

                $images = [];
                if ($stmt = $conn->prepare("SELECT image_id, image_path, image_order, is_primary FROM motorpool_item_images WHERE item_id = ? ORDER BY image_order ASC, is_primary DESC")) {
                    $stmt->bind_param('i', $item_id);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    $images = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
                    $stmt->close();
                }
                if (empty($images)) {
                    $path = trim((string)($item['product_image_url'] ?? ''));
                    if ($path === '') $path = trim((string)($item['item_image'] ?? ''));
                    if ($path !== '') $images[] = ['image_id' => 0, 'image_path' => $path, 'image_order' => 0, 'is_primary' => 1];
                }

                $pricing_rows_map = [];
                $pricing_history = [];
                $price_sql = "SELECT 'current' AS history_source, iup.effective_date, COALESCE(NULLIF(iup.price_level,''),'Standard') AS price_level,
                            iup.unit_type_id, COALESCE(ut.unit_type_name, iut.unit_type_name, 'Piece') AS unit_type_name,
                            iup.unit_price, COALESCE(iup.unit_quantity,1) AS unit_quantity, iup.created_at, iup.updated_at,
                            COALESCE(iup.updated_at, iup.created_at) AS sort_datetime
                        FROM motorpool_item_unit_pricing iup
                        LEFT JOIN motorpool_unit_types ut ON ut.unit_type_id = iup.unit_type_id
                        LEFT JOIN motorpool_item_unit_types iut ON iut.item_id = iup.item_id AND iut.unit_type_id = iup.unit_type_id
                        WHERE iup.item_id = ?
                        UNION ALL
                        SELECT 'scheduled' AS history_source, ips.effective_date, COALESCE(NULLIF(ips.price_level,''),'Standard') AS price_level,
                            ips.unit_type_id, COALESCE(ut.unit_type_name, iut.unit_type_name, 'Piece') AS unit_type_name,
                            ips.unit_price, COALESCE(ips.unit_quantity,1) AS unit_quantity, ips.created_at, ips.updated_at,
                            COALESCE(ips.updated_at, ips.created_at) AS sort_datetime
                        FROM motorpool_item_unit_pricing_schedule ips
                        LEFT JOIN motorpool_unit_types ut ON ut.unit_type_id = ips.unit_type_id
                        LEFT JOIN motorpool_item_unit_types iut ON iut.item_id = ips.item_id AND iut.unit_type_id = ips.unit_type_id
                        WHERE ips.item_id = ?
                        ORDER BY sort_datetime DESC, price_level ASC, unit_type_name ASC";
                if ($stmt = $conn->prepare($price_sql)) {
                    $stmt->bind_param('ii', $item_id, $item_id);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    if ($res) {
                        while ($r = $res->fetch_assoc()) {
                            $key = ($r['effective_date'] ?? '') . '||' . ($r['price_level'] ?? 'Standard');
                            if (!isset($pricing_rows_map[$key])) {
                                $pricing_rows_map[$key] = [
                                    'effective_date' => $r['effective_date'],
                                    'price_level' => $r['price_level'] ?: 'Standard',
                                    'prices' => []
                                ];
                            }
                            $pricing_rows_map[$key]['prices'][$r['unit_type_name']] = [
                                'unit_type_id' => (int)$r['unit_type_id'],
                                'unit_price' => (float)$r['unit_price'],
                                'unit_quantity' => (int)$r['unit_quantity']
                            ];
                            $pricing_history[] = $r;
                        }
                    }
                    $stmt->close();
                }

                $jsonOut([
                    'success' => true,
                    'item' => $item,
                    'unit_types' => $unitTypes,
                    'motorpool_unit_types' => $unitTypes,
                    'images' => $images,
                    'pricing_rows' => array_values($pricing_rows_map),
                    'pricing_history' => $pricing_history,
                    'inventory_summary' => $inventory_summary
                ]);
            }
        } catch (Throwable $e) {
            error_log('Ultra fast motorpool ajax error: ' . $e->getMessage());
            $jsonOut(['success' => false, 'message' => $e->getMessage()]);
        }
    }
}


// ========== MOTORPOOL INVENTORY COMPATIBILITY LAYER ==========
// This page keeps the Branch Admin Current Inventory functions/modals,
// but stores and reads item records from the Motorpool inventory tables.
$conn->query("CREATE TABLE IF NOT EXISTS `motorpool_inventory_items` (
    `item_id` INT AUTO_INCREMENT PRIMARY KEY,
    `item_code` VARCHAR(80) UNIQUE NOT NULL,
    `item_name` VARCHAR(255) NOT NULL,
    `description` TEXT DEFAULT NULL,
    `category` VARCHAR(120) DEFAULT 'General',
    `principal` VARCHAR(150) DEFAULT NULL,
    `volume` VARCHAR(100) DEFAULT NULL,
    `oil_type` VARCHAR(50) DEFAULT NULL,
    `income_account_id` INT DEFAULT NULL,
    `cogs_account_id` INT DEFAULT NULL,
    `asset_account_id` INT DEFAULT NULL,
    `unit_type` VARCHAR(80) DEFAULT 'Piece',
    `barcode` VARCHAR(120) DEFAULT NULL,
    `stock` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `current_stock` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `reorder_level` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `unit_price` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `unit_cost` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `price_case` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `price_inner_pack` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `price_box` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `price_carton` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `total_cost` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `supplier` VARCHAR(255) DEFAULT NULL,
    `product_image_url` VARCHAR(255) DEFAULT NULL,
    `item_image` VARCHAR(255) DEFAULT NULL,
    `default_unit_type_id` INT DEFAULT NULL,
    `branch_id` INT DEFAULT NULL,
    `points_eligible` TINYINT(1) NOT NULL DEFAULT 1,
    `status` VARCHAR(30) DEFAULT 'active',
    `created_by` INT DEFAULT NULL,
    `updated_by` INT DEFAULT NULL,
    `parent_item_id` INT DEFAULT NULL,
    `is_tire_serial_child` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_item_code` (`item_code`),
    KEY `idx_item_name` (`item_name`),
    KEY `idx_category` (`category`),
    KEY `idx_supplier` (`supplier`),
    KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

function motorpoolEnsureColumn(mysqli $conn, string $table, string $column, string $definition): void {
    $tableSafe = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $columnSafe = $conn->real_escape_string($column);
    $res = $conn->query("SHOW COLUMNS FROM `$tableSafe` LIKE '$columnSafe'");
    if (!$res || $res->num_rows == 0) {
        @$conn->query("ALTER TABLE `$tableSafe` ADD COLUMN $definition");
    }
}
foreach ([
    'principal' => "`principal` VARCHAR(150) DEFAULT NULL AFTER `category`",
    'volume' => "`volume` VARCHAR(100) DEFAULT NULL AFTER `principal`",
    'oil_type' => "`oil_type` VARCHAR(50) DEFAULT NULL AFTER `volume`",
    'income_account_id' => "`income_account_id` INT DEFAULT NULL AFTER `oil_type`",
    'cogs_account_id' => "`cogs_account_id` INT DEFAULT NULL AFTER `income_account_id`",
    'asset_account_id' => "`asset_account_id` INT DEFAULT NULL AFTER `cogs_account_id`",
    'barcode' => "`barcode` VARCHAR(120) DEFAULT NULL AFTER `unit_type`",
    'stock' => "`stock` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `barcode`",
    'current_stock' => "`current_stock` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `stock`",
    'reorder_level' => "`reorder_level` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `current_stock`",
    'unit_price' => "`unit_price` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `reorder_level`",
    'unit_cost' => "`unit_cost` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `unit_price`",
    'price_case' => "`price_case` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `unit_cost`",
    'price_inner_pack' => "`price_inner_pack` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `price_case`",
    'price_box' => "`price_box` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `price_inner_pack`",
    'price_carton' => "`price_carton` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `price_box`",
    'total_cost' => "`total_cost` DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER `price_carton`",
    'supplier' => "`supplier` VARCHAR(255) DEFAULT NULL AFTER `total_cost`",
    'product_image_url' => "`product_image_url` VARCHAR(255) DEFAULT NULL AFTER `supplier`",
    'item_image' => "`item_image` VARCHAR(255) DEFAULT NULL AFTER `product_image_url`",
    'default_unit_type_id' => "`default_unit_type_id` INT DEFAULT NULL AFTER `item_image`",
    'branch_id' => "`branch_id` INT DEFAULT NULL AFTER `default_unit_type_id`",
    'points_eligible' => "`points_eligible` TINYINT(1) NOT NULL DEFAULT 1 AFTER `branch_id`",
    'created_by' => "`created_by` INT DEFAULT NULL AFTER `status`",
    'updated_by' => "`updated_by` INT DEFAULT NULL AFTER `created_by`",
    // Tire serial child support: these must exist before the main item SELECT queries run.
    'parent_item_id' => "`parent_item_id` INT DEFAULT NULL AFTER `updated_by`",
    'is_tire_serial_child' => "`is_tire_serial_child` TINYINT(1) NOT NULL DEFAULT 0 AFTER `parent_item_id`"
] as $c => $def) { motorpoolEnsureColumn($conn, 'motorpool_inventory_items', $c, $def); }

$conn->query("CREATE TABLE IF NOT EXISTS `motorpool_inventory_transactions` (
    `transaction_id` INT AUTO_INCREMENT PRIMARY KEY,
    `branch_id` INT DEFAULT NULL,
    `item_id` INT NOT NULL,
    `transaction_type` VARCHAR(40) NOT NULL,
    `quantity_changed` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `quantity` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `unit_cost` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `total_cost` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `reference_type` VARCHAR(80) DEFAULT NULL,
    `reference_id` INT DEFAULT NULL,
    `receive_memo` TEXT DEFAULT NULL,
    `remarks` TEXT DEFAULT NULL,
    `attachment` VARCHAR(255) DEFAULT NULL,
    `created_by` INT DEFAULT NULL,
    `encoded_by` INT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_item_id` (`item_id`),
    KEY `idx_transaction_type` (`transaction_type`),
    KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
foreach ([
    'branch_id' => "`branch_id` INT DEFAULT NULL AFTER `transaction_id`",
    'quantity_changed' => "`quantity_changed` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `transaction_type`",
    'quantity' => "`quantity` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `quantity_changed`",
    'reference_type' => "`reference_type` VARCHAR(80) DEFAULT NULL AFTER `total_cost`",
    'reference_id' => "`reference_id` INT DEFAULT NULL AFTER `reference_type`",
    'receive_memo' => "`receive_memo` TEXT DEFAULT NULL AFTER `reference_id`",
    'remarks' => "`remarks` TEXT DEFAULT NULL AFTER `receive_memo`",
    'attachment' => "`attachment` VARCHAR(255) DEFAULT NULL AFTER `remarks`",
    'created_by' => "`created_by` INT DEFAULT NULL AFTER `attachment`",
    'encoded_by' => "`encoded_by` INT DEFAULT NULL AFTER `created_by`"
] as $c => $def) { motorpoolEnsureColumn($conn, 'motorpool_inventory_transactions', $c, $def); }

// Keep old motorpool fields and Branch Current Inventory fields mirrored.
@$conn->query("UPDATE motorpool_inventory_items SET stock = current_stock WHERE COALESCE(stock,0)=0 AND COALESCE(current_stock,0)<>0");
@$conn->query("UPDATE motorpool_inventory_items SET current_stock = stock WHERE COALESCE(current_stock,0)=0 AND COALESCE(stock,0)<>0");
// FIX: Do not copy unit_cost into unit_price. Price levels must come from motorpool_item_unit_pricing.unit_price.
// @$conn->query("UPDATE motorpool_inventory_items SET unit_price = unit_cost WHERE COALESCE(unit_price,0)=0 AND COALESCE(unit_cost,0)<>0");
// FIX: Do not copy unit_price into unit_cost. Cost and selling price are separate fields.
// @$conn->query("UPDATE motorpool_inventory_items SET unit_cost = unit_price WHERE COALESCE(unit_cost,0)=0 AND COALESCE(unit_price,0)<>0");
@$conn->query("UPDATE motorpool_inventory_items SET product_image_url = item_image WHERE (product_image_url IS NULL OR product_image_url='') AND item_image IS NOT NULL AND item_image<>''");
@$conn->query("UPDATE motorpool_inventory_items SET item_image = product_image_url WHERE (item_image IS NULL OR item_image='') AND product_image_url IS NOT NULL AND product_image_url<>''");
@$conn->query("UPDATE motorpool_inventory_transactions SET quantity_changed = quantity WHERE COALESCE(quantity_changed,0)=0 AND COALESCE(quantity,0)<>0");
@$conn->query("UPDATE motorpool_inventory_transactions SET quantity = quantity_changed WHERE COALESCE(quantity,0)=0 AND COALESCE(quantity_changed,0)<>0");


// ========== REQUIRED MOTORPOOL CURRENT INVENTORY TABLES (CREATED BEFORE ANY QUERY USES THEM) ==========
// These tables mirror the Branch Admin Current Inventory support tables, but are isolated for Motorpool.
$conn->query("CREATE TABLE IF NOT EXISTS `motorpool_unit_types` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$conn->query("CREATE TABLE IF NOT EXISTS `motorpool_item_unit_pricing` (
    `pricing_id` int(11) NOT NULL AUTO_INCREMENT,
    `item_id` int(11) NOT NULL,
    `unit_type_id` int(11) NOT NULL,
    `unit_price` decimal(10,2) NOT NULL DEFAULT 0.00,
    `unit_quantity` int(11) DEFAULT 1,
    `effective_date` date DEFAULT NULL,
    `price_level` varchar(50) DEFAULT 'Standard',
    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
    `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`pricing_id`),
    UNIQUE KEY `item_unit_price_level_unique` (`item_id`, `unit_type_id`, `price_level`),
    KEY `idx_effective_date` (`effective_date`),
    KEY `idx_price_level` (`price_level`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$conn->query("CREATE TABLE IF NOT EXISTS `motorpool_item_unit_pricing_schedule` (
    `schedule_id` int(11) NOT NULL AUTO_INCREMENT,
    `item_id` int(11) NOT NULL,
    `unit_type_id` int(11) NOT NULL,
    `price_level` varchar(50) NOT NULL DEFAULT 'Standard',
    `unit_price` decimal(10,2) NOT NULL DEFAULT 0.00,
    `unit_quantity` int(11) DEFAULT 1,
    `effective_date` date NOT NULL,
    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
    `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`schedule_id`),
    UNIQUE KEY `item_unit_price_schedule_unique` (`item_id`, `unit_type_id`, `price_level`, `effective_date`),
    KEY `idx_schedule_effective_date` (`effective_date`),
    KEY `idx_schedule_price_level` (`price_level`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$conn->query("CREATE TABLE IF NOT EXISTS `motorpool_item_unit_pricing_history` (
    `history_id` int(11) NOT NULL AUTO_INCREMENT,
    `item_id` int(11) NOT NULL,
    `unit_type_id` int(11) NOT NULL,
    `price_level` varchar(50) NOT NULL DEFAULT 'Standard',
    `unit_price` decimal(10,2) NOT NULL DEFAULT 0.00,
    `unit_quantity` int(11) DEFAULT 1,
    `effective_date` date DEFAULT NULL,
    `history_type` varchar(30) NOT NULL DEFAULT 'previous',
    `created_by` int(11) DEFAULT NULL,
    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`history_id`),
    KEY `idx_price_history_item` (`item_id`),
    KEY `idx_price_history_item_unit_level` (`item_id`, `unit_type_id`, `price_level`),
    KEY `idx_price_history_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$conn->query("CREATE TABLE IF NOT EXISTS `motorpool_item_unit_inventory` (
    `inventory_id` int(11) NOT NULL AUTO_INCREMENT,
    `item_id` int(11) NOT NULL,
    `unit_type_id` int(11) NOT NULL,
    `current_inventory` decimal(12,2) NOT NULL DEFAULT 0.00,
    `beginning_inventory` decimal(12,2) NOT NULL DEFAULT 0.00,
    `as_of_date` date DEFAULT NULL,
    `unit_cost` decimal(12,2) NOT NULL DEFAULT 0.00,
    `total_cost` decimal(14,2) NOT NULL DEFAULT 0.00,
    `status` varchar(30) DEFAULT 'active',
    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
    `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`inventory_id`),
    UNIQUE KEY `motorpool_item_unit_inventory_unique` (`item_id`, `unit_type_id`),
    KEY `idx_motorpool_item_unit_inventory_item` (`item_id`),
    KEY `idx_motorpool_item_unit_inventory_unit` (`unit_type_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Existing installs may already have motorpool_item_unit_inventory without newer Branch Admin columns.
// Add them before any seed INSERT/UPSERT uses them.
foreach ([
    'current_inventory' => "`current_inventory` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `unit_type_id`",
    'beginning_inventory' => "`beginning_inventory` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `current_inventory`",
    'as_of_date' => "`as_of_date` DATE DEFAULT NULL AFTER `beginning_inventory`",
    'unit_cost' => "`unit_cost` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `as_of_date`",
    'total_cost' => "`total_cost` DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER `unit_cost`",
    'status' => "`status` VARCHAR(30) DEFAULT 'active' AFTER `total_cost`",
    'created_at' => "`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `status`",
    'updated_at' => "`updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`"
] as $c => $def) { motorpoolEnsureColumn($conn, 'motorpool_item_unit_inventory', $c, $def); }


$conn->query("CREATE TABLE IF NOT EXISTS `motorpool_item_unit_types` (
    `item_unit_type_id` int(11) NOT NULL AUTO_INCREMENT,
    `item_id` int(11) NOT NULL,
    `unit_type_id` int(11) NOT NULL,
    `unit_type_name` varchar(100) NOT NULL,
    `uom_initial` varchar(20) DEFAULT NULL,
    `barcode` varchar(100) DEFAULT NULL,
    `smallest_pack_quantity` decimal(12,4) NOT NULL DEFAULT 1.0000,
    `is_default_uom` tinyint(1) NOT NULL DEFAULT 0,
    `branch_id` int(11) DEFAULT NULL,
    `status` enum('active','inactive') DEFAULT 'active',
    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
    `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`item_unit_type_id`),
    UNIQUE KEY `item_unit_type_unique` (`item_id`, `unit_type_id`),
    KEY `idx_motorpool_item_unit_types_item` (`item_id`),
    KEY `idx_motorpool_item_unit_types_barcode` (`barcode`),
    KEY `idx_motorpool_item_unit_types_unit` (`unit_type_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$conn->query("CREATE TABLE IF NOT EXISTS `motorpool_item_images` (
    `image_id` int(11) NOT NULL AUTO_INCREMENT,
    `item_id` int(11) NOT NULL,
    `image_path` varchar(255) NOT NULL,
    `image_order` int(11) DEFAULT 0,
    `is_primary` tinyint(1) DEFAULT 0,
    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`image_id`),
    KEY `idx_item_id` (`item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Seed one default UOM so existing simple Motorpool items can use all Branch Admin UOM functions immediately.
@$conn->query("INSERT IGNORE INTO motorpool_unit_types (unit_type_name, uom_initial, quantity_smallest_pack, is_default_uom, multiplier, branch_id, status) VALUES ('Piece', 'PC', 1, 1, 1.00, NULL, 'active')");
@$conn->query("UPDATE motorpool_inventory_items SET default_unit_type_id = (SELECT unit_type_id FROM motorpool_unit_types WHERE unit_type_name='Piece' ORDER BY unit_type_id ASC LIMIT 1) WHERE default_unit_type_id IS NULL OR default_unit_type_id = 0");
try {
    $conn->query("INSERT IGNORE INTO motorpool_item_unit_inventory (item_id, unit_type_id, current_inventory, beginning_inventory, as_of_date, unit_cost, total_cost, status)
        SELECT item_id, default_unit_type_id, COALESCE(NULLIF(stock,0), current_stock), COALESCE(NULLIF(stock,0), current_stock), DATE(created_at), COALESCE(NULLIF(unit_cost,0), unit_price), COALESCE(total_cost, COALESCE(NULLIF(stock,0), current_stock) * COALESCE(NULLIF(unit_cost,0), unit_price)), 'active'
        FROM motorpool_inventory_items
        WHERE default_unit_type_id IS NOT NULL AND default_unit_type_id > 0");
} catch (Throwable $e) {
    error_log('motorpool_item_unit_inventory seed skipped: ' . $e->getMessage());
}
// Do not seed fake Standard price rows. Batch Update shows 'No existing price levels' when no price exists.
@$conn->query("INSERT IGNORE INTO motorpool_item_unit_types (item_id, unit_type_id, unit_type_name, uom_initial, barcode, smallest_pack_quantity, is_default_uom, branch_id, status)
    SELECT i.item_id, i.default_unit_type_id, COALESCE(NULLIF(i.unit_type,''),'Piece'), 'PC', i.barcode, 1.0000, 1, i.branch_id, 'active'
    FROM motorpool_inventory_items i
    WHERE i.default_unit_type_id IS NOT NULL AND i.default_unit_type_id > 0");


// Get current user info and branch context
$user_id = $_SESSION['user_id'] ?? 0;
$user_name = isset($_SESSION['first_name']) ? $_SESSION['first_name'] . ' ' . $_SESSION['last_name'] : 'Motorpool';
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
        FROM motorpool_inventory_items i
        JOIN motorpool_unit_types ut ON i.default_unit_type_id = ut.unit_type_id
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
        FROM motorpool_item_unit_pricing iup
        JOIN motorpool_unit_types ut ON iup.unit_type_id = ut.unit_type_id
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
        FROM motorpool_unit_types
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
$check_column = $conn->query("SHOW COLUMNS FROM motorpool_inventory_items LIKE 'branch_id'");
if ($check_column && $check_column->num_rows > 0) {
    $items_branch_column_exists = true;
}

// Check if created_by column exists in items table
$created_by_column_exists = false;
$check_created_by = $conn->query("SHOW COLUMNS FROM motorpool_inventory_items LIKE 'created_by'");
if ($check_created_by && $check_created_by->num_rows > 0) {
    $created_by_column_exists = true;
} else {
    $add_created_by = "ALTER TABLE motorpool_inventory_items ADD COLUMN created_by int(11) DEFAULT NULL AFTER created_at";
    $conn->query($add_created_by);
    $created_by_column_exists = true;
}


// Track who last updated the item, even when optional fields are blank.
$check_updated_by = $conn->query("SHOW COLUMNS FROM motorpool_inventory_items LIKE 'updated_by'");
if (!$check_updated_by || $check_updated_by->num_rows == 0) {
    @$conn->query("ALTER TABLE motorpool_inventory_items ADD COLUMN updated_by INT(11) DEFAULT NULL AFTER created_by");
}

// Add default_unit_type_id column if not exists
$check_default_unit = $conn->query("SHOW COLUMNS FROM motorpool_inventory_items LIKE 'default_unit_type_id'");
if (!$check_default_unit || $check_default_unit->num_rows == 0) {
    $add_default_unit = "ALTER TABLE motorpool_inventory_items ADD COLUMN default_unit_type_id INT(11) DEFAULT NULL AFTER unit_type";
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
$items_branch_condition_c = "";
if ($items_branch_column_exists && !$view_all_branches && $branch_id > 0) {
    $items_branch_condition = "AND (i.branch_id = " . intval($branch_id) . " OR i.branch_id IS NULL OR i.branch_id = 0)";
    $items_branch_condition_c = "AND (c.branch_id = " . intval($branch_id) . " OR c.branch_id IS NULL OR c.branch_id = 0)";
}

// Determine branch filter condition for suppliers
$suppliers_branch_condition = "";
if ($suppliers_branch_column_exists && !$view_all_branches && $branch_id > 0) {
    $suppliers_branch_condition = "AND (branch_id = " . intval($branch_id) . " OR branch_id IS NULL OR branch_id = 0)";
}

// Fetch all available price levels from database (filtered by branch)
$priceLevels = [];
$priceLevelQuery = "SELECT DISTINCT iup.price_level 
                   FROM motorpool_item_unit_pricing iup
                   JOIN motorpool_inventory_items i ON iup.item_id = i.item_id
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

// disabled noisy log for faster page load

// ========== CREATE/ALTER TABLES IF NEEDED ==========

$create_motorpool_unit_types = "CREATE TABLE IF NOT EXISTS `motorpool_unit_types` (
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
$conn->query($create_motorpool_unit_types);

$check_uom_initial_column = $conn->query("SHOW COLUMNS FROM motorpool_unit_types LIKE 'uom_initial'");
if (!$check_uom_initial_column || $check_uom_initial_column->num_rows == 0) {
    $conn->query("ALTER TABLE motorpool_unit_types ADD COLUMN uom_initial VARCHAR(20) DEFAULT NULL AFTER unit_type_name");
}

$check_pricing_table = $conn->query("SHOW TABLES LIKE 'motorpool_item_unit_pricing'");
if ($check_pricing_table && $check_pricing_table->num_rows == 0) {
    $create_pricing = "CREATE TABLE IF NOT EXISTS `motorpool_item_unit_pricing` (
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
    $check_effective_date = $conn->query("SHOW COLUMNS FROM motorpool_item_unit_pricing LIKE 'effective_date'");
    if (!$check_effective_date || $check_effective_date->num_rows == 0) {
        $add_effective_date = "ALTER TABLE motorpool_item_unit_pricing ADD COLUMN effective_date date DEFAULT NULL AFTER unit_quantity";
        $conn->query($add_effective_date);
    }
    
    $check_price_level = $conn->query("SHOW COLUMNS FROM motorpool_item_unit_pricing LIKE 'price_level'");
    if (!$check_price_level || $check_price_level->num_rows == 0) {
        $add_price_level = "ALTER TABLE motorpool_item_unit_pricing ADD COLUMN price_level varchar(50) DEFAULT 'Standard' AFTER effective_date";
        $conn->query($add_price_level);
    }
}

$create_motorpool_item_unit_pricing_schedule = "CREATE TABLE IF NOT EXISTS `motorpool_item_unit_pricing_schedule` (
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
$conn->query($create_motorpool_item_unit_pricing_schedule);

$create_motorpool_item_unit_pricing_history = "CREATE TABLE IF NOT EXISTS `motorpool_item_unit_pricing_history` (
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
$conn->query($create_motorpool_item_unit_pricing_history);

$create_motorpool_item_unit_inventory = "CREATE TABLE IF NOT EXISTS `motorpool_item_unit_inventory` (
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
    UNIQUE KEY `motorpool_item_unit_inventory_unique` (`item_id`, `unit_type_id`),
    KEY `idx_motorpool_item_unit_inventory_item` (`item_id`),
    KEY `idx_motorpool_item_unit_inventory_unit` (`unit_type_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
$conn->query($create_motorpool_item_unit_inventory);


// Per-item UOM table. This keeps each item's UOM barcode separate.
// Do NOT rely on motorpool_unit_types.barcode only, because motorpool_unit_types is shared by many items
// (Piece/Ream/Box can exist on multiple products with different barcodes).
$create_motorpool_item_unit_types = "CREATE TABLE IF NOT EXISTS `motorpool_item_unit_types` (
    `item_unit_type_id` int(11) NOT NULL AUTO_INCREMENT,
    `item_id` int(11) NOT NULL,
    `unit_type_id` int(11) NOT NULL,
    `unit_type_name` varchar(100) NOT NULL,
    `uom_initial` varchar(20) DEFAULT NULL,
    `barcode` varchar(100) DEFAULT NULL,
    `smallest_pack_quantity` decimal(12,4) NOT NULL DEFAULT 1.0000,
    `is_default_uom` tinyint(1) NOT NULL DEFAULT 0,
    `branch_id` int(11) DEFAULT NULL,
    `status` enum('active','inactive') DEFAULT 'active',
    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
    `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`item_unit_type_id`),
    UNIQUE KEY `item_unit_type_unique` (`item_id`, `unit_type_id`),
    KEY `idx_motorpool_item_unit_types_item` (`item_id`),
    KEY `idx_motorpool_item_unit_types_barcode` (`barcode`),
    KEY `idx_motorpool_item_unit_types_unit` (`unit_type_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
$conn->query($create_motorpool_item_unit_types);


// Repair older/failed motorpool_item_unit_types table definitions before any ALTER TABLE runs.
// Correct structure must be:
//   item_unit_type_id = row primary key / auto increment
//   unit_type_id      = master UOM id from motorpool_unit_types
// Older DB exports used unit_type_id as AUTO_INCREMENT PRIMARY KEY, which makes one UOM row shared
// across many items. That is the cause of barcode from one multi-UOM item appearing on another item.
try {
    $hasItemUnitTypes = $conn->query("SHOW TABLES LIKE 'motorpool_item_unit_types'");
    if ($hasItemUnitTypes && $hasItemUnitTypes->num_rows > 0) {
        $hasItemUnitTypeId = $conn->query("SHOW COLUMNS FROM motorpool_item_unit_types LIKE 'item_unit_type_id'");
        $unitTypeCol = $conn->query("SHOW COLUMNS FROM motorpool_item_unit_types LIKE 'unit_type_id'");
        $unitTypeExtra = '';
        if ($unitTypeCol && $unitTypeRow = $unitTypeCol->fetch_assoc()) {
            $unitTypeExtra = strtolower((string)($unitTypeRow['Extra'] ?? ''));
        }

        // If unit_type_id is AUTO_INCREMENT, remove it first. It must be a normal FK-like column.
        if (strpos($unitTypeExtra, 'auto_increment') !== false) {
            @$conn->query("ALTER TABLE motorpool_item_unit_types MODIFY unit_type_id INT(11) NOT NULL");
        }

        // Drop wrong primary key if it is not item_unit_type_id.
        $primaryResult = $conn->query("SHOW INDEX FROM motorpool_item_unit_types WHERE Key_name = 'PRIMARY'");
        $primaryColumns = [];
        if ($primaryResult) {
            while ($pk = $primaryResult->fetch_assoc()) {
                $primaryColumns[] = (string)($pk['Column_name'] ?? '');
            }
        }
        if (!empty($primaryColumns) && !(count($primaryColumns) === 1 && $primaryColumns[0] === 'item_unit_type_id')) {
            @$conn->query("ALTER TABLE motorpool_item_unit_types DROP PRIMARY KEY");
        }

        // Add correct row id if missing.
        if (!$hasItemUnitTypeId || $hasItemUnitTypeId->num_rows == 0) {
            @$conn->query("ALTER TABLE motorpool_item_unit_types ADD COLUMN item_unit_type_id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST");
        } else {
            // Ensure item_unit_type_id is the primary key and auto increment.
            $primaryResult2 = $conn->query("SHOW INDEX FROM motorpool_item_unit_types WHERE Key_name = 'PRIMARY' AND Column_name = 'item_unit_type_id'");
            if (!$primaryResult2 || $primaryResult2->num_rows == 0) {
                @$conn->query("ALTER TABLE motorpool_item_unit_types ADD PRIMARY KEY (item_unit_type_id)");
            }
            @$conn->query("ALTER TABLE motorpool_item_unit_types MODIFY item_unit_type_id INT(11) NOT NULL AUTO_INCREMENT");
        }
    }
} catch (Throwable $e) {
    error_log('motorpool_item_unit_types repair skipped: ' . $e->getMessage());
}

// IMPORTANT:
// Do not mass-update motorpool_unit_types.barcode here.
// motorpool_unit_types is a shared/master UOM table, and changing it while opening current_inventory.php
// can make unrelated items appear to have changed. Item-specific barcode values are handled
// only while saving the currently edited item.

// Safety for older databases where motorpool_item_unit_types already exists but lacks newer columns/indexes.
foreach ([
    'uom_initial' => "ALTER TABLE motorpool_item_unit_types ADD COLUMN uom_initial VARCHAR(20) DEFAULT NULL AFTER unit_type_name",
    'barcode' => "ALTER TABLE motorpool_item_unit_types ADD COLUMN barcode VARCHAR(100) DEFAULT NULL AFTER uom_initial",
    'smallest_pack_quantity' => "ALTER TABLE motorpool_item_unit_types ADD COLUMN smallest_pack_quantity DECIMAL(12,4) NOT NULL DEFAULT 1.0000 AFTER barcode",
    'is_default_uom' => "ALTER TABLE motorpool_item_unit_types ADD COLUMN is_default_uom TINYINT(1) NOT NULL DEFAULT 0 AFTER smallest_pack_quantity",
    'branch_id' => "ALTER TABLE motorpool_item_unit_types ADD COLUMN branch_id INT(11) DEFAULT NULL AFTER is_default_uom",
    'status' => "ALTER TABLE motorpool_item_unit_types ADD COLUMN status ENUM('active','inactive') DEFAULT 'active' AFTER branch_id",
    'created_at' => "ALTER TABLE motorpool_item_unit_types ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER status",
    'updated_at' => "ALTER TABLE motorpool_item_unit_types ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at"
] as $colName => $alterSql) {
    try {
        if (!amgcColumnExists($conn, 'motorpool_item_unit_types', $colName)) {
            $conn->query($alterSql);
        }
    } catch (Throwable $e) {
        error_log('motorpool_item_unit_types alter skipped for ' . $colName . ': ' . $e->getMessage());
    }
}

// Remove duplicates before applying the item+UOM unique key.
try {
    if (amgcColumnExists($conn, 'motorpool_item_unit_types', 'item_unit_type_id')) {
        $conn->query("CREATE TEMPORARY TABLE IF NOT EXISTS tmp_keep_motorpool_item_unit_types AS
            SELECT MAX(item_unit_type_id) AS keep_id
            FROM motorpool_item_unit_types
            GROUP BY item_id, unit_type_id");
        $conn->query("DELETE iut FROM motorpool_item_unit_types iut
            LEFT JOIN tmp_keep_motorpool_item_unit_types k ON k.keep_id = iut.item_unit_type_id
            WHERE k.keep_id IS NULL");
        $conn->query("DROP TEMPORARY TABLE IF EXISTS tmp_keep_motorpool_item_unit_types");
    }
} catch (Throwable $e) {
    error_log('motorpool_item_unit_types dedupe skipped: ' . $e->getMessage());
}
try {
    if (!amgcIndexExists($conn, 'motorpool_item_unit_types', 'item_unit_type_unique')) {
        $conn->query("ALTER TABLE motorpool_item_unit_types ADD UNIQUE KEY item_unit_type_unique (item_id, unit_type_id)");
    }
} catch (Throwable $e) {
    error_log('motorpool_item_unit_types unique index skipped: ' . $e->getMessage());
}
try {
    if (!amgcIndexExists($conn, 'motorpool_item_unit_types', 'idx_motorpool_item_unit_types_barcode')) {
        $conn->query("ALTER TABLE motorpool_item_unit_types ADD KEY idx_motorpool_item_unit_types_barcode (barcode)");
    }
} catch (Throwable $e) {
    error_log('motorpool_item_unit_types barcode index skipped: ' . $e->getMessage());
}


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

function amgcTableExists($conn, $table) {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $result = $conn->query("SHOW TABLES LIKE '$table'");
    return ($result && $result->num_rows > 0);
}


// Audit trail for add/update item. Records are created even if optional fields are blank.
@$conn->query("CREATE TABLE IF NOT EXISTS `motorpool_inventory_item_activity` (
    `activity_id` INT AUTO_INCREMENT PRIMARY KEY,
    `item_id` INT NOT NULL,
    `activity_type` VARCHAR(30) NOT NULL,
    `activity_note` TEXT DEFAULT NULL,
    `performed_by` INT DEFAULT NULL,
    `performed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_item_activity_item` (`item_id`),
    KEY `idx_item_activity_type` (`activity_type`),
    KEY `idx_item_activity_date` (`performed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

function motorpoolRecordItemActivity($conn, $item_id, $activity_type, $performed_by, $activity_note = '') {
    $item_id = (int)$item_id;
    $performed_by = $performed_by ? (int)$performed_by : null;
    $activity_type = trim((string)$activity_type) ?: 'update';
    $activity_note = trim((string)$activity_note);
    if ($item_id <= 0) return;

    @$conn->query("CREATE TABLE IF NOT EXISTS `motorpool_inventory_item_activity` (
        `activity_id` INT AUTO_INCREMENT PRIMARY KEY,
        `item_id` INT NOT NULL,
        `activity_type` VARCHAR(30) NOT NULL,
        `activity_note` TEXT DEFAULT NULL,
        `performed_by` INT DEFAULT NULL,
        `performed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_item_activity_item` (`item_id`),
        KEY `idx_item_activity_type` (`activity_type`),
        KEY `idx_item_activity_date` (`performed_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $stmt = $conn->prepare("INSERT INTO motorpool_inventory_item_activity (item_id, activity_type, activity_note, performed_by, performed_at) VALUES (?, ?, ?, ?, NOW())");
    if ($stmt) {
        $stmt->bind_param('issi', $item_id, $activity_type, $activity_note, $performed_by);
        $stmt->execute();
        $stmt->close();
    }
}

function amgcResolveUnitTypeIdForItemUom($conn, $unit_type_name, $uom_initial, $branch_id, $items_branch_column_exists) {
    $unit_type_name = trim((string)$unit_type_name);
    $uom_initial = strtoupper(trim((string)$uom_initial));
    $branch_id = (int)$branch_id;

    if ($unit_type_name === '') {
        $unit_type_name = 'Piece';
    }

    // motorpool_unit_types is a shared/master list only. Do not update an existing motorpool_unit_types row
    // with item-specific barcode/default/current qty values because that affects every item
    // using the same UOM. Just resolve the id.
    $query = "SELECT unit_type_id FROM motorpool_unit_types WHERE unit_type_name = ?";
    $types = "s";
    $params = [$unit_type_name];

    if ($items_branch_column_exists) {
        $query .= " AND (branch_id = ? OR branch_id IS NULL OR branch_id = 0)";
        $types .= "i";
        $params[] = $branch_id;
    }

    $query .= " ORDER BY CASE WHEN branch_id = ? THEN 0 WHEN branch_id IS NULL OR branch_id = 0 THEN 1 ELSE 2 END, unit_type_id ASC LIMIT 1";
    $types .= "i";
    $params[] = $branch_id;

    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new Exception('Database prepare error while resolving unit type');
    }

    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $unit_type_id = (int)$row['unit_type_id'];
        $stmt->close();
        return $unit_type_id;
    }
    $stmt->close();

    // Create a master UOM row only if it does not exist yet. No item barcode here.
    $status = 'active';
    $qty_smallest = 1;
    $is_default = 0;

    $insert = "INSERT INTO motorpool_unit_types (unit_type_name, uom_initial, barcode, quantity_smallest_pack, is_default_uom, multiplier, branch_id, status)
               VALUES (?, ?, NULL, ?, ?, 1.00, ?, ?)";
    $insert_stmt = $conn->prepare($insert);
    if (!$insert_stmt) {
        throw new Exception('Database prepare error while creating unit type');
    }

    $insert_stmt->bind_param("ssiiis", $unit_type_name, $uom_initial, $qty_smallest, $is_default, $branch_id, $status);
    if (!$insert_stmt->execute()) {
        throw new Exception('Failed to create unit type: ' . $insert_stmt->error);
    }

    $new_id = (int)$conn->insert_id;
    $insert_stmt->close();
    return $new_id;
}

function amgcDeleteDuplicatePricingRows($conn) {
    $check_table = $conn->query("SHOW TABLES LIKE 'motorpool_item_unit_pricing'");
    if (!$check_table || $check_table->num_rows == 0) return;

    $conn->query("CREATE TEMPORARY TABLE IF NOT EXISTS tmp_keep_motorpool_item_unit_pricing AS
        SELECT MAX(pricing_id) AS keep_id
        FROM motorpool_item_unit_pricing
        GROUP BY item_id, unit_type_id, price_level");
    $conn->query("DELETE iup FROM motorpool_item_unit_pricing iup
        LEFT JOIN tmp_keep_motorpool_item_unit_pricing k ON k.keep_id = iup.pricing_id
        WHERE k.keep_id IS NULL");
    $conn->query("DROP TEMPORARY TABLE IF EXISTS tmp_keep_motorpool_item_unit_pricing");
}

function amgcDeleteDuplicateScheduleRows($conn) {
    $check_table = $conn->query("SHOW TABLES LIKE 'motorpool_item_unit_pricing_schedule'");
    if (!$check_table || $check_table->num_rows == 0) return;

    $conn->query("CREATE TEMPORARY TABLE IF NOT EXISTS tmp_keep_item_unit_schedule AS
        SELECT MAX(schedule_id) AS keep_id
        FROM motorpool_item_unit_pricing_schedule
        GROUP BY item_id, unit_type_id, price_level, effective_date");
    $conn->query("DELETE sch FROM motorpool_item_unit_pricing_schedule sch
        LEFT JOIN tmp_keep_item_unit_schedule k ON k.keep_id = sch.schedule_id
        WHERE k.keep_id IS NULL");
    $conn->query("DROP TEMPORARY TABLE IF EXISTS tmp_keep_item_unit_schedule");
}

function amgcDeleteDuplicateInventoryRows($conn) {
    $check_table = $conn->query("SHOW TABLES LIKE 'motorpool_item_unit_inventory'");
    if (!$check_table || $check_table->num_rows == 0) return;

    $conn->query("CREATE TEMPORARY TABLE IF NOT EXISTS tmp_keep_motorpool_item_unit_inventory AS
        SELECT MAX(inventory_id) AS keep_id
        FROM motorpool_item_unit_inventory
        GROUP BY item_id, unit_type_id");
    $conn->query("DELETE inv FROM motorpool_item_unit_inventory inv
        LEFT JOIN tmp_keep_motorpool_item_unit_inventory k ON k.keep_id = inv.inventory_id
        WHERE k.keep_id IS NULL");
    $conn->query("DROP TEMPORARY TABLE IF EXISTS tmp_keep_motorpool_item_unit_inventory");
}

function amgcEnsureNoDuplicateItemUnitTables($conn) {
    amgcDeleteDuplicatePricingRows($conn);
    amgcDeleteDuplicateScheduleRows($conn);
    amgcDeleteDuplicateInventoryRows($conn);

    if (!amgcIndexExists($conn, 'motorpool_item_unit_pricing', 'item_unit_price_level_unique')) {
        @$conn->query("ALTER TABLE motorpool_item_unit_pricing ADD UNIQUE KEY item_unit_price_level_unique (item_id, unit_type_id, price_level)");
    }
    if (!amgcIndexExists($conn, 'motorpool_item_unit_pricing_schedule', 'item_unit_price_schedule_unique')) {
        @$conn->query("ALTER TABLE motorpool_item_unit_pricing_schedule ADD UNIQUE KEY item_unit_price_schedule_unique (item_id, unit_type_id, price_level, effective_date)");
    }
    if (!amgcIndexExists($conn, 'motorpool_item_unit_inventory', 'motorpool_item_unit_inventory_unique')) {
        @$conn->query("ALTER TABLE motorpool_item_unit_inventory ADD UNIQUE KEY motorpool_item_unit_inventory_unique (item_id, unit_type_id)");
    }
}

function amgcUpsertItemUnitPricingStrict($conn, $item_id, $unit_type_id, $unit_price, $unit_quantity, $effective_date, $price_level) {
    $item_id = (int)$item_id;
    $unit_type_id = (int)$unit_type_id;
    $unit_price = is_numeric($unit_price) ? (float)$unit_price : 0;
    $unit_quantity = max(1, (int)$unit_quantity);
    $effective_date = !empty($effective_date) ? $effective_date : null;
    $price_level = trim((string)$price_level) !== '' ? trim((string)$price_level) : 'Standard';

    $check_stmt = $conn->prepare("SELECT pricing_id FROM motorpool_item_unit_pricing WHERE item_id = ? AND unit_type_id = ? AND price_level = ? ORDER BY pricing_id DESC");
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
        $update_stmt = $conn->prepare("UPDATE motorpool_item_unit_pricing SET unit_price = ?, unit_quantity = ?, effective_date = ?, updated_at = NOW() WHERE pricing_id = ?");
        if (!$update_stmt) throw new Exception('Database prepare error while updating unit pricing');
        $update_stmt->bind_param('disi', $unit_price, $unit_quantity, $effective_date, $keep_id);
        if (!$update_stmt->execute()) throw new Exception('Failed to update unit pricing: ' . $update_stmt->error);
        $update_stmt->close();

        if (count($pricing_ids) > 1) {
            $delete_ids = array_slice($pricing_ids, 1);
            $placeholders = implode(',', array_fill(0, count($delete_ids), '?'));
            $types = str_repeat('i', count($delete_ids));
            $delete_stmt = $conn->prepare("DELETE FROM motorpool_item_unit_pricing WHERE pricing_id IN ($placeholders)");
            if ($delete_stmt) {
                $delete_stmt->bind_param($types, ...$delete_ids);
                $delete_stmt->execute();
                $delete_stmt->close();
            }
        }
        return $keep_id;
    }

    $insert_stmt = $conn->prepare("INSERT INTO motorpool_item_unit_pricing (item_id, unit_type_id, unit_price, unit_quantity, effective_date, price_level) VALUES (?, ?, ?, ?, ?, ?)");
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

    $check_stmt = $conn->prepare("SELECT schedule_id FROM motorpool_item_unit_pricing_schedule WHERE item_id = ? AND unit_type_id = ? AND price_level = ? AND effective_date = ? ORDER BY schedule_id DESC");
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
        $update_stmt = $conn->prepare("UPDATE motorpool_item_unit_pricing_schedule SET unit_price = ?, unit_quantity = ?, updated_at = NOW() WHERE schedule_id = ?");
        if (!$update_stmt) throw new Exception('Database prepare error while updating scheduled pricing');
        $update_stmt->bind_param('dii', $unit_price, $unit_quantity, $keep_id);
        if (!$update_stmt->execute()) throw new Exception('Failed to update scheduled pricing: ' . $update_stmt->error);
        $update_stmt->close();

        if (count($schedule_ids) > 1) {
            $delete_ids = array_slice($schedule_ids, 1);
            $placeholders = implode(',', array_fill(0, count($delete_ids), '?'));
            $types = str_repeat('i', count($delete_ids));
            $delete_stmt = $conn->prepare("DELETE FROM motorpool_item_unit_pricing_schedule WHERE schedule_id IN ($placeholders)");
            if ($delete_stmt) {
                $delete_stmt->bind_param($types, ...$delete_ids);
                $delete_stmt->execute();
                $delete_stmt->close();
            }
        }
        return $keep_id;
    }

    $insert_stmt = $conn->prepare("INSERT INTO motorpool_item_unit_pricing_schedule (item_id, unit_type_id, price_level, unit_price, unit_quantity, effective_date) VALUES (?, ?, ?, ?, ?, ?)");
    if (!$insert_stmt) throw new Exception('Database prepare error while inserting scheduled pricing');
    $insert_stmt->bind_param('iisdis', $item_id, $unit_type_id, $price_level, $unit_price, $unit_quantity, $effective_date);
    if (!$insert_stmt->execute()) throw new Exception('Failed to insert scheduled pricing: ' . $insert_stmt->error);
    $new_id = (int)$conn->insert_id;
    $insert_stmt->close();
    return $new_id;
}

amgcEnsureNoDuplicateItemUnitTables($conn);

// ========== MOTORPOOL PRICE LEVEL SELF-HEAL ===========
// Keeps Motorpool pricing tables identical in structure/behavior to Branch Current Inventory.
// This is intentionally lightweight and safe to run; it only creates missing columns/indexes.
try {
    @$conn->query("CREATE TABLE IF NOT EXISTS `motorpool_item_unit_pricing` (
        `pricing_id` int(11) NOT NULL AUTO_INCREMENT,
        `item_id` int(11) NOT NULL,
        `unit_type_id` int(11) NOT NULL,
        `unit_price` decimal(10,2) NOT NULL DEFAULT 0.00,
        `unit_quantity` int(11) DEFAULT 1,
        `effective_date` date DEFAULT NULL,
        `price_level` varchar(50) DEFAULT 'Standard',
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (`pricing_id`),
        UNIQUE KEY `item_unit_price_level_unique` (`item_id`, `unit_type_id`, `price_level`),
        KEY `idx_effective_date` (`effective_date`),
        KEY `idx_price_level` (`price_level`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    foreach ([
        'unit_quantity' => "`unit_quantity` INT(11) DEFAULT 1 AFTER `unit_price`",
        'effective_date' => "`effective_date` DATE DEFAULT NULL AFTER `unit_quantity`",
        'price_level' => "`price_level` VARCHAR(50) DEFAULT 'Standard' AFTER `effective_date`",
        'created_at' => "`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `price_level`",
        'updated_at' => "`updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`"
    ] as $col => $def) {
        if (!amgcColumnExists($conn, 'motorpool_item_unit_pricing', $col)) {
            @$conn->query("ALTER TABLE motorpool_item_unit_pricing ADD COLUMN $def");
        }
    }

    @$conn->query("UPDATE motorpool_item_unit_pricing SET price_level = 'Standard' WHERE price_level IS NULL OR TRIM(price_level) = ''");
    amgcEnsureNoDuplicateItemUnitTables($conn);
} catch (Throwable $e) {
    error_log('Motorpool price level self-heal skipped: ' . $e->getMessage());
}


$check_beginning_inventory_column = $conn->query("SHOW COLUMNS FROM motorpool_item_unit_inventory LIKE 'beginning_inventory'");
if (!$check_beginning_inventory_column || $check_beginning_inventory_column->num_rows == 0) {
    $conn->query("ALTER TABLE motorpool_item_unit_inventory ADD COLUMN beginning_inventory decimal(12,2) NOT NULL DEFAULT 0.00 AFTER current_inventory");
}
$conn->query("UPDATE motorpool_item_unit_inventory SET beginning_inventory = current_inventory WHERE beginning_inventory IS NULL OR beginning_inventory = 0");
$check_total_cost_column = $conn->query("SHOW COLUMNS FROM motorpool_item_unit_inventory LIKE 'total_cost'");
if (!$check_total_cost_column || $check_total_cost_column->num_rows == 0) {
    $conn->query("ALTER TABLE motorpool_item_unit_inventory ADD COLUMN total_cost decimal(14,2) NOT NULL DEFAULT 0.00 AFTER unit_cost");
    $conn->query("UPDATE motorpool_item_unit_inventory SET total_cost = COALESCE(current_inventory, 0) * COALESCE(unit_cost, 0) WHERE total_cost IS NULL OR total_cost = 0");
}


$create_motorpool_item_images = "CREATE TABLE IF NOT EXISTS `motorpool_item_images` (
    `image_id` int(11) NOT NULL AUTO_INCREMENT,
    `item_id` int(11) NOT NULL,
    `image_path` varchar(255) NOT NULL,
    `image_order` int(11) DEFAULT 0,
    `is_primary` tinyint(1) DEFAULT 0,
    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`image_id`),
    KEY `idx_item_id` (`item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
$conn->query($create_motorpool_item_images);

$check_product_image_column = $conn->query("SHOW COLUMNS FROM motorpool_inventory_items LIKE 'product_image_url'");
if (!$check_product_image_column || $check_product_image_column->num_rows == 0) {
    $add_product_image_column = "ALTER TABLE motorpool_inventory_items ADD COLUMN product_image_url VARCHAR(255) DEFAULT NULL AFTER item_name";
    $conn->query($add_product_image_column);
}

// Principal field for inventory items (optional). Blank values are displayed as "No Principal".
$check_principal_column = $conn->query("SHOW COLUMNS FROM motorpool_inventory_items LIKE 'principal'");
if (!$check_principal_column || $check_principal_column->num_rows == 0) {
    $conn->query("ALTER TABLE motorpool_inventory_items ADD COLUMN principal VARCHAR(150) DEFAULT NULL AFTER category");
}

// Volume field for Oil items
$check_volume_column = $conn->query("SHOW COLUMNS FROM motorpool_inventory_items LIKE 'volume'");
if (!$check_volume_column || $check_volume_column->num_rows == 0) {
    $conn->query("ALTER TABLE motorpool_inventory_items ADD COLUMN volume VARCHAR(100) DEFAULT NULL AFTER principal");
}

// Oil Type field for Oil items (Palm or Coconut)
$check_oil_type_column = $conn->query("SHOW COLUMNS FROM motorpool_inventory_items LIKE 'oil_type'");
if (!$check_oil_type_column || $check_oil_type_column->num_rows == 0) {
    $conn->query("ALTER TABLE motorpool_inventory_items ADD COLUMN oil_type VARCHAR(50) DEFAULT NULL AFTER volume");
}


// Barcode field for inventory items. This is where generated/manual UoM barcode is saved.
$check_item_barcode_column = $conn->query("SHOW COLUMNS FROM motorpool_inventory_items LIKE 'barcode'");
if (!$check_item_barcode_column || $check_item_barcode_column->num_rows == 0) {
    $conn->query("ALTER TABLE motorpool_inventory_items ADD COLUMN barcode VARCHAR(100) DEFAULT NULL AFTER item_code");
}


// Loyalty points eligibility flag for POS. 1 = earns points, 0 = exempted from points.
$check_points_eligible_column = $conn->query("SHOW COLUMNS FROM motorpool_inventory_items LIKE 'points_eligible'");
if (!$check_points_eligible_column || $check_points_eligible_column->num_rows == 0) {
    $conn->query("ALTER TABLE motorpool_inventory_items ADD COLUMN points_eligible TINYINT(1) NOT NULL DEFAULT 1 AFTER status");
}

// QuickBooks-style linked accounts for inventory items
$check_income_account_column = $conn->query("SHOW COLUMNS FROM motorpool_inventory_items LIKE 'income_account_id'");
if (!$check_income_account_column || $check_income_account_column->num_rows == 0) {
    $conn->query("ALTER TABLE motorpool_inventory_items ADD COLUMN income_account_id INT(11) DEFAULT NULL AFTER oil_type");
}
$check_cogs_account_column = $conn->query("SHOW COLUMNS FROM motorpool_inventory_items LIKE 'cogs_account_id'");
if (!$check_cogs_account_column || $check_cogs_account_column->num_rows == 0) {
    $conn->query("ALTER TABLE motorpool_inventory_items ADD COLUMN cogs_account_id INT(11) DEFAULT NULL AFTER income_account_id");
}
$check_asset_account_column = $conn->query("SHOW COLUMNS FROM motorpool_inventory_items LIKE 'asset_account_id'");
if (!$check_asset_account_column || $check_asset_account_column->num_rows == 0) {
    $conn->query("ALTER TABLE motorpool_inventory_items ADD COLUMN asset_account_id INT(11) DEFAULT NULL AFTER cogs_account_id");
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
        SELECT DISTINCT i.item_id FROM motorpool_inventory_items i
        LEFT JOIN motorpool_item_unit_inventory inv ON inv.item_id = i.item_id AND inv.unit_type_id = i.default_unit_type_id
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
    $history_check = $conn->query("SHOW TABLES LIKE 'motorpool_item_unit_pricing_history'");
    if (!$history_check || $history_check->num_rows == 0) {
        return;
    }

    $price_level = $price_level ?: 'Standard';
    $unit_quantity = max(1, (int)$unit_quantity);
    $effective_date = !empty($effective_date) ? $effective_date : null;
    $created_by = $created_by ? (int)$created_by : null;

    $history_query = "INSERT INTO motorpool_item_unit_pricing_history (item_id, unit_type_id, price_level, unit_price, unit_quantity, effective_date, history_type, created_by)
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
        FROM motorpool_inventory_items i
        LEFT JOIN motorpool_item_unit_pricing iup 
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

    $update_query = "UPDATE motorpool_inventory_items
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
        FROM motorpool_item_unit_pricing_schedule
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

        $check_query = "SELECT pricing_id FROM motorpool_item_unit_pricing WHERE item_id = ? AND unit_type_id = ? AND price_level = ? LIMIT 1";
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
            $update_query = "UPDATE motorpool_item_unit_pricing
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
            $insert_query = "INSERT INTO motorpool_item_unit_pricing (item_id, unit_type_id, unit_price, unit_quantity, effective_date, price_level)
                VALUES (?, ?, ?, ?, ?, ?)";
            $insert_stmt = $conn->prepare($insert_query);
            if ($insert_stmt) {
                $insert_stmt->bind_param('iidiss', $item_id, $unit_type_id, $unit_price, $unit_quantity, $effective_date, $price_level);
                $insert_stmt->execute();
                $insert_stmt->close();
            }
        }

        $delete_stmt = $conn->prepare("DELETE FROM motorpool_item_unit_pricing_schedule WHERE schedule_id = ?");
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
    $query = "INSERT INTO motorpool_item_unit_inventory (item_id, unit_type_id, current_inventory, beginning_inventory, as_of_date, unit_cost, total_cost)
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


function upsertItemUnitTypeRow($conn, $item_id, $unit_type_id, $unit_type_name, $uom_initial, $barcode, $smallest_pack_quantity, $is_default_uom, $branch_id, $status = 'active') {
    $item_id = (int)$item_id;
    $unit_type_id = (int)$unit_type_id;
    $unit_type_name = trim((string)$unit_type_name);
    $uom_initial = strtoupper(trim((string)$uom_initial));
    $barcode = trim((string)$barcode);
    $smallest_pack_quantity = max(1, (float)$smallest_pack_quantity);
    $is_default_uom = (int)$is_default_uom === 1 ? 1 : 0;
    $branch_id = (int)$branch_id;
    $status = trim((string)$status) !== '' ? trim((string)$status) : 'active';

    if ($item_id <= 0 || $unit_type_id <= 0 || $unit_type_name === '') {
        return;
    }

    $query = "INSERT INTO motorpool_item_unit_types
        (item_id, unit_type_id, unit_type_name, uom_initial, barcode, smallest_pack_quantity, is_default_uom, branch_id, status, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ON DUPLICATE KEY UPDATE
            unit_type_name = VALUES(unit_type_name),
            uom_initial = VALUES(uom_initial),
            barcode = VALUES(barcode),
            smallest_pack_quantity = VALUES(smallest_pack_quantity),
            is_default_uom = VALUES(is_default_uom),
            branch_id = VALUES(branch_id),
            status = VALUES(status),
            updated_at = NOW()";
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new Exception('Database prepare error while saving item UOM barcode');
    }
    $stmt->bind_param('iisssdiis', $item_id, $unit_type_id, $unit_type_name, $uom_initial, $barcode, $smallest_pack_quantity, $is_default_uom, $branch_id, $status);
    if (!$stmt->execute()) {
        throw new Exception('Failed to save item UOM barcode: ' . $stmt->error);
    }
    $stmt->close();
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
        FROM motorpool_inventory_items i
        LEFT JOIN motorpool_unit_types ut ON i.default_unit_type_id = ut.unit_type_id
        LEFT JOIN motorpool_item_unit_inventory inv ON inv.item_id = i.item_id AND inv.unit_type_id = i.default_unit_type_id
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

    $update_query = "UPDATE motorpool_inventory_items
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
    // Do NOT recompute motorpool_item_unit_inventory from ledger here.
    // Do NOT insert new motorpool_item_unit_inventory rows here.
    // Do NOT zero other unit rows here.
    // This function only mirrors the existing motorpool_item_unit_inventory current stock to items.stock.
    // Source of truth remains motorpool_item_unit_inventory.
    if ($item_id <= 0) return;

    $hasInvBranch = amgcColumnExists($conn, 'motorpool_item_unit_inventory', 'branch_id');
    $branchWhere = ($branch_id > 0 && $hasInvBranch) ? ' AND branch_id = ?' : '';

    $totalStock = 0.0;
    $stockSql = "SELECT COALESCE(SUM(current_inventory), 0) AS total_stock
                 FROM motorpool_item_unit_inventory
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

    if (amgcColumnExists($conn, 'motorpool_inventory_items', 'stock')) {
        if ($stmt = $conn->prepare("UPDATE motorpool_inventory_items SET stock = ?, updated_at = NOW() WHERE item_id = ?")) {
            $stmt->bind_param('di', $totalStock, $item_id);
            $stmt->execute();
            $stmt->close();
        }
    }
}

function amgcSyncAllBranchStocksFromLedger(mysqli $conn, int $branch_id = 0, bool $view_all_branches = false): void {
    if (!amgcColumnExists($conn, 'motorpool_inventory_items', 'item_id')) return;
    $where = "WHERE i.status = 'active'";
    if (!$view_all_branches && $branch_id > 0 && amgcColumnExists($conn, 'motorpool_inventory_items', 'branch_id')) {
        $where .= " AND i.branch_id = " . intval($branch_id);
    }
    $result = $conn->query("SELECT i.item_id FROM motorpool_inventory_items i $where");
    if (!$result) return;
    while ($row = $result->fetch_assoc()) {
        amgcSyncItemStockFromLedger($conn, (int)$row['item_id'], (!$view_all_branches ? $branch_id : 0));
    }
}



// ========== SYNC RECEIVE INVENTORY / RETURNED MERCHANDISE TO PER-UOM INVENTORY ==========
// Current Inventory displays stocks from motorpool_item_unit_inventory.
// Receive Inventory / Returned Merchandise can record stock in motorpool_inventory_transactions,
// so this function applies those unprocessed incoming transactions into motorpool_item_unit_inventory once only.
function syncReceivedInventoryTransactionsToUnitInventory($conn, $branch_id, $user_id, $items_branch_column_exists, $view_all_branches) {
    $check_trans_table = $conn->query("SHOW TABLES LIKE 'motorpool_inventory_transactions'");
    if (!$check_trans_table || $check_trans_table->num_rows == 0) {
        return;
    }

    $check_unit_inv_table = $conn->query("SHOW TABLES LIKE 'motorpool_item_unit_inventory'");
    if (!$check_unit_inv_table || $check_unit_inv_table->num_rows == 0) {
        return;
    }

    $conn->query("CREATE TABLE IF NOT EXISTS `motorpool_item_unit_inventory_receive_sync_log` (
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
    $trans_cols_result = $conn->query("SHOW COLUMNS FROM motorpool_inventory_transactions");
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
        FROM motorpool_inventory_transactions it
        JOIN motorpool_inventory_items i ON i.item_id = it.item_id
        LEFT JOIN motorpool_unit_types utd_sync ON utd_sync.unit_type_id = i.default_unit_type_id
        LEFT JOIN motorpool_item_unit_inventory_receive_sync_log log ON log.transaction_id = it.transaction_id
        LEFT JOIN motorpool_item_unit_inventory inv ON inv.item_id = i.item_id AND inv.unit_type_id = i.default_unit_type_id
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
            $find_unit_stmt = $conn->prepare("SELECT unit_type_id FROM motorpool_unit_types WHERE unit_type_name = ? AND status = 'active' LIMIT 1");
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
            $unit_cost = importMoneyValue($row['unit_price']);
        }

        $current_inventory = isset($row['current_inventory']) ? importMoneyValue($row['current_inventory']) : 0;
        $existing_total_cost = isset($row['existing_total_cost']) ? (float)$row['existing_total_cost'] : 0;

        $check_current_stmt = $conn->prepare("SELECT current_inventory, unit_cost, COALESCE(NULLIF(total_cost, 0), COALESCE(current_inventory, 0) * COALESCE(unit_cost, 0)) AS total_cost FROM motorpool_item_unit_inventory WHERE item_id = ? AND unit_type_id = ? LIMIT 1");
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

        $log_stmt = $conn->prepare("INSERT IGNORE INTO motorpool_item_unit_inventory_receive_sync_log (transaction_id, item_id, unit_type_id, quantity_added, reference_type, reference_id) VALUES (?, ?, ?, ?, ?, ?)");
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
    $upload_dir = '../uploads/motorpool_inventory/';
    
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
    $query = "SELECT item_code FROM motorpool_inventory_items WHERE item_code LIKE 'ITEM%'";
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
    $check_ut_query = "SELECT unit_type_id FROM motorpool_unit_types WHERE unit_type_name = ?";
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
        // Existing motorpool_unit_types rows are shared by many items. Never update barcode,
        // default flag, or smallest pack here because it will affect unrelated items.
        return (int)$ut_row['unit_type_id'];
    }

    $insert_ut_query = "INSERT INTO motorpool_unit_types (unit_type_name, barcode, quantity_smallest_pack, is_default_uom, multiplier, branch_id, status) VALUES (?, NULL, ?, ?, 1.00, ?, ?)";
    $insert_ut_stmt = $conn->prepare($insert_ut_query);
    if (!$insert_ut_stmt) {
        throw new Exception('Database prepare error while creating unit type');
    }
    $insert_ut_stmt->bind_param("siiis", $unit_type_name, $qty_smallest_pack, $is_default_uom, $branch_id, $status);
    if (!$insert_ut_stmt->execute()) {
        throw new Exception('Failed to create unit type: ' . $insert_ut_stmt->error);
    }
    return (int)$conn->insert_id;
}

function importValueProvided($row, $key) {
    return array_key_exists($key, $row) && trim((string)$row[$key]) !== '';
}

function importMoneyValue($value, $default = 0) {
    if ($value === null) return $default;
    $raw = trim((string)$value);
    if ($raw === '') return $default;
    $raw = str_replace(["₱", "PHP", "php", ",", " "], '', $raw);
    $raw = preg_replace('/[^0-9.\-]/', '', $raw);
    return is_numeric($raw) ? (float)$raw : $default;
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

    // IMPORTANT FIX FOR IMPORT:
    // If the Excel row has an item_code, that item_code is the unique identity of the item.
    // Do NOT fall back to item_name when the code is new. Tire rows commonly share the same
    // item_name but have different serial/item codes, so matching by name caused only 1-2 tires
    // to be inserted while the rest kept updating the same item.
    if ($item_code !== '') {
        $query = "SELECT item_id, item_code, item_name, description, category, principal, volume, stock, reorder_level, status, unit_type, unit_price, product_image_url, default_unit_type_id FROM motorpool_inventory_items WHERE item_code = ?";
        $types = 's';
        $params = [$item_code];
        if ($items_branch_column_exists && !$view_all_branches && $branch_id > 0) {
            $query .= " AND (branch_id = ? OR branch_id IS NULL OR branch_id = 0)";
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
        return null;
    }

    // Fallback by item_name is allowed ONLY for old/import templates where item_code is blank.
    if ($item_name !== '') {
        $query = "SELECT item_id, item_code, item_name, description, category, principal, volume, stock, reorder_level, status, unit_type, unit_price, product_image_url, default_unit_type_id FROM motorpool_inventory_items WHERE LOWER(TRIM(CONVERT(item_name USING utf8mb4) COLLATE utf8mb4_unicode_ci)) = LOWER(TRIM(CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci))";
        $types = 's';
        $params = [$item_name];
        if ($items_branch_column_exists && !$view_all_branches && $branch_id > 0) {
            $query .= " AND (branch_id = ? OR branch_id IS NULL OR branch_id = 0)";
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
    $volume = importValueProvided($base, 'volume') ? trim((string)$base['volume']) : ($is_update ? ($existing_item['volume'] ?? null) : null);
    if ($volume === '') $volume = null;
    $stock = importValueProvided($base, 'stock') ? importMoneyValue($base['stock']) : ($is_update ? importMoneyValue($existing_item['stock'] ?? 0) : importMoneyValue($firstRow['current_inventory'] ?? 0));
    $reorder_level = importValueProvided($base, 'reorder_level') ? (int)importMoneyValue($base['reorder_level']) : ($is_update ? (int)importMoneyValue($existing_item['reorder_level'] ?? 0) : 0);
    $status = importValueProvided($base, 'status') ? trim((string)$base['status']) : ($is_update ? ($existing_item['status'] ?? 'active') : 'active');
    $unit_type = importValueProvided($firstRow, 'unit_type') ? trim((string)$firstRow['unit_type']) : ($is_update ? ($existing_item['unit_type'] ?? 'Piece') : 'Piece');
    $unit_price = importValueProvided($firstRow, 'unit_price') ? importMoneyValue($firstRow['unit_price']) : ($is_update ? importMoneyValue($existing_item['unit_price'] ?? 0) : 0);
    $price_case = $unit_price * 12;
    $price_inner_pack = $unit_price * 6;
    $price_box = $unit_price * 24;
    $price_carton = $unit_price * 48;
    $product_image_url = $is_update ? ($existing_item['product_image_url'] ?? null) : null;

    if ($is_update) {
        $item_id = (int)$existing_item['item_id'];
        $update_query = "UPDATE motorpool_inventory_items SET item_code = ?, item_name = ?, description = ?, category = ?, stock = ?, unit_type = ?, unit_price = ?, price_case = ?, price_inner_pack = ?, price_box = ?, price_carton = ?, reorder_level = ?, status = ?, updated_by = ?, updated_at = NOW() WHERE item_id = ?";
        $update_stmt = $conn->prepare($update_query);
        if (!$update_stmt) throw new Exception('Database prepare error while updating imported item');
        $update_stmt->bind_param("ssssdsdddddisii", $item_code, $item_name, $description, $category, $stock, $unit_type, $unit_price, $price_case, $price_inner_pack, $price_box, $price_carton, $reorder_level, $status, $user_id, $item_id);
        if (!$update_stmt->execute()) throw new Exception('Failed to update imported item: ' . $update_stmt->error);
        motorpoolRecordItemActivity($conn, $item_id, 'updated', $user_id, 'Item updated by import');
    } else {
        if ($items_branch_column_exists) {
            $insert_query = "INSERT INTO motorpool_inventory_items (item_code, item_name, description, category, stock, unit_type, unit_price, price_case, price_inner_pack, price_box, price_carton, reorder_level, status, product_image_url, branch_id, created_by, updated_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
            $insert_stmt = $conn->prepare($insert_query);
            if (!$insert_stmt) throw new Exception('Database prepare error while inserting item');
            $insert_stmt->bind_param("ssssdsdddddissiii", $item_code, $item_name, $description, $category, $stock, $unit_type, $unit_price, $price_case, $price_inner_pack, $price_box, $price_carton, $reorder_level, $status, $product_image_url, $branch_id, $user_id, $user_id);
        } else {
            $insert_query = "INSERT INTO motorpool_inventory_items (item_code, item_name, description, category, stock, unit_type, unit_price, price_case, price_inner_pack, price_box, price_carton, reorder_level, status, product_image_url, created_by, updated_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
            $insert_stmt = $conn->prepare($insert_query);
            if (!$insert_stmt) throw new Exception('Database prepare error while inserting item');
            $insert_stmt->bind_param("ssssdsdddddissii", $item_code, $item_name, $description, $category, $stock, $unit_type, $unit_price, $price_case, $price_inner_pack, $price_box, $price_carton, $reorder_level, $status, $product_image_url, $user_id, $user_id);
        }
        if (!$insert_stmt->execute()) throw new Exception('Failed to add imported item: ' . $insert_stmt->error);
        $item_id = (int)$conn->insert_id;
        motorpoolRecordItemActivity($conn, $item_id, 'created', $user_id, 'Item created by import');
    }

            $principal_update_stmt = $conn->prepare("UPDATE motorpool_inventory_items SET principal = ?, volume = ? WHERE item_id = ?");
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
        $ut_qty_smallest = importValueProvided($row, 'qty_smallest_pack') ? max(1, (int)importMoneyValue($row['qty_smallest_pack'])) : 1;
        $ut_is_default = importValueProvided($row, 'default_uom') ? normalizeImportBoolean($row['default_uom']) : 0;
        $ut_status = importValueProvided($row, 'unit_status') ? trim((string)$row['unit_status']) : 'active';
        $ut_quantity = importValueProvided($row, 'unit_quantity') ? max(1, (int)importMoneyValue($row['unit_quantity'])) : $ut_qty_smallest;
        $effective_date = importValueProvided($row, 'effective_date') ? normalizeImportDateValue($row['effective_date']) : null;
        $price_level = importValueProvided($row, 'price_level') ? trim((string)$row['price_level']) : 'Standard';

        $ut_id = importCreateOrGetUnitType($conn, $ut_name, $ut_barcode, $ut_qty_smallest, $ut_is_default, $ut_status, $branch_id, $items_branch_column_exists);
        if ($ut_is_default || !$default_unit_type_id) $default_unit_type_id = $ut_id;

        if (importValueProvided($row, 'current_inventory') || importValueProvided($row, 'stock') || importValueProvided($row, 'unit_cost') || importValueProvided($row, 'as_of_date')) {
            $current_inventory = importValueProvided($row, 'current_inventory') ? importMoneyValue($row['current_inventory']) : (importValueProvided($row, 'stock') ? importMoneyValue($row['stock']) : 0);
            $as_of_date = importValueProvided($row, 'as_of_date') ? normalizeImportDateValue($row['as_of_date']) : null;
            $unit_cost = importValueProvided($row, 'unit_cost') ? importMoneyValue($row['unit_cost']) : (importValueProvided($row, 'unit_price') ? importMoneyValue($row['unit_price']) : 0);
            upsertItemUnitInventory($conn, $item_id, $ut_id, $current_inventory, $as_of_date, $unit_cost);
        }

        if (importValueProvided($row, 'unit_price')) {
            $ut_price = importMoneyValue($row['unit_price']);
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

                $existing_schedule_query = "SELECT unit_price, unit_quantity, effective_date FROM motorpool_item_unit_pricing_schedule WHERE item_id = ? AND unit_type_id = ? AND price_level = ? AND effective_date = ? LIMIT 1";
                $existing_schedule_stmt = $conn->prepare($existing_schedule_query);
                if (!$existing_schedule_stmt) throw new Exception('Database prepare error while checking imported scheduled pricing');
                $existing_schedule_stmt->bind_param("iiss", $item_id, $ut_id, $price_level, $new_effective_date);
                $existing_schedule_stmt->execute();
                $existing_schedule_result = $existing_schedule_stmt->get_result();
                if ($existing_schedule_result && $existing_schedule_row = $existing_schedule_result->fetch_assoc()) {
                    $old_schedule_price = round((float)$existing_schedule_row['unit_price'], 4);
                    $new_schedule_price = round(importMoneyValue($ut_price), 4);
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

            $check_pricing_query = "SELECT pricing_id, unit_price, unit_quantity, effective_date FROM motorpool_item_unit_pricing WHERE item_id = ? AND unit_type_id = ? AND price_level = ? ORDER BY pricing_id DESC LIMIT 1";
            $check_pricing_stmt = $conn->prepare($check_pricing_query);
            if (!$check_pricing_stmt) throw new Exception('Database prepare error while checking imported pricing');
            $check_pricing_stmt->bind_param("iis", $item_id, $ut_id, $price_level);
            $check_pricing_stmt->execute();
            $pricing_result = $check_pricing_stmt->get_result();
            if ($pricing_result && $pricing_row = $pricing_result->fetch_assoc()) {
                $old_price = round((float)$pricing_row['unit_price'], 4);
                $new_price = round(importMoneyValue($ut_price), 4);
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
        $update_default = "UPDATE motorpool_inventory_items SET default_unit_type_id = ? WHERE item_id = ?";
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



// ========== MOTORPOOL TIRE PROFILING EXTENSION ==========
// Applies only to inventory items with category Tire/Tires.
$conn->query("CREATE TABLE IF NOT EXISTS `motorpool_tire_profiles` (
    `tire_profile_id` INT AUTO_INCREMENT PRIMARY KEY,
    `item_id` INT NOT NULL UNIQUE,
    `serial_number` VARCHAR(120) DEFAULT NULL,
    `brand` VARCHAR(150) DEFAULT NULL,
    `tire_size` VARCHAR(120) DEFAULT NULL,
    `pattern` VARCHAR(120) DEFAULT NULL,
    `purchase_date` DATE DEFAULT NULL,
    `supplier_name` VARCHAR(255) DEFAULT NULL,
    `invoice_no` VARCHAR(120) DEFAULT NULL,
    `purchase_cost` DECIMAL(14,2) DEFAULT 0.00,
    `current_status` VARCHAR(60) DEFAULT 'Warehouse',
    `current_truck` VARCHAR(180) DEFAULT NULL,
    `current_plate` VARCHAR(80) DEFAULT NULL,
    `current_position` VARCHAR(80) DEFAULT NULL,
    `remaining_tread` VARCHAR(80) DEFAULT NULL,
    `remarks` TEXT DEFAULT NULL,
    `created_by` INT DEFAULT NULL,
    `updated_by` INT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_tire_item` (`item_id`),
    KEY `idx_tire_serial` (`serial_number`),
    KEY `idx_tire_status` (`current_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Safety for older databases: add all fields needed by Tire Profiling even if the table already existed.
foreach ([
    'serial_number' => "`serial_number` VARCHAR(120) DEFAULT NULL AFTER `item_id`",
    'brand' => "`brand` VARCHAR(150) DEFAULT NULL AFTER `serial_number`",
    'tire_size' => "`tire_size` VARCHAR(120) DEFAULT NULL AFTER `brand`",
    'pattern' => "`pattern` VARCHAR(120) DEFAULT NULL AFTER `tire_size`",
    'purchase_date' => "`purchase_date` DATE DEFAULT NULL AFTER `pattern`",
    'supplier_name' => "`supplier_name` VARCHAR(255) DEFAULT NULL AFTER `purchase_date`",
    'invoice_no' => "`invoice_no` VARCHAR(120) DEFAULT NULL AFTER `supplier_name`",
    'purchase_cost' => "`purchase_cost` DECIMAL(14,2) DEFAULT 0.00 AFTER `invoice_no`",
    'current_status' => "`current_status` VARCHAR(60) DEFAULT 'Warehouse' AFTER `purchase_cost`",
    'current_truck' => "`current_truck` VARCHAR(180) DEFAULT NULL AFTER `current_status`",
    'current_plate' => "`current_plate` VARCHAR(80) DEFAULT NULL AFTER `current_truck`",
    'current_position' => "`current_position` VARCHAR(80) DEFAULT NULL AFTER `current_plate`",
    'remaining_tread' => "`remaining_tread` VARCHAR(80) DEFAULT NULL AFTER `current_position`",
    'remarks' => "`remarks` TEXT DEFAULT NULL AFTER `remaining_tread`",
    'created_by' => "`created_by` INT DEFAULT NULL AFTER `remarks`",
    'updated_by' => "`updated_by` INT DEFAULT NULL AFTER `created_by`"
] as $col => $def) { motorpoolEnsureColumn($conn, 'motorpool_tire_profiles', $col, $def); }

$conn->query("CREATE TABLE IF NOT EXISTS `motorpool_tire_history` (
    `history_id` INT AUTO_INCREMENT PRIMARY KEY,
    `tire_profile_id` INT NOT NULL,
    `item_id` INT NOT NULL,
    `action_type` VARCHAR(60) NOT NULL,
    `action_date` DATE NOT NULL,
    `truck_name` VARCHAR(180) DEFAULT NULL,
    `plate_number` VARCHAR(80) DEFAULT NULL,
    `position` VARCHAR(80) DEFAULT NULL,
    `odometer` VARCHAR(80) DEFAULT NULL,
    `damage_description` TEXT DEFAULT NULL,
    `repair_cost` DECIMAL(14,2) DEFAULT 0.00,
    `shop_name` VARCHAR(180) DEFAULT NULL,
    `remarks` TEXT DEFAULT NULL,
    `attachment_path` VARCHAR(255) DEFAULT NULL,
    `created_by` INT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_tire_history_profile` (`tire_profile_id`),
    KEY `idx_tire_history_item` (`item_id`),
    KEY `idx_tire_history_action` (`action_type`),
    KEY `idx_tire_history_date` (`action_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

function motorpoolIsTireCategoryValue($category) {
    $c = strtolower(trim((string)$category));
    return in_array($c, ['tire','tires','wheel tire','truck tire','truck tires'], true) || strpos($c, 'tire') !== false;
}

function motorpoolEnsureTireProfile(mysqli $conn, int $item_id, int $user_id = 0): int {
    $item_id = (int)$item_id;
    if ($item_id <= 0) return 0;

    $stmt = $conn->prepare("SELECT tire_profile_id FROM motorpool_tire_profiles WHERE item_id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $item_id);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) { $stmt->close(); return (int)$row['tire_profile_id']; }
        $stmt->close();
    }

    $item = null;
    $q = $conn->prepare("SELECT item_id, item_code, barcode, item_name, category, principal, unit_cost, unit_price, supplier, created_at FROM motorpool_inventory_items WHERE item_id = ? LIMIT 1");
    if ($q) {
        $q->bind_param('i', $item_id);
        $q->execute();
        $r = $q->get_result();
        $item = $r ? $r->fetch_assoc() : null;
        $q->close();
    }
    if (!$item || !motorpoolIsTireCategoryValue($item['category'] ?? '')) return 0;

    $serial = trim((string)($item['barcode'] ?: $item['item_code']));
    $brand = trim((string)($item['principal'] ?: ''));
    $cost = (float)($item['unit_cost'] ?: $item['unit_price'] ?: 0);
    $supplier = trim((string)($item['supplier'] ?? ''));
    $purchase_date = !empty($item['created_at']) ? date('Y-m-d', strtotime($item['created_at'])) : date('Y-m-d');

    $ins = $conn->prepare("INSERT INTO motorpool_tire_profiles (item_id, serial_number, brand, purchase_date, supplier_name, purchase_cost, current_status, created_by, updated_by) VALUES (?, ?, ?, ?, ?, ?, 'Warehouse', ?, ?)");
    if (!$ins) return 0;
    $ins->bind_param('issssdii', $item_id, $serial, $brand, $purchase_date, $supplier, $cost, $user_id, $user_id);
    $ins->execute();
    $profile_id = (int)$conn->insert_id;
    $ins->close();

    if ($profile_id > 0) {
        $hist = $conn->prepare("INSERT INTO motorpool_tire_history (tire_profile_id, item_id, action_type, action_date, remarks, created_by) VALUES (?, ?, 'PURCHASED', ?, 'Auto-created from Current Inventory tire item.', ?)");
        if ($hist) {
            $hist->bind_param('iisi', $profile_id, $item_id, $purchase_date, $user_id);
            $hist->execute();
            $hist->close();
        }
    }
    return $profile_id;
}


if (!function_exists('motorpoolTableExistsFast')) {
function motorpoolTableExistsFast(mysqli $conn, string $table): bool {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    if ($table === '') return false;
    $res = @$conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($table) . "'");
    return $res && $res->num_rows > 0;
}
}

if (!function_exists('motorpoolGetTableColumnsFast')) {
function motorpoolGetTableColumnsFast(mysqli $conn, string $table): array {
    static $cache = [];
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    if ($table === '') return [];
    if (isset($cache[$table])) return $cache[$table];
    $cols = [];
    $res = @$conn->query("SHOW COLUMNS FROM `$table`");
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $cols[$r['Field']] = strtolower((string)($r['Type'] ?? ''));
        }
    }
    $cache[$table] = $cols;
    return $cols;
}
}

if (!function_exists('motorpoolPickColumn')) {
function motorpoolPickColumn(array $cols, array $candidates): ?string {
    $lowerMap = [];
    foreach ($cols as $name => $type) $lowerMap[strtolower($name)] = $name;
    foreach ($candidates as $c) {
        $k = strtolower($c);
        if (isset($lowerMap[$k])) return $lowerMap[$k];
    }
    foreach ($candidates as $c) {
        $needle = strtolower($c);
        foreach ($lowerMap as $low => $orig) {
            if (strpos($low, $needle) !== false) return $orig;
        }
    }
    return null;
}
}

if (!function_exists('motorpoolSearchRowsByText')) {
function motorpoolSearchRowsByText(mysqli $conn, string $table, array $needles, int $limit = 30): array {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    if ($table === '' || !motorpoolTableExistsFast($conn, $table)) return [];
    $cols = motorpoolGetTableColumnsFast($conn, $table);
    if (!$cols) return [];

    $searchCols = [];
    foreach ($cols as $c => $type) {
        if (preg_match('/char|text|enum|set|date|time|int|decimal|double|float/i', $type)) {
            $searchCols[] = $c;
        }
    }
    if (!$searchCols) return [];

    $validNeedles = [];
    foreach ($needles as $n) {
        $n = trim((string)$n);
        if ($n !== '' && strlen($n) >= 2) $validNeedles[] = $n;
    }
    $validNeedles = array_values(array_unique($validNeedles));
    if (!$validNeedles) return [];

    $whereParts = [];
    $params = [];
    foreach ($validNeedles as $needle) {
        $ors = [];
        foreach ($searchCols as $c) {
            $ors[] = "CAST(`$c` AS CHAR) LIKE ?";
            $params[] = '%' . $needle . '%';
        }
        $whereParts[] = '(' . implode(' OR ', $ors) . ')';
    }
    $sql = "SELECT * FROM `$table` WHERE " . implode(' OR ', $whereParts) . " LIMIT " . max(1, min(100, $limit));
    $stmt = @$conn->prepare($sql);
    if (!$stmt) return [];
    $types = str_repeat('s', count($params));
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    if ($res) while ($r = $res->fetch_assoc()) $rows[] = $r;
    $stmt->close();
    return $rows;
}
}

if (!function_exists('motorpoolVal')) {
function motorpoolVal(array $row, array $cols, array $candidates, string $default = ''): string {
    $c = motorpoolPickColumn($cols, $candidates);
    if ($c === null) return $default;
    return trim((string)($row[$c] ?? $default));
}
}

if (!function_exists('motorpoolFirstDateVal')) {
function motorpoolFirstDateVal(array $row, array $cols, array $candidates): string {
    $v = motorpoolVal($row, $cols, $candidates, '');
    if ($v === '') return '';
    $ts = strtotime($v);
    return $ts ? date('Y-m-d', $ts) : substr($v, 0, 10);
}
}

if (!function_exists('motorpoolBuildTireSourceHistory')) {
function motorpoolBuildTireSourceHistory(mysqli $conn, array $item, array $profile = []): array {
    $itemId = (int)($item['item_id'] ?? 0);
    $needles = array_filter(array_unique([
        (string)($item['item_code'] ?? ''),
        (string)($item['barcode'] ?? ''),
        (string)($item['item_name'] ?? ''),
        (string)($profile['serial_number'] ?? ''),
    ]), fn($v) => trim($v) !== '');

    $histories = [];
    $seen = [];

    $add = function(array $h) use (&$histories, &$seen) {
        $h = array_merge([
            'history_id' => 0,
            'action_date' => '',
            'action_type' => 'NOTE',
            'truck_name' => '',
            'plate_number' => '',
            'position' => '',
            'odometer' => '',
            'damage_description' => '',
            'repair_cost' => '',
            'shop_name' => '',
            'remarks' => '',
            'attachment_path' => '',
            'source_module' => '',
            'source_reference' => '',
            'invoice_no' => '',
            'vendor_name' => '',
            'purchase_cost' => '',
        ], $h);
        $key = strtoupper(($h['action_type'] ?? '') . '|' . ($h['action_date'] ?? '') . '|' . ($h['source_module'] ?? '') . '|' . ($h['source_reference'] ?? '') . '|' . ($h['plate_number'] ?? '') . '|' . ($h['damage_description'] ?? ''));
        if (isset($seen[$key])) return;
        $seen[$key] = true;
        $histories[] = $h;
    };

    // Purchase fallback from the serial item's saved cost. This keeps the tire profile usable
    // even when the Enter Bills schema cannot be detected.
    $purchaseDate = '';
    if (!empty($profile['purchase_date'])) $purchaseDate = date('Y-m-d', strtotime($profile['purchase_date']));
    elseif (!empty($item['created_at'])) $purchaseDate = date('Y-m-d', strtotime($item['created_at']));
    if ($purchaseDate !== '') {
        $add([
            'action_type' => 'PURCHASED',
            'action_date' => $purchaseDate,
            'remarks' => 'Purchase information from Enter Bills if matched; otherwise from Current Inventory cost.',
            'source_module' => 'Enter Bills / Current Inventory',
            'source_reference' => (string)($profile['invoice_no'] ?? ''),
            'invoice_no' => (string)($profile['invoice_no'] ?? ''),
            'vendor_name' => (string)($profile['supplier_name'] ?? ($item['supplier'] ?? '')),
            'purchase_cost' => (string)($profile['purchase_cost'] ?? ($item['unit_cost'] ?? '')),
        ]);
    }

    $purchaseTables = [];
    $res = @$conn->query("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND (LOWER(TABLE_NAME) LIKE '%bill%' OR LOWER(TABLE_NAME) LIKE '%invoice%' OR LOWER(TABLE_NAME) LIKE '%purchase%' OR LOWER(TABLE_NAME) LIKE '%receive%') ORDER BY TABLE_NAME LIMIT 80");
    if ($res) while ($r = $res->fetch_assoc()) $purchaseTables[] = $r['TABLE_NAME'];
    foreach ($purchaseTables as $tbl) {
        $rows = motorpoolSearchRowsByText($conn, $tbl, $needles, 20);
        if (!$rows) continue;
        $cols = motorpoolGetTableColumnsFast($conn, $tbl);
        foreach ($rows as $r) {
            $date = motorpoolFirstDateVal($r, $cols, ['bill_date','invoice_date','purchase_date','received_date','date','created_at','transaction_date']);
            $invoice = motorpoolVal($r, $cols, ['invoice_no','invoice_number','bill_no','bill_number','reference_no','reference','po_no','enter_bill_no','transaction_no','document_no']);
            $vendor = motorpoolVal($r, $cols, ['vendor_name','supplier_name','vendor','supplier','payee','counterparty_name','counterparty']);
            $cost = motorpoolVal($r, $cols, ['unit_cost','cost','amount','total_cost','line_total','total_amount','debit','price']);
            $add([
                'action_type' => 'PURCHASED',
                'action_date' => $date ?: $purchaseDate,
                'remarks' => 'Matched from Enter Bills / vendor bill records.',
                'source_module' => 'Enter Bills',
                'source_reference' => $invoice,
                'invoice_no' => $invoice,
                'vendor_name' => $vendor,
                'purchase_cost' => $cost,
            ]);
        }
    }

    $risTables = [];
    $res = @$conn->query("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND (LOWER(TABLE_NAME) LIKE '%ris%' OR LOWER(TABLE_NAME) LIKE '%request%' OR LOWER(TABLE_NAME) LIKE '%release%' OR LOWER(TABLE_NAME) LIKE '%issue%' OR LOWER(TABLE_NAME) LIKE '%return%' OR LOWER(TABLE_NAME) LIKE '%damage%') ORDER BY TABLE_NAME LIMIT 120");
    if ($res) while ($r = $res->fetch_assoc()) $risTables[] = $r['TABLE_NAME'];
    foreach ($risTables as $tbl) {
        $rows = motorpoolSearchRowsByText($conn, $tbl, $needles, 40);
        if (!$rows) continue;
        $cols = motorpoolGetTableColumnsFast($conn, $tbl);
        foreach ($rows as $r) {
            $status = strtolower(motorpoolVal($r, $cols, ['status','request_status','item_status','action_type','transaction_type','movement_type','ris_status']));
            $tableLow = strtolower($tbl);
            $action = 'RELEASED';
            if (strpos($tableLow, 'damage') !== false || strpos($status, 'damage') !== false) $action = 'DAMAGED';
            elseif (strpos($tableLow, 'return') !== false || strpos($status, 'return') !== false) $action = 'RETURNED';
            elseif (strpos($status, 'replace') !== false) $action = 'REPLACED';
            elseif (strpos($status, 'install') !== false) $action = 'INSTALLED';
            elseif (strpos($status, 'release') !== false || strpos($status, 'approved') !== false || strpos($status, 'received') !== false || strpos($status, 'issued') !== false) $action = 'RELEASED';

            $dateCandidates = $action === 'DAMAGED' || $action === 'REPLACED'
                ? ['damage_date','damaged_date','replacement_date','replaced_date','return_date','date_returned','updated_at','created_at','date','request_date']
                : ['release_date','released_date','date_released','issued_date','date_issued','approved_date','ris_date','request_date','date','created_at','updated_at'];

            $date = motorpoolFirstDateVal($r, $cols, $dateCandidates);
            $plate = motorpoolVal($r, $cols, ['plate_no','plate_number','truck_plate','vehicle_plate','plate','vehicle_no']);
            $truck = motorpoolVal($r, $cols, ['truck_name','vehicle_name','truck','vehicle','unit','equipment','vehicle_details']);
            $position = motorpoolVal($r, $cols, ['tire_position','position','wheel_position','installed_position']);
            $odo = motorpoolVal($r, $cols, ['odometer','current_mileage','mileage','km_reading','kilometer']);
            $damage = motorpoolVal($r, $cols, ['damage_description','damage','damage_reason','replacement_reason','reason','status_note','remarks','note','notes','description']);
            $attach = motorpoolVal($r, $cols, ['damage_picture','damage_image','attachment_path','attachment','file_path','image_path','photo','picture','attachments']);
            $ref = motorpoolVal($r, $cols, ['ris_no','ris_number','request_no','request_number','reference_no','reference','transaction_no','id']);
            $add([
                'action_type' => $action,
                'action_date' => $date,
                'truck_name' => $truck,
                'plate_number' => $plate,
                'position' => $position,
                'odometer' => $odo,
                'damage_description' => $damage,
                'attachment_path' => $attach,
                'remarks' => $damage,
                'source_module' => 'RIS Monitoring',
                'source_reference' => $ref,
            ]);
        }
    }

    usort($histories, function($a, $b) {
        $ad = (string)($a['action_date'] ?? '');
        $bd = (string)($b['action_date'] ?? '');
        if ($ad === $bd) return strcmp((string)($b['action_type'] ?? ''), (string)($a['action_type'] ?? ''));
        return strcmp($bd, $ad);
    });

    // Compute latest status from source history.
    $current = [
        'status' => (string)($profile['current_status'] ?? 'Warehouse'),
        'truck' => (string)($profile['current_truck'] ?? ''),
        'plate' => (string)($profile['current_plate'] ?? ''),
        'position' => (string)($profile['current_position'] ?? ''),
    ];
    $chron = array_reverse($histories);
    foreach ($chron as $h) {
        $a = strtoupper((string)($h['action_type'] ?? ''));
        if (in_array($a, ['RELEASED','INSTALLED'], true)) {
            $current['status'] = $a === 'INSTALLED' ? 'Installed' : 'Released';
            $current['truck'] = (string)($h['truck_name'] ?? $current['truck']);
            $current['plate'] = (string)($h['plate_number'] ?? $current['plate']);
            $current['position'] = (string)($h['position'] ?? $current['position']);
        } elseif (in_array($a, ['DAMAGED','REPLACED'], true)) {
            $current['status'] = 'Damaged/Replaced';
            $current['truck'] = (string)($h['truck_name'] ?? $current['truck']);
            $current['plate'] = (string)($h['plate_number'] ?? $current['plate']);
            $current['position'] = (string)($h['position'] ?? $current['position']);
        } elseif (in_array($a, ['RETURNED','REMOVED'], true)) {
            $current['status'] = 'Returned';
        }
    }

    return ['histories' => $histories, 'current' => $current];
}
}

// ========== HANDLE AJAX REQUESTS ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json');
    ini_set('display_errors', 0);
    error_reporting(E_ALL);

    if (($_POST['action'] ?? '') === 'get_tire_profile') {
        try {
            $item_id = (int)($_POST['item_id'] ?? 0);
            if ($item_id <= 0) throw new Exception('Invalid tire item.');

            $item_stmt = $conn->prepare("SELECT item_id, item_code, barcode, item_name, description, category, principal, unit_cost, unit_price, supplier, created_at FROM motorpool_inventory_items WHERE item_id = ? LIMIT 1");
            if (!$item_stmt) throw new Exception('Unable to read item.');
            $item_stmt->bind_param('i', $item_id);
            $item_stmt->execute();
            $item = $item_stmt->get_result()->fetch_assoc();
            $item_stmt->close();
            if (!$item) throw new Exception('Item not found.');
            if (!motorpoolIsTireCategoryValue($item['category'] ?? '')) throw new Exception('Tire Profile is only available for Tires category.');

            $profile_id = motorpoolEnsureTireProfile($conn, $item_id, (int)$user_id);
            if ($profile_id <= 0) throw new Exception('Unable to create tire profile.');

            $profile_stmt = $conn->prepare("SELECT * FROM motorpool_tire_profiles WHERE tire_profile_id = ? LIMIT 1");
            $profile_stmt->bind_param('i', $profile_id);
            $profile_stmt->execute();
            $profile = $profile_stmt->get_result()->fetch_assoc();
            $profile_stmt->close();

            // History is read-only and is built from source transactions:
            // Purchase details from Enter Bills/vendor bill records and release/damage/return
            // movement from RIS Monitoring. Manual Tire History is no longer the source of truth.
            $sourceHistory = motorpoolBuildTireSourceHistory($conn, $item, $profile ?: []);
            $histories = $sourceHistory['histories'] ?? [];
            $sourceCurrent = $sourceHistory['current'] ?? [];

            if (!empty($sourceCurrent)) {
                $profile['current_status'] = $sourceCurrent['status'] ?? ($profile['current_status'] ?? 'Warehouse');
                $profile['current_truck'] = $sourceCurrent['truck'] ?? ($profile['current_truck'] ?? '');
                $profile['current_plate'] = $sourceCurrent['plate'] ?? ($profile['current_plate'] ?? '');
                $profile['current_position'] = $sourceCurrent['position'] ?? ($profile['current_position'] ?? '');
            }

            echo json_encode(['success' => true, 'item' => $item, 'profile' => $profile, 'histories' => $histories, 'history_source' => 'Enter Bills and RIS Monitoring']);
            exit;
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            exit;
        }
    }

    if (($_POST['action'] ?? '') === 'save_tire_profile') {
        try {
            $item_id = (int)($_POST['item_id'] ?? 0);
            $profile_id = motorpoolEnsureTireProfile($conn, $item_id, (int)$user_id);
            if ($profile_id <= 0) throw new Exception('Invalid tire profile.');

            $serial = trim((string)($_POST['serial_number'] ?? ''));
            $brand = trim((string)($_POST['brand'] ?? ''));
            $size = trim((string)($_POST['tire_size'] ?? ''));
            $pattern = trim((string)($_POST['pattern'] ?? ''));
            $purchase_date = trim((string)($_POST['purchase_date'] ?? ''));
            $purchase_date = $purchase_date !== '' ? $purchase_date : null;
            $supplier_name = trim((string)($_POST['supplier_name'] ?? ''));
            $invoice_no = trim((string)($_POST['invoice_no'] ?? ''));
            $purchase_cost_raw = str_replace(['₱', ',', ' '], '', (string)($_POST['purchase_cost'] ?? '0'));
            $purchase_cost = is_numeric($purchase_cost_raw) ? (float)$purchase_cost_raw : 0.0;
            $status = trim((string)($_POST['current_status'] ?? 'Warehouse')) ?: 'Warehouse';
            $truck = trim((string)($_POST['current_truck'] ?? ''));
            $plate = trim((string)($_POST['current_plate'] ?? ''));
            $position = trim((string)($_POST['current_position'] ?? ''));
            $tread = trim((string)($_POST['remaining_tread'] ?? ''));
            $remarks = trim((string)($_POST['remarks'] ?? ''));

            $stmt = $conn->prepare("UPDATE motorpool_tire_profiles SET serial_number=?, brand=?, tire_size=?, pattern=?, purchase_date=?, supplier_name=?, invoice_no=?, purchase_cost=?, current_status=?, current_truck=?, current_plate=?, current_position=?, remaining_tread=?, remarks=?, updated_by=?, updated_at=NOW() WHERE tire_profile_id=?");
            if (!$stmt) throw new Exception('Unable to save tire profile.');
            $stmt->bind_param('sssssssdssssssii', $serial, $brand, $size, $pattern, $purchase_date, $supplier_name, $invoice_no, $purchase_cost, $status, $truck, $plate, $position, $tread, $remarks, $user_id, $profile_id);
            $stmt->execute();
            $stmt->close();

            echo json_encode(['success' => true, 'message' => 'Tire profile saved.']);
            exit;
        } catch (Throwable $e) { echo json_encode(['success'=>false,'message'=>$e->getMessage()]); exit; }
    }

    if (($_POST['action'] ?? '') === 'save_tire_history') {
        try {
            $item_id = (int)($_POST['item_id'] ?? 0);
            $profile_id = motorpoolEnsureTireProfile($conn, $item_id, (int)$user_id);
            if ($profile_id <= 0) throw new Exception('Invalid tire profile.');

            $action_type = trim((string)($_POST['action_type'] ?? 'NOTE')) ?: 'NOTE';
            $action_date = trim((string)($_POST['action_date'] ?? date('Y-m-d'))) ?: date('Y-m-d');
            $truck = trim((string)($_POST['truck_name'] ?? ''));
            $plate = trim((string)($_POST['plate_number'] ?? ''));
            $position = trim((string)($_POST['position'] ?? ''));
            $odometer = trim((string)($_POST['odometer'] ?? ''));
            $damage = trim((string)($_POST['damage_description'] ?? ''));
            $repair_cost = (float)($_POST['repair_cost'] ?? 0);
            $shop = trim((string)($_POST['shop_name'] ?? ''));
            $remarks = trim((string)($_POST['remarks'] ?? ''));
            $attachment_path = null;

            if (!empty($_FILES['attachment']['name']) && is_uploaded_file($_FILES['attachment']['tmp_name'])) {
                $dir = '../uploads/tire_profiles/';
                if (!is_dir($dir)) @mkdir($dir, 0775, true);
                $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($_FILES['attachment']['name']));
                $newName = 'tire_' . $item_id . '_' . time() . '_' . $safeName;
                if (move_uploaded_file($_FILES['attachment']['tmp_name'], $dir . $newName)) {
                    $attachment_path = $dir . $newName;
                }
            }

            $stmt = $conn->prepare("INSERT INTO motorpool_tire_history (tire_profile_id, item_id, action_type, action_date, truck_name, plate_number, position, odometer, damage_description, repair_cost, shop_name, remarks, attachment_path, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            if (!$stmt) throw new Exception('Unable to save tire history.');
            $stmt->bind_param('iisssssssdsssi', $profile_id, $item_id, $action_type, $action_date, $truck, $plate, $position, $odometer, $damage, $repair_cost, $shop, $remarks, $attachment_path, $user_id);
            $stmt->execute();
            $stmt->close();

            if (in_array(strtoupper($action_type), ['INSTALLED','RELEASED','DAMAGED','REPLACED','REMOVED','RETURNED','REPAIRED','SCRAPPED','DISPOSED'], true)) {
                $newStatus = match(strtoupper($action_type)) {
                    'INSTALLED','RELEASED' => 'Installed',
                    'DAMAGED','REPLACED' => 'Damaged/Replaced',
                    'REMOVED','RETURNED' => 'Returned',
                    'REPAIRED' => 'Repaired',
                    'SCRAPPED','DISPOSED' => 'Scrapped',
                    default => 'Warehouse'
                };
                $upd = $conn->prepare("UPDATE motorpool_tire_profiles SET current_status=?, current_truck=?, current_plate=?, current_position=?, updated_by=?, updated_at=NOW() WHERE tire_profile_id=?");
                if ($upd) { $upd->bind_param('ssssii', $newStatus, $truck, $plate, $position, $user_id, $profile_id); $upd->execute(); $upd->close(); }
            }

            echo json_encode(['success' => true, 'message' => 'Tire history saved.']);
            exit;
        } catch (Throwable $e) { echo json_encode(['success'=>false,'message'=>$e->getMessage()]); exit; }
    }


    // FINAL SAFE MOTORPOOL BATCH PRICE LEVEL / STOCK LOADER
    // Loads from Motorpool tables only. It always returns JSON and it does NOT require
    // an existing Standard pricing row, so the modal will not stay on "Loading items".
    if (($_POST['action'] ?? '') === 'get_batch_price_level_items') {
        try {
            $price_level = trim((string)($_POST['price_level'] ?? 'Standard'));
            $effective_date = trim((string)($_POST['effective_date'] ?? date('Y-m-d')));
            if ($price_level === '') $price_level = 'Standard';
            if ($effective_date === '') $effective_date = date('Y-m-d');

            // Make sure there is a usable fallback UOM.
            @$conn->query("INSERT IGNORE INTO motorpool_unit_types (unit_type_name, uom_initial, quantity_smallest_pack, is_default_uom, multiplier, branch_id, status) VALUES ('Piece', 'PC', 1, 1, 1.00, NULL, 'active')");
            $piece_unit_type_id = 0;
            if ($pieceRes = $conn->query("SELECT unit_type_id FROM motorpool_unit_types WHERE unit_type_name='Piece' ORDER BY unit_type_id ASC LIMIT 1")) {
                if ($pieceRow = $pieceRes->fetch_assoc()) $piece_unit_type_id = (int)$pieceRow['unit_type_id'];
            }
            if ($piece_unit_type_id <= 0) {
                throw new Exception('Default Piece UOM is not available.');
            }

            // Repair old/simple Motorpool items that have stock but no default UOM/pricing yet.
            @$conn->query("UPDATE motorpool_inventory_items SET default_unit_type_id = $piece_unit_type_id WHERE default_unit_type_id IS NULL OR default_unit_type_id = 0");
            @$conn->query("INSERT IGNORE INTO motorpool_item_unit_inventory (item_id, unit_type_id, current_inventory, beginning_inventory, as_of_date, unit_cost, total_cost, status)
                SELECT i.item_id, COALESCE(i.default_unit_type_id, $piece_unit_type_id), COALESCE(NULLIF(i.stock,0), i.current_stock, 0), COALESCE(NULLIF(i.stock,0), i.current_stock, 0), COALESCE(DATE(i.created_at), CURDATE()), COALESCE(NULLIF(i.unit_cost,0), i.unit_price, 0), COALESCE(NULLIF(i.total_cost,0), COALESCE(NULLIF(i.stock,0), i.current_stock, 0) * COALESCE(NULLIF(i.unit_cost,0), i.unit_price, 0)), 'active'
                FROM motorpool_inventory_items i
                WHERE COALESCE(i.status,'active') <> 'deleted'");
            @$conn->query("INSERT IGNORE INTO motorpool_item_unit_types (item_id, unit_type_id, unit_type_name, uom_initial, barcode, smallest_pack_quantity, is_default_uom, branch_id, status)
                SELECT i.item_id, COALESCE(i.default_unit_type_id, $piece_unit_type_id), COALESCE(NULLIF(i.unit_type,''), 'Piece'), '', COALESCE(i.barcode,''), 1, 1, i.branch_id, 'active'
                FROM motorpool_inventory_items i
                WHERE COALESCE(i.status,'active') <> 'deleted'");
            // Do not create fake Standard prices here. Items without pricing display as No existing price levels.

            $query = "SELECT
                    i.item_id,
                    i.item_name,
                    COALESCE(i.description, '') AS description,
                    b.unit_type_id,
                    COALESCE(ut.unit_type_name, iut.unit_type_name, NULLIF(i.unit_type,''), 'Piece') AS unit_type_name,
                    CASE WHEN selected_schedule.schedule_id IS NOT NULL THEN selected_schedule.unit_price WHEN selected_current.pricing_id IS NOT NULL THEN selected_current.unit_price WHEN standard_schedule.schedule_id IS NOT NULL THEN standard_schedule.unit_price WHEN standard_current.pricing_id IS NOT NULL THEN standard_current.unit_price ELSE NULL END AS current_price,
                    COALESCE(selected_schedule.unit_quantity, selected_current.unit_quantity, standard_schedule.unit_quantity, standard_current.unit_quantity, 1) AS unit_quantity,
                    CASE
                        WHEN selected_schedule.schedule_id IS NOT NULL THEN selected_schedule.unit_price
                        WHEN selected_current.pricing_id IS NOT NULL THEN selected_current.unit_price
                        ELSE NULL
                    END AS editable_price,
                    CASE
                        WHEN selected_schedule.schedule_id IS NOT NULL OR selected_current.pricing_id IS NOT NULL OR standard_schedule.schedule_id IS NOT NULL OR standard_current.pricing_id IS NOT NULL THEN 1
                        ELSE 0
                    END AS has_existing_price,
                    CASE
                        WHEN selected_schedule.schedule_id IS NOT NULL OR selected_current.pricing_id IS NOT NULL OR standard_schedule.schedule_id IS NOT NULL OR standard_current.pricing_id IS NOT NULL THEN ''
                        ELSE 'No existing price levels'
                    END AS current_price_label
                FROM motorpool_inventory_items i
                JOIN (
                    SELECT item_id, unit_type_id FROM motorpool_item_unit_inventory
                    UNION
                    SELECT item_id, unit_type_id FROM motorpool_item_unit_types WHERE COALESCE(status,'active')='active'
                    UNION
                    SELECT item_id, unit_type_id FROM motorpool_item_unit_pricing
                    UNION
                    SELECT item_id, COALESCE(default_unit_type_id, $piece_unit_type_id) AS unit_type_id FROM motorpool_inventory_items
                ) b ON b.item_id = i.item_id AND b.unit_type_id IS NOT NULL AND b.unit_type_id > 0
                LEFT JOIN motorpool_unit_types ut ON ut.unit_type_id = b.unit_type_id
                LEFT JOIN motorpool_item_unit_types iut ON iut.item_id = i.item_id AND iut.unit_type_id = b.unit_type_id
                LEFT JOIN motorpool_item_unit_inventory inv ON inv.item_id = i.item_id AND inv.unit_type_id = b.unit_type_id
                LEFT JOIN motorpool_item_unit_pricing standard_current
                    ON standard_current.item_id = i.item_id
                    AND standard_current.unit_type_id = b.unit_type_id
                    AND standard_current.price_level = 'Standard'
                LEFT JOIN motorpool_item_unit_pricing selected_current
                    ON selected_current.item_id = i.item_id
                    AND selected_current.unit_type_id = b.unit_type_id
                    AND selected_current.price_level = ?
                LEFT JOIN motorpool_item_unit_pricing_schedule selected_schedule
                    ON selected_schedule.item_id = i.item_id
                    AND selected_schedule.unit_type_id = b.unit_type_id
                    AND selected_schedule.price_level = ?
                    AND selected_schedule.effective_date = ?
                LEFT JOIN motorpool_item_unit_pricing_schedule standard_schedule
                    ON standard_schedule.item_id = i.item_id
                    AND standard_schedule.unit_type_id = b.unit_type_id
                    AND standard_schedule.price_level = 'Standard'
                    AND standard_schedule.effective_date = ?
                WHERE COALESCE(i.status,'active') = 'active'";
            if ($items_branch_column_exists && !$view_all_branches && $branch_id > 0) {
                $query .= " AND (i.branch_id = " . intval($branch_id) . " OR i.branch_id IS NULL OR i.branch_id = 0)";
            }
            $query .= " GROUP BY i.item_id, b.unit_type_id ORDER BY i.item_name ASC, unit_type_name ASC";

            $stmt = $conn->prepare($query);
            if (!$stmt) throw new Exception('Database prepare error while loading batch price list: ' . $conn->error);
            $stmt->bind_param('ssss', $price_level, $price_level, $effective_date, $effective_date);
            $stmt->execute();
            $result = $stmt->get_result();
            $items = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
            $stmt->close();

            echo json_encode(['success' => true, 'items' => $items, 'message' => 'Loaded ' . count($items) . ' item(s).']);
            exit;
        } catch (Throwable $e) {
            error_log('motorpool batch price loader error: ' . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Batch price loader error: ' . $e->getMessage(), 'items' => []]);
            exit;
        }
    }


    // FINAL SAFE MOTORPOOL VIEW/EDIT HANDLERS
    // These handlers intentionally use only Motorpool tables and return clean JSON.
    if (false && ($_POST['action'] ?? '') === 'get_item') {
        try {
            $item_id = (int)($_POST['item_id'] ?? 0);
            if ($item_id <= 0) throw new Exception('Invalid item ID');

            $item_sql = "SELECT i.*, CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,'')) AS created_by_name,
                                CONCAT(COALESCE(uu.first_name,''), ' ', COALESCE(uu.last_name,'')) AS updated_by_name
                         FROM motorpool_inventory_items i
                         LEFT JOIN users u ON u.user_id = i.created_by
                         LEFT JOIN users uu ON uu.user_id = i.updated_by
                         WHERE i.item_id = ? AND COALESCE(i.status, 'active') <> 'deleted'
                         LIMIT 1";
            $stmt = $conn->prepare($item_sql);
            if (!$stmt) throw new Exception('Unable to prepare item query: ' . $conn->error);
            $stmt->bind_param('i', $item_id);
            $stmt->execute();
            $item = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$item) throw new Exception('Item not found');

            $stock = (float)($item['stock'] ?? 0);
            if ($stock == 0 && isset($item['current_stock'])) $stock = (float)$item['current_stock'];
            $unit_cost = (float)($item['unit_cost'] ?? 0);
            if ($unit_cost == 0 && isset($item['unit_price'])) $unit_cost = (float)$item['unit_price'];
            $total_cost = (float)($item['total_cost'] ?? 0);
            if ($total_cost == 0 && $stock > 0 && $unit_cost > 0) $total_cost = $stock * $unit_cost;
            $item['stock'] = $stock;
            $item['current_stock'] = $stock;
            $item['unit_cost'] = $unit_cost;
            $item['unit_price'] = (float)($item['unit_price'] ?? $unit_cost);
            $item['total_cost'] = $total_cost;
            $item['unit_type'] = $item['unit_type'] ?: 'Piece';
            $item['status'] = $item['status'] ?: 'active';

            // System Information fallback: old Motorpool rows sometimes have blank/zero dates
            // or no created_by. Pull the exact recorded date/user from inventory/transaction rows.
            $createdFallback = '';
            $updatedFallback = '';
            $actorFallback = '';
            if ($sysStmt = $conn->prepare("SELECT
                    MIN(dt) AS first_recorded_at,
                    MAX(dt) AS last_recorded_at
                FROM (
                    SELECT created_at AS dt FROM motorpool_inventory_items WHERE item_id = ?
                    UNION ALL
                    SELECT created_at AS dt FROM motorpool_item_unit_inventory WHERE item_id = ?
                    UNION ALL
                    SELECT created_at AS dt FROM motorpool_item_unit_pricing WHERE item_id = ?
                    UNION ALL
                    SELECT updated_at AS dt FROM motorpool_item_unit_pricing WHERE item_id = ?
                    UNION ALL
                    SELECT created_at AS dt FROM motorpool_inventory_transactions WHERE item_id = ?
                ) x
                WHERE dt IS NOT NULL AND dt <> '0000-00-00 00:00:00'")) {
                $sysStmt->bind_param('iiiii', $item_id, $item_id, $item_id, $item_id, $item_id);
                $sysStmt->execute();
                if ($sysRow = $sysStmt->get_result()->fetch_assoc()) {
                    $createdFallback = (string)($sysRow['first_recorded_at'] ?? '');
                    $updatedFallback = (string)($sysRow['last_recorded_at'] ?? '');
                }
                $sysStmt->close();
            }
            if ($actorStmt = $conn->prepare("SELECT TRIM(CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,''))) AS actor_name
                FROM motorpool_inventory_transactions tx
                LEFT JOIN users u ON u.user_id = COALESCE(tx.created_by, tx.encoded_by)
                WHERE tx.item_id = ? AND COALESCE(tx.created_by, tx.encoded_by, 0) > 0
                ORDER BY tx.created_at ASC, tx.transaction_id ASC LIMIT 1")) {
                $actorStmt->bind_param('i', $item_id);
                $actorStmt->execute();
                if ($actorRow = $actorStmt->get_result()->fetch_assoc()) {
                    $actorFallback = trim((string)($actorRow['actor_name'] ?? ''));
                }
                $actorStmt->close();
            }
            if (empty($item['created_at']) || $item['created_at'] === '0000-00-00 00:00:00') $item['created_at'] = $createdFallback;
            if (empty($item['updated_at']) || $item['updated_at'] === '0000-00-00 00:00:00') $item['updated_at'] = $updatedFallback ?: $item['created_at'];
            $item['system_created_at'] = $item['created_at'] ?: $createdFallback;
            $item['system_updated_at'] = $item['updated_at'] ?: $updatedFallback ?: $item['system_created_at'];
            $item['created_by_name'] = trim((string)($item['created_by_name'] ?? ''));
            if ($item['created_by_name'] === '') $item['created_by_name'] = $actorFallback ?: 'System';

            $unitTypes = [];
            if (amgcTableExists($conn, 'motorpool_item_unit_inventory')) {
                $unit_sql = "SELECT inv.unit_type_id,
                                    COALESCE(ut.unit_type_name, iut.unit_type_name, ?) AS unit_type_name,
                                    COALESCE(ut.uom_initial, iut.uom_initial, '') AS uom_initial,
                                    COALESCE(NULLIF(iut.barcode,''), NULLIF(ut.barcode,''), ?) AS barcode,
                                    COALESCE(iut.smallest_pack_quantity, ut.quantity_smallest_pack, 1) AS quantity_smallest_pack,
                                    CASE WHEN inv.unit_type_id = ? THEN 1 ELSE COALESCE(iut.is_default_uom, ut.is_default_uom, 0) END AS is_default_uom,
                                    COALESCE(iut.status, ut.status, 'active') AS unit_status,
                                    1 AS unit_quantity,
                                    COALESCE(inv.current_inventory, 0) AS current_inventory,
                                    COALESCE(inv.beginning_inventory, inv.current_inventory, 0) AS beginning_inventory,
                                    COALESCE(inv.as_of_date, DATE(?)) AS as_of_date,
                                    COALESCE(inv.unit_cost, 0) AS unit_cost,
                                    CASE WHEN COALESCE(inv.current_inventory,0) > 0 AND COALESCE(inv.total_cost,0) > 0 THEN inv.total_cost / inv.current_inventory ELSE COALESCE(inv.unit_cost, 0) END AS average_cost,
                                    COALESCE(inv.total_cost, 0) AS total_cost,
                                    COALESCE(?, 0) AS reorder_level
                             FROM motorpool_item_unit_inventory inv
                             LEFT JOIN motorpool_unit_types ut ON ut.unit_type_id = inv.unit_type_id
                             LEFT JOIN motorpool_item_unit_types iut ON iut.item_id = inv.item_id AND iut.unit_type_id = inv.unit_type_id
                             WHERE inv.item_id = ?
                             ORDER BY is_default_uom DESC, unit_type_name ASC";
                if ($u = $conn->prepare($unit_sql)) {
                    $fallbackUnit = (string)($item['unit_type'] ?: 'Piece');
                    $fallbackBarcode = (string)($item['barcode'] ?? '');
                    $defaultUom = (int)($item['default_unit_type_id'] ?? 0);
                    $createdAt = (string)($item['created_at'] ?? date('Y-m-d'));
                    $reorder = (float)($item['reorder_level'] ?? 0);
                    $u->bind_param('ssisdi', $fallbackUnit, $fallbackBarcode, $defaultUom, $createdAt, $reorder, $item_id);
                    $u->execute();
                    $res = $u->get_result();
                    while ($r = $res->fetch_assoc()) $unitTypes[] = $r;
                    $u->close();
                }
            }
            if (empty($unitTypes)) {
                $unitTypes[] = [
                    'unit_type_id' => (int)($item['default_unit_type_id'] ?? 0),
                    'unit_type_name' => $item['unit_type'] ?: 'Piece',
                    'uom_initial' => '',
                    'barcode' => $item['barcode'] ?? '',
                    'quantity_smallest_pack' => 1,
                    'is_default_uom' => 1,
                    'unit_status' => 'active',
                    'unit_quantity' => 1,
                    'current_inventory' => $stock,
                    'beginning_inventory' => $stock,
                    'as_of_date' => !empty($item['created_at']) ? date('Y-m-d', strtotime($item['created_at'])) : date('Y-m-d'),
                    'unit_cost' => $unit_cost,
                    'average_cost' => $unit_cost,
                    'total_cost' => $total_cost,
                    'reorder_level' => (float)($item['reorder_level'] ?? 0)
                ];
            }

            $images = [];
            if (amgcTableExists($conn, 'motorpool_item_images')) {
                if ($imgStmt = $conn->prepare("SELECT image_id, image_path, image_order, is_primary FROM motorpool_item_images WHERE item_id = ? ORDER BY is_primary DESC, image_order ASC, image_id ASC")) {
                    $imgStmt->bind_param('i', $item_id);
                    $imgStmt->execute();
                    $imgRes = $imgStmt->get_result();
                    while ($img = $imgRes->fetch_assoc()) $images[] = $img;
                    $imgStmt->close();
                }
            }
            $imagePath = trim((string)($item['product_image_url'] ?? '')) ?: trim((string)($item['item_image'] ?? ''));
            if (empty($images) && $imagePath !== '') $images[] = ['image_id'=>0, 'image_path'=>$imagePath, 'image_order'=>0, 'is_primary'=>1];

            $prices = [];
            foreach ($unitTypes as $ut) {
                $prices[$ut['unit_type_name']] = ['unit_type_id'=>(int)$ut['unit_type_id'], 'unit_price'=>(float)($item['unit_price'] ?: $ut['unit_cost']), 'unit_quantity'=>1];
            }
            $pricing_rows = [['effective_date'=>date('Y-m-d'), 'price_level'=>'Standard', 'prices'=>$prices]];

            $transactions = [];
            if (amgcTableExists($conn, 'motorpool_inventory_transactions')) {
                $tx_sql = "SELECT transaction_id, item_id, transaction_type,
                                  COALESCE(NULLIF(quantity_changed,0), quantity, 0) AS quantity,
                                  COALESCE(unit_cost,0) AS unit_cost,
                                  COALESCE(total_cost,0) AS total_cost,
                                  COALESCE(reference_type,'') AS reference_type,
                                  reference_id,
                                  COALESCE(receive_memo, remarks, '') AS notes,
                                  created_at,
                                  COALESCE(attachment,'') AS attachment,
                                  COALESCE(created_by, encoded_by) AS actor_id
                           FROM motorpool_inventory_transactions
                           WHERE item_id = ?
                           ORDER BY created_at DESC, transaction_id DESC
                           LIMIT 100";
                if ($t = $conn->prepare($tx_sql)) {
                    $t->bind_param('i', $item_id);
                    $t->execute();
                    $txRes = $t->get_result();
                    while ($tx = $txRes->fetch_assoc()) {
                        $tx['uom'] = $item['unit_type'] ?: 'Piece';
                        $tx['actor_name'] = '';
                        $tx['source_label'] = $tx['reference_type'] ?: $tx['transaction_type'];
                        $tx['party_name'] = $item['supplier'] ?: 'Motorpool';
                        $tx['reference_label'] = !empty($tx['reference_id']) ? ('Ref #' . $tx['reference_id']) : '—';
                        $transactions[] = $tx;
                    }
                    $t->close();
                }
            }

            $inventory_summary = [
                'beginning_inventory' => array_sum(array_map(fn($x) => (float)($x['beginning_inventory'] ?? 0), $unitTypes)),
                'total_inventory' => $stock,
                'total_cost' => $total_cost,
                'average_cost_month' => $stock > 0 ? ($total_cost / $stock) : 0,
                'ave_daily_offtake' => 0,
                'offtake_total_quantity' => 0,
                'offtake_active_days' => 0
            ];

            echo json_encode([
                'success' => true,
                'item' => $item,
                'motorpool_unit_types' => $unitTypes,
                'unit_types' => $unitTypes,
                'images' => $images,
                'pricing_rows' => $pricing_rows,
                'pricing_history' => [],
                'inventory_summary' => $inventory_summary,
                'transactions' => $transactions
            ]);
            exit;
        } catch (Throwable $e) {
            error_log('motorpool final get_item error: ' . $e->getMessage());
            echo json_encode(['success'=>false, 'message'=>'Item details error: ' . $e->getMessage()]);
            exit;
        }
    }

    if (($_POST['action'] ?? '') === 'motorpool_quick_update_item') {
        try {
            $item_id = (int)($_POST['item_id'] ?? 0);
            if ($item_id <= 0) throw new Exception('Invalid item ID');
            $item_code = trim((string)($_POST['item_code'] ?? ''));
            $item_name = trim((string)($_POST['item_name'] ?? ''));
            if ($item_name === '') throw new Exception('Item name is required');
            $description = trim((string)($_POST['description'] ?? ''));
            $category = trim((string)($_POST['category'] ?? 'General')) ?: 'General';
            $principal = trim((string)($_POST['principal'] ?? ''));
            $volume = trim((string)($_POST['volume'] ?? ''));
            $oil_type = trim((string)($_POST['oil_type'] ?? ''));
            $barcode = trim((string)($_POST['barcode'] ?? ''));
            $unit_type = trim((string)($_POST['unit_type'] ?? 'Piece')) ?: 'Piece';
            $stock = (float)($_POST['stock'] ?? 0);
            $reorder_level = (float)($_POST['reorder_level'] ?? 0);
            $unit_cost = (float)($_POST['unit_cost'] ?? 0);
            $unit_price = (float)($_POST['unit_price'] ?? $unit_cost);
            if ($unit_price <= 0) $unit_price = $unit_cost;
            $total_cost = (float)($_POST['total_cost'] ?? 0);
            if ($total_cost <= 0) $total_cost = $stock * $unit_cost;
            $status = trim((string)($_POST['status'] ?? 'active')) ?: 'active';
            $points_eligible = (int)($_POST['points_eligible'] ?? 1);
            $income = ($_POST['income_account_id'] ?? '') !== '' ? (int)$_POST['income_account_id'] : null;
            $cogs = ($_POST['cogs_account_id'] ?? '') !== '' ? (int)$_POST['cogs_account_id'] : null;
            $asset = ($_POST['asset_account_id'] ?? '') !== '' ? (int)$_POST['asset_account_id'] : null;

            $stmt = $conn->prepare("UPDATE motorpool_inventory_items
                SET item_code = ?, item_name = ?, description = ?, category = ?, principal = ?, volume = ?, oil_type = ?, barcode = ?, unit_type = ?, stock = ?, current_stock = ?, reorder_level = ?, unit_cost = ?, unit_price = ?, total_cost = ?, status = ?, points_eligible = ?, income_account_id = ?, cogs_account_id = ?, asset_account_id = ?, updated_by = ?, updated_at = NOW()
                WHERE item_id = ?");
            if (!$stmt) throw new Exception('Unable to prepare update: ' . $conn->error);
            $stmt->bind_param('sssssssssddddddsiiiiii', $item_code, $item_name, $description, $category, $principal, $volume, $oil_type, $barcode, $unit_type, $stock, $stock, $reorder_level, $unit_cost, $unit_price, $total_cost, $status, $points_eligible, $income, $cogs, $asset, $user_id, $item_id);
            if (!$stmt->execute()) throw new Exception('Unable to update item: ' . $stmt->error);
            $stmt->close();
            motorpoolRecordItemActivity($conn, $item_id, 'updated', $user_id, 'Item updated');

            // Ensure default UOM row exists and mirrors the visible stock/cost.
            @$conn->query("INSERT IGNORE INTO motorpool_unit_types (unit_type_name, uom_initial, quantity_smallest_pack, is_default_uom, multiplier, branch_id, status) VALUES ('" . $conn->real_escape_string($unit_type) . "', '', 1, 1, 1.00, NULL, 'active')");
            $unit_type_id = 0;
            if ($r = $conn->query("SELECT unit_type_id FROM motorpool_unit_types WHERE unit_type_name = '" . $conn->real_escape_string($unit_type) . "' ORDER BY unit_type_id ASC LIMIT 1")) {
                if ($row = $r->fetch_assoc()) $unit_type_id = (int)$row['unit_type_id'];
            }
            if ($unit_type_id > 0) {
                @$conn->query("UPDATE motorpool_inventory_items SET default_unit_type_id = $unit_type_id WHERE item_id = $item_id");
                $asof = date('Y-m-d');
                if ($inv = $conn->prepare("INSERT INTO motorpool_item_unit_inventory (item_id, unit_type_id, current_inventory, beginning_inventory, as_of_date, unit_cost, total_cost, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'active') ON DUPLICATE KEY UPDATE current_inventory=VALUES(current_inventory), as_of_date=VALUES(as_of_date), unit_cost=VALUES(unit_cost), total_cost=VALUES(total_cost), status='active', updated_at=NOW()")) {
                    $inv->bind_param('iiddsdd', $item_id, $unit_type_id, $stock, $stock, $asof, $unit_cost, $total_cost);
                    $inv->execute();
                    $inv->close();
                }
                if ($iut = $conn->prepare("INSERT INTO motorpool_item_unit_types (item_id, unit_type_id, unit_type_name, uom_initial, barcode, smallest_pack_quantity, is_default_uom, branch_id, status) VALUES (?, ?, ?, '', ?, 1, 1, NULL, 'active') ON DUPLICATE KEY UPDATE unit_type_name=VALUES(unit_type_name), barcode=VALUES(barcode), is_default_uom=1, status='active', updated_at=NOW()")) {
                    $iut->bind_param('iiss', $item_id, $unit_type_id, $unit_type, $barcode);
                    $iut->execute();
                    $iut->close();
                }
                if ($pr = $conn->prepare("INSERT INTO motorpool_item_unit_pricing (item_id, unit_type_id, unit_price, unit_quantity, effective_date, price_level) VALUES (?, ?, ?, 1, CURDATE(), 'Standard') ON DUPLICATE KEY UPDATE unit_price=VALUES(unit_price), effective_date=VALUES(effective_date), updated_at=NOW()")) {
                    $pr->bind_param('iid', $item_id, $unit_type_id, $unit_price);
                    $pr->execute();
                    $pr->close();
                }
            }

            echo json_encode(['success'=>true, 'message'=>'Motorpool item updated successfully.']);
            exit;
        } catch (Throwable $e) {
            error_log('motorpool quick update error: ' . $e->getMessage());
            echo json_encode(['success'=>false, 'message'=>'Edit error: ' . $e->getMessage()]);
            exit;
        }
    }

    // SAFE MOTORPOOL ITEM DETAILS HANDLER
    // This handler runs before the large Branch Admin action switch so View Details/Edit
    // will always get clean JSON from motorpool tables only.
    if (false && ($_POST['action'] ?? '') === 'get_item') {
        try {
            $item_id = (int)($_POST['item_id'] ?? 0);
            if ($item_id <= 0) {
                throw new Exception('Invalid item ID');
            }

            $item_sql = "SELECT i.*, CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,'')) AS created_by_name
                         FROM motorpool_inventory_items i
                         LEFT JOIN users u ON u.user_id = i.created_by
                         WHERE i.item_id = ? AND COALESCE(i.status, 'active') <> 'deleted'";
            if ($items_branch_column_exists && !$view_all_branches && $branch_id > 0) {
                $item_sql .= " AND (i.branch_id = ? OR i.branch_id IS NULL OR i.branch_id = 0)";
            }
            $stmt = $conn->prepare($item_sql);
            if (!$stmt) throw new Exception('Database prepare error while loading item: ' . $conn->error);
            if ($items_branch_column_exists && !$view_all_branches && $branch_id > 0) {
                $stmt->bind_param('ii', $item_id, $branch_id);
            } else {
                $stmt->bind_param('i', $item_id);
            }
            $stmt->execute();
            $item = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$item) throw new Exception('Item not found');

            $unitTypes = [];
            $unit_sql = "SELECT
                    COALESCE(inv.unit_type_id, i.default_unit_type_id, ut.unit_type_id, 0) AS unit_type_id,
                    COALESCE(ut.unit_type_name, i.unit_type, 'Piece') AS unit_type_name,
                    COALESCE(ut.uom_initial, '') AS uom_initial,
                    COALESCE(NULLIF(iut.barcode,''), i.barcode, '') AS barcode,
                    COALESCE(iut.smallest_pack_quantity, ut.quantity_smallest_pack, 1) AS quantity_smallest_pack,
                    CASE WHEN COALESCE(inv.unit_type_id, i.default_unit_type_id, ut.unit_type_id, 0) = COALESCE(i.default_unit_type_id, 0) THEN 1 ELSE COALESCE(iut.is_default_uom, ut.is_default_uom, 0) END AS is_default_uom,
                    COALESCE(iut.status, ut.status, 'active') AS unit_status,
                    1 AS unit_quantity,
                    COALESCE(inv.current_inventory, NULLIF(i.stock,0), i.current_stock, 0) AS current_inventory,
                    COALESCE(inv.beginning_inventory, inv.current_inventory, NULLIF(i.stock,0), i.current_stock, 0) AS beginning_inventory,
                    COALESCE(inv.as_of_date, DATE(i.created_at)) AS as_of_date,
                    COALESCE(NULLIF(inv.unit_cost,0), NULLIF(i.unit_cost,0), i.unit_price, 0) AS unit_cost,
                    CASE
                        WHEN COALESCE(inv.current_inventory,0) > 0 AND COALESCE(inv.total_cost,0) > 0 THEN inv.total_cost / inv.current_inventory
                        ELSE COALESCE(NULLIF(inv.unit_cost,0), NULLIF(i.unit_cost,0), i.unit_price, 0)
                    END AS average_cost,
                    CASE
                        WHEN COALESCE(inv.total_cost,0) > 0 THEN inv.total_cost
                        ELSE COALESCE(NULLIF(i.total_cost,0), COALESCE(NULLIF(i.stock,0), i.current_stock, 0) * COALESCE(NULLIF(i.unit_cost,0), i.unit_price, 0))
                    END AS total_cost,
                    COALESCE(i.reorder_level, 0) AS reorder_level
                FROM motorpool_inventory_items i
                LEFT JOIN motorpool_item_unit_inventory inv ON inv.item_id = i.item_id AND COALESCE(inv.status, 'active') = 'active'
                LEFT JOIN motorpool_unit_types ut ON ut.unit_type_id = COALESCE(inv.unit_type_id, i.default_unit_type_id)
                LEFT JOIN motorpool_item_unit_types iut ON iut.item_id = i.item_id AND iut.unit_type_id = COALESCE(inv.unit_type_id, i.default_unit_type_id)
                WHERE i.item_id = ?
                ORDER BY is_default_uom DESC, unit_type_name ASC";
            if ($ustmt = $conn->prepare($unit_sql)) {
                $ustmt->bind_param('i', $item_id);
                $ustmt->execute();
                $ures = $ustmt->get_result();
                while ($r = $ures->fetch_assoc()) $unitTypes[] = $r;
                $ustmt->close();
            }
            if (empty($unitTypes)) {
                $stock = (float)($item['stock'] ?: $item['current_stock'] ?: 0);
                $cost = (float)($item['unit_cost'] ?: $item['unit_price'] ?: 0);
                $unitTypes[] = [
                    'unit_type_id' => (int)($item['default_unit_type_id'] ?? 0),
                    'unit_type_name' => $item['unit_type'] ?: 'Piece',
                    'uom_initial' => '',
                    'barcode' => $item['barcode'] ?? '',
                    'quantity_smallest_pack' => 1,
                    'is_default_uom' => 1,
                    'unit_status' => 'active',
                    'unit_quantity' => 1,
                    'current_inventory' => $stock,
                    'beginning_inventory' => $stock,
                    'as_of_date' => $item['created_at'] ?? null,
                    'unit_cost' => $cost,
                    'average_cost' => $cost,
                    'total_cost' => (float)($item['total_cost'] ?: ($stock * $cost)),
                    'reorder_level' => (float)($item['reorder_level'] ?? 0)
                ];
            }

            $images = [];
            if (amgcTableExists($conn, 'motorpool_item_images')) {
                if ($imgStmt = $conn->prepare("SELECT image_id, image_path, image_order, is_primary FROM motorpool_item_images WHERE item_id = ? ORDER BY is_primary DESC, image_order ASC, image_id ASC")) {
                    $imgStmt->bind_param('i', $item_id);
                    $imgStmt->execute();
                    $imgRes = $imgStmt->get_result();
                    while ($img = $imgRes->fetch_assoc()) $images[] = $img;
                    $imgStmt->close();
                }
            }
            if (empty($images)) {
                $imagePath = trim((string)($item['product_image_url'] ?: $item['item_image'] ?: ''));
                if ($imagePath !== '') {
                    $images[] = ['image_id' => 0, 'image_path' => $imagePath, 'image_order' => 0, 'is_primary' => 1];
                }
            }

            $pricing_rows = [];
            $pricing_history = [];
            if (amgcTableExists($conn, 'motorpool_item_unit_pricing')) {
                $prSql = "SELECT COALESCE(iup.effective_date, DATE(iup.created_at)) AS effective_date,
                                 COALESCE(NULLIF(iup.price_level,''), 'Standard') AS price_level,
                                 iup.unit_type_id,
                                 COALESCE(ut.unit_type_name, 'Piece') AS unit_type_name,
                                 COALESCE(iup.unit_price,0) AS unit_price,
                                 COALESCE(iup.unit_quantity,1) AS unit_quantity,
                                 COALESCE(ut.is_default_uom,0) AS is_default_uom
                          FROM motorpool_item_unit_pricing iup
                          LEFT JOIN motorpool_unit_types ut ON ut.unit_type_id = iup.unit_type_id
                          WHERE iup.item_id = ?
                          ORDER BY price_level ASC, effective_date DESC";
                if ($prStmt = $conn->prepare($prSql)) {
                    $prStmt->bind_param('i', $item_id);
                    $prStmt->execute();
                    $prRes = $prStmt->get_result();
                    $map = [];
                    while ($pr = $prRes->fetch_assoc()) {
                        $key = ($pr['effective_date'] ?? '') . '||' . ($pr['price_level'] ?? 'Standard');
                        if (!isset($map[$key])) $map[$key] = ['effective_date'=>$pr['effective_date'], 'price_level'=>$pr['price_level'] ?: 'Standard', 'prices'=>[]];
                        $map[$key]['prices'][$pr['unit_type_name']] = ['unit_type_id'=>(int)$pr['unit_type_id'], 'unit_price'=>(float)$pr['unit_price'], 'unit_quantity'=>(int)$pr['unit_quantity']];
                        $pricing_history[] = [
                            'history_source'=>'current',
                            'effective_date'=>$pr['effective_date'],
                            'price_level'=>$pr['price_level'] ?: 'Standard',
                            'unit_type_name'=>$pr['unit_type_name'],
                            'unit_price'=>(float)$pr['unit_price'],
                            'unit_quantity'=>(int)$pr['unit_quantity'],
                            'created_at'=>$pr['effective_date'],
                            'updated_at'=>$pr['effective_date'],
                            'sort_datetime'=>$pr['effective_date']
                        ];
                    }
                    $pricing_rows = array_values($map);
                    $prStmt->close();
                }
            }
            if (empty($pricing_rows)) {
                $prices = [];
                foreach ($unitTypes as $ut) {
                    $prices[$ut['unit_type_name']] = ['unit_type_id'=>(int)$ut['unit_type_id'], 'unit_price'=>(float)($item['unit_price'] ?: $item['unit_cost'] ?: $ut['unit_cost']), 'unit_quantity'=>1];
                }
                $pricing_rows[] = ['effective_date'=>date('Y-m-d'), 'price_level'=>'Standard', 'prices'=>$prices];
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
            foreach ($unitTypes as $ut) {
                $inventory_summary['beginning_inventory'] += (float)($ut['beginning_inventory'] ?? 0);
                $inventory_summary['total_inventory'] += (float)($ut['current_inventory'] ?? 0);
                $inventory_summary['total_cost'] += (float)($ut['total_cost'] ?? 0);
            }
            // Use the item table total_cost when available to avoid double-counting migrated per-UOM rows.
            if ((float)($item['total_cost'] ?? 0) > 0) {
                $inventory_summary['total_cost'] = (float)$item['total_cost'];
            }
            if ($inventory_summary['total_inventory'] > 0) {
                $inventory_summary['average_cost_month'] = $inventory_summary['total_cost'] / $inventory_summary['total_inventory'];
            }
            $item['stock'] = $inventory_summary['total_inventory'];
            $item['barcode'] = $item['barcode'] ?: ($unitTypes[0]['barcode'] ?? '');

            $transactions = [];
            if (amgcTableExists($conn, 'motorpool_inventory_transactions')) {
                $txSql = "SELECT t.transaction_id, t.item_id, t.transaction_type,
                                 COALESCE(NULLIF(t.quantity_changed,0), t.quantity, 0) AS quantity,
                                 COALESCE(t.unit_cost, 0) AS unit_cost,
                                 COALESCE(t.total_cost, 0) AS total_cost,
                                 COALESCE(t.reference_type, '') AS reference_type,
                                 t.reference_id,
                                 COALESCE(t.receive_memo, t.remarks, '') AS notes,
                                 t.created_at,
                                 COALESCE(ut.unit_type_name, ?) AS uom,
                                 CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,'')) AS actor_name,
                                 COALESCE(NULLIF(t.reference_type,''), t.transaction_type) AS source_label,
                                 COALESCE(NULLIF(i.supplier,''), 'Motorpool') AS party_name,
                                 CASE WHEN t.reference_id IS NOT NULL THEN CONCAT('Ref #', t.reference_id) ELSE '—' END AS reference_label
                          FROM motorpool_inventory_transactions t
                          LEFT JOIN motorpool_inventory_items i ON i.item_id = t.item_id
                          LEFT JOIN motorpool_unit_types ut ON ut.unit_type_id = i.default_unit_type_id
                          LEFT JOIN users u ON u.user_id = COALESCE(t.created_by, t.encoded_by)
                          WHERE t.item_id = ?
                          ORDER BY t.created_at DESC, t.transaction_id DESC
                          LIMIT 100";
                if ($txStmt = $conn->prepare($txSql)) {
                    $fallbackUnit = (string)($item['unit_type'] ?: 'Piece');
                    $txStmt->bind_param('si', $fallbackUnit, $item_id);
                    $txStmt->execute();
                    $txRes = $txStmt->get_result();
                    while ($tx = $txRes->fetch_assoc()) $transactions[] = $tx;
                    $txStmt->close();
                }
            }

            echo json_encode([
                'success' => true,
                'item' => $item,
                'motorpool_unit_types' => $unitTypes,
                'images' => $images,
                'pricing_rows' => $pricing_rows,
                'pricing_history' => $pricing_history,
                'inventory_summary' => $inventory_summary,
                'transactions' => $transactions
            ]);
            exit;
        } catch (Throwable $e) {
            error_log('motorpool safe get_item error: ' . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Item details error: ' . $e->getMessage()]);
            exit;
        }
    }

    try {
        $conn->begin_transaction();

        // ADD CHART OF ACCOUNT DIRECTLY FROM ITEM ACCOUNT DROPDOWNS
        if ($_POST['action'] === 'add_item_chart_account') {
            $account_kind = trim($_POST['account_kind'] ?? '');
            $account_title = trim($_POST['account_title'] ?? '');
            $account_code = trim($_POST['account_code'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $parent_account_id = (int)($_POST['parent_account_id'] ?? 0);

            $accountTypeMap = [
                'income' => 'Income',
                'cogs' => 'Cost of Goods Sold',
                'asset' => 'Other Current Asset'
            ];

            if (!isset($accountTypeMap[$account_kind])) {
                throw new Exception('Invalid account category selected.');
            }
            if ($account_title === '') {
                throw new Exception('Account title is required.');
            }

            $account_type = $accountTypeMap[$account_kind];
            $coa_branch_id = (!$view_all_branches && $branch_id > 0) ? (int)$branch_id : ((int)$branch_id > 0 ? (int)$branch_id : 0);

            if ($parent_account_id > 0) {
                $parent_query = "SELECT account_id FROM chart_of_accounts WHERE account_id = ? AND account_type = ? AND status = 'active'";
                $parent_types = 'is';
                $parent_params = [$parent_account_id, $account_type];
                if (!$view_all_branches && $branch_id > 0) {
                    $parent_query .= " AND (branch_id = ? OR branch_id = 0)";
                    $parent_types .= 'i';
                    $parent_params[] = $branch_id;
                }
                $parent_stmt = $conn->prepare($parent_query);
                if (!$parent_stmt) {
                    throw new Exception('Database prepare error while checking parent account.');
                }
                $parent_stmt->bind_param($parent_types, ...$parent_params);
                $parent_stmt->execute();
                $parent_result = $parent_stmt->get_result();
                if (!$parent_result || $parent_result->num_rows === 0) {
                    throw new Exception('Parent Account must have the same Account Type.');
                }
                $parent_stmt->close();
            } else {
                $parent_account_id = null;
            }

            $check_query = "SELECT account_id, account_code, account_title, account_type
                            FROM chart_of_accounts
                            WHERE LOWER(TRIM(CONVERT(account_title USING utf8mb4) COLLATE utf8mb4_unicode_ci)) = LOWER(TRIM(CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci))
                              AND account_type = ?
                              AND status = 'active'
                              AND branch_id = ?
                            LIMIT 1";
            $check_stmt = $conn->prepare($check_query);
            if (!$check_stmt) {
                throw new Exception('Database prepare error while checking chart account.');
            }
            $check_stmt->bind_param('ssi', $account_title, $account_type, $coa_branch_id);
            $check_stmt->execute();
            $existing_result = $check_stmt->get_result();
            if ($existing_result && $existing_row = $existing_result->fetch_assoc()) {
                $label_code = trim((string)($existing_row['account_code'] ?? ''));
                $label_title = trim((string)($existing_row['account_title'] ?? ''));
                $existing_label = ($label_code !== '' ? $label_code . ' · ' : '') . $label_title;

                $conn->commit();
                echo json_encode([
                    'success' => true,
                    'message' => 'Account already exists. It has been selected.',
                    'account' => [
                        'id' => (int)$existing_row['account_id'],
                        'label' => $existing_label,
                        'type' => $existing_row['account_type'],
                        'kind' => $account_kind
                    ]
                ]);
                exit;
            }
            $check_stmt->close();

            $insert_account_query = "INSERT INTO chart_of_accounts
                (branch_id, parent_account_id, account_code, account_title, account_type, description, balance, status, created_by, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, 0.00, 'active', ?, NOW(), NOW())";
            $insert_account_stmt = $conn->prepare($insert_account_query);
            if (!$insert_account_stmt) {
                throw new Exception('Database prepare error while creating chart account.');
            }
            $insert_account_stmt->bind_param('iissssi', $coa_branch_id, $parent_account_id, $account_code, $account_title, $account_type, $description, $user_id);
            if (!$insert_account_stmt->execute()) {
                throw new Exception('Failed to create chart account: ' . $insert_account_stmt->error);
            }

            $new_account_id = (int)$conn->insert_id;
            $insert_account_stmt->close();

            $label = ($account_code !== '' ? $account_code . ' · ' : '') . $account_title;

            $conn->commit();
            echo json_encode([
                'success' => true,
                'message' => 'Account added successfully.',
                'account' => [
                    'id' => $new_account_id,
                    'label' => $label,
                    'type' => $account_type,
                    'kind' => $account_kind
                ]
            ]);
            exit;
        }

        // ADD ITEM
        if ($_POST['action'] === 'add_item') {
            $item_code = $_POST['item_code'] ?? '';
            $barcode = trim($_POST['barcode'] ?? '');
            $item_name = $_POST['item_name'] ?? '';
            $description = trim($_POST['description'] ?? '');
            $category = $_POST['category'] ?? 'General';
            $principal = trim($_POST['principal'] ?? '');
            $principal = ($principal !== '' && strtolower($principal) !== 'no principal') ? $principal : null;
            $volume = (strtolower($category) === 'oil') ? trim($_POST['volume'] ?? '') : null;
            $volume = (empty($volume)) ? null : $volume;
            $oil_type = (strtolower($category) === 'oil') ? trim($_POST['oil_type'] ?? '') : null;
            $oil_type = (empty($oil_type)) ? null : $oil_type;
            $income_account_id = (int)($_POST['income_account_id'] ?? 0);
            $cogs_account_id = (int)($_POST['cogs_account_id'] ?? 0);
            $asset_account_id = (int)($_POST['asset_account_id'] ?? 0);
            $stock = 0;
            
            $motorpool_unit_types_json = $_POST['motorpool_unit_types'] ?? '[]';
            $motorpool_unit_types_array = json_decode($motorpool_unit_types_json, true);
            
            $pricing_json = $_POST['pricing'] ?? '[]';
            $pricing_data = json_decode($pricing_json, true);
            
            if (!is_array($motorpool_unit_types_array) || count($motorpool_unit_types_array) === 0) {
                throw new Exception('At least one unit type with price is required');
            }
            
            $first_unit = $motorpool_unit_types_array[0];
            $unit_type = $first_unit['unit_type'];
            $unit_price = (float)$first_unit['unit_price'];
            
            $reorder_level = (int)($_POST['reorder_level'] ?? 0);
            $status = $_POST['status'] ?? 'active';
            $points_eligible = isset($_POST['points_eligible']) ? (int)$_POST['points_eligible'] : 1;
            $points_eligible = $points_eligible === 1 ? 1 : 0;
            
            if (empty($item_code)) throw new Exception('Item code is required');
            if (empty($item_name)) throw new Exception('Item name is required');
            if (empty($description)) $description = $item_name;

            $validateAccount = function($accountId, $allowedTypes, $label) use ($conn, $branch_id, $view_all_branches) {
                $query = "SELECT account_id FROM chart_of_accounts WHERE account_id = ? AND status = 'active'";
                $types = 'i';
                $params = [$accountId];
                if (!$view_all_branches && $branch_id > 0) {
                    $query .= " AND (branch_id = ? OR branch_id = 0)";
                    $types .= 'i';
                    $params[] = $branch_id;
                }
                if (!empty($allowedTypes)) {
                    $placeholders = implode(',', array_fill(0, count($allowedTypes), '?'));
                    $query .= " AND account_type IN ($placeholders)";
                    $types .= str_repeat('s', count($allowedTypes));
                    foreach ($allowedTypes as $type) {
                        $params[] = $type;
                    }
                }
                $stmt = $conn->prepare($query);
                if (!$stmt) throw new Exception('Database prepare error while validating ' . $label);
                $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $result = $stmt->get_result();
                if (!$result || $result->num_rows === 0) {
                    throw new Exception('Please select a valid ' . $label);
                }
                $stmt->close();
            };
            if ($income_account_id > 0) {
                $validateAccount($income_account_id, ['Income'], 'Income Account');
            }
            if ($cogs_account_id > 0) {
                $validateAccount($cogs_account_id, ['Cost of Goods Sold'], 'COGS Account');
            }
            if ($asset_account_id > 0) {
                $validateAccount($asset_account_id, ['Other Current Asset'], 'Asset Account');
            }
            
            $price_case = $unit_price * 12;
            $price_inner_pack = $unit_price * 6;
            $price_box = $unit_price * 24;
            $price_carton = $unit_price * 48;
            
            $check_query = "SELECT item_id FROM motorpool_inventory_items WHERE item_code = ?";
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
                $barcode_check_query = "SELECT item_id FROM motorpool_inventory_items WHERE barcode = ?";
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
                    $upload_dir = '../uploads/motorpool_inventory/';
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
                $insert_query = "INSERT INTO motorpool_inventory_items (
                    item_code, barcode, item_name, description, category, principal, volume, oil_type, income_account_id, cogs_account_id, asset_account_id, stock, unit_type, unit_price, 
                    price_case, price_inner_pack, price_box, price_carton, reorder_level, status, 
                    product_image_url, branch_id, created_by, updated_by, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
                $insert_stmt = $conn->prepare($insert_query);
                if (!$insert_stmt) throw new Exception('Database prepare error');
                $insert_stmt->bind_param("ssssssssiiidsdddddissiii", 
                    $item_code, $barcode, $item_name, $description, $category, $principal, $volume, $oil_type, $income_account_id, $cogs_account_id, $asset_account_id, $stock, $unit_type, $unit_price,
                    $price_case, $price_inner_pack, $price_box, $price_carton,
                    $reorder_level, $status, $product_image_url, $branch_id, $user_id, $user_id
                );
            } else {
                $insert_query = "INSERT INTO motorpool_inventory_items (
                    item_code, barcode, item_name, description, category, principal, volume, oil_type, income_account_id, cogs_account_id, asset_account_id, stock, unit_type, unit_price, 
                    price_case, price_inner_pack, price_box, price_carton, reorder_level, status, 
                    product_image_url, created_by, updated_by, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
                $insert_stmt = $conn->prepare($insert_query);
                if (!$insert_stmt) throw new Exception('Database prepare error');
                $insert_stmt->bind_param("ssssssssiiidsdddddissii", 
                    $item_code, $barcode, $item_name, $description, $category, $principal, $volume, $oil_type, $income_account_id, $cogs_account_id, $asset_account_id, $stock, $unit_type, $unit_price,
                    $price_case, $price_inner_pack, $price_box, $price_carton,
                    $reorder_level, $status, $product_image_url, $user_id, $user_id
                );
            }
            if (!$insert_stmt->execute()) {
                throw new Exception('Failed to add item: ' . $insert_stmt->error);
            }
            $item_id = $conn->insert_id;
            motorpoolRecordItemActivity($conn, $item_id, 'created', $user_id, 'Item created');
            $principal_update_stmt = $conn->prepare("UPDATE motorpool_inventory_items SET principal = ? WHERE item_id = ?");
            if ($principal_update_stmt) {
                $principal_update_stmt->bind_param("si", $principal, $item_id);
                $principal_update_stmt->execute();
                $principal_update_stmt->close();
            }
            $points_update_stmt = $conn->prepare("UPDATE motorpool_inventory_items SET points_eligible = ? WHERE item_id = ?");
            if ($points_update_stmt) {
                $points_update_stmt->bind_param("ii", $points_eligible, $item_id);
                $points_update_stmt->execute();
                $points_update_stmt->close();
            }
            
            // Get unique unit types
            $unique_motorpool_unit_types = [];
            foreach ($motorpool_unit_types_array as $ut_data) {
                $ut_name = trim($ut_data['unit_type']);
                if (!isset($unique_motorpool_unit_types[$ut_name])) {
                    $unique_motorpool_unit_types[$ut_name] = $ut_data;
                }
            }
            
            $default_unit_type_id = null;
            foreach ($unique_motorpool_unit_types as $ut_name => $ut_data) {
                $ut_price = (float)$ut_data['unit_price'];
                $ut_quantity = (int)($ut_data['unit_quantity'] ?? 1);
                $ut_initial = strtoupper(trim($ut_data['uom_initial'] ?? ''));
                $ut_barcode = trim($ut_data['barcode'] ?? '');
                $ut_qty_smallest = (int)($ut_data['qty_smallest_pack'] ?? 1);
                $ut_is_default = (int)($ut_data['default_uom'] ?? 0);
                $ut_status = $ut_data['status'] ?? 'active';
                $ut_current_inventory = isset($ut_data['current_inventory']) ? (float)$ut_data['current_inventory'] : 0;
                $ut_as_of_date = !empty($ut_data['as_of_date']) ? $ut_data['as_of_date'] : null;
                $ut_unit_cost = isset($ut_data['unit_cost']) ? (float)$ut_data['unit_cost'] : $ut_price;
                
                $ut_id = amgcResolveUnitTypeIdForItemUom($conn, $ut_name, $ut_initial, $branch_id, $items_branch_column_exists);
if ($ut_is_default) {
                    $default_unit_type_id = $ut_id;
                }

                upsertItemUnitInventory($conn, $item_id, $ut_id, $ut_current_inventory, $ut_as_of_date, $ut_unit_cost);
                upsertItemUnitTypeRow($conn, $item_id, $ut_id, $ut_name, $ut_initial, $ut_barcode, $ut_qty_smallest, $ut_is_default, $branch_id, $ut_status);
                
                if (!empty($pricing_data) && is_array($pricing_data)) {
                    foreach ($pricing_data as $pricing_row) {
                        $pricesForRow = isset($pricing_row['prices']) && is_array($pricing_row['prices']) ? $pricing_row['prices'] : [];
                        $matchedPrice = null;
                        if (array_key_exists($ut_name, $pricesForRow)) {
                            $matchedPrice = $pricesForRow[$ut_name];
                        } elseif (array_key_exists((string)$ut_id, $pricesForRow)) {
                            $matchedPrice = $pricesForRow[(string)$ut_id];
                        } else {
                            foreach ($pricesForRow as $priceKey => $priceValue) {
                                if (strtolower(trim((string)$priceKey)) === strtolower(trim((string)$ut_name))) {
                                    $matchedPrice = $priceValue;
                                    break;
                                }
                            }
                        }

                        if ($matchedPrice !== null && trim((string)$matchedPrice) !== '') {
                            $ut_price = (float)$matchedPrice;
                            $effective_date = !empty($pricing_row['effective_date']) ? $pricing_row['effective_date'] : null;
                            $price_level = !empty($pricing_row['price_level']) ? trim((string)$pricing_row['price_level']) : 'Standard';
                            if ($price_level === '') $price_level = 'Standard';
                            $effective_date = !empty($effective_date) ? normalizeImportDateValue($effective_date) : null;
                            amgcUpsertItemUnitPricingStrict($conn, $item_id, $ut_id, $ut_price, $ut_quantity, $effective_date, $price_level);
                        }
                    }
                }
            }
            
            // If no default was marked, set the first unit type as default
            if (!$default_unit_type_id) {
                $first_ut_query = "SELECT unit_type_id FROM motorpool_item_unit_pricing WHERE item_id = ? LIMIT 1";
                $first_stmt = $conn->prepare($first_ut_query);
                $first_stmt->bind_param("i", $item_id);
                $first_stmt->execute();
                $first_res = $first_stmt->get_result();
                if ($first_row = $first_res->fetch_assoc()) {
                    $default_unit_type_id = $first_row['unit_type_id'];
                }
            }
            if ($default_unit_type_id) {
                $update_default = "UPDATE motorpool_inventory_items SET default_unit_type_id = ? WHERE item_id = ?";
                $upd_def_stmt = $conn->prepare($update_default);
                $upd_def_stmt->bind_param("ii", $default_unit_type_id, $item_id);
                $upd_def_stmt->execute();
            }

            // Keep items.barcode as the default/base UOM barcode only.
            // Other UOM barcodes stay per item in motorpool_item_unit_types and will not affect other items.
            if ($default_unit_type_id) {
                $default_barcode_stmt = $conn->prepare("SELECT barcode FROM motorpool_item_unit_types WHERE item_id = ? AND unit_type_id = ? AND status = 'active' LIMIT 1");
                if ($default_barcode_stmt) {
                    $default_barcode_stmt->bind_param("ii", $item_id, $default_unit_type_id);
                    $default_barcode_stmt->execute();
                    $default_barcode_row = $default_barcode_stmt->get_result()->fetch_assoc();
                    $default_barcode_stmt->close();
                    $default_item_barcode = trim((string)($default_barcode_row['barcode'] ?? ''));
                    if ($default_item_barcode !== '') {
                        $update_item_barcode_stmt = $conn->prepare("UPDATE motorpool_inventory_items SET barcode = ? WHERE item_id = ?");
                        if ($update_item_barcode_stmt) {
                            $update_item_barcode_stmt->bind_param("si", $default_item_barcode, $item_id);
                            $update_item_barcode_stmt->execute();
                            $update_item_barcode_stmt->close();
                        }
                    }
                }
            }

            syncItemSummaryFromDefaultInventory($conn, $item_id);
            
            if (isset($_FILES['itemImages']) && !empty($_FILES['itemImages']['name'][0])) {
                $uploaded_images = handleMultipleImageUpload($_FILES['itemImages'], $item_id);
                foreach ($uploaded_images as $index => $img_file) {
                    $img_query = "INSERT INTO motorpool_item_images (item_id, image_path, image_order, is_primary) VALUES (?, ?, ?, ?)";
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
                    COALESCE(iut.unit_type_name, ut.unit_type_name, i.unit_type, 'Piece') AS unit_type,
                    COALESCE(iut.barcode, '') AS barcode,
                    COALESCE(iut.smallest_pack_quantity, ut.quantity_smallest_pack, iup.unit_quantity, 1) AS qty_smallest_pack,
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
                FROM motorpool_inventory_items i
                LEFT JOIN motorpool_item_unit_pricing iup ON iup.item_id = i.item_id
                LEFT JOIN motorpool_unit_types ut ON ut.unit_type_id = iup.unit_type_id
                LEFT JOIN motorpool_item_unit_types iut ON iut.item_id = i.item_id AND iut.unit_type_id = iup.unit_type_id AND (iut.status IS NULL OR iut.status = 'active')
                LEFT JOIN motorpool_item_unit_inventory inv ON inv.item_id = i.item_id AND inv.unit_type_id = iup.unit_type_id
                LEFT JOIN motorpool_item_unit_inventory inv_default ON inv_default.item_id = i.item_id AND inv_default.unit_type_id = i.default_unit_type_id
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
            $income_account_id = (int)($_POST['income_account_id'] ?? 0);
            $cogs_account_id = (int)($_POST['cogs_account_id'] ?? 0);
            $asset_account_id = (int)($_POST['asset_account_id'] ?? 0);
            $stock = 0;
            
            $motorpool_unit_types_json = $_POST['motorpool_unit_types'] ?? '[]';
            $motorpool_unit_types_array = json_decode($motorpool_unit_types_json, true);
            
            $pricing_json = $_POST['pricing'] ?? '[]';
            $pricing_data = json_decode($pricing_json, true);
            
            if (!is_array($motorpool_unit_types_array) || count($motorpool_unit_types_array) === 0) {
                throw new Exception('At least one unit type with price is required');
            }
            
            $first_unit = $motorpool_unit_types_array[0];
            $unit_type = $first_unit['unit_type'];
            $unit_price = (float)$first_unit['unit_price'];
            $reorder_level = (int)($_POST['reorder_level'] ?? 0);
            $status = $_POST['status'] ?? 'active';
            $points_eligible = isset($_POST['points_eligible']) ? (int)$_POST['points_eligible'] : 1;
            $points_eligible = $points_eligible === 1 ? 1 : 0;
            
            if ($item_id <= 0) throw new Exception('Invalid item ID');
            if (empty($item_name)) throw new Exception('Item name is required');
            if (empty($description)) $description = $item_name;

            $validateAccount = function($accountId, $allowedTypes, $label) use ($conn, $view_all_branches, $branch_id) {
                if ((int)$accountId <= 0) {
                    throw new Exception($label . ' is required');
                }

                $query = "SELECT account_id FROM chart_of_accounts WHERE account_id = ? AND status = 'active'";
                $types = 'i';
                $params = [(int)$accountId];

                if (!$view_all_branches && $branch_id > 0) {
                    $query .= " AND (branch_id = ? OR branch_id = 0 OR branch_id IS NULL)";
                    $types .= 'i';
                    $params[] = (int)$branch_id;
                }

                if (!empty($allowedTypes)) {
                    $placeholders = implode(',', array_fill(0, count($allowedTypes), '?'));
                    $query .= " AND account_type IN ($placeholders)";
                    $types .= str_repeat('s', count($allowedTypes));
                    foreach ($allowedTypes as $type) {
                        $params[] = $type;
                    }
                }

                $stmt = $conn->prepare($query);
                if (!$stmt) throw new Exception('Database prepare error while validating ' . $label);
                $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $result = $stmt->get_result();
                if (!$result || $result->num_rows === 0) {
                    throw new Exception('Please select a valid ' . $label);
                }
                $stmt->close();
            };

            $validateAccount($income_account_id, ['Income'], 'Income Account');
            $validateAccount($cogs_account_id, ['Cost of Goods Sold'], 'COGS Account');
            $validateAccount($asset_account_id, ['Other Current Asset'], 'Asset Account');
            
            $item_code = $_POST['item_code'] ?? '';
            if ($items_branch_column_exists && !$view_all_branches && $branch_id > 0) {
                $duplicate_check = "SELECT item_id FROM motorpool_inventory_items WHERE item_code = ? AND branch_id = ? AND item_id != ?";
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
                $barcode_duplicate_query = "SELECT item_id FROM motorpool_inventory_items WHERE barcode = ? AND item_id != ?";
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
                    $upload_dir = '../uploads/motorpool_inventory/';
                    if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
                    $filename = 'item_' . $item_id . '_' . time() . '.' . $extension;
                    $filepath = $upload_dir . $filename;
                    if (move_uploaded_file($_FILES['item_image']['tmp_name'], $filepath)) {
                        $product_image_url = $filename;
                    }
                }
            }
            
            if ($product_image_url) {
                $update_query = "UPDATE motorpool_inventory_items SET barcode = ?, item_name = ?, description = ?, category = ?, volume = ?, oil_type = ?, income_account_id = ?, cogs_account_id = ?, asset_account_id = ?, stock = ?, unit_type = ?, unit_price = ?, price_case = ?, price_inner_pack = ?, price_box = ?, price_carton = ?, reorder_level = ?, status = ?, product_image_url = ?, updated_by = ?, updated_at = NOW() WHERE item_id = ?";
                $update_stmt = $conn->prepare($update_query);
                if (!$update_stmt) throw new Exception('Database prepare error');
                $update_stmt->bind_param("ssssssiiidsddddddssii", $barcode, $item_name, $description, $category, $volume, $oil_type, $income_account_id, $cogs_account_id, $asset_account_id, $stock, $unit_type, $unit_price, $price_case, $price_inner_pack, $price_box, $price_carton, $reorder_level, $status, $product_image_url, $user_id, $item_id);
            } else {
                $update_query = "UPDATE motorpool_inventory_items SET barcode = ?, item_name = ?, description = ?, category = ?, volume = ?, oil_type = ?, income_account_id = ?, cogs_account_id = ?, asset_account_id = ?, stock = ?, unit_type = ?, unit_price = ?, price_case = ?, price_inner_pack = ?, price_box = ?, price_carton = ?, reorder_level = ?, status = ?, updated_by = ?, updated_at = NOW() WHERE item_id = ?";
                $update_stmt = $conn->prepare($update_query);
                if (!$update_stmt) throw new Exception('Database prepare error');
                $update_stmt->bind_param("ssssssiiidsddddddsii", $barcode, $item_name, $description, $category, $volume, $oil_type, $income_account_id, $cogs_account_id, $asset_account_id, $stock, $unit_type, $unit_price, $price_case, $price_inner_pack, $price_box, $price_carton, $reorder_level, $status, $user_id, $item_id);
            }
            if (!$update_stmt->execute()) {
                throw new Exception('Failed to update item: ' . $update_stmt->error);
            }
            motorpoolRecordItemActivity($conn, $item_id, 'updated', $user_id, 'Item updated');
            $principal_update_stmt = $conn->prepare("UPDATE motorpool_inventory_items SET principal = ? WHERE item_id = ?");
            if ($principal_update_stmt) {
                $principal_update_stmt->bind_param("si", $principal, $item_id);
                $principal_update_stmt->execute();
                $principal_update_stmt->close();
            }
            $points_update_stmt = $conn->prepare("UPDATE motorpool_inventory_items SET points_eligible = ? WHERE item_id = ?");
            if ($points_update_stmt) {
                $points_update_stmt->bind_param("ii", $points_eligible, $item_id);
                $points_update_stmt->execute();
                $points_update_stmt->close();
            }
            
            // Process unit types and pricing
            $unique_motorpool_unit_types = [];
            foreach ($motorpool_unit_types_array as $ut_data) {
                $ut_name = trim($ut_data['unit_type']);
                if (!isset($unique_motorpool_unit_types[$ut_name])) {
                    $unique_motorpool_unit_types[$ut_name] = $ut_data;
                }
            }
            
            $default_unit_type_id = null;
            $ut_name_to_id_map = [];
            
            foreach ($unique_motorpool_unit_types as $ut_name => $ut_data) {
                $ut_initial = strtoupper(trim($ut_data['uom_initial'] ?? ''));
                $ut_barcode = trim($ut_data['barcode'] ?? '');
                $ut_qty_smallest = (int)($ut_data['qty_smallest_pack'] ?? 1);
                $ut_is_default = (int)($ut_data['default_uom'] ?? 0);
                $ut_status = $ut_data['status'] ?? 'active';
                $ut_current_inventory = isset($ut_data['current_inventory']) ? (float)$ut_data['current_inventory'] : 0;
                $ut_as_of_date = !empty($ut_data['as_of_date']) ? $ut_data['as_of_date'] : null;
                $ut_unit_cost = isset($ut_data['unit_cost']) ? (float)$ut_data['unit_cost'] : $ut_price;
                
                $ut_id = amgcResolveUnitTypeIdForItemUom($conn, $ut_name, $ut_initial, $branch_id, $items_branch_column_exists);
$ut_name_to_id_map[$ut_name] = $ut_id;
                if ($ut_is_default) {
                    $default_unit_type_id = $ut_id;
                }

                upsertItemUnitInventory($conn, $item_id, $ut_id, $ut_current_inventory, $ut_as_of_date, $ut_unit_cost);
                upsertItemUnitTypeRow($conn, $item_id, $ut_id, $ut_name, $ut_initial, $ut_barcode, $ut_qty_smallest, $ut_is_default, $branch_id, $ut_status);
            }
            
            // Update pricing without creating duplicate rows
            $seenPricingUpdates = [];
            foreach ($unique_motorpool_unit_types as $ut_name => $ut_data) {
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
            // Rows removed in the edit modal stayed in motorpool_item_unit_pricing/motorpool_item_unit_inventory,
            // so they appeared again when opening the item because get_item reads from both tables.
            $submitted_unit_type_ids = array_values(array_unique(array_map('intval', array_values($ut_name_to_id_map))));
            $submitted_unit_type_ids = array_filter($submitted_unit_type_ids, function($id) { return $id > 0; });

            if (count($submitted_unit_type_ids) > 0) {
                $delete_placeholders = implode(',', array_fill(0, count($submitted_unit_type_ids), '?'));
                $delete_types = 'i' . str_repeat('i', count($submitted_unit_type_ids));
                $delete_params = array_merge([$item_id], $submitted_unit_type_ids);

                $delete_removed_pricing = $conn->prepare("DELETE FROM motorpool_item_unit_pricing WHERE item_id = ? AND unit_type_id NOT IN ($delete_placeholders)");
                if (!$delete_removed_pricing) throw new Exception('Database prepare error while deleting removed unit pricing');
                $delete_removed_pricing->bind_param($delete_types, ...$delete_params);
                if (!$delete_removed_pricing->execute()) {
                    throw new Exception('Failed to delete removed unit pricing: ' . $delete_removed_pricing->error);
                }
                $delete_removed_pricing->close();

                $delete_removed_schedule = $conn->prepare("DELETE FROM motorpool_item_unit_pricing_schedule WHERE item_id = ? AND unit_type_id NOT IN ($delete_placeholders)");
                if ($delete_removed_schedule) {
                    $delete_removed_schedule->bind_param($delete_types, ...$delete_params);
                    if (!$delete_removed_schedule->execute()) {
                        throw new Exception('Failed to delete removed scheduled pricing: ' . $delete_removed_schedule->error);
                    }
                    $delete_removed_schedule->close();
                }

                $delete_removed_inventory = $conn->prepare("DELETE FROM motorpool_item_unit_inventory WHERE item_id = ? AND unit_type_id NOT IN ($delete_placeholders)");
                if ($delete_removed_inventory) {
                    $delete_removed_inventory->bind_param($delete_types, ...$delete_params);
                    if (!$delete_removed_inventory->execute()) {
                        throw new Exception('Failed to delete removed unit inventory: ' . $delete_removed_inventory->error);
                    }
                    $delete_removed_inventory->close();
                }

                $delete_removed_motorpool_item_unit_types = $conn->prepare("DELETE FROM motorpool_item_unit_types WHERE item_id = ? AND unit_type_id NOT IN ($delete_placeholders)");
                if ($delete_removed_motorpool_item_unit_types) {
                    $delete_removed_motorpool_item_unit_types->bind_param($delete_types, ...$delete_params);
                    if (!$delete_removed_motorpool_item_unit_types->execute()) {
                        throw new Exception('Failed to delete removed item UOM barcodes: ' . $delete_removed_motorpool_item_unit_types->error);
                    }
                    $delete_removed_motorpool_item_unit_types->close();
                }

                if (!$default_unit_type_id || !in_array((int)$default_unit_type_id, $submitted_unit_type_ids, true)) {
                    $default_unit_type_id = (int)$submitted_unit_type_ids[0];
                }
            }

            // Set default_unit_type_id if found
            if ($default_unit_type_id) {
                $update_default = "UPDATE motorpool_inventory_items SET default_unit_type_id = ? WHERE item_id = ?";
                $upd_def_stmt = $conn->prepare($update_default);
                $upd_def_stmt->bind_param("ii", $default_unit_type_id, $item_id);
                $upd_def_stmt->execute();
            } else {
                // No default marked, keep existing or set to first
                $first_ut_query = "SELECT unit_type_id FROM motorpool_item_unit_pricing WHERE item_id = ? LIMIT 1";
                $first_stmt = $conn->prepare($first_ut_query);
                $first_stmt->bind_param("i", $item_id);
                $first_stmt->execute();
                $first_res = $first_stmt->get_result();
                if ($first_row = $first_res->fetch_assoc()) {
                    $update_default = "UPDATE motorpool_inventory_items SET default_unit_type_id = ? WHERE item_id = ?";
                    $upd_def_stmt = $conn->prepare($update_default);
                    $upd_def_stmt->bind_param("ii", $first_row['unit_type_id'], $item_id);
                    $upd_def_stmt->execute();
                }
            }

            syncItemSummaryFromDefaultInventory($conn, $item_id);
            
            if (isset($_FILES['editItemImages']) && !empty($_FILES['editItemImages']['name'][0])) {
                $uploaded_images = handleMultipleImageUpload($_FILES['editItemImages'], $item_id);
                foreach ($uploaded_images as $index => $img_file) {
                    $img_query = "INSERT INTO motorpool_item_images (item_id, image_path, image_order, is_primary) VALUES (?, ?, ?, ?)";
                    $img_stmt = $conn->prepare($img_query);
                    $img_order = $index;
                    $existing_count_query = "SELECT COUNT(*) as cnt FROM motorpool_item_images WHERE item_id = ?";
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
                $unit_price = isset($row['unit_price']) ? importMoneyValue($row['unit_price']) : null;
                $unit_quantity = max(1, (int)($row['unit_quantity'] ?? 1));

                if ($item_id <= 0 || $unit_type_id <= 0 || $unit_price === null) {
                    continue;
                }

                $row_changed = false;
                $old_price_for_details = null;
                $old_effective_for_details = null;
                $old_quantity_for_details = null;
                $change_type_for_details = ($effective_date > $today) ? 'Scheduled' : 'Applied';

                $details_stmt = $conn->prepare("SELECT i.item_code, i.item_name, i.description, ut.unit_type_name FROM motorpool_inventory_items i JOIN motorpool_unit_types ut ON ut.unit_type_id = ? WHERE i.item_id = ? LIMIT 1");
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
                    $existing_schedule_stmt = $conn->prepare("SELECT unit_price, COALESCE(unit_quantity, 1) as unit_quantity, effective_date FROM motorpool_item_unit_pricing_schedule WHERE item_id = ? AND unit_type_id = ? AND price_level = ? AND effective_date = ? LIMIT 1");
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
                        $current_price_stmt = $conn->prepare("SELECT unit_price, COALESCE(unit_quantity, 1) as unit_quantity, effective_date FROM motorpool_item_unit_pricing WHERE item_id = ? AND unit_type_id = ? AND price_level = ? LIMIT 1");
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

                    $schedule_query = "INSERT INTO motorpool_item_unit_pricing_schedule (item_id, unit_type_id, price_level, unit_price, unit_quantity, effective_date)
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
                    $check_query = "SELECT pricing_id FROM motorpool_item_unit_pricing WHERE item_id = ? AND unit_type_id = ? AND price_level = ? LIMIT 1";
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
                        $current_snapshot_stmt = $conn->prepare("SELECT unit_price, COALESCE(unit_quantity, 1) as unit_quantity, effective_date FROM motorpool_item_unit_pricing WHERE pricing_id = ? LIMIT 1");
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

                        $update_query = "UPDATE motorpool_item_unit_pricing SET unit_price = ?, unit_quantity = ?, effective_date = ?, updated_at = NOW() WHERE pricing_id = ?";
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
                        $insert_query = "INSERT INTO motorpool_item_unit_pricing (item_id, unit_type_id, unit_price, unit_quantity, effective_date, price_level) VALUES (?, ?, ?, ?, ?, ?)";
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

                    $delete_schedule_stmt = $conn->prepare("DELETE FROM motorpool_item_unit_pricing_schedule WHERE item_id = ? AND unit_type_id = ? AND price_level = ? AND effective_date <= ?");
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
                $check_query = "SELECT item_id FROM motorpool_inventory_items WHERE item_id = ? AND branch_id = ?";
                $check_stmt = $conn->prepare($check_query);
                if (!$check_stmt) throw new Exception('Database prepare error');
                $check_stmt->bind_param("ii", $item_id, $branch_id);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                if ($check_result->num_rows === 0) {
                    throw new Exception('Item not found or access denied');
                }
            }
            $delete_images_query = "DELETE FROM motorpool_item_images WHERE item_id = ?";
            $delete_images_stmt = $conn->prepare($delete_images_query);
            if ($delete_images_stmt) {
                $delete_images_stmt->bind_param("i", $item_id);
                $delete_images_stmt->execute();
            }
            $delete_pricing_query = "DELETE FROM motorpool_item_unit_pricing WHERE item_id = ?";
            $delete_pricing_stmt = $conn->prepare($delete_pricing_query);
            if ($delete_pricing_stmt) {
                $delete_pricing_stmt->bind_param("i", $item_id);
                $delete_pricing_stmt->execute();
            }
            $delete_inventory_query = "DELETE FROM motorpool_item_unit_inventory WHERE item_id = ?";
            $delete_inventory_stmt = $conn->prepare($delete_inventory_query);
            if ($delete_inventory_stmt) {
                $delete_inventory_stmt->bind_param("i", $item_id);
                $delete_inventory_stmt->execute();
            }
            $delete_query = "DELETE FROM motorpool_inventory_items WHERE item_id = ?";
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
                FROM motorpool_inventory_items i
                LEFT JOIN motorpool_item_unit_pricing standard_current
                    ON standard_current.item_id = i.item_id
                    AND standard_current.price_level = 'Standard'
                LEFT JOIN motorpool_unit_types ut
                    ON ut.unit_type_id = COALESCE(standard_current.unit_type_id, i.default_unit_type_id)
                LEFT JOIN motorpool_item_unit_pricing selected_current
                    ON selected_current.item_id = i.item_id
                    AND selected_current.unit_type_id = standard_current.unit_type_id
                    AND selected_current.price_level = ?
                LEFT JOIN motorpool_item_unit_pricing_schedule selected_schedule
                    ON selected_schedule.item_id = i.item_id
                    AND selected_schedule.unit_type_id = standard_current.unit_type_id
                    AND selected_schedule.price_level = ?
                    AND selected_schedule.effective_date = ?
                LEFT JOIN motorpool_item_unit_pricing_schedule standard_schedule
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
            $item_query = "SELECT i.*, ut.unit_type_name as default_uom_name, ut.quantity_smallest_pack as default_multiplier, CONCAT(u.first_name, ' ', u.last_name) as created_by_name FROM motorpool_inventory_items i LEFT JOIN motorpool_unit_types ut ON i.default_unit_type_id = ut.unit_type_id LEFT JOIN users u ON i.created_by = u.user_id WHERE i.item_id = ?";
            if ($items_branch_column_exists && !$view_all_branches && $branch_id > 0) {
                $item_query .= " AND (i.branch_id = ? OR i.branch_id IS NULL OR i.branch_id = 0)";
            }
            $item_stmt = $conn->prepare($item_query);
            if (!$item_stmt) throw new Exception('Database prepare error: ' . $conn->error);
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

            // Query unit types from BOTH motorpool_item_unit_pricing AND motorpool_item_unit_inventory
            // This ensures we show all unit types that have received inventory, not just pricing entries
            $motorpool_unit_types_query = "
            SELECT DISTINCT 
                ut.unit_type_id, 
                ut.unit_type_name, 
                ut.uom_initial,
                COALESCE(NULLIF(iut.barcode, ''), CASE 
                    WHEN ut.unit_type_id = i.default_unit_type_id OR COALESCE(iut.is_default_uom, ut.is_default_uom, 0) = 1 THEN COALESCE(i.barcode, '')
                    ELSE ''
                END, '') AS barcode, 
                COALESCE(iut.smallest_pack_quantity, ut.quantity_smallest_pack, 1) AS quantity_smallest_pack, 
                COALESCE(iut.is_default_uom, ut.is_default_uom, 0) AS is_default_uom, 
                COALESCE(iut.status, ut.status) as unit_status, 
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
            FROM motorpool_unit_types ut
            LEFT JOIN motorpool_item_unit_pricing iup ON iup.unit_type_id = ut.unit_type_id AND iup.item_id = ?
            LEFT JOIN motorpool_item_unit_inventory inv ON inv.item_id = ? AND inv.unit_type_id = ut.unit_type_id
            LEFT JOIN motorpool_inventory_items i ON i.item_id = ?
            LEFT JOIN motorpool_item_unit_types iut ON iut.item_id = i.item_id AND iut.unit_type_id = ut.unit_type_id
            WHERE 
                (iup.item_id = ? OR inv.item_id = ?)
                AND ut.status = 'active'
            ORDER BY COALESCE(iut.is_default_uom, ut.is_default_uom, 0) DESC, ut.unit_type_name ASC
            ";
            $motorpool_unit_types_stmt = $conn->prepare($motorpool_unit_types_query);
            if (!$motorpool_unit_types_stmt) throw new Exception('Database prepare error');
            $motorpool_unit_types_stmt->bind_param("iiiii", $item_id, $item_id, $item_id, $item_id, $item_id);
            $motorpool_unit_types_stmt->execute();
            $motorpool_unit_types_result = $motorpool_unit_types_stmt->get_result();
            $motorpool_unit_types = $motorpool_unit_types_result->fetch_all(MYSQLI_ASSOC);
            if (empty($motorpool_unit_types)) {
                $motorpool_unit_types[] = [
                    'unit_type_id' => (int)($item['default_unit_type_id'] ?? 0),
                    'unit_type_name' => !empty($item['unit_type']) ? $item['unit_type'] : 'Piece',
                    'uom_initial' => '',
                    'barcode' => $item['barcode'] ?? '',
                    'quantity_smallest_pack' => 1,
                    'is_default_uom' => 1,
                    'unit_status' => 'active',
                    'unit_quantity' => 1,
                    'current_inventory' => (float)($item['stock'] ?? $item['current_stock'] ?? 0),
                    'beginning_inventory' => (float)($item['stock'] ?? $item['current_stock'] ?? 0),
                    'as_of_date' => $item['created_at'] ?? null,
                    'unit_cost' => (float)($item['unit_cost'] ?? $item['unit_price'] ?? 0),
                    'average_cost' => (float)($item['unit_cost'] ?? $item['unit_price'] ?? 0),
                    'total_cost' => (float)($item['total_cost'] ?? 0),
                    'reorder_level' => (float)($item['reorder_level'] ?? 0)
                ];
            }

            $images_query = "SELECT image_id, image_path, image_order, is_primary FROM motorpool_item_images WHERE item_id = ? ORDER BY image_order ASC, is_primary DESC";
            $images_stmt = $conn->prepare($images_query);
            if (!$images_stmt) throw new Exception('Database prepare error');
            $images_stmt->bind_param("i", $item_id);
            $images_stmt->execute();
            $images_result = $images_stmt->get_result();
            $images = $images_result->fetch_all(MYSQLI_ASSOC);
            if (empty($images) && !empty($item['product_image_url'])) {
                $images[] = ['image_id' => 0, 'image_path' => $item['product_image_url'], 'image_order' => 0, 'is_primary' => 1];
            } elseif (empty($images) && !empty($item['item_image'])) {
                $images[] = ['image_id' => 0, 'image_path' => $item['item_image'], 'image_order' => 0, 'is_primary' => 1];
            }

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
                    FROM motorpool_item_unit_pricing iup
                    JOIN motorpool_unit_types ut ON iup.unit_type_id = ut.unit_type_id
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
                    FROM motorpool_item_unit_pricing_schedule ips
                    JOIN motorpool_unit_types ut ON ips.unit_type_id = ut.unit_type_id
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
                FROM motorpool_item_unit_pricing iup
                JOIN motorpool_unit_types ut ON iup.unit_type_id = ut.unit_type_id
                WHERE iup.item_id = ?
                UNION ALL
                SELECT 'scheduled' as history_source, ips.effective_date, ips.price_level, ut.unit_type_name, ips.unit_price, COALESCE(ips.unit_quantity, 1) as unit_quantity, ips.created_at, ips.updated_at, COALESCE(ips.updated_at, ips.created_at) as sort_datetime
                FROM motorpool_item_unit_pricing_schedule ips
                JOIN motorpool_unit_types ut ON ips.unit_type_id = ut.unit_type_id
                WHERE ips.item_id = ?
                UNION ALL
                SELECT iuph.history_type as history_source, iuph.effective_date, iuph.price_level, ut.unit_type_name, iuph.unit_price, COALESCE(iuph.unit_quantity, 1) as unit_quantity, iuph.created_at, iuph.created_at as updated_at, iuph.created_at as sort_datetime
                FROM motorpool_item_unit_pricing_history iuph
                JOIN motorpool_unit_types ut ON iuph.unit_type_id = ut.unit_type_id
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
            $defaultUnitTypeIdForCost = (int)($item['default_unit_type_id'] ?? 0);
            $defaultCostRow = null;
            foreach ($motorpool_unit_types as &$ut) {
                $currentQty = (float)($ut['current_inventory'] ?? 0);
                $beginQty = (float)($ut['beginning_inventory'] ?? $currentQty);
                $unitCostVal = (float)($ut['unit_cost'] ?? 0);
                $totalCostVal = (float)($ut['total_cost'] ?? 0);
                if ($totalCostVal <= 0 && $unitCostVal > 0) {
                    $qtyForCost = $currentQty > 0 ? $currentQty : $beginQty;
                    $totalCostVal = $qtyForCost * $unitCostVal;
                    $ut['total_cost'] = $totalCostVal;
                }
                if ((float)($ut['average_cost'] ?? 0) <= 0) {
                    $ut['average_cost'] = $unitCostVal;
                }
                $inventory_summary['beginning_inventory'] += $beginQty;
                $inventory_summary['total_inventory'] += $currentQty;
                $inventory_summary['total_cost'] += $totalCostVal;
                if ($defaultCostRow === null) {
                    $defaultCostRow = $ut;
                }
                if ($defaultUnitTypeIdForCost > 0 && (int)($ut['unit_type_id'] ?? 0) === $defaultUnitTypeIdForCost) {
                    $defaultCostRow = $ut;
                }
            }
            unset($ut);
            $item['stock'] = $inventory_summary['total_inventory'];
            $item['current_stock'] = $inventory_summary['total_inventory'];
            $item['total_cost'] = $inventory_summary['total_cost'];
            if ($defaultCostRow !== null) {
                $item['unit_cost'] = (float)($defaultCostRow['unit_cost'] ?? 0);
                $item['unit_type'] = $defaultCostRow['unit_type_name'] ?? ($item['unit_type'] ?? 'Piece');
            }
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

            $check_transaction_table = $conn->query("SHOW TABLES LIKE 'motorpool_inventory_transactions'");
            if ($check_transaction_table && $check_transaction_table->num_rows > 0) {
                $inventory_transaction_columns = [];
                $inventory_transaction_columns_result = $conn->query("SHOW COLUMNS FROM motorpool_inventory_transactions");
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

                    $motorpool_inventory_transactions_query = "SELECT " . implode(", ", $inventory_select_parts) . "
                        FROM motorpool_inventory_transactions it
                        LEFT JOIN motorpool_inventory_items i ON i.item_id = it.item_id
                        LEFT JOIN motorpool_unit_types ut_tx ON ut_tx.unit_type_id = i.default_unit_type_id
                        LEFT JOIN purchase_orders po
                            ON " . ($reference_type_col ? "it.$reference_type_col = 'purchase_order'" : "1=0") . "
                            AND " . ($reference_id_col ? "it.$reference_id_col = po.po_id" : "1=0") . "
                        LEFT JOIN users u
                            ON " . ($created_by_col ? "it.$created_by_col = u.user_id" : "1=0") . "
                        WHERE it.item_id = ?" .
                        (($transaction_branch_col && !$view_all_branches && $branch_id > 0) ? " AND it.$transaction_branch_col = ?" : "") .
                        (($reference_type_col) ? " AND it.$reference_type_col IN ('purchase_order','production','return','return_merchandise','rmr','rejected_delivery')" : "");

                    $motorpool_inventory_transactions_stmt = $conn->prepare($motorpool_inventory_transactions_query);
                    if ($motorpool_inventory_transactions_stmt) {
                        if ($transaction_branch_col && !$view_all_branches && $branch_id > 0) {
                            $motorpool_inventory_transactions_stmt->bind_param("ii", $item_id, $branch_id);
                        } else {
                            $motorpool_inventory_transactions_stmt->bind_param("i", $item_id);
                        }
                        $motorpool_inventory_transactions_stmt->execute();
                        $motorpool_inventory_transactions_result = $motorpool_inventory_transactions_stmt->get_result();
                        while ($tx = $motorpool_inventory_transactions_result ? $motorpool_inventory_transactions_result->fetch_assoc() : null) {
                            $transactions[] = $tx;
                        }
                        $motorpool_inventory_transactions_stmt->close();
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
                        LEFT JOIN motorpool_inventory_items i ON i.item_id = soi.item_id
                        LEFT JOIN motorpool_unit_types ut_sales ON ut_sales.unit_type_id = i.default_unit_type_id
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
                'motorpool_unit_types' => $motorpool_unit_types,
                'images' => $images,
                'pricing_rows' => $pricing_rows,
                'pricing_history' => $pricing_history,
                'inventory_summary' => $inventory_summary,
                'transactions' => $transactions
            ]);
            exit;
        }
        
        // GET ITEM IMAGES
        elseif ($_POST['action'] === 'get_motorpool_item_images') {
            $item_id = (int)($_POST['item_id'] ?? 0);
            if ($item_id <= 0) throw new Exception('Invalid item ID');
            $images_query = "SELECT image_id, image_path, image_order, is_primary FROM motorpool_item_images WHERE item_id = ? ORDER BY image_order ASC";
            $images_stmt = $conn->prepare($images_query);
            if (!$images_stmt) throw new Exception('Database prepare error');
            $images_stmt->bind_param("i", $item_id);
            $images_stmt->execute();
            $images_result = $images_stmt->get_result();
            $images = $images_result->fetch_all(MYSQLI_ASSOC);
            if (empty($images) && !empty($item['product_image_url'])) {
                $images[] = ['image_id' => 0, 'image_path' => $item['product_image_url'], 'image_order' => 0, 'is_primary' => 1];
            } elseif (empty($images) && !empty($item['item_image'])) {
                $images[] = ['image_id' => 0, 'image_path' => $item['item_image'], 'image_order' => 0, 'is_primary' => 1];
            }
            echo json_encode(['success' => true, 'images' => $images]);
            exit;
        }
        
        // DELETE ITEM IMAGE
        elseif ($_POST['action'] === 'delete_item_image') {
            $image_id = (int)($_POST['image_id'] ?? 0);
            if ($image_id <= 0) throw new Exception('Invalid image ID');
            $img_query = "SELECT image_path FROM motorpool_item_images WHERE image_id = ?";
            $img_stmt = $conn->prepare($img_query);
            if (!$img_stmt) throw new Exception('Database prepare error');
            $img_stmt->bind_param("i", $image_id);
            $img_stmt->execute();
            $img_result = $img_stmt->get_result();
            $img_row = $img_result->fetch_assoc();
            if ($img_row) {
                $file_path = '../uploads/motorpool_inventory/' . $img_row['image_path'];
                if (file_exists($file_path)) unlink($file_path);
            }
            $delete_query = "DELETE FROM motorpool_item_images WHERE image_id = ?";
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
        elseif ($_POST['action'] === 'get_motorpool_item_unit_types') {
            $item_id = (int)($_POST['item_id'] ?? 0);
            if ($item_id <= 0) throw new Exception('Invalid item ID');
            $motorpool_unit_types_query = "SELECT ut.unit_type_name, MAX(COALESCE(iup.unit_quantity, 1)) as unit_quantity FROM motorpool_item_unit_pricing iup JOIN motorpool_unit_types ut ON iup.unit_type_id = ut.unit_type_id WHERE iup.item_id = ? GROUP BY ut.unit_type_id, ut.unit_type_name ORDER BY MAX(ut.is_default_uom) DESC, ut.unit_type_name ASC";
            $motorpool_unit_types_stmt = $conn->prepare($motorpool_unit_types_query);
            if (!$motorpool_unit_types_stmt) throw new Exception('Database prepare error');
            $motorpool_unit_types_stmt->bind_param('i', $item_id);
            $motorpool_unit_types_stmt->execute();
            $motorpool_unit_types_result = $motorpool_unit_types_stmt->get_result();
            $motorpool_unit_types = $motorpool_unit_types_result->fetch_all(MYSQLI_ASSOC);
            if (empty($motorpool_unit_types)) {
                $motorpool_unit_types[] = [
                    'unit_type_id' => (int)($item['default_unit_type_id'] ?? 0),
                    'unit_type_name' => !empty($item['unit_type']) ? $item['unit_type'] : 'Piece',
                    'uom_initial' => '',
                    'barcode' => $item['barcode'] ?? '',
                    'quantity_smallest_pack' => 1,
                    'is_default_uom' => 1,
                    'unit_status' => 'active',
                    'unit_quantity' => 1,
                    'current_inventory' => (float)($item['stock'] ?? $item['current_stock'] ?? 0),
                    'beginning_inventory' => (float)($item['stock'] ?? $item['current_stock'] ?? 0),
                    'as_of_date' => $item['created_at'] ?? null,
                    'unit_cost' => (float)($item['unit_cost'] ?? $item['unit_price'] ?? 0),
                    'average_cost' => (float)($item['unit_cost'] ?? $item['unit_price'] ?? 0),
                    'total_cost' => (float)($item['total_cost'] ?? 0),
                    'reorder_level' => (float)($item['reorder_level'] ?? 0)
                ];
            }
            echo json_encode(['success' => true, 'motorpool_unit_types' => $motorpool_unit_types]);
            exit;
        }
        
        // GET ALL UNIT TYPES (for dropdown)
        elseif ($_POST['action'] === 'get_motorpool_unit_types') {
            $motorpool_unit_types_query = "SELECT unit_type_id, unit_type_name, uom_initial, '' AS barcode, quantity_smallest_pack, is_default_uom, status FROM motorpool_unit_types WHERE status = 'active'";
            if ($items_branch_column_exists && !$view_all_branches && $branch_id > 0) {
                $motorpool_unit_types_query .= " AND (branch_id = $branch_id OR branch_id IS NULL)";
            }
            $motorpool_unit_types_query .= " ORDER BY is_default_uom DESC, unit_type_name ASC";
            $motorpool_unit_types_result = $conn->query($motorpool_unit_types_query);
            if (!$motorpool_unit_types_result) {
                throw new Exception('Failed to fetch unit types: ' . $conn->error);
            }
            $motorpool_unit_types = $motorpool_unit_types_result->fetch_all(MYSQLI_ASSOC);
            if (empty($motorpool_unit_types)) {
                $motorpool_unit_types[] = [
                    'unit_type_id' => (int)($item['default_unit_type_id'] ?? 0),
                    'unit_type_name' => !empty($item['unit_type']) ? $item['unit_type'] : 'Piece',
                    'uom_initial' => '',
                    'barcode' => $item['barcode'] ?? '',
                    'quantity_smallest_pack' => 1,
                    'is_default_uom' => 1,
                    'unit_status' => 'active',
                    'unit_quantity' => 1,
                    'current_inventory' => (float)($item['stock'] ?? $item['current_stock'] ?? 0),
                    'beginning_inventory' => (float)($item['stock'] ?? $item['current_stock'] ?? 0),
                    'as_of_date' => $item['created_at'] ?? null,
                    'unit_cost' => (float)($item['unit_cost'] ?? $item['unit_price'] ?? 0),
                    'average_cost' => (float)($item['unit_cost'] ?? $item['unit_price'] ?? 0),
                    'total_cost' => (float)($item['total_cost'] ?? 0),
                    'reorder_level' => (float)($item['reorder_level'] ?? 0)
                ];
            }
            echo json_encode(['success' => true, 'motorpool_unit_types' => $motorpool_unit_types]);
            exit;
        }
        
        // GET SUPPLIERS
        elseif ($_POST['action'] === 'get_suppliers') {
            // Motorpool inventory suppliers are saved as text in motorpool_inventory_items.supplier.
            // Do not return supplier_id = 0 as the select value because get_supplier_details will reject it.
            $suppliers = [];

            if (amgcTableExists($conn, 'suppliers')) {
                $real_supplier_sql = "SELECT supplier_id, supplier_name, supplier_code, contact_person, email, phone_number, CAST(supplier_id AS CHAR) AS supplier_key FROM suppliers WHERE COALESCE(status,'active') <> 'deleted'";
                if (!empty($suppliers_branch_condition)) {
                    $real_supplier_sql .= " $suppliers_branch_condition";
                }
                $real_supplier_sql .= " ORDER BY supplier_name ASC";
                if ($real_supplier_result = $conn->query($real_supplier_sql)) {
                    while ($row = $real_supplier_result->fetch_assoc()) {
                        $name = trim((string)($row['supplier_name'] ?? ''));
                        if ($name === '') continue;
                        $row['supplier_key'] = 'id:' . (int)$row['supplier_id'];
                        $suppliers[strtolower($name)] = $row;
                    }
                }
            }

            $inventory_supplier_sql = "SELECT COALESCE(NULLIF(TRIM(supplier),''), 'No Supplier') AS supplier_name, COUNT(*) AS item_count FROM motorpool_inventory_items WHERE COALESCE(status,'active') <> 'deleted' GROUP BY COALESCE(NULLIF(TRIM(supplier),''), 'No Supplier') ORDER BY supplier_name ASC";
            $inventory_supplier_result = $conn->query($inventory_supplier_sql);
            if (!$inventory_supplier_result) {
                throw new Exception('Failed to fetch suppliers: ' . $conn->error);
            }
            while ($row = $inventory_supplier_result->fetch_assoc()) {
                $name = trim((string)($row['supplier_name'] ?? 'No Supplier'));
                if ($name === '') $name = 'No Supplier';
                $key = strtolower($name);
                if (!isset($suppliers[$key])) {
                    $suppliers[$key] = [
                        'supplier_id' => 0,
                        'supplier_key' => 'name:' . rawurlencode($name),
                        'supplier_name' => $name,
                        'supplier_code' => '',
                        'contact_person' => '',
                        'email' => '',
                        'phone_number' => '',
                        'item_count' => (int)($row['item_count'] ?? 0)
                    ];
                } else {
                    $suppliers[$key]['item_count'] = (int)($row['item_count'] ?? 0);
                }
            }
            echo json_encode(['success' => true, 'suppliers' => array_values($suppliers)]);
            exit;
        }
        
        // GET SUPPLIER DETAILS
        elseif ($_POST['action'] === 'get_supplier_details') {
            $supplier_key = trim((string)($_POST['supplier_id'] ?? $_POST['supplier_key'] ?? ''));
            $posted_supplier_name = trim((string)($_POST['supplier_name'] ?? ''));
            $supplier_id = 0;
            $supplier_name = $posted_supplier_name;

            if (strpos($supplier_key, 'id:') === 0) {
                $supplier_id = (int)substr($supplier_key, 3);
            } elseif (strpos($supplier_key, 'name:') === 0) {
                $supplier_name = rawurldecode(substr($supplier_key, 5));
            } elseif (ctype_digit($supplier_key) && (int)$supplier_key > 0) {
                $supplier_id = (int)$supplier_key;
            } elseif ($supplier_name === '' && $supplier_key !== '') {
                $supplier_name = $supplier_key;
            }

            $supplier = null;
            $purchase_orders = [];

            if ($supplier_id > 0 && amgcTableExists($conn, 'suppliers')) {
                $supplier_query = "SELECT * FROM suppliers WHERE supplier_id = ? LIMIT 1";
                $supplier_stmt = $conn->prepare($supplier_query);
                if (!$supplier_stmt) throw new Exception('Database prepare error');
                $supplier_stmt->bind_param("i", $supplier_id);
                $supplier_stmt->execute();
                $supplier_result = $supplier_stmt->get_result();
                $supplier = $supplier_result->fetch_assoc();
                $supplier_stmt->close();
                if ($supplier) $supplier_name = trim((string)($supplier['supplier_name'] ?? $supplier_name));

                if ($supplier && amgcTableExists($conn, 'purchase_orders') && amgcTableExists($conn, 'purchase_order_items')) {
                    $po_query = "SELECT po.*, COUNT(poi.po_item_id) as total_items, SUM(poi.quantity_ordered) as total_quantity, b.branch_name FROM purchase_orders po LEFT JOIN purchase_order_items poi ON po.po_id = poi.po_id LEFT JOIN branches b ON po.branch_id = b.branch_id WHERE po.supplier_id = ? GROUP BY po.po_id ORDER BY po.created_at DESC";
                    $po_stmt = $conn->prepare($po_query);
                    if ($po_stmt) {
                        $po_stmt->bind_param("i", $supplier_id);
                        $po_stmt->execute();
                        $po_result = $po_stmt->get_result();
                        $purchase_orders = $po_result ? $po_result->fetch_all(MYSQLI_ASSOC) : [];
                        $po_stmt->close();
                        foreach ($purchase_orders as &$po) {
                            $items_query = "SELECT poi.*, i.item_name, i.item_code, COALESCE(ut_po.unit_type_name, '') AS unit_type FROM purchase_order_items poi JOIN motorpool_inventory_items i ON poi.item_id = i.item_id LEFT JOIN motorpool_unit_types ut_po ON ut_po.unit_type_id = i.default_unit_type_id WHERE poi.po_id = ?";
                            $items_stmt = $conn->prepare($items_query);
                            if ($items_stmt) {
                                $items_stmt->bind_param("i", $po['po_id']);
                                $items_stmt->execute();
                                $items_result = $items_stmt->get_result();
                                $po['items'] = $items_result ? $items_result->fetch_all(MYSQLI_ASSOC) : [];
                                $items_stmt->close();
                            } else {
                                $po['items'] = [];
                            }
                        }
                        unset($po);
                    }
                }
            }

            if (!$supplier) {
                if ($supplier_name === '') throw new Exception('Please select a valid supplier.');
                $supplier = [
                    'supplier_id' => 0,
                    'supplier_name' => $supplier_name,
                    'supplier_code' => '',
                    'contact_person' => '',
                    'email' => '',
                    'phone_number' => ''
                ];
            }

            // Inventory suppliers are text-based, so also return all current items under the selected supplier.
            $inventory_items = [];
            if ($supplier_name !== '') {
                $items_sql = "SELECT i.item_id, i.item_code, i.item_name, i.category, COALESCE(inv_summary.total_inventory, i.current_stock, i.stock, 0) AS quantity_ordered, COALESCE(i.unit_cost, i.unit_price, 0) AS unit_price, COALESCE(ut.unit_type_name, i.unit_type, 'Piece') AS unit_type FROM motorpool_inventory_items i LEFT JOIN motorpool_unit_types ut ON ut.unit_type_id = i.default_unit_type_id LEFT JOIN (SELECT item_id, SUM(COALESCE(current_inventory,0)) AS total_inventory FROM motorpool_item_unit_inventory GROUP BY item_id) inv_summary ON inv_summary.item_id = i.item_id WHERE COALESCE(i.status,'active') <> 'deleted' AND COALESCE(NULLIF(TRIM(i.supplier),''), 'No Supplier') = ? ORDER BY i.item_name ASC";
                $items_stmt = $conn->prepare($items_sql);
                if ($items_stmt) {
                    $items_stmt->bind_param('s', $supplier_name);
                    $items_stmt->execute();
                    $items_result = $items_stmt->get_result();
                    $inventory_items = $items_result ? $items_result->fetch_all(MYSQLI_ASSOC) : [];
                    $items_stmt->close();
                }
            }

            if (empty($purchase_orders) && !empty($inventory_items)) {
                $total_qty = 0;
                $total_amount = 0;
                foreach ($inventory_items as $it) {
                    $qty = (float)($it['quantity_ordered'] ?? 0);
                    $price = (float)($it['unit_price'] ?? 0);
                    $total_qty += $qty;
                    $total_amount += ($qty * $price);
                }
                $purchase_orders[] = [
                    'po_id' => 0,
                    'po_number' => 'Inventory Items',
                    'po_status' => 'active',
                    'order_date' => null,
                    'total_items' => count($inventory_items),
                    'total_quantity' => $total_qty,
                    'total_amount' => $total_amount,
                    'items' => $inventory_items
                ];
            }

            echo json_encode(['success' => true, 'supplier' => $supplier, 'purchase_orders' => $purchase_orders, 'supplier_items' => $inventory_items]);
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
                FROM motorpool_inventory_items i
                LEFT JOIN motorpool_unit_types ut ON i.default_unit_type_id = ut.unit_type_id
                LEFT JOIN motorpool_item_unit_inventory inv ON inv.item_id = i.item_id AND inv.unit_type_id = i.default_unit_type_id
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
            $items_count_query = "SELECT COUNT(*) as total_items FROM motorpool_inventory_items WHERE status = 'active'";
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
            $items_count_query = "SELECT COUNT(*) as total_items FROM motorpool_inventory_items WHERE status = 'active'";
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
                $check_query = "SELECT item_id FROM motorpool_inventory_items WHERE item_id = ? AND branch_id = ?";
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
                FROM motorpool_item_unit_inventory inv
                JOIN motorpool_unit_types ut ON inv.unit_type_id = ut.unit_type_id
                WHERE inv.item_id = ? AND (LOWER(TRIM(CONVERT(ut.unit_type_name USING utf8mb4) COLLATE utf8mb4_unicode_ci)) = LOWER(TRIM(CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci)) OR LOWER(TRIM(CONVERT(ut.uom_initial USING utf8mb4) COLLATE utf8mb4_unicode_ci)) = LOWER(TRIM(CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci)))" .
                ((amgcColumnExists($conn, 'motorpool_item_unit_inventory', 'branch_id') && !$view_all_branches && $branch_id > 0) ? " AND inv.branch_id = " . intval($branch_id) : "") .
                " ORDER BY CASE WHEN inv.current_inventory > 0 THEN 0 ELSE 1 END, inv.inventory_id ASC LIMIT 1";
            $inventory_stmt = $conn->prepare($inventory_query);
            if (!$inventory_stmt) throw new Exception('Database prepare error');
            $inventory_stmt->bind_param('iss', $item_id, $unit_type, $unit_type);
            $inventory_stmt->execute();
            $inventory_row = $inventory_stmt->get_result()->fetch_assoc();
            $inventory_stmt->close();

            if (!$inventory_row) {
                $inventory_query = "SELECT inv.inventory_id, inv.current_inventory, inv.unit_cost, inv.total_cost, ut.unit_type_id, ut.unit_type_name
                    FROM motorpool_inventory_items i
                    JOIN motorpool_item_unit_inventory inv ON inv.item_id = i.item_id AND inv.unit_type_id = i.default_unit_type_id
                    JOIN motorpool_unit_types ut ON inv.unit_type_id = ut.unit_type_id
                    WHERE i.item_id = ?" .
                    ((amgcColumnExists($conn, 'motorpool_item_unit_inventory', 'branch_id') && !$view_all_branches && $branch_id > 0) ? " AND inv.branch_id = " . intval($branch_id) : "") .
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
                FROM motorpool_item_unit_inventory inv
                JOIN motorpool_unit_types ut ON inv.unit_type_id = ut.unit_type_id
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
                             WHERE so_id = ? AND item_id = ? AND LOWER(TRIM(CONVERT(unit_type USING utf8mb4) COLLATE utf8mb4_unicode_ci)) = LOWER(TRIM(CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci))";
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

            $update_query = "UPDATE motorpool_item_unit_inventory
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
            if (amgcColumnExists($conn, 'motorpool_inventory_items', 'stock')) {
                $stock_stmt = $conn->prepare("UPDATE motorpool_inventory_items SET stock = ?, updated_at = NOW() WHERE item_id = ?");
                if ($stock_stmt) {
                    $stock_stmt->bind_param('di', $new_inventory, $item_id);
                    $stock_stmt->execute();
                    $stock_stmt->close();
                }
            }

            $check_transaction_table = $conn->query("SHOW TABLES LIKE 'motorpool_inventory_transactions'");
            if ($check_transaction_table && $check_transaction_table->num_rows > 0) {
                $dup_sql = "SELECT transaction_id FROM motorpool_inventory_transactions WHERE item_id = ? AND reference_type = 'sales_order' AND reference_id = ? LIMIT 1";
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
                    $trans_query = "INSERT INTO motorpool_inventory_transactions
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

// Receive Inventory already updates motorpool_item_unit_inventory directly.
// Do NOT auto-sync motorpool_inventory_transactions here, because it can add the same received quantity again on page load.
// syncReceivedInventoryTransactionsToUnitInventory($conn, (int)$branch_id, (int)$user_id, $items_branch_column_exists, (bool)$view_all_branches);

// Sync stock display from ledger before loading the inventory list.
// This fixes items like item_id 170 where 48 + 52 - 74 should remain 26, not 0.
// Disabled: do not recompute inventory on page load. This caused wrong stocks and duplicate rows.
// amgcSyncAllBranchStocksFromLedger($conn, (int)$branch_id, (bool)$view_all_branches);


// Make sure old Motorpool records have the supporting UOM/inventory rows required by the Branch Current Inventory functions.
// DISABLED FOR SPEED: this old compatibility sync loop was running through every item on every page load.
// Existing rows are now handled only during add/update/import/receive actions, not while simply opening the page.

// FETCH ALL ITEMS FROM motorpool_inventory_items TABLE
// Only show inventory from motorpool_item_unit_inventory table (which is synced from motorpool_inventory_transactions)
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
        CASE
            WHEN COALESCE(child_summary.child_qty, 0) > 0 THEN COALESCE(child_summary.child_qty, 0)
            ELSE COALESCE(inv_summary.total_inventory, i.current_stock, i.stock, 0)
        END as quantity_on_hand,
        COALESCE(ut.unit_type_name, '') as unit_type,
        i.unit_price,
        i.price_case,
        i.price_inner_pack,
        i.price_box,
        i.price_carton,
        i.reorder_level,
        i.status,
        COALESCE(i.points_eligible, 1) AS points_eligible,
        i.branch_id,
        i.created_at,
        i.updated_at,
        i.default_unit_type_id
    FROM motorpool_inventory_items i
    LEFT JOIN motorpool_unit_types ut ON i.default_unit_type_id = ut.unit_type_id
    LEFT JOIN (
        SELECT item_id, SUM(COALESCE(current_inventory, 0)) AS total_inventory
        FROM motorpool_item_unit_inventory
        WHERE 1=1
        GROUP BY item_id
    ) inv_summary ON inv_summary.item_id = i.item_id
    LEFT JOIN (
        SELECT
            c.parent_item_id,
            SUM(COALESCE(child_inv.total_inventory, c.current_stock, c.stock, 0)) AS child_qty,
            SUM(COALESCE(child_inv.total_cost, NULLIF(c.total_cost,0), COALESCE(c.current_stock,c.stock,0) * COALESCE(c.unit_cost,0))) AS child_cost
        FROM motorpool_inventory_items c
        LEFT JOIN (
            SELECT
                item_id,
                SUM(COALESCE(current_inventory,0)) AS total_inventory,
                SUM(CASE WHEN COALESCE(total_cost,0) > 0 THEN total_cost ELSE COALESCE(current_inventory, beginning_inventory, 0) * COALESCE(unit_cost, 0) END) AS total_cost
            FROM motorpool_item_unit_inventory
            WHERE COALESCE(status,'active') = 'active'
            GROUP BY item_id
        ) child_inv ON child_inv.item_id = c.item_id
        WHERE COALESCE(c.is_tire_serial_child,0) = 1
          AND COALESCE(c.status,'active') <> 'deleted'
        GROUP BY c.parent_item_id
    ) child_summary ON child_summary.parent_item_id = i.item_id
    WHERE 1=1
    AND COALESCE(i.is_tire_serial_child,0) = 0
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
        $quantity_on_hand = (float)($item['quantity_on_hand'] ?? 0);
        $default_unit_type = !empty($item['unit_type']) ? $item['unit_type'] : 'Piece';
        $item['default_uom_multiplier'] = 1;
        $item['default_uom_name'] = $default_unit_type;
        $item['stock_display'] = (floor($quantity_on_hand) == $quantity_on_hand ? number_format($quantity_on_hand, 0) : number_format($quantity_on_hand, 2)) . ' ' . htmlspecialchars($default_unit_type);
    }
    unset($item);
}

// Load Tire serial child rows for indented display under their parent Tire item.
$tire_serial_children_by_parent = [];
$tire_children_sql = "
    SELECT
        c.parent_item_id,
        c.item_id,
        c.item_code,
        c.barcode,
        c.item_name,
        c.description,
        c.category,
        COALESCE(NULLIF(TRIM(c.principal), ''), 'No Principal') AS principal,
        COALESCE(child_inv.total_inventory, c.current_stock, c.stock, 0) AS quantity_on_hand,
        COALESCE(ut.unit_type_name, c.unit_type, 'Piece') AS unit_type,
        COALESCE(c.unit_price, 0) AS unit_price,
        COALESCE(c.reorder_level, 0) AS reorder_level,
        COALESCE(c.status, 'active') AS status,
        COALESCE(c.points_eligible, 1) AS points_eligible,
        c.branch_id,
        c.default_unit_type_id,
        COALESCE(child_inv.total_cost, NULLIF(c.total_cost,0), COALESCE(c.current_stock,c.stock,0) * COALESCE(c.unit_cost,0)) AS total_cost
    FROM motorpool_inventory_items c
    LEFT JOIN motorpool_unit_types ut ON c.default_unit_type_id = ut.unit_type_id
    LEFT JOIN (
        SELECT
            item_id,
            SUM(COALESCE(current_inventory,0)) AS total_inventory,
            SUM(CASE WHEN COALESCE(total_cost,0) > 0 THEN total_cost ELSE COALESCE(current_inventory, beginning_inventory, 0) * COALESCE(unit_cost,0) END) AS total_cost
        FROM motorpool_item_unit_inventory
        WHERE COALESCE(status,'active') = 'active'
        GROUP BY item_id
    ) child_inv ON child_inv.item_id = c.item_id
    WHERE COALESCE(c.is_tire_serial_child,0) = 1
      AND COALESCE(c.status,'active') <> 'deleted'
      $items_branch_condition_c
    ORDER BY c.parent_item_id ASC, c.item_name ASC, c.item_id ASC
";
$tire_children_result = $conn->query($tire_children_sql);
if ($tire_children_result) {
    while ($child = $tire_children_result->fetch_assoc()) {
        $child_qty = (float)($child['quantity_on_hand'] ?? 0);
        $child_unit = !empty($child['unit_type']) ? $child['unit_type'] : 'Piece';
        $child['stock_display'] = (floor($child_qty) == $child_qty ? number_format($child_qty, 0) : number_format($child_qty, 2)) . ' ' . htmlspecialchars($child_unit);
        $tire_serial_children_by_parent[(int)$child['parent_item_id']][] = $child;
    }
}
$total_value = 0;
$received_total_value = 0;
$cogs_total_value = 0;

// FAST LOAD: skipped received/COGS sales-order calculations on page load.
// The inventory value card below still uses motorpool_inventory_items.total_cost.
$total_stock = array_sum(array_column($items, 'quantity_on_hand'));

// Motorpool Total Inventory Value must come from per-UoM inventory cost.
// This fixes imported items where unit_cost/total_cost is saved in motorpool_item_unit_inventory
// but motorpool_inventory_items.total_cost is still 0. The subquery groups by item+UoM
// first so duplicate compatibility rows do not multiply the value.
$total_value = 0;
$valueSql = "
    SELECT COALESCE(SUM(
        CASE
            WHEN child_values.child_cost IS NOT NULL THEN child_values.child_cost
            ELSE COALESCE(parent_values.parent_cost, 0)
        END
    ), 0) AS total_value
    FROM motorpool_inventory_items i
    LEFT JOIN (
        SELECT x.item_id, SUM(x.total_cost) AS parent_cost
        FROM (
            SELECT
                inv.item_id,
                inv.unit_type_id,
                MAX(CASE
                    WHEN COALESCE(inv.total_cost, 0) > 0 THEN COALESCE(inv.total_cost, 0)
                    ELSE COALESCE(inv.current_inventory, inv.beginning_inventory, 0) * COALESCE(inv.unit_cost, 0)
                END) AS total_cost
            FROM motorpool_item_unit_inventory inv
            WHERE COALESCE(inv.status, 'active') = 'active'
            GROUP BY inv.item_id, inv.unit_type_id
        ) x
        GROUP BY x.item_id
    ) parent_values ON parent_values.item_id = i.item_id
    LEFT JOIN (
        SELECT c.parent_item_id,
               SUM(COALESCE(NULLIF(c.total_cost,0), child_inv.child_cost, COALESCE(c.current_stock, c.stock, 0) * COALESCE(c.unit_cost, 0))) AS child_cost
        FROM motorpool_inventory_items c
        LEFT JOIN (
            SELECT y.item_id, SUM(y.total_cost) AS child_cost
            FROM (
                SELECT
                    inv.item_id,
                    inv.unit_type_id,
                    MAX(CASE
                        WHEN COALESCE(inv.total_cost, 0) > 0 THEN COALESCE(inv.total_cost, 0)
                        ELSE COALESCE(inv.current_inventory, inv.beginning_inventory, 0) * COALESCE(inv.unit_cost, 0)
                    END) AS total_cost
                FROM motorpool_item_unit_inventory inv
                WHERE COALESCE(inv.status, 'active') = 'active'
                GROUP BY inv.item_id, inv.unit_type_id
            ) y
            GROUP BY y.item_id
        ) child_inv ON child_inv.item_id = c.item_id
        WHERE COALESCE(c.is_tire_serial_child,0) = 1
          AND COALESCE(c.status,'active') = 'active'
        GROUP BY c.parent_item_id
    ) child_values ON child_values.parent_item_id = i.item_id
    WHERE COALESCE(i.status, 'active') = 'active'
      AND COALESCE(i.is_tire_serial_child,0) = 0
";
if ($items_branch_column_exists && !$view_all_branches && $branch_id > 0) {
    $valueSql .= " AND (i.branch_id = " . intval($branch_id) . " OR i.branch_id IS NULL OR i.branch_id = 0)";
}
$valueQuery = $conn->query($valueSql);
if ($valueQuery && ($valueRow = $valueQuery->fetch_assoc())) {
    $total_value = (float)($valueRow['total_value'] ?? 0);
}

$statInventoryValue = '₱' . number_format($total_value, 2);

/* RESTORE THIS */
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


function renderTireSerialChildRowsHtml(array $children, bool $showBranchColumn): void {
    foreach ($children as $child) {
        $childId = (int)($child['item_id'] ?? 0);
        $parentId = (int)($child['parent_item_id'] ?? 0);
        $qty = (float)($child['quantity_on_hand'] ?? 0);
        $stockDisplay = $child['stock_display'] ?? (number_format($qty, (floor($qty) == $qty ? 0 : 2)) . ' Piece');
        $status = (string)($child['status'] ?? 'active');
        $branchCell = $showBranchColumn ? '<td><span class="badge bg-info">Branch ' . htmlspecialchars((string)($child['branch_id'] ?? 'N/A')) . '</span></td>' : '';
        echo '<tr class="inventory-row tire-serial-child-row" data-id="' . $childId . '" data-parent-id="' . $parentId . '" data-code="' . htmlspecialchars((string)($child['item_code'] ?? '')) . '" data-barcode="' . htmlspecialchars((string)($child['barcode'] ?? '')) . '" data-name="' . htmlspecialchars((string)($child['item_name'] ?? '')) . '" data-category="' . htmlspecialchars((string)($child['category'] ?? 'Tire')) . '" data-principal="' . htmlspecialchars((string)($child['principal'] ?? 'No Principal')) . '" data-status="' . htmlspecialchars($status) . '" data-points-eligible="' . (int)($child['points_eligible'] ?? 1) . '" data-stock="' . htmlspecialchars((string)$qty) . '" data-reorder="' . htmlspecialchars((string)($child['reorder_level'] ?? 0)) . '" data-price="' . htmlspecialchars((string)($child['unit_price'] ?? 0)) . '" data-unit="' . htmlspecialchars((string)($child['unit_type'] ?? 'Piece')) . '" data-description="' . htmlspecialchars((string)($child['description'] ?? '')) . '" data-branch="' . htmlspecialchars((string)($child['branch_id'] ?? '')) . '">';
        echo '<td class="col-image tire-serial-empty-image"></td>';
        echo '<td><div class="tire-serial-indent"><span class="tire-serial-connector">└─</span><span class="tire-serial-name">' . htmlspecialchars((string)($child['item_name'] ?? '')) . '</span><small class="text-muted ms-2">Serial Item</small></div></td>';
        echo '<td>' . htmlspecialchars((string)($child['principal'] ?? 'No Principal')) . '</td>';
        echo $branchCell;
        echo '<td><span>' . $stockDisplay . '</span></td>';
        echo '<td class="col-status"><span class="badge ' . (strtolower($status) === 'active' ? 'bg-success' : 'bg-secondary') . '">' . htmlspecialchars($status) . '</span></td>';
        echo '<td class="col-actions"><div class="action-buttons"><button class="btn-action btn-view" onclick="event.stopPropagation(); openTireProfile(' . $childId . ')" title="Tire Profile"><i class="bi bi-life-preserver"></i></button><button class="btn-action btn-edit" onclick="event.stopPropagation(); editItem(' . $childId . ')" title="Edit Serial Item"><i class="bi bi-pencil"></i></button><button class="btn-action btn-delete" onclick="event.stopPropagation(); deleteTireSerialChild(' . $parentId . ', ' . $childId . ')" title="Delete Serial Item"><i class="bi bi-trash"></i></button></div></td>';
        echo '</tr>';
    }
}

$principalOptions = [];
$principal_query = "SELECT DISTINCT TRIM(i.principal) AS principal FROM motorpool_inventory_items i WHERE i.status <> 'deleted' AND i.principal IS NOT NULL AND TRIM(i.principal) <> '' $items_branch_condition ORDER BY principal ASC";
$principal_result = $conn->query($principal_query);
if ($principal_result) {
    while ($principal_row = $principal_result->fetch_assoc()) {
        if (!empty($principal_row['principal'])) {
            $principalOptions[] = $principal_row['principal'];
        }
    }
}


$itemIncomeAccounts = [];
$itemCogsAccounts = [];
$itemAssetAccounts = [];
$chartAccountBranchCondition = "";
if (!$view_all_branches && $branch_id > 0) {
    $chartAccountBranchCondition = " AND (branch_id = " . intval($branch_id) . " OR branch_id = 0)";
}
$chartAccountQuery = "SELECT account_id, account_code, account_title, account_type FROM chart_of_accounts WHERE status = 'active' $chartAccountBranchCondition ORDER BY account_type ASC, account_title ASC";
$chartAccountResult = $conn->query($chartAccountQuery);
if ($chartAccountResult) {
    while ($accountRow = $chartAccountResult->fetch_assoc()) {
        $accountType = trim((string)($accountRow['account_type'] ?? ''));
        $accountTitle = trim((string)($accountRow['account_title'] ?? ''));
        $accountCode = trim((string)($accountRow['account_code'] ?? ''));
        $label = ($accountCode !== '' ? $accountCode . ' · ' : '') . $accountTitle;
        $accountOption = [
            'id' => (int)$accountRow['account_id'],
            'label' => $label,
            'type' => $accountType
        ];

        if ($accountType === 'Income') {
            $itemIncomeAccounts[] = $accountOption;
        }
        if ($accountType === 'Cost of Goods Sold') {
            $itemCogsAccounts[] = $accountOption;
        }
        if ($accountType === 'Other Current Asset') {
            $itemAssetAccounts[] = $accountOption;
        }
    }
}


$supplier_items_query = "
    SELECT
        0 AS supplier_id,
        COALESCE(NULLIF(TRIM(i.supplier), ''), 'No Supplier') AS supplier_name,
        i.item_id,
        i.item_code,
        i.barcode,
        i.item_name,
        i.description,
        i.category,
        COALESCE(NULLIF(TRIM(i.principal), ''), COALESCE(NULLIF(TRIM(i.supplier), ''), 'No Supplier')) as principal,
        COALESCE(inv_summary.total_inventory, COALESCE(i.current_stock, i.stock, 0)) as quantity_on_hand,
        COALESCE(ut.unit_type_name, i.unit_type, '') as unit_type,
        i.reorder_level,
        i.status,
        i.branch_id
    FROM motorpool_inventory_items i
    LEFT JOIN motorpool_unit_types ut ON i.default_unit_type_id = ut.unit_type_id
    LEFT JOIN (
        SELECT item_id, SUM(COALESCE(current_inventory, 0)) AS total_inventory
        FROM motorpool_item_unit_inventory
        GROUP BY item_id
    ) inv_summary ON inv_summary.item_id = i.item_id
    WHERE COALESCE(i.status, 'active') <> 'deleted'
  AND COALESCE(i.is_tire_serial_child,0) = 0
";
if ($items_branch_column_exists && !$view_all_branches && $branch_id > 0) {
    $supplier_items_query .= " AND (i.branch_id = " . intval($branch_id) . " OR i.branch_id IS NULL OR i.branch_id = 0)";
}
$supplier_items_query .= " ORDER BY supplier_name, i.item_name";
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
        $item['stock_display'] = number_format((float)$quantity_on_hand, (floor((float)$quantity_on_hand)==(float)$quantity_on_hand ? 0 : 2)) . ' ' . htmlspecialchars($default_unit_type);
    }
    unset($item);
    $items_by_supplier = [];
    foreach ($supplier_items as $item) {
        $supplier = $item['supplier_name'] ?? 'No Supplier';
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
// Calculate low stock and out of stock counts using motorpool_item_unit_inventory
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
    $item_id = (int)$item_id;
    $paths = [];

    // Primary source: motorpool_item_images gallery table.
    $images_query = "SELECT image_path FROM motorpool_item_images WHERE item_id = ? ORDER BY is_primary DESC, image_order ASC, image_id ASC LIMIT 1";
    $stmt = $conn->prepare($images_query);
    if ($stmt) {
        $stmt->bind_param("i", $item_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            if (!empty($row['image_path'])) $paths[] = (string)$row['image_path'];
        }
        $stmt->close();
    }

    // Fallback source: legacy columns used by older motorpool inventory records.
    $item_query = "SELECT product_image_url, item_image FROM motorpool_inventory_items WHERE item_id = ? LIMIT 1";
    $stmt2 = $conn->prepare($item_query);
    if ($stmt2) {
        $stmt2->bind_param("i", $item_id);
        $stmt2->execute();
        $res2 = $stmt2->get_result();
        if ($item = $res2->fetch_assoc()) {
            if (!empty($item['product_image_url'])) $paths[] = (string)$item['product_image_url'];
            if (!empty($item['item_image'])) $paths[] = (string)$item['item_image'];
        }
        $stmt2->close();
    }

    $paths = array_values(array_unique(array_filter(array_map('trim', $paths))));
    if (empty($paths)) {
        return '<i class="bi bi-image text-muted"></i>';
    }

    $fallbacks = [];
    foreach ($paths as $path) {
        $clean = str_replace('\\', '/', $path);
        $clean = ltrim($clean, '/');
        if (preg_match('/^https?:\/\//i', $clean)) {
            $fallbacks[] = $clean;
        } else {
            $base = basename($clean);
            $fallbacks[] = '../uploads/motorpool_inventory/' . $base;
            $fallbacks[] = '../uploads/items/' . $base;
            $fallbacks[] = '../uploads/' . $base;
            $fallbacks[] = $clean;
            $fallbacks[] = '../' . $clean;
        }
    }
    $fallbacks = array_values(array_unique($fallbacks));
    $first = htmlspecialchars($fallbacks[0], ENT_QUOTES, 'UTF-8');
    $json = htmlspecialchars(json_encode($fallbacks), ENT_QUOTES, 'UTF-8');
    return '<img src="' . $first . '" data-fallbacks="' . $json . '" data-fallback-index="0" alt="Item Image" style="display:block;width:60px;height:60px;object-fit:cover;border-radius:8px;" onerror="motorpoolImageFallback(this)">';
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

    $show_system_task_modal = false;
} catch (Throwable $e) {
    error_log('System task table modal error: ' . $e->getMessage());
    $system_tasks = [];
    $show_system_task_modal = false;
}
// ================= END SYSTEM-WIDE TASK TABLE MODAL =================
$check_barcode_column = $conn->query("SHOW COLUMNS FROM motorpool_inventory_items LIKE 'barcode'");
if (!$check_barcode_column || $check_barcode_column->num_rows == 0) {
    $conn->query("
        ALTER TABLE motorpool_inventory_items
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
    <title>Motorpool Inventory - Motorpool</title>
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
        .category-group-header td { background: #f3f8f3 !important; border-top: 2px solid #d9ead9 !important; border-bottom: 1px solid #d9ead9 !important; color: #052A47; padding: 12px 16px !important; }
        .category-group-title { display: flex; align-items: center; justify-content: space-between; gap: 12px; font-weight: 700; }
        .category-group-name { display: inline-flex; align-items: center; gap: 8px; }
        .category-group-name i { color: #047857; }
        .category-group-count { background: #e8f7ea; color: #047857; border: 1px solid #bdecc3; border-radius: 999px; padding: 3px 10px; font-size: 0.75rem; font-weight: 700; white-space: nowrap; }
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
    .table-container tbody tr.category-group-header:not([style*="display: none"]):not([style*="display:none"]) {
        display: table-row !important;
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
/* Make all table rows clickable */
.table-container tbody tr {
    cursor: pointer;
    transition: background-color 0.2s ease, transform 0.2s ease;
}

/* Desktop hover effect */
@media (min-width: 769px) {
    .table-container tbody tr:hover {
        background-color: rgba(46, 125, 50, 0.08) !important;
    }
    
    /* Keep edit/delete buttons using the default cursor */
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

/* Keep edit and delete buttons from changing to the pointer cursor */
.btn-action {
    cursor: pointer !important;
}

/* Optional: keep the status toggle clickable */
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


/* Fix SweetAlert input typing inside Add Item modal */
#itemModal .swal2-container {
    z-index: 20000 !important;
}
#itemModal .swal2-popup input.form-control {
    pointer-events: auto !important;
    user-select: text !important;
}




.tire-serial-child-row { background: #f7fffb !important; }
.tire-serial-child-row td { border-top: 1px dashed #b9efd0 !important; font-size: 0.94rem; }
.tire-serial-empty-image { width: 80px; }
.tire-serial-indent { padding-left: 34px; display: flex; align-items: center; gap: 8px; }
.tire-serial-connector { color: #0f8f5f; font-weight: 700; }
.tire-serial-name { font-weight: 700; color: #07325a; }
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
/* ===== FIX DROPDOWN PARENT ACTIVE COLOR - DO NOT SHOW GREEN ===== */

/* When the sidebar is expanded, do not highlight the dropdown parent */
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

/* Do not add the active class to the dropdown parent */
.sidebar .dropdown-nav > .nav-link.active {
    background: transparent !important;
    color: #9ca3af !important;
}

.sidebar .dropdown-nav > .nav-link.active i {
    color: #9ca3af !important;
}

/* When there is an active child inside, do not highlight the parent in expanded mode */
.sidebar:not(.collapsed) .dropdown-nav:has(.nav-link.active) > .nav-link {
    background: transparent !important;
    color: #9ca3af !important;
}

.sidebar:not(.collapsed) .dropdown-nav:has(.nav-link.active) > .nav-link i {
    color: #9ca3af !important;
}

/* When the sidebar is collapsed, only then show the parent in green */
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

/* Nested Add Account modal from Add Item. Keeps child backdrop from covering the parent after close. */
#itemLinkedAccountModal { z-index: 1095 !important; }
.modal-backdrop.item-linked-account-backdrop { z-index: 1090 !important; opacity: 0.62 !important; }
.modal-backdrop.item-parent-backdrop { z-index: 1040 !important; opacity: 0.5 !important; }

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

/* Keep input and button borders aligned */
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
.mobile-nav {
    position: fixed;
    bottom: 0;
    left: 0;
    right: 0;
    background: #ffffff;
    border-top: 1px solid #e5e7eb;
    box-shadow: 0 -2px 12px rgba(0,0,0,.08);
    z-index: 9999;
    display: none;
    padding: 8px 0 12px;
    overflow: visible !important;
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

.mobile-nav .nav::-webkit-scrollbar { display: none; }
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
    padding: 6px 10px;
    color: #9ca3af;
    font-size: .7rem;
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
    line-height: 1;
    margin: 0;
}

.mobile-nav .nav-link span {
    font-size: .65rem;
    font-weight: 600;
}

.mobile-nav .nav-link.active {
    color: #059669;
    background: rgba(5, 150, 105, .1);
}

.mobile-nav .more-dropdown {
    position: absolute;
    bottom: calc(100% + 8px);
    left: 50%;
    transform: translateX(-50%) translateY(8px);
    background: #ffffff;
    border-radius: 12px;
    box-shadow: 0 4px 20px rgba(0,0,0,.15);
    border: 1px solid #e5e7eb;
    min-width: 205px;
    z-index: 10000;
    display: none !important;
    opacity: 0;
    visibility: hidden;
    pointer-events: none;
    transition: opacity .2s ease, transform .2s ease;
    overflow: hidden;
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
    background: #ffffff;
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
    border-bottom: 1px solid #f3f4f6;
    font-size: .85rem;
    background: #ffffff;
    width: 100%;
    text-align: left;
    cursor: pointer;
}

.mobile-nav .dropdown-item:last-child { border-bottom: none; }
.mobile-nav .dropdown-item:hover { background: #f9fafb; }
.mobile-nav .dropdown-item.active {
    background: rgba(5, 150, 105, .1);
    color: #059669;
}
.mobile-nav .dropdown-item i {
    width: 20px;
    font-size: 1rem;
    color: #6b7280;
}
.mobile-nav .dropdown-item.active i { color: #059669; }
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

            <span class="nav-text">Motorpool</span>
        </h3>
    </div>

    <div class="sidebar-content">
        <div class="sidebar-menu">
            <ul class="nav flex-column">
                <!-- Dashboard -->
                <li class="nav-item">
                    <a class="nav-link active" href="motorpool_inventory.php">
                        <i class="bi bi-box-seam"></i>
                        <span class="nav-text">Current Inventory</span>
                    </a>
                </li>
                <!-- Vendor Dropdown -->
                <li class="nav-item dropdown-nav">
                    <a class="nav-link" href="#" onclick="toggleSidebarDropdown(event, 'supplierMenu')">
                        <i class="bi bi-building"></i>
                        <span class="nav-text">Vendor</span>
                        <i class="bi bi-chevron-down dropdown-arrow"></i>
                    </a>

                    <div class="collapse" id="supplierMenu">
                        <ul class="nav flex-column ps-4">
                            <li class="nav-item">
                                <a class="nav-link" href="enterbills.php">
                                    <i class="bi bi-file-earmark-text"></i>
                                    <span class="nav-text">Enter Bills</span>
                                </a>
                            </li>

                            <li class="nav-item">
                                <a class="nav-link" href="paybills.php">
                                    <i class="bi bi-currency-dollar"></i>
                                    <span class="nav-text">Pay Bills</span>
                                </a>
                            </li>

                            <li class="nav-item">
                                <a class="nav-link" href="vendors.php">
                                    <i class="bi bi-shop"></i>
                                    <span class="nav-text">Vendor List</span>
                                </a>
                            </li>
                        </ul>
                    </div>
                </li>

                <!-- Customer Dropdown -->
                <li class="nav-item dropdown-nav">
                    <a class="nav-link" href="#" onclick="toggleSidebarDropdown(event, 'customerMenu')">
                        <i class="bi bi-people-fill"></i>
                        <span class="nav-text">Customers</span>
                        <i class="bi bi-chevron-down dropdown-arrow"></i>
                    </a>

                    <div class="collapse" id="customerMenu">
                        <ul class="nav flex-column ps-4">
                            <li class="nav-item">
                                <a class="nav-link" href="orderproduct.php">
                                    <i class="bi bi-receipt"></i>
                                    <span class="nav-text">Create Invoice</span>
                                </a>
                            </li>

                            <li class="nav-item">
                                <a class="nav-link" href="collections.php">
                                    <i class="bi bi-cash-stack"></i>
                                    <span class="nav-text">Receive Payment</span>
                                </a>
                            </li>

                            <li class="nav-item">
                                <a class="nav-link" href="customer_list.php">
                                    <i class="bi bi-person-badge"></i>
                                    <span class="nav-text">Customer List</span>
                                </a>
                            </li>
                        </ul>
                    </div>
                </li>

                <!-- Employees Dropdown -->
                <li class="nav-item dropdown-nav">
                    <a class="nav-link" href="#" onclick="toggleSidebarDropdown(event, 'employeesMenu')">
                        <i class="bi bi-briefcase"></i>
                        <span class="nav-text">Employees</span>
                        <i class="bi bi-chevron-down dropdown-arrow"></i>
                    </a>

                    <div class="collapse" id="employeesMenu">
                        <ul class="nav flex-column ps-4">
                            <li class="nav-item">
                                <a class="nav-link" href="employeelist.php">
                                    <i class="bi bi-person-badge"></i>
                                    <span class="nav-text">Employee List</span>
                                </a>
                            </li>

                            <li class="nav-item">
                                <a class="nav-link" href="employee.php">
                                    <i class="bi bi-clock-history"></i>
                                    <span class="nav-text">Enter Time</span>
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
                                    <i class="bi bi-bank"></i>
                                    <span class="nav-text">Record Deposit</span>
                                </a>
                            </li>

                            <li class="nav-item">
                                <a class="nav-link" href="withdrawal.php">
                                    <i class="bi bi-journal-check"></i>
                                    <span class="nav-text">Write Checks</span>
                                </a>
                            </li>
                            
                            <li class="nav-item active">
                                <a class="nav-link" href="transferfunds.php">
                                    <i class="bi bi-arrow-left-right"></i>
                                    <span class="nav-text">Transfer Funds</span>
                                </a>
                            </li>
                        </ul>
                    </div>
                </li>

                <!-- Company Dropdown -->
                <li class="nav-item dropdown-nav">
                    <a class="nav-link" href="#" onclick="toggleSidebarDropdown(event, 'warehouseMenu')">
                        <i class="bi bi-building"></i>
                        <span class="nav-text">Company</span>
                        <i class="bi bi-chevron-down dropdown-arrow"></i>
                    </a>

                    <div class="collapse" id="warehouseMenu">
                        <ul class="nav flex-column ps-4">

                            <li class="nav-item">
                                <a class="nav-link" href="chartofaccounts.php">
                                    <i class="bi bi-graph-up"></i>
                                    <span class="nav-text">Chart of Accounts</span>
                                </a>
                            </li>

                            <li class="nav-item">
                                <a class="nav-link" href="request_handler.php">
                                    <i class="bi bi-clipboard"></i>
                                    <span class="nav-text">RIS Monitoring</span>
                                </a>
                            </li>

                            <li class="nav-item">
                                <a class="nav-link" href="motorpool.php">
                                    <i class="bi bi-truck"></i>
                                    <span class="nav-text">Vehicle Profile</span>
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
                <!-- Accounting Dropdown -->
                <li class="nav-item dropdown-nav">
                    <a class="nav-link" href="#" onclick="toggleSidebarDropdown(event, 'accountingMenu')">
                        <i class="bi bi-graph-up"></i>
                        <span class="nav-text">Accounting</span>
                        <i class="bi bi-chevron-down dropdown-arrow"></i>
                    </a>

                    <div class="collapse" id="accountingMenu">
                        <ul class="nav flex-column ps-4">
                            <li class="nav-item">
                                <a class="nav-link" href="journal_entries.php">
                                    <i class="bi bi-journal"></i>
                                    <span class="nav-text">Journal Entries</span>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="batch_transaction.php">
                                    <i class="bi bi-collection"></i>
                                    <span class="nav-text">Batch Transaction</span>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="item_adjustment.php">
                                    <i class="bi bi-sliders"></i>
                                    <span class="nav-text">Item Adjusment</span>
                                </a>
                            </li>
                        </ul>
                    </div>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="it_request.php">
                        <i class="bi bi-headset"></i>
                        <span class="nav-text">IT Requests</span>
                    </a>
                </li>
            </ul>
        </div>
    </div>

    <div class="sidebar-footer">
        <div class="user-profile-sidebar">
            <div class="user-avatar-sidebar">
                <?php echo htmlspecialchars($user_initials); ?>
            </div>

            <div class="user-details-sidebar">
                <span class="user-name-sidebar">
                    <?php echo htmlspecialchars($user_name); ?>
                </span>

                <span class="user-role-sidebar">
                    <?php echo htmlspecialchars(ucfirst($user_role)); ?>
                </span>
            </div>
        </div>

        <button class="logout-btn-sidebar" onclick="logout()">
            <i class="bi bi-box-arrow-right"></i>
            <span class="logout-text">Logout</span>
        </button>
    </div>
</div>

    <div class="main-content" id="mainContent">
            <div id="dashboardContent" class="page-content active">
                <div class="navbar-top">
                    <button class="mobile-toggle-btn" id="mobileToggleBtn"><i class="bi bi-list"></i></button>
                    <div class="page-title">
                        <h2>Current Inventory</h2>
                        <p id="dashboardSubtitle">Manage inventory, pricing and stock levels</p>
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
                            <div class="table-container"><table class="table custom-table compact-table"><thead><th class="col-image">Image</th><th>Item Name</th><th>Principal</th><?php if ($items_branch_column_exists && $view_all_branches): ?><th>Branch</th><?php endif; ?><th>Stock</th><th class="col-status">Active</th><th class="col-actions">Actions</th> </thead><tbody>
                            <?php foreach ($items_by_category as $category => $category_items): $category_label = trim((string)$category) !== '' ? $category : 'Uncategorized'; ?>
                            <tr class="category-group-header" data-category-group="<?= htmlspecialchars($category_label, ENT_QUOTES) ?>">
                                <td colspan="<?= ($items_branch_column_exists && $view_all_branches) ? 7 : 6; ?>">
                                    <div class="category-group-title">
                                        <span class="category-group-name"><i class="bi bi-folder2-open"></i><?= htmlspecialchars($category_label) ?></span>
                                        <span class="category-group-count"><?= count($category_items) ?> Item<?= count($category_items) > 1 ? 's' : '' ?></span>
                                    </div>
                                </td>
                            </tr>
                            <?php foreach ($category_items as $item): $stock_status = getStockStatus($item['quantity_on_hand'], $item['reorder_level']); ?>
                            <tr class="inventory-row" data-id="<?= $item['item_id'] ?>" data-code="<?= htmlspecialchars($item['item_code']) ?>" data-barcode="<?= htmlspecialchars($item['barcode'] ?? '') ?>" data-name="<?= htmlspecialchars($item['item_name']) ?>" data-category="<?= htmlspecialchars($item['category'] ?? 'Uncategorized') ?>" data-principal="<?= htmlspecialchars($item['principal'] ?? 'No Principal') ?>" data-status="<?= $item['status'] ?>" data-points-eligible="<?= (int)($item['points_eligible'] ?? 1) ?>" data-stock="<?= $item['quantity_on_hand'] ?>" data-reorder="<?= $item['reorder_level'] ?>" data-price="<?= $item['unit_price'] ?>" data-unit="<?= $item['unit_type'] ?>" data-description="<?= htmlspecialchars($item['description'] ?? '') ?>" data-branch="<?= $item['branch_id'] ?? '' ?>">
                                <td class="col-image"><div class="item-thumbnail" data-item-id="<?= $item['item_id'] ?>"><?= getItemImagesHtml((int)$item['item_id']) ?></div></td>
                                <td><strong><?= htmlspecialchars($item['item_name']) ?></strong></td>
                                <td><?= htmlspecialchars($item['principal'] ?? 'No Principal') ?></td>
                                <?php if ($items_branch_column_exists && $view_all_branches): ?><td><span class="badge bg-info">Branch <?= $item['branch_id'] ?? 'N/A' ?></span></td><?php endif; ?>
                                <td><span class="<?= $item['quantity_on_hand'] <= $item['reorder_level'] ? 'text-danger fw-bold' : '' ?>"><?= $item['stock_display'] ?></span><span class="badge <?= $stock_status['class'] ?> ms-1"><?= $stock_status['label'] ?></span></td>
                                <td class="col-status"><div class="status-toggle"><label class="toggle-switch"><input type="checkbox" class="status-checkbox" data-id="<?= $item['item_id'] ?>" <?= $item['status'] === 'active' ? 'checked' : '' ?> onchange="toggleItemStatus(<?= $item['item_id'] ?>, this)"><span class="toggle-slider"></span></label></div></td>
                                <td class="col-actions"><div class="action-buttons"><?php if (motorpoolIsTireCategoryValue($item['category'] ?? '')): ?><button class="btn-action btn-view" onclick="event.stopPropagation(); openTireSerialItems(<?= $item['item_id'] ?>)" title="Tire Serial Items"><i class="bi bi-list-check"></i></button><?php endif; ?><button class="btn-action btn-edit" onclick="event.stopPropagation(); editItem(<?= $item['item_id'] ?>)" title="Edit"><i class="bi bi-pencil"></i></button><button class="btn-action btn-delete" onclick="event.stopPropagation(); deleteItem(<?= $item['item_id'] ?>)" title="Delete"><i class="bi bi-trash"></i></button></div></td>
                            </tr>
                            <?php if (!empty($tire_serial_children_by_parent[(int)$item['item_id']])) { renderTireSerialChildRowsHtml($tire_serial_children_by_parent[(int)$item['item_id']], ($items_branch_column_exists && $view_all_branches)); } ?>
                            <?php endforeach; ?>
                            <?php endforeach; ?>
                            </tbody></table></div>
                            <div class="no-items-message" id="no-items-cat-tab-all" style="display: none;"><i class="bi bi-inbox"></i><h5>No items match your filters</h5><p class="text-muted">Try adjusting your search or filter criteria</p><button class="btn btn-sm btn-outline-primary" onclick="clearAllFilters()"><i class="bi bi-x-circle"></i> Clear Filters</button></div>
                        </div>
                        
                        <?php foreach ($items_by_category as $category => $category_items): $tab_id = 'cat-tab-' . preg_replace('/[^a-z0-9]/i', '-', strtolower($category)); ?>
                        <div id="<?= $tab_id ?>" class="tab-content" data-category="<?= htmlspecialchars($category) ?>">
                            <div class="table-container"><table class="table custom-table compact-table"><thead><th class="col-image">Image</th><th>Item Name</th><th>Principal</th><?php if ($items_branch_column_exists && $view_all_branches): ?><th>Branch</th><?php endif; ?><th>Stock</th><th class="col-status">Active</th><th class="col-actions">Actions</th> </thead><tbody>
                            <?php $category_label = trim((string)$category) !== '' ? $category : 'Uncategorized'; ?>
                            <tr class="category-group-header" data-category-group="<?= htmlspecialchars($category_label, ENT_QUOTES) ?>">
                                <td colspan="<?= ($items_branch_column_exists && $view_all_branches) ? 7 : 6; ?>">
                                    <div class="category-group-title">
                                        <span class="category-group-name"><i class="bi bi-folder2-open"></i><?= htmlspecialchars($category_label) ?></span>
                                        <span class="category-group-count"><?= count($category_items) ?> Item<?= count($category_items) > 1 ? 's' : '' ?></span>
                                    </div>
                                </td>
                            </tr>
                            <?php foreach ($category_items as $item): $stock_status = getStockStatus($item['quantity_on_hand'], $item['reorder_level']); ?>
                            <tr class="inventory-row" data-id="<?= $item['item_id'] ?>" data-code="<?= htmlspecialchars($item['item_code']) ?>" data-barcode="<?= htmlspecialchars($item['barcode'] ?? '') ?>" data-name="<?= htmlspecialchars($item['item_name']) ?>" data-category="<?= htmlspecialchars($item['category'] ?? '') ?>" data-principal="<?= htmlspecialchars($item['principal'] ?? 'No Principal') ?>" data-status="<?= $item['status'] ?>" data-points-eligible="<?= (int)($item['points_eligible'] ?? 1) ?>" data-stock="<?= $item['quantity_on_hand'] ?>" data-reorder="<?= $item['reorder_level'] ?>" data-price="<?= $item['unit_price'] ?>" data-unit="<?= $item['unit_type'] ?>" data-description="<?= htmlspecialchars($item['description'] ?? '') ?>" data-branch="<?= $item['branch_id'] ?? '' ?>">
                                <td class="col-image"><div class="item-thumbnail" data-item-id="<?= $item['item_id'] ?>"><?= getItemImagesHtml((int)$item['item_id']) ?></div></td>
                                <td><strong><?= htmlspecialchars($item['item_name']) ?></strong></td>
                                <td><?= htmlspecialchars($item['principal'] ?? 'No Principal') ?></td>
                                <?php if ($items_branch_column_exists && $view_all_branches): ?><td><span class="badge bg-info">Branch <?= $item['branch_id'] ?? 'N/A' ?></span></td><?php endif; ?>
                                <td><span class="<?= $item['quantity_on_hand'] <= $item['reorder_level'] ? 'text-danger fw-bold' : '' ?>"><?= $item['stock_display'] ?></span><span class="badge <?= $stock_status['class'] ?> ms-1"><?= $stock_status['label'] ?></span></td>
                                <td class="col-status"><div class="status-toggle"><label class="toggle-switch"><input type="checkbox" class="status-checkbox" data-id="<?= $item['item_id'] ?>" <?= $item['status'] === 'active' ? 'checked' : '' ?> onchange="toggleItemStatus(<?= $item['item_id'] ?>, this)"><span class="toggle-slider"></span></label></div></td>
                                <td class="col-actions"><div class="action-buttons"><?php if (motorpoolIsTireCategoryValue($item['category'] ?? '')): ?><button class="btn-action btn-view" onclick="event.stopPropagation(); openTireSerialItems(<?= $item['item_id'] ?>)" title="Tire Serial Items"><i class="bi bi-list-check"></i></button><?php endif; ?><button class="btn-action btn-edit" onclick="event.stopPropagation(); editItem(<?= $item['item_id'] ?>)" title="Edit"><i class="bi bi-pencil"></i></button><button class="btn-action btn-delete" onclick="event.stopPropagation(); deleteItem(<?= $item['item_id'] ?>)" title="Delete"><i class="bi bi-trash"></i></button></div></td>
                            </tr>
                            <?php if (!empty($tire_serial_children_by_parent[(int)$item['item_id']])) { renderTireSerialChildRowsHtml($tire_serial_children_by_parent[(int)$item['item_id']], ($items_branch_column_exists && $view_all_branches)); } ?>
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
                            <tr class="inventory-row" data-id="<?= $item['item_id'] ?>" data-code="<?= htmlspecialchars($item['item_code']) ?>" data-barcode="<?= htmlspecialchars($item['barcode'] ?? '') ?>" data-name="<?= htmlspecialchars($item['item_name']) ?>" data-category="<?= htmlspecialchars($item['category'] ?? '') ?>" data-principal="<?= htmlspecialchars($item['principal'] ?? 'No Principal') ?>" data-status="<?= $item['status'] ?>" data-points-eligible="<?= (int)($item['points_eligible'] ?? 1) ?>" data-stock="<?= $item['quantity_on_hand'] ?>" data-reorder="<?= $item['reorder_level'] ?>" data-price="0" data-unit="<?= $item['unit_type'] ?>" data-branch="<?= $item['branch_id'] ?? '' ?>">
                                <td class="col-image"><div class="item-thumbnail" data-item-id="<?= $item['item_id'] ?>"><?= getItemImagesHtml((int)$item['item_id']) ?></div></td>
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
                            <tr class="inventory-row" data-id="<?= $item['item_id'] ?>" data-code="<?= htmlspecialchars($item['item_code']) ?>" data-barcode="<?= htmlspecialchars($item['barcode'] ?? '') ?>" data-name="<?= htmlspecialchars($item['item_name']) ?>" data-category="<?= htmlspecialchars($item['category'] ?? '') ?>" data-principal="<?= htmlspecialchars($item['principal'] ?? 'No Principal') ?>" data-status="<?= $item['status'] ?>" data-points-eligible="<?= (int)($item['points_eligible'] ?? 1) ?>" data-stock="<?= $item['quantity_on_hand'] ?>" data-reorder="<?= $item['reorder_level'] ?>" data-price="0" data-unit="<?= $item['unit_type'] ?>" data-branch="<?= $item['branch_id'] ?? '' ?>">
                                <td class="col-image"><div class="item-thumbnail" data-item-id="<?= $item['item_id'] ?>"><?= getItemImagesHtml((int)$item['item_id']) ?></div></td>
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

</div>


<div class="modal fade" id="barcodeScannerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-upc-scan me-2"></i>Scan Barcode</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info small mb-3">
                    Point the camera at the item barcode. Once detected, it will automatically search the inventory table.
                </div>
                <div id="motorpoolBarcodeReader" class="border rounded-3 overflow-hidden bg-light" style="min-height:280px;"></div>
                <div class="text-muted small mt-2" id="barcodeScannerStatus">Camera scanner is ready.</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary rounded-3" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

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
                    <input type="hidden" id="tireSerialParentItemId" value="">
                    <input type="hidden" id="isTireSerialChild" value="0">
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
                                <input type="number" class="form-control" id="reorderLevel" min="0" placeholder="0">
                            </div>
                            
                            <div class="col-12 col-md-4">
                                <label for="status" class="form-label">Status *</label>
                                <select class="form-select" id="status">
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                    <option value="discontinued">Discontinued</option>
                                </select>
                            </div>

                            <div class="col-12 col-md-4">
                                <label class="form-label d-block">Loyalty Points</label>
                                <div class="form-check form-switch mt-2">
                                    <input class="form-check-input" type="checkbox" id="pointsEligible" checked>
                                    <label class="form-check-label" for="pointsEligible">Eligible for Points</label>
                                </div>
                                <small class="text-muted">Uncheck for cigarettes/tobacco or exempted items.</small>
                            </div>

                            <div class="col-12 col-md-4">
                                <label for="incomeAccount" class="form-label">Income Account <span class="text-danger">*</span></label>
                                <select class="form-select item-account-select" id="incomeAccount" required onchange="handleItemAccountSelectChange(this)">
                                    <option value="">-- Select Income Account --</option>
                                    <option value="__add_income">+ Add New Income Account</option>
                                    <?php foreach ($itemIncomeAccounts as $account): ?>
                                        <option value="<?= (int)$account['id'] ?>" <?= count($itemIncomeAccounts) === 1 ? 'selected' : '' ?>><?= htmlspecialchars($account['label']) ?><?= !empty($account['type']) ? ' (' . htmlspecialchars($account['type']) . ')' : '' ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-12 col-md-4">
                                <label for="cogsAccount" class="form-label">COGS Account <span class="text-danger">*</span></label>
                                <select class="form-select item-account-select" id="cogsAccount" required onchange="handleItemAccountSelectChange(this)">
                                    <option value="">-- Select COGS Account --</option>
                                    <option value="__add_cogs">+ Add New COGS Account</option>
                                    <?php foreach ($itemCogsAccounts as $account): ?>
                                        <option value="<?= (int)$account['id'] ?>" <?= count($itemCogsAccounts) === 1 ? 'selected' : '' ?>><?= htmlspecialchars($account['label']) ?><?= !empty($account['type']) ? ' (' . htmlspecialchars($account['type']) . ')' : '' ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-12 col-md-4">
                                <label for="assetAccount" class="form-label">Asset Account <span class="text-danger">*</span></label>
                                <select class="form-select item-account-select" id="assetAccount" required onchange="handleItemAccountSelectChange(this)">
                                    <option value="">-- Select Asset Account --</option>
                                    <option value="__add_asset">+ Add New Asset Account</option>
                                    <?php foreach ($itemAssetAccounts as $account): ?>
                                        <option value="<?= (int)$account['id'] ?>" <?= count($itemAssetAccounts) === 1 ? 'selected' : '' ?>><?= htmlspecialchars($account['label']) ?><?= !empty($account['type']) ? ' (' . htmlspecialchars($account['type']) . ')' : '' ?></option>
                                    <?php endforeach; ?>
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

                        <div class="col-md-4">
                            <label class="form-label d-block">Loyalty Points</label>
                            <div class="form-check form-switch mt-2">
                                <input class="form-check-input" type="checkbox" id="editPointsEligible">
                                <label class="form-check-label" for="editPointsEligible">Eligible for Points</label>
                            </div>
                            <small class="text-muted">Uncheck for cigarettes/tobacco or exempted items.</small>
                        </div>

                        <div class="col-12 col-md-4">
                            <label for="editIncomeAccount" class="form-label">Income Account <span class="text-danger">*</span></label>
                            <select class="form-select item-account-select" id="editIncomeAccount" required onchange="handleItemAccountSelectChange(this)">
                                <option value="">-- Select Income Account --</option>
                                <option value="__add_income">+ Add New Income Account</option>
                                <?php foreach ($itemIncomeAccounts as $account): ?>
                                    <option value="<?= (int)$account['id'] ?>"><?= htmlspecialchars($account['label']) ?><?= !empty($account['type']) ? ' (' . htmlspecialchars($account['type']) . ')' : '' ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-12 col-md-4">
                            <label for="editCogsAccount" class="form-label">COGS Account <span class="text-danger">*</span></label>
                            <select class="form-select item-account-select" id="editCogsAccount" required onchange="handleItemAccountSelectChange(this)">
                                <option value="">-- Select COGS Account --</option>
                                <option value="__add_cogs">+ Add New COGS Account</option>
                                <?php foreach ($itemCogsAccounts as $account): ?>
                                    <option value="<?= (int)$account['id'] ?>"><?= htmlspecialchars($account['label']) ?><?= !empty($account['type']) ? ' (' . htmlspecialchars($account['type']) . ')' : '' ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-12 col-md-4">
                            <label for="editAssetAccount" class="form-label">Asset Account <span class="text-danger">*</span></label>
                            <select class="form-select item-account-select" id="editAssetAccount" required onchange="handleItemAccountSelectChange(this)">
                                <option value="">-- Select Asset Account --</option>
                                <option value="__add_asset">+ Add New Asset Account</option>
                                <?php foreach ($itemAssetAccounts as $account): ?>
                                    <option value="<?= (int)$account['id'] ?>"><?= htmlspecialchars($account['label']) ?><?= !empty($account['type']) ? ' (' . htmlspecialchars($account['type']) . ')' : '' ?></option>
                                <?php endforeach; ?>
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
                <div class="small text-muted mt-2">Point the barcode at the camera. Once scanned, it will automatically search the inventory table.</div>
                <div class="input-group mt-3">
                    <input type="text" class="form-control" id="manualBarcodeSearchInput" placeholder="Or type barcode manually">
                    <button class="btn btn-success" type="button" onclick="searchBarcodeManually()"><i class="bi bi-search"></i> Search</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add Item Linked Account Modal -->
<div class="modal fade" id="itemLinkedAccountModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content" style="border-radius: 18px; overflow: hidden; border: none; box-shadow: 0 20px 50px rgba(0,0,0,.25);">
            <form id="itemLinkedAccountForm" autocomplete="off">
                <div class="modal-header" style="background:#047857;color:#fff;border-bottom:none;padding:1.35rem 1.5rem;">
                    <h5 class="modal-title fw-bold" id="itemLinkedAccountModalTitle"><i class="bi bi-plus-circle me-2"></i>Add Account</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" style="padding:1.5rem;background:#fff;">
                    <input type="hidden" id="itemLinkedAccountKind">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold" style="color:#052A47;">Account Code</label>
                            <input type="text" id="itemLinkedAccountCode" class="form-control" placeholder="Example: 10100">
                        </div>
                        <div class="col-md-8">
                            <label class="form-label fw-semibold" style="color:#052A47;">Account Title <span class="text-danger">*</span></label>
                            <input type="text" id="itemLinkedAccountTitle" class="form-control" placeholder="Example: Checking" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" style="color:#052A47;">Account Type <span class="text-danger">*</span></label>
                            <select id="itemLinkedAccountType" class="form-select" disabled>
                                <option value="">Select account type</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" style="color:#052A47;">Parent Account</label>
                            <select id="itemLinkedParentAccount" class="form-select">
                                <option value="">Main account</option>
                            </select>
                            <small class="text-muted d-block mt-1">Only accounts with the same account type can be selected as parent.</small>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold" style="color:#052A47;">Description</label>
                            <textarea id="itemLinkedAccountDescription" class="form-control" rows="4" placeholder="Enter account description or notes"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer" style="background:#fff;border-top:1px solid #e5e7eb;padding:1.25rem 1.5rem;">
                    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn text-white fw-semibold" style="background:#16a34a;border-radius:24px;padding:.7rem 1.2rem;"><i class="bi bi-save me-1"></i>Save Account</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Mobile Bottom Navigation -->
    <div class="mobile-nav" id="mobileNav">
        <ul class="nav">
            <li class="nav-item">
                <a class="nav-link active" href="motorpool_inventory.php">
                    <i class="bi bi-box-seam"></i>
                    <span>Inventory</span>
                </a>
            </li>

            <li class="nav-item dropdown-more">
                <a class="nav-link more-btn" href="#" onclick="toggleMobileDropdown(event, 'vendorMobileMenu')">
                    <i class="bi bi-building"></i>
                    <span>Vendor</span>
                </a>
                <div class="more-dropdown" id="vendorMobileMenu">
                    <a class="dropdown-item" href="enterbills.php"><i
                            class="bi bi-file-earmark-text"></i><span>Enter Bills</span></a>
                    <a class="dropdown-item" href="paybills.php"><i class="bi bi-currency-dollar"></i><span>Pay
                            Bills</span></a>
                    <a class="dropdown-item" href="vendors.php"><i class="bi bi-shop"></i><span>Vendor List</span></a>
                </div>
            </li>

            <li class="nav-item dropdown-more">
                <a class="nav-link more-btn" href="#" onclick="toggleMobileDropdown(event, 'customerMobileMenu')">
                    <i class="bi bi-people-fill"></i>
                    <span>Customer</span>
                </a>
                <div class="more-dropdown" id="customerMobileMenu">
                    <a class="dropdown-item" href="orderproduct.php"><i class="bi bi-receipt"></i><span>Create
                            Invoice</span></a>
                    <a class="dropdown-item" href="collections.php"><i class="bi bi-cash-stack"></i><span>Receive
                            Payment</span></a>
                    <a class="dropdown-item" href="customer_list.php"><i class="bi bi-person-badge"></i><span>Customer
                            List</span></a>
                </div>
            </li>

            <li class="nav-item dropdown-more">
                <a class="nav-link more-btn" href="#" onclick="toggleMobileDropdown(event, 'employeeMobileMenu')">
                    <i class="bi bi-briefcase"></i>
                    <span>Employees</span>
                </a>
                <div class="more-dropdown" id="employeeMobileMenu">
                    <a class="dropdown-item" href="employeelist.php"><i class="bi bi-receipt"></i><span>Employee
                            List</span></a>
                    <a class="dropdown-item" href="employee.php"><i class="bi bi-cash-stack"></i><span>Enter
                            Time</span></a>
                </div>
            </li>

            <li class="nav-item dropdown-more">
                <a class="nav-link more-btn" href="#" onclick="toggleMobileDropdown(event, 'bankingMobileMenu')">
                    <i class="bi bi-bank2"></i>
                    <span>Banking</span>
                </a>
                <div class="more-dropdown" id="bankingMobileMenu">
                    <a class="dropdown-item" href="deposit.php"><i class="bi bi-bank"></i><span>Record
                            Deposit</span></a>
                    <a class="dropdown-item" href="withdrawal.php"><i class="bi bi-journal-check"></i><span>Write
                            Checks</span></a>
                    <a class="dropdown-item" href="transferfunds.php"><i
                            class="bi bi-arrow-left-right"></i><span>Transfer Funds</span></a>
                </div>
            </li>


            <li class="nav-item dropdown-more">
                <a class="nav-link more-btn" href="#" onclick="toggleMobileDropdown(event, 'companyMobileMenu')">
                    <i class="bi bi-building"></i>
                    <span>Company</span>
                </a>
                <div class="more-dropdown" id="companyMobileMenu">
                    <a class="dropdown-item" href="chartofaccounts.php"><i class="bi bi-graph-up"></i><span>Chart of
                            Accounts</span></a>
                    <a class="dropdown-item" href="request_handler.php"><i class="bi bi-clipboard"></i><span>RIS
                            Monitoring</span></a>
                    <a class="dropdown-item" href="motorpool.php"><i class="bi bi-truck"></i><span>Vehicle
                            Profile</span></a>
                    <a class="dropdown-item" href="central_warehouse.php"><i class="bi bi-box-seam"></i><span>Central
                            Warehouse</span></a>
                </div>
            </li>

            <li class="nav-item dropdown-more">
                <a class="nav-link more-btn" href="#" onclick="toggleMobileDropdown(event, 'accountingMobileMenu')">
                    <i class="bi bi-graph-up"></i>
                    <span>Accounting</span>
                </a>
                <div class="more-dropdown" id="accountingMobileMenu">
                    <a class="dropdown-item" href="journal_entries.php"><i class="bi bi-journal"></i><span>Journal
                            Entries</span></a>
                    <a class="dropdown-item" href="batch_transaction.php"><i class="bi bi-collection"></i><span>Batch
                            Transactions</span></a>
                    <a class="dropdown-item" href="item_adjustment.php"><i class="bi bi-sliders"></i><span>Item
                            Adjustment</span></a>
                </div>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="it_request.php">
                    <i class="bi bi-headset"></i>
                    <span>IT Request</span>
                </a>
            </li>


            <li class="nav-item" id="profileMobileBtn">
                <a href="#" class="nav-link" data-bs-toggle="modal" data-bs-target="#profileModal">
                    <i class="bi bi-box-arrow-right"></i>
                    <span>Logout</span>
                </a>
            </li>
        </ul>
    </div>

    <!-- Mobile Profile Modal -->
    <div class="modal fade" id="profileModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-person-circle me-2"></i>User Profile</h5><button
                        type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-center">
                    <div class="user-avatar-large mb-3"><?php echo $user_initials; ?></div>
                    <h4 class="mb-1"><?php echo htmlspecialchars($user_name); ?></h4>
                    <p class="text-muted mb-3"><span class="badge bg-success"><?php echo ucfirst($user_role); ?></span>
                    </p><?php if (!$view_all_branches && $branch_id > 0): ?>
                        <div class="branch-info mb-3"><i
                                class="bi bi-building me-1"></i><span><?php echo htmlspecialchars($branch_name); ?></span>
                        </div><?php endif; ?>
                    <button class="btn btn-danger btn-lg w-100"
                        onclick="confirmLogout()"><i class="bi bi-box-arrow-right me-2"></i>Logout</button>
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
    formData.append('action', 'get_motorpool_item_unit_types');
    formData.append('item_id', itemId);
    
    fetch('motorpool_inventory.php', { method: 'POST', body: formData })
        .then(async response => {
            const text = await response.text();
            try { return JSON.parse(text); }
            catch (e) { throw new Error(text ? text.substring(0, 500) : 'Invalid server response'); }
        })
        .then(data => {
            if (data.success && data.motorpool_unit_types && data.motorpool_unit_types.length > 0) {
                const badges = data.motorpool_unit_types.map(ut => 
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
    
    updateCategoryGroupHeaders(activeTab);

    const noItemsMsg = document.getElementById(`no-items-${activeTab.id}`);
    if (noItemsMsg) {
        noItemsMsg.style.display = visibleCount === 0 ? 'block' : 'none';
    }
}

function updateCategoryGroupHeaders(activeTab) {
    if (!activeTab) return;

    const headers = activeTab.querySelectorAll('.category-group-header');
    headers.forEach(header => {
        let next = header.nextElementSibling;
        let hasVisibleItem = false;
        let visibleItems = 0;

        while (next && !next.classList.contains('category-group-header')) {
            if (next.classList.contains('inventory-row') && next.style.display !== 'none') {
                hasVisibleItem = true;
                visibleItems++;
            }
            next = next.nextElementSibling;
        }

        header.style.display = hasVisibleItem ? '' : 'none';
        const countBadge = header.querySelector('.category-group-count');
        if (countBadge) {
            countBadge.textContent = `${visibleItems} Item${visibleItems > 1 ? 's' : ''}`;
        }
    });
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
            text: '' + cleanBarcode,
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
        showBarcodeScannerMessage('Camera scanning needs HTTPS. Please type the barcode manually in the field below.', 'danger');
        return;
    }

    if (typeof Html5Qrcode === 'undefined') {
        showBarcodeScannerMessage('Barcode scanner library did not load. Please type the barcode manually in the field below.', 'danger');
        return;
    }

    barcodeSearchStarting = true;
    readerEl.innerHTML = '';

    try {
        barcodeSearchScanner = new Html5Qrcode('barcodeSearchReader', { verbose: false });
    } catch (error) {
        barcodeSearchStarting = false;
        console.error('Scanner init error:', error);
        showBarcodeScannerMessage('The scanner could not start. Please type the barcode manually in the field below.', 'danger');
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

            // Fallback when the exact camera ID is not supported in some browsers.
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
                    showBarcodeScannerMessage('Cannot open camera. Allow camera permission, then try again. You can also type the barcode manually below.', 'danger');
                });
            } catch (fallbackInitError) {
                console.error('Barcode scanner fallback init error:', fallbackInitError);
                showBarcodeScannerMessage('Cannot open camera. Allow camera permission, then try again. You can also type the barcode manually below.', 'danger');
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
    
    fetch('motorpool_inventory.php', { method: 'POST', body: formData })
        .then(async response => {
            const text = await response.text();
            try { return JSON.parse(text); }
            catch (e) { throw new Error(text ? text.substring(0, 500) : 'Invalid server response'); }
        })
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
            Swal.fire({ icon: 'error', title: 'Error', text: (error && error.message) ? error.message : 'An error occurred' });
            checkbox.checked = !checkbox.checked; 
        });
}

// ========== SUPPLIER FUNCTIONS ==========
function showSupplierSelector() {
    cleanupModalBackdrops();
    showLoading();
    const formData = new FormData();
    formData.append('action', 'get_suppliers');
    
    fetch('motorpool_inventory.php', { method: 'POST', body: formData })
        .then(response => response.json())
        .then(data => {
            Swal.close();
            if (data.success) {
                suppliersList = data.suppliers || [];
                const select = $('#supplierSelect');
                select.empty().append('<option value="">-- Choose Supplier --</option>');
                suppliersList.forEach(s => {
                    const optionValue = s.supplier_key || (s.supplier_id ? ('id:' + s.supplier_id) : ('name:' + encodeURIComponent(s.supplier_name || 'No Supplier')));
                    const opt = new Option(s.supplier_name, optionValue);
                    opt.dataset.supplierName = s.supplier_name || '';
                    select.append(opt);
                });

                document.getElementById('supplierDetailsContainer').style.display = 'none';
                document.getElementById('noSupplierSelected').style.display = 'block';
                new bootstrap.Modal(document.getElementById('supplierSelectorModal')).show();
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: data.message });
            }
        })
        .catch(error => { 
            Swal.close(); 
            Swal.fire({ icon: 'error', title: 'Error', text: (error && error.message) ? error.message : 'An error occurred' });
        });
}

$('#supplierSelect').on('change', function() {
    const supplierKey = $(this).val();
    const supplierName = $(this).find('option:selected').data('supplierName') || $(this).find('option:selected').text() || '';
    if (supplierKey) loadSupplierDetails(supplierKey, supplierName);
    else { document.getElementById('supplierDetailsContainer').style.display = 'none'; document.getElementById('noSupplierSelected').style.display = 'block'; }
});

function loadSupplierDetails(supplierId, supplierName = '') {
    showLoading();
    const formData = new FormData();
    formData.append('action', 'get_supplier_details');
    formData.append('supplier_id', supplierId);
    formData.append('supplier_name', supplierName);
    
    fetch('motorpool_inventory.php', { method: 'POST', body: formData })
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
            Swal.fire({ icon: 'error', title: 'Error', text: (error && error.message) ? error.message : 'An error occurred' });
        });
}

// ========== LOW STOCK FUNCTIONS ==========
function showLowStockModal() {
    cleanupModalBackdrops();
    showLoading();
    const formData = new FormData();
    formData.append('action', 'get_low_stock_items');
    
    fetch('motorpool_inventory.php', { method: 'POST', body: formData })
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
            Swal.fire({ icon: 'error', title: 'Error', text: (error && error.message) ? error.message : 'An error occurred' });
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
    
    fetch('motorpool_inventory.php', { method: 'POST', body: formData })
        .then(response => response.json())
        .then(data => {
            Swal.close();
            if (data.success) updateOfftakeUI(data);
            else Swal.fire({ icon: 'error', title: 'Error', text: data.message });
        })
        .catch(error => { 
            Swal.close(); 
            Swal.fire({ icon: 'error', title: 'Error', text: (error && error.message) ? error.message : 'An error occurred' });
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
    
    fetch('motorpool_inventory.php', { method: 'POST', body: formData })
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
                    return `<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Offtake Report</title>
<style>
body{font-family:Arial;margin:0;padding:20px;font-size:12px;color:#111}
table{width:100%;border-collapse:collapse}
th,td{border:1px solid #000;padding:8px;text-align:left}
th{background:#f0f0f0}
.summary{display:flex;margin-bottom:20px;gap:10px}
.summary-item{flex:1;border:1px solid #000;padding:10px;text-align:center}
.print-header{text-align:center;margin-bottom:20px}
.print-header img{width:50px}
</style>
</head>
<body>
<div class="print-header"><img src="${logoBase64}" alt="Logo"><h2>Average Daily Offtake Report</h2><p>Branch: ${branchName || 'All Branches'}</p></div>
<div class="summary"><div class="summary-item"><strong>Avg Daily</strong><br>${summary.avg_daily.toFixed(1)}</div><div class="summary-item"><strong>Total Quantity</strong><br>${summary.total_quantity.toLocaleString()}</div><div class="summary-item"><strong>Active Days</strong><br>${summary.active_days}</div><div class="summary-item"><strong>Per Item Avg</strong><br>${summary.avg_per_item.toFixed(1)}</div></div>
<table><thead><tr><th>Date</th><th>Orders</th><th>Quantity</th><th>Amount</th></tr></thead><tbody>${tableRows}<tr style="font-weight:bold"><td>TOTAL</td><td style="text-align:center">${tOrders}</td><td style="text-align:center">${tQty}</td><td style="text-align:right">₱${tAmount.toFixed(2)}</td></tr></tbody></table>
<p style="margin-top:20px;font-size:10px">Generated: ${currentDate}</p>
</body>
</html>`;

</body></html>`;
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
            Swal.fire({ icon: 'error', title: 'Error', text: (error && error.message) ? error.message : 'An error occurred' });
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

    fetch('motorpool_inventory.php', { method: 'POST', body: formData })
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

            fetch('motorpool_inventory.php', { method: 'POST', body: formData })
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

    const controller = new AbortController();
    const batchLoadTimer = setTimeout(() => controller.abort(), 15000);

    fetch(window.location.href, {
        method: 'POST',
        body: formData,
        cache: 'no-store',
        signal: controller.signal
    })
    .then(response => response.text())
    .then(text => {
        clearTimeout(batchLoadTimer);
        let data;
        try {
            data = JSON.parse(text);
        } catch (e) {
            throw new Error(text.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim() || 'Invalid server response');
        }
        if (!data.success) {
            throw new Error(data.message || 'Unable to load price level items.');
        }
        if (!Array.isArray(data.items) || data.items.length === 0) {
            tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-4">No items found.</td></tr>';
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
                <td>${String(row.has_existing_price) === '0' || row.current_price === null || typeof row.current_price === 'undefined' ? '<span class="text-muted">No existing price levels</span>' : '<span class="fw-semibold">₱' + Number(row.current_price || 0).toFixed(2) + '</span>'}</td>
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
    .catch(error => {
        clearTimeout(batchLoadTimer);
        const msg = (error && error.name === 'AbortError') ? 'Loading timed out. Please reopen the modal.' : ((error && error.message) ? error.message : 'Failed to load price level items.');
        tbody.innerHTML = `<tr><td colspan="5" class="text-center text-danger py-4">${escapeHtml(msg)}</td></tr>`;
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
    if (document.getElementById('tireSerialParentItemId')) document.getElementById('tireSerialParentItemId').value = '';
    if (document.getElementById('isTireSerialChild')) document.getElementById('isTireSerialChild').value = '0';
    document.getElementById('itemCode').value = '<?= $next_item_code ?>';
    document.getElementById('itemCode').readOnly = true;
    document.getElementById('status').value = 'active';
    if (document.getElementById('pointsEligible')) document.getElementById('pointsEligible').checked = true;
    if (document.getElementById('incomeAccount')) document.getElementById('incomeAccount').value = '';
    if (document.getElementById('cogsAccount')) document.getElementById('cogsAccount').value = '';
    if (document.getElementById('assetAccount')) document.getElementById('assetAccount').value = '';
    applySingleRegisteredItemAccountDefaults();
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
            const inputUnitType = (input.dataset.unitType || (idx < unitTypesData.length ? unitTypesData[idx].unit_type : '') || '').trim();
            if (!inputUnitType) return;
            if (input.value.trim() !== '') {
                priceRow.prices[inputUnitType] = parseFloat(input.value) || 0;
            }
        });
        
        if (Object.keys(priceRow.prices).length > 0) {
            pricingData.push(priceRow);
        }
    });
    
    if (pricingData.length > 0 && unitTypesData.length > 0) {
        const standardRow = pricingData.find(row => (row.price_level || '').trim().toLowerCase() === 'standard') || pricingData[0];
        unitTypesData.forEach(ut => {
            ut.unit_price = standardRow.prices[ut.unit_type] || 0;
        });
    }
    
    return { unitTypesData, pricingData };
}


const itemAccountAddConfig = {
    incomeAccount: {
        kind: 'income',
        addValue: '__add_income',
        title: 'Add New Income Account',
        accountLabel: 'Income Account',
        accountType: 'Income'
    },
    cogsAccount: {
        kind: 'cogs',
        addValue: '__add_cogs',
        title: 'Add New COGS Account',
        accountLabel: 'COGS Account',
        accountType: 'Cost of Goods Sold'
    },
    assetAccount: {
        kind: 'asset',
        addValue: '__add_asset',
        title: 'Add New Asset Account',
        accountLabel: 'Asset Account',
        accountType: 'Other Current Asset'
    }
};

const itemLinkedAccountParents = {
    income: <?= json_encode($itemIncomeAccounts, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
    cogs: <?= json_encode($itemCogsAccounts, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
    asset: <?= json_encode($itemAssetAccounts, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>
};

const itemLinkedAccountDefaultSelections = {
    incomeAccount: <?= count($itemIncomeAccounts) === 1 ? (int)$itemIncomeAccounts[0]['id'] : 0 ?>,
    cogsAccount: <?= count($itemCogsAccounts) === 1 ? (int)$itemCogsAccounts[0]['id'] : 0 ?>,
    assetAccount: <?= count($itemAssetAccounts) === 1 ? (int)$itemAssetAccounts[0]['id'] : 0 ?>
};

function applySingleRegisteredItemAccountDefaults() {
    Object.entries(itemLinkedAccountDefaultSelections).forEach(([selectId, defaultAccountId]) => {
        const select = document.getElementById(selectId);
        if (!select || !defaultAccountId || String(select.value || '').startsWith('__add_')) return;
        if (select.value) return;
        const optionExists = Array.from(select.options).some(option => String(option.value) === String(defaultAccountId));
        if (optionExists) {
            select.value = String(defaultAccountId);
            select.dataset.previousValue = String(defaultAccountId);
        }
    });
}
let itemLinkedAccountActiveSelect = null;
let itemLinkedAccountModalInstance = null;

function escapeItemAccountHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function buildItemAccountOptionLabel(account) {
    const label = (account && account.label) ? account.label : '';
    const type = (account && account.type) ? account.type : '';
    return label + (type ? ' (' + type + ')' : '');
}

function addAccountOptionToDropdown(select, account) {
    if (!select || !account || !account.id) return;

    const existing = Array.from(select.options).find(option => String(option.value) === String(account.id));
    if (existing) {
        existing.textContent = buildItemAccountOptionLabel(account);
        select.value = String(account.id);
        return;
    }

    const option = document.createElement('option');
    option.value = String(account.id);
    option.textContent = buildItemAccountOptionLabel(account);

    const addOption = Array.from(select.options).find(opt => String(opt.value).startsWith('__add_'));
    if (addOption && addOption.nextSibling) {
        select.insertBefore(option, addOption.nextSibling);
    } else {
        select.appendChild(option);
    }

    select.value = String(account.id);
}

function populateItemLinkedAccountType(config) {
    const typeSelect = document.getElementById('itemLinkedAccountType');
    if (!typeSelect || !config) return;
    typeSelect.innerHTML = '';
    const option = document.createElement('option');
    option.value = config.accountType;
    option.textContent = config.accountType;
    option.selected = true;
    typeSelect.appendChild(option);
}

function populateItemLinkedParentAccounts(config) {
    const parentSelect = document.getElementById('itemLinkedParentAccount');
    if (!parentSelect || !config) return;
    parentSelect.innerHTML = '<option value="">Main account</option>';
    const accounts = itemLinkedAccountParents[config.kind] || [];
    accounts.forEach(account => {
        const option = document.createElement('option');
        option.value = String(account.id);
        option.textContent = buildItemAccountOptionLabel(account);
        parentSelect.appendChild(option);
    });
}

function resetItemLinkedAccountForm(config) {
    document.getElementById('itemLinkedAccountKind').value = config.kind;
    document.getElementById('itemLinkedAccountCode').value = '';
    document.getElementById('itemLinkedAccountTitle').value = '';
    document.getElementById('itemLinkedAccountDescription').value = '';
    document.getElementById('itemLinkedAccountModalTitle').innerHTML = '<i class="bi bi-plus-circle me-2"></i>' + escapeItemAccountHtml(config.title.replace('New ', ''));
    populateItemLinkedAccountType(config);
    populateItemLinkedParentAccounts(config);
}


function getVisibleItemParentModal() {
    return document.querySelector('#itemModal.show, #editItemModal.show');
}

function prepareItemLinkedAccountModalLayer() {
    const parentModal = getVisibleItemParentModal();
    const childModal = document.getElementById('itemLinkedAccountModal');

    if (parentModal) {
        parentModal.classList.remove('amgc-parent-modal-paused');
        parentModal.style.zIndex = '1055';
        parentModal.style.opacity = '1';
        parentModal.style.filter = 'none';
        parentModal.style.pointerEvents = 'auto';
        parentModal.removeAttribute('aria-hidden');
    }

    if (childModal) {
        childModal.style.zIndex = '1095';
        childModal.setAttribute('data-bs-backdrop', 'false');
    }

    document.body.classList.add('modal-open');
    document.body.style.overflow = 'hidden';

    requestAnimationFrame(() => {
        const parentIsOpen = !!getVisibleItemParentModal();
        const childIsOpen = childModal && childModal.classList.contains('show');

        document.querySelectorAll('.modal-backdrop').forEach((backdrop, index) => {
            if (backdrop.classList.contains('item-linked-account-backdrop') ||
                backdrop.classList.contains('amgc-item-linked-backdrop-final') ||
                parseInt(backdrop.style.zIndex || window.getComputedStyle(backdrop).zIndex || '0', 10) >= 1080) {
                backdrop.remove();
            }
        });

        if (parentIsOpen) {
            let parentBackdrop = Array.from(document.querySelectorAll('.modal-backdrop'))
                .find(backdrop => backdrop.classList.contains('item-parent-backdrop'));

            if (!parentBackdrop) {
                parentBackdrop = Array.from(document.querySelectorAll('.modal-backdrop'))[0] || document.createElement('div');
                if (!parentBackdrop.parentNode) {
                    parentBackdrop.className = 'modal-backdrop fade show item-parent-backdrop';
                    document.body.appendChild(parentBackdrop);
                }
            }

            parentBackdrop.classList.add('item-parent-backdrop');
            parentBackdrop.classList.remove('item-linked-account-backdrop', 'amgc-item-linked-backdrop-final', 'uom-scanner-backdrop');
            parentBackdrop.style.zIndex = '1040';
            parentBackdrop.style.opacity = '0.5';
        } else if (!document.querySelector('.modal.show')) {
            document.querySelectorAll('.modal-backdrop').forEach(backdrop => backdrop.remove());
            document.body.classList.remove('modal-open');
            document.body.style.removeProperty('overflow');
            document.body.style.removeProperty('padding-right');
        }
    });
}

function restoreItemParentModalAfterLinkedAccount() {
    const parentModal = getVisibleItemParentModal();

    document.querySelectorAll('.modal-backdrop').forEach(backdrop => {
        const z = parseInt(backdrop.style.zIndex || window.getComputedStyle(backdrop).zIndex || '0', 10);
        if (backdrop.classList.contains('item-linked-account-backdrop') ||
            backdrop.classList.contains('amgc-item-linked-backdrop-final') ||
            z >= 1080) {
            backdrop.remove();
        }
    });

    if (!parentModal) {
        if (!document.querySelector('.modal.show')) {
            document.body.classList.remove('modal-open');
            document.body.style.removeProperty('overflow');
            document.body.style.removeProperty('padding-right');
            document.querySelectorAll('.modal-backdrop').forEach(backdrop => backdrop.remove());
        }
        return;
    }

    parentModal.classList.remove('amgc-parent-modal-paused');
    parentModal.style.zIndex = '1055';
    parentModal.style.opacity = '1';
    parentModal.style.filter = 'none';
    parentModal.style.pointerEvents = 'auto';
    parentModal.removeAttribute('aria-hidden');

    document.body.classList.add('modal-open');
    document.body.style.overflow = 'hidden';

    let parentBackdrop = Array.from(document.querySelectorAll('.modal-backdrop'))
        .find(backdrop => backdrop.classList.contains('item-parent-backdrop'))
        || Array.from(document.querySelectorAll('.modal-backdrop'))[0];

    if (!parentBackdrop) {
        parentBackdrop = document.createElement('div');
        parentBackdrop.className = 'modal-backdrop fade show item-parent-backdrop';
        document.body.appendChild(parentBackdrop);
    }

    document.querySelectorAll('.modal-backdrop').forEach(backdrop => {
        if (backdrop !== parentBackdrop && !backdrop.classList.contains('uom-scanner-backdrop')) {
            backdrop.remove();
        }
    });

    parentBackdrop.classList.add('item-parent-backdrop');
    parentBackdrop.classList.remove('item-linked-account-backdrop', 'amgc-item-linked-backdrop-final', 'uom-scanner-backdrop');
    parentBackdrop.style.zIndex = '1040';
    parentBackdrop.style.opacity = '0.5';
}

function openItemLinkedAccountModal(config, select) {
    itemLinkedAccountActiveSelect = select;
    const modalEl = document.getElementById('itemLinkedAccountModal');
    if (!modalEl) return;

    resetItemLinkedAccountForm(config);

    prepareItemLinkedAccountModalLayer();

    itemLinkedAccountModalInstance = bootstrap.Modal.getOrCreateInstance(modalEl, {
        backdrop: false,
        keyboard: false
    });
    itemLinkedAccountModalInstance.show();

    setTimeout(() => {
        prepareItemLinkedAccountModalLayer();
        const titleInput = document.getElementById('itemLinkedAccountTitle');
        if (titleInput) titleInput.focus();
    }, 250);
}

function handleItemAccountSelectChange(select) {
    if (!select) return;

    const config = itemAccountAddConfig[select.id];
    if (!config || select.value !== config.addValue) return;

    const previousValue = select.dataset.previousValue || '';
    select.value = previousValue;
    openItemLinkedAccountModal(config, select);
}

const itemLinkedAccountModalEl = document.getElementById('itemLinkedAccountModal');
if (itemLinkedAccountModalEl) {
    itemLinkedAccountModalEl.addEventListener('show.bs.modal', function() {
        prepareItemLinkedAccountModalLayer();
    });

    itemLinkedAccountModalEl.addEventListener('shown.bs.modal', function() {
        prepareItemLinkedAccountModalLayer();
    });

    itemLinkedAccountModalEl.addEventListener('hide.bs.modal', function() {
        document.querySelectorAll('.modal-backdrop.item-linked-account-backdrop').forEach(backdrop => {
            backdrop.style.opacity = '0';
        });
    });

    itemLinkedAccountModalEl.addEventListener('hidden.bs.modal', function() {
        setTimeout(restoreItemParentModalAfterLinkedAccount, 10);
        setTimeout(restoreItemParentModalAfterLinkedAccount, 160);
    });
}

const itemLinkedAccountForm = document.getElementById('itemLinkedAccountForm');
if (itemLinkedAccountForm) {
    itemLinkedAccountForm.addEventListener('submit', function(e) {
        e.preventDefault();

        const kind = document.getElementById('itemLinkedAccountKind').value;
        const config = Object.values(itemAccountAddConfig).find(item => item.kind === kind);
        if (!config || !itemLinkedAccountActiveSelect) return;

        const title = document.getElementById('itemLinkedAccountTitle').value.trim();
        const code = document.getElementById('itemLinkedAccountCode').value.trim();
        const parentId = document.getElementById('itemLinkedParentAccount').value || '';
        const description = document.getElementById('itemLinkedAccountDescription').value.trim();

        if (!title) {
            Swal.fire({ icon: 'warning', title: 'Required Field', text: 'Account Title is required.' });
            return;
        }

        const formData = new FormData();
        formData.append('action', 'add_item_chart_account');
        formData.append('account_kind', config.kind);
        formData.append('account_title', title);
        formData.append('account_code', code);
        formData.append('parent_account_id', parentId);
        formData.append('description', description);

        const saveBtn = itemLinkedAccountForm.querySelector('button[type="submit"]');
        const originalSaveText = saveBtn ? saveBtn.innerHTML : '';
        if (saveBtn) {
            saveBtn.disabled = true;
            saveBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Saving...';
        }

        fetch('motorpool_inventory.php', { method: 'POST', body: formData })
            .then(response => response.json())
            .then(data => {
                if (!data.success || !data.account) {
                    Swal.fire({ icon: 'error', title: 'Error', text: data.message || 'Failed to save account.' });
                    return;
                }

                addAccountOptionToDropdown(itemLinkedAccountActiveSelect, data.account);
                itemLinkedAccountActiveSelect.dataset.previousValue = String(data.account.id);

                if (!itemLinkedAccountParents[config.kind]) itemLinkedAccountParents[config.kind] = [];
                if (!itemLinkedAccountParents[config.kind].some(account => String(account.id) === String(data.account.id))) {
                    itemLinkedAccountParents[config.kind].push(data.account);
                }

                if (itemLinkedAccountModalInstance) {
                    itemLinkedAccountModalInstance.hide();
                }

                Swal.fire({
                    icon: 'success',
                    title: 'Account Added',
                    text: data.message || 'Account added successfully.',
                    timer: 1500,
                    showConfirmButton: false
                });
            })
            .catch(error => {
                Swal.fire({ icon: 'error', title: 'Error', text: 'An error occurred: ' + error.message });
            })
            .finally(() => {
                if (saveBtn) {
                    saveBtn.disabled = false;
                    saveBtn.innerHTML = originalSaveText;
                }
            });
    });
}

document.addEventListener('DOMContentLoaded', function() {
    applySingleRegisteredItemAccountDefaults();
    document.querySelectorAll('.item-account-select').forEach(select => {
        select.dataset.previousValue = select.value || '';
        select.addEventListener('focus', function() {
            this.dataset.previousValue = this.value || '';
        });
        select.addEventListener('change', function() {
            if (!String(this.value).startsWith('__add_')) {
                this.dataset.previousValue = this.value || '';
            }
        });
    });
});


function saveItem() {
    const itemCode = document.getElementById('itemCode').value.trim();
    const itemName = document.getElementById('itemName').value.trim();
    const category = document.getElementById('category').value;
    const principal = normalizePrincipalValue(document.getElementById('principal') ? document.getElementById('principal').value : '');
    const description = document.getElementById('description').value;
        const reorderLevel = document.getElementById('reorderLevel').value;
    const status = document.getElementById('status').value;
    const incomeAccountId = document.getElementById('incomeAccount') ? document.getElementById('incomeAccount').value : '';
    const cogsAccountId = document.getElementById('cogsAccount') ? document.getElementById('cogsAccount').value : '';
    const assetAccountId = document.getElementById('assetAccount') ? document.getElementById('assetAccount').value : '';
    
    const missingFields = [];
    if (!itemCode) missingFields.push('Item Code');
    if (!itemName) missingFields.push('Item Name');
    // Linked accounts are saved when selected, but they no longer block saving the item.
    // This prevents false Required Fields alerts when the dropdown visually has a value but its hidden value is blank.

    if (missingFields.length > 0) {
        Swal.fire({
            icon: 'warning',
            title: 'Required Fields',
            text: 'Please fill in: ' + missingFields.join(', ') + '.'
        });
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
    const tireParentInput = document.getElementById('tireSerialParentItemId');
    const tireChildInput = document.getElementById('isTireSerialChild');
    if (tireChildInput && tireChildInput.value === '1' && tireParentInput && tireParentInput.value) {
        formData.append('is_tire_serial_child', '1');
        formData.append('parent_item_id', tireParentInput.value);
    }
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
    formData.append('points_eligible', document.getElementById('pointsEligible') && document.getElementById('pointsEligible').checked ? '1' : '0');
    formData.append('income_account_id', incomeAccountId);
    formData.append('cogs_account_id', cogsAccountId);
    formData.append('asset_account_id', assetAccountId);
    formData.append('motorpool_unit_types', JSON.stringify(unitTypesData));
    formData.append('pricing', JSON.stringify(pricingData));
    const tireSerialBulkEl = document.getElementById('tireSerialBulkNumbers');
    if (tireSerialBulkEl) formData.append('serial_numbers_bulk', tireSerialBulkEl.value || '');
    
    const imagesInput = document.getElementById('itemImages');
    if (imagesInput && imagesInput.files.length > 0) {
        for (let i = 0; i < imagesInput.files.length; i++) {
            formData.append('itemImages[]', imagesInput.files[i]);
        }
    }
    
    fetch('motorpool_inventory.php', { method: 'POST', body: formData })
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
    
    fetch('motorpool_inventory.php', { method: 'POST', body: formData })
        .then(response => response.json())
        .then(data => {
            Swal.close();
            if (data.success) {
                const item = data.item;
                currentViewItemData = item;
                const unitTypes = data.motorpool_unit_types || [];
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
                    mainImageHtml = `<img src="../uploads/motorpool_inventory/${escapeHtml(primaryImage.image_path)}" alt="${escapeHtml(item.item_name)}" class="main-image" id="mainViewImage">`;
                    
                    imagesHtml = `<div class="item-images-carousel">`;
                    images.forEach((img, idx) => {
                        const activeClass = (img.is_primary || idx === 0) ? 'active' : '';
                        imagesHtml += `<img src="../uploads/motorpool_inventory/${escapeHtml(img.image_path)}" alt="Thumbnail" class="item-image-thumb ${activeClass}" onclick="document.getElementById('mainViewImage').src = this.src; document.querySelectorAll('.item-image-thumb').forEach(t => t.classList.remove('active')); this.classList.add('active');">`;
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
                            const priceInfo = getPricingCellInfo(pr, ut.unit_type_name, ut.unit_type_id);
                            const formattedPrice = priceInfo ? `₱${parseFloat((typeof priceInfo === 'object' ? priceInfo.unit_price : priceInfo) || 0).toFixed(2)}` : '—';
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
                                                const totalCost = parseFloat(ut.total_cost || 0) > 0 ? parseFloat(ut.total_cost || 0) : (currentStock * avgCost);
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
            Swal.fire({ icon: 'error', title: 'Error', text: (error && error.message) ? error.message : 'An error occurred' });
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
                text: '',
                confirmButtonColor: '#047857'
            });
        } else {
            alert('');
        }
        return;
    }

    const barcodeValue = String(item.barcode || '').trim();
    const itemName = String(item.item_name || '').trim();

    const esc = (value) => String(value || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');

    const labelHtml = `
        <div class="barcode-label">
            <div class="label-item-name">${esc(itemName)}</div>
            <svg class="barcode-svg" id="barcodeLabel0"></svg>
            <div class="label-barcode-text">${esc(barcodeValue)}</div>
        </div>
    `;

    const printWindow = window.open('', '_blank', 'width=420,height=650');
    if (!printWindow) {
        if (typeof Swal !== 'undefined') {
            Swal.fire({
                icon: 'warning',
                title: 'Popup Blocked',
                text: 'Allow popups first so the barcode print preview can open.',
                confirmButtonColor: '#047857'
            });
        } else {
            alert('Allow popups first so the barcode print preview can open.');
        }
        return;
    }

    printWindow.document.open();
    printWindow.document.write(`
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Print Barcode - ${esc(itemName)}</title>
            <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"><\/script>
            <style>
                @page {
                    size: 80mm auto;
                    margin: 3mm;
                }

                * {
                    box-sizing: border-box;
                }

                html,
                body {
                    width: 80mm;
                    margin: 0;
                    padding: 0;
                    background: #ffffff;
                    color: #111827;
                    font-family: Arial, Helvetica, sans-serif;
                }

                .barcode-sheet {
                    width: 74mm;
                    margin: 0 auto;
                    padding: 0;
                }

                .barcode-label {
                    width: 74mm;
                    min-height: 32mm;
                    padding: 0;
                    border: none;
                    display: flex;
                    flex-direction: column;
                    align-items: center;
                    justify-content: flex-start;
                    text-align: center;
                    overflow: hidden;
                    page-break-inside: avoid;
                    break-inside: avoid;
                }

                .label-item-name {
                    width: 100%;
                    margin: 0 0 2mm 0;
                    padding: 0;
                    font-size: 11pt;
                    font-weight: 700;
                    line-height: 1.2;
                    text-align: center;
                    color: #111827;
                    white-space: normal;
                    word-break: break-word;
                }

                .barcode-svg {
                    width: 100%;
                    max-width: 72mm;
                    height: 18mm;
                    margin: 0 auto;
                    display: block;
                }

                .label-barcode-text {
                    width: 100%;
                    margin-top: 1mm;
                    font-size: 9pt;
                    font-weight: 700;
                    letter-spacing: 0.4px;
                    line-height: 1.1;
                    text-align: center;
                    color: #111827;
                    white-space: nowrap;
                    overflow: hidden;
                    text-overflow: ellipsis;
                }

                @media print {
                    html,
                    body {
                        width: 80mm;
                        margin: 0;
                        padding: 0;
                        -webkit-print-color-adjust: exact;
                        print-color-adjust: exact;
                    }
                }
            </style>
        </head>
        <body>
            <div class="barcode-sheet">${labelHtml}</div>
            <script>
                const barcodeValue = ${JSON.stringify(barcodeValue)};
                window.onload = function() {
                    try {
                        JsBarcode('#barcodeLabel0', barcodeValue, {
                            format: 'CODE128',
                            lineColor: '#111827',
                            width: 1.6,
                            height: 52,
                            displayValue: false,
                            margin: 0
                        });
                    } catch (error) {
                        console.error(error);
                    }

                    setTimeout(function() {
                        window.focus();
                        window.print();
                    }, 500);
                };
            <\/script>
        
<style id="mp-clean-details-final-css">
#viewItemModal .modal-dialog{max-width:1000px!important;}
#viewItemModal .modal-body{background:#f8fafc!important;padding:0!important;}
.mp-detail-clean{background:#fff;padding:28px 42px 34px!important;}
.mp-gallery-wrapper{width:100%;margin:0 0 34px!important;text-align:center;}
.mp-gallery-main{width:100%;min-height:360px;display:flex;align-items:center;justify-content:center;background:#fff;border:0!important;border-radius:0!important;overflow:hidden;}
.mp-gallery-main img,.mp-main-detail-img{max-width:560px!important;max-height:460px!important;width:auto!important;height:auto!important;object-fit:contain!important;display:block;margin:auto;}
.mp-gallery-thumbs{display:flex;gap:14px;flex-wrap:wrap;justify-content:flex-start;margin-top:20px;padding-left:10px;}
.mp-gallery-thumb{width:98px;height:98px;border:2px solid #e2e8f0;border-radius:10px;background:#fff;padding:0;overflow:hidden;cursor:pointer;}
.mp-gallery-thumb img{width:100%;height:100%;object-fit:cover;}
.mp-gallery-thumb.active{border-color:#047857;box-shadow:0 0 0 3px rgba(68,211,78,.18);}
.clean-section{border:1px solid #bbf7d0!important;border-radius:18px!important;background:#fff!important;margin:22px 0!important;box-shadow:0 4px 14px rgba(15,23,42,.04)!important;padding:20px!important;}
.clean-title{width:100%;border:1px solid #bbf7d0!important;background:#fff!important;border-radius:14px!important;padding:15px 20px!important;font-size:1.08rem!important;font-weight:800!important;color:#052A47!important;display:flex!important;align-items:center!important;justify-content:space-between!important;text-align:left!important;}
.clean-title span{display:flex;align-items:center;gap:11px;}.clean-title i{color:#047857;}
.mp-detail-table{width:100%;border-collapse:collapse;margin-top:12px;overflow:hidden;border-radius:0 0 12px 12px;}
.mp-detail-table th{width:145px;color:#052A47;font-weight:800;padding:18px 24px!important;vertical-align:middle;background:#fff;text-align:left;}
.mp-detail-table td{padding:18px 24px!important;vertical-align:middle;color:#052A47;font-size:1.02rem;}
.mp-detail-table tr:nth-child(even) th,.mp-detail-table tr:nth-child(even) td{background:#eafff3!important;}
.mp-detail-table tr:last-child th,.mp-detail-table tr:last-child td{background:#d8fbe7!important;}
.mp-status-badge{display:inline-flex;align-items:center;gap:7px;margin-left:10px;padding:7px 12px;border-radius:999px;font-size:.85rem;font-weight:800;border:1px solid #86efac;background:#dcfce7;color:#047857;}.mp-status-badge.inactive{background:#f1f5f9;color:#64748b;border-color:#cbd5e1;}
.barcode-preview-card{display:inline-block;background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:8px 10px;max-width:100%;}.barcode-text{font-size:12px;font-weight:700;letter-spacing:1px;text-align:center;color:#052A47;margin-top:2px;}
@media(max-width:768px){.mp-detail-clean{padding:18px!important}.mp-gallery-main{min-height:260px}.mp-gallery-main img,.mp-main-detail-img{max-width:100%!important;max-height:300px!important}.mp-detail-table th{width:120px;padding:14px!important}.mp-detail-table td{padding:14px!important}.clean-section{padding:14px!important}.mp-gallery-thumb{width:74px;height:74px}}
</style>

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

function setSelectValueIfOptionExists(selectId, value) {
    const select = document.getElementById(selectId);
    if (!select) return;
    const stringValue = value === null || typeof value === 'undefined' ? '' : String(value);
    const hasOption = Array.from(select.options).some(option => option.value === stringValue);
    select.value = hasOption ? stringValue : '';
}

function editItem(id) {
    cleanupModalBackdrops();
    showLoading();
    const formData = new FormData();
    formData.append('action', 'get_item');
    formData.append('item_id', id);
    
    fetch('motorpool_inventory.php', { method: 'POST', body: formData })
        .then(response => response.json())
        .then(data => {
            Swal.close();
            if (data.success) {
                const item = data.item;
                const unitTypes = data.motorpool_unit_types || [];
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
                if (document.getElementById('editPointsEligible')) {
                    document.getElementById('editPointsEligible').checked = String(item.points_eligible ?? '1') === '1';
                }
                setSelectValueIfOptionExists('editIncomeAccount', item.income_account_id || '');
                setSelectValueIfOptionExists('editCogsAccount', item.cogs_account_id || '');
                setSelectValueIfOptionExists('editAssetAccount', item.asset_account_id || '');
                
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
                                <img src="../uploads/motorpool_inventory/${escapeHtml(img.image_path)}" class="img-thumbnail" style="width: 80px; height: 80px; object-fit: cover;">
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
            Swal.fire({ icon: 'error', title: 'Error', text: (error && error.message) ? error.message : 'An error occurred' });
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
    const incomeAccountId = document.getElementById('editIncomeAccount') ? document.getElementById('editIncomeAccount').value : '';
    const cogsAccountId = document.getElementById('editCogsAccount') ? document.getElementById('editCogsAccount').value : '';
    const assetAccountId = document.getElementById('editAssetAccount') ? document.getElementById('editAssetAccount').value : '';
    
    if (!itemName) {
        Swal.fire({ icon: 'warning', title: 'Required Fields', text: 'Please fill in Item Name.' });
        return;
    }

    if (!incomeAccountId || !cogsAccountId || !assetAccountId) {
        Swal.fire({ icon: 'warning', title: 'Required Fields', text: 'Please select Income Account, COGS Account, and Asset Account.' });
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
    formData.append('points_eligible', document.getElementById('editPointsEligible') && document.getElementById('editPointsEligible').checked ? '1' : '0');
    formData.append('income_account_id', incomeAccountId);
    formData.append('cogs_account_id', cogsAccountId);
    formData.append('asset_account_id', assetAccountId);
    formData.append('motorpool_unit_types', JSON.stringify(unitTypesData));
    formData.append('pricing', JSON.stringify(pricingData));
    
    const imagesInput = document.getElementById('editItemImages');
    if (imagesInput && imagesInput.files.length > 0) {
        for (let i = 0; i < imagesInput.files.length; i++) {
            formData.append('editItemImages[]', imagesInput.files[i]);
        }
    }
    
    fetch('motorpool_inventory.php', { method: 'POST', body: formData })
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
            
            fetch('motorpool_inventory.php', { method: 'POST', body: formData })
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
                    Swal.fire({ icon: 'error', title: 'Error', text: (error && error.message) ? error.message : 'An error occurred' });
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
            
            fetch('motorpool_inventory.php', { method: 'POST', body: formData })
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
    // If the click came from the edit/delete button or toggle switch, do not open the view
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
                <p class="text-muted small mb-0">Value calculated using default unit type prices from motorpool_item_unit_pricing table.</p>
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
    
   // ========== STOCK ALERT - SHOWS ON EVERY REFRESH (NO AUTO CLOSE) ==========
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
    fetch('motorpool_inventory.php', { method: 'POST', body: formData }).then(r => r.json()).then(d => { Swal.close(); if (!d.success) console.error(d.message); }).catch(e => { Swal.close(); console.error(e); });
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
                        thumbnail.innerHTML = `<img src="../uploads/motorpool_inventory/${primaryImage.image_path}" alt="Product" style="width: 40px; height: 40px; object-fit: cover; border-radius: 4px;" onerror="this.style.display='none';">`;
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



<script>
function openSystemTaskRecord(page,param,id){
    if(!page||!param||!id) return;
    const sep = page.indexOf('?') === -1 ? '?' : '&';
    window.location.href = page + sep
        + encodeURIComponent(param) + '=' + encodeURIComponent(id)
        + '&auto_open=1&open_record_id=' + encodeURIComponent(id)
        + '&open_param=' + encodeURIComponent(param);
}

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
                        <div class="small text-muted mt-2">Point the barcode at the camera. Once scanned, it will automatically be placed in the UoM barcode field.</div>
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
        showUomBarcodeScannerMessage('Camera scanning needs HTTPS. You can still type the barcode manually in the UoM field.', 'danger');
        return;
    }

    if (typeof Html5Qrcode === 'undefined') {
        uomBarcodeScannerStarting = false;
        showUomBarcodeScannerMessage('Barcode scanner library did not load. You can still type the barcode manually in the UoM field.', 'danger');
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
        showUomBarcodeScannerMessage('The scanner could not start. You can still type the barcode manually in the UoM field.', 'danger');
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
                        showUomBarcodeScannerMessage('Cannot open camera. Allow camera permission, then try again. You can still type the barcode manually in the UoM field.', 'danger');
                    });
            } catch (fallbackInitError) {
                uomBarcodeScannerStarting = false;
                console.error('UoM scanner fallback init error:', fallbackInitError);
                showUomBarcodeScannerMessage('Cannot open camera. Allow camera permission, then try again. You can still type the barcode manually in the UoM field.', 'danger');
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
                            <div class="small text-muted mt-2">Point the barcode at the camera. Once scanned, it will automatically be placed in the UoM barcode field.</div>
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




<style id="amgc-linked-account-no-flicker-final">
/* No-flicker nested Add Account modal:
   Keep only the Add Item backdrop. The child Add Account modal opens above it without its own backdrop. */
#itemModal.show,
#editItemModal.show {
    z-index: 1055 !important;
    opacity: 1 !important;
    filter: none !important;
    pointer-events: auto !important;
}
#itemLinkedAccountModal.show {
    z-index: 1095 !important;
}
.modal-backdrop.item-parent-backdrop {
    z-index: 1040 !important;
    opacity: .5 !important;
}
.modal-backdrop.item-linked-account-backdrop,
.modal-backdrop.amgc-item-linked-backdrop-final {
    display: none !important;
}
</style>

<script id="amgc-linked-account-no-flicker-final">
(function () {
    function parentItemModal() {
        return document.querySelector('#itemModal.show, #editItemModal.show');
    }

    function removeChildAccountBackdrops() {
        document.querySelectorAll('.modal-backdrop').forEach(function (backdrop) {
            var z = parseInt(backdrop.style.zIndex || window.getComputedStyle(backdrop).zIndex || '0', 10);
            if (
                backdrop.classList.contains('item-linked-account-backdrop') ||
                backdrop.classList.contains('amgc-item-linked-backdrop-final') ||
                z >= 1080
            ) {
                backdrop.remove();
            }
        });
    }

    function ensureParentBackdrop() {
        var parent = parentItemModal();
        if (!parent) return;

        removeChildAccountBackdrops();

        var parentBackdrop = Array.from(document.querySelectorAll('.modal-backdrop')).find(function (backdrop) {
            return backdrop.classList.contains('item-parent-backdrop');
        }) || Array.from(document.querySelectorAll('.modal-backdrop'))[0];

        if (!parentBackdrop) {
            parentBackdrop = document.createElement('div');
            parentBackdrop.className = 'modal-backdrop fade show item-parent-backdrop';
            document.body.appendChild(parentBackdrop);
        }

        parentBackdrop.className = 'modal-backdrop fade show item-parent-backdrop';
        parentBackdrop.style.zIndex = '1040';
        parentBackdrop.style.opacity = '0.5';

        parent.style.zIndex = '1055';
        parent.style.opacity = '1';
        parent.style.filter = 'none';
        parent.style.pointerEvents = 'auto';
        parent.removeAttribute('aria-hidden');

        document.body.classList.add('modal-open');
        document.body.style.overflow = 'hidden';
    }

    function cleanNestedState() {
        var parent = parentItemModal();
        removeChildAccountBackdrops();

        if (parent) {
            ensureParentBackdrop();
            return;
        }

        if (!document.querySelector('.modal.show')) {
            document.querySelectorAll('.modal-backdrop').forEach(function (backdrop) {
                backdrop.remove();
            });
            document.body.classList.remove('modal-open');
            document.body.style.removeProperty('overflow');
            document.body.style.removeProperty('padding-right');
        }
    }

    var childModal = document.getElementById('itemLinkedAccountModal');
    if (childModal) {
        childModal.setAttribute('data-bs-backdrop', 'false');

        childModal.addEventListener('show.bs.modal', function () {
            childModal.setAttribute('data-bs-backdrop', 'false');
            ensureParentBackdrop();
        }, true);

        childModal.addEventListener('shown.bs.modal', function () {
            removeChildAccountBackdrops();
            ensureParentBackdrop();
        }, true);

        childModal.addEventListener('hide.bs.modal', function () {
            removeChildAccountBackdrops();
        }, true);

        childModal.addEventListener('hidden.bs.modal', function () {
            requestAnimationFrame(cleanNestedState);
            setTimeout(cleanNestedState, 60);
        }, true);
    }

    document.addEventListener('shown.bs.modal', function (event) {
        if (event.target && (event.target.id === 'itemModal' || event.target.id === 'editItemModal')) {
            setTimeout(ensureParentBackdrop, 40);
        }
    }, true);

    document.addEventListener('hidden.bs.modal', function () {
        setTimeout(cleanNestedState, 40);
    }, true);
})();
</script>



<style id="amgc-linked-account-hide-parent-final-fix">
/* FINAL NESTED ACCOUNT MODAL FIX
   When adding an Income/COGS/Asset account, temporarily hide the Add Item modal
   to prevent the backdrop from flickering. The Add Account modal has its own steady dark background. */
#itemModal.amgc-item-modal-temporarily-hidden,
#editItemModal.amgc-item-modal-temporarily-hidden {
    visibility: hidden !important;
    opacity: 0 !important;
    pointer-events: none !important;
}

.amgc-linked-account-dark-layer {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.62);
    z-index: 1088 !important;
}

#itemLinkedAccountModal.show {
    z-index: 1095 !important;
}

/* Hide any Bootstrap child backdrop so only our stable dark layer is used. */
.modal-backdrop.item-linked-account-backdrop,
.modal-backdrop.amgc-item-linked-backdrop-final {
    display: none !important;
}

/* Cancel button fixed style for Add Account modal */
#itemLinkedAccountModal .modal-footer .btn[data-bs-dismiss="modal"] {
    background: #f8fafc !important;
    color: #111827 !important;
    border: 1px solid #d1d5db !important;
    border-radius: 10px !important;
    min-width: 96px !important;
    height: 44px !important;
    font-weight: 500 !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: .35rem !important;
    padding: 0 1rem !important;
}
#itemLinkedAccountModal .modal-footer .btn[data-bs-dismiss="modal"]:hover {
    background: #e5e7eb !important;
    color: #052A47 !important;
}
#itemLinkedAccountModal .modal-footer .btn[data-bs-dismiss="modal"]::before {
    content: "\F62A";
    font-family: "bootstrap-icons" !important;
    font-size: .95rem;
    line-height: 1;
}
</style>

<script id="amgc-linked-account-hide-parent-final-fix">
(function () {
    function getParentItemModal() {
        return document.querySelector('#itemModal.show, #editItemModal.show');
    }

    function removeChildBootstrapBackdrops() {
        document.querySelectorAll('.modal-backdrop').forEach(function (backdrop) {
            var z = parseInt(backdrop.style.zIndex || window.getComputedStyle(backdrop).zIndex || '0', 10);
            if (backdrop.classList.contains('item-linked-account-backdrop') ||
                backdrop.classList.contains('amgc-item-linked-backdrop-final') ||
                z >= 1080) {
                backdrop.remove();
            }
        });
    }

    function showStableDarkLayer() {
        var layer = document.querySelector('.amgc-linked-account-dark-layer');
        if (!layer) {
            layer = document.createElement('div');
            layer.className = 'amgc-linked-account-dark-layer';
            document.body.appendChild(layer);
        }
    }

    function hideStableDarkLayer() {
        document.querySelectorAll('.amgc-linked-account-dark-layer').forEach(function (layer) {
            layer.remove();
        });
    }

    function pauseParentItemModal() {
        var parent = getParentItemModal();
        if (!parent) return;

        parent.classList.add('amgc-item-modal-temporarily-hidden');
        parent.style.visibility = 'hidden';
        parent.style.opacity = '0';
        parent.style.pointerEvents = 'none';
        parent.style.zIndex = '1055';
        parent.removeAttribute('aria-hidden');

        showStableDarkLayer();
        removeChildBootstrapBackdrops();
        document.body.classList.add('modal-open');
        document.body.style.overflow = 'hidden';
    }

    function resumeParentItemModal() {
        var parent = document.querySelector('#itemModal.show, #editItemModal.show, #itemModal.amgc-item-modal-temporarily-hidden, #editItemModal.amgc-item-modal-temporarily-hidden');

        hideStableDarkLayer();
        removeChildBootstrapBackdrops();

        if (parent) {
            parent.classList.remove('amgc-item-modal-temporarily-hidden');
            parent.style.visibility = '';
            parent.style.opacity = '';
            parent.style.pointerEvents = '';
            parent.style.zIndex = '1055';
            parent.removeAttribute('aria-hidden');

            var parentBackdrop = document.querySelector('.modal-backdrop.item-parent-backdrop') || document.querySelector('.modal-backdrop');
            if (!parentBackdrop) {
                parentBackdrop = document.createElement('div');
                parentBackdrop.className = 'modal-backdrop fade show item-parent-backdrop';
                document.body.appendChild(parentBackdrop);
            }
            parentBackdrop.className = 'modal-backdrop fade show item-parent-backdrop';
            parentBackdrop.style.zIndex = '1040';
            parentBackdrop.style.opacity = '0.5';

            document.body.classList.add('modal-open');
            document.body.style.overflow = 'hidden';
            return;
        }

        if (!document.querySelector('.modal.show')) {
            document.querySelectorAll('.modal-backdrop').forEach(function (backdrop) { backdrop.remove(); });
            document.body.classList.remove('modal-open');
            document.body.style.removeProperty('overflow');
            document.body.style.removeProperty('padding-right');
        }
    }

    var childModal = document.getElementById('itemLinkedAccountModal');
    if (!childModal) return;

    childModal.setAttribute('data-bs-backdrop', 'false');

    childModal.addEventListener('show.bs.modal', function () {
        childModal.setAttribute('data-bs-backdrop', 'false');
        pauseParentItemModal();
    }, true);

    childModal.addEventListener('shown.bs.modal', function () {
        pauseParentItemModal();
        var titleInput = document.getElementById('itemLinkedAccountTitle');
        if (titleInput) titleInput.focus();
    }, true);

    childModal.addEventListener('hide.bs.modal', function () {
        removeChildBootstrapBackdrops();
    }, true);

    childModal.addEventListener('hidden.bs.modal', function () {
        requestAnimationFrame(resumeParentItemModal);
        setTimeout(resumeParentItemModal, 80);
        setTimeout(resumeParentItemModal, 180);
    }, true);
})();

document.addEventListener('DOMContentLoaded', function () {
    const sidebar = document.querySelector('.sidebar-content');
    const activeLink = document.querySelector('.sidebar .nav-link.active');

    if (!sidebar || !activeLink) return;

    // Open parent dropdown if collapsed
    const collapse = activeLink.closest('.collapse');
    if (collapse) {
        collapse.classList.add('show');

        const trigger = document.querySelector(
            `[onclick*="${collapse.id}"]`
        );

        if (trigger) {
            trigger.setAttribute('aria-expanded', 'true');

            const arrow = trigger.querySelector('.dropdown-arrow');
            if (arrow) {
                arrow.style.transform = 'rotate(180deg)';
            }
        }
    }

    // Smooth scroll to active menu
    setTimeout(() => {
        activeLink.scrollIntoView({
            behavior: 'smooth',
            block: 'center'
        });
    }, 200);
});



</script>


<script>
// FINAL MOTORPOOL VIEW/EDIT OVERRIDES - safe and simple, sidebar untouched.
(function(){
    function mpEsc(v){ return String(v ?? '').replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c])); }
    function mpMoney(v){ return '₱' + Number(v || 0).toLocaleString(undefined,{minimumFractionDigits:2, maximumFractionDigits:2}); }
    function mpNum(v){ return Number(v || 0).toLocaleString(undefined,{minimumFractionDigits:0, maximumFractionDigits:2}); }
    function mpDate(v){ if(!v) return '—'; const d = new Date(String(v).replace(' ', 'T')); return isNaN(d.getTime()) ? mpEsc(v) : d.toLocaleString(); }
    function mpCloseLoading(){ if (typeof Swal !== 'undefined') Swal.close(); }
    function mpFetchItem(id){ const fd = new FormData(); fd.append('action','get_item'); fd.append('item_id',id); return fetch('motorpool_inventory.php',{method:'POST',body:fd}).then(async r => { const t = await r.text(); try { return JSON.parse(t); } catch(e){ throw new Error(t.replace(/<[^>]+>/g,' ').replace(/\s+/g,' ').trim() || 'Invalid server response'); } }); }

    window.viewItem = function(id){
        cleanupModalBackdrops && cleanupModalBackdrops();
        if (typeof showLoading === 'function') showLoading();
        mpFetchItem(id).then(data => {
            mpCloseLoading();
            if (!data.success) throw new Error(data.message || 'Unable to load item');
            const item = data.item || {};
            const units = data.motorpool_unit_types || data.unit_types || [];
            const txs = data.transactions || [];
            const inv = data.inventory_summary || {};
            const images = data.images || [];
            const mainImg = images.length ? `<img src="../uploads/motorpool_inventory/${mpEsc(images[0].image_path)}" style="max-width:100%;max-height:260px;object-fit:contain;border-radius:12px;border:1px solid #e5e7eb;background:#fff;">` : `<div class="text-center text-muted py-5"><i class="bi bi-image" style="font-size:56px"></i><div>No image available</div></div>`;
            const defaultUnit = units.find(u => Number(u.unit_type_id || 0) === Number(item.default_unit_type_id || 0)) || units[0] || {};
            const displayUnitCost = Number(defaultUnit.unit_cost ?? item.unit_cost ?? 0);
            const displayTotalCost = Number(inv.total_cost ?? item.total_cost ?? defaultUnit.total_cost ?? 0);
            const unitRows = units.map(u => {
                const qty = Number(u.current_inventory ?? u.beginning_inventory ?? item.stock ?? 0);
                const cost = Number(u.unit_cost ?? 0);
                const rowTotal = Number(u.total_cost || 0) > 0 ? Number(u.total_cost || 0) : (qty * cost);
                return `<tr><td>${mpEsc(u.unit_type_name || item.unit_type || 'Piece')}</td><td>${mpNum(qty)}</td><td>${mpMoney(cost)}</td><td>${mpMoney(rowTotal)}</td></tr>`;
            }).join('') || `<tr><td>${mpEsc(item.unit_type || 'Piece')}</td><td>${mpNum(item.stock)}</td><td>${mpMoney(item.unit_cost)}</td><td>${mpMoney(item.total_cost)}</td></tr>`;
            const txRows = txs.slice(0,25).map(t => `<tr><td>${mpEsc(t.created_at || '')}</td><td>${mpEsc(t.transaction_type || '')}</td><td>${mpNum(t.quantity || 0)}</td><td>${mpMoney(t.unit_cost || 0)}</td><td>${mpMoney(t.total_cost || 0)}</td></tr>`).join('') || `<tr><td colspan="5" class="text-center text-muted">No transactions found</td></tr>`;
            const createdBy = (item.created_by_name && String(item.created_by_name).trim()) ? item.created_by_name : (item.created_by ? ('User #' + item.created_by) : 'System');
            const systemInfoRows = `<tr><th style="width:180px">Created By</th><td>${mpEsc(createdBy)}</td></tr><tr><th>Created At</th><td>${mpDate(item.created_at)}</td></tr><tr><th>Last Updated</th><td>${mpDate(item.updated_at)}</td></tr>`;
            const html = `
                <div class="p-3">
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">${mainImg}</div>
                        <div class="col-md-8">
                            <h4 class="mb-1">${mpEsc(item.item_name)}</h4>
                            <div class="text-muted mb-3">${mpEsc(item.item_code)} ${item.barcode ? ' | Barcode: ' + mpEsc(item.barcode) : ''}</div>
                            <div class="row g-2">
                                <div class="col-sm-6"><div class="border rounded p-2"><small class="text-muted">Category</small><div class="fw-bold">${mpEsc(item.category || 'General')}</div></div></div>
                                <div class="col-sm-6"><div class="border rounded p-2"><small class="text-muted">Supplier</small><div class="fw-bold">${mpEsc(item.supplier || 'No Supplier')}</div></div></div>
                                <div class="col-sm-6"><div class="border rounded p-2"><small class="text-muted">Stock</small><div class="fw-bold">${mpNum(inv.total_inventory ?? item.stock)} ${mpEsc(item.unit_type || '')}</div></div></div>
                                <div class="col-sm-6"><div class="border rounded p-2"><small class="text-muted">Total Cost</small><div class="fw-bold">${mpMoney(displayTotalCost)}</div></div></div>
                                <div class="col-sm-6"><div class="border rounded p-2"><small class="text-muted">Unit Cost</small><div class="fw-bold">${mpMoney(displayUnitCost)}</div></div></div>
                                <div class="col-sm-6"><div class="border rounded p-2"><small class="text-muted">Reorder Level</small><div class="fw-bold">${mpNum(item.reorder_level)}</div></div></div>
                            </div>
                        </div>
                    </div>
                    <h6 class="fw-bold mt-3">Inventory by UoM</h6>
                    <div class="table-responsive"><table class="table table-sm table-bordered"><thead><tr><th>UoM</th><th>Current Stock</th><th>Unit Cost</th><th>Total Cost</th></tr></thead><tbody>${unitRows}</tbody></table></div>
                    <h6 class="fw-bold mt-3">Transactions</h6>
                    <div class="table-responsive"><table class="table table-sm table-bordered"><thead><tr><th>Date</th><th>Type</th><th>Qty</th><th>Unit Cost</th><th>Total Cost</th></tr></thead><tbody>${txRows}</tbody></table></div>
                    <h6 class="fw-bold mt-3">System Information</h6>
                    <div class="table-responsive"><table class="table table-sm table-bordered mb-0"><tbody>${systemInfoRows}</tbody></table></div>
                </div>`;
            const c = document.getElementById('viewItemContent');
            if (c) c.innerHTML = html;
            window.currentItemId = id;
            window.currentViewItemData = item;
            new bootstrap.Modal(document.getElementById('viewItemModal')).show();
        }).catch(err => { mpCloseLoading(); Swal.fire({icon:'error', title:'View Item Error', text: err.message || 'Unable to view item'}); });
    };

    window.editItem = function(id){
        cleanupModalBackdrops && cleanupModalBackdrops();
        if (typeof showLoading === 'function') showLoading();
        mpFetchItem(id).then(data => {
            mpCloseLoading();
            if (!data.success) throw new Error(data.message || 'Unable to load item');
            const item = data.item || {};
            const units = data.motorpool_unit_types || data.unit_types || [];
            const u = units[0] || {};
            const set = (id,val) => { const el=document.getElementById(id); if(el) el.value = val ?? ''; };
            set('editItemId', item.item_id); set('editItemCode', item.item_code); set('editItemName', item.item_name); set('editDescription', item.description || ''); set('editCategory', item.category || 'General'); set('editPrincipal', item.principal || ''); set('editVolume', item.volume || ''); set('editOilType', item.oil_type || ''); set('editReorderLevel', item.reorder_level || 0); set('editStatus', item.status || 'active');
            if (document.getElementById('editPointsEligible')) document.getElementById('editPointsEligible').checked = String(item.points_eligible ?? '1') === '1';
            set('editIncomeAccount', item.income_account_id || ''); set('editCogsAccount', item.cogs_account_id || ''); set('editAssetAccount', item.asset_account_id || '');
            const tbody = document.getElementById('editUnitTypesBody');
            if (tbody) {
                tbody.innerHTML = `<tr>
                    <td><input type="text" class="form-control form-control-sm" name="edit_unit_type[]" value="${mpEsc(u.unit_type_name || item.unit_type || 'Piece')}" required></td>
                    <td><input type="text" class="form-control form-control-sm text-uppercase" name="edit_uom_initial[]" value="${mpEsc(u.uom_initial || '')}"></td>
                    <td><input type="text" class="form-control form-control-sm" name="edit_barcode[]" value="${mpEsc(u.barcode || item.barcode || '')}"></td>
                    <td><input type="number" class="form-control form-control-sm" name="edit_qty_smallest_pack[]" min="1" value="${mpEsc(u.quantity_smallest_pack || 1)}"></td>
                    <td><input type="number" class="form-control form-control-sm" name="edit_current_inventory[]" min="0" step="0.01" value="${mpEsc(u.current_inventory ?? item.stock ?? 0)}"></td>
                    <td><input type="date" class="form-control form-control-sm" name="edit_as_of_date[]" value="${mpEsc(String(u.as_of_date || '').substring(0,10))}"></td>
                    <td class="text-center"><input type="radio" class="form-check-input edit-default-uom-radio" name="edit_default_uom" value="1" checked></td>
                    <td><input type="number" class="form-control form-control-sm" name="edit_unit_cost[]" min="0" step="0.01" value="${mpEsc(u.unit_cost ?? item.unit_cost ?? 0)}"></td>
                    <td><input type="number" class="form-control form-control-sm" name="edit_total_cost[]" min="0" step="0.01" value="${mpEsc(u.total_cost ?? item.total_cost ?? 0)}"></td>
                    <td class="text-center"><span class="text-muted">Default</span></td>
                </tr>`;
            }
            const existing = document.getElementById('existingImagesContainer');
            if (existing) existing.innerHTML = '<p class="text-muted mb-0">Images can still be managed from item details.</p>';
            window.currentItemId = id;
            new bootstrap.Modal(document.getElementById('editItemModal')).show();
        }).catch(err => { mpCloseLoading(); Swal.fire({icon:'error', title:'Edit Item Error', text: err.message || 'Unable to edit item'}); });
    };

    window.editFromView = function(){
        const modal = bootstrap.Modal.getInstance(document.getElementById('viewItemModal'));
        if (modal) modal.hide();
        setTimeout(() => window.editItem(window.currentItemId), 250);
    };

    window.updateItem = function(){
        const get = id => document.getElementById(id);
        const itemId = get('editItemId')?.value || '';
        const itemName = (get('editItemName')?.value || '').trim();
        if (!itemName) { Swal.fire({icon:'warning', title:'Required', text:'Item name is required.'}); return; }
        const row = document.querySelector('#editUnitTypesBody tr');
        const q = sel => row ? (row.querySelector(sel)?.value || '') : '';
        const stock = parseFloat(q('[name="edit_current_inventory[]"]') || 0) || 0;
        const unitCost = parseFloat(q('[name="edit_unit_cost[]"]') || 0) || 0;
        const totalCostTyped = parseFloat(q('[name="edit_total_cost[]"]') || 0) || 0;
        const fd = new FormData();
        fd.append('action','motorpool_quick_update_item');
        fd.append('item_id', itemId);
        fd.append('item_code', get('editItemCode')?.value || '');
        fd.append('item_name', itemName);
        fd.append('description', get('editDescription')?.value || '');
        fd.append('category', get('editCategory')?.value || 'General');
        fd.append('principal', get('editPrincipal')?.value || '');
        fd.append('volume', get('editVolume')?.value || '');
        fd.append('oil_type', get('editOilType')?.value || '');
        fd.append('barcode', q('[name="edit_barcode[]"]'));
        fd.append('unit_type', q('[name="edit_unit_type[]"]') || 'Piece');
        fd.append('stock', stock);
        fd.append('reorder_level', get('editReorderLevel')?.value || 0);
        fd.append('unit_cost', unitCost);
        fd.append('unit_price', unitCost);
        fd.append('total_cost', totalCostTyped > 0 ? totalCostTyped : (stock * unitCost));
        fd.append('status', get('editStatus')?.value || 'active');
        fd.append('points_eligible', get('editPointsEligible') && get('editPointsEligible').checked ? '1':'0');
        fd.append('income_account_id', get('editIncomeAccount')?.value || '');
        fd.append('cogs_account_id', get('editCogsAccount')?.value || '');
        fd.append('asset_account_id', get('editAssetAccount')?.value || '');
        if (typeof showLoading === 'function') showLoading();
        fetch('motorpool_inventory.php',{method:'POST',body:fd}).then(async r => { const t = await r.text(); try { return JSON.parse(t); } catch(e){ throw new Error(t.replace(/<[^>]+>/g,' ').replace(/\s+/g,' ').trim() || 'Invalid server response'); } }).then(data => {
            mpCloseLoading();
            if (!data.success) throw new Error(data.message || 'Unable to update item');
            Swal.fire({icon:'success', title:'Success', text:data.message || 'Item updated.', timer:1500, showConfirmButton:false}).then(()=>location.reload());
        }).catch(err => { mpCloseLoading(); Swal.fire({icon:'error', title:'Update Error', text:err.message || 'Unable to update item'}); });
    };
})();
</script>

<script>
// ===== MOTORPOOL FINAL NON-DESTRUCTIVE UI FIX =====
// Fixes sidebar dropdowns, filter collapse, category/supplier tabs, and image fallbacks.
// Placed at the actual end of the page so it does not break JavaScript print templates.
(function(){
    'use strict';

    window.motorpoolImageFallback = function(img){
        try {
            var list = JSON.parse(img.getAttribute('data-fallbacks') || '[]');
            var idx = parseInt(img.getAttribute('data-fallback-index') || '0', 10) + 1;
            while (idx < list.length && (!list[idx] || list[idx] === img.getAttribute('src'))) idx++;
            if (idx < list.length) {
                img.setAttribute('data-fallback-index', String(idx));
                img.src = list[idx];
                return;
            }
        } catch(e) {}
        var holder = img.closest('.item-thumbnail');
        if (holder) holder.innerHTML = '<i class="bi bi-image text-muted"></i>';
        else img.style.display = 'none';
    };

    window.toggleSidebarDropdown = function(event, targetId){
        if (event) {
            event.preventDefault();
            event.stopPropagation();
        }
        var target = document.getElementById(targetId);
        if (!target) return false;
        var parentLink = event && event.currentTarget ? event.currentTarget : document.querySelector('[onclick*="' + targetId + '"]');
        var arrow = parentLink ? parentLink.querySelector('.dropdown-arrow') : null;
        var shouldShow = !target.classList.contains('show');

        // Close sibling menus only, keep sidebar itself untouched.
        document.querySelectorAll('.sidebar .collapse.show').forEach(function(el){
            if (el !== target && el.parentElement && el.parentElement.classList.contains('dropdown-nav')) {
                el.classList.remove('show');
                el.style.display = 'none';
                var a = document.querySelector('[onclick*="' + el.id + '"] .dropdown-arrow');
                if (a) a.style.transform = '';
            }
        });

        target.classList.toggle('show', shouldShow);
        target.style.display = shouldShow ? 'block' : 'none';
        if (arrow) arrow.style.transform = shouldShow ? 'rotate(180deg)' : '';
        return false;
    };

    function setupSidebarDropdowns(){
        document.querySelectorAll('.sidebar .dropdown-nav > .nav-link').forEach(function(link){
            var attr = link.getAttribute('onclick') || '';
            var m = attr.match(/toggleSidebarDropdown\(event,\s*['\"]([^'\"]+)['\"]\)/);
            if (!m) return;
            var id = m[1];
            link.onclick = function(e){ return window.toggleSidebarDropdown(e, id); };
        });
    }

    function setFilterOpen(open){
        var content = document.getElementById('filterContent');
        var icon = document.getElementById('filterIcon');
        var btn = document.getElementById('filterToggleBtn');
        if (!content) return;
        content.classList.toggle('collapsed', !open);
        content.style.display = open ? 'block' : 'none';
        if (icon) icon.className = open ? 'bi bi-chevron-up' : 'bi bi-chevron-down';
        if (btn) btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    }

    function setupFilterDropdown(){
        var header = document.getElementById('filterHeader');
        var btn = document.getElementById('filterToggleBtn');
        var content = document.getElementById('filterContent');
        if (!content) return;
        if (!content.classList.contains('collapsed')) setFilterOpen(true);
        else setFilterOpen(false);
        [header, btn].forEach(function(el){
            if (!el) return;
            el.onclick = function(e){
                e.preventDefault();
                e.stopPropagation();
                setFilterOpen(content.classList.contains('collapsed'));
            };
        });
    }

    window.switchCategoryTab = function(tabId, tabElement){
        var view = document.getElementById('categoryView');
        if (!view) return false;
        view.querySelectorAll('.tab-content').forEach(function(tab){
            tab.classList.remove('active');
            tab.style.display = 'none';
        });
        view.querySelectorAll('.category-tab').forEach(function(btn){ btn.classList.remove('active'); });
        var target = document.getElementById(tabId);
        if (target) {
            target.classList.add('active');
            target.style.display = 'block';
        }
        if (tabElement) tabElement.classList.add('active');
        if (typeof filterItems === 'function') setTimeout(filterItems, 0);
        return false;
    };

    window.switchSupplierTab = function(tabId, tabElement){
        var view = document.getElementById('supplierView');
        if (!view) return false;
        view.querySelectorAll('.tab-content').forEach(function(tab){
            tab.classList.remove('active');
            tab.style.display = 'none';
        });
        view.querySelectorAll('.category-tab').forEach(function(btn){ btn.classList.remove('active'); });
        var target = document.getElementById(tabId);
        if (target) {
            target.classList.add('active');
            target.style.display = 'block';
        }
        if (tabElement) tabElement.classList.add('active');
        if (typeof filterItems === 'function') setTimeout(filterItems, 0);
        return false;
    };

    window.toggleView = function(viewType){
        var categoryView = document.getElementById('categoryView');
        var supplierView = document.getElementById('supplierView');
        var catBtn = document.getElementById('viewCategoryBtn');
        var supBtn = document.getElementById('viewSupplierBtn');
        var isSupplier = viewType === 'supplier';
        if (categoryView) categoryView.style.display = isSupplier ? 'none' : 'block';
        if (supplierView) supplierView.style.display = isSupplier ? 'block' : 'none';
        if (catBtn) catBtn.classList.toggle('active', !isSupplier);
        if (supBtn) supBtn.classList.toggle('active', isSupplier);
        if (typeof filterItems === 'function') setTimeout(filterItems, 0);
    };

    function setupTabs(){
        document.querySelectorAll('#categoryView .category-tab').forEach(function(tab){
            var id = tab.getAttribute('data-tab');
            if (!id) return;
            tab.onclick = function(e){ e.preventDefault(); e.stopPropagation(); return window.switchCategoryTab(id, tab); };
        });
        document.querySelectorAll('#supplierView .category-tab').forEach(function(tab){
            var id = tab.getAttribute('data-tab');
            if (!id) return;
            tab.onclick = function(e){ e.preventDefault(); e.stopPropagation(); return window.switchSupplierTab(id, tab); };
        });
        var catBtn = document.getElementById('viewCategoryBtn');
        var supBtn = document.getElementById('viewSupplierBtn');
        if (catBtn) catBtn.onclick = function(e){ e.preventDefault(); return window.toggleView('category'); };
        if (supBtn) supBtn.onclick = function(e){ e.preventDefault(); return window.toggleView('supplier'); };
    }

    function removePendingSystemTasksModal(){
        var modal = document.getElementById('systemTaskTableModal');
        if (modal) modal.remove();
        document.querySelectorAll('.system-task-table-modal').forEach(function(el){ el.remove(); });
        document.querySelectorAll('.modal-backdrop').forEach(function(backdrop){
            if (!document.querySelector('.modal.show')) backdrop.remove();
        });
    }

    document.addEventListener('DOMContentLoaded', function(){
        removePendingSystemTasksModal();
        setupSidebarDropdowns();
        setupFilterDropdown();
        setupTabs();
    });

    // Run once immediately too, for pages where scripts execute after DOM is already ready.
    removePendingSystemTasksModal();
    setupSidebarDropdowns();
    setupFilterDropdown();
    setupTabs();
})();
</script>


<script>
/* FINAL MOTORPOOL UI FIX - fast view/edit, real image fallback, no pending tasks modal */
(function(){
    'use strict';

    function esc(v){
        return String(v == null ? '' : v).replace(/[&<>'"]/g, function(c){
            return {'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c];
        });
    }
    function money(v){
        const n = Number(v || 0);
        return '₱' + n.toLocaleString(undefined,{minimumFractionDigits:2, maximumFractionDigits:2});
    }
    function qty(v){
        const n = Number(v || 0);
        return n.toLocaleString(undefined,{minimumFractionDigits:0, maximumFractionDigits:2});
    }
    function norm(v){ return String(v || '').trim().toLowerCase(); }

    window.motorpoolImageFallback = function(img){
        try {
            const raw = img.getAttribute('data-fallbacks') || '[]';
            const list = JSON.parse(raw);
            let idx = Number(img.getAttribute('data-fallback-index') || 0) + 1;
            while (idx < list.length && (!list[idx] || list[idx] === img.src)) idx++;
            if (idx < list.length) {
                img.setAttribute('data-fallback-index', String(idx));
                img.src = list[idx];
                return;
            }
        } catch(e) {}
        img.onerror = null;
        img.outerHTML = '<i class="bi bi-image text-muted"></i>';
    };

    function imageCandidates(path){
        const out = [];
        const add = p => { if (p && !out.includes(p)) out.push(p); };
        path = String(path || '').trim().replace(/\\/g,'/');
        if (!path) return out;
        if (/^https?:\/\//i.test(path)) { add(path); return out; }
        const noLead = path.replace(/^\/+/, '');
        const base = noLead.split('/').pop();
        if (path.indexOf('../') === 0 || path.indexOf('./') === 0) add(path);
        add('../uploads/motorpool_inventory/' + base);
        add('../uploads/items/' + base);
        add('../uploads/item_images/' + base);
        add('../uploads/' + base);
        add(noLead);
        add('../' + noLead);
        return out;
    }

    function makeImage(path, alt, cls){
        const list = imageCandidates(path);
        if (!list.length) return '<div class="mp-no-image"><i class="bi bi-image"></i></div>';
        return `<img src="${esc(list[0])}" data-fallbacks='${esc(JSON.stringify(list))}' data-fallback-index="0" onerror="motorpoolImageFallback(this)" alt="${esc(alt || 'Item Image')}" class="${esc(cls || '')}">`;
    }

    function rowData(id){
        const row = document.querySelector(`tr.inventory-row[data-id="${CSS.escape(String(id))}"]`);
        if (!row) return null;
        const img = row.querySelector('.item-thumbnail img');
        return {
            item_id: id,
            item_code: row.dataset.code || '',
            barcode: row.dataset.barcode || '',
            item_name: row.dataset.name || '',
            description: row.dataset.description || '',
            category: row.dataset.category || 'General',
            principal: row.dataset.principal || '',
            supplier: row.dataset.supplier || row.dataset.principal || '',
            status: row.dataset.status || 'active',
            stock: row.dataset.stock || '0',
            reorder_level: row.dataset.reorder || '0',
            unit_price: row.dataset.price || '0',
            unit_cost: row.dataset.cost || row.dataset.price || '0',
            unit_type: row.dataset.unit || 'Piece',
            total_cost: row.dataset.totalCost || row.dataset.total_cost || (Number(row.dataset.stock || 0) * Number(row.dataset.price || 0)),
            image_path: img ? (img.getAttribute('src') || '') : ''
        };
    }

    function dedupeUnitTypes(unitTypes, item){
        const seen = new Set();
        const clean = [];
        (unitTypes || []).forEach(ut => {
            const name = String(ut.unit_type_name || item.unit_type || 'Piece').trim() || 'Piece';
            const key = norm(name);
            if (seen.has(key)) return;
            seen.add(key);
            clean.push(Object.assign({}, ut, {unit_type_name:name}));
        });
        if (!clean.length) {
            clean.push({
                unit_type_id: item.default_unit_type_id || 0,
                unit_type_name: item.unit_type || 'Piece',
                uom_initial: '',
                barcode: item.barcode || '',
                quantity_smallest_pack: 1,
                is_default_uom: 1,
                unit_status: 'active',
                unit_quantity: 1,
                current_inventory: item.stock || item.current_stock || 0,
                beginning_inventory: item.stock || item.current_stock || 0,
                unit_cost: item.unit_cost || item.unit_price || 0,
                total_cost: item.total_cost || (Number(item.stock || 0) * Number(item.unit_cost || item.unit_price || 0)),
                reorder_level: item.reorder_level || 0
            });
        }
        return clean;
    }

    function dedupePricingRows(rows){
        const seen = new Set();
        const out = [];
        (rows || []).forEach(r => {
            const key = norm((r.price_level || 'Standard') + '|' + (r.effective_date || ''));
            if (seen.has(key)) return;
            seen.add(key);
            out.push(r);
        });
        return out;
    }

    function buildDetails(data){
        const item = data.item || data || {};
        const units = dedupeUnitTypes(data.motorpool_unit_types || data.unit_types || [], item);

        const imageList = [];
        (data.images || []).forEach(i => {
            const p = i.image_path || i.path || i.image || i.url || '';
            if (p) imageList.push(p);
        });
        ['product_image_url','item_image','image_path'].forEach(k => { if (item[k]) imageList.push(item[k]); });
        const uniqueImages = [...new Set(imageList.filter(Boolean))];
        const mainImage = uniqueImages[0] || '';

        const status = String(item.status || 'active').toLowerCase();
        const statusBadge = `<span class="mp-status-badge ${status === 'active' ? 'active' : 'inactive'}"><i class="bi bi-check-circle-fill"></i> ${esc(status.charAt(0).toUpperCase() + status.slice(1))}</span>`;

        const stock = Number(item.stock || item.current_stock || units.reduce((s,u)=>s+Number(u.current_inventory||0),0));
        const unitName = item.unit_type || (units.find(u => Number(u.is_default_uom) === 1)?.unit_type_name) || (units[0] && units[0].unit_type_name) || 'Piece';
        const unitCost = Number(item.unit_cost || item.unit_price || (units[0] && units[0].unit_cost) || 0);
        const totalCost = Number(item.total_cost || 0) || (Number(stock || 0) * Number(unitCost || 0));

        const thumbs = uniqueImages.map((p,idx)=>`<button type="button" class="mp-gallery-thumb ${idx===0?'active':''}" onclick="(function(btn){var img=document.getElementById('mpMainDetailImage'); if(img){var list=JSON.parse(btn.getAttribute('data-fallbacks')||'[]'); img.src=list[0]||''; img.setAttribute('data-fallbacks', btn.getAttribute('data-fallbacks')||'[]'); img.setAttribute('data-fallback-index','0');} document.querySelectorAll('.mp-gallery-thumb').forEach(t=>t.classList.remove('active')); btn.classList.add('active');})(this)" data-fallbacks='${esc(JSON.stringify(imageCandidates(p)))}'>${makeImage(p,item.item_name,'')}</button>`).join('');

        const barcodeHtml = item.barcode
            ? `<div class="barcode-preview-card"><svg id="detailBarcodeSvg"></svg><div class="barcode-text">${esc(item.barcode)}</div></div>`
            : `<span class="text-muted">No barcode saved</span>`;

        const unitRows = units.map(u => `<tr>
            <td>${esc(u.unit_type_name || 'Piece')}</td>
            <td>${qty(u.current_inventory ?? stock)}</td>
            <td>${money(u.unit_cost ?? unitCost)}</td>
            <td>${money(u.total_cost ?? (Number(u.current_inventory || 0) * Number(u.unit_cost || 0)))}</td>
        </tr>`).join('') || `<tr><td>${esc(unitName)}</td><td>${qty(stock)}</td><td>${money(unitCost)}</td><td>${money(totalCost)}</td></tr>`;

        const beginningRows = units.map(u => `<tr>
            <td>${esc(u.unit_type_name || 'Piece')}</td>
            <td>${qty(u.beginning_inventory ?? u.current_inventory ?? stock)}</td>
            <td>${esc(String(u.as_of_date || item.created_at || '').slice(0,10))}</td>
            <td>${money(u.unit_cost ?? unitCost)}</td>
            <td>${money(u.total_cost ?? (Number(u.current_inventory || 0) * Number(u.unit_cost || 0)))}</td>
        </tr>`).join('') || `<tr><td>${esc(unitName)}</td><td>${qty(stock)}</td><td>${esc(String(item.created_at || '').slice(0,10))}</td><td>${money(unitCost)}</td><td>${money(totalCost)}</td></tr>`;

        const pricingRows = dedupePricingRows(data.pricing_rows || []);
        const pricingHtml = pricingRows.length ? pricingRows.map(pr => {
            const prices = pr.prices || {};
            return units.map(u => {
                const matchKey = Object.keys(prices).find(k => norm(k) === norm(u.unit_type_name));
                const p = prices[u.unit_type_name] || prices[matchKey] || {};
                const priceValue = (p && p.unit_price !== undefined && p.unit_price !== null && p.unit_price !== '') ? p.unit_price : '';
                return `<tr><td>${esc(u.unit_type_name || 'Piece')}</td><td>${esc(pr.price_level || 'Standard')}</td><td>${priceValue === '' ? '—' : money(priceValue)}</td><td>${esc(pr.effective_date || 'Immediate')}</td></tr>`;
            }).join('');
        }).join('') : units.map(u => `<tr><td>${esc(u.unit_type_name || 'Piece')}</td><td>Standard</td><td>${money(item.unit_price || u.unit_cost || unitCost)}</td><td>Immediate</td></tr>`).join('');

        const historyRows = (data.pricing_history || []).slice(0,50).map(h => `<tr>
            <td>${esc(h.created_at || h.effective_date || '')}</td>
            <td>${esc(h.price_level || 'Standard')}</td>
            <td>${esc(h.unit_type_name || '')}</td>
            <td>${money(h.unit_price || 0)}</td>
        </tr>`).join('') || '<tr><td colspan="4" class="text-center text-muted py-3">No pricing history found</td></tr>';

        const txRows = (data.transactions || []).slice(0,50).map(tx => `<tr>
            <td>${esc(String(tx.created_at || '').slice(0,19))}</td>
            <td>${esc(tx.transaction_type || tx.source_label || '')}</td>
            <td>${qty(tx.quantity || tx.quantity_changed || 0)}</td>
            <td>${money(tx.unit_cost || 0)}</td>
            <td>${money(tx.total_cost || 0)}</td>
            <td>${esc(tx.notes || tx.remarks || '')}</td>
        </tr>`).join('') || '<tr><td colspan="6" class="text-center text-muted py-3">No transactions found</td></tr>';

        const formatSystemDate = (v) => {
            const raw = String(v || '').trim();
            if (!raw || raw === '0000-00-00' || raw === '0000-00-00 00:00:00') return 'Not recorded';
            return raw.slice(0, 19);
        };
        const createdSystemDate = formatSystemDate(item.system_created_at || item.created_at || (data.transactions || [])[0]?.created_at || '');
        const updatedSystemDate = formatSystemDate(item.system_updated_at || item.updated_at || item.system_created_at || item.created_at || '');
        let createdBySystem = String(item.created_by_name || '').trim();
        if (!createdBySystem || createdBySystem.toLowerCase() === 'system') {
            const txActor = (data.transactions || []).map(tx => String(tx.actor_name || '').trim()).find(Boolean);
            if (txActor) createdBySystem = txActor;
        }
        if (!createdBySystem) createdBySystem = 'System';

        const galleryHtml = mainImage ? `
            <div class="mp-gallery-wrapper">
                <div class="mp-gallery-main">${makeImage(mainImage, item.item_name, 'mp-main-detail-img')}</div>
                ${uniqueImages.length > 1 ? `<div class="mp-gallery-thumbs">${thumbs}</div>` : ''}
            </div>` : '';

        return `
        <div class="mp-detail-clean">
            ${galleryHtml}

            <div class="mp-section clean-section">
                <button type="button" class="mp-section-title clean-title" data-bs-toggle="collapse" data-bs-target="#mpBasicInfo" aria-expanded="true"><span><i class="bi bi-tag"></i> Basic Information</span><i class="bi bi-chevron-up"></i></button>
                <div class="collapse show" id="mpBasicInfo"><div class="mp-section-body p-0">
                    <table class="mp-detail-table">
                        <tbody>
                            <tr><th>Item Code:</th><td><strong>${esc(item.item_code || '')}</strong></td></tr>
                            <tr><th>Generated<br>Barcode:</th><td>${barcodeHtml}</td></tr>
                            <tr><th>Item Name:</th><td>${esc(item.item_name || '')} ${statusBadge}</td></tr>
                            <tr><th>Category:</th><td>${esc(item.category || 'General')}</td></tr>
                            <tr><th>Principal:</th><td>${esc(item.principal || item.supplier || 'No Principal')}</td></tr>
                            <tr><th>Description:</th><td>${esc(item.description || 'No description')}</td></tr>
                        </tbody>
                    </table>
                </div></div>
            </div>

            <div class="mp-section clean-section">
                <button type="button" class="mp-section-title clean-title" data-bs-toggle="collapse" data-bs-target="#mpInventoryInfo"><span><i class="bi bi-box"></i> Inventory Information</span><i class="bi bi-chevron-down"></i></button>
                <div class="collapse" id="mpInventoryInfo"><div class="mp-section-body">
                    <table class="table table-sm"><tbody>
                        <tr><th>Current Stock</th><td>${qty(stock)} ${esc(unitName)}</td></tr>
                        <tr><th>Unit Cost</th><td>${money(unitCost)}</td></tr>
                        <tr><th>Total Cost</th><td>${money(totalCost)}</td></tr>
                        <tr><th>Reorder Level</th><td>${qty(item.reorder_level || 0)}</td></tr>
                    </tbody></table>
                </div></div>
            </div>

            <div class="mp-section clean-section">
                <button type="button" class="mp-section-title clean-title" data-bs-toggle="collapse" data-bs-target="#mpBeginningInfo"><span><i class="bi bi-archive"></i> Beginning Inventory by UoM</span><i class="bi bi-chevron-down"></i></button>
                <div class="collapse" id="mpBeginningInfo"><div class="mp-section-body"><table class="table table-sm"><thead><tr><th>UOM</th><th>Beginning Inventory</th><th>As Of</th><th>Unit Cost</th><th>Total Cost</th></tr></thead><tbody>${beginningRows}</tbody></table></div></div>
            </div>

            <div class="mp-section clean-section">
                <button type="button" class="mp-section-title clean-title" data-bs-toggle="collapse" data-bs-target="#mpPricingInfo"><span><i class="bi bi-calculator"></i> Unit Types & Pricing</span><i class="bi bi-chevron-down"></i></button>
                <div class="collapse" id="mpPricingInfo"><div class="mp-section-body"><table class="table table-sm"><thead><tr><th>UOM</th><th>Price Level</th><th>Unit Price</th><th>Effective Date</th></tr></thead><tbody>${pricingHtml}</tbody></table></div></div>
            </div>

            <div class="mp-section clean-section">
                <button type="button" class="mp-section-title clean-title" data-bs-toggle="collapse" data-bs-target="#mpHistoryInfo"><span><i class="bi bi-clock-history"></i> Pricing History</span><i class="bi bi-chevron-down"></i></button>
                <div class="collapse" id="mpHistoryInfo"><div class="mp-section-body"><table class="table table-sm"><thead><tr><th>Date</th><th>Price Level</th><th>UOM</th><th>Unit Price</th></tr></thead><tbody>${historyRows}</tbody></table></div></div>
            </div>

            <div class="mp-section clean-section">
                <button type="button" class="mp-section-title clean-title" data-bs-toggle="collapse" data-bs-target="#mpTransactionsInfo"><span><i class="bi bi-arrow-left-right"></i> Transactions</span><i class="bi bi-chevron-down"></i></button>
                <div class="collapse" id="mpTransactionsInfo"><div class="mp-section-body"><table class="table table-sm"><thead><tr><th>Date</th><th>Type</th><th>Qty</th><th>Unit Cost</th><th>Total Cost</th><th>Remarks</th></tr></thead><tbody>${txRows}</tbody></table></div></div>
            </div>

            <div class="mp-section clean-section">
                <button type="button" class="mp-section-title clean-title" data-bs-toggle="collapse" data-bs-target="#mpSystemInfo"><span><i class="bi bi-info-circle"></i> System Information</span><i class="bi bi-chevron-down"></i></button>
                <div class="collapse" id="mpSystemInfo"><div class="mp-section-body"><table class="table table-sm"><tbody><tr><th>Created</th><td>${esc(createdSystemDate)}</td></tr><tr><th>Updated</th><td>${esc(updatedSystemDate)}</td></tr><tr><th>Created By</th><td>${esc(createdBySystem)}</td></tr></tbody></table></div></div>
            </div>
        </div>`;
    }

    function fetchItemFast(id){
        const fd = new FormData();
        fd.append('action','get_item');
        fd.append('item_id', id);
        const controller = new AbortController();
        const timer = setTimeout(()=>controller.abort(), 12000);
        return fetch('motorpool_inventory.php', {method:'POST', body:fd, signal:controller.signal})
            .then(r => r.text())
            .then(t => { clearTimeout(timer); try { return JSON.parse(t); } catch(e){ throw new Error('Invalid JSON response'); } });
    }

    window.viewItem = function(id){
        window.currentViewItemData = rowData(id) || {item_id:id};
        const modalEl = document.getElementById('viewItemModal');
        const content = document.getElementById('viewItemContent');
        if (!modalEl || !content) return;
        content.innerHTML = buildDetails(window.currentViewItemData);
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
        fetchItemFast(id).then(data => {
            if (data && data.success) {
                window.currentViewItemData = data.item || window.currentViewItemData;
                content.innerHTML = buildDetails(data);
            }
        }).catch(()=>{});
    };

    function fillEditFromData(data){
        const item = data.item || data || {};
        const units = dedupeUnitTypes(data.motorpool_unit_types || data.unit_types || [], item);
        const pricingRows = dedupePricingRows(data.pricing_rows || []);
        const set = (id,v) => { const el = document.getElementById(id); if (el) el.value = v == null ? '' : v; };
        set('editItemId', item.item_id || '');
        set('editItemCode', item.item_code || '');
        set('editItemName', item.item_name || '');
        set('editDescription', item.description || '');
        set('editCategory', item.category || '');
        set('editPrincipal', item.principal || item.supplier || '');
        set('editVolume', item.volume || '');
        set('editOilType', item.oil_type || '');
        set('editReorderLevel', item.reorder_level || 0);
        set('editStatus', item.status || 'active');
        set('editIncomeAccount', item.income_account_id || '');
        set('editCogsAccount', item.cogs_account_id || '');
        set('editAssetAccount', item.asset_account_id || '');
        const points = document.getElementById('editPointsEligible');
        if (points) points.checked = String(item.points_eligible ?? '1') === '1';

        const body = document.getElementById('editUnitTypesBody');
        if (body) {
            body.innerHTML = '';
            units.forEach((ut, idx) => {
                const tr = document.createElement('tr');
                const currentInventory = parseFloat(ut.current_inventory ?? item.stock ?? 0) || 0;
                const unitCost = parseFloat(ut.unit_cost ?? item.unit_cost ?? item.unit_price ?? 0) || 0;
                const totalCost = parseFloat(ut.total_cost ?? item.total_cost ?? (currentInventory * unitCost)) || 0;
                tr.innerHTML = `
                    <td><input type="text" class="form-control form-control-sm" placeholder="e.g., Piece, Box, Carton" name="edit_unit_type[]" value="${esc(ut.unit_type_name || 'Piece')}" required></td>
                    <td><input type="text" class="form-control form-control-sm text-uppercase" placeholder="CS" maxlength="20" name="edit_uom_initial[]" value="${esc(ut.uom_initial || '')}"></td>
                    <td>
                        <div class="input-group barcode-group">
                            <input type="text" class="form-control form-control-sm uom-barcode-input" placeholder="Type or Scan barcode" name="edit_barcode[]" value="${esc(ut.barcode || item.barcode || '')}" inputmode="numeric" autocomplete="off">
                            <button type="button" class="btn scan-barcode-btn" onclick="scanBarcode(this)" title="Scan Barcode"><i class="bi bi-upc-scan"></i></button>
                        </div>
                    </td>
                    <td><input type="number" step="0.01" min="1" class="form-control form-control-sm" placeholder="1" name="edit_qty_smallest_pack[]" value="${esc(ut.quantity_smallest_pack || 1)}"></td>
                    <td><input type="number" step="0.01" min="0" class="form-control form-control-sm" placeholder="0" name="edit_current_inventory[]" value="${esc(currentInventory)}"></td>
                    <td><input type="date" class="form-control form-control-sm" name="edit_as_of_date[]" value="${esc(String(ut.as_of_date || '').slice(0,10))}"></td>
                    <td class="text-center"><input type="radio" class="form-check-input edit-default-uom-radio" name="edit_default_uom" value="${idx}" ${idx===0 || Number(ut.is_default_uom)===1 ? 'checked' : ''} onchange="handleEditDefaultUOMChange(this)"></td>
                    <td><input type="number" step="0.01" min="0" class="form-control form-control-sm" placeholder="0.00" name="edit_unit_cost[]" value="${esc(unitCost)}"></td>
                    <td><input type="number" step="0.01" min="0" class="form-control form-control-sm" placeholder="0.00" name="edit_total_cost[]" value="${esc(totalCost.toFixed(2))}" readonly></td>
                    <td class="text-center">${idx===0 ? '<span class="text-muted">Default</span>' : '<button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest(\'tr\').remove(); if(window.updateEditPricingTableColumns) updateEditPricingTableColumns();"><i class="bi bi-trash"></i></button>'}</td>`;
                if (typeof bindUnitInventoryRowEvents === 'function') bindUnitInventoryRowEvents(tr);
                const unitTypeInput = tr.querySelector('input[name="edit_unit_type[]"]');
                if (unitTypeInput) {
                    unitTypeInput.addEventListener('change', () => { if (window.updateEditPricingTableColumns) updateEditPricingTableColumns(); });
                    unitTypeInput.addEventListener('keyup', () => { if (window.updateEditPricingTableColumns) updateEditPricingTableColumns(); });
                }
                body.appendChild(tr);
            });
        }
        window.currentEditPricingRows = pricingRows;
        const priceBody = document.getElementById('editPricingBody');
        if (priceBody) {
            priceBody.innerHTML = '';
            if (pricingRows.length && typeof window.addEditPricingRow === 'function') {
                pricingRows.forEach(() => window.addEditPricingRow());
            } else if (typeof window.addEditPricingRow === 'function') {
                window.addEditPricingRow();
            }
        }
        if (typeof window.updateEditPricingTableColumns === 'function') window.updateEditPricingTableColumns();
    }

    window.editItem = function(id){
        const modalEl = document.getElementById('editItemModal');
        if (!modalEl) return;
        fillEditFromData(rowData(id) || {item_id:id});
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
        fetchItemFast(id).then(data => { if (data && data.success) fillEditFromData(data); }).catch(()=>{});
    };

    window.editFromView = function(){
        const item = window.currentViewItemData || {};
        if (item.item_id) {
            const vm = document.getElementById('viewItemModal');
            if (vm) bootstrap.Modal.getOrCreateInstance(vm).hide();
            setTimeout(()=>window.editItem(item.item_id), 200);
        }
    };

    function removeTaskModalForReal(){
        const ids = ['systemTaskTableModal','pendingSystemTasksModal','systemTasksModal'];
        ids.forEach(id => { const el = document.getElementById(id); if (el) el.remove(); });
        document.querySelectorAll('.system-task-table-modal,.system-task-alert').forEach(el => el.remove());
    }

    function fixTableImages(){
        document.querySelectorAll('.item-thumbnail img').forEach(img => {
            if (!img.getAttribute('onerror')) img.setAttribute('onerror','motorpoolImageFallback(this)');
            if (!img.getAttribute('data-fallbacks')) {
                const list = imageCandidates(img.getAttribute('src') || '');
                img.setAttribute('data-fallbacks', JSON.stringify(list));
                img.setAttribute('data-fallback-index','0');
            }
        });
    }

    const css = `
    #viewItemModal .modal-dialog{max-width:1000px!important;}
    #viewItemModal .modal-body{background:#f8fafc;}
    .mp-detail-wrap{padding:24px 34px 34px;background:#fff;}
    .mp-detail-hero{display:grid;grid-template-columns:270px 1fr;gap:22px;align-items:start;margin-bottom:26px;}
    .mp-main-img{width:270px;height:300px;border:1px solid #dbe3ea;border-radius:12px;background:#fff;display:flex;align-items:center;justify-content:center;overflow:hidden;}
    .mp-main-detail-img,.mp-main-img img{max-width:100%;max-height:100%;width:100%;height:100%;object-fit:contain;}
    .mp-no-image{width:100%;height:100%;display:flex;align-items:center;justify-content:center;color:#94a3b8;font-size:3rem;}
    .mp-thumbs{display:flex;gap:10px;flex-wrap:wrap;margin-top:12px;}
    .mp-thumb{width:72px;height:72px;border:2px solid #e5e7eb;border-radius:8px;background:#fff;padding:0;overflow:hidden;}
    .mp-thumb img{width:100%;height:100%;object-fit:cover;}.mp-thumb.active{border-color:#047857;box-shadow:0 0 0 3px rgba(4,120,87,.14);}
    .mp-detail-summary h3{font-size:1.75rem;font-weight:800;margin:4px 0 6px;color:#111827;}.mp-desc{font-size:1.05rem;color:#64748b;margin-bottom:18px;}
    .mp-info-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;}.mp-info-grid>div{border:1px solid #dbe3ea;border-radius:6px;padding:12px 11px;background:#fff;}.mp-info-grid span{display:block;color:#64748b;margin-bottom:4px;}.mp-info-grid b{display:block;color:#111827;font-size:1.05rem;}
    .mp-section{background:#fff;border:1px solid #e5e7eb;border-radius:12px;margin:16px 0;box-shadow:0 4px 12px rgba(15,23,42,.04);overflow:hidden;}.mp-section-title{width:100%;display:flex;align-items:center;gap:10px;justify-content:space-between;border:0;background:#fff;padding:16px 20px;font-weight:800;font-size:1.05rem;color:#052A47;text-align:left;}.mp-section-title i:first-child{color:#047857}.mp-section-body{padding:0 20px 18px;}.mp-section table thead th{background:#047857!important;color:#fff!important;border:0!important;}.mp-section table tbody tr:nth-child(even) td{background:#eafff3;}
    @media(max-width:768px){.mp-detail-wrap{padding:18px}.mp-detail-hero{grid-template-columns:1fr}.mp-main-img{width:100%;height:260px}.mp-info-grid{grid-template-columns:1fr}}
    `;
    const st = document.createElement('style'); st.textContent = css; document.head.appendChild(st);

    document.addEventListener('DOMContentLoaded', function(){
        removeTaskModalForReal();
        fixTableImages();
    });
    removeTaskModalForReal();
})();
</script>


<style id="mp-clean-details-final-css">
#viewItemModal .modal-dialog{max-width:1000px!important;}
#viewItemModal .modal-body{background:#f8fafc!important;padding:0!important;}
.mp-detail-clean{background:#fff;padding:28px 42px 34px!important;}
.mp-gallery-wrapper{width:100%;margin:0 0 34px!important;text-align:center;}
.mp-gallery-main{width:100%;min-height:360px;display:flex;align-items:center;justify-content:center;background:#fff;border:0!important;border-radius:0!important;overflow:hidden;}
.mp-gallery-main img,.mp-main-detail-img{max-width:560px!important;max-height:460px!important;width:auto!important;height:auto!important;object-fit:contain!important;display:block;margin:auto;}
.mp-gallery-thumbs{display:flex;gap:14px;flex-wrap:wrap;justify-content:flex-start;margin-top:20px;padding-left:10px;}
.mp-gallery-thumb{width:98px;height:98px;border:2px solid #e2e8f0;border-radius:10px;background:#fff;padding:0;overflow:hidden;cursor:pointer;}
.mp-gallery-thumb img{width:100%;height:100%;object-fit:cover;}
.mp-gallery-thumb.active{border-color:#047857;box-shadow:0 0 0 3px rgba(68,211,78,.18);}
.clean-section{border:1px solid #bbf7d0!important;border-radius:18px!important;background:#fff!important;margin:22px 0!important;box-shadow:0 4px 14px rgba(15,23,42,.04)!important;padding:20px!important;}
.clean-title{width:100%;border:1px solid #bbf7d0!important;background:#fff!important;border-radius:14px!important;padding:15px 20px!important;font-size:1.08rem!important;font-weight:800!important;color:#052A47!important;display:flex!important;align-items:center!important;justify-content:space-between!important;text-align:left!important;}
.clean-title span{display:flex;align-items:center;gap:11px;}.clean-title i{color:#047857;}
.mp-detail-table{width:100%;border-collapse:collapse;margin-top:12px;overflow:hidden;border-radius:0 0 12px 12px;}
.mp-detail-table th{width:145px;color:#052A47;font-weight:800;padding:18px 24px!important;vertical-align:middle;background:#fff;text-align:left;}
.mp-detail-table td{padding:18px 24px!important;vertical-align:middle;color:#052A47;font-size:1.02rem;}
.mp-detail-table tr:nth-child(even) th,.mp-detail-table tr:nth-child(even) td{background:#eafff3!important;}
.mp-detail-table tr:last-child th,.mp-detail-table tr:last-child td{background:#d8fbe7!important;}
.mp-status-badge{display:inline-flex;align-items:center;gap:7px;margin-left:10px;padding:7px 12px;border-radius:999px;font-size:.85rem;font-weight:800;border:1px solid #86efac;background:#dcfce7;color:#047857;}.mp-status-badge.inactive{background:#f1f5f9;color:#64748b;border-color:#cbd5e1;}
.barcode-preview-card{display:inline-block;background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:8px 10px;max-width:100%;}.barcode-text{font-size:12px;font-weight:700;letter-spacing:1px;text-align:center;color:#052A47;margin-top:2px;}
@media(max-width:768px){.mp-detail-clean{padding:18px!important}.mp-gallery-main{min-height:260px}.mp-gallery-main img,.mp-main-detail-img{max-width:100%!important;max-height:300px!important}.mp-detail-table th{width:120px;padding:14px!important}.mp-detail-table td{padding:14px!important}.clean-section{padding:14px!important}.mp-gallery-thumb{width:74px;height:74px}}
</style>



<!-- Tire Profile Modal -->
<style>
/* Tire Profile visibility fix: do not reuse the page stat-card class because it is overridden elsewhere. */
#tireProfileModal .modal-content{border:0;border-radius:10px;overflow:hidden;}
#tireProfileModal .modal-body{background:#fff;color:#052A47;}
#tireProfileModal .tp-summary-card{background:#ffffff;border:1px solid #e5e7eb;border-radius:14px;box-shadow:0 8px 22px rgba(15,23,42,.08);padding:18px 20px;min-height:96px;display:flex;flex-direction:column;justify-content:center;}
#tireProfileModal .tp-card-title{font-size:.78rem;font-weight:800;text-transform:uppercase;letter-spacing:.04em;color:#64748b;margin-bottom:8px;}
#tireProfileModal .tp-card-value{font-size:1.15rem;font-weight:900;color:#052A47;line-height:1.25;word-break:break-word;}
#tireProfileModal .tab-content{display:block!important;background:#fff;min-height:220px;border:1px solid #e5e7eb;border-top:0;padding:18px;border-radius:0 0 10px 10px;}
#tireProfileModal .tab-pane{display:none!important;opacity:1!important;visibility:visible!important;}
#tireProfileModal .tab-pane.active,#tireProfileModal .tab-pane.show.active{display:block!important;}
#tireProfileModal .nav-tabs .nav-link{color:#0d6efd;font-weight:700;}
#tireProfileModal .nav-tabs .nav-link.active{color:#052A47;background:#fff;border-color:#dee2e6 #dee2e6 #fff;}
#tireProfileModal .tp-section-title{color:#047857;font-weight:900;margin:4px 0 2px;}
#tireProfileModal label.form-label{font-weight:700;color:#052A47;}
#tireProfileModal .form-control,#tireProfileModal .form-select{color:#052A47;background:#fff;border:1px solid #dbe5ef;}
#tireProfileModal .custom-table th{background:#047857!important;color:#fff!important;}
</style>
<div class="modal fade" id="tireProfileModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header" style="background: linear-gradient(135deg, #052A47 0%, #047857 100%); color: #fff;">
        <h5 class="modal-title"><i class="bi bi-life-preserver me-2"></i>Tire Profile</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="tireItemId">
        <div class="row g-3 mb-3">
          <div class="col-md-3"><div class="tp-summary-card"><div class="tp-card-title">Serial Number</div><div class="tp-card-value" id="tpSerialView">Loading...</div></div></div>
          <div class="col-md-3"><div class="tp-summary-card"><div class="tp-card-title">Current Status</div><div class="tp-card-value" id="tpStatusView">Loading...</div></div></div>
          <div class="col-md-3"><div class="tp-summary-card"><div class="tp-card-title">Truck / Plate</div><div class="tp-card-value" id="tpTruckView">Loading...</div></div></div>
          <div class="col-md-3"><div class="tp-summary-card"><div class="tp-card-title">Remaining Tread / Lifespan</div><div class="tp-card-value" id="tpTreadView">Loading...</div></div></div>
        </div>

        <ul class="nav nav-tabs mb-3" role="tablist">
          <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tpOverview" type="button">Overview</button></li>
          <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tpHistory" type="button">History</button></li>
          
        </ul>

        <div class="tab-content">
          <div class="tab-pane fade show active" id="tpOverview">
            <form id="tireProfileForm" class="row g-3">
              <div class="col-12"><h6 class="tp-section-title"><i class="bi bi-receipt me-1"></i>Purchase / Enter Bills Info</h6></div>
              <div class="col-md-3"><label class="form-label">Purchase Date</label><input type="date" class="form-control" name="purchase_date" id="tpPurchaseDate"></div>
              <div class="col-md-3"><label class="form-label">Invoice / Bill No. (Enter Bills)</label><input class="form-control" name="invoice_no" id="tpInvoice" placeholder="Invoice/Bill No."></div>
              <div class="col-md-3"><label class="form-label">Supplier</label><input class="form-control" name="supplier_name" id="tpSupplier"></div>
              <div class="col-md-3"><label class="form-label">Purchase Cost</label><input class="form-control" name="purchase_cost" id="tpPurchaseCost" placeholder="0.00"></div>

              <div class="col-12"><h6 class="tp-section-title mt-2"><i class="bi bi-disc me-1"></i>Tire Serial Profile</h6></div>
              <div class="col-md-4"><label class="form-label">Serial Number</label><input class="form-control" name="serial_number" id="tpSerial"></div>
              <div class="col-md-4"><label class="form-label">Brand / Principal</label><input class="form-control" name="brand" id="tpBrand"></div>
              <div class="col-md-4"><label class="form-label">Size</label><input class="form-control" name="tire_size" id="tpSize" placeholder="Ex. 12R22.5"></div>
              <div class="col-md-4"><label class="form-label">Pattern</label><input class="form-control" name="pattern" id="tpPattern"></div>
              <div class="col-md-4"><label class="form-label">Status</label><select class="form-select" name="current_status" id="tpStatus"><option>Warehouse</option><option>Installed</option><option>Released</option><option>Damaged/Replaced</option><option>Returned</option><option>Repaired</option><option>Scrapped</option></select></div>
              <div class="col-md-4"><label class="form-label">Remaining Tread</label><input class="form-control" name="remaining_tread" id="tpTread" placeholder="Ex. 8 mm / 70%"></div>
              <div class="col-md-4"><label class="form-label">Current Truck</label><input class="form-control" name="current_truck" id="tpTruck"></div>
              <div class="col-md-4"><label class="form-label">Plate Number</label><input class="form-control" name="current_plate" id="tpPlate"></div>
              <div class="col-md-4"><label class="form-label">Tire Position</label><input class="form-control" name="current_position" id="tpPosition" placeholder="Front Left / Rear Right"></div>
              <div class="col-12"><label class="form-label">Remarks</label><textarea class="form-control" name="remarks" id="tpRemarks" rows="2"></textarea></div>
              <div class="col-12 text-end"><button type="button" class="btn btn-primary" onclick="saveTireProfile()"><i class="bi bi-save me-1"></i>Save Tire Profile</button></div>
            </form>
            <hr>
            <div class="row g-3" id="tpPurchaseDetails"></div>
            <div class="row g-3 mt-1" id="tpLifespanDetails"></div>
          </div>
          <div class="tab-pane fade" id="tpHistory">
            <div class="alert alert-info py-2 mb-3"><i class="bi bi-info-circle me-1"></i>History is view-only and comes from <strong id="tpHistorySourceLabel">Enter Bills and RIS Monitoring</strong>.</div><div class="table-responsive"><table class="table custom-table"><thead><tr><th>Date</th><th>Action</th><th>Source</th><th>Reference / Invoice</th><th>Truck</th><th>Plate</th><th>Position</th><th>Damage/Remarks</th><th>Attachment</th></tr></thead><tbody id="tpHistoryBody"><tr><td colspan="9" class="text-center text-muted">No history yet.</td></tr></tbody></table></div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
let currentTireProfileData = null;
function tireEsc(v){ return (v === null || v === undefined || v === '') ? '-' : String(v).replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m])); }
function openTireProfile(itemId){
    document.getElementById('tireItemId').value = itemId;
    ['tpSerialView','tpStatusView','tpTruckView','tpTreadView'].forEach(id=>{ const el=document.getElementById(id); if(el) el.textContent='Loading...'; });
    const body=document.getElementById('tpHistoryBody'); if(body) body.innerHTML='<tr><td colspan="9" class="text-center text-muted">Loading...</td></tr>';
    const modalEl=document.getElementById('tireProfileModal');
    if(modalEl && window.bootstrap){ bootstrap.Modal.getOrCreateInstance(modalEl).show(); }
    const fd = new FormData();
    fd.append('action','get_tire_profile');
    fd.append('item_id', itemId);
    fetch(window.location.href, {method:'POST', body:fd})
      .then(r=>r.json())
      .then(data=>{
        if(!data.success){ throw new Error(data.message || 'Unable to load Tire Profile'); }
        currentTireProfileData = data;
        document.getElementById('tireItemId').value = itemId;
        const p=data.profile||{}, i=data.item||{};
        document.getElementById('tpSerial').value = p.serial_number || i.barcode || i.item_code || '';
        document.getElementById('tpBrand').value = p.brand || i.principal || '';
        document.getElementById('tpSize').value = p.tire_size || '';
        document.getElementById('tpPattern').value = p.pattern || '';
        document.getElementById('tpPurchaseDate').value = p.purchase_date || (i.created_at || '').substring(0,10) || '';
        document.getElementById('tpInvoice').value = p.invoice_no || '';
        document.getElementById('tpSupplier').value = p.supplier_name || i.supplier || '';
        document.getElementById('tpPurchaseCost').value = p.purchase_cost || i.unit_cost || '';
        document.getElementById('tpStatus').value = p.current_status || 'Warehouse';
        document.getElementById('tpTread').value = p.remaining_tread || '';
        document.getElementById('tpTruck').value = p.current_truck || '';
        document.getElementById('tpPlate').value = p.current_plate || '';
        document.getElementById('tpPosition').value = p.current_position || '';
        document.getElementById('tpRemarks').value = p.remarks || '';
        renderTireProfile(data);
      })
      .catch(err=>{ if(window.Swal){Swal.fire('Error', err.message, 'error');} else {alert(err.message);} });
}
function tireDateOnly(v){ return (v || '').substring(0,10); }
function tireDaysBetween(a,b){
    if(!a || !b) return null;
    const d1 = new Date(a + 'T00:00:00');
    const d2 = new Date(b + 'T00:00:00');
    if(isNaN(d1.getTime()) || isNaN(d2.getTime())) return null;
    return Math.max(0, Math.round((d2 - d1) / 86400000));
}
function tireFormatDays(days){
    if(days === null || typeof days === 'undefined') return '-';
    const y = Math.floor(days / 365);
    const m = Math.floor((days % 365) / 30);
    const d = days - (y * 365) - (m * 30);
    const parts = [];
    if(y) parts.push(y + ' yr' + (y > 1 ? 's' : ''));
    if(m) parts.push(m + ' mo' + (m > 1 ? 's' : ''));
    if(d || parts.length === 0) parts.push(d + ' day' + (d !== 1 ? 's' : ''));
    return parts.join(', ');
}
function getTireLifespanInfo(histories){
    const rows = (histories || []).slice().sort((a,b)=>String(a.action_date || '').localeCompare(String(b.action_date || '')) || ((a.history_id||0) - (b.history_id||0)));
    const release = rows.find(h => ['RELEASED','INSTALLED'].includes(String(h.action_type || '').toUpperCase()));
    let end = null;
    if(release){
        const relDate = tireDateOnly(release.action_date);
        end = rows.find(h => ['DAMAGED','REPLACED','RETURNED','REMOVED','SCRAPPED'].includes(String(h.action_type || '').toUpperCase()) && tireDateOnly(h.action_date) >= relDate);
    }
    const days = release && end ? tireDaysBetween(tireDateOnly(release.action_date), tireDateOnly(end.action_date)) : null;
    return { release, end, days };
}
function renderTireProfile(data){
    const p=data.profile||{}, i=data.item||{};
    const histories = data.histories || [];
    const life = getTireLifespanInfo(histories);
    const purchased = histories.find(h => String(h.action_type || '').toUpperCase() === 'PURCHASED') || {};
    const release = life.release || histories.find(h => ['RELEASED','INSTALLED'].includes(String(h.action_type || '').toUpperCase())) || {};
    const damage = life.end || histories.find(h => ['DAMAGED','REPLACED','RETURNED','REMOVED','SCRAPPED'].includes(String(h.action_type || '').toUpperCase())) || {};

    document.getElementById('tpSerialView').textContent = p.serial_number || i.barcode || i.item_code || '-';
    document.getElementById('tpStatusView').textContent = p.current_status || 'Warehouse';
    document.getElementById('tpTruckView').textContent = [p.current_truck,p.current_plate].filter(Boolean).join(' / ') || '-';
    document.getElementById('tpTreadView').textContent = p.remaining_tread || tireFormatDays(life.days);

    document.getElementById('tpPurchaseDetails').innerHTML = `
      <div class="col-md-3"><strong>Item:</strong><br>${tireEsc(i.item_name)}</div>
      <div class="col-md-3"><strong>Date Purchased:</strong><br>${tireEsc(purchased.action_date || p.purchase_date || (i.created_at||'').substring(0,10))}</div>
      <div class="col-md-3"><strong>Enter Bills / Invoice:</strong><br>${tireEsc(purchased.invoice_no || purchased.source_reference || p.invoice_no)}</div>
      <div class="col-md-3"><strong>Vendor / Cost:</strong><br>${tireEsc(purchased.vendor_name || p.supplier_name || i.supplier)}${(purchased.purchase_cost || p.purchase_cost) ? '<br>₱' + tireEsc(purchased.purchase_cost || p.purchase_cost) : ''}</div>`;

    document.getElementById('tpLifespanDetails').innerHTML = `
      <div class="col-md-3"><strong>Date Released:</strong><br>${release.action_date ? tireEsc(release.action_date) : '-'}</div>
      <div class="col-md-3"><strong>Plate Number / Truck:</strong><br>${release.plate_number ? tireEsc(release.plate_number) : '-'}${release.truck_name ? '<br>' + tireEsc(release.truck_name) : ''}</div>
      <div class="col-md-3"><strong>Date Damaged / Replaced / Returned:</strong><br>${damage.action_date ? tireEsc(damage.action_date) : '-'}</div>
      <div class="col-md-3"><strong>Lifespan:</strong><br>${tireFormatDays(life.days)}</div>
      <div class="col-12"><strong>Damage / Replacement Details:</strong><br>${tireEsc(damage.damage_description || damage.remarks || '-')}</div>`;

    const rows = histories.map(h=>`<tr>
      <td>${tireEsc(h.action_date)}</td>
      <td><span class="badge ${String(h.action_type || '').toUpperCase()==='PURCHASED'?'bg-primary':(['DAMAGED','REPLACED'].includes(String(h.action_type || '').toUpperCase())?'bg-danger':'bg-success')}">${tireEsc(h.action_type)}</span></td>
      <td>${tireEsc(h.source_module || '')}</td>
      <td>${tireEsc(h.source_reference || h.invoice_no || '')}</td>
      <td>${tireEsc(h.truck_name)}</td>
      <td>${tireEsc(h.plate_number)}</td>
      <td>${tireEsc(h.position)}</td>
      <td>${tireEsc(h.damage_description || h.remarks)}</td>
      <td>${h.attachment_path ? `<a href="${tireEsc(h.attachment_path)}" target="_blank" class="btn btn-sm btn-outline-primary"><i class="bi bi-paperclip"></i></a>` : '-'}</td>
    </tr>`).join('');
    document.getElementById('tpHistoryBody').innerHTML = rows || '<tr><td colspan="9" class="text-center text-muted">No Enter Bills or RIS Monitoring history found for this serial item.</td></tr>';
    const sourceLabel = document.getElementById('tpHistorySourceLabel');
    if(sourceLabel) sourceLabel.textContent = data.history_source || 'Enter Bills and RIS Monitoring';
}
function saveTireProfile(){
    const form = document.getElementById('tireProfileForm');
    const fd = new FormData(form);
    fd.append('action','save_tire_profile');
    fd.append('item_id', document.getElementById('tireItemId').value);
    fetch(window.location.href,{method:'POST',body:fd}).then(r=>r.json()).then(data=>{
      if(!data.success) throw new Error(data.message||'Save failed');
      if(window.Swal) Swal.fire('Saved', data.message, 'success');
      openTireProfile(document.getElementById('tireItemId').value);
    }).catch(err=> window.Swal ? Swal.fire('Error', err.message, 'error') : alert(err.message));
}
function saveTireHistory(){
    const form = document.getElementById('tireHistoryForm');
    const fd = new FormData(form);
    fd.append('action','save_tire_history');
    fd.append('item_id', document.getElementById('tireItemId').value);
    fetch(window.location.href,{method:'POST',body:fd}).then(r=>r.json()).then(data=>{
      if(!data.success) throw new Error(data.message||'Save failed');
      form.reset();
      const dateInput = form.querySelector('input[name="action_date"]'); if(dateInput) dateInput.value = new Date().toISOString().slice(0,10);
      if(window.Swal) Swal.fire('Saved', data.message, 'success');
      openTireProfile(document.getElementById('tireItemId').value);
    }).catch(err=> window.Swal ? Swal.fire('Error', err.message, 'error') : alert(err.message));
}
</script>



<!-- Tire Serial Items Modal: Multiple Add-Item Style Forms, Tire category only -->
<div class="modal fade" id="tireSerialItemsModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content" style="border-radius:18px;overflow:hidden;border:0;">
      <div class="modal-header" style="background:linear-gradient(135deg,#0b7f4f,#2bc653);color:#fff;">
        <div>
          <h5 class="modal-title mb-1"><i class="bi bi-list-check me-2"></i>Tire Serial Items</h5>
          <div id="tsiParentInfo" style="font-size:13px;opacity:.95;"></div>
        </div>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" style="background:#f8fffb;">
        <input type="hidden" id="tsiParentId">

        <div class="card mb-3" style="border:1px solid #bff5d1;border-radius:16px;">
          <div class="card-body">
            <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
              <div>
                <h4 id="tsiParentName" class="mb-2" style="color:#052A47;font-weight:800;">Tire Item</h4>
                <div class="d-flex gap-2 flex-wrap">
                  <span class="badge rounded-pill" style="background:#e8fff1;color:#006b43;border:1px solid #9cf0bd;padding:10px 14px;"><span id="tsiTotalCount">0</span> Total Qty</span>
                  <span class="badge rounded-pill" style="background:#e8fff1;color:#006b43;border:1px solid #9cf0bd;padding:10px 14px;"><span id="tsiActiveCount">0</span> Active Qty</span>
                  <span class="badge rounded-pill" style="background:#e8fff1;color:#006b43;border:1px solid #9cf0bd;padding:10px 14px;"><span id="tsiTotalCost">₱0.00</span> Total Cost</span>
                  <span class="badge rounded-pill" style="background:#e8fff1;color:#006b43;border:1px solid #9cf0bd;padding:10px 14px;"><span id="tsiTotalValue">₱0.00</span> Total Price Value</span>
                </div>
              </div>
              <button type="button" class="btn btn-success" onclick="addTireSerialForm()"><i class="bi bi-plus-circle me-1"></i> Add New Serial Item</button>
            </div>
          </div>
        </div>

        <div class="alert alert-success py-2 mb-3" style="border-radius:14px;border:1px solid #bff5d1;background:#effff4;color:#075b39;">
          <i class="bi bi-info-circle me-1"></i>
          Click <strong>Add New Serial Item</strong> to add another form. Each serial item now includes its own Tire Profile and will appear under the parent tire in Current Inventory.
        </div>

        <div id="tireSerialFormsContainer" class="d-flex flex-column gap-3"></div>

        <div class="d-flex justify-content-end gap-2 mt-3">
          <button type="button" class="btn btn-light border" onclick="resetTireSerialForms()">Clear Forms</button>
          <button type="button" class="btn btn-success px-4" onclick="saveAllTireSerialForms()"><i class="bi bi-save me-1"></i> Save All Serial Items</button>
        </div>

        <!-- Serial list table removed. Saved serial items now appear indented under the parent Tire item in Current Inventory. -->
      </div>
    </div>
  </div>
</div>

<script>
let tireSerialItemRows = [];
let tireSerialFormCounter = 0;

function tsiEsc(v){return String(v ?? '').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));}
function tsiMoney(v){return '₱' + Number(v || 0).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2});}
function tsiGetValue(form, key){ return form.querySelector(`[data-tsi-field="${key}"]`)?.value || ''; }
function tsiSetValue(form, key, value){ const el=form.querySelector(`[data-tsi-field="${key}"]`); if(el) el.value=value ?? ''; }
function updateTsiTotalCostPreview(form){
  if(!form) return;
  const q=Number(tsiGetValue(form,'quantity')||0);
  const c=Number(tsiGetValue(form,'unit_cost')||0);
  tsiSetValue(form,'computed_total_cost', tsiMoney(q*c));
}
function updateAllTsiTotalCostPreviews(){ document.querySelectorAll('.tsi-form-card').forEach(updateTsiTotalCostPreview); }

function openTireSerialItems(itemId){
  const modalEl=document.getElementById('tireSerialItemsModal');
  if(!modalEl){ alert('Tire Serial Items modal not found.'); return; }
  document.getElementById('tsiParentId').value=itemId;
  const modal=bootstrap.Modal.getOrCreateInstance(modalEl); modal.show();
  loadTireSerialItems(itemId);
}

function loadTireSerialItems(itemId){
  const fd=new FormData(); fd.append('action','get_tire_serial_items'); fd.append('parent_item_id',itemId);
  fetch(window.location.href,{method:'POST',body:fd}).then(r=>r.json()).then(data=>{
    if(!data.success) throw new Error(data.message||'Unable to load tire serial items.');
    tireSerialItemRows=data.serial_items||[];
    document.getElementById('tsiParentName').textContent=(data.parent?.item_name||'Tire Item');
    document.getElementById('tsiParentInfo').textContent='Code: '+(data.parent?.item_code||'-')+' • Category: '+(data.parent?.category||'Tire');
    document.getElementById('tsiTotalCount').textContent=Number(data.summary?.total||0).toLocaleString();
    document.getElementById('tsiActiveCount').textContent=Number(data.summary?.active||0).toLocaleString();
    document.getElementById('tsiTotalCost').textContent=tsiMoney(data.summary?.total_cost||0);
    document.getElementById('tsiTotalValue').textContent=tsiMoney(data.summary?.total_value||0);
    resetTireSerialForms(false);
  }).catch(err=>{ if(window.Swal) Swal.fire('Error',err.message,'error'); else alert(err.message); });
}

function buildTireSerialFormHtml(index, defaults={}){
  const title = defaults.title || ('Serial Item Form #' + index);
  return `
  <div class="card tsi-form-card" data-tsi-index="${index}" style="border:1px solid #bff5d1;border-radius:16px;overflow:hidden;">
    <div class="card-header d-flex justify-content-between align-items-center" style="background:#effff4;border-bottom:1px solid #bff5d1;">
      <h6 class="mb-0 tsi-form-title" style="color:#052A47;font-weight:800;"><i class="bi bi-card-list me-1"></i>${tsiEsc(title)}</h6>
      <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeTireSerialForm(this)" title="Remove this form"><i class="bi bi-x-lg"></i></button>
    </div>
    <div class="card-body">
      <div class="row g-3">
        <div class="col-md-3"><label class="form-label">Item Code</label><input data-tsi-field="item_code" class="form-control" placeholder="optional / auto if blank" value="${tsiEsc(defaults.item_code||'')}"></div>
        <div class="col-md-3"><label class="form-label">Serial Number <span class="text-danger">*</span></label><input data-tsi-field="serial_number" class="form-control" placeholder="e.g. SN-001" value="${tsiEsc(defaults.serial_number||'')}"></div>
        <div class="col-md-3"><label class="form-label">Barcode</label><input data-tsi-field="barcode" class="form-control" placeholder="Scan / enter barcode" value="${tsiEsc(defaults.barcode||'')}"></div>
        <div class="col-md-3"><label class="form-label">Status</label><select data-tsi-field="status" class="form-select"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>

        <div class="col-md-2"><label class="form-label">Qty</label><input data-tsi-field="quantity" type="number" min="0" step="0.01" class="form-control" value="${tsiEsc(defaults.quantity||'1')}" oninput="updateTsiTotalCostPreview(this.closest('.tsi-form-card'))"></div>
        <div class="col-md-2"><label class="form-label">Unit Type</label><input data-tsi-field="unit_type" class="form-control" value="${tsiEsc(defaults.unit_type||'Piece')}"></div>
        <div class="col-md-2"><label class="form-label">Beginning Inventory</label><input data-tsi-field="beginning_inventory" type="number" min="0" step="0.01" class="form-control" value="${tsiEsc(defaults.beginning_inventory||'1')}"></div>
        <div class="col-md-3"><label class="form-label">As Of Date</label><input data-tsi-field="as_of_date" type="date" class="form-control" value="${tsiEsc(defaults.as_of_date||'')}"></div>
        <div class="col-md-3"><label class="form-label">Unit Cost</label><input data-tsi-field="unit_cost" type="number" min="0" step="0.01" class="form-control" value="${tsiEsc(defaults.unit_cost||'')}" oninput="updateTsiTotalCostPreview(this.closest('.tsi-form-card'))"></div>
        <div class="col-md-3"><label class="form-label">Total Cost</label><input data-tsi-field="computed_total_cost" class="form-control" readonly></div>
        <div class="col-md-3"><label class="form-label">Unit Price</label><input data-tsi-field="unit_price" type="number" min="0" step="0.01" class="form-control" value="${tsiEsc(defaults.unit_price||'')}"></div>
        <div class="col-md-3"><label class="form-label">Price Level</label><input data-tsi-field="price_level" class="form-control" value="${tsiEsc(defaults.price_level||'Standard')}"></div>

        <div class="col-12"><hr class="my-2"><h6 class="mb-0" style="color:#047857;font-weight:800;"><i class="bi bi-life-preserver me-1"></i>Tire Profile</h6></div>
        <div class="col-md-3"><label class="form-label">Brand</label><input data-tsi-field="tire_brand" class="form-control" placeholder="Brand" value="${tsiEsc(defaults.tire_brand||'')}"></div>
        <div class="col-md-3"><label class="form-label">Tire Size</label><input data-tsi-field="tire_size" class="form-control" placeholder="e.g. 315/80" value="${tsiEsc(defaults.tire_size||'')}"></div>
        <div class="col-md-3"><label class="form-label">Pattern</label><input data-tsi-field="tire_pattern" class="form-control" placeholder="Pattern" value="${tsiEsc(defaults.tire_pattern||'')}"></div>
        <div class="col-md-3"><label class="form-label">Profile Status</label><select data-tsi-field="tire_status" class="form-select"><option value="Warehouse">Warehouse</option><option value="Installed">Installed</option><option value="For Repair">For Repair</option><option value="Damaged">Damaged</option><option value="Retired">Retired</option></select></div>
        <div class="col-md-3"><label class="form-label">Purchase Date</label><input data-tsi-field="tire_purchase_date" type="date" class="form-control" value="${tsiEsc(defaults.tire_purchase_date||'')}"></div>
        <div class="col-md-3"><label class="form-label">Invoice No.</label><input data-tsi-field="tire_invoice_no" class="form-control" placeholder="Invoice No." value="${tsiEsc(defaults.tire_invoice_no||'')}"></div>
        <div class="col-md-3"><label class="form-label">Purchase Cost</label><input data-tsi-field="tire_purchase_cost" type="number" min="0" step="0.01" class="form-control" value="${tsiEsc(defaults.tire_purchase_cost||'')}"></div>
        <div class="col-md-3"><label class="form-label">Remaining Tread</label><input data-tsi-field="tire_remaining_tread" class="form-control" placeholder="e.g. 14 mm" value="${tsiEsc(defaults.tire_remaining_tread||'')}"></div>
        <div class="col-md-4"><label class="form-label">Truck / Vehicle</label><input data-tsi-field="tire_truck" class="form-control" placeholder="Truck / Vehicle" value="${tsiEsc(defaults.tire_truck||'')}"></div>
        <div class="col-md-4"><label class="form-label">Plate No.</label><input data-tsi-field="tire_plate" class="form-control" placeholder="Plate No." value="${tsiEsc(defaults.tire_plate||'')}"></div>
        <div class="col-md-4"><label class="form-label">Position</label><input data-tsi-field="tire_position" class="form-control" placeholder="Front L/R" value="${tsiEsc(defaults.tire_position||'')}"></div>

        <div class="col-md-6"><label class="form-label">Description</label><input data-tsi-field="description" class="form-control" placeholder="Description" value="${tsiEsc(defaults.description||'')}"></div>
        <div class="col-md-6"><label class="form-label">Remarks</label><input data-tsi-field="remarks" class="form-control" placeholder="Remarks" value="${tsiEsc(defaults.remarks||'')}"></div>
        <div class="col-12"><label class="form-label">Tire Profile Remarks</label><input data-tsi-field="tire_profile_remarks" class="form-control" placeholder="Tire profile notes" value="${tsiEsc(defaults.tire_profile_remarks||'')}"></div>
      </div>
    </div>
  </div>`;
}

function getDefaultsFromLastTireSerialForm(){
  const forms=[...document.querySelectorAll('.tsi-form-card')];
  const last=forms[forms.length-1];
  if(!last) return {};
  return {
    barcode: '',
    quantity: tsiGetValue(last,'quantity') || '1',
    unit_type: tsiGetValue(last,'unit_type') || 'Piece',
    beginning_inventory: tsiGetValue(last,'beginning_inventory') || '1',
    as_of_date: tsiGetValue(last,'as_of_date') || '',
    unit_cost: tsiGetValue(last,'unit_cost') || '',
    unit_price: tsiGetValue(last,'unit_price') || '',
    price_level: tsiGetValue(last,'price_level') || 'Standard',
    status: tsiGetValue(last,'status') || 'active',
    description: tsiGetValue(last,'description') || '',
    remarks: tsiGetValue(last,'remarks') || '',
    tire_brand: tsiGetValue(last,'tire_brand') || '',
    tire_size: tsiGetValue(last,'tire_size') || '',
    tire_pattern: tsiGetValue(last,'tire_pattern') || '',
    tire_status: tsiGetValue(last,'tire_status') || 'Warehouse',
    tire_purchase_date: tsiGetValue(last,'tire_purchase_date') || '',
    tire_invoice_no: tsiGetValue(last,'tire_invoice_no') || '',
    tire_purchase_cost: tsiGetValue(last,'tire_purchase_cost') || '',
    tire_remaining_tread: tsiGetValue(last,'tire_remaining_tread') || '',
    tire_truck: tsiGetValue(last,'tire_truck') || '',
    tire_plate: tsiGetValue(last,'tire_plate') || '',
    tire_position: tsiGetValue(last,'tire_position') || '',
    tire_profile_remarks: tsiGetValue(last,'tire_profile_remarks') || ''
  };
}

function refreshTireSerialFormNumbers(){
  const forms=[...document.querySelectorAll('#tireSerialFormsContainer .tsi-form-card')];
  forms.forEach((form, idx)=>{
    const num = idx + 1;
    form.setAttribute('data-tsi-index', String(num));
    const titleEl = form.querySelector('.tsi-form-title');
    if(titleEl && !form.getAttribute('data-edit-id')){
      titleEl.innerHTML = '<i class="bi bi-card-list me-1"></i>Serial Item Form #' + num;
    }
  });
}

function addTireSerialForm(defaults=null){
  const container=document.getElementById('tireSerialFormsContainer');
  if(!container) return;
  const existingCount=container.querySelectorAll('.tsi-form-card').length;
  const baseDefaults = defaults || (existingCount > 0 ? getDefaultsFromLastTireSerialForm() : {});
  const nextIndex = existingCount + 1;
  container.insertAdjacentHTML('beforeend', buildTireSerialFormHtml(nextIndex, baseDefaults));
  const form=container.lastElementChild;
  const statusVal=baseDefaults.status || 'active';
  tsiSetValue(form,'status',statusVal);
  tsiSetValue(form,'tire_status', baseDefaults.tire_status || 'Warehouse');
  updateTsiTotalCostPreview(form);
  refreshTireSerialFormNumbers();
  const serialInput=form.querySelector('[data-tsi-field="serial_number"]');
  if(serialInput) serialInput.focus();
}

function removeTireSerialForm(btn){
  const form=btn.closest('.tsi-form-card');
  if(form) form.remove();
  refreshTireSerialFormNumbers();
}

function resetTireSerialForms(focusIt=true){
  const container=document.getElementById('tireSerialFormsContainer');
  if(!container) return;
  container.innerHTML='';
  tireSerialFormCounter=0;
  addTireSerialForm({});
  updateAllTsiTotalCostPreviews();
  if(focusIt){
    const first=container.querySelector('[data-tsi-field="serial_number"]');
    if(first) first.focus();
  }
}

function editTireSerialItem(id){
  const s=tireSerialItemRows.find(x=>Number(x.serial_item_id)===Number(id));
  if(!s) return;
  const container=document.getElementById('tireSerialFormsContainer');
  if(!container) return;
  container.innerHTML='';
  tireSerialFormCounter=0;
  addTireSerialForm({
    title: 'Edit Tire Serial Item',
    serial_item_id: s.serial_item_id,
    item_code: s.item_code || '',
    serial_number: s.serial_number || '',
    barcode: s.barcode || '',
    quantity: s.quantity || 1,
    unit_type: s.unit_type || 'Piece',
    beginning_inventory: s.beginning_inventory || s.quantity || 1,
    as_of_date: s.as_of_date || '',
    unit_cost: s.unit_cost || '',
    unit_price: s.unit_price || '',
    price_level: s.price_level || 'Standard',
    description: s.description || '',
    remarks: s.remarks || '',
    status: s.status || 'active',
    tire_brand: s.tire_brand || '',
    tire_size: s.tire_size || '',
    tire_pattern: s.tire_pattern || '',
    tire_status: s.tire_status || 'Warehouse',
    tire_purchase_date: s.tire_purchase_date || '',
    tire_invoice_no: s.tire_invoice_no || '',
    tire_purchase_cost: s.tire_purchase_cost || '',
    tire_remaining_tread: s.tire_remaining_tread || '',
    tire_truck: s.tire_truck || '',
    tire_plate: s.tire_plate || '',
    tire_position: s.tire_position || '',
    tire_profile_remarks: s.tire_profile_remarks || ''
  });
  container.querySelector('.tsi-form-card')?.setAttribute('data-edit-id', s.serial_item_id || '');
}

function collectTireSerialForms(){
  const forms=[...document.querySelectorAll('.tsi-form-card')];
  const rows=[];
  for(const form of forms){
    const serial=(tsiGetValue(form,'serial_number')||'').trim();
    if(!serial) continue;
    rows.push({
      form,
      serial_item_id: form.getAttribute('data-edit-id') || '',
      item_code: (tsiGetValue(form,'item_code')||'').trim(),
      serial_number: serial,
      barcode: tsiGetValue(form,'barcode'),
      quantity: tsiGetValue(form,'quantity') || '1',
      unit_type: tsiGetValue(form,'unit_type') || 'Piece',
      beginning_inventory: tsiGetValue(form,'beginning_inventory') || '1',
      as_of_date: tsiGetValue(form,'as_of_date') || '',
      unit_cost: tsiGetValue(form,'unit_cost') || '0',
      unit_price: tsiGetValue(form,'unit_price') || '0',
      price_level: tsiGetValue(form,'price_level') || 'Standard',
      description: tsiGetValue(form,'description') || '',
      remarks: tsiGetValue(form,'remarks') || '',
      status: tsiGetValue(form,'status') || 'active',
      tire_brand: tsiGetValue(form,'tire_brand') || '',
      tire_size: tsiGetValue(form,'tire_size') || '',
      tire_pattern: tsiGetValue(form,'tire_pattern') || '',
      tire_status: tsiGetValue(form,'tire_status') || 'Warehouse',
      tire_purchase_date: tsiGetValue(form,'tire_purchase_date') || '',
      tire_invoice_no: tsiGetValue(form,'tire_invoice_no') || '',
      tire_purchase_cost: tsiGetValue(form,'tire_purchase_cost') || '',
      tire_remaining_tread: tsiGetValue(form,'tire_remaining_tread') || '',
      tire_truck: tsiGetValue(form,'tire_truck') || '',
      tire_plate: tsiGetValue(form,'tire_plate') || '',
      tire_position: tsiGetValue(form,'tire_position') || '',
      tire_profile_remarks: tsiGetValue(form,'tire_profile_remarks') || ''
    });
  }
  return rows;
}

function saveTireSerialRowsSequentially(rows){
  const parentId=document.getElementById('tsiParentId').value;
  const saveOne=(row)=>{
    const fd=new FormData();
    fd.append('action','save_tire_serial_item');
    fd.append('parent_item_id',parentId);
    Object.entries(row).forEach(([k,v])=>{
      if(k !== 'form') fd.append(k, v ?? '');
    });
    return fetch(window.location.href,{method:'POST',body:fd}).then(r=>r.json()).then(data=>{
      if(!data.success) throw new Error(data.message||'Save failed.');
      return data;
    });
  };
  return rows.reduce((p,row)=>p.then(()=>saveOne(row)), Promise.resolve());
}

function saveAllTireSerialForms(){
  const rows=collectTireSerialForms();
  if(rows.length===0){
    if(window.Swal) Swal.fire('Required','Enter at least one Serial Number.','warning');
    else alert('Enter at least one Serial Number.');
    return;
  }

  const seen=new Set();
  for(const row of rows){
    const key=row.serial_number.toLowerCase();
    if(seen.has(key)){
      if(window.Swal) Swal.fire('Duplicate','Duplicate serial number in the forms: '+row.serial_number,'warning');
      else alert('Duplicate serial number in the forms: '+row.serial_number);
      return;
    }
    seen.add(key);
  }

  if(window.Swal) Swal.fire({title:'Saving...',text:'Saving '+rows.length+' serial item(s). Please wait.',allowOutsideClick:false,didOpen:()=>Swal.showLoading()});
  saveTireSerialRowsSequentially(rows)
    .then(()=>{
      if(window.Swal){
        Swal.fire({icon:'success',title:'Saved',text:rows.length+' serial item(s) saved.',timer:1000,showConfirmButton:false}).then(()=>window.location.reload());
      } else {
        window.location.reload();
      }
    })
    .catch(err=>{ if(window.Swal) Swal.fire('Error',err.message,'error'); else alert(err.message); });
}

// Backward-compatible function name used by old buttons, if any.
function saveTireSerialItem(){ saveAllTireSerialForms(); }

function deleteTireSerialItem(id){
  const parentId=document.getElementById('tsiParentId').value;
  const run=()=>{const fd=new FormData(); fd.append('action','delete_tire_serial_item'); fd.append('parent_item_id',parentId); fd.append('serial_item_id',id); fetch(window.location.href,{method:'POST',body:fd}).then(r=>r.json()).then(data=>{ if(!data.success) throw new Error(data.message||'Delete failed.'); window.location.reload(); }).catch(err=>{ if(window.Swal) Swal.fire('Error',err.message,'error'); else alert(err.message); });};
  if(window.Swal) Swal.fire({title:'Delete serial item?',text:'This will permanently delete the serial item from the database.',icon:'warning',showCancelButton:true,confirmButtonText:'Yes, delete'}).then(res=>{if(res.isConfirmed) run();}); else if(confirm('Delete serial item permanently?')) run();
}
function deleteTireSerialChild(parentId, serialId){
  const run=()=>{const fd=new FormData(); fd.append('action','delete_tire_serial_item'); fd.append('parent_item_id',parentId); fd.append('serial_item_id',serialId); fetch(window.location.href,{method:'POST',body:fd}).then(r=>r.json()).then(data=>{ if(!data.success) throw new Error(data.message||'Delete failed.'); window.location.reload(); }).catch(err=>{ if(window.Swal) Swal.fire('Error',err.message,'error'); else alert(err.message); });};
  if(window.Swal) Swal.fire({title:'Delete serial item?',text:'This will permanently delete the serial item from the database.',icon:'warning',showCancelButton:true,confirmButtonText:'Yes, delete'}).then(res=>{if(res.isConfirmed) run();}); else if(confirm('Delete serial item permanently?')) run();
}
</script>
<script>
 // ========== MOBILE BOTTOM NAVBAR FUNCTIONS ==========
function closeAllMobileDropdowns() {
    document.querySelectorAll('.mobile-nav .more-dropdown.show').forEach(function (dropdown) {
        dropdown.classList.remove('show');
    });
    document.querySelectorAll('.mobile-nav .more-btn.active').forEach(function (btn) {
        const menuId = (btn.getAttribute('onclick') || '').match(/'([^']+)'/);
        const menu = menuId ? document.getElementById(menuId[1]) : null;
        const hasActiveChild = menu && menu.querySelector('.dropdown-item.active');
        if (!hasActiveChild) btn.classList.remove('active');
    });
}

function toggleMobileDropdown(event, dropdownId) {
    if (event) {
        event.preventDefault();
        event.stopPropagation();
    }
    const dropdown = document.getElementById(dropdownId);
    const btn = event ? event.currentTarget : document.querySelector(`[onclick*="${dropdownId}"]`);
    if (!dropdown || !btn) return false;

    if (dropdown.classList.contains('show')) {
        dropdown.classList.remove('show');
        if (!dropdown.querySelector('.dropdown-item.active')) btn.classList.remove('active');
    } else {
        closeAllMobileDropdowns();
        dropdown.classList.add('show');
        btn.classList.add('active');
    }
    return false;
}

function setActiveMobileNav() {
    const currentPage = window.location.pathname.split('/').pop() || 'request_handler.php';
    document.querySelectorAll('.mobile-nav .nav-link, .mobile-nav .dropdown-item').forEach(function (link) {
        link.classList.remove('active');
    });
    document.querySelectorAll('.mobile-nav .nav-link, .mobile-nav .dropdown-item').forEach(function (link) {
        const href = (link.getAttribute('href') || '').split('?')[0];
        if (href === currentPage) {
            link.classList.add('active');
            const parentDropdown = link.closest('.more-dropdown');
            if (parentDropdown) {
                const parentBtn = document.querySelector(`[onclick*="${parentDropdown.id}"]`);
                if (parentBtn) parentBtn.classList.add('active');
            }
        }
    });
}

document.addEventListener('click', function (e) {
    if (!e.target.closest('.mobile-nav .dropdown-more')) closeAllMobileDropdowns();
});

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeAllMobileDropdowns();
});
</script>

</body>
</html>