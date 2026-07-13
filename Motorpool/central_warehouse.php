<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once '../config/database.php';
require_once '../config/session_handler.php';

if (!$conn) { die('Database connection failed: ' . mysqli_connect_error()); }

$user_id = (int)($_SESSION['user_id'] ?? 0);
$user_role_raw = strtolower(trim((string)($_SESSION['role'] ?? '')));

if ($user_id <= 0 || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../login.php');
    exit();
}

if ($user_role_raw !== 'motorpool') {
    if ($user_role_raw === 'branch_admin') {
        header('Location: ../Branch_Admin/branchdashboard.php');
    } elseif ($user_role_raw === 'admin') {
        header('Location: ../Admin/dashboard.php');
    } else {
        header('Location: ../login.php');
    }
    exit();
}

$user_name = trim((string)($_SESSION['first_name'] ?? '') . ' ' . (string)($_SESSION['last_name'] ?? ''));
if ($user_name === '') $user_name = 'Motorpool Account';
$user_role = 'motorpool';
$view_all_branches = false;
$_SESSION['view_all_branches'] = false;

function mpTableExists(mysqli $conn, string $table): bool {
    $table = $conn->real_escape_string($table);
    $res = $conn->query("SHOW TABLES LIKE '{$table}'");
    return $res && $res->num_rows > 0;
}

function ensureMotorpoolBranchContext(mysqli $conn): array {
    $branchName = 'Motorpool';
    if (mpTableExists($conn, 'branches')) {
        $sql = "SELECT branch_id, branch_name, business_unit
                FROM branches
                WHERE LOWER(TRIM(branch_name)) = 'motorpool'
                   OR LOWER(TRIM(branch_name)) LIKE '%motorpool%'
                   OR LOWER(TRIM(COALESCE(business_unit,''))) = 'motorpool'
                   OR LOWER(TRIM(COALESCE(business_unit,''))) LIKE '%motorpool%'
                ORDER BY CASE WHEN LOWER(TRIM(branch_name)) = 'motorpool' THEN 0 ELSE 1 END, branch_id ASC
                LIMIT 1";
        $res = $conn->query($sql);
        if ($res && ($row = $res->fetch_assoc())) {
            return [(int)$row['branch_id'], trim((string)$row['branch_name']) ?: $branchName, trim((string)($row['business_unit'] ?? 'Motorpool')) ?: 'Motorpool'];
        }
        $businessUnit = 'Motorpool';
        $stmt = $conn->prepare("INSERT INTO branches (branch_name, business_unit) VALUES (?, ?)");
        if ($stmt) {
            $stmt->bind_param('ss', $branchName, $businessUnit);
            if (@$stmt->execute()) {
                $newId = (int)$conn->insert_id;
                $stmt->close();
                return [$newId, $branchName, $businessUnit];
            }
            $stmt->close();
        }
    }
    $sessionBranch = (int)($_SESSION['branch_id'] ?? 0);
    return [$sessionBranch > 0 ? $sessionBranch : 0, $branchName, 'Motorpool'];
}

[$branch_id, $branch_name, $branch_business_unit] = ensureMotorpoolBranchContext($conn);
$_SESSION['branch_id'] = $branch_id;
$_SESSION['branch_name'] = $branch_name;
$_SESSION['view_all_branches'] = false;

function h($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }

function columnExists(mysqli $conn, string $table, string $column): bool {
    $table = $conn->real_escape_string($table);
    $column = $conn->real_escape_string($column);
    $res = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
    return $res && $res->num_rows > 0;
}

function addColumnIfMissing(mysqli $conn, string $table, string $column, string $definition): void {
    if (tableExists($conn, $table) && !columnExists($conn, $table, $column)) {
        @$conn->query("ALTER TABLE `{$table}` ADD COLUMN {$definition}");
    }
}

function indexExists(mysqli $conn, string $table, string $index): bool {
    $table = $conn->real_escape_string($table);
    $index = $conn->real_escape_string($index);
    $res = $conn->query("SHOW INDEX FROM `{$table}` WHERE Key_name = '{$index}'");
    return $res && $res->num_rows > 0;
}

function dropIndexIfExists(mysqli $conn, string $table, string $index): void {
    if (tableExists($conn, $table) && indexExists($conn, $table, $index)) {
        @$conn->query("ALTER TABLE `{$table}` DROP INDEX `{$index}`");
    }
}

function tableExists(mysqli $conn, string $table): bool {
    $table = $conn->real_escape_string($table);
    $res = $conn->query("SHOW TABLES LIKE '{$table}'");
    return $res && $res->num_rows > 0;
}


function ensureCentralWarehouseItemsTable(mysqli $conn): void {
    if (!tableExists($conn, 'central_warehouse_items')) {
        @$conn->query("CREATE TABLE IF NOT EXISTS `central_warehouse_items` (
            `item_id` INT(11) NOT NULL AUTO_INCREMENT,
            `item_code` VARCHAR(50) NOT NULL,
            `barcode` VARCHAR(100) DEFAULT NULL,
            `item_name` VARCHAR(150) NOT NULL,
            `description` TEXT DEFAULT NULL,
            `item_image` VARCHAR(255) DEFAULT NULL,
            `category` VARCHAR(255) DEFAULT NULL,
            `principal` VARCHAR(150) DEFAULT NULL,
            `unit_type` VARCHAR(50) DEFAULT NULL,
            `default_unit_type_id` INT(11) DEFAULT NULL,
            `default_uom_id` INT(11) DEFAULT NULL,
            `smallest_uom_id` INT(11) DEFAULT NULL,
            `unit_price` DECIMAL(10,2) DEFAULT 0.00,
            `reorder_level` INT(11) DEFAULT 0,
            `status` ENUM('active','inactive') DEFAULT 'active',
            `created_by` INT(11) DEFAULT NULL,
            `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`item_id`),
            UNIQUE KEY `uk_cw_item_code` (`item_code`),
            KEY `idx_cw_item_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    }

    if (tableExists($conn, 'items') && tableExists($conn, 'central_warehouse_stocks')) {
        @$conn->query("INSERT IGNORE INTO central_warehouse_items
            (item_id, item_code, barcode, item_name, description, item_image, category, principal, unit_type, default_unit_type_id, default_uom_id, smallest_uom_id, unit_price, reorder_level, status, created_by, created_at, updated_at)
            SELECT DISTINCT i.item_id, i.item_code, i.barcode, i.item_name, i.description, i.product_image_url, i.category, i.principal, i.unit_type,
                   i.default_unit_type_id, i.default_uom_id, i.smallest_uom_id, COALESCE(i.unit_price,0), COALESCE(i.reorder_level,0), COALESCE(i.status,'active'), i.created_by, i.created_at, i.updated_at
            FROM central_warehouse_stocks cws
            INNER JOIN items i ON i.item_id = cws.item_id");
    }

    if (tableExists($conn, 'item_images') && tableExists($conn, 'central_warehouse_items')) {
        @$conn->query("UPDATE central_warehouse_items cwi
            INNER JOIN (
                SELECT ii.item_id, ii.image_path
                FROM item_images ii
                INNER JOIN (
                    SELECT item_id, MAX(is_primary) AS max_primary, MIN(image_order) AS min_order, MIN(image_id) AS min_image_id
                    FROM item_images
                    GROUP BY item_id
                ) pick ON pick.item_id = ii.item_id
                WHERE ii.is_primary = pick.max_primary
                GROUP BY ii.item_id
            ) img ON img.item_id = cwi.item_id
            SET cwi.item_image = img.image_path
            WHERE (cwi.item_image IS NULL OR cwi.item_image = '')");
    }
}

function getBranchInfo(mysqli $conn, int $branch_id): array {
    $info = ['branch_name' => '', 'business_unit' => ''];
    if ($branch_id <= 0) return $info;

    $stmt = $conn->prepare("SELECT branch_name, business_unit FROM branches WHERE branch_id = ? LIMIT 1");
    if (!$stmt) return $info;

    $stmt->bind_param('i', $branch_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $info['branch_name'] = $row['branch_name'] ?? '';
        $info['business_unit'] = $row['business_unit'] ?? '';
    }
    return $info;
}

function stockStatus($stock, $reorder) {
    $stock = (float)$stock; $reorder = (float)$reorder;
    if ($stock <= 0) return ['class' => 'bg-danger', 'label' => 'Out'];
    if ($reorder > 0 && $stock <= $reorder) return ['class' => 'bg-warning text-dark', 'label' => 'Low'];
    return ['class' => 'bg-success', 'label' => 'Good'];
}

function fetchCentralWarehouseItems(mysqli $conn, int $branch_id, string $business_unit, bool $view_all_branches): array {
    if (!tableExists($conn, 'central_warehouse_stocks')) return [];

    $sql = "SELECT cws.central_stock_id,
                   cws.business_unit,
                   cws.branch_id,
                   cws.item_id,
                   cws.unit_type_id,
                   cws.current_stock AS quantity_on_hand,
                   COALESCE(total_stock.total_qty, cws.current_stock, 0) AS total_item_stock,
                   cws.as_of_date,
                   cws.remarks AS stock_remarks,
                   i.item_code,
                   COALESCE(i.barcode, '') AS barcode,
                   i.item_name,
                   COALESCE(i.description, '') AS description,
                   COALESCE(i.category, 'Uncategorized') AS category,
                   COALESCE(i.principal, 'No Principal') AS principal,
                   COALESCE(i.item_image, '') AS item_image_path,
                   COALESCE(i.reorder_level, 0) AS reorder_level,
                   COALESCE(ut.unit_type_name, i.unit_type, 'Piece') AS unit_type,
                   COALESCE(i.unit_price, 0) AS unit_price,
                   b.branch_name
            FROM central_warehouse_stocks cws
            INNER JOIN central_warehouse_items i ON i.item_id = cws.item_id
            LEFT JOIN unit_types ut ON ut.unit_type_id = cws.unit_type_id
            LEFT JOIN branches b ON b.branch_id = cws.branch_id
            LEFT JOIN (
                SELECT item_id, IFNULL(unit_type_id,0) AS unit_key, SUM(current_stock) AS total_qty
                FROM central_warehouse_stocks
                WHERE status = 'active'
                GROUP BY item_id, IFNULL(unit_type_id,0)
            ) total_stock ON total_stock.item_id = cws.item_id AND total_stock.unit_key = IFNULL(cws.unit_type_id,0)
            WHERE cws.status = 'active'
              AND i.status = 'active'
              AND cws.current_stock > 0";

    $types = '';
    $params = [];

    if (!$view_all_branches) {
        $sql .= " AND cws.branch_id = ?";
        $types .= 'i';
        $params[] = $branch_id;

        if (trim($business_unit) !== '') {
            $sql .= " AND cws.business_unit = ?";
            $types .= 's';
            $params[] = $business_unit;
        }
    }

    $sql .= " ORDER BY i.item_name ASC";

    $stmt = $conn->prepare($sql);
    if (!$stmt) return [];
    if ($types !== '') $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();

    $items = [];
    while ($row = $result->fetch_assoc()) $items[] = $row;
    return $items;
}


function fetchMyAtwRequests(mysqli $conn, int $branch_id, string $business_unit, bool $view_all_branches): array {
    if (!tableExists($conn, 'central_warehouse_atw_requests')) return [];

    $sql = "SELECT MIN(r.request_id) AS request_id,
                   r.request_no,
                   MAX(r.business_unit) AS business_unit,
                   MAX(r.branch_id) AS branch_id,
                   GROUP_CONCAT(CONCAT(i.item_name, ' (', FORMAT(r.requested_qty, 2), ' ', COALESCE(ut.unit_type_name, i.unit_type, 'Piece'), ')') ORDER BY i.item_name SEPARATOR '\n') AS items_summary,
                   COUNT(*) AS item_count,
                   MAX(r.requested_by) AS requested_by,
                   MAX(r.request_date) AS request_date,
                   MAX(r.purpose) AS purpose,
                   CASE
                       WHEN SUM(r.status = 'released') = COUNT(*) THEN 'released'
                       WHEN SUM(r.status = 'cancelled') = COUNT(*) THEN 'cancelled'
                       WHEN SUM(r.status = 'rejected') = COUNT(*) THEN 'rejected'
                       ELSE 'pending'
                   END AS status,
                   MAX(r.released_at) AS released_at,
                   MAX(r.authorized_recipient) AS authorized_recipient,
                   MAX(r.withdrawn_by) AS withdrawn_by,
                   MAX(r.received_by) AS received_by,
                   MAX(r.to_be_returned) AS to_be_returned,
                   MAX(r.return_date) AS return_date,
                   CASE
                       WHEN SUM(r.to_be_returned = 1 AND r.return_status = 'returned') = SUM(r.to_be_returned = 1) AND SUM(r.to_be_returned = 1) > 0 THEN 'returned'
                       WHEN SUM(r.to_be_returned = 1 AND r.status = 'released' AND r.return_status <> 'returned' AND r.return_date < CURDATE()) > 0 THEN 'overdue'
                       WHEN SUM(r.to_be_returned = 1 AND r.status = 'released' AND r.return_status <> 'returned') > 0 THEN 'pending_return'
                       ELSE 'not_required'
                   END AS return_status,
                   MAX(r.returned_at) AS returned_at,
                   MAX(r.returned_by) AS returned_by,
                   MAX(b.branch_name) AS branch_name
            FROM central_warehouse_atw_requests r
            INNER JOIN central_warehouse_items i ON i.item_id = r.item_id
            LEFT JOIN unit_types ut ON ut.unit_type_id = r.unit_type_id
            LEFT JOIN branches b ON b.branch_id = r.branch_id
            WHERE 1=1";

    $types = '';
    $params = [];

    if (!$view_all_branches) {
        $sql .= " AND r.branch_id = ?";
        $types .= 'i';
        $params[] = $branch_id;

        if (trim($business_unit) !== '') {
            $sql .= " AND r.business_unit = ?";
            $types .= 's';
            $params[] = $business_unit;
        }
    }

    $sql .= " GROUP BY r.request_no ORDER BY MAX(r.created_at) DESC, MIN(r.request_id) DESC LIMIT 50";

    $stmt = $conn->prepare($sql);
    if (!$stmt) return [];
    if ($types !== '') $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];
    while ($row = $result->fetch_assoc()) $rows[] = $row;
    return $rows;
}


function fetchCentralWarehouseItemProfile(mysqli $conn, int $central_stock_id, int $branch_id, string $business_unit, bool $view_all_branches): array {
    if (!tableExists($conn, 'central_warehouse_stocks')) return ['success' => false, 'message' => 'Central warehouse stock table is missing.'];

    $sql = "SELECT cws.central_stock_id, cws.business_unit, cws.branch_id, cws.item_id, cws.unit_type_id, cws.current_stock, cws.as_of_date, cws.remarks, cws.encoded_by, cws.created_at, cws.updated_at,
                   i.item_code, i.item_name, COALESCE(i.category, 'Uncategorized') AS category, COALESCE(i.principal, 'No Principal') AS principal,
                   COALESCE(i.description, '') AS description, COALESCE(i.item_image, '') AS item_image_path,
                   COALESCE(ut.unit_type_name, i.unit_type, 'Piece') AS unit_type, b.branch_name,
                   CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, '')) AS encoded_by_name
            FROM central_warehouse_stocks cws
            INNER JOIN central_warehouse_items i ON i.item_id = cws.item_id
            LEFT JOIN unit_types ut ON ut.unit_type_id = cws.unit_type_id
            LEFT JOIN branches b ON b.branch_id = cws.branch_id
            LEFT JOIN users u ON u.user_id = cws.encoded_by
            WHERE cws.central_stock_id = ? AND cws.status = 'active' LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return ['success' => false, 'message' => 'Failed to prepare item profile.'];
    $stmt->bind_param('i', $central_stock_id);
    $stmt->execute();
    $profile = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$profile) return ['success' => false, 'message' => 'Item profile was not found.'];
    if (!$view_all_branches && ((int)$profile['branch_id'] !== $branch_id || (trim($business_unit) !== '' && $profile['business_unit'] !== $business_unit))) {
        return ['success' => false, 'message' => 'This item is not assigned to your branch/business unit.'];
    }

    $receive_history = [[
        'date' => $profile['as_of_date'] ?: substr((string)$profile['created_at'], 0, 10),
        'encoded_at' => $profile['created_at'] ?: '',
        'received_by' => 'Warehouse',
        'encoded_by' => trim((string)($profile['encoded_by_name'] ?? '')) ?: '-',
        'quantity' => (float)$profile['current_stock'],
        'unit' => $profile['unit_type'],
        'remarks' => $profile['remarks'] ?: 'Initial / current warehouse stock record'
    ]];

    $withdrawal_history = [];
    if (tableExists($conn, 'central_warehouse_atw_requests')) {
        $whSql = "SELECT r.request_no, r.requested_qty, r.approved_qty, r.requested_by, r.authorized_recipient, r.withdrawn_by, r.received_by, r.request_date, r.status, r.released_at, r.release_remarks,
                         r.to_be_returned, r.return_date, r.return_status, r.returned_at, r.returned_by, r.return_remarks,
                         CONCAT(COALESCE(ru.first_name, ''), ' ', COALESCE(ru.last_name, '')) AS released_by_name
                  FROM central_warehouse_atw_requests r
                  LEFT JOIN users ru ON ru.user_id = r.released_by
                  WHERE r.central_stock_id = ? OR (r.item_id = ? AND r.branch_id = ? AND r.business_unit = ?)
                  ORDER BY COALESCE(r.released_at, r.created_at) DESC, r.request_id DESC
                  LIMIT 80";
        $whStmt = $conn->prepare($whSql);
        if ($whStmt) {
            $item_id = (int)$profile['item_id'];
            $profile_branch_id = (int)$profile['branch_id'];
            $profile_bu = (string)$profile['business_unit'];
            $whStmt->bind_param('iiis', $central_stock_id, $item_id, $profile_branch_id, $profile_bu);
            $whStmt->execute();
            $res = $whStmt->get_result();
            while ($row = $res->fetch_assoc()) $withdrawal_history[] = $row;
            $whStmt->close();
        }
    }

    return ['success' => true, 'profile' => $profile, 'receive_history' => $receive_history, 'withdrawal_history' => $withdrawal_history];
}

function requestStatusBadge(string $status): array {
    $status = strtolower(trim($status));
    if ($status === 'released') return ['class' => 'bg-success', 'label' => 'Released'];
    if ($status === 'cancelled') return ['class' => 'bg-danger', 'label' => 'Cancelled'];
    return ['class' => 'bg-warning text-dark', 'label' => 'Pending Release'];
}

function atwOverallStatusBadge(array $req): array {
    $status = strtolower(trim((string)($req['status'] ?? 'pending')));
    $return_status = strtolower(trim((string)($req['return_status'] ?? '')));
    $returned_at = trim((string)($req['returned_at'] ?? ''));

    if ($return_status === 'returned' || $returned_at !== '') {
        return ['class' => 'bg-primary', 'label' => 'Returned'];
    }

    if ($status === 'released') {
        return ['class' => 'bg-success', 'label' => 'Released'];
    }

    return ['class' => 'bg-warning text-dark', 'label' => 'Pending'];
}

function returnDateDisplay($to_be_returned, ?string $return_date): string {
    return ((int)$to_be_returned === 1) ? (($return_date && trim($return_date) !== '') ? $return_date : 'N/A') : 'Not Required';
}

function returnStatusBadge($to_be_returned, ?string $return_date, ?string $return_status, ?string $returned_at, string $status): array {
    $to_be_returned = (int)$to_be_returned;
    $status = strtolower(trim($status));
    $return_status = strtolower(trim((string)$return_status));

    if ($to_be_returned !== 1) {
        return ['class' => 'bg-secondary', 'label' => 'Not Required'];
    }

    if ($return_status === 'returned' || !empty($returned_at)) {
        return ['class' => 'bg-success', 'label' => 'Returned'];
    }

    if ($status !== 'released') {
        return ['class' => 'bg-secondary', 'label' => 'After Release'];
    }

    if (!empty($return_date) && $return_date < date('Y-m-d')) {
        return ['class' => 'bg-danger', 'label' => 'Overdue'];
    }

    return ['class' => 'bg-warning text-dark', 'label' => 'Pending Return'];
}

function syncReturnStatuses(mysqli $conn): void {
    if (!tableExists($conn, 'central_warehouse_atw_requests') || !columnExists($conn, 'central_warehouse_atw_requests', 'return_status')) return;

    @$conn->query("UPDATE central_warehouse_atw_requests
                  SET return_status = 'not_required'
                  WHERE COALESCE(to_be_returned, 0) = 0");

    @$conn->query("UPDATE central_warehouse_atw_requests
                  SET return_status = 'returned'
                  WHERE COALESCE(to_be_returned, 0) = 1
                    AND returned_at IS NOT NULL");

    @$conn->query("UPDATE central_warehouse_atw_requests
                  SET return_status = 'overdue'
                  WHERE COALESCE(to_be_returned, 0) = 1
                    AND status = 'released'
                    AND returned_at IS NULL
                    AND return_date IS NOT NULL
                    AND return_date < CURDATE()");

    @$conn->query("UPDATE central_warehouse_atw_requests
                  SET return_status = 'pending_return'
                  WHERE COALESCE(to_be_returned, 0) = 1
                    AND status = 'released'
                    AND returned_at IS NULL
                    AND (return_date IS NULL OR return_date >= CURDATE())");
}

ensureCentralWarehouseItemsTable($conn);

$branch_info = getBranchInfo($conn, $branch_id);
$branch_name = $branch_name ?: ($branch_info['branch_name'] ?: 'Motorpool');
$branch_business_unit = $branch_business_unit ?: ($branch_info['business_unit'] ?: 'Motorpool');
$central_stock_table_ready = tableExists($conn, 'central_warehouse_stocks');
$central_atw_table_ready = tableExists($conn, 'central_warehouse_atw_requests');
addColumnIfMissing($conn, 'central_warehouse_atw_requests', 'authorized_recipient', "authorized_recipient VARCHAR(150) DEFAULT NULL AFTER requested_by");
addColumnIfMissing($conn, 'central_warehouse_atw_requests', 'withdrawn_by', "withdrawn_by VARCHAR(150) DEFAULT NULL AFTER authorized_recipient");
addColumnIfMissing($conn, 'central_warehouse_atw_requests', 'atw_group_note', "atw_group_note VARCHAR(80) DEFAULT NULL AFTER withdrawn_by");
addColumnIfMissing($conn, 'central_warehouse_atw_requests', 'to_be_returned', "to_be_returned TINYINT(1) NOT NULL DEFAULT 0 AFTER atw_group_note");
addColumnIfMissing($conn, 'central_warehouse_atw_requests', 'return_date', "return_date DATE DEFAULT NULL AFTER to_be_returned");
addColumnIfMissing($conn, 'central_warehouse_atw_requests', 'return_status', "return_status ENUM('not_required','pending_return','returned','overdue') NOT NULL DEFAULT 'not_required' AFTER return_date");
addColumnIfMissing($conn, 'central_warehouse_atw_requests', 'returned_at', "returned_at DATETIME DEFAULT NULL AFTER return_status");
addColumnIfMissing($conn, 'central_warehouse_atw_requests', 'returned_by', "returned_by VARCHAR(150) DEFAULT NULL AFTER returned_at");
addColumnIfMissing($conn, 'central_warehouse_atw_requests', 'return_remarks', "return_remarks TEXT DEFAULT NULL AFTER returned_by");
dropIndexIfExists($conn, 'central_warehouse_atw_requests', 'uk_atw_request_no');
syncReturnStatuses($conn);


if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'fetch_item_profile') {
    header('Content-Type: application/json');
    $central_stock_id = (int)($_GET['central_stock_id'] ?? 0);
    if ($central_stock_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid item selected.']);
        exit;
    }
    echo json_encode(fetchCentralWarehouseItemProfile($conn, $central_stock_id, $branch_id, $branch_business_unit, (bool)$view_all_branches));
    exit;
}



if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_atw') {
    header('Content-Type: application/json');

    if (!$central_atw_table_ready || !$central_stock_table_ready) {
        echo json_encode(['success' => false, 'message' => 'Missing central warehouse database tables. Run the SQL patch first.']);
        exit;
    }

    $central_stock_id = (int)($_POST['central_stock_id'] ?? 0);
    $qty = (float)($_POST['quantity'] ?? 0);
    $requested_by = trim($_POST['requested_by'] ?? $user_name);
    $request_date = trim($_POST['request_date'] ?? date('Y-m-d'));
    $purpose = trim($_POST['purpose'] ?? '');
    $to_be_returned = isset($_POST['to_be_returned']) && (string)$_POST['to_be_returned'] === '1' ? 1 : 0;
    $return_date = trim($_POST['return_date'] ?? '');
    if ($to_be_returned === 1 && $return_date === '') {
        echo json_encode(['success' => false, 'message' => 'Please select the return date.']);
        exit;
    }
    if ($to_be_returned === 1 && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $return_date)) {
        echo json_encode(['success' => false, 'message' => 'Invalid return date format.']);
        exit;
    }
    if ($to_be_returned === 0) $return_date = null;
    $return_status = $to_be_returned === 1 ? 'pending_return' : 'not_required';

    if ($central_stock_id <= 0 || $qty <= 0) {
        echo json_encode(['success' => false, 'message' => 'Please select an item and enter a valid quantity.']);
        exit;
    }

    $stock_stmt = $conn->prepare("SELECT central_stock_id, business_unit, branch_id, item_id, unit_type_id, current_stock FROM central_warehouse_stocks WHERE central_stock_id = ? AND status = 'active' LIMIT 1");
    $stock_stmt->bind_param('i', $central_stock_id);
    $stock_stmt->execute();
    $stock = $stock_stmt->get_result()->fetch_assoc();

    if (!$stock) {
        echo json_encode(['success' => false, 'message' => 'Selected stock record was not found.']);
        exit;
    }

    if (!$view_all_branches && ((int)$stock['branch_id'] !== $branch_id || (trim($branch_business_unit) !== '' && $stock['business_unit'] !== $branch_business_unit))) {
        echo json_encode(['success' => false, 'message' => 'This item is not assigned to your branch/business unit.']);
        exit;
    }

    if ($qty > (float)$stock['current_stock']) {
        echo json_encode(['success' => false, 'message' => 'Quantity cannot exceed available stock.']);
        exit;
    }

    $request_no = 'ATW-' . date('YmdHis') . '-' . $user_id;
    $status = 'pending';

    $insert = $conn->prepare("INSERT INTO central_warehouse_atw_requests
        (request_no, central_stock_id, business_unit, branch_id, item_id, unit_type_id, requested_qty, requested_by, requested_by_user_id, request_date, purpose, status, to_be_returned, return_date, return_status, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");

    if (!$insert) {
        echo json_encode(['success' => false, 'message' => 'Failed to prepare request save.']);
        exit;
    }

    $insert->bind_param(
        'sisiiidsisssiss',
        $request_no,
        $central_stock_id,
        $stock['business_unit'],
        $stock['branch_id'],
        $stock['item_id'],
        $stock['unit_type_id'],
        $qty,
        $requested_by,
        $user_id,
        $request_date,
        $purpose,
        $status,
        $to_be_returned,
        $return_date,
        $return_status
    );

    if ($insert->execute()) {
        echo json_encode(['success' => true, 'message' => 'ATW request submitted successfully. Waiting for warehouse release.', 'request_no' => $request_no]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to save ATW request.']);
    }
    exit;
}

$user_initials = '';
foreach (explode(' ', trim($user_name)) as $part) { if ($part !== '') $user_initials .= strtoupper(substr($part, 0, 1)); }
if ($user_initials === '') $user_initials = 'MP';

$items = fetchCentralWarehouseItems($conn, $branch_id, $branch_business_unit, (bool)$view_all_branches);
$atw_requests = fetchMyAtwRequests($conn, $branch_id, $branch_business_unit, (bool)$view_all_branches);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Central Warehouse - Motorpool</title>
<link rel="icon" type="image/png" href="../Pictures/favicon-96x96.png" sizes="96x96" />
<link rel="stylesheet" href="../css/current_inventory.css">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
<style>
.form-card {
    background:#fff;
    border-radius:14px;
    padding:18px;
    box-shadow:0 2px 10px rgba(0,0,0,.05)
}
.custom-table th {
    background:#052A47;
    color:#fff;
    white-space:nowrap
}
.custom-table td {
    vertical-align:middle
}
.clickable-item-row {
    cursor:pointer;
}
.clickable-item-row,
.clickable-atw-row {
    cursor:pointer;
}
.clickable-item-row:hover,
.clickable-atw-row:hover {
    background:#f8fafc;
}
.dashboard-tabs .nav-link {
    color: #047857;
    font-weight:700;
    border-radius:10px 10px 0 0;
}
.dashboard-tabs .nav-link.active {
    background: #047857;
    color: #fff;
    border-color: #047857;
}
.tab-card {
    background:#fff;
    border-radius:0 14px 14px 14px;
    padding:18px;
    box-shadow:0 2px 10px rgba(0,0,0,.05);
}
.atw-detail-grid {
    display:grid;
    grid-template-columns:repeat(3, minmax(0, 1fr));
    gap:12px 16px;
}
.atw-detail-item {
    border-bottom:1px solid #eef2f6;
    padding-bottom:8px;
}
.atw-detail-label {
    font-size:.78rem;
    color:#6c757d;
}
.atw-detail-value {
    font-weight:700;
    color:#052A47;
    white-space:pre-line;
    word-break:break-word;
}
@media (max-width: 768px) {
    .atw-detail-grid { grid-template-columns:1fr; }
}
.history-table th {
    background:#052A47;
    color:#fff;
    white-space:nowrap;
}
.profile-info-label {
    font-size:.78rem;
    color:#6c757d;
}
.profile-info-value {
    font-weight:700;
    color:#052A47;
}

.profile-summary-grid {
    display:grid;
    grid-template-columns:repeat(4, minmax(0, 1fr));
    gap:12px 16px;
    margin-bottom:16px;
}
.profile-summary-item {
    border-bottom:1px solid #eef2f6;
    padding-bottom:8px;
    min-width:0;
}
.profile-summary-item .profile-info-value,
.profile-summary-item div:last-child {
    white-space:normal;
    word-break:break-word;
}
.history-table th,
.history-table td {
    text-align:center;
}
.history-table td:first-child,
.history-table th:first-child {
    text-align:left;
}
@media (max-width: 992px) {
    .profile-summary-grid { grid-template-columns:repeat(2, minmax(0, 1fr)); }
}
@media (max-width: 576px) {
    .profile-summary-grid { grid-template-columns:1fr; }
}
.item-thumbnail {
    width:46px;
    height:46px;
    border-radius:8px;
    background:#f1f3f5;
    display:flex;
    align-items:center;
    justify-content:center;
    overflow:hidden auto
}
.item-thumbnail img {
    width:100%;
    height:100%;
    object-fit:cover
}
.btn-action-text {
    white-space:nowrap;
    border-radius:8px;
}
.status-card {
    background:#fff;
    border-radius:14px;
    padding:18px;
    box-shadow:0 2px 10px rgba(0,0,0,.05);
    margin-top:18px;
}
.section-title {
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    margin-bottom:12px;
}
.section-title h5 {
    margin:0;
    font-weight:700;
    color:#052A47;
}
.header-actions {
    margin-left:auto;
    display:flex;
    align-items:center;
    gap:10px;
}
.header-actions .btn-action-text {
    padding:8px 14px;
}
@media (max-width: 576px) {
    .navbar-top {
        align-items:flex-start;
        gap:10px;
    }
    .header-actions {
        width:100%;
        justify-content:flex-start;
        margin-left:0;
    }
}


.sidebar-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,.35);
    z-index: 998;
    opacity: 0;
    transition: opacity .25s ease;
}
.sidebar-overlay.active {
    opacity: 1;
}
.dropdown-arrow {
    margin-left: auto;
    transition: transform .2s ease;
}
@media (max-width: 992px) {
    .sidebar {
        transform: translateX(-100%);
        transition: transform .25s ease;
        z-index: 999;
    }
    .sidebar.active,
    .sidebar.show {
        transform: translateX(0);
    }
}

/* Mobile Bottom Navigation Styles */
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

/* Small mobile adjustments */
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


.inventory-search-wrap {
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    margin-bottom:14px;
    flex-wrap:wrap;
}
.inventory-search-box {
    position:relative;
    width:100%;
    max-width:380px;
}
.inventory-search-box i {
    position:absolute;
    left:14px;
    top:50%;
    transform:translateY(-50%);
    color:#6b7280;
    pointer-events:none;
}
.inventory-search-box .form-control {
    padding-left:40px;
    border-radius:10px;
    border:1px solid #d1d5db;
    box-shadow:none;
}
.inventory-search-box .form-control:focus {
    border-color:#067857;
    box-shadow:0 0 0 .2rem rgba(6,120,87,.12);
}
.inventory-search-count {
    color:#6c757d;
    font-size:.9rem;
    font-weight:600;
}
.inventory-empty-search {
    display:none;
}
@media (max-width: 576px) {
    .inventory-search-wrap {
        align-items:stretch;
    }
    .inventory-search-box {
        max-width:100%;
    }
}

/* Skeleton Loading */
.skeleton{
    position:relative;
    overflow:hidden;
    background:#e5e7eb;
    border-radius:8px;
}

.skeleton::after{
    content:'';
    position:absolute;
    top:0;
    left:-150%;
    width:150%;
    height:100%;
    background:linear-gradient(
        90deg,
        transparent,
        rgba(255,255,255,.6),
        transparent
    );
    animation:skeletonLoading 1.2s infinite;
}

@keyframes skeletonLoading{
    100%{
        left:100%;
    }
}

.skeleton-title{
    height:32px;
    width:280px;
    margin-bottom:20px;
}

.skeleton-card{
    height:75px;
    width:100%;
}

.skeleton-section{
    height:24px;
    width:200px;
    margin:20px 0;
}

.skeleton-row{
    height:40px;
    width:100%;
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
                    <a class="nav-link" href="motorpool_inventory.php">
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
                                <a class="nav-link active" href="central_warehouse.php">
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

<main class="main-content" id="mainContent">
    <div id="dashboardContent" class="page-content active">
    <div class="navbar-top">
        <button class="mobile-toggle-btn" id="mobileToggleBtn"><i class="bi bi-list"></i></button>
        <div class="page-title">
            <h2>Central Warehouse</h2>
            <p id="dashboardSubtitle">Inventory List / Authority to Withdraw</p>
        </div>
        <div class="header-actions">
            <a class="btn btn-success btn-action-text" href="atworderproduct.php">
                <i class="bi bi-box-arrow-up me-1"></i>Authority to Withdraw
            </a>
        </div>
    </div>
  <ul class="nav nav-tabs dashboard-tabs mb-0" role="tablist">
    <li class="nav-item" role="presentation">
      <button class="nav-link active" id="inventory-tab" data-bs-toggle="tab" data-bs-target="#inventoryPane" type="button" role="tab">
        Inventory List
      </button>
    </li>
    <li class="nav-item" role="presentation">
      <button class="nav-link" id="atw-tab" data-bs-toggle="tab" data-bs-target="#atwPane" type="button" role="tab">
        My ATW Requests
      </button>
    </li>
  </ul>

  <div class="tab-content">
    <div class="tab-pane fade show active" id="inventoryPane" role="tabpanel" aria-labelledby="inventory-tab">
      <div class="tab-card">
        <?php if (!$central_stock_table_ready || !$central_atw_table_ready): ?>
          <div class="alert alert-warning">
            Central Warehouse tables are not yet created. Please run the SQL patch first before using this page.
          </div>
        <?php endif; ?>
        <div class="inventory-search-wrap">
          <div class="inventory-search-box">
            <i class="bi bi-search"></i>
            <input type="text" class="form-control" id="inventoryGlobalSearch" placeholder="Search inventory...">
          </div>
          <div class="inventory-search-count" id="inventorySearchCount">Showing <?= count($items) ?> item<?= count($items) === 1 ? '' : 's' ?></div>
        </div>
        <div class="table-responsive">
          <table class="table custom-table compact-table align-middle" id="inventoryListTable">
            <thead><tr><th>Image</th><th>Item Name</th><th>Category</th><th>Principal</th><th>Stock/Profile</th></tr></thead>
            <tbody>
            <?php if (empty($items)): ?>
              <tr><td colspan="5" class="text-center text-muted py-4">No central warehouse items assigned to your branch/business unit.</td></tr>
            <?php else: foreach ($items as $item): $status = stockStatus($item['quantity_on_hand'], $item['reorder_level']); ?>
              <tr class="clickable-item-row" onclick="openItemProfile(this)" data-stock-id="<?= h($item['central_stock_id']) ?>" data-id="<?= h($item['item_id']) ?>" data-code="<?= h($item['item_code']) ?>" data-name="<?= h($item['item_name']) ?>" data-category="<?= h($item['category']) ?>" data-principal="<?= h($item['principal']) ?>" data-stock="<?= h(number_format((float)$item['quantity_on_hand'], 2)) ?>" data-stock-raw="<?= h((float)$item['quantity_on_hand']) ?>" data-total-stock="<?= h((float)($item['total_item_stock'] ?? $item['quantity_on_hand'])) ?>" data-unit="<?= h($item['unit_type']) ?>" data-description="<?= h($item['description']) ?>" data-branch="<?= h($item['branch_id'] ?? '') ?>" data-business-unit="<?= h($item['business_unit'] ?? '') ?>">
                <td>
                    <div class="item-thumbnail">
                        <?php if (!empty($item['item_image_path'])): ?>
                            <img src="../uploads/central_warehouse_items/<?= h($item['item_image_path']) ?>" alt="<?= h($item['item_name']) ?>">
                        <?php else: ?>
                            <i class="bi bi-image text-muted"></i>
                        <?php endif; ?>
                    </div>
                </td>
                <td><strong><?= h($item['item_name']) ?></strong><br><small class="text-muted"><?= h($item['item_code']) ?></small></td>
                <td><?= h($item['category']) ?></td>
                <td><?= h($item['principal']) ?></td>
                <td>
                    <span class="fw-semibold"><?= h(number_format((float)$item['quantity_on_hand'], 2)) ?> <?= h($item['unit_type']) ?></span>
                    <span class="badge <?= h($status['class']) ?> ms-1"><?= h($status['label']) ?></span>
                </td>
              </tr>
            <?php endforeach; endif; ?>
              <tr id="inventoryNoSearchResult" class="inventory-empty-search"><td colspan="5" class="text-center text-muted py-4">No inventory item matched your search.</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <div class="tab-pane fade" id="atwPane" role="tabpanel" aria-labelledby="atw-tab">
      <div class="tab-card">
        <div class="table-responsive">
          <table class="table custom-table compact-table align-middle">
            <thead>
              <tr>
                <th>ATW No.</th>
                <th>Date</th>
                <th>Authorized Recipient</th>
                <th>Status</th>
                <th>Return Date</th>
              </tr>
            </thead>
            <tbody>
            <?php if (empty($atw_requests)): ?>
              <tr><td colspan="5" class="text-center text-muted py-4">No ATW requests yet.</td></tr>
            <?php else: foreach ($atw_requests as $req):
                $overallBadge = atwOverallStatusBadge($req);
                $returnDateText = returnDateDisplay($req['to_be_returned'] ?? 0, $req['return_date'] ?? null);
                $withdrawnByText = (($req['status'] ?? '') === 'released' || $overallBadge['label'] === 'Returned') ? (($req['withdrawn_by'] ?: $req['received_by']) ?: '-') : '-';
            ?>
              <tr class="clickable-atw-row" onclick="openAtwDetails(this)"
                  data-request-no="<?= h($req['request_no']) ?>"
                  data-request-date="<?= h($req['request_date'] ?: '-') ?>"
                  data-business-unit="<?= h($req['business_unit'] ?: '-') ?>"
                  data-branch-name="<?= h($req['branch_name'] ?: '-') ?>"
                  data-items="<?= h($req['items_summary'] ?: '-') ?>"
                  data-item-count="<?= h($req['item_count'] ?: '0') ?>"
                  data-requested-by="<?= h($req['requested_by'] ?: '-') ?>"
                  data-purpose="<?= h($req['purpose'] ?: '-') ?>"
                  data-authorized-recipient="<?= h($req['authorized_recipient'] ?: '-') ?>"
                  data-withdrawn-by="<?= h($withdrawnByText) ?>"
                  data-released-at="<?= h($req['released_at'] ?: '-') ?>"
                  data-return-date="<?= h($returnDateText) ?>"
                  data-return-status="<?= h($req['return_status'] ?: '-') ?>"
                  data-returned-at="<?= h($req['returned_at'] ?: '-') ?>"
                  data-returned-by="<?= h($req['returned_by'] ?: '-') ?>"
                  data-status-label="<?= h($overallBadge['label']) ?>"
                  data-status-class="<?= h($overallBadge['class']) ?>">
                <td><strong><?= h($req['request_no']) ?></strong></td>
                <td><?= h($req['request_date'] ?: '-') ?></td>
                <td><?= h($req['authorized_recipient'] ?: '-') ?></td>
                <td><span class="badge <?= h($overallBadge['class']) ?>"><?= h($overallBadge['label']) ?></span></td>
                <td><?= h($returnDateText) ?></td>
              </tr>
            <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</main>




<div class="modal fade" id="itemProfileModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header" style="background:#067857;color:#fff;">
        <h5 class="modal-title"><i class="bi bi-box-seam me-2"></i>Central Warehouse Item Profile</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body" id="itemProfileModalBody">
        <div class="text-center text-muted py-4">Loading item profile...</div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="atwDetailsModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header" style="background:#067857;color:#fff;">
        <h5 class="modal-title"><i class="bi bi-clipboard-data me-2"></i>ATW Withdrawal Details</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body" id="atwDetailsModalBody"></div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<!-- Mobile Bottom Navigation -->
    <div class="mobile-nav" id="mobileNav">
        <ul class="nav">
            <li class="nav-item">
                <a class="nav-link" href="motorpool_inventory.php">
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
                <a class="nav-link more-btn active" href="#" onclick="toggleMobileDropdown(event, 'companyMobileMenu')">
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
                    <a class="dropdown-item active" href="central_warehouse.php"><i class="bi bi-box-seam"></i><span>Central
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
}

function formatQty(value) {
    const n = parseFloat(value || 0);
    return Number.isFinite(n) ? n.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}) : '0.00';
}

function formatDateValue(value) {
    if (!value) return '-';
    return String(value).replace('T', ' ');
}



function initInventoryGlobalSearch() {
    const searchInput = document.getElementById('inventoryGlobalSearch');
    const table = document.getElementById('inventoryListTable');
    const countLabel = document.getElementById('inventorySearchCount');
    const noResultRow = document.getElementById('inventoryNoSearchResult');

    if (!searchInput || !table) return;

    const rows = Array.from(table.querySelectorAll('tbody tr.clickable-item-row'));
    const totalRows = rows.length;

    function updateSearch() {
        const keyword = searchInput.value.trim().toLowerCase();
        let visibleCount = 0;

        rows.forEach(row => {
            const searchableText = [
                row.dataset.code,
                row.dataset.name,
                row.dataset.category,
                row.dataset.principal,
                row.dataset.stock,
                row.dataset.unit,
                row.dataset.description,
                row.dataset.businessUnit,
                row.dataset.branch,
                row.textContent
            ].join(' ').toLowerCase();

            const matched = keyword === '' || searchableText.includes(keyword);
            row.style.display = matched ? '' : 'none';
            if (matched) visibleCount++;
        });

        if (noResultRow) {
            noResultRow.style.display = keyword !== '' && visibleCount === 0 && totalRows > 0 ? '' : 'none';
        }

        if (countLabel) {
            countLabel.textContent = `Showing ${visibleCount} of ${totalRows} item${totalRows === 1 ? '' : 's'}`;
        }
    }

    searchInput.addEventListener('input', updateSearch);
    updateSearch();
}

function openAtwDetails(row) {
    if (!row || !row.dataset) return;

    const modalEl = document.getElementById('atwDetailsModal');
    const body = document.getElementById('atwDetailsModalBody');
    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    const d = row.dataset;

    const statusClass = d.statusClass || 'bg-warning text-dark';
    const statusLabel = d.statusLabel || 'Pending';

    body.innerHTML = `
        <div class="mb-3 d-flex flex-wrap align-items-center justify-content-between gap-2">
            <div>
                <div class="profile-info-label">ATW No.</div>
                <h5 class="mb-0" style="color:#052A47;">${escapeHtml(d.requestNo || '-')}</h5>
            </div>
            <span class="badge ${escapeHtml(statusClass)} fs-6">${escapeHtml(statusLabel)}</span>
        </div>
        <div class="atw-detail-grid">
            <div class="atw-detail-item"><div class="atw-detail-label">Date</div><div class="atw-detail-value">${escapeHtml(d.requestDate || '-')}</div></div>
            <div class="atw-detail-item"><div class="atw-detail-label">Business Unit</div><div class="atw-detail-value">${escapeHtml(d.businessUnit || '-')}</div></div>
            <div class="atw-detail-item"><div class="atw-detail-label">Branch</div><div class="atw-detail-value">${escapeHtml(d.branchName || '-')}</div></div>
            <div class="atw-detail-item"><div class="atw-detail-label">Requested By</div><div class="atw-detail-value">${escapeHtml(d.requestedBy || '-')}</div></div>
            <div class="atw-detail-item"><div class="atw-detail-label">Authorized Recipient</div><div class="atw-detail-value">${escapeHtml(d.authorizedRecipient || '-')}</div></div>
            <div class="atw-detail-item"><div class="atw-detail-label">Withdrawn / Received By</div><div class="atw-detail-value">${escapeHtml(d.withdrawnBy || '-')}</div></div>
            <div class="atw-detail-item"><div class="atw-detail-label">Released At</div><div class="atw-detail-value">${escapeHtml(d.releasedAt || '-')}</div></div>
            <div class="atw-detail-item"><div class="atw-detail-label">Return Date</div><div class="atw-detail-value">${escapeHtml(d.returnDate || 'Not Required')}</div></div>
            <div class="atw-detail-item"><div class="atw-detail-label">Returned At</div><div class="atw-detail-value">${escapeHtml(d.returnedAt || '-')}</div></div>
            <div class="atw-detail-item"><div class="atw-detail-label">Returned By</div><div class="atw-detail-value">${escapeHtml(d.returnedBy || '-')}</div></div>
            <div class="atw-detail-item"><div class="atw-detail-label">Total Item</div><div class="atw-detail-value">${escapeHtml(d.itemCount || '0')}</div></div>
            <div class="atw-detail-item"><div class="atw-detail-label">Purpose</div><div class="atw-detail-value">${escapeHtml(d.purpose || '-')}</div></div>
        </div>
        <hr>
        <div class="profile-info-label mb-1">Items</div>
        <div class="p-3 rounded" style="background:#f8fafc;white-space:pre-line;color:#052A47;font-weight:600;">${escapeHtml(d.items || '-')}</div>
    `;

    modal.show();
}

function openItemProfile(row) {
    const stockId = row?.dataset?.stockId;
    if (!stockId) return;

    const modalEl = document.getElementById('itemProfileModal');
    const body = document.getElementById('itemProfileModalBody');
    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    body.innerHTML = `
<div class="skeleton-profile">
    <div class="skeleton skeleton-title"></div>

    <div class="profile-summary-grid">
        <div class="skeleton skeleton-card"></div>
        <div class="skeleton skeleton-card"></div>
        <div class="skeleton skeleton-card"></div>
        <div class="skeleton skeleton-card"></div>
    </div>

    <div class="skeleton skeleton-section"></div>

    <table class="table">
        <tbody>
            <tr><td><div class="skeleton skeleton-row"></div></td></tr>
            <tr><td><div class="skeleton skeleton-row"></div></td></tr>
            <tr><td><div class="skeleton skeleton-row"></div></td></tr>
            <tr><td><div class="skeleton skeleton-row"></div></td></tr>
        </tbody>
    </table>
</div>
`;
    modal.show();

    fetch(`central_warehouse.php?action=fetch_item_profile&central_stock_id=${encodeURIComponent(stockId)}`)
        .then(response => response.json())
        .then(data => {
            if (!data.success) {
                body.innerHTML = `<div class="alert alert-warning mb-0">${escapeHtml(data.message || 'Unable to load item profile.')}</div>`;
                return;
            }
            renderItemProfile(data);
        })
        .catch(() => {
            body.innerHTML = '<div class="alert alert-danger mb-0">Something went wrong while loading the item profile.</div>';
        });
}

function renderItemProfile(data) {
    const body = document.getElementById('itemProfileModalBody');
    const p = data.profile || {};
    const receiveRows = (data.receive_history || []).map(row => `
        <tr>
            <td>${escapeHtml(formatDateValue(row.date))}</td>
            <td>${escapeHtml(row.received_by || '-')}</td>
            <td>${escapeHtml(row.encoded_by || '-')}</td>
            <td>${formatQty(row.quantity)} ${escapeHtml(row.unit || '')}</td>
            <td>${escapeHtml(row.remarks || '-')}</td>
        </tr>
    `).join('') || '<tr><td colspan="5" class="text-center text-muted py-3" style="text-align:center !important;">No receive history found.</td></tr>';

    const withdrawalRows = (data.withdrawal_history || []).map(row => {
        const authorizedRecipient = row.authorized_recipient || '-';
        const isReleased = row.status === 'released';
        const withdrawnBy = isReleased ? (row.withdrawn_by || row.received_by || '-') : '-';
        const receivedBy = isReleased ? (row.received_by || row.withdrawn_by || '-') : '-';
        const releasedBy = row.released_by_name || '-';
        const qty = row.approved_qty || row.requested_qty || 0;
        const statusClass = row.status === 'released' ? 'bg-success' : (row.status === 'rejected' || row.status === 'cancelled' ? 'bg-danger' : 'bg-warning text-dark');
        const isReturnable = Number(row.to_be_returned || 0) === 1;
        const returnedAt = row.returned_at || '';
        let returnStatus = row.return_status || (isReturnable ? 'pending_return' : 'not_required');
        if (isReturnable && !returnedAt && row.status === 'released' && row.return_date) {
            const today = new Date();
            today.setHours(0, 0, 0, 0);
            const dueDate = new Date(row.return_date + 'T00:00:00');
            if (dueDate < today) returnStatus = 'overdue';
        }
        const returnStatusClass = !isReturnable ? 'bg-secondary' : (returnStatus === 'returned' ? 'bg-success' : (returnStatus === 'overdue' ? 'bg-danger' : 'bg-warning text-dark'));
        const returnStatusLabel = !isReturnable ? 'Not Required' : (returnStatus === 'returned' ? 'Returned' : (returnStatus === 'overdue' ? 'Overdue' : 'Pending Return'));
        const returnInfo = isReturnable ? `<br><small class="text-muted">Return Date: ${escapeHtml(row.return_date || '-')}</small><br><span class="badge ${returnStatusClass}">${returnStatusLabel}</span>` : '<br><span class="badge bg-secondary">Not Required</span>';
        return `
            <tr>
                <td><strong>${escapeHtml(row.request_no || '-')}</strong>${returnInfo}</td>
                <td>${escapeHtml(formatDateValue(row.released_at || row.request_date || '-'))}</td>
                <td>${escapeHtml(authorizedRecipient)}</td>
                <td>${escapeHtml(withdrawnBy)}</td>
                <td>${escapeHtml(receivedBy)}</td>
                <td>${escapeHtml(releasedBy)}</td>
                <td>${formatQty(qty)} ${escapeHtml(p.unit_type || '')}</td>
                <td><span class="badge ${statusClass}">${escapeHtml(row.status || 'pending')}</span></td>
                <td>${escapeHtml(returnedAt || '-')}</td>
                <td>${escapeHtml(row.returned_by || '-')}</td>
                <td>${escapeHtml(row.release_remarks || row.return_remarks || '-')}</td>
            </tr>
        `;
    }).join('') || '<tr><td colspan="11" class="text-center text-muted py-3" style="text-align:center !important;">No withdrawal history found.</td></tr>'

    const profileImage = p.item_image_path
        ? `<div class="mb-3 d-flex align-items-center gap-3"><div class="item-thumbnail" style="width:70px;height:70px;"><img src="../uploads/central_warehouse_items/${escapeHtml(p.item_image_path)}" alt="${escapeHtml(p.item_name || 'Item')}"></div><div><div class="profile-info-label">Item Image</div><div class="profile-info-value">Uploaded from Central Warehouse Encode Stocks</div></div></div>`
        : '';

    body.innerHTML = `
        ${profileImage}
        <div class="profile-summary-grid">
            <div class="profile-summary-item">
                <div class="profile-info-label">Item Code</div>
                <div class="profile-info-value">${escapeHtml(p.item_code || '-')}</div>
            </div>
            <div class="profile-summary-item">
                <div class="profile-info-label">Item Name</div>
                <div class="profile-info-value">${escapeHtml(p.item_name || '-')}</div>
            </div>
            <div class="profile-summary-item">
                <div class="profile-info-label">Current Stock</div>
                <div class="profile-info-value">${formatQty(p.current_stock)} ${escapeHtml(p.unit_type || '')}</div>
            </div>
            <div class="profile-summary-item">
                <div class="profile-info-label">Branch</div>
                <div class="profile-info-value">${escapeHtml(p.branch_name || '-')}</div>
            </div>
            <div class="profile-summary-item">
                <div class="profile-info-label">Category</div>
                <div class="profile-info-value">${escapeHtml(p.category || '-')}</div>
            </div>
            <div class="profile-summary-item">
                <div class="profile-info-label">Principal</div>
                <div class="profile-info-value">${escapeHtml(p.principal || '-')}</div>
            </div>
            <div class="profile-summary-item">
                <div class="profile-info-label">Business Unit</div>
                <div class="profile-info-value">${escapeHtml(p.business_unit || '-')}</div>
            </div>
            <div class="profile-summary-item">
                <div class="profile-info-label">As of Date</div>
                <div class="profile-info-value">${escapeHtml(p.as_of_date || '-')}</div>
            </div>
        </div>

        <ul class="nav nav-tabs" role="tablist">
            <li class="nav-item" role="presentation"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#receiveHistoryTab" type="button">Receive History</button></li>
            <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#withdrawalHistoryTab" type="button">Withdrawal History</button></li>
        </ul>
        <div class="tab-content pt-3">
            <div class="tab-pane fade show active" id="receiveHistoryTab">
                <div class="table-responsive">
                    <table class="table table-sm history-table align-middle">
                        <thead><tr><th>Date</th><th>Received By</th><th>Encoded By</th><th>Quantity</th><th>Remarks</th></tr></thead>
                        <tbody>${receiveRows}</tbody>
                    </table>
                </div>
            </div>
            <div class="tab-pane fade" id="withdrawalHistoryTab">
                <div class="table-responsive">
                    <table class="table table-sm history-table align-middle">
                        <thead><tr><th>ATW No.</th><th>Date</th><th>Authorized Recipient</th><th>Withdrawn By</th><th>Received By</th><th>Released By</th><th>Quantity</th><th>Status</th><th>Returned At</th><th>Returned By</th><th>Remarks</th></tr></thead>
                        <tbody>${withdrawalRows}</tbody>
                    </table>
                </div>
            </div>
        </div>
    `;
}




// ========== SIDEBAR FUNCTIONS ==========
let isSidebarPinned = false;

function getSidebarMenuIds() {
    return [
        'warehouseMenu',
        'supplierMenu',
        'customerMenu',
        'deliveryMenu',
        'bankingMenu',
        'sharedServicesMenu'
    ];
}

function toggleSidebarDropdown(event, targetId) {
    if (event) {
        event.preventDefault();
        event.stopPropagation();
    }

    const sidebar = document.getElementById('sidebar');
    const target = document.getElementById(targetId);
    if (!target) return false;

    const clickedLink = event ? event.currentTarget : document.querySelector(`[onclick*="${targetId}"]`);
    const clickedArrow = clickedLink ? clickedLink.querySelector('.dropdown-arrow') : null;

    if (sidebar && sidebar.classList.contains('collapsed') && window.innerWidth > 992) {
        isSidebarPinned = true;
        sidebar.classList.remove('collapsed');
        localStorage.setItem('sidebarCollapsed', 'false');

        setTimeout(function () {
            openOnlySidebarMenu(targetId);
        }, 120);

        return false;
    }

    const willOpen = !target.classList.contains('show');

    getSidebarMenuIds().forEach(function(menuId) {
        const menu = document.getElementById(menuId);
        const menuLink = document.querySelector(`[onclick*="${menuId}"]`);
        const arrow = menuLink ? menuLink.querySelector('.dropdown-arrow') : null;

        if (menu && menuId !== targetId) {
            menu.classList.remove('show');
            if (arrow) arrow.style.transform = 'translateY(-50%) rotate(0deg)';
        }
    });

    target.classList.toggle('show', willOpen);
    if (clickedArrow) {
        clickedArrow.style.transform = willOpen ? 'translateY(-50%) rotate(180deg)' : 'translateY(-50%) rotate(0deg)';
    }

    return false;
}

function openOnlySidebarMenu(targetId) {
    getSidebarMenuIds().forEach(function(menuId) {
        const menu = document.getElementById(menuId);
        const menuLink = document.querySelector(`[onclick*="${menuId}"]`);
        const arrow = menuLink ? menuLink.querySelector('.dropdown-arrow') : null;

        if (!menu) return;

        const shouldOpen = menuId === targetId;
        menu.classList.toggle('show', shouldOpen);
        if (arrow) {
            arrow.style.transform = shouldOpen ? 'translateY(-50%) rotate(180deg)' : 'translateY(-50%) rotate(0deg)';
        }
    });
}

function expandCurrentMenu() {
    const currentFile = window.location.pathname.split('/').pop();
    const menuMap = {
        'current_inventory.php': 'warehouseMenu',
        'bad_orders.php': 'warehouseMenu',
        'pick_list_items.php': 'warehouseMenu',
        'warehouses.php': 'warehouseMenu',
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
        'expenses.php': 'bankingMenu',
        'motorpool.php': 'sharedServicesMenu',
        'central_warehouse.php': 'sharedServicesMenu',
        'atworderproduct.php': 'sharedServicesMenu'
    };

    const targetMenu = menuMap[currentFile] || 'sharedServicesMenu';
    openOnlySidebarMenu(targetMenu);

    document.querySelectorAll('.sidebar .nav-link').forEach(function(link) {
        const href = link.getAttribute('href');
        if (href === currentFile) {
            link.classList.add('active');
        } else if (href && href !== '#') {
            link.classList.remove('active');
        }
    });
}

function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    const mainContent = document.getElementById('mainContent');

    if (!sidebar) return;

    if (window.innerWidth <= 992) {
        sidebar.classList.toggle('active');

        let overlay = document.querySelector('.sidebar-overlay');
        if (!overlay) {
            overlay = document.createElement('div');
            overlay.className = 'sidebar-overlay';
            document.body.appendChild(overlay);

            overlay.addEventListener('click', function() {
                sidebar.classList.remove('active');
                overlay.classList.remove('active');
                setTimeout(function() {
                    if (overlay && overlay.parentNode) overlay.remove();
                }, 250);
            });
        }

        setTimeout(function() {
            overlay.classList.toggle('active', sidebar.classList.contains('active'));
        }, 10);
    } else {
        isSidebarPinned = false;
        sidebar.classList.toggle('collapsed');
        if (mainContent) mainContent.classList.toggle('expanded', sidebar.classList.contains('collapsed'));
        localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed') ? 'true' : 'false');
    }
}

function initSidebarButtons() {
    const desktopToggleBtn = document.getElementById('desktopToggleBtn');
    const mobileToggleBtn = document.getElementById('mobileToggleBtn');
    const mobileMenuBtn = document.getElementById('mobileMenuBtn');

    if (desktopToggleBtn) {
        desktopToggleBtn.addEventListener('click', function(e) {
            e.preventDefault();
            toggleSidebar();
        });
    }

    if (mobileToggleBtn) {
        mobileToggleBtn.addEventListener('click', function(e) {
            e.preventDefault();
            toggleSidebar();
        });
    }

    if (mobileMenuBtn) {
        mobileMenuBtn.addEventListener('click', function(e) {
            e.preventDefault();
            toggleSidebar();
        });
    }
}

function initSidebarState() {
    const sidebar = document.getElementById('sidebar');
    const mainContent = document.getElementById('mainContent');

    if (!sidebar) return;

    if (window.innerWidth > 992 && localStorage.getItem('sidebarCollapsed') === 'true') {
        sidebar.classList.add('collapsed');
        if (mainContent) mainContent.classList.add('expanded');
    }

    expandCurrentMenu();
}

function initSidebarHoverBehavior() {
    const sidebar = document.getElementById('sidebar');
    if (!sidebar || window.innerWidth <= 992) return;

    sidebar.addEventListener('mouseleave', function() {
        if (!isSidebarPinned && sidebar.classList.contains('collapsed')) {
            sidebar.style.width = '';
        }
    });

    sidebar.addEventListener('click', function(e) {
        const navLink = e.target.closest('.nav-link');
        if (navLink && sidebar.classList.contains('collapsed')) {
            isSidebarPinned = true;
            sidebar.classList.remove('collapsed');
            localStorage.setItem('sidebarCollapsed', 'false');

            const mainContent = document.getElementById('mainContent');
            if (mainContent) mainContent.classList.remove('expanded');
        }
    });
}

function confirmLogout() {
    if (typeof Swal !== 'undefined') {
        Swal.fire({
            title: 'Are you sure?',
            text: 'You will be logged out of the system',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#07d826',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Yes, logout'
        }).then(function(result) {
            if (result.isConfirmed) {
                localStorage.removeItem('sidebarCollapsed');
                window.location.href = './logout.php';
            }
        });
    } else if (confirm('Are you sure you want to logout?')) {
        localStorage.removeItem('sidebarCollapsed');
        window.location.href = './logout.php';
    }
}

function logout() {
    confirmLogout();
}

document.addEventListener('DOMContentLoaded', function() {
    initInventoryGlobalSearch();
    initSidebarButtons();
    initSidebarState();
    initSidebarHoverBehavior();
});

// ========== MOBILE BOTTOM NAVBAR FUNCTIONS ==========
window.closeAllMobileDropdowns = function() {
    document.querySelectorAll('.more-dropdown').forEach(function(el) {
        el.classList.remove('show');
    });
    document.querySelectorAll('.more-btn').forEach(function(btn) {
        btn.classList.remove('active', 'has-active');
        btn.setAttribute('aria-expanded', 'false');
    });
};

window.toggleMobileDropdown = function(event, dropdownId) {
    if (event) {
        event.preventDefault();
        event.stopPropagation();
    }

    var dropdown = document.getElementById(dropdownId);
    var btn = event.currentTarget;

    if (!dropdown) return false;

    var isOpen = dropdown.classList.contains('show');

    window.closeAllMobileDropdowns();

    if (!isOpen) {
        dropdown.classList.add('show');
        btn.classList.add('active');
        btn.setAttribute('aria-expanded', 'true');
    }

    return false;
};

window.toggleDropdown = function(event, dropdownId) {
    return window.toggleMobileDropdown(event, dropdownId);
};

window.showProfileModal = function(event) {
    if (event) {
        event.preventDefault();
        event.stopPropagation();
    }

    if (typeof cleanupModalBackdrops === 'function') {
        cleanupModalBackdrops();
    }

    window.closeAllMobileDropdowns();

    var modal = document.getElementById('profileModal');
    if (modal) {
        bootstrap.Modal.getOrCreateInstance(modal).show();
    }

    return false;
};

// Close dropdowns when clicking outside
document.addEventListener('click', function(e) {
    if (!e.target.closest('.mobile-nav')) {
        window.closeAllMobileDropdowns();
    }
});

// Close dropdowns on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        window.closeAllMobileDropdowns();
    }
});

// Set active mobile nav item based on current page
function setActiveMobileNav() {
    var currentPage = window.location.pathname.split('/').pop();
    
    // Remove all active classes from ALL navigation elements
    document.querySelectorAll('.mobile-nav .nav-link, .more-btn, .dropdown-item, .has-active').forEach(function(el) {
        el.classList.remove('active', 'has-active');
    });
    
    // Main navigation links (non-dropdown items)
    var mainNavLinks = document.querySelectorAll('.mobile-nav .nav-link:not(.more-btn)');
    mainNavLinks.forEach(function(link) {
        var href = link.getAttribute('href');
        if (href === currentPage) {
            link.classList.add('active');
        }
    });
    
    // Dropdown items - set active state on the dropdown item itself
    document.querySelectorAll('.more-dropdown .dropdown-item').forEach(function(item) {
        var href = item.getAttribute('href');
        if (href === currentPage) {
            item.classList.add('active');
            
            // Mark the parent more-btn as has-active
            var parentDropdown = item.closest('.dropdown-more');
            if (parentDropdown) {
                var parentBtn = parentDropdown.querySelector('.more-btn');
                if (parentBtn) {
                    parentBtn.classList.add('has-active');
                }
            }
        }
    });
    
    // Special handling for motorpool.php (current page)
    if (currentPage === 'motorpool.php') {
        var sharedServicesBtn = document.querySelector('#sharedServicesMobileDropdown .more-btn');
        if (sharedServicesBtn) {
            sharedServicesBtn.classList.add('has-active');
        }
        var motorpoolItem = document.querySelector('#sharedServicesMobileMenu .dropdown-item[href="motorpool.php"]');
        if (motorpoolItem) {
            motorpoolItem.classList.add('active');
        }
    }
}

// Fix modal backdrop cleanup
function cleanupModalBackdrops() {
    document.querySelectorAll('.modal-backdrop').forEach(function(backdrop) {
        backdrop.remove();
    });
    document.body.classList.remove('modal-open');
    document.body.style.removeProperty('padding-right');
    document.body.style.removeProperty('overflow');
}

// Logout confirmation
function confirmLogout() {
    var modal = bootstrap.Modal.getInstance(document.getElementById('profileModal'));
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
    }).then(function(result) {
        if (result.isConfirmed) {
            localStorage.removeItem('sidebarCollapsed');
            window.location.href = '../logout.php';
        }
    });
}

// Initialize mobile nav on DOM load
document.addEventListener('DOMContentLoaded', function() {
    setActiveMobileNav();
});

// AUTO-SCROLL TO ACTIVE SIDEBAR ITEM
function scrollToActiveSidebarItem() {
    const activeLink = document.querySelector('.sidebar .nav-link.active');
    const sidebarContent = document.querySelector('.sidebar-content');

    if (activeLink && sidebarContent) {
        // Get the position of the active link relative to the sidebar content
        const linkRect = activeLink.getBoundingClientRect();
        const containerRect = sidebarContent.getBoundingClientRect();

        // Calculate scroll position to center the active link
        const scrollTop = activeLink.offsetTop - (containerRect.height / 2) + (linkRect.height / 2);

        // Scroll smoothly to the active link
        sidebarContent.scrollTo({
            top: Math.max(0, scrollTop - 20), // Add small offset for better visibility
            behavior: 'smooth'
        });
    }
}

// Expand dropdown menus that contain the active link (para lumabas ang nakatagong menu)
function expandActiveDropdownContainers() {
    const sidebar = document.getElementById('sidebar');
    if (!sidebar || sidebar.classList.contains('collapsed')) return;

    // Look for any dropdown that contains an active link
    document.querySelectorAll('.sidebar .dropdown-nav').forEach(dropdownNav => {
        const activeLink = dropdownNav.querySelector('.nav-link.active');

        if (activeLink) {
            const collapseDiv = dropdownNav.querySelector('.collapse');

            if (collapseDiv && !collapseDiv.classList.contains('show')) {
                collapseDiv.classList.add('show');

                const parentLink = dropdownNav.querySelector(':scope > .nav-link');
                if (parentLink) {
                    const arrow = parentLink.querySelector('.dropdown-arrow');
                    if (arrow) arrow.style.transform = 'translateY(-50%) rotate(180deg)';
                }
            }
        }
    });
}

// Execute after page loads (with a small delay para sure na rendered na ang sidebar)
setTimeout(() => {
    expandActiveDropdownContainers();  // Buksan ang dropdown kung nasa loob ang active item
    scrollToActiveSidebarItem();       // I-scroll papunta sa active item
}, 150);

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
